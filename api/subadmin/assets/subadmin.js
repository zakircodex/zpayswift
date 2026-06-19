const APP_API_BASE = (() => {
  const configured = String(window.SUBADMIN_API_BASE || '').trim();
  if (configured) return configured.replace(/\/+$/, '');

  const path = window.location.pathname || '';
  const marker = '/api/subadmin/';
  const index = path.indexOf(marker);

  if (index >= 0) {
    return window.location.origin + path.slice(0, index) + '/api';
  }

  return window.location.origin + '/api';
})();

const state = window.subadminState = {
  csrf: '',
  me: null,
  wallet: null,
  apiKeys: [],
  requestLogs: [],
  addMoneyProfile: null,
  addMoneyHistory: [],
  bundleOffers: [],
  users: [],
  mfs: {
    tab: 'pending',
    summary: {
      pending: 0,
      processing: 0,
      done: 0,
      failed: 0
    },
    rows: [],
    loaded: false
  },
  mfsCreateReview: null,

  bundleBuy: {
    offerId: '',
    row: null
  },
  
  bundleCommission: {
  offerId: '',
  row: null
  },

  busyCount: 0,
requestLogFilter: 'ALL',
logsAutoRefreshTimer: null,

loaded: {
  wallet: false,
  keys: false,
  logs: false,
  addMoney: false,
  users: false,
  bundleOffers: false,
  mfs: false,
  mfsSummary: false,
  mfsList: false
},

loadingSections: {},
bundleRenderToken: '',

  deductOtp: {
    targetUid: '',
    otpRequestId: '',
    targetName: '',
    targetPhone: '',
    targetCurrency: 'BDT',
    amount: 0,
    note: ''
  },

  addBalance: {
    targetUid: '',
    targetName: '',
    targetPhone: '',
    targetCurrency: 'BDT'
  },

  walletLedger: {
    targetUid: '',
    targetName: '',
    targetPhone: ''
  },

  userCreateOtp: {
    requestToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300,
    expiresAt: 0,
    timer: null,
    formData: null
  }
};

function el(id){
  return document.getElementById(id);
}

function esc(v){
  return String(v ?? '').replace(/[&<>"']/g, s => ({
    '&':'&amp;',
    '<':'&lt;',
    '>':'&gt;',
    '"':'&quot;',
    "'":'&#39;'
  }[s]));
}

function money(v){
  return Number(v || 0).toFixed(2);
}

function fmtMoney(v, prefix = 'BDT'){
  return `${prefix} ${money(v)}`;
}

function walletPrefix(currency){
  return String(currency || 'BDT').toUpperCase() === 'MYR' ? 'RM' : 'BDT';
}

function walletNativeCurrency(row){
  const country = String(
    row?.pricing_country || row?.market_country || row?.service_country || row?.country_code || row?.country || ''
  ).trim().toUpperCase();
  if (country === 'MY') return 'MYR';
  if (country === 'BD') return 'BDT';

  const currency = String(row?.wallet_currency || row?.currency || row?.display_currency || 'BDT').trim().toUpperCase();
  return ['MYR', 'RM'].includes(currency) ? 'MYR' : 'BDT';
}

function fmtWalletMoney(row, type = 'available'){
  const currency = String(row?.display_currency || row?.wallet_currency || row?.currency || 'BDT').toUpperCase();
  const key = type === 'hold' ? 'display_hold_balance' : 'display_available_balance';
  const fallback = type === 'hold' ? 'hold_balance' : 'available_balance';
  return `${walletPrefix(currency)} ${money(row?.[key] ?? row?.[fallback] ?? 0)}`;
}

function walletRawHint(row, type = 'available'){
  if (walletNativeCurrency(row) === 'MYR') return '';
  const note = String(row?.conversion_note || '').trim();
  if (!note) return '';
  const rawKey = type === 'hold' ? 'hold_balance_bdt' : 'available_balance_bdt';
  return `Stored: BDT ${money(row?.[rawKey] ?? row?.[type === 'hold' ? 'hold_balance' : 'available_balance'] ?? 0)}`;
}

function fmtTs(ts){
  if (!ts) return '-';
  const raw = Number(ts);
  const ms = String(Math.trunc(raw)).length <= 10 ? raw * 1000 : raw;
  const d = new Date(ms);
  return isNaN(d.getTime()) ? '-' : d.toLocaleString();
}


function getBundlePrice(item){
  return Number(
    item?.price_amount ??
    item?.offer_price ??
    item?.price ??
    item?.amount ??
    0
  );
}

function getBundleUserCommission(item){
  return Number(item?.user_commission || 0);
}

function getBundleYouPay(item){
  const price = getBundlePrice(item);
  const userCommission = getBundleUserCommission(item);

  return Number(
    item?.you_pay ??
    item?.payable_amount ??
    item?.wallet_hold_amount ??
    item?.net_cost_after_commission ??
    Math.max(0, price - userCommission)
  );
}

function getBundlePackageValidity(item){
  const direct = String(
    item?.package_validity ??
    item?.bundle_validity ??
    item?.validity_text ??
    item?.package_duration ??
    ''
  ).trim();

  if (direct) return direct.toUpperCase();

  const name = String(item?.bundle_name || item?.name || '').toUpperCase();

  // Example:
  // 35 GB 400 BDT 30 DAY  => 30 DAY
  // 70 GB 30 DAY 450 BDT  => 30 DAY
  // 100 GB 30DAY          => 30 DAY
  const matches = [...name.matchAll(/(\d+(?:\.\d+)?)\s*(DAY|DAYS|MONTH|MONTHS|HOUR|HOURS|MINUTE|MINUTES)\b/g)];

  if (!matches.length) return '-';

  const last = matches[matches.length - 1];
  const value = last[1];
  let unit = last[2];

  if (unit === 'DAYS') unit = 'DAY';
  if (unit === 'MONTHS') unit = 'MONTH';
  if (unit === 'HOURS') unit = 'HOUR';
  if (unit === 'MINUTES') unit = 'MINUTE';

  return `${value} ${unit}`;
}


function statusPill(v){
  const t = String(v || '').toUpperCase();
  let cls = 'info';

  if (['SUCCESS','SUCCESSFUL','DONE','ACTIVE','COMPLETED','APPROVED','VERIFIED'].includes(t)) cls = 'success';
  else if (['FAILED','DISABLED','REVOKED','INACTIVE','LOCKED','SMS_FAILED','REJECTED','CANCELLED'].includes(t)) cls = 'danger';
  else if (['PENDING','EXPIRED','WAITING','WAITING_ADMIN','OTP_PENDING','PROCESSING'].includes(t)) cls = 'warning';

  return `<span class="pill ${cls}">${esc(v || '-')}</span>`;
}

function setBusy(on, text = 'Loading...'){
  const wrap = el('loadingWrap');
  const txt = el('loadingText');

  if (!wrap || !txt) return;

  if (on) {
    state.busyCount++;
    txt.textContent = text;
    wrap.classList.add('show');
    return;
  }

  state.busyCount = Math.max(0, state.busyCount - 1);

  if (state.busyCount === 0) {
    wrap.classList.remove('show');
    txt.textContent = 'Loading...';
  }
}

function showToast(message, type = 'info'){
  const wrap = el('toastWrap');
  if (!wrap) return;

  const div = document.createElement('div');
  div.className = 'toast ' + type;
  div.textContent = String(message || '');
  wrap.appendChild(div);

  setTimeout(() => div.remove(), 3500);
}

async function withButtonLoading(button, loadingText, task){
  if (!button) return task();
  if (button.disabled) return null;

  const originalText = button.textContent;
  button.disabled = true;
  button.dataset.loading = '1';
  button.textContent = loadingText || 'Loading...';

  try{
    return await task();
  } finally {
    button.disabled = false;
    delete button.dataset.loading;
    button.textContent = originalText;
  }
}


function injectDashboardLazyScrollStyle(){
  if (document.getElementById('subadminLazyScrollStyle')) return;

  const style = document.createElement('style');
  style.id = 'subadminLazyScrollStyle';
  style.textContent = `
    html, body{
      height:100%;
      overflow:hidden !important;
    }

    .wrap{
      height:100dvh !important;
      overflow:hidden !important;
      box-sizing:border-box;
    }

    .app-shell{
      height:100% !important;
      min-height:0 !important;
      overflow:hidden !important;
    }

    .sidebar{
      height:100% !important;
      max-height:100% !important;
      overflow-y:auto !important;
      overflow-x:hidden !important;
      overscroll-behavior:contain;
      scrollbar-width:thin;
    }

    .main-panel{
      height:100% !important;
      min-height:0 !important;
      overflow:hidden !important;
      display:flex !important;
      flex-direction:column !important;
    }

    .main-panel > .topbar{
      flex:0 0 auto !important;
    }

    .page-section{
      display:none !important;
    }

    .page-section.active{
      display:block !important;
      flex:1 1 auto !important;
      min-height:0 !important;
      overflow-y:auto !important;
      overflow-x:hidden !important;
      padding-right:6px;
      overscroll-behavior:contain;
      scroll-behavior:smooth;
      scrollbar-width:thin;
    }

    .table-wrap{
      max-width:100% !important;
      overflow:auto !important;
      overscroll-behavior:contain;
      scrollbar-width:thin;
    }

    .modal-card,
    .modal-card-wide,
    .modal-card-sm{
      max-height:calc(100dvh - 40px) !important;
      overflow-y:auto !important;
      scrollbar-width:thin;
    }

    .sidebar::-webkit-scrollbar,
    .page-section.active::-webkit-scrollbar,
    .table-wrap::-webkit-scrollbar,
    .modal-card::-webkit-scrollbar{
      width:8px;
      height:8px;
    }

    .sidebar::-webkit-scrollbar-thumb,
    .page-section.active::-webkit-scrollbar-thumb,
    .table-wrap::-webkit-scrollbar-thumb,
    .modal-card::-webkit-scrollbar-thumb{
      background:rgba(110,149,221,.35);
      border-radius:999px;
    }

    .sidebar::-webkit-scrollbar-track,
    .page-section.active::-webkit-scrollbar-track,
    .table-wrap::-webkit-scrollbar-track,
    .modal-card::-webkit-scrollbar-track{
      background:rgba(255,255,255,.04);
    }

    @media (max-width:900px){
      html, body{
        height:auto;
        overflow:auto !important;
      }

      .wrap{
        height:auto !important;
        min-height:100dvh !important;
        overflow:visible !important;
      }

      .app-shell{
        height:auto !important;
        overflow:visible !important;
      }

      .sidebar,
      .main-panel{
        height:auto !important;
        max-height:none !important;
        overflow:visible !important;
      }

      .page-section.active{
        height:auto !important;
        overflow:visible !important;
      }
    }
  `;

  document.head.appendChild(style);
}

function getCurrentSectionId(){
  return document.querySelector('.page-section.active')?.id || 'overviewSection';
}

function scrollPageSectionTop(sectionId){
  const section = el(sectionId);
  if (section) {
    section.scrollTo({ top: 0, behavior: 'smooth' });
    return;
  }

  const main = document.querySelector('.main-panel');
  if (main) {
    main.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

async function ensureWalletLoaded(force = false){
  if (force || !state.loaded.wallet) {
    await loadWallet();
  }
  renderSummary();
}

async function ensureKeysLoaded(force = false){
  if (force || !state.loaded.keys) {
    await loadKeys();
  }
  renderKeys();
  renderSummary();
  renderIntegrationGuide();
}

async function ensureLogsLoaded(force = false){
  if (force || !state.loaded.logs) {
    await loadLogs();
  }
  renderLogs();
  renderPanelTopupRequests();
  renderSummary();
}

async function ensureUsersLoaded(force = false){
  if (force || !state.loaded.users) {
    await loadUsers();
  }
  renderUsers();
}

async function ensureBundleOffersLoaded(force = false){
  if (force || !state.loaded.bundleOffers) {
    await loadBundleOffers();
  } else {
    renderBundleOffers();
  }
}

function addMoneyHistoryCard(row){
  const prefix = walletPrefix(row.currency || 'BDT');
  const receipt = String(row.receipt_url || '').trim();
  return `
    <div class="history-log-card">
      <div class="history-log-card-top">
        <strong>${esc(row.request_id || '-')}</strong>
        ${statusPill(row.status || 'PENDING')}
      </div>
      <div class="history-log-card-grid">
        <span>Method</span><b>${esc(row.method || '-')}</b>
        <span>Amount</span><b>${prefix} ${money(row.amount || 0)}</b>
        <span>Submitted</span><b>${esc(fmtTs(row.created_at || 0))}</b>
        <span>Processed</span><b>${esc(fmtTs(row.approved_at || row.rejected_at || 0))}</b>
      </div>
      ${row.reject_reason ? `<div class="muted">${esc(row.reject_reason)}</div>` : ''}
      <div class="actions">
        <button class="btn ghost" type="button" onclick="copyText('${esc(row.request_id || '')}','Request ID copied')">Copy ID</button>
        ${receipt ? `<button class="btn green" type="button" onclick="window.open('${esc(receipt)}','_blank','noopener')">Receipt</button>` : ''}
      </div>
    </div>
  `;
}

function renderAddMoneyHistory(){
  const list = el('addMoneyHistoryList');
  if (!list) return;

  if (!state.addMoneyHistory.length) {
    list.innerHTML = '<div class="muted">No add money request yet.</div>';
    return;
  }

  list.innerHTML = state.addMoneyHistory.map(addMoneyHistoryCard).join('');
}

function renderAddMoneyPage(){
  const wrap = el('addMoneyContent');
  if (!wrap) return;

  const profile = state.addMoneyProfile || {};
  const settings = profile.settings || {};
  const country = String(profile.pricing_country || '').toUpperCase();
  const prefix = walletPrefix(profile.currency || (country === 'MY' ? 'MYR' : 'BDT'));

  if (!settings.enabled) {
    wrap.innerHTML = `
      <div class="box">
        <label>Add Money</label>
        <strong>Temporarily unavailable</strong>
        <p class="muted">Please contact support if you need help adding balance.</p>
      </div>
    `;
    renderAddMoneyHistory();
    return;
  }

  if (country === 'MY') {
    wrap.innerHTML = `
      <form id="addMoneyForm" class="form-grid" enctype="multipart/form-data">
        <input type="hidden" name="method" value="BANK">
        <div class="box add-money-payment-card form-full">
          <label>Bank Transfer Details</label>
          <div class="add-money-detail-list">
            <div class="add-money-detail-row"><span>Bank Name</span><strong>${esc(settings.bank_name || '-')}</strong></div>
            <div class="add-money-detail-row"><span>Account Holder Name</span><strong>${esc(settings.account_holder || '-')}</strong></div>
            <div class="add-money-detail-row"><span>Account Number</span><strong>${esc(settings.account_number || '-')}</strong></div>
          </div>
          <div class="add-money-copy-action"><button class="btn ghost" type="button" data-copy-account-number="${esc(settings.account_number || '')}">Copy Number</button></div>
        </div>
        <div class="field form-full"><label>Instruction</label><p class="muted">${esc(settings.instruction || 'Transfer and upload your receipt.')}</p></div>
        <div class="field"><label>Amount (${prefix})</label><input class="input" name="amount_rm" type="number" min="1" step="0.01" placeholder="Enter amount"></div>
        <div class="field"><label>Receipt Upload</label><input class="input" name="receipt_upload" type="file" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
        <div class="field form-full"><label>Note / Reference</label><input class="input" name="note" placeholder="Optional note"></div>
        <div class="actions form-full"><button class="btn green" type="submit">Submit Add Money Request</button></div>
      </form>
    `;
  } else {
    wrap.innerHTML = `
      <form id="addMoneyForm" class="form-grid">
        <div class="box add-money-payment-card">
          <label>bKash Payment</label>
          <div class="add-money-detail-list">
            <div class="add-money-detail-row"><span>Number</span><strong>${esc(settings.bkash_number || '-')}</strong></div>
            <div class="add-money-detail-row"><span>Account Type</span><strong>${esc(settings.bkash_account_type || '-')}</strong></div>
          </div>
          <div class="add-money-copy-action"><button class="btn ghost" type="button" data-copy-account-number="${esc(settings.bkash_number || '')}">Copy Number</button></div>
        </div>
        <div class="box add-money-payment-card">
          <label>Nagad Payment</label>
          <div class="add-money-detail-list">
            <div class="add-money-detail-row"><span>Number</span><strong>${esc(settings.nagad_number || '-')}</strong></div>
            <div class="add-money-detail-row"><span>Account Type</span><strong>${esc(settings.nagad_account_type || '-')}</strong></div>
          </div>
          <div class="add-money-copy-action"><button class="btn ghost" type="button" data-copy-account-number="${esc(settings.nagad_number || '')}">Copy Number</button></div>
        </div>
        <div class="field form-full"><label>Instruction</label><p class="muted">${esc(settings.instruction || 'Send money first, then submit transaction ID.')}</p></div>
        <div class="field"><label>Method</label><select class="input" name="method"><option value="BKASH">bKash</option><option value="NAGAD">Nagad</option></select></div>
        <div class="field"><label>Amount (${prefix})</label><input class="input" name="amount_bdt" type="number" min="1" step="0.01" placeholder="Enter amount"></div>
        <div class="field"><label>Transaction ID</label><input class="input" name="transaction_id" placeholder="bKash/Nagad transaction ID"></div>
        <div class="field"><label>Sender Number</label><input class="input" name="sender_number" placeholder="Number used to send payment"></div>
        <div class="actions form-full"><button class="btn green" type="submit">Submit Add Money Request</button></div>
      </form>
    `;
  }

  const form = el('addMoneyForm');
  if (form && form.dataset.bound !== '1') {
    form.dataset.bound = '1';
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await proxyFormPost('add_money_submit', new FormData(form), 'Submitting add money request...');
        showToast('Add money request submitted. Please wait for approval.', 'ok');
        form.reset();
        state.loaded.addMoney = false;
        await ensureAddMoneyLoaded(true);
      } catch (err) {
        showToast(err.message || 'Failed to submit add money request', 'error');
      }
    });
  }

  renderAddMoneyHistory();
}

async function ensureAddMoneyLoaded(force = false){
  if (force || !state.loaded.addMoney) {
    const data = await proxyGet('add_money_settings', {}, 'Loading add money...');
    state.addMoneyProfile = data.profile || null;
    state.addMoneyHistory = Array.isArray(data.history) ? data.history : [];
    state.loaded.addMoney = true;
  }

  renderAddMoneyPage();
}

async function loadSectionData(sectionId, force = false){
  sectionId = sectionId || 'overviewSection';

  const lockKey = sectionId + ':' + (force ? 'force' : 'normal');
  if (state.loadingSections[lockKey]) {
    return state.loadingSections[lockKey];
  }

  const task = (async () => {
    if (sectionId === 'overviewSection') {
      await ensureWalletLoaded(force);
      await ensureKeysLoaded(force);
      await ensureLogsLoaded(force);
      return;
    }

    if (sectionId === 'bundleOffersSection') {
      await ensureBundleOffersLoaded(force);
      return;
    }

    if (sectionId === 'apiKeysSection') {
      await ensureWalletLoaded(false);
      await ensureKeysLoaded(force);
      return;
    }

    if (sectionId === 'requestLogsSection') {
      await ensureWalletLoaded(false);
      await ensureLogsLoaded(force);
      return;
    }

    if (sectionId === 'addMoneySection') {
      await ensureWalletLoaded(false);
      await ensureAddMoneyLoaded(force);
      return;
    }

    if (sectionId === 'usersSection') {
      await ensureWalletLoaded(false);
      await ensureUsersLoaded(force);
      return;
    }

    if (sectionId === 'panelTopupSection') {
      await ensureWalletLoaded(false);
      await ensureLogsLoaded(force);
      return;
    }

    if (sectionId === 'mfsCreateSection') {
      await ensureWalletLoaded(false);
      await ensureMfsSummaryLoaded(force);
      return;
    }

    if (sectionId === 'mfsRequestsSection') {
      await ensureWalletLoaded(false);
      setSubMfsTab(state.mfs.tab || 'pending');
      await ensureMfsListLoaded(force);
      return;
    }

    if (sectionId === 'integrationGuideSection') {
      renderIntegrationGuide();
      return;
    }

    if (sectionId === 'bundleApiTestSection' || sectionId === 'liveApiTestSection' || sectionId === 'createUserSection') {
      await ensureWalletLoaded(false);
    }
  })();

  state.loadingSections[lockKey] = task;

  try{
    await task;
  }finally{
    delete state.loadingSections[lockKey];
  }
}


