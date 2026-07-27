(() => {
  'use strict';

  const shell = window.UserShell;
  if (!shell) return;

  const byId = (id) => document.getElementById(id);
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
    document.querySelector('[data-dashboard-action="shopping"]')?.addEventListener('click', () => {
      shell.toast('Shopping is not available yet.', 'info');
    });
    document.querySelector('[data-dashboard-action="info"]')?.addEventListener('click', () => {
      shell.toast('Z-Pay Swift Web User Panel', 'info');
    });
  }

  async function init() {
    try {
      await shell.ready;
      renderDashboard(shell.state.bootstrapData || {});
      bindActions();
    } catch (error) {
      if (!shell.isSessionError(error)) {
        shell.toast(error.message || 'Unable to load dashboard.', 'error');
      }
    }
  }

  init();
})();
