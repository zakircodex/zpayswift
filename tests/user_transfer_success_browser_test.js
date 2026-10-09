'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const transferScript = path.join(root, 'api', 'user', 'assets', 'pages', 'transfer-page.js');
const transferCss = path.join(root, 'api', 'user', 'assets', 'pages', 'transfer-page.css');
const shellCss = path.join(root, 'api', 'user', 'assets', 'user-shell.css');

function pageMarkup(origin) {
  const trackingUrl = `${origin}/receipt.php?t=LOCAL_TRANSFER_CAPABILITY`;
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/user-shell.css"><link rel="stylesheet" href="/transfer-page.css"></head>
    <body class="user-authenticated user-transfer-page"><main class="user-page-content">
      <section id="transferSection" class="page-section transfer-page-section active" data-tracking-base="${origin}/receipt.php">
        <div class="transfer-page-shell"><header class="transfer-page-header"><a id="transferBackButton" href="/user/dashboard">Back</a><h2>Z-Pay Transfer</h2></header>
        <div class="transfer-scroll-body">
          <div id="transferStepReceiver" class="transfer-step active"><div class="transfer-step-card"><input id="transferReceiverInput"><button id="transferResolveBtn">Continue</button></div><button id="transferFavoriteRefreshBtn">Refresh</button><div id="transferFavoriteList"></div></div>
          <div id="transferStepAmount" class="transfer-step"><div class="transfer-step-card"><div id="transferReceiverCard"></div><b id="transferCurrencyPrefix"></b><input id="transferAmountInput" type="number"><p id="transferMinimumHint"></p><button id="transferAmountNextBtn">Continue</button></div></div>
          <div id="transferStepPin" class="transfer-step"><div class="transfer-step-card"><div id="transferVerifySummary"></div><input id="transferPinInput"><button id="transferPreviewBtn">Continue</button></div></div>
          <div id="transferStepReview" class="transfer-step"><div class="transfer-step-card"><div id="transferReviewRows"></div><input id="transferReferenceInput"><button id="transferHoldConfirmBtn"><span class="transfer-hold-label">Tap and hold to confirm transfer</span></button></div></div>
        </div></div>
      </section>
    </main>
    <script>
      window.__transferTest={blurred:[],copied:'',toasts:[],calls:[]};
      Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async(value)=>{window.__transferTest.copied=value;}}});
      window.UserShell={
        ready:Promise.resolve(),holdPageLoad:()=>()=>{},setBusy:()=>{},refreshSession:async()=>{},
        toast:(message,type)=>window.__transferTest.toasts.push({message,type}),
        get:async(action)=>action==='transfer_favorites'?{favorites:[]}:{},
        post:async(action,payload)=>{
          window.__transferTest.calls.push({action,payload});
          if(action==='transfer_recipient') return {can_transfer:true,wallet_currency:'MYR',recipient:{receiver_name:'ZAKIR HOSEN',receiver_phone:'60108767201',receiver_phone_masked:'601*****201',wallet_currency:'MYR',can_transfer:true}};
          if(action==='transfer_preview'&&payload.check_only) return {minimum_amount:1};
          if(action==='transfer_preview') return {preview_token:'preview_token_1234567890',receiver_name:'ZAKIR HOSEN',receiver_phone:'60108767201',amount:1,amount_text:'RM 1.00',wallet_currency:'MYR',fee_amount:0,fee_text:'RM 0.00',total_paid:1,total_paid_text:'RM 1.00',balance_after:99,balance_after_text:'RM 99.00'};
          if(action==='transfer_create') return {transfer:{transfer_id:'WTR-LOCAL-1',receiver_name:'ZAKIR HOSEN',receiver_account:'601*****201',amount:1,amount_text:'RM 1.00',wallet_currency:'MYR',fee_amount:0,fee_text:'RM 0.00',total_paid:1,total_paid_text:'RM 1.00',status:'SUCCESS',receipt_url:${JSON.stringify(trackingUrl)},tracking_url:${JSON.stringify(trackingUrl)}}};
          throw new Error('Unexpected action '+action);
        }
      };
      addEventListener('DOMContentLoaded',()=>document.querySelectorAll('input').forEach((input)=>input.addEventListener('blur',()=>window.__transferTest.blurred.push(input.id))));
    </script><script src="/transfer-page.js"></script></body></html>`;
}

function sendFile(response, file, type) {
  response.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' });
  fs.createReadStream(file).pipe(response);
}

async function runFlow(browser, origin, width) {
  const context = await browser.newContext({
    viewport: { width, height: 844 }, hasTouch: true, isMobile: true, serviceWorkers: 'block',
    userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/140.0 Mobile Safari/537.36'
  });
  const page = await context.newPage();
  await page.goto(origin, { waitUntil: 'domcontentloaded' });

  await page.locator('#transferReceiverInput').fill('0108767201');
  await page.locator('#transferResolveBtn').click();
  await page.waitForFunction(() => document.getElementById('transferStepAmount')?.classList.contains('active'));
  await page.locator('#transferAmountInput').fill('1');
  await page.locator('#transferAmountNextBtn').click();
  await page.waitForFunction(() => document.getElementById('transferStepPin')?.classList.contains('active'));
  await page.locator('#transferPinInput').fill('1234');
  await page.locator('#transferPreviewBtn').click();
  await page.waitForFunction(() => document.getElementById('transferStepReview')?.classList.contains('active'));
  await page.locator('#transferReferenceInput').fill('Keyboard focus test');

  await page.evaluate(() => {
    const hold = document.getElementById('transferHoldConfirmBtn');
    hold.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
  });
  await page.waitForTimeout(2400);
  await page.evaluate(() => document.getElementById('transferHoldConfirmBtn')
    .dispatchEvent(new KeyboardEvent('keyup', { key: 'Enter', bubbles: true, cancelable: true })));
  await page.waitForFunction(() => document.querySelector('.transfer-success-modal.show'));
  await page.waitForTimeout(50);

  const state = await page.evaluate(() => {
    const open = Array.from(document.querySelectorAll('.transfer-modal-button')).find((node) => node.textContent === 'Open');
    const copy = Array.from(document.querySelectorAll('.transfer-modal-button')).find((node) => node.textContent === 'Copy');
    const done = Array.from(document.querySelectorAll('.transfer-modal-button')).find((node) => node.textContent === 'Done');
    const copyStyle = getComputedStyle(copy);
    const doneStyle = getComputedStyle(done);
    return {
      activeId: document.activeElement?.id || '',
      activeTag: document.activeElement?.tagName || '',
      openTag: open?.tagName || '',
      openHref: open?.href || '',
      openAriaDisabled: open?.getAttribute('aria-disabled'),
      copyDisabled: Boolean(copy?.disabled),
      copyAriaDisabled: copy?.getAttribute('aria-disabled'),
      copyCursor: copyStyle.cursor,
      copyBackground: copyStyle.backgroundColor,
      doneBackground: doneStyle.backgroundColor,
      blurred: window.__transferTest.blurred,
      overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth
    };
  });

  assert.equal(state.activeTag, 'A', `${width}px success modal did not move focus away from a form input.`);
  assert.equal(state.openTag, 'A', `${width}px Open was not rendered as an active tracking link.`);
  assert.equal(state.openHref, `${origin}/receipt.php?t=LOCAL_TRANSFER_CAPABILITY`, `${width}px Open lost its receipt URL.`);
  assert.equal(state.openAriaDisabled, 'false', `${width}px Open reports a disabled state.`);
  assert.equal(state.copyDisabled, false, `${width}px Copy remained disabled with a valid receipt URL.`);
  assert.equal(state.copyAriaDisabled, 'false', `${width}px Copy reports a disabled state.`);
  assert.equal(state.copyCursor, 'pointer', `${width}px Copy does not present as actionable.`);
  assert.notEqual(state.copyBackground, state.doneBackground, `${width}px Copy still looks like the neutral action.`);
  assert.ok(state.blurred.includes('transferReferenceInput'), `${width}px reference input was not blurred before success.`);
  assert.equal(state.overflow, false, `${width}px success modal overflows horizontally.`);

  await page.getByRole('button', { name: 'Copy', exact: true }).click();
  const copyResult = await page.evaluate(() => ({ copied: window.__transferTest.copied, toasts: window.__transferTest.toasts }));
  assert.equal(copyResult.copied, `${origin}/receipt.php?t=LOCAL_TRANSFER_CAPABILITY`, `${width}px Copy did not write the tracking URL.`);
  assert.ok(copyResult.toasts.some((item) => item.message === 'Tracking link copied' && item.type === 'ok'), `${width}px Copy success feedback is missing.`);

  if (process.env.ZPAY_TRANSFER_SCREENSHOT && width === 390) {
    await page.screenshot({ path: process.env.ZPAY_TRANSFER_SCREENSHOT, fullPage: true });
  }
  await context.close();
}

async function main() {
  let origin = '';
  const server = http.createServer((request, response) => {
    if (request.url === '/') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(pageMarkup(origin));
      return;
    }
    if (request.url === '/transfer-page.js') return sendFile(response, transferScript, 'application/javascript');
    if (request.url === '/transfer-page.css') return sendFile(response, transferCss, 'text/css');
    if (request.url === '/user-shell.css') return sendFile(response, shellCss, 'text/css');
    response.writeHead(404).end();
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  origin = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });

  try {
    for (const width of [320, 360, 390, 412, 430]) await runFlow(browser, origin, width);
    console.log('User transfer success browser tests passed (5 Android viewports).');
  } finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
