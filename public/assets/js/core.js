function getTheme(){ return document.documentElement.getAttribute('data-theme') || 'dark'; }
function toggleTheme(){
  var next = getTheme() === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('elw_theme', next);
  updateThemeIcon(next);
  
  rerenderForTheme();
}
async function rerenderForTheme(){
  var v = state.view;
  if (v === 'dashboard' && typeof viewDashboard === 'function') await viewDashboard();
  else if (v === 'loracalc' && typeof viewLoraCalc === 'function') await viewLoraCalc();
  else if (v === 'apidocs' && typeof viewApiDocs === 'function') await viewApiDocs();
  applyMobileH2();
}
function applyMobileH2(){
  const ph = document.querySelector('#view h2');
  if (!ph) return;
  const mobile = window.innerWidth <= 760;
  ph.style.display = mobile ? 'none' : '';
  const p = ph.parentElement;
  if (!p) return;
  if (mobile) {
    const others = Array.from(p.children).filter(c => c !== ph && c.offsetParent !== null);
    p.style.justifyContent = others.length ? 'flex-end' : 'space-between';
  } else {
    p.style.justifyContent = 'space-between';
  }
}


const ICON_MOON = ICON.moon;
const ICON_SUN  = ICON.sun;
function updateThemeIcon(t){
  var btn = document.getElementById('themeToggle');
  if(!btn) return;
  btn.innerHTML = (t === 'dark' ? ICON_MOON : ICON_SUN);
  btn.setAttribute('aria-label', t === 'dark' ? '切换到浅色主题' : '切换到深色主题');
}

updateThemeIcon(getTheme());



function t(s){
  if (typeof s !== 'string' || s === '') return s;
  return (window.I18N && window.I18N[s] !== undefined) ? window.I18N[s] : s;
}


function applyI18n(root){
  root = root || document;
  if (!window.I18N) return;
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
  const nodes = [];
  while (walker.nextNode()) nodes.push(walker.currentNode);
  nodes.forEach(node => {
    const raw = node.nodeValue;
    if (!raw || !raw.trim()) return;
    const tr = t(raw);
    if (tr !== raw) node.nodeValue = tr;
  });
  if (root.querySelectorAll){
    root.querySelectorAll('[placeholder],[title],[aria-label],button,option,input[type=button],input[type=submit]').forEach(el => {
      if (el.placeholder){ const tr = t(el.placeholder); if (tr !== el.placeholder) el.placeholder = tr; }
      if (el.title){ const tr = t(el.title); if (tr !== el.title) el.title = tr; }
      const al = el.getAttribute && el.getAttribute('aria-label');
      if (al){ const tr = t(al); if (tr !== al) el.setAttribute('aria-label', tr); }
      if ((el.tagName === 'BUTTON' || el.type === 'button' || el.type === 'submit') && el.value){ const tr = t(el.value); if (tr !== el.value) el.value = tr; }
    });
  }
}


const _i18nObserver = new MutationObserver(muts => {
  muts.forEach(m => m.addedNodes.forEach(n => { if (n.nodeType === 1) applyI18n(n); }));
});
function startI18nObserver(){ _i18nObserver.observe(document.body, { childList: true, subtree: true }); }


async function loadDict(lang){
  try {
    const r = await fetch('/api/i18n?lang=' + encodeURIComponent(lang));
    const j = await r.json();
    window.UI_LANG = (j.lang || lang);
    window.I18N = (j.dict && typeof j.dict === 'object') ? j.dict : {};
    window.LANGS = (j.langs && typeof j.langs === 'object') ? j.langs : {zh:'中文'};
  } catch(e){ window.UI_LANG = lang; window.I18N = {}; window.LANGS = {zh:'中文'}; }
}
function langAttr(lang){ return (lang === 'en') ? 'en' : (lang === 'zh' ? 'zh-CN' : lang); }
async function applyLanguage(lang){
  
  
  try {
    await fetch('/api/i18n?lang=' + encodeURIComponent(lang));
  } catch(e){}
  location.reload();
}



const _origAlert = window.alert;
const _toastType = (m) => {
  const s = String(m || '');
  if (/失败|错误|不允许|不能|无法|不可用|无效|非法|禁止|禁用|权限|已存在|重复|冲突|找不到|不存在|已被|forbidden|error|fail|denied|conflict|not\s*found|invalid|exist/i.test(s)) return 'err';
  if (/警告|注意|小心|warn/i.test(s)) return 'warn';
  if (/成功|已完成|已保存|已删除|已更新|已复制|已发送|已提交|ok|saved|created|updated|deleted|submitted/i.test(s)) return 'ok';
  return 'info';
};
window.alert = (m) => {
  
  if (typeof toast === 'function') return toast(t(m), _toastType(m));
  return _origAlert.call(window, t(m));
};
const _origConfirm = window.confirm;
window.confirm = (q) => _origConfirm.call(window, t(q));

