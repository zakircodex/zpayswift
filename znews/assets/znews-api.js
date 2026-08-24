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
      this.profile = this.readStoredProfile();
      this.defaultTimeoutMs = Math.max(3000, Number(config.requestTimeoutMs || 12000));
    }

    readStoredProfile() {
      try {
        const value = JSON.parse(sessionStorage.getItem(this.config.profileStorageKey) || '{}');
        return value && typeof value === 'object' ? value : {};
      } catch (_error) {
        return {};
      }
    }

    setSession(token, profile = {}) {
      this.sessionToken = String(token || '').trim();
      this.profile = profile && typeof profile === 'object' ? profile : {};

      if (this.sessionToken) {
        sessionStorage.setItem(this.config.sessionStorageKey, this.sessionToken);
        sessionStorage.setItem(this.config.profileStorageKey, JSON.stringify(this.profile));
      } else {
        sessionStorage.removeItem(this.config.sessionStorageKey);
        sessionStorage.removeItem(this.config.profileStorageKey);
      }
    }

    clearSession() {
      this.setSession('', {});
    }

    isAuthenticated() {
      return this.sessionToken !== '';
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
      appKey = true,
      signal = undefined,
      timeoutMs = this.defaultTimeoutMs
    } = {}) {
      const headers = new Headers({ Accept: 'application/json' });
      let requestBody = body;

      if (appKey && this.config.appKey) {
        headers.set('X-APP-KEY', this.config.appKey);
      }
      if (authenticated) {
        if (!this.sessionToken) {
          throw new ZNewsApiError('Open Z Sky 24 from your Z-Pay dashboard.', {
            code: 'ZNEWS_DASHBOARD_ACCESS_REQUIRED',
            status: 401
          });
        }
        headers.set('Authorization', `Bearer ${this.sessionToken}`);
        headers.set('X-SESSION-TOKEN', this.sessionToken);
      }
      if (body !== undefined && !(body instanceof FormData)) {
        headers.set('Content-Type', 'application/json');
        requestBody = JSON.stringify(body);
      }

      const controller = new AbortController();
      const boundedTimeout = Math.max(1000, Number(timeoutMs || this.defaultTimeoutMs));
      let timedOut = false;
      let detachExternalAbort = () => {};
      const timeoutId = window.setTimeout(() => {
        timedOut = true;
        controller.abort();
      }, boundedTimeout);

      if (signal) {
        if (signal.aborted) {
          controller.abort();
        } else {
          const forwardAbort = () => controller.abort();
          signal.addEventListener('abort', forwardAbort, { once: true });
          detachExternalAbort = () => signal.removeEventListener('abort', forwardAbort);
        }
      }

      let response;
      try {
        response = await fetch(this.url(path, params), {
          method,
          headers,
          body: requestBody,
          credentials: 'same-origin',
          cache: method === 'GET' ? 'no-store' : 'default',
          signal: controller.signal
        });
      } catch (error) {
        if (timedOut) {
          throw new ZNewsApiError('The request timed out. Please try again.', {
            code: 'REQUEST_TIMEOUT'
          });
        }
        if (error?.name === 'AbortError') {
          throw new ZNewsApiError('The request was cancelled.', {
            code: 'REQUEST_CANCELLED'
          });
        }
        throw new ZNewsApiError('Network connection failed.', {
          code: 'NETWORK_FAILURE'
        });
      } finally {
        window.clearTimeout(timeoutId);
        detachExternalAbort();
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
          || code === 'ZNEWS_AUTH_REQUIRED'
          || response.status === 401) {
          this.clearSession();
        }
        throw new ZNewsApiError(String(payload.message || 'Request failed.'), {
          code,
          status: response.status,
          data: payload.data || {}
        });
      }

      return payload;
    }

    exchangeHandoff(code, options = {}) {
      return this.request('znews/auth/handoff.php', {
        method: 'POST',
        body: { code },
        appKey: true,
        timeoutMs: 15000,
        ...options
      });
    }

    validateCreatorSession(options = {}) {
      return this.request('znews/auth/session.php', {
        authenticated: true,
        appKey: true,
        timeoutMs: 6000,
        ...options
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

    publicCreatorPolicy() {
      return this.request('znews/public/policy.php', { appKey: false });
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
        method: 'POST', body, authenticated: true, timeoutMs: 30000
      });
    }

    createPost({ title = '', text = '', mediaId = '' }) {
      return this.request('znews/posts/create.php', {
        method: 'POST',
        authenticated: true,
        body: {
          title,
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

    likeStatus(postId) {
      return this.request('znews/likes/status.php', {
        params: { post_id: postId },
        authenticated: true
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

    startView(postId, idempotencyKey = '') {
      return this.request('znews/views/start.php', {
        method: 'POST',
        appKey: false,
        authenticated: this.isAuthenticated(),
        body: {
          post_id: postId,
          idempotency_key: idempotencyKey || this.idempotencyKey('view')
        }
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
