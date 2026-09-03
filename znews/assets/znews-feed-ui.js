(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const ApiClient = window.ZNewsApiClient;
  if (!config || !ApiClient) return;

  const api = new ApiClient(config);
  const requestScheduler = window.ZNEWS_REQUEST_SCHEDULER;
  const analyticsPriority = window.ZNewsRequestScheduler?.PRIORITY?.ANALYTICS ?? 3;
  const fairFeed = window.ZNewsFairFeed && typeof window.ZNewsFairFeed === 'object'
    ? window.ZNewsFairFeed
    : {};
  fairFeed.sessionId = '';
  fairFeed.rankingMode = '';
  fairFeed.sentImpressions = new Set();
  fairFeed.pendingImpressions = new Set();
  window.ZNewsFairFeed = fairFeed;

  function text(value) {
    return String(value ?? '');
  }

  function patchPublicFeed() {
    const original = ApiClient.prototype.publicFeed;
    if (typeof original !== 'function' || original.__znewsFairFeedWrapped) return;

    const wrapped = async function (...args) {
      const cursor = text(args[0]).trim();
      const result = await original.apply(this, args);
      const data = result?.data || {};
      const sessionId = text(data.feed_session_id).trim();
      if (sessionId && sessionId !== fairFeed.sessionId) {
        fairFeed.sessionId = sessionId;
        fairFeed.sentImpressions.clear();
        fairFeed.pendingImpressions.clear();
      }
      fairFeed.rankingMode = text(data.ranking_mode).trim();
      window.dispatchEvent(new CustomEvent('znews:feed-page', {
        detail: {
          feedSessionId: fairFeed.sessionId,
          rankingMode: fairFeed.rankingMode,
          hasMore: data.has_more === true,
          itemCount: Array.isArray(data.items) ? data.items.length : 0,
          append: cursor !== ''
        }
      }));
      return result;
    };
    wrapped.__znewsFairFeedWrapped = true;
    ApiClient.prototype.publicFeed = wrapped;
  }

  patchPublicFeed();

  const feedList = document.querySelector('#feedList');
  const feedLoadMore = document.querySelector('#loadMoreButton');
  const creatorList = document.querySelector('#creatorList');
  const creatorLoadMore = document.querySelector('#creatorLoadMoreButton');
  if (!feedList || !feedLoadMore) return;

  function createPager(list, button, { caughtUpText, activeViewSelector, progressive = false }) {
    button.classList.add('auto-load-source');
    button.setAttribute('aria-hidden', 'true');
    button.tabIndex = -1;

    const sentinel = document.createElement('div');
    sentinel.className = 'feed-scroll-sentinel';
    sentinel.setAttribute('aria-hidden', 'true');
    button.insertAdjacentElement('afterend', sentinel);

    const status = document.createElement('p');
    status.className = 'feed-end-status';
    status.hidden = true;
    status.textContent = caughtUpText;
    sentinel.insertAdjacentElement('afterend', status);

    let intersecting = false;
    let pageLoaded = false;
    let hasMore = false;

    function hasRealCards() {
      return list.querySelector('.post-card:not(.skeleton-card)') !== null;
    }

    function hasResolvedContent() {
      return hasRealCards() || list.querySelector('.empty-state') !== null;
    }

    function viewIsActive() {
      if (!activeViewSelector) return true;
      return document.querySelector(activeViewSelector)?.classList.contains('active') === true;
    }

    function updateStatus() {
      const done = progressive
        ? pageLoaded && button.dataset.feedDone === 'true' && hasRealCards() && viewIsActive()
        : pageLoaded && !hasMore && hasRealCards() && viewIsActive();
      status.hidden = !done;
    }

    function requestNextPage() {
      updateStatus();
      if (!intersecting
        || !viewIsActive()
        || button.hidden
        || button.disabled
        || button.dataset.autoLoadPaused === 'true') return;
      button.click();
    }

    const observer = new IntersectionObserver((entries) => {
      intersecting = entries.some((entry) => entry.isIntersecting);
      if (intersecting) requestNextPage();
    }, { root: null, rootMargin: '700px 0px', threshold: 0 });
    observer.observe(sentinel);

    const mutationObserver = new MutationObserver(() => {
      if (hasResolvedContent()) pageLoaded = true;
      if (pageLoaded && button.hidden) hasMore = false;
      if (!button.hidden) hasMore = true;
      updateStatus();
      window.setTimeout(requestNextPage, 30);
    });
    mutationObserver.observe(button, {
      attributes: true,
      attributeFilter: ['hidden', 'disabled', 'data-auto-load-paused', 'data-feed-done']
    });
    mutationObserver.observe(list, { childList: true });

    return {
      page({ more }) {
        pageLoaded = true;
        hasMore = more === true;
        updateStatus();
        window.setTimeout(requestNextPage, 30);
      },
      refresh() {
        if (hasResolvedContent()) pageLoaded = true;
        updateStatus();
        requestNextPage();
      }
    };
  }

  const feedPager = createPager(feedList, feedLoadMore, {
    caughtUpText: 'You’re all caught up.',
    progressive: true
  });

  const creatorPager = creatorList && creatorLoadMore
    ? createPager(creatorList, creatorLoadMore, {
      caughtUpText: 'All public posts from this creator are shown.',
      activeViewSelector: '[data-view="creator"]'
    })
    : null;

  window.addEventListener('znews:feed-page', (event) => {
    feedPager.page({ more: event.detail?.hasMore === true });
  });

  window.addEventListener('znews:feed-progress', (event) => {
    const detail = event.detail || {};
    feedPager.page({ more: detail.canAdvance === true });
  });

  function actionAttribute(card) {
    return card.matches('.creator-public-post') || card.hasAttribute('data-profile-post-id')
      ? 'data-profile-action'
      : 'data-action';
  }

  function addSeeMore(card) {
    const copy = card.querySelector('.post-copy');
    if (!(copy instanceof HTMLElement)) return;
    if (copy.closest('#postDetail') || copy.closest('#mineList')) return;

    copy.classList.add('feed-preview-copy');
    window.requestAnimationFrame(() => {
      if (!document.contains(copy)) return;
      const existing = card.querySelector('.see-more-button');
      const overflowing = copy.scrollHeight > copy.clientHeight + 2;
      if (!overflowing) {
        existing?.remove();
        return;
      }
      if (existing) return;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'see-more-button';
      button.textContent = 'See more';
      button.setAttribute(actionAttribute(card), 'open');
      copy.insertAdjacentElement('afterend', button);
    });
  }

  function dedupeFeedCards() {
    const seen = new Set();
    feedList.querySelectorAll('.post-card[data-post-id]').forEach((card) => {
      const postId = text(card.dataset.postId).trim();
      if (!postId) return;
      if (seen.has(postId)) {
        card.remove();
        return;
      }
      seen.add(postId);
    });
  }

  const impressionTimers = new WeakMap();
  const observedCards = new WeakSet();
  let impressionFlushTimer = 0;

  async function flushImpressions() {
    window.clearTimeout(impressionFlushTimer);
    impressionFlushTimer = 0;
    const sessionId = text(fairFeed.sessionId).trim();
    if (!sessionId || fairFeed.pendingImpressions.size === 0) return;

    const postIds = [...fairFeed.pendingImpressions]
      .filter((postId) => !fairFeed.sentImpressions.has(postId))
      .slice(0, 12);
    postIds.forEach((postId) => fairFeed.pendingImpressions.delete(postId));
    if (!postIds.length) return;

    try {
      const request = ({ signal }) => api.request('znews/public/impression.php', {
        method: 'POST',
        body: { feed_session_id: sessionId, post_ids: postIds },
        appKey: true,
        signal
      });
      if (requestScheduler && typeof requestScheduler.schedule === 'function') {
        await requestScheduler.schedule(analyticsPriority, request, {
          key: `feed-impression:${sessionId}:${postIds.join(',')}`,
          preemptible: true
        });
      } else {
        await request({ signal: undefined });
      }
      postIds.forEach((postId) => fairFeed.sentImpressions.add(postId));
    } catch (_error) {
      // Telemetry is deliberately non-blocking; cards can be queued again after re-entering the viewport.
    }

    if (fairFeed.pendingImpressions.size > 0) {
      impressionFlushTimer = window.setTimeout(flushImpressions, 600);
    }
  }

  function queueImpression(card) {
    const postId = text(card.dataset.postId).trim();
    if (!postId || fairFeed.sentImpressions.has(postId)) return;
    fairFeed.pendingImpressions.add(postId);
    if (!impressionFlushTimer) impressionFlushTimer = window.setTimeout(flushImpressions, 650);
  }

  const impressionObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      const card = entry.target;
      const currentTimer = impressionTimers.get(card);
      if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
        if (currentTimer) return;
        const timer = window.setTimeout(() => {
          impressionTimers.delete(card);
          if (document.contains(card)) queueImpression(card);
        }, 700);
        impressionTimers.set(card, timer);
      } else if (currentTimer) {
        window.clearTimeout(currentTimer);
        impressionTimers.delete(card);
      }
    });
  }, { threshold: [0, 0.5, 0.75] });

  function decorateFeed(root = feedList) {
    dedupeFeedCards();
    root.querySelectorAll?.('.post-card[data-post-id]').forEach((card) => {
      addSeeMore(card);
      if (!observedCards.has(card)) {
        observedCards.add(card);
        impressionObserver.observe(card);
      }
    });
  }

  function decorateCreator(root = creatorList) {
    if (!root) return;
    root.querySelectorAll?.('.post-card').forEach(addSeeMore);
    creatorPager?.refresh();
  }

  const feedMutation = new MutationObserver((records) => {
    records.forEach((record) => record.addedNodes.forEach((node) => {
      if (node instanceof Element) decorateFeed(node.matches('.post-card') ? feedList : node);
    }));
    feedPager.refresh();
  });
  feedMutation.observe(feedList, { childList: true, subtree: true });

  if (creatorList) {
    const creatorMutation = new MutationObserver((records) => {
      records.forEach((record) => record.addedNodes.forEach((node) => {
        if (node instanceof Element) decorateCreator(node.matches('.post-card') ? creatorList : node);
      }));
      creatorPager?.refresh();
    });
    creatorMutation.observe(creatorList, { childList: true, subtree: true });
  }

  let resizeTimer = 0;
  window.addEventListener('resize', () => {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => {
      feedList.querySelectorAll('.post-card').forEach(addSeeMore);
      creatorList?.querySelectorAll('.post-card').forEach(addSeeMore);
    }, 120);
  });

  decorateFeed();
  decorateCreator();
})();
