<?php
/**
 * holastack Web 管理后台入口（同时作为 PHP 内置服务器 router 脚本）。
 * 启动：php -S localhost:8080 public/index.php
 *   - /install            安装向导（未安装时）
 *   - /login              通过 /api/login 接口完成登录（SPA 内）
 *   - /api/*              返回 JSON（除 login/logout/me 外均需登录；写操作需 admin）
 *   - 其它路径            单页管理界面（SPA）
 */
require __DIR__ . '/../bootstrap.php';
use holastack\Web\WebApp;
use holastack\Auth\Auth;
use holastack\DB\Database;
use holastack\Install\Installer;

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

// ---- 安装守卫 ----
if (!ELW_INSTALLED) {
    if ($path === '/install') {
        Installer::handle();
        exit;
    }
    if (strpos($path, '/api/') === 0) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        echo json_encode(['error' => 'not_installed']);
        exit;
    }
    header('Location: /install');
    exit;
}
if ($path === '/install') {
    header('Location: /login');
    exit;
}

// 已安装：确保表结构存在（含 auth_tokens 等新增表，对已存在库兜底）
Database::migrate();

// ---- API 路由 ----
if (strpos($path, '/api/') === 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(handleApi($method, $path), JSON_UNESCAPED_UNICODE);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo renderPage();

// ============================================================
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $dec = json_decode($raw, true);
    if (is_array($dec)) {
        return $dec;
    }
    parse_str($raw, $out);
    return $out;
}

function handleApi(string $method, string $path): array
{
    $segs = explode('/', trim($path, '/'));
    array_shift($segs); // 去掉 'api'
    $resource = $segs[0] ?? '';
    $body = in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true) ? getJsonBody() : [];
    $get = $_GET;

    // 公开端点
    if ($resource === 'login') {
        if ($method !== 'POST') {
            return ['error' => 'method not allowed'];
        }
        $u = Auth::authenticate($body['username'] ?? '', $body['password'] ?? '');
        if (!$u) {
            http_response_code(401);
            return ['error' => 'invalid credentials'];
        }
        $token = Auth::issueToken($u);
        return ['ok' => true, 'user' => ['id' => $u['id'], 'username' => $u['username'], 'role' => $u['role']], 'token' => $token];
    }
    if ($resource === 'logout') {
        Auth::logout(Auth::tokenFromRequest());
        return ['ok' => true];
    }
    if ($resource === 'me') {
        $u = Auth::currentUser();
        if (!$u) {
            http_response_code(401);
            return ['error' => 'unauthorized'];
        }
        return ['user' => $u];
    }

    // 其余需登录；写操作（创建/更新/删除）需 admin，下行入队 operator 即可
    $isWrite = in_array($method, ['POST', 'PUT', 'DELETE'], true);
    $isDownlink = ($resource === 'devices' && ($segs[2] ?? '') === 'downlink');
    $isPwChange = ($resource === 'users' && ($segs[1] ?? '') === 'password');
    $needsAdmin = $isWrite && !$isDownlink && !$isPwChange;
    Auth::guardApi($needsAdmin ? Auth::ROLE_ADMIN : Auth::ROLE_OPERATOR);

    switch ($resource) {
        case 'stats':
            return WebApp::getStats();
        case 'regions':
            return ['regions' => WebApp::regions()];
        case 'applications':
            if (isset($segs[1]) && $method === 'PUT') {
                return WebApp::updateApplication((int) $segs[1], $body);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteApplication((int) $segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createApplication($body);
            }
            return ['data' => WebApp::listApplications()];
        case 'devices':
            if (isset($segs[1]) && ($segs[2] ?? '') === 'downlink' && $method === 'POST') {
                return WebApp::enqueueDownlink((int) $segs[1], (int) ($body['port'] ?? 0), $body['payload'] ?? '', !empty($body['confirmed']));
            }
            if (isset($segs[1]) && $method === 'PUT') {
                return WebApp::updateDevice((int) $segs[1], $body);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteDevice((int) $segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createDevice($body);
            }
            $appId = isset($get['app_id']) ? (int) $get['app_id'] : null;
            return ['data' => WebApp::listDevices($appId)];
        case 'gateways':
            if (isset($segs[1]) && $method === 'PUT') {
                return WebApp::updateGateway($segs[1], $body);
            }
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteGateway($segs[1]);
            }
            if ($method === 'POST') {
                return WebApp::createGateway($body);
            }
            return ['data' => WebApp::listGateways()];
        case 'uplinks':
            $devId = isset($get['dev_id']) ? (int) $get['dev_id'] : null;
            return ['data' => WebApp::listUplinks($devId)];
        case 'downlinks':
            $devId = isset($segs[1]) ? (int) $segs[1] : null;
            return ['data' => WebApp::listDownlinks($devId)];
        case 'events':
            return ['data' => WebApp::listEvents()];
        case 'users':
            if (isset($segs[1]) && $method === 'DELETE') {
                return WebApp::deleteUser((int) $segs[1]);
            }
            if (($segs[1] ?? '') === 'password' && $method === 'POST') {
                $cur = Auth::currentUser();
                $target = (isset($body['user_id']) && $body['user_id'] !== '') ? (int) $body['user_id'] : (int) $cur['id'];
                return WebApp::changePassword($target, $body['new_password'] ?? '');
            }
            if ($method === 'POST') {
                if (empty($body['username']) || empty($body['password'])) {
                    return ['error' => 'username and password required'];
                }
                if (!in_array($body['role'] ?? 'operator', [Auth::ROLE_ADMIN, Auth::ROLE_OPERATOR], true)) {
                    return ['error' => 'invalid role'];
                }
                $id = Auth::createUser($body['username'], $body['password'], $body['role'] ?? Auth::ROLE_OPERATOR);
                return ['id' => $id];
            }
            return ['data' => WebApp::listUsers()];
        default:
            return ['error' => 'unknown endpoint'];
    }
}

