const USER_PROXY_URL = window.USER_PROXY_URL || '/api/user/proxy.php';

const state = {
  csrf: '',
  me: null,
  walletSummary: null,
  requestLogs: [],
  historyMonth: currentMonthKey(),
  historyLoaded: false,
  historyLoading: false,
  historyVisited: false,
  historyLimit: 50,
  walletHistory: [],
  addMoneyProfile: null,
  addMoneyHistory: [],
  addMoneyLoaded: false,
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
    expiresAt: 0,
    timer: null,
    trustDevice: true
  }
};

window.userState = state;

const wizard = {
  step: 1,
  operator: '',
  amount: ''
};

let bundleLazyTimer = null;
let bundleRenderToken = 0;
let userBackHandlingReady = false;
let userBackRestoring = false;
let userBackExitAllowed = false;

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
  const pricingCountry = String(
    wallet?.pricing_country ||
    wallet?.market_country ||
    wallet?.service_country ||
    state.me?.pricing_country ||
    state.me?.market_country ||
    state.me?.service_country ||
    ''
  ).toUpperCase();
  if (pricingCountry === 'MY') return 'RM';
  if (pricingCountry === 'BD') return 'BDT';
  return walletPrefix(wallet?.display_currency || wallet?.wallet_currency || wallet?.currency || 'BDT');
}

function fmtTs(ts){
  const num = Number(ts || 0);
  if (!num) return '-';

  const ms = String(Math.trunc(num)).length <= 10 ? num * 1000 : num;
  const d = new Date(ms);

  return isNaN(d.getTime()) ? '-' : d.toLocaleString();
}

function currentMonthKey(date = new Date()){
  const d = date instanceof Date ? date : new Date(date);
  if (isNaN(d.getTime())) return currentMonthKey(new Date());

  const month = String(d.getMonth() + 1).padStart(2, '0');
  return `${d.getFullYear()}-${month}`;
}

function normalizeMonthKey(value){
  const month = String(value || '').trim();
  return /^\d{4}-\d{2}$/.test(month) ? month : currentMonthKey();
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
  if (String(row?._history_type || '').toUpperCase() === 'ADD_MONEY') return 'ADD_MONEY';
  if (String(row?._history_type || '').toUpperCase() === 'WALLET') return 'WALLET';
  if (row?.transfer_id && String(row?.direction || '').toUpperCase() === 'CREDIT') return 'WALLET';
  return String(row?.request_type || row?.type || row?.action || 'TOPUP').toUpperCase();
}

function requestNumberOf(row){
  return String(row?.bundle_number || row?.topup_number || row?.receiver_number || row?.number || '');
}

function amountPrefixOf(row){
  if (requestTypeOf(row) === 'ADD_MONEY') {
    return walletPrefix(row?.currency || row?.wallet_currency || 'BDT');
  }
  if (requestTypeOf(row) === 'WALLET') {
    return walletPrefix(row?.currency || row?.wallet_currency || 'BDT');
  }
  return 'BDT';
}

function isMfsRow(row){
  return requestTypeOf(row) === 'MFS';
}

function mfsProviderLabel(row){
  const provider = String(row?.provider_name || row?.provider || row?.mfs_provider || '').toUpperCase();
  if (provider === 'BKASH') return 'bKash';
  if (provider === 'NAGAD') return 'Nagad';
  return row?.provider_name || row?.provider || row?.mfs_provider || 'Send Money';
}

function mfsIsRemittance(row){
  const mode = String(row?.service_mode || '').toUpperCase();
  const country = String(
    row?.pricing_country || row?.market_country || row?.service_country || row?.country_code || row?.country || ''
  ).toUpperCase();
  if (country === 'BD' && (!mode || mode === 'LOCAL')) return false;
  if (country === 'MY' || mode === 'REMITTANCE') return true;
  return Number(row?.amount_rm ?? row?.amount_myr ?? 0) > 0;
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
    'WAITING_APPROVAL',
    'ADMIN_PENDING',
    'OTP_PENDING'
  ].includes(t)) return 'warning';

  return 'info';
}

function userStatusLabel(v){
  const t = String(v || '').trim().toUpperCase();

  if (['PENDING','WAITING','WAITING_ADMIN','WAITING_APPROVAL','ADMIN_PENDING'].includes(t)) {
    return 'Pending';
  }

  if (['CLAIMED','PROCESSING','DIALING'].includes(t)) {
    return 'Processing';
  }

  if (['SUCCESS','SUCCESSFUL','COMPLETED','APPROVED','DONE'].includes(t)) {
    return 'Successful';
  }

  if (['FAILED','REJECTED','CANCELLED'].includes(t)) {
    return 'Failed';
  }

  if (!t || t === '-') return '-';

  return t
    .toLowerCase()
    .replace(/_/g, ' ')
    .replace(/\b\w/g, letter => letter.toUpperCase());
}

