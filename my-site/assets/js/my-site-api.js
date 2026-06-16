// My-Site API client. Safe public reads only by default.
// Tenant create is protected by backend X-APP-KEY and is not called from public demo UI.
(function attachMySiteApi() {
  const API_BASE = '/api/my_site';

  async function request(path, options) {
    const response = await fetch(API_BASE + path, {
      cache: 'no-store',
      headers: { 'Accept': 'application/json' },
      ...(options || {})
    });
    const json = await response.json().catch(function () { return null; });
    if (!response.ok || !json || json.ok !== true) {
      const code = json?.code || 'API_ERROR';
      const message = json?.message || 'My-Site API request failed';
      throw new Error(code + ': ' + message);
    }
    return json.data || {};
  }

  function queryFromTenantRef(ref) {
    const params = new URLSearchParams();
    if (ref?.tenant_id) params.set('tenant_id', ref.tenant_id);
    if (ref?.slug) params.set('slug', ref.slug);
    if (ref?.domain) params.set('domain', ref.domain);
    return params.toString();
  }

  async function getTenant(ref) {
    const query = queryFromTenantRef(ref);
    if (!query) throw new Error('TENANT_REF_REQUIRED');
    return request('/tenant_get.php?' + query);
  }

  async function checkSubscription(ref) {
    const query = queryFromTenantRef(ref);
    if (!query) throw new Error('TENANT_REF_REQUIRED');
    return request('/subscription_check.php?' + query);
  }

  function refFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const ref = {
      tenant_id: params.get('tenant_id') || '',
      slug: params.get('slug') || params.get('site_slug') || '',
      domain: params.get('domain') || ''
    };
    return (ref.tenant_id || ref.slug || ref.domain) ? ref : null;
  }

  window.MySiteAPI = {
    getTenant,
    checkSubscription,
    refFromUrl
  };
})();
