(() => {
  'use strict';

  class BirthdayApiError extends Error {
    constructor(message, { code = 'BIRTHDAY_REQUEST_FAILED', status = 0, data = {} } = {}) {
      super(message || 'Request failed.');
      this.name = 'BirthdayApiError';
      this.code = code;
      this.status = status;
      this.data = data && typeof data === 'object' ? data : {};
    }
  }

  class BirthdayApiClient {
    constructor() {
      this.base = '/api/znews/birthday';
      this.appKey = 'zawtopup';
      this.sessionKey = 'znews_session_v1';
      this.profileKey = 'znews_profile_v1';
    }

    get sessionToken() {
      return String(sessionStorage.getItem(this.sessionKey) || '').trim();
    }

    setSession(token, profile = {}) {
      const normalized = String(token || '').trim();
      if (!normalized) {
        sessionStorage.removeItem(this.sessionKey);
        sessionStorage.removeItem(this.profileKey);
        return;
      }
      sessionStorage.setItem(this.sessionKey, normalized);
      sessionStorage.setItem(this.profileKey, JSON.stringify(profile || {}));
    }

    async request(path, { method = 'GET', params = null, body, form, authenticated = false, draftToken = '', timeout = 20000, networkRetries = 0 } = {}) {
      const url = new URL(`${this.base}/${String(path || '').replace(/^\//, '')}`, window.location.origin);
      Object.entries(params || {}).forEach(([key, value]) => {
        if (value !== undefined && value !== null && String(value) !== '') url.searchParams.set(key, String(value));
      });
      const headers = new Headers({ Accept: 'application/json' });
      if (draftToken) headers.set('X-Draft-Token', draftToken);
      if (authenticated) {
        if (!this.sessionToken) throw new BirthdayApiError('Z-Pay login is required.', { code: 'SESSION_EXPIRED', status: 401 });
        headers.set('X-APP-KEY', this.appKey);
        headers.set('X-SESSION-TOKEN', this.sessionToken);
        headers.set('Authorization', `Bearer ${this.sessionToken}`);
      }
      let payload;
      if (form instanceof FormData) {
        payload = form;
      } else if (body !== undefined) {
        headers.set('Content-Type', 'application/json');
        payload = JSON.stringify(body);
      }
      let response;
      let fetchError = null;
      const retries = Math.max(0, Math.min(2, Number(networkRetries) || 0));
      for (let attempt = 0; attempt <= retries; attempt += 1) {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), timeout);
        try {
          response = await fetch(url, { method, headers, body: payload, credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
          fetchError = null;
          break;
        } catch (error) {
          fetchError = error;
        } finally {
          window.clearTimeout(timer);
        }
        if (attempt < retries) await new Promise(resolve => window.setTimeout(resolve, 450 * (attempt + 1)));
      }
      if (!response) {
        throw new BirthdayApiError(fetchError?.name === 'AbortError' ? 'The request timed out. Please try again.' : 'Network connection failed.', {
          code: fetchError?.name === 'AbortError' ? 'REQUEST_TIMEOUT' : 'NETWORK_FAILURE'
        });
      }
      let json;
      try { json = await response.json(); } catch (_error) { json = null; }
      if (!json || typeof json !== 'object') {
        throw new BirthdayApiError('The server returned an invalid response.', { code: 'MALFORMED_RESPONSE', status: response.status });
      }
      if (!response.ok || (json.ok !== true && json.success !== true)) {
        if (response.status === 401 || json.code === 'SESSION_EXPIRED') this.setSession('');
        throw new BirthdayApiError(String(json.message || 'Request failed.'), {
          code: String(json.code || 'BIRTHDAY_REQUEST_FAILED'), status: response.status, data: json.data || {}
        });
      }
      return json.data || {};
    }

    config() { return this.request('catalog.php', { networkRetries: 1 }); }
    createDraft(payload) { return this.request('draft.php', { method: 'POST', body: payload, networkRetries: 1 }); }
    draft(id, token) { return this.request('draft.php', { params: { id }, draftToken: token, networkRetries: 1 }); }
    async draftPhotoBlob(url, token) {
      let response;
      let fetchError = null;
      for (let attempt = 0; attempt < 2; attempt += 1) {
        try {
          response = await fetch(url, { headers: { 'X-Draft-Token': token }, credentials: 'same-origin', cache: 'no-store' });
          fetchError = null;
          break;
        } catch (error) {
          fetchError = error;
          if (attempt === 0) await new Promise(resolve => window.setTimeout(resolve, 450));
        }
      }
      if (!response) throw new BirthdayApiError(fetchError?.message || 'Preview photo connection failed.', { code: 'PHOTO_NETWORK_FAILURE' });
      if (!response.ok) throw new BirthdayApiError('Preview photo could not be loaded.', { code: 'PHOTO_LOAD_FAILED', status: response.status });
      return response.blob();
    }
    async draftAudioBuffer(url, token) {
      let response;
      let fetchError = null;
      for (let attempt = 0; attempt < 2; attempt += 1) {
        try {
          response = await fetch(url, { headers: { 'X-Draft-Token': token }, credentials: 'same-origin', cache: 'no-store' });
          fetchError = null;
          break;
        } catch (error) {
          fetchError = error;
          if (attempt === 0) await new Promise(resolve => window.setTimeout(resolve, 450));
        }
      }
      if (!response) throw new BirthdayApiError(fetchError?.message || 'Preview audio connection failed.', { code: 'AUDIO_NETWORK_FAILURE' });
      if (!response.ok) throw new BirthdayApiError('Preview audio could not be loaded.', { code: 'AUDIO_LOAD_FAILED', status: response.status });
      return response.arrayBuffer();
    }
    uploadPhoto(form, authenticated = false) { return this.request('media_upload.php', { method: 'POST', form, authenticated, timeout: 60000, networkRetries: 2 }); }
    uploadAudio(form, authenticated = false) { return this.request('audio_upload.php', { method: 'POST', form, authenticated, timeout: 45000, networkRetries: 1 }); }
    generate(payload) { return this.request('generate.php', { method: 'POST', body: payload, timeout: 30000, networkRetries: 1 }); }
    universe(slug) { return this.request('public.php', { params: { slug }, networkRetries: 1 }); }
    event(slug, eventType, metadata = {}) { return this.request('event.php', { method: 'POST', body: { slug, event_type: eventType, metadata }, timeout: 10000 }); }
    report(slug, reason, details) { return this.request('report.php', { method: 'POST', body: { slug, reason, details } }); }
    ad(params, draftToken = '') { return this.request('ad.php', { params, draftToken, timeout: 12000 }); }
    manage(slug) { return this.request('manage.php', { params: { slug }, authenticated: true }); }
    manageAction(payload) { return this.request('manage.php', { method: 'POST', body: payload, authenticated: true }); }

    async exchangeHandoff(code) {
      const url = new URL('/api/znews/auth/handoff.php', window.location.origin);
      const response = await fetch(url, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-APP-KEY': this.appKey },
        body: JSON.stringify({ code })
      });
      const json = await response.json();
      if (!response.ok || !json?.ok) throw new BirthdayApiError(json?.message || 'Login handoff failed.', { code: json?.code, status: response.status });
      this.setSession(json.data?.session_token || '', json.data?.user || {});
      return json.data || {};
    }
  }

  window.BirthdayApiError = BirthdayApiError;
  window.BirthdayApi = new BirthdayApiClient();
})();
