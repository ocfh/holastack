async function viewDashboard(){
  const prevOpen = [];
  document.querySelectorAll('.log-col').forEach(c => { if (!c.classList.contains('collapsed')) prevOpen.push(c.getAttribute('data-kind')); });
  const s = await api('GET','/api/stats'); state.stats = s;
  const devTotal = s.devices|0, devOn = s.devices_online|0, devOff = s.devices_offline|0;
  const gwTotal = s.gateways|0, gwOn = s.gateways_online|0, gwOff = s.gateways_offline|0;
  const appTotal = s.applications|0;
  const dpsTotal = s.device_profiles|0, mcTotal = s.multicast_groups|0;
  const msgTotal = (s.uplinks|0) + (s.downlinks|0);
  const devLogs = s.device_logs||[], gwLogs = s.gateway_logs||[];
  const mobile = window.innerWidth <= 760;
  const devOpen = !mobile || prevOpen.includes('dev');
  const gwOpen = !mobile || prevOpen.includes('gw');
  document.getElementById('view').innerHTML = `
    <div class="view-head"><h2>${ICON[VIEW_ICONS['dashboard']]||''}概览</h2></div>
    <div class="rings">
      ${dashRingCard('设备', devTotal, devOn, devOff, true)}
      ${dashRingCard('网关', gwTotal, gwOn, gwOff, true)}
      ${dashRingCard('应用', appTotal, 0, 0, false, `<div class="hl-row hl-split"><span>${t('设备模板')} <b>${dpsTotal}</b></span><span>${t('组播组')} <b>${mcTotal}</b></span></div>`)}
    </div>

    <div class="msg-bar">
      <div class="msg-main"><span class="msg-num">${msgTotal}</span><span class="msg-lbl">消息总数</span></div>
      <div class="msg-split">
        <div><span class="up">▲</span> ${t('上行')} <b>${s.uplinks|0}</b></div>
        <div><span class="down">▼</span> ${t('下行')} <b>${s.downlinks|0}</b></div>
      </div>
    </div>

    <div class="log-cols">
      <div class="log-col ${devOpen?'':'collapsed'}" data-kind="dev">
        <div class="log-head" onclick="toggleLogCol(this)">
          <h3>最近设备日志</h3>
          <span class="log-fold"><span class="log-chev">${ICON.chevronRight}</span><span class="log-fold-txt">${devOpen?t('折叠'):t('展开')}</span></span>
          <button class="log-more" onclick="event.stopPropagation();nav('uplinks')">查看全部</button>
        </div>
        <div class="log-body"><div class="log-inner">${devLogs.length? devLogs.map(e=>dashUpRow(e)).join('') : '<div class="log-empty">暂无设备日志</div>'}</div></div>
      </div>
      <div class="log-col ${gwOpen?'':'collapsed'}" data-kind="gw">
        <div class="log-head" onclick="toggleLogCol(this)">
          <h3>最近网关日志</h3>
          <span class="log-fold"><span class="log-chev">${ICON.chevronRight}</span><span class="log-fold-txt">${gwOpen?t('折叠'):t('展开')}</span></span>
          <button class="log-more" onclick="event.stopPropagation();nav('events')">查看全部</button>
        </div>
        <div class="log-body"><div class="log-inner">${gwLogs.length? gwLogs.map(e=>dashLogRow(e,'网关 '+esc(e.gateway_id||''))).join('') : '<div class="log-empty">暂无网关日志</div>'}</div></div>
      </div>
    </div>`;
}
async function toggleLogCol(head){
  if (window.innerWidth > 760) return;
  const col = head.closest('.log-col');
  const expanding = col.classList.contains('collapsed');
  col.classList.toggle('collapsed');
  const fold = col.querySelector('.log-fold');
  if (fold) fold.querySelector('.log-fold-txt').textContent = expanding ? t('折叠') : t('展开');
  if (!expanding) return;
  const kind = col.getAttribute('data-kind');
  try {
    const r = await api('GET','/api/stats');
    const logs = kind==='dev' ? (r.device_logs||[]) : (r.gateway_logs||[]);
    col.querySelector('.log-body .log-inner').innerHTML = logs.length
      ? logs.map(e=> kind==='dev' ? dashUpRow(e) : dashLogRow(e, '网关 '+esc(e.gateway_id||''))).join('')
      : '<div class="log-empty">暂无'+(kind==='dev'?'设备':'网关')+'日志</div>';
  } catch(e){}
}

function dashRingCard(title, total, online, offline, split, extra){
  const r=70, cx=90, cy=90, sw=18, C=2*Math.PI*r;

  const cs = getComputedStyle(document.documentElement);
  const cLine = cs.getPropertyValue('--line').trim() || '#2b3650';
  const cTxt  = cs.getPropertyValue('--txt').trim() || '#e6ecf5';
  const cMut  = cs.getPropertyValue('--mut').trim() || '#8b97ad';
  const cOk   = cs.getPropertyValue('--ok').trim() || '#36d399';
  const cErr  = cs.getPropertyValue('--err').trim() || '#f87272';
  const cAcc  = cs.getPropertyValue('--acc').trim() || '#3da9fc';
  let arcs;
  if(!total){
    arcs = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cLine}" stroke-width="${sw}"/>`;
  } else if(split){
    const onLen = C*online/total, offLen = C*offline/total;
    arcs = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cLine}" stroke-width="${sw}"/>
      <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cOk}" stroke-width="${sw}" stroke-dasharray="${onLen.toFixed(2)} ${C.toFixed(2)}" transform="rotate(-90 ${cx} ${cy})"/>
      <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cErr}" stroke-width="${sw}" stroke-dasharray="${offLen.toFixed(2)} ${C.toFixed(2)}" stroke-dashoffset="${(-onLen).toFixed(2)}" transform="rotate(-90 ${cx} ${cy})"/>`;
  } else {
    arcs = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${cAcc}" stroke-width="${sw}"/>`;
  }
  let legend;
  if(split){
    legend = `
      <div class="hl-row hl-online"><span><span class="dot"></span>在线</span><b>${online} <span class="pct">(${total?Math.round(online/total*100):0}%)</span></b></div>
      <div class="hl-row hl-offline"><span><span class="dot"></span>离线</span><b>${offline} <span class="pct">(${total?Math.round(offline/total*100):0}%)</span></b></div>`;
  } else {
    legend = `<div class="hl-row"><span>应用总数</span><b>${total}</b></div>`;
  }

  legend += extra || '';
  return `<div class="ring-card">
    <svg viewBox="0 0 180 180" class="ring">${arcs}
      <text x="${cx}" y="${cy-2}" text-anchor="middle" fill="${cTxt}" font-size="32" font-weight="700">${total}</text>
      <text x="${cx}" y="${cy+18}" text-anchor="middle" fill="${cMut}" font-size="12">${title}</text>
    </svg>
    <div class="ring-legend">${legend}</div>
  </div>`;
}
function dashLogRow(ev, who){
  const lvl = (ev.level==='error')?'err':((ev.level==='warn'||ev.level==='warning')?'pending':'ok');
  const t = ev.created_at? new Date(ev.created_at*1000).toLocaleString() : '-';
  return `<div class="log-row">
    <div class="log-top"><span class="tag">${esc(ev.type)}</span><span class="tag ${lvl}">${esc(ev.level)}</span><span class="log-who">${esc(who)}</span><span class="log-time">${esc(t)}</span></div>
    <div class="log-msg">${esc(ev.message||'')}</div>
  </div>`;
}
function dashUpRow(e){
  const tm = e.received_at? new Date(e.received_at*1000).toLocaleString() : '-';
  const who = 'dev #' + (e.dev_id||'') + (e.dev_addr? ' ('+esc(e.dev_addr)+')' : '');
  const payload = e.decrypted_hex || e.payload_hex || '';
  const sig = `${t('RSSI')} ${e.rssi??'-'} · ${t('SNR')} ${e.snr??'-'}`;
  return `<div class="log-row">
    <div class="log-top"><span class="tag up">UPLINK</span><span class="log-who">${esc(who)}</span><span class="log-time">${esc(tm)}</span></div>
    <div class="log-msg">${t('FCnt')} ${e.fcnt??'-'} · ${t('端口')} ${e.port??'-'} · ${esc(sig)}${payload? ' · '+esc(payload):''}</div>
  </div>`;
}
const rawBtn = (id, fn) => `<button class="raw-btn" title="查看原始 JSON" onclick="${fn}(${id})">${ICON.magnifyingGlass}</button>`;
const frameBtn = (id, fn) => `<button class="raw-btn" title="${t('帧结构检视')}" onclick="${fn}(${id})">${ICON.codeBracket}</button>`;

