/*
  Offline shell.
  
  Caches the client itself so a dropped connection leaves a working tablet
  rather than a browser error page — the tech still has the loaded RO on screen
  and can still dictate, which is the one thing you cannot ask him to repeat.

  API responses are never cached: a stale repair order is worse than an honest
  "no connection", and §10 says lookups must fail loudly, not silently.
*/
const CACHE = 'shopvoice-bay-v1';
const SHELL = ['./', 'index.html', 'app.js', 'styles.css', 'icon.svg', 'manifest.webmanifest'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  if (url.pathname.startsWith('/api/')) return; // straight to the network, always

  event.respondWith(
    caches.match(event.request).then((hit) => hit || fetch(event.request))
  );
});