function showLogin(){
  el('loginView')?.classList.remove('hidden');
  el('appView')?.classList.add('hidden');
}

function showApp(){
  el('loginView')?.classList.add('hidden');
  el('appView')?.classList.remove('hidden');
}

function setLoginError(msg = ''){
  const node = el('loginError');
  if (!node) return;

  if (!msg) {
    node.classList.add('hidden');
    node.textContent = '';
    return;
  }

  node.classList.remove('hidden');
  node.textContent = msg;
}

function morphOutputBox(id){
  const node = el(id);
  if (!node) return null;

  if (node.tagName === 'PRE') {
    const div = document.createElement('div');
    div.id = node.id;
    div.className = 'status-box-clean';
    div.innerHTML = '<div style="width:100%">Ready.</div>';
    node.replaceWith(div);
    return div;
  }

  node.classList.remove('code-box');
  node.classList.add('status-box-clean');
  return node;
}

function setBoxMessage(id, type, title, lines = []){
  const node = el(id);
  if (!node) return;

  const pillClass =
    type === 'ok' ? 'success' :
    type === 'error' ? 'danger' :
    type === 'warning' ? 'warning' : 'info';

  const cleanLines = Array.isArray(lines) ? lines.filter(Boolean) : [];
  const listHtml = cleanLines.length
    ? cleanLines.map(line => `<div class="muted" style="margin-top:6px;line-height:1.55;">${esc(line)}</div>`).join('')
    : `<div class="muted" style="margin-top:6px;line-height:1.55;">No additional details.</div>`;

  node.innerHTML = `
    <div style="width:100%">
      <div style="margin-bottom:10px;">${statusPill(title || 'Status').replace(/pill info|pill success|pill warning|pill danger/, 'pill ' + pillClass)}</div>
      ${listHtml}
    </div>
  `;
}

function setDetailBox(id, title, pairs = []){
  const node = el(id);
  if (!node) return;

  const rows = pairs
    .filter(item => Array.isArray(item) && item.length >= 2)
    .map(item => `
      <div style="display:flex;gap:10px;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06);">
        <div class="muted" style="font-weight:700;">${esc(item[0])}</div>
        <div style="text-align:right;">${esc(item[1])}</div>
      </div>
    `)
    .join('');

  node.innerHTML = `
    <div style="width:100%">
      <div style="margin-bottom:10px;">${statusPill(title || 'Details')}</div>
      <div>${rows || '<div class="muted">No details found.</div>'}</div>
    </div>
  `;
}

function upgradeOutputBoxes(){
  [
    'panelTopupOutput',
    'createUserOutput',
    'liveApiOutput',
    'bundleOffersOutput',
    'bundleApiOutput',
    'panelBundleBuyOutput',
    'subMfsOutput',
    'subMfsDetailsOutput',
    'logRawJson',
    'addBalanceStatusBox',
    'deductOtpStatusBox',
    'deductOtpRequestInfo'
  ].forEach(morphOutputBox);

  const rawLabel = el('logRawJson')?.previousElementSibling;
  if (rawLabel && rawLabel.tagName === 'LABEL') {
    rawLabel.textContent = 'Details';
  }
}

async function readJsonSafe(res){
  const text = await res.text();

  if (!text || !text.trim()) {
    throw new Error('Empty response from server');
  }

  try{
    return JSON.parse(text);
  }catch(_){
    throw new Error(text.length > 400 ? text.slice(0, 400) : text);
  }
}

async function proxyGet(action, params = {}, busyText = 'Loading...'){
  setBusy(true, busyText);

  try{
    const qs = new URLSearchParams(params).toString();
    const res = await fetch(
      (window.SUBADMIN_PROXY_URL || '/api/subadmin/proxy.php') + '?action=' + encodeURIComponent(action) + (qs ? '&' + qs : ''),
      {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }
    );

    const json = await readJsonSafe(res);

    if (!res.ok || !json.ok) {
      const err = new Error(json.message || 'Request failed');
      err.code = json.code || 'ERROR';
      err.data = json.data || {};
      throw err;
    }

    return json.data || {};
  } finally {
    setBusy(false);
  }
}

async function proxyPost(action, body = {}, busyText = 'Processing...'){
  setBusy(true, busyText);

  try{
    const headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    };

    if (state.csrf) {
      headers['X-CSRF-TOKEN'] = state.csrf;
    }

    const res = await fetch((window.SUBADMIN_PROXY_URL || '/api/subadmin/proxy.php') + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify(body)
    });

    const json = await readJsonSafe(res);

    if (!res.ok || !json.ok) {
      const err = new Error(json.message || 'Request failed');
      err.code = json.code || 'ERROR';
      err.data = json.data || {};
      throw err;
    }

    return json.data || {};
  } finally {
    setBusy(false);
  }
}

async function proxyFormPost(action, formData, busyText = 'Processing...'){
  setBusy(true, busyText);

  try{
    const headers = { 'Accept': 'application/json' };

    if (state.csrf) {
      headers['X-CSRF-TOKEN'] = state.csrf;
    }

    const res = await fetch((window.SUBADMIN_PROXY_URL || '/api/subadmin/proxy.php') + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers,
      body: formData
    });

    const json = await readJsonSafe(res);

    if (!res.ok || !json.ok) {
      const err = new Error(json.message || 'Request failed');
      err.code = json.code || 'ERROR';
      err.data = json.data || {};
      throw err;
    }

    return json.data || {};
  } finally {
    setBusy(false);
  }
}

async function copyText(text, successMessage){
  try{
    await navigator.clipboard.writeText(String(text || ''));
    showToast(successMessage || 'Copied', 'ok');
  }catch(_){
    showToast('Copy failed. Please copy manually.', 'error');
  }
}

function copyById(id, successMessage){
  const node = el(id);
  if (!node) {
    showToast('Element not found', 'error');
    return;
  }
  copyText((node.textContent || '').trim(), successMessage);
}

function copyLastPlainKey(){
  const key = el('lastPlainKey')?.textContent?.trim() || '';
  if (!key || key === '-') {
    showToast('No plain key available to copy', 'error');
    return;
  }
  copyText(key, 'Plain API key copied');
}

function useLastPlainKeyInLiveTest(){
  const key = el('lastPlainKey')?.textContent?.trim() || '';
  if (!key || key === '-') {
    showToast('No plain key available', 'error');
    return;
  }

  const input = el('liveApiKey');
  if (input) input.value = key;

  openPageSection('apiTestSection');
  setApiTestTab('liveApiTestPanel');

  showToast('Plain key inserted into live test', 'ok');
}

function renderIntegrationGuide(){
  const topupEndpoint = APP_API_BASE + '/public_api/topup_create.php';
  const bundleEndpoint = APP_API_BASE + '/public_api/bundle_create.php';

  const plainKey = el('lastPlainKey')?.textContent?.trim() || '';
  const sampleKey = (plainKey && plainKey !== '-') ? plainKey : 'YOUR_PLAIN_API_KEY';

  const topupBodyObj = {
    topup_number: '01712345678',
    operator: 'GP',
    amount: 20,
    note: 'API test topup'
  };

  const bundleBodyObj = {
    offer_id: 'YOUR_BUNDLE_OFFER_ID',
    bundle_number: '01712345678',
    note: 'API test bundle'
  };

  const topupBodyJson = JSON.stringify(topupBodyObj, null, 2);
  const bundleBodyJson = JSON.stringify(bundleBodyObj, null, 2);

  const topupCurlText =
`curl -X POST "${topupEndpoint}" \\
  -H "Authorization: Bearer ${sampleKey}" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json" \\
  -d '${JSON.stringify(topupBodyObj)}'`;

  const bundleCurlText =
`curl -X POST "${bundleEndpoint}" \\
  -H "Authorization: Bearer ${sampleKey}" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json" \\
  -d '${JSON.stringify(bundleBodyObj)}'`;

  if (el('guideTopupEndpoint')) el('guideTopupEndpoint').textContent = topupEndpoint;
  if (el('guideTopupAuth')) el('guideTopupAuth').textContent = 'Bearer ' + sampleKey;
  if (el('guideTopupBody')) el('guideTopupBody').textContent = topupBodyJson;
  if (el('guideTopupCurl')) el('guideTopupCurl').textContent = topupCurlText;

  if (el('guideBundleEndpoint')) el('guideBundleEndpoint').textContent = bundleEndpoint;
  if (el('guideBundleAuth')) el('guideBundleAuth').textContent = 'Bearer ' + sampleKey;
  if (el('guideBundleBody')) el('guideBundleBody').textContent = bundleBodyJson;
  if (el('guideBundleCurl')) el('guideBundleCurl').textContent = bundleCurlText;

  const liveEndpoint = el('liveApiEndpoint');
  if (liveEndpoint && !liveEndpoint.value.trim()) {
    liveEndpoint.value = topupEndpoint;
  }

  const bundleCreateEndpoint = el('bundleCreateEndpoint');
  if (bundleCreateEndpoint && !bundleCreateEndpoint.value.trim()) {
    bundleCreateEndpoint.value = bundleEndpoint;
  }
}

function renderSummary(){
  const me = state.me || {};
  const data = state.wallet || {};
  const wallet = data.wallet || {};
  const roleSettings = data.role_settings || {};

  if (el('meName')) el('meName').textContent = me.name || data.name || '-';
  if (el('mePhone')) el('mePhone').textContent = me.phone || data.phone || '-';
  if (el('meEmail')) el('meEmail').textContent = me.email || data.email || '-';
  if (el('meRole')) el('meRole').textContent = me.role || data.role || '-';
  if (el('meStatus')) el('meStatus').textContent = me.status || data.status || '-';
  if (el('meLastLogin')) el('meLastLogin').textContent = fmtTs(data.last_login_at || me.last_login_at || 0);

  if (el('availableBalance')) {
    el('availableBalance').textContent = fmtWalletMoney(wallet, 'available');
    el('availableBalance').title = walletRawHint(wallet, 'available');
  }
  if (el('holdBalance')) {
    el('holdBalance').textContent = fmtWalletMoney(wallet, 'hold');
    el('holdBalance').title = walletRawHint(wallet, 'hold');
  }
  if (el('apiKeyCount')) el('apiKeyCount').textContent = String((state.apiKeys || []).length);
  if (el('requestLogCount')) el('requestLogCount').textContent = String((state.requestLogs || []).length);

  if (el('meCommission')) el('meCommission').textContent = money(roleSettings.commission_per_1000 || 0);
  if (el('meApiEnabled')) el('meApiEnabled').textContent = roleSettings.api_enabled ? 'Yes' : 'No';
  if (el('meTopupEnabled')) el('meTopupEnabled').textContent = roleSettings.topup_enabled ? 'Yes' : 'No';
  if (el('meBundleEnabled')) el('meBundleEnabled').textContent = roleSettings.bundle_enabled ? 'Yes' : 'No';

  const minAmount = Number(roleSettings.min_amount || 0);
  const maxAmount = Number(roleSettings.max_amount || 0);
  if (el('meAmountLimits')) el('meAmountLimits').textContent = money(minAmount) + ' - ' + money(maxAmount);

  if (el('meWalletUpdated')) el('meWalletUpdated').textContent = fmtTs(wallet.updated_at || roleSettings.updated_at || 0);

  if (el('sideMeName')) el('sideMeName').textContent = me.name || data.name || '-';
  if (el('sideMeRole')) el('sideMeRole').textContent = me.role || data.role || '-';
  if (el('sideMeStatus')) el('sideMeStatus').textContent = me.status || data.status || '-';

  renderIntegrationGuide();
  renderRequestChart();
}


function renderRequestChart(){
  const rows = state.requestLogs || [];

  const successCount = rows.filter(item => {
    const s = String(item.status || '').toUpperCase();
    return ['SUCCESS', 'COMPLETED', 'APPROVED'].includes(s);
  }).length;

  const failedCount = rows.filter(item => {
    const s = String(item.status || '').toUpperCase();
    return ['FAILED', 'REJECTED', 'CANCELLED', 'CANCELED'].includes(s);
  }).length;

  const pendingCount = rows.filter(item => {
    const s = String(item.status || '').toUpperCase();
    return ['PENDING', 'WAITING_ADMIN', 'WAITING', 'PROCESSING', 'CLAIMED'].includes(s);
  }).length;

  const total = rows.length || 0;

  const pct = (count) => {
    if (!total) return 0;
    return Math.round((count / total) * 100);
  };

  const successPct = pct(successCount);
  const failedPct = pct(failedCount);
  const pendingPct = pct(pendingCount);

  if (el('chartTotalRequests')) el('chartTotalRequests').textContent = `${total} Requests`;

  if (el('chartSuccessCount')) el('chartSuccessCount').textContent = String(successCount);
  if (el('chartFailedCount')) el('chartFailedCount').textContent = String(failedCount);
  if (el('chartPendingCount')) el('chartPendingCount').textContent = String(pendingCount);

  if (el('chartSuccessBar')) el('chartSuccessBar').style.width = successPct + '%';
  if (el('chartFailedBar')) el('chartFailedBar').style.width = failedPct + '%';
  if (el('chartPendingBar')) el('chartPendingBar').style.width = pendingPct + '%';

  if (el('chartSuccessPercent')) el('chartSuccessPercent').textContent = successPct + '%';
  if (el('chartFailedPercent')) el('chartFailedPercent').textContent = failedPct + '%';
  if (el('chartPendingPercent')) el('chartPendingPercent').textContent = pendingPct + '%';
}


function renderKeys(){
  const tbody = el('keysTableBody');
  if (!tbody) return;

  const rows = state.apiKeys || [];

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="6" class="muted">No API keys yet.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(item => `
    <tr>
      <td>
        <div><strong>${esc(item.key_id || '-')}</strong></div>
        <div style="margin-top:8px;">
          <button class="mini-btn" onclick="copyText('${String(item.key_id || '')}','Key ID copied')">Copy Key ID</button>
        </div>
      </td>
      <td><code>${esc(item.key_mask || '-')}</code></td>
      <td>${statusPill(item.status || '-')}</td>
      <td>${fmtTs(item.last_used_at || 0)}</td>
      <td>${fmtTs(item.created_at || 0)}</td>
      <td>
        ${
          String(item.status || '').toUpperCase() === 'ACTIVE'
            ? `<button class="mini-btn" onclick="updateKeyStatus('${String(item.key_id || '')}','DISABLED')">Disable</button>`
            : `<button class="mini-btn blue" onclick="updateKeyStatus('${String(item.key_id || '')}','ACTIVE')">Activate</button>`
        }
      </td>
    </tr>
  `).join('');
}

function renderLogs(){
  const tbody = el('logsTableBody');
  const mobile = el('historyLogsMobileList');
  if (!tbody && !mobile) return;

  let rows = state.requestLogs || [];

  if (state.requestLogFilter !== 'ALL') {
    rows = rows.filter(item => String(item.status || '').toUpperCase() === state.requestLogFilter);
  }

  if (!rows.length) {
    if (tbody) {
      tbody.innerHTML = '<tr><td colspan="9" class="muted">No history logs found for this filter.</td></tr>';
    }
    if (mobile) {
      mobile.innerHTML = '<div class="history-log-empty">No history logs found for this filter.</div>';
    }
    return;
  }

  const details = rows.map(item => {
    const type = String(item.request_type || item.action || '').toUpperCase();
    const isMfs = type === 'MFS';
    const isWallet = type === 'WALLET' || item.is_wallet_history === true;
    const isAddMoney = type === 'ADD_MONEY' || item.is_add_money_history === true;
    const number = isAddMoney ? (item.sender_number || item.transaction_id || '') : (item.topup_number || item.bundle_number || item.receiver_number || item.number || '');
    const service = isWallet
      ? `${item.sender_name || 'Admin'} (${item.sender_role || 'ADMIN'})`
      : isAddMoney
      ? `Add Money - ${item.method || '-'}`
      : (isMfs ? (item.provider_name || mfsProviderName(item.provider || item.service)) : (item.operator || '-'));
    const amountText = isWallet
      ? `${walletPrefix(item.currency)} ${money(item.amount || 0)}`
      : isAddMoney
      ? `${walletPrefix(item.currency)} ${money(item.amount || 0)}`
      : (isMfs ? `${mfsAmountText(item)} / ${mfsFeePayText(item)}` : money(item.amount || 0));
    const typeLabel = isWallet ? 'Balance Received' : (isAddMoney ? 'Add Money' : (type || '-'));
    return { item, isMfs, isWallet, isAddMoney, number, service, amountText, typeLabel };
  });

  if (tbody) {
    tbody.innerHTML = details.map(({ item, isMfs, isWallet, isAddMoney, number, service, amountText, typeLabel }) => `
      <tr>
        <td class="history-log-id">${esc(item.request_id || '-')}</td>
        <td>${esc(item.key_id || '-')}</td>
        <td>${esc(typeLabel)} ${isMfs ? statusPill(item.provider_name || mfsProviderName(item.provider || item.service)) : ''}</td>
        <td>${statusPill(item.status || '-')}</td>
        <td>
          ${esc(service)}
          ${isWallet ? `<br><span class="muted history-log-meta">${esc(item.note || item.message || '-')}<br>${esc(item.reference || '-')}</span>` : ''}
          ${isAddMoney ? `<br><span class="muted history-log-meta">${esc(item.note || item.message || '-')}</span>` : ''}
        </td>
        <td>${esc(number || '-')}</td>
        <td>
          ${esc(amountText)}
          ${isWallet ? `<br><span class="muted history-log-meta">${walletPrefix(item.currency)} ${money(item.before_balance || 0)} to ${walletPrefix(item.currency)} ${money(item.after_balance || 0)}</span>` : ''}
        </td>
        <td>${fmtTs(item.created_at || 0)}</td>
        <td>
          <div class="row-actions">
            <button class="mini-btn blue" onclick="viewRequestLog('${String(item.request_id || '')}')">View</button>
            <button class="mini-btn green" onclick="copyRequestId('${String(item.request_id || '')}')">Copy ID</button>
          </div>
        </td>
      </tr>
    `).join('');
  }

  if (mobile) {
    mobile.innerHTML = details.map(({ item, isWallet, isAddMoney, number, service, amountText, typeLabel }) => `
      <article class="history-log-card ${isWallet ? 'wallet-received' : ''}">
        <div class="history-log-card-top">
          <div>
            <span class="history-log-type">${esc(typeLabel)}</span>
            <strong>${esc(item.request_id || '-')}</strong>
          </div>
          ${statusPill(item.status || '-')}
        </div>
        <div class="history-log-card-grid">
          <span>${isWallet ? 'From' : 'Service'}</span><b>${esc(service)}</b>
          <span>${isWallet ? 'Phone' : (isAddMoney ? 'Txn / Sender' : 'Number')}</span><b>${esc(number || '-')}</b>
          <span>Amount</span><b>${esc(amountText)}</b>
          ${isWallet ? `<span>Balance</span><b>${walletPrefix(item.currency)} ${money(item.before_balance || 0)} to ${walletPrefix(item.currency)} ${money(item.after_balance || 0)}</b>` : ''}
          ${isWallet ? `<span>Note / Ref</span><b>${esc(item.note || item.message || '-')}<br><small>${esc(item.reference || '-')}</small></b>` : ''}
          ${isAddMoney ? `<span>Note</span><b>${esc(item.note || item.message || '-')}</b>` : ''}
          <span>Date</span><b>${fmtTs(item.created_at || 0)}</b>
        </div>
        <div class="row-actions">
          <button class="mini-btn blue" onclick="viewRequestLog('${String(item.request_id || '')}')">View</button>
          <button class="mini-btn green" onclick="copyRequestId('${String(item.request_id || '')}')">Copy ID</button>
        </div>
      </article>
    `).join('');
  }
}

