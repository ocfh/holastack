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
const frameBtn = (id, fn) => `<button class="raw-btn" title="帧结构检视" onclick="${fn}(${id})">${ICON.codeBracket}</button>`;

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
  while(i+2<=b.length){
    const chan=b[i], type=b[i+1]; i+=2;
    let r=null;
    if(type===0x00||type===0x01){ const x=need(1); if(x===null)break; r={type:(type===0?'digital_in':'digital_out'), value:x[0]}; }
    else if(type===0x02||type===0x03){ const x=need(2); if(x===null)break; r={type:(type===0x02?'analog_in':'analog_out'), value:((x[0]*256+x[1])/100)}; }
    else if(type===0x65){ const x=need(2); if(x===null)break; r={type:'luminosity', value:(x[0]*256+x[1])}; }
    else if(type===0x66){ const x=need(1); if(x===null)break; r={type:'presence', value:x[0]}; }
    else if(type===0x67){ const x=need(2); if(x===null)break; const v=(x[0]<<8)|x[1]; const s=(v&0x8000)?(v-0x10000):v; r={type:'temperature', value:+(s/10).toFixed(1)}; }
    else if(type===0x68){ const x=need(1); if(x===null)break; r={type:'humidity', value:+(x[0]/2).toFixed(1)}; }
    else if(type===0x71){ const x=need(3); if(x===null)break; const v=(x[0]<<16)|(x[1]<<8)|x[2]; r={type:'barometric_pressure', value:+((v/10)-6553.6).toFixed(1)}; }
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
let __liveES=null;
async function toggleLiveEvents(){
  if(__liveES){ __liveES.close(); __liveES=null; state.live=false; toast('已关闭实时事件流','info'); viewEvents(); return; }
  state.live=true; toast('已开启实时事件流','ok'); viewEvents();
  __liveES=new EventSource('/api/stream?token='+encodeURIComponent(state.token||''));
  __liveES.onmessage=e=>{ try{ const d=JSON.parse(e.data); if(d&&d.id&&state.view==='events') viewEvents(); }catch(_){} };
  __liveES.onerror=()=>{ };
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
  document.getElementById('view').innerHTML = `<div class="view-head"><h2>${ICON[VIEW_ICONS['events']]||''}网关日志</h2><div style="display:flex;gap:10px;align-items:center;margin-left:auto"><button class="btn ghost" onclick="exportCapture('events','json')">导出JSON</button><button class="btn ghost" onclick="exportCapture('events','csv')">导出CSV</button><button class="btn danger" onclick="clearPageLogs('events')">${ICON.trash}${t('清空日志')}</button> <button id="liveBtn" class="btn ghost ${state.live?'on':''}" onclick="toggleLiveEvents()">${state.live?'● 实时中':'实时'}</button> ${logRefreshCtrl()}</div></div>
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
    cellValue: (u, k) => ({id:u.id, username:u.username, email:u.email||'', role:u.role, tenant:u.tenant_id||0, time:u.created_at}[k]),
    cols:[
      {key:'id',       label:'ID',       type:'num', firstDir:'asc', sortable:false},
      {key:'username', label:'用户名',    type:'str', firstDir:'asc', sortable:false},
      {key:'email',    label:'邮箱',      type:'str', firstDir:'asc', sortable:false},
      {key:'role',     label:'角色',      type:'str', firstDir:'asc', sortable:false},
      {key:'tenant',   label:'用户配置',  type:'num', firstDir:'asc', sortable:false},
      {key:'time',     label:'创建时间',  type:'time', firstDir:'desc'},
      {key:'_raw',     label:'',         type:'raw'},
    ],
    rows: state.users,
    rowHtml: u => `<tr><td>${u.id}</td><td>${esc(u.username)}</td><td class="muted">${u.email?esc(u.email):'—'}</td><td><span class="tag">${u.role}</span></td>
     <td class="muted">${u.tenant_id ? esc(u.tenant_name || ('#用户配置'+u.tenant_id)) : '—'}</td>
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
        <div style="display:flex;flex-direction:column;gap:10px">
          ${maintRow('上行消息日志','uplinks')}
          ${maintRow('下行消息日志','downlinks')}
          ${maintRow('网关日志','events')}
        </div>
      </div>
      <div class="st-cat hidden" id="stcat-map">
        <h3>${ICON.map}地图服务</h3>
        <label>地图提供商（用于「位置地图」页渲染，可选）</label>
        <select id="st_mapprov">${window.MAP_PROVIDERS.map(p=>`<option value="${p.id}" ${s.map_provider===p.id?'selected':''}>${esc(p.name)}${p.needKey?'（需 Key）':''}</option>`).join('')}</select>
        <label>自定义瓦片 URL（选择「自定义瓦片 URL」时使用，Leaflet 占位符 {z}/{x}/{y}；含 token 可用 KEY 占位）</label>
        <input id="st_mapurl" value="${val('map_url')}" placeholder="https://your-tile-server.com/{z}/{x}/{y}.png?token=KEY">
        <label>API Key（下发给需要 Key 的提供商 / 填到上面的 KEY 占位）</label><input id="st_mapkey" value="${val('map_key')}" placeholder="粘贴地图提供商的访问令牌">
        <p class="muted" style="margin:2px 0 0">无需 Key 的国内底图：高德 / 腾讯（GCJ-02）可直接出图。天地图（矢量 / 影像）免费但需先在 <a href="https://console.tianditu.gov.cn/api/key" target="_blank" rel="noopener">tianditu.gov.cn</a> 申请 tk 并填到「API Key」。百度、Mapbox、MapTiler 及您自己的服务器也需 Key / URL。国内坐标系（高德/腾讯=GCJ-02，百度=BD-09，天地图=WGS84）与设备侧 WGS84 坐标存在数十米偏移，属正常现象。</p>
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
// 站点设置分类菜单：PC 侧栏 / 移动横条共用，按文字长度由短到长排序
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

function maintRow(labelKey, target){
  return `<div style="display:flex;align-items:center;justify-content:space-between;border:1px solid var(--line);border-radius:8px;padding:10px 12px">
    <span>${t(labelKey)}</span>
    <button class="btn danger" onclick="clearLogs('${target}','${labelKey}')">${ICON.trash}${t('清空日志')}</button>
  </div>`;
}
async function clearLogs(target, labelKey){
  if (!confirm(t('确认清空') + ' ' + t(labelKey) + '？' + t('此操作不可恢复'))) return;
  const r = await api('POST','/api/settings',{clear_logs: target});
  if (r.error){ alert(t(r.error)); return; }
  toast(t('已清空') + ' ' + t(labelKey), 'ok');
}
// 每页右上角的"清空日志"：按当前用户/页面作用域清理，避免误清他人数据
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
  // 作用域：租户只能清自己；admin 仅当该页确实有租户筛选时才按筛选清，否则清全部
  let tid = 0;
  if (isTenant()) {
    tid = state.user.tenant_id || 0;          // 租户：强制只清自己租户
  } else if (target === 'api') {
    tid = (state.apiLogFilter && state.apiLogFilter.tenant_id) ? state.apiLogFilter.tenant_id : 0;
  } else if (target === 'events') {
    tid = state.tenantFilter || 0;            // 网关日志页自带租户筛选
  } else {
    tid = 0;                                  // 上行/下行页无租户筛选，admin 清全部
  }
  const scopeTxt = tid ? t('（仅清理当前用户配置）') : t('（将清空全部）');
  if (!confirm(t('确认清空') + ' ' + t(label) + '？' + t('此操作不可恢复') + scopeTxt)) return;
  const r = await api('POST','/api/settings',{clear_logs: apiTarget, clear_logs_tenant: tid});
  if (r.error){ alert(t(r.error)); return; }
  toast(t('已清空') + ' ' + t(label) + (tid ? t('（当前用户配置）') : t('（全部）')), 'ok');
  refresh();
}
// 日志页自动刷新（原计算器 lc-refresh 的自动刷新下拉，迁移到右下角悬浮组件）：手动/5s/10s/15s/30s/1m 轮询当前日志页
let logRefreshTimer = null;
const LOG_REFRESH_VIEWS = ['uplinks','downlinks','events','api-logs'];
const LOG_REFRESH_OPTS = [[0,'停止刷新'],[5,'5 秒'],[10,'10 秒'],[15,'15 秒'],[30,'30 秒'],[60,'1 分钟']];
let refreshFloatOpen = false;
let logRefreshTarget = null;

// 头部不再显示大号下拉，改为右下角悬浮窗（见 renderRefreshFloat）
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
  // 将当前日志页的刷新间隔保存到浏览器，下次进入沿用
  try { if (LOG_REFRESH_VIEWS.includes(state.view)) localStorage.setItem('elw_refresh_'+state.view, String(sec)); } catch(e){}
  if (sec>0){
    const target = state.view;
    logRefreshTimer = setInterval(()=>{
      // 仅当用户仍停留在该日志页（以地址栏 hash 为准）才静默刷新，否则停止，避免静默渲染把用户拉回日志页
      if (((location.hash||'').slice(1)||'dashboard') !== target){ stopLogRefresh(); return; }
      nav(target, true);
    }, sec*1000);
  }
  renderRefreshFloat();
}
// 进入日志页时，读取本地保存的刷新间隔并延续自动刷新；若已在该页运行则跳过以免重置定时器
function restoreLogRefresh(){
  const v = state.view;
  if (!LOG_REFRESH_VIEWS.includes(v)) return;
  if (logRefreshTimer && logRefreshTarget === v) return;
  // 未显式存储过时默认 10 秒（用户预期默认值）；已存储的值（含 0=停止刷新）一律沿用
  let sec = 10;
  try { const raw = localStorage.getItem('elw_refresh_'+v); sec = raw==null ? 10 : parseInt(raw,10); } catch(e){ sec = 10; }
  setLogRefresh(isNaN(sec) ? 0 : sec);
}
// 移动端悬浮主操作按钮（新建/清空日志），显示在右下角"回顶/回底"按钮上方；清空为红色
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
    <label>新密码（≥6 位）</label><input id="m_pw_new" type="password">
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
    <label>新密码（≥6 位）</label><input id="m_pw_new" type="password">
    <label>确认新密码</label><input id="m_pw_cfm" type="password">
    <div id="pw_err" class="muted" style="color:var(--err)"></div>
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
  const [ra, tf] = await Promise.all([api('GET','/api/applications'+(tq?'?'+tq:'')), tenantFilterHtml()]);
  state.apps = ra.data||[];
  const opts=`<option value="">选择应用…</option>`+state.apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(state.appSel)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('');
  let ks=[];
  if(state.appSel){
    const r=await api('GET','/api/api-keys?app_id='+state.appSel+(tq?'&'+tq:'')); ks=r.data||[];
  }
  const akCfg = {
    state, stateKey:'apiKeysSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (k, ck) => ({id:k.id, name:k.name, token:k.token_preview||'', time:k.created_at}[ck]),
    cols:[
      {key:'id',    label:'ID',          type:'num', firstDir:'asc', sortable:false},
      {key:'name',  label:'名称',         type:'str', firstDir:'asc', sortable:false},
      {key:'token', label:'Token(预览)', type:'str', firstDir:'asc', sortable:false},
      {key:'time',  label:'创建时间',     type:'time', firstDir:'desc'},
      {key:'_raw',  label:'',            type:'raw'},
    ],
    rows: ks,
    rowHtml: k => `<tr><td>${k.id}</td><td>${esc(k.name)}</td><td class="muted"><code>${esc(k.token_preview)}…</code></td><td class="muted">${new Date(k.created_at*1000).toLocaleString()}</td>
      <td>${adminBtn(`<button class="btn danger" onclick="busy('删除中…', ()=>delApiKey(${k.id}))">${ICON.trash}删除</button>`)}</td></tr>`,
    emptyText: state.appSel ? '该应用暂无 API 密钥' : '请先在上方选择应用',
  };
  const [filteredKeys, keysTotal] = filterAndSortRows(akCfg);
  akCfg.rows = paginateRows(filteredKeys, state, {pageKey:'apiKeysPage', limitKey:'apiKeysLimit', offsetKey:'apiKeysOffset'})[0];
  akCfg.presorted = true;
  const table = buildSortableTable(akCfg);
  const pager = buildPager({ total: keysTotal, limit: state.apiKeysLimit, offset: state.apiKeysOffset, pageKey:'apiKeysPage', limitKey:'apiKeysLimit', offsetKey:'apiKeysOffset', totalKey:'apiKeysTotal', refresh:'viewApiKeys' });
  window.apiKeysSort_sort = col => _tableToggleSort('apiKeysSort','viewApiKeys',col);
  window.viewApiKeys__page = p => _pagerGo({pageKey:'apiKeysPage',limitKey:'apiKeysLimit',offsetKey:'apiKeysOffset',totalKey:'apiKeysTotal'},'viewApiKeys',p);
  window.viewApiKeys__limit = l => _pagerSetLimit({pageKey:'apiKeysPage',limitKey:'apiKeysLimit',offsetKey:'apiKeysOffset',totalKey:'apiKeysTotal'},'viewApiKeys',l);
  document.getElementById('view').innerHTML=`<div class="view-head"><h2>${ICON[VIEW_ICONS['api-keys']]||''}API 密钥</h2></div>
   <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 360px"><label>应用</label><select id="ak_app" onchange="state.appSel=this.value;state.apiKeysPage=1;state.apiKeysOffset=0;nav('api-keys')">${opts}</select></div><button class="btn ghost" onclick="resetFilters(()=>{state.appSel='';state.apiKeysPage=1;state.apiKeysOffset=0;state.apiKeysLimit=50;state.apiKeysSort={col:'time',dir:'desc'};}, viewApiKeys)">${ICON.arrowPath}重置</button>${state.appSel?adminBtn('<button onclick="newApiKey()">'+ICON.plus+'新建 API 密钥</button>'):''}</div>
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
    return it.kind==='HTTP' ? (cfg.url||'') : it.kind==='INFLUX_DB' ? (cfg.endpoint||'') : it.kind==='MQTT_GLOBAL' ? (cfg.server||'') : it.kind==='AWS_SNS' ? (cfg.topic_arn||'') : it.kind==='AZURE_SERVICE_BUS' ? (cfg.publish_name||'') : it.kind==='GCP_PUBSUB' ? (cfg.topic_name||'') : it.kind==='AMQP' ? (cfg.url||'') : it.kind==='KAFKA' ? (cfg.topic||'') : '';
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

/* ----------------------------------------------------------------------------
 * NOC 仪表盘 (网络运维中心)
 * ------------------------------------------------------------------------- */
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

/* ----------------------------------------------------------------------------
 * 位置地图 (设备 / 网关地理分布)
 * ------------------------------------------------------------------------- */
// ----------------------------------------------------------------------------
// 位置地图（设备 / 网关地理分布）—— 第三方地图 API 接入（Leaflet 瓦片）
// ----------------------------------------------------------------------------
// 可在站点设置选择提供商；无需 Key 的可直接用，needKey=1 的需在设置里填 Key。
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
  // 天地图：vec_w/img_w/ter_w 为底图（无注记），叠加 cva_w 矢量注记层显示地名/路名
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
/* ---------- 天地图官方 JS API（T.Map）渲染 ---------- */
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
    // tk 变化：清掉旧实例与旧脚本，避免拿到旧授权
    try { if (window.__tdtMap && window.__tdtMap.destroy) window.__tdtMap.destroy(); } catch(e){}
    window.__tdtMap = null; window.T = undefined;
    document.querySelectorAll('script[data-tdt-sdk]').forEach(s => { try{ s.remove(); }catch(e){} });
    const sb = document.createElement('script');
    sb.setAttribute('data-tdt-sdk', '1');
    sb.src = 'https://api.tianditu.gov.cn/api?v=4.0&tk=' + encodeURIComponent(tk);
    sb.onload = () => { window.__tdtTk = tk; setTimeout(res, 60); };
    sb.onerror = () => { window.__tdtTk = null; res(); };
    document.head.appendChild(sb);
    setTimeout(res, 12000); // 兜底超时，防网络挂起卡死
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

  // 图层切换（矢量 / 影像 / 影像+注记 / 地形）
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
  // 自定义瓦片 URL：用站点设置里填写的 map_url 作为瓦片地址
  const tileUrl = (prov && prov.id==='custom') ? (set.map_url||'') : (prov && prov.url || '');
  const name = (prov && prov.id==='custom') ? '自定义瓦片 URL' : (prov ? prov.name : '');
  // 天地图：走官方 JS API（T.Map），需填 tk
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

// ===================================================================
// 帧检视：LoRaWAN PHY 逐字段解析
// ===================================================================
function b2h(arr, sep){ return (arr||[]).map(x=>('0'+((x&0xff)>>>0).toString(16)).slice(-2)).join(sep||''); }
function rev(arr){ return (arr||[]).slice().reverse(); }

function parseLoraFrame(hexPlain){
  const b = hexToBytes(hexPlain);
  const out = {rows:[]};
  if (!b.length){ out.error='空帧（无 phy_payload）'; return out; }
  const mhdr=b[0];
  const mtype=(mhdr>>5)&0x07, major=mhdr&0x03;
  const MT={0:'Join-request',1:'Join-accept',2:'Unconfirmed Data Up',3:'Unconfirmed Data Down',4:'Confirmed Data Up',5:'Confirmed Data Down',6:'RFU(6)',7:'Proprietary'};
  out.rows.push({k:'MHDR', v:b2h([mhdr]), d:`MType=${mtype} → ${MT[mtype]||'?'}; Major=${major}${major===0?' (LoRaWAN R1)':''}`});
  if (mtype===0){ // Join-request
    if (b.length<23){ out.error=`Join-request 长度不足（${b.length} 字节，需 23）`; return out; }
    out.rows.push({k:'AppEUI', v:b2h(b.slice(1,9)), d:'空口小端，网络序 '+b2h(rev(b.slice(1,9)))});
    out.rows.push({k:'DevEUI', v:b2h(b.slice(9,17)), d:'空口小端，网络序 '+b2h(rev(b.slice(9,17)))});
    out.rows.push({k:'DevNonce', v:b2h(b.slice(17,19))});
    out.rows.push({k:'MIC', v:b2h(b.slice(19,23))});
    out.note='MIC 需 AppKey 验证，由 NS 完成；此处仅展示原始字节。';
    return out;
  }
  if (mtype===1){ // Join-accept（空口为密文）
    if (b.length<17){ out.error='Join-accept 长度不足'; return out; }
    out.rows.push({k:'(密文主体)', v:b2h(b.slice(1,b.length-4)), d:'Join-accept 在空口为 AES 加密，需 AppKey 解密后才能解析 AppNonce/NetID/DevAddr/DLSettings/RxDelay/CFList'});
    out.rows.push({k:'MIC', v:b2h(b.slice(b.length-4)), d:'末 4 字节（密文内）'});
    out.note='Join-accept 为加密帧，解密由 NS 完成。';
    return out;
  }
  if (mtype>=2 && mtype<=5){ // 数据帧 上行/下行
    if (b.length<8){ out.error='数据帧长度不足'; return out; }
    const up = (mtype===2||mtype===4);
    const devAddr=b.slice(1,5);
    out.rows.push({k:'DevAddr', v:b2h(devAddr), d:'空口小端，网络序 '+b2h(rev(devAddr))});
    const fctrl=b[5];
    const foptsLen = fctrl&0x0f;
    const flags=[];
    if (fctrl&0x80) flags.push('ADR');
    flags.push(up ? ((fctrl&0x40)?'ADRACKReq':'') : ((fctrl&0x40)?'FPending':''));
    if (fctrl&0x20) flags.push('ACK');
    if (fctrl&0x10) flags.push('ClassB(FCtrl.b4)');
    out.rows.push({k:'FCtrl', v:b2h([fctrl]), d:`${(flags.filter(Boolean).join(' / ')||'无标志')} · FOptsLen=${foptsLen}`});
    const fcnt = b[6] | (b[7]<<8);
    out.rows.push({k:'FCnt (低16位)', v:String(fcnt), d:'完整 FCnt 由 NS 按设备会话上下文补全'});
    let p=8;
    if (foptsLen>0){
      const fopts=b.slice(p,p+foptsLen);
      out.rows.push({k:'FOpts', v:b2h(fopts), d: foptsLen===15?'MAC 命令占满，无 FPort/FRMPayload':'MAC 命令（'+foptsLen+' 字节）'});
      p+=foptsLen;
    }
    const remain=b.length-p;
    if (remain>4){
      const fport=b[p]; p++;
      out.rows.push({k:'FPort', v:String(fport), d: fport===0?'MAC 层（FRMPayload 为 MAC 命令）':'应用层'});
      const payload=b.slice(p, b.length-4);
      out.rows.push({k:'FRMPayload', v:b2h(payload)||'(空)', d:'应用负载（若已配置会话密钥，NS 已解密后存储为 decrypted_hex）'});
      out.rows.push({k:'MIC', v:b2h(b.slice(b.length-4))});
      out.note='MIC 需 NwkSKey 验证，NS 侧已校验；FRMPayload 解密需 AppSKey/NwkSKey。';
    } else if (remain===4){
      out.rows.push({k:'MIC', v:b2h(b.slice(p,p+4)), d:'无 FPort/FRMPayload（纯 MAC/确认帧）'});
    } else {
      out.error=`帧尾部长度异常（剩余 ${remain} 字节，应 ≥4 用于 MIC）`;
    }
    return out;
  }
  out.rows.push({k:'Payload', v:b2h(b.slice(1)), d:'专有/RFU 帧，按透传处理'});
  return out;
}

async function frameInspector(id){
  const rec = (state.ups||[]).find(x=>x.id===id) || (state.dls||[]).find(x=>x.id===id);
  if (!rec){ toast('未找到该记录','err'); return; }
  let phy = rec.phy_payload || '';
  if (!phy && rec.raw_json){ try{ const j=JSON.parse(rec.raw_json); phy = j.phy_payload || (j.txpk&&j.txpk.data) || ''; }catch(e){} }
  if (!phy){
    openModal(`<h3>帧结构检视 #${id}</h3><p class="muted">该记录没有原始帧（phy_payload）可供解析。上行记录通常包含空口帧；若为空，可能是 NS 未记录原始帧。</p><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
    return;
  }
  const parsed = parseLoraFrame(phy);
  const rowsHtml = parsed.error
    ? `<tr><td colspan="3" class="warn-box" style="border:0">${esc(parsed.error)}</td></tr>`
    : parsed.rows.map(r=>`<tr><td class="mono">${esc(r.k)}</td><td class="mono">${esc(r.v)}</td><td class="muted">${esc(r.d||'')}</td></tr>`).join('');
  const noteHtml = parsed.note ? `<p class="muted" style="margin-top:10px">${esc(parsed.note)}</p>` : '';
  openModal(`<h3>帧结构检视 #${id}</h3>
    <p class="muted" style="word-break:break-all">完整帧 (hex)：<code>${esc(phy)}</code></p>
    <div style="position:relative"><button class="ad-copy" onclick="copyText('${phy}')">复制帧</button></div>
    <table class="tbl" style="margin-top:8px"><thead><tr><th>字段</th><th>值 (hex)</th><th>说明</th></tr></thead><tbody>${rowsHtml}</tbody></table>
    ${noteHtml}
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}

// ===================================================================
// 包捕获导出（上行 / 下行 / 事件）
// ===================================================================
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

// ===================================================================
// 解码器模板库
// ===================================================================
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
