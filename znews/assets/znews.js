(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const api = new window.ZNewsApiClient(config);
  const state = {
    route: 'feed',
    feedCursor: '',
    feedHasMore: false,
    feedLoading: false,
    currentPostId: '',
    openingPostId: '',
    viewStartingPostId: '',
    viewSession: null,
    balanceMicros: 0,
    transferMinimumMicros: 200_000_000,
    authStage: 'credentials',
    authContext: {},
    localLikes: new Set(),
    lastBoundaryBackAt: 0
  };

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  const els = {
    feedList: $('#feedList'),
    mineList: $('#mineList'),
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

  function text(value) {
    return String(value ?? '');
  }

  function escapeHtml(value) {
    return text(value).replace(/[&<>'"]/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[char]);
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
    const signedIn = api.isAuthenticated();
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
    if (api.isAuthenticated()) return true;
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
    refreshSessionUi();
    els.authDialog.close();
    toast('Signed in successfully.');
    if (state.route === 'mine') loadMyPosts();
    if (state.route === 'balance') loadBalance();
  }

  function appHistoryState(view, extra = {}) {
    return { znewsAppEntry: true, znewsView: view, ...extra };
  }

  function syncViewMetadata(view) {
    const policy = view === 'policy';
    const canonical = policy ? config.canonicalUrl('policy') : config.canonicalUrl();
    document.title = policy ? 'Creator credit policy | Z Sky 24' : 'Z Sky 24';
    document.querySelector('link[rel="canonical"]')?.setAttribute('href', canonical);
    document.querySelector('meta[property="og:url"]')?.setAttribute('content', canonical);
    document.querySelector('meta[property="og:title"]')?.setAttribute('content', policy ? 'Creator credit policy | Z Sky 24' : 'Z Sky 24');
    document.querySelector('meta[name="description"]')?.setAttribute('content', policy
      ? 'Z Sky 24 verified ad credit, balance and transfer policy.'
      : 'Z Sky 24 — News, stories and community updates.');
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
    const allowed = ['feed', 'create', 'mine', 'balance', 'policy'];
    const next = allowed.includes(route) ? route : 'feed';
    if (['create', 'mine', 'balance'].includes(next) && !requireSession()) return;
    if (els.postDialog.open) closePost({ syncHistory: false });
    state.route = next;
    syncViewMetadata(next);
    document.documentElement.dataset.znewsRoute = next;
    $$('.view').forEach((view) => view.classList.toggle('active', view.dataset.view === next));
    $$('[data-route]').forEach((button) => button.classList.toggle('active', button.dataset.route === next));
    window.scrollTo({ top: 0, behavior: next === 'create' ? 'auto' : 'smooth' });
    if (next === 'mine') loadMyPosts();
    if (next === 'balance') loadBalance();
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

  function avatarMarkup(name, photo) {
    const url = safeUrl(photo);
    if (url) return `<span class="avatar"><img src="${escapeHtml(url)}" alt="" referrerpolicy="no-referrer"></span>`;
    return `<span class="avatar">${escapeHtml(text(name).charAt(0).toUpperCase() || 'Z')}</span>`;
  }

  function postMarkup(post, { detail = false, creatorMode = false } = {}) {
    const id = text(post.post_id);
    const name = text(post.creator_name || 'Z Sky 24 creator');
    const image = postImage(post);
    const title = text(post.title).trim();
    const body = text(post.text);
    const status = text(post.status || 'ACTIVE').toUpperCase();
    const moderation = text(post.moderation_status || '').toUpperCase();
    const chip = creatorMode
      ? `<span class="status-chip ${status === 'REVIEW' ? 'pending' : status === 'BLOCKED' ? 'blocked' : ''}">${escapeHtml(status)}${moderation ? ` • ${escapeHtml(moderation)}` : ''}</span>`
      : '';
    const liked = state.localLikes.has(id);

    return `
      <article class="post-card card" data-post-id="${escapeHtml(id)}">
        <header class="post-head">
          ${avatarMarkup(name, post.creator_photo_url)}
          <div class="post-author"><strong>${escapeHtml(name)}</strong><span>${escapeHtml(formatTime(post.created_at))}</span></div>
          ${chip}
        </header>
        ${title ? `<button class="post-title" type="button" data-action="open">${escapeHtml(title)}</button>` : ''}
        ${body ? `<div class="post-copy ${!detail && body.length > 700 ? 'truncated' : ''}" data-action="open">${escapeHtml(body)}</div>` : ''}
        ${image ? `<div class="post-media-frame" data-action="open"><img class="post-media-backdrop" src="${escapeHtml(image)}" alt="" aria-hidden="true" loading="lazy"><img class="post-media" src="${escapeHtml(image)}" alt="Image shared by ${escapeHtml(name)}" loading="lazy"></div>` : ''}
        <div class="post-meta"><span>${Number(post.like_count || 0)} likes</span><span>${Number(post.comment_count || 0)} comments • ${Number(post.share_count || 0)} shares</span></div>
        <div class="post-actions">
          <button class="post-action ${liked ? 'active' : ''}" type="button" data-action="like">♡ Like</button>
          <button class="post-action" type="button" data-action="comment">◯ Comment</button>
          <button class="post-action" type="button" data-action="share">↗ Share</button>
        </div>
      </article>`;
  }

  function bindPostActions(root) {
    $$('[data-post-id]', root).forEach((card) => {
      const postId = card.dataset.postId;
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

  function renderSkeletons(container, count = 3) {
    container.textContent = '';
    const template = $('#postSkeletonTemplate');
    for (let i = 0; i < count; i += 1) container.appendChild(template.content.cloneNode(true));
  }

  async function loadFeed({ append = false } = {}) {
    if (state.feedLoading) return;
    state.feedLoading = true;
    if (!append) renderSkeletons(els.feedList);
    setBusy(els.loadMore, true, 'Loading…');

    try {
      const result = await api.publicFeed(append ? state.feedCursor : '');
      const items = Array.isArray(result.data?.items) ? result.data.items : [];
      if (!append) els.feedList.textContent = '';
      const fragment = document.createDocumentFragment();

      items.forEach((post, index) => {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = postMarkup(post);
        fragment.appendChild(wrapper.firstElementChild);
        if ((index + 1) % 4 === 0) {
          const ad = document.createElement('div');
          ad.className = 'ad-slot';
          ad.dataset.znewsAdSlot = 'post_inline';
          ad.dataset.format = 'mobile_banner';
          fragment.appendChild(ad);
        }
      });
      els.feedList.appendChild(fragment);
      if (!els.feedList.children.length) {
        els.feedList.innerHTML = '<div class="empty-state card"><strong>No public posts yet</strong>New approved stories will appear here.</div>';
      }
      bindPostActions(els.feedList);
      window.ZNewsAds.mountAll(els.feedList);
      state.feedCursor = text(result.data?.next_cursor);
      state.feedHasMore = result.data?.has_more === true;
      els.loadMore.hidden = !state.feedHasMore;
      showAnnouncement('');
    } catch (error) {
      if (!append) els.feedList.innerHTML = `<div class="empty-state card"><strong>Feed could not be loaded</strong>${escapeHtml(errorMessage(error))}</div>`;
      else toast(errorMessage(error), 'error');
    } finally {
      state.feedLoading = false;
      setBusy(els.loadMore, false);
    }
  }

  async function toggleLike(card, postId, button) {
    if (!requireSession()) return;
    const liked = !state.localLikes.has(postId);
    setBusy(button, true, '…');
    try {
      const result = await api.setLike(postId, liked);
      if (liked) state.localLikes.add(postId); else state.localLikes.delete(postId);
      button.classList.toggle('active', liked);
      button.textContent = '♡ Like';
      const count = Number(result.data?.counts?.like_count ?? 0);
      const meta = $('.post-meta span', card);
      if (meta) meta.textContent = `${count} likes`;
    } catch (error) {
      toast(errorMessage(error), 'error');
    } finally {
      setBusy(button, false);
      button.textContent = '♡ Like';
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
      if (api.isAuthenticated()) {
        await api.recordShare(postId, channel);
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
      window.ZNewsAds.mount(ad);
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
      const result = await api.startView(postId, idempotencyKey);
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
    session.heartbeatPending = api.heartbeatView(session.id, session.token)
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
      try { await api.completeView(session.id, session.token); } catch (_error) { /* non-blocking */ }
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
    const submit = $('button[type="submit"]', els.createPostForm);
    const postTitle = els.postTitle.value.trim();
    const postText = els.postText.value.trim();
    const file = els.postImage.files?.[0] || null;
    if (!postTitle) return toast('Add a news headline.', 'error');
    if (!postText && !file) return toast('Add post details or a photo.', 'error');
    if (file && file.size > 5 * 1024 * 1024) return toast('Image must be 5 MB or smaller.', 'error');

    setBusy(submit, true, file ? 'Uploading…' : 'Submitting…');
    try {
      let mediaId = '';
      if (file) {
        const upload = await api.uploadMedia(file);
        mediaId = text(upload.data?.media?.media_id);
        if (!mediaId) throw new window.ZNewsApiError('Image upload did not return a media ID.');
        submit.textContent = 'Submitting…';
      }
      await api.createPost({ title: postTitle, text: postText, mediaId });
      els.createPostForm.reset();
      els.imagePreview.hidden = true;
      els.imagePreview.textContent = '';
      els.postTitleCount.textContent = '0 / 160';
      els.postTextCount.textContent = '0 / 5000';
      toast('Post submitted for review.');
      routeTo('mine');
    } catch (error) {
      toast(errorMessage(error), 'error');
    } finally {
      setBusy(submit, false);
    }
  }

  async function loadMyPosts() {
    if (!api.isAuthenticated()) return;
    renderSkeletons(els.mineList, 2);
    try {
      const result = await api.myPosts();
      const items = Array.isArray(result.data?.items) ? result.data.items : [];
      els.mineList.innerHTML = items.length
        ? items.map((post) => postMarkup(post, { creatorMode: true })).join('')
        : '<div class="empty-state card"><strong>No posts yet</strong>Create your first Z Sky 24 post.</div>';
      bindPostActions(els.mineList);
    } catch (error) {
      els.mineList.innerHTML = `<div class="empty-state card"><strong>Posts could not be loaded</strong>${escapeHtml(errorMessage(error))}</div>`;
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
    if (!api.isAuthenticated()) return;
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
    const length = els.postText.value.length;
    els.postTitleCount.textContent = `${titleLength} / 160`;
    els.postTextCount.textContent = `${length} / 5000`;
    els.postText.style.height = 'auto';
    els.postText.style.height = `${Math.min(210, Math.max(112, els.postText.scrollHeight))}px`;
    const hasContent = Boolean(
      els.postTitle.value.trim()
      && (els.postText.value.trim() || els.postImage.files?.[0])
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
    $('#composerTrigger').addEventListener('click', () => routeTo('create'));
    $('#composerMediaTrigger').addEventListener('click', () => {
      if (!requireSession()) return;
      routeTo('create');
      window.setTimeout(() => els.postImage.click(), 100);
    });
    els.sessionButton.addEventListener('click', () => {
      if (!api.isAuthenticated()) return openAuth();
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
    els.postTitle.addEventListener('input', syncComposerState);
    els.postText.addEventListener('input', syncComposerState);
    els.postImage.addEventListener('change', previewImage);
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
        const restoredView = ['feed', 'create', 'mine', 'balance', 'policy'].includes(event.state?.znewsView)
          ? event.state.znewsView
          : 'feed';
        routeTo(restoredView, { syncHistory: false });
      }
    });
    window.addEventListener('pagehide', () => completeView());
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
