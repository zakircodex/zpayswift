(() => {
  'use strict';

  const ENDPOINT = '/api/admin/zsky24_creator_admin.php';
  const BATCH_LIMIT = 5;
  const PAGE_SIZE = 10;
  const zskyState = {
    loaded: false,
    loading: false,
    loadPromise: null,
    mode: 'OVERVIEW',
    moderationType: 'POSTS',
    postRows: [],
    commentRows: [],
    moderationLoading: false,
    moderationPages: {
      POSTS: {cursor:'', stack:[], next:'', hasMore:false, page:1},
      COMMENTS: {cursor:'', stack:[], next:'', hasMore:false, page:1},
    },
    selectedPost: null,
    selectedComment: null,
    activeStatus: 'ACTIVE',
    activeCreators: [],
    blockedCreators: [],
    creatorPage: 1,
    selected: new Set(),
    weeklyPeriodsLoaded: false,
    weeklyPeriods: [],
    defaultWeeklyPeriod: null,
    selectedPeriodId: '',
    weeklyReview: null,
    weeklyLoading: false,
    weeklyPage: 1,
    monthlyPeriodsLoaded: false,
    monthlyPeriods: [],
    defaultMonth: null,
    selectedMonthId: '',
    monthlyPreview: null,
    monthlyLoading: false,
    monthlyPage: 1,
    revenueStatus: null,
    revenueLoading: false,
    payoutPreview: null,
    payoutExecuting: false,
    policy: null,
    policyLoading: false,
  };

  const $ = id => document.getElementById(id);
  const safe = value => esc(String(value ?? ''));
  const timestamp = value => value ? fmtTs(Number(value)) : '-';
  const usd = value => `$${humanValue(value, '0')}`;

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
            <span class="zsky-admin-kicker">Z SKY 24 OPERATIONS</span>
            <h3 id="zsky24AdminTitle">Z Sky 24 Admin</h3>
            <p>Moderate publishing, manage creator access and review fixed-calendar performance from one protected workspace.</p>
          </div>
          <button class="btn blue zsky-admin-button" id="zsky24RefreshBtn" type="button">Refresh</button>
        </div>

        <div class="zsky-admin-tabs zsky-primary-tabs" role="tablist" aria-label="Z Sky 24 administration">
          <button class="zsky-admin-tab active" type="button" role="tab" aria-selected="true" data-zsky-mode="OVERVIEW">Overview</button>
          <button class="zsky-admin-tab" type="button" role="tab" aria-selected="false" data-zsky-mode="MODERATION">Posts / Moderation</button>
          <button class="zsky-admin-tab" type="button" role="tab" aria-selected="false" data-zsky-mode="CREATORS">Creator accounts</button>
          <button class="zsky-admin-tab" type="button" role="tab" aria-selected="false" data-zsky-mode="WEEKLY">Weekly reviews</button>
          <button class="zsky-admin-tab" type="button" role="tab" aria-selected="false" data-zsky-mode="MONTHLY">Monthly summary</button>
          <button class="zsky-admin-tab" type="button" role="tab" aria-selected="false" data-zsky-mode="PAYOUT">Payout readiness</button>
          <button class="zsky-admin-tab" type="button" role="tab" aria-selected="false" data-zsky-mode="POLICY">Settings</button>
        </div>

        <section id="zskyOverviewView">
          <div class="zsky-admin-metrics" aria-label="Z Sky 24 overview">
            <div class="zsky-admin-metric warning"><span>Posts on current queue page</span><strong id="zskyPendingPostCount">0</strong></div>
            <div class="zsky-admin-metric warning"><span>Comments on current queue page</span><strong id="zskyPendingCommentCount">0</strong></div>
            <div class="zsky-admin-metric"><span>Active creators loaded</span><strong id="zskyActiveCreatorCountOverview">0</strong></div>
            <div class="zsky-admin-metric danger"><span>Blocked creators loaded</span><strong id="zskyBlockedCreatorCountOverview">0</strong></div>
            <div class="zsky-admin-metric"><span>Adsterra estimate</span><strong id="zskyRevenueEstimateOverview">Pending</strong></div>
            <div class="zsky-admin-metric warning"><span>Revenue sync</span><strong id="zskyRevenueSyncOverview">Not synced</strong></div>
          </div>
          <div class="zsky-overview-grid">
            <article class="card zsky-overview-card"><span class="zsky-admin-kicker">PUBLISHING</span><h3>Moderation queue</h3><p>Review pending posts and comments with canonical version and idempotency controls.</p><button class="btn ghost" type="button" data-zsky-open-mode="MODERATION">Open moderation</button></article>
            <article class="card zsky-overview-card"><span class="zsky-admin-kicker">CREATORS</span><h3>Creator access</h3><p>Inspect active and blocked creator registrations without exposing private account data.</p><button class="btn ghost" type="button" data-zsky-open-mode="CREATORS">Open creators</button></article>
            <article class="card zsky-overview-card"><span class="zsky-admin-kicker">PERFORMANCE</span><h3>Calendar review</h3><p>Generate completed period reviews and inspect the read-only monthly aggregation.</p><button class="btn ghost" type="button" data-zsky-open-mode="WEEKLY">Open reviews</button></article>
            <article class="card zsky-overview-card"><span class="zsky-admin-kicker">POLICY</span><h3>Current settings</h3><p>Inspect the canonical public creator policy. No browser-side setting or payout value is created here.</p><button class="btn ghost" type="button" data-zsky-open-mode="POLICY">Open settings</button></article>
          </div>
          <div class="zsky-retired-note"><strong>Monthly payout contract</strong><span>Provider revenue, approved traffic and locked payout FX are resolved by the server. Creator balances, withdrawals and automatic per-ad credit remain disabled.</span></div>
        </section>

        <section id="zskyModerationView" hidden>
          <div class="zsky-admin-tabs zsky-secondary-tabs" role="tablist" aria-label="Moderation type">
            <button class="zsky-admin-tab active" type="button" role="tab" aria-selected="true" data-zsky-moderation-tab="POSTS">Posts</button>
            <button class="zsky-admin-tab" type="button" role="tab" aria-selected="false" data-zsky-moderation-tab="COMMENTS">Comments</button>
          </div>
          <div class="card zsky-admin-panel">
            <div class="panel-head"><div><h3 id="zskyModerationTitle">Posts awaiting review</h3><p id="zskyModerationSubtitle">Newest pending posts first. Decisions use the stored version and canonical moderation service.</p></div></div>
            <div id="zskyModerationList" class="zsky-admin-list" aria-live="polite"><div class="empty">Loading moderation queue...</div></div>
            <div class="zsky-pagination" id="zskyModerationPagination" aria-label="Moderation pagination">
              <button class="btn ghost" id="zskyModerationPrevious" type="button" disabled>Previous</button>
              <span id="zskyModerationPage">Page 1</span>
              <button class="btn ghost" id="zskyModerationNext" type="button" disabled>Next</button>
            </div>
          </div>
        </section>

        <section id="zskyCreatorAdminView" hidden>
          <div class="card zsky-settlement-panel" id="zskyPayoutSettlementPanel" hidden>
            <div class="panel-head"><div><span class="zsky-admin-kicker">ADSTERRA SETTLEMENT</span><h3>Revenue and payout locks</h3><p>Sync provider USD revenue, lock the completed month, then lock independent USD payout FX before checking creators.</p></div></div>
            <div class="zsky-settlement-controls">
              <label>Month<select class="input" id="zskyPayoutMonthSelect"></select></label>
              <button class="btn ghost zsky-admin-button" id="zskyRevenueSyncBtn" type="button">Sync Adsterra</button>
              <button class="btn brand zsky-admin-button" id="zskyRevenueLockBtn" type="button" disabled>Lock revenue</button>
            </div>
            <div id="zskyRevenueStatus" class="zsky-revenue-status" aria-live="polite"></div>
            <div class="zsky-fx-grid" id="zskyFxLockPanel">
              <label>USD_BDT<input class="input" id="zskyFxBdtRate" inputmode="decimal" placeholder="e.g. 122.00"></label>
              <label>USD_MYR<input class="input" id="zskyFxMyrRate" inputmode="decimal" placeholder="e.g. 4.20"></label>
              <label class="zsky-fx-source">Rate source/reference<input class="input" id="zskyFxSource" maxlength="200" placeholder="Required audit reference"></label>
              <button class="btn ghost zsky-admin-button" id="zskyLockBdtFx" type="button">Lock USD_BDT</button>
              <button class="btn ghost zsky-admin-button" id="zskyLockMyrFx" type="button">Lock USD_MYR</button>
            </div>
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
              <div><h3 id="zskyCreatorListTitle">Active creators</h3><p id="zskyCreatorListSubtitle">Manage creator access using public registry fields only.</p></div>
            </div>
            <div id="zskyCreatorList" class="zsky-admin-list" aria-live="polite"><div class="empty">Loading creators…</div></div>
            <div class="zsky-pagination" id="zskyCreatorPagination" aria-label="Creator pagination">
              <button class="btn ghost" id="zskyCreatorPrevious" type="button" disabled>Previous</button>
              <span id="zskyCreatorPage">Page 1</span>
              <button class="btn ghost" id="zskyCreatorNext" type="button" disabled>Next</button>
            </div>
          </div>

          <div class="zsky-payout-dock" id="zskyPayoutDock">
            <div><strong><span id="zskyPayoutSelectedText">0</span> of ${BATCH_LIMIT} selected</strong><span>Readiness is read-only. Execute payout appears only after month, reviews, revenue and FX all pass server checks.</span></div>
            <div class="zsky-admin-actions"><button class="btn ghost zsky-admin-button" id="zskyClearCreatorSelection" type="button" disabled>Clear</button><button class="btn brand zsky-admin-button" id="zskyPayoutPreflightBtn" type="button" disabled>Check readiness</button></div>
          </div>
        </section>

        <section id="zskyWeeklyReviewView" hidden>
          <div class="card zsky-weekly-toolbar">
            <div class="zsky-weekly-heading">
              <span class="zsky-admin-kicker">WEEKLY PERFORMANCE</span>
              <h3>Verified creator engagement</h3>
              <p>Fixed calendar periods: 01–07, 08–14, 15–21 and 22–month end. Current periods are live and read-only. No revenue, balance or payout amount is calculated here.</p>
            </div>
            <div class="zsky-weekly-controls">
              <label for="zskyWeeklyPeriodSelect">Review period</label>
              <select class="input" id="zskyWeeklyPeriodSelect"></select>
              <button class="btn brand zsky-period-action" id="zskyGenerateWeeklyReview" type="button">Generate review</button>
            </div>
          </div>
          <div class="zsky-period-notice" id="zskyWeeklyPeriodNotice" aria-live="polite"></div>
          <div id="zskyWeeklyMetrics" class="zsky-admin-metrics" aria-label="Weekly review summary"></div>
          <div class="card zsky-admin-panel">
            <div class="panel-head"><div><h3 id="zskyWeeklyTitle">Calendar creator review</h3><p id="zskyWeeklySubtitle">Select a period to view live or completed creator performance.</p></div></div>
            <div id="zskyWeeklyList" class="zsky-admin-list" aria-live="polite"><div class="empty">Select a review period.</div></div>
            <div class="zsky-pagination" id="zskyWeeklyPagination" aria-label="Creator review pagination">
              <button class="btn ghost" id="zskyWeeklyPrevious" type="button" disabled>Previous</button>
              <span id="zskyWeeklyPage">Page 1</span>
              <button class="btn ghost" id="zskyWeeklyNext" type="button" disabled>Next</button>
            </div>
          </div>
        </section>

        <section id="zskyMonthlyView" hidden>
          <div class="card zsky-weekly-toolbar">
            <div class="zsky-weekly-heading">
              <span class="zsky-admin-kicker">MONTHLY PERFORMANCE</span>
              <h3>Creator settlement preview</h3>
              <p>Approved fixed-calendar reviews are aggregated here. Locked Adsterra revenue and FX snapshots are shown without mutating any wallet.</p>
            </div>
            <div class="zsky-weekly-controls">
              <label for="zskyMonthlySelect">Month</label>
              <select class="input" id="zskyMonthlySelect"></select>
              <button class="btn brand zsky-period-action" id="zskyMonthlyReadOnly" type="button" disabled>Read-only preview</button>
            </div>
          </div>
          <div class="zsky-period-notice" id="zskyMonthlyNotice" aria-live="polite"></div>
          <div id="zskyMonthlyMetrics" class="zsky-admin-metrics" aria-label="Monthly performance summary"></div>
          <div class="card zsky-admin-panel">
            <div class="panel-head"><div><h3 id="zskyMonthlyTitle">Monthly creator performance</h3><p id="zskyMonthlySubtitle">Select a month to inspect approved review aggregation.</p></div></div>
            <div id="zskyMonthlyList" class="zsky-admin-list" aria-live="polite"><div class="empty">Select a month.</div></div>
            <div class="zsky-pagination" id="zskyMonthlyPagination" aria-label="Monthly performance pagination">
              <button class="btn ghost" id="zskyMonthlyPrevious" type="button" disabled>Previous</button>
              <span id="zskyMonthlyPage">Page 1</span>
              <button class="btn ghost" id="zskyMonthlyNext" type="button" disabled>Next</button>
            </div>
          </div>
        </section>

        <section id="zskyPolicyView" hidden>
          <div class="card zsky-admin-panel">
            <div class="panel-head"><div><span class="zsky-admin-kicker">READ-ONLY SETTINGS</span><h3>Current creator policy</h3><p>Values come from the existing canonical Z Sky 24 policy endpoint. This screen does not save or override configuration.</p></div></div>
            <div id="zskyPolicyBody" class="zsky-admin-list" aria-live="polite"><div class="empty">Loading policy...</div></div>
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
    if ($('zskyActiveCreatorCountOverview')) $('zskyActiveCreatorCountOverview').textContent = String(zskyState.activeCreators.length);
    if ($('zskyBlockedCreatorCountOverview')) $('zskyBlockedCreatorCountOverview').textContent = String(zskyState.blockedCreators.length);
    if ($('zskySelectedCreatorCount')) $('zskySelectedCreatorCount').textContent = String(zskyState.selected.size);
    if ($('zskyPayoutSelectedText')) $('zskyPayoutSelectedText').textContent = String(zskyState.selected.size);
    const hasSelection = zskyState.selected.size > 0;
    if ($('zskyPayoutPreflightBtn')) $('zskyPayoutPreflightBtn').disabled = !hasSelection;
    if ($('zskyClearCreatorSelection')) $('zskyClearCreatorSelection').disabled = !hasSelection;
    if ($('zskyPayoutDock')) $('zskyPayoutDock').hidden = zskyState.mode !== 'PAYOUT' || zskyState.activeStatus !== 'ACTIVE';
    if ($('zskyPayoutSettlementPanel')) $('zskyPayoutSettlementPanel').hidden = zskyState.mode !== 'PAYOUT';
  }

  function empty(message){ return `<div class="empty">${safe(message)}</div>`; }

  function actionKey(prefix){
    const random = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    return `${prefix}-${random}`;
  }

  function humanValue(value, fallback='-'){
    const text = String(value ?? '').trim();
    return text || fallback;
  }

  function statusClass(status){
    const value = String(status || '').toUpperCase();
    if (['ACTIVE','APPROVED','PAID','COMPLETED','SUCCESSFUL','DONE'].includes(value)) return 'is-success';
    if (['REJECTED','BLOCKED','FAILED','REMOVED','HELD'].includes(value)) return 'is-danger';
    if (['REVIEW','PENDING','UNDER_REVIEW','PROCESSING'].includes(value)) return 'is-warning';
    return 'is-neutral';
  }

  function statusBadge(status){
    const value = humanValue(status, 'PENDING').toUpperCase();
    return `<span class="zsky-status-badge ${statusClass(value)}">${safe(humanStatus(value))}</span>`;
  }

  function pageRows(rows, page){
    const safeRows = Array.isArray(rows) ? rows : [];
    const maxPage = Math.max(1, Math.ceil(safeRows.length / PAGE_SIZE));
    const current = Math.max(1, Math.min(Number(page) || 1, maxPage));
    return {items:safeRows.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE), page:current, maxPage};
  }

  function updateClientPager(prefix, page, maxPage){
    const current = Math.max(1, Number(page) || 1);
    if ($(prefix + 'Page')) $(prefix + 'Page').textContent = `Page ${current} of ${Math.max(1, maxPage)}`;
    if ($(prefix + 'Previous')) $(prefix + 'Previous').disabled = current <= 1;
    if ($(prefix + 'Next')) $(prefix + 'Next').disabled = current >= maxPage;
  }

  function moderationState(){ return zskyState.moderationPages[zskyState.moderationType]; }

  function moderationRows(){
    return zskyState.moderationType === 'COMMENTS' ? zskyState.commentRows : zskyState.postRows;
  }

  function postRow(row){
    const postId = humanValue(row.post_id || row.id, 'Unknown post');
    const creator = humanValue(row.creator_name || row.author_name || row.name || row.creator_uid, 'Z-Pay creator');
    const title = humanValue(row.title || row.headline, 'Untitled post');
    const status = row.moderation_status || row.status || 'REVIEW';
    return `<article class="zsky-admin-row zsky-moderation-row">
      <div class="zsky-admin-row-main"><strong>${safe(title)}</strong><span>${safe(creator)} · ${safe(postId)}</span><small>${safe(timestamp(row.created_at))}</small></div>
      <div class="zsky-admin-cell"><small>Status</small>${statusBadge(status)}</div>
      <div class="zsky-admin-cell"><small>Engagement</small><strong>${safe(row.view_count || row.views || 0)} views</strong></div>
      <div class="zsky-admin-actions"><button class="btn ghost" type="button" data-zsky-view-post="${safe(postId)}">View</button></div>
    </article>`;
  }

  function commentRow(row){
    const postId = humanValue(row.post_id, 'Unknown post');
    const commentId = humanValue(row.comment_id || row.id, 'Unknown comment');
    const creator = humanValue(row.creator_name || row.author_name || row.name || row.creator_uid, 'Z-Pay user');
    const message = humanValue(row.text || row.comment || row.body || row.content, 'Comment awaiting review');
    const status = row.moderation_status || row.status || 'REVIEW';
    return `<article class="zsky-admin-row zsky-moderation-row">
      <div class="zsky-admin-row-main"><strong>${safe(message)}</strong><span>${safe(creator)} · ${safe(commentId)}</span><small>Post ${safe(postId)} · ${safe(timestamp(row.created_at))}</small></div>
      <div class="zsky-admin-cell"><small>Status</small>${statusBadge(status)}</div>
      <div class="zsky-admin-actions"><button class="btn ghost" type="button" data-zsky-view-comment="${safe(commentId)}" data-post-id="${safe(postId)}">View</button></div>
    </article>`;
  }

  function renderModeration(){
    const type = zskyState.moderationType;
    const rows = moderationRows();
    const pager = moderationState();
    if ($('zskyModerationTitle')) $('zskyModerationTitle').textContent = type === 'COMMENTS' ? 'Comments awaiting review' : 'Posts awaiting review';
    if ($('zskyModerationSubtitle')) $('zskyModerationSubtitle').textContent = type === 'COMMENTS'
      ? 'Newest pending comments first. Decisions use the canonical comment moderation service.'
      : 'Newest pending posts first. Decisions use the stored version and canonical moderation service.';
    if ($('zskyModerationList')) $('zskyModerationList').innerHTML = rows.length
      ? rows.map(type === 'COMMENTS' ? commentRow : postRow).join('')
      : empty(type === 'COMMENTS' ? 'No comments are awaiting review.' : 'No posts are awaiting review.');
    if ($('zskyModerationPage')) $('zskyModerationPage').textContent = `Page ${pager.page}`;
    if ($('zskyModerationPrevious')) $('zskyModerationPrevious').disabled = pager.stack.length === 0;
    if ($('zskyModerationNext')) $('zskyModerationNext').disabled = !pager.hasMore || !pager.next;
    if ($('zskyPendingPostCount')) $('zskyPendingPostCount').textContent = String(zskyState.postRows.length);
    if ($('zskyPendingCommentCount')) $('zskyPendingCommentCount').textContent = String(zskyState.commentRows.length);
  }

  async function loadModeration(force=false){
    if (zskyState.moderationLoading && !force) return;
    zskyState.moderationLoading = true;
    const type = zskyState.moderationType;
    const pager = zskyState.moderationPages[type];
    if ($('zskyModerationList')) $('zskyModerationList').innerHTML = empty(`Loading ${type === 'COMMENTS' ? 'comments' : 'posts'}...`);
    try {
      const data = await request(type === 'COMMENTS' ? 'comments_queue' : 'posts_queue', {
        params:{limit:PAGE_SIZE, cursor:pager.cursor}, busy:false,
      });
      if (type === 'COMMENTS') zskyState.commentRows = Array.isArray(data.items) ? data.items.slice(0, PAGE_SIZE) : [];
      else zskyState.postRows = Array.isArray(data.items) ? data.items.slice(0, PAGE_SIZE) : [];
      pager.next = String(data.next_cursor || '');
      pager.hasMore = Boolean(data.has_more && pager.next);
      renderModeration();
    } catch (error) {
      if ($('zskyModerationList')) $('zskyModerationList').innerHTML = empty('Moderation queue could not be loaded. Please retry.');
      throw error;
    } finally {
      zskyState.moderationLoading = false;
    }
  }

  function openZSkyModal(title, bodyHtml, footHtml){
    openModal(title, bodyHtml, footHtml);
    if (typeof setModalPresentationScope === 'function') {
      setModalPresentationScope('zsky24');
    }
  }

  async function moveModerationPage(direction){
    const pager = moderationState();
    if (direction > 0) {
      if (!pager.hasMore || !pager.next) return;
      pager.stack.push(pager.cursor);
      pager.cursor = pager.next;
      pager.page += 1;
    } else {
      if (!pager.stack.length) return;
      pager.cursor = pager.stack.pop() || '';
      pager.page = Math.max(1, pager.page - 1);
    }
    await loadModeration(true);
  }

  function detailRows(rows){
    return `<div class="zsky-admin-detail-grid">${rows.map(([label, value]) => `<div class="zsky-admin-detail"><small>${safe(label)}</small><strong>${safe(humanValue(value))}</strong></div>`).join('')}</div>`;
  }

  async function openPostDetails(postId){
    try {
      const data = await request('post_details', {params:{post_id:postId}, busyText:'Loading post details...'});
      const post = data.post || {};
      zskyState.selectedPost = post;
      const body = `${detailRows([
        ['Post ID', post.post_id || postId], ['Creator', post.creator_name || post.author_name || post.creator_uid],
        ['Status', post.moderation_status || post.status], ['Created', timestamp(post.created_at)],
        ['Updated', timestamp(post.updated_at)], ['Views', post.view_count || post.views || 0],
      ])}<div class="zsky-content-preview"><small>Title</small><strong>${safe(humanValue(post.title || post.headline, 'Untitled post'))}</strong><small>Post text</small><p>${safe(humanValue(post.body || post.text || post.content, 'No text content.'))}</p></div>`;
      const reviewable = String(post.status || '').toUpperCase() === 'REVIEW' || String(post.moderation_status || '').toUpperCase() === 'PENDING';
      const foot = reviewable
        ? '<button class="btn ghost zsky-admin-button" type="button" onclick="closeDrawer()">Close</button><button class="btn danger zsky-admin-button" type="button" data-zsky-post-decision="REJECT">Reject</button><button class="btn brand zsky-admin-button" type="button" data-zsky-post-decision="APPROVE">Approve</button>'
        : '<button class="btn ghost zsky-admin-button" type="button" onclick="closeDrawer()">Close</button>';
      openDrawer('Post moderation', humanValue(post.title || post.headline, 'Post details'), body, foot);
    } catch (error) { showToast(error.message || 'Post details could not be loaded.', 'error'); }
  }

  function decidePost(decision){
    const post = zskyState.selectedPost || {};
    if (!post.post_id || !post.updated_at) return showToast('Reload the post before moderating it.', 'error');
    const approve = decision === 'APPROVE';
    const options = approve
      ? '<option value="CLEAR">Clear</option><option value="ORIGINAL_CONFIRMED">Original confirmed</option><option value="LICENSED">Licensed</option>'
      : '<option value="POLICY_REJECTED">Policy rejected</option><option value="COPYRIGHT_MATCH">Copyright match</option><option value="PLAGIARISM">Plagiarism</option><option value="OTHER">Other</option>';
    openZSkyModal(
      approve ? 'Approve post' : 'Reject post',
      `<label for="zskyPostVerdict">Copyright verdict</label><select class="input" id="zskyPostVerdict">${options}</select><label for="zskyPostDecisionNote">${approve ? 'Admin note (optional)' : 'Reason'}</label><textarea class="input zsky-admin-reason" id="zskyPostDecisionNote" maxlength="${approve ? 1000 : 500}" ${approve ? '' : 'required'}></textarea>`,
      `<button class="btn ghost zsky-admin-button" type="button" onclick="closeModal()">Cancel</button><button class="btn ${approve ? 'brand' : 'danger'} zsky-admin-button" type="button" id="zskyConfirmPostDecision">${approve ? 'Approve' : 'Reject'}</button>`
    );
    $('zskyConfirmPostDecision')?.addEventListener('click', async event => {
      const note = $('zskyPostDecisionNote')?.value.trim() || '';
      if (!approve && !note) return showToast('A rejection reason is required.', 'error');
      event.currentTarget.disabled = true;
      try {
        await request('post_decision', {method:'POST', body:{
          post_id:post.post_id, expected_updated_at:Number(post.updated_at), decision,
          copyright_verdict:$('zskyPostVerdict')?.value || (approve ? 'CLEAR' : 'POLICY_REJECTED'),
          [approve ? 'note' : 'reason']:note, idempotency_key:actionKey(`post-${decision.toLowerCase()}`),
        }, busyText:`${approve ? 'Approving' : 'Rejecting'} post...`});
        closeModal(); closeDrawer();
        await loadModeration(true);
        showToast(approve ? 'Post approved.' : 'Post rejected.', 'success');
      } catch (error) { closeModal(); showToast(error.message || 'Post decision failed.', 'error'); }
    }, {once:true});
  }

  async function openCommentDetails(postId, commentId){
    try {
      const data = await request('comment_details', {params:{post_id:postId, comment_id:commentId}, busyText:'Loading comment details...'});
      const comment = data.comment || {};
      zskyState.selectedComment = comment;
      const body = `${detailRows([
        ['Comment ID', comment.comment_id || commentId], ['Post ID', comment.post_id || postId],
        ['Author', comment.creator_name || comment.author_name || comment.creator_uid], ['Status', comment.moderation_status || comment.status],
        ['Created', timestamp(comment.created_at)], ['Updated', timestamp(comment.updated_at)],
      ])}<div class="zsky-content-preview"><small>Comment</small><p>${safe(humanValue(comment.text || comment.comment || comment.body || comment.content, 'No text content.'))}</p></div>`;
      const reviewable = String(comment.status || '').toUpperCase() === 'REVIEW' || String(comment.moderation_status || '').toUpperCase() === 'PENDING';
      const foot = reviewable
        ? '<button class="btn ghost zsky-admin-button" type="button" onclick="closeDrawer()">Close</button><button class="btn danger zsky-admin-button" type="button" data-zsky-comment-decision="REJECT">Reject</button><button class="btn brand zsky-admin-button" type="button" data-zsky-comment-decision="APPROVE">Approve</button>'
        : '<button class="btn ghost zsky-admin-button" type="button" onclick="closeDrawer()">Close</button>';
      openDrawer('Comment moderation', `Comment ${humanValue(comment.comment_id || commentId)}`, body, foot);
    } catch (error) { showToast(error.message || 'Comment details could not be loaded.', 'error'); }
  }

  function decideComment(decision){
    const comment = zskyState.selectedComment || {};
    if (!comment.post_id || !comment.comment_id || !comment.updated_at) return showToast('Reload the comment before moderating it.', 'error');
    const approve = decision === 'APPROVE';
    openZSkyModal(
      approve ? 'Approve comment' : 'Reject comment',
      `<label for="zskyCommentDecisionNote">${approve ? 'Admin note (optional)' : 'Reason'}</label><textarea class="input zsky-admin-reason" id="zskyCommentDecisionNote" maxlength="500" ${approve ? '' : 'required'}></textarea>`,
      `<button class="btn ghost zsky-admin-button" type="button" onclick="closeModal()">Cancel</button><button class="btn ${approve ? 'brand' : 'danger'} zsky-admin-button" type="button" id="zskyConfirmCommentDecision">${approve ? 'Approve' : 'Reject'}</button>`
    );
    $('zskyConfirmCommentDecision')?.addEventListener('click', async event => {
      const note = $('zskyCommentDecisionNote')?.value.trim() || '';
      if (!approve && !note) return showToast('A rejection reason is required.', 'error');
      event.currentTarget.disabled = true;
      try {
        await request('comment_decision', {method:'POST', body:{
          post_id:comment.post_id, comment_id:comment.comment_id, expected_updated_at:Number(comment.updated_at), decision,
          [approve ? 'note' : 'reason']:note, idempotency_key:actionKey(`comment-${decision.toLowerCase()}`),
        }, busyText:`${approve ? 'Approving' : 'Rejecting'} comment...`});
        closeModal(); closeDrawer();
        await loadModeration(true);
        showToast(approve ? 'Comment approved.' : 'Comment rejected.', 'success');
      } catch (error) { closeModal(); showToast(error.message || 'Comment decision failed.', 'error'); }
    }, {once:true});
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
        <div class="zsky-creator-select">${active && zskyState.mode === 'PAYOUT' ? `<input type="checkbox" aria-label="Select ${safe(row.name || 'creator')}" data-zsky-select-creator="${safe(uid)}" ${selected ? 'checked' : ''}>` : `<span class="${active ? 'zsky-active-mark' : 'zsky-blocked-mark'}" aria-hidden="true">${active ? '✓' : '×'}</span>`}</div>
        <div class="zsky-admin-row-main"><strong>${safe(row.name || 'Z-Pay creator')}</strong><span>${safe(account)} • ${safe(uid)}</span><small>${secondary}</small></div>
        <div class="zsky-admin-cell"><small>Wallet snapshot</small><strong>${safe(currency)}</strong></div>
        <div class="zsky-admin-cell"><small>Creator status</small><strong class="${active ? 'zsky-status-active' : 'zsky-flag'}">${safe(status)}</strong></div>
        <div class="zsky-admin-actions">${active
          ? `<button class="btn danger zsky-admin-button" type="button" data-zsky-block-creator="${safe(uid)}" data-creator-name="${safe(row.name || 'Creator')}">Block</button>`
          : `<button class="btn brand zsky-admin-button" type="button" data-zsky-unblock-creator="${safe(uid)}" data-creator-name="${safe(row.name || 'Creator')}">Unblock</button>`}</div>
      </article>`;
  }

  function renderCreators(){
    ensureUi();
    const status = zskyState.activeStatus;
    const rows = creatorsForStatus(status);
    const paged = pageRows(rows, zskyState.creatorPage);
    zskyState.creatorPage = paged.page;
    if ($('zskyCreatorListTitle')) $('zskyCreatorListTitle').textContent = zskyState.mode === 'PAYOUT'
      ? 'Payout readiness'
      : (status === 'ACTIVE' ? 'Active creators' : 'Blocked creators');
    if ($('zskyCreatorListSubtitle')) $('zskyCreatorListSubtitle').textContent = status === 'ACTIVE'
      ? (zskyState.mode === 'PAYOUT' ? 'Select up to five active creators. The server resolves review readiness, locked revenue, locked FX and final wallet credit.' : 'Manage creator access using public registry fields only.')
      : 'Blocked creators cannot publish, manage posts or receive payout.';
    if ($('zskyCreatorList')) $('zskyCreatorList').innerHTML = paged.items.length
      ? paged.items.map(creatorRow).join('')
      : empty(status === 'ACTIVE' ? 'No active creator is registered yet.' : 'No creator is currently blocked.');
    updateClientPager('zskyCreator', paged.page, paged.maxPage);
    updateMetrics();
  }

  function yesNo(value){ return value ? 'Enabled' : 'Disabled'; }

  function renderPolicy(){
    const policy = zskyState.policy || {};
    if (!$('zskyPolicyBody')) return;
    if (!zskyState.policy) {
      $('zskyPolicyBody').innerHTML = empty('Current policy could not be loaded. Please retry.');
      return;
    }
    $('zskyPolicyBody').innerHTML = `
      ${detailRows([
        ['Revenue mode', policy.revenue_mode],
        ['Revenue provider', policy.revenue_provider],
        ['Base revenue currency', policy.revenue_base_currency],
        ['Performance review', `${humanValue(policy.performance_review_days, '7')} days`],
        ['Payout cycle', policy.payout_cycle],
        ['Payout destination', policy.payout_destination],
        ['Creator pool', `${humanValue(policy.creator_pool_percent_of_net, '0')}% of net`],
        ['Platform share', `${humanValue(policy.platform_share_percent_of_net, '0')}% of net`],
        ['Creator effective gross', `${humanValue(policy.creator_effective_percent_of_gross, '0')}%`],
        ['Platform effective gross', `${humanValue(policy.platform_effective_percent_of_gross, '0')}%`],
        ['Safety reserve', `${humanValue(policy.safety_reserve_percent, '0')}%`],
        ['Batch limit', policy.payout_batch_limit],
        ['Creator balance', yesNo(Boolean(policy.creator_balance_enabled))],
        ['Withdrawal requests', yesNo(Boolean(policy.withdraw_request_enabled))],
        ['Automatic per-ad credit', yesNo(Boolean(policy.automatic_per_ad_credit_enabled))],
        ['Instant clean comments', yesNo(Boolean(policy.instant_comments_enabled))],
        ['Wallet currencies', Array.isArray(policy.supported_wallet_currencies) ? policy.supported_wallet_currencies.join(', ') : '-'],
      ])}
      <section class="zsky-admin-guide" aria-labelledby="zskyAdminGuideTitle">
        <div class="zsky-admin-guide-head"><span class="zsky-admin-kicker">OPERATIONS GUIDE</span><h4 id="zskyAdminGuideTitle">What each area does</h4></div>
        <div class="zsky-admin-guide-grid">
          <article><strong>Overview</strong><span>Shows bounded queue and creator counts for a quick health check.</span></article>
          <article><strong>Posts / Moderation</strong><span>Opens pending posts or comments and applies canonical approve or reject decisions.</span></article>
          <article><strong>Creator accounts</strong><span>Activates or blocks Z Sky creator access. It does not change the Z-Pay account or wallet.</span></article>
          <article><strong>Weekly reviews</strong><span>Generates completed calendar snapshots, then approves or holds each creator review.</span></article>
          <article><strong>Monthly summary</strong><span>Combines approved review metrics into a read-only settlement-readiness summary.</span></article>
          <article><strong>Payout readiness</strong><span>Checks up to five creators against completed reviews, locked Adsterra revenue, locked FX and live wallet identity.</span></article>
          <article><strong>Settings</strong><span>Displays the canonical public creator policy. All values on this screen are read-only.</span></article>
        </div>
      </section>
      <section class="zsky-balance-flow" aria-labelledby="zskyBalanceFlowTitle">
        <div class="zsky-balance-flow-head"><div><span class="zsky-admin-kicker">BALANCE FLOW</span><h4 id="zskyBalanceFlowTitle">How creator value can reach Z-Pay</h4></div><span class="zsky-status-badge is-warning">No balance movement here</span></div>
        <ol>
          <li><strong>Verify engagement</strong><span>Self, creator, duplicate, bot and invalid activity is excluded by the server.</span></li>
          <li><strong>Review periods</strong><span>Completed weekly reviews must be approved and the monthly summary must be ready.</span></li>
          <li><strong>Lock Adsterra revenue</strong><span>A completed month uses the final provider USD report. Views alone never create money.</span></li>
          <li><strong>Lock payout FX</strong><span>USD_BDT and USD_MYR are independent audited snapshots, not Top-Up conversion rates.</span></li>
          <li><strong>Server-side payout</strong><span>The canonical wallet helper credits the exact native amount once and writes a deterministic ledger reference.</span></li>
        </ol>
        <p>Formula: reserve 10% of gross; creators receive 40% of the remaining 90% (36% effective gross); platform receives 60% of the remaining 90% (54% effective gross). Creator balance, withdrawal and per-ad credit remain disabled.</p>
      </section>
      <div class="zsky-retired-note"><strong>Server authoritative</strong><span>Creator/account eligibility and review-period rules remain enforced by the existing backend. No financial amount is accepted from this screen.</span></div>`;
  }

  async function loadPolicy(force=false){
    if (zskyState.policy && !force) { renderPolicy(); return; }
    if (zskyState.policyLoading) return;
    zskyState.policyLoading = true;
    if ($('zskyPolicyBody')) $('zskyPolicyBody').innerHTML = empty('Loading policy...');
    try {
      const response = await fetch('/api/znews/public/policy.php', {
        method:'GET', credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'},
      });
      const json = readJson(await response.text());
      if (!response.ok || !json.ok || !json.data) throw new Error('Current policy could not be loaded.');
      zskyState.policy = json.data;
      renderPolicy();
    } catch (error) {
      zskyState.policy = null;
      renderPolicy();
      throw error;
    } finally {
      zskyState.policyLoading = false;
    }
  }

  function setMode(mode){
    const allowed = ['OVERVIEW','MODERATION','CREATORS','WEEKLY','MONTHLY','PAYOUT','POLICY'];
    zskyState.mode = allowed.includes(mode) ? mode : 'OVERVIEW';
    document.querySelectorAll('[data-zsky-mode]').forEach(node => {
      const active = node.dataset.zskyMode === zskyState.mode;
      node.classList.toggle('active', active);
      node.setAttribute('aria-selected', String(active));
    });
    if ($('zskyOverviewView')) $('zskyOverviewView').hidden = zskyState.mode !== 'OVERVIEW';
    if ($('zskyModerationView')) $('zskyModerationView').hidden = zskyState.mode !== 'MODERATION';
    if ($('zskyCreatorAdminView')) $('zskyCreatorAdminView').hidden = !['CREATORS','PAYOUT'].includes(zskyState.mode);
    if ($('zskyWeeklyReviewView')) $('zskyWeeklyReviewView').hidden = zskyState.mode !== 'WEEKLY';
    if ($('zskyMonthlyView')) $('zskyMonthlyView').hidden = zskyState.mode !== 'MONTHLY';
    if ($('zskyPolicyView')) $('zskyPolicyView').hidden = zskyState.mode !== 'POLICY';
    if ($('zskyPayoutSettlementPanel')) $('zskyPayoutSettlementPanel').hidden = zskyState.mode !== 'PAYOUT';
    const blockedTab = document.querySelector('[data-zsky-creator-tab="BLOCKED"]');
    if (blockedTab) blockedTab.hidden = zskyState.mode === 'PAYOUT';
    if (zskyState.mode === 'PAYOUT') {
      zskyState.activeStatus = 'ACTIVE';
      zskyState.creatorPage = 1;
      document.querySelectorAll('[data-zsky-creator-tab]').forEach(node => {
        const active = node.dataset.zskyCreatorTab === 'ACTIVE';
        node.classList.toggle('active', active);
        node.setAttribute('aria-selected', String(active));
      });
    }
    if (zskyState.mode === 'WEEKLY') {
      loadWeekly().catch(error => showToast(error.message || 'Weekly reviews could not be loaded.', 'error'));
    } else if (zskyState.mode === 'MONTHLY') {
      loadMonthly().catch(error => showToast(error.message || 'Monthly performance could not be loaded.', 'error'));
    } else if (zskyState.mode === 'MODERATION') {
      loadModeration().catch(error => showToast(error.message || 'Moderation queue could not be loaded.', 'error'));
    } else if (zskyState.mode === 'POLICY') {
      loadPolicy().catch(error => showToast(error.message || 'Z Sky settings could not be loaded.', 'error'));
    } else if (zskyState.mode === 'PAYOUT') {
      loadPayoutWorkspace().catch(error => showToast(error.message || 'Payout workspace could not be loaded.', 'error'));
    } else if (zskyState.mode === 'CREATORS') {
      renderCreators();
    } else {
      updateMetrics();
      renderModeration();
    }
  }

  async function load(force=false){
    ensureUi();
    if (zskyState.loaded && !force) { setMode(zskyState.mode); return; }
    if (zskyState.loading && zskyState.loadPromise) return zskyState.loadPromise;
    zskyState.loading = true;
    if ($('zskyCreatorList') && !zskyState.loaded) $('zskyCreatorList').innerHTML = empty('Loading creators…');
    zskyState.loadPromise = (async () => {
      const postPager = zskyState.moderationPages.POSTS;
      const commentPager = zskyState.moderationPages.COMMENTS;
      const results = await Promise.allSettled([
        request('creators_list', {params:{status:'ACTIVE', limit:100}, busy:false}),
        request('creators_list', {params:{status:'BLOCKED', limit:100}, busy:false}),
        request('posts_queue', {params:{limit:PAGE_SIZE, cursor:postPager.cursor}, busy:false}),
        request('comments_queue', {params:{limit:PAGE_SIZE, cursor:commentPager.cursor}, busy:false}),
      ]);
      if (results.every(result => result.status === 'rejected')) throw results[0].reason;
      const [active, blocked, posts, comments] = results.map(result => result.status === 'fulfilled' ? result.value : {});
      zskyState.activeCreators = Array.isArray(active.items) ? active.items : [];
      zskyState.blockedCreators = Array.isArray(blocked.items) ? blocked.items : [];
      zskyState.postRows = Array.isArray(posts.items) ? posts.items.slice(0, PAGE_SIZE) : [];
      zskyState.commentRows = Array.isArray(comments.items) ? comments.items.slice(0, PAGE_SIZE) : [];
      postPager.next = String(posts.next_cursor || '');
      postPager.hasMore = Boolean(posts.has_more && postPager.next);
      commentPager.next = String(comments.next_cursor || '');
      commentPager.hasMore = Boolean(comments.has_more && commentPager.next);
      const activeIds = new Set(zskyState.activeCreators.map(row => String(row.creator_uid || '')));
      [...zskyState.selected].forEach(uid => { if (!activeIds.has(uid)) zskyState.selected.delete(uid); });
      zskyState.loaded = true;
      renderCreators();
      renderModeration();
      setMode(zskyState.mode);
      loadMonthlyPeriods().then(() => loadRevenueStatus(zskyState.defaultMonth?.month_id || zskyState.selectedMonthId)).catch(() => {});
      if (results.some(result => result.status === 'rejected')) showToast('Some Z Sky 24 data could not be loaded. Retry this view.', 'error');
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
    zskyState.payoutPreview = null;
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
    openZSkyModal(
      'Block Z Sky creator',
      `<p>Block <strong>${safe(name)}</strong> from creator actions and payout eligibility?</p><label for="zskyCreatorBlockReason">Reason</label><textarea id="zskyCreatorBlockReason" class="input zsky-admin-reason" maxlength="300" placeholder="Required reason"></textarea>`,
      '<button class="btn ghost zsky-admin-button" type="button" onclick="closeModal()">Cancel</button><button class="btn danger zsky-admin-button" type="button" id="zskyConfirmCreatorBlock">Block creator</button>'
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
    openZSkyModal(
      'Activate Z Sky creator',
      `<p>Restore creator access for <strong>${safe(name)}</strong>? Live Z-Pay account eligibility will still be checked before payout.</p>`,
      '<button class="btn ghost zsky-admin-button" type="button" onclick="closeModal()">Cancel</button><button class="btn brand zsky-admin-button" type="button" id="zskyConfirmCreatorUnblock">Activate creator</button>'
    );
    $('zskyConfirmCreatorUnblock')?.addEventListener('click', async () => {
      closeModal();
      try { await updateCreatorStatus(uid, 'ACTIVE'); }
      catch (error) { showToast(error.message || 'Creator could not be activated.', 'error'); }
    }, {once:true});
  }

  function preflightMarkup(data){
    const creators = Array.isArray(data.creators) ? data.creators : [];
    const revenue = data.revenue || {};
    return `
      <div class="zsky-preflight-banner"><strong>Ready for explicit payout</strong><span>Review these server-calculated final values. No value came from the browser.</span></div>
      <div class="zsky-admin-detail-grid">
        <div class="zsky-admin-detail"><small>Selected creators</small><strong>${safe(creators.length)}</strong></div>
        <div class="zsky-admin-detail"><small>Batch limit</small><strong>${safe(data.batch_limit || BATCH_LIMIT)}</strong></div>
        <div class="zsky-admin-detail"><small>Locked gross USD</small><strong>${safe(usd(revenue.gross_settled_usd))}</strong></div>
        <div class="zsky-admin-detail"><small>Creator pool USD</small><strong>${safe(usd(revenue.creator_pool_usd))}</strong></div>
      </div>
      <div class="zsky-preflight-list">${creators.map(row => `
        <div class="zsky-preflight-row"><div><strong>${safe(row.name || 'Creator')}</strong><span>${safe(row.zpay_account_masked || row.creator_uid || '')}</span></div><div><small>Eligible views / USD share</small><strong>${safe(row.settlement_eligible_views || 0)} / ${safe(usd(row.creator_share_usd))}</strong></div><div><small>Locked FX</small><strong>${safe(row.fx_pair || '-')} ${safe(row.fx_rate || '-')}</strong></div><div><small>Final wallet credit</small><strong>${safe(row.wallet_currency || '-')} ${safe(row.wallet_amount || '0')}</strong></div></div>`).join('')}</div>
      <p class="zsky-admin-note">Execute payout uses the canonical wallet helper, deterministic ledger IDs and one payout identity per provider, month and creator.</p>`;
  }

  async function previewPayout(){
    if (!zskyState.selected.size) return;
    try {
      const data = await request('payout_preflight', {
        method:'POST', body:{month_id:zskyState.selectedMonthId, creator_uids:[...zskyState.selected]},
        busyText:'Validating creator payout batch…',
      });
      zskyState.payoutPreview = data;
      openDrawer('Payout readiness', `${(data.creators || []).length} creators passed all payout checks`, preflightMarkup(data), '<button class="btn ghost zsky-admin-button" type="button" onclick="closeDrawer()">Close</button><button class="btn brand zsky-admin-button" type="button" id="zskyExecutePayoutBtn">Execute payout</button>');
      $('zskyExecutePayoutBtn')?.addEventListener('click', confirmPayoutExecution, {once:true});
    } catch (error) {
      const rejected = Array.isArray(error.data?.rejected) ? error.data.rejected : [];
      if (rejected.length) {
        openDrawer(
          'Payout batch blocked',
          'One or more selected creators failed eligibility checks',
          `<div class="zsky-preflight-banner danger"><strong>Preview failed</strong><span>No wallet balance was changed.</span></div><div class="zsky-preflight-list">${rejected.map(row => `<div class="zsky-preflight-row"><div><strong>${safe(row.creator_uid || 'Creator')}</strong><span>${safe(row.message || row.code || 'Not eligible')}</span></div><div><small>Code</small><strong class="zsky-flag">${safe(row.code || '-')}</strong></div></div>`).join('')}</div>`,
          '<button class="btn ghost zsky-admin-button" type="button" onclick="closeDrawer()">Close</button>'
        );
        return;
      }
      showToast(error.message || 'Payout preview failed.', 'error');
    }
  }

  function confirmPayoutExecution(){
    const data = zskyState.payoutPreview || {};
    const creators = Array.isArray(data.creators) ? data.creators : [];
    if (!creators.length || zskyState.payoutExecuting) return;
    closeDrawer();
    openZSkyModal(
      'Execute creator payout?',
      `<div class="zsky-confirm-payout"><p>This will credit linked Z-Pay wallets using the locked provider revenue and FX snapshots.</p>${creators.map(row => `<div><strong>${safe(row.name || row.creator_uid)}</strong><span>${safe(row.wallet_currency)} ${safe(row.wallet_amount)} (${safe(usd(row.creator_share_usd))})</span></div>`).join('')}</div>`,
      '<button class="btn ghost zsky-admin-button" type="button" onclick="closeModal()">Cancel</button><button class="btn brand zsky-admin-button" type="button" id="zskyConfirmPayoutExecute">Confirm payout</button>'
    );
    $('zskyConfirmPayoutExecute')?.addEventListener('click', executePayout, {once:true});
  }

  async function executePayout(){
    if (zskyState.payoutExecuting || !zskyState.payoutPreview) return;
    zskyState.payoutExecuting = true;
    closeModal();
    try {
      const data = await request('payout_execute', {
        method:'POST',
        body:{
          month_id:zskyState.selectedMonthId,
          creator_uids:[...zskyState.selected],
          confirmation:'EXECUTE_PAYOUT',
          idempotency_key:actionKey('zsky-monthly-payout'),
        },
        busyText:'Crediting linked Z-Pay wallets…',
      });
      zskyState.selected.clear();
      zskyState.payoutPreview = null;
      renderCreators();
      showToast(data.idempotent_replay ? 'Payout was already completed.' : 'Creator payout completed.', 'success');
    } catch (error) {
      showToast(error.message || 'Creator payout could not be completed. Safe retry is available.', 'error');
    } finally {
      zskyState.payoutExecuting = false;
    }
  }

  function parsePeriodDate(value){
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return null;
    return new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
  }

  function shortDate(value){
    const date = parsePeriodDate(value);
    if (!date) return String(value || '');
    return new Intl.DateTimeFormat('en-GB', {day:'2-digit', month:'short', year:'numeric', timeZone:'UTC'}).format(date);
  }

  function periodLabel(period){
    const start = String(period?.period_start_date || period?.period_id || '');
    const end = String(period?.period_end_date || '');
    return end ? `${shortDate(start)} – ${shortDate(end)}` : shortDate(start);
  }

  function periodMeta(periodId = zskyState.selectedPeriodId){
    const match = zskyState.weeklyPeriods.find(row => String(row?.period_id || '') === String(periodId || ''));
    if (match) return match;
    if (String(zskyState.defaultWeeklyPeriod?.period_id || '') === String(periodId || '')) return zskyState.defaultWeeklyPeriod;
    return null;
  }

  function humanStatus(status){
    status = String(status || '').toUpperCase();
    const labels = {
      LIVE:'Live now',
      UPCOMING:'Upcoming',
      COMPLETED:'Ready to generate',
      UNDER_REVIEW:'Under review',
      APPROVED:'Approved',
      HELD:'Held',
      NOT_GENERATED:'Ready to generate',
    };
    return labels[status] || status.replaceAll('_', ' ').toLowerCase().replace(/^./, char => char.toUpperCase());
  }

  function periodOptionStatus(period){
    const lifecycle = String(period?.lifecycle_status || '').toUpperCase();
    if (lifecycle === 'LIVE') return 'LIVE';
    if (lifecycle === 'UPCOMING') return 'UPCOMING';
    if (period?.generated && period?.review_status) return String(period.review_status).toUpperCase();
    return 'NOT_GENERATED';
  }

  function renderPeriodOptions(){
    const select = $('zskyWeeklyPeriodSelect');
    if (!select) return;
    const map = new Map();
    if (zskyState.defaultWeeklyPeriod?.period_id) map.set(zskyState.defaultWeeklyPeriod.period_id, zskyState.defaultWeeklyPeriod);
    zskyState.weeklyPeriods.forEach(period => {
      if (period?.period_id) map.set(period.period_id, period);
    });
    const periods = [...map.values()];
    select.innerHTML = periods.map(period => {
      const status = periodOptionStatus(period);
      return `<option value="${safe(period.period_id)}">${safe(periodLabel(period))} • ${safe(humanStatus(status))}</option>`;
    }).join('');
    if (!zskyState.selectedPeriodId) zskyState.selectedPeriodId = zskyState.defaultWeeklyPeriod?.period_id || periods[0]?.period_id || '';
    select.value = zskyState.selectedPeriodId;
  }

  function weeklyStatusClass(status){
    status = String(status || 'UNDER_REVIEW').toUpperCase();
    if (status === 'APPROVED' || status === 'LIVE') return 'zsky-status-active';
    return status === 'HELD' ? 'zsky-flag' : 'zsky-status-review';
  }

  function weeklyRow(row, {readOnly=false} = {}){
    const status = String(row.review_status || (readOnly ? 'LIVE' : 'UNDER_REVIEW')).toUpperCase();
    const creatorStatus = String(row.creator_status || 'ACTIVE').toUpperCase();
    const canApprove = !readOnly && creatorStatus === 'ACTIVE' && status !== 'APPROVED';
    const canHold = !readOnly && status !== 'HELD';
    const actions = readOnly
      ? `<div class="zsky-live-chip">Live preview</div>`
      : `<button class="btn brand zsky-admin-button" type="button" data-zsky-weekly-approve="${safe(row.creator_uid)}" ${canApprove ? '' : 'disabled'}>${status === 'APPROVED' ? 'Approved' : 'Approve'}</button><button class="btn danger zsky-admin-button" type="button" data-zsky-weekly-hold="${safe(row.creator_uid)}" data-creator-name="${safe(row.creator_name || 'Creator')}" ${canHold ? '' : 'disabled'}>${status === 'HELD' ? 'Held' : 'Hold'}</button>`;
    return `
      <article class="zsky-weekly-row ${readOnly ? 'is-live' : ''}">
        <div class="zsky-admin-row-main"><strong>${safe(row.creator_name || 'Z-Pay creator')}</strong><span>${safe(row.creator_uid || '')}</span><small>${safe(row.review_reason || `${row.post_count || 0} posts in period`)}</small></div>
        <div class="zsky-weekly-stat"><small>Raw / eligible</small><strong>${safe(row.raw_views || 0)} / ${safe(row.eligible_views || 0)}</strong></div>
        <div class="zsky-weekly-stat"><small>Invalid / spam</small><strong>${safe(row.invalid_views || 0)} / ${safe(row.spam_views || 0)}</strong></div>
        <div class="zsky-weekly-stat"><small>Duplicate / pending</small><strong>${safe(row.duplicate_views || 0)} / ${safe(row.pending_views || 0)}</strong></div>
        <div class="zsky-weekly-stat"><small>Creator / self excluded</small><strong>${safe(row.creator_views_excluded || 0)} / ${safe(row.self_views_excluded || 0)}</strong></div>
        <div class="zsky-weekly-stat"><small>Traffic share</small><strong>${safe(Number(row.traffic_share_percent || 0).toFixed(4))}%</strong></div>
        <div class="zsky-weekly-status"><small>${readOnly ? 'Period status' : 'Review status'}</small><strong class="${weeklyStatusClass(status)}">${safe(humanStatus(status))}</strong></div>
        <div class="zsky-admin-actions zsky-weekly-actions">${actions}</div>
      </article>`;
  }

  function configurePeriodAction(period, data){
    const button = $('zskyGenerateWeeklyReview');
    const notice = $('zskyWeeklyPeriodNotice');
    if (!button || !notice) return;
    const lifecycle = String(period?.lifecycle_status || '').toUpperCase();
    const generated = Boolean(period?.generated || data?.period?.generated || data?.period?.generated_at);

    if (lifecycle === 'LIVE' || data?.read_only && data?.period?.live_preview) {
      button.disabled = true;
      button.textContent = 'Live period';
      notice.className = 'zsky-period-notice live';
      notice.innerHTML = `<strong>Live preview</strong><span>${safe(periodLabel(period))} is still running. Metrics update from current activity, but Generate, Approve and Hold stay disabled until the period closes.</span>`;
      return;
    }
    if (lifecycle === 'UPCOMING') {
      button.disabled = true;
      button.textContent = 'Upcoming';
      notice.className = 'zsky-period-notice upcoming';
      notice.innerHTML = `<strong>Upcoming period</strong><span>${safe(periodLabel(period))} has not started yet.</span>`;
      return;
    }

    button.disabled = false;
    button.textContent = generated ? 'Regenerate review' : 'Generate review';
    notice.className = 'zsky-period-notice completed';
    notice.innerHTML = generated
      ? `<strong>Completed period</strong><span>This snapshot is reviewable. Regenerate only when you intentionally want to refresh the verified metrics.</span>`
      : `<strong>Ready to review</strong><span>This period is complete. Generate the verified snapshot before approving or holding creators.</span>`;
  }

  function renderWeekly(){
    renderPeriodOptions();
    const data = zskyState.weeklyReview;
    const selected = periodMeta();
    const period = data?.period || selected || zskyState.defaultWeeklyPeriod || {};
    const lifecycle = String(period.lifecycle_status || selected?.lifecycle_status || '').toUpperCase();
    const readOnly = Boolean(data?.read_only || period.live_preview || lifecycle === 'LIVE' || lifecycle === 'UPCOMING');
    const rows = Array.isArray(data?.items) ? data.items : [];
    const paged = pageRows(rows, zskyState.weeklyPage);
    zskyState.weeklyPage = paged.page;

    configurePeriodAction({...selected, ...period}, data);

    if ($('zskyWeeklyTitle')) {
      const prefix = lifecycle === 'LIVE' ? 'Live performance' : lifecycle === 'UPCOMING' ? 'Upcoming period' : 'Calendar review';
      $('zskyWeeklyTitle').textContent = period.period_id ? `${prefix} • ${periodLabel(period)}` : 'Calendar creator review';
    }
    if ($('zskyWeeklySubtitle')) {
      $('zskyWeeklySubtitle').textContent = lifecycle === 'LIVE'
        ? 'Read-only live metrics from the current calendar period. No money or payout is calculated.'
        : lifecycle === 'UPCOMING'
          ? 'This period has not started yet.'
          : data
            ? 'Eligible traffic share is calculated only from verified guest engagement.'
            : 'This completed period has not been generated yet.';
    }

    if ($('zskyWeeklyMetrics')) {
      if (lifecycle === 'LIVE') {
        $('zskyWeeklyMetrics').innerHTML = `
          <div class="zsky-admin-metric"><span>Raw views</span><strong>${safe(period.total_raw_views || 0)}</strong></div>
          <div class="zsky-admin-metric"><span>Eligible views</span><strong>${safe(period.total_eligible_views || 0)}</strong></div>
          <div class="zsky-admin-metric warning"><span>Invalid / spam</span><strong>${safe(period.total_invalid_views || 0)} / ${safe(period.total_spam_views || 0)}</strong></div>
          <div class="zsky-admin-metric"><span>Pending views</span><strong>${safe(period.total_pending_views || 0)}</strong></div>`;
      } else if (lifecycle === 'UPCOMING') {
        $('zskyWeeklyMetrics').innerHTML = `
          <div class="zsky-admin-metric"><span>Raw views</span><strong>0</strong></div>
          <div class="zsky-admin-metric"><span>Eligible views</span><strong>0</strong></div>
          <div class="zsky-admin-metric warning"><span>Under review</span><strong>0</strong></div>
          <div class="zsky-admin-metric danger"><span>Held</span><strong>0</strong></div>`;
      } else {
        $('zskyWeeklyMetrics').innerHTML = `
          <div class="zsky-admin-metric"><span>Raw views</span><strong>${safe(period.total_raw_views || 0)}</strong></div>
          <div class="zsky-admin-metric"><span>Eligible views</span><strong>${safe(period.total_eligible_views || 0)}</strong></div>
          <div class="zsky-admin-metric warning"><span>Under review</span><strong>${safe(period.under_review_count || 0)}</strong></div>
          <div class="zsky-admin-metric danger"><span>Held</span><strong>${safe(period.held_count || 0)}</strong></div>`;
      }
    }

    if ($('zskyWeeklyList')) {
      if (paged.items.length) {
        $('zskyWeeklyList').innerHTML = paged.items.map(row => weeklyRow(row, {readOnly})).join('');
      } else if (lifecycle === 'UPCOMING') {
        $('zskyWeeklyList').innerHTML = empty('This period has not started yet. Live metrics will appear automatically when it begins.');
      } else if (lifecycle === 'LIVE') {
        $('zskyWeeklyList').innerHTML = empty('No registered creator activity has been recorded in this live period yet.');
      } else {
        $('zskyWeeklyList').innerHTML = empty(data ? 'No registered creators were found for this period.' : 'Generate this completed period to create review snapshots.');
      }
    }
    updateClientPager('zskyWeekly', paged.page, paged.maxPage);
  }

  async function loadWeeklyPeriods(force=false){
    if (zskyState.weeklyPeriodsLoaded && !force) return;
    const data = await request('weekly_periods', {params:{limit:16}, busy:false});
    zskyState.defaultWeeklyPeriod = data.default_period || null;
    zskyState.weeklyPeriods = Array.isArray(data.items) ? data.items : [];
    if (!zskyState.selectedPeriodId) zskyState.selectedPeriodId = zskyState.defaultWeeklyPeriod?.period_id || '';
    zskyState.weeklyPeriodsLoaded = true;
    renderPeriodOptions();
  }

  async function loadWeeklyReview(periodId){
    zskyState.weeklyPage = 1;
    if (!periodId) { zskyState.weeklyReview = null; renderWeekly(); return; }
    const meta = periodMeta(periodId);
    if (String(meta?.lifecycle_status || '').toUpperCase() === 'UPCOMING') {
      zskyState.weeklyReview = {period:meta, items:[], read_only:true};
      renderWeekly();
      return;
    }
    try {
      const data = await request('weekly_review', {params:{period_id:periodId}, busy:false});
      zskyState.weeklyReview = data;
    } catch (error) {
      if (error.status === 404 || error.code === 'ZNEWS_CALENDAR_REVIEW_NOT_GENERATED' || error.code === 'ZNEWS_WEEKLY_REVIEW_NOT_FOUND') {
        zskyState.weeklyReview = null;
      } else {
        throw error;
      }
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
    const meta = periodMeta(periodId);
    if (!periodId) return showToast('Select a completed period.', 'error');
    if (String(meta?.lifecycle_status || '').toUpperCase() !== 'COMPLETED') {
      return showToast('Only completed calendar periods can be generated.', 'error');
    }
    try {
      const data = await request('weekly_generate', {
        method:'POST', body:{period_id:periodId}, busyText:'Generating verified calendar review…',
      });
      zskyState.weeklyReview = data;
      zskyState.selectedPeriodId = periodId;
      zskyState.weeklyPeriodsLoaded = false;
      await loadWeeklyPeriods(true);
      renderWeekly();
      showToast('Calendar creator review generated.', 'success');
    } catch (error) {
      showToast(error.message || 'Calendar review generation failed.', 'error');
    }
  }

  async function updateWeeklyStatus(creatorUid, status, reason=''){
    const periodId = zskyState.selectedPeriodId;
    const meta = periodMeta(periodId);
    if (!periodId) return;
    if (String(meta?.lifecycle_status || '').toUpperCase() !== 'COMPLETED') {
      return showToast('Live and upcoming periods are read-only.', 'error');
    }
    await request('weekly_status', {
      method:'POST', body:{period_id:periodId, creator_uid:creatorUid, status, reason},
      busyText: status === 'HELD' ? 'Holding creator review…' : 'Approving creator review…',
    });
    await loadWeeklyReview(periodId);
    showToast(status === 'HELD' ? 'Creator review held.' : 'Creator review approved.', 'success');
  }

  function holdWeeklyReview(uid, name){
    openZSkyModal(
      'Hold creator review',
      `<p>Hold <strong>${safe(name)}</strong> for manual investigation?</p><label for="zskyWeeklyHoldReason">Reason</label><textarea id="zskyWeeklyHoldReason" class="input zsky-admin-reason" maxlength="300" placeholder="Required reason"></textarea>`,
      '<button class="btn ghost zsky-admin-button" type="button" onclick="closeModal()">Cancel</button><button class="btn danger zsky-admin-button" type="button" id="zskyConfirmWeeklyHold">Hold review</button>'
    );
    $('zskyConfirmWeeklyHold')?.addEventListener('click', async () => {
      const reason = $('zskyWeeklyHoldReason')?.value.trim() || '';
      if (!reason) return showToast('A hold reason is required.', 'error');
      closeModal();
      try { await updateWeeklyStatus(uid, 'HELD', reason); }
      catch (error) { showToast(error.message || 'Review could not be held.', 'error'); }
    }, {once:true});
  }

  function monthLabel(value){
    const monthId = typeof value === 'string' ? value : String(value?.month_id || '');
    const match = monthId.match(/^(\d{4})-(\d{2})$/);
    if (!match) return monthId;
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1));
    return new Intl.DateTimeFormat('en-GB', {month:'long', year:'numeric', timeZone:'UTC'}).format(date);
  }

  function monthlyMeta(monthId = zskyState.selectedMonthId){
    const match = zskyState.monthlyPeriods.find(row => String(row?.month_id || '') === String(monthId || ''));
    if (match) return match;
    if (String(zskyState.defaultMonth?.month_id || '') === String(monthId || '')) return zskyState.defaultMonth;
    return null;
  }

  function monthlyOptionLabel(month){
    const lifecycle = String(month?.lifecycle_status || '').toUpperCase();
    const status = lifecycle === 'LIVE' ? 'Live month' : lifecycle === 'UPCOMING' ? 'Upcoming' : 'Completed';
    return `${monthLabel(month)} • ${status}`;
  }

  function renderMonthlyOptions(){
    const map = new Map();
    if (zskyState.defaultMonth?.month_id) map.set(zskyState.defaultMonth.month_id, zskyState.defaultMonth);
    zskyState.monthlyPeriods.forEach(month => {
      if (month?.month_id) map.set(month.month_id, month);
    });
    const months = [...map.values()];
    if (!zskyState.selectedMonthId) zskyState.selectedMonthId = zskyState.defaultMonth?.month_id || months[0]?.month_id || '';
    [$('zskyMonthlySelect'), $('zskyPayoutMonthSelect')].filter(Boolean).forEach(select => {
      select.innerHTML = months.map(month => `<option value="${safe(month.month_id)}">${safe(monthlyOptionLabel(month))}</option>`).join('');
      select.value = zskyState.selectedMonthId;
    });
  }

  function monthlyReadinessLabel(row){
    if (row.payout_candidate) return 'Review ready';
    if (String(row.creator_status || '').toUpperCase() === 'BLOCKED') return 'Blocked';
    return 'Not ready';
  }

  function monthlyRow(row){
    const expected = Number(row.expected_period_count || 4);
    const approved = Number(row.approved_period_count || 0);
    const currency = String(row.wallet_currency_snapshot || '-').toUpperCase() || '-';
    const reason = row.payout_block_reason || 'Approved review data is ready for later live payout validation.';
    const statusClass = row.payout_candidate ? 'zsky-status-active' : 'zsky-status-review';
    const revenue = String(row.revenue_status || 'PENDING').toUpperCase();
    const share = revenue === 'LOCKED' ? usd(row.creator_share_usd) : humanStatus(revenue);
    const native = row.estimated_wallet_amount
      ? `${currency} ${row.estimated_wallet_amount}`
      : (row.fx_status === 'UNLOCKED' ? 'FX pending' : '-');
    return `
      <article class="zsky-weekly-row">
        <div class="zsky-admin-row-main"><strong>${safe(row.creator_name || 'Z-Pay creator')}</strong><span>${safe(row.creator_uid || '')}</span><small>${safe(reason)}</small></div>
        <div class="zsky-weekly-stat"><small>Raw / eligible</small><strong>${safe(row.raw_views || 0)} / ${safe(row.eligible_views || 0)}</strong></div>
        <div class="zsky-weekly-stat"><small>Approved eligible</small><strong>${safe(row.settlement_eligible_views || 0)}</strong></div>
        <div class="zsky-weekly-stat"><small>Reviews approved</small><strong>${safe(approved)} / ${safe(expected)}</strong></div>
        <div class="zsky-weekly-stat"><small>Traffic share</small><strong>${safe(Number(row.settlement_traffic_share_percent || 0).toFixed(4))}%</strong></div>
        <div class="zsky-weekly-stat"><small>Creator USD share</small><strong>${safe(share)}</strong></div>
        <div class="zsky-weekly-stat"><small>Native estimate</small><strong>${safe(native)}</strong></div>
        <div class="zsky-weekly-stat"><small>Currency snapshot</small><strong>${safe(currency)}</strong></div>
        <div class="zsky-weekly-status"><small>Review readiness</small><strong class="${statusClass}">${safe(monthlyReadinessLabel(row))}</strong></div>
        <div class="zsky-admin-actions zsky-weekly-actions"><div class="zsky-live-chip">Read only</div></div>
      </article>`;
  }

  function configureMonthlyNotice(data){
    const notice = $('zskyMonthlyNotice');
    if (!notice) return;
    const month = data?.month || monthlyMeta() || {};
    const summary = data?.summary || {};
    const lifecycle = String(month.lifecycle_status || '').toUpperCase();
    const generated = Number(summary.generated_period_count || 0);
    const expected = Number(summary.expected_period_count || month.expected_period_count || 4);

    if (lifecycle === 'LIVE') {
      notice.className = 'zsky-period-notice live';
      notice.innerHTML = `<strong>Month in progress</strong><span>${safe(monthLabel(month))} is still open. Approved completed periods accumulate here, but settlement readiness remains locked until the month closes.</span>`;
      return;
    }
    if (lifecycle === 'UPCOMING') {
      notice.className = 'zsky-period-notice upcoming';
      notice.innerHTML = `<strong>Upcoming month</strong><span>${safe(monthLabel(month))} has not started yet.</span>`;
      return;
    }
    if (summary.settlement_ready) {
      notice.className = 'zsky-period-notice completed';
      notice.innerHTML = summary.revenue_locked
        ? `<strong>Revenue snapshot locked</strong><span>All ${safe(expected)} calendar periods are complete. Provider revenue and creator allocations shown below are read-only; wallet credit requires the separate payout readiness flow.</span>`
        : `<strong>Performance review complete</strong><span>All ${safe(expected)} calendar periods are complete and approved eligible traffic is ready for a later revenue-allocation phase. No money is calculated here.</span>`;
      return;
    }
    notice.className = 'zsky-period-notice completed';
    notice.innerHTML = `<strong>Review incomplete</strong><span>${safe(generated)} of ${safe(expected)} calendar periods are generated, or one or more creator reviews still need approval/hold resolution. This preview cannot execute a payout.</span>`;
  }

  function renderMonthly(){
    renderMonthlyOptions();
    const data = zskyState.monthlyPreview;
    const month = data?.month || monthlyMeta() || zskyState.defaultMonth || {};
    const summary = data?.summary || {};
    const rows = Array.isArray(data?.items) ? data.items : [];
    const paged = pageRows(rows, zskyState.monthlyPage);
    zskyState.monthlyPage = paged.page;
    const expected = Number(summary.expected_period_count || month.expected_period_count || 4);
    const generated = Number(summary.generated_period_count || 0);

    configureMonthlyNotice(data);

    if ($('zskyMonthlyTitle')) $('zskyMonthlyTitle').textContent = month.month_id ? `Monthly performance • ${monthLabel(month)}` : 'Monthly creator performance';
    if ($('zskyMonthlySubtitle')) {
      const bdt = Number(summary.currency_snapshot_counts?.BDT || 0);
      const myr = Number(summary.currency_snapshot_counts?.MYR || 0);
      $('zskyMonthlySubtitle').textContent = `Read-only approved-review aggregation. Later payout candidates by snapshot: BDT ${bdt}, MYR ${myr}. Live account preflight is still required before any future payout.`;
    }

    if ($('zskyMonthlyMetrics')) {
      const locked = Boolean(summary.revenue_locked);
      $('zskyMonthlyMetrics').innerHTML = `
        <div class="zsky-admin-metric"><span>Creators</span><strong>${safe(summary.creator_count || rows.length || 0)}</strong></div>
        <div class="zsky-admin-metric"><span>Approved eligible views</span><strong>${safe(summary.settlement_eligible_views || 0)}</strong></div>
        <div class="zsky-admin-metric warning"><span>Calendar periods</span><strong>${safe(generated)} / ${safe(expected)}</strong></div>
        <div class="zsky-admin-metric"><span>Review-ready creators</span><strong>${safe(summary.payout_candidate_count || 0)}</strong></div>
        <div class="zsky-admin-metric"><span>Adsterra gross USD</span><strong>${locked ? safe(usd(summary.gross_settled_usd)) : 'Revenue pending'}</strong></div>
        <div class="zsky-admin-metric warning"><span>Safety reserve USD</span><strong>${locked ? safe(usd(summary.safety_reserve_usd)) : '-'}</strong></div>
        <div class="zsky-admin-metric"><span>Distributable USD</span><strong>${locked ? safe(usd(summary.distributable_usd)) : '-'}</strong></div>
        <div class="zsky-admin-metric"><span>Creator pool USD</span><strong>${locked ? safe(usd(summary.creator_pool_usd)) : '-'}</strong></div>
        <div class="zsky-admin-metric"><span>Platform share USD</span><strong>${locked ? safe(usd(summary.platform_share_usd)) : '-'}</strong></div>`;
    }

    if ($('zskyMonthlyList')) {
      $('zskyMonthlyList').innerHTML = paged.items.length
        ? paged.items.map(monthlyRow).join('')
        : empty(String(month.lifecycle_status || '').toUpperCase() === 'UPCOMING'
          ? 'This month has not started yet.'
          : 'No registered creator performance is available for this month.');
    }
    updateClientPager('zskyMonthly', paged.page, paged.maxPage);
  }

  async function loadMonthlyPeriods(force=false){
    if (zskyState.monthlyPeriodsLoaded && !force) return;
    const data = await request('monthly_periods', {params:{limit:12}, busy:false});
    zskyState.defaultMonth = data.default_month || null;
    zskyState.monthlyPeriods = Array.isArray(data.items) ? data.items : [];
    if (!zskyState.selectedMonthId) zskyState.selectedMonthId = zskyState.defaultMonth?.month_id || '';
    zskyState.monthlyPeriodsLoaded = true;
    renderMonthlyOptions();
  }

  async function loadMonthlyPreview(monthId){
    zskyState.monthlyPage = 1;
    if (!monthId) { zskyState.monthlyPreview = null; renderMonthly(); return; }
    zskyState.monthlyPreview = await request('monthly_preview', {params:{month_id:monthId}, busy:false});
    renderMonthly();
  }

  async function loadMonthly(force=false){
    if (zskyState.monthlyLoading) return;
    zskyState.monthlyLoading = true;
    try {
      await loadMonthlyPeriods(force);
      await loadMonthlyPreview(zskyState.selectedMonthId);
    } finally {
      zskyState.monthlyLoading = false;
    }
  }

  function renderRevenueStatus(){
    const data = zskyState.revenueStatus || {};
    const month = data.month || monthlyMeta() || {};
    const sync = data.sync || {};
    const lock = data.lock || {};
    const fx = data.fx || {};
    const locked = String(lock.status || '').toUpperCase() === 'LOCKED';
    const syncStatus = String(sync.source_status || sync.status || 'NOT_SYNCED').toUpperCase();
    const completed = Boolean(month.completed);
    if ($('zskyRevenueEstimateOverview')) {
      const value = locked ? lock.gross_settled_usd : sync.gross_settled_usd;
      $('zskyRevenueEstimateOverview').textContent = value !== undefined ? usd(value) : 'Pending';
    }
    if ($('zskyRevenueSyncOverview')) $('zskyRevenueSyncOverview').textContent = humanStatus(locked ? 'LOCKED' : syncStatus);
    if ($('zskyRevenueStatus')) {
      const providerAmount = locked
        ? usd(lock.gross_settled_usd)
        : (sync.gross_settled_usd !== undefined ? usd(sync.gross_settled_usd) : 'Pending');
      $('zskyRevenueStatus').innerHTML = `
        <div><small>Month</small><strong>${safe(monthLabel(month))}</strong></div>
        <div><small>Revenue status</small>${statusBadge(locked ? 'LOCKED' : syncStatus)}</div>
        <div><small>Provider USD</small><strong>${safe(providerAmount)}</strong></div>
        <div><small>Last sync</small><strong>${safe(timestamp(sync.synced_at))}</strong></div>
        <div><small>USD_BDT</small><strong>${safe(fx.USD_BDT?.rate || 'Unlocked')}</strong></div>
        <div><small>USD_MYR</small><strong>${safe(fx.USD_MYR?.rate || 'Unlocked')}</strong></div>
        ${data.provider_configured === false ? '<div class="zsky-settlement-warning"><strong>Private Adsterra configuration required</strong><span>Set the API token and Z Sky domain ID on the server before syncing.</span></div>' : ''}`;
    }
    if ($('zskyRevenueSyncBtn')) $('zskyRevenueSyncBtn').disabled = zskyState.revenueLoading || locked;
    if ($('zskyRevenueLockBtn')) $('zskyRevenueLockBtn').disabled = zskyState.revenueLoading || locked || !completed || syncStatus !== 'FINAL_SYNCED';
    if ($('zskyLockBdtFx')) $('zskyLockBdtFx').disabled = !completed || Boolean(fx.USD_BDT?.locked_at);
    if ($('zskyLockMyrFx')) $('zskyLockMyrFx').disabled = !completed || Boolean(fx.USD_MYR?.locked_at);
    if ($('zskyFxBdtRate') && fx.USD_BDT?.rate) $('zskyFxBdtRate').value = fx.USD_BDT.rate;
    if ($('zskyFxMyrRate') && fx.USD_MYR?.rate) $('zskyFxMyrRate').value = fx.USD_MYR.rate;
  }

  async function loadRevenueStatus(monthId=zskyState.selectedMonthId){
    if (!monthId) return;
    zskyState.revenueStatus = await request('revenue_status', {params:{month_id:monthId}, busy:false});
    renderRevenueStatus();
  }

  async function loadPayoutWorkspace(force=false){
    await loadMonthlyPeriods(force);
    renderMonthlyOptions();
    await loadRevenueStatus(zskyState.selectedMonthId);
    renderCreators();
  }

  async function syncRevenue(){
    if (zskyState.revenueLoading || !zskyState.selectedMonthId) return;
    zskyState.revenueLoading = true;
    renderRevenueStatus();
    try {
      await request('revenue_sync', {
        method:'POST', body:{month_id:zskyState.selectedMonthId}, busyText:'Synchronizing Adsterra revenue…',
      });
      await loadRevenueStatus(zskyState.selectedMonthId);
      await loadMonthlyPreview(zskyState.selectedMonthId);
      showToast('Adsterra revenue synchronized.', 'success');
    } catch (error) {
      showToast(error.message || 'Adsterra revenue could not be synchronized.', 'error');
    } finally {
      zskyState.revenueLoading = false;
      renderRevenueStatus();
    }
  }

  function confirmRevenueLock(){
    const sync = zskyState.revenueStatus?.sync || {};
    if (!sync.sync_id) return;
    openZSkyModal(
      'Lock final Adsterra revenue?',
      `<p>Lock <strong>${safe(usd(sync.gross_settled_usd))}</strong> for ${safe(monthLabel(zskyState.revenueStatus?.month || {}))}. This snapshot cannot be silently changed after payout starts.</p>`,
      '<button class="btn ghost zsky-admin-button" type="button" onclick="closeModal()">Cancel</button><button class="btn brand zsky-admin-button" type="button" id="zskyConfirmRevenueLock">Lock revenue</button>'
    );
    $('zskyConfirmRevenueLock')?.addEventListener('click', lockRevenue, {once:true});
  }

  async function lockRevenue(){
    const sync = zskyState.revenueStatus?.sync || {};
    closeModal();
    try {
      await request('revenue_lock', {
        method:'POST',
        body:{month_id:zskyState.selectedMonthId, sync_id:sync.sync_id, confirmation:'LOCK_REVENUE'},
        busyText:'Locking final provider revenue…',
      });
      await loadRevenueStatus(zskyState.selectedMonthId);
      await loadMonthlyPreview(zskyState.selectedMonthId);
      showToast('Final Adsterra revenue locked.', 'success');
    } catch (error) {
      showToast(error.message || 'Final revenue could not be locked.', 'error');
    }
  }

  async function lockPayoutFx(currency){
    currency = String(currency || '').toUpperCase();
    const input = $(currency === 'BDT' ? 'zskyFxBdtRate' : 'zskyFxMyrRate');
    const rate = input?.value.trim() || '';
    const source = $('zskyFxSource')?.value.trim() || '';
    if (!/^\d{1,4}(?:\.\d{1,6})?$/.test(rate)) return showToast('Enter a valid positive payout FX rate.', 'error');
    if (source.length < 3) return showToast('Rate source/reference is required.', 'error');
    try {
      await request('payout_fx_lock', {
        method:'POST',
        body:{month_id:zskyState.selectedMonthId, currency, rate, source_reference:source, rate_timestamp:Math.floor(Date.now()/1000), confirmation:'LOCK_FX'},
        busyText:`Locking USD_${currency} payout FX…`,
      });
      await loadRevenueStatus(zskyState.selectedMonthId);
      await loadMonthlyPreview(zskyState.selectedMonthId);
      showToast(`USD_${currency} payout FX locked.`, 'success');
    } catch (error) {
      showToast(error.message || `USD_${currency} payout FX could not be locked.`, 'error');
    }
  }

  document.addEventListener('click', event => {
    const mode = event.target.closest('[data-zsky-mode]');
    if (mode) { setMode(mode.dataset.zskyMode); return; }
    const openMode = event.target.closest('[data-zsky-open-mode]');
    if (openMode) { setMode(openMode.dataset.zskyOpenMode); return; }

    const moderationTab = event.target.closest('[data-zsky-moderation-tab]');
    if (moderationTab) {
      zskyState.moderationType = moderationTab.dataset.zskyModerationTab === 'COMMENTS' ? 'COMMENTS' : 'POSTS';
      document.querySelectorAll('[data-zsky-moderation-tab]').forEach(node => {
        const active = node === moderationTab;
        node.classList.toggle('active', active);
        node.setAttribute('aria-selected', String(active));
      });
      renderModeration();
      return;
    }

    const tab = event.target.closest('[data-zsky-creator-tab]');
    if (tab) {
      zskyState.activeStatus = tab.dataset.zskyCreatorTab === 'BLOCKED' ? 'BLOCKED' : 'ACTIVE';
      zskyState.creatorPage = 1;
      document.querySelectorAll('[data-zsky-creator-tab]').forEach(node => {
        const active = node === tab;
        node.classList.toggle('active', active);
        node.setAttribute('aria-selected', String(active));
      });
      renderCreators();
      return;
    }

    const viewPost = event.target.closest('[data-zsky-view-post]');
    if (viewPost) { openPostDetails(viewPost.dataset.zskyViewPost); return; }
    const viewComment = event.target.closest('[data-zsky-view-comment]');
    if (viewComment) { openCommentDetails(viewComment.dataset.postId, viewComment.dataset.zskyViewComment); return; }
    const postDecision = event.target.closest('[data-zsky-post-decision]');
    if (postDecision) { decidePost(postDecision.dataset.zskyPostDecision); return; }
    const commentDecision = event.target.closest('[data-zsky-comment-decision]');
    if (commentDecision) { decideComment(commentDecision.dataset.zskyCommentDecision); return; }

    const block = event.target.closest('[data-zsky-block-creator]');
    if (block) { blockCreator(block.dataset.zskyBlockCreator, block.dataset.creatorName || 'Creator'); return; }
    const unblock = event.target.closest('[data-zsky-unblock-creator]');
    if (unblock) { unblockCreator(unblock.dataset.zskyUnblockCreator, unblock.dataset.creatorName || 'Creator'); return; }
    const approve = event.target.closest('[data-zsky-weekly-approve]');
    if (approve && !approve.disabled) {
      updateWeeklyStatus(approve.dataset.zskyWeeklyApprove, 'APPROVED').catch(error => showToast(error.message || 'Review could not be approved.', 'error'));
      return;
    }
    const hold = event.target.closest('[data-zsky-weekly-hold]');
    if (hold && !hold.disabled) { holdWeeklyReview(hold.dataset.zskyWeeklyHold, hold.dataset.creatorName || 'Creator'); return; }

    if (event.target.closest('#zskyModerationPrevious')) { moveModerationPage(-1).catch(error => showToast(error.message || 'Previous page could not be loaded.', 'error')); return; }
    if (event.target.closest('#zskyModerationNext')) { moveModerationPage(1).catch(error => showToast(error.message || 'Next page could not be loaded.', 'error')); return; }
    if (event.target.closest('#zskyCreatorPrevious')) { zskyState.creatorPage = Math.max(1, zskyState.creatorPage - 1); renderCreators(); return; }
    if (event.target.closest('#zskyCreatorNext')) { zskyState.creatorPage += 1; renderCreators(); return; }
    if (event.target.closest('#zskyWeeklyPrevious')) { zskyState.weeklyPage = Math.max(1, zskyState.weeklyPage - 1); renderWeekly(); return; }
    if (event.target.closest('#zskyWeeklyNext')) { zskyState.weeklyPage += 1; renderWeekly(); return; }
    if (event.target.closest('#zskyMonthlyPrevious')) { zskyState.monthlyPage = Math.max(1, zskyState.monthlyPage - 1); renderMonthly(); return; }
    if (event.target.closest('#zskyMonthlyNext')) { zskyState.monthlyPage += 1; renderMonthly(); return; }

    if (event.target.closest('#zsky24RefreshBtn')) {
      if (zskyState.mode === 'WEEKLY') loadWeekly(true).catch(error => showToast(error.message || 'Weekly refresh failed.', 'error'));
      else if (zskyState.mode === 'MONTHLY') loadMonthly(true).catch(error => showToast(error.message || 'Monthly refresh failed.', 'error'));
      else if (zskyState.mode === 'PAYOUT') loadPayoutWorkspace(true).catch(error => showToast(error.message || 'Payout workspace refresh failed.', 'error'));
      else if (zskyState.mode === 'MODERATION') loadModeration(true).catch(error => showToast(error.message || 'Moderation refresh failed.', 'error'));
      else if (zskyState.mode === 'POLICY') loadPolicy(true).catch(error => showToast(error.message || 'Settings refresh failed.', 'error'));
      else load(true).catch(error => showToast(error.message || 'Z Sky 24 refresh failed.', 'error'));
      return;
    }
    if (event.target.closest('#zskyClearCreatorSelection')) {
      zskyState.selected.clear(); renderCreators(); return;
    }
    if (event.target.closest('#zskyPayoutPreflightBtn')) { previewPayout(); return; }
    if (event.target.closest('#zskyRevenueSyncBtn')) { syncRevenue(); return; }
    if (event.target.closest('#zskyRevenueLockBtn')) { confirmRevenueLock(); return; }
    if (event.target.closest('#zskyLockBdtFx')) { lockPayoutFx('BDT'); return; }
    if (event.target.closest('#zskyLockMyrFx')) { lockPayoutFx('MYR'); return; }
    if (event.target.closest('#zskyGenerateWeeklyReview') && !event.target.closest('#zskyGenerateWeeklyReview').disabled) generateWeekly();
  });

  document.addEventListener('change', event => {
    const checkbox = event.target.closest('[data-zsky-select-creator]');
    if (checkbox) { selectCreator(checkbox.dataset.zskySelectCreator, checkbox.checked); return; }
    if (event.target.closest('#zskyWeeklyPeriodSelect')) {
      zskyState.selectedPeriodId = event.target.value;
      loadWeeklyReview(zskyState.selectedPeriodId).catch(error => showToast(error.message || 'Calendar review could not be loaded.', 'error'));
      return;
    }
    if (event.target.closest('#zskyMonthlySelect')) {
      zskyState.selectedMonthId = event.target.value;
      loadMonthlyPreview(zskyState.selectedMonthId).catch(error => showToast(error.message || 'Monthly performance could not be loaded.', 'error'));
      return;
    }
    if (event.target.closest('#zskyPayoutMonthSelect')) {
      zskyState.selectedMonthId = event.target.value;
      zskyState.selected.clear();
      zskyState.payoutPreview = null;
      renderMonthlyOptions();
      loadRevenueStatus(zskyState.selectedMonthId).then(renderCreators).catch(error => showToast(error.message || 'Settlement month could not be loaded.', 'error'));
    }
  });

  window.loadZSky24Admin = load;
  window.dispatchEvent(new CustomEvent('zsky24:admin-ready'));
  ensureUi();
  if ($('zsky24Section')?.classList.contains('active')) {
    load().catch(error => showToast(error.message || 'Creator data could not be loaded.', 'error'));
  }
})();
