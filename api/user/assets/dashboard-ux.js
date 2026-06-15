// Z-Pay Swift user dashboard UX helper.
// Quick services + bKash/Nagad frontend flow.
// MFS create uses the public API path so it works from the clean /user/ URL.
(function(){
  'use strict';

  function byId(id){ return document.getElementById(id); }
  function money(v){ var n = Number(v || 0); return Number.isFinite(n) ? n.toFixed(2) : '0.00'; }
  function esc(v){
    return String(v == null ? '' : v).replace(/[&<>"']/g, function(s){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s];
    });
  }

  var serverPreview = null;
  var amountSyncing = false;
  var previewCanContinue = true;

  function openSection(id){
    if (typeof window.openSection === 'function') { window.openSection(id); return; }
    document.querySelectorAll('.page-section').forEach(function(n){ n.classList.remove('active'); });
    document.querySelectorAll('.side-btn,.bottom-btn').forEach(function(n){
      n.classList.toggle('active', n.getAttribute('data-page-section') === id);
    });
    var section = byId(id);
    if (section) section.classList.add('active');
  }

  function selectedProvider(){
    var active = document.querySelector('.mfs-provider-choice.active');
    return String(active ? active.getAttribute('data-provider') || '' : 'BKASH').toUpperCase();
  }

  function providerName(p){
    p = String(p || '').toUpperCase();
    if (p === 'NAGAD') return 'Nagad';
    if (p === 'BKASH') return 'bKash';
    return p || 'bKash';
  }

  function setProvider(provider){
    provider = String(provider || 'BKASH').toUpperCase();
    document.querySelectorAll('.mfs-provider-choice').forEach(function(btn){
      btn.classList.toggle('active', String(btn.getAttribute('data-provider') || '').toUpperCase() === provider);
    });
    var title = document.querySelector('#mfsSection .section-title');
    var sub = document.querySelector('#mfsSection .section-sub');
    if (title) title.textContent = providerName(provider) + ' Send Money';
    if (sub) sub.textContent = 'Personal ' + providerName(provider) + ' request. Review, PIN confirm and secure processing.';
    renderPreview();
  }

  function data(){
    return {
      provider: selectedProvider(),
      receiver_number: String(byId('mfsReceiverNumber') ? byId('mfsReceiverNumber').value : '').trim(),
      amount_bdt: Number(byId('mfsAmountBdt') ? byId('mfsAmountBdt').value || 0 : 0),
      amount_rm: isMyrMfsAccount() ? Number(byId('mfsAmountRm') ? byId('mfsAmountRm').value || 0 : 0) : 0,
      reference: String(byId('mfsReference') ? byId('mfsReference').value : '').trim(),
      pin: String(byId('mfsPin') ? byId('mfsPin').value : '').trim()
    };
  }

  function walletMeta(){
    var appState = window.userState || {};
    var summary = appState.walletSummary || {};
    var wallet = summary.wallet || {};
    var me = appState.me || {};
    var currency = normalizeCurrency(wallet.display_currency || wallet.wallet_currency || wallet.currency || summary.wallet_currency || '');
    var country = normalizeCountry(
      summary.pricing_country ||
      summary.market_country ||
      summary.service_country ||
      wallet.pricing_country ||
      wallet.market_country ||
      wallet.service_country ||
      me.pricing_country ||
      me.market_country ||
      me.service_country ||
      summary.country_code ||
      summary.country ||
      wallet.country_code ||
      wallet.country ||
      me.country_code ||
      me.country ||
      ''
    );
    var preview = serverPreview || {};
    var rate = Number(preview.rate_myr_to_bdt || preview.exchange_rate || wallet.rate_myr_bdt || wallet.rate_myr_to_bdt || summary.rate_myr_bdt || 0);

    return {
      currency: currency,
      country: country,
      rate: Number.isFinite(rate) ? rate : 0,
      isMyr: country === 'MY' || (currency === 'MYR' && country !== 'BD')
    };
  }

  function normalizeCountry(value){
    var country = String(value || '').toUpperCase().trim();
    if (country === 'BANGLADESH') return 'BD';
    if (country === 'MALAYSIA') return 'MY';
    if (['BD','MY'].indexOf(country) >= 0) return country;
    return '';
  }

  function countryLabel(country){
    country = normalizeCountry(country);
    if (country === 'BD') return 'Bangladesh';
    if (country === 'MY') return 'Malaysia';
    return '-';
  }

  function modeLabel(mode){
    mode = String(mode || '').toUpperCase().trim();
    if (mode === 'LOCAL') return 'Local';
    if (mode === 'REMITTANCE') return 'Remittance';
    return '-';
  }

  function normalizeCurrency(value){
    var currency = String(value || '').toUpperCase().trim();
    if (['MYR','RM','MY'].indexOf(currency) >= 0) return 'MYR';
    if (['BDT','BD','TK'].indexOf(currency) >= 0) return 'BDT';
    return '';
  }

  function currencyPrefix(currency){
    return normalizeCurrency(currency) === 'MYR' ? 'RM' : 'BDT';
  }

  function firstNumber(){
    for (var i = 0; i < arguments.length; i++) {
      var value = arguments[i];
      if (value === undefined || value === null || value === '') continue;
      var n = Number(value);
      if (Number.isFinite(n)) return n;
    }
    return NaN;
  }

  function walletCurrencyForPreview(p, remittance){
    var appState = window.userState || {};
    var summary = appState.walletSummary || {};
    var wallet = summary.wallet || {};
    var candidates = [
      p.display_currency,
      p.wallet_currency,
      p.currency,
      wallet.display_currency,
      wallet.wallet_currency,
      wallet.currency,
      summary.wallet_currency
    ];

    for (var i = 0; i < candidates.length; i++) {
      var normalized = normalizeCurrency(candidates[i]);
      if (normalized) return normalized;
    }

    var country = String(
      p.pricing_country ||
      p.market_country ||
      p.service_country ||
      p.country_code ||
      p.country ||
      summary.pricing_country ||
      summary.market_country ||
      summary.service_country ||
      wallet.pricing_country ||
      wallet.market_country ||
      wallet.service_country ||
      summary.country_code ||
      wallet.country_code ||
      ''
    ).toUpperCase();
    if (country === 'MY' || remittance) return 'MYR';
    return 'BDT';
  }

  function availableForPreview(p, currency){
    var appState = window.userState || {};
    var summary = appState.walletSummary || {};
    var wallet = summary.wallet || {};
    if (currency === 'MYR') {
      return firstNumber(
        normalizeCurrency(p.display_currency) === 'MYR' ? p.display_available_balance : undefined,
        p.available_balance_myr,
        normalizeCurrency(wallet.display_currency) === 'MYR' ? wallet.display_available_balance : undefined,
        wallet.available_balance_myr,
        p.available_balance,
        p.wallet_balance,
        wallet.available_balance
      );
    }
    return firstNumber(
      normalizeCurrency(p.display_currency) === 'BDT' ? p.display_available_balance : undefined,
      p.available_balance_bdt,
      normalizeCurrency(wallet.display_currency) === 'BDT' ? wallet.display_available_balance : undefined,
      wallet.available_balance_bdt,
      p.available_balance,
      p.wallet_balance,
      wallet.available_balance
    );
  }

  function debitForPreview(p, currency, d){
    if (currency === 'MYR') {
      return firstNumber(
        normalizeCurrency(p.display_currency) === 'MYR' ? p.display_total_pay : undefined,
        p.total_pay_myr,
        p.total_debit_rm,
        normalizeCurrency(p.wallet_currency) === 'MYR' ? p.wallet_hold_amount : undefined,
        normalizeCurrency(p.wallet_currency) === 'MYR' ? p.total_pay : undefined,
        d.amount_rm
      );
    }
    return firstNumber(
      normalizeCurrency(p.display_currency) === 'BDT' ? p.display_total_pay : undefined,
      p.total_pay_bdt,
      p.total_debit_bdt,
      normalizeCurrency(p.wallet_currency) === 'BDT' ? p.wallet_hold_amount : undefined,
      normalizeCurrency(p.wallet_currency) === 'BDT' ? p.total_pay : undefined,
      d.amount_bdt
    );
  }

  function isMyrMfsAccount(){
    return walletMeta().isMyr;
  }

  function previewFacts(d){
    var p = serverPreview || {};
    var meta = walletMeta();
    var country = normalizeCountry(
      p.pricing_country ||
      p.market_country ||
      p.service_country ||
      p.country_code ||
      p.country ||
      p.display_country ||
      meta.country
    );
    var mode = String(p.service_mode || p.mode || '').toUpperCase().trim();
    var reviewCurrency = walletCurrencyForPreview(p, false);
    var localBd = country === 'BD' && (mode === '' || mode === 'LOCAL');
    var remittance = !localBd && (
      country === 'MY' ||
      mode === 'REMITTANCE' ||
      reviewCurrency === 'MYR' ||
      meta.isMyr ||
      Number(p.amount_myr || p.amount_rm || d.amount_rm || 0) > 0
    );

    if (remittance) {
      reviewCurrency = 'MYR';
      if (!mode) mode = 'REMITTANCE';
      if (!country) country = 'MY';
    } else {
      reviewCurrency = 'BDT';
      if (!mode) mode = 'LOCAL';
      if (!country) country = 'BD';
    }

    return { p: p, meta: meta, country: country, mode: mode, reviewCurrency: reviewCurrency, remittance: remittance };
  }

  function updateCurrencyUi(){
    var meta = walletMeta();
    var field = byId('mfsAmountRmField') || (byId('mfsAmountRm') ? byId('mfsAmountRm').closest('.field') : null);
    if (field) field.classList.toggle('hidden', !meta.isMyr);
    if (!meta.isMyr && byId('mfsAmountRm')) byId('mfsAmountRm').value = '';
    var rateHint = byId('mfsRateHint');
    if (rateHint) {
      rateHint.textContent = meta.isMyr && meta.rate > 0 ? 'Rate: RM 1 = BDT ' + money(meta.rate) : '';
      rateHint.classList.toggle('active', meta.isMyr && meta.rate > 0);
    }
    return meta;
  }

  function syncAmounts(source){
    var meta = updateCurrencyUi();
    if (!meta.isMyr || meta.rate <= 0 || amountSyncing) return;

    var bdt = byId('mfsAmountBdt');
    var rm = byId('mfsAmountRm');
    if (!bdt || !rm) return;

    amountSyncing = true;

    try {
      if (source === 'bdt') {
        var bdtValue = Number(bdt.value || 0);
        rm.value = bdtValue > 0 ? money(bdtValue / meta.rate) : '';
      } else if (source === 'rm') {
        var rmValue = Number(rm.value || 0);
        bdt.value = rmValue > 0 ? money(rmValue * meta.rate) : '';
      }
    } finally {
      amountSyncing = false;
    }
  }

  function syncPreviewAmountsToInputs(){
    var p = serverPreview || {};
    if (!isMyrMfsAccount()) return;

    var bdt = byId('mfsAmountBdt');
    var rm = byId('mfsAmountRm');
    var amountBdt = firstNumber(p.amount_bdt);
    var amountRm = firstNumber(p.amount_myr, p.amount_rm);

    amountSyncing = true;
    try {
      if (bdt && Number.isFinite(amountBdt) && amountBdt > 0) bdt.value = money(amountBdt);
      if (rm && Number.isFinite(amountRm) && amountRm > 0) rm.value = money(amountRm);
    } finally {
      amountSyncing = false;
    }
  }

  function previewHtml(d){
    var facts = previewFacts(d);
    var p = facts.p;
    var meta = facts.meta;
    var country = facts.country;
    var mode = facts.mode;
    var remittance = facts.remittance;
    var reviewCurrency = facts.reviewCurrency;
    var feeText = remittance
      ? 'RM ' + money(p.fee_myr || p.fee_rm || 0)
      : 'BDT ' + money(p.fee_bdt || 0);
    var totalText = remittance
      ? 'RM ' + money(p.total_pay_myr || p.total_debit_rm || ((Number(p.amount_myr || p.amount_rm || d.amount_rm || 0)) + Number(p.fee_myr || p.fee_rm || 0)))
      : 'BDT ' + money(p.total_pay_bdt || p.total_debit_bdt || p.wallet_hold_amount || 0);
    var rate = Number(p.rate_myr_to_bdt || p.exchange_rate || meta.rate || 0);
    var available = availableForPreview(p, reviewCurrency);
    var debit = debitForPreview(p, reviewCurrency, d);
    var responseAfter = firstNumber(normalizeCurrency(p.display_currency) === reviewCurrency ? p.display_balance_after : undefined);
    var after = Number.isFinite(responseAfter) ? responseAfter : (Number.isFinite(available) && Number.isFinite(debit) ? available - debit : NaN);
    var explicitCanPay = p.can_pay;
    var canPayFlag = !(explicitCanPay === false || explicitCanPay === 0 || String(explicitCanPay).toLowerCase() === 'false');
    var hasPreview = !!serverPreview;
    previewCanContinue = !hasPreview || (
      canPayFlag &&
      (!Number.isFinite(after) || after >= 0) &&
      (!Number.isFinite(available) || !Number.isFinite(debit) || available >= debit)
    );
    var balanceAfterText = Number.isFinite(after)
      ? currencyPrefix(reviewCurrency) + ' ' + money(Math.max(after, 0))
      : '';
    return '<div class="mfs-review-grid">' +
      '<div class="zpay-mfs-preview-row"><span>Provider</span><b>' + esc(providerName(d.provider)) + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Receiver</span><b>' + esc(d.receiver_number || '-') + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Country</span><b>' + esc(countryLabel(country)) + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Mode</span><b>' + esc(modeLabel(mode)) + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Received Amount</span><b>BDT ' + money(p.amount_bdt || d.amount_bdt) + '</b></div>' +
      (remittance ? '<div class="zpay-mfs-preview-row"><span>Send Amount</span><b>RM ' + money(p.amount_myr || p.amount_rm || d.amount_rm) + '</b></div>' : '') +
      (remittance && rate > 0 ? '<div class="zpay-mfs-preview-row"><span>Rate</span><b>RM 1 = BDT ' + money(rate) + '</b></div>' : '') +
      '<div class="zpay-mfs-preview-row"><span>Fee</span><b>' + esc(feeText) + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Total Pay</span><b>' + esc(totalText) + '</b></div>' +
      (Number.isFinite(available) ? '<div class="zpay-mfs-preview-row"><span>Available Balance</span><b>' + currencyPrefix(reviewCurrency) + ' ' + money(available) + '</b></div>' : '') +
      (balanceAfterText ? '<div class="zpay-mfs-preview-row"><span>Balance After</span><b>' + esc(balanceAfterText) + '</b></div>' : '') +
      '<div class="zpay-mfs-preview-row"><span>Reference</span><b>' + esc(d.reference || '-') + '</b></div>' +
      '</div>';
  }

  function updateContinueButton(){
    var btn = byId('mfsSendBtn');
    if (!btn) return;
    btn.disabled = serverPreview ? !previewCanContinue : false;
    btn.title = btn.disabled ? 'Insufficient available balance' : '';
  }

  function setMfsPinError(message){
    var pin = byId('mfsPin');
    if (!pin || !pin.parentNode) return;
    var node = byId('mfsPinError');
    if (!node) {
      node = document.createElement('div');
      node.id = 'mfsPinError';
      node.className = 'mfs-pin-error';
      pin.insertAdjacentElement('afterend', node);
    }
    node.textContent = message || '';
    node.classList.toggle('active', !!message);
  }

  function setMfsAmountNotice(message){
    var node = byId('mfsAmountNotice');
    if (!node) return;
    node.textContent = message || '';
    node.classList.toggle('active', !!message);
  }

  function isAuthError(err){
    var code = String(err && err.code || '').toUpperCase();
    var status = Number(err && err.status || 0);
    var msg = String(err && err.message || '').toLowerCase();
    if (['INVALID_PIN','VALIDATION_ERROR','INSUFFICIENT_BALANCE'].indexOf(code) >= 0) {
      return false;
    }
    return status === 401 ||
      ['AUTH_ERROR','UNAUTHORIZED','SESSION_EXPIRED','USER_SESSION_EXPIRED'].indexOf(code) >= 0 ||
      (code === 'FORBIDDEN' && msg.indexOf('session') >= 0) ||
      (msg.indexOf('session') >= 0 && (msg.indexOf('expired') >= 0 || msg.indexOf('not found') >= 0));
  }

  function isPinError(err){
    var msg = String(err && err.message || '').toLowerCase();
    var code = String(err && err.code || '').toUpperCase();
    return code.indexOf('PIN') >= 0 || msg.indexOf('pin') >= 0;
  }

  function renderPreview(){
    updateCurrencyUi();
    var d = data();
    var live = byId('mfsLivePreview') || byId('mfsPreviewBox');
    var details = byId('mfsPreviewDetails');
    if (live) { live.className = 'bundle-result-box'; live.innerHTML = previewHtml(d); }
    if (details) details.innerHTML = previewHtml(d);
    updateContinueButton();
  }

  async function loadServerPreview(){
    var d = data();
    if (typeof window.proxyPost !== 'function') {
      serverPreview = null;
      renderPreview();
      return;
    }

    serverPreview = await window.proxyPost('mfs_preview', {
      provider: d.provider,
      service_type: 'SEND_MONEY',
      account_type: 'PERSONAL',
      receiver_number: d.receiver_number,
      amount_bdt: d.amount_bdt,
      amount_rm: isMyrMfsAccount() ? d.amount_rm : 0,
      amount_myr: isMyrMfsAccount() ? d.amount_rm : 0,
      reference: d.reference
    }, 'Loading send money preview...', { busy: false });
    syncPreviewAmountsToInputs();
    renderPreview();
  }

  async function validatePinBeforeReview(pin){
    if (typeof window.proxyPost !== 'function') return;
    await window.proxyPost('validate_pin', { pin: pin }, 'Checking PIN...', { busy: false });
  }

  function mfsStepHistoryName(id){
    if (id === 'mfsStepAmount') return 'amount';
    if (id === 'mfsStepPin') return 'pin';
    if (id === 'mfsStepPreview') return 'review';
    return 'form';
  }

  function mfsStepIdFromName(name){
    name = String(name || '').toLowerCase();
    if (name === 'amount') return 'mfsStepAmount';
    if (name === 'pin') return 'mfsStepPin';
    if (name === 'review' || name === 'preview') return 'mfsStepPreview';
    return 'mfsStepForm';
  }

  function showStep(id, options){
    options = options || {};
    ['mfsStepForm','mfsStepAmount','mfsStepPreview','mfsStepPin'].forEach(function(stepId){
      var n = byId(stepId);
      if (n) n.classList.remove('active');
    });
    var target = byId(id);
    if (target) target.classList.add('active');
    if (id === 'mfsStepPin') setMfsPinError('');
    if (id === 'mfsStepAmount') setMfsAmountNotice('');
    if (typeof window.syncUserModalLock === 'function') {
      window.syncUserModalLock();
    } else {
      document.body.classList.toggle('flow-modal-open', id === 'mfsStepAmount' || id === 'mfsStepPreview' || id === 'mfsStepPin');
    }

    if (!options.fromHistory) {
      if (id === 'mfsStepForm') {
        if (typeof window.replaceUserFlowHistory === 'function') window.replaceUserFlowHistory('dashboard', 'guard');
      } else if (typeof window.pushUserFlowHistory === 'function') {
        window.pushUserFlowHistory('mfs', mfsStepHistoryName(id));
      }
    }
  }

  function validNumberStep(){
    var d = data();
    var num = d.receiver_number.replace(/\D+/g, '');
    if (!/^01\d{9}$/.test(num)) {
      if (typeof showToast === 'function') showToast('Receiver number must be 11 digit BD number', 'error');
      if (typeof showMfsErrorModal === 'function') showMfsErrorModal('Validation Error', 'Receiver number must be 11 digit BD number');
      return false;
    }
    return true;
  }

  function validAmountStep(){
    var d = data();
    if (d.amount_bdt <= 0 && d.amount_rm <= 0) {
      if (typeof showToast === 'function') showToast('Amount is required', 'error');
      setMfsAmountNotice('Amount is required');
      return false;
    }
    if (d.amount_bdt > 0 && (d.amount_bdt < 500 || d.amount_bdt > 50000)) {
      if (typeof showToast === 'function') showToast('Amount must be between BDT 500 and BDT 50,000', 'error');
      setMfsAmountNotice('Amount must be between BDT 500 and BDT 50,000');
      return false;
    }
    setMfsAmountNotice('');
    return true;
  }

  function validBase(){
    if (!validNumberStep()) return false;
    if (!validAmountStep()) return false;
    return true;
  }

  async function createWithTelegramButtons(d){
    var payload = {
      provider: d.provider,
      service_type: 'SEND_MONEY',
      account_type: 'PERSONAL',
      receiver_number: d.receiver_number,
      amount_bdt: d.amount_bdt,
      amount_rm: isMyrMfsAccount() ? d.amount_rm : 0,
      amount_myr: isMyrMfsAccount() ? d.amount_rm : 0,
      reference: d.reference,
      pin: d.pin,
      note: providerName(d.provider) + ' request from user panel'
    };
    var res = await fetch('/api/user/mfs_create_telegram.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/json','Accept':'application/json','Cache-Control':'no-cache'},
      body: JSON.stringify(payload)
    });
    var text = await res.text();
    var json;
    try { json = JSON.parse(text); } catch(e) { throw new Error(text || 'Invalid server response'); }
    if (!res.ok || !json.ok) {
      var err = new Error(json.message || 'Failed to create request');
      err.status = res.status;
      err.code = json.code || (json.data && json.data.code) || '';
      err.data = json.data || null;
      throw err;
    }
    return json.data || {};
  }

  async function confirmMfs(){
    var d = data();
    if (!validBase()) return;
    if (!d.pin) {
      if (typeof showToast === 'function') showToast('PIN is required', 'error');
      setMfsPinError('PIN is required');
      showStep('mfsStepPin');
      return;
    }
    if (serverPreview && !previewCanContinue) {
      showStep('mfsStepAmount');
      setMfsAmountNotice('Insufficient available balance');
      if (typeof showMfsErrorModal === 'function') {
        showMfsErrorModal(
          'Insufficient Balance',
          'Your available balance is not enough for this send money request.',
          { retryStep: 'amount', editStep: 'amount' }
        );
      } else if (typeof showToast === 'function') {
        showToast('Insufficient available balance', 'error');
      }
      return;
    }
    try {
      if (typeof setBusy === 'function') setBusy(true, 'Creating request...');
      var confirmBtn = byId('mfsSendBtn');
      var confirmText = confirmBtn ? confirmBtn.textContent : '';
      if (confirmBtn) {
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Submitting...';
      }
      var result = await createWithTelegramButtons(d);
      if (typeof renderMfsResultSuccess === 'function') renderMfsResultSuccess(result);
      if (typeof applyMfsCreateSuccessToLocalState === 'function') applyMfsCreateSuccessToLocalState(result);
      clearMfsCreateFieldsAfterSuccess();
      if (typeof showToast === 'function') showToast('Request created successfully', 'ok');
    } catch(e) {
      var message = e.message || 'Failed to create request';
      if (isPinError(e)) {
        message = 'Please enter your correct transaction PIN.';
        showStep('mfsStepPin');
        setMfsPinError(message);
      } else if (typeof renderMfsResultError === 'function') {
        renderMfsResultError(message);
      }
      if (typeof showToast === 'function') showToast(message, 'error');
      if (isAuthError(e) && typeof window.userSessionExpired === 'function') {
        setTimeout(function(){ window.userSessionExpired(); }, 900);
      }
    } finally {
      var finalConfirmBtn = byId('mfsSendBtn');
      if (finalConfirmBtn) {
        finalConfirmBtn.disabled = false;
        finalConfirmBtn.textContent = confirmText || 'Confirm & Send Money';
      }
      if (typeof setBusy === 'function') setBusy(false);
    }
  }

  function quickButton(icon, label, section, provider){
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'zpay-service-btn';
    btn.innerHTML = '<span class="zpay-service-icon">' + icon + '</span><span class="zpay-service-name">' + label + '</span>';
    btn.addEventListener('click', function(){
      if (provider) setProvider(provider);
      openSection(section);
      if (provider) setTimeout(function(){ var n = byId('mfsReceiverNumber'); if (n) n.focus(); }, 150);
    });
    return btn;
  }

  function ensureQuickActions(){
    if (byId('zpayQuickActions')) return;
    var hero = document.querySelector('.hero-card');
    if (!hero || !hero.parentNode) return;
    var card = document.createElement('div');
    card.id = 'zpayQuickActions';
    card.className = 'zpay-quick-card';
    card.innerHTML = '<div class="zpay-quick-head"><div><h3 class="zpay-quick-title">Quick Services</h3><p class="zpay-quick-sub">Fast access to Z-Pay Swift services</p></div><div class="zpay-rate-chip">Fast - Secure</div></div>';
    var grid = document.createElement('div');
    grid.className = 'zpay-service-grid';
    grid.appendChild(quickButton('T', 'Topup', 'topupSection'));
    grid.appendChild(quickButton('b', 'bKash', 'mfsSection', 'BKASH'));
    grid.appendChild(quickButton('N', 'Nagad', 'mfsSection', 'NAGAD'));
    grid.appendChild(quickButton('B', 'Bundle', 'bundleSection'));
    grid.appendChild(quickButton('H', 'History', 'historySection'));
    card.appendChild(grid);
    hero.insertAdjacentElement('afterend', card);
  }

  function bindMfs(){
    if (window.__zpayMfsFlowFixBound) return;
    window.__zpayMfsFlowFixBound = true;
    document.querySelectorAll('.mfs-provider-choice').forEach(function(btn){ btn.addEventListener('click', function(){ setProvider(btn.getAttribute('data-provider') || 'BKASH'); }); });
    var receiver = byId('mfsReceiverNumber'); if (receiver) receiver.addEventListener('input', function(){ serverPreview = null; renderPreview(); });
    var reference = byId('mfsReference'); if (reference) reference.addEventListener('input', function(){ renderPreview(); });
    var bdt = byId('mfsAmountBdt'); if (bdt) bdt.addEventListener('input', function(){ serverPreview = null; setMfsAmountNotice(''); syncAmounts('bdt'); renderPreview(); });
    var rm = byId('mfsAmountRm'); if (rm) rm.addEventListener('input', function(){ serverPreview = null; setMfsAmountNotice(''); syncAmounts('rm'); renderPreview(); });
    var preview = byId('mfsPreviewBtn'); if (preview) preview.addEventListener('click', function(e){
      e.preventDefault();
      if (!validNumberStep()) return;
      updateCurrencyUi();
      showStep('mfsStepAmount');
      setTimeout(function(){ var amount = byId('mfsAmountBdt'); if (amount) amount.focus(); }, 50);
    });
    var amountBack = byId('mfsAmountBackBtn'); if (amountBack) amountBack.addEventListener('click', function(e){ e.preventDefault(); showStep('mfsStepForm'); });
    var amountNext = byId('mfsAmountNextBtn'); if (amountNext) amountNext.addEventListener('click', async function(e){
      e.preventDefault();
      if (!validNumberStep() || !validAmountStep()) return;
      var originalText = amountNext.textContent;
      try {
        amountNext.disabled = true;
        amountNext.textContent = 'Checking...';
        await loadServerPreview();
        if (!previewCanContinue) {
          setMfsAmountNotice('Insufficient available balance');
          if (typeof showMfsErrorModal === 'function') {
            showMfsErrorModal(
              'Insufficient Balance',
              'Your available balance is not enough for this send money request.',
              { retryStep: 'amount', editStep: 'amount' }
            );
          } else if (typeof showToast === 'function') {
            showToast('Insufficient available balance', 'error');
          }
          return;
        }
        setMfsAmountNotice('');
        showStep('mfsStepPin');
      } catch(err) {
        if (typeof showToast === 'function') showToast(err.message || 'Failed to load send money preview', 'error');
        if (isAuthError(err) && typeof window.userSessionExpired === 'function') {
          setTimeout(function(){ window.userSessionExpired(); }, 900);
        }
      } finally {
        amountNext.disabled = false;
        amountNext.textContent = originalText || 'Next';
      }
    });
    var back = byId('mfsBackBtn'); if (back) back.addEventListener('click', function(e){ e.preventDefault(); showStep('mfsStepAmount'); });
    var send = byId('mfsSendBtn'); if (send) send.addEventListener('click', function(e){
      e.preventDefault();
      renderPreview();
      if (!validBase()) return;
      if (!previewCanContinue) {
        showStep('mfsStepAmount');
        setMfsAmountNotice('Insufficient available balance');
        if (typeof showMfsErrorModal === 'function') {
          showMfsErrorModal(
            'Insufficient Balance',
            'Your available balance is not enough for this send money request.',
            { retryStep: 'amount', editStep: 'amount' }
          );
        } else if (typeof showToast === 'function') {
          showToast('Insufficient available balance', 'error');
        }
        return;
      }
      confirmMfs();
    });
    var pinBack = byId('mfsPinBackBtn'); if (pinBack) pinBack.addEventListener('click', function(e){ e.preventDefault(); showStep('mfsStepAmount'); });
    var confirm = byId('mfsConfirmBtn'); if (confirm) confirm.addEventListener('click', async function(e){
      e.preventDefault();
      var d = data();
      if (!d.pin) {
        setMfsPinError('PIN is required');
        if (typeof showToast === 'function') showToast('PIN is required', 'error');
        return;
      }
      var originalText = confirm.textContent;
      try {
        confirm.disabled = true;
        confirm.textContent = 'Checking...';
        await validatePinBeforeReview(d.pin);
        setMfsPinError('');
        renderPreview();
        showStep('mfsStepPreview');
      } catch (err) {
        var invalidPin = String(err && err.code || '').toUpperCase() === 'INVALID_PIN' || isPinError(err);
        var message = invalidPin ? 'Please enter your correct transaction PIN.' : (err.message || 'Invalid transaction PIN');
        setMfsPinError(message);
        var pinInput = byId('mfsPin');
        if (invalidPin && pinInput) setTimeout(function(){ pinInput.focus(); pinInput.select(); }, 50);
        if (!invalidPin && typeof showToast === 'function') {
          showToast(message, 'error');
        }
        if (isAuthError(err) && typeof window.userSessionExpired === 'function') {
          setTimeout(function(){ window.userSessionExpired(); }, 900);
        }
      } finally {
        confirm.disabled = false;
        confirm.textContent = originalText || 'Next';
      }
    });
    var pin = byId('mfsPin'); if (pin) pin.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); var next = byId('mfsConfirmBtn'); if (next) next.click(); } });
    setProvider(selectedProvider());
    updateCurrencyUi();
    renderPreview();
  }

  function clearMfsCreateFieldsAfterSuccess(){
    ['mfsReceiverNumber','mfsAmountBdt','mfsAmountRm','mfsPin','mfsReference'].forEach(function(id){
      var node = byId(id);
      if (node) node.value = '';
    });
    serverPreview = null;
    updateCurrencyUi();
    renderPreview();
    showStep('mfsStepForm');
  }

  window.zpayMfsRefreshCurrencyUi = function(){
    updateCurrencyUi();
    renderPreview();
  };

  window.zpayOpenMfsPinStep = function(){
    showStep('mfsStepPin');
  };

  window.zpayOpenMfsAmountStep = function(){
    showStep('mfsStepAmount');
  };

  window.zpayOpenMfsStep = function(step){
    showStep(mfsStepIdFromName(step));
  };

  window.zpayShowMfsHistoryStep = function(step){
    showStep(mfsStepIdFromName(step), { fromHistory: true });
  };

  window.zpayCloseMfsFlow = function(options){
    showStep('mfsStepForm', options || {});
  };

  function init(){ ensureQuickActions(); bindMfs(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
