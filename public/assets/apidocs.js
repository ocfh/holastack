/* ============================================================================
 * holastack 应用开放 API 文档页（SPA 模块）
 * 左右分栏：左侧按分组列出每个 API（二级菜单），右侧展示详情 + 一键复制。
 * 由 index.php 在 <script src="/assets/apidocs.js"> 引入，通过 window.viewApiDocs 调用。
 * 所有样式以 .apidocs 作用域包裹，复用后台 CSS 变量（--bg/--panel/--acc/--ok...）。
 * ========================================================================== */
(function () {
  'use strict';

  var Ad_current = '';

  // ----------------------------- 数据：API 清单 -----------------------------
  var Ad_GROUPS = [
    {
      title: '应用概览',
      apis: [
        {
          id: 'info', method: 'GET', path: '/v1/info',
          title: '获取应用信息',
          desc: '返回当前 API Key 所属应用的基础信息与数据统计（设备数、上行数、下行数）。',
          params: [],
          respFields: [
            { name: 'application.id', type: 'int', desc: '应用 ID' },
            { name: 'application.name', type: 'string', desc: '应用名称' },
            { name: 'application.app_eui', type: 'string', desc: '应用 EUI（JoinEUI）' },
            { name: 'application.description', type: 'string', desc: '应用描述' },
            { name: 'counts.devices', type: 'int', desc: '该应用下设备总数' },
            { name: 'counts.uplinks', type: 'int', desc: '该应用累计上行消息数' },
            { name: 'counts.downlinks', type: 'int', desc: '该应用累计下行消息数' }
          ],
          respExample: {
            application: { id: 1, name: '我的传感器应用', app_eui: '0000000000000000', description: '' },
            counts: { devices: 12, uplinks: 4821, downlinks: 37 }
          },
          errors: [
            { code: '401 invalid_api_key', desc: 'API Key 缺失或无效' }
          ]
        }
      ]
    },
    {
      title: '设备管理',
      apis: [
        {
          id: 'devices', method: 'GET', path: '/v1/devices',
          title: '列出应用下所有设备',
          desc: '返回该应用下的全部设备列表。响应已剥离 app_key / nwk_s_key / app_s_key 等敏感密钥。',
          params: [],
          respFields: [
            { name: 'data[].id', type: 'int', desc: '设备 ID' },
            { name: 'data[].name', type: 'string', desc: '设备名称' },
            { name: 'data[].dev_eui', type: 'string', desc: '设备 EUI（16 hex）' },
            { name: 'data[].dev_addr', type: 'string', desc: '设备地址（ABP/已入网后）' },
            { name: 'data[].activation', type: 'string', desc: 'OTAA / ABP' },
            { name: 'data[].class', type: 'string', desc: '工作模式 A / B / C' },
            { name: 'data[].region', type: 'string', desc: '频段区域' },
            { name: 'data[].status', type: 'string', desc: 'pending / active' },
            { name: 'data[].online', type: 'string', desc: 'online / offline（按最近上报判定）' }
          ],
          respExample: {
            data: [
              { id: 3, name: '温湿度节点-01', dev_eui: 'aabbccddeeff0011', dev_addr: '01ff02aa', activation: 'OTAA', class: 'A', region: 'CN470', status: 'active', online: 'online', last_seen: '2026-08-16 21:00:12', created_at: 1754000000 }
            ]
          },
          errors: [
            { code: '401 invalid_api_key', desc: 'API Key 缺失或无效' }
          ]
        },
        {
          id: 'device-detail', method: 'GET', path: '/v1/devices/{dev_eui}',
          title: '获取单个设备详情',
          desc: '根据 DevEUI 查询单个设备及其上行/下行计数。设备必须属于该 API Key 所属应用，否则返回 404。',
          params: [
            { name: 'dev_eui', in: 'path', type: 'string', required: true, desc: '设备 EUI（16 hex，大小写均可）' }
          ],
          respFields: [
            { name: 'device', type: 'object', desc: '设备对象（同 /v1/devices 中的单条）' },
            { name: 'counts.uplinks', type: 'int', desc: '该设备累计上行数' },
            { name: 'counts.downlinks', type: 'int', desc: '该设备累计下行数' }
          ],
          respExample: {
            device: { id: 3, name: '温湿度节点-01', dev_eui: 'aabbccddeeff0011', dev_addr: '01ff02aa', activation: 'OTAA', class: 'A', region: 'CN470', status: 'active', online: 'online', last_seen: '2026-08-16 21:00:12', created_at: 1754000000 },
            counts: { uplinks: 1205, downlinks: 9 }
          },
          errors: [
            { code: '401 invalid_api_key', desc: 'API Key 缺失或无效' },
            { code: '404 device_not_found', desc: '设备不存在或不归该应用所有' }
          ]
        },
        {
          id: 'device-uplinks', method: 'GET', path: '/v1/devices/{dev_eui}/uplinks',
          title: '获取设备上行数据',
          desc: '返回指定设备的最近上行消息列表（按 id 倒序）。',
          params: [
            { name: 'dev_eui', in: 'path', type: 'string', required: true, desc: '设备 EUI' },
            { name: 'limit', in: 'query', type: 'int', required: false, desc: '返回条数，默认 50，最大 500' }
          ],
          respFields: [
            { name: 'data[].id', type: 'int', desc: '上行记录 ID' },
            { name: 'data[].dev_addr', type: 'string', desc: '设备地址' },
            { name: 'data[].fcnt', type: 'int', desc: '帧计数' },
            { name: 'data[].port', type: 'int', desc: 'FPort' },
            { name: 'data[].confirmed', type: 'bool', desc: '是否为确认帧' },
            { name: 'data[].decrypted_hex', type: 'string', desc: '解密后的应用负载（hex）' },
            { name: 'data[].gateway_id', type: 'string', desc: '接收网关 ID' },
            { name: 'data[].rssi', type: 'int', desc: 'RSSI (dBm)' },
            { name: 'data[].snr', type: 'number', desc: 'SNR (dB)' },
            { name: 'data[].received_at', type: 'int', desc: '接收时间（Unix 秒）' }
          ],
          respExample: {
            data: [
              { id: 9981, dev_addr: '01ff02aa', fcnt: 1205, port: 10, confirmed: false, decrypted_hex: '48656c6c6f', gateway_id: '0080000000000001', rssi: -73, snr: 9.2, frequency: 486.3, data_rate: 'SF9BW125', received_at: 1755349212 }
            ]
          },
          errors: [
            { code: '401 invalid_api_key', desc: 'API Key 缺失或无效' },
            { code: '404 device_not_found', desc: '设备不存在或不归该应用所有' }
          ]
        }
      ]
    },
    {
      title: '消息数据',
      apis: [
        {
          id: 'uplinks', method: 'GET', path: '/v1/uplinks',
          title: '获取应用最近上行',
          desc: '返回该应用最近的上行消息（按 id 倒序）。可通过 dev_eui 过滤单设备。',
          params: [
            { name: 'dev_eui', in: 'query', type: 'string', required: false, desc: '仅返回该设备上行' },
            { name: 'limit', in: 'query', type: 'int', required: false, desc: '返回条数，默认 50，最大 500' }
          ],
          respFields: [
            { name: 'data[]', type: 'object[]', desc: '同 /v1/devices/{dev_eui}/uplinks 的 data 元素' }
          ],
          respExample: {
            data: [
              { id: 9981, dev_addr: '01ff02aa', fcnt: 1205, port: 10, confirmed: false, decrypted_hex: '48656c6c6f', gateway_id: '0080000000000001', rssi: -73, snr: 9.2, received_at: 1755349212 }
            ]
          },
          errors: [
            { code: '401 invalid_api_key', desc: 'API Key 缺失或无效' }
          ]
        },
        {
          id: 'downlinks', method: 'GET', path: '/v1/downlinks',
          title: '获取应用最近下行',
          desc: '返回该应用最近的下行队列/发送记录（按 id 倒序）。',
          params: [
            { name: 'dev_eui', in: 'query', type: 'string', required: false, desc: '仅返回该设备下行' },
            { name: 'limit', in: 'query', type: 'int', required: false, desc: '返回条数，默认 50，最大 500' }
          ],
          respFields: [
            { name: 'data[].id', type: 'int', desc: '下行记录 ID' },
            { name: 'data[].dev_id', type: 'int', desc: '目标设备 ID' },
            { name: 'data[].port', type: 'int', desc: 'FPort' },
            { name: 'data[].payload_hex', type: 'string', desc: '下行负载（hex）' },
            { name: 'data[].confirmed', type: 'bool', desc: '是否确认帧' },
            { name: 'data[].status', type: 'string', desc: 'pending / sent / acked / failed / timeout' },
            { name: 'data[].sent_at', type: 'int', desc: '实际发送时间（Unix 秒）' }
          ],
          respExample: {
            data: [
              { id: 412, dev_id: 3, port: 10, payload_hex: '48656c6c6f', confirmed: false, status: 'sent', sent_at: 1755349300 }
            ]
          },
          errors: [
            { code: '401 invalid_api_key', desc: 'API Key 缺失或无效' }
          ]
        }
      ]
    },
    {
      title: '下行控制',
      apis: [
        {
          id: 'downlink', method: 'POST', path: '/v1/devices/{dev_eui}/downlink',
          title: '下发下行数据',
          desc: '向指定设备入队一条下行。Class C 立即下发；Class A 于下次上行 RX1/RX2 窗口下发；Class B 于 ping 时隙下发。payload 为 hex 字符串。',
          params: [
            { name: 'dev_eui', in: 'path', type: 'string', required: true, desc: '目标设备 EUI' },
            { name: 'port', in: 'body', type: 'int', required: true, desc: 'FPort，范围 1–223' },
            { name: 'payload', in: 'body', type: 'string', required: true, desc: '应用负载，hex 字符串（长度需为偶数）' },
            { name: 'confirmed', in: 'body', type: 'bool', required: false, desc: '是否确认帧，默认 false' }
          ],
          sample: { port: 10, payload: '48656c6c6f', confirmed: false },
          respFields: [
            { name: 'id', type: 'int', desc: '下行记录 ID' },
            { name: 'status', type: 'string', desc: '入队状态，成功为 pending' }
          ],
          respExample: { id: 413, status: 'pending' },
          errors: [
            { code: '400', desc: '参数错误（port 越界 / payload 非 hex / 长度非偶数）' },
            { code: '401 invalid_api_key', desc: 'API Key 缺失或无效' },
            { code: '404 device_not_found', desc: '设备不存在或不归该应用所有' }
          ]
        }
      ]
    }
  ];

  function Ad_find(id) {
    for (var i = 0; i < Ad_GROUPS.length; i++) {
      for (var j = 0; j < Ad_GROUPS[i].apis.length; j++) {
        if (Ad_GROUPS[i].apis[j].id === id) return Ad_GROUPS[i].apis[j];
      }
    }
    return Ad_GROUPS[0].apis[0];
  }

  function escHtml(s) {
    return String(s).replace(/[&<>]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c];
    });
  }

  // 生成 curl 示例（基于当前站点 origin 动态拼接）
  function Ad_curl(a) {
    var url = location.origin + a.path;
    var head = '  -H "Authorization: Bearer <YOUR_API_KEY>"';
    if (a.method === 'POST') {
      return 'curl -X POST "' + url + '" \\\n' + head + ' \\\n  -H "Content-Type: application/json" \\\n  -d \'' + JSON.stringify(a.sample || {}) + '\'';
    }
    return 'curl -X ' + a.method + ' "' + url + '" \\\n' + head;
  }

  // ----------------------------- 左侧二级菜单 -----------------------------
  function Ad_side() {
    return Ad_GROUPS.map(function (g) {
      return '<h4>' + g.title + '</h4>' + g.apis.map(function (a) {
        return '<button class="ad-item' + (a.id === Ad_current ? ' active' : '') + '" onclick="adSelect(\'' + a.id + '\')">' +
          '<span class="ad-method m-' + a.method.toLowerCase() + '">' + a.method + '</span>' +
          '<span>' + a.title + '</span></button>';
      }).join('');
    }).join('');
  }

  // ----------------------------- 右侧详情 -----------------------------
  function Ad_detail(a) {
    var params = a.params && a.params.length
      ? a.params.map(function (p) {
          return '<tr><td><code>' + escHtml(p.name) + '</code></td><td>' + p.in + '</td><td>' + p.type + '</td><td>' +
            (p.required ? '<span class="tag err">必填</span>' : '<span class="tag off">可选</span>') +
            '</td><td class="ad-note">' + p.desc + '</td></tr>';
        }).join('')
      : '<tr><td colspan="5" class="ad-note">无参数</td></tr>';

    var resp = a.respFields && a.respFields.length
      ? a.respFields.map(function (f) {
          return '<tr><td><code>' + escHtml(f.name) + '</code></td><td>' + f.type + '</td><td class="ad-note">' + f.desc + '</td></tr>';
        }).join('')
      : '<tr><td colspan="3" class="ad-note">—</td></tr>';

    var errs = a.errors && a.errors.length
      ? a.errors.map(function (e) {
          return '<tr><td><code>' + escHtml(e.code) + '</code></td><td class="ad-note">' + e.desc + '</td></tr>';
        }).join('')
      : '<tr><td colspan="2" class="ad-note">—</td></tr>';

    var curl = Ad_curl(a);
    var respJson = JSON.stringify(a.respExample, null, 2);

    var bodyBlock = (a.method === 'POST')
      ? '<div class="ad-sec"><h3>请求体 (JSON)</h3>' + Ad_code(JSON.stringify(a.sample || {}, null, 2)) + '</div>'
      : '';

    return '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">' +
        '<span class="ad-method m-' + a.method.toLowerCase() + '">' + a.method + '</span>' +
        '<h2 style="margin:0">' + a.title + '</h2></div>' +
      '<p class="ad-note" style="margin-top:6px">' + a.desc + '</p>' +
      '<div class="ad-path"><code>' + a.method + ' ' + a.path + '</code>' + Ad_copyBtn() + '</div>' +
      '<div class="ad-sec"><h3>请求参数</h3><table class="ad-tbl"><thead><tr><th>参数</th><th>位置</th><th>类型</th><th>必填</th><th>说明</th></tr></thead><tbody>' + params + '</tbody></table></div>' +
      bodyBlock +
      '<div class="ad-sec"><h3>请求示例</h3>' + Ad_code(curl) + '</div>' +
      '<div class="ad-sec"><h3>响应字段</h3><table class="ad-tbl"><thead><tr><th>字段</th><th>类型</th><th>说明</th></tr></thead><tbody>' + resp + '</tbody></table></div>' +
      '<div class="ad-sec"><h3>响应示例</h3>' + Ad_code(respJson) + '</div>' +
      '<div class="ad-sec"><h3>错误码</h3><table class="ad-tbl"><thead><tr><th>HTTP / 错误</th><th>说明</th></tr></thead><tbody>' + errs + '</tbody></table></div>';
  }

  // 可复制代码块：<code> 承载文本，按钮读取最近的 code 文本
  function Ad_code(text) {
    return '<div class="ad-req"><code>' + escHtml(text) + '</code>' + Ad_copyBtn() + '</div>';
  }
  function Ad_copyBtn() {
    return '<button class="ad-copy" onclick="adCopyFrom(this)">复制</button>';
  }

  // ----------------------------- 复制逻辑 -----------------------------
  function Ad_copy(text, btn) {
    var reset = function () { var t = btn.textContent; btn.textContent = '已复制 ✓'; setTimeout(function () { btn.textContent = t; }, 1200); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(reset).catch(function () { Ad_fallback(text); reset(); });
    } else {
      Ad_fallback(text); reset();
    }
  }
  function Ad_fallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
  }

  // ----------------------------- 样式注入 -----------------------------
  function Ad_ensureCss() {
    var existing = document.getElementById('ad-css');
    if (existing) existing.remove();
    var css = [
      '.apidocs{display:flex;gap:18px;align-items:flex-start;margin-top:8px}',
      '.ad-side{width:280px;flex:0 0 280px;position:sticky;top:14px;max-height:calc(100vh - 120px);overflow:auto;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:8px 6px}',
      '.ad-side h4{margin:14px 10px 6px;color:var(--mut);font-size:11px;text-transform:uppercase;letter-spacing:.5px}',
      '.ad-item{display:flex;align-items:center;gap:8px;width:100%;text-align:left;background:transparent;border:0;color:var(--txt);padding:8px 10px;border-radius:8px;cursor:pointer;font-size:13px}',
      '.ad-item:hover{background:var(--bg-chip)}',
      '.ad-item.active{background:var(--bg-chip);color:var(--txt)}',
      '.ad-method{font-size:10px;font-weight:700;padding:2px 6px;border-radius:5px;flex:0 0 auto;min-width:44px;text-align:center}',
      '.m-get{background:var(--tag-a-bg);color:var(--acc)} .m-post{background:var(--tag-c-bg);color:var(--ok)} .m-put{background:var(--tag-pending-bg);color:var(--warn)} .m-del{background:var(--tag-err-bg);color:var(--err)}',
      '.ad-main{flex:1;min-width:0;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:20px 24px}',
      '.ad-main h2{font-size:18px}',
      '.ad-path{font-family:monospace;background:var(--bg-deep);border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin:10px 0;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;position:relative}',
      '.ad-path code{color:var(--acc);font-size:13px;word-break:break-all}',
      '.ad-sec{margin:18px 0}',
      '.ad-sec h3{font-size:13px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px;border-bottom:1px solid var(--line);padding-bottom:6px}',
      '.ad-tbl{width:100%;border-collapse:collapse;font-size:13px}',
      '.ad-tbl th,.ad-tbl td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line)}',
      '.ad-tbl th{color:var(--mut);font-weight:600;background:var(--bg-subtle);font-size:12px}',
      '.ad-tbl code{font-size:12px;color:var(--acc)}',
      '.ad-req{position:relative;background:var(--bg-deep);border:1px solid var(--line);border-radius:10px;padding:14px;overflow:auto;font-size:12px;font-family:monospace;white-space:pre;line-height:1.5}',
      '.ad-req code{font-family:inherit;color:var(--acc);white-space:pre}',
      '.ad-copy{position:absolute;top:8px;right:8px;background:var(--bg-chip);color:var(--txt);border:1px solid var(--line);border-radius:6px;padding:4px 10px;cursor:pointer;font-size:11px}',
      '.ad-copy:hover{background:var(--bg-hover)}',
      '.ad-note{color:var(--mut);line-height:1.6}',
      '@media(max-width:860px){.apidocs{flex-direction:column}.ad-side{width:100%;flex:none;position:static;max-height:none}.ad-main{padding:16px}}'
    ].join('\n');
    var st = document.createElement('style');
    st.id = 'ad-css';
    st.textContent = css;
    document.head.appendChild(st);
  }

  // ----------------------------- 入口（供 index.php SPA 调用） -----------------------------
  if (typeof window !== 'undefined') {
    window.viewApiDocs = function () {
      var view = document.getElementById('view');
      if (!view) return;
      Ad_ensureCss();
      Ad_current = Ad_GROUPS[0].apis[0].id;
      var api = Ad_find(Ad_current);
      view.innerHTML =
        '<h2>应用开放 API（v1）</h2>' +
        '<p class="ad-note" style="margin-top:2px">使用「应用 API Key」调用，作用域限定到该 Key 所属应用。所有请求需在头部携带 ' +
        '<code>Authorization: Bearer &lt;API_KEY&gt;</code>（或 URL 参数 <code>?api_key=&lt;API_KEY&gt;</code>）。' +
        'API Key 在后台「应用 → API Key」中创建，<b>明文仅显示一次</b>，请妥善保存。</p>' +
        '<div class="apidocs"><div class="ad-side" id="adSide">' + Ad_side() + '</div>' +
        '<div class="ad-main" id="adMain">' + Ad_detail(api) + '</div></div>';
    };
    window.adSelect = function (id) {
      Ad_current = id;
      var api = Ad_find(id);
      var side = document.getElementById('adSide');
      var main = document.getElementById('adMain');
      if (side) side.innerHTML = Ad_side();
      if (main) main.innerHTML = Ad_detail(api);
      var sc = document.getElementById('view');
      if (sc) sc.scrollTop = 0;
    };
    // 从最近的 code 元素复制
    window.adCopyFrom = function (btn) {
      var box = btn.closest('.ad-req, .ad-path');
      var code = box ? box.querySelector('code') : null;
      if (code) Ad_copy(code.textContent, btn);
    };
  }

  // 仅在 node 环境下做自检（浏览器有 window，不执行）
  if (typeof window === 'undefined') {
    var total = Ad_GROUPS.reduce(function (n, g) { return n + g.apis.length; }, 0);
    console.log('[apidocs self-test] groups =', Ad_GROUPS.length, ', apis =', total);
  }
})();
