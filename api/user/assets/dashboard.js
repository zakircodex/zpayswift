const USER_PROXY_URL = window.USER_PROXY_URL || 'proxy.php';

const state = {
  csrf: '',
  me: null,
  walletSummary: null,
  requestLogs: [],
  bundleOffers: [],
  busyCount: 0,
  filter: 'ALL',

  bundleBuy: {
    offerId: '',
    row: null
  },

  loginOtp: {
    preAuthToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300,
    trustDevice: true
  }
};

const wizard = {
  step: 1,
  operator: '',
  amount: ''
};

let bundleLazyTimer = null;
let bundleRenderToken = 0;

const BUNDLE_FIRST_RENDER_COUNT = 1;
const BUNDLE_RENDER_CHUNK_SIZE = 1;
const BUNDLE_RENDER_DELAY_MS = 120;

/* =========================
   Basic Helpers
========================= */

function el(id){
  return document.getElementById(id);
}

function firstExistingEl(ids){
  for (const id of ids) {
    const node = el(id);
    if (node) return node;
  }
  return null;
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
  const n = Number(v || 0);
  return Number.isFinite(n) ? n.toFixed(2) : '0.00';
}

function fmtMoney(v, prefix = 'BDT'){
  return `${prefix} ${money(v)}`;
}

function walletPrefix(currency){
  return String(currency || 'BDT').toUpperCase() === 'MYR' ? 'RM' : 'BDT';
}

function walletDisplayAmount(wallet, type = 'available'){
  const key = type === 'hold' ? 'display_hold_balance' : 'display_available_balance';
  const fallback = type === 'hold' ? 'hold_balance' : 'available_balance';
  return money(wallet?.[key] ?? wallet?.[fallback] ?? 0);
}

function walletDisplayCurrency(wallet){
  return walletPrefix(wallet?.display_currency || wallet?.wallet_currency || wallet?.currency || 'BDT');
}

function fmtTs(ts){
  const num = Number(ts || 0);
  if (!num) return '-';

  const ms = String(Math.trunc(num)).length <= 10 ? num * 1000 : num;
  const d = new Date(ms);

  return isNaN(d.getTime()) ? '-' : d.toLocaleString();
}

function operatorName(code){
  const t = String(code || '').toUpperCase().trim();

  const map = {
    GP: 'Grameenphone',
    GRAMEENPHONE: 'Grameenphone',
    ROBI: 'Robi',
    AIRTEL: 'Airtel',
    BL: 'Banglalink',
    BANGLALINK: 'Banglalink',
    TT: 'Teletalk',
    TELETALK: 'Teletalk'
  };

  return map[t] || code || '-';
}

function requestTypeOf(row){
  return String(row?.request_type || row?.type || row?.action || 'TOPUP').toUpperCase();
}

function requestNumberOf(row){
  return String(row?.bundle_number || row?.topup_number || row?.receiver_number || row?.number || '');
}

function amountPrefixOf(){
  return 'BDT';
}

function isMfsRow(row){
  return requestTypeOf(row) === 'MFS';
}

function mfsProviderLabel(row){
  const provider = String(row?.provider_name || row?.provider || row?.mfs_provider || '').toUpperCase();
  if (provider === 'BKASH') return 'bKash';
  if (provider === 'NAGAD') return 'Nagad';
  return row?.provider_name || row?.provider || row?.mfs_provider || 'MFS';
}

