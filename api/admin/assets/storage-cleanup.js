(function(){
  'use strict';

  const cleanupState = {
    loaded: false,
    loading: false,
    running: false,
    preview: null
  };

  const categoryLabels = {
    add_money_receipts: 'Add Money receipts',
    notification_images: 'Notification images',
    support_attachments: 'Support attachments',
    old_app_releases: 'Old app releases'
  };

  function cleanupEsc(value){
    return String(value ?? '').replace(/[&<>"']/g, character => ({
      '&':'&amp;',
      '<':'&lt;',
      '>':'&gt;',
      '"':'&quot;',
      "'":'&#39;'
    }[character]));
  }

  function cleanupBytes(value){
    let bytes = Math.max(0, Number(value || 0));
    if (!Number.isFinite(bytes) || bytes === 0) return '0 B';
    const units = ['B','KB','MB','GB','TB'];
    let index = 0;
    while (bytes >= 1024 && index < units.length - 1) {
      bytes /= 1024;
      index += 1;
    }
    const digits = index === 0 || bytes >= 10 ? 0 : 1;
    return `${bytes.toFixed(digits)} ${units[index]}`;
  }

  function cleanupDate(timestamp){
    const seconds = Number(timestamp || 0);
    if (!seconds) return '-';
    const date = new Date(seconds * 1000);
    return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString();
  }

  function cleanupAge(timestamp, generatedAt){
    const value = Number(timestamp || 0);
    const now = Number(generatedAt || Math.floor(Date.now() / 1000));
    if (!value || now <= value) return '-';
    return `${Math.floor((now - value) / 86400)} days`;
  }

  function selectedCleanupCategories(){
    return Array.from(document.querySelectorAll('#storageCleanupCategories input[type="checkbox"]:checked'))
      .map(input => String(input.value || '').trim())
      .filter(Boolean);
  }

  function setCleanupBusy(busy){
    cleanupState.loading = busy;
    const previewButton = document.getElementById('storageCleanupPreviewBtn');
    const deleteButton = document.getElementById('storageCleanupDeleteBtn');
    if (previewButton) {
      previewButton.disabled = busy || cleanupState.running;
      previewButton.textContent = busy ? 'Scanning...' : 'Preview Cleanup';
    }
    if (deleteButton) {
      deleteButton.disabled = busy || cleanupState.running || !cleanupState.preview || Number(cleanupState.preview.total_files || 0) < 1;
    }
  }

  function invalidateCleanupPreview(){
    cleanupState.preview = null;
    cleanupState.loaded = false;
    const panel = document.getElementById('storageCleanupPreviewPanel');
    const result = document.getElementById('storageCleanupRunResult');
    const deleteButton = document.getElementById('storageCleanupDeleteBtn');
    if (panel) panel.hidden = true;
    if (result) result.hidden = true;
    if (deleteButton) deleteButton.disabled = true;
    document.querySelectorAll('[data-cleanup-result]').forEach(node => {
      node.textContent = 'Preview required';
    });
  }

  function renderCleanupPreview(preview){
    const totalFiles = Math.max(0, Number(preview.total_files || 0));
    const totalBytes = Math.max(0, Number(preview.total_bytes || 0));
    const sampleItems = Array.isArray(preview.sample_items) ? preview.sample_items : [];
    const categories = preview.categories && typeof preview.categories === 'object' ? preview.categories : {};
    const generatedAt = Number(preview.generated_at || 0);
    const automatic = preview.automatic && typeof preview.automatic === 'object' ? preview.automatic : {};

    document.getElementById('storageCleanupFileCount').textContent = String(totalFiles);
    document.getElementById('storageCleanupByteCount').textContent = cleanupBytes(totalBytes);
    document.getElementById('storageCleanupCutoff').textContent = `${Number(preview.retention_days || 90)} days / ${cleanupDate(preview.cutoff_at)}`;
    document.getElementById('storageCleanupBatchLimit').textContent = `${Number(preview.batch_limit || 100)} files`;

    const automaticPanel = document.getElementById('storageCleanupAutomation');
    const automaticStatus = document.getElementById('storageCleanupAutomationStatus');
    const automaticDetail = document.getElementById('storageCleanupAutomationDetail');
    const automaticEnabled = automatic.enabled === true;
    const automaticCategories = Array.isArray(automatic.categories) ? automatic.categories.length : 0;
    if (automaticPanel) automaticPanel.classList.toggle('enabled', automaticEnabled);
    if (automaticStatus) automaticStatus.textContent = automaticEnabled ? 'Automatic cleanup runner enabled' : 'Automatic cleanup runner disabled';
    if (automaticDetail) {
      automaticDetail.textContent = automaticEnabled
        ? `Daily server runs may delete up to ${Number(automatic.batch_limit || 100)} eligible files across ${automaticCategories} protected categories.`
        : 'Scheduled deletion remains fail-closed until it is explicitly enabled in the private server configuration.';
    }

    document.querySelectorAll('[data-cleanup-result]').forEach(node => {
      const category = String(node.dataset.cleanupResult || '');
      const row = categories[category] || null;
      if (!row) {
        node.textContent = 'Not selected';
        return;
      }
      if (!row.available) {
        node.textContent = 'Folder not present';
        return;
      }
      const notes = [`${Number(row.eligible_files || 0)} eligible`, cleanupBytes(row.eligible_bytes || 0)];
      if (Number(row.protected_files || 0) > 0) notes.push(`${Number(row.protected_files)} protected`);
      if (Number(row.ambiguous_files || 0) > 0) notes.push(`${Number(row.ambiguous_files)} skipped`);
      node.textContent = notes.join(' / ');
    });

    const panel = document.getElementById('storageCleanupPreviewPanel');
    const note = document.getElementById('storageCleanupPreviewNote');
    const tableBody = document.getElementById('storageCleanupTableBody');
    const deleteButton = document.getElementById('storageCleanupDeleteBtn');
    if (panel) panel.hidden = false;
    if (note) {
      const messages = [`${totalFiles} eligible file${totalFiles === 1 ? '' : 's'} (${cleanupBytes(totalBytes)}).`];
      if (preview.sample_limited) messages.push(`Showing the first ${sampleItems.length}.`);
      if (preview.scan_limited) messages.push('The scan limit was reached; run cleanup in batches.');
      note.textContent = messages.join(' ');
    }
    if (deleteButton) deleteButton.disabled = totalFiles < 1;

    if (!tableBody) return;
    if (sampleItems.length === 0) {
      tableBody.innerHTML = '<tr><td colspan="6" class="empty">No files currently meet the protected retention rules.</td></tr>';
      return;
    }
    tableBody.innerHTML = sampleItems.map(item => `
      <tr>
        <td>${cleanupEsc(categoryLabels[item.category] || item.category || '-')}</td>
        <td>${cleanupEsc(item.reference || '-')}</td>
        <td>${cleanupEsc(item.name || '-')}</td>
        <td>${cleanupEsc(cleanupAge(item.eligible_at || item.modified_at, generatedAt))}</td>
        <td>${cleanupEsc(cleanupBytes(item.size_bytes || 0))}</td>
        <td>${cleanupEsc(item.status || 'ELIGIBLE')}</td>
      </tr>
    `).join('');
  }

  async function previewStorageCleanup(){
    if (cleanupState.loading || cleanupState.running) return;
    const categories = selectedCleanupCategories();
    if (categories.length === 0) {
      showToast('Select at least one cleanup category.', 'error');
      return;
    }

    setCleanupBusy(true);
    try {
      const preview = await proxyGet('storage_cleanup_preview', {
        categories: categories.join(',')
      }, {
        busy: false
      });
      cleanupState.preview = preview;
      cleanupState.loaded = true;
      renderCleanupPreview(preview);
      showToast('Cleanup preview is ready.', 'info');
    } catch (error) {
      cleanupState.preview = null;
      cleanupState.loaded = false;
      showToast(error.message || 'Cleanup preview failed.', 'error');
      throw error;
    } finally {
      setCleanupBusy(false);
    }
  }

  function renderCleanupRunResult(result){
    const node = document.getElementById('storageCleanupRunResult');
    if (!node) return;
    const deletedFiles = Number(result.deleted_files || 0);
    const failedFiles = Number(result.failed_files || 0);
    const orphanedFiles = Number(result.orphaned_files || 0);
    const remainingFiles = Number(result.remaining_files || 0);
    node.className = `storage-cleanup-result ${failedFiles > 0 ? 'warning' : 'success'}`;
    node.innerHTML = `
      <h4>${failedFiles > 0 ? 'Cleanup completed with warnings' : 'Cleanup completed'}</h4>
      <p>${cleanupEsc(`${deletedFiles} files deleted, ${cleanupBytes(result.deleted_bytes || 0)} recovered, ${remainingFiles} eligible files remaining${orphanedFiles > 0 ? `, ${orphanedFiles} quarantined files need review` : ''}. Audit ID: ${result.run_id || '-'}.`)}</p>
    `;
    node.hidden = false;
  }

  function openCleanupConfirmation(){
    const preview = cleanupState.preview;
    if (!preview || Number(preview.total_files || 0) < 1 || cleanupState.running) return;
    const phrase = String(preview.confirmation_phrase || 'DELETE 90 DAY FILES');
    const body = `
      <div class="storage-cleanup-confirm-copy">
        <div class="storage-cleanup-confirm-summary">
          <strong>${cleanupEsc(preview.total_files)} eligible files</strong><br>
          <span>${cleanupEsc(cleanupBytes(preview.total_bytes || 0))} can be recovered. A maximum of ${cleanupEsc(preview.batch_limit || 100)} files will be deleted in this run.</span>
        </div>
        <p>Only the categories in this preview will run. Transaction history, pending requests, active tickets, the current app and one rollback app remain protected.</p>
        <label class="field">
          <span>Type <code>${cleanupEsc(phrase)}</code> to confirm</span>
          <input class="input" id="storageCleanupConfirmationInput" autocomplete="off" spellcheck="false">
        </label>
      </div>
    `;
    const foot = `
      <button class="btn ghost" id="storageCleanupCancelRun" type="button">Cancel</button>
      <button class="btn red" id="storageCleanupConfirmRun" type="button" disabled>Delete Eligible Files</button>
    `;
    openModal('Confirm Storage Cleanup', body, foot);
    setModalPresentationScope('storage-cleanup');

    const input = document.getElementById('storageCleanupConfirmationInput');
    const confirmButton = document.getElementById('storageCleanupConfirmRun');
    document.getElementById('storageCleanupCancelRun')?.addEventListener('click', closeModal);
    input?.addEventListener('input', () => {
      if (confirmButton) confirmButton.disabled = input.value.trim().toUpperCase() !== phrase;
    });
    confirmButton?.addEventListener('click', () => runStorageCleanup(phrase));
    input?.focus();
  }

  async function runStorageCleanup(phrase){
    if (cleanupState.running || !cleanupState.preview) return;
    const categories = selectedCleanupCategories();
    const input = document.getElementById('storageCleanupConfirmationInput');
    if (!input || input.value.trim().toUpperCase() !== phrase) return;

    cleanupState.running = true;
    setCleanupBusy(false);
    const confirmButton = document.getElementById('storageCleanupConfirmRun');
    const cancelButton = document.getElementById('storageCleanupCancelRun');
    if (confirmButton) {
      confirmButton.disabled = true;
      confirmButton.textContent = 'Deleting...';
    }
    if (cancelButton) cancelButton.disabled = true;

    let refreshAfterRun = false;
    try {
      const result = await proxyPost('storage_cleanup_run', {
        categories,
        preview_token: String(cleanupState.preview.preview_token || ''),
        confirmation: phrase
      }, true, {
        busy: true,
        busyText: 'Safely deleting eligible files...'
      });
      closeModal();
      renderCleanupRunResult(result);
      cleanupState.preview = null;
      cleanupState.loaded = false;
      refreshAfterRun = true;
      showToast(Number(result.failed_files || 0) > 0 ? 'Cleanup completed with warnings.' : 'Storage cleanup completed.', Number(result.failed_files || 0) > 0 ? 'error' : 'ok');
    } catch (error) {
      showToast(error.message || 'Storage cleanup failed.', 'error');
      if (confirmButton) {
        confirmButton.disabled = false;
        confirmButton.textContent = 'Delete Eligible Files';
      }
      if (cancelButton) cancelButton.disabled = false;
    } finally {
      cleanupState.running = false;
      setCleanupBusy(false);
    }
    if (refreshAfterRun) {
      await previewStorageCleanup().catch(() => {});
    }
  }

  async function loadStorageCleanup(force){
    if (cleanupState.loading || cleanupState.running) return;
    if (cleanupState.loaded && !force) return;
    await previewStorageCleanup();
  }

  document.getElementById('storageCleanupPreviewBtn')?.addEventListener('click', () => {
    previewStorageCleanup().catch(() => {});
  });
  document.getElementById('storageCleanupDeleteBtn')?.addEventListener('click', openCleanupConfirmation);
  document.querySelectorAll('#storageCleanupCategories input[type="checkbox"]').forEach(input => {
    input.addEventListener('change', invalidateCleanupPreview);
  });

  window.loadStorageCleanup = loadStorageCleanup;
})();
