const state = {
  csrf: '',
  me: null,

  topupTab: 'pending',

  topups: [],
  bundles: [],
  bundleOffers: [],
  addMoney: [],
  addMoneyPaymentAccounts: [],
  supportTickets: [],
  supportCategories: [],
  supportConfig: {},
  supportOpenTicketId: '',
  doneTopups: [],
  doneBundles: [],
  users: [],
  usersPagination: {
    page: 1,
    limit: 50,
    total: 0,
    total_pages: 1,
    has_more: false
  },
  workers: [],
  operators: [],

  busyCount: 0,

  autoRefreshSeconds: Number(localStorage.getItem('zaw_admin_auto_refresh') || 0),
  autoRefreshTimer: null,
  autoRefreshUiTimer: null,
  lastRefreshAt: 0,
  nextRefreshAt: 0,

  counts: {
    pending: 0,
    claimed: 0,
    processing: 0,
    done: 0
  },

  loaded: {
    counts: false,
    topups: false,
    bundles: false,
    bundleOffers: false,
    addMoney: false,
    support: false,
    doneTopups: false,
    doneBundles: false,
    users: false,
    workers: false,
    operators: false,
    appConfig: false
  },

  backgroundStarted: false,

  loginOtp: {
    preAuthToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300,
    trustDevice: true,
    timer: null,
    expiresAt: 0
  },

  pendingEditUserUpdate: null
};

const submitLocks = {};
let usersSearchTimer = null;
let supportSearchTimer = null;

/* =========================
   BASIC HELPERS
========================= */

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

function walletMoney(row, type = 'available'){
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
  const raw = Number(row?.[rawKey] ?? row?.[type === 'hold' ? 'hold_balance' : 'available_balance'] ?? 0);
  return `<div class="muted" style="font-size:12px;margin-top:4px;">Stored: BDT ${money(raw)}</div>`;
}

function boolFromValue(v, fallback = false){
  if (v === true || v === false) return v;

  const s = String(v ?? '').trim().toLowerCase();

  if (['1','true','yes','on','enabled','active'].includes(s)) return true;
  if (['0','false','no','off','disabled','inactive'].includes(s)) return false;

  return fallback;
}

function numberFromValue(v, fallback = 0){
  if (typeof v === 'string') {
    v = v.replace(/,/g, '').trim();
  }

  const n = parseFloat(v);
  return Number.isFinite(n) ? n : fallback;
}

function fmtTs(ts){
  if (!ts) return '-';

  const raw = Number(ts || 0);
  const ms = String(Math.trunc(raw)).length <= 10 ? raw * 1000 : raw;
  const d = new Date(ms);

  return isNaN(d.getTime()) ? '-' : d.toLocaleString();
}

function fmtClock(ts){
  if (!ts) return '-';

  const d = new Date(ts);
  return isNaN(d.getTime()) ? '-' : d.toLocaleTimeString();
}

function formatAgo(ts){
  const n = Number(ts || 0);
  if (!n) return '-';

  const ms = String(Math.trunc(n)).length <= 10 ? n * 1000 : n;
  const diff = Math.max(0, Date.now() - ms);

  const sec = Math.floor(diff / 1000);
  const min = Math.floor(sec / 60);
  const hr = Math.floor(min / 60);

  if (sec < 60) return `${sec}s ago`;
  if (min < 60) return `${min}m ago`;
  if (hr < 24) return `${hr}h ago`;

  return `${Math.floor(hr / 24)}d ago`;
}

function jsArg(v){
  return String(v ?? '')
    .replace(/\\/g, '\\\\')
    .replace(/'/g, "\\'")
    .replace(/\r/g, '')
    .replace(/\n/g, '\\n');
}

function readJsonSafeFromText(text){
  if (!text || !String(text).trim()) {
    throw new Error('Empty response from server');
  }

  try{
    return JSON.parse(text);
  }catch(_){
    throw new Error(text.length > 500 ? text.slice(0, 500) : text);
  }
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
    msg.includes('session not found') ||
    msg.includes('admin session not found')
  );
}

function activeSectionId(){
  const active = document.querySelector('.section.active');
  return active ? active.id : 'dashboardSection';
}

function sleep(ms){
  return new Promise(resolve => setTimeout(resolve, ms));
}

/* =========================
   AMOUNT HELPERS
========================= */

function bundlePayAmount(item){
  return Number(
    item?.wallet_hold_amount ??
    item?.payable_amount ??
    item?.you_pay ??
    item?.net_cost_after_commission ??
    item?.amount ??
    0
  );
}

function bundlePriceAmount(item){
  return Number(
    item?.price_amount ??
    item?.offer_price ??
    item?.price ??
    item?.amount ??
    0
  );
}

function parseBdtPriceFromText(text){
  const s = String(text || '').toUpperCase();
  const matches = [...s.matchAll(/(\d+(?:\.\d+)?)\s*BDT/g)];

  if (!matches.length) return 0;

  const last = matches[matches.length - 1];
  const n = Number(last[1] || 0);

  return Number.isFinite(n) ? n : 0;
}

function getBundleOfferPriceAmount(item){
  const direct = Number(
    item?.price_amount ??
    item?.offer_price ??
    item?.price ??
    item?.amount ??
    item?.cost ??
    0
  );

  const parsedFromName = parseBdtPriceFromText(
    [
      item?.bundle_name,
      item?.package_name,
      item?.plan_name,
      item?.name,
      item?.note,
      item?.description
    ].join(' ')
  );

  if (parsedFromName > direct) {
    return parsedFromName;
  }

  return Number.isFinite(direct) ? direct : 0;
}

function getBundleOfferAdminCommission(item){
  const n = Number(
    item?.admin_commission ??
    item?.commission ??
    item?.commission_amount ??
    0
  );

  return Number.isFinite(n) ? n : 0;
}

function getBundleOfferUserCommission(item){
  const n = Number(
    item?.user_commission ??
    item?.customer_commission ??
    item?.user_discount ??
    0
  );

  return Number.isFinite(n) ? n : 0;
}

function getBundleOfferYouPay(item){
  const price = getBundleOfferPriceAmount(item);
  const userCommission = getBundleOfferUserCommission(item);

  const direct = Number(
    item?.you_pay ??
    item?.payable_amount ??
    item?.net_cost_after_commission ??
    0
  );

  if (direct > 0 && direct <= price) {
    return direct;
  }

  return Math.max(0, price - userCommission);
}

function calcDurationSeconds(value, unit){
  const n = Number(value || 0);
  const u = String(unit || '').toUpperCase();

  if (n <= 0) return 0;

  if (u === 'MINUTE' || u === 'MINUTES') return Math.round(n * 60);
  if (u === 'HOUR' || u === 'HOURS') return Math.round(n * 3600);
  if (u === 'DAY' || u === 'DAYS') return Math.round(n * 86400);
  if (u === 'MONTH' || u === 'MONTHS') return Math.round(n * 30 * 86400);

  return 0;
}

/* =========================
   UI STATE
========================= */

function updateInteractiveState(){
  const locked = state.busyCount > 0;

  document.body.classList.toggle('ui-busy', locked);

  document.querySelectorAll('.btn,.mini-btn,.tab-btn,.nav-btn').forEach(node => {
    node.disabled = locked;
  });

  const uiStateText = document.getElementById('uiStateText');
  const uiStateDot = document.getElementById('uiStateDot');

  if (!uiStateText || !uiStateDot) return;

  if (locked) {
    uiStateText.textContent = 'UI state: Processing request...';
    uiStateDot.className = 'status-dot orange';
  } else {
    uiStateText.textContent = 'UI state: Ready';
    uiStateDot.className = 'status-dot';
  }
}

function updateStatusStrip(){
  const lastRefreshText = document.getElementById('lastRefreshText');
  const lastRefreshDot = document.getElementById('lastRefreshDot');
  const autoRefreshText = document.getElementById('autoRefreshText');
  const autoRefreshDot = document.getElementById('autoRefreshDot');

  if (lastRefreshText && lastRefreshDot) {
    if (state.lastRefreshAt > 0) {
      lastRefreshText.textContent = `Last refresh: ${fmtClock(state.lastRefreshAt)}`;
      lastRefreshDot.className = 'status-dot';
    } else {
      lastRefreshText.textContent = 'Last refresh: never';
      lastRefreshDot.className = 'status-dot orange';
    }
  }

  if (autoRefreshText && autoRefreshDot) {
    if (state.autoRefreshSeconds <= 0) {
      autoRefreshText.textContent = 'Auto refresh: Off';
      autoRefreshDot.className = 'status-dot blue';
      return;
    }

    if (shouldPauseAutoRefresh()) {
      autoRefreshText.textContent = `Auto refresh: Paused (${state.autoRefreshSeconds}s)`;
      autoRefreshDot.className = 'status-dot orange';
      return;
    }

    const remainMs = Math.max(0, state.nextRefreshAt - Date.now());
    const remainSec = Math.ceil(remainMs / 1000);

    autoRefreshText.textContent = `Auto refresh: ${state.autoRefreshSeconds}s • next in ${remainSec}s`;
    autoRefreshDot.className = 'status-dot blue';
  }
}

function setBusy(on, text='Loading...'){
  const wrap = document.getElementById('loadingWrap');
  const txt = document.getElementById('loadingText');

  if (!wrap || !txt) return;

  if (on) {
    state.busyCount++;
    txt.textContent = text;
    wrap.classList.add('show');
    updateInteractiveState();
    updateStatusStrip();
    return;
  }

  state.busyCount = Math.max(0, state.busyCount - 1);

  if (state.busyCount === 0) {
    wrap.classList.remove('show');
    txt.textContent = 'Loading...';
  }

  updateInteractiveState();
  updateStatusStrip();
}

function showLogin(){
  document.getElementById('loginView')?.classList.remove('hidden');
  document.getElementById('appView')?.classList.add('hidden');
}

function showApp(){
  document.getElementById('loginView')?.classList.add('hidden');
  document.getElementById('appView')?.classList.remove('hidden');
}

function log(msg){
  const box = document.getElementById('logBox');
  if (!box) return;

  const line = `[${new Date().toLocaleString()}] ${msg}`;
  box.textContent = line + '\n' + box.textContent;
}

function showToast(message, type='ok'){
  const wrap = document.getElementById('toastWrap');
  if (!wrap) return;

  const div = document.createElement('div');
  div.className = `toast ${type}`;
  div.textContent = message;
  wrap.appendChild(div);

  setTimeout(() => div.remove(), 3500);
}

function setLoginError(msg=''){
  const node = document.getElementById('loginError');
  if (!node) return;

  if (!msg){
    node.classList.add('hidden');
    node.textContent = '';
    return;
  }

  node.classList.remove('hidden');
  node.textContent = msg;
}

function statusPill(v){
  const t = String(v || '').toUpperCase();
  let cls = 'info';

  if (['SUCCESS','DONE','ACTIVE','COMPLETED','APPROVED'].includes(t)) cls = 'success';
  else if (['FAILED','ERROR','INACTIVE','DISABLED','REVOKED','DELETED','REMOVED','REJECTED'].includes(t)) cls = 'danger';
  else if (['PENDING','CLAIMED','PROCESSING','WAITING','WAITING_ADMIN','EXPIRED'].includes(t)) cls = 'warning';

  return `<span class="pill ${cls}">${esc(v || '-')}</span>`;
}

function rolePill(role){
  const r = String(role || 'USER').toUpperCase();
  let cls = 'info';

  if (r === 'RETAILER') cls = 'success';
  if (r === 'SUBADMIN') cls = 'warning';
  if (r === 'ADMIN') cls = 'danger';

  return `<span class="pill ${cls}">${esc(r)}</span>`;
}

function yesNoPill(v){
  return `<span class="pill ${v ? 'success' : 'danger'}">${v ? 'Yes' : 'No'}</span>`;
}

/* =========================
   MODAL / DRAWER
========================= */

function openSection(id){
  document.querySelectorAll('.section').forEach(x => x.classList.remove('active'));
  document.querySelectorAll('.nav-btn').forEach(x => x.classList.remove('active'));

  document.getElementById(id)?.classList.add('active');
  document.querySelector(`.nav-btn[data-section="${id}"]`)?.classList.add('active');

  loadSectionData(id, false).catch(err => {
    if (isSessionError(err)) {
      showLogin();
      setLoginError('Session expired. Please login again.');
      return;
    }

    showToast(err.message || 'Failed to load section data', 'error');
  });
}

function openDrawer(title, sub, html, footHtml=''){
  const drawer = document.getElementById('drawer');
  const titleNode = document.getElementById('drawerTitle');
  const subNode = document.getElementById('drawerSub');
  const bodyNode = document.getElementById('drawerBody');
  const footNode = document.getElementById('drawerFoot');

  if (titleNode) titleNode.textContent = title;
  if (subNode) subNode.textContent = sub || '';
  if (bodyNode) bodyNode.innerHTML = html;
  if (footNode) {
    footNode.innerHTML = footHtml || '<button class="btn ghost" id="drawerFootCloseDynamic">Close</button>';
  }

  drawer?.removeAttribute('inert');
  drawer?.setAttribute('aria-hidden', 'false');
  drawer?.classList.add('open');
  document.getElementById('drawerFootCloseDynamic')?.addEventListener('click', closeDrawer);
}

function closeDrawer(){
  const drawer = document.getElementById('drawer');
  drawer?.classList.remove('open');
  drawer?.setAttribute('aria-hidden', 'true');
  drawer?.setAttribute('inert', '');
}

function openModal(title, bodyHtml, footHtml){
  const titleNode = document.getElementById('modalTitle');
  const bodyNode = document.getElementById('modalBody');
  const footNode = document.getElementById('modalFoot');

  if (titleNode) titleNode.textContent = title;
  if (bodyNode) bodyNode.innerHTML = bodyHtml;
  if (footNode) footNode.innerHTML = footHtml;

  document.getElementById('modalWrap')?.classList.add('open');
}

function closeModal(){
  document.getElementById('modalWrap')?.classList.remove('open');
}

function shouldPauseAutoRefresh(){
  return document.getElementById('modalWrap')?.classList.contains('open')
      || document.getElementById('drawer')?.classList.contains('open')
      || !document.getElementById('loginView')?.classList.contains('hidden');
}

/* =========================
   PROXY REQUESTS
========================= */

async function proxyGet(action, params = {}, options = {}){
  const busy = options.busy !== false;
  const busyText = options.busyText || 'Loading...';

  if (busy) setBusy(true, busyText);

  try{
    const qs = new URLSearchParams(params).toString();

    const proxyUrl = window.ADMIN_PROXY_URL || '/api/admin/proxy.php';
    const res = await fetch(`${proxyUrl}?action=${encodeURIComponent(action)}${qs ? '&' + qs : ''}`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Cache-Control': 'no-cache'
      }
    });

    const text = await res.text();
    const json = readJsonSafeFromText(text);

    if (!res.ok || !json.ok) {
      const err = new Error(json.message || 'Request failed');
      err.code = json.code || 'ERROR';
      err.data = json.data || {};
      err.status = res.status;
      throw err;
    }

    return json.data || {};
  } finally {
    if (busy) setBusy(false);
  }
}

