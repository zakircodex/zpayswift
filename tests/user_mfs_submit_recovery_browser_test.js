'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const mfsScript = path.join(root, 'api', 'user', 'assets', 'pages', 'mfs-page.js');

function pageMarkup(provider, outcome) {
  const label = provider === 'NAGAD' ? 'Nagad' : 'bKash';
  return `<!doctype html><html><body class="user-mfs-page">
    <button id="mfsBackButton" type="button">Back</button>
    <section id="mfsSection" data-provider="${provider}" data-tracking-base="/user/mfs-track.php">
      <div id="mfsScrollBody">
        <div id="mfsStepReceiver" data-mfs-step="receiver"><input id="mfsReceiverNumber"><div id="mfsFavoriteList"></div><button id="mfsReceiverContinue">Continue</button></div>
        <div id="mfsStepAmount" data-mfs-step="amount"><div id="mfsAmountSummary"></div><div id="mfsRateCard"><span id="mfsRateText"></span></div><label id="mfsAmountMyrField"><input id="mfsAmountMyr"></label><input id="mfsAmountBdt"><button id="mfsAmountContinue">Continue</button></div>
        <div id="mfsStepPin" data-mfs-step="pin"><div id="mfsPinSummary"></div><input id="mfsPin"><button id="mfsPinContinue">Continue</button></div>
        <div id="mfsStepPreview" data-mfs-step="preview"><div id="mfsPreviewRows"></div><input id="mfsReference"><button id="mfsHoldConfirm" disabled aria-disabled="true"><span class="mfs-hold-label">Hold</span></button></div>
      </div>
    </section>
    <div id="mfsActionModal" aria-hidden="true" inert><div data-mfs-modal-close></div><div><button id="mfsModalClose"></button><div id="mfsModalIcon"></div><h2 id="mfsModalTitle"></h2><p id="mfsModalMessage"></p><div id="mfsModalBody"></div><div id="mfsModalActions"></div></div></div>
    <script>
      window.USER_MFS_CONFIG={provider:${JSON.stringify(provider)}};
      window.__mfsTest={outcome:${JSON.stringify(outcome)},calls:[],payloads:[]};
      const ready=Promise.resolve();
      window.UserShell={
        ready,
        state:{user:{uid:'MFS-RUNTIME-USER'},bootstrapData:{user:{uid:'MFS-RUNTIME-USER'}}},
        holdPageLoad:()=>()=>{},
        isSessionError:()=>false,
        get:async()=>({wallet:{display_currency:'MYR',wallet_currency:'MYR',display_available_balance:3018.26,display_hold_balance:0,rate_myr_bdt:31.10}})
      };
      window.proxyPost=async(action,payload)=>{
        window.__mfsTest.calls.push(action);
        window.__mfsTest.payloads.push({action,payload});
        if(action==='mfs_preview') return {
          preview_token:'preview_token_runtime_1234567890',provider:${JSON.stringify(provider)},provider_name:${JSON.stringify(label)},
          service_type:'SEND_MONEY',service_mode:'REMITTANCE',account_type:'PERSONAL',receiver_number:payload.receiver_number,
          wallet_currency:'MYR',amount_bdt:1000,amount_rm:32.15,amount_myr:32.15,exchange_rate:31.10,
          fee_currency:'MYR',fee_amount:5,fee_rm:5,total_debit:37.15,total_pay:37.15,total_debit_text:'RM 37.15',
          balance_after_debit:2981.11,balance_after_debit_text:'RM 2981.11',can_submit:true
        };
        if(action==='validate_pin'){
          if(window.__mfsTest.outcome==='INVALID_PIN') throw Object.assign(new Error('Invalid transaction PIN'),{code:'INVALID_PIN'});
          return {verified:true};
        }
        if(action==='mfs_create'){
          if(window.__mfsTest.outcome==='success') {
            await new Promise((resolve)=>setTimeout(resolve,250));
            return {
            request_id:'MFS-RUNTIME-1',provider:${JSON.stringify(provider)},provider_name:${JSON.stringify(label)},status:'PENDING',
            receiver_number:payload.receiver_number,wallet_currency:'MYR',service_mode:'REMITTANCE',amount_bdt:1000,
            amount_rm:32.15,exchange_rate:31.10,fee_currency:'MYR',fee_amount:5,total_debit:37.15,total_debit_text:'RM 37.15',
            receipt_token:'abcdefghijklmnopqrstuvwx'
            };
          }
          const code=window.__mfsTest.outcome;
          const messages={SERVER_ERROR:'Request could not be saved.',MFS_DAILY_AMOUNT_TOO_CLOSE:'Use a different amount.',MFS_CREATE_STATUS_UNKNOWN:'Request status could not be confirmed.',MFS_PREVIEW_EXPIRED:'This preview has expired.',MFS_PREVIEW_MISMATCH:'Preview mismatch.',INSUFFICIENT_BALANCE:'Insufficient available balance.'};
          const error=Object.assign(new Error(messages[code]||'Request failed.'),{code,data:code==='MFS_DAILY_AMOUNT_TOO_CLOSE'?{minimum_difference_bdt:50}:{}});
          throw error;
        }
        throw new Error('Unexpected action '+action);
      };
    </script>
    <script src="/mfs-page.js"></script>
  </body></html>`;
}

