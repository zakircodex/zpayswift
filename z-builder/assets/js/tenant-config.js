// Z Builder tenant config helper.
window.ZBuilderTenant = {
  tenant: null,

  demoJsonPath() {
    const p = window.location.pathname;
    if (p.includes('/user/') || p.includes('/control/') || p.includes('/onboarding/') || p.includes('/expired/')) return '../demo/tenant.example.json';
    return 'demo/tenant.example.json';
  },

  fallbackTenant() {
    if (window.ZBuilderDemoStore) return window.ZBuilderDemoStore.defaultTenant();
    return { site_name: 'Z Builder Demo Site', plan: 'FREE_TRIAL', subscription_status: 'TRIALING', subscription_expires_at: '', primary_color: '#0b5cff', currency: 'BDT', service_country: 'BD' };
  },

  normalize(tenant) {
    if (window.ZBuilderDemoStore) return window.ZBuilderDemoStore.normalizeTenant(tenant);
    return { ...this.fallbackTenant(), ...(tenant || {}) };
  },

  async load() {
    const apiRef = window.ZBuilderAPI?.refFromUrl?.();
    if (apiRef) {
      try {
        const data = await window.ZBuilderAPI.getTenant(apiRef);
        this.tenant = this.normalize(data.tenant || {});
        return this.tenant;
      } catch (error) {
        console.warn('Z Builder tenant API read failed, using local/demo config:', error.message);
      }
    }

    const stored = window.ZBuilderDemoStore?.load?.();
    if (stored) { this.tenant = this.normalize(stored); return this.tenant; }

    try {
      const response = await fetch(this.demoJsonPath(), { cache: 'no-store' });
      if (!response.ok) throw new Error('Tenant config not found');
      this.tenant = this.normalize(await response.json());
    } catch (error) {
      this.tenant = this.normalize(this.fallbackTenant());
      console.warn('Using Z Builder fallback tenant config:', error.message);
    }
    return this.tenant;
  },

  isExpired(tenant) {
    if (!tenant) return true;
    if (tenant.subscription_status === 'EXPIRED' || tenant.subscription_status === 'SUSPENDED') return true;
    if (!tenant.subscription_expires_at) return false;
    const expiresAt = new Date(tenant.subscription_expires_at).getTime();
    return Number.isFinite(expiresAt) && expiresAt < Date.now();
  },

  statusLabel(tenant) {
    if (!tenant) return 'Unknown';
    if (this.isExpired(tenant)) return 'Expired';
    if (tenant.subscription_status === 'TRIALING') return 'Trial Active';
    if (tenant.subscription_status === 'ACTIVE') return 'Active';
    return tenant.subscription_status || 'Unknown';
  },

  daysLeft(tenant) {
    if (!tenant?.subscription_expires_at) return '';
    const diff = new Date(tenant.subscription_expires_at).getTime() - Date.now();
    if (!Number.isFinite(diff)) return '';
    return Math.max(0, Math.ceil(diff / 86400000));
  },

  applyBrand(tenant) {
    document.documentElement.style.setProperty('--brand', tenant?.primary_color || '#0b5cff');
    document.querySelectorAll('[data-site-name]').forEach((el) => { el.textContent = tenant?.site_name || 'Z Builder Demo Site'; });
    document.querySelectorAll('[data-plan-label]').forEach((el) => { el.textContent = this.statusLabel(tenant); });
    document.querySelectorAll('[data-expiry-days]').forEach((el) => {
      const days = this.daysLeft(tenant);
      el.textContent = days === '' ? '-' : days + ' day' + (days === 1 ? '' : 's');
    });
    document.querySelectorAll('[data-tenant-url]').forEach((el) => { el.textContent = tenant?.custom_domain || tenant?.free_url || 'https://zpayswift.com/site/z-builder-demo'; });
    document.querySelectorAll('[data-site-logo]').forEach((el) => {
      const logoUrl = tenant?.logo_url || '';
      if (logoUrl && el.tagName === 'IMG') { el.src = logoUrl; el.alt = (tenant?.site_name || 'Tenant') + ' logo'; el.hidden = false; }
      else if (el.tagName === 'IMG') { el.hidden = true; }
    });
    document.body.classList.toggle('is-expired', this.isExpired(tenant));
  }
};
window.MySiteTenant = window.ZBuilderTenant;
