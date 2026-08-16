// =============================================================
// LoRa / LoRaWAN 参数计算器 —— holastack SPA 模块
// 由 index.php 的 nav('loracalc') -> viewLoraCalc() 调用。
// 纯前端计算，所有符号以 Lc 前缀避免与 SPA 主脚本冲突。
// 样式与模板自包含（.loracalc 作用域），复用 SPA 的 CSS 变量配色。
// =============================================================
(function () {
  'use strict';

  // ----------------------- 公共数据 -----------------------
  const Lc_REGION_FREQ = {
    CN470: 470.3, CN779: 779.5, EU868: 868.1, US915: 903.9, AU915: 915.2,
    AS923: 923.2, KR920: 922.1, IN865: 865.0625, RU864: 864.1, EU433: 433.175
  };
  const Lc_SNR_MIN = { 7: -7.5, 8: -10, 9: -12.5, 10: -15, 11: -17.5, 12: -20 };
  // DR -> [SF, BW_kHz]，按区域；rx2 = 默认 RX2 的 DR
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
    return [12, 125]; // 兜底
  }

  // ----------------------- 通用：LoRa 空中时间 -----------------------
  function Lc_loraToA(sf, bwKHz, payload, crcOn, implicit, ldro, preamble) {
    const bw = bwKHz * 1000;
    const tsym = Math.pow(2, sf) / bw;             // 秒
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

  // ----------------------- Tab 切换 -----------------------
  function Lc_switchTab(which) {
    const phy = which === 'phy';
    document.getElementById('panelPhy').classList.toggle('hidden', !phy);
    document.getElementById('panelLw').classList.toggle('hidden', phy);
    document.getElementById('tabPhy').classList.toggle('active', phy);
    document.getElementById('tabLw').classList.toggle('active', !phy);
  }

  // ----------------------- TAB 1: LoRa (PHY) -----------------------
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
    const preambleDur = r.preambleSyms * tsym;               // 秒
    const xtalPpm = 0.25 * bw / (freqMHz * 1e6) * 1e6;       // ±25% 带宽 -> ppm
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

  // ----------------------- TAB 2: LoRaWAN -----------------------
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
    const itx = parseFloat(document.getElementById('l_itx').value) || 0;     // mA
    const irx = parseFloat(document.getElementById('l_irx').value) || 0;     // mA
    const isleep = parseFloat(document.getElementById('l_isleep').value) || 0;  // µA
    const volt = parseFloat(document.getElementById('l_volt').value) || 3.3;
    const batt = Math.max(1, parseFloat(document.getElementById('l_batt').value) || 2400); // mAh
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
    const toaH_tx = uplinksPerHour * (1 + retrans) * toaUpSec * 1000; // ms/h
    const downlinksPerHour = dlday / 24;
    const toaH_rx = downlinksPerHour * rxPerDownSec * 1000;           // ms/h
    const duty = toaH_tx / 36000;                                    // %（1h=3.6e6 ms，占比×100 已含于 /36000）

    const txActive = (1 + retrans) * toaUpSec;                        // s / 周期
    const downlinksPerCycle = dlday * interval / 86400;               // 每周期下行数
    const rxActive = downlinksPerCycle * rxPerDownSec;                // s / 周期
    const sleepActive = Math.max(0, interval - txActive - rxActive);  // s / 周期
    const qTx = itx * 1000 * txActive;    // µA·s
    const qRx = irx * 1000 * rxActive;    // µA·s
    const qSl = isleep * sleepActive;     // µA·s
    const avgTot = (qTx + qRx + qSl) / interval; // µA
    const avgTx = qTx / interval, avgRx = qRx / interval, avgSl = qSl / interval;

    const bwHz = bw * 1000;
    const sens = -174 + 10 * Math.log10(bwHz) + nf + Lc_SNR_MIN[sf];
    const lb = txpwr + gtx + grx - sens - margin;
    const distKm = Math.pow(10, (lb - 32.45 - 20 * Math.log10(freqMHz)) / (10 * n));

    const battUah = batt * 1000;                 // µAh
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

  // ----------------------- 样式（首次注入 head，作用域 .loracalc） -----------------------
  const Lc_CSS = `
.loracalc{--panel2:#161d2c}
.loracalc *{box-sizing:border-box}
.loracalc a{color:var(--acc);text-decoration:none}
.loracalc .tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.loracalc .tab{background:var(--panel);border:1px solid var(--line);color:var(--mut);padding:9px 16px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px}
.loracalc .tab.active{background:var(--acc);color:#04121f;border-color:var(--acc)}
.loracalc .grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start}
@media (max-width:860px){ .loracalc .grid{grid-template-columns:1fr} }
.loracalc .panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px 20px}
.loracalc .panel h2{margin:0 0 4px;font-size:15px;color:var(--txt)}
.loracalc .panel .hint{color:var(--mut);font-size:12px;margin:0 0 12px}
.loracalc fieldset{border:1px solid var(--line);border-radius:10px;margin:0 0 14px;padding:12px 14px}
.loracalc legend{color:var(--acc);font-size:12px;padding:0 6px;font-weight:600}
.loracalc label{display:block;color:var(--mut);margin:10px 0 4px;font-size:12px}
.loracalc input,.loracalc select,.loracalc textarea{background:#0d1320;color:var(--txt);border:1px solid var(--line);border-radius:7px;padding:9px 10px;width:100%;font-family:inherit;font-size:14px}
.loracalc .row{display:flex;gap:12px;flex-wrap:wrap}
.loracalc .row>div{flex:1;min-width:110px}
.loracalc .check{display:flex;align-items:center;gap:8px;margin:10px 0 2px}
.loracalc .check input{width:auto}
.loracalc .check label{margin:0;color:var(--txt);font-size:13px}
.loracalc button.calc{background:var(--acc);color:#04121f;border:0;padding:11px 18px;border-radius:8px;cursor:pointer;font-weight:700;font-size:14px;width:100%;margin-top:6px}
.loracalc .results{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:520px){ .loracalc .results{grid-template-columns:1fr} }
.loracalc .stat{background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.loracalc .stat .k{color:var(--mut);font-size:12px;margin-bottom:6px}
.loracalc .stat .v{font-size:21px;font-weight:700;color:var(--acc);word-break:break-all}
.loracalc .stat .u{font-size:12px;color:var(--mut);margin-left:4px;font-weight:400}
.loracalc .stat.big{grid-column:1 / -1;background:linear-gradient(135deg,#13233a,#0f1a2c)}
.loracalc .stat.big .v{font-size:28px;color:var(--ok)}
.loracalc .stat.warnv .v{color:var(--warn)}
.loracalc .note{color:var(--mut);font-size:12px;line-height:1.7;margin-top:14px}
.loracalc .note code{background:#0d1320;padding:1px 5px;border-radius:4px;color:var(--warn)}
.loracalc .pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;background:#243049;color:var(--mut);margin-right:6px}
.loracalc .foot{color:var(--mut);font-size:12px;text-align:center;margin-top:18px}
.loracalc .hidden{display:none}
.loracalc kbd{background:#0d1320;border:1px solid var(--line);border-radius:4px;padding:1px 6px;font-size:11px}
`;
  let Lc_cssInjected = false;
  function Lc_ensureCss() {
    if (Lc_cssInjected) return;
    const s = document.createElement('style');
    s.id = 'lc-style';
    s.textContent = Lc_CSS;
    document.head.appendChild(s);
    Lc_cssInjected = true;
  }

  // ----------------------- 模板 -----------------------
  const Lc_TEMPLATE = `
<div class="loracalc">
  <h2>LoRa / LoRaWAN 参数计算器</h2>
  <p class="hint">参照 Semtech LoRa Calculator · 中文 · 双计算器 · 所有计算均在浏览器本地完成，不上传任何数据。</p>
  <div class="tabs">
    <div class="tab active" id="tabPhy" onclick="Lc_switchTab('phy')">LoRa 计算器（物理层）</div>
    <div class="tab" id="tabLw" onclick="Lc_switchTab('lw')">LoRaWAN 计算器（能耗 / 占空比）</div>
  </div>

  <!-- TAB 1: LoRa (PHY) -->
  <div id="panelPhy">
    <div class="grid">
      <div class="panel">
        <h2>输入参数（物理层）</h2>
        <p class="hint">修改任意参数结果即时更新。</p>
        <fieldset>
          <legend>频段与频率</legend>
          <label>频段 / 区域</label>
          <select id="p_region" onchange="Lc_applyRegion('p')">
            <option value="CN470">CN470（中国 470MHz）</option>
            <option value="CN779">CN779（中国 779MHz）</option>
            <option value="EU868">EU868（欧洲 868MHz）</option>
            <option value="US915">US915（美国 915MHz）</option>
            <option value="AU915">AU915（澳洲 915MHz）</option>
            <option value="AS923">AS923（亚太 923MHz）</option>
            <option value="KR920">KR920（韩国 920MHz）</option>
            <option value="IN865">IN865（印度 865MHz）</option>
            <option value="RU864">RU864（俄罗斯 864MHz）</option>
            <option value="EU433">EU433（欧洲 433MHz）</option>
          </select>
          <label>中心频率（MHz）</label>
          <input id="p_freq" type="number" step="0.0001" value="470.3">
        </fieldset>
        <fieldset>
          <legend>调制参数</legend>
          <div class="row">
            <div><label>带宽 BW（kHz）</label>
              <select id="p_bw">
                <option>7.8</option><option>10.4</option><option>15.6</option><option>20.8</option>
                <option>31.25</option><option>41.7</option><option>62.5</option>
                <option selected>125</option><option>250</option><option>500</option>
              </select></div>
            <div><label>扩频因子 SF</label>
              <select id="p_sf">
                <option>7</option><option>8</option><option>9</option><option>10</option>
                <option>11</option><option selected>12</option>
              </select></div>
            <div><label>编码率 CR</label>
              <select id="p_cr">
                <option value="1" selected>4/5</option><option value="2">4/6</option>
                <option value="3">4/7</option><option value="4">4/8</option>
              </select></div>
          </div>
          <div class="row">
            <div><label>前导码长度（符号）</label>
              <input id="p_preamble" type="number" step="1" value="8" min="1"></div>
            <div><label>数据包长度（字节）</label>
              <input id="p_payload" type="number" step="1" value="12" min="0"></div>
          </div>
          <div class="check"><input id="p_crc" type="checkbox" checked onchange="Lc_pCalc()"><label for="p_crc">CRC 校验开启</label></div>
          <div class="check"><input id="p_implicit" type="checkbox" onchange="Lc_pCalc()"><label for="p_implicit">隐式报头（Implicit Header）</label></div>
          <div class="check"><input id="p_ldro" type="checkbox" checked onchange="Lc_pCalc()"><label for="p_ldro">低速率优化 LDRO（符号时长 &gt; 16ms 建议开启）</label></div>
        </fieldset>
        <fieldset>
          <legend>射频与功耗</legend>
          <div class="row">
            <div><label>发射功率 TX（dBm）</label><input id="p_txpwr" type="number" step="0.1" value="17"></div>
            <div><label>发射电流（mA）</label><input id="p_itx" type="number" step="0.1" value="30"></div>
            <div><label>接收电流（mA）</label><input id="p_irx" type="number" step="0.1" value="5"></div>
          </div>
          <div class="row">
            <div><label>发射天线增益（dBi）</label><input id="p_gtx" type="number" step="0.1" value="0"></div>
            <div><label>接收天线增益（dBi）</label><input id="p_grx" type="number" step="0.1" value="3"></div>
            <div><label>噪声系数 NF（dB）</label><input id="p_nf" type="number" step="0.1" value="6"></div>
          </div>
          <div class="row">
            <div><label>供电电压（V）</label><input id="p_volt" type="number" step="0.1" value="3.3"></div>
            <div><label>衰落余量（dB）</label><input id="p_margin" type="number" step="1" value="0"></div>
            <div><label>传播模型（n）</label>
              <select id="p_model" onchange="Lc_pSyncN()">
                <option value="2.0">自由空间 (n=2.0)</option>
                <option value="2.4">开阔地 / 农村 (n=2.4)</option>
                <option value="2.7" selected>郊区 (n=2.7)</option>
                <option value="3.0">城市 (n=3.0)</option>
                <option value="3.5">密集城市 (n=3.5)</option>
                <option value="custom">自定义…</option>
              </select></div>
          </div>
          <div class="row" id="p_nrow" style="display:none">
            <div><label>自定义路径损耗指数 n</label><input id="p_nval" type="number" step="0.1" value="2.7" oninput="Lc_pCalc()"></div>
          </div>
        </fieldset>
        <button class="calc" onclick="Lc_pCalc()">计算</button>
      </div>

      <div class="panel">
        <h2>计算结果（物理层）</h2>
        <p class="hint">基于 Semtech AN1200.22 空中时间公式与链路预算模型。</p>
        <div class="results">
          <div class="stat big"><div class="k">空中时间 Time on Air</div>
            <div class="v"><span id="p_toa">—</span><span class="u" id="p_toaUnit"></span></div></div>
          <div class="stat"><div class="k">符号时长 T<sub>sym</sub></div><div class="v"><span id="p_tsym">—</span><span class="u">ms</span></div></div>
          <div class="stat"><div class="k">总符号数</div><div class="v"><span id="p_syms">—</span><span class="u">sym</span></div></div>
          <div class="stat"><div class="k">前导码时长</div><div class="v"><span id="p_preambleDur">—</span><span class="u">ms</span></div></div>
          <div class="stat"><div class="k">有效数据速率</div><div class="v"><span id="p_dr">—</span><span class="u" id="p_drUnit"></span></div></div>
          <div class="stat"><div class="k">最大晶振容差</div><div class="v"><span id="p_xtal">—</span><span class="u">ppm</span></div></div>
          <div class="stat"><div class="k">接收灵敏度</div><div class="v"><span id="p_sens">—</span><span class="u">dBm</span></div></div>
          <div class="stat"><div class="k">链路预算</div><div class="v"><span id="p_lb">—</span><span class="u">dB</span></div></div>
          <div class="stat"><div class="k">TX 功耗 / RX 功耗</div><div class="v"><span id="p_pwr">—</span><span class="u">mW</span></div></div>
          <div class="stat big"><div class="k">理论最大通信距离（估算）</div>
            <div class="v"><span id="p_dist">—</span><span class="u" id="p_distUnit"></span></div></div>
        </div>
        <div class="note">
          <span class="pill">公式</span>
          空中时间 <code>ToA = (Npreamble + 4.25 + Npayload) · T_sym</code>，<code>T_sym = 2^SF / BW</code>。<br>
          <span class="pill">灵敏度</span>
          <code>Sens = -174 + 10·log10(BW) + NF + SNR_min</code>（SF7..12 最小 SNR = −7.5/−10/−12.5/−15/−17.5/−20 dB）。<br>
          <span class="pill">晶振容差</span>
          解调对频偏容忍约 ±25% 带宽 → <code>ppm = 0.25·BW / f × 1e6</code>（收发两端各占一半）。<br>
          <span class="pill">距离</span>
          <code>d = 10^((LB − 32.45 − 20·log10(f_MHz)) / (10·n))</code> km，为理论上限，实测需预留衰落余量。
        </div>
      </div>
    </div>
  </div>

  <!-- TAB 2: LoRaWAN (network / energy) -->
  <div id="panelLw" class="hidden">
    <div class="grid">
      <div class="panel">
        <h2>输入参数（LoRaWAN 网络层）</h2>
        <p class="hint">修改任意参数结果即时更新。数据率（DR）按区域自动映射 SF/BW。</p>
        <fieldset>
          <legend>LoRaWAN</legend>
          <div class="row">
            <div><label>区域 Region</label>
              <select id="l_region" onchange="Lc_lRegionChange()">
                <option value="CN470">CN470</option><option value="CN779">CN779</option>
                <option value="EU868">EU868</option><option value="US915">US915</option>
                <option value="AU915">AU915</option><option value="AS923">AS923</option>
                <option value="KR920">KR920</option><option value="IN865">IN865</option>
                <option value="RU864">RU864</option><option value="EU433">EU433</option>
              </select></div>
            <div><label>数据率 DR（上行）</label><select id="l_dr" onchange="Lc_lCalc()"></select></div>
            <div><label>RX2 数据率</label><select id="l_drRx2" onchange="Lc_lCalc()"></select></div>
          </div>
          <div class="row">
            <div><label>ADR</label><select id="l_adr"><option value="1" selected>开启</option><option value="0">关闭</option></select></div>
            <div><label>RX 延迟（s）</label><input id="l_rxdelay" type="number" step="0.1" value="1"></div>
            <div><label>Class</label><select id="l_class"><option value="A" selected>A</option><option value="B">B</option><option value="C">C</option></select></div>
          </div>
          <div class="row" id="l_classB" style="display:none">
            <div><label>Beacon 前导码长度</label><input id="l_beaconPre" type="number" value="8"></div>
            <div><label>Ping 时隙下行概率(%)</label><input id="l_pingProb" type="number" step="0.1" value="10"></div>
            <div><label>Beacon 周期</label><select id="l_beaconPer"><option value="128">128 s</option><option value="64">64 s</option><option value="32">32 s</option><option value="16">16 s</option><option value="8">8 s</option></select></div>
          </div>
        </fieldset>
        <fieldset>
          <legend>上行包 Uplink</legend>
          <div class="row">
            <div><label>负载长度（字节）</label><input id="l_pl" type="number" value="12" min="0"></div>
            <div><label>重传次数</label><input id="l_retrans" type="number" value="0" min="0"></div>
            <div><label>上行间隔（s）</label><input id="l_interval" type="number" value="900" min="1"></div>
          </div>
        </fieldset>
        <fieldset>
          <legend>下行 Downlink</legend>
          <div class="row">
            <div><label>RX 负载长度（字节）</label><input id="l_rxpl" type="number" value="8" min="0"></div>
            <div><label>RX 前导码（符号）</label><input id="l_rxpreamble" type="number" value="8"></div>
            <div><label>每日下行数</label><input id="l_dlday" type="number" value="2" min="0"></div>
            <div><label>RX1 占比(%)</label><input id="l_rx1pct" type="number" value="50" min="0" max="100"></div>
          </div>
        </fieldset>
        <fieldset>
          <legend>功耗与电池</legend>
          <div class="row">
            <div><label>TX 电流（mA）</label><input id="l_itx" type="number" step="0.1" value="30"></div>
            <div><label>RX 电流（mA）</label><input id="l_irx" type="number" step="0.1" value="5"></div>
            <div><label>休眠电流（µA）</label><input id="l_isleep" type="number" step="0.1" value="1"></div>
          </div>
          <div class="row">
            <div><label>供电电压（V）</label><input id="l_volt" type="number" step="0.1" value="3.3"></div>
            <div><label>电池容量（mAh）</label><input id="l_batt" type="number" value="2400" min="1"></div>
            <div><label>衰落余量（dB）</label><input id="l_margin" type="number" step="1" value="0"></div>
          </div>
          <div class="row">
            <div><label>传播模型（n）</label>
              <select id="l_model" onchange="Lc_lSyncN()">
                <option value="2.0">自由空间 (n=2.0)</option>
                <option value="2.4">开阔地 / 农村 (n=2.4)</option>
                <option value="2.7" selected>郊区 (n=2.7)</option>
                <option value="3.0">城市 (n=3.0)</option>
                <option value="3.5">密集城市 (n=3.5)</option>
                <option value="custom">自定义…</option>
              </select></div>
            <div id="l_nwrap" style="display:none"><label>自定义 n</label><input id="l_nval" type="number" step="0.1" value="2.7" oninput="Lc_lCalc()"></div>
            <div><label>TX 功率（dBm）</label><input id="l_txpwr" type="number" step="0.1" value="17"></div>
          </div>
        </fieldset>
        <button class="calc" onclick="Lc_lCalc()">计算</button>
      </div>

      <div class="panel">
        <h2>计算结果（LoRaWAN）</h2>
        <p class="hint">能耗与占空比基于周期平均模型估算。</p>
        <div class="results">
          <div class="stat big"><div class="k">单次上行空中时间</div>
            <div class="v"><span id="l_toa">—</span><span class="u" id="l_toaUnit"></span></div></div>

          <div class="stat"><div class="k">设备 TX 电流</div><div class="v"><span id="l_itx_out">—</span><span class="u">mA</span></div></div>
          <div class="stat"><div class="k">设备 RX 电流</div><div class="v"><span id="l_irx_out">—</span><span class="u">mA</span></div></div>
          <div class="stat"><div class="k">平均 TX 功耗</div><div class="v"><span id="l_avgTx">—</span><span class="u">µA</span></div></div>
          <div class="stat"><div class="k">平均 RX 功耗</div><div class="v"><span id="l_avgRx">—</span><span class="u">µA</span></div></div>
          <div class="stat"><div class="k">平均休眠功耗</div><div class="v"><span id="l_avgSleep">—</span><span class="u">µA</span></div></div>
          <div class="stat"><div class="k">总平均功耗</div><div class="v"><span id="l_avgTot">—</span><span class="u">µA</span></div></div>

          <div class="stat"><div class="k">每小时上行 ToA</div><div class="v"><span id="l_toaH_tx">—</span><span class="u">ms/h</span></div></div>
          <div class="stat"><div class="k">每小时下行 ToA</div><div class="v"><span id="l_toaH_rx">—</span><span class="u">ms/h</span></div></div>
          <div class="stat warnv"><div class="k">占空比 (TX)</div><div class="v"><span id="l_duty">—</span><span class="u">%</span></div></div>
          <div class="stat"><div class="k">链路预算</div><div class="v"><span id="l_lb">—</span><span class="u">dB</span></div></div>

          <div class="stat"><div class="k">接收灵敏度</div><div class="v"><span id="l_sens">—</span><span class="u">dBm</span></div></div>
          <div class="stat big"><div class="k">理论最大通信距离</div><div class="v"><span id="l_dist">—</span><span class="u" id="l_distUnit"></span></div></div>
          <div class="stat big"><div class="k">电池寿命（估算）</div><div class="v"><span id="l_battlife">—</span><span class="u" id="l_battUnit"></span></div></div>
        </div>
        <div class="note">
          <span class="pill">能耗模型</span>
          周期 = 上行间隔；周期内：TX 时长 = (1+重传)·上行ToA；RX 时长 = 每周期下行数 × (RX1占比·RX1 ToA + (1−占比)·RX2 ToA)；休眠时长 = 间隔 − TX − RX。<br>
          平均电流 <code>I_avg = (I_tx·t_tx + I_rx·t_rx + I_sleep·t_sleep) / 间隔</code>；
          电池寿命 <code>= 容量(mAh)·1000 / I_avg(µA) / 24 / 365</code> 年。<br>
          <span class="pill">占空比</span>
          <code>= 每小时 TX ToA / 3600 × 100%</code>。EU868 等区域法规上限通常为 1%（请结合实际区域核对）。<br>
          <span class="pill">说明</span>
          DR 由区域决定 SF/BW（US915/AU915 的 RX2 为 500kHz SF12）。结果为理想链路预算上限，实际部署受环境衰减影响。
        </div>
      </div>
    </div>
  </div>

  <div class="foot">holastack · LoRa / LoRaWAN 参数计算器（本地计算，不上传任何数据）</div>
</div>
`;

  // ----------------------- 入口（供 index.php SPA 调用） -----------------------
  if (typeof window !== 'undefined') {
    window.viewLoraCalc = function () {
      const view = document.getElementById('view');
      if (!view) return;
      Lc_ensureCss();
      view.innerHTML = Lc_TEMPLATE;
      Lc_applyRegion('p'); Lc_pSyncN(); Lc_pCalc();
      Lc_lRegionChange(); Lc_lSyncN(); Lc_lCalc();
    };
    // 关键修复：模板内联的 onclick/onchange（onclick="Lc_switchTab('phy')" 等）
    // 在全局作用域执行，而本模块所有函数都封装在 IIFE 内。若不挂到 window，
    // 点击 Tab / 改下拉 / 点“计算”都会因 Lc_xxx is not defined 而失效。
    // 这里把模板引用的入口函数逐个暴露给全局，修复“点击无法打开/切换”。
    window.Lc_switchTab = Lc_switchTab;
    window.Lc_applyRegion = Lc_applyRegion;
    window.Lc_pCalc = Lc_pCalc;
    window.Lc_pSyncN = Lc_pSyncN;
    window.Lc_lRegionChange = Lc_lRegionChange;
    window.Lc_lSyncN = Lc_lSyncN;
    window.Lc_lCalc = Lc_lCalc;
  }

  // 仅在 node 环境下做数值自检（浏览器有 window，不执行）
  if (typeof window === 'undefined') {
    const r = Lc_loraToA(12, 125, 12, true, false, true, 8);
    console.log('[lc self-test] ToA SF12/125kHz/12B =', (r.toaSec * 1000).toFixed(2), 'ms; totalSyms =', r.totalSyms.toFixed(1));
  }
})();
