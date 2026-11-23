/* Task Assistant: streaming chat, steps/thinking trail, blocks, confirm cards, chats, memory, images and voice.
   Exposes window.TMAI = {open, close, toggle, send, newChat}. Survives PJAX navigation (the page never reloads). */
(function () {
  'use strict';
  var P = document.getElementById('aiPanel'); if (!P) return;
  var $ = function (id) { return document.getElementById(id); };
  var csrf = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };
  var log = $('aiLog'), input = $('aiInput'), sendBtn = $('aiSend');
  var S = { loaded: false, conv: null, busy: false, ctrl: null, files: [], name: 'there', starters: [], ai: false, stick: true, rec: null, convs: [] };

  /* ---------------------------------------------------------------- helpers */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function jfetch(url, opt) {
    opt = opt || {}; opt.headers = Object.assign({ 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, opt.headers || {}); opt.credentials = 'same-origin';
    return fetch(url, opt).then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) { var e = new Error(j.message || (j.errors && Object.values(j.errors)[0][0]) || ('HTTP ' + r.status)); e.data = j; e.status = r.status; throw e; } return j; }); });
  }
  function toast(m, t) { if (window.toast) window.toast(m, t); }
  function tick(ms) { return ms < 950 ? '<1s' : Math.round(ms / 1000) + 's'; }

  /* tiny safe markdown: **bold** *italic* `code` [link](/path) lists, paragraphs */
  function md(src) {
    var t = esc(src).replace(/`([^`\n]+)`/g, '<code>$1</code>').replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>').replace(/(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>')
      .replace(/\[([^\]\n]+)\]\(((?:\/|https?:\/\/)[^)\s]*)\)/g, function (m, a, u) { return '<a href="' + u + '"' + (u.charAt(0) === '/' ? '' : ' target="_blank" rel="noopener"') + '>' + a + '</a>'; });
    var out = '', list = null;
    t.split('\n').forEach(function (line) {
      var b = line.match(/^\s*[-•*]\s+(.*)$/), n = line.match(/^\s*\d+[.)]\s+(.*)$/);
      if (b || n) { var kind = b ? 'ul' : 'ol'; if (list !== kind) { out += (list ? '</' + list + '>' : '') + '<' + kind + '>'; list = kind; } out += '<li>' + (b || n)[1] + '</li>'; }
      else { if (list) { out += '</' + list + '>'; list = null; } if (line.trim()) out += '<p>' + line + '</p>'; }
    });
    return out + (list ? '</' + list + '>' : '');
  }
  function atBottom() { return log.scrollHeight - log.scrollTop - log.clientHeight < 90; }
  function toBottom(force) { if (force || S.stick) { log.scrollTop = log.scrollHeight; } }
  log.addEventListener('scroll', function () { S.stick = atBottom(); $('aiDown').classList.toggle('show', !S.stick); });
  $('aiDown').addEventListener('click', function () { S.stick = true; log.scrollTo({ top: log.scrollHeight, behavior: 'smooth' }); });

  /* ---------------------------------------------------------------- open / close */
  function open() {
    P.classList.add('open'); P.setAttribute('aria-hidden', 'false'); document.body.classList.add('ai-open');
    if (!S.loaded) boot(); setTimeout(function () { input.focus({ preventScroll: true }); }, 280);
  }
  function close() { if (P.contains(document.activeElement)) document.activeElement.blur(); P.classList.remove('open', 'drawer'); P.setAttribute('aria-hidden', 'true'); document.body.classList.remove('ai-open'); }
  function toggle() { P.classList.contains('open') ? close() : open(); }
  document.addEventListener('click', function (e) { var t = e.target.closest('[data-ai-open]'); if (t) { e.preventDefault(); var sb = document.getElementById('sidebar'); if (sb) sb.classList.remove('open'); open(); } });
  $('aiClose').addEventListener('click', close);
  $('aiWide').addEventListener('click', function () { P.classList.toggle('wide'); this.querySelector('i').className = P.classList.contains('wide') ? 'fas fa-compress' : 'fas fa-expand'; });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && P.classList.contains('open') && !document.querySelector('.modal.show')) { if (!$('aiMem').hidden) $('aiMem').hidden = true; else close(); } });
  $('aiMenu').addEventListener('click', function () { P.classList.toggle('drawer'); }); $('aiScrim').addEventListener('click', function () { P.classList.remove('drawer'); });
  $('aiMoreBtn').addEventListener('click', function (e) { e.stopPropagation(); $('aiMorePop').classList.toggle('show'); });
  document.addEventListener('click', function () { $('aiMorePop').classList.remove('show'); });

  /* ---------------------------------------------------------------- bootstrap + chats */
  function boot() {
    S.loaded = true;
    jfetch(P.dataset.history).then(function (r) {
      S.name = r.name; S.starters = r.starters || []; S.ai = r.ai; S.convs = r.conversations || [];
      $('aiMode').textContent = r.ai ? 'Ready' : 'Offline mode (no API key)'; $('aiDot').classList.toggle('off', !r.ai);
      renderConvs();
      if (r.active && r.active.messages.length) { S.conv = r.active.conversation.id; showConv(r.active); markActive(); } else hero();
    }).catch(function (e) { S.loaded = false; log.innerHTML = ''; log.appendChild(el('div', 'ai-text ai-err', 'I could not load the assistant: ' + esc(e.message))); });
  }
  function renderConvs() {
    var box = $('aiConvs'); box.innerHTML = '';
    if (!S.convs.length) { box.appendChild(el('div', 'ai-side-label', 'No chats yet')); return; }
    S.convs.forEach(function (c) {
      var row = el('div', 'ai-conv' + (c.id === S.conv ? ' active' : ''), '<div class="t"><b>' + esc(c.title) + '</b><small>' + esc(c.ago) + ' ago</small></div><button class="del" type="button" aria-label="Delete chat"><i class="fas fa-trash"></i></button>');
      row.dataset.id = c.id;
      row.addEventListener('click', function (e) { if (e.target.closest('.del')) return; openConv(c.id); P.classList.remove('drawer'); });
      row.querySelector('.del').addEventListener('click', function () { if (!confirm('Delete this chat?')) return; jfetch(P.dataset.convBase + '/' + c.id, { method: 'DELETE' }).then(function () { S.convs = S.convs.filter(function (x) { return x.id !== c.id; }); if (S.conv === c.id) newChat(); else renderConvs(); }); });
      box.appendChild(row);
    });
  }
  function markActive() { [].forEach.call($('aiConvs').children, function (r) { r.classList.toggle('active', +r.dataset.id === S.conv); }); }
  function refreshConvs() { return jfetch(P.dataset.conversations + '?q=' + encodeURIComponent($('aiSearch').value || '')).then(function (r) { S.convs = r.conversations; renderConvs(); }); }
  var st; $('aiSearch').addEventListener('input', function () { clearTimeout(st); st = setTimeout(refreshConvs, 220); });
  function openConv(id) { jfetch(P.dataset.convBase + '/' + id).then(function (r) { S.conv = r.conversation.id; showConv(r); markActive(); }).catch(function (e) { toast(e.message, 'err'); }); }
  function showConv(r) { log.innerHTML = ''; S.stick = true; r.messages.forEach(function (m, i) { renderStored(m, i === r.messages.length - 1); }); toBottom(true); chips(r.messages.length ? lastFollowups(r.messages) : []); }
  function lastFollowups(msgs) { var m = msgs[msgs.length - 1]; return m && m.role === 'assistant' ? (m.followups || []) : []; }
  function newChat() { if (S.busy && S.ctrl) S.ctrl.abort(); S.conv = null; S.files = []; renderFiles(); markActive(); hero(); $('aiMem').hidden = true; P.classList.remove('drawer'); input.focus(); }
  $('aiNew').addEventListener('click', newChat); $('aiNewSide').addEventListener('click', newChat);

  function hero() {
    log.innerHTML = ''; var h = el('div', 'ai-hero', '<div class="gem"><svg class="tm-ai-logo" viewBox="0 0 32 32" width="1em" height="1em" aria-hidden="true"><use href="#tm-logo"/></svg></div><h2>Hi ' + esc(S.name) + ', <span>how can I help?</span></h2><p>I can look things up, draw tables, make reports, and do things for you. Anything that changes data asks you to confirm first. Send me a photo or a voice note too.</p>');
    var grid = el('div', 'ai-starters');
    S.starters.forEach(function (s) { var b = el('button', 'ai-starter', '<span class="ic"><i class="fas ' + esc(s.icon) + '"></i></span><span><b>' + esc(s.title) + '</b><small>' + esc(s.sub) + '</small></span>'); b.type = 'button'; b.addEventListener('click', function () { send(s.text); }); grid.appendChild(b); });
    h.appendChild(grid); log.appendChild(h);
    chips(S.starters.slice(0, 0));
  }
  function chips(items) {
    var box = $('aiChips'); box.innerHTML = '';
    (items || []).forEach(function (t) { var b = el('button', '', '<i class="fas fa-bolt"></i>' + esc(t)); b.type = 'button'; b.addEventListener('click', function () { send(t); }); box.appendChild(b); });
  }

  /* ---------------------------------------------------------------- rendering */
  function userRow(text, files, time, id) {
    var r = el('div', 'ai-row user');
    (files || []).forEach(function (f) { if (f.kind === 'image') { var im = el('img'); im.src = f.url; im.style.cssText = 'max-width:200px;max-height:160px;border-radius:14px'; r.appendChild(im); } else r.appendChild(el('span', 'ai-note', '<i class="fas fa-microphone"></i> voice message')); });
    if (text && text !== '(attachment)') r.appendChild(el('div', 'ai-bubble', esc(text)));
    var meta = el('div', 'ai-meta', '<span>' + esc(time || new Date().toTimeString().slice(0, 5)) + '</span><button type="button" title="Copy"><i class="far fa-copy"></i></button>');
    meta.querySelector('button').addEventListener('click', function () { navigator.clipboard && navigator.clipboard.writeText(text); toast('Copied', 'ok'); }); r.appendChild(meta);
    log.appendChild(r); return r;
  }
  function botRow() {
    var r = el('div', 'ai-row bot'), av = el('span', 'ai-av', '<svg class="tm-ai-logo" viewBox="0 0 32 32" width="1em" height="1em" aria-hidden="true"><use href="#tm-logo"/></svg>'), body = el('div', 'ai-body'); r.appendChild(av); r.appendChild(body); log.appendChild(r);
    return { row: r, body: body, think: null, text: null, blocks: el('div'), cards: el('div'), steps: [], raw: '', t0: Date.now(), msgId: null };
  }
  function thinkBox(b) {
    if (b.think) return b.think;
    var t = el('div', 'ai-think open', '<div class="ai-think-h"><span class="sp"></span><i class="fas fa-circle-check ok"></i><span class="lbl">Thinking… 0s</span><i class="fas fa-chevron-down chev"></i></div><div class="ai-think-b"></div>');
    t.querySelector('.ai-think-h').addEventListener('click', function () { t.classList.toggle('open'); });
    b.body.insertBefore(t, b.body.firstChild); b.think = t;
    b.timer = setInterval(function () { var l = t.querySelector('.lbl'); if (l && !t.classList.contains('done')) l.textContent = 'Thinking… ' + Math.round((Date.now() - b.t0) / 1000) + 's'; }, 500);
    return t;
  }
  function addStep(b, txt) { var t = thinkBox(b); b.steps.push(txt); var d = el('div', '', '<i class="fas fa-check" style="color:var(--teal-2);margin-right:.4rem;font-size:.7rem"></i>' + esc(txt)); t.querySelector('.ai-think-b').appendChild(d); toBottom(); }
  function addThought(b, txt) { var t = thinkBox(b), box = t.querySelector('.ai-think-b'), th = box.querySelector('.th:last-child'); if (!th) { th = el('div', 'th'); box.appendChild(th); } th.textContent += txt; toBottom(); }
  function finishThink(b, steps, ms) {
    if (b.timer) clearInterval(b.timer);
    if (!b.think && (!steps || !steps.length)) return;
    var t = thinkBox(b); if (b.timer) clearInterval(b.timer); t.classList.add('done'); t.classList.remove('open');
    t.querySelector('.lbl').textContent = 'Thought for ' + tick(ms != null ? ms : Date.now() - b.t0) + ' · ' + (steps ? steps.length : b.steps.length) + ' step' + ((steps ? steps.length : b.steps.length) === 1 ? '' : 's');
    if (steps && !t.querySelector('.ai-think-b div')) steps.forEach(function (s) { t.querySelector('.ai-think-b').appendChild(el('div', '', '<i class="fas fa-check" style="color:var(--teal-2);margin-right:.4rem;font-size:.7rem"></i>' + esc(s))); });
  }
  function setText(b, raw, live) {
    b.raw = raw; if (!b.text) { b.text = el('div', 'ai-text'); b.body.insertBefore(b.text, b.blocks.parentNode ? b.blocks : null); if (b.think && b.think.nextSibling !== b.text) b.think.after(b.text); }
    b.text.innerHTML = md(raw); b.text.classList.toggle('streaming', !!live);
  }
  function ensureParts(b) { if (!b.blocks.parentNode) { b.body.appendChild(b.blocks); b.body.appendChild(b.cards); } }

  function block(b, k) {
    ensureParts(b); var w = el('div', 'ai-block'), t = k.type;
    if (t === 'table') {
      var th = k.columns.map(function (c) { return '<th>' + esc(c) + '</th>'; }).join(''), tr = k.rows.map(function (r) { return '<tr>' + r.map(function (c) { return '<td>' + esc(c) + '</td>'; }).join('') + '</tr>'; }).join('');
      w.innerHTML = '<div class="ai-table"><div class="ai-table-top"><span>' + esc(k.title) + ' (' + k.count + ')</span><span class="sp"></span><span class="ai-pill">' + k.shown + ' rows</span><a class="ai-pill csv" data-no-ajax href="' + esc(k.csv_url) + '"><i class="fas fa-download"></i> CSV</a><a class="ai-pill pdf" data-no-ajax target="_blank" href="' + esc(k.pdf_url) + '"><i class="fas fa-file-pdf"></i> PDF</a></div><div class="ai-tscroll"><table><thead><tr>' + th + '</tr></thead><tbody>' + tr + '</tbody></table></div><div class="ai-hint">↔ Swipe sideways to see every column' + (k.count > k.shown ? ' · showing ' + k.shown + ' of ' + k.count : '') + '</div></div>';
    } else if (t === 'stats') {
      w.innerHTML = '<h6>' + esc(k.title) + '</h6><div class="ai-stats">' + k.items.map(function (i) { return '<div class="ai-stat ' + esc(i.tone) + '"><small>' + esc(i.label) + '</small><b>' + esc(i.value) + '</b><span>' + esc(i.hint) + '</span></div>'; }).join('') + '</div>';
    } else if (t === 'report') {
      var L = { csv: 'CSV', xlsx: 'Excel', docx: 'Word', pdf: 'PDF', html: 'HTML', md: 'Markdown', txt: 'Text', json: 'JSON' };
      w.innerHTML = '<div class="ai-report"><div class="h"><span class="ic"><i class="fas fa-file-export"></i></span><div><b>' + esc(k.title) + '</b><small>' + k.count + ' row' + (k.count === 1 ? '' : 's') + ' · ' + esc(k.subtitle || '') + '</small></div></div><div class="fm">' + Object.keys(k.formats).map(function (f) { return '<a class="ai-pill' + (f === k.format ? ' primary' : '') + '" data-no-ajax target="_blank" href="' + esc(k.formats[f]) + '"><i class="fas fa-download"></i> ' + L[f] + '</a>'; }).join('') + '</div></div>';
    } else if (t === 'choices') {
      w.innerHTML = '<div class="ai-choices"><p>' + esc(k.question) + '</p><div class="opts"></div></div>';
      var box = w.querySelector('.opts'), wrap = w.firstChild;
      k.options.forEach(function (o) { var bt = el('button', '', esc(o)); bt.type = 'button'; bt.addEventListener('click', function () { wrap.classList.add('used'); send(o); }); box.appendChild(bt); });
    } else if (t === 'links') {
      w.innerHTML = '<div class="ai-links">' + k.links.map(function (l) { return '<a href="' + esc(l.url) + '"><i class="fas ' + esc(l.icon) + '"></i>' + esc(l.label) + '</a>'; }).join('') + '</div>';
      w.addEventListener('click', function (e) { if (e.target.closest('a') && window.innerWidth < 992) close(); });
    } else if (t === 'remembered') {
      w.innerHTML = '<span class="ai-remembered"><i class="fas ' + (k.forgot ? 'fa-eraser' : 'fa-brain') + '"></i>' + (k.forgot ? '' : 'Remembered: ') + esc(k.text) + '</span>';
    } else return;
    b.blocks.appendChild(w); toBottom();
  }

  function field(f) {
    var v = f.value == null ? '' : f.value, e;
    if (f.type === 'hidden') { e = el('input'); e.type = 'hidden'; }
    else if (f.type === 'select') { e = el('select', 'custom-select custom-select-sm'); (f.options || []).forEach(function (o) { var op = el('option'); op.value = o.value; op.textContent = o.label; if (String(o.value) === String(v)) op.selected = true; e.appendChild(op); }); }
    else if (f.type === 'textarea') { e = el('textarea', 'form-control form-control-sm'); e.rows = 2; e.value = v; }
    else { e = el('input', 'form-control form-control-sm'); e.type = f.type === 'date' ? 'date' : (f.type === 'color' ? 'color' : 'text'); e.value = v; }
    e.name = f.key; e.setAttribute('aria-label', f.label); if (f.type === 'hidden') e.value = v; return e;
  }
  function card(b, p, msgId) {
    ensureParts(b);
    var c = el('div', 'ai-card' + (p.danger ? ' danger' : '') + (p.done ? ' done' : '')); c.dataset.pid = p.id;
    c.appendChild(el('div', 'ph', '<i class="fas ' + esc(p.icon) + '"></i><span>' + esc(p.label + (p.target ? ' — ' + p.target : '')) + '</span>'));
    var st = el('div', 'st');
    if (p.done) { st.className = 'st text-success'; st.innerHTML = '<i class="fas fa-circle-check"></i> ' + esc(p.result || 'Done') + (p.url ? ' <a href="' + esc(p.url) + '">Open</a>' : ''); c.appendChild(st); b.cards.appendChild(c); return c; }
    var dl = el('dl');
    (p.fields || []).forEach(function (f) { var inp = field(f); if (f.type === 'hidden') { c.appendChild(inp); return; } if (p.readonly) return; dl.appendChild(el('dt', '', esc(f.label))); var dd = el('dd'); dd.appendChild(inp); dl.appendChild(dd); });
    if (dl.children.length) c.appendChild(dl);
    var acts = el('div', 'acts'), ok = el('button', 'btn btn-sm ' + (p.danger ? 'btn-danger' : 'btn-primary'), '<i class="fas fa-check mr-1"></i>Confirm'), no = el('button', 'btn btn-sm btn-light', 'Dismiss');
    ok.type = 'button'; no.type = 'button'; acts.appendChild(ok); acts.appendChild(no); c.appendChild(acts); c.appendChild(st);
    c.confirm = function () {
      return new Promise(function (res) {
        var params = {}; [].forEach.call(c.querySelectorAll('[name]'), function (x) { params[x.name] = x.value; });
        ok.disabled = true; ok.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        jfetch(P.dataset.exec, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: p.action, params: params, message_id: msgId || b.msgId || '', proposal_id: p.id }) })
          .then(function (r) { c.classList.add('done'); c.classList.remove('danger'); [].forEach.call(c.querySelectorAll('dl,.acts,input,select,textarea'), function (x) { x.remove(); }); st.className = 'st text-success'; st.innerHTML = '<i class="fas fa-circle-check"></i> ' + esc(r.message) + (r.url ? ' <a href="' + esc(r.url) + '">Open</a>' : ''); toast(r.message, 'ok'); document.dispatchEvent(new CustomEvent('tm:data-changed')); res(true); })
          .catch(function (e) { ok.disabled = false; ok.innerHTML = '<i class="fas fa-check mr-1"></i>Confirm'; st.className = 'st text-danger'; st.textContent = e.message + (e.data && e.data.ref ? ' (ref ' + e.data.ref + ')' : ''); res(false); });
      });
    };
    ok.addEventListener('click', function () { c.confirm(); });
    no.addEventListener('click', function () { c.style.opacity = 0; setTimeout(function () { c.remove(); }, 160); });
    b.cards.appendChild(c); return c;
  }
  function confirmAllBtn(b) {
    var cs = [].filter.call(b.cards.querySelectorAll('.ai-card'), function (x) { return x.confirm; });
    if (cs.length < 2 || b.cards.querySelector('.ai-all')) return;
    var bt = el('button', 'btn btn-primary btn-sm ai-all', '<i class="fas fa-check-double mr-1"></i>Confirm all ' + cs.length); bt.type = 'button';
    bt.addEventListener('click', function () { bt.disabled = true; cs.reduce(function (p, c) { return p.then(function () { return c.confirm ? c.confirm() : 0; }); }, Promise.resolve()).then(function () { bt.remove(); }); });
    b.cards.insertBefore(bt, b.cards.firstChild);
  }

  function renderStored(m, last) {
    if (m.role === 'user') { userRow(m.content, m.attachments, m.time, m.id); return; }
    var b = botRow(); b.msgId = m.id;
    if (m.steps && m.steps.length) finishThink(b, m.steps, m.thinking_ms);
    setText(b, m.content, false);
    (m.blocks || []).forEach(function (k) { block(b, k); }); (m.proposals || []).forEach(function (p) { card(b, p, m.id); }); confirmAllBtn(b);
    b.body.appendChild(el('div', 'ai-meta', '<span>' + esc(m.time || '') + '</span>'));
  }

  /* ---------------------------------------------------------------- sending (SSE stream) */
  function setBusy(on) {
    S.busy = on; sendBtn.classList.toggle('stop', on); sendBtn.innerHTML = on ? '<i class="fas fa-stop"></i>' : '<i class="fas fa-arrow-up"></i>';
    $('aiDot').className = 'ai-dot' + (on ? ' busy' : (S.ai ? '' : ' off')); $('aiMode').textContent = on ? 'Thinking…' : (S.ai ? 'Ready' : 'Offline mode (no API key)');
  }
  function send(text) {
    text = (text == null ? input.value : text).trim();
    if (S.busy) return; if (!text && !S.files.length) return;
    var files = S.files.slice(); S.files = []; renderFiles(); input.value = ''; input.style.height = ''; chips([]);
    var hero0 = log.querySelector('.ai-hero'); if (hero0) hero0.remove();
    S.stick = true;
    userRow(text, files.map(function (f) { return { kind: f.kind, url: f.preview || '' }; }), null);
    var b = botRow(); addStep(b, 'Reading your request…'); toBottom(true);
    var fd = new FormData(); fd.append('message', text); if (S.conv) fd.append('conversation_id', S.conv); files.forEach(function (f) { fd.append('files[]', f.file, f.file.name || 'recording.webm'); });
    S.ctrl = new AbortController(); setBusy(true);
    var finished = false, raw = '', followups = [];

    function onEvent(e) {
      switch (e.t) {
        case 'meta': S.conv = e.conversation_id; if (!S.convs.some(function (c) { return c.id === e.conversation_id; })) { S.convs.unshift({ id: e.conversation_id, title: e.title, ago: 'now' }); renderConvs(); } markActive(); break;
        case 'step': addStep(b, e.text); break;
        case 'think': addThought(b, e.text); break;
        case 'text': raw += e.text; setText(b, raw, true); toBottom(); break;
        case 'block': block(b, e.block); break;
        case 'card': card(b, e.card, null); toBottom(); break;
        case 'followups': followups = e.items || []; break;
        case 'done':
          finished = true; b.msgId = e.message_id; finishThink(b, e.steps, e.thinking_ms); setText(b, e.reply || raw, false);
          [].forEach.call(b.cards.querySelectorAll('.ai-card'), function (c) { /* attach saved message id for execute bookkeeping */ });
          if (e.note) b.body.appendChild(el('div', 'ai-note', esc(e.note)));
          confirmAllBtn(b); chips(followups); b.body.appendChild(el('div', 'ai-meta', '<span>' + new Date().toTimeString().slice(0, 5) + '</span>'));
          refreshConvs(); toBottom(); break;
        case 'error': finished = true; finishThink(b); setText(b, '', false); b.body.appendChild(el('div', 'ai-text ai-err', esc(e.message) + (e.ref ? ' (ref ' + esc(e.ref) + ')' : ''))); retry(b, text); break;
      }
    }
    fetch(P.dataset.stream, { method: 'POST', body: fd, signal: S.ctrl.signal, credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'text/event-stream', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) return r.json().catch(function () { return {}; }).then(function (j) { throw new Error(j.message || (j.errors && Object.values(j.errors)[0][0]) || 'HTTP ' + r.status); });
        var rd = r.body.getReader(), dec = new TextDecoder(), buf = '';
        function pump() {
          return rd.read().then(function (x) {
            if (x.done) return;
            buf += dec.decode(x.value, { stream: true }); var i;
            while ((i = buf.indexOf('\n\n')) !== -1) { var chunk = buf.slice(0, i); buf = buf.slice(i + 2); chunk.split('\n').forEach(function (ln) { if (ln.indexOf('data:') === 0) { try { onEvent(JSON.parse(ln.slice(5))); } catch (err) { /* ignore partial */ } } }); }
            return pump();
          });
        }
        return pump();
      })
      .catch(function (err) { if (err.name === 'AbortError') { finishThink(b); if (!raw) setText(b, '_Stopped._', false); return; } finished = true; finishThink(b); b.body.appendChild(el('div', 'ai-text ai-err', esc(err.message || 'Network problem. Check your connection.'))); retry(b, text); })
      .then(function () { if (!finished && !S.ctrl.signal.aborted) { finishThink(b); } setBusy(false); S.ctrl = null; if (window.innerWidth > 991) input.focus({ preventScroll: true }); });
  }
  function retry(b, text) { var bt = el('button', 'ai-pill', '<i class="fas fa-rotate-right"></i> Try again'); bt.type = 'button'; bt.style.marginTop = '.5rem'; bt.addEventListener('click', function () { b.row.remove(); send(text); }); b.body.appendChild(bt); }

  $('aiForm').addEventListener('submit', function (e) { e.preventDefault(); if (S.busy && S.ctrl) { S.ctrl.abort(); return; } send(); });
  sendBtn.addEventListener('click', function (e) { if (S.busy && S.ctrl) { e.preventDefault(); S.ctrl.abort(); } });
  input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); if (!S.busy) send(); } });
  input.addEventListener('input', function () { this.style.height = 'auto'; this.style.height = Math.min(this.scrollHeight, 130) + 'px'; });

  /* ---------------------------------------------------------------- attachments */
  function addFile(file) {
    if (S.files.length >= 4) { toast('Up to 4 files per message.', 'err'); return; }
    var kind = file.type.indexOf('image/') === 0 ? 'image' : (file.type.indexOf('audio/') === 0 || file.type.indexOf('video/') === 0 ? 'audio' : null); if (!kind) { toast('Only pictures and voice recordings are supported.', 'err'); return; }
    if (file.size > 8 * 1024 * 1024) { toast('That file is over 8 MB.', 'err'); return; }
    S.files.push({ file: file, kind: kind, preview: kind === 'image' ? URL.createObjectURL(file) : '' }); renderFiles();
  }
  function renderFiles() {
    var box = $('aiFiles'); box.innerHTML = '';
    S.files.forEach(function (f, i) { var d = el('div', 'ai-file', f.kind === 'image' ? '<img src="' + f.preview + '" alt="">' : '<i class="fas fa-microphone"></i>'); var x = el('button', '', '✕'); x.type = 'button'; x.addEventListener('click', function () { S.files.splice(i, 1); renderFiles(); }); d.appendChild(x); box.appendChild(d); });
  }
  $('aiAttach').addEventListener('click', function () { $('aiFile').click(); });
  $('aiFile').addEventListener('change', function () { [].forEach.call(this.files, addFile); this.value = ''; });
  input.addEventListener('paste', function (e) { [].forEach.call((e.clipboardData || {}).files || [], function (f) { if (f.type.indexOf('image/') === 0) addFile(f); }); });
  P.addEventListener('dragover', function (e) { e.preventDefault(); }); P.addEventListener('drop', function (e) { e.preventDefault(); [].forEach.call(e.dataTransfer.files || [], addFile); });

  /* ---------------------------------------------------------------- voice: browser dictation first, recorder as fallback */
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition, mic = $('aiMic');
  function micOff() { mic.classList.remove('rec'); mic.innerHTML = '<i class="fas fa-microphone"></i>'; }
  mic.addEventListener('click', function () {
    if (S.rec) { S.rec.stop(); return; }
    if (SR) {
      var r = new SR(); r.lang = document.documentElement.lang || 'en-US'; r.interimResults = true; r.continuous = false; var base = input.value ? input.value + ' ' : '', final = '';
      r.onresult = function (ev) { var t = ''; for (var i = ev.resultIndex; i < ev.results.length; i++) { if (ev.results[i].isFinal) final += ev.results[i][0].transcript; else t += ev.results[i][0].transcript; } input.value = base + final + t; input.dispatchEvent(new Event('input')); };
      r.onerror = function (ev) { if (ev.error === 'not-allowed') toast('Microphone permission was denied.', 'err'); };
      r.onend = function () { S.rec = null; micOff(); if (final.trim() && !S.busy) send(); }; S.rec = r; mic.classList.add('rec'); mic.innerHTML = '<i class="fas fa-stop"></i>'; r.start(); return;
    }
    if (!navigator.mediaDevices || !window.MediaRecorder) { toast('Voice input is not supported in this browser.', 'err'); return; }
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var chunks = [], mr = new MediaRecorder(stream); mr.ondataavailable = function (e) { chunks.push(e.data); };
      mr.onstop = function () { stream.getTracks().forEach(function (t) { t.stop(); }); S.rec = null; micOff(); var f = new File(chunks, 'voice.webm', { type: mr.mimeType || 'audio/webm' }); addFile(f); if (!S.busy) send('Please listen to my voice message and help.'); };
      S.rec = mr; mic.classList.add('rec'); mic.innerHTML = '<i class="fas fa-stop"></i>'; mr.start();
    }).catch(function () { toast('Microphone permission was denied.', 'err'); });
  });

  /* ---------------------------------------------------------------- memory + clear */
  function memOpen() {
    $('aiMorePop').classList.remove('show'); P.classList.remove('drawer'); $('aiMem').hidden = false; var box = $('aiMemList'); box.innerHTML = '<div class="ai-empty">Loading…</div>';
    jfetch(P.dataset.memories).then(function (r) {
      box.innerHTML = ''; if (!r.memories.length) { box.innerHTML = '<div class="ai-empty">Nothing yet. Tell me things like “call me Dan” or “I prefer short answers”, and I will remember them.</div>'; return; }
      r.memories.forEach(function (m) { var row = el('div', 'ai-mem-item', '<span class="tag">' + esc(m.category) + '</span><span class="c">' + esc(m.content) + '</span><button type="button" aria-label="Forget"><i class="fas fa-xmark"></i></button>'); row.querySelector('button').addEventListener('click', function () { jfetch(P.dataset.memoryBase + '/' + m.id, { method: 'DELETE' }).then(function () { row.remove(); }); }); box.appendChild(row); });
    });
  }
  $('aiMemOpen').addEventListener('click', memOpen); $('aiMemOpen2').addEventListener('click', memOpen); $('aiMemBack').addEventListener('click', function () { $('aiMem').hidden = true; });
  $('aiForgetAll').addEventListener('click', function () { if (!confirm('Forget everything I know about you?')) return; jfetch(P.dataset.memoryBase, { method: 'DELETE' }).then(function () { $('aiMemList').innerHTML = '<div class="ai-empty">All forgotten.</div>'; }); });
  $('aiClearAll').addEventListener('click', function () { $('aiMorePop').classList.remove('show'); if (!confirm('Delete ALL your chats with the assistant?')) return; jfetch(P.dataset.clear, { method: 'DELETE' }).then(function () { S.convs = []; renderConvs(); newChat(); }); });

  window.TMAI = { open: open, close: close, toggle: toggle, send: function (t) { open(); setTimeout(function () { send(t); }, 350); }, newChat: newChat };
  document.addEventListener('tm:navigated', function () { if (window.innerWidth < 992) close(); });
})();
