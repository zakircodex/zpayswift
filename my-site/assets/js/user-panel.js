// User panel demo only. Real login/register/topup APIs will be connected later.
(async function initUserPanel() {
  let tenant = await window.MySiteTenant.load();
  window.MySiteTenant.applyBrand(tenant);

  const expiryText = document.querySelector('[data-expiry-text]');
  function renderStatus() {
    const expired = window.MySiteTenant.isExpired(tenant);
    document.body.classList.toggle('is-expired', expired);
    if (expiryText) {
      expiryText.textContent = expired
        ? 'This site subscription is expired. Please contact the site owner.'
        : 'Plan status: ' + window.MySiteTenant.statusLabel(tenant) + '. Days left: ' + (window.MySiteTenant.daysLeft(tenant) || '-');
    }
    window.MySiteTenant.applyBrand(tenant);
  }

  renderStatus();

  document.querySelectorAll('[data-demo-action]').forEach(function (button) {
    button.addEventListener('click', function () {
      const action = button.getAttribute('data-demo-action');
      alert(action + ' demo only. Secure Z-Pay Swift API connection will be added later.');
    });
  });

  document.querySelector('[data-expire-demo]')?.addEventListener('click', function () {
    tenant = window.MySiteDemoStore.expireDemoTenant();
    renderStatus();
  });

  document.querySelector('[data-renew-demo]')?.addEventListener('click', function () {
    tenant = window.MySiteDemoStore.renewDemoTenant(30);
    renderStatus();
  });
})();
