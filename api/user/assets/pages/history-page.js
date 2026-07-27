(() => {
  'use strict';

  const shell = window.UserShell;
  const $ = (id) => document.getElementById(id);
  const state = { month: new Date().toISOString().slice(0, 7), filter: 'ALL', rows: [] };

  function status(row) {
    return String(row.status || row.request_status || 'PENDING').toUpperCase();
  }

  function timestamp(row) {
    return Number(row.updated_at || row.created_at || row.timestamp || 0);
  }

  function normalizeRows(data) {
    const groups = [
      data.rows, data.items, data.logs, data.history, data.wallet_history,
      data.topup_logs, data.bundle_requests, data.mfs_requests, data.add_money_history
    ];
    const found = new Map();
    groups.forEach((group) => {
      if (!Array.isArray(group)) return;
      group.forEach((row) => {
        if (!row || typeof row !== 'object') return;
        const id = String(row.request_id || row.transfer_id || row.transaction_id || row.id || '');
        const key = id || `${row.type || row.source || 'ROW'}:${timestamp(row)}:${row.amount || ''}`;
        if (!found.has(key)) found.set(key, row);
      });
    });
    return Array.from(found.values()).sort((a, b) => timestamp(b) - timestamp(a));
  }

  function rowTitle(row) {
    return String(row.type || row.request_type || row.source || row.service || 'Transaction').replaceAll('_', ' ');
  }

  function rowId(row) {
    return String(row.request_id || row.transfer_id || row.transaction_id || row.id || '-');
  }

  function rowAmount(row) {
    const amount = Number(row.amount ?? row.wallet_amount ?? row.total_debit ?? 0);
    const currency = String(row.currency || row.wallet_currency || 'BDT').toUpperCase();
    return `${currency} ${Number.isFinite(amount) ? amount.toFixed(2) : '0.00'}`;
  }

  function dateText(value) {
    const numeric = Number(value || 0);
    if (!numeric) return '-';
    return new Date(numeric < 1000000000000 ? numeric * 1000 : numeric).toLocaleString();
  }

  function filtered() {
    return state.rows.filter((row) => {
      if (state.filter === 'ALL') return true;
      const value = status(row);
      if (state.filter === 'SUCCESS') return ['SUCCESS', 'COMPLETED', 'APPROVED'].includes(value);
      if (state.filter === 'FAILED') return ['FAILED', 'REJECTED', 'CANCELLED'].includes(value);
      return value === state.filter;
    });
  }

  function render() {
    const list = $('historyList');
    if (!list) return;
    const rows = filtered();
    if (!rows.length) {
      list.innerHTML = '<div class="history-item"><div class="history-id">No history found for this month.</div></div>';
      return;
    }
    list.innerHTML = rows.map((row, index) => `
      <button class="history-item" type="button" data-history-index="${index}">
        <span>
          <span class="history-id">${shell.escapeHtml(rowTitle(row))}</span>
          <span class="history-small">${shell.escapeHtml(rowId(row))}<br>${shell.escapeHtml(dateText(timestamp(row)))}</span>
        </span>
        <span>
          <span class="history-id">${shell.escapeHtml(rowAmount(row))}</span>
          <span class="status-pill">${shell.escapeHtml(status(row))}</span>
        </span>
      </button>`).join('');
    list.querySelectorAll('[data-history-index]').forEach((button) => {
      button.addEventListener('click', () => openDetails(rows[Number(button.dataset.historyIndex || 0)]));
    });
  }

  function openDetails(row) {
    const grid = $('detailGrid');
    const values = [
      ['Request ID', rowId(row)],
      ['Status', status(row)],
      ['Type', rowTitle(row)],
      ['Amount', rowAmount(row)],
      ['Number', row.number || row.phone || row.receiver_phone || '-'],
      ['Created', dateText(row.created_at || timestamp(row))],
      ['Updated', dateText(row.updated_at || timestamp(row))],
      ['Message', row.message || row.note || row.reference || '-']
    ];
    grid.innerHTML = values.map(([label, value]) =>
      `<div class="detail-box"><label>${shell.escapeHtml(label)}</label><strong>${shell.escapeHtml(value)}</strong></div>`
    ).join('');
    $('detailModal').classList.add('show');
    $('detailModal').setAttribute('aria-hidden', 'false');
  }

  function closeDetails() {
    $('detailModal')?.classList.remove('show');
    $('detailModal')?.setAttribute('aria-hidden', 'true');
  }

  async function load(force = false) {
    const data = await shell.get('request_logs', { month: state.month, limit: 100 }, force ? 'Refreshing history...' : 'Loading history...');
    state.rows = normalizeRows(data);
    render();
  }

  async function init() {
    await shell.ready;
    $('historyMonthInput').value = state.month;
    $('historyMonthLabel').textContent = new Date(`${state.month}-01T00:00:00`).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    $('historyMonthInput').addEventListener('change', async (event) => {
      state.month = event.target.value || state.month;
      await load(true).catch((error) => shell.toast(error.message, 'error'));
    });
    $('historyRefreshBtn').addEventListener('click', () => load(true).catch((error) => shell.toast(error.message, 'error')));
    document.querySelectorAll('[data-filter]').forEach((button) => button.addEventListener('click', () => {
      state.filter = String(button.dataset.filter || 'ALL');
      document.querySelectorAll('[data-filter]').forEach((item) => item.classList.toggle('active', item === button));
      render();
    }));
    $('closeDetailModalBtn').addEventListener('click', closeDetails);
    $('detailModal').addEventListener('click', (event) => { if (event.target === $('detailModal')) closeDetails(); });
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeDetails(); });
    await load();
  }

  init().catch((error) => shell.toast(error.message || 'Failed to load history.', 'error'));
})();
