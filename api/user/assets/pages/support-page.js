(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const allowedImages = new Set(['image/jpeg', 'image/png', 'image/webp']);
  const viewIds = {
    main: 'supportMainView',
    category: 'supportCategoryView',
    create: 'supportCreateView',
    chat: 'supportConversationView'
  };
  const state = {
    view: 'main',
    config: {},
    categories: [],
    tickets: [],
    ticket: null,
    messages: [],
    attachments: [],
    selectedCategory: null,
    requestLogs: [],
    ticketsLoaded: false,
    createFiles: [],
    replyFiles: [],
    createKey: '',
    replyKey: '',
    loadingTickets: false,
    loadingConversation: false,
    creating: false,
    replying: false,
    pollTimer: 0,
    initialized: false,
    modal: {
      open: false,
      busy: false,
      history: false,
      opener: null
    }
  };

  function csrf() {
    return String((window.userState && window.userState.csrf) || '');
  }

  function toast(message, type) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type || 'info');
    }
  }

  function safeMessage(error, fallback) {
    const fallbackText = String(fallback || 'Please try again.');
    const message = String(error && error.message ? error.message : fallbackText).trim();
    if (!message || message.length > 220 || /firebase|exception|stack trace|support_(tickets|messages|attachments)|session[_ -]?token|csrf[_ -]?token|app[_ -]?key|\/api\//i.test(message)) {
      return fallbackText;
    }
    return message;
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

  function handleSessionError(error) {
    if (isSessionError(error) && typeof window.userSessionExpired === 'function') {
      window.userSessionExpired();
      return true;
    }
    return false;
  }

  async function get(action, params) {
    if (typeof window.proxyGet !== 'function') {
      throw new Error('Support service is unavailable.');
    }
    return window.proxyGet(action, params || {}, '', { busy: false });
  }

  async function refreshCsrfToken() {
    const data = await get('me', {});
    if (data && data.csrf && window.userState) {
      window.userState.csrf = String(data.csrf);
    }
    return csrf();
  }

  async function postForm(action, formData) {
    const send = async () => {
      const response = await fetch((window.USER_PROXY_URL || '/api/user/proxy.php') + '?action=' + encodeURIComponent(action), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'X-CSRF-Token': csrf(),
          'Accept': 'application/json'
        },
        body: formData
      });
      const responseText = await response.text();
      let json = null;
      try {
        json = JSON.parse(responseText);
      } catch (_) {
        json = null;
      }
      if (!response.ok || !json || !json.ok) {
        const error = new Error(String((json && json.message) || 'The request could not be completed.'));
        error.code = String((json && json.code) || 'REQUEST_FAILED');
        error.status = response.status;
        error.data = json && json.data && typeof json.data === 'object' ? json.data : {};
        if (handleSessionError(error)) throw error;
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
  }

  function makeIdempotencyKey(prefix) {
    const random = window.crypto && typeof window.crypto.randomUUID === 'function'
      ? window.crypto.randomUUID()
      : String(Date.now()) + '-' + Math.random().toString(36).slice(2);
    return String(prefix || 'SUPPORT') + '-' + random;
  }

  function formatDate(value, dateOnly) {
    let timestamp = Number(value || 0);
    if (!timestamp) return '-';
    if (timestamp < 100000000000) timestamp *= 1000;
    const date = new Date(timestamp);
    if (Number.isNaN(date.getTime())) return '-';
    if (dateOnly) {
      return date.toLocaleDateString([], { year: 'numeric', month: 'short', day: '2-digit' });
    }
    return date.toLocaleString([], {
      year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit'
    });
  }

  function formatTime(value) {
    let timestamp = Number(value || 0);
    if (!timestamp) return '-';
    if (timestamp < 100000000000) timestamp *= 1000;
    const date = new Date(timestamp);
    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function supportStatus(status) {
    const code = String(status || 'OPEN').toUpperCase();
    return ({ OPEN: 'Open', PENDING: 'Pending', REPLIED: 'Replied', RESOLVED: 'Resolved', CLOSED: 'Closed' })[code] || code;
  }

  function supportIsClosed(status) {
    return ['CLOSED', 'RESOLVED'].includes(String(status || '').toUpperCase());
  }

  function activeSupportTicket() {
    return state.tickets.find((ticket) => ticket && ticket.ticket_id && !supportIsClosed(ticket.status)) || null;
  }

  function renderStartChatState() {
    const button = $('supportStartChatButton');
    if (!button) return;
    if (!state.ticketsLoaded || state.loadingTickets) {
      button.disabled = true;
      button.textContent = 'Loading...';
      button.dataset.activeTicketId = '';
      return;
    }
    const active = activeSupportTicket();
    button.disabled = false;
    button.textContent = active ? 'Open Conversation' : 'Start Chat';
    button.dataset.activeTicketId = active ? String(active.ticket_id) : '';
  }

  async function startSupportChat() {
    if (!state.ticketsLoaded || state.loadingTickets) return;
    const active = activeSupportTicket();
    if (active) {
      await openSupportConversation(active.ticket_id);
      return;
    }
    navigate('category');
  }

  function supportStatusClass(status) {
    return 'status-' + String(status || 'OPEN').toLowerCase().replace(/[^a-z0-9]+/g, '-');
  }

  function setPageBusy(busy) {
    $('supportSection')?.setAttribute('aria-busy', String(Boolean(busy)));
  }

  function setButtonBusy(button, busy, busyText) {
    if (!button) return;
    if (busy) {
      if (!button.dataset.originalHtml) button.dataset.originalHtml = button.innerHTML;
      button.disabled = true;
      button.textContent = busyText || 'Please wait...';
    } else {
      button.disabled = false;
      if (button.dataset.originalHtml) button.innerHTML = button.dataset.originalHtml;
      delete button.dataset.originalHtml;
    }
  }

  function setView(view) {
    const next = viewIds[view] ? view : 'main';
    state.view = next;
    document.body.classList.toggle('support-subview-open', next !== 'main');
    document.body.classList.toggle('support-chat-open', next === 'chat');
    Object.entries(viewIds).forEach(([key, id]) => {
      $(id)?.classList.toggle('hidden', key !== next);
    });
    if (next !== 'chat') stopPolling();
    if (next === 'main') $('supportTicketList')?.scrollTo({ top: 0, behavior: 'auto' });
    if (next === 'category') $('supportCategoryGrid')?.scrollTo({ top: 0, behavior: 'auto' });
    if (next === 'create') $('supportCreateScroll')?.scrollTo({ top: 0, behavior: 'auto' });
  }

  function historyState(view, extra) {
    return Object.assign({ zpaySupport: true, view: view || state.view }, extra || {});
  }

  function navigate(view, options) {
    const opts = options || {};
    setView(view);
    const nextState = historyState(view, opts.ticketId ? { ticket_id: opts.ticketId } : {});
    if (opts.replace) {
      window.history.replaceState(nextState, '', '/user/support');
    } else {
      window.history.pushState(nextState, '', '/user/support');
    }
  }

  function requestStepBack() {
    if (state.modal.open) {
      closeSupportModal();
      return;
    }
    if (state.view === 'main') {
      window.location.assign('/user/dashboard');
      return;
    }
    window.history.back();
  }

  function setModalView(kind, title, message, rows) {
    const spinner = $('supportModalSpinner');
    const icon = $('supportModalIcon');
    const infoRows = $('supportTicketInfoRows');
    const action = $('supportModalAction');
    const isLoading = kind === 'loading';
    const isInfo = kind === 'info';
    spinner?.classList.toggle('hidden', !isLoading);
    icon?.classList.toggle('hidden', isLoading || isInfo);
    if (icon) icon.textContent = '!';
    if ($('supportModalTitle')) $('supportModalTitle').textContent = String(title || 'Support');
    if ($('supportModalMessage')) {
      $('supportModalMessage').textContent = String(message || '');
      $('supportModalMessage').classList.toggle('hidden', !message);
    }
    if (infoRows) {
      infoRows.replaceChildren();
      (rows || []).forEach((row) => {
        const item = document.createElement('div');
        item.className = 'support-ticket-info-row';
        const label = document.createElement('span');
        label.textContent = String(row.label || '');
        const value = document.createElement('strong');
        value.textContent = String(row.value || '-');
        item.append(label, value);
        infoRows.appendChild(item);
      });
      infoRows.classList.toggle('hidden', !isInfo);
    }
    if (action) {
      action.textContent = isInfo ? 'Close' : 'OK';
      action.classList.toggle('hidden', isLoading);
    }
  }

  function openSupportModal(kind, title, message, rows, options) {
    const modal = $('supportActionModal');
    if (!modal) return;
    if (state.modal.open) closeSupportModal({ force: true, fromHistory: true, restoreFocus: false });
    state.modal.open = true;
    state.modal.busy = kind === 'loading';
    state.modal.opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    state.modal.history = Boolean(options && options.history && kind !== 'loading');
    setModalView(kind, title, message, rows);
    modal.classList.remove('hidden');
    modal.removeAttribute('inert');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('support-modal-open');
    Object.values(viewIds).forEach((id) => $(id)?.setAttribute('inert', ''));
    if (state.modal.history) {
      window.history.pushState(historyState(state.view, { supportModal: true, ticket_id: state.ticket && state.ticket.ticket_id }), '', '/user/support');
    }
    if (!state.modal.busy) window.setTimeout(() => $('supportModalAction')?.focus(), 30);
  }

  function openSupportLoading(message) {
    setPageBusy(true);
    openSupportModal('loading', String(message || 'Loading support...'), '', [], { history: false });
  }

  function openSupportError(message) {
    openSupportModal('error', 'Unable to Continue', String(message || 'Please try again.'), [], { history: true });
  }

  function openTicketInfo() {
    const ticket = state.ticket || {};
    if (!ticket.ticket_id) return;
    openSupportModal('info', 'Ticket Info', '', [
      { label: 'Ticket ID', value: ticket.ticket_id },
      { label: 'Status', value: ticket.status_label || supportStatus(ticket.status) },
      { label: 'Subject', value: ticket.subject || '-' },
      { label: 'Category', value: ticket.category_name || ticket.category_code || '-' },
      { label: 'Created', value: formatDate(ticket.created_at) }
    ], { history: true });
  }

  function closeSupportModal(options) {
    const opts = options || {};
    if (!state.modal.open) return;
    if (state.modal.busy && !opts.force) return;
    if (state.modal.history && !opts.fromHistory) {
      window.history.back();
      return;
    }
    const modal = $('supportActionModal');
    modal?.classList.add('hidden');
    modal?.setAttribute('aria-hidden', 'true');
    modal?.setAttribute('inert', '');
    document.body.classList.remove('support-modal-open');
    Object.values(viewIds).forEach((id) => $(id)?.removeAttribute('inert'));
    const opener = state.modal.opener;
    state.modal.open = false;
    state.modal.busy = false;
    state.modal.history = false;
    state.modal.opener = null;
    setPageBusy(false);
    if (opts.restoreFocus !== false && opener && opener.isConnected) opener.focus();
  }

  function closeSupportLoading() {
    if (state.modal.open && state.modal.busy) closeSupportModal({ force: true, fromHistory: true, restoreFocus: false });
  }

  function categoryIcon(category) {
    const source = (String(category.code || '') + ' ' + String(category.name || '')).toUpperCase();
    if (source.includes('ACCOUNT') || source.includes('LOGIN')) return 'A';
    if (source.includes('ADD')) return '+';
    if (source.includes('TOPUP') || source.includes('TOP-UP') || source.includes('MOBILE')) return 'M';
    if (source.includes('BKASH')) return 'bK';
    if (source.includes('NAGAD')) return 'N';
    if (source.includes('ZPAY') || source.includes('Z-PAY') || source.includes('TRANSFER')) return 'Z';
    if (source.includes('BUNDLE')) return 'B';
    if (source.includes('WALLET') || source.includes('BALANCE')) return '$';
    if (source.includes('TRANSACTION')) return '!';
    return '?';
  }

  function renderCategories() {
    const grid = $('supportCategoryGrid');
    if (!grid) return;
    grid.replaceChildren();
    if (!state.categories.length) {
      const empty = document.createElement('div');
      empty.className = 'support-empty-state';
      empty.textContent = 'No active support categories are available.';
      grid.appendChild(empty);
      return;
    }
    state.categories.forEach((category) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'support-category-card';
      const icon = document.createElement('span');
      icon.className = 'support-category-icon';
      icon.textContent = categoryIcon(category);
      const label = document.createElement('span');
      label.textContent = String(category.name || category.code || 'Other');
      button.append(icon, label);
      button.addEventListener('click', () => selectCategory(category));
      grid.appendChild(button);
    });
  }

  function selectCategory(category) {
    state.selectedCategory = category || null;
    if ($('supportSelectedCategory')) {
      $('supportSelectedCategory').textContent = String(category && (category.name || category.code) || 'Support');
    }
    const related = Boolean(category && category.related_request_enabled);
    $('supportRelatedWrap')?.classList.toggle('hidden', !related || !state.requestLogs.length);
    const attachmentsAllowed = Boolean(state.config.attachments_enabled !== false && (!category || category.attachment_enabled !== false));
    if ($('supportAddScreenshot')) $('supportAddScreenshot').disabled = !attachmentsAllowed;
    navigate('create');
  }

  function renderRelatedRequests() {
    const select = $('supportRelatedRequest');
    if (!select) return;
    const current = select.value;
    select.replaceChildren(new Option('No related request', ''));
    state.requestLogs.slice(0, 40).forEach((row) => {
      const id = String(row.request_id || row.transfer_id || row.id || '');
      if (!id) return;
      const label = [id, row.type || row.request_type || '', row.amount_text || ''].filter(Boolean).join(' - ');
      const option = new Option(label, id);
      option.dataset.relatedType = String(row.type || row.request_type || '');
      select.add(option);
    });
    select.value = current;
  }

  async function loadSupportConfig() {
    const data = await get('support_config', {});
    state.config = data.config || {};
    state.categories = Array.isArray(data.categories) ? data.categories : [];
    renderCategories();
  }

  async function loadRequestLogs() {
    try {
      const data = await get('request_logs', { limit: 40 });
      state.requestLogs = Array.isArray(data.rows) ? data.rows : (Array.isArray(data.logs) ? data.logs : []);
      renderRelatedRequests();
    } catch (error) {
      if (handleSessionError(error)) return;
      state.requestLogs = [];
    }
  }

  async function loadSupportTickets(force) {
    if (state.loadingTickets) return state.tickets;
    if (state.tickets.length && !force) {
      renderTickets();
      return state.tickets;
    }
    state.loadingTickets = true;
    renderStartChatState();
    const refresh = $('supportRefreshButton');
    if (refresh) refresh.disabled = true;
    try {
      const data = await get('support_list', { limit: 50 });
      state.tickets = (Array.isArray(data.tickets) ? data.tickets : []).slice().sort((a, b) =>
        Number(b.last_message_at || b.updated_at || b.created_at || 0) - Number(a.last_message_at || a.updated_at || a.created_at || 0)
      );
      state.ticketsLoaded = true;
      renderTickets();
      return state.tickets;
    } catch (error) {
      state.ticketsLoaded = false;
      if (handleSessionError(error)) return [];
      const list = $('supportTicketList');
      if (list) {
        list.replaceChildren();
        const empty = document.createElement('div');
        empty.className = 'support-empty-state';
        empty.textContent = 'Support conversations could not be loaded.';
        list.appendChild(empty);
      }
      throw error;
    } finally {
      state.loadingTickets = false;
      if (refresh) refresh.disabled = false;
      renderStartChatState();
    }
  }

  function renderTickets() {
    const list = $('supportTicketList');
    if (!list) return;
    list.replaceChildren();
    if (!state.tickets.length) {
      const empty = document.createElement('div');
      empty.className = 'support-empty-state';
      empty.textContent = 'No conversations yet.';
      list.appendChild(empty);
      renderStartChatState();
      return;
    }
    state.tickets.forEach((ticket) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'support-ticket-card' + (ticket.user_unread ? ' unread' : '');
      const copy = document.createElement('div');
      copy.className = 'support-ticket-copy';
      const title = document.createElement('h3');
      title.textContent = String(ticket.subject || 'Support Request');
      const ticketId = document.createElement('small');
      ticketId.className = 'support-ticket-id';
      ticketId.textContent = String(ticket.ticket_id || '-');
      const meta = document.createElement('span');
      meta.className = 'support-ticket-meta';
      meta.textContent = [ticket.category_name || ticket.category_code || 'Support', formatDate(ticket.last_message_at || ticket.updated_at || ticket.created_at)].join(' - ');
      const preview = document.createElement('p');
      preview.className = 'support-ticket-preview';
      preview.textContent = String(ticket.last_message_preview || 'Open this conversation to view messages.');
      copy.append(title, ticketId, meta, preview);
      const status = document.createElement('span');
      status.className = 'support-status-pill ' + supportStatusClass(ticket.status);
      status.textContent = String(ticket.status_label || supportStatus(ticket.status));
      button.append(copy, status);
      button.addEventListener('click', () => openSupportConversation(ticket.ticket_id));
      list.appendChild(button);
    });
    renderStartChatState();
  }

  function validateFiles(files) {
    const rows = Array.from(files || []);
    const maxFiles = Math.max(0, Number(state.config.max_attachments == null ? 3 : state.config.max_attachments));
    const maxSize = Math.max(1, Number(state.config.max_file_size || 5 * 1024 * 1024));
    if (rows.length > maxFiles) throw new Error('You can attach up to ' + maxFiles + ' screenshots.');
    rows.forEach((file) => {
      if (!allowedImages.has(String(file.type || '').toLowerCase())) {
        throw new Error('Only JPG, PNG and WebP screenshots are allowed.');
      }
      if (file.size <= 0 || file.size > maxSize) {
        throw new Error('Each screenshot must be within the allowed file size.');
      }
    });
    return rows;
  }

  function mergeFiles(existing, incoming) {
    const maxFiles = Math.max(0, Number(state.config.max_attachments == null ? 3 : state.config.max_attachments));
    const merged = existing.slice();
    validateFiles(incoming).forEach((file) => {
      const duplicate = merged.some((row) => row.name === file.name && row.size === file.size && row.lastModified === file.lastModified);
      if (!duplicate) merged.push(file);
    });
    if (merged.length > maxFiles) throw new Error('You can attach up to ' + maxFiles + ' screenshots.');
    validateFiles(merged);
    return merged;
  }

  function renderFilePreviews(files, container, removeFile) {
    if (!container) return;
    container.replaceChildren();
    files.forEach((file, index) => {
      const frame = document.createElement('div');
      frame.className = 'support-file-preview';
      const image = document.createElement('img');
      const source = URL.createObjectURL(file);
      image.src = source;
      image.alt = file.name || 'Selected screenshot';
      image.onload = () => URL.revokeObjectURL(source);
      image.onerror = () => URL.revokeObjectURL(source);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'support-file-remove';
      remove.setAttribute('aria-label', 'Remove ' + (file.name || 'screenshot'));
      remove.textContent = 'x';
      remove.addEventListener('click', () => removeFile(index));
      frame.append(image, remove);
      container.appendChild(frame);
    });
  }

  function renderCreateFiles() {
    renderFilePreviews(state.createFiles, $('supportAttachmentPreview'), (index) => {
      state.createFiles.splice(index, 1);
      renderCreateFiles();
    });
    if ($('supportAttachmentCount')) {
      $('supportAttachmentCount').textContent = state.createFiles.length + '/' + Number(state.config.max_attachments || 3) + ' selected';
    }
  }

  function renderReplyFiles() {
    renderFilePreviews(state.replyFiles, $('supportReplyAttachmentPreview'), (index) => {
      state.replyFiles.splice(index, 1);
      renderReplyFiles();
    });
  }

  async function createSupportTicket(event) {
    event.preventDefault();
    if (state.creating) return;
    const category = state.selectedCategory;
    const subject = String($('supportSubject')?.value || '').trim();
    const message = String($('supportMessage')?.value || '').trim();
    if (!category || !category.code) {
      openSupportError('Please choose a support topic.');
      return;
    }
    if (!subject) {
      openSupportError('Please enter a subject.');
      $('supportSubject')?.focus();
      return;
    }
    if (message.length < 4) {
      openSupportError('Please describe your issue.');
      $('supportMessage')?.focus();
      return;
    }
    try {
      validateFiles(state.createFiles);
    } catch (error) {
      openSupportError(safeMessage(error, 'The selected screenshot is not supported.'));
      return;
    }

    const button = $('supportCreateButton');
    state.creating = true;
    if (!state.createKey) state.createKey = makeIdempotencyKey('SUPPORT-CREATE');
    const data = new FormData();
    data.append('category_code', String(category.code));
    data.append('subject', subject);
    data.append('message', message);
    data.append('idempotency_key', state.createKey);
    data.append('related_request_id', String($('supportRelatedRequest')?.value || ''));
    data.append('related_type', String($('supportRelatedRequest')?.selectedOptions?.[0]?.dataset.relatedType || ''));
    state.createFiles.forEach((file) => data.append('attachments[]', file, file.name));
    setButtonBusy(button, true, 'Creating...');
    openSupportLoading('Creating chat, please wait...');
    try {
      const result = await postForm('support_create', data);
      const ticket = result.ticket || {};
      state.createKey = '';
      state.createFiles = [];
      $('supportCreateForm')?.reset();
      renderCreateFiles();
      await loadSupportTickets(true).catch(() => state.tickets);
      closeSupportLoading();
      if (ticket.ticket_id) {
        window.history.replaceState(historyState('main'), '', '/user/support');
        await openSupportConversation(ticket.ticket_id, { replace: false });
      } else {
        navigate('main', { replace: true });
        toast('Support chat created.', 'success');
      }
    } catch (error) {
      closeSupportLoading();
      if (handleSessionError(error)) return;
      if (String(error && error.code || '').toUpperCase() === 'SUPPORT_ACTIVE_TICKET_EXISTS') {
        await loadSupportTickets(true).catch(() => state.tickets);
        const activeId = String(error && error.data && (error.data.active_ticket_id || error.data.active_ticket?.ticket_id) || '');
        const active = state.tickets.find((ticket) => String(ticket.ticket_id || '') === activeId) || activeSupportTicket();
        if (active && active.ticket_id) {
          window.history.replaceState(historyState('main'), '', '/user/support');
          setView('main');
          await openSupportConversation(active.ticket_id);
        } else {
          openSupportError('You already have an active support conversation.');
        }
      } else {
        openSupportError(safeMessage(error, 'Support request could not be submitted.'));
      }
    } finally {
      state.creating = false;
      setButtonBusy(button, false);
    }
  }

  async function openSupportConversation(ticketId, options) {
    const id = String(ticketId || '').trim();
    const opts = options || {};
    if (!id || state.loadingConversation) return;
    state.loadingConversation = true;
    if (!opts.silent) openSupportLoading('Loading conversation...');
    try {
      const data = await get('support_details', { ticket_id: id });
      state.ticket = data.ticket || null;
      state.messages = Array.isArray(data.messages) ? data.messages : [];
      state.attachments = Array.isArray(data.attachments) ? data.attachments : [];
      renderConversation(Boolean(opts.keepScroll));
      if (!opts.fromHistory) navigate('chat', { replace: Boolean(opts.replace), ticketId: id });
      else setView('chat');
      startPolling();
    } catch (error) {
      if (!opts.silent && !handleSessionError(error)) openSupportError(safeMessage(error, 'Support conversation could not be opened.'));
    } finally {
      state.loadingConversation = false;
      if (!opts.silent) closeSupportLoading();
    }
  }

  function attachmentUrl(ticketId, attachmentId) {
    return (window.USER_PROXY_URL || '/api/user/proxy.php')
      + '?action=support_attachment&ticket_id=' + encodeURIComponent(String(ticketId || ''))
      + '&attachment_id=' + encodeURIComponent(String(attachmentId || ''));
  }

  function renderConversation(keepScroll) {
    const ticket = state.ticket || {};
    const messages = $('supportMessages');
    const wasNearBottom = !keepScroll || !messages || messages.scrollHeight - messages.scrollTop - messages.clientHeight < 90;
    if ($('supportConversationStatus')) $('supportConversationStatus').textContent = String(ticket.status_label || supportStatus(ticket.status));
    if ($('supportConversationTicketId')) $('supportConversationTicketId').textContent = String(ticket.ticket_id || '-');
    if ($('supportConversationSubject')) $('supportConversationSubject').textContent = String(ticket.subject || 'Support Request');
    renderMessages();
    const closed = supportIsClosed(ticket.status);
    $('supportReplyForm')?.classList.toggle('hidden', closed);
    $('supportReplyAttachmentPreview')?.classList.toggle('hidden', closed);
    if ($('supportClosedNotice')) {
      $('supportClosedNotice').classList.toggle('hidden', !closed);
      $('supportClosedNotice').textContent = closed
        ? (String(ticket.status || '').toUpperCase() === 'RESOLVED' ? 'This ticket has been resolved.' : 'This ticket is closed. You can no longer reply.')
        : '';
    }
    if (wasNearBottom && messages) requestAnimationFrame(() => { messages.scrollTop = messages.scrollHeight; });
  }

  function renderMessages() {
    const container = $('supportMessages');
    if (!container) return;
    const byId = new Map();
    state.messages.forEach((message, index) => {
      const id = String(message.message_id || message.idempotency_key || 'message-' + index);
      byId.set(id, message);
    });
    const rows = Array.from(byId.values()).sort((a, b) => Number(a.created_at || 0) - Number(b.created_at || 0));
    const attachmentsByMessage = new Map();
    state.attachments.forEach((attachment) => {
      const id = String(attachment.message_id || '');
      if (!attachmentsByMessage.has(id)) attachmentsByMessage.set(id, []);
      attachmentsByMessage.get(id).push(attachment);
    });
    container.replaceChildren();
    let lastDate = '';
    rows.forEach((message) => {
      const date = formatDate(message.created_at, true);
      if (date !== lastDate) {
        const chip = document.createElement('div');
        chip.className = 'support-date-chip';
        chip.textContent = date;
        container.appendChild(chip);
        lastDate = date;
      }
      const sender = String(message.sender_type || '').toUpperCase();
      if (sender === 'SYSTEM') {
        const system = document.createElement('article');
        system.className = 'support-message system';
        system.textContent = String(message.message || '');
        container.appendChild(system);
        return;
      }
      const isUser = sender === 'USER';
      const row = document.createElement('div');
      row.className = 'support-message-row ' + (isUser ? 'user' : 'support');
      if (!isUser) {
        const avatar = document.createElement('span');
        avatar.className = 'support-avatar';
        avatar.textContent = 'Z';
        avatar.setAttribute('aria-label', 'Z-Pay Swift Support');
        row.appendChild(avatar);
      }
      const bubble = document.createElement('article');
      bubble.className = 'support-message ' + (isUser ? 'user' : 'support');
      const text = document.createElement('p');
      text.textContent = String(message.message || '');
      if (text.textContent) bubble.appendChild(text);
      const files = attachmentsByMessage.get(String(message.message_id || '')) || [];
      if (files.length) {
        const wrap = document.createElement('div');
        wrap.className = 'support-message-attachments';
        files.forEach((attachment, index) => {
          const link = document.createElement('a');
          link.className = 'support-message-attachment';
          link.href = attachmentUrl(state.ticket && state.ticket.ticket_id, attachment.attachment_id);
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          const image = document.createElement('img');
          image.src = link.href;
          image.alt = String(attachment.original_name || 'Support screenshot ' + (index + 1));
          image.loading = 'lazy';
          const name = document.createElement('span');
          name.textContent = String(attachment.original_name || 'Screenshot ' + (index + 1));
          link.append(image, name);
          wrap.appendChild(link);
        });
        bubble.appendChild(wrap);
      }
      const meta = document.createElement('small');
      const senderName = isUser ? 'You' : String(message.sender_name || 'Z-Pay Swift Support');
      meta.textContent = senderName + ' - ' + formatTime(message.created_at);
      bubble.appendChild(meta);
      row.appendChild(bubble);
      container.appendChild(row);
    });
    if (!rows.length) {
      const empty = document.createElement('div');
      empty.className = 'support-empty-state';
      empty.textContent = 'No messages yet.';
      container.appendChild(empty);
    }
  }

  async function replySupport(event) {
    event.preventDefault();
    if (state.replying || !state.ticket || supportIsClosed(state.ticket.status)) return;
    const input = $('supportReplyMessage');
    const message = String(input && input.value || '').trim();
    if (!message && !state.replyFiles.length) {
      openSupportError('Please write a reply or attach a screenshot.');
      return;
    }
    try {
      validateFiles(state.replyFiles);
    } catch (error) {
      openSupportError(safeMessage(error, 'The selected screenshot is not supported.'));
      return;
    }
    const button = $('supportReplyButton');
    state.replying = true;
    if (!state.replyKey) state.replyKey = makeIdempotencyKey('SUPPORT-REPLY');
    const data = new FormData();
    data.append('ticket_id', String(state.ticket.ticket_id || ''));
    data.append('message', message);
    data.append('idempotency_key', state.replyKey);
    state.replyFiles.forEach((file) => data.append('attachments[]', file, file.name));
    setButtonBusy(button, true, 'Sending...');
    openSupportLoading('Sending message...');
    try {
      const result = await postForm('support_reply', data);
      state.replyKey = '';
      state.replyFiles = [];
      if (input) {
        input.value = '';
        input.style.height = 'auto';
      }
      renderReplyFiles();
      state.ticket = result.ticket || state.ticket;
      state.messages = Array.isArray(result.messages) ? result.messages : state.messages;
      state.attachments = Array.isArray(result.attachments) ? result.attachments : state.attachments;
      renderConversation(false);
      loadSupportTickets(true).catch(() => state.tickets);
    } catch (error) {
      if (!handleSessionError(error)) {
        openSupportError(safeMessage(error, 'Reply could not be sent. Your message is still here.'));
      }
    } finally {
      closeSupportLoading();
      state.replying = false;
      setButtonBusy(button, false);
    }
  }

  function startPolling() {
    stopPolling();
    state.pollTimer = window.setInterval(() => {
      if (document.visibilityState === 'visible' && state.view === 'chat' && state.ticket && !state.replying && !state.modal.open) {
        openSupportConversation(state.ticket.ticket_id, { silent: true, fromHistory: true, keepScroll: true });
      }
    }, 30000);
  }

  function stopPolling() {
    if (state.pollTimer) window.clearInterval(state.pollTimer);
    state.pollTimer = 0;
  }

  function handleAttachmentSelection(input, target) {
    try {
      if (target === 'create') {
        state.createFiles = mergeFiles(state.createFiles, input.files);
        renderCreateFiles();
      } else {
        state.replyFiles = mergeFiles(state.replyFiles, input.files);
        renderReplyFiles();
      }
    } catch (error) {
      openSupportError(safeMessage(error, 'The selected screenshot is not supported.'));
    } finally {
      input.value = '';
    }
  }

  function ensureFocusedVisible(target) {
    if (!(target instanceof HTMLElement) || !target.matches('input, textarea, select')) return;
    window.setTimeout(() => {
      target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    }, 180);
  }

  function updateKeyboardState() {
    const viewport = window.visualViewport;
    const keyboardOpen = Boolean(viewport && window.innerHeight - viewport.height > 140);
    document.body.classList.toggle('support-keyboard-open', keyboardOpen);
    document.body.style.setProperty('--support-keyboard-space', keyboardOpen ? Math.max(0, window.innerHeight - viewport.height) + 'px' : '0px');
    if (keyboardOpen) ensureFocusedVisible(document.activeElement);
  }

  function handlePopState(event) {
    if (state.modal.open) {
      if (state.modal.busy) {
        window.history.pushState(historyState(state.view, { ticket_id: state.ticket && state.ticket.ticket_id }), '', '/user/support');
        return;
      }
      closeSupportModal({ fromHistory: true });
      return;
    }
    const target = event.state && event.state.zpaySupport ? String(event.state.view || 'main') : 'main';
    if (state.view === 'chat' && target !== 'main') {
      state.ticket = null;
      state.messages = [];
      state.attachments = [];
      setView('main');
      window.history.replaceState(historyState('main'), '', '/user/support');
      loadSupportTickets(true).catch(() => state.tickets);
      return;
    }
    if (target === 'chat' && event.state.ticket_id) {
      openSupportConversation(event.state.ticket_id, { fromHistory: true });
      return;
    }
    if (target === 'create' && !state.selectedCategory) {
      setView('category');
      window.history.replaceState(historyState('category'), '', '/user/support');
      return;
    }
    setView(target);
  }

  function bindEvents() {
    $('supportStartChatButton')?.addEventListener('click', startSupportChat);
    $('supportRefreshButton')?.addEventListener('click', async () => {
      if (state.loadingTickets) return;
      openSupportLoading('Loading support...');
      try {
        await loadSupportTickets(true);
      } catch (error) {
        if (!handleSessionError(error)) openSupportError(safeMessage(error, 'Support conversations could not be loaded.'));
      } finally {
        closeSupportLoading();
      }
    });
    $('supportCreateBack')?.addEventListener('click', requestStepBack);
    $('supportConversationBack')?.addEventListener('click', requestStepBack);
    $('supportInfoButton')?.addEventListener('click', openTicketInfo);
    $('supportTicketStrip')?.addEventListener('click', openTicketInfo);
    $('supportModalAction')?.addEventListener('click', () => closeSupportModal());
    $('supportCreateForm')?.addEventListener('submit', createSupportTicket);
    $('supportAddScreenshot')?.addEventListener('click', () => $('supportAttachments')?.click());
    $('supportAttachments')?.addEventListener('change', (event) => handleAttachmentSelection(event.currentTarget, 'create'));
    $('supportReplyForm')?.addEventListener('submit', replySupport);
    $('supportReplyAttachmentButton')?.addEventListener('click', () => $('supportReplyAttachment')?.click());
    $('supportReplyAttachment')?.addEventListener('change', (event) => handleAttachmentSelection(event.currentTarget, 'reply'));
    $('supportReplyMessage')?.addEventListener('input', (event) => {
      event.currentTarget.style.height = 'auto';
      event.currentTarget.style.height = Math.min(112, event.currentTarget.scrollHeight) + 'px';
    });
    document.addEventListener('focusin', (event) => ensureFocusedVisible(event.target));
    window.visualViewport?.addEventListener('resize', updateKeyboardState);
    window.visualViewport?.addEventListener('scroll', updateKeyboardState);
    window.addEventListener('popstate', handlePopState);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible' && state.view === 'chat') startPolling();
      else if (document.visibilityState !== 'visible') stopPolling();
    });
    window.addEventListener('pagehide', () => {
      stopPolling();
      closeSupportLoading();
      document.body.classList.remove('support-keyboard-open');
      document.body.classList.remove('support-chat-open');
    });
  }

  async function initSupportPage() {
    await window.UserShell.ready;
    if (state.initialized) return;
    state.initialized = true;
    bindEvents();
    const requestedTicket = new URLSearchParams(window.location.search).get('ticket_id');
    window.history.replaceState(historyState('main'), '', '/user/support');
    openSupportLoading('Loading support...');
    const results = await Promise.allSettled([
      loadSupportConfig(),
      loadSupportTickets(true),
      loadRequestLogs()
    ]);
    closeSupportLoading();
    setPageBusy(false);
    const failed = results.find((result) => result.status === 'rejected');
    if (failed && !handleSessionError(failed.reason)) {
      openSupportError(safeMessage(failed.reason, 'Some support information could not be loaded.'));
    }
    if (requestedTicket) await openSupportConversation(requestedTicket, { replace: true });
  }

  initSupportPage().catch((error) => {
    closeSupportLoading();
    setPageBusy(false);
    if (!handleSessionError(error)) openSupportError(safeMessage(error, 'Support could not be loaded.'));
  });
})();