let state = {user:null, token:null, view:'dashboard', live:false, stats:null, apps:[], devs:[], gws:[], ups:[], users:[], evs:[], regions:['EU868','US915','CN470','AS923','AU915','CN779','EU433','IN865','KR920','RU864'], upsFilter:'', upsAppFilter:'', dlDevFilter:'', dlAppFilter:'', evsDevFilter:'', evsGwFilter:'', dps:[], appSel:null, intAppSel:null, mcDetail:null, tenantFilter:'', devAppFilter:'', apiLogFilter:{path:'',ip:'',status:'',method:'',tenant_id:'',application_id:''}, upsSort:{col:'time',dir:'desc'}, dlsSort:{col:'time',dir:'desc'}, evsSort:{col:'time',dir:'desc'}, apiLogSort:{col:'time',dir:'desc'}, appsSort:{col:'time',dir:'desc'}, devsSort:{col:'time',dir:'desc'}, gwsSort:{col:'time',dir:'desc'}, usersSort:{col:'time',dir:'desc'}, apiKeysSort:{col:'time',dir:'desc'}, intgSort:{col:'time',dir:'desc'}, dpsSort:{col:null,dir:'desc'}, upsFStatus:'', dlsFStatus:'', evsFLevel:'', evsFType:'', apiLogFStatus:'', devsFActivation:'', devsFCls:'', devsFOnline:'', devsFStatus:'', gwsFOnline:'', dpsFRegion:'', dpsFCls:'', upsFFcnt:'', upsFPort:'', upsPage:1, dlsPage:1, evsPage:1, apiLogPage:1, appsPage:1, devsPage:1, gwsPage:1, usersPage:1, apiKeysPage:1, intgPage:1, dpsPage:1, upsLimit:50, dlsLimit:50, evsLimit:50, apiLogLimit:50, appsLimit:50, devsLimit:50, gwsLimit:50, usersLimit:50, apiKeysLimit:50, intgLimit:50, dpsLimit:50, upsOffset:0, dlsOffset:0, evsOffset:0, apiLogOffset:0, appsOffset:0, devsOffset:0, gwsOffset:0, usersOffset:0, apiKeysOffset:0, intgOffset:0, dpsOffset:0, upsTotal:0, dlsTotal:0, evsTotal:0, apiLogTotal:0, appsTotal:0, devsTotal:0, gwsTotal:0, usersTotal:0, apiKeysTotal:0, intgTotal:0, dpsTotal:0};

async function boot(){
  state.token = localStorage.getItem('elw_token') || null;
  try {
    const opt = {headers: state.token ? {'X-Elw-Token': state.token} : {}};
    const r = await fetch('/api/me', opt);
    if (r.ok) { const j = await r.json(); state.user = j.user; }
  } catch(e){}
  try { const rr = await fetch('/api/regions'); if (rr.ok) { const j = await rr.json(); if (j.regions && j.regions.length) state.regions = j.regions; } } catch(e){}
  
  if (!window.LANGS) { try { await loadDict(window.UI_LANG || 'zh'); } catch(e){} }
  
  
  applyI18n(document);
  
  applyPublicSettings();
  renderShell();
}
const regionOptions = (sel) => state.regions.map(r=>`<option ${r===sel?'selected':''}>${r}</option>`).join('');
function renderShell(){
  if (!state.user) {
    document.getElementById('topbar').classList.add('hidden');
    document.getElementById('view').classList.add('hidden');
    document.getElementById('login').classList.remove('hidden');
    const dk = document.getElementById('floatDock');
    if (dk) dk.classList.add('hidden');
    return;
  }
  document.getElementById('login').classList.add('hidden');
  document.getElementById('topbar').classList.remove('hidden');
  document.getElementById('view').classList.remove('hidden');
  const dk = document.getElementById('floatDock');
  if (dk) dk.classList.remove('hidden');
  document.getElementById('who').textContent = state.user.username;
  const av = document.getElementById('avatar');
  if (av) av.src = state.user.avatar_url || 'https://gravatar.webp.se/avatar/00000000000000000000000000000000?s=40&d=mp';
  renderNav();
  
  applyPublicSettings();
  
  nav((location.hash||'').slice(1)||'dashboard');
  
  applyI18n(document);
  startI18nObserver();
  enhanceSelects(document);
  startSelObserver();
}
function pageTop(){ window.scrollTo({top:0, behavior:'smooth'}); }
function pageBottom(){ window.scrollTo({top:document.documentElement.scrollHeight, behavior:'smooth'}); }
const isAdmin = () => state.user && state.user.role === 'admin';
const isTenant = () => state.user && state.user.role === 'tenant';
const isDemo = () => state.user && state.user.role === 'operator';

const canWrite = () => state.user && ['admin','tenant'].includes(state.user.role);

const adminBtn = (html) => html;


const NAV_GROUPS = [
  { label:'运行监控', icon:'chartBar', items:[
    {v:'dashboard', perm:'dashboard', text:'概览', icon:'chartBar'},
    {v:'uplinks', perm:'uplinks', text:'上行消息日志', icon:'signal'},
    {v:'downlinks', perm:'downlinks', text:'下行消息日志', icon:'arrowDownTray'},
    {v:'events', perm:'events', text:'网关日志', icon:'server'},
    {v:'noc', perm:'noc', text:'运维仪表盘', icon:'chartBar'},
    {v:'map', perm:'map', text:'位置地图', icon:'map'},
  ]},
  { label:'设备管理', icon:'cpuChip', items:[
    {v:'applications', perm:'applications', text:'应用', icon:'squares2x2'},
    {v:'devices', perm:'devices', text:'设备', icon:'cpuChip'},
    {v:'gateways', perm:'gateways', text:'网关', icon:'radio'},
    {v:'device-profiles', perm:'device-profiles', text:'设备模板', icon:'rectangleStack'},
    {v:'multicast-groups', perm:'multicast-groups', text:'组播组', icon:'userGroup'},
  ]},
  { label:'数据管理', icon:'chartBar', items:[
    {v:'thing-models', perm:'thing-models', text:'物模型', icon:'codeBracket'},
    {v:'dashboard-data', perm:'dashboard-data', text:'数据看板', icon:'chartBar'},
    {v:'alerts', perm:'alerts', text:'告警管理', icon:'bellAlert'},
    {v:'scheduled', perm:'scheduled', text:'定时任务', icon:'clock'},
    {v:'automations', perm:'automations', text:'联动模型', icon:'bolt'},
  ]},
  { label:'工具集成', icon:'puzzlePiece', items:[
    {v:'integrations', perm:'integrations', text:'外部集成', icon:'puzzlePiece'},
    {v:'api-keys', perm:'api-keys', text:'API 密钥', icon:'key'},
    {v:'api-logs', perm:'api-logs', text:'API 调用日志', icon:'clipboardDocumentList'},
    {v:'apidocs', perm:'apidocs', text:'API 文档', icon:'bookOpen'},
    {v:'loracalc', perm:'loracalc', text:'LoRa 计算器', icon:'calculator'},
  ]},
  { label:'系统管理', admin:true, icon:'cog6Tooth', items:[
    {v:'tenants', perm:'tenants', text:'用户配置', icon:'users'},
    {v:'users', perm:'users', text:'用户管理', icon:'user'},
    {v:'roles', perm:'roles', text:'角色管理', icon:'shieldCheck'},
    {v:'departments', perm:'departments', text:'部门管理', icon:'buildingOffice'},
    {v:'settings', perm:'settings', text:'站点设置', icon:'cog6Tooth'},
  ]},
];
const hasPerm = (p) => !!state.user && (state.user.role === 'admin' || (state.user.permissions||[]).indexOf(p) !== -1);
const VIEW_TITLES = {};
NAV_GROUPS.forEach(g => (g.items||[]).forEach(it => { VIEW_TITLES[it.v] = it.text; }));
const VIEW_ICONS = {};
NAV_GROUPS.forEach(g => (g.items||[]).forEach(it => { VIEW_ICONS[it.v] = it.icon; }));

