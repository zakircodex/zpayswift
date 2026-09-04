'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');

function contentType(file) {
  if (file.endsWith('.html')) return 'text/html; charset=utf-8';
  if (file.endsWith('.js')) return 'application/javascript; charset=utf-8';
  if (file.endsWith('.css')) return 'text/css; charset=utf-8';
  if (file.endsWith('.webmanifest')) return 'application/manifest+json';
  if (file.endsWith('.png')) return 'image/png';
  return 'application/octet-stream';
}

function staticFile(requestPath) {
  if (requestPath === '/blank') return '';
  if (requestPath === '/znews/' || requestPath === '/znews') return path.join(root, 'znews', 'index.html');
  const candidate = path.resolve(root, requestPath.replace(/^\/+/, ''));
  return candidate.startsWith(root + path.sep) ? candidate : '';
}

function listen(server) {
  return new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
}

function close(server) {
  return new Promise((resolve) => server.close(resolve));
}

async function main() {
  const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
    if (pathname === '/blank') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end('<!doctype html><title>blank</title>');
      return;
    }
    const file = staticFile(pathname);
    if (!file || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      response.writeHead(404, { 'Content-Type': 'text/plain' });
      response.end('Not Found');
      return;
    }
    response.writeHead(200, {
      'Content-Type': contentType(file),
      'Cache-Control': 'no-store',
      'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src-elem 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'"
    });
    fs.createReadStream(file).pipe(response);
  });
  await listen(server);
  const origin = `http://127.0.0.1:${server.address().port}`;

  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await context.newPage();
  const cspStyleErrors = [];
  page.on('pageerror', (error) => process.stderr.write(`PAGE_ERROR: ${error.message}\n`));
  page.on('console', (message) => {
    const value = message.text();
    if (/content security policy/i.test(value) && /style-src|inline style/i.test(value)) cspStyleErrors.push(value);
  });

  const posts = Array.from({ length: 12 }, (_value, index) => {
    const number = index + 1;
    const category = number % 4 === 1
      ? 'INTERNATIONAL_NEWS'
      : number % 4 === 2
        ? 'BD_NEWS'
        : number % 4 === 3
          ? 'MOBILE_PRICING'
          : '';
    const dimensions = number === 1 ? [1600, 900] : number === 2 ? [900, 1600] : number === 3 ? [1200, 1200] : [0, 0];
    return {
      post_id: `POST_${number}`,
      creator_uid: 'TEST_USER',
      creator_name: 'Test Creator',
      creator_photo_url: '',
      title: `Post title ${number}`,
      text: `Post body ${number}`,
      bold_ranges: number === 1 ? [{ start: 5, end: 9 }] : [],
      formatting_runs: number === 1 ? [{ start: 5, end: 9, bold: true, color: 'orange' }] : [],
      category,
      image_media_id: `MEDIA_${number}`,
      image_url: `/api/znews/public/media.php?media_id=MEDIA_${number}`,
      image_preview_url: `/api/znews/media/owner.php?media_id=MEDIA_${number}`,
      image_width: dimensions[0],
      image_height: dimensions[1],
      content_type: 'TEXT_IMAGE',
      status: 'ACTIVE',
      visibility: 'PUBLIC',
      like_count: 0,
      comment_count: 0,
      share_count: 0,
      created_at: 1800000000 - number,
      updated_at: 1800000000 - number
    };
  });

  await page.addInitScript(({ fixturePosts }) => {
    sessionStorage.setItem('znews_session_v1', 'fixture-session');
    sessionStorage.setItem('znews_profile_v1', JSON.stringify({ uid: 'TEST_USER', name: 'Test Creator' }));

    const state = {
      posts: fixturePosts,
      requests: [],
      updates: [],
      deletes: [],
      creates: [],
      uploads: [],
      mode: 'ok',
      updateDelayMs: 0,
      createDelayMs: 0,
      uploadDelayMs: 0,
      deleteDelayMs: 0,
      detailsDelayMs: 0,
      mineDelayMs: 0,
      detailFailuresRemaining: 0,
      mineFailuresRemaining: 0,
      nextMedia: 100
    };
    window.__zskyMediaCategoryTest = state;

    const json = (payload, status = 200) => new Response(JSON.stringify(payload), {
      status,
      headers: { 'Content-Type': 'application/json; charset=utf-8' }
    });
    const success = (code, data = {}) => json({ ok: true, success: true, code, message: 'OK', data });
    const fail = (code, message, status) => json({ ok: false, success: false, code, message, data: {} }, status);
    const tinySvg = '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="90"><rect width="160" height="90" fill="#138a56"/></svg>';
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const originalFetch = window.fetch.bind(window);

    window.fetch = async (input, init = {}) => {
      const url = new URL(typeof input === 'string' ? input : input.url, window.location.href);
      if (!url.pathname.startsWith('/api/')) return originalFetch(input, init);
      state.requests.push(`${url.pathname}${url.search}`);

      if (url.pathname.endsWith('/znews/auth/session.php')) {
        return success('ZNEWS_SESSION_OK', { user: { uid: 'TEST_USER', name: 'Test Creator', role: 'USER', status: 'ACTIVE' } });
      }
      if (url.pathname.endsWith('/znews/public/feed.php')) {
        const category = String(url.searchParams.get('category') || '');
        const filtered = category ? state.posts.filter((post) => post.category === category) : state.posts;
        const offset = Number(String(url.searchParams.get('cursor') || '').replace(/^CURSOR_/, '')) || 0;
        const limit = Number(url.searchParams.get('limit') || 3);
        const items = filtered.slice(offset, offset + limit).map((post) => ({ ...post, image_media_id: undefined, image_preview_url: undefined }));
        const next = offset + items.length;
        return success('ZNEWS_PUBLIC_FEED_OK', {
          items,
          category,
          next_cursor: next < filtered.length ? `CURSOR_${next}` : '',
          has_more: next < filtered.length,
          feed_session_id: `SESSION_${category || 'ALL'}`,
          ranking_mode: 'FRESH_FAIR_V1',
          fresh_ratio: 70,
          fair_ratio: 30
        });
      }
      if (url.pathname.endsWith('/znews/posts/mine.php')) {
        if (state.mineDelayMs) await sleep(state.mineDelayMs);
        if (state.mineFailuresRemaining > 0) {
          state.mineFailuresRemaining -= 1;
          return fail('REQUEST_TIMEOUT', 'The request timed out. Please try again.', 504);
        }
        return success('ZNEWS_MY_POSTS_OK', { items: state.posts.slice(0, 10), next_cursor: 'MINE_10', has_more: true });
      }
      if (url.pathname.endsWith('/znews/posts/details.php')) {
        if (state.detailsDelayMs) await sleep(state.detailsDelayMs);
        if (state.detailFailuresRemaining > 0) {
          state.detailFailuresRemaining -= 1;
          return fail('REQUEST_TIMEOUT', 'The request timed out. Please try again.', 504);
        }
        const post = state.posts.find((item) => item.post_id === url.searchParams.get('post_id'));
        return post ? success('ZNEWS_POST_DETAILS_OK', { post: { ...post } }) : fail('ZNEWS_POST_NOT_FOUND', 'Post not found.', 404);
      }
      if (url.pathname.endsWith('/znews/media/owner.php')) {
        return new Response(tinySvg, { status: 200, headers: { 'Content-Type': 'image/svg+xml' } });
      }
      if (url.pathname.endsWith('/znews/public/media.php')) {
        if (url.searchParams.get('media_id') === 'MEDIA_4') return new Response('', { status: 404 });
        const post = state.posts.find((item) => item.image_media_id === url.searchParams.get('media_id'));
        const width = Number(post?.image_width || 160);
        const height = Number(post?.image_height || 90);
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="100%" height="100%" fill="#138a56"/></svg>`;
        return new Response(svg, { status: 200, headers: { 'Content-Type': 'image/svg+xml' } });
      }
      if (url.pathname.endsWith('/znews/media/upload.php')) {
        if (state.uploadDelayMs) await sleep(state.uploadDelayMs);
        if (state.mode === 'upload-fail') return fail('ZNEWS_MEDIA_UPLOAD_FAILED', 'Upload failed safely.', 503);
        const file = init.body?.get?.('image');
        state.uploads.push({ size: Number(file?.size || 0), type: String(file?.type || ''), name: String(file?.name || '') });
        const mediaId = `MEDIA_NEW_${state.nextMedia++}`;
        return success('ZNEWS_MEDIA_UPLOADED', { media: { media_id: mediaId } });
      }
      if (url.pathname.endsWith('/znews/posts/create.php')) {
        const body = JSON.parse(String(init.body || '{}'));
        state.creates.push(body);
        if (state.createDelayMs) await sleep(state.createDelayMs);
        return success('ZNEWS_POST_CREATED', { post: body, published_immediately: true });
      }
      if (url.pathname.endsWith('/znews/posts/update.php')) {
        const body = JSON.parse(String(init.body || '{}'));
        state.updates.push(body);
        if (state.updateDelayMs) await sleep(state.updateDelayMs);
        if (state.mode === 'conflict') return fail('ZNEWS_POST_VERSION_CONFLICT', 'This post changed. Reload it before editing.', 409);
        if (state.mode === 'update-fail') return fail('ZNEWS_POST_UPDATE_FAILED', 'Post could not be updated.', 503);
        const post = state.posts.find((item) => item.post_id === body.post_id);
        Object.assign(post, {
          title: body.title,
          text: body.text,
          bold_ranges: Array.isArray(body.bold_ranges) ? body.bold_ranges : [],
          formatting_runs: Array.isArray(body.formatting_runs) ? body.formatting_runs : [],
          category: body.category,
          updated_at: post.updated_at + 1
        });
        if (Object.prototype.hasOwnProperty.call(body, 'media_id')) {
          post.image_media_id = body.media_id;
          post.image_url = body.media_id ? `/api/znews/public/media.php?media_id=${body.media_id}` : '';
          post.image_preview_url = body.media_id ? `/api/znews/media/owner.php?media_id=${body.media_id}` : '';
          post.image_width = body.media_id ? 390 : 0;
          post.image_height = body.media_id ? 844 : 0;
        }
        return success('ZNEWS_POST_UPDATED', { post: { ...post }, published_immediately: true });
      }
      if (url.pathname.endsWith('/znews/posts/delete.php')) {
        const body = JSON.parse(String(init.body || '{}'));
        if (state.deleteDelayMs) await sleep(state.deleteDelayMs);
        if (state.mode === 'delete-fail') return fail('ZNEWS_POST_DELETE_FAILED', 'Post could not be deleted.', 503);
        state.deletes.push(body);
        return success('ZNEWS_POST_DELETED');
      }
      if (url.pathname.endsWith('/znews/likes/status.php')) return success('ZNEWS_LIKE_STATUS_OK', { liked: false });
      if (url.pathname.endsWith('/znews/public/impression.php')) return success('ZNEWS_IMPRESSION_OK');
      if (url.pathname.endsWith('/znews/views/start.php')) return success('ZNEWS_VIEW_STARTED', { view_session_id: 'VIEW_1', ad_policy: { ad_eligible: false, revenue_share_eligible: false } });
      if (url.pathname.endsWith('/znews/views/heartbeat.php') || url.pathname.endsWith('/znews/views/complete.php')) return success('ZNEWS_VIEW_OK');
      return success('TEST_OK');
    };
  }, { fixturePosts: posts });

  try {
    await page.goto(`${origin}/znews/`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#feedList .post-card[data-post-id="POST_1"]', { timeout: 5000 });
    const renderedBold = await page.evaluate(() => ({
      value: document.querySelector('#feedList [data-post-id="POST_1"] .post-copy strong')?.textContent || '',
      rawMarkers: document.querySelector('#feedList [data-post-id="POST_1"] .post-copy')?.textContent.includes('**') === true
    }));
    assert.deepEqual(renderedBold, { value: 'body', rawMarkers: false }, 'Feed did not safely render the middle bold range.');
    const escapedFormatting = await page.evaluate(() => {
      const holder = document.createElement('div');
      holder.innerHTML = window.ZNewsRichText.formattedTextHtml('<img src=x> bold', [], [{ start: 12, end: 16 }]);
      return {
        images: holder.querySelectorAll('img').length,
        strong: holder.querySelector('strong')?.textContent || '',
        text: holder.textContent,
        unsafe: holder.querySelectorAll('script,style,[onerror]').length
      };
    });
    assert.deepEqual(escapedFormatting, { images: 0, strong: 'bold', text: '<img src=x> bold', unsafe: 0 }, 'Formatted text allowed HTML injection or lost text.');
    await page.evaluate(() => window.ZNEWS_AUTH_READY);
    await page.waitForFunction(() => window.ZNEWS_POST_PAINT_MODULES?.ready === true, null, { timeout: 10000 });

    const mediaLayout = await page.evaluate(() => ({
      ratios: [...document.querySelectorAll('#feedList .feed-media-frame')].slice(0, 3).map((frame) => frame.style.aspectRatio),
      lazy: [...document.querySelectorAll('#feedList img.post-media')].every((image) => image.loading === 'lazy' && image.decoding === 'async'),
      overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth
    }));
    assert.deepEqual(mediaLayout.ratios, ['1600 / 900', '900 / 1600', '1200 / 1200'], 'Feed did not reserve real media aspect ratios.');
    assert.equal(mediaLayout.lazy, true, 'Feed media is not lazy/async.');
    assert.equal(mediaLayout.overflow, false, 'Feed causes horizontal overflow at 390px.');

    for (const width of [320, 360, 390, 412, 430]) {
      await page.setViewportSize({ width, height: 844 });
      const mobileLayout = await page.evaluate(() => {
        const card = document.querySelector('#feedList .post-card');
        const rect = card?.getBoundingClientRect();
        return {
          overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
          cardInsideViewport: !!rect && rect.left >= -0.5 && rect.right <= window.innerWidth + 0.5
        };
      });
      assert.equal(mobileLayout.overflow, false, `Feed causes horizontal overflow at ${width}px.`);
      assert.equal(mobileLayout.cardInsideViewport, true, `Feed card exceeds the ${width}px viewport.`);
    }
    await page.setViewportSize({ width: 390, height: 844 });

    for (let attempt = 0; attempt < 30 && await page.locator('#feedList [data-post-id="POST_4"]').count() === 0; attempt += 1) {
      await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
      await page.waitForTimeout(80);
    }
    await page.locator('#feedList [data-post-id="POST_4"]').scrollIntoViewIfNeeded();
    await page.waitForSelector('#feedList [data-post-id="POST_4"] .media-failed', { timeout: 10000 });
    const failedHeight = await page.locator('#feedList [data-post-id="POST_4"] .media-failed').evaluate((element) => element.getBoundingClientRect().height);
    assert.ok(failedHeight <= 70, `Broken image placeholder remained too tall (${failedHeight}px).`);

    await page.getByRole('button', { name: /Micro job/ }).dispatchEvent('click');
    const microFeedRequests = await page.evaluate(() => window.__zskyMediaCategoryTest.requests.filter((value) => value.includes('/public/feed.php') && value.includes('category=MICRO_JOB')).length);
    assert.equal(microFeedRequests, 0, 'Micro Job triggered a category feed request.');
    await page.getByRole('button', { name: 'BD news', exact: true }).click();
    await page.waitForFunction(() => [...document.querySelectorAll('#feedList .post-card')].every((card) => card.textContent.includes('BD news')));
    const categoryAudit = await page.evaluate(() => ({
      ids: [...document.querySelectorAll('#feedList .post-card')].map((card) => card.dataset.postId),
      requests: window.__zskyMediaCategoryTest.requests.filter((value) => value.includes('/public/feed.php') && value.includes('category=BD_NEWS'))
    }));
    assert.ok(categoryAudit.ids.length > 0, 'BD category rendered no posts.');
    assert.ok(categoryAudit.requests.length > 0, 'BD category was not sent to the feed API.');

    await page.evaluate(() => { window.__zskyMediaCategoryTest.mineFailuresRemaining = 1; });
    await page.locator('[data-route="mine"]').first().dispatchEvent('click');
    await page.waitForSelector('#mineList [data-mine-retry]');
    assert.equal(await page.locator('#mineList .post-card').count(), 0, 'Failed initial My Posts load rendered stale cards.');
    await page.locator('#mineList [data-mine-retry]').click();
    await page.waitForSelector('#mineList [data-post-id="POST_1"] [data-creator-menu]', { timeout: 5000 });
    const mineUiAudit = await page.evaluate(() => {
      const view = document.querySelector('#mineView');
      const heading = view.querySelector('.section-heading');
      return {
        guestNoticeCount: document.querySelectorAll('.znews-guest-note').length,
        nativeCategorySelects: document.querySelectorAll('#postCategory, #creatorEditCategory').length
          ? [...document.querySelectorAll('#postCategory, #creatorEditCategory')].filter((item) => item.tagName === 'SELECT').length
          : 0,
        bottomManagement: document.querySelectorAll('#mineList .creator-management').length,
        overflowButtons: document.querySelectorAll('#mineList [data-creator-menu]').length,
        topGap: Math.round(heading.getBoundingClientRect().top - view.getBoundingClientRect().top)
      };
    });
    assert.equal(mineUiAudit.guestNoticeCount, 0, 'Removed guest notice remains in the document.');
    assert.equal(mineUiAudit.nativeCategorySelects, 0, 'Create/Edit still depends on a native select popup.');
    assert.equal(mineUiAudit.bottomManagement, 0, 'Legacy bottom Edit/Delete controls remain.');
    assert.equal(mineUiAudit.overflowButtons, 10, 'My Posts cards do not have one owner-only overflow action each.');
    assert.ok(mineUiAudit.topGap <= 4, `My Posts retained an excessive top gap (${mineUiAudit.topGap}px).`);
    const openEdit = async ({ expectLoading = false, postId = 'POST_1' } = {}) => {
      await page.locator(`#mineList [data-post-id="${postId}"] [data-creator-menu]`).click();
      await page.waitForSelector('#creatorCardMenuDialog[open]');
      await page.locator('#creatorCardMenuDialog [data-menu-edit]').click();
      if (expectLoading) {
        await page.waitForSelector('#creatorActionDialog[open][data-mode="loading"]');
        assert.match(await page.locator('#creatorActionDialog').textContent(), /Loading post.*Preparing editor/is, 'Polished edit loading state is missing.');
        const loadingAudit = await page.evaluate(() => ({
          dialogBorder: getComputedStyle(document.querySelector('#creatorActionDialog')).borderTopWidth,
          sheetBorder: getComputedStyle(document.querySelector('#creatorActionDialog .creator-action-shell')).borderTopWidth,
          radius: getComputedStyle(document.querySelector('#creatorActionDialog .creator-action-shell')).borderRadius,
          blackRectangle: getComputedStyle(document.querySelector('#creatorActionDialog .creator-action-shell')).backgroundColor === 'rgb(0, 0, 0)',
          progress: document.querySelector('#znewsTopProgress')?.classList.contains('active') === true,
          spinner: !document.querySelector('#creatorActionDialog .creator-action-spinner')?.hidden
        }));
        assert.deepEqual(loadingAudit, { dialogBorder: '0px', sheetBorder: '0px', radius: '22px', blackRectangle: false, progress: true, spinner: true }, 'Edit loading does not use the shared borderless glass progress system.');
      }
      await page.waitForSelector('#creatorEditDialog[open]');
    };
    const fillRichText = async (selector, value) => page.evaluate(({ target, content }) => {
      window.ZNewsRichText.setEditorContent(document.querySelector(target), content);
    }, { target: selector, content: value });

    await page.locator('#mineList [data-post-id="POST_1"] [data-creator-menu]').click();
    assert.equal(await page.locator('#creatorCardMenuDialog[open]').count(), 1, 'Overflow did not open exactly one custom action menu.');
    const menuAudit = await page.evaluate(() => {
      const dialog = document.querySelector('#creatorCardMenuDialog');
      const sheet = dialog.querySelector('.creator-card-menu-sheet');
      const actions = [...dialog.querySelectorAll('.creator-card-menu-actions button')];
      const sheetStyle = getComputedStyle(sheet);
      const first = actions[0].getBoundingClientRect();
      return {
        openMenus: document.querySelectorAll('#creatorCardMenuDialog[open]').length,
        role: dialog.getAttribute('role'),
        fullRows: actions.every((button) => button.getBoundingClientRect().width >= first.width && button.getBoundingClientRect().height >= 48),
        rowHeights: actions.map((button) => Math.round(button.getBoundingClientRect().height)),
        icons: dialog.querySelectorAll('.creator-card-menu-actions svg').length,
        floatingClose: dialog.querySelectorAll('header [data-menu-close]').length,
        borderWidth: sheetStyle.borderTopWidth,
        nearBottom: Math.abs(window.innerHeight - dialog.getBoundingClientRect().bottom) < 2
      };
    });
    assert.equal(menuAudit.openMenus, 1, 'Overflow did not keep exactly one action sheet open.');
    assert.equal(menuAudit.role, 'dialog', 'Post options lacks dialog semantics.');
    assert.equal(menuAudit.fullRows && menuAudit.rowHeights.every((height) => height >= 52 && height <= 56) && menuAudit.icons === 2 && menuAudit.floatingClose === 0 && menuAudit.borderWidth === '0px', true, 'Post options lacks borderless full-width 52-56px icon rows.');
    assert.equal(menuAudit.nearBottom, true, 'Post options dialog is not anchored to the mobile viewport bottom.');
    for (const width of [320, 360, 390, 412, 430]) {
      await page.setViewportSize({ width, height: 844 });
      const mobileSheet = await page.evaluate(() => {
        const dialog = document.querySelector('#creatorCardMenuDialog');
        const rows = [...dialog.querySelectorAll('.creator-card-menu-actions button')];
        return {
          width: Math.round(dialog.getBoundingClientRect().width),
          viewport: window.innerWidth,
          bottom: Math.round(window.innerHeight - dialog.getBoundingClientRect().bottom),
          rowHeights: rows.map((row) => Math.round(row.getBoundingClientRect().height)),
          overflow: document.documentElement.scrollWidth > window.innerWidth
        };
      });
      assert.equal(mobileSheet.width, mobileSheet.viewport, `Post options is not full-width at ${width}px.`);
      assert.equal(Math.abs(mobileSheet.bottom) <= 1, true, `Post options is not bottom-anchored at ${width}px.`);
      assert.equal(mobileSheet.rowHeights.every((height) => height >= 52 && height <= 56), true, `Post option rows are not touch-safe at ${width}px.`);
      assert.equal(mobileSheet.overflow, false, `Post options creates horizontal overflow at ${width}px.`);
    }
    await page.setViewportSize({ width: 390, height: 844 });
    if (process.env.ZNEWS_UI_SCREENSHOT_DIR) {
      fs.mkdirSync(process.env.ZNEWS_UI_SCREENSHOT_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.ZNEWS_UI_SCREENSHOT_DIR, 'post-options-390.png'), fullPage: true });
    }
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('#creatorCardMenuDialog')?.open);
    assert.equal(await page.evaluate(() => document.activeElement?.matches('#mineList [data-post-id="POST_1"] [data-creator-menu]')), true, 'Overflow menu did not return focus after Back/Escape.');
    await page.locator('#mineList [data-post-id="POST_1"] [data-creator-menu]').click();
    await page.locator('#creatorCardMenuDialog').evaluate((dialog) => dialog.dispatchEvent(new MouseEvent('click', { bubbles: true })));
    await page.waitForFunction(() => !document.querySelector('#creatorCardMenuDialog')?.open);
    assert.equal(await page.evaluate(() => document.activeElement?.matches('#mineList [data-post-id="POST_1"] [data-creator-menu]')), true, 'Overflow menu did not return focus to its trigger.');
    await page.evaluate(() => { window.__zskyMediaCategoryTest.detailsDelayMs = 300; });
    await openEdit({ expectLoading: true });
    await page.evaluate(() => { window.__zskyMediaCategoryTest.detailsDelayMs = 0; });
    const initialEdit = await page.evaluate(() => ({
      title: document.querySelector('#creatorEditTitle').value,
      text: document.querySelector('#creatorEditText').value,
      category: document.querySelector('#creatorEditCategory').value,
      preview: !document.querySelector('#creatorEditPreview').hidden,
      previewImages: document.querySelectorAll('#creatorEditPreview img').length,
      savesDisabled: [...document.querySelectorAll('#creatorEditForm button[type="submit"]')].every((button) => button.disabled)
    }));
    assert.deepEqual(initialEdit, { title: 'Post title 1', text: 'Post body 1', category: 'INTERNATIONAL_NEWS', preview: true, previewImages: 1, savesDisabled: true }, 'Edit did not load current fields, exactly one image, or unchanged Save state.');
    assert.equal(await page.locator('#creatorActionDialog[open]').count(), 0, 'Edit left a stale loading sheet open.');
    await page.waitForTimeout(240);
    assert.equal(await page.locator('#znewsTopProgress.active').count(), 0, 'Edit left the top progress indicator active.');
    if (process.env.ZNEWS_UI_SCREENSHOT_DIR) {
      fs.mkdirSync(process.env.ZNEWS_UI_SCREENSHOT_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.ZNEWS_UI_SCREENSHOT_DIR, 'edit-390.png'), fullPage: true });
    }
    await page.locator('#creatorEditTitle').fill('Cancelled title');
    assert.equal(await page.locator('#creatorEditSubmitTop').isEnabled(), true, 'Top and bottom Save state did not activate after a valid change.');
    assert.equal(await page.locator('#creatorEditSubmitBottom').isEnabled(), true, 'Bottom Save state is not synchronized with top Save.');
    const updateCountBeforeCancel = await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length);
    await page.locator('#creatorEditDialog [data-close]').click();
    assert.equal(await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length), updateCountBeforeCancel, 'Cancel mutated the post.');

    await page.evaluate(() => { window.__zskyMediaCategoryTest.detailFailuresRemaining = 1; });
    await page.locator('#mineList [data-post-id="POST_1"] [data-creator-menu]').click();
    await page.locator('#creatorCardMenuDialog [data-menu-edit]').click();
    await page.waitForSelector('#creatorActionDialog[open][data-mode="error"]');
    assert.match(await page.locator('#creatorActionDialog').textContent(), /could not be loaded.*Retry/is, 'Edit loading failure lacks concise Retry/Close state.');
    await page.locator('#creatorActionDialog [data-action-confirm]').click();
    await page.waitForSelector('#creatorEditDialog[open]');
    await page.locator('#creatorEditDialog [data-close]').click();

    await openEdit();
    const cleanReopen = await page.evaluate(() => ({
      title: document.querySelector('#creatorEditTitle').value,
      fileCount: document.querySelector('#creatorEditImage').files.length,
      remove: document.querySelector('#creatorRemoveImage').checked
    }));
    assert.deepEqual(cleanReopen, { title: 'Post title 1', fileCount: 0, remove: false }, 'Edit reopened with stale state.');
    await page.locator('#creatorEditTitle').fill('Title-only update');
    await page.locator('#creatorEditSubmitTop').click();
    await page.waitForFunction(() => window.__zskyMediaCategoryTest.updates.length === 1);
    const titleOnly = await page.evaluate(() => window.__zskyMediaCategoryTest.updates[0]);
    assert.equal(Object.prototype.hasOwnProperty.call(titleOnly, 'media_id'), false, 'Title-only edit did not preserve the existing image.');
    assert.deepEqual(titleOnly.bold_ranges, [{ start: 5, end: 9 }], 'Title-only edit did not preserve the middle bold range.');
    assert.equal(titleOnly.formatting_runs[0].color, 'orange', 'Title-only edit did not preserve text color.');

    const png = await page.screenshot({ type: 'png' });
    const paddedPhoto = Buffer.concat([png, Buffer.alloc(Math.max(0, 3 * 1024 * 1024 - png.length))]);
    await openEdit();
    await page.locator('#creatorRemoveImage').evaluate((element) => { element.checked = true; element.dispatchEvent(new Event('change', { bubbles: true })); });
    await page.locator('#creatorEditImage').setInputFiles({ name: 'replacement.png', mimeType: 'image/png', buffer: paddedPhoto });
    assert.equal(await page.locator('#creatorRemoveImage').isChecked(), false, 'Replacement did not win after Remove.');
    await page.locator('#creatorEditSubmitTop').click();
    await page.waitForFunction(() => window.__zskyMediaCategoryTest.updates.length === 2);
    const replacementAudit = await page.evaluate(() => ({
      update: window.__zskyMediaCategoryTest.updates[1],
      upload: window.__zskyMediaCategoryTest.uploads.at(-1)
    }));
    assert.ok(String(replacementAudit.update.media_id).startsWith('MEDIA_NEW_'), 'Replacement media was not attached.');
    assert.ok(replacementAudit.upload.size > 0 && replacementAudit.upload.size <= 700 * 1024, 'Edit replacement was not compressed below 700 KB.');

    await openEdit();
    await page.locator('#creatorEditPreview .composer-image-remove').click();
    await page.locator('#creatorEditSubmitTop').click();
    await page.waitForFunction(() => window.__zskyMediaCategoryTest.updates.length === 3);
    assert.equal(await page.evaluate(() => window.__zskyMediaCategoryTest.updates[2].media_id), '', 'Remove image did not send the canonical empty media ID.');

    await page.evaluate(() => {
      const post = window.__zskyMediaCategoryTest.posts[0];
      post.image_media_id = 'MEDIA_RESTORED';
      post.image_url = '/api/znews/public/media.php?media_id=MEDIA_RESTORED';
      post.image_preview_url = '/api/znews/media/owner.php?media_id=MEDIA_RESTORED';
    });
    await openEdit();
    await page.evaluate(() => { window.__zskyMediaCategoryTest.mode = 'conflict'; });
    await fillRichText('#creatorEditText', 'Conflict text');
    await page.locator('#creatorEditSubmitTop').click();
    await page.waitForSelector('#creatorEditError:not([hidden])');
    assert.match(await page.locator('#creatorEditError').textContent(), /changed|Reload/i, 'Version conflict is not clear.');
    await page.locator('#creatorEditDialog [data-close]').click();

    await openEdit();
    await page.evaluate(() => { window.__zskyMediaCategoryTest.mode = 'upload-fail'; });
    const updatesBeforeUploadFailure = await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length);
    await page.locator('#creatorEditImage').setInputFiles({ name: 'failed.png', mimeType: 'image/png', buffer: paddedPhoto });
    await page.locator('#creatorEditSubmitTop').click();
    await page.waitForSelector('#creatorEditError:not([hidden])');
    assert.equal(await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length), updatesBeforeUploadFailure, 'Failed upload attempted to update/detach current media.');
    await page.locator('#creatorEditDialog [data-close]').click();

    await openEdit();
    await page.evaluate(() => { window.__zskyMediaCategoryTest.mode = 'update-fail'; });
    await fillRichText('#creatorEditText', 'Failed update text');
    const imageBeforeFailedUpdate = await page.evaluate(() => window.__zskyMediaCategoryTest.posts[0].image_media_id);
    await page.locator('#creatorEditSubmitTop').click();
    await page.waitForSelector('#creatorEditError:not([hidden])');
    assert.equal(await page.evaluate(() => window.__zskyMediaCategoryTest.posts[0].image_media_id), imageBeforeFailedUpdate, 'Failed update detached the current image.');
    await page.locator('#creatorEditDialog [data-close]').click();

    await openEdit();
    await page.evaluate(() => {
      window.__zskyMediaCategoryTest.mode = 'ok';
      window.__zskyMediaCategoryTest.updateDelayMs = 250;
      window.__zskyMediaCategoryTest.mineDelayMs = 600;
    });
    const beforeDouble = await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length);
    await fillRichText('#creatorEditText', 'Single guarded update');
    await page.locator('#creatorEditForm').evaluate((form) => {
      form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
      form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
    });
    await page.waitForFunction(() => document.querySelector('#creatorEditSubmitTop')?.textContent === 'SAVING…');
    const saveLoadingAudit = await page.evaluate(() => ({
      top: document.querySelector('#creatorEditSubmitTop').textContent,
      bottom: document.querySelector('#creatorEditSubmitBottom').textContent,
      spinner: document.querySelector('#creatorEditSubmitBottom').classList.contains('znews-button-loading'),
      bothDisabled: [...document.querySelectorAll('#creatorEditForm button[type="submit"]')].every((button) => button.disabled),
      progress: document.querySelector('#znewsTopProgress')?.classList.contains('active') === true
    }));
    assert.deepEqual(saveLoadingAudit, { top: 'SAVING…', bottom: 'Saving…', spinner: true, bothDisabled: true, progress: true }, 'Top/bottom Edit save states are not synchronized.');
    await page.waitForFunction((expected) => window.__zskyMediaCategoryTest.updates.length === expected + 1, beforeDouble);
    assert.equal(await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length), beforeDouble + 1, 'Double submit produced duplicate updates.');
    await page.waitForFunction(() => document.querySelector('#mineList [data-post-id="POST_1"] .post-copy')?.textContent.includes('Single guarded update'));
    const localSaveAudit = await page.evaluate(() => ({
      locallyUpdated: document.querySelector('#mineList [data-post-id="POST_1"] .post-copy')?.textContent.includes('Single guarded update') === true,
      skeletons: document.querySelectorAll('#mineList .skeleton').length,
      cards: document.querySelectorAll('#mineList .post-card').length
    }));
    assert.deepEqual(localSaveAudit, { locallyUpdated: true, skeletons: 0, cards: 10 }, 'Successful Edit blanked My Posts instead of updating the existing card locally.');
    await page.waitForFunction(() => !document.querySelector('#znewsTopProgress')?.classList.contains('active'), null, { timeout: 1500 });
    assert.equal(await page.locator('#znewsTopProgress.active').count(), 0, 'Save left the shared progress indicator active.');
    await page.evaluate(() => {
      window.__zskyMediaCategoryTest.updateDelayMs = 0;
      window.__zskyMediaCategoryTest.mineDelayMs = 0;
    });

    for (const [postId, category] of [
      ['POST_1', 'INTERNATIONAL_NEWS'],
      ['POST_2', 'BD_NEWS'],
      ['POST_3', 'MOBILE_PRICING']
    ]) {
      await openEdit({ postId });
      const beforeCategorySave = await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length);
      await fillRichText('#creatorEditText', `Text-only edit for ${category}`);
      await page.locator('#creatorEditSubmitBottom').click();
      await page.waitForFunction((before) => window.__zskyMediaCategoryTest.updates.length === before + 1, beforeCategorySave);
      const savedCategory = await page.evaluate(() => window.__zskyMediaCategoryTest.updates.at(-1).category);
      assert.equal(savedCategory, category, `Text-only Edit changed ${category}.`);
      await page.waitForFunction(() => !document.querySelector('#creatorEditDialog')?.open);
    }

    const openDelete = async () => {
      await page.locator('#mineList [data-post-id="POST_2"] [data-creator-menu]').click();
      await page.waitForSelector('#creatorCardMenuDialog[open]');
      await page.locator('#creatorCardMenuDialog [data-menu-delete]').click();
      await page.waitForSelector('#creatorActionDialog[open][data-mode="delete"]');
    };
    await page.evaluate(() => {
      window.__zskyMediaCategoryTest.mode = 'delete-fail';
      window.__zskyMediaCategoryTest.deleteDelayMs = 220;
    });
    await openDelete();
    await page.waitForTimeout(220);
    const deleteButtonSizes = await page.locator('#creatorActionDialog .creator-action-buttons button').evaluateAll((buttons) => buttons.map((button) => ({
      width: Math.round(button.getBoundingClientRect().width),
      height: Math.round(button.getBoundingClientRect().height),
      radius: getComputedStyle(button).borderRadius,
      fontSize: getComputedStyle(button).fontSize
    })));
    assert.equal(deleteButtonSizes[0].height, 52, 'Delete modal controls are not exactly 52px high.');
    assert.deepEqual(deleteButtonSizes[0], deleteButtonSizes[1], 'Delete modal buttons do not have equal dimensions and typography.');
    await page.locator('#creatorActionDialog [data-action-confirm]').evaluate((button) => {
      button.click();
      button.click();
    });
    await page.waitForFunction(() => document.querySelector('#creatorActionDialog [data-action-confirm-label]')?.textContent === 'Deleting…');
    const deletingAudit = await page.evaluate(() => ({
      cancelDisabled: document.querySelector('#creatorActionDialog [data-action-cancel]').disabled,
      deleteDisabled: document.querySelector('#creatorActionDialog [data-action-confirm]').disabled,
      spinner: document.querySelector('#creatorActionDialog [data-action-confirm]').classList.contains('is-loading'),
      progress: document.querySelector('#znewsTopProgress')?.classList.contains('active') === true
    }));
    assert.deepEqual(deletingAudit, { cancelDisabled: true, deleteDisabled: true, spinner: true, progress: true }, 'Delete loading state is incomplete.');
    await page.waitForSelector('#creatorActionError:not([hidden])');
    const failedDeleteAudit = await page.evaluate(() => ({
      requests: window.__zskyMediaCategoryTest.requests.filter((value) => value.includes('/posts/delete.php')).length,
      cardExists: Boolean(document.querySelector('#mineList [data-post-id="POST_2"]')),
      confirmDisabled: document.querySelector('#creatorActionDialog [data-action-confirm]').disabled
    }));
    assert.deepEqual(failedDeleteAudit, { requests: 1, cardExists: true, confirmDisabled: false }, 'Delete failure did not recover safely or double-delete was not blocked.');
    await page.locator('#creatorActionDialog [data-action-cancel]').click();

    await page.evaluate(() => {
      window.__zskyMediaCategoryTest.mode = 'ok';
      window.__zskyMediaCategoryTest.deleteDelayMs = 0;
    });
    await openDelete();
    await page.locator('#creatorActionDialog [data-action-confirm]').click();
    await page.waitForFunction(() => window.__zskyMediaCategoryTest.deletes.length === 1);
    await page.waitForFunction(() => !document.querySelector('#mineList [data-post-id="POST_2"]'));
    assert.equal(await page.evaluate(() => window.__zskyMediaCategoryTest.deletes.length), 1, 'Successful delete was submitted more than once.');

    await page.locator('[data-route="create"]').first().dispatchEvent('click');
    const richEditorAudit = await page.evaluate(() => {
      const textarea = document.querySelector('#postText');
      const rich = window.ZNewsRichText;
      const sample = 'বাংলা hello 🎉 রং';
      rich.setEditorContent(textarea, sample);
      const start = sample.indexOf('hello');
      textarea.focus();
      textarea.setSelectionRange(start, start + 5);
      rich.toggleBold(textarea);
      rich.applyColor(textarea, 'red');
      const combined = rich.getEditorPayload(textarea);
      const combinedPreview = {
        styledText: document.querySelector('#createView .rich-editor-live-preview strong .znews-text-color-red')?.textContent || '',
        boldState: document.querySelector('#postBoldButton').getAttribute('aria-pressed'),
        colorLabel: document.querySelector('#postColorButton').getAttribute('aria-label'),
        html: document.querySelector('#createView .rich-editor-live-preview')?.innerHTML || ''
      };
      rich.toggleBold(textarea);
      const unbold = rich.getEditorPayload(textarea);
      rich.applyColor(textarea, 'green');
      const recolored = rich.getEditorPayload(textarea);
      const sentenceText = 'Bold a full sentence.';
      rich.setEditorContent(textarea, sentenceText);
      textarea.setSelectionRange(0, sentenceText.length);
      rich.toggleBold(textarea);
      const sentence = rich.getEditorPayload(textarea);
      const partialText = 'Partial color';
      rich.setEditorContent(textarea, partialText);
      textarea.setSelectionRange(1, 5);
      rich.applyColor(textarea, 'yellow');
      const partial = rich.getEditorPayload(textarea);
      const emojiText = 'A 🎉 B';
      rich.setEditorContent(textarea, emojiText);
      const emojiStart = emojiText.indexOf('🎉');
      textarea.setSelectionRange(emojiStart, emojiStart + '🎉'.length);
      rich.toggleBold(textarea);
      const emoji = rich.getEditorPayload(textarea);
      rich.setEditorContent(textarea, 'Mixed text', [{ start: 0, end: 5, bold: true, color: 'green' }]);
      textarea.setSelectionRange(0, 10);
      rich.updateToolbar(textarea);
      const mixed = {
        bold: document.querySelector('#postBoldButton').getAttribute('aria-pressed'),
        color: document.querySelector('#postColorButton').getAttribute('aria-label')
      };
      textarea.setSelectionRange(2, 2);
      rich.updateToolbar(textarea);
      const caret = {
        bold: document.querySelector('#postBoldButton').getAttribute('aria-pressed'),
        color: document.querySelector('#postColorButton').getAttribute('aria-label')
      };
      const finalText = 'Hi welcome to Malaysia';
      rich.setEditorContent(textarea, finalText);
      const malaysiaStart = finalText.indexOf('Malaysia');
      textarea.setSelectionRange(malaysiaStart, finalText.length);
      rich.toggleBold(textarea);
      rich.applyColor(textarea, 'green');
      const finalPayload = rich.getEditorPayload(textarea);
      const livePreview = document.querySelector('#createView .rich-editor-live-preview').cloneNode(true);
      const publishedPreview = document.createElement('div');
      publishedPreview.innerHTML = rich.formattedTextHtml(finalPayload.text, finalPayload.formattingRuns, finalPayload.boldRanges);
      const finalMatch = {
        liveText: livePreview.textContent,
        publishedText: publishedPreview.textContent,
        liveStyledText: livePreview.querySelector('strong .znews-text-color-green')?.textContent || '',
        publishedStyledText: publishedPreview.querySelector('strong .znews-text-color-green')?.textContent || ''
      };
      rich.setEditorContent(textarea, 'Legacy bold', [], [{ start: 7, end: 11 }]);
      const legacy = rich.getEditorPayload(textarea);
      return { value: textarea.value, combined, combinedPreview, unbold, recolored, sentence, partial, emoji, mixed, caret, finalMatch, legacy };
    });
    assert.equal(richEditorAudit.combined.text, 'বাংলা hello 🎉 রং', 'Bangla/emoji editor changed canonical text.');
    assert.deepEqual(richEditorAudit.combined.formattingRuns, [{ start: 6, end: 11, bold: true, color: 'red' }], 'Bold/color combination was not represented safely.');
    assert.equal(richEditorAudit.combinedPreview.styledText, 'hello', `Selected text does not show immediate bold/color feedback (${richEditorAudit.combinedPreview.html}).`);
    assert.equal(richEditorAudit.combinedPreview.boldState, 'true', 'Bold toolbar state did not activate for the selected text.');
    assert.equal(richEditorAudit.combinedPreview.colorLabel, 'Text color: Red', 'Color toolbar state did not activate for the selected text.');
    assert.deepEqual(richEditorAudit.unbold.formattingRuns, [{ start: 6, end: 11, color: 'red' }], 'Bold toggle-off removed the wrong formatting.');
    assert.deepEqual(richEditorAudit.recolored.formattingRuns, [{ start: 6, end: 11, color: 'green' }], 'Text color change was not applied to the selected word.');
    assert.deepEqual(richEditorAudit.sentence.boldRanges, [{ start: 0, end: 21 }], 'A selected sentence was not made bold.');
    assert.deepEqual(richEditorAudit.partial.formattingRuns, [{ start: 1, end: 5, color: 'yellow' }], 'Partial text selection color was not preserved.');
    assert.deepEqual(richEditorAudit.emoji.boldRanges, [{ start: 2, end: 3 }], 'Emoji formatting did not use Unicode code-point ranges.');
    assert.deepEqual(richEditorAudit.mixed, { bold: 'mixed', color: 'Text color: Mixed' }, 'Mixed formatting selection is not represented neutrally.');
    assert.deepEqual(richEditorAudit.caret, { bold: 'true', color: 'Text color: Green' }, 'Caret inside formatted text does not restore toolbar state.');
    assert.equal(richEditorAudit.finalMatch.liveText, richEditorAudit.finalMatch.publishedText, 'Live editor text does not match the published renderer.');
    assert.equal(richEditorAudit.finalMatch.liveStyledText, 'Malaysia', 'Malaysia did not render bold and green in the live editor.');
    assert.equal(richEditorAudit.finalMatch.publishedStyledText, 'Malaysia', 'Published Malaysia formatting does not match the live editor.');
    assert.deepEqual(richEditorAudit.legacy.boldRanges, [{ start: 7, end: 11 }], 'Legacy bold range was not preserved by the editor.');
    assert.equal(richEditorAudit.value, 'Legacy bold', 'Rich editor displayed formatting syntax.');
    const androidSelectionAudit = await page.evaluate(() => {
      const textarea = document.querySelector('#postText');
      const bold = document.querySelector('#postBoldButton');
      const rich = window.ZNewsRichText;
      const sample = 'Select Malaysia safely';
      const start = sample.indexOf('Malaysia');
      const end = start + 'Malaysia'.length;
      rich.setEditorContent(textarea, sample);
      textarea.focus();
      textarea.setSelectionRange(start, end);
      textarea.dispatchEvent(new Event('select'));
      bold.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, cancelable: true, pointerType: 'touch' }));
      textarea.blur();
      textarea.setSelectionRange(end, end);
      bold.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
      const payload = rich.getEditorPayload(textarea);
      const surface = document.querySelector('#createView .rich-editor-surface');
      const toolbar = document.querySelector('#postFormatToolbar');
      return {
        selection: [textarea.selectionStart, textarea.selectionEnd],
        boldRanges: payload.boldRanges,
        liveBold: document.querySelector('#createView .rich-editor-live-preview strong')?.textContent || '',
        boldPressed: bold.getAttribute('aria-pressed'),
        boldBackground: getComputedStyle(bold).backgroundColor,
        toolbarBeforeEditor: toolbar.compareDocumentPosition(surface) === Node.DOCUMENT_POSITION_FOLLOWING,
        editorPaddingTop: Number.parseFloat(getComputedStyle(document.querySelector('#createView .rich-editor-input')).paddingTop)
      };
    });
    assert.deepEqual(androidSelectionAudit.selection, [7, 15], 'Bold toolbar tap did not restore the Android text selection.');
    assert.deepEqual(androidSelectionAudit.boldRanges, [{ start: 7, end: 15 }], 'Stored Android selection was not formatted.');
    assert.equal(androidSelectionAudit.liveBold, 'Malaysia', 'Bold was not visible immediately in the editor.');
    assert.equal(androidSelectionAudit.boldPressed, 'true', 'Bold active state is not exposed.');
    assert.notEqual(androidSelectionAudit.boldBackground, 'rgba(0, 0, 0, 0)', 'Bold active state has no strong filled background.');
    assert.equal(androidSelectionAudit.toolbarBeforeEditor && androidSelectionAudit.editorPaddingTop >= 24, true, 'Formatting toolbar is not structurally separated from selected text.');

    await page.evaluate(() => {
      document.documentElement.style.setProperty('--znews-keyboard-inset', '300px');
      document.documentElement.style.setProperty('--znews-visual-height', '544px');
    });
    await page.locator('#postColorButton').click();
    const paletteLayout = await page.evaluate(() => {
      const palette = document.querySelector('#postColorPalette');
      const rect = palette.getBoundingClientRect();
      return {
        position: getComputedStyle(palette).position,
        bottom: Math.round(rect.bottom),
        visibleBottom: 544,
        toolbarOpen: document.querySelector('#postFormatToolbar').classList.contains('palette-open')
      };
    });
    assert.equal(paletteLayout.position, 'fixed', 'Mobile color palette is not a keyboard-safe bottom sheet.');
    assert.equal(paletteLayout.bottom <= paletteLayout.visibleBottom && paletteLayout.toolbarOpen, true, 'Color palette overlaps the simulated Android keyboard area.');
    await page.locator('#postColorPalette [data-format-color="green"]').click();
    const androidColorAudit = await page.evaluate(() => ({
      selection: [document.querySelector('#postText').selectionStart, document.querySelector('#postText').selectionEnd],
      live: document.querySelector('#createView .rich-editor-live-preview .znews-text-color-green')?.textContent || '',
      colorPressed: document.querySelector('#postColorButton').getAttribute('aria-pressed'),
      colorLabel: document.querySelector('#postColorButton').getAttribute('aria-label'),
      paletteClosed: document.querySelector('#postColorPalette').hidden
    }));
    assert.deepEqual(androidColorAudit, { selection: [7, 15], live: 'Malaysia', colorPressed: 'true', colorLabel: 'Text color: Green', paletteClosed: true }, 'Color toolbar did not restore selection and apply a live color safely.');
    await page.evaluate(() => {
      document.documentElement.style.removeProperty('--znews-keyboard-inset');
      document.documentElement.style.removeProperty('--znews-visual-height');
    });
    await page.locator('#postCategoryButton').click();
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('#postCategoryDialog')?.open);
    assert.equal(await page.evaluate(() => document.activeElement?.id), 'postCategoryButton', 'Category picker did not return focus after Escape.');
    await page.locator('#postCategoryButton').click();
    const pickerAudit = await page.evaluate(() => ({
      options: [...document.querySelectorAll('#postCategoryDialog [data-category-option]')].map((button) => button.dataset.categoryOption),
      nativeSelect: document.querySelector('#postCategory')?.tagName === 'SELECT'
    }));
    assert.deepEqual(pickerAudit.options, ['INTERNATIONAL_NEWS', 'BD_NEWS', 'MOBILE_PRICING'], 'Custom category picker options are incorrect.');
    assert.equal(pickerAudit.nativeSelect, false, 'Create category still uses a native select.');
    if (process.env.ZNEWS_UI_SCREENSHOT_DIR) {
      await page.screenshot({ path: path.join(process.env.ZNEWS_UI_SCREENSHOT_DIR, 'category-picker-390.png'), fullPage: true });
    }
    await page.locator('#postCategoryDialog [data-category-option="MOBILE_PRICING"]').click();
    for (const width of [320, 360, 390, 412, 430]) {
      await page.setViewportSize({ width, height: 844 });
      const composerLayout = await page.evaluate(() => {
        const toolbar = document.querySelector('#postFormatToolbar');
        const trigger = document.querySelector('#postCategoryButton');
        const bottom = document.querySelector('#createView .composer-bottom-submit');
        return {
          overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
          toolbarInside: toolbar.getBoundingClientRect().right <= window.innerWidth,
          triggerInside: trigger.getBoundingClientRect().right <= window.innerWidth,
          toolbarTargets: [...toolbar.querySelectorAll('button')].slice(0, 2).map((button) => Math.round(button.getBoundingClientRect().height)),
          bottomHeight: Math.round(bottom.getBoundingClientRect().height)
        };
      });
      assert.equal(composerLayout.overflow, false, `Composer causes horizontal overflow at ${width}px.`);
      assert.equal(composerLayout.toolbarInside && composerLayout.triggerInside, true, `Composer controls exceed the ${width}px viewport.`);
      assert.deepEqual(composerLayout.toolbarTargets, [44, 44], `Formatting touch targets are too small at ${width}px.`);
      assert.ok(composerLayout.bottomHeight >= 48, `Sticky Post action is too short at ${width}px.`);
    }
    await page.setViewportSize({ width: 390, height: 520 });
    await page.locator('#createView .rich-editor-input').focus();
    await page.locator('#createView .rich-editor-input').evaluate((element) => element.scrollIntoView({ block: 'center' }));
    const keyboardViewportAudit = await page.evaluate(() => {
      const toolbar = document.querySelector('#postFormatToolbar').getBoundingClientRect();
      const bottom = document.querySelector('#createPostSubmitBottom').getBoundingClientRect();
      const editor = document.querySelector('#createView .rich-editor-input').getBoundingClientRect();
      return {
        toolbarVisible: toolbar.top >= 0 && toolbar.bottom < window.innerHeight,
        editorVisible: editor.top < bottom.top && editor.bottom > toolbar.bottom,
        actionsSeparated: toolbar.bottom < bottom.top,
        overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth
      };
    });
    assert.deepEqual(keyboardViewportAudit, { toolbarVisible: true, editorVisible: true, actionsSeparated: true, overflow: false }, 'Compact keyboard viewport hides or overlaps the shared rich editor controls.');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('#postTitle').fill('Created with category');
    await page.evaluate(() => window.ZNewsRichText.setEditorContent(document.querySelector('#postText'), 'Created middle bold text'));
    await page.locator('#postText').evaluate((element) => {
      element.focus();
      element.setSelectionRange(8, 19);
    });
    await page.locator('#postBoldButton').click();
    assert.equal(await page.locator('#postText').inputValue(), 'Created middle bold text', 'Bold toolbar exposed formatting markers in the editor.');
    await page.locator('#postColorButton').click();
    await page.locator('#postColorPalette [data-format-color="green"]').click();
    const toolbarSelectionAudit = await page.evaluate(() => {
      const textarea = document.querySelector('#postText');
      const green = document.querySelector('#postColorPalette [data-format-color="green"]');
      return {
        start: textarea.selectionStart,
        end: textarea.selectionEnd,
        greenChecked: green.getAttribute('aria-checked'),
        greenActive: green.classList.contains('active'),
        circular: getComputedStyle(green.querySelector('.color-swatch')).borderRadius === '50%',
        paletteClosed: document.querySelector('#postColorPalette').hidden
      };
    });
    assert.deepEqual(toolbarSelectionAudit, { start: 8, end: 19, greenChecked: 'true', greenActive: true, circular: true, paletteClosed: true }, 'Color selection did not preserve text selection or update the active swatch.');
    if (process.env.ZNEWS_UI_SCREENSHOT_DIR) {
      await page.screenshot({ path: path.join(process.env.ZNEWS_UI_SCREENSHOT_DIR, 'rich-editor-390.png'), fullPage: true });
    }
    assert.equal(await page.locator('#createPostSubmit').isEnabled(), true, 'Top Post action did not activate for valid content.');
    assert.equal(await page.locator('#createPostSubmitBottom').isEnabled(), true, 'Bottom Post action is not synchronized with top Post.');
    await page.locator('#postImage').setInputFiles({ name: 'create.png', mimeType: 'image/png', buffer: paddedPhoto });
    await page.evaluate(() => {
      window.__zskyMediaCategoryTest.uploadDelayMs = 250;
      window.__zskyMediaCategoryTest.createDelayMs = 250;
    });
    await page.locator('#createPostSubmit').click();
    await page.waitForFunction(() => document.querySelector('#createPostSubmitBottom')?.textContent === 'Uploading photo…', null, { timeout: 10000 });
    const uploadState = await page.evaluate(() => ({
      top: document.querySelector('#createPostSubmit').textContent,
      bottom: document.querySelector('#createPostSubmitBottom').textContent,
      spinner: document.querySelector('#createPostSubmitBottom').classList.contains('znews-button-loading'),
      progress: document.querySelector('#znewsTopProgress')?.classList.contains('active') === true
    }));
    assert.deepEqual(uploadState, { top: 'UPLOADING…', bottom: 'Uploading photo…', spinner: true, progress: true }, 'Create upload stage gives incomplete progress feedback.');
    await page.waitForFunction(() => document.querySelector('#createPostSubmitBottom')?.textContent === 'Publishing…', null, { timeout: 10000 });
    await page.waitForFunction(() => window.__zskyMediaCategoryTest.creates.length === 1);
    await page.waitForFunction(() => document.querySelector('#createPostForm')?.getAttribute('aria-busy') !== 'true');
    await page.waitForFunction(() => !document.querySelector('#znewsTopProgress')?.classList.contains('active'), null, { timeout: 1500 });
    const createAudit = await page.evaluate(() => ({
      body: window.__zskyMediaCategoryTest.creates[0],
      upload: window.__zskyMediaCategoryTest.uploads.at(-1),
      progressActive: document.querySelector('#znewsTopProgress')?.classList.contains('active') === true
    }));
    assert.equal(createAudit.body.category, 'MOBILE_PRICING', 'Create did not persist selected category.');
    assert.equal(createAudit.body.text, 'Created middle bold text', 'Create sent editor markers instead of canonical plain text.');
    assert.deepEqual(createAudit.body.bold_ranges, [{ start: 8, end: 19 }], 'Create did not send the selected middle bold range.');
    assert.deepEqual(createAudit.body.formatting_runs, [{ start: 8, end: 19, bold: true, color: 'green' }], 'Create did not send combined bold/color formatting.');
    assert.ok(createAudit.upload.size > 0 && createAudit.upload.size <= 700 * 1024, 'Create upload was not compressed below 700 KB.');
    assert.equal(createAudit.progressActive, false, 'Create left the shared progress indicator active.');

    await page.evaluate(() => window.ZNEWS_IMAGE_OPTIMIZER_READY());
    const compressionSamples = await page.evaluate(async () => {
      async function makeFile(megabytes, width, height, type) {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        const gradient = context.createLinearGradient(0, 0, width, height);
        gradient.addColorStop(0, '#075ca8');
        gradient.addColorStop(0.5, 'rgba(32,216,102,.55)');
        gradient.addColorStop(1, '#f8d347');
        context.fillStyle = gradient;
        context.fillRect(0, 0, width, height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, 0.94));
        const padding = new Uint8Array(Math.max(0, (megabytes * 1024 * 1024) - blob.size));
        return new File([blob, padding], `sample-${megabytes}.${type.split('/')[1]}`, { type });
      }
      const definitions = [
        [1, 1600, 900, 'image/jpeg'],
        [3, 900, 1600, 'image/png'],
        [5, 1200, 1200, 'image/webp'],
        [8, 2400, 1600, 'image/jpeg']
      ];
      const results = [];
      for (const [size, width, height, type] of definitions) {
        const file = await makeFile(size, width, height, type);
        const result = await window.ZNewsImageOptimizer.optimize(file);
        results.push({ size, type, originalBytes: result.originalBytes, finalBytes: result.finalBytes, width: result.width, height: result.height, compressionPercent: result.compressionPercent });
      }
      return results;
    });
    for (const sample of compressionSamples) {
      assert.ok(sample.finalBytes <= 700 * 1024, `${sample.size} MB client sample exceeds 700 KB.`);
      assert.ok(Math.max(sample.width, sample.height) <= 1600, `${sample.size} MB client sample exceeds 1600px.`);
    }
    assert.deepEqual(cspStyleErrors, [], 'Creator UI attempted CSP-blocked inline styling.');

    process.stdout.write(`PASS: Z Sky media/category/Edit browser assertions; compression=${JSON.stringify(compressionSamples)}\n`);
  } finally {
    await context.close();
    await browser.close();
    await close(server);
  }
}

main().catch((error) => {
  process.stderr.write(`FAIL: ${error.stack || error.message}\n`);
  process.exit(1);
});
