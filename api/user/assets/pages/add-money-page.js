(() => {
  'use strict';
  const shell = window.UserShell;
  const el = (id) => document.getElementById(id);
  const esc = shell.escapeHtml;
  const money = (value) => { const number = Number(value || 0); return Number.isFinite(number) ? number.toFixed(2) : '0.00'; };
  const walletPrefix = (currency) => String(currency || 'BDT').toUpperCase() === 'MYR' ? 'RM' : 'BDT';
  const state = { addMoneyProfile: null, addMoneyHistory: [], addMoneyLoaded: false, addMoneySelectedAccountId: '', addMoneySubmitKey: '', addMoneyReceipt: { file: null, objectUrl: '', name: '', mime: '', size: 0 } };
  const showToast = shell.toast;
  const proxyGet = shell.get;
  const proxyFormPost = shell.postForm;
  const isSessionError = shell.isSessionError;
  const fmtTs = (value) => { const n=Number(value||0); return n ? new Date(n < 1000000000000 ? n*1000 : n).toLocaleString() : '-'; };
  const statusPill = (value) => `<span class="status-pill">${esc(value)}</span>`;
  const copyHistoryId = () => {};
  const openReceiptLink = () => {};
function addMoneyStatusLabel(status){
  const s = String(status || 'PENDING').toUpperCase();
  if (s === 'APPROVED') return 'Approved';
  if (s === 'REJECTED') return 'Rejected';
  return 'Pending';
}

function addMoneyHistoryCard(row){
  const prefix = walletPrefix(row.currency || 'BDT');
  const receipt = String(row.receipt_url || '').trim();
  return `
    <div class="history-item">
      <div class="history-top">
        <div>
          <div class="history-id">${esc(row.request_id || '-')}</div>
          <div class="history-small">Add Money - ${esc(row.method || '-')}</div>
        </div>
        ${statusPill(addMoneyStatusLabel(row.status || 'PENDING'))}
      </div>
      <div class="history-meta">
        <div class="mini"><label>Amount</label><strong>${prefix} ${money(row.amount || 0)}</strong></div>
        <div class="mini"><label>Submitted</label><strong>${esc(fmtTs(row.created_at || 0))}</strong></div>
        <div class="mini"><label>Txn / Sender</label><strong>${esc(row.transaction_id || row.sender_number || '-')}</strong></div>
        <div class="mini"><label>Processed</label><strong>${esc(fmtTs(row.approved_at || row.rejected_at || 0))}</strong></div>
      </div>
      ${row.reject_reason ? `<div class="history-message">${esc(row.reject_reason)}</div>` : ''}
      <div class="history-actions">
        <button class="btn ghost sm" type="button" onclick="copyHistoryId('${esc(row.request_id || '')}')">Copy ID</button>
        ${receipt ? `<button class="btn green sm" type="button" onclick="openReceiptLink('${esc(receipt)}')">Receipt</button>` : ''}
      </div>
    </div>
  `;
}

function renderAddMoneyHistory(){
  const list = el('addMoneyHistoryList');
  if (!list) return;

  if (!state.addMoneyHistory.length) {
    list.innerHTML = `
      <div class="history-item history-empty">
        <div class="history-id">No add money request yet.</div>
      </div>
    `;
    return;
  }

  list.innerHTML = state.addMoneyHistory.map(addMoneyHistoryCard).join('');
}

function addMoneyMethodLabel(method){
  const key = String(method || '').toUpperCase();
  if (key === 'BKASH') return 'bKash';
  if (key === 'NAGAD') return 'Nagad';
  if (key === 'EWALLET') return 'eWallet';
  return 'Bank';
}

function addMoneyPaymentType(method, country){
  const key = String(method || '').toUpperCase();
  if (String(country || '').toUpperCase() === 'BD') {
    return key === 'NAGAD' ? 'Nagad Payment' : 'bKash Payment';
  }
  return key === 'EWALLET' ? 'eWallet Deposit' : 'Bank Deposit';
}

function addMoneyCountryName(country){
  return String(country || '').toUpperCase() === 'MY' ? 'Malaysia' : 'Bangladesh';
}

function addMoneySafeLogoUrl(value){
  const raw = String(value || '').trim();
  if (!raw) return '';
  try {
    const url = new URL(raw, window.location.origin);
    return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
  } catch (_) {
    return '';
  }
}

function addMoneyAccountId(account){
  return String(account?.account_id || account?.id || '').trim();
}

function addMoneyCountryProfile(){
  const profile = state.addMoneyProfile || {};
  return String(profile.pricing_country || '').toUpperCase() === 'MY' ? 'MY' : 'BD';
}

function addMoneyAccountsForProfile(){
  const country = addMoneyCountryProfile();
  const profile = state.addMoneyProfile || {};
  const currency = String(profile.currency || (country === 'MY' ? 'MYR' : 'BDT')).toUpperCase();
  return (Array.isArray(profile.accounts) ? profile.accounts : []).filter((account) => {
    const accountCountry = String(account?.country || '').toUpperCase();
    const accountCurrency = String(account?.currency || '').toUpperCase();
    return accountCountry === country
      && (accountCurrency === '' || accountCurrency === currency)
      && account.active !== false
      && addMoneyAccountId(account) !== '';
  });
}

function addMoneySelectedAccount(){
  const selectedId = String(state.addMoneySelectedAccountId || '');
  return addMoneyAccountsForProfile().find(account => addMoneyAccountId(account) === selectedId) || null;
}

function addMoneyRevokeReceiptUrl(){
  const receipt = state.addMoneyReceipt;
  if (receipt?.objectUrl) {
    URL.revokeObjectURL(receipt.objectUrl);
    receipt.objectUrl = '';
  }
}

function addMoneyClearReceipt(updateUi = true){
  addMoneyRevokeReceiptUrl();
  state.addMoneyReceipt = { file: null, objectUrl: '', name: '', mime: '', size: 0 };
  const input = el('addMoneyReceiptInput');
  if (input) input.value = '';
  if (updateUi) addMoneyUpdateReceiptPreview();
}

function addMoneyFileSize(size){
  const bytes = Number(size || 0);
  if (!bytes) return '0 KB';
  if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
  return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function addMoneyReceiptAllowed(file){
  if (!file) return false;
  const name = String(file.name || '').toLowerCase();
  const type = String(file.type || '').toLowerCase();
  const extensionAllowed = /\.(jpe?g|png|webp|pdf)$/.test(name);
  const mimeAllowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(type);
  return extensionAllowed && (mimeAllowed || type === '');
}

function addMoneyReceiptPreviewHtml(){
  const receipt = state.addMoneyReceipt;
  if (!receipt.file) {
    return `
      <div class="add-money-receipt-empty-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 2h8l4 4v16H6V2Zm7 2H8v16h8V8h-3V4Zm-4 8h6v2H9v-2Zm0 4h6v2H9v-2Z"/></svg></div>
      <div class="add-money-receipt-copy">
        <strong>No receipt selected</strong>
        <small>JPG, PNG, WEBP or PDF, max 5 MB.</small>
      </div>
    `;
  }

  const isImage = String(receipt.mime || '').startsWith('image/') && receipt.objectUrl;
  const preview = isImage
    ? `<img src="${esc(receipt.objectUrl)}" alt="Selected receipt preview">`
    : '<div class="add-money-receipt-empty-icon" aria-hidden="true">PDF</div>';

  return `
    ${preview}
    <div class="add-money-receipt-copy">
      <strong>${esc(receipt.name || 'Selected receipt')}</strong>
      <small>${esc(addMoneyFileSize(receipt.size))}</small>
    </div>
    <div class="add-money-receipt-actions">
      <button class="btn ghost sm" id="addMoneyReplaceReceiptBtn" type="button">Replace</button>
      <button class="btn ghost sm danger" id="addMoneyRemoveReceiptBtn" type="button">Remove</button>
    </div>
  `;
}

function addMoneyUpdateReceiptPreview(){
  const preview = el('addMoneyReceiptPreview');
  if (preview) preview.innerHTML = addMoneyReceiptPreviewHtml();
}

function selectAddMoneyAccount(accountId){
  const account = addMoneyAccountsForProfile().find(item => addMoneyAccountId(item) === String(accountId || ''));
  if (!account) return;

  state.addMoneySelectedAccountId = addMoneyAccountId(account);
  state.addMoneySubmitKey = '';
  addMoneyClearReceipt();

  document.querySelectorAll('[data-add-money-account-id]').forEach((card) => {
    const selected = card.dataset.addMoneyAccountId === state.addMoneySelectedAccountId;
    card.classList.toggle('selected', selected);
    card.setAttribute('aria-pressed', selected ? 'true' : 'false');
  });

  const accountIdInput = el('addMoneyPaymentAccountId');
  const methodInput = el('addMoneyPaymentMethod');
  const methodMirror = el('addMoneyPaymentMethodMirror');
  const method = String(account.method || '').toUpperCase();
  if (accountIdInput) accountIdInput.value = state.addMoneySelectedAccountId;
  if (methodInput) methodInput.value = method;
  if (methodMirror) methodMirror.value = method;
}

function renderAddMoneyAccountCards(accounts, country){
  const list = Array.isArray(accounts) ? accounts : [];
  if (!list.length) {
    return `
      <div class="add-money-account-list form-full">
        <div class="add-money-account-card unavailable">
          <div class="add-money-account-name">Payment account unavailable</div>
          <p class="muted">Please contact support before submitting an add money request.</p>
        </div>
      </div>
    `;
  }

  return `
    <div class="add-money-account-list form-full">
      ${list.map((account) => {
        const accountId = addMoneyAccountId(account);
        const instruction = String(account.instruction || '').trim();
        const holder = String(account.account_holder || account.holder_name || '-');
        const number = String(account.account_number || '-');
        const methodLabel = addMoneyPaymentType(account.method, country);
        const logo = addMoneySafeLogoUrl(account.logo_url || account.logo);
        const selected = String(state.addMoneySelectedAccountId || '') === accountId;
        return `
          <div class="add-money-account-card${selected ? ' selected' : ''}"
               data-add-money-account-id="${esc(accountId)}"
               role="button"
               tabindex="0"
               aria-pressed="${selected ? 'true' : 'false'}">
            <div class="add-money-account-main">
              ${logo ? `<img class="add-money-account-logo" src="${esc(logo)}" alt="${esc(account.display_name || methodLabel)}">` : `<div class="add-money-account-logo fallback" aria-hidden="true">${esc(addMoneyMethodLabel(account.method).slice(0, 2))}</div>`}
              <div>
                <div class="add-money-account-name">${esc(account.display_name || methodLabel)}</div>
              </div>
            </div>
            <div class="add-money-account-info">
              <div>Holder: <strong>${esc(holder)}</strong></div>
              <div>Account: <strong>${esc(number)}</strong></div>
            </div>
            <button class="add-money-copy-icon" type="button" data-copy-account-number="${esc(account.account_number || '')}" aria-label="Copy ${esc(account.display_name || methodLabel)} account number" title="Copy account number">
              <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M8 8h11v13H8V8Zm2 2v9h7v-9h-7ZM5 3h11v3h-2V5H7v9h-1V3h-1v13h1v2H5V3Z"/></svg>
            </button>
            ${instruction ? `<p class="muted add-money-account-note">${esc(instruction)}</p>` : ''}
          </div>
        `;
      }).join('')}
    </div>
  `;
}

function renderAddMoneyPage(){
  const wrap = el('addMoneyContent');
  if (!wrap) return;

  const profile = state.addMoneyProfile || {};
  const settings = profile.settings || {};
  const country = addMoneyCountryProfile();
  const accounts = addMoneyAccountsForProfile();
  const prefix = walletPrefix(profile.currency || (country === 'MY' ? 'MYR' : 'BDT'));
  const enabled = !!settings.enabled && accounts.length > 0;

  if (!addMoneySelectedAccount()) {
    state.addMoneySelectedAccountId = '';
  }

  if (!enabled) {
    wrap.innerHTML = `
      <div class="add-money-unavailable-card">
        <strong>${esc(addMoneyCountryName(country))} Add Money - ${esc(prefix)}</strong>
        <p>Please contact support before submitting an add money request.</p>
      </div>
      ${addMoneySupportCardHtml()}
    `;
    return;
  }

  wrap.innerHTML = `
    <section class="add-money-payment-card">
      <h4>${esc(addMoneyCountryName(country))} Add Money - ${esc(prefix)}</h4>
      <p class="add-money-instruction">${esc(settings.instruction || 'Transfer to one of the accounts below, then upload your receipt.')}</p>
      ${renderAddMoneyAccountCards(accounts, country)}
    </section>
    <section class="add-money-proof-card">
      <h4>Submit Payment Proof</h4>
      ${addMoneySubmitFormHtml()}
    </section>
    ${addMoneySupportCardHtml()}
  `;
  bindAddMoneyForm(el('addMoneyForm'));
}

function addMoneySupportCardHtml(){
  return `
    <section class="add-money-support-card">
      <p>If your balance is not added within 1 hour after submitting the request, please contact support.</p>
      <a class="btn green" href="/user/contact-us">Contact</a>
    </section>
  `;
}

async function copyAddMoneyAccountNumber(value) {
  const text = String(value || '').trim();
  if (!text) throw new Error('Account number is unavailable.');
  if (navigator.clipboard && window.isSecureContext) {
    await navigator.clipboard.writeText(text);
    return;
  }
  const input = document.createElement('textarea');
  input.value = text;
  input.readOnly = true;
  input.style.position = 'fixed';
  input.style.opacity = '0';
  document.body.appendChild(input);
  input.select();
  const copied = document.execCommand('copy');
  input.remove();
  if (!copied) throw new Error('Copy failed.');
}

function addMoneySubmitFormHtml(){
  const profile = state.addMoneyProfile || {};
  const country = addMoneyCountryProfile();
  const accounts = addMoneyAccountsForProfile();
  const prefix = walletPrefix(profile.currency || (country === 'MY' ? 'MYR' : 'BDT'));
  const selected = addMoneySelectedAccount();
  const method = selected ? String(selected.method || '').toUpperCase() : '';
  const receiptLabel = country === 'MY' ? 'Upload bank receipt' : 'Upload receipt (optional)';

  if (!accounts.length) {
    return '<div class="add-money-unavailable-card"><strong>Payment account unavailable</strong><p>Please contact support before submitting an add money request.</p></div>';
  }

  return `
    <form id="addMoneyForm" class="add-money-proof-form" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="payment_account_id" id="addMoneyPaymentAccountId" value="${esc(addMoneyAccountId(selected))}">
      <input type="hidden" name="method" id="addMoneyPaymentMethod" value="${esc(method)}">
      <input type="hidden" name="payment_method" id="addMoneyPaymentMethodMirror" value="${esc(method)}">
      <input type="hidden" name="payment_country" value="${esc(country)}">
      <input type="hidden" name="payment_currency" value="${esc(profile.currency || (country === 'MY' ? 'MYR' : 'BDT'))}">
      <div class="field">
        <label class="visually-hidden" for="addMoneyAmount">Amount (${prefix})</label>
        <input id="addMoneyAmount" class="input" name="amount" type="number" min="1" step="0.01" inputmode="decimal" placeholder="Amount" required>
      </div>

      ${country === 'BD' ? `
        <div class="field">
          <label>Transaction ID</label>
          <input class="input" name="transaction_id" placeholder="bKash/Nagad transaction ID">
        </div>
        <div class="field">
          <label>Sender Number</label>
          <input class="input" name="sender_number" inputmode="tel" placeholder="Number used to send payment">
        </div>
      ` : ''}

      <div class="field add-money-receipt-field">
        <label for="addMoneyReceiptInput">${receiptLabel}</label>
        <input id="addMoneyReceiptInput" class="visually-hidden" name="receipt_upload" type="file" accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf">
        <button id="addMoneyReceiptBtn" class="add-money-receipt-button" type="button">${receiptLabel}</button>
        <div id="addMoneyReceiptPreview" class="add-money-receipt-preview" aria-live="polite">${addMoneyReceiptPreviewHtml()}</div>
      </div>

      <div class="field">
        <label for="addMoneyNote">Note (optional)</label>
        <textarea id="addMoneyNote" class="input" name="note" rows="3" placeholder="Note (optional)"></textarea>
      </div>
      <div class="form-actions">
        <button id="addMoneySubmitBtn" class="btn green full-btn" type="submit">Submit Receipt</button>
      </div>
    </form>
  `;
}

function bindAddMoneyForm(form){
  if (!form || form.dataset.bound === '1') return;
  form.dataset.bound = '1';

  const receiptInput = el('addMoneyReceiptInput');
  const receiptButton = el('addMoneyReceiptBtn');
  const submitButton = el('addMoneySubmitBtn');

  receiptButton?.addEventListener('click', () => receiptInput?.click());
  receiptInput?.addEventListener('change', () => {
    const file = receiptInput.files?.[0];
    if (!file) return;
    if (Number(file.size || 0) > 5 * 1024 * 1024) {
      receiptInput.value = '';
      showToast('Receipt file is too large. Maximum size is 5 MB.', 'error');
      return;
    }
    if (!addMoneyReceiptAllowed(file)) {
      receiptInput.value = '';
      showToast('Unsupported file type. Please upload JPG, PNG, WEBP or PDF.', 'error');
      return;
    }

    addMoneyRevokeReceiptUrl();
    state.addMoneyReceipt = {
      file,
      objectUrl: String(file.type || '').startsWith('image/') ? URL.createObjectURL(file) : '',
      name: String(file.name || 'receipt'),
      mime: String(file.type || '').toLowerCase(),
      size: Number(file.size || 0)
    };
    state.addMoneySubmitKey = '';
    addMoneyUpdateReceiptPreview();
  });

  form.addEventListener('input', (event) => {
    if (['amount', 'transaction_id', 'sender_number', 'note'].includes(event.target?.name)) {
      state.addMoneySubmitKey = '';
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (form.dataset.submitting === '1') return;

    const selected = addMoneySelectedAccount();
    if (!selected) {
      showToast('Please select the account you sent money to.', 'error');
      return;
    }

    const country = addMoneyCountryProfile();
    if (country === 'MY' && !state.addMoneyReceipt.file) {
      showToast('Please upload your bank transfer receipt.', 'error');
      return;
    }

    if (!state.addMoneySubmitKey) {
      state.addMoneySubmitKey = window.crypto?.randomUUID?.() || `AM-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }

    const formData = new FormData(form);
    formData.set('idempotency_key', state.addMoneySubmitKey);
    formData.set('payment_account_id', addMoneyAccountId(selected));
    formData.set('method', String(selected.method || ''));
    formData.set('payment_method', String(selected.method || ''));
    formData.set('payment_country', country);
    formData.set('payment_currency', String((state.addMoneyProfile || {}).currency || (country === 'MY' ? 'MYR' : 'BDT')));
    const currentReceipt = formData.get('receipt_upload');
    if (state.addMoneyReceipt.file && (!currentReceipt || Number(currentReceipt.size || 0) === 0)) {
      formData.set('receipt_upload', state.addMoneyReceipt.file, state.addMoneyReceipt.name);
    }

    form.dataset.submitting = '1';
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.dataset.originalText = submitButton.textContent || 'Submit Request';
      submitButton.textContent = 'Submitting...';
    }

    try {
      await proxyFormPost('add_money_submit', formData, 'Submitting add money request...');
      showToast('Add money request submitted. Please wait for approval.', 'ok');
      state.addMoneySubmitKey = '';
      form.reset();
      addMoneyClearReceipt();
      state.addMoneyLoaded = false;
      state.historyLoaded = false;
      await loadAddMoneyPage({ force: true, busy:false });
    } catch (err) {
      if (isSessionError(err)) {
        showLogin();
        setLoginError('Session expired. Please login again.');
        return;
      }
      showToast(err.message || 'Failed to submit add money request', 'error');
    } finally {
      form.dataset.submitting = '0';
      if (submitButton && document.contains(submitButton)) {
        submitButton.disabled = false;
        submitButton.textContent = submitButton.dataset.originalText || 'Submit Request';
        delete submitButton.dataset.originalText;
      }
    }
  });
}

function focusAddMoneyForm(){
  const profile = state.addMoneyProfile || {};
  const settings = profile.settings || {};
  if (!settings.enabled) {
    showToast('Add money is temporarily unavailable', 'error');
    return;
  }

  const form = el('addMoneyForm');
  if (form) {
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    form.querySelector('[name="amount"]')?.focus({ preventScroll: true });
  }
}

async function loadAddMoneyPage(options = {}){
  if (state.addMoneyLoaded && !options.force) {
    renderAddMoneyPage();
    return;
  }

  const data = await proxyGet(
    'add_money_settings',
    {},
    options.busyText || 'Loading add money...',
    { busy: options.busy !== false }
  );

  state.addMoneyProfile = data.profile || null;
  state.addMoneyHistory = Array.isArray(data.history) ? data.history : [];
  state.addMoneyLoaded = true;
  renderAddMoneyPage();
}

/* =========================
   Bundle Offers
========================= */


  function bindPageEvents() {
    document.addEventListener('click', (event) => {
      const copy = event.target.closest('[data-copy-account-number]');
      if (copy) {
        event.preventDefault();
        const value = String(copy.dataset.copyAccountNumber || '');
        copyAddMoneyAccountNumber(value)
          .then(() => showToast('Copied', 'ok'))
          .catch(() => showToast('Copy failed', 'error'));
        return;
      }
      const card = event.target.closest('[data-add-money-account-id]');
      if (card) selectAddMoneyAccount(card.dataset.addMoneyAccountId || '');
      if (event.target.closest('#addMoneyReplaceReceiptBtn')) el('addMoneyReceiptInput')?.click();
      if (event.target.closest('#addMoneyRemoveReceiptBtn')) addMoneyClearReceipt();
    });
    document.addEventListener('keydown', (event) => {
      const card = event.target.closest?.('[data-add-money-account-id]');
      if (card && ['Enter', ' '].includes(event.key)) { event.preventDefault(); selectAddMoneyAccount(card.dataset.addMoneyAccountId || ''); }
    });
    window.addEventListener('beforeunload', addMoneyRevokeReceiptUrl);
  }

  async function init() {
    await shell.ready;
    bindPageEvents();
    await loadAddMoneyPage({ force: true });
  }
  init().catch((error) => showToast(error.message || 'Failed to load add money.', 'error'));
})();
