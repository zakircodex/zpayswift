'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const playwrightModule = process.env.PLAYWRIGHT_MODULE || 'playwright';
let chromium;
try {
  ({ chromium } = require(playwrightModule));
} catch (_error) {
  process.stdout.write('SKIP: Playwright is unavailable for Z Sky fast-bootstrap browser assertions.\n');
  process.exit(0);
}

const root = path.resolve(__dirname, '..');
const slowPaths = new Set([
  '/znews/assets/znews-access.js',
  '/znews/assets/znews-profile.js',
  '/znews/assets/znews-reader.js',
  '/znews/assets/znews-header.js',
  '/znews/assets/znews-creator.js',
  '/znews/assets/znews-instant-comments.js',
  '/znews/assets/znews-weekly-review.js',
  '/znews/assets/znews-reader.css',
  '/znews/assets/znews-weekly-review.css',
  '/znews/sw.js'
]);
const criticalPaths = [
  '/znews/assets/znews-config.js',
  '/znews/assets/znews-api.js',
  '/znews/assets/znews-request-scheduler.js',
  '/znews/assets/znews-progressive-feed.js',
  '/znews/assets/znews-feed-ui.js',
  '/znews/assets/znews-ads.js',
  '/znews/assets/znews-bootstrap.js',
  '/znews/assets/znews.js'
];

let requestLog = [];
let sessionMode = 'guest';
let exchangedHandoffCode = '';

function json(response, status, payload, delayMs = 0) {
  setTimeout(() => {
    response.writeHead(status, {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store'
    });
    response.end(JSON.stringify(payload));
  }, delayMs);
}

function contentType(file) {
  if (file.endsWith('.html')) return 'text/html; charset=utf-8';
  if (file.endsWith('.js')) return 'application/javascript; charset=utf-8';
  if (file.endsWith('.css')) return 'text/css; charset=utf-8';
  if (file.endsWith('.png')) return 'image/png';
  if (file.endsWith('.webmanifest')) return 'application/manifest+json';
  return 'application/octet-stream';
}

function feedPayload() {
  return {
    ok: true,
    code: 'ZNEWS_PUBLIC_FEED_OK',
    message: 'Feed loaded.',
    data: {
      items: [{
        post_id: 'FAST_BOOT_POST_1',
        creator_uid: 'CREATOR_1',
        creator_name: 'Fast Boot Creator',
        creator_photo_url: '',
        title: 'The first story',
        text: 'Public feed content rendered before noncritical modules.',
        image_url: '',
        content_type: 'TEXT',
        created_at: 1788360000,
        updated_at: 1788360000,
        status: 'ACTIVE',
        visibility: 'PUBLIC',
        like_count: 0,
        comment_count: 0,
        share_count: 0
      }],
      next_cursor: '',
      has_more: false,
      feed_session_id: 'FAST_BOOT_SESSION',
      ranking_mode: 'FRESH_FAIR_V1'
    }
  };
}

