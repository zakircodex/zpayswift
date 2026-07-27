(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const allowedImages = new Set(['image/jpeg', 'image/png', 'image/webp']);
  let lastModalFocus = null;
  const profileModal = {
    open: false,
    kind: '',
    opener: null,
    historyOpen: false,
    closing: false,
    crop: null
  };
  const sectionPaths = {
    overviewSection: '/user/',
    servicesSection: '/user/services',
    transferSection: '/user/transfer',
    historySection: '/user/history',
    supportSection: '/user/support',
    profileSection: '/user/profile'
  };

  const app = {
    profile: null,
    profileLoading: false,
    transfer: {
      step: 1,
      recipient: null,
      preview: null,
      reference: '',
      submitting: false,
      resolving: false,
      amountChecking: false,
      favorites: [],
      favoritesLoaded: false,
      favoritesLoading: false,
      verifiedInput: '',
      holdFrame: 0,
      holdStartedAt: 0,
      modalOpen: false,
      modalBusy: false,
      modalHistoryOpen: false,
      modalClosing: false,
      successContext: null
    },
    support: {
      config: null,
      categories: [],
      tickets: [],
      ticket: null,
      messages: [],
      attachments: [],
      pollTimer: 0,
      createKey: '',
      replyKey: ''
    },
    notifications: {
      filter: 'ALL',
      items: [],
      loading: false,
      loaded: false,
      unreadCount: 0,
      returnSection: 'overviewSection',
      editing: false,
      selected: new Set(),
      activeDetail: null,
      detailOpener: null,
      detailHistory: false
    }
  };

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    })[char]);
  }

  function safeMessage(error, fallback) {
    const message = String(error && error.message ? error.message : fallback || 'Please try again.').trim();
    return message && message.length <= 220 ? message : String(fallback || 'Please try again.');
  }

  function transferStatusUnknown(error) {
    const code = String(error && error.code || '').toUpperCase();
    const status = Number(error && error.status || 0);
    return status === 0
      || status >= 500
      || [
        'REQUEST_FAILED',
        'TRANSFER_FAILED',
        'TRANSFER_PROCESSING',
        'TRANSFER_STORE_FAILED',
        'TRANSFER_INDEX_FAILED',
        'TRANSFER_RETRYABLE',
        'FINANCIAL_OPERATION_UNAVAILABLE'
      ].includes(code);
  }

  function profileSafeMessage(error, fallback) {
    const code = String(error && error.code || '').toUpperCase();
    const known = {
      WRONG_PASSWORD: 'Current password is incorrect.',
      WRONG_PIN: 'Current PIN is incorrect.',
      PASSWORD_MISMATCH: 'Confirm password does not match.',
      PIN_MISMATCH: 'Confirm PIN does not match.',
      INVALID_PASSWORD: 'Choose a stronger password.',
      INVALID_PIN: 'PIN must be exactly 4 digits.',
      IMAGE_TOO_LARGE: 'Profile photo must be 5 MB or smaller.',
      UNSUPPORTED_IMAGE: 'Choose a supported JPG, PNG or WebP image.',
      SESSION_EXPIRED: 'Your session expired. Please login again.'
    };
    if (known[code]) return known[code];
    const message = safeMessage(error, fallback);
    return /firebase|exception|stack trace|user_wallets|session[_ -]?token|csrf[_ -]?token|\/api\//i.test(message)
      ? String(fallback || 'Please try again.')
      : message;
  }

  function toast(message, type) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type || 'info');
    }
  }

  function setBusy(on, label) {
    if (typeof window.setBusy === 'function') {
      window.setBusy(on, label || 'Loading...');
    }
  }

  function csrf() {
    return String((window.userState && window.userState.csrf) || '');
  }

  function makeIdempotencyKey(prefix) {
    const random = window.crypto && typeof window.crypto.randomUUID === 'function'
      ? window.crypto.randomUUID()
      : String(Date.now()) + '-' + Math.random().toString(36).slice(2);
    return String(prefix || 'WEB') + '-' + random;
  }

  function formatMoney(value, currency) {
    const amount = Number(value || 0);
    const code = String(currency || 'BDT').toUpperCase();
    const prefix = code === 'MYR' ? 'RM' : code;
    return prefix + ' ' + (Number.isFinite(amount) ? amount.toFixed(2) : '0.00');
  }

  function formatDate(value) {
    let timestamp = Number(value || 0);
    if (!timestamp) return '-';
    if (timestamp < 100000000000) timestamp *= 1000;
    const date = new Date(timestamp);
    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString([], {
      year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit'
    });
  }

  function maskPhone(value) {
    const phone = String(value || '').trim();
    if (phone.length < 7) return phone || '-';
    return phone.slice(0, 4) + '***' + phone.slice(-3);
  }

  function maskEmail(value) {
    const email = String(value || '').trim();
    if (!email) return '-';
    const at = email.indexOf('@');
    if (at <= 0 || at === email.length - 1) {
      return email.length <= 20 ? email : email.slice(0, 17) + '...';
    }
    const local = email.slice(0, at);
    let domain = email.slice(at + 1);
    if (domain.length > 16) domain = domain.slice(0, 13) + '...';
    return local.slice(0, Math.min(5, local.length)) + '***@' + domain;
  }

  function profileCountryLabel(value) {
    const country = String(value || '').toUpperCase();
    if (country === 'MY') return 'Malaysia';
    if (country === 'BD') return 'Bangladesh';
    return country || '-';
  }

  function profileSessionStatus(value) {
    const status = String(value || '').trim();
    if (!status || status.toUpperCase() === 'ACTIVE') return 'Active';
    return status.replace(/[_-]+/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function profileVersionLabel() {
    return 'Version 1.0.0';
  }

  function initials(name) {
    const parts = String(name || 'Z P').trim().split(/\s+/).filter(Boolean);
    return ((parts[0] || 'Z')[0] + (parts[1] || parts[0] || 'P')[0]).toUpperCase();
  }

  function safeProfileImage(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.origin);
      return url.origin === window.location.origin ? url.href : '';
    } catch (_) {
      return '';
    }
  }

  async function get(action, params, label, options) {
    if (typeof window.proxyGet !== 'function') throw new Error('User API is unavailable.');
    return window.proxyGet(action, params || {}, label || 'Loading...', options || {});
  }

  async function post(action, payload, label, options) {
    if (typeof window.proxyPost !== 'function') throw new Error('User API is unavailable.');
    return window.proxyPost(action, payload || {}, label || 'Processing...', options || {});
  }

  function isSessionError(error) {
    const code = String(error && error.code || '').toUpperCase();
    return ['SESSION_EXPIRED', 'AUTH_REQUIRED', 'UNAUTHORIZED', 'USER_SESSION_EXPIRED'].includes(code)
      || Number(error && error.status || 0) === 401;
  }

  function isCsrfError(error) {
    const code = String(error && error.code || '').toUpperCase();
    const message = String(error && error.message || '').toLowerCase();
    return Number(error && error.status || 0) === 403
      && (code === 'FORBIDDEN' || code === 'CSRF_INVALID' || message.includes('csrf'));
  }

  async function refreshCsrfToken() {
    const data = await get('me', {}, 'Refreshing session...', { busy: false });
    if (data && data.csrf && window.userState) {
      window.userState.csrf = String(data.csrf);
    }
    return csrf();
  }

  async function postWithFreshCsrf(action, payload, label) {
    if (!csrf()) {
      await refreshCsrfToken();
    }
    try {
      return await post(action, payload || {}, label || 'Processing...', { busy: false });
    } catch (error) {
      if (!isCsrfError(error)) throw error;
      await refreshCsrfToken();
      return post(action, payload || {}, label || 'Processing...', { busy: false });
    }
  }

  function handleNotificationSessionExpired() {
    renderNotificationMessage('Session expired', 'Please login again to view your notifications.');
    if (typeof window.userSessionExpired === 'function') {
      window.userSessionExpired();
    }
  }

  async function postForm(action, formData, label) {
    setBusy(true, label || 'Uploading...');
    try {
      const send = async () => {
        const response = await fetch((window.USER_PROXY_URL || '/api/user/proxy.php') + '?action=' + encodeURIComponent(action), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
          body: formData
        });
        const responseText = await response.text();
        let json = null;
        try { json = JSON.parse(responseText); } catch (_) { json = null; }
        if (!response.ok || !json || !json.ok) {
          const error = new Error(String((json && json.message) || 'The request could not be completed.'));
          error.code = String((json && json.code) || 'REQUEST_FAILED');
          error.status = response.status;
          if (isSessionError(error) && typeof window.userSessionExpired === 'function') {
            window.userSessionExpired();
          }
          throw error;
        }
        return json.data || {};
      };
      if (!csrf()) await refreshCsrfToken();
      try {
        return await send();
      } catch (error) {
        if (!isCsrfError(error)) throw error;
        await refreshCsrfToken();
        return send();
      }
    } finally {
      setBusy(false);
    }
  }

  function setButtonBusy(button, busy, busyText) {
    if (!button) return;
    if (busy) {
      button.dataset.originalText = button.textContent;
      button.disabled = true;
      button.textContent = busyText || 'Please wait...';
    } else {
      button.disabled = false;
      button.textContent = button.dataset.originalText || button.textContent;
      delete button.dataset.originalText;
    }
  }

  function isProfileSectionActive() {
    return document.body.getAttribute('data-active-section') === 'profileSection';
  }

  function profileModalHistory(kind, replace) {
    if (!isProfileSectionActive() || !window.history) return;
    const state = Object.assign({}, window.history.state || {}, {
      zpayProfileModal: { kind: String(kind || 'form') }
    });
    if (replace && window.history.replaceState) {
      window.history.replaceState(state, '', window.location.href);
      return;
    }
    if (!profileModal.historyOpen && window.history.pushState) {
      window.history.pushState(state, '', window.location.href);
      profileModal.historyOpen = true;
    }
  }

  function profileModalVisualClose() {
    $('zpayActionModal')?.classList.remove('show', 'zpay-profile-modal');
    $('zpayActionBody')?.replaceChildren();
    $('zpayProfileCropModal')?.classList.remove('show');
  }

  function finishProfileModalClose() {
    const opener = profileModal.opener;
    releaseProfileCrop();
    profileModal.open = false;
    profileModal.kind = '';
    profileModal.historyOpen = false;
    profileModal.closing = false;
    profileModal.opener = null;
    profileModal.crop = null;
    profileModalVisualClose();
    if (typeof window.syncUserModalLock === 'function') window.syncUserModalLock();
    opener?.focus?.({ preventScroll: true });
  }

  function closeProfileModal(options) {
    const settings = options || {};
    if (!profileModal.open) return;
    if (settings.preserveHistory) {
      profileModalVisualClose();
      if (typeof window.syncUserModalLock === 'function') window.syncUserModalLock();
      return;
    }
    if (!settings.fromHistory && profileModal.historyOpen && !profileModal.closing && window.history?.back) {
      profileModal.closing = true;
      window.history.back();
      return;
    }
    finishProfileModalClose();
  }

  function ensureActionModal() {
    if ($('zpayActionModal')) return $('zpayActionModal');
    const modal = document.createElement('div');
    modal.id = 'zpayActionModal';
    modal.className = 'modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'zpayActionTitle');
    modal.innerHTML = '<div class="zpay-action-dialog"></div>';
    document.body.appendChild(modal);
    modal.addEventListener('click', (event) => {
      if (event.target === modal && !modal.classList.contains('zpay-profile-modal')) closeActionModal();
    });
    modal.addEventListener('keydown', trapModalFocus);
    return modal;
  }

  function trapFocusWithin(event, closeHandler) {
    if (event.key === 'Escape') {
      closeHandler();
      return;
    }
    if (event.key !== 'Tab') return;
    const nodes = Array.from(event.currentTarget.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),a[href]'));
    if (!nodes.length) return;
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function trapModalFocus(event) {
    trapFocusWithin(event, closeActionModal);
  }

  function openActionModal(builder, options) {
    const settings = options || {};
    const isProfile = settings.profile === true;
    const modal = ensureActionModal();
    if (isProfile && !profileModal.open) {
      profileModal.opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    } else if (!isProfile) {
      lastModalFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    }
    if (isProfile) {
      profileModal.open = true;
      profileModal.kind = String(settings.kind || 'form');
      profileModal.closing = false;
      profileModalHistory(profileModal.kind, profileModal.historyOpen);
    }
    ['zpay-profile-modal', 'zpay-transfer-modal', 'zpay-transfer-loading', 'zpay-transfer-result', 'zpay-transfer-success', 'zpay-transfer-error'].forEach((className) => {
      modal.classList.remove(className);
    });
    modal.classList.toggle('zpay-profile-modal', isProfile);
    String(settings.className || '').split(/\s+/).filter(Boolean).forEach((className) => modal.classList.add(className));
    if (isProfile && settings.kind === 'result') modal.setAttribute('aria-describedby', 'zpayActionCopy');
    else modal.removeAttribute('aria-describedby');
    const dialog = modal.querySelector('.zpay-action-dialog');
    dialog.replaceChildren();
    if (settings.closeButton !== false && !(isProfile && settings.kind === 'result')) {
      const close = document.createElement('button');
      close.id = 'zpayActionClose';
      close.className = 'modal-close';
      close.type = 'button';
      close.setAttribute('aria-label', 'Close');
      close.innerHTML = '&times;';
      close.addEventListener('click', () => closeActionModal());
      dialog.appendChild(close);
    }
    const body = document.createElement('div');
    body.id = 'zpayActionBody';
    dialog.appendChild(body);
    builder(body);
    modal.classList.add('show');
    if (typeof window.syncUserModalLock === 'function') window.syncUserModalLock();
    setTimeout(() => body.querySelector('input,button,textarea,select')?.focus(), 0);
  }

  function closeActionModal(options) {
    if ($('zpayActionModal')?.classList.contains('zpay-profile-modal') && profileModal.open) {
      closeProfileModal(options);
      return;
    }
    if ($('zpayActionModal')?.classList.contains('zpay-transfer-modal') && app.transfer.modalOpen) {
      closeTransferModal(options);
      return;
    }
    $('zpayActionModal')?.classList.remove('show');
    if (typeof window.syncUserModalLock === 'function') window.syncUserModalLock();
    lastModalFocus?.focus();
    lastModalFocus = null;
  }

  function showResult(title, message, kind, actions) {
    openActionModal((body) => {
      const icon = document.createElement('div');
      icon.className = 'zpay-action-icon';
      icon.textContent = kind === 'error' ? '!' : 'OK';
      if (kind === 'error') icon.style.borderColor = icon.style.color = 'var(--z-error)';
      const heading = document.createElement('h3');
      heading.id = 'zpayActionTitle';
      heading.className = 'modal-title';
      heading.textContent = title;
      const copy = document.createElement('p');
      copy.className = 'zpay-action-copy';
      copy.textContent = message;
      body.append(icon, heading, copy);
      const wrap = document.createElement('div');
      wrap.className = 'feature-actions';
      (actions || [{ label: 'Done', action: closeActionModal }]).forEach((item, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = index === 0 ? 'android-primary-button' : 'android-secondary-button';
        button.textContent = item.label;
        button.addEventListener('click', item.action);
        wrap.appendChild(button);
      });
      body.appendChild(wrap);
    });
  }

  function showProfileResult(title, message, kind) {
    openActionModal((body) => {
      const icon = document.createElement('div');
      icon.className = 'zpay-action-icon';
      icon.textContent = kind === 'error' ? '!' : 'OK';
      if (kind === 'error') icon.style.borderColor = icon.style.color = 'var(--z-error)';
      const heading = document.createElement('h3');
      heading.id = 'zpayActionTitle';
      heading.className = 'modal-title';
      heading.textContent = title;
      const copy = document.createElement('p');
      copy.id = 'zpayActionCopy';
      copy.className = 'zpay-action-copy';
      copy.textContent = message;
      body.append(icon, heading, copy);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'android-primary-button';
      button.textContent = kind === 'error' ? 'OK' : 'Done';
      button.addEventListener('click', () => closeProfileModal());
      const wrap = document.createElement('div');
      wrap.className = 'zpay-profile-result-actions';
      wrap.appendChild(button);
      body.appendChild(wrap);
    }, { profile: true, kind: 'result' });
  }

  function openDashboardUtility(action) {
    if (action === 'shopping') {
      showResult('Shopping', 'Shopping is coming soon.', 'success');
      return;
    }
    if (action === 'info') {
      showResult(
        'Z-Pay Swift',
        'A fast, secure and simple way to manage wallet services, payments and requests.',
        'success'
      );
    }
  }

  function openFormModal(title, fields, submitLabel, submitHandler) {
    openActionModal((body) => {
      const heading = document.createElement('h3');
      heading.id = 'zpayActionTitle';
      heading.className = 'modal-title';
      heading.textContent = title;
      const form = document.createElement('form');
      form.className = 'zpay-action-form';
      form.noValidate = true;
      fields.forEach((field) => {
        const label = document.createElement('label');
        label.textContent = field.label;
        const input = document.createElement('input');
        input.name = field.name;
        input.type = field.type || 'text';
        input.value = field.value || '';
        input.placeholder = field.placeholder || '';
        if (field.autocomplete) input.autocomplete = field.autocomplete;
        if (field.maxLength) input.maxLength = field.maxLength;
        if (field.inputMode) input.inputMode = field.inputMode;
        label.appendChild(input);
        form.appendChild(label);
      });
      const submit = document.createElement('button');
      submit.type = 'submit';
      submit.className = 'android-primary-button';
      submit.textContent = submitLabel;
      form.appendChild(submit);
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const values = Object.fromEntries(new FormData(form).entries());
        setButtonBusy(submit, true, 'Saving...');
        try {
          await submitHandler(values);
        } catch (error) {
          closeActionModal({ preserveHistory: true });
          showProfileResult('Update Not Completed', profileSafeMessage(error, 'Unable to save changes.'), 'error');
        }
      });
      body.append(heading, form);
    }, { profile: true, kind: 'form' });
  }

  function mergeProfile(data) {
    app.profile = Object.assign({}, app.profile || {}, data || {});
    if (window.userState) {
      window.userState.me = Object.assign({}, window.userState.me || {}, app.profile);
    }
    renderProfile();
    if (typeof window.renderUserDrawerProfile === 'function') {
      window.renderUserDrawerProfile();
    }
  }

  async function loadProfile(force) {
    if ((app.profile && !force) || app.profileLoading) {
      renderProfile();
      return;
    }
    app.profileLoading = true;
    try {
      mergeProfile(await get('profile_get', {}, 'Loading profile...'));
    } catch (error) {
      toast(safeMessage(error, 'Profile could not be loaded.'), 'error');
    } finally {
      app.profileLoading = false;
    }
  }

  function renderProfile() {
    const profile = app.profile || (window.userState && window.userState.me) || {};
    const name = String(profile.name || 'Z-Pay User');
    const status = String(profile.account_status || profile.status || 'ACTIVE').toUpperCase();
    const currency = String(profile.wallet_currency || 'BDT').toUpperCase();
    const pricing = String(profile.pricing_country || profile.market_country || '').toUpperCase();
    const displayCountry = profileCountryLabel(pricing || (currency === 'MYR' ? 'MY' : 'BD'));
    const image = safeProfileImage(profile.profile_photo_url);
    if ($('profileName')) $('profileName').textContent = name;
    if ($('profilePhone')) $('profilePhone').textContent = maskPhone(profile.phone);
    if ($('profileEmail')) $('profileEmail').textContent = maskEmail(profile.email);
    if ($('profileRoleBadge')) $('profileRoleBadge').textContent = String(profile.role || 'USER').toUpperCase();
    if ($('profileStatusBadge')) $('profileStatusBadge').textContent = status;
    if ($('profileCountryCurrency')) $('profileCountryCurrency').textContent = displayCountry + ' | ' + currency;
    if ($('profileUid')) $('profileUid').textContent = profile.uid || '-';
    if ($('profilePhoneCountry')) $('profilePhoneCountry').textContent = String(profile.phone_country || '-').toUpperCase();
    if ($('profilePricingCountry')) $('profilePricingCountry').textContent = pricing || '-';
    if ($('profileWalletCurrency')) $('profileWalletCurrency').textContent = currency;
    if ($('profileCreatedAt')) $('profileCreatedAt').textContent = formatDate(profile.created_at);
    if ($('profileLastLogin')) $('profileLastLogin').textContent = formatDate(profile.last_login_at);
    if ($('profileAppVersion')) $('profileAppVersion').textContent = profileVersionLabel();
    if ($('profileSessionStatus')) $('profileSessionStatus').textContent = profileSessionStatus(profile.session_status || profile.sessionStatus || 'Active');
    if ($('profileAvatarInitials')) $('profileAvatarInitials').textContent = initials(name);
    if ($('profileAvatarImage')) {
      $('profileAvatarImage').classList.toggle('hidden', !image);
      if (image) $('profileAvatarImage').src = image;
      else $('profileAvatarImage').removeAttribute('src');
    }
  }

  function editProfile() {
    const profile = app.profile || {};
    openFormModal('Edit Profile', [
      { name: 'name', label: 'Full Name', value: String(profile.name || ''), autocomplete: 'name', maxLength: 80 },
      { name: 'email', label: 'Email', value: String(profile.email || ''), type: 'email', autocomplete: 'email', maxLength: 120 }
    ], 'Save Profile', async (values) => {
      const data = await post('profile_update', { name: values.name, email: values.email }, 'Updating profile...');
      mergeProfile(data);
      closeActionModal({ preserveHistory: true });
      showProfileResult('Profile Updated', 'Your profile details were updated successfully.', 'success');
    });
  }

  function changePassword() {
    openFormModal('Change Password', [
      { name: 'current_password', label: 'Current Password', type: 'password', autocomplete: 'current-password' },
      { name: 'new_password', label: 'New Password', type: 'password', autocomplete: 'new-password' },
      { name: 'confirm_password', label: 'Confirm New Password', type: 'password', autocomplete: 'new-password' }
    ], 'Update Password', async (values) => {
      await post('profile_change_password', values, 'Updating password...');
      closeActionModal({ preserveHistory: true });
      showProfileResult('Password Updated', 'Your login password was updated successfully.', 'success');
    });
  }

  function changePin() {
    openFormModal('Change Transaction PIN', [
      { name: 'current_pin', label: 'Current PIN', type: 'password', inputMode: 'numeric', maxLength: 4 },
      { name: 'new_pin', label: 'New 4-digit PIN', type: 'password', inputMode: 'numeric', maxLength: 4 },
      { name: 'confirm_pin', label: 'Confirm New PIN', type: 'password', inputMode: 'numeric', maxLength: 4 }
    ], 'Update PIN', async (values) => {
      await post('profile_change_pin', values, 'Updating PIN...');
      closeActionModal({ preserveHistory: true });
      showProfileResult('PIN Updated', 'Your transaction PIN was updated successfully.', 'success');
    });
  }

  function validateProfilePhoto(file) {
    if (!file) return;
    if (!allowedImages.has(String(file.type || '').toLowerCase())) {
      throw new Error('Choose a JPG, PNG or WebP image.');
    }
    if (file.size <= 0 || file.size > 5 * 1024 * 1024) {
      throw new Error('Profile photo must be 5 MB or smaller.');
    }
  }

  function ensureProfileCropModal() {
    if ($('zpayProfileCropModal')) return $('zpayProfileCropModal');
    const modal = document.createElement('div');
    modal.id = 'zpayProfileCropModal';
    modal.className = 'modal zpay-profile-modal profile-crop-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'profileCropTitle');
    modal.innerHTML = '<div class="profile-crop-dialog">' +
      '<button id="profileCropClose" class="modal-close" type="button" aria-label="Close photo crop">&times;</button>' +
      '<h3 id="profileCropTitle" class="modal-title">Crop Profile Photo</h3>' +
      '<p class="modal-sub">Drag inside the circle to reposition. Pinch or scroll to zoom.</p>' +
      '<div class="profile-crop-stage"><canvas id="profileCropCanvas" width="640" height="640" tabindex="0" aria-label="Profile photo crop area"></canvas></div>' +
      '<div class="profile-crop-controls"><button id="profileCropCancel" class="android-secondary-button" type="button">Cancel</button><button id="profileCropSave" class="android-primary-button" type="button">Use Photo</button></div>' +
      '</div>';
    document.body.appendChild(modal);
    const canvas = $('profileCropCanvas');
    const draw = () => drawProfileCrop();
    const pointFor = (event) => {
      const rect = canvas.getBoundingClientRect();
      return {
        x: (event.clientX - rect.left) * (canvas.width / rect.width),
        y: (event.clientY - rect.top) * (canvas.height / rect.height)
      };
    };
    const distanceBetween = (first, second) => Math.hypot(second.x - first.x, second.y - first.y);
    canvas?.addEventListener('pointerdown', (event) => {
      const crop = profileModal.crop;
      if (!crop) return;
      event.preventDefault();
      const point = pointFor(event);
      crop.pointers = crop.pointers || new Map();
      crop.pointers.set(event.pointerId, point);
      if (crop.pointers.size === 1) {
        crop.dragging = true;
        crop.lastX = point.x;
        crop.lastY = point.y;
      } else if (crop.pointers.size === 2) {
        const points = Array.from(crop.pointers.values());
        crop.dragging = false;
        crop.pinchDistance = distanceBetween(points[0], points[1]);
        crop.pinchZoom = crop.zoom;
      }
      canvas.setPointerCapture?.(event.pointerId);
    });
    canvas?.addEventListener('pointermove', (event) => {
      const crop = profileModal.crop;
      if (!crop || !crop.pointers?.has(event.pointerId)) return;
      event.preventDefault();
      const point = pointFor(event);
      crop.pointers.set(event.pointerId, point);
      if (crop.pointers.size >= 2 && crop.pinchDistance) {
        const points = Array.from(crop.pointers.values());
        const pinchDistance = distanceBetween(points[0], points[1]);
        crop.zoom = Math.max(1, Math.min(3, crop.pinchZoom * (pinchDistance / crop.pinchDistance)));
      } else if (crop.dragging) {
        crop.offsetX += point.x - crop.lastX;
        crop.offsetY += point.y - crop.lastY;
        crop.lastX = point.x;
        crop.lastY = point.y;
      }
      draw();
    });
    ['pointerup', 'pointercancel'].forEach((name) => canvas?.addEventListener(name, (event) => {
      const crop = profileModal.crop;
      if (!crop) return;
      crop.pointers?.delete(event.pointerId);
      crop.pinchDistance = 0;
      crop.dragging = false;
    }));
    canvas?.addEventListener('pointerleave', (event) => {
      if (profileModal.crop && !(canvas.hasPointerCapture?.(event.pointerId))) profileModal.crop.dragging = false;
    });
    canvas?.addEventListener('wheel', (event) => {
      if (!profileModal.crop) return;
      event.preventDefault();
      profileModal.crop.zoom = Math.max(1, Math.min(3, profileModal.crop.zoom + (event.deltaY < 0 ? 0.08 : -0.08)));
      draw();
    }, { passive: false });
    $('profileCropCancel')?.addEventListener('click', () => closeProfileModal());
    $('profileCropClose')?.addEventListener('click', () => closeProfileModal());
    $('profileCropSave')?.addEventListener('click', saveProfileCrop);
    modal.addEventListener('click', (event) => {
      if (event.target === modal) event.preventDefault();
    });
    modal.addEventListener('keydown', (event) => trapFocusWithin(event, closeProfileModal));
    return modal;
  }

  function drawProfileCrop() {
    const crop = profileModal.crop;
    const canvas = $('profileCropCanvas');
    if (!crop || !canvas || !crop.image) return;
    const context = canvas.getContext('2d');
    if (!context) return;
    const size = 640;
    const imageWidth = crop.image.width || crop.image.naturalWidth;
    const imageHeight = crop.image.height || crop.image.naturalHeight;
    const scale = Math.max(size / imageWidth, size / imageHeight) * crop.zoom;
    const width = imageWidth * scale;
    const height = imageHeight * scale;
    const x = (size - width) / 2 + crop.offsetX;
    const y = (size - height) / 2 + crop.offsetY;
    context.clearRect(0, 0, size, size);
    context.fillStyle = '#061426';
    context.fillRect(0, 0, size, size);
    context.drawImage(crop.image, x, y, width, height);
    context.save();
    context.fillStyle = 'rgba(2, 9, 18, 0.48)';
    context.fillRect(0, 0, size, size);
    context.restore();
    context.save();
    context.beginPath();
    context.arc(size / 2, size / 2, size / 2 - 8, 0, Math.PI * 2);
    context.clip();
    context.drawImage(crop.image, x, y, width, height);
    context.restore();
    context.beginPath();
    context.arc(size / 2, size / 2, size / 2 - 9, 0, Math.PI * 2);
    context.lineWidth = 4;
    context.strokeStyle = '#32e686';
    context.stroke();
  }

  function releaseProfileCrop() {
    const crop = profileModal.crop;
    if (!crop) return;
    if (crop.image && typeof crop.image.close === 'function') crop.image.close();
    if (crop.objectUrl) URL.revokeObjectURL(crop.objectUrl);
  }

  async function openProfileCrop(file) {
    if (!file) return;
    let objectUrl = '';
    try {
      validateProfilePhoto(file);
      objectUrl = URL.createObjectURL(file);
      let image = null;
      if (typeof window.createImageBitmap === 'function') {
        try {
          image = await window.createImageBitmap(file, { imageOrientation: 'from-image' });
        } catch (_) {
          image = null;
        }
      }
      if (!image) {
        image = await new Promise((resolve, reject) => {
          const element = new Image();
          element.onload = () => resolve(element);
          element.onerror = () => reject(new Error('The selected image could not be read.'));
          element.src = objectUrl;
        });
      }
      const width = image.width || image.naturalWidth || 0;
      const height = image.height || image.naturalHeight || 0;
      if (width < 80 || height < 80 || width > 10000 || height > 10000) {
        if (typeof image.close === 'function') image.close();
        URL.revokeObjectURL(objectUrl);
        throw new Error('Choose a valid image between 80 and 10000 pixels.');
      }
      releaseProfileCrop();
      profileModal.crop = { file, image, objectUrl, zoom: 1, offsetX: 0, offsetY: 0, dragging: false, pointers: new Map(), pinchDistance: 0, pinchZoom: 1 };
      ensureProfileCropModal();
      profileModal.open = true;
      profileModal.kind = 'crop';
      profileModal.opener = profileModal.opener || (document.activeElement instanceof HTMLElement ? document.activeElement : null);
      profileModal.closing = false;
      profileModalHistory('crop', false);
      $('zpayProfileCropModal')?.classList.add('show');
      if (typeof window.syncUserModalLock === 'function') window.syncUserModalLock();
      drawProfileCrop();
      setTimeout(() => $('profileCropSave')?.focus(), 0);
    } catch (error) {
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      toast(safeMessage(error, 'The selected image could not be opened.'), 'error');
      if ($('profilePhotoInput')) $('profilePhotoInput').value = '';
    }
  }

  async function saveProfileCrop() {
    const crop = profileModal.crop;
    const button = $('profileCropSave');
    const canvas = $('profileCropCanvas');
    if (!crop || !canvas || crop.uploading) return;
    crop.uploading = true;
    setButtonBusy(button, true, 'Uploading...');
    try {
      const output = document.createElement('canvas');
      output.width = 512;
      output.height = 512;
      const context = output.getContext('2d');
      if (!context) throw new Error('The cropped image could not be prepared.');
      context.fillStyle = '#061426';
      context.fillRect(0, 0, output.width, output.height);
      const imageWidth = crop.image.width || crop.image.naturalWidth;
      const imageHeight = crop.image.height || crop.image.naturalHeight;
      const scale = Math.max(640 / imageWidth, 640 / imageHeight) * crop.zoom;
      const width = imageWidth * scale;
      const height = imageHeight * scale;
      const x = (640 - width) / 2 + crop.offsetX;
      const y = (640 - height) / 2 + crop.offsetY;
      context.drawImage(crop.image, x * 0.8, y * 0.8, width * 0.8, height * 0.8);
      const blob = await new Promise((resolve) => output.toBlob(resolve, 'image/jpeg', 0.9));
      if (!blob) throw new Error('The cropped image could not be prepared.');
      const data = new FormData();
      data.append('profile_photo', blob, 'profile-cropped.jpg');
      const response = await postForm('profile_photo_upload', data, 'Uploading profile photo...');
      closeProfileModal({ preserveHistory: true });
      mergeProfile(response);
      showProfileResult('Profile Photo Updated', 'Your profile photo was updated successfully.', 'success');
    } catch (error) {
      closeProfileModal({ preserveHistory: true });
      showProfileResult('Photo Not Updated', profileSafeMessage(error, 'Profile photo could not be updated.'), 'error');
    } finally {
      crop.uploading = false;
      setButtonBusy(button, false);
      if ($('profilePhotoInput')) $('profilePhotoInput').value = '';
    }
  }

  function uploadProfilePhoto(file) {
    openProfileCrop(file);
  }


  function bindProfilePage() {
    const openPhoto = (event) => { profileModal.opener = event.currentTarget; $("profilePhotoInput")?.click(); };
    $("profileEditButton")?.addEventListener("click", editProfile);
    $("profileAvatarButton")?.addEventListener("click", openPhoto);
    $("profilePhotoEditButton")?.addEventListener("click", openPhoto);
    $("profilePhotoInput")?.addEventListener("change", (event) => uploadProfilePhoto(event.target.files && event.target.files[0]));
    $("profileChangePasswordBtn")?.addEventListener("click", changePassword);
    $("profileChangePinBtn")?.addEventListener("click", changePin);
    $("profileCopyUidBtn")?.addEventListener("click", async () => {
      const uid = String(app.profile && app.profile.uid || "");
      if (!uid) return;
      try { await navigator.clipboard.writeText(uid); toast("Account ID copied.", "ok"); }
      catch (_) { toast("Account ID could not be copied.", "error"); }
    });
    $("profileLogoutBtn")?.addEventListener("click", () => $("drawerLogoutBtn")?.click());
    window.addEventListener("popstate", () => { if (profileModal.open) closeProfileModal({ fromHistory: true }); });
    document.addEventListener("keydown", (event) => { if (event.key === "Escape" && profileModal.open) closeProfileModal(); });
  }

  async function initProfilePage() {
    await window.UserShell.ready;
    bindProfilePage();
    await loadProfile(false);
    if (window.location.hash === "#security") document.getElementById("security")?.scrollIntoView();
  }

  initProfilePage().catch((error) => toast(profileSafeMessage(error, "Profile could not be loaded."), "error"));
})();
