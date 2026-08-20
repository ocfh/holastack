function filterAndSortRows(cfg){
  const filterList = cfg.filterStatusList
    ? cfg.filterStatusList
    : (cfg.filterStatus ? [cfg.filterStatus] : []);
  let rows = (cfg.rows || []).slice();
  for (const f of filterList) {
    if (!f || !f.col || !f.value) continue;
    const fcol = cfg.cols.find(c => c.key === f.col);
    if (fcol && fcol.opts && fcol.opts.getValue) {
      const getV = fcol.opts.getValue;
      const opt = fcol.opts.values.find(o => o.value === f.value);
      const matchFn = opt && opt.match ? opt.match : (v => String(v) === f.value);
      rows = rows.filter(r => matchFn(getV(r)));
    }
  }
  const sort = cfg.state[cfg.stateKey] || cfg.defaultSort;
  if (sort && sort.col) {
    const c = cfg.cols.find(x => x.key === sort.col);
    if (c) {
      const dir = sort.dir === 'asc' ? 1 : -1;
      rows = rows.sort((a,b) => {
        let va, vb;
        if (c.type === 'time' || c.type === 'num') { va = +cfg.cellValue(a, c.key) || 0; vb = +cfg.cellValue(b, c.key) || 0; }
        else if (c.type === 'status') {
          const vals = c.opts && c.opts.values ? c.opts.values : [];
          const getV = c.opts && c.opts.getValue ? c.opts.getValue : (r => r[c.key]);
          const vaRaw = getV(a), vbRaw = getV(b);
          const ia = vals.findIndex(o => String(o.value) === String(vaRaw));
          const ib = vals.findIndex(o => String(o.value) === String(vbRaw));
          const idxA = ia >= 0 ? ia : Number.MAX_SAFE_INTEGER;
          const idxB = ib >= 0 ? ib : Number.MAX_SAFE_INTEGER;
          if (idxA === idxB) return String(vaRaw ?? '').localeCompare(String(vbRaw ?? '')) * dir;
          va = idxA; vb = idxB;
        }
        else { va = String(cfg.cellValue(a, c.key) ?? ''); vb = String(cfg.cellValue(b, c.key) ?? ''); }
        if (va < vb) return -1 * dir;
        if (va > vb) return 1 * dir;
        return 0;
      });
    }
  }
  return [rows, rows.length];
}

function paginateRows(rows, state, keys){
  const page = Math.max(1, +(state[keys.pageKey] || 1));
  const limit = Math.max(1, Math.min(500, +(state[keys.limitKey] || 50)));
  const total = rows.length;
  const offset = Math.max(0, (page - 1) * limit);
  state[keys.offsetKey] = offset;
  return [rows.slice(offset, offset + limit), total, page, limit, offset];
}
function buildSortableTable(cfg){
  
  if (cfg.refresh) {
    window.__tableCfg = window.__tableCfg || {};
    window.__tableCfg[cfg.refresh] = cfg;
  }
  const sort = cfg.state[cfg.stateKey] || cfg.defaultSort;
  
  
  if (cfg.presorted) {
    return renderTable(cfg, sort);
  }
  
  
  const filterList = cfg.filterStatusList
    ? cfg.filterStatusList
    : (cfg.filterStatus ? [cfg.filterStatus] : []);
  const fk = cfg.filterStatus ? cfg.filterStatus.col : null;
  const fv = cfg.filterStatus ? cfg.filterStatus.value : '';
  
  let rows = cfg.rows || [];
  for (const f of filterList) {
    if (!f || !f.col || !f.value) continue;
    const fcol = cfg.cols.find(c => c.key === f.col);
    if (fcol && fcol.opts && fcol.opts.getValue) {
      const getV = fcol.opts.getValue;
      
      const opt = fcol.opts.values.find(o => o.value === f.value);
      const matchFn = opt && opt.match ? opt.match : (v => String(v) === f.value);
      rows = rows.filter(r => matchFn(getV(r)));
    }
  }
  
  if (sort && sort.col) {
    const c = cfg.cols.find(x => x.key === sort.col);
    if (c) {
      const dir = sort.dir === 'asc' ? 1 : -1;
      rows = rows.slice().sort((a,b) => {
        let va, vb;
        if (c.type === 'time') { va = +cfg.cellValue(a, c.key) || 0; vb = +cfg.cellValue(b, c.key) || 0; }
        else if (c.type === 'num') { va = +cfg.cellValue(a, c.key) || 0; vb = +cfg.cellValue(b, c.key) || 0; }
        else if (c.type === 'status') {
          
          
          
          
          const vals = c.opts && c.opts.values ? c.opts.values : [];
          const getV = c.opts && c.opts.getValue ? c.opts.getValue : (r => r[c.key]);
          const vaRaw = getV(a), vbRaw = getV(b);
          const ia = vals.findIndex(o => String(o.value) === String(vaRaw));
          const ib = vals.findIndex(o => String(o.value) === String(vbRaw));
          const idxA = ia >= 0 ? ia : Number.MAX_SAFE_INTEGER;
          const idxB = ib >= 0 ? ib : Number.MAX_SAFE_INTEGER;
          
          if (idxA === idxB) return String(vaRaw ?? '').localeCompare(String(vbRaw ?? '')) * dir;
          va = idxA; vb = idxB;
        }
        else { va = String(cfg.cellValue(a, c.key) ?? ''); vb = String(cfg.cellValue(b, c.key) ?? ''); }
        if (va < vb) return -1 * dir;
        if (va > vb) return 1 * dir;
        return 0;
      });
    }
  }
  
  
  cfg.rows = rows;
  return renderTable(cfg, sort);
}



