'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const loginScript = path.join(root, 'api', 'user', 'assets', 'pages', 'login-page.js');

function pageMarkup() {
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
  <body class="user-login-page user-login-checking"><main id="loginPageRoot">
    <section id="loginMaintenanceView" hidden><button id="retryLoginMaintenance">Retry</button></section>
    <section id="loginCard"><button id="loginStepBack" hidden>Back</button>
      <h1 id="loginStepTitle">Login</h1><p id="loginStepSubtitle"></p>
      <div data-login-step="phone"><strong id="loginCountryDisplay">Country: Detecting...</strong><input id="loginPhoneCountry" type="hidden"><input id="loginPhone"><button id="loginPhoneContinue" disabled>Continue</button></div>
      <div data-login-step="password" hidden><input id="loginPassword"><button id="loginPasswordContinue">Continue</button></div>
      <div data-login-step="pin" hidden><input id="loginPin"><button id="loginPinContinue">Continue</button><button id="loginUseAnotherAccount" hidden>Use another account</button></div>
      <div data-login-step="otp" hidden><strong id="loginOtpMaskedPhone">-</strong><small id="loginOtpExpiresText">05:00</small><input id="loginOtpCode"><p id="loginOtpStatus"></p><button id="verifyLoginOtpBtn">Verify OTP</button><button id="resendLoginOtpBtn" disabled>Resend OTP</button></div>
    </section>
  </main>
  <div id="loginFeedbackModal"><span id="loginFeedbackIcon"></span><h2 id="loginFeedbackTitle"></h2><p id="loginFeedbackMessage"></p><button id="loginFeedbackOk">OK</button></div>
  <div id="loginLoadingModal"><span id="loginLoadingText"></span></div>
  <script>window.USER_PROXY_URL='/api/user/proxy.php';</script><script src="/login-page.js"></script></body></html>`;
}

function json(response, status, ok, code, data = {}) {
  response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
  response.end(JSON.stringify({ ok, code, message: code, data }));
}

async function main() {
  const calls = [];
  let verifyAttempts = 0;
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    if (url.pathname === '/') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
      response.end(pageMarkup());
      return;
    }
    if (url.pathname === '/login-page.js') {
      response.writeHead(200, { 'Content-Type': 'application/javascript; charset=utf-8', 'Cache-Control': 'no-store' });
      fs.createReadStream(loginScript).pipe(response);
      return;
    }
    if (url.pathname === '/user/dashboard') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      response.end('<h1>Dashboard</h1>');
      return;
    }
    if (url.pathname !== '/api/user/proxy.php') {
      response.writeHead(404).end();
      return;
    }

    const action = url.searchParams.get('action') || '';
    calls.push(action);
    request.resume();
    if (action === 'maintenance_status') return json(response, 200, true, 'SUCCESS');
    if (action === 'login_trusted_account') return json(response, 200, true, 'SUCCESS', { trusted_login_available: false });
    if (action === 'country_defaults') return json(response, 200, true, 'SUCCESS', { phone_country: 'MY' });
    if (action === 'login_check_number') return json(response, 200, true, 'SUCCESS', { phone: '60123456789', phone_country: 'MY', name: 'TEST USER' });
    if (action === 'login_verify_password') return json(response, 200, true, 'SUCCESS', { pre_auth_token: 'PREAUTH', user: { name: 'TEST USER' } });
    if (action === 'login_verify_pin') return json(response, 200, true, 'PIN_VERIFIED', { pre_auth_token: 'PREAUTH', otp_required: true });
    if (action === 'login_send_otp') return json(response, 200, true, 'OTP_SENT', {
      pre_auth_token: 'PREAUTH', otp_request_id: 'OTP-1', masked_phone: '601*****789',
      expires_in_seconds: 300, resend_in_seconds: 1
    });
    if (action === 'login_resend_otp') return json(response, 200, true, 'SUCCESS', {
      pre_auth_token: 'PREAUTH', otp_request_id: 'OTP-2', masked_phone: '601*****789',
      expires_in_seconds: 300, resend_in_seconds: 1
    });
    if (action === 'login_verify_otp') {
      verifyAttempts += 1;
      if (verifyAttempts === 1) return json(response, 409, false, 'OTP_VERIFY_IN_PROGRESS');
      setTimeout(() => json(response, 200, true, 'SUCCESS', { login_complete: true, session_active: true }), 12000);
      return;
    }
    return json(response, 404, false, 'NOT_FOUND');
  });

  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    hasTouch: true,
    isMobile: true,
    serviceWorkers: 'block',
    userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/140.0 Mobile Safari/537.36'
  });
  const page = await context.newPage();

  try {
    await page.goto(`http://127.0.0.1:${server.address().port}/`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => !document.querySelector('#loginPhoneContinue').disabled);
    await page.fill('#loginPhone', '0123456789');
    await page.click('#loginPhoneContinue');
    await page.fill('#loginPassword', 'correct-password');
    await page.click('#loginPasswordContinue');
    await page.fill('#loginPin', '1234');
    await page.click('#loginPinContinue');
    await page.waitForFunction(() => !document.querySelector('[data-login-step="otp"]').hidden);

    assert.equal(await page.locator('#verifyLoginOtpBtn').isEnabled(), true, 'OTP verify should remain available during its validity window.');
    assert.equal(await page.locator('#resendLoginOtpBtn').isDisabled(), true, 'Resend should respect the independent cooldown.');
    await page.waitForFunction(() => !document.querySelector('#resendLoginOtpBtn').disabled, null, { timeout: 3000 });
    assert.notEqual(await page.locator('#loginOtpExpiresText').textContent(), 'Expired', 'Resend became available only after OTP expiry.');
    await page.click('#resendLoginOtpBtn');
    assert.equal(calls.filter((action) => action === 'login_resend_otp').length, 1, 'Resend submitted more than once.');

    await page.fill('#loginOtpCode', '123456');
    await page.click('#verifyLoginOtpBtn');
    await page.waitForFunction(() => document.querySelector('#loginFeedbackModal').classList.contains('show'));
    assert.equal(await page.inputValue('#loginOtpCode'), '123456', 'A retryable verification conflict erased the entered OTP.');
    await page.click('#loginFeedbackOk');
    const slowVerifyStartedAt = Date.now();
    await page.click('#verifyLoginOtpBtn');
    await page.waitForURL('**/user/dashboard');
    const slowVerifyDuration = Date.now() - slowVerifyStartedAt;

    assert.equal(verifyAttempts, 2, 'OTP retry did not submit exactly once per tap.');
    assert.ok(slowVerifyDuration >= 12000 && slowVerifyDuration < 50000, 'The browser did not survive a valid 12-second OTP verification.');
    assert.equal(calls.filter((action) => action === 'login_verify_pin').length, 1, 'PIN verification submitted more than once.');
    assert.equal(calls.filter((action) => action === 'login_send_otp').length, 1, 'OTP send submitted more than once.');
    console.log(`User login PIN/OTP browser tests passed (slow verify ${slowVerifyDuration} ms).`);
  } finally {
    await context.close();
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
