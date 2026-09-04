(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const EDITABLE_CATEGORIES = Object.freeze(['INTERNATIONAL_NEWS', 'BD_NEWS', 'MOBILE_PRICING']);
  const form = document.querySelector('#createPostForm');
  const mineList = document.querySelector('#mineList');
  const toastRegion = document.querySelector('#toastRegion');

  if (!config || !form || !mineList || !window.ZNewsApiClient) return;

  const client = () => new window.ZNewsApiClient(config);
  const text = (value) => String(value ?? '');
  const canonicalCategory = (value) => {
    const normalized = text(value).trim().toUpperCase();
    return EDITABLE_CATEGORIES.includes(normalized) ? normalized : '';
  };
  const richText = window.ZNewsRichText || {
    getEditorPayload: (editor) => ({ text: text(editor?.textContent).trim(), boldRanges: [], formattingRuns: [] }),
    setEditorContent: (editor, value) => { if (editor) editor.textContent = text(value); },
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
        <button class="composer-format-button composer-color-button" type="button" data-format-color-toggle data-color="default" aria-label="Text color: Default" aria-haspopup="dialog" aria-expanded="false" aria-pressed="false" aria-controls="${prefix}Palette"><strong>A</strong><span class="format-color-swatch" aria-hidden="true"></span></button>
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
            <span class="composer-field-label" id="creatorEditTextLabel">Post details</span>
            ${formattingToolbarMarkup('creatorEditFormat')}
            <div class="rich-editor-editable" id="creatorEditText" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="creatorEditTextLabel" aria-describedby="creatorEditNote creatorEditTextCount" data-placeholder="Write the story or update…" data-maxlength="5000" spellcheck="true" dir="auto"></div>
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
    categoryInput?.addEventListener('change', (event) => {
      if (event.detail?.source === 'user') {
        dialog.querySelector('#creatorEditForm').dataset.categoryTouched = 'true';
      }
      syncEditor(dialog);
    });
    dialog.querySelector('#creatorEditTitle')?.addEventListener('input', () => syncEditor(dialog));
    const body = dialog.querySelector('#creatorEditText');
    body?.addEventListener('input', (event) => {
      if (event.isComposing || event.inputType === 'insertCompositionText') return;
      syncEditor(dialog);
    });
    body?.addEventListener('znews:editor-sync', () => syncEditor(dialog));
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
      editForm.dataset.originalCategory = '';
      editForm.dataset.categoryTouched = 'false';
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
    const valid = Boolean(
      title.value.trim()
      && ['INTERNATIONAL_NEWS', 'BD_NEWS', 'MOBILE_PRICING'].includes(category.value)
      && (parsedBody.text || replacement || currentImageKept)
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
      <div class="creator-action-shell znews-creator-sheet" role="document">
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
      const initialCategory = canonicalCategory(post.category);
      if (!initialCategory) {
        const categoryError = new Error('The post category could not be loaded safely.');
        categoryError.code = 'ZNEWS_POST_CATEGORY_INVALID';
        throw categoryError;
      }
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
        initialCategory,
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
      editForm.dataset.originalCategory = initialCategory;
      editForm.dataset.categoryTouched = 'false';
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
    const selectedCategory = canonicalCategory(editForm.querySelector('#creatorEditCategory').value);
    const originalCategory = canonicalCategory(editForm.dataset.originalCategory);
    const category = editForm.dataset.categoryTouched === 'true' ? selectedCategory : originalCategory;
    const replacement = editForm.querySelector('#creatorEditImage').files?.[0] || null;
    const removeImage = editForm.querySelector('#creatorRemoveImage').checked;

    if (!postTitle) {
      error.textContent = 'Add a news headline.';
      error.hidden = false;
      return;
    }
    if (!EDITABLE_CATEGORIES.includes(category)) {
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

      const updatedPost = result.data?.post && typeof result.data.post === 'object'
        ? result.data.post
        : {
            post_id: postId,
            title: postTitle,
            text: postText,
            bold_ranges: parsedText.boldRanges,
            formatting_runs: parsedText.formattingRuns,
            category
          };
      ensureEditor().close();
      window.dispatchEvent(new CustomEvent('znews:creator-post-mutated', {
        detail: { postId, action: 'update', post: updatedPost }
      }));
      toast(result.data?.published_immediately === true ? 'Saved' : 'Saved and sent for review.');
      if (document.documentElement.dataset.znewsRoute !== 'mine') {
        document.querySelector('[data-route="mine"]')?.click();
      }
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
    if (window.matchMedia('(min-width: 781px)').matches) {
      const rect = trigger.getBoundingClientRect();
      const width = 360;
      dialog.style.left = `${Math.max(12, Math.min(window.innerWidth - width - 12, rect.right - width))}px`;
      dialog.style.top = `${Math.max(12, Math.min(window.innerHeight - 260, rect.bottom + 8))}px`;
    } else {
      dialog.style.removeProperty('left');
      dialog.style.removeProperty('top');
    }
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
})();
