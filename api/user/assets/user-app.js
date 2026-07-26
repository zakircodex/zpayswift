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

  function transferDigits(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  function transferRecipientPhone(recipient) {
    return String(
      (recipient && (recipient.receiver_phone || recipient.phone || recipient.account || recipient.recipient_phone)) || ''
    ).trim();
  }

  function transferRecipientName(recipient) {
    return String(
      (recipient && (recipient.receiver_name || recipient.name || recipient.receiver_name_masked)) || 'Z-Pay User'
    ).trim();
  }

  function transferStep(step, options) {
    const next = Math.max(1, Math.min(4, Number(step || 1)));
    const previous = app.transfer.step;
    app.transfer.step = next;
    if (next !== 3 && $('transferPinInput')) $('transferPinInput').value = '';
    if (next < 4) {
      app.transfer.holdStartedAt = 0;
      cancelHold();
    }
    document.querySelectorAll('.transfer-step').forEach((node, index) => node.classList.toggle('active', index + 1 === next));
    for (let index = 1; index <= 4; index += 1) {
      $('transferPill' + index)?.classList.toggle('active', index === next);
      $('transferPill' + index)?.classList.toggle('complete', index < next);
    }
    if (!(options && options.fromHistory) && next > 1 && next !== previous && window.history && window.history.pushState) {
      window.history.pushState(Object.assign({}, window.history.state || {}, {
        zpayUserApp: { view: 'transfer', step: next }
      }), '', sectionPaths.transferSection);
    }
    const focusId = ['transferReceiverInput', 'transferAmountInput', 'transferPinInput'][next - 1];
    if (focusId) {
      setTimeout(() => $(focusId)?.focus(), 0);
    } else {
      document.querySelector('#transferSection .transfer-card')?.scrollTo?.({ top: 0, behavior: 'auto' });
    }
  }

  function syncTransferFavoriteAdd() {
    const button = $('transferFavoriteAddBtn');
    if (!button) return;
    const recipient = app.transfer.recipient;
    if (!recipient) {
      button.classList.add('hidden');
      button.disabled = true;
      return;
    }

    const recipientDigits = transferDigits(transferRecipientPhone(recipient) || app.transfer.verifiedInput);
    const duplicate = app.transfer.favorites.some((item) => transferDigits(item.phone || item.receiver_phone) === recipientDigits);
    button.classList.toggle('hidden', duplicate);
    button.disabled = duplicate;
    button.textContent = duplicate ? 'Saved to Favourite' : 'Add to Favourite';
  }

  function isTransferSectionActive() {
    return document.body.getAttribute('data-active-section') === 'transferSection';
  }

  function transferModalHistory(kind) {
    if (!isTransferSectionActive() || !window.history?.pushState || app.transfer.modalHistoryOpen) return;
    window.history.pushState(Object.assign({}, window.history.state || {}, {
      zpayUserApp: { view: 'transfer', step: app.transfer.step },
      zpayTransferModal: { kind: String(kind || 'modal') }
    }), '', sectionPaths.transferSection);
    app.transfer.modalHistoryOpen = true;
  }

  function finishTransferModalClose(options) {
    const settings = options || {};
    const modal = $('zpayActionModal');
    app.transfer.modalOpen = false;
    app.transfer.modalBusy = false;
    app.transfer.modalClosing = false;
    if (settings.replaceHistory && app.transfer.modalHistoryOpen && window.history?.replaceState) {
      window.history.replaceState(Object.assign({}, window.history.state || {}, {
        zpayUserApp: { view: 'transfer', step: app.transfer.step },
        zpayTransferModal: null
      }), '', sectionPaths.transferSection);
    }
    app.transfer.modalHistoryOpen = false;
    modal?.classList.remove('show', 'zpay-transfer-modal', 'zpay-transfer-loading', 'zpay-transfer-result', 'zpay-transfer-success', 'zpay-transfer-error');
    $('zpayActionBody')?.replaceChildren();
    if (typeof window.syncUserModalLock === 'function') window.syncUserModalLock();
    lastModalFocus?.focus?.({ preventScroll: true });
    lastModalFocus = null;
  }

  function closeTransferModal(options) {
    const settings = options || {};
    if (!app.transfer.modalOpen || app.transfer.modalBusy) return;
    if (!settings.fromHistory && app.transfer.modalHistoryOpen && !app.transfer.modalClosing && window.history?.back) {
      app.transfer.modalClosing = true;
      window.history.back();
      return;
    }
    finishTransferModalClose();
  }

  function openTransferLoading(message) {
    app.transfer.modalOpen = true;
    app.transfer.modalBusy = true;
    app.transfer.modalClosing = false;
    transferModalHistory('loading');
    openActionModal((body) => {
      const spinner = document.createElement('div');
      spinner.className = 'zpay-transfer-spinner';
      const heading = document.createElement('h3');
      heading.id = 'zpayActionTitle';
      heading.className = 'modal-title';
      heading.textContent = message || 'Please wait...';
      const copy = document.createElement('p');
      copy.className = 'zpay-action-copy';
      copy.textContent = 'Z-Pay Swift is securely processing your request.';
      body.append(spinner, heading, copy);
    }, { closeButton: false, className: 'zpay-transfer-modal zpay-transfer-loading' });
  }

  function openTransferError(title, message) {
    app.transfer.modalOpen = true;
    app.transfer.modalBusy = false;
    app.transfer.modalClosing = false;
    transferModalHistory('error');
    openActionModal((body) => {
      const icon = document.createElement('div');
      icon.className = 'zpay-action-icon';
      icon.textContent = '!';
      icon.style.borderColor = icon.style.color = 'var(--z-error)';
      const heading = document.createElement('h3');
      heading.id = 'zpayActionTitle';
      heading.className = 'modal-title';
      heading.textContent = title || 'Transfer Error';
      const copy = document.createElement('p');
      copy.className = 'zpay-action-copy';
      copy.textContent = safeMessage({ message }, 'Transfer could not be processed.');
      const actions = document.createElement('div');
      actions.className = 'zpay-transfer-result-actions';
      const ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'android-primary-button';
      ok.textContent = 'OK';
      ok.addEventListener('click', () => closeTransferModal());
      actions.appendChild(ok);
      body.append(icon, heading, copy, actions);
    }, { closeButton: false, className: 'zpay-transfer-modal zpay-transfer-result zpay-transfer-error' });
  }

  function isTransferFavoriteSaved(recipientOrContext) {
    const phone = transferDigits(transferRecipientPhone(recipientOrContext) || recipientOrContext?.phone || app.transfer.verifiedInput);
    if (!phone) return false;
    return app.transfer.favorites.some((item) => transferDigits(item.phone || item.receiver_phone) === phone);
  }

  async function addTransferFavoriteFromContext(context, button) {
    const phone = transferRecipientPhone(context) || context?.phone || '';
    if (!phone) return;
    setButtonBusy(button, true, 'Saving...');
    try {
      await postWithFreshCsrf('transfer_favorite_add', {
        recipient_phone: phone,
        name: context.receiver_name || context.name || ''
      }, 'Saving favorite...');
      app.transfer.favoritesLoaded = false;
      await loadTransferFavorites(true);
      button.textContent = 'Saved to Favourite';
      button.disabled = true;
      toast('Favorite receiver saved.', 'success');
    } catch (error) {
      setButtonBusy(button, false);
      openTransferError('Favourite Not Saved', safeMessage(error, 'Transfer completed, but the receiver could not be saved as favourite.'));
    }
  }

  function showTransferSuccess(context) {
    const details = context || {};
    app.transfer.successContext = details;
    app.transfer.modalOpen = true;
    app.transfer.modalBusy = false;
    app.transfer.modalClosing = false;
    transferModalHistory('success');
    openActionModal((body) => {
      const icon = document.createElement('div');
      icon.className = 'zpay-action-icon';
      icon.textContent = 'OK';
      const heading = document.createElement('h3');
      heading.id = 'zpayActionTitle';
      heading.className = 'modal-title';
      heading.textContent = 'Transfer Successful';
      const rows = document.createElement('div');
      rows.className = 'zpay-transfer-result-rows';
      [
        ['Receiver', details.receiver_name || details.name || 'Z-Pay User'],
        ['Account', details.receiver_phone_masked || maskPhone(details.receiver_phone || details.receiver_account || '')],
        ['Amount', details.amount_text || formatMoney(details.amount, details.wallet_currency || details.currency)],
        ['Transfer ID', details.transfer_id || details.request_id || '-']
      ].forEach((row) => {
        const item = document.createElement('div');
        item.className = 'zpay-transfer-result-row';
        item.innerHTML = '<span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1]) + '</strong>';
        rows.appendChild(item);
      });
      const actions = document.createElement('div');
      actions.className = 'zpay-transfer-result-actions';
      if (!isTransferFavoriteSaved(details)) {
        const favorite = document.createElement('button');
        favorite.type = 'button';
        favorite.className = 'android-secondary-button';
        favorite.textContent = 'Add to Favourite';
        favorite.addEventListener('click', () => addTransferFavoriteFromContext(details, favorite));
        actions.appendChild(favorite);
      }
      const history = document.createElement('button');
      history.type = 'button';
      history.className = 'android-secondary-button';
      history.textContent = 'View History / Track';
      history.addEventListener('click', () => {
        finishTransferModalClose({ replaceHistory: true });
        window.openSection?.('historySection');
      });
      const done = document.createElement('button');
      done.type = 'button';
      done.className = 'android-primary-button';
      done.textContent = 'Done';
      done.addEventListener('click', () => finishTransferModalClose({ replaceHistory: true }));
      actions.append(history, done);
      body.append(icon, heading, rows, actions);
    }, { closeButton: false, className: 'zpay-transfer-modal zpay-transfer-result zpay-transfer-success' });
  }

  function renderRecipientCard() {
    const recipient = app.transfer.recipient || {};
    const phone = transferRecipientPhone(recipient);
    if ($('transferReceiverCard')) {
      const currencyText = String(recipient.wallet_currency || recipient.sender_wallet_currency || (window.userState && window.userState.walletSummary && window.userState.walletSummary.wallet_currency) || 'BDT').toUpperCase();
      $('transferReceiverCard').innerHTML =
        '<div class="transfer-verified-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9.2 16.6-4.1-4.1 1.4-1.4 2.7 2.7 8.3-8.3 1.4 1.4-9.7 9.7Z"/></svg></div>' +
        '<div class="transfer-verified-copy"><strong>' + escapeHtml(transferRecipientName(recipient)) + '</strong>' +
        '<p>' + escapeHtml(recipient.receiver_phone_masked || maskPhone(phone)) + ' - ' + escapeHtml(currencyText) + '</p></div>';
    }
    const currency = String(recipient.wallet_currency || recipient.sender_wallet_currency || (window.userState && window.userState.walletSummary && window.userState.walletSummary.wallet_currency) || 'BDT').toUpperCase();
    if ($('transferCurrencyPrefix')) $('transferCurrencyPrefix').textContent = currency === 'MYR' ? 'RM' : currency;
    syncTransferFavoriteAdd();
  }

  function renderTransferFavorites(loading) {
    const list = $('transferFavoriteList');
    if (!list) return;

    if (loading) {
      list.innerHTML = '<div class="transfer-empty-card">Loading favorite accounts...</div>';
      return;
    }

    const favorites = Array.isArray(app.transfer.favorites) ? app.transfer.favorites : [];
    if (!favorites.length) {
      list.innerHTML = '<div class="transfer-empty-card">No favorite accounts yet.</div>';
      return;
    }

    list.innerHTML = favorites.map((favorite) => {
      const id = escapeHtml(favorite.favorite_id || '');
      const phone = escapeHtml(favorite.phone || favorite.receiver_phone || '');
      const title = escapeHtml(favorite.name || favorite.receiver_name || 'Z-Pay User');
      const subtitle = escapeHtml((favorite.phone || favorite.receiver_phone || favorite.phone_masked || '-') + ' - Z-Pay');
      return '<article class="transfer-favorite-item" tabindex="0" role="button" data-favorite-phone="' + phone + '">' +
        '<div class="transfer-favorite-avatar" aria-hidden="true">Z</div>' +
        '<div class="transfer-favorite-copy"><strong>' + title + '</strong><small>' + subtitle + '</small></div>' +
        '<button class="transfer-favorite-remove" type="button" data-favorite-id="' + id + '" aria-label="Remove favorite receiver">Remove</button>' +
        '</article>';
    }).join('');

    list.querySelectorAll('.transfer-favorite-item').forEach((item) => {
      item.addEventListener('click', () => selectTransferFavorite(item.dataset.favoritePhone || ''));
      item.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          selectTransferFavorite(item.dataset.favoritePhone || '');
        }
      });
    });

    list.querySelectorAll('.transfer-favorite-remove').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.stopPropagation();
        removeTransferFavorite(button.dataset.favoriteId || '');
      });
    });
  }

  async function loadTransferFavorites(force) {
    if (app.transfer.favoritesLoaded && !force) {
      renderTransferFavorites(false);
      syncTransferFavoriteAdd();
      return;
    }
    if (app.transfer.favoritesLoading) return;

    app.transfer.favoritesLoading = true;
    renderTransferFavorites(true);
    try {
      const data = await get('transfer_favorites', { limit: 10 }, 'Loading favorite accounts...', { busy: false });
      app.transfer.favorites = Array.isArray(data.favorites) ? data.favorites : [];
      app.transfer.favoritesLoaded = true;
      renderTransferFavorites(false);
      syncTransferFavoriteAdd();
    } catch (error) {
      app.transfer.favoritesLoaded = false;
      if ($('transferFavoriteList')) {
        $('transferFavoriteList').innerHTML = '<div class="transfer-empty-card error">Favorite accounts could not be loaded.</div>';
      }
    } finally {
      app.transfer.favoritesLoading = false;
    }
  }

  function invalidateTransferReceiver() {
    if (!app.transfer.recipient) return;
    const current = transferDigits($('transferReceiverInput')?.value || '');
    if (current === transferDigits(app.transfer.verifiedInput)) return;

    app.transfer.recipient = null;
    app.transfer.preview = null;
    app.transfer.reference = '';
    app.transfer.verifiedInput = '';
    syncTransferFavoriteAdd();
  }

  async function selectTransferFavorite(phone) {
    const input = $('transferReceiverInput');
    if (!input || !phone) return;
    input.value = phone;
    app.transfer.recipient = null;
    app.transfer.preview = null;
    app.transfer.verifiedInput = '';
    await resolveRecipient();
  }

  async function addTransferFavorite() {
    const recipient = app.transfer.recipient || {};
    const phone = transferRecipientPhone(recipient) || app.transfer.verifiedInput;
    if (!phone) {
      toast('Verify a receiver first.', 'error');
      return;
    }

    const button = $('transferFavoriteAddBtn');
    setButtonBusy(button, true, 'Saving...');
    try {
      await postWithFreshCsrf('transfer_favorite_add', {
        recipient_phone: phone,
        name: recipient.receiver_name || recipient.receiver_name_masked || ''
      }, 'Saving favorite...');
      toast('Favorite receiver saved.', 'success');
      app.transfer.favoritesLoaded = false;
      await loadTransferFavorites(true);
      syncTransferFavoriteAdd();
    } catch (error) {
      toast(safeMessage(error, 'Favorite receiver could not be saved.'), 'error');
    } finally {
      setButtonBusy(button, false);
    }
  }

  async function removeTransferFavorite(favoriteId) {
    const id = String(favoriteId || '').trim();
    if (!id) return;
    if (!window.confirm('Remove this favorite receiver?')) return;

    try {
      await postWithFreshCsrf('transfer_favorite_remove', { favorite_id: id }, 'Removing favorite...');
      toast('Favorite receiver removed.', 'success');
      app.transfer.favoritesLoaded = false;
      await loadTransferFavorites(true);
      syncTransferFavoriteAdd();
    } catch (error) {
      toast(safeMessage(error, 'Favorite receiver could not be removed.'), 'error');
    }
  }

  async function resolveRecipient() {
    const button = $('transferResolveBtn');
    const receiver = String($('transferReceiverInput')?.value || '').trim();
    if (!receiver) {
      openTransferError('Receiver Required', 'Enter the receiver phone number.');
      return;
    }
    if (app.transfer.resolving) return;
    app.transfer.resolving = true;
    setButtonBusy(button, true, 'Checking...');
    openTransferLoading('Checking receiver...');
    try {
      const data = await postWithFreshCsrf('transfer_recipient', { recipient_phone: receiver }, 'Checking receiver...');
      const recipient = Object.assign({}, data.recipient || {}, {
        wallet_currency: data.wallet_currency || data.sender_wallet_currency || ''
      });
      if (!data.can_transfer || recipient.can_transfer === false) {
        throw new Error(data.validation_message || 'This account cannot receive this transfer.');
      }
      app.transfer.recipient = recipient;
      app.transfer.preview = null;
      app.transfer.verifiedInput = receiver;
      finishTransferModalClose({ replaceHistory: true });
      renderRecipientCard();
      transferStep(2);
    } catch (error) {
      finishTransferModalClose({ replaceHistory: true });
      openTransferError('Receiver Not Found', safeMessage(error, 'Receiver could not be verified.'));
    } finally {
      app.transfer.resolving = false;
      setButtonBusy(button, false);
    }
  }

  async function continueTransferAmount() {
    if (!app.transfer.recipient) {
      openTransferError('Receiver Required', 'Verify the receiver first.');
      transferStep(1);
      return;
    }
    const amount = Number($('transferAmountInput')?.value || 0);
    if (!Number.isFinite(amount) || amount < 1) {
      openTransferError('Invalid Amount', 'Enter an amount of at least 1.00.');
      return;
    }
    if (app.transfer.amountChecking) return;
    app.transfer.amountChecking = true;
    setButtonBusy($('transferAmountNextBtn'), true, 'Checking...');
    openTransferLoading('Checking balance...');
    try {
      await postWithFreshCsrf('transfer_preview', {
        recipient_phone: app.transfer.verifiedInput || String($('transferReceiverInput')?.value || '').trim(),
        amount: amount,
        check_only: true
      }, 'Checking balance...');
      app.transfer.preview = null;
      finishTransferModalClose({ replaceHistory: true });
      transferStep(3);
    } catch (error) {
      finishTransferModalClose({ replaceHistory: true });
      openTransferError('Amount Not Ready', safeMessage(error, 'Transfer amount could not be validated.'));
    } finally {
      app.transfer.amountChecking = false;
      setButtonBusy($('transferAmountNextBtn'), false);
    }
  }

  async function previewTransfer() {
    const button = $('transferPreviewBtn');
    const pinInput = $('transferPinInput');
    const pin = String(pinInput?.value || '').trim();
    const receiver = app.transfer.verifiedInput || String($('transferReceiverInput')?.value || '').trim();
    const amount = Number($('transferAmountInput')?.value || 0);
    if (!app.transfer.recipient) {
      openTransferError('Receiver Required', 'Verify the receiver first.');
      transferStep(1);
      return;
    }
    if (!/^\d{4}$/.test(pin)) {
      openTransferError('PIN Required', 'Enter your correct 4-digit transaction PIN.');
      return;
    }
    setButtonBusy(button, true, 'Preparing...');
    openTransferLoading('Loading transfer preview...');
    try {
      const preview = await post('transfer_preview', {
        recipient_phone: receiver,
        amount: amount,
        pin: pin
      }, 'Preparing transfer preview...', { busy: false });
      app.transfer.preview = preview;
      if (pinInput) pinInput.value = '';
      finishTransferModalClose({ replaceHistory: true });
      renderTransferReview();
      transferStep(4);
    } catch (error) {
      if (pinInput) pinInput.value = '';
      finishTransferModalClose({ replaceHistory: true });
      openTransferError('Preview Failed', safeMessage(error, 'Transfer preview could not be loaded.'));
    } finally {
      setButtonBusy(button, false);
    }
  }

  function renderTransferReview() {
    const preview = app.transfer.preview || {};
    const recipient = app.transfer.recipient || {};
    const currency = String(preview.wallet_currency || preview.currency || recipient.wallet_currency || 'BDT').toUpperCase();
    const feeAmount = Number(preview.fee_amount || preview.fee || 0);
    const rows = [
      ['Receiver', preview.receiver_name || recipient.receiver_name || recipient.name || '-'],
      ['Account', maskPhone(preview.receiver_phone || preview.receiver_account || recipient.receiver_phone)],
      ['Amount', preview.amount_text || formatMoney(preview.amount, currency)]
    ];
    rows.push(['Fee', preview.fee_text || formatMoney(Number.isFinite(feeAmount) ? feeAmount : 0, currency)]);
    rows.push(['Total Amount', preview.total_paid_text || preview.total_pay_text || formatMoney(preview.total_debit, currency)]);
    if (preview.balance_after_text || preview.balance_after !== undefined) {
      rows.push(['Remaining Balance', preview.balance_after_text || formatMoney(preview.balance_after, currency)]);
    }
    if ($('transferReviewRows')) {
      $('transferReviewRows').innerHTML = rows.map((row) => '<div class="review-row"><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1]) + '</strong></div>').join('');
    }
    if ($('transferReferenceInput')) $('transferReferenceInput').value = app.transfer.reference || '';
  }

  function cancelHold() {
    if (app.transfer.holdFrame) cancelAnimationFrame(app.transfer.holdFrame);
    app.transfer.holdFrame = 0;
    app.transfer.holdStartedAt = 0;
    $('transferHoldConfirmBtn')?.style.setProperty('--hold-progress', '0%');
  }

  function startHold(event) {
    if (app.transfer.submitting || !app.transfer.preview || app.transfer.holdStartedAt) return;
    if (event && event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
    if (event) event.preventDefault();
    if (event && event.pointerId !== undefined && event.currentTarget?.setPointerCapture) {
      try { event.currentTarget.setPointerCapture(event.pointerId); } catch (_) {}
    }
    app.transfer.holdStartedAt = performance.now();
    const duration = 2300;
    const tick = (now) => {
      if (!app.transfer.holdStartedAt) return;
      const progress = Math.min(1, (now - app.transfer.holdStartedAt) / duration);
      $('transferHoldConfirmBtn')?.style.setProperty('--hold-progress', (progress * 100).toFixed(1) + '%');
      if (progress >= 1) {
        app.transfer.holdStartedAt = 0;
        app.transfer.holdFrame = 0;
        submitTransfer();
        return;
      }
      app.transfer.holdFrame = requestAnimationFrame(tick);
    };
    app.transfer.holdFrame = requestAnimationFrame(tick);
  }

  async function submitTransfer() {
    const preview = app.transfer.preview || {};
    const token = String(preview.preview_token || '');
    if (!token || app.transfer.submitting) return;
    app.transfer.submitting = true;
    const button = $('transferHoldConfirmBtn');
    const label = button?.querySelector('.hold-confirm-label');
    if (button) button.disabled = true;
    if (label) label.textContent = 'Transferring...';
    app.transfer.reference = String($('transferReferenceInput')?.value || '').trim();
    const recipient = app.transfer.recipient || {};
    const successBase = {
      receiver_name: preview.receiver_name || recipient.receiver_name || recipient.name || 'Z-Pay User',
      receiver_phone: preview.receiver_phone || preview.receiver_account || transferRecipientPhone(recipient),
      receiver_phone_masked: recipient.receiver_phone_masked || maskPhone(preview.receiver_phone || preview.receiver_account || transferRecipientPhone(recipient)),
      amount: preview.amount || $('transferAmountInput')?.value || 0,
      amount_text: preview.amount_text || formatMoney(preview.amount, preview.wallet_currency || recipient.wallet_currency),
      wallet_currency: preview.wallet_currency || preview.currency || recipient.wallet_currency || 'BDT',
      reference: app.transfer.reference
    };
    openTransferLoading('Submitting transfer...');
    try {
      const data = await post('transfer_create', {
        preview_token: token,
        reference: app.transfer.reference
      }, 'Completing transfer...');
      const transfer = data.transfer || {};
      const context = Object.assign({}, successBase, transfer, {
        receiver_name: transfer.receiver_name || successBase.receiver_name,
        receiver_phone: transfer.receiver_phone || transfer.receiver_account || successBase.receiver_phone,
        receiver_phone_masked: maskPhone(transfer.receiver_phone || transfer.receiver_account || successBase.receiver_phone),
        amount_text: transfer.amount_text || transfer.total_paid_text || successBase.amount_text,
        wallet_currency: transfer.wallet_currency || transfer.currency || successBase.wallet_currency,
        transfer_id: transfer.transfer_id || transfer.request_id || '',
        reference: transfer.reference || successBase.reference
      });
      finishTransferModalClose({ replaceHistory: true });
      resetTransfer();
      app.transfer.favoritesLoaded = false;
      loadTransferFavorites(true).catch(() => {});
      if (typeof window.refreshUserDashboard === 'function') {
        window.refreshUserDashboard(false).catch(() => {});
      }
      showTransferSuccess(context);
    } catch (error) {
      finishTransferModalClose({ replaceHistory: true });
      openTransferError('Transfer Not Completed', safeMessage(error, 'No money was moved. Please review and try again.'));
    } finally {
      app.transfer.submitting = false;
      if (button) button.disabled = false;
      if (label) label.textContent = 'Tap and hold to confirm transfer';
      cancelHold();
    }
  }

  function resetTransfer() {
    app.transfer.recipient = null;
    app.transfer.preview = null;
    app.transfer.reference = '';
    app.transfer.verifiedInput = '';
    ['transferReceiverInput', 'transferAmountInput', 'transferReferenceInput', 'transferPinInput'].forEach((id) => {
      if ($(id)) $(id).value = '';
    });
    syncTransferFavoriteAdd();
    transferStep(1, { fromHistory: true });
  }

  function supportStatus(status) {
    const code = String(status || 'OPEN').toUpperCase();
    return ({ OPEN: 'Open', PENDING: 'Pending', REPLIED: 'Replied', RESOLVED: 'Resolved', CLOSED: 'Closed' })[code] || code;
  }

  function supportIsClosed(status) {
    return ['CLOSED', 'RESOLVED'].includes(String(status || '').toUpperCase());
  }

  async function loadSupportConfig(force) {
    if (app.support.config && !force) {
      renderSupportConfig();
      return;
    }
    try {
      const data = await get('support_config', {}, 'Loading support...');
      app.support.config = data.config || {};
      app.support.categories = Array.isArray(data.categories) ? data.categories : [];
      renderSupportConfig();
    } catch (error) {
      toast(safeMessage(error, 'Support is unavailable.'), 'error');
    }
  }

  function renderSupportConfig() {
    const config = app.support.config || {};
    if ($('supportNotice')) $('supportNotice').textContent = config.support_notice || 'Never share your password, PIN or OTP with anyone.';
    if ($('supportHoursText')) $('supportHoursText').textContent = config.support_hours || 'Every day, 10:00 AM - 10:00 PM';
    if ($('supportAverageReplyText')) $('supportAverageReplyText').textContent = config.average_response_text || 'Average response time: within 24 hours.';
    const category = $('supportCategory');
    if (category) {
      const selected = category.value;
      category.replaceChildren(new Option('Select a category', ''));
      app.support.categories.forEach((item) => category.add(new Option(String(item.name || item.code || ''), String(item.code || ''))));
      category.value = selected;
    }
    const actions = $('supportContactActions');
    if (actions) {
      actions.replaceChildren();
      const links = [];
      if (config.email_enabled && config.support_email) {
        links.push({
          type: 'email',
          label: 'Email',
          detail: String(config.support_email),
          href: 'mailto:' + String(config.support_email).trim()
        });
      }
      if (config.whatsapp_enabled && config.whatsapp_number) {
        links.push({
          type: 'chat',
          label: 'WhatsApp',
          detail: String(config.whatsapp_number),
          href: 'https://wa.me/' + String(config.whatsapp_number).replace(/\D/g, '')
        });
      }
      if (config.call_enabled && config.support_phone) {
        links.push({
          type: 'phone',
          label: 'Call',
          detail: String(config.support_phone),
          href: 'tel:' + String(config.support_phone).replace(/[^+\d]/g, '')
        });
      }
      links.forEach((item) => {
        const link = document.createElement('a');
        link.className = 'support-contact-action';
        link.href = item.href;
        if (item.href.startsWith('https:')) {
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
        }
        const icon = document.createElement('span');
        icon.className = 'support-contact-action-icon ' + item.type;
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = supportContactIcon(item.type);
        const title = document.createElement('strong');
        title.textContent = item.label;
        const detail = document.createElement('small');
        detail.textContent = item.detail;
        link.append(icon, title, detail);
        actions.appendChild(link);
      });
      actions.classList.toggle('hidden', !links.length);
    }
    renderRelatedRequests();
  }

  function supportContactIcon(type) {
    if (type === 'email') {
      return '<svg viewBox="0 0 24 24"><path d="M4 6h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Zm8 7.1L4.9 8H4v.8l8 5.7 8-5.7V8h-.9L12 13.1Z"/></svg>';
    }
    if (type === 'phone') {
      return '<svg viewBox="0 0 24 24"><path d="M6.6 10.8a15 15 0 0 0 6.6 6.6l2.2-2.2a1.4 1.4 0 0 1 1.4-.3c1.5.5 3 .8 4.6.8a1.4 1.4 0 0 1 1.4 1.4v3.5a1.4 1.4 0 0 1-1.4 1.4A19.9 19.9 0 0 1 1.5 2.6a1.4 1.4 0 0 1 1.4-1.4h3.5a1.4 1.4 0 0 1 1.4 1.4c0 1.6.3 3.1.8 4.6.2.5.1 1-.3 1.4l-1.7 2.2Z"/></svg>';
    }
    return '<svg viewBox="0 0 24 24"><path d="M12 3C6.5 3 2 6.8 2 11.5c0 2.7 1.5 5.2 4 6.7V22l3.7-2.1c.7.1 1.5.2 2.3.2 5.5 0 10-3.8 10-8.5S17.5 3 12 3Zm-4 9.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm4 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm4 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>';
  }

  function renderRelatedRequests() {
    const select = $('supportRelatedRequest');
    const wrap = $('supportRelatedWrap');
    if (!select || !wrap) return;
    const rows = Array.isArray(window.userState && window.userState.requestLogs) ? window.userState.requestLogs : [];
    select.replaceChildren(new Option('No related request', ''));
    rows.slice(0, 40).forEach((row) => {
      const id = String(row.request_id || row.transfer_id || row.id || '');
      if (!id) return;
      const label = [id, row.type || row.request_type || '', row.amount_text || ''].filter(Boolean).join(' - ');
      const option = new Option(label, id);
      option.dataset.relatedType = String(row.type || row.request_type || '');
      select.add(option);
    });
    wrap.classList.toggle('hidden', !rows.length);
  }

  async function loadSupportTickets(force) {
    if (app.support.tickets.length && !force) {
      renderSupportTickets();
      return app.support.tickets;
    }
    try {
      const data = await get('support_list', { limit: 50 }, 'Loading support requests...', { busy: false });
      app.support.tickets = Array.isArray(data.tickets) ? data.tickets : [];
      renderSupportTickets();
      return app.support.tickets;
    } catch (error) {
      if ($('supportTicketList')) $('supportTicketList').innerHTML = '<div class="feature-empty-state">Support requests could not be loaded.</div>';
      toast(safeMessage(error, 'Support requests could not be loaded.'), 'error');
      return [];
    }
  }

  function renderSupportTickets() {
    const list = $('supportTicketList');
    if (!list) return;
    list.replaceChildren();
    if (!app.support.tickets.length) {
      const empty = document.createElement('div');
      empty.className = 'feature-empty-state';
      empty.textContent = 'No support requests yet.';
      list.appendChild(empty);
    } else {
      app.support.tickets.forEach((ticket) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'support-ticket-card' + (ticket.user_unread ? ' unread' : '');
        button.innerHTML = '<div><h4>' + escapeHtml(ticket.subject || ticket.ticket_id || 'Support Request') + '</h4>' +
          '<p>' + escapeHtml(ticket.category_name || ticket.category_code || 'Support') + ' - ' + escapeHtml(formatDate(ticket.updated_at || ticket.created_at)) + '</p>' +
          '<p>' + escapeHtml(ticket.last_message_preview || '') + '</p></div>' +
          '<span class="status-pill">' + escapeHtml(ticket.status_label || supportStatus(ticket.status)) + '</span>';
        button.addEventListener('click', () => openSupportConversation(ticket.ticket_id));
        list.appendChild(button);
      });
    }
    const unread = app.support.tickets.filter((ticket) => ticket.user_unread).length;
    if ($('supportUnreadBadge')) {
      $('supportUnreadBadge').textContent = String(unread);
      $('supportUnreadBadge').classList.toggle('hidden', unread < 1);
    }
  }

  function switchSupportTab(tab) {
    $('supportRequestWorkspace')?.classList.remove('hidden');
    const list = tab === 'list';
    $('supportNewTab')?.classList.toggle('active', !list);
    $('supportListTab')?.classList.toggle('active', list);
    $('supportNewTab')?.setAttribute('aria-selected', String(!list));
    $('supportListTab')?.setAttribute('aria-selected', String(list));
    $('supportCreatePanel')?.classList.toggle('active', !list);
    $('supportListPanel')?.classList.toggle('active', list);
    if (list) loadSupportTickets(false);
  }

  function openSupportTicketCandidate() {
    return app.support.tickets.find((ticket) => ticket && ticket.ticket_id && !supportIsClosed(ticket.status)) || null;
  }

  function showSupportHome() {
    stopSupportPolling();
    app.support.ticket = null;
    $('supportConversationView')?.classList.add('hidden');
    $('supportHomeView')?.classList.remove('hidden');
    $('supportRequestWorkspace')?.classList.add('hidden');
    $('supportContactBody')?.scrollTo?.({ top: 0, behavior: 'auto' });
  }

  function showSupportWorkspace(tab) {
    $('supportHomeView')?.classList.add('hidden');
    $('supportConversationView')?.classList.add('hidden');
    switchSupportTab(tab === 'new' ? 'new' : 'list');
    $('supportRequestWorkspace')?.scrollTo?.({ top: 0, behavior: 'auto' });
  }

  async function openSupportEntry() {
    const button = $('supportOpenRequestsButton');
    if (button) button.disabled = true;
    try {
      await loadSupportTickets(false);
      const activeTicket = openSupportTicketCandidate();
      if (activeTicket) {
        await openSupportConversation(activeTicket.ticket_id);
        return;
      }
      showSupportWorkspace('list');
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function startSupportChat() {
    const button = $('supportStartChatButton');
    if (button) button.disabled = true;
    try {
      await loadSupportTickets(false);
      const activeTicket = openSupportTicketCandidate();
      if (activeTicket) {
        await openSupportConversation(activeTicket.ticket_id);
        return;
      }
      showSupportWorkspace('new');
    } finally {
      if (button) button.disabled = false;
    }
  }

  function selectedCategory() {
    return app.support.categories.find((item) => String(item.code || '') === String($('supportCategory')?.value || '')) || null;
  }

  function validateFiles(files, maxFiles, maxSize) {
    const rows = Array.from(files || []);
    if (rows.length > maxFiles) throw new Error('You can attach up to ' + maxFiles + ' screenshots.');
    rows.forEach((file) => {
      if (!allowedImages.has(String(file.type || '').toLowerCase())) throw new Error('Only JPG, PNG and WebP screenshots are allowed.');
      if (file.size <= 0 || file.size > maxSize) throw new Error('Each screenshot must be within the allowed file size.');
    });
    return rows;
  }

  function updateAttachmentSummary(input, output) {
    const files = Array.from(input && input.files ? input.files : []);
    if (output) output.textContent = files.length ? files.map((file) => file.name).join(', ') : '';
  }

  async function createSupportTicket(event) {
    event.preventDefault();
    const button = $('supportCreateButton');
    const config = app.support.config || {};
    try {
      const files = validateFiles($('supportAttachments')?.files, Number(config.max_attachments || 3), Number(config.max_file_size || 5 * 1024 * 1024));
      if (!app.support.createKey) app.support.createKey = makeIdempotencyKey('SUPPORT-CREATE');
      const data = new FormData();
      data.append('category_code', String($('supportCategory')?.value || ''));
      data.append('subject', String($('supportSubject')?.value || '').trim());
      data.append('message', String($('supportMessage')?.value || '').trim());
      data.append('related_request_id', String($('supportRelatedRequest')?.value || ''));
      data.append('related_type', String($('supportRelatedRequest')?.selectedOptions?.[0]?.dataset.relatedType || ''));
      data.append('idempotency_key', app.support.createKey);
      files.forEach((file) => data.append('attachments[]', file, file.name));
      setButtonBusy(button, true, 'Submitting...');
      const result = await postForm('support_create', data, 'Submitting support request...');
      const ticket = result.ticket || {};
      app.support.createKey = '';
      $('supportCreateForm')?.reset();
      if ($('supportAttachmentSummary')) $('supportAttachmentSummary').textContent = '';
      await loadSupportTickets(true);
      switchSupportTab('list');
      toast('Support request submitted.', 'ok');
      if (ticket.ticket_id) await openSupportConversation(ticket.ticket_id);
    } catch (error) {
      toast(safeMessage(error, 'Support request could not be submitted.'), 'error');
    } finally {
      setButtonBusy(button, false);
    }
  }

  async function openSupportConversation(ticketId, options) {
    const id = String(ticketId || '').trim();
    if (!id) return;
    try {
      const data = await get('support_details', { ticket_id: id }, 'Loading conversation...', { busy: !(options && options.silent) });
      app.support.ticket = data.ticket || null;
      app.support.messages = Array.isArray(data.messages) ? data.messages : [];
      app.support.attachments = Array.isArray(data.attachments) ? data.attachments : [];
      $('supportHomeView')?.classList.add('hidden');
      $('supportRequestWorkspace')?.classList.add('hidden');
      $('supportConversationView')?.classList.remove('hidden');
      renderSupportConversation();
      window.scrollTo({ top: 0, behavior: 'auto' });
      if (!(options && options.fromHistory) && window.history && window.history.pushState) {
        window.history.pushState(Object.assign({}, window.history.state || {}, {
          zpayUserApp: { view: 'supportConversation', ticket_id: id }
        }), '', sectionPaths.supportSection);
      }
      startSupportPolling();
    } catch (error) {
      toast(safeMessage(error, 'Support conversation could not be opened.'), 'error');
    }
  }

  function renderSupportConversation() {
    const ticket = app.support.ticket || {};
    if ($('supportConversationTitle')) $('supportConversationTitle').textContent = ticket.subject || 'Support Request';
    if ($('supportConversationMeta')) $('supportConversationMeta').textContent = [ticket.ticket_id, ticket.category_name || ticket.category_code].filter(Boolean).join(' - ');
    if ($('supportConversationStatus')) $('supportConversationStatus').textContent = ticket.status_label || supportStatus(ticket.status);
    const closed = supportIsClosed(ticket.status);
    $('supportReplyForm')?.classList.toggle('hidden', closed);
    if ($('supportClosedNotice')) {
      $('supportClosedNotice').classList.toggle('hidden', !closed);
      $('supportClosedNotice').textContent = closed ? 'This request is ' + supportStatus(ticket.status).toLowerCase() + '. New replies are disabled.' : '';
    }
    renderSupportMessages();
  }

  function renderSupportMessages() {
    const container = $('supportMessages');
    if (!container) return;
    const byId = new Map();
    app.support.messages.forEach((message, index) => {
      const id = String(message.message_id || 'message-' + index);
      byId.set(id, message);
    });
    const rows = Array.from(byId.values()).sort((a, b) => Number(a.created_at || 0) - Number(b.created_at || 0));
    const attachmentsByMessage = new Map();
    app.support.attachments.forEach((attachment) => {
      const id = String(attachment.message_id || '');
      if (!attachmentsByMessage.has(id)) attachmentsByMessage.set(id, []);
      attachmentsByMessage.get(id).push(attachment);
    });
    container.replaceChildren();
    rows.forEach((message) => {
      const sender = String(message.sender_type || '').toUpperCase();
      const type = sender === 'USER' ? 'user' : (sender === 'SYSTEM' ? 'system' : 'support');
      const bubble = document.createElement('article');
      bubble.className = 'support-message ' + type;
      const text = document.createElement('p');
      text.textContent = String(message.message || '');
      const meta = document.createElement('small');
      meta.textContent = [message.sender_name || (type === 'user' ? 'You' : 'Z-Pay Support'), formatDate(message.created_at)].filter(Boolean).join(' - ');
      bubble.append(text, meta);
      const files = attachmentsByMessage.get(String(message.message_id || '')) || [];
      if (files.length) {
        const wrap = document.createElement('div');
        wrap.className = 'message-attachments';
        files.forEach((attachment, index) => {
          const link = document.createElement('a');
          link.textContent = attachment.original_name || 'Screenshot ' + (index + 1);
          link.href = (window.USER_PROXY_URL || '/api/user/proxy.php') + '?action=support_attachment&ticket_id=' + encodeURIComponent(String(app.support.ticket.ticket_id || '')) + '&attachment_id=' + encodeURIComponent(String(attachment.attachment_id || ''));
          link.target = '_blank';
          link.rel = 'noopener';
          wrap.appendChild(link);
        });
        bubble.appendChild(wrap);
      }
      container.appendChild(bubble);
    });
    requestAnimationFrame(() => { container.scrollTop = container.scrollHeight; });
  }

  function closeSupportConversation(options) {
    stopSupportPolling();
    app.support.ticket = null;
    app.support.messages = [];
    app.support.attachments = [];
    $('supportConversationView')?.classList.add('hidden');
    showSupportWorkspace('list');
    if (!(options && options.fromHistory) && window.history?.back) window.history.back();
  }

  async function replySupport(event) {
    event.preventDefault();
    const ticket = app.support.ticket || {};
    const messageInput = $('supportReplyMessage');
    const message = String(messageInput?.value || '').trim();
    if (supportIsClosed(ticket.status)) return;
    if (!message) {
      toast('Write a reply before sending.', 'error');
      return;
    }
    const button = $('supportReplyButton');
    try {
      const config = app.support.config || {};
      const files = validateFiles($('supportReplyAttachment')?.files, Number(config.max_attachments || 3), Number(config.max_file_size || 5 * 1024 * 1024));
      if (!app.support.replyKey) app.support.replyKey = makeIdempotencyKey('SUPPORT-REPLY');
      const data = new FormData();
      data.append('ticket_id', String(ticket.ticket_id || ''));
      data.append('message', message);
      data.append('idempotency_key', app.support.replyKey);
      files.forEach((file) => data.append('attachments[]', file, file.name));
      setButtonBusy(button, true, 'Sending...');
      const result = await postForm('support_reply', data, 'Sending reply...');
      app.support.replyKey = '';
      app.support.ticket = result.ticket || ticket;
      app.support.messages = Array.isArray(result.messages) ? result.messages : app.support.messages;
      app.support.attachments = Array.isArray(result.attachments) ? result.attachments : app.support.attachments;
      if (messageInput) messageInput.value = '';
      if ($('supportReplyAttachment')) $('supportReplyAttachment').value = '';
      if ($('supportReplyAttachmentSummary')) $('supportReplyAttachmentSummary').textContent = '';
      renderSupportConversation();
    } catch (error) {
      toast(safeMessage(error, 'Reply could not be sent.'), 'error');
    } finally {
      setButtonBusy(button, false);
    }
  }

  function startSupportPolling() {
    stopSupportPolling();
    app.support.pollTimer = window.setInterval(() => {
      if (document.visibilityState === 'visible' && app.support.ticket && document.body.getAttribute('data-active-section') === 'supportSection') {
        openSupportConversation(app.support.ticket.ticket_id, { silent: true, fromHistory: true });
      }
    }, 30000);
  }

  function stopSupportPolling() {
    if (app.support.pollTimer) clearInterval(app.support.pollTimer);
    app.support.pollTimer = 0;
  }

  async function loadUnreadCount() {
    try {
      const data = await get('notifications_unread', {}, 'Loading notifications...', { busy: false });
      renderNotificationBadge(Number(data.unread_count || 0));
    } catch (_) {
      renderNotificationBadge(0);
    }
  }

  function renderNotificationBadge(count) {
    const unreadCount = Math.min(99, Math.max(0, Number(count || 0)));
    app.notifications.unreadCount = unreadCount;
    ['notificationBadge', 'heroNotificationBadge', 'profileNotificationBadge', 'supportNotificationBadge', 'transferNotificationBadge'].forEach((id) => {
      const badge = $(id);
      if (!badge) return;
      badge.textContent = String(unreadCount);
      badge.classList.toggle('hidden', unreadCount < 1);
    });
    if ($('notificationUnreadCount')) $('notificationUnreadCount').textContent = String(unreadCount);
    updateNotificationEditActions();
  }

  function updateNotificationTabs() {
    document.querySelectorAll('[data-notification-filter]').forEach((tab) => {
      const active = String(tab.dataset.notificationFilter || '') === app.notifications.filter;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.disabled = app.notifications.loading;
    });
    renderNotificationBadge(app.notifications.unreadCount);
  }

  function updateNotificationEditActions() {
    const selectedCount = app.notifications.selected.size;
    const visibleIds = app.notifications.items.map((item) => String(item.notification_id || '')).filter(Boolean);
    const editButton = $('notificationsEditButton');
    const editBar = $('notificationEditBar');
    const selectAll = $('notificationsSelectAllButton');
    const deleteButton = $('notificationsDeleteButton');
    const markButton = $('notificationsMarkSelectedButton');
    if (editButton) {
      editButton.setAttribute('aria-pressed', app.notifications.editing ? 'true' : 'false');
      editButton.setAttribute('aria-label', app.notifications.editing ? 'Finish editing notifications' : 'Edit notifications');
    }
    editBar?.classList.toggle('hidden', !app.notifications.editing);
    if (selectAll) selectAll.textContent = visibleIds.length > 0 && selectedCount === visibleIds.length ? 'Clear All' : 'Select All';
    if (deleteButton) deleteButton.disabled = selectedCount < 1 || app.notifications.loading;
    if (markButton) markButton.disabled = selectedCount < 1 || app.notifications.loading;
  }

  function setNotificationEditMode(enabled) {
    app.notifications.editing = Boolean(enabled);
    app.notifications.selected.clear();
    updateNotificationEditActions();
    renderNotifications(app.notifications.items);
  }

  function toggleNotificationSelection(item) {
    const id = String(item?.notification_id || '');
    if (!id) return;
    if (app.notifications.selected.has(id)) app.notifications.selected.delete(id);
    else app.notifications.selected.add(id);
    updateNotificationEditActions();
    renderNotifications(app.notifications.items);
  }

  function setNotificationLive(message) {
    if ($('notificationPageLive')) $('notificationPageLive').textContent = String(message || '');
  }

  function renderNotificationLoading() {
    const list = $('notificationList');
    if (!list) return;
    list.setAttribute('aria-busy', 'true');
    list.replaceChildren();
    for (let index = 0; index < 4; index += 1) {
      const skeleton = document.createElement('div');
      skeleton.className = 'notification-page-skeleton';
      skeleton.setAttribute('aria-hidden', 'true');
      list.appendChild(skeleton);
    }
    setNotificationLive('Loading notifications.');
  }

  function renderNotificationMessage(title, message, actionLabel) {
    const list = $('notificationList');
    if (!list) return;
    list.setAttribute('aria-busy', 'false');
    list.replaceChildren();
    const state = document.createElement('div');
    state.className = 'notification-page-state';
    const icon = document.createElement('span');
    icon.className = 'notification-page-state-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = app.notifications.filter === 'UNREAD' ? '\u2713' : 'Z';
    const heading = document.createElement('h3');
    heading.textContent = title;
    const copy = document.createElement('p');
    copy.textContent = message;
    state.append(icon, heading, copy);
    if (actionLabel) {
      const retry = document.createElement('button');
      retry.className = 'notification-page-retry';
      retry.type = 'button';
      retry.textContent = actionLabel;
      retry.addEventListener('click', () => loadNotificationPage(true));
      state.appendChild(retry);
    }
    list.appendChild(state);
    setNotificationLive(title + ' ' + message);
  }

  function notificationGlyph(item) {
    const type = String(item && item.type || '').toUpperCase();
    if (type.includes('FAILED') || type.includes('REJECTED')) return '!';
    if (type.startsWith('SUPPORT_')) return 'S';
    if (type.startsWith('ACCOUNT_') || type === 'SECURITY_REVIEW' || type === 'LOGIN_ALERT') return 'A';
    if (type === 'RINGGIT_RATE_UPDATED') return 'R';
    if (type.includes('TRANSFER') || type.includes('MONEY') || type.includes('SUCCESS')) return '$';
    return 'Z';
  }

  function notificationDestination(item) {
    const type = String(item && item.type || '').toUpperCase();
    if (type.startsWith('SUPPORT_')) return 'supportSection';
    if (type.startsWith('ACCOUNT_') || type === 'SECURITY_REVIEW' || type === 'LOGIN_ALERT') return 'profileSection';
    if (type === 'ADMIN_NOTICE' || type === 'RINGGIT_RATE_UPDATED') return '';
    return 'historySection';
  }

  function renderNotificationDetail(item, loading) {
    const detail = item || {};
    if ($('notificationDetailIcon')) $('notificationDetailIcon').textContent = notificationGlyph(detail);
    if ($('notificationDetailTitle')) $('notificationDetailTitle').textContent = String(detail.title || 'Notification');
    if ($('notificationDetailTime')) $('notificationDetailTime').textContent = loading ? 'Loading details...' : formatDate(detail.created_at);
    if ($('notificationDetailBody')) {
      $('notificationDetailBody').textContent = loading
        ? String(detail.body || 'Loading notification...')
        : String(detail.body_full || detail.body || 'No additional details are available.');
    }
    const openButton = $('notificationDetailOpenButton');
    if (openButton) openButton.classList.toggle('hidden', !notificationDestination(detail));
  }

  function setNotificationDetailInert(modal, inert) {
    if (!modal) return;
    modal.inert = Boolean(inert);
    if (inert) modal.setAttribute('inert', '');
    else modal.removeAttribute('inert');
  }

  function closeNotificationDetails(options) {
    const settings = options || {};
    const modal = $('notificationDetailModal');
    if (!modal || modal.classList.contains('hidden')) return;
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    setNotificationDetailInert(modal, true);
    document.body.classList.remove('notification-detail-open');
    const opener = app.notifications.detailOpener;
    app.notifications.activeDetail = null;
    app.notifications.detailOpener = null;
    if (app.notifications.detailHistory && !settings.fromHistory && window.history?.back) {
      app.notifications.detailHistory = false;
      window.history.back();
    } else if (settings.fromHistory) {
      app.notifications.detailHistory = false;
    }
    opener?.focus?.({ preventScroll: true });
  }

  async function openNotificationItem(item, opener) {
    if (!item || item.opening || app.notifications.editing) return;
    item.opening = true;
    app.notifications.activeDetail = item;
    app.notifications.detailOpener = opener || document.activeElement;
    const modal = $('notificationDetailModal');
    modal?.classList.remove('hidden');
    modal?.removeAttribute('aria-hidden');
    setNotificationDetailInert(modal, false);
    document.body.classList.add('notification-detail-open');
    renderNotificationDetail(item, true);
    $('notificationDetailCloseButton')?.focus?.({ preventScroll: true });

    if (!app.notifications.detailHistory && window.history?.pushState) {
      app.notifications.detailHistory = true;
      window.history.pushState(Object.assign({}, window.history.state || {}, {
        zpayUserApp: { view: 'notification-detail', notificationId: item.notification_id }
      }), '', window.location.href);
    }

    try {
      const requests = [
        get('notification_details', { notification_id: item.notification_id }, 'Loading notification...', { busy: false })
      ];
      if (!item.is_read) {
        requests.push(postWithFreshCsrf('notification_mark_read', { notification_id: item.notification_id }, 'Updating notification...'));
      }
      const results = await Promise.allSettled(requests);
      const detailsResult = results[0];
      if (detailsResult.status === 'fulfilled') {
        const detailItem = detailsResult.value.notification || item;
        Object.assign(item, detailItem);
        app.notifications.activeDetail = item;
        renderNotificationDetail(item, false);
        renderNotificationBadge(Number(detailsResult.value.unread_count ?? app.notifications.unreadCount));
      } else {
        throw detailsResult.reason;
      }
      if (results[1]?.status === 'fulfilled') {
        item.is_read = true;
        renderNotificationBadge(Number(results[1].value.unread_count ?? app.notifications.unreadCount));
      } else if (results[1]?.status === 'rejected') {
        toast(safeMessage(results[1].reason, 'Read status could not be updated.'), 'error');
      }
      if (app.notifications.filter === 'UNREAD' && item.is_read) {
        app.notifications.items = app.notifications.items.filter((candidate) => candidate.notification_id !== item.notification_id);
      }
      renderNotifications(app.notifications.items);
    } catch (error) {
      if (isSessionError(error)) {
        closeNotificationDetails({ fromHistory: true });
        handleNotificationSessionExpired();
        return;
      }
      renderNotificationDetail(Object.assign({}, item, {
        body_full: safeMessage(error, 'Notification details could not be loaded.')
      }), false);
    } finally {
      item.opening = false;
    }
  }

  function renderNotifications(items) {
    const list = $('notificationList');
    if (!list) return;
    list.setAttribute('aria-busy', 'false');
    list.replaceChildren();
    if (!items.length) {
      renderNotificationMessage(
        app.notifications.filter === 'UNREAD' ? 'You are all caught up' : 'No notifications yet',
        app.notifications.filter === 'UNREAD'
          ? 'New updates will appear here when they arrive.'
          : 'Important account and transaction updates will appear here.'
      );
      return;
    }
    items.forEach((item) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'notification-page-card' + (item.is_read ? '' : ' unread');
      const selected = app.notifications.selected.has(String(item.notification_id || ''));
      button.classList.toggle('selected', selected);
      button.setAttribute('aria-pressed', app.notifications.editing ? (selected ? 'true' : 'false') : 'false');

      const icon = document.createElement('span');
      icon.className = 'notification-page-card-icon';
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = notificationGlyph(item);

      const content = document.createElement('span');
      content.className = 'notification-page-card-content';
      const title = document.createElement('strong');
      title.textContent = String(item.title || 'Z-Pay Swift');
      const body = document.createElement('span');
      body.className = 'notification-page-card-body';
      body.textContent = String(item.body || '');
      const time = document.createElement('time');
      time.className = 'notification-page-card-time';
      time.textContent = formatDate(item.created_at);
      content.append(title, body, time);

      button.append(icon, content);
      if (app.notifications.editing) {
        const selection = document.createElement('span');
        selection.className = 'notification-page-select-indicator';
        selection.setAttribute('aria-hidden', 'true');
        selection.textContent = selected ? '\u2713' : '';
        button.appendChild(selection);
      } else if (!item.is_read) {
        const dot = document.createElement('span');
        dot.className = 'notification-page-unread-dot';
        dot.setAttribute('aria-label', 'Unread');
        button.appendChild(dot);
      }
      button.addEventListener('click', () => {
        if (app.notifications.editing) {
          toggleNotificationSelection(item);
          return;
        }
        openNotificationItem(item, button);
      });
      list.appendChild(button);
    });
    setNotificationLive(items.length + ' notifications loaded.');
  }

  async function loadNotificationPage(force) {
    if (app.notifications.loading) return;
    if (!force && app.notifications.loaded) {
      updateNotificationTabs();
      renderNotifications(app.notifications.items);
      return;
    }
    app.notifications.loading = true;
    updateNotificationTabs();
    renderNotificationLoading();
    try {
      const data = await get(
        'notifications_list',
        { limit: 50, filter: app.notifications.filter },
        'Loading notifications...',
        { busy: false }
      );
      app.notifications.items = Array.isArray(data.items) ? data.items : [];
      app.notifications.loaded = true;
      renderNotificationBadge(Number(data.unread_count || 0));
      renderNotifications(app.notifications.items);
    } catch (error) {
      app.notifications.loaded = false;
      if (isSessionError(error)) {
        handleNotificationSessionExpired();
        return;
      }
      renderNotificationMessage(
        'Could not load notifications',
        safeMessage(error, 'Please check your connection and try again.'),
        'Retry'
      );
    } finally {
      app.notifications.loading = false;
      updateNotificationTabs();
    }
  }

  function openNotificationsPage() {
    const currentSection = document.body.getAttribute('data-active-section') || 'overviewSection';
    if (currentSection !== 'notificationsSection') app.notifications.returnSection = currentSection;
    window.openSection?.('notificationsSection');
  }

  function closeNotificationsPage() {
    const destination = app.notifications.returnSection && app.notifications.returnSection !== 'notificationsSection'
      ? app.notifications.returnSection
      : 'overviewSection';
    window.openSection?.(destination);
  }

  function switchNotificationFilter(filter) {
    const nextFilter = String(filter || '').toUpperCase();
    if (!['ALL', 'UNREAD'].includes(nextFilter) || nextFilter === app.notifications.filter || app.notifications.loading) return;
    app.notifications.filter = nextFilter;
    app.notifications.items = [];
    app.notifications.loaded = false;
    updateNotificationTabs();
    loadNotificationPage(true);
  }

  function selectedNotificationIds() {
    return Array.from(app.notifications.selected).filter(Boolean);
  }

  async function markSelectedNotifications() {
    const ids = selectedNotificationIds();
    if (app.notifications.loading || ids.length < 1) return;
    app.notifications.loading = true;
    updateNotificationEditActions();
    try {
      const data = await postWithFreshCsrf(
        'notification_mark_read',
        { notification_ids: ids },
        'Updating notifications...'
      );
      const selected = new Set(ids);
      app.notifications.items.forEach((item) => {
        if (selected.has(String(item.notification_id || ''))) item.is_read = true;
      });
      if (app.notifications.filter === 'UNREAD') {
        app.notifications.items = app.notifications.items.filter((item) => !selected.has(String(item.notification_id || '')));
      }
      app.notifications.selected.clear();
      renderNotificationBadge(Number(data.unread_count ?? app.notifications.unreadCount));
      renderNotifications(app.notifications.items);
      toast('Selected notifications marked as read.', 'ok');
    } catch (error) {
      if (isSessionError(error)) {
        handleNotificationSessionExpired();
        return;
      }
      toast(safeMessage(error, 'Notifications could not be updated.'), 'error');
    } finally {
      app.notifications.loading = false;
      updateNotificationEditActions();
    }
  }

  async function deleteNotifications(ids, options) {
    const notificationIds = Array.from(new Set((ids || []).map((id) => String(id || '')).filter(Boolean)));
    if (app.notifications.loading || notificationIds.length < 1) return;
    const settings = options || {};
    app.notifications.loading = true;
    updateNotificationEditActions();
    try {
      const data = await postWithFreshCsrf(
        'notifications_delete',
        { notification_ids: notificationIds },
        'Deleting notifications...'
      );
      const deleted = new Set(notificationIds);
      app.notifications.items = app.notifications.items.filter(
        (item) => !deleted.has(String(item.notification_id || ''))
      );
      notificationIds.forEach((id) => app.notifications.selected.delete(id));
      renderNotificationBadge(Number(data.unread_count ?? app.notifications.unreadCount));
      if (settings.closeDetail) closeNotificationDetails();
      renderNotifications(app.notifications.items);
      toast(notificationIds.length === 1 ? 'Notification deleted.' : 'Notifications deleted.', 'ok');
    } catch (error) {
      if (isSessionError(error)) {
        if (settings.closeDetail) closeNotificationDetails({ fromHistory: true });
        handleNotificationSessionExpired();
        return;
      }
      toast(safeMessage(error, 'Notifications could not be deleted.'), 'error');
    } finally {
      app.notifications.loading = false;
      updateNotificationEditActions();
    }
  }

  function toggleSelectAllNotifications() {
    const visibleIds = app.notifications.items.map((item) => String(item.notification_id || '')).filter(Boolean);
    const allSelected = visibleIds.length > 0 && visibleIds.every((id) => app.notifications.selected.has(id));
    app.notifications.selected.clear();
    if (!allSelected) visibleIds.forEach((id) => app.notifications.selected.add(id));
    updateNotificationEditActions();
    renderNotifications(app.notifications.items);
  }

  function openNotificationRelatedPage() {
    const destination = notificationDestination(app.notifications.activeDetail);
    if (!destination) return;
    closeNotificationDetails({ fromHistory: true });
    if (window.history?.replaceState) {
      window.history.replaceState(Object.assign({}, window.history.state || {}, { zpayUserApp: null }), '', window.location.href);
    }
    window.openSection?.(destination);
  }

  function deleteActiveNotification() {
    const id = String(app.notifications.activeDetail?.notification_id || '');
    if (id) deleteNotifications([id], { closeDetail: true });
  }

  function handleNotificationDetailKeydown(event) {
    const modal = $('notificationDetailModal');
    if (!modal || modal.classList.contains('hidden')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeNotificationDetails();
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = Array.from(modal.querySelectorAll(
      'button:not([disabled]):not(.hidden), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter((node) => !node.hidden && node.offsetParent !== null);
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function sectionChanged(sectionId) {
    if (sectionId !== 'profileSection' && profileModal.open) finishProfileModalClose();
    if (sectionId !== 'supportSection') stopSupportPolling();
    if (sectionId !== 'notificationsSection') {
      if (app.notifications.activeDetail) closeNotificationDetails({ fromHistory: true });
      if (app.notifications.editing) setNotificationEditMode(false);
    }
    if (sectionId === 'profileSection') {
      loadProfile(false);
      loadUnreadCount();
      document.querySelector('.profile-scroll-body')?.scrollTo?.({ top: 0, behavior: 'auto' });
    }
    if (sectionId === 'supportSection') {
      loadSupportConfig(false);
      loadSupportTickets(false);
    }
    if (sectionId === 'notificationsSection') {
      app.notifications.loaded = false;
      loadNotificationPage(true);
    }
    if (sectionId === 'transferSection') {
      loadTransferFavorites(false);
      loadUnreadCount();
    }
    if (sectionId === 'overviewSection') loadUnreadCount();
  }

  function handleAppPopState(event) {
    if (app.transfer.modalOpen) {
      if (app.transfer.modalBusy) {
        if (window.history?.pushState) {
          window.history.pushState(Object.assign({}, window.history.state || {}, {
            zpayUserApp: { view: 'transfer', step: app.transfer.step },
            zpayTransferModal: { kind: 'loading' }
          }), '', sectionPaths.transferSection);
          app.transfer.modalHistoryOpen = true;
        }
        return true;
      }
      closeTransferModal({ fromHistory: true });
      return true;
    }
    if (app.notifications.activeDetail) {
      closeNotificationDetails({ fromHistory: true });
      return true;
    }
    if (profileModal.open) {
      closeProfileModal({ fromHistory: true });
      return true;
    }
    const state = event.state && event.state.zpayUserApp;
    if (app.support.ticket) {
      closeSupportConversation({ fromHistory: true });
      return true;
    }
    if (state && state.view === 'transfer') {
      transferStep(Number(state.step || 1), { fromHistory: true });
      return true;
    }
    if (app.transfer.step > 1 && document.body.getAttribute('data-active-section') === 'transferSection') {
      transferStep(Math.max(1, app.transfer.step - 1), { fromHistory: true });
      return true;
    }
    return false;
  }

  function bind() {
    document.addEventListener('click', (event) => {
      const dashboardAction = event.target.closest('[data-dashboard-action]');
      if (dashboardAction) {
        event.preventDefault();
        openDashboardUtility(String(dashboardAction.getAttribute('data-dashboard-action') || ''));
        return;
      }
      const sectionButton = event.target.closest('[data-open-section]');
      if (!sectionButton) return;
      event.preventDefault();
      const provider = String(sectionButton.getAttribute('data-mfs-provider') || '').toUpperCase();
      if (provider) document.querySelector(`.mfs-provider-choice[data-provider="${provider}"]`)?.click();
      const destination = sectionButton.getAttribute('data-open-section');
      window.openSection?.(destination);
      if (destination === 'supportSection') {
        const supportTab = String(sectionButton.getAttribute('data-support-tab') || '');
        if (supportTab === 'list') showSupportWorkspace('list');
        else showSupportHome();
      }
    });

    $('notificationButton')?.addEventListener('click', openNotificationsPage);
    $('heroNotificationButton')?.addEventListener('click', openNotificationsPage);
    $('profileNotificationButton')?.addEventListener('click', openNotificationsPage);
    $('supportNotificationButton')?.addEventListener('click', openNotificationsPage);
    $('notificationsBackButton')?.addEventListener('click', closeNotificationsPage);
    $('notificationsEditButton')?.addEventListener('click', () => setNotificationEditMode(!app.notifications.editing));
    $('notificationsSelectAllButton')?.addEventListener('click', toggleSelectAllNotifications);
    $('notificationsDeleteButton')?.addEventListener('click', () => deleteNotifications(selectedNotificationIds()));
    $('notificationsMarkSelectedButton')?.addEventListener('click', markSelectedNotifications);
    $('notificationDetailCloseButton')?.addEventListener('click', () => closeNotificationDetails());
    $('notificationDetailModal')?.addEventListener('keydown', handleNotificationDetailKeydown);
    document.querySelector('[data-notification-detail-close]')?.addEventListener('click', () => closeNotificationDetails());
    $('notificationDetailDeleteButton')?.addEventListener('click', deleteActiveNotification);
    $('notificationDetailOpenButton')?.addEventListener('click', openNotificationRelatedPage);
    document.querySelectorAll('[data-notification-filter]').forEach((tab) => {
      tab.addEventListener('click', () => switchNotificationFilter(tab.dataset.notificationFilter));
    });
    $('profileEditButton')?.addEventListener('click', editProfile);
    const openProfilePhotoPicker = (event) => {
      profileModal.opener = event.currentTarget;
      $('profilePhotoInput')?.click();
    };
    $('profileAvatarButton')?.addEventListener('click', openProfilePhotoPicker);
    $('profilePhotoEditButton')?.addEventListener('click', openProfilePhotoPicker);
    $('profilePhotoInput')?.addEventListener('change', (event) => uploadProfilePhoto(event.target.files && event.target.files[0]));
    $('profileChangePasswordBtn')?.addEventListener('click', changePassword);
    $('profileChangePinBtn')?.addEventListener('click', changePin);
    $('profileCopyUidBtn')?.addEventListener('click', async () => {
      const uid = String(app.profile && app.profile.uid || '');
      if (!uid) return;
      try { await navigator.clipboard.writeText(uid); toast('Account ID copied.', 'ok'); } catch (_) { toast('Account ID could not be copied.', 'error'); }
    });
    $('profileLogoutBtn')?.addEventListener('click', () => ($('drawerLogoutBtn') || $('sidebarLogoutBtn'))?.click());

    $('transferNotificationButton')?.addEventListener('click', () => window.openSection?.('notificationsSection'));
    $('transferResolveBtn')?.addEventListener('click', resolveRecipient);
    $('transferFavoriteAddBtn')?.addEventListener('click', addTransferFavorite);
    $('transferFavoriteRefreshBtn')?.addEventListener('click', () => loadTransferFavorites(true));
    $('transferReceiverInput')?.addEventListener('input', invalidateTransferReceiver);
    $('transferReceiverInput')?.addEventListener('keydown', (event) => { if (event.key === 'Enter') resolveRecipient(); });
    $('transferAmountNextBtn')?.addEventListener('click', continueTransferAmount);
    $('transferPreviewBtn')?.addEventListener('click', previewTransfer);
    document.querySelectorAll('[data-transfer-back]').forEach((button) => button.addEventListener('click', () => {
      const step = Number(button.dataset.transferBack || 1);
      transferStep(step, { fromHistory: true });
      if (window.history?.replaceState) {
        window.history.replaceState(Object.assign({}, window.history.state || {}, {
          zpayUserApp: { view: 'transfer', step: step }
        }), '', sectionPaths.transferSection);
      }
    }));
    const holdButton = $('transferHoldConfirmBtn');
    ['pointerdown', 'keydown'].forEach((name) => holdButton?.addEventListener(name, startHold));
    ['pointerup', 'pointercancel', 'pointerleave', 'keyup', 'blur'].forEach((name) => holdButton?.addEventListener(name, cancelHold));

    $('supportNewTab')?.addEventListener('click', () => switchSupportTab('new'));
    $('supportListTab')?.addEventListener('click', () => switchSupportTab('list'));
    $('supportOpenRequestsButton')?.addEventListener('click', openSupportEntry);
    $('supportRefreshButton')?.addEventListener('click', () => loadSupportTickets(true));
    $('supportRefreshTopButton')?.addEventListener('click', () => loadSupportTickets(true));
    $('supportStartChatButton')?.addEventListener('click', startSupportChat);
    $('supportCreateForm')?.addEventListener('submit', createSupportTicket);
    $('supportAttachments')?.addEventListener('change', () => updateAttachmentSummary($('supportAttachments'), $('supportAttachmentSummary')));
    $('supportConversationBack')?.addEventListener('click', () => closeSupportConversation());
    $('supportReplyForm')?.addEventListener('submit', replySupport);
    $('supportReplyAttachment')?.addEventListener('change', () => updateAttachmentSummary($('supportReplyAttachment'), $('supportReplyAttachmentSummary')));
    $('supportReplyMessage')?.addEventListener('input', (event) => {
      event.target.style.height = 'auto';
      event.target.style.height = Math.min(130, event.target.scrollHeight) + 'px';
    });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible' && app.support.ticket) startSupportPolling();
      else if (document.visibilityState !== 'visible') stopSupportPolling();
    });

    sectionChanged(document.body.getAttribute('data-active-section') || 'overviewSection');
  }

  window.zpayUserAppSectionChanged = sectionChanged;
  window.zpayUserAppHandlePopState = handleAppPopState;
  window.zpayUserEscapeHtml = escapeHtml;
  window.zpaySupportShowHome = showSupportHome;
  window.zpaySupportShowWorkspace = showSupportWorkspace;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind, { once: true });
  } else {
    bind();
  }
})();