function mfsIsRemittance(row){
  return String(row?.service_mode || '').toUpperCase() === 'REMITTANCE'
    || String(row?.country_code || row?.country || '').toUpperCase() === 'MY'
    || Number(row?.amount_rm ?? row?.amount_myr ?? 0) > 0;
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

function mfsFeeRm(row){
  let value = Number(row?.fee_rm ?? row?.fee_myr ?? 0);
  const rate = mfsRate(row);
  if (value <= 0 && String(row?.fee_currency || '').toUpperCase() === 'MYR') value = Number(row?.fee_amount || 0);
  if (value <= 0 && rate > 0 && Number(row?.fee_bdt || 0) > 0) value = Number(row.fee_bdt || 0) / rate;
  return value;
}

function mfsTotalRm(row){
  let value = Number(row?.total_debit_rm ?? row?.total_pay_myr ?? 0);
  const rate = mfsRate(row);
  if (value <= 0) value = mfsAmountRm(row) + mfsFeeRm(row);
  if (value <= 0 && String(row?.wallet_currency || '').toUpperCase() === 'MYR') value = Number(row?.total_debit ?? row?.total_pay ?? row?.amount ?? 0);
  if (value <= 0 && rate > 0 && Number(row?.total_debit ?? row?.total_pay ?? 0) > 0) value = Number(row?.total_debit ?? row?.total_pay ?? 0) / rate;
  return value;
}

function mfsTotalBdt(row){
  let value = Number(row?.total_debit_bdt ?? row?.total_pay_bdt ?? 0);
  if (value <= 0) value = Number(row?.total_debit ?? row?.total_pay ?? row?.amount ?? 0);
  if (value <= 0) value = Number(row?.amount_bdt ?? row?.amount ?? 0) + Number(row?.fee_bdt ?? 0);
  return value;
}

function mfsDisplayText(row){
  if (mfsIsRemittance(row)) {
    return `Received: BDT ${money(row?.amount_bdt || 0)} | Send: RM ${money(mfsAmountRm(row))} | Fee: RM ${money(mfsFeeRm(row))} | Total Paid: RM ${money(mfsTotalRm(row))}`;
  }

  return `Amount: BDT ${money(row?.amount_bdt ?? row?.amount ?? 0)} | Fee: BDT ${money(row?.fee_bdt || 0)} | Total Paid: BDT ${money(mfsTotalBdt(row))}`;
}

function isSessionError(err){
  const code = String(err?.code || '').toUpperCase();
  const msg = String(err?.message || '').toLowerCase();

  return (
    code === 'SESSION_EXPIRED' ||
    code === 'UNAUTHORIZED' ||
    code === 'AUTH_ERROR' ||
    (code === 'FORBIDDEN' && msg.includes('session')) ||
    msg.includes('session expired') ||
    msg.includes('session not found')
  );
}

function clearBundleLazyTimer(){
  if (bundleLazyTimer) {
    clearTimeout(bundleLazyTimer);
    bundleLazyTimer = null;
  }

  bundleRenderToken++;
}

function getBundlePrice(row){
  return Number(
    row?.price_amount ??
    row?.offer_price ??
    row?.price ??
    row?.amount ??
    0
  );
}

function getBundleUserCommission(row){
  return Number(
    row?.user_commission ??
    row?.customer_commission ??
    row?.user_discount ??
    0
  );
}

function getBundleYouPay(row){
  const price = getBundlePrice(row);
  const userCommission = getBundleUserCommission(row);

  return Number(
    row?.you_pay ??
    row?.payable_amount ??
    row?.wallet_hold_amount ??
    row?.net_cost_after_commission ??
    Math.max(0, price - userCommission)
  );
}

function getBundleValidity(row){
  const name = String(
    row?.bundle_name ||
    row?.name ||
    row?.title ||
    ''
  ).toUpperCase();

  const nameMatches = [...name.matchAll(/(\d+(?:\.\d+)?)\s*(DAY|DAYS|MONTH|MONTHS|HOUR|HOURS|MINUTE|MINUTES|D)\b/g)];

  if (nameMatches.length) {
    const last = nameMatches[nameMatches.length - 1];
    let unit = last[2];

    if (unit === 'D') unit = 'DAY';
    if (unit === 'DAYS') unit = 'DAY';
    if (unit === 'MONTHS') unit = 'MONTH';
    if (unit === 'HOURS') unit = 'HOUR';
    if (unit === 'MINUTES') unit = 'MINUTE';

    return `${last[1]} ${unit}`;
  }

  const direct = String(
    row?.package_validity ||
    row?.bundle_validity ||
    row?.validity_text ||
    row?.package_duration ||
    ''
  ).trim();

  if (direct) {
    const directText = direct.toUpperCase();
    const directMatches = [...directText.matchAll(/(\d+(?:\.\d+)?)\s*(DAY|DAYS|MONTH|MONTHS|HOUR|HOURS|MINUTE|MINUTES|D)\b/g)];

    if (directMatches.length) {
      const last = directMatches[directMatches.length - 1];
      let unit = last[2];

      if (unit === 'D') unit = 'DAY';
      if (unit === 'DAYS') unit = 'DAY';
      if (unit === 'MONTHS') unit = 'MONTH';
      if (unit === 'HOURS') unit = 'HOUR';
      if (unit === 'MINUTES') unit = 'MINUTE';

      return `${last[1]} ${unit}`;
    }

    return directText;
  }

  const durationValue = Number(row?.duration_value || 0);
  const durationUnit = String(row?.duration_unit || '').trim().toUpperCase();

  if (durationValue > 0 && durationUnit !== '') {
    let unit = durationUnit;

    if (unit === 'D') unit = 'DAY';
    if (unit === 'DAYS') unit = 'DAY';
    if (unit === 'MONTHS') unit = 'MONTH';
    if (unit === 'HOURS') unit = 'HOUR';
    if (unit === 'MINUTES') unit = 'MINUTE';

    return `${durationValue} ${unit}`;
  }

  const seconds = Number(row?.duration_seconds || 0);

  if (seconds > 0) {
    const days = Math.round(seconds / 86400);
    const hours = Math.round(seconds / 3600);

    if (days > 0) return `${days} DAY`;
    if (hours > 0) return `${hours} HOUR`;
  }

  return '-';
}

function getBundleExpiry(row){
  const expiresAt = Number(row?.expires_at || 0);
  return expiresAt ? fmtTs(expiresAt) : 'No expiry';
}

/* =========================
   Status / Toast / Busy
========================= */

function statusClass(v){
  const t = String(v || '').toUpperCase();

  if (['SUCCESS','SUCCESSFUL','ACTIVE','VERIFIED','COMPLETED','APPROVED','DONE'].includes(t)) return 'success';

  if ([
    'FAILED',
    'DISABLED',
    'REVOKED',
    'INACTIVE',
    'SMS_FAILED',
    'REJECTED',
    'CANCELLED',
    'LOCKED'
  ].includes(t)) return 'danger';

  if ([
    'PENDING',
    'CLAIMED',
    'PROCESSING',
    'DIALING',
    'OTP_REQUIRED',
    'RESENT',
    'WAITING',
    'WAITING_ADMIN',
    'OTP_PENDING'
  ].includes(t)) return 'warning';

  return 'info';
}

function statusPill(v){
  return `<span class="pill ${statusClass(v)}">${esc(v || '-')}</span>`;
}

function setBusy(on, text = 'Loading...'){
  const wrap = el('loadingWrap');
  const txt = el('loadingText');

  if (!wrap || !txt) return;

  if (on) {
    state.busyCount++;
    txt.textContent = text || 'Loading...';
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

function setLoginError(msg = ''){
  const box = el('loginError');
  if (!box) return;

  if (!msg) {
    box.classList.remove('show');
    box.textContent = '';
    return;
  }

  box.classList.add('show');
  box.textContent = msg;
}

function showLogin(){
  document.body.classList.remove('user-authenticated');

  el('loginView')?.classList.remove('hidden');
  el('appView')?.classList.add('hidden');
}

function showApp(){
  document.body.classList.add('user-authenticated');

  el('loginView')?.classList.add('hidden');
  el('appView')?.classList.remove('hidden');
}

/* =========================
   Proxy Requests
========================= */

async function readJsonSafe(res){
  const text = await res.text();

  if (!text || !text.trim()) {
    throw new Error('Empty response from server');
  }

  try{
    return JSON.parse(text);
  }catch(_){
    throw new Error(text.length > 500 ? text.slice(0, 500) : text);
  }
}

async function proxyGet(action, params = {}, busyText = 'Loading...', options = {}){
  const useBusy = options.busy !== false;

  if (useBusy) {
    setBusy(true, busyText);
  }

  try{
    const qs = new URLSearchParams(params).toString();
    const url = USER_PROXY_URL + '?action=' + encodeURIComponent(action) + (qs ? '&' + qs : '');

    const res = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Cache-Control': 'no-cache'
      }
    });

    const json = await readJsonSafe(res);

    if (!res.ok || !json.ok) {
      const err = new Error(json.message || 'Request failed');
      err.code = json.code || 'ERROR';
      err.data = json.data || {};
      err.status = res.status;
      throw err;
    }

    return json.data || {};
  } finally {
    if (useBusy) {
      setBusy(false);
    }
  }
}

async function proxyPost(action, body = {}, busyText = 'Processing...', options = {}){
  const useBusy = options.busy !== false;

  if (useBusy) {
    setBusy(true, busyText);
  }

  try{
    const headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Cache-Control': 'no-cache'
    };

    if (state.csrf) {
      headers['X-CSRF-TOKEN'] = state.csrf;
    }

    const res = await fetch(USER_PROXY_URL + '?action=' + encodeURIComponent(action), {
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
      err.status = res.status;
      throw err;
    }

    return json.data || {};
  } finally {
    if (useBusy) {
      setBusy(false);
    }
  }
}

async function copyText(text, okMessage = 'Copied'){
  try{
    await navigator.clipboard.writeText(String(text || ''));
    showToast(okMessage, 'ok');
  }catch(_){
    showToast('Copy failed. Please copy manually.', 'error');
  }
}

/* =========================
   Navigation
========================= */

function openSidebar(){
  el('sidebar')?.classList.add('show');
  el('sidebarOverlay')?.classList.add('show');
}

function closeSidebar(){
  el('sidebar')?.classList.remove('show');
  el('sidebarOverlay')?.classList.remove('show');
}

function getInitialSection(){
  const p = (window.location.pathname || '').replace(/\/+$/,'').toLowerCase();

  if (p === '/user/topup') return 'topupSection';
  if (p === '/user/bundle' || p === '/user/bundles') return 'bundleSection';
  if (p === '/user/history') return 'historySection';

  return 'overviewSection';
}