function statusPill(v){
  return `<span class="pill ${statusClass(v)}">${esc(userStatusLabel(v))}</span>`;
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

function syncUserModalLock(){
  const hasOpenModal = !!document.querySelector('.modal.show, #mfsStepAmount.active, #mfsStepPreview.active, #mfsStepPin.active');
  document.body.classList.toggle('flow-modal-open', hasOpenModal);
}

function showModalById(id){
  el(id)?.classList.add('show');
  syncUserModalLock();
}

function hideModalById(id){
  el(id)?.classList.remove('show');
  syncUserModalLock();
}

function userHistoryUrl(){
  return (window.location.pathname || '/user/') + (window.location.search || '');
}

function userFlowHistoryState(flow = 'dashboard', step = 'guard'){
  return {
    ...(window.history.state || {}),
    zpayUserFlow: { flow, step }
  };
}

function pushUserFlowHistory(flow, step){
  if (!userBackHandlingReady || userBackRestoring || !window.history?.pushState) return;
  window.history.pushState(userFlowHistoryState(flow, step), '', userHistoryUrl());
}

function replaceUserFlowHistory(flow = 'dashboard', step = 'guard'){
  if (!userBackHandlingReady || userBackRestoring || !window.history?.replaceState) return;
  window.history.replaceState(userFlowHistoryState(flow, step), '', userHistoryUrl());
}

function ensureUserExitModal(){
  if (el('userExitModal')) return;

  const wrap = document.createElement('div');
  wrap.id = 'userExitModal';
  wrap.className = 'modal';
  wrap.innerHTML = `
    <div class="modal-card">
      <button id="closeUserExitModalBtn" class="modal-close" type="button">&times;</button>
      <h3 class="modal-title">Exit Z-Pay Swift?</h3>
      <p class="modal-sub">Do you want to leave the user dashboard?</p>
      <div class="wizard-actions">
        <button id="stayUserDashboardBtn" class="btn green" type="button">Stay</button>
        <button id="exitUserDashboardBtn" class="btn ghost" type="button">Exit</button>
      </div>
    </div>
  `;
  document.body.appendChild(wrap);

  const stay = () => hideModalById('userExitModal');
  el('closeUserExitModalBtn')?.addEventListener('click', stay);
  el('stayUserDashboardBtn')?.addEventListener('click', stay);
  el('exitUserDashboardBtn')?.addEventListener('click', () => {
    userBackExitAllowed = true;
    hideModalById('userExitModal');
    window.history.go(-2);
    setTimeout(() => {
      if (document.visibilityState === 'visible') {
        window.location.assign('/user/');
      }
    }, 900);
  });
}

function showUserExitModal(){
  ensureUserExitModal();
  showModalById('userExitModal');
}

function handleUserPopState(event){
  if (userBackExitAllowed) return;

  const flowState = event.state?.zpayUserFlow || {};
  const flow = String(flowState.flow || 'dashboard');
  const step = String(flowState.step || 'base');

  userBackRestoring = true;
  try {
    if (flow === 'topup' && ['operator','amount','pin','review'].includes(step)) {
      showTopupFlowStep(step, { fromHistory: true });
      return;
    }

    if (flow === 'mfs' && typeof window.zpayShowMfsHistoryStep === 'function') {
      window.zpayShowMfsHistoryStep(step);
      return;
    }

    let closedFlow = false;
    if (el('topupFlowModal')?.classList.contains('show')) {
      closeTopupFlowModal({ fromHistory: true });
      closedFlow = true;
    }
    if (typeof window.zpayCloseMfsFlow === 'function') {
      const mfsWasOpen = !!document.querySelector('#mfsStepAmount.active, #mfsStepPreview.active, #mfsStepPin.active');
      window.zpayCloseMfsFlow({ fromHistory: true });
      if (mfsWasOpen) {
        closedFlow = true;
      }
    }
    if (closedFlow) return;
  } finally {
    userBackRestoring = false;
  }

  showUserExitModal();
  window.history.pushState(userFlowHistoryState('dashboard', 'guard'), '', userHistoryUrl());
}

function initUserBackHandling(){
  if (userBackHandlingReady || !window.history?.pushState) return;

  userBackHandlingReady = true;
  window.history.replaceState(userFlowHistoryState('dashboard', 'base'), '', userHistoryUrl());
  window.history.pushState(userFlowHistoryState('dashboard', 'guard'), '', userHistoryUrl());
  window.addEventListener('popstate', handleUserPopState);
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

  const countrySelect = el('loginPhoneCountry');
  if (countrySelect && countrySelect.dataset.defaultsLoaded !== '1') {
    countrySelect.dataset.defaultsLoaded = '1';
    loadLoginCountryDefault();
  }
}

function showApp(){
  document.body.classList.add('user-authenticated');

  el('loginView')?.classList.add('hidden');
  el('appView')?.classList.remove('hidden');
  initUserBackHandling();
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
  if (p === '/user/add-money') return 'addMoneySection';
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

  if (sectionId === 'historySection') {
    if (!state.historyVisited) {
      state.historyVisited = true;
      setHistoryFilter('ALL', { skipRender: true });
    }

    ensureHistoryLoaded({ force: !state.historyLoaded }).catch(err => {
      if (isSessionError(err)) {
        showLogin();
        setLoginError('Session expired. Please login again.');
        return;
      }

      showToast(err.message || 'Failed to load history', 'error');
    });

  }

  if (sectionId === 'addMoneySection') {
    loadAddMoneyPage({ force: !state.addMoneyLoaded }).catch(err => {
      if (isSessionError(err)) {
        showLogin();
        setLoginError('Session expired. Please login again.');
        return;
      }

      showToast(err.message || 'Failed to load add money', 'error');
    });
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
  if (logWrap.month) {
    state.historyMonth = normalizeMonthKey(logWrap.month);
  }
  if (Array.isArray(logWrap.items)) {
    state.requestLogs = logWrap.items;
    state.historyLoaded = true;
  }
  if (Array.isArray(logWrap.wallet_history)) {
    state.walletHistory = logWrap.wallet_history;
  }
  if (Array.isArray(logWrap.add_money_history)) {
    state.addMoneyHistory = logWrap.add_money_history;
  }
}

async function loadDashboardBootstrap(showBusy = true, busyText = 'Checking session...'){
  const data = await proxyGet(
    'dashboard_bootstrap',
    { limit: state.historyLimit, month: state.historyMonth },
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
  if (state.historyLoading) return;

  const month = normalizeMonthKey(options.month || state.historyMonth);
  const limit = Number(options.limit || state.historyLimit || 50);

  state.historyLoading = true;
  renderHistory();

  try {
    const data = await proxyGet(
      'request_logs',
      { limit, month },
      options.busyText || 'Loading history...',
      { busy: options.busy !== false }
    );

    state.historyMonth = normalizeMonthKey(data.month || month);
    state.requestLogs = Array.isArray(data.items) ? data.items : [];
    state.walletHistory = Array.isArray(data.wallet_history) ? data.wallet_history : [];
    state.addMoneyHistory = Array.isArray(data.add_money_history) ? data.add_money_history : [];
    state.historyLoaded = true;
    syncHistoryMonthControls();
    renderHero();
  } finally {
    state.historyLoading = false;
    renderHistory();
  }
}

async function ensureHistoryLoaded(options = {}){
  if (state.historyLoaded && !options.force) {
    renderHistory();
    return;
  }

  await loadRequestLogs({
    month: state.historyMonth,
    limit: state.historyLimit,
    busy: options.busy !== false,
    busyText: options.busyText || 'Loading this month history...'
  });
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
      loadRequestLogs({ busy: false, limit: 20 })
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

  if (el('historySection')?.classList.contains('active')) {
    await loadRequestLogs({
      month: state.historyMonth,
      limit: state.historyLimit,
      busy: false
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
    return ['PENDING','WAITING','WAITING_ADMIN','WAITING_APPROVAL','ADMIN_PENDING'].includes(status);
  }

  if (filter === 'PROCESSING') {
    return ['CLAIMED','PROCESSING','DIALING'].includes(status);
  }

  if (filter === 'SUCCESS') {
    return ['SUCCESS','SUCCESSFUL','COMPLETED','APPROVED','DONE'].includes(status);
  }

  if (filter === 'FAILED') {
    return ['FAILED','REJECTED','CANCELLED'].includes(status);
  }

  return status === filter;
}

function historyTimestamp(row){
  const candidates = [
    row?.updated_at,
    row?.completed_at,
    row?.success_at,
    row?.created_at,
    row?.timestamp,
    row?.date
  ];

  for (const value of candidates) {
    const numeric = Number(value || 0);
    if (Number.isFinite(numeric) && numeric > 0) {
      return String(Math.trunc(numeric)).length > 10 ? Math.floor(numeric / 1000) : numeric;
    }

    const parsed = Date.parse(String(value || ''));
    if (Number.isFinite(parsed) && parsed > 0) return Math.floor(parsed / 1000);
  }

  return 0;
}

function normalizeWalletHistoryItem(row){
  const transferId = String(row?.transfer_id || row?.ledger_id || '').trim();

  return {
    ...(row || {}),
    _history_type: 'WALLET',
    request_id: transferId,
    request_type: 'WALLET',
    type: 'WALLET',
    service: 'Wallet Received',
    status: String(row?.status || 'SUCCESS').toUpperCase(),
    amount: Number(row?.amount || 0),
    created_at: historyTimestamp(row),
    message: String(row?.note || row?.message || ''),
    raw: row || {}
  };
}

function normalizeAddMoneyHistoryItem(row){
  const requestId = String(row?.request_id || '').trim();

  return {
    ...(row || {}),
    _history_type: 'ADD_MONEY',
    request_id: requestId,
    request_type: 'ADD_MONEY',
    type: 'ADD_MONEY',
    service: 'Add Money',
    status: String(row?.status || 'PENDING').toUpperCase(),
    amount: Number(row?.amount || 0),
    created_at: historyTimestamp(row),
    message: String(row?.reject_reason || row?.note || ''),
    raw: row || {}
  };
}

function getFilteredHistory(){
  const rows = [
    ...(state.requestLogs || []),
    ...(state.walletHistory || []).map(normalizeWalletHistoryItem),
    ...(state.addMoneyHistory || []).map(normalizeAddMoneyHistoryItem)
  ];

  rows.sort((a,b) => {
    const aa = historyTimestamp(a);
    const bb = historyTimestamp(b);
    return bb - aa;
  });

  return rows.filter(row => statusMatchesFilter(row, state.filter));
}

function historyMonthLabel(monthKey = state.historyMonth){
  const month = normalizeMonthKey(monthKey);
  const date = new Date(`${month}-01T00:00:00`);

  if (isNaN(date.getTime())) {
    return 'this month';
  }

  return date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
}

function syncHistoryMonthControls(){
  const input = el('historyMonthInput');
  if (input && input.value !== state.historyMonth) {
    input.value = state.historyMonth;
  }

  const label = el('historyMonthLabel');
  if (label) {
    label.textContent = historyMonthLabel();
  }
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

  if (['PENDING','WAITING','WAITING_ADMIN','WAITING_APPROVAL','ADMIN_PENDING'].includes(status)) {
    return 'Request is pending.';
  }

  if (['CLAIMED','PROCESSING','DIALING'].includes(status)) {
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

  syncHistoryMonthControls();

  if (state.historyLoading) {
    list.innerHTML = `
      <div class="history-item history-empty">
        <div class="history-id">Loading this month history...</div>
        <div class="history-small">${esc(historyMonthLabel())}</div>
      </div>
    `;
    return;
  }

  const rows = getFilteredHistory();

  if (!rows.length) {
    list.innerHTML = `
      <div class="history-item history-empty">
        <div class="history-id">No history found for this month.</div>
        <div class="history-small">${esc(historyMonthLabel())}</div>
      </div>
    `;
    return;
  }

  list.innerHTML = rows.map(item => {
    const type = requestTypeOf(item);
    const number = requestNumberOf(item);
    const prefix = amountPrefixOf(item);
    const isMfs = type === 'MFS';
    const isWallet = type === 'WALLET';
    const isAddMoney = type === 'ADD_MONEY';
    const receiptLink = isMfs ? mfsTrackingUrl(item) : String(item.receipt_url || '').trim();

    const displayAmount = isAddMoney
      ? (item.amount || 0)
      : type === 'BUNDLE'
      ? (item.you_pay ?? item.payable_amount ?? item.net_cost_after_commission ?? item.amount ?? 0)
      : (item.amount || 0);

    const metaHtml = isWallet
      ? `
          <div class="mini"><label>From</label><strong>${esc(item.sender_name || item.sender_uid || '-')}</strong></div>
          <div class="mini"><label>Phone / Role</label><strong>${esc(item.sender_phone || '-')} - ${esc(item.sender_role || '-')}</strong></div>
          <div class="mini"><label>Amount</label><strong>${esc(item.currency || 'BDT')} ${money(item.amount || 0)}</strong></div>
          <div class="mini"><label>Date</label><strong>${esc(fmtTs(item.created_at || 0))}</strong></div>
        `
      : isAddMoney
      ? `
          <div class="mini"><label>Method</label><strong>${esc(item.method || '-')}</strong></div>
          <div class="mini"><label>Amount</label><strong>${esc(prefix)} ${money(item.amount || 0)}</strong></div>
          <div class="mini"><label>Submitted</label><strong>${esc(fmtTs(item.created_at || 0))}</strong></div>
          <div class="mini"><label>Processed</label><strong>${esc(fmtTs(item.approved_at || item.rejected_at || 0))}</strong></div>
        `
      : isMfs
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

    const walletExtraHtml = isWallet
      ? `
          <div class="history-meta history-meta-extra">
            <div class="mini"><label>Balance Before</label><strong>${esc(item.currency || 'BDT')} ${money(item.before_available ?? item.before_balance ?? 0)}</strong></div>
            <div class="mini"><label>Balance After</label><strong>${esc(item.currency || 'BDT')} ${money(item.after_available ?? item.after_balance ?? 0)}</strong></div>
            <div class="mini"><label>Note</label><strong>${esc(item.note || '-')}</strong></div>
            <div class="mini"><label>Reference</label><strong>${esc(item.reference || '-')}</strong></div>
          </div>
        `
      : isAddMoney
      ? `
          <div class="history-meta history-meta-extra">
            <div class="mini"><label>Txn / Receipt</label><strong>${esc(item.transaction_id || item.receipt_hash || '-')}</strong></div>
            <div class="mini"><label>Sender</label><strong>${esc(item.sender_number || '-')}</strong></div>
            <div class="mini"><label>Note</label><strong>${esc(item.note || '-')}</strong></div>
            <div class="mini"><label>Reject Reason</label><strong>${esc(item.reject_reason || '-')}</strong></div>
          </div>
        `
      : '';

    return `
      <div class="history-item">
        <div class="history-top">
          <div>
            <div class="history-id">${esc(item.request_id || '-')}</div>
            <div class="history-small">
              <span class="history-type-badge">${esc(type)}</span>
              ${
                isWallet
                  ? 'Wallet Received'
                  : isAddMoney
                    ? 'Add Money Request'
                  : isMfs
                    ? esc(mfsProviderLabel(item)) + ' Send Money'
                    : esc(type === 'BUNDLE' ? 'Bundle Request' : 'Topup Request')
              }
            </div>
          </div>
          ${statusPill(item.status || '-')}
        </div>

        <div class="history-meta">
          ${metaHtml}
        </div>

        ${walletExtraHtml}

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

        ${isWallet || isAddMoney ? '' : historyMessageHtml(item)}

        <div class="history-actions">
          ${isWallet || isAddMoney ? '' : `<button class="btn blue sm" type="button" onclick="openHistoryDetail('${esc(item.request_id || '')}')">View</button>`}
          <button class="btn ghost sm" type="button" onclick="copyHistoryId('${esc(item.request_id || '')}')">Copy ID</button>
          ${receiptLink ? `<button class="btn green sm" type="button" onclick="openReceiptLink('${esc(receiptLink)}')">Receipt</button>` : ''}
        </div>
      </div>
    `;
  }).join('');
}

window.copyHistoryId = function(requestId){
  copyText(requestId, 'History ID copied');
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

  const receiptLink = isMfs ? mfsTrackingUrl(row) : String(row.receipt_url || '').trim();
  if (receiptLink) {
    msg += '\nReceipt: ' + receiptLink;
  }

  if (el('detailMessage')) el('detailMessage').textContent = msg;

  el('detailModal')?.classList.add('show');
};

function closeHistoryDetail(){
  el('detailModal')?.classList.remove('show');
}

function setHistoryFilter(filter, options = {}){
  state.filter = String(filter || 'ALL').toUpperCase();

  document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.filter === state.filter);
  });

  if (!state.historyLoaded && !options.skipLoad) {
    ensureHistoryLoaded({ force: true, busyText: 'Loading this month history...' }).catch(err => {
      if (isSessionError(err)) {
        showLogin();
        setLoginError('Session expired. Please login again.');
        return;
      }
      showToast(err.message || 'Failed to load history', 'error');
    });
  }

  if (options.skipRender) return;
  renderHistory();
}

/* =========================
   Add Money
========================= */

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

function renderAddMoneyAccountCards(accounts, country){
  const list = Array.isArray(accounts) ? accounts : [];
  if (!list.length) {
    return `
      <div class="add-money-account-list form-full">
        <div class="detail-box add-money-account-card">
          <div class="add-money-account-name">Payment account unavailable</div>
          <p class="muted">Please contact support before submitting an add money request.</p>
        </div>
      </div>
    `;
  }

  return `
    <div class="add-money-account-list form-full">
      ${list.map((account) => {
        const instruction = String(account.instruction || '').trim();
        const holder = account.account_holder || '-';
        const number = account.account_number || '-';
        return `
          <div class="detail-box add-money-account-card">
            <div class="add-money-account-main">
              <div>
                <div class="add-money-account-name">${esc(account.display_name || addMoneyMethodLabel(account.method))}</div>
                <div class="add-money-account-method">${esc(addMoneyMethodLabel(account.method))}${country === 'MY' ? ' Deposit' : ' Payment'}</div>
              </div>
            </div>
            <div class="add-money-account-lines">
              <div class="add-money-account-line"><span>A/C Name</span><strong>${esc(holder)}</strong></div>
              <div class="add-money-account-line"><span>A/C No</span><strong>${esc(number)}</strong></div>
            </div>
            ${instruction ? `<p class="muted add-money-account-note">${esc(instruction)}</p>` : ''}
            <div class="add-money-copy-action">
              <button class="btn ghost sm" type="button" data-copy-account-number="${esc(account.account_number || '')}">Copy Number</button>
            </div>
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
  const accounts = Array.isArray(profile.accounts) ? profile.accounts : [];
  const country = String(profile.pricing_country || '').toUpperCase();
  const prefix = walletPrefix(profile.currency || (country === 'MY' ? 'MYR' : 'BDT'));
  const enabled = !!settings.enabled;
  const bdMethods = [...new Set(accounts.map(account => String(account.method || '').toUpperCase()).filter(method => ['BKASH', 'NAGAD'].includes(method)))];
  const bdMethodOptions = (bdMethods.length ? bdMethods : ['BKASH', 'NAGAD']).map(method => `<option value="${esc(method)}">${esc(addMoneyMethodLabel(method))}</option>`).join('');

  if (!enabled) {
    wrap.innerHTML = `
      <div class="detail-box">
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
        <div class="add-money-section-title form-full">Deposit With Bank & eWallet</div>
        ${renderAddMoneyAccountCards(accounts, 'MY')}
        <div class="field form-full">
          <label>Instruction</label>
          <p class="muted">${esc(settings.instruction || 'Transfer and upload your receipt.')}</p>
        </div>
        <div class="field">
          <label>Amount (${prefix})</label>
          <input class="input" name="amount_rm" type="number" min="1" step="0.01" placeholder="Enter amount">
        </div>
        <div class="field">
          <label>Receipt Upload</label>
          <input class="input" name="receipt_upload" type="file" accept="image/jpeg,image/png,image/webp,application/pdf">
        </div>
        <div class="field form-full">
          <label>Note / Reference (optional)</label>
          <input class="input" name="note" placeholder="Optional note">
        </div>
        <div class="form-actions form-full">
          <button class="btn green" type="submit">Submit Add Money Request</button>
        </div>
      </form>
    `;
  } else {
    wrap.innerHTML = `
      <form id="addMoneyForm" class="form-grid">
        <div class="add-money-section-title form-full">Deposit With bKash & Nagad</div>
        ${renderAddMoneyAccountCards(accounts, 'BD')}
        <div class="field form-full">
          <label>Instruction</label>
          <p class="muted">${esc(settings.instruction || 'Send money first, then submit your transaction ID.')}</p>
        </div>
        <div class="field">
          <label>Method</label>
          <select class="input" name="method">
            ${bdMethodOptions}
          </select>
        </div>
        <div class="field">
          <label>Amount (${prefix})</label>
          <input class="input" name="amount_bdt" type="number" min="1" step="0.01" placeholder="Enter amount">
        </div>
        <div class="field">
          <label>Transaction ID</label>
          <input class="input" name="transaction_id" placeholder="bKash/Nagad transaction ID">
        </div>
        <div class="field">
          <label>Sender Number</label>
          <input class="input" name="sender_number" placeholder="Number used to send payment">
        </div>
        <div class="form-actions form-full">
          <button class="btn green" type="submit">Submit Add Money Request</button>
        </div>
      </form>
    `;
  }

  bindAddMoneyForm();
  renderAddMoneyHistory();
}

function bindAddMoneyForm(){
  const form = el('addMoneyForm');
  if (!form || form.dataset.bound === '1') return;
  form.dataset.bound = '1';

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(form);

    try {
      await proxyFormPost('add_money_submit', formData, 'Submitting add money request...');
      showToast('Add money request submitted. Please wait for approval.', 'ok');
      form.reset();
      state.addMoneyLoaded = false;
      state.historyLoaded = false;
      await loadAddMoneyPage({ force: true, busy:false });
    } catch (err) {
      showToast(err.message || 'Failed to submit add money request', 'error');
    }
  });
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
Available Balance: ${prefix} ${walletDisplayAmount(wallet, 'available')}`;
}

function clearMfsCreateFieldsAfterSuccess(){
  ['mfsReceiverNumber','mfsAmountBdt','mfsAmountRm','mfsPin','mfsReference'].forEach(id => {
    if (el(id)) el(id).value = '';
  });
  renderMfsPreview();
}

function mfsTrackingUrl(data){
  const direct = String(data?.tracking_url || data?.receipt_url || data?.request_url || '').trim();
  if (direct) return direct;

  const token = String(data?.receipt_token || data?.tracking_token || '').trim();
  if (token) {
    return `${window.location.origin || ''}/api/mfs/receipt.php?t=${encodeURIComponent(token)}`;
  }

  return '';
}

function ensureMfsResultModal(){
  if (el('mfsCreateResultModal')) return;

  const wrap = document.createElement('div');
  wrap.id = 'mfsCreateResultModal';
  wrap.className = 'modal';
  wrap.innerHTML = `
    <div class="modal-card">
      <button id="closeMfsCreateResultModalBtn" class="modal-close" type="button">×</button>
      <h3 class="modal-title" id="mfsCreateResultTitle">Send Money Request</h3>
      <p class="modal-sub" id="mfsCreateResultSub">Request details</p>
      <div id="mfsCreateResultBody" class="result-card"></div>
      <div class="wizard-actions">
        <button id="mfsRetryBtn" class="btn green hidden" type="button">Try Again</button>
        <button id="mfsEditBtn" class="btn ghost hidden" type="button">Edit</button>
        <button id="mfsCopyTrackingBtn" class="btn blue" type="button">Copy Link</button>
        <button id="mfsOpenTrackingBtn" class="btn green" type="button">Open Receipt</button>
        <button id="mfsCreateResultOkBtn" class="btn ghost" type="button">OK</button>
      </div>
    </div>
  `;

  document.body.appendChild(wrap);

  const close = () => hideModalById('mfsCreateResultModal');
  el('closeMfsCreateResultModalBtn')?.addEventListener('click', close);
  el('mfsCreateResultOkBtn')?.addEventListener('click', close);
  wrap.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'mfsCreateResultModal') close();
  });
}

function showMfsResultModal({
  title = 'Send Money Request',
  subtitle = '',
  rows = [],
  link = '',
  type = 'info',
  retryStep = 'pin',
  editStep = 'amount'
} = {}){
  ensureMfsResultModal();

  const wrap = el('mfsCreateResultModal');
  const titleNode = el('mfsCreateResultTitle');
  const subNode = el('mfsCreateResultSub');
  const body = el('mfsCreateResultBody');
  const copyBtn = el('mfsCopyTrackingBtn');
  const openBtn = el('mfsOpenTrackingBtn');
  const retryBtn = el('mfsRetryBtn');
  const editBtn = el('mfsEditBtn');

  if (titleNode) titleNode.textContent = title;
  if (subNode) subNode.textContent = subtitle || '';
  if (body) {
    body.className = 'result-card ' + (type === 'error' ? 'bad' : 'good');
    const rowsHtml = rows
      .filter(row => Array.isArray(row) && row.length >= 2)
      .map(row => `
        <div class="mfs-review-item">
          <span class="mfs-review-label">${esc(row[0])}</span>
          <strong class="mfs-review-value">${esc(row[1] || '-')}</strong>
        </div>
      `).join('');

    body.innerHTML = `
      <div class="mfs-review-grid">${rowsHtml || `<div class="result-text">${esc(subtitle || 'No details available.')}</div>`}</div>
      ${link ? `<div class="mfs-review-link"><span class="mfs-review-label">Receipt / Tracking Link</span><strong class="mfs-review-value">${esc(link)}</strong></div>` : ''}
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

  if (retryBtn) {
    retryBtn.classList.toggle('hidden', type !== 'error');
    retryBtn.onclick = () => {
      hideModalById('mfsCreateResultModal');
      if (typeof window.zpayOpenMfsStep === 'function') window.zpayOpenMfsStep(retryStep);
    };
  }

  if (editBtn) {
    editBtn.classList.toggle('hidden', type !== 'error');
    editBtn.onclick = () => {
      hideModalById('mfsCreateResultModal');
      if (typeof window.zpayOpenMfsStep === 'function') window.zpayOpenMfsStep(editStep);
    };
  }

  showModalById('mfsCreateResultModal');
}

function showMfsErrorModal(title, message, options = {}){
  showMfsResultModal({
    title: title || 'Send Money Error',
    subtitle: message || 'Something went wrong',
    type: 'error',
    rows: [['Message', message || 'Something went wrong']],
    retryStep: options.retryStep || 'pin',
    editStep: options.editStep || 'amount'
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
    subtitle: 'Your send money request has been submitted securely.',
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
      ['Status', userStatusLabel(data.status || 'PENDING')],
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
    const trackingLink = mfsTrackingUrl(data);

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
      receipt_url: trackingLink,
      tracking_url: trackingLink,
      receipt_token: data.receipt_token || data.tracking_token || '',
      receipt_created_at: Number(data.receipt_created_at || 0),
      reference: data.reference || '',
      trxid: data.trxid || '',
      message: 'Send money request created',
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
      note: 'Send money request from user panel'
    }, 'Creating request...');

    renderMfsResultSuccess(res);
    applyMfsCreateSuccessToLocalState(res);
    clearMfsCreateFieldsAfterSuccess();
    showToast('Request created successfully', 'ok');
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

function topupWalletInfo(){
  const summary = state.walletSummary || {};
  const wallet = summary.wallet || {};
  const roleSettings = summary.role_settings || {};
  const prefix = walletDisplayCurrency(wallet);
  const available = Number(wallet.display_available_balance ?? wallet.available_balance ?? 0);
  const rate = Number(wallet.rate_myr_bdt ?? wallet.rate_myr_to_bdt ?? summary.rate_myr_bdt ?? 0);
  const commissionPer1000 = Number(roleSettings.commission_per_1000 || 0);

  return {
    prefix,
    currency: prefix === 'RM' || prefix === 'MYR' ? 'MYR' : 'BDT',
    available: Number.isFinite(available) ? available : NaN,
    rate: Number.isFinite(rate) && rate > 0 ? rate : 31,
    commissionPer1000: Number.isFinite(commissionPer1000) ? Math.max(0, commissionPer1000) : 0
  };
}

function topupDebitPreview(){
  const amountBdt = Number(wizardData().amount || 0);
  const wallet = topupWalletInfo();
  const commissionBdt = Math.min(amountBdt, Math.max(0, amountBdt * wallet.commissionPer1000 / 1000));
  const walletDebitBdt = Math.max(0, amountBdt - commissionBdt);
  const walletDebitAmount = wallet.currency === 'MYR'
    ? walletDebitBdt / wallet.rate
    : walletDebitBdt;

  return {
    amountBdt,
    commissionBdt,
    walletDebitBdt,
    walletDebitAmount,
    wallet
  };
}

function topupReviewRows(){
  const data = wizardData();
  const debit = topupDebitPreview();
  const wallet = debit.wallet;
  const after = Number.isFinite(wallet.available) ? wallet.available - debit.walletDebitAmount : NaN;

  return [
    ['Number', data.topup_number || '-'],
    ['Operator', operatorName(data.operator || '-')],
    ['Topup Amount', 'BDT ' + money(debit.amountBdt)],
    ...(debit.commissionBdt > 0 ? [['Commission Benefit', 'BDT ' + money(debit.commissionBdt)]] : []),
    ['Wallet Debit', wallet.prefix + ' ' + money(debit.walletDebitAmount), true],
    ...(wallet.currency === 'MYR' ? [['Rate', 'RM 1 = BDT ' + money(wallet.rate)]] : []),
    ...(Number.isFinite(wallet.available) ? [['Available Balance', wallet.prefix + ' ' + money(wallet.available)]] : []),
    ...(Number.isFinite(after) ? [['Balance After', wallet.prefix + ' ' + money(after)]] : [])
  ];
}

function topupTotalPay(){
  return topupDebitPreview().walletDebitAmount;
}

function topupHasEnoughBalance(){
  const wallet = topupWalletInfo();
  const total = topupTotalPay();
  return !Number.isFinite(wallet.available) || wallet.available >= total;
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
    if (!topupHasEnoughBalance()) {
      setTopupFlowError('Your available balance is not enough for this topup.');
      showTopupResultModal({
        title: 'Insufficient Balance',
        subtitle: 'Your available balance is not enough for this topup.',
        type: 'error',
        rows: [['Message', 'Your available balance is not enough for this topup.']],
        retryStep: 'amount',
        editStep: 'amount'
      });
      return;
    }
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
          showLogin();
          setLoginError('Session expired. Please login again.');
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

  try{
    setTopupFlowBusy(true, 'Submitting...');
    const res = await proxyPost('topup_create', {
      topup_number: data.topup_number,
      operator: data.operator,
      amount: Number(data.amount),
      pin: data.pin,
      note: 'Topup request from user dashboard'
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

function clearLoginOtpTimer(){
  if (state.loginOtp.timer) {
    clearInterval(state.loginOtp.timer);
    state.loginOtp.timer = null;
  }
}

async function proxyFormPost(action, formData, busyText = 'Processing...', options = {}){
  const useBusy = options.busy !== false;

  if (useBusy) {
    setBusy(true, busyText);
  }

  try{
    const headers = {
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
      body: formData
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

function loginOtpIsExpired(){
  return state.loginOtp.expiresAt > 0 && Date.now() >= state.loginOtp.expiresAt;
}

function updateLoginOtpCountdown(){
  const expiresNode = el('loginOtpExpiresText');
  const verifyButton = el('verifyLoginOtpBtn');
  const resendButton = el('resendLoginOtpBtn');
  const left = Math.max(0, Math.ceil((state.loginOtp.expiresAt - Date.now()) / 1000));

  if (expiresNode) expiresNode.textContent = left > 0 ? left + ' seconds' : 'Expired';
  if (verifyButton) verifyButton.disabled = left <= 0;

  if (left <= 0) {
    clearLoginOtpTimer();
    if (resendButton) resendButton.disabled = false;

    if (el('loginOtpStatus') && el('loginOtpModal')?.classList.contains('show')) {
      el('loginOtpStatus').textContent = 'OTP expired. Please resend OTP to continue.';
    }
  }
}

function startLoginOtpTimer(){
  clearLoginOtpTimer();

  if (!state.loginOtp.expiresAt) {
    state.loginOtp.expiresAt = Date.now() + (Math.max(0, state.loginOtp.expiresInSeconds) * 1000);
  }

  updateLoginOtpCountdown();

  if (!loginOtpIsExpired()) {
    state.loginOtp.timer = setInterval(updateLoginOtpCountdown, 1000);
  }
}

function resetLoginOtpState(){
  clearLoginOtpTimer();
  state.loginOtp = {
    preAuthToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300,
    expiresAt: 0,
    timer: null,
    trustDevice: true
  };

  if (el('loginOtpMaskedPhone')) el('loginOtpMaskedPhone').textContent = '-';
  if (el('loginOtpExpiresText')) el('loginOtpExpiresText').textContent = '5 minutes';
  if (el('loginOtpCode')) el('loginOtpCode').value = '';
  if (el('verifyLoginOtpBtn')) el('verifyLoginOtpBtn').disabled = false;
  if (el('loginOtpStatus')) el('loginOtpStatus').textContent = 'OTP পাঠানোর পরে এখানে status দেখাবে।';
}

function updateLoginOtpModal(data){
  state.loginOtp.preAuthToken = String(data.pre_auth_token || '');
  state.loginOtp.otpRequestId = String(data.otp_request_id || '');
  state.loginOtp.maskedPhone = String(data.masked_phone || '');
  const expiresInValue = data.expires_in_seconds ?? data.expires_in ?? 300;
  const parsedExpiresIn = Number(expiresInValue);
  state.loginOtp.expiresInSeconds = Number.isFinite(parsedExpiresIn)
    ? Math.max(0, parsedExpiresIn)
    : 300;
  const serverExpiresAt = Number(data.expires_at || 0);
  state.loginOtp.expiresAt = serverExpiresAt > 0
    ? (serverExpiresAt < 1000000000000 ? serverExpiresAt * 1000 : serverExpiresAt)
    : Date.now() + (state.loginOtp.expiresInSeconds * 1000);

  if (el('loginOtpMaskedPhone')) {
    el('loginOtpMaskedPhone').textContent = state.loginOtp.maskedPhone || '-';
  }

  if (el('loginOtpCode')) {
    el('loginOtpCode').value = '';
  }

  if (el('loginOtpStatus')) {
    el('loginOtpStatus').textContent =
      'OTP sent to ' + (state.loginOtp.maskedPhone || 'your phone') + '. Enter the code to complete login.';
  }

  startLoginOtpTimer();
}

function openLoginOtpModal(){
  el('loginOtpModal')?.classList.add('show');
  startLoginOtpTimer();
}

function closeLoginOtpModal(){
  clearLoginOtpTimer();
  el('loginOtpModal')?.classList.remove('show');
  resetLoginOtpState();
}

async function doLogin(){
  setLoginError('');

  const phone = (el('loginPhone')?.value || '').trim();
  const phoneCountry = (el('loginPhoneCountry')?.value || 'BD').toUpperCase();
  const password = el('loginPassword')?.value || '';
  const trustDevice = !!el('rememberTrustedDevice')?.checked;

  if (!phone || !password) {
    setLoginError('Phone and password are required.');
    return;
  }

  const phoneDigits = phone.replace(/\D+/g, '');
  const validPhone = phoneCountry === 'MY'
    ? /^(?:011\d{8}|01[02-9]\d{7}|6011\d{8}|601[02-9]\d{7}|11\d{8}|1[02-9]\d{7})$/.test(phoneDigits)
    : /^(?:01[3-9]\d{8}|8801[3-9]\d{8}|1[3-9]\d{8})$/.test(phoneDigits);

  if (!validPhone) {
    setLoginError(phoneCountry === 'MY' ? 'Invalid Malaysia number' : 'Invalid Bangladesh number');
    return;
  }

  state.loginOtp.trustDevice = trustDevice;

  try{
    const data = await proxyPost('login', {
      phone,
      phone_country: phoneCountry,
      password,
      trust_device: trustDevice,
      device_id: 'USER_WEB',
      device_name: 'User Dashboard',
      browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ''
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

function updateLoginCountryUi(){
  const country = (el('loginPhoneCountry')?.value || 'BD').toUpperCase();
  if (el('loginPhone')) {
    el('loginPhone').placeholder = country === 'MY' ? '01XXXXXXXX or +60XXXXXXXXX' : '01XXXXXXXXX or +8801XXXXXXXXX';
  }
}

async function loadLoginCountryDefault(){
  try {
    const data = await proxyPost('country_defaults', {}, 'Detecting country...');
    const country = String(data.phone_country || 'BD').toUpperCase();
    if (el('loginPhoneCountry') && ['BD','MY'].includes(country)) {
      el('loginPhoneCountry').value = country;
    }
  } catch (_) {
    // Keep Bangladesh as the compatibility default.
  }
  updateLoginCountryUi();
}

async function verifyLoginOtp(){
  const otp = (el('loginOtpCode')?.value || '').trim();

  if (!state.loginOtp.preAuthToken || !state.loginOtp.otpRequestId) {
    if (el('loginOtpStatus')) {
      el('loginOtpStatus').textContent = 'Login verification session missing. Please login again.';
    }
    return;
  }

  if (loginOtpIsExpired()) {
    if (el('loginOtpStatus')) {
      el('loginOtpStatus').textContent = 'OTP expired. Please resend OTP to continue.';
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
      expires_in_seconds: data.expires_in_seconds || 300,
      expires_at: data.expires_at || 0
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
  state.walletHistory = [];
  state.addMoneyProfile = null;
  state.addMoneyHistory = [];
  state.addMoneyLoaded = false;
  state.historyLoaded = false;
  state.historyLoading = false;
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

  el('loginPhoneCountry')?.addEventListener('change', updateLoginCountryUi);

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

  const historyMonthInput = el('historyMonthInput');
  if (historyMonthInput && historyMonthInput.dataset.bound !== '1') {
    historyMonthInput.dataset.bound = '1';
    historyMonthInput.value = state.historyMonth;
    historyMonthInput.addEventListener('change', () => {
      state.historyMonth = normalizeMonthKey(historyMonthInput.value);
      state.historyLoaded = false;
      ensureHistoryLoaded({ force: true, busyText: 'Loading selected month...' }).catch(err => {
        if (isSessionError(err)) {
          showLogin();
          setLoginError('Session expired. Please login again.');
          return;
        }

        showToast(err.message || 'Failed to load history', 'error');
      });
    });
  }

  const historyRefreshBtn = el('historyRefreshBtn');
  if (historyRefreshBtn && historyRefreshBtn.dataset.bound !== '1') {
    historyRefreshBtn.dataset.bound = '1';
    historyRefreshBtn.addEventListener('click', () => {
      ensureHistoryLoaded({ force: true, busyText: 'Refreshing history...' }).catch(err => {
        if (isSessionError(err)) {
          showLogin();
          setLoginError('Session expired. Please login again.');
          return;
        }

        showToast(err.message || 'Failed to refresh history', 'error');
      });
    });
  }

  const addMoneyReloadBtn = el('addMoneyReloadBtn');
  if (addMoneyReloadBtn && addMoneyReloadBtn.dataset.bound !== '1') {
    addMoneyReloadBtn.dataset.bound = '1';
    addMoneyReloadBtn.addEventListener('click', () => {
      loadAddMoneyPage({ force: true, busyText: 'Reloading add money...' }).catch(err => {
        if (isSessionError(err)) {
          showLogin();
          setLoginError('Session expired. Please login again.');
          return;
        }
        showToast(err.message || 'Failed to reload add money', 'error');
      });
    });
  }

  if (document.body && document.body.dataset.addMoneyCopyBound !== '1') {
    document.body.dataset.addMoneyCopyBound = '1';
    document.addEventListener('click', (event) => {
      const btn = event.target?.closest?.('[data-copy-account-number]');
      if (!btn) return;
      copyText(btn.dataset.copyAccountNumber || '', 'Account number copied.');
    });
  }

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

  el('wizardNext1')?.addEventListener('click', openTopupFlowFromNumber);

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
    if (e.key === 'Enter') openTopupFlowFromNumber();
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
      closeTopupFlowModal();
      hideModalById('topupResultModal');
      hideModalById('mfsCreateResultModal');
      if (typeof window.zpayCloseMfsFlow === 'function') {
        window.zpayCloseMfsFlow();
      }
      el('loginOtpModal')?.classList.remove('show');
      syncUserModalLock();
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

window.userState = state;
window.proxyGet = proxyGet;
window.proxyPost = proxyPost;
window.openSection = openSection;
window.showToast = showToast;
window.setBusy = setBusy;
window.syncUserModalLock = syncUserModalLock;
window.pushUserFlowHistory = pushUserFlowHistory;
window.replaceUserFlowHistory = replaceUserFlowHistory;
window.userSessionExpired = function(){
  showLogin();
  setLoginError('Session expired. Please login again.');
};
window.renderMfsResultSuccess = renderMfsResultSuccess;
window.applyMfsCreateSuccessToLocalState = applyMfsCreateSuccessToLocalState;
window.renderMfsResultError = renderMfsResultError;
window.showMfsErrorModal = showMfsErrorModal;

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