function renderNav(){
  const desk = document.getElementById('deskNav');
  const mob = document.getElementById('mobilePanel');
  if (!desk || !mob) return;
  const groups = NAV_GROUPS
    .filter(g => !g.admin || isAdmin())
    .map(g => ({ ...g, items: (g.items||[]).filter(it => !it.perm || hasPerm(it.perm)) }))
    .filter(g => g.items.length);
  desk.innerHTML = groups.map(g => {
    const sub = g.items.map(it => `<a href="#${it.v}" class="nav" data-v="${it.v}">${ICON[it.icon]||''}<span>${it.text}</span></a>`).join('');
    return `<div class="navgrp"><button class="navgrp-btn" onclick="toggleGrp(this)">${ICON[g.icon]||''}<span>${g.label}</span><span class="caret">${ICON.chevronDown}</span></button><div class="navsub">${sub}</div></div>`;
  }).join('');
  const accountGrid = `<a href="javascript:void(0)" onclick="closeNav();changePw()">${ICON.key}<span>修改密码</span></a>`
    + `<a href="javascript:void(0)" class="mp-danger" onclick="closeNav();logout()">${ICON.logout}<span>退出登录</span></a>`;
  mob.innerHTML = groups.map(g => {
    const grid = g.items.map(it => `<a href="#${it.v}" class="nav" data-v="${it.v}">${ICON[it.icon]||''}<span>${it.text}</span></a>`).join('');
    return `<div class="mp-group"><div class="mp-glabel">${g.label}</div><div class="mp-grid">${grid}</div></div>`;
  }).join('') + `<div class="mp-group"><div class="mp-glabel">账户</div><div class="mp-grid">${accountGrid}</div></div>`;
  bindNavLinks();
  updateNavActive();
}
function updateNavActive(){
  document.querySelectorAll('.nav').forEach(a => a.classList.toggle('active', a.dataset.v === state.view));
  document.querySelectorAll('.navgrp').forEach(g => {
    g.classList.toggle('active', !!g.querySelector('.nav.active'));
  });
}
function bindNavLinks(){
  document.querySelectorAll('.nav').forEach(a => a.onclick = () => { nav(a.dataset.v); closeNav(); closeGrps(); });
}
function toggleGrp(btn){
  const grp = btn.parentElement;
  const wasOpen = grp.classList.contains('open');
  closeGrps();
  if (!wasOpen) grp.classList.add('open');
}
function closeGrps(){
  document.querySelectorAll('.navgrp.open').forEach(g => g.classList.remove('open'));
}

document.addEventListener('click', (e) => {
  if (!e.target.closest('.navgrp')) closeGrps();
});

async function doLogin(){
  const u = document.getElementById('l_user').value.trim();
  const p = document.getElementById('l_pass').value;
  const err = document.getElementById('l_err');
  err.textContent = '';
  try {
    const r = await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:u,password:p})});
    let j;
    try { j = await r.json(); }
    catch(e){ err.textContent = '服务器返回异常（HTTP '+r.status+'），请查看服务器错误日志'; return; }
    if (j.ok && j.token){ state.user = j.user; state.token = j.token; localStorage.setItem('elw_token', j.token); renderShell(); }
    else err.textContent = j.error || ('登录失败 (HTTP '+r.status+')');
  } catch(e){ err.textContent = e.message || '网络错误，登录失败'; }
}
async function logout(){
  const opt = {method:'POST', headers: state.token ? {'X-Elw-Token': state.token} : {}};
  await fetch('/api/logout', opt);
  state.token = null; state.user = null; localStorage.removeItem('elw_token'); renderShell();
}

const api = async (m,p,body) => {
  const opt = {method:m, headers:{'Content-Type':'application/json'}};
  if (state.token) opt.headers['Grpc-Metadata-Authorization'] = 'Bearer ' + state.token;
  if (body) opt.body = JSON.stringify(body);
  const r = await fetch(p, opt);
  const ct = r.headers.get('content-type') || '';
  const text = await r.text();
  if (r.status === 401) { state.token = null; state.user = null; localStorage.removeItem('elw_token'); renderShell(); throw new Error('unauthorized'); }
  if (r.status === 403) {
    
    try { const ej = JSON.parse(text); if (ej.error && String(ej.error).indexOf('forbidden') !== -1) toast(t('演示模式：当前为只读账号，不能进行实际操作。如需体验完整功能，请联系管理员获取写权限账号。'), 'warn'); } catch(e) {}
  }
  if (r.status < 200 || r.status >= 300) {
    throw new Error('HTTP ' + r.status + '：' + text.slice(0, 300));
  }
  if (ct.indexOf('application/json') === -1) {
    throw new Error('服务器返回了非 JSON 响应（可能是错误页）：' + text.slice(0, 300));
  }
  let j;
  try { j = JSON.parse(text); } catch (e) { throw new Error('JSON 解析失败：' + text.slice(0, 300)); }
  
  return csAdapt(j, p);
};

