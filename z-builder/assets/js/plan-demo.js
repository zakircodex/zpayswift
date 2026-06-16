(function () {
  const OWNER_KEY = 'z_builder_owner_demo';
  const PLAN_KEY = 'z_builder_plan_demo';

  function getOwner() {
    try { return JSON.parse(localStorage.getItem(OWNER_KEY) || 'null'); } catch (e) { return null; }
  }

  document.querySelectorAll('[data-select-plan]').forEach(function (button) {
    button.addEventListener('click', function () {
      const user = getOwner();
      if (!user) { alert('Create account first.'); location.href = '../auth/register.html'; return; }
      if (!user.verified) { alert('Verify email first.'); location.href = '../auth/register.html'; return; }
      const plan = {
        code: button.getAttribute('data-select-plan'),
        months: Number(button.getAttribute('data-months') || 0),
        selected_at: new Date().toISOString()
      };
      localStorage.setItem(PLAN_KEY, JSON.stringify(plan, null, 2));
      location.href = '../setup/index.html';
    });
  });
})();
