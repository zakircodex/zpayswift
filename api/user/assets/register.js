const USER_PROXY_URL = window.USER_PROXY_URL || '/zawtopup/api/user/proxy.php';
const USER_LOGIN_URL = window.USER_LOGIN_URL || '/zawtopup/api/user/dashboard.php';

const state = {
  busyCount: 0,
  registerOtp: {
    preAuthToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300
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
  showNode('registerError', msg || 'Something went wrong');
  showNode('registerSuccess', '');
}

function showSuccess(msg){
  showNode('registerSuccess', msg || 'Success');
  showNode('registerError', '');
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

function getFormData(){
  return {
    name: (el('regName')?.value || '').trim(),
    phone: (el('regPhone')?.value || '').trim(),
    email: (el('regEmail')?.value || '').trim(),
    password: el('regPassword')?.value || '',
    confirm_password: el('regConfirmPassword')?.value || '',
    pin: (el('regPin')?.value || '').trim(),
    confirm_pin: (el('regConfirmPin')?.value || '').trim(),
    device_id: 'USER_WEB',
    device_name: 'User Register'
  };
}

function validateForm(data){
  if (!data.name || !data.phone || !data.email || !data.password || !data.confirm_password || !data.pin || !data.confirm_pin) {
    return 'All fields are required';
  }

  if (!/^\S+@\S+\.\S+$/.test(data.email)) {
    return 'Valid email is required';
  }

  const phoneDigits = data.phone.replace(/\D+/g, '');
  if (phoneDigits.length < 10) {
    return 'Valid phone number is required';
  }

  if (data.password.length < 6) {
    return 'Password must be at least 6 characters';
  }

  if (data.password !== data.confirm_password) {
    return 'Password confirmation does not match';
  }

  if (!/^\d{4,8}$/.test(data.pin)) {
    return 'PIN must be 4 to 8 digits';
  }

  if (data.pin !== data.confirm_pin) {
    return 'PIN confirmation does not match';
  }

  return '';
}

function updateOtpState(data){
  state.registerOtp.preAuthToken = String(data.pre_auth_token || '');
  state.registerOtp.otpRequestId = String(data.otp_request_id || '');
  state.registerOtp.maskedPhone = String(data.masked_phone || '');
  state.registerOtp.expiresInSeconds = Number(data.expires_in_seconds || 300);

  if (el('otpMaskedPhone')) {
    el('otpMaskedPhone').textContent = state.registerOtp.maskedPhone || '-';
  }

  if (el('otpExpiresText')) {
    const sec = state.registerOtp.expiresInSeconds;
    el('otpExpiresText').textContent = sec >= 60 ? Math.ceil(sec / 60) + ' minutes' : sec + ' seconds';
  }

  if (el('otpCode')) {
    el('otpCode').value = '';
  }

  if (el('otpStatus')) {
    el('otpStatus').textContent =
      'OTP sent to ' + (state.registerOtp.maskedPhone || 'your phone') + '. Enter OTP to create account.';
  }
}

function openOtpModal(){
  el('registerOtpModal')?.classList.add('show');
}

function closeOtpModal(){
  el('registerOtpModal')?.classList.remove('show');
}

function resetOtpState(){
  state.registerOtp = {
    preAuthToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresInSeconds: 300
  };

  if (el('otpMaskedPhone')) el('otpMaskedPhone').textContent = '-';
  if (el('otpExpiresText')) el('otpExpiresText').textContent = '5 minutes';
  if (el('otpCode')) el('otpCode').value = '';
  if (el('otpStatus')) el('otpStatus').textContent = 'OTP পাঠানোর পরে এখানে status দেখাবে।';
}

function clearForm(){
  ['regName','regPhone','regEmail','regPassword','regConfirmPassword','regPin','regConfirmPin'].forEach(id => {
    if (el(id)) el(id).value = '';
  });
}

async function sendRegisterOtp(){
  showNode('registerError', '');
  showNode('registerSuccess', '');

  const data = getFormData();
  const err = validateForm(data);

  if (err) {
    showError(err);
    showToast(err, 'error');
    return;
  }

  try{
    const res = await proxyPost('register_send_otp', data, 'Sending OTP...');
    updateOtpState(res);
    openOtpModal();
    showSuccess('OTP sent successfully. Please verify to create your account.');
    showToast('OTP sent successfully', 'ok');
  }catch(error){
    showError(error.message || 'Failed to send OTP');
    showToast(error.message || 'Failed to send OTP', 'error');
  }
}

async function resendRegisterOtp(){
  if (!state.registerOtp.preAuthToken || !state.registerOtp.otpRequestId) {
    if (el('otpStatus')) {
      el('otpStatus').textContent = 'Registration session missing. Please start again.';
    }
    return;
  }

  try{
    const res = await proxyPost('register_resend_otp', {
      pre_auth_token: state.registerOtp.preAuthToken,
      otp_request_id: state.registerOtp.otpRequestId
    }, 'Resending OTP...');

    updateOtpState(res);

    if (el('otpStatus')) {
      el('otpStatus').textContent =
        'OTP resent successfully to ' + (state.registerOtp.maskedPhone || 'your phone') + '.';
    }

    showToast('OTP resent successfully', 'ok');
  }catch(error){
    if (el('otpStatus')) {
      el('otpStatus').textContent = error.message || 'Failed to resend OTP.';
    }
    showToast(error.message || 'Failed to resend OTP', 'error');
  }
}

async function verifyRegisterOtp(){
  const otp = (el('otpCode')?.value || '').trim();

  if (!state.registerOtp.preAuthToken || !state.registerOtp.otpRequestId) {
    if (el('otpStatus')) {
      el('otpStatus').textContent = 'Registration session missing. Please start again.';
    }
    return;
  }

  if (!otp) {
    if (el('otpStatus')) {
      el('otpStatus').textContent = 'Please enter OTP first.';
    }
    return;
  }

  try{
    const res = await proxyPost('register_confirm', {
      pre_auth_token: state.registerOtp.preAuthToken,
      otp_request_id: state.registerOtp.otpRequestId,
      otp
    }, 'Creating account...');

    if (el('otpStatus')) {
      el('otpStatus').textContent = 'Account created successfully. Redirecting to login...';
    }

    showSuccess('Account created successfully. Please login.');
    showToast('Account created successfully', 'ok');

    clearForm();
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
  el('sendRegisterOtpBtn')?.addEventListener('click', sendRegisterOtp);
  el('verifyRegisterOtpBtn')?.addEventListener('click', verifyRegisterOtp);
  el('resendRegisterOtpBtn')?.addEventListener('click', resendRegisterOtp);

  el('cancelRegisterOtpBtn')?.addEventListener('click', () => {
    closeOtpModal();
  });

  el('otpCode')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') verifyRegisterOtp();
  });

  el('registerOtpModal')?.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'registerOtpModal') {
      closeOtpModal();
    }
  });

  ['regName','regPhone','regEmail','regPassword','regConfirmPassword','regPin','regConfirmPin'].forEach(id => {
    el(id)?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        sendRegisterOtp();
      }
    });
  });
}

bindEvents();