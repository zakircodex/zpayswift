(() => {
  'use strict';

  const proxyUrl = window.USER_PROXY_URL || '/api/user/proxy.php';
  const $ = (id) => document.getElementById(id);
  const otpState = {
    preAuthToken: '',
    otpRequestId: '',
    maskedPhone: '',
    expiresAt: 0,
    timer: 0,
    trustDevice: true
  };

  function setBusy(on, label = 'Loading...') {
    $('loadingText').textContent = label;
    $('loadingWrap').classList.toggle('show', Boolean(on));
    $('loadingWrap').setAttribute('aria-hidden', on ? 'false' : 'true');
  }

  function setLoginError(message = '') {
    $('loginError').textContent = message;
    $('loginError').classList.toggle('show', Boolean(message));
  }

  function toast(message, type = 'info') {
    const node = document.createElement('div');
    node.className = `toast ${type}`;
    node.textContent = message;
    $('toastWrap').appendChild(node);
    window.setTimeout(() => node.remove(), 2800);
  }

  async function post(action, body, label) {
    setBusy(true, label);
    try {
      const response = await fetch(`${proxyUrl}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        credentials: 'same-origin',
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
        throw new Error('Invalid response received.');
      }
      if (!response.ok || json?.ok !== true) {
        const error = new Error(String(json?.message || 'Request failed'));
        error.code = String(json?.code || 'REQUEST_FAILED');
        error.status = response.status;
        throw error;
      }
      return json.data || {};
    } finally {
      setBusy(false);
    }
  }

  function clearTimer() {
    if (otpState.timer) window.clearInterval(otpState.timer);
    otpState.timer = 0;
  }

  function updateCountdown() {
    const left = Math.max(0, Math.ceil((otpState.expiresAt - Date.now()) / 1000));
    $('loginOtpExpiresText').textContent = left > 0 ? `${left} seconds` : 'Expired';
    $('verifyLoginOtpBtn').disabled = left < 1;
    if (left < 1) {
      clearTimer();
      $('loginOtpStatus').textContent = 'OTP expired. Please resend OTP to continue.';
    }
  }

  function setOtpData(data) {
    otpState.preAuthToken = String(data.pre_auth_token || otpState.preAuthToken || '');
    otpState.otpRequestId = String(data.otp_request_id || otpState.otpRequestId || '');
    otpState.maskedPhone = String(data.masked_phone || otpState.maskedPhone || '');
    const expiresAt = Number(data.expires_at || 0);
    const expiresIn = Math.max(0, Number(data.expires_in_seconds || data.expires_in || 300));
    otpState.expiresAt = expiresAt > 0
      ? (expiresAt < 1000000000000 ? expiresAt * 1000 : expiresAt)
      : Date.now() + expiresIn * 1000;
    $('loginOtpMaskedPhone').textContent = otpState.maskedPhone || '-';
    $('loginOtpCode').value = '';
    $('loginOtpStatus').textContent = `OTP sent to ${otpState.maskedPhone || 'your phone'}. Enter the code to complete login.`;
    clearTimer();
    updateCountdown();
    otpState.timer = window.setInterval(updateCountdown, 1000);
  }

  function openOtp() {
    $('loginOtpModal').classList.add('show');
    $('loginOtpModal').setAttribute('aria-hidden', 'false');
    window.setTimeout(() => $('loginOtpCode').focus(), 0);
  }

  function closeOtp() {
    clearTimer();
    $('loginOtpModal').classList.remove('show');
    $('loginOtpModal').setAttribute('aria-hidden', 'true');
    otpState.preAuthToken = '';
    otpState.otpRequestId = '';
    otpState.maskedPhone = '';
    otpState.expiresAt = 0;
    $('loginOtpCode').value = '';
  }

  function validPhone(country, phone) {
    const digits = phone.replace(/\D+/g, '');
    return country === 'MY'
      ? /^(?:011\d{8}|01[02-9]\d{7}|6011\d{8}|601[02-9]\d{7}|11\d{8}|1[02-9]\d{7})$/.test(digits)
      : /^(?:01[3-9]\d{8}|8801[3-9]\d{8}|1[3-9]\d{8})$/.test(digits);
  }

  async function login() {
    setLoginError('');
    const phone = $('loginPhone').value.trim();
    const phoneCountry = $('loginPhoneCountry').value.toUpperCase();
    const password = $('loginPassword').value;
    otpState.trustDevice = $('rememberTrustedDevice').checked;
    if (!phone || !password) return setLoginError('Phone and password are required.');
    if (!validPhone(phoneCountry, phone)) {
      return setLoginError(phoneCountry === 'MY' ? 'Invalid Malaysia number' : 'Invalid Bangladesh number');
    }
    try {
      const data = await post('login', {
        phone,
        phone_country: phoneCountry,
        password,
        trust_device: otpState.trustDevice,
        device_id: 'USER_WEB',
        device_name: 'User Dashboard',
        browser_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || ''
      }, 'Logging in...');
      if (data.require_otp) {
        setOtpData(data);
        openOtp();
        toast('OTP sent for login verification', 'ok');
        return;
      }
      window.location.replace('/user/dashboard');
    } catch (error) {
      setLoginError(error.message || 'Login failed');
    }
  }

  async function verifyOtp() {
    const otp = $('loginOtpCode').value.trim();
    if (!otpState.preAuthToken || !otpState.otpRequestId) {
      $('loginOtpStatus').textContent = 'Login verification session missing. Please login again.';
      return;
    }
    if (Date.now() >= otpState.expiresAt) {
      $('loginOtpStatus').textContent = 'OTP expired. Please resend OTP to continue.';
      return;
    }
    if (!otp) {
      $('loginOtpStatus').textContent = 'Please enter the OTP first.';
      return;
    }
    try {
      await post('login_verify_otp', {
        pre_auth_token: otpState.preAuthToken,
        otp_request_id: otpState.otpRequestId,
        otp,
        trust_device: otpState.trustDevice,
        device_id: 'USER_WEB',
        device_name: 'User Dashboard'
      }, 'Verifying OTP...');
      $('loginOtpStatus').textContent = 'OTP verified successfully. Logging in...';
      window.location.replace('/user/dashboard');
    } catch (error) {
      $('loginOtpStatus').textContent = error.message || 'OTP verification failed.';
    }
  }

  async function resendOtp() {
    if (!otpState.preAuthToken || !otpState.otpRequestId) {
      $('loginOtpStatus').textContent = 'Login verification session missing. Please login again.';
      return;
    }
    try {
      const data = await post('login_resend_otp', {
        pre_auth_token: otpState.preAuthToken,
        otp_request_id: otpState.otpRequestId
      }, 'Resending OTP...');
      setOtpData(data);
      toast('OTP resent successfully', 'ok');
    } catch (error) {
      $('loginOtpStatus').textContent = error.message || 'Failed to resend OTP.';
    }
  }

  async function loadCountryDefault() {
    try {
      const data = await post('country_defaults', {}, 'Detecting country...');
      const country = String(data.phone_country || 'BD').toUpperCase();
      if (['BD', 'MY'].includes(country)) $('loginPhoneCountry').value = country;
    } catch (_) {
      // The existing compatibility default remains Bangladesh.
    }
    $('loginPhoneCountry').dispatchEvent(new Event('change'));
  }

  $('loginBtn').addEventListener('click', login);
  $('loginPhone').addEventListener('keydown', (event) => { if (event.key === 'Enter') $('loginPassword').focus(); });
  $('loginPassword').addEventListener('keydown', (event) => { if (event.key === 'Enter') login(); });
  $('loginPhoneCountry').addEventListener('change', () => {
    $('loginPhone').placeholder = $('loginPhoneCountry').value === 'MY'
      ? '01XXXXXXXX or +60XXXXXXXXX'
      : '01XXXXXXXXX or +8801XXXXXXXXX';
  });
  $('verifyLoginOtpBtn').addEventListener('click', verifyOtp);
  $('resendLoginOtpBtn').addEventListener('click', resendOtp);
  $('cancelLoginOtpBtn').addEventListener('click', closeOtp);
  $('closeLoginOtpModalBtn').addEventListener('click', closeOtp);
  $('loginOtpCode').addEventListener('keydown', (event) => { if (event.key === 'Enter') verifyOtp(); });
  $('loginOtpModal').addEventListener('click', (event) => { if (event.target === $('loginOtpModal')) closeOtp(); });
  loadCountryDefault();
})();
