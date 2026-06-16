// Control panel demo only. Real owner APIs must be tenant-scoped and server-side guarded later.
(async function initControlPanel() {
  let tenant = await window.MySiteTenant.load();
  window.MySiteTenant.applyBrand(tenant);

  const status = document.querySelector('[data-control-status]');

  function renderStatus() {
    const expired = window.MySiteTenant.isExpired(tenant);
    document.body.classList.toggle('is-expired', expired);

    if (status) {
      status.textContent = expired
        ? 'Expired: renewal required before users can transact.'
        : window.MySiteTenant.statusLabel(tenant) + ' — owner controls are available in demo mode.';
    }

    document.querySelectorAll('[data-setting-value]').forEach(function (el) {
      const key = el.getAttribute('data-setting-value');
      el.textContent = tenant?.[key] || '-';
    });

    window.MySiteTenant.applyBrand(tenant);
  }

  renderStatus();

  document.querySelectorAll('[data-demo-action]').forEach(function (button) {
    button.addEventListener('click', function () {
      const action = button.getAttribute('data-demo-action');
      alert(action + ' demo only. Secure tenant API will be connected later.');
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
