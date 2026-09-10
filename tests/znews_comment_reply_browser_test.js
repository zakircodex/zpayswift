'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');

async function main() {
  const launchOptions = { headless: true };
  if (process.env.PLAYWRIGHT_CHANNEL) launchOptions.channel = process.env.PLAYWRIGHT_CHANNEL;
  const browser = await chromium.launch(launchOptions);
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, hasTouch: true });

  try {
    await page.setContent(`<!doctype html><html><body>
      <dialog id="postDialog" open>
        <div class="post-reader-shell">
          <header class="post-reader-header"><button id="postDialogClose"></button><strong id="postReaderTitle"></strong></header>
          <div id="postReaderScroll" class="post-reader-scroll">
            <div id="postDetail"><article data-post-id="POST1"></article></div>
            <div id="commentList" class="comment-list">
              <div class="comment" data-comment-id="COMMENT1" data-author-uid="USER1">
                <span class="avatar">A</span>
                <div class="comment-bubble"><strong>A Creator</strong><p>Parent comment</p><small>Now</small></div>
              </div>
              <div class="comment" data-comment-id="COMMENT2" data-author-uid="USER2" data-parent-comment-id="COMMENT1" data-reply-to-name="A Creator">
                <span class="avatar">B</span>
                <div class="comment-bubble"><strong>B Creator</strong><p>Reply comment</p><small>Now</small></div>
              </div>
            </div>
          </div>
          <footer class="comment-dock">
            <form id="commentForm" class="comment-composer">
              <span class="avatar" id="commentComposerAvatar">Z</span>
              <div class="comment-input-shell">
                <div id="commentReplyContext" class="comment-reply-context" hidden><span>Replying to <strong id="commentReplyName"></strong></span><button id="commentReplyCancel" type="button">×</button></div>
                <textarea id="commentText" placeholder="Write a comment…"></textarea>
                <button type="submit"></button>
              </div>
            </form>
            <a id="commentGuestCta" hidden></a>
          </footer>
        </div>
      </dialog>
      <button class="post-action active" id="likedButton">♥ Liked</button>
    </body></html>`);

    await page.addStyleTag({ path: path.join(root, 'znews', 'assets', 'znews.css') });
    await page.addStyleTag({ path: path.join(root, 'znews', 'assets', 'znews-reader.css') });
    await page.addScriptTag({ content: `
      window.ZNEWS_CONFIG = {
        commentPageSize: 20,
        resolveProfilePhotoUrl: (value) => value,
        parseRoute: () => ({ kind: 'post', id: 'POST1' })
      };
      class FakeApiClient {
        constructor() { this.profile = { name: 'Current Creator' }; }
        isAuthenticated() { return true; }
        comments() { return Promise.resolve({ data: { items: [], has_more: false, next_cursor: '' } }); }
        publicPost() { return Promise.resolve({ data: { post: { creator_name: 'A Creator' } } }); }
        createComment(postId, body, parentCommentId) {
          window.__commentCreateCall = { postId, body, parentCommentId };
          return Promise.resolve({ data: {
            comment: {
              comment_id: 'COMMENT3', post_id: postId, author_uid: 'CURRENT_USER',
              author_name: 'Current Creator', text: body, parent_comment_id: parentCommentId,
              root_comment_id: parentCommentId, reply_to_uid: 'USER1',
              reply_to_name: 'A Creator', created_at: Math.floor(Date.now() / 1000)
            },
            published_immediately: true,
            counts: { comment_count: 3 }
          } });
        }
      }
      window.ZNewsApiClient = FakeApiClient;
      window.ZNewsAccess = { authenticated: true };
    ` });
    await page.addScriptTag({ path: path.join(root, 'znews', 'assets', 'znews-reader.js') });
    await page.addScriptTag({ path: path.join(root, 'znews', 'assets', 'znews-instant-comments.js') });
    await page.waitForFunction(() => document.querySelectorAll('[data-comment-reply]').length === 2);

    const nested = await page.$eval('[data-comment-id="COMMENT2"]', (row) => ({
      indented: row.classList.contains('is-reply'),
      target: row.querySelector('.comment-reply-target')?.textContent || ''
    }));
    assert.equal(nested.indented, true, 'Reply row was not visually nested.');
    assert.match(nested.target, /A Creator/, 'Reply target label is missing.');

    await page.click('[data-comment-id="COMMENT1"] [data-comment-reply]');
    const replyState = await page.evaluate(() => ({
      parent: document.querySelector('#commentForm').dataset.parentCommentId,
      name: document.querySelector('#commentReplyName').textContent,
      hidden: document.querySelector('#commentReplyContext').hidden,
      placeholder: document.querySelector('#commentText').placeholder
    }));
    assert.equal(replyState.parent, 'COMMENT1');
    assert.equal(replyState.name, 'A Creator');
    assert.equal(replyState.hidden, false);
    assert.match(replyState.placeholder, /A Creator/);

    await page.click('#commentReplyCancel');
    assert.equal(await page.$eval('#commentReplyContext', (node) => node.hidden), true);
    assert.equal(await page.$eval('#commentForm', (node) => node.dataset.parentCommentId || ''), '');

    await page.click('[data-comment-id="COMMENT1"] [data-comment-reply]');
    await page.fill('#commentText', 'A tested reply');
    await page.click('#commentForm button[type="submit"]');
    await page.waitForFunction(() => Boolean(window.__commentCreateCall));
    const createCall = await page.evaluate(() => window.__commentCreateCall);
    assert.deepEqual(createCall, {
      postId: 'POST1',
      body: 'A tested reply',
      parentCommentId: 'COMMENT1'
    });
    await page.waitForSelector('[data-comment-id="COMMENT3"].is-reply');
    assert.match(
      await page.locator('[data-comment-id="COMMENT3"] .comment-reply-target').textContent(),
      /A Creator/
    );
    assert.equal(await page.$eval('#commentReplyContext', (node) => node.hidden), true);

    const likedColor = await page.$eval('#likedButton', (button) => getComputedStyle(button).color);
    assert.equal(likedColor, 'rgb(255, 93, 115)', 'Liked heart is not using the red active state.');
  } finally {
    await browser.close();
  }

  console.log('PASS: Z News comment reply browser UI.');
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
