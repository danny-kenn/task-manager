/* Task Manager service worker: fast static assets, offline page, push wake-ups. Never caches pages, AI or notification data. */
const VERSION = 'tm-v2';
const STATIC = ['/offline', '/css/app.css', '/css/ai.css', '/js/app.js', '/js/ai.js', '/icons/icon-192.png', '/icons/icon-512.png',
  '/vendor/bootstrap/bootstrap.min.css', '/vendor/bootstrap/bootstrap.bundle.min.js', '/vendor/jquery/jquery.min.js', '/vendor/sortable/Sortable.min.js', '/vendor/chartjs/chart.umd.js', '/vendor/fontawesome/css/all.min.css'];

self.addEventListener('install', (e) => { e.waitUntil(caches.open(VERSION).then((c) => Promise.allSettled(STATIC.map((u) => c.add(u)))).then(() => self.skipWaiting())); });
self.addEventListener('activate', (e) => { e.waitUntil(caches.keys().then((k) => Promise.all(k.filter((x) => x !== VERSION).map((x) => caches.delete(x)))).then(() => self.clients.claim())); });

self.addEventListener('fetch', (e) => {
  const req = e.request, url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  if (/^\/(ai|notifications|push|reports|profile)\//.test(url.pathname)) return;
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('/offline')));
    return;
  }
  if (/\.(css|js|png|jpg|jpeg|svg|woff2?|ttf|ico)$/.test(url.pathname) || url.pathname.startsWith('/vendor/') || url.pathname.startsWith('/icons/')) {
    e.respondWith(caches.match(req).then((hit) => {
      const net = fetch(req).then((res) => { if (res.ok) { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(req, copy)); } return res; }).catch(() => hit);
      return hit || net; // stale-while-revalidate
    }));
  }
});

/* Pushes arrive EMPTY: wake up, ask the server what is new, show it. */
self.addEventListener('push', (e) => {
  e.waitUntil((async () => {
    let items = [];
    try { const r = await fetch('/notifications/poll', { credentials: 'include', headers: { Accept: 'application/json' } }); if (r.ok) items = (await r.json()).items || []; } catch (_) {}
    const cache = await caches.open(VERSION + '-meta');
    const lastRes = await cache.match('/__last'); const last = lastRes ? parseInt(await lastRes.text(), 10) : 0;
    const fresh = items.filter((n) => !n.read && n.id > last).reverse().slice(-3);
    for (const n of fresh) await self.registration.showNotification(n.title, { body: n.body || '', icon: '/icons/icon-192.png', badge: '/icons/favicon.png', tag: 'n' + n.id, data: { url: n.url || '/' } });
    if (items.length) await cache.put('/__last', new Response(String(Math.max(last, items[0].id))));
    if (!fresh.length && !items.length) await self.registration.showNotification('Task Manager', { body: 'You have something new.', icon: '/icons/icon-192.png', data: { url: '/' } });
    (await self.clients.matchAll({ type: 'window' })).forEach((c) => c.postMessage({ type: 'poll' }));
  })());
});
self.addEventListener('notificationclick', (e) => {
  e.notification.close(); const url = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((cs) => { for (const c of cs) { if ('focus' in c) { c.focus(); c.navigate && c.navigate(url); return; } } return self.clients.openWindow(url); }));
});
