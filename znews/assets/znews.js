(() => {
  'use strict';

  if (window.ZNEWS_APP_INITIALIZED === true) return;
  window.ZNEWS_APP_INITIALIZED = true;

  const config = window.ZNEWS_CONFIG;
  const api = window.ZNEWS_API_CLIENT || new window.ZNewsApiClient(config);
  const requestScheduler = window.ZNEWS_REQUEST_SCHEDULER;
  const requestPriority = window.ZNewsRequestScheduler?.PRIORITY || {
    FEED: 0, MEDIA: 1, LIKE: 2, ANALYTICS: 3
  };
  const FEED_MEDIA_TIMEOUT_MS = 70000;
  const state = {
    route: 'feed',
    feedCursor: '',
    feedHasMore: false,
    feedLoading: false,
    feedCategory: '',
    feedDirty: false,
    mineCursor: '',
    mineHasMore: false,
    mineLoading: false,
    currentPostId: '',
    openingPostId: '',
    viewStartingPostId: '',
    viewSession: null,
    balanceMicros: 0,
    transferMinimumMicros: 200_000_000,
    authStage: 'credentials',
    authContext: {},
    minePosts: new Map(),
    localLikes: new Set(),
    lastBoundaryBackAt: 0
  };

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  const els = {
    feedList: $('#feedList'),
    mineList: $('#mineList'),
    mineLoadMore: $('#mineLoadMoreButton'),
    ledgerList: $('#ledgerList'),
    loadMore: $('#loadMoreButton'),
    sessionButton: $('#sessionButton'),
    authDialog: $('#authDialog'),
    authForm: $('#authForm'),
    authTitle: $('#authTitle'),
    authDescription: $('#authDescription'),
    authSubmit: $('#authSubmit'),
    authError: $('#authError'),
    credentialFields: $('#credentialFields'),
    otpFields: $('#otpFields'),
    postDialog: $('#postDialog'),
    postDetail: $('#postDetail'),
    postDialogClose: $('#postDialogClose'),
    commentForm: $('#commentForm'),
    commentText: $('#commentText'),
    commentList: $('#commentList'),
    createPostForm: $('#createPostForm'),
    feedCategories: $('#feedCategories'),
    postCategory: $('#postCategory'),
    postTitle: $('#postTitle'),
    postTitleCount: $('#postTitleCount'),
    postText: $('#postText'),
    postTextCount: $('#postTextCount'),
    postImage: $('#postImage'),
    imagePreview: $('#imagePreview'),
    balanceAmount: $('#balanceAmount'),
    miniBalance: $('#miniBalance'),
    balanceStatus: $('#balanceStatus'),
    creatorAdRate: $('#creatorAdRate'),
    creatorAdRateNote: $('#creatorAdRateNote'),
    transferButton: $('#transferButton'),
    sidebarName: $('#sidebarName'),
    sidebarMeta: $('#sidebarMeta'),
    sidebarAvatar: $('#sidebarAvatar'),
    composerAvatar: $('#composerAvatar'),
    createComposerAvatar: $('#createComposerAvatar'),
    createComposerName: $('#createComposerName'),
    createPostSubmit: $('#createPostSubmit'),
    createPostSubmitBottom: $('#createPostSubmitBottom'),
    toastRegion: $('#toastRegion'),
    announcement: $('#announcement')
  };
  let progressiveFeed = null;
  let likeStateObserver = null;
  let feedMediaObserver = null;
  const observedLikeCards = new WeakSet();
  const likeStateRequests = new WeakMap();
  const observedMediaCards = new WeakSet();
  const feedMediaCache = new Map();
  const feedMediaObjectUrls = new Set();

  function scheduleRequest(priority, task, options = {}) {
    if (requestScheduler && typeof requestScheduler.schedule === 'function') {
      return requestScheduler.schedule(priority, task, options);
    }
    return Promise.resolve().then(() => task({ signal: undefined, priority }));
  }

  function text(value) {
    return String(value ?? '');
  }

  function hasVerifiedSession() {
    return window.ZNEWS_AUTH_VERIFIED === true && api.isAuthenticated();
  }

  function markBoot(name) {
    window.ZNEWS_BOOTSTRAP?.mark?.(name);
  }

  function escapeHtml(value) {
    return text(value).replace(/[&<>'"]/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[char]);
  }

  const richText = window.ZNewsRichText;
  if (!richText) throw new Error('Z Sky rich-text module is unavailable.');
  const uiFeedback = window.ZNewsUiFeedback || {
    beginProgress: () => () => {},
    setButtonLoading: (button, busy, label) => setBusy(button, busy, label)
  };

  function setCreateMutationState(busy, stage = 'posting') {
    const labels = {
      optimizing: ['OPTIMIZING…', 'Optimizing photo…'],
      uploading: ['UPLOADING…', 'Uploading photo…'],
      publishing: ['PUBLISHING…', 'Publishing…'],
      posting: ['POSTING…', 'Posting…']
    }[stage] || ['POSTING…', 'Posting…'];
    uiFeedback.setButtonLoading(els.createPostSubmit, busy, labels[0], { spinner: false });
    uiFeedback.setButtonLoading(els.createPostSubmitBottom, busy, labels[1], { spinner: true });
  }

  function safeUrl(value) {
    const raw = text(value).trim();
    if (!raw) return '';
    try {
      const url = new URL(config.resolveProfilePhotoUrl(raw), window.location.origin);
      if (url.protocol !== 'https:' && url.origin !== window.location.origin) return '';
      return url.toString();
    } catch (_error) {
      return '';
    }
  }

  function formatTime(seconds) {
    const timestamp = Number(seconds || 0) * 1000;
    if (!timestamp) return '';
    const date = new Date(timestamp);
    const diff = Date.now() - timestamp;
    if (diff >= 0 && diff < 60_000) return 'Just now';
    if (diff >= 0 && diff < 3_600_000) return `${Math.max(1, Math.floor(diff / 60_000))}m`;
    if (diff >= 0 && diff < 86_400_000) return `${Math.floor(diff / 3_600_000)}h`;
    return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: date.getFullYear() !== new Date().getFullYear() ? 'numeric' : undefined }).format(date);
  }

  function formatBdtMicros(micros) {
    const value = Math.max(0, Number(micros || 0)) / 1_000_000;
    return `৳${value.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 6 })}`;
  }

  function toast(message, type = 'success') {
    const item = document.createElement('div');
    item.className = `toast ${type}`;
    item.textContent = text(message);
    els.toastRegion.appendChild(item);
    window.setTimeout(() => item.remove(), 4200);
  }

  function showAnnouncement(message = '') {
    els.announcement.textContent = text(message);
    els.announcement.hidden = !message;
  }

  function errorMessage(error) {
    const map = {
      SESSION_EXPIRED: 'Your session has expired. Please sign in again.',
      ZNEWS_POST_NOT_FOUND: 'This post is no longer available.',
      ZNEWS_COMMENT_MESSAGE_REQUIRED: 'Write a comment first.',
      ZNEWS_TRANSFER_MINIMUM_NOT_MET: 'Minimum transfer amount is ৳200.',
      ZNEWS_TRANSFER_INSUFFICIENT_BALANCE: 'Your available Z Sky 24 balance is not enough.',
      NETWORK_FAILURE: 'Network connection failed. Please try again.',
      MALFORMED_RESPONSE: 'The server returned an invalid response.'
    };
    return map[error?.code] || error?.message || 'Something went wrong.';
  }

  function setBusy(button, busy, busyLabel = 'Please wait…') {
    if (!(button instanceof HTMLButtonElement)) return;
    if (busy) {
      button.dataset.originalLabel = button.textContent;
      button.textContent = busyLabel;
      button.disabled = true;
    } else {
      button.textContent = button.dataset.originalLabel || button.textContent;
      button.disabled = false;
    }
  }

  function profileName() {
    return text(api.profile.name || api.profile.NAME || api.profile.display_name || api.profile.phone || 'Z-Pay user').trim();
  }

  function profilePhoto() {
    return safeUrl(api.profile.profile_photo_url || api.profile.photo_url || api.profile.PROFILE || '');
  }

  function setAvatar(element, name, photoUrl = '') {
    if (!element) return;
    element.textContent = '';
    const safePhoto = safeUrl(photoUrl);
    if (safePhoto) {
      const img = document.createElement('img');
      img.src = safePhoto;
      img.alt = '';
      img.referrerPolicy = 'no-referrer';
      element.appendChild(img);
    } else {
      element.textContent = text(name || 'Z').trim().charAt(0).toUpperCase() || 'Z';
    }
  }

  function refreshSessionUi() {
    const signedIn = hasVerifiedSession();
    els.sessionButton.textContent = signedIn ? 'Sign out' : 'Sign in';
    const name = signedIn ? profileName() : 'Guest reader';
    els.sidebarName.textContent = name;
    els.sidebarMeta.textContent = signedIn ? 'Create posts and join conversations.' : 'Sign in to post and join conversations.';
    setAvatar(els.sidebarAvatar, name, signedIn ? profilePhoto() : '');
    setAvatar(els.composerAvatar, name, signedIn ? profilePhoto() : '');
    setAvatar(els.createComposerAvatar, name, signedIn ? profilePhoto() : '');
    if (els.createComposerName) els.createComposerName.textContent = name;
  }

  function requireSession() {
    if (hasVerifiedSession()) return true;
    openAuth();
    return false;
  }

  function openAuth() {
    state.authStage = 'credentials';
    state.authContext = {};
    els.credentialFields.hidden = false;
    els.otpFields.hidden = true;
    els.authError.hidden = true;
    els.authTitle.textContent = 'Open Z Sky 24 from Z-Pay';
    els.authDescription.textContent = 'Use your existing Z-Pay phone number, password and PIN.';
    els.authSubmit.textContent = 'Continue';
    if (!els.authDialog.open) els.authDialog.showModal();
  }

  async function submitAuth(event) {
    event.preventDefault();
    els.authError.hidden = true;
    setBusy(els.authSubmit, true, state.authStage === 'otp' ? 'Verifying…' : 'Signing in…');

    try {
      if (state.authStage === 'credentials') {
        const country = $('#authCountry').value;
        const phone = $('#authPhone').value.trim();
        const password = $('#authPassword').value;
        const pin = $('#authPin').value;
        const deviceId = localStorage.getItem('znews_web_device_id') || api.idempotencyKey('device');
        localStorage.setItem('znews_web_device_id', deviceId);
        const base = {
          device_id: deviceId,
          device_name: 'Z Sky 24 Web',
          app_version: 'znews-web-1'
        };

        const passwordResult = await api.verifyPassword({
          phone_country: country,
          phone,
          password,
          ...base
        });
        const preAuthToken = text(passwordResult.data?.pre_auth_token);
        if (!preAuthToken) throw new window.ZNewsApiError('Password verification did not return a token.');

        const pinResult = await api.verifyPin({ pre_auth_token: preAuthToken, pin, ...base });
        const directSession = text(pinResult.data?.session_token);
        const profile = pinResult.data?.user || passwordResult.data?.user || { phone, phone_country: country };

        if (directSession) {
          finishAuth(directSession, profile);
          return;
        }

        const otpResult = await api.sendLoginOtp(preAuthToken);
        const otpRequestId = text(otpResult.data?.otp_request_id);
        if (!otpRequestId) throw new window.ZNewsApiError('OTP could not be sent.');

        state.authStage = 'otp';
        state.authContext = { preAuthToken, otpRequestId, deviceId, profile };
        els.credentialFields.hidden = true;
        els.otpFields.hidden = false;
        els.authTitle.textContent = 'Enter SMS OTP';
        els.authDescription.textContent = 'We sent a verification code to your Z-Pay phone number.';
        els.authSubmit.textContent = 'Verify and sign in';
        $('#authOtp').focus();
        return;
      }

      const otp = $('#authOtp').value.trim();
      const result = await api.verifyLoginOtp({
        pre_auth_token: state.authContext.preAuthToken,
        otp_request_id: state.authContext.otpRequestId,
        otp,
        trust_device: false,
        device_id: state.authContext.deviceId,
        device_name: 'Z Sky 24 Web'
      });
      const session = text(result.data?.session_token);
      if (!session) throw new window.ZNewsApiError('Login did not return a session token.');
      finishAuth(session, result.data?.user || state.authContext.profile);
    } catch (error) {
      els.authError.textContent = errorMessage(error);
      els.authError.hidden = false;
    } finally {
      setBusy(els.authSubmit, false);
    }
  }

  function finishAuth(sessionToken, profile) {
    api.setSession(sessionToken, profile || {});
    window.ZNEWS_AUTH_VERIFIED = true;
    window.dispatchEvent(new CustomEvent('znews:auth-ready', {
      detail: { ready: true, authenticated: true, interactiveLogin: true }
    }));
    refreshSessionUi();
    els.authDialog.close();
    toast('Signed in successfully.');
    if (state.route === 'mine') loadMyPosts();
    if (state.route === 'performance') {
      window.dispatchEvent(new CustomEvent('znews:weekly-performance-open'));
    }
  }

  function appHistoryState(view, extra = {}) {
    return { znewsAppEntry: true, znewsView: view, ...extra };
  }

  function syncViewMetadata(view) {
    const policy = view === 'policy';
    const performance = view === 'performance';
    const canonical = policy ? config.canonicalUrl('policy') : config.canonicalUrl();
    document.title = policy
      ? 'Creator policy | Z Sky 24'
      : (performance ? 'Weekly Performance | Z Sky 24' : 'Z Sky 24');
    document.querySelector('link[rel="canonical"]')?.setAttribute('href', canonical);
    document.querySelector('meta[property="og:url"]')?.setAttribute('content', canonical);
    document.querySelector('meta[property="og:title"]')?.setAttribute('content', policy
      ? 'Creator policy | Z Sky 24'
      : (performance ? 'Weekly Performance | Z Sky 24' : 'Z Sky 24'));
    document.querySelector('meta[name="description"]')?.setAttribute('content', policy
      ? 'How Z Sky 24 verifies creator engagement and weekly reviews.'
      : (performance
        ? 'Track your verified weekly Z Sky 24 engagement.'
        : 'Z Sky 24 — News, stories and community updates.'));
  }

  function initializeAppHistory(route) {
    if (history.state?.znewsAppEntry === true || history.state?.znewsBoundary === true) return;
    const initialView = route.kind === 'policy' ? 'policy' : 'feed';
    const initialPath = route.kind === 'post'
      ? config.publicPath('post', route.id)
      : (route.kind === 'policy' ? config.publicPath('policy') : config.publicPath());
    history.replaceState({ znewsBoundary: true, znewsView: 'feed' }, '', config.publicPath());
    history.pushState(appHistoryState(initialView, route.kind === 'post'
      ? { postId: route.id, znewsPostOverlay: true }
      : {}), '', initialPath);
  }

  function handleAppBoundaryBack() {
    const now = Date.now();
    if (now - state.lastBoundaryBackAt <= 2000) {
      state.lastBoundaryBackAt = 0;
      history.back();
      return;
    }
    state.lastBoundaryBackAt = now;
    history.pushState(appHistoryState('feed'), '', config.publicPath());
    routeTo('feed', { syncHistory: false });
    toast('Press Back again to return to Z-Pay.');
  }

  function routeTo(route, { syncHistory = true } = {}) {
    const allowed = ['feed', 'create', 'mine', 'performance', 'policy'];
    const next = allowed.includes(route) ? route : 'feed';
    if (['create', 'mine', 'performance'].includes(next) && !requireSession()) return;
    if (els.postDialog.open) closePost({ syncHistory: false });
    state.route = next;
    syncViewMetadata(next);
    document.documentElement.dataset.znewsRoute = next;
    $$('.view').forEach((view) => view.classList.toggle('active', view.dataset.view === next));
    $$('[data-route]').forEach((button) => button.classList.toggle('active', button.dataset.route === next));
    window.scrollTo({ top: 0, behavior: next === 'create' ? 'auto' : 'smooth' });
    if (next === 'mine') loadMyPosts();
    if (next === 'feed' && state.feedDirty) {
      state.feedDirty = false;
      progressiveFeed?.destroy?.();
      progressiveFeed = null;
      void loadFeed();
    }
    if (next === 'performance') {
      window.dispatchEvent(new CustomEvent('znews:weekly-performance-open'));
    }
    if (next === 'policy') loadCreatorPolicy();
    if (syncHistory) {
      state.lastBoundaryBackAt = 0;
      const expectedKind = next === 'policy' ? 'policy' : 'feed';
      const alreadyCurrent = config.parseRoute().kind === expectedKind
        && history.state?.znewsAppEntry === true
        && history.state?.znewsView === next;
      if (!alreadyCurrent) history.pushState(
        appHistoryState(next),
        '',
        next === 'policy' ? config.publicPath('policy') : config.publicPath()
      );
    }
  }

  function postImage(post) {
    return safeUrl(post.image_url || '');
  }

  function deferredMediaAttributes(url, group, priority = false) {
    return `data-media-src="${escapeHtml(url)}" data-media-group="${escapeHtml(group)}" loading="lazy" decoding="async"${priority ? ' fetchpriority="high"' : ''}`;
  }

  function categoryLabel(category) {
    return ({
      INTERNATIONAL_NEWS: 'International news',
      BD_NEWS: 'BD news',
      MOBILE_PRICING: 'Mobile pricing'
    })[text(category).toUpperCase()] || '';
  }

  function avatarMarkup(name, photo, { deferred = false, group = 'avatar' } = {}) {
    const url = safeUrl(photo);
    if (url) {
      const source = deferred
        ? deferredMediaAttributes(url, group)
        : `src="${escapeHtml(url)}" loading="lazy" decoding="async"`;
      return `<span class="avatar"><img ${source} alt="" width="44" height="44" referrerpolicy="no-referrer"></span>`;
    }
    return `<span class="avatar">${escapeHtml(text(name).charAt(0).toUpperCase() || 'Z')}</span>`;
  }

  function postMarkup(post, { detail = false, creatorMode = false, feed = false, priority = false } = {}) {
    const id = text(post.post_id);
    const name = text(post.creator_name || 'Z Sky 24 creator');
    const image = postImage(post);
    const title = text(post.title).trim();
    const body = text(post.text);
    const bodyHtml = richText.formattedTextHtml(body, post.formatting_runs, post.bold_ranges);
    const category = categoryLabel(post.category);
    const imageWidth = Math.max(0, Number(post.image_width || 0));
    const imageHeight = Math.max(0, Number(post.image_height || 0));
    const hasImageRatio = imageWidth > 0 && imageHeight > 0;
    const mediaFrameClass = hasImageRatio ? ' media-ratio-known' : ' media-ratio-unknown';
    const mediaFrameStyle = hasImageRatio ? ` style="aspect-ratio:${imageWidth} / ${imageHeight}"` : '';
    const mediaDimensions = hasImageRatio ? ` width="${imageWidth}" height="${imageHeight}"` : '';
    const status = text(post.status || 'ACTIVE').toUpperCase();
    const moderation = text(post.moderation_status || '').toUpperCase();
    const chip = creatorMode
      ? `<span class="status-chip ${status === 'REVIEW' ? 'pending' : status === 'BLOCKED' ? 'blocked' : ''}">${escapeHtml(status)}${moderation ? ` • ${escapeHtml(moderation)}` : ''}</span>`
      : '';
    const liked = state.localLikes.has(id);
    const creatorActions = hasVerifiedSession()
      ? `<button class="post-action ${liked ? 'active' : ''}" type="button" data-action="like">${liked ? '♥ Unlike' : '♡ Like'}</button>
          <button class="post-action" type="button" data-action="comment">◯ Comment</button>`
      : '';

    return `
      <article class="post-card card" data-post-id="${escapeHtml(id)}">
        <header class="post-head">
          ${avatarMarkup(name, post.creator_photo_url, { deferred: feed, group: `avatar-${id}` })}
          <div class="post-author"><strong>${escapeHtml(name)}</strong><span>${escapeHtml(formatTime(post.created_at))}</span></div>
          ${chip}
        </header>
        ${category ? `<span class="post-category-label">${escapeHtml(category)}</span>` : ''}
        ${title ? `<button class="post-title" type="button" data-action="open">${escapeHtml(title)}</button>` : ''}
        ${body ? `<div class="post-copy ${!detail && body.length > 700 ? 'truncated' : ''}" data-action="open">${bodyHtml}</div>` : ''}
        ${image ? `<div class="post-media-frame${feed ? ` feed-media-frame media-pending${mediaFrameClass}` : ''}"${mediaFrameStyle} data-action="open"><img class="post-media" ${feed ? deferredMediaAttributes(image, `post-${id}`, priority) : `src="${escapeHtml(image)}" loading="${priority ? 'eager' : 'lazy'}" decoding="async"${priority ? ' fetchpriority="high"' : ''}`} alt="Image shared by ${escapeHtml(name)}"${mediaDimensions}></div>` : ''}
        <div class="post-meta"><span>${Number(post.like_count || 0)} likes</span><span>${Number(post.comment_count || 0)} comments • ${Number(post.share_count || 0)} shares</span></div>
        <div class="post-actions">
          ${creatorActions}
          <button class="post-action" type="button" data-action="share">↗ Share</button>
        </div>
      </article>`;
  }

  function abortError() {
    const error = new Error('Request cancelled.');
    error.name = 'AbortError';
    return error;
  }

  function loadExternalImage(url, signal) {
    return new Promise((resolve, reject) => {
      const probe = new Image();
      const cleanup = () => signal?.removeEventListener('abort', onAbort);
      const onAbort = () => {
        probe.src = '';
        cleanup();
        reject(abortError());
      };
      probe.onload = () => { cleanup(); resolve(url); };
      probe.onerror = () => { cleanup(); reject(new Error('Image could not be loaded.')); };
      if (signal?.aborted) return onAbort();
      signal?.addEventListener('abort', onAbort, { once: true });
      probe.referrerPolicy = 'no-referrer';
      markBoot('first_image_start');
      probe.src = url;
    });
  }

  async function requestFeedMedia(url, signal) {
    const target = new URL(url, window.location.origin);
    if (target.origin !== window.location.origin) return loadExternalImage(target.toString(), signal);

    const controller = new AbortController();
    let timedOut = false;
    const forwardAbort = () => controller.abort();
    if (signal?.aborted) controller.abort();
    else signal?.addEventListener('abort', forwardAbort, { once: true });
    const timeout = window.setTimeout(() => {
      timedOut = true;
      controller.abort();
    }, FEED_MEDIA_TIMEOUT_MS);
    try {
      markBoot('first_image_start');
      const response = await fetch(target.toString(), {
        credentials: 'same-origin',
        cache: 'default',
        signal: controller.signal
      });
      if (!response.ok) throw new Error('Image could not be loaded.');
      const contentType = String(response.headers.get('content-type') || '').toLowerCase();
      if (!contentType.startsWith('image/')) throw new Error('Image response was invalid.');
      const objectUrl = URL.createObjectURL(await response.blob());
      feedMediaObjectUrls.add(objectUrl);
      return objectUrl;
    } catch (error) {
      if (signal?.aborted) throw abortError();
      if (timedOut) throw new Error('Image request timed out.');
      throw error;
    } finally {
      window.clearTimeout(timeout);
      signal?.removeEventListener('abort', forwardAbort);
    }
  }

  function resolveFeedMedia(url) {
    const cached = feedMediaCache.get(url);
    if (cached) return Promise.resolve(cached);
    return scheduleRequest(
      requestPriority.MEDIA,
      ({ signal }) => requestFeedMedia(url, signal),
      { key: `media:${url}`, preemptible: true }
    ).then((resolvedUrl) => {
      feedMediaCache.set(url, resolvedUrl);
      return resolvedUrl;
    });
  }

  function markMediaFailure(elements) {
    elements.forEach((image) => {
      image.classList.add('media-load-failed');
      const frame = image.closest('.feed-media-frame');
      if (frame) {
        frame.classList.remove('media-pending');
        frame.classList.add('media-failed');
      } else {
        image.hidden = true;
      }
    });
  }

  async function loadFeedCardMedia(card) {
    const groups = new Map();
    $$('img[data-media-src]', card).forEach((image) => {
      const url = safeUrl(image.dataset.mediaSrc || '');
      if (!url) return;
      const group = image.dataset.mediaGroup || url;
      const entry = groups.get(group) || { url, elements: [] };
      entry.elements.push(image);
      groups.set(group, entry);
    });

    for (const entry of groups.values()) {
      try {
        const resolvedUrl = await resolveFeedMedia(entry.url);
        entry.elements.forEach((image) => {
          if (!document.contains(image)) return;
          image.addEventListener('load', () => {
            const frame = image.closest('.feed-media-frame');
            if (!frame || image.naturalWidth <= 0 || image.naturalHeight <= 0) return;
            frame.style.aspectRatio = `${image.naturalWidth} / ${image.naturalHeight}`;
            frame.classList.remove('media-ratio-unknown');
            frame.classList.add('media-ratio-known');
          }, { once: true });
          image.src = resolvedUrl;
          image.removeAttribute('data-media-src');
          image.closest('.feed-media-frame')?.classList.remove('media-pending', 'media-failed');
        });
      } catch (_error) {
        markMediaFailure(entry.elements);
      }
    }
  }

  function observeFeedMedia(card) {
    if (observedMediaCards.has(card) || !card.querySelector('img[data-media-src]')) return;
    observedMediaCards.add(card);
    if (!('IntersectionObserver' in window)) {
      void loadFeedCardMedia(card);
      return;
    }
    if (!feedMediaObserver) {
      feedMediaObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          feedMediaObserver.unobserve(entry.target);
          void loadFeedCardMedia(entry.target);
        });
      }, { root: null, rootMargin: '96px 0px', threshold: 0.01 });
    }
    feedMediaObserver.observe(card);
  }

  function bindPostActions(root) {
    const cards = root?.matches?.('[data-post-id]')
      ? [root]
      : $$('[data-post-id]', root);
    cards.forEach((card) => {
      if (card.dataset.postActionsBound === 'true') return;
      card.dataset.postActionsBound = 'true';
      const postId = card.dataset.postId;
      observeLikeState(card, postId);
      observeFeedMedia(card);
      bindMediaFallback(card);
      card.addEventListener('click', async (event) => {
        const action = event.target.closest('[data-action]')?.dataset.action;
        if (!action) return;
        if (action === 'open' || action === 'comment') return openPost(postId);
        if (action === 'like') return toggleLike(card, postId, event.target.closest('button'));
        if (action === 'share') {
          const headline = text($('.post-title', card)?.textContent).trim();
          return sharePost(postId, event.target.closest('button'), headline);
        }
      });
    });
  }

  function syncPostCardAccess(root = document) {
    const authenticated = hasVerifiedSession();
    const cards = root?.matches?.('[data-post-id]') ? [root] : $$('[data-post-id]', root);
    cards.forEach((card) => {
      const actions = $('.post-actions', card);
      if (!actions) return;
      actions.querySelectorAll('[data-action="like"], [data-action="comment"]').forEach((button) => {
        if (!authenticated) button.remove();
      });
      if (!authenticated || actions.querySelector('[data-action="like"]')) return;
      const share = actions.querySelector('[data-action="share"]');
      if (!share) return;
      share.insertAdjacentHTML(
        'beforebegin',
        '<button class="post-action" type="button" data-action="like">♡ Like</button>'
          + '<button class="post-action" type="button" data-action="comment">◯ Comment</button>'
      );
      observeLikeState(card, card.dataset.postId || '');
    });
  }

  function bindMediaFallback(card) {
    $$('img', card).forEach((image) => {
      if (image.dataset.mediaFallbackBound === 'true') return;
      image.dataset.mediaFallbackBound = 'true';
      image.addEventListener('error', () => {
        if (image.classList.contains('post-media')) {
          const frame = image.closest('.post-media-frame');
          if (frame?.classList.contains('feed-media-frame')) {
            image.hidden = true;
            frame.classList.remove('media-pending');
            frame.classList.add('media-failed');
          } else if (frame) frame.hidden = true;
        } else {
          image.hidden = true;
        }
      }, { once: true });
    });
  }

  function observeLikeState(card, postId) {
    if (!hasVerifiedSession() || observedLikeCards.has(card)) return;
    observedLikeCards.add(card);
    if (!('IntersectionObserver' in window)) {
      void hydrateLikeState(card, postId);
      return;
    }
    if (!likeStateObserver) {
      likeStateObserver = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          likeStateObserver.unobserve(entry.target);
          void hydrateLikeState(entry.target, entry.target.dataset.postId || '');
        });
      }, { root: null, rootMargin: '320px 0px', threshold: 0 });
    }
    likeStateObserver.observe(card);
  }

  async function hydrateLikeState(card, postId) {
    const button = $('[data-action="like"]', card);
    if (!button) return null;
    if (card.dataset.likeStateLoading === 'true') return likeStateRequests.get(card) || null;
    card.dataset.likeStateLoading = 'true';
    button.disabled = true;
    const request = scheduleRequest(
      requestPriority.LIKE,
      ({ signal }) => api.likeStatus(postId, { signal }),
      { key: `like-status:${postId}`, preemptible: true }
    ).then((result) => {
      const liked = result.data?.liked === true;
      if (liked) state.localLikes.add(postId); else state.localLikes.delete(postId);
      button.classList.toggle('active', liked);
      button.textContent = liked ? '♥ Unlike' : '♡ Like';
      card.dataset.likeStateLoaded = 'true';
      return result;
    }).catch((_error) => {
      button.textContent = 'Retry Like';
      return null;
    }).finally(() => {
      likeStateRequests.delete(card);
      card.dataset.likeStateLoading = 'false';
      button.disabled = false;
    });
    likeStateRequests.set(card, request);
    return request;
  }

  function renderSkeletons(container, count = 1) {
    container.textContent = '';
    const template = $('#postSkeletonTemplate');
    for (let i = 0; i < count; i += 1) container.appendChild(template.content.cloneNode(true));
  }

  function appendFeedPost(post, index) {
    if (index === 0) els.feedList.textContent = '';
    const wrapper = document.createElement('div');
    wrapper.innerHTML = postMarkup(post, { feed: true, priority: index === 0 });
    const card = wrapper.firstElementChild;
    if (!card) return;
    els.feedList.appendChild(card);
    if (index === 0) {
      markBoot('first_card_dom_append');
      window.dispatchEvent(new CustomEvent('znews:first-card', {
        detail: { postId: text(post.post_id) }
      }));
      window.requestAnimationFrame(() => markBoot('first_text_paint'));
    }
    if ((index + 1) % 4 === 0) {
      const ad = document.createElement('div');
      ad.className = 'ad-slot';
      ad.dataset.znewsAdSlot = 'post_inline';
      ad.dataset.format = 'mobile_banner';
      els.feedList.appendChild(ad);
    }
    bindPostActions(card);
    window.ZNewsAds.mountAll(els.feedList);
    showAnnouncement('');
  }

  function renderInitialFeedError(error) {
    els.feedList.innerHTML = `<div class="empty-state card"><strong>Feed could not be loaded</strong>${escapeHtml(errorMessage(error))}<button class="feed-retry-button" type="button" data-feed-retry>Retry</button></div>`;
  }

  function syncFeedProgress(snapshot) {
    state.feedCursor = text(snapshot.cursor);
    state.feedHasMore = snapshot.hasMore === true;
    state.feedLoading = snapshot.loading === true;

    const hasBufferedPost = Number(snapshot.bufferSize || 0) > 0;
    const retryReady = Boolean(snapshot.error)
      && Number(snapshot.renderedCount || 0) > 0
      && !hasBufferedPost
      && !snapshot.loading;
    const canAutoAdvance = hasBufferedPost || (!snapshot.error && snapshot.hasMore === true);
    els.loadMore.hidden = !(canAutoAdvance || retryReady);
    els.loadMore.disabled = snapshot.loading === true && !hasBufferedPost;
    els.loadMore.dataset.autoLoadPaused = retryReady ? 'true' : 'false';
    els.loadMore.dataset.feedDone = snapshot.done === true ? 'true' : 'false';
    els.loadMore.classList.toggle('feed-inline-retry', retryReady);
    els.loadMore.setAttribute('aria-hidden', retryReady ? 'false' : 'true');
    els.loadMore.tabIndex = retryReady ? 0 : -1;
    els.loadMore.textContent = retryReady ? 'Retry loading posts' : 'Load next post';

    if (snapshot.done === true
      && Number(snapshot.renderedCount || 0) === 0
      && !snapshot.error
      && !els.feedList.querySelector('.empty-state')) {
      els.feedList.innerHTML = '<div class="empty-state card"><strong>No public posts yet</strong>New approved stories will appear here.</div>';
    }
    window.dispatchEvent(new CustomEvent('znews:feed-progress', { detail: snapshot }));
    if (!snapshot.loading && (snapshot.loaded || snapshot.error)) {
      window.dispatchEvent(new CustomEvent('znews:feed-settled', { detail: snapshot }));
    }
  }

  function progressiveFeedInstance() {
    if (progressiveFeed) return progressiveFeed;
    const Controller = window.ZNewsProgressiveFeed;
    if (typeof Controller !== 'function') {
      throw new window.ZNewsApiError('Progressive feed is unavailable.', {
        code: 'ZNEWS_PROGRESSIVE_FEED_UNAVAILABLE'
      });
    }
    progressiveFeed = new Controller({
      batchSize: config.feedPageSize,
      lowWatermark: config.feedBufferLowWatermark,
      fetchPage: async (cursor, limit) => {
        const category = state.feedCategory;
        const result = await scheduleRequest(
          requestPriority.FEED,
          async ({ signal }) => {
            if (!cursor) markBoot('feed_request_start');
            const response = await api.publicFeed(cursor, limit, { signal, category });
            if (!cursor) markBoot('feed_response');
            return response;
          },
          { key: `feed:${category || 'ALL'}:${cursor || 'initial'}`, preemptible: false }
        );
        return result.data || {};
      },
      renderItem: appendFeedPost,
      onReset: () => renderSkeletons(els.feedList, 1),
      onStateChange: syncFeedProgress,
      onInitialError: renderInitialFeedError,
      onPaginationError: () => {
        // Existing posts stay visible; syncFeedProgress exposes a bottom retry when the buffer drains.
      }
    });
    return progressiveFeed;
  }

  async function loadFeed({ append = false } = {}) {
    try {
      const controller = progressiveFeedInstance();
      if (!append) await controller.start();
      else if (controller.snapshot().error) await controller.retry();
      else await controller.advance();
    } catch (_error) {
      // Controller callbacks own initial and pagination error presentation.
    }
  }

  async function toggleLike(card, postId, button) {
    if (!requireSession()) return;
    if (card.dataset.likeStateLoaded !== 'true') {
      await hydrateLikeState(card, postId);
      if (card.dataset.likeStateLoaded !== 'true') return;
    }
    const liked = !state.localLikes.has(postId);
    setBusy(button, true, '…');
    try {
      const result = await api.setLike(postId, liked);
      const canonicalLiked = result.data?.liked === true;
      if (canonicalLiked) state.localLikes.add(postId); else state.localLikes.delete(postId);
      button.classList.toggle('active', canonicalLiked);
      button.textContent = canonicalLiked ? '♥ Unlike' : '♡ Like';
      const count = Number(result.data?.counts?.like_count ?? 0);
      const meta = $('.post-meta span', card);
      if (meta) meta.textContent = `${count} likes`;
    } catch (error) {
      toast(errorMessage(error), 'error');
    } finally {
      setBusy(button, false);
      button.textContent = state.localLikes.has(postId) ? '♥ Unlike' : '♡ Like';
    }
  }

  async function sharePost(postId, button, headline = '') {
    const url = config.canonicalUrl('post', postId);
    setBusy(button, true, 'Sharing…');
    try {
      let channel = 'COPY_LINK';
      if (navigator.share) {
        await navigator.share({
          title: headline || 'Z Sky 24',
          text: headline || 'Read this story on Z Sky 24',
          url
        });
        channel = 'NATIVE_SHARE';
      } else {
        await navigator.clipboard.writeText(url);
        toast('Post link copied.');
      }
      if (hasVerifiedSession()) {
        const idempotencyKey = api.idempotencyKey('share');
        void scheduleRequest(
          requestPriority.ANALYTICS,
          ({ signal }) => api.recordShare(postId, channel, { signal, idempotencyKey }),
          { key: `share:${idempotencyKey}`, preemptible: true }
        ).catch(() => {});
      }
    } catch (error) {
      if (error?.name !== 'AbortError') toast(errorMessage(error), 'error');
    } finally {
      setBusy(button, false);
      button.textContent = '↗ Share';
    }
  }

  async function openPost(postId, { syncHistory = true } = {}) {
    if (state.openingPostId === postId) return;
    if (state.currentPostId === postId && els.postDialog.open && state.viewSession?.postId === postId) return;
    state.openingPostId = postId;
    state.currentPostId = postId;
    els.postDetail.innerHTML = '<div class="skeleton-card"><div class="skeleton line short"></div><div class="skeleton block"></div></div>';
    els.commentList.textContent = '';
    if (!els.postDialog.open) els.postDialog.showModal();
    if (syncHistory) {
      state.lastBoundaryBackAt = 0;
      const currentRoute = config.parseRoute();
      const alreadyCurrent = currentRoute.kind === 'post' && currentRoute.id === postId;
      if (!alreadyCurrent) {
        history.pushState(
          appHistoryState(state.route, { postId, znewsPostOverlay: true }),
          '',
          config.publicPath('post', postId)
        );
      }
    }

    try {
      const [postResult, commentResult] = await Promise.all([
        api.publicPost(postId),
        api.comments(postId)
      ]);
      const post = postResult.data?.post || {};
      els.postDetail.innerHTML = postMarkup(post, { detail: true });
      bindPostActions(els.postDetail);
      renderComments(commentResult.data?.items || []);
      const ad = document.createElement('div');
      ad.className = 'ad-slot';
      ad.dataset.znewsAdSlot = 'post_reader';
      ad.dataset.format = 'mobile_banner';
      els.postDetail.appendChild(ad);
      window.ZNewsAds.mount(ad, { creatorUid: text(post.creator_uid).trim() });
      beginView(postId);
    } catch (error) {
      els.postDetail.innerHTML = `<div class="empty-state"><strong>Post could not be loaded</strong>${escapeHtml(errorMessage(error))}</div>`;
    } finally {
      if (state.openingPostId === postId) state.openingPostId = '';
    }
  }

  function renderComments(items) {
    const comments = Array.isArray(items) ? items : [];
    els.commentList.textContent = '';
    if (!comments.length) {
      els.commentList.innerHTML = '<div class="empty-state"><strong>No comments yet</strong>Start the conversation.</div>';
      return;
    }
    els.commentList.innerHTML = comments.map((comment) => {
      const name = text(comment.author_name || comment.creator_name || 'Z-Pay user');
      return `<div class="comment">${avatarMarkup(name, comment.author_photo_url)}<div class="comment-bubble"><strong>${escapeHtml(name)}</strong><p>${escapeHtml(comment.text || comment.message || '')}</p><small>${escapeHtml(formatTime(comment.created_at))}</small></div></div>`;
    }).join('');
  }

  async function submitComment(event) {
    event.preventDefault();
    if (!requireSession()) return;
    const value = els.commentText.value.trim();
    if (!value) return toast('Write a comment first.', 'error');
    const button = $('button', els.commentForm);
    setBusy(button, true, 'Sending…');
    try {
      await api.createComment(state.currentPostId, value);
      els.commentText.value = '';
      toast('Comment submitted for review.');
    } catch (error) {
      toast(errorMessage(error), 'error');
    } finally {
      setBusy(button, false);
    }
  }

  async function beginView(postId) {
    if (state.viewSession?.postId === postId || state.viewStartingPostId === postId) return;
    state.viewStartingPostId = postId;
    const idempotencyKey = api.idempotencyKey(`view-${postId}`);
    try {
      await completeView();
      const result = await scheduleRequest(
        requestPriority.ANALYTICS,
        ({ signal }) => api.startView(postId, idempotencyKey, { signal }),
        { key: `view-start:${idempotencyKey}`, preemptible: true }
      );
      const session = result.data?.session || {};
      if (!session.view_id || !session.view_token) return;
      const heartbeatDelay = Math.max(3000, Number(session.heartbeat_after_seconds || 5) * 1000);
      state.viewSession = {
        id: session.view_id,
        postId,
        token: session.view_token,
        closing: false,
        heartbeatPending: null,
        completionPending: null,
        timer: window.setTimeout(() => {
          heartbeatView();
          const current = state.viewSession;
          if (!current || current.id !== session.view_id || current.closing) return;
          current.interval = window.setInterval(() => heartbeatView(), 10000);
        }, heartbeatDelay),
        interval: 0
      };
    } catch (_error) {
      state.viewSession = null;
    } finally {
      if (state.viewStartingPostId === postId) state.viewStartingPostId = '';
    }
  }

  async function heartbeatView(session = state.viewSession) {
    if (!session || session.closing || document.visibilityState !== 'visible') return null;
    if (session.heartbeatPending) return session.heartbeatPending;
    session.heartbeatPending = scheduleRequest(
      requestPriority.ANALYTICS,
      ({ signal }) => api.heartbeatView(session.id, session.token, { signal }),
      { key: `view-heartbeat:${session.id}`, preemptible: true }
    )
      .catch(() => null)
      .finally(() => { session.heartbeatPending = null; });
    return session.heartbeatPending;
  }

  async function completeView() {
    const session = state.viewSession;
    if (!session) return;
    if (session.completionPending) return session.completionPending;
    session.completionPending = (async () => {
      session.closing = true;
      window.clearTimeout(session.timer);
      window.clearInterval(session.interval);
      if (session.heartbeatPending) await session.heartbeatPending;
      if (document.visibilityState === 'visible') {
        session.closing = false;
        await heartbeatView(session);
        session.closing = true;
      }
      if (state.viewSession === session) state.viewSession = null;
      try {
        await scheduleRequest(
          requestPriority.ANALYTICS,
          ({ signal }) => api.completeView(session.id, session.token, { signal }),
          { key: `view-complete:${session.id}`, preemptible: true }
        );
      } catch (_error) { /* non-blocking */ }
    })();
    return session.completionPending;
  }

  function closePost({ syncHistory = true } = {}) {
    completeView();
    if (els.postDialog.open) els.postDialog.close();
    state.currentPostId = '';
    if (syncHistory && config.parseRoute().kind === 'post') {
      if (history.state?.znewsPostOverlay === true) {
        history.back();
      } else {
        history.replaceState({ znewsView: 'feed' }, '', config.publicPath());
        routeTo('feed', { syncHistory: false });
      }
    }
  }

  async function submitPost(event) {
    event.preventDefault();
    if (!requireSession()) return;
    if (els.createPostForm.getAttribute('aria-busy') === 'true') return;
    const postTitle = els.postTitle.value.trim();
    const parsedText = richText.getEditorPayload(els.postText);
    const postText = parsedText.text;
    const category = text(els.postCategory?.value).trim();
    const file = els.postImage.files?.[0] || null;
    if (!postTitle) return toast('Add a news headline.', 'error');
    if (!['INTERNATIONAL_NEWS', 'BD_NEWS', 'MOBILE_PRICING'].includes(category)) return toast('Choose a post category.', 'error');
    if (!postText && !file) return toast('Add post details or a photo.', 'error');
    if (file && !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return toast('Choose a JPEG, PNG or WebP photo.', 'error');
    if (file && file.size > 8 * 1024 * 1024) return toast('Image must be 8 MB or smaller.', 'error');

    els.createPostForm.setAttribute('aria-busy', 'true');
    const finishProgress = uiFeedback.beginProgress();
    setCreateMutationState(true, file ? 'optimizing' : 'posting');
    try {
      let mediaId = '';
      if (file) {
        const optimizer = window.ZNewsImageOptimizer
          || await window.ZNEWS_IMAGE_OPTIMIZER_READY?.();
        if (!optimizer?.optimize) throw new window.ZNewsApiError('Photo optimization is unavailable. Reload and try again.');
        const optimized = await optimizer.optimize(file, () => setCreateMutationState(true, 'optimizing'));
        setCreateMutationState(true, 'uploading');
        const upload = await api.uploadMedia(optimized.file);
        mediaId = text(upload.data?.media?.media_id);
        if (!mediaId) throw new window.ZNewsApiError('Image upload did not return a media ID.');
        setCreateMutationState(true, 'publishing');
      }
      await api.createPost({
        title: postTitle,
        text: postText,
        boldRanges: parsedText.boldRanges,
        formattingRuns: parsedText.formattingRuns,
        mediaId,
        category
      });
      state.feedDirty = true;
      els.createPostForm.reset();
      richText.setEditorContent(els.postText, '');
      window.ZNewsCategoryPicker?.set(els.postCategory, '', { notify: false });
      els.imagePreview.hidden = true;
      els.imagePreview.textContent = '';
      els.postTitleCount.textContent = '0 / 160';
      els.postTextCount.textContent = '0 / 5000';
      toast('Post submitted for review.');
      routeTo('mine');
    } catch (error) {
      toast(errorMessage(error), 'error');
    } finally {
      finishProgress();
      els.createPostForm.removeAttribute('aria-busy');
      setCreateMutationState(false);
      syncComposerState();
    }
  }

  function replaceMyPostCard(postId, incoming) {
    const card = els.mineList.querySelector(`.post-card[data-post-id="${CSS.escape(postId)}"]`);
    if (!card || !incoming || typeof incoming !== 'object') return false;
    const previous = state.minePosts.get(postId) || {};
    const merged = { ...previous, ...incoming, post_id: postId };
    ['like_count', 'comment_count', 'share_count'].forEach((field) => {
      if (Object.hasOwn(previous, field)) merged[field] = previous[field];
    });
    const holder = document.createElement('div');
    holder.innerHTML = postMarkup(merged, { creatorMode: true }).trim();
    const replacement = holder.firstElementChild;
    if (!replacement) return false;
    card.replaceWith(replacement);
    state.minePosts.set(postId, merged);
    bindPostActions(replacement);
    return true;
  }

  async function loadMyPosts({ append = false, preserveExisting = false } = {}) {
    if (!hasVerifiedSession() || state.mineLoading) return;
    state.mineLoading = true;
    const finishProgress = uiFeedback.beginProgress();
    let appendFailed = false;
    if (!append && !preserveExisting) renderSkeletons(els.mineList, 2);
    setBusy(els.mineLoadMore, true, 'Loading…');
    try {
      const result = await api.myPosts(append ? state.mineCursor : '');
      const items = Array.isArray(result.data?.items) ? result.data.items : [];
      if (!append) {
        els.mineList.textContent = '';
        state.minePosts.clear();
      }
      if (items.length) {
        items.forEach((post) => state.minePosts.set(text(post.post_id), post));
        els.mineList.insertAdjacentHTML('beforeend', items.map((post) => postMarkup(post, { creatorMode: true })).join(''));
      } else if (!append) {
        els.mineList.innerHTML = '<div class="empty-state card"><strong>No posts yet</strong>Create your first Z Sky 24 post.</div>';
      }
      bindPostActions(els.mineList);
      state.mineCursor = text(result.data?.next_cursor);
      state.mineHasMore = result.data?.has_more === true;
      els.mineLoadMore.hidden = !state.mineHasMore || !state.mineCursor;
    } catch (error) {
      if (!append && !preserveExisting) {
        els.mineList.innerHTML = `<div class="empty-state card"><strong>Posts could not be loaded</strong>${escapeHtml(errorMessage(error))}<button class="feed-retry-button" type="button" data-mine-retry>Retry</button></div>`;
      } else {
        appendFailed = true;
        els.mineLoadMore.hidden = false;
      }
    } finally {
      finishProgress();
      state.mineLoading = false;
      setBusy(els.mineLoadMore, false);
      els.mineLoadMore.textContent = appendFailed ? 'Retry' : 'Load more';
    }
  }

  function readBdtBalance(payload) {
    const balances = Array.isArray(payload?.data?.balances) ? payload.data.balances : [];
    return balances.find((item) => text(item.currency).toUpperCase() === 'BDT') || {};
  }

  function renderCreatorAdRate(summary) {
    const policy = summary?.data?.creator_ad_payout_policy || {};
    const unitMicros = Math.max(0, Number(policy.payout_unit_micros || 0));
    const maximumMicros = Math.max(0, Number(policy.maximum_per_verified_ad_micros || 0));
    if (!unitMicros || maximumMicros < unitMicros) {
      els.creatorAdRate.textContent = 'Verified provider reports only';
      els.creatorAdRateNote.textContent = 'No client-calculated ad value is accepted.';
      return;
    }
    els.creatorAdRate.textContent = unitMicros === maximumMicros
      ? `${formatBdtMicros(maximumMicros)} per verified ad`
      : `${formatBdtMicros(unitMicros)}–${formatBdtMicros(maximumMicros)} per verified ad`;
    const sharePercent = Math.max(0, Number(policy.base_creator_share_percent || 0));
    els.creatorAdRateNote.textContent = `${sharePercent}% of the provider-reported amount, rounded down to whole paisa and capped at ${formatBdtMicros(maximumMicros)}. Values are settled by the server only.`;
  }

  async function loadCreatorPolicy() {
    try {
      const policy = await api.publicCreatorPolicy();
      renderCreatorAdRate(policy);
    } catch (_error) {
      els.creatorAdRate.textContent = '৳0.01–৳0.03';
      els.creatorAdRateNote.textContent = 'Current maximum range. Reload to verify the latest server policy.';
    }
  }

  async function loadBalance() {
    if (!hasVerifiedSession()) return;
    els.balanceStatus.textContent = 'Loading balance…';
    els.ledgerList.innerHTML = '<div class="skeleton line"></div><div class="skeleton line"></div>';
    try {
      const [summary, ledger] = await Promise.all([api.balanceSummary(), api.balanceLedger()]);
      const balance = readBdtBalance(summary);
      renderCreatorAdRate(summary);
      state.transferMinimumMicros = Math.max(1, Number(summary.data?.minimum_bdt_micros || 200_000_000));
      state.balanceMicros = Number(balance.available_micros || 0);
      const formatted = formatBdtMicros(state.balanceMicros);
      els.balanceAmount.textContent = formatted;
      els.miniBalance.textContent = formatted;
      els.balanceStatus.textContent = Number(balance.reserved_micros || 0) > 0
        ? `${formatBdtMicros(balance.reserved_micros)} reserved for review.`
        : 'Available for an eligible transfer request.';
      els.transferButton.disabled = state.balanceMicros < state.transferMinimumMicros;
      renderLedger(ledger.data?.items || []);
    } catch (error) {
      els.balanceStatus.textContent = errorMessage(error);
      els.creatorAdRate.textContent = 'Policy unavailable';
      els.creatorAdRateNote.textContent = 'Reload the page to try again.';
      els.ledgerList.innerHTML = '<div class="empty-state">Balance activity could not be loaded.</div>';
    }
  }

  async function loadMiniBalance() {
    if (!hasVerifiedSession() || !els.miniBalance) return;
    els.miniBalance.textContent = 'Loading…';
    try {
      const summary = await api.balanceSummary();
      const balance = readBdtBalance(summary);
      state.balanceMicros = Number(balance.available_micros || 0);
      els.miniBalance.textContent = formatBdtMicros(state.balanceMicros);
    } catch (_error) {
      els.miniBalance.textContent = 'Unavailable';
    }
  }

  function renderLedger(items) {
    const rows = Array.isArray(items) ? items : [];
    els.ledgerList.innerHTML = rows.length ? rows.map((entry) => {
      const direction = text(entry.direction).toUpperCase();
      const amount = formatBdtMicros(entry.amount_micros);
      const label = text(entry.type).replaceAll('_', ' ').toLowerCase();
      return `<div class="ledger-item"><div><strong>${escapeHtml(label.charAt(0).toUpperCase() + label.slice(1))}</strong><small>${escapeHtml(formatTime(entry.created_at))} • ${escapeHtml(entry.status || '')}</small></div><strong class="ledger-amount ${direction === 'CREDIT' ? 'credit' : 'debit'}">${direction === 'CREDIT' ? '+' : '−'}${escapeHtml(amount)}</strong></div>`;
    }).join('') : '<div class="empty-state"><strong>No balance activity yet</strong>Creator share entries will appear here.</div>';
  }

  async function requestTransfer() {
    if (state.balanceMicros < state.transferMinimumMicros) {
      return toast(`Minimum transfer amount is ${formatBdtMicros(state.transferMinimumMicros)}.`, 'error');
    }
    if (!window.confirm(`Submit ${formatBdtMicros(state.balanceMicros)} for transfer review?`)) return;
    setBusy(els.transferButton, true, 'Submitting…');
    try {
      await api.requestTransfer(state.balanceMicros);
      toast('Transfer request submitted for review.');
      await loadBalance();
    } catch (error) {
      toast(errorMessage(error), 'error');
    } finally {
      setBusy(els.transferButton, false);
    }
  }

  function previewImage() {
    els.imagePreview.textContent = '';
    const file = els.postImage.files?.[0];
    if (!file) {
      els.imagePreview.hidden = true;
      syncComposerState();
      return;
    }
    const imageUrl = URL.createObjectURL(file);
    const backdrop = document.createElement('img');
    backdrop.className = 'composer-image-backdrop';
    backdrop.alt = '';
    backdrop.setAttribute('aria-hidden', 'true');
    backdrop.src = imageUrl;
    els.imagePreview.appendChild(backdrop);
    const img = document.createElement('img');
    img.className = 'composer-image-foreground';
    img.alt = 'Selected image preview';
    img.src = imageUrl;
    img.onload = () => URL.revokeObjectURL(imageUrl);
    els.imagePreview.appendChild(img);
    const remove = document.createElement('button');
    remove.className = 'composer-image-remove';
    remove.type = 'button';
    remove.setAttribute('aria-label', 'Remove selected photo');
    remove.textContent = '×';
    remove.addEventListener('click', () => {
      els.postImage.value = '';
      previewImage();
    });
    els.imagePreview.appendChild(remove);
    els.imagePreview.hidden = false;
    syncComposerState();
  }

  function syncComposerState() {
    const titleLength = els.postTitle.value.length;
    const editorPayload = richText.getEditorPayload(els.postText);
    const length = Array.from(editorPayload.text).length;
    els.postTitleCount.textContent = `${titleLength} / 160`;
    els.postTextCount.textContent = `${length} / 5000`;
    const hasContent = Boolean(
      els.postTitle.value.trim()
      && ['INTERNATIONAL_NEWS', 'BD_NEWS', 'MOBILE_PRICING'].includes(text(els.postCategory?.value))
      && (editorPayload.text || els.postImage.files?.[0])
    );
    els.createPostForm.classList.toggle('has-media', Boolean(els.postImage.files?.[0]));
    [els.createPostSubmit, els.createPostSubmitBottom].forEach((button) => {
      if (button) button.disabled = !hasContent;
    });
  }

  function bindEvents() {
    $$('[data-route]').forEach((button) => button.addEventListener('click', () => {
      if (button.classList.contains('composer-back')) history.back();
      else routeTo(button.dataset.route);
    }));
    $('#refreshButton').addEventListener('click', () => loadFeed());
    $('#feedRefreshInline').addEventListener('click', () => loadFeed());
    els.loadMore.addEventListener('click', () => loadFeed({ append: true }));
    els.feedList.addEventListener('click', (event) => {
      if (event.target.closest('[data-feed-retry]')) void loadFeed({ append: true });
    });
    els.mineList.addEventListener('click', (event) => {
      if (event.target.closest('[data-mine-retry]')) void loadMyPosts();
    });
    els.mineLoadMore.addEventListener('click', () => loadMyPosts({ append: true }));
    $('#composerTrigger').addEventListener('click', () => routeTo('create'));
    $('#composerMediaTrigger').addEventListener('click', () => {
      if (!requireSession()) return;
      routeTo('create');
      window.setTimeout(() => els.postImage.click(), 100);
    });
    els.sessionButton.addEventListener('click', () => {
      if (!hasVerifiedSession()) return openAuth();
      api.clearSession();
      state.balanceMicros = 0;
      els.balanceAmount.textContent = '৳0.00';
      els.miniBalance.textContent = '৳0.00';
      refreshSessionUi();
      routeTo('feed');
      toast('Signed out.');
    });
    els.authForm.addEventListener('submit', submitAuth);
    els.createPostForm.addEventListener('submit', submitPost);
    els.postCategory?.addEventListener('change', syncComposerState);
    els.postTitle.addEventListener('input', syncComposerState);
    els.postText.addEventListener('input', (event) => {
      if (event.isComposing || event.inputType === 'insertCompositionText') return;
      syncComposerState();
    });
    els.postText.addEventListener('znews:editor-sync', syncComposerState);
    els.postText.addEventListener('znews:format-change', syncComposerState);
    richText.setEditorContent(els.postText, '');
    richText.bindToolbar(els.postText, $('#postFormatToolbar'));
    window.ZNewsCategoryPicker?.bind(els.postCategory, $('#postCategoryButton'), $('#postCategoryDialog'));
    els.postImage.addEventListener('change', previewImage);
    els.feedCategories?.addEventListener('click', (event) => {
      const button = event.target.closest('[data-feed-category]');
      if (!button) return;
      const category = text(button.dataset.feedCategory).toUpperCase();
      if (category === 'MICRO_JOB') {
        toast('Micro job is coming soon.');
        return;
      }
      if (category === state.feedCategory) return;
      state.feedCategory = category;
      $$('.feed-category', els.feedCategories).forEach((item) => {
        const active = text(item.dataset.feedCategory).toUpperCase() === category;
        item.classList.toggle('active', active);
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      progressiveFeed?.destroy?.();
      progressiveFeed = null;
      void loadFeed();
    });
    window.addEventListener('znews:creator-post-mutated', (event) => {
      const postId = text(event.detail?.postId);
      const action = text(event.detail?.action);
      if (action === 'update' && postId && event.detail?.post) {
        replaceMyPostCard(postId, event.detail.post);
      }
      if (action === 'delete' && postId) state.minePosts.delete(postId);
      state.feedDirty = true;
      if (state.route === 'feed') routeTo('feed', { syncHistory: false });
      if (state.route === 'mine' && action === 'update') {
        window.setTimeout(() => void loadMyPosts({ preserveExisting: true }), 0);
      }
    });
    els.commentForm.addEventListener('submit', submitComment);
    els.postDialogClose.addEventListener('click', closePost);
    els.postDialog.addEventListener('cancel', (event) => { event.preventDefault(); closePost(); });
    els.postDialog.addEventListener('click', (event) => { if (event.target === els.postDialog) closePost(); });
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') heartbeatView();
    });
    els.transferButton.addEventListener('click', requestTransfer);
    window.addEventListener('popstate', (event) => {
      if (event.state?.znewsBoundary === true) {
        handleAppBoundaryBack();
        return;
      }
      const route = config.parseRoute();
      if (route.kind === 'post') openPost(route.id, { syncHistory: false });
      else {
        if (els.postDialog.open) closePost({ syncHistory: false });
        const restoredView = ['feed', 'create', 'mine', 'performance', 'policy'].includes(event.state?.znewsView)
          ? event.state.znewsView
          : 'feed';
        routeTo(restoredView, { syncHistory: false });
      }
    });
    window.addEventListener('pagehide', (event) => {
      completeView();
      if (event.persisted) return;
      feedMediaObjectUrls.forEach((url) => URL.revokeObjectURL(url));
      feedMediaObjectUrls.clear();
      feedMediaCache.clear();
    });
    window.addEventListener('znews:auth-ready', () => {
      refreshSessionUi();
      syncPostCardAccess();
    });
    syncComposerState();
  }

  async function boot() {
    const route = config.parseRoute();
    initializeAppHistory(route);
    refreshSessionUi();
    bindEvents();
    window.ZNewsAds.mountAll();
    if (route.kind !== 'policy') await loadFeed();
    if (route.kind === 'post') openPost(route.id, { syncHistory: false });
    if (route.kind === 'policy') routeTo('policy', { syncHistory: false });
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register(
        config.standalone ? '/sw.js' : '/znews/sw.js',
        { updateViaCache: 'none' }
      ).catch(() => {});
    }
  }

  boot();
})();
