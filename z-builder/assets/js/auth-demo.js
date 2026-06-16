// Z Builder owner account demo only. Real email/Google sign-in will be connected later.
(function () {
  const OWNER_KEY = 'z_builder_owner_demo';

  function saveOwner(owner) {
    localStorage.setItem(OWNER_KEY, JSON.stringify(owner, null, 2));
    return owner;
  }

  function loadOwner() {
    try { return JSON.parse(localStorage.getItem(OWNER_KEY) || 'null'); }
    catch (e) { return null; }
  }

  function showOwner() {
    const owner = loadOwner();
    document.querySelectorAll('[data-owner-email]').forEach(function (el) { el.textContent = owner?.email || 'Not logged in'; });
    document.querySelectorAll('[data-owner-status]').forEach(function (el) { el.textContent = owner?.verified ? 'Verified' : 'Not verified'; });
  }

  document.querySelector('[data-google-demo]')?.addEventListener('click', function () {
    const email = prompt('Enter Gmail for demo login');
    if (!email) return;
    saveOwner({ name: 'Google User', email: email.trim(), login_method: 'GOOGLE_DEMO', verified: true, created_at: new Date().toISOString() });
    alert('Google demo login done. Real Google sign-in will be connected later.');
    window.location.href = '../plans/index.html';
  });

  document.querySelector('[data-verify-demo]')?.addEventListener('click', function () {
    const owner = loadOwner();
    if (!owner) { alert('Create account first.'); return; }
    owner.verified = true;
    owner.verified_at = new Date().toISOString();
    saveOwner(owner);
    alert('Email verified in demo.');
    window.location.href = '../plans/index.html';
  });

  const form = document.querySelector('[data-owner-form]');
  form?.addEventListener('submit', function (event) {
    event.preventDefault();
    const data = new FormData(form);
    const name = String(data.get('name') || '').trim();
    const email = String(data.get('email') || '').trim();
    if (!name || !email) { alert('Name and email required.'); return; }
    saveOwner({ name: name, email: email, login_method: 'EMAIL_DEMO', verified: false, created_at: new Date().toISOString() });
    alert('Demo verification email would be sent now. Click Verify Email Demo to continue.');
    showOwner();
  });

  showOwner();
})();
