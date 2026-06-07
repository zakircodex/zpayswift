const forgotState = {
  busyCount: 0,
  resetToken: '',
  otpRequestId: '',
  maskedPhone: '',
  expiresInSeconds: 300,
  resetType: 'PASSWORD'
};

function normalizeSubadminUrl(url, fallback) {
  const value = String(url || '').trim();
  if (!value) return fallback;
  const marker = '/api/subadmin/';
  const index = value.indexOf(marker);
  if (index !== -1) return value.slice(index + marker.length) || fallback;
  return value;
}

const FORGOT_PROXY_URL = normalizeSubadminUrl(window.SUBADMIN_PROXY_URL, '/api/subadmin/proxy.php');
const FORGOT_STORE_KEY = 'zawtopup_subadmin_forgot_state_v2';

function fId(id) {
  return document.getElementById(id);
}

function saveForgotState() {
  try {
    sessionStorage.setItem(FORGOT_STORE_KEY, JSON.stringify({
      resetToken: forgotState.resetToken,
      otpRequestId: forgotState.otpRequestId,
      maskedPhone: forgotState.maskedPhone,
      expiresInSeconds: forgotState.expiresInSeconds,
      resetType: forgotState.resetType
    }));
  } catch (_) {}
}

function loadForgotState() {
  try {
    const raw = sessionStorage.getItem(FORGOT_STORE_KEY);
    if (!raw) return;

    const data = JSON.parse(raw);
    if (!data || typeof data !== 'object') return;

    forgotState.resetToken = String(data.resetToken || '');
    forgotState.otpRequestId = String(data.otpRequestId || '');
    forgotState.maskedPhone = String(data.maskedPhone || '');
    forgotState.expiresInSeconds = Number(data.expiresInSeconds || 300);
    forgotState.resetType = String(data.resetType || 'PASSWORD').toUpperCase();
  } catch (_) {}
}

function clearForgotState() {
  forgotState.resetToken = '';
  forgotState.otpRequestId = '';
  forgotState.maskedPhone = '';
  forgotState.expiresInSeconds = 300;

  try {
    sessionStorage.removeItem(FORGOT_STORE_KEY);
  } catch (_) {}
}

function forgotSetBusy(on, text = 'Loading...') {
  const wrap = fId('loadingWrap');
  const txt = fId('loadingText');

  if (!wrap || !txt) return;

  if (on) {
    forgotState.busyCount += 1;
    txt.textContent = text;
    wrap.classList.add('show');
    return;
  }

  forgotState.busyCount = Math.max(0, forgotState.busyCount - 1);

  if (forgotState.busyCount === 0) {
    txt.textContent = 'Loading...';
    wrap.classList.remove('show');
  }
}

function forgotShowToast(message, type = 'info') {
  const wrap = fId('toastWrap');
  if (!wrap) return;

  const div = document.createElement('div');
  div.className = 'toast' + (type ? ' ' + type : '');
  div.textContent = String(message || '');
  wrap.appendChild(div);

  setTimeout(() => {
    div.remove();
  }, 3500);
}

function forgotSetError(message = '') {
  const errBox = fId('forgotError');
  const okBox = fId('forgotSuccess');

  if (okBox) {
    okBox.classList.add('hidden');
    okBox.textContent = '';
  }

  if (!errBox) return;

  if (!message) {
    errBox.classList.add('hidden');
    errBox.textContent = '';
    return;
  }

  errBox.classList.remove('hidden');
  errBox.textContent = String(message);
}

function forgotSetSuccess(message = '') {
  const errBox = fId('forgotError');
  const okBox = fId('forgotSuccess');

  if (errBox) {
    errBox.classList.add('hidden');
    errBox.textContent = '';
  }

  if (!okBox) return;

  if (!message) {
    okBox.classList.add('hidden');
    okBox.textContent = '';
    return;
  }

  okBox.classList.remove('hidden');
  okBox.textContent = String(message);
}

