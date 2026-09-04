(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const form = document.querySelector('#createPostForm');
  const mineList = document.querySelector('#mineList');
  const toastRegion = document.querySelector('#toastRegion');

  if (!config || !form || !mineList || !window.ZNewsApiClient) return;

  const client = () => new window.ZNewsApiClient(config);
  const text = (value) => String(value ?? '');
  const richText = window.ZNewsRichText || {
    getEditorPayload: (textarea) => ({ text: text(textarea?.value).trim(), boldRanges: [], formattingRuns: [] }),
    setEditorContent: (textarea, value) => { if (textarea) textarea.value = text(value); },
    bindToolbar: () => {}
  };
  const uiFeedback = window.ZNewsUiFeedback || {
    beginProgress: () => () => {},
    setButtonLoading: (button, busy, label) => setBusy(button, busy, label)
  };
  let currentImagePreviewUrl = '';
  let replacementPreviewUrl = '';
  let editLoadGeneration = 0;
  let editOpening = false;
  let actionCleanup = null;
  let actionReturnFocus = null;

  function categoryPickerMarkup(prefix, inputId) {
    return `
      <span class="composer-field-label" id="${prefix}Label">Category</span>
      <input id="${inputId}" type="hidden" value="">
      <button class="category-picker-trigger" id="${prefix}Button" type="button" aria-labelledby="${prefix}Label ${prefix}Value" aria-haspopup="dialog" aria-expanded="false" aria-controls="${prefix}Dialog">
        <span id="${prefix}Value" data-category-label>Choose a category</span><span aria-hidden="true">⌄</span>
      </button>
      <dialog class="category-picker-dialog" id="${prefix}Dialog" aria-labelledby="${prefix}DialogTitle">
        <div class="category-picker-sheet">
          <header><h2 id="${prefix}DialogTitle">Choose category</h2><button type="button" data-category-close aria-label="Close category picker">×</button></header>
          <div class="category-picker-options" role="radiogroup" aria-label="Post category">
            <button type="button" role="radio" aria-checked="false" data-category-option="INTERNATIONAL_NEWS"><span>International news</span><i aria-hidden="true"></i></button>
            <button type="button" role="radio" aria-checked="false" data-category-option="BD_NEWS"><span>BD news</span><i aria-hidden="true"></i></button>
            <button type="button" role="radio" aria-checked="false" data-category-option="MOBILE_PRICING"><span>Mobile pricing</span><i aria-hidden="true"></i></button>
          </div>
        </div>
      </dialog>`;
  }

  function formattingToolbarMarkup(prefix) {
    const colors = [
      ['default', 'default', 'Default'], ['white', 'white', 'White'],
      ['light_blue', 'light-blue', 'Light blue'], ['green', 'green', 'Green'],
      ['yellow', 'yellow', 'Yellow'], ['orange', 'orange', 'Orange'], ['red', 'red', 'Red']
    ];
    return `
      <div class="composer-format-toolbar" id="${prefix}Toolbar" role="toolbar" aria-label="Text formatting">
        <button class="composer-format-button" type="button" data-format-bold aria-label="Bold selected text" aria-pressed="false"><strong>B</strong></button>
        <button class="composer-format-button composer-color-button" type="button" data-format-color-toggle data-color="default" aria-label="Text color: Default" aria-haspopup="true" aria-expanded="false" aria-controls="${prefix}Palette"><strong>A</strong><span class="format-color-swatch" aria-hidden="true"></span></button>
        <div class="format-color-palette" id="${prefix}Palette" data-format-palette role="radiogroup" aria-label="Text color" hidden>
          ${colors.map(([id, className, label], index) => `<button type="button" role="radio" aria-checked="${index === 0 ? 'true' : 'false'}" data-format-color="${id}"><i class="color-swatch ${className}" aria-hidden="true"></i><span>${label}</span></button>`).join('')}
        </div>
      </div>`;
  }

  function safeUrl(value) {
    const raw = text(value).trim();
    if (!raw) return '';
    try {
      const url = new URL(config.resolveProfilePhotoUrl(raw), window.location.origin);
      if (url.protocol !== 'https:' && url.origin !== window.location.origin) return '';
      return url.toString();
    } catch (_error) {
      return '';
    }
  }

  function setAvatar(element, name, photoUrl = '') {
    if (!element) return;
    element.textContent = '';
    const photo = safeUrl(photoUrl);
    if (photo) {
      const image = document.createElement('img');
      image.src = photo;
      image.alt = '';
      image.referrerPolicy = 'no-referrer';
      element.appendChild(image);
      return;
    }
    element.textContent = text(name).trim().charAt(0).toUpperCase() || 'Z';
  }

  function toast(message, type = 'success') {
    const item = document.createElement('div');
    item.className = `toast ${type}`;
    item.textContent = text(message);
    toastRegion?.appendChild(item);
    window.setTimeout(() => item.remove(), 4200);
  }

  function errorMessage(error) {
    const map = {
      SESSION_EXPIRED: 'Your session has expired. Please sign in again.',
      ZNEWS_POST_VERSION_CONFLICT: 'This post changed. Reload My posts and try again.',
      ZNEWS_POST_BLOCKED: 'A blocked post cannot be edited.',
      ZNEWS_MEDIA_DUPLICATE: 'This exact image has already been uploaded.',
      ZNEWS_MEDIA_NOT_AVAILABLE: 'This image is already attached to another post.',
      NETWORK_FAILURE: 'Network connection failed. Please try again.'
    };
    return map[error?.code] || error?.message || 'Something went wrong.';
  }

  function setBusy(button, busy, label = 'Please wait…') {
    if (!(button instanceof HTMLButtonElement)) return;
    if (busy) {
      button.dataset.creatorLabel = button.textContent;
      button.textContent = label;
      button.disabled = true;
    } else {
      button.textContent = button.dataset.creatorLabel || button.textContent;
      button.disabled = false;
    }
  }

  function idempotency(prefix) {
    const random = window.crypto?.randomUUID
      ? window.crypto.randomUUID()
      : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
    return `znews-web-${prefix}-${random}`;
  }

  async function uploadImage(api, file, onStatus = () => {}) {
    const optimizer = window.ZNewsImageOptimizer
      || await window.ZNEWS_IMAGE_OPTIMIZER_READY?.();
    if (!optimizer?.optimize) throw new window.ZNewsApiError('Photo optimization is unavailable. Reload and try again.');
    const optimized = await optimizer.optimize(file, (label) => onStatus('optimizing', label || 'Optimizing photo…'));
    onStatus('uploading', 'Uploading photo…');
    const body = new FormData();
    body.append('image', optimized.file);
    body.append('idempotency_key', idempotency('media'));
    const response = await api.request('znews/media/upload.php', {
      method: 'POST',
      body,
      authenticated: true
    });
    const mediaId = text(response.data?.media?.media_id).trim();
    if (!mediaId) throw new window.ZNewsApiError('Image upload did not return a media ID.');
    return mediaId;
  }

  function setEditMutationState(dialog, busy, stage = 'saving') {
    const top = dialog.querySelector('#creatorEditSubmitTop');
    const bottom = dialog.querySelector('#creatorEditSubmitBottom');
    const labels = {
      optimizing: ['OPTIMIZING…', 'Optimizing photo…'],
      uploading: ['UPLOADING…', 'Uploading photo…'],
      publishing: ['SAVING…', 'Saving…'],
      saving: ['SAVING…', 'Saving…']
    }[stage] || ['SAVING…', 'Saving…'];
    uiFeedback.setButtonLoading(top, busy, labels[0], { spinner: false });
    uiFeedback.setButtonLoading(bottom, busy, labels[1], { spinner: true });
  }

  async function postDetails(postId) {
    return client().request('znews/posts/details.php', {
      authenticated: true,
      params: { post_id: postId }
    });
  }

  function ensureEditor() {
    let dialog = document.querySelector('#creatorEditDialog');
    if (dialog) return dialog;

    dialog = document.createElement('dialog');
    dialog.id = 'creatorEditDialog';
    dialog.className = 'modal creator-edit-dialog';
    dialog.innerHTML = `
      <form class="composer-form creator-edit-form" id="creatorEditForm">
        <header class="composer-topbar creator-edit-topbar">
          <button class="composer-back" type="button" data-close aria-label="Close editor">‹</button>
          <h1>Edit post</h1>
          <button class="primary-button compact composer-submit" id="creatorEditSubmitTop" type="submit">Save</button>
        </header>
        <div class="composer-author">
          <div class="avatar composer-author-avatar" id="creatorEditAvatar">Z</div>
          <div>
            <strong id="creatorEditName">Z-Pay creator</strong>
            <span class="composer-audience" aria-label="Post audience: Public"><span aria-hidden="true">◉</span> Public</span>
          </div>
        </div>
        <div class="composer-writing-fields">
          <div class="composer-field composer-category-field">
            ${categoryPickerMarkup('creatorEditCategoryPicker', 'creatorEditCategory')}
          </div>
          <div class="composer-field composer-title-field">
            <label for="creatorEditTitle">News headline</label>
            <input id="creatorEditTitle" type="text" maxlength="160" placeholder="Add a clear headline" required aria-describedby="creatorEditTitleCount">
            <span class="composer-field-count" id="creatorEditTitleCount">0 / 160</span>
          </div>
          <div class="composer-field composer-body-field">
            <label for="creatorEditText">Post details</label>
            ${formattingToolbarMarkup('creatorEditFormat')}
            <textarea id="creatorEditText" maxlength="5000" rows="4" placeholder="Write the story or update…" aria-describedby="creatorEditNote creatorEditTextCount"></textarea>
            <span class="composer-field-count" id="creatorEditTextCount">0 / 5000</span>
          </div>
        </div>
        <div id="creatorEditPreview" class="image-preview" hidden></div>
        <div class="composer-add-row" aria-label="Update your post photo">
          <label class="composer-photo-action" for="creatorEditImage">
            <input id="creatorEditImage" type="file" accept="image/jpeg,image/png,image/webp">
            <span aria-hidden="true">▧</span>
            <strong id="creatorEditPhotoLabel">Add photo</strong>
          </label>
        </div>
        <input id="creatorRemoveImage" type="checkbox" hidden>
        <p class="composer-review-note" id="creatorEditNote">Clean changes publish immediately. Risky or duplicate content may be held for review.</p>
        <p class="form-error" id="creatorEditError" hidden></p>
        <div class="composer-bottom-action creator-edit-bottom-action">
          <button class="primary-button composer-bottom-submit" id="creatorEditSubmitBottom" type="submit">Save changes</button>
        </div>
      </form>`;
    document.body.appendChild(dialog);
    dialog.querySelector('[data-close]')?.addEventListener('click', () => {
      if (dialog.querySelector('#creatorEditForm')?.getAttribute('aria-busy') !== 'true') dialog.close();
    });
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      if (dialog.querySelector('#creatorEditForm')?.getAttribute('aria-busy') !== 'true') dialog.close();
    });
    dialog.addEventListener('close', () => {
      const returnFocus = dialog._returnFocus;
      resetEditor(dialog);
      dialog._returnFocus = null;
      if (returnFocus?.isConnected) returnFocus.focus();
    });
    const categoryInput = dialog.querySelector('#creatorEditCategory');
    categoryInput?.addEventListener('change', () => syncEditor(dialog));
    dialog.querySelector('#creatorEditTitle')?.addEventListener('input', () => syncEditor(dialog));
    const body = dialog.querySelector('#creatorEditText');
    body?.addEventListener('input', () => syncEditor(dialog));
    body?.addEventListener('znews:format-change', () => syncEditor(dialog));
    richText.setEditorContent(body, '');
    richText.bindToolbar(body, dialog.querySelector('#creatorEditFormatToolbar'));
    window.ZNewsCategoryPicker?.bind(
      categoryInput,
      dialog.querySelector('#creatorEditCategoryPickerButton'),
      dialog.querySelector('#creatorEditCategoryPickerDialog')
    );
    dialog.querySelector('#creatorEditImage')?.addEventListener('change', () => {
      dialog.querySelector('#creatorRemoveImage').checked = false;
      renderEditPreview(dialog);
      syncEditor(dialog);
    });
    return dialog;
  }

  function clearEditPreviewUrls() {
    if (currentImagePreviewUrl) URL.revokeObjectURL(currentImagePreviewUrl);
    if (replacementPreviewUrl) URL.revokeObjectURL(replacementPreviewUrl);
    currentImagePreviewUrl = '';
    replacementPreviewUrl = '';
  }

  function resetEditor(dialog) {
    editLoadGeneration += 1;
    clearEditPreviewUrls();
    const editForm = dialog.querySelector('#creatorEditForm');
    editForm?.reset();
    editForm?.removeAttribute('aria-busy');
    if (editForm) {
      editForm.dataset.hasCurrentImage = 'false';
      editForm.dataset.currentImageUrl = '';
      editForm.dataset.postId = '';
      editForm.dataset.updatedAt = '';
      editForm.dataset.initialState = '';
    }
    richText.setEditorContent(dialog.querySelector('#creatorEditText'), '');
    window.ZNewsCategoryPicker?.set(dialog.querySelector('#creatorEditCategory'), '', { notify: false });
    const preview = dialog.querySelector('#creatorEditPreview');
    if (preview) {
      preview.textContent = '';
      preview.hidden = true;
    }
    const error = dialog.querySelector('#creatorEditError');
    if (error) {
      error.textContent = '';
      error.hidden = true;
    }
    const photoLabel = dialog.querySelector('#creatorEditPhotoLabel');
    if (photoLabel) photoLabel.textContent = 'Add photo';
    const titleCount = dialog.querySelector('#creatorEditTitleCount');
    if (titleCount) titleCount.textContent = '0 / 160';
    const textCount = dialog.querySelector('#creatorEditTextCount');
    if (textCount) textCount.textContent = '0 / 5000';
    const creatorName = dialog.querySelector('#creatorEditName');
    if (creatorName) creatorName.textContent = 'Z-Pay creator';
  }

  function renderEditPreview(dialog) {
    const editForm = dialog.querySelector('#creatorEditForm');
    const preview = dialog.querySelector('#creatorEditPreview');
    const input = dialog.querySelector('#creatorEditImage');
    const remove = dialog.querySelector('#creatorRemoveImage');
    const file = input.files?.[0] || null;
    if (replacementPreviewUrl) URL.revokeObjectURL(replacementPreviewUrl);
    replacementPreviewUrl = '';

    let imageUrl = '';
    if (file) {
      replacementPreviewUrl = URL.createObjectURL(file);
      imageUrl = replacementPreviewUrl;
    } else if (!remove.checked) {
      imageUrl = currentImagePreviewUrl || safeUrl(editForm.dataset.currentImageUrl);
    }

    preview.textContent = '';
    preview.hidden = !imageUrl;
    if (imageUrl) {
      const image = document.createElement('img');
      image.className = 'composer-image-foreground';
      image.src = imageUrl;
      image.alt = 'Selected post photo';
      const removeButton = document.createElement('button');
      removeButton.className = 'composer-image-remove';
      removeButton.type = 'button';
      removeButton.setAttribute('aria-label', 'Remove photo');
      removeButton.textContent = '×';
      removeButton.addEventListener('click', () => {
        input.value = '';
        remove.checked = editForm.dataset.hasCurrentImage === 'true';
        renderEditPreview(dialog);
        syncEditor(dialog);
      });
      preview.append(image, removeButton);
    }

    dialog.querySelector('#creatorEditPhotoLabel').textContent = imageUrl ? 'Replace photo' : 'Add photo';
  }

  function editorSnapshot(dialog) {
    const formElement = dialog.querySelector('#creatorEditForm');
    const body = dialog.querySelector('#creatorEditText');
    const replacement = dialog.querySelector('#creatorEditImage').files?.[0] || null;
    const parsedBody = richText.getEditorPayload(body);
    return JSON.stringify({
      title: dialog.querySelector('#creatorEditTitle').value.trim(),
      text: parsedBody.text,
      formattingRuns: parsedBody.formattingRuns,
      category: dialog.querySelector('#creatorEditCategory').value,
      replacement: replacement ? `${replacement.name}:${replacement.size}:${replacement.lastModified}` : '',
      removeImage: dialog.querySelector('#creatorRemoveImage').checked,
      hasCurrentImage: formElement.dataset.hasCurrentImage === 'true'
    });
  }

  function syncEditor(dialog) {
    const title = dialog.querySelector('#creatorEditTitle');
    const body = dialog.querySelector('#creatorEditText');
    const category = dialog.querySelector('#creatorEditCategory');
    const formElement = dialog.querySelector('#creatorEditForm');
    const replacement = dialog.querySelector('#creatorEditImage').files?.[0] || null;
    const currentImageKept = formElement.dataset.hasCurrentImage === 'true'
      && !dialog.querySelector('#creatorRemoveImage').checked;
    dialog.querySelector('#creatorEditTitleCount').textContent = `${title.value.length} / 160`;
    const parsedBody = richText.getEditorPayload(body);
    dialog.querySelector('#creatorEditTextCount').textContent = `${Array.from(parsedBody.text).length} / 5000`;
    body.style.height = 'auto';
    body.style.height = `${Math.min(260, Math.max(84, body.scrollHeight))}px`;
    const valid = Boolean(
      title.value.trim()
      && ['INTERNATIONAL_NEWS', 'BD_NEWS', 'MOBILE_PRICING'].includes(category.value)
      && (body.value.trim() || replacement || currentImageKept)
    );
    const currentState = editorSnapshot(dialog);
    const changed = formElement.dataset.initialState !== '' && currentState !== formElement.dataset.initialState;
    dialog.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = !valid || !changed; });
  }

  function ensureActionDialog() {
    let dialog = document.querySelector('#creatorActionDialog');
    if (dialog) return dialog;

    dialog = document.createElement('dialog');
    dialog.id = 'creatorActionDialog';
    dialog.className = 'creator-action-dialog';
    dialog.innerHTML = `
      <div class="creator-action-shell" role="document">
        <span class="creator-action-spinner" aria-hidden="true"></span>
        <h2 id="creatorActionTitle">Please wait…</h2>
        <p id="creatorActionMessage">Loading your post.</p>
        <p class="creator-action-error" id="creatorActionError" hidden></p>
        <div class="creator-action-buttons" hidden>
          <button class="ghost-button" type="button" data-action-cancel>Cancel</button>
          <button class="primary-button danger" type="button" data-action-confirm><span data-action-confirm-label>Delete</span></button>
        </div>
      </div>`;
    dialog.setAttribute('aria-labelledby', 'creatorActionTitle');
    dialog.setAttribute('aria-describedby', 'creatorActionMessage');
    document.body.appendChild(dialog);
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      if (dialog.getAttribute('aria-busy') !== 'true') closeActionDialog();
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog && dialog.getAttribute('aria-busy') !== 'true') closeActionDialog();
    });
    return dialog;
  }

  function clearActionHandlers() {
    if (typeof actionCleanup === 'function') actionCleanup();
    actionCleanup = null;
  }

  function showActionLoading(title, message, returnFocus = null) {
    const dialog = ensureActionDialog();
    const confirm = dialog.querySelector('[data-action-confirm]');
    const cancel = dialog.querySelector('[data-action-cancel]');
    clearActionHandlers();
    actionReturnFocus = returnFocus;
    dialog.dataset.mode = 'loading';
    dialog.querySelector('#creatorActionTitle').textContent = title;
    dialog.querySelector('#creatorActionMessage').textContent = message;
    dialog.querySelector('#creatorActionError').hidden = true;
    dialog.querySelector('.creator-action-spinner').hidden = false;
    dialog.querySelector('.creator-action-buttons').hidden = true;
    confirm.classList.remove('is-loading');
    confirm.disabled = false;
    cancel.disabled = false;
    dialog.setAttribute('aria-busy', 'true');
    if (!dialog.open) dialog.showModal();
  }

  function closeActionDialog() {
    const dialog = document.querySelector('#creatorActionDialog');
    clearActionHandlers();
    if (dialog?.open) dialog.close();
    dialog?.removeAttribute('aria-busy');
    const returnFocus = actionReturnFocus;
    actionReturnFocus = null;
    if (returnFocus?.isConnected) returnFocus.focus();
  }

  function showActionError(message, retry, returnFocus) {
    const dialog = ensureActionDialog();
    const confirm = dialog.querySelector('[data-action-confirm]');
    const cancel = dialog.querySelector('[data-action-cancel]');
    clearActionHandlers();
    actionReturnFocus = returnFocus || actionReturnFocus;
    dialog.dataset.mode = 'error';
    dialog.querySelector('#creatorActionTitle').textContent = 'Post could not be loaded';
    dialog.querySelector('#creatorActionMessage').textContent = message;
    dialog.querySelector('#creatorActionError').hidden = true;
    dialog.querySelector('.creator-action-spinner').hidden = true;
    dialog.querySelector('.creator-action-buttons').hidden = false;
    cancel.textContent = 'Close';
    confirm.classList.remove('danger');
    confirm.classList.remove('is-loading');
    confirm.querySelector('[data-action-confirm-label]').textContent = 'Retry';
    cancel.disabled = false;
    confirm.disabled = false;
    dialog.removeAttribute('aria-busy');
    if (!dialog.open) dialog.showModal();
    const onCancel = () => closeActionDialog();
    const onRetry = () => {
      clearActionHandlers();
      if (dialog.open) dialog.close();
      dialog.removeAttribute('aria-busy');
      actionReturnFocus = null;
      retry();
    };
    cancel.addEventListener('click', onCancel);
    confirm.addEventListener('click', onRetry);
    actionCleanup = () => {
      cancel.removeEventListener('click', onCancel);
      confirm.removeEventListener('click', onRetry);
    };
  }

  async function openEditor(postId, returnFocus = null) {
    if (editOpening) return;
    editOpening = true;
    const dialog = ensureEditor();
    dialog._returnFocus = returnFocus;
    resetEditor(dialog);
    const generation = ++editLoadGeneration;
    const editForm = dialog.querySelector('#creatorEditForm');
    const error = dialog.querySelector('#creatorEditError');
    error.hidden = true;

    const finishProgress = uiFeedback.beginProgress();
    let loadingVisible = false;
    const loadingTimer = window.setTimeout(() => {
      loadingVisible = true;
      showActionLoading('Loading post…', 'Preparing editor', returnFocus);
    }, 180);
    try {
      const result = await postDetails(postId);
      if (generation !== editLoadGeneration) return;
      const post = result.data?.post || {};
      const api = client();
      const creatorName = text(post.creator_name || api.profile.name || api.profile.NAME
        || api.profile.display_name || api.profile.phone || 'Z-Pay creator').trim();
      const creatorPhoto = text(post.creator_photo_url || api.profile.profile_photo_url
        || api.profile.photo_url || api.profile.PROFILE);
      dialog.querySelector('#creatorEditTitle').value = text(post.title);
      richText.setEditorContent(
        dialog.querySelector('#creatorEditText'),
        post.text,
        post.formatting_runs,
        post.bold_ranges
      );
      window.ZNewsCategoryPicker?.set(
        dialog.querySelector('#creatorEditCategory'),
        text(post.category).toUpperCase(),
        { notify: false }
      );
      dialog.querySelector('#creatorEditImage').value = '';
      dialog.querySelector('#creatorRemoveImage').checked = false;
      dialog.querySelector('#creatorEditName').textContent = creatorName;
      setAvatar(dialog.querySelector('#creatorEditAvatar'), creatorName, creatorPhoto);
      editForm.dataset.hasCurrentImage = text(post.image_media_id).trim() ? 'true' : 'false';
      editForm.dataset.currentImageUrl = safeUrl(post.image_url);
      editForm.dataset.postId = postId;
      editForm.dataset.updatedAt = text(post.updated_at);
      renderEditPreview(dialog);
      editForm.dataset.initialState = editorSnapshot(dialog);
      syncEditor(dialog);
      window.clearTimeout(loadingTimer);
      if (loadingVisible) closeActionDialog();
      if (!dialog.open) dialog.showModal();
      editOpening = false;
      if (editForm.dataset.hasCurrentImage === 'true' && post.image_preview_url) {
        try {
          const blob = await api.authenticatedMedia(post.image_preview_url);
          if (generation !== editLoadGeneration || !dialog.open) return;
          currentImagePreviewUrl = URL.createObjectURL(blob);
          renderEditPreview(dialog);
        } catch (_error) {
          // Active public posts can still use their public URL; edit remains usable without a preview.
        }
      }
    } catch (requestError) {
      if (generation !== editLoadGeneration) return;
      window.clearTimeout(loadingTimer);
      editOpening = false;
      showActionError(errorMessage(requestError), () => openEditor(postId, returnFocus), returnFocus);
    } finally {
      window.clearTimeout(loadingTimer);
      finishProgress();
    }
  }

  document.addEventListener('submit', async (event) => {
    const editForm = event.target.closest('#creatorEditForm');
    if (!editForm) return;
    event.preventDefault();
    if (editForm.getAttribute('aria-busy') === 'true') return;

    const error = editForm.querySelector('#creatorEditError');
    const postId = text(editForm.dataset.postId);
    const expectedUpdatedAt = Number(editForm.dataset.updatedAt || 0);
    const postTitle = text(editForm.querySelector('#creatorEditTitle').value).trim();
    const parsedText = richText.getEditorPayload(editForm.querySelector('#creatorEditText'));
    const postText = parsedText.text;
    const category = text(editForm.querySelector('#creatorEditCategory').value).toUpperCase();
    const replacement = editForm.querySelector('#creatorEditImage').files?.[0] || null;
    const removeImage = editForm.querySelector('#creatorRemoveImage').checked;

    if (!postTitle) {
      error.textContent = 'Add a news headline.';
      error.hidden = false;
      return;
    }
    if (!['INTERNATIONAL_NEWS', 'BD_NEWS', 'MOBILE_PRICING'].includes(category)) {
      error.textContent = 'Choose a post category.';
      error.hidden = false;
      return;
    }
    if (!postText && !replacement && (removeImage || editForm.dataset.hasCurrentImage !== 'true')) {
      error.textContent = 'Add post details or a photo.';
      error.hidden = false;
      return;
    }
    if (replacement && !['image/jpeg', 'image/png', 'image/webp'].includes(replacement.type)) {
      error.textContent = 'Choose a JPEG, PNG or WebP photo.';
      error.hidden = false;
      return;
    }
    if (replacement && replacement.size > 8 * 1024 * 1024) {
      error.textContent = 'Image must be 8 MB or smaller.';
      error.hidden = false;
      return;
    }

    error.hidden = true;
    editForm.setAttribute('aria-busy', 'true');
    const finishProgress = uiFeedback.beginProgress();
    setEditMutationState(ensureEditor(), true, replacement ? 'optimizing' : 'saving');
    try {
      const api = client();
      const body = {
        post_id: postId,
        title: postTitle,
        text: postText,
        bold_ranges: parsedText.boldRanges,
        formatting_runs: parsedText.formattingRuns,
        category,
        expected_updated_at: expectedUpdatedAt,
        idempotency_key: idempotency('post-edit')
      };

      if (replacement) {
        body.media_id = await uploadImage(api, replacement, (stage) => {
          setEditMutationState(ensureEditor(), true, stage);
        });
        setEditMutationState(ensureEditor(), true, 'publishing');
      } else if (removeImage) {
        body.media_id = '';
      }

      const result = await api.request('znews/posts/update.php', {
        method: 'POST',
        authenticated: true,
        body
      });

      ensureEditor().close();
      window.dispatchEvent(new CustomEvent('znews:creator-post-mutated', { detail: { postId, action: 'update' } }));
      toast(result.data?.published_immediately === true ? 'Saved' : 'Saved and sent for review.');
      document.querySelector('[data-route="mine"]')?.click();
    } catch (requestError) {
      error.textContent = errorMessage(requestError);
      error.hidden = false;
    } finally {
      finishProgress();
      editForm.removeAttribute('aria-busy');
      setEditMutationState(ensureEditor(), false);
      syncEditor(ensureEditor());
    }
  });

  function deletePost(postId, card, returnFocus) {
    const dialog = ensureActionDialog();
    const confirm = dialog.querySelector('[data-action-confirm]');
    const cancel = dialog.querySelector('[data-action-cancel]');
    const error = dialog.querySelector('#creatorActionError');
    const spinner = dialog.querySelector('.creator-action-spinner');
    const buttons = dialog.querySelector('.creator-action-buttons');
    clearActionHandlers();
    actionReturnFocus = returnFocus;
    dialog.dataset.mode = 'delete';
    dialog.querySelector('#creatorActionTitle').textContent = 'Delete this post?';
    dialog.querySelector('#creatorActionMessage').textContent = 'This action cannot be undone.';
    error.textContent = '';
    error.hidden = true;
    spinner.hidden = true;
    buttons.hidden = false;
    cancel.textContent = 'Cancel';
    confirm.classList.add('danger');
    confirm.classList.remove('is-loading');
    confirm.querySelector('[data-action-confirm-label]').textContent = 'Delete';
    cancel.disabled = false;
    confirm.disabled = false;
    dialog.removeAttribute('aria-busy');
    if (!dialog.open) dialog.showModal();

    let busy = false;
    const onCancel = () => { if (!busy) closeActionDialog(); };
    const onConfirm = async () => {
      if (busy) return;
      busy = true;
      dialog.setAttribute('aria-busy', 'true');
      cancel.disabled = true;
      confirm.disabled = true;
      confirm.classList.add('is-loading');
      confirm.querySelector('[data-action-confirm-label]').textContent = 'Deleting…';
      error.hidden = true;
      const finishProgress = uiFeedback.beginProgress();
      try {
        const details = await postDetails(postId);
        const post = details.data?.post || {};
        await client().request('znews/posts/delete.php', {
          method: 'POST',
          authenticated: true,
          body: {
            post_id: postId,
            expected_updated_at: Number(post.updated_at || 0),
            idempotency_key: idempotency('post-delete')
          }
        });
        closeActionDialog();
        card?.classList.add('creator-card-removing');
        window.setTimeout(() => {
          card?.remove();
          if (!mineList.querySelector('.post-card')) {
            mineList.innerHTML = '<div class="empty-state card"><strong>No posts yet</strong>Create your first Z Sky 24 post.</div>';
          }
        }, 180);
        window.dispatchEvent(new CustomEvent('znews:creator-post-mutated', { detail: { postId, action: 'delete' } }));
        toast('Post deleted');
      } catch (requestError) {
        busy = false;
        dialog.removeAttribute('aria-busy');
        cancel.disabled = false;
        confirm.disabled = false;
        confirm.classList.remove('is-loading');
        confirm.querySelector('[data-action-confirm-label]').textContent = 'Delete';
        error.textContent = errorMessage(requestError);
        error.hidden = false;
      } finally {
        finishProgress();
      }
    };
    cancel.addEventListener('click', onCancel);
    confirm.addEventListener('click', onConfirm);
    actionCleanup = () => {
      cancel.removeEventListener('click', onCancel);
      confirm.removeEventListener('click', onConfirm);
    };
  }

  function ensureCardMenu() {
    let dialog = document.querySelector('#creatorCardMenuDialog');
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.id = 'creatorCardMenuDialog';
    dialog.className = 'creator-card-menu-dialog';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'creatorCardMenuTitle');
    dialog.innerHTML = `
      <div class="creator-card-menu-sheet">
        <header><h2 id="creatorCardMenuTitle">Post options</h2></header>
        <div class="creator-card-menu-actions" role="menu" aria-labelledby="creatorCardMenuTitle">
          <button type="button" role="menuitem" data-menu-edit><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 16.5V20h3.5L18.8 8.7l-3.5-3.5L4 16.5Zm16.5-10.3a1 1 0 0 0 0-1.4l-1.3-1.3a1 1 0 0 0-1.4 0l-1.5 1.5 3.5 3.5 1.7-1.7Z"/></svg><span>Edit post</span></button>
          <button class="danger" type="button" role="menuitem" data-menu-delete><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 20a2 2 0 0 1-2-2V7h14v11a2 2 0 0 1-2 2H7Zm1-3h2V10H8v7Zm4 0h2V10h-2v7Zm4 0h1V10h-1v7ZM8 5l1-2h6l1 2h4v2H4V5h4Z"/></svg><span>Delete post</span></button>
        </div>
        <button class="creator-card-menu-cancel" type="button" data-menu-close>Cancel</button>
      </div>`;
    document.body.appendChild(dialog);
    const close = ({ restore = true } = {}) => {
      const trigger = dialog._trigger;
      if (dialog.open) dialog.close();
      trigger?.setAttribute('aria-expanded', 'false');
      dialog._trigger = null;
      if (restore && trigger?.isConnected) trigger.focus();
    };
    dialog.querySelectorAll('[data-menu-close]').forEach((button) => button.addEventListener('click', () => close()));
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });
    dialog.addEventListener('click', (event) => { if (event.target === dialog) close(); });
    dialog._closeMenu = close;
    return dialog;
  }

  function openCardMenu(card, trigger, blocked) {
    const dialog = ensureCardMenu();
    if (dialog.open) dialog._closeMenu({ restore: false });
    const postId = text(card.dataset.postId);
    dialog._trigger = trigger;
    trigger.setAttribute('aria-expanded', 'true');
    const edit = dialog.querySelector('[data-menu-edit]');
    const remove = dialog.querySelector('[data-menu-delete]');
    edit.disabled = blocked;
    edit.onclick = () => {
      dialog._closeMenu({ restore: false });
      openEditor(postId, trigger);
    };
    remove.onclick = () => {
      dialog._closeMenu({ restore: false });
      deletePost(postId, card, trigger);
    };
    dialog.showModal();
    (blocked ? remove : edit).focus();
  }

  function enhanceMineCards() {
    mineList.querySelectorAll('.post-card[data-post-id]').forEach((card) => {
      if (card.querySelector('[data-creator-menu]')) return;
      const chip = card.querySelector('.status-chip');
      const blocked = chip?.textContent?.toUpperCase().includes('BLOCKED') === true;
      const trigger = document.createElement('button');
      trigger.className = 'creator-overflow-button';
      trigger.type = 'button';
      trigger.dataset.creatorMenu = '';
      trigger.setAttribute('aria-label', 'Post options');
      trigger.setAttribute('aria-haspopup', 'menu');
      trigger.setAttribute('aria-expanded', 'false');
      trigger.setAttribute('aria-controls', 'creatorCardMenuDialog');
      trigger.textContent = '⋮';
      trigger.addEventListener('click', (event) => {
        event.stopPropagation();
        openCardMenu(card, trigger, blocked);
      });
      card.querySelector('.post-head')?.appendChild(trigger);
    });
  }

  const observer = new MutationObserver(enhanceMineCards);
  observer.observe(mineList, { childList: true, subtree: true });
  enhanceMineCards();

  const style = document.createElement('style');
  style.textContent = `
    .creator-overflow-button{width:44px;height:44px;flex:0 0 44px;display:grid;place-items:center;margin-left:auto;padding:0;border:0;border-radius:12px;background:transparent;color:#cbd9ea;font-size:27px;line-height:1;cursor:pointer;touch-action:manipulation}
    .creator-overflow-button:hover,.creator-overflow-button:focus-visible{background:rgba(255,255,255,.07);color:#fff;outline:2px solid rgba(97,226,156,.52);outline-offset:1px}
    .creator-overflow-button:active{transform:scale(.92);background:rgba(101,224,159,.12)}
    .creator-card-menu-dialog{width:min(92vw,360px);padding:0;border:0;background:transparent;color:#f4f8ff}
    .creator-card-menu-dialog::backdrop{background:rgba(0,8,20,.68);backdrop-filter:blur(5px)}
    .creator-card-menu-sheet{display:grid;gap:8px;padding:18px;border:0;border-radius:18px;background:rgba(8,29,53,.97);box-shadow:0 26px 76px rgba(0,0,0,.52);backdrop-filter:blur(22px);animation:creator-sheet-in .18s ease-out}
    .creator-card-menu-sheet header{padding:2px 4px 10px;border-bottom:1px solid rgba(142,177,226,.12)}
    .creator-card-menu-sheet h2{margin:0;font-size:16px;letter-spacing:0}
    .creator-card-menu-actions{display:grid;gap:4px}
    .creator-card-menu-actions button,.creator-card-menu-cancel{width:100%;min-height:54px;display:flex;align-items:center;gap:13px;padding:0 14px;border:0;border-radius:12px;background:transparent;color:#eaf2fc;text-align:left;font:inherit;font-size:15px;font-weight:800;cursor:pointer;touch-action:manipulation}
    .creator-card-menu-actions button svg{width:21px;height:21px;flex:0 0 21px;fill:currentColor}
    .creator-card-menu-actions button:hover,.creator-card-menu-actions button:focus-visible,.creator-card-menu-cancel:hover,.creator-card-menu-cancel:focus-visible{background:rgba(91,225,151,.09);outline:2px solid rgba(91,225,151,.34);outline-offset:-2px}
    .creator-card-menu-actions button:active,.creator-card-menu-cancel:active{transform:scale(.985);background:rgba(91,225,151,.14)}
    .creator-card-menu-actions button.danger{color:#ff8999}
    .creator-card-menu-actions button.danger:hover,.creator-card-menu-actions button.danger:focus-visible{background:rgba(173,45,66,.18);outline-color:rgba(255,102,122,.32)}
    .creator-card-menu-cancel{justify-content:center;border-top:1px solid rgba(142,177,226,.12);border-radius:0;color:#aebfd6;text-align:center}
    .creator-edit-dialog{width:min(100%,680px);max-width:680px;max-height:min(94dvh,920px);padding:0;border:0;border-radius:20px;background:#0a203b;color:#f3f7fd;overflow:hidden}
    .creator-edit-dialog::backdrop{background:rgba(0,10,24,.78);backdrop-filter:blur(6px)}
    .creator-edit-form{max-height:min(94dvh,920px);overflow-x:hidden;overflow-y:auto;padding-bottom:18px;overscroll-behavior:contain}
    .creator-edit-form .image-preview{position:relative;width:min(calc(100% - 32px),420px);margin:4px 16px 12px;overflow:hidden;border:1px solid rgba(142,177,226,.2);border-radius:13px;background:#020915}
    .creator-edit-form .image-preview img{display:block;width:100%;height:auto;object-fit:contain}
    .creator-edit-form .composer-image-foreground{position:relative;z-index:1;object-fit:contain}
    .creator-edit-form .form-error{margin:8px 16px 0}
    .creator-edit-form[aria-busy="true"] [data-close]{opacity:.45;pointer-events:none}
    .creator-action-dialog{width:min(90vw,390px);padding:0;border:0;border-radius:18px;background:transparent;color:#f7fbff}
    .creator-action-dialog::backdrop{background:rgba(0,10,24,.72);backdrop-filter:blur(5px)}
    .creator-action-shell{display:grid;justify-items:center;gap:12px;padding:26px 22px;border:0;border-radius:18px;background:rgba(9,33,59,.97);box-shadow:0 24px 70px rgba(0,0,0,.48);text-align:center;backdrop-filter:blur(18px);animation:creator-sheet-in .18s ease-out}
    .creator-action-shell h2,.creator-action-shell p{margin:0}
    .creator-action-shell p{color:#aebed4}
    .creator-action-spinner{width:42px;height:42px;border:4px solid rgba(116,231,168,.2);border-top-color:#74e7a8;border-radius:50%;animation:creator-action-spin .75s linear infinite}
    .creator-action-error{width:100%;padding:9px 11px;border-radius:9px;background:rgba(181,49,69,.14);color:#ffbdc6;font-size:12px}
    .creator-action-buttons{display:flex;width:100%;gap:10px;margin-top:8px}
    .creator-action-buttons button{box-sizing:border-box;flex:1 1 0;width:0;min-width:0;height:50px;min-height:50px;padding:0 14px;border:1px solid transparent;border-radius:12px;font-size:14px;line-height:1}
    .creator-action-buttons .danger{border-color:rgba(255,102,122,.38);background:#9f3044;color:#fff;box-shadow:none}
    .creator-action-buttons .danger:hover{background:#b63a50}
    .creator-action-buttons [data-action-confirm].is-loading{display:flex;align-items:center;justify-content:center;gap:8px}
    .creator-action-buttons [data-action-confirm].is-loading::before{content:"";width:16px;height:16px;border:2px solid rgba(255,255,255,.35);border-top-color:#fff;border-radius:50%;animation:creator-action-spin .75s linear infinite}
    .creator-card-removing{opacity:0;transform:translateY(-8px);transition:opacity .18s ease,transform .18s ease}
    @keyframes creator-action-spin{to{transform:rotate(360deg)}}
    @keyframes creator-sheet-in{from{opacity:0;transform:translateY(12px) scale(.985)}to{opacity:1;transform:none}}
    @media (prefers-reduced-motion:reduce){.creator-action-spinner{animation-duration:1.5s}}
    @media(max-width:780px){
      .creator-edit-dialog{inset:0;width:100%;max-width:none;height:100dvh;max-height:none;margin:0;border-radius:0;background:linear-gradient(180deg,#0c203b 0%,#07162a 100%)}
      .creator-edit-form{height:100dvh;max-height:none;padding-top:64px;padding-bottom:calc(94px + env(safe-area-inset-bottom,0px))}
      .creator-edit-topbar{position:fixed;inset:0 0 auto;z-index:90;width:100%;min-height:64px;background:rgba(8,25,47,.96);backdrop-filter:blur(16px)}
      .creator-edit-form .composer-author{padding:12px 22px 5px}
      .creator-edit-form .composer-writing-fields{padding:14px 22px}
      .creator-edit-form .image-preview{width:calc(100% - 44px);margin:2px 22px 14px}
      .creator-edit-form .composer-add-row{margin:0 22px;border-radius:18px}
      .creator-edit-form .composer-photo-action{border-radius:18px}
      .creator-edit-form .composer-review-note{margin:10px 24px 12px}
      .creator-edit-bottom-action{position:fixed;inset:auto 0 0;z-index:90;display:block;padding:10px 22px calc(12px + env(safe-area-inset-bottom,0px));background:linear-gradient(180deg,rgba(7,22,42,.2),#07162a 28%);backdrop-filter:blur(14px)}
      .creator-edit-bottom-action .composer-bottom-submit{min-height:52px;border-radius:17px}
      .creator-card-menu-dialog{position:fixed;inset:auto 0 0;width:100%;max-width:none;margin:0;border-radius:22px 22px 0 0}
      .creator-card-menu-sheet{width:100%;padding:18px 18px calc(14px + env(safe-area-inset-bottom,0px));border-radius:22px 22px 0 0}
      .creator-action-dialog{position:fixed;inset:auto 0 0;width:100%;max-width:none;margin:0;border-radius:22px 22px 0 0}
      .creator-action-shell{padding:25px 22px calc(22px + env(safe-area-inset-bottom,0px));border-radius:22px 22px 0 0}
    }
  `;
  document.head.appendChild(style);
})();
