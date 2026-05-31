// Z-Pay Swift user dashboard UX helper.
// This file adds mobile-friendly quick actions and fixes the MFS step flow without changing backend routes.
(function(){
  'use strict';

  function byId(id){
    return document.getElementById(id);
  }

  function money(value){
    var n = Number(value || 0);
    return Number.isFinite(n) ? n.toFixed(2) : '0.00';
  }

  function html(value){
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(s){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[s];
    });
  }

  function openDashboardSection(sectionId){
    if (typeof window.openSection === 'function') {
      window.openSection(sectionId);
      return;
    }

    document.querySelectorAll('.page-section').forEach(function(node){
      node.classList.remove('active');
    });

    document.querySelectorAll('.side-btn,.bottom-btn').forEach(function(node){
      node.classList.toggle('active', node.getAttribute('data-page-section') === sectionId);
    });

    var section = byId(sectionId);
    if (section) section.classList.add('active');
  }

  function createButton(icon, label, sectionId){
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'zpay-service-btn';
    btn.setAttribute('data-page-section', sectionId);
    btn.innerHTML = '<span class="zpay-service-icon">' + icon + '</span><span class="zpay-service-name">' + label + '</span>';
    btn.addEventListener('click', function(){
      openDashboardSection(sectionId);
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

    var head = document.createElement('div');
    head.className = 'zpay-quick-head';
    head.innerHTML = '<div><h3 class="zpay-quick-title">Quick Services</h3><p class="zpay-quick-sub">Fast access to the most used Z-Pay Swift services</p></div><div class="zpay-rate-chip">Fast • Secure</div>';

    var grid = document.createElement('div');
    grid.className = 'zpay-service-grid';
    grid.appendChild(createButton('↗', 'Topup', 'topupSection'));
    grid.appendChild(createButton('৳', 'bKash/Nagad', 'mfsSection'));
    grid.appendChild(createButton('▣', 'Bundle', 'bundleSection'));
    grid.appendChild(createButton('⌕', 'History', 'historySection'));

    card.appendChild(head);
    card.appendChild(grid);
    hero.insertAdjacentElement('afterend', card);
  }

  function selectedMfsProvider(){
    var active = document.querySelector('.mfs-provider-choice.active');
    return String(active ? active.getAttribute('data-provider') || '' : 'BKASH').toUpperCase();
  }

  function setSelectedMfsProvider(provider){
    provider = String(provider || 'BKASH').toUpperCase();
    document.querySelectorAll('.mfs-provider-choice').forEach(function(btn){
      btn.classList.toggle('active', String(btn.getAttribute('data-provider') || '').toUpperCase() === provider);
    });
    renderMfsLivePreview();
  }

  function mfsData(){
    return {
      provider: selectedMfsProvider(),
      receiver_number: String(byId('mfsReceiverNumber') ? byId('mfsReceiverNumber').value : '').trim(),
      amount_bdt: Number(byId('mfsAmountBdt') ? byId('mfsAmountBdt').value || 0 : 0),
      amount_rm: Number(byId('mfsAmountRm') ? byId('mfsAmountRm').value || 0 : 0),
      reference: String(byId('mfsReference') ? byId('mfsReference').value : '').trim(),
      pin: String(byId('mfsPin') ? byId('mfsPin').value : '').trim()
    };
  }

  function mfsProviderName(provider){
    provider = String(provider || '').toUpperCase();
    if (provider === 'BKASH') return 'bKash';
    if (provider === 'NAGAD') return 'Nagad';
    return provider || '-';
  }

  function mfsPreviewHtml(data){
    return '' +
      '<div class="zpay-mfs-preview-row"><span>Provider</span><b>' + html(mfsProviderName(data.provider)) + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Receiver</span><b>' + html(data.receiver_number || '-') + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Amount BDT</span><b>BDT ' + money(data.amount_bdt || 0) + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Amount RM</span><b>RM ' + money(data.amount_rm || 0) + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Reference</span><b>' + html(data.reference || '-') + '</b></div>' +
      '<div class="zpay-mfs-preview-row"><span>Status</span><b>PENDING</b></div>';
  }

  function renderMfsLivePreview(){
    var data = mfsData();
    var live = byId('mfsLivePreview') || byId('mfsPreviewBox');
    var details = byId('mfsPreviewDetails');

    if (live) {
      live.className = 'bundle-result-box';
      live.innerHTML = mfsPreviewHtml(data);
    }

    if (details) {
      details.innerHTML = mfsPreviewHtml(data);
    }
  }

  function showMfsStep(step){
    ['mfsStepForm','mfsStepPreview','mfsStepPin'].forEach(function(id){
      var node = byId(id);
      if (node) node.classList.remove('active');
    });

    var target = byId(step);
    if (target) target.classList.add('active');
  }

  function validateMfsBase(){
    var data = mfsData();
    var digits = data.receiver_number.replace(/\D+/g, '');

    if (!data.provider) {
      if (typeof showToast === 'function') showToast('Please select bKash or Nagad', 'error');
      return false;
    }

    if (!/^01\d{9}$/.test(digits)) {
      if (typeof showToast === 'function') showToast('Receiver number must be 11 digit BD number', 'error');
      return false;
    }

    if (data.amount_bdt <= 0 && data.amount_rm <= 0) {
      if (typeof showToast === 'function') showToast('Amount is required', 'error');
      return false;
    }

    return true;
  }

  function goMfsPreview(){
    renderMfsLivePreview();
    if (!validateMfsBase()) return;
    showMfsStep('mfsStepPreview');
  }

  function goMfsPin(){
    renderMfsLivePreview();
    if (!validateMfsBase()) return;
    showMfsStep('mfsStepPin');
    setTimeout(function(){
      var pin = byId('mfsPin');
      if (pin) pin.focus();
    }, 120);
  }

  async function confirmMfsRequest(){
    var data = mfsData();

    if (!validateMfsBase()) return;

    if (!data.pin) {
      if (typeof showToast === 'function') showToast('PIN is required', 'error');
      return;
    }

    if (typeof proxyPost !== 'function') {
      if (typeof showToast === 'function') showToast('Dashboard API helper missing', 'error');
      return;
    }

    try{
      var res = await proxyPost('mfs_create', {
        provider: data.provider,
        service_type: 'SEND_MONEY',
        account_type: 'PERSONAL',
        receiver_number: data.receiver_number,
        amount_bdt: data.amount_bdt,
        amount_rm: data.amount_rm,
        reference: data.reference,
        pin: data.pin,
        note: 'MFS request from user panel'
      }, 'Creating request...');

      if (typeof renderMfsResultSuccess === 'function') renderMfsResultSuccess(res);
      if (typeof applyMfsCreateSuccessToLocalState === 'function') applyMfsCreateSuccessToLocalState(res);
      if (typeof showToast === 'function') showToast('Request created successfully', 'ok');

      if (byId('mfsPin')) byId('mfsPin').value = '';

      setTimeout(function(){
        openDashboardSection('historySection');
      }, 600);
    }catch(err){
      if (typeof renderMfsResultError === 'function') renderMfsResultError(err.message || 'Failed to create request');
      if (typeof showToast === 'function') showToast(err.message || 'Failed to create request', 'error');
    }
  }

  function bindMfsFlowFix(){
    if (window.__zpayMfsFlowFixBound) return;
    window.__zpayMfsFlowFixBound = true;

    document.querySelectorAll('.mfs-provider-choice').forEach(function(btn){
      btn.addEventListener('click', function(){
        setSelectedMfsProvider(btn.getAttribute('data-provider') || '');
      });
    });

    ['mfsReceiverNumber','mfsAmountBdt','mfsAmountRm','mfsReference'].forEach(function(id){
      var node = byId(id);
      if (node) node.addEventListener('input', renderMfsLivePreview);
    });

    var previewBtn = byId('mfsPreviewBtn');
    if (previewBtn) previewBtn.addEventListener('click', function(e){ e.preventDefault(); goMfsPreview(); });

    var backBtn = byId('mfsBackBtn');
    if (backBtn) backBtn.addEventListener('click', function(e){ e.preventDefault(); showMfsStep('mfsStepForm'); });

    var sendBtn = byId('mfsSendBtn');
    if (sendBtn) sendBtn.addEventListener('click', function(e){ e.preventDefault(); goMfsPin(); });

    var pinBackBtn = byId('mfsPinBackBtn');
    if (pinBackBtn) pinBackBtn.addEventListener('click', function(e){ e.preventDefault(); showMfsStep('mfsStepPreview'); });

    var confirmBtn = byId('mfsConfirmBtn');
    if (confirmBtn) confirmBtn.addEventListener('click', function(e){ e.preventDefault(); confirmMfsRequest(); });

    var pinInput = byId('mfsPin');
    if (pinInput) {
      pinInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter') confirmMfsRequest();
      });
    }

    setSelectedMfsProvider(selectedMfsProvider());
    renderMfsLivePreview();
  }

  function init(){
    ensureQuickActions();
    bindMfsFlowFix();

    var observer = new MutationObserver(function(){
      if (document.body.classList.contains('user-authenticated')) {
        ensureQuickActions();
        bindMfsFlowFix();
      }
    });

    observer.observe(document.body, { attributes:true, attributeFilter:['class'] });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
