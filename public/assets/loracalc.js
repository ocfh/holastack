





(function () {
  'use strict';

  
  const Lc_REGION_FREQ = {
    CN470: 470.3, CN779: 779.5, EU868: 868.1, US915: 903.9, AU915: 915.2,
    AS923: 923.2, KR920: 922.1, IN865: 865.0625, RU864: 864.1, EU433: 433.175
  };
  const Lc_SNR_MIN = { 7: -7.5, 8: -10, 9: -12.5, 10: -15, 11: -17.5, 12: -20 };
  
  const Lc_DR_MAP = {
    EU868: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250], rx2: 0 },
    US915: { 0: [10, 125], 1: [9, 125], 2: [8, 125], 3: [7, 125], 4: [8, 500], 8: [12, 500], rx2: 8 },
    CN470: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250], rx2: 0 },
    AS923: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250], rx2: 2 },
    AU915: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [8, 500], 8: [12, 500], rx2: 8 },
    KR920: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250], rx2: 0 },
    IN865: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250], rx2: 2 },
    RU864: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], rx2: 0 },
    EU433: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250], rx2: 0 },
    CN779: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250], rx2: 0 }
  };
  function Lc_resolveDR(region, dr) {
    const m = Lc_DR_MAP[region];
    if (m && m[dr]) return m[dr];
    return [12, 125]; 
  }

  
  function Lc_loraToA(sf, bwKHz, payload, crcOn, implicit, ldro, preamble) {
    const bw = bwKHz * 1000;
    const tsym = Math.pow(2, sf) / bw;             
    const nBitPayload = payload * 8;
    const nBitHeader = implicit ? 0 : 20;
    const crcBits = crcOn ? 16 : 0;
    const ih = implicit ? 1 : 0;
    const de = ldro ? 1 : 0;
    const numerator = 2 * nBitPayload - nBitHeader + 4 * sf + crcBits - 4 * ih;
    const denominator = 4 * (sf - 2 * de);
    const payloadSyms = 8 + Math.max(Math.ceil(numerator / denominator), 0);
    const preambleSyms = preamble + 4.25;
    const totalSyms = preambleSyms + payloadSyms;
    return { tsym, payloadSyms, preambleSyms, totalSyms, toaSec: totalSyms * tsym };
  }
  function Lc_fmt(x, d) {
    if (!isFinite(x)) return '—';
    return x.toLocaleString('zh-CN', { minimumFractionDigits: d, maximumFractionDigits: d });
  }

  
  function Lc_switchTab(which) {
    const phy = which === 'phy';
    document.getElementById('panelPhy').classList.toggle('hidden', !phy);
    document.getElementById('panelLw').classList.toggle('hidden', phy);
    document.getElementById('tabPhy').classList.toggle('active', phy);
    document.getElementById('tabLw').classList.toggle('active', !phy);
  }

  
  function Lc_applyRegion(prefix) {
    const r = document.getElementById(prefix + '_region').value;
    if (Lc_REGION_FREQ[r] !== undefined) document.getElementById(prefix + '_freq').value = Lc_REGION_FREQ[r];
    if (prefix === 'p') Lc_pCalc(); else Lc_lCalc();
  }
  function Lc_pSyncN() {
    const m = document.getElementById('p_model').value;
    document.getElementById('p_nrow').style.display = (m === 'custom') ? 'flex' : 'none';
    Lc_pCalc();
  }
  function Lc_pGetN() {
    const m = document.getElementById('p_model').value;
    return (m === 'custom') ? (parseFloat(document.getElementById('p_nval').value) || 2.7) : parseFloat(m);
  }
  function Lc_pCalc() {
    const freqMHz = parseFloat(document.getElementById('p_freq').value);
    const bwKHz = parseFloat(document.getElementById('p_bw').value);
    const sf = parseInt(document.getElementById('p_sf').value, 10);
    const cr = parseInt(document.getElementById('p_cr').value, 10);
    const preamble = Math.max(1, parseInt(document.getElementById('p_preamble').value, 10) || 8);
    const payload = Math.max(0, parseInt(document.getElementById('p_payload').value, 10) || 0);
    const crcOn = document.getElementById('p_crc').checked;
    const implicit = document.getElementById('p_implicit').checked;
    const ldro = document.getElementById('p_ldro').checked;
    const txpwr = parseFloat(document.getElementById('p_txpwr').value) || 0;
    const gtx = parseFloat(document.getElementById('p_gtx').value) || 0;
    const grx = parseFloat(document.getElementById('p_grx').value) || 0;
    const nf = parseFloat(document.getElementById('p_nf').value) || 0;
    const margin = parseFloat(document.getElementById('p_margin').value) || 0;
    const volt = parseFloat(document.getElementById('p_volt').value) || 3.3;
    const itx = parseFloat(document.getElementById('p_itx').value) || 0;
    const irx = parseFloat(document.getElementById('p_irx').value) || 0;
    const n = Lc_pGetN();

    if (!(freqMHz > 0) || !(bwKHz > 0) || !(sf >= 7 && sf <= 12)) {
      document.getElementById('p_toa').textContent = '参数错误'; document.getElementById('p_toaUnit').textContent = ''; return;
    }
    const bw = bwKHz * 1000;
    const r = Lc_loraToA(sf, bwKHz, payload, crcOn, implicit, ldro, preamble);
    const tsym = r.tsym, toaSec = r.toaSec;

    const rs = bw / Math.pow(2, sf);
    const crEff = 4 / (4 + cr);
    const bitrate = rs * sf * crEff;
    const snrMin = Lc_SNR_MIN[sf];
    const sens = -174 + 10 * Math.log10(bw) + nf + snrMin;
    const lb = txpwr + gtx + grx - sens - margin;
    const distKm = Math.pow(10, (lb - 32.45 - 20 * Math.log10(freqMHz)) / (10 * n));
    const preambleDur = r.preambleSyms * tsym;               
    const xtalPpm = 0.25 * bw / (freqMHz * 1e6) * 1e6;       
    const txMw = volt * itx, rxMw = volt * irx;

    if (toaSec < 1) { document.getElementById('p_toa').textContent = Lc_fmt(toaSec * 1000, 2); document.getElementById('p_toaUnit').textContent = 'ms'; }
    else { document.getElementById('p_toa').textContent = Lc_fmt(toaSec, 3); document.getElementById('p_toaUnit').textContent = 's'; }
    document.getElementById('p_tsym').textContent = Lc_fmt(tsym * 1000, 3);
    document.getElementById('p_syms').textContent = Lc_fmt(r.totalSyms, 1);
    document.getElementById('p_preambleDur').textContent = Lc_fmt(preambleDur * 1000, 3);
    if (bitrate >= 1000) { document.getElementById('p_dr').textContent = Lc_fmt(bitrate / 1000, 3); document.getElementById('p_drUnit').textContent = 'kbps'; }
    else { document.getElementById('p_dr').textContent = Lc_fmt(bitrate, 1); document.getElementById('p_drUnit').textContent = 'bps'; }
    document.getElementById('p_xtal').textContent = Lc_fmt(xtalPpm, 2);
    document.getElementById('p_sens').textContent = Lc_fmt(sens, 2);
    document.getElementById('p_lb').textContent = Lc_fmt(lb, 2);
    document.getElementById('p_pwr').textContent = Lc_fmt(txMw, 1) + ' / ' + Lc_fmt(rxMw, 1);
    if (distKm >= 1) { document.getElementById('p_dist').textContent = Lc_fmt(distKm, 2); document.getElementById('p_distUnit').textContent = 'km'; }
    else { document.getElementById('p_dist').textContent = Lc_fmt(distKm * 1000, 0); document.getElementById('p_distUnit').textContent = 'm'; }
  }

  
  function Lc_lRegionChange() {
    const region = document.getElementById('l_region').value;
    const m = Lc_DR_MAP[region];
    const drSel = document.getElementById('l_dr');
    drSel.innerHTML = Object.keys(m).filter(k => k !== 'rx2').map(k => `<option value="${k}">DR${k} (SF${m[k][0]}/BW${m[k][1]})</option>`).join('');
    drSel.value = '2';
    const rxSel = document.getElementById('l_drRx2');
    rxSel.innerHTML = Object.keys(m).filter(k => k !== 'rx2').map(k => `<option value="${k}">DR${k} (SF${m[k][0]}/BW${m[k][1]})</option>`).join('');
    rxSel.value = String(m.rx2);
    if (Lc_REGION_FREQ[region] !== undefined && document.getElementById('l_freq')) {
      document.getElementById('l_freq').value = Lc_REGION_FREQ[region];
    }
    Lc_lCalc();
  }
  function Lc_lSyncN() {
    const m = document.getElementById('l_model').value;
    document.getElementById('l_nwrap').style.display = (m === 'custom') ? 'block' : 'none';
    Lc_lCalc();
  }
  function Lc_lGetN() {
    const m = document.getElementById('l_model').value;
    return (m === 'custom') ? (parseFloat(document.getElementById('l_nval').value) || 2.7) : parseFloat(m);
  }
  function Lc_lCalc() {
    const region = document.getElementById('l_region').value;
    const [sf, bw] = Lc_resolveDR(region, document.getElementById('l_dr').value);
    const [sfRx2, bwRx2] = Lc_resolveDR(region, document.getElementById('l_drRx2').value);
    const freqMHz = Lc_REGION_FREQ[region];
    const crcOn = true, implicit = false, ldro = ((Math.pow(2, sf) / (bw * 1000)) > 0.016);
    const payload = Math.max(0, parseInt(document.getElementById('l_pl').value, 10) || 0);
    const retrans = Math.max(0, parseInt(document.getElementById('l_retrans').value, 10) || 0);
    const interval = Math.max(1, parseFloat(document.getElementById('l_interval').value) || 900);
    const rxpl = Math.max(0, parseInt(document.getElementById('l_rxpl').value, 10) || 0);
    const rxpreamble = Math.max(1, parseInt(document.getElementById('l_rxpreamble').value, 10) || 8);
    const dlday = Math.max(0, parseFloat(document.getElementById('l_dlday').value) || 0);
    const rx1pct = Math.min(100, Math.max(0, parseFloat(document.getElementById('l_rx1pct').value) || 50)) / 100;
    const itx = parseFloat(document.getElementById('l_itx').value) || 0;     
    const irx = parseFloat(document.getElementById('l_irx').value) || 0;     
    const isleep = parseFloat(document.getElementById('l_isleep').value) || 0;  
    const volt = parseFloat(document.getElementById('l_volt').value) || 3.3;
    const batt = Math.max(1, parseFloat(document.getElementById('l_batt').value) || 2400); 
    const txpwr = parseFloat(document.getElementById('l_txpwr').value) || 0;
    const gtx = 0, grx = 3, nf = 6;
    const margin = parseFloat(document.getElementById('l_margin').value) || 0;
    const n = Lc_lGetN();

    const up = Lc_loraToA(sf, bw, payload, crcOn, implicit, ldro, 8);
    const toaUpSec = up.toaSec;
    const rx1 = Lc_loraToA(sf, bw, rxpl, crcOn, implicit, false, rxpreamble);
    const rx2 = Lc_loraToA(sfRx2, bwRx2, rxpl, crcOn, implicit, false, rxpreamble);
    const rxPerDownSec = rx1pct * rx1.toaSec + (1 - rx1pct) * rx2.toaSec;

    const uplinksPerHour = 3600 / interval;
    const toaH_tx = uplinksPerHour * (1 + retrans) * toaUpSec * 1000; 
    const downlinksPerHour = dlday / 24;
    const toaH_rx = downlinksPerHour * rxPerDownSec * 1000;           
    const duty = toaH_tx / 36000;                                    

    const txActive = (1 + retrans) * toaUpSec;                        
    const downlinksPerCycle = dlday * interval / 86400;               
    const rxActive = downlinksPerCycle * rxPerDownSec;                
    const sleepActive = Math.max(0, interval - txActive - rxActive);  
    const qTx = itx * 1000 * txActive;    
    const qRx = irx * 1000 * rxActive;    
    const qSl = isleep * sleepActive;     
    const avgTot = (qTx + qRx + qSl) / interval; 
    const avgTx = qTx / interval, avgRx = qRx / interval, avgSl = qSl / interval;

    const bwHz = bw * 1000;
    const sens = -174 + 10 * Math.log10(bwHz) + nf + Lc_SNR_MIN[sf];
    const lb = txpwr + gtx + grx - sens - margin;
    const distKm = Math.pow(10, (lb - 32.45 - 20 * Math.log10(freqMHz)) / (10 * n));

    const battUah = batt * 1000;                 
    const lifeH = battUah / avgTot;
    const lifeY = lifeH / (24 * 365);

    if (toaUpSec < 1) { document.getElementById('l_toa').textContent = Lc_fmt(toaUpSec * 1000, 2); document.getElementById('l_toaUnit').textContent = 'ms'; }
    else { document.getElementById('l_toa').textContent = Lc_fmt(toaUpSec, 3); document.getElementById('l_toaUnit').textContent = 's'; }
    document.getElementById('l_itx_out').textContent = Lc_fmt(itx, 1);
    document.getElementById('l_irx_out').textContent = Lc_fmt(irx, 1);
    document.getElementById('l_avgTx').textContent = Lc_fmt(avgTx, 2);
    document.getElementById('l_avgRx').textContent = Lc_fmt(avgRx, 2);
    document.getElementById('l_avgSleep').textContent = Lc_fmt(avgSl, 2);
    document.getElementById('l_avgTot').textContent = Lc_fmt(avgTot, 2);
    document.getElementById('l_toaH_tx').textContent = Lc_fmt(toaH_tx, 1);
    document.getElementById('l_toaH_rx').textContent = Lc_fmt(toaH_rx, 1);
    document.getElementById('l_duty').textContent = Lc_fmt(duty, 4);
    document.getElementById('l_lb').textContent = Lc_fmt(lb, 2);
    document.getElementById('l_sens').textContent = Lc_fmt(sens, 2);
    if (distKm >= 1) { document.getElementById('l_dist').textContent = Lc_fmt(distKm, 2); document.getElementById('l_distUnit').textContent = 'km'; }
    else { document.getElementById('l_dist').textContent = Lc_fmt(distKm * 1000, 0); document.getElementById('l_distUnit').textContent = 'm'; }
    if (lifeY >= 1) { document.getElementById('l_battlife').textContent = Lc_fmt(lifeY, 2); document.getElementById('l_battUnit').textContent = '年'; }
    else if (lifeH / 24 >= 1) { document.getElementById('l_battlife').textContent = Lc_fmt(lifeH / 24, 1); document.getElementById('l_battUnit').textContent = '天'; }
    else { document.getElementById('l_battlife').textContent = Lc_fmt(lifeH, 0); document.getElementById('l_battUnit').textContent = '小时'; }
  }

  
  const Lc_CSS = `
.loracalc{--panel2:var(--bg-subtle)}
.loracalc *{box-sizing:border-box}
.loracalc a{color:var(--acc);text-decoration:none}
.loracalc .tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.loracalc .tab{background:var(--panel);border:1px solid var(--line);color:var(--mut);padding:9px 16px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px}
.loracalc .tab.active{background:var(--acc);color:var(--txt-on-acc);border-color:var(--acc)}
.loracalc .grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start}
@media (max-width:860px){ .loracalc .grid{grid-template-columns:1fr} }
.loracalc .panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px 20px}
.loracalc .panel h2{margin:0 0 4px;font-size:15px;color:var(--txt)}
.loracalc .panel .hint{color:var(--mut);font-size:12px;margin:0 0 12px}
.loracalc fieldset{border:1px solid var(--line);border-radius:10px;margin:0 0 14px;padding:12px 14px}
.loracalc legend{color:var(--acc);font-size:12px;padding:0 6px;font-weight:600}
.loracalc label{display:block;color:var(--mut);margin:10px 0 4px;font-size:12px}
.loracalc input,.loracalc select,.loracalc textarea{background:var(--bg-deep);color:var(--txt);border:1px solid var(--line);border-radius:7px;padding:9px 10px;width:100%;font-family:inherit;font-size:14px}
.loracalc .row{display:flex;gap:12px;flex-wrap:wrap}
.loracalc .row>div{flex:1;min-width:110px}
.loracalc .check{display:flex;align-items:center;gap:8px;margin:10px 0 2px}
.loracalc .check input{width:auto}
.loracalc .check label{margin:0;color:var(--txt);font-size:13px}
.loracalc button.calc{background:var(--acc);color:var(--txt-on-acc);border:0;padding:11px 18px;border-radius:8px;cursor:pointer;font-weight:700;font-size:14px;width:100%;margin-top:6px}
.loracalc .results{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:520px){ .loracalc .results{grid-template-columns:1fr} }
.loracalc .stat{background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.loracalc .stat .k{color:var(--mut);font-size:12px;margin-bottom:6px}
.loracalc .stat .v{font-size:21px;font-weight:700;color:var(--acc);word-break:break-all}
.loracalc .stat .u{font-size:12px;color:var(--mut);margin-left:4px;font-weight:400}
.loracalc .stat.big{grid-column:1 / -1;background:var(--bg-subtle)}
.loracalc .stat.big .v{font-size:28px;color:var(--ok)}
.loracalc .stat.warnv .v{color:var(--warn)}
.loracalc .note{color:var(--mut);font-size:12px;line-height:1.7;margin-top:14px}
.loracalc .note code{background:var(--bg-deep);padding:1px 5px;border-radius:4px;color:var(--warn)}
.loracalc .pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;background:var(--bg-chip);color:var(--mut);margin-right:6px}
.loracalc .foot{color:var(--mut);font-size:12px;text-align:center;margin-top:18px}
.loracalc .hidden{display:none}
.loracalc kbd{background:var(--bg-deep);border:1px solid var(--line);border-radius:4px;padding:1px 6px;font-size:11px}
`;
  let Lc_cssInjected = false;
  function Lc_ensureCss() {
    
    const existing = document.getElementById('lc-style');
    if (existing) existing.remove();
    const s = document.createElement('style');
    s.id = 'lc-style';
    s.textContent = Lc_CSS;
    document.head.appendChild(s);
    Lc_cssInjected = true;
  }

  
  
  
  
  const Lc_TEMPLATE = '';

  
  if (typeof window !== 'undefined') {
    window.viewLoraCalc = async function () {
      const view = document.getElementById('view');
      if (!view) return;
      Lc_ensureCss();
      
      
      
      try {
        const tok = (window.state && window.state.token) || localStorage.getItem('elw_token') || '';
        const res = await fetch('/api/view/loracalc', tok ? { headers: { 'X-Elw-Token': tok } } : {});
        if (!res.ok) { view.innerHTML = '<p class="muted">' + (t('加载失败') || '加载失败') + '</p>'; return; }
        view.innerHTML = await res.text();
      } catch (e) {
        view.innerHTML = '<p class="muted">' + (t('加载失败') || '加载失败') + '</p>';
        return;
      }
      Lc_applyRegion('p'); Lc_pSyncN(); Lc_pCalc();
      Lc_lRegionChange(); Lc_lSyncN(); Lc_lCalc();
      
      
      const root = view.querySelector('.loracalc');
      if (!root) return;
      const Lc_onChange = (e) => {
        const id = (e.target && e.target.id) || '';
        if (id.charAt(0) === 'p') Lc_pCalc();
        else if (id.charAt(0) === 'l') Lc_lCalc();
      };
      root.addEventListener('input', Lc_onChange);
      root.addEventListener('change', Lc_onChange);
    };
    
    
    
    
    window.Lc_switchTab = Lc_switchTab;
    window.Lc_applyRegion = Lc_applyRegion;
    window.Lc_pCalc = Lc_pCalc;
    window.Lc_pSyncN = Lc_pSyncN;
    window.Lc_lRegionChange = Lc_lRegionChange;
    window.Lc_lSyncN = Lc_lSyncN;
    window.Lc_lCalc = Lc_lCalc;
  }

  
  if (typeof window === 'undefined') {
    const r = Lc_loraToA(12, 125, 12, true, false, true, 8);
  }
})();
