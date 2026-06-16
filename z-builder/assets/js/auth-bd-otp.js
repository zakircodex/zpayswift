(function () {
  const START_KEY = 'z_builder_register_start';
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

  function saveStart(data) { localStorage.setItem(START_KEY, JSON.stringify(data || {}, null, 2)); }
  function getStart() { try { return JSON.parse(localStorage.getItem(START_KEY) || 'null'); } catch (e) { return null; } }

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
    const payload = {
      name: String(data.get('name') || '').trim(),
      phone: String(data.get('phone') || '').trim(),
      email: String(data.get('email') || '').trim(),
      dob: String(data.get('dob') || '').trim(),
      address: String(data.get('address') || '').trim()
    };
    if (!payload.name || !payload.phone || !payload.email || !payload.dob || !payload.address) { box('সব field পূরণ করুন।', false); return; }
    try {
      box('OTP পাঠানো হচ্ছে...', true);
      const result = await postJson('/api/my_site/auth_register_start.php', payload);
      saveStart({ pre_auth_token: result.pre_auth_token, otp_id: result.otp_id, phone: result.phone || payload.phone, email: payload.email, name: payload.name });
      location.href = 'verify-otp.html';
    } catch (error) { box(error.message, false); }
  });

  const verifyForm = document.querySelector('[data-otp-form]');
  if (verifyForm) {
    const start = getStart();
    document.querySelectorAll('[data-otp-phone]').forEach(function (el) { el.textContent = start?.phone || '-'; });
    if (!start?.pre_auth_token || !start?.otp_id) box('আবার register করুন।', false);
    verifyForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      const otp = String(new FormData(verifyForm).get('otp') || '').trim();
      if (otp.length !== 6) { box('৬ digit OTP দিন।', false); return; }
      try {
        box('OTP verify হচ্ছে...', true);
        const result = await postJson('/api/my_site/auth_register_verify.php', { pre_auth_token: start.pre_auth_token, otp_id: start.otp_id, otp: otp });
        localStorage.setItem(OWNER_KEY, JSON.stringify(result.owner || {}, null, 2));
        if (result.session_token) localStorage.setItem(SESSION_KEY, result.session_token);
        localStorage.removeItem(START_KEY);
        location.href = '../dashboard/index.html';
      } catch (error) { box(error.message, false); }
    });
    document.querySelector('[data-resend-otp]')?.addEventListener('click', async function () {
      try {
        const result = await postJson('/api/my_site/auth_resend_otp.php', { pre_auth_token: start.pre_auth_token });
        start.otp_id = result.otp_id;
        saveStart(start);
        box('নতুন OTP পাঠানো হয়েছে।', true);
      } catch (error) { box(error.message, false); }
    });
  }
})();
