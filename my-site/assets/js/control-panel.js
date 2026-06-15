(async function initControlPanel() {
  const tenant = await window.MySiteTenant.load();
  window.MySiteTenant.applyBrand(tenant);

  const expired = window.MySiteTenant.isExpired(tenant);
  document.body.classList.toggle('is-expired', expired);

  const status = document.querySelector('[data-control-status]');
  if (status) {
    status.textContent = expired
      ? 'Expired: renewal required before users can transact.'
      : `${window.MySiteTenant.statusLabel(tenant)} — owner controls are available in demo mode.`;
  }

  document.querySelectorAll('[data-setting-value]').forEach((el) => {
    const key = el.getAttribute('data-setting-value');
    el.textContent = tenant?.[key] || '-';
  });

  document.querySelectorAll('[data-demo-action]').forEach((button) => {
    button.addEventListener('click', () => {
      const action = button.getAttribute('data-demo-action');
      alert(`${action} demo only. Secure tenant API will be connected later.`);
    });
  });
})();
