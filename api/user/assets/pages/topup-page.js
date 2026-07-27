(() => {
  'use strict';
  const shell = window.UserShell;
  const el = (id) => document.getElementById(id);
  const state = { walletSummary: null };
  const wizard = { step: 1, operator: '', amount: '', preview: null };
  const money = (value) => { const n=Number(value||0); return Number.isFinite(n) ? n.toFixed(2) : '0.00'; };
  const esc = shell.escapeHtml;
  const showToast = shell.toast;
  const proxyPost = shell.post;
  const isSessionError = shell.isSessionError;
  const operatorName = (value) => ({GP:'Grameenphone',ROBI:'Robi',AIRTEL:'Airtel',BL:'Banglalink',TT:'Teletalk'})[String(value||'').toUpperCase()] || String(value||'-');
  const userStatusLabel = (value) => String(value||'PENDING').replaceAll('_',' ');
  const fmtTs = (value) => { const n=Number(value||0); return n ? new Date(n<1000000000000?n*1000:n).toLocaleString() : '-'; };
  const showModalById = (id) => { const node=el(id); node?.classList.add('show'); node?.setAttribute('aria-hidden','false'); };
  const hideModalById = (id) => { const node=el(id); node?.classList.remove('show'); node?.setAttribute('aria-hidden','true'); };
  const pushUserFlowHistory = (flow, step) => history.pushState({zpayFlow:{flow,step}},'',location.href);
  const replaceUserFlowHistory = () => history.replaceState({},'',location.href);
  async function safeRefreshAll(){ state.walletSummary = await shell.get('wallet_summary',{},'Refreshing wallet...',{busy:false}); }
function wizardData(){
  return {
    topup_number: (el('wizardTopupNumber')?.value || '').trim(),
    operator: (wizard.operator || '').trim(),
    amount: (el('wizardAmount')?.value || '').trim(),
    pin: (el('wizardPin')?.value || '').trim()
  };
}

function setWizardOperator(value){
  wizard.operator = value;
  wizard.preview = null;

  document.querySelectorAll('.operator-choice').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.operator === value);
  });
}

function setWizardAmount(value){
  wizard.amount = String(value || '');
  wizard.preview = null;

  if (el('wizardAmount')) {
    el('wizardAmount').value = wizard.amount;
  }

  document.querySelectorAll('.amount-choice').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.amount === String(value));
  });
}

function updateWizardUI(){
  for (let i = 1; i <= 5; i++) {
    el('wizardStep' + i)?.classList.toggle('active', i === wizard.step);
    el('wizardPill' + i)?.classList.toggle('active', i === wizard.step);
  }

  if (wizard.step === 5) {
    const data = wizardData();

    if (el('reviewNumber')) el('reviewNumber').textContent = data.topup_number || '-';
    if (el('reviewOperator')) el('reviewOperator').textContent = operatorName(data.operator || '-');
    if (el('reviewAmount')) el('reviewAmount').textContent = data.amount ? ('BDT ' + money(data.amount)) : '-';
    if (el('reviewPin')) el('reviewPin').textContent = data.pin ? 'â€¢â€¢â€¢â€¢' : '-';
  }
}

function gotoWizardStep(step){
  wizard.step = step;
  updateWizardUI();
}

function validateWizardStep(step){
  const data = wizardData();
  const roleSettings = (state.walletSummary || {}).role_settings || {};

  if (roleSettings.topup_enabled === false) {
    showToast('Topup is disabled for this account', 'error');
    return false;
  }

  if (step === 1) {
    if (!data.topup_number || data.topup_number.replace(/\D+/g, '').length < 10) {
      showToast('Valid topup number is required', 'error');
      return false;
    }
  }

  if (step === 2) {
    if (!data.operator) {
      showToast('Please select operator', 'error');
      return false;
    }
  }

  if (step === 3) {
    const amount = Number(data.amount || 0);
    const minAmount = Number(roleSettings.min_amount || 0);
    const maxAmount = Number(roleSettings.max_amount || 0);

    if (amount <= 0) {
      showToast('Valid amount is required', 'error');
      return false;
    }

    if (minAmount > 0 && amount < minAmount) {
      showToast('Minimum amount is ' + money(minAmount), 'error');
      return false;
    }

    if (maxAmount > 0 && amount > maxAmount) {
      showToast('Maximum amount is ' + money(maxAmount), 'error');
      return false;
    }
  }

  if (step === 4) {
    if (!data.pin) {
      showToast('PIN is required', 'error');
      return false;
    }
  }

  return true;
}

