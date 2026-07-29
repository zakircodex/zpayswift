(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const ApiClient = window.ZNewsApiClient;
  if (!config || !ApiClient) return;

  const api = new ApiClient(config);
  const dialog = document.querySelector('#postDialog');
  const detail = document.querySelector('#postDetail');
  const commentList = document.querySelector('#commentList');
  const form = document.querySelector('#commentForm');
  const input = document.querySelector('#commentText');
  const sendButton = form?.querySelector('button[type="submit"]');
  const composerAvatar = document.querySelector('#commentComposerAvatar');
  const guestCta = document.querySelector('#commentGuestCta');
  const title = document.querySelector('#postReaderTitle');
  const closeButton = document.querySelector('#postDialogClose');
  const readerScroll = document.querySelector('#postReaderScroll');

  if (!dialog || !detail || !commentList || !form || !input || !sendButton || !closeButton) return;

  const state = {
    comments: [],
    nextCursor: '',
    hasMore: false,
    loadingMore: false,
    openedFromFeed: false,
    feedScrollY: 0,
    initialPostPath: /^\/znews\/post\/[A-Za-z0-9_-]+\/?$/.test(window.location.pathname)
  };

  function text(value) {
    return String(value ?? '').trim();
  }

  function safeUrl(value) {
    const raw = text(value);
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.origin);
      if (url.protocol !== 'https:' && url.origin !== window.location.origin) return '';
      return url.toString();
    } catch (_error) {
      return '';
    }
  }

  function profileName() {
    return text(api.profile?.name || api.profile?.NAME || api.profile?.display_name || api.profile?.phone || 'Z-Pay user');
  }

  function profilePhoto() {
    return safeUrl(api.profile?.profile_photo_url || api.profile?.photo_url || api.profile?.PROFILE || '');
  }

  function setAvatar(element, name, photoUrl) {
    if (!(element instanceof HTMLElement)) return;
    element.textContent = '';
    const photo = safeUrl(photoUrl);
    if (photo) {
      const image = document.createElement('img');
      image.src = photo;
      image.alt = '';
      image.referrerPolicy = 'no-referrer';
      element.appendChild(image);
      return;
    }
    element.textContent = text(name).charAt(0).toUpperCase() || 'Z';
  }

  function currentPostId() {
    const cardId = text(detail.querySelector('[data-post-id]')?.dataset.postId);
    if (cardId) return cardId;
    const match = window.location.pathname.match(/^\/znews\/post\/([A-Za-z0-9_-]+)\/?$/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  function formatTime(seconds) {
    const timestamp = Number(seconds || 0) * 1000;
    if (!timestamp) return '';
    const diff = Date.now() - timestamp;
    if (diff >= 0 && diff < 60_000) return 'Just now';
    if (diff >= 0 && diff < 3_600_000) return `${Math.max(1, Math.floor(diff / 60_000))}m`;
    if (diff >= 0 && diff < 86_400_000) return `${Math.max(1, Math.floor(diff / 3_600_000))}h`;
    if (diff >= 0 && diff < 604_800_000) return `${Math.max(1, Math.floor(diff / 86_400_000))}d`;
    return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(new Date(timestamp));
  }

  function cacheComments(payload) {
    const data = payload?.data || {};
    const items = Array.isArray(data.items) ? data.items : [];
    state.comments = items;
    state.nextCursor = text(data.next_cursor);
    state.hasMore = data.has_more === true;
    window.dispatchEvent(new CustomEvent('znews:comments-page', {
      detail: { items, nextCursor: state.nextCursor, hasMore: state.hasMore }
    }));
  }

  function wrapApiMethod(methodName, after) {
    const original = ApiClient.prototype[methodName];
    if (typeof original !== 'function' || original.__znewsReaderWrapped) return;
    const wrapped = async function (...args) {
      const result = await original.apply(this, args);
      after(result, args);
      return result;
    };
    wrapped.__znewsReaderWrapped = true;
    ApiClient.prototype[methodName] = wrapped;
  }

  wrapApiMethod('comments', cacheComments);
  wrapApiMethod('publicPost', (payload) => {
    const post = payload?.data?.post || {};
    const creator = text(post.creator_name || 'Z News');
    if (title) title.textContent = creator ? `${creator}'s post` : 'Z News post';
  });

  function syncAccessUi() {
    const authenticated = api.isAuthenticated();
    form.hidden = !authenticated;
    form.setAttribute('aria-hidden', authenticated ? 'false' : 'true');
    if (guestCta) {
      guestCta.hidden = authenticated;
      guestCta.setAttribute('aria-hidden', authenticated ? 'true' : 'false');
    }
    setAvatar(composerAvatar, profileName(), profilePhoto());
  }

  function resizeComposer() {
    input.style.height = 'auto';
    input.style.height = `${Math.min(112, Math.max(42, input.scrollHeight))}px`;
    const hasText = text(input.value) !== '';
    sendButton.disabled = !hasText || sendButton.dataset.sending === 'true';
    sendButton.classList.toggle('is-ready', hasText);
  }

  function findCachedComment(index, row) {
    const explicitId = text(row.dataset.commentId);
    if (explicitId) {
      return state.comments.find((item) => text(item.comment_id) === explicitId) || null;
    }
    return state.comments[index] || null;
  }

  function commentKey(row) {
    const id = text(row.dataset.commentId);
    if (id) return `id:${id}`;
    const name = text(row.querySelector('.comment-bubble strong')?.textContent);
    const body = text(row.querySelector('.comment-bubble p')?.textContent);
    const time = text(row.querySelector('.comment-bubble small')?.textContent);
    return `content:${name}|${body}|${time}`;
  }

  function decorateComment(row, index) {
    if (!(row instanceof HTMLElement) || row.dataset.readerReady === 'true') return;
    const bubble = row.querySelector('.comment-bubble');
    const avatar = row.querySelector('.avatar');
    if (!(bubble instanceof HTMLElement) || !(avatar instanceof HTMLElement)) return;

    const cached = findCachedComment(index, row);
    if (cached) {
      row.dataset.commentId = text(cached.comment_id);
      row.dataset.authorUid = text(cached.author_uid);
      const cachedPhoto = safeUrl(cached.author_photo_url);
      if (cachedPhoto && !avatar.querySelector('img')) setAvatar(avatar, cached.author_name, cachedPhoto);
    }

    const content = document.createElement('div');
    content.className = 'comment-content';
    bubble.insertAdjacentElement('beforebegin', content);
    content.appendChild(bubble);

    const oldTime = bubble.querySelector('small');
    const actionRow = document.createElement('div');
    actionRow.className = 'comment-action-row';
    const time = document.createElement('span');
    time.textContent = cached ? formatTime(cached.created_at) : text(oldTime?.textContent);
    actionRow.appendChild(time);
    oldTime?.remove();
    content.appendChild(actionRow);

    row.dataset.readerReady = 'true';
  }

  function dedupeAndDecorateComments() {
    const seen = new Set();
    [...commentList.querySelectorAll('.comment')].forEach((row, index) => {
      decorateComment(row, index);
      const key = commentKey(row);
      if (seen.has(key)) row.remove();
      else seen.add(key);
    });
    syncMoreCommentsControl();
  }

  function createMoreCommentsButton() {
    let button = document.querySelector('#commentLoadMoreButton');
    if (button) return button;
    button = document.createElement('button');
    button.id = 'commentLoadMoreButton';
    button.className = 'comment-load-more';
    button.type = 'button';
    button.textContent = 'View more comments';
    commentList.insertAdjacentElement('beforebegin', button);
    button.addEventListener('click', loadMoreComments);
    return button;
  }

  function syncMoreCommentsControl() {
    const button = createMoreCommentsButton();
    button.hidden = !state.hasMore;
    button.disabled = state.loadingMore;
    button.textContent = state.loadingMore ? 'Loading comments…' : 'View more comments';
  }

  function buildCommentRow(comment) {
    const row = document.createElement('div');
    row.className = 'comment';
    row.dataset.commentId = text(comment.comment_id);
    row.dataset.authorUid = text(comment.author_uid);

    const avatar = document.createElement('span');
    avatar.className = 'avatar';
    setAvatar(avatar, comment.author_name || 'Z-Pay user', comment.author_photo_url || '');

    const bubble = document.createElement('div');
    bubble.className = 'comment-bubble';
    const author = document.createElement('strong');
    author.textContent = text(comment.author_name || 'Z-Pay user');
    const body = document.createElement('p');
    body.textContent = text(comment.text || comment.message);
    bubble.append(author, body);
    row.append(avatar, bubble);
    return row;
  }

  async function loadMoreComments() {
    if (state.loadingMore || !state.hasMore || !state.nextCursor) return;
    const postId = currentPostId();
    if (!postId) return;
    state.loadingMore = true;
    syncMoreCommentsControl();
    try {
      const result = await api.comments(postId, state.nextCursor);
      const items = Array.isArray(result.data?.items) ? result.data.items : [];
      items.forEach((comment) => commentList.appendChild(buildCommentRow(comment)));
      state.comments = [...state.comments, ...items];
      state.nextCursor = text(result.data?.next_cursor);
      state.hasMore = result.data?.has_more === true;
      dedupeAndDecorateComments();
    } catch (_error) {
      // Existing comments remain readable; the user can retry the button.
    } finally {
      state.loadingMore = false;
      syncMoreCommentsControl();
    }
  }

  function dialogOpened() {
    document.documentElement.classList.add('znews-post-reader-open');
    document.body.classList.add('znews-post-reader-open');
    syncAccessUi();
    resizeComposer();
    window.setTimeout(() => {
      dedupeAndDecorateComments();
      readerScroll?.scrollTo({ top: 0, behavior: 'instant' });
      const current = window.history.state && typeof window.history.state === 'object'
        ? window.history.state
        : {};
      window.history.replaceState({
        ...current,
        znewsReader: true,
        znewsFeedScrollY: state.feedScrollY
      }, '', window.location.href);
    }, 0);
  }

  function dialogClosed() {
    document.documentElement.classList.remove('znews-post-reader-open');
    document.body.classList.remove('znews-post-reader-open');
    if (state.openedFromFeed) {
      const target = Number(window.history.state?.znewsFeedScrollY ?? state.feedScrollY ?? 0);
      window.setTimeout(() => window.scrollTo({ top: target, behavior: 'instant' }), 0);
    }
    state.openedFromFeed = false;
  }

  const dialogObserver = new MutationObserver(() => {
    if (dialog.open) dialogOpened();
    else dialogClosed();
  });
  dialogObserver.observe(dialog, { attributes: true, attributeFilter: ['open'] });

  const commentObserver = new MutationObserver(dedupeAndDecorateComments);
  commentObserver.observe(commentList, { childList: true, subtree: true });

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    const action = target.closest('[data-action]')?.dataset.action;
    const feedCard = target.closest('#feedList [data-post-id], #creatorList [data-profile-post-id]');
    if (feedCard && (action === 'open' || action === 'comment' || target.closest('[data-profile-action="open"]'))) {
      state.openedFromFeed = true;
      state.feedScrollY = window.scrollY;
      const current = window.history.state && typeof window.history.state === 'object'
        ? window.history.state
        : {};
      window.history.replaceState({ ...current, znewsFeedScrollY: state.feedScrollY }, '', window.location.href);
    }
  }, true);

  closeButton.addEventListener('click', (event) => {
    if (!state.openedFromFeed || !window.location.pathname.startsWith('/znews/post/')) return;
    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();
    window.history.back();
  }, true);

  window.addEventListener('popstate', (event) => {
    if (window.location.pathname.startsWith('/znews/post/')) return;
    const target = Number(event.state?.znewsFeedScrollY ?? state.feedScrollY ?? 0);
    window.setTimeout(() => window.scrollTo({ top: target, behavior: 'instant' }), 40);
  });

  input.addEventListener('input', resizeComposer);
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      if (!sendButton.disabled) form.requestSubmit();
    }
  });

  window.addEventListener('znews:comments-page', () => window.setTimeout(dedupeAndDecorateComments, 0));
  window.addEventListener('znews:comment-created', (event) => {
    const comment = event.detail?.comment;
    if (!comment || event.detail?.published !== true) return;
    state.comments.push(comment);
    window.setTimeout(dedupeAndDecorateComments, 0);
  });

  syncAccessUi();
  resizeComposer();
  dedupeAndDecorateComments();
})();