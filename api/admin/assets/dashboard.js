const state = {
  csrf: '',
  me: null,

  topupTab: 'pending',

  topups: [],
  bundles: [],
  bundleOffers: [],
  doneTopups: [],
  doneBundles: [],
  users: [],
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
  }
};

const submitLocks = {};

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
  else if (['FAILED','ERROR','INACTIVE','DISABLED','REVOKED','DELETED','REMOVED'].includes(t)) cls = 'danger';
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

  document.getElementById('drawer')?.classList.add('open');
  document.getElementById('drawerFootCloseDynamic')?.addEventListener('click', closeDrawer);
}

function closeDrawer(){
  document.getElementById('drawer')?.classList.remove('open');
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

    const res = await fetch(`proxy.php?action=${encodeURIComponent(action)}${qs ? '&' + qs : ''}`, {
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

    const res = await fetch(`proxy.php?action=${encodeURIComponent(action)}`, {
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

    openSection('dashboardSection');

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
  const password = document.getElementById('loginPassword')?.value || '';

  if (!phone || !password) {
    setLoginError('Phone and password are required');
    return;
  }

  try{
    const data = await proxyPost('login', {
      phone,
      password,
      trust_device: true,
      device_id: 'ADMIN_WEB',
      device_name: 'Admin Dashboard'
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
            <option value="BL" ${String(item.operator || '').toUpperCase() === 'BL' ? 'selected' : ''}>BL</option>
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

  const showCommission = role === 'RETAILER' || role === 'SUBADMIN';
  const showApi = role === 'SUBADMIN';
  const showLimits = role === 'RETAILER' || role === 'SUBADMIN';

  document.getElementById('commissionField')?.classList.toggle('hidden', !showCommission);
  document.getElementById('apiEnabledField')?.classList.toggle('hidden', !showApi);
  document.getElementById('minAmountField')?.classList.toggle('hidden', !showLimits);
  document.getElementById('maxAmountField')?.classList.toggle('hidden', !showLimits);

  if (!showCommission) {
    const node = document.getElementById('userCommissionPer1000');
    if (node) node.value = '0';
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

  const showCommission = role === 'RETAILER' || role === 'SUBADMIN';
  const showApi = role === 'SUBADMIN';
  const showLimits = role === 'RETAILER' || role === 'SUBADMIN';

  document.getElementById('editCommissionField')?.classList.toggle('hidden', !showCommission);
  document.getElementById('editApiEnabledField')?.classList.toggle('hidden', !showApi);
  document.getElementById('editMinAmountField')?.classList.toggle('hidden', !showLimits);
  document.getElementById('editMaxAmountField')?.classList.toggle('hidden', !showLimits);

  if (!showCommission) {
    const node = document.getElementById('editUserCommissionPer1000');
    if (node) node.value = '0';
  }

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
  if (apiEl) apiEl.value = '0';
  if (topupEl) topupEl.value = '1';
  if (bundleEl) bundleEl.value = '1';
  if (minEl) minEl.value = '0';
  if (maxEl) maxEl.value = '0';

  syncUserRoleFields();
}

async function loadUsers(options = {}){
  const data = await proxyGet('users', {}, options);

  state.users = data.items || [];
  state.loaded.users = true;

  renderUsers();

  const dashUsersCount = document.getElementById('dashUsersCount');
  const dashBalanceTotal = document.getElementById('dashBalanceTotal');

  if (dashUsersCount) dashUsersCount.textContent = state.users.length;

  const totalBalance = state.users.reduce((sum, row) => sum + Number(row.available_balance || 0), 0);

  if (dashBalanceTotal) dashBalanceTotal.textContent = money(totalBalance);

  if (!options.silentLog) log('Loaded users list.');
}

function renderUsers(){
  const q = document.getElementById('usersSearch')?.value.trim().toLowerCase() || '';
  const rows = state.users.filter(item => !q || JSON.stringify(item).toLowerCase().includes(q));
  const tbody = document.getElementById('usersTableBody');

  if (!tbody) return;

  if (!rows.length){
    tbody.innerHTML = '<tr><td colspan="10" class="empty">No user found.</td></tr>';
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
      <td>${statusPill(item.status || 'ACTIVE')}</td>
      <td>${rolePill(item.role || 'USER')}</td>
      <td>${esc(item.country_code || item.country || '-')}</td>
      <td>${esc((Number(item.commission_per_1000 || 0)).toFixed(2))}</td>
      <td>${yesNoPill(!!item.api_enabled)}</td>
      <td>${money(item.available_balance || 0)}</td>
      <td>${money(item.hold_balance || 0)}</td>
      <td>
        <div class="row-actions">
          <button class="mini-btn" onclick="viewUser('${esc(item.uid || '')}')">View</button>
          <button class="mini-btn" onclick="openEditUserModal('${esc(item.uid || '')}')">Edit</button>
        </div>
      </td>
    </tr>
  `).join('');
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
          <div class="detail-item"><label>Role</label><strong>${esc(data.role || 'USER')}</strong></div>
          <div class="detail-item"><label>Country</label><strong>${esc(data.country_code || data.country || '-')}</strong></div>
          <div class="detail-item"><label>Commission / 1000</label><strong>${Number(data.commission_per_1000 || 0).toFixed(2)}</strong></div>
          <div class="detail-item"><label>API Enabled</label><strong>${data.api_enabled ? 'Yes' : 'No'}</strong></div>
          <div class="detail-item"><label>Topup Enabled</label><strong>${data.topup_enabled ? 'Yes' : 'No'}</strong></div>
          <div class="detail-item"><label>Bundle Enabled</label><strong>${data.bundle_enabled ? 'Yes' : 'No'}</strong></div>
          <div class="detail-item"><label>Amount Limits</label><strong>${Number(data.min_amount || 0).toFixed(2)} - ${Number(data.max_amount || 0).toFixed(2)}</strong></div>
          <div class="detail-item"><label>Created</label><strong>${fmtTs(data.created_at)}</strong></div>
          <div class="detail-item"><label>Last Login</label><strong>${fmtTs(data.last_login_at)}</strong></div>
          <div class="detail-item"><label>Available Balance</label><strong>${money(w.available_balance)}</strong></div>
          <div class="detail-item"><label>Hold Balance</label><strong>${money(w.hold_balance)}</strong></div>
          <div class="detail-item"><label>Total Topup Spent</label><strong>${money(w.total_topup_spent)}</strong></div>
          <div class="detail-item"><label>Total Bundle Spent</label><strong>${money(w.total_bundle_spent)}</strong></div>
          <div class="detail-item"><label>Total Refund</label><strong>${money(w.total_refund)}</strong></div>
          <div class="detail-item"><label>Wallet Updated</label><strong>${fmtTs(w.updated_at)}</strong></div>
        </div>
      `,
      `
        <button class="btn ghost" onclick="closeDrawer()">Close</button>
        <button class="btn blue" onclick="openEditUserModal('${esc(uid)}')">Edit User</button>
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
            </select>
          </div>

          <div>
            <label>Country</label>
            <select id="editUserCountry">
              <option value="">Not Set / Auto fallback</option>
              <option value="BD" ${(String(data.country_code || data.country || '').toUpperCase() === 'BD') ? 'selected' : ''}>Bangladesh (BD)</option>
              <option value="MY" ${(String(data.country_code || data.country || '').toUpperCase() === 'MY') ? 'selected' : ''}>Malaysia (MY)</option>
            </select>
          </div>

          <div class="form-full">
            <div class="card">
              <div class="card-body">
                <div class="metric-title" style="margin-bottom:12px;">Role Settings</div>

                <div class="form-grid">
                  <div id="editCommissionField">
                    <label>Commission per 1000</label>
                    <input class="input" id="editUserCommissionPer1000" type="number" step="0.01" min="0" value="${esc(String(data.commission_per_1000 || 0))}">
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

async function submitEditUser(){
  const uid = document.getElementById('editUserUid')?.value.trim() || '';
  const name = document.getElementById('editUserName')?.value.trim() || '';
  const email = document.getElementById('editUserEmail')?.value.trim() || '';
  const role = (document.getElementById('editUserRole')?.value || 'USER').toUpperCase();
  const status = (document.getElementById('editUserStatus')?.value || 'ACTIVE').toUpperCase();
  const country = (document.getElementById('editUserCountry')?.value || '').toUpperCase();

  const commission_per_1000 = numberFromValue(document.getElementById('editUserCommissionPer1000')?.value, 0);
  const api_enabled = boolFromValue(document.getElementById('editUserApiEnabled')?.value, false);
  const topup_enabled = boolFromValue(document.getElementById('editUserTopupEnabled')?.value, true);
  const bundle_enabled = boolFromValue(document.getElementById('editUserBundleEnabled')?.value, true);
  const min_amount = numberFromValue(document.getElementById('editUserMinAmount')?.value, 0);
  const max_amount = numberFromValue(document.getElementById('editUserMaxAmount')?.value, 0);

  try{
    const payload = {
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
      payload.country = country;
      payload.country_code = country;
    }

    const data = await proxyPost('user_update', payload, true, { busyText: 'Updating user account...' });

    closeModal();

    log(`User updated: ${data.uid || uid}`);
    showToast(`User updated: ${data.name || data.uid || uid}`, 'ok');

    await loadUsers({ busy:false, silentLog:true });

    const drawer = document.getElementById('drawer');
    if (drawer && drawer.classList.contains('open')) {
      await viewUser(uid);
    }
  }catch(err){
    if (err.code === 'EMAIL_EXISTS') {
      alert('This email is already registered.');
      return;
    }

    alert(err.message || 'Failed to update user');
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
          <label>Phone</label>
          <input class="input" id="userPhone" placeholder="Enter phone">
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
                  <label>Commission per 1000</label>
                  <input id="userCommissionPer1000" class="input" type="number" step="0.01" min="0" value="0">
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
  openModal(
    type === 'add' ? 'Add Balance' : 'Deduct Balance',
    `
      <div class="form-grid">
        <div class="form-full">
          <label>UID</label>
          <input class="input" id="walletUid" value="${esc(uid)}" readonly>
        </div>

        <div>
          <label>Amount</label>
          <input class="input" id="walletAmount" type="number" step="0.01" min="0">
        </div>

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

  try{
    await proxyPost(type === 'add' ? 'wallet_add' : 'wallet_deduct', {
      uid,
      amount,
      note
    }, true, { busyText: type === 'add' ? 'Adding balance...' : 'Deducting balance...' });

    closeModal();

    log(`${type === 'add' ? 'Added' : 'Deducted'} balance for ${uid}.`);
    showToast(`${type === 'add' ? 'Balance added' : 'Balance deducted'} for ${uid}`, 'ok');

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
                  <td>${money(item.amount)}</td>
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

/* =========================
   OPERATORS
========================= */

async function loadOperators(options = {}){
  const data = await proxyGet('operators', {}, options);

  state.operators = data.items || [];
  state.loaded.operators = true;

  renderOperators();

  const dashOperatorsCount = document.getElementById('dashOperatorsCount');
  if (dashOperatorsCount) {
    dashOperatorsCount.textContent = state.operators.filter(x => x.active).length;
  }

  if (!options.silentLog) log('Loaded operators list.');
}

function renderOperators(){
  const tbody = document.getElementById('operatorsTableBody');

  if (!tbody) return;

  if (!state.operators.length){
    tbody.innerHTML = '<tr><td colspan="6" class="empty">No operator found.</td></tr>';
    return;
  }

  tbody.innerHTML = state.operators.map(item => `
    <tr>
      <td><strong>${esc(item.operator || '-')}</strong></td>
      <td>${esc(item.name || '-')}</td>
      <td>${statusPill(item.active ? 'ACTIVE' : 'INACTIVE')}</td>
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

async function editOperator(operator){
  try{
    const data = await proxyGet('operator_get', { operator }, { busyText: 'Loading operator...' });

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
            <input class="input" id="opRetailerPin" value="${esc(data.retailer_secret_pin || '')}">
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
    active: document.getElementById('opActive')?.value === 'true',
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
            <option value="BL">BL</option>
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
document.getElementById('usersSearch')?.addEventListener('input', renderUsers);

document.getElementById('refreshBtn')?.addEventListener('click', () => refreshCurrentView(false));
document.getElementById('reloadTopupBtn')?.addEventListener('click', () => loadTopups({ busyText:'Reloading topup...' }));
document.getElementById('reloadBundleBtn')?.addEventListener('click', () => loadBundles({ busyText:'Reloading bundles...' }));

document.getElementById('createBundleOfferBtn')?.addEventListener('click', () => openBundleOfferModal(''));
document.getElementById('reloadBundleOffersBtn')?.addEventListener('click', () => loadBundleOffers({ busyText:'Reloading bundle offers...' }));
document.getElementById('bundleOfferSearch')?.addEventListener('input', renderBundleOffers);
document.getElementById('bundleOfferStatusFilter')?.addEventListener('change', renderBundleOffers);

document.getElementById('reloadUsersBtn')?.addEventListener('click', () => loadUsers({ busyText:'Reloading users...' }));
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
