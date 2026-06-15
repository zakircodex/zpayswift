window.MySiteTenant = {
  tenant: null,

  async load() {
    try {
      const response = await fetch('../demo/tenant.example.json', { cache: 'no-store' });
      if (!response.ok) throw new Error('Tenant config not found');
      this.tenant = await response.json();
    } catch (error) {
      this.tenant = {
        site_name: 'Z-Pay Swift Demo Site',
        plan: 'FREE_TRIAL',
        subscription_status: 'TRIALING',
        subscription_expires_at: '',
        primary_color: '#0b5cff',
        currency: 'BDT',
        features: { topup: true, bundle: true, mfs: true, tracking: true, worker: true }
      };
      console.warn('Using fallback tenant config:', error.message);
    }
    return this.tenant;
  },

  isExpired(tenant) {
    if (!tenant) return true;
    if (tenant.subscription_status === 'EXPIRED' || tenant.subscription_status === 'SUSPENDED') return true;
    if (!tenant.subscription_expires_at) return false;
    return new Date(tenant.subscription_expires_at).getTime() < Date.now();
  },

  statusLabel(tenant) {
    if (!tenant) return 'Unknown';
    if (this.isExpired(tenant)) return 'Expired';
    if (tenant.subscription_status === 'TRIALING') return 'Trial Active';
    if (tenant.subscription_status === 'ACTIVE') return 'Active';
    return tenant.subscription_status || 'Unknown';
  },

  applyBrand(tenant) {
    const root = document.documentElement;
    const color = tenant?.primary_color || tenant?.branding?.primary_color || '#0b5cff';
    root.style.setProperty('--brand', color);
    document.querySelectorAll('[data-site-name]').forEach((el) => {
      el.textContent = tenant?.site_name || 'Z-Pay Swift Demo Site';
    });
    document.querySelectorAll('[data-plan-label]').forEach((el) => {
      el.textContent = this.statusLabel(tenant);
    });
  }
};
