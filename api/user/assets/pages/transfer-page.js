(() => {
  'use strict';

  const shell = window.UserShell;
  const $ = (id) => document.getElementById(id);
  let lastModalFocus = null;
  const app = {
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
    }
  };

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (character) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
  }

  function safeMessage(error, fallback) {
    const message = String(error?.message || fallback || 'Please try again.').trim();
    if (!message || message.length > 220 || /firebase|exception|stack trace|session[_ -]?token|csrf[_ -]?token|\/api\//i.test(message)) {
      return String(fallback || 'Please try again.');
    }
    return message;
  }

  function transferStatusUnknown(error) {
    const code = String(error?.code || '').toUpperCase();
    const status = Number(error?.status || 0);
    return status === 0 || status >= 500 || [
      'REQUEST_FAILED',
      'TRANSFER_FAILED',
      'TRANSFER_PROCESSING',
      'TRANSFER_STORE_FAILED',
      'TRANSFER_INDEX_FAILED',
      'TRANSFER_RETRYABLE',
      'FINANCIAL_OPERATION_UNAVAILABLE'
    ].includes(code);
  }

  function formatMoney(value, currency) {
    const amount = Number(value || 0);
    const code = String(currency || 'BDT').toUpperCase();
    return `${code === 'MYR' ? 'RM' : code} ${Number.isFinite(amount) ? amount.toFixed(2) : '0.00'}`;
  }

  function maskPhone(value) {
    const phone = String(value || '').trim();
    if (phone.length < 7) return phone || '-';
    return `${phone.slice(0, 4)}***${phone.slice(-3)}`;
  }

  function toast(message, type) {
    shell.toast(message, type || 'info');
  }

  function setButtonBusy(button, busy, busyText) {
    if (!button) return;
    if (busy) {
      button.dataset.originalText = button.textContent;
      button.disabled = true;
      button.textContent = busyText || 'Please wait...';
      return;
    }
    button.disabled = false;
    button.textContent = button.dataset.originalText || button.textContent;
    delete button.dataset.originalText;
  }

  async function postWithFreshCsrf(action, payload, label) {
    try {
      return await shell.post(action, payload || {}, label || 'Processing...', { busy: false });
    } catch (error) {
      const csrfError = Number(error?.status || 0) === 403
        && (String(error?.code || '').toUpperCase().includes('CSRF') || String(error?.message || '').toLowerCase().includes('csrf'));
      if (!csrfError) throw error;
      await shell.refreshSession();
      return shell.post(action, payload || {}, label || 'Processing...', { busy: false });
    }
  }

  function syncModalLock() {
    document.body.classList.toggle('user-modal-open', app.transfer.modalOpen);
  }

  function ensureActionModal() {
    let modal = $('zpayActionModal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'zpayActionModal';
    modal.className = 'modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'zpayActionTitle');
    modal.innerHTML = '<div class="zpay-action-dialog"></div>';
    modal.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !app.transfer.modalBusy) closeTransferModal();
      if (event.key !== 'Tab') return;
      const nodes = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled]), a[href]'))
        .filter((node) => node.offsetParent !== null);
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
    });
    document.body.appendChild(modal);
    return modal;
  }

  function openActionModal(builder, options = {}) {
    const modal = ensureActionModal();
    lastModalFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    modal.className = 'modal';
    String(options.className || '').split(/\s+/).filter(Boolean).forEach((className) => modal.classList.add(className));
    const dialog = modal.querySelector('.zpay-action-dialog');
    dialog.replaceChildren();
    const body = document.createElement('div');
    body.id = 'zpayActionBody';
    dialog.appendChild(body);
    builder(body);
    modal.classList.add('show');
    syncModalLock();
    window.setTimeout(() => body.querySelector('button,input,a[href]')?.focus(), 0);
  }

  function transferDigits(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  function transferRecipientPhone(recipient) {
    return String(recipient?.receiver_phone || recipient?.phone || recipient?.account || recipient?.recipient_phone || '').trim();
  }

  function transferRecipientName(recipient) {
    return String(recipient?.receiver_name || recipient?.name || recipient?.receiver_name_masked || 'Z-Pay User').trim();
  }

  function transferStep(step, options = {}) {
    const next = Math.max(1, Math.min(4, Number(step || 1)));
    const previous = app.transfer.step;
    app.transfer.step = next;
    if (next !== 3 && $('transferPinInput')) $('transferPinInput').value = '';
    if (next < 4) cancelHold();

    document.querySelectorAll('.transfer-step').forEach((node, index) => node.classList.toggle('active', index + 1 === next));
    for (let index = 1; index <= 4; index += 1) {
      $('transferPill' + index)?.classList.toggle('active', index === next);
      $('transferPill' + index)?.classList.toggle('complete', index < next);
    }

    if (!options.fromHistory && next > 1 && next !== previous) {
      window.history.pushState({ zpayTransferStep: next }, '', '/user/transfer');
    }
    const focusId = ['transferReceiverInput', 'transferAmountInput', 'transferPinInput'][next - 1];
    if (focusId) window.setTimeout(() => $(focusId)?.focus(), 0);
    else document.querySelector('#transferSection .transfer-card')?.scrollTo({ top: 0, behavior: 'auto' });
  }

  function transferModalHistory(kind) {
    if (!window.history?.pushState || app.transfer.modalHistoryOpen) return;
    window.history.pushState({ zpayTransferStep: app.transfer.step, zpayTransferModal: kind || 'modal' }, '', '/user/transfer');
    app.transfer.modalHistoryOpen = true;
  }

  function clearTransferModalSurface() {
    const modal = $('zpayActionModal');
    modal?.classList.remove('show', 'zpay-transfer-modal', 'zpay-transfer-loading', 'zpay-transfer-result', 'zpay-transfer-success', 'zpay-transfer-error');
    $('zpayActionBody')?.replaceChildren();
  }

  function finishTransferModalClose(options = {}) {
    app.transfer.modalOpen = false;
    app.transfer.modalBusy = false;
    app.transfer.modalClosing = false;
    if (options.replaceHistory && app.transfer.modalHistoryOpen) {
      window.history.replaceState({ zpayTransferStep: app.transfer.step }, '', '/user/transfer');
    }
    app.transfer.modalHistoryOpen = false;
    clearTransferModalSurface();
    syncModalLock();
    lastModalFocus?.focus?.({ preventScroll: true });
    lastModalFocus = null;
  }

  function closeTransferModal(options = {}) {
    if (!app.transfer.modalOpen || app.transfer.modalBusy) return;
    if (!options.fromHistory && app.transfer.modalHistoryOpen && !app.transfer.modalClosing) {
      app.transfer.modalClosing = true;
      window.history.back();
      return;
    }
    finishTransferModalClose();
  }

  function openTransferLoading(message) {
    shell.setBusy(false);
    clearTransferModalSurface();
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
    }, { className: 'zpay-transfer-modal zpay-transfer-loading' });
  }

  function openTransferError(title, message) {
    shell.setBusy(false);
    clearTransferModalSurface();
    app.transfer.modalOpen = true;
    app.transfer.modalBusy = false;
    app.transfer.modalClosing = false;
    transferModalHistory('error');
    openActionModal((body) => {
      const icon = document.createElement('div');
      icon.className = 'zpay-action-icon';
      icon.textContent = '!';
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
    }, { className: 'zpay-transfer-modal zpay-transfer-result zpay-transfer-error' });
  }

  function isTransferFavoriteSaved(context) {
    const phone = transferDigits(context?.receiver_phone_full || transferRecipientPhone(context) || context?.phone || app.transfer.verifiedInput);
    return Boolean(phone) && app.transfer.favorites.some((item) => transferDigits(item.phone || item.receiver_phone) === phone);
  }

  async function addTransferFavoriteFromContext(context, button) {
    const phone = context?.receiver_phone_full || transferRecipientPhone(context) || context?.phone || '';
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
      toast('Favorite receiver saved.', 'ok');
    } catch (error) {
      setButtonBusy(button, false);
      openTransferError('Favourite Not Saved', safeMessage(error, 'Transfer completed, but the receiver could not be saved as favourite.'));
    }
  }

  function showTransferSuccess(context) {
    shell.setBusy(false);
    clearTransferModalSurface();
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
        ['Account', details.receiver_phone_masked || maskPhone(details.receiver_phone_full || details.receiver_phone || details.receiver_account || '')],
        ['Amount', details.amount_text || formatMoney(details.amount, details.wallet_currency || details.currency)],
        ['Transfer ID', details.transfer_id || details.request_id || '-']
      ].forEach((row) => {
        const item = document.createElement('div');
        item.className = 'zpay-transfer-result-row';
        if (row[0] === 'Transfer ID') item.classList.add('is-long');
        item.innerHTML = `<span>${escapeHtml(row[0])}</span><strong>${escapeHtml(row[1])}</strong>`;
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
      const history = document.createElement('a');
      history.className = 'android-secondary-button transfer-result-link';
      history.href = '/user/history';
      history.textContent = 'View History / Track';
      const done = document.createElement('button');
      done.type = 'button';
      done.className = 'android-primary-button';
      done.textContent = 'Done';
      done.addEventListener('click', () => {
        finishTransferModalClose({ replaceHistory: true });
        shell.get('wallet_summary', {}, 'Refreshing wallet...', { busy: false }).catch(() => {});
      });
      actions.append(history, done);
      body.append(icon, heading, rows, actions);
    }, { className: 'zpay-transfer-modal zpay-transfer-result zpay-transfer-success' });
  }

  function renderRecipientCard() {
    const recipient = app.transfer.recipient || {};
    const phone = transferRecipientPhone(recipient);
    const currency = String(recipient.wallet_currency || recipient.sender_wallet_currency || 'BDT').toUpperCase();
    if ($('transferReceiverCard')) {
      $('transferReceiverCard').innerHTML =
        '<div class="transfer-verified-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9.2 16.6-4.1-4.1 1.4-1.4 2.7 2.7 8.3-8.3 1.4 1.4-9.7 9.7Z"/></svg></div>' +
        `<div class="transfer-verified-copy"><strong>${escapeHtml(transferRecipientName(recipient))}</strong>` +
        `<p>${escapeHtml(recipient.receiver_phone_masked || maskPhone(phone))} - ${escapeHtml(currency)}</p></div>`;
    }
    if ($('transferCurrencyPrefix')) $('transferCurrencyPrefix').textContent = currency === 'MYR' ? 'RM' : currency;
  }

  function renderTransferFavorites(loading) {
    const list = $('transferFavoriteList');
    if (!list) return;
    if (loading) {
      list.innerHTML = '<div class="transfer-empty-card">Loading favorite accounts...</div>';
      return;
    }
    if (!app.transfer.favorites.length) {
      list.innerHTML = '<div class="transfer-empty-card">No favorite accounts yet.</div>';
      return;
    }
    list.innerHTML = app.transfer.favorites.map((favorite) => {
      const id = escapeHtml(favorite.favorite_id || '');
      const phone = escapeHtml(favorite.phone || favorite.receiver_phone || '');
      const title = escapeHtml(favorite.name || favorite.receiver_name || 'Z-Pay User');
      const subtitle = escapeHtml((favorite.phone || favorite.receiver_phone || favorite.phone_masked || '-') + ' - Z-Pay');
      return `<article class="transfer-favorite-item" tabindex="0" role="button" data-favorite-phone="${phone}">
        <div class="transfer-favorite-avatar" aria-hidden="true">Z</div>
        <div class="transfer-favorite-copy"><strong>${title}</strong><small>${subtitle}</small></div>
        <button class="transfer-favorite-remove" type="button" data-favorite-id="${id}" aria-label="Remove favorite receiver">Remove</button>
      </article>`;
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
    if (app.transfer.favoritesLoaded && !force) return renderTransferFavorites(false);
    if (app.transfer.favoritesLoading) return;
    app.transfer.favoritesLoading = true;
    renderTransferFavorites(true);
    try {
      const data = await shell.get('transfer_favorites', { limit: 10 }, 'Loading favorite accounts...', { busy: false });
      app.transfer.favorites = Array.isArray(data.favorites) ? data.favorites : [];
      app.transfer.favoritesLoaded = true;
      renderTransferFavorites(false);
    } catch (_) {
      app.transfer.favoritesLoaded = false;
      if ($('transferFavoriteList')) $('transferFavoriteList').innerHTML = '<div class="transfer-empty-card error">Favorite accounts could not be loaded.</div>';
    } finally {
      app.transfer.favoritesLoading = false;
    }
  }

  function invalidateTransferReceiver() {
    if (!app.transfer.recipient) return;
    if (transferDigits($('transferReceiverInput')?.value || '') === transferDigits(app.transfer.verifiedInput)) return;
    app.transfer.recipient = null;
    app.transfer.preview = null;
    app.transfer.reference = '';
    app.transfer.verifiedInput = '';
  }

  async function selectTransferFavorite(phone) {
    if (!$('transferReceiverInput') || !phone) return;
    $('transferReceiverInput').value = phone;
    app.transfer.recipient = null;
    app.transfer.preview = null;
    app.transfer.verifiedInput = '';
    await resolveRecipient();
  }

  async function removeTransferFavorite(favoriteId) {
    const id = String(favoriteId || '').trim();
    if (!id || !window.confirm('Remove this favorite receiver?')) return;
    try {
      await postWithFreshCsrf('transfer_favorite_remove', { favorite_id: id }, 'Removing favorite...');
      app.transfer.favoritesLoaded = false;
      await loadTransferFavorites(true);
      toast('Favorite receiver removed.', 'ok');
    } catch (error) {
      toast(safeMessage(error, 'Favorite receiver could not be removed.'), 'error');
    }
  }

  async function resolveRecipient() {
    const button = $('transferResolveBtn');
    const receiver = String($('transferReceiverInput')?.value || '').trim();
    if (!receiver) return openTransferError('Receiver Required', 'Enter the receiver phone number.');
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
      return transferStep(1);
    }
    const amount = Number($('transferAmountInput')?.value || 0);
    if (!Number.isFinite(amount) || amount < 1) return openTransferError('Invalid Amount', 'Enter an amount of at least 1.00.');
    if (app.transfer.amountChecking) return;
    app.transfer.amountChecking = true;
    setButtonBusy($('transferAmountNextBtn'), true, 'Checking...');
    openTransferLoading('Checking balance...');
    try {
      await postWithFreshCsrf('transfer_preview', {
        recipient_phone: app.transfer.verifiedInput,
        amount,
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
    if (!app.transfer.recipient) {
      openTransferError('Receiver Required', 'Verify the receiver first.');
      return transferStep(1);
    }
    if (!/^\d{4}$/.test(pin)) return openTransferError('PIN Required', 'Enter your correct 4-digit transaction PIN.');
    setButtonBusy(button, true, 'Preparing...');
    openTransferLoading('Loading transfer preview...');
    try {
      const preview = await shell.post('transfer_preview', {
        recipient_phone: app.transfer.verifiedInput,
        amount: Number($('transferAmountInput')?.value || 0),
        pin
      }, 'Preparing transfer preview...', { busy: false });
      app.transfer.preview = preview;
      pinInput.value = '';
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
    const fee = Number(preview.fee_amount || preview.fee || 0);
    const rows = [
      ['Receiver', preview.receiver_name || recipient.receiver_name || recipient.name || '-'],
      ['Account', maskPhone(preview.receiver_phone || preview.receiver_account || recipient.receiver_phone)],
      ['Amount', preview.amount_text || formatMoney(preview.amount, currency)],
      ['Fee', preview.fee_text || formatMoney(Number.isFinite(fee) ? fee : 0, currency)],
      ['Total Amount', preview.total_paid_text || preview.total_pay_text || formatMoney(preview.total_debit, currency)]
    ];
    if (preview.balance_after_text || preview.balance_after !== undefined) {
      rows.push(['Remaining Balance', preview.balance_after_text || formatMoney(preview.balance_after, currency)]);
    }
    $('transferReviewRows').innerHTML = rows
      .map((row) => `<div class="review-row"><span>${escapeHtml(row[0])}</span><strong>${escapeHtml(row[1])}</strong></div>`)
      .join('');
    $('transferReferenceInput').value = app.transfer.reference || '';
  }

  function cancelHold() {
    if (app.transfer.holdFrame) cancelAnimationFrame(app.transfer.holdFrame);
    app.transfer.holdFrame = 0;
    app.transfer.holdStartedAt = 0;
    $('transferHoldConfirmBtn')?.style.setProperty('--hold-progress', '0%');
  }

  function startHold(event) {
    if (app.transfer.submitting || !app.transfer.preview || app.transfer.holdStartedAt) return;
    if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    if (event.pointerId !== undefined && event.currentTarget?.setPointerCapture) {
      try { event.currentTarget.setPointerCapture(event.pointerId); } catch (_) {}
    }
    app.transfer.holdStartedAt = performance.now();
    const tick = (now) => {
      if (!app.transfer.holdStartedAt) return;
      const progress = Math.min(1, (now - app.transfer.holdStartedAt) / 2300);
      $('transferHoldConfirmBtn')?.style.setProperty('--hold-progress', `${(progress * 100).toFixed(1)}%`);
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
    const receiverFullPhone = preview.receiver_phone || transferRecipientPhone(recipient) || app.transfer.verifiedInput;
    const successBase = {
      receiver_name: preview.receiver_name || recipient.receiver_name || recipient.name || 'Z-Pay User',
      receiver_phone_full: receiverFullPhone,
      receiver_phone: receiverFullPhone,
      receiver_phone_masked: recipient.receiver_phone_masked || maskPhone(receiverFullPhone),
      amount: preview.amount || $('transferAmountInput')?.value || 0,
      amount_text: preview.amount_text || formatMoney(preview.amount, preview.wallet_currency || recipient.wallet_currency),
      wallet_currency: preview.wallet_currency || preview.currency || recipient.wallet_currency || 'BDT',
      reference: app.transfer.reference
    };
    openTransferLoading('Submitting transfer...');
    try {
      const data = await shell.post('transfer_create', {
        preview_token: token,
        reference: app.transfer.reference
      }, 'Completing transfer...', { busy: false });
      const transfer = data.transfer || {};
      const context = Object.assign({}, successBase, transfer, {
        receiver_name: transfer.receiver_name || successBase.receiver_name,
        receiver_phone_full: successBase.receiver_phone_full,
        receiver_phone: successBase.receiver_phone_full,
        receiver_phone_masked: transfer.receiver_account || transfer.receiver_phone_masked || successBase.receiver_phone_masked,
        amount_text: transfer.amount_text || transfer.total_paid_text || successBase.amount_text,
        wallet_currency: transfer.wallet_currency || transfer.currency || successBase.wallet_currency,
        transfer_id: transfer.transfer_id || transfer.request_id || '',
        reference: transfer.reference || successBase.reference
      });
      finishTransferModalClose({ replaceHistory: true });
      resetTransfer();
      app.transfer.favoritesLoaded = false;
      loadTransferFavorites(true).catch(() => {});
      showTransferSuccess(context);
    } catch (error) {
      finishTransferModalClose({ replaceHistory: true });
      const uncertain = transferStatusUnknown(error);
      openTransferError(
        uncertain ? 'Transfer Status Unknown' : 'Transfer Not Completed',
        uncertain
          ? 'Transfer status could not be confirmed. Please check History before trying again.'
          : safeMessage(error, 'Transfer could not be completed. Please review again.')
      );
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
    transferStep(1, { fromHistory: true });
  }

  function handlePopState(event) {
    if (app.transfer.modalOpen) {
      if (app.transfer.modalBusy) {
        window.history.pushState({ zpayTransferStep: app.transfer.step, zpayTransferModal: 'loading' }, '', '/user/transfer');
        app.transfer.modalHistoryOpen = true;
        return;
      }
      closeTransferModal({ fromHistory: true });
      return;
    }
    const stateStep = Number(event.state?.zpayTransferStep || 0);
    if (stateStep > 0) {
      transferStep(stateStep, { fromHistory: true });
      return;
    }
    if (app.transfer.step > 1) {
      transferStep(app.transfer.step - 1, { fromHistory: true });
    }
  }

  function bind() {
    $('transferResolveBtn')?.addEventListener('click', resolveRecipient);
    $('transferFavoriteRefreshBtn')?.addEventListener('click', () => loadTransferFavorites(true));
    $('transferReceiverInput')?.addEventListener('input', invalidateTransferReceiver);
    $('transferReceiverInput')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') resolveRecipient();
    });
    $('transferAmountNextBtn')?.addEventListener('click', continueTransferAmount);
    $('transferPreviewBtn')?.addEventListener('click', previewTransfer);
    document.querySelectorAll('[data-transfer-back]').forEach((button) => {
      button.addEventListener('click', () => transferStep(Number(button.dataset.transferBack || 1), { fromHistory: true }));
    });
    const holdButton = $('transferHoldConfirmBtn');
    ['pointerdown', 'keydown'].forEach((name) => holdButton?.addEventListener(name, startHold));
    ['pointerup', 'pointercancel', 'pointerleave', 'keyup', 'blur'].forEach((name) => holdButton?.addEventListener(name, cancelHold));
    window.addEventListener('popstate', handlePopState);
  }

  async function init() {
    await shell.ready;
    bind();
    window.history.replaceState({ zpayTransferStep: 1 }, '', '/user/transfer');
    loadTransferFavorites(false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