function openSection(sectionId){
  document.body.setAttribute('data-active-section', sectionId || 'overviewSection');

  document.querySelectorAll('.page-section').forEach(node => node.classList.remove('active'));
  document.querySelectorAll('.side-btn').forEach(node => node.classList.remove('active'));
  document.querySelectorAll('.bottom-btn').forEach(node => node.classList.remove('active'));

  el(sectionId)?.classList.add('active');

  document.querySelector(`.side-btn[data-page-section="${sectionId}"]`)?.classList.add('active');
  document.querySelector(`.bottom-btn[data-page-section="${sectionId}"]`)?.classList.add('active');

  if (window.innerWidth <= 980) {
    closeSidebar();
  }

  if (sectionId === 'topupSection') {
    updateWizardUI();
  }

  if (sectionId === 'bundleSection') {
    if (!state.bundleOffers.length) {
      loadBundleOffers().catch(() => {});
    } else {
      renderBundleOffers();
    }
  }

  const main = document.querySelector('.main-panel');
  if (main) {
    main.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

/* =========================
   Fast Loaders
========================= */

function applyDashboardBootstrap(data){
  state.me = data.user || state.me || null;
  state.csrf = data.csrf || state.csrf || '';

  if (data.wallet_summary && typeof data.wallet_summary === 'object') {
    state.walletSummary = data.wallet_summary;
  }

  const logWrap = data.request_logs || {};
  if (Array.isArray(logWrap.items)) {
    state.requestLogs = logWrap.items;
  }
}

async function loadDashboardBootstrap(showBusy = true, busyText = 'Checking session...'){
  const data = await proxyGet(
    'dashboard_bootstrap',
    { limit: 100 },
    busyText,
    { busy: showBusy }
  );

  applyDashboardBootstrap(data);
  return data;
}

async function loadMe(options = {}){
  const data = await proxyGet(
    'me',
    {},
    options.busyText || 'Checking session...',
    { busy: options.busy !== false }
  );

  state.me = data.user || null;
  state.csrf = data.csrf || '';
}

async function loadWalletSummary(options = {}){
  state.walletSummary = await proxyGet(
    'wallet_summary',
    {},
    options.busyText || 'Loading wallet...',
    { busy: options.busy !== false }
  );
}

async function loadRequestLogs(options = {}){
  const data = await proxyGet(
    'request_logs',
    { limit: 100 },
    options.busyText || 'Loading history...',
    { busy: options.busy !== false }
  );

  state.requestLogs = Array.isArray(data.items) ? data.items : [];
}

async function loadInitialDashboard(showBusy = true, busyText = 'Checking session...'){
  try{
    await loadDashboardBootstrap(showBusy, busyText);
    renderAll();
    return;
  }catch(err){
    if (isSessionError(err)) {
      throw err;
    }

    await loadMe({
      busy: showBusy,
      busyText: 'Checking session...'
    });

    await Promise.all([
      loadWalletSummary({ busy: false }),
      loadRequestLogs({ busy: false })
    ]);

    renderAll();
  }
}

async function refreshAll(showMessage = false){
  await loadInitialDashboard(true, showMessage ? 'Refreshing dashboard...' : 'Checking session...');

  if (el('bundleSection')?.classList.contains('active')) {
    await loadBundleOffers().catch(err => {
      if (isSessionError(err)) throw err;
    });
  }

  if (showMessage) {
    showToast('Dashboard refreshed', 'info');
  }
}

async function safeRefreshAll(showMessage = false){
  try{
    await refreshAll(showMessage);
  }catch(err){
    if (isSessionError(err)) {
      showLogin();
      setLoginError('Session expired. Please login again.');
      return;
    }

    showToast(err.message || 'Refresh failed', 'error');
  }
}


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

function renderAll(){
  renderHero();
  renderSummary();
  renderHistory();
  hideBundleSummaryBoxes();
  if (typeof window.zpayMfsRefreshCurrencyUi === 'function') {
    window.zpayMfsRefreshCurrencyUi();
  }

  if (el('bundleSection')?.classList.contains('active')) {
    renderBundleOffers();
  }
}

function renderHero(){
  const me = state.me || {};
  const data = state.walletSummary || {};
  const wallet = data.wallet || {};
  const roleSettings = data.role_settings || {};

  if (el('heroStatusText')) {
    el('heroStatusText').textContent = String(me.status || data.status || 'ACTIVE').toUpperCase();
  }

  if (el('heroBalancePrefix')) el('heroBalancePrefix').textContent = walletDisplayCurrency(wallet);
  if (el('heroHoldPrefix')) el('heroHoldPrefix').textContent = walletDisplayCurrency(wallet);
  if (el('heroBalance')) {
    el('heroBalance').textContent = walletDisplayAmount(wallet, 'available');
    el('heroBalance').title = wallet.conversion_note || '';
  }
  if (el('heroHold')) {
    el('heroHold').textContent = walletDisplayAmount(wallet, 'hold');
    el('heroHold').title = wallet.conversion_note || '';
  }
  if (el('heroRequests')) el('heroRequests').textContent = String((state.requestLogs || []).length);
  if (el('heroRole')) el('heroRole').textContent = String(me.role || data.role || '-').toUpperCase();

  if (el('heroTopupAccess')) {
    el('heroTopupAccess').textContent = 'Topup: ' + (roleSettings.topup_enabled ? 'Yes' : 'No');
  }

  if (el('heroBundleAccess')) {
    el('heroBundleAccess').textContent = 'Bundle: ' + (roleSettings.bundle_enabled ? 'Yes' : 'No');
  }
}

function renderSummary(){
  const me = state.me || {};
  const data = state.walletSummary || {};
  const wallet = data.wallet || {};
  const roleSettings = data.role_settings || {};

  if (el('meName')) el('meName').textContent = me.name || data.name || '-';
  if (el('mePhone')) el('mePhone').textContent = me.phone || data.phone || '-';
  if (el('meEmail')) el('meEmail').textContent = me.email || data.email || '-';
  if (el('meRole')) el('meRole').textContent = String(me.role || data.role || '-').toUpperCase();
  if (el('meStatus')) el('meStatus').textContent = String(me.status || data.status || '-').toUpperCase();
  if (el('meLastLogin')) el('meLastLogin').textContent = fmtTs(data.last_login_at || me.last_login_at || 0);
  if (el('meCommission')) el('meCommission').textContent = money(roleSettings.commission_per_1000 || 0);
  if (el('meApiEnabled')) el('meApiEnabled').textContent = roleSettings.api_enabled ? 'Yes' : 'No';
  if (el('meTopupEnabled')) el('meTopupEnabled').textContent = roleSettings.topup_enabled ? 'Yes' : 'No';
  if (el('meBundleEnabled')) el('meBundleEnabled').textContent = roleSettings.bundle_enabled ? 'Yes' : 'No';
  if (el('meAmountLimits')) el('meAmountLimits').textContent = money(roleSettings.min_amount || 0) + ' - ' + money(roleSettings.max_amount || 0);
  if (el('meWalletUpdated')) el('meWalletUpdated').textContent = fmtTs(wallet.updated_at || roleSettings.updated_at || 0);

  if (el('sideMeName')) el('sideMeName').textContent = me.name || data.name || '-';
  if (el('sideMeRole')) el('sideMeRole').textContent = String(me.role || data.role || '-').toUpperCase();
  if (el('sideMeStatus')) el('sideMeStatus').textContent = String(me.status || data.status || '-').toUpperCase();
}

function hideBundleSummaryBoxes(){
  const ids = [
    'bundleOfferCount',
    'bundleAccessText',
    'bundleAvailableBalance',
    'bundleHoldBalance'
  ];

  for (const id of ids) {
    const node = el(id);
    if (!node) continue;

    const grid = node.closest('.summary-grid');
    if (grid) {
      grid.classList.add('hidden');
      grid.style.display = 'none';
    }
  }
}

function hideBundleStatus(){
  const box = el('bundleOfferStatus');
  if (!box) return;

  box.classList.add('hidden');
  box.textContent = '';
}

function setBundleStatus(type, message){
  const box = el('bundleOfferStatus');
  if (!box) return;

  box.classList.remove('hidden');
  box.className = 'bundle-result-box ' + (type || '');
  box.textContent = message || '';
}

/* =========================
   History
========================= */

function statusMatchesFilter(row, filter){
  const status = String(row.status || '').toUpperCase();
  const type = requestTypeOf(row);

  if (filter === 'ALL') return true;

  if (filter === 'TOPUP') return type === 'TOPUP';
  if (filter === 'BUNDLE') return type === 'BUNDLE';

  if (filter === 'PENDING') {
    return ['PENDING','CLAIMED','PROCESSING','DIALING','WAITING','WAITING_ADMIN'].includes(status);
  }

  if (filter === 'SUCCESS') {
    return ['SUCCESS','COMPLETED','APPROVED','DONE'].includes(status);
  }

  if (filter === 'FAILED') {
    return ['FAILED','REJECTED','CANCELLED'].includes(status);
  }

  return status === filter;
}

function getFilteredHistory(){
  const rows = [...(state.requestLogs || [])];

  rows.sort((a,b) => {
    const aa = Number(a.updated_at || a.completed_at || a.created_at || 0);
    const bb = Number(b.updated_at || b.completed_at || b.created_at || 0);
    return bb - aa;
  });

  return rows.filter(row => statusMatchesFilter(row, state.filter));
}

function cleanHistoryMessage(row){
  const msg = String(row?.message || '').trim();
  if (!msg) return '';

  const lower = msg.toLowerCase();

  const hidePhrases = [
    'marked as success by admin',
    'marked as failed by admin',
    'marked as rejected by admin',
    'bundle marked as success by admin',
    'bundle marked as failed by admin',
    'topup marked as success by admin',
    'topup marked as failed by admin',
    'marked by admin',
    'manually'
  ];

  if (hidePhrases.some(p => lower.includes(p))) {
    return '';
  }

  return msg;
}

function historyMessageHtml(row){
  const msg = cleanHistoryMessage(row);
  if (!msg) return '';

  return `<div class="history-message">${esc(msg)}</div>`;
}

function detailMessageText(row){
  const msg = cleanHistoryMessage(row);
  if (msg) return msg;

  const status = String(row?.status || '').toUpperCase();

  if (['SUCCESS','COMPLETED','APPROVED','DONE'].includes(status)) {
    return 'Request completed successfully.';
  }

  if (['PENDING','WAITING','WAITING_ADMIN','CLAIMED','PROCESSING','DIALING'].includes(status)) {
    return 'Request is processing.';
  }

  if (['FAILED','REJECTED','CANCELLED'].includes(status)) {
    return 'Request failed.';
  }

  return 'No message';
}

function renderHistory(){
  const list = el('historyList');
  if (!list) return;

  const rows = getFilteredHistory();

  if (!rows.length) {
    list.innerHTML = `
      <div class="history-item">
        <div class="history-id">No history found.</div>
      </div>
    `;
    return;
  }

  list.innerHTML = rows.map(item => {
    const type = requestTypeOf(item);
    const number = requestNumberOf(item);
    const prefix = amountPrefixOf(item);
    const isMfs = type === 'MFS';

    const displayAmount = type === 'BUNDLE'
      ? (item.you_pay ?? item.payable_amount ?? item.net_cost_after_commission ?? item.amount ?? 0)
      : (item.amount || 0);

    const metaHtml = isMfs
      ? `
          <div class="mini"><label>Service</label><strong>${esc(mfsProviderLabel(item))}</strong></div>
          <div class="mini"><label>Receiver</label><strong>${esc(number || '-')}</strong></div>
          <div class="mini"><label>Amount</label><strong>${esc(mfsDisplayText(item))}</strong></div>
          <div class="mini"><label>Created</label><strong>${esc(fmtTs(item.created_at || 0))}</strong></div>
        `
      : `
          <div class="mini"><label>Operator</label><strong>${esc(operatorName(item.operator || '-'))}</strong></div>
          <div class="mini"><label>Number</label><strong>${esc(number || '-')}</strong></div>
          <div class="mini"><label>Amount</label><strong>${esc(prefix)} ${money(displayAmount)}</strong></div>
          <div class="mini"><label>Created</label><strong>${esc(fmtTs(item.created_at || 0))}</strong></div>
        `;

    return `
      <div class="history-item">
        <div class="history-top">
          <div>
            <div class="history-id">${esc(item.request_id || '-')}</div>
            <div class="history-small">${esc(type)} Request${isMfs ? ' - ' + esc(mfsProviderLabel(item)) : ''}</div>
          </div>
          ${statusPill(item.status || '-')}
        </div>

        <div class="history-meta">
          ${metaHtml}
        </div>

        ${
          type === 'BUNDLE'
            ? `
              <div class="history-meta history-meta-extra">
                <div class="mini"><label>Bundle</label><strong>${esc(item.bundle_name || '-')}</strong></div>
                <div class="mini"><label>Offer ID</label><strong>${esc(item.offer_id || '-')}</strong></div>
                <div class="mini"><label>User Commission</label><strong>BDT ${money(item.user_commission || item.customer_commission || item.user_discount || 0)}</strong></div>
                <div class="mini"><label>You Pay</label><strong>BDT ${money(item.you_pay || item.payable_amount || item.net_cost_after_commission || item.amount || 0)}</strong></div>
              </div>
            `
            : ''
        }

        ${historyMessageHtml(item)}

        <div class="history-actions">
          <button class="btn blue sm" type="button" onclick="openHistoryDetail('${esc(item.request_id || '')}')">View</button>
          <button class="btn ghost sm" type="button" onclick="copyHistoryId('${esc(item.request_id || '')}')">Copy ID</button>
          ${item.receipt_url ? `<button class="btn green sm" type="button" onclick="openReceiptLink('${esc(item.receipt_url)}')">Receipt</button>` : ''}
        </div>
      </div>
    `;
  }).join('');
}

window.copyHistoryId = function(requestId){
  copyText(requestId, 'Request ID copied');
};

window.openReceiptLink = function(url){
  url = String(url || '').trim();
  if (!url) {
    showToast('Receipt link not available', 'error');
    return;
  }
  window.open(url, '_blank', 'noopener');
};

window.openHistoryDetail = function(requestId){
  const row = (state.requestLogs || []).find(x => String(x.request_id || '') === String(requestId || ''));

  if (!row) {
    showToast('Request not found', 'error');
    return;
  }

  const type = requestTypeOf(row);
  const number = requestNumberOf(row);
  const prefix = amountPrefixOf(row);
  const isMfs = type === 'MFS';

  const displayAmount = type === 'BUNDLE'
    ? (row.you_pay ?? row.payable_amount ?? row.net_cost_after_commission ?? row.amount ?? 0)
    : (row.amount || 0);

  if (el('detailRequestId')) el('detailRequestId').textContent = row.request_id || '-';
  if (el('detailStatus')) el('detailStatus').innerHTML = statusPill(row.status || '-');
  if (el('detailType')) el('detailType').textContent = type;
  if (el('detailSource')) el('detailSource').textContent = row.source || row.request_source || 'USER_PANEL';
  const operatorLabel = el('detailOperator')?.parentElement?.querySelector('label');
  if (operatorLabel) operatorLabel.textContent = isMfs ? 'Service' : 'Operator';
  if (el('detailOperator')) el('detailOperator').textContent = isMfs ? mfsProviderLabel(row) : operatorName(row.operator || '-');
  if (el('detailNumber')) el('detailNumber').textContent = number || '-';
  if (el('detailAmount')) el('detailAmount').textContent = isMfs ? mfsDisplayText(row) : prefix + ' ' + money(displayAmount);
  if (el('detailCreated')) el('detailCreated').textContent = fmtTs(row.created_at || 0);
  if (el('detailUpdated')) el('detailUpdated').textContent = fmtTs(row.updated_at || 0);
  if (el('detailCompleted')) el('detailCompleted').textContent = fmtTs(row.completed_at || 0);

  let msg = detailMessageText(row);

  if (type === 'BUNDLE') {
    msg +=
      '\nBundle: ' + (row.bundle_name || '-') +
      '\nOffer ID: ' + (row.offer_id || '-') +
      '\nUser Commission: BDT ' + money(row.user_commission || row.customer_commission || row.user_discount || 0) +
      '\nYou Pay: BDT ' + money(row.you_pay || row.payable_amount || row.net_cost_after_commission || row.amount || 0);
  }

  if (row.receipt_url) {
    msg += '\nReceipt: ' + row.receipt_url;
  }

  if (el('detailMessage')) el('detailMessage').textContent = msg;

  el('detailModal')?.classList.add('show');
};

function closeHistoryDetail(){
  el('detailModal')?.classList.remove('show');
}

function setHistoryFilter(filter){
  state.filter = String(filter || 'ALL').toUpperCase();

  document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.filter === state.filter);
  });

  renderHistory();
}