function resetWizard(){
  wizard.step = 1;
  wizard.operator = '';
  wizard.amount = '';
  wizard.preview = null;

  if (el('wizardTopupNumber')) el('wizardTopupNumber').value = '';
  if (el('wizardAmount')) el('wizardAmount').value = '';
  if (el('wizardPin')) el('wizardPin').value = '';

  document.querySelectorAll('.operator-choice,.amount-choice').forEach(btn => {
    btn.classList.remove('active');
  });

  updateWizardUI();
}

function topupDigits(){
  return String(el('wizardTopupNumber')?.value || '').replace(/\D+/g, '');
}

function detectTopupOperator(number = topupDigits()){
  const n = String(number || '').replace(/\D+/g, '');
  if (/^(013|017)/.test(n)) return 'GP';
  if (/^018/.test(n)) return 'ROBI';
  if (/^016/.test(n)) return 'AIRTEL';
  if (/^(014|019)/.test(n)) return 'BL';
  if (/^015/.test(n)) return 'TT';
  return '';
}

function topupReviewRows(){
  const data = wizardData();
  const preview = wizard.preview || {};
  const walletCurrency = String(preview.display_currency || preview.wallet_currency || '').toUpperCase();
  const walletPrefix = walletCurrency === 'MYR' ? 'RM' : 'BDT';
  const amountBdt = Number(preview.amount_bdt ?? preview.topup_amount_bdt ?? preview.amount ?? 0);
  const walletDebit = Number(preview.wallet_debit_amount ?? preview.wallet_debit ?? preview.total_pay ?? 0);
  const balanceBefore = Number(preview.balance_before);
  const balanceAfter = Number(preview.balance_after);

  return [
    ['Number', preview.topup_number || preview.number || data.topup_number || '-'],
    ['Operator', operatorName(preview.operator_code || preview.operator || data.operator || '-')],
    ['Topup Amount', preview.topup_amount_text || ('BDT ' + money(amountBdt))],
    ...(preview.commission_applicable && Number(preview.commission_amount || 0) > 0
      ? [['Commission Benefit', preview.commission_text || ('BDT ' + money(preview.commission_amount))]]
      : []),
    ['Wallet Debit', preview.total_pay_text || (walletPrefix + ' ' + money(walletDebit)), true],
    ...(preview.rate_applicable && preview.rate_text ? [['Rate', preview.rate_text]] : []),
    ...(Number.isFinite(balanceBefore) ? [['Available Balance', walletPrefix + ' ' + money(balanceBefore)]] : []),
    ...(Number.isFinite(balanceAfter) ? [['Balance After', preview.balance_after_text || (walletPrefix + ' ' + money(balanceAfter))]] : [])
  ];
}

function topupStepNumber(step){
  if (step === 'operator') return 2;
  if (step === 'amount') return 3;
  if (step === 'pin') return 4;
  return 5;
}

function topupStepNameFromWizard(){
  if (wizard.step === 2) return 'operator';
  if (wizard.step === 3) return 'amount';
  if (wizard.step === 4) return 'pin';
  return 'review';
}

