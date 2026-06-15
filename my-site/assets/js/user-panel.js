(async function initUserPanel() {
  const tenant = await window.MySiteTenant.load();
  window.MySiteTenant.applyBrand(tenant);

  const expired = window.MySiteTenant.isExpired(tenant);
  document.body.classList.toggle('is-expired', expired);

  const expiryText = document.querySelector('[data-expiry-text]');
  if (expiryText) {
    expiryText.textContent = expired
      ? 'This site subscription is expired. Please contact the site owner.'
      : `Plan status: ${window.MySiteTenant.statusLabel(tenant)}`;
  }

  document.querySelectorAll('[data-demo-action]').forEach((button) => {
    button.addEventListener('click', () => {
      const action = button.getAttribute('data-demo-action');
      alert(`${action} demo only. API connection will be added later.`);
    });
  });
})();
