(() => {
  'use strict';
  const shell=window.UserShell;
  const el=(id)=>document.getElementById(id);
  const esc=shell.escapeHtml;
  const money=(value)=>{const n=Number(value||0);return Number.isFinite(n)?n.toFixed(2):'0.00';};
  const fmtMoney=(value,prefix='BDT')=>`${prefix} ${money(value)}`;
  const fmtTs=(value)=>{const n=Number(value||0);return n?new Date(n<1000000000000?n*1000:n).toLocaleString():'-';};
  const operatorName=(value)=>String(value||'-');
  const userStatusLabel=(value)=>String(value||'PENDING').replaceAll('_',' ');
  const state={walletSummary:null,requestLogs:[],bundleOffers:[],bundleBuy:{offerId:'',row:null,preview:null,idempotencyKey:''}};
  let bundleLazyTimer=null; let bundleRenderToken=0;
  const BUNDLE_FIRST_RENDER_COUNT=1,BUNDLE_RENDER_CHUNK_SIZE=1,BUNDLE_RENDER_DELAY_MS=120;
  const showToast=shell.toast,proxyGet=shell.get,proxyPost=shell.post;
  const showModalById=(id)=>el(id)?.classList.add('show');
  const hideModalById=(id)=>el(id)?.classList.remove('show');
  const renderHero=()=>{}; const renderSummary=()=>{}; const renderHistory=()=>{};
  async function safeRefreshAll(){state.walletSummary=await shell.get('wallet_summary',{},'Refreshing wallet...',{busy:false});}
function getBundleOfferItems(data){
  const out = [];
  const seen = new Set();

  function looksLikeOffer(row){
    if (!row || typeof row !== 'object' || Array.isArray(row)) return false;

    return !!(
      row.offer_id ||
      row.bundle_name ||
      row.name ||
      row.operator ||
      row.amount ||
      row.price_amount ||
      row.offer_price ||
      row.user_commission
    );
  }

  function addRow(row, key = ''){
    if (!row || typeof row !== 'object' || Array.isArray(row)) return;

    const item = { ...row };

    if (!item.offer_id && key) {
      item.offer_id = String(key);
    }

    if (!looksLikeOffer(item)) return;

    const id = String(item.offer_id || item.id || item.bundle_id || '').trim();
    const uniqueKey = id || JSON.stringify(item);

    if (seen.has(uniqueKey)) return;
    seen.add(uniqueKey);

    out.push(item);
  }

  function scan(value){
    if (!value) return;

    if (Array.isArray(value)) {
      value.forEach((row, index) => addRow(row, String(index)));
      return;
    }

    if (typeof value === 'object') {
      if (looksLikeOffer(value)) {
        addRow(value);
        return;
      }

      Object.keys(value).forEach(key => {
        const child = value[key];

        if (Array.isArray(child)) {
          scan(child);
          return;
        }

        if (child && typeof child === 'object') {
          if (looksLikeOffer(child)) {
            addRow(child, key);
          } else {
            scan(child);
          }
        }
      });
    }
  }

  scan(data);

  return out;
}

function mergeBundleResponseIntoWalletSummary(data){
  if (!data || typeof data !== 'object') return;

  if (!state.walletSummary) {
    state.walletSummary = {};
  }

  if (data.wallet && typeof data.wallet === 'object') {
    state.walletSummary.wallet = {
      ...(state.walletSummary.wallet || {}),
      ...data.wallet
    };
  }

  if (data.role_settings && typeof data.role_settings === 'object') {
    state.walletSummary.role_settings = {
      ...(state.walletSummary.role_settings || {}),
      ...data.role_settings
    };
  }
}


function applyBundleCreateSuccessToLocalState(data){
  if (!data || typeof data !== 'object') return;

  if (!state.walletSummary) {
    state.walletSummary = {};
  }

  if (!state.walletSummary.wallet) {
    state.walletSummary.wallet = {};
  }

  if (data.wallet && typeof data.wallet === 'object') {
    state.walletSummary.wallet = {
      ...(state.walletSummary.wallet || {}),
      available_balance: Number(data.wallet.available_balance || 0),
      hold_balance: Number(data.wallet.hold_balance || 0),
      updated_at: Number(data.updated_at || data.created_at || Math.floor(Date.now() / 1000))
    };
  }

  const requestId = String(data.request_id || '').trim();

  if (requestId) {
    state.requestLogs = (state.requestLogs || []).filter(row => {
      return String(row.request_id || '') !== requestId;
    });

    state.requestLogs.unshift({
      request_id: requestId,
      uid: data.uid || '',
      key_id: 'PANEL',
      action: 'BUNDLE',
      request_type: 'BUNDLE',
      source: data.source || 'USER_PANEL',
      request_source: data.request_source || 'USER_PANEL',
      status: data.status || 'WAITING_ADMIN',

      operator: data.operator || '',
      operator_name: data.operator_name || operatorName(data.operator || ''),

      topup_number: data.bundle_number || data.topup_number || data.number || '',
      bundle_number: data.bundle_number || data.topup_number || data.number || '',
      number: data.bundle_number || data.topup_number || data.number || '',

      offer_id: data.offer_id || '',
      bundle_name: data.bundle_name || '',

      amount: Number(data.you_pay || data.payable_amount || data.amount || 0),
      price_amount: Number(data.price_amount || data.offer_price || 0),
      offer_price: Number(data.offer_price || data.price_amount || 0),
      user_commission: Number(data.user_commission || data.customer_commission || data.user_discount || 0),
      you_pay: Number(data.you_pay || data.payable_amount || data.amount || 0),
      payable_amount: Number(data.payable_amount || data.you_pay || data.amount || 0),

      message: data.message || 'Bundle request created from user panel',
      created_at: Number(data.created_at || Math.floor(Date.now() / 1000)),
      updated_at: Number(data.updated_at || data.created_at || Math.floor(Date.now() / 1000)),
      completed_at: Number(data.completed_at || 0)
    });
  }

  renderHero();
  renderSummary();
  renderHistory();
}


/* =========================
   Render
========================= */

function bundleCardHtml(item){
  const offerId = String(item.offer_id || '');
  const name = String(item.bundle_name || item.name || 'Bundle Offer');
  const opName = operatorName(item.operator_name || item.operator || '-');
  const desc = String(item.description || 'Ready bundle offer for your customer.');

  const price = getBundlePrice(item);
  const userCommission = getBundleUserCommission(item);
  const youPay = getBundleYouPay(item);
  const validity = getBundleValidity(item);
  const expiry = getBundleExpiry(item);

  return `
    <div class="bundle-card bundle-card-lazy">
      <div class="bundle-card-top">
        <div>
          <div class="bundle-name">${esc(name)}</div>
          <div class="bundle-id">Offer ID: ${esc(offerId || '-')}</div>
        </div>
        <span class="pill info">${esc(opName)}</span>
      </div>

      <div class="bundle-desc">${esc(desc)}</div>

      <div class="bundle-price-grid">
        <div class="bundle-mini">
          <label>Price</label>
          <strong>${fmtMoney(price)}</strong>
        </div>

        <div class="bundle-mini">
          <label>User Commission</label>
          <strong>${fmtMoney(userCommission)}</strong>
        </div>

        <div class="bundle-mini">
          <label>You Pay</label>
          <strong>${fmtMoney(youPay)}</strong>
        </div>

        <div class="bundle-mini">
          <label>Validity</label>
          <strong>${esc(validity)}</strong>
        </div>
      </div>

      <div class="bundle-footer">
        <div class="bundle-expiry">Expires: ${esc(expiry)}</div>

        <button class="btn green bundle-buy-btn" type="button" data-offer-id="${esc(offerId)}">
          Buy Bundle
        </button>
      </div>
    </div>
  `;
}

function renderBundleOffers(options = {}){
  const wrap = el('bundleOffersGrid') || el('bundleOfferCards');

  const closeBusyAfterFirst = !!options.closeBusyAfterFirst;
  let closedBusy = false;

  function closeFirstBusy(){
    if (!closeBusyAfterFirst || closedBusy) return;

    closedBusy = true;
    setBusy(false);
  }

  if (!wrap) {
    closeFirstBusy();
    return;
  }

  clearBundleLazyTimer();
  hideBundleSummaryBoxes();

  const rows = Array.isArray(state.bundleOffers) ? state.bundleOffers : [];

  if (!rows.length) {
    wrap.innerHTML = `
      <div class="bundle-empty">
        <strong>No bundle offers found</strong>
        <span>Please refresh again or contact support.</span>
      </div>
    `;

    closeFirstBusy();
    return;
  }

  wrap.innerHTML = '';

  const currentToken = bundleRenderToken;
  let index = 0;

  function renderOneCard(){
    if (currentToken !== bundleRenderToken) {
      closeFirstBusy();
      return;
    }

    if (index >= rows.length) {
      bundleLazyTimer = null;
      closeFirstBusy();
      return;
    }

    const item = rows[index];
    index++;

    wrap.insertAdjacentHTML('beforeend', bundleCardHtml(item));

    if (index === 1) {
      hideBundleStatus();
      closeFirstBusy();
    }

    bundleLazyTimer = setTimeout(renderOneCard, BUNDLE_RENDER_DELAY_MS);
  }

  renderOneCard();
}

async function loadBundleOffers(){
  let manualBusy = false;

  try{
    clearBundleLazyTimer();

    const wrap = el('bundleOffersGrid') || el('bundleOfferCards');

    if (wrap) {
      wrap.innerHTML = `
        <div class="bundle-empty">
          <strong>Loading first bundle offer...</strong>
          <span>Offers will appear one by one.</span>
        </div>
      `;
    }

    setBundleStatus('info', 'Loading bundle offers...');
    setBusy(true, 'Loading bundle offers...');
    manualBusy = true;

    let data = {};

    try{
      data = await proxyGet(
        'bundle_offers_panel',
        {},
        'Loading bundle offers...',
        { busy: false }
      );
    }catch(firstErr){
      if (isSessionError(firstErr)) {
        throw firstErr;
      }

      data = await proxyGet(
        'bundle_offers',
        {},
        'Loading bundle offers...',
        { busy: false }
      );
    }

    const items = getBundleOfferItems(data);

    mergeBundleResponseIntoWalletSummary(data);

    state.bundleOffers = Array.isArray(items) ? items : [];

    renderHero();
    renderSummary();
    hideBundleSummaryBoxes();

    if (state.bundleOffers.length > 0) {
      renderBundleOffers({ closeBusyAfterFirst: true });
      manualBusy = false;
      return state.bundleOffers;
    }

    renderBundleOffers();

    if (manualBusy) {
      setBusy(false);
      manualBusy = false;
    }

    setBundleStatus('warning', 'No active bundle offer returned from server.');

    return state.bundleOffers;
  }catch(err){
    if (manualBusy) {
      setBusy(false);
      manualBusy = false;
    }

    state.bundleOffers = [];

    hideBundleSummaryBoxes();
    renderBundleOffers();

    if (isSessionError(err)) {
      throw err;
    }

    setBundleStatus('error', err.message || 'Failed to load bundle offers.');
    showToast(err.message || 'Failed to load bundle offers', 'error');

    throw err;
  }
}

function openBundleBuyModal(offerId){
  const modal = el('bundleBuyModal');

  if (!modal) {
    showToast('Bundle modal missing in dashboard.php', 'error');
    return;
  }

  const row = (state.bundleOffers || []).find(item => String(item.offer_id || '') === String(offerId || ''));

  if (!row) {
    showToast('Bundle offer not found', 'error');
    return;
  }

  state.bundleBuy = {
    offerId: String(row.offer_id || ''),
    row,
    preview: null,
    idempotencyKey: ''
  };

  const price = getBundlePrice(row);
  const userCommission = getBundleUserCommission(row);
  const youPay = getBundleYouPay(row);

  if (el('bundleBuyName')) el('bundleBuyName').textContent = String(row.bundle_name || row.name || '-');
  if (el('bundleBuyOfferId')) el('bundleBuyOfferId').textContent = String(row.offer_id || '-');
  if (el('bundleBuyOperator')) el('bundleBuyOperator').textContent = operatorName(row.operator_name || row.operator || '-');
  if (el('bundleBuyAmount')) el('bundleBuyAmount').textContent = fmtMoney(price);

  const commissionEl = firstExistingEl(['bundleBuyCommission', 'bundleBuyUserCommission']);
  if (commissionEl) commissionEl.textContent = fmtMoney(userCommission);

  if (el('bundleBuyNetCost')) el('bundleBuyNetCost').textContent = fmtMoney(youPay);
  if (el('bundleBuyValidity')) el('bundleBuyValidity').textContent = getBundleValidity(row);
  if (el('bundleBuyExpires')) el('bundleBuyExpires').textContent = getBundleExpiry(row);

  const numberInput = firstExistingEl(['bundleBuyNumberInput', 'bundleBuyNumber']);
  const pinInput = firstExistingEl(['bundleBuyPinInput', 'bundleBuyPin']);
  const noteInput = firstExistingEl(['bundleBuyNoteInput', 'bundleBuyNote']);

  if (numberInput) numberInput.value = '';
  if (pinInput) pinInput.value = '';
  if (noteInput) noteInput.value = 'Bundle request from user panel';
  if (numberInput) numberInput.disabled = false;
  if (pinInput) pinInput.disabled = false;

  const confirmButton = firstExistingEl(['confirmBundleBuyBtn', 'submitBundleBuyBtn']);
  if (confirmButton) confirmButton.textContent = 'Review Bundle';

  const out = firstExistingEl(['bundleBuyOutput', 'bundleBuyResult']);
  if (out) {
    out.className = 'bundle-result-box';
    out.textContent = 'Enter bundle number and PIN to create request.';
  }

  modal.classList.add('show');

  setTimeout(() => {
    numberInput?.focus();
  }, 150);
}

function closeBundleBuyModal(){
  el('bundleBuyModal')?.classList.remove('show');

  state.bundleBuy = {
    offerId: '',
    row: null,
    preview: null,
    idempotencyKey: ''
  };
}

function showBundleMainResult(){
  const box = el('bundleResult');
  const wrap = box ? box.closest('.result-box') : null;

  if (wrap) {
    wrap.classList.remove('hidden');
  }

  return box;
}

function renderBundleResultSuccess(data){
  const box = showBundleMainResult();
  if (!box) return;

  box.className = '';
  box.innerHTML = `
    <div class="result-card good">
      <div class="result-title">Bundle request created successfully</div>
      <div class="result-text">
Request ID: ${esc(data.request_id || '-')}
Status: ${esc(userStatusLabel(data.status || 'WAITING_ADMIN'))}
Number: ${esc(data.bundle_number || '-')}
Operator: ${esc(operatorName(data.operator || '-'))}
Bundle: ${esc(data.bundle_name || '-')}
You Pay: BDT ${money(data.you_pay || data.payable_amount || data.amount || 0)}
User Commission: BDT ${money(data.user_commission || data.customer_commission || data.user_discount || 0)}
Created: ${esc(fmtTs(data.created_at || 0))}
      </div>
    </div>
  `;
}

function renderBundleBuyOutputSuccess(data){
  const box = firstExistingEl(['bundleBuyOutput', 'bundleBuyResult']);
  if (!box) return;

  box.className = 'bundle-result-box success';
  box.textContent =
`Request ID: ${data.request_id || '-'}
Status: ${userStatusLabel(data.status || 'WAITING_ADMIN')}
You Pay: BDT ${money(data.you_pay || data.payable_amount || data.amount || 0)}`;
}

function renderBundleBuyOutputError(message){
  const box = firstExistingEl(['bundleBuyOutput', 'bundleBuyResult']);
  if (!box) return;

  box.className = 'bundle-result-box error';
  box.textContent = message || 'Unknown error';
}

async function submitBundleBuy(){
  const row = state.bundleBuy.row;
  const offerId = state.bundleBuy.offerId;
  const bundleNumber = (firstExistingEl(['bundleBuyNumberInput', 'bundleBuyNumber'])?.value || '').trim();
  const pin = (firstExistingEl(['bundleBuyPinInput', 'bundleBuyPin'])?.value || '').trim();
  const note = (firstExistingEl(['bundleBuyNoteInput', 'bundleBuyNote'])?.value || '').trim();

  if (!row || !offerId) {
    renderBundleBuyOutputError('Bundle offer missing. Please select offer again.');
    return;
  }

  if (!bundleNumber || bundleNumber.replace(/\D+/g, '').length < 10) {
    renderBundleBuyOutputError('Valid bundle number is required.');
    showToast('Valid bundle number is required', 'error');
    return;
  }

  if (!pin) {
    renderBundleBuyOutputError('Transaction PIN is required.');
    showToast('PIN is required', 'error');
    return;
  }

  try{
    const confirmButton = firstExistingEl(['confirmBundleBuyBtn', 'submitBundleBuyBtn']);

    if (!state.bundleBuy.preview?.preview_token) {
      if (confirmButton) {
        confirmButton.disabled = true;
        confirmButton.textContent = 'Reviewing...';
      }

      await validateTransactionPin(pin);
      const preview = await proxyPost('bundle_preview', {
        offer_id: offerId,
        bundle_number: bundleNumber,
        verified_by: 'USER_WEB'
      }, 'Loading bundle preview...');

      if (!preview?.preview_token) {
        throw new Error('Bundle preview could not be created.');
      }

      state.bundleBuy.preview = preview;
      if (!state.bundleBuy.idempotencyKey) {
        state.bundleBuy.idempotencyKey = window.crypto?.randomUUID?.()
          || `BUNDLE-${Date.now()}-${Math.random().toString(16).slice(2)}`;
      }

      if (el('bundleBuyAmount')) el('bundleBuyAmount').textContent = fmtMoney(preview.service_amount || preview.amount || 0);
      const commissionEl = firstExistingEl(['bundleBuyCommission', 'bundleBuyUserCommission']);
      if (commissionEl) commissionEl.textContent = fmtMoney(preview.user_commission || preview.bundle_commission || 0);
      if (el('bundleBuyNetCost')) {
        const prefix = String(preview.wallet_currency || preview.wallet_debit_currency || 'BDT').toUpperCase() === 'MYR' ? 'RM ' : 'BDT ';
        el('bundleBuyNetCost').textContent = prefix + money(preview.wallet_debit_amount || preview.wallet_hold_amount || 0);
      }

      const out = firstExistingEl(['bundleBuyOutput', 'bundleBuyResult']);
      if (out) {
        const walletCurrency = String(preview.wallet_currency || preview.wallet_debit_currency || 'BDT').toUpperCase();
        const walletPrefix = walletCurrency === 'MYR' ? 'RM' : 'BDT';
        out.className = 'bundle-result-box success';
        out.textContent =
`Review ready
Number: ${preview.bundle_number || bundleNumber}
Bundle: ${preview.bundle_name || row.bundle_name || row.name || '-'}
Service amount: BDT ${money(preview.service_amount || preview.amount || 0)}
Wallet debit: ${walletPrefix} ${money(preview.wallet_debit_amount || preview.wallet_hold_amount || 0)}
Balance after: ${walletPrefix} ${money(preview.balance_after || 0)}`;
      }

      const numberInput = firstExistingEl(['bundleBuyNumberInput', 'bundleBuyNumber']);
      const pinInput = firstExistingEl(['bundleBuyPinInput', 'bundleBuyPin']);
      if (numberInput) numberInput.disabled = true;
      if (pinInput) pinInput.disabled = true;
      if (confirmButton) {
        confirmButton.disabled = false;
        confirmButton.textContent = 'Confirm Bundle';
      }
      return;
    }

    if (confirmButton) {
      confirmButton.disabled = true;
      confirmButton.textContent = 'Submitting...';
    }

    const data = await proxyPost('bundle_submit', {
      preview_token: state.bundleBuy.preview.preview_token,
      offer_id: offerId,
      bundle_number: bundleNumber,
      pin,
      note: note || 'Bundle request from user panel',
      idempotency_key: state.bundleBuy.idempotencyKey
    }, 'Creating bundle request...');

    renderBundleBuyOutputSuccess(data);
    renderBundleResultSuccess(data);
    applyBundleCreateSuccessToLocalState(data);
    showToast('Bundle request created successfully', 'ok');
    
    setTimeout(() => {
        closeBundleBuyModal();
        window.location.assign('/user/history');
    }, 500);
  }catch(err){
    renderBundleBuyOutputError(err.message || 'Failed to create bundle request');
    showToast(err.message || 'Failed to create bundle request', 'error');
  }finally{
    const confirmButton = firstExistingEl(['confirmBundleBuyBtn', 'submitBundleBuyBtn']);
    if (confirmButton && el('bundleBuyModal')?.classList.contains('show')) {
      confirmButton.disabled = false;
      confirmButton.textContent = state.bundleBuy.preview?.preview_token ? 'Confirm Bundle' : 'Review Bundle';
    }
  }
}

  function bindBundlePage(){
    document.addEventListener('click',(event)=>{
      const buy=event.target.closest('.bundle-buy-btn'); if(buy){event.preventDefault();openBundleBuyModal(buy.dataset.offerId||buy.dataset.bundleOfferId||'');return;}
      if(event.target.closest('#confirmBundleBuyBtn')){event.preventDefault();submitBundleBuy();return;}
      if(event.target.closest('#cancelBundleBuyBtn,#closeBundleBuyModalBtn')){event.preventDefault();closeBundleBuyModal();return;}
      if(event.target===el('bundleBuyModal'))closeBundleBuyModal();
    });
    el('refreshBundleOffersBtn')?.addEventListener('click',()=>loadBundleOffers().catch((error)=>showToast(error.message,'error')));
    el('bundleBuyPin')?.addEventListener('keydown',(event)=>{if(event.key==='Enter')submitBundleBuy();});
    document.addEventListener('keydown',(event)=>{if(event.key==='Escape')closeBundleBuyModal();});
  }
  async function init(){await shell.ready;state.walletSummary=await shell.get('wallet_summary',{},'Loading wallet...',{busy:false});bindBundlePage();await loadBundleOffers();}
  init().catch((error)=>showToast(error.message||'Bundle offers could not be loaded.','error'));
})();
