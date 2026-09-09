(() => {
  'use strict';

  const shell = window.UserShell;
  const pageRoot = document.body.classList.contains('user-dashboard-page')
    ? document.getElementById('overviewSection')
    : null;
  if (!shell || !pageRoot) return;
  const releaseInitialLoad = shell.holdPageLoad?.('Loading dashboard...') || (() => {});

  const byId = (id) => document.getElementById(id);
  const pullIndicator = byId('dashboardPullIndicator');
  const pullText = byId('dashboardPullText');
  const pullThreshold = 72;
  const pullLimit = 112;
  let refreshPromise = null;
  let activityPromise = null;
  let actionsBound = false;
  let swipeBound = false;
  let pullStartX = 0;
  let pullStartY = 0;
  let pullDistance = 0;
  let pullTracking = false;
  let pullDirectionLocked = false;

  const amount = (value) => {
    const number = Number(value || 0);
    return Number.isFinite(number) ? number.toFixed(2) : '0.00';
  };

  function walletPrefix(wallet, user) {
    const country = String(
      user?.pricing_country || wallet?.pricing_country || wallet?.market_country || ''
    ).toUpperCase();
    if (country === 'MY') return 'RM';
    if (country === 'BD') return 'BDT';
    const currency = String(
      wallet?.display_currency || wallet?.wallet_currency || wallet?.currency || user?.wallet_currency || ''
    ).toUpperCase();
    if (currency === 'MYR') return 'RM';
    if (currency === 'BDT') return 'BDT';
    return 'BDT';
  }

  function setDashboardLoading(on, message = 'Refreshing dashboard...') {
    const open = Boolean(on);
    pageRoot.setAttribute('aria-busy', open ? 'true' : 'false');
    shell.setBusy(open, String(message || 'Refreshing dashboard...'));
  }

  function renderDashboard(data) {
    const user = data.user || shell.state.user || {};
    const summary = data.wallet_summary || {};
    const wallet = summary.wallet || {};
    const logs = data.request_logs || {};
    const prefix = walletPrefix(wallet, user);
    const pricingCountry = String(user.pricing_country || summary.pricing_country || wallet.pricing_country || '').toUpperCase();
    const currency = String(wallet.display_currency || wallet.wallet_currency || wallet.currency || '').toUpperCase();
    const isMalaysiaAccount = pricingCountry === 'MY' || (pricingCountry === '' && currency === 'MYR');
    const rate = Number(
      wallet.rate_myr_bdt
      ?? summary.rate_myr_bdt
      ?? wallet.rate_myr_to_bdt
      ?? summary.rate_myr_to_bdt
      ?? summary.current_rate
      ?? 0
    );

    byId('heroBalancePrefix').textContent = prefix;
    byId('heroHoldPrefix').textContent = prefix;
    const balanceText = amount(wallet.display_available_balance ?? wallet.available_balance);
    const balanceValue = byId('heroBalance');
    const balanceLine = balanceValue?.closest('.hero-balance');
    if (balanceValue) balanceValue.textContent = balanceText;
    balanceLine?.classList.toggle('is-long', balanceText.length > 9);
    balanceLine?.classList.toggle('is-very-long', balanceText.length > 12);
    byId('heroHold').textContent = amount(wallet.display_hold_balance ?? wallet.hold_balance);
    if (!logs.deferred) {
      const requestCount = byId('heroRequests');
      if (requestCount) {
        requestCount.textContent = String(Array.isArray(logs.items) ? logs.items.length : 0);
        requestCount.classList.remove('dashboard-deferred-placeholder', 'dashboard-placeholder');
      }
    }
    const displayName = String(user.name || summary.name || 'Z-Pay User');
    byId('heroName').textContent = displayName;
    byId('heroName').title = displayName;
    const role = String(user.account_type || user.role || 'USER').trim().toUpperCase();
    const accountType = role === 'RETAILER' ? 'RETAILER' : 'USER';
    const rateCard = byId('heroRateCard');
    if (rateCard) {
      rateCard.hidden = false;
      rateCard.setAttribute('aria-hidden', 'false');
    }
    byId('heroRateLabel').textContent = isMalaysiaAccount ? 'Today Rate' : 'Account Type';
    byId('heroRate').textContent = isMalaysiaAccount
      ? (rate > 0 ? `RM 1 = ${rate.toFixed(2)} BDT` : 'Rate unavailable')
      : accountType;
  }

  async function loadDashboardActivity(options = {}) {
    if (activityPromise) return activityPromise;

    activityPromise = (async () => {
      const requestCount = byId('heroRequests');
      try {
        const data = await shell.get(
          'dashboard_activity_summary',
          { limit: 50 },
          'Loading dashboard activity...',
          { busy: false }
        );
        if (requestCount) requestCount.textContent = String(Math.max(0, Number(data.request_count || 0)));
        return data;
      } catch (error) {
        if (requestCount) requestCount.textContent = '--';
        if (options.reportError && !shell.isSessionError(error)) {
          shell.toast('Dashboard activity could not be refreshed.', 'error');
        }
        return null;
      } finally {
        requestCount?.classList.remove('dashboard-deferred-placeholder', 'dashboard-placeholder');
      }
    })();

    try {
      return await activityPromise;
    } finally {
      activityPromise = null;
    }
  }

  function bindActions() {
    if (actionsBound) return;
    actionsBound = true;
    document.querySelector('[data-dashboard-action="shopping"]')?.addEventListener('click', () => {
      shell.toast('Shopping is not available yet.', 'info');
    });
  }

  function hasOpenModal() {
    return Array.from(document.querySelectorAll('[aria-modal="true"][aria-hidden="false"]'))
      .some((modal) => modal.id !== 'loadingWrap');
  }

  function canPullToRefresh(event) {
    const active = document.activeElement;
    return !refreshPromise
      && !document.body.classList.contains('user-page-loading')
      && !shell.state.drawerOpen
      && !hasOpenModal()
      && pageRoot.scrollTop <= 0
      && event.touches?.length === 1
      && !(active instanceof HTMLElement && active.matches('input, select, textarea, [contenteditable="true"]'));
  }

  function updatePullIndicator(distance) {
    pullDistance = Math.max(0, Math.min(pullLimit, distance));
    pageRoot.style.setProperty('--dashboard-pull-offset', `${pullDistance}px`);
    pageRoot.classList.toggle('is-pulling', pullDistance > 0);
    pageRoot.classList.toggle('is-pull-ready', pullDistance >= pullThreshold);
    if (pullIndicator) {
      pullIndicator.setAttribute('aria-hidden', pullDistance > 0 ? 'false' : 'true');
    }
    if (pullText) {
      pullText.textContent = pullDistance >= pullThreshold ? 'Release to refresh' : 'Pull to refresh';
    }
  }

  function resetPullIndicator(animate = true) {
    pageRoot.classList.toggle('is-pull-resetting', animate);
    updatePullIndicator(0);
    window.setTimeout(() => pageRoot.classList.remove('is-pull-resetting'), animate ? 180 : 0);
    pullTracking = false;
    pullDirectionLocked = false;
    pullStartX = 0;
    pullStartY = 0;
  }

  async function refreshDashboard(options = {}) {
    if (refreshPromise) return refreshPromise;
    const showLoader = options.showLoader !== false;

    refreshPromise = (async () => {
      if (showLoader) setDashboardLoading(true);
      try {
        const params = window.USER_BOOTSTRAP_PARAMS && typeof window.USER_BOOTSTRAP_PARAMS === 'object'
          ? window.USER_BOOTSTRAP_PARAMS
          : {};
        const data = await shell.get(
          'dashboard_bootstrap',
          params,
          'Loading dashboard, please wait...',
          { busy: false }
        );
        shell.state.bootstrapData = data;
        shell.state.user = data.user || shell.state.user;
        shell.state.csrf = String(data.csrf || shell.state.csrf || '');
        window.userState = shell.state;
        renderDashboard(data);
        void loadDashboardActivity({ reportError: true });
        void shell.loadUnread();
        return data;
      } catch (error) {
        if (!shell.isSessionError(error)) {
          shell.toast('Dashboard data could not be loaded. Please try again.', 'error');
        }
        throw error;
      } finally {
        resetPullIndicator();
        setDashboardLoading(false);
      }
    })();

    try {
      return await refreshPromise;
    } finally {
      refreshPromise = null;
    }
  }

  function bindSwipeRefresh() {
    if (swipeBound) return;
    swipeBound = true;

    pageRoot.addEventListener('touchstart', (event) => {
      if (!canPullToRefresh(event)) return;
      const touch = event.touches[0];
      pullStartX = touch.clientX;
      pullStartY = touch.clientY;
      pullTracking = true;
      pullDirectionLocked = false;
    }, { passive: true });

    pageRoot.addEventListener('touchmove', (event) => {
      if (!pullTracking || event.touches.length !== 1) return;
      const touch = event.touches[0];
      const deltaX = touch.clientX - pullStartX;
      const deltaY = touch.clientY - pullStartY;

      if (!pullDirectionLocked) {
        if (Math.abs(deltaX) < 6 && Math.abs(deltaY) < 6) return;
        if (Math.abs(deltaX) >= Math.abs(deltaY)) {
          resetPullIndicator(false);
          return;
        }
        pullDirectionLocked = true;
      }

      if (deltaY <= 0 || pageRoot.scrollTop > 0) {
        resetPullIndicator(false);
        return;
      }

      event.preventDefault();
      updatePullIndicator(Math.min(pullLimit, deltaY * 0.55));
    }, { passive: false });

    pageRoot.addEventListener('touchend', () => {
      if (!pullTracking) return;
      const shouldRefresh = pullDistance >= pullThreshold;
      resetPullIndicator();
      if (shouldRefresh) {
        refreshDashboard().catch(() => {});
      }
    }, { passive: true });

    pageRoot.addEventListener('touchcancel', () => resetPullIndicator(), { passive: true });
  }

  async function init() {
    bindActions();
    bindSwipeRefresh();
    try {
      await shell.ready;
      renderDashboard(shell.state.bootstrapData || {});
      void loadDashboardActivity();
    } catch (_) {
      // The shared shell already presents a safe bootstrap error or redirects an expired session.
    } finally {
      releaseInitialLoad();
    }
  }

  window.refreshUserDashboard = () => refreshDashboard();
  window.addEventListener('pagehide', () => {
    setDashboardLoading(false);
    resetPullIndicator(false);
  });
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    setDashboardLoading(false);
    resetPullIndicator(false);
    refreshDashboard().catch(() => {});
  });

  init();
})();