async function proxyPost(action, body = {}, withCsrf = true, options = {}){
  const busy = options.busy !== false;
  const busyText = options.busyText || 'Processing...';

  if (busy) setBusy(true, busyText);

  try{
    const headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Cache-Control': 'no-cache'
    };

    if (withCsrf && state.csrf) {
      headers['X-CSRF-TOKEN'] = state.csrf;
    }

    const proxyUrl = window.ADMIN_PROXY_URL || '/api/admin/proxy.php';
    const res = await fetch(`${proxyUrl}?action=${encodeURIComponent(action)}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers,
      body: JSON.stringify(body)
    });

    const text = await res.text();
    const json = readJsonSafeFromText(text);

    if (!res.ok || !json.ok) {
      const err = new Error(json.message || 'Request failed');
      err.code = json.code || 'ERROR';
      err.data = json.data || {};
      err.status = res.status;
      throw err;
    }

    return json.data || {};
  } finally {
    if (busy) setBusy(false);
  }
}

async function safeLoad(label, fn, options = {}){
  try {
    await fn(options);
    return { ok: true, label };
  } catch (err) {
    const msg = err?.message || 'Failed to fetch';
    const code = err?.code || 'NETWORK';

    log(`[${label}] ${msg} • ${code}`);

    return {
      ok: false,
      label,
      message: msg,
      code
    };
  }
}

/* =========================
   CHARTS
========================= */

function setChartBar(fillId, textId, value, total){
  const fill = document.getElementById(fillId);
  const text = document.getElementById(textId);

  if (!fill || !text) return;

  const safeTotal = Number(total || 0);
  const safeValue = Number(value || 0);
  const percent = safeTotal > 0 ? Math.round((safeValue / safeTotal) * 100) : 0;

  fill.style.width = percent + '%';
  text.textContent = percent + '%';
}

function renderOverviewCharts(){
  const topupSuccess = Number(document.getElementById('sumTopupSuccess')?.textContent || 0);
  const topupFailed = Number(document.getElementById('sumTopupFailed')?.textContent || 0);
  const topupPending = Number(state.counts?.pending || 0);
  const topupTotal = topupSuccess + topupFailed + topupPending;

  setChartBar('barTopupSuccess', 'barTopupSuccessText', topupSuccess, topupTotal);
  setChartBar('barTopupFailed', 'barTopupFailedText', topupFailed, topupTotal);
  setChartBar('barTopupPending', 'barTopupPendingText', topupPending, topupTotal);

  const bundleSuccess = Number(document.getElementById('sumBundleSuccess')?.textContent || 0);
  const bundleFailed = Number(document.getElementById('sumBundleFailed')?.textContent || 0);
  const bundlePending = Number(document.getElementById('sumBundlePending')?.textContent || 0);
  const bundleTotal = bundleSuccess + bundleFailed + bundlePending;

  setChartBar('barBundleSuccess', 'barBundleSuccessText', bundleSuccess, bundleTotal);
  setChartBar('barBundleFailed', 'barBundleFailedText', bundleFailed, bundleTotal);
  setChartBar('barBundlePending', 'barBundlePendingText', bundlePending, bundleTotal);
}

/* =========================
   LOGIN / SESSION
========================= */

async function loadMe(options = {}){
  const data = await proxyGet('me', {}, options);

  state.me = data.user || null;
  state.csrf = data.csrf || '';

  renderAdminInfo();
}

async function bootstrapSession(){
  try{
    await loadMe({ busyText: 'Checking session...' });

    const refreshSelect = document.getElementById('autoRefreshSelect');
    if (refreshSelect) refreshSelect.value = String(state.autoRefreshSeconds || 0);

    configureAutoRefresh();
    updateInteractiveState();
    updateStatusStrip();

    showApp();

    await loadDashboardFast();

    const params = new URLSearchParams(window.location.search || '');
    if (String(params.get('section') || '').toLowerCase() === 'support') {
      openSection('supportSection');
      const ticketId = String(params.get('ticket_id') || '').trim();
      if (ticketId) {
        setTimeout(() => openSupportTicket(ticketId), 700);
      }
    } else {
      openSection('dashboardSection');
    }

    startBackgroundDashboardLoad();

  }catch(_){
    showLogin();
  }
}

function renderAdminInfo(){
  const me = state.me || {};

  const adminName = document.getElementById('adminName');
  const adminRole = document.getElementById('adminRole');

  if (adminName) adminName.textContent = me.name || me.phone || '-';
  if (adminRole) adminRole.textContent = me.role || '-';
}

function clearAdminOtpTimer(){
  if (state.loginOtp.timer) {
    clearInterval(state.loginOtp.timer);
    state.loginOtp.timer = null;
  }
}

function updateAdminOtpTimerText(){
  const node = document.getElementById('adminOtpExpiresText');
  if (!node) return;

  const left = Math.max(0, Math.ceil((state.loginOtp.expiresAt - Date.now()) / 1000));
  node.textContent = left + ' seconds';

  if (left <= 0) {
    clearAdminOtpTimer();
    node.textContent = 'Expired';
  }
}

function startAdminOtpTimer(seconds){
  clearAdminOtpTimer();

  const safeSeconds = Number(seconds || 300);
  state.loginOtp.expiresAt = Date.now() + (safeSeconds * 1000);

  updateAdminOtpTimerText();

  state.loginOtp.timer = setInterval(() => {
    updateAdminOtpTimerText();
  }, 1000);
}

function openAdminOtpModal(data){
  state.loginOtp.preAuthToken = String(data.pre_auth_token || '');
  state.loginOtp.otpRequestId = String(data.otp_request_id || '');
  state.loginOtp.maskedPhone = String(data.masked_phone || '');
  state.loginOtp.expiresInSeconds = Number(data.expires_in_seconds || 300);
  state.loginOtp.trustDevice = true;

  openModal(
    'Verify Admin OTP',
    `
      <div class="otp-info-grid">
        <div class="otp-info-box">
          <label>Phone</label>
          <strong id="adminOtpMaskedPhoneText">${esc(state.loginOtp.maskedPhone || '-')}</strong>
        </div>

        <div class="otp-info-box">
          <label>Expires In</label>
          <strong id="adminOtpExpiresText">${esc(String(state.loginOtp.expiresInSeconds))} seconds</strong>
        </div>
      </div>

      <div class="field">
        <label>OTP Code</label>
        <input class="input" id="adminOtpInput" inputmode="numeric" maxlength="6" placeholder="Enter 6 digit OTP" autocomplete="one-time-code">
      </div>

      <label class="check-row">
        <input type="checkbox" id="adminTrustDeviceOtp" checked>
        <span>Trust this device after OTP verification</span>
      </label>

      <div class="muted" style="font-size:13px;line-height:1.55;">
        Enter the OTP sent to your admin phone number to complete login.
      </div>
    `,
    `
      <button class="btn ghost" onclick="closeAdminOtpModal()">Cancel</button>
      <button class="btn blue" onclick="resendAdminLoginOtp()">Resend OTP</button>
      <button class="btn brand" onclick="verifyAdminLoginOtp()">Verify OTP</button>
    `
  );

  startAdminOtpTimer(state.loginOtp.expiresInSeconds);

  setTimeout(() => {
    const otpInput = document.getElementById('adminOtpInput');

    if (otpInput) {
      otpInput.focus();

      otpInput.addEventListener('input', () => {
        otpInput.value = otpInput.value.replace(/\D+/g, '').slice(0, 6);
      });

      otpInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          verifyAdminLoginOtp();
        }
      });
    }
  }, 150);
}

function closeAdminOtpModal(){
  clearAdminOtpTimer();

  state.loginOtp.preAuthToken = '';
  state.loginOtp.otpRequestId = '';
  state.loginOtp.maskedPhone = '';
  state.loginOtp.expiresInSeconds = 300;
  state.loginOtp.expiresAt = 0;

  closeModal();
}

async function completeAdminLogin(data, logMessage = 'Admin login successful.'){
  state.me = data.user || null;
  state.csrf = data.csrf || '';

  renderAdminInfo();

  const refreshSelect = document.getElementById('autoRefreshSelect');
  if (refreshSelect) refreshSelect.value = String(state.autoRefreshSeconds || 0);

  configureAutoRefresh();
  updateInteractiveState();
  updateStatusStrip();

  showApp();

  log(logMessage);
  showToast('Login successful', 'ok');

  await loadDashboardFast();
  openSection('dashboardSection');
  startBackgroundDashboardLoad();
}

async function doLogin(){
  setLoginError('');

  const phone = document.getElementById('loginPhone')?.value.trim() || '';
  const phoneCountry = (document.getElementById('loginPhoneCountry')?.value || 'BD').toUpperCase();
  const password = document.getElementById('loginPassword')?.value || '';

  if (!phone || !password) {
    setLoginError('Phone and password are required');
    return;
  }

  const digits = phone.replace(/\D+/g, '');
  const validPhone = phoneCountry === 'MY'
    ? /^(?:011\d{8}|01[02-9]\d{7}|6011\d{8}|601[02-9]\d{7}|11\d{8}|1[02-9]\d{7})$/.test(digits)
    : /^(?:01[3-9]\d{8}|8801[3-9]\d{8}|1[3-9]\d{8})$/.test(digits);
  if (!validPhone) {
    setLoginError(phoneCountry === 'MY' ? 'Invalid Malaysia number' : 'Invalid Bangladesh number');
    return;
  }

  try{
    const data = await proxyPost('login', {
      phone,
      phone_country: phoneCountry,
      password,
      trust_device: true,
      device_id: 'ADMIN_WEB',
      device_name: 'Admin Dashboard',
      browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ''
    }, false, { busyText: 'Logging in...' });

    if (data.require_otp) {
      openAdminOtpModal(data);
      showToast('OTP sent to admin phone', 'info');
      return;
    }

    await completeAdminLogin(data, 'Admin login successful.');
  }catch(err){
    setLoginError(err.message || 'Login failed');
  }
}

async function verifyAdminLoginOtp(){
  const otp = document.getElementById('adminOtpInput')?.value.trim() || '';
  const trustDevice = document.getElementById('adminTrustDeviceOtp')?.checked !== false;

  if (!state.loginOtp.preAuthToken || !state.loginOtp.otpRequestId) {
    alert('OTP session missing. Please login again.');
    closeAdminOtpModal();
    return;
  }

  if (!/^\d{6}$/.test(otp)) {
    alert('Please enter valid 6 digit OTP.');
    return;
  }

  try{
    const data = await proxyPost('login_verify_otp', {
      pre_auth_token: state.loginOtp.preAuthToken,
      otp_request_id: state.loginOtp.otpRequestId,
      otp,
      trust_device: trustDevice,
      device_id: 'ADMIN_WEB',
      device_name: 'Admin Dashboard'
    }, false, { busyText: 'Verifying OTP...' });

    clearAdminOtpTimer();
    closeModal();

    await completeAdminLogin(data, 'Admin OTP verified successfully.');
  }catch(err){
    alert(err.message || 'OTP verification failed');
  }
}

async function resendAdminLoginOtp(){
  if (!state.loginOtp.preAuthToken || !state.loginOtp.otpRequestId) {
    alert('OTP session missing. Please login again.');
    closeAdminOtpModal();
    return;
  }

  try{
    const data = await proxyPost('login_resend_otp', {
      pre_auth_token: state.loginOtp.preAuthToken,
      otp_request_id: state.loginOtp.otpRequestId
    }, false, { busyText: 'Resending OTP...' });

    state.loginOtp.preAuthToken = String(data.pre_auth_token || state.loginOtp.preAuthToken);
    state.loginOtp.otpRequestId = String(data.otp_request_id || state.loginOtp.otpRequestId);
    state.loginOtp.maskedPhone = String(data.masked_phone || state.loginOtp.maskedPhone);
    state.loginOtp.expiresInSeconds = Number(data.expires_in_seconds || 300);

    const phoneNode = document.getElementById('adminOtpMaskedPhoneText');
    if (phoneNode) phoneNode.textContent = state.loginOtp.maskedPhone || '-';

    startAdminOtpTimer(state.loginOtp.expiresInSeconds);

    const otpInput = document.getElementById('adminOtpInput');
    if (otpInput) otpInput.value = '';

    showToast('OTP resent successfully', 'info');
  }catch(err){
    alert(err.message || 'Failed to resend OTP');
  }
}

async function doLogout(){
  clearAdminOtpTimer();

  try{
    await proxyPost('logout', {}, true, { busyText: 'Logging out...' });
  }catch(_){}

  if (state.autoRefreshTimer) {
    clearInterval(state.autoRefreshTimer);
    state.autoRefreshTimer = null;
  }

  if (state.autoRefreshUiTimer) {
    clearInterval(state.autoRefreshUiTimer);
    state.autoRefreshUiTimer = null;
  }

  state.me = null;
  state.csrf = '';
  state.lastRefreshAt = 0;
  state.nextRefreshAt = 0;
  state.backgroundStarted = false;

  state.loaded = {
    counts: false,
    topups: false,
    bundles: false,
    bundleOffers: false,
    doneTopups: false,
    doneBundles: false,
    users: false,
    workers: false,
    operators: false,
    appConfig: false
  };

  updateInteractiveState();
  updateStatusStrip();

  closeModal();
  closeDrawer();
  showLogin();
  showToast('Logged out', 'info');
}

/* =========================
   FAST LOADING SYSTEM
========================= */

async function loadDashboardFast(){
  setBusy(true, 'Loading dashboard...');

  try{
    const results = await Promise.allSettled([
      safeLoad('Counts', loadCounts, { busy:false, silentLog:true }),
      safeLoad('Pending Topups', loadTopups, { busy:false, silentLog:true }),
      safeLoad('Pending Bundles', loadBundles, { busy:false, silentLog:true }),
      safeLoad('App Config', loadAppConfigStatus, { busy:false, silentLog:true })
    ]);

    state.lastRefreshAt = Date.now();

    if (state.autoRefreshSeconds > 0) {
      state.nextRefreshAt = Date.now() + (state.autoRefreshSeconds * 1000);
    }

    updateStatusStrip();
    renderOverviewCharts();

    const failed = results.filter(x => x.status === 'fulfilled' && x.value && !x.value.ok);

    if (failed.length) {
      log(`Dashboard loaded with ${failed.length} warning(s).`);
    } else {
      log('Dashboard fast load completed.');
    }
  } finally {
    setBusy(false);
  }
}

function startBackgroundDashboardLoad(){
  if (state.backgroundStarted) return;

  state.backgroundStarted = true;

  const jobs = [
    async () => loadDoneTopupsSummary({ busy:false, silentLog:true }),
    async () => loadDoneBundlesSummary({ busy:false, silentLog:true }),
    async () => loadUsers({ busy:false, silentLog:true }),
    async () => loadWorkersStatus({ busy:false, silentLog:true }),
    async () => loadOperators({ busy:false, silentLog:true }),
    async () => loadBundleOffers({ busy:false, silentLog:true })
  ];

  let delay = 500;

  jobs.forEach(job => {
    setTimeout(async () => {
      try{
        await job();
      }catch(err){
        if (!isSessionError(err)) {
          log(err.message || 'Background load failed');
        }
      }
    }, delay);

    delay += 700;
  });
}

async function loadSectionData(sectionId, force = false){
  if (sectionId === 'dashboardSection') {
    if (force || !state.loaded.counts) {
      await loadCounts({ busy:false, silentLog:true });
    }

    if (force || !state.loaded.bundles) {
      await loadBundles({ busy:false, silentLog:true });
    }

    if (force || !state.loaded.appConfig) {
      await loadAppConfigStatus({ busy:false, silentLog:true });
    }

    startBackgroundDashboardLoad();
    return;
  }

  if (sectionId === 'topupSection') {
    if (force || !state.loaded.topups) {
      await loadTopups({ busyText:'Loading topup list...' });
    }
    return;
  }

  if (sectionId === 'bundleSection') {
    if (force || !state.loaded.bundles) {
      await loadBundles({ busyText:'Loading bundle requests...' });
    }
    return;
  }

  if (sectionId === 'bundleOffersSection') {
    if (force || !state.loaded.bundleOffers) {
      await loadBundleOffers({ busyText:'Loading bundle offers...' });
    }
    return;
  }

  if (sectionId === 'addMoneySection') {
    if (force || !state.loaded.addMoney) {
      await loadAddMoneyRequests({ busyText:'Loading add money requests...' });
    }
    return;
  }

  if (sectionId === 'supportSection') {
    if (force || !state.loaded.support) {
      await loadSupportAdmin({ busyText:'Loading support center...' });
    }
    return;
  }

  if (sectionId === 'usersSection') {
    if (force || !state.loaded.users) {
      await loadUsers({ busyText:'Loading users...' });
    }
    return;
  }

  if (sectionId === 'operatorsSection') {
    if (force || !state.loaded.operators) {
      await loadOperators({ busyText:'Loading operators...' });
    }
    return;
  }

  if (sectionId === 'zsky24Section' && typeof window.loadZSky24Admin === 'function') {
    await window.loadZSky24Admin(force);
  }
}

async function refreshCurrentView(silent = false){
  const sectionId = activeSectionId();

  const options = silent
    ? { busy:false, silentLog:true }
    : { busyText:'Refreshing current page...' };

  if (!silent) setBusy(true, 'Refreshing current page...');

  try{
    await safeLoad('Session', loadMe, { busy:false, silentLog:true });
    await safeLoad('Counts', loadCounts, { busy:false, silentLog:true });
    await safeLoad('App Config', loadAppConfigStatus, { busy:false, silentLog:true });

    await loadSectionData(sectionId, true);

    if (sectionId === 'dashboardSection') {
      await safeLoad('Done Topups', loadDoneTopupsSummary, { busy:false, silentLog:true });
      await safeLoad('Done Bundles', loadDoneBundlesSummary, { busy:false, silentLog:true });
    }

    state.lastRefreshAt = Date.now();

    if (state.autoRefreshSeconds > 0) {
      state.nextRefreshAt = Date.now() + (state.autoRefreshSeconds * 1000);
    }

    updateStatusStrip();
    renderOverviewCharts();

    if (!silent) {
      log('Current page refreshed.');
      showToast('Refreshed', 'info');
    }
  }catch(err){
    if (isSessionError(err)) {
      showLogin();
      setLoginError('Session expired. Please login again.');
      return;
    }

    if (!silent) {
      alert(err.message || 'Refresh failed');
    }

    log(err.message || 'Refresh failed');
  }finally{
    if (!silent) setBusy(false);
  }
}

async function refreshAll(silent = false){
  await refreshCurrentView(silent);
}

/* =========================
   AUTO REFRESH
========================= */

function configureAutoRefresh(){
  if (state.autoRefreshTimer) {
    clearInterval(state.autoRefreshTimer);
    state.autoRefreshTimer = null;
  }

  if (state.autoRefreshUiTimer) {
    clearInterval(state.autoRefreshUiTimer);
    state.autoRefreshUiTimer = null;
  }

  localStorage.setItem('zaw_admin_auto_refresh', String(state.autoRefreshSeconds || 0));

  if (state.autoRefreshSeconds <= 0) {
    state.nextRefreshAt = 0;
    updateStatusStrip();
    return;
  }

  state.nextRefreshAt = Date.now() + (state.autoRefreshSeconds * 1000);

  state.autoRefreshTimer = setInterval(async () => {
    if (shouldPauseAutoRefresh()) {
      updateStatusStrip();
      return;
    }

    try {
      await refreshCurrentView(true);
    } catch(_) {}

    state.nextRefreshAt = Date.now() + (state.autoRefreshSeconds * 1000);
    updateStatusStrip();
  }, state.autoRefreshSeconds * 1000);

  state.autoRefreshUiTimer = setInterval(() => {
    updateStatusStrip();
  }, 1000);

  updateStatusStrip();
}

/* =========================
   LOAD COUNTS / TOPUPS
========================= */

async function loadCounts(options = {}){
  const data = await proxyGet('counts', {}, options);

  const pending = Number(data.pending || 0);
  const claimed = Number(data.claimed || 0);
  const processing = Number(data.processing || 0);
  const done = Number(data.done || 0);

  if (document.getElementById('countPending')) document.getElementById('countPending').textContent = pending;
  if (document.getElementById('countClaimed')) document.getElementById('countClaimed').textContent = claimed;
  if (document.getElementById('countProcessing')) document.getElementById('countProcessing').textContent = processing;
  if (document.getElementById('countDone')) document.getElementById('countDone').textContent = done;

  if (document.getElementById('bottomPending')) document.getElementById('bottomPending').textContent = pending;
  if (document.getElementById('bottomClaimed')) document.getElementById('bottomClaimed').textContent = claimed;
  if (document.getElementById('bottomProcessing')) document.getElementById('bottomProcessing').textContent = processing;
  if (document.getElementById('bottomDone')) document.getElementById('bottomDone').textContent = done;

  state.counts.pending = pending;
  state.counts.claimed = claimed;
  state.counts.processing = processing;
  state.counts.done = done;
  state.loaded.counts = true;

  renderOverviewCharts();
}

async function loadTopups(options = {}){
  const data = await proxyGet('topups', {
    bucket: state.topupTab,
    page: 1,
    limit: 50
  }, options);

  state.topups = data.items || [];
  state.loaded.topups = true;

  renderTopups();

  if (!options.silentLog) log(`Loaded ${state.topupTab} topup requests.`);
}

function renderTopups(){
  const q = document.getElementById('topupSearch')?.value.trim().toLowerCase() || '';
  const rows = state.topups.filter(item => !q || JSON.stringify(item).toLowerCase().includes(q));
  const tbody = document.getElementById('topupTableBody');

  if (!tbody) return;

  if (!rows.length){
    tbody.innerHTML = '<tr><td colspan="6" class="empty">No topup request found.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(item => {
    const requestId = item.request_id || item.id || '';
    const uid = item.uid || '-';
    const userName = item.user_name || item.name || (item.created_by_admin ? 'Admin Direct' : '-');
    const phone = item.phone || item.user_phone || '-';
    const operator = item.operator || '-';
    const topupNumber = item.topup_number || item.number || '-';
    const amount = item.amount ?? item.amount_bdt ?? 0;
    const status = item.status || state.topupTab.toUpperCase();
    const ts = item.created_at || item.updated_at || item.claimed_at || item.processing_at || 0;
    const finalStatus = String(status).toUpperCase();

    return `
      <tr>
        <td>
          <div><strong>${esc(requestId)}</strong></div>
          <div class="muted" style="font-size:12px;">UID: ${esc(uid)}</div>
        </td>
        <td>
          <div>${esc(userName)}</div>
          <div class="muted" style="font-size:12px;">${esc(phone)}</div>
        </td>
        <td>
          <div>${esc(operator)} • ${esc(topupNumber)}</div>
          <div class="muted" style="font-size:12px;">Amount: ${money(amount)}</div>
        </td>
        <td>${statusPill(status)}</td>
        <td>${fmtTs(ts)}</td>
        <td>
          <div class="row-actions">
            <button class="mini-btn" onclick="viewTopup('${esc(requestId)}')">View</button>
            ${
              finalStatus !== 'SUCCESS' && finalStatus !== 'FAILED'
                ? `
                  <button class="mini-btn" onclick="openTopupAction('${esc(requestId)}','success')">Success</button>
                  <button class="mini-btn" onclick="openTopupAction('${esc(requestId)}','failed')">Failed</button>
                `
                : ''
            }
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

async function viewTopup(requestId){
  try{
    const data = await proxyGet('topup_get', { request_id: requestId }, { busyText: 'Loading topup details...' });
    const req = data.request || {};
    const user = data.user || {};
    const wallet = data.wallet || {};

    openDrawer(
      `Topup Request ${requestId}`,
      `${req.operator || '-'} • ${req.topup_number || req.number || '-'}`,
      `
        <div class="detail-grid">
          <div class="detail-item"><label>Status</label><strong>${statusPill(req.status || '-')}</strong></div>
          <div class="detail-item"><label>Amount</label><strong>${money(req.amount ?? req.amount_bdt ?? 0)}</strong></div>
          <div class="detail-item"><label>Topup Number</label><strong>${esc(req.topup_number || req.number || '-')}</strong></div>
          <div class="detail-item"><label>Operator</label><strong>${esc(req.operator || '-')}</strong></div>
          <div class="detail-item"><label>UID</label><strong>${esc(req.uid || '-')}</strong></div>
          <div class="detail-item"><label>Created</label><strong>${fmtTs(req.created_at || req.updated_at || 0)}</strong></div>

          <div class="detail-item"><label>User Name</label><strong>${esc(user.name || '-')}</strong></div>
          <div class="detail-item"><label>User Phone</label><strong>${esc(user.phone || '-')}</strong></div>
          <div class="detail-item"><label>User Email</label><strong>${esc(user.email || '-')}</strong></div>
          <div class="detail-item"><label>User Status</label><strong>${esc(user.status || '-')}</strong></div>

          <div class="detail-item"><label>Available Balance</label><strong>${money(wallet.available_balance)}</strong></div>
          <div class="detail-item"><label>Hold Balance</label><strong>${money(wallet.hold_balance)}</strong></div>
          <div class="detail-item"><label>Wallet Updated</label><strong>${fmtTs(wallet.updated_at)}</strong></div>
        </div>

        <div class="detail-item" style="margin-top:16px;">
          <label>Raw Request JSON</label>
          <div class="log-box">${esc(JSON.stringify(req, null, 2))}</div>
        </div>
      `
    );
  }catch(err){
    alert(err.message || 'Failed to load topup details');
  }
}

/* =========================
   BUNDLES
========================= */

async function loadBundles(options = {}){
  const data = await proxyGet('bundles', {}, options);

  state.bundles = data.items || [];
  state.loaded.bundles = true;

  renderBundles();

  const dashBundleCount = document.getElementById('dashBundleCount');
  if (dashBundleCount) dashBundleCount.textContent = state.bundles.length;

  if (document.getElementById('sumBundlePending')) {
    document.getElementById('sumBundlePending').textContent = state.bundles.length;
  }

  renderOverviewCharts();

  if (!options.silentLog) log('Loaded bundle pending list.');
}

function renderBundles(){
  const tbody = document.getElementById('bundleTableBody');

  if (!tbody) return;

  if (!state.bundles.length){
    tbody.innerHTML = '<tr><td colspan="5" class="empty">No bundle request found.</td></tr>';
    return;
  }

  tbody.innerHTML = state.bundles.map(item => {
    const requestId = item.request_id || item.id || '';
    const uid = item.uid || '-';
    const phone = item.user_phone || item.phone || '-';
    const bundleName = item.bundle_name || item.package_name || item.plan_name || '-';
    const operator = item.operator || '-';
    const ts = item.created_at || item.updated_at || 0;

    const priceAmount = bundlePriceAmount(item);
    const payAmount = bundlePayAmount(item);
    const userCommission = Number(item.user_commission || item.customer_commission || item.user_discount || 0);
    const subadminProfit = Number(item.subadmin_profit || item.subadmin_commission || 0);

    return `
      <tr>
        <td>
          <div><strong>${esc(requestId)}</strong></div>
          <div class="muted" style="font-size:12px;">UID: ${esc(uid)}</div>
        </td>

        <td>${esc(phone)}</td>

        <td>
          <div>${esc(operator)} • ${esc(bundleName)}</div>
          <div class="muted" style="font-size:12px;">
            Amount: ${money(payAmount)}
          </div>
          <div class="muted" style="font-size:12px;">
            Price: ${money(priceAmount)} • User Commission: ${money(userCommission)} • Subadmin Profit: ${money(subadminProfit)}
          </div>
        </td>

        <td>${fmtTs(ts)}</td>

        <td>
          <div class="row-actions">
            <button class="mini-btn" onclick="openBundleAction('${esc(requestId)}','success')">Success</button>
            <button class="mini-btn" onclick="openBundleAction('${esc(requestId)}','failed')">Failed</button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function openBundleAction(requestId, type){
  const isSuccess = type === 'success';
  const defaultMessage = isSuccess
    ? 'Bundle successful'
    : 'Bundle failed';

  openModal(
    isSuccess ? 'Mark Bundle Success' : 'Mark Bundle Failed',
    `
      <div class="form-grid">
        <div class="form-full">
          <label>Request ID</label>
          <input class="input" id="bundleRequestId" value="${esc(requestId)}" readonly>
        </div>

        <div class="form-full">
          <label>Message / Note</label>
          <textarea class="input" id="bundleMessage" rows="4">${esc(defaultMessage)}</textarea>
        </div>

        <div class="form-full">
          <div class="muted" style="font-size:13px;">
            This action will update bundle status and refresh related data.
          </div>
        </div>
      </div>
    `,
    `
      <button class="btn ghost" id="bundleCancelBtn" onclick="closeModal()">Cancel</button>
      <button class="btn ${isSuccess ? 'brand' : 'red'}" id="bundleSubmitBtn" onclick="submitBundleAction('${type}')">
        ${isSuccess ? 'Confirm Success' : 'Confirm Failed'}
      </button>
    `
  );
}

async function submitBundleAction(type){
  const requestId = document.getElementById('bundleRequestId')?.value.trim() || '';
  const message = document.getElementById('bundleMessage')?.value.trim() || '';

  if (!requestId){
    alert('Request ID not found');
    return;
  }

  const lockKey = `bundle_${type}_${requestId}`;
  if (submitLocks[lockKey]) return;

  const ok = confirm(
    type === 'success'
      ? `Are you sure you want to mark ${requestId} as SUCCESS?`
      : `Are you sure you want to mark ${requestId} as FAILED?`
  );

  if (!ok) return;

  submitLocks[lockKey] = true;

  setActionBtnLoading(
    'bundleSubmitBtn',
    true,
    type === 'success' ? 'Marking Success...' : 'Marking Failed...'
  );

  try{
    await proxyPost(
      type === 'success' ? 'bundle_success' : 'bundle_failed',
      { request_id: requestId, message },
      true,
      {
        busyText: type === 'success' ? 'Marking bundle success...' : 'Marking bundle failed...'
      }
    );

    closeModal();

    log(`Bundle ${requestId} marked ${type}.`);
    showToast(`Bundle ${requestId} marked ${type}`, 'ok');

    await Promise.allSettled([
      loadBundles({ busy:false, silentLog:true }),
      loadDoneBundlesSummary({ busy:false, silentLog:true }),
      loadUsers({ busy:false, silentLog:true })
    ]);
  }catch(err){
    alert(err.message || 'Bundle action failed');
  }finally{
    submitLocks[lockKey] = false;
    setActionBtnLoading('bundleSubmitBtn', false);
  }
}

/* =========================
   BUNDLE OFFERS
========================= */

function adminOfferBool(value, fallback = false){
  return boolFromValue(value, fallback);
}

function isBundleOfferDeleted(item){
  const status = String(item?.status || '').toUpperCase();

  if (['DELETED','REMOVED','TRASHED'].includes(status)) return true;
  if (adminOfferBool(item?.deleted, false)) return true;
  if (adminOfferBool(item?.is_deleted, false)) return true;
  if (Number(item?.deleted_at || 0) > 0) return true;

  return false;
}

function getBundleOfferStatus(item){
  if (isBundleOfferDeleted(item)) {
    return 'DELETED';
  }

  const expiresAt = Number(item?.expires_at || item?.expire_at || 0);
  const activeRaw = item?.active ?? item?.is_active ?? true;
  const statusRaw = String(item?.status || '').toUpperCase();
  const expiredFlag = adminOfferBool(item?.expired, false);

  if (statusRaw === 'EXPIRED' || expiredFlag) {
    return 'EXPIRED';
  }

  if (expiresAt > 0) {
    const expiresMs = String(Math.trunc(expiresAt)).length <= 10 ? expiresAt * 1000 : expiresAt;
    if (expiresMs <= Date.now()) return 'EXPIRED';
  }

  if (statusRaw === 'INACTIVE') {
    return 'INACTIVE';
  }

  if (statusRaw && statusRaw !== 'ACTIVE') {
    return statusRaw;
  }

  return boolFromValue(activeRaw, true) ? 'ACTIVE' : 'INACTIVE';
}

function getBundleOfferId(item){
  return String(item?.offer_id || item?.bundle_id || item?.id || '');
}

function bundleOfferDurationText(item){
  const durationValue = item?.duration_value || item?.validity_value || item?.expire_after_value || '';
  const durationUnit = item?.duration_unit || item?.validity_unit || item?.expire_after_unit || '';
  const expiresAt = Number(item?.expires_at || item?.expire_at || 0);

  const parts = [];

  if (durationValue) {
    parts.push(`${durationValue} ${String(durationUnit || '').toLowerCase() || 'unit'}`);
  }

  if (expiresAt > 0) {
    parts.push(`Expires: ${fmtTs(expiresAt)}`);
  }

  return parts.length ? parts.join(' • ') : '-';
}

function bundleOfferVisibilityText(item){
  const visibility = String(item?.visibility || item?.scope || 'GLOBAL').toUpperCase();
  const subUid = item?.subadmin_uid || item?.owner_subadmin_uid || item?.customized_by_uid || '';

  if (visibility === 'SUBADMIN_ONLY' || visibility === 'SUBADMIN') {
    return `Subadmin only${subUid ? ' • ' + subUid : ''}`;
  }

  if (visibility === 'PRIVATE') {
    return `Private${subUid ? ' • ' + subUid : ''}`;
  }

  return 'Global';
}

async function loadBundleOffers(options = {}){
  try{
    const data = await proxyGet('bundle_offers', {
      include_inactive: '1',
      include_deleted: '0'
    }, options);

    state.bundleOffers = data.items || [];
    state.loaded.bundleOffers = true;

    renderBundleOffers();

    if (!options.silentLog) log('Loaded bundle offers.');
  }catch(err){
    state.bundleOffers = state.bundleOffers || [];
    renderBundleOffers();

    if (!options.silentLog) {
      log(err.message || 'Failed to load bundle offers.');
    }

    throw err;
  }
}

function renderBundleOffers(){
  const tbody = document.getElementById('bundleOffersTableBody');
  if (!tbody) return;

  const q = document.getElementById('bundleOfferSearch')?.value.trim().toLowerCase() || '';
  const filterStatus = String(document.getElementById('bundleOfferStatusFilter')?.value || '').toUpperCase();

  const rows = (state.bundleOffers || []).filter(item => {
    const status = getBundleOfferStatus(item);

    if (status === 'DELETED' && filterStatus !== 'DELETED') {
      return false;
    }

    if (filterStatus && status !== filterStatus) {
      return false;
    }

    if (q && !JSON.stringify(item).toLowerCase().includes(q)) {
      return false;
    }

    return true;
  });

  if (!rows.length){
    tbody.innerHTML = '<tr><td colspan="8" class="empty">No bundle offer found.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(item => {
    const offerId = getBundleOfferId(item);
    const bundleName = item.bundle_name || item.package_name || item.plan_name || item.name || '-';
    const operator = item.operator || '-';

    const priceAmount = getBundleOfferPriceAmount(item);
    const adminCommission = getBundleOfferAdminCommission(item);
    const userCommission = getBundleOfferUserCommission(item);
    const youPay = getBundleOfferYouPay(item);

    const status = getBundleOfferStatus(item);

    return `
      <tr>
        <td>
          <div><strong>${esc(bundleName)}</strong></div>
          <div class="muted" style="font-size:12px;">Offer ID: ${esc(offerId || '-')}</div>
          <div class="muted" style="font-size:12px;">${esc(item.note || item.description || '')}</div>
        </td>

        <td>${esc(operator)}</td>

        <td>
          <div><strong>BDT ${money(priceAmount)}</strong></div>
          <div class="muted" style="font-size:12px;">User Pay: BDT ${money(youPay)}</div>
          ${
            userCommission > 0
              ? `<div class="muted" style="font-size:12px;">User Commission: BDT ${money(userCommission)}</div>`
              : ''
          }
        </td>

        <td>
          <strong>BDT ${money(adminCommission)}</strong>
        </td>

        <td>${esc(bundleOfferDurationText(item))}</td>

        <td>${esc(bundleOfferVisibilityText(item))}</td>

        <td>${statusPill(status)}</td>

        <td>
          <div class="row-actions">
            <button class="mini-btn" onclick="openBundleOfferModal('${esc(offerId)}')">Edit</button>
            ${
              status !== 'EXPIRED' && status !== 'DELETED'
                ? `<button class="mini-btn" onclick="expireBundleOffer('${esc(offerId)}')">Expire</button>`
                : ''
            }
            ${
              status !== 'DELETED'
                ? `<button class="mini-btn" onclick="deleteBundleOffer('${esc(offerId)}')">Delete</button>`
                : ''
            }
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

async function openBundleOfferModal(offerId = ''){
  let item = {};

  if (offerId) {
    try{
      const data = await proxyGet('bundle_offer_get', { offer_id: offerId }, { busyText: 'Loading bundle offer...' });
      item = data.offer || data.item || {};
    }catch(_){
      item = (state.bundleOffers || []).find(row => getBundleOfferId(row) === String(offerId)) || {};
    }
  }

  const currentStatus = getBundleOfferStatus(item || {});
  const durationUnit = String(item.duration_unit || item.validity_unit || item.expire_after_unit || 'DAY').toUpperCase();

  const priceAmount = getBundleOfferPriceAmount(item || {});
  const adminCommission = getBundleOfferAdminCommission(item || {});

  const statusForSelect = currentStatus === 'EXPIRED' ? 'ACTIVE' : currentStatus;

  openModal(
    offerId ? 'Edit Bundle Offer' : 'Add Bundle Offer',
    `
      <div class="form-grid">
        <div class="form-full">
          <label>Offer ID</label>
          <input class="input" id="bundleOfferId" value="${esc(offerId || getBundleOfferId(item))}" readonly placeholder="Auto generated">
        </div>

        <div>
          <label>Bundle Name</label>
          <input class="input" id="bundleOfferName" value="${esc(item.bundle_name || item.package_name || item.plan_name || item.name || '')}" placeholder="Example: 70 GB 30 DAY 450 BDT">
        </div>

        <div>
          <label>Operator</label>
          <select id="bundleOfferOperator" class="input">
            <option value="GP" ${String(item.operator || '').toUpperCase() === 'GP' ? 'selected' : ''}>GP</option>
            <option value="ROBI" ${String(item.operator || '').toUpperCase() === 'ROBI' ? 'selected' : ''}>ROBI</option>
            <option value="BL" ${String(item.operator || '').toUpperCase() === 'BL' ? 'selected' : ''}>Banglalink</option>
            <option value="AIRTEL" ${String(item.operator || '').toUpperCase() === 'AIRTEL' ? 'selected' : ''}>AIRTEL</option>
            <option value="TT" ${String(item.operator || '').toUpperCase() === 'TT' ? 'selected' : ''}>TT</option>
          </select>
        </div>

        <div>
          <label>Offer Price BDT</label>
          <input class="input" id="bundleOfferAmount" type="number" step="0.01" min="0" value="${esc(priceAmount || '')}" placeholder="450">
        </div>

        <div>
          <label>Admin Commission BDT</label>
          <input class="input" id="bundleOfferAdminCommission" type="number" step="0.01" min="0" value="${esc(adminCommission || '')}" placeholder="50">
        </div>

        <div>
          <label>Duration Value</label>
          <input class="input" id="bundleOfferDurationValue" type="number" step="1" min="1" value="${esc(item.duration_value || item.validity_value || item.expire_after_value || 1)}" placeholder="30">
        </div>

        <div>
          <label>Duration Unit</label>
          <select id="bundleOfferDurationUnit" class="input">
            <option value="HOUR" ${durationUnit === 'HOUR' || durationUnit === 'HOURS' ? 'selected' : ''}>Hour</option>
            <option value="DAY" ${durationUnit === 'DAY' || durationUnit === 'DAYS' ? 'selected' : ''}>Day</option>
            <option value="MONTH" ${durationUnit === 'MONTH' || durationUnit === 'MONTHS' ? 'selected' : ''}>Month</option>
          </select>
        </div>

        <div>
          <label>Status</label>
          <select id="bundleOfferStatus" class="input">
            <option value="ACTIVE" ${statusForSelect === 'ACTIVE' ? 'selected' : ''}>ACTIVE</option>
            <option value="INACTIVE" ${statusForSelect === 'INACTIVE' ? 'selected' : ''}>INACTIVE</option>
          </select>
          ${
            currentStatus === 'EXPIRED'
              ? `<div class="muted" style="margin-top:6px;font-size:12px;">This offer is expired. Saving as ACTIVE will reactivate it with new expiry.</div>`
              : ''
          }
        </div>

        <div>
          <label>Visibility</label>
          <select id="bundleOfferVisibility" class="input">
            <option value="GLOBAL" ${String(item.visibility || item.scope || 'GLOBAL').toUpperCase() === 'GLOBAL' ? 'selected' : ''}>GLOBAL</option>
            <option value="SUBADMIN_ONLY" ${String(item.visibility || item.scope || '').toUpperCase() === 'SUBADMIN_ONLY' ? 'selected' : ''}>SUBADMIN_ONLY</option>
          </select>
        </div>

        <div class="form-full">
          <label>Note / Description</label>
          <textarea class="input" id="bundleOfferNote" rows="4" placeholder="Short bundle details">${esc(item.note || item.description || '')}</textarea>
        </div>

        <div class="form-full">
          <div class="muted" style="font-size:13px;line-height:1.55;">
            Price হলো original bundle price. Admin Commission আলাদা থাকবে। User/Customer commission অনুযায়ী user pay calculate হবে।
          </div>
        </div>
      </div>
    `,
    `
      <button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn brand" id="bundleOfferSaveBtn" onclick="saveBundleOffer()">Save Offer</button>
    `
  );
}

async function saveBundleOffer(){
  const offerId = document.getElementById('bundleOfferId')?.value.trim() || '';
  const bundleName = document.getElementById('bundleOfferName')?.value.trim() || '';
  const operator = document.getElementById('bundleOfferOperator')?.value.trim() || '';

  const priceAmount = Number(document.getElementById('bundleOfferAmount')?.value || 0);
  const adminCommission = Number(document.getElementById('bundleOfferAdminCommission')?.value || 0);

  const durationValue = Number(document.getElementById('bundleOfferDurationValue')?.value || 0);
  const durationUnit = document.getElementById('bundleOfferDurationUnit')?.value.trim() || 'DAY';
  const status = document.getElementById('bundleOfferStatus')?.value.trim() || 'ACTIVE';
  const visibility = document.getElementById('bundleOfferVisibility')?.value.trim() || 'GLOBAL';
  const note = document.getElementById('bundleOfferNote')?.value.trim() || '';

  if (!bundleName) {
    alert('Bundle name is required');
    return;
  }

  if (!operator) {
    alert('Operator is required');
    return;
  }

  if (priceAmount <= 0) {
    alert('Offer price must be greater than zero');
    return;
  }

  if (adminCommission < 0) {
    alert('Commission cannot be negative');
    return;
  }

  if (adminCommission > priceAmount) {
    alert('Commission cannot be greater than offer price');
    return;
  }

  if (durationValue <= 0) {
    alert('Duration value must be greater than zero');
    return;
  }

  const lockKey = `bundle_offer_save_${offerId || bundleName}`;
  if (submitLocks[lockKey]) return;

  const durationSeconds = calcDurationSeconds(durationValue, durationUnit);
  const expiresAt = status === 'ACTIVE' && durationSeconds > 0
    ? Math.floor(Date.now() / 1000) + durationSeconds
    : 0;

  submitLocks[lockKey] = true;
  setActionBtnLoading('bundleOfferSaveBtn', true, 'Saving...');

  try{
    const data = await proxyPost('bundle_offer_save', {
      offer_id: offerId,

      bundle_name: bundleName,
      package_name: bundleName,
      name: bundleName,

      operator,

      amount: priceAmount,
      price_amount: priceAmount,
      offer_price: priceAmount,
      price: priceAmount,
      cost: priceAmount,

      admin_commission: adminCommission,
      commission: adminCommission,
      commission_amount: adminCommission,

      duration_value: durationValue,
      validity_value: durationValue,
      expire_after_value: durationValue,

      duration_unit: durationUnit,
      validity_unit: durationUnit,
      expire_after_unit: durationUnit,

      duration_seconds: durationSeconds,
      expires_at: expiresAt,

      status,
      active: status === 'ACTIVE',
      expired: false,
      deleted: false,
      deleted_at: 0,

      visibility,
      scope: visibility,

      note,
      description: note
    }, true, { busyText: 'Saving bundle offer...' });

    closeModal();

    log(`Bundle offer saved: ${data.offer?.offer_id || data.offer_id || offerId || bundleName}`);
    showToast('Bundle offer saved', 'ok');

    await loadBundleOffers({ busy:false, silentLog:true });
  }catch(err){
    alert(err.message || 'Failed to save bundle offer');
  }finally{
    submitLocks[lockKey] = false;
    setActionBtnLoading('bundleOfferSaveBtn', false);
  }
}

async function expireBundleOffer(offerId){
  offerId = String(offerId || '').trim();

  if (!offerId) {
    alert('Offer ID not found');
    return;
  }

  const ok = confirm(`Expire bundle offer ${offerId}?`);
  if (!ok) return;

  const item = (state.bundleOffers || []).find(row => getBundleOfferId(row) === offerId) || {};

  try{
    try{
      await proxyPost('bundle_offer_expire', { offer_id: offerId }, true, {
        busyText: 'Expiring bundle offer...'
      });
    }catch(_){
      await proxyPost('bundle_offer_save', {
        offer_id: offerId,

        bundle_name: item.bundle_name || item.package_name || item.plan_name || item.name || 'Bundle Offer',
        package_name: item.package_name || item.bundle_name || item.name || 'Bundle Offer',
        name: item.name || item.bundle_name || item.package_name || 'Bundle Offer',

        operator: item.operator || 'GP',

        amount: getBundleOfferPriceAmount(item),
        price_amount: getBundleOfferPriceAmount(item),
        offer_price: getBundleOfferPriceAmount(item),
        price: getBundleOfferPriceAmount(item),
        cost: getBundleOfferPriceAmount(item),

        admin_commission: getBundleOfferAdminCommission(item),
        commission: getBundleOfferAdminCommission(item),
        commission_amount: getBundleOfferAdminCommission(item),

        duration_value: Number(item.duration_value || item.validity_value || item.expire_after_value || 1),
        validity_value: Number(item.validity_value || item.duration_value || item.expire_after_value || 1),
        expire_after_value: Number(item.expire_after_value || item.duration_value || item.validity_value || 1),

        duration_unit: item.duration_unit || item.validity_unit || item.expire_after_unit || 'DAY',
        validity_unit: item.validity_unit || item.duration_unit || item.expire_after_unit || 'DAY',
        expire_after_unit: item.expire_after_unit || item.duration_unit || item.validity_unit || 'DAY',

        status: 'EXPIRED',
        active: false,
        expired: true,
        expires_at: Math.floor(Date.now() / 1000) - 60,

        visibility: item.visibility || item.scope || 'GLOBAL',
        scope: item.scope || item.visibility || 'GLOBAL',

        note: item.note || item.description || '',
        description: item.description || item.note || ''
      }, true, {
        busyText: 'Expiring bundle offer...'
      });
    }

    log(`Bundle offer expired: ${offerId}`);
    showToast('Bundle offer expired', 'ok');

    await loadBundleOffers({ busy:false, silentLog:true });
  }catch(err){
    alert(err.message || 'Failed to expire bundle offer');
  }
}

async function deleteBundleOffer(offerId){
  offerId = String(offerId || '').trim();

  if (!offerId) {
    alert('Offer ID not found');
    return;
  }

  const ok = confirm(`Delete bundle offer ${offerId}?`);
  if (!ok) return;

  try{
    await proxyPost('bundle_offer_delete', { offer_id: offerId }, true, { busyText: 'Deleting bundle offer...' });

    log(`Bundle offer deleted: ${offerId}`);
    showToast('Bundle offer deleted', 'ok');

    await loadBundleOffers({ busy:false, silentLog:true });
  }catch(err){
    alert(err.message || 'Failed to delete bundle offer');
  }
}

/* =========================
   USERS
========================= */

function syncUserRoleFields(){
  const role = (document.getElementById('userRole')?.value || 'USER').toUpperCase();

  const showCommission = true;
  const showApi = role === 'SUBADMIN';
  const showLimits = role === 'RETAILER' || role === 'SUBADMIN';

  document.getElementById('commissionField')?.classList.toggle('hidden', !showCommission);
  document.getElementById('apiEnabledField')?.classList.toggle('hidden', !showApi);
  document.getElementById('minAmountField')?.classList.toggle('hidden', !showLimits);
  document.getElementById('maxAmountField')?.classList.toggle('hidden', !showLimits);

  const commissionNode = document.getElementById('userCommissionPer1000');
  if (commissionNode && commissionNode.dataset.userEdited !== '1') {
    commissionNode.value = (role === 'RETAILER' || role === 'SUBADMIN') ? '18' : '0';
  }

  if (!showApi) {
    const node = document.getElementById('userApiEnabled');
    if (node) node.value = '0';
  }

  if (!showLimits) {
    const minEl = document.getElementById('userMinAmount');
    const maxEl = document.getElementById('userMaxAmount');

    if (minEl) minEl.value = '0';
    if (maxEl) maxEl.value = '0';
  }
}

function syncEditUserRoleFields(){
  const role = (document.getElementById('editUserRole')?.value || 'USER').toUpperCase();

  const showCommission = true;
  const showApi = role === 'SUBADMIN';
  const showLimits = role === 'RETAILER' || role === 'SUBADMIN';

  document.getElementById('editCommissionField')?.classList.toggle('hidden', !showCommission);
  document.getElementById('editApiEnabledField')?.classList.toggle('hidden', !showApi);
  document.getElementById('editMinAmountField')?.classList.toggle('hidden', !showLimits);
  document.getElementById('editMaxAmountField')?.classList.toggle('hidden', !showLimits);

  if (!showApi) {
    const node = document.getElementById('editUserApiEnabled');
    if (node) node.value = '0';
  }

  if (!showLimits) {
    const minEl = document.getElementById('editUserMinAmount');
    const maxEl = document.getElementById('editUserMaxAmount');

    if (minEl) minEl.value = '0';
    if (maxEl) maxEl.value = '0';
  }
}

function collectUserRoleSettingsPayload(){
  return {
    role: (document.getElementById('userRole')?.value || 'USER').toUpperCase(),
    status: (document.getElementById('userStatus')?.value || 'ACTIVE').toUpperCase(),
    commission_per_1000: numberFromValue(document.getElementById('userCommissionPer1000')?.value, 0),
    api_enabled: boolFromValue(document.getElementById('userApiEnabled')?.value, false),
    topup_enabled: boolFromValue(document.getElementById('userTopupEnabled')?.value, true),
    bundle_enabled: boolFromValue(document.getElementById('userBundleEnabled')?.value, true),
    min_amount: numberFromValue(document.getElementById('userMinAmount')?.value, 0),
    max_amount: numberFromValue(document.getElementById('userMaxAmount')?.value, 0),
  };
}

function resetUserRoleFields(role = 'USER'){
  const roleEl = document.getElementById('userRole');
  const statusEl = document.getElementById('userStatus');
  const commissionEl = document.getElementById('userCommissionPer1000');
  const apiEl = document.getElementById('userApiEnabled');
  const topupEl = document.getElementById('userTopupEnabled');
  const bundleEl = document.getElementById('userBundleEnabled');
  const minEl = document.getElementById('userMinAmount');
  const maxEl = document.getElementById('userMaxAmount');

  if (roleEl) roleEl.value = role;
  if (statusEl) statusEl.value = 'ACTIVE';
  if (commissionEl) commissionEl.value = '0';
  if (commissionEl) commissionEl.dataset.userEdited = '0';
  if (apiEl) apiEl.value = '0';
  if (topupEl) topupEl.value = '1';
  if (bundleEl) bundleEl.value = '1';
  if (minEl) minEl.value = '0';
  if (maxEl) maxEl.value = '0';

  syncUserRoleFields();
}

async function loadUsers(options = {}){
  const tbody = document.getElementById('usersTableBody');
  const page = Math.max(1, Number(options.page || state.usersPagination.page || 1));
  const search = document.getElementById('usersSearch')?.value.trim() || '';

  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty">Loading users...</td></tr>';
  }

  try {
    const data = await proxyGet('users', {
      page,
      limit: state.usersPagination.limit,
      search
    }, options);

    state.users = Array.isArray(data.items) ? data.items : [];
    state.usersPagination = {
      ...state.usersPagination,
      ...(data.pagination || {}),
      page: Number(data.pagination?.page || page),
      total: Number(data.pagination?.total || 0),
      total_pages: Number(data.pagination?.total_pages || 1),
      has_more: !!data.pagination?.has_more
    };
    state.loaded.users = true;

    renderUsers();
    renderUsersPagination();

    const dashUsersCount = document.getElementById('dashUsersCount');
    const dashBalanceTotal = document.getElementById('dashBalanceTotal');
    const summary = data.summary || {};

    if (dashUsersCount) dashUsersCount.textContent = String(summary.total_users ?? state.usersPagination.total);
    if (dashBalanceTotal) dashBalanceTotal.textContent = money(summary.total_available_balance || 0);

    if (!options.silentLog) log('Loaded users list.');
  } catch (err) {
    state.loaded.users = false;
    if (tbody) {
      tbody.innerHTML = `<tr><td colspan="8" class="empty">${esc(err.message || 'Unable to load users.')}</td></tr>`;
    }
    renderUsersPagination();
    if (!options.silentLog) {
      showToast(err.message || 'Unable to load users.', 'error');
    }
    throw err;
  }
}

function renderUsers(){
  const rows = state.users;
  const tbody = document.getElementById('usersTableBody');

  if (!tbody) return;

  if (!rows.length){
    tbody.innerHTML = '<tr><td colspan="8" class="empty">No user found.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(item => `
    <tr>
      <td>
        <div><strong>${esc(item.name || '-')}</strong></div>
        <div class="muted" style="font-size:12px;">UID: ${esc(item.uid || '-')}</div>
        <div class="muted" style="font-size:12px;">${esc(item.email || '-')}</div>
      </td>
      <td>${esc(item.phone || '-')}</td>
      <td>
        ${statusPill(item.account_status || item.status || 'ACTIVE')}
        ${item.vpn_suspected ? '<div class="muted" style="font-size:11px;color:#ff8f9f;margin-top:5px;">Risk review</div>' : ''}
      </td>
      <td>${rolePill(item.role || 'USER')}</td>
      <td>
        <div><strong>${esc(item.phone_country || '-')}</strong> <span class="muted">OTP</span></div>
        <div class="muted" style="font-size:12px;">${esc(item.pricing_country || item.market_country || item.country_code || item.country || '-')} pricing</div>
        <div class="muted" style="font-size:11px;">GPS ${esc(item.gps_country || '-')} / IP ${esc(item.ip_country || '-')}</div>
        ${item.country_mismatch ? '<div class="muted" style="font-size:11px;color:#ffb96a;">Country mismatch</div>' : ''}
      </td>
      <td>${walletMoney(item, 'available')}${walletRawHint(item, 'available')}</td>
      <td>${fmtTs(item.last_login_at || 0)}</td>
      <td>
        <div class="row-actions">
          <button class="mini-btn" onclick="viewUser('${esc(item.uid || '')}')">View</button>
          <button class="mini-btn" onclick="openEditUserModal('${esc(item.uid || '')}')">Edit</button>
          ${String(item.account_status || item.status || '').toUpperCase() === 'REVIEW'
            ? `
              <button class="mini-btn" onclick="approveUserAccount('${esc(item.uid || '')}')">Approve</button>
              <button class="mini-btn" onclick="rejectUserAccount('${esc(item.uid || '')}')">Reject</button>
            `
            : ''}
        </div>
      </td>
    </tr>
  `).join('');
}

function renderUsersPagination(){
  const pagination = state.usersPagination;
  const text = document.getElementById('usersPaginationText');
  const prev = document.getElementById('usersPrevBtn');
  const next = document.getElementById('usersNextBtn');

  if (text) {
    text.textContent = `${pagination.total} users • Page ${pagination.page} of ${pagination.total_pages}`;
  }
  if (prev) prev.disabled = pagination.page <= 1;
  if (next) next.disabled = pagination.page >= pagination.total_pages || !pagination.has_more;
}

function loadUsersPage(page){
  const target = Math.max(1, Math.min(Number(page || 1), state.usersPagination.total_pages || 1));
  if (target === state.usersPagination.page && state.loaded.users) return;
  loadUsers({ page: target, busyText: 'Loading users...' }).catch(() => {});
}

async function viewUser(uid){
  try{
    const data = await proxyGet('user_get', { uid }, { busyText: 'Loading user details...' });
    const w = data.wallet || {};

    openDrawer(
      `User ${uid}`,
      `${data.name || '-'} • ${data.phone || '-'}`,
      `
        <div class="detail-grid">
          <div class="detail-item"><label>Name</label><strong>${esc(data.name || '-')}</strong></div>
          <div class="detail-item"><label>Phone</label><strong>${esc(data.phone || '-')}</strong></div>
          <div class="detail-item"><label>Email</label><strong>${esc(data.email || '-')}</strong></div>
          <div class="detail-item"><label>Status</label><strong>${esc(data.status || '-')}</strong></div>
          <div class="detail-item"><label>Account Review Status</label><strong>${esc(data.account_status || data.status || '-')}</strong></div>
          <div class="detail-item"><label>Role</label><strong>${esc(data.role || 'USER')}</strong></div>
          <div class="detail-item"><label>Phone Country (OTP)</label><strong>${esc(data.phone_country || '-')}</strong></div>
          <div class="detail-item"><label>Pricing Country (fee/wallet/service)</label><strong>${esc(data.pricing_country || data.market_country || data.country_code || data.country || '-')}</strong></div>
          <div class="detail-item"><label>IP Country</label><strong>${esc(data.ip_country || '-')}</strong></div>
          <div class="detail-item"><label>GPS Country</label><strong>${esc(data.gps_country || '-')}</strong></div>
          <div class="detail-item"><label>GPS Accuracy</label><strong>${Number(data.gps_accuracy || 0).toFixed(0)} m</strong></div>
          <div class="detail-item"><label>Country Mismatch</label><strong>${data.country_mismatch ? 'Yes - review recommended' : 'No'}</strong></div>
          <div class="detail-item"><label>VPN / Proxy Suspected</label><strong>${data.vpn_suspected ? 'Yes' : 'No'}</strong></div>
          <div class="detail-item"><label>Detection Source</label><strong>${esc(data.market_detection_source || '-')}</strong></div>
          <div class="detail-item"><label>Review Reason</label><strong>${esc(data.account_review_reason || '-')}</strong></div>
          <div class="detail-item"><label>IP Risk</label><strong>${esc(data.ip_risk_type || '-')} (${Number(data.ip_risk_score || 0)})</strong></div>
          <div class="detail-item"><label>Registration IP</label><strong>${esc(data.created_ip || data.registration_ip || '-')}</strong></div>
          <div class="detail-item"><label>Last Login IP</label><strong>${esc(data.last_login_ip || '-')}</strong></div>
          <div class="detail-item"><label>Topup Commission / 1000 BDT</label><strong>${Number(data.commission_per_1000 || 0).toFixed(2)}</strong></div>
          <div class="detail-item"><label>API Enabled</label><strong>${data.api_enabled ? 'Yes' : 'No'}</strong></div>
          <div class="detail-item"><label>Topup Enabled</label><strong>${data.topup_enabled ? 'Yes' : 'No'}</strong></div>
          <div class="detail-item"><label>Bundle Enabled</label><strong>${data.bundle_enabled ? 'Yes' : 'No'}</strong></div>
          <div class="detail-item"><label>Amount Limits</label><strong>${Number(data.min_amount || 0).toFixed(2)} - ${Number(data.max_amount || 0).toFixed(2)}</strong></div>
          <div class="detail-item"><label>Created</label><strong>${fmtTs(data.created_at)}</strong></div>
          <div class="detail-item"><label>Last Login</label><strong>${fmtTs(data.last_login_at)}</strong></div>
          <div class="detail-item"><label>Available Balance</label><strong>${walletMoney(w, 'available')}</strong>${walletRawHint(w, 'available')}</div>
          <div class="detail-item"><label>Hold Balance</label><strong>${walletMoney(w, 'hold')}</strong>${walletRawHint(w, 'hold')}</div>
          <div class="detail-item"><label>Wallet Currency</label><strong>${esc(w.wallet_currency || w.currency || 'BDT')}</strong></div>
          <div class="detail-item"><label>Total Topup Spent</label><strong>${money(w.total_topup_spent)}</strong></div>
          <div class="detail-item"><label>Total Bundle Spent</label><strong>${money(w.total_bundle_spent)}</strong></div>
          <div class="detail-item"><label>Total Refund</label><strong>${money(w.total_refund)}</strong></div>
          <div class="detail-item"><label>Wallet Updated</label><strong>${fmtTs(w.updated_at)}</strong></div>
        </div>
      `,
      `
        <button class="btn ghost" onclick="closeDrawer()">Close</button>
        <button class="btn blue" onclick="openEditUserModal('${esc(uid)}')">Edit User</button>
        ${String(data.account_status || data.status || '').toUpperCase() === 'REVIEW'
          ? `
            <button class="btn brand" onclick="approveUserAccount('${esc(uid)}')">Approve Account</button>
            <button class="btn orange" onclick="rejectUserAccount('${esc(uid)}')">Reject Account</button>
          `
          : ''}
        ${
          canManageApiKeys(data.role)
            ? `<button class="btn blue" onclick="openUserApiKeys('${esc(uid)}')">API Keys</button>`
            : ''
        }
        <button class="btn brand" onclick="openWalletAction('add','${esc(uid)}')">Add Balance</button>
        <button class="btn orange" onclick="openWalletAction('deduct','${esc(uid)}')">Deduct Balance</button>
        <button class="btn blue ledger-btn" onclick="openLedger('${esc(uid)}')">View Ledger</button>
      `
    );
  }catch(err){
    alert(err.message || 'Failed to load user');
  }
}

function canManageApiKeys(role){
  const r = String(role || '').toUpperCase();
  return r === 'SUBADMIN' || r === 'ADMIN';
}

async function openEditUserModal(uid){
  try{
    const data = await proxyGet('user_get', { uid }, { busyText: 'Loading user for edit...' });

    openModal(
      `Edit User • ${uid}`,
      `
        <div class="form-grid">
          <div class="form-full">
            <label>UID</label>
            <input class="input" id="editUserUid" value="${esc(data.uid || uid)}" readonly>
          </div>

          <div>
            <label>Name</label>
            <input class="input" id="editUserName" value="${esc(data.name || '')}">
          </div>

          <div>
            <label>Email</label>
            <input class="input" id="editUserEmail" value="${esc(data.email || '')}">
          </div>

          <div>
            <label>Role</label>
            <select id="editUserRole">
              <option value="USER" ${(String(data.role || '').toUpperCase() === 'USER') ? 'selected' : ''}>USER</option>
              <option value="RETAILER" ${(String(data.role || '').toUpperCase() === 'RETAILER') ? 'selected' : ''}>RETAILER</option>
              <option value="SUBADMIN" ${(String(data.role || '').toUpperCase() === 'SUBADMIN') ? 'selected' : ''}>SUBADMIN</option>
              <option value="ADMIN" ${(String(data.role || '').toUpperCase() === 'ADMIN') ? 'selected' : ''}>ADMIN</option>
            </select>
          </div>

          <div>
            <label>Status</label>
            <select id="editUserStatus">
              <option value="ACTIVE" ${(String(data.status || '').toUpperCase() === 'ACTIVE') ? 'selected' : ''}>ACTIVE</option>
              <option value="INACTIVE" ${(String(data.status || '').toUpperCase() === 'INACTIVE') ? 'selected' : ''}>INACTIVE</option>
              <option value="REVIEW" ${(String(data.account_status || data.status || '').toUpperCase() === 'REVIEW') ? 'selected' : ''}>REVIEW</option>
              <option value="BLOCKED" ${(String(data.account_status || data.status || '').toUpperCase() === 'BLOCKED') ? 'selected' : ''}>BLOCKED</option>
              <option value="REJECTED" ${(String(data.account_status || data.status || '').toUpperCase() === 'REJECTED') ? 'selected' : ''}>REJECTED</option>
            </select>
          </div>

          <div>
            <label>Pricing Country</label>
            <select id="editUserCountry" data-original-country="${esc(String(data.pricing_country || data.market_country || data.country_code || data.country || '').toUpperCase())}">
              <option value="">Not Set / Auto fallback</option>
              <option value="BD" ${(String(data.pricing_country || data.market_country || data.country_code || data.country || '').toUpperCase() === 'BD') ? 'selected' : ''}>Bangladesh (BDT)</option>
              <option value="MY" ${(String(data.pricing_country || data.market_country || data.country_code || data.country || '').toUpperCase() === 'MY') ? 'selected' : ''}>Malaysia (MYR)</option>
            </select>
            <small class="muted">Admin-only. Controls wallet currency, fee and service pricing. Phone Country remains unchanged. If pricing country changes, wallet balance is converted with the active Ringgit rate.</small>
          </div>

          <div>
            <label>Phone Country (OTP)</label>
            <input class="input" value="${esc(data.phone_country || '-')}" readonly>
          </div>

          <div class="form-full">
            <div class="card">
              <div class="card-body">
                <div class="metric-title" style="margin-bottom:12px;">Role Settings</div>

                <div class="form-grid">
                  <div id="editCommissionField">
                    <label>Topup Commission / 1000 BDT</label>
                    <input class="input" id="editUserCommissionPer1000" type="number" step="0.01" min="0" value="${esc(String(data.commission_per_1000 || 0))}">
                    <small class="muted">Normal mobile topup only. Balance transfer and bundle are excluded.</small>
                  </div>

                  <div id="editApiEnabledField">
                    <label>API Enabled</label>
                    <select id="editUserApiEnabled">
                      <option value="1" ${data.api_enabled ? 'selected' : ''}>Enabled</option>
                      <option value="0" ${!data.api_enabled ? 'selected' : ''}>Disabled</option>
                    </select>
                  </div>

                  <div>
                    <label>Topup Enabled</label>
                    <select id="editUserTopupEnabled">
                      <option value="1" ${data.topup_enabled ? 'selected' : ''}>Enabled</option>
                      <option value="0" ${!data.topup_enabled ? 'selected' : ''}>Disabled</option>
                    </select>
                  </div>

                  <div>
                    <label>Bundle Enabled</label>
                    <select id="editUserBundleEnabled">
                      <option value="1" ${data.bundle_enabled ? 'selected' : ''}>Enabled</option>
                      <option value="0" ${!data.bundle_enabled ? 'selected' : ''}>Disabled</option>
                    </select>
                  </div>

                  <div id="editMinAmountField">
                    <label>Min Amount</label>
                    <input class="input" id="editUserMinAmount" type="number" step="0.01" min="0" value="${esc(String(data.min_amount || 0))}">
                  </div>

                  <div id="editMaxAmountField">
                    <label>Max Amount</label>
                    <input class="input" id="editUserMaxAmount" type="number" step="0.01" min="0" value="${esc(String(data.max_amount || 0))}">
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      `,
      `
        <button class="btn ghost" onclick="closeModal()">Cancel</button>
        <button class="btn brand" onclick="submitEditUser()">Save Changes</button>
      `
    );

    syncEditUserRoleFields();
  }catch(err){
    alert(err.message || 'Failed to load user for edit');
  }
}

async function submitEditUser(confirmCurrencyConversion = false){
  try{
    let payload = null;
    let originalCountry = '';

    if (confirmCurrencyConversion && state.pendingEditUserUpdate) {
      payload = state.pendingEditUserUpdate.payload;
      originalCountry = state.pendingEditUserUpdate.originalCountry || '';
    } else {
      const countryEl = document.getElementById('editUserCountry');
      const uid = document.getElementById('editUserUid')?.value.trim() || '';
      const name = document.getElementById('editUserName')?.value.trim() || '';
      const email = document.getElementById('editUserEmail')?.value.trim() || '';
      const role = (document.getElementById('editUserRole')?.value || 'USER').toUpperCase();
      const status = (document.getElementById('editUserStatus')?.value || 'ACTIVE').toUpperCase();
      const country = (countryEl?.value || '').toUpperCase();

      const commission_per_1000 = numberFromValue(document.getElementById('editUserCommissionPer1000')?.value, 0);
      const api_enabled = boolFromValue(document.getElementById('editUserApiEnabled')?.value, false);
      const topup_enabled = boolFromValue(document.getElementById('editUserTopupEnabled')?.value, true);
      const bundle_enabled = boolFromValue(document.getElementById('editUserBundleEnabled')?.value, true);
      const min_amount = numberFromValue(document.getElementById('editUserMinAmount')?.value, 0);
      const max_amount = numberFromValue(document.getElementById('editUserMaxAmount')?.value, 0);

      originalCountry = (countryEl?.dataset.originalCountry || '').toUpperCase();
      payload = {
        uid,
        name,
        email,
        role,
        status,
        commission_per_1000,
        api_enabled,
        topup_enabled,
        bundle_enabled,
        min_amount,
        max_amount
      };

      if (country) {
        payload.pricing_country = country;
        payload.market_country = country;
        payload.service_country = country;
        payload.country = country;
        payload.country_code = country;
        payload.country_change_note = 'Admin pricing country update with wallet currency conversion.';
      }

      if (country && originalCountry && country !== originalCountry) {
        const previewData = await proxyPost('user_currency_preview', {
          uid,
          pricing_country: country
        }, true, { busyText: 'Preparing currency conversion preview...' });

        const preview = previewData.preview || {};
        state.pendingEditUserUpdate = { payload, originalCountry, preview };
        const oldPrefix = walletPrefix(preview.old_currency || (originalCountry === 'MY' ? 'MYR' : 'BDT'));
        const newPrefix = walletPrefix(preview.new_currency || (country === 'MY' ? 'MYR' : 'BDT'));
        const body = `
          <div class="status-box-clean warning">
            <strong>Confirm Currency Conversion</strong>
            <p class="muted">This will convert the wallet balance using the active Ringgit rate. It will not simply rename the currency label.</p>
          </div>
          <div class="summary-grid" style="margin-top:12px;">
            <div class="summary-box"><label>Old Pricing</label><strong>${esc(preview.old_pricing_country || originalCountry)} / ${esc(preview.old_currency || '')}</strong></div>
            <div class="summary-box"><label>Old Balance</label><strong>${oldPrefix} ${money(preview.old_balance || 0)}</strong></div>
            <div class="summary-box"><label>Rate Used</label><strong>RM 1 = BDT ${money(preview.rate_used || 0)}</strong></div>
            <div class="summary-box"><label>New Pricing</label><strong>${esc(preview.new_pricing_country || country)} / ${esc(preview.new_currency || '')}</strong></div>
            <div class="summary-box"><label>New Balance</label><strong>${newPrefix} ${money(preview.new_balance || 0)}</strong></div>
            <div class="summary-box"><label>Hold Balance</label><strong>${oldPrefix} ${money(preview.old_hold_balance || 0)} to ${newPrefix} ${money(preview.new_hold_balance || 0)}</strong></div>
          </div>
        `;
        openModal('Currency Conversion Preview', body, `
          <button class="btn ghost" onclick="cancelEditUserConversion()">Cancel</button>
          <button class="btn brand" onclick="submitEditUser(true)">Convert & Save</button>
        `);
        return;
      }
    }

    const data = await proxyPost('user_update', payload, true, { busyText: 'Updating user account...' });

    state.pendingEditUserUpdate = null;
    closeModal();

    log(`User updated: ${data.uid || payload.uid}`);
    showToast(`User updated: ${data.name || data.uid || payload.uid}`, 'ok');

    await loadUsers({ busy:false, silentLog:true });

    const drawer = document.getElementById('drawer');
    if (drawer && drawer.classList.contains('open')) {
      await viewUser(payload.uid);
    }
  }catch(err){
    if (err.code === 'EMAIL_EXISTS') {
      alert('This email is already registered.');
      return;
    }

    alert(err.message || 'Failed to update user');
  }
}

function cancelEditUserConversion(){
  state.pendingEditUserUpdate = null;
  closeModal();
}

async function approveUserAccount(uid){
  uid = String(uid || '').trim();
  if (!uid) return;

  if (!confirm(`Approve account ${uid}? Verify the GPS/IP risk details first.`)) {
    return;
  }

  try {
    await proxyPost('user_approve', { uid }, true, { busyText: 'Approving account...' });
    showToast('Account approved successfully', 'ok');
    await loadUsers({ busy:false, silentLog:true });

    const drawer = document.getElementById('drawer');
    if (drawer && drawer.classList.contains('open')) {
      await viewUser(uid);
    }
  } catch (err) {
    alert(err.message || 'Failed to approve account');
  }
}

async function rejectUserAccount(uid){
  uid = String(uid || '').trim();
  if (!uid) return;

  if (!confirm(`Reject account ${uid}? This user will not be able to login.`)) {
    return;
  }

  try {
    await proxyPost('user_reject', { uid }, true, { busyText: 'Rejecting account...' });
    showToast('Account rejected successfully', 'ok');
    await loadUsers({ busy:false, silentLog:true });

    const drawer = document.getElementById('drawer');
    if (drawer && drawer.classList.contains('open')) {
      await viewUser(uid);
    }
  } catch (err) {
    alert(err.message || 'Failed to reject account');
  }
}

function openCreateUserModal(){
  openModal(
    'Create User',
    `
      <div class="form-grid">
        <input type="hidden" id="userUid">

        <div class="field">
          <label>Name</label>
          <input class="input" id="userName" placeholder="Enter name">
        </div>

        <div class="field">
          <label>Phone Country</label>
          <select id="userPhoneCountry" class="input">
            <option value="BD">Bangladesh (+880)</option>
            <option value="MY">Malaysia (+60)</option>
          </select>
        </div>

        <div class="field">
          <label>Phone</label>
          <input class="input" id="userPhone" placeholder="Enter phone">
        </div>

        <div class="field">
          <label>Pricing Country</label>
          <select id="userPricingCountry" class="input">
            <option value="BD">Bangladesh (BDT)</option>
            <option value="MY">Malaysia (MYR)</option>
          </select>
        </div>

        <div class="field">
          <label>Email</label>
          <input class="input" id="userEmail" placeholder="Enter email">
        </div>

        <div class="field">
          <label>Password</label>
          <input class="input" id="userPassword" type="password" placeholder="Enter password">
        </div>

        <div class="field">
          <label>PIN</label>
          <input class="input" id="userPin" placeholder="Enter PIN">
        </div>

        <div class="field">
          <label>Status</label>
          <select id="userStatus" class="input">
            <option value="ACTIVE">ACTIVE</option>
            <option value="INACTIVE">INACTIVE</option>
          </select>
        </div>

        <div class="field form-full">
          <label>Role</label>
          <select id="userRole" class="input">
            <option value="USER">USER</option>
            <option value="RETAILER">RETAILER</option>
            <option value="SUBADMIN">SUBADMIN</option>
            <option value="ADMIN">ADMIN</option>
          </select>
        </div>

        <div class="form-full">
          <div class="card">
            <div class="card-body">
              <div class="metric-title" style="margin-bottom:12px;">Role Settings</div>

              <div class="form-grid">
                <div class="field" id="commissionField">
                  <label>Topup Commission / 1000 BDT</label>
                  <input id="userCommissionPer1000" class="input" type="number" step="0.01" min="0" value="0">
                  <small class="muted">Normal mobile topup only. Balance transfer and bundle are excluded.</small>
                </div>

                <div class="field" id="apiEnabledField">
                  <label>API Enabled</label>
                  <select id="userApiEnabled" class="input">
                    <option value="1">Enabled</option>
                    <option value="0">Disabled</option>
                  </select>
                </div>

                <div class="field">
                  <label>Topup Enabled</label>
                  <select id="userTopupEnabled" class="input">
                    <option value="1">Enabled</option>
                    <option value="0">Disabled</option>
                  </select>
                </div>

                <div class="field">
                  <label>Bundle Enabled</label>
                  <select id="userBundleEnabled" class="input">
                    <option value="1">Enabled</option>
                    <option value="0">Disabled</option>
                  </select>
                </div>

                <div class="field" id="minAmountField">
                  <label>Min Amount</label>
                  <input id="userMinAmount" class="input" type="number" step="0.01" min="0" value="0">
                </div>

                <div class="field" id="maxAmountField">
                  <label>Max Amount</label>
                  <input id="userMaxAmount" class="input" type="number" step="0.01" min="0" value="0">
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    `,
    `
      <button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn brand" id="saveCreateUserBtn">Create User</button>
    `
  );

  resetUserRoleFields('USER');

  const saveBtn = document.getElementById('saveCreateUserBtn');

  if (saveBtn) {
    saveBtn.onclick = async () => {
      const payload = {
        name: document.getElementById('userName')?.value.trim() || '',
        phone: document.getElementById('userPhone')?.value.trim() || '',
        phone_country: document.getElementById('userPhoneCountry')?.value || 'BD',
        pricing_country: document.getElementById('userPricingCountry')?.value || 'BD',
        market_country: document.getElementById('userPricingCountry')?.value || 'BD',
        email: document.getElementById('userEmail')?.value.trim() || '',
        password: document.getElementById('userPassword')?.value || '',
        pin: document.getElementById('userPin')?.value || '',
        ...collectUserRoleSettingsPayload()
      };

      try {
        const data = await proxyPost('user_create', payload, true, { busyText: 'Creating user account...' });

        closeModal();

        log(`User created: ${data.uid || '-'} (${data.phone || '-'})`);
        showToast(`User created: ${data.name || data.uid || ''}`, 'ok');

        await loadUsers({ busy:false, silentLog:true });
      } catch (err) {
        if (err.code === 'PHONE_EXISTS') {
          alert('This phone number is already registered.');
          return;
        }

        if (err.code === 'EMAIL_EXISTS') {
          alert('This email is already registered.');
          return;
        }

        alert(err.message || 'Failed to create user');
      }
    };
  }
}

/* =========================
   USER API KEYS / WALLET
========================= */

async function openUserApiKeys(uid){
  try{
    const user = await proxyGet('user_get', { uid }, { busyText:'Loading user API keys...' });
    const role = String(user.role || 'USER').toUpperCase();

    if (!canManageApiKeys(role)) {
      alert('Only SUBADMIN or ADMIN can use API keys.');
      return;
    }

    const data = await proxyGet('subapi_list_keys', { uid }, { busy:false });
    const items = data.items || [];

    openModal(
      `API Keys • ${uid}`,
      `
        <div class="detail-item" style="margin-bottom:14px;">
          <label>User</label>
          <div><strong>${esc(user.name || '-')}</strong> • ${esc(user.phone || '-')}</div>
          <div class="muted" style="margin-top:6px;font-size:12px;">
            Role: ${esc(role)} • API Enabled: ${(user.api_enabled ? 'Yes' : 'No')}
          </div>
        </div>

        ${
          items.length ? `
            <div class="table-wrap" style="padding:0; max-height:420px; overflow:auto;">
              <table style="min-width:100%;">
                <thead>
                  <tr>
                    <th>Key ID</th>
                    <th>Masked Key</th>
                    <th>Status</th>
                    <th>Last Used</th>
                    <th>Created</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  ${items.map(item => `
                    <tr>
                      <td>${esc(item.key_id || '-')}</td>
                      <td><code>${esc(item.key_mask || '-')}</code></td>
                      <td>${statusPill(item.status || 'ACTIVE')}</td>
                      <td>${fmtTs(item.last_used_at || 0)}</td>
                      <td>${fmtTs(item.created_at || 0)}</td>
                      <td>
                        <div class="row-actions">
                          ${
                            String(item.status || '').toUpperCase() !== 'ACTIVE'
                              ? `<button class="mini-btn" onclick="updateUserApiKeyStatus('${esc(uid)}','${esc(item.key_id || '')}','ACTIVE')">Activate</button>`
                              : ''
                          }
                          ${
                            String(item.status || '').toUpperCase() === 'ACTIVE'
                              ? `<button class="mini-btn" onclick="updateUserApiKeyStatus('${esc(uid)}','${esc(item.key_id || '')}','DISABLED')">Disable</button>`
                              : ''
                          }
                          ${
                            String(item.status || '').toUpperCase() !== 'REVOKED'
                              ? `<button class="mini-btn" onclick="updateUserApiKeyStatus('${esc(uid)}','${esc(item.key_id || '')}','REVOKED')">Revoke</button>`
                              : ''
                          }
                        </div>
                      </td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            </div>
          ` : `
            <div class="empty">No API key found for this user.</div>
          `
        }
      `,
      `
        <button class="btn ghost" onclick="closeModal()">Close</button>
        <button class="btn brand" onclick="createUserApiKey('${esc(uid)}')">Generate API Key</button>
      `
    );
  }catch(err){
    alert(err.message || 'Failed to load API keys');
  }
}

async function createUserApiKey(uid){
  const ok = confirm(`Create new API key for ${uid}?`);
  if (!ok) return;

  try{
    const data = await proxyPost('subapi_create_key', { uid }, true, { busyText:'Creating API key...' });

    alert(
      'API key created successfully.\n\n' +
      'Save this key now. It may not be shown again in full form.\n\n' +
      (data.plain_key || '')
    );

    showToast('API key created', 'ok');
    await openUserApiKeys(uid);
  }catch(err){
    alert(err.message || 'Failed to create API key');
  }
}

async function updateUserApiKeyStatus(uid, keyId, status){
  const ok = confirm(`Change key ${keyId} status to ${status}?`);
  if (!ok) return;

  try{
    await proxyPost('subapi_update_key_status', {
      uid,
      key_id: keyId,
      status
    }, true, { busyText:'Updating API key status...' });

    showToast(`API key ${status}`, 'ok');
    await openUserApiKeys(uid);
  }catch(err){
    alert(err.message || 'Failed to update API key status');
  }
}

function openWalletAction(type, uid){
  const target = state.users.find(item => String(item.uid || '') === String(uid)) || {};
  const currency = walletNativeCurrency(target);
  const prefix = walletPrefix(currency);
  const actionId = window.crypto?.randomUUID?.()
    || `WA_${Date.now()}_${Math.random().toString(16).slice(2)}`;
  const currencyFields = `
    <div>
      <label>Receiver Currency</label>
      <input class="input" id="walletCurrency" value="${esc(currency === 'MYR' ? 'MYR (RM)' : 'BDT')}" readonly>
    </div>

    <div>
      <label>Amount (${esc(prefix)})</label>
      <input class="input" id="walletAmount" type="number" step="0.01" min="0">
    </div>

    <div class="form-full muted">The amount is ${type === 'add' ? 'credited to' : 'deducted from'} the receiver wallet in ${esc(prefix)}. No exchange conversion is applied.</div>
  `;

  openModal(
    type === 'add' ? 'Add Balance' : 'Deduct Balance',
    `
      <div class="form-grid">
        <div class="form-full">
          <label>UID</label>
          <input class="input" id="walletUid" value="${esc(uid)}" readonly>
          <input id="walletActionId" type="hidden" value="${esc(actionId)}">
        </div>

        ${currencyFields}

        <div>
          <label>Note</label>
          <input class="input" id="walletNote" value="${type === 'add' ? 'Admin balance added' : 'Admin balance deducted'}">
        </div>
      </div>
    `,
    `
      <button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn ${type === 'add' ? 'brand' : 'orange'}" onclick="submitWalletAction('${type}')">Submit</button>
    `
  );
}

async function submitWalletAction(type){
  const uid = document.getElementById('walletUid')?.value.trim() || '';
  const amount = Number(document.getElementById('walletAmount')?.value || 0);
  const note = document.getElementById('walletNote')?.value.trim() || '';
  const actionId = document.getElementById('walletActionId')?.value.trim() || '';

  try{
    const action = type === 'add' ? 'wallet_add' : 'wallet_deduct_send_otp';
    const data = await proxyPost(action, {
      uid,
      amount,
      note,
      action_id: actionId
    }, true, { busyText: type === 'add' ? 'Adding balance...' : 'Sending OTP...' });

    closeModal();

    if (type === 'add') {
      const currency = String(data.currency || data.wallet_currency || 'BDT').toUpperCase();
      const amountLabel = `${walletPrefix(currency)} ${money(data.total_credit ?? data.amount ?? amount)}`;
      log(`Added ${amountLabel} for ${uid}.`);
      showToast(`Balance added: ${amountLabel}`, 'ok');
    } else {
      state.walletDeductOtp = {
        uid,
        amount,
        note,
        otpRequestId: String(data.otp_request_id || ''),
        maskedPhone: String(data.masked_phone || ''),
        currency: String(data.currency || data.wallet_currency || 'BDT').toUpperCase() === 'MYR' ? 'MYR' : 'BDT'
      };
      openAdminWalletDeductOtpModal();
      showToast('Deduction OTP sent to the target account', 'ok');
      return;
    }

    await loadUsers({ busy:false, silentLog:true });
    await viewUser(uid);
  }catch(err){
    if (err.code === 'INSUFFICIENT_BALANCE') {
      alert(`Not enough available balance.\nAvailable: ${money(err.data.available_balance)}\nRequired: ${money(err.data.required_amount)}`);
      return;
    }

    alert(err.message || 'Wallet action failed');
  }
}

function openAdminWalletDeductOtpModal(){
  const otpState = state.walletDeductOtp || {};
  const prefix = walletPrefix(otpState.currency || 'BDT');

  openModal(
    'Confirm Balance Deduction',
    `
      <div class="form-grid">
        <div class="form-full muted">
          OTP sent to ${esc(otpState.maskedPhone || 'the target account')}. Confirm ${esc(prefix)} ${money(otpState.amount || 0)} deduction.
        </div>
        <div class="form-full">
          <label>OTP Code</label>
          <input class="input" id="adminWalletDeductOtp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="Enter 6-digit OTP">
        </div>
      </div>
    `,
    `
      <button class="btn ghost" onclick="resendAdminWalletDeductOtp()">Resend OTP</button>
      <button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn orange" onclick="confirmAdminWalletDeductOtp()">Confirm Deduction</button>
    `
  );
}

async function resendAdminWalletDeductOtp(){
  const otpState = state.walletDeductOtp || {};
  if (!otpState.uid || !(Number(otpState.amount) > 0)) return;

  try{
    const data = await proxyPost('wallet_deduct_send_otp', {
      uid: otpState.uid,
      amount: otpState.amount,
      note: otpState.note || ''
    }, true, { busyText:'Resending OTP...' });

    otpState.otpRequestId = String(data.otp_request_id || '');
    otpState.maskedPhone = String(data.masked_phone || otpState.maskedPhone || '');
    otpState.currency = String(data.currency || data.wallet_currency || otpState.currency || 'BDT').toUpperCase() === 'MYR' ? 'MYR' : 'BDT';
    state.walletDeductOtp = otpState;
    openAdminWalletDeductOtpModal();
    showToast('OTP resent to the target account', 'ok');
  }catch(err){
    showToast(err.message || 'Failed to resend OTP', 'error');
  }
}

async function confirmAdminWalletDeductOtp(){
  const otpState = state.walletDeductOtp || {};
  const otp = document.getElementById('adminWalletDeductOtp')?.value.trim() || '';

  if (!otpState.otpRequestId || !/^\d{6}$/.test(otp)) {
    showToast('Enter the 6-digit OTP', 'error');
    return;
  }

  try{
    const data = await proxyPost('wallet_deduct_confirm', {
      otp_request_id: otpState.otpRequestId,
      otp
    }, true, { busyText:'Confirming deduction...' });

    closeModal();
    const currency = String(data.currency || data.wallet_currency || otpState.currency || 'BDT').toUpperCase();
    const amountLabel = `${walletPrefix(currency)} ${money(data.amount ?? otpState.amount ?? 0)}`;
    log(`Deducted ${amountLabel} for ${otpState.uid}.`);
    showToast(`Balance deducted: ${amountLabel}`, 'ok');
    state.walletDeductOtp = null;

    await loadUsers({ busy:false, silentLog:true });
    await viewUser(otpState.uid);
  }catch(err){
    showToast(err.message || 'Failed to confirm deduction OTP', 'error');
  }
}

async function openLedger(uid){
  const month = prompt('Enter month as YYYY-MM', new Date().toISOString().slice(0,7));
  if (!month) return;

  try{
    const data = await proxyGet('wallet_ledger', { uid, month }, { busyText: 'Loading ledger...' });
    const items = data.items || [];

    openModal(
      `Ledger • ${uid} • ${month}`,
      items.length ? `
        <div class="table-wrap" style="padding:0; max-height:calc(100vh - 260px); overflow:auto; -webkit-overflow-scrolling:touch;">
          <table style="min-width:100%;">
            <thead>
              <tr>
                <th>Time</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Note</th>
                <th>Ref</th>
              </tr>
            </thead>
            <tbody>
              ${items.map(item => `
                <tr>
                  <td>${fmtTs(item.created_at || item.ts)}</td>
                  <td>${esc(item.type || '-')}</td>
                  <td>${walletPrefix(item.currency)} ${money(item.amount)}</td>
                  <td>${esc(item.note || '-')}</td>
                  <td>${esc(item.ref_id || item.ref || '-')}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      ` : '<div class="empty">No ledger entry found for this month.</div>',
      `<button class="btn ghost" onclick="closeModal()">Close</button>`
    );
  }catch(err){
    alert(err.message || 'Failed to load ledger');
  }
}

async function loadWalletTransferHistory(){
  const month = document.getElementById('walletHistoryMonth')?.value || new Date().toISOString().slice(0,7);
  const receiver = document.getElementById('walletHistoryReceiver')?.value.trim() || '';
  const senderRole = document.getElementById('walletHistorySenderRole')?.value || '';
  const receiverRole = document.getElementById('walletHistoryReceiverRole')?.value || '';
  const type = document.getElementById('walletHistoryType')?.value || '';
  const target = document.getElementById('walletHistoryRows');

  if (target) {
    target.innerHTML = '<tr><td colspan="7" class="empty">Loading balance history...</td></tr>';
  }

  try{
    const data = await proxyGet('wallet_history', {
      month,
      receiver,
      sender_role: senderRole,
      receiver_role: receiverRole,
      type,
      limit: 300
    }, { busyText: 'Loading balance history...' });
    const items = Array.isArray(data.items) ? data.items : [];

    if (!target) return;
    if (!items.length) {
      target.innerHTML = '<tr><td colspan="7" class="empty">No balance transfer found for this month.</td></tr>';
      return;
    }

    target.innerHTML = items.map(item => `
      <tr>
        <td>${fmtTs(item.created_at)}</td>
        <td><strong>${esc(item.sender_name || item.sender_uid || '-')}</strong><br><span class="muted">${esc(item.sender_phone || '-')} - ${esc(item.sender_role || '-')}</span></td>
        <td><strong>${esc(item.receiver_name || item.receiver_uid || '-')}</strong><br><span class="muted">${esc(item.receiver_phone || '-')} - ${esc(item.receiver_role || '-')}</span></td>
        <td>${walletPrefix(item.currency)} ${money(item.amount)}</td>
        <td>${esc(item.type || '-')}<br><span class="muted">${esc(item.transfer_id || '-')}</span></td>
        <td>${esc(item.note || '-')}<br><span class="muted">${esc(item.reference || '-')}</span></td>
        <td>${walletPrefix(item.currency)} ${money(item.before_available ?? item.before_balance)} to ${walletPrefix(item.currency)} ${money(item.after_available ?? item.after_balance)}</td>
      </tr>
    `).join('');
  }catch(err){
    if (target) {
      target.innerHTML = `<tr><td colspan="7" class="empty">${esc(err.message || 'Failed to load balance history')}</td></tr>`;
    }
  }
}

function openWalletTransferHistory(){
  const month = new Date().toISOString().slice(0,7);

  openModal(
    'Wallet Transfers',
    `
      <div class="toolbar" style="padding:0 0 16px;">
        <div class="toolbar-left">
          <input class="input md" id="walletHistoryMonth" type="month" value="${month}">
          <input class="input md" id="walletHistoryReceiver" placeholder="Receiver phone or UID">
          <select class="input md" id="walletHistorySenderRole">
            <option value="">All sender roles</option>
            <option value="ADMIN">ADMIN</option>
            <option value="SUBADMIN">SUBADMIN</option>
          </select>
          <select class="input md" id="walletHistoryReceiverRole">
            <option value="">All receiver roles</option>
            <option value="SUBADMIN">SUBADMIN</option>
            <option value="RETAILER">RETAILER</option>
            <option value="USER">USER</option>
          </select>
          <select class="input md" id="walletHistoryType">
            <option value="">All transfer types</option>
            <option value="ADMIN_BALANCE_ADD">Admin Balance Add</option>
            <option value="SUBADMIN_BALANCE_TRANSFER">Subadmin Transfer</option>
          </select>
          <button class="btn brand" type="button" onclick="loadWalletTransferHistory()">Apply</button>
        </div>
      </div>
      <div class="table-wrap" style="padding:0;max-height:calc(100vh - 290px);overflow:auto;">
        <table style="min-width:980px;">
          <thead>
            <tr>
              <th>Date</th>
              <th>From</th>
              <th>To</th>
              <th>Amount</th>
              <th>Type</th>
              <th>Note / Reference</th>
              <th>Receiver Before / After</th>
            </tr>
          </thead>
          <tbody id="walletHistoryRows">
            <tr><td colspan="7" class="empty">Loading balance history...</td></tr>
          </tbody>
        </table>
      </div>
    `,
    `<button class="btn ghost" onclick="closeModal()">Close</button>`
  );

  loadWalletTransferHistory();
}

/* =========================
   OPERATORS
========================= */

async function loadOperators(options = {}){
  const data = await proxyGet('operators', {}, options);

  state.operators = data.items || [];
  state.topupCountries = data.countries || [];
  state.loaded.operators = true;

  renderOperators();

  const dashOperatorsCount = document.getElementById('dashOperatorsCount');
  if (dashOperatorsCount) {
    dashOperatorsCount.textContent = state.operators.filter(x => x.active).length;
  }

  if (!options.silentLog) log('Loaded operators list.');
}

function topupListText(value){
  return Array.isArray(value) ? value.join(', ') : String(value || '');
}

function renderOperators(){
  const countryBody = document.getElementById('topupCountriesTableBody');
  const tbody = document.getElementById('operatorsTableBody');

  if (countryBody) {
    const countries = state.topupCountries || [];
    if (!countries.length) {
      countryBody.innerHTML = '<tr><td colspan="7" class="empty">No country data yet.</td></tr>';
    } else {
      countryBody.innerHTML = countries.map(item => `
        <tr>
          <td><strong>${esc(item.code || '-')}</strong></td>
          <td>${esc(item.name || '-')}</td>
          <td>${esc(item.currency || '-')}</td>
          <td>${esc(item.dial_code || '-')}</td>
          <td>${statusPill(item.active ? 'ACTIVE' : 'INACTIVE')}</td>
          <td>${Number(item.sort_order || 0)}</td>
          <td><button class="mini-btn" onclick="editTopupCountry('${esc(item.code || '')}')">Edit</button></td>
        </tr>
      `).join('');
    }
  }

  if (!tbody) return;

  if (!state.operators.length){
    tbody.innerHTML = '<tr><td colspan="9" class="empty">No operator found.</td></tr>';
    return;
  }

  tbody.innerHTML = state.operators.map(item => `
    <tr>
      <td><strong>${esc(item.operator || '-')}</strong></td>
      <td>${esc(item.country_code || '-')}<br><small>${esc(item.service_type || '-')}</small></td>
      <td>${esc(item.name || '-')}</td>
      <td>${statusPill(item.active ? 'ACTIVE' : 'INACTIVE')}</td>
      <td>${Number(item.min_amount || 0).toFixed(2)} - ${Number(item.max_amount || 0).toFixed(2)}<br><small>Sort ${Number(item.sort_order || 0)}</small></td>
      <td><small>Quick: ${esc(topupListText(item.quick_amounts) || '-')}</small><br><small>Prefixes: ${esc(topupListText(item.prefixes) || '-')}</small></td>
      <td><code>${esc(item.masked_template || item.dial_template || '-')}</code></td>
      <td>${item.requires_secret_pin ? 'Yes' : 'No'}</td>
      <td>
        <div class="row-actions">
          <button class="mini-btn" onclick="editOperator('${esc(item.operator || '')}')">Edit</button>
        </div>
      </td>
    </tr>
  `).join('');
}

async function editTopupCountry(code){
  const item = (state.topupCountries || []).find(row => String(row.code || '') === String(code || ''));
  if (!item) {
    alert('Country not found');
    return;
  }

  openModal(
    `Edit Top-Up Country - ${esc(item.code || '')}`,
    `
      <div class="form-grid">
        <div>
          <label>Code</label>
          <input class="input" id="topupCountryCode" value="${esc(item.code || '')}" readonly>
        </div>
        <div>
          <label>Name</label>
          <input class="input" id="topupCountryName" value="${esc(item.name || '')}">
        </div>
        <div>
          <label>Currency</label>
          <input class="input" id="topupCountryCurrency" value="${esc(item.currency || '')}">
        </div>
        <div>
          <label>Dial Code</label>
          <input class="input" id="topupCountryDial" value="${esc(item.dial_code || '')}">
        </div>
        <div>
          <label>Active</label>
          <select id="topupCountryActive">
            <option value="true" ${item.active ? 'selected' : ''}>Active</option>
            <option value="false" ${!item.active ? 'selected' : ''}>Inactive</option>
          </select>
        </div>
        <div>
          <label>Sort Order</label>
          <input class="input" id="topupCountrySort" type="number" step="1" value="${esc(String(item.sort_order ?? 999))}">
        </div>
      </div>
    `,
    `
      <button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn brand" onclick="saveTopupCountry()">Save Country</button>
    `
  );
}

async function saveTopupCountry(){
  const body = {
    code: document.getElementById('topupCountryCode')?.value.trim() || '',
    name: document.getElementById('topupCountryName')?.value.trim() || '',
    currency: document.getElementById('topupCountryCurrency')?.value.trim() || '',
    dial_code: document.getElementById('topupCountryDial')?.value.trim() || '',
    active: document.getElementById('topupCountryActive')?.value === 'true',
    sort_order: Number(document.getElementById('topupCountrySort')?.value || 999),
  };

  try{
    await proxyPost('topup_country_save', body, true, { busyText: 'Saving country...' });
    closeModal();
    showToast(`Country ${body.code} saved`, 'ok');
    await loadOperators({ busy:false, silentLog:true });
  }catch(err){
    alert(err.message || 'Failed to save country');
  }
}

async function editOperator(operator){
  try{
    const data = await proxyGet('operator_get', { operator }, { busyText: 'Loading operator...' });
    const quickAmounts = topupListText(data.quick_amounts);
    const prefixes = topupListText(data.prefixes);

    openModal(
      `Edit Operator • ${operator}`,
      `
        <div class="form-grid">
          <div>
            <label>Operator</label>
            <input class="input" id="opOperator" value="${esc(data.operator || '')}" readonly>
          </div>

          <div>
            <label>Name</label>
            <input class="input" id="opName" value="${esc(data.name || '')}">
          </div>

          <div>
            <label>Country</label>
            <input class="input" id="opCountryCode" value="${esc(data.country_code || '')}" readonly>
          </div>

          <div>
            <label>Service Type</label>
            <select id="opServiceType">
              <option value="PREPAID" selected>PREPAID</option>
            </select>
          </div>

          <div>
            <label>Active</label>
            <select id="opActive">
              <option value="true" ${data.active ? 'selected' : ''}>Active</option>
              <option value="false" ${!data.active ? 'selected' : ''}>Inactive</option>
            </select>
          </div>

          <div>
            <label>Requires Secret PIN</label>
            <select id="opRequiresPin">
              <option value="true" ${data.requires_secret_pin ? 'selected' : ''}>Yes</option>
              <option value="false" ${!data.requires_secret_pin ? 'selected' : ''}>No</option>
            </select>
          </div>

          <div>
            <label>Min Amount</label>
            <input class="input" id="opMinAmount" type="number" step="0.01" min="0" value="${esc(String(data.min_amount ?? 20))}">
          </div>

          <div>
            <label>Max Amount</label>
            <input class="input" id="opMaxAmount" type="number" step="0.01" min="0" value="${esc(String(data.max_amount ?? 1000))}">
          </div>

          <div>
            <label>Quick Amounts</label>
            <input class="input" id="opQuickAmounts" placeholder="20,50,100" value="${esc(quickAmounts)}">
          </div>

          <div>
            <label>Prefixes</label>
            <input class="input" id="opPrefixes" placeholder="017,013" value="${esc(prefixes)}">
          </div>

          <div>
            <label>Sort Order</label>
            <input class="input" id="opSortOrder" type="number" step="1" value="${esc(String(data.sort_order ?? 999))}">
          </div>

          <div>
            <label>Execution</label>
            <input class="input" value="${data.execution_ready ? 'Live queue ready' : 'Catalog only / not ready'}" readonly>
          </div>

          <div class="form-full">
            <label>Dial Template</label>
            <textarea class="input" id="opDialTemplate" rows="4">${esc(data.dial_template || '')}</textarea>
          </div>

          <div class="form-full">
            <label>Masked Template</label>
            <textarea class="input" id="opMaskedTemplate" rows="3">${esc(data.masked_template || '')}</textarea>
          </div>

          <div class="form-full">
            <label>Retailer Secret PIN</label>
            <input class="input" id="opRetailerPin" value="" placeholder="${data.retailer_secret_pin_set ? 'Leave blank to keep existing PIN' : 'Enter retailer secret PIN'}">
            <div class="hint">${data.retailer_secret_pin_set ? `Current PIN: ${esc(data.retailer_secret_pin_masked || '********')}` : 'PIN is stored privately and never displayed.'}</div>
          </div>
        </div>
      `,
      `
        <button class="btn ghost" onclick="closeModal()">Cancel</button>
        <button class="btn brand" onclick="saveOperator()">Save Operator</button>
      `
    );
  }catch(err){
    alert(err.message || 'Failed to load operator');
  }
}

async function saveOperator(){
  const body = {
    operator: document.getElementById('opOperator')?.value.trim() || '',
    name: document.getElementById('opName')?.value.trim() || '',
    country_code: document.getElementById('opCountryCode')?.value.trim() || '',
    service_type: document.getElementById('opServiceType')?.value || 'PREPAID',
    active: document.getElementById('opActive')?.value === 'true',
    min_amount: Number(document.getElementById('opMinAmount')?.value || 0),
    max_amount: Number(document.getElementById('opMaxAmount')?.value || 0),
    quick_amounts: document.getElementById('opQuickAmounts')?.value.trim() || '',
    prefixes: document.getElementById('opPrefixes')?.value.trim() || '',
    sort_order: Number(document.getElementById('opSortOrder')?.value || 999),
    requires_secret_pin: document.getElementById('opRequiresPin')?.value === 'true',
    dial_template: document.getElementById('opDialTemplate')?.value.trim() || '',
    masked_template: document.getElementById('opMaskedTemplate')?.value.trim() || '',
    retailer_secret_pin: document.getElementById('opRetailerPin')?.value.trim() || '',
  };

  try{
    await proxyPost('operator_save', body, true, { busyText: 'Saving operator...' });

    closeModal();

    log(`Operator ${body.operator} saved.`);
    showToast(`Operator ${body.operator} saved`, 'ok');

    await loadOperators({ busy:false, silentLog:true });
  }catch(err){
    alert(err.message || 'Failed to save operator');
  }
}

/* =========================
   WORKERS
========================= */

async function loadWorkersStatus(options = {}){
  const data = await proxyGet('workers_status', {}, options);

  state.workers = data.items || [];
  state.loaded.workers = true;

  const summary = data.summary || {};

  if (document.getElementById('workersTotalCount')) document.getElementById('workersTotalCount').textContent = summary.total || 0;
  if (document.getElementById('workersOnlineCount')) document.getElementById('workersOnlineCount').textContent = summary.online || 0;
  if (document.getElementById('workersBusyCount')) document.getElementById('workersBusyCount').textContent = summary.busy || 0;
  if (document.getElementById('workersIdleCount')) document.getElementById('workersIdleCount').textContent = summary.idle || 0;
  if (document.getElementById('workersOfflineCount')) document.getElementById('workersOfflineCount').textContent = summary.offline || 0;

  renderWorkersStatus();

  if (!options.silentLog) log('Loaded workers status.');
}

function getWorkerHeartbeatTs(worker){
  return Number(
    worker?.last_heartbeat_at ||
    worker?.heartbeat_at ||
    worker?.updated_at ||
    worker?.ts ||
    0
  );
}

function isWorkerOnline(worker){
  return !!(
    worker?.online === true ||
    worker?.is_online === true ||
    worker?.raw?.online === true ||
    worker?.raw?.is_online === true
  );
}

function workerLivePill(worker){
  const hb = getWorkerHeartbeatTs(worker);
  const ageMs = hb ? (Date.now() - (String(Math.trunc(hb)).length <= 10 ? hb * 1000 : hb)) : Number.MAX_SAFE_INTEGER;
  const onlineByFlag = isWorkerOnline(worker);
  const current = String(worker.current_status || worker.status || '').toUpperCase();

  if (!onlineByFlag) {
    return '<span class="pill danger">OFFLINE</span>';
  }

  if (ageMs > 10 * 60 * 1000) {
    return '<span class="pill danger">STALE</span>';
  }

  if (ageMs > 2 * 60 * 1000) {
    return '<span class="pill warning">' + esc(current || 'ONLINE') + '</span>';
  }

  if (current === 'BUSY') return '<span class="pill warning">BUSY</span>';
  if (current === 'IDLE') return '<span class="pill success">IDLE</span>';

  return '<span class="pill success">' + esc(current || 'ONLINE') + '</span>';
}

function workerFlagsText(worker){
  const parts = [];
  parts.push(worker.worker_enabled ? 'Worker ON' : 'Worker OFF');
  parts.push(worker.accessibility_enabled ? 'Access ON' : 'Access OFF');
  return parts.join(' • ');
}

function workerSimText(worker){
  if (worker?.sim_summary) return worker.sim_summary;

  const slots = worker?.sim_slots;

  if (Array.isArray(slots)) {
    return slots.map(slot => {
      const op = slot.operator || slot.name || 'Unknown';
      const active = slot.active ? 'ON' : 'OFF';
      return `SIM: ${op} (${active})`;
    }).join(' • ');
  }

  if (slots && typeof slots === 'object') {
    return Object.keys(slots).map(key => {
      const slot = slots[key] || {};
      const op = slot.operator || slot.name || key;
      const active = slot.active ? 'ON' : 'OFF';
      return `SIM: ${op} (${active})`;
    }).join(' • ');
  }

  if (worker?.raw?.sim_slots && typeof worker.raw.sim_slots === 'object') {
    return Object.keys(worker.raw.sim_slots).map(key => {
      const slot = worker.raw.sim_slots[key] || {};
      const op = slot.operator || slot.name || key;
      const active = slot.active ? 'ON' : 'OFF';
      return `SIM: ${op} (${active})`;
    }).join(' • ');
  }

  return '-';
}

function findWorkerByDeviceId(deviceId){
  return (state.workers || []).find(w =>
    String(w.device_id || w.id || '') === String(deviceId)
  ) || null;
}

function renderWorkersStatus(){
  const tbody = document.getElementById('workersTableBody');
  if (!tbody) return;

  const rows = state.workers || [];

  if (!rows.length){
    tbody.innerHTML = '<tr><td colspan="7" class="empty">No worker found.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(worker => {
    const deviceId = worker.device_id || worker.id || '-';
    const deviceName = worker.device_name || worker.name || '-';
    const hb = getWorkerHeartbeatTs(worker);
    const appVersion = worker.app_version || '-';

    return `
      <tr>
        <td>
          <div><strong>${esc(deviceId)}</strong></div>
          <div class="muted" style="font-size:12px;">${esc(deviceName)}</div>
        </td>

        <td>${workerLivePill(worker)}</td>

        <td>
          <div>${fmtTs(hb)}</div>
          <div class="muted" style="font-size:12px;">${esc(formatAgo(hb))}</div>
        </td>

        <td>${esc(workerSimText(worker))}</td>

        <td>${esc(appVersion)}</td>

        <td>${esc(workerFlagsText(worker))}</td>

        <td>
          <div class="row-actions">
            <button class="mini-btn" onclick="viewWorkerStatus('${esc(deviceId)}')">View</button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function viewWorkerStatus(deviceId){
  const worker = findWorkerByDeviceId(deviceId);

  if (!worker){
    alert('Worker not found');
    return;
  }

  const onlineNow = isWorkerOnline(worker);
  const hb = getWorkerHeartbeatTs(worker);

  const rawSlots =
    worker?.sim_slots ||
    worker?.raw?.sim_slots ||
    {};

  const slotsHtml = Array.isArray(rawSlots)
    ? rawSlots.map((slot, i) => `
        <div class="detail-item">
          <label>SIM ${i + 1}</label>
          <strong>
            ${esc(slot.operator || slot.name || 'Unknown')}
            ${slot.active ? ' (ON)' : ' (OFF)'}
          </strong>
          <div class="muted" style="margin-top:6px;font-size:12px;">
            Slot: ${esc(slot.slot_index ?? slot.slot ?? i + 1)}
          </div>
        </div>
      `).join('')
    : Object.keys(rawSlots).length
      ? Object.keys(rawSlots).map((key, i) => {
          const slot = rawSlots[key] || {};
          return `
            <div class="detail-item">
              <label>${esc(key)}</label>
              <strong>
                ${esc(slot.operator || slot.name || 'Unknown')}
                ${slot.active ? ' (ON)' : ' (OFF)'}
              </strong>
              <div class="muted" style="margin-top:6px;font-size:12px;">
                Slot: ${esc(slot.slot_index ?? slot.slot ?? i + 1)}
              </div>
            </div>
          `;
        }).join('')
      : `
        <div class="detail-item">
          <label>SIM Slots</label>
          <strong>-</strong>
        </div>
      `;

  openDrawer(
    `Worker ${deviceId}`,
    `${worker.device_name || '-'} • ${worker.current_status || worker.status || 'UNKNOWN'}`,
    `
      <div class="detail-grid">
        <div class="detail-item"><label>Device ID</label><strong>${esc(worker.device_id || worker.id || '-')}</strong></div>
        <div class="detail-item"><label>Device Name</label><strong>${esc(worker.device_name || worker.name || '-')}</strong></div>

        <div class="detail-item"><label>Online</label><strong>${onlineNow ? 'Yes' : 'No'}</strong></div>
        <div class="detail-item"><label>Current Status</label><strong>${esc(worker.current_status || worker.status || '-')}</strong></div>

        <div class="detail-item"><label>Worker Enabled</label><strong>${worker.worker_enabled ? 'Yes' : 'No'}</strong></div>
        <div class="detail-item"><label>Accessibility Enabled</label><strong>${worker.accessibility_enabled ? 'Yes' : 'No'}</strong></div>

        <div class="detail-item"><label>App Version</label><strong>${esc(worker.app_version || '-')}</strong></div>
        <div class="detail-item"><label>Heartbeat</label><strong>${fmtTs(hb)}</strong></div>

        <div class="detail-item"><label>Heartbeat Age</label><strong>${esc(formatAgo(hb))}</strong></div>
        <div class="detail-item"><label>Assigned Request</label><strong>${esc(worker.current_request_id || worker.assigned_request_id || '-')}</strong></div>

        ${slotsHtml}
      </div>

      <div class="detail-item" style="margin-top:16px;">
        <label>Raw Worker JSON</label>
        <div class="log-box">${esc(JSON.stringify(worker, null, 2))}</div>
      </div>
    `,
    `
      <button class="btn ghost" onclick="closeDrawer()">Close</button>
    `
  );
}

/* =========================
   DONE SUMMARIES
========================= */

async function loadDoneTopupsSummary(options = {}){
  const data = await proxyGet('topups', {
    bucket: 'done',
    page: 1,
    limit: 30
  }, options);

  state.doneTopups = data.items || [];
  state.loaded.doneTopups = true;

  renderDoneTopupsSummary();
  renderOverviewCharts();
}

async function loadDoneBundlesSummary(options = {}){
  const data = await proxyGet('bundles_done', {}, options);

  state.doneBundles = data.items || [];
  state.loaded.doneBundles = true;

  renderDoneBundlesSummary();
  renderOverviewCharts();
}

function renderDoneTopupsSummary(){
  const rows = state.doneTopups || [];

  let successCount = 0;
  let failedCount = 0;
  let successAmount = 0;
  let failedAmount = 0;

  rows.forEach(item => {
    const status = String(item.status || '').toUpperCase();
    const amount = Number(item.amount || item.amount_bdt || 0);

    if (status === 'SUCCESS') {
      successCount++;
      successAmount += amount;
    } else if (status === 'FAILED') {
      failedCount++;
      failedAmount += amount;
    }
  });

  if (document.getElementById('sumTopupSuccess')) document.getElementById('sumTopupSuccess').textContent = successCount;
  if (document.getElementById('sumTopupFailed')) document.getElementById('sumTopupFailed').textContent = failedCount;
  if (document.getElementById('sumTopupSuccessAmount')) document.getElementById('sumTopupSuccessAmount').textContent = money(successAmount);
  if (document.getElementById('sumTopupFailedAmount')) document.getElementById('sumTopupFailedAmount').textContent = money(failedAmount);

  const lines = rows.slice(0, 8).map(item => {
    const requestId = item.request_id || '-';
    const operator = item.operator || '-';
    const number = item.topup_number || item.number || '-';
    const amount = money(item.amount || item.amount_bdt || 0);
    const status = String(item.status || '-').toUpperCase();
    const time = fmtTs(item.completed_at || item.updated_at || item.created_at || 0);

    return `${time} • ${status} • ${operator} • ${number} • ${amount} • ${requestId}`;
  });

  const box = document.getElementById('recentTopupDoneBox');
  if (box) {
    box.textContent = lines.length ? lines.join('\n') : 'No recent topup activity.';
  }
}

function renderDoneBundlesSummary(){
  const rows = state.doneBundles || [];

  let successCount = 0;
  let failedCount = 0;

  rows.forEach(item => {
    const status = String(item.status || '').toUpperCase();

    if (status === 'SUCCESS') successCount++;
    else if (status === 'FAILED') failedCount++;
  });

  if (document.getElementById('sumBundlePending')) document.getElementById('sumBundlePending').textContent = (state.bundles || []).length;
  if (document.getElementById('sumBundleDone')) document.getElementById('sumBundleDone').textContent = rows.length;
  if (document.getElementById('sumBundleSuccess')) document.getElementById('sumBundleSuccess').textContent = successCount;
  if (document.getElementById('sumBundleFailed')) document.getElementById('sumBundleFailed').textContent = failedCount;

  const lines = rows.slice(0, 8).map(item => {
    const requestId = item.request_id || '-';
    const operator = item.operator || '-';
    const bundleName = item.bundle_name || '-';
    const amount = money(bundlePayAmount(item));
    const status = String(item.status || '-').toUpperCase();
    const time = fmtTs(item.completed_at || item.updated_at || item.created_at || 0);

    return `${time} • ${status} • ${operator} • ${bundleName} • ${amount} • ${requestId}`;
  });

  const box = document.getElementById('recentBundleDoneBox');
  if (box) {
    box.textContent = lines.length ? lines.join('\n') : 'No recent bundle activity.';
  }
}

/* =========================
   TOPUP ACTIONS
========================= */

function openTopupAction(requestId, type){
  const isSuccess = type === 'success';
  const defaultMessage = isSuccess
    ? 'Topup successful'
    : 'Topup failed';

  openModal(
    isSuccess ? 'Mark Topup Success' : 'Mark Topup Failed',
    `
      <div class="form-grid">
        <div class="form-full">
          <label>Request ID</label>
          <input class="input" id="topupRequestId" value="${esc(requestId)}" readonly>
        </div>

        <div class="form-full">
          <label>Message / Note</label>
          <textarea class="input" id="topupMessage" rows="4">${esc(defaultMessage)}</textarea>
        </div>

        <div class="form-full">
          <div class="muted" style="font-size:13px;">
            This action will update request status and refresh dashboard data.
          </div>
        </div>
      </div>
    `,
    `
      <button class="btn ghost" id="topupCancelBtn" onclick="closeModal()">Cancel</button>
      <button class="btn ${isSuccess ? 'brand' : 'red'}" id="topupSubmitBtn" onclick="submitTopupAction('${type}')">
        ${isSuccess ? 'Confirm Success' : 'Confirm Failed'}
      </button>
    `
  );
}

async function submitTopupAction(type){
  const requestId = document.getElementById('topupRequestId')?.value.trim() || '';
  const message = document.getElementById('topupMessage')?.value.trim() || '';

  if (!requestId){
    alert('Request ID not found');
    return;
  }

  const lockKey = `topup_${type}_${requestId}`;
  if (submitLocks[lockKey]) return;

  const ok = confirm(
    type === 'success'
      ? `Are you sure you want to mark ${requestId} as SUCCESS?`
      : `Are you sure you want to mark ${requestId} as FAILED?`
  );

  if (!ok) return;

  submitLocks[lockKey] = true;

  setActionBtnLoading(
    'topupSubmitBtn',
    true,
    type === 'success' ? 'Marking Success...' : 'Marking Failed...'
  );

  try{
    await proxyPost(
      type === 'success' ? 'topup_success' : 'topup_failed',
      { request_id: requestId, message },
      true,
      {
        busyText: type === 'success' ? 'Marking topup success...' : 'Marking topup failed...'
      }
    );

    closeModal();

    log(`Topup ${requestId} marked ${type}.`);
    showToast(`Topup ${requestId} marked ${type}`, 'ok');

    await Promise.allSettled([
      loadTopups({ busy:false, silentLog:true }),
      loadCounts({ busy:false, silentLog:true }),
      loadDoneTopupsSummary({ busy:false, silentLog:true }),
      loadUsers({ busy:false, silentLog:true })
    ]);
  }catch(err){
    alert(err.message || 'Topup action failed');
  }finally{
    submitLocks[lockKey] = false;
    setActionBtnLoading('topupSubmitBtn', false);
  }
}

/* =========================
   APP CONFIG / DIRECT TOPUP
========================= */

async function loadAppConfigStatus(options = {}){
  try{
    const data = await proxyGet('app_config_get', {}, options);

    state.loaded.appConfig = true;

    const topupDot = document.getElementById('cfgTopupDot');
    const topupText = document.getElementById('cfgTopupText');

    const bundleDot = document.getElementById('cfgBundleDot');
    const bundleText = document.getElementById('cfgBundleText');

    const maintenanceDot = document.getElementById('cfgMaintenanceDot');
    const maintenanceText = document.getElementById('cfgMaintenanceText');

    if (topupText && topupDot) {
      topupText.textContent = `Topup: ${data.topup_enabled ? 'Enabled' : 'Disabled'}`;
      topupDot.className = data.topup_enabled ? 'status-dot' : 'status-dot red';
    }

    if (bundleText && bundleDot) {
      bundleText.textContent = `Bundle: ${data.bundle_enabled ? 'Enabled' : 'Disabled'}`;
      bundleDot.className = data.bundle_enabled ? 'status-dot' : 'status-dot red';
    }

    if (maintenanceText && maintenanceDot) {
      maintenanceText.textContent = `Maintenance: ${data.maintenance_mode ? 'On' : 'Off'}`;
      maintenanceDot.className = data.maintenance_mode ? 'status-dot orange' : 'status-dot';
    }
  }catch(err){
    console.error('Failed to load app config status', err);
    throw err;
  }
}

async function openAppConfigModal(){
  try{
    const data = await proxyGet('app_config_get', {}, { busyText: 'Loading system settings...' });

    openModal(
      'System Settings',
      `
        <div class="form-grid">
          <div>
            <label>Topup Service</label>
            <select id="cfgTopupEnabled">
              <option value="true" ${data.topup_enabled ? 'selected' : ''}>Enabled</option>
              <option value="false" ${!data.topup_enabled ? 'selected' : ''}>Disabled</option>
            </select>
          </div>

          <div>
            <label>Bundle Service</label>
            <select id="cfgBundleEnabled">
              <option value="true" ${data.bundle_enabled ? 'selected' : ''}>Enabled</option>
              <option value="false" ${!data.bundle_enabled ? 'selected' : ''}>Disabled</option>
            </select>
          </div>

          <div class="form-full">
            <label>Maintenance Mode</label>
            <select id="cfgMaintenanceMode">
              <option value="false" ${!data.maintenance_mode ? 'selected' : ''}>Off</option>
              <option value="true" ${data.maintenance_mode ? 'selected' : ''}>On</option>
            </select>
          </div>

          <div>
            <label>Min Topup Amount</label>
            <input class="input" id="cfgMinTopupAmount" type="number" step="0.01" min="0" value="${esc(data.min_topup_amount ?? 0)}">
          </div>

          <div>
            <label>Max Topup Amount</label>
            <input class="input" id="cfgMaxTopupAmount" type="number" step="0.01" min="0" value="${esc(data.max_topup_amount ?? 0)}">
          </div>

          <div>
            <label>Min Bundle Amount</label>
            <input class="input" id="cfgMinBundleAmount" type="number" step="0.01" min="0" value="${esc(data.min_bundle_amount ?? 0)}">
          </div>

          <div>
            <label>Max Bundle Amount</label>
            <input class="input" id="cfgMaxBundleAmount" type="number" step="0.01" min="0" value="${esc(data.max_bundle_amount ?? 0)}">
          </div>

          <div class="form-full">
            <label>Privacy Policy URL</label>
            <input class="input" id="cfgPrivacyPolicyUrl" type="url" inputmode="url" placeholder="https://example.com/privacy-policy" value="${esc(data.privacy_policy_url || '')}">
          </div>

          <div class="form-full">
            <label>Terms &amp; Conditions URL</label>
            <input class="input" id="cfgTermsConditionsUrl" type="url" inputmode="url" placeholder="https://example.com/terms" value="${esc(data.terms_conditions_url || '')}">
          </div>

          <div class="form-full">
            <label>Last Updated</label>
            <input class="input" value="${esc(fmtTs(data.updated_at || 0))}" readonly>
          </div>
        </div>
      `,
      `
        <button class="btn ghost" onclick="closeModal()">Cancel</button>
        <button class="btn brand" onclick="saveAppConfig()">Save Settings</button>
      `
    );
  }catch(err){
    alert(err.message || 'Failed to load system settings');
  }
}

async function saveAppConfig(){
  const body = {
    topup_enabled: document.getElementById('cfgTopupEnabled')?.value === 'true',
    bundle_enabled: document.getElementById('cfgBundleEnabled')?.value === 'true',
    maintenance_mode: document.getElementById('cfgMaintenanceMode')?.value === 'true',

    min_topup_amount: Number(document.getElementById('cfgMinTopupAmount')?.value || 0),
    max_topup_amount: Number(document.getElementById('cfgMaxTopupAmount')?.value || 0),

    min_bundle_amount: Number(document.getElementById('cfgMinBundleAmount')?.value || 0),
    max_bundle_amount: Number(document.getElementById('cfgMaxBundleAmount')?.value || 0),
    privacy_policy_url: (document.getElementById('cfgPrivacyPolicyUrl')?.value || '').trim(),
    terms_conditions_url: (document.getElementById('cfgTermsConditionsUrl')?.value || '').trim(),
  };

  try{
    await proxyPost('app_config_save', body, true, { busyText: 'Saving system settings...' });

    closeModal();

    log('System settings updated.');
    showToast('System settings saved', 'ok');

    await loadAppConfigStatus({ busy:false });
  }catch(err){
    alert(err.message || 'Failed to save system settings');
  }
}

function openDirectTopupModal(){
  openModal(
    'Admin Direct Topup',
    `
      <div class="form-grid">
        <div>
          <label>Topup Number</label>
          <input class="input" id="directTopupNumber" placeholder="01712345678">
        </div>

        <div>
          <label>Operator</label>
          <select id="directTopupOperator">
            <option value="GP">GP</option>
            <option value="ROBI">ROBI</option>
            <option value="BL">Banglalink</option>
            <option value="AIRTEL">AIRTEL</option>
            <option value="TT">TT</option>
          </select>
        </div>

        <div>
          <label>Amount</label>
          <input class="input" id="directTopupAmount" type="number" step="0.01" min="0" placeholder="50">
        </div>

        <div>
          <label>Note</label>
          <input class="input" id="directTopupNote" placeholder="Admin manual topup">
        </div>
      </div>
    `,
    `
      <button class="btn ghost" onclick="closeModal()">Cancel</button>
      <button class="btn brand" onclick="submitDirectTopup()">Create Topup</button>
    `
  );
}

async function submitDirectTopup(){
  const topup_number = document.getElementById('directTopupNumber')?.value.trim() || '';
  const operator = document.getElementById('directTopupOperator')?.value.trim() || '';
  const amount = Number(document.getElementById('directTopupAmount')?.value || 0);
  const note = document.getElementById('directTopupNote')?.value.trim() || '';

  try{
    const data = await proxyPost('topup_create', {
      topup_number,
      operator,
      amount,
      note
    }, true, { busyText: 'Creating direct topup...' });

    closeModal();

    log(`Admin direct topup created: ${data.request_id || '-'}`);
    showToast(`Topup request created: ${data.request_id || ''}`, 'ok');

    state.topupTab = 'pending';

    document.querySelectorAll('[data-topup-tab]').forEach(x => x.classList.remove('active'));
    document.querySelector('[data-topup-tab="pending"]')?.classList.add('active');

    openSection('topupSection');

    await Promise.allSettled([
      loadCounts({ busy:false, silentLog:true }),
      loadTopups({ busy:false, silentLog:true }),
      loadUsers({ busy:false, silentLog:true })
    ]);
  }catch(err){
    alert(err.message || 'Failed to create direct topup');
  }
}

/* =========================
   BUTTON LOADING
========================= */

function setActionBtnLoading(buttonId, isLoading, loadingText = 'Processing...'){
  const btn = document.getElementById(buttonId);
  if (!btn) return;

  if (isLoading){
    if (!btn.dataset.originalText) {
      btn.dataset.originalText = btn.textContent;
    }

    btn.disabled = true;
    btn.textContent = loadingText;
    return;
  }

  btn.disabled = false;

  if (btn.dataset.originalText) {
    btn.textContent = btn.dataset.originalText;
  }
}

/* =========================
   SUPPORT CENTER
========================= */

function supportFilters(){
  return {
    status: document.getElementById('supportStatusFilter')?.value || '',
    query: document.getElementById('supportSearch')?.value || '',
    limit: 150
  };
}

async function loadSupportAdmin(options = {}){
  const errors = [];
  try {
    await loadSupportTickets(options);
  } catch (err) {
    errors.push(err);
    showToast(err.message || 'Support ticket list failed', 'error');
  }
  try {
    await loadSupportConfig({ busy:false, silentLog:true });
  } catch (err) {
    errors.push(err);
    showToast(err.message || 'Support config failed', 'error');
  }
  try {
    await loadSupportCategories({ busy:false, silentLog:true });
  } catch (err) {
    errors.push(err);
    showToast(err.message || 'Support categories failed', 'error');
  }
  state.loaded.support = true;
  if (errors.length >= 3) {
    throw errors[0];
  }
}

async function loadSupportTickets(options = {}){
  const data = await proxyGet('support_list', supportFilters(), options);
  state.supportTickets = Array.isArray(data.tickets) ? data.tickets : [];
  renderSupportTickets();
}

async function loadSupportConfig(options = {}){
  const data = await proxyGet('support_config_get', {}, options);
  state.supportConfig = data.config || data.public_config || {};
  renderSupportConfig();
}

async function loadSupportCategories(options = {}){
  const data = await proxyGet('support_categories', {}, options);
  state.supportCategories = Array.isArray(data.categories) ? data.categories : [];
  renderSupportCategories();
}

function supportBoolValue(value){
  return boolFromValue(value, false) ? '1' : '0';
}

function setSupportInput(id, value){
  const node = document.getElementById(id);
  if (node) node.value = String(value ?? '');
}

function renderSupportConfig(){
  const c = state.supportConfig || {};
  setSupportInput('supportContactEnabled', supportBoolValue(c.contact_us_enabled ?? true));
  setSupportInput('supportTicketEnabled', supportBoolValue(c.ticket_enabled ?? true));
  setSupportInput('supportWhatsappEnabled', supportBoolValue(c.whatsapp_enabled));
  setSupportInput('supportWhatsappNumber', c.whatsapp_number || '');
  setSupportInput('supportCallEnabled', supportBoolValue(c.call_enabled));
  setSupportInput('supportPhone', c.support_phone || '');
  setSupportInput('supportEmailEnabled', supportBoolValue(c.email_enabled));
  setSupportInput('supportEmail', c.support_email || '');
  setSupportInput('supportHours', c.support_hours || '');
  setSupportInput('supportAverageResponse', c.average_response_text || '');
  setSupportInput('supportNotice', c.support_notice || '');
  setSupportInput('supportAttachmentsEnabled', supportBoolValue(c.attachments_enabled ?? true));
  setSupportInput('supportMaxAttachments', c.max_attachments ?? 3);
  setSupportInput('supportMaxFileSize', c.max_file_size ?? 5242880);
  setSupportInput('supportRateLimit', c.ticket_rate_limit_seconds ?? 20);
  setSupportInput('supportReopenAllowed', supportBoolValue(c.reopen_allowed ?? true));
}

function supportTicketStatus(row){
  return String(row?.status || 'OPEN').toUpperCase();
}

function renderSupportTickets(){
  const tbody = document.getElementById('supportTicketsTableBody');
  if (!tbody) return;
  if (!state.supportTickets.length) {
    tbody.innerHTML = '<tr><td colspan="9" class="empty">No support ticket found.</td></tr>';
    return;
  }
  tbody.innerHTML = state.supportTickets.map(row => {
    const id = String(row.ticket_id || '');
    return `
      <tr>
        <td><div class="mono">${esc(id)}</div><div class="muted">${fmtTs(row.created_at || 0)}</div></td>
        <td><strong>${esc(row.user_name || '-')}</strong><div class="muted">${esc(row.user_phone || '-')}</div><div class="muted">${esc(row.uid || '')}</div></td>
        <td>${esc(row.category_name || row.category_code || '-')}</td>
        <td><strong>${esc(row.subject || '-')}</strong>${row.admin_unread ? '<span class="support-unread">New</span>' : ''}<div class="muted">${esc(row.last_message_preview || '')}</div></td>
        <td>${esc(row.related_request_id || '-')}</td>
        <td>${statusPill(row.status_label || supportTicketStatus(row))}</td>
        <td>${Number(row.attachment_count || 0)}</td>
        <td>${fmtTs(row.last_message_at || row.updated_at || 0)}</td>
        <td><button class="mini-btn blue" onclick="openSupportTicket('${jsArg(id)}')">View</button></td>
      </tr>
    `;
  }).join('');
}

function supportAttachmentUrl(ticketId, attachmentId){
  const proxyUrl = window.ADMIN_PROXY_URL || '/api/admin/proxy.php';
  return `${proxyUrl}?action=support_attachment&ticket_id=${encodeURIComponent(ticketId)}&attachment_id=${encodeURIComponent(attachmentId)}`;
}

function supportAttachmentCards(ticketId, attachments, ids){
  const allowedIds = Array.isArray(ids) ? ids.map(String) : [];
  const rows = (Array.isArray(attachments) ? attachments : []).filter(row => {
    return !allowedIds.length || allowedIds.includes(String(row.attachment_id || ''));
  });
  if (!rows.length) return '';
  return `
    <div class="support-attachments">
      ${rows.map(row => {
        const url = supportAttachmentUrl(ticketId, String(row.attachment_id || ''));
        return `
          <a class="support-attachment-card" href="${esc(url)}" target="_blank" rel="noopener">
            <img src="${esc(url)}" alt="${esc(row.original_name || 'Attachment')}" loading="lazy">
            <span>${esc(row.original_name || 'Screenshot')}</span>
            <small>${money((Number(row.size || 0) / 1024))} KB</small>
          </a>
        `;
      }).join('')}
    </div>
  `;
}

function supportIsAdminMessage(msg){
  const sender = String(msg?.sender_type || '').toUpperCase();
  return sender === 'ADMIN' || sender === 'SUPPORT';
}

function supportMessageHtml(msg, ticketId, attachments){
  const fromAdmin = supportIsAdminMessage(msg);
  const sender = fromAdmin ? 'You' : 'User';
  return `
    <div class="support-message ${fromAdmin ? 'admin' : 'user'}">
      <div class="support-message-head">
        <strong>${esc(sender)}</strong>
        <span>${fmtTs(msg.created_at || 0)}</span>
      </div>
      <div class="support-message-text">${esc(msg.message || '-')}</div>
      ${supportAttachmentCards(ticketId, attachments, msg.attachment_ids || [])}
    </div>
  `;
}

function supportConversationHtml(messages, ticketId, attachments){
  if (!messages.length) return '<div class="empty">No conversation message found.</div>';
  const ordered = [...messages].sort((a,b) => Number(a.created_at || 0) - Number(b.created_at || 0));
  return ordered.map(msg => supportMessageHtml(msg, ticketId, attachments)).join('');
}

async function openSupportTicket(ticketId){
  const data = await proxyGet('support_details', { ticket_id: ticketId }, { busyText:'Loading support ticket...' });
  const ticket = data.ticket || {};
  const messages = Array.isArray(data.messages) ? data.messages : [];
  const attachments = Array.isArray(data.attachments) ? data.attachments : [];
  const id = String(ticket.ticket_id || ticketId || '');
  state.supportOpenTicketId = id;
  const closed = supportTicketStatus(ticket) === 'CLOSED';
  const resolved = supportTicketStatus(ticket) === 'RESOLVED';
  const blocked = closed || resolved;
  const body = `
    <div class="support-chat-shell">
      <div class="support-chat-head">
        <div>
          <strong>${esc(ticket.user_name || 'Support User')}</strong>
          <span>${esc(ticket.subject || 'Support request')}</span>
        </div>
        ${statusPill(ticket.status_label || ticket.status || '-')}
      </div>
      <div class="support-chat-meta">
        <span>${esc(id)}</span>
        <span>${esc(ticket.category_name || ticket.category_code || '-')}</span>
        ${ticket.related_request_id ? `<span>${esc(ticket.related_request_id)}</span>` : ''}
        <span>${fmtTs(ticket.created_at || 0)}</span>
      </div>
      <div class="support-conversation">
        ${supportConversationHtml(messages, id, attachments)}
      </div>
      ${blocked ? `<div class="support-closed-note">${closed ? 'This ticket is closed.' : 'This ticket has been resolved.'}</div>` : `
        <div class="support-composer">
          <textarea id="supportReplyMessage" class="input" rows="3" placeholder="Write a reply..."></textarea>
          <input id="supportReplyAttachments" class="input" type="file" accept="image/jpeg,image/png,image/webp" multiple>
          <div class="row-actions">
            <button class="btn brand" id="supportReplySendBtn" onclick="submitSupportReply('${jsArg(id)}')">Send</button>
            <button class="btn blue" onclick="setSupportTicketStatus('${jsArg(id)}','PENDING')">Pending</button>
            <button class="btn red" onclick="setSupportTicketStatus('${jsArg(id)}','CLOSED')">Close</button>
          </div>
        </div>
      `}
    </div>
  `;
  openDrawer('Support Chat', `${ticket.status_label || ticket.status || ''} - ${id}`, body, '<button class="btn ghost" id="drawerFootCloseDynamic">Close</button>');
  setTimeout(() => {
    const convo = document.querySelector('.support-conversation');
    if (convo) convo.scrollTop = convo.scrollHeight;
  }, 60);
}

async function submitSupportReply(ticketId){
  const messageNode = document.getElementById('supportReplyMessage');
  const message = String(messageNode?.value || '').trim();
  if (!message) {
    showToast('Reply message is required.', 'error');
    return;
  }
  if (submitLocks.supportReply) return;
  submitLocks.supportReply = true;
  setActionBtnLoading('supportReplySendBtn', true, 'Sending...');
  try {
    const files = Array.from(document.getElementById('supportReplyAttachments')?.files || []);
    if (files.length) {
      setBusy(true, 'Sending support reply...');
      const form = new FormData();
      form.append('ticket_id', ticketId);
      form.append('message', message);
      form.append('idempotency_key', `${ticketId}_${Date.now()}`);
      files.slice(0, 3).forEach(file => form.append('attachments[]', file));
      const proxyUrl = window.ADMIN_PROXY_URL || '/api/admin/proxy.php';
      const res = await fetch(`${proxyUrl}?action=support_reply_upload`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: state.csrf ? { 'X-CSRF-TOKEN': state.csrf, 'Accept': 'application/json' } : { 'Accept': 'application/json' },
        body: form
      });
      const text = await res.text();
      const json = readJsonSafeFromText(text);
      if (!res.ok || !json.ok) {
        const err = new Error(json.message || 'Support reply failed');
        err.code = json.code || 'ERROR';
        throw err;
      }
      setBusy(false);
    } else {
      await proxyPost('support_reply', {
        ticket_id: ticketId,
        message,
        idempotency_key: `${ticketId}_${Date.now()}`
      }, true, { busyText:'Sending support reply...' });
    }
    showToast('Support reply sent', 'ok');
    await openSupportTicket(ticketId);
    await loadSupportTickets({ busy:false, silentLog:true });
  } catch (err) {
    setBusy(false);
    showToast(err.message || 'Support reply failed', 'error');
  } finally {
    submitLocks.supportReply = false;
    setActionBtnLoading('supportReplySendBtn', false);
  }
}

async function setSupportTicketStatus(ticketId, status){
  try {
    await proxyPost('support_status', { ticket_id: ticketId, status }, true, { busyText:'Updating support ticket...' });
    showToast('Support ticket updated', 'ok');
    await openSupportTicket(ticketId);
    await loadSupportTickets({ busy:false, silentLog:true });
  } catch (err) {
    showToast(err.message || 'Status update failed', 'error');
  }
}

async function saveSupportConfig(){
  const payload = {
    contact_us_enabled: document.getElementById('supportContactEnabled')?.value === '1',
    ticket_enabled: document.getElementById('supportTicketEnabled')?.value === '1',
    whatsapp_enabled: document.getElementById('supportWhatsappEnabled')?.value === '1',
    whatsapp_number: document.getElementById('supportWhatsappNumber')?.value || '',
    call_enabled: document.getElementById('supportCallEnabled')?.value === '1',
    support_phone: document.getElementById('supportPhone')?.value || '',
    email_enabled: document.getElementById('supportEmailEnabled')?.value === '1',
    support_email: document.getElementById('supportEmail')?.value || '',
    support_hours: document.getElementById('supportHours')?.value || '',
    average_response_text: document.getElementById('supportAverageResponse')?.value || '',
    support_notice: document.getElementById('supportNotice')?.value || '',
    attachments_enabled: document.getElementById('supportAttachmentsEnabled')?.value === '1',
    max_attachments: Number(document.getElementById('supportMaxAttachments')?.value || 0),
    max_file_size: Number(document.getElementById('supportMaxFileSize')?.value || 0),
    ticket_rate_limit_seconds: Number(document.getElementById('supportRateLimit')?.value || 0),
    reopen_allowed: document.getElementById('supportReopenAllowed')?.value === '1'
  };
  try {
    await proxyPost('support_config_save', payload, true, { busyText:'Saving contact settings...' });
    showToast('Contact settings updated successfully.', 'ok');
    await loadSupportConfig({ busy:false, silentLog:true });
  } catch (err) {
    showToast(err.message || 'Contact settings save failed', 'error');
  }
}

function renderSupportCategories(){
  const tbody = document.getElementById('supportCategoriesTableBody');
  if (!tbody) return;
  if (!state.supportCategories.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="empty">No category found.</td></tr>';
    return;
  }
  tbody.innerHTML = state.supportCategories.map(row => {
    const code = String(row.code || '');
    return `
      <tr>
        <td class="mono">${esc(code)}</td>
        <td><strong>${esc(row.name || '-')}</strong></td>
        <td>${yesNoPill(boolFromValue(row.active, true))}</td>
        <td>${esc(row.sort_order ?? '-')}</td>
        <td>${yesNoPill(boolFromValue(row.related_request_enabled, false))}</td>
        <td>${yesNoPill(boolFromValue(row.attachment_enabled, true))}</td>
        <td><button class="mini-btn blue" onclick="openSupportCategoryModal('${jsArg(code)}')">Edit</button></td>
      </tr>
    `;
  }).join('');
}

function openSupportCategoryModal(code = ''){
  const row = state.supportCategories.find(item => String(item.code || '') === String(code)) || {
    code: '',
    name: '',
    active: true,
    sort_order: 100,
    related_request_enabled: false,
    attachment_enabled: true
  };
  const existing = String(row.code || '');
  const body = `
    <div class="form-grid">
      <label>Category Code
        <input class="input" id="supportCategoryCode" value="${esc(existing)}" ${existing ? 'readonly' : ''} placeholder="OTHER">
      </label>
      <label>Category Name
        <input class="input" id="supportCategoryName" value="${esc(row.name || '')}" placeholder="Other">
      </label>
      <label>Active
        <select class="input" id="supportCategoryActive">
          <option value="1" ${boolFromValue(row.active, true) ? 'selected' : ''}>Enabled</option>
          <option value="0" ${!boolFromValue(row.active, true) ? 'selected' : ''}>Disabled</option>
        </select>
      </label>
      <label>Sort Order
        <input class="input" id="supportCategorySort" type="number" min="1" max="999" value="${esc(row.sort_order ?? 100)}">
      </label>
      <label>Related Request Enabled
        <select class="input" id="supportCategoryRelated">
          <option value="0" ${!boolFromValue(row.related_request_enabled, false) ? 'selected' : ''}>No</option>
          <option value="1" ${boolFromValue(row.related_request_enabled, false) ? 'selected' : ''}>Yes</option>
        </select>
      </label>
      <label>Attachment Enabled
        <select class="input" id="supportCategoryAttachment">
          <option value="1" ${boolFromValue(row.attachment_enabled, true) ? 'selected' : ''}>Yes</option>
          <option value="0" ${!boolFromValue(row.attachment_enabled, true) ? 'selected' : ''}>No</option>
        </select>
      </label>
    </div>
  `;
  const foot = `
    <button class="btn brand" onclick="saveSupportCategory()">Save Category</button>
    <button class="btn ghost" onclick="closeModal()">Cancel</button>
  `;
  openModal(existing ? 'Edit Support Category' : 'Add Support Category', body, foot);
}

async function saveSupportCategory(){
  const payload = {
    code: document.getElementById('supportCategoryCode')?.value || '',
    name: document.getElementById('supportCategoryName')?.value || '',
    active: document.getElementById('supportCategoryActive')?.value === '1',
    sort_order: Number(document.getElementById('supportCategorySort')?.value || 100),
    related_request_enabled: document.getElementById('supportCategoryRelated')?.value === '1',
    attachment_enabled: document.getElementById('supportCategoryAttachment')?.value === '1'
  };
  try {
    await proxyPost('support_category_save', payload, true, { busyText:'Saving support category...' });
    closeModal();
    showToast('Support category saved', 'ok');
    await loadSupportCategories({ busy:false, silentLog:true });
  } catch (err) {
    showToast(err.message || 'Category save failed', 'error');
  }
}

/* =========================
   ADD MONEY
========================= */

function addMoneyFilters(){
  return {
    status: document.getElementById('addMoneyStatusFilter')?.value || '',
    country: document.getElementById('addMoneyCountryFilter')?.value || '',
    method: document.getElementById('addMoneyMethodFilter')?.value || '',
    limit: 150
  };
}

async function loadAddMoneyRequests(options = {}){
  const data = await proxyGet('add_money_requests', addMoneyFilters(), options);
  state.addMoney = Array.isArray(data.items) ? data.items : [];
  state.loaded.addMoney = true;
  renderAddMoneyRequests();
}

function addMoneyAmount(row){
  return `${walletPrefix(row.currency || row.wallet_currency)} ${money(row.amount || 0)}`;
}

function renderAddMoneyRequests(){
  const tbody = document.getElementById('addMoneyTableBody');
  if (!tbody) return;

  if (!state.addMoney.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="empty">No add money request found.</td></tr>';
    return;
  }

  tbody.innerHTML = state.addMoney.map(row => {
    const id = String(row.request_id || '');
    const status = String(row.status || 'PENDING').toUpperCase();
    const pending = status === 'PENDING';
    const receiptUrl = String(row.receipt_url || '');
    const txn = String(row.transaction_id || '');
    const sender = String(row.sender_number || '');
    const proof = receiptUrl
      ? `<a class="mini-btn blue" href="${esc(receiptUrl)}" target="_blank" rel="noopener">View Receipt</a>`
      : `<div class="muted">Txn: ${esc(txn || '-')}</div><div class="muted">Sender: ${esc(sender || '-')}</div>`;

    return `
      <tr>
        <td><div class="mono">${esc(id)}</div><div class="muted">${fmtTs(row.created_at || 0)}</div></td>
        <td><strong>${esc(row.name || '-')}</strong><div class="muted">${esc(row.phone || '-')} - ${esc(row.role || '-')}</div></td>
        <td>${esc(row.pricing_country || '-')}<div class="muted">${esc(row.currency || '-')}</div></td>
        <td>${esc(row.method || '-')}</td>
        <td><strong>${addMoneyAmount(row)}</strong></td>
        <td>${proof}</td>
        <td class="status-cell add-money-status-cell">${statusPill(status)}</td>
        <td class="actions-cell">
          <div class="row-actions">
            ${pending ? `<button class="mini-btn green" onclick="openAddMoneyAction('${esc(id)}','APPROVE')">Approve</button>` : ''}
            ${pending ? `<button class="mini-btn red" onclick="openAddMoneyAction('${esc(id)}','REJECT')">Reject</button>` : ''}
            <button class="mini-btn ghost" onclick="copyAddMoneyValue('${esc(id)}')">Copy ID</button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

async function copyAddMoneyValue(value){
  try {
    await navigator.clipboard.writeText(String(value || ''));
    showToast('Copied', 'ok');
  } catch (_) {
    showToast('Copy failed', 'error');
  }
}

function openAddMoneyAction(requestId, action){
  const row = state.addMoney.find(item => String(item.request_id || '') === String(requestId));
  if (!row) {
    showToast('Request not found', 'error');
    return;
  }

  const isApprove = action === 'APPROVE';
  const body = `
    <div class="detail-grid">
      <div class="detail-item"><label>Request ID</label><strong>${esc(requestId)}</strong></div>
      <div class="detail-item"><label>User</label><strong>${esc(row.name || '-')}</strong></div>
      <div class="detail-item"><label>Amount</label><strong>${addMoneyAmount(row)}</strong></div>
      <div class="detail-item"><label>Method</label><strong>${esc(row.method || '-')}</strong></div>
    </div>
    ${isApprove ? '<p class="muted">Wallet balance will be credited only after this approval.</p>' : '<label>Reject Reason</label><textarea id="addMoneyRejectReason" class="input" rows="3" placeholder="Reason shown in user history"></textarea>'}
  `;
  const foot = `
    <button class="btn ${isApprove ? 'brand' : 'red'}" onclick="submitAddMoneyAction('${esc(requestId)}','${isApprove ? 'APPROVE' : 'REJECT'}')">${isApprove ? 'Approve Request' : 'Reject Request'}</button>
    <button class="btn ghost" onclick="closeModal()">Cancel</button>
  `;
  openModal(isApprove ? 'Approve Add Money' : 'Reject Add Money', body, foot);
}

async function submitAddMoneyAction(requestId, action){
  const isApprove = action === 'APPROVE';
  const reason = document.getElementById('addMoneyRejectReason')?.value || '';
  try {
    await proxyPost(isApprove ? 'add_money_approve' : 'add_money_reject', {
      request_id: requestId,
      reason
    }, true, { busyText: isApprove ? 'Approving request...' : 'Rejecting request...' });
    closeModal();
    showToast(isApprove ? 'Add money approved' : 'Add money rejected', 'ok');
    state.loaded.addMoney = false;
    state.loaded.users = false;
    await loadAddMoneyRequests({ busy:false, silentLog:true });
  } catch (err) {
    showToast(err.message || 'Action failed', 'error');
  }
}

function addMoneyPaymentMethodLabel(method){
  const key = String(method || '').toUpperCase();
  if (key === 'BKASH') return 'bKash';
  if (key === 'NAGAD') return 'Nagad';
  if (key === 'EWALLET') return 'eWallet';
  return 'Bank';
}

function renderAddMoneyPaymentAccountRows(accounts){
  const rows = Array.isArray(accounts) ? accounts : [];
  if (!rows.length) {
    return '<div class="empty">No payment account configured yet. Use Add New Account to create one. Old BD/MY settings still work as fallback until new accounts are added.</div>';
  }

  return `
    <div class="table-wrap add-money-settings-table">
      <table class="add-money-accounts-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Country</th>
            <th>Method</th>
            <th>A/C No</th>
            <th>Status</th>
            <th>Sort</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map((account) => `
            <tr>
              <td>
                <div class="payment-account-name-cell">
                  ${account.logo_url ? `<img class="payment-account-logo-sm" src="${esc(account.logo_url)}" alt="">` : `<span class="payment-account-fallback-sm">${esc(addMoneyPaymentMethodLabel(account.method).slice(0, 2))}</span>`}
                  <div><strong>${esc(account.display_name || '-')}</strong><br><span class="muted">${esc(account.account_holder || '-')}</span></div>
                </div>
              </td>
              <td>${esc(account.country || '-')} / ${esc(account.currency || '-')}</td>
              <td>${esc(addMoneyPaymentMethodLabel(account.method))}</td>
              <td>${esc(account.account_number || '-')}</td>
              <td>${account.active ? statusPill('Active') : statusPill('Inactive')}</td>
              <td>${Number(account.sort_order || 0)}</td>
              <td><button class="btn ghost sm" type="button" onclick="editAddMoneyPaymentAccount('${esc(account.account_id || '')}')">Edit</button></td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function editAddMoneyPaymentAccount(accountId){
  openAddMoneyPaymentAccountModal(accountId);
}

function resetAddMoneyPaymentAccountForm(){
  openAddMoneyPaymentAccountModal('');
}

function openAddMoneyPaymentAccountModal(accountId = ''){
  const account = (state.addMoneyPaymentAccounts || []).find(item => String(item.account_id || '') === String(accountId || ''));
  if (accountId && !account) {
    showToast('Payment account not found', 'error');
    return;
  }

  const row = account || {
    account_id: '',
    country: 'BD',
    method: 'BKASH',
    display_name: '',
    account_holder: '',
    account_number: '',
    instruction: '',
    logo_url: '',
    sort_order: 100,
    active: true
  };
  const selected = (value, current) => String(value).toUpperCase() === String(current || '').toUpperCase() ? 'selected' : '';
  const body = `
    <div class="form-grid">
      <input id="amAccountId" type="hidden" value="${esc(row.account_id || '')}">
      <div class="field">
        <label>Country</label>
        <select id="amAccountCountry" class="input">
          <option value="BD" ${selected('BD', row.country)}>Bangladesh / BDT</option>
          <option value="MY" ${selected('MY', row.country)}>Malaysia / MYR</option>
        </select>
      </div>
      <div class="field">
        <label>Method</label>
        <select id="amAccountMethod" class="input">
          <option value="BKASH" ${selected('BKASH', row.method)}>bKash</option>
          <option value="NAGAD" ${selected('NAGAD', row.method)}>Nagad</option>
          <option value="BANK" ${selected('BANK', row.method)}>Bank</option>
          <option value="EWALLET" ${selected('EWALLET', row.method)}>eWallet</option>
        </select>
      </div>
      <div class="field">
        <label>Payment Name</label>
        <input id="amAccountName" class="input" placeholder="RHB Bank or bKash Personal" value="${esc(row.display_name || '')}">
      </div>
      <div class="field">
        <label>A/C Name</label>
        <input id="amAccountHolder" class="input" placeholder="Account holder name" value="${esc(row.account_holder || '')}">
      </div>
      <div class="field">
        <label>A/C No</label>
        <input id="amAccountNumber" class="input" placeholder="Account number" value="${esc(row.account_number || '')}">
      </div>
      <div class="field">
        <label>Sort Order</label>
        <input id="amAccountSort" class="input" type="number" step="1" placeholder="100" value="${esc(row.sort_order ?? 100)}">
      </div>
      <div class="field">
        <label>Logo URL optional</label>
        <input id="amAccountLogo" class="input" placeholder="https://..." value="${esc(row.logo_url || '')}">
      </div>
      <label class="field" style="display:flex;align-items:center;gap:8px;margin-top:28px;">
        <input id="amAccountActive" type="checkbox" ${row.active ? 'checked' : ''}> Active
      </label>
      <div class="field form-full">
        <label>Instruction optional</label>
        <textarea id="amAccountInstruction" class="input" rows="3" placeholder="Payment instruction">${esc(row.instruction || '')}</textarea>
      </div>
    </div>
  `;
  const foot = `
    <button class="btn brand" type="button" onclick="saveAddMoneyPaymentAccount()">Save Account</button>
    <button class="btn ghost" type="button" onclick="openAddMoneySettings()">Back</button>
  `;
  openModal(accountId ? 'Edit Payment Account' : 'Add New Payment Account', body, foot);
}

async function saveAddMoneyPaymentAccount(){
  const payload = {
    account_id: document.getElementById('amAccountId')?.value || '',
    country: document.getElementById('amAccountCountry')?.value || 'BD',
    method: document.getElementById('amAccountMethod')?.value || 'BKASH',
    display_name: document.getElementById('amAccountName')?.value || '',
    account_holder: document.getElementById('amAccountHolder')?.value || '',
    account_number: document.getElementById('amAccountNumber')?.value || '',
    instruction: document.getElementById('amAccountInstruction')?.value || '',
    logo_url: document.getElementById('amAccountLogo')?.value || '',
    sort_order: Number(document.getElementById('amAccountSort')?.value || 100),
    active: !!document.getElementById('amAccountActive')?.checked
  };

  try {
    await proxyPost('add_money_account_save', payload, true, { busyText:'Saving payment account...' });
    showToast('Payment account saved', 'ok');
    await openAddMoneySettings();
  } catch (err) {
    showToast(err.message || 'Failed to save payment account', 'error');
  }
}

async function openAddMoneySettings(){
  const data = await proxyGet('add_money_settings', {}, { busyText:'Loading payment settings...' });
  const accounts = Array.isArray(data.accounts) ? data.accounts : [];
  state.addMoneyPaymentAccounts = accounts;
  const body = `
    <div class="detail-item">
      <div class="payment-settings-head">
        <div>
          <label>Add Money Payment Accounts</label>
          <p class="muted">Manage active deposit accounts for BD and MY users. Existing old settings remain fallback only if no new account exists for a country.</p>
        </div>
        <button class="btn brand" type="button" onclick="openAddMoneyPaymentAccountModal('')">Add New Account</button>
      </div>
      ${renderAddMoneyPaymentAccountRows(accounts)}
    </div>
  `;
  const foot = `
    <button class="btn ghost" onclick="closeModal()">Close</button>
  `;
  openModal('Add Money Payment Settings', body, foot);
}

/* =========================
   EVENT BINDINGS
========================= */

document.getElementById('loginBtn')?.addEventListener('click', doLogin);

document.getElementById('loginPassword')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') doLogin();
});

document.querySelectorAll('.nav-btn[data-section]').forEach(btn => {
  btn.addEventListener('click', () => openSection(btn.dataset.section));
});

document.querySelectorAll('[data-topup-tab]').forEach(btn => {
  btn.addEventListener('click', async () => {
    document.querySelectorAll('[data-topup-tab]').forEach(x => x.classList.remove('active'));
    btn.classList.add('active');

    state.topupTab = btn.dataset.topupTab;
    state.loaded.topups = false;

    await loadTopups({ busyText:'Loading topup list...' });
  });
});

document.getElementById('topupSearch')?.addEventListener('input', renderTopups);
document.getElementById('usersSearch')?.addEventListener('input', () => {
  clearTimeout(usersSearchTimer);
  usersSearchTimer = setTimeout(() => {
    state.usersPagination.page = 1;
    state.loaded.users = false;
    loadUsers({ page: 1, busy:false, silentLog:true }).catch(() => {});
  }, 350);
});

document.getElementById('refreshBtn')?.addEventListener('click', () => refreshCurrentView(false));
document.getElementById('reloadTopupBtn')?.addEventListener('click', () => loadTopups({ busyText:'Reloading topup...' }));
document.getElementById('reloadBundleBtn')?.addEventListener('click', () => loadBundles({ busyText:'Reloading bundles...' }));
document.getElementById('reloadAddMoneyBtn')?.addEventListener('click', () => loadAddMoneyRequests({ busyText:'Reloading add money requests...' }));
document.getElementById('addMoneySettingsBtn')?.addEventListener('click', openAddMoneySettings);
document.getElementById('addMoneyStatusFilter')?.addEventListener('change', () => loadAddMoneyRequests({ busyText:'Filtering add money requests...' }));
document.getElementById('addMoneyCountryFilter')?.addEventListener('change', () => loadAddMoneyRequests({ busyText:'Filtering add money requests...' }));
document.getElementById('addMoneyMethodFilter')?.addEventListener('change', () => loadAddMoneyRequests({ busyText:'Filtering add money requests...' }));
document.getElementById('reloadSupportBtn')?.addEventListener('click', () => loadSupportAdmin({ busyText:'Reloading support center...' }));
document.getElementById('supportStatusFilter')?.addEventListener('change', () => loadSupportTickets({ busyText:'Filtering support tickets...' }));
document.getElementById('supportSearch')?.addEventListener('input', () => {
  clearTimeout(supportSearchTimer);
  supportSearchTimer = setTimeout(() => loadSupportTickets({ busy:false, silentLog:true }).catch(() => {}), 350);
});
document.getElementById('saveSupportConfigBtn')?.addEventListener('click', saveSupportConfig);
document.getElementById('supportCategoryAddBtn')?.addEventListener('click', () => openSupportCategoryModal(''));

document.getElementById('createBundleOfferBtn')?.addEventListener('click', () => openBundleOfferModal(''));
document.getElementById('reloadBundleOffersBtn')?.addEventListener('click', () => loadBundleOffers({ busyText:'Reloading bundle offers...' }));
document.getElementById('bundleOfferSearch')?.addEventListener('input', renderBundleOffers);
document.getElementById('bundleOfferStatusFilter')?.addEventListener('change', renderBundleOffers);

document.getElementById('reloadUsersBtn')?.addEventListener('click', () => {
  state.usersPagination.page = 1;
  loadUsers({ page: 1, busyText:'Reloading users...' }).catch(() => {});
});
document.getElementById('usersPrevBtn')?.addEventListener('click', () => loadUsersPage(state.usersPagination.page - 1));
document.getElementById('usersNextBtn')?.addEventListener('click', () => loadUsersPage(state.usersPagination.page + 1));
document.getElementById('walletHistoryBtn')?.addEventListener('click', openWalletTransferHistory);
document.getElementById('reloadOperatorsBtn')?.addEventListener('click', () => loadOperators({ busyText:'Reloading operators...' }));
document.getElementById('reloadWorkersBtn')?.addEventListener('click', () => loadWorkersStatus({ busyText:'Reloading workers...' }));

document.getElementById('logoutBtn')?.addEventListener('click', doLogout);
document.getElementById('directTopupBtn')?.addEventListener('click', openDirectTopupModal);
document.getElementById('openConfigBtn')?.addEventListener('click', openAppConfigModal);
document.getElementById('createUserBtn')?.addEventListener('click', openCreateUserModal);

document.addEventListener('change', (e) => {
  if (!e.target) return;

  if (e.target.id === 'userRole') {
    syncUserRoleFields();
  }

  if (e.target.id === 'editUserRole') {
    syncEditUserRoleFields();
  }
});

document.addEventListener('input', (e) => {
  if (e.target?.id === 'userCommissionPer1000') {
    e.target.dataset.userEdited = '1';
  }
});

document.getElementById('closeDrawerBtn')?.addEventListener('click', closeDrawer);
document.getElementById('drawerFootClose')?.addEventListener('click', closeDrawer);

document.getElementById('modalWrap')?.addEventListener('click', e => {
  if (e.target.id === 'modalWrap') closeModal();
});

const autoRefreshSelect = document.getElementById('autoRefreshSelect');
if (autoRefreshSelect) {
  autoRefreshSelect.value = String(state.autoRefreshSeconds || 0);

  autoRefreshSelect.addEventListener('change', (e) => {
    state.autoRefreshSeconds = Number(e.target.value || 0);
    configureAutoRefresh();

    log(`Auto refresh set to ${state.autoRefreshSeconds === 0 ? 'Off' : state.autoRefreshSeconds + ' sec'}.`);
    showToast(`Auto refresh: ${state.autoRefreshSeconds === 0 ? 'Off' : state.autoRefreshSeconds + ' sec'}`, 'info');
  });
}

/* =========================
   EXPOSE INLINE FUNCTIONS
========================= */

window.viewTopup = viewTopup;
window.openTopupAction = openTopupAction;
window.submitTopupAction = submitTopupAction;

window.openBundleAction = openBundleAction;
window.submitBundleAction = submitBundleAction;

window.openBundleOfferModal = openBundleOfferModal;
window.saveBundleOffer = saveBundleOffer;
window.expireBundleOffer = expireBundleOffer;
window.deleteBundleOffer = deleteBundleOffer;

window.viewUser = viewUser;
window.openEditUserModal = openEditUserModal;
window.submitEditUser = submitEditUser;

window.openWalletAction = openWalletAction;
window.submitWalletAction = submitWalletAction;
window.openLedger = openLedger;
window.loadWalletTransferHistory = loadWalletTransferHistory;
window.openAddMoneyAction = openAddMoneyAction;
window.submitAddMoneyAction = submitAddMoneyAction;
window.copyAddMoneyValue = copyAddMoneyValue;
window.openAddMoneyPaymentAccountModal = openAddMoneyPaymentAccountModal;
window.editAddMoneyPaymentAccount = editAddMoneyPaymentAccount;
window.resetAddMoneyPaymentAccountForm = resetAddMoneyPaymentAccountForm;
window.saveAddMoneyPaymentAccount = saveAddMoneyPaymentAccount;

window.editOperator = editOperator;
window.saveOperator = saveOperator;

window.submitDirectTopup = submitDirectTopup;
window.saveAppConfig = saveAppConfig;

window.closeModal = closeModal;
window.closeDrawer = closeDrawer;

window.viewWorkerStatus = viewWorkerStatus;

window.openUserApiKeys = openUserApiKeys;
window.createUserApiKey = createUserApiKey;
window.updateUserApiKeyStatus = updateUserApiKeyStatus;

window.closeAdminOtpModal = closeAdminOtpModal;
window.verifyAdminLoginOtp = verifyAdminLoginOtp;
window.resendAdminLoginOtp = resendAdminLoginOtp;

/* =========================
   START
========================= */

updateInteractiveState();
updateStatusStrip();
bootstrapSession();
