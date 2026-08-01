(() => {
  'use strict';

  const zskyState = { loaded:false, active:'settlements', settlements:[], transfers:[], impressions:[] };
  const $ = id => document.getElementById(id);
  const safe = value => esc(String(value ?? ''));
  const idempotencyKey = prefix => `${prefix}-${Date.now()}-${crypto.getRandomValues(new Uint32Array(2)).join('-')}`;
  const timestamp = value => value ? fmtTs(Number(value)) : '-';
  const amount = (value, currency) => `${safe(currency || '')} ${safe(value || '0.000000')}`.trim();

  function setText(id, value){ const node = $(id); if (node) node.textContent = String(value); }
  function flagged(row){ return Boolean(row?.reconciliation_required); }

  function updateMetrics(){
    setText('zskySettlementCount', zskyState.settlements.length);
    setText('zskyTransferCount', zskyState.transfers.length);
    setText('zskyImpressionCount', zskyState.impressions.length);
    setText('zskyReconciliationCount', [...zskyState.settlements, ...zskyState.transfers, ...zskyState.impressions].filter(flagged).length);
  }

  function empty(message){ return `<div class="empty">${safe(message)}</div>`; }

  function renderSettlements(){
    setText('zskyQueueTitle', 'Verified revenue');
    setText('zskyQueueSubtitle', 'Settlement amount is calculated only by authenticated server-side provider data.');
    if (!zskyState.settlements.length) return empty('No verified revenue is waiting for settlement.');
    return zskyState.settlements.map(row => `
      <article class="zsky-admin-row">
        <div class="zsky-admin-row-main"><strong>${safe(row.impression_id)}</strong><span>Creator: ${safe(row.creator_uid || '-')}</span></div>
        <div class="zsky-admin-cell"><small>Provider revenue</small><strong>${amount(row.reported_revenue, row.currency)}</strong></div>
        <div class="zsky-admin-cell"><small>Status</small><strong>${safe(row.settlement_status)}</strong>${flagged(row) ? '<span class="zsky-flag">Review required</span>' : ''}</div>
        <div class="zsky-admin-actions"><button class="btn ghost" data-zsky-impression="${safe(row.impression_id)}">Details</button><button class="btn brand" data-zsky-settle="${safe(row.impression_id)}" data-updated-at="${Number(row.updated_at || 0)}" ${flagged(row) || Number(row.updated_at || 0) <= 0 ? 'disabled' : ''}>Settle credit</button></div>
      </article>`).join('');
  }

  function renderTransfers(){
    setText('zskyQueueTitle', 'Creator transfer requests');
    setText('zskyQueueSubtitle', 'Review the user, source balance and destination amount before approval.');
    if (!zskyState.transfers.length) return empty('No creator transfer request is waiting for review.');
    return zskyState.transfers.map(row => `
      <article class="zsky-admin-row">
        <div class="zsky-admin-row-main"><strong>${safe(row.request_id)}</strong><span>${timestamp(row.created_at)}</span></div>
        <div class="zsky-admin-cell"><small>Source</small><strong>${amount(row.source_amount, row.source_currency)}</strong></div>
        <div class="zsky-admin-cell"><small>Destination</small><strong>${amount(row.destination_amount, row.destination_currency)}</strong>${flagged(row) ? '<span class="zsky-flag">Reconciliation</span>' : ''}</div>
        <div class="zsky-admin-actions"><button class="btn ghost" data-zsky-transfer="${safe(row.request_id)}">Review</button></div>
      </article>`).join('');
  }

  function renderImpressions(){
    setText('zskyQueueTitle', 'Ad verification review');
    setText('zskyQueueSubtitle', 'Recheck only suspicious or pending provider impressions; this does not set a client-side payout.');
    if (!zskyState.impressions.length) return empty('No ad impression needs manual review.');
    return zskyState.impressions.map(row => `
      <article class="zsky-admin-row">
        <div class="zsky-admin-row-main"><strong>${safe(row.impression_id)}</strong><span>${safe(row.creator_uid || row.uid || 'Creator not indexed')}</span></div>
        <div class="zsky-admin-cell"><small>Network</small><strong>${safe(row.network || '-')}</strong></div>
        <div class="zsky-admin-cell"><small>Status</small><strong>${safe(row.verification_status || row.status || 'REVIEW')}</strong></div>
        <div class="zsky-admin-actions"><button class="btn ghost" data-zsky-impression="${safe(row.impression_id)}">Details</button></div>
      </article>`).join('');
  }

  function render(){
    const body = $('zskyQueueBody');
    if (!body) return;
    body.innerHTML = zskyState.active === 'transfers' ? renderTransfers() : (zskyState.active === 'impressions' ? renderImpressions() : renderSettlements());
  }

  async function load(force = false){
    if (zskyState.loaded && !force) return;
    const [settlements, transfers, impressions] = await Promise.all([
      proxyGet('zsky24_settlements_queue', {limit:50}, {busy:false}),
      proxyGet('zsky24_transfers_queue', {limit:50}, {busy:false}),
      proxyGet('zsky24_impressions_queue', {limit:50}, {busy:false})
    ]);
    zskyState.settlements = Array.isArray(settlements.items) ? settlements.items : [];
    zskyState.transfers = Array.isArray(transfers.items) ? transfers.items : [];
    zskyState.impressions = Array.isArray(impressions.items) ? impressions.items : [];
    zskyState.loaded = true;
    updateMetrics(); render();
  }

  async function showImpression(id){
    const data = await proxyGet('zsky24_impression_details', {impression_id:id}, {busyText:'Loading impression...'});
    const row = data.impression || {};
    const canRecheck = Number(row.updated_at || 0) > 0;
    openDrawer('Ad impression', id, `<div class="zsky-admin-detail-grid">
      <div class="zsky-admin-detail"><small>Network</small><strong>${safe(row.network || '-')}</strong></div><div class="zsky-admin-detail"><small>Verification</small><strong>${safe(row.verification_status || '-')}</strong></div>
      <div class="zsky-admin-detail"><small>Settlement</small><strong>${safe(row.settlement_status || '-')}</strong></div><div class="zsky-admin-detail"><small>Updated</small><strong>${timestamp(row.updated_at)}</strong></div>
      <div class="zsky-admin-detail"><small>Creator</small><strong>${safe(row.creator_uid || '-')}</strong></div><div class="zsky-admin-detail"><small>Post</small><strong>${safe(row.post_id || '-')}</strong></div>
    </div><p class="zsky-admin-note">Revenue and creator credit are never accepted from the browser.</p>`, `<button class="btn ghost" onclick="closeDrawer()">Close</button>${canRecheck ? `<button class="btn blue" data-zsky-recheck="${safe(id)}" data-updated-at="${Number(row.updated_at)}">Recheck</button>` : ''}`);
  }

  async function showTransfer(id){
    const data = await proxyGet('zsky24_transfer_details', {request_id:id}, {busyText:'Loading transfer request...'});
    const row = data.request || {}, user = data.user || {}, balance = data.source_balance || {};
    const blocked = flagged(row) || Number(row.updated_at || 0) <= 0;
    openDrawer('Creator transfer', id, `<div class="zsky-admin-detail-grid">
      <div class="zsky-admin-detail"><small>Creator</small><strong>${safe(user.name || user.uid || '-')}</strong></div><div class="zsky-admin-detail"><small>User status</small><strong>${safe(user.status || '-')}</strong></div>
      <div class="zsky-admin-detail"><small>Requested</small><strong>${amount(row.source_amount, row.source_currency)}</strong></div><div class="zsky-admin-detail"><small>Destination</small><strong>${amount(row.destination_amount, row.destination_currency)}</strong></div>
      <div class="zsky-admin-detail"><small>Available source balance</small><strong>${amount(balance.available, balance.currency)}</strong></div><div class="zsky-admin-detail"><small>Status</small><strong>${safe(row.status || '-')}</strong></div>
    </div>${blocked ? '<p class="zsky-admin-note zsky-flag">This request requires reconciliation and cannot be actioned from this panel.</p>' : ''}`, `<button class="btn ghost" onclick="closeDrawer()">Close</button>${blocked ? '' : `<button class="btn danger" data-zsky-reject="${safe(id)}" data-updated-at="${Number(row.updated_at)}">Reject</button><button class="btn brand" data-zsky-approve="${safe(id)}" data-updated-at="${Number(row.updated_at)}">Approve</button>`}`);
  }

  function confirmAction(title, message, action, label){
    openModal(title, `<p>${safe(message)}</p>`, `<button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn brand" id="zskyConfirmAction">${safe(label)}</button>`);
    $('zskyConfirmAction')?.addEventListener('click', action, {once:true});
  }

  document.addEventListener('click', async event => {
    const tab = event.target.closest('[data-zsky-tab]');
    if (tab) { zskyState.active = tab.dataset.zskyTab; document.querySelectorAll('[data-zsky-tab]').forEach(node => { const active=node===tab; node.classList.toggle('active',active); node.setAttribute('aria-selected', String(active)); }); render(); return; }
    const details = event.target.closest('[data-zsky-impression]'); if (details) { await showImpression(details.dataset.zskyImpression); return; }
    const transfer = event.target.closest('[data-zsky-transfer]'); if (transfer) { await showTransfer(transfer.dataset.zskyTransfer); return; }
    const settle = event.target.closest('[data-zsky-settle]'); if (settle) confirmAction('Settle creator credit','Settle this verified provider impression using the server-calculated creator share?', async()=>{ closeModal(); await proxyPost('zsky24_settlement_settle',{impression_id:settle.dataset.zskySettle,expected_updated_at:Number(settle.dataset.updatedAt),idempotency_key:idempotencyKey('settle')},true,{busyText:'Settling verified revenue...'}); closeDrawer(); await load(true); showToast('Creator credit settled.','success'); },'Settle credit');
    const approve = event.target.closest('[data-zsky-approve]'); if (approve) confirmAction('Approve transfer','Approve this reviewed creator transfer into the linked Z-Pay wallet?',async()=>{ closeModal(); await proxyPost('zsky24_transfer_approve',{request_id:approve.dataset.zskyApprove,expected_updated_at:Number(approve.dataset.updatedAt),idempotency_key:idempotencyKey('approve')},true,{busyText:'Approving transfer...'}); closeDrawer(); await load(true); showToast('Transfer approved.','success'); },'Approve');
    const reject = event.target.closest('[data-zsky-reject]'); if (reject) { openModal('Reject transfer','<label for="zskyRejectReason">Reason</label><textarea id="zskyRejectReason" class="input zsky-admin-reason" maxlength="240" placeholder="Give a clear review reason"></textarea>','<button class="btn ghost" onclick="closeModal()">Cancel</button><button class="btn danger" id="zskyConfirmReject">Reject</button>'); $('zskyConfirmReject')?.addEventListener('click',async()=>{ const reason=$('zskyRejectReason')?.value.trim()||''; if(!reason){showToast('A rejection reason is required.','error');return;} closeModal(); await proxyPost('zsky24_transfer_reject',{request_id:reject.dataset.zskyReject,expected_updated_at:Number(reject.dataset.updatedAt),idempotency_key:idempotencyKey('reject'),reason},true,{busyText:'Rejecting transfer...'}); closeDrawer(); await load(true); showToast('Transfer rejected.','success'); },{once:true}); }
    const recheck = event.target.closest('[data-zsky-recheck]'); if (recheck) confirmAction('Recheck impression','Run the existing server-side verification checks again?',async()=>{ closeModal(); await proxyPost('zsky24_impression_recheck',{impression_id:recheck.dataset.zskyRecheck,expected_updated_at:Number(recheck.dataset.updatedAt),idempotency_key:idempotencyKey('recheck')},true,{busyText:'Rechecking impression...'}); closeDrawer(); await load(true); showToast('Impression rechecked.','success'); },'Recheck');
  });

  $('zsky24RefreshBtn')?.addEventListener('click', () => load(true).catch(error => showToast(error.message || 'Refresh failed.','error')));
  window.loadZSky24Admin = load;
})();
