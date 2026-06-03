const loginState = {
  busyCount: 0,
  preAuthToken: '',
  otpRequestId: '',
  maskedPhone: '',
  expiresInSeconds: 300,
  trustDevice: true
};

function normalizeSubadminUrl(url, fallback) {
  const value = String(url || '').trim();
  if (!value) return fallback;
  const marker = '/api/subadmin/';
  const index = value.indexOf(marker);
  if (index !== -1) return value.slice(index + marker.length) || fallback;
  return value;
}

const LOGIN_PROXY_URL = normalizeSubadminUrl(window.SUBADMIN_PROXY_URL, 'proxy.php');

function $id(id){
  return document.getElementById(id);
}

function loginSetBusy(on, text = 'Loading...'){
  const wrap = $id('loadingWrap');
  const txt = $id('loadingText');

  if (!wrap || !txt) return;

  if (on) {
    loginState.busyCount += 1;
    txt.textContent = text;
    wrap.classList.add('show');
    return;
  }

  loginState.busyCount = Math.max(0, loginState.busyCount - 1);

  if (loginState.busyCount === 0) {
    txt.textContent = 'Loading...';
    wrap.classList.remove('show');
  }
}

function loginShowToast(message, type = 'info'){
  const wrap = $id('toastWrap');
  if (!wrap) return;

  const box = document.createElement('div');
  box.className = 'toast' + (type ? ' ' + type : '');
  box.textContent = String(message || '');
  wrap.appendChild(box);

  setTimeout(() => {
    box.remove();
  }, 3500);
}

function loginSetError(message = ''){
  const err = $id('loginError');
  const ok = $id('loginSuccess');

  if (ok) {
    ok.classList.add('hidden');
    ok.textContent = '';
  }

  if (!err) return;

  if (!message) {
    err.classList.add('hidden');
    err.textContent = '';
    return;
  }

  err.textContent = String(message);
  err.classList.remove('hidden');
}

function loginSetSuccess(message = ''){
  const err = $id('loginError');
  const ok = $id('loginSuccess');

  if (err) {
    err.classList.add('hidden');
    err.textContent = '';
  }

  if (!ok) return;

  if (!message) {
    ok.classList.add('hidden');
    ok.textContent = '';
    return;
  }

  ok.textContent = String(message);
  ok.classList.remove('hidden');
}

async function loginReadJsonSafe(res){
  const text = await res.text();

  if (!text || !text.trim()) {
    throw new Error('Empty response from server');
  }

  try {
    return JSON.parse(text);
  } catch (e) {
    throw new Error(text.substring(0, 300));
  }
}

async function loginProxyPost(action, body = {}, busyText = 'Processing...'){
  loginSetBusy(true, busyText);

  try {
    const res = await fetch(LOGIN_PROXY_URL + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(body || {})
    });

    const json = await loginReadJsonSafe(res);

    if (!res.ok || !json.ok) {
      const err = new Error(json.message || 'Request failed');
      err.code = json.code || 'ERROR';
      err.data = json.data || {};
      throw err;
    }

    return json.data || {};
  } finally {
    loginSetBusy(false);
  }
}

function loginResetOtpState(){
  loginState.preAuthToken = '';
  loginState.otpRequestId = '';
  loginState.maskedPhone = '';
  loginState.expiresInSeconds = 300;
}

function openLoginOtpModal(){
  const modal = $id('loginOtpModalWrap');
  if (modal) modal.classList.add('open');
}

function closeLoginOtpModal(resetState = true){
  const modal = $id('loginOtpModalWrap');
  const otpInput = $id('loginOtpCode');
  const statusBox = $id('loginOtpStatus');
  const phoneBox = $id('loginOtpMaskedPhone');
  const expBox = $id('loginOtpExpiresText');

  if (modal) modal.classList.remove('open');
  if (otpInput) otpInput.value = '';
  if (statusBox) statusBox.textContent = 'OTP পাঠানোর পরে এখানে status দেখাবে।';
  if (phoneBox) phoneBox.textContent = '-';
  if (expBox) expBox.textContent = '5 minutes';

  if (resetState) {
    loginResetOtpState();
  }
}

function updateLoginOtpModal(data){
  loginState.preAuthToken = String(data.pre_auth_token || loginState.preAuthToken || '');
  loginState.otpRequestId = String(data.otp_request_id || loginState.otpRequestId || '');
  loginState.maskedPhone = String(data.masked_phone || loginState.maskedPhone || '');
  loginState.expiresInSeconds = Number(data.expires_in_seconds || 300);

  const phoneBox = $id('loginOtpMaskedPhone');
  const expBox = $id('loginOtpExpiresText');
  const statusBox = $id('loginOtpStatus');
  const otpInput = $id('loginOtpCode');

  if (phoneBox) phoneBox.textContent = loginState.maskedPhone || '-';
  if (expBox) expBox.textContent = loginState.expiresInSeconds + ' seconds';
  if (otpInput) otpInput.value = '';

  if (statusBox) {
    statusBox.textContent = 'OTP sent to ' + (loginState.maskedPhone || 'your phone') + '. Enter the code to complete login.';
  }
}

