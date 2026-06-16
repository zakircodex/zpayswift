// Z Builder demo storage only. No real API key or secret is stored here.
(function attachMySiteDemoStore() {
  const KEY = 'z_builder_demo_tenant';
  const LEGACY_KEY = 'zpayswift_my_site_demo_tenant';

  function daysFromNow(days) {
    const date = new Date();
    date.setDate(date.getDate() + Number(days || 0));
    return date.toISOString();
  }

  function defaultTenant() {
    return {
      tenant_id: 'demo_tenant',
      owner_uid: 'owner_uid_placeholder',
      plan: 'FREE_TRIAL',
      subscription_status: 'TRIALING',
      subscription_expires_at: daysFromNow(7),
      site_name: 'Z Builder Demo Site',
      site_slug: 'z-builder-demo',
      free_url: 'https://zpayswift.com/site/z-builder-demo',
      custom_domain: '',
      domain_status: 'NOT_CONFIGURED',
      logo_url: '',
      primary_color: '#0b5cff',
      service_country: 'BD',
      currency: 'BDT',
      sms_brand_name: 'Z Builder Demo',
      features: {
        topup: true,
        bundle: true,
        mfs: true,
        tracking: true,
        worker: true,
        telegram: false
      },
      commission: {
        topup_per_1000: 18,
        bundle_per_1000: 0,
        mfs_fee_mode: 'OWNER_CONFIGURED'
      },
      worker: {
        mode: 'EXISTING_ZPAY_WORKER_APP',
        link_mode: 'QR_OR_LINK_CODE',
        demo_link_code: 'DEMO-WORKER-LINK-CODE'
      },
      telegram: {
        enabled: false,
        bot_name: ''
      }
    };
  }

  function slugify(value) {
    return String(value || 'z-builder-demo')
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'z-builder-demo';
  }

  function normalizeTenant(input) {
    const base = defaultTenant();
    const tenant = { ...base, ...(input || {}) };
    tenant.site_slug = slugify(tenant.site_slug || tenant.site_name);
    tenant.free_url = tenant.free_url || `https://zpayswift.com/site/${tenant.site_slug}`;
    tenant.currency = 'BDT';
    tenant.service_country = 'BD';
    tenant.features = { ...base.features, ...(tenant.features || {}) };
    tenant.commission = { ...base.commission, ...(tenant.commission || {}) };
    tenant.worker = { ...base.worker, ...(tenant.worker || {}) };
    tenant.telegram = { ...base.telegram, ...(tenant.telegram || {}) };
    return tenant;
  }

  function load() {
    try {
      const raw = localStorage.getItem(KEY) || localStorage.getItem(LEGACY_KEY);
      return raw ? normalizeTenant(JSON.parse(raw)) : null;
    } catch (error) {
      console.warn('Z Builder demo tenant load failed:', error.message);
      return null;
    }
  }

  function save(tenant) {
    const next = normalizeTenant(tenant);
    localStorage.setItem(KEY, JSON.stringify(next, null, 2));
    localStorage.removeItem(LEGACY_KEY);
    return next;
  }

  function clear() {
    localStorage.removeItem(KEY);
    localStorage.removeItem(LEGACY_KEY);
  }

  function makeTrialTenant(formData) {
    return save({
      ...defaultTenant(),
      ...formData,
      plan: 'FREE_TRIAL',
      subscription_status: 'TRIALING',
      subscription_expires_at: daysFromNow(7)
    });
  }

  function makePaidTenant(formData) {
    return save({
      ...defaultTenant(),
      ...formData,
      plan: 'SUBSCRIPTION',
      subscription_status: 'ACTIVE',
      subscription_expires_at: daysFromNow(30),
      domain_status: formData.custom_domain ? 'PENDING_VERIFICATION' : 'NOT_CONFIGURED'
    });
  }

  function expireDemoTenant() {
    const tenant = load() || defaultTenant();
    tenant.subscription_status = 'EXPIRED';
    tenant.subscription_expires_at = daysFromNow(-1);
    return save(tenant);
  }

  function renewDemoTenant(days = 30) {
    const tenant = load() || defaultTenant();
    tenant.subscription_status = 'ACTIVE';
    tenant.plan = tenant.plan === 'FREE_TRIAL' ? 'SUBSCRIPTION' : tenant.plan;
    tenant.subscription_expires_at = daysFromNow(days);
    return save(tenant);
  }

  window.MySiteDemoStore = {
    KEY,
    daysFromNow,
    defaultTenant,
    normalizeTenant,
    load,
    save,
    clear,
    makeTrialTenant,
    makePaidTenant,
    expireDemoTenant,
    renewDemoTenant,
    slugify
  };
})();
