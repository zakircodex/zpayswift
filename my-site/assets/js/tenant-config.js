// My-Site tenant config helper. Demo only; real backend API will be connected later.
window.MySiteTenant = {
  tenant: null,

  demoJsonPath() {
    const path = window.location.pathname;
    if (path.includes('/user/') || path.includes('/control/') || path.includes('/onboarding/') || path.includes('/expired/')) {
      return '../demo/tenant.example.json';
    }
    return 'demo/tenant.example.json';
  },

  fallbackTenant() {
    if (window.MySiteDemoStore) return window.MySiteDemoStore.defaultTenant();
    return {
      site_name: 'Z-Pay Swift Demo Site',
      plan: 'FREE_TRIAL',
      subscription_status: 'TRIALING',
      subscription_expires_at: '',
      primary_color: '#0b5cff',
      logo_url: '',
      currency: 'BDT',
      service_country: 'BD',
      features: { topup: true, bundle: true, mfs: true, tracking: true, worker: true }
    };
  },

  normalize(tenant) {
    if (window.MySiteDemoStore) return window.MySiteDemoStore.normalizeTenant(tenant);
    return { ...this.fallbackTenant(), ...(tenant || {}) };
  },

  async load() {
    const stored = window.MySiteDemoStore?.load?.();
    if (stored) {
      this.tenant = this.normalize(stored);
      return this.tenant;
    }

    try {
      const response = await fetch(this.demoJsonPath(), { cache: 'no-store' });
      if (!response.ok) throw new Error('Tenant config not found');
      this.tenant = this.normalize(await response.json());
    } catch (error) {
      this.tenant = this.normalize(this.fallbackTenant());
      console.warn('Using fallback tenant config:', error.message);
    }
    return this.tenant;
  },

  save(tenant) {
    this.tenant = this.normalize(tenant);
    window.MySiteDemoStore?.save?.(this.tenant);
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
    if (tenant.subscription_status === 'SUSPENDED') return 'Suspended';
    return tenant.subscription_status || 'Unknown';
  },

  daysLeft(tenant) {
    if (!tenant?.subscription_expires_at) return '';
    const diff = new Date(tenant.subscription_expires_at).getTime() - Date.now();
    if (!Number.isFinite(diff)) return '';
    return Math.max(0, Math.ceil(diff / 86400000));
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

    document.querySelectorAll('[data-expiry-days]').forEach((el) => {
      const days = this.daysLeft(tenant);
      el.textContent = days === '' ? '-' : `${days} day${days === 1 ? '' : 's'}`;
    });

    document.querySelectorAll('[data-tenant-url]').forEach((el) => {
      el.textContent = tenant?.custom_domain || tenant?.free_url || 'https://zpayswift.com/site/demo-site';
    });

    document.querySelectorAll('[data-site-logo]').forEach((el) => {
      const logoUrl = tenant?.logo_url || tenant?.branding?.logo_url || '';
      if (logoUrl && el.tagName === 'IMG') {
        el.src = logoUrl;
        el.alt = `${tenant?.site_name || 'Tenant'} logo`;
        el.hidden = false;
      } else if (el.tagName === 'IMG') {
        el.hidden = true;
      }
    });

    document.body.classList.toggle('is-expired', this.isExpired(tenant));
  }
};
