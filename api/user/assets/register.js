(() => {
  'use strict';

  const USER_PROXY_URL = window.USER_PROXY_URL || '/api/user/proxy.php';
  const USER_LOGIN_URL = window.USER_LOGIN_URL || '/user/';
  const el = (id) => document.getElementById(id);
  const steps = ['details', 'contact', 'security', 'location', 'otp'];
  const activeRequests = new Set();
  const state = {
    step: 'details',
    busyCount: 0,
    requestInFlight: false,
    feedbackAfterClose: '',
    ipCountry: '',
    registrationLocation: emptyLocation(),
    registerOtp: {
      preAuthToken: '',
      otpRequestId: '',
      maskedPhone: '',
      expiresAt: 0,
      timer: 0
    }
  };

  function emptyLocation() {
    return {
      verified: false,
      gpsLat: null,
      gpsLng: null,
      gpsAccuracy: null,
      gpsCountry: '',
      ipCountry: '',
      pricingCountry: '',
      currency: '',
      accountStatus: '',
      requiresAdminReview: false
    };
  }

  function modalOpen() {
    return document.querySelector('.register-modal.show') !== null;
  }

  function closeAllModals() {
    document.querySelectorAll('.register-modal.show').forEach((node) => {
      node.classList.remove('show');
      node.setAttribute('aria-hidden', 'true');
    });
    document.body.classList.remove('register-modal-open');
  }

  function showFeedback(message, type = 'error', title = '') {
    closeAllModals();
    const modal = el('registerFeedbackModal');
    modal.dataset.type = type;
    el('registerFeedbackTitle').textContent = title || (type === 'success' ? 'Success' : 'Error');
    el('registerFeedbackMessage').textContent = String(message || 'Something went wrong.');
    el('registerFeedbackIcon').textContent = type === 'success' ? '\u2713' : '!';
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('register-modal-open');
    window.setTimeout(() => el('registerFeedbackOk')?.focus(), 0);
  }

  function closeFeedback() {
    closeAllModals();
    if (state.feedbackAfterClose === 'login') {
      state.feedbackAfterClose = '';
      window.location.replace(USER_LOGIN_URL);
    }
  }

  function showReviewModal() {
    closeAllModals();
    const modal = el('registerReviewModal');
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('register-modal-open');
  }

  function setBusy(on, text = 'Loading...') {
    if (on) state.busyCount += 1;
    else state.busyCount = Math.max(0, state.busyCount - 1);
    const visible = state.busyCount > 0;
    el('registerLoadingText').textContent = text;
    el('registerLoadingModal').classList.toggle('show', visible);
    el('registerLoadingModal').setAttribute('aria-hidden', visible ? 'false' : 'true');
    el('registerPageRoot').setAttribute('aria-busy', visible ? 'true' : 'false');
    document.body.classList.toggle('register-modal-open', visible || modalOpen());
  }

  function resetBusy() {
    state.busyCount = 0;
    el('registerLoadingModal')?.classList.remove('show');
    el('registerLoadingModal')?.setAttribute('aria-hidden', 'true');
    el('registerPageRoot')?.setAttribute('aria-busy', 'false');
    document.body.classList.toggle('register-modal-open', modalOpen());
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

  function getFormData() {
    return {
      name: el('regName').value.trim(),
      phone: el('regPhone').value.trim(),
      phone_country: el('regPhoneCountry').value.toUpperCase(),
      email: el('regEmail').value.trim(),
      identity_type: el('regIdentityType').value.toUpperCase(),
      identity_number: el('regIdentityNumber').value.trim(),
      password: el('regPassword').value,
      confirm_password: el('regConfirmPassword').value,
      pin: el('regPin').value.trim(),
      confirm_pin: el('regConfirmPin').value.trim(),
      terms_accepted: el('regTermsAccepted').checked,
      device_id: 'USER_WEB',
      device_name: 'User Register',
      browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
      gps_lat: state.registrationLocation.gpsLat,
      gps_lng: state.registrationLocation.gpsLng,
      gps_accuracy: state.registrationLocation.gpsAccuracy
    };
  }

  function validateStep(step) {
    const data = getFormData();
    if (step === 'details') {
      if (!data.name) return 'Full name is required.';
      if (!data.phone) return 'Phone number is required.';
      if (!validPhone(data.phone_country, data.phone)) {
        return data.phone_country === 'MY' ? 'Invalid Malaysia number.' : 'Invalid Bangladesh number.';
      }
    }
    if (step === 'contact') {
      if (!/^\S+@\S+\.\S+$/.test(data.email)) return 'A valid email is required.';
      if (!['NID', 'PASSPORT'].includes(data.identity_type)) return 'Select a valid identity type.';
      if (!data.identity_number) return 'NID or Passport number is required.';
    }
    if (step === 'security') {
      if (data.password.length < 6) return 'Password must be at least 6 characters.';
      if (data.password !== data.confirm_password) return 'Password confirmation does not match.';
      if (!/^\d{4,8}$/.test(data.pin)) return 'PIN must be 4 to 8 digits.';
      if (data.pin !== data.confirm_pin) return 'PIN confirmation does not match.';
    }
    if (step === 'location') {
      if (!state.registrationLocation.verified) return 'Location permission is required to create an account.';
      if (!data.terms_accepted) return 'You must accept the Terms & Conditions.';
    }
    return '';
  }

  function stepText(step) {
    const map = {
      details: 'Step 1: Enter your account details.',
      contact: 'Step 2: Add contact and identity details.',
      security: 'Step 3: Create your password and PIN.',
      location: 'Step 4: Verify location and pricing country.',
      otp: 'Step 5: Verify your phone number.'
    };
    return map[step] || map.details;
  }

  function focusForStep(step) {
    const ids = { details: 'regName', contact: 'regEmail', security: 'regPassword', location: 'verifyLocationBtn', otp: 'otpCode' };
    const node = el(ids[step]);
    if (!node) return;
    window.setTimeout(() => {
      node.focus({ preventScroll: true });
      node.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }, 50);
  }

  function showStep(step, historyMode = '') {
    const next = steps.includes(step) ? step : 'details';
    state.step = next;
    document.querySelectorAll('[data-register-step]').forEach((node) => {
      node.hidden = node.dataset.registerStep !== next;
    });
    el('registerStepDescription').textContent = stepText(next);
    el('registerBackButton').setAttribute('aria-label', next === 'details' ? 'Back to login' : 'Previous step');
    document.querySelectorAll('.register-progress span').forEach((node, index) => {
      node.classList.toggle('active', index <= steps.indexOf(next));
    });
    if (historyMode === 'push') history.pushState({ registerStep: next }, '', window.location.href);
    else if (historyMode === 'replace') history.replaceState({ registerStep: next }, '', window.location.href);
    focusForStep(next);
  }

  function goForward(next) {
    const error = validateStep(state.step);
    if (error) {
      showFeedback(error);
      return;
    }
    showStep(next, 'push');
  }

  function clearOtpTimer() {
    if (state.registerOtp.timer) window.clearInterval(state.registerOtp.timer);
    state.registerOtp.timer = 0;
  }

  function formatCountdown(seconds) {
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
  }

  function updateOtpCountdown() {
    const seconds = Math.max(0, Math.ceil((state.registerOtp.expiresAt - Date.now()) / 1000));
    el('otpExpiresText').textContent = seconds > 0 ? formatCountdown(seconds) : 'Expired';
    el('verifyRegisterOtpBtn').disabled = seconds < 1 || state.requestInFlight;
    el('resendRegisterOtpBtn').disabled = seconds > 0 || state.requestInFlight;
    if (seconds < 1) {
      clearOtpTimer();
      el('otpStatus').textContent = 'OTP expired. Resend OTP to continue.';
    }
  }

  function updateOtpState(data) {
    state.registerOtp.preAuthToken = String(data.pre_auth_token || state.registerOtp.preAuthToken || '');
    state.registerOtp.otpRequestId = String(data.otp_request_id || state.registerOtp.otpRequestId || '');
    state.registerOtp.maskedPhone = String(data.masked_phone || state.registerOtp.maskedPhone || '');
    const serverExpiresAt = Number(data.expires_at || 0);
    const expiresIn = Math.max(0, Number(data.expires_in_seconds || data.expires_in || 300));
    state.registerOtp.expiresAt = serverExpiresAt > 0
      ? (serverExpiresAt < 1000000000000 ? serverExpiresAt * 1000 : serverExpiresAt)
      : Date.now() + expiresIn * 1000;
    el('otpMaskedPhone').textContent = state.registerOtp.maskedPhone || '-';
    el('otpCode').value = '';
    el('otpStatus').textContent = 'Enter the OTP to create your account.';
    clearOtpTimer();
    updateOtpCountdown();
    state.registerOtp.timer = window.setInterval(updateOtpCountdown, 1000);
  }

  function resetOtpState() {
    clearOtpTimer();
    state.registerOtp = { preAuthToken: '', otpRequestId: '', maskedPhone: '', expiresAt: 0, timer: 0 };
    el('otpCode').value = '';
  }

  async function sendRegisterOtp() {
    if (state.requestInFlight) return;
    const error = validateStep('location');
    if (error) {
      showFeedback(error);
      return;
    }
    const data = getFormData();
    state.requestInFlight = true;
    el('sendRegisterOtpBtn').disabled = true;
    try {
      const response = await proxyPost('register_send_otp', data, 'Sending registration OTP...');
      updateOtpState(response);
      showStep('otp', 'push');
    } catch (errorValue) {
      showFeedback(errorValue.message || 'Registration OTP could not be sent.');
    } finally {
      state.requestInFlight = false;
      el('sendRegisterOtpBtn').disabled = false;
      updateOtpCountdown();
    }
  }

  async function resendRegisterOtp() {
    if (state.requestInFlight || Date.now() < state.registerOtp.expiresAt) return;
    if (!state.registerOtp.preAuthToken || !state.registerOtp.otpRequestId) {
      showFeedback('Registration session expired. Please start again.');
      return;
    }
    state.requestInFlight = true;
    try {
      const response = await proxyPost('register_resend_otp', {
        pre_auth_token: state.registerOtp.preAuthToken,
        otp_request_id: state.registerOtp.otpRequestId
      }, 'Resending OTP...');
      updateOtpState(response);
      el('otpStatus').textContent = 'OTP resent successfully.';
    } catch (error) {
      showFeedback(error.message || 'OTP could not be resent.');
    } finally {
      state.requestInFlight = false;
      updateOtpCountdown();
    }
  }

  async function verifyRegisterOtp() {
    if (state.requestInFlight) return;
    const otp = el('otpCode').value.trim();
    if (!state.registerOtp.preAuthToken || !state.registerOtp.otpRequestId) {
      showFeedback('Registration session expired. Please start again.');
      return;
    }
    if (Date.now() >= state.registerOtp.expiresAt) {
      showFeedback('OTP expired. Resend OTP to continue.');
      return;
    }
    if (!/^\d{4,6}$/.test(otp)) {
      showFeedback('Please enter the OTP sent to your phone.');
      return;
    }
    state.requestInFlight = true;
    try {
      const response = await proxyPost('register_confirm', {
        pre_auth_token: state.registerOtp.preAuthToken,
        otp_request_id: state.registerOtp.otpRequestId,
        otp
      }, 'Creating account...');
      clearSensitiveFields();
      resetOtpState();
      const review = Boolean(response.requires_admin_review)
        || String(response.account_status || '').toUpperCase() === 'REVIEW';
      if (review) {
        showReviewModal();
      } else {
        state.feedbackAfterClose = 'login';
        showFeedback('Account created successfully. You can login now.', 'success', 'Account Created');
      }
    } catch (error) {
      el('otpCode').value = '';
      showFeedback(error.message || 'OTP verification failed.');
    } finally {
      state.requestInFlight = false;
      updateOtpCountdown();
    }
  }

  function browserPosition() {
    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) {
        reject(new Error('Location permission is required to create an account.'));
        return;
      }
      navigator.geolocation.getCurrentPosition(
        resolve,
        () => reject(new Error('Location permission was denied or unavailable.')),
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
      );
    });
  }

  function updateCountryUi() {
    const phoneCountry = el('regPhoneCountry').value.toUpperCase();
    const pricingCountry = state.registrationLocation.pricingCountry;
    el('regPhone').placeholder = phoneCountry === 'MY'
      ? '01XXXXXXXX or +60XXXXXXXXX'
      : '01XXXXXXXXX or +8801XXXXXXXXX';
    el('regPricingCountryDisplay').textContent = pricingCountry === 'MY'
      ? 'Malaysia | MYR'
      : pricingCountry === 'BD'
        ? 'Bangladesh | BDT'
        : 'Location verification required';
    const provider = phoneCountry === 'MY' ? 'SMS360' : 'BulkSMSBD';
    el('regCountryHint').textContent = `Phone OTP: ${provider}. Pricing: ${pricingCountry || 'pending GPS verification'}.`;
  }

  function resetLocationVerification(message = 'Verify your current location before creating the account.') {
    state.registrationLocation = emptyLocation();
    state.registrationLocation.ipCountry = state.ipCountry;
    el('regLocationTitle').textContent = 'Location permission required';
    el('regLocationStatus').textContent = message;
    el('verifyLocationBtn').textContent = 'Verify Location';
    updateCountryUi();
  }

  async function verifyRegistrationLocation() {
    if (state.requestInFlight) return;
    state.requestInFlight = true;
    el('verifyLocationBtn').disabled = true;
    try {
      const position = await browserPosition();
      const latitude = Number(position.coords.latitude);
      const longitude = Number(position.coords.longitude);
      const accuracy = Number(position.coords.accuracy);
      const data = await proxyPost('registration_location_check', {
        phone_country: el('regPhoneCountry').value.toUpperCase(),
        gps_lat: latitude,
        gps_lng: longitude,
        gps_accuracy: accuracy,
        browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ''
      }, 'Verifying location...');

      state.registrationLocation = {
        verified: true,
        gpsLat: latitude,
        gpsLng: longitude,
        gpsAccuracy: accuracy,
        gpsCountry: String(data.gps_country || '').toUpperCase(),
        ipCountry: String(data.ip_country || '').toUpperCase(),
        pricingCountry: String(data.pricing_country || '').toUpperCase(),
        currency: String(data.currency || '').toUpperCase(),
        accountStatus: String(data.account_status || '').toUpperCase(),
        requiresAdminReview: Boolean(data.requires_admin_review)
      };
      el('regLocationTitle').textContent = state.registrationLocation.requiresAdminReview
        ? 'Location verified - review required'
        : 'Location verified';
      el('regLocationStatus').textContent = `GPS: ${state.registrationLocation.gpsCountry || 'Unknown'} | IP: ${state.registrationLocation.ipCountry || 'Unknown'}`;
      el('verifyLocationBtn').textContent = 'Recheck Location';
      updateCountryUi();
    } catch (error) {
      resetLocationVerification('Location permission was denied or unavailable.');
      showFeedback(error.message || 'Location permission is required to create an account.');
    } finally {
      state.requestInFlight = false;
      el('verifyLocationBtn').disabled = false;
    }
  }

  async function loadCountryDefaults() {
    try {
      const data = await proxyPost('country_defaults', {
        browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ''
      }, 'Detecting country...');
      const phoneCountry = String(data.phone_country || 'MY').toUpperCase();
      state.ipCountry = String(data.ip_country || '').toUpperCase();
      if (['BD', 'MY'].includes(phoneCountry)) el('regPhoneCountry').value = phoneCountry;
    } catch (_) {
      state.ipCountry = '';
    }
    resetLocationVerification();
  }

  function clearSensitiveFields() {
    ['regPassword', 'regConfirmPassword', 'regPin', 'regConfirmPin', 'otpCode'].forEach((id) => { el(id).value = ''; });
  }

  function handleBack() {
    if (modalOpen()) {
      closeAllModals();
      return;
    }
    if (state.busyCount > 0) return;
    const index = steps.indexOf(state.step);
    if (index <= 0) {
      window.location.href = USER_LOGIN_URL;
      return;
    }
    if (state.step === 'otp') resetOtpState();
    showStep(steps[index - 1]);
  }

  function focusVisible(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) && !(target instanceof HTMLSelectElement)) return;
    window.setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'center' }), 120);
  }

  document.querySelectorAll('[data-register-next]').forEach((button) => {
    button.addEventListener('click', () => goForward(button.dataset.registerNext));
  });
  el('registerBackButton').addEventListener('click', () => {
    if (state.step === 'details') window.location.href = USER_LOGIN_URL;
    else history.back();
  });
  el('sendRegisterOtpBtn').addEventListener('click', sendRegisterOtp);
  el('verifyLocationBtn').addEventListener('click', verifyRegistrationLocation);
  el('verifyRegisterOtpBtn').addEventListener('click', verifyRegisterOtp);
  el('resendRegisterOtpBtn').addEventListener('click', resendRegisterOtp);
  el('registerFeedbackOk').addEventListener('click', closeFeedback);
  el('closeRegisterReviewBtn').addEventListener('click', () => window.location.replace(USER_LOGIN_URL));
  el('regPhoneCountry').addEventListener('change', () => {
    resetLocationVerification('Phone country changed. Verify location again.');
  });
  el('otpCode').addEventListener('keydown', (event) => { if (event.key === 'Enter') verifyRegisterOtp(); });
  document.addEventListener('focusin', focusVisible);

  window.addEventListener('popstate', (event) => {
    if (modalOpen()) {
      closeAllModals();
      history.pushState({ registerStep: state.step }, '', window.location.href);
      return;
    }
    const requested = event.state?.registerStep;
    if (steps.includes(requested)) {
      if (state.step === 'otp' && requested !== 'otp') resetOtpState();
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
    closeAllModals();
    clearSensitiveFields();
    if (steps.indexOf(state.step) >= steps.indexOf('security')) showStep('security', 'replace');
  });

  showStep('details', 'replace');
  resetBusy();
  loadCountryDefaults();
})();
