(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const form = document.querySelector('#createPostForm');
  const mineList = document.querySelector('#mineList');
  const toastRegion = document.querySelector('#toastRegion');

  if (!config || !form || !mineList || !window.ZNewsApiClient) return;

  const client = () => new window.ZNewsApiClient(config);
  const text = (value) => String(value ?? '');

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

  async function uploadImage(api, file) {
    const body = new FormData();
    body.append('image', file);
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

  form.addEventListener('submit', async (event) => {
    const api = client();
    if (!api.isAuthenticated()) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    const submit = form.querySelector('button[type="submit"]');
    const postText = text(document.querySelector('#postText')?.value).trim();
    const imageInput = document.querySelector('#postImage');
    const file = imageInput?.files?.[0] || null;

    if (!postText && !file) return toast('Add text or an image.', 'error');
    if (file && file.size > 5 * 1024 * 1024) return toast('Image must be 5 MB or smaller.', 'error');

    setBusy(submit, true, file ? 'Uploading…' : 'Publishing…');
    try {
      let mediaId = '';
      if (file) {
        mediaId = await uploadImage(api, file);
        submit.textContent = 'Publishing…';
      }

      const result = await api.request('znews/posts/create.php', {
        method: 'POST',
        authenticated: true,
        body: {
          text: postText,
          media_id: mediaId,
          idempotency_key: idempotency('post')
        }
      });

      form.reset();
      const preview = document.querySelector('#imagePreview');
      if (preview) {
        preview.hidden = true;
        preview.textContent = '';
      }
      const counter = document.querySelector('#postTextCount');
      if (counter) counter.textContent = '0 / 5000';

      toast(result.data?.published_immediately === true
        ? 'Post published.'
        : 'Post is being checked before it appears publicly.');

      document.querySelector('[data-route="mine"]')?.click();
    } catch (error) {
      toast(errorMessage(error), 'error');
    } finally {
      setBusy(submit, false);
    }
  }, true);

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
    dialog.className = 'modal';
    dialog.innerHTML = `
      <form class="modal-shell stack-form" id="creatorEditForm">
        <button class="modal-close" type="button" data-close aria-label="Close">×</button>
        <span class="eyebrow">Creator</span>
        <h2>Edit post</h2>
        <label class="field-label" for="creatorEditText">Post text</label>
        <textarea id="creatorEditText" maxlength="5000" rows="7"></textarea>
        <label class="upload-box" for="creatorEditImage">
          <input id="creatorEditImage" type="file" accept="image/jpeg,image/png,image/webp">
          <strong>Replace image</strong>
          <small>Leave empty to keep the current image.</small>
        </label>
        <label class="creator-remove-row" id="creatorRemoveRow">
          <input id="creatorRemoveImage" type="checkbox"> Remove current image
        </label>
        <p class="form-error" id="creatorEditError" hidden></p>
        <button class="primary-button" type="submit">Save changes</button>
      </form>`;
    document.body.appendChild(dialog);
    dialog.querySelector('[data-close]')?.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      dialog.close();
    });
    return dialog;
  }

  async function openEditor(postId) {
    const dialog = ensureEditor();
    const editForm = dialog.querySelector('#creatorEditForm');
    const error = dialog.querySelector('#creatorEditError');
    error.hidden = true;

    try {
      const result = await postDetails(postId);
      const post = result.data?.post || {};
      dialog.querySelector('#creatorEditText').value = text(post.text);
      dialog.querySelector('#creatorEditImage').value = '';
      dialog.querySelector('#creatorRemoveImage').checked = false;
      dialog.querySelector('#creatorRemoveRow').hidden = !text(post.image_media_id).trim();
      editForm.dataset.postId = postId;
      editForm.dataset.updatedAt = text(post.updated_at);
      if (!dialog.open) dialog.showModal();
    } catch (requestError) {
      toast(errorMessage(requestError), 'error');
    }
  }

  document.addEventListener('submit', async (event) => {
    const editForm = event.target.closest('#creatorEditForm');
    if (!editForm) return;
    event.preventDefault();

    const submit = editForm.querySelector('button[type="submit"]');
    const error = editForm.querySelector('#creatorEditError');
    const postId = text(editForm.dataset.postId);
    const expectedUpdatedAt = Number(editForm.dataset.updatedAt || 0);
    const postText = text(editForm.querySelector('#creatorEditText').value).trim();
    const replacement = editForm.querySelector('#creatorEditImage').files?.[0] || null;
    const removeImage = editForm.querySelector('#creatorRemoveImage').checked;

    if (replacement && replacement.size > 5 * 1024 * 1024) {
      error.textContent = 'Image must be 5 MB or smaller.';
      error.hidden = false;
      return;
    }

    setBusy(submit, true, replacement ? 'Uploading…' : 'Saving…');
    try {
      const api = client();
      const body = {
        post_id: postId,
        text: postText,
        expected_updated_at: expectedUpdatedAt,
        idempotency_key: idempotency('post-edit')
      };

      if (replacement) {
        body.media_id = await uploadImage(api, replacement);
        submit.textContent = 'Saving…';
      } else if (removeImage) {
        body.media_id = '';
      }

      const result = await api.request('znews/posts/update.php', {
        method: 'POST',
        authenticated: true,
        body
      });

      ensureEditor().close();
      toast(result.data?.published_immediately === true
        ? 'Post updated and published.'
        : 'Post update is being checked.');
      document.querySelector('[data-route="mine"]')?.click();
    } catch (requestError) {
      error.textContent = errorMessage(requestError);
      error.hidden = false;
    } finally {
      setBusy(submit, false);
    }
  });

  async function deletePost(postId) {
    if (!window.confirm('Delete this post? This cannot be undone.')) return;
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
      toast('Post deleted.');
      document.querySelector('[data-route="mine"]')?.click();
    } catch (error) {
      toast(errorMessage(error), 'error');
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
    .creator-remove-row{display:flex;align-items:center;gap:10px;color:#c7d3e8}
    #creatorEditDialog textarea{resize:vertical}
  `;
  document.head.appendChild(style);
})();
