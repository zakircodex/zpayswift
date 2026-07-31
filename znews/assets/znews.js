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
    viewSession: null,
    balanceMicros: 0,
    authStage: 'credentials',
    authContext: {},
    localLikes: new Set()
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
    postText: $('#postText'),
    postTextCount: $('#postTextCount'),
    postImage: $('#postImage'),
    imagePreview: $('#imagePreview'),
    balanceAmount: $('#balanceAmount'),
    miniBalance: $('#miniBalance'),
    balanceStatus: $('#balanceStatus'),
    transferButton: $('#transferButton'),
    sidebarName: $('#sidebarName'),
    sidebarMeta: $('#sidebarMeta'),
    sidebarAvatar: $('#sidebarAvatar'),
    composerAvatar: $('#composerAvatar'),
    createComposerAvatar: $('#createComposerAvatar'),
    createComposerName: $('#createComposerName'),
    createPostSubmit: $('#createPostSubmit'),
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
      ZNEWS_TRANSFER_MINIMUM_NOT_MET: 'Minimum transfer amount is ৳500.',
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

  function routeTo(route) {
    const allowed = ['feed', 'create', 'mine', 'balance'];
    const next = allowed.includes(route) ? route : 'feed';
    if (['create', 'mine', 'balance'].includes(next) && !requireSession()) return;
    state.route = next;
    $$('.view').forEach((view) => view.classList.toggle('active', view.dataset.view === next));
    $$('[data-route]').forEach((button) => button.classList.toggle('active', button.dataset.route === next));
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (next === 'mine') loadMyPosts();
    if (next === 'balance') loadBalance();
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
        ${body ? `<div class="post-copy ${!detail && body.length > 700 ? 'truncated' : ''}" data-action="open">${escapeHtml(body)}</div>` : ''}
        ${image ? `<img class="post-media" data-action="open" src="${escapeHtml(image)}" alt="Image shared by ${escapeHtml(name)}" loading="lazy">` : ''}
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
        if (action === 'share') return sharePost(postId, event.target.closest('button'));
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

  async function sharePost(postId, button) {
    const url = config.canonicalUrl('post', postId);
    setBusy(button, true, 'Sharing…');
    try {
      let channel = 'COPY_LINK';
      if (navigator.share) {
        await navigator.share({ title: 'Z Sky 24', text: 'Read this story on Z Sky 24', url });
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

  async function openPost(postId) {
    state.currentPostId = postId;
    els.postDetail.innerHTML = '<div class="skeleton-card"><div class="skeleton line short"></div><div class="skeleton block"></div></div>';
    els.commentList.textContent = '';
    if (!els.postDialog.open) els.postDialog.showModal();
    history.pushState({ postId }, '', config.publicPath('post', postId));

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
    await completeView();
    try {
      const result = await api.startView(postId);
      const session = result.data?.session || {};
      if (!session.view_id || !session.view_token) return;
      state.viewSession = {
        id: session.view_id,
        token: session.view_token,
        timers: [
          window.setTimeout(() => heartbeatView(), 5000),
          window.setTimeout(() => heartbeatView(), 15000)
        ]
      };
    } catch (_error) {
      state.viewSession = null;
    }
  }

  async function heartbeatView() {
    const session = state.viewSession;
    if (!session || document.visibilityState !== 'visible') return;
    try { await api.heartbeatView(session.id, session.token); } catch (_error) { /* non-blocking */ }
  }

  async function completeView() {
    const session = state.viewSession;
    state.viewSession = null;
    if (!session) return;
    session.timers.forEach((timer) => window.clearTimeout(timer));
    try { await api.completeView(session.id, session.token); } catch (_error) { /* non-blocking */ }
  }

  function closePost() {
    completeView();
    if (els.postDialog.open) els.postDialog.close();
    state.currentPostId = '';
    if (config.parseRoute().kind === 'post') history.pushState({}, '', config.publicPath());
  }

  async function submitPost(event) {
    event.preventDefault();
    if (!requireSession()) return;
    const submit = $('button[type="submit"]', els.createPostForm);
    const postText = els.postText.value.trim();
    const file = els.postImage.files?.[0] || null;
    if (!postText && !file) return toast('Add text or an image.', 'error');
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
      await api.createPost({ text: postText, mediaId });
      els.createPostForm.reset();
      els.imagePreview.hidden = true;
      els.imagePreview.textContent = '';
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

  async function loadBalance() {
    if (!api.isAuthenticated()) return;
    els.balanceStatus.textContent = 'Loading balance…';
    els.ledgerList.innerHTML = '<div class="skeleton line"></div><div class="skeleton line"></div>';
    try {
      const [summary, ledger] = await Promise.all([api.balanceSummary(), api.balanceLedger()]);
      const balance = readBdtBalance(summary);
      state.balanceMicros = Number(balance.available_micros || 0);
      const formatted = formatBdtMicros(state.balanceMicros);
      els.balanceAmount.textContent = formatted;
      els.miniBalance.textContent = formatted;
      els.balanceStatus.textContent = Number(balance.reserved_micros || 0) > 0
        ? `${formatBdtMicros(balance.reserved_micros)} reserved for review.`
        : 'Available for an eligible transfer request.';
      els.transferButton.disabled = state.balanceMicros < 500_000_000;
      renderLedger(ledger.data?.items || []);
    } catch (error) {
      els.balanceStatus.textContent = errorMessage(error);
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
    if (state.balanceMicros < 500_000_000) return toast('Minimum transfer amount is ৳500.', 'error');
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
    const img = document.createElement('img');
    img.alt = 'Selected image preview';
    img.src = URL.createObjectURL(file);
    img.onload = () => URL.revokeObjectURL(img.src);
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
    const length = els.postText.value.length;
    els.postTextCount.textContent = `${length} / 5000`;
    els.postTextCount.parentElement?.classList.toggle('visible', length >= 4500);
    els.postText.style.height = 'auto';
    els.postText.style.height = `${Math.min(240, Math.max(96, els.postText.scrollHeight))}px`;
    if (els.createPostSubmit) {
      els.createPostSubmit.disabled = !els.postText.value.trim() && !els.postImage.files?.[0];
    }
  }

  function bindEvents() {
    $$('[data-route]').forEach((button) => button.addEventListener('click', () => routeTo(button.dataset.route)));
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
    els.postText.addEventListener('input', syncComposerState);
    els.postImage.addEventListener('change', previewImage);
    els.commentForm.addEventListener('submit', submitComment);
    els.postDialogClose.addEventListener('click', closePost);
    els.postDialog.addEventListener('cancel', (event) => { event.preventDefault(); closePost(); });
    els.postDialog.addEventListener('click', (event) => { if (event.target === els.postDialog) closePost(); });
    els.transferButton.addEventListener('click', requestTransfer);
    window.addEventListener('popstate', () => {
      const route = config.parseRoute();
      if (route.kind === 'post') openPost(route.id);
      else if (els.postDialog.open) closePost();
    });
    window.addEventListener('pagehide', () => completeView());
    syncComposerState();
  }

  async function boot() {
    refreshSessionUi();
    bindEvents();
    window.ZNewsAds.mountAll();
    await loadFeed();
    const route = config.parseRoute();
    if (route.kind === 'post') openPost(route.id);
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register(
        config.standalone ? '/sw.js' : '/znews/sw.js',
        { updateViaCache: 'none' }
      ).catch(() => {});
    }
  }

  boot();
})();