function getUserRowByUidForWallet(uid){
  return (state.users || []).find(item => String(item.uid || '') === String(uid)) || null;
}

function renderUsers(){
  const tbody = el('usersTableBody');
  if (!tbody) return;

  const rows = state.users || [];

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="9" class="muted">No users found.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(item => `
    <tr>
      <td>${esc(item.name || '-')}</td>
      <td>${esc(item.phone || '-')}</td>
      <td>${esc(item.email || '-')}</td>
      <td>${statusPill(item.role || '-')}</td>
      <td>${statusPill(item.status || '-')}</td>
      <td>
        <div><strong>${esc(item.pricing_country || item.market_country || item.country_code || item.country || '-')}</strong></div>
        <div class="muted" style="font-size:11px;">Pricing country (admin managed)</div>
      </td>
      <td>
        <div>${fmtWalletMoney(item, 'available')}</div>
        <div class="muted" style="margin-top:4px;">Hold: ${fmtWalletMoney(item, 'hold')}</div>
        ${walletRawHint(item, 'available') ? `<div class="muted" style="margin-top:4px;">${esc(walletRawHint(item, 'available'))}</div>` : ''}
      </td>
      <td>${fmtMoney(item.min_amount || 0)} - ${fmtMoney(item.max_amount || 0)}</td>
      <td class="users-table-action">
        <div class="users-action-stack">
          <button class="mini-btn green" onclick="openAddBalanceModal('${String(item.uid || '')}')">Add</button>
          <button class="mini-btn blue" onclick="openDeductOtpModal('${String(item.uid || '')}')">Deduct</button>
          <button class="mini-btn" onclick="openWalletLedgerModal('${String(item.uid || '')}')">Ledger</button>
          ${
            item.can_convert_to_retailer
              ? `<button class="mini-btn green" onclick="convertUserToRetailer('${String(item.uid || '')}')">Convert</button>`
              : `<button class="mini-btn" disabled>Convert</button>`
          }
        </div>
      </td>
    </tr>
  `).join('');
}

function resetAddBalanceState(){
  state.addBalance = {
    targetUid: '',
    targetName: '',
    targetPhone: '',
    targetCurrency: 'BDT'
  };

  if (el('addBalanceTargetName')) el('addBalanceTargetName').textContent = '-';
  if (el('addBalanceTargetPhone')) el('addBalanceTargetPhone').textContent = '-';
  if (el('addBalanceTargetBalance')) el('addBalanceTargetBalance').textContent = '0.00';
  if (el('addBalanceTargetRole')) el('addBalanceTargetRole').textContent = '-';
  if (el('addBalanceTargetCurrency')) el('addBalanceTargetCurrency').textContent = 'BDT';
  if (el('addBalanceAmountLabel')) el('addBalanceAmountLabel').textContent = 'Add Amount (BDT)';
  if (el('addBalanceAmountInput')) el('addBalanceAmountInput').value = '';
  if (el('addBalanceNoteInput')) el('addBalanceNoteInput').value = '';

  setBoxMessage('addBalanceStatusBox', 'info', 'Ready', [
    'No balance add request yet.'
  ]);
}

function closeAddBalanceModal(){
  el('addBalanceModalWrap')?.classList.remove('open');
  resetAddBalanceState();
}

function openAddBalanceModal(uid){
  const row = getUserRowByUidForWallet(uid);

  if (!row) {
    showToast('User not found', 'error');
    return;
  }

  resetAddBalanceState();

  state.addBalance.targetUid = String(row.uid || '');
  state.addBalance.targetName = String(row.name || '');
  state.addBalance.targetPhone = String(row.phone || '');
  state.addBalance.targetCurrency = walletNativeCurrency(row);

  if (el('addBalanceTargetName')) el('addBalanceTargetName').textContent = row.name || '-';
  if (el('addBalanceTargetPhone')) el('addBalanceTargetPhone').textContent = row.phone || '-';
  if (el('addBalanceTargetBalance')) el('addBalanceTargetBalance').textContent = fmtWalletMoney(row, 'available');
  if (el('addBalanceTargetRole')) el('addBalanceTargetRole').textContent = row.role || '-';
  if (el('addBalanceTargetCurrency')) {
    el('addBalanceTargetCurrency').textContent = state.addBalance.targetCurrency === 'MYR' ? 'MYR (RM)' : 'BDT';
  }
  if (el('addBalanceAmountLabel')) {
    el('addBalanceAmountLabel').textContent = `Add Amount (${walletPrefix(state.addBalance.targetCurrency)})`;
  }

  el('addBalanceModalWrap')?.classList.add('open');
}

async function submitAddBalance(){
  const uid = state.addBalance.targetUid;
  const amount = Number(el('addBalanceAmountInput')?.value || 0);
  const note = el('addBalanceNoteInput')?.value.trim() || '';

  if (!uid) {
    showToast('Target user missing', 'error');
    return;
  }

  if (amount <= 0) {
    showToast('Enter valid add amount', 'error');
    return;
  }

  try{
    const data = await proxyPost('wallet_add_balance', {
      uid,
      amount,
      note
    }, 'Adding balance...');
    const currency = String(data.currency || data.wallet_currency || state.addBalance.targetCurrency || 'BDT').toUpperCase();
    const prefix = walletPrefix(currency);

    setBoxMessage('addBalanceStatusBox', 'ok', 'Balance Added', [
      `Target: ${data.target_name || state.addBalance.targetName || '-'}`,
      `Phone: ${data.target_phone || state.addBalance.targetPhone || '-'}`,
      `Added: ${prefix} ${money(data.amount || amount)}`,
      `Available Balance: ${prefix} ${money(data.available_balance_after || data.after_available || 0)}`,
      `${data.message || 'Wallet balance updated successfully.'}`
    ]);

    await Promise.all([
      loadWallet(),
      loadLogs(),
      loadUsers()
    ]);

    renderSummary();
    renderLogs();
    renderUsers();
    renderPanelTopupRequests();

    showToast('Balance added successfully', 'ok');

    setTimeout(() => {
      closeAddBalanceModal();
    }, 900);
  }catch(err){
    setBoxMessage('addBalanceStatusBox', 'error', 'Add Balance Failed', [
      err.message || 'Failed to add balance'
    ]);
    showToast(err.message || 'Failed to add balance', 'error');
  }
}

function resetWalletLedgerState(){
  state.walletLedger = {
    targetUid: '',
    targetName: '',
    targetPhone: ''
  };

  if (el('ledgerTargetName')) el('ledgerTargetName').textContent = '-';
  if (el('ledgerTargetPhone')) el('ledgerTargetPhone').textContent = '-';
  if (el('ledgerAvailableBalance')) el('ledgerAvailableBalance').textContent = '0.00';
  if (el('ledgerHoldBalance')) el('ledgerHoldBalance').textContent = '0.00';

  const tbody = el('walletLedgerTableBody');
  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="8" class="muted">No wallet ledger loaded yet.</td></tr>';
  }
}

function closeWalletLedgerModal(){
  el('walletLedgerModalWrap')?.classList.remove('open');
  resetWalletLedgerState();
}

function renderWalletLedgerRows(items){
  const tbody = el('walletLedgerTableBody');
  if (!tbody) return;

  if (!items || !items.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="muted">No wallet ledger found.</td></tr>';
    return;
  }

  tbody.innerHTML = items.map(item => `
    <tr>
      <td>${fmtTs(item.created_at || 0)}</td>
      <td>${esc(item.type || '-')}</td>
      <td>${statusPill(item.direction || '-')}</td>
      <td>${walletPrefix(item.currency)} ${money(item.amount || 0)}</td>
      <td>${walletPrefix(item.currency)} ${money(item.before_available || 0)}</td>
      <td>${walletPrefix(item.currency)} ${money(item.after_available || 0)}</td>
      <td>${esc(item.note || '-')}</td>
      <td>${esc(item.created_by_role || '-')}<br>${esc(item.created_by_uid || '-')}</td>
    </tr>
  `).join('');
}

async function loadWalletLedger(uid){
  const data = await proxyGet('wallet_ledger_list', {
    uid,
    limit: 100
  }, 'Loading wallet ledger...');

  if (el('ledgerTargetName')) el('ledgerTargetName').textContent = data.target_name || '-';
  if (el('ledgerTargetPhone')) el('ledgerTargetPhone').textContent = data.target_phone || '-';
  if (el('ledgerAvailableBalance')) el('ledgerAvailableBalance').textContent = money(data.available_balance || 0);
  if (el('ledgerHoldBalance')) el('ledgerHoldBalance').textContent = money(data.hold_balance || 0);

  renderWalletLedgerRows(data.items || []);
}

async function openWalletLedgerModal(uid){
  const row = getUserRowByUidForWallet(uid);

  if (!row) {
    showToast('User not found', 'error');
    return;
  }

  resetWalletLedgerState();

  state.walletLedger.targetUid = String(row.uid || '');
  state.walletLedger.targetName = String(row.name || '');
  state.walletLedger.targetPhone = String(row.phone || '');

  el('walletLedgerModalWrap')?.classList.add('open');

  try{
    await loadWalletLedger(uid);
  }catch(err){
    showToast(err.message || 'Failed to load wallet ledger', 'error');
  }
}

function closeTransferHistoryModal(){
  el('transferHistoryModalWrap')?.classList.remove('open');
}

function renderTransferHistoryRows(items){
  const tbody = el('transferHistoryTableBody');
  if (!tbody) return;

  if (!Array.isArray(items) || !items.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="muted">No balance transfer found for this month.</td></tr>';
    return;
  }

  tbody.innerHTML = items.map(item => `
    <tr>
      <td>${fmtTs(item.created_at || 0)}</td>
      <td><strong>${esc(item.receiver_name || item.receiver_uid || '-')}</strong><br><span class="muted">${esc(item.receiver_phone || '-')} - ${esc(item.receiver_role || '-')}</span></td>
      <td>${walletPrefix(item.currency)} ${money(item.amount || 0)}</td>
      <td>${walletPrefix(item.currency)} ${money(item.receiver_before_available ?? item.before_available ?? item.before_balance ?? 0)} to ${walletPrefix(item.currency)} ${money(item.receiver_after_available ?? item.after_available ?? item.after_balance ?? 0)}</td>
      <td>${walletPrefix(item.sender_currency || item.currency)} ${money(item.sender_before_available ?? 0)} to ${walletPrefix(item.sender_currency || item.currency)} ${money(item.sender_after_available ?? 0)}</td>
      <td>${esc(item.note || '-')}<br><span class="muted">${esc(item.reference || '-')}</span></td>
      <td>${esc(item.transfer_id || '-')}</td>
    </tr>
  `).join('');
}

