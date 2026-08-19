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
    <h2>概览</h2>
    <div class="rings">
      ${dashRingCard('设备', devTotal, devOn, devOff, true)}
      ${dashRingCard('网关', gwTotal, gwOn, gwOff, true)}
      ${dashRingCard('应用', appTotal, 0, 0, false, `<div class="hl-row hl-split"><span>设备模板 <b>${dpsTotal}</b></span><span>组播组 <b>${mcTotal}</b></span></div>`)}
    </div>

    <div class="msg-bar">
      <div class="msg-main"><span class="msg-num">${msgTotal}</span><span class="msg-lbl">消息总数</span></div>
      <div class="msg-split">
        <div><span class="up">▲</span> 上行 <b>${s.uplinks|0}</b></div>
        <div><span class="down">▼</span> 下行 <b>${s.downlinks|0}</b></div>
      </div>
    </div>

    <div class="log-cols">
      <div class="log-col ${devOpen?'':'collapsed'}" data-kind="dev">
        <div class="log-head" onclick="toggleLogCol(this)">
          <h3>最近设备日志</h3>
          <span class="log-fold"><span class="log-chev"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span><span class="log-fold-txt">${devOpen?t('折叠'):t('展开')}</span></span>
          <button class="log-more" onclick="event.stopPropagation();nav('events')">查看全部</button>
        </div>
        <div class="log-body"><div class="log-inner">${devLogs.length? devLogs.map(e=>dashLogRow(e,'dev #'+(e.dev_id||''))).join('') : '<div class="log-empty">暂无设备日志</div>'}</div></div>
      </div>
      <div class="log-col ${gwOpen?'':'collapsed'}" data-kind="gw">
        <div class="log-head" onclick="toggleLogCol(this)">
          <h3>最近网关日志</h3>
          <span class="log-fold"><span class="log-chev"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span><span class="log-fold-txt">${gwOpen?t('折叠'):t('展开')}</span></span>
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
    const who = kind==='dev' ? e => ('dev #'+(e.dev_id||'')) : e => ('网关 '+esc(e.gateway_id||''));
    col.querySelector('.log-body .log-inner').innerHTML = logs.length
      ? logs.map(e=>dashLogRow(e, who(e))).join('')
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
const rawBtn = (id, fn) => `<button class="raw-btn" title="查看原始 JSON" onclick="${fn}(${id})"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg></button>`;

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
     <td>${adminBtn(`<button class="btn ghost" onclick="editApplication(${a.id})">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delApplication(${a.id}))">删除</button>`)} <button class="btn ghost" onclick="newDevice(${a.id})">+ 设备</button></td></tr>`,
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
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>应用</h2>${adminBtn('<button onclick="newApplication()">+ 新建应用</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<button class="btn ghost" onclick="resetFilters(()=>{state.appsPage=1;state.appsOffset=0;state.appsLimit=50;}, viewApplications)">重置</button></div>
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
      if (d.battery!==null && d.battery!==undefined && +d.battery>=0) tel.push('电量'+(+d.battery===0?'外电':(+d.battery)+'%'));
      if (d.margin!==null && d.margin!==undefined && d.margin!=='') tel.push('余量'+(+d.margin)+'dB');
      if (d.latitude && +d.latitude!==0 && d.longitude!==null) tel.push('GPS '+ (+d.latitude).toFixed(5)+','+(+d.longitude).toFixed(5));
      const telStr = tel.length? `<div class="muted" style="font-size:11px">${tel.join(' · ')}</div>`:'';
      const seen = (d.last_seen_fmt && d.last_seen_fmt!=='-') ? d.last_seen_fmt : '-';
      return `<tr>
        <td>${d.id}</td><td>${esc(d.name)}</td>
        <td class="muted"><span class="pill" style="margin:0">${appName(d.app_id)}</span></td>
        <td><span class="tag">${d.activation}</span></td>
        <td><span class="tag ${d.class}">${d.class}</span></td>
        <td><span class="tag ${online?'ok':'off'}">${online?'在线':'离线'}</span></td>
        <td class="muted">${hex(d.dev_eui)}</td><td class="muted">${hex(d.dev_addr)}</td>
        <td><span class="tag ${d.status==='active'?'ok':'pending'}">${d.status}</span></td>
        <td class="muted">${seen}${telStr}</td>
        <td>${adminBtn(`<button class="btn ghost" onclick="editDevice(${d.id})">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delDevice(${d.id}))">删除</button>`)} <button class="btn ghost" onclick="deviceDetail(${d.id})">密钥</button> <button class="btn ghost" onclick="downlink(${d.id})">下行</button></td></tr>`;
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
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>设备</h2>${adminBtn('<button onclick="newDevice()">+ 添加设备</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 240px"><label>按应用筛选</label><select id="devAppFilter" onchange="state.devAppFilter=this.value;viewDevices()">${appOpts}</select></div>
    <button class="btn ghost" onclick="resetFilters(()=>{state.devAppFilter='';state.devsFActivation='';state.devsFCls='';state.devsFOnline='';state.devsFStatus='';state.devsSort={col:'time',dir:'desc'};state.devsPage=1;state.devsOffset=0;state.devsLimit=50;}, viewDevices)">重置</button></div>
    ${table}
    ${pager}`;
}
async function deviceDetail(id){
  const r = await api('GET','/api/devices'); state.devs = r.data||[];
  const d=(state.devs||[]).find(x=>x.id===id); if(!d)return;
  
  const kv=(label,val)=>`<label>${label}</label><input value="${esc(val||'')}" readonly style="cursor:pointer" title="点击自动复制" onclick="copyKeyField(this, '${label}')">`;
  openModal(`<h3>${t('设备密钥')} #${id} ${esc(d.name)}</h3>
    ${kv('DevEUI', d.dev_eui)}
    ${d.activation==='OTAA'
      ? kv('JoinEUI', d.join_eui) + kv('AppKey', d.app_key)
      : kv('DevAddr', d.dev_addr) + kv('NwkSKey', d.nwk_s_key) + kv('AppSKey', d.app_s_key)}
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
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
        <td>${adminBtn(`<button class="btn ghost" onclick="editGateway('${g.gw_id}')">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delGateway('${g.gw_id}'))">删除</button>`)}</td></tr>`;
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
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>网关</h2>${adminBtn('<button onclick="newGateway()">+ 新建网关</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px">${tf}
      <button class="btn ghost" onclick="resetFilters(()=>{state.gwsFOnline='';state.gwsSort={col:'time',dir:'desc'};state.gwsPage=1;state.gwsOffset=0;state.gwsLimit=50;}, viewGateways)">重置</button></div>
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
      <td class="muted"><a href="javascript:void(0)" style="color:var(--acc);text-decoration:none" onclick="deviceDetail(${u.dev_id})">${hex(u.dev_addr)}</a></td>
      <td>${u.fcnt}</td><td>${u.port}</td><td>${(u.confirmed==1||u.confirmed==='1')?'✓':'-'}</td>
      <td class="cell-scroll"><code>${hex(u.decrypted_hex)}</code></td>
      <td class="muted cell-scroll" style="font-family:monospace">${esc(textDisp)}</td>
      <td class="cell-scroll"><code class="muted">${hex(u.phy_payload)}</code></td>
      <td class="muted">${u.gateway_id||'-'}</td>
      <td class="muted">${u.rssi} / ${u.snr}</td>
      <td class="muted">${new Date(u.received_at*1000).toLocaleString()}</td>
      <td>${rawBtn(u.id,'showRaw')}</td></tr>`;
    },
    emptyText:'暂无上行',
  });
  const pager = buildPager({ total: state.upsTotal, limit: state.upsLimit, offset: state.upsOffset, pageKey:'upsPage', limitKey:'upsLimit', offsetKey:'upsOffset', totalKey:'upsTotal', refresh:'viewUplinks' });
  document.getElementById('view').innerHTML = `<h2>上行消息日志</h2>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">
      ${tf}
      <div style="flex:0 0 300px"><label>按应用筛选</label><select id="upAppFilter" onchange="state.upsAppFilter=this.value;state.upsPage=1;state.upsOffset=0;viewUplinks()">${appOpts}</select></div>
      <div style="flex:0 0 300px"><label>按设备筛选</label><select id="upFilter" onchange="state.upsFilter=this.value;state.upsPage=1;state.upsOffset=0;viewUplinks()">${devOpts}</select></div>
      <button class="btn ghost" onclick="resetFilters(()=>{state.upsFilter='';state.upsAppFilter='';state.upsSort={col:'time',dir:'desc'};state.upsFFcnt='';state.upsFPort='';state.upsPage=1;state.upsOffset=0;state.upsLimit=50;}, viewUplinks)">重置</button>
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
function selectModalPre(){
  const pre = document.querySelector('#modalBox pre');
  if (!pre) return;
  const range = document.createRange();
  range.selectNodeContents(pre);
  const sel = window.getSelection();
  sel.removeAllRanges();
  sel.addRange(range);
}
async function showRaw(id){
  const u=(state.ups||[]).find(x=>x.id===id); if(!u)return;
  let j={}; try { j = u.raw_json ? JSON.parse(u.raw_json) : {}; } catch(e){}
  if (!Object.keys(j).length) {
    openModal(`<h3>${t('原始 JSON')} #${id}</h3><p class="muted">该上行无原始协议报文。</p><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
    return;
  }
  openModal(`<h3>${t('原始 JSON')} #${id}</h3><pre>${esc(JSON.stringify(j,null,2))}</pre><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="copyModalPre()">复制</button><button class="ghost" onclick="closeModal()">关闭</button></div>`);
  selectModalPre();
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
        <td>${rawBtn(d.id,'showDownlinkRaw')}</td></tr>`;
    },
    emptyText:'暂无下行',
  });
  const pager = buildPager({ total: state.dlsTotal, limit: state.dlsLimit, offset: state.dlsOffset, pageKey:'dlsPage', limitKey:'dlsLimit', offsetKey:'dlsOffset', totalKey:'dlsTotal', refresh:'viewDownlinks' });
  document.getElementById('view').innerHTML = `<h2>下行消息日志</h2>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">
      ${tf}
      <div style="flex:0 0 300px"><label>按应用筛选</label><select id="dlAppFilter" onchange="state.dlAppFilter=this.value;state.dlsPage=1;state.dlsOffset=0;viewDownlinks()">${appOpts}</select></div>
      <div style="flex:0 0 300px"><label>按设备筛选</label><select id="dlDevFilter" onchange="state.dlDevFilter=this.value;state.dlsPage=1;state.dlsOffset=0;viewDownlinks()">${devOpts}</select></div>
      <button class="btn ghost" onclick="resetFilters(()=>{state.dlDevFilter='';state.dlAppFilter='';state.dlsSort={col:'time',dir:'desc'};state.dlsPage=1;state.dlsOffset=0;state.dlsLimit=50;state.dlsFStatus='';}, viewDownlinks)">重置</button>
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
      <pre>${esc(JSON.stringify(proto, null, 2))}</pre>
      <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="copyModalPre()">复制</button><button class="ghost" onclick="closeModal()">关闭</button></div>`);
    selectModalPre();
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
    <pre>${pretty}</pre>
    <h4 style="margin:16px 0 6px">Payload 十六进制字节</h4>
    <div class="mono" style="line-height:1.9">${hexRows}</div>
    <h4 style="margin:14px 0 6px">ASCII</h4>
    <div class="mono">${esc(ascii)||'(不可打印)'}</div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="copyModalPre()">复制</button><button class="ghost" onclick="closeModal()">关闭</button></div>`);
  selectModalPre();
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
  document.getElementById('view').innerHTML = `<h2>网关日志</h2>
    <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">
      ${tf}
      <div style="flex:0 0 300px"><label>按设备筛选</label><select id="evs_dev" onchange="state.evsDevFilter=this.value; state.evsPage=1; state.evsOffset=0; viewEvents()">${devOpts}</select></div>
      <div style="flex:0 0 300px"><label>按网关筛选</label><select id="evs_gw" onchange="state.evsGwFilter=this.value; state.evsPage=1; state.evsOffset=0; viewEvents()">${gwOpts}</select></div>
      <button class="btn ghost" onclick="resetFilters(()=>{state.evsDevFilter=''; state.evsGwFilter=''; state.evsSort={col:'time',dir:'desc'}; state.evsFType=''; state.evsFLevel=''; state.evsPage=1; state.evsOffset=0; state.evsLimit=50;}, viewEvents)">重置</button>
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
  openModal(`<h3>${t('事件 JSON')} #${id}</h3><pre>${esc(JSON.stringify(j,null,2))}</pre><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="copyModalPre()">复制</button><button class="ghost" onclick="closeModal()">关闭</button></div>`);
  selectModalPre();
}
async function viewUsers(){
  if (!isAdmin()) { nav('dashboard'); return; }
  const r = await api('GET','/api/users'); state.users = r.data||[];
  const userCfg = {
    state, stateKey:'usersSort',
    defaultSort:{col:'time',dir:'desc'},
    cellValue: (u, k) => ({id:u.id, username:u.username, role:u.role, tenant:u.tenant_id||0, time:u.created_at}[k]),
    cols:[
      {key:'id',       label:'ID',       type:'num', firstDir:'asc', sortable:false},
      {key:'username', label:'用户名',    type:'str', firstDir:'asc', sortable:false},
      {key:'role',     label:'角色',      type:'str', firstDir:'asc', sortable:false},
      {key:'tenant',   label:'用户配置',  type:'num', firstDir:'asc', sortable:false},
      {key:'time',     label:'创建时间',  type:'time', firstDir:'desc'},
      {key:'_raw',     label:'',         type:'raw'},
    ],
    rows: state.users,
    rowHtml: u => `<tr><td>${u.id}</td><td>${esc(u.username)}</td><td><span class="tag">${u.role}</span></td>
     <td class="muted">${u.tenant_id ? esc(u.tenant_name || ('#用户配置'+u.tenant_id)) : '—'}</td>
     <td class="muted">${new Date(u.created_at*1000).toLocaleString()}</td>
     <td><button class="btn ghost" onclick="editUser(${u.id})">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delUser(${u.id}))">删除</button> <button class="btn ghost" onclick="changePwFor(${u.id})">改密</button></td></tr>`,
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
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>用户管理</h2><button onclick="newUser()">+ 新建用户</button></div>
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
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>${t('API 调用日志')}</h2><div class="muted" style="font-size:12px">${t('共')} ${state.apiLogTotal} ${t('条')}${t('（仅保留最近 10000 条）')}</div></div>
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
  .st-wrap{display:flex;gap:18px;align-items:flex-start;margin-top:8px}
  .st-side{width:200px;flex:0 0 200px;position:sticky;top:14px;display:flex;flex-direction:column;gap:4px;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:8px 6px}
  .st-item{display:block;width:100%;text-align:left;background:transparent;border:0;color:var(--txt);padding:9px 12px;border-radius:8px;cursor:pointer;font-size:13px}
  .st-item:hover{background:var(--bg-chip)}
  .st-item.active{background:var(--bg-chip);color:var(--txt);font-weight:600}
  .st-main{flex:1;min-width:0;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:20px 24px}
  .st-cat h3{font-size:13px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;margin:0 0 6px;border-bottom:1px solid var(--line);padding-bottom:8px}
  .st-cat.hidden{display:none}
  @media(max-width:860px){.st-wrap{flex-direction:column}.st-side{width:100%;flex:none;position:static;flex-direction:row;overflow-x:auto}.st-main{padding:16px}}
  </style>
  <h2>站点设置</h2>
  <div class="st-wrap">
    <div class="st-side">
      <button class="st-item active" onclick="stCat('basic',this)">基础信息</button>
      <button class="st-item" onclick="stCat('login',this)">登录页</button>
      <button class="st-item" onclick="stCat('footer',this)">页脚与集成</button>
    </div>
    <div class="st-main">
      <div class="st-cat" id="stcat-basic">
        <h3>基础信息</h3>
        <label>网站名称</label><input id="st_name" value="${val('site_name')}" placeholder="HolaStack">
        <label>顶部图标 URL（可选，留空则显示文字名称）</label><input id="st_logo" value="${val('site_logo_url')}" placeholder="https://example.com/logo.png">
        <label>站点 Favicon URL</label><input id="st_favicon" value="${val('favicon_url')}" placeholder="https://example.com/favicon.ico">
        <label>界面语言</label><select id="st_lang">${(window.LANGS||{zh:'中文'}) && Object.entries(window.LANGS||{zh:'中文'}).map(([k,n])=>`<option value="${k}" ${s.ui_lang===k?'selected':''}>${n}</option>`).join('')}</select>
      </div>
      <div class="st-cat hidden" id="stcat-login">
        <h3>登录页</h3>
        <label>登录页 LOGO 图片 URL（可选）</label><input id="st_login_img" value="${val('login_logo_url')}" placeholder="https://example.com/login-logo.png">
        <label>登录页 LOGO 文字（无图片时显示）</label><input id="st_login_text" value="${val('login_logo_text')}" placeholder="HolaStack">
        <label>登录页公告（留空则隐藏公告框，支持多行）</label><textarea id="st_notice" rows="3" placeholder="例如：系统将于本周六 23:00 停机维护。">${esc(s.login_notice||'')}</textarea>
      </div>
      <div class="st-cat hidden" id="stcat-footer">
        <h3>页脚与集成</h3>
        <label>页面底部 Footer（支持 HTML）</label><textarea id="st_footer" rows="2" placeholder="&copy; {Y} {SITE}">${esc(s.footer||'')}</textarea>
        <label>API 基础地址</label><input id="st_api_url" value="${val('api_base_url')}" placeholder="https://your-server.example.com">
      </div>
      <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end">
        <button class="ghost" onclick="nav('dashboard')">取消</button>
        <button onclick="busy('保存中…', saveSettings)">保存</button>
      </div>
    </div>
  </div>`;
}

function stCat(id, btn){
  document.querySelectorAll('.st-cat').forEach(c => c.classList.toggle('hidden', c.id !== 'stcat-'+id));
  document.querySelectorAll('.st-item').forEach(b => b.classList.toggle('active', b === btn));
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
        ln.innerHTML = `<span class="ln-ico"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/></svg></span><span class="ln-txt">${esc(d.login_notice)}</span>`;
        
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
      <td>${adminBtn(`<button class="btn ghost" onclick="editDeviceProfile(${d.id})">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delDeviceProfile(${d.id}))">删除</button>`)}</td></tr>`,
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
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>设备模板</h2>${adminBtn('<button onclick="newDeviceProfile()">+ 新建模板</button>')}</div>
    <div class="row" style="align-items:flex-end;margin-bottom:12px">${tf}
      <button class="btn ghost" onclick="resetFilters(()=>{state.dpsFRegion='';state.dpsFCls='';state.dpsSort={col:null,dir:'desc'};state.dpsPage=1;state.dpsOffset=0;state.dpsLimit=50;}, viewDeviceProfiles)">重置</button></div>
    ${table}
    ${pager}`;
}


async function viewTenants(){
  const r = await api('GET','/api/tenants'); state.tenants = r.data||[];
  const rows = state.tenants.map(row=>{
    const unlimited = +row.private_gateways_unlimited === 1;
    return `<tr><td>${row.id}</td><td>${esc(row.name)}</td><td class="muted">${esc(row.description||'')}</td>
    <td class="muted">${unlimited ? t('无限制') : t('上限') + ' ' + (row.private_gateways_limit||0)}</td>
    <td>${adminBtn(`<button class="btn ghost" onclick="editTenant(${row.id})">${t('编辑')}</button> <button class="btn danger" onclick="busy('删除中…', ()=>delTenant(${row.id}))">${t('删除')}</button>`)}</td></tr>`;
  }).join('')||`<tr><td colspan="5" class="muted">${t('暂无用户配置')}</td></tr>`;
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>${t('用户配置')}</h2>${adminBtn(`<button onclick="newTenant()">${t('+ 新建用户配置')}</button>`)}</div>
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
      <td>${adminBtn(`<button class="btn danger" onclick="busy('删除中…', ()=>delApiKey(${k.id}))">删除</button>`)}</td></tr>`,
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
  document.getElementById('view').innerHTML=`<h2>API 密钥</h2>
   <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 360px"><label>应用</label><select id="ak_app" onchange="state.appSel=this.value;state.apiKeysPage=1;state.apiKeysOffset=0;nav('api-keys')">${opts}</select></div><button class="btn ghost" onclick="resetFilters(()=>{state.appSel='';state.apiKeysPage=1;state.apiKeysOffset=0;state.apiKeysLimit=50;state.apiKeysSort={col:'time',dir:'desc'};}, viewApiKeys)">重置</button>${state.appSel?adminBtn('<button onclick="newApiKey()">+ 新建 API 密钥</button>'):''}</div>
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
        <td>${adminBtn(`<button class="btn ghost" onclick="busy('处理中…', ()=>toggleIntegration(${it.id},${it.enabled?0:1}))">${it.enabled?'停用':'启用'}</button> <button class="btn danger" onclick="busy('删除中…', ()=>delIntegration(${it.id}))">删除</button>`)}</td></tr>`,
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
  document.getElementById('view').innerHTML=`<h2>外部集成</h2>
   <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 360px"><label>应用</label><select id="int_app" onchange="state.intAppSel=this.value;state.intgPage=1;state.intgOffset=0;nav('integrations')">${opts}</select></div>${state.intAppSel?adminBtn('<button onclick="newIntegration()">+ 新建外部集成</button>'):''}</div>
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
     <td>${adminBtn(`<button class="btn ghost" onclick="mcDetail(${m.id})">详情</button> <button class="btn ghost" onclick="editMulticast(${m.id})">编辑</button> <button class="btn danger" onclick="busy('删除中…', ()=>delMulticast(${m.id}))">删除</button>`)}</td></tr>`).join('')||`<tr><td colspan="9" class="muted">暂无组播组</td></tr>`;
  document.getElementById('view').innerHTML=`<div style="display:flex;justify-content:space-between;align-items:center"><h2>组播组</h2>${adminBtn('<button onclick="newMulticast()">+ 新建组播组</button>')}</div>
   <div class="row" style="align-items:flex-end;margin-bottom:12px;gap:16px">${tf}<div style="flex:0 0 360px"><label>按应用筛选</label><select id="mc_app" onchange="state.appSel=this.value;nav('multicast-groups')">${opts}</select></div><button class="btn ghost" onclick="resetFilters(()=>{state.appSel='';}, viewMulticastGroups)">重置</button></div>
   <table><thead><tr><th>ID</th><th>名称</th><th>应用</th><th>区域</th><th>类型</th><th>MC Addr</th><th>DR</th><th>FCnt</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
}
async function mcDetail(id){
  const g = await api('GET',`/api/multicast-groups/${id}`);
  const devs = await api('GET',`/api/multicast-groups/${id}/devices`);
  const gws = await api('GET',`/api/multicast-groups/${id}/gateways`);
  state.mcDetail = {id, g, devs:(devs.data||[]).map(x=>x.dev_eui), gws:(gws.data||[]).map(x=>x.gw_id)};
  const devList=(state.mcDetail.devs.map(e=>`<tr><td><code>${esc(e)}</code></td><td><button class="btn danger" onclick="busy('移除中…', ()=>rmMcDev(${id},'${esc(e)}'))">移除</button></td></tr>`).join(''))||`<tr><td colspan="2" class="muted">暂无设备</td></tr>`;
  const gwList=(state.mcDetail.gws.map(e=>`<tr><td><code>${esc(e)}</code></td><td><button class="btn danger" onclick="busy('移除中…', ()=>rmMcGw(${id},'${esc(e)}'))">移除</button></td></tr>`).join(''))||`<tr><td colspan="2" class="muted">暂无网关（为空则广播到全部网关）</td></tr>`;
  openModal(`<h3>${t('组播组')} #${id} ${esc(g.name||'')}</h3>
   <p class="muted">MC Addr: <code>${esc(g.mc_addr||'')}</code> · 类型 ${g.group_type} · DR${g.dr} · f_cnt ${g.f_cnt} · 应用 #${g.application_id}</p>
   <h4 style="margin-top:6px">下发数据</h4>
   <div class="row"><div style="flex:0 0 120px"><label>端口 (1..223)</label><input id="m_port" value="10"></div><div style="flex:2"><label>Hex 负载</label><input id="m_payload" placeholder="48656c6c6f"></div></div>
   <button onclick="enqueueMc(${id})">加入下发队列</button>
   <h4 style="margin-top:14px">设备（仅用于展示/管理，不参与单播）</h4>
   <div class="row"><div><input id="m_mcdev" placeholder="DevEUI 16 hex" oninput="hexOnly(this)"></div><button onclick="addMcDev(${id})">添加设备</button></div>
   <table style="margin-top:8px"><thead><tr><th>DevEUI</th><th></th></tr></thead><tbody>${devList}</tbody></table>
   <h4 style="margin-top:14px">网关（空=全部网关）</h4>
   <div class="row"><div><input id="m_mcgw" placeholder="Gateway ID" oninput="hexOnly(this)"></div><button onclick="addMcGw(${id})">添加网关</button></div>
   <table style="margin-top:8px"><thead><tr><th>GatewayID</th><th></th></tr></thead><tbody>${gwList}</tbody></table>
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}
