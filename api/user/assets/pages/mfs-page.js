(() => {
  'use strict';

  const root = document.getElementById('mfsSection');
  const shell = window.UserShell;
  if (!root || !shell || root.dataset.mfsBound === 'true') return;
  root.dataset.mfsBound = 'true';

  const byId = (id) => document.getElementById(id);
  const config = window.USER_MFS_CONFIG && typeof window.USER_MFS_CONFIG === 'object'
    ? window.USER_MFS_CONFIG
    : {};
  const provider = String(config.provider || root.dataset.provider || 'BKASH').toUpperCase() === 'NAGAD'
    ? 'NAGAD'
    : 'BKASH';
  const providerLabel = provider === 'NAGAD' ? 'Nagad' : 'bKash';
  const STEP_ORDER = ['receiver', 'amount', 'pin', 'preview'];
  const HOLD_DURATION_MS = 2300;

  const state = {
    step: 'receiver',
    receiverFull: '',
    amountBdt: 0,
    amountMyr: 0,
    syncingAmount: false,
    pin: '',
    reference: '',
    walletSummary: null,
    preview: null,
    requestBusy: false,
    submitting: false,
    completed: false,
    finishingFlow: false,
    result: null,
    uid: '',
    favorites: [],
    favoriteStorageKey: '',
    afterModalClose: null,
    modal: {
      open: false,
      busy: false,
      kind: '',
      opener: null,
      hasHistory: false,
      closeResolver: null
    },
    hold: {
      startedAt: 0,
      frame: 0,
      pointerId: null,
      startX: 0,
      startY: 0,
      completed: false
    },
    keyboard: {
      activeInput: null,
      restoreScrollTop: 0,
      baselineHeight: Math.max(window.innerHeight || 0, window.visualViewport?.height || 0),
      timer: 0,
      restoreTimer: 0
    }
  };

  function normalizeNumber(value) {
    let digits = String(value || '').replace(/\D+/g, '');
    if (digits.startsWith('880')) digits = `0${digits.slice(3)}`;
    if (digits.length === 10 && digits.startsWith('1')) digits = `0${digits}`;
    return digits;
  }

  function validBdNumber(value) {
    return /^01\d{9}$/.test(normalizeNumber(value));
  }

  function maskNumber(value) {
    const number = normalizeNumber(value);
    if (number.length < 8) return number || '-';
    return `${number.slice(0, 4)}***${number.slice(-3)}`;
  }

  function numberValue(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function money(value) {
    return numberValue(value).toFixed(2);
  }

  function linkedAmountText(value) {
    const amount = numberValue(value);
    if (amount <= 0) return '';
    const rounded = Math.round((amount + Number.EPSILON) * 100) / 100;
    return rounded.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1');
  }

  function normalizeCurrency(value) {
    const currency = String(value || '').trim().toUpperCase();
    return ['MYR', 'RM', 'MY'].includes(currency) ? 'MYR' : 'BDT';
  }

  function walletDetails() {
    const summary = state.walletSummary || {};
    const wallet = summary.wallet || {};
    const currency = normalizeCurrency(wallet.display_currency || wallet.wallet_currency || wallet.currency || 'BDT');
    const rate = numberValue(wallet.rate_myr_bdt || wallet.rate_myr_to_bdt || 0);
    return { currency, rate, isMyr: currency === 'MYR' };
  }

  function safeMessage(error, fallback = 'Request could not be completed. Please try again.') {
    const code = String(error?.code || '').trim().toUpperCase();
    const message = String(error?.message || '').trim();
    if (['WRONG_PIN', 'INVALID_PIN'].includes(code)) return 'Incorrect PIN. Please try again.';
    if (code === 'INSUFFICIENT_BALANCE') {
      return walletDetails().isMyr ? 'Insufficient MYR balance.' : 'Insufficient BDT balance.';
    }
    if (code === 'MFS_PREVIEW_EXPIRED') return 'This preview has expired. Please review again.';
    if (['MFS_PREVIEW_INVALID', 'MFS_PREVIEW_MISMATCH'].includes(code)) {
      return 'This preview is no longer valid. Please review again.';
    }
    if (code === 'PROVIDER_DISABLED') return `${providerLabel} is currently unavailable.`;
    if (code === 'MFS_DISABLED') return 'bKash/Nagad service is currently unavailable.';
    if (!message || /firebase|exception|stack|session[_ -]?token|csrf|\/api\//i.test(message)) return fallback;
    return message;
  }

  function isPinError(error) {
    const code = String(error?.code || '').toUpperCase();
    return ['WRONG_PIN', 'INVALID_PIN'].includes(code) || /\bpin\b/i.test(String(error?.message || ''));
  }

  function clearNode(node) {
    if (node) node.replaceChildren();
  }

  function summaryMarkup(number, detail) {
    const wrapper = document.createDocumentFragment();
    const icon = document.createElement('span');
    icon.className = 'mfs-summary-icon';
    icon.textContent = provider === 'NAGAD' ? 'N' : 'bK';
    const copy = document.createElement('span');
    copy.className = 'mfs-summary-copy';
    const title = document.createElement('strong');
    title.textContent = providerLabel;
    const subtitle = document.createElement('span');
    subtitle.textContent = `${maskNumber(number)}${detail ? ` - ${detail}` : ''}`;
    copy.append(title, subtitle);
    wrapper.append(icon, copy);
    return wrapper;
  }

  function renderAmountContext() {
    const summary = byId('mfsAmountSummary');
    if (summary) {
      clearNode(summary);
      summary.classList.add('mfs-amount-selection-summary');
      const text = document.createElement('strong');
      text.textContent = `${providerLabel} \u2022 ${state.receiverFull}`;
      summary.appendChild(text);
    }

    const wallet = walletDetails();
    const rateCard = byId('mfsRateCard');
    const rateText = byId('mfsRateText');
    const myrField = byId('mfsAmountMyrField');
    const myrInput = byId('mfsAmountMyr');
    rateCard?.classList.toggle('hidden', !wallet.isMyr);
    rateCard?.setAttribute('aria-hidden', wallet.isMyr ? 'false' : 'true');
    myrField?.classList.toggle('hidden', !wallet.isMyr);
    myrField?.setAttribute('aria-hidden', wallet.isMyr ? 'false' : 'true');
    if (myrInput) myrInput.disabled = !wallet.isMyr || wallet.rate <= 0;
    if (rateText) {
      rateText.textContent = wallet.isMyr && wallet.rate > 0
        ? `Rate: RM 1 = ${linkedAmountText(wallet.rate)} BDT`
        : wallet.isMyr ? 'Rate is currently unavailable' : '';
    }
    if (!wallet.isMyr && myrInput) {
      myrInput.value = '';
      state.amountMyr = 0;
    }
  }

  function syncAmountInputs(source) {
    if (state.syncingAmount) return;
    const bdtInput = byId('mfsAmountBdt');
    const myrInput = byId('mfsAmountMyr');
    const wallet = walletDetails();
    state.syncingAmount = true;
    try {
      if (source === 'MYR') {
        const amountMyr = numberValue(myrInput?.value);
        state.amountMyr = amountMyr;
        state.amountBdt = wallet.isMyr && wallet.rate > 0 ? amountMyr * wallet.rate : 0;
        if (bdtInput) bdtInput.value = linkedAmountText(state.amountBdt);
      } else {
        const amountBdt = numberValue(bdtInput?.value);
        state.amountBdt = amountBdt;
        state.amountMyr = wallet.isMyr && wallet.rate > 0 ? amountBdt / wallet.rate : 0;
        if (myrInput) myrInput.value = wallet.isMyr ? linkedAmountText(state.amountMyr) : '';
      }
    } finally {
      state.syncingAmount = false;
    }
    invalidatePreview();
  }

  function renderPinSummary() {
    const summary = byId('mfsPinSummary');
    if (!summary) return;
    const preview = state.preview || {};
    const walletCurrency = normalizeCurrency(preview.wallet_currency || walletDetails().currency);
    const detail = walletCurrency === 'MYR'
      ? `BDT ${money(preview.amount_bdt)} - ${String(preview.total_debit_text || `RM ${money(preview.total_pay_myr || preview.total_debit)}`)}`
      : `BDT ${money(preview.amount_bdt)}`;
    clearNode(summary);
    summary.appendChild(summaryMarkup(state.receiverFull, detail));
  }

  function displayMoney(value, currency) {
    return normalizeCurrency(currency) === 'MYR' ? `RM ${money(value)}` : `BDT ${money(value)}`;
  }

  function previewFeeText(preview) {
    if (preview.fee_currency || preview.fee_amount !== undefined) {
      return displayMoney(preview.fee_amount, preview.fee_currency || preview.wallet_currency);
    }
    return normalizeCurrency(preview.wallet_currency) === 'MYR'
      ? `RM ${money(preview.fee_rm)}`
      : `BDT ${money(preview.fee_bdt)}`;
  }

  function previewTotalText(preview) {
    const text = String(preview.total_debit_text || preview.total_pay_text || '').trim();
    if (text) return text;
    return displayMoney(preview.total_pay ?? preview.total_debit, preview.wallet_currency);
  }

  function addDataRow(parent, label, value, total = false, result = false) {
    const row = document.createElement('div');
    row.className = `${result ? 'mfs-result-row' : 'mfs-preview-row'}${total ? ' total' : ''}`;
    const labelNode = document.createElement('span');
    labelNode.textContent = label;
    const valueNode = document.createElement('strong');
    valueNode.textContent = String(value || '-');
    row.append(labelNode, valueNode);
    parent.appendChild(row);
  }

  function renderPreview() {
    const rows = byId('mfsPreviewRows');
    const preview = state.preview || {};
    if (!rows) return;
    clearNode(rows);
    const isMyr = normalizeCurrency(preview.wallet_currency) === 'MYR'
      || String(preview.service_mode || '').toUpperCase() === 'REMITTANCE';
    if (isMyr) {
      addDataRow(rows, 'BDT Amount', `BDT ${money(preview.amount_bdt)}`);
      addDataRow(rows, 'MYR Amount', `RM ${money(preview.amount_rm || preview.amount_myr)}`);
      addDataRow(rows, 'Rate', `RM 1 = ${money(preview.exchange_rate)} BDT`);
    } else {
      addDataRow(rows, 'BDT Amount', `BDT ${money(preview.amount_bdt)}`);
    }
    addDataRow(rows, 'Fee', previewFeeText(preview));
    addDataRow(rows, 'Total Pay', previewTotalText(preview), true);
    addDataRow(rows, 'Balance After', String(preview.balance_after_debit_text || displayMoney(preview.balance_after_debit, preview.wallet_currency)));
  }

  function historyPayload(step = state.step, modal = '') {
    return {
      ...(history.state && typeof history.state === 'object' ? history.state : {}),
      zpayMfs: { provider, step, modal }
    };
  }

  function applyStep(nextStep, { focus = true } = {}) {
    if (!STEP_ORDER.includes(nextStep)) return;
    const previous = state.step;
    state.step = nextStep;
    document.querySelectorAll('.user-mfs-page [data-mfs-step]').forEach((node) => {
      const active = node.getAttribute('data-mfs-step') === nextStep;
      node.classList.toggle('active', active);
      node.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    byId('mfsScrollBody')?.scrollTo({ top: 0, behavior: 'auto' });

    if (nextStep === 'amount') renderAmountContext();
    if (nextStep === 'pin') renderPinSummary();
    if (nextStep === 'preview') renderPreview();

    if (previous === 'preview' && nextStep === 'pin') clearPin();
    if ((previous === 'pin' || previous === 'preview') && STEP_ORDER.indexOf(nextStep) < 2) clearPin();

    if (!focus) return;
    const targets = {
      receiver: 'mfsReceiverNumber',
      amount: 'mfsAmountBdt',
      pin: 'mfsPin'
    };
    const targetId = targets[nextStep];
    if (targetId) window.setTimeout(() => byId(targetId)?.focus({ preventScroll: true }), 50);
  }

  function navigateStep(nextStep, mode = 'push') {
    if (!STEP_ORDER.includes(nextStep)) return;
    if (mode === 'replace') {
      history.replaceState(historyPayload(nextStep), '', window.location.href);
    } else if (nextStep !== state.step) {
      history.pushState(historyPayload(nextStep), '', window.location.href);
    }
    applyStep(nextStep);
  }

  function clearPin() {
    state.pin = '';
    const pin = byId('mfsPin');
    if (pin) pin.value = '';
  }

  function invalidatePreview() {
    state.preview = null;
    state.completed = false;
    state.result = null;
    clearPin();
    cancelHold();
  }

  function modalElement() {
    return byId('mfsActionModal');
  }

  function hideModalImmediate({ restoreFocus = true } = {}) {
    const modal = modalElement();
    const opener = state.modal.opener;
    state.modal.open = false;
    state.modal.busy = false;
    state.modal.kind = '';
    state.modal.opener = null;
    state.modal.hasHistory = false;
    modal?.classList.remove('show', 'is-loading', 'is-error', 'is-success', 'is-dismissible');
    modal?.setAttribute('aria-hidden', 'true');
    if (modal && 'inert' in modal) modal.inert = true;
    document.querySelector('.user-mfs-page .mfs-modal-feedback')?.remove();
    document.body.classList.remove('mfs-modal-open');
    root.setAttribute('aria-busy', 'false');
    if (restoreFocus && opener instanceof HTMLElement) {
      window.setTimeout(() => opener.focus({ preventScroll: true }), 0);
    }
  }

  function openModal({
    kind,
    title,
    message,
    rows = [],
    actions = [],
    opener = document.activeElement,
    pushHistory = true,
    dismissible = kind !== 'loading'
  }) {
    const modal = modalElement();
    if (!modal) return;
    const wasOpen = state.modal.open;
    if (wasOpen) hideModalImmediate({ restoreFocus: false });
    if (!wasOpen && pushHistory) {
      history.pushState(historyPayload(state.step, kind), '', window.location.href);
      state.modal.hasHistory = true;
    } else {
      state.modal.hasHistory = false;
    }
    state.modal.open = true;
    state.modal.busy = kind === 'loading';
    state.modal.kind = kind;
    state.modal.opener = opener instanceof HTMLElement ? opener : null;
    modal.className = `mfs-action-modal show is-${kind}${dismissible ? ' is-dismissible' : ''}`;
    modal.setAttribute('aria-hidden', 'false');
    if ('inert' in modal) modal.inert = false;
    document.querySelector('.user-mfs-page .mfs-modal-feedback')?.remove();
    document.body.classList.add('mfs-modal-open');
    root.setAttribute('aria-busy', kind === 'loading' ? 'true' : 'false');
    byId('mfsModalTitle').textContent = String(title || pageTitle());
    byId('mfsModalMessage').textContent = String(message || '');
    const icon = byId('mfsModalIcon');
    if (icon) icon.textContent = kind === 'success' ? '\u2713' : kind === 'error' ? '!' : '';

    const body = byId('mfsModalBody');
    clearNode(body);
    rows.forEach((row) => addDataRow(body, row.label, row.value, Boolean(row.total), true));

    const actionWrap = byId('mfsModalActions');
    clearNode(actionWrap);
    actionWrap?.style.setProperty('--mfs-action-count', String(Math.max(1, actions.length)));
    actions.forEach((action) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `mfs-modal-action${action.primary ? ' primary' : ''}`;
      button.textContent = action.label;
      button.disabled = Boolean(action.disabled);
      if (action.id) button.id = action.id;
      button.addEventListener('click', action.handler);
      actionWrap?.appendChild(button);
    });

    window.setTimeout(() => {
      const focusTarget = actionWrap?.querySelector('button:not(:disabled)') || byId('mfsModalClose');
      focusTarget?.focus({ preventScroll: true });
    }, 0);
  }

  function pageTitle() {
    return `${providerLabel} Send Money`;
  }

  function requestModalClose(callback = null) {
    if (!state.modal.open || state.modal.busy) return;
    if (typeof callback === 'function') state.afterModalClose = callback;
    if (state.modal.hasHistory) {
      history.back();
    } else {
      hideModalImmediate();
      const after = state.afterModalClose;
      state.afterModalClose = null;
      if (typeof after === 'function') after();
    }
  }

  function openLoading(message, opener) {
    openModal({
      kind: 'loading',
      title: pageTitle(),
      message,
      opener,
      pushHistory: true
    });
  }

  function closeLoading() {
    if (!state.modal.open || state.modal.kind !== 'loading') return Promise.resolve();
    if (!state.modal.hasHistory) {
      hideModalImmediate({ restoreFocus: false });
      return Promise.resolve();
    }
    return new Promise((resolve) => {
      state.modal.busy = false;
      state.modal.closeResolver = resolve;
      history.back();
    });
  }

  function openError(title, message, opener = document.activeElement) {
    openModal({
      kind: 'error',
      title,
      message,
      opener,
      actions: [{ label: 'OK', primary: true, handler: () => requestModalClose() }]
    });
  }

  async function runWithLoading(message, opener, task) {
    openLoading(message, opener);
    try {
      const result = await task();
      await closeLoading();
      return result;
    } catch (error) {
      await closeLoading();
      throw error;
    }
  }

  async function ensureWalletSummary() {
    if (state.walletSummary) return state.walletSummary;
    state.walletSummary = await shell.get('wallet_summary', {}, 'Loading wallet...', { busy: false });
    return state.walletSummary;
  }

  function favoriteFallbackName(number) {
    const clean = normalizeNumber(number);
    return `${providerLabel} ${clean.slice(-4) || 'Number'}`;
  }

  function loadFavorites() {
    state.favorites = [];
    if (!state.favoriteStorageKey) return;
    try {
      const parsed = JSON.parse(window.localStorage.getItem(state.favoriteStorageKey) || '[]');
      if (!Array.isArray(parsed)) return;
      const seen = new Set();
      state.favorites = parsed.filter((item) => {
        const number = normalizeNumber(item?.number);
        if (!validBdNumber(number) || seen.has(number)) return false;
        seen.add(number);
        item.number = number;
        item.name = String(item.name || favoriteFallbackName(number)).slice(0, 60);
        return true;
      }).slice(0, 10);
    } catch (_) {
      state.favorites = [];
    }
  }

  function saveFavorites() {
    if (!state.favoriteStorageKey) return false;
    try {
      window.localStorage.setItem(state.favoriteStorageKey, JSON.stringify(state.favorites.slice(0, 10)));
      return true;
    } catch (_) {
      return false;
    }
  }

  function favoriteExists(number) {
    const normalized = normalizeNumber(number);
    return state.favorites.some((item) => normalizeNumber(item.number) === normalized);
  }

  function renderFavorites() {
    const list = byId('mfsFavoriteList');
    if (!list) return;
    clearNode(list);
    if (!state.favorites.length) {
      const empty = document.createElement('div');
      empty.className = 'mfs-empty-state';
      empty.textContent = 'No favorite numbers yet.';
      list.appendChild(empty);
      return;
    }
    state.favorites.forEach((favorite) => {
      const item = document.createElement('div');
      item.className = 'mfs-favorite-item';
      const select = document.createElement('button');
      select.type = 'button';
      select.className = 'mfs-favorite-select';
      const name = document.createElement('strong');
      name.textContent = favorite.name || favoriteFallbackName(favorite.number);
      const details = document.createElement('span');
      details.textContent = `${maskNumber(favorite.number)} - ${providerLabel}`;
      select.append(name, details);
      select.addEventListener('click', () => {
        const input = byId('mfsReceiverNumber');
        if (input) input.value = favorite.number;
        state.receiverFull = favorite.number;
        invalidatePreview();
        continueFromReceiver(select);
      });

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'mfs-favorite-remove';
      remove.setAttribute('aria-label', `Remove ${favorite.name || 'favorite number'}`);
      remove.textContent = '×';
      remove.addEventListener('click', () => {
        openModal({
          kind: 'error',
          title: 'Remove Favorite?',
          message: `Remove ${favorite.name || maskNumber(favorite.number)} from your favorites?`,
          opener: remove,
          actions: [
            { label: 'Cancel', handler: () => requestModalClose() },
            {
              label: 'Remove',
              primary: true,
              handler: () => requestModalClose(() => {
                state.favorites = state.favorites.filter((row) => normalizeNumber(row.number) !== normalizeNumber(favorite.number));
                saveFavorites();
                renderFavorites();
              })
            }
          ]
        });
      });
      item.append(select, remove);
      list.appendChild(item);
    });
  }

  function addFavorite(number) {
    const normalized = normalizeNumber(number);
    if (!validBdNumber(normalized)) return { ok: false, message: 'A valid receiver number is required.' };
    if (favoriteExists(normalized)) return { ok: true, duplicate: true };
    if (state.favorites.length >= 10) return { ok: false, message: 'You can save up to 10 favorite numbers.' };
    state.favorites.unshift({
      name: favoriteFallbackName(normalized),
      number: normalized,
      provider,
      created_at: Date.now()
    });
    if (!saveFavorites()) {
      state.favorites.shift();
      return { ok: false, message: 'Favorite number could not be saved in this browser.' };
    }
    renderFavorites();
    return { ok: true, duplicate: false };
  }

  async function continueFromReceiver(opener = byId('mfsReceiverContinue')) {
    if (state.requestBusy) return;
    const input = byId('mfsReceiverNumber');
    const normalized = normalizeNumber(input?.value);
    if (!validBdNumber(normalized)) {
      openError('Invalid Receiver', 'Receiver number must be a valid 11 digit Bangladesh mobile number.', opener);
      return;
    }
    if (state.receiverFull && state.receiverFull !== normalized) invalidatePreview();
    state.receiverFull = normalized;
    if (input) input.value = normalized;
    state.requestBusy = true;
    try {
      await runWithLoading('Checking receiver...', opener, async () => ensureWalletSummary());
      renderAmountContext();
      navigateStep('amount');
    } catch (error) {
      openError('Receiver Check Failed', safeMessage(error, 'Receiver could not be checked. Please try again.'), opener);
    } finally {
      state.requestBusy = false;
    }
  }

  function validAmount() {
    const wallet = walletDetails();
    if (wallet.isMyr && wallet.rate <= 0) {
      openError('Rate Unavailable', 'The current MYR to BDT rate could not be loaded. Please try again.', byId('mfsAmountContinue'));
      return false;
    }
    const value = numberValue(byId('mfsAmountBdt')?.value);
    if (value < 500 || value > 100000) {
      openError('Invalid Amount', 'Amount must be between BDT 500 and BDT 100,000.', byId('mfsAmountContinue'));
      return false;
    }
    state.amountBdt = value;
    state.amountMyr = wallet.isMyr ? numberValue(byId('mfsAmountMyr')?.value) : 0;
    return true;
  }

  function previewPayload() {
    return {
      provider,
      service_type: 'SEND_MONEY',
      account_type: 'PERSONAL',
      receiver_number: state.receiverFull,
      currency: 'BDT',
      amount: state.amountBdt,
      amount_bdt: state.amountBdt,
      amount_rm: 0,
      amount_myr: 0,
      reference: state.reference
    };
  }

  async function continueFromAmount(opener = byId('mfsAmountContinue')) {
    if (state.requestBusy || !validAmount()) return;
    state.requestBusy = true;
    try {
      const preview = await runWithLoading('Checking balance...', opener, async () => (
        window.proxyPost('mfs_preview', previewPayload(), 'Checking balance...', { busy: false })
      ));
      if (!preview || !String(preview.preview_token || '').trim()) {
        throw Object.assign(new Error(`${providerLabel} preview could not be secured. Please try again.`), { code: 'MFS_PREVIEW_FAILED' });
      }
      if (preview.can_submit === false || String(preview.validation_code || '').toUpperCase() === 'INSUFFICIENT_BALANCE') {
        throw Object.assign(new Error(String(preview.validation_message || 'Insufficient available balance.')), { code: 'INSUFFICIENT_BALANCE' });
      }
      state.preview = preview;
      state.amountBdt = numberValue(preview.amount_bdt || state.amountBdt);
      state.amountMyr = numberValue(preview.amount_rm || preview.amount_myr || state.amountMyr);
      const bdtInput = byId('mfsAmountBdt');
      const myrInput = byId('mfsAmountMyr');
      if (bdtInput) bdtInput.value = linkedAmountText(state.amountBdt);
      if (myrInput && walletDetails().isMyr) myrInput.value = linkedAmountText(state.amountMyr);
      renderPinSummary();
      navigateStep('pin');
    } catch (error) {
      openError('Balance Check Failed', safeMessage(error, 'Balance could not be checked. Please try again.'), opener);
    } finally {
      state.requestBusy = false;
    }
  }

  async function continueFromPin(opener = byId('mfsPinContinue')) {
    if (state.requestBusy) return;
    const pin = String(byId('mfsPin')?.value || '').trim();
    if (!/^\d{4,6}$/.test(pin)) {
      clearPin();
      openError('Invalid PIN', 'Please enter a valid transaction PIN.', opener);
      return;
    }
    if (!state.preview?.preview_token) {
      clearPin();
      openError('Preview Expired', 'This preview has expired. Please review the amount again.', opener);
      return;
    }
    state.requestBusy = true;
    state.pin = pin;
    try {
      await runWithLoading('Preparing preview...', opener, async () => (
        window.proxyPost('validate_pin', { purpose: 'TOPUP', pin }, 'Preparing preview...', { busy: false })
      ));
      renderPreview();
      navigateStep('preview');
      const pinInput = byId('mfsPin');
      if (pinInput) pinInput.value = '';
    } catch (error) {
      clearPin();
      const title = isPinError(error) ? 'Incorrect PIN' : 'Verification Failed';
      openError(title, safeMessage(error, 'PIN could not be verified. Please try again.'), opener);
    } finally {
      state.requestBusy = false;
    }
  }

  function createPayload() {
    const serverPreview = state.preview || {};
    return {
      provider,
      service_type: 'SEND_MONEY',
      account_type: 'PERSONAL',
      receiver_number: state.receiverFull,
      preview_token: serverPreview.preview_token,
      source: 'USER_API',
      amount_bdt: state.amountBdt,
      reference: state.reference,
      pin: state.pin
    };
  }

  function statusLabel(status) {
    const value = String(status || 'PENDING').trim().toUpperCase();
    if (['PENDING', 'WAITING_ADMIN', 'QUEUED', 'SUBMITTED'].includes(value)) return 'Pending';
    if (value === 'SUCCESSFUL') return 'Successful';
    if (value === 'PROCESSING') return 'Processing';
    if (value === 'FAILED') return 'Failed';
    return value.replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
  }

  function canonicalTrackingUrl(result) {
    const configuredBase = String(root.dataset.trackingBase || '').trim();
    if (!configuredBase) return '';
    try {
      const base = new URL(configuredBase, window.location.origin);
      const token = String(result?.receipt_token || '').trim();
      if (/^[A-Za-z0-9_-]{24,128}$/.test(token)) {
        base.search = '';
        base.searchParams.set('t', token);
        return base.toString();
      }
      const supplied = String(result?.tracking_url || result?.receipt_url || '').trim();
      if (!supplied) return '';
      const candidate = new URL(supplied, base.origin);
      const candidateToken = String(candidate.searchParams.get('t') || '').trim();
      if (candidate.origin !== base.origin || candidate.pathname !== base.pathname || !/^[A-Za-z0-9_-]{24,128}$/.test(candidateToken)) return '';
      base.search = '';
      base.searchParams.set('t', candidateToken);
      return base.toString();
    } catch (_) {
      return '';
    }
  }

  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return;
    }
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    const copied = document.execCommand('copy');
    textarea.remove();
    if (!copied) throw new Error('Tracking link could not be copied.');
  }

  function modalFeedback(message, error = false) {
    const existing = document.querySelector('.user-mfs-page .mfs-modal-feedback');
    existing?.remove();
    const node = document.createElement('div');
    node.className = 'mfs-modal-feedback';
    node.textContent = message;
    if (error) node.style.color = '#ff9aaa';
    byId('mfsModalActions')?.insertAdjacentElement('afterend', node);
    window.setTimeout(() => node.remove(), 2600);
  }

  function resetFlow() {
    state.receiverFull = '';
    state.amountBdt = 0;
    state.amountMyr = 0;
    state.reference = '';
    state.preview = null;
    state.result = null;
    state.completed = false;
    state.finishingFlow = false;
    clearPin();
    cancelHold();
    ['mfsReceiverNumber', 'mfsAmountMyr', 'mfsAmountBdt', 'mfsReference'].forEach((id) => {
      const node = byId(id);
      if (node) node.value = '';
    });
    history.replaceState(historyPayload('receiver'), '', window.location.href);
    applyStep('receiver', { focus: false });
  }

  function finishSuccessFlow() {
    if (state.finishingFlow || !state.modal.open || state.modal.kind !== 'success') return;
    state.finishingFlow = true;
    const stepDepth = Math.max(0, STEP_ORDER.indexOf(state.step));
    const historyDistance = stepDepth + (state.modal.hasHistory ? 1 : 0);
    state.afterModalClose = resetFlow;

    if (historyDistance > 0) {
      history.go(-historyDistance);
      return;
    }

    hideModalImmediate({ restoreFocus: false });
    const after = state.afterModalClose;
    state.afterModalClose = null;
    if (typeof after === 'function') after();
  }

  function openSuccess(result) {
    state.result = result;
    const isMyr = normalizeCurrency(result.wallet_currency) === 'MYR'
      || String(result.service_mode || '').toUpperCase() === 'REMITTANCE';
    const rows = [
      { label: 'Provider', value: result.provider_name || providerLabel },
      { label: 'Request ID', value: result.request_id || '-' },
      { label: 'Number', value: maskNumber(result.receiver_number || state.receiverFull) },
      { label: 'Amount in BDT', value: `BDT ${money(result.amount_bdt || state.amountBdt)}` }
    ];
    if (isMyr) rows.push({ label: 'MYR Amount', value: `RM ${money(result.amount_rm || result.amount_myr)}` });
    rows.push(
      { label: 'Fee', value: previewFeeText(result) },
      { label: 'Total Pay', value: String(result.total_debit_text || result.total_pay_text || displayMoney(result.total_pay ?? result.total_debit, result.wallet_currency)), total: true },
      { label: 'Status', value: statusLabel(result.status) }
    );
    const trackingUrl = canonicalTrackingUrl(result);
    const fullNumber = normalizeNumber(result.receiver_number || state.receiverFull);
    const alreadyFavorite = favoriteExists(fullNumber);
    openModal({
      kind: 'success',
      title: `${providerLabel} Request Submitted`,
      message: 'This is your tracking link. You can open it or copy it for later.',
      rows,
      opener: byId('mfsHoldConfirm'),
      dismissible: false,
      actions: [
        {
          label: 'Open',
          primary: true,
          disabled: !trackingUrl,
          handler: () => {
            if (trackingUrl) window.open(trackingUrl, '_blank', 'noopener,noreferrer');
          }
        },
        {
          label: 'Copy',
          disabled: !trackingUrl,
          handler: async () => {
            try {
              await copyText(trackingUrl);
              modalFeedback('Tracking link copied');
            } catch (error) {
              modalFeedback(safeMessage(error, 'Tracking link could not be copied.'), true);
            }
          }
        },
        {
          id: 'mfsFavoriteResultAction',
          label: alreadyFavorite ? 'Saved' : 'Favorite',
          primary: true,
          disabled: alreadyFavorite,
          handler: () => {
            const saved = addFavorite(fullNumber);
            if (!saved.ok) {
              modalFeedback(saved.message || 'Favorite could not be saved.', true);
              return;
            }
            const button = byId('mfsFavoriteResultAction');
            if (button) {
              button.textContent = 'Saved';
              button.disabled = true;
            }
            modalFeedback(saved.duplicate ? 'Already saved' : 'Favorite saved');
          }
        },
        { label: 'Done', handler: finishSuccessFlow }
      ]
    });
  }

  async function submitRequest() {
    if (state.submitting || state.completed) return;
    if (!state.preview?.preview_token || !state.pin) {
      clearPin();
      openError('Verification Required', 'Please verify this request again before submitting.', byId('mfsHoldConfirm'));
      return;
    }
    state.submitting = true;
    const button = byId('mfsHoldConfirm');
    if (button) button.disabled = true;
    setHoldLabel('Submitting...');
    state.reference = String(byId('mfsReference')?.value || '').trim().slice(0, 80);
    try {
      const result = await runWithLoading(`Submitting ${providerLabel} request...`, button, async () => (
        window.proxyPost('mfs_create', createPayload(), `Submitting ${providerLabel} request...`, { busy: false })
      ));
      state.completed = true;
      clearPin();
      if (result?.wallet) {
        state.walletSummary = state.walletSummary || {};
        state.walletSummary.wallet = { ...(state.walletSummary.wallet || {}), ...result.wallet };
      }
      openSuccess(result || {});
    } catch (error) {
      clearPin();
      cancelHold();
      if (isPinError(error)) {
        navigateStep('pin');
        openError('Incorrect PIN', safeMessage(error, 'Incorrect PIN. Please try again.'), button);
      } else {
        openError(`${providerLabel} Request Failed`, safeMessage(error, 'Request could not be submitted. Please try again.'), button);
      }
    } finally {
      state.submitting = false;
      if (button && !state.completed) button.disabled = false;
      if (!state.completed) resetHoldVisual();
    }
  }

  function setHoldLabel(label) {
    const node = byId('mfsHoldConfirm')?.querySelector('.mfs-hold-label');
    if (node) node.textContent = label;
  }

  function setHoldProgress(value) {
    const button = byId('mfsHoldConfirm');
    if (!button) return;
    const progress = Math.max(0, Math.min(100, numberValue(value)));
    button.style.setProperty('--hold-progress', `${progress}%`);
  }

  function stopHoldTimers() {
    if (state.hold.frame) cancelAnimationFrame(state.hold.frame);
    state.hold.frame = 0;
  }

  function resetHoldVisual() {
    const button = byId('mfsHoldConfirm');
    button?.classList.remove('is-holding');
    setHoldProgress(0);
    setHoldLabel(`Tap and hold to confirm ${providerLabel}`);
  }

  function cancelHold() {
    stopHoldTimers();
    state.hold.startedAt = 0;
    state.hold.pointerId = null;
    state.hold.completed = false;
    if (!state.submitting && !state.completed) resetHoldVisual();
  }

  function startHold(event) {
    if (state.submitting || state.completed || !state.preview?.preview_token || state.hold.startedAt) return;
    if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    if (event.pointerId !== undefined && event.currentTarget?.setPointerCapture) {
      try { event.currentTarget.setPointerCapture(event.pointerId); } catch (_) {}
    }
    state.hold.pointerId = event.pointerId ?? null;
    state.hold.startX = numberValue(event.clientX);
    state.hold.startY = numberValue(event.clientY);
    state.hold.startedAt = performance.now();
    state.hold.completed = false;
    event.currentTarget?.classList.add('is-holding');
    setHoldLabel('Keep holding...');
    const tick = (now) => {
      if (!state.hold.startedAt) return;
      const progress = Math.min(1, (now - state.hold.startedAt) / HOLD_DURATION_MS);
      setHoldProgress(progress * 100);
      if (progress >= 1) {
        state.hold.startedAt = 0;
        state.hold.frame = 0;
        state.hold.completed = true;
        if (navigator.vibrate) navigator.vibrate(35);
        submitRequest();
        return;
      }
      state.hold.frame = requestAnimationFrame(tick);
    };
    state.hold.frame = requestAnimationFrame(tick);
  }

  function moveHold(event) {
    if (!state.hold.startedAt) return;
    const movedX = Math.abs(numberValue(event.clientX) - state.hold.startX);
    const movedY = Math.abs(numberValue(event.clientY) - state.hold.startY);
    if (movedX > 12 || movedY > 12) cancelHold();
  }

  function isKeyboardInput(node) {
    return node instanceof HTMLInputElement && ['mfsReceiverNumber', 'mfsAmountMyr', 'mfsAmountBdt', 'mfsPin', 'mfsReference'].includes(node.id);
  }

  function keyboardInset() {
    const viewport = window.visualViewport;
    if (!viewport) return 0;
    state.keyboard.baselineHeight = Math.max(state.keyboard.baselineHeight, window.innerHeight || 0);
    const covered = state.keyboard.baselineHeight - viewport.height - viewport.offsetTop;
    return covered > 90 ? Math.max(0, Math.round(covered)) : 0;
  }

  function keepFocusedControlsVisible(input) {
    const body = byId('mfsScrollBody');
    if (!body || !input) return;
    const button = ['mfsAmountMyr', 'mfsAmountBdt'].includes(input.id)
      ? byId('mfsAmountContinue')
      : input.id === 'mfsPin'
        ? byId('mfsPinContinue')
        : null;
    const first = input.closest('.mfs-field') || input;
    const last = button || input;
    const bodyRect = body.getBoundingClientRect();
    const firstRect = first.getBoundingClientRect();
    const lastRect = last.getBoundingClientRect();
    const padding = 10;
    const visibleTop = bodyRect.top + padding;
    const visibleBottom = bodyRect.bottom - padding;
    const contentSpan = lastRect.bottom - firstRect.top;
    const availableSpan = visibleBottom - visibleTop;
    let nextScrollTop = body.scrollTop;

    if (contentSpan <= availableSpan) {
      nextScrollTop += firstRect.top - visibleTop;
    } else if (firstRect.top < visibleTop) {
      nextScrollTop += firstRect.top - visibleTop;
    } else if (lastRect.bottom > visibleBottom) {
      nextScrollTop += lastRect.bottom - visibleBottom;
    }

    body.scrollTo({ top: Math.max(0, nextScrollTop), behavior: 'smooth' });
  }

  function applyKeyboardLayout() {
    state.keyboard.timer = 0;
    const input = state.keyboard.activeInput;
    if (!isKeyboardInput(input) || document.activeElement !== input) return;
    const inset = keyboardInset();
    document.body.style.setProperty('--mfs-keyboard-inset', `${inset ? inset + 34 : 0}px`);
    document.body.classList.toggle('mfs-keyboard-open', inset > 0);
    keepFocusedControlsVisible(input);
  }

  function scheduleKeyboardLayout(delay = 50) {
    if (state.keyboard.timer) window.clearTimeout(state.keyboard.timer);
    state.keyboard.timer = window.setTimeout(applyKeyboardLayout, delay);
  }

  function resetKeyboardLayout({ restoreScroll = false } = {}) {
    if (state.keyboard.timer) window.clearTimeout(state.keyboard.timer);
    if (state.keyboard.restoreTimer) window.clearTimeout(state.keyboard.restoreTimer);
    state.keyboard.timer = 0;
    state.keyboard.restoreTimer = 0;
    document.body.classList.remove('mfs-keyboard-open');
    document.body.style.setProperty('--mfs-keyboard-inset', '0px');
    if (restoreScroll) {
      const body = byId('mfsScrollBody');
      body?.scrollTo({ top: state.keyboard.restoreScrollTop, behavior: 'smooth' });
    }
    state.keyboard.activeInput = null;
  }

  function handleFocusIn(event) {
    if (!isKeyboardInput(event.target)) return;
    state.keyboard.activeInput = event.target;
    state.keyboard.restoreScrollTop = byId('mfsScrollBody')?.scrollTop || 0;
    state.keyboard.baselineHeight = Math.max(
      state.keyboard.baselineHeight,
      window.innerHeight || 0,
      window.visualViewport?.height || 0
    );
    scheduleKeyboardLayout(0);
    window.setTimeout(() => scheduleKeyboardLayout(0), 220);
  }

  function handleFocusOut() {
    if (state.keyboard.restoreTimer) window.clearTimeout(state.keyboard.restoreTimer);
    state.keyboard.restoreTimer = window.setTimeout(() => {
      state.keyboard.restoreTimer = 0;
      if (isKeyboardInput(document.activeElement)) return;
      resetKeyboardLayout({ restoreScroll: false });
    }, 180);
  }

  function handlePopState(event) {
    if (state.modal.open && state.modal.busy) {
      history.pushState(historyPayload(state.step, state.modal.kind), '', window.location.href);
      return;
    }

    if (state.modal.open) hideModalImmediate({ restoreFocus: false });
    const resolver = state.modal.closeResolver;
    state.modal.closeResolver = null;
    if (typeof resolver === 'function') resolver();

    const after = state.afterModalClose;
    state.afterModalClose = null;
    const targetStep = String(event.state?.zpayMfs?.step || '');
    if (STEP_ORDER.includes(targetStep)) applyStep(targetStep, { focus: false });
    if (typeof after === 'function') window.setTimeout(after, 0);
  }

  function bindEvents() {
    byId('mfsReceiverContinue')?.addEventListener('click', (event) => continueFromReceiver(event.currentTarget));
    byId('mfsAmountContinue')?.addEventListener('click', (event) => continueFromAmount(event.currentTarget));
    byId('mfsPinContinue')?.addEventListener('click', (event) => continueFromPin(event.currentTarget));
    byId('mfsReceiverNumber')?.addEventListener('input', () => {
      const current = normalizeNumber(byId('mfsReceiverNumber')?.value);
      if (state.receiverFull && current !== state.receiverFull) invalidatePreview();
    });
    byId('mfsAmountMyr')?.addEventListener('input', () => syncAmountInputs('MYR'));
    byId('mfsAmountBdt')?.addEventListener('input', () => syncAmountInputs('BDT'));
    byId('mfsReference')?.addEventListener('input', () => {
      state.reference = String(byId('mfsReference')?.value || '').slice(0, 80);
    });
    byId('mfsReceiverNumber')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        continueFromReceiver(byId('mfsReceiverContinue'));
      }
    });
    byId('mfsAmountBdt')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        continueFromAmount(byId('mfsAmountContinue'));
      }
    });
    byId('mfsAmountMyr')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        byId('mfsAmountBdt')?.focus();
      }
    });
    byId('mfsPin')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        continueFromPin(byId('mfsPinContinue'));
      }
    });

    const hold = byId('mfsHoldConfirm');
    hold?.addEventListener('pointerdown', startHold);
    hold?.addEventListener('pointermove', moveHold);
    hold?.addEventListener('pointerup', () => { if (!state.hold.completed) cancelHold(); });
    hold?.addEventListener('pointercancel', cancelHold);
    hold?.addEventListener('lostpointercapture', () => { if (!state.hold.completed) cancelHold(); });
    hold?.addEventListener('keydown', startHold);
    hold?.addEventListener('keyup', (event) => {
      if (['Enter', ' '].includes(event.key)) {
        event.preventDefault();
        if (!state.hold.completed) cancelHold();
      }
    });
    hold?.addEventListener('contextmenu', (event) => event.preventDefault());
    hold?.addEventListener('dragstart', (event) => event.preventDefault());

    byId('mfsModalClose')?.addEventListener('click', () => requestModalClose());
    document.querySelector('[data-mfs-modal-close]')?.addEventListener('click', () => requestModalClose());
    document.addEventListener('focusin', handleFocusIn);
    document.addEventListener('focusout', handleFocusOut);
    window.addEventListener('popstate', handlePopState);
    window.visualViewport?.addEventListener('resize', () => scheduleKeyboardLayout(0));
    window.visualViewport?.addEventListener('scroll', () => scheduleKeyboardLayout(0));
    window.addEventListener('resize', () => {
      if (isKeyboardInput(document.activeElement)) scheduleKeyboardLayout(0);
      else state.keyboard.baselineHeight = Math.max(window.innerHeight || 0, window.visualViewport?.height || 0);
    });
    window.addEventListener('pagehide', () => {
      stopHoldTimers();
      resetKeyboardLayout({ restoreScroll: false });
      if (state.modal.kind === 'loading') hideModalImmediate({ restoreFocus: false });
    });
    window.addEventListener('pageshow', (event) => {
      if (!event.persisted) return;
      if (state.modal.kind === 'loading') hideModalImmediate({ restoreFocus: false });
      state.requestBusy = false;
      state.submitting = false;
      resetHoldVisual();
    });

    byId('mfsBackButton')?.addEventListener('click', (event) => {
      if (state.modal.open) {
        event.preventDefault();
        requestModalClose();
        return;
      }
      if (state.step !== 'receiver') {
        event.preventDefault();
        history.back();
      }
    });
  }

  async function init() {
    root.setAttribute('aria-busy', 'true');
    try {
      await shell.ready;
      state.uid = String(shell.state.user?.uid || shell.state.bootstrapData?.user?.uid || '').trim();
      state.favoriteStorageKey = state.uid
        ? `zpay_mfs_favorites_v1_${state.uid}_${provider}`
        : `zpay_mfs_favorites_v1_session_${provider}`;
      loadFavorites();
      renderFavorites();
      bindEvents();
      history.replaceState(historyPayload('receiver'), '', window.location.href);
      applyStep('receiver', { focus: false });
    } catch (error) {
      if (!shell.isSessionError(error)) {
        openError(`${providerLabel} Unavailable`, safeMessage(error, 'Send Money could not be loaded.'));
      }
    } finally {
      root.setAttribute('aria-busy', 'false');
    }
  }

  init();
})();
