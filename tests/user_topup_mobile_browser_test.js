'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const topupScript = path.join(root, 'api', 'user', 'assets', 'pages', 'topup-page.js');
const topupCss = path.join(root, 'api', 'user', 'assets', 'pages', 'topup-page.css');
const shellCss = path.join(root, 'api', 'user', 'assets', 'user-shell.css');

function pageMarkup() {
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/user-shell.css"><link rel="stylesheet" href="/topup-page.css"></head>
  <body class="user-authenticated user-topup-page"><div id="appView"><main class="app-shell"><div class="main-panel"><div class="user-page-content">
    <section id="topupSection" class="page-section topup-page-section active" aria-busy="true"><div class="topup-page-shell">
      <header class="topup-page-header"><a id="topupBackButton" href="#">Back</a><h1>Mobile Top-Up</h1><a href="#">Notices</a></header>
      <div id="topupScrollBody" class="topup-scroll-body">
        <div id="topupStepNumber" class="topup-step active" data-topup-step="number"><div class="topup-step-card"><h2>Mobile Top-Up</h2>
          <button id="topupCountryButton" type="button"><span id="topupCountryCodeBadge"></span><span id="topupCountryName"></span><span id="topupCountryDialCode"></span></button>
          <input id="topupNumberInput"><button id="topupNumberContinueButton" type="button">Continue</button></div><div id="topupFavoriteList"></div></div>
        <div id="topupStepAmount" class="topup-step" data-topup-step="amount"><div class="topup-step-card"><h2>Top-Up Amount</h2>
          <div id="topupAmountSummary"></div><div class="topup-amount-heading"><small id="topupAmountCurrency"></small></div><div id="topupPresetGrid" class="topup-preset-grid"></div>
          <label id="topupCustomAmountField" class="topup-field-group topup-custom-amount"><span id="topupAmountPrefix"></span><input id="topupAmountInput" type="number"></label>
          <p id="topupMinimumHint"></p><button id="topupAmountContinueButton" type="button">Continue</button></div></div>
        <div id="topupStepPin" class="topup-step" data-topup-step="pin"><div class="topup-step-card"><div id="topupPinSummary"></div><input id="topupPinInput"><button id="topupPinContinueButton" type="button">Continue</button></div></div>
        <div id="topupStepPreview" class="topup-step" data-topup-step="preview"><div class="topup-step-card"><div id="topupPreviewRows"></div>
          <button id="topupHoldConfirmButton" type="button"><span class="topup-hold-progress"></span><span class="topup-hold-label">Hold to Confirm</span></button></div></div>
      </div></div>
      <div id="topupActionModal" class="topup-action-modal" aria-hidden="true" inert><button data-topup-modal-close></button><div class="topup-modal-card">
        <button id="topupModalCloseButton"></button><div id="topupModalIcon"></div><h2 id="topupModalTitle"></h2><p id="topupModalMessage"></p><div id="topupModalBody"></div><div id="topupModalActions"></div>
      </div></div>
    </section></div></div></main></div>
    <nav class="bottom-nav"><div class="bottom-nav-inner">${'<a class="bottom-btn">Menu</a>'.repeat(5)}</div></nav>
    <script>
      window.__topupCalls=[];
      window.UserShell={
        ready:Promise.resolve(),state:{bootstrapData:{user:{uid:'MY-TOPUP-USER'}}},holdPageLoad:()=>()=>{},isSessionError:()=>false,toast:()=>{},
        get:async()=>({wallet:{wallet_currency:'MYR',display_currency:'MYR',display_available_balance:100}}),
        post:async(action,payload)=>{
          window.__topupCalls.push({action,payload});
          if(action==='validate_pin') return {verified:true};
          if(action==='topup_preview') {
            const data={country:'Malaysia',country_code:'MY',topup_number:payload.topup_number,operator:'Digi',operator_code:'DIGI',amount:payload.amount,
              topup_amount:payload.amount,topup_currency:'MYR',topup_amount_text:'RM '+Number(payload.amount).toFixed(2),wallet_currency:'MYR',
              rate_applicable:true,rate_text:'RM 1 = 31.10 BDT',commission_applicable:false,fee_text:'RM 0.00',wallet_debit_amount:payload.amount,
              total_pay_text:'RM '+Number(payload.amount).toFixed(2),balance_after_text:'RM '+(100-Number(payload.amount)).toFixed(2)};
            if(!payload.check_only) data.preview_token='topup-browser-preview-token';
            return data;
          }
          if(action==='topup_submit') return {request_id:'TOPUP-MY-001',status:'PENDING',topup_number:payload.topup_number,operator:'DIGI',operator_name:'Digi',topup_amount:payload.amount,topup_currency:'MYR',topup_amount_text:'RM '+Number(payload.amount).toFixed(2)};
          throw new Error('Unexpected action '+action);
        }
      };
    </script><script src="/topup-page.js"></script>
  </body></html>`;
}

function sendFile(response, file, type) {
  response.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' });
  fs.createReadStream(file).pipe(response);
}

async function selectMalaysia(page) {
  await page.locator('#topupCountryButton').click();
  await page.locator('.topup-modal-option', { hasText: 'Malaysia' }).click();
  await page.waitForFunction(() => document.getElementById('topupCountryName')?.textContent === 'Malaysia');
}

async function main() {
  const server = http.createServer((request, response) => {
    if (request.url === '/') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(pageMarkup());
      return;
    }
    if (request.url === '/topup-page.js') return sendFile(response, topupScript, 'application/javascript');
    if (request.url === '/topup-page.css') return sendFile(response, topupCss, 'text/css');
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
      await page.waitForFunction(() => document.getElementById('topupSection')?.getAttribute('aria-busy') === 'false');
      await selectMalaysia(page);

      const state = await page.evaluate(() => {
        const nav = document.querySelector('.bottom-nav');
        return {
          currency: document.getElementById('topupAmountCurrency')?.textContent,
          presetLabels: [...document.querySelectorAll('#topupPresetGrid button')].map((button) => button.textContent),
          customHidden: document.getElementById('topupCustomAmountField')?.hidden,
          customDisabled: document.getElementById('topupAmountInput')?.disabled,
          navGap: Math.round(window.innerHeight - nav.getBoundingClientRect().bottom),
          overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth
        };
      });
      assert.equal(state.currency, 'MYR', `${width}px Malaysia currency is not MYR.`);
      assert.deepEqual(state.presetLabels, ['RM 5', 'RM 10', 'RM 15', 'RM 20', 'RM 25', 'RM 30', 'RM 35', 'RM 40', 'RM 45', 'RM 50'], `${width}px Malaysia preset catalog is wrong.`);
      assert.equal(state.customHidden, true, `${width}px Malaysia custom amount remained visible.`);
      assert.equal(state.customDisabled, true, `${width}px Malaysia custom amount remained enabled.`);
      assert.equal(state.overflow, false, `${width}px Top-Up page overflows horizontally.`);
      assert.ok(state.navGap >= 0 && state.navGap <= 1, `${width}px bottom navigation is raised by ${state.navGap}px.`);
      if (width === 390 && process.env.TOPUP_SCREENSHOT) {
        await page.screenshot({ path: process.env.TOPUP_SCREENSHOT, fullPage: true });
      }
      await context.close();
    }

    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, serviceWorkers: 'block' });
    const page = await context.newPage();
    await page.goto(`http://127.0.0.1:${server.address().port}/`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => document.getElementById('topupSection')?.getAttribute('aria-busy') === 'false');
    await selectMalaysia(page);
    await page.locator('#topupNumberInput').fill('0146321294');
    await page.locator('#topupNumberContinueButton').click();
    await page.locator('.topup-modal-option', { hasText: 'Digi' }).click();
    await page.waitForFunction(() => document.getElementById('topupStepAmount')?.classList.contains('active'));
    await page.locator('#topupPresetGrid button[data-topup-amount="10"]').click();
    await page.locator('#topupAmountContinueButton').click();
    await page.waitForFunction(() => document.getElementById('topupStepPin')?.classList.contains('active'));
    await page.locator('#topupPinInput').fill('1234');
    await page.locator('#topupPinContinueButton').click();
    await page.waitForFunction(() => document.getElementById('topupStepPreview')?.classList.contains('active'));
    assert.match(await page.locator('#topupPreviewRows').textContent(), /AmountRM 10\.00/);
    assert.match(await page.locator('#topupPreviewRows').textContent(), /Total PayRM 10\.00/);
    await page.locator('#topupHoldConfirmButton').focus();
    await page.keyboard.down('Enter');
    await page.waitForTimeout(2400);
    await page.keyboard.up('Enter');
    await page.waitForFunction(() => document.getElementById('topupActionModal')?.classList.contains('success'));
    const result = await page.evaluate(() => ({
      title: document.getElementById('topupModalTitle')?.textContent,
      calls: window.__topupCalls,
      result: document.getElementById('topupModalBody')?.textContent
    }));
    assert.equal(result.title, 'Success', 'Recovered Top-Up response did not render success.');
    assert.match(result.result, /AmountRM 10\.00/, 'Success result lost the MYR amount.');
    assert.equal(result.calls.filter((call) => call.action === 'topup_submit').length, 1, 'Top-Up submit ran more than once.');
    const submit = result.calls.find((call) => call.action === 'topup_submit');
    assert.equal(submit.payload.amount, 10, 'Top-Up submit changed the selected RM amount.');
    assert.equal(result.calls.filter((call) => call.action === 'topup_preview' && !call.payload.check_only).length, 1, 'Top-Up created duplicate authoritative previews.');
    await context.close();

    console.log(`User Top-Up mobile browser tests passed (${widths.length} Android viewports + 1 full MYR flow).`);
  } finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
