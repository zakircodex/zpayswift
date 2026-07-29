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
    const match = window.location.pathname.match(/^\/znews\/post\/([A-Za-z0-9_-]+)\/?$/);
    return match ? decodeURIComponent(match[1]) : '';
  }

  function formatTime(seconds) {
    const timestamp = Number(seconds || 0) * 1000;
    if (!timestamp) return '';
    return new Intl.DateTimeFormat('en-GB', {
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit'
    }).format(new Date(timestamp));
  }

  function safePhoto(value) {
    const raw = text(value);
    if (!raw) return '';
    try {
      const url = new URL(raw, window.location.origin);
      if (url.protocol !== 'https:' && url.origin !== window.location.origin) return '';
      return url.toString();
    } catch (_error) {
      return '';
    }
  }

  function commentElement(comment) {
    const row = document.createElement('div');
    row.className = 'comment';

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

  function renderComments(items) {
    const comments = Array.isArray(items) ? items : [];
    list.textContent = '';
    if (!comments.length) {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      const heading = document.createElement('strong');
      heading.textContent = 'No comments yet';
      empty.append(heading, document.createTextNode('Start the conversation.'));
      list.appendChild(empty);
      return;
    }
    comments.forEach((comment) => list.appendChild(commentElement(comment)));
  }

  function updateCommentCount(counts) {
    const count = Number(counts?.comment_count);
    if (!Number.isFinite(count)) return;
    document.querySelectorAll('#postDetail .post-meta span:nth-child(2)').forEach((element) => {
      const shares = element.textContent.match(/(\d+)\s+shares?/i)?.[1] || '0';
      element.textContent = `${count} comments • ${shares} shares`;
    });
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
    const originalLabel = button?.textContent || 'Send';
    if (button) {
      button.disabled = true;
      button.textContent = 'Sending…';
    }

    try {
      const result = await api.createComment(postId, value);
      input.value = '';
      const published = result.data?.published_immediately === true;
      updateCommentCount(result.data?.counts || {});

      if (published) {
        const comments = await api.comments(postId);
        renderComments(comments.data?.items || []);
        toast('Comment published.');
      } else {
        toast('Comment is being checked before it appears publicly.');
      }
    } catch (requestError) {
      const message = requestError?.code === 'SESSION_EXPIRED'
        ? 'Creator access expired. Open Z News again from your Z-Pay dashboard.'
        : (requestError?.message || 'Comment could not be sent.');
      toast(message, 'error');
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = originalLabel;
      }
    }
  }

  form.addEventListener('submit', submitInstantComment, true);
})();