function renderTable(cfg, sort){
  const rows = cfg.rows || [];
  const filterList = cfg.filterStatusList
    ? cfg.filterStatusList
    : (cfg.filterStatus ? [cfg.filterStatus] : []);
  
  const arrow = (k) => {
    if (!sort || sort.col !== k) return '<span class="sort-arrow" style="opacity:.3;margin-left:4px">↕</span>';
    return sort.dir === 'asc'
      ? '<span class="sort-arrow" style="opacity:1;margin-left:4px;color:var(--acc)">↑</span>'
      : '<span class="sort-arrow" style="opacity:1;margin-left:4px;color:var(--acc)">↓</span>';
  };
  const header = cfg.cols.map(c => {
    
    
    const sortable = c.sortable !== false && c.type !== 'raw';
    const cursor = sortable ? 'cursor:pointer' : '';
    const title = sortable ? `点表头排序（${c.label}）` : '';
    const onclick = sortable
      ? ` onclick="window['${cfg.stateKey}_sort']('${c.key}')"`
      : '';
    if (c.type === 'status') {
      
      
      
      const vals = c.opts.values || [];
      const allOpt = (vals[0] && vals[0].value !== '') ? [{value:'',label:'全部'}, ...vals] : vals;
      
      const curF = filterList.find(f => f.col === c.key);
      const curVal = curF ? curF.value : '';
      const sel = `<select style="font-weight:600;background:transparent;border:0;color:var(--txt);${sortable?'cursor:pointer':'cursor:default'}" onchange="event.stopPropagation();window['${cfg.stateKey}_fstatus']('${c.key}', this.value)">` +
        allOpt.map(o => `<option value="${esc(o.value)}" ${o.value===curVal?'selected':''}>${esc(o.label)}</option>`).join('') +
        `</select>`;
      return `<th style="${cursor}" ${title?`title="${title}"`:''} ${onclick}>${sel}${sortable?arrow(c.key):''}</th>`;
    }
    return `<th style="${cursor}" ${title?`title="${title}"`:''} ${onclick}>${esc(c.label)}${sortable?arrow(c.key):''}</th>`;
  }).join('');
  
  const bodyHtml = rows.length
    ? rows.map(r => cfg.rowHtml(r)).join('')
    : `<tr><td colspan="${cfg.cols.length}" class="muted">${esc(cfg.emptyText||'暂无数据')}</td></tr>`;
  return `<div class="tbl-wrap"><table class="sortable"><thead><tr>${header}</tr></thead><tbody>${bodyHtml}</tbody></table></div>`;
}








