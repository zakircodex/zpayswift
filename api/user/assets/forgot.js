(() => {
  'use strict';

  const USER_PROXY_URL = window.USER_PROXY_URL || '/api/user/proxy.php';
  const USER_LOGIN_URL = window.USER_LOGIN_URL || '/user/';
  const el = (id) => document.getElementById(id);
  const steps = ['phone', 'identity', 'otp', 'credential'];
  const activeRequests = new Set();
  const state = {
    step: 'phone',
    resetType: 'PASSWORD',
    phone: '',
    phoneCountry: 'BD',
    busyCount: 0,
    requestInFlight: false,
    feedbackAfterClose: '',
    forgotOtp: {
      preAuthToken: '',
      otpRequestId: '',
      maskedPhone: '',
      expiresAt: 0,
      timer: 0,
      otp: ''
    }
  };

  function modalOpen() {
    return el('forgotFeedbackModal')?.classList.contains('show') === true;
  }

  function showFeedback(message, type = 'error', title = '') {
    resetBusy();
    const modal = el('forgotFeedbackModal');
    modal.dataset.type = type;
    el('forgotFeedbackTitle').textContent = title || (type === 'success' ? 'Success' : 'Error');
    el('forgotFeedbackMessage').textContent = String(message || 'Something went wrong.');
    el('forgotFeedbackIcon').textContent = type === 'success' ? '\u2713' : '!';
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('forgot-modal-open');
    window.setTimeout(() => el('forgotFeedbackOk')?.focus(), 0);
  }

  function closeFeedback() {
    el('forgotFeedbackModal')?.classList.remove('show');
    el('forgotFeedbackModal')?.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('forgot-modal-open');
    if (state.feedbackAfterClose === 'login') {
      state.feedbackAfterClose = '';
      window.location.replace(USER_LOGIN_URL);
    } else if (state.feedbackAfterClose === 'restart') {
      state.feedbackAfterClose = '';
      resetOtpState();
      clearSensitiveFields();
      showStep('phone', 'replace');
    } else if (state.feedbackAfterClose === 'otp') {
      state.feedbackAfterClose = '';
      state.forgotOtp.otp = '';
      el('otpCode').value = '';
      showStep('otp');
    } else if (state.feedbackAfterClose === 'identity') {
      state.feedbackAfterClose = '';
      showStep('identity');
    }
  }

  function setBusy(on, text = 'Loading...') {
    if (on) state.busyCount += 1;
    else state.busyCount = Math.max(0, state.busyCount - 1);
    const visible = state.busyCount > 0;
    el('forgotLoadingText').textContent = text;
    el('forgotLoadingModal').classList.toggle('show', visible);
    el('forgotLoadingModal').setAttribute('aria-hidden', visible ? 'false' : 'true');
    el('forgotPageRoot').setAttribute('aria-busy', visible ? 'true' : 'false');
    document.body.classList.toggle('forgot-modal-open', visible || modalOpen());
  }

  function resetBusy() {
    state.busyCount = 0;
    el('forgotLoadingModal')?.classList.remove('show');
    el('forgotLoadingModal')?.setAttribute('aria-hidden', 'true');
    el('forgotPageRoot')?.setAttribute('aria-busy', 'false');
    document.body.classList.toggle('forgot-modal-open', modalOpen());
  }

  async function proxyPost(action, body = {}, busyText = 'Processing...') {
    const controller = new AbortController();
    activeRequests.add(controller);
    setBusy(true, busyText);
    try {
      const response = await fetch(`${USER_PROXY_URL}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        credentials: 'same-origin',
        signal: controller.signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body)
      });
      const text = await response.text();
      let json;
      try {
        json = JSON.parse(text);
      } catch (_) {
        throw new Error('A valid response was not received. Please try again.');
      }
      if (!response.ok || json?.ok !== true) {
        const error = new Error(String(json?.message || 'Request failed'));
        error.code = String(json?.code || 'REQUEST_FAILED');
        error.data = json?.data || {};
        throw error;
      }
      return json.data || {};
    } finally {
      activeRequests.delete(controller);
      setBusy(false);
    }
  }

  function validPhone(country, phone) {
    const digits = phone.replace(/\D+/g, '');
    return country === 'MY'
      ? /^(?:011\d{8}|01[02-9]\d{7}|6011\d{8}|601[02-9]\d{7}|11\d{8}|1[02-9]\d{7})$/.test(digits)
      : /^(?:01[3-9]\d{8}|8801[3-9]\d{8}|1[3-9]\d{8})$/.test(digits);
  }

  function setResetType(type) {
    const next = String(type || 'PASSWORD').toUpperCase();
    state.resetType = ['PASSWORD', 'PIN'].includes(next) ? next : 'PASSWORD';
    el('resetType').value = state.resetType;
    el('typePasswordBtn').classList.toggle('active', state.resetType === 'PASSWORD');
    el('typePinBtn').classList.toggle('active', state.resetType === 'PIN');
    el('passwordFields').hidden = state.resetType === 'PIN';
    el('pinFields').hidden = state.resetType !== 'PIN';
    el('verifyForgotOtpBtn').textContent = state.resetType === 'PIN' ? 'Reset PIN' : 'Reset Password';
  }

  function stepDescription(step) {
    const name = state.resetType === 'PIN' ? 'PIN' : 'password';
    const map = {
      phone: 'Step 1: Enter your phone number.',
      identity: 'Step 2: Verify your registered identity.',
      otp: 'Step 3: Enter the OTP sent to your phone.',
      credential: `Step 4: Set your new ${name}.`
    };
    return map[step] || map.phone;
  }

  function focusForStep(step) {
    const ids = { phone: 'forgotPhone', identity: 'forgotIdentityNumber', otp: 'otpCode', credential: state.resetType === 'PIN' ? 'newPin' : 'newPassword' };
    const node = el(ids[step]);
    if (!node) return;
    window.setTimeout(() => {
      node.focus({ preventScroll: true });
      node.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 50);
  }

  function showStep(step, historyMode = '') {
    const next = steps.includes(step) ? step : 'phone';
    state.step = next;
    document.querySelectorAll('[data-forgot-step]').forEach((node) => {
      node.hidden = node.dataset.forgotStep !== next;
    });
    el('forgotStepDescription').textContent = stepDescription(next);
    document.querySelectorAll('.forgot-progress span').forEach((node, index) => {
      node.classList.toggle('active', index <= steps.indexOf(next));
    });
    if (historyMode === 'push') history.pushState({ forgotStep: next }, '', window.location.href);
    else if (historyMode === 'replace') history.replaceState({ forgotStep: next }, '', window.location.href);
    focusForStep(next);
  }

  function continuePhone() {
    const phone = el('forgotPhone').value.trim();
    const country = el('forgotPhoneCountry').value.toUpperCase();
    if (!phone) {
      showFeedback('Please enter your phone number.');
      return;
    }
    if (!validPhone(country, phone)) {
      showFeedback(country === 'MY' ? 'Invalid Malaysia number.' : 'Invalid Bangladesh number.');
      return;
    }
    state.phone = phone;
    state.phoneCountry = country;
    el('forgotAccountPhone').textContent = phone;
    showStep('identity', 'push');
  }

  function clearOtpTimer() {
    if (state.forgotOtp.timer) window.clearInterval(state.forgotOtp.timer);
    state.forgotOtp.timer = 0;
  }

  function formatCountdown(seconds) {
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
  }

  function updateOtpCountdown() {
    const seconds = Math.max(0, Math.ceil((state.forgotOtp.expiresAt - Date.now()) / 1000));
    el('otpExpiresText').textContent = seconds > 0 ? formatCountdown(seconds) : 'Expired';
    el('forgotOtpContinue').disabled = seconds < 1 || state.requestInFlight;
    el('resendForgotOtpBtn').disabled = seconds > 0 || state.requestInFlight;
    if (seconds < 1) {
      clearOtpTimer();
      el('otpStatus').textContent = 'OTP expired. Resend OTP to continue.';
    }
  }

  function updateOtpState(data) {
    state.forgotOtp.preAuthToken = String(data.pre_auth_token || data.reset_token || data.forgot_token || state.forgotOtp.preAuthToken || '');
    state.forgotOtp.otpRequestId = String(data.otp_request_id || data.request_id || state.forgotOtp.otpRequestId || '');
    state.forgotOtp.maskedPhone = String(data.masked_phone || state.forgotOtp.maskedPhone || '');
    const expiresAt = Number(data.expires_at || 0);
    const expiresIn = Math.max(0, Number(data.expires_in_seconds || data.expires_in || 300));
    state.forgotOtp.expiresAt = expiresAt > 0
      ? (expiresAt < 1000000000000 ? expiresAt * 1000 : expiresAt)
      : Date.now() + expiresIn * 1000;
    state.forgotOtp.otp = '';
    el('otpMaskedPhone').textContent = state.forgotOtp.maskedPhone || '-';
    el('otpCode').value = '';
    el('otpStatus').textContent = 'Enter the OTP sent to your phone.';
    clearOtpTimer();
    updateOtpCountdown();
    state.forgotOtp.timer = window.setInterval(updateOtpCountdown, 1000);
  }

  function resetOtpState() {
    clearOtpTimer();
    state.forgotOtp = { preAuthToken: '', otpRequestId: '', maskedPhone: '', expiresAt: 0, timer: 0, otp: '' };
    el('otpCode').value = '';
  }

  async function sendForgotOtp() {
    if (state.requestInFlight) return;
    const identity = el('forgotIdentityNumber').value.trim();
    if (!identity) {
      showFeedback('NID or Passport number is required.');
      return;
    }
    state.requestInFlight = true;
    el('sendForgotOtpBtn').disabled = true;
    try {
      const response = await proxyPost('forgot_send_otp', {
        phone: state.phone,
        phone_country: state.phoneCountry,
        reset_type: state.resetType,
        device_id: 'USER_WEB',
        device_name: 'User Forgot',
        browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ''
      }, 'Sending recovery OTP...');
      updateOtpState(response);
      showStep('otp', 'push');
    } catch (error) {
      showFeedback(error.message || 'Recovery OTP could not be sent.');
    } finally {
      state.requestInFlight = false;
      el('sendForgotOtpBtn').disabled = false;
      updateOtpCountdown();
    }
  }

  function continueOtp() {
    const otp = el('otpCode').value.trim();
    if (!state.forgotOtp.preAuthToken || !state.forgotOtp.otpRequestId) {
      showFeedback('Recovery session expired. Please start again.');
      return;
    }
    if (Date.now() >= state.forgotOtp.expiresAt) {
      showFeedback('OTP expired. Resend OTP to continue.');
      return;
    }
    if (!/^\d{4,6}$/.test(otp)) {
      showFeedback('Please enter the OTP sent to your phone.');
      return;
    }
    state.forgotOtp.otp = otp;
    setResetType(state.resetType);
    showStep('credential', 'push');
  }

  async function resendForgotOtp() {
    if (state.requestInFlight || Date.now() < state.forgotOtp.expiresAt) return;
    if (!state.forgotOtp.preAuthToken || !state.forgotOtp.otpRequestId) {
      showFeedback('Recovery session expired. Please start again.');
      return;
    }
    state.requestInFlight = true;
    try {
      const response = await proxyPost('forgot_resend_otp', {
        pre_auth_token: state.forgotOtp.preAuthToken,
        reset_token: state.forgotOtp.preAuthToken,
        forgot_token: state.forgotOtp.preAuthToken,
        otp_request_id: state.forgotOtp.otpRequestId,
        request_id: state.forgotOtp.otpRequestId
      }, 'Resending OTP...');
      updateOtpState(response);
      el('otpStatus').textContent = 'A new OTP was sent. The previous code is no longer valid.';
    } catch (error) {
      showFeedback(error.message || 'OTP could not be resent.');
    } finally {
      state.requestInFlight = false;
      updateOtpCountdown();
    }
  }

  function validateCredential() {
    if (state.resetType === 'PIN') {
      const pin = el('newPin').value.trim();
      const confirmPin = el('confirmPin').value.trim();
      if (!/^\d{4,8}$/.test(pin)) return 'PIN must be 4 to 8 digits.';
      if (pin !== confirmPin) return 'PIN confirmation does not match.';
      return '';
    }
    const password = el('newPassword').value;
    const confirmPassword = el('confirmPassword').value;
    if (password.length < 6) return 'Password must be at least 6 characters.';
    if (password !== confirmPassword) return 'Password confirmation does not match.';
    return '';
  }

  async function verifyForgotOtp() {
    if (state.requestInFlight) return;
    const errorMessage = validateCredential();
    if (errorMessage) {
      showFeedback(errorMessage);
      return;
    }
    if (Date.now() >= state.forgotOtp.expiresAt) {
      showFeedback('OTP expired. Resend OTP to continue.');
      return;
    }

    const body = {
      pre_auth_token: state.forgotOtp.preAuthToken,
      reset_token: state.forgotOtp.preAuthToken,
      forgot_token: state.forgotOtp.preAuthToken,
      otp_request_id: state.forgotOtp.otpRequestId,
      request_id: state.forgotOtp.otpRequestId,
      otp: state.forgotOtp.otp,
      reset_type: state.resetType,
      identity_type: el('forgotIdentityType').value.toUpperCase(),
      identity_number: el('forgotIdentityNumber').value.trim()
    };
    if (state.resetType === 'PIN') {
      body.new_pin = el('newPin').value.trim();
      body.confirm_pin = el('confirmPin').value.trim();
    } else {
      body.new_password = el('newPassword').value;
      body.confirm_password = el('confirmPassword').value;
    }

    state.requestInFlight = true;
    el('verifyForgotOtpBtn').disabled = true;
    try {
      await proxyPost('forgot_verify_otp', body, `Resetting ${state.resetType === 'PIN' ? 'PIN' : 'password'}...`);
      clearSensitiveFields();
      resetOtpState();
      state.feedbackAfterClose = 'login';
      showFeedback(`${state.resetType === 'PIN' ? 'PIN' : 'Password'} reset successful. Please login.`, 'success', 'Reset Complete');
    } catch (error) {
      clearSensitiveFields(false);
      const code = String(error.code || '').toUpperCase();
      if (code.includes('OTP_EXPIRED') || code.includes('FORGOT_SESSION')) {
        state.feedbackAfterClose = 'restart';
      } else if (code.includes('OTP_')) {
        state.feedbackAfterClose = 'otp';
      } else if (code.includes('IDENTITY_')) {
        state.feedbackAfterClose = 'identity';
      }
      showFeedback(error.message || 'Reset could not be completed.');
    } finally {
      state.requestInFlight = false;
      el('verifyForgotOtpBtn').disabled = false;
    }
  }

  function clearSensitiveFields(includeOtp = true) {
    ['newPassword', 'confirmPassword', 'newPin', 'confirmPin'].forEach((id) => { el(id).value = ''; });
    if (includeOtp) el('otpCode').value = '';
  }

  function updateCountryUi() {
    const country = el('forgotPhoneCountry').value.toUpperCase();
    el('forgotPhone').placeholder = country === 'MY'
      ? '01XXXXXXXX or +60XXXXXXXXX'
      : '01XXXXXXXXX or +8801XXXXXXXXX';
  }

  async function loadCountryDefault() {
    try {
      const data = await proxyPost('country_defaults', {}, 'Detecting country...');
      const country = String(data.phone_country || 'MY').toUpperCase();
      if (['BD', 'MY'].includes(country)) el('forgotPhoneCountry').value = country;
    } catch (_) {
      // Keep the server-compatible fallback country.
    }
    updateCountryUi();
  }

  function handleBack() {
    if (modalOpen()) {
      closeFeedback();
      return;
    }
    if (state.busyCount > 0) return;
    const index = steps.indexOf(state.step);
    if (index <= 0) {
      window.location.href = USER_LOGIN_URL;
      return;
    }
    if (steps.indexOf(state.step) >= steps.indexOf('otp') && index - 1 < steps.indexOf('otp')) resetOtpState();
    if (state.step === 'credential') clearSensitiveFields(false);
    showStep(steps[index - 1]);
  }

  function focusVisible(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) && !(target instanceof HTMLSelectElement)) return;
    window.setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'center' }), 120);
  }

  el('typePasswordBtn').addEventListener('click', () => setResetType('PASSWORD'));
  el('typePinBtn').addEventListener('click', () => setResetType('PIN'));
  el('forgotPhoneContinue').addEventListener('click', continuePhone);
  el('sendForgotOtpBtn').addEventListener('click', sendForgotOtp);
  el('forgotOtpContinue').addEventListener('click', continueOtp);
  el('resendForgotOtpBtn').addEventListener('click', resendForgotOtp);
  el('verifyForgotOtpBtn').addEventListener('click', verifyForgotOtp);
  el('forgotFeedbackOk').addEventListener('click', closeFeedback);
  el('forgotFeedbackModal').addEventListener('click', (event) => {
    if (event.target === el('forgotFeedbackModal')) closeFeedback();
  });
  el('forgotBackButton').addEventListener('click', () => {
    if (state.step === 'phone') window.location.href = USER_LOGIN_URL;
    else history.back();
  });
  el('forgotPhoneCountry').addEventListener('change', updateCountryUi);
  el('forgotPhone').addEventListener('keydown', (event) => { if (event.key === 'Enter') continuePhone(); });
  el('forgotIdentityNumber').addEventListener('keydown', (event) => { if (event.key === 'Enter') sendForgotOtp(); });
  el('otpCode').addEventListener('keydown', (event) => { if (event.key === 'Enter') continueOtp(); });
  ['newPassword', 'confirmPassword', 'newPin', 'confirmPin'].forEach((id) => {
    el(id).addEventListener('keydown', (event) => { if (event.key === 'Enter') verifyForgotOtp(); });
  });
  document.addEventListener('focusin', focusVisible);

  window.addEventListener('popstate', (event) => {
    if (modalOpen()) {
      closeFeedback();
      history.pushState({ forgotStep: state.step }, '', window.location.href);
      return;
    }
    const requested = event.state?.forgotStep;
    if (steps.includes(requested)) {
      if (steps.indexOf(requested) < steps.indexOf('otp') && steps.indexOf(state.step) >= steps.indexOf('otp')) resetOtpState();
      if (state.step === 'credential' && requested !== 'credential') clearSensitiveFields(false);
      showStep(requested);
      return;
    }
    handleBack();
  });

  window.addEventListener('pagehide', () => {
    activeRequests.forEach((controller) => controller.abort());
    activeRequests.clear();
    clearOtpTimer();
    resetBusy();
  });
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    resetBusy();
    closeFeedback();
    clearSensitiveFields();
    if (state.step === 'credential') showStep('otp', 'replace');
  });

  setResetType('PASSWORD');
  showStep('phone', 'replace');
  resetBusy();
  loadCountryDefault();
})();
