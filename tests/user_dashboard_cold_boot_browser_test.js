'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const asset = (...parts) => path.join(root, 'api', 'user', 'assets', ...parts);

function pageMarkup() {
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/user-shell.css"><link rel="stylesheet" href="/user-components.css"><link rel="stylesheet" href="/dashboard-page.css"></head>
  <body class="user-authenticated user-service-checking user-dashboard-page" data-user-page="dashboard" data-active-section="overviewSection">
  <section id="userMaintenanceView" class="hidden" aria-hidden="true"><button id="retryUserMaintenance">Retry</button></section>
  <div id="appView" inert aria-busy="true"><div class="app-shell"><main class="main-panel"><div class="user-page-panel"><div class="user-page-content">
    <div class="dashboard-fixed-stack"><div class="hero-card"><div class="dashboard-hero-topbar"><button id="openSidebarBtn"></button><h1 id="dashboardHeroTitle">Z-Pay Swift</h1><a href="/user/notifications"><span data-notification-badge class="hidden">0</span></a></div>
      <div class="hero-balance"><span id="heroBalancePrefix" class="dashboard-placeholder dashboard-placeholder-prefix">BDT</span> <span id="heroBalance" class="dashboard-placeholder dashboard-placeholder-balance">--</span></div>
      <div class="hero-hold-line"><span id="heroHoldPrefix" class="dashboard-placeholder dashboard-placeholder-prefix">BDT</span> <span id="heroHold" class="dashboard-placeholder dashboard-placeholder-compact">--</span></div>
      <div id="heroGrid"><span id="heroRateCard"><span id="heroRateLabel">Today Rate</span><span id="heroRate" class="dashboard-placeholder dashboard-placeholder-rate">Loading rate</span></span><span id="heroRequests" class="dashboard-placeholder dashboard-placeholder-compact dashboard-deferred-placeholder">--</span><span id="heroName" class="dashboard-placeholder dashboard-placeholder-name">Loading account</span></div>
    </div></div>
    <section id="overviewSection" aria-busy="true"><div id="zpayQuickActions"><h2>Recommended</h2><button data-dashboard-action="shopping">Shopping</button></div></section>
    <div id="dashboardPullIndicator"><span id="dashboardPullText"></span></div>
  </div></div></main></div></div>
  <nav class="bottom-nav" inert></nav><aside id="sidebar" aria-hidden="true" inert></aside><div id="sidebarOverlay"></div>
  <div id="loadingWrap" class="loading user-global-loading show" role="dialog" aria-modal="true" aria-labelledby="loadingTitle" aria-describedby="loadingText" aria-hidden="false"><div class="loading-box user-global-loading-card"><div class="spinner"></div><strong id="loadingTitle">Z-Pay Swift</strong><div id="loadingText">Loading your account...</div></div></div><div id="toastWrap"></div>
  <script>window.USER_PROXY_URL='/api/user/proxy.php';window.USER_LOGIN_URL='/user/';window.USER_PAGE_KEY='dashboard';window.USER_BOOTSTRAP_ACTION='dashboard_bootstrap';window.USER_BOOTSTRAP_PARAMS={limit:50,summary_only:'1',balance_only:'1'};</script>
  <script src="/user-shell.js"></script><script src="/dashboard-page.js"></script></body></html>`;
}

function sendFile(response, file, type) {
  response.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' });
  fs.createReadStream(file).pipe(response);
}

async function main() {
  let dashboardCalls = 0;
  let activityCalls = 0;
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    if (url.pathname === '/') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(pageMarkup());
      return;
    }
    if (url.pathname === '/user-shell.css') return sendFile(response, asset('user-shell.css'), 'text/css');
    if (url.pathname === '/user-components.css') return sendFile(response, asset('user-components.css'), 'text/css');
    if (url.pathname === '/dashboard-page.css') return sendFile(response, asset('pages', 'dashboard-page.css'), 'text/css');
    if (url.pathname === '/user-shell.js') return sendFile(response, asset('user-shell.js'), 'application/javascript');
    if (url.pathname === '/dashboard-page.js') return sendFile(response, asset('pages', 'dashboard-page.js'), 'application/javascript');
    if (url.pathname !== '/api/user/proxy.php') return response.writeHead(404).end();

    const action = url.searchParams.get('action') || '';
    const reply = (data) => {
      response.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(JSON.stringify({ ok: true, code: 'SUCCESS', data }));
    };
    if (action === 'dashboard_bootstrap') {
      dashboardCalls += 1;
      const isBangladesh = String(request.headers.referer || '').includes('market=BD');
      setTimeout(() => reply({
        user: { uid: 'U-TEST', name: 'TEST USER', role: 'USER', status: 'ACTIVE', pricing_country: isBangladesh ? 'BD' : 'MY' },
        csrf: 'TEST-CSRF',
        wallet_summary: {
          pricing_country: isBangladesh ? 'BD' : 'MY',
          wallet: {
            display_currency: 'MYR',
            display_available_balance: isBangladesh ? 2500 : 25,
            display_hold_balance: 0,
            rate_myr_bdt: 31.1
          }
        },
        request_logs: { items: [], deferred: true }
      }), 900);
      return;
    }
    if (action === 'dashboard_activity_summary') {
      activityCalls += 1;
      setTimeout(() => reply({ request_count: 2 }), 1800);
      return;
    }
    if (action === 'notifications_unread') return reply({ unread_count: 0 });
    response.writeHead(404, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ ok: false, code: 'NOT_FOUND', data: {} }));
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
  const widths = [320, 360, 390, 412, 430];

  try {
    for (const width of widths) {
      const context = await browser.newContext({
        viewport: { width, height: 844 }, hasTouch: true, isMobile: true, serviceWorkers: 'block',
        userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/140.0 Mobile Safari/537.36'
      });
      const page = await context.newPage();
      const startedAt = Date.now();
      await page.goto(`http://127.0.0.1:${server.address().port}/`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(120);

      assert.equal(await page.locator('#appView').evaluate((node) => getComputedStyle(node).visibility), 'visible', `${width}px shell stayed hidden.`);
      assert.equal(await page.locator('#loadingWrap').getAttribute('aria-hidden'), 'false', `${width}px shared loader did not open.`);
      assert.equal(await page.locator('#appView').evaluate((node) => node.inert), true, `${width}px dashboard remained interactive while loading.`);
      assert.equal(await page.locator('.bottom-nav').evaluate((node) => node.inert), true, `${width}px navigation remained interactive while loading.`);
      assert.equal(await page.locator('#dashboardHeroTitle').isVisible(), true, `${width}px dashboard shell is not visible.`);
      assert.equal(await page.locator('body').evaluate((node) => node.classList.contains('user-service-checking')), true, `${width}px fixture resolved before the cold-shell assertion.`);
      assert.ok(Date.now() - startedAt < 1000, `${width}px shell was not useful within one second.`);

      await page.waitForFunction(() => window.UserShell?.state?.ready === true);
      await page.waitForFunction(() => document.getElementById('loadingWrap')?.getAttribute('aria-hidden') === 'true');
      assert.equal(await page.locator('#heroName').textContent(), 'TEST USER', `${width}px resolved dashboard did not render.`);
      assert.equal(await page.locator('#heroBalancePrefix').textContent(), 'RM', `${width}px loader closed before RM currency rendered.`);
      assert.equal(await page.locator('#heroBalance').textContent(), '25.00', `${width}px loader closed before balance rendered.`);
      assert.equal(await page.locator('#appView').evaluate((node) => node.inert), false, `${width}px dashboard stayed blocked after loading.`);
      assert.equal(await page.locator('.bottom-nav').evaluate((node) => node.inert), false, `${width}px navigation stayed blocked after loading.`);
      assert.equal(await page.locator('#zpayQuickActions button').isEnabled(), true, `${width}px dashboard actions waited for monthly activity.`);
      assert.equal(await page.locator('#heroRequests').getAttribute('class').then((value) => value.includes('dashboard-deferred-placeholder')), true, `${width}px deferred activity placeholder disappeared too early.`);
      assert.equal(await page.locator('#loadingWrap').getAttribute('aria-hidden'), 'true', `${width}px slow activity reopened the blocking modal.`);
      await page.waitForFunction(() => document.getElementById('heroRequests')?.textContent === '2');
      assert.equal(await page.locator('#heroRequests').getAttribute('class').then((value) => value.includes('dashboard-deferred-placeholder')), false, `${width}px activity placeholder stayed after render.`);
      assert.equal(await page.locator('body').textContent().then((text) => text.includes('Dashboard ready.') || text.includes('Loading account summary.')), false, `${width}px obsolete dashboard status text is visible.`);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), true, `${width}px dashboard overflows horizontally.`);
      await context.close();
    }

    const bdContext = await browser.newContext({
      viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, serviceWorkers: 'block',
      userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/140.0 Mobile Safari/537.36'
    });
    const bdPage = await bdContext.newPage();
    await bdPage.goto(`http://127.0.0.1:${server.address().port}/?market=BD`, { waitUntil: 'domcontentloaded' });
    await bdPage.waitForFunction(() => window.UserShell?.state?.ready === true);
    await bdPage.waitForFunction(() => document.getElementById('loadingWrap')?.getAttribute('aria-hidden') === 'true');
    assert.equal(await bdPage.locator('#heroBalancePrefix').textContent(), 'BDT', 'BD account did not keep BDT balance currency.');
    assert.equal(await bdPage.locator('#heroBalance').textContent(), '2500.00', 'BD account balance did not render.');
    assert.equal(await bdPage.locator('#heroRateCard').isVisible(), true, 'BD account type card is not visible.');
    assert.equal(await bdPage.locator('#heroRateLabel').textContent(), 'Account Type', 'BD account still labels the metric as Today Rate.');
    assert.equal(await bdPage.locator('#heroRate').textContent(), 'USER', 'BD account role did not replace Today Rate.');
    assert.equal(await bdPage.locator('#appView').evaluate((node) => node.inert), false, 'BD dashboard stayed blocked for monthly activity.');
    assert.equal(await bdPage.locator('#zpayQuickActions button').isEnabled(), true, 'BD dashboard actions waited for monthly activity.');
    await bdContext.close();

    assert.equal(dashboardCalls, widths.length + 1, 'Dashboard bootstrap did not run exactly once per page load.');
    assert.equal(activityCalls, widths.length + 1, 'Dashboard activity did not run exactly once per page load.');
    console.log(`User dashboard balance-first browser tests passed (${widths.length} MY mobile viewports + BD, ${dashboardCalls} balance requests, ${activityCalls} deferred activity requests).`);
  } finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
