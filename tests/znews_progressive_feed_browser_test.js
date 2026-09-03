'use strict';

const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const playwright = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');

function contentType(file) {
  if (file.endsWith('.html')) return 'text/html; charset=utf-8';
  if (file.endsWith('.js')) return 'application/javascript; charset=utf-8';
  if (file.endsWith('.css')) return 'text/css; charset=utf-8';
  if (file.endsWith('.json') || file.endsWith('.webmanifest')) return 'application/json; charset=utf-8';
  if (file.endsWith('.png')) return 'image/png';
  return 'application/octet-stream';
}

function staticFile(requestPath) {
  if (requestPath === '/znews/' || requestPath === '/znews/index.html') {
    return path.join(root, 'znews', 'index.html');
  }
  const relative = requestPath.replace(/^\/+/, '');
  const candidate = path.resolve(root, relative);
  return candidate.startsWith(root + path.sep) ? candidate : '';
}

async function main() {
  const server = http.createServer((request, response) => {
    const requestPath = new URL(request.url, 'http://127.0.0.1').pathname;
    const file = staticFile(requestPath);
    if (!file || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      response.writeHead(404, { 'Content-Type': 'text/plain' });
      response.end('Not Found');
      return;
    }
    response.writeHead(200, { 'Content-Type': contentType(file), 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(response);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  const address = server.address();
  const origin = `http://127.0.0.1:${address.port}`;
  const launchOptions = { headless: true };
  if (process.env.PLAYWRIGHT_CHANNEL) launchOptions.channel = process.env.PLAYWRIGHT_CHANNEL;
  const browser = await playwright.chromium.launch(launchOptions);
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  page.on('pageerror', (error) => console.error(`PAGE_ERROR: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') console.error(`PAGE_CONSOLE: ${message.text()}`);
  });
  const posts = Array.from({ length: 10 }, (_value, index) => ({
    post_id: `POST_${index + 1}`,
    creator_uid: `CREATOR_${(index % 3) + 1}`,
    creator_name: `Creator ${(index % 3) + 1}`,
    creator_photo_url: '',
    title: `Progressive post ${index + 1}`,
    text: `Post ${index + 1} ` + 'progressive feed content '.repeat(24),
    image_url: `/api/znews/public/media.php?media_id=MEDIA_${index + 1}`,
    content_type: 'TEXT',
    status: 'ACTIVE',
    visibility: 'PUBLIC',
    like_count: index,
    comment_count: index,
    share_count: index,
    created_at: 1800000000 - index,
    updated_at: 1800000000 - index
  }));

  await page.addInitScript(({ fixturePosts }) => {
    const originalFetch = window.fetch.bind(window);
    const timeline = [];
    const active = { feed: 0, media: 0, analytics: 0, like: 0 };
    const maximum = { feed: 0, media: 0, analytics: 0, like: 0, total: 0 };
    let activeTotal = 0;

    function delayed(ms, signal) {
      return new Promise((resolve, reject) => {
        const timer = window.setTimeout(resolve, ms);
        const abort = () => {
          window.clearTimeout(timer);
          reject(new DOMException('Aborted', 'AbortError'));
        };
        if (signal?.aborted) return abort();
        signal?.addEventListener('abort', abort, { once: true });
      });
    }

    function json(payload) {
      return new Response(JSON.stringify(payload), {
        status: 200,
        headers: { 'Content-Type': 'application/json; charset=utf-8' }
      });
    }

    window.__znewsRequestAudit = { timeline, maximum, likeStatusRequests: 0 };
    window.fetch = async (input, init = {}) => {
      const raw = typeof input === 'string' ? input : input?.url;
      const url = new URL(raw, window.location.href);
      let kind = '';
      let priority = '';
      let delayMs = 0;
      let responseFactory = null;

      if (url.pathname.endsWith('/api/znews/public/feed.php')) {
        kind = 'feed'; priority = 'P0'; delayMs = 300;
        responseFactory = () => {
          const limit = Math.max(1, Number(url.searchParams.get('limit') || 3));
          const offset = Math.max(0, Number(String(url.searchParams.get('cursor') || '').replace(/^CURSOR_/, '')) || 0);
          const items = fixturePosts.slice(offset, offset + limit);
          const nextOffset = offset + items.length;
          const hasMore = nextOffset < fixturePosts.length;
          return json({
            ok: true, success: true, code: 'ZNEWS_PUBLIC_FEED_OK', message: 'Feed loaded.',
            data: {
              feed_session_id: 'ZFS00000000000000000000000000000000',
              ranking_mode: 'FRESH_FAIR_V1', fresh_ratio: 70, fair_ratio: 30,
              items, next_cursor: hasMore ? `CURSOR_${nextOffset}` : '', has_more: hasMore
            }
          });
        };
      } else if (url.pathname.endsWith('/api/znews/public/media.php')) {
        kind = 'media'; priority = 'P1'; delayMs = 800;
        responseFactory = () => new Response(
          '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="9"><rect width="16" height="9" fill="#071326"/></svg>',
          { status: 200, headers: { 'Content-Type': 'image/svg+xml' } }
        );
      } else if (url.pathname.endsWith('/api/znews/public/impression.php')) {
        kind = 'analytics'; priority = 'P3'; delayMs = 600;
        responseFactory = () => json({
          ok: true, success: true, code: 'ZNEWS_FEED_IMPRESSIONS_RECORDED', message: 'Recorded.', data: {}
        });
      } else if (url.pathname.endsWith('/api/znews/likes/status.php')) {
        kind = 'like'; priority = 'P2'; delayMs = 100;
        window.__znewsRequestAudit.likeStatusRequests += 1;
        responseFactory = () => json({ ok: true, success: true, code: 'ZNEWS_LIKE_STATUS_OK', data: { liked: false } });
      } else {
        return originalFetch(input, init);
      }

      const startedAt = performance.now();
      active[kind] += 1;
      activeTotal += 1;
      maximum[kind] = Math.max(maximum[kind], active[kind]);
      maximum.total = Math.max(maximum.total, activeTotal);
      const record = { path: url.pathname, start: startedAt, end: 0, duration: 0, initiator: kind, priority, concurrent: activeTotal, outcome: 'pending' };
      timeline.push(record);
      try {
        await delayed(delayMs, init.signal);
        record.outcome = 'complete';
        return responseFactory();
      } catch (error) {
        record.outcome = error?.name === 'AbortError' ? 'preempted' : 'failed';
        throw error;
      } finally {
        record.end = performance.now();
        record.duration = record.end - record.start;
        active[kind] -= 1;
        activeTotal -= 1;
      }
    };
  }, { fixturePosts: posts });
  await page.addInitScript(() => {
    window.__feedMutationSizes = [];
    document.addEventListener('DOMContentLoaded', () => {
      const feed = document.querySelector('#feedList');
      if (!feed) return;
      new MutationObserver((records) => {
        records.forEach((record) => {
          const count = [...record.addedNodes].filter((node) => node.nodeType === 1
            && node.matches?.('.post-card:not(.skeleton-card)')).length;
          if (count) window.__feedMutationSizes.push(count);
        });
      }).observe(feed, { childList: true });
    });
  });

  const startedAt = Date.now();
  await page.goto(`${origin}/znews/`, { waitUntil: 'domcontentloaded' });
  try {
    await page.waitForSelector('#feedList .post-card:not(.skeleton-card)', { timeout: 5000 });
  } catch (error) {
    const diagnostic = await page.evaluate(() => ({
      announcement: document.querySelector('#announcement')?.textContent || '',
      feed: document.querySelector('#feedList')?.textContent || '',
      ready: document.documentElement.classList.contains('znews-ready')
    }));
    console.error(`PAGE_STATE: ${JSON.stringify(diagnostic)}`);
    throw error;
  }
  const firstPostMs = Date.now() - startedAt;
  await page.waitForTimeout(1500);
  let postsTwoToFiveMs = 0;
  let postsSixToTenMs = 0;

  for (let attempt = 0; attempt < 80; attempt += 1) {
    const count = await page.locator('#feedList .post-card:not(.skeleton-card)').count();
    if (count >= 5 && !postsTwoToFiveMs) postsTwoToFiveMs = Date.now() - startedAt;
    if (count >= 10) {
      postsSixToTenMs = Date.now() - startedAt;
      break;
    }
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(100);
  }

  await page.waitForFunction(() => (
    window.__znewsRequestAudit?.timeline?.some((entry) => (
      entry.initiator === 'analytics' && entry.outcome === 'complete'
    )) === true
      && window.ZNEWS_REQUEST_SCHEDULER?.snapshot().activePriority === null
      && window.ZNEWS_REQUEST_SCHEDULER?.snapshot().pendingByPriority.every((count) => count === 0)
  ), { timeout: 15000 }).catch(() => {});

  const ids = await page.locator('#feedList .post-card:not(.skeleton-card)').evaluateAll((cards) => (
    cards.map((card) => card.dataset.postId)
  ));
  const mutationSizes = await page.evaluate(() => window.__feedMutationSizes);
  const requestAudit = await page.evaluate(() => window.__znewsRequestAudit);
  const feedTimeline = requestAudit.timeline.filter((entry) => entry.initiator === 'feed');
  const mediaTimeline = requestAudit.timeline.filter((entry) => entry.initiator === 'media');
  const analyticsTimeline = requestAudit.timeline.filter((entry) => entry.initiator === 'analytics');
  const timeoutToast = await page.locator('#toastRegion').getByText('The request timed out', { exact: false }).count();
  const horizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);

  assert.equal(ids.length, 10, 'Ten progressive cards must render.');
  assert.equal(new Set(ids).size, 10, 'Progressive cards must not duplicate.');
  assert.ok(mutationSizes.length >= 10, 'Each post must produce its own DOM append.');
  assert.equal(Math.max(...mutationSizes), 1, 'No DOM mutation may append a full post batch.');
  assert.equal(requestAudit.maximum.feed, 1, 'Only one feed request may be active.');
  assert.equal(requestAudit.maximum.media, 1, 'Only one protected-media request may be active.');
  assert.equal(requestAudit.maximum.analytics, 1, 'Only one analytics request may be active.');
  assert.equal(requestAudit.maximum.total, 1, 'Scheduled same-origin requests must not overlap.');
  assert.equal(feedTimeline.length, 4, 'Ten posts should use four bounded three-item requests.');
  assert.ok(feedTimeline.every((entry) => entry.concurrent === 1), 'Every P0 feed request must start without lower-priority contention.');
  assert.ok(mediaTimeline.length > 0, 'Viewport media loading was not exercised.');
  assert.ok(analyticsTimeline.length > 0, 'Queued impression analytics was not exercised.');
  assert.equal(requestAudit.likeStatusRequests, 0, 'Guest feed must make zero Like-status requests.');
  assert.equal(timeoutToast, 0, 'Background impression failure must not show a timeout toast.');
  assert.equal(horizontalOverflow, false, '390px feed must not cause page-level overflow.');
  assert.ok(firstPostMs < 5000, 'First post must appear inside five seconds.');

  console.log(`PASS: browser priority feed first=${firstPostMs}ms posts2-5=${postsTwoToFiveMs}ms posts6-10=${postsSixToTenMs}ms feed=${feedTimeline.length} media=${mediaTimeline.length} analytics=${analyticsTimeline.length} max=${requestAudit.maximum.total}.`);
  console.log(`TIMELINE: ${JSON.stringify(requestAudit.timeline)}`);
  await browser.close();
  await new Promise((resolve) => server.close(resolve));
}

main().catch((error) => {
  console.error(error?.stack || String(error));
  process.exit(1);
});
