// Z-Pay Swift user dashboard UX helper.
// Quick services + bKash/Nagad frontend flow.
// MFS create uses a relative endpoint so it works under any install folder.
(function(){
  'use strict';

  function byId(id){ return document.getElementById(id); }
  function money(v){ var n = Number(v || 0); return Number.isFinite(n) ? n.toFixed(2) : '0.00'; }
  function esc(v){
    return String(v == null ? '' : v).replace(/[&<>"']/g, function(s){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s];
    });
  }

  var serverPreview = null;

  function openSection(id){
    if (typeof window.openSection === 'function') { window.openSection(id); return; }
    document.querySelectorAll('.page-section').forEach(function(n){ n.classList.remove('active'); });
    document.querySelectorAll('.side-btn,.bottom-btn').forEach(function(n){
      n.classList.toggle('active', n.getAttribute('data-page-section') === id);
    });
    var section = byId(id);
    if (section) section.classList.add('active');
  }

  function selectedProvider(){
    var active = document.querySelector('.mfs-provider-choice.active');
    return String(active ? active.getAttribute('data-provider') || '' : 'BKASH').toUpperCase();
  }

  function providerName(p){
    p = String(p || '').toUpperCase();
    if (p === 'NAGAD') return 'Nagad';
    if (p === 'BKASH') return 'bKash';
    return p || 'bKash';
  }

  function setProvider(provider){
    provider = String(provider || 'BKASH').toUpperCase();
    document.querySelectorAll('.mfs-provider-choice').forEach(function(btn){
      btn.classList.toggle('active', String(btn.getAttribute('data-provider') || '').toUpperCase() === provider);
    });
    var title = document.querySelector('#mfsSection .section-title');
    var sub = document.querySelector('#mfsSection .section-sub');
    if (title) title.textContent = providerName(provider) + ' Send Money';
    if (sub) sub.textContent = 'Personal ' + providerName(provider) + ' request. Review, PIN confirm and Telegram approval.';
    renderPreview();
  }

  function data(){
    return {
      provider: selectedProvider(),
      receiver_number: String(byId('mfsReceiverNumber') ? byId('mfsReceiverNumber').value : '').trim(),
      amount_bdt: Number(byId('mfsAmountBdt') ? byId('mfsAmountBdt').value || 0 : 0),
      amount_rm: Number(byId('mfsAmountRm') ? byId('mfsAmountRm').value || 0 : 0),
      reference: String(byId('mfsReference') ? byId('mfsReference').value : '').trim(),
      pin: String(byId('mfsPin') ? byId('mfsPin').value : '').trim()
    };
  }

  function previewHtml(d){
    var p = serverPreview || {};
    var currency = String(p.wallet_currency || '').toUpperCase();
    var country = String(p.country_code || '').toUpperCase();
    var mode = String(p.service_mode || '').toUpperCase();
    var feeText = currency === 'MYR'
      ? 'RM ' + money(p.fee_myr || p.fee_rm || 0)
      : 'BDT ' + money(p.fee_bdt || 0);
    if (mode === 'REMITTANCE' && currency !== 'MYR') {
      feeText += ' / RM ' + money(p.fee_myr || p.fee_rm || 0);
    }
    var totalText = currency === 'MYR'
      ? 'RM ' + money(p.total_pay_myr || p.total_debit_rm || p.wallet_hold_amount || 0)
      : 'BDT ' + money(p.total_pay_bdt || p.total_debit_bdt || p.wallet_hold_amount || 0);
    var rate = Number(p.rate_myr_to_bdt || p.exchange_rate || 0);
    return '' +
      '<div class="zpay-mfs-preview-row"><span>Provider</span><b>' + esc(providerName(d.provider)) + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Receiver</span><b>' + esc(d.receiver_number || '-') + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Country</span><b>' + esc(country || 'Auto from profile/phone') + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Role</span><b>' + esc(p.role || 'USER') + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Mode</span><b>' + esc(mode || 'Auto') + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Amount BDT</span><b>BDT ' + money(p.amount_bdt || d.amount_bdt) + '</b></div>' +
      ((p.amount_myr || p.amount_rm || d.amount_rm) ? '<div class="zpay-mfs-preview-row"><span>Amount RM</span><b>RM ' + money(p.amount_myr || p.amount_rm || d.amount_rm) + '</b></div>' : '') +
      (rate > 0 ? '<div class="zpay-mfs-preview-row"><span>Rate</span><b>RM 1 = BDT ' + money(rate) + '</b></div>' : '') +
      '<div class="zpay-mfs-preview-row"><span>Fee</span><b>' + esc(feeText) + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Total Hold</span><b>' + esc(totalText) + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Reference</span><b>' + esc(d.reference || '-') + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Status</span><b>PENDING</b></div>';
  }

  function renderPreview(){
    var d = data();
    var live = byId('mfsLivePreview') || byId('mfsPreviewBox');
    var details = byId('mfsPreviewDetails');
    if (live) { live.className = 'bundle-result-box'; live.innerHTML = previewHtml(d); }
    if (details) details.innerHTML = previewHtml(d);
  }

  async function loadServerPreview(){
    var d = data();
    if (typeof window.proxyPost !== 'function') {
      serverPreview = null;
      renderPreview();
      return;
    }

    serverPreview = await window.proxyPost('mfs_preview', {
      provider: d.provider,
      service_type: 'SEND_MONEY',
      account_type: 'PERSONAL',
      receiver_number: d.receiver_number,
      amount_bdt: d.amount_bdt,
      amount_rm: d.amount_rm,
      amount_myr: d.amount_rm,
      reference: d.reference
    }, 'Loading MFS preview...', { busy: false });
    renderPreview();
  }

  function showStep(id){
    ['mfsStepForm','mfsStepPreview','mfsStepPin'].forEach(function(stepId){
      var n = byId(stepId);
      if (n) n.classList.remove('active');
    });
    var target = byId(id);
    if (target) target.classList.add('active');
  }

  function validBase(){
    var d = data();
    var num = d.receiver_number.replace(/\D+/g, '');
    if (!/^01\d{9}$/.test(num)) { if (typeof showToast === 'function') showToast('Receiver number must be 11 digit BD number', 'error'); return false; }
    if (d.amount_bdt <= 0 && d.amount_rm <= 0) { if (typeof showToast === 'function') showToast('Amount is required', 'error'); return false; }
    if (d.amount_bdt > 0 && (d.amount_bdt < 500 || d.amount_bdt > 50000)) { if (typeof showToast === 'function') showToast('Amount must be between BDT 500 and BDT 50,000', 'error'); return false; }
    return true;
  }

  async function createWithTelegramButtons(d){
    var payload = {
      provider: d.provider,
      service_type: 'SEND_MONEY',
      account_type: 'PERSONAL',
      receiver_number: d.receiver_number,
      amount_bdt: d.amount_bdt,
      amount_rm: d.amount_rm,
      amount_myr: d.amount_rm,
      reference: d.reference,
      pin: d.pin,
      note: providerName(d.provider) + ' request from user panel'
    };
    var res = await fetch('mfs_create_telegram.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/json','Accept':'application/json','Cache-Control':'no-cache'},
      body: JSON.stringify(payload)
    });
    var text = await res.text();
    var json;
    try { json = JSON.parse(text); } catch(e) { throw new Error(text || 'Invalid server response'); }
    if (!res.ok || !json.ok) throw new Error(json.message || 'Failed to create request');
    return json.data || {};
  }

  async function confirmMfs(){
    var d = data();
    if (!validBase()) return;
    if (!d.pin) { if (typeof showToast === 'function') showToast('PIN is required', 'error'); return; }
    try {
      if (typeof setBusy === 'function') setBusy(true, 'Creating request...');
      var result = await createWithTelegramButtons(d);
      if (typeof renderMfsResultSuccess === 'function') renderMfsResultSuccess(result);
      if (typeof applyMfsCreateSuccessToLocalState === 'function') applyMfsCreateSuccessToLocalState(result);
      if (byId('mfsPin')) byId('mfsPin').value = '';
      var okTelegram = !!(result.telegram && result.telegram.ok);
      if (typeof showToast === 'function') showToast(okTelegram ? 'Request created with Telegram buttons' : 'Request created, Telegram send failed', okTelegram ? 'ok' : 'error');
      setTimeout(function(){ openSection('historySection'); }, 700);
    } catch(e) {
      if (typeof renderMfsResultError === 'function') renderMfsResultError(e.message || 'Failed to create request');
      if (typeof showToast === 'function') showToast(e.message || 'Failed to create request', 'error');
    } finally {
      if (typeof setBusy === 'function') setBusy(false);
    }
  }

  function quickButton(icon, label, section, provider){
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'zpay-service-btn';
    btn.innerHTML = '<span class="zpay-service-icon">' + icon + '</span><span class="zpay-service-name">' + label + '</span>';
    btn.addEventListener('click', function(){
      if (provider) setProvider(provider);
      openSection(section);
      if (provider) setTimeout(function(){ var n = byId('mfsReceiverNumber'); if (n) n.focus(); }, 150);
    });
    return btn;
  }

  function ensureQuickActions(){
    if (byId('zpayQuickActions')) return;
    var hero = document.querySelector('.hero-card');
    if (!hero || !hero.parentNode) return;
    var card = document.createElement('div');
    card.id = 'zpayQuickActions';
    card.className = 'zpay-quick-card';
    card.innerHTML = '<div class="zpay-quick-head"><div><h3 class="zpay-quick-title">Quick Services</h3><p class="zpay-quick-sub">Fast access to Z-Pay Swift services</p></div><div class="zpay-rate-chip">Fast - Secure</div></div>';
    var grid = document.createElement('div');
    grid.className = 'zpay-service-grid';
    grid.appendChild(quickButton('T', 'Topup', 'topupSection'));
    grid.appendChild(quickButton('b', 'bKash', 'mfsSection', 'BKASH'));
    grid.appendChild(quickButton('N', 'Nagad', 'mfsSection', 'NAGAD'));
    grid.appendChild(quickButton('B', 'Bundle', 'bundleSection'));
    grid.appendChild(quickButton('H', 'History', 'historySection'));
    card.appendChild(grid);
    hero.insertAdjacentElement('afterend', card);
  }

  function bindMfs(){
    if (window.__zpayMfsFlowFixBound) return;
    window.__zpayMfsFlowFixBound = true;
    document.querySelectorAll('.mfs-provider-choice').forEach(function(btn){ btn.addEventListener('click', function(){ setProvider(btn.getAttribute('data-provider') || 'BKASH'); }); });
    ['mfsReceiverNumber','mfsAmountBdt','mfsAmountRm','mfsReference'].forEach(function(id){ var n = byId(id); if (n) n.addEventListener('input', function(){ serverPreview = null; renderPreview(); }); });
    var preview = byId('mfsPreviewBtn'); if (preview) preview.addEventListener('click', async function(e){
      e.preventDefault();
      if (!validBase()) return;
      try {
        await loadServerPreview();
        showStep('mfsStepPreview');
      } catch(err) {
        if (typeof showToast === 'function') showToast(err.message || 'Failed to load MFS preview', 'error');
      }
    });
    var back = byId('mfsBackBtn'); if (back) back.addEventListener('click', function(e){ e.preventDefault(); showStep('mfsStepForm'); });
    var send = byId('mfsSendBtn'); if (send) send.addEventListener('click', function(e){ e.preventDefault(); renderPreview(); if (validBase()) showStep('mfsStepPin'); });
    var pinBack = byId('mfsPinBackBtn'); if (pinBack) pinBack.addEventListener('click', function(e){ e.preventDefault(); showStep('mfsStepPreview'); });
    var confirm = byId('mfsConfirmBtn'); if (confirm) confirm.addEventListener('click', function(e){ e.preventDefault(); confirmMfs(); });
    var pin = byId('mfsPin'); if (pin) pin.addEventListener('keydown', function(e){ if (e.key === 'Enter') confirmMfs(); });
    setProvider(selectedProvider());
    renderPreview();
  }

  function init(){ ensureQuickActions(); bindMfs(); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
