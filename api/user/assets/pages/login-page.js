(() => {
  'use strict';

  const proxyUrl = window.USER_PROXY_URL || '/api/user/proxy.php';
  const $ = (id) => document.getElementById(id);
  const stepOrder = ['phone', 'password', 'pin', 'otp'];
  const activeRequests = new Set();
  const state = {
    step: 'phone',
    phone: '',
    phoneCountry: '',
    fullName: '',
    preAuthToken: '',
    trustedLogin: false,
    ignoreTrustedLogin: false,
    pinVerified: false,
    otpRequestId: '',
    maskedPhone: '',
    expiresAt: 0,
    timer: 0,
    busyCount: 0,
    navigationStarted: false,
    countryReady: false,
    trustedLookupInFlight: false,
    phoneInFlight: false,
    passwordInFlight: false,
    pinInFlight: false,
    otpSendInFlight: false,
    verifyInFlight: false,
    resendInFlight: false
  };

  function isFeedbackOpen() {
    return $('loginFeedbackModal')?.classList.contains('show') === true;
  }

  function setBusy(on, label = 'Loading...') {
    const modal = $('loginLoadingModal');
    const root = $('loginPageRoot');
    if (!modal || !root) return;

    state.busyCount = on ? state.busyCount + 1 : Math.max(0, state.busyCount - 1);
    const visible = state.busyCount > 0;
    $('loginLoadingText').textContent = label;
    modal.classList.toggle('show', visible);
    modal.setAttribute('aria-hidden', visible ? 'false' : 'true');
    root.setAttribute('aria-busy', visible ? 'true' : 'false');
    document.body.classList.toggle('login-modal-open', visible || isFeedbackOpen());
  }

  function resetLoginLoading() {
    state.busyCount = 0;
    $('loginLoadingModal')?.classList.remove('show');
    $('loginLoadingModal')?.setAttribute('aria-hidden', 'true');
    $('loginPageRoot')?.setAttribute('aria-busy', 'false');
    document.body.classList.toggle('login-modal-open', isFeedbackOpen());
  }

  function showFeedback(message, type = 'error', title = '') {
    const modal = $('loginFeedbackModal');
    if (!modal) return;
    resetLoginLoading();
    $('loginFeedbackTitle').textContent = title || (type === 'success' ? 'Success' : 'Error');
    $('loginFeedbackMessage').textContent = String(message || 'Something went wrong.');
    $('loginFeedbackIcon').textContent = type === 'success' ? '\u2713' : '!';
    modal.dataset.type = type;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('login-modal-open');
    window.setTimeout(() => $('loginFeedbackOk')?.focus(), 0);
  }

  function closeFeedback() {
    $('loginFeedbackModal')?.classList.remove('show');
    $('loginFeedbackModal')?.setAttribute('aria-hidden', 'true');
    document.body.classList.toggle('login-modal-open', state.busyCount > 0);
  }

  function safeErrorMessage(error, fallback) {
    const code = String(error?.code || '').toUpperCase();
    const messages = {
      VALIDATION_ERROR: 'Please check the information and try again.',
      PHONE_INVALID: state.phoneCountry === 'BD'
        ? 'Please enter a valid Bangladesh phone number.'
        : 'Please enter a valid Malaysia phone number.',
      ACCOUNT_NOT_FOUND: 'No Z-Pay Swift account was found for this phone number.',
      USER_NOT_FOUND: 'No Z-Pay Swift account was found for this phone number.',
      ACCOUNT_REVIEW_REQUIRED: 'Your account is under review. Please wait for admin approval.',
      ACCOUNT_BLOCKED: 'Your account is blocked. Please contact support.',
      ACCOUNT_REJECTED: 'Your account registration was rejected.',
      FORBIDDEN: 'This account is not available for User login.',
      INVALID_CREDENTIALS: 'Incorrect password. Please try again.',
      WRONG_PASSWORD: 'Incorrect password. Please try again.',
      PASSWORD_REQUIRED: 'Password verification is required. Please start again.',
      WRONG_PIN: 'Incorrect PIN. Please try again.',
      PIN_REQUIRED: 'PIN verification is required before OTP.',
      DEVICE_MISMATCH: 'Login verification expired. Please start again.',
      PREAUTH_NOT_FOUND: 'Login verification expired. Please start again.',
      PREAUTH_EXPIRED: 'Login verification expired. Please start again.',
      TRUSTED_DEVICE_INVALID: 'Trusted login expired. Please verify your password again.',
      TRUSTED_DEVICE_EXPIRED: 'Trusted login expired. Please verify your password again.',
      OTP_INVALID: 'Incorrect OTP. Please try again.',
      OTP_MISMATCH: 'This OTP does not match the current login request.',
      OTP_EXPIRED: 'OTP expired. Resend OTP to continue.',
      OTP_ALREADY_USED: 'This OTP has already been used.',
      OTP_ATTEMPTS_EXCEEDED: 'Too many incorrect OTP attempts. Request a new OTP.',
      OTP_VERIFY_CONFLICT: 'OTP verification could not be completed. Please try again.',
      SMS_FAILED: 'OTP could not be sent. Please try again later.',
      OTP_SEND_RATE_LIMITED: 'Too many OTP requests. Please wait before trying again.',
      OTP_RESEND_LIMIT_REACHED: 'OTP resend limit reached. Please start login again later.',
      SESSION_EXPIRED: 'Login session expired. Please start again.',
      NETWORK_ERROR: 'Network error. Please check your internet connection.',
      REQUEST_TIMEOUT: 'Login request timed out. Please try again.',
      INVALID_RESPONSE: 'A valid response was not received. Please try again.'
    };
    if (messages[code]) return messages[code];
    if (code.includes('ACCOUNT_REVIEW')) return messages.ACCOUNT_REVIEW_REQUIRED;
    if (code.includes('ACCOUNT_BLOCKED')) return messages.ACCOUNT_BLOCKED;
    if (code.includes('ACCOUNT_REJECTED')) return messages.ACCOUNT_REJECTED;
    if (code.includes('WRONG_PASSWORD') || code.includes('INVALID_CREDENTIAL')) return messages.WRONG_PASSWORD;
    if (code.includes('WRONG_PIN')) return messages.WRONG_PIN;
    if (code.includes('OTP') && code.includes('EXPIRED')) return messages.OTP_EXPIRED;
    if (code.includes('OTP') && (code.includes('INVALID') || code.includes('INCORRECT'))) return messages.OTP_INVALID;
    if (code.includes('PREAUTH') || code.includes('SESSION_EXPIRED')) return messages.SESSION_EXPIRED;
    return fallback || 'Login could not be completed. Please try again.';
  }

  async function post(action, body, label) {
    if (state.navigationStarted) throw new DOMException('Navigation started', 'AbortError');
    const controller = new AbortController();
    let timedOut = false;
    const timeoutId = window.setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, 25000);
    activeRequests.add(controller);
    setBusy(true, label);

    try {
      const response = await fetch(`${proxyUrl}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        credentials: 'same-origin',
        signal: controller.signal,
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'Cache-Control': 'no-cache'
        },
        body: JSON.stringify(body || {})
      });
      const text = await response.text();
      let json;
      try {
        json = JSON.parse(text);
      } catch (_) {
        const parseError = new Error('Invalid response');
        parseError.code = 'INVALID_RESPONSE';
        throw parseError;
      }
      if (!response.ok || json?.ok !== true) {
        const requestError = new Error('Backend request failed');
        requestError.code = String(json?.code || 'REQUEST_FAILED');
        requestError.status = response.status;
        requestError.data = json?.data || {};
        throw requestError;
      }
      return json.data || {};
    } catch (error) {
      if (timedOut) {
        const timeoutError = new Error('Request timed out');
        timeoutError.code = 'REQUEST_TIMEOUT';
        throw timeoutError;
      }
      if (error?.name === 'AbortError') throw error;
      if (!error?.code) error.code = 'NETWORK_ERROR';
      throw error;
    } finally {
      window.clearTimeout(timeoutId);
      activeRequests.delete(controller);
      setBusy(false);
    }
  }

  function countryLabel(country) {
    return country === 'BD' ? 'Bangladesh (+880)' : 'Malaysia (+60)';
  }

  function validPhone(country, phone) {
    const digits = phone.replace(/\D+/g, '');
    return country === 'MY'
      ? /^(?:011\d{8}|01[02-9]\d{7}|6011\d{8}|601[02-9]\d{7}|11\d{8}|1[02-9]\d{7})$/.test(digits)
      : /^(?:01[3-9]\d{8}|8801[3-9]\d{8}|1[3-9]\d{8})$/.test(digits);
  }

  function clearOtpTimer() {
    if (state.timer) window.clearInterval(state.timer);
    state.timer = 0;
  }

  function formatCountdown(seconds) {
    const minutes = Math.floor(seconds / 60);
    const rest = seconds % 60;
    return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
  }

  function updateOtpCountdown() {
    const seconds = Math.max(0, Math.ceil((state.expiresAt - Date.now()) / 1000));
    $('loginOtpExpiresText').textContent = seconds > 0 ? formatCountdown(seconds) : 'Expired';
    $('verifyLoginOtpBtn').disabled = seconds < 1 || state.verifyInFlight;
    $('resendLoginOtpBtn').disabled = seconds > 0 || state.resendInFlight;
    if (seconds < 1) {
      clearOtpTimer();
      $('loginOtpStatus').textContent = 'OTP expired. Resend OTP to continue.';
    }
  }

  function setOtpData(data) {
    state.preAuthToken = String(data.pre_auth_token || state.preAuthToken || '');
    state.otpRequestId = String(data.otp_request_id || state.otpRequestId || '');
    state.maskedPhone = String(data.masked_phone || state.maskedPhone || '');
    const expiresAt = Number(data.expires_at || 0);
    const expiresIn = Math.max(0, Number(data.expires_in_seconds || data.expires_in || 300));
    state.expiresAt = expiresAt > 0
      ? (expiresAt < 1000000000000 ? expiresAt * 1000 : expiresAt)
      : Date.now() + expiresIn * 1000;
    $('loginOtpMaskedPhone').textContent = state.maskedPhone || '-';
    $('loginOtpCode').value = '';
    $('loginOtpStatus').textContent = 'Enter the OTP to complete login.';
    clearOtpTimer();
    updateOtpCountdown();
    state.timer = window.setInterval(updateOtpCountdown, 1000);
  }

  function stepCopy(step) {
    if (step === 'password') return ['Password', `Enter your password for ${state.phone}.`];
    if (step === 'pin') return [`Welcome back, ${state.fullName || state.phone}`, 'Enter your PIN'];
    if (step === 'otp') return ['OTP Verification', 'Enter the code sent to your registered phone.'];
    return ['Login', 'Enter your phone number to continue.'];
  }

  function actionForInput(input) {
    const actions = {
      loginPhone: $('loginPhoneContinue'),
      loginPassword: $('loginPasswordContinue'),
      loginPin: $('loginPinContinue'),
      loginOtpCode: $('verifyLoginOtpBtn')
    };
    return input ? actions[input.id] || null : null;
  }

  function updateKeyboardViewport() {
    const viewport = window.visualViewport;
    const visibleHeight = viewport ? viewport.height : window.innerHeight;
    const visibleBottom = viewport ? viewport.offsetTop + viewport.height : window.innerHeight;
    const keyboardInset = Math.max(0, window.innerHeight - visibleBottom);
    document.documentElement.style.setProperty('--login-keyboard-inset', `${Math.round(keyboardInset)}px`);
    document.body.classList.toggle('login-keyboard-open', keyboardInset > 80 || visibleHeight < 560);
  }

  function ensureLoginControlVisible(input) {
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
    const wrap = $('loginPageRoot');
    if (!wrap) return;

    if (targetBottom > visibleBottom) {
      wrap.scrollBy({ top: targetBottom - visibleBottom + 12, behavior: 'smooth' });
    } else if (targetTop < visibleTop) {
      wrap.scrollBy({ top: targetTop - visibleTop - 12, behavior: 'smooth' });
    }
  }

  function focusVisible(input) {
    if (!input) return;
    input.focus({ preventScroll: true });
    window.setTimeout(() => ensureLoginControlVisible(input), 80);
    window.setTimeout(() => ensureLoginControlVisible(input), 280);
  }

  function showStep(step, options = {}) {
    const next = stepOrder.includes(step) ? step : 'phone';
    state.step = next;
    document.querySelectorAll('[data-login-step]').forEach((node) => {
      node.hidden = node.dataset.loginStep !== next;
    });
    const [title, subtitle] = stepCopy(next);
    $('loginStepTitle').textContent = title;
    $('loginStepSubtitle').textContent = subtitle;
    $('loginStepBack').hidden = next === 'phone' || (next === 'pin' && state.trustedLogin);
    $('loginUseAnotherAccount').hidden = !(next === 'pin' && state.trustedLogin);

    const focusTarget = {
      phone: $('loginPhone'),
      password: $('loginPassword'),
      pin: $('loginPin'),
      otp: $('loginOtpCode')
    }[next];
    if (options.focus !== false) {
      window.setTimeout(() => focusVisible(focusTarget), 30);
    }

    if (options.history === 'push') {
      history.pushState({ authStep: next }, '', window.location.href);
    } else if (options.history === 'replace') {
      history.replaceState({ authStep: next }, '', window.location.href);
    }
  }

  function resetOtpState(keepPreAuth = false) {
    clearOtpTimer();
    state.otpRequestId = '';
    state.maskedPhone = '';
    state.expiresAt = 0;
    $('loginOtpCode').value = '';
    if (!keepPreAuth) {
      state.preAuthToken = '';
      state.pinVerified = false;
    }
  }

  function resetAfterPassword() {
    $('loginPin').value = '';
    resetOtpState(false);
    state.trustedLogin = false;
  }

  function resetAccountState() {
    $('loginPassword').value = '';
    resetAfterPassword();
    state.phone = '';
    state.fullName = '';
  }

  function browserMeta() {
    return {
      device_id: 'USER_WEB',
      device_name: 'User Dashboard',
      browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ''
    };
  }

  async function continuePhone() {
    if (state.phoneInFlight || state.navigationStarted) return;
    const phone = $('loginPhone').value.trim();
    if (!state.countryReady || !['BD', 'MY'].includes(state.phoneCountry)) {
      showFeedback('Phone country is still loading. Please try again.');
      return;
    }
    if (!phone) {
      showFeedback('Please enter your phone number.');
      return;
    }
    if (!validPhone(state.phoneCountry, phone)) {
      showFeedback(state.phoneCountry === 'MY'
        ? 'Please enter a valid Malaysia phone number.'
        : 'Please enter a valid Bangladesh phone number.');
      return;
    }

    state.phoneInFlight = true;
    $('loginPhoneContinue').disabled = true;
    try {
      const data = await post('login_check_number', {
        phone,
        phone_country: state.phoneCountry,
        ignore_trusted_device: state.ignoreTrustedLogin,
        ...browserMeta()
      }, 'Checking account...');
      state.phone = String(data.phone || data.account || phone);
      state.phoneCountry = String(data.phone_country || state.phoneCountry).toUpperCase();
      state.fullName = String(data.name || data.user?.name || '').trim();
      $('loginCountryDisplay').textContent = `Country: ${countryLabel(state.phoneCountry)}`;
      $('loginPhoneCountry').value = state.phoneCountry;
      resetAfterPassword();
      const trustedToken = String(data.pre_auth_token || '');
      state.trustedLogin = data.trusted_login_available === true && trustedToken !== '';
      if (state.trustedLogin) {
        state.preAuthToken = trustedToken;
        showStep('pin', { history: 'push' });
      } else {
        showStep('password', { history: 'push' });
      }
    } catch (error) {
      if (error?.name !== 'AbortError') showFeedback(safeErrorMessage(error, 'Account could not be verified.'));
    } finally {
      state.phoneInFlight = false;
      if (!state.navigationStarted) $('loginPhoneContinue').disabled = !state.countryReady;
    }
  }

  async function verifyPassword() {
    if (state.passwordInFlight || state.navigationStarted) return;
    const password = $('loginPassword').value;
    if (!password) {
      showFeedback('Please enter your password.');
      return;
    }
    if (password.length < 6) {
      showFeedback('Password must be at least 6 characters.');
      return;
    }

    state.passwordInFlight = true;
    $('loginPasswordContinue').disabled = true;
    try {
      const data = await post('login_verify_password', {
        phone: state.phone,
        phone_country: state.phoneCountry,
        password,
        ...browserMeta()
      }, 'Checking password...');
      const token = String(data.pre_auth_token || '');
      if (!token) throw Object.assign(new Error('Missing pre-auth token'), { code: 'INVALID_RESPONSE' });
      state.preAuthToken = token;
      state.trustedLogin = false;
      state.fullName = String(data.user?.name || data.name || state.fullName || '').trim();
      state.pinVerified = false;
      $('loginPassword').value = '';
      showStep('pin', { history: 'push' });
    } catch (error) {
      $('loginPassword').value = '';
      if (error?.name !== 'AbortError') showFeedback(safeErrorMessage(error, 'Password could not be verified.'));
    } finally {
      state.passwordInFlight = false;
      if (!state.navigationStarted) $('loginPasswordContinue').disabled = false;
    }
  }

  async function sendLoginOtp() {
    if (state.otpSendInFlight || state.navigationStarted) return;
    state.otpSendInFlight = true;
    try {
      const data = await post('login_send_otp', {
        pre_auth_token: state.preAuthToken
      }, 'Sending OTP...');
      if (!data.otp_request_id) throw Object.assign(new Error('Missing OTP request'), { code: 'INVALID_RESPONSE' });
      setOtpData(data);
      $('loginPin').value = '';
      showStep('otp', { history: 'push' });
    } catch (error) {
      if (error?.name !== 'AbortError') showFeedback(safeErrorMessage(error, 'OTP could not be sent.'));
    } finally {
      state.otpSendInFlight = false;
    }
  }

  async function verifyPin() {
    if (state.pinInFlight || state.otpSendInFlight || state.navigationStarted) return;
    const pin = $('loginPin').value.trim();
    if (!/^\d{4}$/.test(pin)) {
      showFeedback('Please enter your 4 digit PIN.');
      return;
    }
    if (!state.preAuthToken) {
      resetAfterPassword();
      showStep('password');
      showFeedback('Login verification expired. Please enter your password again.');
      return;
    }

    if (state.pinVerified) {
      await sendLoginOtp();
      return;
    }

    state.pinInFlight = true;
    $('loginPinContinue').disabled = true;
    try {
      const data = await post('login_verify_pin', {
        pre_auth_token: state.preAuthToken,
        pin,
        ...browserMeta()
      }, 'Checking PIN...');
      if (data.login_complete === true && data.session_active === true) {
        $('loginPin').value = '';
        goToDashboard();
        return;
      }
      state.preAuthToken = String(data.pre_auth_token || state.preAuthToken || '');
      state.pinVerified = true;
      $('loginPin').value = '';
      await sendLoginOtp();
    } catch (error) {
      $('loginPin').value = '';
      const code = String(error?.code || '').toUpperCase();
      if (code === 'TRUSTED_DEVICE_INVALID' || code === 'TRUSTED_DEVICE_EXPIRED') {
        state.ignoreTrustedLogin = true;
        state.trustedLogin = false;
        resetAccountState();
        state.countryReady = false;
        state.phoneCountry = '';
        $('loginCountryDisplay').textContent = 'Country: Detecting...';
        showStep('phone', { history: 'replace', focus: false });
        loadCountryDefault().then(() => focusVisible($('loginPhone')));
      }
      if (error?.name !== 'AbortError') showFeedback(safeErrorMessage(error, 'PIN could not be verified.'));
    } finally {
      state.pinInFlight = false;
      if (!state.navigationStarted) $('loginPinContinue').disabled = false;
    }
  }

  function goToDashboard() {
    if (state.navigationStarted) return;
    state.navigationStarted = true;
    clearLoginNavigationState();
    window.location.replace('/user/dashboard');
  }

  async function verifyOtp() {
    if (state.verifyInFlight || state.navigationStarted) return;
    const otp = $('loginOtpCode').value.trim();
    if (!state.preAuthToken || !state.otpRequestId) {
      showFeedback('Login verification expired. Please start again.');
      return;
    }
    if (Date.now() >= state.expiresAt) {
      showFeedback('OTP expired. Resend OTP to continue.');
      return;
    }
    if (!/^\d{6}$/.test(otp)) {
      showFeedback('Please enter the 6 digit OTP sent to your phone.');
      return;
    }

    state.verifyInFlight = true;
    $('verifyLoginOtpBtn').disabled = true;
    try {
      await post('login_verify_otp', {
        pre_auth_token: state.preAuthToken,
        otp_request_id: state.otpRequestId,
        otp,
        trust_device: true,
        ...browserMeta()
      }, 'Verifying OTP...');
      goToDashboard();
    } catch (error) {
      $('loginOtpCode').value = '';
      if (error?.name !== 'AbortError') showFeedback(safeErrorMessage(error, 'OTP verification failed.'));
    } finally {
      state.verifyInFlight = false;
      if (!state.navigationStarted) updateOtpCountdown();
    }
  }

  async function resendOtp() {
    if (state.resendInFlight || state.navigationStarted || Date.now() < state.expiresAt) return;
    if (!state.preAuthToken || !state.otpRequestId) {
      showFeedback('Login verification expired. Please start again.');
      return;
    }
    state.resendInFlight = true;
    $('resendLoginOtpBtn').disabled = true;
    try {
      const data = await post('login_resend_otp', {
        pre_auth_token: state.preAuthToken,
        otp_request_id: state.otpRequestId
      }, 'Resending OTP...');
      setOtpData(data);
      $('loginOtpStatus').textContent = 'A new OTP was sent. The previous code is no longer valid.';
    } catch (error) {
      if (error?.name !== 'AbortError') showFeedback(safeErrorMessage(error, 'OTP could not be resent.'));
    } finally {
      state.resendInFlight = false;
      updateOtpCountdown();
    }
  }

  async function loadCountryDefault() {
    $('loginPhoneContinue').disabled = true;
    try {
      const data = await post('country_defaults', {}, 'Detecting country...');
      const country = String(data.phone_country || '').toUpperCase();
      if (!['BD', 'MY'].includes(country)) throw Object.assign(new Error('Country unavailable'), { code: 'INVALID_RESPONSE' });
      state.phoneCountry = country;
      state.countryReady = true;
      $('loginPhoneCountry').value = country;
      $('loginCountryDisplay').textContent = `Country: ${countryLabel(country)}`;
      $('loginPhoneContinue').disabled = false;
    } catch (error) {
      state.countryReady = false;
      $('loginCountryDisplay').textContent = 'Country: Unavailable';
      showFeedback(safeErrorMessage(error, 'Phone country could not be detected. Please reload the page.'));
    }
  }

  async function resolveTrustedAccount() {
    if (state.trustedLookupInFlight || state.navigationStarted) return false;
    state.trustedLookupInFlight = true;
    try {
      const data = await post('login_trusted_account', {
        ...browserMeta()
      }, 'Checking trusted device...');
      const token = String(data.pre_auth_token || '');
      if (data.trusted_login_available !== true || token === '') return false;

      state.phone = String(data.phone || data.account || '');
      state.phoneCountry = String(data.phone_country || '').toUpperCase();
      state.fullName = String(data.name || data.user?.name || '').trim();
      state.preAuthToken = token;
      state.trustedLogin = true;
      state.ignoreTrustedLogin = false;
      state.countryReady = ['BD', 'MY'].includes(state.phoneCountry);
      $('loginPhoneCountry').value = state.phoneCountry;
      $('loginCountryDisplay').textContent = state.countryReady
        ? `Country: ${countryLabel(state.phoneCountry)}`
        : 'Country: Detecting...';
      showStep('pin', { history: 'replace' });
      return true;
    } catch (_) {
      return false;
    } finally {
      state.trustedLookupInFlight = false;
    }
  }

  async function bootstrapLogin() {
    showStep('phone', { history: 'replace', focus: false });
    const trusted = await resolveTrustedAccount();
    if (trusted || state.navigationStarted) return;
    await loadCountryDefault();
    focusVisible($('loginPhone'));
  }

  async function useAnotherAccount() {
    if (state.busyCount > 0 || state.navigationStarted) return;
    state.ignoreTrustedLogin = true;
    resetAccountState();
    state.countryReady = false;
    state.phoneCountry = '';
    $('loginPhoneCountry').value = '';
    $('loginCountryDisplay').textContent = 'Country: Detecting...';
    showStep('phone', { history: 'replace', focus: false });
    await loadCountryDefault();
    focusVisible($('loginPhone'));
  }

  function backStep() {
    if (state.step === 'otp') {
      resetOtpState(true);
      $('loginPin').value = '';
      showStep('pin');
      return;
    }
    if (state.step === 'pin') {
      if (state.trustedLogin) {
        resetAccountState();
        showStep('phone');
        return;
      }
      resetAfterPassword();
      showStep('password');
      return;
    }
    if (state.step === 'password') {
      resetAccountState();
      showStep('phone');
      return;
    }
    window.location.href = '/';
  }

  function handleFocus(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || target.type === 'hidden') return;
    window.setTimeout(() => ensureLoginControlVisible(target), 80);
    window.setTimeout(() => ensureLoginControlVisible(target), 280);
  }

  function handleViewportChange() {
    updateKeyboardViewport();
    const active = document.activeElement;
    if (active instanceof HTMLInputElement && active.type !== 'hidden') {
      window.setTimeout(() => ensureLoginControlVisible(active), 30);
    }
  }

  function clearLoginNavigationState() {
    clearOtpTimer();
    resetLoginLoading();
    closeFeedback();
    activeRequests.forEach((controller) => controller.abort());
    activeRequests.clear();
    $('loginPassword').value = '';
    $('loginPin').value = '';
    $('loginOtpCode').value = '';
    document.documentElement.style.removeProperty('--login-keyboard-inset');
    document.body.classList.remove('login-keyboard-open');
  }

  $('loginPhoneContinue').addEventListener('click', continuePhone);
  $('loginPasswordContinue').addEventListener('click', verifyPassword);
  $('loginPinContinue').addEventListener('click', verifyPin);
  $('loginUseAnotherAccount').addEventListener('click', useAnotherAccount);
  $('verifyLoginOtpBtn').addEventListener('click', verifyOtp);
  $('resendLoginOtpBtn').addEventListener('click', resendOtp);
  $('loginStepBack').addEventListener('click', () => history.back());
  $('loginFeedbackOk').addEventListener('click', closeFeedback);
  $('loginFeedbackModal').addEventListener('click', (event) => {
    if (event.target === $('loginFeedbackModal')) closeFeedback();
  });
  $('loginPhone').addEventListener('keydown', (event) => { if (event.key === 'Enter') continuePhone(); });
  $('loginPassword').addEventListener('keydown', (event) => { if (event.key === 'Enter') verifyPassword(); });
  $('loginPin').addEventListener('keydown', (event) => { if (event.key === 'Enter') verifyPin(); });
  $('loginOtpCode').addEventListener('keydown', (event) => { if (event.key === 'Enter') verifyOtp(); });
  document.addEventListener('focusin', handleFocus);
  document.addEventListener('focusout', () => window.setTimeout(handleViewportChange, 80));
  window.visualViewport?.addEventListener('resize', handleViewportChange);
  window.visualViewport?.addEventListener('scroll', handleViewportChange);

  window.addEventListener('popstate', (event) => {
    if (isFeedbackOpen()) {
      closeFeedback();
      history.pushState({ authStep: state.step }, '', window.location.href);
      return;
    }
    if (state.busyCount > 0) {
      history.pushState({ authStep: state.step }, '', window.location.href);
      return;
    }
    const requested = event.state?.authStep;
    if (stepOrder.includes(requested)) {
      if (state.step === 'otp' && requested === 'pin') resetOtpState(true);
      if (state.step === 'pin' && requested === 'password') resetAfterPassword();
      if (state.step === 'pin' && requested === 'phone') resetAccountState();
      if (state.step === 'password' && requested === 'phone') resetAccountState();
      showStep(requested);
      return;
    }
    backStep();
  });

  window.addEventListener('pagehide', clearLoginNavigationState);
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    state.navigationStarted = false;
    state.phoneInFlight = false;
    state.passwordInFlight = false;
    state.pinInFlight = false;
    state.otpSendInFlight = false;
    state.verifyInFlight = false;
    state.resendInFlight = false;
    resetLoginLoading();
    closeFeedback();
    $('loginPhoneContinue').disabled = !state.countryReady;
    $('loginPasswordContinue').disabled = false;
    $('loginPinContinue').disabled = false;
    updateKeyboardViewport();
  });

  resetLoginLoading();
  updateKeyboardViewport();
  bootstrapLogin();
})();
