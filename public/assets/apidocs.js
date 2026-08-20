





(function () {
  'use strict';

  
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
      '.ad-main{flex:1;min-width:0;width:100%;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:20px 24px}',
      '.ad-main h2{font-size:18px}',
      '.ad-detail{display:block}',
      '.ad-detail.hidden{display:none}',
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
      '@media(max-width:860px){.apidocs{flex-direction:column}.ad-side{width:100%;flex:none;position:static;max-height:none}.ad-main{padding:16px;overflow-x:auto;-webkit-overflow-scrolling:touch}}'
    ].join('\n');
    var st = document.createElement('style');
    st.id = 'ad-css';
    st.textContent = css;
    document.head.appendChild(st);
  }

  
  
  function Ad_select(id) {
    var items = document.querySelectorAll('.ad-item');
    items.forEach(function (it) { it.classList.toggle('active', it.getAttribute('data-ad') === id); });
    var blocks = document.querySelectorAll('.ad-detail');
    blocks.forEach(function (b) { b.classList.toggle('hidden', b.getAttribute('data-ad') !== id); });
    var sc = document.getElementById('view');
    if (sc) sc.scrollTop = 0;
  }

  
  function Ad_copy(text, btn) {
    var reset = function () { var t0 = btn.textContent; btn.textContent = (typeof t === 'function' ? t('已复制 ✓') : '已复制 ✓'); setTimeout(function () { btn.textContent = t0; }, 1200); };
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
  function Ad_copyFrom(btn) {
    var box = btn.closest('.ad-req, .ad-path');
    var code = box ? box.querySelector('code') : null;
    if (code) Ad_copy(code.textContent, btn);
  }

  
  if (typeof window !== 'undefined') {
    window.viewApiDocs = async function () {
      var view = document.getElementById('view');
      if (!view) return;
      Ad_ensureCss();
      try {
        
        var tok = (window.state && window.state.token) || localStorage.getItem('elw_token') || '';
        var res = await fetch('/api/view/apidocs', tok ? { headers: { 'X-Elw-Token': tok } } : {});
        if (!res.ok) { view.innerHTML = '<p class="muted">' + (typeof t === 'function' ? t('加载失败') : '加载失败') + '</p>'; return; }
        view.innerHTML = await res.text();
      } catch (e) {
        view.innerHTML = '<p class="muted">' + (typeof t === 'function' ? t('加载失败') : '加载失败') + '</p>';
        return;
      }
      
      var first = view.querySelector('.ad-item');
      if (first) Ad_select(first.getAttribute('data-ad'));
    };
    window.adSelect = Ad_select;
    window.adCopyFrom = Ad_copyFrom;
  }
})();