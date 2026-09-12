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
  <link rel="stylesheet" href="/user-shell.css"><link rel="stylesheet" href="/user-components.css"><link rel="stylesheet" href="/referral-page.css"></head>
  <body class="user-authenticated user-referral-page" data-user-page="referral" data-active-section="referralSection">
    <div id="appView"><div class="app-shell"><main class="main-panel"><div class="user-page-content">
      <section id="referralSection" class="page-section referral-page-section active" aria-busy="true">
        <header class="referral-page-header"><a class="referral-icon-button" href="#">Back</a><h1>Refer &amp; Earn</h1><a class="referral-icon-button" href="#">Alerts</a></header>
        <div class="referral-scroll-body">
          <div id="referralStatusMessage" class="referral-status-message hidden"></div>
          <section class="referral-summary"><div><small>Total Earned</small><strong id="referralTotalEarned">--</strong></div><div><small>Referred</small><strong id="referralCount">--</strong></div><div><small>Rewarded</small><strong id="referralRewardedCount">--</strong></div></section>
          <section class="referral-panel"><div class="referral-code-row"><code id="referralCode">Loading...</code></div><div class="referral-actions"><button id="referralCopyCode">Copy code</button><button id="referralShare">Share link</button></div></section>
          <section id="referralClaimPanel" class="referral-panel hidden"><form id="referralClaimForm"><input id="referralClaimCode"><button id="referralClaimButton">Apply</button></form></section>
          <section id="referralDevicePanel" class="referral-panel hidden"></section>
          <section class="referral-panel"><h2>How rewards work</h2><div id="referralRules" class="referral-rules"></div></section>
          <section class="referral-history-section"><div class="referral-history-heading"><h2>Reward history</h2><span id="referralHistoryCurrency"></span></div><div id="referralHistoryList" class="referral-history-list"></div><button id="referralSeeMore" class="referral-see-more hidden">See more</button></section>
        </div>
      </section>
    </div></main></div></div>
    <script>
      window.__referralCalls = [];
      window.UserShell = {
        ready: Promise.resolve(),
        holdPageLoad: function () { return function () {}; },
        escapeHtml: function (value) { return String(value); }
      };
      window.proxyPost = async function () { return {}; };
      window.proxyGet = async function (action, params) {
        window.__referralCalls.push({ action: action, params: Object.assign({}, params) });
        if (params.scope === 'core') {
          return {
            enabled: true,
            country: 'MY',
            reward_currency: 'MYR',
            referral_code: 'ZPTEST123456',
            share_url: 'https://zpayswift.com/user/referral?code=ZPTEST123456',
            total_earned: 12.5,
            referred_count: 12,
            rewarded_count: 12,
            relation: {},
            claim: { eligible: false },
            rules: {
              claim_window_days: 7,
              providers: { BKASH: true, NAGAD: true },
              my_fees: {
                TIER1: { USER: 5, RETAILER: 2 },
                TIER2: { USER: 7, RETAILER: 3 },
                TIER3: { USER: 10, RETAILER: 4 }
              },
              my_commissions: {
                USER: { TIER1: 1, TIER2: 1.2, TIER3: 2 },
                RETAILER: { TIER1: 0.1, TIER2: 0.15, TIER3: 0.2 }
              }
            }
          };
        }
        const secondPage = Number(params.before || 0) > 0;
        const count = secondPage ? 2 : 10;
        const items = Array.from({ length: count }, function (_, index) {
          return {
            payout_id: (secondPage ? 'second-' : 'first-') + index,
            payout_type: 'MY_RECURRING',
            amount: 1,
            currency: 'MYR',
            provider: index % 2 ? 'NAGAD' : 'BKASH',
            referred_name_masked: 'T***',
            created_at: 1800000000 - index
          };
        });
        return { history: { items: items, has_more: !secondPage, next_before: secondPage ? 0 : 1700000000 } };
      };
    </script>
    <script src="/referral-page.js"></script>
  </body></html>`;
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
    if (request.url === '/user-shell.css') return sendFile(response, asset('user-shell.css'), 'text/css');
    if (request.url === '/user-components.css') return sendFile(response, asset('user-components.css'), 'text/css');
    if (request.url === '/referral-page.css') return sendFile(response, asset('pages', 'referral-page.css'), 'text/css');
    if (request.url === '/referral-page.js') return sendFile(response, asset('pages', 'referral-page.js'), 'application/javascript');
    response.writeHead(404).end();
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  let browser = null;
  try {
    const launchOptions = { headless: true };
    if (process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE) {
      launchOptions.executablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE;
    }
    browser = await chromium.launch(launchOptions);
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    await page.goto(`http://127.0.0.1:${server.address().port}/`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => document.querySelectorAll('.referral-history-item').length === 10);

    const layout = await page.evaluate(() => {
      const section = document.getElementById('referralSection');
      const scroller = document.querySelector('.referral-scroll-body');
      const code = document.getElementById('referralCode');
      scroller.scrollTop = 240;
      return {
        sectionDisplay: getComputedStyle(section).display,
        overflowY: getComputedStyle(scroller).overflowY,
        canScroll: scroller.scrollHeight > scroller.clientHeight,
        scrollTop: scroller.scrollTop,
        codeAlign: getComputedStyle(code).textAlign,
        actionCount: document.querySelectorAll('.referral-actions button').length,
        calls: window.__referralCalls
      };
    });

    assert.equal(layout.sectionDisplay, 'grid');
    assert.equal(layout.overflowY, 'auto');
    assert.equal(layout.canScroll, true);
    assert.ok(layout.scrollTop > 0);
    assert.equal(layout.codeAlign, 'center');
    assert.equal(layout.actionCount, 2);
    assert.deepEqual(layout.calls.map((call) => call.params.scope), ['core', 'history']);
    assert.equal(layout.calls[1].params.limit, 10);

    await page.locator('#referralSeeMore').click();
    await page.waitForFunction(() => document.querySelectorAll('.referral-history-item').length === 12);
    assert.equal(await page.locator('#referralSeeMore').isHidden(), true);

    const calls = await page.evaluate(() => window.__referralCalls);
    assert.equal(calls.length, 3);
    assert.equal(calls[2].params.before, 1700000000);
    console.log('User referral progressive browser test passed (mobile scroll + 10 item pagination).');
  } finally {
    if (browser) await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