async function forgotReadJsonSafe(res) {
  const text = await res.text();

  if (!text || !text.trim()) {
    throw new Error('Empty response from server');
  }

  try {
    return JSON.parse(text);
  } catch (_) {
    throw new Error(text.substring(0, 300));
  }
}

async function forgotProxyPost(action, body = {}, busyText = 'Processing...') {
  forgotSetBusy(true, busyText);

  try {
    const res = await fetch(FORGOT_PROXY_URL + '?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(body || {})
    });

    const json = await forgotReadJsonSafe(res);

    if (!res.ok || !json.ok) {
      const err = new Error(json.message || 'Request failed');
      err.code = json.code || 'ERROR';
      err.data = json.data || {};
      throw err;
    }

    return json.data || {};
  } finally {
    forgotSetBusy(false);
  }
}

function setForgotType(type) {
  const finalType = String(type || 'PASSWORD').toUpperCase() === 'PIN' ? 'PIN' : 'PASSWORD';
  forgotState.resetType = finalType;

  const hidden = fId('forgotResetType');
  const passBtn = fId('forgotTypePasswordBtn');
  const pinBtn = fId('forgotTypePinBtn');
  const typeLabel = fId('forgotResetTypeLabel');
  const modalTitle = fId('forgotModalTitle');
  const modalSub = fId('forgotModalSub');
  const newValueLabel = fId('forgotNewValueLabel');
  const confirmValueLabel = fId('forgotConfirmValueLabel');
  const newValueInput = fId('forgotNewValue');
  const confirmValueInput = fId('forgotConfirmValue');
  const verifyBtn = fId('verifyForgotOtpBtn');

  if (hidden) hidden.value = finalType;

  if (passBtn) passBtn.classList.toggle('active', finalType === 'PASSWORD');
  if (pinBtn) pinBtn.classList.toggle('active', finalType === 'PIN');

  if (typeLabel) typeLabel.textContent = finalType === 'PIN' ? 'PIN' : 'Password';

  if (modalTitle) {
    modalTitle.textContent = finalType === 'PIN'
      ? 'Verify PIN Reset OTP'
      : 'Verify Password Reset OTP';
  }

  if (modalSub) {
    modalSub.textContent = finalType === 'PIN'
      ? 'Enter OTP and set your new PIN'
      : 'Enter OTP and set your new password';
  }

  if (newValueLabel) {
    newValueLabel.textContent = finalType === 'PIN' ? 'New PIN' : 'New Password';
  }

  if (confirmValueLabel) {
    confirmValueLabel.textContent = finalType === 'PIN' ? 'Confirm New PIN' : 'Confirm New Password';
  }

  if (newValueInput) {
    newValueInput.type = 'password';
    newValueInput.placeholder = finalType === 'PIN' ? 'Enter new PIN' : 'Enter new password';
    newValueInput.inputMode = finalType === 'PIN' ? 'numeric' : 'text';
  }

  if (confirmValueInput) {
    confirmValueInput.type = 'password';
    confirmValueInput.placeholder = finalType === 'PIN' ? 'Confirm new PIN' : 'Confirm new password';
    confirmValueInput.inputMode = finalType === 'PIN' ? 'numeric' : 'text';
  }

  if (verifyBtn) {
    verifyBtn.textContent = finalType === 'PIN'
      ? 'Verify & Reset PIN'
      : 'Verify & Reset Password';
  }

  saveForgotState();
}

function openForgotOtpModal() {
  const modal = fId('forgotOtpModalWrap');
  if (modal) modal.classList.add('open');
}

function closeForgotOtpModal(clearStateOnClose = true) {
  const modal = fId('forgotOtpModalWrap');
  if (modal) modal.classList.remove('open');

  const otp = fId('forgotOtpCode');
  const v1 = fId('forgotNewValue');
  const v2 = fId('forgotConfirmValue');
  const status = fId('forgotOtpStatus');

  if (otp) otp.value = '';
  if (v1) v1.value = '';
  if (v2) v2.value = '';
  if (status) status.textContent = 'OTP পাঠানোর পরে এখানে status দেখাবে।';

  if (clearStateOnClose) {
    clearForgotState();
    setForgotType('PASSWORD');
  }
}

