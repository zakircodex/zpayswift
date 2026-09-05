'use strict';

const CACHE_NAME = 'zsky24-embedded-shell-v31';
const SHELL_REVISION = 'adsterra-reader-v1';
const SHELL = [
  '/znews/',
  '/znews/index.html',
  '/znews/assets/znews.css?v=5',
  '/znews/assets/znews-premium.css?v=17',
  '/znews/assets/znews-reader.css?v=3',
  '/znews/assets/znews-weekly-review.css?v=3',
  '/znews/assets/znews-config.js?v=10',
  '/znews/assets/znews-api.js?v=16',
  '/znews/assets/znews-weekly-review.js?v=4',
  '/znews/assets/znews-ads.js?v=3',
  '/znews/assets/znews-rich-editor.js?v=5',
  '/znews/assets/znews-bootstrap.js?v=36',
  '/znews/assets/znews-access.js?v=4',
  '/znews/assets/znews-request-scheduler.js?v=1',
  '/znews/assets/znews-progressive-feed.js?v=3',
  '/znews/assets/znews-feed-ui.js?v=3',
  '/znews/assets/znews-profile.js?v=7',
  '/znews/assets/znews-reader.js?v=4',
  '/znews/assets/znews.js?v=29',
  '/znews/assets/znews-image-optimizer.js?v=1',
  '/znews/assets/znews-header.js?v=2',
  '/znews/assets/znews-creator.js?v=14',
  '/znews/assets/znews-instant-comments.js?v=4',
  '/znews/manifest.webmanifest'
];

async function cacheFresh(request) {
  const response = await fetch(request, { cache: 'no-store' });
  if (response.ok) {
    const cache = await caches.open(CACHE_NAME);
    await cache.put(request, response.clone());
  }
  return response;
}

async function networkFirst(request, fallbackUrl = '') {
  try {
    return await cacheFresh(request);
  } catch (_error) {
    const cached = await caches.match(request);
    if (cached) return cached;
    if (fallbackUrl) {
      const fallback = await caches.match(fallbackUrl);
      if (fallback) return fallback;
    }
    return Response.error();
  }
}

self.addEventListener('install', (event) => {
  void SHELL_REVISION;
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    await Promise.allSettled(SHELL.map(async (url) => {
      const request = new Request(url, { cache: 'reload' });
      const response = await fetch(request);
      if (response.ok) await cache.put(request, response);
    }));
  })());
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => key.startsWith('zsky24-embedded-') && key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;

  if (request.mode === 'navigate' && url.pathname.startsWith('/znews/')) {
    event.respondWith(networkFirst(request, '/znews/index.html'));
    return;
  }

  if (!url.pathname.startsWith('/znews/')) return;
  event.respondWith(networkFirst(request));
});
