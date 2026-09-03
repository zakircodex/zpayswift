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
    response.writeHead(200, { 'Content-Type': contentType(file), 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(response);
  });
  await listen(server);
  const origin = `http://127.0.0.1:${server.address().port}`;

  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await context.newPage();
  page.on('pageerror', (error) => process.stderr.write(`PAGE_ERROR: ${error.message}\n`));

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
      creates: [],
      uploads: [],
      mode: 'ok',
      updateDelayMs: 0,
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
        return success('ZNEWS_MY_POSTS_OK', { items: state.posts.slice(0, 10), next_cursor: 'MINE_10', has_more: true });
      }
      if (url.pathname.endsWith('/znews/posts/details.php')) {
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
        if (state.mode === 'upload-fail') return fail('ZNEWS_MEDIA_UPLOAD_FAILED', 'Upload failed safely.', 503);
        const file = init.body?.get?.('image');
        state.uploads.push({ size: Number(file?.size || 0), type: String(file?.type || ''), name: String(file?.name || '') });
        const mediaId = `MEDIA_NEW_${state.nextMedia++}`;
        return success('ZNEWS_MEDIA_UPLOADED', { media: { media_id: mediaId } });
      }
      if (url.pathname.endsWith('/znews/posts/create.php')) {
        const body = JSON.parse(String(init.body || '{}'));
        state.creates.push(body);
        return success('ZNEWS_POST_CREATED', { post: body, published_immediately: true });
      }
      if (url.pathname.endsWith('/znews/posts/update.php')) {
        const body = JSON.parse(String(init.body || '{}'));
        state.updates.push(body);
        if (state.updateDelayMs) await sleep(state.updateDelayMs);
        if (state.mode === 'conflict') return fail('ZNEWS_POST_VERSION_CONFLICT', 'This post changed. Reload it before editing.', 409);
        if (state.mode === 'update-fail') return fail('ZNEWS_POST_UPDATE_FAILED', 'Post could not be updated.', 503);
        const post = state.posts.find((item) => item.post_id === body.post_id);
        Object.assign(post, { title: body.title, text: body.text, category: body.category, updated_at: post.updated_at + 1 });
        if (Object.prototype.hasOwnProperty.call(body, 'media_id')) {
          post.image_media_id = body.media_id;
          post.image_url = body.media_id ? `/api/znews/public/media.php?media_id=${body.media_id}` : '';
          post.image_preview_url = body.media_id ? `/api/znews/media/owner.php?media_id=${body.media_id}` : '';
          post.image_width = body.media_id ? 390 : 0;
          post.image_height = body.media_id ? 844 : 0;
        }
        return success('ZNEWS_POST_UPDATED', { post: { ...post }, published_immediately: true });
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

    const feedRequestsBeforeMicro = await page.evaluate(() => window.__zskyMediaCategoryTest.requests.filter((value) => value.includes('/public/feed.php')).length);
    await page.getByRole('button', { name: /Micro job/ }).dispatchEvent('click');
    const feedRequestsAfterMicro = await page.evaluate(() => window.__zskyMediaCategoryTest.requests.filter((value) => value.includes('/public/feed.php')).length);
    assert.equal(feedRequestsAfterMicro, feedRequestsBeforeMicro, 'Micro Job triggered a feed request.');
    await page.getByRole('button', { name: 'BD news', exact: true }).click();
    await page.waitForFunction(() => [...document.querySelectorAll('#feedList .post-card')].every((card) => card.textContent.includes('BD news')));
    const categoryAudit = await page.evaluate(() => ({
      ids: [...document.querySelectorAll('#feedList .post-card')].map((card) => card.dataset.postId),
      requests: window.__zskyMediaCategoryTest.requests.filter((value) => value.includes('/public/feed.php') && value.includes('category=BD_NEWS'))
    }));
    assert.ok(categoryAudit.ids.length > 0, 'BD category rendered no posts.');
    assert.ok(categoryAudit.requests.length > 0, 'BD category was not sent to the feed API.');

    await page.locator('[data-route="mine"]').first().dispatchEvent('click');
    await page.waitForSelector('#mineList [data-post-id="POST_1"] [data-creator-edit]', { timeout: 5000 });
    const editButton = page.locator('#mineList [data-post-id="POST_1"] [data-creator-edit]');

    await editButton.click();
    await page.waitForSelector('#creatorEditDialog[open]');
    const initialEdit = await page.evaluate(() => ({
      title: document.querySelector('#creatorEditTitle').value,
      text: document.querySelector('#creatorEditText').value,
      category: document.querySelector('#creatorEditCategory').value,
      preview: !document.querySelector('#creatorEditPreview').hidden
    }));
    assert.deepEqual(initialEdit, { title: 'Post title 1', text: 'Post body 1', category: 'INTERNATIONAL_NEWS', preview: true }, 'Edit did not load current fields/image.');
    await page.locator('#creatorEditTitle').fill('Cancelled title');
    const updateCountBeforeCancel = await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length);
    await page.locator('#creatorEditDialog [data-close]').click();
    assert.equal(await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length), updateCountBeforeCancel, 'Cancel mutated the post.');

    await editButton.click();
    await page.waitForSelector('#creatorEditDialog[open]');
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

    const png = await page.screenshot({ type: 'png' });
    const paddedPhoto = Buffer.concat([png, Buffer.alloc(Math.max(0, 3 * 1024 * 1024 - png.length))]);
    await page.waitForSelector('#mineList [data-post-id="POST_1"] [data-creator-edit]');
    await page.locator('#mineList [data-post-id="POST_1"] [data-creator-edit]').click();
    await page.waitForSelector('#creatorEditDialog[open]');
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

    await page.locator('#mineList [data-post-id="POST_1"] [data-creator-edit]').click();
    await page.waitForSelector('#creatorEditDialog[open]');
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
    await page.locator('#mineList [data-post-id="POST_1"] [data-creator-edit]').click();
    await page.waitForSelector('#creatorEditDialog[open]');
    await page.evaluate(() => { window.__zskyMediaCategoryTest.mode = 'conflict'; });
    await page.locator('#creatorEditText').fill('Conflict text');
    await page.locator('#creatorEditSubmitTop').click();
    await page.waitForSelector('#creatorEditError:not([hidden])');
    assert.match(await page.locator('#creatorEditError').textContent(), /changed|Reload/i, 'Version conflict is not clear.');
    await page.locator('#creatorEditDialog [data-close]').click();

    await page.locator('#mineList [data-post-id="POST_1"] [data-creator-edit]').click();
    await page.waitForSelector('#creatorEditDialog[open]');
    await page.evaluate(() => { window.__zskyMediaCategoryTest.mode = 'upload-fail'; });
    const updatesBeforeUploadFailure = await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length);
    await page.locator('#creatorEditImage').setInputFiles({ name: 'failed.png', mimeType: 'image/png', buffer: paddedPhoto });
    await page.locator('#creatorEditSubmitTop').click();
    await page.waitForSelector('#creatorEditError:not([hidden])');
    assert.equal(await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length), updatesBeforeUploadFailure, 'Failed upload attempted to update/detach current media.');
    await page.locator('#creatorEditDialog [data-close]').click();

    await page.locator('#mineList [data-post-id="POST_1"] [data-creator-edit]').click();
    await page.waitForSelector('#creatorEditDialog[open]');
    await page.evaluate(() => { window.__zskyMediaCategoryTest.mode = 'update-fail'; });
    await page.locator('#creatorEditText').fill('Failed update text');
    const imageBeforeFailedUpdate = await page.evaluate(() => window.__zskyMediaCategoryTest.posts[0].image_media_id);
    await page.locator('#creatorEditSubmitTop').click();
    await page.waitForSelector('#creatorEditError:not([hidden])');
    assert.equal(await page.evaluate(() => window.__zskyMediaCategoryTest.posts[0].image_media_id), imageBeforeFailedUpdate, 'Failed update detached the current image.');
    await page.locator('#creatorEditDialog [data-close]').click();

    await page.locator('#mineList [data-post-id="POST_1"] [data-creator-edit]').click();
    await page.waitForSelector('#creatorEditDialog[open]');
    await page.evaluate(() => {
      window.__zskyMediaCategoryTest.mode = 'ok';
      window.__zskyMediaCategoryTest.updateDelayMs = 250;
    });
    const beforeDouble = await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length);
    await page.locator('#creatorEditText').fill('Single guarded update');
    await page.locator('#creatorEditForm').evaluate((form) => {
      form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
      form.dispatchEvent(new SubmitEvent('submit', { bubbles: true, cancelable: true }));
    });
    await page.waitForFunction((expected) => window.__zskyMediaCategoryTest.updates.length === expected + 1, beforeDouble);
    assert.equal(await page.evaluate(() => window.__zskyMediaCategoryTest.updates.length), beforeDouble + 1, 'Double submit produced duplicate updates.');
    await page.waitForTimeout(300);

    await page.locator('[data-route="create"]').first().dispatchEvent('click');
    await page.locator('#postCategory').selectOption('MOBILE_PRICING');
    await page.locator('#postTitle').fill('Created with category');
    await page.locator('#postText').fill('Created text');
    await page.locator('#postImage').setInputFiles({ name: 'create.png', mimeType: 'image/png', buffer: paddedPhoto });
    await page.locator('#createPostSubmit').click();
    await page.waitForFunction(() => window.__zskyMediaCategoryTest.creates.length === 1);
    const createAudit = await page.evaluate(() => ({
      body: window.__zskyMediaCategoryTest.creates[0],
      upload: window.__zskyMediaCategoryTest.uploads.at(-1)
    }));
    assert.equal(createAudit.body.category, 'MOBILE_PRICING', 'Create did not persist selected category.');
    assert.ok(createAudit.upload.size > 0 && createAudit.upload.size <= 700 * 1024, 'Create upload was not compressed below 700 KB.');

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