function ensureTopupFlowModal(){
  if (el('topupFlowModal')) return;

  const wrap = document.createElement('div');
  wrap.id = 'topupFlowModal';
  wrap.className = 'modal';
  wrap.innerHTML = `
    <div class="modal-card modal-card-flow">
      <button id="closeTopupFlowModalBtn" class="modal-close" type="button">&times;</button>
      <div class="flow-step-head">
        <h3 id="topupFlowTitle" class="flow-step-title">Topup</h3>
        <p id="topupFlowSub" class="flow-step-sub">Complete the next step.</p>
      </div>
      <div id="topupFlowBody"></div>
      <div class="wizard-actions">
        <button id="topupFlowBackBtn" class="btn ghost" type="button">Back</button>
        <button id="topupFlowNextBtn" class="btn green" type="button">Next</button>
      </div>
    </div>
  `;
  document.body.appendChild(wrap);

  el('closeTopupFlowModalBtn')?.addEventListener('click', closeTopupFlowModal);
  wrap.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'topupFlowModal') closeTopupFlowModal();

    const operatorBtn = e.target.closest?.('.topup-flow-operator');
    if (operatorBtn) {
      setWizardOperator(operatorBtn.getAttribute('data-operator') || '');
      renderTopupFlowStep();
      return;
    }

    const amountBtn = e.target.closest?.('.topup-flow-amount');
    if (amountBtn) {
      setWizardAmount(amountBtn.getAttribute('data-amount') || '');
      renderTopupFlowStep();
      return;
    }

    if (e.target.closest?.('#topupFlowBackBtn')) {
      e.preventDefault();
      topupFlowBack();
      return;
    }

    if (e.target.closest?.('#topupFlowNextBtn')) {
      e.preventDefault();
      topupFlowNext();
    }
  });
}

function setTopupFlowBusy(on, text = 'Submitting...'){
  const btn = el('topupFlowNextBtn');
  if (!btn) return;

  if (on) {
    btn.dataset.originalText = btn.textContent || '';
    btn.disabled = true;
    btn.textContent = text;
    return;
  }

  btn.disabled = false;
  btn.textContent = btn.dataset.originalText || btn.textContent || 'Next';
  delete btn.dataset.originalText;
}

function setTopupFlowError(message){
  const node = el('topupFlowError');
  if (!node) return;

  node.textContent = message || '';
  node.classList.toggle('active', !!message);
}

async function validateTransactionPin(pin){
  await proxyPost('validate_pin', { pin }, 'Checking PIN...', { busy: false });
}

function openTopupFlowFromNumber(){
  if (!validateWizardStep(1)) return;

  const detected = detectTopupOperator();
  if (detected) {
    setWizardOperator(detected);
  }

  showTopupFlowStep('operator');
}

function showTopupFlowStep(step, options = {}){
  ensureTopupFlowModal();
  wizard.step = topupStepNumber(step);
  renderTopupFlowStep(step);
  showModalById('topupFlowModal');
  if (!options.fromHistory) {
    pushUserFlowHistory('topup', step);
  }
}

function closeTopupFlowModal(options = {}){
  hideModalById('topupFlowModal');
  wizard.step = 1;
  updateWizardUI();
  if (!options.fromHistory) {
    replaceUserFlowHistory('dashboard', 'guard');
  }
}