const server = http.createServer((request, response) => {
  const startedAt = performance.now();
  const url = new URL(request.url, 'http://127.0.0.1');
  const entry = { path: url.pathname, startedAt, endedAt: 0 };
  requestLog.push(entry);
  response.on('finish', () => { entry.endedAt = performance.now(); });

  if (url.pathname === '/blank') {
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    response.end('<!doctype html><title>blank</title>');
    return;
  }
  if (url.pathname === '/api/znews/public/feed.php') {
    json(response, 200, feedPayload(), 160);
    return;
  }
  if (url.pathname === '/api/znews/auth/session.php') {
    if (sessionMode === 'valid') {
      json(response, 200, {
        ok: true,
        code: 'ZNEWS_SESSION_OK',
        message: 'Session active.',
        data: { user: { uid: 'TEST_USER', name: 'Verified Creator', role: 'USER', status: 'ACTIVE' } }
      }, 500);
    } else {
      json(response, 401, {
        ok: false,
        code: 'ZNEWS_AUTH_REQUIRED',
        message: 'Creator access expired.',
        data: {}
      }, 500);
    }
    return;
  }
  if (url.pathname === '/api/znews/auth/handoff.php') {
    let body = '';
    request.on('data', (chunk) => { body += chunk; });
    request.on('end', () => {
      try {
        exchangedHandoffCode = String(JSON.parse(body || '{}').code || '');
      } catch (_error) {
        exchangedHandoffCode = '';
      }
      json(response, 200, {
        ok: true,
        code: 'ZNEWS_HANDOFF_ACCEPTED',
        message: 'Creator access granted.',
        data: {
          session_token: 'handoff-session-token',
          user: { uid: 'HANDOFF_USER', name: 'Handoff Creator', role: 'USER', status: 'ACTIVE' }
        }
      }, 100);
    });
    return;
  }
  if (url.pathname.startsWith('/api/')) {
    json(response, 200, { ok: true, code: 'TEST_OK', message: 'OK', data: {} }, 10);
    return;
  }

  let pathname = url.pathname;
  if (pathname === '/znews/' || pathname === '/znews') pathname = '/znews/index.html';
  const file = path.resolve(root, pathname.replace(/^\//, ''));
  if (!file.startsWith(root) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
    response.writeHead(404, { 'Content-Type': 'text/plain' });
    response.end('Not found');
    return;
  }

  const send = () => {
    response.writeHead(200, { 'Content-Type': contentType(file), 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(response);
  };
  if (slowPaths.has(url.pathname)) setTimeout(send, 2000);
  else send();
});

function listen() {
  return new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
}

function closeServer() {
  return new Promise((resolve) => server.close(resolve));
}

async function launchBrowser() {
  const options = { headless: true };
  if (process.env.PLAYWRIGHT_CHROME_PATH) options.executablePath = process.env.PLAYWRIGHT_CHROME_PATH;
  else options.channel = 'chrome';
  return chromium.launch(options);
}

async function openApp(browser, baseUrl, { storedSession = false, handoff = '' } = {}) {
  const context = await browser.newContext({ serviceWorkers: 'allow' });
  const page = await context.newPage();
  if (storedSession) {
    await page.goto(`${baseUrl}/blank`, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => {
      sessionStorage.setItem('znews_session_v1', 'test-session-token');
      sessionStorage.setItem('znews_profile_v1', JSON.stringify({ uid: 'TEST_USER', name: 'Stored Creator' }));
    });
  }
  const handoffFragment = handoff ? `#handoff=${encodeURIComponent(handoff)}` : '';
  await page.goto(`${baseUrl}/znews/${handoffFragment}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#feedList .post-card[data-post-id]', { timeout: 5000 });
  const metrics = await page.evaluate(() => ({
    timings: { ...window.ZNEWS_BOOT_TIMINGS },
    criticalStarts: performance.getEntriesByType('resource')
      .filter((entry) => [
        'znews-config.js', 'znews-api.js', 'znews-request-scheduler.js',
        'znews-progressive-feed.js', 'znews-feed-ui.js', 'znews-ads.js',
        'znews-bootstrap.js', 'znews.js'
      ].some((name) => entry.name.includes(name)))
      .map((entry) => entry.startTime),
    authHidden: [...document.querySelectorAll('[data-auth-only]')].every((element) => element.hidden),
    likeButtons: document.querySelectorAll('#feedList [data-action="like"]').length,
    appInitialized: window.ZNEWS_APP_INITIALIZED === true,
    postPaintReady: window.ZNEWS_POST_PAINT_MODULES?.ready === true
  }));
  return { context, page, metrics };
}

async function main() {
  await listen();
  const port = server.address().port;
  const baseUrl = `http://127.0.0.1:${port}`;
  const browser = await launchBrowser();
  const coldRuns = [];

  try {
    for (let run = 0; run < 5; run += 1) {
      requestLog = [];
      sessionMode = 'guest';
      const opened = await openApp(browser, baseUrl);
      const timing = opened.metrics.timings;
      assert.equal(opened.metrics.appInitialized, true, 'The app did not initialize.');
      assert.equal(opened.metrics.authHidden, true, 'Guest auth-only controls became visible.');
      assert.equal(opened.metrics.likeButtons, 0, 'Guest feed rendered a Like control.');
      assert.equal(opened.metrics.postPaintReady, false, 'Noncritical modules finished before first-card capture.');
      assert.ok(opened.metrics.criticalStarts.length >= criticalPaths.length, 'Critical defer resources were not all requested.');
      const criticalSpread = Math.max(...opened.metrics.criticalStarts) - Math.min(...opened.metrics.criticalStarts);
      assert.ok(criticalSpread < 500, `Critical scripts formed a request waterfall (${criticalSpread.toFixed(1)}ms).`);
      assert.ok(timing.feed_request_start < 1500, `Feed start was late (${timing.feed_request_start.toFixed(1)}ms).`);
      assert.ok(timing.first_card_dom_append < 5000, `First post was late (${timing.first_card_dom_append.toFixed(1)}ms).`);
      assert.ok(timing.feed_request_start < (timing.auth_start ?? Infinity), 'Auth started before the first public feed request.');
      assert.equal(
        requestLog.filter((entry) => entry.path === '/api/znews/public/feed.php').length,
        1,
        'Cold boot started the public feed more than once.'
      );
      coldRuns.push({
        feedStart: timing.feed_request_start,
        feedDuration: timing.feed_response - timing.feed_request_start,
        firstPost: timing.first_card_dom_append,
        criticalSpread
      });
      await opened.context.close();
    }

    requestLog = [];
    sessionMode = 'expired';
    const expired = await openApp(browser, baseUrl, { storedSession: true });
    assert.equal(expired.metrics.authHidden, true, 'Stored session exposed creator controls before validation.');
    const expiredState = await expired.page.evaluate(async () => {
      await window.ZNEWS_AUTH_READY;
      return {
        verified: window.ZNEWS_AUTH_VERIFIED,
        expired: window.ZNEWS_AUTH_STATE?.expired,
        token: sessionStorage.getItem('znews_session_v1'),
        hidden: [...document.querySelectorAll('[data-auth-only]')].every((element) => element.hidden)
      };
    });
    assert.equal(expiredState.verified, false, 'Expired session remained verified.');
    assert.equal(expiredState.expired, true, 'Expired session state was not retained safely.');
    assert.equal(expiredState.token, null, 'Expired session token was not cleared.');
    assert.equal(expiredState.hidden, true, 'Expired session exposed creator controls.');
    await expired.context.close();

    requestLog = [];
    sessionMode = 'valid';
    const valid = await openApp(browser, baseUrl, { storedSession: true });
    assert.equal(valid.metrics.authHidden, true, 'Creator controls appeared before validation completed.');
    const validState = await valid.page.evaluate(async () => {
      await window.ZNEWS_AUTH_READY;
      const firstPost = window.ZNEWS_BOOT_TIMINGS.first_card_dom_append;
      const authStart = window.ZNEWS_BOOT_TIMINGS.auth_start;
      return { verified: window.ZNEWS_AUTH_VERIFIED, firstPost, authStart };
    });
    assert.equal(validState.verified, true, 'Valid stored session was not verified.');
    assert.ok(validState.authStart >= validState.firstPost, 'Session validation blocked the first public post.');
    await valid.page.waitForFunction(() => window.ZNEWS_POST_PAINT_MODULES?.ready === true, null, { timeout: 12000 });
    const visibleCreatorControls = await valid.page.evaluate(() => (
      [...document.querySelectorAll('[data-auth-only]')].some((element) => !element.hidden)
      && document.querySelectorAll('#feedList [data-action="like"]').length === 1
    ));
    assert.equal(visibleCreatorControls, true, 'Verified creator controls were not enabled after validation.');
    assert.equal(await valid.page.evaluate(() => window.ZNEWS_APP_INITIALIZED), true, 'App initialization guard changed unexpectedly.');
    await valid.context.close();

    requestLog = [];
    exchangedHandoffCode = '';
    sessionMode = 'guest';
    const handoff = await openApp(browser, baseUrl, { handoff: 'ONE_TIME_HANDOFF_CODE' });
    const handoffState = await handoff.page.evaluate(async () => {
      await window.ZNEWS_AUTH_READY;
      while (window.ZNEWS_POST_PAINT_MODULES?.ready !== true) {
        await new Promise((resolve) => setTimeout(resolve, 25));
      }
      return {
        verified: window.ZNEWS_AUTH_VERIFIED,
        token: sessionStorage.getItem('znews_session_v1'),
        hash: window.location.hash,
        creatorControlsVisible: [...document.querySelectorAll('[data-auth-only]')]
          .some((element) => !element.hidden)
      };
    });
    assert.equal(exchangedHandoffCode, 'ONE_TIME_HANDOFF_CODE', 'App history discarded the handoff code before exchange.');
    assert.equal(handoffState.verified, true, 'Successful dashboard handoff did not verify creator access.');
    assert.equal(handoffState.token, 'handoff-session-token', 'Successful handoff session was not stored.');
    assert.equal(handoffState.hash, '', 'One-time handoff code remained in the visible URL.');
    assert.equal(handoffState.creatorControlsVisible, true, 'Verified handoff did not reveal creator controls.');
    assert.equal(
      requestLog.filter((entry) => entry.path === '/api/znews/auth/handoff.php').length,
      1,
      'One-time handoff was not exchanged exactly once.'
    );
    await handoff.context.close();

    process.stdout.write(`PASS: Z Sky fast bootstrap browser assertions. ${JSON.stringify({ coldRuns })}\n`);
  } finally {
    await browser.close();
    await closeServer();
  }
}

main().catch(async (error) => {
  process.stderr.write(`FAIL: ${error.stack || error.message}\n`);
  try { await closeServer(); } catch (_error) {}
  process.exit(1);
});
