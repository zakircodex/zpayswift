'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const sharp = require('sharp');

const repo = path.resolve(__dirname, '..');
const php = process.env.PHP_EXECUTABLE || 'C:\\xampp\\php\\php.exe';
const screenshotDir = process.env.BIRTHDAY_SCREENSHOT_DIR || '';

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      server.close(() => resolve(address.port));
    });
  });
}

function waitForServer(url, timeout = 10000) {
  const started = Date.now();
  return new Promise((resolve, reject) => {
    const attempt = () => {
      const request = http.get(url, response => {
        response.resume();
        if (response.statusCode && response.statusCode < 500) return resolve();
        setTimeout(attempt, 100);
      });
      request.on('error', () => {
        if (Date.now() - started >= timeout) return reject(new Error('Birthday browser server did not start.'));
        setTimeout(attempt, 100);
      });
    };
    attempt();
  });
}

async function noOverflow(page, label) {
  const audit = await page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const offenders = Array.from(document.querySelectorAll('body *')).map(node => {
      const rect = node.getBoundingClientRect();
      return { tag: node.tagName.toLowerCase(), id: node.id, className: String(node.className || ''), left: Math.round(rect.left), right: Math.round(rect.right), width: Math.round(rect.width) };
    }).filter(item => item.left < -1 || item.right > width + 1).slice(0, 8);
    const active = document.activeElement;
    const activeRect = active?.getBoundingClientRect();
    return {
      overflow: document.documentElement.scrollWidth - width,
      bodyOverflow: document.body.scrollWidth - document.body.clientWidth,
      offenders,
      active: active ? { tag: active.tagName.toLowerCase(), id: active.id, className: String(active.className || ''), left: Math.round(activeRect.left), right: Math.round(activeRect.right), outline: getComputedStyle(active).outline } : null
    };
  });
  assert.ok(audit.overflow <= 1, `${label} overflow audit: ${JSON.stringify(audit)}`);
}

async function animatedCanvasAudit(page, label) {
  const canvas = page.locator('.universe-space-canvas');
  await canvas.waitFor({ state: 'visible' });
  await page.waitForTimeout(250);
  const first = await canvas.screenshot();
  const stats = await sharp(first).stats();
  const variation = Math.max(...stats.channels.slice(0, 3).map(channel => channel.stdev));
  assert.ok(variation > 3, `${label} canvas must contain visible scene pixels (stdev ${variation}).`);
  await page.waitForTimeout(450);
  const second = await canvas.screenshot();
  assert.notEqual(
    crypto.createHash('sha256').update(first).digest('hex'),
    crypto.createHash('sha256').update(second).digest('hex'),
    `${label} canvas must animate between frames.`
  );
}

