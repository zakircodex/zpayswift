(() => {
  'use strict';

  const existing = window.ZNEWS_CONFIG && typeof window.ZNEWS_CONFIG === 'object'
    ? window.ZNEWS_CONFIG
    : {};

  window.ZNEWS_CONFIG = Object.freeze({
    apiBase: existing.apiBase || '/api',
    appKey: existing.appKey || 'zawtopup',
    sessionStorageKey: 'znews_session_v1',
    profileStorageKey: 'znews_profile_v1',
    feedPageSize: 12,
    commentPageSize: 50,
    creatorPostPageSize: 30,
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
