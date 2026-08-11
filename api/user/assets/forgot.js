(() => {
  'use strict';

  const USER_PROXY_URL = window.USER_PROXY_URL || '/api/user/proxy.php';
  const USER_LOGIN_URL = window.USER_LOGIN_URL || '/user/';
  const el = (id) => document.getElementById(id);
  const steps = ['phone', 'identity', 'otp', 'credential'];
  const activeRequests = new Set();
  const state = {
    step: 'phone',
    phone: '',
    phoneCountry: '',
    busyCount: 0,
    requestInFlight: false,
    feedbackAfterClose: '',
    resetAuthorizationToken: '',
    recoveryPreAuthToken: '',
    identityType: '',
    forgotOtp: {
      preAuthToken: '',
      otpRequestId: '',
      maskedPhone: '',
      expiresAt: 0,
      timer: 0
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
    el('forgotFeedbackOk').textContent = state.feedbackAfterClose === 'login' ? 'Back to Login' : 'OK';
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('forgot-modal-open');
    window.setTimeout(() => el('forgotFeedbackOk')?.focus(), 0);
  }

  function closeFeedback() {
    const next = state.feedbackAfterClose;
    state.feedbackAfterClose = '';
    el('forgotFeedbackModal')?.classList.remove('show');
    el('forgotFeedbackModal')?.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('forgot-modal-open');
    el('forgotFeedbackOk').textContent = 'OK';

    if (next === 'login') {
      clearRecoveryState();
      window.location.replace(USER_LOGIN_URL);
    } else if (next === 'restart') {
      clearRecoveryState();
      showStep('phone', 'replace');
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

  function stepDescription(step) {
    const descriptions = {
      phone: 'Enter your phone number to recover your account.',
      identity: 'Confirm your registered identity before an OTP is sent.',
      otp: 'Enter the OTP sent to your registered phone.',
      credential: 'Set your new password and transaction PIN.'
    };
    return descriptions[step] || descriptions.phone;
  }

  function actionForInput(input) {
    const actions = {
      forgotPhone: el('forgotPhoneContinue'),
      forgotIdentityNumber: el('forgotIdentityContinue'),
      otpCode: el('forgotOtpContinue'),
      newPassword: el('updateForgotCredentialsBtn'),
      confirmPassword: el('updateForgotCredentialsBtn'),
      newPin: el('updateForgotCredentialsBtn'),
      confirmPin: el('updateForgotCredentialsBtn')
    };
    return input ? actions[input.id] || null : null;
  }

  function updateKeyboardViewport() {
    const viewport = window.visualViewport;
    const visibleHeight = viewport ? viewport.height : window.innerHeight;
    const visibleBottom = viewport ? viewport.offsetTop + viewport.height : window.innerHeight;
    const keyboardInset = Math.max(0, window.innerHeight - visibleBottom);
    document.documentElement.style.setProperty('--forgot-keyboard-inset', `${Math.round(keyboardInset)}px`);
    document.body.classList.toggle('forgot-keyboard-open', keyboardInset > 80 || visibleHeight < 560);
  }

  function ensureControlVisible(input) {
    if (!input || document.activeElement !== input) return;
    updateKeyboardViewport();
    const viewport = window.visualViewport;
    const visibleTop = (viewport?.offsetTop || 0) + 14;
    const visibleBottom = (viewport ? viewport.offsetTop + viewport.height : window.innerHeight) - 18;
    const action = actionForInput(input);
    const inputRect = input.getBoundingClientRect();
    const actionRect = action?.getBoundingClientRect() || inputRect;
    const targetTop = Math.min(inputRect.top, actionRect.top);
    const targetBottom = Math.max(inputRect.bottom, actionRect.bottom);
    const page = el('forgotPageRoot');
    if (!page) return;

    if (targetBottom > visibleBottom) {
      page.scrollBy({ top: targetBottom - visibleBottom + 12, behavior: 'smooth' });
    } else if (targetTop < visibleTop) {
      page.scrollBy({ top: targetTop - visibleTop - 12, behavior: 'smooth' });
    }
  }

  function focusForStep(step) {
    const ids = { phone: 'forgotPhone', identity: 'forgotIdentityNumber', otp: 'otpCode', credential: 'newPassword' };
    const node = el(ids[step]);
    if (!node) return;
    window.setTimeout(() => {
      node.focus({ preventScroll: true });
      ensureControlVisible(node);
    }, 80);
    window.setTimeout(() => ensureControlVisible(node), 280);
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
    state.resetAuthorizationToken = '';
    el('otpMaskedPhone').textContent = state.forgotOtp.maskedPhone || '-';
    el('otpCode').value = '';
    el('otpStatus').textContent = 'Enter the OTP sent to your phone.';
    clearOtpTimer();
    updateOtpCountdown();
    state.forgotOtp.timer = window.setInterval(updateOtpCountdown, 1000);
  }

  function clearSensitiveFields() {
    ['forgotIdentityNumber', 'otpCode', 'newPassword', 'confirmPassword', 'newPin', 'confirmPin'].forEach((id) => {
      el(id).value = '';
    });
  }

  function clearRecoveryState() {
    clearOtpTimer();
    clearSensitiveFields();
    state.phone = '';
    state.recoveryPreAuthToken = '';
    state.identityType = '';
    state.resetAuthorizationToken = '';
    state.forgotOtp = { preAuthToken: '', otpRequestId: '', maskedPhone: '', expiresAt: 0, timer: 0 };
  }

  async function continuePhone() {
    if (state.requestInFlight) return;
    const phone = el('forgotPhone').value.trim();
    const country = el('forgotPhoneCountry').value.toUpperCase();
    if (!['BD', 'MY'].includes(country)) return showFeedback('Phone country could not be detected. Please reload the page.');
    if (!phone) return showFeedback('Please enter your phone number.');
    if (!validPhone(country, phone)) {
      return showFeedback(country === 'MY' ? 'Invalid Malaysia number.' : 'Invalid Bangladesh number.');
    }

    state.requestInFlight = true;
    el('forgotPhoneContinue').disabled = true;
    try {
      const data = await proxyPost('forgot_start', {
        phone,
        phone_country: country,
        browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ''
      }, 'Verifying account...');
      const preAuthToken = String(data.pre_auth_token || data.reset_token || data.forgot_token || '');
      const identityType = String(data.identity_type || '').toUpperCase();
      if (!preAuthToken || !['NID', 'PASSPORT'].includes(identityType)) {
        throw new Error('Identity verification could not be prepared. Please start again.');
      }
      state.phone = phone;
      state.phoneCountry = country;
      state.recoveryPreAuthToken = preAuthToken;
      state.identityType = identityType;
      el('forgotIdentityTypeLabel').textContent = identityType === 'PASSPORT' ? 'Passport' : 'NID';
      el('forgotIdentityInputLabel').textContent = identityType === 'PASSPORT' ? 'Passport Number' : 'NID Number';
      el('forgotIdentityNumber').placeholder = identityType === 'PASSPORT' ? 'Enter registered passport number' : 'Enter registered NID number';
      showStep('identity', 'push');
    } catch (error) {
      showFeedback(error.message || 'Account could not be verified.');
    } finally {
      state.requestInFlight = false;
      el('forgotPhoneContinue').disabled = false;
      updateOtpCountdown();
    }
  }

  async function verifyIdentity() {
    if (state.requestInFlight) return;
    const identityNumber = el('forgotIdentityNumber').value.trim();
    if (!state.recoveryPreAuthToken) {
      state.feedbackAfterClose = 'restart';
      return showFeedback('Recovery session expired. Please start again.');
    }
    if (!identityNumber) {
      return showFeedback(state.identityType === 'PASSPORT' ? 'Please enter your registered passport number.' : 'Please enter your registered NID number.');
    }

    state.requestInFlight = true;
    el('forgotIdentityContinue').disabled = true;
    try {
      await proxyPost('forgot_verify_identity', {
        pre_auth_token: state.recoveryPreAuthToken,
        identity_number: identityNumber
      }, 'Verifying identity...');
      el('forgotIdentityNumber').value = '';

      const otpData = await proxyPost('forgot_send_otp', {
        pre_auth_token: state.recoveryPreAuthToken,
        reset_type: 'PASSWORD_PIN',
        device_id: 'USER_WEB'
      }, 'Sending OTP...');
      updateOtpState(otpData);
      showStep('otp', 'push');
    } catch (error) {
      const code = String(error.code || '').toUpperCase();
      if (code.includes('ATTEMPTS_EXCEEDED') || code.includes('FORGOT_SESSION')) {
        state.feedbackAfterClose = 'restart';
      }
      showFeedback(error.message || 'Identity verification could not be completed.');
    } finally {
      state.requestInFlight = false;
      el('forgotIdentityContinue').disabled = false;
      updateOtpCountdown();
    }
  }

  async function verifyOtp() {
    if (state.requestInFlight) return;
    if (state.resetAuthorizationToken) {
      showStep('credential', 'push');
      return;
    }

    const otp = el('otpCode').value.trim();
    if (!state.forgotOtp.preAuthToken || !state.forgotOtp.otpRequestId) {
      state.feedbackAfterClose = 'restart';
      return showFeedback('Recovery session expired. Please start again.');
    }
    if (Date.now() >= state.forgotOtp.expiresAt) return showFeedback('OTP expired. Resend OTP to continue.');
    if (!/^\d{4,6}$/.test(otp)) return showFeedback('Please enter the OTP sent to your phone.');

    state.requestInFlight = true;
    el('forgotOtpContinue').disabled = true;
    try {
      const data = await proxyPost('forgot_verify_otp', {
        pre_auth_token: state.forgotOtp.preAuthToken,
        otp_request_id: state.forgotOtp.otpRequestId,
        otp,
        reset_type: 'PASSWORD_PIN',
        device_id: 'USER_WEB'
      }, 'Verifying OTP...');
      state.resetAuthorizationToken = String(data.reset_authorization_token || data.reset_token || '');
      if (!state.resetAuthorizationToken) throw new Error('Reset authorization could not be created. Please start again.');
      clearOtpTimer();
      showStep('credential', 'push');
    } catch (error) {
      const code = String(error.code || '').toUpperCase();
      if (code.includes('FORGOT_SESSION')) state.feedbackAfterClose = 'restart';
      showFeedback(error.message || 'OTP could not be verified.');
    } finally {
      state.requestInFlight = false;
      el('forgotOtpContinue').disabled = false;
    }
  }

  async function resendOtp() {
    if (state.requestInFlight || Date.now() < state.forgotOtp.expiresAt) return;
    if (!state.forgotOtp.preAuthToken || !state.forgotOtp.otpRequestId) {
      state.feedbackAfterClose = 'restart';
      return showFeedback('Recovery session expired. Please start again.');
    }

    state.requestInFlight = true;
    try {
      const data = await proxyPost('forgot_resend_otp', {
        pre_auth_token: state.forgotOtp.preAuthToken,
        otp_request_id: state.forgotOtp.otpRequestId,
        device_id: 'USER_WEB'
      }, 'Resending OTP...');
      updateOtpState(data);
      el('otpStatus').textContent = 'A new OTP was sent. The previous code is no longer valid.';
    } catch (error) {
      showFeedback(error.message || 'OTP could not be resent.');
    } finally {
      state.requestInFlight = false;
      updateOtpCountdown();
    }
  }

  function validateCredentials() {
    const password = el('newPassword').value;
    const confirmPassword = el('confirmPassword').value;
    const pin = el('newPin').value.trim();
    const confirmPin = el('confirmPin').value.trim();
    if (password.length < 6) return 'Password must be at least 6 characters.';
    if (password !== confirmPassword) return 'Password confirmation does not match.';
    if (!/^\d{4}$/.test(pin)) return 'PIN must be exactly 4 digits.';
    if (pin !== confirmPin) return 'PIN confirmation does not match.';
    return '';
  }

  async function updateCredentials() {
    if (state.requestInFlight) return;
    const validation = validateCredentials();
    if (validation) return showFeedback(validation);
    if (!state.forgotOtp.preAuthToken || !state.resetAuthorizationToken) {
      state.feedbackAfterClose = 'restart';
      return showFeedback('Reset authorization expired. Please start again.');
    }

    state.requestInFlight = true;
    el('updateForgotCredentialsBtn').disabled = true;
    try {
      await proxyPost('forgot_reset_credentials', {
        pre_auth_token: state.forgotOtp.preAuthToken,
        reset_authorization_token: state.resetAuthorizationToken,
        new_password: el('newPassword').value,
        confirm_password: el('confirmPassword').value,
        new_pin: el('newPin').value.trim(),
        confirm_pin: el('confirmPin').value.trim(),
        device_id: 'USER_WEB'
      }, 'Updating password and PIN...');
      clearSensitiveFields();
      clearOtpTimer();
      state.forgotOtp.preAuthToken = '';
      state.forgotOtp.otpRequestId = '';
      state.resetAuthorizationToken = '';
      state.feedbackAfterClose = 'login';
      showFeedback(
        'Your password and transaction PIN were updated successfully.',
        'success',
        'Password & PIN Updated'
      );
    } catch (error) {
      const code = String(error.code || '').toUpperCase();
      if (code.includes('RESET_TOKEN') || code.includes('FORGOT_SESSION')) state.feedbackAfterClose = 'restart';
      showFeedback(error.message || 'Password and PIN could not be updated.');
    } finally {
      state.requestInFlight = false;
      el('updateForgotCredentialsBtn').disabled = false;
    }
  }

  function updateCountryUi() {
    const country = el('forgotPhoneCountry').value.toUpperCase();
    el('forgotCountryDisplay').textContent = country === 'BD'
      ? 'Bangladesh (+880)'
      : (country === 'MY' ? 'Malaysia (+60)' : 'Unavailable');
  }

  async function loadCountryDefault() {
    el('forgotPhoneContinue').disabled = true;
    try {
      const data = await proxyPost('country_defaults', {}, 'Detecting country...');
      const country = String(data.phone_country || '').toUpperCase();
      if (!['BD', 'MY'].includes(country)) throw new Error('Phone country is unavailable.');
      state.phoneCountry = country;
      el('forgotPhoneCountry').value = country;
      el('forgotPhoneContinue').disabled = false;
    } catch (error) {
      state.phoneCountry = '';
      el('forgotPhoneCountry').value = '';
      showFeedback(error.message || 'Phone country could not be detected. Please reload the page.');
    }
    updateCountryUi();
  }

  function handleBack() {
    if (modalOpen()) return closeFeedback();
    if (state.busyCount > 0) return;
    const index = steps.indexOf(state.step);
    if (index <= 0) {
      clearRecoveryState();
      window.location.href = USER_LOGIN_URL;
      return;
    }
    if (state.step === 'identity') clearRecoveryState();
    if (state.step === 'otp') {
      clearOtpTimer();
      el('otpCode').value = '';
    }
    if (state.step === 'credential') clearSensitiveFields();
    showStep(steps[index - 1]);
  }

  function handleFocus(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || target.type === 'hidden') return;
    window.setTimeout(() => ensureControlVisible(target), 80);
    window.setTimeout(() => ensureControlVisible(target), 280);
  }

  function handleViewportChange() {
    updateKeyboardViewport();
    const active = document.activeElement;
    if (active instanceof HTMLInputElement && active.type !== 'hidden') {
      window.setTimeout(() => ensureControlVisible(active), 30);
    }
  }

  el('forgotPhoneContinue').addEventListener('click', continuePhone);
  el('forgotIdentityContinue').addEventListener('click', verifyIdentity);
  el('forgotOtpContinue').addEventListener('click', verifyOtp);
  el('resendForgotOtpBtn').addEventListener('click', resendOtp);
  el('updateForgotCredentialsBtn').addEventListener('click', updateCredentials);
  el('forgotFeedbackOk').addEventListener('click', closeFeedback);
  el('forgotFeedbackModal').addEventListener('click', (event) => {
    if (event.target === el('forgotFeedbackModal') && state.feedbackAfterClose !== 'login') closeFeedback();
  });
  el('forgotBackButton').addEventListener('click', () => {
    if (modalOpen()) closeFeedback();
    else if (state.step === 'phone') window.location.href = USER_LOGIN_URL;
    else history.back();
  });
  el('forgotPhone').addEventListener('keydown', (event) => { if (event.key === 'Enter') continuePhone(); });
  el('forgotIdentityNumber').addEventListener('keydown', (event) => { if (event.key === 'Enter') verifyIdentity(); });
  el('otpCode').addEventListener('keydown', (event) => { if (event.key === 'Enter') verifyOtp(); });
  ['newPassword', 'confirmPassword', 'newPin', 'confirmPin'].forEach((id) => {
    el(id).addEventListener('keydown', (event) => { if (event.key === 'Enter') updateCredentials(); });
  });
  document.addEventListener('focusin', handleFocus);
  document.addEventListener('focusout', () => window.setTimeout(handleViewportChange, 80));
  window.visualViewport?.addEventListener('resize', handleViewportChange);
  window.visualViewport?.addEventListener('scroll', handleViewportChange);

  window.addEventListener('popstate', (event) => {
    if (modalOpen()) {
      closeFeedback();
      return;
    }
    const requested = event.state?.forgotStep;
    if (steps.includes(requested)) {
      if (requested === 'phone' && state.step !== 'phone') clearRecoveryState();
      if (state.step === 'otp' && requested === 'identity') {
        clearOtpTimer();
        el('otpCode').value = '';
      }
      if (state.step === 'credential' && requested !== 'credential') clearSensitiveFields();
      showStep(requested);
      return;
    }
    handleBack();
  });

  window.addEventListener('pagehide', () => {
    activeRequests.forEach((controller) => controller.abort());
    activeRequests.clear();
    clearOtpTimer();
    clearSensitiveFields();
    resetBusy();
    document.documentElement.style.removeProperty('--forgot-keyboard-inset');
    document.body.classList.remove('forgot-keyboard-open');
  });
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    resetBusy();
    if (modalOpen()) closeFeedback();
    handleViewportChange();
  });

  showStep('phone', 'replace');
  resetBusy();
  updateKeyboardViewport();
  loadCountryDefault();
})();
