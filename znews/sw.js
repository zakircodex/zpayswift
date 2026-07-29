'use strict';

const CACHE_NAME = 'znews-shell-v4';
const SHELL = [
  '/znews/',
  '/znews/index.html',
  '/znews/assets/znews.css?v=2',
  '/znews/assets/znews-premium.css?v=1',
  '/znews/assets/znews-config.js?v=3',
  '/znews/assets/znews-api.js?v=3',
  '/znews/assets/znews-ads.js?v=1',
  '/znews/assets/znews-bootstrap.js?v=1',
  '/znews/assets/znews-access.js?v=1',
  '/znews/assets/znews.js?v=3',
  '/znews/assets/znews-creator.js?v=2',
  '/znews/assets/znews-instant-comments.js?v=2',
  '/znews/manifest.webmanifest'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;

  if (request.mode === 'navigate' && url.pathname.startsWith('/znews/')) {
    event.respondWith(
      fetch(request).catch(() => caches.match('/znews/index.html'))
    );
    return;
  }

  if (!url.pathname.startsWith('/znews/')) return;

  event.respondWith(
    caches.match(request).then((cached) => cached || fetch(request).then((response) => {
      if (response.ok) {
        const copy = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
      }
      return response;
    }))
  );
});
