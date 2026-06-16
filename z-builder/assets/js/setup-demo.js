// Z Builder site setup demo only.
(function () {
  const PLAN_KEY = 'z_builder_plan_demo';

  function getPlan() {
    try { return JSON.parse(localStorage.getItem(PLAN_KEY) || 'null'); } catch (e) { return null; }
  }

  function slugify(value) {
    return String(value || 'site').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'site';
  }

  const plan = getPlan() || { code: 'FREE_TRIAL', months: 0 };
  const isFree = plan.code === 'FREE_TRIAL';
  document.querySelectorAll('[data-plan-name]').forEach(function (el) { el.textContent = isFree ? 'Free Trial' : plan.code.replaceAll('_', ' '); });
  document.body.classList.toggle('free-plan', isFree);

  document.querySelectorAll('[data-paid-only]').forEach(function (el) {
    if (isFree) {
      el.classList.add('locked');
      el.querySelectorAll('input,select,textarea,button').forEach(function (input) { input.disabled = true; });
    }
  });

  const form = document.querySelector('[data-setup-form]');
  const previewName = document.querySelector('[data-preview-name]');
  const previewUrl = document.querySelector('[data-preview-url]');

  function readForm() {
    const data = new FormData(form);
    const siteName = String(data.get('site_name') || 'My Demo Site').trim();
    const customDomain = String(data.get('custom_domain') || '').trim();
    const autoId = 'zb-' + Math.random().toString(36).slice(2, 8);
    const siteSlug = slugify(String(data.get('site_slug') || siteName));
    return {
      site_name: siteName,
      site_slug: siteSlug,
      custom_domain: isFree ? '' : customDomain,
      free_url: 'https://zpayswift.com/site/' + (siteSlug || autoId),
      logo_url: isFree ? '' : String(data.get('logo_url') || '').trim(),
      primary_color: data.get('primary_color') || '#0b5cff',
      plan: plan.code,
      subscription_status: isFree ? 'TRIALING' : 'ACTIVE',
      subscription_expires_at: window.ZBuilderDemoStore.daysFromNow(isFree ? 7 : 30),
      currency: 'BDT',
      service_country: 'BD',
      features: { topup: true, bundle: true, mfs: true, tracking: true, worker: !isFree, telegram: !isFree },
      commission: { topup_per_1000: isFree ? 18 : Number(data.get('topup_per_1000') || 18), bundle_per_1000: isFree ? 0 : Number(data.get('bundle_per_1000') || 0), mfs_fee_mode: 'OWNER_CONFIGURED' }
    };
  }

  function updatePreview() {
    const tenant = readForm();
    document.documentElement.style.setProperty('--brand', tenant.primary_color);
    if (previewName) previewName.textContent = tenant.site_name;
    if (previewUrl) previewUrl.textContent = tenant.custom_domain || tenant.free_url;
  }

  form?.addEventListener('input', updatePreview);
  form?.addEventListener('change', updatePreview);
  form?.addEventListener('submit', function (event) {
    event.preventDefault();
    const tenant = window.ZBuilderDemoStore.save(readForm());
    alert(tenant.site_name + ' published in demo.');
    location.href = '../control/index.html';
  });

  updatePreview();
})();
