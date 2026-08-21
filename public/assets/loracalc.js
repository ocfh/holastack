
(function () {
  'use strict';

  /* ============================================================
   * LoRa / LoRaWAN 参数计算器 —— 复刻 Semtech 官方 LoRa Calculator
   * 中文版 · 双页（LoRa 射频 / LoRaWAN 网络）· 纯前端计算
   * 设备电流/灵敏度取自各器件数据手册典型值（与官方计算器设备模型一致）
   * ============================================================ */

  // ---- 设备模型（数据手册典型值）----
  // tx 表：[功率dBm, 电流mA]，线性插值；txDC=DC-DC 稳压，txLDO=LDO 稳压
  const Lc_DEVICES = {
    LR1110: {
      label: 'LR1110', freqMin: 150, freqMax: 960, freqDef: 868.1,
      regulator: ['DC-DC', 'LDO'], defReg: 'DC-DC', paBoostMax: 22, paBoost: true,
      rf: { options: ['RF Switch'], def: 'RF Switch' },
      pa: { options: ['HP', 'LP', 'HP_LP'], def: 'HP', cap: { HP: 22, LP: 14, HP_LP: 22 } },
      nf: 5.5, rx: { hs: { ldo: 15.0, dcdc: 8.0 }, lp: { ldo: 10.02, dcdc: 5.7 } }, sleep: 0.8,
      txDC: [[10, 71.8], [14, 88.9], [17, 98.8], [22, 117]], txLDO: [[10, 74.3], [14, 91.4], [17, 101.3], [22, 119.5]],
      sfMin: 5, sfMax: 12, bw: [8, 10, 15, 20, 31, 41, 62, 125, 250, 500]
    },
    LR112x: {
      label: 'LR112x (LR1120/1121)', freqMin: 150, freqMax: 960, freqDef: 868.1,
      regulator: ['DC-DC', 'LDO'], defReg: 'DC-DC', paBoostMax: 22, paBoost: true,
      rf: { options: ['RF Switch'], def: 'RF Switch' },
      pa: { options: ['HP', 'LP', 'HP_LP'], def: 'HP', cap: { HP: 22, LP: 14, HP_LP: 22 } },
      nf: 5.5, rx: { hs: { ldo: 15.0, dcdc: 8.0 }, lp: { ldo: 10.02, dcdc: 5.7 } }, sleep: 0.8,
      txDC: [[10, 71.8], [14, 88.9], [17, 98.8], [22, 117]], txLDO: [[10, 74.3], [14, 91.4], [17, 101.3], [22, 119.5]],
      sfMin: 5, sfMax: 12, bw: [8, 10, 15, 20, 31, 41, 62, 125, 250, 500]
    },
    SX1280: {
      label: 'SX1280 (2.4GHz)', freqMin: 2400, freqMax: 2500, freqDef: 2440,
      regulator: ['DC-DC', 'LDO'], defReg: 'DC-DC', paBoostMax: 13, paBoost: true,
      rf: { options: ['RF Switch'], def: 'RF Switch' },
      pa: { options: ['HP'], def: 'HP', cap: { HP: 13 } },
      nf: 7, rx: { boosted: 9.6, normal: 9.6 }, rxLdoExtra: 1.0, sleep: 0.9,
      txDC: [[0, 8], [6, 11], [12.5, 15]], txLDO: [[0, 9], [6, 12.5], [12.5, 16]],
      sfMin: 5, sfMax: 12, bw: [200, 400, 800]
    },
    SX1261: {
      label: 'SX1261', freqMin: 150, freqMax: 960, freqDef: 868.1,
      regulator: ['DC-DC', 'LDO'], defReg: 'DC-DC', paBoostMax: 15, paBoost: false,
      rf: { options: ['RF Switch'], def: 'RF Switch' },
      pa: { options: ['HP'], def: 'HP', cap: { HP: 15 } },
      nf: 5, rx: { boosted: 4.2, normal: 4.2 }, rxLdoExtra: 0.8, sleep: 0.8,
      txDC: [[2, 14], [10, 25], [15, 45]], txLDO: [[2, 16], [10, 28], [15, 52]],
      sfMin: 5, sfMax: 12, bw: [8, 10, 15, 20, 31, 41, 62, 125, 250, 500]
    },
    SX1262: {
      label: 'SX1262', freqMin: 150, freqMax: 960, freqDef: 868.1,
      regulator: ['DC-DC', 'LDO'], defReg: 'DC-DC', paBoostMax: 22, paBoost: true,
      rf: { options: ['RF Switch'], def: 'RF Switch' },
      pa: { options: ['HP', 'LP', 'HP_LP'], def: 'HP', cap: { HP: 22, LP: 14, HP_LP: 22 } },
      nf: 5.5, rx: { hs: { ldo: 15.0, dcdc: 8.0 }, lp: { ldo: 10.02, dcdc: 5.7 } }, sleep: 0.8,
      txDC: [[10, 71.8], [14, 88.9], [17, 98.8], [22, 117]], txLDO: [[10, 74.3], [14, 91.4], [17, 101.3], [22, 119.5]],
      sfMin: 5, sfMax: 12, bw: [8, 10, 15, 20, 31, 41, 62, 125, 250, 500]
    },
    SX127X: {
      label: 'SX127X (SX1276/77/78/79)', freqMin: 137, freqMax: 1020, freqDef: 868.1,
      regulator: ['LDO'], defReg: 'LDO', paBoostMax: 20, paBoost: true,
      rf: { options: ['RF Switch', 'Shared RFIO'], def: 'RF Switch' },
      nf: 6, rx: { boosted: 12.0, normal: 10.5 }, rxLdoExtra: 0, sleep: 1.0,
      tx: [[2, 18], [7, 21], [13, 28], [15, 30], [17, 120], [20, 130]],
      sfMin: 6, sfMax: 12, bw: [8, 10, 15, 20, 31, 41, 62, 125, 250, 500]
    }
  };
  const Lc_DEVICE_ORDER = ['LR1110', 'LR112x', 'SX1280', 'SX1261', 'SX1262', 'SX127X'];

  // SF 最小解调 SNR（LoRa）——官方计算器实际使用的表（略低于教科书值）
  const Lc_SNR = { 5: -2.5, 6: -5.5, 7: -8.2, 8: -11, 9: -14, 10: -16.7, 11: -19.5, 12: -21.5 };
  const Lc_FSK_SNR = 10; // FSK 解调典型所需 SNR
  // LoRa 标称带宽 → 实际频率（官方计算器取值，如 8kHz=7810Hz）
  const Lc_BW_HZ = { 8: 7810, 10: 10417, 15: 15625, 20: 20833, 31: 31250, 41: 41667, 62: 62500, 125: 125000, 250: 250000, 500: 500000, 200: 203125, 400: 406250, 800: 812500 };

  // LoRaWAN 区域 → DR 映射 [SF, BW(kHz)] 与默认频率 / RX2-DR
  const Lc_REGIONS = {
    EU868: { freq: 868.1, rx2: 0, drs: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250] } },
    US915: { freq: 903.9, rx2: 8, drs: { 0: [10, 125], 1: [9, 125], 2: [8, 125], 3: [7, 125], 4: [8, 500], 8: [12, 500], 9: [11, 500], 10: [10, 500], 11: [9, 500], 12: [8, 500], 13: [7, 500] } },
    AU915: { freq: 915.2, rx2: 8, drs: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [8, 500], 8: [12, 500], 9: [11, 500], 10: [10, 500], 11: [9, 500], 12: [8, 500], 13: [7, 500] } },
    CN470: { freq: 470.3, rx2: 0, drs: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250] } },
    AS923: { freq: 923.2, rx2: 2, drs: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250] } },
    KR920: { freq: 922.1, rx2: 0, drs: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250] } },
    IN865: { freq: 865.0625, rx2: 2, drs: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125], 6: [7, 250] } },
    RU864: { freq: 864.1, rx2: 0, drs: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125] } },
    EU433: { freq: 433.175, rx2: 0, drs: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125] } },
    CN779: { freq: 779.5, rx2: 0, drs: { 0: [12, 125], 1: [11, 125], 2: [10, 125], 3: [9, 125], 4: [8, 125], 5: [7, 125] } }
  };

  // ---- 工具函数 ----
  function Lc_interp(tbl, p) {
    if (p <= tbl[0][0]) return tbl[0][1];
    const last = tbl[tbl.length - 1];
    if (p >= last[0]) return last[1];
    for (let i = 0; i < tbl.length - 1; i++) {
      const [p0, i0] = tbl[i], [p1, i1] = tbl[i + 1];
      if (p >= p0 && p <= p1) {
        const f = (p - p0) / (p1 - p0);
        return i0 + f * (i1 - i0);
      }
    }
    return last[1];
  }
  function Lc_txCurrent(dev, p, reg, rfpath) {
    let tbl = dev.txDC ? (reg === 'DC-DC' ? dev.txDC : (dev.txLDO || dev.txDC)) : dev.tx;
    if (rfpath && dev.rf && dev.rf.cur && dev.rf.cur[rfpath]) tbl = dev.rf.cur[rfpath];
    const pp = Math.min(Math.max(p, tbl[0][0]), tbl[tbl.length - 1][0]);
    return Lc_interp(tbl, pp);
  }
  function Lc_rxCurrent(dev, reg, mode) {
    if (dev.rx && dev.rx.hs) {
      const m = dev.rx[mode] || dev.rx.hs;
      const r = String(reg).toLowerCase();
      return m[r] || m.dcdc || 0;
    }
    const boosted = mode === 'hs';
    const base = boosted ? dev.rx.boosted : dev.rx.normal;
    return base + (reg === 'LDO' ? (dev.rxLdoExtra || 0) : 0);
  }
  function Lc_fmt(x, d) {
    if (!isFinite(x)) return '—';
    return x.toLocaleString('zh-CN', { minimumFractionDigits: d, maximumFractionDigits: d });
  }
  function Lc_uA(x) {
    if (!isFinite(x)) return '—';
    if (x >= 1000) return Lc_fmt(x / 1000, 2) + ' mA';
    return Lc_fmt(x, 1) + ' µA';
  }
  function Lc_num(id, def) {
    const el = document.getElementById(id);
    if (!el) return def;
    const v = parseFloat(el.value);
    return isFinite(v) ? v : def;
  }
  function Lc_chk(id) { const el = document.getElementById(id); return !!(el && el.checked); }
  function Lc_val(id, def) { const el = document.getElementById(id); return el ? el.value : def; }

  // ---- LoRa 空中时间（与官方 Semtech 计算器一致）----
  // 负载符号数：SF5/6 用官方低 SF 公式（round-half-up/ceil，无 -4SF 无尾部 +8）；SF7+ 用标准公式
  function Lc_payloadSyms(sf, bwKHz, payload, crcOn, implicit, ldro, crN) {
    const ih = implicit ? 1 : 0, de = ldro ? 1 : 0, crc = crcOn ? 1 : 0;
    const den = 4 * (sf - 2 * de);
    if (sf <= 6) {
      const num = 8 * payload + 28 + 16 * crc - 20 * ih;
      const v = num / den;
      let b;
      if (de === 1) b = (sf === 5) ? Math.round(v) : Math.ceil(v);
      else b = Math.round(v) + 1;
      return Math.max(b, 0) * (crN + 4);
    }
    const num = 8 * payload - 4 * sf + 28 + 16 * crc - 20 * ih;
    return 8 + Math.max(Math.ceil(num / den), 0) * (crN + 4);
  }
  function Lc_toA(sf, bwKHz, payload, crcOn, implicit, ldro, preamble, crN) {
    const bw = (Lc_BW_HZ[bwKHz] ?? bwKHz * 1000);
    const tsym = Math.pow(2, sf) / bw;
    const nPayload = Lc_payloadSyms(sf, bwKHz, payload, crcOn, implicit, ldro, crN);
    const preambleSyms = preamble + 4.25;
    const totalSyms = preambleSyms + nPayload;
    return { tsym, nPayload, preambleSyms, totalSyms, toaSec: totalSyms * tsym };
  }
  function Lc_fskToA(bitrateKbps, preambleBits, syncBytes, payload, crcOn, lenByte) {
    const bps = bitrateKbps * 1000;
    const bits = preambleBits + syncBytes * 8 + (lenByte ? 8 : 0) + payload * 8 + (crcOn ? 16 : 0);
    return { toaSec: bits / bps, bits };
  }

  function Lc_sensLoRa(sf, bwKHz, nf) {
    const bwHz = Lc_BW_HZ[bwKHz] ?? bwKHz * 1000;
    return -174 + 10 * Math.log10(bwHz) + nf + (Lc_SNR[sf] ?? -20);
  }
  function Lc_sensFsk(bwKHz, nf) {
    const bwHz = Lc_BW_HZ[bwKHz] ?? bwKHz * 1000;
    return -174 + 10 * Math.log10(bwHz) + nf + Lc_FSK_SNR;
  }
  function Lc_xtalLoRa(tsym, fHz, boosted) {
    // 官方公式：MaxCrystalTolerance(Hz) = 8/tsym；ppm = Hz/freq
    return (8 / tsym) / fHz * 1e6;
  }
  function Lc_xtalFsk(bitrateHz, fHz) {
    return (0.5 * bitrateHz) / fHz * 1e6;
  }
  function Lc_range(lb, fMHz, n) {
    return Math.pow(10, (lb - 32.45 - 20 * Math.log10(fMHz)) / (10 * n));
  }

  // ---- 模板构建 ----
  function Lc_deviceOpts(sel) {
    return Lc_DEVICE_ORDER.map(d => `<option value="${d}"${d === sel ? ' selected' : ''}>${Lc_DEVICES[d].label}</option>`).join('');
  }
  function Lc_regOpts(dev, sel) {
    return dev.regulator.map(r => `<option value="${r}"${r === sel ? ' selected' : ''}>${r}</option>`).join('');
  }
  function Lc_bwOpts(list, sel) {
    return list.map(b => `<option${b === sel ? ' selected' : ''}>${b}</option>`).join('');
  }
  function Lc_sfOpts(min, max, sel) {
    let s = '';
    for (let sf = max; sf >= min; sf--) s += `<option${sf === sel ? ' selected' : ''}>${sf}</option>`;
    return s;
  }
  function Lc_rfOpts(dev, sel) {
    const opts = (dev.rf && dev.rf.options) || ['RF Switch'];
    return opts.map(o => `<option${o === sel ? ' selected' : ''}>${o}</option>`).join('');
  }
  function Lc_paOpts(dev, sel) {
    const opts = (dev.pa && dev.pa.options) || ['HP'];
    return opts.map(o => `<option${o === sel ? ' selected' : ''}>${o}</option>`).join('');
  }
  function Lc_rfCap(dev, rfPath, pa) {
    if (dev.pa && dev.pa.cap) return dev.pa.cap[pa] ?? dev.paBoostMax;
    return dev.paBoostMax;
  }

  function Lc_template() {
    const d0 = Lc_DEVICES.LR1110;
    return `
<div class="lc-head">
  <h2>LoRa / LoRaWAN 参数计算器</h2>
  <p class="hint">复刻 Semtech 官方 LoRa Calculator · 中文版 · 所有计算在浏览器本地完成，不上传任何数据。</p>
</div>
<div class="tabs">
  <div class="tab active" id="tabLoRa" onclick="Lc_switchTab('LoRa')">LoRa（射频）</div>
  <div class="tab" id="tabLW" onclick="Lc_switchTab('LW')">LoRaWAN（网络）</div>
</div>

<!-- ============ Tab 1: LoRa ============ -->
<div id="panelLoRa">
  <div class="grid">
    <div class="panel">
      <h2>输入参数</h2>
      <p class="hint">修改任意参数结果即时更新。</p>

      <fieldset>
        <legend>设备 Device</legend>
        <label>器件型号</label>
        <select id="r_device" onchange="Lc_rDevice()">${Lc_deviceOpts('LR1110')}</select>
      </fieldset>

      <fieldset>
        <legend>射频路径 RF Path</legend>
        <div class="row">
          <div><label>射频路径 RF Path</label><select id="r_rfpath" onchange="Lc_rRfPath()"></select></div>
          <div><label>功率放大器 Power Amplifier</label><select id="r_pa" onchange="Lc_rRfPath()"></select></div>
        </div>
        <div class="row">
          <div><label>稳压器模式 Regulator</label><select id="r_reg" onchange="Lc_rCalc()">${Lc_regOpts(d0, d0.defReg)}</select></div>
          <div><label>RF 发射功率 (dBm)</label><input id="r_txpower" type="number" step="0.1" value="17" oninput="Lc_rCalc()"></div>
        </div>
        <div class="row">
          <div><label>接收模式 Receiver Mode</label>
            <select id="r_rxmode" onchange="Lc_rCalc()"><option value="hs" selected>高灵敏度 High Sensitivity</option><option value="lp">低功耗 Low Power</option></select></div>
          <div><label>斜坡时间 Ramp Time (µs)</label><input id="r_ramp" type="number" step="1" value="16" oninput="Lc_rCalc()"></div>
        </div>
      </fieldset>

      <fieldset>
        <legend>频率与调制 Modem</legend>
        <div class="row">
          <div><label>频率 Frequency (MHz)</label><input id="r_freq" type="number" step="0.0001" value="868.1" oninput="Lc_rCalc()"></div>
          <div><label>调制方式 Modulation</label>
            <select id="r_mod" onchange="Lc_rMod()"><option value="LoRa" selected>LoRa</option><option value="FSK">FSK</option></select></div>
        </div>
        <div id="r_loraGrp">
          <div class="row">
            <div><label>扩频因子 SF</label><select id="r_sf" onchange="Lc_rCalc()">${Lc_sfOpts(d0.sfMin, d0.sfMax, Math.min(12, d0.sfMax))}</select></div>
            <div><label>带宽 BW (kHz)</label><select id="r_bw" onchange="Lc_rCalc()">${Lc_bwOpts(d0.bw, 125)}</select></div>
            <div><label>编码率 CR</label>
              <select id="r_cr" onchange="Lc_rCalc()"><option value="1" selected>4/5</option><option value="2">4/6</option><option value="3">4/7</option><option value="4">4/8</option></select></div>
          </div>
          <div class="row">
            <div><label>低速率优化 LDRO</label>
              <select id="r_ldro" onchange="Lc_rCalc()"><option value="auto" selected>自动</option><option value="1">开启</option><option value="0">关闭</option></select></div>
          </div>
        </div>
        <div id="r_fskGrp" style="display:none">
          <div class="row">
            <div><label>频偏 Frequency Deviation (kHz)</label><input id="r_fdev" type="number" step="0.1" value="25" oninput="Lc_rCalc()"></div>
            <div><label>数据速率 Data Rate (kbps)</label><input id="r_fdr" type="number" step="0.1" value="50" oninput="Lc_rCalc()"></div>
          </div>
          <div class="readout" id="r_midx">调制指数 Modulation Index：—</div>
        </div>
      </fieldset>

      <fieldset>
        <legend>数据包 Packet</legend>
        <div class="row">
          <div><label>前导码长度 Preamble</label><input id="r_preamble" type="number" step="1" value="8" min="1" oninput="Lc_rCalc()"></div>
          <div><label>负载长度 Payload (Bytes)</label><input id="r_payload" type="number" step="1" value="12" min="0" oninput="Lc_rCalc()"></div>
        </div>
        <div class="row" id="r_hdrRow">
          <div><label>报头 Header</label>
            <select id="r_header" onchange="Lc_rCalc()"><option value="0" selected>显式 Explicit</option><option value="1">隐式 Implicit</option></select></div>
          <div><label>同步字长度 Sync Word (Bytes)</label><input id="r_sync" type="number" step="1" value="3" min="0" oninput="Lc_rCalc()"></div>
        </div>
        <div class="check"><input id="r_crc" type="checkbox" checked onchange="Lc_rCalc()"><label for="r_crc">CRC 校验开启</label></div>
        <div class="check"><input id="r_iq" type="checkbox" onchange="Lc_rCalc()"><label for="r_iq">IQ 反转（IQ Inverted）</label></div>
      </fieldset>

      <fieldset>
        <legend>时序 Timing</legend>
        <div class="row">
          <div><label>TX Period (ms)</label><input id="r_txperiod" type="number" step="1" value="100" oninput="Lc_rCalc()"></div>
          <div><label>RX Duration (ms)</label><input id="r_rxdur" type="number" step="1" value="100" oninput="Lc_rCalc()"></div>
          <div><label>RX Period (ms)</label><input id="r_rxperiod" type="number" step="1" value="1000" oninput="Lc_rCalc()"></div>
        </div>
        <div class="row">
          <div><label>休眠电流 Sleep (µA)</label><input id="r_sleep" type="number" step="0.1" value="1" oninput="Lc_rCalc()"></div>
        </div>
      </fieldset>
      <button class="calc" onclick="Lc_rCalc()">计算 / Submit</button>
    </div>

    <div class="panel">
      <h2>计算结果 Results</h2>
      <div class="resblock">
        <div class="restitle">时序结果 Timing Results</div>
        <div class="results">
          <div class="stat big"><div class="k">空中时间 Time on Air</div><div class="v"><span id="r_toa">—</span><span class="u" id="r_toaU"></span></div></div>
          <div class="stat"><div class="k">总符号数 Total symbol</div><div class="v"><span id="r_syms">—</span></div></div>
          <div class="stat"><div class="k">符号时长 Symbol Time</div><div class="v"><span id="r_tsym">—</span><span class="u">ms</span></div></div>
          <div class="stat"><div class="k">前导码时长 Preamble Duration</div><div class="v"><span id="r_pre">—</span><span class="u">ms</span></div></div>
          <div class="stat"><div class="k">有效数据速率 Effective Data Rate</div><div class="v"><span id="r_dr">—</span><span class="u" id="r_drU"></span></div></div>
        </div>
      </div>
      <div class="resblock">
        <div class="restitle">射频性能 RF Performance</div>
        <div class="results">
          <div class="stat"><div class="k">接收灵敏度 Receiver Sensitivity</div><div class="v"><span id="r_sens">—</span><span class="u">dBm</span></div></div>
          <div class="stat"><div class="k">链路预算 Link Budget</div><div class="v"><span id="r_lb">—</span><span class="u">dB</span></div></div>
          <div class="stat"><div class="k">最大晶振容差 Max Crystal Tolerance</div><div class="v"><span id="r_xtal">—</span><span class="u">ppm</span></div></div>
          <div class="stat"><div class="k">射频功耗 Radio Consumption</div><div class="v"><span id="r_radiocons">—</span><span class="u">mA</span></div></div>
        </div>
      </div>
      <div class="resblock">
        <div class="restitle">能耗结果 Energy consumption results</div>
        <div class="results">
          <div class="stat"><div class="k">设备 TX 电流 Device Tx Current</div><div class="v"><span id="r_txcur">—</span><span class="u">mA</span></div></div>
          <div class="stat"><div class="k">设备 RX 电流 Device Rx Current</div><div class="v"><span id="r_rxcur">—</span><span class="u">mA</span></div></div>
          <div class="stat"><div class="k">平均 TX 功耗 Avg Tx Consumption</div><div class="v"><span id="r_avgTx">—</span></div></div>
          <div class="stat"><div class="k">平均 RX 功耗 Avg Rx Consumption</div><div class="v"><span id="r_avgRx">—</span></div></div>
          <div class="stat"><div class="k">平均休眠功耗 Avg Sleep Consumption</div><div class="v"><span id="r_avgSl">—</span></div></div>
          <div class="stat"><div class="k">总平均功耗 Total Average Consumption</div><div class="v"><span id="r_avgTot">—</span></div></div>
          <div class="stat"><div class="k">版本 version</div><div class="v"><span id="r_ver">v1.0</span></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ============ Tab 2: LoRaWAN ============ -->
<div id="panelLW" class="hidden">
  <div class="grid">
    <div class="panel">
      <h2>输入参数</h2>
      <p class="hint">修改任意参数结果即时更新。数据率 DR 按区域自动映射 SF/BW。</p>

      <fieldset>
        <legend>设备 Device</legend>
        <label>器件型号</label>
        <select id="w_device" onchange="Lc_wDevice()">${Lc_deviceOpts('SX1262')}</select>
      </fieldset>

      <fieldset>
        <legend>射频路径 RF Path</legend>
        <div class="row">
          <div><label>射频路径 RF Path</label><select id="w_rfpath" onchange="Lc_wRfPath()"></select></div>
          <div><label>功率放大器 Power Amplifier</label><select id="w_pa" onchange="Lc_wRfPath()"></select></div>
        </div>
        <div class="row">
          <div><label>稳压器模式 Regulator</label><select id="w_reg" onchange="Lc_wCalc()">${Lc_regOpts(Lc_DEVICES.SX1262, 'DC-DC')}</select></div>
          <div><label>TX 功率 (dBm)</label><input id="w_txpower" type="number" step="0.1" value="17" oninput="Lc_wCalc()"></div>
        </div>
        <div class="row">
          <div><label>接收模式 Receiver Mode</label>
            <select id="w_rxmode" onchange="Lc_wCalc()"><option value="hs" selected>高灵敏度 High Sensitivity</option><option value="lp">低功耗 Low Power</option></select></div>
          <div><label>斜坡时间 Ramp Time (µs)</label><input id="w_ramp" type="number" step="1" value="16" oninput="Lc_wCalc()"></div>
        </div>
      </fieldset>

      <fieldset>
        <legend>LoRaWAN</legend>
        <div class="row">
          <div><label>区域 Region</label><select id="w_region" onchange="Lc_wRegion()">${Object.keys(Lc_REGIONS).map(r => `<option value="${r}"${r === 'CN470' ? ' selected' : ''}>${r}</option>`).join('')}</select></div>
          <div><label>数据率 DR（上行）</label><select id="w_dr" onchange="Lc_wCalc()"></select></div>
          <div><label>数据率 DR [Rx2]</label><select id="w_dr2" onchange="Lc_wCalc()"></select></div>
        </div>
        <div class="row">
          <div><label>ADR</label><select id="w_adr" onchange="Lc_wCalc()"><option value="1" selected>开启</option><option value="0">关闭</option></select></div>
          <div><label>RX 延迟 Rx Delay (s)</label><input id="w_rxdelay" type="number" step="0.1" value="1" oninput="Lc_wCalc()"></div>
          <div><label>Class</label><select id="w_class" onchange="Lc_wClass()"><option value="A" selected>A</option><option value="B">B</option><option value="C">C</option></select></div>
        </div>
        <div class="row" id="w_classB" style="display:none">
          <div><label>Beacon 前导码长度</label><input id="w_beaconpre" type="number" value="8" oninput="Lc_wCalc()"></div>
          <div><label>Ping 时隙下行概率 (%)</label><input id="w_pingprob" type="number" step="0.1" value="10" oninput="Lc_wCalc()"></div>
          <div><label>Beacon 周期</label><select id="w_beaconper" onchange="Lc_wCalc()"><option value="128">128 s</option><option value="64">64 s</option><option value="32">32 s</option><option value="16">16 s</option><option value="8">8 s</option></select></div>
        </div>
      </fieldset>

      <fieldset>
        <legend>上行包 Uplink Packet</legend>
        <div class="row">
          <div><label>负载长度 Payload (Bytes)</label><input id="w_pl" type="number" value="12" min="0" oninput="Lc_wCalc()"></div>
          <div><label>重传次数 Retransmissions</label><input id="w_retrans" type="number" value="0" min="0" oninput="Lc_wCalc()"></div>
          <div><label>上行间隔 Interval (s)</label><input id="w_interval" type="number" value="900" min="1" oninput="Lc_wCalc()"></div>
        </div>
      </fieldset>

      <fieldset>
        <legend>下行 Downlink</legend>
        <div class="row">
          <div><label>RX 负载长度 (Bytes)</label><input id="w_rxpl" type="number" value="8" min="0" oninput="Lc_wCalc()"></div>
          <div><label>RX 前导码 (符号)</label><input id="w_rxpreamble" type="number" value="8" oninput="Lc_wCalc()"></div>
          <div><label>每日下行数 / day</label><input id="w_dlday" type="number" value="2" min="0" oninput="Lc_wCalc()"></div>
          <div><label>RX1 占比 on Rx1 (%)</label><input id="w_rx1pct" type="number" value="50" min="0" max="100" oninput="Lc_wCalc()"></div>
        </div>
        <div class="row">
          <div><label>MCU 休眠电流 Sleep (µA)</label><input id="w_mcusleep" type="number" step="0.1" value="1" oninput="Lc_wCalc()"></div>
          <div><label>环境 Environment（路径损耗）</label>
            <select id="w_env" onchange="Lc_wCalc()"><option value="2.0">视距 LoS (n=2.0)</option><option value="2.5">农村 (n=2.5)</option><option value="3.0" selected>郊区 (n=3.0)</option><option value="3.5">城市 (n=3.5)</option></select></div>
        </div>
      </fieldset>
      <button class="calc" onclick="Lc_wCalc()">计算 / Submit</button>
    </div>

    <div class="panel">
      <h2>计算结果 Results</h2>
      <div class="resblock">
        <div class="restitle">能耗结果 Energy consumption results</div>
        <div class="results">
          <div class="stat"><div class="k">设备 TX 电流 Device Tx Current</div><div class="v"><span id="w_txcur">—</span><span class="u">mA</span></div></div>
          <div class="stat"><div class="k">设备 RX 电流 Device Rx Current</div><div class="v"><span id="w_rxcur">—</span><span class="u">mA</span></div></div>
          <div class="stat"><div class="k">平均 TX 功耗 Avg Tx Consumption</div><div class="v"><span id="w_avgTx">—</span></div></div>
          <div class="stat"><div class="k">平均 RX 功耗 Avg Rx Consumption</div><div class="v"><span id="w_avgRx">—</span></div></div>
          <div class="stat"><div class="k">平均休眠功耗 Avg Sleep Consumption</div><div class="v"><span id="w_avgSl">—</span></div></div>
          <div class="stat"><div class="k">总平均功耗 Total Average Consumption</div><div class="v"><span id="w_avgTot">—</span></div></div>
        </div>
      </div>
      <div class="resblock">
        <div class="restitle">时序结果 Timing Results</div>
        <div class="results">
          <div class="stat big"><div class="k">单次上行空中时间 Time on Air per uplink</div><div class="v"><span id="w_toa">—</span><span class="u" id="w_toaU"></span></div></div>
          <div class="stat"><div class="k">每小时上行 ToA Avg Transmit ToA/hour</div><div class="v"><span id="w_toaHtx">—</span><span class="u">ms/h</span></div></div>
          <div class="stat"><div class="k">每小时下行 ToA Avg Receive ToA/hour</div><div class="v"><span id="w_toaHrx">—</span><span class="u">ms/h</span></div></div>
          <div class="stat warnv"><div class="k">占空比 Duty Cycle</div><div class="v"><span id="w_duty">—</span><span class="u">%</span></div></div>
        </div>
      </div>
      <div class="resblock">
        <div class="restitle">射频性能 RF Performance</div>
        <div class="results">
          <div class="stat"><div class="k">链路预算 Link Budget</div><div class="v"><span id="w_lb">—</span><span class="u">dB</span></div></div>
          <div class="stat"><div class="k">接收灵敏度 Sensitivity</div><div class="v"><span id="w_sens">—</span><span class="u">dBm</span></div></div>
          <div class="stat"><div class="k">最大晶振容差 Max Crystal tolerance</div><div class="v"><span id="w_xtal">—</span><span class="u">ppm</span></div></div>
          <div class="stat big"><div class="k">理论最大通信距离 Range</div><div class="v"><span id="w_range">—</span><span class="u" id="w_rangeU"></span></div></div>
          <div class="stat"><div class="k">版本 version</div><div class="v"><span id="w_ver">v1.0</span></div></div>
        </div>
      </div>
    </div>
  </div>
  <div class="lc-refresh">
    <span>自动刷新</span>
    <select id="lc_refresh" onchange="Lc_refresh()">
      <option value="0" selected>手动刷新</option>
      <option value="5">5 秒</option>
      <option value="10">10 秒</option>
      <option value="15">15 秒</option>
      <option value="30">30 秒</option>
      <option value="60">1 分钟</option>
    </select>
  </div>
</div>`;
  }

  // ---- Tab 切换 ----
  function Lc_switchTab(which) {
    const lora = which === 'LoRa';
    document.getElementById('panelLoRa').classList.toggle('hidden', !lora);
    document.getElementById('panelLW').classList.toggle('hidden', lora);
    document.getElementById('tabLoRa').classList.toggle('active', lora);
    document.getElementById('tabLW').classList.toggle('active', !lora);
  }

  // ---- 右下角自动刷新 ----
  let Lc_refreshTimer = null;
  function Lc_refresh() {
    if (Lc_refreshTimer) { clearInterval(Lc_refreshTimer); Lc_refreshTimer = null; }
    const el = document.getElementById('lc_refresh');
    const sec = el ? parseInt(el.value, 10) : 0;
    if (sec > 0) {
      Lc_refreshTimer = setInterval(() => {
        if (document.getElementById('lcRoot')) { Lc_rCalc(); Lc_wCalc(); }
      }, sec * 1000);
    }
  }

  // ---- 设备联动（LoRa 页）----
  function Lc_rDevice() {
    const dev = Lc_DEVICES[Lc_val('r_device', 'LR1110')];
    document.getElementById('r_reg').innerHTML = Lc_regOpts(dev, dev.defReg);
    document.getElementById('r_freq').value = dev.freqDef;
    document.getElementById('r_bw').innerHTML = Lc_bwOpts(dev.bw, dev.bw.indexOf(125) >= 0 ? 125 : dev.bw[dev.bw.length - 1]);
    document.getElementById('r_sf').innerHTML = Lc_sfOpts(dev.sfMin, dev.sfMax, Math.min(12, dev.sfMax));
    const rfSel = document.getElementById('r_rfpath');
    rfSel.innerHTML = Lc_rfOpts(dev, (dev.rf && dev.rf.def) || 'RF Switch');
    const paSel = document.getElementById('r_pa');
    paSel.innerHTML = Lc_paOpts(dev, (dev.pa && dev.pa.def) || 'HP');
    Lc_rRfPath(false);
    Lc_rCalc();
  }
  function Lc_rRfPath(recalc) {
    const dev = Lc_DEVICES[Lc_val('r_device', 'LR1110')];
    const cap = Lc_rfCap(dev, Lc_val('r_rfpath', 'RF Switch'), Lc_val('r_pa', 'HP'));
    const tp = document.getElementById('r_txpower');
    tp.max = cap;
    tp.value = Math.min(parseFloat(tp.value) || 17, cap);
    if (recalc !== false) Lc_rCalc();
  }
  function Lc_rMod() {
    const fsk = Lc_val('r_mod', 'LoRa') === 'FSK';
    document.getElementById('r_loraGrp').style.display = fsk ? 'none' : '';
    document.getElementById('r_fskGrp').style.display = fsk ? '' : 'none';
    document.getElementById('r_hdrRow').style.display = fsk ? 'none' : '';
    Lc_rCalc();
  }

  // ---- LoRa 页计算 ----
  function Lc_rCalc() {
    const dev = Lc_DEVICES[Lc_val('r_device', 'LR1110')];
    const reg = Lc_val('r_reg', 'DC-DC');
    const rfpath = Lc_val('r_rfpath', 'RF Switch');
    const pa = Lc_val('r_pa', 'HP');
    const txpower = Math.min(Lc_num('r_txpower', 17), Lc_rfCap(dev, rfpath, pa));
    const fMHz = Lc_num('r_freq', 868.1);
    const fHz = fMHz * 1e6;
    const mod = Lc_val('r_mod', 'LoRa');
    const rxmode = Lc_val('r_rxmode', 'hs');
    const sleepUA = Lc_num('r_sleep', 1);
    const txPeriod = Math.max(0, Lc_num('r_txperiod', 100));
    const rxDur = Math.max(0, Lc_num('r_rxdur', 100));
    const rxPeriod = Math.max(0, Lc_num('r_rxperiod', 1000));

    const Itx = Lc_txCurrent(dev, txpower, reg, rfpath);
    const Irx = Lc_rxCurrent(dev, reg, rxmode);

    let toaSec = 0, tsym = 0, totalSyms = 0, preambleSyms = 0, sens = 0, xtal = 0, effDr = 0;
    if (mod === 'FSK') {
      const fdev = Lc_num('r_fdev', 25);
      const fdr = Lc_num('r_fdr', 50);
      const payload = Math.max(0, Lc_num('r_payload', 12));
      const preambleBits = Math.max(0, Lc_num('r_preamble', 8)) * 8;
      const sync = Math.max(0, Lc_num('r_sync', 3));
      const crcOn = Lc_chk('r_crc');
      const lenByte = true;
      const r = Lc_fskToA(fdr, preambleBits, sync, payload, crcOn, lenByte);
      toaSec = r.toaSec;
      tsym = 1 / (fdr * 1000);
      totalSyms = r.bits;
      preambleSyms = preambleBits / (fdr * 1000);
      sens = Lc_sensFsk(Lc_num('r_fdr', 50) * 2, dev.nf); // 近似：以 2×数据速率估算占用带宽
      xtal = Lc_xtalFsk(fdr * 1000, fHz);
      effDr = (payload * 8) / toaSec;
      document.getElementById('r_midx').textContent = '调制指数 Modulation Index：' + Lc_fmt(2 * fdev / fdr, 3);
    } else {
      const sf = parseInt(Lc_val('r_sf', '12'), 10);
      const bw = parseFloat(Lc_val('r_bw', '125'));
      const crN = parseInt(Lc_val('r_cr', '1'), 10);
      const payload = Math.max(0, Lc_num('r_payload', 12));
      const crcOn = Lc_chk('r_crc');
      const implicit = Lc_val('r_header', '0') === '1';
      const preamble = Math.max(1, Lc_num('r_preamble', 8));
      const ldroMode = Lc_val('r_ldro', 'auto');
      const ts = Math.pow(2, sf) / (bw * 1000);
      let ldro = false;
      if (ldroMode === 'auto') ldro = ts >= 0.016;
      else ldro = ldroMode === '1';
      const r = Lc_toA(sf, bw, payload, crcOn, implicit, ldro, preamble, crN);
      toaSec = r.toaSec; tsym = r.tsym; totalSyms = r.totalSyms; preambleSyms = r.preambleSyms;
      sens = Lc_sensLoRa(sf, bw, dev.nf);
      xtal = Lc_xtalLoRa(tsym, fHz);
      effDr = (sf * (4 / (4 + crN)) * (bw * 1000) / Math.pow(2, sf));
    }

    if (toaSec < 1) { document.getElementById('r_toa').textContent = Lc_fmt(toaSec * 1000, 2); document.getElementById('r_toaU').textContent = 'ms'; }
    else { document.getElementById('r_toa').textContent = Lc_fmt(toaSec, 3); document.getElementById('r_toaU').textContent = 's'; }
    document.getElementById('r_syms').textContent = Lc_fmt(totalSyms, 1);
    document.getElementById('r_tsym').textContent = Lc_fmt(tsym * 1000, 3);
    document.getElementById('r_pre').textContent = Lc_fmt(preamble * tsym * 1000, 3);
    if (effDr >= 1000) { document.getElementById('r_dr').textContent = Lc_fmt(effDr / 1000, 3); document.getElementById('r_drU').textContent = 'kbps'; }
    else { document.getElementById('r_dr').textContent = Lc_fmt(effDr, 1); document.getElementById('r_drU').textContent = 'bps'; }

    const lb = txpower - sens;
    document.getElementById('r_sens').textContent = Lc_fmt(sens, 2);
    document.getElementById('r_lb').textContent = Lc_fmt(lb, 2);
    document.getElementById('r_xtal').textContent = Lc_fmt(xtal, 2);
    document.getElementById('r_radiocons').textContent = Lc_fmt(Itx, 1);

    // 能耗：周期 T = tx + rx + rxPeriod
    const T = Math.max(1e-6, (txPeriod + rxDur + rxPeriod) / 1000);
    const fTx = (txPeriod / 1000) / T, fRx = (rxDur / 1000) / T, fSl = (rxPeriod / 1000) / T;
    const avgTx = Itx * 1000 * fTx;     // µA
    const avgRx = Irx * 1000 * fRx;     // µA
    const avgSl = sleepUA * fSl;        // µA
    const avgTot = avgTx + avgRx + avgSl;
    document.getElementById('r_txcur').textContent = Lc_fmt(Itx, 1);
    document.getElementById('r_rxcur').textContent = Lc_fmt(Irx, 1);
    document.getElementById('r_avgTx').textContent = Lc_uA(avgTx);
    document.getElementById('r_avgRx').textContent = Lc_uA(avgRx);
    document.getElementById('r_avgSl').textContent = Lc_uA(avgSl);
    document.getElementById('r_avgTot').textContent = Lc_uA(avgTot);
  }

  // ---- 设备联动（LoRaWAN 页）----
  function Lc_wDevice() {
    const dev = Lc_DEVICES[Lc_val('w_device', 'SX1262')];
    document.getElementById('w_reg').innerHTML = Lc_regOpts(dev, dev.defReg);
    const rfSel = document.getElementById('w_rfpath');
    rfSel.innerHTML = Lc_rfOpts(dev, (dev.rf && dev.rf.def) || 'RF Switch');
    const paSel = document.getElementById('w_pa');
    paSel.innerHTML = Lc_paOpts(dev, (dev.pa && dev.pa.def) || 'HP');
    Lc_wRfPath(false);
    Lc_wCalc();
  }
  function Lc_wRfPath(recalc) {
    const dev = Lc_DEVICES[Lc_val('w_device', 'SX1262')];
    const cap = Lc_rfCap(dev, Lc_val('w_rfpath', 'RF Switch'), Lc_val('w_pa', 'HP'));
    const tp = document.getElementById('w_txpower');
    tp.max = cap;
    tp.value = Math.min(parseFloat(tp.value) || 17, cap);
    if (recalc !== false) Lc_wCalc();
  }
  function Lc_wRegion() {
    const reg = Lc_REGIONS[Lc_val('w_region', 'CN470')];
    const drSel = document.getElementById('w_dr');
    drSel.innerHTML = Object.keys(reg.drs).map(k => `<option value="${k}">DR${k} (SF${reg.drs[k][0]}/BW${reg.drs[k][1]})</option>`).join('');
    drSel.value = (reg.drs['2'] ? '2' : Object.keys(reg.drs)[0]);
    const dr2Sel = document.getElementById('w_dr2');
    dr2Sel.innerHTML = Object.keys(reg.drs).map(k => `<option value="${k}">DR${k} (SF${reg.drs[k][0]}/BW${reg.drs[k][1]})</option>`).join('');
    dr2Sel.value = String(reg.rx2);
    Lc_wCalc();
  }
  function Lc_wClass() {
    const c = Lc_val('w_class', 'A');
    document.getElementById('w_classB').style.display = (c === 'B') ? '' : 'none';
    Lc_wCalc();
  }

  // ---- LoRaWAN 页计算 ----
  function Lc_wCalc() {
    const dev = Lc_DEVICES[Lc_val('w_device', 'SX1262')];
    const reg = Lc_val('w_reg', 'DC-DC');
    const rfpath = Lc_val('w_rfpath', 'RF Switch');
    const pa = Lc_val('w_pa', 'HP');
    const txpower = Math.min(Lc_num('w_txpower', 17), Lc_rfCap(dev, rfpath, pa));
    const region = Lc_VAL_check('w_region', 'CN470');
    const regDef = Lc_REGIONS[region] || Lc_REGIONS.CN470;
    const fMHz = regDef.freq;
    const fHz = fMHz * 1e6;
    const [sf, bw] = regDef.drs[Lc_val('w_dr', '2')] || [12, 125];
    const [sf2, bw2] = regDef.drs[Lc_val('w_dr2', '0')] || [12, 125];
    const crcOn = true, implicit = false;
    const payload = Math.max(0, Lc_num('w_pl', 12));
    const retrans = Math.max(0, Lc_num('w_retrans', 0));
    const interval = Math.max(1, Lc_num('w_interval', 900));
    const rxpl = Math.max(0, Lc_num('w_rxpl', 8));
    const rxpreamble = Math.max(1, Lc_num('w_rxpreamble', 8));
    const dlday = Math.max(0, Lc_num('w_dlday', 2));
    const rx1pct = Math.min(100, Math.max(0, Lc_num('w_rx1pct', 50))) / 100;
    const mcusleep = Lc_num('w_mcusleep', 1);
    const n = Lc_num('w_env', 3.0);
    const rxmode = Lc_val('w_rxmode', 'hs');

    const tsUp = Math.pow(2, sf) / (bw * 1000);
    const ldroUp = tsUp >= 0.016;
    const up = Lc_toA(sf, bw, payload, crcOn, implicit, ldroUp, 8, 1);
    const rx1 = Lc_toA(sf, bw, rxpl, crcOn, implicit, false, rxpreamble, 1);
    const rx2 = Lc_toA(sf2, bw2, rxpl, crcOn, implicit, false, rxpreamble, 1);
    const toaUp = up.toaSec;
    const rxPerDown = rx1pct * rx1.toaSec + (1 - rx1pct) * rx2.toaSec;

    const uplinksPerHour = 3600 / interval;
    const txActiveH = (1 + retrans) * toaUp * uplinksPerHour;       // s/h
    const downlinksPerHour = dlday / 24;
    const rxActiveH = downlinksPerHour * rxPerDown;                  // s/h
    const sleepH = Math.max(0, 3600 - txActiveH - rxActiveH);

    const Itx = Lc_txCurrent(dev, txpower, reg, rfpath);
    const Irx = Lc_rxCurrent(dev, reg, rxmode);
    const avgTx = Itx * 1000 * (txActiveH / 3600);
    const avgRx = Irx * 1000 * (rxActiveH / 3600);
    const avgSl = mcusleep * (sleepH / 3600);
    const avgTot = avgTx + avgRx + avgSl;

    const sens = Lc_sensLoRa(sf, bw, dev.nf);
    const lb = txpower - sens;
    const xtal = Lc_xtalLoRa(tsUp, fHz);
    const rangeKm = Lc_range(lb, fMHz, n);

    if (toaUp < 1) { document.getElementById('w_toa').textContent = Lc_fmt(toaUp * 1000, 2); document.getElementById('w_toaU').textContent = 'ms'; }
    else { document.getElementById('w_toa').textContent = Lc_fmt(toaUp, 3); document.getElementById('w_toaU').textContent = 's'; }
    document.getElementById('w_toaHtx').textContent = Lc_fmt(txActiveH * 1000, 1);
    document.getElementById('w_toaHrx').textContent = Lc_fmt(rxActiveH * 1000, 1);
    document.getElementById('w_duty').textContent = Lc_fmt(txActiveH / 3600 * 100, 4);

    document.getElementById('w_txcur').textContent = Lc_fmt(Itx, 1);
    document.getElementById('w_rxcur').textContent = Lc_fmt(Irx, 1);
    document.getElementById('w_avgTx').textContent = Lc_uA(avgTx);
    document.getElementById('w_avgRx').textContent = Lc_uA(avgRx);
    document.getElementById('w_avgSl').textContent = Lc_uA(avgSl);
    document.getElementById('w_avgTot').textContent = Lc_uA(avgTot);

    document.getElementById('w_lb').textContent = Lc_fmt(lb, 2);
    document.getElementById('w_sens').textContent = Lc_fmt(sens, 2);
    document.getElementById('w_xtal').textContent = Lc_fmt(xtal, 2);
    if (rangeKm >= 1) { document.getElementById('w_range').textContent = Lc_fmt(rangeKm, 2); document.getElementById('w_rangeU').textContent = 'km'; }
    else { document.getElementById('w_range').textContent = Lc_fmt(rangeKm * 1000, 0); document.getElementById('w_rangeU').textContent = 'm'; }
  }
  function Lc_VAL_check(id, def) { const v = Lc_val(id, def); return v || def; }

  // ---- CSS ----
  const Lc_CSS = `
.loracalc{--lc-panel2:var(--bg-subtle)}
.loracalc *{box-sizing:border-box}
.loracalc .lc-head h2{margin:0 0 4px;font-size:18px;color:var(--txt)}
.loracalc .hint{color:var(--mut);font-size:12px;margin:0 0 14px}
.loracalc .tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.loracalc .tab{background:var(--panel);border:1px solid var(--line);color:var(--mut);padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:14px}
.loracalc .tab.active{background:var(--acc);color:var(--txt-on-acc);border-color:var(--acc)}
.loracalc .grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start}
@media (max-width:860px){ .loracalc .grid{grid-template-columns:1fr} }
.loracalc .panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px 20px}
.loracalc .panel h2{margin:0 0 4px;font-size:15px;color:var(--txt)}
.loracalc fieldset{border:1px solid var(--line);border-radius:10px;margin:0 0 14px;padding:12px 14px}
.loracalc legend{color:var(--acc);font-size:12px;padding:0 6px;font-weight:600}
.loracalc label{display:block;color:var(--mut);margin:10px 0 4px;font-size:12px}
.loracalc input,.loracalc select{background:var(--bg-deep);color:var(--txt);border:1px solid var(--line);border-radius:7px;padding:9px 10px;width:100%;font-family:inherit;font-size:14px}
.loracalc .row{display:flex;gap:12px;flex-wrap:wrap}
.loracalc .row>div{flex:1;min-width:110px}
.loracalc .check{display:flex;align-items:center;gap:8px;margin:10px 0 2px}
.loracalc .check input{width:auto}
.loracalc .check label{margin:0;color:var(--txt);font-size:13px}
.loracalc .readout{color:var(--mut);font-size:12px;margin-top:8px}
.loracalc button.calc{background:var(--acc);color:var(--txt-on-acc);border:0;padding:11px 18px;border-radius:8px;cursor:pointer;font-weight:700;font-size:14px;width:100%;margin-top:6px}
.loracalc .resblock{margin-bottom:14px}
.loracalc .restitle{color:var(--acc);font-size:12px;font-weight:700;margin:6px 0 8px;letter-spacing:.3px}
.loracalc .results{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:520px){ .loracalc .results{grid-template-columns:1fr} }
.loracalc .stat{background:var(--lc-panel2);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
.loracalc .stat .k{color:var(--mut);font-size:12px;margin-bottom:6px;line-height:1.4}
.loracalc .stat .v{font-size:21px;font-weight:700;color:var(--acc);word-break:break-all}
.loracalc .stat .u{font-size:12px;color:var(--mut);margin-left:4px;font-weight:400}
.loracalc .stat.big{grid-column:1 / -1;background:var(--bg-subtle)}
.loracalc .stat.big .v{font-size:28px;color:var(--ok)}
.loracalc .stat.warnv .v{color:var(--warn)}
.loracalc .lc-refresh{position:fixed;right:22px;bottom:22px;z-index:120;display:flex;align-items:center;gap:8px;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:8px 12px;box-shadow:0 4px 16px rgba(var(--shadow-rgba),.25)}
.loracalc .lc-refresh span{color:var(--mut);font-size:12px;white-space:nowrap}
.loracalc .lc-refresh select{width:auto;min-width:110px;padding:6px 8px;font-size:13px;margin:0}
.loracalc .hidden{display:none}
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

  // ---- 入口 ----
  if (typeof window !== 'undefined') {
    window.viewLoraCalc = async function () {
      const view = document.getElementById('view');
      if (!view) return;
      Lc_ensureCss();
      const root = document.getElementById('lcRoot');
      if (root) {
        root.innerHTML = Lc_template();
      } else {
        view.innerHTML = '<div class="loracalc" id="lcRoot"></div>';
        document.getElementById('lcRoot').innerHTML = Lc_template();
      }
      Lc_rDevice();
      Lc_rMod();
      Lc_wDevice();
      Lc_wRegion();
      Lc_wClass();
      Lc_rCalc();
      Lc_wCalc();
      Lc_refresh();
    };

    window.Lc_switchTab = Lc_switchTab;
    window.Lc_refresh = Lc_refresh;
    window.Lc_rDevice = Lc_rDevice;
    window.Lc_rMod = Lc_rMod;
    window.Lc_rRfPath = Lc_rRfPath;
    window.Lc_rCalc = Lc_rCalc;
    window.Lc_wDevice = Lc_wDevice;
    window.Lc_wRfPath = Lc_wRfPath;
    window.Lc_wRegion = Lc_wRegion;
    window.Lc_wClass = Lc_wClass;
    window.Lc_wCalc = Lc_wCalc;
  }
})();