function renderForgotModalInfo() {
  const phoneBox = fId('forgotMaskedPhone');
  const typeLabel = fId('forgotResetTypeLabel');

  if (phoneBox) phoneBox.textContent = forgotState.maskedPhone || '-';
  if (typeLabel) typeLabel.textContent = forgotState.resetType === 'PIN' ? 'PIN' : 'Password';
}

function validateForgotValues(resetType, value1, value2) {
  const type = String(resetType || 'PASSWORD').toUpperCase();

  if (!value1 || !value2) {
    return 'সবগুলো ঘর পূরণ করতে হবে';
  }

  if (value1 !== value2) {
    return type === 'PIN' ? 'PIN মিলেনি' : 'Password মিলেনি';
  }

  if (type === 'PIN') {
    if (!/^\d{4,8}$/.test(value1)) {
      return 'PIN অবশ্যই 4 থেকে 8 digit হতে হবে';
    }
  } else {
    if (value1.length < 6) {
      return 'Password কমপক্ষে 6 অক্ষরের হতে হবে';
    }
  }

  return '';
}

async function handleSendForgotOtp() {
  forgotSetError('');
  forgotSetSuccess('');

  const phone = (fId('forgotPhone')?.value || '').trim();
  const resetType = String(
    fId('forgotResetType')?.value || forgotState.resetType || 'PASSWORD'
  ).toUpperCase();

  setForgotType(resetType);

  if (!phone) {
    forgotSetError('Phone is required');
    return;
  }

  try {
    const data = await forgotProxyPost('forgot_send_otp', {
      phone,
      reset_type: resetType
    }, 'Sending OTP...');

    forgotState.resetToken = String(
      data.pre_auth_token || data.reset_token || data.forgot_token || ''
    );
    forgotState.otpRequestId = String(
      data.otp_request_id || data.request_id || ''
    );
    forgotState.maskedPhone = String(
      data.masked_phone || data.phone_mask || ''
    );
    forgotState.expiresInSeconds = Number(data.expires_in_seconds || 300);
    forgotState.resetType = resetType;

    saveForgotState();
    renderForgotModalInfo();

    const statusBox = fId('forgotOtpStatus');
    if (statusBox) {
      statusBox.textContent =
        'OTP sent to ' + (forgotState.maskedPhone || 'your phone') + '. এখন OTP দিয়ে নতুন ' +
        (resetType === 'PIN' ? 'PIN' : 'password') + ' সেট করো।';
    }

    openForgotOtpModal();
    forgotShowToast('OTP sent successfully', 'ok');
  } catch (err) {
    forgotSetError(err.message || 'Failed to send OTP');
    forgotShowToast(err.message || 'Failed to send OTP', 'error');
  }
}

