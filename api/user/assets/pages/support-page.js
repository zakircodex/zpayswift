(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const allowedImages = new Set(['image/jpeg', 'image/png', 'image/webp']);
  let lastModalFocus = null;
  const profileModal = {
    open: false,
    kind: '',
    opener: null,
    historyOpen: false,
    closing: false,
    crop: null
  };
  const sectionPaths = {
    overviewSection: '/user/',
    servicesSection: '/user/services',
    transferSection: '/user/transfer',
    historySection: '/user/history',
    supportSection: '/user/support',
    profileSection: '/user/profile'
  };

  const app = {
    profile: null,
    profileLoading: false,
    transfer: {
      step: 1,
      recipient: null,
      preview: null,
      reference: '',
      submitting: false,
      resolving: false,
      amountChecking: false,
      favorites: [],
      favoritesLoaded: false,
      favoritesLoading: false,
      verifiedInput: '',
      holdFrame: 0,
      holdStartedAt: 0,
      modalOpen: false,
      modalBusy: false,
      modalHistoryOpen: false,
      modalClosing: false,
      successContext: null
    },
    support: {
      config: null,
      categories: [],
      tickets: [],
      ticket: null,
      messages: [],
      attachments: [],
      pollTimer: 0,
      createKey: '',
      replyKey: ''
    },
    notifications: {
      filter: 'ALL',
      items: [],
      loading: false,
      loaded: false,
      unreadCount: 0,
      returnSection: 'overviewSection',
      editing: false,
      selected: new Set(),
      activeDetail: null,
      detailOpener: null,
      detailHistory: false
    }
  };

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    })[char]);
  }

  function safeMessage(error, fallback) {
    const message = String(error && error.message ? error.message : fallback || 'Please try again.').trim();
    return message && message.length <= 220 ? message : String(fallback || 'Please try again.');
  }

  function transferStatusUnknown(error) {
    const code = String(error && error.code || '').toUpperCase();
    const status = Number(error && error.status || 0);
    return status === 0
      || status >= 500
      || [
        'REQUEST_FAILED',
        'TRANSFER_FAILED',
        'TRANSFER_PROCESSING',
        'TRANSFER_STORE_FAILED',
        'TRANSFER_INDEX_FAILED',
        'TRANSFER_RETRYABLE',
        'FINANCIAL_OPERATION_UNAVAILABLE'
      ].includes(code);
  }

  function profileSafeMessage(error, fallback) {
    const code = String(error && error.code || '').toUpperCase();
    const known = {
      WRONG_PASSWORD: 'Current password is incorrect.',
      WRONG_PIN: 'Current PIN is incorrect.',
      PASSWORD_MISMATCH: 'Confirm password does not match.',
      PIN_MISMATCH: 'Confirm PIN does not match.',
      INVALID_PASSWORD: 'Choose a stronger password.',
      INVALID_PIN: 'PIN must be exactly 4 digits.',
      IMAGE_TOO_LARGE: 'Profile photo must be 5 MB or smaller.',
      UNSUPPORTED_IMAGE: 'Choose a supported JPG, PNG or WebP image.',
      SESSION_EXPIRED: 'Your session expired. Please login again.'
    };
    if (known[code]) return known[code];
    const message = safeMessage(error, fallback);
    return /firebase|exception|stack trace|user_wallets|session[_ -]?token|csrf[_ -]?token|\/api\//i.test(message)
      ? String(fallback || 'Please try again.')
      : message;
  }

  function toast(message, type) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type || 'info');
    }
  }

  function setBusy(on, label) {
    if (typeof window.setBusy === 'function') {
      window.setBusy(on, label || 'Loading...');
    }
  }

  function csrf() {
    return String((window.userState && window.userState.csrf) || '');
  }

  function makeIdempotencyKey(prefix) {
    const random = window.crypto && typeof window.crypto.randomUUID === 'function'
      ? window.crypto.randomUUID()
      : String(Date.now()) + '-' + Math.random().toString(36).slice(2);
    return String(prefix || 'WEB') + '-' + random;
  }

  function formatMoney(value, currency) {
    const amount = Number(value || 0);
    const code = String(currency || 'BDT').toUpperCase();
    const prefix = code === 'MYR' ? 'RM' : code;
    return prefix + ' ' + (Number.isFinite(amount) ? amount.toFixed(2) : '0.00');
  }

  function formatDate(value) {
    let timestamp = Number(value || 0);
    if (!timestamp) return '-';
    if (timestamp < 100000000000) timestamp *= 1000;
    const date = new Date(timestamp);
    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString([], {
      year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit'
    });
  }

  function maskPhone(value) {
    const phone = String(value || '').trim();
    if (phone.length < 7) return phone || '-';
    return phone.slice(0, 4) + '***' + phone.slice(-3);
  }

  function maskEmail(value) {
    const email = String(value || '').trim();
    if (!email) return '-';
    const at = email.indexOf('@');
    if (at <= 0 || at === email.length - 1) {
      return email.length <= 20 ? email : email.slice(0, 17) + '...';
    }
    const local = email.slice(0, at);
    let domain = email.slice(at + 1);
    if (domain.length > 16) domain = domain.slice(0, 13) + '...';
    return local.slice(0, Math.min(5, local.length)) + '***@' + domain;
  }

  function profileCountryLabel(value) {
    const country = String(value || '').toUpperCase();
    if (country === 'MY') return 'Malaysia';
    if (country === 'BD') return 'Bangladesh';
    return country || '-';
  }

  function profileSessionStatus(value) {
    const status = String(value || '').trim();
    if (!status || status.toUpperCase() === 'ACTIVE') return 'Active';
    return status.replace(/[_-]+/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
  }

  function profileVersionLabel() {
    return 'Version 1.0.0';
  }

  function initials(name) {
    const parts = String(name || 'Z P').trim().split(/\s+/).filter(Boolean);
    return ((parts[0] || 'Z')[0] + (parts[1] || parts[0] || 'P')[0]).toUpperCase();
  }

  function safeProfileImage(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.origin);
      return url.origin === window.location.origin ? url.href : '';
    } catch (_) {
      return '';
    }
  }

  async function get(action, params, label, options) {
    if (typeof window.proxyGet !== 'function') throw new Error('User API is unavailable.');
    return window.proxyGet(action, params || {}, label || 'Loading...', options || {});
  }

  async function post(action, payload, label, options) {
    if (typeof window.proxyPost !== 'function') throw new Error('User API is unavailable.');
    return window.proxyPost(action, payload || {}, label || 'Processing...', options || {});
  }

  function isSessionError(error) {
    const code = String(error && error.code || '').toUpperCase();
    return ['SESSION_EXPIRED', 'AUTH_REQUIRED', 'UNAUTHORIZED', 'USER_SESSION_EXPIRED'].includes(code)
      || Number(error && error.status || 0) === 401;
  }

  function isCsrfError(error) {
    const code = String(error && error.code || '').toUpperCase();
    const message = String(error && error.message || '').toLowerCase();
    return Number(error && error.status || 0) === 403
      && (code === 'FORBIDDEN' || code === 'CSRF_INVALID' || message.includes('csrf'));
  }

  async function refreshCsrfToken() {
    const data = await get('me', {}, 'Refreshing session...', { busy: false });
    if (data && data.csrf && window.userState) {
      window.userState.csrf = String(data.csrf);
    }
    return csrf();
  }

  async function postWithFreshCsrf(action, payload, label) {
    if (!csrf()) {
      await refreshCsrfToken();
    }
    try {
      return await post(action, payload || {}, label || 'Processing...', { busy: false });
    } catch (error) {
      if (!isCsrfError(error)) throw error;
      await refreshCsrfToken();
      return post(action, payload || {}, label || 'Processing...', { busy: false });
    }
  }

  function handleNotificationSessionExpired() {
    renderNotificationMessage('Session expired', 'Please login again to view your notifications.');
    if (typeof window.userSessionExpired === 'function') {
      window.userSessionExpired();
    }
  }

  async function postForm(action, formData, label) {
    setBusy(true, label || 'Uploading...');
    try {
      const send = async () => {
        const response = await fetch((window.USER_PROXY_URL || '/api/user/proxy.php') + '?action=' + encodeURIComponent(action), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': csrf(), 'Accept': 'application/json' },
          body: formData
        });
        const responseText = await response.text();
        let json = null;
        try { json = JSON.parse(responseText); } catch (_) { json = null; }
        if (!response.ok || !json || !json.ok) {
          const error = new Error(String((json && json.message) || 'The request could not be completed.'));
          error.code = String((json && json.code) || 'REQUEST_FAILED');
          error.status = response.status;
          if (isSessionError(error) && typeof window.userSessionExpired === 'function') {
            window.userSessionExpired();
          }
          throw error;
        }
        return json.data || {};
      };
      if (!csrf()) await refreshCsrfToken();
      try {
        return await send();
      } catch (error) {
        if (!isCsrfError(error)) throw error;
        await refreshCsrfToken();
        return send();
      }
    } finally {
      setBusy(false);
    }
  }

  function setButtonBusy(button, busy, busyText) {
    if (!button) return;
    if (busy) {
      button.dataset.originalText = button.textContent;
      button.disabled = true;
      button.textContent = busyText || 'Please wait...';
    } else {
      button.disabled = false;
      button.textContent = button.dataset.originalText || button.textContent;
      delete button.dataset.originalText;
    }
  }

  function supportStatus(status) {
    const code = String(status || 'OPEN').toUpperCase();
    return ({ OPEN: 'Open', PENDING: 'Pending', REPLIED: 'Replied', RESOLVED: 'Resolved', CLOSED: 'Closed' })[code] || code;
  }

  function supportIsClosed(status) {
    return ['CLOSED', 'RESOLVED'].includes(String(status || '').toUpperCase());
  }

  async function loadSupportConfig(force) {
    if (app.support.config && !force) {
      renderSupportConfig();
      return;
    }
    try {
      const data = await get('support_config', {}, 'Loading support...');
      app.support.config = data.config || {};
      app.support.categories = Array.isArray(data.categories) ? data.categories : [];
      renderSupportConfig();
    } catch (error) {
      toast(safeMessage(error, 'Support is unavailable.'), 'error');
    }
  }

  function renderSupportConfig() {
    const config = app.support.config || {};
    if ($('supportNotice')) $('supportNotice').textContent = config.support_notice || 'Never share your password, PIN or OTP with anyone.';
    if ($('supportHoursText')) $('supportHoursText').textContent = config.support_hours || 'Every day, 10:00 AM - 10:00 PM';
    if ($('supportAverageReplyText')) $('supportAverageReplyText').textContent = config.average_response_text || 'Average response time: within 24 hours.';
    const category = $('supportCategory');
    if (category) {
      const selected = category.value;
      category.replaceChildren(new Option('Select a category', ''));
      app.support.categories.forEach((item) => category.add(new Option(String(item.name || item.code || ''), String(item.code || ''))));
      category.value = selected;
    }
    const actions = $('supportContactActions');
    if (actions) {
      actions.replaceChildren();
      const links = [];
      if (config.email_enabled && config.support_email) {
        links.push({
          type: 'email',
          label: 'Email',
          detail: String(config.support_email),
          href: 'mailto:' + String(config.support_email).trim()
        });
      }
      if (config.whatsapp_enabled && config.whatsapp_number) {
        links.push({
          type: 'chat',
          label: 'WhatsApp',
          detail: String(config.whatsapp_number),
          href: 'https://wa.me/' + String(config.whatsapp_number).replace(/\D/g, '')
        });
      }
      if (config.call_enabled && config.support_phone) {
        links.push({
          type: 'phone',
          label: 'Call',
          detail: String(config.support_phone),
          href: 'tel:' + String(config.support_phone).replace(/[^+\d]/g, '')
        });
      }
      links.forEach((item) => {
        const link = document.createElement('a');
        link.className = 'support-contact-action';
        link.href = item.href;
        if (item.href.startsWith('https:')) {
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
        }
        const icon = document.createElement('span');
        icon.className = 'support-contact-action-icon ' + item.type;
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = supportContactIcon(item.type);
        const title = document.createElement('strong');
        title.textContent = item.label;
        const detail = document.createElement('small');
        detail.textContent = item.detail;
        link.append(icon, title, detail);
        actions.appendChild(link);
      });
      actions.classList.toggle('hidden', !links.length);
    }
    renderRelatedRequests();
  }

  function supportContactIcon(type) {
    if (type === 'email') {
      return '<svg viewBox="0 0 24 24"><path d="M4 6h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Zm8 7.1L4.9 8H4v.8l8 5.7 8-5.7V8h-.9L12 13.1Z"/></svg>';
    }
    if (type === 'phone') {
      return '<svg viewBox="0 0 24 24"><path d="M6.6 10.8a15 15 0 0 0 6.6 6.6l2.2-2.2a1.4 1.4 0 0 1 1.4-.3c1.5.5 3 .8 4.6.8a1.4 1.4 0 0 1 1.4 1.4v3.5a1.4 1.4 0 0 1-1.4 1.4A19.9 19.9 0 0 1 1.5 2.6a1.4 1.4 0 0 1 1.4-1.4h3.5a1.4 1.4 0 0 1 1.4 1.4c0 1.6.3 3.1.8 4.6.2.5.1 1-.3 1.4l-1.7 2.2Z"/></svg>';
    }
    return '<svg viewBox="0 0 24 24"><path d="M12 3C6.5 3 2 6.8 2 11.5c0 2.7 1.5 5.2 4 6.7V22l3.7-2.1c.7.1 1.5.2 2.3.2 5.5 0 10-3.8 10-8.5S17.5 3 12 3Zm-4 9.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm4 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm4 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/></svg>';
  }

  function renderRelatedRequests() {
    const select = $('supportRelatedRequest');
    const wrap = $('supportRelatedWrap');
    if (!select || !wrap) return;
    const rows = Array.isArray(window.userState && window.userState.requestLogs) ? window.userState.requestLogs : [];
    select.replaceChildren(new Option('No related request', ''));
    rows.slice(0, 40).forEach((row) => {
      const id = String(row.request_id || row.transfer_id || row.id || '');
      if (!id) return;
      const label = [id, row.type || row.request_type || '', row.amount_text || ''].filter(Boolean).join(' - ');
      const option = new Option(label, id);
      option.dataset.relatedType = String(row.type || row.request_type || '');
      select.add(option);
    });
    wrap.classList.toggle('hidden', !rows.length);
  }

  async function loadSupportTickets(force) {
    if (app.support.tickets.length && !force) {
      renderSupportTickets();
      return app.support.tickets;
    }
    try {
      const data = await get('support_list', { limit: 50 }, 'Loading support requests...', { busy: false });
      app.support.tickets = Array.isArray(data.tickets) ? data.tickets : [];
      renderSupportTickets();
      return app.support.tickets;
    } catch (error) {
      if ($('supportTicketList')) $('supportTicketList').innerHTML = '<div class="feature-empty-state">Support requests could not be loaded.</div>';
      toast(safeMessage(error, 'Support requests could not be loaded.'), 'error');
      return [];
    }
  }

  function renderSupportTickets() {
    const list = $('supportTicketList');
    if (!list) return;
    list.replaceChildren();
    if (!app.support.tickets.length) {
      const empty = document.createElement('div');
      empty.className = 'feature-empty-state';
      empty.textContent = 'No support requests yet.';
      list.appendChild(empty);
    } else {
      app.support.tickets.forEach((ticket) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'support-ticket-card' + (ticket.user_unread ? ' unread' : '');
        button.innerHTML = '<div><h4>' + escapeHtml(ticket.subject || ticket.ticket_id || 'Support Request') + '</h4>' +
          '<p>' + escapeHtml(ticket.category_name || ticket.category_code || 'Support') + ' - ' + escapeHtml(formatDate(ticket.updated_at || ticket.created_at)) + '</p>' +
          '<p>' + escapeHtml(ticket.last_message_preview || '') + '</p></div>' +
          '<span class="status-pill">' + escapeHtml(ticket.status_label || supportStatus(ticket.status)) + '</span>';
        button.addEventListener('click', () => openSupportConversation(ticket.ticket_id));
        list.appendChild(button);
      });
    }
    const unread = app.support.tickets.filter((ticket) => ticket.user_unread).length;
    if ($('supportUnreadBadge')) {
      $('supportUnreadBadge').textContent = String(unread);
      $('supportUnreadBadge').classList.toggle('hidden', unread < 1);
    }
  }

  function switchSupportTab(tab) {
    $('supportRequestWorkspace')?.classList.remove('hidden');
    const list = tab === 'list';
    $('supportNewTab')?.classList.toggle('active', !list);
    $('supportListTab')?.classList.toggle('active', list);
    $('supportNewTab')?.setAttribute('aria-selected', String(!list));
    $('supportListTab')?.setAttribute('aria-selected', String(list));
    $('supportCreatePanel')?.classList.toggle('active', !list);
    $('supportListPanel')?.classList.toggle('active', list);
    if (list) loadSupportTickets(false);
  }

  function openSupportTicketCandidate() {
    return app.support.tickets.find((ticket) => ticket && ticket.ticket_id && !supportIsClosed(ticket.status)) || null;
  }

  function showSupportHome() {
    stopSupportPolling();
    app.support.ticket = null;
    $('supportConversationView')?.classList.add('hidden');
    $('supportHomeView')?.classList.remove('hidden');
    $('supportRequestWorkspace')?.classList.add('hidden');
    $('supportContactBody')?.scrollTo?.({ top: 0, behavior: 'auto' });
  }

  function showSupportWorkspace(tab) {
    $('supportHomeView')?.classList.add('hidden');
    $('supportConversationView')?.classList.add('hidden');
    switchSupportTab(tab === 'new' ? 'new' : 'list');
    $('supportRequestWorkspace')?.scrollTo?.({ top: 0, behavior: 'auto' });
  }

  async function openSupportEntry() {
    const button = $('supportOpenRequestsButton');
    if (button) button.disabled = true;
    try {
      await loadSupportTickets(false);
      const activeTicket = openSupportTicketCandidate();
      if (activeTicket) {
        await openSupportConversation(activeTicket.ticket_id);
        return;
      }
      showSupportWorkspace('list');
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function startSupportChat() {
    const button = $('supportStartChatButton');
    if (button) button.disabled = true;
    try {
      await loadSupportTickets(false);
      const activeTicket = openSupportTicketCandidate();
      if (activeTicket) {
        await openSupportConversation(activeTicket.ticket_id);
        return;
      }
      showSupportWorkspace('new');
    } finally {
      if (button) button.disabled = false;
    }
  }

  function selectedCategory() {
    return app.support.categories.find((item) => String(item.code || '') === String($('supportCategory')?.value || '')) || null;
  }

  function validateFiles(files, maxFiles, maxSize) {
    const rows = Array.from(files || []);
    if (rows.length > maxFiles) throw new Error('You can attach up to ' + maxFiles + ' screenshots.');
    rows.forEach((file) => {
      if (!allowedImages.has(String(file.type || '').toLowerCase())) throw new Error('Only JPG, PNG and WebP screenshots are allowed.');
      if (file.size <= 0 || file.size > maxSize) throw new Error('Each screenshot must be within the allowed file size.');
    });
    return rows;
  }

  function updateAttachmentSummary(input, output) {
    const files = Array.from(input && input.files ? input.files : []);
    if (output) output.textContent = files.length ? files.map((file) => file.name).join(', ') : '';
  }

  async function createSupportTicket(event) {
    event.preventDefault();
    const button = $('supportCreateButton');
    const config = app.support.config || {};
    try {
      const files = validateFiles($('supportAttachments')?.files, Number(config.max_attachments || 3), Number(config.max_file_size || 5 * 1024 * 1024));
      if (!app.support.createKey) app.support.createKey = makeIdempotencyKey('SUPPORT-CREATE');
      const data = new FormData();
      data.append('category_code', String($('supportCategory')?.value || ''));
      data.append('subject', String($('supportSubject')?.value || '').trim());
      data.append('message', String($('supportMessage')?.value || '').trim());
      data.append('related_request_id', String($('supportRelatedRequest')?.value || ''));
      data.append('related_type', String($('supportRelatedRequest')?.selectedOptions?.[0]?.dataset.relatedType || ''));
      data.append('idempotency_key', app.support.createKey);
      files.forEach((file) => data.append('attachments[]', file, file.name));
      setButtonBusy(button, true, 'Submitting...');
      const result = await postForm('support_create', data, 'Submitting support request...');
      const ticket = result.ticket || {};
      app.support.createKey = '';
      $('supportCreateForm')?.reset();
      if ($('supportAttachmentSummary')) $('supportAttachmentSummary').textContent = '';
      await loadSupportTickets(true);
      switchSupportTab('list');
      toast('Support request submitted.', 'ok');
      if (ticket.ticket_id) await openSupportConversation(ticket.ticket_id);
    } catch (error) {
      toast(safeMessage(error, 'Support request could not be submitted.'), 'error');
    } finally {
      setButtonBusy(button, false);
    }
  }

  async function openSupportConversation(ticketId, options) {
    const id = String(ticketId || '').trim();
    if (!id) return;
    try {
      const data = await get('support_details', { ticket_id: id }, 'Loading conversation...', { busy: !(options && options.silent) });
      app.support.ticket = data.ticket || null;
      app.support.messages = Array.isArray(data.messages) ? data.messages : [];
      app.support.attachments = Array.isArray(data.attachments) ? data.attachments : [];
      $('supportHomeView')?.classList.add('hidden');
      $('supportRequestWorkspace')?.classList.add('hidden');
      $('supportConversationView')?.classList.remove('hidden');
      renderSupportConversation();
      window.scrollTo({ top: 0, behavior: 'auto' });
      if (!(options && options.fromHistory) && window.history && window.history.pushState) {
        window.history.pushState(Object.assign({}, window.history.state || {}, {
          zpayUserApp: { view: 'supportConversation', ticket_id: id }
        }), '', sectionPaths.supportSection);
      }
      startSupportPolling();
    } catch (error) {
      toast(safeMessage(error, 'Support conversation could not be opened.'), 'error');
    }
  }

  function renderSupportConversation() {
    const ticket = app.support.ticket || {};
    if ($('supportConversationTitle')) $('supportConversationTitle').textContent = ticket.subject || 'Support Request';
    if ($('supportConversationMeta')) $('supportConversationMeta').textContent = [ticket.ticket_id, ticket.category_name || ticket.category_code].filter(Boolean).join(' - ');
    if ($('supportConversationStatus')) $('supportConversationStatus').textContent = ticket.status_label || supportStatus(ticket.status);
    const closed = supportIsClosed(ticket.status);
    $('supportReplyForm')?.classList.toggle('hidden', closed);
    if ($('supportClosedNotice')) {
      $('supportClosedNotice').classList.toggle('hidden', !closed);
      $('supportClosedNotice').textContent = closed ? 'This request is ' + supportStatus(ticket.status).toLowerCase() + '. New replies are disabled.' : '';
    }
    renderSupportMessages();
  }

  function renderSupportMessages() {
    const container = $('supportMessages');
    if (!container) return;
    const byId = new Map();
    app.support.messages.forEach((message, index) => {
      const id = String(message.message_id || 'message-' + index);
      byId.set(id, message);
    });
    const rows = Array.from(byId.values()).sort((a, b) => Number(a.created_at || 0) - Number(b.created_at || 0));
    const attachmentsByMessage = new Map();
    app.support.attachments.forEach((attachment) => {
      const id = String(attachment.message_id || '');
      if (!attachmentsByMessage.has(id)) attachmentsByMessage.set(id, []);
      attachmentsByMessage.get(id).push(attachment);
    });
    container.replaceChildren();
    rows.forEach((message) => {
      const sender = String(message.sender_type || '').toUpperCase();
      const type = sender === 'USER' ? 'user' : (sender === 'SYSTEM' ? 'system' : 'support');
      const bubble = document.createElement('article');
      bubble.className = 'support-message ' + type;
      const text = document.createElement('p');
      text.textContent = String(message.message || '');
      const meta = document.createElement('small');
      meta.textContent = [message.sender_name || (type === 'user' ? 'You' : 'Z-Pay Support'), formatDate(message.created_at)].filter(Boolean).join(' - ');
      bubble.append(text, meta);
      const files = attachmentsByMessage.get(String(message.message_id || '')) || [];
      if (files.length) {
        const wrap = document.createElement('div');
        wrap.className = 'message-attachments';
        files.forEach((attachment, index) => {
          const link = document.createElement('a');
          link.textContent = attachment.original_name || 'Screenshot ' + (index + 1);
          link.href = (window.USER_PROXY_URL || '/api/user/proxy.php') + '?action=support_attachment&ticket_id=' + encodeURIComponent(String(app.support.ticket.ticket_id || '')) + '&attachment_id=' + encodeURIComponent(String(attachment.attachment_id || ''));
          link.target = '_blank';
          link.rel = 'noopener';
          wrap.appendChild(link);
        });
        bubble.appendChild(wrap);
      }
      container.appendChild(bubble);
    });
    requestAnimationFrame(() => { container.scrollTop = container.scrollHeight; });
  }

  function closeSupportConversation(options) {
    stopSupportPolling();
    app.support.ticket = null;
    app.support.messages = [];
    app.support.attachments = [];
    $('supportConversationView')?.classList.add('hidden');
    showSupportWorkspace('list');
    if (!(options && options.fromHistory) && window.history?.back) window.history.back();
  }

  async function replySupport(event) {
    event.preventDefault();
    const ticket = app.support.ticket || {};
    const messageInput = $('supportReplyMessage');
    const message = String(messageInput?.value || '').trim();
    if (supportIsClosed(ticket.status)) return;
    if (!message) {
      toast('Write a reply before sending.', 'error');
      return;
    }
    const button = $('supportReplyButton');
    try {
      const config = app.support.config || {};
      const files = validateFiles($('supportReplyAttachment')?.files, Number(config.max_attachments || 3), Number(config.max_file_size || 5 * 1024 * 1024));
      if (!app.support.replyKey) app.support.replyKey = makeIdempotencyKey('SUPPORT-REPLY');
      const data = new FormData();
      data.append('ticket_id', String(ticket.ticket_id || ''));
      data.append('message', message);
      data.append('idempotency_key', app.support.replyKey);
      files.forEach((file) => data.append('attachments[]', file, file.name));
      setButtonBusy(button, true, 'Sending...');
      const result = await postForm('support_reply', data, 'Sending reply...');
      app.support.replyKey = '';
      app.support.ticket = result.ticket || ticket;
      app.support.messages = Array.isArray(result.messages) ? result.messages : app.support.messages;
      app.support.attachments = Array.isArray(result.attachments) ? result.attachments : app.support.attachments;
      if (messageInput) messageInput.value = '';
      if ($('supportReplyAttachment')) $('supportReplyAttachment').value = '';
      if ($('supportReplyAttachmentSummary')) $('supportReplyAttachmentSummary').textContent = '';
      renderSupportConversation();
    } catch (error) {
      toast(safeMessage(error, 'Reply could not be sent.'), 'error');
    } finally {
      setButtonBusy(button, false);
    }
  }

  function startSupportPolling() {
    stopSupportPolling();
    app.support.pollTimer = window.setInterval(() => {
      if (document.visibilityState === 'visible' && app.support.ticket && document.body.getAttribute('data-active-section') === 'supportSection') {
        openSupportConversation(app.support.ticket.ticket_id, { silent: true, fromHistory: true });
      }
    }, 30000);
  }

  function stopSupportPolling() {
    if (app.support.pollTimer) clearInterval(app.support.pollTimer);
    app.support.pollTimer = 0;
  }


  function bindSupportPage() {
    $("supportNewTab")?.addEventListener("click", () => switchSupportTab("new"));
    $("supportListTab")?.addEventListener("click", () => switchSupportTab("list"));
    $("supportStartChatButton")?.addEventListener("click", startSupportChat);
    $("supportRefreshTopButton")?.addEventListener("click", () => loadSupportTickets(true));
    $("supportCreateForm")?.addEventListener("submit", createSupportTicket);
    $("supportAttachments")?.addEventListener("change", () => updateAttachmentSummary($("supportAttachments"), $("supportAttachmentSummary")));
    $("supportConversationBack")?.addEventListener("click", () => closeSupportConversation());
    $("supportReplyForm")?.addEventListener("submit", replySupport);
    $("supportReplyAttachment")?.addEventListener("change", () => updateAttachmentSummary($("supportReplyAttachment"), $("supportReplyAttachmentSummary")));
    $("supportReplyMessage")?.addEventListener("input", (event) => {
      event.target.style.height = "auto";
      event.target.style.height = Math.min(130, event.target.scrollHeight) + "px";
    });
    window.addEventListener("popstate", () => { if (app.support.ticket) closeSupportConversation({ fromHistory: true }); });
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible" && app.support.ticket) startSupportPolling();
      else if (document.visibilityState !== "visible") stopSupportPolling();
    });
  }

  async function initSupportPage() {
    await window.UserShell.ready;
    bindSupportPage();
    const results = await Promise.allSettled([
      loadSupportConfig(false),
      loadSupportTickets(false),
      get("request_logs", { limit: 40 }, "Loading related requests...", { busy: false })
    ]);
    if (results[2].status === "fulfilled") {
      const data = results[2].value || {};
      window.userState.requestLogs = Array.isArray(data.rows) ? data.rows : (Array.isArray(data.logs) ? data.logs : []);
      renderRelatedRequests();
    }
    switchSupportTab("list");
  }

  initSupportPage().catch((error) => toast(safeMessage(error, "Support could not be loaded."), "error"));
})();
