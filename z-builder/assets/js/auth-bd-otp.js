(function () {
  const REGISTER_KEY = 'z_builder_register_start';
  const LOGIN_KEY = 'z_builder_login_start';
  const OWNER_KEY = 'z_builder_owner_demo';
  const SESSION_KEY = 'z_builder_owner_session';

  function box(message, ok) {
    const el = document.querySelector('[data-auth-result]');
    if (!el) return;
    el.hidden = false;
    el.textContent = message;
    el.classList.toggle('success', !!ok);
    el.classList.toggle('danger', !ok);
  }
  function otpBox(message, ok) {
    const el = document.querySelector('[data-login-otp-result]');
    if (!el) return;
    el.hidden = false;
    el.textContent = message;
    el.classList.toggle('success', !!ok);
    el.classList.toggle('danger', !ok);
  }
  function setData(key, data) { localStorage.setItem(key, JSON.stringify(data || {}, null, 2)); }
  function getData(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; } }

  async function postJson(url, payload) {
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload || {}) });
    const json = await res.json().catch(function () { return null; });
    if (!res.ok || !json || json.ok !== true) throw new Error(json?.message || 'Request failed');
    return json.data || {};
  }

  async function checkSession() {
    const session = localStorage.getItem(SESSION_KEY) || '';
    if (!session) return null;
    try {
      const res = await fetch('/api/my_site/owner_session.php', { headers: { 'Accept': 'application/json', 'X-ZBUILDER-SESSION': session }, cache: 'no-store' });
      const json = await res.json().catch(function () { return null; });
      if (!res.ok || !json || json.ok !== true) throw new Error('Invalid session');
      if (json.data?.owner) localStorage.setItem(OWNER_KEY, JSON.stringify(json.data.owner, null, 2));
      return json.data?.owner || null;
    } catch (e) {
      localStorage.removeItem(SESSION_KEY);
      localStorage.removeItem(OWNER_KEY);
      return null;
    }
  }

  const registerForm = document.querySelector('[data-owner-form]');
  const loginForm = document.querySelector('[data-login-form]');
  if (registerForm || loginForm) {
    checkSession().then(function (owner) { if (owner) location.href = '../dashboard/index.html'; });
  }

  registerForm?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const data = new FormData(registerForm);
    const payload = { name: String(data.get('name') || '').trim(), phone: String(data.get('phone') || '').trim(), email: String(data.get('email') || '').trim(), dob: String(data.get('dob') || '').trim(), address: String(data.get('address') || '').trim() };
    if (!payload.name || !payload.phone || !payload.email || !payload.dob || !payload.address) { box('সব field পূরণ করুন।', false); return; }
    try {
      box('OTP sent. Please wait...', true);
      const result = await postJson('/api/my_site/auth_register_start.php', payload);
      setData(REGISTER_KEY, { pre_auth_token: result.pre_auth_token, otp_id: result.otp_id, phone: result.phone || payload.phone, email: payload.email, name: payload.name });
      location.href = 'verify-otp.html?mode=register';
    } catch (error) { box(error.message, false); }
  });

  function openLoginModal(phone) {
    document.querySelectorAll('[data-login-otp-phone]').forEach(function (el) { el.textContent = phone || '-'; });
    const modal = document.querySelector('[data-login-otp-modal]');
    if (modal) modal.hidden = false;
  }
  function closeLoginModal() {
    const modal = document.querySelector('[data-login-otp-modal]');
    if (modal) modal.hidden = true;
  }

  loginForm?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const phone = String(new FormData(loginForm).get('phone') || '').trim();
    if (!phone) { box('BD number দিন।', false); return; }
    try {
      box('OTP sent. Please verify.', true);
      const result = await postJson('/api/my_site/auth_login_start.php', { phone: phone });
      const data = { login_token: result.login_token, otp_id: result.otp_id, phone: result.phone || phone };
      setData(LOGIN_KEY, data);
      openLoginModal(data.phone);
    } catch (error) { box(error.message, false); }
  });

  document.querySelector('[data-modal-close]')?.addEventListener('click', closeLoginModal);
  document.querySelector('[data-login-otp-modal]')?.addEventListener('click', function (event) {
    if (event.target === event.currentTarget) closeLoginModal();
  });

  const loginOtpForm = document.querySelector('[data-login-otp-form]');
  loginOtpForm?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const start = getData(LOGIN_KEY);
    const otp = String(new FormData(loginOtpForm).get('otp') || '').trim();
    if (!start?.login_token || !start?.otp_id) { otpBox('আবার login করুন।', false); return; }
    if (otp.length !== 6) { otpBox('৬ digit OTP দিন।', false); return; }
    try {
      otpBox('Verifying...', true);
      const result = await postJson('/api/my_site/auth_login_verify.php', { login_token: start.login_token, otp_id: start.otp_id, otp: otp });
      localStorage.setItem(OWNER_KEY, JSON.stringify(result.owner || {}, null, 2));
      if (result.session_token) localStorage.setItem(SESSION_KEY, result.session_token);
      localStorage.removeItem(LOGIN_KEY);
      location.href = '../dashboard/index.html';
    } catch (error) { otpBox(error.message, false); }
  });

  document.querySelector('[data-login-resend-otp]')?.addEventListener('click', async function () {
    const start = getData(LOGIN_KEY);
    if (!start?.login_token) { otpBox('আবার login করুন।', false); return; }
    try {
      otpBox('Sending new OTP...', true);
      const result = await postJson('/api/my_site/auth_login_resend_otp.php', { login_token: start.login_token });
      start.otp_id = result.otp_id;
      setData(LOGIN_KEY, start);
      otpBox('নতুন OTP পাঠানো হয়েছে।', true);
    } catch (error) { otpBox(error.message, false); }
  });

  const verifyForm = document.querySelector('[data-otp-form]');
  if (verifyForm) {
    const mode = new URLSearchParams(location.search).get('mode') === 'login' ? 'login' : 'register';
    const storeKey = mode === 'login' ? LOGIN_KEY : REGISTER_KEY;
    const start = getData(storeKey);
    document.querySelectorAll('[data-otp-phone]').forEach(function (el) { el.textContent = start?.phone || '-'; });
    document.querySelectorAll('[data-otp-mode]').forEach(function (el) { el.textContent = mode === 'login' ? 'Login' : 'Register'; });
    document.querySelectorAll('[data-otp-title]').forEach(function (el) { el.textContent = mode === 'login' ? 'Login Verification' : 'Account Verification'; });
    document.querySelectorAll('[data-otp-submit]').forEach(function (el) { el.textContent = mode === 'login' ? 'Login' : 'Create Account'; });
    if (!start?.otp_id) box('আবার শুরু করুন।', false);
    verifyForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      const otp = String(new FormData(verifyForm).get('otp') || '').trim();
      if (otp.length !== 6) { box('৬ digit OTP দিন।', false); return; }
      try {
        box('OTP verify হচ্ছে...', true);
        const url = mode === 'login' ? '/api/my_site/auth_login_verify.php' : '/api/my_site/auth_register_verify.php';
        const payload = mode === 'login' ? { login_token: start.login_token, otp_id: start.otp_id, otp: otp } : { pre_auth_token: start.pre_auth_token, otp_id: start.otp_id, otp: otp };
        const result = await postJson(url, payload);
        localStorage.setItem(OWNER_KEY, JSON.stringify(result.owner || {}, null, 2));
        if (result.session_token) localStorage.setItem(SESSION_KEY, result.session_token);
        localStorage.removeItem(storeKey);
        location.href = '../dashboard/index.html';
      } catch (error) { box(error.message, false); }
    });
    document.querySelector('[data-resend-otp]')?.addEventListener('click', async function () {
      try {
        const url = mode === 'login' ? '/api/my_site/auth_login_resend_otp.php' : '/api/my_site/auth_resend_otp.php';
        const payload = mode === 'login' ? { login_token: start.login_token } : { pre_auth_token: start.pre_auth_token };
        const result = await postJson(url, payload);
        start.otp_id = result.otp_id;
        setData(storeKey, start);
        box('নতুন OTP পাঠানো হয়েছে।', true);
      } catch (error) { box(error.message, false); }
    });
  }
})();
