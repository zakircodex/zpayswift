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
    feedPageSize: 3,
    feedBufferLowWatermark: 1,
    creatorPublicPageSize: 12,
    commentPageSize: 20,
    creatorPostPageSize: 10,
    weeklyReviewPageSize: 6,
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
      provider: 'ADSTERRA',
      mode: 'SERVER_GATED',
      enabled: true,
      eligibilitySource: 'VIEW_START_SERVER_POLICY',
      placements: Object.freeze({
        post_reader: 'SIGNED_SAME_ORIGIN_FRAME'
      })
    })
  });

  const REVENUE_UI_SELECTOR = [
    '[data-route="balance"]',
    '[data-menu-route="balance"]',
    '#balanceView',
    '.balance-mini',
    '.creator-policy-page',
    '#creatorAdRateLabel',
    '#creatorAdRate',
    '#creatorAdRateNote'
  ].join(',');

  let revenueUiObserver = null;
  let revenueUiNormalising = false;
  let revenueUiScheduled = false;

  function observeRevenueUi() {
    if (revenueUiObserver && document.body) {
      revenueUiObserver.observe(document.body, { subtree: true, childList: true });
    }
  }

  function hideElement(element) {
    if (!element) return;
    if (!element.hidden) element.hidden = true;
    if (element.getAttribute('aria-hidden') !== 'true') element.setAttribute('aria-hidden', 'true');
  }

  function setTextIfChanged(element, value) {
    if (element && element.textContent !== value) element.textContent = value;
  }

  function normaliseCreatorRevenueUi() {
    if (revenueUiNormalising) return;
    revenueUiNormalising = true;
    revenueUiObserver?.disconnect();

    try {
      document.querySelectorAll('[data-route="balance"], [data-menu-route="balance"]').forEach((element) => {
        hideElement(element);
        if (element.getAttribute('tabindex') !== '-1') element.setAttribute('tabindex', '-1');
      });

      hideElement(document.querySelector('#balanceView'));
      document.querySelectorAll('.balance-mini').forEach(hideElement);

      const policyTitle = document.querySelector('.creator-policy-page .policy-header h1');
      const policyIntro = document.querySelector('.creator-policy-page .policy-header p');
      const policyLabel = document.querySelector('#creatorAdRateLabel');
      const policyValue = document.querySelector('#creatorAdRate');
      const policyNote = document.querySelector('#creatorAdRateNote');
      setTextIfChanged(policyTitle, 'Creator revenue policy');
      setTextIfChanged(policyIntro, 'How creator performance reviews and direct Z-Pay payouts are handled.');
      setTextIfChanged(policyLabel, 'Review and payout cycle');
      setTextIfChanged(policyValue, 'Weekly review • Monthly payout');
      setTextIfChanged(policyNote, 'Z Sky 24 does not keep a creator wallet or show an estimated ad balance.');

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
      if (notice && notice.dataset.periodPolicyApplied !== 'true') {
        notice.dataset.periodPolicyApplied = 'true';
        notice.innerHTML = '<strong>Important</strong><p>Creator revenue is not guaranteed per post, view or advertisement. Final payout depends on verified provider revenue, eligible guest traffic, account status, fraud review and the approved exchange-rate snapshot.</p>';
      }
    } finally {
      revenueUiNormalising = false;
      observeRevenueUi();
    }
  }

  function revenueUiNodeMatches(node) {
    if (!(node instanceof Element)) return false;
    return node.matches(REVENUE_UI_SELECTOR) || Boolean(node.querySelector(REVENUE_UI_SELECTOR));
  }

  function mutationTouchesRevenueUi(record) {
    const target = record.target instanceof Element ? record.target : record.target?.parentElement;
    if (target && (target.matches(REVENUE_UI_SELECTOR) || target.closest(REVENUE_UI_SELECTOR))) {
      return true;
    }
    return Array.from(record.addedNodes || []).some(revenueUiNodeMatches);
  }

  function scheduleRevenueUiNormalise() {
    if (revenueUiScheduled) return;
    revenueUiScheduled = true;
    const run = () => {
      revenueUiScheduled = false;
      normaliseCreatorRevenueUi();
    };
    if (typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(run);
    } else {
      window.setTimeout(run, 0);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    normaliseCreatorRevenueUi();
    revenueUiObserver = new MutationObserver((records) => {
      if (records.some(mutationTouchesRevenueUi)) scheduleRevenueUiNormalise();
    });
    observeRevenueUi();
  });
})();
