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


const ICON_MOON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
const ICON_SUN  = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>';
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

let state = {user:null, token:null, view:'dashboard', stats:null, apps:[], devs:[], gws:[], ups:[], users:[], evs:[], regions:['EU868','US915','CN470','AS923','AU915','CN779','EU433','IN865','KR920','RU864'], upsFilter:'', upsAppFilter:'', dlDevFilter:'', dlAppFilter:'', evsDevFilter:'', evsGwFilter:'', dps:[], appSel:null, intAppSel:null, mcDetail:null, tenantFilter:'', devAppFilter:'', apiLogFilter:{path:'',ip:'',status:'',method:'',tenant_id:'',application_id:''}, upsSort:{col:'time',dir:'desc'}, dlsSort:{col:'time',dir:'desc'}, evsSort:{col:'time',dir:'desc'}, apiLogSort:{col:'time',dir:'desc'}, appsSort:{col:'time',dir:'desc'}, devsSort:{col:'time',dir:'desc'}, gwsSort:{col:'time',dir:'desc'}, usersSort:{col:'time',dir:'desc'}, apiKeysSort:{col:'time',dir:'desc'}, intgSort:{col:'time',dir:'desc'}, dpsSort:{col:null,dir:'desc'}, upsFStatus:'', dlsFStatus:'', evsFLevel:'', evsFType:'', apiLogFStatus:'', devsFActivation:'', devsFCls:'', devsFOnline:'', devsFStatus:'', gwsFOnline:'', dpsFRegion:'', dpsFCls:'', upsFFcnt:'', upsFPort:'', upsPage:1, dlsPage:1, evsPage:1, apiLogPage:1, appsPage:1, devsPage:1, gwsPage:1, usersPage:1, apiKeysPage:1, intgPage:1, dpsPage:1, upsLimit:50, dlsLimit:50, evsLimit:50, apiLogLimit:50, appsLimit:50, devsLimit:50, gwsLimit:50, usersLimit:50, apiKeysLimit:50, intgLimit:50, dpsLimit:50, upsOffset:0, dlsOffset:0, evsOffset:0, apiLogOffset:0, appsOffset:0, devsOffset:0, gwsOffset:0, usersOffset:0, apiKeysOffset:0, intgOffset:0, dpsOffset:0, upsTotal:0, dlsTotal:0, evsTotal:0, apiLogTotal:0, appsTotal:0, devsTotal:0, gwsTotal:0, usersTotal:0, apiKeysTotal:0, intgTotal:0, dpsTotal:0};

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
    return;
  }
  document.getElementById('login').classList.add('hidden');
  document.getElementById('topbar').classList.remove('hidden');
  document.getElementById('view').classList.remove('hidden');
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
const isAdmin = () => state.user && state.user.role === 'admin';
const isTenant = () => state.user && state.user.role === 'tenant';
const isDemo = () => state.user && state.user.role === 'operator';

const canWrite = () => state.user && ['admin','tenant'].includes(state.user.role);

const adminBtn = (html) => html;


const NAV_GROUPS = [
  { label:'运行监控', items:[
    {v:'dashboard', text:'概览'},
    {v:'uplinks', text:'上行消息日志'},
    {v:'downlinks', text:'下行消息日志'},
    {v:'events', text:'网关日志'},
  ]},
  { label:'设备管理', items:[
    {v:'applications', text:'应用'},
    {v:'devices', text:'设备'},
    {v:'gateways', text:'网关'},
    {v:'device-profiles', text:'设备模板'},
    {v:'multicast-groups', text:'组播组'},
  ]},
  { label:'工具集成', items:[
    {v:'integrations', text:'外部集成'},
    {v:'api-keys', text:'API 密钥'},
    {v:'api-logs', text:'API 调用日志'},
    {v:'apidocs', text:'API 文档'},
    {v:'loracalc', text:'LoRa 计算器'},
  ]},
  { label:'系统管理', admin:true, items:[
    {v:'tenants', text:'用户配置'},
    {v:'users', text:'用户管理'},
    {v:'settings', text:'站点设置'},
  ]},
];
const VIEW_TITLES = {};
NAV_GROUPS.forEach(g => (g.items||[]).forEach(it => { VIEW_TITLES[it.v] = it.text; }));