function renderTopupFlowStep(step = ''){
  ensureTopupFlowModal();

  const body = el('topupFlowBody');
  const title = el('topupFlowTitle');
  const sub = el('topupFlowSub');
  const back = el('topupFlowBackBtn');
  const next = el('topupFlowNextBtn');

  const current = step || topupStepNameFromWizard();
  const data = wizardData();

  if (back) back.textContent = current === 'operator' ? 'Back / Edit' : 'Back';
  if (next) next.textContent = current === 'review' ? 'Confirm Topup' : 'Next';

  if (current === 'operator') {
    if (title) title.textContent = 'Select Operator';
    if (sub) sub.textContent = 'Choose the mobile operator for this number.';
    if (body) body.innerHTML = `
      <div class="flow-choice-grid flow-choice-grid-operators">
        ${[
          ['GP','Grameenphone'],
          ['ROBI','Robi'],
          ['AIRTEL','Airtel'],
          ['BL','Banglalink'],
          ['TT','Teletalk']
        ].map(([code, name]) => `
          <button type="button" class="flow-choice-btn topup-flow-operator ${data.operator === code ? 'active' : ''}" data-operator="${esc(code)}">
            ${esc(name)}
            <small>${esc(code)}</small>
          </button>
        `).join('')}
      </div>
      <div id="topupFlowError" class="mfs-pin-error"></div>
    `;
    return;
  }

  if (current === 'amount') {
    if (title) title.textContent = 'Enter Amount';
    if (sub) sub.textContent = 'Choose a preset amount or enter a custom amount.';
    if (body) body.innerHTML = `
      <div class="flow-amount-grid">
        ${['20','30','50','100'].map(value => `
          <button type="button" class="flow-choice-btn topup-flow-amount ${String(data.amount) === value ? 'active' : ''}" data-amount="${value}">
            BDT ${value}
          </button>
        `).join('')}
      </div>
      <input id="topupFlowAmountInput" class="wizard-big-input" type="number" inputmode="decimal" step="0.01" min="1" placeholder="Enter amount" value="${esc(data.amount || '')}">
      <div id="topupFlowError" class="mfs-pin-error"></div>
    `;

    const amountInput = el('topupFlowAmountInput');
    if (amountInput) {
      amountInput.addEventListener('input', () => setWizardAmount(amountInput.value || ''));
      setTimeout(() => amountInput.focus(), 50);
    }
    return;
  }

  if (current === 'review') {
    if (title) title.textContent = 'Review Topup';
    if (sub) sub.textContent = 'Please review details before confirming.';
    if (body) body.innerHTML = `
      <div class="flow-review-grid">
        ${topupReviewRows().map(row => `
          <div class="flow-review-box ${row[2] ? 'total' : ''}">
            <label>${esc(row[0])}</label>
            <strong>${esc(row[1])}</strong>
          </div>
        `).join('')}
      </div>
    `;
    return;
  }

  if (title) title.textContent = 'Confirm with PIN';
  if (sub) sub.textContent = 'Enter your transaction PIN before final review.';
  if (body) body.innerHTML = `
    <input id="topupFlowPinInput" class="wizard-big-input" type="password" inputmode="numeric" placeholder="Enter PIN" value="${esc(data.pin || '')}">
    <div id="topupFlowError" class="mfs-pin-error"></div>
  `;

  const pinInput = el('topupFlowPinInput');
  if (pinInput) {
    pinInput.addEventListener('input', () => {
      if (el('wizardPin')) el('wizardPin').value = pinInput.value || '';
    });
    pinInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') topupFlowNext();
    });
    setTimeout(() => pinInput.focus(), 50);
  }
}

function topupFlowBack(){
  const current = topupStepNameFromWizard();
  if (current === 'operator') {
    closeTopupFlowModal();
  } else if (current === 'amount') {
    showTopupFlowStep('operator');
  } else if (current === 'pin') {
    showTopupFlowStep('amount');
  } else {
    showTopupFlowStep('pin');
  }
}

