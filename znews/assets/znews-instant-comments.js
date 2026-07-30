(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const ApiClient = window.ZNewsApiClient;
  const form = document.querySelector('#commentForm');
  const input = document.querySelector('#commentText');
  const list = document.querySelector('#commentList');
  const toastRegion = document.querySelector('#toastRegion');

  if (!config || !ApiClient || !form || !input || !list) return;

  const api = new ApiClient(config);
  input.maxLength = 1000;

  function text(value) {
    return String(value ?? '').trim();
  }

  function toast(message, type = 'success') {
    const item = document.createElement('div');
    item.className = `toast ${type}`;
    item.textContent = text(message);
    toastRegion?.appendChild(item);
    window.setTimeout(() => item.remove(), 4200);
  }

  function currentPostId() {
    const cardId = text(document.querySelector('#postDetail [data-post-id]')?.dataset.postId);
    if (cardId) return cardId;
    const route = config.parseRoute();
    return route.kind === 'post' ? route.id : '';
  }

  function formatTime(seconds) {
    const timestamp = Number(seconds || 0) * 1000;
    if (!timestamp) return 'Just now';
    const diff = Date.now() - timestamp;
    if (diff >= 0 && diff < 60_000) return 'Just now';
    if (diff >= 0 && diff < 3_600_000) return `${Math.max(1, Math.floor(diff / 60_000))}m`;
    if (diff >= 0 && diff < 86_400_000) return `${Math.max(1, Math.floor(diff / 3_600_000))}h`;
    return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(new Date(timestamp));
  }

  function safePhoto(value) {
    const raw = text(value);
    if (!raw) return '';
    try {
      const url = new URL(config.resolveProfilePhotoUrl(raw), window.location.origin);
      if (url.protocol !== 'https:' && url.origin !== window.location.origin) return '';
      return url.toString();
    } catch (_error) {
      return '';
    }
  }

  function commentElement(comment) {
    const row = document.createElement('div');
    row.className = 'comment';
    row.dataset.commentId = text(comment.comment_id);
    row.dataset.authorUid = text(comment.author_uid);

    const avatar = document.createElement('span');
    avatar.className = 'avatar';
    const name = text(comment.author_name || comment.creator_name || 'Z-Pay user');
    const photo = safePhoto(comment.author_photo_url);
    if (photo) {
      const image = document.createElement('img');
      image.src = photo;
      image.alt = '';
      image.referrerPolicy = 'no-referrer';
      avatar.appendChild(image);
    } else {
      avatar.textContent = name.charAt(0).toUpperCase() || 'Z';
    }

    const bubble = document.createElement('div');
    bubble.className = 'comment-bubble';
    const author = document.createElement('strong');
    author.textContent = name;
    const body = document.createElement('p');
    body.textContent = text(comment.text || comment.message);
    const time = document.createElement('small');
    time.textContent = formatTime(comment.created_at);
    bubble.append(author, body, time);
    row.append(avatar, bubble);
    return row;
  }

  function removeEmptyState() {
    list.querySelector('.empty-state')?.remove();
  }

  function appendPublishedComment(comment) {
    const commentId = text(comment?.comment_id);
    if (commentId && list.querySelector(`[data-comment-id="${CSS.escape(commentId)}"]`)) return;
    removeEmptyState();
    list.appendChild(commentElement(comment));
    list.lastElementChild?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  function updateCommentCount(counts) {
    const count = Number(counts?.comment_count);
    if (!Number.isFinite(count)) return;
    document.querySelectorAll('#postDetail .post-meta span:nth-child(2)').forEach((element) => {
      const shares = element.textContent.match(/(\d+)\s+shares?/i)?.[1] || '0';
      element.textContent = `${count} comments • ${shares} shares`;
    });
  }

  function setSending(button, sending) {
    if (!(button instanceof HTMLButtonElement)) return;
    button.dataset.sending = sending ? 'true' : 'false';
    button.disabled = sending || text(input.value) === '';
    button.setAttribute('aria-busy', sending ? 'true' : 'false');
  }

  async function submitInstantComment(event) {
    if (!api.isAuthenticated()) return;

    event.preventDefault();
    event.stopImmediatePropagation();

    const postId = currentPostId();
    const value = text(input.value);
    if (!postId) return toast('Post could not be identified.', 'error');
    if (!value) return toast('Write a comment first.', 'error');
    if (value.length > 1000) return toast('Comment must not exceed 1000 characters.', 'error');

    const button = form.querySelector('button[type="submit"]');
    setSending(button, true);

    try {
      const result = await api.createComment(postId, value);
      const comment = result.data?.comment || {};
      const published = result.data?.published_immediately === true;
      updateCommentCount(result.data?.counts || {});

      input.value = '';
      input.dispatchEvent(new Event('input', { bubbles: true }));

      if (published) {
        appendPublishedComment(comment);
        window.dispatchEvent(new CustomEvent('znews:comment-created', {
          detail: { comment, published: true }
        }));
        toast('Comment published.');
      } else {
        window.dispatchEvent(new CustomEvent('znews:comment-created', {
          detail: { comment, published: false }
        }));
        toast('Comment is being checked before it appears publicly.');
      }
    } catch (requestError) {
      const message = requestError?.code === 'SESSION_EXPIRED'
        ? 'Creator access expired. Open Z Sky 24 again from your Z-Pay dashboard.'
        : (requestError?.message || 'Comment could not be sent.');
      toast(message, 'error');
    } finally {
      setSending(button, false);
    }
  }

  form.addEventListener('submit', submitInstantComment, true);
})();
