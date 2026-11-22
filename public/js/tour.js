/* Welcome tour — animated spotlight walkthrough for new users. "Not now" snoozes it; finishing or "Don't show again" marks it done. */
(function () {
  var S = null, idx = 0, steps = [], root, ring, card, resizeT;
  var $ = function (s) { return document.querySelector(s); };
  function vis(el) { if (!el) return false; var r = el.getBoundingClientRect(); return r.width > 4 && r.height > 4 && getComputedStyle(el).visibility !== 'hidden'; }
  function pick(list) { for (var i = 0; i < list.length; i++) { var el = $(list[i]); if (vis(el)) return el; } return null; }
  function mobile() { return window.innerWidth < 992; }
  function post(url) { var t = document.querySelector('meta[name="csrf-token"]'); return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': t ? t.content : '', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).catch(function () {}); }

  function build() {
    var C = window.TMCFG || {}, staff = C.role === 'admin' || C.role === 'manager';
    var all = [
      { emoji: '👋', title: 'Welcome, ' + (C.name || 'there') + '!', text: 'Here is a 30-second tour of your new workspace. You can skip it any time and replay it later from your profile.', sel: null },
      { emoji: '📊', title: 'Your day at a glance', text: 'This banner shows what is overdue, what is due today and how much you have finished.', sel: ['.hero'] },
      { emoji: '🧭', title: 'Everything is one tap away', text: mobile() ? 'Use this bar to jump between Home, your tasks, tasks assigned to you and more.' : 'The menu on the left takes you to every page. Pages open instantly — no reloads.', sel: mobile() ? ['#bottomNav'] : ['#sidebar .sidebar-scroll'] },
      { emoji: '⚡', title: 'Add tasks in a second', text: 'Type a title and press Enter. Tick the circle to complete a task, drag the handle to reorder.', sel: ['#quickAdd'] },
      { emoji: '✨', title: 'Your AI assistant', text: 'Ask it anything, or just tell it what to do: add or complete tasks, build a PDF/Word/Excel report, send it a photo or a voice note. It remembers your preferences and always asks before changing anything.', sel: mobile() ? ['.bn-ai'] : ['#openAiSide'] },
      { emoji: '🔔', title: 'Stay in the loop', text: 'Assignments, comments and updates land here. Choose what also reaches your email or phone in your profile.', sel: ['#bellBtn'] },
      { emoji: '🙂', title: 'Make it yours', text: 'Add your photo so teammates recognise you, and set how you want to be notified.', sel: mobile() ? ['#moreBtn'] : ['.brand-me'] }
    ];
    if (staff) all.push({ emoji: '👥', title: 'Team tools', text: C.role === 'admin' ? 'Manage people and roles here. You also get team-wide notifications, reports and the activity log.' : 'You see the whole team’s work here, get team-wide notifications and can export reports.', sel: ['#sidebar a[href$="/users"]', '#sidebar a[href$="/categories"]', '#sidebar a[href$="/activity"]'] });
    all.push({ emoji: '🎉', title: 'You are all set', text: 'Try telling the assistant: “Add a task to review my notes tomorrow”. Enjoy!', sel: null, last: true });
    return all.filter(function (s) { return !s.sel || pick(s.sel); });
  }

  function mount() {
    root = document.createElement('div'); root.className = 'tt-root';
    root.innerHTML = '<div class="tt-shade"></div><div class="tt-ring"></div><div class="tt-card" role="dialog" aria-live="polite"></div>';
    document.body.appendChild(root); ring = root.querySelector('.tt-ring'); card = root.querySelector('.tt-card');
    root.querySelector('.tt-shade').addEventListener('click', function () { });
    document.addEventListener('keydown', onKey); window.addEventListener('resize', onResize);
  }
  function onKey(e) { if (!root) return; if (e.key === 'Escape') snooze(); else if (e.key === 'ArrowRight' || e.key === 'Enter') go(1); else if (e.key === 'ArrowLeft') go(-1); }
  function onResize() { clearTimeout(resizeT); resizeT = setTimeout(function () { if (root) show(idx, true); }, 120); }

  function show(i, quick) {
    idx = i; var s = steps[i], el = s.sel ? pick(s.sel) : null, last = i === steps.length - 1;
    var dots = steps.map(function (_, k) { return '<i class="' + (k === i ? 'on' : k < i ? 'done' : '') + '"></i>'; }).join('');
    card.classList.remove('in'); void card.offsetWidth;
    card.innerHTML = '<div class="tt-emoji">' + s.emoji + '</div><h4>' + s.title + '</h4><p>' + s.text + '</p><div class="tt-dots">' + dots + '</div>' +
      '<div class="tt-actions">' + (i > 0 ? '<button class="tt-btn ghost" data-a="back">Back</button>' : '<button class="tt-btn ghost" data-a="snooze">Not now</button>') +
      '<button class="tt-btn go" data-a="next">' + (last ? 'Finish' : i === 0 ? 'Show me around' : 'Next') + ' <span>→</span></button></div>' +
      '<div class="tt-foot">' + (i > 0 && !last ? '<button data-a="snooze">Not now</button> · ' : '') + '<button data-a="never">Don’t show again</button></div>';
    card.querySelectorAll('[data-a]').forEach(function (b) { b.onclick = function () { var a = b.dataset.a; if (a === 'next') go(1); else if (a === 'back') go(-1); else if (a === 'snooze') snooze(); else done(); }; });
    var place = function () {
      if (el) {
        var r = el.getBoundingClientRect(), pad = 8;
        ring.style.cssText = 'opacity:1;left:' + (r.left - pad) + 'px;top:' + (r.top - pad) + 'px;width:' + (r.width + pad * 2) + 'px;height:' + (r.height + pad * 2) + 'px;border-radius:' + (r.height > 120 ? 18 : 16) + 'px';
        root.classList.remove('center');
        var cw = Math.min(360, window.innerWidth - 24), ch = card.offsetHeight || 230, vw = window.innerWidth, vh = window.innerHeight, x, y;
        var below = vh - r.bottom, above = r.top;
        if (mobile() || cw + r.width > vw - 20 || r.width > vw * .6) { // stack above/below
          y = below > ch + 24 ? r.bottom + pad + 14 : (above > ch + 24 ? r.top - pad - 14 - ch : Math.max(12, vh - ch - 12));
          x = Math.min(Math.max(12, r.left + r.width / 2 - cw / 2), vw - cw - 12);
        } else { // beside (desktop sidebar etc.)
          x = r.right + pad + 16 + cw < vw ? r.right + pad + 16 : Math.max(12, r.left - pad - 16 - cw);
          y = Math.min(Math.max(12, r.top + r.height / 2 - ch / 2), vh - ch - 12);
          if (r.width < cw && below > ch + 24 && above < 60) { x = Math.min(Math.max(12, r.left), vw - cw - 12); y = r.bottom + pad + 14; }
        }
        card.style.cssText = 'left:' + x + 'px;top:' + y + 'px;width:' + cw + 'px';
      } else {
        ring.style.cssText = 'opacity:0;left:50%;top:50%;width:0;height:0';
        root.classList.add('center'); card.style.cssText = '';
      }
      card.classList.add('in');
    };
    if (el) { el.scrollIntoView({ block: 'center', behavior: quick ? 'auto' : 'smooth' }); setTimeout(place, quick ? 0 : 260); } else place();
  }

  function go(d) { var n = idx + d; if (n < 0) return; if (n >= steps.length) return done(); show(n); }
  function close() { if (!root) return; root.classList.add('out'); document.removeEventListener('keydown', onKey); window.removeEventListener('resize', onResize); var r = root; root = null; setTimeout(function () { r.remove(); }, 300); }
  function snooze() { try { localStorage.setItem('tm_tour_snooze', String(Date.now() + 24 * 3600 * 1000)); } catch (e) { } close(); }
  function done() { post((window.TMCFG || {}).tourDone); if (window.TMCFG) TMCFG.tour = false; close(); if (steps[idx] && steps[idx].last && window.TM && window.TM.toast) { } }

  function start(force) {
    if (root || !window.TMCFG) return;
    if (!force) { try { if (Date.now() < +localStorage.getItem('tm_tour_snooze')) return; } catch (e) { } }
    steps = build(); if (steps.length < 2) return;
    mount(); show(0, true);
  }
  window.TMTour = { start: start };
  // "Replay the welcome tour" button (profile page). Delegated so it survives PJAX page swaps.
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('#replayTour'); if (!b) return;
    e.preventDefault();
    if (root) { try { root.remove(); } catch (x) { } root = null; }
    try { localStorage.removeItem('tm_tour_snooze'); } catch (x) { }
    var go2 = function () { if (window.TMCFG) TMCFG.tour = true; start(true); if (!root && window.toast) window.toast('The tour could not start on this screen.', 'err'); };
    var r = post((window.TMCFG || {}).tourReset); (r && r.then ? r.then(go2) : go2());
  });
  function auto() { if (window.TMCFG && TMCFG.tour) setTimeout(function () { start(false); }, 1200); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto); else auto();
})();
