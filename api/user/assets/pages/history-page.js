(() => {
  'use strict';

  const shell = window.UserShell;
  const HISTORY_DAYS = 30;
  const HISTORY_LIMIT = 100;
  const HISTORY_WINDOW_SECONDS = HISTORY_DAYS * 24 * 60 * 60;
  const SUCCESS_STATUSES = new Set(['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'APPROVED', 'DONE']);
  const FAILED_STATUSES = new Set(['FAILED', 'REJECTED', 'CANCELLED', 'REFUNDED']);
  const PROCESSING_STATUSES = new Set(['PROCESSING', 'CLAIMED', 'DIALING']);
  const PENDING_STATUSES = new Set(['', 'PENDING', 'WAITING_ADMIN', 'WAITING_APPROVAL', 'ADMIN_PENDING']);
  const SHARE_ALLOWED_LABELS = new Set([
    'DATE', 'PROVIDER', 'REQUEST ID', 'BUNDLE', 'NUMBER', 'OPERATOR', 'RECEIVER NUMBER',
    'AMOUNT', 'BDT AMOUNT', 'MYR AMOUNT', 'MYR PAID', 'RATE', 'COMMISSION', 'FEE',
    'TOTAL PAID', 'WALLET DEBIT', 'PAID', 'REFERENCE',
    'SENDER LAST DIGIT', 'TRANSFER ID', 'SENDER', 'RECEIVER', 'ACCOUNT', 'PHONE',
    'AMOUNT RECEIVED', 'PAYMENT ACCOUNT', 'TRANSACTION ID'
  ]);
  const SHARE_LAYOUT = Object.freeze({
    width: 1080,
    cardInset: 34,
    cardPadding: 42,
    minHeight: 720,
    titleY: 188,
    titleLineHeight: 64,
    badgeHeight: 58,
    badgeToRows: 102,
    labelToValue: 42,
    valueLineHeight: 43,
    rowGap: 18
  });
  const state = {
    rows: [],
    loading: false,
    active: null,
    opener: null,
    modalHistory: false,
    pendingTarget: '',
    targetHandled: false
  };
  const $ = (id) => document.getElementById(id);

  function text(value) {
    return String(value ?? '').trim();
  }

  function first(row, ...keys) {
    for (const key of keys) {
      if (!Object.prototype.hasOwnProperty.call(row || {}, key) || row[key] === null || row[key] === undefined) continue;
      const value = text(row[key]);
      if (value && value.toLowerCase() !== 'null') return value;
    }
    return '';
  }

  function firstPositive(row, ...keys) {
    return keys.map((key) => first(row, key)).find((value) => positive(value)) || '';
  }

  function positive(value) {
    const numeric = Number(value);
    return Number.isFinite(numeric) && numeric > 0;
  }

  function zeroMoney(value) {
    if (text(value) === '') return true;
    const numeric = Number(value);
    return Number.isFinite(numeric) && Math.abs(numeric) < 0.000001;
  }

  function truthy(value) {
    return ['1', 'true', 'yes'].includes(text(value).toLowerCase());
  }

  function normalizedCurrency(value) {
    const clean = text(value).toUpperCase();
    if (clean === 'RM') return 'MYR';
    return clean || 'BDT';
  }

  function fixedMoney(value) {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric.toFixed(2) : text(value);
  }

  function moneyDisplay(value, currency = 'BDT', prefixBdt = false) {
    if (text(value) === '') return '';
    const label = normalizedCurrency(currency);
    const amount = fixedMoney(value);
    if (label === 'MYR') return `RM ${amount}`;
    return prefixBdt ? `${label} ${amount}` : `${amount} ${label}`;
  }

  function rateText(value) {
    if (!positive(value)) return '';
    return `RM 1 = ${text(value)} BDT`;
  }

  function parseTimestamp(value) {
    const clean = text(value);
    if (!clean) return 0;
    const numeric = Number(clean);
    if (Number.isFinite(numeric) && numeric > 0) return numeric > 100000000000 ? Math.floor(numeric / 1000) : Math.floor(numeric);
    const parsed = Date.parse(clean);
    return Number.isFinite(parsed) ? Math.floor(parsed / 1000) : 0;
  }

  function timestamp(row) {
    for (const key of ['updated_at', 'completed_at', 'success_at', 'approved_at', 'created_at', 'timestamp', 'date']) {
      const parsed = parseTimestamp(row?.[key]);
      if (parsed > 0) return parsed;
    }
    return 0;
  }

  function dateText(seconds) {
    if (!seconds) return '-';
    return new Intl.DateTimeFormat(undefined, {
      day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
    }).format(new Date(seconds * 1000));
  }

  function statusInfo(value) {
    const raw = text(value).toUpperCase();
    if (SUCCESS_STATUSES.has(raw)) return { label: 'Successful', className: 'successful' };
    if (FAILED_STATUSES.has(raw)) return { label: 'Failed', className: 'failed' };
    if (PROCESSING_STATUSES.has(raw)) return { label: 'Processing', className: 'processing' };
    if (PENDING_STATUSES.has(raw)) return { label: 'Pending', className: 'pending' };
    const label = raw ? raw.charAt(0) + raw.slice(1).toLowerCase() : 'Pending';
    return { label, className: 'pending' };
  }

  function operatorDisplay(value) {
    const clean = text(value).toUpperCase();
    return ({ GP: 'Grameenphone', ROBI: 'Robi', AIRTEL: 'Airtel', BANGLALINK: 'Banglalink', BL: 'Banglalink', TELETALK: 'Teletalk', TT: 'Teletalk' })[clean] || text(value);
  }

  function providerDisplay(value) {
    const clean = text(value).toUpperCase();
    if (clean.includes('BKASH')) return 'bKash';
    if (clean.includes('NAGAD')) return 'Nagad';
    return text(value);
  }

  function cleanReference(value) {
    const clean = text(value);
    return /telegram|admin|manual/i.test(clean) ? '' : clean;
  }

  function cleanMessage(value) {
    const clean = text(value);
    return /telegram|admin|manually|manual/i.test(clean) ? '' : clean;
  }

  function addDetail(rows, label, value) {
    const cleanLabel = text(label);
    const cleanValue = text(value);
    if (cleanLabel && cleanValue) rows.push({ label: cleanLabel, value: cleanValue });
  }

  function canonicalReceiptUrl(value) {
    const clean = text(value);
    if (!clean) return '';
    try {
      const url = new URL(clean, window.location.origin);
      if (!['http:', 'https:'].includes(url.protocol)) return '';
      const allowedHosts = new Set([window.location.hostname.toLowerCase(), 'zpayswift.com', 'www.zpayswift.com']);
      return allowedHosts.has(url.hostname.toLowerCase()) ? url.href : '';
    } catch (_) {
      return '';
    }
  }

  function baseItem(source, row, id, title, cardRows, detailRows) {
    const time = timestamp(row);
    return {
      source,
      id: text(id) || `${source}_${time}_${text(title)}`,
      title: text(title) || 'Transaction',
      status: first(row, 'display_status', 'status', 'request_status') || 'PENDING',
      timestamp: time,
      cardRows,
      detailRows,
      receiptUrl: canonicalReceiptUrl(first(row, 'receipt_url', 'tracking_url')),
      raw: row
    };
  }

  function addMoneyItem(row) {
    const id = first(row, 'request_id', 'id');
    const method = first(row, 'payment_account_name', 'method', 'payment_method');
    const currency = normalizedCurrency(first(row, 'currency', 'wallet_currency', 'payment_currency'));
    const amount = moneyDisplay(first(row, 'amount'), currency, true);
    const transactionId = cleanReference(first(row, 'transaction_id', 'reference'));
    const balanceBefore = moneyDisplay(first(row, 'balance_before', 'old_balance', 'before_balance'), currency, true);
    const balanceAfter = moneyDisplay(first(row, 'balance_after', 'new_balance', 'after_balance'), currency, true);
    const cardRows = [];
    addDetail(cardRows, 'Payment Account', method || first(row, 'payment_account_id'));
    addDetail(cardRows, 'Amount', amount);
    addDetail(cardRows, 'Transaction ID', transactionId);
    addDetail(cardRows, 'Balance After', balanceAfter);
    const detailRows = [];
    addDetail(detailRows, 'Date', dateText(timestamp(row)));
    addDetail(detailRows, 'Request ID', id);
    cardRows.forEach((entry) => addDetail(detailRows, entry.label, entry.value));
    addDetail(detailRows, 'Balance Before', balanceBefore);
    addDetail(detailRows, 'Reference', cleanReference(first(row, 'reference')));
    return baseItem('ADD_MONEY', row, id, `Add Money${method ? ` - ${method}` : ''}`, cardRows, detailRows);
  }

  function topupItem(row) {
    const id = first(row, 'request_id', 'id');
    const operator = first(row, 'operator', 'operator_name', 'operator_code');
    const number = first(row, 'topup_number', 'number');
    const walletCurrency = normalizedCurrency(first(row, 'display_currency', 'wallet_debit_currency', 'wallet_currency'));
    const myWallet = walletCurrency === 'MYR' || truthy(first(row, 'rate_applicable')) || positive(first(row, 'amount_myr', 'topup_amount_myr', 'wallet_debit_myr'));
    const amountBdt = moneyDisplay(first(row, 'topup_amount_bdt', 'amount_bdt', 'amount'), 'BDT');
    const paidValue = firstPositive(row, 'total_paid', 'total_pay', 'wallet_debit_amount', 'wallet_debit', 'total_debit', myWallet ? 'wallet_debit_myr' : 'wallet_debit_bdt');
    const paid = moneyDisplay(paidValue, walletCurrency);
    let amountMyrValue = firstPositive(row, 'topup_amount_myr', 'amount_myr', 'payable_myr', 'converted_myr', 'converted_amount');
    const feeValue = first(row, 'fee_amount');
    if (!amountMyrValue && myWallet && zeroMoney(feeValue)) amountMyrValue = firstPositive(row, 'wallet_debit_myr', 'wallet_debit_amount', 'wallet_debit', 'total_paid', 'total_pay', 'total_debit');
    const amountMyr = myWallet ? moneyDisplay(amountMyrValue, 'MYR') : '';
    const rate = myWallet ? rateText(first(row, 'rate_snapshot', 'rate_used', 'RATE_SNAPSHOT', 'exchange_rate')) : '';
    const commissionValue = first(row, 'commission_amount', 'commission_bdt');
    const commission = positive(commissionValue) ? moneyDisplay(commissionValue, 'BDT') : '';
    const fee = moneyDisplay(feeValue, walletCurrency);
    const balanceAfter = first(row, 'balance_after_text') || moneyDisplay(first(row, 'balance_after', 'last_balance', 'after_balance'), walletCurrency);
    const cardRows = [];
    addDetail(cardRows, 'Number', number);
    addDetail(cardRows, 'Operator', operator);
    addDetail(cardRows, 'BDT Amount', amountBdt);
    if (myWallet) {
      addDetail(cardRows, 'MYR Paid', paid || amountMyr);
      addDetail(cardRows, 'Rate', rate);
    } else {
      addDetail(cardRows, 'Paid', paid);
      addDetail(cardRows, 'Commission', commission);
    }
    addDetail(cardRows, 'Fee', fee);
    addDetail(cardRows, 'Balance After', balanceAfter);
    const detailRows = [];
    addDetail(detailRows, 'Date', dateText(timestamp(row)));
    addDetail(detailRows, 'Request ID', id);
    addDetail(detailRows, 'Number', number);
    addDetail(detailRows, 'Operator', operator);
    addDetail(detailRows, 'BDT Amount', amountBdt);
    if (myWallet) {
      addDetail(detailRows, 'MYR Amount', amountMyr);
      addDetail(detailRows, 'Rate', rate);
    }
    addDetail(detailRows, 'Fee', fee);
    addDetail(detailRows, 'Commission', commission);
    addDetail(detailRows, 'Total Paid', paid);
    addDetail(detailRows, 'Balance After', balanceAfter);
    return baseItem('TOPUP', row, id, `Mobile Top-Up${operator ? ` - ${text(operator).toUpperCase()}` : ''}`, cardRows, detailRows);
  }

  function transferItem(row) {
    const id = first(row, 'transfer_id', 'request_id', 'id');
    const direction = first(row, 'direction').toUpperCase();
    const received = ['IN', 'CREDIT', 'RECEIVED'].includes(direction);
    const partyLabel = received ? 'Sender' : 'Receiver';
    const partyName = received
      ? first(row, 'counterparty_name', 'sender_name', 'sender_account')
      : first(row, 'counterparty_name', 'receiver_name', 'receiver_account');
    const partyAccount = received
      ? first(row, 'counterparty_phone', 'sender_account', 'sender_phone')
      : first(row, 'counterparty_phone', 'receiver_account', 'receiver_phone');
    const currency = normalizedCurrency(first(row, 'wallet_currency', 'currency'));
    const amount = first(row, 'amount_text') || moneyDisplay(first(row, 'transfer_amount', 'amount'), currency, true);
    const fee = first(row, 'fee_text') || moneyDisplay(first(row, 'fee_amount', 'fee'), currency, true);
    const totalPaid = first(row, 'total_paid_text') || moneyDisplay(first(row, 'total_paid', 'total_debit', 'wallet_debit', 'amount'), currency, true);
    const balanceAfter = first(row, 'balance_after_text') || moneyDisplay(first(row, 'balance_after', 'after_available', 'last_balance'), currency, true);
    let reference = cleanReference(first(row, 'reference', 'note'));
    if (reference.toLowerCase() === id.toLowerCase()) reference = '';
    const cardRows = [];
    addDetail(cardRows, partyLabel, partyName);
    addDetail(cardRows, 'Account', partyAccount);
    addDetail(cardRows, received ? 'Amount Received' : 'Amount', amount);
    addDetail(cardRows, 'Fee', fee);
    if (!received) addDetail(cardRows, 'Total Paid', totalPaid);
    const detailRows = [];
    addDetail(detailRows, 'Date', dateText(timestamp(row)));
    addDetail(detailRows, 'Transfer ID', id);
    cardRows.forEach((entry) => addDetail(detailRows, entry.label, entry.value));
    addDetail(detailRows, 'Balance After', balanceAfter);
    addDetail(detailRows, 'Reference', reference);
    return baseItem('TRANSFER', row, id, `Z-Pay Transfer - ${received ? 'Received' : 'Sent'}`, cardRows, detailRows);
  }

  function mfsItem(row) {
    const id = first(row, 'request_id', 'id');
    const providerCode = first(row, 'provider');
    const provider = providerDisplay(first(row, 'provider_name', 'provider')) || 'MFS';
    const walletCurrency = normalizedCurrency(first(row, 'wallet_currency', 'wallet_debit_currency', 'currency'));
    const myWallet = walletCurrency === 'MYR' || truthy(first(row, 'rate_applicable')) || positive(first(row, 'amount_rm', 'amount_myr'));
    const number = first(row, 'receiver_number', 'number');
    const amountBdt = moneyDisplay(first(row, 'amount_bdt', 'received_amount', 'service_amount_bdt'), 'BDT');
    const amountMyr = myWallet ? moneyDisplay(first(row, 'amount_rm', 'amount_myr', 'send_amount_rm'), 'MYR') : '';
    const rate = myWallet ? rateText(first(row, 'rate_snapshot', 'exchange_rate', 'rate_myr_to_bdt', 'rate_myr_bdt')) : '';
    const fee = moneyDisplay(first(row, 'fee_amount', myWallet ? 'fee_rm' : 'fee_bdt'), myWallet ? 'MYR' : 'BDT');
    const totalPaid = first(row, 'total_pay_text', 'total_debit_text', 'wallet_debit_text') || moneyDisplay(first(row, 'total_paid', 'total_pay', 'wallet_debit', 'wallet_debit_amount', 'total_debit'), walletCurrency);
    const balanceAfter = first(row, 'balance_after_text', 'display_balance_after_text') || moneyDisplay(first(row, 'balance_after', 'display_balance_after', 'last_balance', 'after_balance'), walletCurrency);
    let reference = cleanReference(first(row, 'reference'));
    if ([id.toLowerCase(), `zp-${id}`.toLowerCase()].includes(reference.toLowerCase())) reference = '';
    const lastDigit = first(row, 'sender_last_digit', 'sender_details', 'last_digit');
    const cardRows = [];
    addDetail(cardRows, 'Number', number);
    addDetail(cardRows, 'BDT Amount', amountBdt);
    if (myWallet) {
      addDetail(cardRows, 'MYR Amount', amountMyr);
      addDetail(cardRows, 'Rate', rate);
    }
    addDetail(cardRows, 'Fee', fee);
    addDetail(cardRows, 'Total Paid', totalPaid);
    addDetail(cardRows, 'Reference', reference);
    addDetail(cardRows, 'Last Digit', lastDigit);
    addDetail(cardRows, 'Balance After', balanceAfter);
    const detailRows = [];
    addDetail(detailRows, 'Date', dateText(timestamp(row)));
    addDetail(detailRows, 'Provider', provider);
    addDetail(detailRows, 'Request ID', id);
    addDetail(detailRows, 'Receiver Number', number);
    addDetail(detailRows, 'BDT Amount', amountBdt);
    if (myWallet) {
      addDetail(detailRows, 'MYR Amount', amountMyr);
      addDetail(detailRows, 'Rate', rate);
    }
    addDetail(detailRows, 'Fee', fee);
    addDetail(detailRows, 'Total Paid', totalPaid);
    addDetail(detailRows, 'Balance After', balanceAfter);
    addDetail(detailRows, 'Reference', reference);
    addDetail(detailRows, 'Sender Last Digit', lastDigit);
    return baseItem('MFS', row, id, `${provider} - Send Money`, cardRows, detailRows);
  }

  function bundleItem(row) {
    const id = first(row, 'request_id', 'id');
    const operator = first(row, 'operator');
    const name = first(row, 'bundle_name', 'offer_id');
    const number = first(row, 'bundle_number', 'number', 'topup_number');
    const walletCurrency = normalizedCurrency(first(row, 'wallet_debit_currency', 'wallet_currency'));
    const serviceAmount = moneyDisplay(first(row, 'service_amount_bdt', 'service_amount', 'price_amount', 'offer_price', 'amount'), 'BDT', true);
    const walletDebit = moneyDisplay(first(row, 'wallet_debit_amount', 'wallet_hold_amount', 'held_amount', 'payable_amount', 'you_pay'), walletCurrency, true);
    const commissionValue = first(row, 'bundle_commission', 'user_commission', 'customer_commission', 'user_discount');
    const commission = positive(commissionValue) ? moneyDisplay(commissionValue, 'BDT', true) : '';
    const myWallet = walletCurrency === 'MYR' || truthy(first(row, 'rate_applicable')) || positive(first(row, 'wallet_debit_myr'));
    const rate = myWallet ? rateText(first(row, 'rate_snapshot', 'rate_used', 'rate')) : '';
    const balanceAfter = first(row, 'balance_after_text', 'display_balance_after_text') || moneyDisplay(first(row, 'balance_after', 'wallet_balance_after', 'balance_after_amount', 'last_balance', 'after_balance'), walletCurrency, true);
    const cardRows = [];
    addDetail(cardRows, 'Bundle', name);
    addDetail(cardRows, 'Amount', serviceAmount);
    addDetail(cardRows, 'Commission', commission);
    addDetail(cardRows, 'Wallet Debit', walletDebit);
    addDetail(cardRows, 'Number', number);
    const detailRows = [];
    addDetail(detailRows, 'Date', dateText(timestamp(row)));
    addDetail(detailRows, 'Request ID', id);
    addDetail(detailRows, 'Number', number);
    addDetail(detailRows, 'Operator', operatorDisplay(operator));
    addDetail(detailRows, 'Bundle', name);
    addDetail(detailRows, 'Amount', serviceAmount);
    addDetail(detailRows, 'Commission', commission);
    addDetail(detailRows, 'Rate', rate);
    addDetail(detailRows, 'Wallet Debit', walletDebit);
    addDetail(detailRows, 'Balance After', balanceAfter);
    return baseItem('BUNDLE', row, id, `Bundle${operator ? ` - ${text(operator).toUpperCase()}` : ''}`, cardRows, detailRows);
  }

  function inferSource(row, fallback = '') {
    const explicit = text(fallback || row?.history_source || row?.request_type || row?.action || row?.type).toUpperCase().replaceAll(' ', '_');
    if (explicit.includes('ADD_MONEY')) return 'ADD_MONEY';
    if (explicit.includes('TRANSFER') || row?.direction || row?.counterparty_name) return 'TRANSFER';
    if (explicit.includes('BUNDLE') || row?.bundle_name || row?.offer_id) return 'BUNDLE';
    if (explicit.includes('MFS') || row?.provider || row?.receiver_number || row?.service_type) return 'MFS';
    return 'TOPUP';
  }

  function normalizeItem(source, row) {
    if (!row || typeof row !== 'object') return null;
    switch (source) {
      case 'ADD_MONEY': return addMoneyItem(row);
      case 'TRANSFER': return transferItem(row);
      case 'BUNDLE': return bundleItem(row);
      case 'MFS': return mfsItem(row);
      default: return topupItem(row);
    }
  }

  function monthKey(date) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
  }

  function recentMonthKeys() {
    const current = new Date();
    const previous = new Date(current.getFullYear(), current.getMonth() - 1, 1);
    return Array.from(new Set([monthKey(current), monthKey(previous)]));
  }

  function requestRows(data) {
    const rows = [];
    (Array.isArray(data?.items) ? data.items : []).forEach((row) => rows.push([inferSource(row), row]));
    (Array.isArray(data?.wallet_history) ? data.wallet_history : []).forEach((row) => rows.push(['TRANSFER', row]));
    (Array.isArray(data?.add_money_history) ? data.add_money_history : []).forEach((row) => rows.push(['ADD_MONEY', row]));
    return rows;
  }

  function itemQuality(item) {
    return item.detailRows.length * 5 + item.cardRows.length * 2 + (item.receiptUrl ? 2 : 0);
  }

  function mergeRows(groups) {
    const cutoff = Math.floor(Date.now() / 1000) - HISTORY_WINDOW_SECONDS;
    const found = new Map();
    groups.flat().forEach(([source, row]) => {
      const item = normalizeItem(source, row);
      if (!item || item.timestamp <= 0 || item.timestamp < cutoff) return;
      const key = `${item.source}:${item.id}`.toUpperCase();
      const existing = found.get(key);
      if (!existing || itemQuality(item) > itemQuality(existing)) found.set(key, item);
    });
    return Array.from(found.values())
      .sort((left, right) => right.timestamp - left.timestamp)
      .slice(0, HISTORY_LIMIT);
  }

  function element(tag, className = '', value = '') {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (value !== '') node.textContent = value;
    return node;
  }

  function statusNode(value) {
    const info = statusInfo(value);
    return element('span', `history-status ${info.className}`, info.label);
  }

  function detailLine(entry, className) {
    const line = element('div', className);
    line.append(document.createTextNode(`${entry.label}: `), element('strong', '', entry.value));
    return line;
  }

  function historyCard(item, index) {
    const card = element('button', 'history-transaction-card');
    card.type = 'button';
    card.dataset.historyIndex = String(index);
    card.setAttribute('aria-label', `${item.title}, ${statusInfo(item.status).label}, ${dateText(item.timestamp)}`);
    const head = element('span', 'history-card-head');
    const heading = element('span', 'history-card-heading');
    heading.append(element('span', 'history-card-title', item.title), element('time', 'history-card-date', dateText(item.timestamp)));
    head.append(heading, statusNode(item.status));
    const details = element('span', 'history-card-details');
    item.cardRows.forEach((entry) => details.append(detailLine(entry, 'history-card-line')));
    card.append(head, details);
    card.addEventListener('click', () => openDetails(item, card));
    return card;
  }

  function render() {
    const list = $('historyList');
    list.replaceChildren();
    list.setAttribute('aria-busy', 'false');
    if (!state.rows.length) {
      list.append(element('div', 'history-state', 'No transaction history found in the last 30 days.'));
      $('historyLive').textContent = 'No transaction history was found in the last 30 days.';
      return;
    }
    state.rows.forEach((item, index) => list.append(historyCard(item, index)));
    $('historyLive').textContent = `${state.rows.length} recent transactions loaded.`;
    openPendingTarget();
  }

  function renderSkeletons() {
    const list = $('historyList');
    list.replaceChildren();
    list.setAttribute('aria-busy', 'true');
    for (let index = 0; index < 4; index += 1) {
      const skeleton = element('div', 'history-skeleton');
      skeleton.setAttribute('aria-hidden', 'true');
      const head = element('div', 'history-skeleton-head');
      head.append(element('span'), element('i'));
      skeleton.append(head, element('b'), element('b'), element('b'), element('b'));
      list.append(skeleton);
    }
  }

  function renderError() {
    const list = $('historyList');
    list.replaceChildren(element('div', 'history-state', 'History could not be loaded. Please try again.'));
    list.setAttribute('aria-busy', 'false');
    $('historyLive').textContent = 'History could not be loaded.';
  }

  async function loadHistory() {
    if (state.loading) return;
    state.loading = true;
    const hadRows = state.rows.length > 0;
    if (!hadRows) renderSkeletons();
    const requests = recentMonthKeys().map((month) =>
      shell.get('request_logs', { month, limit: HISTORY_LIMIT }, '', { busy: false })
        .then((data) => ({ kind: 'REQUESTS', data }))
    );
    requests.push(shell.get('transfer_history', { limit: HISTORY_LIMIT }, '', { busy: false })
      .then((data) => ({ kind: 'TRANSFER', data })));

    try {
      const results = await Promise.allSettled(requests);
      const fulfilled = results.filter((result) => result.status === 'fulfilled').map((result) => result.value);
      if (!fulfilled.length) throw new Error('History could not be loaded. Please try again.');
      const groups = fulfilled.map((result) => result.kind === 'TRANSFER'
        ? (Array.isArray(result.data?.items) ? result.data.items.map((row) => ['TRANSFER', row]) : [])
        : requestRows(result.data));
      state.rows = mergeRows(groups);
      render();
      if (results.some((result) => result.status === 'rejected')) shell.toast('Some history could not be refreshed.', 'error');
    } catch (error) {
      if (hadRows) shell.toast('History could not be refreshed. Please try again.', 'error');
      else renderError();
    } finally {
      state.loading = false;
    }
  }

  function setModalStatus(value) {
    const info = statusInfo(value);
    const badge = $('historyDetailStatus');
    badge.className = `history-status ${info.className}`;
    badge.textContent = info.label;
  }

  function actionButton(label, primary, handler) {
    const button = element('button', `history-modal-action${primary ? ' primary' : ''}`, label);
    button.type = 'button';
    button.addEventListener('click', handler);
    return button;
  }

  function openReceipt(item) {
    if (!item.receiptUrl) return;
    const opened = window.open(item.receiptUrl, '_blank', 'noopener,noreferrer');
    if (opened) opened.opener = null;
    else shell.toast('Receipt link could not be opened.', 'error');
  }

  function openDetails(item, opener = null, fromHistory = false) {
    state.active = item;
    state.opener = opener instanceof HTMLElement ? opener : null;
    $('historyDetailTitle').textContent = item.title;
    setModalStatus(item.status);
    const rows = $('historyDetailRows');
    rows.replaceChildren();
    item.detailRows.forEach((entry) => rows.append(detailLine(entry, 'history-detail-row')));
    const actions = $('historyDetailActions');
    actions.replaceChildren();
    if (item.receiptUrl) actions.append(actionButton('Open', true, () => openReceipt(item)));
    actions.append(actionButton('Share', true, () => shareHistoryImage(item)));
    actions.append(actionButton('Close', false, closeDetails));
    const modal = $('historyDetailModal');
    modal.classList.remove('hidden');
    modal.inert = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('history-modal-open');
    if (!fromHistory) {
      window.history.pushState({ ...(window.history.state || {}), zpayHistoryDetail: item.id }, '', window.location.href);
      state.modalHistory = true;
    } else {
      state.modalHistory = false;
    }
    window.setTimeout(() => modal.querySelector('.history-detail-card')?.focus({ preventScroll: true }), 0);
  }

  function hideDetails(restoreFocus = true) {
    const modal = $('historyDetailModal');
    if (modal.classList.contains('hidden')) return;
    modal.classList.add('hidden');
    modal.inert = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('history-modal-open');
    state.active = null;
    state.modalHistory = false;
    if (restoreFocus) state.opener?.focus?.({ preventScroll: true });
  }

  function closeDetails() {
    if (state.modalHistory && window.history.state?.zpayHistoryDetail) window.history.back();
    else hideDetails();
  }

  function normalizedShareLabel(value) {
    return text(value).toUpperCase().replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
  }

  function isShareAllowedLabel(value) {
    const label = normalizedShareLabel(value);
    return SHARE_ALLOWED_LABELS.has(label) && !/\bBALANCE\b/.test(label);
  }

  function shareRows(item) {
    return item.detailRows.filter((entry) => {
      const label = normalizedShareLabel(entry.label);
      if (!isShareAllowedLabel(label)) return false;
      return !(item.source === 'BUNDLE' && ['COMMISSION', 'RATE'].includes(label));
    });
  }

  function wrapCanvasText(context, value, maxWidth) {
    const words = text(value).split(/\s+/).filter(Boolean);
    const lines = [];
    let current = '';
    words.forEach((word) => {
      if (context.measureText(word).width > maxWidth) {
        if (current) lines.push(current);
        let piece = '';
        Array.from(word).forEach((character) => {
          const next = piece + character;
          if (piece && context.measureText(next).width > maxWidth) {
            lines.push(piece);
            piece = character;
          } else piece = next;
        });
        current = piece;
        return;
      }
      const next = current ? `${current} ${word}` : word;
      if (current && context.measureText(next).width > maxWidth) {
        lines.push(current);
        current = word;
      } else current = next;
    });
    if (current) lines.push(current);
    return lines.length ? lines : ['-'];
  }

  function roundedRect(context, x, y, width, height, radius) {
    const safeRadius = Math.min(radius, width / 2, height / 2);
    context.beginPath();
    context.roundRect(x, y, width, height, safeRadius);
  }

  function prepareShareLayout(context, item) {
    const contentX = SHARE_LAYOUT.cardInset + SHARE_LAYOUT.cardPadding;
    const contentWidth = SHARE_LAYOUT.width - contentX * 2;
    context.font = '500 34px system-ui, sans-serif';
    const valueDescent = Math.max(8, Math.ceil(context.measureText('Ag').actualBoundingBoxDescent || 0));
    const rows = shareRows(item).map((entry) => ({
      label: entry.label,
      lines: wrapCanvasText(context, entry.value, contentWidth)
    }));
    context.font = '800 52px system-ui, sans-serif';
    const titleLines = wrapCanvasText(context, item.title, contentWidth);
    const badgeY = SHARE_LAYOUT.titleY + titleLines.length * SHARE_LAYOUT.titleLineHeight;
    let rowY = badgeY + SHARE_LAYOUT.badgeToRows;
    let contentBottom = badgeY - 6 + SHARE_LAYOUT.badgeHeight;
    const preparedRows = rows.map((entry) => {
      const labelY = rowY;
      const firstValueY = labelY + SHARE_LAYOUT.labelToValue;
      const lineYs = entry.lines.map((line, index) => firstValueY + index * SHARE_LAYOUT.valueLineHeight);
      const lastValueY = lineYs[lineYs.length - 1];
      contentBottom = lastValueY + valueDescent;
      rowY = lastValueY + SHARE_LAYOUT.valueLineHeight + SHARE_LAYOUT.rowGap;
      return { ...entry, labelY, lineYs };
    });
    const height = Math.max(
      SHARE_LAYOUT.minHeight,
      Math.ceil(contentBottom + SHARE_LAYOUT.cardPadding + SHARE_LAYOUT.cardInset)
    );
    return { contentX, contentWidth, titleLines, badgeY, rows: preparedRows, contentBottom, height };
  }

  async function createShareBlob(item) {
    await document.fonts?.ready?.catch?.(() => {});
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Canvas is unavailable.');
    const layout = prepareShareLayout(context, item);
    const width = SHARE_LAYOUT.width;
    const height = layout.height;
    canvas.width = width;
    canvas.height = height;
    const gradient = context.createLinearGradient(0, 0, width, height);
    gradient.addColorStop(0, '#07172f');
    gradient.addColorStop(1, '#0b2d5c');
    context.fillStyle = gradient;
    context.fillRect(0, 0, width, height);
    roundedRect(
      context,
      SHARE_LAYOUT.cardInset,
      SHARE_LAYOUT.cardInset,
      width - SHARE_LAYOUT.cardInset * 2,
      height - SHARE_LAYOUT.cardInset * 2,
      46
    );
    context.fillStyle = '#10284f';
    context.fill();
    context.strokeStyle = 'rgba(78, 127, 168, 0.85)';
    context.lineWidth = 3;
    context.stroke();

    context.textAlign = 'center';
    context.fillStyle = '#32e686';
    context.font = '800 38px system-ui, sans-serif';
    context.fillText('Z-Pay Swift', width / 2, 118);
    context.fillStyle = '#ffffff';
    context.font = '800 52px system-ui, sans-serif';
    let titleY = SHARE_LAYOUT.titleY;
    layout.titleLines.forEach((line) => {
      context.fillText(line, width / 2, titleY);
      titleY += SHARE_LAYOUT.titleLineHeight;
    });
    const info = statusInfo(item.status);
    context.font = '800 29px system-ui, sans-serif';
    const badgeWidth = Math.max(176, context.measureText(info.label).width + 70);
    roundedRect(context, (width - badgeWidth) / 2, layout.badgeY - 6, badgeWidth, SHARE_LAYOUT.badgeHeight, 29);
    context.fillStyle = info.className === 'successful' ? 'rgba(50,230,134,.14)' : info.className === 'failed' ? 'rgba(255,122,122,.14)' : 'rgba(255,255,255,.08)';
    context.fill();
    context.strokeStyle = info.className === 'successful' ? '#32e686' : info.className === 'failed' ? '#ff8b8b' : '#c8d6ec';
    context.stroke();
    context.fillStyle = info.className === 'successful' ? '#32e686' : info.className === 'failed' ? '#ff8b8b' : '#c8d6ec';
    context.fillText(info.label, width / 2, layout.badgeY + 33);

    context.textAlign = 'left';
    layout.rows.forEach((entry) => {
      context.fillStyle = '#8ea3c6';
      context.font = '700 28px system-ui, sans-serif';
      context.fillText(entry.label, layout.contentX, entry.labelY);
      context.fillStyle = '#ffffff';
      context.font = '500 34px system-ui, sans-serif';
      entry.lines.forEach((line, index) => {
        context.fillText(line, layout.contentX, entry.lineYs[index]);
      });
    });

    return new Promise((resolve, reject) => canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('Image creation failed.')), 'image/png'));
  }

  async function shareHistoryImage(item) {
    const buttons = Array.from($('historyDetailActions').querySelectorAll('button'));
    buttons.forEach((button) => { button.disabled = true; });
    try {
      const blob = await createShareBlob(item);
      const file = new File([blob], 'zpay_history_share.png', { type: 'image/png' });
      if (navigator.share && navigator.canShare?.({ files: [file] })) {
        await navigator.share({ files: [file], title: 'Z-Pay Swift transaction' });
        return;
      }
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'zpay_history_share.png';
      document.body.append(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
      shell.toast('Transaction image saved.', 'ok');
    } catch (error) {
      if (error?.name !== 'AbortError') shell.toast('Transaction image could not be shared.', 'error');
    } finally {
      buttons.forEach((button) => { button.disabled = false; });
    }
  }

  function readPendingTarget() {
    const params = new URLSearchParams(window.location.search);
    state.pendingTarget = text(params.get('entity_id') || params.get('transfer_id') || params.get('request_id'));
    state.targetHandled = !state.pendingTarget;
  }

  function openPendingTarget() {
    if (state.targetHandled || !state.pendingTarget) return;
    state.targetHandled = true;
    const match = state.rows.find((item) => item.id.toLowerCase() === state.pendingTarget.toLowerCase());
    if (match) openDetails(match, null);
    else shell.toast('Notification details could not be found yet. Please refresh history.', 'error');
  }

  async function init() {
    readPendingTarget();
    await shell.ready;
    $('historyDetailModal').querySelector('[data-history-modal-close]').addEventListener('click', closeDetails);
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeDetails(); });
    window.addEventListener('popstate', () => hideDetails(false));
    await loadHistory();
  }

  init().catch(() => renderError());
})();