function _tableToggleSort(key, re, col){
  const cur = state[key] || {col:null, dir:'desc'};
  if (cur.col !== col) {
    
    const cfg = (window.__tableCfg && window.__tableCfg[re]) || null;
    const c = cfg && cfg.cols ? cfg.cols.find(x => x.key === col) : null;
    const firstDir = (c && c.firstDir) ? c.firstDir : 'desc';
    state[key] = {col, dir:firstDir};
  }
  else {
    
    const cfg = (window.__tableCfg && window.__tableCfg[re]) || null;
    const c = cfg && cfg.cols ? cfg.cols.find(x => x.key === col) : null;
    const firstDir = (c && c.firstDir) ? c.firstDir : 'desc';
    if (cur.dir === firstDir) state[key] = {col, dir: firstDir==='desc' ? 'asc' : 'desc'}; 
    else state[key] = {col:null, dir:'desc'}; 
  }
  window[re]();
}


function _tableSetFStatus(fieldName, re, val){
  state[fieldName] = val;
  window[re]();
}








function buildPager(cfg){
  const total = +cfg.total || 0;
  const limit = +cfg.limit || 50;
  const offset = +cfg.offset || 0;
  const cur = Math.floor(offset / Math.max(1, limit)) + 1;
  const pages = Math.max(1, Math.ceil(total / Math.max(1, limit)));
  const from = total === 0 ? 0 : (offset + 1);
  const to = Math.min(offset + limit, total);
  
  const win = [];
  for (let i = Math.max(1, cur - 2); i <= Math.min(pages, cur + 2); i++) win.push(i);
  const chevL = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>';
  const chevR = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
  const pageBtn = (p, label, extra) => {
    const dis = extra && extra.disabled;
    const cls = extra && extra.cur ? 'btn' : 'ghost';
    return `<button class="${cls}" style="padding:4px 9px;font-size:12px;display:inline-flex;align-items:center;gap:3px" ${dis?'disabled':''} onclick="window['${cfg.refresh}__page'](${p})">${label||p}</button>`;
  };
  return `<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;gap:12px;flex-wrap:wrap">
    <div class="muted" style="font-size:12px">共 <b>${total}</b> 条 · 第 ${from}-${to} 条 · ${pages>0?cur:0} / ${pages} 页</div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <label style="margin:0;font-size:12px;color:var(--mut)">每页</label>
      <select style="width:auto;padding:4px 8px;font-size:12px" onchange="window['${cfg.refresh}__limit'](+this.value)">
        ${[20,50,100,200,500].map(n => `<option value="${n}" ${n===limit?'selected':''}>${n}</option>`).join('')}
      </select>
      ${pageBtn(Math.max(1, cur-1), chevL + '<span>上一页</span>', {disabled: cur<=1})}
      ${win[0] > 1 ? pageBtn(1, '1') + (win[0] > 2 ? '<span class="muted">…</span>' : '') : ''}
      ${win.map(p => pageBtn(p, p, {cur: p===cur})).join('')}
      ${win[win.length-1] < pages ? (win[win.length-1] < pages-1 ? '<span class="muted">…</span>' : '') + pageBtn(pages, pages) : ''}
      ${pageBtn(Math.min(pages, cur+1), '<span>下一页</span>' + chevR, {disabled: cur>=pages})}
      <label style="margin:0;font-size:12px;color:var(--mut)">跳到</label>
      <input type="number" min="1" max="${pages}" value="${cur}" style="width:64px;padding:4px 6px;font-size:12px" onchange="window['${cfg.refresh}__page'](+this.value)">
      <span class="muted" style="font-size:12px">页</span>
    </div>
  </div>`;
}
function _pagerGo(key, re, page){
  const lim = +state[key.limitKey] || 50;
  const total = +state[key.totalKey] || 0;
  const pages = Math.max(1, Math.ceil(total / lim));
  const p = Math.max(1, Math.min(pages, page|0));
  state[key.pageKey] = p;
  state[key.offsetKey] = (p - 1) * lim;
  window[re]();
}
function _pagerSetLimit(key, re, lim){
  state[key.limitKey] = +lim;
  state[key.pageKey] = 1;
  state[key.offsetKey] = 0;
  window[re]();
}