async function loadTransferHistory(){
  const month = el('transferHistoryMonth')?.value || new Date().toISOString().slice(0, 7);
  const receiver = el('transferHistoryReceiver')?.value.trim() || '';
  const receiverRole = el('transferHistoryRole')?.value || '';
  const tbody = el('transferHistoryTableBody');

  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="7" class="muted">Loading transfer history...</td></tr>';
  }

  try{
    const data = await proxyGet('wallet_history', {
      month,
      receiver,
      receiver_role: receiverRole,
      limit: 300
    }, 'Loading transfer history...');

    renderTransferHistoryRows(data.items || []);
  }catch(err){
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="7" class="muted">${esc(err.message || 'Failed to load transfer history')}</td></tr>`;
    }
    showToast(err.message || 'Failed to load transfer history', 'error');
  }
}

function openTransferHistoryModal(){
  if (el('transferHistoryMonth') && !el('transferHistoryMonth').value) {
    el('transferHistoryMonth').value = new Date().toISOString().slice(0, 7);
  }

  el('transferHistoryModalWrap')?.classList.add('open');
  loadTransferHistory();
}

function getPanelTopupRows(){
  return (state.requestLogs || [])
    .filter(item =>
      String(item.key_id || '').toUpperCase() === 'PANEL' &&
      String(item.request_type || item.action || '').toUpperCase() === 'TOPUP'
    )
    .sort((a, b) => Number(b.created_at || 0) - Number(a.created_at || 0));
}

function renderPanelTopupRequests(){
  const tbody = el('panelTopupTableBody');
  if (!tbody) return;

  const rows = getPanelTopupRows().slice(0, 10);

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="muted">No panel topup yet.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(item => `
    <tr>
      <td>${esc(item.request_id || '-')}</td>
      <td>${statusPill(item.status || '-')}</td>
      <td>${esc(item.operator || '-')}</td>
      <td>${esc(item.topup_number || '-')}</td>
      <td>${money(item.amount || 0)}</td>
      <td>${fmtTs(item.created_at || 0)}</td>
      <td>
        <div class="row-actions">
          <button class="mini-btn blue" onclick="viewRequestLog('${String(item.request_id || '')}')">View</button>
          <button class="mini-btn green" onclick="copyRequestId('${String(item.request_id || '')}')">Copy ID</button>
        </div>
      </td>
    </tr>
  `).join('');
}

function mfsProviderName(value){
  const provider = String(value || '').toUpperCase();
  if (provider === 'BKASH') return 'bKash';
  if (provider === 'NAGAD') return 'Nagad';
  return value || '-';
}

function mfsCountryName(value){
  const country = String(value || '').toUpperCase();
  if (country === 'MY') return 'Malaysia';
  if (country === 'BD') return 'Bangladesh';
  return value || '-';
}

function mfsModeName(value){
  const mode = String(value || '').toUpperCase();
  if (mode === 'REMITTANCE') return 'Remittance';
  if (mode === 'LOCAL') return 'Local';
  return value || '-';
}

function mfsStatusLabel(value){
  const status = String(value || '').toUpperCase();
  if (status === 'SUCCESSFUL' || status === 'SUCCESS') return 'Done';
  if (status === 'FAILED') return 'Failed';
  if (status === 'PROCESSING') return 'Processing';
  return 'Pending';
}

function mfsRowId(row){
  return String(row?.request_id || row?.id || '');
}

function mfsRowNumber(row){
  return String(row?.receiver_number || row?.number || '-');
}

function mfsRowAmount(row){
  return Number(row?.amount_bdt ?? row?.amount ?? 0);
}

function mfsRate(row){
  return Number(row?.exchange_rate ?? row?.rate_myr_to_bdt ?? row?.rate_myr_bdt ?? 0);
}

function mfsAmountRm(row){
  let value = Number(row?.amount_rm ?? row?.amount_myr ?? 0);
  const rate = mfsRate(row);
  if (value <= 0 && rate > 0 && Number(row?.amount_bdt || 0) > 0) {
    value = Number(row.amount_bdt || 0) / rate;
  }
  return value;
}

function mfsRowFee(row){
  if (mfsIsRemittance(row)) {
    let value = Number(row?.fee_rm ?? row?.fee_myr ?? 0);
    const rate = mfsRate(row);
    if (value <= 0 && String(row?.fee_currency || '').toUpperCase() === 'MYR') value = Number(row?.fee_amount || 0);
    if (value <= 0 && rate > 0 && Number(row?.fee_bdt || 0) > 0) value = Number(row.fee_bdt || 0) / rate;
    return value;
  }

  return Number(row?.fee_bdt ?? row?.fee ?? 0);
}

function mfsRowPay(row){
  if (mfsIsRemittance(row)) {
    let value = Number(row?.total_debit_rm ?? row?.total_pay_myr ?? 0);
    const rate = mfsRate(row);
    if (value <= 0) value = mfsAmountRm(row) + mfsRowFee(row);
    if (value <= 0 && String(row?.wallet_currency || '').toUpperCase() === 'MYR') value = Number(row?.total_debit ?? row?.total_pay ?? 0);
    if (value <= 0 && rate > 0 && Number(row?.total_debit ?? row?.total_pay ?? 0) > 0) value = Number(row?.total_debit ?? row?.total_pay ?? 0) / rate;
    return value;
  }

  let value = Number(row?.total_debit_bdt ?? row?.total_hold_bdt ?? row?.total_pay_bdt ?? 0);
  if (value <= 0) value = Number(row?.total_debit ?? row?.total_pay ?? row?.amount ?? 0);
  if (value <= 0) value = mfsRowAmount(row) + mfsRowFee(row);
  return value;
}

function mfsMoney(row, amount){
  return `${mfsIsRemittance(row) ? 'RM' : 'BDT'} ${money(amount)}`;
}

function mfsIsRemittance(row){
  return String(row?.service_mode || '').toUpperCase() === 'REMITTANCE'
    || String(row?.country_code || row?.country || '').toUpperCase() === 'MY'
    || Number(row?.amount_rm ?? row?.amount_myr ?? 0) > 0;
}

function mfsAmountText(row){
  if (mfsIsRemittance(row)) {
    return `Received: BDT ${money(row?.amount_bdt || 0)} / Send: RM ${money(mfsAmountRm(row))}`;
  }

  return `Amount: ${fmtMoney(mfsRowAmount(row))}`;
}

function mfsFeePayText(row){
  if (mfsIsRemittance(row)) {
    return `Fee: RM ${money(mfsRowFee(row))} / Total Paid: RM ${money(mfsRowPay(row))}`;
  }

  return `Fee: ${mfsMoney(row, mfsRowFee(row))} / Total Paid: ${mfsMoney(row, mfsRowPay(row))}`;
}

function mfsReference(row){
  return String(row?.reference || '-');
}

function mfsWalletMeta(){
  const wallet = state.wallet?.wallet || {};
  const currency = String(wallet.display_currency || wallet.wallet_currency || wallet.currency || state.wallet?.wallet_currency || '').toUpperCase();
  const country = String(state.wallet?.country_code || wallet.country_code || '').toUpperCase();
  const rate = Number(wallet.rate_myr_bdt || wallet.rate_myr_to_bdt || state.wallet?.rate_myr_bdt || 0);

  return {
    currency,
    country,
    rate: Number.isFinite(rate) ? rate : 0,
    isMyr: currency === 'MYR' || country === 'MY'
  };
}

function updateSubMfsCurrencyUi(){
  const meta = mfsWalletMeta();
  const field = el('subMfsAmountRmField') || el('subMfsAmountRm')?.closest('.field');

  if (field) field.classList.toggle('hidden', !meta.isMyr);
  if (!meta.isMyr && el('subMfsAmountRm')) el('subMfsAmountRm').value = '';

  return meta;
}

function syncSubMfsAmounts(source){
  const meta = updateSubMfsCurrencyUi();
  if (!meta.isMyr || meta.rate <= 0 || state.mfsAmountSyncing) return;

  const bdt = el('subMfsAmountBdt');
  const rm = el('subMfsAmountRm');
  if (!bdt || !rm) return;

  state.mfsAmountSyncing = true;

  try{
    if (source === 'bdt') {
      const value = Number(bdt.value || 0);
      rm.value = value > 0 ? money(value / meta.rate) : '';
    } else if (source === 'rm') {
      const value = Number(rm.value || 0);
      bdt.value = value > 0 ? money(value * meta.rate) : '';
    }
  } finally {
    state.mfsAmountSyncing = false;
  }
}

function mfsTrackingUrl(row){
  return String(row?.tracking_url || row?.receipt_url || row?.request_url || '');
}

function ensureSubMfsResultModal(){
  if (el('subMfsResultModalWrap')) return;

  const wrap = document.createElement('div');
  wrap.id = 'subMfsResultModalWrap';
  wrap.className = 'modal-wrap';
  wrap.innerHTML = `
    <div class="modal-card modal-card-sm">
      <div class="modal-head">
        <div>
          <h3 id="subMfsResultModalTitle">MFS Request</h3>
          <p id="subMfsResultModalSub">Request details</p>
        </div>
        <button id="subMfsResultModalCloseBtn" class="modal-close" type="button">Close</button>
      </div>
      <div id="subMfsResultModalBody" class="status-box-clean"></div>
      <div class="actions mt-14" id="subMfsResultModalActions">
        <button id="subMfsResultCopyBtn" class="btn blue" type="button">Copy Link</button>
        <button id="subMfsResultOpenBtn" class="btn green" type="button">Open Receipt</button>
        <button id="subMfsResultOkBtn" class="btn ghost" type="button">OK</button>
      </div>
    </div>
  `;

  document.body.appendChild(wrap);

  const close = () => wrap.classList.remove('open');
  el('subMfsResultModalCloseBtn')?.addEventListener('click', close);
  el('subMfsResultOkBtn')?.addEventListener('click', close);
  wrap.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'subMfsResultModalWrap') close();
  });
}

function showSubMfsResultModal({title = 'MFS Request', subtitle = '', rows = [], link = '', type = 'info'} = {}){
  ensureSubMfsResultModal();

  const wrap = el('subMfsResultModalWrap');
  const titleNode = el('subMfsResultModalTitle');
  const subNode = el('subMfsResultModalSub');
  const body = el('subMfsResultModalBody');
  const copyBtn = el('subMfsResultCopyBtn');
  const openBtn = el('subMfsResultOpenBtn');

  if (titleNode) titleNode.textContent = title;
  if (subNode) subNode.textContent = subtitle || '';

  const pillType = type === 'success' ? 'SUCCESS' : type === 'error' ? 'FAILED' : 'PENDING';
  const rowHtml = rows
    .filter(row => Array.isArray(row) && row.length >= 2)
    .map(row => `
      <div class="mfs-review-item">
        <span class="mfs-review-label">${esc(row[0])}</span>
        <strong class="mfs-review-value">${esc(row[1] || '-')}</strong>
      </div>
    `).join('');

  if (body) {
    body.innerHTML = `
      <div style="width:100%">
        <div style="margin-bottom:10px;">${statusPill(pillType)}</div>
        <div class="mfs-review-grid">${rowHtml || '<div class="muted">No details available.</div>'}</div>
        ${link ? `<div class="mfs-review-link"><span class="mfs-review-label">Receipt / Tracking Link</span><strong class="mfs-review-value">${esc(link)}</strong></div>` : ''}
      </div>
    `;
  }

  if (copyBtn) {
    copyBtn.classList.toggle('hidden', !link);
    copyBtn.onclick = () => copyText(link, 'Receipt link copied');
  }

  if (openBtn) {
    openBtn.classList.toggle('hidden', !link);
    openBtn.onclick = () => window.open(link, '_blank', 'noopener');
  }

  wrap?.classList.add('open');
}

function showSubMfsCreateSuccessModal(row){
  const link = mfsTrackingUrl(row);
  showSubMfsResultModal({
    title: 'Request Created Successfully',
    subtitle: 'Your send money request has been submitted securely.',
    type: 'success',
    link,
    rows: [
      ['Request ID', row.request_id || '-'],
      ['Provider', row.provider_name || mfsProviderName(row.provider)],
      ['Receiver Number', mfsRowNumber(row)],
      ['Amount BDT', `BDT ${money(row.amount_bdt || 0)}`],
      ...(mfsIsRemittance(row) ? [['Amount RM', `RM ${money(mfsAmountRm(row))}`]] : []),
      ['Fee', mfsMoney(row, mfsRowFee(row))],
      ['Total Pay/Hold', mfsMoney(row, mfsRowPay(row))],
      ['Status', row.status || 'PENDING']
    ]
  });
}

function subMfsWalletMoney(currency, amount){
  const prefix = mfsCurrencyPrefix(currency);
  return `${prefix} ${money(amount)}`;
}

function mfsNormalizeCurrency(value){
  const currency = String(value || '').toUpperCase().trim();
  if (['MYR', 'RM', 'MY'].includes(currency)) return 'MYR';
  if (['BDT', 'BD', 'TK'].includes(currency)) return 'BDT';
  return '';
}

function mfsCurrencyPrefix(currency){
  return mfsNormalizeCurrency(currency) === 'MYR' ? 'RM' : 'BDT';
}

function mfsReviewCurrency(data, remittance){
  const meta = mfsWalletMeta();
  const candidates = [
    data?.display_currency,
    data?.wallet_currency,
    data?.currency,
    data?.wallet?.display_currency,
    data?.wallet?.wallet_currency,
    data?.wallet?.currency,
    meta.currency
  ];

  for (const value of candidates) {
    const normalized = mfsNormalizeCurrency(value);
    if (normalized) return normalized;
  }

  const country = String(data?.country_code || data?.country || meta.country || '').toUpperCase();
  if (country === 'MY' || remittance) return 'MYR';
  return 'BDT';
}

function mfsFirstNumber(...values){
  for (const value of values) {
    if (value === undefined || value === null || value === '') continue;
    const n = Number(value);
    if (Number.isFinite(n)) return n;
  }
  return NaN;
}

function mfsReviewAvailable(data, currency){
  const wallet = state.wallet?.wallet || {};
  if (currency === 'MYR') {
    return mfsFirstNumber(
      data?.display_currency === 'MYR' ? data?.display_available_balance : undefined,
      data?.available_balance_myr,
      data?.wallet?.display_currency === 'MYR' ? data?.wallet?.display_available_balance : undefined,
      data?.wallet?.available_balance_myr,
      wallet.display_currency === 'MYR' ? wallet.display_available_balance : undefined,
      wallet.available_balance_myr,
      data?.available_balance,
      data?.wallet_balance
    );
  }

  return mfsFirstNumber(
    data?.display_currency === 'BDT' ? data?.display_available_balance : undefined,
    data?.available_balance_bdt,
    data?.wallet?.display_currency === 'BDT' ? data?.wallet?.display_available_balance : undefined,
    data?.wallet?.available_balance_bdt,
    wallet.display_currency === 'BDT' ? wallet.display_available_balance : undefined,
    wallet.available_balance_bdt,
    data?.available_balance,
    data?.wallet_balance
  );
}

function mfsReviewDebit(data, currency){
  if (currency === 'MYR') {
    return mfsFirstNumber(
      data?.display_currency === 'MYR' ? data?.display_total_pay : undefined,
      data?.total_pay_myr,
      data?.total_debit_rm,
      data?.wallet_currency === 'MYR' ? data?.wallet_hold_amount : undefined,
      data?.wallet_currency === 'MYR' ? data?.total_pay : undefined,
      data?.wallet_currency === 'MYR' ? data?.total_debit : undefined
    );
  }

  return mfsFirstNumber(
    data?.display_currency === 'BDT' ? data?.display_total_pay : undefined,
    data?.total_pay_bdt,
    data?.total_debit_bdt,
    data?.wallet_currency === 'BDT' ? data?.wallet_hold_amount : undefined,
    data?.wallet_currency === 'BDT' ? data?.total_pay : undefined,
    data?.wallet_currency === 'BDT' ? data?.total_debit : undefined
  );
}

function subMfsReviewRows(data, payload){
  const remittance = mfsIsRemittance(data);
  const walletCurrency = mfsReviewCurrency(data, remittance);
  const rate = Number(data.exchange_rate ?? data.rate_myr_to_bdt ?? 0);
  const available = mfsReviewAvailable(data, walletCurrency);
  const hold = mfsReviewDebit(data, walletCurrency);
  const afterFromResponse = mfsFirstNumber(data?.display_currency === walletCurrency ? data?.display_balance_after : undefined);
  const after = Number.isFinite(afterFromResponse) ? afterFromResponse : (Number.isFinite(available) && Number.isFinite(hold) ? available - hold : NaN);

  return [
    {label: 'Provider', value: data.provider_name || mfsProviderName(payload.provider), className: 'mfs-review-highlight'},
    {label: 'Receiver Number', value: data.receiver_number || payload.receiver_number || '-'},
    {label: 'Country', value: mfsCountryName(data.country_code || data.country)},
    {label: 'Mode', value: mfsModeName(data.service_mode)},
    ...(remittance && rate > 0 ? [{label: 'Rate', value: `RM 1 = BDT ${money(rate)}`}] : []),
    {label: remittance ? 'Received Amount' : 'Amount', value: `BDT ${money(data.amount_bdt ?? payload.amount_bdt ?? 0)}`},
    ...(remittance ? [{label: 'Send Amount', value: `RM ${money(data.amount_rm ?? data.amount_myr ?? payload.amount_rm ?? 0)}`}] : []),
    {label: 'Fee', value: mfsMoney(data, mfsRowFee(data))},
    {label: 'Total Pay', value: mfsMoney(data, mfsRowPay(data)), className: 'mfs-review-total'},
    ...(Number.isFinite(available) ? [{label: 'Available Balance', value: subMfsWalletMoney(walletCurrency, available), className: 'mfs-review-highlight'}] : []),
    ...(Number.isFinite(after) ? [{label: 'Balance After', value: subMfsWalletMoney(walletCurrency, after), className: 'mfs-review-highlight'}] : []),
    {label: 'Reference', value: payload.reference || '-', className: 'mfs-review-wide'}
  ];
}

function ensureSubMfsReviewModal(){
  if (el('subMfsReviewModalWrap')) return;

  const wrap = document.createElement('div');
  wrap.id = 'subMfsReviewModalWrap';
  wrap.className = 'modal-wrap';
  wrap.innerHTML = `
    <div class="modal-card modal-card-sm mfs-review-modal-card">
      <div class="modal-head">
        <div>
          <h3>Confirm Send Money</h3>
          <p>Please review the details before confirming.</p>
        </div>
        <button id="subMfsReviewCloseBtn" class="modal-close" type="button">Close</button>
      </div>
      <div id="subMfsReviewBody" class="mfs-review-grid"></div>
      <div class="actions mt-14">
        <button id="subMfsReviewBackBtn" class="btn ghost" type="button">Back / Edit</button>
        <button id="subMfsReviewConfirmBtn" class="btn green" type="button">Confirm Send Money</button>
      </div>
    </div>
  `;

  document.body.appendChild(wrap);

  const close = () => {
    state.mfsCreateReview = null;
    wrap.classList.remove('open');
  };

  el('subMfsReviewCloseBtn')?.addEventListener('click', close);
  el('subMfsReviewBackBtn')?.addEventListener('click', close);
  el('subMfsReviewConfirmBtn')?.addEventListener('click', (e) => confirmMfsReview(e.currentTarget));
  wrap.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'subMfsReviewModalWrap') close();
  });
}

function showSubMfsReviewModal(data, payload){
  ensureSubMfsReviewModal();
  state.mfsCreateReview = {data, payload};

  const rows = subMfsReviewRows(data, payload);
  const body = el('subMfsReviewBody');
  if (body) {
    body.innerHTML = rows.map(row => `
      <div class="mfs-review-item ${esc(row.className || '')}">
        <span class="mfs-review-label">${esc(row.label)}</span>
        <strong class="mfs-review-value">${esc(row.value || '-')}</strong>
      </div>
    `).join('');
  }

  el('subMfsReviewModalWrap')?.classList.add('open');
  el('subMfsReviewConfirmBtn')?.focus();
}

function mfsDetailsBoxId(){
  if (getCurrentSectionId() === 'mfsRequestsSection' && el('subMfsDetailsOutput')) {
    return 'subMfsDetailsOutput';
  }

  return el('subMfsOutput') ? 'subMfsOutput' : 'subMfsDetailsOutput';
}

function getSubMfsFilters(){
  return {
    search: el('subMfsSearch')?.value.trim() || '',
    number: el('subMfsNumberFilter')?.value.trim() || '',
    service: el('subMfsProviderFilter')?.value.trim() || ''
  };
}

function normalizeSubMfsRows(rows){
  return Array.isArray(rows) ? rows : [];
}

function renderMfsSummary(){
  const summary = state.mfs.summary || {};
  if (el('subMfsSummaryPending')) el('subMfsSummaryPending').textContent = Number(summary.pending || 0);
  if (el('subMfsSummaryProcessing')) el('subMfsSummaryProcessing').textContent = Number(summary.processing || 0);
  if (el('subMfsSummaryDone')) el('subMfsSummaryDone').textContent = Number(summary.done || 0);
  if (el('subMfsSummaryFailed')) el('subMfsSummaryFailed').textContent = Number(summary.failed || 0);
}

function setSubMfsTab(tab){
  const safeTab = ['pending', 'processing', 'done', 'failed'].includes(tab) ? tab : 'pending';
  state.mfs.tab = safeTab;

  document.querySelectorAll('.sub-mfs-tab').forEach(btn => {
    const active = btn.dataset.mfsTab === safeTab;
    btn.classList.toggle('active', active);
    btn.classList.toggle('green', active);
    btn.classList.toggle('ghost', !active);
  });
}

function renderMfsLoading(){
  const tbody = el('subMfsTableBody');
  const mobile = el('subMfsMobileList');

  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="9" class="muted">Loading MFS requests...</td></tr>';
  }

  if (mobile) {
    mobile.innerHTML = '<div class="sub-mfs-empty">Loading MFS requests...</div>';
  }
}

function renderMfsRows(){
  const tbody = el('subMfsTableBody');
  const mobile = el('subMfsMobileList');
  const rows = normalizeSubMfsRows(state.mfs.rows);

  if (tbody) {
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="9" class="muted">No MFS request found for this tab.</td></tr>';
    } else {
      tbody.innerHTML = rows.map(row => {
        const requestId = mfsRowId(row);
        const provider = row.provider_name || mfsProviderName(row.provider || row.service);
        return `
          <tr>
            <td>${esc(requestId || '-')}</td>
            <td>${esc(provider)}</td>
            <td>${esc(mfsRowNumber(row))}</td>
            <td>${esc(mfsAmountText(row))}</td>
            <td>${esc(mfsFeePayText(row))}</td>
            <td>${statusPill(mfsStatusLabel(row.status))}</td>
            <td>${fmtTs(row.created_at || 0)}</td>
            <td>${esc(mfsReference(row))}</td>
            <td>
              <div class="row-actions">
                <button class="mini-btn blue sub-mfs-view-btn" type="button" data-mfs-request="${esc(requestId)}">View</button>
                ${row.receipt_url ? `<button class="mini-btn green sub-mfs-receipt-btn" type="button" data-receipt-url="${esc(row.receipt_url)}">Receipt</button>` : ''}
              </div>
            </td>
          </tr>
        `;
      }).join('');
    }
  }

  if (!mobile) return;

  if (!rows.length) {
    mobile.innerHTML = '<div class="sub-mfs-empty">No MFS request found for this tab.</div>';
    return;
  }

  mobile.innerHTML = rows.map(row => {
    const requestId = mfsRowId(row);
    const provider = row.provider_name || mfsProviderName(row.provider || row.service);
    return `
      <div class="sub-mfs-card">
        <div class="sub-mfs-card-top">
          <strong>${esc(requestId || '-')}</strong>
          ${statusPill(mfsStatusLabel(row.status))}
        </div>
        <div class="sub-mfs-card-grid">
          <span>Provider</span><b>${esc(provider)}</b>
          <span>Receiver</span><b>${esc(mfsRowNumber(row))}</b>
          <span>Amount</span><b>${esc(mfsAmountText(row))}</b>
          <span>Fee / Pay</span><b>${esc(mfsFeePayText(row))}</b>
          <span>Reference</span><b>${esc(mfsReference(row))}</b>
          <span>Created</span><b>${fmtTs(row.created_at || 0)}</b>
        </div>
        <button class="mini-btn blue sub-mfs-view-btn" type="button" data-mfs-request="${esc(requestId)}">View Request</button>
        ${row.receipt_url ? `<button class="mini-btn green sub-mfs-receipt-btn" type="button" data-receipt-url="${esc(row.receipt_url)}">View Receipt</button>` : ''}
      </div>
    `;
  }).join('');
}

async function loadMfsSummary(){
  const data = await proxyGet('mfs_summary', {}, 'Loading MFS summary...');
  state.mfs.summary = data.summary || {
    pending: 0,
    processing: 0,
    done: 0,
    failed: 0
  };
  renderMfsSummary();
}

async function loadMfsList(){
  renderMfsLoading();

  const filters = getSubMfsFilters();
  const data = await proxyGet('mfs_list', {
    tab: state.mfs.tab || 'pending',
    page: 1,
    limit: 50,
    search: filters.search,
    number: filters.number,
    service: filters.service
  }, 'Loading MFS requests...');

  state.mfs.rows = normalizeSubMfsRows(data.items || []);
  renderMfsRows();
}

async function loadMfsSummaryPanel(force = false){
  if (!force && state.loaded.mfsSummary) {
    renderMfsSummary();
    return;
  }

  await loadMfsSummary();
  state.loaded.mfsSummary = true;
  state.loaded.mfs = state.loaded.mfsSummary && state.loaded.mfsList;
}

async function loadMfsRequestsPanel(force = false){
  if (!force && state.loaded.mfsList) {
    renderMfsRows();
    return;
  }

  await loadMfsList();
  state.loaded.mfsList = true;
  state.loaded.mfs = state.loaded.mfsSummary && state.loaded.mfsList;
}

async function loadMfsPanel(force = false){
  if (!force && state.loaded.mfs) {
    renderMfsSummary();
    renderMfsRows();
    return;
  }

  await Promise.all([
    loadMfsSummaryPanel(force),
    loadMfsRequestsPanel(force)
  ]);

  state.loaded.mfs = true;
  state.mfs.loaded = true;
}

async function ensureMfsSummaryLoaded(force = false){
  await loadMfsSummaryPanel(force);
}

async function ensureMfsListLoaded(force = false){
  await loadMfsRequestsPanel(force);
}

async function ensureMfsLoaded(force = false){
  await loadMfsPanel(force);
}

function clearMfsForm(){
  if (el('subMfsProvider')) el('subMfsProvider').value = 'BKASH';
  if (el('subMfsReceiver')) el('subMfsReceiver').value = '';
  if (el('subMfsAmountBdt')) el('subMfsAmountBdt').value = '';
  if (el('subMfsAmountRm')) el('subMfsAmountRm').value = '';
  if (el('subMfsPin')) el('subMfsPin').value = '';
  if (el('subMfsReference')) el('subMfsReference').value = '';
  if (el('subMfsNote')) el('subMfsNote').value = '';
  updateSubMfsCurrencyUi();
}

function clearMfsCreateFieldsAfterSuccess(){
  if (el('subMfsReceiver')) el('subMfsReceiver').value = '';
  if (el('subMfsAmountBdt')) el('subMfsAmountBdt').value = '';
  if (el('subMfsAmountRm')) el('subMfsAmountRm').value = '';
  if (el('subMfsPin')) el('subMfsPin').value = '';
  if (el('subMfsReference')) el('subMfsReference').value = '';
  if (el('subMfsNote')) el('subMfsNote').value = '';
  const out = el('subMfsOutput');
  if (out) out.textContent = 'No MFS request created yet.';
  updateSubMfsCurrencyUi();
}

function validateMfsForm(){
  const payload = buildMfsPreviewPayload();
  const pin = el('subMfsPin')?.value || '';

  if (!pin.trim()) {
    throw new Error('Transaction PIN is required');
  }

  return {
    ...payload,
    pin,
    note: el('subMfsNote')?.value.trim() || ''
  };
}

function buildMfsPreviewPayload(){
  const provider = el('subMfsProvider')?.value.trim() || '';
  const receiver = el('subMfsReceiver')?.value.trim() || '';
  const meta = updateSubMfsCurrencyUi();
  const amount = Number(el('subMfsAmountBdt')?.value || 0);
  const amountRm = meta.isMyr ? Number(el('subMfsAmountRm')?.value || 0) : 0;

  if (!['BKASH', 'NAGAD'].includes(provider)) {
    throw new Error('Provider must be bKash or Nagad');
  }

  if (!receiver) {
    throw new Error('Receiver number is required');
  }

  if ((!amount || Number.isNaN(amount)) && (!amountRm || Number.isNaN(amountRm))) {
    throw new Error('Amount BDT or Amount RM is required');
  }

  if (amount > 0 && (amount < 500 || amount > 50000)) {
    throw new Error('Amount BDT must be between BDT 500 and BDT 50,000');
  }

  return {
    provider,
    receiver_number: receiver,
    amount_bdt: amount,
    amount_rm: amountRm,
    service_type: 'SEND_MONEY',
    account_type: 'PERSONAL',
    reference: el('subMfsReference')?.value.trim() || ''
  };
}

async function openMfsReview(button = null){
  return withButtonLoading(button || el('subMfsCreateBtn'), 'Checking...', async () => {
    let payload;

    try{
      payload = validateMfsForm();
    }catch(err){
      showToast(err.message || 'Validation error', 'error');
      showSubMfsResultModal({
        title: 'Validation Error',
        subtitle: err.message || 'Please check the MFS form.',
        type: 'error'
      });
      return;
    }

    try{
      const data = await proxyPost('mfs_preview', payload, 'Loading MFS preview...');
      showSubMfsReviewModal(data, payload);
    }catch(err){
      showSubMfsResultModal({
        title: 'Preview Failed',
        subtitle: err.message || 'Failed to preview MFS request',
        type: 'error'
      });
      showToast(err.message || 'Failed to preview MFS request', 'error');
    }
  });
}

async function previewMfsRequest(button = null){
  return openMfsReview(button);
}

async function createMfsRequest(button = null, payloadOverride = null){
  return withButtonLoading(button || el('subMfsCreateBtn'), payloadOverride ? 'Submitting...' : 'Submitting...', async () => {
    let payload;

    try{
      payload = payloadOverride || validateMfsForm();
    }catch(err){
      showToast(err.message || 'Validation error', 'error');
      showSubMfsResultModal({
        title: 'Validation Error',
        subtitle: err.message || 'Please check the MFS form.',
        type: 'error'
      });
      return;
    }

    try{
      const data = await proxyPost('mfs_create', payload, 'Creating MFS request...');
      const row = data.request || data.item || data || {};

      state.mfsCreateReview = null;
      el('subMfsReviewModalWrap')?.classList.remove('open');
      showSubMfsCreateSuccessModal(row);
      clearMfsCreateFieldsAfterSuccess();

      const refreshJobs = [
        loadWallet(),
        loadMfsSummaryPanel(true),
        loadLogs()
      ];

      if (state.loaded.mfsList || getCurrentSectionId() === 'mfsRequestsSection') {
        refreshJobs.push(loadMfsRequestsPanel(true));
      }

      await Promise.all(refreshJobs);
      state.loaded.mfs = state.loaded.mfsSummary && state.loaded.mfsList;

      renderSummary();
      renderLogs();
      renderPanelTopupRequests();
      showToast('MFS request created successfully', 'ok');
    }catch(err){
      showSubMfsResultModal({
        title: 'Create Failed',
        subtitle: err.message || 'Failed to create MFS request',
        type: 'error'
      });
      showToast(err.message || 'Failed to create MFS request', 'error');
    }
  });
}

async function confirmMfsReview(button = null){
  const review = state.mfsCreateReview;
  if (!review || !review.payload) {
    showSubMfsResultModal({
      title: 'Review Required',
      subtitle: 'Please review the MFS request again before confirming.',
      type: 'error'
    });
    return;
  }

  await createMfsRequest(button || el('subMfsReviewConfirmBtn'), review.payload);
}

async function viewMfsRequest(requestId, button = null){
  requestId = String(requestId || '').trim();
  if (!requestId) {
    showToast('Request ID missing', 'error');
    return;
  }

  return withButtonLoading(button, 'Loading...', async () => {
    try{
      const data = await proxyGet('mfs_get', { request_id: requestId }, 'Loading MFS request...');
      const row = data.item || {};

      setDetailBox(mfsDetailsBoxId(), 'MFS Request Details', [
        ['Request ID', row.request_id || requestId],
        ['Provider', row.provider_name || mfsProviderName(row.provider)],
        ['Country', row.country_code || '-'],
        ['Mode', row.service_mode || '-'],
        ['Type', row.service_name || row.service_type || 'SEND_MONEY'],
        ['Receiver', mfsRowNumber(row)],
        ['Amount', mfsAmountText(row)],
        ['Fee', mfsMoney(row, mfsRowFee(row))],
        ['Total Paid', mfsMoney(row, mfsRowPay(row))],
        ['Rate', row.exchange_rate ? `RM 1 = BDT ${money(row.exchange_rate)}` : '-'],
        ['Reference', row.reference || '-'],
        ['Status', row.status || '-'],
        ['Sender Last Digit', row.sender_last_digit || row.sender_details || '-'],
        ['Receipt', row.receipt_url || '-'],
        ['Message', row.message || '-'],
        ['Created', fmtTs(row.created_at || 0)],
        ['Updated', fmtTs(row.updated_at || row.created_at || 0)]
      ]);

      showToast('MFS request loaded', 'info');
    }catch(err){
      showToast(err.message || 'Failed to load MFS request', 'error');
    }
  });
}

async function refreshMfsPanelFromButton(button, force = true){
  return withButtonLoading(button, 'Loading...', async () => {
    await loadMfsPanel(force);
    showToast('MFS requests refreshed', 'info');
  });
}

async function refreshMfsSummaryFromButton(button, force = true){
  return withButtonLoading(button, 'Loading...', async () => {
    await loadMfsSummaryPanel(force);
    showToast('MFS summary refreshed', 'info');
  });
}

async function refreshMfsRequestsFromButton(button, force = true){
  return withButtonLoading(button, 'Loading...', async () => {
    await loadMfsRequestsPanel(force);
    showToast('MFS requests refreshed', 'info');
  });
}

/* =========================
   Bundle panel
========================= */

function injectBundlePanelStyle(){
  const oldStyle = document.getElementById('bundlePanelInlineStyle');
  if (oldStyle) oldStyle.remove();

  const style = document.createElement('style');
  style.id = 'bundlePanelInlineStyle';
  style.textContent = `
    .bundle-offer-grid{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
      gap:16px;
      margin-top:16px;
      align-items:start;
    }

    .bundle-offer-card{
      background:linear-gradient(180deg,rgba(18,40,77,.98),rgba(11,31,67,.98));
      border:1px solid rgba(110,149,221,.20);
      border-radius:22px;
      padding:16px;
      box-shadow:0 14px 34px rgba(0,0,0,.20);
      overflow:hidden;
      min-width:0;
    }

    .bundle-offer-top{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:12px;
      margin-bottom:12px;
      min-width:0;
    }

    .bundle-offer-name{
      font-size:18px;
      font-weight:900;
      line-height:1.25;
      color:#ecf4ff;
      word-break:break-word;
    }

    .bundle-offer-id{
      margin-top:5px;
      color:#9fb5d8;
      font-size:12px;
      line-height:1.35;
      word-break:break-word;
    }

    .bundle-offer-desc{
      color:#b8c9e3;
      font-size:13px;
      line-height:1.5;
      min-height:20px;
      margin-bottom:12px;
    }

    .bundle-price-row{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
      margin:12px 0;
    }

    .bundle-price-main{
      background:rgba(255,255,255,.035);
      border:1px solid rgba(255,255,255,.07);
      border-radius:16px;
      padding:12px;
      min-width:0;
    }

    .bundle-price-main span,
    .bundle-mini-grid span,
    .bundle-expiry{
      display:block;
      color:#9fb5d8;
      font-size:12px;
      line-height:1.4;
    }

    .bundle-price-main strong{
      display:block;
      margin-top:5px;
      font-size:17px;
      line-height:1.25;
      color:#ecf4ff;
      word-break:break-word;
    }

    .bundle-mini-grid{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:8px;
      margin:12px 0;
    }

    .bundle-mini-grid > div{
      background:rgba(255,255,255,.025);
      border:1px solid rgba(255,255,255,.06);
      border-radius:14px;
      padding:10px;
      min-width:0;
    }

    .bundle-mini-grid strong{
      display:block;
      margin-top:4px;
      font-size:13px;
      color:#ecf4ff;
      word-break:break-word;
    }

    .bundle-offer-footer{
      display:flex;
      flex-direction:column;
      align-items:stretch;
      gap:12px;
      margin-top:14px;
      clear:both;
    }

    .bundle-expiry{
    width:100%;
    min-width:0;
    display:flex;
    flex-direction:column;
    align-items:flex-start;
    gap:4px;
    }

    .bundle-offer-actions{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
      width:100%;
    }

    .bundle-offer-actions .btn{
      width:100%;
      min-width:0;
      white-space:nowrap;
      justify-content:center;
      text-align:center;
      padding-left:10px;
      padding-right:10px;
    }

    .bundle-buy-btn,
    .bundle-commission-btn{
      min-width:0;
      white-space:nowrap;
    }

    .bundle-profit-tag{
      display:inline-flex;
      align-items:center;
      min-height:28px;
      padding:0 10px;
      border-radius:999px;
      background:rgba(31,215,96,.12);
      border:1px solid rgba(31,215,96,.24);
      color:#b9ffd0;
      font-size:12px;
      font-weight:900;
      margin-top:8px;
      width:max-content;
      max-width:100%;
    }

    .bundle-custom-tag{
  display:flex;
  align-items:center;
  justify-content:center;
  min-height:26px;
  padding:0 10px;
  border-radius:999px;
  background:rgba(255,191,31,.13);
  border:1px solid rgba(255,191,31,.22);
  color:#ffe3a0;
  font-size:11px;
  font-weight:900;
  margin-top:8px;
  width:max-content;
  max-width:100%;
  clear:both;
}

    .bundle-empty-card{
      grid-column:1/-1;
      padding:24px;
      border-radius:22px;
      border:1px solid rgba(110,149,221,.18);
      background:rgba(255,255,255,.025);
      text-align:center;
    }

    .bundle-empty-card strong{
      display:block;
      font-size:18px;
      margin-bottom:8px;
    }

    .bundle-empty-card span{
      color:#9fb5d8;
    }

    .bundle-commission-preview{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
      margin-top:12px;
    }

    .bundle-commission-preview .box{
      box-shadow:none;
    }

    @media (max-width:720px){
      .bundle-offer-grid{
        grid-template-columns:1fr;
      }

      .bundle-price-row,
      .bundle-mini-grid,
      .bundle-offer-actions,
      .bundle-commission-preview{
        grid-template-columns:1fr;
      }
    }
  `;

  document.head.appendChild(style);
}





function getBundleOfferItemsFromResponse(data){
  if (Array.isArray(data)) return data;
  if (Array.isArray(data.items)) return data.items;
  if (Array.isArray(data.offers)) return data.offers;
  if (data.data && Array.isArray(data.data.items)) return data.data.items;
  if (data.data && Array.isArray(data.data.offers)) return data.data.offers;
  return [];
}

function hideNodeById(id){
  const node = el(id);
  if (!node) return;

  const field = node.closest('.field');
  if (field) {
    field.classList.add('hidden');
    return;
  }

  node.classList.add('hidden');
}

function ensureBundlePanelUi(){
  injectBundlePanelStyle();

  const section = el('bundleOffersSection');
  if (!section) return;

  const loadBtn = el('loadBundleOffersBtn');
  if (loadBtn) loadBtn.textContent = 'Refresh Offers';

  hideNodeById('bundleOffersEndpoint');
  hideNodeById('bundleOffersApiKey');
  hideNodeById('bundleOfferSearch');
  hideNodeById('bundleOfferSearchInput');
  hideNodeById('bundleOfferOperatorFilter');

  document.querySelectorAll('.bundle-panel-tools,.bundle-offer-toolbar').forEach(node => {
    node.classList.add('hidden');
  });

  const tableBody = el('bundleOffersTableBody');
  const tableWrap = tableBody ? tableBody.closest('.table-wrap') : null;
  if (tableWrap) {
    tableWrap.classList.add('hidden');
  }

  let cardWrap = el('bundleOfferCards') || el('bundleOffersGrid');

  if (!cardWrap) {
    cardWrap = document.createElement('div');
    cardWrap.id = 'bundleOfferCards';
    cardWrap.className = 'bundle-offer-grid';

    const outputBox = el('bundleOffersOutput');
    const outputParent = outputBox ? outputBox.closest('.box') : null;

    if (outputParent && outputParent.parentNode) {
      outputParent.parentNode.insertBefore(cardWrap, outputParent.nextSibling);
    } else {
      section.appendChild(cardWrap);
    }
  }

  cardWrap.classList.add('bundle-offer-grid');
  cardWrap.classList.remove('hidden');

  if (tableBody) {
    tableBody.innerHTML = '<tr><td colspan="7" class="muted">Bundle offers are displayed as cards.</td></tr>';
  }

  ensurePanelBundleModal();
  ensurePanelBundleCommissionModal();
}

function ensurePanelBundleModal(){
  if (el('panelBundleBuyModalWrap')) return;

  const modal = document.createElement('div');
  modal.id = 'panelBundleBuyModalWrap';
  modal.className = 'modal-wrap';
  modal.innerHTML = `
    <div class="modal-card">
      <div class="modal-head">
        <div>
          <h3>Buy Bundle</h3>
          <p>Confirm bundle number and create request</p>
        </div>
        <button id="closePanelBundleBuyModalBtn" class="modal-close" type="button">Close</button>
      </div>

      <div class="info-grid mb-14">
        <div class="box">
          <label>Bundle Name</label>
          <strong id="panelBundleOfferName">-</strong>
        </div>

        <div class="box">
          <label>Offer ID</label>
          <strong id="panelBundleOfferId">-</strong>
        </div>

        <div class="box">
          <label>Operator</label>
          <strong id="panelBundleOperator">-</strong>
        </div>

        <div class="box">
          <label>Amount</label>
          <strong id="panelBundleAmount">BDT 0.00</strong>
        </div>

        <div class="box">
          <label>User Commission</label>
          <strong id="panelBundleCommission">BDT 0.00</strong>
        </div>

        <div class="box">
          <label>User Pay</label>
          <strong id="panelBundleNetCost">BDT 0.00</strong>
        </div>

        <div class="box">
          <label>Expiry</label>
          <strong id="panelBundleExpires">-</strong>
        </div>

        <div class="box">
          <label>Status</label>
          <strong id="panelBundleStatus">-</strong>
        </div>
      </div>

      <div class="field">
        <label>Bundle Number</label>
        <input id="panelBundleNumberInput" class="input" placeholder="01712345678">
      </div>

      <div class="field">
        <label>Note</label>
        <input id="panelBundleNoteInput" class="input" value="Panel bundle request">
      </div>

      <div class="box mt-14">
        <label>Result</label>
        <div id="panelBundleBuyOutput" class="status-box-clean">No bundle request created yet.</div>
      </div>

      <div class="actions mt-14">
        <button id="panelBundleSubmitBtn" class="btn green" type="button">Create Bundle Request</button>
        <button id="panelBundleCancelBtn" class="btn ghost" type="button">Cancel</button>
      </div>
    </div>
  `;

  document.body.appendChild(modal);
  
  morphOutputBox('panelBundleBuyOutput');

  el('closePanelBundleBuyModalBtn')?.addEventListener('click', closeBundleBuyModal);
  el('panelBundleCancelBtn')?.addEventListener('click', closeBundleBuyModal);
  el('panelBundleSubmitBtn')?.addEventListener('click', confirmBundleBuy);

  el('panelBundleBuyModalWrap')?.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'panelBundleBuyModalWrap') {
      closeBundleBuyModal();
    }
  });

  el('panelBundleNumberInput')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      confirmBundleBuy();
    }
  });
  
}



function ensurePanelBundleCommissionModal(){
  if (el('panelBundleCommissionModalWrap')) return;

  const modal = document.createElement('div');
  modal.id = 'panelBundleCommissionModalWrap';
  modal.className = 'modal-wrap';
  modal.innerHTML = `
    <div class="modal-card modal-card-sm">
      <div class="modal-head">
        <div>
          <h3>Customize Commission</h3>
          <p>Set how much commission customer/user will get from this bundle.</p>
        </div>
        <button id="closePanelBundleCommissionModalBtn" class="modal-close" type="button">Close</button>
      </div>

      <div class="info-grid mb-14">
        <div class="box">
          <label>Bundle Name</label>
          <strong id="panelBundleCommissionOfferName">-</strong>
        </div>

        <div class="box">
          <label>Offer ID</label>
          <strong id="panelBundleCommissionOfferId">-</strong>
        </div>

        <div class="box">
          <label>Admin Commission</label>
          <strong id="panelBundleCommissionAdmin">BDT 0.00</strong>
        </div>

        <div class="box">
          <label>Current User Commission</label>
          <strong id="panelBundleCommissionCurrent">BDT 0.00</strong>
        </div>
      </div>

      <div class="field">
        <label>User Commission</label>
        <input id="panelBundleCommissionInput" class="input" type="number" step="0.01" min="0" placeholder="Enter user commission">
      </div>

      <div class="bundle-commission-preview">
        <div class="box">
          <label>User Gets</label>
          <strong id="panelBundleCommissionUserGets">BDT 0.00</strong>
        </div>
        <div class="box">
          <label>Your Profit On Success</label>
          <strong id="panelBundleCommissionProfit">BDT 0.00</strong>
        </div>
      </div>

      <div class="box mt-14">
        <label>Status</label>
        <div id="panelBundleCommissionOutput" class="status-box-clean">No commission update yet.</div>
      </div>

      <div class="actions mt-14">
        <button id="panelBundleCommissionSaveBtn" class="btn green" type="button">Save Commission</button>
        <button id="panelBundleCommissionResetBtn" class="btn orange" type="button">Reset Default</button>
        <button id="panelBundleCommissionCancelBtn" class="btn ghost" type="button">Cancel</button>
      </div>
    </div>
  `;

  document.body.appendChild(modal);

  morphOutputBox('panelBundleCommissionOutput');

  el('closePanelBundleCommissionModalBtn')?.addEventListener('click', closeBundleCommissionModal);
  el('panelBundleCommissionCancelBtn')?.addEventListener('click', closeBundleCommissionModal);
  el('panelBundleCommissionSaveBtn')?.addEventListener('click', saveBundleCommission);
  el('panelBundleCommissionResetBtn')?.addEventListener('click', resetBundleCommission);

  el('panelBundleCommissionInput')?.addEventListener('input', updateBundleCommissionPreview);

  modal.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'panelBundleCommissionModalWrap') {
      closeBundleCommissionModal();
    }
  });
}

function bundleOfferCardHtml(item){
  const offerId = String(item.offer_id || '');
  const name = String(item.bundle_name || item.name || 'Bundle Offer');
  const operator = String(item.operator || '-').toUpperCase();
  const description = String(item.description || '');
  const amount = getBundlePrice(item);
  const userCommission = getBundleUserCommission(item);
  const adminCommission = Number(item.admin_commission || 0);
  const subadminProfit = Number(item.subadmin_profit || 0);
  const youPay = getBundleYouPay(item);
  const expiresAt = Number(item.expires_at || 0);
  const packageValidity = getBundlePackageValidity(item);

  return `
    <div class="bundle-offer-card">
      <div class="bundle-offer-top">
        <div>
          <div class="bundle-offer-name">${esc(name)}</div>
          <div class="bundle-offer-id">Offer ID: ${esc(offerId || '-')}</div>
        </div>
        <div>${statusPill(operator)}</div>
      </div>

      ${description ? `<div class="bundle-offer-desc">${esc(description)}</div>` : `<div class="bundle-offer-desc">Ready bundle offer for your customer.</div>`}

      <div class="bundle-price-row">
        <div class="bundle-price-main">
          <span>Price</span>
          <strong>${fmtMoney(amount)}</strong>
        </div>
        <div class="bundle-price-main">
          <span>User Commission</span>
          <strong>${fmtMoney(userCommission)}</strong>
        </div>
      </div>

      <div class="bundle-mini-grid">
        <div>
          <span>User Pay</span>
          <strong>${fmtMoney(youPay)}</strong>
        </div>
        <div>
          <span>Admin Commission</span>
          <strong>${fmtMoney(adminCommission)}</strong>
        </div>
        <div>
          <span>Subadmin Profit</span>
          <strong>${fmtMoney(subadminProfit)}</strong>
        </div>
        <div>
          <span>Validity</span>
          <strong>${esc(packageValidity)}</strong>
        </div>
      </div>

      <div class="bundle-offer-footer">
        <div class="bundle-expiry">
          ${expiresAt ? `Expires: ${esc(fmtTs(expiresAt))}` : 'No expiry'}
          ${item.customized_by_subadmin ? `<div class="bundle-custom-tag">Custom commission active</div>` : ''}
        </div>

        <div class="bundle-offer-actions">
          <button class="btn ghost bundle-commission-btn" type="button" data-bundle-offer-id="${esc(offerId)}">
            Customize
          </button>
          <button class="btn green bundle-buy-btn" type="button" data-bundle-offer-id="${esc(offerId)}">
            Buy Bundle
          </button>
        </div>
      </div>
    </div>
  `;
}

function bindBundleOfferCardEvents(cardWrap){
  if (!cardWrap || cardWrap.dataset.bundleClickBound === '1') return;

  cardWrap.dataset.bundleClickBound = '1';

  cardWrap.addEventListener('click', (e) => {
    const buyBtn = e.target.closest('.bundle-buy-btn');
    if (buyBtn) {
      openBundleBuyModal(buyBtn.getAttribute('data-bundle-offer-id') || '');
      return;
    }

    const commissionBtn = e.target.closest('.bundle-commission-btn');
    if (commissionBtn) {
      openBundleCommissionModal(commissionBtn.getAttribute('data-bundle-offer-id') || '');
    }
  });
}

function renderBundleOffers(){
  ensureBundlePanelUi();

  const cardWrap = el('bundleOfferCards') || el('bundleOffersGrid');
  const rows = state.bundleOffers || [];

  if (!cardWrap) return;

  bindBundleOfferCardEvents(cardWrap);

  if (!rows.length) {
    cardWrap.innerHTML = `
      <div class="bundle-empty-card">
        <strong>No bundle offers found</strong>
        <span>Click Refresh Offers to load active bundle offers.</span>
      </div>
    `;
    return;
  }

  const token = String(Date.now()) + Math.random();
  state.bundleRenderToken = token;

  cardWrap.innerHTML = `
    <div class="bundle-empty-card">
      <strong>Loading offers...</strong>
      <span>Offers are rendering one by one for smoother performance.</span>
    </div>
  `;

  let index = 0;
  const chunkSize = 6;

  function drawChunk(){
    if (state.bundleRenderToken !== token) return;

    if (index === 0) {
      cardWrap.innerHTML = '';
    }

    const chunk = rows.slice(index, index + chunkSize);
    cardWrap.insertAdjacentHTML('beforeend', chunk.map(bundleOfferCardHtml).join(''));

    index += chunkSize;

    if (index < rows.length) {
      requestAnimationFrame(drawChunk);
    }
  }

  requestAnimationFrame(drawChunk);
}


async function loadBundleOffers(){
  ensureBundlePanelUi();

  try{
    const data = await proxyGet('bundle_offers_panel', {}, 'Loading bundle offers...');
    const items = getBundleOfferItemsFromResponse(data);

    state.bundleOffers = Array.isArray(items) ? items : [];
    state.loaded.bundleOffers = true;

    renderBundleOffers();

    setBoxMessage('bundleOffersOutput', 'ok', 'Bundle Offers Loaded', [
      `Total offers: ${state.bundleOffers.length}`,
      'Offers loaded from your subadmin session. No API key needed.'
    ]);

    showToast('Bundle offers loaded', 'ok');
  }catch(err){
    state.bundleOffers = [];
    state.loaded.bundleOffers = false;

    renderBundleOffers();

    setBoxMessage('bundleOffersOutput', 'error', 'Load Failed', [
      err.message || 'Failed to load bundle offers'
    ]);

    showToast(err.message || 'Failed to load bundle offers', 'error');
  }
}

function useBundleOfferForTest(offerId){
  const row = (state.bundleOffers || []).find(item => String(item.offer_id || '') === String(offerId));

  if (!row) {
    showToast('Bundle offer not found', 'error');
    return;
  }

  if (el('bundleTestOfferId')) {
    el('bundleTestOfferId').value = String(row.offer_id || '');
  }

  openPageSection('apiTestSection');
  setApiTestTab('bundleApiTestPanel');
  
  showToast('Bundle offer selected for API test', 'ok');
}

function openBundleBuyModal(offerId){
  ensureBundlePanelUi();

  const row = (state.bundleOffers || []).find(item => String(item.offer_id || '') === String(offerId));

  if (!row) {
    showToast('Bundle offer not found', 'error');
    return;
  }

  state.bundleBuy = {
    offerId: String(row.offer_id || ''),
    row
  };
  
  state.bundleCommission = {
  offerId: '',
  row: null
  };
  

  if (el('panelBundleOfferName')) el('panelBundleOfferName').textContent = String(row.bundle_name || row.name || '-');
  if (el('panelBundleOfferId')) el('panelBundleOfferId').textContent = String(row.offer_id || '-');
  if (el('panelBundleOperator')) el('panelBundleOperator').textContent = String(row.operator || '-');
  
  
  if (el('panelBundleAmount')) el('panelBundleAmount').textContent = fmtMoney(getBundlePrice(row));
  if (el('panelBundleCommission')) el('panelBundleCommission').textContent = fmtMoney(getBundleUserCommission(row));
  if (el('panelBundleNetCost')) el('panelBundleNetCost').textContent = fmtMoney(getBundleYouPay(row));
  
  
  if (el('panelBundleExpires')) el('panelBundleExpires').textContent = row.expires_at ? fmtTs(row.expires_at) : '-';
  if (el('panelBundleStatus')) el('panelBundleStatus').innerHTML = statusPill(row.status || (row.active ? 'ACTIVE' : 'INACTIVE'));

  if (el('panelBundleNumberInput')) el('panelBundleNumberInput').value = '';
  if (el('panelBundleNoteInput')) el('panelBundleNoteInput').value = 'Panel bundle request';

  setBoxMessage('panelBundleBuyOutput', 'info', 'Ready', [
    'Enter customer bundle number and create request.'
  ]);

  el('panelBundleBuyModalWrap')?.classList.add('open');
}

function closeBundleBuyModal(){
  el('panelBundleBuyModalWrap')?.classList.remove('open');

  state.bundleBuy = {
    offerId: '',
    row: null
  };
}


function openBundleCommissionModal(offerId){
  ensurePanelBundleCommissionModal();

  const row = (state.bundleOffers || []).find(item => String(item.offer_id || '') === String(offerId));

  if (!row) {
    showToast('Bundle offer not found', 'error');
    return;
  }

  state.bundleCommission = {
    offerId: String(row.offer_id || ''),
    row
  };

  const adminCommission = Number(row.admin_commission || 0);
  const userCommission = Number(row.user_commission || 0);

  if (el('panelBundleCommissionOfferName')) {
    el('panelBundleCommissionOfferName').textContent = String(row.bundle_name || row.name || '-');
  }

  if (el('panelBundleCommissionOfferId')) {
    el('panelBundleCommissionOfferId').textContent = String(row.offer_id || '-');
  }

  if (el('panelBundleCommissionAdmin')) {
    el('panelBundleCommissionAdmin').textContent = fmtMoney(adminCommission);
  }

  if (el('panelBundleCommissionCurrent')) {
    el('panelBundleCommissionCurrent').textContent = fmtMoney(userCommission);
  }

  if (el('panelBundleCommissionInput')) {
    el('panelBundleCommissionInput').value = money(userCommission);
    el('panelBundleCommissionInput').max = String(adminCommission);
  }

  updateBundleCommissionPreview();

  setBoxMessage('panelBundleCommissionOutput', 'info', 'Ready', [
    `Maximum user commission: ${fmtMoney(adminCommission)}`,
    'User commission কম দিলে বাকি profit success হওয়ার পরে subadmin wallet-এ যোগ হবে।'
  ]);

  el('panelBundleCommissionModalWrap')?.classList.add('open');
}

function closeBundleCommissionModal(){
  el('panelBundleCommissionModalWrap')?.classList.remove('open');

  state.bundleCommission = {
    offerId: '',
    row: null
  };
}

function updateBundleCommissionPreview(){
  const row = state.bundleCommission?.row || null;
  const adminCommission = Number(row?.admin_commission || 0);
  let userCommission = Number(el('panelBundleCommissionInput')?.value || 0);

  if (userCommission < 0) userCommission = 0;
  if (userCommission > adminCommission) userCommission = adminCommission;

  const profit = Math.max(0, adminCommission - userCommission);

  if (el('panelBundleCommissionUserGets')) {
    el('panelBundleCommissionUserGets').textContent = fmtMoney(userCommission);
  }

  if (el('panelBundleCommissionProfit')) {
    el('panelBundleCommissionProfit').textContent = fmtMoney(profit);
  }
}

async function saveBundleCommission(){
  const row = state.bundleCommission?.row || null;
  const offerId = String(state.bundleCommission?.offerId || '');
  const adminCommission = Number(row?.admin_commission || 0);
  const userCommission = Number(el('panelBundleCommissionInput')?.value || 0);

  if (!row || !offerId) {
    setBoxMessage('panelBundleCommissionOutput', 'error', 'Offer Missing', [
      'Bundle offer not found. Please select offer again.'
    ]);
    return;
  }

  if (userCommission < 0) {
    setBoxMessage('panelBundleCommissionOutput', 'error', 'Validation Error', [
      'User commission cannot be negative.'
    ]);
    return;
  }

  if (userCommission > adminCommission) {
    setBoxMessage('panelBundleCommissionOutput', 'error', 'Validation Error', [
      `User commission cannot be higher than admin commission ${fmtMoney(adminCommission)}.`
    ]);
    return;
  }

  try{
    setBoxMessage('panelBundleCommissionOutput', 'warning', 'Saving', [
      'Saving custom commission...'
    ]);

    const data = await proxyPost('bundle_commission_save', {
      offer_id: offerId,
      user_commission: userCommission,
      active: true
    }, 'Saving commission...');

    setBoxMessage('panelBundleCommissionOutput', 'ok', 'Commission Saved', [
      `User Commission: ${fmtMoney(data.user_commission ?? userCommission)}`,
      `Your Profit: ${fmtMoney(data.subadmin_profit ?? (adminCommission - userCommission))}`,
      'This profit will be credited only after admin marks the bundle request as SUCCESS.'
    ]);

    await loadBundleOffers();

    showToast('Bundle commission saved', 'ok');

    setTimeout(() => {
      closeBundleCommissionModal();
    }, 700);
  }catch(err){
    setBoxMessage('panelBundleCommissionOutput', 'error', 'Save Failed', [
      err.message || 'Failed to save commission'
    ]);

    showToast(err.message || 'Failed to save commission', 'error');
  }
}

async function resetBundleCommission(){
  const offerId = String(state.bundleCommission?.offerId || '');

  if (!offerId) {
    setBoxMessage('panelBundleCommissionOutput', 'error', 'Offer Missing', [
      'Bundle offer not found. Please select offer again.'
    ]);
    return;
  }

  const ok = await askConfirm({
    title: 'Reset Commission',
    text: 'Reset this bundle commission to default?',
    okText: 'Reset Now',
    okClass: 'orange'
  });

  if (!ok) return;

  try{
    setBoxMessage('panelBundleCommissionOutput', 'warning', 'Resetting', [
      'Resetting custom commission...'
    ]);

    await proxyPost('bundle_commission_reset', {
      offer_id: offerId
    }, 'Resetting commission...');

    setBoxMessage('panelBundleCommissionOutput', 'ok', 'Commission Reset', [
      'Custom commission removed. Default commission will be used.'
    ]);

    await loadBundleOffers();

    showToast('Bundle commission reset', 'ok');

    setTimeout(() => {
      closeBundleCommissionModal();
    }, 700);
  }catch(err){
    setBoxMessage('panelBundleCommissionOutput', 'error', 'Reset Failed', [
      err.message || 'Failed to reset commission'
    ]);

    showToast(err.message || 'Failed to reset commission', 'error');
  }
}



async function confirmBundleBuy(){
  const row = state.bundleBuy?.row || null;
  const offerId = String(state.bundleBuy?.offerId || '');
  const bundleNumber = el('panelBundleNumberInput')?.value.trim() || '';
  const note = el('panelBundleNoteInput')?.value.trim() || '';

  if (!row || !offerId) {
    setBoxMessage('panelBundleBuyOutput', 'error', 'Offer Missing', [
      'Bundle offer not found. Please select offer again.'
    ]);
    return;
  }

  if (!bundleNumber) {
    setBoxMessage('panelBundleBuyOutput', 'error', 'Validation Error', [
      'Bundle number is required.'
    ]);
    return;
  }

  try{
    setBoxMessage('panelBundleBuyOutput', 'warning', 'Processing', [
      'Creating bundle request...'
    ]);

    const data = await proxyPost('bundle_create_panel', {
      offer_id: offerId,
      bundle_number: bundleNumber,
      note: note || 'Panel bundle request'
    }, 'Creating bundle request...');

    setBoxMessage('panelBundleBuyOutput', 'ok', 'Bundle Request Created', [
      `Request ID: ${data.request_id || '-'}`,
      `Bundle: ${data.bundle_name || row.bundle_name || row.name || '-'}`,
      `Number: ${data.bundle_number || bundleNumber}`,
      `Amount: ${fmtMoney(data.amount || row.amount || 0)}`,
      `Status: ${data.status || 'WAITING_ADMIN'}`
    ]);

    setBoxMessage('bundleOffersOutput', 'ok', 'Bundle Request Created', [
      `Request ID: ${data.request_id || '-'}`,
      `Bundle: ${data.bundle_name || row.bundle_name || row.name || '-'}`,
      `Number: ${data.bundle_number || bundleNumber}`,
      `Amount: ${fmtMoney(data.amount || row.amount || 0)}`,
      `Status: ${data.status || 'WAITING_ADMIN'}`
    ]);

    await Promise.all([
      loadWallet(),
      loadLogs()
    ]);

    renderSummary();
    renderLogs();
    renderPanelTopupRequests();

    showToast('Bundle request created successfully', 'ok');

    setTimeout(() => {
      closeBundleBuyModal();
      openPageSection('requestLogsSection');
    }, 900);
  }catch(err){
    setBoxMessage('panelBundleBuyOutput', 'error', 'Bundle Request Failed', [
      err.message || 'Failed to create bundle request'
    ]);

    showToast(err.message || 'Failed to create bundle request', 'error');
  }
}

async function runBundleApiTest(){
  const endpoint = el('bundleCreateEndpoint')?.value.trim() || '';
  const apiKey = el('bundleCreateApiKey')?.value.trim() || '';
  const offerId = el('bundleTestOfferId')?.value.trim() || '';
  const bundleNumber = el('bundleTestNumber')?.value.trim() || '';
  const note = el('bundleTestNote')?.value.trim() || '';

  if (!endpoint) {
    showToast('Bundle create endpoint is required', 'error');
    return;
  }

  if (!apiKey) {
    showToast('Plain API key is required', 'error');
    return;
  }

  if (!offerId) {
    showToast('Offer ID is required', 'error');
    return;
  }

  if (!bundleNumber) {
    showToast('Bundle number is required', 'error');
    return;
  }

  setBusy(true, 'Creating bundle request...');

  try{
    const body = {
      offer_id: offerId,
      bundle_number: bundleNumber,
      note: note || 'Bundle API test from subadmin panel'
    };

    const res = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(body)
    });

    const json = await readJsonSafe(res);

    if (!res.ok || !json.ok) {
      throw new Error(json.message || 'Bundle request failed');
    }

    const data = json.data || {};

    setBoxMessage('bundleApiOutput', 'ok', 'Bundle Request Created', [
      `Request ID: ${data.request_id || '-'}`,
      `Offer ID: ${data.offer_id || offerId}`,
      `Bundle Number: ${data.bundle_number || bundleNumber}`,
      `Status: ${data.status || 'PENDING'}`,
      json.message || 'Bundle request created successfully.'
    ]);

    showToast('Bundle request created', 'ok');
  }catch(err){
    setBoxMessage('bundleApiOutput', 'error', 'Bundle API Failed', [
      err.message || 'Failed to create bundle request'
    ]);

    showToast(err.message || 'Failed to create bundle request', 'error');
  }finally{
    setBusy(false);
  }
}

/* =========================
   Forms
========================= */

function clearPanelTopupForm(){
  if (el('panelTopupNumber')) el('panelTopupNumber').value = '';
  if (el('panelTopupOperator')) el('panelTopupOperator').value = 'GP';
  if (el('panelTopupAmount')) el('panelTopupAmount').value = '20';
  if (el('panelTopupNote')) el('panelTopupNote').value = 'Panel topup request';

  setBoxMessage('panelTopupOutput', 'info', 'Ready', [
    'No panel topup created yet.'
  ]);
}

function clearCreateUserForm(){
  if (el('newUserName')) el('newUserName').value = '';
  if (el('newUserPhone')) el('newUserPhone').value = '';
  if (el('newUserEmail')) el('newUserEmail').value = '';
  if (el('newUserPassword')) el('newUserPassword').value = '';
  if (el('newUserConfirmPassword')) el('newUserConfirmPassword').value = '';
  if (el('newUserPin')) el('newUserPin').value = '';
  if (el('newUserConfirmPin')) el('newUserConfirmPin').value = '';
  if (el('newUserPhoneCountry')) el('newUserPhoneCountry').value = 'BD';
  if (el('newUserPricingCountry')) {
    const ownCountry = String(
      state.me?.pricing_country ||
      state.me?.market_country ||
      state.me?.service_country ||
      state.me?.country_code ||
      state.me?.country ||
      'BD'
    ).toUpperCase();
    el('newUserPricingCountry').value = ownCountry === 'MY' ? 'MY' : 'BD';
  }

  setBoxMessage('createUserOutput', 'info', 'Ready', [
    'No user created yet.'
  ]);
}

function clearCreateUserOtpTimer(){
  if (state.userCreateOtp.timer) {
    clearInterval(state.userCreateOtp.timer);
    state.userCreateOtp.timer = null;
  }
}

function createUserOtpIsExpired(){
  return state.userCreateOtp.expiresAt > 0 && Date.now() >= state.userCreateOtp.expiresAt;
}

function updateCreateUserOtpCountdown(){
  const expiresNode = el('createUserOtpExpiresText');
  const verifyButton = el('verifyCreateUserOtpBtn');
  const resendButton = el('resendCreateUserOtpBtn');
  const left = Math.max(0, Math.ceil((state.userCreateOtp.expiresAt - Date.now()) / 1000));

  if (expiresNode) {
    expiresNode.textContent = left > 0 ? left + ' seconds' : 'Expired';
  }

  if (verifyButton) {
    verifyButton.disabled = left <= 0;
  }

  if (left <= 0) {
    clearCreateUserOtpTimer();
    if (resendButton) resendButton.disabled = false;

    const statusBox = el('createUserOtpStatus');
    if (statusBox && el('createUserOtpModalWrap')?.classList.contains('open')) {
      statusBox.textContent = 'OTP expired. Please resend OTP to continue.';
    }
  }
}

function startCreateUserOtpTimer(){
  clearCreateUserOtpTimer();

  if (!state.userCreateOtp.expiresAt) {
    state.userCreateOtp.expiresAt = Date.now() + (Math.max(0, state.userCreateOtp.expiresInSeconds) * 1000);
  }

  updateCreateUserOtpCountdown();

  if (!createUserOtpIsExpired()) {
    state.userCreateOtp.timer = setInterval(updateCreateUserOtpCountdown, 1000);
  }
}

function resetCreateUserOtpState(){
  clearCreateUserOtpTimer();

  state.userCreateOtp = {
    requestToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300,
    expiresAt: 0,
    timer: null,
    formData: null
  };

  if (el('createUserOtpMaskedPhone')) el('createUserOtpMaskedPhone').textContent = '-';
  if (el('createUserOtpExpiresText')) el('createUserOtpExpiresText').textContent = '300 seconds';
  if (el('createUserOtpCode')) el('createUserOtpCode').value = '';
  if (el('verifyCreateUserOtpBtn')) el('verifyCreateUserOtpBtn').disabled = false;
  if (el('createUserOtpStatus')) {
    el('createUserOtpStatus').textContent = 'OTP পাঠানোর পরে এখানে status দেখাবে।';
  }
}

function openCreateUserOtpModal(){
  el('createUserOtpModalWrap')?.classList.add('open');
  startCreateUserOtpTimer();
}

function closeCreateUserOtpModal(){
  clearCreateUserOtpTimer();
  el('createUserOtpModalWrap')?.classList.remove('open');
  resetCreateUserOtpState();
}

function updateCreateUserOtpModal(data = {}){
  state.userCreateOtp.requestToken = String(
    data.user_create_token ||
    data.create_token ||
    data.pre_auth_token ||
    data.request_token ||
    state.userCreateOtp.requestToken ||
    ''
  );

  state.userCreateOtp.otpRequestId = String(
    data.otp_request_id ||
    data.request_id ||
    state.userCreateOtp.otpRequestId ||
    ''
  );

  state.userCreateOtp.maskedPhone = String(
    data.masked_phone ||
    data.phone_mask ||
    state.userCreateOtp.maskedPhone ||
    ''
  );

  const expiresInValue = data.expires_in_seconds
    ?? data.expires_in
    ?? state.userCreateOtp.expiresInSeconds
    ?? 300;
  const parsedExpiresIn = Number(expiresInValue);
  state.userCreateOtp.expiresInSeconds = Number.isFinite(parsedExpiresIn)
    ? Math.max(0, parsedExpiresIn)
    : 300;
  const serverExpiresAt = Number(data.expires_at || 0);
  state.userCreateOtp.expiresAt = serverExpiresAt > 0
    ? (serverExpiresAt < 1000000000000 ? serverExpiresAt * 1000 : serverExpiresAt)
    : Date.now() + (state.userCreateOtp.expiresInSeconds * 1000);

  if (el('createUserOtpMaskedPhone')) {
    el('createUserOtpMaskedPhone').textContent = state.userCreateOtp.maskedPhone || '-';
  }

  if (el('createUserOtpCode')) {
    el('createUserOtpCode').value = '';
  }

  if (el('createUserOtpStatus')) {
    el('createUserOtpStatus').textContent =
      'OTP sent to the new user at ' + (state.userCreateOtp.maskedPhone || 'the target phone') + '. Verify it to create the user.';
  }

  startCreateUserOtpTimer();
}

async function confirmCreateUserOtp(){
  const otp = el('createUserOtpCode')?.value.trim() || '';
  const statusBox = el('createUserOtpStatus');

  if (!state.userCreateOtp.formData) {
    if (statusBox) statusBox.textContent = 'Create user session পাওয়া যায়নি। আবার শুরু করো।';
    return;
  }

  if (!state.userCreateOtp.otpRequestId) {
    if (statusBox) statusBox.textContent = 'OTP request ID missing. আবার OTP send করো।';
    return;
  }

  if (createUserOtpIsExpired()) {
    if (statusBox) statusBox.textContent = 'OTP expired. Please resend OTP to continue.';
    return;
  }

  if (!otp) {
    if (statusBox) statusBox.textContent = 'OTP code দাও।';
    return;
  }

  try{
    const body = {
      otp: otp,
      otp_request_id: state.userCreateOtp.otpRequestId,
      request_id: state.userCreateOtp.otpRequestId,
      user_create_token: state.userCreateOtp.requestToken,
      create_token: state.userCreateOtp.requestToken,
      pre_auth_token: state.userCreateOtp.requestToken
    };

    const data = await proxyPost('user_create_confirm', body, 'Verifying OTP & creating user...');

    setBoxMessage('createUserOutput', 'ok', 'User Created', [
      `Name: ${data.name || state.userCreateOtp.formData.name || '-'}`,
      `Phone: ${data.phone || state.userCreateOtp.formData.phone || '-'}`,
      `Email: ${data.email || state.userCreateOtp.formData.email || '-'}`,
      `Role: ${data.role || 'USER'}`,
      `Status: ${data.status || 'ACTIVE'}`
    ]);

    if (statusBox) {
      statusBox.textContent = 'OTP verified successfully. User created.';
    }

    clearCreateUserOtpTimer();
    clearCreateUserForm();
    await loadUsers();
    renderUsers();

    showToast('User created successfully', 'ok');

    setTimeout(() => {
      closeCreateUserOtpModal();
      openPageSection('usersSection');
    }, 800);
  }catch(err){
    if (statusBox) {
      statusBox.textContent = err.message || 'Failed to verify OTP.';
    }

    setBoxMessage('createUserOutput', 'error', 'Create User Failed', [
      err.message || 'Failed to create user'
    ]);

    showToast(err.message || 'Failed to create user', 'error');
  }
}

async function resendCreateUserOtp(){
  const statusBox = el('createUserOtpStatus');

  if (!state.userCreateOtp.formData) {
    if (statusBox) statusBox.textContent = 'Create user session পাওয়া যায়নি। আবার শুরু করো।';
    return;
  }

  try{
    const data = await proxyPost('user_create_send_otp', state.userCreateOtp.formData, 'Resending OTP...');

    updateCreateUserOtpModal(data);

    if (statusBox) {
      statusBox.textContent =
        'OTP resent successfully to the new user at ' + (state.userCreateOtp.maskedPhone || 'the target phone') + '.';
    }

    showToast('OTP resent successfully', 'ok');
  }catch(err){
    if (statusBox) {
      statusBox.textContent = err.message || 'Failed to resend OTP.';
    }

    showToast(err.message || 'Failed to resend OTP', 'error');
  }
}

/* =========================
   Logs / navigation
========================= */

function setRequestLogFilter(filter){
  state.requestLogFilter = String(filter || 'ALL').toUpperCase();

  document.querySelectorAll('.log-filter-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.logFilter === state.requestLogFilter);
  });

  renderLogs();
}

function viewRequestLog(requestId){
  const row = (state.requestLogs || []).find(item => String(item.request_id || '') === String(requestId));

  if (!row) {
    showToast('Request log not found', 'error');
    return;
  }

  const type = String(row.request_type || row.action || '').toUpperCase();
  const isMfs = type === 'MFS';
  const isWallet = type === 'WALLET' || row.is_wallet_history === true;
  const isAddMoney = type === 'ADD_MONEY' || row.is_add_money_history === true;
  const number = isAddMoney ? (row.sender_number || row.transaction_id || '') : (row.topup_number || row.bundle_number || row.receiver_number || row.number || '');
  const service = isWallet
    ? `${row.sender_name || 'Admin'} (${row.sender_role || 'ADMIN'})`
    : isAddMoney
    ? `Add Money - ${row.method || '-'}`
    : (isMfs ? (row.provider_name || mfsProviderName(row.provider || row.service)) : (row.operator || '-'));
  const amountText = isWallet
    ? `${walletPrefix(row.currency)} ${money(row.amount || 0)}`
    : isAddMoney
    ? `${walletPrefix(row.currency)} ${money(row.amount || 0)}`
    : (isMfs ? `${mfsAmountText(row)} / ${mfsFeePayText(row)}` : fmtMoney(row.amount || 0));
  const typeLabel = isWallet ? 'Balance Received' : (isAddMoney ? 'Add Money' : (isMfs ? `${type} - ${service}` : (type || '-')));

  if (el('logRequestId')) el('logRequestId').textContent = row.request_id || '-';
  if (el('logKeyId')) el('logKeyId').textContent = row.key_id || '-';
  if (el('logType')) el('logType').textContent = typeLabel;
  if (el('logStatusText')) el('logStatusText').textContent = row.status || '-';
  if (el('logRequestIdLabel')) el('logRequestIdLabel').textContent = isWallet ? 'Transfer ID' : 'Request ID';
  if (el('logKeyIdLabel')) el('logKeyIdLabel').textContent = isWallet ? 'Source' : 'Key ID';
  if (el('logServiceLabel')) el('logServiceLabel').textContent = isWallet ? 'From' : (isAddMoney ? 'Method' : (isMfs ? 'Service' : 'Operator'));
  if (el('logNumberLabel')) el('logNumberLabel').textContent = isWallet ? 'Sender Phone' : (isAddMoney ? 'Txn / Sender' : 'Number');
  if (el('logOperator')) el('logOperator').textContent = service;
  if (el('logNumber')) el('logNumber').textContent = number || '-';
  if (el('logAmount')) el('logAmount').textContent = amountText;
  if (el('logCreated')) el('logCreated').textContent = fmtTs(row.created_at || 0);
  if (el('logUpdated')) el('logUpdated').textContent = fmtTs(row.updated_at || row.created_at || 0);
  if (el('logMessage')) el('logMessage').textContent = row.message || '-';
  if (el('logModalTitle')) el('logModalTitle').textContent = isWallet ? 'Balance Received Details' : (isAddMoney ? 'Add Money Details' : 'Request Details');
  if (el('logModalSub')) el('logModalSub').textContent = isWallet
    ? 'Admin credit and wallet balance summary'
    : isAddMoney
    ? 'Manual add money request summary'
    : 'Request summary and details';

  setDetailBox('logRawJson', 'Request Details', [
    [isWallet ? 'Transfer ID' : 'Request ID', row.request_id || '-'],
    [isWallet ? 'Source' : 'Key ID', row.key_id || '-'],
    ['Type', typeLabel],
    ['Status', row.status || '-'],
    [isWallet ? 'From' : (isAddMoney ? 'Method' : (isMfs ? 'Service' : 'Operator')), service],
    [isWallet ? 'Sender Phone' : (isAddMoney ? 'Txn / Sender' : 'Number'), number || '-'],
    ['Amount', amountText],
    ...(isWallet ? [
      ['Balance Before', `${walletPrefix(row.currency)} ${money(row.before_balance || 0)}`],
      ['Balance After', `${walletPrefix(row.currency)} ${money(row.after_balance || 0)}`],
      ['Reference', row.reference || '-']
    ] : []),
    ...(isAddMoney ? [
      ['Transaction ID', row.transaction_id || '-'],
      ['Sender Number', row.sender_number || '-'],
      ['Receipt', row.receipt_url || '-'],
      ['Reject Reason', row.reject_reason || '-']
    ] : []),
    ['Created', fmtTs(row.created_at || 0)],
    ['Updated', fmtTs(row.updated_at || row.created_at || 0)],
    ['Message', row.message || '-']
  ]);

  el('logModalWrap')?.classList.add('open');

  const copyBtn = el('copyLogRequestBtn');
  if (copyBtn) {
    copyBtn.textContent = isWallet ? 'Copy Transfer ID' : 'Copy Request ID';
    copyBtn.onclick = () => copyRequestId(row.request_id || '');
  }
}

function closeLogModal(){
  el('logModalWrap')?.classList.remove('open');
}

function copyRequestId(requestId){
  copyText(requestId, 'Request ID copied');
}

function stopLogsAutoRefresh(){
  if (state.logsAutoRefreshTimer) {
    clearInterval(state.logsAutoRefreshTimer);
    state.logsAutoRefreshTimer = null;
  }
}

function startLogsAutoRefresh(){
  stopLogsAutoRefresh();

  state.logsAutoRefreshTimer = setInterval(async () => {
    const section = el('requestLogsSection');
    if (!section || !section.classList.contains('active')) return;

    try{
      await loadLogs();
      renderSummary();
      renderLogs();
      renderPanelTopupRequests();
    }catch(_){}
  }, 60000);
}


function setApiTestTab(targetId = 'liveApiTestPanel'){
  document.querySelectorAll('.api-test-panel').forEach(panel => {
    panel.classList.toggle('active', panel.id === targetId);
  });

  document.querySelectorAll('.api-test-tab').forEach(btn => {
    const active = btn.dataset.apiTestTarget === targetId;
    btn.classList.toggle('active', active);
    btn.classList.toggle('green', active);
    btn.classList.toggle('ghost', !active);
  });
}


function openPageSection(sectionId){
  sectionId = sectionId || 'overviewSection';

  document.querySelectorAll('.page-section').forEach(node => node.classList.remove('active'));
  document.querySelectorAll('.side-btn').forEach(node => node.classList.remove('active'));

  el(sectionId)?.classList.add('active');
  document.querySelector(`.side-btn[data-page-section="${sectionId}"]`)?.classList.add('active');

  if (sectionId === 'requestLogsSection') {
    startLogsAutoRefresh();
  } else {
    stopLogsAutoRefresh();
  }

  scrollPageSectionTop(sectionId);

  loadSectionData(sectionId, false).catch(err => {
    showToast(err.message || 'Failed to load section data', 'error');
  });
}

/* =========================
   Loaders
========================= */

async function loadMe(){
  const data = await proxyGet('me', {}, 'Checking session...');
  state.me = data.user || null;
  state.csrf = data.csrf || '';
}



async function loadWallet(){
  state.wallet = await proxyGet('wallet_summary', {}, 'Loading wallet summary...');
  state.loaded.wallet = true;
  updateSubMfsCurrencyUi();
}

async function loadKeys(){
  const data = await proxyGet('api_keys', {}, 'Loading API keys...');
  state.apiKeys = data.items || [];
  state.loaded.keys = true;
}

async function loadLogs(){
  const data = await proxyGet('request_logs', {
    limit: 100,
    month: new Date().toISOString().slice(0, 7)
  }, 'Loading history logs...');
  state.requestLogs = data.items || [];
  state.loaded.logs = true;
}

async function loadUsers(){
  const role = el('usersRoleFilter')?.value || '';
  const status = el('usersStatusFilter')?.value || '';

  const data = await proxyGet('users_list', {
    role,
    status,
    limit: 300
  }, 'Loading users...');

  state.users = data.items || [];
  state.loaded.users = true;
}


async function refreshAll(showMessage = false){
  await loadMe();

  const currentSectionId = getCurrentSectionId();

  if (currentSectionId === 'overviewSection') {
    await loadSectionData('overviewSection', true);
  } else {
    await ensureWalletLoaded(true);
    await loadSectionData(currentSectionId, true);
  }

  renderSummary();

  if (showMessage) {
    showToast('Dashboard refreshed', 'info');
  }
}


async function doLogout(){
  try{
    await proxyPost('logout', {}, 'Logging out...');
  }catch(_){}

  stopLogsAutoRefresh();

  state.me = null;
  state.csrf = '';
  state.wallet = null;
  state.apiKeys = [];
  state.requestLogs = [];
  state.bundleOffers = [];
  state.users = [];
  state.mfs = {
    tab: 'pending',
    summary: {
      pending: 0,
      processing: 0,
      done: 0,
      failed: 0
    },
    rows: [],
    loaded: false
  };
  state.loaded.mfs = false;
  state.loaded.mfsSummary = false;
  state.loaded.mfsList = false;
  state.bundleBuy = {
    offerId: '',
    row: null
  };

  resetAddBalanceState();
  resetWalletLedgerState();

  state.deductOtp = {
    targetUid: '',
    otpRequestId: '',
    targetName: '',
    targetPhone: '',
    amount: 0,
    note: ''
  };

  clearCreateUserOtpTimer();
  state.userCreateOtp = {
    requestToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300,
    expiresAt: 0,
    timer: null,
    formData: null
  };

  if (el('lastPlainKey')) el('lastPlainKey').textContent = '-';
  if (el('liveApiKey')) el('liveApiKey').value = '';

  setBoxMessage('liveApiOutput', 'info', 'Ready', [
    'No test run yet.'
  ]);

  clearPanelTopupForm();
  clearMfsForm();
  setBoxMessage('subMfsDetailsOutput', 'info', 'Ready', [
    'Select a request to view details.'
  ]);
  renderMfsSummary();
  renderMfsRows();
  clearCreateUserForm();

  if (window.resetDeductOtpState) {
    window.resetDeductOtpState();
  }

  closeAddBalanceModal();
  closeWalletLedgerModal();
  closeLogModal();
  closeConfirmModal();
  closeBundleBuyModal();
  closeBundleCommissionModal();

  if (typeof closeCreateUserOtpModal === 'function') {
    closeCreateUserOtpModal();
  }

  showToast('Logged out', 'info');

  setTimeout(() => {
    window.location.href = '/subadmin/login.php';
  }, 150);
}

/* =========================
   Actions
========================= */

async function createKey(){
  try{
    const data = await proxyPost('api_key_create', {}, 'Creating API key...');
    const plainKey = data.plain_key || '-';

    if (el('lastPlainKey')) el('lastPlainKey').textContent = plainKey;

    if (plainKey && plainKey !== '-' && el('liveApiKey')) {
      el('liveApiKey').value = plainKey;
    }

    await loadKeys();
    renderSummary();
    renderKeys();
    renderIntegrationGuide();

    openPageSection('apiKeysSection');
    showToast('API key created successfully. Save the plain key now.', 'ok');
  }catch(err){
    showToast(err.message || 'Failed to create key', 'error');
  }
}

function ensureConfirmModal(){
  if (el('confirmModalWrap')) return;

  const wrap = document.createElement('div');
  wrap.id = 'confirmModalWrap';
  wrap.className = 'modal-wrap';
  wrap.innerHTML = `
    <div class="confirm-modal-card">
      <h3 class="confirm-modal-title" id="confirmModalTitle">Confirm Action</h3>
      <div class="confirm-modal-text" id="confirmModalText">Are you sure?</div>
      <div class="confirm-modal-actions">
        <button class="btn ghost" id="confirmModalCancelBtn">Cancel</button>
        <button class="btn green" id="confirmModalOkBtn">Confirm</button>
      </div>
    </div>
  `;

  document.body.appendChild(wrap);

  wrap.addEventListener('click', (e) => {
    if (e.target.id === 'confirmModalWrap') {
      closeConfirmModal(false);
    }
  });
}

let confirmResolver = null;

function closeConfirmModal(result = false){
  const wrap = el('confirmModalWrap');
  if (wrap) wrap.classList.remove('open');

  if (confirmResolver) {
    confirmResolver(result);
    confirmResolver = null;
  }
}

function askConfirm({title = 'Confirm Action', text = 'Are you sure?', okText = 'Confirm', okClass = 'green'} = {}){
  ensureConfirmModal();

  const wrap = el('confirmModalWrap');
  const titleNode = el('confirmModalTitle');
  const textNode = el('confirmModalText');
  const okBtn = el('confirmModalOkBtn');
  const cancelBtn = el('confirmModalCancelBtn');

  if (titleNode) titleNode.textContent = title;
  if (textNode) textNode.textContent = text;

  if (okBtn) {
    okBtn.textContent = okText;
    okBtn.className = 'btn ' + okClass;
    okBtn.onclick = () => closeConfirmModal(true);
  }

  if (cancelBtn) {
    cancelBtn.onclick = () => closeConfirmModal(false);
  }

  wrap?.classList.add('open');

  return new Promise(resolve => {
    confirmResolver = resolve;
  });
}

async function updateKeyStatus(keyId, status){
  const ok = await askConfirm({
    title: 'Update API Key',
    text: `Change key ${keyId} to ${status}?`,
    okText: 'Yes, Update',
    okClass: 'blue'
  });

  if (!ok) return;

  try{
    await proxyPost('api_key_update_status', {
      key_id: keyId,
      status: status
    }, 'Updating key status...');

    await loadKeys();
    renderSummary();
    renderKeys();
    showToast('API key updated', 'ok');
  }catch(err){
    showToast(err.message || 'Failed to update key', 'error');
  }
}

async function convertUserToRetailer(uid){
  const ok = await askConfirm({
    title: 'Convert User',
    text: 'Convert this USER account to RETAILER?',
    okText: 'Convert Now',
    okClass: 'green'
  });

  if (!ok) return;

  try{
    await proxyPost('user_convert_retailer', { uid }, 'Converting user...');

    showToast('User converted to retailer', 'ok');
    await loadUsers();
    renderUsers();
  }catch(err){
    showToast(err.message || 'Failed to convert user', 'error');
  }
}

async function createPanelTopup(){
  const topupNumber = el('panelTopupNumber')?.value.trim() || '';
  const operator = el('panelTopupOperator')?.value.trim() || '';
  const amount = Number(el('panelTopupAmount')?.value || 0);
  const note = el('panelTopupNote')?.value.trim() || '';

  if (!topupNumber) {
    showToast('Topup number is required', 'error');
    return;
  }

  if (!operator) {
    showToast('Operator is required', 'error');
    return;
  }

  if (amount <= 0) {
    showToast('Amount must be greater than 0', 'error');
    return;
  }

  try{
    const data = await proxyPost('topup_create', {
      topup_number: topupNumber,
      operator,
      amount,
      note
    }, 'Creating topup...');

    setBoxMessage('panelTopupOutput', 'ok', 'Topup Created', [
      `Request ID: ${data.request_id || '-'}`,
      `Number: ${data.topup_number || topupNumber}`,
      `Operator: ${data.operator || operator}`,
      `Amount: ${fmtMoney(data.amount || amount)}`,
      `Status: ${data.status || 'PENDING'}`
    ]);

    await refreshAll(false);
    openPageSection('requestLogsSection');
    showToast('Topup request created', 'ok');
  }catch(err){
    setBoxMessage('panelTopupOutput', 'error', 'Topup Failed', [
      err.message || 'Failed to create topup'
    ]);
    showToast(err.message || 'Failed to create topup', 'error');
  }
}

async function createSubadminUser(){
  const name = el('newUserName')?.value.trim() || '';
  const phone = el('newUserPhone')?.value.trim() || '';
  const phoneCountry = String(el('newUserPhoneCountry')?.value || 'BD').toUpperCase();
  const pricingCountry = String(el('newUserPricingCountry')?.value || 'BD').toUpperCase();
  const email = el('newUserEmail')?.value.trim() || '';
  const password = el('newUserPassword')?.value || '';
  const confirmPassword = el('newUserConfirmPassword')?.value || '';
  const pin = el('newUserPin')?.value.trim() || '';
  const confirmPin = el('newUserConfirmPin')?.value.trim() || '';

  if (!name || !phone || !email || !password || !confirmPassword || !pin || !confirmPin) {
    showToast('All fields are required', 'error');
    return;
  }

  if (password !== confirmPassword) {
    showToast('Password confirmation does not match', 'error');
    return;
  }

  if (pin !== confirmPin) {
    showToast('PIN confirmation does not match', 'error');
    return;
  }

  if (!/^\d{4,8}$/.test(pin)) {
    showToast('PIN must be 4 to 8 digits', 'error');
    return;
  }

  const phoneDigits = phone.replace(/\D+/g, '');
  const validPhone = phoneCountry === 'MY'
    ? /^(?:011\d{8}|01[02-9]\d{7}|6011\d{8}|601[02-9]\d{7}|11\d{8}|1[02-9]\d{7})$/.test(phoneDigits)
    : /^(?:01[3-9]\d{8}|8801[3-9]\d{8}|1[3-9]\d{8})$/.test(phoneDigits);
  if (!validPhone) {
    showToast(phoneCountry === 'MY' ? 'Invalid Malaysia number' : 'Invalid Bangladesh number', 'error');
    return;
  }

  const payload = {
    name,
    phone,
    phone_country: phoneCountry,
    pricing_country: pricingCountry,
    market_country: pricingCountry,
    email,
    password,
    confirm_password: confirmPassword,
    pin,
    confirm_pin: confirmPin
  };

  try{
    const data = await proxyPost('user_create_send_otp', payload, 'Sending create user OTP...');

    state.userCreateOtp.formData = {
      ...payload,
      phone: String(data.target_phone_e164 || data.target_phone || payload.phone || '').trim(),
      phone_country: String(data.target_phone_country || payload.phone_country || '').toUpperCase(),
      pricing_country: String(data.target_pricing_country || data.target_market_country || payload.pricing_country || '').toUpperCase()
    };
    updateCreateUserOtpModal(data);
    openCreateUserOtpModal();

    setBoxMessage('createUserOutput', 'warning', 'OTP Required', [
      'OTP পাঠানো হয়েছে। OTP verify করলে user create হবে।',
      `Phone: ${data.masked_phone || phone}`
    ]);

    showToast('Create user OTP sent', 'ok');
  }catch(err){
    setBoxMessage('createUserOutput', 'error', 'Create User Failed', [
      err.message || 'Failed to send OTP'
    ]);
    showToast(err.message || 'Failed to send OTP', 'error');
  }
}

async function runLiveApiTest(){
  const endpoint = el('liveApiEndpoint')?.value.trim() || '';
  const apiKey = el('liveApiKey')?.value.trim() || '';
  const topupNumber = el('liveTopupNumber')?.value.trim() || '';
  const operator = el('liveOperator')?.value.trim() || '';
  const amount = Number(el('liveAmount')?.value || 0);
  const note = el('liveNote')?.value.trim() || '';

  if (!endpoint) {
    showToast('API endpoint is required', 'error');
    return;
  }

  if (!apiKey) {
    showToast('Plain API key is required', 'error');
    return;
  }

  if (!topupNumber) {
    showToast('Topup number is required', 'error');
    return;
  }

  if (amount <= 0) {
    showToast('Amount must be greater than 0', 'error');
    return;
  }

  setBusy(true, 'Running live API test...');

  try{
    const res = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        topup_number: topupNumber,
        operator,
        amount,
        note
      })
    });

    const text = await res.text();
    let parsed = null;

    try{
      parsed = JSON.parse(text);
    }catch(_){}

    if (parsed && res.ok && parsed.ok) {
      setBoxMessage('liveApiOutput', 'ok', 'Live API Test Success', [
        `Request ID: ${parsed.data?.request_id || '-'}`,
        `Number: ${parsed.data?.topup_number || topupNumber}`,
        `Operator: ${parsed.data?.operator || operator}`,
        `Amount: ${fmtMoney(parsed.data?.amount || amount)}`,
        `${parsed.message || 'API request completed successfully.'}`
      ]);
      showToast('Live API test completed', 'ok');
    } else if (parsed) {
      setBoxMessage('liveApiOutput', 'error', 'Live API Test Failed', [
        parsed.message || 'API request returned an error',
        parsed.code ? `Code: ${parsed.code}` : ''
      ]);
      showToast(parsed.message || 'Live API test returned error', 'error');
    } else {
      setBoxMessage('liveApiOutput', 'warning', 'Live API Response', [
        text || 'Non-JSON response returned from API.'
      ]);
      showToast(res.ok ? 'Live API test completed' : 'Live API test returned error', res.ok ? 'ok' : 'error');
    }

    await loadLogs();
    renderSummary();
    renderLogs();
    renderPanelTopupRequests();
    openPageSection('requestLogsSection');
  }catch(err){
    setBoxMessage('liveApiOutput', 'error', 'Live API Test Failed', [
      err.message || 'Request could not be completed'
    ]);
    showToast('Live API test failed', 'error');
  }finally{
    setBusy(false);
  }
}

/* =========================
   Window exports
========================= */

window.copyText = copyText;
window.copyById = copyById;
window.updateKeyStatus = updateKeyStatus;
window.convertUserToRetailer = convertUserToRetailer;
window.viewRequestLog = viewRequestLog;
window.copyRequestId = copyRequestId;
window.openPageSection = openPageSection;
window.proxyPost = proxyPost;
window.proxyGet = proxyGet;
window.loadWallet = loadWallet;
window.loadLogs = loadLogs;
window.loadUsers = loadUsers;
window.renderSummary = renderSummary;
window.renderLogs = renderLogs;
window.renderUsers = renderUsers;
window.renderPanelTopupRequests = renderPanelTopupRequests;
window.loadMfsPanel = loadMfsPanel;
window.loadMfsSummaryPanel = loadMfsSummaryPanel;
window.loadMfsRequestsPanel = loadMfsRequestsPanel;
window.renderMfsSummary = renderMfsSummary;
window.renderMfsRows = renderMfsRows;
window.viewMfsRequest = viewMfsRequest;
window.loadBundleOffers = loadBundleOffers;
window.renderBundleOffers = renderBundleOffers;
window.useBundleOfferForTest = useBundleOfferForTest;
window.runBundleApiTest = runBundleApiTest;
window.openBundleBuyModal = openBundleBuyModal;
window.closeBundleBuyModal = closeBundleBuyModal;
window.confirmBundleBuy = confirmBundleBuy;
window.money = money;
window.showToast = showToast;
window.openAddBalanceModal = openAddBalanceModal;
window.openWalletLedgerModal = openWalletLedgerModal;
window.getUserRowByUidForWallet = getUserRowByUidForWallet;
window.askConfirm = askConfirm;
window.closeConfirmModal = closeConfirmModal;

window.openBundleCommissionModal = openBundleCommissionModal;
window.closeBundleCommissionModal = closeBundleCommissionModal;
window.saveBundleCommission = saveBundleCommission;
window.resetBundleCommission = resetBundleCommission;
window.setApiTestTab = setApiTestTab;

/* =========================
   Event binding
========================= */

el('logoutBtn')?.addEventListener('click', doLogout);
el('refreshBtn')?.addEventListener('click', () => refreshAll(true));
el('createKeyBtn')?.addEventListener('click', createKey);

el('reloadKeysBtn')?.addEventListener('click', async () => {
  await loadKeys();
  renderSummary();
  renderKeys();
  showToast('Keys reloaded', 'info');
});

el('reloadLogsBtn')?.addEventListener('click', async () => {
  await loadLogs();
  renderSummary();
  renderLogs();
  renderPanelTopupRequests();
  showToast('Logs reloaded', 'info');
});

el('reloadAddMoneyBtn')?.addEventListener('click', async () => {
  state.loaded.addMoney = false;
  await ensureAddMoneyLoaded(true);
  showToast('Add money reloaded', 'info');
});

document.addEventListener('click', (event) => {
  const btn = event.target?.closest?.('[data-copy-account-number]');
  if (!btn) return;
  copyText(btn.dataset.copyAccountNumber || '', 'Account number copied.');
});

el('copyPlainKeyBtn')?.addEventListener('click', copyLastPlainKey);
el('usePlainKeyBtn')?.addEventListener('click', useLastPlainKeyInLiveTest);

document.querySelectorAll('.side-btn').forEach(btn => {
  btn.addEventListener('click', () => openPageSection(btn.dataset.pageSection));
});


document.querySelectorAll('.api-test-tab').forEach(btn => {
  btn.addEventListener('click', () => {
    setApiTestTab(btn.dataset.apiTestTarget || 'liveApiTestPanel');
  });
});


el('runLiveApiTestBtn')?.addEventListener('click', runLiveApiTest);

el('fillLastPlainKeyBtn')?.addEventListener('click', () => {
  const key = el('lastPlainKey')?.textContent.trim() || '';
  if (!key || key === '-') {
    showToast('No last created plain key found', 'error');
    return;
  }
  if (el('liveApiKey')) el('liveApiKey').value = key;
  showToast('Last created key filled', 'ok');
});

el('clearLiveApiOutputBtn')?.addEventListener('click', () => {
  setBoxMessage('liveApiOutput', 'info', 'Ready', [
    'No test run yet.'
  ]);
});

el('sendPanelTopupBtn')?.addEventListener('click', createPanelTopup);
el('clearPanelTopupBtn')?.addEventListener('click', clearPanelTopupForm);

el('subMfsCreateBtn')?.addEventListener('click', (e) => openMfsReview(e.currentTarget));
el('subMfsClearBtn')?.addEventListener('click', clearMfsForm);
el('subMfsAmountBdt')?.addEventListener('input', () => syncSubMfsAmounts('bdt'));
el('subMfsAmountRm')?.addEventListener('input', () => syncSubMfsAmounts('rm'));
el('subMfsRefreshBtn')?.addEventListener('click', (e) => refreshMfsSummaryFromButton(e.currentTarget, true));
el('subMfsListRefreshBtn')?.addEventListener('click', (e) => refreshMfsRequestsFromButton(e.currentTarget, true));
el('subMfsApplyFilterBtn')?.addEventListener('click', (e) => refreshMfsRequestsFromButton(e.currentTarget, true));

document.querySelectorAll('.sub-mfs-tab').forEach(btn => {
  btn.addEventListener('click', async () => {
    setSubMfsTab(btn.dataset.mfsTab || 'pending');
    await refreshMfsRequestsFromButton(btn, true);
  });
});

function handleSubMfsViewClick(e){
  const receiptBtn = e.target?.closest?.('.sub-mfs-receipt-btn');
  if (receiptBtn) {
    const url = receiptBtn.dataset.receiptUrl || '';
    if (url) window.open(url, '_blank', 'noopener');
    return;
  }

  const btn = e.target?.closest?.('.sub-mfs-view-btn');
  if (!btn) return;
  viewMfsRequest(btn.dataset.mfsRequest || '', btn);
}

el('subMfsTableBody')?.addEventListener('click', handleSubMfsViewClick);
el('subMfsMobileList')?.addEventListener('click', handleSubMfsViewClick);

el('loadBundleOffersBtn')?.addEventListener('click', loadBundleOffers);
el('runBundleApiTestBtn')?.addEventListener('click', runBundleApiTest);

el('clearBundleApiOutputBtn')?.addEventListener('click', () => {
  setBoxMessage('bundleApiOutput', 'info', 'Ready', [
    'No bundle API test run yet.'
  ]);
});

document.querySelectorAll('.log-filter-btn').forEach(btn => {
  btn.addEventListener('click', () => setRequestLogFilter(btn.dataset.logFilter));
});

el('closeLogModalBtn')?.addEventListener('click', closeLogModal);
el('closeLogModalBtn2')?.addEventListener('click', closeLogModal);

el('logModalWrap')?.addEventListener('click', (e) => {
  if (e.target.id === 'logModalWrap') {
    closeLogModal();
  }
});

el('reloadUsersBtn')?.addEventListener('click', async () => {
  await loadUsers();
  renderUsers();
  showToast('Users reloaded', 'info');
});

el('closeAddBalanceModalBtn')?.addEventListener('click', closeAddBalanceModal);
el('submitAddBalanceBtn')?.addEventListener('click', submitAddBalance);
el('cancelAddBalanceBtn')?.addEventListener('click', closeAddBalanceModal);

el('addBalanceModalWrap')?.addEventListener('click', (e) => {
  if (e.target.id === 'addBalanceModalWrap') {
    closeAddBalanceModal();
  }
});

el('closeWalletLedgerModalBtn')?.addEventListener('click', closeWalletLedgerModal);
el('closeWalletLedgerModalBtn2')?.addEventListener('click', closeWalletLedgerModal);
el('transferHistoryBtn')?.addEventListener('click', openTransferHistoryModal);
el('reloadTransferHistoryBtn')?.addEventListener('click', loadTransferHistory);
el('closeTransferHistoryModalBtn')?.addEventListener('click', closeTransferHistoryModal);
el('closeTransferHistoryModalBtn2')?.addEventListener('click', closeTransferHistoryModal);

el('reloadWalletLedgerBtn')?.addEventListener('click', async () => {
  const uid = state.walletLedger.targetUid;
  if (!uid) return;

  try{
    await loadWalletLedger(uid);
    showToast('Wallet ledger reloaded', 'info');
  }catch(err){
    showToast(err.message || 'Failed to reload wallet ledger', 'error');
  }
});

el('walletLedgerModalWrap')?.addEventListener('click', (e) => {
  if (e.target.id === 'walletLedgerModalWrap') {
    closeWalletLedgerModal();
  }
});

el('usersRoleFilter')?.addEventListener('change', async () => {
  await loadUsers();
  renderUsers();
});

el('usersStatusFilter')?.addEventListener('change', async () => {
  await loadUsers();
  renderUsers();
});

el('createUserBtn')?.addEventListener('click', createSubadminUser);
el('clearCreateUserBtn')?.addEventListener('click', clearCreateUserForm);

el('verifyCreateUserOtpBtn')?.addEventListener('click', confirmCreateUserOtp);
el('resendCreateUserOtpBtn')?.addEventListener('click', resendCreateUserOtp);
el('cancelCreateUserOtpBtn')?.addEventListener('click', closeCreateUserOtpModal);
el('closeCreateUserOtpModalBtn')?.addEventListener('click', closeCreateUserOtpModal);

el('createUserOtpCode')?.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    confirmCreateUserOtp();
  }
});

el('createUserOtpModalWrap')?.addEventListener('click', (e) => {
  if (e.target && e.target.id === 'createUserOtpModalWrap') {
    closeCreateUserOtpModal();
  }
});

el('closePanelBundleBuyModalBtn')?.addEventListener('click', closeBundleBuyModal);
el('panelBundleCancelBtn')?.addEventListener('click', closeBundleBuyModal);
el('panelBundleSubmitBtn')?.addEventListener('click', confirmBundleBuy);

el('panelBundleBuyModalWrap')?.addEventListener('click', (e) => {
  if (e.target && e.target.id === 'panelBundleBuyModalWrap') {
    closeBundleBuyModal();
  }
});

el('panelBundleNumberInput')?.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    confirmBundleBuy();
  }
});

/* =========================
   Bootstrap
========================= */

(async function bootstrap(){
  injectDashboardLazyScrollStyle();
  upgradeOutputBoxes();

  clearPanelTopupForm();
  clearMfsForm();
  setBoxMessage('subMfsDetailsOutput', 'info', 'Ready', [
    'Select a request to view details.'
  ]);
  clearCreateUserForm();
  resetAddBalanceState();
  resetWalletLedgerState();
  resetCreateUserOtpState();
  ensureBundlePanelUi();

  setBoxMessage('liveApiOutput', 'info', 'Ready', [
    'No test run yet.'
  ]);

  setBoxMessage('bundleOffersOutput', 'info', 'Ready', [
    'Open Bundle Offers or click Refresh Offers to load active offers.'
  ]);

  setBoxMessage('bundleApiOutput', 'info', 'Ready', [
    'No bundle API test run yet.'
  ]);

  setBoxMessage('panelBundleBuyOutput', 'info', 'Ready', [
    'No bundle request created yet.'
  ]);

  renderBundleOffers();
  renderMfsSummary();
  renderMfsRows();

  try{
    await loadMe();
    showApp();

    await ensureWalletLoaded(true);
    setRequestLogFilter('ALL');

    setTimeout(() => {
      loadSectionData('overviewSection', false).catch(() => {});
    }, 150);
  }catch(_){
    showLogin();
  }
})();
