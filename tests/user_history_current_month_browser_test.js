'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const historyScript = path.join(root, 'api', 'user', 'assets', 'pages', 'history-page.js');
const historyCss = path.join(root, 'api', 'user', 'assets', 'pages', 'history-page.css');
const shellCss = path.join(root, 'api', 'user', 'assets', 'user-shell.css');

function pageMarkup() {
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/user-shell.css"><link rel="stylesheet" href="/history-page.css"></head>
  <body class="user-authenticated user-history-page"><div class="user-page-content">
    <section id="historySection" class="page-section history-page-section active"><div class="history-page-shell">
      <header class="history-page-header"><a id="historyBackButton" href="#">Back</a><h1>History</h1><a href="#">Notices</a></header>
      <main class="history-page-body"><div id="historyLive" class="visually-hidden"></div><div id="historyList" class="history-list" aria-busy="true"></div></main>
      <div id="historyLoadMore" class="history-load-more" aria-busy="false" hidden><button id="historyLoadMoreButton" type="button">Load 10 more</button><span id="historyLoadMoreStatus"></span></div>
    </div>
    <div id="historyDetailModal" class="history-detail-modal hidden" aria-hidden="true" inert>
      <button data-history-modal-close>Close</button><div class="history-detail-card" tabindex="-1"><h2 id="historyDetailTitle"></h2><div id="historyDetailStatus"></div><div id="historyDetailRows"></div><div id="historyDetailActions"></div></div>
    </div></section>
  </div><div id="toastWrap"></div>
  <script>
    window.__historyCalls = [];
    window.UserShell = {
      ready: Promise.resolve(),
      state: {},
      holdPageLoad: () => () => {},
      toast: () => {},
      get: async (action, params) => {
        window.__historyCalls.push({ action, params });
        const now = Math.floor(Date.now() / 1000);
        const previous = Math.floor(new Date(new Date().getFullYear(), new Date().getMonth() - 1, 15).getTime() / 1000);
        return {
          items: Array.from({ length: 25 }, (_, index) => ({ request_id: 'MF-CURRENT-' + index, request_type: 'MFS', provider: 'BKASH', receiver_number: '01700000000', amount_bdt: 100 + index, created_at: now - index, status: 'PENDING' })),
          wallet_history: [{ transfer_id: 'WT-CURRENT', direction: 'CREDIT', amount: 50, currency: 'BDT', created_at: now, status: 'SUCCESS', counterparty_name: 'TEST USER' }],
          add_money_history: [{ request_id: 'AM-OLD', amount: 300, currency: 'BDT', created_at: previous, status: 'APPROVED' }],
          pagination: { limit: params.limit, has_more: false }
        };
      }
    };
  </script><script src="/history-page.js"></script></body></html>`;
}

function sendFile(response, file, type) {
  response.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' });
  fs.createReadStream(file).pipe(response);
}

async function main() {
  const server = http.createServer((request, response) => {
    if (request.url === '/') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(pageMarkup());
      return;
    }
    if (request.url === '/history-page.js') return sendFile(response, historyScript, 'application/javascript');
    if (request.url === '/history-page.css') return sendFile(response, historyCss, 'text/css');
    if (request.url === '/user-shell.css') return sendFile(response, shellCss, 'text/css');
    response.writeHead(404).end();
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
      await page.goto(`http://127.0.0.1:${server.address().port}/`, { waitUntil: 'domcontentloaded' });
      await page.waitForFunction(() => document.getElementById('historyList')?.getAttribute('aria-busy') === 'false');

      const calls = await page.evaluate(() => window.__historyCalls);
      assert.equal(calls.length, 1, `${width}px History made duplicate API requests.`);
      assert.equal(calls[0].action, 'request_logs', `${width}px History used the wrong endpoint.`);
      assert.match(calls[0].params.month, /^\d{4}-(0[1-9]|1[0-2])$/, `${width}px History omitted the current month.`);
      assert.equal(calls[0].params.limit, 10, `${width}px History initial page must remain bounded to 10.`);
      assert.equal(calls[0].params.legacy, 0, `${width}px History enabled legacy scans.`);
      assert.equal(await page.locator('.history-transaction-card').count(), 10, `${width}px History did not render exactly the first 10 rows.`);
      await page.locator('#historyLoadMore').scrollIntoViewIfNeeded();
      await page.waitForFunction(() => document.querySelectorAll('.history-transaction-card').length === 20);
      assert.equal(await page.locator('.history-transaction-card').count(), 20, `${width}px History did not reveal the next 10 rows.`);
      await page.evaluate(() => document.getElementById('historyLoadMoreButton').click());
      assert.equal(await page.locator('.history-transaction-card').count(), 26, `${width}px History did not reveal the final current-month rows.`);
      assert.equal(await page.locator('#historyLoadMore').isHidden(), true, `${width}px exhausted History loader remained visible.`);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), true, `${width}px History overflows horizontally.`);
      await context.close();
    }
    console.log(`User History current-month browser tests passed (${widths.length} Android viewports).`);
  } finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
