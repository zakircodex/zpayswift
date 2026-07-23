(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const allowedImages = new Set(['image/jpeg', 'image/png', 'image/webp']);
  let lastModalFocus = null;
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
      holdFrame: 0,
      holdStartedAt: 0
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
    notificationsLoaded: false
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

  async function postForm(action, formData, label) {
    setBusy(true, label || 'Uploading...');
    try {
      const response = await fetch((window.USER_PROXY_URL || '/api/user/proxy.php') + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
        body: formData
      });
      const text = await response.text();
      let json = null;
      try { json = JSON.parse(text); } catch (_) { json = null; }
      if (!response.ok || !json || !json.ok) {
        const error = new Error(String((json && json.message) || 'The request could not be completed.'));
        error.code = String((json && json.code) || 'REQUEST_FAILED');
        if (['SESSION_EXPIRED', 'AUTH_REQUIRED', 'UNAUTHORIZED'].includes(error.code) && typeof window.userSessionExpired === 'function') {
          window.userSessionExpired();
        }
        throw error;
      }
      return json.data || {};
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

  function ensureActionModal() {
    if ($('zpayActionModal')) return $('zpayActionModal');
    const modal = document.createElement('div');
    modal.id = 'zpayActionModal';
    modal.className = 'modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'zpayActionTitle');
    modal.innerHTML = '<div class="zpay-action-dialog">' +
      '<button id="zpayActionClose" class="modal-close" type="button" aria-label="Close">&times;</button>' +
      '<div id="zpayActionBody"></div></div>';
    document.body.appendChild(modal);
    $('zpayActionClose').addEventListener('click', closeActionModal);
    modal.addEventListener('click', (event) => {
      if (event.target === modal) closeActionModal();
    });
    modal.addEventListener('keydown', trapModalFocus);
    return modal;
  }

  function trapModalFocus(event) {
    if (event.key === 'Escape') {
      if (event.currentTarget && event.currentTarget.id === 'notificationModal') {
        closeNotifications();
      } else {
        closeActionModal();
      }
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

  function openActionModal(builder) {
    const modal = ensureActionModal();
    lastModalFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const body = $('zpayActionBody');
    body.replaceChildren();
    builder(body);
    modal.classList.add('show');
    if (typeof window.syncUserModalLock === 'function') window.syncUserModalLock();
    setTimeout(() => body.querySelector('input,button,textarea,select')?.focus(), 0);
  }

  function closeActionModal() {
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
          toast(safeMessage(error, 'Unable to save changes.'), 'error');
          setButtonBusy(submit, false);
        }
      });
      body.append(heading, form);
    });
  }

  function mergeProfile(data) {
    app.profile = Object.assign({}, app.profile || {}, data || {});
    if (window.userState) {
      window.userState.me = Object.assign({}, window.userState.me || {}, app.profile);
    }
    renderProfile();
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
    const pricing = String(profile.pricing_country || '-').toUpperCase();
    const image = safeProfileImage(profile.profile_photo_url);
    if ($('profileName')) $('profileName').textContent = name;
    if ($('profilePhone')) $('profilePhone').textContent = maskPhone(profile.phone);
    if ($('profileEmail')) $('profileEmail').textContent = profile.email || 'No email added';
    if ($('profileRoleBadge')) $('profileRoleBadge').textContent = String(profile.role || 'USER').toUpperCase();
    if ($('profileStatusBadge')) $('profileStatusBadge').textContent = status;
    if ($('profileCountryCurrency')) $('profileCountryCurrency').textContent = pricing + ' pricing - ' + currency + ' wallet';
    if ($('profileUid')) $('profileUid').textContent = profile.uid || '-';
    if ($('profilePhoneCountry')) $('profilePhoneCountry').textContent = String(profile.phone_country || '-').toUpperCase();
    if ($('profilePricingCountry')) $('profilePricingCountry').textContent = pricing;
    if ($('profileWalletCurrency')) $('profileWalletCurrency').textContent = currency;
    if ($('profileCreatedAt')) $('profileCreatedAt').textContent = formatDate(profile.created_at);
    if ($('profileLastLogin')) $('profileLastLogin').textContent = formatDate(profile.last_login_at);
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
      closeActionModal();
      toast('Profile updated successfully.', 'ok');
    });
  }

  function changePassword() {
    openFormModal('Change Password', [
      { name: 'current_password', label: 'Current Password', type: 'password', autocomplete: 'current-password' },
      { name: 'new_password', label: 'New Password', type: 'password', autocomplete: 'new-password' },
      { name: 'confirm_password', label: 'Confirm New Password', type: 'password', autocomplete: 'new-password' }
    ], 'Update Password', async (values) => {
      await post('profile_change_password', values, 'Updating password...');
      closeActionModal();
      showResult('Password Updated', 'Your login password was updated successfully.', 'success');
    });
  }

  function changePin() {
    openFormModal('Change Transaction PIN', [
      { name: 'current_pin', label: 'Current PIN', type: 'password', inputMode: 'numeric', maxLength: 4 },
      { name: 'new_pin', label: 'New 4-digit PIN', type: 'password', inputMode: 'numeric', maxLength: 4 },
      { name: 'confirm_pin', label: 'Confirm New PIN', type: 'password', inputMode: 'numeric', maxLength: 4 }
    ], 'Update PIN', async (values) => {
      await post('profile_change_pin', values, 'Updating PIN...');
      closeActionModal();
      showResult('PIN Updated', 'Your transaction PIN was updated successfully.', 'success');
    });
  }

  async function uploadProfilePhoto(file) {
    if (!file) return;
    if (!allowedImages.has(String(file.type || '').toLowerCase())) {
      toast('Choose a JPG, PNG or WebP image.', 'error');
      return;
    }
    if (file.size <= 0 || file.size > 5 * 1024 * 1024) {
      toast('Profile photo must be 5 MB or smaller.', 'error');
      return;
    }
    const data = new FormData();
    data.append('profile_photo', file, file.name);
    try {
      mergeProfile(await postForm('profile_photo_upload', data, 'Uploading profile photo...'));
      toast('Profile photo updated.', 'ok');
    } catch (error) {
      toast(safeMessage(error, 'Profile photo could not be updated.'), 'error');
    } finally {
      if ($('profilePhotoInput')) $('profilePhotoInput').value = '';
    }
  }

  function transferStep(step, options) {
    const next = Math.max(1, Math.min(4, Number(step || 1)));
    app.transfer.step = next;
    document.querySelectorAll('.transfer-step').forEach((node, index) => node.classList.toggle('active', index + 1 === next));
    for (let index = 1; index <= 4; index += 1) {
      $('transferPill' + index)?.classList.toggle('active', index <= next);
    }
    if (!(options && options.fromHistory) && next > 1 && window.history && window.history.pushState) {
      window.history.pushState(Object.assign({}, window.history.state || {}, {
        zpayUserApp: { view: 'transfer', step: next }
      }), '', sectionPaths.transferSection);
    }
    const focusId = ['transferReceiverInput', 'transferAmountInput', 'transferPinInput'][next - 1];
    if (focusId) {
      setTimeout(() => $(focusId)?.focus(), 0);
    } else {
      window.scrollTo({ top: 0, behavior: 'auto' });
    }
  }

  function renderRecipientCard() {
    const recipient = app.transfer.recipient || {};
    if ($('transferReceiverCard')) {
      $('transferReceiverCard').innerHTML = '<strong>' + escapeHtml(recipient.receiver_name_masked || recipient.receiver_name || 'Z-Pay User') + '</strong>' +
        '<p>' + escapeHtml(recipient.receiver_phone_masked || maskPhone(recipient.receiver_phone)) + '</p>';
    }
    const currency = String(recipient.wallet_currency || recipient.sender_wallet_currency || (window.userState && window.userState.walletSummary && window.userState.walletSummary.wallet_currency) || 'BDT').toUpperCase();
    if ($('transferCurrencyPrefix')) $('transferCurrencyPrefix').textContent = currency === 'MYR' ? 'RM' : currency;
  }

  async function resolveRecipient() {
    const button = $('transferResolveBtn');
    const receiver = String($('transferReceiverInput')?.value || '').trim();
    if (!receiver) {
      showInlineTransfer('Enter the receiver phone number.', true);
      return;
    }
    setButtonBusy(button, true, 'Checking...');
    try {
      const data = await post('transfer_recipient', { recipient_phone: receiver }, 'Checking receiver...', { busy: false });
      const recipient = Object.assign({}, data.recipient || {}, {
        wallet_currency: data.wallet_currency || data.sender_wallet_currency || ''
      });
      if (!data.can_transfer || recipient.can_transfer === false) {
        throw new Error(data.validation_message || 'This account cannot receive this transfer.');
      }
      app.transfer.recipient = recipient;
      showInlineTransfer((recipient.receiver_name_masked || recipient.receiver_name || 'Receiver') + ' verified.', false);
      renderRecipientCard();
      transferStep(2);
    } catch (error) {
      showInlineTransfer(safeMessage(error, 'Receiver could not be verified.'), true);
    } finally {
      setButtonBusy(button, false);
    }
  }

  function showInlineTransfer(message, error) {
    const box = $('transferReceiverResult');
    if (!box) return;
    box.classList.remove('hidden');
    box.classList.toggle('error', !!error);
    box.textContent = message;
  }

  function continueTransferAmount() {
    const amount = Number($('transferAmountInput')?.value || 0);
    if (!Number.isFinite(amount) || amount < 1) {
      toast('Enter an amount of at least 1.00.', 'error');
      return;
    }
    app.transfer.reference = String($('transferReferenceInput')?.value || '').trim();
    transferStep(3);
  }

  async function previewTransfer() {
    const button = $('transferPreviewBtn');
    const pinInput = $('transferPinInput');
    const pin = String(pinInput?.value || '').trim();
    const receiver = String($('transferReceiverInput')?.value || '').trim();
    const amount = Number($('transferAmountInput')?.value || 0);
    if (!/^\d{4}$/.test(pin)) {
      toast('Enter your correct 4-digit transaction PIN.', 'error');
      return;
    }
    setButtonBusy(button, true, 'Preparing...');
    try {
      const preview = await post('transfer_preview', {
        recipient_phone: receiver,
        amount: amount,
        pin: pin
      }, 'Preparing transfer preview...', { busy: false });
      app.transfer.preview = preview;
      if (pinInput) pinInput.value = '';
      renderTransferReview();
      transferStep(4);
    } catch (error) {
      if (pinInput) pinInput.value = '';
      toast(safeMessage(error, 'Transfer preview could not be loaded.'), 'error');
    } finally {
      setButtonBusy(button, false);
    }
  }

  function renderTransferReview() {
    const preview = app.transfer.preview || {};
    const recipient = app.transfer.recipient || {};
    const currency = String(preview.wallet_currency || preview.currency || recipient.wallet_currency || 'BDT').toUpperCase();
    const rows = [
      ['Receiver', preview.receiver_name || recipient.receiver_name_masked || recipient.receiver_name || '-'],
      ['Account', maskPhone(preview.receiver_phone || preview.receiver_account || recipient.receiver_phone)],
      ['Transfer Amount', preview.amount_text || formatMoney(preview.amount, currency)],
      ['Fee', preview.fee_text || formatMoney(preview.fee_amount, currency)],
      ['Total Debit', preview.total_paid_text || preview.total_pay_text || formatMoney(preview.total_debit, currency)],
      ['Remaining Balance', preview.balance_after_text || formatMoney(preview.balance_after, currency)],
      ['Reference', app.transfer.reference || 'No reference']
    ];
    if ($('transferReviewRows')) {
      $('transferReviewRows').innerHTML = rows.map((row) => '<div class="review-row"><span>' + escapeHtml(row[0]) + '</span><strong>' + escapeHtml(row[1]) + '</strong></div>').join('');
    }
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
    try {
      const data = await post('transfer_create', {
        preview_token: token,
        reference: app.transfer.reference
      }, 'Completing transfer...');
      const transfer = data.transfer || {};
      const transferId = String(transfer.transfer_id || transfer.request_id || '');
      resetTransfer();
      if (typeof window.refreshUserDashboard === 'function') {
        window.refreshUserDashboard(false).catch(() => {});
      }
      showResult('Transfer Successful', transferId ? 'Transfer ID: ' + transferId : 'The wallet transfer completed successfully.', 'success', [
        { label: 'View History', action: () => { closeActionModal(); window.openSection?.('historySection'); } },
        { label: 'Done', action: closeActionModal }
      ]);
    } catch (error) {
      showResult('Transfer Not Completed', safeMessage(error, 'No money was moved. Please review and try again.'), 'error');
    } finally {
      app.transfer.submitting = false;
      if (button) button.disabled = false;
      if (label) label.textContent = 'Press & Hold to Transfer';
      cancelHold();
    }
  }

  function resetTransfer() {
    app.transfer.recipient = null;
    app.transfer.preview = null;
    app.transfer.reference = '';
    ['transferReceiverInput', 'transferAmountInput', 'transferReferenceInput', 'transferPinInput'].forEach((id) => {
      if ($(id)) $(id).value = '';
    });
    $('transferReceiverResult')?.classList.add('hidden');
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
    if ($('supportNotice')) $('supportNotice').textContent = config.support_notice || config.average_response_text || 'Create a request or continue an existing conversation.';
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
      if (config.whatsapp_enabled && config.whatsapp_number) {
        links.push({ label: 'WhatsApp', href: 'https://wa.me/' + String(config.whatsapp_number).replace(/\D/g, '') });
      }
      if (config.call_enabled && config.support_phone) {
        links.push({ label: 'Call Support', href: 'tel:' + String(config.support_phone).replace(/[^+\d]/g, '') });
      }
      if (config.email_enabled && config.support_email) {
        links.push({ label: 'Email Support', href: 'mailto:' + encodeURIComponent(String(config.support_email)) });
      }
      links.forEach((item) => {
        const link = document.createElement('a');
        link.className = 'support-contact-action';
        link.textContent = item.label;
        link.href = item.href;
        if (item.href.startsWith('https:')) {
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
        }
        actions.appendChild(link);
      });
      actions.classList.toggle('hidden', !links.length);
    }
    renderRelatedRequests();
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
      return;
    }
    try {
      const data = await get('support_list', { limit: 50 }, 'Loading support requests...', { busy: false });
      app.support.tickets = Array.isArray(data.tickets) ? data.tickets : [];
      renderSupportTickets();
    } catch (error) {
      if ($('supportTicketList')) $('supportTicketList').innerHTML = '<div class="feature-empty-state">Support requests could not be loaded.</div>';
      toast(safeMessage(error, 'Support requests could not be loaded.'), 'error');
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
    const list = tab === 'list';
    $('supportNewTab')?.classList.toggle('active', !list);
    $('supportListTab')?.classList.toggle('active', list);
    $('supportNewTab')?.setAttribute('aria-selected', String(!list));
    $('supportListTab')?.setAttribute('aria-selected', String(list));
    $('supportCreatePanel')?.classList.toggle('active', !list);
    $('supportListPanel')?.classList.toggle('active', list);
    if (list) loadSupportTickets(false);
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
    $('supportHomeView')?.classList.remove('hidden');
    switchSupportTab('list');
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

  function ensureNotificationModal() {
    if ($('notificationModal')) return;
    const modal = document.createElement('div');
    modal.id = 'notificationModal';
    modal.className = 'modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.innerHTML = '<div class="zpay-action-dialog"><button id="notificationClose" class="modal-close" type="button" aria-label="Close">&times;</button>' +
      '<div class="feature-heading"><div><span class="feature-eyebrow">Z-Pay Swift</span><h2>Notifications</h2></div>' +
      '<button id="notificationMarkAll" class="android-secondary-button" type="button">Mark all read</button></div>' +
      '<div id="notificationList" class="notification-list"><div class="feature-empty-state">No notifications loaded.</div></div></div>';
    document.body.appendChild(modal);
    $('notificationClose').addEventListener('click', closeNotifications);
    $('notificationMarkAll').addEventListener('click', markAllNotifications);
    modal.addEventListener('click', (event) => { if (event.target === modal) closeNotifications(); });
    modal.addEventListener('keydown', trapModalFocus);
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
    ['notificationBadge', 'heroNotificationBadge'].forEach((id) => {
      const badge = $(id);
      if (!badge) return;
      badge.textContent = String(Math.min(99, Math.max(0, count)));
      badge.classList.toggle('hidden', count < 1);
    });
  }

  async function openNotifications() {
    ensureNotificationModal();
    const modal = $('notificationModal');
    if (!modal.classList.contains('show')) {
      lastModalFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    }
    modal.classList.add('show');
    if (typeof window.syncUserModalLock === 'function') window.syncUserModalLock();
    setTimeout(() => $('notificationClose')?.focus(), 0);
    try {
      const data = await get('notifications_list', { limit: 30, filter: 'ALL' }, 'Loading notifications...', { busy: false });
      renderNotifications(Array.isArray(data.items) ? data.items : []);
      renderNotificationBadge(Number(data.unread_count || 0));
      app.notificationsLoaded = true;
    } catch (error) {
      if ($('notificationList')) $('notificationList').innerHTML = '<div class="feature-empty-state">Notifications could not be loaded.</div>';
      toast(safeMessage(error, 'Notifications could not be loaded.'), 'error');
    }
  }

  function closeNotifications() {
    $('notificationModal')?.classList.remove('show');
    if (typeof window.syncUserModalLock === 'function') window.syncUserModalLock();
    lastModalFocus?.focus();
    lastModalFocus = null;
  }

  function renderNotifications(items) {
    const list = $('notificationList');
    if (!list) return;
    list.replaceChildren();
    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'feature-empty-state';
      empty.textContent = 'No notifications yet.';
      list.appendChild(empty);
      return;
    }
    items.forEach((item) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'notification-row' + (item.is_read ? '' : ' unread');
      button.innerHTML = '<div><h4>' + escapeHtml(item.title || 'Z-Pay Swift') + '</h4><p>' + escapeHtml(item.body || '') + '</p><p>' + escapeHtml(formatDate(item.created_at)) + '</p></div>' +
        '<span class="status-pill">' + (item.is_read ? 'Read' : 'New') + '</span>';
      if (!item.is_read) {
        button.addEventListener('click', async () => {
          try {
            const data = await post('notification_mark_read', { notification_id: item.notification_id }, 'Updating notification...', { busy: false });
            item.is_read = true;
            renderNotifications(items);
            renderNotificationBadge(Number(data.unread_count || 0));
          } catch (error) {
            toast(safeMessage(error, 'Notification could not be updated.'), 'error');
          }
        });
      }
      list.appendChild(button);
    });
  }

  async function markAllNotifications() {
    try {
      await post('notifications_mark_all_read', {}, 'Updating notifications...', { busy: false });
      renderNotificationBadge(0);
      await openNotifications();
    } catch (error) {
      toast(safeMessage(error, 'Notifications could not be updated.'), 'error');
    }
  }

  function sectionChanged(sectionId) {
    if (sectionId !== 'supportSection') stopSupportPolling();
    if (sectionId === 'profileSection') loadProfile(false);
    if (sectionId === 'supportSection') {
      loadSupportConfig(false);
      loadSupportTickets(false);
    }
    if (sectionId === 'overviewSection') loadUnreadCount();
  }

  function handleAppPopState(event) {
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
      window.openSection?.(sectionButton.getAttribute('data-open-section'));
    });

    $('notificationButton')?.addEventListener('click', openNotifications);
    $('heroNotificationButton')?.addEventListener('click', openNotifications);
    $('profileEditButton')?.addEventListener('click', editProfile);
    $('profileAvatarButton')?.addEventListener('click', () => $('profilePhotoInput')?.click());
    $('profilePhotoInput')?.addEventListener('change', (event) => uploadProfilePhoto(event.target.files && event.target.files[0]));
    $('profileChangePasswordBtn')?.addEventListener('click', changePassword);
    $('profileChangePinBtn')?.addEventListener('click', changePin);
    $('profileCopyUidBtn')?.addEventListener('click', async () => {
      const uid = String(app.profile && app.profile.uid || '');
      if (!uid) return;
      try { await navigator.clipboard.writeText(uid); toast('Account ID copied.', 'ok'); } catch (_) { toast('Account ID could not be copied.', 'error'); }
    });
    $('profileLogoutBtn')?.addEventListener('click', () => $('sidebarLogoutBtn')?.click());

    $('transferResolveBtn')?.addEventListener('click', resolveRecipient);
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
    $('supportRefreshButton')?.addEventListener('click', () => loadSupportTickets(true));
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind, { once: true });
  } else {
    bind();
  }
})();
