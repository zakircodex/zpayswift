// Z Builder user panel demo only. Real login/register/topup APIs will be connected later.
(async function initUserPanel() {
  let tenant = await window.ZBuilderTenant.load();
  window.ZBuilderTenant.applyBrand(tenant);

  const expiryText = document.querySelector('[data-expiry-text]');
  function renderStatus() {
    const expired = window.ZBuilderTenant.isExpired(tenant);
    document.body.classList.toggle('is-expired', expired);
    if (expiryText) {
      expiryText.textContent = expired
        ? 'This site subscription is expired. Please contact the site owner.'
        : 'Plan status: ' + window.ZBuilderTenant.statusLabel(tenant) + '. Days left: ' + (window.ZBuilderTenant.daysLeft(tenant) || '-');
    }
    window.ZBuilderTenant.applyBrand(tenant);
  }
  renderStatus();

  document.querySelectorAll('[data-demo-action]').forEach(function (button) {
    button.addEventListener('click', function () {
      const action = button.getAttribute('data-demo-action');
      alert(action + ' demo only. Secure Z Builder backend connection will be added later.');
    });
  });
  document.querySelector('[data-expire-demo]')?.addEventListener('click', function () { tenant = window.ZBuilderDemoStore.expireDemoTenant(); renderStatus(); });
  document.querySelector('[data-renew-demo]')?.addEventListener('click', function () { tenant = window.ZBuilderDemoStore.renewDemoTenant(30); renderStatus(); });
})();
