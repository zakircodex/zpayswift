(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const ApiClient = window.ZNewsApiClient;

  function syncDomainMetadata() {
    const route = config.parseRoute();
    const canonical = config.canonicalUrl(route.kind === 'feed' ? '' : route.kind, route.id);
    document.querySelector('link[rel="canonical"]')?.setAttribute('href', canonical);
    document.querySelector('meta[property="og:url"]')?.setAttribute('content', canonical);
    document.querySelector('meta[property="og:title"]')?.setAttribute(
      'content',
      route.kind === 'post' ? 'Read this story on Z Sky 24' : 'Z Sky 24'
    );
    document.querySelector('#appManifest')?.setAttribute(
      'href',
      config.standalone ? '/manifest.webmanifest' : '/znews/manifest.webmanifest'
    );
    document.querySelectorAll('[data-public-home]').forEach((link) => {
      link.setAttribute('href', config.publicPath());
    });
    document.querySelectorAll('[data-zpay-dashboard]').forEach((link) => {
      link.setAttribute('href', config.zpayDashboardUrl);
    });
    document.querySelectorAll('[data-zpay-login], #commentGuestCta').forEach((link) => {
      link.setAttribute('href', config.zpayLoginUrl);
    });
  }

  function loadScript(src, timeoutMs = 10000) {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      const timer = window.setTimeout(() => {
        script.remove();
        reject(new Error(`Timed out loading ${src}`));
      }, timeoutMs);
      script.src = src;
      script.async = false;
      script.onload = () => {
        window.clearTimeout(timer);
        resolve();
      };
      script.onerror = () => {
        window.clearTimeout(timer);
        reject(new Error(`Failed to load ${src}`));
      };
      document.body.appendChild(script);
    });
  }

  async function prepareServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    try {
      const registration = await navigator.serviceWorker.register(
        config.standalone ? '/sw.js' : '/znews/sw.js',
        { updateViaCache: 'none' }
      );
      await registration.update();
    } catch (_error) {
      // Service-worker failures must never block the public feed.
    }
  }

  function handoffCode() {
    const hash = window.location.hash.startsWith('#')
      ? window.location.hash.slice(1)
      : window.location.hash;
    return new URLSearchParams(hash).get('handoff') || '';
  }

  function clearHandoffFragment() {
    window.history.replaceState(
      window.history.state,
      '',
      `${window.location.pathname}${window.location.search}`
    );
  }

  async function exchangeHandoff(api) {
    const code = handoffCode().trim();
    if (!code) return false;

    try {
      const result = await api.exchangeHandoff(code);
      const sessionToken = String(result.data?.session_token || '').trim();
      if (!sessionToken) throw new Error('Handoff did not return a session token.');
      api.setSession(sessionToken, result.data?.user || {});
      window.ZNEWS_HANDOFF_RESULT = { ok: true };
      return true;
    } catch (error) {
      api.clearSession();
      window.ZNEWS_HANDOFF_RESULT = {
        ok: false,
        code: error?.code || 'ZNEWS_HANDOFF_FAILED',
        message: error?.message || 'Z Sky 24 access could not be granted.'
      };
      return false;
    } finally {
      clearHandoffFragment();
    }
  }

  async function validateStoredSession(api) {
    if (!api.isAuthenticated()) return;
    try {
      const result = await api.validateCreatorSession({ timeoutMs: 6000 });
      api.setSession(api.sessionToken, result.data?.user || api.profile || {});
      window.ZNEWS_SESSION_VALIDATION = { ok: true };
    } catch (error) {
      if (error?.status === 401 || error?.code === 'ZNEWS_AUTH_REQUIRED') {
        api.clearSession();
        window.ZNEWS_SESSION_VALIDATION = { ok: false, expired: true };
        return;
      }
      window.ZNEWS_SESSION_VALIDATION = {
        ok: false,
        deferred: true,
        code: error?.code || 'ZNEWS_SESSION_VALIDATION_DEFERRED'
      };
    }
  }

  async function boot() {
    if (!config || !ApiClient) throw new Error('Z Sky 24 configuration is unavailable.');
    syncDomainMetadata();
    void prepareServiceWorker();

    const api = new ApiClient(config);
    const exchanged = await exchangeHandoff(api);
    if (!exchanged) await validateStoredSession(api);

    await loadScript('/znews/assets/znews-access.js?v=2');
    await loadScript('/znews/assets/znews-feed-ui.js?v=1');
    await loadScript('/znews/assets/znews-profile.js?v=4');
    await loadScript('/znews/assets/znews-reader.js?v=3');
    await loadScript('/znews/assets/znews.js?v=18');
    await loadScript('/znews/assets/znews-header.js?v=2');
    await loadScript('/znews/assets/znews-creator.js?v=7');
    await loadScript('/znews/assets/znews-instant-comments.js?v=4');
    document.documentElement.classList.add('znews-ready');
  }

  boot().catch((error) => {
    document.documentElement.classList.add('znews-ready');
    const announcement = document.querySelector('#announcement');
    if (announcement) {
      announcement.hidden = false;
      announcement.textContent = error?.message?.startsWith('Timed out loading')
        ? 'Z Sky 24 assets timed out. Please check your connection and reload.'
        : 'Z Sky 24 could not finish loading. Please reload the page.';
    }
  });
})();
