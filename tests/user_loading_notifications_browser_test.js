'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const asset = (...parts) => path.join(root, 'api', 'user', 'assets', ...parts);

function markup() {
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/user-shell.css"><link rel="stylesheet" href="/user-components.css"><link rel="stylesheet" href="/notifications-page.css"></head>
  <body class="user-authenticated user-service-checking user-notifications-page" data-user-page="notifications" data-active-section="notificationsSection">
  <section id="userMaintenanceView" class="hidden" aria-hidden="true"><button id="retryUserMaintenance">Retry</button></section>
  <div id="appView" inert aria-busy="true"><div id="sidebarOverlay"></div><aside id="sidebar" aria-hidden="true" inert></aside><div class="app-shell"><main class="main-panel"><div class="user-page-panel"><div class="user-page-content">
  <section id="notificationsSection" class="page-section notification-page-section active"><div class="notification-page-shell">
    <div class="notification-page-fixed-area"><header class="notification-page-header"><a class="notification-page-icon-button" href="#">Back</a><div class="notification-page-heading"><h1 id="notificationsPageTitle">Notifications</h1><p>Account and transaction updates</p></div><div class="notification-page-header-actions"><button id="notificationsRefreshButton" class="notification-page-icon-button">R</button><button id="notificationsEditButton" class="notification-page-icon-button notification-edit-button" aria-pressed="false">E</button></div></header>
    <div class="notification-page-tabs"><button class="notification-page-tab active" data-notification-filter="ALL">All Notifications</button><button class="notification-page-tab" data-notification-filter="UNREAD">Unread <span id="notificationUnreadCount">0</span></button></div></div>
    <div class="notification-page-scroll-body"><div id="notificationPageLive" class="notification-page-live"></div><div id="notificationList" class="notification-page-list" aria-busy="true"></div></div>
    <div id="notificationEditBar" class="notification-edit-bar hidden"><button id="notificationsSelectAllButton">Select All</button><button id="notificationsDeleteButton" disabled>Delete</button><button id="notificationsMarkSelectedButton" disabled>Mark Read</button></div>
  </div>
  <div id="notificationDetailModal" class="notification-detail-modal hidden" aria-modal="true" aria-hidden="true" inert><div class="notification-detail-backdrop" data-notification-detail-close></div><div class="notification-detail-sheet"><div class="notification-detail-handle"></div><header><span id="notificationDetailIcon" class="notification-page-card-icon">Z</span><h3 id="notificationDetailTitle">Notification</h3><button id="notificationDetailCloseButton">Close</button></header><div class="notification-detail-content"><time id="notificationDetailTime"></time><p id="notificationDetailBody">Loading notification...</p><button id="notificationDetailRetryButton" class="notification-detail-retry hidden">Retry</button></div><div class="notification-detail-actions"><button id="notificationDetailDeleteButton">Delete</button><button id="notificationDetailOpenButton">Open Related Page</button></div></div></div>
  </section></div></div></main></div></div>
  <nav class="bottom-nav" inert><div class="bottom-nav-inner"><a class="bottom-btn" href="#">Home</a><a class="bottom-btn" href="#">Add Money</a><a class="bottom-btn" href="#">Transfer</a><a class="bottom-btn" href="#">History</a><a class="bottom-btn" href="#">Profile</a></div></nav><div id="loadingWrap" class="loading user-global-loading show" role="dialog" aria-modal="true" aria-hidden="false"><div class="loading-box user-global-loading-card"><div class="spinner"></div><strong id="loadingTitle">Z-Pay Swift</strong><div id="loadingText">Loading your account...</div></div></div><div id="toastWrap" class="toast-wrap"></div>
  <script>window.USER_PROXY_URL='/api/user/proxy.php';window.USER_LOGIN_URL='/user/';window.USER_PAGE_KEY='notifications';window.USER_BOOTSTRAP_ACTION='me';</script><script src="/user-shell.js"></script><script src="/notifications-page.js"></script></body></html>`;
}

function sendFile(response, file, type) {
  response.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' });
  fs.createReadStream(file).pipe(response);
}

async function main() {
  const captureDir = String(process.env.ZPAY_CAPTURE_DIR || '');
  if (captureDir) fs.mkdirSync(captureDir, { recursive: true });
  let listCalls = 0;
  let unreadCalls = 0;
  let detailCalls = 0;
  let markCalls = 0;
  let deleteCalls = 0;
  let failNextList = false;
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    if (url.pathname === '/') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(markup());
      return;
    }
    if (url.pathname === '/user-shell.css') return sendFile(response, asset('user-shell.css'), 'text/css');
    if (url.pathname === '/user-components.css') return sendFile(response, asset('user-components.css'), 'text/css');
    if (url.pathname === '/notifications-page.css') return sendFile(response, asset('pages', 'notifications-page.css'), 'text/css');
    if (url.pathname === '/user-shell.js') return sendFile(response, asset('user-shell.js'), 'application/javascript');
    if (url.pathname === '/notifications-page.js') return sendFile(response, asset('pages', 'notifications-page.js'), 'application/javascript');
    if (url.pathname !== '/api/user/proxy.php') return response.writeHead(404).end();

    const action = url.searchParams.get('action') || '';
    const reply = (data, delay = 0) => setTimeout(() => {
      response.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(JSON.stringify({ ok: true, code: 'SUCCESS', data }));
    }, delay);
    if (action === 'me') return reply({ user: { uid: 'U-TEST', name: 'Test User' }, csrf: 'TEST-CSRF' }, 180);
    if (action === 'notifications_unread') {
      unreadCalls += 1;
      return reply({ unread_count: 1 });
    }
    if (action === 'notifications_list') {
      listCalls += 1;
      if (failNextList) {
        failNextList = false;
        setTimeout(() => {
          response.writeHead(503, { 'Content-Type': 'application/json' });
          response.end(JSON.stringify({ ok: false, code: 'TEMPORARY', message: 'Temporary failure.', data: {} }));
        }, 180);
        return;
      }
      return reply({ items: [{ notification_id: 'N-1', type: 'TRANSFER_SUCCESS', category: 'TRANSACTIONS', title: 'Transfer complete', body: 'Your transfer is complete.', is_read: false, created_at: 1788750000 }], unread_count: 1 }, 650);
    }
    if (action === 'notification_details') {
      detailCalls += 1;
      if (detailCalls === 1) {
        response.writeHead(503, { 'Content-Type': 'application/json' });
        response.end(JSON.stringify({ ok: false, code: 'TEMPORARY', message: 'Temporary failure.', data: {} }));
        return;
      }
      return reply({ notification: { notification_id: 'N-1', body_full: 'Canonical notification details.' } }, 80);
    }
    if (action === 'notification_mark_read') {
      markCalls += 1;
      return reply({ unread_count: 0, marked_count: 1 }, 40);
    }
    if (action === 'notifications_delete') {
      deleteCalls += 1;
      return reply({ unread_count: 0, deleted_count: 1 }, 40);
    }
    response.writeHead(404, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ ok: false, code: 'NOT_FOUND', data: {} }));
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
  const widths = [320, 360, 390, 412, 430];
  try {
    for (const width of widths) {
      const context = await browser.newContext({ viewport: { width, height: 844 }, hasTouch: true, isMobile: true, serviceWorkers: 'block' });
      const page = await context.newPage();
      await page.goto(`http://127.0.0.1:${server.address().port}/`, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(80);
      assert.equal(await page.locator('#loadingWrap').getAttribute('aria-hidden'), 'false', `${width}px loader did not open.`);
      assert.equal(await page.locator('#appView').evaluate((node) => node.inert), true, `${width}px app is interactive during bootstrap.`);
      await page.waitForTimeout(260);
      assert.equal(await page.locator('#loadingWrap').getAttribute('aria-hidden'), 'false', `${width}px loader closed before notifications loaded.`);
      if (captureDir && width === 390) await page.screenshot({ path: path.join(captureDir, 'notifications-loading.png'), fullPage: true });
      await page.locator('.notification-page-card').waitFor();
      await page.waitForFunction(() => document.getElementById('loadingWrap')?.getAttribute('aria-hidden') === 'true');
      assert.equal(await page.locator('#appView').evaluate((node) => node.inert), false, `${width}px app stayed locked.`);
      const geometry = await page.evaluate(() => ({
        header: document.querySelector('.notification-page-header')?.getBoundingClientRect().toJSON(),
        tabs: document.querySelector('.notification-page-tabs')?.getBoundingClientRect().toJSON(),
        card: document.querySelector('.notification-page-card')?.getBoundingClientRect().toJSON(),
        nav: document.querySelector('.bottom-nav-inner')?.getBoundingClientRect().toJSON(),
        navDisplay: getComputedStyle(document.querySelector('.bottom-nav')).display
      }));
      assert.ok(geometry.header.height <= 90, `${width}px notification header is too tall.`);
      assert.ok(geometry.tabs.y < 150, `${width}px notification tabs shifted outside the header rhythm.`);
      assert.ok(geometry.card.y < 230, `${width}px first notification shifted below the useful viewport.`);
      assert.notEqual(geometry.navDisplay, 'none', `${width}px shared navigation is hidden.`);
      assert.ok(geometry.nav.height >= 70 && geometry.nav.bottom <= 844, `${width}px shared navigation is outside the viewport.`);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), true, `${width}px page overflows horizontally.`);
      if (captureDir && width === 390) await page.screenshot({ path: path.join(captureDir, 'notifications-loaded.png'), fullPage: true });
      await context.close();
    }

    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, serviceWorkers: 'block' });
    const page = await context.newPage();
    await page.goto(`http://127.0.0.1:${server.address().port}/`);
    await page.locator('.notification-page-card').waitFor();
    await page.locator('.notification-page-card').click();
    await page.locator('#notificationDetailRetryButton:not(.hidden)').waitFor();
    assert.equal(await page.locator('#notificationDetailBody').textContent(), 'Notification details could not be loaded.');
    assert.equal(await page.locator('.notification-page-card.unread').count(), 0, 'Successful mark-read was overwritten by stale detail data.');
    await page.locator('#notificationDetailRetryButton').click();
    await page.waitForFunction(() => document.getElementById('notificationDetailBody')?.textContent === 'Canonical notification details.');
    await page.locator('#notificationDetailCloseButton').click();
    failNextList = true;
    await page.locator('#notificationsRefreshButton').click();
    await page.waitForTimeout(300);
    assert.equal(await page.locator('.notification-page-card').count(), 1, 'Failed refresh removed existing notifications.');
    assert.equal(await page.locator('#notificationList').getAttribute('aria-busy'), 'false');
    await page.locator('#notificationsEditButton').click();
    await page.locator('.notification-page-card').click();
    await page.locator('#notificationsDeleteButton').click();
    await page.waitForFunction(() => document.querySelectorAll('.notification-page-card').length === 0);
    await context.close();

    assert.equal(unreadCalls, 0, 'Notification page started redundant unread requests.');
    assert.equal(listCalls, widths.length + 2, 'Notification list request count is unexpected.');
    assert.equal(detailCalls, 2, 'Notification detail Retry did not issue exactly one replacement request.');
    assert.equal(markCalls, 1, 'Notification detail Retry duplicated the mark-read request.');
    assert.equal(deleteCalls, 1, 'Notification delete did not issue exactly one request.');
    console.log(`User loading/notification browser tests passed (${widths.length} mobile viewports).`);
  } finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
