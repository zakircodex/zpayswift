(() => {
  'use strict';

  const USER_PROXY_URL = window.USER_PROXY_URL || '/api/user/proxy.php';
  const USER_LOGIN_URL = window.USER_LOGIN_URL || '/user/';
  const el = (id) => document.getElementById(id);
  const steps = ['phone', 'personal', 'identity', 'password', 'pin', 'review', 'otp'];
  const activeRequests = new Set();
  const state = {
    step: 'phone',
    busyCount: 0,
    requestInFlight: false,
    feedbackAfterClose: '',
    ipCountry: '',
    countryReady: false,
    phoneVerifiedKey: '',
    personalVerifiedKey: '',
    identityVerifiedKey: '',
    passwordVerified: false,
    pinVerified: false,
    registrationLocation: emptyLocation(),
    registerOtp: emptyOtp()
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

  function emptyOtp() {
    return { preAuthToken: '', otpRequestId: '', maskedPhone: '', expiresAt: 0, timer: 0 };
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
    el('registerFeedbackMessage').textContent = String(message || 'Something went wrong. Please try again.');
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
    window.setTimeout(() => el('closeRegisterReviewBtn')?.focus(), 0);
  }

  function setBusy(on, text = 'Loading...') {
    state.busyCount = on ? state.busyCount + 1 : Math.max(0, state.busyCount - 1);
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
      const responseText = await response.text();
      let json;
      try {
        json = JSON.parse(responseText);
      } catch (_) {
        throw requestError('INVALID_RESPONSE', 'A valid response was not received. Please try again.');
      }
      if (!response.ok || json?.ok !== true) {
        const error = requestError(String(json?.code || 'REQUEST_FAILED'), String(json?.message || 'Request failed'));
        error.data = json?.data || {};
        throw error;
      }
      return json.data || {};
    } finally {
      activeRequests.delete(controller);
      setBusy(false);
    }
  }

  function requestError(code, message) {
    const error = new Error(message);
    error.code = code;
    return error;
  }

  function safeErrorMessage(error, fallback) {
    const code = String(error?.code || '').toUpperCase();
    const messages = {
      DUPLICATE_PHONE: 'This phone number is already registered.',
      PHONE_ALREADY_REGISTERED: 'This phone number is already registered.',
      DUPLICATE_EMAIL: 'This email is already registered.',
      EMAIL_ALREADY_REGISTERED: 'This email is already registered.',
      IDENTITY_ALREADY_USED: 'This NID or Passport is already used by another account.',
      DOCUMENT_ALREADY_USED: 'This NID or Passport is already used by another account.',
      IDENTITY_REQUIRED: 'NID or Passport number is required.',
      TERMS_REQUIRED: 'You must accept the Terms & Conditions and Privacy Policy.',
      LOCATION_REQUIRED: 'Location verification is required.',
      GPS_REQUIRED: 'Location verification is required.',
      GPS_ACCURACY_REQUIRED: 'A more accurate GPS location is required.',
      OTP_EXPIRED: 'OTP is incorrect or expired.',
      OTP_INVALID: 'OTP is incorrect or expired.',
      OTP_ALREADY_USED: 'This OTP has already been used.',
      REGISTER_SESSION_EXPIRED: 'Registration session expired. Please start again.'
    };
    if (messages[code]) return messages[code];
    if (code === 'VALIDATION_ERROR' && error?.message) return String(error.message);
    return fallback;
  }

  function countryDetails(country) {
    return country === 'BD'
      ? { name: 'Bangladesh', dial: '+880' }
      : { name: 'Malaysia', dial: '+60' };
  }

  function pricingName(country) {
    return country === 'BD' ? 'Bangladesh' : country === 'MY' ? 'Malaysia' : 'Not verified';
  }

  function explicitPhoneCountry(phone) {
    const digits = String(phone || '').replace(/\D+/g, '');
    if (/^8801[3-9]\d{8}$/.test(digits)) return 'BD';
    if (/^60(?:11\d{8}|1[02-9]\d{7})$/.test(digits)) return 'MY';
    return '';
  }

  function validPhone(country, phone) {
    const digits = String(phone || '').replace(/\D+/g, '');
    return country === 'MY'
      ? /^(?:60(?:11\d{8}|1[02-9]\d{7})|0(?:11\d{8}|1[02-9]\d{7})|(?:11\d{8}|1[02-9]\d{7}))$/.test(digits)
      : /^(?:8801[3-9]\d{8}|01[3-9]\d{8}|1[3-9]\d{8})$/.test(digits);
  }

  function syncPhoneCountryFromInput() {
    const detected = explicitPhoneCountry(el('regPhone').value);
    if (!detected || detected === el('regPhoneCountry').value) return;
    el('regPhoneCountry').value = detected;
    invalidateLocation('Phone country changed. Verify location again.');
    updateCountryUi();
  }

  function phoneKey() {
    return [el('regPhoneCountry').value, el('regPhone').value.trim()].join('|');
  }

  function personalKey() {
    return [el('regName').value.trim(), el('regEmail').value.trim().toLowerCase()].join('|');
  }

  function identityKey() {
    return [el('regIdentityType').value, el('regIdentityNumber').value.trim().toUpperCase()].join('|');
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

  function validatePhone() {
    if (!state.countryReady) return 'Phone country could not be detected. Please reload the page.';
    const data = getFormData();
    if (!data.phone_country || !validPhone(data.phone_country, data.phone)) return 'Please enter a valid phone number.';
    return '';
  }

  function validatePersonal() {
    const data = getFormData();
    if (!data.name || data.name.length > 100) return 'Please enter your full name.';
    if (!/^\S+@\S+\.\S+$/.test(data.email)) return 'Please enter a valid email address.';
    return '';
  }

  function validatePassword() {
    const data = getFormData();
    if (data.password.length < 6) return 'Password must be at least 6 characters.';
    if (data.password !== data.confirm_password) return 'Password confirmation does not match.';
    return '';
  }

  function validatePin() {
    const data = getFormData();
    if (!/^\d{4,8}$/.test(data.pin)) return 'PIN must be 4 to 8 digits.';
    if (data.pin !== data.confirm_pin) return 'PIN confirmation does not match.';
    return '';
  }

  function validateIdentity() {
    const data = getFormData();
    if (!['NID', 'PASSPORT'].includes(data.identity_type)) return 'Select a valid identity type.';
    if (!data.identity_number) return data.identity_type === 'PASSPORT' ? 'Passport number is required.' : 'NID number is required.';
    return '';
  }

  function stepCopy(step) {
    const content = {
      phone: ['Create Account', 'Enter your phone number to get started.'],
      personal: ['Personal Information', 'Tell us a little about yourself.'],
      identity: ['Verify Identity', 'Choose the identity document you want to register.'],
      password: ['Create Password', 'Create a secure password for your account.'],
      pin: ['Create Transaction PIN', 'Set your PIN for secure transactions.'],
      review: ['Review Your Account', 'Check your information before creating your account.'],
      otp: ['Verify OTP', 'Enter the verification code sent to your phone.']
    };
    return content[step] || content.phone;
  }

  function actionForInput(input) {
    const actions = {
      regPhone: el('registerPhoneContinue'),
      regName: el('registerPersonalContinue'),
      regEmail: el('registerPersonalContinue'),
      regIdentityNumber: el('registerIdentityContinue'),
      regPassword: el('registerPasswordContinue'),
      regConfirmPassword: el('registerPasswordContinue'),
      regPin: el('registerPinContinue'),
      regConfirmPin: el('registerPinContinue'),
      otpCode: el('verifyRegisterOtpBtn')
    };
    return input ? actions[input.id] || null : null;
  }

  function updateKeyboardViewport() {
    const viewport = window.visualViewport;
    const visibleHeight = viewport ? viewport.height : window.innerHeight;
    const visibleBottom = viewport ? viewport.offsetTop + viewport.height : window.innerHeight;
    const keyboardInset = Math.max(0, window.innerHeight - visibleBottom);
    document.documentElement.style.setProperty('--register-keyboard-inset', `${Math.round(keyboardInset)}px`);
    document.body.classList.toggle('register-keyboard-open', keyboardInset > 80 || visibleHeight < 560);
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
    const page = el('registerPageRoot');
    if (!page) return;
    if (targetBottom > visibleBottom) page.scrollBy({ top: targetBottom - visibleBottom + 12, behavior: 'smooth' });
    else if (targetTop < visibleTop) page.scrollBy({ top: targetTop - visibleTop - 12, behavior: 'smooth' });
  }

  function focusForStep(step) {
    const ids = { phone: 'regPhone', personal: 'regName', identity: 'regIdentityNumber', password: 'regPassword', pin: 'regPin', review: 'verifyLocationBtn', otp: 'otpCode' };
    const node = el(ids[step]);
    if (!node) return;
    window.setTimeout(() => {
      node.focus({ preventScroll: true });
      if (node instanceof HTMLInputElement) ensureControlVisible(node);
    }, 80);
  }

  function showStep(step, historyMode = '') {
    const next = steps.includes(step) ? step : 'phone';
    state.step = next;
    el('registerPageRoot').dataset.registerCurrentStep = next;
    document.querySelectorAll('[data-register-step]').forEach((node) => {
      node.hidden = node.dataset.registerStep !== next;
    });
    const copy = stepCopy(next);
    el('registerTitle').textContent = copy[0];
    el('registerStepDescription').textContent = copy[1];
    el('registerBackButton').hidden = next === 'phone';
    el('registerBackButton').setAttribute('aria-label', 'Previous step');
    document.querySelectorAll('.register-progress span').forEach((node, index) => {
      node.classList.toggle('active', index <= steps.indexOf(next));
    });
    el('registerPageRoot').scrollTo({ top: 0, behavior: 'smooth' });
    if (historyMode === 'push') history.pushState({ registerStep: next }, '', window.location.href);
    else if (historyMode === 'replace') history.replaceState({ registerStep: next }, '', window.location.href);
    if (next === 'review') {
      updateReview();
      updateCreateAvailability();
    }
    focusForStep(next);
  }

  async function continuePhone() {
    if (state.requestInFlight) return;
    syncPhoneCountryFromInput();
    const validation = validatePhone();
    if (validation) return showFeedback(validation);
    const data = getFormData();
    state.requestInFlight = true;
    el('registerPhoneContinue').disabled = true;
    try {
      await proxyPost('register_precheck', {
        stage: 'PHONE',
        phone: data.phone,
        phone_country: data.phone_country
      }, 'Checking phone number...');
      state.phoneVerifiedKey = phoneKey();
      showStep('personal', 'push');
    } catch (error) {
      showFeedback(safeErrorMessage(error, 'Phone number could not be verified. Please try again.'));
    } finally {
      state.requestInFlight = false;
      el('registerPhoneContinue').disabled = false;
    }
  }

  async function continuePersonal() {
    if (state.requestInFlight) return;
    if (state.phoneVerifiedKey !== phoneKey()) return showFeedback('Please verify your phone number again.');
    const validation = validatePersonal();
    if (validation) return showFeedback(validation);
    const data = getFormData();
    state.requestInFlight = true;
    el('registerPersonalContinue').disabled = true;
    try {
      await proxyPost('register_precheck', {
        stage: 'PERSONAL',
        name: data.name,
        email: data.email
      }, 'Checking personal information...');
      state.personalVerifiedKey = personalKey();
      showStep('identity', 'push');
    } catch (error) {
      showFeedback(safeErrorMessage(error, 'Account details could not be verified. Please try again.'));
    } finally {
      state.requestInFlight = false;
      el('registerPersonalContinue').disabled = false;
    }
  }

  async function continueIdentity() {
    if (state.requestInFlight) return;
    if (state.personalVerifiedKey !== personalKey()) return showFeedback('Please verify your personal information again.');
    const validation = validateIdentity();
    if (validation) return showFeedback(validation);
    const data = getFormData();
    state.requestInFlight = true;
    el('registerIdentityContinue').disabled = true;
    try {
      await proxyPost('register_precheck', {
        stage: 'IDENTITY',
        identity_type: data.identity_type,
        identity_number: data.identity_number
      }, 'Checking identity details...');
      state.identityVerifiedKey = identityKey();
      showStep('password', 'push');
    } catch (error) {
      showFeedback(safeErrorMessage(error, 'Identity details could not be verified. Please try again.'));
    } finally {
      state.requestInFlight = false;
      el('registerIdentityContinue').disabled = false;
    }
  }

  async function continuePassword() {
    if (state.requestInFlight) return;
    if (state.identityVerifiedKey !== identityKey()) return showFeedback('Please verify your identity again.');
    const validation = validatePassword();
    if (validation) return showFeedback(validation);
    const data = getFormData();
    state.requestInFlight = true;
    el('registerPasswordContinue').disabled = true;
    try {
      await proxyPost('register_precheck', {
        stage: 'PASSWORD',
        password: data.password,
        confirm_password: data.confirm_password
      }, 'Checking password...');
      state.passwordVerified = true;
      showStep('pin', 'push');
    } catch (error) {
      showFeedback(safeErrorMessage(error, 'Password could not be verified. Please try again.'));
    } finally {
      state.requestInFlight = false;
      el('registerPasswordContinue').disabled = false;
    }
  }

  async function continuePin() {
    if (state.requestInFlight) return;
    if (!state.passwordVerified) return showFeedback('Please verify your password again.');
    const passwordValidation = validatePassword();
    if (passwordValidation) return showFeedback(passwordValidation);
    const validation = validatePin();
    if (validation) return showFeedback(validation);
    const data = getFormData();
    state.requestInFlight = true;
    el('registerPinContinue').disabled = true;
    try {
      await proxyPost('register_precheck', {
        stage: 'PIN',
        pin: data.pin,
        confirm_pin: data.confirm_pin
      }, 'Checking transaction PIN...');
      state.pinVerified = true;
      updateReview();
      showStep('review', 'push');
    } catch (error) {
      showFeedback(safeErrorMessage(error, 'PIN could not be verified. Please try again.'));
    } finally {
      state.requestInFlight = false;
      el('registerPinContinue').disabled = false;
    }
  }

  function browserPosition() {
    return new Promise((resolve, reject) => {
      if (!navigator.geolocation) return reject(new Error('Location verification is required.'));
      navigator.geolocation.getCurrentPosition(
        resolve,
        () => reject(new Error('Location permission was denied or unavailable.')),
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
      );
    });
  }

  function updateCountryUi() {
    const phoneCountry = el('regPhoneCountry').value.toUpperCase();
    if (!['BD', 'MY'].includes(phoneCountry)) {
      el('regCountryDisplay').textContent = 'Country: Unavailable';
      el('regPricingCountryDisplay').textContent = 'Not verified';
      el('regCurrencyDisplay').textContent = '-';
      el('regCountryHint').textContent = 'Phone country must be detected before location and pricing verification.';
      return;
    }
    const country = countryDetails(phoneCountry || 'MY');
    el('regCountryDisplay').textContent = `Country: ${country.name} (${country.dial})`;
    const pricingCountry = state.registrationLocation.pricingCountry;
    el('regPricingCountryDisplay').textContent = pricingName(pricingCountry);
    el('regCurrencyDisplay').textContent = state.registrationLocation.currency || '-';
    el('regCountryHint').textContent = pricingCountry
      ? `Phone country: ${country.name}. Pricing is secured from GPS/IP verification.`
      : 'Phone country is used for OTP. GPS and security checks determine pricing.';
  }

  function invalidateLocation(message = 'Use your current GPS location to continue.') {
    state.registrationLocation = emptyLocation();
    state.registrationLocation.ipCountry = state.ipCountry;
    el('regLocationTitle').textContent = 'Location permission required';
    el('regLocationStatus').textContent = message;
    el('verifyLocationBtn').textContent = 'Verify Location';
    updateCountryUi();
    updateReview();
    updateCreateAvailability();
  }

  async function verifyRegistrationLocation() {
    if (state.requestInFlight) return;
    if (state.phoneVerifiedKey !== phoneKey()) return showFeedback('Please verify your phone number again.');
    if (state.personalVerifiedKey !== personalKey()) return showFeedback('Please verify your personal information again.');
    if (state.identityVerifiedKey !== identityKey()) return showFeedback('Please verify your identity again.');
    if (!state.passwordVerified || !state.pinVerified) return showFeedback('Please verify your password and PIN again.');
    const passwordValidation = validatePassword();
    if (passwordValidation) return showFeedback(passwordValidation);
    const pinValidation = validatePin();
    if (pinValidation) return showFeedback(pinValidation);
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
      clearOtpState();
      el('regLocationTitle').textContent = state.registrationLocation.requiresAdminReview ? 'Location verified - review required' : 'Location verified';
      el('regLocationStatus').textContent = 'Pricing and wallet currency were resolved securely.';
      el('verifyLocationBtn').textContent = 'Recheck Location';
      updateCountryUi();
      updateReview();
    } catch (error) {
      invalidateLocation('Location permission was denied or unavailable.');
      showFeedback(safeErrorMessage(error, 'Location verification is required to create an account.'));
    } finally {
      state.requestInFlight = false;
      el('verifyLocationBtn').disabled = false;
      updateCreateAvailability();
    }
  }

  function updateReview() {
    const data = getFormData();
    const phoneCountry = countryDetails(data.phone_country);
    el('reviewName').textContent = data.name;
    el('reviewPhone').textContent = data.phone;
    el('reviewEmail').textContent = data.email;
    el('reviewIdentity').textContent = data.identity_type === 'PASSPORT' ? 'Passport' : 'NID';
    el('reviewPhoneCountry').textContent = phoneCountry.name;
    el('reviewPricingCountry').textContent = pricingName(state.registrationLocation.pricingCountry);
    el('reviewCurrency').textContent = state.registrationLocation.currency || '-';
  }

  function registrationReady() {
    return state.phoneVerifiedKey === phoneKey()
      && state.personalVerifiedKey === personalKey()
      && state.identityVerifiedKey === identityKey()
      && state.passwordVerified
      && state.pinVerified
      && validatePhone() === ''
      && validatePersonal() === ''
      && validateIdentity() === ''
      && validatePassword() === ''
      && validatePin() === ''
      && state.registrationLocation.verified
      && el('regTermsAccepted').checked;
  }

  function updateCreateAvailability() {
    const button = el('sendRegisterOtpBtn');
    if (!button) return;
    button.disabled = state.requestInFlight || !registrationReady();
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

  function clearOtpState() {
    clearOtpTimer();
    state.registerOtp = emptyOtp();
    el('otpCode').value = '';
  }

  async function sendRegisterOtp() {
    if (state.requestInFlight) return;
    if (state.phoneVerifiedKey !== phoneKey()) return showFeedback('Please verify your phone number again.');
    if (state.personalVerifiedKey !== personalKey()) return showFeedback('Please verify your personal information again.');
    if (state.identityVerifiedKey !== identityKey()) return showFeedback('Please verify your identity again.');
    if (!state.passwordVerified || !state.pinVerified) return showFeedback('Please verify your password and PIN again.');
    const validation = validatePhone() || validatePersonal() || validateIdentity() || validatePassword() || validatePin();
    if (validation) return showFeedback(validation);
    if (!state.registrationLocation.verified) return showFeedback('Location verification is required.');
    if (!el('regTermsAccepted').checked) return showFeedback('You must accept the Terms & Conditions and Privacy Policy.');
    if (state.registerOtp.preAuthToken && Date.now() < state.registerOtp.expiresAt) {
      showStep('otp', 'push');
      return;
    }
    const data = getFormData();
    state.requestInFlight = true;
    el('sendRegisterOtpBtn').disabled = true;
    try {
      const response = await proxyPost('register_send_otp', data, 'Sending registration OTP...');
      updateOtpState(response);
      showStep('otp', 'push');
    } catch (error) {
      showFeedback(safeErrorMessage(error, 'Registration OTP could not be sent. Please try again.'));
    } finally {
      state.requestInFlight = false;
      updateCreateAvailability();
      updateOtpCountdown();
    }
  }

  async function resendRegisterOtp() {
    if (state.requestInFlight || Date.now() < state.registerOtp.expiresAt) return;
    if (!state.registerOtp.preAuthToken || !state.registerOtp.otpRequestId) return showFeedback('Registration session expired. Please start again.');
    state.requestInFlight = true;
    try {
      const response = await proxyPost('register_resend_otp', {
        pre_auth_token: state.registerOtp.preAuthToken,
        otp_request_id: state.registerOtp.otpRequestId
      }, 'Resending OTP...');
      updateOtpState(response);
      el('otpStatus').textContent = 'OTP resent successfully.';
    } catch (error) {
      showFeedback(safeErrorMessage(error, 'OTP could not be resent. Please try again.'));
    } finally {
      state.requestInFlight = false;
      updateOtpCountdown();
    }
  }

  async function verifyRegisterOtp() {
    if (state.requestInFlight) return;
    const otp = el('otpCode').value.trim();
    if (!state.registerOtp.preAuthToken || !state.registerOtp.otpRequestId) return showFeedback('Registration session expired. Please start again.');
    if (Date.now() >= state.registerOtp.expiresAt) return showFeedback('OTP is incorrect or expired.');
    if (!/^\d{4,6}$/.test(otp)) return showFeedback('Please enter the OTP sent to your phone.');
    state.requestInFlight = true;
    try {
      const response = await proxyPost('register_confirm', {
        pre_auth_token: state.registerOtp.preAuthToken,
        otp_request_id: state.registerOtp.otpRequestId,
        otp
      }, 'Creating account...');
      clearSensitiveFields();
      clearOtpState();
      const review = Boolean(response.requires_admin_review) || String(response.account_status || '').toUpperCase() === 'REVIEW';
      if (review) showReviewModal();
      else {
        state.feedbackAfterClose = 'login';
        showFeedback('Your Z-Pay Swift account has been created successfully.', 'success', 'Account Created');
      }
    } catch (error) {
      el('otpCode').value = '';
      showFeedback(safeErrorMessage(error, 'OTP is incorrect or expired.'));
    } finally {
      state.requestInFlight = false;
      updateOtpCountdown();
    }
  }

  async function loadCountryDefaults() {
    el('registerPhoneContinue').disabled = true;
    try {
      const data = await proxyPost('country_defaults', { browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '' }, 'Detecting country...');
      const phoneCountry = String(data.phone_country || '').toUpperCase();
      if (!['BD', 'MY'].includes(phoneCountry)) throw new Error('Phone country is unavailable.');
      state.ipCountry = String(data.ip_country || '').toUpperCase();
      state.countryReady = true;
      el('regPhoneCountry').value = phoneCountry;
      el('registerPhoneContinue').disabled = false;
    } catch (error) {
      state.ipCountry = '';
      state.countryReady = false;
      el('regPhoneCountry').value = '';
      showFeedback(error.message || 'Phone country could not be detected. Please reload the page.');
    }
    invalidateLocation();
  }

  function setIdentityType(type) {
    const next = type === 'PASSPORT' ? 'PASSPORT' : 'NID';
    el('regIdentityType').value = next;
    document.querySelectorAll('[data-register-identity]').forEach((button) => {
      const active = button.dataset.registerIdentity === next;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    el('regIdentityLabel').textContent = next === 'PASSPORT' ? 'Passport Number' : 'NID Number';
    el('regIdentityNumber').placeholder = next === 'PASSPORT' ? 'Enter your passport number' : 'Enter your NID number';
    state.identityVerifiedKey = '';
    invalidateLocation('Verify your location after confirming identity.');
    clearOtpState();
  }

  function clearSensitiveFields() {
    ['regPassword', 'regConfirmPassword', 'regPin', 'regConfirmPin', 'regIdentityNumber', 'otpCode'].forEach((id) => { el(id).value = ''; });
  }

  function handleBack() {
    if (modalOpen()) return closeAllModals();
    if (state.busyCount > 0) return;
    const index = steps.indexOf(state.step);
    if (index <= 0) return void (window.location.href = USER_LOGIN_URL);
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
    if (active instanceof HTMLInputElement && active.type !== 'hidden') window.setTimeout(() => ensureControlVisible(active), 30);
  }

  el('registerPhoneContinue').addEventListener('click', continuePhone);
  el('registerPersonalContinue').addEventListener('click', continuePersonal);
  el('registerIdentityContinue').addEventListener('click', continueIdentity);
  el('registerPasswordContinue').addEventListener('click', continuePassword);
  el('registerPinContinue').addEventListener('click', continuePin);
  el('verifyLocationBtn').addEventListener('click', verifyRegistrationLocation);
  el('sendRegisterOtpBtn').addEventListener('click', sendRegisterOtp);
  el('verifyRegisterOtpBtn').addEventListener('click', verifyRegisterOtp);
  el('resendRegisterOtpBtn').addEventListener('click', resendRegisterOtp);
  el('registerFeedbackOk').addEventListener('click', closeFeedback);
  el('closeRegisterReviewBtn').addEventListener('click', () => window.location.replace(USER_LOGIN_URL));
  el('registerFeedbackModal').addEventListener('click', (event) => {
    if (event.target === el('registerFeedbackModal') && state.feedbackAfterClose !== 'login') closeFeedback();
  });
  document.querySelectorAll('[data-register-identity]').forEach((button) => {
    button.addEventListener('click', () => setIdentityType(button.dataset.registerIdentity));
  });

  el('regPhone').addEventListener('input', () => {
    syncPhoneCountryFromInput();
    state.phoneVerifiedKey = '';
    invalidateLocation('Verify your location after confirming the phone number.');
    clearOtpState();
  });
  ['regName', 'regEmail'].forEach((id) => el(id).addEventListener('input', () => { state.personalVerifiedKey = ''; clearOtpState(); updateCreateAvailability(); }));
  el('regIdentityNumber').addEventListener('input', () => { state.identityVerifiedKey = ''; invalidateLocation('Verify your location after confirming identity.'); clearOtpState(); });
  ['regPassword', 'regConfirmPassword'].forEach((id) => el(id).addEventListener('input', () => { state.passwordVerified = false; state.pinVerified = false; clearOtpState(); updateCreateAvailability(); }));
  ['regPin', 'regConfirmPin'].forEach((id) => el(id).addEventListener('input', () => { state.pinVerified = false; clearOtpState(); updateCreateAvailability(); }));
  el('regTermsAccepted').addEventListener('change', updateCreateAvailability);

  el('registerBackButton').addEventListener('click', () => {
    history.back();
  });
  el('otpCode').addEventListener('keydown', (event) => { if (event.key === 'Enter') verifyRegisterOtp(); });
  document.addEventListener('focusin', handleFocus);
  document.addEventListener('focusout', () => window.setTimeout(handleViewportChange, 80));
  window.visualViewport?.addEventListener('resize', handleViewportChange);
  window.visualViewport?.addEventListener('scroll', handleViewportChange);

  window.addEventListener('popstate', (event) => {
    if (modalOpen()) {
      closeAllModals();
      return;
    }
    const requested = event.state?.registerStep;
    if (steps.includes(requested)) {
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
    document.documentElement.style.removeProperty('--register-keyboard-inset');
    document.body.classList.remove('register-keyboard-open');
  });
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    resetBusy();
    closeAllModals();
    clearSensitiveFields();
    clearOtpState();
    state.phoneVerifiedKey = '';
    state.personalVerifiedKey = '';
    state.identityVerifiedKey = '';
    state.passwordVerified = false;
    state.pinVerified = false;
    showStep('phone', 'replace');
    handleViewportChange();
  });

  setIdentityType('NID');
  showStep('phone', 'replace');
  resetBusy();
  updateKeyboardViewport();
  loadCountryDefaults();
})();
