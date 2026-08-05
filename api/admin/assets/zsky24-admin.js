(() => {
  'use strict';

  const ENDPOINT = '/api/admin/zsky24_creator_admin.php';
  const BATCH_LIMIT = 5;
  const zskyState = {
    loaded: false,
    loading: false,
    loadPromise: null,
    activeStatus: 'ACTIVE',
    activeCreators: [],
    blockedCreators: [],
    selected: new Set(),
  };

  const $ = id => document.getElementById(id);
  const safe = value => esc(String(value ?? ''));
  const timestamp = value => value ? fmtTs(Number(value)) : '-';

  function currentCsrf(){
    try {
      return String(state?.csrf || '');
    } catch (_error) {
      return '';
    }
  }

  function readJson(text){
    try {
      return JSON.parse(text);
    } catch (_error) {
      throw Object.assign(new Error('The server returned an invalid response.'), {code:'MALFORMED_RESPONSE'});
    }
  }

  async function request(action, {method='GET', params={}, body=null, busy=true, busyText='Loading Z Sky creators…'} = {}){
    if (busy) setBusy(true, busyText);
    try {
      const url = new URL(ENDPOINT, window.location.origin);
      url.searchParams.set('action', action);
      Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && String(value) !== '') url.searchParams.set(key, String(value));
      });
      const headers = {'Accept':'application/json', 'Cache-Control':'no-cache'};
      const options = {method, credentials:'same-origin', headers};
      if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        const csrf = currentCsrf();
        if (csrf) headers['X-CSRF-TOKEN'] = csrf;
        options.body = JSON.stringify(body || {});
      }
      const response = await fetch(url.toString(), options);
      const json = readJson(await response.text());
      if (!response.ok || !json.ok) {
        const error = Object.assign(new Error(json.message || 'Z Sky creator request failed.'), {
          code: json.code || 'REQUEST_FAILED',
          status: response.status,
          data: json.data || {},
        });
        if (response.status === 401 || error.code === 'SESSION_EXPIRED') showLogin();
        throw error;
      }
      return json.data || {};
    } finally {
      if (busy) setBusy(false);
    }
  }

  function ensureUi(){
    const section = $('zsky24Section');
    if (!section || section.dataset.creatorAdminReady === 'true') return;
    section.dataset.creatorAdminReady = 'true';
    section.innerHTML = `
      <div class="zsky-admin-shell">
        <div class="zsky-admin-hero">
          <div>
            <span class="zsky-admin-kicker">Z SKY 24 • CREATOR CONTROL</span>
            <h3>Creator management</h3>
            <p>Manage creator access and validate monthly payout batches. This screen never credits a wallet.</p>
          </div>
          <button class="btn blue" id="zsky24RefreshBtn" type="button">Refresh</button>
        </div>

        <div class="zsky-admin-metrics" aria-label="Creator summary">
          <div class="zsky-admin-metric"><span>Active creators</span><strong id="zskyActiveCreatorCount">0</strong></div>
          <div class="zsky-admin-metric danger"><span>Blocked creators</span><strong id="zskyBlockedCreatorCount">0</strong></div>
          <div class="zsky-admin-metric warning"><span>Selected for preview</span><strong id="zskySelectedCreatorCount">0</strong></div>
          <div class="zsky-admin-metric"><span>Maximum batch</span><strong>${BATCH_LIMIT}</strong></div>
        </div>

        <div class="zsky-admin-tabs" role="tablist" aria-label="Creator status">
          <button class="zsky-admin-tab active" type="button" role="tab" aria-selected="true" data-zsky-creator-tab="ACTIVE">Active creators</button>
          <button class="zsky-admin-tab" type="button" role="tab" aria-selected="false" data-zsky-creator-tab="BLOCKED">Blocked creators</button>
        </div>

        <div class="card zsky-admin-panel">
          <div class="panel-head">
            <div><h3 id="zskyCreatorListTitle">Active creators</h3><p id="zskyCreatorListSubtitle">Select up to five creators for a payout eligibility preview.</p></div>
          </div>
          <div id="zskyCreatorList" class="zsky-admin-list" aria-live="polite"><div class="empty">Loading creators…</div></div>
        </div>

        <div class="zsky-payout-dock" id="zskyPayoutDock">
          <div><strong><span id="zskyPayoutSelectedText">0</span> of ${BATCH_LIMIT} selected</strong><span>Preview checks creator status, live Z-Pay account status and BDT/MYR wallet currency.</span></div>
          <div class="zsky-admin-actions"><button class="btn ghost" id="zskyClearCreatorSelection" type="button" disabled>Clear</button><button class="btn brand" id="zskyPayoutPreflightBtn" type="button" disabled>Preview payout batch</button></div>
        </div>
      </div>`;
  }

  function creatorsForStatus(status){
    return status === 'BLOCKED' ? zskyState.blockedCreators : zskyState.activeCreators;
  }

  function updateMetrics(){
    if ($('zskyActiveCreatorCount')) $('zskyActiveCreatorCount').textContent = String(zskyState.activeCreators.length);
    if ($('zskyBlockedCreatorCount')) $('zskyBlockedCreatorCount').textContent = String(zskyState.blockedCreators.length);
    if ($('zskySelectedCreatorCount')) $('zskySelectedCreatorCount').textContent = String(zskyState.selected.size);
    if ($('zskyPayoutSelectedText')) $('zskyPayoutSelectedText').textContent = String(zskyState.selected.size);
    const hasSelection = zskyState.selected.size > 0;
    if ($('zskyPayoutPreflightBtn')) $('zskyPayoutPreflightBtn').disabled = !hasSelection;
    if ($('zskyClearCreatorSelection')) $('zskyClearCreatorSelection').disabled = !hasSelection;
    if ($('zskyPayoutDock')) $('zskyPayoutDock').hidden = zskyState.activeStatus !== 'ACTIVE';
  }

  function empty(message){
    return `<div class="empty">${safe(message)}</div>`;
  }

  function creatorRow(row){
    const uid = String(row.creator_uid || row.zpay_uid || '').trim();
    const status = String(row.status || 'ACTIVE').toUpperCase();
    const active = status === 'ACTIVE';
    const selected = zskyState.selected.has(uid);
    const currency = String(row.wallet_currency_snapshot || '-').toUpperCase();
    const account = row.zpay_account_masked || 'Account unavailable';
    const secondary = active
      ? `Last active: ${timestamp(row.last_seen_at)}`
      : `Blocked: ${timestamp(row.blocked_at)}${row.block_reason ? ` • ${safe(row.block_reason)}` : ''}`;
    return `
      <article class="zsky-admin-row zsky-creator-row ${selected ? 'selected' : ''}" data-creator-uid="${safe(uid)}">
        <div class="zsky-creator-select">
          ${active ? `<input type="checkbox" aria-label="Select ${safe(row.name || 'creator')}" data-zsky-select-creator="${safe(uid)}" ${selected ? 'checked' : ''}>` : '<span class="zsky-blocked-mark" aria-hidden="true">×</span>'}
        </div>
        <div class="zsky-admin-row-main"><strong>${safe(row.name || 'Z-Pay creator')}</strong><span>${safe(account)} • ${safe(uid)}</span><small>${secondary}</small></div>
        <div class="zsky-admin-cell"><small>Wallet snapshot</small><strong>${safe(currency)}</strong></div>
        <div class="zsky-admin-cell"><small>Creator status</small><strong class="${active ? 'zsky-status-active' : 'zsky-flag'}">${safe(status)}</strong></div>
        <div class="zsky-admin-actions">
          ${active
            ? `<button class="btn danger" type="button" data-zsky-block-creator="${safe(uid)}" data-creator-name="${safe(row.name || 'Creator')}">Block</button>`
            : `<button class="btn brand" type="button" data-zsky-unblock-creator="${safe(uid)}" data-creator-name="${safe(row.name || 'Creator')}">Unblock</button>`}
        </div>
      </article>`;
  }

  function render(){
    ensureUi();
    const status = zskyState.activeStatus;
    const rows = creatorsForStatus(status);
    const title = $('zskyCreatorListTitle');
    const subtitle = $('zskyCreatorListSubtitle');
    if (title) title.textContent = status === 'ACTIVE' ? 'Active creators' : 'Blocked creators';
    if (subtitle) subtitle.textContent = status === 'ACTIVE'
      ? 'Select up to five creators for a payout eligibility preview.'
      : 'Blocked creators cannot publish, manage posts or receive payout.';
    const list = $('zskyCreatorList');
    if (list) list.innerHTML = rows.length
      ? rows.map(creatorRow).join('')
      : empty(status === 'ACTIVE' ? 'No active creator is registered yet.' : 'No creator is currently blocked.');
    updateMetrics();
  }

  async function load(force=false){
    ensureUi();
    if (zskyState.loaded && !force) { render(); return; }
    if (zskyState.loading && zskyState.loadPromise) return zskyState.loadPromise;
    zskyState.loading = true;
    if ($('zskyCreatorList') && !zskyState.loaded) $('zskyCreatorList').innerHTML = empty('Loading creators…');
    zskyState.loadPromise = (async () => {
      const [active, blocked] = await Promise.all([
        request('creators_list', {params:{status:'ACTIVE', limit:100}, busy:false}),
        request('creators_list', {params:{status:'BLOCKED', limit:100}, busy:false}),
      ]);
      zskyState.activeCreators = Array.isArray(active.items) ? active.items : [];
      zskyState.blockedCreators = Array.isArray(blocked.items) ? blocked.items : [];
      const activeIds = new Set(zskyState.activeCreators.map(row => String(row.creator_uid || '')));
      [...zskyState.selected].forEach(uid => { if (!activeIds.has(uid)) zskyState.selected.delete(uid); });
      zskyState.loaded = true;
      render();
    })().finally(() => {
      zskyState.loading = false;
      zskyState.loadPromise = null;
    });
    return zskyState.loadPromise;
  }

  function selectCreator(uid, checked){
    uid = String(uid || '').trim();
    if (!uid) return;
    if (checked && !zskyState.selected.has(uid) && zskyState.selected.size >= BATCH_LIMIT) {
      showToast(`A payout preview can contain no more than ${BATCH_LIMIT} creators.`, 'error');
      render();
      return;
    }
    if (checked) zskyState.selected.add(uid); else zskyState.selected.delete(uid);
    render();
  }

  async function updateCreatorStatus(uid, status, reason=''){
    await request('creator_status', {
      method:'POST',
      body:{creator_uid:uid, status, reason},
      busyText: status === 'BLOCKED' ? 'Blocking creator…' : 'Activating creator…',
    });
    zskyState.selected.delete(uid);
    await load(true);
    showToast(status === 'BLOCKED' ? 'Creator blocked.' : 'Creator activated.', 'success');
  }

  function blockCreator(uid, name){
    openModal(
      'Block Z Sky creator',
      `<p>Block <strong>${safe(name)}</strong> from creator actions and payout eligibility?</p><label for="zskyCreatorBlockReason">Reason</label><textarea id="zskyCreatorBlockReason" class="input zsky-admin-reason" maxlength="300" placeholder="Required reason"></textarea>`,
      '<button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn danger" id="zskyConfirmCreatorBlock">Block creator</button>'
    );
    $('zskyConfirmCreatorBlock')?.addEventListener('click', async () => {
      const reason = $('zskyCreatorBlockReason')?.value.trim() || '';
      if (!reason) { showToast('A block reason is required.', 'error'); return; }
      closeModal();
      try { await updateCreatorStatus(uid, 'BLOCKED', reason); }
      catch (error) { showToast(error.message || 'Creator could not be blocked.', 'error'); }
    }, {once:true});
  }

  function unblockCreator(uid, name){
    openModal(
      'Activate Z Sky creator',
      `<p>Restore creator access for <strong>${safe(name)}</strong>? Live Z-Pay account eligibility will still be checked before payout.</p>`,
      '<button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn brand" id="zskyConfirmCreatorUnblock">Activate creator</button>'
    );
    $('zskyConfirmCreatorUnblock')?.addEventListener('click', async () => {
      closeModal();
      try { await updateCreatorStatus(uid, 'ACTIVE'); }
      catch (error) { showToast(error.message || 'Creator could not be activated.', 'error'); }
    }, {once:true});
  }

  function preflightMarkup(data){
    const creators = Array.isArray(data.creators) ? data.creators : [];
    const counts = data.currency_counts || {};
    return `
      <div class="zsky-preflight-banner"><strong>Preview only</strong><span>No wallet balance will be changed.</span></div>
      <div class="zsky-admin-detail-grid">
        <div class="zsky-admin-detail"><small>Selected creators</small><strong>${safe(data.count || creators.length)}</strong></div>
        <div class="zsky-admin-detail"><small>Batch limit</small><strong>${safe(data.batch_limit || BATCH_LIMIT)}</strong></div>
        <div class="zsky-admin-detail"><small>BDT wallets</small><strong>${safe(counts.BDT || 0)}</strong></div>
        <div class="zsky-admin-detail"><small>MYR wallets</small><strong>${safe(counts.MYR || 0)}</strong></div>
      </div>
      <div class="zsky-preflight-list">${creators.map(row => `
        <div class="zsky-preflight-row"><div><strong>${safe(row.name || 'Creator')}</strong><span>${safe(row.zpay_account_masked || row.creator_uid || '')}</span></div><div><small>Live account</small><strong>${safe(row.zpay_status || '-')}</strong></div><div><small>Payout currency</small><strong>${safe(row.wallet_currency || '-')}</strong></div></div>`).join('')}</div>
      <p class="zsky-admin-note">The next payout phase will calculate monthly revenue shares and process no more than five creators per execution batch.</p>`;
  }

  async function previewPayout(){
    if (!zskyState.selected.size) return;
    try {
      const data = await request('payout_preflight', {
        method:'POST',
        body:{creator_uids:[...zskyState.selected]},
        busyText:'Validating creator payout batch…',
      });
      openDrawer(
        'Payout batch preview',
        `${data.count || zskyState.selected.size} creators passed live eligibility checks`,
        preflightMarkup(data),
        '<button class="btn ghost" onclick="closeDrawer()">Close</button>'
      );
    } catch (error) {
      const rejected = Array.isArray(error.data?.rejected) ? error.data.rejected : [];
      if (rejected.length) {
        openDrawer(
          'Payout batch blocked',
          'One or more selected creators failed eligibility checks',
          `<div class="zsky-preflight-banner danger"><strong>Preview failed</strong><span>No wallet balance was changed.</span></div><div class="zsky-preflight-list">${rejected.map(row => `<div class="zsky-preflight-row"><div><strong>${safe(row.creator_uid || 'Creator')}</strong><span>${safe(row.message || row.code || 'Not eligible')}</span></div><div><small>Code</small><strong class="zsky-flag">${safe(row.code || '-')}</strong></div></div>`).join('')}</div>`,
          '<button class="btn ghost" onclick="closeDrawer()">Close</button>'
        );
        return;
      }
      showToast(error.message || 'Payout preview failed.', 'error');
    }
  }

  document.addEventListener('click', event => {
    const tab = event.target.closest('[data-zsky-creator-tab]');
    if (tab) {
      zskyState.activeStatus = tab.dataset.zskyCreatorTab === 'BLOCKED' ? 'BLOCKED' : 'ACTIVE';
      document.querySelectorAll('[data-zsky-creator-tab]').forEach(node => {
        const active = node === tab;
        node.classList.toggle('active', active);
        node.setAttribute('aria-selected', String(active));
      });
      render();
      return;
    }

    const block = event.target.closest('[data-zsky-block-creator]');
    if (block) { blockCreator(block.dataset.zskyBlockCreator, block.dataset.creatorName || 'Creator'); return; }
    const unblock = event.target.closest('[data-zsky-unblock-creator]');
    if (unblock) { unblockCreator(unblock.dataset.zskyUnblockCreator, unblock.dataset.creatorName || 'Creator'); return; }
  });

  document.addEventListener('change', event => {
    const checkbox = event.target.closest('[data-zsky-select-creator]');
    if (checkbox) selectCreator(checkbox.dataset.zskySelectCreator, checkbox.checked);
  });

  document.addEventListener('click', event => {
    if (event.target.closest('#zsky24RefreshBtn')) {
      load(true).catch(error => showToast(error.message || 'Creator refresh failed.', 'error'));
      return;
    }
    if (event.target.closest('#zskyClearCreatorSelection')) {
      zskyState.selected.clear();
      render();
      return;
    }
    if (event.target.closest('#zskyPayoutPreflightBtn')) previewPayout();
  });

  window.loadZSky24Admin = load;
  window.dispatchEvent(new CustomEvent('zsky24:admin-ready'));
  ensureUi();
  if ($('zsky24Section')?.classList.contains('active')) {
    load().catch(error => showToast(error.message || 'Creator data could not be loaded.', 'error'));
  }
})();