async function topupFlowNext(){
  const current = topupStepNameFromWizard();

  if (current === 'operator') {
    if (validateWizardStep(2)) showTopupFlowStep('amount');
    return;
  }

  if (current === 'amount') {
    if (!validateWizardStep(3)) return;
    setTopupFlowError('');
    showTopupFlowStep('pin');
    return;
  }

  if (current === 'pin') {
    const pinInput = el('topupFlowPinInput');
    if (pinInput && el('wizardPin')) {
      el('wizardPin').value = pinInput.value || '';
    }
    if (!validateWizardStep(4)) {
      setTopupFlowError('PIN is required');
      return;
    }

    const data = wizardData();
    const next = el('topupFlowNextBtn');
    const originalText = next ? next.textContent : '';

    try {
      if (next) {
        next.disabled = true;
        next.textContent = 'Checking...';
      }
      await validateTransactionPin(data.pin);
      wizard.preview = await proxyPost('topup_preview', {
        country_code: 'BD',
        topup_number: data.topup_number,
        operator: data.operator,
        amount: Number(data.amount),
        verified_by: 'USER_WEB'
      }, 'Loading top-up preview...', { busy: false });
      if (!wizard.preview?.preview_token) {
        throw new Error('Top-up preview could not be created.');
      }
      setTopupFlowError('');
      showTopupFlowStep('review');
    } catch (err) {
      const isInvalidPin = String(err?.code || '').toUpperCase() === 'INVALID_PIN';
      const message = isInvalidPin
        ? 'Please enter your correct transaction PIN.'
        : (err.message || 'Invalid transaction PIN');
      setTopupFlowError(message);
      showTopupResultModal({
        title: isInvalidPin ? 'Incorrect PIN' : 'PIN Check Failed',
        subtitle: message,
        type: 'error',
        rows: [['Message', message]],
        retryStep: 'pin',
        editStep: 'pin'
      });
      if (isSessionError(err)) {
        setTimeout(() => {
          window.location.replace('/user/');
          
        }, 900);
      }
    } finally {
      if (next) {
        next.disabled = false;
        next.textContent = topupStepNameFromWizard() === 'review'
          ? 'Confirm Topup'
          : (originalText || 'Next');
      }
    }
    return;
  }

  submitTopup();
}

function ensureTopupResultModal(){
  if (el('topupResultModal')) return;

  const wrap = document.createElement('div');
  wrap.id = 'topupResultModal';
  wrap.className = 'modal';
  wrap.innerHTML = `
    <div class="modal-card">
      <button id="closeTopupResultModalBtn" class="modal-close" type="button">&times;</button>
      <h3 class="modal-title" id="topupResultTitle">Topup Request</h3>
      <p class="modal-sub" id="topupResultSub">Request details</p>
      <div id="topupResultBody" class="result-card"></div>
      <div class="wizard-actions">
        <button id="topupRetryBtn" class="btn green hidden" type="button">Try Again</button>
        <button id="topupEditBtn" class="btn ghost hidden" type="button">Edit</button>
        <button id="topupResultOkBtn" class="btn green" type="button">OK</button>
      </div>
    </div>
  `;
  document.body.appendChild(wrap);

  const close = () => hideModalById('topupResultModal');
  el('closeTopupResultModalBtn')?.addEventListener('click', close);
  el('topupResultOkBtn')?.addEventListener('click', close);
  wrap.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'topupResultModal') close();
  });
}

function showTopupResultModal({
  title,
  subtitle,
  rows = [],
  type = 'success',
  retryStep = 'review',
  editStep = 'amount'
} = {}){
  ensureTopupResultModal();

  if (el('topupResultTitle')) el('topupResultTitle').textContent = title || 'Topup Request';
  if (el('topupResultSub')) el('topupResultSub').textContent = subtitle || '';

  const body = el('topupResultBody');
  if (body) {
    body.className = 'result-card ' + (type === 'error' ? 'bad' : 'good');
    body.innerHTML = `
      <div class="flow-review-grid">
        ${rows.map(row => `
          <div class="flow-review-box">
            <label>${esc(row[0])}</label>
            <strong>${esc(row[1])}</strong>
          </div>
        `).join('')}
      </div>
    `;
  }

  const retryBtn = el('topupRetryBtn');
  const editBtn = el('topupEditBtn');

  if (retryBtn) {
    retryBtn.classList.toggle('hidden', type !== 'error');
    retryBtn.onclick = () => {
      hideModalById('topupResultModal');
      showTopupFlowStep(retryStep);
    };
  }

  if (editBtn) {
    editBtn.classList.toggle('hidden', type !== 'error');
    editBtn.onclick = () => {
      hideModalById('topupResultModal');
      showTopupFlowStep(editStep);
    };
  }

  showModalById('topupResultModal');
}

