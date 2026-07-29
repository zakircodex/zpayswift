(() => {
  'use strict';

  class ZNewsApiError extends Error {
    constructor(message, { code = 'ZNEWS_REQUEST_FAILED', status = 0, data = {} } = {}) {
      super(message || 'Request failed.');
      this.name = 'ZNewsApiError';
      this.code = code;
      this.status = status;
      this.data = data && typeof data === 'object' ? data : {};
    }
  }

  class ZNewsApiClient {
    constructor(config) {
      this.config = config;
      this.sessionToken = sessionStorage.getItem(config.sessionStorageKey) || '';
      this.persistentSessionToken = localStorage.getItem(config.persistentSessionStorageKey) || '';
      if (!this.sessionToken
        && sessionStorage.getItem(config.sessionUnlockStorageKey) === '1'
        && this.persistentSessionToken) {
        this.sessionToken = this.persistentSessionToken;
        sessionStorage.setItem(config.sessionStorageKey, this.sessionToken);
      }
      this.profile = this.readStoredProfile();
    }

    readStoredProfile() {
      const candidates = [
        localStorage.getItem(this.config.persistentProfileStorageKey),
        sessionStorage.getItem(this.config.profileStorageKey)
      ];
      for (const raw of candidates) {
        try {
          const value = JSON.parse(raw || '{}');
          if (value && typeof value === 'object' && Object.keys(value).length) return value;
        } catch (_error) {
          // Try the next storage source.
        }
      }
      return {};
    }

    setSession(token, profile = {}, { persist = true } = {}) {
      this.sessionToken = String(token || '').trim();
      this.persistentSessionToken = persist ? this.sessionToken : this.persistentSessionToken;
      this.profile = profile && typeof profile === 'object' ? profile : {};

      if (this.sessionToken) {
        sessionStorage.setItem(this.config.sessionStorageKey, this.sessionToken);
        sessionStorage.setItem(this.config.profileStorageKey, JSON.stringify(this.profile));
        sessionStorage.setItem(this.config.sessionUnlockStorageKey, '1');
        if (persist) {
          localStorage.setItem(this.config.persistentSessionStorageKey, this.sessionToken);
          localStorage.setItem(this.config.persistentProfileStorageKey, JSON.stringify(this.profile));
        }
      } else {
        sessionStorage.removeItem(this.config.sessionStorageKey);
        sessionStorage.removeItem(this.config.profileStorageKey);
        sessionStorage.removeItem(this.config.sessionUnlockStorageKey);
      }
    }

    lockSession() {
      this.sessionToken = '';
      sessionStorage.removeItem(this.config.sessionStorageKey);
      sessionStorage.removeItem(this.config.profileStorageKey);
      sessionStorage.removeItem(this.config.sessionUnlockStorageKey);
    }

    clearExpiredSession() {
      this.lockSession();
      this.persistentSessionToken = '';
      localStorage.removeItem(this.config.persistentSessionStorageKey);
    }

    clearSession({ forgetAccount = true } = {}) {
      this.lockSession();
      this.persistentSessionToken = '';
      localStorage.removeItem(this.config.persistentSessionStorageKey);
      if (forgetAccount) {
        this.profile = {};
        localStorage.removeItem(this.config.persistentProfileStorageKey);
      }
    }

    isAuthenticated() {
      return this.sessionToken !== '';
    }

    hasSavedQuickLogin() {
      return this.persistentSessionToken !== '';
    }

    getSavedProfile() {
      return this.profile && typeof this.profile === 'object' ? this.profile : {};
    }

    getDeviceId() {
      let deviceId = localStorage.getItem(this.config.deviceStorageKey) || '';
      if (!deviceId) {
        deviceId = this.idempotencyKey('device');
        localStorage.setItem(this.config.deviceStorageKey, deviceId);
      }
      return deviceId;
    }

    url(path, params = null) {
      const base = String(this.config.apiBase || '/api').replace(/\/$/, '');
      const normalized = String(path || '').replace(/^\//, '');
      const url = new URL(`${base}/${normalized}`, window.location.origin);
      if (params && typeof params === 'object') {
        Object.entries(params).forEach(([key, value]) => {
          if (value !== undefined && value !== null && String(value) !== '') {
            url.searchParams.set(key, String(value));
          }
        });
      }
      return url.toString();
    }

    async request(path, {
      method = 'GET',
      body = undefined,
      params = null,
      authenticated = false,
      authToken = '',
      appKey = true,
      signal = undefined
    } = {}) {
      const headers = new Headers({ Accept: 'application/json' });
      let requestBody = body;

      if (appKey && this.config.appKey) {
        headers.set('X-APP-KEY', this.config.appKey);
      }
      const explicitToken = String(authToken || '').trim();
      const token = explicitToken || (authenticated ? this.sessionToken : '');
      if (authenticated && !token) {
        throw new ZNewsApiError('Please sign in first.', {
          code: 'SESSION_EXPIRED',
          status: 401
        });
      }
      if (token) {
        headers.set('Authorization', `Bearer ${token}`);
        headers.set('X-SESSION-TOKEN', token);
      }
      if (body !== undefined && !(body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
        requestBody = JSON.stringify(body);
      }

      let response;
      try {
        response = await fetch(this.url(path, params), {
          method,
          headers,
          body: requestBody,
          credentials: 'same-origin',
          cache: method === 'GET' ? 'no-store' : 'default',
          signal
        });
      } catch (_error) {
        throw new ZNewsApiError('Network connection failed.', {
          code: 'NETWORK_FAILURE'
        });
      }

      const contentType = response.headers.get('content-type') || '';
      let payload = null;
      if (contentType.includes('application/json')) {
        try {
          payload = await response.json();
        } catch (_error) {
          payload = null;
        }
      }

      if (!payload || typeof payload !== 'object') {
        throw new ZNewsApiError('The server returned an invalid response.', {
          code: 'MALFORMED_RESPONSE',
          status: response.status
        });
      }

      const ok = payload.ok === true || payload.success === true;
      if (!response.ok || !ok) {
        const code = String(payload.code || 'ZNEWS_REQUEST_FAILED');
        if (code === 'SESSION_EXPIRED'
          || code === 'DEVICE_REPLACED'
          || response.status === 401) {
          this.clearExpiredSession();
        }
        throw new ZNewsApiError(String(payload.message || 'Request failed.'), {
          code,
          status: response.status,
          data: payload.data || {}
        });
      }

      return payload;
    }

    verifyPassword(payload) {
      return this.request('auth/verify_password.php', { method: 'POST', body: payload });
    }

    verifyPin(payload) {
      return this.request('auth/verify_pin.php', { method: 'POST', body: payload });
    }

    pinLogin(payload) {
      if (!this.persistentSessionToken) {
        return Promise.reject(new ZNewsApiError('Saved login has expired.', {
          code: 'SESSION_EXPIRED',
          status: 401
        }));
      }
      return this.request('auth/pin_login.php', {
        method: 'POST',
        body: payload,
        authToken: this.persistentSessionToken
      });
    }

    sendLoginOtp(preAuthToken) {
      return this.request('auth/login_send_otp.php', {
        method: 'POST',
        body: { pre_auth_token: preAuthToken }
      });
    }

    verifyLoginOtp(payload) {
      return this.request('auth/user_login_verify_otp.php', {
        method: 'POST',
        body: { ...payload, trust_device: true }
      });
    }

    publicFeed(cursor = '') {
      return this.request('znews/public/feed.php', {
        params: { limit: this.config.feedPageSize, cursor },
        appKey: false
      });
    }

    publicPost(postId) {
      return this.request('znews/public/post.php', {
        params: { post_id: postId },
        appKey: false
      });
    }

    myPosts(cursor = '') {
      return this.request('znews/posts/mine.php', {
        params: { limit: this.config.creatorPostPageSize, cursor },
        authenticated: true
      });
    }

    uploadMedia(file) {
      const body = new FormData();
      body.append('image', file);
      body.append('idempotency_key', this.idempotencyKey('media'));
      return this.request('znews/media/upload.php', {
        method: 'POST', body, authenticated: true
      });
    }

    createPost({ text = '', mediaId = '' }) {
      return this.request('znews/posts/create.php', {
        method: 'POST',
        authenticated: true,
        body: {
          text,
          media_id: mediaId,
          idempotency_key: this.idempotencyKey('post')
        }
      });
    }

    setLike(postId, liked) {
      return this.request('znews/likes/set.php', {
        method: 'POST',
        authenticated: true,
        body: {
          post_id: postId,
          liked: liked === true,
          idempotency_key: this.idempotencyKey(liked ? 'like' : 'unlike')
        }
      });
    }

    recordShare(postId, channel = 'COPY_LINK') {
      return this.request('znews/shares/create.php', {
        method: 'POST',
        authenticated: true,
        body: {
          post_id: postId,
          channel,
          idempotency_key: this.idempotencyKey('share')
        }
      });
    }

    comments(postId, cursor = '') {
      return this.request('znews/comments/list.php', {
        params: { post_id: postId, limit: this.config.commentPageSize, cursor },
        appKey: false
      });
    }

    createComment(postId, text) {
      return this.request('znews/comments/create.php', {
        method: 'POST',
        authenticated: true,
        body: {
          post_id: postId,
          text,
          idempotency_key: this.idempotencyKey('comment')
        }
      });
    }

    balanceSummary() {
      return this.request('znews/balance/summary.php', { authenticated: true });
    }

    balanceLedger(cursor = '') {
      return this.request('znews/balance/ledger.php', {
        params: { currency: 'BDT', limit: 30, cursor },
        authenticated: true
      });
    }

    requestTransfer(amountMicros) {
      return this.request('znews/transfers/create.php', {
        method: 'POST',
        authenticated: true,
        body: {
          currency: 'BDT',
          source_amount_micros: amountMicros,
          idempotency_key: this.idempotencyKey('transfer')
        }
      });
    }

    startView(postId) {
      return this.request('znews/views/start.php', {
        method: 'POST',
        appKey: false,
        body: { post_id: postId, idempotency_key: this.idempotencyKey('view') }
      });
    }

    heartbeatView(viewId, viewToken) {
      return this.request('znews/views/heartbeat.php', {
        method: 'POST',
        appKey: false,
        body: { view_id: viewId, view_token: viewToken }
      });
    }

    completeView(viewId, viewToken) {
      return this.request('znews/views/complete.php', {
        method: 'POST',
        appKey: false,
        body: { view_id: viewId, view_token: viewToken }
      });
    }

    idempotencyKey(prefix) {
      const random = window.crypto?.randomUUID
        ? window.crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
      return `znews-web-${prefix}-${random}`;
    }
  }

  window.ZNewsApiError = ZNewsApiError;
  window.ZNewsApiClient = ZNewsApiClient;
})();
