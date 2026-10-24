/*
 * Work Tracker service worker.
 * HTML zamerne NEKESUJEME: stranky obsahuju udaje prihlaseneho pouzivatela a CSRF
 * tokeny. Navigacie idu vzdy do siete, v cache je len staticky obsah z /assets/
 * a samostatna stranka /offline. POST a htmx poziadavky SW neobsluhuje.
 */
const VERSION = '__SW_VERSION__';
const PRECACHE = __PRECACHE__;
const CACHE = 'wt-static-' + VERSION;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((names) => Promise.all(
        names.filter((n) => n !== CACHE).map((n) => caches.delete(n))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') { return; }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) { return; }

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(() => caches.match('/offline')));
    return;
  }

  if (url.pathname.indexOf('/assets/') === 0) {
    event.respondWith(
      caches.match(request).then((hit) => {
        if (hit) { return hit; }
        return fetch(request).then((res) => {
          if (res && res.ok && !res.redirected) {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(request, copy));
          }
          return res;
        });
      })
    );
  }
});