async function runFlow(browser, origin, provider, outcome) {
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
    serviceWorkers: 'block',
    userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/140.0 Mobile Safari/537.36'
  });
  const page = await context.newPage();
  await page.goto(`${origin}/?provider=${provider}&outcome=${outcome}`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.getElementById('mfsSection')?.getAttribute('aria-busy') === 'false');

  await page.locator('#mfsReceiverNumber').fill('01712345678');
  await page.locator('#mfsReceiverContinue').click();
  await page.locator('#mfsAmountBdt').fill('1000');
  await page.locator('#mfsAmountContinue').click();
  await page.locator('#mfsPin').fill('1234');
  await page.locator('#mfsPinContinue').click();
  await page.waitForFunction(() => document.getElementById('mfsStepPreview')?.classList.contains('active'));
  await page.locator('#mfsReference').fill('Family support');

  const hold = page.locator('#mfsHoldConfirm');
  assert.equal(await hold.isDisabled(), false, `${provider}/${outcome}: verified Preview hold is disabled.`);
  await hold.focus();
  await page.keyboard.down('Enter');
  await page.waitForTimeout(2400);
  await page.keyboard.up('Enter');
  await page.waitForFunction(() => window.__mfsTest.calls.filter((action) => action === 'mfs_create').length === 1);
  if (outcome === 'success') await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.getElementById('mfsActionModal')?.classList.contains('is-success') || document.getElementById('mfsActionModal')?.classList.contains('is-error'));

  const result = await page.evaluate(() => ({
    calls: window.__mfsTest.calls,
    createPayload: window.__mfsTest.payloads.find((entry) => entry.action === 'mfs_create')?.payload || {},
    activeStep: document.querySelector('[data-mfs-step].active')?.getAttribute('data-mfs-step') || '',
    pinValue: document.getElementById('mfsPin')?.value || '',
    holdDisabled: document.getElementById('mfsHoldConfirm')?.disabled,
    holdAriaDisabled: document.getElementById('mfsHoldConfirm')?.getAttribute('aria-disabled'),
    modalTitle: document.getElementById('mfsModalTitle')?.textContent || '',
    modalMessage: document.getElementById('mfsModalMessage')?.textContent || ''
  }));

  assert.equal(result.calls.filter((action) => action === 'mfs_create').length, 1, `${provider}/${outcome}: create ran more than once.`);
  assert.equal(result.createPayload.provider, provider, `${provider}/${outcome}: selected provider changed.`);
  assert.equal(result.createPayload.preview_token, 'preview_token_runtime_1234567890', `${provider}/${outcome}: authoritative preview token changed.`);
  assert.equal(result.createPayload.amount_bdt, 1000, `${provider}/${outcome}: BDT service amount changed.`);
  assert.equal(result.createPayload.reference, 'Family support', `${provider}/${outcome}: confirmation reference was lost.`);
  assert.equal(result.pinValue, '', `${provider}/${outcome}: PIN remained in memory input after submit.`);
  assert.equal(result.holdDisabled, true, `${provider}/${outcome}: empty-PIN hold stayed enabled.`);
  assert.equal(result.holdAriaDisabled, 'true', `${provider}/${outcome}: empty-PIN hold accessibility state is wrong.`);

  if (outcome === 'success') {
    assert.equal(result.activeStep, 'preview', `${provider}: success left the review step unexpectedly.`);
    assert.equal(result.modalTitle, `${provider === 'NAGAD' ? 'Nagad' : 'bKash'} Request Submitted`, `${provider}: success modal missing.`);
  } else if (outcome === 'SERVER_ERROR') {
    assert.equal(result.activeStep, 'pin', `${provider}: reusable canonical failure did not return to PIN.`);
    assert.match(result.modalTitle, /Request Failed$/, `${provider}: canonical error title missing.`);
  } else {
    assert.equal(result.activeStep, 'amount', `${provider}/${outcome}: review-required failure did not return to Amount.`);
    assert.match(result.modalTitle, /Request Not Submitted$/, `${provider}/${outcome}: safe recovery title missing.`);
  }

  await context.close();
}

