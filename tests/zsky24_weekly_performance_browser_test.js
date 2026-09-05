'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');

function type(file) {
  if (file.endsWith('.html')) return 'text/html; charset=utf-8';
  if (file.endsWith('.js')) return 'application/javascript; charset=utf-8';
  if (file.endsWith('.css')) return 'text/css; charset=utf-8';
  if (file.endsWith('.webmanifest')) return 'application/manifest+json';
  if (file.endsWith('.png')) return 'image/png';
  return 'application/octet-stream';
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
    const relative = pathname === '/znews/' || pathname === '/znews'
      ? 'znews/index.html'
      : pathname.replace(/^\/+/, '');
    const file = path.resolve(root, relative);
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      response.writeHead(404, { 'Content-Type': 'text/plain' });
      response.end('Not Found');
      return;
    }
    response.writeHead(200, {
      'Content-Type': type(file),
      'Cache-Control': 'no-store',
      'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'"
    });
    fs.createReadStream(file).pipe(response);
  });
  await listen(server);

  const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome', headless: true });
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
    serviceWorkers: 'block'
  });
  const page = await context.newPage();

  await page.addInitScript(() => {
    sessionStorage.setItem('znews_session_v1', 'weekly-fixture-session');
    sessionStorage.setItem('znews_profile_v1', JSON.stringify({ uid: 'CREATOR_A', name: 'Creator A' }));

    const baseReview = {
      creator_uid: 'CREATOR_A',
      creator_name: 'Creator A',
      creator_status: 'ACTIVE',
      post_count: 7,
      raw_views: 1480,
      eligible_views: 1240,
      invalid_views: 86,
      creator_views_excluded: 54,
      self_views_excluded: 34,
      spam_views: 120,
      duplicate_views: 18,
      pending_views: 100,
      traffic_share_percent: 12.4,
      traffic_share_pending: false
    };
    const history = [
      { ...baseReview, period_id: '2026-08-24', period_start_date: '2026-08-24', period_end_date: '2026-08-30', review_status: 'APPROVED', live_preview: false },
      { ...baseReview, period_id: '2026-08-17', period_start_date: '2026-08-17', period_end_date: '2026-08-23', review_status: 'HELD', review_reason: 'Verification requires another review.', live_preview: false },
      { ...baseReview, period_id: '2026-08-10', period_start_date: '2026-08-10', period_end_date: '2026-08-16', review_status: 'UNDER_REVIEW', live_preview: false }
    ];
    window.__weeklyTest = {
      requests: [],
      delay: 280,
      failures: 0,
      currentFailures: 1,
      empty: false,
      currentStatus: 'UNDER_REVIEW',
      currentLive: true,
      backgroundEvents: [],
      baseReview,
      history
    };

    const json = (payload, status = 200) => new Response(JSON.stringify(payload), {
      status,
      headers: { 'Content-Type': 'application/json; charset=utf-8' }
    });
    const success = (code, data = {}) => json({ ok: true, success: true, code, message: 'OK', data });
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const originalFetch = window.fetch.bind(window);

    window.fetch = async (input, init = {}) => {
      const url = new URL(typeof input === 'string' ? input : input.url, location.href);
      if (!url.pathname.startsWith('/api/')) return originalFetch(input, init);
      window.__weeklyTest.requests.push(`${url.pathname}${url.search}`);
      if (url.pathname.endsWith('/znews/auth/session.php')) {
        return success('ZNEWS_SESSION_OK', { user: { uid: 'CREATOR_A', name: 'Creator A', role: 'USER', status: 'ACTIVE' } });
      }
      if (url.pathname.endsWith('/znews/public/feed.php')) {
        return success('ZNEWS_PUBLIC_FEED_OK', { items: [], next_cursor: '', has_more: false, ranking_mode: 'FRESH_FAIR_V1' });
      }
      if (url.pathname.endsWith('/znews/reviews/mine.php')) {
        await sleep(window.__weeklyTest.delay);
        const includeCurrent = url.searchParams.get('include_current') !== '0';
        const includeHistory = url.searchParams.get('include_history') !== '0';
        if (includeCurrent && window.__weeklyTest.currentFailures > 0) {
          window.__weeklyTest.currentFailures -= 1;
          return json({ ok: false, code: 'ZNEWS_WEEKLY_PREVIEW_TEST_FAILURE', message: 'Fixture current preview failed.', data: {} }, 503);
        }
        if (includeHistory && window.__weeklyTest.failures > 0) {
          window.__weeklyTest.failures -= 1;
          return json({ ok: false, code: 'ZNEWS_WEEKLY_TEST_FAILURE', message: 'Fixture failed.', data: {} }, 503);
        }
        const cursor = url.searchParams.get('cursor') || '';
        const items = !includeHistory || window.__weeklyTest.empty
          ? []
          : (cursor ? window.__weeklyTest.history.slice(2) : window.__weeklyTest.history.slice(0, 2));
        const current = {
          ...window.__weeklyTest.baseReview,
          period_id: '2026-08-31',
          period_start_date: '2026-08-31',
          period_end_date: '2026-09-06',
          review_status: window.__weeklyTest.currentStatus,
          live_preview: window.__weeklyTest.currentLive,
          traffic_share_pending: window.__weeklyTest.currentLive
        };
        return success('ZNEWS_WEEKLY_CREATOR_REVIEWS_OK', {
          creator: { uid: 'CREATOR_A', name: 'Creator A', role: 'USER', status: 'ACTIVE' },
          current_preview: includeCurrent ? current : null,
          items,
          next_cursor: includeHistory && !cursor && !window.__weeklyTest.empty ? '2026-08-17' : '',
          has_more: includeHistory && !cursor && !window.__weeklyTest.empty,
          money_fields_present: false
        });
      }
      if (url.pathname.endsWith('/znews/public/policy.php')) return success('ZNEWS_POLICY_OK', {});
      return success('ZNEWS_TEST_OK', {});
    };
  });

  try {
    const origin = `http://127.0.0.1:${server.address().port}`;
    await page.goto(`${origin}/znews/`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.ZNEWS_AUTH_VERIFIED === true && Boolean(window.ZNewsWeeklyPerformance));
    await page.evaluate(() => {
      const priority = window.ZNewsRequestScheduler.PRIORITY.ANALYTICS;
      window.ZNEWS_REQUEST_SCHEDULER.schedule(priority, ({ signal }) => new Promise((resolve, reject) => {
        window.__weeklyTest.backgroundEvents.push('start');
        const timer = setTimeout(() => {
          window.__weeklyTest.backgroundEvents.push('end');
          resolve();
        }, 800);
        signal.addEventListener('abort', () => {
          clearTimeout(timer);
          window.__weeklyTest.backgroundEvents.push('abort');
          const error = new Error('aborted');
          error.name = 'AbortError';
          reject(error);
        }, { once: true });
      }), { key: 'weekly-test-background' }).catch(() => {});
    });
    await page.waitForFunction(() => window.__weeklyTest.backgroundEvents.includes('start'));
    await page.locator('#menuToggle').click();
    const route = page.locator('#menuDrawer [data-menu-route="performance"]');
    await route.waitFor({ state: 'visible' });
    await route.click();

    await page.waitForTimeout(190);
    assert.equal(await page.locator('#weeklyPerformanceSkeleton').isVisible(), true, 'Slow weekly response did not show skeletons after 150ms.');
    await page.waitForFunction(() => window.ZNewsWeeklyPerformance.snapshot().loaded === true);
    assert.equal(await page.evaluate(() => window.__weeklyTest.backgroundEvents.includes('abort')), true, 'Weekly page P0 request did not preempt shared-origin background work.');

    await page.locator('#weeklyCurrentError').waitFor({ state: 'visible' });
    assert.equal(await page.locator('#weeklyPerformanceContent').isVisible(), true, 'A failed current preview hid the successfully loaded weekly history.');
    assert.equal(await page.locator('#weeklyReviewHistory [data-weekly-period]').count(), 2, 'Fast weekly history was not rendered before the current preview recovered.');
    if (process.env.WEEKLY_SCREENSHOT_DIR) {
      fs.mkdirSync(process.env.WEEKLY_SCREENSHOT_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.WEEKLY_SCREENSHOT_DIR, 'weekly-mobile-partial-390.png'), fullPage: true });
    }
    await page.locator('[data-weekly-current-retry]').click();
    await page.waitForFunction(() => window.ZNewsWeeklyPerformance.snapshot().currentLoaded === true && !window.ZNewsWeeklyPerformance.snapshot().loading);

    assert.equal(await page.locator('#weeklyCurrentStatus').textContent(), 'LIVE');
    assert.equal(await page.locator('#weeklyEligibleViews').textContent(), '1.2K');
    assert.equal(await page.locator('#weeklyEligibleViews').getAttribute('title'), '1,240');
    assert.equal(await page.locator('#weeklyReviewHistory [data-weekly-period]').count(), 2);
    assert.equal(await page.locator('#weeklyReviewLoadMore').isVisible(), true);
    assert.equal(await page.locator('#weeklyPerformanceView').getByText(/wallet balance|withdraw/i).count(), 0);
    if (process.env.WEEKLY_SCREENSHOT_DIR) {
      fs.mkdirSync(process.env.WEEKLY_SCREENSHOT_DIR, { recursive: true });
      await page.screenshot({ path: path.join(process.env.WEEKLY_SCREENSHOT_DIR, 'weekly-390.png'), fullPage: true });
    }

    const firstReview = page.locator('#weeklyReviewHistory [data-weekly-period]').first();
    await firstReview.click();
    await page.locator('#weeklyDetailDialog[open]').waitFor();
    assert.match(await page.locator('#weeklyDetailBody').textContent(), /Eligible views/);
    await page.keyboard.press('Escape');
    await page.waitForFunction(() => !document.querySelector('#weeklyDetailDialog').open);
    assert.equal(await firstReview.evaluate((element) => document.activeElement === element), true, 'Focus did not return to the opened review row.');

    await page.locator('#weeklyReviewLoadMore').click();
    await page.waitForFunction(() => window.ZNewsWeeklyPerformance.snapshot().itemCount === 3);
    const requests = await page.evaluate(() => window.__weeklyTest.requests.filter((item) => item.includes('/znews/reviews/mine.php')));
    assert.equal(requests[0].includes('include_current=1') && requests[0].includes('include_history=0'), true, 'Initial mobile load did not prioritize the isolated current preview.');
    assert.equal(requests[1].includes('include_current=0') && requests[1].includes('include_history=1'), true, 'Weekly history was not kept as an independent request.');
    assert.equal(requests.some((item) => item.includes('include_current=1') && item.includes('refresh_current=1')), true, 'Current-preview Retry did not bypass a stale derived cache.');
    assert.equal(requests.some((item) => item.includes('cursor=2026-08-17') && item.includes('include_current=0') && item.includes('include_history=1')), true, 'History pagination did not use the bounded cursor-only request.');

    await page.evaluate(() => { window.__weeklyTest.delay = 260; });
    const beforeRefresh = await page.locator('#weeklyEligibleViews').textContent();
    const requestCountBefore = requests.length;
    await page.locator('#weeklyReviewRefresh').click();
    await page.locator('#weeklyReviewRefresh').click({ force: true });
    await page.waitForTimeout(170);
    assert.equal(await page.locator('#weeklyEligibleViews').textContent(), beforeRefresh, 'Refresh removed existing weekly content.');
    assert.match(await page.locator('#weeklyInlineStatus').textContent(), /Refreshing/);
    await page.waitForFunction(() => !window.ZNewsWeeklyPerformance.snapshot().loading);
    const requestCountAfter = await page.evaluate(() => window.__weeklyTest.requests.filter((item) => item.includes('/znews/reviews/mine.php')).length);
    assert.equal(requestCountAfter, requestCountBefore + 2, 'Refresh did not issue exactly one history request and one isolated current request.');

    for (const status of ['UNDER_REVIEW', 'APPROVED', 'HELD']) {
      await page.evaluate((nextStatus) => {
        window.__weeklyTest.delay = 0;
        window.__weeklyTest.currentLive = false;
        window.__weeklyTest.currentStatus = nextStatus;
      }, status);
      await page.evaluate(() => window.ZNewsWeeklyPerformance.refresh());
      await page.waitForFunction(() => !window.ZNewsWeeklyPerformance.snapshot().loading);
      const expected = status === 'UNDER_REVIEW' ? 'UNDER REVIEW' : status;
      assert.equal(await page.locator('#weeklyCurrentStatus').textContent(), expected);
    }

    await page.evaluate(() => { window.__weeklyTest.failures = 1; window.__weeklyTest.delay = 0; });
    await page.locator('#weeklyReviewRefresh').click();
    await page.waitForFunction(() => !window.ZNewsWeeklyPerformance.snapshot().loading);
    assert.equal(await page.locator('#weeklyPerformanceContent').isVisible(), true, 'Refresh failure removed rendered data.');
    assert.match(await page.locator('#weeklyInlineStatus').textContent(), /could not be loaded/i);
    await page.locator('[data-weekly-retry]').click();
    await page.waitForFunction(() => !window.ZNewsWeeklyPerformance.snapshot().loading);

    await page.evaluate(() => {
      window.__weeklyTest.baseReview = {
        ...window.__weeklyTest.baseReview,
        raw_views: 0,
        eligible_views: 0,
        invalid_views: 0,
        creator_views_excluded: 0,
        self_views_excluded: 0,
        spam_views: 0,
        duplicate_views: 0,
        pending_views: 0,
        traffic_share_percent: 0
      };
    });
    await page.evaluate(() => window.ZNewsWeeklyPerformance.refresh());
    await page.waitForFunction(() => !window.ZNewsWeeklyPerformance.snapshot().loading);
    assert.equal(await page.locator('#weeklyEligibleViews').textContent(), '0');
    assert.equal(/NaN|Infinity/.test(await page.locator('#weeklyCurrentReview').textContent()), false, 'Zero metrics produced an invalid number.');

    await page.evaluate(() => { window.__weeklyTest.empty = true; });
    await page.evaluate(() => window.ZNewsWeeklyPerformance.refresh());
    await page.waitForFunction(() => !window.ZNewsWeeklyPerformance.snapshot().loading);
    assert.match(await page.locator('#weeklyReviewHistory').textContent(), /No weekly reviews yet/);

    for (const width of [320, 360, 390, 412, 430]) {
      await page.setViewportSize({ width, height: 844 });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
      assert.equal(overflow, false, `Weekly performance overflows at ${width}px.`);
      if (width === 320 && process.env.WEEKLY_SCREENSHOT_DIR) {
        await page.screenshot({ path: path.join(process.env.WEEKLY_SCREENSHOT_DIR, 'weekly-empty-320.png'), fullPage: true });
      }
    }
    await page.setViewportSize({ width: 1280, height: 900 });
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), false, 'Weekly performance overflows on desktop.');
    if (process.env.WEEKLY_SCREENSHOT_DIR) {
      await page.screenshot({ path: path.join(process.env.WEEKLY_SCREENSHOT_DIR, 'weekly-desktop-1280.png'), fullPage: true });
    }

    await page.locator('.weekly-policy-link').click();
    await page.waitForFunction(() => document.querySelector('#policyView').classList.contains('active'));
    assert.match(await page.locator('#policyView h1').textContent(), /Creator/);

    const allText = await page.locator('#weeklyPerformanceView').textContent();
    assert.equal(/NaN|undefined|null|Infinity/.test(allText), false, 'Unsafe numeric text reached the weekly performance UI.');
    const allRequests = await page.evaluate(() => window.__weeklyTest.requests);
    assert.equal(allRequests.some((item) => /[?&]uid=/.test(item)), false, 'Weekly UI sent a client-selected UID.');

    process.stdout.write('Z Sky 24 weekly performance browser test passed.\n');
  } finally {
    await browser.close();
    await close(server);
  }
}

main().catch((error) => {
  process.stderr.write(`${error.stack || error}\n`);
  process.exit(1);
});
