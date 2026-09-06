'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const playwright = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '..');
let frameRequests = 0;

function testPage() {
  return `<!doctype html><html><body>
    <article id="reader">Reader content remains visible.</article>
    <div id="slot" class="ad-slot" data-znews-ad-slot="post_reader" hidden></div>
    <div id="native-slot" class="ad-slot" data-znews-ad-slot="post_reader" hidden></div>
    <script>window.ZNEWS_AUTH_VERIFIED=false;</script>
    <script src="/znews/assets/znews-ads.js"></script>
  </body></html>`;
}

async function main() {
  const frameServer = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    if (url.pathname === '/api/znews/public/ad_frame.php') {
      frameRequests += 1;
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      const channel = JSON.stringify(url.searchParams.get('channel') || '');
      response.end(`<!doctype html><html><body>test ad<script>
        let cookieReadable = true;
        try { void document.cookie; } catch (_error) { cookieReadable = false; }
        if (${channel}) parent.postMessage({
          type:'znews:adsterra-native-size',
          channel:${channel},
          height:cookieReadable ? 412 : 91
        }, '*');
      </script></body></html>`);
      return;
    }
    response.writeHead(404);
    response.end();
  });
  await new Promise((resolve) => frameServer.listen(0, '127.0.0.1', resolve));
  const frameOrigin = `http://127.0.0.1:${frameServer.address().port}`;

  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1');
    if (url.pathname === '/') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      response.end(testPage());
      return;
    }
    if (url.pathname === '/znews/assets/znews-ads.js') {
      response.writeHead(200, { 'Content-Type': 'application/javascript; charset=utf-8' });
      fs.createReadStream(path.join(root, 'znews', 'assets', 'znews-ads.js')).pipe(response);
      return;
    }
    response.writeHead(404);
    response.end();
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;

  const launchOptions = { headless: true };
  if (process.env.PLAYWRIGHT_CHANNEL) launchOptions.channel = process.env.PLAYWRIGHT_CHANNEL;
  const browser = await playwright.chromium.launch(launchOptions);
  const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const page = await context.newPage();
  await page.goto(origin, { waitUntil: 'domcontentloaded' });

  const delivery = {
    enabled: true,
    provider: 'ADSTERRA',
    slot: 'post_reader',
    width: 300,
    height: 250,
    frame_url: `${frameOrigin}/api/znews/public/ad_frame.php?permit=SIGNED_TEST_PERMIT`
  };
  const mounted = await page.evaluate((value) => window.ZNewsAds.mount(document.querySelector('#slot'), value), delivery);
  assert.equal(mounted, true, 'Eligible guest delivery did not mount.');
  await page.waitForSelector('#slot iframe');
  const frame = await page.locator('#slot iframe').evaluate((element) => ({
    src: element.src,
    title: element.title,
    sandbox: element.getAttribute('sandbox'),
    loading: element.loading,
    credentialless: element.hasAttribute('credentialless')
  }));
  assert.equal(frame.src, delivery.frame_url, 'Frame URL changed.');
  assert.equal(frame.title, 'Advertisement', 'Ad frame has no accessible title.');
  assert.equal(frame.loading, 'lazy', 'Ad frame is not lazy.');
  assert.equal(frame.credentialless, true, 'Ad frame can receive first-party credentials.');
  assert.match(frame.sandbox, /allow-top-navigation-by-user-activation/, 'Ad clicks lack user-activation protection.');
  assert.match(frame.sandbox, /allow-same-origin/, 'Cross-origin ad frame cannot use the provider runtime.');
  assert.notEqual(new URL(frame.src).origin, origin, 'Ad frame is not isolated on a cross-origin host.');
  assert.equal(await page.locator('#reader').textContent(), 'Reader content remains visible.', 'Ad mount replaced reader content.');

  await page.evaluate((value) => window.ZNewsAds.mount(document.querySelector('#slot'), value), delivery);
  assert.equal(await page.locator('#slot iframe').count(), 1, 'Duplicate delivery mounted multiple frames.');

  const nativeDelivery = {
    enabled: true,
    provider: 'ADSTERRA',
    slot: 'post_reader',
    creative_format: 'native_banner',
    width: 0,
    height: 300,
    resize_channel: '0123456789abcdef01234567',
    frame_url: `${frameOrigin}/api/znews/public/ad_frame.php?permit=NATIVE_TEST_PERMIT&channel=0123456789abcdef01234567`
  };
  const nativeMounted = await page.evaluate((value) => window.ZNewsAds.mount(document.querySelector('#native-slot'), value), nativeDelivery);
  assert.equal(nativeMounted, true, 'Eligible Native Banner delivery did not mount.');
  await page.waitForFunction(() => document.querySelector('#native-slot iframe')?.height === '412');
  const nativeFrame = await page.locator('#native-slot iframe').evaluate((element) => ({
    width: element.style.width,
    height: element.height,
    sandbox: element.getAttribute('sandbox')
  }));
  assert.equal(nativeFrame.width, '100%', 'Native Banner frame is not responsive.');
  assert.equal(nativeFrame.height, '412', 'Native Banner frame did not apply its bounded content height.');
  assert.match(nativeFrame.sandbox, /allow-same-origin/, 'Native Banner provider runtime cannot read its isolated document state.');

  await page.evaluate((value) => window.ZNewsAds.mount(document.querySelector('#native-slot'), value), nativeDelivery);
  assert.equal(await page.locator('#native-slot iframe').count(), 1, 'Duplicate Native delivery mounted multiple frames.');

  await page.evaluate((value) => {
    window.ZNEWS_AUTH_VERIFIED = true;
    window.ZNewsAds.mount(document.querySelector('#slot'), {
      enabled: true, provider: 'ADSTERRA', slot: 'post_reader', width: 300, height: 250,
      frame_url: value
    });
  }, `${frameOrigin}/api/znews/public/ad_frame.php?permit=CREATOR_PERMIT`);
  assert.equal(await page.locator('#slot iframe').count(), 0, 'Authenticated creator retained an ad frame.');
  assert.equal(await page.locator('#slot').getAttribute('aria-hidden'), 'true', 'Rejected slot is not hidden accessibly.');

  const invalid = await page.evaluate(() => {
    window.ZNEWS_AUTH_VERIFIED = false;
    return window.ZNewsAds.mount(document.querySelector('#slot'), {
      enabled: true, provider: 'ADSTERRA', slot: 'post_reader', width: 300, height: 250,
      frame_url: 'https://attacker.example/ad_frame.php?permit=BAD'
    });
  });
  assert.equal(invalid, false, 'Cross-origin frame URL was accepted.');

  const androidContext = await browser.newContext({
    viewport: { width: 390, height: 844 },
    userAgent: 'ZPaySwift-Android-ZNews/1.0'
  });
  const androidPage = await androidContext.newPage();
  await androidPage.goto(origin, { waitUntil: 'domcontentloaded' });
  const androidMounted = await androidPage.evaluate((value) => window.ZNewsAds.mount(document.querySelector('#slot'), value), delivery);
  assert.equal(androidMounted, false, 'Android WebView mounted a Web ad.');

  assert.ok(frameRequests >= 2, 'Guest Banner and Native frames were not both requested.');
  await androidContext.close();
  await context.close();
  await browser.close();
  await new Promise((resolve) => server.close(resolve));
  await new Promise((resolve) => frameServer.close(resolve));
  console.log('PASS: Z Sky Adsterra Web browser assertions.');
}

main().catch((error) => {
  console.error(error?.stack || String(error));
  process.exitCode = 1;
});
