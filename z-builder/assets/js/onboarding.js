// Z Builder onboarding demo. It stores tenant config in browser localStorage only.
(function initOnboarding() {
  const form = document.getElementById('tenantForm');
  const previewName = document.getElementById('previewName');
  const previewPlan = document.getElementById('previewPlan');
  const previewUrl = document.getElementById('previewUrl');

  function readForm() {
    const data = new FormData(form);
    const siteName = data.get('site_name') || 'Demo Site';
    const slug = window.ZBuilderDemoStore.slugify(siteName);
    const plan = data.get('plan') || 'FREE_TRIAL';
    const customDomain = String(data.get('custom_domain') || '').trim();
    return {
      site_name: siteName,
      site_slug: slug,
      plan: plan,
      primary_color: data.get('primary_color') || '#0b5cff',
      logo_url: String(data.get('logo_url') || '').trim(),
      custom_domain: customDomain,
      free_url: 'https://zpayswift.com/site/' + slug,
      sms_brand_name: siteName,
      commission: { topup_per_1000: Number(data.get('topup_per_1000') || 0), bundle_per_1000: Number(data.get('bundle_per_1000') || 0), mfs_fee_mode: 'OWNER_CONFIGURED' },
      features: { topup: true, bundle: true, mfs: true, tracking: true, worker: data.get('worker_enabled') === 'true', telegram: data.get('telegram_enabled') === 'true' },
      telegram: { enabled: data.get('telegram_enabled') === 'true', bot_name: '' }
    };
  }

  function updatePreview() {
    const tenant = readForm();
    document.documentElement.style.setProperty('--brand', tenant.primary_color);
    previewName.textContent = tenant.site_name;
    previewPlan.textContent = tenant.plan === 'SUBSCRIPTION' ? 'Subscription' : 'Free Trial';
    previewUrl.textContent = tenant.custom_domain || tenant.free_url;
  }

  function publishTenant(expired) {
    const tenantData = readForm();
    let tenant = tenantData.plan === 'SUBSCRIPTION' ? window.ZBuilderDemoStore.makePaidTenant(tenantData) : window.ZBuilderDemoStore.makeTrialTenant(tenantData);
    if (expired) tenant = window.ZBuilderDemoStore.expireDemoTenant();
    alert(tenant.site_name + ' demo tenant saved.');
    return tenant;
  }

  const params = new URLSearchParams(window.location.search);
  if (params.get('plan') === 'paid') form.plan.value = 'SUBSCRIPTION';
  if (params.get('plan') === 'free') form.plan.value = 'FREE_TRIAL';

  form.addEventListener('input', updatePreview);
  form.addEventListener('change', updatePreview);
  form.addEventListener('submit', function (event) { event.preventDefault(); publishTenant(false); window.location.href = '../control/index.html'; });
  document.querySelector('[data-expire-demo]')?.addEventListener('click', function () { publishTenant(true); window.location.href = '../expired/index.html'; });
  document.querySelector('[data-clear-demo]')?.addEventListener('click', function () { window.ZBuilderDemoStore.clear(); alert('Demo tenant cleared.'); updatePreview(); });

  const existing = window.ZBuilderDemoStore.load();
  if (existing) {
    form.plan.value = existing.plan === 'SUBSCRIPTION' ? 'SUBSCRIPTION' : 'FREE_TRIAL';
    form.site_name.value = existing.site_name || '';
    form.primary_color.value = existing.primary_color || '#0b5cff';
    form.logo_url.value = existing.logo_url || '';
    form.custom_domain.value = existing.custom_domain || '';
    form.topup_per_1000.value = existing.commission?.topup_per_1000 ?? 18;
    form.bundle_per_1000.value = existing.commission?.bundle_per_1000 ?? 0;
    form.telegram_enabled.value = String(Boolean(existing.features?.telegram));
    form.worker_enabled.value = String(existing.features?.worker !== false);
  }
  updatePreview();
})();
