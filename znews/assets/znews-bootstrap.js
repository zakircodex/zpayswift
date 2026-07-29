(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const ApiClient = window.ZNewsApiClient;

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src;
      script.async = false;
      script.onload = resolve;
      script.onerror = () => reject(new Error(`Failed to load ${src}`));
      document.body.appendChild(script);
    });
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
        message: error?.message || 'Z News access could not be granted.'
      };
      return false;
    } finally {
      clearHandoffFragment();
    }
  }

  async function validateStoredSession(api) {
    if (!api.isAuthenticated()) return;
    try {
      const result = await api.validateCreatorSession();
      api.setSession(api.sessionToken, result.data?.user || api.profile || {});
    } catch (error) {
      if (error?.status === 401 || error?.code === 'ZNEWS_AUTH_REQUIRED') {
        api.clearSession();
      }
    }
  }

  async function boot() {
    if (!config || !ApiClient) throw new Error('Z News configuration is unavailable.');
    const api = new ApiClient(config);
    const exchanged = await exchangeHandoff(api);
    if (!exchanged) await validateStoredSession(api);

    await loadScript('/znews/assets/znews-access.js?v=1');
    await loadScript('/znews/assets/znews-feed-ui.js?v=1');
    await loadScript('/znews/assets/znews-profile.js?v=1');
    await loadScript('/znews/assets/znews.js?v=3');
    await loadScript('/znews/assets/znews-header.js?v=2');
    await loadScript('/znews/assets/znews-creator.js?v=2');
    await loadScript('/znews/assets/znews-instant-comments.js?v=2');
    document.documentElement.classList.add('znews-ready');
  }

  boot().catch(() => {
    document.documentElement.classList.add('znews-ready');
    const announcement = document.querySelector('#announcement');
    if (announcement) {
      announcement.hidden = false;
      announcement.textContent = 'Z News could not finish loading. Please reload the page.';
    }
  });
})();