async function tenantFilterHtml(){
  if (!isAdmin()) return '';
  let opts = '';
  try {
    const r = await api('GET','/api/tenants');
    opts = (r.data||[]).map(row=>`<option value="${row.id}" ${String(state.tenantFilter)===String(row.id)?'selected':''}>${esc(row.name)}</option>`).join('');
  } catch(e){}
  return `<div style="flex:0 0 220px"><label>用户配置筛选</label><select id="tf" onchange="state.tenantFilter=this.value;nav(state.view)"><option value="">全部用户配置</option>${opts}</select></div>`;
}
async function viewApplications(){
  const q = state.tenantFilter ? `?tenant_id=${state.tenantFilter}` : '';
  const [r, tf] = await Promise.all([api('GET','/api/applications'+q), tenantFilterHtml()]);
  state.apps = r.data||[];
  const cfg = {
    state, stateKey:'appsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (a, k) => ({id:a.id, name:a.name, app_eui:a.app_eui, cb:a.callback_url||'', time:a.created_at}[k]),
    cols:[
      {key:'id',      label:'ID',        type:'num', firstDir:'asc', sortable:false},
      {key:'name',    label:'名称',       type:'str', firstDir:'asc', sortable:false},
      {key:'app_eui', label:'AppEUI',    type:'str', firstDir:'asc', sortable:false},
      {key:'cb',      label:'回调 URL',   type:'str', firstDir:'asc', sortable:false},
      {key:'time',    label:'创建时间',   type:'time', firstDir:'desc'},
      {key:'_raw',    label:'',          type:'raw'},
    ],
    rows: state.apps,
    rowHtml: a => `<tr><td>${a.id}</td><td>${esc(a.name)}</td><td class="muted">${esc(a.app_eui)}</td><td class="muted">${esc(a.callback_url||'')}</td><td class="muted">${new Date(a.created_at*1000).toLocaleString()}</td>
     <td>${adminBtn(`<button class="btn ghost" onclick="editApplication(${a.id})">${ICON.pencilSquare}编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delApplication(${a.id}))">${ICON.trash}删除</button>`)} <button class="btn ghost" onclick="newDevice(${a.id})">${ICON.plus}设备</button></td></tr>`,
    emptyText:'暂无应用',
  };

  const [filteredRows, filteredTotal] = filterAndSortRows(cfg);
  cfg.rows = paginateRows(filteredRows, state, {pageKey:'appsPage', limitKey:'appsLimit', offsetKey:'appsOffset'})[0];
  cfg.presorted = true;
  const table = buildSortableTable(cfg);
  const pager = buildPager({ total: filteredTotal, limit: state.appsLimit, offset: state.appsOffset, pageKey:'appsPage', limitKey:'appsLimit', offsetKey:'appsOffset', totalKey:'appsTotal', refresh:'viewApplications' });
  window.appsSort_sort = col => _tableToggleSort('appsSort','viewApplications',col);
  window.viewApplications__page = p => _pagerGo({pageKey:'appsPage',limitKey:'appsLimit',offsetKey:'appsOffset',totalKey:'appsTotal'},'viewApplications',p);
  window.viewApplications__limit = l => _pagerSetLimit({pageKey:'appsPage',limitKey:'appsLimit',offsetKey:'appsOffset',totalKey:'appsTotal'},'viewApplications',l);
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['applications']]||''}应用</h2>${adminBtn('<button onclick="newApplication()">'+ICON.plus+'新建应用</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<button class="btn ghost" onclick="resetFilters(()=>{state.appsPage=1;state.appsOffset=0;state.appsLimit=50;}, viewApplications)">${ICON.arrowPath}重置</button></div>
    ${table}
    ${pager}`;
}
async function viewDevices(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const q = [tq, state.devAppFilter ? ('app_id='+state.devAppFilter) : ''].filter(Boolean).join('&');
  const [r, ar, tf] = await Promise.all([
    api('GET','/api/devices'+(q?'?'+q:'')),
    api('GET','/api/applications'+(tq?'?'+tq:'')),
    tenantFilterHtml()
  ]);
  state.devs = r.data||[];
  const apps = ar.data||[];
  const appName = id => { const a = apps.find(x=>x.id===id); return a ? esc(a.name) : ('#'+id); };
  const appOpts = `<option value="">全部应用</option>` + apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.devAppFilter)?'selected':''}>${esc(a.name)}</option>`).join('');

  const activationValues = [
    {value:'', label:'全部'},
    {value:'OTAA', label:'OTAA'},
    {value:'ABP',  label:'ABP'},
  ];
  const classValues = [
    {value:'', label:'全部'},
    {value:'A', label:'Class A'},
    {value:'B', label:'Class B'},
    {value:'C', label:'Class C'},
  ];
  const onlineValues = [
    {value:'', label:'全部'},
    {value:'online',  label:'在线'},
    {value:'offline', label:'离线'},
  ];
  const devStatusValues = [
    {value:'', label:'全部'},
    {value:'active',   label:'active · 已入网'},
    {value:'pending',  label:'pending · 待入网'},
    {value:'disabled', label:'disabled · 已禁用'},
  ];
  const devCfg = {
    state, stateKey:'devsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (d, k) => ({id:d.id, name:d.name, app:appName(d.app_id), activation:d.activation, cls:d.class, online:d.online==='online'?'online':'offline', dev_eui:d.dev_eui, dev_addr:d.dev_addr, status:d.status, time:+d.last_seen||0}[k]),
    cols:[
      {key:'id',         label:'ID',      type:'num', firstDir:'asc', sortable:false},
      {key:'name',       label:'名称',     type:'str', firstDir:'asc', sortable:false},
      {key:'app',        label:'应用',     type:'str', firstDir:'asc', sortable:false},
      {key:'activation', label:'激活',     type:'status', firstDir:'asc', sortable:false, opts:{getValue:d=>d.activation, values:activationValues}},
      {key:'cls',        label:'Class',   type:'status', firstDir:'asc', sortable:false, opts:{getValue:d=>d.class, values:classValues}},
      {key:'online',     label:'状态',     type:'status', firstDir:'asc', sortable:false, opts:{getValue:d=>d.online==='online'?'online':'offline', values:onlineValues}},
      {key:'dev_eui',    label:'DevEUI',  type:'str', firstDir:'asc', sortable:false},
      {key:'dev_addr',   label:'DevAddr', type:'str', firstDir:'asc', sortable:false},
      {key:'status',     label:'入网',     type:'status', firstDir:'asc', sortable:false, opts:{getValue:d=>d.status, values:devStatusValues}},
      {key:'time',       label:'最近/遥测', type:'time', firstDir:'desc'},
      {key:'_raw',       label:'',        type:'raw'},
    ],
    filterStatusList: [
      {col:'activation', value: state.devsFActivation},
      {col:'cls',        value: state.devsFCls},
      {col:'online',     value: state.devsFOnline},
      {col:'status',     value: state.devsFStatus},
    ],
    rows: state.devs,
    rowHtml: d => {
      const online = d.online==='online';
      const tel = [];
      if (d.battery!==null && d.battery!==undefined && +d.battery>=0) tel.push(t('电量')+(+d.battery===0?t('外电'):(+d.battery)+'%'));
      if (d.margin!==null && d.margin!==undefined && d.margin!=='') tel.push(t('余量')+(+d.margin)+'dB');
      if (d.latitude && +d.latitude!==0 && d.longitude!==null) tel.push('GPS '+ (+d.latitude).toFixed(5)+','+(+d.longitude).toFixed(5));
      const telStr = tel.length? `<div class="muted" style="font-size:11px">${tel.join(' · ')}</div>`:'';
      const seen = (d.last_seen_fmt && d.last_seen_fmt!=='-') ? d.last_seen_fmt : '-';
      return `<tr>
        <td>${d.id}</td><td>${esc(d.name)}</td>
        <td class="muted"><span class="pill" style="margin:0">${appName(d.app_id)}</span></td>
        <td><span class="tag">${d.activation}</span></td>
        <td><span class="tag ${d.class}">${d.class}</span></td>
        <td><span class="tag ${online?'ok':'off'}">${online?'在线':'离线'}</span></td>
        <td class="muted">${hex(d.dev_eui)}</td><td class="muted">${hex(revAddr(d.dev_addr))}</td>
        <td><span class="tag ${d.status==='active'?'ok':'pending'}">${d.status}</span></td>
        <td class="muted">${seen}${telStr}</td>
        <td>${adminBtn(`<button class="btn ghost" onclick="editDevice(${d.id})">${ICON.pencilSquare}编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delDevice(${d.id}))">${ICON.trash}删除</button>`)} <button class="btn ghost" onclick="deviceDetail(${d.id})">${ICON.key}密钥</button> <button class="btn ghost" onclick="downlink(${d.id})">${ICON.arrowDownTray}下行</button></td></tr>`;
    },
    emptyText:'暂无设备',
  };
  const [filteredDevs, devsTotal] = filterAndSortRows(devCfg);
  devCfg.rows = paginateRows(filteredDevs, state, {pageKey:'devsPage', limitKey:'devsLimit', offsetKey:'devsOffset'})[0];
  devCfg.presorted = true;
  const table = buildSortableTable(devCfg);
  const pager = buildPager({ total: devsTotal, limit: state.devsLimit, offset: state.devsOffset, pageKey:'devsPage', limitKey:'devsLimit', offsetKey:'devsOffset', totalKey:'devsTotal', refresh:'viewDevices' });
  window.devsSort_sort = col => _tableToggleSort('devsSort','viewDevices',col);

  window.devsSort_fstatus = (col, v) => {
    const map = {activation:'devsFActivation', cls:'devsFCls', online:'devsFOnline', status:'devsFStatus'};
    _tableSetFStatus(map[col] || 'devsFStatus', 'viewDevices', v);
  };
  window.viewDevices__page = p => _pagerGo({pageKey:'devsPage',limitKey:'devsLimit',offsetKey:'devsOffset',totalKey:'devsTotal'},'viewDevices',p);
  window.viewDevices__limit = l => _pagerSetLimit({pageKey:'devsPage',limitKey:'devsLimit',offsetKey:'devsOffset',totalKey:'devsTotal'},'viewDevices',l);
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['devices']]||''}设备</h2>${adminBtn('<div style="display:inline-flex;gap:8px;flex-wrap:wrap;margin-left:auto"><button onclick="newDevice()">'+ICON.plus+'添加设备</button><button class="btn ghost" onclick="importDevicesForm()">批量导入</button><button class="btn ghost" onclick="exportDevicesCsv()">导出CSV</button></div>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 240px"><label>按应用筛选</label><select id="devAppFilter" onchange="state.devAppFilter=this.value;viewDevices()">${appOpts}</select></div>
    <button class="btn ghost" onclick="resetFilters(()=>{state.devAppFilter='';state.devsFActivation='';state.devsFCls='';state.devsFOnline='';state.devsFStatus='';state.devsSort={col:'time',dir:'desc'};state.devsPage=1;state.devsOffset=0;state.devsLimit=50;}, viewDevices)">${ICON.arrowPath}重置</button></div>
    ${table}
    ${pager}`;
}

window.importDevicesForm = function () {
  const appOpts = (state.apps || []).map(a => `<option value="${a.id}">#${a.id} ${esc(a.name)}</option>`).join('');
  openModal(`<h3>批量导入设备</h3>
    <label>目标应用</label><select id="imp_app">${appOpts}</select>
    <label>格式</label><select id="imp_fmt"><option value="csv">CSV</option><option value="json">JSON</option></select>
    <label>数据（CSV 含表头，或 JSON 数组 / {"rows":[...]}）</label>
    <textarea id="imp_raw" rows="10" style="width:100%;font-family:monospace;font-size:12px" placeholder="name,dev_eui,activation,app_key,join_eui&#10;温湿度1,70b3d57ed0000001,OTAA,00000000000000000000000000000000,0000000000000000"></textarea>
    <p class="muted" style="font-size:12px">CSV 列：name,dev_eui,activation(OTAA|ABP),app_key,join_eui,nwk_s_key,app_s_key,dev_addr,region,class,device_profile_id。ABP 需提供 dev_addr / nwk_s_key / app_s_key。未指定 device_profile_id 时自动用该应用租户下的第一个模板。</p>
    <div class="modal-foot"><button class="btn" onclick="submitDeviceImport()">导入</button></div>`);
};

window.submitDeviceImport = async function () {
  const appId = +document.getElementById('imp_app').value;
  const fmt = document.getElementById('imp_fmt').value;
  const raw = document.getElementById('imp_raw').value;
  if (!appId) { alert('请选择应用'); return; }
  const r = await api('POST', '/api/devices/import', { app_id: appId, raw, format: fmt });
  if (r.error) { alert('导入失败：' + r.error); return; }
  const ok = r.created || 0, fail = r.failed || 0;
  let msg = `导入完成：成功 ${ok} 条，失败 ${fail} 条。`;
  if (fail > 0) {
    msg += '\n\n失败明细：\n' + (r.errors || []).map(e => `第${e.row}行 ${e.dev_eui || ''}: ${e.error}`).join('\n');
  }
  alert(msg);
  closeModal();
  viewDevices();
};

window.exportDevicesCsv = function () {
  const rows = state.devs || [];
  if (!rows.length) { alert('当前没有可导出的设备'); return; }
  const cols = ['id', 'name', 'app_id', 'dev_eui', 'dev_addr', 'activation', 'class', 'status', 'region'];
  const head = cols.join(',');
  const body = rows.map(d => cols.map(c => {
    let v = d[c];
    if (v === null || v === undefined) v = '';
    v = String(v);
    return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
  }).join(',')).join('\n');
  const csv = '﻿' + head + '\n' + body;
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'devices_' + Date.now() + '.csv';
  document.body.appendChild(a); a.click(); a.remove();
  URL.revokeObjectURL(a.href);
};

function hexToBytes(hex){
  hex = (hex||'').replace(/[^0-9a-fA-F]/g,'');
  const out=[];
  for(let i=0;i<hex.length;i+=2) out.push(parseInt(hex.substr(i,2),16));
  return out;
}
function decodeCayenneLpp(hex){
  const b=hexToBytes(hex); let i=0; const out=[];
  const need=(n)=>{ if(b.length-i<n) return null; const s=b.slice(i,i+n); i+=n; return s; };

  const s16=(x)=>{ const v=(x[0]<<8)|x[1]; return (v&0x8000)?(v-0x10000):v; };
  while(i+2<=b.length){
    const chan=b[i], type=b[i+1]; i+=2;
    let r=null;
    if(type===0x00||type===0x01){ const x=need(1); if(x===null)break; r={type:(type===0?'digital_in':'digital_out'), value:x[0]}; }
    else if(type===0x02||type===0x03){ const x=need(2); if(x===null)break; r={type:(type===0x02?'analog_in':'analog_out'), value:+(s16(x)/100).toFixed(2)}; }
    else if(type===0x65){ const x=need(2); if(x===null)break; r={type:'luminosity', value:(x[0]*256+x[1])}; }
    else if(type===0x66){ const x=need(1); if(x===null)break; r={type:'presence', value:x[0]}; }
    else if(type===0x67){ const x=need(2); if(x===null)break; r={type:'temperature', value:+(s16(x)/10).toFixed(1)}; }
    else if(type===0x68){ const x=need(1); if(x===null)break; r={type:'humidity', value:+(x[0]/2).toFixed(1)}; }
    else if(type===0x71){ const x=need(6); if(x===null)break; r={type:'accelerometer', value:[+(s16(x.slice(0,2))/1000).toFixed(3),+(s16(x.slice(2,4))/1000).toFixed(3),+(s16(x.slice(4,6))/1000).toFixed(3)]}; }
    else if(type===0x72){ const x=need(2); if(x===null)break; r={type:'barometer', value:+((x[0]*256+x[1])/10).toFixed(1)}; }
    else if(type===0x73){ const x=need(6); if(x===null)break; r={type:'gyrometer', value:[+(s16(x.slice(0,2))/100).toFixed(2),+(s16(x.slice(2,4))/100).toFixed(2),+(s16(x.slice(4,6))/100).toFixed(2)]}; }
    else if(type===0x88){ const x=need(11); if(x===null)break; const s32=(q)=>{ const v=((q[0]<<24)|(q[1]<<16)|(q[2]<<8)|q[3])>>>0; return (v&0x80000000)?(v-0x100000000):v; }; let alt=(x[8]<<16)|(x[9]<<8)|x[10]; if(alt&0x800000) alt-=0x1000000; r={type:'gps', value:{latitude:+(s32(x.slice(0,4))/1e7).toFixed(7), longitude:+(s32(x.slice(4,8))/1e7).toFixed(7), altitude:+(alt/100).toFixed(2)}}; }
    else break;
    if(r) out.push({type:String(chan)+'.'+r.type, value:r.value});
  }
  return out;
}
function decodePayload(codecJson, hex){
  if(!hex) return [];
  let cfg={runtime:'NONE'};
  try { if(codecJson) cfg=JSON.parse(codecJson); } catch(e){}
  if(cfg.runtime==='CAYENNE_LPP') return decodeCayenneLpp(hex);
  if(cfg.runtime==='JS' && cfg.script){
    try {
      const fn=new Function('hex','bytes', cfg.script);
      const bytes=hexToBytes(hex);
      const out=fn(hex, bytes);
      if(Array.isArray(out)) return out;
      if(out && typeof out==='object') return Object.entries(out).map(([k,v])=>({type:k, value:v}));
    } catch(e){ return [{type:'decode_error', value:String(e.message||e)}]; }
  }
  return [];
}
function drawSignalChart(canvasId, points){
  const cv=document.getElementById(canvasId); if(!cv) return;
  const W=cv.width=(cv.parentElement?cv.parentElement.clientWidth:600)||600, H=cv.height=170;
  const ctx=cv.getContext('2d'); ctx.clearRect(0,0,W,H);
  if(!points||points.length===0){ ctx.fillStyle='#7d8aa0'; ctx.font='12px sans-serif'; ctx.fillText('暂无上行信号数据',12,H/2); return; }
  const pad=30;
  const xAt=i=> pad + (points.length===1?0:(i/(points.length-1))*(W-pad*2));
  const yR=v=> H-22 - ((Math.max(-130,Math.min(-40,v))+130)/90)*(H-44);
  const yS=v=> H-22 - ((Math.max(-20,Math.min(15,v))+20)/35)*(H-44);
  ctx.strokeStyle='rgba(120,140,160,0.18)'; ctx.lineWidth=1;
  ctx.beginPath(); ctx.moveTo(pad,8); ctx.lineTo(pad,H-22); ctx.lineTo(W-2,H-22); ctx.stroke();
  ctx.strokeStyle='#58A6FF'; ctx.lineWidth=1.8; ctx.beginPath();
  points.forEach((p,i)=>{ const x=xAt(i),y=yR(p.rssi); i?ctx.lineTo(x,y):ctx.moveTo(x,y); }); ctx.stroke();
  ctx.strokeStyle='#3FB950'; ctx.beginPath();
  points.forEach((p,i)=>{ const x=xAt(i),y=yS(p.snr); i?ctx.lineTo(x,y):ctx.moveTo(x,y); }); ctx.stroke();
  ctx.font='11px sans-serif'; ctx.fillStyle='#58A6FF'; ctx.fillText('RSSI(dBm)', W-150, 14);
  ctx.fillStyle='#3FB950'; ctx.fillText('SNR(dB)', W-70, 14);
}
async function saveDeviceCodec(id){
  const rt=(document.getElementById('codec_rt')||{}).value||'NONE';
  const script= rt==='JS' ? (document.getElementById('codec_script')||{}).value||'' : '';
  const codec=JSON.stringify({runtime:rt, script});
  const r=await api('PUT','/api/devices/'+id,{codec});
  if(r&&r.error){ toast('保存失败: '+r.error,'err'); return; }
  toast('解码配置已保存','ok');
  const d=(state.devs||[]).find(x=>x.id===id); if(d) d.codec=codec;
}
function codecRuntimeChanged(){
  const rt=(document.getElementById('codec_rt')||{}).value;
  const w=document.getElementById('codec_script_wrap'); if(w) w.style.display = rt==='JS'?'':'none';
}
async function deviceDetail(id){
  const r = await api('GET','/api/devices'); state.devs = r.data||[];
  const d=(state.devs||[]).find(x=>x.id===id); if(!d)return;
  let codecCfg={runtime:'NONE'};
  try { if(d.codec) codecCfg=JSON.parse(d.codec); } catch(e){}
  let ups=[];
  try { const ur=await api('GET','/api/uplinks?dev_id='+id+'&limit=80'); ups=ur.data||[]; } catch(e){}
  const decoded = ups.map(u=>({u, fields: decodePayload(d.codec, u.decrypted_hex||u.payload_hex||'')})).filter(x=>x.fields&&x.fields.length);

  const kv=(label,val)=>`<label>${label}</label><input value="${esc(val||'')}" readonly style="cursor:pointer" title="点击自动复制" onclick="copyKeyField(this, '${label}')">`;
  const codecOpts=[['NONE','不解码'],['CAYENNE_LPP','Cayenne LPP'],['JS','自定义 JS']].map(o=>`<option value="${o[0]}" ${codecCfg.runtime===o[0]?'selected':''}>${o[1]}</option>`).join('');
  const scriptVal = codecCfg.runtime==='JS' ? (codecCfg.script||'') : '';
  const decodedHtml = decoded.length
    ? decoded.slice(0,12).map(x=>`<div class="dec-row"><span class="muted">#${x.u.id} f${x.u.fcnt}</span> ${x.fields.map(f=>`<span class="tag ok">${esc(String(f.type))}: <b>${esc(String(f.value))}</b></span>`).join(' ')}</div>`).join('')
    : '<p class="muted">暂无已解码的上行（请先配置解码方式并等待设备上行）。</p>';

  openModal(`<h3>${t('设备')} #${id} ${esc(d.name)}</h3>
    <div class="dp-section"><h4>密钥</h4>
      ${kv('DevEUI', d.dev_eui)}
      ${d.activation==='OTAA'
        ? kv('JoinEUI', d.join_eui) + kv('AppKey', d.app_key)
          + (d.dev_addr
              ? kv('DevAddr（服务器分配）', revAddr(d.dev_addr)) + kv('NwkSKey（服务器分配）', d.nwk_s_key) + kv('AppSKey（服务器分配）', d.app_s_key)
              : `<p class="muted" style="margin:8px 0">设备尚未入网，暂无服务器分配的会话密钥。</p>`)
        : kv('DevAddr', revAddr(d.dev_addr)) + kv('NwkSKey', d.nwk_s_key) + kv('AppSKey', d.app_s_key)}
    </div>
    <div class="dp-section"><h4>数据解码</h4>
      <div class="row" style="align-items:flex-end;gap:12px">
        <div style="flex:0 0 220px"><label>解码方式</label><select id="codec_rt" onchange="codecRuntimeChanged()">${codecOpts}</select></div>
        <button class="btn" onclick="saveDeviceCodec(${id})">保存解码配置</button> <button class="btn ghost" onclick="decoderTemplates()">解码器模板</button>
      </div>
      <div id="codec_script_wrap" style="margin-top:10px;${codecCfg.runtime==='JS'?'':'display:none'}">
        <label>JS 解码函数（签名 function(hex, bytes) → 字段数组或对象，如 return [{type:'temp',value:x[0]}]）</label>
        <textarea id="codec_script" rows="5" style="width:100%;font-family:monospace">${esc(scriptVal)}</textarea>
      </div>
    </div>
    <div class="dp-section"><h4>信号质量（RSSI / SNR，近 80 条上行）</h4>
      <canvas id="sigChart" style="width:100%;height:170px"></canvas>
    </div>
    <div class="dp-section"><h4>最近上行解码（${decoded.length}）</h4>
      <div id="decList">${decodedHtml}</div>
    </div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
  drawSignalChart('sigChart', ups.map(u=>({rssi:+(u.rssi||0), snr:+(u.snr||0)})));
}

async function copyKeyField(input, label){
  const val = input.value || '';
  let ok = false;
  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(val);
      ok = true;
    } else {
      input.select();
      document.execCommand && document.execCommand('copy');
      ok = true;
    }
  } catch (e) { ok = false; }
  toast(ok ? `${label} 已复制` : `复制失败，请手动选中`, ok ? 'copy' : 'err');
}
async function viewGateways(){
  const q = state.tenantFilter ? `?tenant_id=${state.tenantFilter}` : '';
  const [r, tf] = await Promise.all([api('GET','/api/gateways'+q), tenantFilterHtml()]);
  state.gws = r.data||[];
  const onlineValues = [
    {value:'', label:'全部'},
    {value:'online',  label:'在线'},
    {value:'offline', label:'离线'},
  ];
  const gwCfg = {
    state, stateKey:'gwsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (g, k) => ({gw_id:g.gw_id, name:g.name, online:g.status==='online'?'online':'offline', region:g.region||'', uplinks:g.uplinks||0, time:+g.last_seen||0}[k]),
    cols:[
      {key:'gw_id',   label:'GatewayID', type:'str', firstDir:'asc', sortable:false},
      {key:'name',    label:'名称',       type:'str', firstDir:'asc', sortable:false},
      {key:'online',  label:'状态',       type:'status', firstDir:'asc', sortable:false, opts:{getValue:g=>g.status==='online'?'online':'offline', values:onlineValues}},
      {key:'region',  label:'区域',       type:'str', firstDir:'asc', sortable:false},
      {key:'uplinks', label:'上行数',     type:'num', firstDir:'asc', sortable:false},
      {key:'time',    label:'最近心跳',   type:'time', firstDir:'desc'},
      {key:'_raw',    label:'',          type:'raw'},
    ],
    filterStatus: {col:'online', value: state.gwsFOnline},
    rows: state.gws,
    rowHtml: g => {
      const online = g.status==='online';
      const seen = g.last_seen ? new Date(g.last_seen*1000).toLocaleString() : '-';
      return `<tr><td class="muted">${g.gw_id}</td><td>${esc(g.name)}</td>
        <td><span class="tag ${online?'ok':'off'}">${online?'在线':'离线'}</span></td>
        <td class="muted">${esc(g.region)}</td><td class="muted">${g.uplinks||0}</td><td class="muted">${seen}</td>
        <td>${adminBtn(`<button class="btn ghost" onclick="editGateway('${g.gw_id}')">${ICON.pencilSquare}编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delGateway('${g.gw_id}'))">${ICON.trash}删除</button>`)}</td></tr>`;
    },
    emptyText:'暂无网关（网关连接后自动出现，亦可手动添加）',
  };
  const [filteredGws, gwsTotal] = filterAndSortRows(gwCfg);
  gwCfg.rows = paginateRows(filteredGws, state, {pageKey:'gwsPage', limitKey:'gwsLimit', offsetKey:'gwsOffset'})[0];
  gwCfg.presorted = true;
  const table = buildSortableTable(gwCfg);
  const pager = buildPager({ total: gwsTotal, limit: state.gwsLimit, offset: state.gwsOffset, pageKey:'gwsPage', limitKey:'gwsLimit', offsetKey:'gwsOffset', totalKey:'gwsTotal', refresh:'viewGateways' });
  window.gwsSort_sort = col => _tableToggleSort('gwsSort','viewGateways',col);
  window.gwsSort_fstatus = (col, v) => _tableSetFStatus('gwsFOnline', 'viewGateways', v);
  window.viewGateways__page = p => _pagerGo({pageKey:'gwsPage',limitKey:'gwsLimit',offsetKey:'gwsOffset',totalKey:'gwsTotal'},'viewGateways',p);
  window.viewGateways__limit = l => _pagerSetLimit({pageKey:'gwsPage',limitKey:'gwsLimit',offsetKey:'gwsOffset',totalKey:'gwsTotal'},'viewGateways',l);
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['gateways']]||''}网关</h2>${adminBtn('<button onclick="newGateway()">'+ICON.plus+'新建网关</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px">${tf}
      <button class="btn ghost" onclick="resetFilters(()=>{state.gwsFOnline='';state.gwsSort={col:'time',dir:'desc'};state.gwsPage=1;state.gwsOffset=0;state.gwsLimit=50;}, viewGateways)">${ICON.arrowPath}重置</button></div>
    ${table}
    ${pager}`;
}

async function viewUplinks(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const qs = [tq, state.upsFilter ? ('dev_id='+state.upsFilter) : '', state.upsAppFilter ? ('app_id='+state.upsAppFilter) : '', 'limit='+state.upsLimit, 'offset='+state.upsOffset].filter(Boolean).join('&');
  const r = await api('GET','/api/uplinks' + (qs ? '?'+qs : '')); state.ups = r.data||[];
  if (typeof r.total === 'number') state.upsTotal = r.total;

  const devQ = [tq, state.upsAppFilter ? ('app_id='+state.upsAppFilter) : ''].filter(Boolean).join('&');
  const [dr, ar, tf] = await Promise.all([
    api('GET','/api/devices' + (devQ ? '?'+devQ : '')),
    api('GET','/api/applications' + (tq ? '?'+tq : '')),
    tenantFilterHtml()
  ]);
  const devs = dr.data||[], apps = ar.data||[];
  const appName = id => { const a = apps.find(x=>x.id===id); return a ? a.name : ('#'+id); };
  const devOpts = `<option value="">全部设备</option>` + devs.map(d=>`<option value="${d.id}" ${String(d.id)===String(state.upsFilter)?'selected':''}>#${d.id} ${esc(d.name)} (${hex(d.dev_eui)})</option>`).join('');
  const appOpts = `<option value="">全部应用</option>` + apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.upsAppFilter)?'selected':''}>${esc(a.name)}</option>`).join('');

  const fcntValues = [{value:'', label:'全部'}].concat(
    [...new Set((state.ups||[]).map(u=>u.fcnt).filter(v=>v!==null && v!==undefined && v!==''))].sort((a,b)=>a-b)
      .map(f=>({value:String(f), label:'FCnt '+f}))
  );
  const portValues = [{value:'', label:'全部'}].concat(
    [...new Set((state.ups||[]).map(u=>u.port).filter(v=>v!==null && v!==undefined && v!==''))].sort((a,b)=>a-b)
      .map(p=>({value:String(p), label:'Port '+p}))
  );
  const table = buildSortableTable({
    state, stateKey:'upsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (u, k) => ({id:u.id, app:appName(u.app_id), dev_addr:u.dev_addr, fcnt:u.fcnt, port:u.port, confirmed:(+u.confirmed)?1:0, payload:u.decrypted_hex, text:hexToText(u.decrypted_hex), phy:u.phy_payload, gw:u.gateway_id, rssi_snr:(u.rssi??'-') + ' / ' + (u.snr??'-'), time:u.received_at}[k]),
    cols:[
      {key:'id',        label:'ID',                   type:'str', firstDir:'asc', sortable:false},
      {key:'app',       label:'应用',                  type:'str', firstDir:'asc', sortable:false},
      {key:'dev_addr',  label:'DevAddr',              type:'str', firstDir:'asc', sortable:false},
      {key:'fcnt',      label:'FCnt',                 type:'status', firstDir:'asc', sortable:false, opts:{getValue:u=>u.fcnt, values:fcntValues}},
      {key:'port',      label:'Port',                 type:'status', firstDir:'asc', sortable:false, opts:{getValue:u=>u.port, values:portValues}},
      {key:'confirmed', label:'确认',                  type:'num', firstDir:'asc', sortable:false},
      {key:'payload',   label:'解密 payload (hex)',   type:'str', firstDir:'asc', sortable:false},
      {key:'text',      label:'解密 payload (文本)',  type:'str', firstDir:'asc', sortable:false},
      {key:'phy',       label:'原始帧 phy',           type:'str', firstDir:'asc', sortable:false},
      {key:'gw',        label:'网关',                  type:'str', firstDir:'asc', sortable:false},
      {key:'rssi_snr',  label:'RSSI / SNR',           type:'str', firstDir:'asc', sortable:false},
      {key:'time',      label:'时间',                  type:'time', firstDir:'desc'},
      {key:'_raw',      label:'',                     type:'raw'},
    ],
    filterStatusList: [
      {col:'fcnt', value: state.upsFFcnt},
      {col:'port', value: state.upsFPort},
    ],
    rows: state.ups,
    rowHtml: u => {
      const textDisp = hexToText(u.decrypted_hex);
      return `<tr><td>${u.id}</td>
      <td class="muted"><span class="pill" style="margin:0">${esc(appName(u.app_id))}</span></td>
      <td class="muted"><a href="javascript:void(0)" style="color:var(--acc);text-decoration:none" onclick="deviceDetail(${u.dev_id})">${hex(revAddr(u.dev_addr))}</a></td>
      <td>${u.fcnt}</td><td>${u.port}</td><td>${(u.confirmed==1||u.confirmed==='1')?'✓':'-'}</td>
      <td class="cell-scroll"><code>${hex(u.decrypted_hex)}</code></td>
      <td class="muted cell-scroll" style="font-family:monospace">${esc(textDisp)}</td>
      <td class="cell-scroll"><code class="muted">${hex(u.phy_payload)}</code></td>
      <td class="muted">${u.gateway_id||'-'}</td>
      <td class="muted">${u.rssi} / ${u.snr}</td>
      <td class="muted">${new Date(u.received_at*1000).toLocaleString()}</td>
      <td>${rawBtn(u.id,'showRaw')} ${frameBtn(u.id,'frameInspector')}</td></tr>`;
    },
    emptyText:'暂无上行',
  });
  const pager = buildPager({ total: state.upsTotal, limit: state.upsLimit, offset: state.upsOffset, pageKey:'upsPage', limitKey:'upsLimit', offsetKey:'upsOffset', totalKey:'upsTotal', refresh:'viewUplinks' });
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['uplinks']]||''}上行消息日志</h2><div style="display:flex;gap:10px;align-items:center;margin-left:auto"><button class="btn ghost" onclick="exportCapture('uplinks','json')">导出JSON</button><button class="btn ghost" onclick="exportCapture('uplinks','csv')">导出CSV</button><button class="btn danger" onclick="clearPageLogs('uplinks')">${ICON.trash}${t('清空日志')}</button> ${logRefreshCtrl()}</div></div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">
      ${tf}
      <div style="flex:0 0 300px"><label>按应用筛选</label><select id="upAppFilter" onchange="state.upsAppFilter=this.value;state.upsPage=1;state.upsOffset=0;viewUplinks()">${appOpts}</select></div>
      <div style="flex:0 0 300px"><label>按设备筛选</label><select id="upFilter" onchange="state.upsFilter=this.value;state.upsPage=1;state.upsOffset=0;viewUplinks()">${devOpts}</select></div>
      <button class="btn ghost" onclick="resetFilters(()=>{state.upsFilter='';state.upsAppFilter='';state.upsSort={col:'time',dir:'desc'};state.upsFFcnt='';state.upsFPort='';state.upsPage=1;state.upsOffset=0;state.upsLimit=50;}, viewUplinks)">${ICON.arrowPath}重置</button>
    </div>
    ${table}
    ${pager}`;
  window.upsSort_sort = col => _tableToggleSort('upsSort','viewUplinks',col);
  window.upsSort_fstatus = (col, v) => {
    const map = {fcnt:'upsFFcnt', port:'upsFPort'};
    _tableSetFStatus(map[col] || 'upsFPort', 'viewUplinks', v);
  };
  window.viewUplinks__page = p => _pagerGo({pageKey:'upsPage',limitKey:'upsLimit',offsetKey:'upsOffset',totalKey:'upsTotal'},'viewUplinks',p);
  window.viewUplinks__limit = l => _pagerSetLimit({pageKey:'upsPage',limitKey:'upsLimit',offsetKey:'upsOffset',totalKey:'upsTotal'},'viewUplinks',l);
}
function copyText(text){
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(() => toast(t('已复制'))).catch(() => fallbackCopy(text));
  } else {
    fallbackCopy(text);
  }
}
function fallbackCopy(text){
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  try { document.execCommand('copy'); toast(t('已复制')); } catch(e){ toast(t('复制失败，请手动选择复制'), 'warn'); }
  document.body.removeChild(ta);
}
function copyModalPre(){
  const pre = document.querySelector('#modalBox pre');
  copyText(pre ? pre.textContent : '');
}
async function showRaw(id){
  const u=(state.ups||[]).find(x=>x.id===id); if(!u)return;
  let j={}; try { j = u.raw_json ? JSON.parse(u.raw_json) : {}; } catch(e){}
  if (!Object.keys(j).length) {
    openModal(`<h3>${t('原始 JSON')} #${id}</h3><p class="muted">该上行无原始协议报文。</p><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
    return;
  }
  openModal(`<h3>${t('原始 JSON')} #${id}</h3><div style="position:relative"><button class="ad-copy" onclick="copyModalPre()">复制</button><pre>${esc(JSON.stringify(j,null,2))}</pre></div><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}

const DL_STATUS = {
  pending:    {label:'待发送', cls:'pending'},
  scheduled:  {label:'已调度', cls:'pending'},
  sent:       {label:'已发送', cls:'ok'},
  acknowledged:{label:'已确认', cls:'ok'},
  failed:     {label:'失败',   cls:'err'},
  timeout:    {label:'超时',   cls:'err'},
  error:      {label:'错误',   cls:'err'}
};
async function viewDownlinks(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const qs = [tq, state.dlDevFilter ? ('dev_id='+state.dlDevFilter) : '', state.dlAppFilter ? ('app_id='+state.dlAppFilter) : '', 'limit='+state.dlsLimit, 'offset='+state.dlsOffset].filter(Boolean).join('&');
  const r = await api('GET','/api/downlinks' + (qs ? '?'+qs : '')); state.dls = r.data||[];
  if (typeof r.total === 'number') state.dlsTotal = r.total;

  const devQ = [tq, state.dlAppFilter ? ('app_id='+state.dlAppFilter) : ''].filter(Boolean).join('&');
  const [dr, ar, tf] = await Promise.all([
    api('GET','/api/devices' + (devQ ? '?'+devQ : '')),
    api('GET','/api/applications' + (tq ? '?'+tq : '')),
    tenantFilterHtml()
  ]);
  const devs = dr.data||[], apps = ar.data||[];
  const appName = id => { const a = apps.find(x=>x.id===id); return a ? a.name : ('#'+id); };
  const devName = id => { const d = devs.find(x=>x.id===id); return d ? (d.name+' (#'+id+')') : ('#'+id); };
  const devOpts = `<option value="">全部设备</option>` + devs.map(d=>`<option value="${d.id}" ${String(d.id)===String(state.dlDevFilter)?'selected':''}>#${d.id} ${esc(d.name)} (${hex(d.dev_eui)})</option>`).join('');
  const appOpts = `<option value="">全部应用</option>` + apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.dlAppFilter)?'selected':''}>${esc(a.name)}</option>`).join('');

  const statusValues = [
    {value:'',label:'全部'},
    ...Object.entries(DL_STATUS).map(([k,v]) => ({value:k,label:v.label})),
  ];
  const table = buildSortableTable({
    state, stateKey:'dlsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (d, k) => ({id:d.id, app:appName(d.app_id), dev:devName(d.dev_id), port:d.port, confirmed:d.confirmed?1:0, payload:d.payload_hex, text:hexToText(d.payload_hex), status:d.status, time:d.created_at||d.sent_at, tx:d.transmissions||0, ack:d.acknowledged_at}[k]),
    cols:[
      {key:'id',        label:'ID',        type:'num', firstDir:'asc', sortable:false},
      {key:'app',       label:'应用',       type:'str', firstDir:'asc', sortable:false},
      {key:'dev',       label:'设备',       type:'str', firstDir:'asc', sortable:false},
      {key:'port',      label:'FPort',     type:'num', firstDir:'asc', sortable:false},
      {key:'confirmed', label:'确认',       type:'num', firstDir:'asc', sortable:false},
      {key:'payload',   label:'负载 (hex)',  type:'str', firstDir:'asc', sortable:false},
      {key:'text',      label:'负载 (文本)', type:'str', firstDir:'asc', sortable:false},
      {key:'status',    label:'状态',       type:'status', firstDir:'asc', sortable:false, opts:{
        getValue: d => d.status,
        values: statusValues,
      }},
      {key:'time',      label:'发送时间',   type:'time', firstDir:'desc'},
      {key:'tx',        label:'重传',       type:'num', firstDir:'asc', sortable:false},
      {key:'ack',       label:'确认时间',   type:'time', firstDir:'desc'},
      {key:'_raw',      label:'',          type:'raw'},
    ],
    rows: state.dls,
    rowHtml: d => {
      const st = DL_STATUS[d.status] || {label:d.status||'-', cls:''};
      const sent = d.sent_at ? new Date(d.sent_at*1000).toLocaleString() : '—';
      const ack = d.acknowledged_at ? new Date(d.acknowledged_at*1000).toLocaleString() : '—';
      const textDisp = hexToText(d.payload_hex);
      const isMac = (d.mac == 1);
      return `<tr><td>${d.id}</td>
        <td class="muted"><span class="pill" style="margin:0">${esc(appName(d.app_id))}</span></td>
        <td class="muted">${esc(devName(d.dev_id))}</td>
        <td>${d.port}${isMac ? ' <span class="tag" style="margin-left:4px">MAC</span>' : ''}</td><td>${d.confirmed?'✓':'-'}</td>
        <td class="cell-scroll"><code>${hex(d.payload_hex)}</code></td>
        <td class="muted cell-scroll" style="font-family:monospace">${esc(textDisp)}</td>
        <td><span class="tag ${st.cls}">${st.label}</span></td>
        <td class="muted">${sent}</td><td class="muted">${d.transmissions||0}</td>
        <td class="muted">${ack}</td>
        <td>${rawBtn(d.id,'showDownlinkRaw')} ${frameBtn(d.id,'frameInspector')}</td></tr>`;
    },
    emptyText:'暂无下行',
  });
  const pager = buildPager({ total: state.dlsTotal, limit: state.dlsLimit, offset: state.dlsOffset, pageKey:'dlsPage', limitKey:'dlsLimit', offsetKey:'dlsOffset', totalKey:'dlsTotal', refresh:'viewDownlinks' });
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['downlinks']]||''}下行消息日志</h2><div style="display:flex;gap:10px;align-items:center;margin-left:auto"><button class="btn ghost" onclick="exportCapture('downlinks','json')">导出JSON</button><button class="btn ghost" onclick="exportCapture('downlinks','csv')">导出CSV</button><button class="btn danger" onclick="clearPageLogs('downlinks')">${ICON.trash}${t('清空日志')}</button> ${logRefreshCtrl()}</div></div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">
      ${tf}
      <div style="flex:0 0 300px"><label>按应用筛选</label><select id="dlAppFilter" onchange="state.dlAppFilter=this.value;state.dlsPage=1;state.dlsOffset=0;viewDownlinks()">${appOpts}</select></div>
      <div style="flex:0 0 300px"><label>按设备筛选</label><select id="dlDevFilter" onchange="state.dlDevFilter=this.value;state.dlsPage=1;state.dlsOffset=0;viewDownlinks()">${devOpts}</select></div>
      <button class="btn ghost" onclick="resetFilters(()=>{state.dlDevFilter='';state.dlAppFilter='';state.dlsSort={col:'time',dir:'desc'};state.dlsPage=1;state.dlsOffset=0;state.dlsLimit=50;state.dlsFStatus='';}, viewDownlinks)">${ICON.arrowPath}重置</button>
    </div>
    ${table}
    ${pager}`;
  window.dlsSort_sort = col => _tableToggleSort('dlsSort','viewDownlinks',col);
  window.dlsSort_fstatus = (col, v) => _tableSetFStatus('dlsFStatus', 'viewDownlinks', v);
  window.viewDownlinks__page = p => _pagerGo({pageKey:'dlsPage',limitKey:'dlsLimit',offsetKey:'dlsOffset',totalKey:'dlsTotal'},'viewDownlinks',p);
  window.viewDownlinks__limit = l => _pagerSetLimit({pageKey:'dlsPage',limitKey:'dlsLimit',offsetKey:'dlsOffset',totalKey:'dlsTotal'},'viewDownlinks',l);
}
async function showDownlinkRaw(id){
  const d=(state.dls||[]).find(x=>x.id===id); if(!d)return;

  if (d.raw_json && d.raw_json !== '') {
    let proto = {};
    try { proto = JSON.parse(d.raw_json); } catch(e) {}
    openModal(`<h3>${t('下行 JSON')} #${id}</h3>
      <p class="muted" style="margin:4px 0 10px">网关协议原文（txpk / phy_payload）</p>
      <div style="position:relative"><button class="ad-copy" onclick="copyModalPre()">复制</button><pre>${esc(JSON.stringify(proto, null, 2))}</pre></div>
      <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
    return;
  }

  let bytes=[], ascii='';
  const hexStr = (d.payload_hex||'').replace(/\s+/g,'');
  for (let i=0;i<hexStr.length;i+=2){ const b=parseInt(hexStr.substr(i,2),16); bytes.push(b); ascii += (b>=32&&b<127)?String.fromCharCode(b):'.'; }
  const parsed = {
    id: d.id, app_id: d.app_id, dev_id: d.dev_id,
    port: d.port, confirmed: !!d.confirmed, fcnt: d.fcnt,
    status: d.status, transmissions: d.transmissions||0,
    created_at: d.created_at ? new Date(d.created_at*1000).toISOString() : null,
    sent_at: d.sent_at ? new Date(d.sent_at*1000).toISOString() : null,
    acknowledged_at: d.acknowledged_at ? new Date(d.acknowledged_at*1000).toISOString() : null,
    payload_hex: d.payload_hex||'',
    payload_bytes: bytes,
    payload_ascii: ascii
  };
  const pretty = esc(JSON.stringify(parsed, null, 2));
  const hexRows = bytes.length ? bytes.map((b,i)=>`<span class="mono">${hexStr.substr(i*2,2).toUpperCase()}</span>`).join(' ') : '(空)';
  openModal(`<h3>${t('下行 JSON')} #${id}</h3>
    <p class="muted" style="margin:4px 0 10px">格式化结构（payload 已解析为字节数组与 ASCII）</p>
    <div style="position:relative"><button class="ad-copy" onclick="copyModalPre()">复制</button><pre>${pretty}</pre></div>
    <h4 style="margin:16px 0 6px">Payload 十六进制字节</h4>
    <div class="mono" style="line-height:1.9">${hexRows}</div>
    <h4 style="margin:14px 0 6px">ASCII</h4>
    <div class="mono">${esc(ascii)||'(不可打印)'}</div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}

async function viewEvents(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';

  const [rd, rg, tf] = await Promise.all([
    api('GET','/api/devices' + (tq ? '?'+tq : '')),
    api('GET','/api/gateways' + (tq ? '?'+tq : '')),
    tenantFilterHtml()
  ]);
  state.devs = rd.data||[]; state.gws = rg.data||[];

  let q = [];
  if (tq) q.push(tq);
  if (state.evsDevFilter) q.push('dev_id=' + state.evsDevFilter);
  if (state.evsGwFilter)  q.push('gw_id=' + encodeURIComponent(state.evsGwFilter));
  if (state.evsFType)     q.push('type=' + encodeURIComponent(state.evsFType));
  q.push('limit=' + state.evsLimit);
  q.push('offset=' + state.evsOffset);
  const qs = q.length ? ('?' + q.join('&')) : '';
  const r = await api('GET','/api/events' + qs); state.evs = r.data||[];
  if (typeof r.total === 'number') state.evsTotal = r.total;
  const devOpts = ['<option value="">全部设备</option>'].concat(
    state.devs.map(d=>`<option value="${d.id}" ${String(d.id)===state.evsDevFilter?'selected':''}>${esc(d.name)} · ${hex(d.dev_eui)}</option>`)
  ).join('');
  const gwOpts = ['<option value="">全部网关</option>'].concat(
    state.gws.map(g=>`<option value="${esc(g.gw_id)}" ${g.gw_id===state.evsGwFilter?'selected':''}>${esc(g.gw_id)} · ${esc(g.name)}</option>`)
  ).join('');

  const levelValues = [
    {value:'', label:'全部'},
    {value:'info',  label:'info · 信息'},
    {value:'warn',  label:'warn · 警告'},
    {value:'error', label:'error · 错误'},
  ];

  const typeValues = [
    {value:'',       label:'全部'},
    {value:'gateway', label:'网关上下线'},
    {value:'join',    label:'入网 join'},
    {value:'uplink',  label:'上行 uplink'},
    {value:'downlink',label:'下行 downlink'},
    {value:'txack',   label:'发射确认 txack'},
    {value:'ack',     label:'确认 ack'},
    {value:'fuota',   label:'FUOTA'},
    {value:'status',  label:'状态 status'},
  ];
  const table = buildSortableTable({
    state, stateKey:'evsSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (e, k) => ({type:e.type, level:e.level, who:(e.gateway_id?('gw '+e.gateway_id):(e.dev_id?('dev #'+e.dev_id):'')), msg:e.message, time:e.created_at}[k]),
    cols:[
      {key:'type',  label:'类型',  type:'status', firstDir:'asc', sortable:false, opts:{
        getValue: e => e.type, values: typeValues,
      }},
      {key:'level', label:'级别',  type:'status', firstDir:'asc', sortable:false, opts:{
        getValue: e => e.level, values: levelValues,
      }},
      {key:'who',   label:'对象',  type:'str',    firstDir:'asc', sortable:false},
      {key:'msg',   label:'消息',  type:'str',    firstDir:'asc', sortable:false},
      {key:'time',  label:'时间',  type:'time',   firstDir:'desc'},
      {key:'_raw',  label:'',     type:'raw'},
    ],
    filterStatusList: [
      {col:'type',  value: state.evsFType},
      {col:'level', value: state.evsFLevel},
    ],
    rows: state.evs,
    rowHtml: e => {
      const lvl = e.level==='error' ? 'err' : (e.level==='warn' ? 'pending' : 'ok');
      const who = e.gateway_id ? ('gw '+e.gateway_id) : (e.dev_id ? ('dev #'+e.dev_id) : '');

      const tCls = e.type==='join' ? 'ok' : (e.type==='downlink' || e.type==='txack' ? 'pending' : (e.type==='gateway' ? 'muted' : ''));
      return `<tr><td><span class="tag ${tCls}">${esc(e.type)}</span></td><td><span class="tag ${lvl}">${e.level}</span></td>
        <td class="muted">${esc(who)}</td><td class="cell-scroll" style="max-width:320px">${esc(e.message)}</td><td class="muted">${new Date(e.created_at*1000).toLocaleString()}</td>
        <td>${rawBtn(e.id,'showEventRaw')}</td></tr>`;
    },
    emptyText:'暂无事件',
  });
  const pager = buildPager({ total: state.evsTotal, limit: state.evsLimit, offset: state.evsOffset, pageKey:'evsPage', limitKey:'evsLimit', offsetKey:'evsOffset', totalKey:'evsTotal', refresh:'viewEvents' });
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['events']]||''}网关日志</h2><div style="display:flex;gap:10px;align-items:center;margin-left:auto"><button class="btn ghost" onclick="exportCapture('events','json')">导出JSON</button><button class="btn ghost" onclick="exportCapture('events','csv')">导出CSV</button><button class="btn danger" onclick="clearPageLogs('events')">${ICON.trash}${t('清空日志')}</button> ${logRefreshCtrl()}</div></div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">
      ${tf}
      <div style="flex:0 0 300px"><label>按设备筛选</label><select id="evs_dev" onchange="state.evsDevFilter=this.value; state.evsPage=1; state.evsOffset=0; viewEvents()">${devOpts}</select></div>
      <div style="flex:0 0 300px"><label>按网关筛选</label><select id="evs_gw" onchange="state.evsGwFilter=this.value; state.evsPage=1; state.evsOffset=0; viewEvents()">${gwOpts}</select></div>
      <button class="btn ghost" onclick="resetFilters(()=>{state.evsDevFilter=''; state.evsGwFilter=''; state.evsSort={col:'time',dir:'desc'}; state.evsFType=''; state.evsFLevel=''; state.evsPage=1; state.evsOffset=0; state.evsLimit=50;}, viewEvents)">${ICON.arrowPath}重置</button>
    </div>
    ${table}
    ${pager}`;
  window.evsSort_sort = col => _tableToggleSort('evsSort','viewEvents',col);
  window.evsSort_fstatus = (col, v) => _tableSetFStatus(col === 'type' ? 'evsFType' : 'evsFLevel', 'viewEvents', v);
  window.viewEvents__page = p => _pagerGo({pageKey:'evsPage',limitKey:'evsLimit',offsetKey:'evsOffset',totalKey:'evsTotal'},'viewEvents',p);
  window.viewEvents__limit = l => _pagerSetLimit({pageKey:'evsPage',limitKey:'evsLimit',offsetKey:'evsOffset',totalKey:'evsTotal'},'viewEvents',l);
}
async function showEventRaw(id){
  const e=(state.evs||[]).find(x=>x.id===id); if(!e)return;
  let j=null;
  if (e.raw_json) { try { j = JSON.parse(e.raw_json); } catch(err){} }
  if (!j || !Object.keys(j).length) {
    openModal(`<h3>${t('事件 JSON')} #${id}</h3><p class="muted">该事件无原始协议报文（网关系统事件 / 流程事件）。</p><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
    return;
  }
  openModal(`<h3>${t('事件 JSON')} #${id}</h3><div style="position:relative"><button class="ad-copy" onclick="copyModalPre()">复制</button><pre>${esc(JSON.stringify(j,null,2))}</pre></div><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}
async function viewUsers(){
  if (!isAdmin()) { nav('dashboard'); return; }
  const r = await api('GET','/api/users'); state.users = r.data||[];
  const userCfg = {
    state, stateKey:'usersSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (u, k) => ({id:u.id, username:u.username, email:u.email||'', role:u.role_name||u.role||'', time:u.created_at}[k]),
    cols:[
      {key:'id',       label:'ID',       type:'num', firstDir:'asc', sortable:false},
      {key:'username', label:'用户名',    type:'str', firstDir:'asc', sortable:false},
      {key:'email',    label:'邮箱',      type:'str', firstDir:'asc', sortable:false},
      {key:'role',     label:'角色',      type:'str', firstDir:'asc', sortable:false},
      {key:'time',     label:'创建时间',  type:'time', firstDir:'desc'},
      {key:'_raw',     label:'',         type:'raw'},
    ],
    rows: state.users,
    rowHtml: u => `<tr><td>${u.id}</td><td>${esc(u.username)}</td><td class="muted">${u.email?esc(u.email):'—'}</td><td><span class="tag">${esc(u.role_name||u.role||'—')}</span></td>
     <td class="muted">${new Date(u.created_at*1000).toLocaleString()}</td>
     <td><button class="btn ghost" onclick="editUser(${u.id})">${ICON.pencilSquare}编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delUser(${u.id}))">${ICON.trash}删除</button> <button class="btn ghost" onclick="changePwFor(${u.id})">${ICON.key}改密</button></td></tr>`,
    emptyText:'暂无用户',
  };
  const [filteredUsers, usersTotal] = filterAndSortRows(userCfg);
  userCfg.rows = paginateRows(filteredUsers, state, {pageKey:'usersPage', limitKey:'usersLimit', offsetKey:'usersOffset'})[0];
  userCfg.presorted = true;
  const table = buildSortableTable(userCfg);
  const pager = buildPager({ total: usersTotal, limit: state.usersLimit, offset: state.usersOffset, pageKey:'usersPage', limitKey:'usersLimit', offsetKey:'usersOffset', totalKey:'usersTotal', refresh:'viewUsers' });
  window.usersSort_sort = col => _tableToggleSort('usersSort','viewUsers',col);
  window.viewUsers__page = p => _pagerGo({pageKey:'usersPage',limitKey:'usersLimit',offsetKey:'usersOffset',totalKey:'usersTotal'},'viewUsers',p);
  window.viewUsers__limit = l => _pagerSetLimit({pageKey:'usersPage',limitKey:'usersLimit',offsetKey:'usersOffset',totalKey:'usersTotal'},'viewUsers',l);
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['users']]||''}用户管理</h2><button onclick="newUser()">${ICON.plus}新建用户</button></div>
    ${table}
    ${pager}`;
}

async function viewApiLogs(){
  const showTenant = isAdmin() || isDemo();
  const params = [];
  if (state.apiLogFilter.path) params.push('path_contains=' + encodeURIComponent(state.apiLogFilter.path));
  if (state.apiLogFilter.ip) params.push('ip=' + encodeURIComponent(state.apiLogFilter.ip));

  if (state.apiLogFilter.method) params.push('method=' + state.apiLogFilter.method);
  if (showTenant && state.apiLogFilter.tenant_id) params.push('tenant_id=' + state.apiLogFilter.tenant_id);
  if (state.apiLogFilter.application_id) params.push('application_id=' + state.apiLogFilter.application_id);

  params.push('limit=' + (state.apiLogLimit|0 || 50));
  params.push('offset=' + (state.apiLogOffset|0 || 0));
  const url = '/api/api-logs' + (params.length ? '?' + params.join('&') : '');
  const r = await api('GET', url);
  const rowsAll = (r.data || []);
  state.apiLogTotal = +r.total || 0;

  let tenantOpts = '';
  let appOpts = '';
  if (showTenant) {
    try { const tr = await api('GET','/api/tenants'); tenantOpts = (tr.data||[]).map(x=>`<option value="${x.id}" ${String(state.apiLogFilter.tenant_id)===String(x.id)?'selected':''}>${esc(x.name)}</option>`).join(''); } catch(e){}
  }
  try {
    const aq = showTenant && state.apiLogFilter.tenant_id ? ('?tenant_id=' + state.apiLogFilter.tenant_id) : '';
    const ar = await api('GET', '/api/applications' + aq);
    appOpts = (ar.data||[]).map(x=>`<option value="${x.id}" ${String(state.apiLogFilter.application_id)===String(x.id)?'selected':''}>${esc(x.name)}</option>`).join('');
  } catch(e){}
  const statusTag = s => {
    if (!s) return `<span class="tag">-</span>`;
    if (s>=200 && s<300) return `<span class="tag ok">${s}</span>`;
    if (s>=400 && s<500) return `<span class="tag err">${s}</span>`;
    if (s>=500) return `<span class="tag pending">${s}</span>`;
    return `<span class="tag">${s}</span>`;
  };

  const statusValues = [
    {value:'',label:'全部', match: () => true},
    {value:'2xx',label:'2xx 成功', match: s => s>=200 && s<300},
    {value:'3xx',label:'3xx 重定向', match: s => s>=300 && s<400},
    {value:'4xx',label:'4xx 客户端错', match: s => s>=400 && s<500},
    {value:'5xx',label:'5xx 服务端错', match: s => s>=500 && s<600},
  ];
  const table = buildSortableTable({
    state, stateKey:'apiLogSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (r, k) => ({time:r.created_at, method:r.method, path:r.path, status:r.status, latency:r.latency_ms, ip:r.ip, user:r.username||'', tenant:r.tenant_id||0, app:r.application_id||0, body:r.body_size||0}[k]),
    cols:[
      {key:'time',    label:t('时间'), type:'time', firstDir:'desc'},
      {key:'method',  label:t('方法'), type:'str',  firstDir:'asc', sortable:false},
      {key:'path',    label:t('路径'), type:'str',  firstDir:'asc', sortable:false},
      {key:'status',  label:t('状态'), type:'status', firstDir:'asc', sortable:false, opts:{getValue: r => r.status, values: statusValues}},
      {key:'latency', label:t('耗时'), type:'num',  firstDir:'asc', sortable:false},
      {key:'ip',      label:t('IP'),   type:'str',  firstDir:'asc', sortable:false},
      {key:'user',    label:t('用户'), type:'str',  firstDir:'asc', sortable:false},
      ...(showTenant ? [{key:'tenant', label:t('租户'), type:'num', firstDir:'asc', sortable:false}] : []),
      {key:'app',     label:t('应用'), type:'num',  firstDir:'asc', sortable:false},
      {key:'body',    label:t('Body'), type:'num', firstDir:'asc', sortable:false},
    ],
    filterStatus: {col:'status', value: state.apiLogFStatus},
    rows: rowsAll,
    rowHtml: r => `<tr>
      <td class="muted">${new Date(r.created_at*1000).toLocaleString()}</td>
      <td><span class="tag">${esc(r.method)}</span></td>
      <td class="muted" style="font-family:monospace;font-size:12px;word-break:break-all">${esc(r.path)}${r.query ? '?' + esc(r.query) : ''}</td>
      <td>${statusTag(r.status)}</td>
      <td class="muted">${r.latency_ms}ms</td>
      <td class="muted" style="font-family:monospace">${esc(r.ip||'-')}</td>
      <td class="muted">${esc(r.username||'-')}${r.role?` <span class="tag">${esc(r.role)}</span>`:''}</td>
      ${showTenant ? `<td class="muted">${r.tenant_id?('#'+r.tenant_id):'-'}</td>` : ''}
      <td class="muted">${r.application_id?('#'+r.application_id):'-'}</td>
      <td class="muted">${r.body_size||0}B</td>
    </tr>`,
    emptyText: t('暂无日志'),
  });
  const filterId = (k) => 'alf_' + k;
  const pager = buildPager({ total: state.apiLogTotal, limit: state.apiLogLimit, offset: state.apiLogOffset, pageKey:'apiLogPage', limitKey:'apiLogLimit', offsetKey:'apiLogOffset', totalKey:'apiLogTotal', refresh:'viewApiLogs' });
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['api-logs']]||''}${t('API 调用日志')}</h2><div style="display:flex;align-items:center;gap:12px"><div class="muted" style="font-size:12px">${t('共')} ${state.apiLogTotal} ${t('条')}${t('（仅保留最近 10000 条）')}</div><button class="btn danger" onclick="clearPageLogs('api')">${ICON.trash}${t('清空日志')}</button> ${logRefreshCtrl()}</div></div>
   <div class="card" style="margin-bottom:12px">
     <div class="row" style="align-items:flex-end">
       <div><label>${t('路径包含')}</label><input id="${filterId('path')}" value="${esc(state.apiLogFilter.path||'')}" placeholder="/v1/devices"></div>
       <div><label>${t('IP')}</label><input id="${filterId('ip')}" value="${esc(state.apiLogFilter.ip||'')}" placeholder="192.168.1.1"></div>
       <div><label>${t('方法')}</label><select id="${filterId('method')}">
         <option value="">${t('全部')}</option>
         <option value="GET" ${state.apiLogFilter.method==='GET'?'selected':''}>GET</option>
         <option value="POST" ${state.apiLogFilter.method==='POST'?'selected':''}>POST</option>
         <option value="PUT" ${state.apiLogFilter.method==='PUT'?'selected':''}>PUT</option>
         <option value="DELETE" ${state.apiLogFilter.method==='DELETE'?'selected':''}>DELETE</option>
       </select></div>
       ${showTenant ? `<div><label>${t('租户')}</label><select id="${filterId('tenant_id')}"><option value="">${t('全部租户')}</option>${tenantOpts}</select></div>` : ''}
       <div><label>${t('应用')}</label><select id="${filterId('application_id')}"><option value="">${t('全部应用')}</option>${appOpts}</select></div>
       <div style="flex:0 0 auto"><button onclick="applyApiLogFilter()">${t('应用筛选')}</button> <button class="ghost" onclick="resetApiLogFilter()">${t('重置')}</button></div>
     </div>
   </div>
   ${table}
   ${pager}`;
  window.apiLogSort_sort = col => _tableToggleSort('apiLogSort','viewApiLogs',col);
  window.apiLogSort_fstatus = (col, v) => _tableSetFStatus('apiLogFStatus', 'viewApiLogs', v);
  window.viewApiLogs__page = p => _pagerGo({pageKey:'apiLogPage',limitKey:'apiLogLimit',offsetKey:'apiLogOffset',totalKey:'apiLogTotal'},'viewApiLogs',p);
  window.viewApiLogs__limit = l => _pagerSetLimit({pageKey:'apiLogPage',limitKey:'apiLogLimit',offsetKey:'apiLogOffset',totalKey:'apiLogTotal'},'viewApiLogs',l);
}
function applyApiLogFilter(){
  const get = k => (document.getElementById('alf_' + k) || {}).value || '';
  state.apiLogFilter = {
    path: get('path').trim(),
    ip: get('ip').trim(),
    status: '',
    method: get('method'),
    tenant_id: get('tenant_id'),
    application_id: get('application_id'),
  };
  state.apiLogFStatus = '';
  state.apiLogSort = {col:'time',dir:'desc'};
  state.apiLogPage = 1;
  state.apiLogOffset = 0;
  viewApiLogs();
}
function resetApiLogFilter(){
  state.apiLogFilter = { path:'', ip:'', status:'', method:'', tenant_id:'', application_id:'' };
  state.apiLogFStatus = '';
  state.apiLogSort = {col:'time',dir:'desc'};
  state.apiLogPage = 1;
  state.apiLogOffset = 0;
  state.apiLogLimit = 50;
  busy('重置中…', viewApiLogs);
}