function renderNav(){
  const desk = document.getElementById('deskNav');
  const mob = document.getElementById('mobilePanel');
  if (!desk || !mob) return;
  const groups = NAV_GROUPS.filter(g => !g.admin || isAdmin());
  desk.innerHTML = groups.map(g => {
    const sub = g.items.map(it => `<a href="#${it.v}" class="nav" data-v="${it.v}">${it.text}</a>`).join('');
    return `<div class="navgrp"><button class="navgrp-btn" onclick="toggleGrp(this)">${g.label}<span class="caret">▾</span></button><div class="navsub">${sub}</div></div>`;
  }).join('');
  const accountGrid = `<a href="javascript:void(0)" onclick="closeNav();changePw()">修改密码</a>`
    + `<a href="javascript:void(0)" class="mp-danger" onclick="closeNav();logout()">退出登录</a>`;
  mob.innerHTML = groups.map(g => {
    const grid = g.items.map(it => `<a href="#${it.v}" class="nav" data-v="${it.v}">${it.text}</a>`).join('');
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
  if (state.token) opt.headers['X-Elw-Token'] = state.token;
  if (body) opt.body = JSON.stringify(body);
  const r = await fetch(p, opt);
  const ct = r.headers.get('content-type') || '';
  const text = await r.text();
  if (r.status === 401) { state.token = null; state.user = null; localStorage.removeItem('elw_token'); renderShell(); throw new Error('unauthorized'); }
  if (r.status === 403) {
    
    try { const ej = JSON.parse(text); if (ej.error && ej.error.indexOf('forbidden') !== -1) toast(t('演示模式：当前为只读账号，不能进行实际操作。如需体验完整功能，请联系管理员获取写权限账号。'), 'warn'); } catch(e) {}
  }
  if (r.status < 200 || r.status >= 300) {
    throw new Error('HTTP ' + r.status + '：' + text.slice(0, 300));
  }
  if (ct.indexOf('application/json') === -1) {
    throw new Error('服务器返回了非 JSON 响应（可能是错误页）：' + text.slice(0, 300));
  }
  let j;
  try { j = JSON.parse(text); } catch (e) { throw new Error('JSON 解析失败：' + text.slice(0, 300)); }
  
  
  if (j && j.error) return j;
  return j;
};
const hex = s => s || '-';
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
  const openSelId = silent ? captureOpenSelId() : null;
  if (openSelId) closeAllSelMenus(true); else closeAllSelMenus();
  if(!silent){ closeNav(); closeGrps(); }
  updateNavActive();
  const pt = document.getElementById('pageTitle');
  if (pt) pt.textContent = VIEW_TITLES[v] || '';
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
    else if (v==='users') await viewUsers();
    else if (v==='api-logs') await viewApiLogs();
    else if (v==='loracalc') await viewLoraCalc();
    else if (v==='apidocs') { await applyPublicSettings(); await viewApiDocs(); }
    else if (v==='settings') await viewSettings();
    else document.getElementById('view').innerHTML = '<div class="muted">未知页面</div>';
    
    applyMobileH2();
    if (isDemo()) disableDemoWriteButtons();
  } catch(e){
    if(!silent) document.getElementById('view').innerHTML = `<div class="err-box">加载失败：${esc(e && e.message ? e.message : e)}</div>`;
  } finally {
    if(!silent) hideLoader();
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


const AUTO_REFRESH_VIEWS = ['dashboard','devices','gateways','uplinks','downlinks','events'];
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
    if (last.tagName === 'DIV' && last.querySelector('button')) {
      last.style.marginTop = '';
      foot = last.outerHTML;
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
  btn.innerHTML = '<span class="sel-label"></span><span class="sel-caret"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></span>';
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
window.addEventListener('resize', () => { closeAllSelMenus(); applyMobileH2(); });
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

