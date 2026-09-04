(() => {
  'use strict';

  if (window.ZNEWS_BOOTSTRAP_STARTED === true) return;
  window.ZNEWS_BOOTSTRAP_STARTED = true;

  const config = window.ZNEWS_CONFIG;
  const ApiClient = window.ZNewsApiClient;
  const timings = window.ZNEWS_BOOT_TIMINGS && typeof window.ZNEWS_BOOT_TIMINGS === 'object'
    ? window.ZNEWS_BOOT_TIMINGS
    : { navigationStart: 0 };
  window.ZNEWS_BOOT_TIMINGS = timings;

  function mark(name, explicitValue = null) {
    if (Object.prototype.hasOwnProperty.call(timings, name)) return timings[name];
    const measured = Number(explicitValue);
    const value = explicitValue !== null && explicitValue !== undefined
      && Number.isFinite(measured) && measured >= 0
      ? measured
      : (typeof performance?.now === 'function' ? performance.now() : 0);
    timings[name] = value;
    window.dispatchEvent(new CustomEvent('znews:boot-timing', { detail: { name, value } }));
    return value;
  }

  function resourceReadyAt(assetName) {
    const entry = performance.getEntriesByType?.('resource')
      ?.find((item) => String(item.name || '').includes(`/znews/assets/${assetName}`));
    return Number(entry?.responseEnd || performance.now());
  }

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
    const existing = [...document.scripts].find((script) => {
      try {
        const url = new URL(script.src, window.location.origin);
        return `${url.pathname}${url.search}` === src;
      } catch (_error) {
        return false;
      }
    });
    if (existing) return Promise.resolve();

    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      const timer = window.setTimeout(() => {
        script.remove();
        reject(new Error(`Timed out loading ${src}`));
      }, timeoutMs);
      script.src = src;
      script.async = false;
      script.defer = true;
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

  function loadStylesheet(href, timeoutMs = 10000) {
    const existing = [...document.querySelectorAll('link[rel="stylesheet"]')].find((link) => {
      try {
        const url = new URL(link.href, window.location.origin);
        return `${url.pathname}${url.search}` === href;
      } catch (_error) {
        return false;
      }
    });
    if (existing) return Promise.resolve();

    return new Promise((resolve, reject) => {
      const link = document.createElement('link');
      const timer = window.setTimeout(() => {
        link.remove();
        reject(new Error(`Timed out loading ${href}`));
      }, timeoutMs);
      link.rel = 'stylesheet';
      link.href = href;
      link.onload = () => {
        window.clearTimeout(timer);
        resolve();
      };
      link.onerror = () => {
        window.clearTimeout(timer);
        reject(new Error(`Failed to load ${href}`));
      };
      document.head.appendChild(link);
    });
  }

  const ensureImageOptimizer = () => loadScript('/znews/assets/znews-image-optimizer.js?v=1')
    .then(() => window.ZNewsImageOptimizer);
  window.ZNEWS_IMAGE_OPTIMIZER_READY = ensureImageOptimizer;

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

  let pendingHandoffCode = handoffCode().trim();
  if (pendingHandoffCode) clearHandoffFragment();

  const SESSION_VALIDATION_TIMEOUT_MS = 15000;
  const SESSION_VALIDATION_MAX_ATTEMPTS = 2;
  const SESSION_VALIDATION_RETRY_DELAY_MS = 500;

  function wait(milliseconds) {
    return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
  }

  function sessionValidationIsExpired(error) {
    return error?.status === 401 || [
      'ZNEWS_AUTH_REQUIRED',
      'SESSION_EXPIRED',
      'DEVICE_REPLACED'
    ].includes(String(error?.code || ''));
  }

  function sessionValidationIsTerminal(error) {
    const status = Number(error?.status || 0);
    const code = String(error?.code || '');
    return sessionValidationIsExpired(error)
      || (status >= 400 && status < 500)
      || code === 'MAINTENANCE';
  }

  function sessionValidationCanRetry(error) {
    if (sessionValidationIsTerminal(error)) return false;
    const status = Number(error?.status || 0);
    return status >= 500 || status === 0 || [
      'REQUEST_TIMEOUT',
      'NETWORK_FAILURE',
      'MALFORMED_RESPONSE'
    ].includes(String(error?.code || ''));
  }

  async function exchangeHandoff(api) {
    const code = pendingHandoffCode;
    pendingHandoffCode = '';
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
    }
  }

  async function validateStoredSession(api) {
    if (!api.isAuthenticated()) return false;

    for (let attempt = 1; attempt <= SESSION_VALIDATION_MAX_ATTEMPTS; attempt += 1) {
      try {
        const result = await api.validateCreatorSession({
          timeoutMs: SESSION_VALIDATION_TIMEOUT_MS
        });
        api.setSession(api.sessionToken, result.data?.user || api.profile || {});
        window.ZNEWS_SESSION_VALIDATION = {
          ok: true,
          attempts: attempt,
          recovered: attempt > 1
        };
        return true;
      } catch (error) {
        if (sessionValidationIsTerminal(error)) {
          const expired = sessionValidationIsExpired(error);
          if (expired) api.clearSession();
          window.ZNEWS_SESSION_VALIDATION = {
            ok: false,
            expired,
            rejected: !expired,
            attempts: attempt,
            code: error?.code || 'ZNEWS_SESSION_VALIDATION_REJECTED'
          };
          return false;
        }

        if (attempt < SESSION_VALIDATION_MAX_ATTEMPTS
          && api.isAuthenticated()
          && sessionValidationCanRetry(error)) {
          await wait(SESSION_VALIDATION_RETRY_DELAY_MS);
          continue;
        }

        window.ZNEWS_SESSION_VALIDATION = {
          ok: false,
          deferred: true,
          attempts: attempt,
          code: error?.code || 'ZNEWS_SESSION_VALIDATION_DEFERRED'
        };
        return false;
      }
    }

    return false;
  }

  function waitForPublicContent() {
    if (config.parseRoute().kind === 'policy') return Promise.resolve('policy');
    return new Promise((resolve) => {
      let settled = false;
      const finish = (reason) => {
        if (settled) return;
        settled = true;
        window.clearTimeout(fallback);
        resolve(reason);
      };
      const fallback = window.setTimeout(() => finish('bounded-fallback'), 4500);
      window.addEventListener('znews:first-card', () => finish('first-card'), { once: true });
      window.addEventListener('znews:feed-settled', () => finish('feed-settled'), { once: true });
    });
  }

  function scheduleIdle(task) {
    if (typeof window.requestIdleCallback === 'function') {
      window.requestIdleCallback(() => task(), { timeout: 1200 });
      return;
    }
    window.setTimeout(task, 0);
  }

  async function resolveAuthentication(api) {
    mark('auth_start');
    const exchanged = await exchangeHandoff(api);
    const verified = exchanged || await validateStoredSession(api);
    window.ZNEWS_AUTH_VERIFIED = verified === true && api.isAuthenticated();
    window.ZNEWS_AUTH_STATE = Object.freeze({
      ready: true,
      authenticated: window.ZNEWS_AUTH_VERIFIED,
      expired: window.ZNEWS_SESSION_VALIDATION?.expired === true,
      handoff: window.ZNEWS_HANDOFF_RESULT?.ok === true
    });
    mark('auth_ready');
    window.dispatchEvent(new CustomEvent('znews:auth-ready', {
      detail: window.ZNEWS_AUTH_STATE
    }));
    return window.ZNEWS_AUTH_VERIFIED;
  }

  async function loadPostPaintModules(authenticated) {
    const accessResult = await Promise.allSettled([
      loadScript('/znews/assets/znews-access.js?v=3')
    ]);
    const publicModules = [
      loadStylesheet('/znews/assets/znews-reader.css?v=2'),
      loadScript('/znews/assets/znews-profile.js?v=6'),
      loadScript('/znews/assets/znews-reader.js?v=4'),
      loadScript('/znews/assets/znews-header.js?v=2')
    ];
    const imageOptimizerReady = authenticated ? ensureImageOptimizer() : Promise.resolve(null);
    const creatorModules = authenticated ? [
      loadStylesheet('/znews/assets/znews-weekly-review.css?v=1'),
      loadScript('/znews/assets/znews-weekly-review.js?v=1'),
      imageOptimizerReady.then(() => loadScript('/znews/assets/znews-creator.js?v=9')),
      loadScript('/znews/assets/znews-instant-comments.js?v=4')
    ] : [];
    const results = accessResult.concat(await Promise.allSettled(publicModules.concat(creatorModules)));
    window.ZNEWS_POST_PAINT_MODULES = Object.freeze({
      ready: true,
      failed: results.filter((result) => result.status === 'rejected').length
    });
    mark('post_paint_modules_ready');
  }

  function showCriticalFailure() {
    document.documentElement.classList.add('znews-ready');
    const announcement = document.querySelector('#announcement');
    if (announcement) {
      announcement.hidden = false;
      announcement.textContent = 'Z Sky 24 could not finish loading. Please reload the page.';
    }
  }

  if (!config || !ApiClient || !window.ZNEWS_REQUEST_SCHEDULER || !window.ZNewsProgressiveFeed) {
    showCriticalFailure();
    return;
  }

  mark('config_ready', resourceReadyAt('znews-config.js'));
  mark('api_ready', resourceReadyAt('znews-api.js'));
  mark('scheduler_ready', resourceReadyAt('znews-request-scheduler.js'));
  mark('progressive_ready', resourceReadyAt('znews-progressive-feed.js'));
  syncDomainMetadata();

  const api = new ApiClient(config);
  window.ZNEWS_API_CLIENT = api;
  window.ZNEWS_AUTH_VERIFIED = false;
  window.ZNEWS_AUTH_STATE = Object.freeze({ ready: false, authenticated: false });

  const publicContentReady = waitForPublicContent();
  const authReady = publicContentReady.then(() => resolveAuthentication(api));
  window.ZNEWS_AUTH_READY = authReady;
  publicContentReady.then(() => {
    void prepareServiceWorker();
    authReady.then((authenticated) => {
      scheduleIdle(() => { void loadPostPaintModules(authenticated); });
    }).catch(() => {});
  }).catch(() => {});

  window.ZNEWS_BOOTSTRAP = Object.freeze({
    mark,
    timings,
    publicContentReady,
    authReady
  });
  document.documentElement.classList.add('znews-ready');
  mark('bootstrap_ready');
})();
