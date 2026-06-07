const USER_PROXY_URL = window.USER_PROXY_URL || '/api/user/proxy.php';
const USER_LOGIN_URL = window.USER_LOGIN_URL || '/user/';

const state = {
  busyCount: 0,
  resetType: 'PASSWORD',
  forgotOtp: {
    preAuthToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300,
    resetType: 'PASSWORD'
  }
};

function el(id){
  return document.getElementById(id);
}

function showNode(id, msg){
  const node = el(id);
  if (!node) return;

  if (!msg) {
    node.classList.add('hidden');
    node.textContent = '';
    return;
  }

  node.classList.remove('hidden');
  node.textContent = msg;
}

function showError(msg){
  showNode('forgotError', msg || 'Something went wrong');
  showNode('forgotSuccess', '');
}

function showSuccess(msg){
  showNode('forgotSuccess', msg || 'Success');
  showNode('forgotError', '');
}

function setBusy(on, text='Loading...'){
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

function showToast(message, type='info'){
  const wrap = el('toastWrap');
  if (!wrap) return;

  const div = document.createElement('div');
  div.className = 'toast ' + type;
  div.textContent = message;
  wrap.appendChild(div);

  setTimeout(() => div.remove(), 3500);
}

async function readJsonSafe(res){
  const text = await res.text();

  if (!text || !text.trim()) {
    throw new Error('Empty response from server');
  }

  try{
    return JSON.parse(text);
  }catch(_){
    throw new Error(text.length > 500 ? text.slice(0,500) : text);
  }
}

async function proxyPost(action, body={}, busyText='Processing...'){
  setBusy(true, busyText);

  try{
    const res = await fetch(USER_PROXY_URL + '?action=' + encodeURIComponent(action), {
      method:'POST',
      credentials:'same-origin',
      headers:{
        'Content-Type':'application/json',
        'Accept':'application/json'
      },
      body:JSON.stringify(body)
    });

    const json = await readJsonSafe(res);

    if (!res.ok || !json.ok) {
      const err = new Error(json.message || 'Request failed');
      err.code = json.code || 'ERROR';
      err.data = json.data || {};
      throw err;
    }

    return json.data || {};
  }finally{
    setBusy(false);
  }
}

function setResetType(type){
  state.resetType = String(type || 'PASSWORD').toUpperCase();

  if (!['PASSWORD','PIN'].includes(state.resetType)) {
    state.resetType = 'PASSWORD';
  }

  if (el('resetType')) {
    el('resetType').value = state.resetType;
  }

  el('typePasswordBtn')?.classList.toggle('active', state.resetType === 'PASSWORD');
  el('typePinBtn')?.classList.toggle('active', state.resetType === 'PIN');
}

function updateOtpState(data){
  state.forgotOtp.preAuthToken = String(data.pre_auth_token || data.reset_token || data.forgot_token || '');
  state.forgotOtp.otpRequestId = String(data.otp_request_id || data.request_id || '');
  state.forgotOtp.maskedPhone = String(data.masked_phone || '');
  state.forgotOtp.expiresInSeconds = Number(data.expires_in_seconds || 300);
  state.forgotOtp.resetType = String(data.reset_type || state.resetType || 'PASSWORD').toUpperCase();

  if (!['PASSWORD','PIN'].includes(state.forgotOtp.resetType)) {
    state.forgotOtp.resetType = 'PASSWORD';
  }

  const isPin = state.forgotOtp.resetType === 'PIN';

  if (el('otpMaskedPhone')) {
    el('otpMaskedPhone').textContent = state.forgotOtp.maskedPhone || '-';
  }

  if (el('otpResetTypeText')) {
    el('otpResetTypeText').textContent = isPin ? 'PIN' : 'Password';
  }

  if (el('modalTitle')) {
    el('modalTitle').textContent = isPin ? 'Verify PIN Reset OTP' : 'Verify Password Reset OTP';
  }

  if (el('modalSub')) {
    el('modalSub').textContent = isPin ? 'Enter OTP and set your new PIN' : 'Enter OTP and set your new password';
  }

  el('passwordFields')?.classList.toggle('hidden', isPin);
  el('pinFields')?.classList.toggle('hidden', !isPin);

  clearOtpInputs();

  if (el('otpStatus')) {
    el('otpStatus').textContent =
      'OTP sent to ' + (state.forgotOtp.maskedPhone || 'your phone') + '. Enter OTP to reset ' + (isPin ? 'PIN.' : 'password.');
  }
}

function clearOtpInputs(){
  if (el('otpCode')) el('otpCode').value = '';
  if (el('newPassword')) el('newPassword').value = '';
  if (el('confirmPassword')) el('confirmPassword').value = '';
  if (el('newPin')) el('newPin').value = '';
  if (el('confirmPin')) el('confirmPin').value = '';
}

function openOtpModal(){
  el('forgotOtpModal')?.classList.add('show');
}

function closeOtpModal(){
  el('forgotOtpModal')?.classList.remove('show');
}

function resetOtpState(){
  state.forgotOtp = {
    preAuthToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300,
    resetType: state.resetType
  };

  if (el('otpMaskedPhone')) el('otpMaskedPhone').textContent = '-';
  if (el('otpResetTypeText')) el('otpResetTypeText').textContent = state.resetType === 'PIN' ? 'PIN' : 'Password';
  if (el('otpStatus')) el('otpStatus').textContent = 'OTP পাঠানোর পরে এখানে status দেখাবে।';
  clearOtpInputs();
}

async function sendForgotOtp(){
  showNode('forgotError', '');
  showNode('forgotSuccess', '');

  const phone = (el('forgotPhone')?.value || '').trim();
  const resetType = state.resetType;

  if (!phone) {
    showError('Phone is required');
    showToast('Phone is required', 'error');
    return;
  }

  try{
    const res = await proxyPost('forgot_send_otp', {
      phone,
      reset_type: resetType,
      device_id: 'USER_WEB',
      device_name: 'User Forgot'
    }, 'Sending OTP...');

    updateOtpState(res);
    openOtpModal();
    showSuccess('OTP sent successfully. Please verify to reset your ' + (resetType === 'PIN' ? 'PIN.' : 'password.'));
    showToast('OTP sent successfully', 'ok');
  }catch(error){
    showError(error.message || 'Failed to send OTP');
    showToast(error.message || 'Failed to send OTP', 'error');
  }
}

async function resendForgotOtp(){
  if (!state.forgotOtp.preAuthToken || !state.forgotOtp.otpRequestId) {
    if (el('otpStatus')) {
      el('otpStatus').textContent = 'Recovery session missing. Please start again.';
    }
    return;
  }

  try{
    const res = await proxyPost('forgot_resend_otp', {
      pre_auth_token: state.forgotOtp.preAuthToken,
      reset_token: state.forgotOtp.preAuthToken,
      forgot_token: state.forgotOtp.preAuthToken,
      otp_request_id: state.forgotOtp.otpRequestId,
      request_id: state.forgotOtp.otpRequestId
    }, 'Resending OTP...');

    updateOtpState(res);

    if (el('otpStatus')) {
      el('otpStatus').textContent =
        'OTP resent successfully to ' + (state.forgotOtp.maskedPhone || 'your phone') + '.';
    }

    showToast('OTP resent successfully', 'ok');
  }catch(error){
    if (el('otpStatus')) {
      el('otpStatus').textContent = error.message || 'Failed to resend OTP.';
    }
    showToast(error.message || 'Failed to resend OTP', 'error');
  }
}

function validateResetInputs(){
  const otp = (el('otpCode')?.value || '').trim();
  const resetType = state.forgotOtp.resetType || state.resetType;

  if (!otp) {
    return 'Please enter OTP first.';
  }

  if (resetType === 'PIN') {
    const newPin = (el('newPin')?.value || '').trim();
    const confirmPin = (el('confirmPin')?.value || '').trim();

    if (!newPin || !confirmPin) {
      return 'New PIN and confirm PIN are required.';
    }

    if (!/^\d{4,8}$/.test(newPin)) {
      return 'PIN must be 4 to 8 digits.';
    }

    if (newPin !== confirmPin) {
      return 'PIN confirmation does not match.';
    }

    return '';
  }

  const newPassword = el('newPassword')?.value || '';
  const confirmPassword = el('confirmPassword')?.value || '';

  if (!newPassword || !confirmPassword) {
    return 'New password and confirm password are required.';
  }

  if (newPassword.length < 6) {
    return 'Password must be at least 6 characters.';
  }

  if (newPassword !== confirmPassword) {
    return 'Password confirmation does not match.';
  }

  return '';
}

async function verifyForgotOtp(){
  if (!state.forgotOtp.preAuthToken || !state.forgotOtp.otpRequestId) {
    if (el('otpStatus')) {
      el('otpStatus').textContent = 'Recovery session missing. Please start again.';
    }
    return;
  }

  const err = validateResetInputs();

  if (err) {
    if (el('otpStatus')) {
      el('otpStatus').textContent = err;
    }
    showToast(err, 'error');
    return;
  }

  const resetType = state.forgotOtp.resetType || state.resetType;
  const body = {
    pre_auth_token: state.forgotOtp.preAuthToken,
    reset_token: state.forgotOtp.preAuthToken,
    forgot_token: state.forgotOtp.preAuthToken,
    otp_request_id: state.forgotOtp.otpRequestId,
    request_id: state.forgotOtp.otpRequestId,
    otp: (el('otpCode')?.value || '').trim(),
    reset_type: resetType
  };

  if (resetType === 'PIN') {
    body.new_pin = (el('newPin')?.value || '').trim();
    body.confirm_pin = (el('confirmPin')?.value || '').trim();
  } else {
    body.new_password = el('newPassword')?.value || '';
    body.confirm_password = el('confirmPassword')?.value || '';
  }

  try{
    const res = await proxyPost('forgot_verify_otp', body, 'Resetting...');

    if (el('otpStatus')) {
      el('otpStatus').textContent = (resetType === 'PIN' ? 'PIN' : 'Password') + ' reset successful. Redirecting to login...';
    }

    showSuccess((resetType === 'PIN' ? 'PIN' : 'Password') + ' reset successful. Please login.');
    showToast((resetType === 'PIN' ? 'PIN' : 'Password') + ' reset successful', 'ok');

    resetOtpState();

    setTimeout(() => {
      window.location.href = USER_LOGIN_URL;
    }, 1200);
  }catch(error){
    if (el('otpStatus')) {
      el('otpStatus').textContent = error.message || 'Failed to verify OTP.';
    }
    showToast(error.message || 'Failed to verify OTP', 'error');
  }
}

function bindEvents(){
  el('typePasswordBtn')?.addEventListener('click', () => setResetType('PASSWORD'));
  el('typePinBtn')?.addEventListener('click', () => setResetType('PIN'));

  el('sendForgotOtpBtn')?.addEventListener('click', sendForgotOtp);
  el('verifyForgotOtpBtn')?.addEventListener('click', verifyForgotOtp);
  el('resendForgotOtpBtn')?.addEventListener('click', resendForgotOtp);

  el('cancelForgotOtpBtn')?.addEventListener('click', () => {
    closeOtpModal();
  });

  el('forgotPhone')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') sendForgotOtp();
  });

  ['otpCode','newPassword','confirmPassword','newPin','confirmPin'].forEach(id => {
    el(id)?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') verifyForgotOtp();
    });
  });

  el('forgotOtpModal')?.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'forgotOtpModal') {
      closeOtpModal();
    }
  });
}

setResetType('PASSWORD');
bindEvents();