/* =====================================================
 * ChirpStack 风格响应适配层
 * 后端 /api/* 已统一为 ChirpStack v4 REST 形状：
 *   - 列表 {totalCount, result:[...]}（camelCase 字段）
 *   - 错误 {error, code, message, details}
 * 本层把 camelCase → snake_case，使既有 140+ 处
 * r.data||[] 与 d.dev_eui 等消费代码无需逐处修改；
 * 同时兼容 login/stats 等未改形状的端点（原样透传）。
 * ===================================================== */
const _SNAKE_CACHE = {};
const _camel2snake = k => {
  let s = _SNAKE_CACHE[k];
  if (s === undefined) { s = k.replace(/[A-Z]/g, c => '_' + c.toLowerCase()); _SNAKE_CACHE[k] = s; }
  return s;
};
const _ISO_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;
const _UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-([0-9a-f]{12})$/;
const _csTs = iso => { const t = Date.parse(iso); return isNaN(t) ? 0 : Math.floor(t / 1000); };
const _csUuidInt = u => { const m = _UUID_RE.exec(u || ''); return m ? parseInt(m[1], 16) : 0; };
function _csDeep(v) {
  if (Array.isArray(v)) return v.map(_csDeep);
  if (v && typeof v === 'object') {
    const out = {};
    for (const k of Object.keys(v)) {
      out[_camel2snake(k)] = _csDeep(v[k]);
    }
    return out;
  }
  return v;
}
/** 行级规范化：ISO 时间→unix 秒、UUID 外键→整数、枚举小写化 */
function _csNormalizeRow(r) {
  if (!r || typeof r !== 'object') return r;
  const out = _csDeep(r);
  for (const k of Object.keys(out)) {
    const v = out[k];
    if (typeof v === 'string' && _ISO_RE.test(v)) {
      out[k] = _csTs(v);  // RFC3339 → unix 秒（前端 new Date(x*1000) 消费）
    } else if (typeof v === 'string' && _UUID_RE.test(v) && /_id$/.test(k)) {
      out[k] = _csUuidInt(v);  // 外键 UUID → 整数（与行数字 id 对齐）
    }
  }
  if (out.last_seen === undefined && out.last_seen_at !== undefined) { out.last_seen = out.last_seen_at; }
  // 主键：优先 numericId（后端同时接受数字/UUID 定位资源）
  if (out.numeric_id !== undefined && out.numeric_id !== null) {
    out.id = out.numeric_id;
  } else if (typeof out.id === 'string' && _UUID_RE.test(out.id)) {
    out.id = _csUuidInt(out.id);
  } else if (typeof out.id === 'string' && /^\d+$/.test(out.id)) {
    out.id = parseInt(out.id, 10);  // 日志类行（uplinks/downlinks/events）数字字符串 id → 数值，兼容既有 x.id===id 比较
  }
  if (typeof out.online === 'string') out.online = out.online.toLowerCase();
  // hex 字段统一小写（前端展示/比对按小写约定）
  for (const hk of ['payload_hex', 'decrypted_hex', 'phy_payload', 'dev_eui', 'dev_addr', 'dev_addr', 'mc_addr', 'mc_nwk_s_key', 'mc_app_s_key', 'app_eui', 'join_eui', 'nwk_s_key', 'app_s_key', 'nwk_key', 'app_key', 'gateway_id', 'gw_id']) {
    if (typeof out[hk] === 'string' && out[hk] && /^[0-9a-fA-F]+$/.test(out[hk])) out[hk] = out[hk].toLowerCase();
  }
  // ---- 资源级别名（ChirpStack 字段 → 前端既有 snake_case 消费字段）----
  // Base64 data → hex
  const b6h = b => { try { const s = atob(b || ''); let h = ''; for (let i = 0; i < s.length; i++) h += ('0' + s.charCodeAt(i).toString(16)).slice(-2); return h; } catch (e) { return ''; } };
  if (typeof out.f_cnt_up === 'number')   out.fcnt = out.f_cnt_up;
  if (typeof out.f_cnt_down === 'number') out.fcnt = out.f_cnt_down;
  if (typeof out.f_port === 'number')     out.port = out.f_port;
  if (typeof out.time === 'number' && out.received_at === undefined) out.received_at = out.time;
  if (typeof out.time === 'number' && out.created_at === undefined && out.state !== undefined) out.created_at = out.time;  // downlinks
  if (typeof out.decrypted_hex !== 'string' || out.decrypted_hex === '') {
    if (typeof out.decrypted_hex === 'undefined' && typeof out.payload_hex === 'string' && out.payload_hex !== '') { /* hex 扩展字段已有 */ }
    else if (typeof out.decrypted === 'string' && out.decrypted !== '') out.decrypted_hex = b6h(out.decrypted);
    else if (typeof out.data === 'string' && out.data !== '') { const h = b6h(out.data); if (out.decrypted_hex === undefined || out.decrypted_hex === '') out.decrypted_hex = h; }
  }
  if ((typeof out.phy_payload === 'undefined' || out.phy_payload === '') && typeof out.payload_hex === 'string' && out.payload_hex !== '') out.phy_payload = out.payload_hex;
  if ((typeof out.payload_hex === 'undefined' || out.payload_hex === '') && typeof out.data === 'string' && out.data !== '') out.payload_hex = b6h(out.data);
  if (typeof out.gateway_id === 'string' && out.gw_id === undefined) out.gw_id = out.gateway_id;
  // applicationId/appId 别名
  if (out.app_id === undefined && typeof out.application_id === 'number') out.app_id = out.application_id;
  // device 行：classEnabled → class；status 由 is_disabled 推导；last_seen_fmt
  if (typeof out.class_enabled === 'string' && out.class === undefined) out.class = out.class_enabled.replace('CLASS_', '');
  if (out.status === undefined && out.is_disabled !== undefined) out.status = out.is_disabled ? 'disabled' : 'active';
  if (typeof out.activation === 'string' && out.activation === '') out.activation = 'OTAA';
  if (out.online === 'offline' && typeof out.last_seen === 'number' && out.last_seen > 0) {
    // 后端用 ChirpStack 语义（从未上行=OFFLINE），前端把有 last_seen 的都显示原逻辑即可
  }
  if (typeof out.last_seen === 'number' && out.last_seen_fmt === undefined) out.last_seen_fmt = out.last_seen > 0 ? new Date(out.last_seen * 1000).toLocaleString('sv-SE').replace('T', ' ') : '-';
  // gateways：state → status；lastSeenFmt
  if (typeof out.state === 'string' && out.status === undefined && out.gateway_id !== undefined) out.status = out.state === 'ONLINE' ? 'online' : 'offline';
  // device-profiles：ChirpStack 枚举 → 前端展示文本
  if (typeof out.mac_version === 'string' && /^LORAWAN_/.test(out.mac_version)) out.mac_version = out.mac_version.replace('LORAWAN_', '').replaceAll('_', '.');
  if (typeof out.reg_params_revision === 'string' && /^RP002_/.test(out.reg_params_revision)) out.reg_params_revision = out.reg_params_revision.replace(/^RP002_/, '').replaceAll('_', '.');
  if (out.adr_algorithm === undefined) out.adr_algorithm = 'default';
  if (typeof out.payload_codec_runtime === 'string' && out.payload_codec_runtime === '') out.payload_codec_runtime = 'NONE';
  if (typeof out.supports_otaa === 'boolean') out.supports_otaa = out.supports_otaa ? 1 : 0;
  if (typeof out.supports_class_b === 'boolean') out.supports_class_b = out.supports_class_b ? 1 : 0;
  if (typeof out.supports_class_c === 'boolean') out.supports_class_c = out.supports_class_c ? 1 : 0;
  // api-keys：token_preview（后端已给 tokenPreview）+ application_id
  if (out.application_id === undefined && typeof out.app_id === 'number') out.application_id = out.app_id;
  if (out.app_id === undefined && typeof out.application_id === 'number') out.app_id = out.application_id;
  return out;
}
function csAdapt(j, path) {
  if (!j || typeof j !== 'object') return j;
  if (j.error !== undefined && j.error !== null) return j;  // 错误（含 gRPC 形状）原样透传
  // 列表：{totalCount, result} → 补 data/total 别名（snake_case 化 + 行规范化的 result），
  // 使既有 r.data||[] 与 r.total 消费代码无需逐处修改
  if (typeof j.totalCount === 'number' && Array.isArray(j.result)) {
    const norm = j.result.map(_csNormalizeRow);
    const out = { totalCount: j.totalCount, result: norm, data: norm, total: j.totalCount };
    for (const k of Object.keys(j)) {
      if (k !== 'totalCount' && k !== 'result') out[_camel2snake(k)] = _csDeep(j[k]);
    }
    return out;
  }
  // 非列表业务对象：若对象所有键都是 snake_case（如 stats/settings/regions），原样透传
  if (typeof path === 'string' && path.indexOf('/api/') === 0 && j.data === undefined && !j.ok) {
    const hasCamel = Object.keys(j).some(k => /[A-Z]/.test(k));
    if (!hasCamel) return j;
    // 设备单体等嵌套包装（device/deviceProfile/...）：转换后取出展平，便于 d.xxx 消费
    const nested = ['device', 'device_profile', 'gateway', 'application', 'tenant', 'multicast_group', 'thing_model', 'uplink', 'downlink'];
    const conv = _csDeep(j);
    for (const nk of nested) {
      if (conv[nk] && typeof conv[nk] === 'object' && !Array.isArray(conv[nk])) {
        return _csNormalizeRow(conv[nk]);
      }
    }
    return conv;
  }
  return j;
}
const hex = s => s || '-';
// DevAddr 显示按 4 字节倒序（与 AT 模块输出一致，仅展示用，不改变存储值）
const revAddr = s => (/^[0-9a-fA-F]{8}$/.test(s||'') ? s.slice(6,8)+s.slice(4,6)+s.slice(2,4)+s.slice(0,2) : (s||'-'));
const esc = s => (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));