async function viewSettings(){
  if (!isAdmin()) { nav('dashboard'); return; }
  const r = await api('GET','/api/settings'); const s = r.data||{};
  const val = (k) => esc(s[k] || '');
  document.getElementById('view').innerHTML = `<style>
  .st-wrap{display:flex;flex-direction:column;gap:18px;margin-top:8px}
  .st-side{display:flex;gap:8px;overflow-x:auto;overscroll-behavior-x:contain;-webkit-overflow-scrolling:touch;padding-bottom:6px;scrollbar-width:thin}
  .st-item{display:flex;align-items:center;justify-content:center;gap:6px;flex:0 0 auto;text-align:center;background:var(--panel);border:1px solid var(--line);color:var(--txt);padding:11px 16px;border-radius:10px;cursor:pointer;font-size:13px;white-space:nowrap}
  .st-item .hi{width:16px;height:16px}
  .st-item:hover{background:var(--bg-chip);border-color:var(--acc)}
  .st-item.active{background:var(--acc);border-color:var(--acc);color:var(--txt-on-acc);font-weight:600}
  .st-main{width:100%;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:20px 24px;box-sizing:border-box}
  .st-cat h3{display:flex;align-items:center;gap:7px;font-size:13px;color:var(--acc);font-weight:700;margin:0 0 6px;border-bottom:1px solid var(--line);padding-bottom:8px}
  .st-cat h3 .hi{width:16px;height:16px}
  .st-cat.hidden{display:none}
  @media(max-width:560px){.st-main{padding:16px}}
  @media(min-width:561px){.st-wrap{flex-direction:row;align-items:flex-start}.st-side{flex-direction:column;width:220px;flex:0 0 auto;overflow-x:visible}.st-item{width:100%;justify-content:flex-start;padding:12px 16px}.st-main{flex:1;width:auto}}
  </style>
  <div class="view-head"><h2>${ICON[VIEW_ICONS['settings']]||''}站点设置</h2></div>
  <div class="st-wrap">
    <div class="st-side">
      ${stCatItems()}
    </div>
    <div class="st-main">
      <div class="st-cat" id="stcat-basic">
        <h3>${ICON.squares2x2}基础信息</h3>
        <label>网站名称</label><input id="st_name" value="${val('site_name')}" placeholder="HolaStack">
        <label>顶部图标 URL（可选，留空则显示文字名称）</label><input id="st_logo" value="${val('site_logo_url')}" placeholder="https://example.com/logo.png">
        <label>站点 Favicon URL</label><input id="st_favicon" value="${val('favicon_url')}" placeholder="https://example.com/favicon.ico">
        <label>界面语言</label><select id="st_lang">${(window.LANGS||{zh:'中文'}) && Object.entries(window.LANGS||{zh:'中文'}).map(([k,n])=>`<option value="${k}" ${s.ui_lang===k?'selected':''}>${n}</option>`).join('')}</select>
      </div>
      <div class="st-cat hidden" id="stcat-login">
        <h3>${ICON.user}登录页</h3>
        <label>登录页 LOGO 图片 URL（可选）</label><input id="st_login_img" value="${val('login_logo_url')}" placeholder="https://example.com/login-logo.png">
        <label>登录页 LOGO 文字（无图片时显示）</label><input id="st_login_text" value="${val('login_logo_text')}" placeholder="HolaStack">
        <label>登录页公告（留空则隐藏公告框，支持多行）</label><textarea id="st_notice" rows="3" placeholder="例如：系统将于本周六 23:00 停机维护。">${esc(s.login_notice||'')}</textarea>
      </div>
      <div class="st-cat hidden" id="stcat-footer">
        <h3>${ICON.puzzlePiece}${t('页脚与集成')}</h3>
        <label>页面底部 Footer（支持 HTML）</label><textarea id="st_footer" rows="2" placeholder="&copy; {Y} {SITE}">${esc(s.footer||'')}</textarea>
        <label>API 基础地址</label><input id="st_api_url" value="${val('api_base_url')}" placeholder="https://your-server.example.com">
      </div>
      <div class="st-cat hidden" id="stcat-maint">
        <h3>${ICON.clipboardDocumentList}${t('日志维护')}</h3>
        <div style="display:flex;flex-direction:column;gap:8px">
          ${maintCheck('uplinks','上行消息日志')}
          ${maintCheck('downlinks','下行消息日志')}
          ${maintCheck('events','网关日志')}
        </div>
        <div style="margin-top:12px"><button class="btn danger" onclick="clearMaintChecked()">${ICON.trash}${t('清空所选日志')}</button></div>
      </div>
      <div class="st-cat hidden" id="stcat-map">
        <h3>${ICON.map}地图服务</h3>
        <label>地图提供商（用于「位置地图」页渲染，可选）</label>
        <select id="st_mapprov">${window.MAP_PROVIDERS.map(p=>`<option value="${p.id}" ${s.map_provider===p.id?'selected':''}>${esc(p.name)}${p.needKey?'（需 Key）':''}</option>`).join('')}</select>
        <label>自定义瓦片 URL（选择「自定义瓦片 URL」时使用，Leaflet 占位符 {z}/{x}/{y}；含 token 可用 KEY 占位）</label>
        <input id="st_mapurl" value="${val('map_url')}" placeholder="https://your-tile-server.com/{z}/{x}/{y}.png?token=KEY">
        <label>API Key（下发给需要 Key 的提供商 / 填到上面的 KEY 占位）</label><input id="st_mapkey" value="${val('map_key')}" placeholder="粘贴地图提供商的访问令牌">
      </div>
      <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end">
        <button class="ghost" onclick="nav('dashboard')">${ICON.xMark}取消</button>
        <button onclick="busy('保存中…', saveSettings)">${ICON.check}保存</button>
      </div>
    </div>
  </div>`;
}

function stCat(id, btn){
  document.querySelectorAll('.st-cat').forEach(c => c.classList.toggle('hidden', c.id !== 'stcat-'+id));
  document.querySelectorAll('.st-item').forEach(b => b.classList.toggle('active', b === btn));
}

function stCatItems(){
  const defs = [
    {id:'basic', icon:'cpuChip',            label:'基础信息'},
    {id:'login', icon:'user',               label:'登录页'},
    {id:'footer',icon:'puzzlePiece',        label:t('页脚与集成')},
    {id:'maint', icon:'clipboardDocumentList',label:t('日志维护')},
    {id:'map',   icon:'map',                label:'地图服务'},
  ];
  const sorted = defs.slice().sort((a,b)=>a.label.length-b.label.length);
  return sorted.map(c=>`<button class="st-item${c.id==='basic'?' active':''}" onclick="stCat('${c.id}',this)">${ICON[c.icon]}${esc(c.label)}</button>`).join('');
}

function maintCheck(target, labelKey){
  return `<label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" class="maint_chk" value="${target}">${t(labelKey)}</label>`;
}
async function clearMaintChecked(){
  const targets = Array.from(document.querySelectorAll('.maint_chk:checked')).map(c=>c.value);
  if (!targets.length){ toast(t('请先勾选要清空的日志'),'warn'); return; }
  if (!confirm(t('确认清空') + ' ' + targets.length + ' ' + t('项日志') + '？' + t('此操作不可恢复'))) return;
  let tid = isTenant() ? (state.user.tenant_id||0) : 0;
  for (const t2 of targets){
    const r = await api('POST','/api/settings',{clear_logs: t2, clear_logs_tenant: tid});
    if (r && r.error){ alert(t(r.error)); return; }
  }
  toast(t('已清空'),'ok');
}

async function clearPageLogs(target){
  const map = {
    uplinks:  ['uplinks', '上行消息日志', viewUplinks],
    downlinks:['downlinks','下行消息日志', viewDownlinks],
    events:   ['events',  '网关日志',    viewEvents],
    api:      ['api',     'API 调用日志', viewApiLogs],
  };
  const m = map[target];
  if (!m) return;
  const [apiTarget, label, refresh] = m;

  let tid = 0;
  if (isTenant()) {
    tid = state.user.tenant_id || 0;
  } else if (target === 'api') {
    tid = (state.apiLogFilter && state.apiLogFilter.tenant_id) ? state.apiLogFilter.tenant_id : 0;
  } else if (target === 'events') {
    tid = state.tenantFilter || 0;
  } else {
    tid = 0;
  }
  const scopeTxt = tid ? t('（仅清理当前用户配置）') : t('（将清空全部）');
  if (!confirm(t('确认清空') + ' ' + t(label) + '？' + t('此操作不可恢复') + scopeTxt)) return;
  const r = await api('POST','/api/settings',{clear_logs: apiTarget, clear_logs_tenant: tid});
  if (r.error){ alert(t(r.error)); return; }
  toast(t('已清空') + ' ' + t(label) + (tid ? t('（当前用户配置）') : t('（全部）')), 'ok');
  refresh();
}

let logRefreshTimer = null;
const LOG_REFRESH_VIEWS = ['uplinks','downlinks','events','api-logs'];
const LOG_REFRESH_OPTS = [[0,'停止刷新'],[5,'5 秒'],[10,'10 秒'],[15,'15 秒'],[30,'30 秒'],[60,'1 分钟']];
let refreshFloatOpen = false;
let logRefreshTarget = null;

function logRefreshCtrl(){ return ''; }

function refreshFloatText(){
  const sec = parseInt(state.logRefreshSec||0,10);
  const o = LOG_REFRESH_OPTS.find(([s])=>s===sec);
  return o ? o[1] : LOG_REFRESH_OPTS[0][1];
}
function renderRefreshFloat(){
  const box = document.getElementById('logRefreshBox');
  if (!box) return;
  if (!LOG_REFRESH_VIEWS.includes(state.view)){
    box.innerHTML = '';
    box.style.display = 'none';
    refreshFloatOpen = false;
    return;
  }
  box.style.display = '';
  const cur = parseInt(state.logRefreshSec||0,10);
  box.innerHTML = `<div class="rf-wrap">
      <div class="refresh-panel${refreshFloatOpen?' show':''}">
        ${LOG_REFRESH_OPTS.map(([s,txt])=>`<button class="rf-opt${s===cur?' on':''}" onclick="setLogRefresh(${s})">${txt}</button>`).join('')}
      </div>
      <button class="float-btn refresh-fab" onclick="toggleRefreshFloat()" title="${refreshFloatText()}">${ICON.arrowPath}</button>
    </div>`;
}
function toggleRefreshFloat(){
  refreshFloatOpen = !refreshFloatOpen;
  renderRefreshFloat();
  if (refreshFloatOpen) setTimeout(()=>document.addEventListener('pointerdown', closeRefreshFloatOuter), 0);
  else document.removeEventListener('pointerdown', closeRefreshFloatOuter);
}
function closeRefreshFloatOuter(e){
  if (e.target && e.target.closest && e.target.closest('.rf-wrap')) return;
  refreshFloatOpen = false;
  const box = document.getElementById('logRefreshBox');
  if (box) { const p = box.querySelector('.refresh-panel'); if (p) p.classList.remove('show'); }
  document.removeEventListener('pointerdown', closeRefreshFloatOuter);
}
function stopLogRefresh(){
  if (logRefreshTimer){ clearInterval(logRefreshTimer); logRefreshTimer=null; }
}
function syncLogRefreshTimer(){
  if (!LOG_REFRESH_VIEWS.includes(state.view)) stopLogRefresh();
}
function setLogRefresh(sec){
  sec = parseInt(sec,10)||0;
  state.logRefreshSec = sec;
  refreshFloatOpen = false;
  document.removeEventListener('pointerdown', closeRefreshFloatOuter);
  stopLogRefresh();
  logRefreshTarget = sec>0 ? state.view : null;

  try { if (LOG_REFRESH_VIEWS.includes(state.view)) localStorage.setItem('elw_refresh_'+state.view, String(sec)); } catch(e){}
  if (sec>0){
    const target = state.view;
    logRefreshTimer = setInterval(()=>{

      if (((location.hash||'').slice(1)||'dashboard') !== target){ stopLogRefresh(); return; }
      nav(target, true);
    }, sec*1000);
  }
  renderRefreshFloat();
}

function restoreLogRefresh(){
  const v = state.view;
  if (!LOG_REFRESH_VIEWS.includes(v)) return;
  if (logRefreshTimer && logRefreshTarget === v) return;

  let sec = 10;
  try { const raw = localStorage.getItem('elw_refresh_'+v); sec = raw==null ? 10 : parseInt(raw,10); } catch(e){ sec = 10; }
  setLogRefresh(isNaN(sec) ? 0 : sec);
}

const FAB_PRIMARY = {
  applications:      {icon:ICON.plus,  title:'新建应用',     onClick:"newApplication()",           danger:false},
  devices:           {icon:ICON.plus,  title:'添加设备',     onClick:"newDevice()",               danger:false},
  gateways:          {icon:ICON.plus,  title:'新建网关',     onClick:"newGateway()",              danger:false},
  users:             {icon:ICON.plus,  title:'新建用户',     onClick:"newUser()",                 danger:false},
  'device-profiles': {icon:ICON.plus,  title:'新建模板',     onClick:"newDeviceProfile()",        danger:false},
  tenants:           {icon:ICON.plus,  title:'新建用户配置', onClick:"newTenant()",               danger:false},
  'multicast-groups':{icon:ICON.plus,  title:'新建组播组',   onClick:"newMulticast()",            danger:false},
  uplinks:           {icon:ICON.trash, title:'清空日志',     onClick:"clearPageLogs('uplinks')",   danger:true},
  downlinks:         {icon:ICON.trash, title:'清空日志',     onClick:"clearPageLogs('downlinks')", danger:true},
  events:            {icon:ICON.trash, title:'清空日志',     onClick:"clearPageLogs('events')",    danger:true},
  'api-logs':        {icon:ICON.trash, title:'清空日志',     onClick:"clearPageLogs('api')",       danger:true},
};
function renderFloatPrimary(){
  const box = document.getElementById('floatPrimary');
  if (!box) return;
  if (window.innerWidth > 760){ box.innerHTML=''; box.style.display='none'; return; }
  box.style.display='';
  const p = FAB_PRIMARY[state.view];
  box.innerHTML = p ? `<button class="float-btn fab-primary${p.danger?' danger':''}" onclick="${p.onClick}" title="${p.title}">${p.icon}</button>` : '';
}
async function saveSettings(){
  const langSel = document.getElementById('st_lang');
  const body = {
    site_name: v('st_name'),
    site_logo_url: v('st_logo'),
    favicon_url: v('st_favicon'),
    login_logo_url: v('st_login_img'),
    login_logo_text: v('st_login_text'),
    login_notice: v('st_notice'),
    footer: v('st_footer'),
    api_base_url: v('st_api_url'),
    ui_lang: langSel ? langSel.value : 'zh',
    map_provider: v('st_mapprov'),
    map_url: v('st_mapurl'),
    map_key: v('st_mapkey'),
  };
  const r = await api('POST','/api/settings', body);
  if (r.error) { toast(r.error, 'err'); return; }
  await applyPublicSettings();

  await applyLanguage(body.ui_lang);
  toast(t('设置已保存'), 'ok');
}

async function applyPublicSettings(){
  try {
    const r = await fetch('/api/public-settings');
    const d = (await r.json()).data || {};
    const brand = document.getElementById('brand');
    if (brand) {
      if (d.site_logo_url) brand.innerHTML = `<a href="#dashboard" onclick="nav('dashboard');return false" style="text-decoration:none;color:inherit"><img src="${esc(d.site_logo_url)}" alt="logo"></a>`;
      else brand.innerHTML = `<a href="#dashboard" onclick="nav('dashboard');return false" style="text-decoration:none;color:inherit">${esc(d.site_name || 'HolaStack')}</a>`;
    }
    const ll = document.getElementById('loginLogo');
    if (ll) {
      if (d.login_logo_url) ll.innerHTML = `<img src="${esc(d.login_logo_url)}" alt="logo">`;
      else if (d.login_logo_text) ll.innerHTML = `<div style="font-size:24px;font-weight:700;color:var(--txt)">${esc(d.login_logo_text)}</div>`;
      else ll.innerHTML = '';
    }
    if (d.site_name) document.title = d.site_name;

    window.ELW_API_BASE_URL = d.api_base_url || '';
    const fav = document.getElementById('faviconLink');
    if (fav && d.favicon_url) fav.href = d.favicon_url;

    const siteName = d.site_name || 'HolaStack';
    const rawFooter = d.footer || ('© ' + new Date().getFullYear() + ' ' + siteName);
    const safeFooter = String(rawFooter).replace(/<script[\s\S]*?<\/script>/gi, '');
    const lf = document.getElementById('loginFooter');
    if (lf) { lf.innerHTML = ''; lf.classList.add('hidden'); }
    const sf = document.getElementById('siteFooter');
    if (sf) { sf.innerHTML = safeFooter; sf.classList.remove('hidden'); }
    const ln = document.getElementById('loginNotice');
    if (ln) {
      if (d.login_notice && d.login_notice.trim()) {
        ln.innerHTML = `<span class="ln-ico">${ICON.speakerWave}</span><span class="ln-txt">${esc(d.login_notice)}</span>`;

        ln.classList.toggle('single', !/(\r\n|\n|\r)/.test(d.login_notice.trim()));
        ln.classList.remove('hidden');
      }
      else { ln.innerHTML = ''; ln.classList.add('hidden'); }
    }
  } catch(e) {}
}
async function changePw(){

  if (isDemo()) {
    toast(t('演示模式：当前为只读账号，不能修改密码'), 'warn');
    return;
  }
  let targetSel = '';
  if (isAdmin()) {
    const r = await api('GET','/api/users');
    targetSel = `<label>目标用户（管理员可改他人；留空=自己）</label><select id="m_pw_uid"><option value="">我自己</option>${(r.data||[]).map(u=>`<option value="${u.id}">${esc(u.username)}</option>`).join('')}</select>`;
  }
  openModal(`<h3>修改密码</h3>${targetSel}
    <label>新密码（≥6 字符）</label><input id="m_pw_new" type="password">
    <label>确认新密码</label><input id="m_pw_cfm" type="password">
    <div id="pw_err" class="muted" style="color:var(--err)"></div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', savePw)">保存</button></div>`);
}
async function savePw(){
  const np=v('m_pw_new'), cf=v('m_pw_cfm'); const err=document.getElementById('pw_err');
  if(np.length<6){ err.textContent='密码至少 6 位'; return; }
  if(np!==cf){ err.textContent='两次输入不一致'; return; }
  const body={new_password:np};
  const uid=document.getElementById('m_pw_uid'); if(uid && uid.value) body.user_id=+uid.value;
  const r=await api('POST','/api/users/password',body); if(r.error){err.textContent=r.error;return;} closeModal();
  if(!body.user_id){ alert('密码已修改，请重新登录'); state.token=null; state.user=null; localStorage.removeItem('elw_token'); renderShell(); }
  else alert('已修改该用户密码');
}
async function changePwFor(id){
  openModal(`<h3>${t('修改用户')} #${id} ${t('密码')}</h3>
    <div class="rl-sec">
      <div class="row">
        <div><label>新密码（≥6 字符）</label><input id="m_pw_new" type="password"></div>
        <div><label>确认新密码</label><input id="m_pw_cfm" type="password"></div>
      </div>
      <div id="pw_err" class="muted" style="color:var(--err);min-height:16px"></div>
    </div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>savePwFor(${id}))">保存</button></div>`);
}
async function savePwFor(id){
  const np=v('m_pw_new'), cf=v('m_pw_cfm'); const err=document.getElementById('pw_err');
  if(np.length<6){ err.textContent='密码至少 6 位'; return; }
  if(np!==cf){ err.textContent='两次输入不一致'; return; }
  const r=await api('POST','/api/users/password',{user_id:id,new_password:np}); if(r.error){err.textContent=r.error;return;} closeModal(); alert('已修改该用户密码');
}

