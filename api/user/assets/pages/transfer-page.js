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
      previewing: false,
      favorites: [],
      favoritesLoaded: false,
      favoritesLoading: false,
      verifiedInput: '',
      holdFrame: 0,
      holdStartedAt: 0,
      holdPointerId: null,
      holdStartX: 0,
      holdStartY: 0,
      minimumAmount: 1,
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
    let modal = $('transferActionModal');
    if (modal) return modal;
    modal = document.createElement('div');
    modal.id = 'transferActionModal';
    modal.className = 'transfer-action-modal zpay-transfer-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'transferActionTitle');
    modal.setAttribute('aria-hidden', 'true');
    modal.inert = true;
    modal.innerHTML = '<div class="transfer-action-dialog"></div>';
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
    modal.addEventListener('pointerdown', (event) => {
      if (event.target === modal && !app.transfer.modalBusy) closeTransferModal();
    });
    document.body.appendChild(modal);
    return modal;
  }

  function openActionModal(builder, options = {}) {
    const modal = ensureActionModal();
    lastModalFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    modal.className = 'transfer-action-modal zpay-transfer-modal';
    String(options.className || '').split(/\s+/).filter(Boolean).forEach((className) => modal.classList.add(className));
    const dialog = modal.querySelector('.transfer-action-dialog');
    dialog.replaceChildren();
    const body = document.createElement('div');
    body.id = 'transferActionBody';
    dialog.appendChild(body);
    builder(body);
    modal.inert = false;
    modal.setAttribute('aria-hidden', 'false');
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
    else document.querySelector('#transferSection .transfer-scroll-body')?.scrollTo({ top: 0, behavior: 'auto' });
  }

  function transferModalHistory(kind) {
    if (!window.history?.pushState || app.transfer.modalHistoryOpen) return;
    window.history.pushState({ zpayTransferStep: app.transfer.step, zpayTransferModal: kind || 'modal' }, '', '/user/transfer');
    app.transfer.modalHistoryOpen = true;
  }

  function clearTransferModalSurface() {
    const modal = $('transferActionModal');
    modal?.classList.remove('show', 'transfer-loading-modal', 'transfer-result-modal', 'transfer-success-modal', 'transfer-error-modal');
    if (modal) {
      modal.setAttribute('aria-hidden', 'true');
      modal.inert = true;
    }
    $('transferActionBody')?.replaceChildren();
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
    $('transferSection')?.setAttribute('aria-busy', 'false');
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
    $('transferSection')?.setAttribute('aria-busy', 'true');
    openActionModal((body) => {
      const spinner = document.createElement('div');
      spinner.className = 'transfer-spinner';
      const heading = document.createElement('h3');
      heading.id = 'transferActionTitle';
      heading.className = 'transfer-action-title';
      heading.textContent = message || 'Please wait...';
      const copy = document.createElement('p');
      copy.className = 'transfer-action-copy';
      copy.textContent = 'Z-Pay Swift is securely processing your request.';
      body.append(spinner, heading, copy);
    }, { className: 'transfer-loading-modal' });
  }

  function openTransferError(title, message) {
    shell.setBusy(false);
    clearTransferModalSurface();
    app.transfer.modalOpen = true;
    app.transfer.modalBusy = false;
    app.transfer.modalClosing = false;
    transferModalHistory('error');
    $('transferSection')?.setAttribute('aria-busy', 'false');
    openActionModal((body) => {
      const icon = document.createElement('div');
      icon.className = 'transfer-action-icon';
      icon.textContent = '!';
      const heading = document.createElement('h3');
      heading.id = 'transferActionTitle';
      heading.className = 'transfer-action-title';
      heading.textContent = title || 'Transfer Error';
      const copy = document.createElement('p');
      copy.className = 'transfer-action-copy';
      copy.textContent = safeMessage({ message }, 'Transfer could not be processed.');
      const actions = document.createElement('div');
      actions.className = 'transfer-action-buttons';
      const ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'transfer-modal-button primary';
      ok.textContent = 'OK';
      ok.addEventListener('click', () => closeTransferModal());
      actions.appendChild(ok);
      body.append(icon, heading, copy, actions);
    }, { className: 'transfer-result-modal transfer-error-modal' });
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
      toast(safeMessage(error, 'Transfer completed, but the receiver could not be saved as favourite.'), 'error');
    }
  }

  function transferStatusLabel(value) {
    const status = String(value || 'SUCCESS').trim().replace(/_/g, ' ').toLowerCase();
    return status.replace(/\b\w/g, (character) => character.toUpperCase());
  }

  function transferTrackingBaseUrl() {
    const raw = String($('transferSection')?.dataset.trackingBase || '').trim();
    if (!raw) return null;
    try {
      const base = new URL(raw);
      if (!['http:', 'https:'].includes(base.protocol) || base.username || base.password || base.search || base.hash) {
        return null;
      }
      return base;
    } catch (_) {
      return null;
    }
  }

  function transferTrackingUrl(details) {
    const raw = String(details?.tracking_url || details?.receipt_url || '').trim();
    const base = transferTrackingBaseUrl();
    if (!raw || !base) return '';
    try {
      const url = new URL(raw, base.origin);
      const token = String(url.searchParams.get('t') || '').trim();
      const queryKeys = Array.from(url.searchParams.keys());
      if (
        !['http:', 'https:'].includes(url.protocol)
        || url.username
        || url.password
        || url.hash
        || url.origin !== base.origin
        || url.pathname !== base.pathname
        || token === ''
        || token.length > 200
        || queryKeys.length !== 1
        || queryKeys[0] !== 't'
      ) {
        return '';
      }
      return url.href;
    } catch (_) {
      return '';
    }
  }

  async function copyTransferResult(details) {
    const link = transferTrackingUrl(details);
    if (!link) {
      toast('Tracking link is unavailable.', 'error');
      return;
    }
    let fallbackField = null;
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(link);
      } else {
        fallbackField = document.createElement('textarea');
        fallbackField.value = link;
        fallbackField.setAttribute('readonly', '');
        fallbackField.style.position = 'fixed';
        fallbackField.style.opacity = '0';
        document.body.appendChild(fallbackField);
        fallbackField.select();
        if (!document.execCommand('copy')) throw new Error('Copy failed');
      }
      toast('Tracking link copied', 'ok');
    } catch (_) {
      toast('Transfer tracking information could not be copied.', 'error');
    } finally {
      fallbackField?.remove();
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
    $('transferSection')?.setAttribute('aria-busy', 'false');
    openActionModal((body) => {
      const icon = document.createElement('div');
      icon.className = 'transfer-action-icon';
      icon.textContent = '\u2713';
      const heading = document.createElement('h3');
      heading.id = 'transferActionTitle';
      heading.className = 'transfer-action-title';
      heading.textContent = 'Transfer Successful';
      const rows = document.createElement('div');
      rows.className = 'transfer-result-rows';
      [
        ['Receiver', details.receiver_name || details.name || 'Z-Pay User'],
        ['Phone', details.receiver_phone_masked || maskPhone(details.receiver_phone_full || details.receiver_phone || details.receiver_account || '')],
        ['Amount', details.amount_text || formatMoney(details.amount, details.wallet_currency || details.currency)],
        ['Fee', details.fee_text || formatMoney(details.fee_amount || details.fee || 0, details.wallet_currency || details.currency)],
        ['Total Paid', details.total_paid_text || details.total_pay_text || formatMoney(details.total_paid || details.total_pay || details.amount, details.wallet_currency || details.currency)],
        ['Transfer ID', details.transfer_id || details.request_id || '-'],
        ['Status', transferStatusLabel(details.status)]
      ].forEach((row) => {
        const item = document.createElement('div');
        item.className = 'transfer-result-row';
        if (row[0] === 'Transfer ID') item.classList.add('is-long');
        item.innerHTML = `<span>${escapeHtml(row[0])}</span><strong>${escapeHtml(row[1])}</strong>`;
        rows.appendChild(item);
      });
      const trackingCopy = document.createElement('p');
      trackingCopy.className = 'transfer-tracking-copy';
      trackingCopy.textContent = 'This is your transfer tracking link.';
      const actions = document.createElement('div');
      actions.className = 'transfer-action-buttons is-compact';
      const trackingUrl = transferTrackingUrl(details);
      const open = document.createElement(trackingUrl ? 'a' : 'button');
      open.className = 'transfer-modal-button primary';
      open.textContent = 'Open';
      if (trackingUrl) {
        open.href = trackingUrl;
        open.addEventListener('click', () => finishTransferModalClose({ replaceHistory: true }));
      } else {
        open.type = 'button';
        open.disabled = true;
      }
      const copy = document.createElement('button');
      copy.type = 'button';
      copy.className = 'transfer-modal-button';
      copy.textContent = 'Copy';
      copy.disabled = !trackingUrl;
      copy.addEventListener('click', () => copyTransferResult(details));
      actions.append(open, copy);
      if (!isTransferFavoriteSaved(details)) {
        const favorite = document.createElement('button');
        favorite.type = 'button';
        favorite.className = 'transfer-modal-button primary';
        favorite.textContent = 'Favorite';
        favorite.addEventListener('click', () => addTransferFavoriteFromContext(details, favorite));
        actions.appendChild(favorite);
      }
      const done = document.createElement('button');
      done.type = 'button';
      done.className = 'transfer-modal-button';
      done.textContent = 'Done';
      done.addEventListener('click', () => {
        finishTransferModalClose({ replaceHistory: true });
        shell.get('wallet_summary', {}, 'Refreshing wallet...', { busy: false }).catch(() => {});
      });
      actions.append(done);
      body.append(icon, heading, rows, trackingCopy, actions);
    }, { className: 'transfer-result-modal transfer-success-modal' });
  }

  function renderRecipientCard() {
    const recipient = app.transfer.recipient || {};
    const phone = transferRecipientPhone(recipient);
    const currency = String(recipient.wallet_currency || recipient.sender_wallet_currency || 'BDT').toUpperCase();
    if ($('transferReceiverCard')) {
      $('transferReceiverCard').innerHTML =
        '<div class="transfer-verified-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m9.2 16.6-4.1-4.1 1.4-1.4 2.7 2.7 8.3-8.3 1.4 1.4-9.7 9.7Z"/></svg></div>' +
        `<div class="transfer-verified-copy"><strong>${escapeHtml(transferRecipientName(recipient))}</strong>` +
        `<p>${escapeHtml(phone || recipient.receiver_phone_masked || '-')} - Z-Pay</p></div>`;
    }
    if ($('transferCurrencyPrefix')) $('transferCurrencyPrefix').textContent = currency === 'MYR' ? 'RM' : currency;
    const minimum = Number(app.transfer.minimumAmount || 1);
    if ($('transferMinimumHint')) {
      $('transferMinimumHint').textContent = `Minimum transfer amount is ${formatMoney(minimum, currency)}.`;
    }
  }

  function renderTransferVerifySummary() {
    const recipient = app.transfer.recipient || {};
    const currency = String(recipient.wallet_currency || recipient.sender_wallet_currency || 'BDT').toUpperCase();
    const summary = $('transferVerifySummary');
    if (!summary) return;
    summary.innerHTML = '<strong>Z-Pay Transfer</strong>' +
      `<div class="transfer-verify-summary-row"><span>Receiver</span><b>${escapeHtml(transferRecipientName(recipient))}</b></div>` +
      `<div class="transfer-verify-summary-row"><span>Transfer amount</span><b>${escapeHtml(formatMoney($('transferAmountInput')?.value || 0, currency))}</b></div>`;
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
    list.innerHTML = app.transfer.favorites.map((favorite, index) => {
      const id = escapeHtml(favorite.favorite_id || '');
      const title = escapeHtml(favorite.name || favorite.receiver_name || 'Z-Pay User');
      const fullPhone = favorite.phone || favorite.receiver_phone || '';
      const subtitle = escapeHtml((favorite.phone_masked || maskPhone(fullPhone)) + ' - Z-Pay');
      return `<article class="transfer-favorite-item" tabindex="0" role="button" data-favorite-index="${index}">
        <div class="transfer-favorite-avatar" aria-hidden="true">Z</div>
        <div class="transfer-favorite-copy"><strong>${title}</strong><small>${subtitle}</small></div>
        <button class="transfer-favorite-remove" type="button" data-favorite-id="${id}" aria-label="Remove favorite receiver">Remove</button>
      </article>`;
    }).join('');
    list.querySelectorAll('.transfer-favorite-item').forEach((item) => {
      item.addEventListener('click', () => selectTransferFavorite(Number(item.dataset.favoriteIndex || -1)));
      item.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          selectTransferFavorite(Number(item.dataset.favoriteIndex || -1));
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

  async function selectTransferFavorite(index) {
    const favorite = app.transfer.favorites[index];
    const phone = String(favorite?.phone || favorite?.receiver_phone || '').trim();
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
      app.transfer.verifiedInput = transferRecipientPhone(recipient) || receiver;
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
      const data = await postWithFreshCsrf('transfer_preview', {
        recipient_phone: app.transfer.verifiedInput,
        amount,
        check_only: true
      }, 'Checking balance...');
      const minimum = Number(data.minimum_amount || 0);
      if (Number.isFinite(minimum) && minimum > 0) app.transfer.minimumAmount = minimum;
      app.transfer.preview = null;
      finishTransferModalClose({ replaceHistory: true });
      renderTransferVerifySummary();
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
    if (app.transfer.previewing) return;
    app.transfer.previewing = true;
    setButtonBusy(button, true, 'Preparing...');
    openTransferLoading('Preparing preview...');
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
      app.transfer.previewing = false;
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
      ['Phone', maskPhone(preview.receiver_phone || preview.receiver_account || recipient.receiver_phone)],
      ['Amount', preview.amount_text || formatMoney(preview.amount, currency)],
      ['Fee', preview.fee_text || formatMoney(Number.isFinite(fee) ? fee : 0, currency)],
      ['Total Pay', preview.total_paid_text || preview.total_pay_text || formatMoney(preview.total_debit, currency)]
    ];
    if (preview.balance_after_text || preview.balance_after !== undefined) {
      rows.push(['Balance After', preview.balance_after_text || formatMoney(preview.balance_after, currency)]);
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
    app.transfer.holdPointerId = null;
    const button = $('transferHoldConfirmBtn');
    button?.classList.remove('is-holding');
    button?.style.setProperty('--hold-progress', '0%');
    if (!app.transfer.submitting) {
      const label = button?.querySelector('.transfer-hold-label');
      if (label) label.textContent = 'Tap and hold to confirm transfer';
    }
  }

  function startHold(event) {
    if (app.transfer.submitting || !app.transfer.preview || app.transfer.holdStartedAt) return;
    if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    if (event.pointerId !== undefined && event.currentTarget?.setPointerCapture) {
      try { event.currentTarget.setPointerCapture(event.pointerId); } catch (_) {}
    }
    app.transfer.holdPointerId = event.pointerId ?? null;
    app.transfer.holdStartX = Number(event.clientX || 0);
    app.transfer.holdStartY = Number(event.clientY || 0);
    app.transfer.holdStartedAt = performance.now();
    event.currentTarget?.classList.add('is-holding');
    const label = event.currentTarget?.querySelector('.transfer-hold-label');
    if (label) label.textContent = 'Keep holding...';
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

  function moveHold(event) {
    if (!app.transfer.holdStartedAt || event.pointerId !== app.transfer.holdPointerId) return;
    const movedX = Math.abs(Number(event.clientX || 0) - app.transfer.holdStartX);
    const movedY = Math.abs(Number(event.clientY || 0) - app.transfer.holdStartY);
    if (movedX > 12 || movedY > 12) cancelHold();
  }

  async function submitTransfer() {
    const preview = app.transfer.preview || {};
    const token = String(preview.preview_token || '');
    if (!token || app.transfer.submitting) return;
    app.transfer.submitting = true;
    const button = $('transferHoldConfirmBtn');
    const label = button?.querySelector('.transfer-hold-label');
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
        fee_amount: transfer.fee_amount ?? preview.fee_amount ?? 0,
        fee_text: transfer.fee_text || preview.fee_text || formatMoney(0, successBase.wallet_currency),
        total_paid: transfer.total_paid ?? transfer.total_pay ?? preview.total_paid ?? preview.total_pay ?? successBase.amount,
        total_paid_text: transfer.total_paid_text || transfer.total_pay_text || preview.total_paid_text || preview.total_pay_text || successBase.amount_text,
        transfer_id: transfer.transfer_id || transfer.request_id || '',
        reference: transfer.reference || successBase.reference,
        status: transfer.status || 'SUCCESS',
        receipt_url: transfer.receipt_url || '',
        tracking_url: transfer.tracking_url || transfer.receipt_url || ''
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
    app.transfer.minimumAmount = 1;
    ['transferReceiverInput', 'transferAmountInput', 'transferReferenceInput', 'transferPinInput'].forEach((id) => {
      if ($(id)) $(id).value = '';
    });
    transferStep(1, { fromHistory: true });
  }

  function leaveTransferPage() {
    try {
      const previous = new URL(document.referrer || '', window.location.origin);
      if (previous.origin === window.location.origin
        && previous.pathname.startsWith('/user/')
        && !['/user/', '/user/transfer', '/user/z-pay-transfer'].includes(previous.pathname)) {
        window.history.back();
        return;
      }
    } catch (_) {}
    window.location.assign('/user/dashboard');
  }

  function handleTransferBack() {
    if (app.transfer.modalOpen) {
      if (!app.transfer.modalBusy) closeTransferModal();
      return;
    }
    if (app.transfer.step > 1) {
      window.history.back();
      return;
    }
    leaveTransferPage();
  }

  function handlePopState(event) {
    if (app.transfer.modalOpen) {
      if (app.transfer.modalBusy) {
        window.setTimeout(() => window.history.forward(), 0);
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
    $('transferBackButton')?.addEventListener('click', (event) => {
      event.preventDefault();
      handleTransferBack();
    });
    $('transferResolveBtn')?.addEventListener('click', resolveRecipient);
    $('transferFavoriteRefreshBtn')?.addEventListener('click', () => loadTransferFavorites(true));
    $('transferReceiverInput')?.addEventListener('input', invalidateTransferReceiver);
    $('transferReceiverInput')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') resolveRecipient();
    });
    $('transferAmountNextBtn')?.addEventListener('click', continueTransferAmount);
    $('transferAmountInput')?.addEventListener('input', () => {
      app.transfer.preview = null;
    });
    $('transferAmountInput')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') continueTransferAmount();
    });
    $('transferPreviewBtn')?.addEventListener('click', previewTransfer);
    $('transferPinInput')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') previewTransfer();
    });
    const holdButton = $('transferHoldConfirmBtn');
    ['pointerdown', 'keydown'].forEach((name) => holdButton?.addEventListener(name, startHold));
    holdButton?.addEventListener('pointermove', moveHold);
    ['pointerup', 'pointercancel', 'pointerleave', 'keyup', 'blur'].forEach((name) => holdButton?.addEventListener(name, cancelHold));
    holdButton?.addEventListener('contextmenu', (event) => event.preventDefault());
    holdButton?.addEventListener('dragstart', (event) => event.preventDefault());
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