function toast(msg, type){
  const host = document.getElementById('toastHost') || (() => {
    const el = document.createElement('div');
    el.id = 'toastHost';
    el.style.cssText = 'position:fixed;top:18px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none';
    document.body.appendChild(el);
    return el;
  })();
  const item = document.createElement('div');
  item.className = 'toast ' + (type || 'info');
  
  item.style.cssText = 'pointer-events:auto;min-width:180px;max-width:380px;padding:10px 14px;display:flex;align-items:flex-start;gap:10px;font-size:13px;line-height:1.45;opacity:0;transform:translateX(20px);transition:opacity .2s, transform .2s';
  
  const text = document.createElement('div');
  text.style.cssText = 'flex:1;word-break:break-word';
  text.textContent = msg;
  const close = document.createElement('button');
  close.textContent = '×';
  close.style.cssText = 'background:transparent;border:0;color:inherit;opacity:.6;cursor:pointer;font-size:18px;line-height:1;padding:0 0 0 4px';
  close.onclick = () => removeToast(item);
  item.appendChild(text);
  item.appendChild(close);
  
  while (host.children.length >= 3) host.removeChild(host.firstChild);
  host.appendChild(item);
  
  requestAnimationFrame(() => { item.style.opacity = '1'; item.style.transform = 'translateX(0)'; });
  setTimeout(() => removeToast(item), 2500);
}
function removeToast(item){
  if (!item || !item.parentNode) return;
  item.style.opacity = '0';
  item.style.transform = 'translateX(20px)';
  setTimeout(() => { if (item.parentNode) item.parentNode.removeChild(item); }, 220);
}





