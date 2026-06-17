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

  function setData(key, data) { localStorage.setItem(key, JSON.stringify(data || {}, null, 2)); }
  function getData(key) { try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; } }

  async function postJson(url, payload) {
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload || {}) });
    const json = await res.json().catch(function () { return null; });
    if (!res.ok || !json || json.ok !== true) throw new Error(json?.message || 'Request failed');
    return json.data || {};
  }

  const registerForm = document.querySelector('[data-owner-form]');
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

  const loginForm = document.querySelector('[data-login-form]');
  loginForm?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const phone = String(new FormData(loginForm).get('phone') || '').trim();
    if (!phone) { box('BD number দিন।', false); return; }
    try {
      box('OTP sent. Please wait...', true);
      const result = await postJson('/api/my_site/auth_login_start.php', { phone: phone });
      setData(LOGIN_KEY, { login_token: result.login_token, otp_id: result.otp_id, phone: result.phone || phone });
      location.href = 'verify-otp.html?mode=login';
    } catch (error) { box(error.message, false); }
  });

  const verifyForm = document.querySelector('[data-otp-form]');
  if (verifyForm) {
    const mode = new URLSearchParams(location.search).get('mode') === 'login' ? 'login' : 'register';
    const storeKey = mode === 'login' ? LOGIN_KEY : REGISTER_KEY;
    const start = getData(storeKey);
    document.querySelectorAll('[data-otp-phone]').forEach(function (el) { el.textContent = start?.phone || '-'; });
    document.querySelectorAll('[data-otp-mode]').forEach(function (el) { el.textContent = mode === 'login' ? 'Login' : 'Register'; });
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
      if (mode === 'login') { box('Login resend next update-এ add হবে। আবার login করুন।', false); return; }
      try {
        const result = await postJson('/api/my_site/auth_resend_otp.php', { pre_auth_token: start.pre_auth_token });
        start.otp_id = result.otp_id;
        setData(storeKey, start);
        box('নতুন OTP পাঠানো হয়েছে।', true);
      } catch (error) { box(error.message, false); }
    });
  }
})();
