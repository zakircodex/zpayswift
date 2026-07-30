(() => {
  'use strict';

  const existing = window.ZNEWS_CONFIG && typeof window.ZNEWS_CONFIG === 'object'
    ? window.ZNEWS_CONFIG
    : {};
  const standaloneHost = 'zsky24.com';
  const requestHost = window.location.hostname.toLowerCase().replace(/^www\./, '');
  const standalone = requestHost === standaloneHost;
  const routeBase = standalone ? '' : '/znews';
  const zpayOrigin = 'https://zpayswift.com';
  const publicPath = (kind = '', id = '') => {
    const suffix = kind ? `/${kind}/${encodeURIComponent(String(id || ''))}` : '/';
    return `${routeBase}${suffix}`.replace(/\/+$/, kind ? '' : '/');
  };
  const parseRoute = (pathname = window.location.pathname) => {
    const pattern = standalone
      ? /^\/(post|creator)\/([A-Za-z0-9_-]+)\/?$/
      : /^\/znews\/(post|creator)\/([A-Za-z0-9_-]+)\/?$/;
    const match = String(pathname).match(pattern);
    return match ? { kind: match[1], id: decodeURIComponent(match[2]) } : { kind: 'feed', id: '' };
  };
  const resolveProfilePhotoUrl = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const base = raw.startsWith('/uploads/profile/photos/') ? zpayOrigin : window.location.origin;
    return new URL(raw, base).toString();
  };

  window.ZNEWS_CONFIG = Object.freeze({
    apiBase: existing.apiBase || '/api',
    appKey: existing.appKey || 'zawtopup',
    sessionStorageKey: 'znews_session_v1',
    profileStorageKey: 'znews_profile_v1',
    feedPageSize: 12,
    commentPageSize: 50,
    creatorPostPageSize: 30,
    brandName: 'Z Sky 24',
    standaloneHost,
    standalone,
    routeBase,
    zpayOrigin,
    publicPath,
    parseRoute,
    resolveProfilePhotoUrl,
    canonicalUrl: (kind = '', id = '') => `https://${standaloneHost}${kind ? `/${kind}/${encodeURIComponent(String(id || ''))}` : '/'}`,
    zpayDashboardUrl: `${zpayOrigin}/user/dashboard`,
    zpayRegisterUrl: `${zpayOrigin}/user/register`,
    ads: Object.freeze({
      provider: 'INMOBI',
      mode: existing.ads?.mode || 'TEST',
      enabled: existing.ads?.enabled === true,
      placements: Object.freeze({
        feed_sidebar: existing.ads?.placements?.feed_sidebar || '',
        post_inline: existing.ads?.placements?.post_inline || '',
        post_reader: existing.ads?.placements?.post_reader || ''
      })
    })
  });

  const publicLedgerLabels = new Map([
    ['Ad revenue share', 'Creator share'],
    ['Main wallet transfer', 'Balance transfer']
  ]);

  function normalisePublicUi() {
    document.querySelectorAll('.ledger-item > div > strong').forEach((element) => {
      const replacement = publicLedgerLabels.get(element.textContent.trim());
      if (replacement) element.textContent = replacement;
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    normalisePublicUi();
    const observer = new MutationObserver(normalisePublicUi);
    observer.observe(document.body, { subtree: true, childList: true });
  });
})();