const hexToText = (s) => {
  if (!s) return '-';
  const clean = String(s).replace(/\s+/g, '');
  if (!clean) return '-';
  if (!/^[0-9a-fA-F]+$/.test(clean) || (clean.length & 1)) return s; 
  const bytes = new Uint8Array(clean.length / 2);
  for (let i = 0; i < bytes.length; i++) bytes[i] = parseInt(clean.substr(i*2, 2), 16);
  try {
    
    const txt = new TextDecoder('utf-8', { fatal: false }).decode(bytes);
    
    return txt.replace(/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/g, '·');
  } catch (_) {
    return s;
  }
};

function showLoader(){ const l=document.getElementById('loader'); if(l) l.classList.add('show'); }
function hideLoader(){ const l=document.getElementById('loader'); if(l) l.classList.remove('show'); }

async function busy(label, fn){ showLoader(label); try { return await fn(); } finally { hideLoader(); } }










function resetFilters(clearPageState, viewFn){
  state.tenantFilter='';
  if (clearPageState) { try { clearPageState(); } catch (e) {} }
  busy('重置中…', () => viewFn());
}



























async function nav(v, silent=false){
  state.view = v;
  const _navItem = NAV_GROUPS.flatMap(g => g.items||[]).find(i => i.v === v);
  if (!state.user) return;
  if (_navItem && _navItem.perm && !hasPerm(_navItem.perm)) {
    if (v !== 'dashboard') {
      state.view = 'dashboard';
      nav('dashboard', true);
    } else {
      state.view = 'dashboard';
      const _view = document.getElementById('view');
      if (_view) _view.innerHTML = '<div class="muted">' + t('无访问权限') + '</div>';
    }
    return;
  }
  const openSelId = silent ? captureOpenSelId() : null;
  if (openSelId) closeAllSelMenus(true); else closeAllSelMenus();
  if(!silent){ closeNav(); closeGrps(); }
  updateNavActive();
  const pt = document.getElementById('pageTitle');
  if (pt){
    const ic = VIEW_ICONS[v] ? (ICON[VIEW_ICONS[v]]||'') : '';
    pt.innerHTML = ic ? (ic + '<span>'+(VIEW_TITLES[v]||'')+'</span>') : (VIEW_TITLES[v]||'');
  }
  const savedScroll = silent ? captureScrollState() : null;
  if(!silent){
    
    if((location.hash||'').slice(1)!==v) history.replaceState(null,'','#'+v);
    showLoader();
  }
  try {
    if (v==='dashboard') await viewDashboard();
    else if (v==='applications') await viewApplications();
    else if (v==='devices') await viewDevices();
    else if (v==='gateways') await viewGateways();
    else if (v==='uplinks') await viewUplinks();
    else if (v==='downlinks') await viewDownlinks();
    else if (v==='events') await viewEvents();
    else if (v==='device-profiles') await viewDeviceProfiles();
    else if (v==='tenants') await viewTenants();
    else if (v==='integrations') await viewIntegrations();
    else if (v==='api-keys') await viewApiKeys();
    else if (v==='multicast-groups') await viewMulticastGroups();
    else if (v==='fuota') await viewFuota();
    else if (v==='users') await viewUsers();
    else if (v==='api-logs') await viewApiLogs();
    else if (v==='loracalc') await viewLoraCalc();
    else if (v==='apidocs') { await applyPublicSettings(); await viewApiDocs(); }
    else if (v==='noc') await viewNoc();
    else if (v==='map') await viewMap();
    else if (v==='thing-models') await viewThingModels();
    else if (v==='dashboard-data') await viewDashboardData();
    else if (v==='alerts') await viewAlerts();
    else if (v==='scheduled') await viewScheduledTasks();
    else if (v==='automations') await viewAutomations();
    else if (v==='notification-groups') { __alert.tab='groups'; await viewAlerts(); }
    else if (v==='roles') await viewRoles();
    else if (v==='departments') await viewDepartments();
    else if (v==='settings') await viewSettings();
    else document.getElementById('view').innerHTML = '<div class="muted">未知页面</div>';
    
    applyMobileH2();
    if (isDemo()) disableDemoWriteButtons();
  } catch(e){
    if(!silent) document.getElementById('view').innerHTML = `<div class="err-box">加载失败：${esc(e && e.message ? e.message : e)}</div>`;
  } finally {
    if(!silent) hideLoader();
    if (typeof restoreLogRefresh === 'function') restoreLogRefresh();
    if (typeof renderRefreshFloat === 'function') renderRefreshFloat();
    if (typeof renderFloatPrimary === 'function') renderFloatPrimary();
    if (typeof syncLogRefreshTimer === 'function') syncLogRefreshTimer();
    if (savedScroll) restoreScrollState(savedScroll);
    if (openSelId) {
      enhanceSelects(document);
      openSelById(openSelId, true);
    }
  }
}
function captureScrollState(){
  return {
    y: window.scrollY || document.documentElement.scrollTop || 0,
    wraps: Array.from(document.querySelectorAll('.tbl-wrap')).map(w => w.scrollLeft)
  };
}
function restoreScrollState(s){
  if (!s) return;
  const wraps = Array.from(document.querySelectorAll('.tbl-wrap'));
  wraps.forEach((w, i) => { if (s.wraps[i] != null) w.scrollLeft = s.wraps[i]; });
  requestAnimationFrame(() => window.scrollTo(0, s.y));
}
function toggleNav(){ document.getElementById('mobilePanel').classList.toggle('open'); }
function closeNav(){ document.getElementById('mobilePanel').classList.remove('open'); }