/* =========================
   Bundle Offers
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
    row
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
    row: null
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
Status: ${esc(data.status || 'WAITING_ADMIN')}
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
Status: ${data.status || 'WAITING_ADMIN'}
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
    const data = await proxyPost('bundle_create_panel', {
      offer_id: offerId,
      bundle_number: bundleNumber,
      pin,
      note: note || 'Bundle request from user panel'
    }, 'Creating bundle request...');

    renderBundleBuyOutputSuccess(data);
    renderBundleResultSuccess(data);
    applyBundleCreateSuccessToLocalState(data);
    showToast('Bundle request created successfully', 'ok');
    
    setTimeout(() => {
        closeBundleBuyModal();
        openSection('historySection');
    }, 500);
  }catch(err){
    renderBundleBuyOutputError(err.message || 'Failed to create bundle request');
    showToast(err.message || 'Failed to create bundle request', 'error');
  }
}


/* =========================
   MFS bKash / Nagad
========================= */

const mfsState = {
  provider: ''
};

function mfsCurrencyPrefix(){
  const wallet = (state.walletSummary || {}).wallet || {};
  const currency = String(wallet.display_currency || wallet.currency || wallet.wallet_currency || state.walletSummary?.wallet_currency || '').toUpperCase();
  return currency === 'MYR' ? 'RM' : 'BDT';
}

