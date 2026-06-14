(function(){
  const state = window.subadminState;
  if (!state) return;

  function getUserRow(uid){
    return (state.users || []).find(item => String(item.uid || '') === String(uid)) || null;
  }

  function setText(id, text){
    const el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  function setStatus(id, message, type = 'info'){
    const el = document.getElementById(id);
    if (!el) return;
    el.className = 'status-note ' + type;
    el.textContent = message;
  }

  function openModal(id){
    const el = document.getElementById(id);
    if (el) el.classList.add('open');
  }

  function closeModal(id){
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
  }

  function setButtonLoading(btnId, loading, loadingText){
    const btn = document.getElementById(btnId);
    if (!btn) return;

    if (loading) {
      if (!btn.dataset.originalText) btn.dataset.originalText = btn.textContent;
      btn.textContent = loadingText;
      btn.disabled = true;
      btn.style.opacity = '0.7';
      btn.style.cursor = 'not-allowed';
    } else {
      btn.textContent = btn.dataset.originalText || btn.textContent;
      btn.disabled = false;
      btn.style.opacity = '';
      btn.style.cursor = '';
    }
  }

  function fillDeductBaseInfo(row){
    const currency = window.walletNativeCurrency
      ? window.walletNativeCurrency(row)
      : (String(row?.pricing_country || row?.country_code || '').toUpperCase() === 'MY' ? 'MYR' : 'BDT');
    const prefix = currency === 'MYR' ? 'RM' : 'BDT';
    setText('deductTargetName', row.name || '-');
    setText('deductTargetPhone', row.phone || '-');
    setText('deductTargetBalance', prefix + ' ' + window.money(row.available_balance || 0));
    setText('deductTargetRole', row.role || '-');
    setText('deductTargetCurrency', currency === 'MYR' ? 'MYR (RM)' : 'BDT');
    setText('deductAmountLabel', `Deduct Amount (${prefix})`);
  }

  function fillConfirmInfo(){
    const prefix = state.deductOtp.targetCurrency === 'MYR' ? 'RM' : 'BDT';
    setText('deductConfirmName', state.deductOtp.targetName || '-');
    setText('deductConfirmPhone', state.deductOtp.targetPhone || '-');
    setText('deductConfirmAmount', prefix + ' ' + window.money(state.deductOtp.amount || 0));
    setText('deductConfirmRole', state.deductOtp.targetRole || '-');
  }

  function resetDeductOtpState(){
    state.deductOtp = {
      targetUid: '',
      otpRequestId: '',
      targetName: '',
      targetPhone: '',
      targetRole: '',
      targetCurrency: 'BDT',
      amount: 0,
      note: ''
    };

    const amountInput = document.getElementById('deductAmountInput');
    const noteInput = document.getElementById('deductNoteInput');
    const otpInput = document.getElementById('deductOtpCodeInput');

    if (amountInput) amountInput.value = '';
    if (noteInput) noteInput.value = '';
    if (otpInput) otpInput.value = '';

    setText('deductTargetName', '-');
    setText('deductTargetPhone', '-');
    setText('deductTargetBalance', '0.00');
    setText('deductTargetRole', '-');
    setText('deductTargetCurrency', 'BDT');
    setText('deductAmountLabel', 'Deduct Amount (BDT)');

    setText('deductConfirmName', '-');
    setText('deductConfirmPhone', '-');
    setText('deductConfirmAmount', 'BDT 0.00');
    setText('deductConfirmRole', '-');

    setStatus('deductOtpSendStatus', 'OTP এখনো পাঠানো হয়নি।', 'info');
    setStatus('deductOtpConfirmStatus', 'OTP পাঠানোর পরে এখানে confirmation status দেখাবে।', 'info');
  }

  function closeDeductOtpModal(){
    closeModal('deductOtpModalWrap');
  }

  function closeDeductConfirmModal(){
    closeModal('deductConfirmModalWrap');
    const otpInput = document.getElementById('deductOtpCodeInput');
    if (otpInput) otpInput.value = '';
  }

  function openDeductOtpModal(uid){
    const row = getUserRow(uid);
    if (!row) {
      alert('User not found');
      return;
    }

    resetDeductOtpState();

    state.deductOtp.targetUid = String(row.uid || '');
    state.deductOtp.targetName = String(row.name || '');
    state.deductOtp.targetPhone = String(row.phone || '');
    state.deductOtp.targetRole = String(row.role || '');
    state.deductOtp.targetCurrency = window.walletNativeCurrency
      ? window.walletNativeCurrency(row)
      : (String(row.pricing_country || row.country_code || '').toUpperCase() === 'MY' ? 'MYR' : 'BDT');

    fillDeductBaseInfo(row);
    openModal('deductOtpModalWrap');
  }

  async function sendDeductOtp(){
    const uid = state.deductOtp.targetUid;
    const amount = Number(document.getElementById('deductAmountInput')?.value || 0);
    const note = (document.getElementById('deductNoteInput')?.value || '').trim();

    if (!uid) {
      alert('Target user missing');
      return;
    }

    if (amount <= 0) {
      alert('Enter valid deduction amount');
      return;
    }

    state.deductOtp.amount = amount;
    state.deductOtp.note = note;

    setButtonLoading('sendDeductOtpBtn', true, 'Sending...');
    setStatus('deductOtpSendStatus', 'OTP পাঠানো হচ্ছে...', 'info');

    try{
      const data = await window.proxyPost('wallet_deduct_send_otp', {
        uid,
        amount,
        note
      }, 'Sending OTP...');

      state.deductOtp.otpRequestId = String(data.otp_request_id || '');
      state.deductOtp.targetCurrency = String(
        data.currency || data.wallet_currency || state.deductOtp.targetCurrency || 'BDT'
      ).toUpperCase() === 'MYR' ? 'MYR' : 'BDT';

      fillConfirmInfo();

      setStatus(
        'deductOtpSendStatus',
        `OTP পাঠানো হয়েছে ${data.masked_phone || state.deductOtp.targetPhone} নাম্বারে। 5 মিনিটের মধ্যে কোড দিন।`,
        'success'
      );

      setStatus(
        'deductOtpConfirmStatus',
        `OTP sent to ${data.masked_phone || state.deductOtp.targetPhone}. Amount: ${state.deductOtp.targetCurrency === 'MYR' ? 'RM' : 'BDT'} ${window.money(amount)}.`,
        'success'
      );

      closeDeductOtpModal();
      openModal('deductConfirmModalWrap');
      window.showToast('OTP sent successfully', 'ok');
    }catch(err){
      setStatus(
        'deductOtpSendStatus',
        err.message || 'OTP পাঠানো যায়নি।',
        'error'
      );
      window.showToast(err.message || 'Failed to send OTP', 'error');
    }finally{
      setButtonLoading('sendDeductOtpBtn', false, 'Sending...');
    }
  }

  async function resendDeductOtp(){
    const uid = state.deductOtp.targetUid;
    const amount = Number(state.deductOtp.amount || 0);
    const note = state.deductOtp.note || '';

    if (!uid || amount <= 0) {
      alert('OTP request তথ্য পাওয়া যায়নি');
      return;
    }

    setButtonLoading('resendDeductOtpBtn', true, 'Resending...');
    setStatus('deductOtpConfirmStatus', 'OTP আবার পাঠানো হচ্ছে...', 'info');

    try{
      const data = await window.proxyPost('wallet_deduct_send_otp', {
        uid,
        amount,
        note
      }, 'Resending OTP...');

      state.deductOtp.otpRequestId = String(data.otp_request_id || '');
      state.deductOtp.targetCurrency = String(
        data.currency || data.wallet_currency || state.deductOtp.targetCurrency || 'BDT'
      ).toUpperCase() === 'MYR' ? 'MYR' : 'BDT';

      setStatus(
        'deductOtpConfirmStatus',
        `New OTP sent to ${data.masked_phone || state.deductOtp.targetPhone}. Amount: ${state.deductOtp.targetCurrency === 'MYR' ? 'RM' : 'BDT'} ${window.money(amount)}.`,
        'success'
      );

      window.showToast('OTP resent successfully', 'ok');
    }catch(err){
      setStatus(
        'deductOtpConfirmStatus',
        err.message || 'OTP আবার পাঠানো যায়নি।',
        'error'
      );
      window.showToast(err.message || 'Failed to resend OTP', 'error');
    }finally{
      setButtonLoading('resendDeductOtpBtn', false, 'Resending...');
    }
  }

  async function confirmDeduction(){
    const otp = (document.getElementById('deductOtpCodeInput')?.value || '').trim();
    const otpRequestId = state.deductOtp.otpRequestId;

    if (!otpRequestId) {
      alert('OTP request missing');
      return;
    }

    if (!/^\d{4,8}$/.test(otp)) {
      alert('Enter valid OTP');
      return;
    }

    setButtonLoading('confirmDeductOtpBtn', true, 'Confirming...');
    setStatus('deductOtpConfirmStatus', 'OTP যাচাই হচ্ছে...', 'info');

    try{
      const data = await window.proxyPost('wallet_deduct_confirm', {
        otp_request_id: otpRequestId,
        otp: otp
      }, 'Confirming deduction...');

      const deductedAmount = Number(data.amount || state.deductOtp.amount || 0);
      const currency = String(data.currency || data.wallet_currency || state.deductOtp.targetCurrency || 'BDT').toUpperCase();
      const prefix = currency === 'MYR' ? 'RM' : 'BDT';

      setStatus(
        'deductOtpConfirmStatus',
        `Successfully deducted ${prefix} ${window.money(deductedAmount)} from ${state.deductOtp.targetName}.`,
        'success'
      );

      await Promise.all([
        window.loadWallet(),
        window.loadLogs(),
        window.loadUsers()
      ]);

      window.renderSummary();
      window.renderLogs();
      window.renderUsers();
      window.renderPanelTopupRequests();

      window.showToast('Balance deducted successfully', 'ok');

      setTimeout(() => {
        closeDeductConfirmModal();
        resetDeductOtpState();
      }, 900);
    }catch(err){
      setStatus(
        'deductOtpConfirmStatus',
        err.message || 'OTP confirm failed.',
        'error'
      );
      window.showToast(err.message || 'Failed to confirm OTP', 'error');
    }finally{
      setButtonLoading('confirmDeductOtpBtn', false, 'Confirming...');
    }
  }

  document.getElementById('sendDeductOtpBtn')?.addEventListener('click', sendDeductOtp);

  document.getElementById('resetDeductOtpBtn')?.addEventListener('click', () => {
    const uid = state.deductOtp.targetUid;
    const row = uid ? getUserRow(uid) : null;
    resetDeductOtpState();
    if (row) {
      state.deductOtp.targetUid = String(row.uid || '');
      state.deductOtp.targetName = String(row.name || '');
      state.deductOtp.targetPhone = String(row.phone || '');
      state.deductOtp.targetRole = String(row.role || '');
      state.deductOtp.targetCurrency = window.walletNativeCurrency
        ? window.walletNativeCurrency(row)
        : (String(row.pricing_country || row.country_code || '').toUpperCase() === 'MY' ? 'MYR' : 'BDT');
      fillDeductBaseInfo(row);
    }
  });

  document.getElementById('closeDeductOtpModalBtn')?.addEventListener('click', closeDeductOtpModal);
  document.getElementById('deductOtpModalWrap')?.addEventListener('click', (e) => {
    if (e.target.id === 'deductOtpModalWrap') closeDeductOtpModal();
  });

  document.getElementById('closeDeductConfirmModalBtn')?.addEventListener('click', closeDeductConfirmModal);
  document.getElementById('cancelDeductOtpBtn')?.addEventListener('click', closeDeductConfirmModal);
  document.getElementById('resendDeductOtpBtn')?.addEventListener('click', resendDeductOtp);
  document.getElementById('confirmDeductOtpBtn')?.addEventListener('click', confirmDeduction);

  document.getElementById('deductConfirmModalWrap')?.addEventListener('click', (e) => {
    if (e.target.id === 'deductConfirmModalWrap') closeDeductConfirmModal();
  });

  document.getElementById('deductOtpCodeInput')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') confirmDeduction();
  });

  window.openDeductOtpModal = openDeductOtpModal;
  window.resetDeductOtpState = resetDeductOtpState;
})();
