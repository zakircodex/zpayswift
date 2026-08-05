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
    const suffix = kind === 'policy'
      ? '/policy'
      : (kind ? `/${kind}/${encodeURIComponent(String(id || ''))}` : '/');
    return `${routeBase}${suffix}`.replace(/\/+$/, kind ? '' : '/');
  };
  const parseRoute = (pathname = window.location.pathname) => {
    const policyPattern = standalone ? /^\/policy\/?$/ : /^\/znews\/policy\/?$/;
    if (policyPattern.test(String(pathname))) return { kind: 'policy', id: '' };
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
    zpayLoginUrl: `${zpayOrigin}/user`,
    creatorRevenueMode: 'PERIOD_REVIEW_DIRECT_ZPAY_PAYOUT',
    creatorBalanceEnabled: false,
    ads: Object.freeze({
      provider: 'NONE',
      mode: 'DISABLED',
      enabled: false,
      eligibilitySource: 'VIEW_START_SERVER_POLICY',
      placements: Object.freeze({
        feed_sidebar: '',
        post_inline: '',
        post_reader: ''
      })
    })
  });

  function normaliseCreatorRevenueUi() {
    document.querySelectorAll('[data-route="balance"], [data-menu-route="balance"]').forEach((element) => {
      element.hidden = true;
      element.setAttribute('aria-hidden', 'true');
      element.setAttribute('tabindex', '-1');
    });

    const balanceView = document.querySelector('#balanceView');
    if (balanceView) {
      balanceView.hidden = true;
      balanceView.setAttribute('aria-hidden', 'true');
    }
    document.querySelectorAll('.balance-mini').forEach((element) => {
      element.hidden = true;
      element.setAttribute('aria-hidden', 'true');
    });

    document.querySelectorAll('.ad-slot').forEach((element) => {
      element.hidden = true;
      element.setAttribute('aria-hidden', 'true');
      element.replaceChildren();
    });

    const policyTitle = document.querySelector('.creator-policy-page .policy-header h1');
    const policyIntro = document.querySelector('.creator-policy-page .policy-header p');
    const policyLabel = document.querySelector('#creatorAdRateLabel');
    const policyValue = document.querySelector('#creatorAdRate');
    const policyNote = document.querySelector('#creatorAdRateNote');
    if (policyTitle) policyTitle.textContent = 'Creator revenue policy';
    if (policyIntro) policyIntro.textContent = 'How creator performance reviews and direct Z-Pay payouts are handled.';
    if (policyLabel) policyLabel.textContent = 'Review and payout cycle';
    if (policyValue) policyValue.textContent = 'Weekly review • Monthly payout';
    if (policyNote) policyNote.textContent = 'Z Sky 24 does not keep a creator wallet or show an estimated ad balance.';

    const sections = document.querySelector('.creator-policy-page .policy-sections');
    if (sections && sections.dataset.periodPolicyApplied !== 'true') {
      sections.dataset.periodPolicyApplied = 'true';
      sections.innerHTML = `
        <section><h2>How creator share is calculated</h2><ul><li>Only verified Adsterra revenue for Z Sky 24 placements is used.</li><li>A configured safety reserve is removed first, then the creator pool is distributed by each creator's share of eligible guest views.</li><li>Post count, ad clicks and repeated refreshes do not directly increase payout.</li></ul></section>
        <section><h2>What qualifies</h2><ul><li>Only legitimate guest reading sessions that pass server-side anti-fraud checks qualify.</li><li>Authenticated creators may read all posts, but creator sessions do not load ads and do not enter the revenue-share view pool.</li><li>More than three guest views inside the configured five-minute window are marked invalid and ads are temporarily disabled for that visitor.</li></ul></section>
        <section><h2>Account eligibility</h2><ul><li>Creators must use a linked Z-Pay USER or RETAILER account.</li><li>Both the Z Sky creator status and the live Z-Pay account status must be ACTIVE at payout time.</li><li>Blocked creators remain in a separate admin list and cannot receive payout.</li></ul></section>
        <section><h2>Direct payout rules</h2><ul><li>Z Sky 24 has no creator balance, withdrawal page or automatic per-ad credit.</li><li>Approved revenue is paid directly to the linked Z-Pay wallet: BDT for BD accounts and MYR for MY accounts.</li><li>One payout execution batch can contain no more than five creators.</li></ul></section>`;
    }

    const notice = document.querySelector('.creator-policy-page .policy-notice');
    if (notice) {
      notice.innerHTML = '<strong>Important</strong><p>Creator revenue is not guaranteed per post, view or advertisement. Final payout depends on verified provider revenue, eligible guest traffic, account status, fraud review and the approved exchange-rate snapshot.</p>';
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    normaliseCreatorRevenueUi();
    const observer = new MutationObserver(normaliseCreatorRevenueUi);
    observer.observe(document.body, { subtree: true, childList: true });
  });
})();
