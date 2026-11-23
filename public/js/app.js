/* Task Manager front-end. jQuery + Bootstrap 4 + SortableJS, no build step.
   - PJAX navigation + AJAX forms: the shell (sidebar, bars, AI panel) never reloads, only #pjax is swapped.
   - notifications (poll + bell), bottom sheets, install prompt, Web Push, service worker. */
(function ($) {
  'use strict';
  var csrf = $('meta[name="csrf-token"]').attr('content');
  $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
  var CFG = window.TMCFG || null;           // only present on authenticated pages (the app shell)
  var $pjax = $('#pjax');
  var setActive = function () {};
  var TM = window.TM = {};

  /* ---------------------------------------------------------------- toasts */
  window.toast = function (msg, type) {
    var $t = $('<div class="toast-msg ' + (type || '') + '" role="status"></div>').text(msg).appendTo('#toasts');
    setTimeout(function () { $t.addClass('out'); setTimeout(function () { $t.remove(); }, 260); }, 3600);
  };
  function errText(xhr) {
    if (xhr.responseJSON && xhr.responseJSON.message) return xhr.responseJSON.message;
    if (xhr.status === 0) return 'Network problem. Check your connection and try again.';
    return 'Something went wrong (HTTP ' + xhr.status + ').';
  }
  window.errText = errText;
  function flashToasts(root) { $(root || document).find('[data-flash]').each(function () { window.toast($(this).data('flash-msg'), $(this).data('flash') === 'error' ? 'err' : 'ok'); $(this).remove(); }); }

  /* ---------------------------------------------------------------- theme */
  function setTheme(t) {
    document.documentElement.setAttribute('data-theme', t); try { localStorage.setItem('tm-theme', t); } catch (e) {}
    $('#themeBtn i').attr('class', t === 'dark' ? 'fas fa-sun' : 'fas fa-moon'); $('#themeColor').attr('content', t === 'dark' ? '#0d1117' : '#ffffff');
    if (window.refreshCharts) window.refreshCharts();
  }
  $(document).on('click', '#themeBtn, [data-theme-toggle]', function () { setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); });
  setTheme(document.documentElement.getAttribute('data-theme') || 'dark');

  /* ---------------------------------------------------------------- progress bar */
  var prog = document.getElementById('progressLine'), progT;
  function progStart() { if (!prog) return; clearTimeout(progT); prog.style.opacity = 1; prog.style.width = '18%'; progT = setTimeout(function () { prog.style.width = '70%'; }, 180); }
  function progDone() { if (!prog) return; prog.style.width = '100%'; progT = setTimeout(function () { prog.style.opacity = 0; prog.style.width = '0'; }, 220); }

  /* ================================================================ PJAX */
  if ($pjax.length) {
    var cache = {}, inflight = {};
    var origin = location.origin;
    var skipRe = /^\/(logout|login|register|reports\/|manifest|offline|sw\.js|ai\/attachments)/;

    function abs(u) { try { return new URL(u, location.href); } catch (e) { return null; } }
    function eligible(a) {
      if (!a || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-no-ajax')) return false;
      var u = abs(a.getAttribute('href')); if (!u || u.origin !== origin) return false;
      if (skipRe.test(u.pathname)) return false;
      if (u.pathname === location.pathname && u.search === location.search && u.hash) return false; // in-page anchor
      return true;
    }
    function fetchHtml(url, opt) {
      opt = opt || {};
      return fetch(url, Object.assign({ credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-PJAX': '1', 'Accept': 'text/html' } }, opt)).then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (r.status === 401 || r.status === 419 || (r.redirected && abs(r.url).pathname === abs(CFG.routes.login).pathname)) { location.href = CFG.routes.login; return new Promise(function () {}); }
        if (ct.indexOf('text/html') === -1) { location.href = url; return new Promise(function () {}); }
        return r.text().then(function (html) { return { url: r.url, html: html, status: r.status }; });
      });
    }
    function parse(html) { return new DOMParser().parseFromString(html, 'text/html'); }

    function runScripts($c) {
      $c.find('script').each(function () { var s = document.createElement('script'); if (this.src) s.src = this.src; else s.text = this.text; if (this.type) s.type = this.type; this.parentNode.replaceChild(s, this); });
    }
    setActive = function () {
      var route = $pjax.data('route'), q = location.search;
      $('.s-link[data-nav-routes], .bn-item[data-nav-routes]').each(function () {
        var $l = $(this), routes = String($l.data('nav-routes')).split(','), on = routes.indexOf(route) !== -1;
        if (on && $l.data('nav-query')) on = q.indexOf($l.data('nav-query')) !== -1;
        if (on && $l.data('nav-not')) on = q.indexOf($l.data('nav-not')) === -1;
        $l.toggleClass('active', on);
      });
      $('#pageTitle').text($pjax.data('heading')); $('#backBtn').toggleClass('d-none', !Number($pjax.data('back')));
      $('body').attr('data-route', route);
    };
    function closeChrome() { closeSheets(); $('#sidebar').removeClass('open'); $('#sidebarBackdrop').removeClass('show'); }

    /** Replace #pjax with the one inside `html`. mode: push | replace | keep (same URL refresh, keep scroll) */
    function swap(res, mode) {
      var doc = parse(res.html), $new = $(doc).find('#pjax').first();
      if (!$new.length) { location.href = res.url; return; }
      var scroll = mode === 'keep' ? window.scrollY : 0, sy = document.scrollingElement;
      $pjax.stop(true, true).attr({ 'data-heading': $new.attr('data-heading'), 'data-route': $new.attr('data-route'), 'data-back': $new.attr('data-back') }).data({ heading: $new.attr('data-heading'), route: $new.attr('data-route'), back: $new.attr('data-back') });
      // a Bootstrap modal that was open (e.g. edit category) leaves its dark backdrop behind when its page is swapped out
      $('.modal-backdrop').remove(); $('body').removeClass('modal-open').css('padding-right', '');
      $pjax.html($new.html()); runScripts($pjax);
      document.title = doc.title || document.title;
      var u = abs(res.url);
      if (mode === 'push') history.pushState({ pjax: 1 }, '', u.pathname + u.search + u.hash); else if (mode === 'replace' || (mode === 'keep' && res.url !== location.href)) history.replaceState({ pjax: 1 }, '', u.pathname + u.search + u.hash);
      var m = doc.querySelector('meta[name="csrf-token"]'); if (m) { csrf = m.content; $('meta[name="csrf-token"]').attr('content', csrf); $.ajaxSetup({ headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }); }
      if (mode !== 'keep') { sy.scrollTop = 0; if (u.hash) { var t = document.getElementById(u.hash.slice(1)); if (t) t.scrollIntoView(); } } else sy.scrollTop = scroll;
      $pjax.removeClass('pjax-in'); void $pjax[0].offsetWidth; $pjax.addClass('pjax-in');
      closeChrome(); setActive(); initPage(); flashToasts($pjax);
      document.dispatchEvent(new Event('tm:navigated'));
    }
    function go(url, mode) {
      var key = abs(url).href; progStart();
      var p = (cache[key] && Date.now() - cache[key].t < 8000) ? Promise.resolve(cache[key].res) : (inflight[key] || (inflight[key] = fetchHtml(key).then(function (r) { delete inflight[key]; return r; })));
      return p.then(function (res) { delete cache[key]; swap(res, mode || 'push'); progDone(); }).catch(function () { progDone(); location.href = key; });
    }
    TM.go = go;
    TM.refresh = function () { return fetchHtml(location.href).then(function (res) { swap(res, 'keep'); }).catch(function () {}); };

    $(document).on('click', 'a[href]', function (e) {
      if (e.which > 1 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.isDefaultPrevented()) return;
      if (!eligible(this)) return;
      var u = abs(this.getAttribute('href')); if (u.hash && u.pathname === location.pathname && u.search === location.search) return;
      e.preventDefault(); go(u.href, 'push');
    });
    window.addEventListener('popstate', function () { go(location.href, 'replace'); });

    // prefetch on hover / touch for instant clicks
    var hoverT;
    function prefetch(a) { if (!eligible(a)) return; var k = abs(a.getAttribute('href')).href; if (k === location.href || (cache[k] && Date.now() - cache[k].t < 8000) || inflight[k]) return; inflight[k] = fetchHtml(k).then(function (r) { delete inflight[k]; cache[k] = { t: Date.now(), res: r }; return r; }).catch(function () { delete inflight[k]; }); }
    $(document).on('mouseover', 'a[href]', function () { var a = this; clearTimeout(hoverT); hoverT = setTimeout(function () { prefetch(a); }, 65); });
    $(document).on('touchstart', 'a[href]', function () { prefetch(this); });

    /* ---------------- AJAX forms: submit, follow the redirect, swap the result ---------------- */
    function formEligible(f) {
      if ($(f).closest('#aiPanel').length || (!f.getAttribute('action') && (f.getAttribute('method') || 'GET').toUpperCase() !== 'GET') || f.hasAttribute('data-no-ajax') || f.hasAttribute('data-live') || $(f).hasClass('toggle-form') || f.target === '_blank') return false;
      var a = abs(f.getAttribute('action') || location.href); return a && a.origin === origin && !skipRe.test(a.pathname);
    }
    $(document).on('submit', 'form', function (e) {
      var f = this; if (e.isDefaultPrevented() || !formEligible(f)) return;
      var method = (f.getAttribute('method') || 'GET').toUpperCase(), url = abs(f.getAttribute('action') || location.href);
      if (f.id === 'deleteForm' && deleteViaJson(f)) { e.preventDefault(); return; }
      e.preventDefault();
      var $btn = $(f).find('button[type=submit],input[type=submit]').filter(':not(:disabled)'), old = $btn.map(function () { return this.innerHTML; }).get();
      $btn.prop('disabled', true).each(function () { if (this.tagName === 'BUTTON') $(this).data('old', this.innerHTML).html('<span class="spinner-border spinner-border-sm"></span> ' + $.trim($(this).text())); });
      progStart(); var done = function () { $btn.prop('disabled', false).each(function (i) { if (this.tagName === 'BUTTON' && $(this).data('old')) this.innerHTML = $(this).data('old'); }); progDone(); };
      var opt;
      if (method === 'GET') { var qs = $(f).serialize(); url.search = qs ? '?' + qs : ''; opt = {}; } else { opt = { method: 'POST', body: new FormData(f), headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-PJAX': '1', 'Accept': 'text/html', 'X-CSRF-TOKEN': csrf } }; }
      fetch(url.href, Object.assign({ credentials: 'same-origin' }, opt)).then(function (r) {
        var ct = r.headers.get('content-type') || '';
        if (r.status === 419) { window.toast('Your session expired. Reloading…', 'err'); setTimeout(function () { location.reload(); }, 800); return; }
        if (ct.indexOf('text/html') === -1) { if (ct.indexOf('json') !== -1) return r.json().then(function (j) { done(); window.toast(j.message || 'Done.', j.ok === false ? 'err' : 'ok'); if (j.ok !== false) TM.refresh(); }); location.href = r.url; return; }
        return r.text().then(function (html) {
          done(); var same = abs(r.url).pathname + abs(r.url).search === location.pathname + location.search;
          $('#deleteModal').modal('hide'); swap({ url: r.url, html: html }, method === 'GET' ? 'push' : (same ? 'keep' : 'push'));
        });
      }).catch(function () { done(); window.toast('Network problem. Check your connection and try again.', 'err'); });
    });

    /* ---------------- live filter/search for the task list ---------------- */
    var liveT, liveReq = 0;
    function live(f) {
      var $f = $(f), target = $f.data('live'), url = abs($f.attr('action')), data = $f.serializeArray().filter(function (x) { return x.value !== ''; });
      url.search = data.length ? '?' + $.param(data) : ''; var my = ++liveReq; $f.addClass('is-loading');
      fetchHtml(url.href).then(function (res) {
        if (my !== liveReq) return; var $n = $(parse(res.html)).find(target).first(); if ($n.length) { $(target).html($n.html()); initPage(); }
        history.replaceState({ pjax: 1 }, '', url.pathname + url.search); $f.removeClass('is-loading');
        var any = data.some(function (x) { return ['sort', 'per_page'].indexOf(x.name) === -1; }); $('#clearFilters').toggleClass('d-none', !any);
      }).catch(function () { $f.removeClass('is-loading'); });
    }
    $(document).on('input', 'form[data-live] input[type=search]', function () { var f = this.form; clearTimeout(liveT); liveT = setTimeout(function () { live(f); }, 250); });
    $(document).on('change', 'form[data-live] select', function () { live(this.form); });
    $(document).on('submit', 'form[data-live]', function (e) { e.preventDefault(); live(this); });
    $(document).on('click', '#clearFilters', function (e) { e.preventDefault(); var f = $('form[data-live]')[0]; $(f).find('input[type=search]').val(''); $(f).find('select').not('[name=sort],[name=per_page]').val(''); live(f); });

    document.addEventListener('tm:data-changed', function () { TM.refresh(); pollNotifs(); });
  }

  /* ---------------------------------------------------------------- per-page behaviours (run on load and after every swap) */
  function initPage() {
    var list = document.getElementById('taskList');
    if (list && window.Sortable && list.dataset.sortable === '1' && !list._sortable) {
      list._sortable = Sortable.create(list, {
        handle: '.drag-handle', animation: 160, ghostClass: 'sortable-ghost', chosenClass: 'sortable-chosen', delay: 120, delayOnTouchOnly: true,
        onEnd: function (ev) { if (ev.oldIndex === ev.newIndex) return; var ids = $(list).children('.task-row').map(function () { return $(this).data('id'); }).get(); $.ajax({ url: list.dataset.reorderUrl, method: 'POST', data: { ids: ids } }).done(function () { window.toast('Order saved.', 'ok'); }).fail(function (x) { window.toast(errText(x), 'err'); }); }
      });
    }
    $('textarea[data-count]').trigger('input'); $('.auto-dismiss').delay(5000).fadeOut(400);
    flashToasts(document);
  }
  $(document).on('input', 'textarea[data-count]', function () { $($(this).data('count')).text(this.value.length); });
  $(document).on('click', '#filterToggle', function () { $('#filters').collapse('toggle'); });
  $(document).on('click', '[data-fill-email]', function () { $('#email').val($(this).data('fill-email')); $('#password').val('password'); });
  $(document).on('click', '[data-toggle-pass]', function () { var i = $($(this).data('toggle-pass')); var show = i.attr('type') === 'password'; i.attr('type', show ? 'text' : 'password'); $(this).find('i').attr('class', show ? 'fas fa-eye-slash' : 'fas fa-eye'); });
  $(document).on('submit', 'form.once', function () { $(this).find('button[type=submit]').prop('disabled', true); });

  /* ---------------------------------------------------------------- task actions */
  $(document).on('submit', 'form.toggle-form', function (e) {
    e.preventDefault();
    var $f = $(this), $row = $f.closest('.task-row'), $btn = $f.find('button').prop('disabled', true);
    $.ajax({ url: $f.attr('action'), method: 'POST', data: $f.serialize() })
      .done(function (r) {
        var done = r.status === 'completed';
        $row.toggleClass('is-completed', done).toggleClass('is-overdue', false);
        $row.find('.status-badge').attr('class', 'badge badge-' + r.badge + ' status-badge').html('<i class="fas ' + r.icon + '"></i> ' + r.label);
        $btn.attr('title', done ? 'Mark as pending' : 'Mark as completed'); window.toast(r.message, 'ok');
        if ($row.data('filter-status')) { $row.addClass('row-out'); setTimeout(function () { $row.remove(); }, 260); }
      })
      .fail(function (xhr) { window.toast(errText(xhr), 'err'); }).always(function () { $btn.prop('disabled', false); });
  });

  // delete modal: one shared modal. Task rows disappear in place; everything else goes through the generic AJAX form path.
  $(document).on('click', '[data-delete-url]', function (e) {
    e.preventDefault(); var $b = $(this);
    $('#deleteForm').attr('action', $b.data('delete-url')); $('#deleteName').text($b.data('delete-name') || 'this item'); $('#deleteNote').text($b.data('delete-note') || 'This can be undone from the trash.');
    $('#deleteModal').data('row', $b.closest('.task-row').attr('id') || '').modal('show');
  });
  $('#deleteModal').on('shown.bs.modal', function () { $('#deleteConfirm').trigger('focus'); });
  function deleteViaJson(f) {
    var rowId = $('#deleteModal').data('row'), $row = rowId ? $('#' + rowId) : $(); if (!$row.length || !/\/tasks\/\d+$/.test(f.getAttribute('action'))) return false;
    var $b = $('#deleteConfirm').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
    $.ajax({ url: f.getAttribute('action'), method: 'POST', data: $(f).serialize() }).done(function (r) { $('#deleteModal').modal('hide'); $row.addClass('row-out'); setTimeout(function () { $row.remove(); }, 260); window.toast(r.message, 'ok'); })
      .fail(function (x) { window.toast(errText(x), 'err'); }).always(function () { $b.prop('disabled', false).html('<i class="fas fa-trash mr-1"></i> Yes, delete'); });

    return true;
  }
  $(document).on('hidden.bs.modal', '#deleteModal', function () { $('#deleteConfirm').prop('disabled', false).html('<i class="fas fa-trash mr-1"></i> Yes, delete'); });

  // quick add: type a title, press Enter, the task appears instantly
  $(document).on('submit', '#quickAdd', function (e) {
    e.preventDefault(); var $f = $(this), $i = $f.find('input[name=title]'), title = $.trim($i.val()); if (title.length < 3) { window.toast('Write at least 3 characters.', 'err'); return; }
    var $b = $f.find('button').prop('disabled', true);
    $.ajax({ url: $f.attr('action'), method: 'POST', data: { title: title, status: 'pending', priority: $f.find('[name=priority]').val() || 'medium' } })
      .done(function (r) {
        $i.val('').trigger('focus'); window.toast(r.message, 'ok'); var $l = $('#taskList');
        if ($l.length) { var $r = $(r.html).addClass('row-in'); $l.prepend($r); } else TM.refresh();
      })
      .fail(function (x) { window.toast(x.status === 422 ? (x.responseJSON.errors.title || ['Invalid'])[0] : errText(x), 'err'); }).always(function () { $b.prop('disabled', false); });
  });

  /* ---------------------------------------------------------------- bottom sheets + notification panel */
  function closeSheets() { var ae = document.activeElement; if (ae && $(ae).closest('#moreSheet, #notifPanel').length) ae.blur(); $('#moreSheet, #notifPanel').removeClass('open').attr('aria-hidden', 'true'); $('#sheetBackdrop').removeClass('show'); $('#bellBtn').attr('aria-expanded', 'false'); }
  function openSheet(sel) { closeSheets(); $(sel).addClass('open').attr('aria-hidden', 'false'); if (sel === '#moreSheet' || window.innerWidth < 992) $('#sheetBackdrop').addClass('show'); }
  $(document).on('click', '#moreBtn', function () { openSheet('#moreSheet'); });
  $(document).on('click', '#sheetBackdrop, [data-sheet-close]', closeSheets);
  $(document).on('click', '[data-notif-open]', function (e) { e.preventDefault(); openSheet('#notifPanel'); pollNotifs(); });
  $(document).on('click', '#bellBtn', function () { var open = $('#notifPanel').hasClass('open'); if (open) closeSheets(); else { openSheet('#notifPanel'); pollNotifs(); $(this).attr('aria-expanded', 'true'); } });
  $(document).on('click', function (e) { if (window.innerWidth >= 992 && $('#notifPanel').hasClass('open') && !$(e.target).closest('#notifPanel, #bellBtn, [data-notif-open]').length) closeSheets(); });
  $(document).on('click', '#backBtn', function () { history.length > 1 ? history.back() : TM.go && TM.go('/tasks'); });
  (function swipe() { var y0 = null, $s; $(document).on('touchstart', '.sheet-grip', function (e) { y0 = e.originalEvent.touches[0].clientY; $s = $(this).closest('.sheet, .notif-panel'); }).on('touchmove', '.sheet-grip', function (e) { if (y0 === null) return; var d = e.originalEvent.touches[0].clientY - y0; if (d > 0) $s.css({ transform: 'translateY(' + d + 'px)', transition: 'none' }); }).on('touchend', '.sheet-grip', function (e) { if (y0 === null) return; var d = e.originalEvent.changedTouches[0].clientY - y0; $s.css({ transform: '', transition: '' }); if (d > 80) closeSheets(); y0 = null; }); })();
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheets(); });

  /* ---------------------------------------------------------------- notifications */
  var lastTop = 0, unread = 0, baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
  function setUnread(n) {
    unread = n; $('[data-unread-badge]').each(function () { $(this).text(n > 99 ? '99+' : n).toggleClass('d-none', !n); });
    document.title = (n ? '(' + n + ') ' : '') + document.title.replace(/^\(\d+\)\s*/, '');
    if (navigator.setAppBadge) { n ? navigator.setAppBadge(n).catch(function () {}) : navigator.clearAppBadge().catch(function () {}); }
  }
  function renderNotifs(items) {
    var $l = $('#notifList').empty(); if (!items.length) { $l.html('<div class="notif-empty"><i class="far fa-bell-slash"></i><p>You are all caught up.</p></div>'); return; }
    items.forEach(function (n) {
      var av = n.actor && n.actor.name ? (n.actor.avatar_url ? '<span class="avatar avatar-md" style="background:' + n.actor.avatar_color + '"><img src="' + n.actor.avatar_url + '" alt=""></span>' : '<span class="avatar avatar-md" style="background:' + n.actor.avatar_color + '">' + $('<i>').text(n.actor.initials).html() + '</span>') : '<span class="avatar avatar-md n-ic"><i class="fas ' + n.icon + '"></i></span>';
      var $i = $('<a class="notif-item' + (n.read ? '' : ' unread') + '" href="' + (n.url || '#') + '" data-nid="' + n.id + '"></a>').append(av).append($('<div class="n-t"></div>').append($('<b></b>').text(n.title)).append($('<p></p>').text(n.body || '')).append($('<small></small>').text(n.time)));
      $l.append($i);
    });
  }
  function pollNotifs() {
    if (!CFG || document.hidden) return $.Deferred().resolve().promise();
    return $.ajax({ url: CFG.routes.poll, method: 'GET', cache: false }).done(function (r) {
      var newest = r.items.length ? r.items[0].id : 0;
      if (lastTop && newest > lastTop && r.unread > unread) { var n = r.items[0]; window.toast(n.title, 'ok'); $('#bellBtn').addClass('ring'); setTimeout(function () { $('#bellBtn').removeClass('ring'); }, 1200); }
      lastTop = Math.max(lastTop, newest); setUnread(r.unread); renderNotifs(r.items);
    });
  }
  TM.poll = pollNotifs;
  if (CFG) {
    pollNotifs(); setInterval(pollNotifs, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) pollNotifs(); });
    $(document).on('click', '.notif-item', function () { var $i = $(this), id = $i.data('nid'); if ($i.hasClass('unread')) { $i.removeClass('unread'); setUnread(Math.max(0, unread - 1)); $.post(CFG.routes.read.replace('__ID__', id)); } closeSheets(); });
    $(document).on('click', '#notifReadAll', function () { $.post(CFG.routes.readAll).done(function () { $('.notif-item').removeClass('unread'); setUnread(0); }); });
    if (navigator.serviceWorker) navigator.serviceWorker.addEventListener('message', function (e) { if (e.data && e.data.type === 'poll') pollNotifs(); });
  }

  /* ---------------------------------------------------------------- avatar helper (used by the profile page) */
  TM.setAvatar = function (url) {
    $('.avatar[data-self]').each(function () { var $a = $(this); $a.find('img').remove(); if (url) { $a.contents().filter(function () { return this.nodeType === 3; }).remove(); $('<img alt="">').attr('src', url).appendTo($a); } else { $a.text($a.data('initials')); } });
  };

  /* ---------------------------------------------------------------- PWA: service worker, install, push */
  var deferred = null, standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  function showInstall() { if (!standalone) $('[data-install]').removeClass('d-none'); }
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; showInstall(); });
  window.addEventListener('appinstalled', function () { deferred = null; $('[data-install]').addClass('d-none'); window.toast('App installed.', 'ok'); });
  if (ios && !standalone) showInstall();
  $(document).on('click', '[data-install]', function (e) {
    e.preventDefault();
    if (deferred) { deferred.prompt(); deferred.userChoice.then(function () { deferred = null; }); }
    else if (ios) window.toast('Tap the Share button, then “Add to Home Screen”.', 'ok');
  });
  if ('serviceWorker' in navigator && CFG) window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });

  function b64(s) { var p = '='.repeat((4 - s.length % 4) % 4), r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')), o = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) o[i] = r.charCodeAt(i); return o; }
  window.TMPush = {
    supported: !!(CFG && CFG.vapid && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window),
    enabled: function () { if (!this.supported) return Promise.resolve(false); return navigator.serviceWorker.ready.then(function (r) { return r.pushManager.getSubscription(); }).then(function (s) { return !!s && Notification.permission === 'granted'; }); },
    enable: function () {
      return Notification.requestPermission().then(function (p) { if (p !== 'granted') throw new Error('Notifications are blocked in this browser. Allow them in the site settings.'); return navigator.serviceWorker.ready; })
        .then(function (reg) { return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(CFG.vapid) }); })
        .then(function (sub) { return $.ajax({ url: CFG.routes.subscribe, method: 'POST', contentType: 'application/json', data: JSON.stringify(sub.toJSON()) }); });
    },
    disable: function () { return navigator.serviceWorker.ready.then(function (r) { return r.pushManager.getSubscription(); }).then(function (s) { if (!s) return; var ep = s.endpoint; return s.unsubscribe().then(function () { return $.ajax({ url: CFG.routes.unsubscribe, method: 'POST', data: { endpoint: ep } }); }); }); }
  };

  /* ---------------------------------------------------------------- boot */
  if ($pjax.length) { setActive(); history.replaceState({ pjax: 1 }, '', location.href); }
  initPage();
})(jQuery);

/* swipe a phone bottom-sheet modal down (from its header) to close it */
(function () {
  var y0 = null, dy = 0, $c = null;
  $(document).on('touchstart', '.modal-header', function (e) {
    if (window.innerWidth > 767) return;
    $c = $(this).closest('.modal-content'); y0 = e.originalEvent.touches[0].clientY; dy = 0; $c.css('transition', 'none');
  });
  $(document).on('touchmove', '.modal-header', function (e) {
    if (y0 === null) return; dy = Math.max(0, e.originalEvent.touches[0].clientY - y0); $c.css('transform', 'translateY(' + dy + 'px)');
  });
  $(document).on('touchend touchcancel', '.modal-header', function () {
    if (y0 === null) return; var close = dy > 90, $m = $c.closest('.modal');
    $c.css({ transition: '', transform: '' }); y0 = null; dy = 0; if (close) $m.modal('hide');
  });
})();
