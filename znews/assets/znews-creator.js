(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const form = document.querySelector('#createPostForm');
  const mineList = document.querySelector('#mineList');
  const toastRegion = document.querySelector('#toastRegion');

  if (!config || !form || !mineList || !window.ZNewsApiClient) return;

  const client = () => new window.ZNewsApiClient(config);
  const text = (value) => String(value ?? '');
  let currentImagePreviewUrl = '';
  let replacementPreviewUrl = '';
  let editLoadGeneration = 0;

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
    const optimized = await optimizer.optimize(file, onStatus);
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
            <label for="creatorEditCategory">Category</label>
            <select id="creatorEditCategory" required>
              <option value="">Choose a category</option>
              <option value="INTERNATIONAL_NEWS">International news</option>
              <option value="BD_NEWS">BD news</option>
              <option value="MOBILE_PRICING">Mobile pricing</option>
            </select>
          </div>
          <div class="composer-field composer-title-field">
            <label for="creatorEditTitle">News headline</label>
            <input id="creatorEditTitle" type="text" maxlength="160" placeholder="Add a clear headline" required aria-describedby="creatorEditTitleCount">
            <span class="composer-field-count" id="creatorEditTitleCount">0 / 160</span>
          </div>
          <div class="composer-field composer-body-field">
            <label for="creatorEditText">Post details</label>
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
    dialog.addEventListener('close', () => resetEditor(dialog));
    dialog.querySelector('#creatorEditCategory')?.addEventListener('change', () => syncEditor(dialog));
    dialog.querySelector('#creatorEditTitle')?.addEventListener('input', () => syncEditor(dialog));
    dialog.querySelector('#creatorEditText')?.addEventListener('input', () => syncEditor(dialog));
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
    }
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
      const backdrop = document.createElement('img');
      backdrop.className = 'composer-image-backdrop';
      backdrop.src = imageUrl;
      backdrop.alt = '';
      backdrop.setAttribute('aria-hidden', 'true');
      const foreground = document.createElement('img');
      foreground.className = 'composer-image-foreground';
      foreground.src = imageUrl;
      foreground.alt = 'Selected post photo';
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
      preview.append(backdrop, foreground, removeButton);
    }

    dialog.querySelector('#creatorEditPhotoLabel').textContent = imageUrl ? 'Replace photo' : 'Add photo';
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
    dialog.querySelector('#creatorEditTextCount').textContent = `${body.value.length} / 5000`;
    body.style.height = 'auto';
    body.style.height = `${Math.min(210, Math.max(112, body.scrollHeight))}px`;
    const enabled = Boolean(
      title.value.trim()
      && ['INTERNATIONAL_NEWS', 'BD_NEWS', 'MOBILE_PRICING'].includes(category.value)
      && (body.value.trim() || replacement || currentImageKept)
    );
    dialog.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = !enabled; });
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
        <div class="creator-action-buttons" hidden>
          <button class="ghost-button" type="button" data-action-cancel>Cancel</button>
          <button class="primary-button danger" type="button" data-action-confirm>Delete</button>
        </div>
      </div>`;
    dialog.setAttribute('aria-labelledby', 'creatorActionTitle');
    dialog.setAttribute('aria-describedby', 'creatorActionMessage');
    document.body.appendChild(dialog);
    return dialog;
  }

  function showActionLoading(title, message) {
    const dialog = ensureActionDialog();
    dialog.dataset.mode = 'loading';
    dialog.querySelector('#creatorActionTitle').textContent = title;
    dialog.querySelector('#creatorActionMessage').textContent = message;
    dialog.querySelector('.creator-action-spinner').hidden = false;
    dialog.querySelector('.creator-action-buttons').hidden = true;
    dialog.setAttribute('aria-busy', 'true');
    if (!dialog.open) dialog.showModal();
  }

  function closeActionDialog() {
    const dialog = document.querySelector('#creatorActionDialog');
    if (dialog?.open) dialog.close();
    dialog?.removeAttribute('aria-busy');
  }

  function confirmDelete() {
    const dialog = ensureActionDialog();
    const confirm = dialog.querySelector('[data-action-confirm]');
    const cancel = dialog.querySelector('[data-action-cancel]');
    const buttons = dialog.querySelector('.creator-action-buttons');
    const spinner = dialog.querySelector('.creator-action-spinner');
    dialog.dataset.mode = 'confirm';
    dialog.querySelector('#creatorActionTitle').textContent = 'Delete this post?';
    dialog.querySelector('#creatorActionMessage').textContent = 'This action cannot be undone.';
    spinner.hidden = true;
    buttons.hidden = false;
    dialog.removeAttribute('aria-busy');
    if (!dialog.open) dialog.showModal();

    return new Promise((resolve) => {
      const finish = (approved) => {
        confirm.removeEventListener('click', approve);
        cancel.removeEventListener('click', reject);
        dialog.removeEventListener('cancel', rejectEvent);
        closeActionDialog();
        resolve(approved);
      };
      const approve = () => finish(true);
      const reject = () => finish(false);
      const rejectEvent = (event) => {
        event.preventDefault();
        finish(false);
      };
      confirm.addEventListener('click', approve);
      cancel.addEventListener('click', reject);
      dialog.addEventListener('cancel', rejectEvent);
    });
  }

  async function openEditor(postId) {
    const dialog = ensureEditor();
    resetEditor(dialog);
    const generation = ++editLoadGeneration;
    const editForm = dialog.querySelector('#creatorEditForm');
    const error = dialog.querySelector('#creatorEditError');
    error.hidden = true;

    showActionLoading('Loading post…', 'Preparing the editor.');
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
      dialog.querySelector('#creatorEditText').value = text(post.text);
      dialog.querySelector('#creatorEditCategory').value = text(post.category).toUpperCase();
      dialog.querySelector('#creatorEditImage').value = '';
      dialog.querySelector('#creatorRemoveImage').checked = false;
      dialog.querySelector('#creatorEditName').textContent = creatorName;
      setAvatar(dialog.querySelector('#creatorEditAvatar'), creatorName, creatorPhoto);
      editForm.dataset.hasCurrentImage = text(post.image_media_id).trim() ? 'true' : 'false';
      editForm.dataset.currentImageUrl = safeUrl(post.image_url);
      editForm.dataset.postId = postId;
      editForm.dataset.updatedAt = text(post.updated_at);
      renderEditPreview(dialog);
      syncEditor(dialog);
      closeActionDialog();
      if (!dialog.open) dialog.showModal();
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
      closeActionDialog();
      toast(errorMessage(requestError), 'error');
    }
  }

  document.addEventListener('submit', async (event) => {
    const editForm = event.target.closest('#creatorEditForm');
    if (!editForm) return;
    event.preventDefault();
    if (editForm.getAttribute('aria-busy') === 'true') return;

    const submits = [...editForm.querySelectorAll('button[type="submit"]')];
    const error = editForm.querySelector('#creatorEditError');
    const postId = text(editForm.dataset.postId);
    const expectedUpdatedAt = Number(editForm.dataset.updatedAt || 0);
    const postTitle = text(editForm.querySelector('#creatorEditTitle').value).trim();
    const postText = text(editForm.querySelector('#creatorEditText').value).trim();
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
    submits.forEach((button) => setBusy(button, true, replacement ? 'Uploading…' : 'Saving…'));
    try {
      const api = client();
      const body = {
        post_id: postId,
        title: postTitle,
        text: postText,
        category,
        expected_updated_at: expectedUpdatedAt,
        idempotency_key: idempotency('post-edit')
      };

      if (replacement) {
        body.media_id = await uploadImage(api, replacement, (label) => {
          submits.forEach((button) => { button.textContent = label; });
        });
        submits.forEach((button) => { button.textContent = 'Saving...'; });
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
      toast(result.data?.published_immediately === true
        ? 'Post updated and published.'
        : 'Post update is being checked.');
      document.querySelector('[data-route="mine"]')?.click();
    } catch (requestError) {
      error.textContent = errorMessage(requestError);
      error.hidden = false;
    } finally {
      editForm.removeAttribute('aria-busy');
      submits.forEach((button) => setBusy(button, false));
      syncEditor(ensureEditor());
    }
  });

  async function deletePost(postId) {
    if (!await confirmDelete()) return;
    showActionLoading('Deleting post…', 'Please wait while the post is removed.');
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
      window.dispatchEvent(new CustomEvent('znews:creator-post-mutated', { detail: { postId, action: 'delete' } }));
      toast('Post deleted.');
      document.querySelector('[data-route="mine"]')?.click();
    } catch (error) {
      toast(errorMessage(error), 'error');
    } finally {
      closeActionDialog();
    }
  }

  function enhanceMineCards() {
    mineList.querySelectorAll('[data-post-id]').forEach((card) => {
      if (card.querySelector('.creator-management')) return;
      const postId = text(card.dataset.postId);
      const chip = card.querySelector('.status-chip');
      const blocked = chip?.textContent?.toUpperCase().includes('BLOCKED') === true;
      const controls = document.createElement('div');
      controls.className = 'creator-management';
      controls.innerHTML = `
        <button class="ghost-button" type="button" data-creator-edit ${blocked ? 'disabled' : ''}>Edit</button>
        <button class="ghost-button danger" type="button" data-creator-delete>Delete</button>`;
      controls.querySelector('[data-creator-edit]')?.addEventListener('click', (event) => {
        event.stopPropagation();
        openEditor(postId);
      });
      controls.querySelector('[data-creator-delete]')?.addEventListener('click', (event) => {
        event.stopPropagation();
        deletePost(postId);
      });
      card.appendChild(controls);
    });
  }

  const observer = new MutationObserver(enhanceMineCards);
  observer.observe(mineList, { childList: true, subtree: true });
  enhanceMineCards();

  const style = document.createElement('style');
  style.textContent = `
    .creator-management{display:flex;gap:10px;padding:0 16px 16px}
    .creator-management .ghost-button{flex:1}
    .creator-management .danger{border-color:rgba(255,107,107,.45);color:#ff9b9b}
    .creator-edit-dialog{width:min(100%,680px);max-width:680px;max-height:min(94dvh,920px);padding:0;border:0;border-radius:24px;background:#0a203b;color:#f3f7fd;overflow:hidden}
    .creator-edit-dialog::backdrop{background:rgba(0,10,24,.78);backdrop-filter:blur(6px)}
    .creator-edit-form{max-height:min(94dvh,920px);overflow-x:hidden;overflow-y:auto;padding-bottom:18px;overscroll-behavior:contain}
    .creator-edit-form .image-preview{position:relative;width:min(calc(100% - 32px),420px);margin:4px 16px 12px;overflow:hidden;border:1px solid rgba(142,177,226,.2);border-radius:13px;background:#020915}
    .creator-edit-form .image-preview img{display:block;width:100%;height:auto;object-fit:contain}
    .creator-edit-form .composer-image-backdrop{display:none}
    .creator-edit-form .composer-image-foreground{position:relative;z-index:1;object-fit:contain}
    .creator-edit-form .form-error{margin:8px 16px 0}
    .creator-edit-form[aria-busy="true"] [data-close]{opacity:.45;pointer-events:none}
    .creator-action-dialog{width:min(88vw,390px);padding:0;border:0;border-radius:24px;background:transparent;color:#f7fbff}
    .creator-action-dialog::backdrop{background:rgba(0,10,24,.72);backdrop-filter:blur(5px)}
    .creator-action-shell{display:grid;justify-items:center;gap:12px;padding:28px 24px;border:1px solid rgba(151,190,235,.25);border-radius:24px;background:#09213b;box-shadow:0 24px 70px rgba(0,0,0,.48);text-align:center}
    .creator-action-shell h2,.creator-action-shell p{margin:0}
    .creator-action-shell p{color:#aebed4}
    .creator-action-spinner{width:42px;height:42px;border:4px solid rgba(116,231,168,.2);border-top-color:#74e7a8;border-radius:50%;animation:creator-action-spin .75s linear infinite}
    .creator-action-buttons{display:flex;width:100%;gap:10px;margin-top:8px}
    .creator-action-buttons button{flex:1;border-radius:16px}
    .creator-action-buttons .danger{background:linear-gradient(135deg,#e34d63,#ff7385);color:#fff}
    @keyframes creator-action-spin{to{transform:rotate(360deg)}}
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
    }
  `;
  document.head.appendChild(style);
})();