function renderTopupResultSuccess(data){
  showTopupResultModal({
    title: 'Topup Created Successfully',
    subtitle: 'Your topup request has been submitted securely.',
    type: 'success',
    rows: [
      ['Request ID', data.request_id || '-'],
      ['Number', data.topup_number || '-'],
      ['Operator', operatorName(data.operator || '-')],
      ['Amount', 'BDT ' + money(data.amount || 0)],
      ['Status', userStatusLabel(data.status || 'PENDING')],
      ['Created', fmtTs(data.created_at || 0)]
    ]
  });
}

function renderTopupResultError(message){
  showTopupResultModal({
    title: 'Topup Failed',
    subtitle: message || 'Unknown error',
    type: 'error',
    rows: [['Message', message || 'Unknown error']],
    retryStep: 'review',
    editStep: 'amount'
  });
}

async function submitTopup(){
  if (!validateWizardStep(1) || !validateWizardStep(2) || !validateWizardStep(3) || !validateWizardStep(4)) {
    return;
  }

  const data = wizardData();
  const previewToken = String(wizard.preview?.preview_token || '');
  if (!previewToken) {
    showTopupResultModal({
      title: 'Preview Expired',
      subtitle: 'Please review the top-up again before confirming.',
      type: 'error',
      rows: [['Message', 'A valid top-up preview is required.']],
      retryStep: 'pin',
      editStep: 'amount'
    });
    return;
  }

  try{
    setTopupFlowBusy(true, 'Submitting...');
    const res = await proxyPost('topup_submit', {
      preview_token: previewToken,
      topup_number: data.topup_number,
      operator: data.operator,
      amount: Number(data.amount),
      verified_by: 'USER_WEB'
    }, 'Creating topup...');

    closeTopupFlowModal();
    renderTopupResultSuccess(res);
    showToast('Topup created successfully', 'ok');

    await safeRefreshAll(false);
    resetWizard();
  }catch(err){
    if (String(err?.code || '').toUpperCase() === 'INVALID_PIN') {
      showTopupResultModal({
        title: 'Incorrect PIN',
        subtitle: 'Please enter your correct transaction PIN.',
        type: 'error',
        rows: [['Message', 'Please enter your correct transaction PIN.']],
        retryStep: 'pin',
        editStep: 'pin'
      });
    } else {
      renderTopupResultError(err.message || 'Failed to create topup');
    }
    showToast(
      String(err?.code || '').toUpperCase() === 'INVALID_PIN'
        ? 'Please enter your correct transaction PIN.'
        : (err.message || 'Failed to create topup'),
      'error'
    );
  }finally{
    setTopupFlowBusy(false);
  }
}

/* =========================
   Login OTP Flow
========================= */


  function bindTopupPage(){
    el('wizardNext1')?.addEventListener('click', openTopupFlowFromNumber);
    el('wizardTopupNumber')?.addEventListener('keydown', (event) => { if(event.key === 'Enter') openTopupFlowFromNumber(); });
    document.querySelectorAll('.operator-choice').forEach((button) => button.addEventListener('click', () => setWizardOperator(button.dataset.operator || '')));
    document.querySelectorAll('.amount-choice').forEach((button) => button.addEventListener('click', () => setWizardAmount(button.dataset.amount || '')));
    window.addEventListener('popstate', () => { if(el('topupFlowModal')?.classList.contains('show')) closeTopupFlowModal({fromHistory:true}); });
    document.addEventListener('keydown', (event) => { if(event.key === 'Escape') { closeTopupFlowModal(); hideModalById('topupResultModal'); } });
  }
  async function init(){ await shell.ready; state.walletSummary = await shell.get('wallet_summary',{},'Loading wallet...',{busy:false}); bindTopupPage(); updateWizardUI(); }
  init().catch((error) => showToast(error.message || 'Top-Up could not be loaded.','error'));
})();
