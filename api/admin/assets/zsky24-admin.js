(() => {
  'use strict';

  const ENDPOINT = '/api/admin/zsky24_creator_admin.php';
  const BATCH_LIMIT = 5;
  const zskyState = {
    loaded: false,
    loading: false,
    loadPromise: null,
    mode: 'CREATORS',
    activeStatus: 'ACTIVE',
    activeCreators: [],
    blockedCreators: [],
    selected: new Set(),
    weeklyPeriodsLoaded: false,
    weeklyPeriods: [],
    defaultWeeklyPeriod: null,
    selectedPeriodId: '',
    weeklyReview: null,
    weeklyLoading: false,
  };

  const $ = id => document.getElementById(id);
  const safe = value => esc(String(value ?? ''));
  const timestamp = value => value ? fmtTs(Number(value)) : '-';

  function currentCsrf(){
    try { return String(state?.csrf || ''); }
    catch (_error) { return ''; }
  }

  function readJson(text){
    try { return JSON.parse(text); }
    catch (_error) {
      throw Object.assign(new Error('The server returned an invalid response.'), {code:'MALFORMED_RESPONSE'});
    }
  }

  async function request(action, {method='GET', params={}, body=null, busy=true, busyText='Loading Z Sky data…'} = {}){
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
        const error = Object.assign(new Error(json.message || 'Z Sky request failed.'), {
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
            <p>Manage creator access, review verified weekly engagement and preview payout eligibility. This screen never credits a wallet.</p>
          </div>
          <button class="btn blue" id="zsky24RefreshBtn" type="button">Refresh</button>
        </div>

        <div class="zsky-admin-tabs zsky-primary-tabs" role="tablist" aria-label="Z Sky creator administration">
          <button class="zsky-admin-tab active" type="button" role="tab" aria-selected="true" data-zsky-mode="CREATORS">Creator accounts</button>
          <button class="zsky-admin-tab" type="button" role="tab" aria-selected="false" data-zsky-mode="WEEKLY">Weekly reviews</button>
        </div>

        <section id="zskyCreatorAdminView">
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
        </section>

        <section id="zskyWeeklyReviewView" hidden>
          <div class="card zsky-weekly-toolbar">
            <div><span class="zsky-admin-kicker">WEEKLY PERFORMANCE</span><h3>Verified creator engagement</h3><p>Completed UTC weeks only. No revenue, balance or payout amount is calculated here.</p></div>
            <div class="zsky-weekly-controls">
              <label for="zskyWeeklyPeriodSelect">Review period</label>
              <select class="input" id="zskyWeeklyPeriodSelect"></select>
              <button class="btn brand" id="zskyGenerateWeeklyReview" type="button">Generate review</button>
            </div>
          </div>
          <div id="zskyWeeklyMetrics" class="zsky-admin-metrics" aria-label="Weekly review summary"></div>
          <div class="card zsky-admin-panel">
            <div class="panel-head"><div><h3 id="zskyWeeklyTitle">Weekly creator review</h3><p id="zskyWeeklySubtitle">Generate the completed week to create an auditable snapshot.</p></div></div>
            <div id="zskyWeeklyList" class="zsky-admin-list" aria-live="polite"><div class="empty">Select a completed week.</div></div>
          </div>
        </section>
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

  function empty(message){ return `<div class="empty">${safe(message)}</div>`; }

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
        <div class="zsky-creator-select">${active ? `<input type="checkbox" aria-label="Select ${safe(row.name || 'creator')}" data-zsky-select-creator="${safe(uid)}" ${selected ? 'checked' : ''}>` : '<span class="zsky-blocked-mark" aria-hidden="true">×</span>'}</div>
        <div class="zsky-admin-row-main"><strong>${safe(row.name || 'Z-Pay creator')}</strong><span>${safe(account)} • ${safe(uid)}</span><small>${secondary}</small></div>
        <div class="zsky-admin-cell"><small>Wallet snapshot</small><strong>${safe(currency)}</strong></div>
        <div class="zsky-admin-cell"><small>Creator status</small><strong class="${active ? 'zsky-status-active' : 'zsky-flag'}">${safe(status)}</strong></div>
        <div class="zsky-admin-actions">${active
          ? `<button class="btn danger" type="button" data-zsky-block-creator="${safe(uid)}" data-creator-name="${safe(row.name || 'Creator')}">Block</button>`
          : `<button class="btn brand" type="button" data-zsky-unblock-creator="${safe(uid)}" data-creator-name="${safe(row.name || 'Creator')}">Unblock</button>`}</div>
      </article>`;
  }

  function renderCreators(){
    ensureUi();
    const status = zskyState.activeStatus;
    const rows = creatorsForStatus(status);
    if ($('zskyCreatorListTitle')) $('zskyCreatorListTitle').textContent = status === 'ACTIVE' ? 'Active creators' : 'Blocked creators';
    if ($('zskyCreatorListSubtitle')) $('zskyCreatorListSubtitle').textContent = status === 'ACTIVE'
      ? 'Select up to five creators for a payout eligibility preview.'
      : 'Blocked creators cannot publish, manage posts or receive payout.';
    if ($('zskyCreatorList')) $('zskyCreatorList').innerHTML = rows.length
      ? rows.map(creatorRow).join('')
      : empty(status === 'ACTIVE' ? 'No active creator is registered yet.' : 'No creator is currently blocked.');
    updateMetrics();
  }

  function setMode(mode){
    zskyState.mode = mode === 'WEEKLY' ? 'WEEKLY' : 'CREATORS';
    document.querySelectorAll('[data-zsky-mode]').forEach(node => {
      const active = node.dataset.zskyMode === zskyState.mode;
      node.classList.toggle('active', active);
      node.setAttribute('aria-selected', String(active));
    });
    if ($('zskyCreatorAdminView')) $('zskyCreatorAdminView').hidden = zskyState.mode !== 'CREATORS';
    if ($('zskyWeeklyReviewView')) $('zskyWeeklyReviewView').hidden = zskyState.mode !== 'WEEKLY';
    if (zskyState.mode === 'WEEKLY') {
      loadWeekly().catch(error => showToast(error.message || 'Weekly reviews could not be loaded.', 'error'));
    } else {
      renderCreators();
    }
  }

  async function load(force=false){
    ensureUi();
    if (zskyState.loaded && !force) { renderCreators(); return; }
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
      renderCreators();
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
      renderCreators();
      return;
    }
    if (checked) zskyState.selected.add(uid); else zskyState.selected.delete(uid);
    renderCreators();
  }

  async function updateCreatorStatus(uid, status, reason=''){
    await request('creator_status', {
      method:'POST', body:{creator_uid:uid, status, reason},
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
      if (!reason) return showToast('A block reason is required.', 'error');
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
      <p class="zsky-admin-note">A later payout phase may process no more than five creators per execution batch. This preview does not calculate or transfer money.</p>`;
  }

  async function previewPayout(){
    if (!zskyState.selected.size) return;
    try {
      const data = await request('payout_preflight', {
        method:'POST', body:{creator_uids:[...zskyState.selected]},
        busyText:'Validating creator payout batch…',
      });
      openDrawer('Payout batch preview', `${data.count || zskyState.selected.size} creators passed live eligibility checks`, preflightMarkup(data), '<button class="btn ghost" onclick="closeDrawer()">Close</button>');
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

  function periodLabel(period){
    const start = String(period?.period_start_date || period?.period_id || '');
    const end = String(period?.period_end_date || '');
    return end ? `${start} to ${end}` : start;
  }

  function renderPeriodOptions(){
    const select = $('zskyWeeklyPeriodSelect');
    if (!select) return;
    const map = new Map();
    if (zskyState.defaultWeeklyPeriod?.period_id) map.set(zskyState.defaultWeeklyPeriod.period_id, zskyState.defaultWeeklyPeriod);
    zskyState.weeklyPeriods.forEach(period => {
      if (period?.period_id) map.set(period.period_id, period);
    });
    select.innerHTML = [...map.values()].map(period => `<option value="${safe(period.period_id)}">${safe(periodLabel(period))}${period.status ? ` • ${safe(period.status)}` : ' • Not generated'}</option>`).join('');
    if (!zskyState.selectedPeriodId) zskyState.selectedPeriodId = zskyState.defaultWeeklyPeriod?.period_id || [...map.keys()][0] || '';
    select.value = zskyState.selectedPeriodId;
  }

  function weeklyStatusClass(status){
    status = String(status || 'UNDER_REVIEW').toUpperCase();
    return status === 'APPROVED' ? 'zsky-status-active' : status === 'HELD' ? 'zsky-flag' : 'zsky-status-review';
  }

  function weeklyRow(row){
    const status = String(row.review_status || 'UNDER_REVIEW').toUpperCase();
    const creatorStatus = String(row.creator_status || 'ACTIVE').toUpperCase();
    const canApprove = creatorStatus === 'ACTIVE' && status !== 'APPROVED';
    return `
      <article class="zsky-weekly-row">
        <div class="zsky-admin-row-main"><strong>${safe(row.creator_name || 'Z-Pay creator')}</strong><span>${safe(row.creator_uid || '')}</span><small>${safe(row.review_reason || `${row.post_count || 0} posts in period`)}</small></div>
        <div class="zsky-weekly-stat"><small>Raw / eligible</small><strong>${safe(row.raw_views || 0)} / ${safe(row.eligible_views || 0)}</strong></div>
        <div class="zsky-weekly-stat"><small>Invalid / spam</small><strong>${safe(row.invalid_views || 0)} / ${safe(row.spam_views || 0)}</strong></div>
        <div class="zsky-weekly-stat"><small>Creator / self excluded</small><strong>${safe(row.creator_views_excluded || 0)} / ${safe(row.self_views_excluded || 0)}</strong></div>
        <div class="zsky-weekly-stat"><small>Traffic share</small><strong>${safe(Number(row.traffic_share_percent || 0).toFixed(4))}%</strong></div>
        <div class="zsky-weekly-status"><small>Review status</small><strong class="${weeklyStatusClass(status)}">${safe(status)}</strong></div>
        <div class="zsky-admin-actions">
          ${canApprove ? `<button class="btn brand" type="button" data-zsky-weekly-approve="${safe(row.creator_uid)}">Approve</button>` : ''}
          ${status !== 'HELD' ? `<button class="btn danger" type="button" data-zsky-weekly-hold="${safe(row.creator_uid)}" data-creator-name="${safe(row.creator_name || 'Creator')}">Hold</button>` : ''}
        </div>
      </article>`;
  }

  function renderWeekly(){
    renderPeriodOptions();
    const data = zskyState.weeklyReview;
    const period = data?.period || zskyState.defaultWeeklyPeriod || {};
    const rows = Array.isArray(data?.items) ? data.items : [];
    if ($('zskyWeeklyTitle')) $('zskyWeeklyTitle').textContent = period.period_id ? `Weekly review • ${periodLabel(period)}` : 'Weekly creator review';
    if ($('zskyWeeklySubtitle')) $('zskyWeeklySubtitle').textContent = data
      ? 'Eligible traffic share is calculated only from verified guest engagement.'
      : 'This completed week has not been generated yet.';
    if ($('zskyWeeklyMetrics')) $('zskyWeeklyMetrics').innerHTML = `
      <div class="zsky-admin-metric"><span>Raw views</span><strong>${safe(period.total_raw_views || 0)}</strong></div>
      <div class="zsky-admin-metric"><span>Eligible views</span><strong>${safe(period.total_eligible_views || 0)}</strong></div>
      <div class="zsky-admin-metric warning"><span>Under review</span><strong>${safe(period.under_review_count || 0)}</strong></div>
      <div class="zsky-admin-metric danger"><span>Held</span><strong>${safe(period.held_count || 0)}</strong></div>`;
    if ($('zskyWeeklyList')) $('zskyWeeklyList').innerHTML = rows.length
      ? rows.map(weeklyRow).join('')
      : empty(data ? 'No registered creators were found for this period.' : 'Generate this completed week to create review snapshots.');
  }

  async function loadWeeklyPeriods(force=false){
    if (zskyState.weeklyPeriodsLoaded && !force) return;
    const data = await request('weekly_periods', {params:{limit:12}, busy:false});
    zskyState.defaultWeeklyPeriod = data.default_period || null;
    zskyState.weeklyPeriods = Array.isArray(data.items) ? data.items : [];
    if (!zskyState.selectedPeriodId) zskyState.selectedPeriodId = zskyState.defaultWeeklyPeriod?.period_id || '';
    zskyState.weeklyPeriodsLoaded = true;
    renderPeriodOptions();
  }

  async function loadWeeklyReview(periodId){
    if (!periodId) { zskyState.weeklyReview = null; renderWeekly(); return; }
    try {
      const data = await request('weekly_review', {params:{period_id:periodId}, busy:false});
      zskyState.weeklyReview = data;
    } catch (error) {
      if (error.status === 404 || error.code === 'ZNEWS_WEEKLY_REVIEW_NOT_FOUND') zskyState.weeklyReview = null;
      else throw error;
    }
    renderWeekly();
  }

  async function loadWeekly(force=false){
    if (zskyState.weeklyLoading) return;
    zskyState.weeklyLoading = true;
    try {
      await loadWeeklyPeriods(force);
      await loadWeeklyReview(zskyState.selectedPeriodId);
    } finally {
      zskyState.weeklyLoading = false;
    }
  }

  async function generateWeekly(){
    const periodId = $('zskyWeeklyPeriodSelect')?.value || zskyState.selectedPeriodId;
    if (!periodId) return showToast('Select a completed week.', 'error');
    try {
      const data = await request('weekly_generate', {
        method:'POST', body:{period_id:periodId}, busyText:'Generating verified weekly reviews…',
      });
      zskyState.weeklyReview = data;
      zskyState.selectedPeriodId = periodId;
      zskyState.weeklyPeriodsLoaded = false;
      await loadWeeklyPeriods(true);
      renderWeekly();
      showToast('Weekly creator review generated.', 'success');
    } catch (error) {
      showToast(error.message || 'Weekly review generation failed.', 'error');
    }
  }

  async function updateWeeklyStatus(creatorUid, status, reason=''){
    const periodId = zskyState.selectedPeriodId;
    if (!periodId) return;
    await request('weekly_status', {
      method:'POST', body:{period_id:periodId, creator_uid:creatorUid, status, reason},
      busyText: status === 'HELD' ? 'Holding creator review…' : 'Approving creator review…',
    });
    await loadWeeklyReview(periodId);
    showToast(status === 'HELD' ? 'Creator review held.' : 'Creator review approved.', 'success');
  }

  function holdWeeklyReview(uid, name){
    openModal(
      'Hold weekly creator review',
      `<p>Hold <strong>${safe(name)}</strong> for manual investigation?</p><label for="zskyWeeklyHoldReason">Reason</label><textarea id="zskyWeeklyHoldReason" class="input zsky-admin-reason" maxlength="300" placeholder="Required reason"></textarea>`,
      '<button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn danger" id="zskyConfirmWeeklyHold">Hold review</button>'
    );
    $('zskyConfirmWeeklyHold')?.addEventListener('click', async () => {
      const reason = $('zskyWeeklyHoldReason')?.value.trim() || '';
      if (!reason) return showToast('A hold reason is required.', 'error');
      closeModal();
      try { await updateWeeklyStatus(uid, 'HELD', reason); }
      catch (error) { showToast(error.message || 'Review could not be held.', 'error'); }
    }, {once:true});
  }

  document.addEventListener('click', event => {
    const mode = event.target.closest('[data-zsky-mode]');
    if (mode) { setMode(mode.dataset.zskyMode); return; }

    const tab = event.target.closest('[data-zsky-creator-tab]');
    if (tab) {
      zskyState.activeStatus = tab.dataset.zskyCreatorTab === 'BLOCKED' ? 'BLOCKED' : 'ACTIVE';
      document.querySelectorAll('[data-zsky-creator-tab]').forEach(node => {
        const active = node === tab;
        node.classList.toggle('active', active);
        node.setAttribute('aria-selected', String(active));
      });
      renderCreators();
      return;
    }

    const block = event.target.closest('[data-zsky-block-creator]');
    if (block) { blockCreator(block.dataset.zskyBlockCreator, block.dataset.creatorName || 'Creator'); return; }
    const unblock = event.target.closest('[data-zsky-unblock-creator]');
    if (unblock) { unblockCreator(unblock.dataset.zskyUnblockCreator, unblock.dataset.creatorName || 'Creator'); return; }
    const approve = event.target.closest('[data-zsky-weekly-approve]');
    if (approve) {
      updateWeeklyStatus(approve.dataset.zskyWeeklyApprove, 'APPROVED').catch(error => showToast(error.message || 'Review could not be approved.', 'error'));
      return;
    }
    const hold = event.target.closest('[data-zsky-weekly-hold]');
    if (hold) { holdWeeklyReview(hold.dataset.zskyWeeklyHold, hold.dataset.creatorName || 'Creator'); return; }

    if (event.target.closest('#zsky24RefreshBtn')) {
      if (zskyState.mode === 'WEEKLY') loadWeekly(true).catch(error => showToast(error.message || 'Weekly refresh failed.', 'error'));
      else load(true).catch(error => showToast(error.message || 'Creator refresh failed.', 'error'));
      return;
    }
    if (event.target.closest('#zskyClearCreatorSelection')) {
      zskyState.selected.clear(); renderCreators(); return;
    }
    if (event.target.closest('#zskyPayoutPreflightBtn')) { previewPayout(); return; }
    if (event.target.closest('#zskyGenerateWeeklyReview')) generateWeekly();
  });

  document.addEventListener('change', event => {
    const checkbox = event.target.closest('[data-zsky-select-creator]');
    if (checkbox) { selectCreator(checkbox.dataset.zskySelectCreator, checkbox.checked); return; }
    if (event.target.closest('#zskyWeeklyPeriodSelect')) {
      zskyState.selectedPeriodId = event.target.value;
      loadWeeklyReview(zskyState.selectedPeriodId).catch(error => showToast(error.message || 'Weekly review could not be loaded.', 'error'));
    }
  });

  window.loadZSky24Admin = load;
  window.dispatchEvent(new CustomEvent('zsky24:admin-ready'));
  ensureUi();
  if ($('zsky24Section')?.classList.contains('active')) {
    load().catch(error => showToast(error.message || 'Creator data could not be loaded.', 'error'));
  }
})();
