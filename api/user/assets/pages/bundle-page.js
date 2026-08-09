(() => {
  'use strict';

  if (window.__zpayBundlePageInitialized) return;
  window.__zpayBundlePageInitialized = true;

  const shell = window.UserShell;
  const pageRoot = document.querySelector('.user-bundle-page #bundleSection');
  if (!shell || !pageRoot) return;

  const HOLD_DURATION_MS = 1200;
  const STEP_ORDER = ['operator', 'offers', 'number', 'pin', 'preview'];
  const OPERATORS = Object.freeze([
    { code: 'GP', label: 'Grameenphone' },
    { code: 'ROBI', label: 'Robi' },
    { code: 'AIRTEL', label: 'Airtel' },
    { code: 'BANGLALINK', label: 'Banglalink' },
    { code: 'TELETALK', label: 'Teletalk' }
  ]);
  const byId = (id) => document.getElementById(id);
  const scrollBody = byId('bundleScrollBody');

  const state = {
    initialized: false,
    offers: [],
    operator: '',
    offerLoadSerial: 0,
    selectedOffer: null,
    numberFull: '',
    numberValidated: null,
    preview: null,
    favorites: [],
    step: 'operator',
    busy: false,
    submitting: false,
    favoriteSaving: false,
    completed: false,
    idempotencyKey: '',
    modal: { open: false, loading: false, history: false, opener: null },
    hold: {
      active: false,
      completed: false,
      pointerId: null,
      timer: 0,
      frame: 0,
      startedAt: 0,
      startX: 0,
      startY: 0
    },
    keyboard: {
      baselineHeight: Math.max(window.innerHeight || 0, window.visualViewport?.height || 0),
      timer: 0
    }
  };

  function text(value, fallback = '') {
    const output = String(value ?? '').trim();
    return output || fallback;
  }

  function number(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }

  function money(value, currency = 'BDT') {
    return `${text(currency, 'BDT')} ${number(value).toFixed(2)}`;
  }

  function normalizeOperator(value) {
    const clean = text(value).toUpperCase().replace(/[^A-Z0-9]/g, '');
    const aliases = {
      GRAMEENPHONE: 'GP', ROBIAXIATA: 'ROBI', AIRTELBD: 'AIRTEL',
      BL: 'BANGLALINK', BANGLALINKDIGITAL: 'BANGLALINK',
      TT: 'TELETALK', TELETALKBD: 'TELETALK'
    };
    return aliases[clean] || clean;
  }

  function operatorLabel(value) {
    const labels = { GP: 'Grameenphone', ROBI: 'Robi', AIRTEL: 'Airtel', BANGLALINK: 'Banglalink', TELETALK: 'Teletalk', SKITTO: 'Skitto' };
    const normalized = normalizeOperator(value);
    return labels[normalized] || text(value, 'Operator');
  }

  function countryLabel(value) {
    const country = text(value, 'BD').toUpperCase();
    if (country === 'BD' || country === 'BANGLADESH') return 'Bangladesh';
    if (country === 'MY' || country === 'MALAYSIA') return 'Malaysia';
    return text(value, 'Bangladesh');
  }

  function normalizeBdNumber(value) {
    let digits = text(value).replace(/\D+/g, '');
    if (digits.startsWith('00880')) digits = digits.slice(5);
    else if (digits.startsWith('880')) digits = digits.slice(3);
    if (digits.length === 10 && digits.startsWith('1')) digits = `0${digits}`;
    return digits;
  }

  function maskNumber(value) {
    const digits = normalizeBdNumber(value);
    if (digits.length < 7) return digits || '-';
    return `${digits.slice(0, 4)}****${digits.slice(-3)}`;
  }

  function suggestedOperators(value) {
    const prefix = normalizeBdNumber(value).slice(0, 3);
    const map = {
      '013': ['GP', 'SKITTO'], '014': ['BANGLALINK'], '015': ['TELETALK'],
      '016': ['AIRTEL'], '017': ['GP'], '018': ['ROBI'], '019': ['BANGLALINK']
    };
    return map[prefix] || [];
  }

  function validNumberForOffer(value, offer = state.selectedOffer) {
    const digits = normalizeBdNumber(value);
    if (!/^01[3-9]\d{8}$/.test(digits)) return false;
    const expected = normalizeOperator(offer?.operator || offer?.operator_name);
    const suggestions = suggestedOperators(digits);
    return !expected || suggestions.includes(expected);
  }

  function offerId(offer) {
    return text(offer?.offer_id || offer?.id || offer?.bundle_id);
  }

  function offerName(offer) {
    const benefits = [];
    const internet = text(offer?.internet || offer?.data || offer?.data_text || offer?.internet_text);
    const minutes = text(offer?.minutes || offer?.minute);
    const sms = text(offer?.sms);
    if (internet) benefits.push(internet);
    if (minutes && minutes !== '0') benefits.push(/min/i.test(minutes) ? minutes : `${minutes} Minutes`);
    if (!benefits.length && sms && sms !== '0') benefits.push(/sms/i.test(sms) ? sms : `${sms} SMS`);
    if (benefits.length) return benefits.join(' + ');

    const original = text(offer?.bundle_name || offer?.name || offer?.description, 'Bundle Offer');
    const cleaned = original
      .replace(/\b\d+(?:\.\d+)?\s*(?:BDT|TK)\b/gi, ' ')
      .replace(/\b(?:BDT|TK)\s*\d+(?:\.\d+)?\b/gi, ' ')
      .replace(/\b\d+(?:\.\d+)?\s*(?:DAY|DAYS|D|HOUR|HOURS|HR|HRS|WEEK|WEEKS|MONTH|MONTHS|MIN|MINS|MINUTE|MINUTES)\b/gi, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    return cleaned || original;
  }

  function offerPrice(offer) {
    return number(offer?.price_amount ?? offer?.amount ?? offer?.offer_price);
  }

  function offerCommission(offer) {
    return number(offer?.user_commission ?? offer?.bundle_commission);
  }

  function validityUnit(value) {
    const unit = text(value).toUpperCase().replaceAll('.', '');
    if (['MIN', 'MINS', 'MINUTE', 'MINUTES'].includes(unit)) return 'MINUTE';
    if (['H', 'HR', 'HRS', 'HOUR', 'HOURS'].includes(unit)) return 'HOUR';
    if (['D', 'DAY', 'DAYS'].includes(unit)) return 'DAY';
    if (['WK', 'WKS', 'WEEK', 'WEEKS'].includes(unit)) return 'WEEK';
    if (['MO', 'MOS', 'MONTH', 'MONTHS'].includes(unit)) return 'MONTH';
    return '';
  }

  function formatValidity(value, rawUnit) {
    const amount = Number(value);
    const unit = validityUnit(rawUnit);
    if (!Number.isFinite(amount) || amount <= 0 || !unit) return '';
    const days = unit === 'MINUTE' ? amount / 1440
      : unit === 'HOUR' ? amount / 24
        : unit === 'WEEK' ? amount * 7
          : unit === 'MONTH' ? amount * 30
            : amount;
    if (days > 120) return '';
    const displayAmount = Number.isInteger(amount) ? String(amount) : amount.toFixed(1);
    const label = `${unit[0]}${unit.slice(1).toLowerCase()}${Math.abs(amount - 1) > 0.0001 ? 's' : ''}`;
    return `${displayAmount} ${label}`;
  }

  function validityFromText(value) {
    const input = text(value).replaceAll('_', ' ');
    const pattern = /\b(\d+(?:\.\d+)?)\s*(DAY|DAYS|D|HOUR|HOURS|HR|HRS|WEEK|WEEKS|MONTH|MONTHS|MIN|MINS|MINUTE|MINUTES)\b/gi;
    let match;
    let result = '';
    while ((match = pattern.exec(input)) !== null) {
      const formatted = formatValidity(match[1], match[2]);
      if (formatted) result = formatted;
    }
    return result;
  }

  function validityFromSeconds(value) {
    const seconds = Number(value);
    if (!Number.isInteger(seconds) || seconds <= 0) return '';
    if (seconds % 86400 === 0) return formatValidity(seconds / 86400, 'DAY');
    if (seconds % 3600 === 0) return formatValidity(seconds / 3600, 'HOUR');
    if (seconds % 60 === 0) return formatValidity(seconds / 60, 'MINUTE');
    return '';
  }

  function offerValidity(offer) {
    const label = validityFromText(offer?.validity_text || offer?.package_validity || offer?.bundle_validity || offer?.validity || offer?.duration_text);
    if (label) return label;
    const validityValue = [offer?.validity_value, offer?.package_validity_value, offer?.bundle_validity_value]
      .map(Number)
      .find((value) => Number.isFinite(value) && value > 0);
    const validityUnitValue = [offer?.validity_unit, offer?.package_validity_unit, offer?.bundle_validity_unit]
      .map((value) => text(value))
      .find(Boolean);
    const pair = formatValidity(
      validityValue,
      validityUnitValue
    );
    if (pair) return pair;
    const validitySeconds = [offer?.validity_seconds, offer?.package_validity_seconds, offer?.bundle_validity_seconds]
      .map(Number)
      .find((value) => Number.isInteger(value) && value > 0);
    const seconds = validityFromSeconds(validitySeconds);
    if (seconds) return seconds;
    return validityFromText(offer?.bundle_name || offer?.name) || 'Bundle';
  }

  function collectOffers(data) {
    const output = [];
    const seen = new Set();
    const scan = (value, key = '') => {
      if (!value) return;
      if (Array.isArray(value)) {
        value.forEach((item, index) => scan(item, String(index)));
        return;
      }
      if (typeof value !== 'object') return;
      const looksLikeOffer = value.offer_id || value.bundle_name || value.price_amount || value.offer_price;
      if (looksLikeOffer) {
        const item = { ...value };
        if (!item.offer_id && key) item.offer_id = key;
        const id = offerId(item);
        if (id && !seen.has(id)) {
          seen.add(id);
          output.push(item);
        }
        return;
      }
      Object.entries(value).forEach(([childKey, child]) => scan(child, childKey));
    };
    scan(data);
    return output.filter((offer) => offerId(offer));
  }

  function createElement(tag, className, content = '') {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (content !== '') node.textContent = String(content);
    return node;
  }

  function clear(node) {
    if (node) node.replaceChildren();
  }

  function makeIdempotencyKey() {
    if (window.crypto?.randomUUID) return `WEB-BUNDLE-${window.crypto.randomUUID()}`;
    const random = window.crypto?.getRandomValues ? window.crypto.getRandomValues(new Uint32Array(2)).join('') : Math.random().toString(36).slice(2);
    return `WEB-BUNDLE-${Date.now()}-${random}`;
  }

  function modalElements() {
    return {
      wrap: byId('bundleActionModal'), close: byId('bundleModalCloseButton'),
      icon: byId('bundleModalIcon'), spinner: byId('bundleModalSpinner'),
      title: byId('bundleModalTitle'), message: byId('bundleModalMessage'),
      body: byId('bundleModalBody'), actions: byId('bundleModalActions')
    };
  }

  function hideModalImmediate({ restoreFocus = true } = {}) {
    const modal = modalElements();
    if (!modal.wrap) return;
    modal.wrap.classList.remove('show', 'loading', 'error', 'success', 'dismissible');
    modal.wrap.setAttribute('aria-hidden', 'true');
    modal.wrap.inert = true;
    document.body.classList.remove('bundle-modal-open');
    pageRoot.inert = false;
    const opener = state.modal.opener;
    state.modal = { open: false, loading: false, history: false, opener: null };
    if (restoreFocus) opener?.focus?.({ preventScroll: true });
  }

  function setModal({ kind = 'info', title = 'Bundle', message = '', loading = false, body = null, actions = [], pushHistory = false }) {
    hideModalImmediate({ restoreFocus: false });
    const modal = modalElements();
    state.modal = {
      open: true,
      loading,
      history: Boolean(pushHistory),
      opener: document.activeElement instanceof HTMLElement ? document.activeElement : null
    };
    modal.title.textContent = title;
    modal.message.textContent = message;
    modal.message.hidden = !message;
    modal.icon.textContent = kind === 'success' ? '✓' : kind === 'error' ? '!' : '';
    modal.icon.hidden = loading || kind === 'info';
    modal.spinner.hidden = !loading;
    modal.close.hidden = loading;
    clear(modal.body);
    clear(modal.actions);
    modal.actions.classList.remove('bundle-success-actions');
    if (body instanceof Node) modal.body.appendChild(body);
    modal.body.hidden = !body;
    actions.forEach((action) => {
      const button = createElement('button', `bundle-modal-action ${action.primary ? '' : 'secondary'}`, action.label);
      button.type = 'button';
      if (action.id) button.id = action.id;
      button.disabled = Boolean(action.disabled);
      button.addEventListener('click', action.handler, { once: Boolean(action.once) });
      modal.actions.appendChild(button);
    });
    modal.actions.hidden = actions.length === 0;
    modal.wrap.classList.add('show');
    if (loading) modal.wrap.classList.add('loading');
    else if (kind === 'error') modal.wrap.classList.add('error', 'dismissible');
    else if (kind === 'success') modal.wrap.classList.add('success');
    else modal.wrap.classList.add('dismissible');
    modal.wrap.setAttribute('aria-hidden', 'false');
    modal.wrap.inert = false;
    document.body.classList.add('bundle-modal-open');
    pageRoot.inert = true;
    if (pushHistory) {
      window.history.pushState(historyState(state.step, true), '', window.location.href);
    }
    window.setTimeout(() => (actions.length ? modal.actions.querySelector('button') : modal.wrap)?.focus?.({ preventScroll: true }), 0);
  }

  function openLoading(message) {
    setModal({ loading: true, title: 'Please wait', message });
  }

  function closeLoading() {
    if (state.modal.loading) hideModalImmediate({ restoreFocus: false });
  }

  function safeMessage(error, fallback) {
    const message = text(error?.message);
    if (!message || /firebase|exception|stack|\/api\/|token|credential/i.test(message)) return fallback;
    return message;
  }

  async function postWithFreshCsrf(action, payload) {
    try {
      return await shell.post(action, payload || {}, '', { busy: false });
    } catch (error) {
      const code = text(error?.code).toUpperCase();
      const message = text(error?.message).toLowerCase();
      const csrfError = Number(error?.status || 0) === 403
        && (code.includes('CSRF') || code === 'FORBIDDEN' || message.includes('csrf'));
      if (!csrfError) throw error;
      await shell.refreshSession();
      return shell.post(action, payload || {}, '', { busy: false });
    }
  }

  function openError(title, error, fallback, pushHistory = true) {
    setModal({
      kind: 'error', title, message: safeMessage(error, fallback), pushHistory,
      actions: [{ label: 'OK', primary: true, handler: closeModalFromAction }]
    });
  }

  function closeModalFromAction() {
    if (!state.modal.open) return;
    if (window.history.state?.zpayBundleModal) {
      window.history.back();
      return;
    }
    hideModalImmediate();
  }

  function closeCompletedModalState() {
    const hasModalHistory = Boolean(window.history.state?.zpayBundleModal);
    hideModalImmediate({ restoreFocus: false });
    if (hasModalHistory) window.history.back();
  }

  function resultRow(label, value) {
    const row = createElement('div', 'bundle-result-row');
    row.append(createElement('span', '', label), createElement('strong', '', value));
    return row;
  }

  function historyState(step = state.step, modal = false) {
    return { ...(window.history.state || {}), zpayBundlePage: true, zpayBundleStep: step, zpayBundleModal: modal };
  }

  function setStep(nextStep, mode = 'push') {
    if (!STEP_ORDER.includes(nextStep)) return;
    if (nextStep === 'offers' && !state.operator) return;
    if (!['operator', 'offers'].includes(nextStep) && !state.selectedOffer) return;
    if (['pin', 'preview'].includes(nextStep) && !state.numberValidated) return;
    if (nextStep === 'preview' && !state.preview?.preview_token) return;
    if (nextStep === 'operator' && state.step !== 'operator') resetOperatorState();
    if (state.step === 'preview' && nextStep === 'pin') {
      state.preview = null;
      state.idempotencyKey = '';
      resetHold();
    }
    if (state.step === 'pin' && nextStep === 'number') {
      state.preview = null;
      state.idempotencyKey = '';
    }
    if (state.step === 'number' && nextStep === 'offers') {
      state.numberFull = '';
      state.numberValidated = null;
      state.preview = null;
      state.idempotencyKey = '';
      byId('bundleNumberInput').value = '';
    }
    state.step = nextStep;
    document.querySelectorAll('[data-bundle-step]').forEach((section) => {
      const active = section.dataset.bundleStep === nextStep;
      section.classList.toggle('active', active);
      section.hidden = !active;
    });
    pageRoot.dataset.bundleCurrentStep = nextStep;
    byId('bundlePinInput').value = nextStep === 'pin' ? byId('bundlePinInput').value : '';
    resetKeyboardLayout();
    scrollBody?.scrollTo({ top: 0, behavior: mode === 'replace' ? 'auto' : 'smooth' });
    const nextState = historyState(nextStep, false);
    if (mode === 'replace') window.history.replaceState(nextState, '', window.location.href);
    else if (mode === 'push') window.history.pushState(nextState, '', window.location.href);
  }

  function resetTransferState() {
    state.selectedOffer = null;
    state.numberFull = '';
    state.numberValidated = null;
    state.preview = null;
    state.completed = false;
    state.idempotencyKey = '';
    byId('bundleNumberInput').value = '';
    byId('bundlePinInput').value = '';
    resetHold();
  }

  function resetOperatorState() {
    state.offerLoadSerial++;
    state.offers = [];
    state.operator = '';
    resetTransferState();
    renderOperators();
    renderSelectedOperator();
    renderOfferSkeletons();
  }

  function operatorIcon() {
    const wrap = createElement('span', 'bundle-operator-icon');
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('aria-hidden', 'true');
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', 'M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v14h10V5H7Zm3 12h4v1h-4v-1Z');
    svg.appendChild(path);
    wrap.appendChild(svg);
    return wrap;
  }

  function renderOperators() {
    const wrap = byId('bundleOperatorGrid');
    clear(wrap);
    OPERATORS.forEach((operator) => {
      const button = createElement('button', 'bundle-operator-option');
      button.type = 'button';
      button.setAttribute('role', 'listitem');
      button.setAttribute('aria-label', `${operator.label}, prepaid`);
      button.append(operatorIcon(), createElement('strong', '', operator.label), createElement('small', '', 'PREPAID'));
      button.addEventListener('click', () => selectOperator(operator));
      wrap.appendChild(button);
    });
  }

  function renderSelectedOperator() {
    const wrap = byId('bundleSelectedOperator');
    if (wrap) wrap.textContent = state.operator ? `${operatorLabel(state.operator)} \u2022 PREPAID` : '';
  }

  function renderOfferSkeletons() {
    const grid = byId('bundleOffersGrid');
    if (!grid) return;
    clear(grid);
    grid.setAttribute('aria-busy', 'true');
    for (let index = 0; index < 3; index++) {
      const card = createElement('div', 'bundle-offer-card bundle-skeleton-card');
      card.setAttribute('aria-hidden', 'true');
      card.append(
        createElement('span', 'bundle-skeleton bundle-skeleton-title'),
        createElement('span', 'bundle-skeleton bundle-skeleton-pill'),
        createElement('span', 'bundle-skeleton bundle-skeleton-line'),
        createElement('span', 'bundle-skeleton bundle-skeleton-line short'),
        createElement('span', 'bundle-skeleton bundle-skeleton-button')
      );
      grid.appendChild(card);
    }
  }

  function renderOffers() {
    const grid = byId('bundleOffersGrid');
    clear(grid);
    grid.setAttribute('aria-busy', 'false');
    const offers = state.offers.filter((offer) => normalizeOperator(offer.operator || offer.operator_name) === normalizeOperator(state.operator));
    if (!offers.length) {
      grid.appendChild(createElement('div', 'bundle-empty-state', 'No bundles are available for this operator.'));
      return;
    }
    offers.forEach((offer) => {
      const card = createElement('article', 'bundle-offer-card');
      const heading = createElement('div', 'bundle-offer-top');
      const copy = createElement('div', 'bundle-offer-copy');
      const description = text(offer.category || offer.type || offer.bundle_type || offer.offer_type || offer.description);
      copy.append(createElement('h3', '', offerName(offer)));
      if (description && description.toLowerCase() !== offerName(offer).toLowerCase()) {
        copy.append(createElement('p', '', description));
      }
      heading.append(copy, createElement('span', 'bundle-validity-pill', offerValidity(offer)));
      const details = createElement('div', 'bundle-offer-details');
      const price = resultRow('Price', money(offerPrice(offer), 'BDT'));
      price.className = 'bundle-offer-row';
      const commission = resultRow('Bundle Commission', money(offerCommission(offer), 'BDT'));
      commission.className = 'bundle-offer-row commission';
      details.append(price, commission);
      const select = createElement('button', 'bundle-primary-button', 'Select Bundle');
      select.type = 'button';
      select.addEventListener('click', () => selectOffer(offer));
      card.append(heading, details, select);
      grid.appendChild(card);
    });
  }

  function selectedSummary(offer, includeNumber = false) {
    const card = createElement('div', 'bundle-summary-content');
    card.append(createElement('div', 'bundle-summary-title', offerName(offer)));
    const meta = createElement('div', 'bundle-summary-meta');
    meta.append(createElement('span', '', offerValidity(offer)), createElement('span', '', `Price: ${money(offerPrice(offer), 'BDT')}`));
    if (includeNumber) meta.append(createElement('span', '', maskNumber(state.numberFull)));
    card.append(meta);
    return card;
  }

  function selectOffer(offer) {
    if (state.busy) return;
    resetTransferState();
    state.selectedOffer = offer;
    state.operator = normalizeOperator(offer.operator || offer.operator_name);
    byId('bundleNumberSummary').replaceChildren(selectedSummary(offer));
    renderPinSummary();
    setStep('number');
    loadFavorites();
  }

  async function selectOperator(operator) {
    if (state.busy || state.submitting) return;
    const code = normalizeOperator(operator?.code);
    if (!code) return;
    state.offerLoadSerial++;
    const serial = state.offerLoadSerial;
    state.operator = code;
    state.offers = [];
    resetTransferState();
    renderSelectedOperator();
    renderOfferSkeletons();
    setStep('offers');
    await loadOffersForOperator(operator, serial);
  }

  function renderPinSummary() {
    const wrap = byId('bundlePinSummary');
    if (!wrap || !state.selectedOffer) return;
    wrap.replaceChildren(selectedSummary(state.selectedOffer, true));
  }

  async function loadOffersForOperator(operator, serial) {
    const grid = byId('bundleOffersGrid');
    try {
      const data = await shell.get('bundle_offers_panel', { operator: operator.code }, '', { busy: false });
      if (serial !== state.offerLoadSerial || normalizeOperator(operator.code) !== state.operator) return;
      state.offers = collectOffers(data).filter((offer) => normalizeOperator(offer.operator || offer.operator_name) === state.operator);
      renderOffers();
    } catch (error) {
      if (serial !== state.offerLoadSerial || normalizeOperator(operator.code) !== state.operator) return;
      if (grid) {
        clear(grid);
        grid.setAttribute('aria-busy', 'false');
        grid.appendChild(createElement('div', 'bundle-empty-state', safeMessage(error, 'Bundle list is unavailable. Please try again.')));
      }
      openError('Bundle Unavailable', error, 'Bundle list is unavailable. Please try again.');
    }
  }

  async function validateNumber() {
    if (state.busy || !state.selectedOffer) return;
    const fullNumber = normalizeBdNumber(byId('bundleNumberInput').value);
    if (!validNumberForOffer(fullNumber)) {
      openError('Invalid Number', new Error(`Enter a valid ${operatorLabel(state.operator)} mobile number.`), 'Enter a valid mobile number.');
      return;
    }
    state.busy = true;
    byId('bundleNumberContinueButton').disabled = true;
    openLoading('Validating mobile number...');
    try {
      const data = await shell.post('bundle_preview', {
        offer_id: offerId(state.selectedOffer), bundle_number: fullNumber, check_only: true
      }, '', { busy: false });
      state.numberFull = fullNumber;
      state.numberValidated = data;
      state.preview = null;
      state.idempotencyKey = '';
      byId('bundleNumberInput').value = fullNumber;
      renderPinSummary();
      closeLoading();
      setStep('pin');
    } catch (error) {
      closeLoading();
      openError('Number Not Valid', error, 'This mobile number could not be validated.');
    } finally {
      state.busy = false;
      byId('bundleNumberContinueButton').disabled = false;
    }
  }

  async function preparePreview() {
    if (state.busy || !state.numberValidated || !state.selectedOffer) return;
    const pinInput = byId('bundlePinInput');
    const pin = text(pinInput.value);
    if (!/^\d{4,6}$/.test(pin)) {
      openError('PIN Required', new Error('Enter your transaction PIN.'), 'Enter your transaction PIN.');
      return;
    }
    state.busy = true;
    byId('bundlePinContinueButton').disabled = true;
    openLoading('Preparing bundle preview...');
    try {
      await shell.post('validate_pin', { pin, purpose: 'BUNDLE' }, '', { busy: false });
      const data = await shell.post('bundle_preview', {
        offer_id: offerId(state.selectedOffer), bundle_number: state.numberFull, verified_by: 'PIN'
      }, '', { busy: false });
      if (!text(data.preview_token)) throw new Error('Secure bundle preview was not returned.');
      state.preview = data;
      state.idempotencyKey = makeIdempotencyKey();
      renderPreview();
      closeLoading();
      setStep('preview');
    } catch (error) {
      closeLoading();
      openError('Verification Failed', error, 'Bundle verification could not be completed.');
    } finally {
      pinInput.value = '';
      state.busy = false;
      byId('bundlePinContinueButton').disabled = false;
    }
  }

  function renderPreview() {
    const wrap = byId('bundlePreviewRows');
    clear(wrap);
    const preview = state.preview || {};
    const walletCurrency = text(preview.wallet_debit_currency || preview.wallet_currency, 'BDT').toUpperCase();
    const rows = [
      ['Operator', text(preview.operator_name || preview.operator, operatorLabel(state.operator))],
      ['Mobile Number', maskNumber(preview.bundle_number || state.numberFull)],
      ['Bundle', text(preview.bundle_name, offerName(state.selectedOffer))],
      ['Amount', money(preview.service_amount_bdt ?? preview.service_amount ?? preview.amount, 'BDT')],
      ['Commission', money(preview.bundle_commission ?? preview.user_commission, 'BDT')]
    ];
    if (walletCurrency === 'MYR' && number(preview.rate_used) > 0) rows.push(['Rate', `RM 1 = ${number(preview.rate_used).toFixed(2)} BDT`]);
    rows.push(
      ['Wallet Debit', money(preview.wallet_debit_amount ?? preview.wallet_hold_amount, walletCurrency)],
      ['Balance After', money(preview.balance_after, walletCurrency)]
    );
    rows.forEach(([label, value]) => {
      const row = resultRow(label, value);
      row.className = 'bundle-preview-row';
      wrap.appendChild(row);
    });
    resetHold();
  }

  function setHoldProgress(progress) {
    byId('bundleHoldConfirmButton')?.style.setProperty('--hold-progress', `${Math.max(0, Math.min(100, progress))}%`);
  }

  function setHoldLabel(label) {
    const node = byId('bundleHoldConfirmButton')?.querySelector('.bundle-hold-label');
    if (node) node.textContent = label;
  }

  function stopHoldTimers() {
    if (state.hold.timer) window.clearTimeout(state.hold.timer);
    if (state.hold.frame) window.cancelAnimationFrame(state.hold.frame);
    state.hold.timer = 0;
    state.hold.frame = 0;
  }

  function resetHold() {
    stopHoldTimers();
    Object.assign(state.hold, { active: false, completed: false, pointerId: null, startedAt: 0, startX: 0, startY: 0 });
    byId('bundleHoldConfirmButton')?.classList.remove('is-holding');
    setHoldProgress(0);
    setHoldLabel('Tap and hold to confirm bundle');
  }

  function animateHold() {
    if (!state.hold.active || state.hold.completed) return;
    setHoldProgress(((performance.now() - state.hold.startedAt) / HOLD_DURATION_MS) * 100);
    state.hold.frame = window.requestAnimationFrame(animateHold);
  }

  function completeHold() {
    if (!state.hold.active || state.hold.completed || state.submitting) return;
    state.hold.completed = true;
    stopHoldTimers();
    setHoldProgress(100);
    navigator.vibrate?.(40);
    submitBundle();
  }

  function beginHold(pointerId, clientX = 0, clientY = 0) {
    if (state.hold.active || state.submitting || state.completed || !state.preview?.preview_token) return;
    state.hold.active = true;
    state.hold.completed = false;
    state.hold.pointerId = pointerId;
    state.hold.startX = Number(clientX);
    state.hold.startY = Number(clientY);
    state.hold.startedAt = performance.now();
    byId('bundleHoldConfirmButton')?.classList.add('is-holding');
    setHoldLabel('Keep holding...');
    setHoldProgress(0);
    state.hold.frame = window.requestAnimationFrame(animateHold);
    state.hold.timer = window.setTimeout(completeHold, HOLD_DURATION_MS);
  }

  function handleHoldPointerDown(event) {
    if (event.pointerType === 'mouse' && event.button !== 0) return;
    try {
      event.currentTarget.setPointerCapture?.(event.pointerId);
    } catch (_) {
      // Pointer capture is optional; the hold still works on browsers that reject it.
    }
    beginHold(event.pointerId, event.clientX, event.clientY);
  }

  function handleHoldPointerMove(event) {
    if (!state.hold.active || state.hold.pointerId !== event.pointerId) return;
    if (Math.abs(event.clientX - state.hold.startX) > 16 || Math.abs(event.clientY - state.hold.startY) > 16) resetHold();
  }

  function handleHoldPointerEnd(event) {
    if (!state.hold.active || state.hold.pointerId !== event.pointerId) return;
    if (!state.hold.completed) resetHold();
  }

  function statusLabel(value) {
    const status = text(value, 'WAITING_ADMIN').toUpperCase();
    return status === 'WAITING_ADMIN' ? 'Pending' : status.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
  }

  function isBundleFavoriteSaved() {
    const currentNumber = normalizeBdNumber(state.numberFull);
    if (!currentNumber) return false;
    return state.favorites.some((favorite) => (
      normalizeBdNumber(favorite.number || favorite.phone) === currentNumber
      && text(favorite.country || favorite.country_code, 'BD').toUpperCase() === 'BD'
    ));
  }

  function setFavoriteButtonSaved(button) {
    if (!button) return;
    button.textContent = 'Saved';
    button.disabled = true;
    button.setAttribute('aria-label', 'Favorite number saved');
  }

  async function saveBundleSuccessFavorite(button) {
    if (state.favoriteSaving || isBundleFavoriteSaved()) {
      setFavoriteButtonSaved(button);
      return;
    }

    const fullNumber = normalizeBdNumber(state.numberFull);
    if (!/^01[3-9]\d{8}$/.test(fullNumber)) {
      shell.toast('Favorite number could not be saved.', 'error');
      return;
    }
    const operatorCode = state.operator;
    const operatorName = operatorLabel(operatorCode);

    state.favoriteSaving = true;
    if (button) {
      button.disabled = true;
      button.textContent = 'Saving...';
    }

    try {
      const data = await postWithFreshCsrf('bundle_favorite_add', {
        name: `${operatorName} Bundle`,
        number: fullNumber,
        country: 'BD',
        country_code: 'BD',
        operator: operatorCode,
        operator_name: operatorName,
        service_type: 'bundle'
      });

      if (Array.isArray(data.favorites)) {
        state.favorites = data.favorites;
      } else if (data.favorite && typeof data.favorite === 'object') {
        state.favorites.push(data.favorite);
      } else {
        state.favorites.push({
          name: `${operatorName} Bundle`,
          number: fullNumber,
          country: 'BD',
          operator: operatorCode
        });
      }
      setFavoriteButtonSaved(button);
      renderFavorites();
      shell.toast('Favorite number saved.', 'ok');
    } catch (error) {
      if (String(error?.code || '').toUpperCase() === 'FAVORITE_ALREADY_EXISTS') {
        state.favorites.push({ number: fullNumber, country: 'BD', operator: operatorCode });
        setFavoriteButtonSaved(button);
        shell.toast('Favorite number already saved.', 'ok');
      } else {
        if (button) {
          button.disabled = false;
          button.textContent = 'Favorite';
        }
        shell.toast(safeMessage(error, 'Favorite number could not be saved.'), 'error');
      }
    } finally {
      state.favoriteSaving = false;
    }
  }

  function showSuccess(result) {
    const preview = state.preview || {};
    const body = createElement('div', 'bundle-result-rows');
    [
      ['Number', maskNumber(result.bundle_number || state.numberFull)],
      ['Operator', text(result.operator_name || result.operator, operatorLabel(state.operator))],
      ['Bundle', text(result.bundle_name, offerName(state.selectedOffer))],
      ['Amount', money(result.service_amount_bdt ?? result.amount ?? preview.service_amount_bdt, 'BDT')],
      ['Commission', money(result.bundle_commission ?? preview.bundle_commission, 'BDT')],
      ['Status', statusLabel(result.status || preview.status)],
      ['Request ID', text(result.request_id, '-')]
    ].forEach(([label, value]) => body.appendChild(resultRow(label, value)));
    const alreadySaved = isBundleFavoriteSaved();
    setModal({
      kind: 'success', title: 'Success', message: 'Your bundle request has been submitted.', body, pushHistory: true,
      actions: [
        {
          id: 'bundleSuccessFavoriteButton',
          label: alreadySaved ? 'Saved' : 'Favorite',
          primary: true,
          disabled: alreadySaved,
          handler: () => saveBundleSuccessFavorite(byId('bundleSuccessFavoriteButton'))
        },
        { label: 'Done', handler: finishSuccess }
      ]
    });
    byId('bundleModalActions')?.classList.add('bundle-success-actions');
  }

  function finishSuccess() {
    const modalEntry = state.modal.history && window.history.state?.zpayBundleModal ? 1 : 0;
    const stepEntries = STEP_ORDER.indexOf(state.step);
    hideModalImmediate({ restoreFocus: false });
    resetOperatorState();
    state.step = 'operator';
    const distance = modalEntry + Math.max(0, stepEntries);
    if (distance > 0 && window.history.length > distance) {
      window.history.go(-distance);
    } else {
      window.history.replaceState(historyState('operator', false), '', window.location.href);
      setStep('operator', 'replace');
    }
    shell.get('wallet_summary', {}, '', { busy: false }).catch(() => {});
  }

  async function submitBundle() {
    if (state.submitting || state.completed || !state.preview?.preview_token) return;
    state.submitting = true;
    const holdButton = byId('bundleHoldConfirmButton');
    holdButton.disabled = true;
    openLoading('Submitting bundle request...');
    try {
      const result = await shell.post('bundle_submit', {
        offer_id: offerId(state.selectedOffer),
        bundle_number: state.numberFull,
        preview_token: state.preview.preview_token,
        verified_by: 'PIN',
        auth_method: 'PIN',
        idempotency_key: state.idempotencyKey
      }, '', { busy: false });
      state.completed = true;
      closeLoading();
      showSuccess(result);
    } catch (error) {
      closeLoading();
      resetHold();
      openError('Bundle Not Submitted', error, 'The bundle request could not be submitted.');
    } finally {
      state.submitting = false;
      holdButton.disabled = state.completed;
    }
  }

  function favoriteItems(data) {
    const rows = data?.favorites || data?.items || [];
    if (Array.isArray(rows)) return rows;
    return rows && typeof rows === 'object' ? Object.values(rows) : [];
  }

  async function loadFavorites() {
    const wrap = byId('bundleFavoriteList');
    if (!wrap) return;
    wrap.replaceChildren(createElement('div', 'bundle-empty-state', 'Loading favorite numbers...'));
    try {
      const data = await shell.get('bundle_favorites', {}, '', { busy: false });
      state.favorites = favoriteItems(data).filter((item) => /^01[3-9]\d{8}$/.test(normalizeBdNumber(item.number || item.phone)));
      renderFavorites();
    } catch (error) {
      wrap.replaceChildren(createElement('div', 'bundle-empty-state', safeMessage(error, 'Favorite numbers could not be loaded.')));
    }
  }

  function renderFavorites() {
    const wrap = byId('bundleFavoriteList');
    clear(wrap);
    const favorites = state.favorites.filter((favorite) => {
      const favoriteOperator = normalizeOperator(favorite.operator || favorite.operator_name);
      return favoriteOperator ? favoriteOperator === state.operator : suggestedOperators(favorite.number || favorite.phone).includes(state.operator);
    });
    if (!favorites.length) {
      wrap.appendChild(createElement('div', 'bundle-empty-state', 'No favorite numbers yet.'));
      return;
    }
    favorites.forEach((favorite) => {
      const full = normalizeBdNumber(favorite.number || favorite.phone);
      const row = createElement('div', 'bundle-favorite-row');
      const select = createElement('button', 'bundle-favorite-select');
      select.type = 'button';
      const details = createElement('span', 'bundle-favorite-details');
      details.append(createElement('strong', '', text(favorite.name || favorite.nickname, 'Favorite Number')),
        createElement('span', '', maskNumber(full)),
        createElement('small', '', `${countryLabel(favorite.country_name || favorite.country)} • ${operatorLabel(favorite.operator)}`));
      select.append(details);
      select.addEventListener('click', () => {
        byId('bundleNumberInput').value = full;
        state.numberValidated = null;
        state.preview = null;
        byId('bundleNumberInput').focus({ preventScroll: true });
      });
      const menu = createElement('button', 'bundle-favorite-more', '⋮');
      menu.type = 'button';
      menu.setAttribute('aria-label', `View ${text(favorite.name, 'favorite')} details`);
      menu.addEventListener('click', () => openFavoriteDetails(favorite));
      row.append(select, menu);
      wrap.appendChild(row);
    });
  }

  function openFavoriteDetails(favorite) {
    const body = createElement('div', 'bundle-result-rows');
    body.append(
      resultRow('Name', text(favorite.name || favorite.nickname, 'Favorite Number')),
      resultRow('Number', maskNumber(favorite.number || favorite.phone)),
      resultRow('Country', countryLabel(favorite.country_name || favorite.country)),
      resultRow('Operator', operatorLabel(favorite.operator))
    );
    setModal({
      title: 'Favorite Number', body, pushHistory: true,
      actions: [
        { label: 'Edit', primary: true, handler: () => openFavoriteEdit(favorite) },
        { label: 'Remove', handler: () => removeFavorite(favorite) }
      ]
    });
  }

  function openFavoriteEdit(favorite) {
    hideModalImmediate({ restoreFocus: false });
    const body = createElement('label', 'bundle-favorite-name-field');
    body.appendChild(createElement('span', '', 'Name'));
    const input = createElement('input', 'bundle-favorite-name-input');
    input.type = 'text';
    input.maxLength = 40;
    input.value = text(favorite.name || favorite.nickname);
    body.appendChild(input);
    setModal({
      title: 'Edit Favorite', body, pushHistory: false,
      actions: [
        { label: 'Cancel', handler: closeModalFromAction },
        { label: 'Save', primary: true, handler: async () => {
          try {
            await shell.post('bundle_favorite_update', { favorite_id: favorite.favorite_id || favorite.id, name: text(input.value) }, '', { busy: false });
            closeCompletedModalState();
            await loadFavorites();
          } catch (error) {
            openError('Favorite Not Updated', error, 'Favorite number could not be updated.', false);
          }
        } }
      ]
    });
    window.setTimeout(() => input.focus(), 0);
  }

  async function removeFavorite(favorite) {
    try {
      await shell.post('bundle_favorite_remove', { favorite_id: favorite.favorite_id || favorite.id }, '', { busy: false });
      closeCompletedModalState();
      await loadFavorites();
    } catch (error) {
      openError('Favorite Not Removed', error, 'Favorite number could not be removed.', false);
    }
  }

  function resetKeyboardLayout() {
    if (state.keyboard.timer) window.clearTimeout(state.keyboard.timer);
    state.keyboard.timer = 0;
    pageRoot.style.removeProperty('--bundle-keyboard-inset');
  }

  function updateKeyboardLayout() {
    const viewport = window.visualViewport;
    if (!viewport) return;
    state.keyboard.baselineHeight = Math.max(state.keyboard.baselineHeight, window.innerHeight || 0);
    const inset = Math.max(0, state.keyboard.baselineHeight - viewport.height - viewport.offsetTop);
    pageRoot.style.setProperty('--bundle-keyboard-inset', `${inset}px`);
    const active = document.activeElement;
    if (inset > 80 && active && pageRoot.contains(active)) {
      state.keyboard.timer = window.setTimeout(() => {
        active.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
      }, 80);
    }
  }

  function handleFocusIn(event) {
    if (!event.target.matches('input, textarea, select')) return;
    state.keyboard.timer = window.setTimeout(updateKeyboardLayout, 80);
  }

  function handleFocusOut() {
    state.keyboard.timer = window.setTimeout(() => {
      if (!pageRoot.contains(document.activeElement) || !document.activeElement.matches('input, textarea, select')) resetKeyboardLayout();
    }, 180);
  }

  function previousStep() {
    const index = STEP_ORDER.indexOf(state.step);
    return index > 0 ? STEP_ORDER[index - 1] : '';
  }

  function handleHeaderBack(event) {
    if (state.modal.open) {
      event.preventDefault();
      if (!state.modal.loading) closeModalFromAction();
      return;
    }
    if (state.busy || state.submitting) {
      event.preventDefault();
      return;
    }
    if (state.step !== 'operator') {
      event.preventDefault();
      window.history.back();
    }
  }

  function handlePopState(event) {
    if (state.modal.open) {
      event.stopImmediatePropagation();
      if (state.modal.loading || state.busy || state.submitting) {
        window.history.pushState(historyState(state.step, false), '', window.location.href);
      } else {
        hideModalImmediate();
      }
      return;
    }
    if (state.busy || state.submitting) {
      event.stopImmediatePropagation();
      window.history.pushState(historyState(state.step, false), '', window.location.href);
      return;
    }
    const requested = text(event.state?.zpayBundleStep);
    if (STEP_ORDER.includes(requested)) {
      if (state.step === 'pin' || requested !== 'pin') byId('bundlePinInput').value = '';
      setStep(requested, 'none');
      return;
    }
    const previous = previousStep();
    if (previous) setStep(previous, 'replace');
  }

  function bindEvents() {
    byId('bundleBackButton')?.addEventListener('click', handleHeaderBack);
    byId('bundleNumberContinueButton')?.addEventListener('click', validateNumber);
    byId('bundlePinContinueButton')?.addEventListener('click', preparePreview);
    byId('bundleNumberInput')?.addEventListener('input', () => {
      state.numberValidated = null;
      state.preview = null;
      state.idempotencyKey = '';
    });
    byId('bundlePinInput')?.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') preparePreview();
    });
    byId('bundleModalCloseButton')?.addEventListener('click', closeModalFromAction);
    document.querySelector('[data-bundle-modal-close]')?.addEventListener('click', () => {
      if (!state.modal.loading) closeModalFromAction();
    });

    const hold = byId('bundleHoldConfirmButton');
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
        beginHold('keyboard');
      }
    });
    hold?.addEventListener('keyup', (event) => {
      if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault();
        if (!state.hold.completed) resetHold();
      }
    });
    hold?.addEventListener('contextmenu', (event) => event.preventDefault());
    hold?.addEventListener('dragstart', (event) => event.preventDefault());

    pageRoot.addEventListener('focusin', handleFocusIn);
    pageRoot.addEventListener('focusout', handleFocusOut);
    window.visualViewport?.addEventListener('resize', updateKeyboardLayout);
    window.visualViewport?.addEventListener('scroll', updateKeyboardLayout);
    window.addEventListener('resize', () => {
      if (!document.activeElement?.matches?.('input, textarea, select')) {
        state.keyboard.baselineHeight = Math.max(window.innerHeight || 0, window.visualViewport?.height || 0);
        resetKeyboardLayout();
      }
    });
    window.addEventListener('popstate', handlePopState);
  }

  async function boot() {
    if (state.initialized) return;
    state.initialized = true;
    bindEvents();
    renderOperators();
    renderSelectedOperator();
    STEP_ORDER.forEach((step) => {
      const node = document.querySelector(`[data-bundle-step="${step}"]`);
      if (node) node.hidden = step !== 'operator';
    });
    window.history.replaceState(historyState('operator', false), '', window.location.href);
    try {
      await shell.ready;
    } catch (_) {
      // UserShell handles expired sessions and bootstrap errors.
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