function loginGoDashboard(){
  window.location.href = 'dashboard.php';
}

async function handleLogin(){
  loginSetError('');
  loginSetSuccess('');

  const phone = ($id('loginPhone')?.value || '').trim();
  const password = $id('loginPassword')?.value || '';
  const trustDevice = !!$id('rememberTrustedDevice')?.checked;

  loginState.trustDevice = trustDevice;

  if (!phone) {
    loginSetError('Phone is required');
    return;
  }

  if (!password) {
    loginSetError('Password is required');
    return;
  }

  try {
    const data = await loginProxyPost('login', {
      phone,
      password,
      trust_device: trustDevice
    }, 'Checking login...');

    if (data.require_otp) {
      updateLoginOtpModal(data);
      openLoginOtpModal();
      loginShowToast('OTP sent successfully', 'ok');
      return;
    }

    if (data.login_complete || data.session_active || data.redirect === 'dashboard') {
      loginSetSuccess('Login successful. Redirecting...');
      loginShowToast('Login successful', 'ok');
      setTimeout(loginGoDashboard, 500);
      return;
    }

    loginSetError('Unexpected login response');
  } catch (err) {
    loginSetError(err.message || 'Login failed');
    loginShowToast(err.message || 'Login failed', 'error');
  }
}

async function handleVerifyLoginOtp(){
  const otp = ($id('loginOtpCode')?.value || '').trim();
  const statusBox = $id('loginOtpStatus');

  if (!loginState.preAuthToken || !loginState.otpRequestId) {
    if (statusBox) statusBox.textContent = 'Login session expired. Please login again.';
    return;
  }

  if (!otp) {
    if (statusBox) statusBox.textContent = 'Please enter OTP first.';
    return;
  }

  try {
    const data = await loginProxyPost('login_verify_otp', {
      pre_auth_token: loginState.preAuthToken,
      otp_request_id: loginState.otpRequestId,
      otp: otp,
      trust_device: loginState.trustDevice
    }, 'Verifying OTP...');

    if (statusBox) {
      statusBox.textContent = 'OTP verified successfully. Redirecting...';
    }

    loginShowToast('OTP verified successfully', 'ok');

    if (data.login_complete || data.session_active || data.redirect === 'dashboard') {
      setTimeout(loginGoDashboard, 500);
      return;
    }

    setTimeout(loginGoDashboard, 500);
  } catch (err) {
    if (statusBox) {
      statusBox.textContent = err.message || 'OTP verification failed';
    }
    loginShowToast(err.message || 'OTP verification failed', 'error');
  }
}

async function handleResendLoginOtp(){
  const statusBox = $id('loginOtpStatus');

  if (!loginState.preAuthToken || !loginState.otpRequestId) {
    if (statusBox) statusBox.textContent = 'Login session expired. Please login again.';
    return;
  }

  try {
    const data = await loginProxyPost('login_resend_otp', {
      pre_auth_token: loginState.preAuthToken,
      otp_request_id: loginState.otpRequestId
    }, 'Resending OTP...');

    updateLoginOtpModal(data);

    if (statusBox) {
      statusBox.textContent = 'OTP resent successfully to ' + (loginState.maskedPhone || 'your phone') + '.';
    }

    loginShowToast('OTP resent successfully', 'ok');
  } catch (err) {
    if (statusBox) {
      statusBox.textContent = err.message || 'Failed to resend OTP';
    }
    loginShowToast(err.message || 'Failed to resend OTP', 'error');
  }
}

function bindLoginEvents(){
  $id('loginBtn')?.addEventListener('click', handleLogin);

  $id('loginPassword')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      handleLogin();
    }
  });

  $id('verifyLoginOtpBtn')?.addEventListener('click', handleVerifyLoginOtp);
  $id('resendLoginOtpBtn')?.addEventListener('click', handleResendLoginOtp);

  $id('cancelLoginOtpBtn')?.addEventListener('click', () => closeLoginOtpModal(true));
  $id('closeLoginOtpModalBtn')?.addEventListener('click', () => closeLoginOtpModal(true));

  $id('loginOtpCode')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      handleVerifyLoginOtp();
    }
  });

  $id('loginOtpModalWrap')?.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'loginOtpModalWrap') {
      closeLoginOtpModal(true);
    }
  });
}

bindLoginEvents();
