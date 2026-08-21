(() => {
  'use strict';

  if (window.__zpayUserShellInitialized) return;
  window.__zpayUserShellInitialized = true;

  const state = {
    user: null,
    csrf: '',
    unread: 0,
    bootstrapData: null,
    drawerOpener: null,
    drawerOpen: false,
    drawerHistoryClosing: false,
    drawerPendingLink: null,
    drawerScrollY: 0,
    logoutDialogOpen: false,
    logoutOpener: null,
    ready: false,
    maintenance: false,
    bootstrapInFlight: false
  };

  const proxyUrl = window.USER_PROXY_URL || '/api/user/proxy.php';
  const loginUrl = window.USER_LOGIN_URL || '/user/';
  const $ = (id) => document.getElementById(id);

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    }[character]));
  }

  function apiMessage(json, fallback = 'Request failed') {
    const message = String(json?.message || json?.error || '').trim();
    if (!message || /firebase|exception|stack trace|session[_ -]?token|csrf[_ -]?token|\/api\//i.test(message)) {
      return fallback;
    }
    return message;
  }

  function apiError(json, status, fallback) {
    const error = new Error(apiMessage(json, fallback));
    error.code = String(json?.code || 'REQUEST_FAILED').toUpperCase();
    error.status = Number(status || 0);
    error.data = json?.data && typeof json.data === 'object' ? json.data : {};
    return error;
  }

  function isSessionError(error) {
    return ['SESSION_EXPIRED', 'AUTH_REQUIRED', 'UNAUTHORIZED', 'USER_SESSION_EXPIRED']
      .includes(String(error?.code || '').toUpperCase()) || Number(error?.status || 0) === 401;
  }

  function isMaintenanceError(error) {
    return String(error?.code || '').toUpperCase() === 'MAINTENANCE';
  }

  function setBusy(on, label = 'Loading...') {
    const wrap = $('loadingWrap');
    if (!wrap) return;
    $('loadingText').textContent = String(label || 'Loading...');
    wrap.classList.toggle('show', Boolean(on));
    wrap.setAttribute('aria-hidden', on ? 'false' : 'true');
  }

  function toast(message, type = 'info') {
    const wrap = $('toastWrap');
    if (!wrap) return;
    const node = document.createElement('div');
    node.className = `toast ${type === 'error' ? 'error' : type === 'ok' ? 'success' : ''}`;
    node.textContent = String(message || '');
    wrap.appendChild(node);
    window.setTimeout(() => node.remove(), 2800);
  }

  function showMaintenanceState() {
    state.maintenance = true;
    setBusy(false);
    syncDrawer(false);
    const view = $('userMaintenanceView');
    const app = $('appView');
    document.body.classList.remove('user-service-checking');
    document.body.classList.add('user-maintenance-active');
    if (view) {
      view.classList.remove('hidden');
      view.setAttribute('aria-hidden', 'false');
    }
    if (app) {
      app.setAttribute('aria-hidden', 'true');
      app.inert = true;
    }
    window.setTimeout(() => $('retryUserMaintenance')?.focus(), 0);
  }

  function hideMaintenanceState() {
    state.maintenance = false;
    const view = $('userMaintenanceView');
    const app = $('appView');
    document.body.classList.remove('user-service-checking', 'user-maintenance-active');
    if (view) {
      view.classList.add('hidden');
      view.setAttribute('aria-hidden', 'true');
    }
    if (app) {
      app.removeAttribute('aria-hidden');
      app.inert = false;
    }
  }

  async function readJson(response) {
    const text = await response.text();
    if (!text.trim()) throw apiError(null, response.status, 'Empty response received.');
    try {
      return JSON.parse(text);
    } catch (_) {
      throw apiError(null, response.status, 'Invalid response received.');
    }
  }

  async function request(action, options = {}) {
    const method = String(options.method || 'GET').toUpperCase();
    const query = new URLSearchParams({ action: String(action || '') });
    Object.entries(options.params || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') query.set(key, String(value));
    });

    const busy = options.busy !== false;
    if (busy) setBusy(true, options.label || (method === 'GET' ? 'Loading...' : 'Processing...'));

    try {
      const headers = { Accept: 'application/json' };
      const init = { method, credentials: 'same-origin', headers };
      if (method !== 'GET') {
        if (!state.csrf && action !== 'login' && action !== 'login_verify_otp' && action !== 'login_resend_otp') {
          await refreshSession();
        }
        headers['X-CSRF-Token'] = state.csrf;
        if (options.formData instanceof FormData) {
          init.body = options.formData;
        } else {
          headers['Content-Type'] = 'application/json';
          init.body = JSON.stringify(options.body || {});
        }
      }

      const response = await fetch(`${proxyUrl}?${query.toString()}`, init);
      const json = await readJson(response);
      if (!response.ok || json?.ok !== true) {
        throw apiError(json, response.status, method === 'GET' ? 'Failed to load data.' : 'Request failed.');
      }
      if (json.data?.csrf) state.csrf = String(json.data.csrf);
      return json.data || {};
    } catch (error) {
      if (isMaintenanceError(error)) {
        showMaintenanceState();
      } else if (isSessionError(error)) {
        window.location.replace(loginUrl);
      }
      throw error;
    } finally {
      if (busy) setBusy(false);
    }
  }

  function get(action, params = {}, label = 'Loading...', options = {}) {
    return request(action, { ...options, method: 'GET', params, label });
  }

  function post(action, body = {}, label = 'Processing...', options = {}) {
    return request(action, { ...options, method: 'POST', body, label });
  }

  function postForm(action, formData, label = 'Processing...', options = {}) {
    return request(action, { ...options, method: 'POST', formData, label });
  }

  async function refreshSession() {
    const data = await get('me', {}, 'Checking session...', { busy: false });
    state.user = data.user || data;
    state.csrf = String(data.csrf || state.csrf || '');
    window.userState = state;
    renderDrawer();
    return state;
  }

  async function bootstrapSession() {
    const action = String(window.USER_BOOTSTRAP_ACTION || 'me');
    const params = window.USER_BOOTSTRAP_PARAMS && typeof window.USER_BOOTSTRAP_PARAMS === 'object'
      ? window.USER_BOOTSTRAP_PARAMS
      : {};
    const data = await get(action, params, 'Loading...', { busy: false });
    state.bootstrapData = data;
    state.user = data.user || data;
    state.csrf = String(data.csrf || state.csrf || '');
    window.userState = state;
    renderDrawer();
    return state;
  }

  function initials(name) {
    const pieces = String(name || 'Z P').trim().split(/\s+/).filter(Boolean);
    return pieces.slice(0, 2).map((piece) => piece.charAt(0).toUpperCase()).join('') || 'ZP';
  }

  function maskPhone(phone) {
    const value = String(phone || '');
    if (value.includes('*')) return value;
    if (value.length < 7) return value || '-';
    return `${value.slice(0, 4)}****${value.slice(-3)}`;
  }

  function displayCountry(countryCode, currency) {
    const country = String(countryCode || '').trim().toUpperCase();
    const walletCurrency = String(currency || '').trim().toUpperCase();
    if (country === 'MY' || (!country && walletCurrency === 'MYR')) return 'Malaysia';
    if (country === 'BD' || (!country && walletCurrency === 'BDT')) return 'Bangladesh';
    return country || 'Account';
  }

  function drawerWalletData() {
    const data = state.bootstrapData && typeof state.bootstrapData === 'object' ? state.bootstrapData : {};
    const summary = data.wallet_summary && typeof data.wallet_summary === 'object' ? data.wallet_summary : {};
    if (data.wallet && typeof data.wallet === 'object') return data.wallet;
    if (summary.wallet && typeof summary.wallet === 'object') return summary.wallet;
    return summary;
  }

  function syncDrawerActiveState() {
    const pageKey = String(window.USER_PAGE_KEY || document.body.dataset.userPage || '').toLowerCase();
    const activeKey = pageKey === 'profile' && window.location.hash.toLowerCase() === '#security'
      ? 'security'
      : pageKey;
    document.querySelectorAll('[data-drawer-page]').forEach((item) => {
      const active = String(item.dataset.drawerPage || '').toLowerCase() === activeKey;
      item.classList.toggle('active', active);
      if (active) {
        item.setAttribute('aria-current', 'page');
      } else {
        item.removeAttribute('aria-current');
      }
    });
  }

  function renderDrawer() {
    const user = state.user || {};
    const wallet = drawerWalletData();
    const name = String(user.name || user.full_name || 'Z-Pay User');
    const image = String(user.profile_photo_url || user.profile_photo || '');
    const pricingCountry = String(user.pricing_country || user.market_country || wallet.pricing_country || '').toUpperCase();
    const currency = String(
      user.wallet_currency
      || user.currency
      || wallet.wallet_currency
      || wallet.currency
      || (pricingCountry === 'MY' ? 'MYR' : pricingCountry === 'BD' ? 'BDT' : '')
    ).toUpperCase();
    $('drawerUserName') && ($('drawerUserName').textContent = name.toUpperCase());
    $('drawerUserPhone') && ($('drawerUserPhone').textContent = maskPhone(user.phone_masked || user.phone));
    $('drawerRoleChip') && ($('drawerRoleChip').textContent = String(user.role || 'User'));
    $('drawerStatusChip') && ($('drawerStatusChip').textContent = String(user.account_status || user.status || 'Active'));
    $('drawerCountryCurrency') && ($('drawerCountryCurrency').textContent =
      `${displayCountry(pricingCountry, currency)} | ${currency || 'Wallet'}`);
    $('drawerAvatarInitials') && ($('drawerAvatarInitials').textContent = initials(name));
    const avatar = $('drawerAvatarImage');
    if (avatar) {
      delete avatar.dataset.fallback;
      avatar.src = image || '/assets/brand/zpay-icon.png';
      avatar.alt = image ? `${name} profile photo` : '';
      avatar.classList.remove('hidden');
    }
    syncDrawerActiveState();
  }

  async function loadUnread() {
    try {
      const data = await get('notifications_unread', {}, 'Loading notifications...', { busy: false });
      state.unread = Math.max(0, Number(data.unread_count || data.count || 0));
      document.querySelectorAll('[data-notification-badge]').forEach((badge) => {
        badge.textContent = String(state.unread);
        badge.classList.toggle('hidden', state.unread < 1);
      });
    } catch (_) {
      state.unread = 0;
    }
  }

  function focusableInDrawer() {
    return Array.from($('sidebar')?.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])') || []);
  }

  function installDrawerPageTrigger() {
    const trigger = $('openSidebarBtn');
    if (!trigger || !trigger.classList.contains('user-drawer-page-trigger')) return;
    const backControl = document.querySelector('main a[aria-label^="Back to dashboard"]');
    if (backControl) {
      trigger.className = `${backControl.className} user-drawer-page-trigger`;
      backControl.replaceWith(trigger);
    } else {
      trigger.classList.remove('hidden');
      trigger.classList.add('user-drawer-floating-trigger');
    }
  }

  function syncDrawer(open) {
    const sidebar = $('sidebar');
    if (!sidebar) return;
    state.drawerOpen = Boolean(open);
    sidebar.classList.toggle('show', open);
    sidebar.setAttribute('aria-hidden', open ? 'false' : 'true');
    sidebar.inert = !open;
    $('sidebarOverlay')?.classList.toggle('show', open);
    $('openSidebarBtn')?.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.classList.toggle('user-drawer-open', open);
  }

  function openDrawer(event) {
    if (state.drawerOpen) return;
    state.drawerOpener = event?.currentTarget || document.activeElement;
    state.drawerScrollY = window.scrollY;
    if (!window.history.state?.zpayUserDrawer) {
      window.history.pushState({ ...(window.history.state || {}), zpayUserDrawer: true }, '', window.location.href);
    }
    syncDrawer(true);
    window.setTimeout(() => focusableInDrawer()[0]?.focus({ preventScroll: true }), 0);
  }

  function restoreDrawerOpener() {
    state.drawerOpener?.focus?.({ preventScroll: true });
    state.drawerOpener = null;
    window.requestAnimationFrame(() => {
      if (Math.abs(window.scrollY - state.drawerScrollY) > 1) {
        window.scrollTo({ top: state.drawerScrollY, left: 0, behavior: 'auto' });
      }
    });
  }

  function closeDrawer(options = {}) {
    const wasOpen = state.drawerOpen;
    syncDrawer(false);
    if (wasOpen) {
      if (options.restoreFocus !== false) restoreDrawerOpener();
      if (!options.fromHistory && window.history.state?.zpayUserDrawer) {
        state.drawerHistoryClosing = true;
        window.history.back();
      }
    }
  }

  function navigateFromDrawer(event) {
    const link = event.currentTarget;
    if (!state.drawerOpen || !(link instanceof HTMLAnchorElement)) return;
    if (link.target === '_blank' || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
      closeDrawer();
      return;
    }
    const target = new URL(link.href, window.location.href);
    if (target.origin !== window.location.origin) {
      closeDrawer();
      return;
    }
    if (!window.history.state?.zpayUserDrawer) {
      syncDrawer(false);
      return;
    }
    event.preventDefault();
    syncDrawer(false);
    state.drawerOpener = null;
    state.drawerPendingLink = link;
    state.drawerHistoryClosing = true;
    window.history.back();
  }

  function syncLogoutDialog(open) {
    const dialog = $('userLogoutDialog');
    if (!dialog) return;
    state.logoutDialogOpen = open;
    dialog.classList.toggle('hidden', !open);
    dialog.setAttribute('aria-hidden', open ? 'false' : 'true');
    dialog.inert = !open;
    document.body.classList.toggle('user-modal-open', open);
    if (open) {
      window.setTimeout(() => $('cancelUserLogout')?.focus({ preventScroll: true }), 0);
    }
  }

  function openLogoutDialog(event) {
    state.logoutOpener = event?.currentTarget || document.activeElement;
    syncLogoutDialog(true);
    window.history.pushState({ ...(window.history.state || {}), zpayUserLogout: true }, '', window.location.href);
  }

  function closeLogoutDialog(fromHistory = false) {
    if (!state.logoutDialogOpen) return;
    syncLogoutDialog(false);
    if (!fromHistory && window.history.state?.zpayUserLogout) {
      window.history.back();
      return;
    }
    state.logoutOpener?.focus?.({ preventScroll: true });
    state.logoutOpener = null;
  }

  async function logout() {
    const button = $('confirmUserLogout');
    try {
      if (button) {
        button.disabled = true;
        button.textContent = 'Logging out...';
      }
      await post('logout', {}, 'Logging out...');
    } finally {
      window.location.replace(loginUrl);
    }
  }

  function bindShell() {
    installDrawerPageTrigger();
    $('openSidebarBtn')?.addEventListener('click', openDrawer);
    $('sidebarOverlay')?.addEventListener('click', closeDrawer);
    $('drawerLogoutBtn')?.addEventListener('click', (event) => {
      const opener = state.drawerOpener;
      syncDrawer(false);
      state.drawerOpener = null;
      state.logoutOpener = opener;
      syncLogoutDialog(true);
      if (window.history.state?.zpayUserDrawer) {
        const historyState = { ...(window.history.state || {}) };
        delete historyState.zpayUserDrawer;
        historyState.zpayUserLogout = true;
        window.history.replaceState(historyState, '', window.location.href);
      } else {
        window.history.pushState({ ...(window.history.state || {}), zpayUserLogout: true }, '', window.location.href);
      }
    });
    $('cancelUserLogout')?.addEventListener('click', () => closeLogoutDialog());
    $('confirmUserLogout')?.addEventListener('click', logout);
    $('retryUserMaintenance')?.addEventListener('click', attemptBootstrap);
    $('userLogoutDialog')?.addEventListener('click', (event) => {
      if (event.target === $('userLogoutDialog')) closeLogoutDialog();
    });
    $('sidebar')?.querySelectorAll('a[href]').forEach((link) => link.addEventListener('click', navigateFromDrawer));
    $('drawerAvatarImage')?.addEventListener('error', (event) => {
      const image = event.currentTarget;
      if (image.dataset.fallback === 'true') {
        image.classList.add('hidden');
        return;
      }
      image.dataset.fallback = 'true';
      image.src = '/assets/brand/zpay-icon.png';
      image.alt = '';
    });
    window.addEventListener('hashchange', syncDrawerActiveState);
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && state.logoutDialogOpen) {
        closeLogoutDialog();
        return;
      }
      if (event.key === 'Escape') closeDrawer();
      if (event.key !== 'Tab' || !$('sidebar')?.classList.contains('show')) return;
      const controls = focusableInDrawer();
      if (!controls.length) return;
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    window.addEventListener('popstate', (event) => {
      if (state.drawerHistoryClosing) {
        event.stopImmediatePropagation();
        state.drawerHistoryClosing = false;
        const pendingLink = state.drawerPendingLink;
        state.drawerPendingLink = null;
        if (pendingLink) {
          window.setTimeout(() => pendingLink.click(), 0);
        }
        return;
      }
      if (state.logoutDialogOpen) {
        event.stopImmediatePropagation();
        closeLogoutDialog(true);
        return;
      }
      if (state.drawerOpen) {
        event.stopImmediatePropagation();
        closeDrawer({ fromHistory: true });
        return;
      }
      if (event.state?.zpayUserDrawer) {
        event.stopImmediatePropagation();
        state.drawerOpener = $('openSidebarBtn');
        state.drawerScrollY = window.scrollY;
        syncDrawer(true);
        window.setTimeout(() => focusableInDrawer()[0]?.focus({ preventScroll: true }), 0);
      }
    });
  }

  let resolveReady;
  let rejectReady;
  const ready = new Promise((resolve, reject) => {
    resolveReady = resolve;
    rejectReady = reject;
  });

  async function attemptBootstrap() {
    if (state.bootstrapInFlight) return;
    state.bootstrapInFlight = true;
    const retryButton = $('retryUserMaintenance');
    if (retryButton) {
      retryButton.disabled = true;
      retryButton.textContent = 'Checking...';
    }

    try {
      await bootstrapSession();
      hideMaintenanceState();
      if (!state.ready) {
        state.ready = true;
        resolveReady(state);
        document.dispatchEvent(new CustomEvent('zpay:user-ready', { detail: state }));
        loadUnread();
      }
    } catch (error) {
      if (isMaintenanceError(error)) {
        showMaintenanceState();
      } else {
        hideMaintenanceState();
      }
      if (!isSessionError(error) && !isMaintenanceError(error)) {
        toast(error.message || 'Unable to load your account.', 'error');
      }
      if (!isMaintenanceError(error) && !state.ready) rejectReady(error);
    } finally {
      state.bootstrapInFlight = false;
      if (retryButton) {
        retryButton.disabled = false;
        retryButton.textContent = 'Retry';
      }
    }
  }

  function boot() {
    bindShell();
    syncDrawer(false);
    attemptBootstrap();
  }

  window.userState = state;
  window.UserShell = {
    state,
    ready,
    get,
    post,
    postForm,
    refreshSession,
    loadUnread,
    setBusy,
    toast,
    escapeHtml,
    isSessionError,
    isMaintenanceError,
    closeDrawer
  };
  window.proxyGet = get;
  window.proxyPost = post;
  window.showToast = toast;
  window.setBusy = setBusy;
  window.userSessionExpired = () => window.location.replace(loginUrl);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
