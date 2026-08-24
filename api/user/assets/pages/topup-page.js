(() => {
  'use strict';

  const shell = window.UserShell;
  const root = document.getElementById('topupSection');
  if (!shell || !root) return;

  const byId = (id) => document.getElementById(id);
  const HOLD_DURATION_MS = 2300;
  const STEP_ORDER = ['number', 'amount', 'pin', 'preview'];
  const COUNTRIES = {
    BD: {
      code: 'BD',
      name: 'Bangladesh',
      dialCode: '+880',
      currency: 'BDT',
      minAmount: 20,
      maxAmount: 1000,
      presets: [20, 50, 100, 200, 500, 1000],
      operators: [
        { code: 'GP', name: 'Grameenphone', prefixes: ['017', '013'] },
        { code: 'ROBI', name: 'Robi', prefixes: ['018'] },
        { code: 'AIRTEL', name: 'Airtel', prefixes: ['016'] },
        { code: 'BL', name: 'Banglalink', prefixes: ['019', '014'] },
        { code: 'TT', name: 'Teletalk', prefixes: ['015'] },
        { code: 'SKITTO', name: 'Skitto', prefixes: ['013'] },
        { code: 'OTHER', name: 'Other Operator', prefixes: [] }
      ]
    },
    MY: {
      code: 'MY',
      name: 'Malaysia',
      dialCode: '+60',
      currency: 'BDT',
      minAmount: 20,
      maxAmount: 1000,
      presets: [20, 50, 100, 200, 500, 1000],
      operators: [
        { code: 'CELCOM_XPAX', name: 'Celcom Xpax', prefixes: [] },
        { code: 'DIGI', name: 'Digi', prefixes: [] },
        { code: 'HOTLINK', name: 'Hotlink', prefixes: [] },
        { code: 'MAXIS', name: 'Maxis', prefixes: [] },
        { code: 'UMOBILE', name: 'U Mobile', prefixes: [] },
        { code: 'XOX', name: 'XOX', prefixes: [] },
        { code: 'TUNETALK', name: 'Tune Talk', prefixes: [] },
        { code: 'YES', name: 'YES Prepaid', prefixes: [] }
      ]
    }
  };

  const state = {
    step: 'number',
    countryCode: 'BD',
    numberFull: '',
    operatorCode: '',
    operatorName: '',
    operatorSource: '',
    amount: '',
    amountCheck: null,
    preview: null,
    verified: false,
    requestBusy: false,
    submitting: false,
    completed: false,
    walletSummary: null,
    uid: '',
    favorites: [],
    favoriteStorageKey: '',
    success: null,
    suppressNumberInput: false,
    resetPending: false,
    afterModalClose: null,
    modal: {
      open: false,
      busy: false,
      kind: '',
      hasHistory: false,
      opener: null
    },
    keyboard: {
      activeInput: null,
      baselineHeight: Math.max(window.innerHeight || 0, window.visualViewport?.height || 0),
      restoreScrollTop: 0,
      restoreStep: 'number',
      layoutTimer: 0,
      restoreTimer: 0
    },
    hold: {
      active: false,
      completed: false,
      pointerId: null,
      startX: 0,
      startY: 0,
      startedAt: 0,
      timer: 0,
      animationFrame: 0
    }
  };

  function country() {
    return COUNTRIES[state.countryCode] || COUNTRIES.BD;
  }

  function operatorByCode(code = state.operatorCode) {
    const clean = String(code || '').trim().toUpperCase();
    return country().operators.find((operator) => operator.code === clean) || null;
  }

  function stepIndex(step = state.step) {
    return Math.max(0, STEP_ORDER.indexOf(step));
  }

  function historyPayload(step = state.step, modal = '') {
    return {
      ...(history.state && typeof history.state === 'object' ? history.state : {}),
      zpayTopup: { step, modal }
    };
  }

  function clearNode(node) {
    while (node?.firstChild) node.removeChild(node.firstChild);
  }

  function createNode(tag, className = '', text = '') {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '') node.textContent = String(text);
    return node;
  }

  function normalizeNumber(raw, code = state.countryCode) {
    let digits = String(raw || '').replace(/\D+/g, '');
    if (code === 'BD') {
      if (digits.startsWith('880')) digits = `0${digits.slice(3)}`;
      if (digits.length === 10 && digits.startsWith('1')) digits = `0${digits}`;
      return digits;
    }
    if (code === 'MY') {
      if (digits.startsWith('60')) digits = `0${digits.slice(2)}`;
      return digits;
    }
    return digits;
  }

  function isValidNumber(number, code = state.countryCode) {
    const clean = normalizeNumber(number, code);
    if (code === 'BD') return /^01[3-9]\d{8}$/.test(clean);
    if (code === 'MY') return /^01\d{7,9}$/.test(clean);
    return false;
  }

  function invalidNumberMessage(code = state.countryCode) {
    if (code === 'BD') {
      return 'Bangladesh number must be 11 digits, for example 01712345678.';
    }
    return 'Please enter a valid Malaysia mobile number.';
  }

  function operatorCandidates(number) {
    const clean = normalizeNumber(number);
    if (state.countryCode !== 'BD') return [];
    return country().operators.filter((operator) => operator.prefixes.some((prefix) => clean.startsWith(prefix)));
  }

  function maskNumber(number) {
    const clean = String(number || '').replace(/\s+/g, '');
    if (clean.length <= 7) return clean || '-';
    return `${clean.slice(0, 3)}${'*'.repeat(Math.min(5, clean.length - 6))}${clean.slice(-3)}`;
  }

  function formatAmount(value, currencyCode) {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '-';
    const currency = String(currencyCode || '').toUpperCase();
    if (currency === 'MYR') return `RM ${amount.toFixed(2)}`;
    return `${Number.isInteger(amount) ? amount.toFixed(0) : amount.toFixed(2)} ${currency || 'BDT'}`;
  }

  function statusLabel(value) {
    const status = String(value || 'PENDING').trim().toUpperCase();
    if (status === 'WAITING_ADMIN' || status === 'PENDING') return 'Pending';
    return status.toLowerCase().replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
  }

  function safeErrorMessage(error, fallback = 'Top-up request could not be completed.') {
    const code = String(error?.code || '').toUpperCase();
    const mapped = {
      INVALID_PIN: 'Incorrect PIN. Please try again.',
      WRONG_PIN: 'Incorrect PIN. Please try again.',
      TOPUP_PREVIEW_REQUIRED: 'Top-up preview expired. Please review again.',
      TOPUP_PREVIEW_INVALID: 'Top-up preview expired. Please review again.',
      TOPUP_PREVIEW_EXPIRED: 'Top-up preview expired. Please review again.',
      TOPUP_ALREADY_SUBMITTED: 'This top-up request was already submitted. Please check History.',
      TOPUP_OPERATOR_UNSUPPORTED: 'Selected operator is not supported.',
      TOPUP_OPERATOR_DISABLED: 'This operator is currently unavailable.',
      TOPUP_OPERATOR_NOT_READY: 'This operator is not ready for top-up yet.',
      TOPUP_ACCOUNT_DISABLED: 'Mobile top-up is disabled for this account.',
      INSUFFICIENT_BALANCE: 'Insufficient balance. Please add money first.'
    };
    if (mapped[code]) return mapped[code];
    const message = String(error?.message || '').trim();
    return message || fallback;
  }

  function proxyPost(action, payload, busyMessage) {
    return shell.post(action, payload, busyMessage, { busy: false });
  }

  function setRequestButtonsDisabled(disabled) {
    [
      'topupNumberContinueButton',
      'topupAmountContinueButton',
      'topupPinContinueButton'
    ].forEach((id) => {
      const button = byId(id);
      if (button) button.disabled = Boolean(disabled);
    });
  }

  function isTopupKeyboardInput(node) {
    return node instanceof HTMLInputElement
      && (node.id === 'topupAmountInput' || node.id === 'topupPinInput');
  }

  function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
  }

  function clearKeyboardTimers() {
    if (state.keyboard.layoutTimer) window.clearTimeout(state.keyboard.layoutTimer);
    if (state.keyboard.restoreTimer) window.clearTimeout(state.keyboard.restoreTimer);
    state.keyboard.layoutTimer = 0;
    state.keyboard.restoreTimer = 0;
  }

  function keyboardFocusRegion(input) {
    if (!isTopupKeyboardInput(input)) return null;
    const field = input.closest('.topup-field-group') || input;
    const button = byId(input.id === 'topupAmountInput'
      ? 'topupAmountContinueButton'
      : 'topupPinContinueButton');
    return { field, button };
  }

  function ensureKeyboardControlsVisible(input = state.keyboard.activeInput) {
    if (!isTopupKeyboardInput(input) || document.activeElement !== input) return;
    const scrollBody = byId('topupScrollBody');
    const region = keyboardFocusRegion(input);
    if (!scrollBody || !region) return;

    region.field.scrollIntoView({
      behavior: prefersReducedMotion() ? 'auto' : 'smooth',
      block: 'center',
      inline: 'nearest'
    });

    window.setTimeout(() => {
      if (document.activeElement !== input) return;
      const viewport = window.visualViewport;
      const scrollRect = scrollBody.getBoundingClientRect();
      const fieldRect = region.field.getBoundingClientRect();
      const buttonRect = region.button?.getBoundingClientRect() || fieldRect;
      const viewportTop = viewport ? viewport.offsetTop : 0;
      const viewportBottom = viewport
        ? viewport.offsetTop + viewport.height
        : window.innerHeight;
      const navRect = document.querySelector('.bottom-nav')?.getBoundingClientRect();
      const visibleTop = Math.max(scrollRect.top, viewportTop) + 12;
      let visibleBottom = Math.min(scrollRect.bottom, viewportBottom) - 14;
      if (navRect && navRect.top > visibleTop && navRect.top < visibleBottom) {
        visibleBottom = navRect.top - 12;
      }

      let delta = 0;
      if (buttonRect.bottom > visibleBottom) {
        delta = buttonRect.bottom - visibleBottom;
      } else if (fieldRect.top < visibleTop) {
        delta = fieldRect.top - visibleTop;
      }
      if (Math.abs(delta) < 1) return;
      scrollBody.scrollTo({
        top: Math.max(0, scrollBody.scrollTop + delta),
        behavior: prefersReducedMotion() ? 'auto' : 'smooth'
      });
    }, 90);
  }

  function updateKeyboardLayout() {
    const input = isTopupKeyboardInput(document.activeElement)
      ? document.activeElement
      : state.keyboard.activeInput;
    if (!isTopupKeyboardInput(input)) return;

    const viewport = window.visualViewport;
    const visibleHeight = viewport
      ? viewport.height + viewport.offsetTop
      : window.innerHeight;
    const currentLayoutHeight = Math.max(window.innerHeight || 0, visibleHeight || 0);
    state.keyboard.baselineHeight = Math.max(state.keyboard.baselineHeight, currentLayoutHeight);
    const occludedHeight = Math.max(0, state.keyboard.baselineHeight - visibleHeight);
    const keyboardOpen = occludedHeight > 96;
    const keyboardInset = keyboardOpen ? Math.ceil(occludedHeight + 72) : 0;

    document.body.classList.toggle('topup-keyboard-open', keyboardOpen);
    document.body.style.setProperty('--topup-keyboard-inset', `${keyboardInset}px`);
    if (keyboardOpen) ensureKeyboardControlsVisible(input);
  }

  function scheduleKeyboardLayout(delay = 0) {
    if (state.keyboard.layoutTimer) window.clearTimeout(state.keyboard.layoutTimer);
    state.keyboard.layoutTimer = window.setTimeout(() => {
      state.keyboard.layoutTimer = 0;
      updateKeyboardLayout();
    }, delay);
  }

  function resetKeyboardLayout({ restoreScroll = false } = {}) {
    clearKeyboardTimers();
    document.body.classList.remove('topup-keyboard-open');
    document.body.style.removeProperty('--topup-keyboard-inset');
    const scrollBody = byId('topupScrollBody');
    if (restoreScroll && scrollBody && state.keyboard.restoreStep === state.step) {
      const restoreTop = Math.min(state.keyboard.restoreScrollTop, scrollBody.scrollHeight - scrollBody.clientHeight);
      scrollBody.scrollTo({
        top: Math.max(0, restoreTop),
        behavior: prefersReducedMotion() ? 'auto' : 'smooth'
      });
    }
    state.keyboard.activeInput = null;
    state.keyboard.restoreScrollTop = 0;
    state.keyboard.restoreStep = state.step;
    state.keyboard.baselineHeight = Math.max(window.innerHeight || 0, window.visualViewport?.height || 0);
  }

  function handleKeyboardFocusIn(event) {
    const input = event.target;
    if (!isTopupKeyboardInput(input)) return;
    const scrollBody = byId('topupScrollBody');
    clearKeyboardTimers();
    state.keyboard.activeInput = input;
    state.keyboard.restoreScrollTop = scrollBody?.scrollTop || 0;
    state.keyboard.restoreStep = state.step;
    state.keyboard.baselineHeight = Math.max(
      state.keyboard.baselineHeight,
      window.innerHeight || 0,
      window.visualViewport?.height || 0
    );
    scheduleKeyboardLayout(0);
    window.setTimeout(() => scheduleKeyboardLayout(0), 220);
  }

  function handleKeyboardFocusOut() {
    if (state.keyboard.restoreTimer) window.clearTimeout(state.keyboard.restoreTimer);
    state.keyboard.restoreTimer = window.setTimeout(() => {
      state.keyboard.restoreTimer = 0;
      if (isTopupKeyboardInput(document.activeElement)) return;
      resetKeyboardLayout({ restoreScroll: true });
    }, 180);
  }

  function hideModalImmediate({ restoreFocus = true } = {}) {
    const modal = byId('topupActionModal');
    const opener = state.modal.opener;
    state.modal.open = false;
    state.modal.busy = false;
    state.modal.kind = '';
    state.modal.hasHistory = false;
    state.modal.opener = null;
    modal?.classList.remove('show', 'busy', 'success', 'error', 'choice');
    modal?.setAttribute('aria-hidden', 'true');
    modal?.setAttribute('inert', '');
    document.body.classList.remove('topup-modal-open');
    if (restoreFocus && opener instanceof HTMLElement) {
      window.setTimeout(() => opener.focus({ preventScroll: true }), 0);
    }
  }

  function requestCloseModal() {
    if (!state.modal.open || state.modal.busy) return;
    const hadHistory = state.modal.hasHistory;
    const callback = state.afterModalClose;
    hideModalImmediate();
    if (hadHistory) {
      history.back();
      return;
    }
    state.afterModalClose = null;
    if (typeof callback === 'function') window.setTimeout(callback, 0);
  }

  function configureModal({ kind, title, message = '', busy = false, pushHistory = true, opener = null }) {
    const modal = byId('topupActionModal');
    const wasOpen = state.modal.open;
    if (!modal) return;

    if (isTopupKeyboardInput(document.activeElement)) document.activeElement.blur();
    resetKeyboardLayout({ restoreScroll: false });

    if (!wasOpen && pushHistory) {
      history.pushState(historyPayload(state.step, kind), '', window.location.href);
      state.modal.hasHistory = true;
    } else if (!wasOpen) {
      state.modal.hasHistory = false;
    }

    state.modal.open = true;
    state.modal.busy = Boolean(busy);
    state.modal.kind = kind;
    state.modal.opener = opener || state.modal.opener || document.activeElement;

    modal.className = `topup-action-modal show ${busy ? 'busy' : kind}`;
    modal.setAttribute('aria-hidden', 'false');
    modal.removeAttribute('inert');
    document.body.classList.add('topup-modal-open');

    const titleNode = byId('topupModalTitle');
    const messageNode = byId('topupModalMessage');
    const icon = byId('topupModalIcon');
    if (titleNode) titleNode.textContent = title;
    if (messageNode) messageNode.textContent = message;
    if (icon) icon.textContent = kind === 'success' ? 'OK' : kind === 'error' ? '!' : '';
    clearNode(byId('topupModalBody'));
    clearNode(byId('topupModalActions'));

    const closeButton = byId('topupModalCloseButton');
    if (closeButton) closeButton.disabled = Boolean(busy);
  }

  function openLoading(message) {
    configureModal({
      kind: 'loading',
      title: 'Mobile Top-Up',
      message,
      busy: true,
      pushHistory: false
    });
    root.setAttribute('aria-busy', 'true');
  }

  function updateLoading(message) {
    if (state.modal.kind !== 'loading') return;
    const messageNode = byId('topupModalMessage');
    if (messageNode) messageNode.textContent = message;
  }

  function closeLoading() {
    if (state.modal.kind === 'loading') hideModalImmediate({ restoreFocus: false });
    root.setAttribute('aria-busy', 'false');
  }

  function addModalAction(label, variant, handler, options = {}) {
    const actions = byId('topupModalActions');
    if (!actions) return null;
    const button = createNode('button', `topup-modal-action ${variant || ''}`, label);
    button.type = 'button';
    button.disabled = Boolean(options.disabled);
    button.addEventListener('click', handler);
    actions.appendChild(button);
    return button;
  }

  function openError(title, message) {
    closeLoading();
    configureModal({ kind: 'error', title, message, busy: false, pushHistory: true });
    addModalAction('OK', 'primary', requestCloseModal);
    window.setTimeout(() => byId('topupModalActions')?.querySelector('button')?.focus({ preventScroll: true }), 0);
  }

  function addModalOption(code, title, subtitle, handler) {
    const body = byId('topupModalBody');
    if (!body) return;
    const button = createNode('button', 'topup-modal-option');
    button.type = 'button';

    const badge = createNode('span', 'topup-modal-option-code', code);
    const copy = createNode('span', 'topup-modal-option-copy');
    copy.append(createNode('strong', '', title), createNode('small', '', subtitle));
    const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    arrow.setAttribute('viewBox', '0 0 24 24');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'm9 6 6 6-6 6');
    arrow.appendChild(path);
    button.append(badge, copy, arrow);
    button.addEventListener('click', handler);
    body.appendChild(button);
  }

  function openCountryChooser() {
    configureModal({
      kind: 'choice',
      title: 'Select Country',
      message: 'Choose the destination country for this top-up.',
      busy: false,
      pushHistory: true,
      opener: byId('topupCountryButton')
    });
    Object.values(COUNTRIES).forEach((item) => {
      addModalOption(item.code, item.name, `${item.dialCode} - ${item.currency}`, () => {
        if (state.countryCode !== item.code) {
          state.countryCode = item.code;
          resetNumberDependentState();
          if (byId('topupNumberInput')) byId('topupNumberInput').value = '';
          renderCountry();
          renderPresets();
          renderFavorites();
        }
        requestCloseModal();
      });
    });
  }

  function openOperatorChooser({ continueAfterSelection = false } = {}) {
    configureModal({
      kind: 'choice',
      title: 'Select Operator',
      message: 'Choose the mobile operator for this number.',
      busy: false,
      pushHistory: true
    });
    country().operators.forEach((operator) => {
      addModalOption(operator.code.slice(0, 4), operator.name, 'Prepaid top-up', () => {
        state.operatorCode = operator.code;
        state.operatorName = operator.name;
        state.operatorSource = 'MANUAL';
        state.amountCheck = null;
        state.preview = null;
        state.verified = false;
        renderAmountSummary();
        if (continueAfterSelection) state.afterModalClose = verifyNumberWithBackend;
        requestCloseModal();
      });
    });
  }

  function resetNumberDependentState() {
    state.numberFull = '';
    state.operatorCode = '';
    state.operatorName = '';
    state.operatorSource = '';
    state.amount = '';
    state.amountCheck = null;
    state.preview = null;
    state.verified = false;
    state.completed = false;
    state.success = null;
    if (byId('topupAmountInput')) byId('topupAmountInput').value = '';
    clearPin();
  }

  function clearPin() {
    const input = byId('topupPinInput');
    if (input) input.value = '';
  }

  function renderCountry() {
    const selected = country();
    if (byId('topupCountryCodeBadge')) byId('topupCountryCodeBadge').textContent = selected.code;
    if (byId('topupCountryName')) byId('topupCountryName').textContent = selected.name;
    if (byId('topupCountryDialCode')) byId('topupCountryDialCode').textContent = selected.dialCode;
  }

  function createSummary(target, { includeAmount = false, allowOperatorChange = false } = {}) {
    if (!target) return;
    clearNode(target);
    const line = createNode('div', 'topup-summary-line');
    line.appendChild(createNode('span', 'topup-summary-icon', 'Z'));
    const copy = createNode('span', 'topup-summary-copy');
    copy.appendChild(createNode('strong', '', state.operatorName || operatorByCode()?.name || 'Mobile Top-Up'));
    const details = [country().name, state.numberFull || '-', includeAmount ? formatAmount(state.amount, country().currency) : ''].filter(Boolean);
    copy.appendChild(createNode('span', '', details.join(' - ')));
    line.appendChild(copy);
    if (allowOperatorChange) {
      const change = createNode('button', 'topup-summary-change', 'Change');
      change.type = 'button';
      change.addEventListener('click', () => openOperatorChooser({ continueAfterSelection: false }));
      line.appendChild(change);
    }
    target.appendChild(line);
  }

  function renderAmountSummary() {
    createSummary(byId('topupAmountSummary'), { includeAmount: false, allowOperatorChange: true });
  }

  function renderPinSummary() {
    createSummary(byId('topupPinSummary'), { includeAmount: true, allowOperatorChange: false });
  }

  function renderPresets() {
    const selectedCountry = country();
    const grid = byId('topupPresetGrid');
    if (!grid) return;
    clearNode(grid);
    selectedCountry.presets.forEach((amount) => {
      const button = createNode('button', 'topup-preset-button', formatAmount(amount, selectedCountry.currency));
      button.type = 'button';
      button.dataset.topupAmount = String(amount);
      button.classList.toggle('active', Number(state.amount) === Number(amount));
      button.addEventListener('click', () => selectAmount(amount));
      grid.appendChild(button);
    });
    if (byId('topupAmountCurrency')) byId('topupAmountCurrency').textContent = selectedCountry.currency;
    if (byId('topupAmountPrefix')) byId('topupAmountPrefix').textContent = selectedCountry.currency === 'MYR' ? 'RM' : 'BDT';
    const input = byId('topupAmountInput');
    if (input) {
      input.min = String(selectedCountry.minAmount);
      input.max = String(selectedCountry.maxAmount);
    }
    if (byId('topupMinimumHint')) {
      byId('topupMinimumHint').textContent = `Minimum top-up amount is ${formatAmount(selectedCountry.minAmount, selectedCountry.currency)}.`;
    }
  }

  function selectAmount(value) {
    state.amount = String(value || '');
    state.amountCheck = null;
    state.preview = null;
    state.verified = false;
    if (byId('topupAmountInput')) byId('topupAmountInput').value = state.amount;
    document.querySelectorAll('.user-topup-page .topup-preset-button').forEach((button) => {
      button.classList.toggle('active', Number(button.dataset.topupAmount) === Number(state.amount));
    });
  }

  function applyStep(nextStep, { focus = true } = {}) {
    if (!STEP_ORDER.includes(nextStep)) return;
    const previousStep = state.step;
    if (isTopupKeyboardInput(document.activeElement)) document.activeElement.blur();
    resetKeyboardLayout({ restoreScroll: false });
    state.step = nextStep;

    if (previousStep === 'pin' && nextStep !== 'preview') {
      state.verified = false;
      state.preview = null;
      clearPin();
    }
    if (previousStep === 'preview' && nextStep === 'pin') {
      state.verified = false;
      state.preview = null;
      clearPin();
    }
    if (nextStep === 'amount') renderAmountSummary();
    if (nextStep === 'pin') renderPinSummary();
    if (nextStep === 'preview') renderPreview();

    document.querySelectorAll('.user-topup-page .topup-step').forEach((node) => {
      const active = node.dataset.topupStep === nextStep;
      node.classList.toggle('active', active);
      node.setAttribute('aria-hidden', active ? 'false' : 'true');
      node.inert = !active;
    });
    const scrollBody = byId('topupScrollBody');
    if (scrollBody) scrollBody.scrollTop = 0;

    if (!focus) return;
    const focusTargets = {
      number: 'topupNumberInput',
      amount: 'topupAmountInput',
      pin: 'topupPinInput',
      preview: 'topupHoldConfirmButton'
    };
    window.setTimeout(() => byId(focusTargets[nextStep])?.focus({ preventScroll: true }), 40);
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

  function topupPayload({ checkOnly = false } = {}) {
    const selectedOperator = operatorByCode();
    return {
      country: state.countryCode,
      country_code: state.countryCode,
      number: state.numberFull,
      topup_number: state.numberFull,
      operator: state.operatorCode,
      operator_code: state.operatorCode,
      operator_name: state.operatorName || selectedOperator?.name || '',
      service_type: 'PREPAID',
      amount: Number(state.amount || country().minAmount),
      verified_by: 'USER_WEB',
      ...(checkOnly ? { check_only: true } : {})
    };
  }

  async function verifyNumberWithBackend() {
    if (state.requestBusy) return;
    const input = byId('topupNumberInput');
    const normalized = normalizeNumber(input?.value || state.numberFull);
    if (!normalized) {
      openError('Mobile Number Required', 'Please enter a mobile number.');
      return;
    }
    if (!isValidNumber(normalized)) {
      openError('Invalid Mobile Number', invalidNumberMessage());
      return;
    }
    if (!state.operatorCode || !operatorByCode()) {
      openOperatorChooser({ continueAfterSelection: true });
      return;
    }

    state.numberFull = normalized;
    state.requestBusy = true;
    setRequestButtonsDisabled(true);
    openLoading('Checking number...');
    let response = null;
    try {
      response = await proxyPost('topup_preview', {
        ...topupPayload({ checkOnly: true }),
        amount: country().minAmount
      }, 'Checking number...');
      state.amountCheck = response;
      const backendOperatorCode = String(response.operator_code || state.operatorCode).toUpperCase();
      if (backendOperatorCode) state.operatorCode = backendOperatorCode;
      state.operatorName = String(response.operator || operatorByCode()?.name || state.operatorName);
    } catch (error) {
      if (!shell.isSessionError(error)) {
        openError('Number Check Failed', safeErrorMessage(error, 'The mobile number could not be verified.'));
      }
      return;
    } finally {
      state.requestBusy = false;
      setRequestButtonsDisabled(false);
      closeLoading();
    }
    if (response) {
      renderPresets();
      navigateStep('amount');
    }
  }

  function continueFromNumber() {
    if (state.requestBusy) return;
    const roleSettings = state.walletSummary?.role_settings || {};
    if (roleSettings.topup_enabled === false) {
      openError('Top-Up Unavailable', 'Mobile top-up is disabled for this account.');
      return;
    }
    const normalized = normalizeNumber(byId('topupNumberInput')?.value || '');
    if (!isValidNumber(normalized)) {
      openError('Invalid Mobile Number', invalidNumberMessage());
      return;
    }
    state.numberFull = normalized;
    const candidates = operatorCandidates(normalized);
    if (candidates.length === 1) {
      state.operatorCode = candidates[0].code;
      state.operatorName = candidates[0].name;
      state.operatorSource = 'AUTO';
      verifyNumberWithBackend();
      return;
    }
    state.operatorCode = '';
    state.operatorName = '';
    state.operatorSource = '';
    openOperatorChooser({ continueAfterSelection: true });
  }

  function validateSelectedAmount() {
    const selectedCountry = country();
    const value = Number(byId('topupAmountInput')?.value || state.amount || 0);
    if (!Number.isFinite(value) || value <= 0) {
      openError('Amount Required', 'Please enter a top-up amount.');
      return null;
    }
    if (value < selectedCountry.minAmount) {
      openError('Amount Too Low', `Minimum top-up amount is ${formatAmount(selectedCountry.minAmount, selectedCountry.currency)}.`);
      return null;
    }
    if (value > selectedCountry.maxAmount) {
      openError('Amount Too High', `Maximum top-up amount is ${formatAmount(selectedCountry.maxAmount, selectedCountry.currency)}.`);
      return null;
    }
    return value;
  }

  async function continueFromAmount() {
    if (state.requestBusy) return;
    const amount = validateSelectedAmount();
    if (amount === null) return;
    state.amount = String(amount);
    state.requestBusy = true;
    setRequestButtonsDisabled(true);
    openLoading('Checking top-up balance...');
    let response = null;
    try {
      response = await proxyPost('topup_preview', topupPayload({ checkOnly: true }), 'Checking top-up balance...');
      state.amountCheck = response;
    } catch (error) {
      if (!shell.isSessionError(error)) {
        openError('Top-Up Check Failed', safeErrorMessage(error, 'Top-up balance could not be checked.'));
      }
      return;
    } finally {
      state.requestBusy = false;
      setRequestButtonsDisabled(false);
      closeLoading();
    }
    if (response) navigateStep('pin');
  }

  async function continueFromPin() {
    if (state.requestBusy) return;
    const pinInput = byId('topupPinInput');
    const pin = String(pinInput?.value || '').trim();
    if (!/^\d{4,6}$/.test(pin)) {
      clearPin();
      openError('PIN Required', 'Please enter a valid transaction PIN.');
      return;
    }

    state.requestBusy = true;
    setRequestButtonsDisabled(true);
    openLoading('Verifying PIN...');
    let response = null;
    try {
      await proxyPost('validate_pin', { pin, purpose: 'TOPUP' }, 'Verifying PIN...');
      state.verified = true;
      clearPin();
      updateLoading('Loading top-up preview...');
      response = await proxyPost('topup_preview', topupPayload(), 'Loading top-up preview...');
      if (!String(response.preview_token || '').trim()) {
        const missingToken = new Error('Top-up preview could not be created.');
        missingToken.code = 'TOPUP_PREVIEW_FAILED';
        throw missingToken;
      }
      state.preview = response;
    } catch (error) {
      state.verified = false;
      state.preview = null;
      clearPin();
      if (!shell.isSessionError(error)) {
        const wrongPin = ['INVALID_PIN', 'WRONG_PIN'].includes(String(error?.code || '').toUpperCase());
        openError(wrongPin ? 'Incorrect PIN' : 'Preview Failed', safeErrorMessage(error, 'Top-up preview could not be loaded.'));
      }
      return;
    } finally {
      state.requestBusy = false;
      setRequestButtonsDisabled(false);
      closeLoading();
    }
    if (response) navigateStep('preview');
  }

  function addPreviewRow(label, value, total = false) {
    const rows = byId('topupPreviewRows');
    if (!rows) return;
    const row = createNode('div', `topup-preview-row${total ? ' total' : ''}`);
    row.append(createNode('span', '', label), createNode('strong', '', value || '-'));
    rows.appendChild(row);
  }

  function renderPreview() {
    const preview = state.preview || {};
    const rows = byId('topupPreviewRows');
    if (!rows) return;
    clearNode(rows);
    addPreviewRow('Operator', String(preview.operator || state.operatorName || '-'));
    addPreviewRow('Amount', String(preview.topup_amount_text || '-'));
    if (preview.rate_applicable && preview.rate_text) addPreviewRow('Rate', String(preview.rate_text));
    if (preview.commission_applicable && preview.commission_text) {
      addPreviewRow('Commission', String(preview.commission_text));
    }
    addPreviewRow('Total Pay', String(preview.total_pay_text || '-'), true);
    addPreviewRow('Fee', String(preview.fee_text || '-'));
    addPreviewRow('Balance After', String(preview.balance_after_text || '-'));
    resetHoldControl();
  }

  function setHoldProgress(progress) {
    const button = byId('topupHoldConfirmButton');
    if (!button) return;
    const safeProgress = Math.max(0, Math.min(100, Number(progress) || 0));
    button.style.setProperty('--hold-progress', `${safeProgress}%`);
  }

  function setHoldLabel(label) {
    const node = byId('topupHoldConfirmButton')?.querySelector('.topup-hold-label');
    if (node) node.textContent = label;
  }

  function stopHoldTimers() {
    if (state.hold.timer) window.clearTimeout(state.hold.timer);
    if (state.hold.animationFrame) window.cancelAnimationFrame(state.hold.animationFrame);
    state.hold.timer = 0;
    state.hold.animationFrame = 0;
  }

  function resetHoldControl() {
    stopHoldTimers();
    state.hold.active = false;
    state.hold.completed = false;
    state.hold.pointerId = null;
    state.hold.startedAt = 0;
    setHoldProgress(0);
    setHoldLabel('Tap and hold to confirm top-up');
    const button = byId('topupHoldConfirmButton');
    if (button) {
      button.classList.remove('is-holding');
      button.disabled = Boolean(state.submitting || state.completed);
    }
  }

  function updateHoldAnimation() {
    if (!state.hold.active || state.hold.completed) return;
    const elapsed = performance.now() - state.hold.startedAt;
    setHoldProgress(Math.min(96, (elapsed / HOLD_DURATION_MS) * 100));
    state.hold.animationFrame = window.requestAnimationFrame(updateHoldAnimation);
  }

  function cancelHold() {
    if (!state.hold.active || state.hold.completed) return;
    resetHoldControl();
  }

  function completeHold() {
    if (!state.hold.active || state.hold.completed || state.submitting) return;
    state.hold.completed = true;
    stopHoldTimers();
    setHoldProgress(100);
    setHoldLabel('Confirming...');
    submitTopup();
  }

  function beginHold({ pointerId = null, clientX = 0, clientY = 0, target = null } = {}) {
    if (state.hold.active || state.submitting || state.completed || !state.preview?.preview_token) return;
    const button = target || byId('topupHoldConfirmButton');
    state.hold.active = true;
    state.hold.completed = false;
    state.hold.pointerId = pointerId;
    state.hold.startX = Number(clientX || 0);
    state.hold.startY = Number(clientY || 0);
    state.hold.startedAt = performance.now();
    button?.classList.add('is-holding');
    if (pointerId !== null && button?.setPointerCapture) {
      try { button.setPointerCapture(pointerId); } catch (_) {}
    }
    setHoldProgress(0);
    setHoldLabel('Keep holding...');
    state.hold.animationFrame = window.requestAnimationFrame(updateHoldAnimation);
    state.hold.timer = window.setTimeout(completeHold, HOLD_DURATION_MS);
  }

  function handleHoldPointerDown(event) {
    if (event.pointerType === 'mouse' && event.button !== 0) return;
    event.preventDefault();
    beginHold({
      pointerId: event.pointerId,
      clientX: event.clientX,
      clientY: event.clientY,
      target: event.currentTarget
    });
  }

  function handleHoldPointerMove(event) {
    if (!state.hold.active || state.hold.pointerId !== event.pointerId) return;
    const distanceX = Math.abs(event.clientX - state.hold.startX);
    const distanceY = Math.abs(event.clientY - state.hold.startY);
    if (distanceX > 14 || distanceY > 14) cancelHold();
  }

  function handleHoldPointerEnd(event) {
    if (!state.hold.active || state.hold.pointerId !== event.pointerId) return;
    if (!state.hold.completed) cancelHold();
  }

  function resultRows(data) {
    const amountText = data.topup_amount_text
      || formatAmount(data.topup_amount ?? data.amount, data.topup_currency || country().currency);
    return [
      ['Number', maskNumber(data.topup_number || state.numberFull)],
      ['Country', country().name],
      ['Operator', data.operator_name || operatorByCode(data.operator)?.name || state.operatorName || data.operator || '-'],
      ['Amount', amountText],
      ['Status', statusLabel(data.status)],
      ['Request ID', data.request_id || '-']
    ];
  }

  function appendResultRows(rows) {
    const body = byId('topupModalBody');
    if (!body) return;
    rows.forEach(([label, value]) => {
      const row = createNode('div', 'topup-result-row');
      row.append(createNode('span', '', label), createNode('strong', '', value));
      body.appendChild(row);
    });
  }

  function favoriteIdentity(item) {
    return `${String(item?.countryCode || '').toUpperCase()}|${normalizeNumber(item?.number || '', String(item?.countryCode || '').toUpperCase())}`;
  }

  function currentFavoriteIdentity() {
    return `${state.countryCode}|${state.numberFull}`;
  }

  function isCurrentFavoriteSaved() {
    const identity = currentFavoriteIdentity();
    return state.favorites.some((item) => favoriteIdentity(item) === identity);
  }

  function showSuccessModal({ pushHistory = true } = {}) {
    const data = state.success || {};
    configureModal({
      kind: 'success',
      title: 'Success',
      message: 'Top-up request submitted successfully.',
      busy: false,
      pushHistory
    });
    appendResultRows(resultRows(data));

    const saved = isCurrentFavoriteSaved();
    addModalAction(saved ? 'Favorite Saved' : 'Add Favorite Number', '', () => openFavoriteSaveForm(), { disabled: saved });
    addModalAction('Done', 'primary', finishSuccessfulFlow);
  }

  function openFavoriteSaveForm() {
    if (!state.success || isCurrentFavoriteSaved()) return;
    configureModal({
      kind: 'success',
      title: 'Save Favorite Number',
      message: `${state.numberFull} - ${state.operatorName}`,
      busy: false,
      pushHistory: false
    });
    const body = byId('topupModalBody');
    const label = createNode('label', 'topup-favorite-name-field');
    label.appendChild(createNode('span', '', 'Favorite name'));
    const input = createNode('input');
    input.id = 'topupFavoriteNameInput';
    input.type = 'text';
    input.maxLength = 60;
    input.autocomplete = 'off';
    input.placeholder = 'Name this number';
    input.value = state.operatorName || 'Mobile Top-Up';
    label.appendChild(input);
    body?.appendChild(label);
    addModalAction('Cancel', '', () => showSuccessModal({ pushHistory: false }));
    addModalAction('Save', 'primary', saveCurrentFavorite);
    window.setTimeout(() => input.focus({ preventScroll: true }), 40);
  }

  function saveCurrentFavorite() {
    const name = String(byId('topupFavoriteNameInput')?.value || '').trim();
    if (!name) {
      shell.toast('Favorite name is required.', 'error');
      byId('topupFavoriteNameInput')?.focus();
      return;
    }
    if (isCurrentFavoriteSaved()) {
      shell.toast('This number is already in your favorites.', 'error');
      showSuccessModal({ pushHistory: false });
      return;
    }
    if (state.favorites.length >= 10) {
      shell.toast('You can save up to 10 favorite numbers.', 'error');
      return;
    }
    const favorite = {
      id: `FAV_${Date.now()}_${Math.random().toString(16).slice(2, 10)}`,
      name,
      number: state.numberFull,
      countryCode: state.countryCode,
      operatorCode: state.operatorCode,
      operatorName: state.operatorName,
      serviceType: 'PREPAID',
      createdAt: Date.now()
    };
    try {
      state.favorites = [...state.favorites, favorite].slice(-10);
      window.localStorage.setItem(state.favoriteStorageKey, JSON.stringify(state.favorites));
      renderFavorites();
      shell.toast('Favorite number saved.', 'ok');
      showSuccessModal({ pushHistory: false });
    } catch (_) {
      state.favorites = state.favorites.filter((item) => item.id !== favorite.id);
      shell.toast('Favorite number could not be saved on this browser.', 'error');
    }
  }

  function finishSuccessfulFlow() {
    const distance = stepIndex(state.step) + (state.modal.hasHistory ? 1 : 0);
    state.resetPending = true;
    hideModalImmediate({ restoreFocus: false });
    if (distance > 0) {
      history.go(-distance);
    } else {
      resetFlow();
      history.replaceState(historyPayload('number'), '', window.location.href);
    }
  }

  async function submitTopup() {
    if (state.submitting || state.completed) return;
    const previewToken = String(state.preview?.preview_token || '').trim();
    if (!previewToken) {
      openError('Preview Expired', 'Top-up preview expired. Please review again.');
      return;
    }

    state.submitting = true;
    const holdButton = byId('topupHoldConfirmButton');
    if (holdButton) holdButton.disabled = true;
    openLoading('Submitting top-up request...');
    try {
      const response = await proxyPost('topup_submit', {
        preview_token: previewToken,
        topup_number: state.numberFull,
        operator: state.operatorCode,
        amount: Number(state.amount),
        verified_by: 'USER_WEB'
      }, 'Submitting top-up request...');
      state.completed = true;
      state.success = {
        ...response,
        country_code: state.countryCode,
        topup_number: response.topup_number || state.numberFull,
        operator_name: response.operator_name || state.operatorName,
        topup_amount_text: response.topup_amount_text || state.preview?.topup_amount_text || ''
      };
      closeLoading();
      showSuccessModal();
    } catch (error) {
      closeLoading();
      if (!shell.isSessionError(error)) {
        openError('Top-Up Failed', safeErrorMessage(error, 'Top-up request could not be submitted.'));
      }
    } finally {
      state.submitting = false;
      if (!state.completed) resetHoldControl();
      else if (holdButton) holdButton.disabled = true;
    }
  }

  function loadFavorites() {
    state.favorites = [];
    if (!state.favoriteStorageKey) return;
    try {
      const parsed = JSON.parse(window.localStorage.getItem(state.favoriteStorageKey) || '[]');
      if (!Array.isArray(parsed)) return;
      state.favorites = parsed.filter((item) => {
        const code = String(item?.countryCode || '').toUpperCase();
        return Boolean(item?.name && item?.number && COUNTRIES[code] && item?.operatorCode);
      }).slice(-10);
    } catch (_) {
      state.favorites = [];
    }
  }

  function renderFavorites() {
    const list = byId('topupFavoriteList');
    if (!list) return;
    clearNode(list);
    const items = state.favorites
      .filter((item) => String(item.countryCode || '').toUpperCase() === state.countryCode)
      .slice()
      .sort((left, right) => Number(right.createdAt || 0) - Number(left.createdAt || 0));
    if (!items.length) {
      list.appendChild(createNode('div', 'topup-empty-state', 'No favorite numbers yet.'));
      return;
    }
    items.forEach((favorite) => {
      const button = createNode('button', 'topup-favorite-item');
      button.type = 'button';
      button.setAttribute('aria-label', `Use favorite ${favorite.name}`);
      button.appendChild(createNode('span', 'topup-favorite-avatar', 'Z'));
      const copy = createNode('span', 'topup-favorite-copy');
      copy.append(
        createNode('strong', '', favorite.name),
        createNode('small', '', `${favorite.number} - ${COUNTRIES[favorite.countryCode]?.name || favorite.countryCode} - ${favorite.operatorName || favorite.operatorCode}`)
      );
      button.appendChild(copy);
      const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      arrow.setAttribute('viewBox', '0 0 24 24');
      const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', 'm9 6 6 6-6 6');
      arrow.appendChild(path);
      button.appendChild(arrow);
      button.addEventListener('click', () => selectFavorite(favorite));
      list.appendChild(button);
    });
  }

  function selectFavorite(favorite) {
    const code = String(favorite.countryCode || '').toUpperCase();
    if (!COUNTRIES[code]) {
      openError('Favorite Unavailable', 'This favorite country is no longer supported.');
      return;
    }
    state.countryCode = code;
    state.numberFull = normalizeNumber(favorite.number, code);
    state.operatorCode = String(favorite.operatorCode || '').toUpperCase();
    state.operatorName = String(favorite.operatorName || operatorByCode(state.operatorCode)?.name || state.operatorCode);
    state.operatorSource = 'FAVORITE';
    state.amount = '';
    state.amountCheck = null;
    state.preview = null;
    state.verified = false;
    state.completed = false;
    state.suppressNumberInput = true;
    if (byId('topupNumberInput')) byId('topupNumberInput').value = state.numberFull;
    state.suppressNumberInput = false;
    renderCountry();
    renderPresets();
    verifyNumberWithBackend();
  }

  function resetFlow() {
    state.countryCode = 'BD';
    state.numberFull = '';
    state.operatorCode = '';
    state.operatorName = '';
    state.operatorSource = '';
    state.amount = '';
    state.amountCheck = null;
    state.preview = null;
    state.verified = false;
    state.completed = false;
    state.success = null;
    state.requestBusy = false;
    state.submitting = false;
    if (byId('topupNumberInput')) byId('topupNumberInput').value = '';
    if (byId('topupAmountInput')) byId('topupAmountInput').value = '';
    clearPin();
    renderCountry();
    renderPresets();
    renderFavorites();
    applyStep('number', { focus: false });
    resetHoldControl();
  }

  function handleNumberInput() {
    if (state.suppressNumberInput) return;
    const current = normalizeNumber(byId('topupNumberInput')?.value || '');
    if (!state.numberFull || current === state.numberFull) return;
    resetNumberDependentState();
  }

  function handleAmountInput() {
    state.amount = String(byId('topupAmountInput')?.value || '');
    state.amountCheck = null;
    state.preview = null;
    state.verified = false;
    document.querySelectorAll('.user-topup-page .topup-preset-button').forEach((button) => {
      button.classList.toggle('active', Number(button.dataset.topupAmount) === Number(state.amount));
    });
  }

  function leaveTopupPage() {
    const referrer = document.referrer;
    try {
      const url = new URL(referrer);
      if (url.origin === window.location.origin && url.pathname.startsWith('/user/')) {
        history.back();
        return;
      }
    } catch (_) {
      // Fall through to the stable dashboard route.
    }
    window.location.assign('/user/dashboard');
  }

  function handleHeaderBack(event) {
    event.preventDefault();
    if (state.modal.open) {
      requestCloseModal();
      return;
    }
    if (state.step !== 'number') {
      history.back();
      return;
    }
    leaveTopupPage();
  }

  function handlePopState(event) {
    if (state.modal.open && state.modal.busy) {
      history.pushState(historyPayload(state.step), '', window.location.href);
      return;
    }

    const callback = state.afterModalClose;
    state.afterModalClose = null;
    if (state.modal.open) hideModalImmediate({ restoreFocus: false });

    if (state.resetPending) {
      state.resetPending = false;
      resetFlow();
      history.replaceState(historyPayload('number'), '', window.location.href);
      return;
    }

    const targetStep = String(event.state?.zpayTopup?.step || '');
    if (STEP_ORDER.includes(targetStep)) applyStep(targetStep, { focus: false });
    if (typeof callback === 'function') window.setTimeout(callback, 0);
  }

  function bindEvents() {
    if (root.dataset.topupBound === 'true') return;
    root.dataset.topupBound = 'true';

    byId('topupBackButton')?.addEventListener('click', handleHeaderBack);
    byId('topupCountryButton')?.addEventListener('click', openCountryChooser);
    byId('topupNumberContinueButton')?.addEventListener('click', continueFromNumber);
    byId('topupAmountContinueButton')?.addEventListener('click', continueFromAmount);
    byId('topupPinContinueButton')?.addEventListener('click', continueFromPin);

    byId('topupNumberInput')?.addEventListener('input', handleNumberInput);
    byId('topupNumberInput')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') continueFromNumber();
    });
    byId('topupAmountInput')?.addEventListener('input', handleAmountInput);
    byId('topupAmountInput')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') continueFromAmount();
    });
    byId('topupPinInput')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') continueFromPin();
    });
    root.addEventListener('focusin', handleKeyboardFocusIn);
    root.addEventListener('focusout', handleKeyboardFocusOut);

    byId('topupModalCloseButton')?.addEventListener('click', requestCloseModal);
    byId('topupActionModal')?.addEventListener('click', (event) => {
      if (event.target instanceof HTMLElement && event.target.hasAttribute('data-topup-modal-close')) {
        requestCloseModal();
      }
    });

    const hold = byId('topupHoldConfirmButton');
    hold?.addEventListener('pointerdown', handleHoldPointerDown);
    hold?.addEventListener('pointermove', handleHoldPointerMove);
    hold?.addEventListener('pointerup', handleHoldPointerEnd);
    hold?.addEventListener('pointercancel', handleHoldPointerEnd);
    hold?.addEventListener('pointerleave', (event) => {
      if (event.pointerType === 'mouse') handleHoldPointerEnd(event);
    });
    hold?.addEventListener('keydown', (event) => {
      if ((event.key === ' ' || event.key === 'Enter') && !event.repeat) {
        event.preventDefault();
        beginHold();
      }
    });
    hold?.addEventListener('keyup', (event) => {
      if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault();
        if (!state.hold.completed) cancelHold();
      }
    });
    hold?.addEventListener('contextmenu', (event) => event.preventDefault());
    hold?.addEventListener('dragstart', (event) => event.preventDefault());

    window.addEventListener('popstate', handlePopState);
    window.visualViewport?.addEventListener('resize', () => scheduleKeyboardLayout(0));
    window.visualViewport?.addEventListener('scroll', () => scheduleKeyboardLayout(0));
    window.addEventListener('resize', () => {
      if (isTopupKeyboardInput(document.activeElement)) {
        scheduleKeyboardLayout(0);
      } else {
        state.keyboard.baselineHeight = Math.max(window.innerHeight || 0, window.visualViewport?.height || 0);
      }
    });
    window.addEventListener('pagehide', () => {
      stopHoldTimers();
      resetKeyboardLayout({ restoreScroll: false });
      if (state.modal.kind === 'loading') hideModalImmediate({ restoreFocus: false });
    });
    window.addEventListener('pageshow', (event) => {
      if (!event.persisted) return;
      closeLoading();
      resetKeyboardLayout({ restoreScroll: false });
      resetHoldControl();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && state.modal.open && !state.modal.busy) requestCloseModal();
    });
  }

  async function init() {
    await shell.ready;
    bindEvents();
    const user = shell.state.bootstrapData?.user || {};
    state.uid = String(user.uid || '').trim();
    state.favoriteStorageKey = state.uid ? `zpay_topup_favorites_v1_${state.uid}` : 'zpay_topup_favorites_v1_session';
    loadFavorites();
    renderCountry();
    renderPresets();
    renderFavorites();
    history.replaceState(historyPayload('number'), '', window.location.href);
    applyStep('number', { focus: false });
    root.setAttribute('aria-busy', 'false');

    try {
      state.walletSummary = await shell.get('wallet_summary', {}, 'Loading wallet...', { busy: false });
    } catch (error) {
      if (!shell.isSessionError(error)) {
        openError('Top-Up Unavailable', safeErrorMessage(error, 'Top-up account details could not be loaded.'));
      }
    }
  }

  init().catch((error) => {
    if (!shell.isSessionError(error)) {
      bindEvents();
      openError('Top-Up Unavailable', safeErrorMessage(error, 'Mobile Top-Up could not be loaded.'));
    }
  });
})();