async function handleVerifyForgotOtp() {
  loadForgotState();

  const otp = (fId('forgotOtpCode')?.value || '').trim();
  const newValue = fId('forgotNewValue')?.value || '';
  const confirmValue = fId('forgotConfirmValue')?.value || '';
  const statusBox = fId('forgotOtpStatus');
  const resetType = String(forgotState.resetType || 'PASSWORD').toUpperCase();

  if (!forgotState.resetToken || !forgotState.otpRequestId) {
    if (statusBox) statusBox.textContent = 'OTP session পাওয়া যায়নি। আবার Send OTP চাপো।';
    return;
  }

  if (!otp) {
    if (statusBox) statusBox.textContent = 'Please enter OTP first.';
    return;
  }

  const validationMessage = validateForgotValues(resetType, newValue, confirmValue);
  if (validationMessage) {
    if (statusBox) statusBox.textContent = validationMessage;
    return;
  }

  const body = {
    pre_auth_token: forgotState.resetToken,
    reset_token: forgotState.resetToken,
    forgot_token: forgotState.resetToken,
    otp_request_id: forgotState.otpRequestId,
    request_id: forgotState.otpRequestId,
    otp: otp,
    reset_type: resetType
  };

  if (resetType === 'PIN') {
    body.new_pin = newValue;
    body.confirm_pin = confirmValue;
  } else {
    body.new_password = newValue;
    body.confirm_password = confirmValue;
  }

  try {
    await forgotProxyPost(
      'forgot_verify_otp',
      body,
      resetType === 'PIN' ? 'Resetting PIN...' : 'Resetting password...'
    );

    if (statusBox) {
      statusBox.textContent =
        (resetType === 'PIN' ? 'PIN' : 'Password') + ' reset successful. Redirecting to login...';
    }

    forgotShowToast((resetType === 'PIN' ? 'PIN' : 'Password') + ' reset successful', 'ok');

    clearForgotState();

    setTimeout(() => {
      window.location.href = '/subadmin/login.php';
    }, 900);
  } catch (err) {
    if (statusBox) statusBox.textContent = err.message || 'Failed to verify OTP';
    forgotShowToast(err.message || 'Failed to verify OTP', 'error');
  }
}

async function handleResendForgotOtp() {
  loadForgotState();

  const statusBox = fId('forgotOtpStatus');

  if (!forgotState.resetToken || !forgotState.otpRequestId) {
    if (statusBox) statusBox.textContent = 'OTP session পাওয়া যায়নি। আবার Send OTP চাপো।';
    return;
  }

  try {
    const data = await forgotProxyPost('forgot_resend_otp', {
      pre_auth_token: forgotState.resetToken,
      reset_token: forgotState.resetToken,
      forgot_token: forgotState.resetToken,
      otp_request_id: forgotState.otpRequestId,
      request_id: forgotState.otpRequestId,
      reset_type: forgotState.resetType
    }, 'Resending OTP...');

    forgotState.resetToken = String(
      data.pre_auth_token || data.reset_token || data.forgot_token || forgotState.resetToken || ''
    );
    forgotState.otpRequestId = String(
      data.otp_request_id || data.request_id || forgotState.otpRequestId || ''
    );
    forgotState.maskedPhone = String(
      data.masked_phone || forgotState.maskedPhone || ''
    );
    forgotState.expiresInSeconds = Number(
      data.expires_in_seconds || forgotState.expiresInSeconds || 300
    );

    saveForgotState();
    renderForgotModalInfo();

    if (statusBox) {
      statusBox.textContent = 'OTP resent successfully to ' + (forgotState.maskedPhone || 'your phone') + '.';
    }

    forgotShowToast('OTP resent successfully', 'ok');
  } catch (err) {
    if (statusBox) statusBox.textContent = err.message || 'Failed to resend OTP';
    forgotShowToast(err.message || 'Failed to resend OTP', 'error');
  }
}

function bindForgotEvents() {
  fId('forgotTypePasswordBtn')?.addEventListener('click', () => setForgotType('PASSWORD'));
  fId('forgotTypePinBtn')?.addEventListener('click', () => setForgotType('PIN'));

  fId('sendForgotOtpBtn')?.addEventListener('click', handleSendForgotOtp);
  fId('verifyForgotOtpBtn')?.addEventListener('click', handleVerifyForgotOtp);
  fId('resendForgotOtpBtn')?.addEventListener('click', handleResendForgotOtp);

  fId('cancelForgotOtpBtn')?.addEventListener('click', () => closeForgotOtpModal(true));
  fId('closeForgotOtpModalBtn')?.addEventListener('click', () => closeForgotOtpModal(true));

  fId('forgotOtpCode')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') handleVerifyForgotOtp();
  });

  fId('forgotOtpModalWrap')?.addEventListener('click', (e) => {
    if (e.target && e.target.id === 'forgotOtpModalWrap') {
      closeForgotOtpModal(true);
    }
  });
}

loadForgotState();
setForgotType(forgotState.resetType || 'PASSWORD');
bindForgotEvents();
