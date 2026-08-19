function newApplication(){ openModal(`<h3>新建应用</h3><label>名称</label><input id="m_name">
  <label>AppEUI（可选，留空自动随机生成）</label>
  <div class="row"><div><input id="m_app_eui" placeholder="0000000000000000" oninput="hexOnly(this)"></div><div style="flex:0 0 auto"><button class="ghost" type="button" onclick="document.getElementById('m_app_eui').value=randHex(8)">随机生成</button></div></div>
  <label>回调 URL（可选，设备上行/遥测 Webhook，留空不回调）</label><input id="m_cb" placeholder="https://example.com/uplink">
  <label>描述</label><input id="m_desc">
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', saveApp)">保存</button></div>`); }
async function saveApp(){ const r = await api('POST','/api/applications',{name:v('m_name'),app_eui:v('m_app_eui'),callback_url:v('m_cb'),description:v('m_desc')}); if(r.error){alert(t(r.error));return;} closeModal(); viewApplications(); }
async function editApplication(id){ const r = await api('GET','/api/applications'); const a = (r.data||[]).find(x=>x.id===id); if(!a)return;
  openModal(`<h3>${t('编辑应用')} #${id}</h3><label>名称</label><input id="m_name" value="${esc(a.name)}">
  <label>AppEUI</label><div class="row"><div><input id="m_app_eui" value="${esc(a.app_eui)}" oninput="hexOnly(this)"></div><div style="flex:0 0 auto"><button class="ghost" type="button" onclick="document.getElementById('m_app_eui').value=randHex(8)">随机生成</button></div></div></label>
  <label>回调 URL</label><input id="m_cb" value="${esc(a.callback_url||'')}" placeholder="https://example.com/uplink">
  <label>描述</label><input id="m_desc" value="${esc(a.description)}">
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveAppEdit(${id}))">保存</button></div>`); }
async function saveAppEdit(id){ const r = await api('PUT',`/api/applications/${id}`,{name:v('m_name'),app_eui:v('m_app_eui'),callback_url:v('m_cb'),description:v('m_desc')}); if(r.error){alert(t(r.error));return;} closeModal(); viewApplications(); }
async function delApplication(id){ confirmDlg('确认删除该应用及其下所有设备？', async ()=>{ const r = await api('DELETE',`/api/applications/${id}`); if(r.error){alert(t(r.error));return;} viewApplications(); }); }

