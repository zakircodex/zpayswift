'use strict';

const USER_SCOPE_PREFIX = '/user/';

self.addEventListener('install', (event) => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin
      || !url.pathname.startsWith(USER_SCOPE_PREFIX)
      || url.pathname.startsWith('/api/')) {
    return;
  }

  // Financial pages always come from the network. No authenticated response,
  // balance, history, session, or transaction data is stored by this worker.
  event.respondWith(fetch(request));
});