async function runWrongPin(browser, origin) {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, serviceWorkers: 'block' });
  const page = await context.newPage();
  await page.goto(`${origin}/?provider=BKASH&outcome=INVALID_PIN`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => document.getElementById('mfsSection')?.getAttribute('aria-busy') === 'false');
  await page.locator('#mfsReceiverNumber').fill('01712345678');
  await page.locator('#mfsReceiverContinue').click();
  await page.locator('#mfsAmountBdt').fill('1000');
  await page.locator('#mfsAmountContinue').click();
  await page.locator('#mfsPin').fill('9999');
  await page.locator('#mfsPinContinue').click();
  await page.waitForFunction(() => document.getElementById('mfsActionModal')?.classList.contains('is-error'));
  const state = await page.evaluate(() => ({
    activeStep: document.querySelector('[data-mfs-step].active')?.getAttribute('data-mfs-step') || '',
    pinValue: document.getElementById('mfsPin')?.value || '',
    createCalls: window.__mfsTest.calls.filter((action) => action === 'mfs_create').length,
    title: document.getElementById('mfsModalTitle')?.textContent || ''
  }));
  assert.equal(state.activeStep, 'pin', 'Wrong PIN left the verification step.');
  assert.equal(state.pinValue, '', 'Wrong PIN remained in the input.');
  assert.equal(state.createCalls, 0, 'Wrong PIN reached MFS create.');
  assert.equal(state.title, 'Incorrect PIN', 'Wrong PIN did not receive its canonical title.');
  await context.close();
}

async function main() {
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    if (url.pathname === '/mfs-page.js') {
      response.writeHead(200, { 'Content-Type': 'application/javascript', 'Cache-Control': 'no-store' });
      fs.createReadStream(mfsScript).pipe(response);
      return;
    }
    if (url.pathname === '/') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(pageMarkup(url.searchParams.get('provider') === 'NAGAD' ? 'NAGAD' : 'BKASH', url.searchParams.get('outcome') || 'success'));
      return;
    }
    response.writeHead(404).end();
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
  const origin = `http://127.0.0.1:${server.address().port}`;

  try {
    await runFlow(browser, origin, 'BKASH', 'success');
    await runFlow(browser, origin, 'NAGAD', 'success');
    await runFlow(browser, origin, 'BKASH', 'SERVER_ERROR');
    await runFlow(browser, origin, 'NAGAD', 'MFS_DAILY_AMOUNT_TOO_CLOSE');
    await runFlow(browser, origin, 'BKASH', 'MFS_CREATE_STATUS_UNKNOWN');
    await runFlow(browser, origin, 'NAGAD', 'MFS_PREVIEW_EXPIRED');
    await runFlow(browser, origin, 'BKASH', 'MFS_PREVIEW_MISMATCH');
    await runFlow(browser, origin, 'NAGAD', 'INSUFFICIENT_BALANCE');
    await runWrongPin(browser, origin);
    console.log('User MFS submit recovery browser tests passed (9 Android-like flows).');
  } finally {
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
