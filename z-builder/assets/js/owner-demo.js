(async function () {
  let tenant = await window.ZBuilderTenant.load();
  const label = document.querySelector('[data-control-status]');

  function draw() {
    const expired = window.ZBuilderTenant.isExpired(tenant);
    document.body.classList.toggle('is-expired', expired);
    if (label) label.textContent = expired ? 'Expired: renewal required.' : window.ZBuilderTenant.statusLabel(tenant) + ' — demo mode.';
    document.querySelectorAll('[data-setting-value]').forEach(function (el) {
      const key = el.getAttribute('data-setting-value');
      el.textContent = tenant?.[key] || '-';
    });
    window.ZBuilderTenant.applyBrand(tenant);
  }

  draw();
  document.querySelectorAll('[data-demo-action]').forEach(function (button) {
    button.addEventListener('click', function () { alert((button.getAttribute('data-demo-action') || 'Action') + ' demo only.'); });
  });
  document.querySelector('[data-expire-demo]')?.addEventListener('click', function () { tenant = window.ZBuilderDemoStore.expireDemoTenant(); draw(); });
  document.querySelector('[data-renew-demo]')?.addEventListener('click', function () { tenant = window.ZBuilderDemoStore.renewDemoTenant(30); draw(); });
})();