window.addEventListener('hashchange', ()=>{
  const v = (location.hash||'').slice(1) || 'dashboard';
  if (v !== state.view) nav(v);
});


// 日志类页面（uplinks/downlinks/events）的自动刷新改由右下角悬浮下拉（views.js 的
// logRefreshTimer / setLogRefresh）单独控制，避免和本全局 5s 定时器叠加导致下拉失效。
// 这里只保留无下拉控件的概览/列表页。
const AUTO_REFRESH_VIEWS = ['dashboard','devices','gateways'];
function isSelMenuOpen(){
  const list = window.__selMenus || [];
  return list.some(m => m.wrap && m.wrap.classList.contains('open'));
}
setInterval(()=>{
  if (document.getElementById('modal').classList.contains('show')) return; 
  if (isSelMenuOpen()) return; 
  if (AUTO_REFRESH_VIEWS.includes(state.view)) nav(state.view, true);
}, 5000);

function renderModal(html){
  const tmp = document.createElement('div');
  tmp.innerHTML = html;
  const box = document.getElementById('modalBox');
  const h3 = tmp.querySelector('h3');
  const title = h3 ? h3.outerHTML : '';
  if (h3) h3.remove();
  const children = Array.from(tmp.children);
  let foot = '';
  while (children.length) {
    const last = children[children.length - 1];
    // 只有「仅含按钮」的尾部 div 才算 footer；带 pre/textarea/input/select/table 的内容块不能被吞进 foot
    const hasBtn = last.tagName === 'DIV' && last.querySelector('button');
    const hasContent = last.tagName === 'DIV' && last.querySelector('pre, textarea, input, select, table, ul, ol');
    if (hasBtn && !hasContent) {
      last.style.marginTop = '';
      foot = last.outerHTML + foot;
      last.remove();
      children.pop();
    } else break;
  }
  box.innerHTML = `<div class="modal-head">${title}</div><div class="modal-body">${tmp.innerHTML}</div>${foot ? `<div class="modal-foot">${foot}</div>` : ''}`;
}
function openModal(html){
  closeAllSelMenus();
  renderModal(html);
  document.getElementById('modal').classList.add('show');
  
  if (isDemo()) {
    document.querySelectorAll('#modalBox button').forEach(btn => {
      const txt = (btn.textContent || '').trim();
      if (txt === '取消' || txt === '关闭' || txt.includes('随机')) return;
      btn.disabled = true;
      btn.style.opacity = '0.45';
      btn.style.cursor = 'not-allowed';
      btn.title = '演示模式：只读账号不能进行实际操作';
    });
  }
}


function disableDemoWriteButtons(){
  document.querySelectorAll('#view button').forEach(btn => {
    const txt = (btn.textContent || '').trim();
    if (/删除|停用|启用/.test(txt)) {
      btn.disabled = true;
      btn.style.opacity = '0.45';
      btn.style.cursor = 'not-allowed';
      btn.title = '演示模式：只读账号不能进行实际操作';
    }
  });
}
function closeModal(){ closeAllSelMenus(); document.getElementById('modal').classList.remove('show'); }
window.alert = function(msg){
  openModal(`<h3>${t('提示')}</h3><p style="margin:8px 0 16px;color:var(--txt);word-break:break-word">${esc(String(msg))}</p><div style="display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">${t('关闭')}</button></div>`);
};
function confirmDlg(msg, onOk){
  const box = document.getElementById('modalBox');
  renderModal(`<h3>${t('确认')}</h3><p style="margin:8px 0 16px;color:var(--txt);word-break:break-word">${esc(String(msg))}</p><div style="display:flex;gap:10px;justify-content:flex-end"><button class="ghost" data-act="cancel">${t('取消')}</button><button class="danger" data-act="ok">${t('删除')}</button></div>`);
  document.getElementById('modal').classList.add('show');
  box.querySelector('[data-act="cancel"]').onclick = closeModal;
  box.querySelector('[data-act="ok"]').onclick = () => { closeModal(); onOk(); };
  if (isDemo()) {
    const ok = box.querySelector('[data-act="ok"]');
    ok.disabled = true; ok.style.opacity = '0.45'; ok.style.cursor = 'not-allowed'; ok.title = '演示模式：只读账号不能进行实际操作';
  }
}
function v(id){ return document.getElementById(id).value.trim(); }

