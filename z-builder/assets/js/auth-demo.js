// Z Builder owner auth frontend. Email flow uses /api/my_site; Google remains demo for now.
(function () {
  const OWNER_KEY = 'z_builder_owner_demo';
  const SESSION_KEY = 'z_builder_owner_session';
  const VERIFY_URL_KEY = 'z_builder_verify_url';

  function saveOwner(owner) {
    localStorage.setItem(OWNER_KEY, JSON.stringify(owner, null, 2));
    return owner;
  }

  function loadOwner() {
    try { return JSON.parse(localStorage.getItem(OWNER_KEY) || 'null'); } catch (e) { return null; }
  }

  function showOwner() {
    const owner = loadOwner();
    document.querySelectorAll('[data-owner-email]').forEach(function (el) { el.textContent = owner?.email || 'Not logged in'; });
    document.querySelectorAll('[data-owner-status]').forEach(function (el) { el.textContent = owner?.email_verified || owner?.verified ? 'Verified' : 'Not verified'; });
  }

  function showResult(message) {
    const box = document.querySelector('[data-auth-result]');
    if (box) { box.hidden = false; box.textContent = message; }
  }

  async function postJson(url, payload) {
    const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload || {}) });
    const json = await res.json().catch(function () { return null; });
    if (!res.ok || !json) throw new Error(json?.message || 'Request failed');
    return json;
  }

  document.querySelector('[data-google-demo]')?.addEventListener('click', function () {
    const email = prompt('Enter Gmail for demo login');
    if (!email) return;
    saveOwner({ name: 'Google User', email: email.trim(), login_method: 'GOOGLE_DEMO', verified: true, email_verified: true, created_at: new Date().toISOString() });
    alert('Google demo login done. Real Google sign-in will be connected later.');
    window.location.href = '../plans/index.html';
  });

  document.querySelector('[data-verify-demo]')?.addEventListener('click', function () {
    const realUrl = localStorage.getItem(VERIFY_URL_KEY) || '';
    if (realUrl) { window.location.href = realUrl; return; }
    const owner = loadOwner();
    if (!owner) { alert('Create account first.'); return; }
    owner.verified = true;
    owner.email_verified = true;
    owner.verified_at = new Date().toISOString();
    saveOwner(owner);
    window.location.href = '../plans/index.html';
  });

  const form = document.querySelector('[data-owner-form]');
  form?.addEventListener('submit', async function (event) {
    event.preventDefault();
    const data = new FormData(form);
    const name = String(data.get('name') || '').trim();
    const email = String(data.get('email') || '').trim();
    if (!name || !email) { alert('Name and email required.'); return; }

    try {
      const json = await postJson('/api/my_site/auth_register.php', { name: name, email: email });
      if (json.ok && json.data?.owner) {
        saveOwner(json.data.owner);
        if (json.data.verify_url) localStorage.setItem(VERIFY_URL_KEY, json.data.verify_url);
        showOwner();
        showResult('Account created. Click Verify Email Demo to continue.');
      } else {
        throw new Error(json.message || 'Register failed');
      }
    } catch (error) {
      saveOwner({ name: name, email: email, login_method: 'EMAIL_DEMO', verified: false, email_verified: false, created_at: new Date().toISOString() });
      showOwner();
      showResult('Backend request failed. Demo owner saved locally.');
    }
  });

  showOwner();
})();