function renderPage(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>holastack</title>
<style>
  :root{--bg:#0f1420;--panel:#1a2233;--line:#2b3650;--txt:#e6ecf5;--mut:#8b97ad;--acc:#3da9fc;--ok:#36d399;--warn:#fbbd23;--err:#f87272}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  header{display:flex;align-items:center;gap:18px;padding:14px 22px;background:var(--panel);border-bottom:1px solid var(--line)}
  header h1{font-size:16px;margin:0;color:var(--acc)}
  nav a{color:var(--mut);text-decoration:none;padding:6px 12px;border-radius:6px}
  nav a.active,nav a:hover{color:var(--txt);background:#243049}
  .spacer{flex:1}
  .who{color:var(--mut);font-size:12px}
  main{padding:22px;max-width:1100px;margin:0 auto}
  .cards{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px}
  .card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:16px 20px;min-width:140px}
  .card .n{font-size:26px;font-weight:700;color:var(--acc)} .card .l{color:var(--mut);font-size:12px}
  table{width:100%;border-collapse:collapse;background:var(--panel);border-radius:10px;overflow:hidden}
  th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);font-size:13px}
  th{color:var(--mut);font-weight:600;background:#161d2c} tr:hover td{background:#1f2740}
  button,.btn{background:var(--acc);color:#04121f;border:0;padding:8px 14px;border-radius:7px;cursor:pointer;font-weight:600}
  button.ghost{background:#243049;color:var(--txt)} button.danger{background:#3a1620;color:var(--err)}
  input,select,textarea{background:#0d1320;color:var(--txt);border:1px solid var(--line);border-radius:7px;padding:8px 10px;width:100%;font-family:inherit}
  label{display:block;color:var(--mut);margin:10px 0 4px;font-size:12px}
  .row{display:flex;gap:12px;flex-wrap:wrap} .row>div{flex:1;min-width:200px}
  .modal{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center}
  .modal.show{display:flex} .box{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:22px;width:min(560px,92vw);max-height:88vh;overflow:auto}
  .box h3{margin:0 0 8px}
  .tag{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;background:#243049;color:var(--mut)}
  .tag.ok{background:#103a2a;color:var(--ok)} .tag.pending{background:#3a3410;color:var(--warn)} .tag.err{background:#3a1620;color:var(--err)} .tag.off{background:#2a2f3d;color:var(--mut)}
  .tag.A{background:#10304a;color:var(--acc)} .tag.B{background:#2a2a10;color:var(--warn)} .tag.C{background:#103a2a;color:var(--ok)}
  .muted{color:var(--mut)} pre{background:#0d1320;padding:10px;border-radius:8px;overflow:auto;font-size:12px} .hidden{display:none}
  #login{display:flex;align-items:center;justify-content:center;min-height:100vh}
  #login.hidden{display:none}
  #login .box{width:min(380px,92vw)}
</style>
</head>
<body>
<header class="hidden" id="topbar">
  <h1>holastack</h1>
  <nav>
    <a href="#dashboard" class="nav" data-v="dashboard">概览</a>
    <a href="#applications" class="nav" data-v="applications">应用</a>
    <a href="#devices" class="nav" data-v="devices">设备</a>
    <a href="#gateways" class="nav" data-v="gateways">网关</a>
    <a href="#uplinks" class="nav" data-v="uplinks">上行</a>
    <a href="#events" class="nav" data-v="events">日志</a>
    <a href="#users" class="nav" data-v="users" id="navUsers">用户</a>
  </nav>
  <div class="spacer"></div>
  <span class="who" id="who"></span>
  <button class="ghost" onclick="changePw()">修改密码</button>
  <button class="ghost" onclick="logout()">退出</button>
</header>

<div id="login" class="hidden">
  <div class="box">
    <h3>登录</h3>
    <label>用户名</label><input id="l_user">
    <label>密码</label><input id="l_pass" type="password">
    <div id="l_err" class="muted" style="color:var(--err)"></div>
    <button style="margin-top:16px;width:100%" onclick="doLogin()">登录</button>
  </div>
</div>

<main id="view" class="hidden"></main>

<div class="modal" id="modal"><div class="box" id="modalBox"></div></div>

<script>
let state = {user:null, token:null, view:'dashboard', stats:null, apps:[], devs:[], gws:[], ups:[], users:[], regions:['EU868','CN470'], upsFilter:''};

async function boot(){
  state.token = localStorage.getItem('elw_token') || null;
  try {
    const opt = {headers: state.token ? {'X-Elw-Token': state.token} : {}};
    const r = await fetch('/api/me', opt);
    if (r.ok) { const j = await r.json(); state.user = j.user; }
  } catch(e){}
  try { const rr = await fetch('/api/regions'); if (rr.ok) { const j = await rr.json(); if (j.regions && j.regions.length) state.regions = j.regions; } } catch(e){}
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
  document.getElementById('navUsers').style.display = (state.user.role === 'admin') ? '' : 'none';
  nav('dashboard');
}
const isAdmin = () => state.user && state.user.role === 'admin';
const adminBtn = (html) => isAdmin() ? html : '';

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
  if (r.status === 401) { state.token = null; state.user = null; localStorage.removeItem('elw_token'); renderShell(); throw new Error('unauthorized'); }
  return r.json();
};
const hex = s => s || '-';
const esc = s => (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));

function nav(v){
  state.view = v;
  document.querySelectorAll('.nav').forEach(a=>a.classList.toggle('active', a.dataset.v===v));
  if (v==='dashboard') return viewDashboard();
  if (v==='applications') return viewApplications();
  if (v==='devices') return viewDevices();
  if (v==='gateways') return viewGateways();
  if (v==='uplinks') return viewUplinks();
  if (v==='events') return viewEvents();
  if (v==='users') return viewUsers();
}
document.querySelectorAll('.nav').forEach(a=>a.onclick=()=>nav(a.dataset.v));

// 自动刷新：只读的实时视图（概览/网关/上行/日志）每 5 秒刷新；模态框打开时暂停，避免打断编辑
const AUTO_REFRESH_VIEWS = ['dashboard','devices','gateways','uplinks','events'];
setInterval(()=>{
  if (document.getElementById('modal').classList.contains('show')) return; // 弹窗打开时暂停
  if (AUTO_REFRESH_VIEWS.includes(state.view)) nav(state.view);
}, 5000);

async function viewDashboard(){
  const s = await api('GET','/api/stats'); state.stats = s;
  document.getElementById('view').innerHTML = `
    <div class="cards">
      <div class="card"><div class="n">${s.applications}</div><div class="l">应用</div></div>
      <div class="card"><div class="n">${s.devices}</div><div class="l">设备</div></div>
      <div class="card"><div class="n">${s.gateways}</div><div class="l">网关</div></div>
      <div class="card"><div class="n">${s.uplinks}</div><div class="l">上行消息</div></div>
    </div>
    <p class="muted">网络服务器监听 UDP 端口由 ELW_GW_UDP_PORT 配置（默认 1700）。先创建应用，再创建设备（OTAA 或 ABP，Class A/B/C），然后用网关连接并发送数据。</p>`;
}
async function viewApplications(){
  const r = await api('GET','/api/applications'); state.apps = r.data||[];
  const rows = state.apps.map(a=>`<tr><td>${a.id}</td><td>${esc(a.name)}</td><td class="muted">${esc(a.app_eui)}</td><td class="muted">${esc(a.callback_url||'')}</td><td class="muted">${new Date(a.created_at*1000).toLocaleString()}</td>
     <td>${adminBtn(`<button class="btn ghost" onclick="editApplication(${a.id})">编辑</button> <button class="btn danger" onclick="delApplication(${a.id})">删除</button>`)} <button class="btn ghost" onclick="newDevice(${a.id})">+ 设备</button></td></tr>`).join('')||`<tr><td colspan="6" class="muted">暂无应用</td></tr>`;
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>应用</h2>${adminBtn('<button onclick="newApplication()">+ 新建应用</button>')}</div>
    <table><thead><tr><th>ID</th><th>名称</th><th>AppEUI</th><th>回调 URL</th><th>创建时间</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
}
async function viewDevices(){
  const r = await api('GET','/api/devices'); state.devs = r.data||[];
  const rows = state.devs.map(d=>{
    const online = d.online==='online';
    const tel = [];
    if (d.battery!==null && d.battery!==undefined && +d.battery>=0) tel.push('电量'+(+d.battery===0?'外电':(+d.battery)+'%'));
    if (d.margin!==null && d.margin!==undefined && d.margin!=='') tel.push('余量'+(+d.margin)+'dB');
    if (d.latitude && +d.latitude!==0 && d.longitude!==null) tel.push('GPS '+ (+d.latitude).toFixed(5)+','+(+d.longitude).toFixed(5));
    const telStr = tel.length? `<div class="muted" style="font-size:11px">${tel.join(' · ')}</div>`:'';
    const seen = (d.last_seen_fmt && d.last_seen_fmt!=='-') ? d.last_seen_fmt : '-';
    return `<tr>
      <td>${d.id}</td><td>${esc(d.name)}</td>
      <td><span class="tag">${d.activation}</span></td>
      <td><span class="tag ${d.class}">${d.class}</span></td>
      <td><span class="tag ${online?'ok':'off'}">${online?'在线':'离线'}</span></td>
      <td class="muted">${hex(d.dev_eui)}</td><td class="muted">${hex(d.dev_addr)}</td>
      <td><span class="tag ${d.status==='active'?'ok':'pending'}">${d.status}</span></td>
      <td class="muted">${seen}${telStr}</td>
      <td>${adminBtn(`<button class="btn ghost" onclick="editDevice(${d.id})">编辑</button> <button class="btn danger" onclick="delDevice(${d.id})">删除</button>`)} <button class="btn ghost" onclick="deviceDetail(${d.id})">密钥</button> <button class="btn ghost" onclick="downlink(${d.id})">下行</button></td></tr>`;
  }).join('')||`<tr><td colspan="10" class="muted">暂无设备</td></tr>`;
  document.getElementById('view').innerHTML = `<h2>设备</h2>
    <table><thead><tr><th>ID</th><th>名称</th><th>激活</th><th>Class</th><th>状态</th><th>DevEUI</th><th>DevAddr</th><th>入网</th><th>最近/遥测</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
}
async function deviceDetail(id){
  const r = await api('GET','/api/devices'); state.devs = r.data||[];
  const d=(state.devs||[]).find(x=>x.id===id); if(!d)return;
  const kv=(label,val)=>`<label>${label}</label><input value="${esc(val||'')}" readonly onclick="this.select()">`;
  openModal(`<h3>设备密钥 #${id} ${esc(d.name)}</h3>
    ${kv('DevEUI', d.dev_eui)}
    ${d.activation==='OTAA'
      ? kv('JoinEUI', d.join_eui) + kv('AppKey', d.app_key)
      : kv('DevAddr', d.dev_addr) + kv('NwkSKey', d.nwk_s_key) + kv('AppSKey', d.app_s_key)}
    <p class="muted" style="font-size:12px">点击输入框即可全选复制。修改请点“编辑”。</p>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}
async function viewGateways(){
  const r = await api('GET','/api/gateways'); state.gws = r.data||[];
  const rows = state.gws.map(g=>{
    const online = g.status==='online';
    const seen = g.last_seen ? new Date(g.last_seen*1000).toLocaleString() : '-';
    return `<tr><td class="muted">${g.gw_id}</td><td>${esc(g.name)}</td>
      <td><span class="tag ${online?'ok':'off'}">${online?'在线':'离线'}</span></td>
      <td class="muted">${esc(g.region)}</td><td class="muted">${g.uplinks||0}</td><td class="muted">${seen}</td>
      <td>${adminBtn(`<button class="btn ghost" onclick="editGateway('${g.gw_id}')">编辑</button> <button class="btn danger" onclick="delGateway('${g.gw_id}')">删除</button>`)}</td></tr>`;
  }).join('')||`<tr><td colspan="7" class="muted">暂无网关（网关连接后自动出现，亦可手动添加）</td></tr>`;
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>网关</h2>${adminBtn('<button onclick="newGateway()">+ 新建网关</button>')}</div>
    <table><thead><tr><th>GatewayID</th><th>名称</th><th>状态</th><th>区域</th><th>上行数</th><th>最近心跳</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
}

async function viewUplinks(){
  const r = await api('GET','/api/uplinks' + (state.upsFilter ? '?dev_id='+state.upsFilter : '')); state.ups = r.data||[];
  const dr = await api('GET','/api/devices'); const devs = dr.data||[];
  const opts = `<option value="">全部设备</option>` + devs.map(d=>`<option value="${d.id}" ${String(d.id)===String(state.upsFilter)?'selected':''}>#${d.id} ${esc(d.name)} (${hex(d.dev_eui)})</option>`).join('');
  const rows = state.ups.map(u=>`<tr><td>${u.id}</td>
    <td class="muted"><a href="javascript:void(0)" style="color:var(--acc);text-decoration:none" onclick="deviceDetail(${u.dev_id})">${hex(u.dev_addr)}</a></td>
    <td>${u.fcnt}</td><td>${u.port}</td><td>${u.confirmed?'✓':'-'}</td>
    <td><code>${hex(u.decrypted_hex)}</code></td>
    <td><code class="muted">${hex(u.phy_payload)}</code></td>
    <td class="muted">${u.gateway_id||'-'}</td>
    <td class="muted">${u.rssi} / ${u.snr}</td>
    <td class="muted">${new Date(u.received_at*1000).toLocaleString()}</td>
    <td><button class="btn ghost" onclick="showRaw(${u.id})">原始JSON</button></td></tr>`).join('')||`<tr><td colspan="11" class="muted">暂无上行</td></tr>`;
  document.getElementById('view').innerHTML = `<h2>上行消息（收到的 LoRa 帧）</h2>
    <div class="row" style="align-items:flex-end;margin-bottom:12px"><div style="flex:0 0 340px"><label>按设备筛选</label><select id="upFilter" onchange="state.upsFilter=this.value;viewUplinks()">${opts}</select></div></div>
    <p class="muted">每 5 秒自动刷新。phy 列为原始 LoRaWAN 帧（hex）；点 DevAddr 跳转到设备；点“原始JSON”查看网关上报元数据。</p>
    <table><thead><tr><th>ID</th><th>DevAddr</th><th>FCnt</th><th>Port</th><th>确认</th><th>解密 payload</th><th>原始帧 phy</th><th>网关</th><th>RSSI/SNR</th><th>时间</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
}
async function showRaw(id){
  const u=(state.ups||[]).find(x=>x.id===id); if(!u)return;
  let j={}; try { j = u.raw_json ? JSON.parse(u.raw_json) : {}; } catch(e){}
  openModal(`<h3>原始 JSON #${id}</h3><pre>${esc(JSON.stringify(j,null,2))}</pre><div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">关闭</button></div>`);
}

async function viewEvents(){
  const r = await api('GET','/api/events');
  const rows = (r.data||[]).map(e=>{
    const lvl = e.level==='error' ? 'err' : (e.level==='warn' ? 'pending' : 'ok');
    const who = e.gateway_id ? ('gw '+e.gateway_id) : (e.dev_id ? ('dev #'+e.dev_id) : '');
    return `<tr><td><span class="tag">${e.type}</span></td><td><span class="tag ${lvl}">${e.level}</span></td>
      <td class="muted">${esc(who)}</td><td>${esc(e.message)}</td><td class="muted">${new Date(e.created_at*1000).toLocaleString()}</td></tr>`;
  }).join('')||`<tr><td colspan="5" class="muted">暂无事件</td></tr>`;
  document.getElementById('view').innerHTML = `<h2>事件日志</h2><p class="muted">网关上下线 / 入网 / 上行 / 下行 / 错误等事件（每 5 秒自动刷新）。</p>
    <table><thead><tr><th>类型</th><th>级别</th><th>对象</th><th>消息</th><th>时间</th></tr></thead><tbody>${rows}</tbody></table>`;
}
async function viewUsers(){
  if (!isAdmin()) { nav('dashboard'); return; }
  const r = await api('GET','/api/users'); state.users = r.data||[];
  const rows = state.users.map(u=>`<tr><td>${u.id}</td><td>${esc(u.username)}</td><td><span class="tag">${u.role}</span></td><td class="muted">${new Date(u.created_at*1000).toLocaleString()}</td>
     <td><button class="btn danger" onclick="delUser(${u.id})">删除</button> <button class="btn ghost" onclick="changePwFor(${u.id})">改密</button></td></tr>`).join('')||`<tr><td colspan="5" class="muted">暂无用户</td></tr>`;
  document.getElementById('view').innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center"><h2>用户</h2><button onclick="newUser()">+ 新建用户</button></div>
    <table><thead><tr><th>ID</th><th>用户名</th><th>角色</th><th>创建时间</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
}
async function changePw(){
  let targetSel = '';
  if (isAdmin()) {
    const r = await api('GET','/api/users');
    targetSel = `<label>目标用户（管理员可改他人；留空=自己）</label><select id="m_pw_uid"><option value="">我自己</option>${(r.data||[]).map(u=>`<option value="${u.id}">${esc(u.username)}</option>`).join('')}</select>`;
  }
  openModal(`<h3>修改密码</h3>${targetSel}
    <label>新密码（≥6 位）</label><input id="m_pw_new" type="password">
    <label>确认新密码</label><input id="m_pw_cfm" type="password">
    <div id="pw_err" class="muted" style="color:var(--err)"></div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="savePw()">保存</button></div>`);
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
  openModal(`<h3>修改用户 #${id} 密码</h3>
    <label>新密码（≥6 位）</label><input id="m_pw_new" type="password">
    <label>确认新密码</label><input id="m_pw_cfm" type="password">
    <div id="pw_err" class="muted" style="color:var(--err)"></div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="savePwFor(${id})">保存</button></div>`);
}
async function savePwFor(id){
  const np=v('m_pw_new'), cf=v('m_pw_cfm'); const err=document.getElementById('pw_err');
  if(np.length<6){ err.textContent='密码至少 6 位'; return; }
  if(np!==cf){ err.textContent='两次输入不一致'; return; }
  const r=await api('POST','/api/users/password',{user_id:id,new_password:np}); if(r.error){err.textContent=r.error;return;} closeModal(); alert('已修改该用户密码');
}

// 表单
const randHex = (n) => Array.from({length:n},()=>Math.floor(Math.random()*16).toString(16)).join('');
function newApplication(){ openModal(`<h3>新建应用</h3><label>名称</label><input id="m_name">
  <label>AppEUI（可选，留空自动随机生成）</label>
  <div class="row"><div><input id="m_app_eui" placeholder="0000000000000000"></div><div style="flex:0 0 auto"><button class="ghost" type="button" onclick="document.getElementById('m_app_eui').value=randHex(8)">随机生成</button></div></div>
  <label>回调 URL（可选，设备上行/遥测 Webhook，留空不回调）</label><input id="m_cb" placeholder="https://example.com/uplink">
  <label>描述</label><input id="m_desc">
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="saveApp()">保存</button></div>`); }
async function saveApp(){ const r = await api('POST','/api/applications',{name:v('m_name'),app_eui:v('m_app_eui'),callback_url:v('m_cb'),description:v('m_desc')}); if(r.error){alert(r.error);return;} closeModal(); viewApplications(); }
async function editApplication(id){ const r = await api('GET','/api/applications'); const a = (r.data||[]).find(x=>x.id===id); if(!a)return;
  openModal(`<h3>编辑应用 #${id}</h3><label>名称</label><input id="m_name" value="${esc(a.name)}">
  <label>AppEUI</label><div class="row"><div><input id="m_app_eui" value="${esc(a.app_eui)}"></div><div style="flex:0 0 auto"><button class="ghost" type="button" onclick="document.getElementById('m_app_eui').value=randHex(8)">随机生成</button></div></div></label>
  <label>回调 URL</label><input id="m_cb" value="${esc(a.callback_url||'')}" placeholder="https://example.com/uplink">
  <label>描述</label><input id="m_desc" value="${esc(a.description)}">
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="saveAppEdit(${id})">保存</button></div>`); }
async function saveAppEdit(id){ const r = await api('PUT',`/api/applications/${id}`,{name:v('m_name'),app_eui:v('m_app_eui'),callback_url:v('m_cb'),description:v('m_desc')}); if(r.error){alert(r.error);return;} closeModal(); viewApplications(); }
async function delApplication(id){ if(!confirm('确认删除该应用及其下所有设备？'))return; const r = await api('DELETE',`/api/applications/${id}`); if(r.error){alert(r.error);return;} viewApplications(); }

function newDevice(appId){ const regions=regionOptions("");
  openModal(`<h3>新建设备 (应用 #${appId})</h3><label>名称</label><input id="m_name"><label>DevEUI (16 hex)</label><input id="m_dev_eui"><label>激活方式</label><select id="m_act" onchange="toggleAct()"><option value="OTAA">OTAA</option><option value="ABP">ABP</option></select>
    <div id="otaa"><label>JoinEUI (16 hex)</label><input id="m_join_eui"><label>AppKey (32 hex)</label><input id="m_app_key"></div>
    <div id="abp" class="hidden"><label>DevAddr (8 hex)</label><input id="m_dev_addr"><label>NwkSKey (32 hex)</label><input id="m_nwk"><label>AppSKey (32 hex)</label><input id="m_app"></div>
    <label>Class</label><select id="m_class"><option>A</option><option>B</option><option>C</option></select>
    <label>区域</label><select id="m_region">${regions}</select>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="saveDevice(${appId})">保存</button></div>`); }
function toggleAct(){ const a=v('m_act')==='OTAA'; document.getElementById('otaa').classList.toggle('hidden',!a); document.getElementById('abp').classList.toggle('hidden',a); }
async function saveDevice(appId){ const act=v('m_act'); const body={app_id:appId,name:v('m_name'),dev_eui:v('m_dev_eui'),activation:act,region:v('m_region'),class:v('m_class')};
  if(act==='OTAA'){ body.join_eui=v('m_join_eui'); body.app_key=v('m_app_key'); } else { body.dev_addr=v('m_dev_addr'); body.nwk_s_key=v('m_nwk'); body.app_s_key=v('m_app'); }
  const r = await api('POST','/api/devices',body); if(r.error){alert(r.error);return;} closeModal(); viewDevices(); }
async function editDevice(id){ const r = await api('GET','/api/devices'); const d=(r.data||[]).find(x=>x.id===id); if(!d)return;
  const otaa=d.activation==='OTAA';
  openModal(`<h3>编辑设备 #${id}</h3><label>名称</label><input id="m_name" value="${esc(d.name)}">
    <label>激活方式</label><input value="${d.activation}" disabled>
    <label>Class</label><select id="m_class"><option ${d.class==='A'?'selected':''}>A</option><option ${d.class==='B'?'selected':''}>B</option><option ${d.class==='C'?'selected':''}>C</option></select>
    <label>区域</label><select id="m_region">${regionOptions(d.region)}</select>
    ${otaa?`<label>DevEUI (16 hex，留空不改)</label><input id="m_dev_eui" value="${esc(d.dev_eui)}" placeholder="留空保持不变"><label>JoinEUI (16 hex，留空不改)</label><input id="m_join_eui" value="${esc(d.join_eui)}" placeholder="留空保持不变"><label>AppKey (32 hex，留空不改)</label><input id="m_app_key" placeholder="留空保持不变">`:`<label>DevAddr (8 hex)</label><input id="m_dev_addr" value="${esc(d.dev_addr)}"><label>NwkSKey (32 hex)</label><input id="m_nwk" value="${esc(d.nwk_s_key)}"><label>AppSKey (32 hex)</label><input id="m_app" value="${esc(d.app_s_key)}"`}
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="saveDeviceEdit(${id})">保存</button></div>`); }
async function saveDeviceEdit(id){ const body={name:v('m_name'),class:v('m_class'),region:v('m_region')};
  if(document.getElementById('m_app_key')) body.app_key=v('m_app_key');
  if(document.getElementById('m_dev_eui')) body.dev_eui=v('m_dev_eui');
  if(document.getElementById('m_join_eui')) body.join_eui=v('m_join_eui');
  if(document.getElementById('m_dev_addr')){ body.dev_addr=v('m_dev_addr'); body.nwk_s_key=v('m_nwk'); body.app_s_key=v('m_app'); }
  const r = await api('PUT',`/api/devices/${id}`,body); if(r.error){alert(r.error);return;} closeModal(); viewDevices(); }
async function delDevice(id){ if(!confirm('确认删除该设备及其上下行记录？'))return; const r = await api('DELETE',`/api/devices/${id}`); if(r.error){alert(r.error);return;} viewDevices(); }

function newGateway(){ openModal(`<h3>新建网关</h3><label>Gateway ID (16/32 hex)</label><input id="m_gwid"><label>名称</label><input id="m_name"><label>区域</label><select id="m_region">${regionOptions("")}</select>
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="saveGateway()">保存</button></div>`); }
async function saveGateway(){ const r = await api('POST','/api/gateways',{gw_id:v('m_gwid'),name:v('m_name'),region:v('m_region')}); if(r.error){alert(r.error);return;} closeModal(); viewGateways(); }
async function editGateway(gwId){ const r = await api('GET','/api/gateways'); const g=(r.data||[]).find(x=>x.gw_id===gwId); if(!g)return;
  openModal(`<h3>编辑网关 ${gwId}</h3><label>名称</label><input id="m_name" value="${esc(g.name)}"><label>区域</label><select id="m_region">${regionOptions(g.region)}</select>
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="saveGatewayEdit('${gwId}')">保存</button></div>`); }
async function saveGatewayEdit(gwId){ const r = await api('PUT',`/api/gateways/${gwId}`,{name:v('m_name'),region:v('m_region')}); if(r.error){alert(r.error);return;} closeModal(); viewGateways(); }
async function delGateway(gwId){ if(!confirm('确认删除该网关？'))return; const r = await api('DELETE',`/api/gateways/${gwId}`); if(r.error){alert(r.error);return;} viewGateways(); }

function downlink(devId){ openModal(`<h3>下发数据 (设备 #${devId})</h3><label>端口 (1..223)</label><input id="m_port" value="10"><label>Hex 负载</label><input id="m_payload" placeholder="48656c6c6f"><label><input type="checkbox" id="m_confirmed"> 确认下行 (Confirmed)</label>
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="sendDown(${devId})">发送</button></div>`); }
async function sendDown(devId){ const r = await api('POST',`/api/devices/${devId}/downlink`,{port:+v('m_port'),payload:v('m_payload'),confirmed:document.getElementById('m_confirmed').checked}); if(r.error){alert(r.error);return;} closeModal(); alert('已加入下行队列（Class C 立即下发；Class A 于下次上行 RX1/RX2；Class B 于 ping 时隙下发）。'); }

function newUser(){ openModal(`<h3>新建用户</h3><label>用户名</label><input id="m_user"><label>密码（≥6 位）</label><input id="m_pass" type="password"><label>角色</label><select id="m_role"><option value="operator">operator（只读+下行）</option><option value="admin">admin（全部权限）</option></select>
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="saveUser()">保存</button></div>`); }
async function saveUser(){ const r = await api('POST','/api/users',{username:v('m_user'),password:v('m_pass'),role:v('m_role')}); if(r.error){alert(r.error);return;} closeModal(); viewUsers(); }
async function delUser(id){ if(!confirm('确认删除该用户？'))return; const r = await api('DELETE',`/api/users/${id}`); if(r.error){alert(r.error);return;} viewUsers(); }

function openModal(html){ document.getElementById('modalBox').innerHTML=html; document.getElementById('modal').classList.add('show'); }
function closeModal(){ document.getElementById('modal').classList.remove('show'); }
document.getElementById('modal').onclick=e=>{ if(e.target.id==='modal') closeModal(); };
function v(id){ return document.getElementById(id).value.trim(); }

boot();
</script>
</body>
</html>
HTML;
}
