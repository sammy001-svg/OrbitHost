// Bumped from v1 → v2 on purpose: the v1 worker was cache-first for *every*
// same-origin GET, so it also stored admin/portal PHP pages (plan catalogue,
// package lists, client data) and then served them from cache indefinitely —
// which is why saved changes only showed up after a manual refresh. The
// activate handler below deletes every cache whose name isn't the current one,
// so bumping this constant wipes those stale pages off existing visitors.
const CACHE = 'orbitcloud-v2';

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

// Anything dynamic: never cached, never served from cache.
const DYNAMIC_PATH = /^\/(admin|portal|api|kb|uploads)(\/|$)/i;
// Static asset types that are safe to serve cache-first (all are versioned by
// filename or effectively immutable).
const STATIC_ASSET = /\.(css|js|png|jpe?g|jfif|gif|svg|webp|ico|woff2?|ttf|eot|webmanifest)$/i;

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

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;

  const url = new URL(e.request.url);
  if (url.origin !== self.location.origin) return;

  // Admin, portal, API and any .php or query-string URL are live data —
  // hand them straight to the network so the browser's own no-store headers
  // are what decides, and nothing ever gets stored here.
  if (DYNAMIC_PATH.test(url.pathname) || /\.php$/i.test(url.pathname) || url.search) return;

  // Static assets: cache-first (fast, and their content is tied to the URL).
  if (STATIC_ASSET.test(url.pathname)) {
    e.respondWith(
      caches.match(e.request).then(cached =>
        cached || fetch(e.request).then(res => {
          if (res && res.status === 200 && res.type === 'basic') {
            const clone = res.clone();
            caches.open(CACHE).then(c => c.put(e.request, clone));
          }
          return res;
        })
      )
    );
    return;
  }

  // Pages (HTML): network-first, cache only as an offline fallback — so an
  // edited page is picked up on the next visit instead of being frozen at
  // whatever version happened to be cached first.
  e.respondWith(
    fetch(e.request).then(res => {
      if (res && res.status === 200 && res.type === 'basic') {
        const clone = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, clone));
      }
      return res;
    }).catch(() => caches.match(e.request).then(cached =>
      cached || new Response('You appear to be offline.', {
        status: 503,
        headers: { 'Content-Type': 'text/plain; charset=utf-8' },
      })
    ))
  );
});
