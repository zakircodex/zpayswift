(() => {
  'use strict';

  if (!window.isSecureContext || !('serviceWorker' in navigator) || window.top !== window.self) {
    return;
  }

  const registerUserApp = () => {
    navigator.serviceWorker.register('/user/pwa-sw.js', {
      scope: '/user/',
      updateViaCache: 'none'
    }).catch(() => {
      // Installation support is optional and must never interrupt account use.
    });
  };

  if (document.readyState === 'complete') {
    registerUserApp();
    return;
  }

  window.addEventListener('load', registerUserApp, { once: true });
})();
