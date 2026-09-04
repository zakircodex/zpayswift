(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const ApiClient = window.ZNewsApiClient;
  if (!config || !ApiClient) return;

  const registry = new Map();
  const api = new ApiClient(config);
  const state = {
    uid: '',
    cursor: '',
    hasMore: false,
    loading: false
  };

  const els = {
    view: document.querySelector('#creatorView'),
    list: document.querySelector('#creatorList'),
    avatar: document.querySelector('#creatorProfileAvatar'),
    name: document.querySelector('#creatorProfileName'),
    meta: document.querySelector('#creatorProfileMeta'),
    back: document.querySelector('#creatorBackButton'),
    loadMore: document.querySelector('#creatorLoadMoreButton')
  };

  if (!els.view || !els.list || !els.avatar || !els.name || !els.meta || !els.back || !els.loadMore) return;

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
    const diff = Date.now() - timestamp;
    if (diff >= 0 && diff < 60_000) return 'Just now';
    if (diff >= 0 && diff < 3_600_000) return `${Math.max(1, Math.floor(diff / 60_000))}m`;
    if (diff >= 0 && diff < 86_400_000) return `${Math.floor(diff / 3_600_000)}h`;
    return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(timestamp));
  }

  function rememberPost(post) {
    const postId = text(post?.post_id).trim();
    if (postId) registry.set(postId, post);
  }

  function rememberPayload(payload) {
    const data = payload?.data || {};
    if (Array.isArray(data.items)) data.items.forEach(rememberPost);
    if (data.post && typeof data.post === 'object') rememberPost(data.post);
  }

  function wrapApiMethod(methodName) {
    const original = ApiClient.prototype[methodName];
    if (typeof original !== 'function' || original.__znewsProfileWrapped) return;

    const wrapped = async function (...args) {
      const result = await original.apply(this, args);
      rememberPayload(result);
      return result;
    };
    wrapped.__znewsProfileWrapped = true;
    ApiClient.prototype[methodName] = wrapped;
  }

  wrapApiMethod('publicFeed');
  wrapApiMethod('publicPost');

  function setAvatar(name, photoUrl) {
    els.avatar.textContent = '';
    const photo = safeUrl(photoUrl);
    if (photo) {
      const image = document.createElement('img');
      image.src = photo;
      image.alt = '';
      image.referrerPolicy = 'no-referrer';
      els.avatar.appendChild(image);
      return;
    }
    els.avatar.textContent = text(name || 'Z').trim().charAt(0).toUpperCase() || 'Z';
  }

  function decorateCards(root = document) {
    root.querySelectorAll?.('.post-card[data-post-id]').forEach((card) => {
      const post = registry.get(text(card.dataset.postId));
      const creatorUid = text(post?.creator_uid).trim();
      if (!creatorUid) return;

      card.dataset.creatorUid = creatorUid;
      const head = card.querySelector('.post-head');
      if (!head || head.dataset.creatorProfileReady === 'true') return;
      head.dataset.creatorProfileReady = 'true';
      head.classList.add('creator-profile-trigger');
      head.setAttribute('role', 'button');
      head.setAttribute('tabindex', '0');
      head.setAttribute('aria-label', `View all public posts by ${text(post.creator_name || 'this creator')}`);
    });
  }

  function showView(viewName) {
    document.querySelectorAll('.view').forEach((view) => {
      view.classList.toggle('active', view.dataset.view === viewName);
    });
    document.querySelectorAll('[data-route]').forEach((item) => item.classList.remove('active'));
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function showFeed() {
    const feedButton = document.querySelector('.desktop-nav [data-route="feed"]')
      || document.querySelector('[data-route="feed"]');
    if (feedButton instanceof HTMLElement) {
      feedButton.click();
    } else {
      showView('feed');
    }
  }

  function creatorPath(uid) {
    return config.publicPath('creator', uid);
  }

  function profilePostMarkup(post) {
    const id = text(post.post_id);
    const name = text(post.creator_name || 'Z Sky 24 creator');
    const photo = safeUrl(post.creator_photo_url);
    const image = safeUrl(post.image_url);
    const title = text(post.title).trim();
    const body = text(post.text);
    const bodyHtml = window.ZNewsRichText?.formattedTextHtml(body, post.bold_ranges) || escapeHtml(body);
    const avatar = photo
      ? `<span class="avatar"><img src="${escapeHtml(photo)}" alt="" referrerpolicy="no-referrer"></span>`
      : `<span class="avatar">${escapeHtml(name.charAt(0).toUpperCase() || 'Z')}</span>`;

    return `<article class="post-card card creator-public-post" data-profile-post-id="${escapeHtml(id)}">
      <header class="post-head">${avatar}<div class="post-author"><strong>${escapeHtml(name)}</strong><span>${escapeHtml(formatTime(post.created_at))}</span></div></header>
      ${title ? `<button class="profile-post-open post-title" type="button" data-profile-action="open">${escapeHtml(title)}</button>` : ''}
      ${body ? `<button class="profile-post-open post-copy" type="button" data-profile-action="open">${bodyHtml}</button>` : ''}
      ${image ? `<button class="profile-post-media-button post-media-frame" type="button" data-profile-action="open"><img class="post-media-backdrop" src="${escapeHtml(image)}" alt="" aria-hidden="true" loading="lazy"><img class="post-media" src="${escapeHtml(image)}" alt="Image shared by ${escapeHtml(name)}" loading="lazy"></button>` : ''}
      <div class="post-meta"><span>${Number(post.like_count || 0)} likes</span><span>${Number(post.comment_count || 0)} comments • ${Number(post.share_count || 0)} shares</span></div>
      <div class="profile-post-actions"><button type="button" data-profile-action="open">Read post</button><button type="button" data-profile-action="share">↗ Share</button></div>
    </article>`;
  }

  function renderSkeletons() {
    els.list.innerHTML = '<article class="post-card card skeleton-card"><div class="skeleton line short"></div><div class="skeleton line"></div><div class="skeleton block"></div></article><article class="post-card card skeleton-card"><div class="skeleton line short"></div><div class="skeleton block"></div></article>';
  }

  async function loadCreator({ append = false } = {}) {
    if (state.loading || !state.uid) return;
    state.loading = true;
    if (!append) renderSkeletons();
    els.loadMore.disabled = true;
    els.loadMore.textContent = 'Loading…';

    try {
      const result = await api.request('znews/public/creator.php', {
        params: {
          creator_uid: state.uid,
          limit: config.creatorPublicPageSize || 12,
          cursor: append ? state.cursor : ''
        },
        appKey: false
      });
      const creator = result.data?.creator || {};
      const items = Array.isArray(result.data?.items) ? result.data.items : [];
      items.forEach(rememberPost);

      const name = text(creator.name || items[0]?.creator_name || 'Z Sky 24 creator');
      const photo = creator.profile_photo_url || items[0]?.creator_photo_url || '';
      els.name.textContent = name;
      setAvatar(name, photo);

      if (!append) els.list.textContent = '';
      const holder = document.createElement('div');
      holder.innerHTML = items.map(profilePostMarkup).join('');
      while (holder.firstElementChild) els.list.appendChild(holder.firstElementChild);

      const shown = els.list.querySelectorAll('[data-profile-post-id]').length;
      els.meta.textContent = shown === 1 ? '1 public post' : `${shown} public posts`;
      if (!shown) {
        els.list.innerHTML = '<div class="empty-state card"><strong>No public posts</strong>This creator has no public stories available.</div>';
      }

      state.cursor = text(result.data?.next_cursor);
      state.hasMore = result.data?.has_more === true;
      els.loadMore.hidden = !state.hasMore;
    } catch (error) {
      if (!append) {
        els.list.innerHTML = `<div class="empty-state card"><strong>Creator posts could not be loaded</strong>${escapeHtml(error?.message || 'Please try again.')}</div>`;
      }
    } finally {
      state.loading = false;
      els.loadMore.disabled = false;
      els.loadMore.textContent = 'Load more posts';
    }
  }

  function openCreator(uid, { pushHistory = true } = {}) {
    const creatorUid = text(uid).trim();
    if (!creatorUid) return;

    document.querySelector('#postDialogClose')?.click();
    state.uid = creatorUid;
    state.cursor = '';
    state.hasMore = false;
    els.name.textContent = 'Loading creator…';
    els.meta.textContent = 'Public posts';
    setAvatar('Z', '');
    showView('creator');

    const path = creatorPath(creatorUid);
    if (pushHistory && window.location.pathname !== path) {
      history.pushState({ znewsCreatorUid: creatorUid }, '', path);
    }
    loadCreator();
  }

  async function sharePost(postId) {
    const url = config.canonicalUrl('post', postId);
    const headline = text(registry.get(postId)?.title).trim();
    try {
      if (navigator.share) {
        await navigator.share({
          title: headline || 'Z Sky 24',
          text: headline || 'Read this story on Z Sky 24',
          url
        });
      } else {
        await navigator.clipboard.writeText(url);
      }
    } catch (error) {
      if (error?.name !== 'AbortError') window.location.assign(url);
    }
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const profileAction = target.closest('[data-profile-action]');
    if (profileAction) {
      const card = profileAction.closest('[data-profile-post-id]');
      const postId = text(card?.dataset.profilePostId);
      if (!postId) return;
      event.preventDefault();
      if (profileAction.dataset.profileAction === 'share') sharePost(postId);
      else window.location.assign(config.publicPath('post', postId));
      return;
    }

    const head = target.closest('.post-card[data-post-id] .post-head');
    if (!head || target.closest('[data-action], button, a')) return;
    const card = head.closest('.post-card[data-post-id]');
    const creatorUid = text(card?.dataset.creatorUid || registry.get(text(card?.dataset.postId))?.creator_uid).trim();
    if (!creatorUid) return;
    event.preventDefault();
    event.stopPropagation();
    openCreator(creatorUid);
  }, true);

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const target = event.target;
    if (!(target instanceof Element) || !target.matches('.creator-profile-trigger')) return;
    const card = target.closest('.post-card[data-post-id]');
    const creatorUid = text(card?.dataset.creatorUid).trim();
    if (!creatorUid) return;
    event.preventDefault();
    openCreator(creatorUid);
  });

  const cardObserver = new MutationObserver((records) => {
    records.forEach((record) => record.addedNodes.forEach((node) => {
      if (node instanceof Element) decorateCards(node.matches('.post-card') ? node.parentElement || node : node);
    }));
  });
  cardObserver.observe(document.body, { childList: true, subtree: true });

  els.back.addEventListener('click', () => {
    if (config.parseRoute().kind === 'creator') {
      history.back();
    } else {
      showFeed();
    }
  });
  els.loadMore.addEventListener('click', () => loadCreator({ append: true }));

  window.addEventListener('popstate', () => {
    const route = config.parseRoute();
    if (route.kind === 'creator') {
      openCreator(route.id, { pushHistory: false });
    } else if (els.view.classList.contains('active')) {
      showFeed();
    }
  });

  decorateCards();
  const initial = config.parseRoute();
  if (initial.kind === 'creator') openCreator(initial.id, { pushHistory: false });

  window.ZNewsCreatorProfile = Object.freeze({ open: openCreator });
})();
