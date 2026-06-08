const CACHE = 'orbithost-v1';

const STATIC = [
  '/',
  '/index.html',
  '/css/style.min.css',
  '/js/main.min.js',
  '/hosting/shared.html',
  '/hosting/vps.html',
  '/hosting/dedicated.html',
  '/hosting/cloud.html',
  '/hosting/wordpress.html',
  '/hosting/reseller.html',
  '/domains.html',
  '/ssl.html',
  '/email-hosting.html',
  '/about.html',
  '/contact.html',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(STATIC)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Cache-first for same-origin static assets; network-only for everything else
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  if (url.origin !== self.location.origin) return;

  e.respondWith(
    caches.match(e.request).then(cached => {
      if (cached) return cached;
      return fetch(e.request).then(res => {
        if (!res || res.status !== 200 || res.type !== 'basic') return res;
        const clone = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, clone));
        return res;
      });
    })
  );
});
