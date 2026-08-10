(() => {
  'use strict';

  const proxyUrl = window.USER_PROXY_URL || '/api/user/proxy.php';
  const $ = (id) => document.getElementById(id);
  const stepOrder = ['phone', 'password', 'otp'];
  const activeRequests = new Set();
  const state = {
    step: 'phone',
    phone: '',
    phoneCountry: 'BD',
    preAuthToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresAt: 0,
    timer: 0,
    trustDevice: true,
    busyCount: 0,
    navigationStarted: false,
    loginInFlight: false,
    verifyInFlight: false,
    resendInFlight: false
  };

  function setBusy(on, label = 'Loading...') {
    const modal = $('loginLoadingModal');
    const root = $('loginPageRoot');
    if (!modal || !root) return;

    if (on) state.busyCount += 1;
    else state.busyCount = Math.max(0, state.busyCount - 1);

    const visible = state.busyCount > 0;
    $('loginLoadingText').textContent = label;
    modal.classList.toggle('show', visible);
    modal.setAttribute('aria-hidden', visible ? 'false' : 'true');
    root.setAttribute('aria-busy', visible ? 'true' : 'false');
    document.body.classList.toggle('login-modal-open', visible || isFeedbackOpen());
  }

  function resetLoginLoading() {
    state.busyCount = 0;
    const modal = $('loginLoadingModal');
    modal?.classList.remove('show');
    modal?.setAttribute('aria-hidden', 'true');
    $('loginPageRoot')?.setAttribute('aria-busy', 'false');
    document.body.classList.toggle('login-modal-open', isFeedbackOpen());
  }

  function isFeedbackOpen() {
    return $('loginFeedbackModal')?.classList.contains('show') === true;
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
    const modal = $('loginFeedbackModal');
    modal?.classList.remove('show');
    modal?.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('login-modal-open');
  }

  async function post(action, body, label) {
    if (state.navigationStarted) throw new DOMException('Navigation started', 'AbortError');
    const controller = new AbortController();
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
        const parseError = new Error('A valid response was not received. Please try again.');
        parseError.code = 'INVALID_RESPONSE';
        throw parseError;
      }
      if (!response.ok || json?.ok !== true) {
        const error = new Error(String(json?.message || 'Request failed'));
        error.code = String(json?.code || 'REQUEST_FAILED');
        error.status = response.status;
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
    if (step === 'password') {
      return ['Password', `Enter your password for ${state.phone}.`];
    }
    if (step === 'otp') {
      return ['OTP Verification', 'Enter the code sent to your registered phone.'];
    }
    return ['Login', 'Enter your phone number to continue.'];
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
    $('loginStepBack').hidden = next === 'phone';

    if (next === 'password') {
      $('loginAccountPhone').textContent = state.phone;
      window.setTimeout(() => focusVisible($('loginPassword')), 30);
    } else if (next === 'otp') {
      window.setTimeout(() => focusVisible($('loginOtpCode')), 30);
    } else {
      clearOtpTimer();
      window.setTimeout(() => focusVisible($('loginPhone')), 30);
    }

    if (options.history === 'push') {
      history.pushState({ authStep: next }, '', window.location.href);
    } else if (options.history === 'replace') {
      history.replaceState({ authStep: next }, '', window.location.href);
    }
  }

  function resetOtpState() {
    clearOtpTimer();
    state.preAuthToken = '';
    state.otpRequestId = '';
    state.maskedPhone = '';
    state.expiresAt = 0;
    $('loginOtpCode').value = '';
  }

  function backStep() {
    if (isFeedbackOpen()) {
      closeFeedback();
      return;
    }
    if (state.busyCount > 0) return;
    if (state.step === 'otp') {
      resetOtpState();
      showStep('password');
      return;
    }
    if (state.step === 'password') {
      $('loginPassword').value = '';
      showStep('phone');
      return;
    }
    window.location.href = '/';
  }

  function continuePhone() {
    const phone = $('loginPhone').value.trim();
    const country = $('loginPhoneCountry').value.toUpperCase();
    if (!phone) {
      showFeedback('Please enter your phone number.');
      return;
    }
    if (!validPhone(country, phone)) {
      showFeedback(country === 'MY' ? 'Please enter a valid Malaysia number.' : 'Please enter a valid Bangladesh number.');
      return;
    }
    state.phone = phone;
    state.phoneCountry = country;
    showStep('password', { history: 'push' });
  }

  function goToDashboard() {
    if (state.navigationStarted) return;
    state.navigationStarted = true;
    clearLoginNavigationState();
    window.location.replace('/user/dashboard');
  }

  async function login() {
    if (state.loginInFlight || state.navigationStarted) return;
    const password = $('loginPassword').value;
    if (!password) {
      showFeedback('Please enter your password.');
      return;
    }
    if (password.length < 6) {
      showFeedback('Password must be at least 6 characters.');
      return;
    }

    state.loginInFlight = true;
    $('loginPasswordContinue').disabled = true;
    state.trustDevice = $('rememberTrustedDevice').checked;
    try {
      const data = await post('login', {
        phone: state.phone,
        phone_country: state.phoneCountry,
        password,
        trust_device: state.trustDevice,
        device_id: 'USER_WEB',
        device_name: 'User Dashboard',
        browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ''
      }, 'Checking login...');

      if (data.require_otp) {
        setOtpData(data);
        showStep('otp', { history: 'push' });
      } else {
        goToDashboard();
      }
    } catch (error) {
      if (!state.navigationStarted && error?.name !== 'AbortError') {
        showFeedback(error.message || 'Login failed.');
      }
    } finally {
      state.loginInFlight = false;
      if (!state.navigationStarted) $('loginPasswordContinue').disabled = false;
    }
  }

  async function verifyOtp() {
    if (state.verifyInFlight || state.navigationStarted) return;
    const otp = $('loginOtpCode').value.trim();
    if (!state.preAuthToken || !state.otpRequestId) {
      showFeedback('Login verification expired. Please start again.');
      showStep('phone');
      return;
    }
    if (Date.now() >= state.expiresAt) {
      showFeedback('OTP expired. Resend OTP to continue.');
      return;
    }
    if (!/^\d{4,6}$/.test(otp)) {
      showFeedback('Please enter the OTP sent to your phone.');
      return;
    }

    state.verifyInFlight = true;
    $('verifyLoginOtpBtn').disabled = true;
    try {
      await post('login_verify_otp', {
        pre_auth_token: state.preAuthToken,
        otp_request_id: state.otpRequestId,
        otp,
        trust_device: state.trustDevice,
        device_id: 'USER_WEB',
        device_name: 'User Dashboard'
      }, 'Verifying OTP...');
      goToDashboard();
    } catch (error) {
      if (!state.navigationStarted && error?.name !== 'AbortError') {
        $('loginOtpCode').value = '';
        showFeedback(error.message || 'OTP verification failed.');
      }
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
      if (!state.navigationStarted && error?.name !== 'AbortError') {
        showFeedback(error.message || 'OTP could not be resent.');
      }
    } finally {
      state.resendInFlight = false;
      updateOtpCountdown();
    }
  }

  function updateCountryUi() {
    const country = $('loginPhoneCountry').value.toUpperCase();
    $('loginPhone').placeholder = country === 'MY'
      ? '01XXXXXXXX or +60XXXXXXXXX'
      : '01XXXXXXXXX or +8801XXXXXXXXX';
  }

  async function loadCountryDefault() {
    try {
      const data = await post('country_defaults', {}, 'Detecting country...');
      const country = String(data.phone_country || 'MY').toUpperCase();
      if (['BD', 'MY'].includes(country)) $('loginPhoneCountry').value = country;
    } catch (_) {
      // Keep the server-compatible fallback country.
    }
    updateCountryUi();
  }

  function focusVisible(input) {
    if (!input) return;
    input.focus({ preventScroll: true });
    window.setTimeout(() => input.scrollIntoView({ behavior: 'smooth', block: 'center' }), 80);
  }

  function handleFocus(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) && !(target instanceof HTMLSelectElement)) return;
    window.setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'center' }), 120);
  }

  function clearLoginNavigationState() {
    clearOtpTimer();
    resetLoginLoading();
    closeFeedback();
    activeRequests.forEach((controller) => controller.abort());
    activeRequests.clear();
  }

  $('loginPhoneContinue').addEventListener('click', continuePhone);
  $('loginPasswordContinue').addEventListener('click', login);
  $('verifyLoginOtpBtn').addEventListener('click', verifyOtp);
  $('resendLoginOtpBtn').addEventListener('click', resendOtp);
  $('loginStepBack').addEventListener('click', () => history.back());
  $('loginFeedbackOk').addEventListener('click', closeFeedback);
  $('loginFeedbackModal').addEventListener('click', (event) => {
    if (event.target === $('loginFeedbackModal')) closeFeedback();
  });
  $('loginPhoneCountry').addEventListener('change', updateCountryUi);
  $('loginPhone').addEventListener('keydown', (event) => { if (event.key === 'Enter') continuePhone(); });
  $('loginPassword').addEventListener('keydown', (event) => { if (event.key === 'Enter') login(); });
  $('loginOtpCode').addEventListener('keydown', (event) => { if (event.key === 'Enter') verifyOtp(); });
  document.addEventListener('focusin', handleFocus);

  window.addEventListener('popstate', (event) => {
    if (isFeedbackOpen()) {
      closeFeedback();
      history.pushState({ authStep: state.step }, '', window.location.href);
      return;
    }
    const requested = event.state?.authStep;
    if (stepOrder.includes(requested)) {
      if (state.step === 'otp' && requested !== 'otp') resetOtpState();
      showStep(requested);
      return;
    }
    backStep();
  });

  window.addEventListener('pagehide', clearLoginNavigationState);
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    state.navigationStarted = false;
    state.loginInFlight = false;
    state.verifyInFlight = false;
    state.resendInFlight = false;
    $('loginPasswordContinue').disabled = false;
    resetLoginLoading();
    closeFeedback();
  });

  resetLoginLoading();
  showStep('phone', { history: 'replace' });
  loadCountryDefault();
})();