const randHex = (n) => Array.from({length:n},()=>Math.floor(Math.random()*16).toString(16)).join('');
async function viewDeviceProfiles(){
  const q = state.tenantFilter ? ('?tenant_id='+state.tenantFilter) : '';
  const [r, tf] = await Promise.all([api('GET','/api/device-profiles'+q), tenantFilterHtml()]);
  state.dps = r.data||[];

  const clsOf = d => {
    const cls = []; if(+d.supports_class_b) cls.push('B'); if(+d.supports_class_c) cls.push('C');
    return cls.length ? cls.join('+') : 'A';
  };

  const regions = [...new Set((state.dps||[]).map(d=>d.region).filter(Boolean))].sort();
  const regionValues = [{value:'', label:'全部'}, ...regions.map(rg=>({value:rg, label:rg}))];
  const classValues = [
    {value:'', label:'全部'},
    {value:'A',   label:'Class A'},
    {value:'B',   label:'Class B'},
    {value:'C',   label:'Class C'},
    {value:'B+C', label:'Class B+C'},
  ];
  const dpsCfg = {
    state, stateKey:'dpsSort',
    defaultSort:{col:null,dir:'desc'},
    cellValue: (d, k) => ({id:d.id, name:d.name, region:d.region, mac:d.mac_version, adr:d.adr_algorithm, codec:d.payload_codec_runtime, cls:clsOf(d)}[k]),
    cols:[
      {key:'id',     label:'ID',    type:'num',  firstDir:'asc', sortable:false},
      {key:'name',   label:'名称',   type:'str',  firstDir:'asc', sortable:false},
      {key:'region', label:'区域',   type:'status', firstDir:'asc', sortable:false, opts:{getValue:d=>d.region, values:regionValues}},
      {key:'mac',    label:'MAC',    type:'str',  firstDir:'asc', sortable:false},
      {key:'adr',    label:'ADR',    type:'str',  firstDir:'asc', sortable:false},
      {key:'codec',  label:'编解码',  type:'str',  firstDir:'asc', sortable:false},
      {key:'cls',    label:'Class',  type:'status', firstDir:'asc', sortable:false, opts:{getValue:clsOf, values:classValues}},
      {key:'_raw',   label:'',       type:'raw'},
    ],
    filterStatusList: [
      {col:'region', value: state.dpsFRegion},
      {col:'cls',    value: state.dpsFCls},
    ],
    rows: state.dps,
    rowHtml: d => `<tr><td>${d.id}</td><td>${esc(d.name)}</td><td class="muted">${esc(d.region)}</td>
      <td class="muted">${esc(d.mac_version)}</td><td class="muted">${esc(d.adr_algorithm)}</td>
      <td class="muted">${esc(d.payload_codec_runtime)}</td><td class="muted">${clsOf(d)}</td>
      <td>${adminBtn(`<button class="btn ghost" onclick="editDeviceProfile(${d.id})">${ICON.pencilSquare}编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delDeviceProfile(${d.id}))">${ICON.trash}删除</button>`)}</td></tr>`,
    emptyText:'暂无设备模板',
  };
  const [filteredDps, dpsTotal] = filterAndSortRows(dpsCfg);
  dpsCfg.rows = paginateRows(filteredDps, state, {pageKey:'dpsPage', limitKey:'dpsLimit', offsetKey:'dpsOffset'})[0];
  dpsCfg.presorted = true;
  const table = buildSortableTable(dpsCfg);
  const pager = buildPager({ total: dpsTotal, limit: state.dpsLimit, offset: state.dpsOffset, pageKey:'dpsPage', limitKey:'dpsLimit', offsetKey:'dpsOffset', totalKey:'dpsTotal', refresh:'viewDeviceProfiles' });
  window.dpsSort_fstatus = (col, v) => {
    const map = {region:'dpsFRegion', cls:'dpsFCls'};
    _tableSetFStatus(map[col] || 'dpsFRegion', 'viewDeviceProfiles', v);
  };
  window.viewDeviceProfiles__page = p => _pagerGo({pageKey:'dpsPage',limitKey:'dpsLimit',offsetKey:'dpsOffset',totalKey:'dpsTotal'},'viewDeviceProfiles',p);
  window.viewDeviceProfiles__limit = l => _pagerSetLimit({pageKey:'dpsPage',limitKey:'dpsLimit',offsetKey:'dpsOffset',totalKey:'dpsTotal'},'viewDeviceProfiles',l);
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['device-profiles']]||''}设备模板</h2>${adminBtn('<button onclick="newDeviceProfile()">'+ICON.plus+'新建模板</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px">${tf}
      <button class="btn ghost" onclick="resetFilters(()=>{state.dpsFRegion='';state.dpsFCls='';state.dpsSort={col:null,dir:'desc'};state.dpsPage=1;state.dpsOffset=0;state.dpsLimit=50;}, viewDeviceProfiles)">${ICON.arrowPath}重置</button></div>
    ${table}
    ${pager}`;
}

async function viewTenants(){
  const r = await api('GET','/api/tenants'); state.tenants = r.data||[];
  const rows = state.tenants.map(row=>{
    const unlimited = +row.private_gateways_unlimited === 1;
    return `<tr><td>${row.id}</td><td>${esc(row.name)}</td><td class="muted">${esc(row.description||'')}</td>
    <td class="muted">${unlimited ? t('无限制') : t('上限') + ' ' + (row.private_gateways_limit||0)}</td>
    <td>${adminBtn(`<button class="btn ghost" onclick="editTenant(${row.id})">${ICON.pencilSquare}${t('编辑')}</button> <button class="btn danger" onclick="busy('删除中…', ()=>delTenant(${row.id}))">${ICON.trash}${t('删除')}</button>`)}</td></tr>`;
  }).join('')||`<tr><td colspan="5" class="muted">${t('暂无用户配置')}</td></tr>`;
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['tenants']]||''}${t('用户配置')}</h2>${adminBtn(`<button onclick="newTenant()">${ICON.plus}${t('新建用户配置')}</button>`)}</div>
    <table><thead><tr><th>ID</th><th>${t('名称')}</th><th>${t('描述')}</th><th>${t('私有网关上限')}</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
}
async function viewApiKeys(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const tf = await tenantFilterHtml();
  let ks=[];
  if(isAdmin()){
    const r=await api('GET','/api/internal/api-keys?all=1&limit=500'); ks=(r.result||[]).map(k=>({...k, kind:'cs', id:k.id, token_preview:'', created_at:null}));
  } else {
    const ra=await api('GET','/api/applications'+(tq?'?'+tq:'')); state.apps=ra.data||[];
    if(state.appSel){ const r=await api('GET','/api/api-keys?app_id='+state.appSel+(tq?'&'+tq:'')); ks=r.data||[]; }
  }
  const isCs = ks.length && ks[0].kind==='cs';
  const akCfg = {
    state, stateKey:'apiKeysSort',
    defaultSort:{col:'name',dir:'asc'},
    cellValue: (k, ck) => ({id:k.id, name:k.name, scope: k.is_admin?'全局':(k.tenant_id&&k.tenant_id!=='00000000-0000-0000-0000-000000000000'?'租户':'—'), ro: k.is_read_only?'只读':'', token:k.token_preview||'', time:k.created_at}[ck]),
    cols: isCs ? [
      {key:'id',    label:'ID(UUID)',   type:'str', firstDir:'asc', sortable:false},
      {key:'name',  label:'名称',        type:'str', firstDir:'asc'},
      {key:'scope', label:'作用域',      type:'str', firstDir:'asc', sortable:false},
      {key:'ro',    label:'权限',        type:'str', firstDir:'asc', sortable:false},
    ] : [
      {key:'id',    label:'ID',          type:'num', firstDir:'asc', sortable:false},
      {key:'name',  label:'名称',         type:'str', firstDir:'asc', sortable:false},
      {key:'token', label:'Token(预览)', type:'str', firstDir:'asc', sortable:false},
      {key:'time',  label:'创建时间',     type:'time', firstDir:'desc'},
      {key:'_raw',  label:'',            type:'raw'},
    ],
    rows: ks,
    rowHtml: isCs ? (k => `<tr><td class="muted"><code>${esc(k.id)}</code></td><td>${esc(k.name)}</td><td>${k.is_admin?'<span class="badge">全局</span>':'租户'}</td><td>${k.is_read_only?'只读':'读写'}</td>
      <td>${adminBtn(`<button class="btn danger" onclick="busy('删除中…', ()=>delApiKey('${esc(k.id)}'))">${ICON.trash}删除</button>`)}</td></tr>`)
      : (k => `<tr><td>${k.id}</td><td>${esc(k.name)}</td><td class="muted"><code>${esc(k.token_preview)}…</code></td><td class="muted">${new Date(k.created_at*1000).toLocaleString()}</td>
      <td>${adminBtn(`<button class="btn danger" onclick="busy('删除中…', ()=>delApiKey(${k.id}))">${ICON.trash}删除</button>`)}</td></tr>`),
    emptyText: isCs ? '暂无 API 密钥' : '请先在上方选择应用',
  };
  const [filteredKeys, keysTotal] = filterAndSortRows(akCfg);
  akCfg.rows = paginateRows(filteredKeys, state, {pageKey:'apiKeysPage', limitKey:'apiKeysLimit', offsetKey:'apiKeysOffset'})[0];
  akCfg.presorted = true;
  const table = buildSortableTable(akCfg);
  const pager = buildPager({ total: keysTotal, limit: state.apiKeysLimit, offset: state.apiKeysOffset, pageKey:'apiKeysPage', limitKey:'apiKeysLimit', offsetKey:'apiKeysOffset', totalKey:'apiKeysTotal', refresh:'viewApiKeys' });
  window.apiKeysSort_sort = col => _tableToggleSort('apiKeysSort','viewApiKeys',col);
  window.viewApiKeys__page = p => _pagerGo({pageKey:'apiKeysPage',limitKey:'apiKeysLimit',offsetKey:'apiKeysOffset',totalKey:'apiKeysTotal'},'viewApiKeys',p);
  window.viewApiKeys__limit = l => _pagerSetLimit({pageKey:'apiKeysPage',limitKey:'apiKeysLimit',offsetKey:'apiKeysOffset',totalKey:'apiKeysTotal'},'viewApiKeys',l);
  const appPicker = isCs ? '' : `<div style="flex:0 0 360px"><label>应用</label><select id="ak_app" onchange="state.appSel=this.value;state.apiKeysPage=1;state.apiKeysOffset=0;nav('api-keys')">${(state.apps||[]).map(a=>`<option value="${a.id}" ${String(a.id)===String(state.appSel)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('')}</select></div>`;
  document.getElementById('view').innerHTML=`<div class="view-head"><h2>${ICON[VIEW_ICONS['api-keys']]||''}API 密钥</h2>${isAdmin()?adminBtn('<button onclick="newApiKey()">'+ICON.plus+'新建 API 密钥</button>'):''}</div>
   <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}${appPicker}${!isCs&&state.appSel?adminBtn('<button onclick="newApiKey()">'+ICON.plus+'新建应用密钥</button>'):''}</div>
   ${table}
   ${pager}`;
}
async function viewIntegrations(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const [ra, tf] = await Promise.all([api('GET','/api/applications'+(tq?'?'+tq:'')), tenantFilterHtml()]);
  state.apps = ra.data||[];
  const opts=`<option value="">选择应用…</option>`+state.apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.intAppSel)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('');
  let its=[];
  if(state.intAppSel){
    const r=await api('GET','/api/integrations?app_id='+state.intAppSel+(tq?'&'+tq:'')); its=r.data||[];
  }
  state.intMap = Object.fromEntries((its||[]).map(x=>[x.id,x]));
  const summaryOf = it => {
    let cfg={}; try{ if(it.config_json) cfg=JSON.parse(it.config_json)||{}; }catch(e){}
    return it.kind==='HTTP' ? (cfg.url||'') : it.kind==='INFLUX_DB' ? (cfg.endpoint||'') : it.kind==='MQTT_GLOBAL' ? (cfg.server||'') : it.kind==='AWS_SNS' ? (cfg.topic_arn||'') : it.kind==='AZURE_SERVICE_BUS' ? (cfg.publish_name||'') : it.kind==='GCP_PUBSUB' ? (cfg.topic_name||'') : it.kind==='AMQP' ? (cfg.url||'') : it.kind==='KAFKA' ? (cfg.topic||'') : it.kind==='MODBUS_TCP' ? ((cfg.server||'')+' unit='+(cfg.unit_id??1)+' addr='+(cfg.address??0)+' ← '+(cfg.value_path||'')) : '';
  };
  const intgCfg = {
    state, stateKey:'intgSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (it, k) => ({kind:it.kind, enabled:it.enabled?1:0, summary:summaryOf(it), time:it.created_at}[k]),
    cols:[
      {key:'kind',    label:'类型',     type:'str', firstDir:'asc', sortable:false},
      {key:'enabled', label:'状态',     type:'num', firstDir:'asc', sortable:false},
      {key:'summary', label:'配置',     type:'str', firstDir:'asc', sortable:false},
      {key:'time',    label:'创建时间', type:'time', firstDir:'desc'},
      {key:'_raw',    label:'',        type:'raw'},
    ],
    rows: its,
    rowHtml: it => `<tr><td><span class="tag">${it.kind}</span></td>
        <td><span class="tag ${it.enabled?'ok':'off'}">${it.enabled?'启用':'停用'}</span></td>
        <td class="muted">${esc(summaryOf(it))}</td>
        <td class="muted">${new Date(it.created_at*1000).toLocaleString()}</td>
        <td>${adminBtn(`<button class="btn ghost" onclick="editIntegration(${it.id})">${ICON.pencilSquare}编辑</button> <button class="btn ghost" onclick="busy('处理中…', ()=>toggleIntegration(${it.id},${it.enabled?0:1}))">${it.enabled?ICON.xMark+'停用':ICON.check+'启用'}</button> <button class="btn danger" onclick="busy('删除中…', ()=>delIntegration(${it.id}))">${ICON.trash}删除</button>`)}</td></tr>`,
    emptyText: state.intAppSel ? '该应用暂无外部集成' : '请先在上方选择应用',
  };
  const [filteredInts, intgTotal] = filterAndSortRows(intgCfg);
  intgCfg.rows = paginateRows(filteredInts, state, {pageKey:'intgPage', limitKey:'intgLimit', offsetKey:'intgOffset'})[0];
  intgCfg.presorted = true;
  const table = buildSortableTable(intgCfg);
  const pager = buildPager({ total: intgTotal, limit: state.intgLimit, offset: state.intgOffset, pageKey:'intgPage', limitKey:'intgLimit', offsetKey:'intgOffset', totalKey:'intgTotal', refresh:'viewIntegrations' });
  window.intgSort_sort = col => _tableToggleSort('intgSort','viewIntegrations',col);
  window.viewIntegrations__page = p => _pagerGo({pageKey:'intgPage',limitKey:'intgLimit',offsetKey:'intgOffset',totalKey:'intgTotal'},'viewIntegrations',p);
  window.viewIntegrations__limit = l => _pagerSetLimit({pageKey:'intgPage',limitKey:'intgLimit',offsetKey:'intgOffset',totalKey:'intgTotal'},'viewIntegrations',l);
  document.getElementById('view').innerHTML=`<div class="view-head"><h2>${ICON[VIEW_ICONS['integrations']]||''}外部集成</h2></div>
   <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 360px"><label>应用</label><select id="int_app" onchange="state.intAppSel=this.value;state.intgPage=1;state.intgOffset=0;nav('integrations')">${opts}</select></div>${state.intAppSel?adminBtn('<button onclick="newIntegration()">'+ICON.plus+'新建外部集成</button>'):''}</div>
   ${table}
   ${pager}`;
}
async function viewMulticastGroups(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const [ra, tf] = await Promise.all([api('GET','/api/applications'+(tq?'?'+tq:'')), tenantFilterHtml()]);
  state.apps = ra.data||[];
  const opts=`<option value="">全部应用</option>`+state.apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.appSel)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('');
  let q=[]; if(tq) q.push(tq); if(state.appSel) q.push('app_id='+state.appSel);
  const r=await api('GET','/api/multicast-groups'+(q.length?'?'+q.join('&'):'')); const ms=r.data||[];
  const appName=(id)=>{const a=(state.apps||[]).find(x=>x.id===id);return a?esc(a.name):('#'+id);};
  const rows=ms.map(m=>`<tr><td>${m.id}</td><td>${esc(m.name)}</td><td class="muted">${appName(m.application_id)}</td>
     <td class="muted">${esc(m.region)}</td><td><span class="tag ${m.group_type}">${m.group_type}</span></td>
     <td class="muted"><code>${esc(m.mc_addr)}</code></td><td class="muted">DR${m.dr}</td><td class="muted">${m.f_cnt}</td>
     <td>${adminBtn(`<button class="btn ghost" onclick="mcDetail(${m.id})">${ICON.bookOpen}详情</button> <button class="btn ghost" onclick="editMulticast(${m.id})">${ICON.pencilSquare}编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delMulticast(${m.id}))">${ICON.trash}删除</button>`)}</td></tr>`).join('')||`<tr><td colspan="9" class="muted">暂无组播组</td></tr>`;
  document.getElementById('view').innerHTML=`<div class="view-head"><h2>${ICON[VIEW_ICONS['multicast-groups']]||''}组播组</h2>${adminBtn('<button onclick="newMulticast()">'+ICON.plus+'新建组播组</button>')}</div>
   <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 360px"><label>按应用筛选</label><select id="mc_app" onchange="state.appSel=this.value;nav('multicast-groups')">${opts}</select></div><button class="btn ghost" onclick="resetFilters(()=>{state.appSel='';}, viewMulticastGroups)">${ICON.arrowPath}重置</button></div>
   <table><thead><tr><th>ID</th><th>名称</th><th>应用</th><th>区域</th><th>类型</th><th>MC Addr</th><th>DR</th><th>FCnt</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
}
async function mcDetail(id){
  const g = await api('GET',`/api/multicast-groups/${id}`);
  const devs = await api('GET',`/api/multicast-groups/${id}/devices`);
  const gws = await api('GET',`/api/multicast-groups/${id}/gateways`);
  state.mcDetail = {id, g, devs:(devs.data||[]).map(x=>x.dev_eui), gws:(gws.data||[]).map(x=>x.gw_id)};
  const devList=(state.mcDetail.devs.map(e=>`<tr><td><code>${esc(e)}</code></td><td><button class="btn danger" onclick="busy('移除中…', ()=>rmMcDev(${id},'${esc(e)}'))">${ICON.trash}移除</button></td></tr>`).join(''))||`<tr><td colspan="2" class="muted">暂无设备</td></tr>`;
  const gwList=(state.mcDetail.gws.map(e=>`<tr><td><code>${esc(e)}</code></td><td><button class="btn danger" onclick="busy('移除中…', ()=>rmMcGw(${id},'${esc(e)}'))">${ICON.trash}移除</button></td></tr>`).join(''))||`<tr><td colspan="2" class="muted">暂无网关（为空则广播到全部网关）</td></tr>`;
  openModal(`<h3>${t('组播组')} #${id} ${esc(g.name||'')}</h3>
   <p class="muted">MC Addr: <code>${esc(g.mc_addr||'')}</code> · 类型 ${g.group_type} · DR${g.dr} · f_cnt ${g.f_cnt} · 应用 #${g.application_id}</p>
   <h4 style="margin-top:6px">下发数据</h4>
   <div class="row"><div style="flex:0 0 120px"><label>端口 (1..223)</label><input id="m_port" value="10"></div><div style="flex:2"><label>Hex 负载</label><input id="m_payload" placeholder="48656c6c6f"></div></div>
   <button onclick="enqueueMc(${id})">加入下发队列</button>
   <h4 style="margin-top:14px">设备（仅用于展示/管理，不参与单播）</h4>
   <div class="row"><div><input id="m_mcdev" placeholder="DevEUI 16 hex" oninput="hexOnly(this)"></div><button onclick="addMcDev(${id})">${ICON.plus}添加设备</button></div>
   <table style="margin-top:8px"><thead><tr><th>DevEUI</th><th></th></tr></thead><tbody>${devList}</tbody></table>
   <h4 style="margin-top:14px">网关（空=全部网关）</h4>
   <div class="row"><div><input id="m_mcgw" placeholder="Gateway ID" oninput="hexOnly(this)"></div><button onclick="addMcGw(${id})">${ICON.plus}添加网关</button></div>
   <table style="margin-top:8px"><thead><tr><th>GatewayID</th><th></th></tr></thead><tbody>${gwList}</tbody></table>
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}

const FUOTA_STATES = ['PENDING','SETUP','FRAGMENTATION','STATUS','DONE','FAILED'];
const FUOTA_STATE_CLS = {PENDING:'',SETUP:'ok',FRAGMENTATION:'ok',STATUS:'ok',DONE:'ok',FAILED:'err'};
const FUOTA_STATE_LABEL = {PENDING:'待启动',SETUP:'参数下发',FRAGMENTATION:'分包传输',STATUS:'状态查询',DONE:'已完成',FAILED:'失败'};

async function viewFuota(){
  const tq = state.tenantFilter ? ('tenant_id='+state.tenantFilter) : '';
  const [ra, rmc, tf] = await Promise.all([
    api('GET','/api/applications'+(tq?'?'+tq:'')),
    api('GET','/api/multicast-groups'+(tq?'?'+tq:'')),
    tenantFilterHtml(),
  ]);
  state.apps = ra.data || [];
  const groups = rmc.data || [];
  const appOpts = `<option value="">选择应用…</option>` + state.apps.map(a=>`<option value="${a.id}">#${a.id} ${esc(a.name)}</option>`).join('');
  const grpOpts = `<option value="">选择组播组…</option>` + groups.map(g=>`<option value="${g.id}">#${g.id} ${esc(g.name)} · ${esc(g.region)} · ${esc(g.mc_addr||'')}</option>`).join('');

  const campR = await api('GET','/api/fuota'+(tq?'?'+tq:''));
  const camps = (campR && campR.data) || [];

  const stateColor = (s) => {
    const c = FUOTA_STATE_CLS[s] || '';
    return `<span class="tag ${c}">${FUOTA_STATE_LABEL[s] || esc(s)}</span>`;
  };
  const progress = (camp) => {
    if (camp.state === 'DONE' || camp.state === 'FAILED' || camp.state === 'PENDING') {
      return `<span class="muted">${camp.frames_sent||0}/${camp.total_frames||0} 帧</span>`;
    }
    const total = Math.max(1, camp.total_frames||0);
    const pct = Math.min(100, Math.round(((camp.frames_sent||0) / total) * 100));
    return `<div class="bar"><span style="width:${pct}%;background:var(--acc)"></span></div><span class="muted" style="font-size:12px;margin-left:6px">${pct}% · ${camp.frames_sent||0}/${total}</span>`;
  };
  const rowHtml = c => `<tr>
    <td>${c.id}</td>
    <td>${esc(c.name||'')}</td>
    <td class="muted">${esc(c.region||'')}</td>
    <td class="muted"><code>${esc(c.multicast_addr||'')}</code></td>
    <td>${stateColor(c.state||'PENDING')}</td>
    <td>${progress(c)}</td>
    <td class="muted">${c.started_at?new Date(c.started_at*1000).toLocaleString():'—'}</td>
    <td>${adminBtn(`
      <button class="btn ghost" onclick="fuotaDetail(${c.id})">${ICON.bookOpen}详情</button>
      ${c.state==='PENDING' ? `<button class="btn ghost" onclick="fuotaStart(${c.id})">${ICON.cloudArrowUp}上传固件并启动</button>` : ''}
      <button class="btn danger" onclick="busy('删除中…', ()=>delFuota(${c.id}))">${ICON.trash}删除</button>
    `)}</td>
  </tr>`;

  const rows = (camps.length ? camps.map(rowHtml).join('') : `<tr><td colspan="8" class="muted" style="text-align:center;padding:24px">暂无 FUOTA 升级任务。先在上方创建新活动。</td></tr>`).trim();

  document.getElementById('view').innerHTML = `<div class="view-head">
      <h2>${ICON[VIEW_ICONS['fuota']]||''}固件升级 (FUOTA)</h2>
      <div class="muted" style="margin-left:12px;font-size:12px">基于 LoRaWAN Fragmentation / Multicast 的批量固件下发 · 上行通过 FUOTA Test 端口 224</div>
    </div>
    <div class="card" style="margin-bottom:14px;padding:14px">
      <h4 style="margin:0 0 8px">${ICON.plus}新建 FUOTA 升级活动</h4>
      <div class="row" style="gap:10px;align-items:flex-end">
        <div style="flex:1;min-width:200px"><label>名称</label><input id="fu_name" placeholder="例如：v1.2.3 全网升级"></div>
        <div style="flex:1;min-width:200px"><label>应用</label><select id="fu_app">${appOpts}</select></div>
        <div style="flex:1;min-width:240px"><label>组播组</label><select id="fu_grp">${grpOpts}</select></div>
        <button onclick="createFuota()">${ICON.plus}创建活动</button>
      </div>
      <p class="muted" style="margin:8px 0 0;font-size:12px">活动创建后状态为 PENDING，需要先添加组播组成员设备，再上传固件启动。</p>
    </div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}</div>
    <table class="tbl">
      <thead><tr><th>ID</th><th>名称</th><th>区域</th><th>MC Addr</th><th>状态</th><th>进度</th><th>开始时间</th><th></th></tr></thead>
      <tbody>${rows}</tbody>
    </table>`;
}

async function createFuota(){
  const name = (document.getElementById('fu_name').value||'').trim();
  const appId = parseInt(document.getElementById('fu_app').value||'0', 10);
  const mgId  = parseInt(document.getElementById('fu_grp').value||'0', 10);
  if (!name){ toast('请填写活动名称','warn'); return; }
  if (!appId){ toast('请选择应用','warn'); return; }
  if (!mgId){ toast('请选择组播组','warn'); return; }
  const r = await api('POST','/api/fuota',{ name, application_id:appId, multicast_group_id:mgId });
  if (r && r.error){ toast(r.error,'err'); return; }
  toast('已创建活动 #'+(r.id||'')+'，请在详情中添加设备并上传固件','ok');
  nav('fuota');
}

async function delFuota(id){
  if (!confirm('确认删除该 FUOTA 活动？相关设备部署、帧、片段将一并清除。')) return;
  const r = await api('DELETE','/api/fuota/'+id);
  if (r && r.error){ toast(r.error,'err'); return; }
  toast('已删除','ok');
  nav('fuota');
}

async function fuotaDetail(id){
  const r = await api('GET','/api/fuota/'+id);
  if (!r || r.error){ toast(r.error||'未找到','err'); return; }
  const c = r;
  const groups = (await api('GET','/api/multicast-groups')).data || [];
  const grp = groups.find(g => g.id === c.multicast_group_id) || {};
  const stateColor = (s) => `<span class="tag ${FUOTA_STATE_CLS[s]||''}">${FUOTA_STATE_LABEL[s]||esc(s)}</span>`;

  const devs = (c.deployments||[]);
  const devList = devs.length
    ? devs.map(d => `<tr>
        <td>${d.dev_id}</td>
        <td><code>${esc(d.dev_eui||'')}</code></td>
        <td>${esc(d.dev_name||'')}</td>
        <td>${stateColor(d.state||'PENDING')}</td>
        <td class="muted">${d.fragments_received||0} / ${d.frag_nb_missing||'?'} 缺</td>
        <td class="muted">${d.mc_group_ans?'✓':'—'}</td>
        <td class="muted">${d.status_ans?'✓':'—'}</td>
      </tr>`).join('')
    : `<tr><td colspan="7" class="muted" style="text-align:center;padding:12px">尚未添加任何设备</td></tr>`;

  const canAdd = c.state === 'PENDING';
  const isActive = c.state === 'SETUP' || c.state === 'FRAGMENTATION' || c.state === 'STATUS';

  openModal(`<h3>${ICON.cloudArrowUp} FUOTA #${id} · ${esc(c.name||'')}</h3>
    <div class="row" style="gap:14px;margin:6px 0 12px">
      <div><span class="muted">状态：</span>${stateColor(c.state)}</div>
      <div><span class="muted">应用：</span>#${c.application_id}</div>
      <div><span class="muted">组播组：</span>#${c.multicast_group_id} ${esc(grp.name||'')}</div>
      <div><span class="muted">MC Addr：</span><code>${esc(c.multicast_addr||'')}</code></div>
      <div><span class="muted">区域：</span>${esc(c.region||'')}</div>
    </div>
    <div class="row" style="gap:14px;margin:4px 0 10px">
      <div><span class="muted">进度：</span>${c.frames_sent||0} / ${c.total_frames||0} 帧</div>
      <div><span class="muted">设备数：</span>${(c.deployments||[]).length}</div>
      <div><span class="muted">帧队列：</span>${c.frames_total||0} 条</div>
      <div><span class="muted">最小/最大间隔：</span>${c.min_delay||200} / ${c.max_delay||1000} ms</div>
      <div><span class="muted">超时：</span>${c.timeout||3600} s</div>
    </div>
    ${canAdd ? `
    <h4 style="margin:14px 0 6px">${ICON.plus}添加设备（须在组播组成员中）</h4>
    <div class="row" style="gap:10px">
      <div style="flex:1"><input id="fu_devid" placeholder="设备 ID" type="number" min="1"></div>
      <button onclick="fuotaAddDev(${id})">添加</button>
    </div>` : ''}
    <h4 style="margin:14px 0 6px">设备部署进度</h4>
    <table class="tbl">
      <thead><tr><th>ID</th><th>DevEUI</th><th>名称</th><th>状态</th><th>已收片段</th><th>MC Group</th><th>Status</th></tr></thead>
      <tbody>${devList}</tbody>
    </table>
    ${canAdd ? `
    <h4 style="margin:14px 0 6px">${ICON.cloudArrowUp}上传固件并启动</h4>
    <p class="muted" style="font-size:12px;margin:0 0 6px">选择 .bin 固件文件（最大 5MB）。系统将分片并通过组播下行通道推送到所有已添加设备。</p>
    <div class="row" style="gap:10px;align-items:flex-end">
      <div style="flex:1"><label>固件文件</label><input id="fu_fwfile" type="file" accept=".bin,application/octet-stream"></div>
      <div style="flex:0 0 120px"><label>版本号 (可选)</label><input id="fu_fwver" placeholder="1.2.3"></div>
      <div style="flex:0 0 100px"><label>分包大小</label><input id="fu_frag" value="200" type="number" min="50" max="200"></div>
      <button onclick="fuotaUpload(${id})" id="fuUploadBtn">${ICON.cloudArrowUp}上传并启动</button>
    </div>
    <div id="fuUploadProgress" style="display:none;margin-top:10px"><div class="bar"><span id="fuUploadBar" style="width:0%;background:var(--acc)"></span></div><span class="muted" id="fuUploadText" style="font-size:12px;margin-left:6px">准备中…</span></div>
    ` : isActive ? `<p class="muted" style="margin-top:14px">活动已启动，状态：${stateColor(c.state)}。可在大表中查看整体进度。</p>` : ''}
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}

async function fuotaAddDev(campId){
  const v = parseInt(document.getElementById('fu_devid').value||'0', 10);
  if (!v){ toast('请输入设备 ID','warn'); return; }
  const r = await api('POST','/api/fuota/'+campId+'/devices',{ dev_id:v });
  if (r && r.error){ toast(r.error,'err'); return; }
  toast('设备已加入活动','ok');
  fuotaDetail(campId);
}

async function fuotaStart(campId){
  fuotaDetail(campId);
}

async function fuotaUpload(campId){
  const file = document.getElementById('fu_fwfile').files[0];
  if (!file){ toast('请选择固件文件','warn'); return; }
  if (file.size > 5*1024*1024){ toast('固件超过 5MB 上限','err'); return; }
  const ver = (document.getElementById('fu_fwver').value||'').trim();
  const frag = parseInt(document.getElementById('fu_frag').value||'200', 10);
  const prog = document.getElementById('fuUploadProgress');
  const bar  = document.getElementById('fuUploadBar');
  const txt  = document.getElementById('fuUploadText');
  const btn  = document.getElementById('fuUploadBtn');
  prog.style.display = 'block';
  btn.disabled = true; btn.textContent = '上传中…';
  bar.style.width = '5%'; txt.textContent = '读取文件…';
  let b64;
  try {
    b64 = await new Promise((res, rej) => {
      const r = new FileReader();
      r.onload = () => {
        const s = String(r.result||'');
        const comma = s.indexOf(',');
        res(comma >= 0 ? s.slice(comma+1) : s);
      };
      r.onerror = () => rej(r.error || new Error('read failed'));
      r.readAsDataURL(file);
    });
  } catch(e){ toast('读取文件失败：'+(e.message||e),'err'); btn.disabled=false; btn.textContent='上传并启动'; return; }
  bar.style.width = '25%'; txt.textContent = '正在上送 ('+Math.round(file.size/1024)+' KB)…';

  const fragN = Math.max(1, Math.ceil(file.size / Math.max(1, frag)));
  const r = await api('POST','/api/fuota/'+campId+'/start',{
    firmware_base64: b64,
    min_delay: 200,
    max_delay: 1000,
    timeout: 3600,
  });
  if (r && r.error){ toast(r.error,'err'); btn.disabled=false; btn.textContent='上传并启动'; return; }
  bar.style.width = '100%'; txt.textContent = '启动成功，预计推送 '+fragN+' 个分片';
  toast('FUOTA 已启动','ok');
  setTimeout(()=>{ closeModal(); nav('fuota'); }, 1200);
}

let nocClockTimer = null;
function bar(pct, color){
  pct = Math.max(0, Math.min(100, pct|0));
  return `<div class="bar"><span style="width:${pct}%;background:${color||'var(--acc)'}"></span></div>`;
}
function kpiCard(title, total, a, b, icon, isMsg){
  const ic = ICON[icon]||'';
  let sub;
  if (isMsg) sub = `<span class="up">▲ ${a}</span> <span class="down">▼ ${b}</span>`;
  else if (b) sub = `<span class="ok">${a} 在线</span> · <span class="err">${b} 离线</span>`;
  else sub = `<span class="muted">— 无明细 —</span>`;
  return `<div class="kpi">
    <div class="kpi-ic">${ic}</div>
    <div class="kpi-main"><div class="kpi-num">${total}</div><div class="kpi-title">${title}</div></div>
    <div class="kpi-sub">${sub}</div>
  </div>`;
}
function buildHourlyTrend(ups, dls, hours){
  const now = new Date();
  const buckets = [];
  for (let i=hours-1; i>=0; i--){
    const d = new Date(now.getTime() - i*3600*1000);
    buckets.push({ t:new Date(d.getFullYear(),d.getMonth(),d.getDate(),d.getHours()), up:0, dl:0 });
  }
  const idxOf = (ts)=>{
    if (!ts) return -1;
    const d = new Date((ts|0)*1000);
    const hh = new Date(d.getFullYear(),d.getMonth(),d.getDate(),d.getHours()).getTime();
    return buckets.findIndex(b=>b.t.getTime()===hh);
  };
  (ups||[]).forEach(u=>{ const i=idxOf(u.received_at); if(i>=0) buckets[i].up++; });
  (dls||[]).forEach(x=>{ const i=idxOf(x.sent_at||x.created_at); if(i>=0) buckets[i].dl++; });
  const max = Math.max(1, ...buckets.map(b=>b.up+b.dl));
  const W=800,H=160,padB=22,padT=10,n=buckets.length,bw=W/n;
  const colW = Math.max(2, Math.min(13, (bw-3)/2));
  let bars='';
  buckets.forEach((b,i)=>{
    const x = i*bw + bw/2;
    const hu = (b.up/max)*(H-padB-padT);
    const hd = (b.dl/max)*(H-padB-padT);
    const baseY = H-padB;
    bars += `<rect x="${(x-colW-1).toFixed(1)}" y="${(baseY-hu).toFixed(1)}" width="${colW}" height="${hu.toFixed(1)}" fill="var(--acc)" rx="1"></rect>`;
    bars += `<rect x="${(x+1).toFixed(1)}" y="${(baseY-hd).toFixed(1)}" width="${colW}" height="${hd.toFixed(1)}" fill="var(--ok)" rx="1"></rect>`;
    if (n<=12 || i%2===0) bars += `<text x="${x.toFixed(1)}" y="${H-6}" text-anchor="middle" fill="var(--mut)" font-size="9">${String(b.t.getHours()).padStart(2,'0')}</text>`;
  });
  return `<svg viewBox="0 0 ${W} ${H}" class="trend-svg" preserveAspectRatio="xMidYMid meet">
    <line x1="0" y1="${H-padB}" x2="${W}" y2="${H-padB}" stroke="var(--line)"></line>${bars}
  </svg><div class="trend-legend"><span class="dot acc"></span>上行 <span class="dot ok"></span>下行</div>`;
}
function startNocClock(){
  if (nocClockTimer) clearInterval(nocClockTimer);
  const el = document.getElementById('nocClock');
  if (!el) return;
  const upd = ()=>{ el.textContent = new Date().toLocaleString(); };
  upd(); nocClockTimer = setInterval(upd, 1000);
}
async function viewNoc(){
  const s = await api('GET','/api/stats'); state.stats = s;
  const devTotal=s.devices|0, devOn=s.devices_online|0, devOff=s.devices_offline|0;
  const gwTotal=s.gateways|0, gwOn=s.gateways_online|0, gwOff=s.gateways_offline|0;
  const ups=s.uplinks|0, dls=s.downlinks|0;
  let apps=[], devs=[];
  try { const ra = await api('GET','/api/applications'); apps = ra.data||[]; } catch(e){}
  try { const rd = await api('GET','/api/devices'); devs = rd.data||[]; } catch(e){}
  const byApp = {};
  apps.forEach(a => byApp[a.id] = { name:a.name, total:0, online:0 });
  devs.forEach(d => { const b=byApp[d.app_id]; if (b){ b.total++; if (d.online==='online') b.online++; } });
  let upsRows=[], dlRows=[];
  try { const ru = await api('GET','/api/uplinks?limit=800'); upsRows = ru.data||[]; } catch(e){}
  try { const rd = await api('GET','/api/downlinks?limit=800'); dlRows = rd.data||[]; } catch(e){}
  const trend = buildHourlyTrend(upsRows, dlRows, 24);

  document.getElementById('view').innerHTML = `
    <div class="view-head"><h2>${ICON[VIEW_ICONS['noc']]||''}运维仪表盘</h2><div class="noc-clock" id="nocClock"></div></div>
    <div class="kpi-grid">
      ${kpiCard('设备', devTotal, devOn, devOff, 'cpuChip')}
      ${kpiCard('网关', gwTotal, gwOn, gwOff, 'radio')}
      ${kpiCard('应用', s.applications|0, 0, 0, 'squares2x2')}
      ${kpiCard('消息', ups+dls, ups, dls, 'signal', true)}
    </div>
    <div class="noc-grid">
      <div class="panel" style="grid-column:1/-1"><div class="panel-h">近 24 小时吞吐</div><div class="trend noc-trend">${trend}</div></div>
    </div>
    ${apps.length ? `<div class="panel"><div class="panel-h">应用设备分布</div>
      <table class="tbl"><thead><tr><th>应用</th><th>设备数</th><th>在线</th><th>在线率</th><th style="width:160px">状态条</th></tr></thead><tbody>
      ${apps.map(a=>{ const b=byApp[a.id]||{total:0,online:0}; const r=b.total?Math.round(b.online/b.total*100):0;
        return `<tr><td>${esc(a.name)}</td><td>${b.total}</td><td>${b.online}</td><td>${r}%</td><td>${bar(r, r>=80?'var(--ok)':(r>=50?'var(--warn)':'var(--err)'))}</td></tr>`; }).join('')}
      </tbody></table></div>` : ''}
  `;
  startNocClock();
}

window.MAP_PROVIDERS = [
  { id:'',    name:'（无，使用内置简图）', url:'', needKey:0 },
  { id:'custom', name:'自定义瓦片 URL',  url:null, needKey:0 },
  { id:'osm',     name:'OpenStreetMap',   needKey:0, sub:'abc',  maxZ:19, url:'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png' },
  { id:'carto-l',  name:'CARTO Positron', needKey:0, sub:'abcd', maxZ:20, url:'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png' },
  { id:'carto-d',  name:'CARTO Dark Matter', needKey:0, sub:'abcd', maxZ:20, url:'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png' },
  { id:'opentopo', name:'OpenTopoMap',    needKey:0, sub:'abc',  maxZ:17, url:'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png' },
  { id:'gaode',    name:'高德（国内·GCJ-02）', needKey:0, sub:'1234', maxZ:19, url:'https://webrd0{s}.is.autonavi.com/appmaptile?style=8&x={x}&y={y}&z={z}' },
  { id:'tencent',  name:'腾讯（国内·GCJ-02）', needKey:0, sub:'0123', maxZ:19, url:'https://rt{s}.map.gtimg.com/tile?z={z}&x={x}&y={y}&styleid=3' },
  { id:'tianditu-sdk', name:'天地图-官方JS API（免费·WGS84·可切图层）', needKey:1, sdk:'tianditu' },
  { id:'tianditu', name:'天地图-矢量瓦片（Leaflet·免费·WGS84）', needKey:1, sub:'01234567', maxZ:18, url:'https://t{s}.tianditu.gov.cn/DataServer?T=vec_w&x={x}&y={y}&l={z}&tk=KEY' },
  { id:'tianditu-sat', name:'天地图-影像（国内·免费·WGS84）', needKey:1, sub:'01234567', maxZ:18, url:'https://t{s}.tianditu.gov.cn/DataServer?T=img_w&x={x}&y={y}&l={z}&tk=KEY' },
  { id:'tianditu-ter', name:'天地图-地形（国内·免费·WGS84）', needKey:1, sub:'01234567', maxZ:18, url:'https://t{s}.tianditu.gov.cn/DataServer?T=ter_w&x={x}&y={y}&l={z}&tk=KEY' },
  { id:'google',   name:'Google Maps（境外）', needKey:0, maxZ:20, url:'https://mt1.google.com/vt/lyrs=m&x={x}&y={y}&z={z}' },
  { id:'baidu',    name:'百度（国内·BD-09，需 Key）', needKey:1, maxZ:19, url:'https://api.map.baidu.com/customimglite/tile?x={x}&y={y}&z={z}&scale=1&ak=KEY' },
  { id:'mapbox',   name:'Mapbox（需 Key）', needKey:1, maxZ:20, url:'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/{z}/{x}/{y}?access_token=KEY' },
  { id:'maptiler', name:'MapTiler（需 Key）', needKey:1, maxZ:20, url:'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key=KEY' },
];
function getMapSettings(){ return fetch('/api/public-settings').then(r=>r.json()).then(j=>j.data||{}).catch(()=>({})); }
function injectLeaflet(){
  return new Promise((res)=>{
    if (window.L && L.map && L.tileLayer){ res(); return; }
    const lk = document.createElement('link');
    lk.rel = 'stylesheet'; lk.href = 'https://cdn.bootcdn.net/ajax/libs/leaflet/1.9.4/leaflet.css'; document.head.appendChild(lk);
    const sb = document.createElement('script');
    sb.src = 'https://cdn.bootcdn.net/ajax/libs/leaflet/1.9.4/leaflet.js';
    sb.onload = () => res(); sb.onerror = () => res();
    document.head.appendChild(sb);
  });
}
function escHtml(s){ return String(s==null?'':s).replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
async function renderLeafletMap(devs, gws, prov, mapKey){
  await injectLeaflet();
  const canvas = document.getElementById('mapCanvas');
  if (!canvas || !window.L) throw new Error('Leaflet 加载失败');
  if (window.__leafletMap){ try{ window.__leafletMap.remove(); }catch(e){} window.__leafletMap = null; }
  canvas.innerHTML = '';
  canvas.style.height = '500px'; canvas.style.background = '#dfe3ea';
  const url = String(prov.url||'').replace('KEY', mapKey||'');
  const map = L.map(canvas, { zoomControl:true }).setView([30, 105], 3);
  window.__leafletMap = map;
  L.tileLayer(url, { maxZoom: prov.maxZ||19, attribution: prov.name, subdomains: prov.sub?prov.sub.split(''):undefined }).addTo(map);

  if (prov.id && String(prov.id).indexOf('tianditu')===0){
    L.tileLayer('https://t{s}.tianditu.gov.cn/DataServer?T=cva_w&x={x}&y={y}&l={z}&tk='+encodeURIComponent(mapKey||''),
      { maxZoom: prov.maxZ||18, attribution:'天地图注记', subdomains:'01234567' }).addTo(map);
  }
  const pts = [];
  (gws||[]).forEach(g=>{ if (!hasCoord(g)) return;
    L.circleMarker([+g.latitude, +g.longitude], { radius:6, color:'var(--acc)', fillColor:'var(--acc)', fillOpacity:.85 })
      .addTo(map).bindPopup('<b>'+escHtml(g.name||g.gw_id||'')+'</b><br>网关'); pts.push([+g.latitude,+g.longitude]);
  });
  (devs||[]).forEach(d=>{ if (!hasCoord(d)) return;
    const on = d.online==='online'; const c = on ? '#36d399' : '#f87272';
    L.circleMarker([+d.latitude, +d.longitude], { radius:7, color:c, fillColor:c, fillOpacity:.85 })
      .addTo(map).bindPopup('<b>'+escHtml(d.name||'')+'</b><br>'+(on?'在线':'离线')+' · '+(d.last_seen_fmt||''));
    pts.push([+d.latitude,+d.longitude]);
  });
  if (pts.length){ map.fitBounds(pts, { padding:[24,24] }); } else { map.setView([30,105], 3); }
  const side = document.getElementById('mapSide');
  if (side) side.innerHTML = `<div class="map-sum"><div><b>${devs.length}</b> 设备（<b>${(devs||[]).filter(hasCoord).length}</b> 有坐标）</div><div><b>${gws.length}</b> 网关（<b>${(gws||[]).filter(hasCoord).length}</b> 有坐标）</div><div class="muted">提供商：${escHtml(prov.name)}｜点击标记查看信息</div></div>`;
}

function tdtDot(color){
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18">'
    + '<circle cx="9" cy="9" r="6.5" fill="'+color+'" stroke="#ffffff" stroke-width="2.5"/></svg>';
  return new T.Icon({
    iconUrl: 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg),
    iconSize: new T.Point(18, 18),
    iconAnchor: new T.Point(9, 9)
  });
}
function injectTiandituSdk(tk){
  return new Promise((res) => {
    if (!tk){ res(); return; }
    if (window.T && T.Map && window.__tdtTk === tk){ res(); return; }

    try { if (window.__tdtMap && window.__tdtMap.destroy) window.__tdtMap.destroy(); } catch(e){}
    window.__tdtMap = null; window.T = undefined;
    document.querySelectorAll('script[data-tdt-sdk]').forEach(s => { try{ s.remove(); }catch(e){} });
    const sb = document.createElement('script');
    sb.setAttribute('data-tdt-sdk', '1');
    sb.src = 'https://api.tianditu.gov.cn/api?v=4.0&tk=' + encodeURIComponent(tk);
    sb.onload = () => { window.__tdtTk = tk; setTimeout(res, 60); };
    sb.onerror = () => { window.__tdtTk = null; res(); };
    document.head.appendChild(sb);
    setTimeout(res, 12000);
  });
}
async function renderTiandituMap(devs, gws, tk){
  await injectTiandituSdk(tk);
  const canvas = document.getElementById('mapCanvas');
  if (!canvas || !window.T || !T.Map) throw new Error('天地图 JS API 加载失败（请检查 API Key 是否有效）');
  canvas.innerHTML = '';
  canvas.style.height = '500px';
  canvas.style.background = '#dfe3ea';
  canvas.style.position = 'relative';
  const map = new T.Map(canvas);
  window.__tdtMap = map;

  const pts = [];
  const bind = (ll, html, color) => {
    const m = new T.Marker(ll, { icon: tdtDot(color) });
    map.addOverLay(m);
    m.addEventListener('click', () => {
      try { map.openInfoWindow(new T.InfoWindow(html, { offset: new T.Point(0, -10) }), ll); } catch(e){}
    });
  };
  (gws || []).forEach(g => {
    if (!hasCoord(g)) return;
    const ll = new T.LngLat(+g.longitude, +g.latitude); pts.push(ll);
    bind(ll, '<b>' + escHtml(g.name || g.gw_id || '') + '</b><br>网关', '#58A6FF');
  });
  (devs || []).forEach(d => {
    if (!hasCoord(d)) return;
    const ll = new T.LngLat(+d.longitude, +d.latitude); pts.push(ll);
    const on = d.online === 'online';
    bind(ll, '<b>' + escHtml(d.name || '') + '</b><br>' + (on ? '在线' : '离线') + ' · ' + (d.last_seen_fmt || ''),
         on ? '#36d399' : '#f87272');
  });

  if (pts.length){
    let minLa = 90, maxLa = -90, minLo = 180, maxLo = -180;
    pts.forEach(p => { minLa = Math.min(minLa, p.lat); maxLa = Math.max(maxLa, p.lat);
                       minLo = Math.min(minLo, p.lng); maxLo = Math.max(maxLo, p.lng); });
    try { map.setViewport(new T.LngLatBounds(new T.LngLat(minLo, minLa), new T.LngLat(maxLo, maxLa))); }
    catch(e){ try { map.centerAndZoom(pts[0], 13); } catch(e2){} }
  } else {
    map.centerAndZoom(new T.LngLat(105, 30), 3);
  }

  const box = document.createElement('div');
  box.className = 'tdt-layer-box';
  box.innerHTML = '<button data-t="TMAP_NORMAL_MAP" class="on">矢量</button>'
    + '<button data-t="TMAP_SATELLITE_MAP">影像</button>'
    + '<button data-t="TMAP_HYBRID_MAP">影像+注记</button>'
    + '<button data-t="TMAP_TERRAIN_MAP">地形</button>';
  box.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-t]'); if (!b) return;
    const k = b.getAttribute('data-t');
    if (typeof window[k] !== 'undefined'){ try { map.setMapType(window[k]); } catch(err){} }
    box.querySelectorAll('button').forEach(x => x.classList.remove('on'));
    b.classList.add('on');
  });
  canvas.appendChild(box);

  const side = document.getElementById('mapSide');
  if (side) side.innerHTML = `<div class="map-sum"><div><b>${devs.length}</b> 设备（<b>${(devs||[]).filter(hasCoord).length}</b> 有坐标）</div><div><b>${gws.length}</b> 网关（<b>${(gws||[]).filter(hasCoord).length}</b> 有坐标）</div><div class="muted">提供商：天地图官方 JS API（WGS84，无偏移）｜点击标记查看信息</div></div>`;
}
async function viewMap(){
  document.getElementById('view').innerHTML = `
    <div class="view-head"><h2>${ICON[VIEW_ICONS['map']]||''}位置地图</h2>
      <div class="map-legend"><span class="dot ok"></span>在线 <span class="dot err"></span>离线 <span class="dot acc"></span>网关</div>
    </div>
    <div class="map-wrap">
      <div class="map-canvas" id="mapCanvas"></div>
      <div class="map-side" id="mapSide"><div class="muted">点击地图上的标记查看设备详情</div></div>
    </div>`;
  let devs=[], gws=[];
  try { const rd = await api('GET','/api/devices'); devs = rd.data||[]; } catch(e){}
  try { const rg = await api('GET','/api/gateways'); gws = rg.data||[]; } catch(e){}
  state.mapDevs = devs; state.mapGws = gws;
  const set = await getMapSettings();
  const prov = (window.MAP_PROVIDERS||[]).find(p=>p.id===set.map_provider)
            || (window.MAP_PROVIDERS||[]).find(p=>p.id==='gaode');

  const tileUrl = (prov && prov.id==='custom') ? (set.map_url||'') : (prov && prov.url || '');
  const name = (prov && prov.id==='custom') ? '自定义瓦片 URL' : (prov ? prov.name : '');

  if (prov && prov.id && String(prov.id).indexOf('tianditu')===0){
    if (!set.map_key){ toast('天地图需在「设置→地图服务」填写 API Key','warn'); renderMap(devs, gws); }
    else { try { await renderTiandituMap(devs, gws, set.map_key); }
           catch(e){ console.error(e); toast('天地图加载失败：'+(e.message||e),'err'); renderMap(devs, gws); } }
  } else if (prov && tileUrl){
    try { await renderLeafletMap(devs, gws, {name, url:tileUrl, needKey:0, maxZ:prov.maxZ||19, id:prov.id}, set.map_key||''); }
    catch(e){ toast('地图加载失败，已回退内置简图','err'); renderMap(devs, gws); }
  } else if (prov && prov.id==='custom'){
    toast('已选择自定义瓦片 URL，但未填写地图 URL，改用内置简图','warn'); renderMap(devs, gws);
  } else {
    renderMap(devs, gws);
  }
}
function hasCoord(o){
  const la=parseFloat(o.latitude), lo=parseFloat(o.longitude);
  return isFinite(la) && isFinite(lo) && !(la===0 && lo===0);
}
function renderMap(devs, gws){
  const canvas = document.getElementById('mapCanvas');
  if (!canvas) return;
  const W=720,H=360,pad=12;
  const lon2x = lon => pad + (lon+180)/360*(W-2*pad);
  const lat2y = lat => pad + (90-lat)/180*(H-2*pad);
  let grid='';
  for (let lon=-180; lon<=180; lon+=30){ const x=lon2x(lon); grid+=`<line x1="${x.toFixed(1)}" y1="${pad}" x2="${x.toFixed(1)}" y2="${H-pad}" stroke="var(--line)"></line>`; }
  for (let lat=-90; lat<=90; lat+=30){ const y=lat2y(lat); grid+=`<line x1="${pad}" y1="${y.toFixed(1)}" x2="${W-pad}" y2="${y.toFixed(1)}" stroke="var(--line)"></line>`; }
  let markers='';
  const placed=[];
  (gws||[]).forEach(g=>{
    if (!hasCoord(g)) return;
    const x=lon2x(parseFloat(g.longitude)), y=lat2y(parseFloat(g.latitude));
    markers += `<g class="mk" transform="translate(${x.toFixed(1)},${y.toFixed(1)})">
      <circle r="8" fill="var(--acc)" opacity="0.22"></circle>
      <path d="M0,-7 L5.5,4 L0,1.2 L-5.5,4 Z" fill="var(--acc)" stroke="var(--bg)" stroke-width="0.6"></path>
      <title>${esc(g.name||g.gw_id||'')}（网关）</title></g>`;
  });
  (devs||[]).forEach(d=>{
    if (!hasCoord(d)) return;
    let x=lon2x(parseFloat(d.longitude)), y=lat2y(parseFloat(d.latitude));
    for (let t=0;t<8;t++){ const jx=x+(Math.random()*9-4.5), jy=y+(Math.random()*9-4.5);
      if (placed.every(p=>Math.hypot(p.x-jx,p.y-jy)>7)){ x=jx; y=jy; break; } }
    placed.push({x,y});
    const on = d.online==='online';
    const c = on?'var(--ok)':'var(--err)';
    markers += `<g class="mk" data-id="${d.id}" transform="translate(${x.toFixed(1)},${y.toFixed(1)})" onclick="mapShowDev(${d.id})" style="cursor:pointer">
      <circle r="6.5" fill="${c}" opacity="0.22"></circle>
      <circle r="3.2" fill="${c}" stroke="var(--bg)" stroke-width="0.6"></circle>
      <title>${esc(d.name||'')}（${on?'在线':'离线'}）</title></g>`;
  });
  canvas.innerHTML = `<svg viewBox="0 0 ${W} ${H}" class="map-svg" preserveAspectRatio="xMidYMid meet">
    <rect x="0" y="0" width="${W}" height="${H}" fill="var(--bg-subtle)"></rect>
    ${grid}${markers}</svg>`;
  const withCoord = (devs||[]).filter(hasCoord).length;
  const side = document.getElementById('mapSide');
  let html = `<div class="map-sum">
      <div><b>${devs.length}</b> 设备（<b>${withCoord}</b> 有坐标）</div>
      <div><b>${gws.length}</b> 网关（<b>${(gws||[]).filter(hasCoord).length}</b> 有坐标）</div>
    </div>`;
  if (!withCoord) html += `<div class="warn-box">当前设备未填写经纬度，地图上无标记。可在设备编辑中设置 latitude / longitude 后在地图上显示。</div>`;
  side.innerHTML = html;
}
function mapShowDev(id){
  const d = (state.mapDevs||[]).find(x=>x.id===id); if (!d) return;
  const side = document.getElementById('mapSide');
  const la=parseFloat(d.latitude), lo=parseFloat(d.longitude);
  const coordTxt = (hasCoord(d)) ? `${la.toFixed(4)}, ${lo.toFixed(4)}` : '—';
  side.innerHTML = `<div class="dev-card">
    <div class="dev-card-h">${esc(d.name||'')}</div>
    <div class="muted">#${d.id} · ${esc(d.dev_eui||'')}</div>
    <div class="dev-rows">
      <div><span>状态</span><b class="${d.online==='online'?'ok':'err'}">${d.online==='online'?'在线':'离线'}</b></div>
      <div><span>Class</span><b>${esc(d.cls||d.class||'A')}</b></div>
      <div><span>经纬度</span><b>${coordTxt}</b></div>
      <div><span>最后上行</span><b>${esc(d.last_seen_fmt||'-')}</b></div>
    </div>
    <div style="margin-top:12px"><button class="btn ghost" onclick="nav('devices')">${ICON.cpuChip}设备列表</button></div>
  </div>`;
}

function b2h(arr, sep){ return (arr||[]).map(x=>('0'+((x&0xff)>>>0).toString(16)).slice(-2)).join(sep||''); }
function rev(arr){ return (arr||[]).slice().reverse(); }

function parseLoraFrame(hexPlain){
  const b = hexToBytes(hexPlain);
  const out = {rows:[]};
  if (!b.length){ out.error=t('空帧（无 phy_payload）'); return out; }
  const mhdr=b[0];
  const mtype=(mhdr>>5)&0x07, major=mhdr&0x03;
  const MT={0:'Join-request',1:'Join-accept',2:'Unconfirmed Data Up',3:'Unconfirmed Data Down',4:'Confirmed Data Up',5:'Confirmed Data Down',6:'RFU(6)',7:'Proprietary'};
  out.rows.push({k:'MHDR', v:b2h([mhdr]), d:`MType=${mtype} → ${MT[mtype]||'?'}; Major=${major}${major===0?' (LoRaWAN R1)':''}`});
  if (mtype===0){
    if (b.length<23){ out.error=t('Join-request 长度不足（{n} 字节，需 23）').replace('{n}', b.length); return out; }
    out.rows.push({k:'AppEUI', v:b2h(b.slice(1,9)), d:t('空口小端，网络序')+' '+b2h(rev(b.slice(1,9)))});
    out.rows.push({k:'DevEUI', v:b2h(b.slice(9,17)), d:t('空口小端，网络序')+' '+b2h(rev(b.slice(9,17)))});
    out.rows.push({k:'DevNonce', v:b2h(b.slice(17,19))});
    out.rows.push({k:'MIC', v:b2h(b.slice(19,23))});
    out.note=t('MIC 需 AppKey 验证，由 NS 完成；此处仅展示原始字节。');
    return out;
  }
  if (mtype===1){
    if (b.length<17){ out.error=t('Join-accept 长度不足'); return out; }
    out.rows.push({k:t('(密文主体)'), v:b2h(b.slice(1,b.length-4)), d:t('Join-accept 在空口为 AES 加密，需 AppKey 解密后才能解析 AppNonce/NetID/DevAddr/DLSettings/RxDelay/CFList')});
    out.rows.push({k:'MIC', v:b2h(b.slice(b.length-4)), d:t('末 4 字节（密文内）')});
    out.note=t('Join-accept 为加密帧，解密由 NS 完成。');
    return out;
  }
  if (mtype>=2 && mtype<=5){
    if (b.length<8){ out.error=t('数据帧长度不足'); return out; }
    const up = (mtype===2||mtype===4);
    const devAddr=b.slice(1,5);
    out.rows.push({k:'DevAddr', v:b2h(devAddr), d:t('空口小端，网络序')+' '+b2h(rev(devAddr))});
    const fctrl=b[5];
    const foptsLen = fctrl&0x0f;
    const flags=[];
    if (fctrl&0x80) flags.push('ADR');
    flags.push(up ? ((fctrl&0x40)?'ADRACKReq':'') : ((fctrl&0x40)?'FPending':''));
    if (fctrl&0x20) flags.push('ACK');
    if (fctrl&0x10) flags.push('ClassB(FCtrl.b4)');
    out.rows.push({k:'FCtrl', v:b2h([fctrl]), d:`${(flags.filter(Boolean).map(f=>t(f)||f).join(' / ')||t('无标志'))} · FOptsLen=${foptsLen}`});
    const fcnt = b[6] | (b[7]<<8);
    out.rows.push({k:t('FCnt (低16位)'), v:String(fcnt), d:t('完整 FCnt 由 NS 按设备会话上下文补全')});
    let p=8;
    if (foptsLen>0){
      const fopts=b.slice(p,p+foptsLen);
      out.rows.push({k:'FOpts', v:b2h(fopts), d: foptsLen===15?t('MAC 命令占满，无 FPort/FRMPayload'):t('MAC 命令（{n} 字节）').replace('{n}', foptsLen)});
      p+=foptsLen;
    }
    const remain=b.length-p;
    if (remain>4){
      const fport=b[p]; p++;
      out.rows.push({k:'FPort', v:String(fport), d: fport===0?t('MAC 层（FRMPayload 为 MAC 命令）'):t('应用层')});
      const payload=b.slice(p, b.length-4);
      out.rows.push({k:'FRMPayload', v:b2h(payload)||t('(空)'), d:t('应用负载（若已配置会话密钥，NS 已解密后存储为 decrypted_hex）')});
      out.rows.push({k:'MIC', v:b2h(b.slice(b.length-4))});
      out.note=t('MIC 需 NwkSKey 验证，NS 侧已校验；FRMPayload 解密需 AppSKey/NwkSKey。');
    } else if (remain===4){
      out.rows.push({k:'MIC', v:b2h(b.slice(p,p+4)), d:t('无 FPort/FRMPayload（纯 MAC/确认帧）')});
    } else {
      out.error=t('帧尾部长度异常（剩余 {n} 字节，应 ≥4 用于 MIC）').replace('{n}', remain);
    }
    return out;
  }
  out.rows.push({k:'Payload', v:b2h(b.slice(1)), d:t('专有/RFU 帧，按透传处理')});
  return out;
}

async function frameInspector(id){

  let rec = (state.ups||[]).find(x=>x.id===id) || (state.dls||[]).find(x=>x.id===id);
  let kind = rec ? ((state.ups||[]).includes(rec) ? 'uplink' : 'downlink') : '';

  if (!rec){
    try {
      const up = await api('GET', '/api/uplinks/' + id);
      const u1 = up && !up.error ? (up.data || up.uplink) : null;
      if (u1) { rec = u1; kind = 'uplink'; }
    } catch(e){}
    if (!rec){
      try {
        const dl = await api('GET', '/api/downlinks/' + id);
        const d1 = dl && !dl.error ? (dl.data || dl.downlink) : null;
        if (d1) { rec = d1; kind = 'downlink'; }
      } catch(e){}
    }
  }
  if (!rec){
    openModal(`<h3>${t('帧结构检视')} #${id}</h3>
      <p class="muted">${t('未找到该记录（可能已被清理或不在当前租户可见范围）。')}</p>
      <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">${t('关闭')}</button></div>`);
    return;
  }
  let phy = rec.phy_payload || '';
  if (!phy && rec.raw_json){ try{ const j=JSON.parse(rec.raw_json); phy = j.phy_payload || (j.txpk&&j.txpk.data) || ''; }catch(e){} }
  if (!phy){
    openModal(`<h3>${t('帧结构检视')} #${id}（${t(kind||'记录')}）</h3>
      <p class="muted">${t('该记录没有原始帧（phy_payload）可供解析。上行记录通常包含空口帧；若为空，可能是 NS 未记录原始帧。')}</p>
      <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">${t('关闭')}</button></div>`);
    return;
  }
  const parsed = parseLoraFrame(phy);
  const rowsHtml = parsed.error
    ? `<tr><td colspan="3" class="warn-box" style="border:0">${esc(parsed.error)}</td></tr>`
    : parsed.rows.map(r=>`<tr><td class="mono">${esc(r.k)}</td><td class="mono">${esc(r.v)}</td><td class="muted">${esc(r.d||'')}</td></tr>`).join('');
  const noteHtml = parsed.note ? `<p class="muted" style="margin-top:10px">${esc(parsed.note)}</p>` : '';
  openModal(`<h3>${t('帧结构检视')} #${id}（${t(kind||'记录')}）</h3>
    <p class="muted" style="word-break:break-all">${t('完整帧 (hex)：')}<code>${esc(phy)}</code></p>
    <div style="position:relative"><button class="ad-copy" onclick="copyText('${phy}')">${t('复制帧')}</button></div>
    <table class="tbl" style="margin-top:8px"><thead><tr><th>${t('字段')}</th><th>${t('值 (hex)')}</th><th>${t('说明')}</th></tr></thead><tbody>${rowsHtml}</tbody></table>
    ${noteHtml}
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">${t('关闭')}</button></div>`);
}

function downloadBlob(name, text, mime){
  const blob = new Blob([text], {type: mime||'text/plain'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href=url; a.download=name; document.body.appendChild(a); a.click();
  setTimeout(()=>{ try{ URL.revokeObjectURL(url); a.remove(); }catch(e){} }, 120);
}
async function exportCapture(kind, fmt){
  const map = {uplinks: state.ups, downlinks: state.dls, events: state.evs};
  const data = map[kind] || [];
  if (!data.length){ toast('当前没有可导出的记录','info'); return; }
  const stamp = new Date().toISOString().slice(0,19).replace(/[:T]/g,'-');
  if (fmt==='csv'){
    const flat = data.map(r=>{ const o={}; for (const k of Object.keys(r)){ if (k==='raw_json') continue; let v=r[k]; if (v&&typeof v==='object') v=JSON.stringify(v); o[k]=v; } return o; });
    const cols = Array.from(new Set(flat.flatMap(o=>Object.keys(o))));
    const escC = v => { v=v==null?'':String(v); return /[",\n\r]/.test(v)? '"'+v.replace(/"/g,'""')+'"' : v; };
    const lines = [cols.join(',')].concat(flat.map(o=>cols.map(c=>escC(o[c])).join(',')));
    downloadBlob(`capture_${kind}_${stamp}.csv`, '﻿'+lines.join('\n'), 'text/csv;charset=utf-8');
  } else {
    downloadBlob(`capture_${kind}_${stamp}.json`, JSON.stringify(data, null, 2), 'application/json');
  }
  toast(`已导出 ${data.length} 条 ${kind}（${fmt.toUpperCase()}）`, 'ok');
}

const DECODER_TEMPLATES = [
  { name:'HEX 透传（原样输出）', desc:'把负载 hex 当作字符串返回，便于调试与抓包对照。',
    code:`// function(hex, bytes) -> 字段数组
return [{ type:'raw', value: hex }];` },
  { name:'温度 + 湿度（2 字节大端，各 ÷100）', desc:'常见于温湿度传感器：前 2 字节温度、后 2 字节湿度。',
    code:`// function(hex, bytes) -> 字段数组
if (!bytes || bytes.length < 4) return [];
const be = (i)=> (bytes[i]<<8) | bytes[i+1];
return [
  { type:'temperature', value: +((be(0)/100).toFixed(2)) },
  { type:'humidity',    value: +((be(2)/100).toFixed(2)) },
];` },
  { name:'单通道模拟量（uint16 大端）', desc:'单个 2 字节大端无符号整数。',
    code:`// function(hex, bytes) -> 字段数组
if (!bytes || bytes.length < 2) return [];
const v = (bytes[0]<<8) | bytes[1];
return [{ type:'value', value: v }];` },
  { name:'带符号 int16 大端', desc:'2 字节大端有符号整数（如 ±温度）。',
    code:`// function(hex, bytes) -> 字段数组
if (!bytes || bytes.length < 2) return [];
let v = (bytes[0]<<8) | bytes[1];
if (v & 0x8000) v -= 0x10000;
return [{ type:'value', value: v }];` },
  { name:'JSON-in-HEX（UTF-8 文本）', desc:'负载是 UTF-8 编码的 JSON 字符串，直接解析为字段。',
    code:`// function(hex, bytes) -> 字段数组
try {
  const txt = new TextDecoder('utf-8').decode(Uint8Array.from(bytes||[]));
  const o = JSON.parse(txt);
  return Object.entries(o).map(([k,v])=>({ type:k, value:v }));
} catch(e){ return [{ type:'parse_error', value:String(e.message||e) }]; }` },
  { name:'Cayenne LPP（内置，无需脚本）', desc:'在「解码方式」下拉选 Cayenne LPP 即可；此处给出等价调用。',
    code:`// 提示：在「解码方式」选 Cayenne LPP 即可，无需自定义脚本。
// 若强制用 JS，可调用内置解码器：
return decodeCayenneLpp(hex);` },
];

async function decoderTemplates(){
  const cards = DECODER_TEMPLATES.map((t,i)=>`
    <div class="tpl-card">
      <div class="tpl-head">${esc(t.name)}</div>
      <div class="muted" style="font-size:12px;margin:4px 0 8px">${esc(t.desc)}</div>
      <pre class="tpl-code">${esc(t.code)}</pre>
      <div style="margin-top:8px;display:flex;gap:8px;justify-content:flex-end">
        <button class="ghost" onclick="copyTemplate(${i})">复制</button>
        <button class="btn" onclick="useDecoderTemplate(${i})">使用此模板</button>
      </div>
    </div>`).join('');
  openModal(`<h3>解码器模板</h3>
    <p class="muted">选择一个模板插入到「自定义 JS」解码函数。插入后请将解码方式切到 JS 并点「保存解码配置」。</p>
    <div class="tpl-grid">${cards}</div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}
async function copyTemplate(i){ const t=DECODER_TEMPLATES[i]; if(t) copyText(t.code); }
async function useDecoderTemplate(i){
  const t = DECODER_TEMPLATES[i]; if(!t) return;
  const sel = document.getElementById('codec_rt');
  const ta = document.getElementById('codec_script');
  if (!ta){ toast('请先在设备详情中打开「数据解码」面板','err'); return; }
  if (sel) sel.value='JS';
  if (typeof codecRuntimeChanged==='function') codecRuntimeChanged();
  ta.value = t.code;
  toast('已填入模板，记得点「保存解码配置」','ok');
  closeModal();
}

let __tm = { id:0, fields:[] };

async function viewThingModels(){
  const view = document.getElementById('view');
  const apps = (await api('GET','/api/applications')).data || [];
  view.innerHTML = `
    <div class="view-head"><h2>${ICON.codeBracket||''}${t('物模型')}</h2></div>
    <div class="card" style="padding:16px;max-width:1000px;margin-top:4px">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
        <label style="margin:0">${t('应用')}</label>
        <select id="tm_app" onchange="tmLoad()" style="max-width:340px;flex:1">
          <option value="0">${t('请选择应用')}</option>
          ${apps.map(a=>`<option value="${a.id}">${esc(a.name)}</option>`).join('')}
        </select>
        <button class="btn" onclick="tmNew(${esc(JSON.stringify(apps))})">${ICON.plus}${t('新建物模型')}</button>
      </div>
      <div id="tm_list">${t('请先选择应用以查看或配置其物模型')}</div>
    </div>
    <div class="muted" style="margin-top:14px;max-width:1000px;font-size:12px">${t('物模型说明')}</div>`;
}

function tmFieldsOf(m){
  try{ const a = m.fields || JSON.parse(m.fields_json||'[]'); return Array.isArray(a)?a:[]; }catch(e){ return []; }
}

async function tmLoad(){
  const id = document.getElementById('tm_app').value;
  const host = document.getElementById('tm_list');
  if(!id || id==='0'){ host.innerHTML = '<div class="muted">请先选择应用</div>'; return; }
  host.innerHTML = t('加载中…');
  const list = (await api('GET','/api/thing-models?app_id='+id)).data || [];
  host.innerHTML = list.length ? list.map(m=>{
    const fs = tmFieldsOf(m);
    return `<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;margin-bottom:8px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <b>${esc(m.name)}</b> <span class="muted" style="font-size:12px">· ${esc(m.codec)}</span>
        <div class="muted" style="font-size:12px;margin-top:2px">${fs.slice(0,5).map(f=>esc(f.name||f.key)).join('、')}${fs.length>5?'…':''}</div>
      </div>
      <button class="ghost" onclick="tmEdit(${m.id})">${ICON.pencilSquare}${t('编辑')}</button>
      <button class="btn err" onclick="tmDel(${m.id})">${ICON.trash}${t('删除')}</button>
    </div>`;
  }).join('') : '<div class="muted">暂无物模型，点击右上角「新建物模型」</div>';
}

async function tmNew(apps){
  __tm = { id:0, fields:[] };
  tmOpenModal(apps, 0, 'SEGMENT', '');
}
async function tmEdit(id){
  const r = await api('GET','/api/thing-models/'+id);
  const m = r.thing_model;
  $$ = __tm;
  __tm = { id:+m.id, fields: tmFieldsOf(m) };
  const apps = (await api('GET','/api/applications')).data || [];
  tmOpenModal(apps, +m.application_id, m.codec, m.name);
}
function tmOpenModal(apps, appId, codec, name){
  openModal(`
    <h3>${__tm.id?t('编辑物模型'):t('新建物模型')}</h3>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <label style="margin:0;min-width:56px">${t('应用')}</label>
        <select id="tmf_app" style="flex:1">
          ${apps.map(a=>`<option value="${a.id}" ${+a.id===+appId?'selected':''}>${esc(a.name)}</option>`).join('')}
        </select>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <label style="margin:0;min-width:56px">${t('名称')}</label>
        <input id="tmf_name" value="${esc(name)}" placeholder="${t('如：温湿度传感器')}" style="flex:1">
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <label style="margin:0;min-width:56px">${t('解码方式')}</label>
        <select id="tmf_codec" onchange="tmRenderFields()">
          <option value="SEGMENT" ${codec==='SEGMENT'?'selected':''}>${t('二进制分段')}</option>
          <option value="JSON" ${codec==='JSON'?'selected':''}>JSON</option>
          <option value="LPP" ${codec==='LPP'?'selected':''}>Cayenne LPP</option>
        </select>
      </div>
      <div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
          <b>${t('字段定义')}</b>
          <button class="btn ghost" onclick="tmAddField()" style="margin-left:auto">${ICON.plus}${t('添加字段')}</button>
        </div>
        <div id="tmf_fields"></div>
      </div>
      <div>
        <label style="font-weight:600">${t('测试解码')}</label>
        <div style="display:flex;gap:8px;margin-top:4px">
          <input id="tmf_hex" placeholder="${t('十六进制数据，如 0167D00168C3')}" style="flex:1" value="">
          <button class="btn ghost" onclick="tmTest()">${t('测试')}</button>
        </div>
        <pre id="tmf_out" class="muted" style="white-space:pre-wrap;background:var(--bg2,#0e1420);border:1px solid var(--line);border-radius:8px;padding:10px;margin-top:8px;max-height:200px;overflow:auto">${t('（输入 hex，点击测试查看解码结果）')}</pre>
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="ghost" onclick="closeModal()">${t('取消')}</button>
      <button class="btn" onclick="tmSave()">${t('保存')}</button>
    </div>`);
  tmRenderFields();
}

function tmAddField(){
  __tm.fields.push({key:'',name:'',type:'number',unit:'',dataType:'uint16',offset:0,endian:'be',scale:'1',add:'0',decimals:'0'});
  tmRenderFields();
  const rows = document.querySelectorAll('#tmf_fields .tmf-row');
  if(rows.length) { const r = rows[rows.length-1].querySelector('input'); if(r) r.focus(); }
}
function tmRenderFields(){
  const box = document.getElementById('tmf_fields');
  if(!box) return;
  const codec = (document.getElementById('tmf_codec')||{}).value || 'SEGMENT';
  box.innerHTML = __tm.fields.map((f,i)=>`
    <div class="tmf-row" data-i="${i}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px;border:1px solid var(--line);border-radius:8px;margin-bottom:6px;background:var(--bg2,#0e1420)">
      <button class="ghost" title="删除" onclick="tmRmField(${i})" style="padding:4px 8px">${ICON.xMark}</button>
      <input class="tmf-k" value="${esc(f.key)}" placeholder="key" title="key（英文/下划线）" style="width:90px">
      <input class="tmf-n" value="${esc(f.name)}" placeholder="${t('字段名')}" style="width:110px">
      <select class="tmf-t">
        ${['number','string','bool','enum'].map(t=>`<option value="${t}" ${f.type===t?'selected':''}>${t}</option>`).join('')}
      </select>
      <input class="tmf-u" value="${esc(f.unit)}" placeholder="${t('单位')}" style="width:60px">
      ${codec==='SEGMENT'? `
        <select class="tmf-dt">
          ${['uint8','int8','uint16','int16','uint32','int32','float32'].map(d=>`<option value="${d}" ${f.dataType===d?'selected':''}>${d}</option>`).join('')}
        </select>
        <input class="tmf-of" type="number" value="${f.offset}" placeholder="offset" title="offset" style="width:70px">
        <select class="tmf-en">${['be','le'].map(e=>`<option value="${e}" ${f.endian===e?'selected':''}>${e}</option>`).join('')}</select>
        <input class="tmf-sc" type="text" value="${esc(f.scale)}" placeholder="scale" title="scale（倍率）" style="width:60px">
        <input class="tmf-ad" type="text" value="${esc(f.add)}" placeholder="add" title="add（偏移）" style="width:60px">
        <input class="tmf-dc" type="text" value="${esc(f.decimals)}" placeholder="小数位" style="width:60px">
      `:''}
      ${codec==='JSON'?'<input class="tmf-jk" value="'+(f.jsonKey||'')+'" placeholder="JSON key（可用点号路径）" style="flex:1">':''}
      ${(codec==='SEGMENT'||codec==='JSON') && f.type==='enum' ? `<input class="tmf-map" value="${esc(f.map?JSON.stringify(f.map):'')}" placeholder='{"0":"正常","1":"告警"}' style="min-width:200px">`:''}
    </div>`).join('') || '<div class="muted">暂无字段，点击「添加字段」</div>';
}
function tmRmField(i){ __tm.fields.splice(i,1); tmRenderFields(); }

function tmCollectFields(){
  const codec = (document.getElementById('tmf_codec')||{}).value || 'SEGMENT';
  const rows = document.querySelectorAll('#tmf_fields .tmf-row');
  const out = [];
  rows.forEach(tr=>{
    const q = c => (tr.querySelector(c)||{}).value || '';
    const f = {
      key: q('.tmf-k').trim().replace(/[^A-Za-z0-9_\-]/g,'_'),
      name: q('.tmf-n') || q('.tmf-k'),
      type: q('.tmf-t') || 'number',
      unit: q('.tmf-u'),
    };
    if(!f.key) return;
    if(codec==='SEGMENT'){
      f.dataType = q('.tmf-dt') || 'uint16';
      f.offset = +q('.tmf-of') || 0;
      f.endian = q('.tmf-en') || 'be';
      f.scale = parseFloat(q('.tmf-sc'))||1;
      f.add = parseFloat(q('.tmf-ad'))||0;
      f.decimals = +(q('.tmf-dc')||0)||0;
    }
    if(codec==='JSON') f.jsonKey = q('.tmf-jk') || f.key;
    const map = tr.querySelector('.tmf-map');
    if(map && map.value.trim()){
      try{ f.map = JSON.parse(map.value.trim()); }catch(e){ toast(t('枚举映射 JSON 格式错误')+'：'+map.value, 'err'); return null; }
    }
    if(f.key) out.push(f);
  });
  if(out.includes(null) !== true){  }
  return out.filter(Boolean);
}

async function tmSave(){
  const appId = +document.getElementById('tmf_app').value;
  const name = document.getElementById('tmf_name').value.trim();
  const codec = document.getElementById('tmf_codec').value;
  if(!appId){ toast(t('请选择应用'),'err'); return; }
  if(!name){ toast(t('请输入物模型名称'),'err'); return; }
  const fields = [];
  document.querySelectorAll('#tmf_fields .tmf-row').forEach(tr=>{
    const q = c => (tr.querySelector(c)||{}).value || '';
    const f = { key:q('.tmf-k').trim(), name:q('.tmf-n')||q('.tmf-k').trim(), type:q('.tmf-t')||'number', unit:q('.tmf-u') };
    if(!f.key) return;
    const codec2 = codec;
    if(codec2==='SEGMENT'){ f.dataType=q('.tmf-dt')||'uint16'; f.offset=+q('.tmf-of')||0; f.endian=q('.tmf-en')||'be'; f.scale=parseFloat(q('.tmf-sc'))||1; f.add=parseFloat(q('.tmf-ad'))||0; f.decimals=+(q('.tmf-dc')||0)||0; }
    if(codec2==='JSON') f.jsonKey = q('.tmf-jk') || f.key;
    const map = tr.querySelector('.tmf-map');
    if(map && map.value.trim()){ try{ f.map = JSON.parse(map.value.trim()); }catch(e){ toast('枚举映射 JSON 格式错误：'+map.value,'err'); return; } }
    fields.push(f);
  });
  const body = { application_id: appId, name, codec, fields_json: fields };
  try{
    const r = __tm.id
      ? await api('PUT','/api/thing-models/'+__tm.id, body)
      : await api('POST','/api/thing-models', body);
    if(r && r.error){ toast(String(r.error),'err'); return; }
    toast(t('已保存'),'ok');
    closeModal();
    tmLoad();
  }catch(e){ toast('保存失败：'+e.message,'err'); }
}

async function tmDel(id){
  const ok = await new Promise(res=>confirmDlg(t('确定删除该物模型？删除后设备将不再解码，历史读数保留。'), res));
  if(!ok) return;
  const r = await api('DELETE','/api/thing-models/'+id);
  if(r && r.error){ toast(String(r.error),'err'); return; }
  toast(t('已删除'),'ok');
  tmLoad();
}

function tmTest(){
  const hex = (document.getElementById('tmf_hex')||{}).value || '';
  const codec = (document.getElementById('tmf_codec')||{}).value || 'SEGMENT';
  const out = document.getElementById('tmf_out');
  if(!hex){ out.textContent = '请输入十六进制数据'; return; }
  try{
    const fields = [];
    document.querySelectorAll('#tmf_fields .tmf-row').forEach(tr=>{
      const q = c => (tr.querySelector(c)||{}).value || '';
      const f = { key:q('.tmf-k').trim(), type:q('.tmf-t')||'number', unit:q('.tmf-u') };
      if(!f.key) return;
      if(codec==='SEGMENT'){ f.dataType=q('.tmf-dt')||'uint16'; f.offset=+q('.tmf-of')||0; f.endian=q('.tmf-en')||'be'; f.scale=parseFloat(q('.tmf-sc'))||1; f.add=parseFloat(q('.tmf-ad'))||0; f.decimals=+(q('.tmf-dc')||0)||0; }
      if(codec==='JSON') f.jsonKey = q('.tmf-jk') || f.key;
      const map = tr.querySelector('.tmf-map');
      if(map && map.value.trim()){ try{ f.map=JSON.parse(map.value.trim()); }catch(e){} }
      fields.push(f);
    });
    const res = codec==='JSON' ? tmDecodeJson(fields, hex) : codec==='LPP' ? { info:'LPP 解码在服务器上行时进行，此处请直接参考 Cayenne LPP 规范。'} : tmDecodeSegment(fields, hex);
    out.textContent = JSON.stringify(res, null, 2);
  }catch(e){ out.textContent = '解码出错：'+e.message; }
}
function tmHexToBin(hex){
  hex = (hex||'').replace(/[^0-9a-fA-F]/g,'');
  if(hex.length%2) hex = '0'+hex;
  return hex.match(/.{2}/g).map(b=>parseInt(b,16));
}
function tmReadNum(bin, off, dt, le){
  const bytes = bin.slice(off, off + ({uint8:1,int8:1,uint16:2,int16:2,uint32:4,int32:4,float32:4}[dt]||2));
  if(bytes.length < bytes.length) { }
  const need = {uint8:1,int8:1,uint16:2,int16:2,uint32:4,int32:4,float32:4}[dt]||2;
  if(bytes.length < need) throw new Error('数据越界 offset='+off+' len='+need);
  if(dt==='uint8') return bytes[0];
  if(dt==='int8'){ const v=bytes[0]; return v>=128?v-256:v; }
  if(dt==='uint16'){ return le? (bytes[0]|(bytes[1]<<8)) : ((bytes[0]<<8)|bytes[1]); }
  if(dt==='int16'){ const v = le? (bytes[0]|(bytes[1]<<8)) : ((bytes[0]<<8)|bytes[1]); return v>=32768?v-65536:v; }
  if(dt==='uint32'){ const v = le? (bytes[0]|(bytes[1]<<8)|(bytes[2]<<16)|(bytes[3]<<24)) : ((bytes[0]<<24)|(bytes[1]<<16)|(bytes[2]<<8)|bytes[3]); return (v>>>0); }
  if(dt==='int32'){ const v = le? (bytes[0]|(bytes[1]<<8)|(bytes[2]<<16)|(bytes[3]<<24)) : ((bytes[0]<<24)|(bytes[1]<<16)|(bytes[2]<<8)|bytes[3]); return v|0; }
  if(dt==='float32'){ const buf=new ArrayBuffer(4); const dv=new DataView(buf); bytes.forEach((b,i)=>dv.setUint8(i,b)); return le? dv.getFloat32(0,true) : dv.getFloat32(0,false); }
  throw new Error('未知类型 '+dt);
}
function tmDecodeSegment(fields, hex){
  const bin = tmHexToBin(hex);
  const out = {};
  fields.forEach(f=>{
    const off = +f.offset||0;
    if(off<0) return;
    if(f.type==='string'){
      const len = f.len|| (bin.length-off);
      out[f.key] = bin.slice(off,off+len).map(b=>String.fromCharCode(b)).join('').replace(/[\x00-\x1F]/g,'');
      return;
    }
    if(f.type==='bool'){
      const v = bin[off];
      out[f.key] = v? f.trueText||('on') : f.falseText||('off');
      return;
    }
    if(f.type==='enum'){
      const u = tmReadNum(bin, off, f.dataType||'uint8', f.endian==='le');
      out[f.key+' ('+u+')'] = (f.map&&f.map[u]!=null)? f.map[u] : String(u);
      return;
    }
    const num = tmReadNum(bin, off, f.dataType||'uint16', f.endian==='le');
    const val = num*(+f.scale||1)+(+f.add||0);
    const dec = (f.decimals!=null)? +f.decimals : (Math.abs(val)%1>0? +f.scale<1? +f.scale<0.1?2:1:0 :0);
    out[f.key] = Number(val.toFixed(dec)) + (f.unit?(' '+f.unit):'');
  });
  return out;
}
function tmDecodeJson(fields, hex){
  const bin = tmHexToBin(hex);
  const str = bin.map(b=>String.fromCharCode(b)).join('');
  let obj;
  try{ obj = JSON.parse(str.trim()); }catch(e){  try{ obj = JSON.parse(str.replace(/^\uFEFF/,'')); }catch(e2){ return {error:'JSON 解析失败：'+str}; } }
  const out = {};
  fields.forEach(f=>{
    const path = (f.jsonKey||f.key).split('.');
    let v = obj;
    for(const k of path){ if(v&&typeof v==='object'&&k in v) v=v[k]; else { v=undefined; break; } }
    if(v===undefined) return;
    if(f.type==='enum' && f.map){ v = f.map[String(v)]!=null? f.map[String(v)] : v; }
    out[f.key] = v + (f.unit && fnNum(v)?(' '+f.unit):'');
  });
  function fnNum(x){ return typeof x==='number'; }
  return out;
}

window.__dd = { dev:0 };

async function viewDashboardData(){
  const view = document.getElementById('view');
  const [apps, devs] = await Promise.all([
    api('GET','/api/applications').then(r=>r.data||[]),
    api('GET','/api/devices').then(r=>r.data||[]),
  ]);
  view.innerHTML = `
    <div class="view-head"><h2>${ICON.chartBar||''}${t('数据看板')}</h2></div>
    <div class="card" style="padding:16px;max-width:1100px;margin-top:4px">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <label style="margin:0">${t('设备')}</label>
        <select id="dd_dev" style="max-width:280px;flex:1" onchange="ddLoadField()">
          <option value="0">${t('请选择设备')}</option>
          ${devs.map(d=>`<option value="${d.id}">${esc(d.name)} (${esc(d.dev_eui||d.dev_addr||'')})</option>`).join('')}
        </select>
        <label style="margin:0">${t('字段')}</label>
        <select id="dd_field" style="max-width:200px" onchange="ddLoad()">
          <option value="">${t('请选择字段')}</option>
        </select>
        <label style="margin:0">${t('范围')}</label>
        <select id="dd_range" style="width:120px" onchange="ddLoad()">
          <option value="3600">${t('近1小时')}</option>
          <option value="86400" selected>${t('近24小时')}</option>
          <option value="604800">${t('近7天')}</option>
          <option value="2592000">${t('近30天')}</option>
        </select>
        <button class="btn ghost" onclick="ddLoad()">${ICON.arrowPath}${t('刷新')}</button>
      </div>
      <div id="dd_chart" style="margin-top:16px">${t('请选择设备和字段查看历史曲线')}</div>
      <div id="dd_table" style="margin-top:16px"></div>
    </div>`;
}

async function ddLoadField(){
  const devId = +document.getElementById('dd_dev').value;
  const field = document.getElementById('dd_field');
  window.__dd.dev = devId;
  field.innerHTML = '<option value="">加载字段…</option>';
  if(!devId){ field.innerHTML='<option value="">请选择字段</option>'; document.getElementById('dd_chart').innerHTML='请选择设备'; document.getElementById('dd_table').innerHTML=''; return; }
  const r = await api('GET','/api/devices/'+devId+'/fields');
  const model = r.model||null;
  const fs = (model && model.fields) ? model.fields : [];
  const latest = r.fields||{};
  field.innerHTML = '<option value="">（选择字段）</option>' + fs.map(f=>`<option value="${f.key}">${esc(f.name||f.key)}${esc((f.unit?' ('+f.unit+')':''))}</option>`).join('')
    + (Object.keys(latest).length ? '<optgroup label="最新值">'+Object.keys(latest).map(k=>`<option value="${k}">${esc(k)}</option>`).join('')+'</optgroup>':'');
  if(!fs.length){
    document.getElementById('dd_chart').innerHTML = '<div class="muted">该设备的应用未配置物模型，或尚未收到可解码的上行。请先到「物模型」页为其应用配置模型。</div>';
  }
  ddLoad();
}

async function ddLoad(){
  const devId = +document.getElementById('dd_dev').value;
  const field = document.getElementById('dd_field').value;
  const range = +document.getElementById('dd_range').value || 86400;
  const chartHost = document.getElementById('dd_chart');
  const tableHost = document.getElementById('dd_table');
  if(!devId || !field){ return; }
  chartHost.innerHTML = t('加载中…');
  const now = Math.floor(Date.now()/1000);
  const r = await api('GET','/api/device-readings?dev_id='+devId+'&field='+encodeURIComponent(field)+'&from='+(now-range)+'&to='+now);
  if(r && r.error){ chartHost.innerHTML = '<div class="err-box">'+esc(r.error)+'</div>'; return; }
  const data = (r && r.data) ? r.data : [];
  if(!data.length){ chartHost.innerHTML = '<div class="muted">该时间范围内没有「'+esc(field)+'」的读数</div>'; tableHost.innerHTML=''; return; }
  chartHost.innerHTML = ddSvgChart(data, field);
  tableHost.innerHTML = `<b style="display:block;margin-bottom:6px">${t('历史明细')}</b>` + buildHistTable(data);
}

function buildHistTable(data){
  return `<table class="table sortable" style="width:100%">
    <thead><tr><th>${t('时间')}</th><th>${t('数值')}</th><th>fcnt</th></tr></thead>
    <tbody>${data.slice(-100).reverse().map(d=>`<tr><td>${esc(new Date(d.ts*1000).toLocaleString())}</td><td>${esc(d.t||d.v)}</td><td>${d.fcnt}</td></tr>`).join('')}</tbody>
  </table>`;
}

function ddSvgChart(data, field){
  const w = 800, h = 200, L = 44, R = 12, T = 12, B = 28;
  const cOk = getCSS('--ok','#36d399'), cTxt=getCSS('--txt','#e6ecf5'), cMut=getCSS('--mut','#8b97ad'), cLine=getCSS('--line','#2b3650'), cAcc=getCSS('--acc','#3da9fc');
  const vals = data.map(d=>+d.v || 0);
  let mn = Math.min.apply(null, vals), mx = Math.max.apply(null, vals);
  if(mn===mx){ mn-=1; mx+=1; }
  const pad=(mx-mn)*0.08 || 1; mn-=pad; mx+=pad;
  const X = i => L + (w-L-R) * (data.length===1?0.5:(i/(data.length-1)));
  const Y = v => h-B - (h-T-B) * ((v-mn)/(mx-mn));
  const pts = data.map((d,i)=>`${X(i).toFixed(1)},${Y(+d.v).toFixed(1)}`).join(' ');
  let grid='';
  for(let g=0; g<=4; g++){ const v=mn+(mx-mn)*g/4; const yy=Y(v); grid+=`<line x1="${L}" y1="${yy}" x2="${w-R}" y2="${yy}" stroke="${cLine}" stroke-width="1" stroke-dasharray="3 4"/><text x="${L-6}" y="${yy+4}" fill="${cMut}" font-size="10" text-anchor="end">${fmtNum(v)}</text>`; }
  const t0 = data[0].ts*1000, t1 = data[data.length-1].ts*1000;
  const timeLabels = [t0, (t0+t1)/2, t1].map(ts=>new Date(ts).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}));
  const timeXs = [L, L+(w-L-R)/2, w-R];
  const timeTxt = timeXs.map((x,i)=>`<text x="${x}" y="${h-8}" fill="${cMut}" font-size="10" text-anchor="${i===0?'start':i===2?'end':'middle'}">${timeLabels[i]}</text>`).join('');
  const dots = data.map((d,i)=>`<circle cx="${X(i).toFixed(1)}" cy="${Y(+d.v).toFixed(1)}" r="2.6" fill="${cAcc}"/>`).join('');
  return `<div style="font-weight:600;margin-bottom:6px">${esc(field)} <span class="muted" style="font-weight:400;font-size:12px">(${data.length} 点 · 最近值 ${esc(data[data.length-1].t||data[data.length-1].v)})</span></div>
    <svg viewBox="0 0 ${w} ${h}" style="width:100%;height:auto;display:block;background:var(--bg2,#0e1420);border:1px solid ${cLine};border-radius:8px" preserveAspectRatio="xMidYMid meet" role="img" aria-label="${esc(field)} trend">
      <rect x="0" y="0" width="${w}" height="${h}" fill="transparent"/>
      ${grid}
      ${timeTxt}
      <polyline points="${pts}" fill="none" stroke="${cOk}" stroke-width="1.6"/>
      ${dots}
    </svg>`;
}
function getCSS(name, fallback){
  try{ return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback; }catch(e){ return fallback; }
}
function fmtNum(v){
  if(Math.abs(v)>=1000 || Math.abs(v)<0.01) return Number(v).toExponential(1);
  return Number(v).toFixed(1);
}

const __alert = { rules: [], groups: [], devices: [] };

async function viewAlerts(){
  const view = document.getElementById('view');
  view.innerHTML = `<div class="view-head"><h2>${ICON.bellAlert||''}${t('告警管理')}</h2></div>
    <div id="alert_summary" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px"></div>
    <div style="display:flex;gap:8px;margin:14px 0 10px;flex-wrap:wrap">
      <button class="btn ${__alert.tab==='rules'?'':'ghost'}" onclick="__alert.tab='rules';alertRenderTabs()">${ICON.pencilSquare}${t('告警规则')}</button>
      <button class="btn ${__alert.tab==='open'?'':'ghost'}" onclick="__alert.tab='open';alertRenderTabs()">${ICON.bellAlert}${t('最新告警')}</button>
      <button class="btn ${__alert.tab==='log'?'':'ghost'}" onclick="__alert.tab='log';alertRenderTabs()">${ICON.clipboardDocumentList}${t('告警日志')}</button>
      <button class="btn ${__alert.tab==='groups'?'':'ghost'}" onclick="__alert.tab='groups';alertRenderTabs()">${ICON.bell}${t('通知组')}</button>
    </div>
    <div id="alert_body" class="card" style="padding:16px;max-width:1000px"></div>`;
  await alertLoadSummary();
  await alertRenderTabs();
}

async function alertLoadSummary(){
  const host = document.getElementById('alert_summary');
  const c = await api('GET','/api/alerts?scope=counts').catch(()=>({}));
  const counts = c && c.counts ? c.counts : {triggered:0,today:0,total:0};
  host.innerHTML = [
    {k:'triggered', v:counts.triggered, label:t('触发中'), color:'var(--err,#f87171)', icon:'exclamationTriangle'},
    {k:'today', v:counts.today, label:t('今日告警'), color:'var(--warn,#fbbf24)', icon:'clock'},
    {k:'total', v:counts.total, label:t('累计告警'), color:'var(--acc,#3da9fc)', icon:'bellAlert'},
  ].map(s=>`<div style="flex:1;min-width:160px;background:var(--bg2,#0e1420);border:1px solid var(--line);border-radius:12px;padding:14px 16px;display:flex;gap:12px;align-items:center">
      <div style="color:${s.color}">${ICON[s.icon]||''}</div>
      <div><div style="font-size:24px;font-weight:700;line-height:1.1">${s.v}</div><div class="muted" style="font-size:12px">${s.label}</div></div>
    </div>`).join('');
}

async function alertRenderTabs(){
  const host = document.getElementById('alert_body');
  if(!host) return;
  const tab = __alert.tab || 'open';
  if(tab === 'rules') return alertRenderRules(host);
  if(tab === 'groups') return alertRenderGroups(host);
  return alertRenderLog(host, tab === 'open');
}

async function alertRenderRules(host){
  const apps = (await api('GET','/api/applications')).data || [];
  __alert.apps = apps;
  host.innerHTML = `
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
      <button class="btn" onclick="alertRuleNew()">${ICON.plus}${t('新建规则')}</button>
      <div class="muted" style="font-size:12px">${t('告警规则说明')}</div>
    </div>
    <div id="alert_rules_list"></div>`;
  await alertReloadRules();
}

async function alertReloadRules(){
  const host = document.getElementById('alert_rules_list');
  if(!host) return;
  const list = (await api('GET','/api/alert-rules')).data || [];
  host.innerHTML = list.length ? list.map(r=>{
    const dev = r.device_id && r.device_id>0 ? ((__alert.cachedDevs||{})[r.device_id]||('#dev'+r.device_id)) : t('全部设备');
    const sev = `<span class="sev-${esc(r.severity)}">${sevLabel(r.severity)}</span>`;
    return `<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;margin-bottom:8px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <b>${esc(r.name)}</b> <span class="muted" style="font-size:12px">${sev} · ${esc(r.field_key)} ${esc(opLabelAlter(String(r.operator)))} ${esc(r.threshold)}</span>
        <div class="muted" style="font-size:12px;margin-top:2px">${t('触发就绪')}: ${esc(r.field_key)} · 设备 ${dev} ${r.enabled?'':'· <span style="color:var(--mut)">(已停用)</span>'}</div>
      </div>
      <button class="ghost" onclick="alertRuleEdit(${r.id})">${ICON.pencilSquare}${t('编辑')}</button>
      <button class="btn err" onclick="alertRuleToggle(${r.id},${r.enabled?0:1})">${r.enabled?t('停用'):t('启用')}</button>
      <button class="btn err" onclick="alertRuleDel(${r.id})">${ICON.trash}${t('删除')}</button>
    </div>`;
  }).join('') : '<div class="muted">'+t('暂无告警规则，点击「新建规则」')+'</div>';

  if(list.some(r=>r.device_id && r.device_id>0)) alertCacheDevices(list);
}

let __alertDevCached = false;
async function alertCacheDevices(list){
  if(__alertDevCached) return;
  __alertDevCached = true;
  const devs = (await api('GET','/api/devices')).data || [];
  __alert.cachedDevs = {};
  devs.forEach(d=>__alert.cachedDevs[d.id]=d.name);
  alertReloadRules();
}

function sevLabel(s){ return s==='critical'?t('严重'):s==='info'?t('提示'):t('警告'); }
function opLabelAlter(op){ return {gt:'>',ge:'≥',lt:'<',le:'≤',eq:'=',neq:'≠',in:'∈'}[op]||op; }

function alertRuleNew(){
  const apps = __alert.apps || [];
  alertRuleModal(apps, {application_id:0, device_id:0, name:'', field_key:'', operator:'gt', threshold:'', severity:'warn', notify_group_id:0, enabled:1});
}
async function alertRuleEdit(id){
  const list = (await api('GET','/api/alert-rules')).data || [];
  const r = list.find(x=>+x.id===+id);
  if(!r){ toast(t('未找到该规则'),'err'); return; }
  const apps = (await api('GET','/api/applications')).data || [];
  await alertLoadAppDevices(+r.application_id);
  alertRuleModal(apps, r);
}

let __alertModelFields = [];
async function alertLoadModelFields(appId){
  __alertModelFields = [];
  if(appId>0){
    const r = await api('GET','/api/thing-models?app_id='+appId);
    const list = r && r.data ? r.data : [];
    if(list.length) __alertModelFields = (list[0].fields||[]).map(f=>({key:f.key, name:f.name||f.key}));
  }
  return __alertModelFields;
}
async function alertLoadAppDevices(appId){
  const r = await api('GET','/api/devices');
  const all = (r && r.data) || [];
  __alert.devices = appId>0 ? all.filter(d=>(+d.app_id===+appId)||(+d.application_id===+appId)) : all;
}

async function alertRuleModal(apps, r){
  __alertModal = r;
  const groups = (await api('GET','/api/notification-groups')).data || [];
  await alertLoadModelFields(+r.application_id);
  openModal(`
    <h3>${t('配置告警规则')}</h3>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('应用')}</label>
        <select id="alr_app" onchange="alertRuleAppChanged()" style="flex:1">
          <option value="0">${t('请选择应用')}</option>
          ${apps.map(a=>`<option value="${a.id}" ${+a.id===+r.application_id?'selected':''}>${esc(a.name)}</option>`).join('')}
        </select>
      </div>
      <div id="alr_fields" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('监控字段')}</label>
        <select id="alr_field" style="flex:1;">${alertFieldsOptions(r.field_key)}</select>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('规则名称')}</label>
        <input id="alr_name" value="${esc(r.name)}" placeholder="${t('如：温度过高')}" style="flex:1">
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('条件')}</label>
        <select id="alr_op" style="width:90px">
          ${[{v:'gt',t:'> 大于'},{v:'ge',t:'≥ 大于等于'},{v:'lt',t:'< 小于'},{v:'le',t:'≤ 小于等于'},{v:'eq',t:'= 等于'},{v:'neq',t:'≠ 不等于'},{v:'in',t:'∈ 属于'},].map(o=>`<option value="${o.v}" ${r.operator===o.v?'selected':''}>${o.t}</option>`).join('')}
        </select>
        <input id="alr_threshold" value="${esc(r.threshold)}" placeholder="${t('阈值，in 用逗号分隔')}" style="flex:1">
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('级别')}</label>
        <select id="alr_severity">
          ${[{v:'info',t:t('提示'),c:'--ok'},{v:'warn',t:t('警告'),c:'--warn'},{v:'critical',t:t('严重'),c:'--err'}].map(s=>`<option value="${s.v}" ${r.severity===s.v?'selected':''}>${s.t}</option>`).join('')}
        </select>
        <label style="margin:0">${t('设备')}</label>
        <select id="alr_device" style="flex:1">
          <option value="0">${t('全部设备（该应用）')}</option>
          ${__alert.devices.map(d=>`<option value="${d.id}" ${+r.device_id===+d.id?'selected':''}>${esc(d.name)}</option>`).join('')}
        </select>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('通知组')}</label>
        <select id="alr_group" style="flex:1">
          <option value="0">${t('不通知')}</option>
          ${groups.map(g=>`<option value="${g.id}" ${+r.notify_group_id===+g.id?'selected':''}>${esc(g.name)}</option>`).join('')}
        </select>
        <label style="margin:0;display:flex;align-items:center;gap:4px"><input type="checkbox" id="alr_enabled" ${r.enabled?'checked':''}>${t('启用')}</label>
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="ghost" onclick="closeModal()">${t('取消')}</button>
      <button class="btn" onclick="alertRuleSave()">${t('保存')}</button>
    </div>`);
}
function alertFieldsOptions(selKey){
  if(!__alertModelFields.length) return `<option value="">${t('（无字段，请先在物模型定义）')}</option>`;
  return __alertModelFields.map(f=>`<option value="${esc(f.key)}" ${f.key===selKey?'selected':''}>${esc(f.name)}</option>`).join('');
}
async function alertRuleAppChanged(){
  const appId = +document.getElementById('alr_app').value;
  await alertLoadAppDevices(appId);
  await alertLoadModelFields(appId);
  const dv = document.getElementById('alr_device');
  if(dv) dv.innerHTML = `<option value="0">${t('全部设备（该应用）')}</option>` + __alert.devices.map(d=>`<option value="${d.id}">${esc(d.name)}</option>`).join('');
  const ff = document.getElementById('alr_field');
  if(ff) ff.innerHTML = alertFieldsOptions('');
}

async function alertRuleSave(){
  const appId = +document.getElementById('alr_app').value;
  const body = {
    application_id: appId,
    name: document.getElementById('alr_name').value.trim(),
    field_key: document.getElementById('alr_field').value,
    operator: document.getElementById('alr_op').value,
    threshold: document.getElementById('alr_threshold').value.trim(),
    severity: document.getElementById('alr_severity').value,
    device_id: +document.getElementById('alr_device').value || 0,
    notify_group_id: +document.getElementById('alr_group').value || 0,
    enabled: document.getElementById('alr_enabled').checked ? 1 : 0,
  };
  if(!appId){ toast(t('请选择应用'),'err'); return; }
  if(!body.field_key){ toast(t('请选择监控字段'),'err'); return; }
  try{
    const r = __alertModal.id
      ? await api('PUT','/api/alert-rules/'+__alertModal.id, body)
      : await api('POST','/api/alert-rules', body);
    if(r && r.error){ toast(String(r.error),'err'); return; }
    toast(t('已保存'),'ok'); closeModal(); alertReloadRules();
  }catch(e){ toast(t('保存失败')+'：'+e.message,'err'); }
}
async function alertRuleToggle(id, on){
  const r = await api('PUT','/api/alert-rules/'+id, {enabled:on});
  if(r && r.error){ toast(String(r.error),'err'); return; }
  toast(t('已更新'),'ok'); alertReloadRules();
}
async function alertRuleDel(id){
  const ok = await new Promise(res=>confirmDlg(t('确定删除该规则？已产生的告警记录会保留。'), res));
  if(!ok) return;
  const r = await api('DELETE','/api/alert-rules/'+id);
  if(r && r.error){ toast(String(r.error),'err'); return; }
  toast(t('已删除'),'ok'); alertReloadRules();
}

let __alertLogState = {status:'', offset:0};
async function alertRenderLog(host, activeOnly){
  __alertLogState = {status: activeOnly?'triggered':'', offset:0, active:activeOnly};
  host.innerHTML = activeOnly
    ? `<div class="muted" style="margin-bottom:10px">${t('当前触发中的告警，恢复或手动处理后归入日志')}</div><div id="alert_log_list"></div>`
    : `<div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap">
        <button class="btn ${!__alertLogState.status?'':'ghost'}" onclick="alertLogFilter('')">${t('全部')}</button>
        <button class="btn ${__alertLogState.status==='triggered'?'':'ghost'}" onclick="alertLogFilter('triggered')">${t('触发中')}</button>
        <button class="btn ${__alertLogState.status==='resolved'?'':'ghost'}" onclick="alertLogFilter('resolved')">${t('已恢复')}</button>
        <button class="btn ghost" onclick="alertReloadLog()" style="margin-left:auto">${ICON.arrowPath}${t('刷新')}</button>
      </div><div id="alert_log_list"></div>`;
  await alertReloadLog();
}
function alertLogFilter(s){ __alertLogState.status=s; alertReloadLog(); }
async function alertReloadLog(){
  const host = document.getElementById('alert_log_list');
  if(!host) return;
  const q = '/api/alerts?limit=100' + (__alertLogState.status?'&status='+__alertLogState.status:'');
  const r = await api('GET', q).catch(()=>({data:[]}));
  const list = (r && r.data) || [];
  host.innerHTML = list.length ? list.map(a=>{
    const sevC = a.severity==='critical'?'var(--err,#f87171)':a.severity==='info'?'var(--ok,#36d399)':'var(--warn,#fbbf24)';
    const active = a.status==='triggered';
    return `<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid ${active?'': 'var(--line)'};border-left:3px solid ${active?sevC:'var(--line)'};border-radius:8px;margin-bottom:8px;flex-wrap:wrap;background:${active?'color-mix(in srgb,'+sevC+' 8%, transparent)':'var(--bg2,#0e1420)'}">
      <div style="flex:1;min-width:200px">
        <b>${esc(a.rule_name||a.rule_id)}</b>
        <span class="sev-${esc(a.severity)}">${sevLabel(a.severity)}</span>
        ${active?`<span style="color:${sevC};font-size:12px">● ${t('触发中')}</span>`:`<span class="muted" style="font-size:12px">✓ ${t('已恢复')}</span>`}
        <div style="font-size:12px;margin-top:2px">${esc(a.device_name)} · ${esc(a.field_key||'')} = ${esc(a.text_value!==''?a.text_value:a.value)}</div>
        <div class="muted" style="font-size:12px;margin-top:2px">${esc(a.message||'')}</div>
      </div>
      <div class="muted" style="font-size:12px">${esc(new Date(a.ts*1000).toLocaleString())}</div>
      ${active?`<button class="ghost" onclick="alertResolve(${a.id})">${ICON.checkCircle}${t('手动处理')}</button>`:''}
    </div>`;
  }).join('') : '<div class="muted">'+t('暂无告警记录')+'</div>';
  const counts = (r && r.counts) || {};
  if(counts.triggered!==undefined && (document.getElementById('alert_summary'))) alertLoadSummary();
}
async function alertResolve(id){
  const ok = await new Promise(res=>confirmDlg(t('标记该告警为已处理？'), res));
  if(!ok) return;
  const r = await api('POST','/api/alerts/'+id+'?action=resolve');
  if(r && r.error){ toast(String(r.error),'err'); return; }
  toast(t('已处理'),'ok'); alertReloadLog(); alertLoadSummary();
}
async function alertRenderGroups(host){
  host.innerHTML = `
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
      <button class="btn" onclick="grpNew()">${ICON.plus}${t('新建通知组')}</button>
      <div class="muted" style="font-size:12px">${t('通知组说明')}</div>
    </div>
    <div id="grp_list" class="muted">${t('加载中…')}</div>`;
  await grpReload();
}

let __grpModal = null;
async function viewNotificationGroups(){
  const view = document.getElementById('view');
  view.innerHTML = `<div class="view-head"><h2>${ICON.bell||''}${t('通知组')}</h2></div>
    <div class="card" style="padding:16px;max-width:1000px;margin-top:4px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
        <button class="btn" onclick="grpNew()">${ICON.plus}${t('新建通知组')}</button>
        <div class="muted" style="font-size:12px">${t('通知组说明')}</div>
      </div>
      <div id="grp_list" class="muted">${t('加载中…')}</div>
    </div>`;
  await grpReload();
}
async function grpReload(){
  const host = document.getElementById('grp_list');
  if(!host) return;
  const r = await api('GET','/api/notification-groups').catch(()=>({data:[]}));
  const list = (r && r.data) || [];
  host.innerHTML = list.length ? list.map(g=>`
    <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;margin-bottom:8px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <b>${esc(g.name)}</b> ${g.enabled?'':'<span class="muted" style="font-size:12px">(已停用)</span>'}
        <div class="muted" style="font-size:12px;margin-top:2px;word-break:break-all">${esc(g.webhook_url||t('未配置 Webhook'))}</div>
      </div>
      <button class="ghost" onclick="grpEdit(${JSON.stringify(g)})">${ICON.pencilSquare}${t('编辑')}</button>
      <button class="btn err" onclick="grpDel(${g.id})">${ICON.trash}${t('删除')}</button>
    </div>`).join('') : '<div class="muted">'+t('暂无通知组，点击「新建通知组」')+'</div>';
}
function grpNew(){ grpModal({id:0,name:'',webhook_url:'',enabled:1}); }
function grpEdit(g){ grpModal(g); }
function grpModal(g){
  __grpModal = g;
  openModal(`
    <h3>${g.id?t('编辑通知组'):t('新建通知组')}</h3>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('名称')}</label>
        <input id="grp_name" value="${esc(g.name)}" placeholder="${t('如：运维群')}" style="flex:1">
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">Webhook</label>
        <input id="grp_url" value="${esc(g.webhook_url)}" placeholder="https://…（告警触达/恢复时 POST JSON）" style="flex:1">
      </div>
      <div><label style="display:flex;align-items:center;gap:6px;font-weight:400"><input type="checkbox" id="grp_enabled" ${g.enabled?'checked':''}>${t('启用该通知组')}</label></div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="ghost" onclick="closeModal()">${t('取消')}</button>
      <button class="btn" onclick="grpSave()">${t('保存')}</button>
    </div>`);
}
async function grpSave(){
  const g = {
    name: document.getElementById('grp_name').value.trim(),
    webhook_url: document.getElementById('grp_url').value.trim(),
    enabled: document.getElementById('grp_enabled').checked ? 1 : 0,
  };
  if(!g.name){ toast(t('请输入通知组名称'),'err'); return; }
  try{
    const r = __grpModal.id
      ? await api('PUT','/api/notification-groups/'+__grpModal.id, g)
      : await api('POST','/api/notification-groups', g);
    if(r && r.error){ toast(String(r.error),'err'); return; }
    toast(t('已保存'),'ok'); closeModal(); grpReload();
  }catch(e){ toast(t('保存失败')+'：'+e.message,'err'); }
}
async function grpDel(id){
  const ok = await new Promise(res=>confirmDlg(t('确定删除该通知组？引用它的规则将不再通知。'), res));
  if(!ok) return;
  const r = await api('DELETE','/api/notification-groups/'+id);
  if(r && r.error){ toast(String(r.error),'err'); return; }
  toast(t('已删除'),'ok'); grpReload();
}

let __sched = { devices: [] };
function schedFmtTS(ts){ return ts ? new Date(ts*1000).toLocaleString() : '—'; }
function schedCronDesc(expr){
  expr = (expr||'').trim();
  const p = expr.split(/\s+/);
  if(p.length!==5) return expr||'—';
  const [min,h,d,m,dow] = p;
  const pad = s=>String(s).padStart(2,'0');
  const mk = (mm,hh)=>mm+':'+hh;
  if(min==='*' && h==='*' && d==='*' && m==='*' && dow==='*') return t('每分钟');
  if(min.indexOf('*/')===0 && h==='*' && d==='*'&&m==='*'&&dow==='*') return t('每')+min.slice(1)+t('分钟');
  if(min==='*/1' && h==='*'&&d==='*'&&m==='*'&&dow==='*') return t('每分钟');
  if(h!=='*' && d==='*' && m==='*' && dow==='*' && min.indexOf(',')<0 && min.indexOf('*/')<0) return t('每天')+mk(pad(min),pad(h));
  if(h!=='*' && d==='*' && m==='*' && dow!=='*' && min.indexOf(',')<0 && min.indexOf('*/')<0){
    const week=['周日','周一','周二','周三','周四','周五','周六'];
    const parts = dow.split(',');
    return (parts.map(x=>week[+x]).join('/'))+' '+mk(pad(min),pad(h));
  }
  return expr;
}
async function viewScheduledTasks(){
  const view = document.getElementById('view');
  view.innerHTML = `<div class="view-head"><h2>${ICON.clock||''}${t('定时任务')}</h2></div>
    <div class="card" style="padding:16px;max-width:1100px;margin-top:4px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap">
        <button class="btn" onclick="schedNew()">${ICON.plus}${t('新建定时任务')}</button>
        <div class="muted" style="font-size:12px">${t('定时任务说明')}</div>
      </div>
      <div id="sched_list" class="muted">${t('加载中…')}</div>
    </div>`;
  const [appR, devR] = await Promise.all([
    api('GET','/api/applications').catch(()=>({data:[]})),
    api('GET','/api/devices').catch(()=>({data:[]})),
  ]);
  __sched.apps = (appR&&appR.data)||[];
  __sched.devices = (devR&&devR.data)||[];
  await schedReload();
}
async function schedReload(){
  const host = document.getElementById('sched_list');
  if(!host) return;
  const r = await api('GET','/api/scheduled-tasks').catch(()=>({data:[]}));
  const list = (r && r.data) || [];
  const devName = id => { const d = (__sched.devices||[]).find(x=>+x.id===+id); return d?d.name:('#id'+id); };
  host.innerHTML = list.length ? list.map(x=>`
    <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;margin-bottom:8px;flex-wrap:wrap">
      <div style="flex:1;min-width:220px">
        <b>${esc(x.name)}</b>
        ${x.enabled?'':'<span class="muted" style="font-size:12px">('+t('已停用')+')</span>'}
        <div class="muted" style="font-size:12px;margin-top:2px">
          ${t('设备')}: ${esc(devName(x.device_id))} · ${t('端口')} ${x.port} · ${x.confirmed?'● '+t('确认'):''} ·
          payload <code>${esc(x.payload_hex)}</code>
        </div>
        <div class="muted" style="font-size:12px;margin-top:2px">
          cron <code>${esc(x.cron)}</code> (${esc(schedCronDesc(x.cron))})
          · ${t('下次')}: ${schedFmtTS(x.next_run_at)} · ${t('上次')}: ${schedFmtTS(x.last_run_at)}
          ${x.last_result?(' · '+esc(x.last_result)):''}
        </div>
      </div>
      <button class="ghost" onclick="schedRun(${x.id})" title="${t('立即执行')}">${ICON.play||'▶'}${t('执行')}</button>
      <button class="ghost" onclick="schedEdit(${JSON.stringify(x)})">${ICON.pencilSquare}${t('编辑')}</button>
      <button class="btn ${x.enabled?'ghost err':'ghost'}" onclick="schedToggle(${x.id},${x.enabled?0:1})">${x.enabled?'⏸':t('启用')}</button>
      <button class="btn err ghost" onclick="schedDel(${x.id})">${ICON.trash}${t('删除')}</button>
    </div>`).join('') : '<div class="muted">'+t('暂无定时任务，点击「新建定时任务」')+'</div>';
}

function schedBuildCron(){
  const mode = document.getElementById('st_mode').value;
  if(mode==='custom') return (document.getElementById('st_cron').value||'').trim();
  if(mode==='interval'){
    const n = Math.max(1, parseInt(document.getElementById('st_min').value,10)||0);
    return '*/'+n+' * * * *';
  }
  if(mode==='daily'){
    const hm = (document.getElementById('st_hm').value||'08:00').split(':');
    return hm[1]+' '+hm[0]+' * * *';
  }
  if(mode==='weekly'){
    const hm = (document.getElementById('st_wd_hm').value||'08:00').split(':');
    const dow = document.getElementById('st_dow').value;
    return hm[1]+' '+hm[0]+' * * '+dow;
  }
  return '';
}
function schedModal(x){
  __schedModal = x;
  const isNew = !x.id;
  const devs = __sched.devices||[];
  const cronMode = (x.cron||'').indexOf('*/')===0 ? 'interval'
    : /^\d+ \d+ \* \* \*$/.test(x.cron||'') ? 'daily'
    : /^\d+ \d+ \* \* [0-6]$/.test(x.cron||'') ? 'weekly' : 'custom';
  openModal(`
    <h3>${isNew?t('新建定时任务'):t('编辑定时任务')}</h3>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('任务名称')}</label>
        <input id="st_name" value="${esc(x.name||'')}" placeholder="${t('如：每小时上报继电器状态')}" style="flex:1">
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('目标设备')}</label>
        <select id="st_dev" style="flex:1">
          <option value="0">${t('请选择设备')}</option>
          ${devs.map(d=>`<option value="${d.id}" ${+d.id===+x.device_id?'selected':''}>${esc(d.name)} (${esc(d.dev_eui||'')})</option>`).join('')}
        </select>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('下行内容')}</label>
        <input id="st_payload" value="${esc(x.payload_hex||'')}" placeholder="HEX，如 010203" style="flex:1;font-family:monospace">
        <input id="st_port" type="number" min="1" max="223" value="${x.port||1}" style="width:70px" title="${t('端口')}">
        <label style="margin:0;display:flex;align-items:center;gap:4px"><input type="checkbox" id="st_confirmed" ${x.confirmed?'checked':''}>${t('确认')}</label>
      </div>
      <div style="border-top:1px solid var(--line);padding-top:12px">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
          <label style="display:flex;align-items:center;gap:4px"><input type="radio" name="st_mode" id="st_mode" value="interval" ${cronMode==='interval'?'checked':''} onchange="schedModeUI()">${t('每N分钟')}</label>
          <label style="display:flex;align-items:center;gap:4px"><input type="radio" name="st_mode" id="st_mode" value="daily" ${cronMode==='daily'?'checked':''} onchange="schedModeUI()">${t('每天')}</label>
          <label style="display:flex;align-items:center;gap:4px"><input type="radio" name="st_mode" id="st_mode" value="weekly" ${cronMode==='weekly'?'checked':''} onchange="schedModeUI()">${t('每周')}</label>
          <label style="display:flex;align-items:center;gap:4px"><input type="radio" name="st_mode" id="st_mode" value="custom" ${cronMode==='custom'?'checked':''} onchange="schedModeUI()">${t('自定义 cron')}</label>
        </div>
        <div id="st_mode_body" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          ${/* filled by schedModeUI */''}
        </div>
        <div class="muted" style="font-size:12px;margin-top:6px">${t('cron')}: <code id="st_cron_preview">${esc(x.cron||'*/5 * * * *')}</code></div>
      </div>
      <div><label style="display:flex;align-items:center;gap:6px;font-weight:400"><input type="checkbox" id="st_enabled" ${x.enabled?'checked':''}>${t('启用')}</label></div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="ghost" onclick="closeModal()">${t('取消')}</button>
      <button class="btn" onclick="schedSave()">${t('保存')}</button>
    </div>`);
  const m = document.getElementById('st_mode');
  const fns = { device:null };
  document.getElementById('st_mode_body')._applied = false;
  schedModeApply(cronMode, (x.cron||'').trim());
  document.getElementById('st_mode').value = cronMode;
  document.getElementById('st_cron_hidden') && schedRefreshPreview();
}
function schedModeUI(){
  const m = document.querySelector('input[name="st_mode"]:checked').value;
  schedModeApply(m, '');
  schedRefreshPreview();
}
function schedModeApply(mode, cron){
  const body = document.getElementById('st_mode_body');
  const def = { daily:['08:00'], weekly:['08:00','1'], interval:[(cron||'*/5').replace(/[^0-9]/g,'')||'5'] };
  let hm = def[mode]&&def[mode][0]; let dow = def[mode]&&def[mode][1];
  const mm = { daily:'', weekly:'', interval:'' };
  if(mode==='custom'){
    body.innerHTML = `<label style="margin:0;min-width:64px">cron</label><input id="st_cron" value="${esc(cron)}" placeholder="分 时 日 月 周，如 */15 * * * *" style="flex:1;font-family:monospace">`;
    return;
  }
  if(mode==='daily'){
    body.innerHTML = `<label style="margin:0">${t('每天')}</label><input type="time" id="st_hm" value="${hm}">`;
  } else if(mode==='weekly'){
    const week=['周日','周一','周二','周三','周四','周五','周六'];
    body.innerHTML = `<label style="margin:0">${t('每周')}</label>
      <select id="st_dow">${week.map((w,i)=>`<option value="${i}" ${String(i)===String(dow?'1':'') || +i===(dow||1)?'selected':''}>${w}</option>`).join('')}</select>
      <input type="time" id="st_wd_hm" value="${hm}">`;
  } else {
    body.innerHTML = `<label style="margin:0">${t('每')}</label>
      <input type="number" id="st_min" min="1" max="59" value="${def.interval[0]}" style="width:70px">${t('分钟')}`;
  }
}
function schedRefreshPreview(){
  const pre = document.getElementById('st_cron_preview');
  if(pre) pre.textContent = schedBuildCron();
}
async function schedSave(){
  try{
    const devId = parseInt(document.getElementById('st_dev').value,10);
    if(!devId){ toast(t('请选择设备'),'err'); return; }
    const payload = (document.getElementById('st_payload').value||'').replace(/[\s:]/g,'');
    if(!payload || payload.length%2){ toast(t('下行内容需为偶数个 HEX 字符'),'err'); return; }
    const cron = schedBuildCron();
    if(!cron){ toast(t('cron 表达式无效'),'err'); return; }
    const d = __schedModal;
    const body = {
      name: document.getElementById('st_name').value,
      device_id: devId,
      payload_hex: payload,
      port: parseInt(document.getElementById('st_port').value,10)||1,
      confirmed: document.getElementById('st_confirmed').checked?1:0,
      cron,
      enabled: document.getElementById('st_enabled').checked?1:0,
    };
    const r = d.id
      ? await api('PUT','/api/scheduled-tasks/'+d.id, body)
      : await api('POST','/api/scheduled-tasks', body);
    if(r && r.error){ toast(String(r.error),'err'); return; }
    toast(t('已保存'),'ok'); closeModal(); schedReload();
  }catch(e){ toast(t('保存失败')+'：'+e.message,'err'); }
}
async function schedRun(id){
  const r = await api('POST','/api/scheduled-tasks/'+id+'?action=run', {});
  if(r && r.error){ toast(String(r.error),'err'); return; }
  toast(t('已入队，等待下行窗口发送'),'ok'); schedReload();
}
async function schedToggle(id,enabled){
  const r = await api('POST','/api/scheduled-tasks/'+id+'?action=toggle&enabled='+(enabled?1:0), {});
  if(r && r.error){ toast(String(r.error),'err'); return; }
  schedReload();
}
async function schedDel(id){
  const ok = await new Promise(res=>confirmDlg(t('确定删除该定时任务？'), res));
  if(!ok) return;
  const r = await api('DELETE','/api/scheduled-tasks/'+id);
  if(r && r.error){ toast(String(r.error),'err'); return; }
  toast(t('已删除'),'ok'); schedReload();
}
function schedNew(){ schedModal({id:0,name:'',device_id:0,port:1,payload_hex:'',confirmed:0,cron:'*/5 * * * *',enabled:1}); }
function schedEdit(x){ schedModal(x); }
document.addEventListener('input', ev=>{ if(ev.target && /^st_(min|hm|wd_hm|dow|cron)$/.test(ev.target.id)) schedRefreshPreview(); });

var __au = { apps:[], devices:[], groups:[], list:[], modal:null };
async function viewAutomations(){
  const view = document.getElementById('view');
  view.innerHTML = `<div class="view-head"><h2>${ICON.bolt||''}${t('联动模型')}</h2>
    <button class="btn" onclick="autoNew()">${ICON.plus}${t('新建联动')}</button></div>
    <div class="card" style="padding:16px;max-width:1100px;margin-top:4px">
      <div class="muted" style="font-size:12px;margin-bottom:12px">${t('联动模型说明')}</div>
      <div id="auto_list" class="muted">${t('加载中…')}</div>
    </div>`;
  const [appR, devR, grpR] = await Promise.all([
    api('GET','/api/applications').catch(()=>({data:[]})),
    api('GET','/api/devices').catch(()=>({data:[]})),
    api('GET','/api/notification-groups').catch(()=>({data:[]})),
  ]);
  __au.apps = (appR&&appR.data)||[];
  __au.devices = (devR&&devR.data)||[];
  __au.groups = (grpR&&grpR.data)||[];
  await autoReload();
}
async function autoReload(){
  const host = document.getElementById('auto_list');
  if(!host) return;
  const r = await api('GET','/api/automations').catch(()=>({data:[]}));
  __au.list = (r && r.data) || [];
  const devName = id => { const d=(__au.devices||[]).find(x=>+x.id===+id); return d?d.name:('#id'+id); };
  const opLabel = op => ({gt:'>',ge:'≥',lt:'<',le:'≤',eq:'=',neq:'≠',in:'∈'})[op]||op;
  host.innerHTML = __au.list.length ? __au.list.map(x=>{
    const src = (+x.trigger_device_id===0)?t('任意设备'):devName(x.trigger_device_id);
    const act = x.action_type==='notify'
      ? t('通知组')+': '+(__au.groups.find(g=>+g.id===+x.notify_group_id)?.name||('#grp'+x.notify_group_id))
      : t('下发命令')+' → '+devName(x.action_device_id)+' '+(x.action_payload_hex||'');
    return `<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--line);border-radius:8px;margin-bottom:8px;flex-wrap:wrap">
      <div style="flex:1;min-width:260px">
        <b>${esc(x.name)}</b>
        ${x.enabled?'':'<span class="muted" style="font-size:12px">('+t('已停用')+')</span>'}
        <div class="muted" style="font-size:12px;margin-top:2px">
          <code>${esc(src)}</code> · <code>${esc(x.trigger_field)}</code> ${opLabel(x.trigger_operator)} <code>${esc(x.trigger_value)}</code>
          <span style="opacity:.6">→</span> ${act}
        </div>
        <div class="muted" style="font-size:12px;margin-top:2px">
          ${t('冷却')} ${x.cooldown_seconds}s · ${t('触发')} ${x.fired_count||0} ${t('次')} · ${t('最近触发')}: ${autoFmtTS(x.last_fired_at)}
          ${x.last_result?(' · '+esc(String(x.last_result).slice(0,60))):''}
        </div>
      </div>
      <button class="ghost" onclick="autoEdit(${x.id})">${ICON.pencilSquare}${t('编辑')}</button>
      <button class="btn ${x.enabled?'ghost err':'ghost'}" onclick="autoToggle(${x.id},${x.enabled?0:1})">${x.enabled?'⏸':'▶ '+t('启用')}</button>
      <button class="btn err ghost" onclick="autoDel(${x.id})">${ICON.trash}${t('删除')}</button>
    </div>`;
  }).join('') : '<div class="muted">'+t('暂无联动，点击「新建联动」')+'</div>';
}
function autoFmtTS(ts){
  if(!ts) return '-';
  const d = new Date(ts*1000);
  const p = n => String(n).padStart(2,'0');
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());
}
function autoNew(){ autoModal({id:0,name:'',application_id:(__au.apps[0]||{}).id||0,trigger_device_id:0,trigger_field:'',trigger_operator:'gt',trigger_value:'',cooldown_seconds:60,enabled:1,action_type:'downlink',action_device_id:0,action_port:1,action_payload_hex:'',action_confirmed:0,notify_group_id:0}); }
function autoEdit(id){ const x=__au.list.find(r=>+r.id===+id); if(!x) return; autoModal(x); }
function autoModal(x){
  __au.modal = x;
  const isNew = !x.id;
  const devsByApp = appId => (__au.devices||[]).filter(d=>+d.application_id===+appId);
  openModal(`
    <h3>${isNew?t('新建联动'):t('编辑联动')}</h3>
    <div style="display:flex;flex-direction:column;gap:12px">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('名称')}</label>
        <input id="au_name" value="${esc(x.name||'')}" placeholder="${t('如：温度过高自动关阀')}" style="flex:1">
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('应用')}</label>
        <select id="au_app" onchange="autoAppChange()" style="flex:1">
          ${(__au.apps||[]).map(a=>`<option value="${a.id}" ${+a.id===+x.application_id?'selected':''}>${esc(a.name)}</option>`).join('')}
        </select>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('触发设备')}</label>
        <select id="au_tdev" style="flex:1">
          <option value="0">${t('任意设备')}</option>
        </select>
        <label style="margin:0;min-width:40px">字段</label>
        <input id="au_field" value="${esc(x.trigger_field||'')}" placeholder="${t('字段名，如 temperature')}" style="flex:1;font-family:monospace">
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('触发条件')}</label>
        <select id="au_op" style="width:70px">
          ${['gt','ge','lt','le','eq','neq','in'].map(o=>`<option value="${o}" ${x.trigger_operator===o?'selected':''}>${({gt:'>',ge:'≥',lt:'<',le:'≤',eq:'=',neq:'≠',in:'∈'})[o]||o}</option>`).join('')}
        </select>
        <input id="au_val" value="${esc(x.trigger_value||'')}" placeholder="阈值，如 30 或 in:on,off" style="flex:1">
        <label style="margin:0;min-width:56px">${t('冷却秒数')}</label>
        <input id="au_cd" type="number" min="0" value="${x.cooldown_seconds||60}" style="width:90px">
      </div>
      <div style="border-top:1px solid var(--line);padding-top:12px">
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
          <label style="display:flex;align-items:center;gap:4px"><input type="radio" name="au_atype" value="downlink" ${x.action_type!=='notify'?'checked':''} onchange="autoAtypeUI()">${t('下发命令')}</label>
          <label style="display:flex;align-items:center;gap:4px"><input type="radio" name="au_atype" value="notify" ${x.action_type==='notify'?'checked':''} onchange="autoAtypeUI()">${t('通知推送')}</label>
        </div>
        <div id="au_action_body"></div>
      </div>
      <div><label style="display:flex;align-items:center;gap:6px;font-weight:400"><input type="checkbox" id="au_enabled" ${x.enabled?'checked':''}>${t('启用')}</label></div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end">
      <button class="ghost" onclick="closeModal()">${t('取消')}</button>
      <button class="btn" onclick="autoSave()">${t('保存')}</button>
    </div>`);
  autoFillApp(x, devsByApp(x.application_id||0));
  autoAtypeUI();
}
function autoFillApp(x, devs){
  const tsel = document.getElementById('au_tdev');
  if(!tsel) return;
  tsel.innerHTML = '<option value="0">'+t('任意设备')+'</option>' +
    devs.map(d=>`<option value="${d.id}" ${+d.id===(+x.trigger_device_id||0)?'selected':''}>${esc(d.name)} (${esc(d.dev_eui||'')})</option>`).join('');
}
function autoAppChange(){
  const appId = +document.getElementById('au_app').value;
  autoFillApp(__au.modal, (__au.devices||[]).filter(d=>+d.application_id===appId));
  autoAtypeUI();
}
function autoActionDeviceOptions(devs, sel){
  return '<option value="0">'+t('请选择设备')+'</option>' +
    (devs||[]).map(d=>`<option value="${d.id}" ${String(d.id)===String(sel||0)?'selected':''}>${esc(d.name)} (${esc(d.dev_eui||'')})</option>`).join('');
}
function autoAtypeUI(){
  const body = document.getElementById('au_action_body');
  if(!body) return;
  const x = __au.modal||{};
  const type = document.querySelector('input[name="au_atype"]:checked').value;
  const appId = +((document.getElementById('au_app')||{}).value || x.application_id||0);
  const devs = (__au.devices||[]).filter(d=>+d.application_id===appId);
  if(type==='downlink'){
    body.innerHTML = `
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('目标设备')}</label>
        <select id="au_adev" style="flex:1">${autoActionDeviceOptions(devs, x.action_device_id)}</select>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px">
        <label style="margin:0;min-width:64px">${t('下行内容')}</label>
        <input id="au_payload" value="${esc(x.action_payload_hex||'')}" placeholder="HEX，如 010203" style="flex:1;font-family:monospace">
        <input id="au_port" type="number" min="1" max="223" value="${x.action_port||1}" style="width:70px" title="${t('端口')}">
        <label style="margin:0;display:flex;align-items:center;gap:4px"><input type="checkbox" id="au_confirmed" ${x.action_confirmed?'checked':''}>${t('确认')}</label>
      </div>`;
  } else {
    body.innerHTML = `
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="margin:0;min-width:64px">${t('通知组')}</label>
        <select id="au_grp" style="flex:1">
          <option value="0">${t('请选择通知组')}</option>
          ${(__au.groups||[]).map(g=>`<option value="${g.id}" ${String(g.id)===String(x.notify_group_id||0)?'selected':''}>${esc(g.name)}</option>`).join('')}
        </select>
      </div>`;
  }
}
async function autoSave(){
  try{
    const d = __au.modal;
    const body = {
      name: document.getElementById('au_name').value,
      application_id: +document.getElementById('au_app').value,
      trigger_device_id: +document.getElementById('au_tdev').value,
      trigger_field: (document.getElementById('au_field').value||'').trim(),
      trigger_operator: document.getElementById('au_op').value,
      trigger_value: (document.getElementById('au_val').value||'').trim(),
      cooldown_seconds: parseInt(document.getElementById('au_cd').value,10)||60,
      action_type: document.querySelector('input[name="au_atype"]:checked').value,
      action_device_id: +((document.getElementById('au_adev')||{}).value||0),
      action_port: parseInt((document.getElementById('au_port')||{value:1}).value,10)||1,
      action_payload_hex: ((document.getElementById('au_payload')||{value:''}).value||'').replace(/[\s:]/g,''),
      action_confirmed: (document.getElementById('au_confirmed')||{}).checked?1:0,
      notify_group_id: +((document.getElementById('au_grp')||{value:0}).value||0),
      enabled: document.getElementById('au_enabled').checked?1:0,
    };
    if(!body.trigger_field){ toast(t('请填写触发字段'),'err'); return; }
    if(body.action_type==='notify' && !body.notify_group_id){ toast(t('请选择通知组'),'err'); return; }
    const r = d.id
      ? await api('PUT','/api/automations/'+d.id, body)
      : await api('POST','/api/automations', body);
    if(r && r.error){ toast(String(r.error),'err'); return; }
    toast(t('已保存'),'ok'); closeModal(); autoReload();
  }catch(e){ toast(t('保存失败')+'：'+e.message,'err'); }
}
async function autoToggle(id,enabled){
  const x = __au.list.find(r=>+r.id===+id); if(!x) return;
  const r = await api('PUT','/api/automations/'+id, {enabled: enabled?1:0});
  if(r && r.error){ toast(String(r.error),'err'); return; }
  autoReload();
}
async function autoDel(id){
  const ok = await new Promise(res=>confirmDlg(t('确定删除该联动？'), res));
  if(!ok) return;
  const r = await api('DELETE','/api/automations/'+id);
  if(r && r.error){ toast(String(r.error),'err'); return; }
  toast(t('已删除'),'ok'); autoReload();
}

async function viewRoles(){
  const r = await api('GET','/api/roles'); const roles=r.data||[]; const catalog=r.catalog||{};
  __rbc.catalog = catalog; __rbc.roles = roles;
  const rows = roles.map(row=>{
    const isSys = row.is_system || row.isSystem;
    const permChips = Array.isArray(row.permissions) ? row.permissions.map(p=>`<span class="tag">${esc(catalog[p]||p)}</span>`).join('') : '';
    const gwUnl = row.gateways_unlimited || row.gatewaysUnlimited;
    const gwTxt = gwUnl ? t('无限制') : ((+row.gateways_limit||+row.gatewaysLimit||0) ? `${t('上限')} ${row.gateways_limit||row.gatewaysLimit}` : t('未配置'));
    const devTxt = (+row.devices_limit||+row.devicesLimit||0) ? `${t('上限')} ${row.devices_limit||row.devicesLimit}` : t('未配置');
    const actions = isSys
      ? ''
      : `<button class="btn ghost" onclick="roleEdit(${row.id})">${ICON.pencilSquare}${t('编辑')}</button> <button class="btn danger" onclick="roleDel(${row.id})">${ICON.trash}${t('删除')}</button>`;
    return `<tr><td>${esc(row.name)}</td><td class="muted">${esc(row.description||'')}</td><td><div style="display:flex;flex-wrap:wrap;gap:4px">${permChips}</div></td><td class="muted">${devTxt}</td><td class="muted">${gwTxt}</td><td class="muted">${row.user_count||row.userCount||0}</td><td style="white-space:nowrap">${adminBtn(actions)}</td></tr>`;
  }).join('')||`<tr><td colspan="7" class="muted">${t('暂无角色')}</td></tr>`;
  document.getElementById('view').innerHTML = `
    <div class="view-head"><h2>${ICON.shieldCheck||''}${t('角色管理')}</h2>${adminBtn(`<button onclick="roleNew()">${ICON.plus}${t('新建角色')}</button>`)}</div>
    <div class="card" style="padding:4px 0"><table><thead><tr><th>${t('名称')}</th><th>${t('描述')}</th><th>${t('权限')}</th><th>${t('设备上限')}</th><th>${t('私有网关上限')}</th><th>${t('用户数')}</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>
    <div class="muted" style="font-size:12px;margin-top:8px">${t('内置角色只读；自定义角色可设设备/网关配额')}</div>`;
}
function roleNew(){
  const defPerms = Object.keys(__rbc.catalog||{}).filter(k=>['dashboard','devices','alerts','uplinks','downlinks'].indexOf(k)!==-1);
  roleModal({id:0,name:'',description:'',permissions:defPerms,devices_limit:0,gateways_limit:0,gateways_unlimited:0});
}
function roleEdit(id){ const x=__rbc.roles.find(r=>+r.id===+id); if(!x) return; roleModal(x); }
function rlPermAll(){
  document.querySelectorAll('.rl_perm').forEach(c=>{ c.checked=true; });
  rlSyncChips();
}
function rlPermNone(){
  document.querySelectorAll('.rl_perm').forEach(c=>{ c.checked=false; });
  rlSyncChips();
}
function rlPermInv(){
  document.querySelectorAll('.rl_perm').forEach(c=>{ c.checked=!c.checked; });
  rlSyncChips();
}
function rlGroupToggle(group, on){
  document.querySelectorAll(`.rl_perm[data-group="${group}"]`).forEach(c=>{ c.checked=on; });
  rlSyncChips();
  rlSyncGroupBtn(group);
}
function rlSyncChips(){
  document.querySelectorAll('.rl-chip').forEach(chip=>{
    const cb = chip.querySelector('.rl_perm');
    chip.classList.toggle('on', !!(cb && cb.checked));
  });
  document.querySelectorAll('.rl_perm[data-group]').forEach(cb=>rlSyncGroupBtn(cb.dataset.group));
}
function rlSyncGroupBtn(gi){
  const cbs = document.querySelectorAll(`.rl_perm[data-group="${gi}"]`);
  if (!cbs.length) return;
  const all = Array.from(cbs).every(c=>c.checked);
  const btn = document.querySelector(`.rl-group-all[data-group="${gi}"]`);
  if (btn) btn.textContent = all ? t('取消全选') : t('全选');
}
function rlGwToggle(){
  const cb = document.getElementById('rl_gw_unlimited');
  if (!cb) return;
  const div = document.getElementById('rl_gw_limit_div');
  if (div) div.style.display = cb.checked ? 'none' : '';
}
function roleModal(x){
  const isNew = !x.id;
  const isSys = x.is_system || x.isSystem;
  const cats = __rbc.catalog||{};
  const groups = [
    ['运行监控', ['dashboard','uplinks','downlinks','events','noc','map']],
    ['设备管理', ['applications','devices','gateways','device-profiles','multicast-groups']],
    ['数据管理', ['thing-models','dashboard-data','alerts','notification-groups','scheduled','automations']],
    ['工具集成', ['integrations','api-keys','api-logs','apidocs','loracalc']],
  ].map(([g,ks])=>[t(g),ks]);
  const has = (k)=>Array.isArray(x.permissions) && x.permissions.indexOf(k)!==-1;
  const boxes = groups.map(([glabel,ks],gi)=>{
    const chips = ks.map(k=>`<label class="rl-chip ${has(k)?'on':''}"><input type="checkbox" class="rl_perm" data-group="${gi}" value="${k}" ${has(k)?'checked':''} onchange="rlSyncChips()">${esc(cats[k]||k)}</label>`).join('');
    return `<div class="rl-group">
      <div class="rl-group-head"><span>${t(glabel)}</span><button type="button" class="rl-group-all" data-group="${gi}" onclick="rlGroupToggle(${gi}, document.querySelectorAll('.rl_perm[data-group=\\"${gi}\\"]').length !== document.querySelectorAll('.rl_perm[data-group=\\"${gi}\\"]:checked').length)">${t('全选')}</button></div>
      <div class="rl-chips">${chips}</div>
    </div>`;
  }).join('');
  const gwUnl = (x.gateways_unlimited ?? (x.gatewaysUnlimited ? 1 : 0)) ? true : false;
  const sysNote = isSys ? `<div class="rl-sec"><div class="rl-sec-title"><h4>${t('内置角色')}</h4><span class="muted">${t('内置角色不可编辑/删除，仅可查看')}</span></div></div>` : '';
  openModal(`<h3>${isNew?t('新建角色'):t('编辑角色')}</h3>
    <div class="rl-sec">
      <div class="row">
        <div><label>${t('角色名称')}</label><input id="rl_name" value="${esc(x.name||'')}" ${isSys?'disabled':''}></div>
        <div><label>${t('描述')}</label><input id="rl_desc" value="${esc(x.description||'')}" ${isSys?'disabled':''}></div>
      </div>
    </div>
    <div class="rl-sec">
      <div class="rl-sec-title">
        <h4>${t('权限')}</h4>
        <div class="rl-bulk">
          <button type="button" onclick="rlPermAll()">${t('全选')}</button>
          <button type="button" onclick="rlPermNone()">${t('清空')}</button>
          <button type="button" onclick="rlPermInv()">${t('反选')}</button>
        </div>
      </div>
      ${isSys?'':`<div class="rl-groups">${boxes}</div>`}
      ${isSys?`<div class="rl-chips">${groups.map(([glabel,ks])=>ks.map(k=>`<label class="rl-chip ${has(k)?'on':''}" style="cursor:default"><input type="checkbox" ${has(k)?'checked':''} disabled>${esc(cats[k]||k)}</label>`).join('')).join('')}</div>`:''}
    </div>
    ${isSys?'':`<div class="rl-sec">
      <div class="rl-sec-title"><h4>${t('资源配额')}</h4><span class="muted">${t('绑定该角色的用户创建设备/网关时受此配额约束')}</span></div>
      <div class="rl-quota">
        <div class="rl-quota-card">
          <label>${t('设备上限')}</label>
          <input id="rl_dev_limit" type="number" min="0" value="${+x.devices_limit||+x.devicesLimit||0}">
          <div class="muted">${t('0 = 不限制设备数量')}</div>
        </div>
        <div class="rl-quota-card">
          <label>${t('私有网关限额')}</label>
          <label class="rl-switch"><input type="checkbox" id="rl_gw_unlimited" ${gwUnl?'checked':''} onchange="rlGwToggle()"><span>${t('无限制')}</span></label>
          <div id="rl_gw_limit_div" style="${gwUnl?'display:none':''}">
            <input id="rl_gw_limit" type="number" min="0" value="${+x.gateways_limit||+x.gatewaysLimit||0}">
            <div class="muted">${t('0 = 不允许创建私有网关；正值 = 上限')}</div>
          </div>
        </div>
      </div>
    </div>`}
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
      <button class="ghost" onclick="closeModal()">${t('取消')}</button>
      ${isSys?'':`<button onclick="busy('保存中…', ()=>roleSave(${x.id||0}))">${t('保存')}</button>`}
    </div>`, {wide:true});
  rlGwToggle();
}
async function roleSave(id){
  const perms = Array.from(document.querySelectorAll('.rl_perm')).filter(c=>c.checked).map(c=>c.value);
  if(!perms.length){ toast(t('至少勾选一项权限'),'err'); return; }
  const gwUnl = document.getElementById('rl_gw_unlimited').checked;
  const body = {
    name: v('rl_name'), description: v('rl_desc'), permissions: perms,
    devices_limit: +v('rl_dev_limit')||0,
    gateways_limit: gwUnl ? 0 : (+v('rl_gw_limit')||0),
    gateways_unlimited: gwUnl ? 1 : 0,
  };
  const r = id ? await api('PUT','/api/roles/'+id, body) : await api('POST','/api/roles', body);
  if(r && r.error){ toast(String(r.error),'err'); return; }
  closeModal(); toast(t('已保存'),'ok'); viewRoles();
}
async function roleDel(id){
  const ok = await new Promise(res=>confirmDlg(t('确定删除该角色？'), res));
  if(!ok) return;
  let r;
  try { r = await api('DELETE','/api/roles/'+id); }
  catch(e){ toast(String(e.message||e).replace(/^HTTP \d+：/,'').replace(/[{}\[\]"]/g,'').slice(0,120),'err'); return; }
  if(r && r.error){ toast(String(r.error),'err'); return; }
  toast(t('已删除'),'ok'); viewRoles();
}

window.__rbc = window.__rbc || { roles:[], catalog:{} };