async function newDevice(appId){ const regions=regionOptions(""); const dps=await dpOptions(0);
  const ar = await api('GET','/api/applications'); const apps = ar.data||[];
  const appOpts = apps.map(a=>`<option value="${a.id}" ${String(a.id)===String(appId)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('');
  const appSel = apps.length ? `<label>应用</label><select id="m_app_sel" ${appId?'disabled':''}>${appOpts}</select>` : '<p class="muted">系统中暂无应用，请先在「应用」页面创建应用。</p>';
  openModal(`<h3>${t('新建设备')}${appId?` (${t('应用')} #${appId})`:''}</h3>${appSel}<label>名称</label><input id="m_name"><label>DevEUI (16 hex)</label><input id="m_dev_eui" oninput="hexOnly(this)"><label>激活方式</label><select id="m_act" onchange="toggleAct()"><option value="OTAA">OTAA</option><option value="ABP">ABP</option></select>
    <div id="otaa"><label>JoinEUI (16 hex)</label><input id="m_join_eui" oninput="hexOnly(this)"><label>AppKey (32 hex)</label><input id="m_app_key" oninput="hexOnly(this)"></div>
    <div id="abp" class="hidden"><label>DevAddr (8 hex)</label><input id="m_dev_addr" oninput="hexOnly(this)"><label>NwkSKey (32 hex)</label><input id="m_nwk" oninput="hexOnly(this)"><label>AppSKey (32 hex)</label><input id="m_app" oninput="hexOnly(this)"></div>
    <label>Class</label><select id="m_class"><option>A</option><option>B</option><option>C</option></select>
    <label>区域</label><select id="m_region">${regions}</select>
    <label>设备模板 (可选)</label><select id="m_dp">${dps}</select>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveDevice(${appId||0}))">保存</button></div>`); }
function toggleAct(){ const a=v('m_act')==='OTAA'; document.getElementById('otaa').classList.toggle('hidden',!a); document.getElementById('abp').classList.toggle('hidden',a); }
async function saveDevice(appId){ const sel=document.getElementById('m_app_sel'); const finalAppId = appId ? appId : (sel ? +sel.value : 0); if(!finalAppId){alert('请先选择应用');return;}
  const act=v('m_act'); const body={app_id:finalAppId,name:v('m_name'),dev_eui:v('m_dev_eui'),activation:act,region:v('m_region'),class:v('m_class'),device_profile_id:+v('m_dp')};
  if(act==='OTAA'){ body.join_eui=v('m_join_eui'); body.app_key=v('m_app_key'); } else { body.dev_addr=v('m_dev_addr'); body.nwk_s_key=v('m_nwk'); body.app_s_key=v('m_app'); }
  const r = await api('POST','/api/devices',body); if(r.error){alert(t(r.error));return;} closeModal(); viewDevices(); }
async function editDevice(id){ const r = await api('GET','/api/devices'); const d=(r.data||[]).find(x=>x.id===id); if(!d)return;
  const otaa=d.activation==='OTAA'; const dps=await dpOptions(d.device_profile_id||0);
  openModal(`<h3>${t('编辑设备')} #${id}</h3><label>名称</label><input id="m_name" value="${esc(d.name)}">
    <label>激活方式</label><input value="${d.activation}" disabled>
    <label>Class</label><select id="m_class"><option ${d.class==='A'?'selected':''}>A</option><option ${d.class==='B'?'selected':''}>B</option><option ${d.class==='C'?'selected':''}>C</option></select>
    <label>区域</label><select id="m_region">${regionOptions(d.region)}</select>
    <label>设备模板 (可选)</label><select id="m_dp">${dps}</select>
    ${otaa?`<label>DevEUI (16 hex，留空不改)</label><input id="m_dev_eui" value="${esc(d.dev_eui)}" placeholder="留空保持不变" oninput="hexOnly(this)"><label>JoinEUI (16 hex，留空不改)</label><input id="m_join_eui" value="${esc(d.join_eui)}" placeholder="留空保持不变" oninput="hexOnly(this)"><label>AppKey (32 hex，留空不改)</label><input id="m_app_key" placeholder="留空保持不变" oninput="hexOnly(this)">`:`<label>DevAddr (8 hex)</label><input id="m_dev_addr" value="${esc(d.dev_addr)}" oninput="hexOnly(this)"><label>NwkSKey (32 hex)</label><input id="m_nwk" value="${esc(d.nwk_s_key)}" oninput="hexOnly(this)"><label>AppSKey (32 hex)</label><input id="m_app" value="${esc(d.app_s_key)}" oninput="hexOnly(this)"`}
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveDeviceEdit(${id}))">保存</button></div>`); }
async function saveDeviceEdit(id){ const body={name:v('m_name'),class:v('m_class'),region:v('m_region'),device_profile_id:+v('m_dp')};
  if(document.getElementById('m_app_key')) body.app_key=v('m_app_key');
  if(document.getElementById('m_dev_eui')) body.dev_eui=v('m_dev_eui');
  if(document.getElementById('m_join_eui')) body.join_eui=v('m_join_eui');
  if(document.getElementById('m_dev_addr')){ body.dev_addr=v('m_dev_addr'); body.nwk_s_key=v('m_nwk'); body.app_s_key=v('m_app'); }
  const r = await api('PUT',`/api/devices/${id}`,body); if(r.error){alert(t(r.error));return;} closeModal(); viewDevices(); }
async function delDevice(id){ confirmDlg('确认删除该设备及其上下行记录？', async ()=>{ const r = await api('DELETE',`/api/devices/${id}`); if(r.error){alert(t(r.error));return;} viewDevices(); }); }

function newGateway(){ openModal(`<h3>新建网关</h3><label>Gateway ID (16/32 hex)</label><input id="m_gwid" oninput="hexOnly(this)"><label>名称</label><input id="m_name"><label>区域</label><select id="m_region">${regionOptions("")}</select>
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', saveGateway)">保存</button></div>`); }
async function saveGateway(){ const r = await api('POST','/api/gateways',{gw_id:v('m_gwid'),name:v('m_name'),region:v('m_region')}); if(r.error){alert(t(r.error));return;} closeModal(); viewGateways(); }
async function editGateway(gwId){ const r = await api('GET','/api/gateways'); const g=(r.data||[]).find(x=>x.gw_id===gwId); if(!g)return;
  openModal(`<h3>${t('编辑网关')} ${gwId}</h3><label>名称</label><input id="m_name" value="${esc(g.name)}"><label>区域</label><select id="m_region">${regionOptions(g.region)}</select>
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveGatewayEdit('${gwId}'))">保存</button></div>`); }
async function saveGatewayEdit(gwId){ const r = await api('PUT',`/api/gateways/${gwId}`,{name:v('m_name'),region:v('m_region')}); if(r.error){alert(t(r.error));return;} closeModal(); viewGateways(); }
async function delGateway(gwId){ confirmDlg('确认删除该网关？', async ()=>{ const r = await api('DELETE',`/api/gateways/${gwId}`); if(r.error){alert(t(r.error));return;} viewGateways(); }); }

function downlink(devId){ openModal(`<h3>${t('下发数据')} (${t('设备')} #${devId})</h3><label>端口 (1..223)</label><input id="m_port" value="10"><label>Hex 负载</label><input id="m_payload" placeholder="48656c6c6f"><label class="check"><input type="checkbox" id="m_confirmed"> 确认下行 (Confirmed)</label>
  <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('发送中…', ()=>sendDown(${devId}))">发送</button></div>`); }
async function sendDown(devId){ const r = await api('POST',`/api/devices/${devId}/downlink`,{port:+v('m_port'),payload:v('m_payload'),confirmed:document.getElementById('m_confirmed').checked}); if(r.error){alert(t(r.error));return;} closeModal(); alert('已加入下行队列（Class C 立即下发；Class A 于下次上行 RX1/RX2；Class B 于 ping 时隙下发）。'); }

async function newUser(){
  let tenants = '';
  try { const r = await api('GET','/api/tenants'); tenants = (r.data||[]).map(row=>`<option value="${row.id}">${esc(row.name)}</option>`).join(''); } catch(e){}
  openModal(`<h3>新建用户</h3><label>用户名</label><input id="m_user"><label>密码（≥6 位）</label><input id="m_pass" type="password">
    <label>角色</label><select id="m_role" onchange="roleTenantToggle()">
      <option value="operator">operator（演示：只读 + 模拟数据）</option>
      <option value="tenant">用户配置（仅本用户配置数据，可写）</option>
      <option value="admin">admin（全部权限）</option>
    </select>
    <div id="m_tenant_box" class="hidden"><label>绑定用户配置（用户配置角色；留空则自动新建同名用户配置）</label>
      <select id="m_tenant"><option value="">— 自动新建同名用户配置 —</option>${tenants}</select>
    </div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', saveUser)">保存</button></div>`);
  roleTenantToggle();
}
function roleTenantToggle(){
  const box = document.getElementById('m_tenant_box');
  if (box) box.classList.toggle('hidden', (document.getElementById('m_role')||{}).value !== 'tenant');
}
async function saveUser(){
  const role = v('m_role');
  const body = {username:v('m_user'), password:v('m_pass'), role};
  if (role === 'tenant') {
    const t = v('m_tenant');
    if (t && +t > 0) body.tenant_id = +t;
    else body.new_tenant_name = v('m_user'); 
  }
  const r = await api('POST','/api/users',body); if(r.error){alert(t(r.error));return;} closeModal(); viewUsers();
}
async function delUser(id){ confirmDlg('确认删除该用户？', async ()=>{ const r = await api('DELETE',`/api/users/${id}`); if(r.error){alert(t(r.error));return;} viewUsers(); }); }

async function editUser(id){
  const r = await api('GET','/api/users');
  const u = (r.data||[]).find(x=>x.id===id); if(!u) return;
  let tenants = '';
  try { const tr = await api('GET','/api/tenants'); tenants = (tr.data||[]).map(row=>`<option value="${row.id}" ${String(row.id)===String(u.tenant_id)?'selected':''}>${esc(row.name)}</option>`).join(''); } catch(e){}
  const isSelf = state.user && state.user.id === id;
  openModal(`<h3>编辑用户 #${id}（${esc(u.username)}）</h3>
    <label>用户名</label><input id="m_user" value="${esc(u.username)}" disabled>
    <label>角色</label><select id="m_role" onchange="roleTenantToggle()" ${isSelf?'disabled':''}>
      <option value="operator" ${u.role==='operator'?'selected':''}>operator（演示：只读 + 模拟数据）</option>
      <option value="tenant" ${u.role==='tenant'?'selected':''}>用户配置（仅本用户配置数据，可写）</option>
      <option value="admin" ${u.role==='admin'?'selected':''}>admin（全部权限）</option>
    </select>
    <div id="m_tenant_box" class="${u.role==='tenant'?'':'hidden'}"><label>绑定用户配置（留空则自动新建同名用户配置）</label>
      <select id="m_tenant"><option value="">— 自动新建同名用户配置 —</option>${tenants}</select>
    </div>
    <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveUserEdit(${id}))">保存</button></div>`);
  roleTenantToggle();
}
async function saveUserEdit(id){
  const role = v('m_role');
  const body = {role};
  if (role === 'tenant') {
    const t = v('m_tenant');
    if (t && +t > 0) body.tenant_id = +t;
    else body.new_tenant_name = v('m_user');
  }
  const r = await api('PUT',`/api/users/${id}`,body); if(r.error){alert(t(r.error));return;} closeModal(); viewUsers();
}


async function dpOptions(sel){
  if(!state.dps.length){ const r=await api('GET','/api/device-profiles'); state.dps=r.data||[]; }
  return `<option value="0" ${(sel==0||sel===''||sel==null)?'selected':''}>默认模板</option>`+(state.dps||[]).map(d=>`<option value="${d.id}" ${String(d.id)===String(sel)?'selected':''}>${esc(d.name)}</option>`).join('');
}


function tenantForm(d){
  d = d || {};
  const unlimited = +d.private_gateways_unlimited === 1;
  const limit = d.private_gateways_limit || 0;
  return `<label>${t('名称')}</label><input id="t_name" value="${esc(d.name||'')}">
  <label>${t('描述')}</label><input id="t_desc" value="${esc(d.description||'')}">
  <div class="row" style="align-items:flex-end">
    <div><label>${t('启用私有网关上限')}</label>
      <label class="check" style="margin:6px 0 0">
        <input type="checkbox" id="t_unlimited" ${unlimited?'':'checked'} onchange="document.getElementById('t_limit_div').style.display=this.checked?'':'none'">
        <span>${unlimited?t('已关闭（无限额）'):t('已启用（受上限约束）')}</span>
      </label>
      <div class="muted" style="font-size:11px;margin-top:4px">${t('取消勾选后该用户配置可创建任意数量的网关；勾选时按下方上限约束。')}</div>
    </div>
    <div id="t_limit_div" style="${unlimited?'display:none':''}"><label>${t('私有网关上限')}</label><input id="t_limit" type="number" min="0" value="${limit}"><div class="muted" style="font-size:11px;margin-top:4px">${t('0 = 不允许创建网关；正值 = 允许的最大私有网关数。')}</div></div>
  </div>`;
}
function newTenant(){
  
  openModal(`<h3>${t('新建用户配置')}</h3>${tenantForm({ private_gateways_unlimited: 0, private_gateways_limit: 0 })}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">${t('取消')}</button><button onclick="busy('保存中…', ()=>saveTenant(0))">${t('保存')}</button></div>`);
}
async function saveTenant(id){
  const unlimited = !document.getElementById('t_unlimited').checked; 
  const body = {
    name: v('t_name'),
    description: v('t_desc'),
    private_gateways_unlimited: unlimited ? 1 : 0,
    private_gateways_limit: +v('t_limit') || 0,
  };
  const r = id ? await api('PUT',`/api/tenants/${id}`,body) : await api('POST','/api/tenants',body);
  if(r.error){alert(t(r.error));return;} closeModal(); viewTenants();
}
async function editTenant(id){
  const row = state.tenants.find(x=>x.id==id)||{};
  openModal(`<h3>${t('编辑用户配置')}</h3>${tenantForm(row)}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">${t('取消')}</button><button onclick="busy('保存中…', ()=>saveTenant(${id}))">${t('保存')}</button></div>`);
}
async function delTenant(id){
  confirmDlg(t('删除用户配置？其下资源将回退到默认用户配置。'), async ()=>{ const r = await api('DELETE',`/api/tenants/${id}`); if(r.error){alert(t(r.error));return;} viewTenants(); });
}
function deviceProfileForm(d){
  d = d||{};
  const regions=regionOptions(d.region||"");
  const codec = (sel)=>['NONE','CAYENNE_LPP','JS'].map(c=>`<option value="${c}" ${c===sel?'selected':''}>${c}</option>`).join('');
  const yesno=(v)=>`<option value="1" ${v?'selected':''}>是</option><option value="0" ${v?'':'selected'}>否</option>`;
  return `<label>名称</label><input id="m_name" value="${esc(d.name||'')}">
  <label>描述</label><input id="m_desc" value="${esc(d.description||'')}">
  <div class="row">
    <div><label>区域</label><select id="m_region">${regions}</select></div>
    <div><label>MAC 版本</label><input id="m_mac" value="${esc(d.mac_version||'1.0.4')}"></div>
    <div><label>区域参数版本</label><input id="m_reg" value="${esc(d.reg_params_revision||'RP002-1.0.3')}"></div>
  </div>
  <div class="row">
    <div><label>ADR 算法</label><input id="m_adr" value="${esc(d.adr_algorithm||'default')}"></div>
    <div><label>编解码运行时</label><select id="m_codec">${codec(d.payload_codec_runtime||'NONE')}</select></div>
  </div>
  <label>编解码脚本（仅 NONE / CAYENNE_LPP 生效）</label><textarea id="m_script">${esc(d.payload_codec_script||'')}</textarea>
  <div class="row">
    <div><label>支持 OTAA</label><select id="m_otaa">${yesno(d.supports_otaa)}</select></div>
    <div><label>支持 Class B</label><select id="m_cb">${yesno(d.supports_class_b)}</select></div>
    <div><label>支持 Class C</label><select id="m_cc">${yesno(d.supports_class_c)}</select></div>
  </div>
  <div class="row">
    <div><label>激活清空队列</label><select id="m_flush">${yesno(d.flush_queue_on_activate)}</select></div>
    <div><label>上行间隔(s,0=不限)</label><input id="m_upl" value="${d.uplink_interval||0}"></div>
    <div><label>状态查询间隔(s,0=关)</label><input id="m_streq" value="${d.device_status_req_interval||0}"></div>
  </div>
  <div class="row">
    <div><label>ClassB Ping 周期</label><input id="m_bpp" value="${d.class_b_ping_slot_periodicity||0}"></div>
    <div><label>ClassB Ping DR</label><input id="m_bpd" value="${d.class_b_ping_slot_dr||0}"></div>
    <div><label>ClassB Ping 频率</label><input id="m_bpf" value="${d.class_b_ping_slot_freq||0}"></div>
  </div>
  <div class="row">
    <div><label>ClassC 超时(s)</label><input id="m_cto" value="${d.class_c_timeout||0}"></div>
    <div><label>ABP RX1 Delay</label><input id="m_ard" value="${d.abp_rx1_delay||1}"></div>
    <div><label>ABP RX1 DR Offset</label><input id="m_ardo" value="${d.abp_rx1_dr_offset||0}"></div>
  </div>
  <div class="row">
    <div><label>ABP RX2 DR</label><input id="m_ar2d" value="${d.abp_rx2_dr||0}"></div>
    <div><label>ABP RX2 频率</label><input id="m_ar2f" value="${d.abp_rx2_freq||0}"></div>
    <div><label>允许漫游</label><select id="m_roam">${yesno(d.allow_roaming)}</select></div>
  </div>`;
}
function newDeviceProfile(){
  openModal(`<h3>新建设备模板</h3>${deviceProfileForm()}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveDeviceProfile(0))">保存</button></div>`);
}
async function saveDeviceProfile(id){
  const body={
    name:v('m_name'), description:v('m_desc'), region:v('m_region'),
    mac_version:v('m_mac'), reg_params_revision:v('m_reg'), adr_algorithm:v('m_adr'),
    payload_codec_runtime:v('m_codec'), payload_codec_script:v('m_script'),
    supports_otaa:+v('m_otaa'), supports_class_b:+v('m_cb'), supports_class_c:+v('m_cc'),
    flush_queue_on_activate:+v('m_flush'), uplink_interval:+v('m_upl'), device_status_req_interval:+v('m_streq'),
    class_b_ping_slot_periodicity:+v('m_bpp'), class_b_ping_slot_dr:+v('m_bpd'), class_b_ping_slot_freq:+v('m_bpf'),
    class_c_timeout:+v('m_cto'), abp_rx1_delay:+v('m_ard'), abp_rx1_dr_offset:+v('m_ardo'),
    abp_rx2_dr:+v('m_ar2d'), abp_rx2_freq:+v('m_ar2f'), allow_roaming:+v('m_roam')
  };
  const r = id ? await api('PUT',`/api/device-profiles/${id}`,body) : await api('POST','/api/device-profiles',body);
  if(r.error){alert(t(r.error));return;} closeModal(); viewDeviceProfiles();
}
async function editDeviceProfile(id){
  const r = await api('GET','/api/device-profiles'); const d=(r.data||[]).find(x=>x.id===id); if(!d)return;
  openModal(`<h3>${t('编辑模板')} #${id}</h3>${deviceProfileForm(d)}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveDeviceProfile(${id}))">保存</button></div>`);
}
async function delDeviceProfile(id){ confirmDlg('确认删除该模板？引用该模板的设备将回退到默认模板。', async ()=>{ const r=await api('DELETE',`/api/device-profiles/${id}`); if(r.error){alert(t(r.error));return;} viewDeviceProfiles(); }); }


function newApiKey(){
  if(!state.appSel){alert('请先选择应用');return;}
  openModal(`<h3>${t('新建 API 密钥')} (${t('应用')} #${state.appSel})</h3><label>名称</label><input id="m_name">
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', saveApiKey)">保存</button></div>`);
}
async function saveApiKey(){
  const r=await api('POST','/api/api-keys',{application_id:+state.appSel,name:v('m_name')});
  if(r.error){alert(t(r.error));return;}
  const token=r.token||'';
  openModal(`<h3>API 密钥已创建</h3><p class="muted">请立即复制保存，关闭后将无法再查看明文：</p>
   <label>Token</label><input id="m_tok" value="${esc(token)}" readonly onclick="this.select()">
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button onclick="(navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('m_tok').value));closeModal();viewApiKeys()">我已复制，关闭</button></div>`);
}
async function delApiKey(id){ confirmDlg('确认删除该 API 密钥？', async ()=>{ const r=await api('DELETE',`/api/api-keys/${id}`); if(r.error){alert(t(r.error));return;} viewApiKeys(); }); }


function newIntegration(){
  if(!state.intAppSel){alert('请先选择应用');return;}
  const httpFields=`<div id="f_http"><label>HTTP URL</label><input id="m_url" placeholder="https://example.com/uplink"><label>Headers (JSON, 可选)</label><input id="m_headers" placeholder='{"X-Api-Key":"..."}'></div>`;
  const influxFields=`<div id="f_influx" class="hidden"><label>InfluxDB Endpoint</label><input id="m_endpoint" placeholder="http://localhost:8086/api/v2/write"><label>Measurement (可选)</label><input id="m_measurement" placeholder="device_uplink"><label>Token (可选)</label><input id="m_token" placeholder="Token xxx"></div>`;
  const mqttFields=`<div id="f_mqtt" class="hidden"><label>Server</label><input id="m_server" placeholder="tcp://127.0.0.1:1883"><label>Topic 模板</label><input id="m_topic" placeholder="application/{app_id}/device/{dev_eui}/up"><label>QoS</label><select id="m_qos"><option>0</option><option>1</option></select><label>用户名(可选)</label><input id="m_user"><label>密码(可选)</label><input id="m_pass" type="password"></div>`;
  const awsFields=`<div id="f_aws" class="hidden"><label>AWS Region</label><input id="m_aws_region" placeholder="eu-west-1"><label>Access Key ID</label><input id="m_aws_key"><label>Secret Access Key</label><input id="m_aws_secret" type="password"><label>Topic ARN</label><input id="m_aws_topic" placeholder="arn:aws:sns:eu-west-1:123456789012:my-topic"></div>`;
  const azureFields=`<div id="f_azure" class="hidden"><label>Connection String</label><input id="m_az_conn" placeholder="Endpoint=sb://ns.servicebus.windows.net/;SharedAccessKeyName=...;SharedAccessKey=..."><label>Publish Mode</label><select id="m_az_mode"><option value="topic">topic</option><option value="queue">queue</option></select><label>Topic/Queue Name</label><input id="m_az_name"></div>`;
  const gcpFields=`<div id="f_gcp" class="hidden"><label>Project ID</label><input id="m_gcp_project"><label>Topic Name</label><input id="m_gcp_topic"><label>Credentials JSON (服务账号)</label><textarea id="m_gcp_cred" placeholder='{"type":"service_account","project_id":"...","private_key":"...","client_email":"..."}'></textarea><label>或 Credentials 文件</label><input id="m_gcp_credfile" placeholder="/path/to/sa.json"></div>`;
  const amqpFields=`<div id="f_amqp" class="hidden"><label>AMQP URL</label><input id="m_amqp_url" placeholder="amqp://user:pass@host:5672"><label>Exchange</label><input id="m_amqp_exchange" placeholder="amq.topic"><label>Routing Key 模板</label><input id="m_amqp_rk" placeholder="application.{app_id}.device.{dev_eui}.event.{event}"></div>`;
  const kafkaFields=`<div id="f_kafka" class="hidden"><label>Brokers</label><input id="m_kafka_brokers" placeholder="host1:9092,host2:9092"><label>Topic</label><input id="m_kafka_topic"><label>TLS</label><select id="m_kafka_tls"><option value="0">否</option><option value="1">是</option></select><label>SASL 用户名(可选)</label><input id="m_kafka_user"><label>SASL 密码(可选)</label><input id="m_kafka_pass" type="password"></div>`;
  openModal(`<h3>${t('新建外部集成')} (${t('应用')} #${state.intAppSel})</h3>
   <label>类型</label><select id="m_kind" onchange="toggleIntFields()"><option value="HTTP">HTTP</option><option value="INFLUX_DB">InfluxDB</option><option value="MQTT_GLOBAL">MQTT</option><option value="AWS_SNS">AWS SNS</option><option value="AZURE_SERVICE_BUS">Azure Service Bus</option><option value="GCP_PUBSUB">GCP Pub/Sub</option><option value="AMQP">AMQP (RabbitMQ)</option><option value="KAFKA">Kafka</option></select>
   <label>启用</label><select id="m_enabled"><option value="1" selected>是</option><option value="0">否</option></select>
   ${httpFields}${influxFields}${mqttFields}${awsFields}${azureFields}${gcpFields}${amqpFields}${kafkaFields}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', saveIntegration)">保存</button></div>`);
}
function toggleIntFields(){
  const k=v('m_kind');
  const map={HTTP:'f_http',INFLUX_DB:'f_influx',MQTT_GLOBAL:'f_mqtt',AWS_SNS:'f_aws',AZURE_SERVICE_BUS:'f_azure',GCP_PUBSUB:'f_gcp',AMQP:'f_amqp',KAFKA:'f_kafka'};
  for(const id of ['f_http','f_influx','f_mqtt','f_aws','f_azure','f_gcp','f_amqp','f_kafka']){
    document.getElementById(id).classList.toggle('hidden', map[k]!==id);
  }
}
async function saveIntegration(){
  const kind=v('m_kind'); let config={};
  if(kind==='HTTP'){ config={url:v('m_url')}; const h=v('m_headers'); if(h){try{config.headers=JSON.parse(h)}catch(e){alert('Headers 不是合法 JSON');return;}} }
  else if(kind==='INFLUX_DB'){ config={endpoint:v('m_endpoint'),measurement:v('m_measurement'),token:v('m_token')}; }
  else if(kind==='MQTT_GLOBAL'){ config={server:v('m_server'),topic:v('m_topic'),qos:+v('m_qos'),username:v('m_user'),password:v('m_pass')}; }
  else if(kind==='AWS_SNS'){ config={aws_region:v('m_aws_region'),aws_access_key_id:v('m_aws_key'),aws_secret_access_key:v('m_aws_secret'),topic_arn:v('m_aws_topic')}; }
  else if(kind==='AZURE_SERVICE_BUS'){ config={connection_string:v('m_az_conn'),publish_mode:v('m_az_mode'),publish_name:v('m_az_name')}; }
  else if(kind==='GCP_PUBSUB'){ config={project_id:v('m_gcp_project'),topic_name:v('m_gcp_topic'),credentials_json:v('m_gcp_cred')||'',credentials_file:v('m_gcp_credfile')||''}; }
  else if(kind==='AMQP'){ config={url:v('m_amqp_url'),exchange:v('m_amqp_exchange'),routing_key_template:v('m_amqp_rk')}; }
  else if(kind==='KAFKA'){ config={brokers:v('m_kafka_brokers'),topic:v('m_kafka_topic'),tls:+v('m_kafka_tls'),username:v('m_kafka_user'),password:v('m_kafka_pass')}; }
  const body={application_id:+state.intAppSel, kind, enabled:+v('m_enabled'), config};
  const r=await api('POST','/api/integrations',body); if(r.error){alert(t(r.error));return;} closeModal(); viewIntegrations();
}
async function toggleIntegration(id,enabled){ const r=await api('PUT',`/api/integrations/${id}`,{enabled}); if(r.error){alert(t(r.error));return;} viewIntegrations(); }
async function delIntegration(id){ confirmDlg('确认删除该外部集成？', async ()=>{ const r=await api('DELETE',`/api/integrations/${id}`); if(r.error){alert(t(r.error));return;} viewIntegrations(); }); }


function multicastForm(m){
  m=m||{}; const regions=regionOptions(m.region||"");
  const type=(s)=>['A','B','C'].map(cls=>`<option value="${cls}" ${cls===s?'selected':''}>${cls}</option>`).join('');
  const sched=(s)=>['DELAY','FIXED'].map(t=>`<option value="${t}" ${t===s?'selected':''}>${t}</option>`).join('');
  const appOpts=(state.apps||[]).map(a=>`<option value="${a.id}" ${String(a.id)===String(m.application_id||state.appSel)?'selected':''}>#${a.id} ${esc(a.name)}</option>`).join('');
  return `<label>名称</label><input id="m_name" value="${esc(m.name||'')}">
   <div class="row"><div><label>应用</label><select id="m_app">${appOpts}</select></div>
     <div><label>区域</label><select id="m_region">${regions}</select></div>
     <div><label>组类型</label><select id="m_type">${type(m.group_type||'C')}</select></div></div>
   <div class="row"><div><label>MC Addr (8 hex)</label><input id="m_mcaddr" value="${esc(m.mc_addr||'')}" oninput="hexOnly(this)"></div>
     <div><label>MC NwkSKey (32 hex)</label><input id="m_mcnwk" value="${esc(m.mc_nwk_s_key||'')}" oninput="hexOnly(this)"></div>
     <div><label>MC AppSKey (32 hex)</label><input id="m_mcapp" value="${esc(m.mc_app_s_key||'')}" oninput="hexOnly(this)"></div></div>
   <div style="margin-bottom:8px"><button class="btn ghost" type="button" onclick="genMc()">随机生成组播密钥</button></div>
   <div class="row"><div><label>DR</label><input id="m_dr" value="${m.dr||0}"></div>
     <div><label>频率 (Hz,0=区域默认)</label><input id="m_freq" value="${m.frequency||0}"></div>
     <div><label>ClassB Ping 周期</label><input id="m_bpp" value="${m.class_b_ping_slot_periodicity||0}"></div></div>
   <div class="row"><div><label>ClassC 调度类型</label><select id="m_sched">${sched(m.class_c_scheduling_type||'DELAY')}</select></div></div>`;
}
function genMc(){ document.getElementById('m_mcaddr').value=randHex(8); document.getElementById('m_mcnwk').value=randHex(32); document.getElementById('m_mcapp').value=randHex(32); }
function newMulticast(){ if(!state.apps.length){alert('请先创建应用');return;} openModal(`<h3>新建组播组</h3>${multicastForm()}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveMulticast(0))">保存</button></div>`); }
async function saveMulticast(id){
  const body={name:v('m_name'),application_id:+v('m_app'),region:v('m_region'),group_type:v('m_type'),
    mc_addr:v('m_mcaddr'),mc_nwk_s_key:v('m_mcnwk'),mc_app_s_key:v('m_mcapp'),
    dr:+v('m_dr'),frequency:+v('m_freq'),class_b_ping_slot_periodicity:+v('m_bpp'),class_c_scheduling_type:v('m_sched')};
  const r= id?await api('PUT',`/api/multicast-groups/${id}`,body):await api('POST','/api/multicast-groups',body);
  if(r.error){alert(t(r.error));return;} closeModal(); viewMulticastGroups();
}
async function editMulticast(id){ const r=await api('GET',`/api/multicast-groups/${id}`); const m=r; if(!m||m.error){alert('未找到');return;}
  openModal(`<h3>${t('编辑组播组')} #${id}</h3>${multicastForm(m)}
   <div style="margin-top:16px;display:flex;gap:10px;justify-content:flex-end"><button class="ghost" onclick="closeModal()">取消</button><button onclick="busy('保存中…', ()=>saveMulticast(${id}))">保存</button></div>`); }
async function delMulticast(id){ confirmDlg('确认删除该组播组及其设备/网关/队列？', async ()=>{ const r=await api('DELETE',`/api/multicast-groups/${id}`); if(r.error){alert(t(r.error));return;} viewMulticastGroups(); }); }
async function enqueueMc(id){ const r=await api('POST',`/api/multicast-groups/${id}/enqueue`,{port:+v('m_port'),payload:v('m_payload')}); if(r.error){alert(t(r.error));return;} closeModal(); alert('已加入组播下发队列（NS 调度线程将按队列发送）。'); }
async function addMcDev(id){ const e=v('m_mcdev'); if(!e){alert('请输入 DevEUI');return;} const r=await api('POST',`/api/multicast-groups/${id}/devices`,{dev_eui:e}); if(r.error){alert(t(r.error));return;} mcDetail(id); }
async function rmMcDev(id,e){ const r=await api('DELETE',`/api/multicast-groups/${id}/devices`,{dev_eui:e}); if(r.error){alert(t(r.error));return;} mcDetail(id); }
async function addMcGw(id){ const e=v('m_mcgw'); if(!e){alert('请输入 Gateway ID');return;} const r=await api('POST',`/api/multicast-groups/${id}/gateways`,{gw_id:e}); if(r.error){alert(t(r.error));return;} mcDetail(id); }
async function rmMcGw(id,e){ const r=await api('DELETE',`/api/multicast-groups/${id}/gateways`,{gw_id:e}); if(r.error){alert(t(r.error));return;} mcDetail(id); }

