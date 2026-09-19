(() => {
  'use strict';

  const ENDPOINT = '/api/admin/birthday_admin.php';
  const adminState = {
    loaded: false,
    loading: false,
    tab: 'OVERVIEW',
    summary: null,
    universes: [],
    universePage: 1,
    universeTotal: 0,
    universeHasMore: false,
    reports: [],
  };
  const $ = id => document.getElementById(id);

  function currentCsrf() {
    try { return String(state?.csrf || ''); }
    catch (_error) { return ''; }
  }

  function busy(on, message = 'Loading Birthday Universe data...') {
    try { if (typeof setBusy === 'function') setBusy(on, message); }
    catch (_error) {}
  }

  function notice(message, type = 'ok') {
    try { if (typeof showToast === 'function') showToast(message, type); }
    catch (_error) {}
  }

  async function request(action, { method = 'GET', params = {}, body, form, showBusy = true } = {}) {
    if (showBusy) busy(true);
    try {
      const url = new URL(ENDPOINT, window.location.origin);
      url.searchParams.set('action', action);
      Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && String(value) !== '') url.searchParams.set(key, String(value));
      });
      const headers = new Headers({ Accept: 'application/json', 'Cache-Control': 'no-cache' });
      const options = { method, credentials: 'same-origin', cache: 'no-store', headers };
      if (method !== 'GET') {
        const csrf = currentCsrf();
        if (csrf) headers.set('X-CSRF-TOKEN', csrf);
        if (form instanceof FormData) {
          options.body = form;
        } else {
          headers.set('Content-Type', 'application/json');
          options.body = JSON.stringify(body || {});
        }
      }
      const response = await fetch(url, options);
      let json;
      try { json = JSON.parse(await response.text()); }
      catch (_error) { throw new Error('The server returned an invalid response.'); }
      if (!response.ok || !json?.ok) {
        if (response.status === 401) {
          try { if (typeof showLogin === 'function') showLogin(); }
          catch (_error) {}
        }
        throw new Error(json?.message || 'Birthday Universe request failed.');
      }
      return json.data || {};
    } finally {
      if (showBusy) busy(false);
    }
  }

  function element(tag, className = '', text = '') {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '') node.textContent = String(text);
    return node;
  }

  function button(text, action, value = '', className = 'btn ghost') {
    const node = element('button', className, text);
    node.type = 'button';
    node.dataset.birthdayAction = action;
    if (value) node.dataset.value = value;
    return node;
  }

  function formatDate(value) {
    const timestamp = Number(value || 0);
    if (!timestamp) return '-';
    try { return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(timestamp * 1000)); }
    catch (_error) { return '-'; }
  }

  function formatBytes(value) {
    const bytes = Math.max(0, Number(value || 0));
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1073741824) return `${(bytes / 1048576).toFixed(1)} MB`;
    return `${(bytes / 1073741824).toFixed(2)} GB`;
  }

  function ensureUi() {
    const root = $('birthdayAdminMount');
    if (!root || root.dataset.ready === 'true') return;
    root.dataset.ready = 'true';
    root.innerHTML = `
      <div class="birthday-admin-hero">
        <div><span class="birthday-admin-kicker">Z SKY 24 · SELF-SERVICE EXPERIENCE</span><h3 id="birthdayAdminTitle">Birthday Universe Admin</h3><p>Manage generated Universes, templates, licensed music, reports and retention controls.</p></div>
        <div class="birthday-admin-actions"><a class="btn ghost" href="https://zsky24.com/birthday" target="_blank" rel="noopener">Open public site</a><button class="btn blue" id="birthdayAdminRefresh" type="button">Refresh</button></div>
      </div>
      <div class="birthday-admin-tabs" role="tablist" aria-label="Birthday Universe administration">
        <button class="birthday-admin-tab active" type="button" role="tab" aria-selected="true" data-birthday-tab="OVERVIEW">Overview</button>
        <button class="birthday-admin-tab" type="button" role="tab" aria-selected="false" data-birthday-tab="UNIVERSES">Universes</button>
        <button class="birthday-admin-tab" type="button" role="tab" aria-selected="false" data-birthday-tab="CATALOG">Templates & music</button>
        <button class="birthday-admin-tab" type="button" role="tab" aria-selected="false" data-birthday-tab="REPORTS">Reports</button>
        <button class="birthday-admin-tab" type="button" role="tab" aria-selected="false" data-birthday-tab="SETTINGS">Settings</button>
      </div>
      <section class="birthday-admin-view" id="birthdayAdminOverview">
        <div class="birthday-admin-metrics" id="birthdayAdminMetrics"></div>
        <div class="birthday-admin-grid">
          <article class="card birthday-admin-panel"><h3>Template usage</h3><p>Generated Universe count by template.</p><div class="birthday-admin-list" id="birthdayTemplateUsage"></div></article>
          <article class="card birthday-admin-panel"><h3>Platform status</h3><p>Storage and ad delivery activity.</p><div class="birthday-admin-list" id="birthdayPlatformStatus"></div></article>
        </div>
      </section>
      <section class="birthday-admin-view" id="birthdayAdminUniverses" hidden>
        <div class="card birthday-admin-panel">
          <div class="birthday-admin-toolbar"><label>Search<input class="input" id="birthdayUniverseSearch" maxlength="100" placeholder="Name, slug or star ID"></label><label>Status<select class="input" id="birthdayUniverseStatus"><option value="ALL">All</option><option>ACTIVE</option><option>BLOCKED</option><option>EXPIRED</option><option>DELETED</option></select></label><button class="btn brand" id="birthdayUniverseSearchButton" type="button">Search</button></div>
          <div class="birthday-admin-list" id="birthdayUniverseList"></div>
          <div class="birthday-admin-pagination"><button class="btn ghost" id="birthdayUniversePrevious" type="button">Previous</button><span id="birthdayUniversePage">Page 1</span><button class="btn ghost" id="birthdayUniverseNext" type="button">Next</button></div>
        </div>
      </section>
      <section class="birthday-admin-view birthday-admin-grid" id="birthdayAdminCatalog" hidden>
        <article class="card birthday-admin-panel"><h3>Template registry</h3><p>Only deployed code templates appear here. Configure their public metadata and availability.</p><div class="birthday-admin-list" id="birthdayTemplateSettings"></div></article>
        <article class="card birthday-admin-panel"><h3>Licensed music</h3><p>Only upload music you are legally permitted to use on public pages.</p><form class="birthday-admin-form birthday-music-upload" id="birthdayMusicForm"><label class="wide">Track name<input class="input" name="name" maxlength="80" required></label><label>Duration (seconds)<input class="input" name="duration" type="number" min="0" max="7200" value="0"></label><label>Audio file<input class="input" name="music" type="file" accept="audio/mpeg,audio/ogg,audio/mp4" required></label><label class="birthday-admin-check wide"><input name="rights_confirmed" type="checkbox" value="1" required><span>I confirm this track is licensed for platform use.</span></label><button class="btn brand wide" type="submit">Upload track</button></form><div class="birthday-admin-list" id="birthdayMusicList"></div></article>
      </section>
      <section class="birthday-admin-view" id="birthdayAdminReports" hidden><div class="card birthday-admin-panel"><h3>Content reports</h3><p>Resolve reviewed privacy, abuse, spam or copyright reports.</p><div class="birthday-admin-list" id="birthdayReportList"></div></div></section>
      <section class="birthday-admin-view" id="birthdayAdminSettings" hidden><div class="card birthday-admin-panel"><h3>Generation and retention</h3><p>Defaults preserve anonymous generation while keeping cleanup and abuse limits configurable.</p><form class="birthday-admin-form" id="birthdaySettingsForm"><label>Retention days<input class="input" name="retention_days" type="number" min="7" max="3650" required></label><label>Renewal days<input class="input" name="renewal_days" type="number" min="7" max="3650" required></label><label>Creations per IP / hour<input class="input" name="generation_per_hour" type="number" min="1" max="50" required></label><label>Draft lifetime (seconds)<input class="input" name="draft_ttl_seconds" type="number" min="3600" max="604800" required></label><label>Default language<select class="input" name="default_locale"><option value="en">English</option><option value="bn">Bangla</option></select></label><label class="birthday-admin-check"><input name="enabled" type="checkbox"><span>Creation enabled</span></label><label class="birthday-admin-check wide"><input name="allow_public_indexing" type="checkbox"><span>Allow users to opt into public search indexing</span></label><button class="btn brand wide" type="submit">Save settings</button></form></div></section>
      <dialog class="birthday-admin-dialog" id="birthdayMusicEditDialog"><form method="dialog" class="birthday-admin-form" id="birthdayMusicEditForm"><h3 class="wide">Edit music details</h3><input name="id" type="hidden"><label class="wide">Track name<input class="input" name="name" maxlength="80" required></label><label class="wide">Duration (seconds)<input class="input" name="duration" type="number" min="0" max="7200" required></label><div class="birthday-admin-actions wide"><button class="btn ghost" type="button" data-birthday-dialog-close>Cancel</button><button class="btn brand" type="submit">Save changes</button></div></form></dialog>`;
  }

  function setTab(tab) {
    const allowed = ['OVERVIEW', 'UNIVERSES', 'CATALOG', 'REPORTS', 'SETTINGS'];
    adminState.tab = allowed.includes(tab) ? tab : 'OVERVIEW';
    document.querySelectorAll('[data-birthday-tab]').forEach(node => {
      const active = node.dataset.birthdayTab === adminState.tab;
      node.classList.toggle('active', active);
      node.setAttribute('aria-selected', String(active));
    });
    const views = {
      OVERVIEW: 'birthdayAdminOverview', UNIVERSES: 'birthdayAdminUniverses', CATALOG: 'birthdayAdminCatalog',
      REPORTS: 'birthdayAdminReports', SETTINGS: 'birthdayAdminSettings'
    };
    Object.entries(views).forEach(([key, id]) => { if ($(id)) $(id).hidden = key !== adminState.tab; });
    if (adminState.tab === 'UNIVERSES' && !adminState.universes.length) void loadUniverses();
    if (adminState.tab === 'REPORTS' && !adminState.reports.length) void loadReports();
  }

  function metric(label, value, detail = '') {
    const node = element('div', 'birthday-admin-metric');
    node.append(element('span', '', label), element('strong', '', value));
    if (detail) node.append(element('small', '', detail));
    return node;
  }

  function renderSummary() {
    const data = adminState.summary || {};
    const metrics = data.metrics || {};
    const target = $('birthdayAdminMetrics');
    target?.replaceChildren(
      metric('Total Universes', metrics.total_universes || 0, `${metrics.active_universes || 0} active`),
      metric('Created today', metrics.universes_today || 0, `${metrics.universes_this_month || 0} this month`),
      metric('Total views', metrics.total_views || 0, `${metrics.ad_events || 0} ad events`),
      metric('Open reports', metrics.open_reports || 0, formatBytes(metrics.storage_bytes || 0) + ' stored')
    );

    const usage = $('birthdayTemplateUsage');
    usage?.replaceChildren();
    Object.entries(data.top_templates || {}).sort((a, b) => b[1] - a[1]).forEach(([name, count]) => {
      const row = element('div', 'birthday-admin-row');
      const main = element('div', 'birthday-admin-row-main');
      main.append(element('strong', '', name), element('span', '', `${count} Universes`));
      row.append(main);
      usage.append(row);
    });
    if (usage && !usage.children.length) usage.append(element('div', 'birthday-admin-empty', 'No Universes generated yet.'));

    const platform = $('birthdayPlatformStatus');
    platform?.replaceChildren(
      metric('Feature state', data.settings?.enabled ? 'Enabled' : 'Paused', `Default language: ${data.settings?.default_locale || 'en'}`),
      metric('Retention', `${data.settings?.retention_days || 90} days`, `${data.settings?.generation_per_hour || 5} creations/IP/hour`)
    );
    renderTemplates(data.templates || []);
    renderMusic(data.music || []);
    fillSettings(data.settings || {});
  }

  function renderTemplates(items) {
    const target = $('birthdayTemplateSettings');
    if (!target) return;
    target.replaceChildren();
    items.forEach(item => {
      const form = element('form', 'birthday-template-admin');
      form.dataset.templateId = item.id;
      const title = element('h4', '', item.name || item.id);
      const name = element('input', 'input'); name.name = 'name'; name.maxLength = 50; name.required = true; name.value = item.name || '';
      const description = element('textarea', 'input'); description.name = 'description'; description.maxLength = 180; description.required = true; description.rows = 3; description.value = item.description || '';
      const colors = element('div', 'birthday-template-colors');
      const accentLabel = element('label', '', 'Accent'); const accent = element('input', 'input'); accent.name = 'accent'; accent.type = 'color'; accent.value = item.configuration?.accent || '#63e6be'; accentLabel.append(accent);
      const secondaryLabel = element('label', '', 'Secondary'); const secondary = element('input', 'input'); secondary.name = 'secondary'; secondary.type = 'color'; secondary.value = item.configuration?.secondary || '#f6c86b'; secondaryLabel.append(secondary);
      colors.append(accentLabel, secondaryLabel);
      const activeLabel = element('label', 'birthday-admin-check'); const active = element('input'); active.name = 'active'; active.type = 'checkbox'; active.checked = item.active !== false; activeLabel.append(active, element('span', '', 'Available to creators'));
      const save = element('button', 'btn brand', 'Save template'); save.type = 'submit';
      form.append(title, name, description, colors, activeLabel, save);
      target.append(form);
    });
  }

  function renderMusic(items) {
    const target = $('birthdayMusicList');
    if (!target) return;
    target.replaceChildren();
    items.forEach(item => {
      const row = element('div', 'birthday-admin-row');
      const main = element('div', 'birthday-admin-row-main');
      main.append(element('strong', '', item.name || 'Untitled track'), element('span', '', item.id || ''), element('small', '', `${item.duration || 0}s · ${formatBytes(item.size_bytes || 0)}`));
      const badge = element('span', `birthday-admin-badge${item.active ? '' : ' expired'}`, item.active ? 'ACTIVE' : 'INACTIVE');
      const actions = element('div', 'birthday-admin-actions');
      actions.append(button('Edit', 'music-edit', item.id), button(item.active ? 'Deactivate' : 'Activate', 'music-status', item.id));
      row.append(main, badge, element('span', '', formatDate(item.created_at)), actions);
      target.append(row);
    });
    if (!items.length) target.append(element('div', 'birthday-admin-empty', 'No licensed music has been uploaded.'));
  }

  function fillSettings(settings) {
    const form = $('birthdaySettingsForm');
    if (!form) return;
    ['retention_days', 'renewal_days', 'generation_per_hour', 'draft_ttl_seconds', 'default_locale'].forEach(name => {
      if (form.elements[name]) form.elements[name].value = settings[name] ?? '';
    });
    form.elements.enabled.checked = settings.enabled !== false;
    form.elements.allow_public_indexing.checked = settings.allow_public_indexing !== false;
  }

  async function loadUniverses() {
    const target = $('birthdayUniverseList');
    if (target) target.replaceChildren(element('div', 'birthday-admin-empty', 'Loading Universes...'));
    const data = await request('universes', { params: { page: adminState.universePage, limit: 20, search: $('birthdayUniverseSearch')?.value || '', status: $('birthdayUniverseStatus')?.value || 'ALL' } });
    adminState.universes = data.items || [];
    adminState.universeTotal = Number(data.total || 0);
    adminState.universeHasMore = Boolean(data.has_more);
    renderUniverses();
  }

  function renderUniverses() {
    const target = $('birthdayUniverseList');
    if (!target) return;
    target.replaceChildren();
    adminState.universes.forEach(item => {
      const row = element('div', 'birthday-admin-row');
      const main = element('div', 'birthday-admin-row-main');
      main.append(element('strong', '', item.name || 'Unnamed Universe'), element('span', '', `${item.slug} · ${item.star_id}`), element('small', '', `Created ${formatDate(item.created_at)} · Expires ${formatDate(item.expires_at)}`));
      const badge = element('span', `birthday-admin-badge ${String(item.status || '').toLowerCase()}`, item.status || 'UNKNOWN');
      const stats = element('span', '', `${item.view_count || 0} views`);
      const actions = element('div', 'birthday-admin-actions');
      const open = element('a', 'btn ghost', 'Open'); open.href = `https://zsky24.com/u/${encodeURIComponent(item.slug)}`; open.target = '_blank'; open.rel = 'noopener';
      actions.append(open);
      if (item.status === 'ACTIVE') actions.append(button('Block', 'universe-status', `${item.id}|BLOCKED`, 'btn danger'));
      else if (item.status === 'BLOCKED') actions.append(button('Restore', 'universe-status', `${item.id}|ACTIVE`, 'btn brand'));
      if (!['DELETED', 'EXPIRED'].includes(item.status)) actions.append(button('Delete', 'universe-status', `${item.id}|DELETED`, 'btn danger'));
      row.append(main, badge, stats, actions);
      target.append(row);
    });
    if (!adminState.universes.length) target.append(element('div', 'birthday-admin-empty', 'No matching Universes.'));
    if ($('birthdayUniversePage')) $('birthdayUniversePage').textContent = `Page ${adminState.universePage} · ${adminState.universeTotal} records`;
    if ($('birthdayUniversePrevious')) $('birthdayUniversePrevious').disabled = adminState.universePage <= 1;
    if ($('birthdayUniverseNext')) $('birthdayUniverseNext').disabled = !adminState.universeHasMore;
  }

  async function loadReports() {
    const data = await request('reports');
    adminState.reports = data.items || [];
    renderReports();
  }

  function renderReports() {
    const target = $('birthdayReportList');
    if (!target) return;
    target.replaceChildren();
    adminState.reports.forEach(item => {
      const row = element('div', 'birthday-admin-row');
      const main = element('div', 'birthday-admin-row-main');
      main.append(element('strong', '', item.reason || 'Report'), element('span', '', item.slug || item.universe_id || ''), element('small', '', item.details || 'No details provided.'));
      const status = String(item.status || 'OPEN').toUpperCase();
      const badge = element('span', `birthday-admin-badge${status === 'OPEN' ? ' expired' : ''}`, status);
      const actions = element('div', 'birthday-admin-actions');
      if (status === 'OPEN') actions.append(button('Resolve', 'report-resolve', item.id, 'btn brand'));
      row.append(main, badge, element('span', '', formatDate(item.created_at)), actions);
      target.append(row);
    });
    if (!adminState.reports.length) target.append(element('div', 'birthday-admin-empty', 'No reports have been submitted.'));
  }

  async function load(force = false) {
    ensureUi();
    if (adminState.loading) return;
    if (adminState.loaded && !force) { setTab(adminState.tab); return; }
    adminState.loading = true;
    try {
      adminState.summary = await request('summary');
      adminState.loaded = true;
      renderSummary();
      if (adminState.tab === 'UNIVERSES') await loadUniverses();
      if (adminState.tab === 'REPORTS') await loadReports();
      setTab(adminState.tab);
    } finally {
      adminState.loading = false;
    }
  }

  document.addEventListener('click', event => {
    const tab = event.target.closest('[data-birthday-tab]');
    if (tab) { setTab(tab.dataset.birthdayTab); return; }
    if (event.target.closest('#birthdayAdminRefresh')) { void load(true).catch(error => notice(error.message, 'error')); return; }
    if (event.target.closest('#birthdayUniverseSearchButton')) { adminState.universePage = 1; void loadUniverses().catch(error => notice(error.message, 'error')); return; }
    if (event.target.closest('#birthdayUniversePrevious')) { adminState.universePage = Math.max(1, adminState.universePage - 1); void loadUniverses().catch(error => notice(error.message, 'error')); return; }
    if (event.target.closest('#birthdayUniverseNext')) { adminState.universePage += 1; void loadUniverses().catch(error => notice(error.message, 'error')); return; }
    const action = event.target.closest('[data-birthday-action]');
    if (!action) return;
    const value = String(action.dataset.value || '');
    if (action.dataset.birthdayAction === 'music-status') {
      const item = adminState.summary?.music?.find(row => row.id === value);
      void request('music_status', { method: 'POST', body: { id: value, active: !item?.active } }).then(data => {
        adminState.summary.music = data.music || [];
        renderMusic(adminState.summary.music);
        notice('Music status updated.');
      }).catch(error => notice(error.message, 'error'));
    }
    if (action.dataset.birthdayAction === 'music-edit') {
      const item = adminState.summary?.music?.find(row => row.id === value);
      const dialog = $('birthdayMusicEditDialog');
      const form = $('birthdayMusicEditForm');
      if (!item || !dialog || !form) return;
      form.elements.id.value = item.id;
      form.elements.name.value = item.name || '';
      form.elements.duration.value = item.duration || 0;
      dialog.showModal();
    }
    if (action.dataset.birthdayAction === 'universe-status') {
      const [id, status] = value.split('|');
      if (status === 'DELETED' && !window.confirm('Delete this Birthday Universe from public access?')) return;
      void request('universe_status', { method: 'POST', body: { id, status } }).then(() => loadUniverses()).then(() => notice('Universe status updated.')).catch(error => notice(error.message, 'error'));
    }
    if (action.dataset.birthdayAction === 'report-resolve') {
      void request('report_resolve', { method: 'POST', body: { id: value } }).then(() => loadReports()).then(() => notice('Report resolved.')).catch(error => notice(error.message, 'error'));
    }
  });

  document.addEventListener('submit', event => {
    const form = event.target;
    if (form.matches('[data-template-id]')) {
      event.preventDefault();
      const fields = new FormData(form);
      void request('template_save', { method: 'POST', body: { id: form.dataset.templateId, name: fields.get('name'), description: fields.get('description'), accent: fields.get('accent'), secondary: fields.get('secondary'), active: fields.get('active') === 'on' } }).then(data => {
        adminState.summary.templates = data.templates || [];
        renderTemplates(adminState.summary.templates);
        notice('Template settings saved.');
      }).catch(error => notice(error.message, 'error'));
      return;
    }
    if (form.id === 'birthdaySettingsForm') {
      event.preventDefault();
      const fields = new FormData(form);
      const payload = Object.fromEntries(fields.entries());
      payload.enabled = fields.get('enabled') === 'on';
      payload.allow_public_indexing = fields.get('allow_public_indexing') === 'on';
      void request('settings_save', { method: 'POST', body: payload }).then(data => {
        adminState.summary.settings = data.settings || {};
        fillSettings(adminState.summary.settings);
        renderSummary();
        notice('Birthday Universe settings saved.');
      }).catch(error => notice(error.message, 'error'));
      return;
    }
    if (form.id === 'birthdayMusicForm') {
      event.preventDefault();
      const upload = new FormData(form);
      void request('music_upload', { method: 'POST', form: upload }).then(data => {
        adminState.summary.music = [...(adminState.summary.music || []), data.music];
        form.reset();
        renderMusic(adminState.summary.music);
        notice('Licensed music uploaded.');
      }).catch(error => notice(error.message, 'error'));
    }
    if (form.id === 'birthdayMusicEditForm') {
      event.preventDefault();
      const fields = new FormData(form);
      void request('music_edit', { method: 'POST', body: { id: fields.get('id'), name: fields.get('name'), duration: fields.get('duration') } }).then(data => {
        adminState.summary.music = data.music || [];
        $('birthdayMusicEditDialog')?.close();
        renderMusic(adminState.summary.music);
        notice('Music details updated.');
      }).catch(error => notice(error.message, 'error'));
    }
  });

  document.addEventListener('click', event => {
    if (event.target.closest('[data-birthday-dialog-close]')) $('birthdayMusicEditDialog')?.close();
  });

  ensureUi();
  $('birthdayUniverseSearch')?.addEventListener('keydown', event => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    adminState.universePage = 1;
    void loadUniverses().catch(error => notice(error.message, 'error'));
  });

  window.loadBirthdayUniverseAdmin = load;
  window.dispatchEvent(new CustomEvent('birthday-universe:admin-ready'));
})();