async function run() {
  const port = await freePort();
  const origin = `http://127.0.0.1:${port}`;
  const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', repo, path.join(repo, 'tests', 'znews_birthday_browser_router.php')], {
    cwd: repo, stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true
  });
  let serverError = '';
  server.stderr.on('data', chunk => { serverError += String(chunk); });
  let browser;
  try {
    await waitForServer(`${origin}/birthday`);
    browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
    const page = await browser.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => {
      if (message.type() !== 'error') return;
      const location = message.location();
      const source = location.url ? ` (${location.url}${location.lineNumber ? `:${location.lineNumber}` : ''})` : '';
      errors.push(`${message.text()}${source}`);
    });
    page.on('response', response => {
      if (response.status() >= 400) errors.push(`HTTP ${response.status()} ${response.url()}`);
    });
    await page.goto(`${origin}/birthday`, { waitUntil: 'networkidle' });
    await page.locator('.birthday-hero h1').waitFor();
    assert.equal(await page.locator('.birthday-hero-visual').evaluate(node => getComputedStyle(node).backgroundImage.includes('birthday-universe-cosmic.webp')), true, 'Landing hero must use the generated cosmic artwork.');
    assert.equal(await page.locator('.birthday-hero-visual').evaluate(node => node.getBoundingClientRect().height > 300), true, 'Landing artwork must occupy a meaningful first-viewport area.');
    await noOverflow(page, '390px landing page');
    if (screenshotDir) {
      fs.mkdirSync(screenshotDir, { recursive: true });
      await page.screenshot({ path: path.join(screenshotDir, 'birthday-landing-390.png'), fullPage: true });
    }

    await page.locator('.birthday-hero .button.primary').click();
    await page.waitForURL('**/birthday/create');
    await page.locator('#birthdayName').fill('Mim');
    await page.locator('#nextStep').click();
    await page.locator('#birthdayDay').fill('24');
    await page.locator('#birthdayMonth').selectOption('7');
    await page.locator('#nextStep').click();
    await page.locator('#senderName').fill('Zakir');
    await page.locator('#nextStep').click();
    await page.locator('#birthdayMessage').fill('Happy birthday! Keep shining.');
    await page.locator('#nextStep').click();
    const phoneWidth = 2800;
    const phoneHeight = 2100;
    const phonePhoto = await sharp(crypto.randomBytes(phoneWidth * phoneHeight * 3), {
      raw: { width: phoneWidth, height: phoneHeight, channels: 3 }
    }).jpeg({ quality: 68, mozjpeg: true }).toBuffer();
    assert.ok(phonePhoto.length > 2 * 1024 * 1024 && phonePhoto.length < 5 * 1024 * 1024, 'The browser fixture must exercise a camera-sized photo above the host upload limit.');
    await page.locator('#birthdayPhoto').setInputFiles({
      name: 'large-phone-photo.jpg',
      mimeType: 'image/jpeg',
      buffer: phonePhoto
    });
    await page.locator('#photoPreview:not([hidden])').waitFor();
    assert.equal(await page.locator('#photoPreview').evaluate(node => getComputedStyle(node).objectFit), 'contain', 'Selected photo preview must never crop the original image.');
    await page.locator('#nextStep').click();
    await page.locator('[data-template-id="cosmic"]').click();
    await page.locator('#nextStep').click();
    await page.locator('[data-audio-mode="CUSTOM"]').click();
    assert.equal(await page.locator('#customAudioPanel').isVisible(), true, 'Custom audio selection must reveal the protected 30-second upload controls.');
    if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, 'birthday-audio-390.png') });
    await page.locator('[data-audio-mode="AMBIENT"]').click();
    await page.locator('#consentConfirmed').check();
    const photoUploadResponsePromise = page.waitForResponse(response => response.url().includes('/api/znews/birthday/media_upload.php') && response.request().method() === 'POST');
    await page.locator('#nextStep').click();
    const photoUploadResponse = await photoUploadResponsePromise;
    const browserUploadSize = Number(await photoUploadResponse.headerValue('x-birthday-test-upload-size') || 0);
    assert.ok(browserUploadSize > 0 && browserUploadSize <= 100 * 1024, `Camera photo must upload at or below 100 KB, received ${browserUploadSize} bytes.`);
    await page.waitForURL('**/birthday/preview/ZBD_BROWSER_001');
    await page.locator('#previewUniverse .birthday-universe').waitFor();
    assert.equal(await page.locator('#generateUniverse').isEnabled(), true, 'Generate must become available only after the private preview is ready.');
    assert.equal(await page.locator('#generationPanel').evaluate((panel, preview) => panel.getBoundingClientRect().top < document.querySelector(preview).getBoundingClientRect().top, '#previewUniverse'), true, 'Generate controls must appear before the long preview and its advertisement.');
    if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, 'birthday-entry-390.png') });
    await page.locator('#previewUniverse .universe-enter-button').click();
    assert.match(await page.locator('#previewUniverse').innerText(), /MIM-247-PREVIEW/);
    assert.ok(await page.locator('#previewUniverse .twinkle-star').count() >= 100, 'The full Universe must include a dense CSS twinkle field independent of WebGL support.');
    assert.equal(await page.locator('#previewUniverse .moon-twinkle').count(), 24, 'The moon must have its own surrounding sparkle field.');
    assert.match(await page.locator('#previewUniverse .twinkle-star').first().evaluate(node => getComputedStyle(node).animationName), /birthday-twinkle/);
    assert.equal(await page.locator('#previewUniverse .photo-frame img').evaluate(node => getComputedStyle(node).objectFit), 'contain', 'Preview photo must preserve its original aspect ratio.');
    await page.locator('#birthdayPreviewAd iframe').waitFor();
    assert.ok(await page.locator('#birthdayPreviewAd iframe').evaluate(node => node.getBoundingClientRect().height <= 321), 'Preview ad height must be compact on mobile.');
    await animatedCanvasAudit(page, 'Preview Universe');
    await noOverflow(page, '390px preview page');

    await page.locator('#generateUniverse').click();
    await page.locator('#generationSuccess:not([hidden])').waitFor();
    assert.match(await page.locator('#generatedUrl').inputValue(), /\/u\/mim-247-x8k2browser$/);
    assert.ok((await page.locator('#recoveryCode').innerText()).replace(/-/g, '').length === 20, 'Generation must reveal a 20-character recovery code.');
    assert.equal(await page.locator('.skip-link').evaluate(node => node.matches(':focus-visible')), false, 'Mouse generation flow must not expose the keyboard skip link.');
    if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, 'birthday-generated-390.png'), fullPage: true });

    await page.locator('#openUniverse').click();
    await page.waitForURL('**/u/mim-247-x8k2browser');
    await page.locator('#publicUniverse .birthday-universe').waitFor();
    await page.locator('#qrCode img').waitFor();
    await page.locator('#birthdayPublicAd iframe').waitFor();
    assert.ok(await page.locator('#birthdayPublicAd iframe').evaluate(node => node.getBoundingClientRect().height <= 321), 'Public ad height must be compact on mobile.');
    await page.locator('#publicUniverse .universe-enter-button').click();
    assert.match(await page.locator('#publicUniverse').innerText(), /MIM-247-X8K2/);
    assert.equal(await page.locator('#qrCode img').getAttribute('src').then(value => String(value).startsWith('data:image/png;base64,')), true, 'QR code must be generated locally as a PNG from the public URL.');
    await animatedCanvasAudit(page, 'Public Universe');
    assert.equal(await page.locator('.public-share-panel').evaluate((panel, ad) => panel.offsetTop < document.querySelector(ad).offsetTop, '#birthdayPublicAd'), true, 'Sharing and QR controls must appear before the advertisement.');
    await page.locator('.public-share-panel').scrollIntoViewIfNeeded();
    assert.equal(await page.locator('#nativeShare').isVisible(), true, 'Public Share action must remain visible below the Universe.');
    assert.equal(await page.locator('#copyPublicLink').isVisible(), true, 'Copy Link action must remain visible below the Universe.');
    assert.equal(await page.locator('#downloadQr').isVisible(), true, 'QR download action must remain visible below the Universe.');
    assert.ok(await page.locator('.public-share-panel').evaluate(node => node.getBoundingClientRect().height < 650), 'Mobile sharing and QR controls must use a compact layout.');
    assert.equal(await page.locator('.universe-space-canvas').evaluate(node => getComputedStyle(node).position), 'absolute', 'The animated canvas must stay bounded to the Universe on Android.');
    await noOverflow(page, '390px public Universe before message reveal');
    assert.equal(await page.locator('#publicUniverse .photo-frame img').evaluate(node => getComputedStyle(node).objectFit), 'contain', 'Published photo must remain uncropped.');
    await page.locator('.moon-section').scrollIntoViewIfNeeded();
    await page.waitForTimeout(350);
    assert.equal(await page.locator('.moon-orbit-anchor').evaluate(node => {
      const rect = node.getBoundingClientRect();
      return rect.width >= 200 && rect.height >= 200 && rect.top < innerHeight && rect.bottom > 0;
    }), true, 'The rotating moon must have a stable visible viewport anchor.');
    if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, 'birthday-moon-390.png') });
    await page.locator('.message-toggle').click();
    assert.equal(await page.locator('.birthday-message').isVisible(), true, 'Birthday message must reveal on demand.');
    await noOverflow(page, '390px public Universe');
    if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, 'birthday-public-390.png'), fullPage: true });

    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(`${origin}/birthday`, { waitUntil: 'networkidle' });
    await noOverflow(page, 'desktop landing page');
    if (screenshotDir) await page.screenshot({ path: path.join(screenshotDir, 'birthday-landing-1366.png'), fullPage: true });
    await page.locator('[data-locale="bn"]').click();
    assert.match(await page.locator('.birthday-hero h1').innerText(), /Birthday Universe তৈরি করুন/);
    await page.goto(`${origin}/birthday/privacy`, { waitUntil: 'networkidle' });
    assert.match(await page.locator('.legal-shell h1').innerText(), /গোপনীয়তা/);

    await page.setViewportSize({ width: 320, height: 760 });
    await page.goto(`${origin}/u/mim-247-x8k2browser`, { waitUntil: 'networkidle' });
    await page.locator('#publicUniverse .birthday-universe').waitFor();
    await page.locator('#publicUniverse .universe-enter-button').click();
    await noOverflow(page, '320px public Universe');
    assert.deepEqual(errors, [], `Browser console errors: ${errors.join(' | ')}`);
    console.log('Z Sky 24 Birthday Universe browser flow passed.');
  } finally {
    if (browser) await browser.close();
    server.kill();
    if (server.exitCode && server.exitCode !== 0) throw new Error(`PHP browser server failed: ${serverError}`);
  }
}

run().catch(error => {
  console.error(error.stack || error.message || String(error));
  process.exitCode = 1;
});
