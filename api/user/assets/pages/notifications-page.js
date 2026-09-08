(() => {
  'use strict';

  const shell = window.UserShell;
  const $ = (id) => document.getElementById(id);
  if (!shell || !$('notificationsSection')) return;

  const releaseInitialLoad = shell.holdPageLoad?.('Loading notifications...') || (() => {});
  const scrollBody = document.querySelector('.notification-page-scroll-body');
  const pageSize = 10;
  const pullThreshold = 68;
  const pullLimit = 108;
  const state = {
    filter: 'ALL',
    items: [],
    selected: new Set(),
    editing: false,
    loading: false,
    loadingMore: false,
    loadMoreError: false,
    loaded: false,
    hasMore: false,
    nextBefore: 0,
    nextBeforeId: '',
    active: null,
    opener: null,
    listSerial: 0,
    detailSerial: 0,
    pullTracking: false,
    pullDirectionLocked: false,
    pullStartX: 0,
    pullStartY: 0,
    pullDistance: 0
  };

  function dateText(value) {
    const numeric = Number(value || 0);
    if (!numeric) return '-';
    return new Date(numeric < 1000000000000 ? numeric * 1000 : numeric).toLocaleString();
  }

  function glyph(item) {
    const type = String(item?.type || '').toUpperCase();
    if (type.includes('FAILED') || type.includes('REJECTED')) return '!';
    if (type.startsWith('SUPPORT_')) return 'S';
    if (type.startsWith('ACCOUNT_') || type === 'SECURITY_REVIEW' || type === 'LOGIN_ALERT') return 'A';
    if (type.includes('TRANSFER') || type.includes('MONEY') || type.includes('SUCCESS')) return '$';
    return 'Z';
  }

  function categoryText(item) {
    const category = String(item?.category || '').toUpperCase();
    return ({
      TRANSACTIONS: 'Transaction',
      SECURITY: 'Security',
      SUPPORT: 'Support',
      NOTICE: 'Notice'
    })[category] || 'Update';
  }

  function destination(item) {
    const type = String(item?.type || '').toUpperCase();
    if (type.startsWith('SUPPORT_')) return '/user/support';
    if (type.startsWith('ACCOUNT_') || type === 'SECURITY_REVIEW' || type === 'LOGIN_ALERT') return '/user/profile';
    if (type === 'ADMIN_NOTICE' || type === 'RINGGIT_RATE_UPDATED') return '';
    return '/user/history';
  }

  function safeError(error, fallback) {
    const message = String(error?.message || '').trim();
    return message && message.length <= 180 ? message : fallback;
  }

  function updateControls() {
    const busy = state.loading || state.loadingMore;
    document.querySelectorAll('[data-notification-filter]').forEach((tab) => {
      const active = tab.dataset.notificationFilter === state.filter;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.disabled = busy;
    });
    $('notificationUnreadCount').textContent = String(shell.state.unread || 0);
    $('notificationsEditButton').setAttribute('aria-pressed', state.editing ? 'true' : 'false');
    $('notificationsEditButton').setAttribute('aria-label', state.editing ? 'Finish selecting notifications' : 'Select notifications');
    $('notificationsEditButton').disabled = busy || !state.loaded;
    $('notificationEditBar').classList.toggle('hidden', !state.editing);
    $('notificationsDeleteButton').disabled = !state.selected.size || busy;
    $('notificationsMarkSelectedButton').disabled = !state.selected.size || busy;
    $('notificationsSelectAllButton').disabled = busy || !state.items.length;
    $('notificationsSelectAllButton').textContent =
      state.items.length && state.items.every((item) => state.selected.has(String(item.notification_id || '')))
        ? 'Clear All' : 'Select All';
  }

  function renderLoading() {
    const list = $('notificationList');
    list.setAttribute('aria-busy', 'true');
    list.innerHTML = '<div class="notification-page-skeleton"></div><div class="notification-page-skeleton"></div><div class="notification-page-skeleton"></div>';
    updateLoadMore();
    $('notificationPageLive').textContent = 'Loading notifications.';
  }

  function renderError(error) {
    const message = safeError(error, 'Please check your connection and try again.');
    const list = $('notificationList');
    list.setAttribute('aria-busy', 'false');
    list.innerHTML = `<div class="notification-page-state notification-page-error"><span class="notification-page-state-icon">!</span><h3>Notifications could not be loaded</h3><p>${shell.escapeHtml(message)}</p><button id="notificationRetry" class="notification-page-retry" type="button">Retry</button></div>`;
    state.hasMore = false;
    updateLoadMore();
    $('notificationRetry')?.addEventListener('click', () => load({ preserve: false }));
    $('notificationPageLive').textContent = 'Notifications could not be loaded.';
  }

  function render() {
    const list = $('notificationList');
    list.setAttribute('aria-busy', 'false');
    if (!state.items.length) {
      list.innerHTML = `<div class="notification-page-state"><span class="notification-page-state-icon">Z</span><h3>${state.filter === 'UNREAD' ? 'You are all caught up' : 'No notifications yet'}</h3><p>Important account and transaction updates will appear here.</p></div>`;
      updateLoadMore();
      $('notificationPageLive').textContent = state.filter === 'UNREAD' ? 'No unread notifications.' : 'No notifications.';
      return;
    }

    list.replaceChildren();
    state.items.forEach((item) => {
      const id = String(item.notification_id || '');
      const selected = state.selected.has(id);
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.notificationId = id;
      button.className = `notification-page-card${item.is_read ? '' : ' unread'}${selected ? ' selected' : ''}`;
      button.setAttribute('aria-label', `${item.is_read ? '' : 'Unread. '}${item.title || 'Z-Pay Swift'}`);
      if (state.editing) button.setAttribute('aria-pressed', selected ? 'true' : 'false');
      button.innerHTML = `
        <span class="notification-page-card-icon" aria-hidden="true">${shell.escapeHtml(glyph(item))}</span>
        <span class="notification-page-card-content">
          <span class="notification-page-card-meta"><span>${shell.escapeHtml(categoryText(item))}</span><time>${shell.escapeHtml(dateText(item.created_at))}</time></span>
          <strong>${shell.escapeHtml(item.title || 'Z-Pay Swift')}</strong>
          <span class="notification-page-card-body">${shell.escapeHtml(item.body || '')}</span>
        </span>
        ${state.editing
          ? `<span class="notification-page-select-indicator">${selected ? '&#10003;' : ''}</span>`
          : (!item.is_read ? '<span class="notification-page-unread-dot" aria-label="Unread"></span>' : '')}`;
      button.addEventListener('click', () => state.editing ? toggle(id) : openDetail(item, button));
      list.appendChild(button);
    });
    updateLoadMore();
    $('notificationPageLive').textContent = `${state.items.length} notifications loaded.`;
  }

  function updateLoadMore() {
    const footer = $('notificationLoadMore');
    const button = $('notificationLoadMoreButton');
    if (!footer || !button) return;
    const visible = state.loaded && state.items.length > 0 && (state.hasMore || state.loadingMore || state.loadMoreError);
    footer.classList.toggle('hidden', !visible);
    footer.classList.toggle('is-loading', state.loadingMore);
    footer.setAttribute('aria-hidden', visible ? 'false' : 'true');
    button.disabled = state.loadingMore;
    button.textContent = state.loadingMore ? 'Loading more...' : (state.loadMoreError ? 'Retry loading more' : 'Load more');
  }

  async function load(options = {}) {
    if (state.loading) return;
    const preserve = options.preserve === true && state.loaded;
    const serial = ++state.listSerial;
    state.loading = true;
    updateControls();
    if (!preserve) renderLoading();
    else $('notificationPageLive').textContent = 'Refreshing notifications.';

    try {
      const data = await shell.get(
        'notifications_list',
        { limit: pageSize, filter: state.filter },
        'Loading notifications...',
        { busy: false }
      );
      if (serial !== state.listSerial) return;
      state.items = Array.isArray(data.items) ? data.items : [];
      state.loaded = true;
      state.nextBefore = Number(data.next_before || 0);
      state.nextBeforeId = String(data.next_before_id || '');
      state.hasMore = data.has_more === true && state.nextBefore > 0 && state.nextBeforeId !== '';
      state.loadMoreError = false;
      const visibleIds = new Set(state.items.map((item) => String(item.notification_id || '')));
      state.selected = new Set(Array.from(state.selected).filter((id) => visibleIds.has(id)));
      shell.state.unread = Number(data.unread_count || 0);
      render();
    } catch (error) {
      if (serial !== state.listSerial) return;
      if (preserve) {
        shell.toast('Notifications could not be refreshed.', 'error');
        $('notificationPageLive').textContent = 'Notifications refresh failed. Existing notifications remain visible.';
      } else {
        renderError(error);
      }
    } finally {
      if (serial === state.listSerial) {
        state.loading = false;
        updateControls();
      }
    }
  }

  async function loadMore() {
    if (state.loading || state.loadingMore || !state.loaded || !state.hasMore || state.nextBefore <= 0) return;
    const serial = state.listSerial;
    const before = state.nextBefore;
    const beforeId = state.nextBeforeId;
    state.loadingMore = true;
    state.loadMoreError = false;
    updateControls();
    updateLoadMore();

    try {
      const data = await shell.get(
        'notifications_list',
        { limit: pageSize, filter: state.filter, before, before_id: beforeId },
        'Loading more notifications...',
        { busy: false }
      );
      if (serial !== state.listSerial) return;
      const incoming = Array.isArray(data.items) ? data.items : [];
      const known = new Set(state.items.map((item) => String(item.notification_id || '')));
      incoming.forEach((item) => {
        const id = String(item.notification_id || '');
        if (id && !known.has(id)) {
          known.add(id);
          state.items.push(item);
        }
      });
      const nextBefore = Number(data.next_before || 0);
      const nextBeforeId = String(data.next_before_id || '');
      state.nextBefore = nextBefore;
      state.nextBeforeId = nextBeforeId;
      state.hasMore = data.has_more === true
        && incoming.length > 0
        && nextBefore > 0
        && nextBeforeId !== ''
        && (nextBefore !== before || nextBeforeId !== beforeId);
      state.loadMoreError = false;
      shell.state.unread = Number(data.unread_count ?? shell.state.unread);
      render();
    } catch (error) {
      if (serial !== state.listSerial) return;
      state.loadMoreError = true;
      shell.toast('More notifications could not be loaded.', 'error');
      $('notificationPageLive').textContent = 'More notifications could not be loaded. Retry is available.';
    } finally {
      if (serial === state.listSerial) {
        state.loadingMore = false;
        updateControls();
        updateLoadMore();
      }
    }
  }

  function bindProgressiveLoading() {
    if (!scrollBody) return;
    scrollBody.addEventListener('scroll', () => {
      if (scrollBody.scrollTop <= 0) return;
      const remaining = scrollBody.scrollHeight - scrollBody.scrollTop - scrollBody.clientHeight;
      if (remaining <= 180) loadMore();
    }, { passive: true });
    $('notificationLoadMoreButton')?.addEventListener('click', () => loadMore());
  }

  function updatePullIndicator(distance, refreshing = false) {
    const indicator = $('notificationPullIndicator');
    const label = $('notificationPullText');
    if (!indicator || !label) return;
    const safeDistance = Math.max(0, Math.min(pullLimit, Number(distance || 0)));
    state.pullDistance = safeDistance;
    indicator.style.height = `${Math.round(safeDistance * 0.72)}px`;
    indicator.style.setProperty('--notification-pull-rotation', String(Math.round((safeDistance / pullThreshold) * 250)));
    indicator.classList.toggle('is-ready', safeDistance >= pullThreshold && !refreshing);
    indicator.classList.toggle('is-refreshing', refreshing);
    indicator.setAttribute('aria-hidden', safeDistance > 0 || refreshing ? 'false' : 'true');
    label.textContent = refreshing
      ? 'Refreshing...'
      : (safeDistance >= pullThreshold ? 'Release to refresh' : 'Pull to refresh');
  }

  function resetPullIndicator(animate = true) {
    const indicator = $('notificationPullIndicator');
    indicator?.classList.toggle('is-resetting', animate);
    updatePullIndicator(0, false);
    if (animate) window.setTimeout(() => indicator?.classList.remove('is-resetting'), 220);
    state.pullTracking = false;
    state.pullDirectionLocked = false;
    state.pullStartX = 0;
    state.pullStartY = 0;
  }

  function notificationDetailOpen() {
    return !$('notificationDetailModal')?.classList.contains('hidden');
  }

  function bindPullToRefresh() {
    if (!scrollBody) return;
    scrollBody.addEventListener('touchstart', (event) => {
      const active = document.activeElement;
      if (state.loading || state.loadingMore || state.editing || notificationDetailOpen() || scrollBody.scrollTop > 0
        || event.touches.length !== 1
        || (active instanceof HTMLElement && active.matches('input, select, textarea, [contenteditable="true"]'))) {
        resetPullIndicator(false);
        return;
      }
      const touch = event.touches[0];
      state.pullTracking = true;
      state.pullDirectionLocked = false;
      state.pullStartX = touch.clientX;
      state.pullStartY = touch.clientY;
      updatePullIndicator(0);
    }, { passive: true });

    scrollBody.addEventListener('touchmove', (event) => {
      if (!state.pullTracking || event.touches.length !== 1) return;
      const touch = event.touches[0];
      const deltaX = touch.clientX - state.pullStartX;
      const deltaY = touch.clientY - state.pullStartY;
      if (!state.pullDirectionLocked && (Math.abs(deltaX) > 8 || Math.abs(deltaY) > 8)) {
        state.pullDirectionLocked = true;
        if (Math.abs(deltaX) >= Math.abs(deltaY) || deltaY <= 0) {
          resetPullIndicator(false);
          return;
        }
      }
      if (!state.pullDirectionLocked || deltaY <= 0 || scrollBody.scrollTop > 0) return;
      event.preventDefault();
      updatePullIndicator(Math.min(pullLimit, deltaY * 0.58));
    }, { passive: false });

    scrollBody.addEventListener('touchend', () => {
      if (!state.pullTracking) return;
      const shouldRefresh = state.pullDistance >= pullThreshold;
      if (!shouldRefresh) {
        resetPullIndicator();
        return;
      }
      state.pullTracking = false;
      updatePullIndicator(54, true);
      load({ preserve: true }).finally(() => resetPullIndicator());
    }, { passive: true });
    scrollBody.addEventListener('touchcancel', () => resetPullIndicator(), { passive: true });
  }

  function toggle(id) {
    if (state.selected.has(id)) state.selected.delete(id);
    else state.selected.add(id);
    updateControls();
    render();
  }

  function closeDetail(fromHistory = false) {
    const modal = $('notificationDetailModal');
    if (modal.classList.contains('hidden')) return;
    state.detailSerial++;
    const activeId = String(state.active?.notification_id || '');
    modal.classList.add('hidden');
    modal.classList.remove('is-loading', 'has-error');
    modal.setAttribute('aria-hidden', 'true');
    modal.inert = true;
    document.body.classList.remove('notification-detail-open');
    state.active = null;
    const currentOpener = activeId
      ? document.querySelector(`[data-notification-id="${CSS.escape(activeId)}"]`)
      : null;
    (currentOpener || state.opener)?.focus?.();
    if (!fromHistory && window.history.state?.zpayNotificationDetail) {
      window.history.back();
    }
  }

  async function loadDetail(item) {
    const serial = ++state.detailSerial;
    const modal = $('notificationDetailModal');
    modal.classList.add('is-loading');
    modal.classList.remove('has-error');
    $('notificationDetailRetryButton').classList.add('hidden');
    $('notificationDetailBody').textContent = 'Loading notification...';
    $('notificationDetailDeleteButton').disabled = true;
    $('notificationDetailOpenButton').disabled = true;

    const requests = [shell.get('notification_details', { notification_id: item.notification_id }, 'Loading notification...', { busy: false })];
    if (!item.is_read) {
      requests.push(shell.post('notification_mark_read', { notification_id: item.notification_id }, 'Updating...', { busy: false }));
    }
    const results = await Promise.allSettled(requests);
    if (serial !== state.detailSerial || state.active !== item) return;

    modal.classList.remove('is-loading');
    $('notificationDetailDeleteButton').disabled = false;
    $('notificationDetailOpenButton').disabled = false;

    if (results[0].status === 'fulfilled') {
      Object.assign(item, results[0].value.notification || {});
    }

    if (results[1]?.status === 'fulfilled') {
      item.is_read = true;
      shell.state.unread = Number(results[1].value.unread_count ?? shell.state.unread);
      if (state.filter === 'UNREAD') state.items = state.items.filter((candidate) => candidate !== item);
      render();
      updateControls();
    } else if (results.length > 1) {
      $('notificationPageLive').textContent = 'Notification opened, but it could not be marked as read.';
    }

    if (results[0].status === 'rejected') {
      modal.classList.add('has-error');
      $('notificationDetailBody').textContent = 'Notification details could not be loaded.';
      $('notificationDetailRetryButton').classList.remove('hidden');
      return;
    }

    $('notificationDetailBody').textContent = item.body_full || item.body || 'No additional details are available.';
  }

  function openDetail(item, opener) {
    if (state.loading || state.loadingMore) return;
    state.active = item;
    state.opener = opener;
    const modal = $('notificationDetailModal');
    modal.classList.remove('hidden');
    modal.setAttribute('aria-hidden', 'false');
    modal.inert = false;
    document.body.classList.add('notification-detail-open');
    $('notificationDetailIcon').textContent = glyph(item);
    $('notificationDetailTitle').textContent = item.title || 'Notification';
    $('notificationDetailTime').textContent = dateText(item.created_at);
    $('notificationDetailOpenButton').classList.toggle('hidden', !destination(item));
    window.history.pushState({ ...(window.history.state || {}), zpayNotificationDetail: true }, '', window.location.href);
    loadDetail(item).catch(() => {
      if (state.active !== item) return;
      modal.classList.remove('is-loading');
      modal.classList.add('has-error');
      $('notificationDetailBody').textContent = 'Notification details could not be loaded.';
      $('notificationDetailRetryButton').classList.remove('hidden');
      $('notificationDetailDeleteButton').disabled = false;
      $('notificationDetailOpenButton').disabled = false;
    });
    window.setTimeout(() => $('notificationDetailCloseButton').focus(), 0);
  }

  async function mutate(action, ids) {
    if (!ids.length || state.loading || state.loadingMore) return;
    let refill = false;
    state.loading = true;
    updateControls();
    try {
      const payload = ids.length === 1 ? { notification_id: ids[0], notification_ids: ids } : { notification_ids: ids };
      const data = await shell.post(action, payload, action === 'notifications_delete' ? 'Deleting notification...' : 'Updating notifications...');
      const changedCount = Number(action === 'notifications_delete' ? data.deleted_count : data.marked_count);
      if (!Number.isFinite(changedCount) || changedCount < 1) {
        throw new Error(action === 'notifications_delete' ? 'Notification could not be deleted.' : 'Notification could not be marked as read.');
      }
      const chosen = new Set(ids);
      if (action === 'notifications_delete') {
        state.items = state.items.filter((item) => !chosen.has(String(item.notification_id || '')));
      } else {
        state.items.forEach((item) => { if (chosen.has(String(item.notification_id || ''))) item.is_read = true; });
        if (state.filter === 'UNREAD') state.items = state.items.filter((item) => !chosen.has(String(item.notification_id || '')));
      }
      state.selected.clear();
      shell.state.unread = Number(data.unread_count ?? shell.state.unread);
      render();
      refill = state.hasMore && state.items.length < pageSize;
      shell.toast(action === 'notifications_delete' ? 'Notification deleted.' : 'Notification marked as read.', 'ok');
    } catch (error) {
      shell.toast(safeError(error, 'Notifications could not be updated.'), 'error');
    } finally {
      state.loading = false;
      updateControls();
    }
    if (refill) await loadMore();
  }

  async function init() {
    try {
      await shell.ready;
      document.querySelectorAll('[data-notification-filter]').forEach((tab) => tab.addEventListener('click', () => {
        if (state.loading || state.filter === tab.dataset.notificationFilter) return;
        state.filter = tab.dataset.notificationFilter;
        state.selected.clear();
        state.loaded = false;
        load({ preserve: false });
      }));
      bindPullToRefresh();
      bindProgressiveLoading();
      $('notificationsEditButton').addEventListener('click', () => {
        state.editing = !state.editing;
        state.selected.clear();
        updateControls();
        render();
      });
      $('notificationsSelectAllButton').addEventListener('click', () => {
        const all = state.items.length && state.items.every((item) => state.selected.has(String(item.notification_id || '')));
        state.selected.clear();
        if (!all) state.items.forEach((item) => state.selected.add(String(item.notification_id || '')));
        updateControls();
        render();
      });
      $('notificationsDeleteButton').addEventListener('click', () => mutate('notifications_delete', Array.from(state.selected)));
      $('notificationsMarkSelectedButton').addEventListener('click', () => mutate('notification_mark_read', Array.from(state.selected)));
      $('notificationDetailCloseButton').addEventListener('click', () => closeDetail());
      document.querySelector('[data-notification-detail-close]').addEventListener('click', () => closeDetail());
      $('notificationDetailRetryButton').addEventListener('click', () => {
        if (state.active) loadDetail(state.active);
      });
      $('notificationDetailDeleteButton').addEventListener('click', async () => {
        const id = String(state.active?.notification_id || '');
        closeDetail(true);
        await mutate('notifications_delete', id ? [id] : []);
      });
      $('notificationDetailOpenButton').addEventListener('click', () => {
        const target = destination(state.active);
        if (target) window.location.assign(target);
      });
      window.addEventListener('popstate', () => closeDetail(true));
      document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeDetail(); });
      window.addEventListener('pagehide', () => resetPullIndicator(false));
      await load({ preserve: false });
    } finally {
      releaseInitialLoad();
    }
  }

  init().catch((error) => {
    renderError(error);
    shell.toast('Failed to load notifications.', 'error');
  });
})();