function setMfsProvider(provider){
  mfsState.provider = String(provider || '').toUpperCase();

  document.querySelectorAll('.mfs-provider-choice').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.provider === mfsState.provider);
  });

  renderMfsPreview();
}

function mfsFormData(){
  return {
    provider: mfsState.provider,
    receiver_number: (el('mfsReceiverNumber')?.value || '').trim(),
    amount_bdt: Number(el('mfsAmountBdt')?.value || 0),
    amount_rm: Number(el('mfsAmountRm')?.value || 0),
    reference: (el('mfsReference')?.value || '').trim(),
    pin: (el('mfsPin')?.value || '').trim()
  };
}

function renderMfsPreview(){
  const box = el('mfsPreviewBox');
  if (!box) return;

  const data = mfsFormData();
  const providerName = data.provider === 'BKASH' ? 'bKash' : data.provider === 'NAGAD' ? 'Nagad' : '-';
  const wallet = (state.walletSummary || {}).wallet || {};
  const prefix = mfsCurrencyPrefix();

  box.className = 'bundle-result-box';
  box.textContent =
`Provider: ${providerName}
Number: ${data.receiver_number || '-'}
Received Amount: BDT ${money(data.amount_bdt || 0)}
Send Amount: RM ${money(data.amount_rm || 0)}
Reference: ${data.reference || '-'}
Available Balance: ${prefix} ${walletDisplayAmount(wallet, 'available')}
Status: PENDING`;
}

function mfsTrackingUrl(data){
  return String(data?.tracking_url || data?.receipt_url || data?.request_url || '');
}

function ensureMfsResultModal(){
  if (el('mfsCreateResultModal')) return;

  const wrap = document.createElement('div');
  wrap.id = 'mfsCreateResultModal';
  wrap.className = 'modal';
  wrap.innerHTML = `
    <div class="modal-card">
      <button id="closeMfsCreateResultModalBtn" class="modal-close" type="button">×</button>
      <h3 class="modal-title" id="mfsCreateResultTitle">MFS Request</h3>
      <p class="modal-sub" id="mfsCreateResultSub">Request details</p>
      <div id="mfsCreateResultBody" class="result-card"></div>
      <div class="wizard-actions">
        <button id="mfsCopyTrackingBtn" class="btn blue" type="button">Copy Link</button>
        <button id="mfsOpenTrackingBtn" class="btn green" type="button">Open Receipt</button>
        <button id="mfsCreateResultOkBtn" class="btn ghost" type="button">OK</button>
      </div>
    </div>
  `;

  document.body.appendChild(wrap);

  const close = () => wrap.classList.remove('show');
  el('closeMfsCreateResultModalBtn')?.addEventListener('click', close);
  el('mfsCreateResultOkBtn')?.addEventListener('click', close);
  wrap.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'mfsCreateResultModal') close();
  });
}

