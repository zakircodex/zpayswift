(() => {
  'use strict';

  const shell = window.UserShell;
  const $ = (id) => document.getElementById(id);
  const state = {
    filter: 'ALL',
    items: [],
    selected: new Set(),
    editing: false,
    loading: false,
    active: null,
    opener: null
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

  function destination(item) {
    const type = String(item?.type || '').toUpperCase();
    if (type.startsWith('SUPPORT_')) return '/user/support';
    if (type.startsWith('ACCOUNT_') || type === 'SECURITY_REVIEW' || type === 'LOGIN_ALERT') return '/user/profile';
    if (type === 'ADMIN_NOTICE' || type === 'RINGGIT_RATE_UPDATED') return '';
    return '/user/history';
  }

  function updateControls() {
    document.querySelectorAll('[data-notification-filter]').forEach((tab) => {
      const active = tab.dataset.notificationFilter === state.filter;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.disabled = state.loading;
    });
    $('notificationUnreadCount').textContent = String(shell.state.unread || 0);
    $('notificationsEditButton').setAttribute('aria-pressed', state.editing ? 'true' : 'false');
    $('notificationEditBar').classList.toggle('hidden', !state.editing);
    $('notificationsDeleteButton').disabled = !state.selected.size || state.loading;
    $('notificationsMarkSelectedButton').disabled = !state.selected.size || state.loading;
    $('notificationsSelectAllButton').textContent =
      state.items.length && state.items.every((item) => state.selected.has(String(item.notification_id || '')))
        ? 'Clear All' : 'Select All';
  }

  function renderLoading() {
    $('notificationList').innerHTML = '<div class="notification-page-skeleton"></div><div class="notification-page-skeleton"></div><div class="notification-page-skeleton"></div>';
  }

  function render() {
    const list = $('notificationList');
    list.setAttribute('aria-busy', 'false');
    if (!state.items.length) {
      list.innerHTML = `<div class="notification-page-state"><span class="notification-page-state-icon">Z</span><h3>${state.filter === 'UNREAD' ? 'You are all caught up' : 'No notifications yet'}</h3><p>Important account and transaction updates will appear here.</p></div>`;
      return;
    }
    list.replaceChildren();
    state.items.forEach((item) => {
      const id = String(item.notification_id || '');
      const selected = state.selected.has(id);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = `notification-page-card${item.is_read ? '' : ' unread'}${selected ? ' selected' : ''}`;
      button.setAttribute('aria-pressed', state.editing ? (selected ? 'true' : 'false') : 'false');
      button.innerHTML = `
        <span class="notification-page-card-icon" aria-hidden="true">${shell.escapeHtml(glyph(item))}</span>
        <span class="notification-page-card-content">
          <strong>${shell.escapeHtml(item.title || 'Z-Pay Swift')}</strong>
          <span class="notification-page-card-body">${shell.escapeHtml(item.body || '')}</span>
          <time class="notification-page-card-time">${shell.escapeHtml(dateText(item.created_at))}</time>
        </span>
        ${state.editing
          ? `<span class="notification-page-select-indicator">${selected ? '&#10003;' : ''}</span>`
          : (!item.is_read ? '<span class="notification-page-unread-dot" aria-label="Unread"></span>' : '')}`;
      button.addEventListener('click', () => state.editing ? toggle(id) : openDetail(item, button));
      list.appendChild(button);
    });
    $('notificationPageLive').textContent = `${state.items.length} notifications loaded.`;
  }

  async function load(force = false) {
    if (state.loading) return;
    state.loading = true;
    updateControls();
    renderLoading();
    try {
      const data = await shell.get('notifications_list', { limit: 50, filter: state.filter }, 'Loading notifications...', { busy: false });
      state.items = Array.isArray(data.items) ? data.items : [];
      shell.state.unread = Number(data.unread_count || 0);
      render();
    } catch (error) {
      $('notificationList').innerHTML = `<div class="notification-page-state"><h3>Could not load notifications</h3><p>${shell.escapeHtml(error.message)}</p><button id="notificationRetry" class="notification-page-retry" type="button">Retry</button></div>`;
      $('notificationRetry')?.addEventListener('click', () => load(true));
    } finally {
      state.loading = false;
      updateControls();
    }
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
    modal.classList.add('hidden');
    modal.setAttribute('aria-hidden', 'true');
    modal.inert = true;
    document.body.classList.remove('notification-detail-open');
    state.active = null;
    if (!fromHistory && window.history.state?.zpayNotificationDetail) {
      window.history.back();
    } else {
      state.opener?.focus?.();
    }
  }

  async function openDetail(item, opener) {
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
    $('notificationDetailBody').textContent = item.body || 'Loading notification...';
    $('notificationDetailOpenButton').classList.toggle('hidden', !destination(item));
    window.history.pushState({ ...(window.history.state || {}), zpayNotificationDetail: true }, '', window.location.href);
    try {
      const requests = [shell.get('notification_details', { notification_id: item.notification_id }, 'Loading notification...', { busy: false })];
      if (!item.is_read) requests.push(shell.post('notification_mark_read', { notification_id: item.notification_id }, 'Updating...', { busy: false }));
      const results = await Promise.allSettled(requests);
      if (results[0].status === 'fulfilled') {
        Object.assign(item, results[0].value.notification || {});
        $('notificationDetailBody').textContent = item.body_full || item.body || 'No additional details are available.';
      }
      if (results[1]?.status === 'fulfilled') {
        item.is_read = true;
        shell.state.unread = Number(results[1].value.unread_count ?? shell.state.unread);
      }
      if (state.filter === 'UNREAD' && item.is_read) state.items = state.items.filter((candidate) => candidate !== item);
      render();
      updateControls();
    } catch (error) {
      $('notificationDetailBody').textContent = error.message || 'Notification details could not be loaded.';
    }
  }

  async function mutate(action, ids) {
    if (!ids.length || state.loading) return;
    state.loading = true;
    updateControls();
    try {
      const payload = ids.length === 1 ? { notification_id: ids[0], notification_ids: ids } : { notification_ids: ids };
      const data = await shell.post(action, payload, 'Updating notifications...');
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
      shell.toast(action === 'notifications_delete' ? 'Notification deleted.' : 'Notification marked as read.', 'ok');
    } catch (error) {
      shell.toast(error.message, 'error');
    } finally {
      state.loading = false;
      updateControls();
    }
  }

  async function init() {
    await shell.ready;
    document.querySelectorAll('[data-notification-filter]').forEach((tab) => tab.addEventListener('click', () => {
      if (state.loading || state.filter === tab.dataset.notificationFilter) return;
      state.filter = tab.dataset.notificationFilter;
      state.selected.clear();
      load(true);
    }));
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
    await load();
  }

  init().catch((error) => shell.toast(error.message || 'Failed to load notifications.', 'error'));
})();
