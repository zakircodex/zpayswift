(() => {
  'use strict';

  const shell = window.UserShell;
  const pageRoot = document.body.classList.contains('user-dashboard-page')
    ? document.getElementById('overviewSection')
    : null;
  if (!shell || !pageRoot) return;

  const byId = (id) => document.getElementById(id);
  const loadingModal = byId('dashboardLoadingModal');
  const loadingText = byId('dashboardLoadingText');
  const pullIndicator = byId('dashboardPullIndicator');
  const pullText = byId('dashboardPullText');
  const pullThreshold = 72;
  const pullLimit = 112;
  let refreshPromise = null;
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
    const currency = String(
      wallet?.display_currency || wallet?.wallet_currency || wallet?.currency || user?.wallet_currency || ''
    ).toUpperCase();
    return country === 'MY' || currency === 'MYR' ? 'RM' : 'BDT';
  }

  function setDashboardLoading(on, message = 'Loading dashboard, please wait...') {
    if (!loadingModal || !document.body.classList.contains('user-dashboard-page')) return;
    const open = Boolean(on);
    if (loadingText) loadingText.textContent = String(message || 'Loading dashboard, please wait...');
    loadingModal.classList.toggle('show', open);
    loadingModal.setAttribute('aria-hidden', open ? 'false' : 'true');
    loadingModal.inert = !open;
    pageRoot.setAttribute('aria-busy', open ? 'true' : 'false');
    document.body.classList.toggle('user-dashboard-loading-open', open);
  }

  function renderDashboard(data) {
    const user = data.user || shell.state.user || {};
    const summary = data.wallet_summary || {};
    const wallet = summary.wallet || {};
    const logs = data.request_logs || {};
    const prefix = walletPrefix(wallet, user);
    const pricingCountry = String(user.pricing_country || summary.pricing_country || wallet.pricing_country || '').toUpperCase();
    const currency = String(wallet.display_currency || wallet.wallet_currency || wallet.currency || '').toUpperCase();
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
    byId('heroRequests').textContent = String(Array.isArray(logs.items) ? logs.items.length : 0);
    const displayName = String(user.name || summary.name || 'Z-Pay User');
    byId('heroName').textContent = displayName;
    byId('heroName').title = displayName;
    byId('heroRate').textContent = pricingCountry === 'MY' || currency === 'MYR'
      ? (rate > 0 ? `RM 1 = ${rate.toFixed(2)} BDT` : 'Rate unavailable')
      : 'Not applicable';
  }

  function bindActions() {
    if (actionsBound) return;
    actionsBound = true;
    document.querySelector('[data-dashboard-action="shopping"]')?.addEventListener('click', () => {
      shell.toast('Shopping is not available yet.', 'info');
    });
    document.querySelector('[data-dashboard-action="info"]')?.addEventListener('click', () => {
      shell.toast('Z-Pay Swift Web User Panel', 'info');
    });
  }

  function hasOpenModal() {
    return Array.from(document.querySelectorAll('[aria-modal="true"][aria-hidden="false"]'))
      .some((modal) => modal !== loadingModal);
  }

  function canPullToRefresh(event) {
    const active = document.activeElement;
    return !refreshPromise
      && !loadingModal?.classList.contains('show')
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
        await shell.loadUnread();
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
    setDashboardLoading(true);
    bindActions();
    bindSwipeRefresh();
    try {
      await shell.ready;
      renderDashboard(shell.state.bootstrapData || {});
    } catch (_) {
      // The shared shell already presents a safe bootstrap error or redirects an expired session.
    } finally {
      setDashboardLoading(false);
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