function showMfsResultModal({title = 'MFS Request', subtitle = '', rows = [], link = '', type = 'info'} = {}){
  ensureMfsResultModal();

  const wrap = el('mfsCreateResultModal');
  const titleNode = el('mfsCreateResultTitle');
  const subNode = el('mfsCreateResultSub');
  const body = el('mfsCreateResultBody');
  const copyBtn = el('mfsCopyTrackingBtn');
  const openBtn = el('mfsOpenTrackingBtn');

  if (titleNode) titleNode.textContent = title;
  if (subNode) subNode.textContent = subtitle || '';
  if (body) {
    body.className = 'result-card ' + (type === 'error' ? 'bad' : 'good');
    const rowsHtml = rows
      .filter(row => Array.isArray(row) && row.length >= 2)
      .map(row => `
        <div style="display:flex;gap:12px;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.08);">
          <label style="color:#9fb5d8;font-weight:900;">${esc(row[0])}</label>
          <strong style="text-align:right;word-break:break-word;">${esc(row[1] || '-')}</strong>
        </div>
      `).join('');

    body.innerHTML = `
      ${rowsHtml || `<div class="result-text">${esc(subtitle || 'No details available.')}</div>`}
      ${link ? `<div style="margin-top:12px;"><label style="display:block;color:#9fb5d8;font-weight:900;margin-bottom:6px;">Receipt / Tracking Link</label><div style="word-break:break-all;color:#dbeafe;">${esc(link)}</div></div>` : ''}
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

  wrap?.classList.add('show');
}

function showMfsErrorModal(title, message){
  showMfsResultModal({
    title: title || 'MFS Error',
    subtitle: message || 'Something went wrong',
    type: 'error',
    rows: [['Message', message || 'Something went wrong']]
  });
}

function renderMfsResultSuccess(data){
  const box = el('mfsResult');

  const providerName = data.provider_name || (data.provider === 'BKASH' ? 'bKash' : 'Nagad');
  const isRemit = String(data.service_mode || '').toUpperCase() === 'REMITTANCE'
    || String(data.country_code || '').toUpperCase() === 'MY'
    || Number(data.amount_rm || data.amount_myr || 0) > 0;
  const rate = Number(data.exchange_rate ?? data.rate_myr_to_bdt ?? 0);
  let amountRm = Number(data.amount_rm ?? data.amount_myr ?? 0);
  if (isRemit && amountRm <= 0 && rate > 0 && Number(data.amount_bdt || 0) > 0) {
    amountRm = Number(data.amount_bdt || 0) / rate;
  }
  let feeRm = Number(data.fee_rm ?? data.fee_myr ?? 0);
  if (isRemit && feeRm <= 0 && rate > 0 && Number(data.fee_bdt || 0) > 0) {
    feeRm = Number(data.fee_bdt || 0) / rate;
  }
  const totalRm = Number(data.total_debit_rm ?? data.total_pay_myr ?? 0) || amountRm + feeRm;
  const totalBdt = Number(data.total_debit_bdt ?? data.total_pay_bdt ?? data.total_debit ?? 0);
  const amountLines = isRemit
    ? `Received Amount: BDT ${money(data.amount_bdt || 0)}
Send Amount: RM ${money(amountRm)}
Fee: RM ${money(feeRm)}
Total Paid: RM ${money(totalRm)}`
    : `Amount: BDT ${money(data.amount_bdt || 0)}
Fee: BDT ${money(data.fee_bdt || 0)}
Total Paid: BDT ${money(totalBdt)}`;

  if (box) {
    box.className = 'result-empty';
    box.textContent = '';
  }

  showMfsResultModal({
    title: 'Request Created Successfully',
    subtitle: 'Admin approval pending. Use this link to track the request.',
    type: 'success',
    link: mfsTrackingUrl(data),
    rows: [
      ['Request ID', data.request_id || '-'],
      ['Provider', providerName],
      ['Receiver Number', data.receiver_number || data.number || '-'],
      ['Amount BDT', `BDT ${money(data.amount_bdt || 0)}`],
      ...(isRemit ? [['Amount RM', `RM ${money(amountRm)}`]] : []),
      ['Fee', isRemit ? `RM ${money(feeRm)}` : `BDT ${money(data.fee_bdt || 0)}`],
      ['Total Pay/Hold', isRemit ? `RM ${money(totalRm)}` : `BDT ${money(totalBdt)}`],
      ['Status', data.status || 'PENDING'],
      ['Reference', data.reference || '-']
    ]
  });
}

function renderMfsResultError(message){
  const box = el('mfsResult');
  if (box) {
    box.className = 'result-empty';
    box.textContent = '';
  }

  showMfsErrorModal('Request Failed', message || 'Unknown error');
}

function applyMfsCreateSuccessToLocalState(data){
  if (!data || typeof data !== 'object') return;

  if (!state.walletSummary) state.walletSummary = {};
  if (!state.walletSummary.wallet) state.walletSummary.wallet = {};

  if (data.wallet && typeof data.wallet === 'object') {
    const walletCurrency = data.wallet.currency || data.wallet.wallet_currency || data.wallet_currency || '';
    state.walletSummary.wallet = {
      ...(state.walletSummary.wallet || {}),
      available_balance: Number(data.wallet.available_balance || 0),
      hold_balance: Number(data.wallet.hold_balance || 0),
      currency: walletCurrency,
      wallet_currency: walletCurrency,
      display_currency: data.wallet.display_currency || walletCurrency,
      display_available_balance: Number(data.wallet.display_available_balance ?? data.wallet.available_balance ?? 0),
      display_hold_balance: Number(data.wallet.display_hold_balance ?? data.wallet.hold_balance ?? 0),
      available_balance_bdt: Number(data.wallet.available_balance_bdt ?? data.wallet.available_balance ?? 0),
      hold_balance_bdt: Number(data.wallet.hold_balance_bdt ?? data.wallet.hold_balance ?? 0),
      available_balance_myr: Number(data.wallet.available_balance_myr ?? 0),
      hold_balance_myr: Number(data.wallet.hold_balance_myr ?? 0),
      rate_myr_bdt: Number(data.wallet.rate_myr_bdt ?? 0),
      conversion_note: data.wallet.conversion_note || '',
      updated_at: Number(data.created_at || Math.floor(Date.now() / 1000))
    };
  }

  const requestId = String(data.request_id || '').trim();

  if (requestId) {
    state.requestLogs = (state.requestLogs || []).filter(row => String(row.request_id || '') !== requestId);

    state.requestLogs.unshift({
      request_id: requestId,
      action: 'MFS',
      request_type: 'MFS',
      source: 'USER_PANEL',
      request_source: 'USER_PANEL',
      status: data.status || 'PENDING',
      provider: data.provider || '',
      provider_name: data.provider_name || '',
      service_type: data.service_type || 'SEND_MONEY',
      service_name: data.service_name || 'Send Money',
      receiver_number: data.receiver_number || data.number || '',
      number: data.receiver_number || data.number || '',
      wallet_currency: data.wallet_currency || '',
      country_code: data.country_code || '',
      service_mode: data.service_mode || '',
      amount: Number(data.total_debit || 0),
      amount_bdt: Number(data.amount_bdt || 0),
      amount_rm: Number(data.amount_rm || 0),
      fee_bdt: Number(data.fee_bdt || 0),
      fee_rm: Number(data.fee_rm || 0),
      total_debit: Number(data.total_debit || 0),
      total_debit_bdt: Number(data.total_debit_bdt || 0),
      total_debit_rm: Number(data.total_debit_rm || 0),
      exchange_rate: Number(data.exchange_rate || 0),
      receipt_id: data.receipt_id || '',
      receipt_url: data.receipt_url || '',
      tracking_url: data.tracking_url || data.receipt_url || '',
      receipt_created_at: Number(data.receipt_created_at || 0),
      reference: data.reference || '',
      trxid: data.trxid || '',
      message: 'MFS request created',
      created_at: Number(data.created_at || Math.floor(Date.now() / 1000)),
      updated_at: Number(data.created_at || Math.floor(Date.now() / 1000)),
      completed_at: 0
    });
  }

  renderHero();
  renderSummary();
  renderHistory();
  if (typeof window.zpayMfsRefreshCurrencyUi === 'function') {
    window.zpayMfsRefreshCurrencyUi();
  }
}

async function submitMfsRequest(){
  const data = mfsFormData();

  if (!data.provider) {
    showToast('Please select bKash or Nagad', 'error');
    return;
  }

  if (!/^01\d{9}$/.test(data.receiver_number.replace(/\D+/g, ''))) {
    showToast('Receiver number must be 11 digit BD number', 'error');
    return;
  }

  if (data.amount_bdt <= 0 && data.amount_rm <= 0) {
    showToast('Amount is required', 'error');
    return;
  }

  if (!data.pin) {
    showToast('PIN is required', 'error');
    return;
  }

  try{
    const res = await proxyPost('mfs_create', {
      provider: data.provider,
      service_type: 'SEND_MONEY',
      account_type: 'PERSONAL',
      receiver_number: data.receiver_number,
      amount_bdt: data.amount_bdt,
      amount_rm: data.amount_rm,
      reference: data.reference,
      pin: data.pin,
      note: 'MFS request from user panel'
    }, 'Creating request...');

    renderMfsResultSuccess(res);
    applyMfsCreateSuccessToLocalState(res);
    showToast('Request created successfully', 'ok');

    setTimeout(() => openSection('historySection'), 600);
  }catch(err){
    renderMfsResultError(err.message || 'Failed to create request');
    showToast(err.message || 'Failed to create request', 'error');
  }
}



/* =========================
   Topup Wizard
========================= */

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

  document.querySelectorAll('.operator-choice').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.operator === value);
  });
}

function setWizardAmount(value){
  wizard.amount = String(value || '');

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
    if (el('reviewPin')) el('reviewPin').textContent = data.pin ? '••••' : '-';
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

  if (el('wizardTopupNumber')) el('wizardTopupNumber').value = '';
  if (el('wizardAmount')) el('wizardAmount').value = '';
  if (el('wizardPin')) el('wizardPin').value = '';

  document.querySelectorAll('.operator-choice,.amount-choice').forEach(btn => {
    btn.classList.remove('active');
  });

  updateWizardUI();
}

function renderTopupResultSuccess(data){
  const box = el('topupResult');
  if (!box) return;

  box.className = '';
  box.innerHTML = `
    <div class="result-card good">
      <div class="result-title">Topup request created successfully</div>
      <div class="result-text">
Request ID: ${esc(data.request_id || '-')}
Status: ${esc(data.status || 'PENDING')}
Number: ${esc(data.topup_number || '-')}
Operator: ${esc(operatorName(data.operator || '-'))}
Amount: BDT ${money(data.amount || 0)}
Created: ${esc(fmtTs(data.created_at || 0))}
      </div>
    </div>
  `;
}

function renderTopupResultError(message){
  const box = el('topupResult');
  if (!box) return;

  box.className = '';
  box.innerHTML = `
    <div class="result-card bad">
      <div class="result-title">Topup failed</div>
      <div class="result-text">${esc(message || 'Unknown error')}</div>
    </div>
  `;
}

async function submitTopup(){
  if (!validateWizardStep(1) || !validateWizardStep(2) || !validateWizardStep(3) || !validateWizardStep(4)) {
    return;
  }

  const data = wizardData();

  try{
    const res = await proxyPost('topup_create', {
      topup_number: data.topup_number,
      operator: data.operator,
      amount: Number(data.amount),
      pin: data.pin,
      note: 'Topup request from user dashboard'
    }, 'Creating topup...');

    renderTopupResultSuccess(res);
    showToast('Topup created successfully', 'ok');

    await safeRefreshAll(false);
    resetWizard();
    openSection('historySection');
  }catch(err){
    renderTopupResultError(err.message || 'Failed to create topup');
    showToast(err.message || 'Failed to create topup', 'error');
  }
}

/* =========================
   Login OTP Flow
========================= */

function resetLoginOtpState(){
  state.loginOtp = {
    preAuthToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300,
    trustDevice: true
  };

  if (el('loginOtpMaskedPhone')) el('loginOtpMaskedPhone').textContent = '-';
  if (el('loginOtpExpiresText')) el('loginOtpExpiresText').textContent = '5 minutes';
  if (el('loginOtpCode')) el('loginOtpCode').value = '';
  if (el('loginOtpStatus')) el('loginOtpStatus').textContent = 'OTP পাঠানোর পরে এখানে status দেখাবে।';
}

function updateLoginOtpModal(data){
  state.loginOtp.preAuthToken = String(data.pre_auth_token || '');
  state.loginOtp.otpRequestId = String(data.otp_request_id || '');
  state.loginOtp.maskedPhone = String(data.masked_phone || '');
  state.loginOtp.expiresInSeconds = Number(data.expires_in_seconds || 300);

  if (el('loginOtpMaskedPhone')) {
    el('loginOtpMaskedPhone').textContent = state.loginOtp.maskedPhone || '-';
  }

  if (el('loginOtpExpiresText')) {
    const sec = state.loginOtp.expiresInSeconds;
    el('loginOtpExpiresText').textContent = sec >= 60 ? Math.ceil(sec / 60) + ' minutes' : sec + ' seconds';
  }

  if (el('loginOtpCode')) {
    el('loginOtpCode').value = '';
  }

  if (el('loginOtpStatus')) {
    el('loginOtpStatus').textContent =
      'OTP sent to ' + (state.loginOtp.maskedPhone || 'your phone') + '. Enter the code to complete login.';
  }
}

function openLoginOtpModal(){
  el('loginOtpModal')?.classList.add('show');
}

function closeLoginOtpModal(){
  el('loginOtpModal')?.classList.remove('show');
  resetLoginOtpState();
}

async function doLogin(){
  setLoginError('');

  const phone = (el('loginPhone')?.value || '').trim();
  const password = el('loginPassword')?.value || '';
  const trustDevice = !!el('rememberTrustedDevice')?.checked;

  if (!phone || !password) {
    setLoginError('Phone and password are required.');
    return;
  }

  state.loginOtp.trustDevice = trustDevice;

  try{
    const data = await proxyPost('login', {
      phone,
      password,
      trust_device: trustDevice,
      device_id: 'USER_WEB',
      device_name: 'User Dashboard'
    }, 'Logging in...');

    if (data.require_otp) {
      updateLoginOtpModal(data);
      openLoginOtpModal();
      showToast('OTP sent for login verification', 'ok');
      return;
    }

    state.me = data.user || null;
    state.csrf = data.csrf || '';

    showApp();
    await refreshAll(false);
    openSection(getInitialSection());
    showToast('Login successful', 'ok');
  }catch(err){
    setLoginError(err.message || 'Login failed');
  }
}

async function verifyLoginOtp(){
  const otp = (el('loginOtpCode')?.value || '').trim();

  if (!state.loginOtp.preAuthToken || !state.loginOtp.otpRequestId) {
    if (el('loginOtpStatus')) {
      el('loginOtpStatus').textContent = 'Login verification session missing. Please login again.';
    }
    return;
  }

  if (!otp) {
    if (el('loginOtpStatus')) {
      el('loginOtpStatus').textContent = 'Please enter the OTP first.';
    }
    return;
  }

  try{
    const data = await proxyPost('login_verify_otp', {
      pre_auth_token: state.loginOtp.preAuthToken,
      otp_request_id: state.loginOtp.otpRequestId,
      otp,
      trust_device: state.loginOtp.trustDevice,
      device_id: 'USER_WEB',
      device_name: 'User Dashboard'
    }, 'Verifying OTP...');

    if (el('loginOtpStatus')) {
      el('loginOtpStatus').textContent = 'OTP verified successfully. Logging in...';
    }

    state.me = data.user || null;
    state.csrf = data.csrf || '';

    closeLoginOtpModal();
    showApp();
    await refreshAll(false);
    openSection(getInitialSection());
    showToast('Login successful', 'ok');
  }catch(err){
    if (el('loginOtpStatus')) {
      el('loginOtpStatus').textContent = err.message || 'OTP verification failed.';
    }

    showToast(err.message || 'OTP verification failed', 'error');
  }
}

async function resendLoginOtp(){
  if (!state.loginOtp.preAuthToken || !state.loginOtp.otpRequestId) {
    if (el('loginOtpStatus')) {
      el('loginOtpStatus').textContent = 'Login verification session missing. Please login again.';
    }
    return;
  }

  try{
    const data = await proxyPost('login_resend_otp', {
      pre_auth_token: state.loginOtp.preAuthToken,
      otp_request_id: state.loginOtp.otpRequestId
    }, 'Resending OTP...');

    updateLoginOtpModal({
      pre_auth_token: data.pre_auth_token || state.loginOtp.preAuthToken,
      otp_request_id: data.otp_request_id || state.loginOtp.otpRequestId,
      masked_phone: data.masked_phone || state.loginOtp.maskedPhone,
      expires_in_seconds: data.expires_in_seconds || 300
    });

    if (el('loginOtpStatus')) {
      el('loginOtpStatus').textContent =
        'OTP resent successfully to ' + (state.loginOtp.maskedPhone || 'your phone') + '.';
    }

    showToast('OTP resent successfully', 'ok');
  }catch(err){
    if (el('loginOtpStatus')) {
      el('loginOtpStatus').textContent = err.message || 'Failed to resend OTP.';
    }

    showToast(err.message || 'Failed to resend OTP', 'error');
  }
}

async function doLogout(){
  try{
    await proxyPost('logout', {}, 'Logging out...');
  }catch(_){}

  state.me = null;
  state.csrf = '';
  state.walletSummary = null;
  state.requestLogs = [];
  state.bundleOffers = [];
  state.bundleBuy = {
    offerId: '',
    row: null
  };
  state.filter = 'ALL';

  clearBundleLazyTimer();
  resetWizard();
  resetLoginOtpState();

  if (el('topupResult')) {
    el('topupResult').className = 'result-empty';
    el('topupResult').textContent = 'No topup created yet.';
  }

  if (el('bundleResult')) {
    el('bundleResult').className = 'result-empty';
    el('bundleResult').textContent = '';
  }

  const bundleWrap = el('bundleOffersGrid') || el('bundleOfferCards');
  if (bundleWrap) {
    bundleWrap.innerHTML = `
      <div class="bundle-empty">
        <strong>No bundle offers loaded</strong>
        <span>Click Refresh Offers to load active offers.</span>
      </div>
    `;
  }

  if (el('loginPhone')) el('loginPhone').value = '';
  if (el('loginPassword')) el('loginPassword').value = '';

  setLoginError('');

  showLogin();
  closeSidebar();
  closeHistoryDetail();
  closeBundleBuyModal();
  el('loginOtpModal')?.classList.remove('show');

  showToast('Logged out', 'info');
}

/* =========================
   Events
========================= */

function bindNavigationButtons(){
  document.querySelectorAll('.side-btn').forEach(btn => {
    if (btn.dataset.bound === '1') return;

    btn.dataset.bound = '1';
    btn.addEventListener('click', () => openSection(btn.dataset.pageSection));
  });

  document.querySelectorAll('.bottom-btn').forEach(btn => {
    if (btn.dataset.bound === '1') return;

    btn.dataset.bound = '1';
    btn.addEventListener('click', () => openSection(btn.dataset.pageSection));
  });
}

function bindBundleDelegatedEvents(){
  if (window.__zpayBundleClickFixBound) return;
  window.__zpayBundleClickFixBound = true;

  document.addEventListener('click', function(e){
    const target = e.target;
    if (!target || !target.closest) return;

    const buyBtn = target.closest('.bundle-buy-btn');
    if (buyBtn) {
      e.preventDefault();
      e.stopPropagation();

      const offerId =
        buyBtn.getAttribute('data-offer-id') ||
        buyBtn.getAttribute('data-bundle-offer-id') ||
        buyBtn.dataset.offerId ||
        buyBtn.dataset.bundleOfferId ||
        '';

      if (!offerId) {
        showToast('Offer ID missing', 'error');
        return;
      }

      openBundleBuyModal(offerId);
      return;
    }

    if (target.closest('#confirmBundleBuyBtn') || target.closest('#submitBundleBuyBtn')) {
      e.preventDefault();
      e.stopPropagation();
      submitBundleBuy();
      return;
    }

    if (target.closest('#cancelBundleBuyBtn') || target.closest('#closeBundleBuyModalBtn')) {
      e.preventDefault();
      e.stopPropagation();
      closeBundleBuyModal();
      return;
    }

    if (target.id === 'bundleBuyModal') {
      e.preventDefault();
      closeBundleBuyModal();
    }
  });
}

function bindEvents(){
  el('loginBtn')?.addEventListener('click', doLogin);

  el('loginPassword')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') doLogin();
  });

  el('loginPhone')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') el('loginPassword')?.focus();
  });

  el('openSidebarBtn')?.addEventListener('click', openSidebar);
  el('sidebarOverlay')?.addEventListener('click', closeSidebar);

  el('quickRefreshBtn')?.addEventListener('click', () => safeRefreshAll(true));
  el('desktopRefreshBtn')?.addEventListener('click', () => safeRefreshAll(true));
  el('sidebarRefreshBtn')?.addEventListener('click', () => safeRefreshAll(true));
  el('sidebarLogoutBtn')?.addEventListener('click', doLogout);

  bindNavigationButtons();
  bindBundleDelegatedEvents();

  document.querySelectorAll('.filter-btn').forEach(btn => {
    if (btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => setHistoryFilter(btn.dataset.filter || 'ALL'));
  });

  document.querySelectorAll('.operator-choice').forEach(btn => {
    if (btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => setWizardOperator(btn.dataset.operator || ''));
  });

  document.querySelectorAll('.amount-choice').forEach(btn => {
    if (btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => setWizardAmount(btn.dataset.amount || ''));
  });

  el('wizardAmount')?.addEventListener('input', function(){
    wizard.amount = String(this.value || '');

    document.querySelectorAll('.amount-choice').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.amount === wizard.amount);
    });
  });

  el('wizardNext1')?.addEventListener('click', () => {
    if (validateWizardStep(1)) gotoWizardStep(2);
  });

  el('wizardNext2')?.addEventListener('click', () => {
    if (validateWizardStep(2)) gotoWizardStep(3);
  });

  el('wizardNext3')?.addEventListener('click', () => {
    if (validateWizardStep(3)) gotoWizardStep(4);
  });

  el('wizardNext4')?.addEventListener('click', () => {
    if (validateWizardStep(4)) gotoWizardStep(5);
  });

  el('wizardBack2')?.addEventListener('click', () => gotoWizardStep(1));
  el('wizardBack3')?.addEventListener('click', () => gotoWizardStep(2));
  el('wizardBack4')?.addEventListener('click', () => gotoWizardStep(3));
  el('wizardBack5')?.addEventListener('click', () => gotoWizardStep(4));
  el('wizardConfirmBtn')?.addEventListener('click', submitTopup);

  el('wizardTopupNumber')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && validateWizardStep(1)) gotoWizardStep(2);
  });

  el('wizardAmount')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && validateWizardStep(3)) gotoWizardStep(4);
  });

  el('wizardPin')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && validateWizardStep(4)) gotoWizardStep(5);
  });

  el('refreshBundleOffersBtn')?.addEventListener('click', () => loadBundleOffers().catch(() => {}));

  const bundleNumberInput = firstExistingEl(['bundleBuyNumberInput', 'bundleBuyNumber']);
  const bundlePinInput = firstExistingEl(['bundleBuyPinInput', 'bundleBuyPin']);

  bundleNumberInput?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') bundlePinInput?.focus();
  });

  bundlePinInput?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') submitBundleBuy();
  });

  el('verifyLoginOtpBtn')?.addEventListener('click', verifyLoginOtp);
  el('resendLoginOtpBtn')?.addEventListener('click', resendLoginOtp);
  el('cancelLoginOtpBtn')?.addEventListener('click', closeLoginOtpModal);
  el('closeLoginOtpModalBtn')?.addEventListener('click', closeLoginOtpModal);

  el('loginOtpCode')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') verifyLoginOtp();
  });

  el('loginOtpModal')?.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'loginOtpModal') {
      closeLoginOtpModal();
    }
  });

  el('closeDetailModalBtn')?.addEventListener('click', closeHistoryDetail);

  el('detailModal')?.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'detailModal') {
      closeHistoryDetail();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      closeSidebar();
      closeHistoryDetail();
      closeBundleBuyModal();
      el('loginOtpModal')?.classList.remove('show');
    }
  });
  
  
  document.querySelectorAll('.mfs-provider-choice').forEach(btn => {
  if (btn.dataset.bound === '1') return;
  btn.dataset.bound = '1';
  btn.addEventListener('click', () => setMfsProvider(btn.dataset.provider || ''));
});

['mfsReceiverNumber','mfsAmountBdt','mfsAmountRm','mfsReference'].forEach(id => {
  el(id)?.addEventListener('input', renderMfsPreview);
});

el('mfsPreviewBtn')?.addEventListener('click', renderMfsPreview);
el('mfsSubmitBtn')?.addEventListener('click', submitMfsRequest);
  
  
}

/* =========================
   Bootstrap
========================= */

async function bootstrap(){
  bindEvents();

  try{
    await loadInitialDashboard(true, 'Checking session...');
  }catch(err){
    setBusy(false);
    showLogin();

    if (isSessionError(err)) {
      setLoginError('');
    } else {
      setLoginError(err.message || '');
    }

    return;
  }

  showApp();
  renderAll();
  openSection(getInitialSection());
}

bootstrap();
