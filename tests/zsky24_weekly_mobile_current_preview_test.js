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
  if (file.endsWith('.png')) return 'image/png';
  return 'application/octet-stream';
}

function listen(server) {
  return new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
}

async function runCold(browser, origin, options) {
  const context = await browser.newContext({
    viewport: { width: options.width, height: 844 },
    hasTouch: options.mobile,
    isMobile: options.mobile,
    userAgent: options.mobile
      ? 'Mozilla/5.0 (Linux; Android 15; ELI-NX9) AppleWebKit/537.36 Chrome/140.0 Mobile Safari/537.36'
      : undefined,
    serviceWorkers: 'block'
  });
  const page = await context.newPage();
  await page.addInitScript(({ currentDelay, historyDelay }) => {
    sessionStorage.setItem('znews_session_v1', 'weekly-mobile-session');
    sessionStorage.setItem('znews_profile_v1', JSON.stringify({ uid: 'CREATOR_A', name: 'Creator A' }));
    window.__weeklyMobile = { currentDelay, historyDelay, requests: [], currentActive: 0, currentMax: 0 };
    const response = (data) => new Response(JSON.stringify({ ok: true, success: true, code: 'OK', message: 'OK', data }), {
      status: 200,
      headers: { 'Content-Type': 'application/json; charset=utf-8' }
    });
    const sleep = (duration) => new Promise((resolve) => setTimeout(resolve, duration));
    const originalFetch = window.fetch.bind(window);
    window.fetch = async (input, init = {}) => {
      const url = new URL(typeof input === 'string' ? input : input.url, location.href);
      if (!url.pathname.startsWith('/api/')) return originalFetch(input, init);
      if (url.pathname.endsWith('/znews/auth/session.php')) {
        return response({ user: { uid: 'CREATOR_A', name: 'Creator A', role: 'USER', status: 'ACTIVE' } });
      }
      if (url.pathname.endsWith('/znews/public/feed.php')) {
        return response({ items: [], next_cursor: '', has_more: false, ranking_mode: 'FRESH_FAIR_V1' });
      }
      if (url.pathname.endsWith('/znews/reviews/mine.php')) {
        const current = url.searchParams.get('include_current') === '1';
        const entry = { current, start: performance.now(), end: 0 };
        window.__weeklyMobile.requests.push(entry);
        if (current) {
          window.__weeklyMobile.currentActive += 1;
          window.__weeklyMobile.currentMax = Math.max(window.__weeklyMobile.currentMax, window.__weeklyMobile.currentActive);
          await sleep(window.__weeklyMobile.currentDelay);
          window.__weeklyMobile.currentActive -= 1;
          entry.end = performance.now();
          return response({
            creator: { creator_uid: 'CREATOR_A', name: 'Creator A', status: 'ACTIVE' },
            current_preview: {
              period_id: '2026-08-31', period_start_date: '2026-08-31', period_end_date: '2026-09-06',
              creator_uid: 'CREATOR_A', creator_name: 'Creator A', creator_status: 'ACTIVE',
              review_status: 'UNDER_REVIEW', live_preview: true, raw_views: 7, eligible_views: 2,
              invalid_views: 1, creator_views_excluded: 2, self_views_excluded: 1,
              spam_views: 1, duplicate_views: 0, pending_views: 2,
              traffic_share_percent: 0, traffic_share_pending: true
            },
            items: [], next_cursor: '', has_more: false, money_fields_present: false
          });
        }
        await sleep(window.__weeklyMobile.historyDelay);
        entry.end = performance.now();
        return response({
          creator: { creator_uid: 'CREATOR_A', name: 'Creator A', status: 'ACTIVE' },
          current_preview: null,
          items: [], next_cursor: '', has_more: false, money_fields_present: false
        });
      }
      return response({});
    };
  }, { currentDelay: options.currentDelay, historyDelay: options.historyDelay });

  try {
    await page.goto(`${origin}/znews/`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => window.ZNEWS_AUTH_VERIFIED === true && Boolean(window.ZNewsWeeklyPerformance));
    await page.locator('#menuToggle').click();
    await page.locator('#menuDrawer [data-menu-route="performance"]').click();
    const routeStarted = await page.evaluate(() => performance.now());
    await page.waitForFunction(() => window.ZNewsWeeklyPerformance.snapshot().currentLoaded === true);
    const currentReady = await page.evaluate((started) => performance.now() - started, routeStarted);
    const currentBeforeHistory = await page.evaluate(() => !window.ZNewsWeeklyPerformance.snapshot().loaded);
    await page.waitForFunction(() => window.ZNewsWeeklyPerformance.snapshot().loaded === true);
    const result = await page.evaluate(() => ({
      requests: window.__weeklyMobile.requests,
      currentMax: window.__weeklyMobile.currentMax,
      overflow: document.documentElement.scrollWidth > window.innerWidth,
      currentText: document.querySelector('#weeklyEligibleViews')?.textContent || ''
    }));
    const currentRequests = result.requests.filter((request) => request.current);
    assert.equal(result.requests[0]?.current, true, 'Current preview did not receive first P0 position.');
    assert.equal(currentRequests.length, 1, 'A cold page issued duplicate current-preview requests.');
    assert.equal(result.currentMax, 1, 'Current-preview concurrency exceeded one.');
    assert.equal(currentBeforeHistory, true, 'Current preview waited for weekly history.');
    assert.equal(result.currentText, '2', 'Current card did not render the preview response.');
    assert.equal(result.overflow, false, `Weekly page overflowed at ${options.width}px.`);
    return {
      width: options.width,
      currentRequestMs: Number((currentRequests[0].end - currentRequests[0].start).toFixed(1)),
      routeToCurrentMs: Number(currentReady.toFixed(1))
    };
  } finally {
    await context.close();
  }
}

async function main() {
  const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
    const relative = pathname === '/znews/' || pathname === '/znews' ? 'znews/index.html' : pathname.replace(/^\/+/, '');
    const file = path.resolve(root, relative);
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      response.writeHead(404).end();
      return;
    }
    response.writeHead(200, { 'Content-Type': contentType(file), 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(response);
  });
  await listen(server);
  const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome', headless: true });
  const origin = `http://127.0.0.1:${server.address().port}`;
  try {
    const mobileRuns = [];
    const widths = [360, 390, 412, 430, 390];
    const delays = [350, 500, 650, 800, 1200];
    for (let index = 0; index < widths.length; index += 1) {
      mobileRuns.push(await runCold(browser, origin, {
        width: widths[index], mobile: true, currentDelay: delays[index], historyDelay: 900
      }));
    }
    const desktopRuns = [];
    for (let index = 0; index < 5; index += 1) {
      desktopRuns.push(await runCold(browser, origin, {
        width: 1280, mobile: false, currentDelay: 350, historyDelay: 900
      }));
    }
    process.stdout.write(`mobile=${JSON.stringify(mobileRuns)}\n`);
    process.stdout.write(`desktop=${JSON.stringify(desktopRuns)}\n`);
    process.stdout.write('Z Sky weekly mobile current-preview runtime test passed.\n');
  } finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  process.stderr.write(`${error.stack || error}\n`);
  process.exit(1);
});