function enhanceSelects(root){
  root = root || document;
  window.__selMenus = (window.__selMenus || []).filter(m => m.wrap && m.wrap.isConnected);
  root.querySelectorAll('select:not([data-enhanced])').forEach(enhanceSelect);
}
function enhanceSelect(sel){
  if (sel.dataset.enhanced) return;
  sel.dataset.enhanced = '1';
  sel.classList.add('sel-native');

  const wrap = document.createElement('div');
  wrap.className = 'selwrap';
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'sel-btn';
  btn.innerHTML = '<span class="sel-label"></span><span class="sel-caret">' + ICON.chevronDown + '</span>';
  const menu = document.createElement('div');
  menu.className = 'sel-menu';

  const w = sel.style.width;
  if (w === 'auto') wrap.style.width = 'auto';
  else if (w) wrap.style.width = w;
  if (sel.style.fontSize) btn.style.fontSize = sel.style.fontSize;
  if (sel.style.fontWeight) btn.style.fontWeight = sel.style.fontWeight;
  if (sel.style.padding) btn.style.padding = sel.style.padding;

  const render = () => {
    menu.innerHTML = Array.from(sel.options).map((o,i) =>
      `<div class="sel-opt ${o.selected?'active':''}" data-i="${i}">${esc(t(o.text))}</div>`).join('');
    btn.querySelector('.sel-label').textContent = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
  };
  const sync = () => {
    btn.querySelector('.sel-label').textContent = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
    menu.querySelectorAll('.sel-opt').forEach((el,i) => el.classList.toggle('active', sel.options[i] && sel.options[i].selected));
  };
  const position = () => {
    const r = btn.getBoundingClientRect();
    const below = window.innerHeight - r.bottom;
    const above = r.top;
    menu.style.left = r.left + 'px';
    if (below < 200 && above > below) {
      menu.style.top = 'auto';
      menu.style.bottom = (window.innerHeight - r.top + 4) + 'px';
      menu.classList.add('up');
      menu.style.maxHeight = Math.max(120, above - 8) + 'px';
    } else {
      menu.style.bottom = 'auto';
      menu.style.top = (r.bottom + 4) + 'px';
      menu.classList.remove('up');
      menu.style.maxHeight = Math.max(120, below - 8) + 'px';
    }
    menu.style.minWidth = Math.max(r.width, 120) + 'px';
  };
  let closing = false;
  const open = (instant) => {
    closing = false;
    closeAllSelMenus(false);
    render();
    position();
    menu.classList.remove('open');
    document.body.appendChild(menu);
    menu.style.display = 'block';
    if (instant) menu.style.transition = 'none';
    void menu.offsetWidth;
    menu.classList.add('open');
    wrap.classList.add('open');
    if (instant) requestAnimationFrame(() => { menu.style.transition = ''; });
  };
  const close = (immediate) => {
    closing = true;
    menu.classList.remove('open');
    wrap.classList.remove('open');
    if (immediate) {
      if (menu.parentNode === document.body) menu.parentNode.removeChild(menu);
      closing = false;
    } else {
      setTimeout(() => { if (!menu.classList.contains('open') && menu.parentNode === document.body) menu.parentNode.removeChild(menu); closing = false; }, 180);
    }
  };

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (wrap.classList.contains('open')) { close(false); return; }
    if (closing) return;
    open();
  });
  menu.addEventListener('click', (e) => {
    e.stopPropagation();
    const opt = e.target.closest('.sel-opt');
    if (!opt) return;
    sel.selectedIndex = +opt.dataset.i;
    sync();
    close(false);
    sel.dispatchEvent(new Event('change', {bubbles:true}));
  });

  wrap.appendChild(btn);
  sel.parentNode.insertBefore(wrap, sel);
  wrap.appendChild(sel);
  render();
  new MutationObserver(() => sync()).observe(sel, {subtree:true, attributes:true, attributeFilter:['selected']});
  (window.__selMenus = window.__selMenus || []).push({wrap, menu, close, open, sel});
}
function closeAllSelMenus(immediate){
  const list = window.__selMenus || [];
  window.__selMenus = list.filter(m => m.wrap.isConnected || (m.menu && m.menu.parentNode === document.body));
  list.forEach(m => {
    if (m.menu && m.menu.parentNode === document.body && m.menu.classList.contains('open')) m.close(immediate);
  });
}
function captureOpenSelId(){
  const list = window.__selMenus || [];
  const entry = list.find(m => m.wrap && m.wrap.classList.contains('open') && m.sel);
  if (!entry || !entry.sel) return null;
  if (entry.sel.id) return 'id:' + entry.sel.id;
  if (entry.sel.name) return 'name:' + entry.sel.name;
  return 'idx:' + Array.from(document.querySelectorAll('select')).indexOf(entry.sel);
}
function openSelById(id, instant){
  if (!id) return;
  const list = window.__selMenus || [];
  let entry = null;
  if (id.indexOf('idx:') === 0) {
    const sel = Array.from(document.querySelectorAll('select'))[+id.slice(4)];
    if (sel) entry = list.find(m => m.sel === sel);
  } else if (id.indexOf('id:') === 0) {
    entry = list.find(m => m.wrap && m.wrap.isConnected && m.sel && m.sel.id === id.slice(3));
  } else if (id.indexOf('name:') === 0) {
    entry = list.find(m => m.wrap && m.wrap.isConnected && m.sel && m.sel.name === id.slice(5));
  }
  if (entry) entry.open(instant);
}
document.addEventListener('click', (e) => {
  if (!e.target.closest('.selwrap')) closeAllSelMenus();
});
window.addEventListener('scroll', (e) => {
  if (!e.isTrusted) return; // 程序化滚动（刷新后恢复滚动位置）不关闭下拉菜单
  if (e.target && e.target.closest && e.target.closest('.sel-menu')) return;
  closeAllSelMenus();
}, true);
window.addEventListener('resize', () => { closeAllSelMenus(); applyMobileH2(); if (typeof renderRefreshFloat==='function') renderRefreshFloat(); if (typeof renderFloatPrimary==='function') renderFloatPrimary(); });
const _selObserver = new MutationObserver(muts => {
  muts.forEach(m => {
    if (!m.addedNodes) return;
    m.addedNodes.forEach(n => {
      if (n.nodeType !== 1) return;
      if (n.matches && n.matches('select')) enhanceSelect(n);
      if (n.querySelectorAll) n.querySelectorAll('select:not([data-enhanced])').forEach(enhanceSelect);
    });
  });
});
function startSelObserver(){ _selObserver.observe(document.body, {childList:true, subtree:true}); }

