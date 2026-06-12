/* Signage PWA service worker — deliberately minimal.
   Boards are live server-rendered data, so nothing dynamic is ever cached;
   this exists for installability plus a tiny offline notice. */
const CACHE = 'signage-shell-v1';
const SHELL = ['icon-192.png', 'icon-512.png', 'manifest.webmanifest'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(self.clients.claim());
});
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  e.respondWith(
    fetch(e.request).catch(async () => {
      const hit = await caches.match(e.request);
      if (hit) return hit;
      if (e.request.mode === 'navigate') {
        return new Response(
          '<body style="background:#0c1422;color:#8aa0c0;font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0">' +
          '<div style="text-align:center"><h1 style="color:#ffb347">Signage server unreachable</h1>' +
          '<p>Retrying when the connection returns&hellip;</p>' +
          '<script>setTimeout(()=>location.reload(),10000)<\/script></div>',
          { headers: { 'Content-Type': 'text/html' } });
      }
      return Response.error();
    })
  );
});
