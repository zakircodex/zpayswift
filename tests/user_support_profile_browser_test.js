'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const shellCss = fs.readFileSync(path.join(root, 'api/user/assets/user-shell.css'), 'utf8');
const componentCss = fs.readFileSync(path.join(root, 'api/user/assets/user-components.css'), 'utf8');
const supportCss = fs.readFileSync(path.join(root, 'api/user/assets/pages/support-page.css'), 'utf8');
const profileCss = fs.readFileSync(path.join(root, 'api/user/assets/pages/profile-page.css'), 'utf8');
const supportJs = path.join(root, 'api/user/assets/pages/support-page.js');
const profileJs = path.join(root, 'api/user/assets/pages/profile-page.js');
const brandIcon = path.join(root, 'assets/brand/zpay-icon.png');

function pageSection(file, id) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  const start = source.indexOf(`<section id="${id}"`);
  const end = source.lastIndexOf('</section>');
  assert.ok(start >= 0 && end > start, `Could not extract ${id}`);
  return source.slice(start, end + '</section>'.length);
}

function bottomNav() {
  return `<nav class="bottom-nav" aria-label="Primary navigation"><div class="bottom-nav-inner">
    ${['Home', 'Add Money', 'Transfer', 'History', 'Profile'].map((label) => `<a class="bottom-btn" href="#"><span class="bottom-icon"></span><span>${label}</span></a>`).join('')}
  </div></nav>`;
}

function fixture(bodyClass, css, section) {
  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>${shellCss}\n${componentCss}\n${css}</style></head>
    <body class="user-authenticated ${bodyClass}"><div id="appView"><div class="app-shell"><main class="main-panel user-page-panel">
      <div class="user-page-content user-page-content-custom-header">${section}</div>
    </main></div></div>${bottomNav()}</body></html>`;
}

async function supportAudit(browser, width, height) {
  const context = await browser.newContext({ viewport: { width, height }, hasTouch: true, isMobile: true, serviceWorkers: 'block' });
  const page = await context.newPage();
  const html = fixture('user-support-page', supportCss, pageSection('api/user/support.php', 'supportSection'));
  await page.route('http://local.test/user/support', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: html }));
  await page.goto('http://local.test/user/support');
  await page.evaluate(() => {
    window.userState = { csrf: 'LOCAL-CSRF' };
    window.USER_PROXY_URL = '/api/user/proxy.php';
    window.UserShell = { ready: Promise.resolve(), holdPageLoad: () => () => {} };
    window.showToast = () => {};
    window.proxyGet = async (action) => {
      if (action === 'support_config') {
        return {
          config: {
            contact_us_enabled: true,
            ticket_enabled: true,
            whatsapp_enabled: true,
            whatsapp_number: '+60123456789',
            email_enabled: true,
            support_email: 'support@example.invalid',
            call_enabled: false,
            support_hours: 'Every day, 10:00 AM - 10:00 PM',
            average_response_text: 'Average response time: within 24 hours.'
          },
          categories: []
        };
      }
      if (action === 'support_list') return { tickets: [] };
      if (action === 'request_logs') return { items: [] };
      return {};
    };
  });
  await page.addScriptTag({ path: supportJs });
  await page.getByText('No conversations yet.', { exact: true }).waitFor({ state: 'visible' });
  const layout = await page.evaluate(() => {
    const rect = (selector) => {
      const box = document.querySelector(selector).getBoundingClientRect();
      return { top: box.top, right: box.right, bottom: box.bottom, left: box.left, width: box.width, height: box.height };
    };
    const options = document.getElementById('supportContactOptions');
    const visibleLinks = Array.from(options.querySelectorAll('a')).filter((link) => !link.hidden);
    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      header: rect('.support-page-header'),
      hero: rect('.support-live-hero'),
      options: rect('#supportContactOptions'),
      optionWidths: visibleLinks.map((link) => link.getBoundingClientRect().width),
      optionCount: visibleLinks.length,
      dataCount: options.dataset.count,
      empty: rect('.support-empty-state'),
      nav: rect('.bottom-nav-inner')
    };
  });
  assert.ok(layout.scrollWidth <= layout.clientWidth, `${width}x${height}: Contact Us overflows horizontally`);
  const expectedHeaderHeight = height > 720 ? 82 : 58;
  assert.ok(layout.header.height >= expectedHeaderHeight, `${width}x${height}: Contact Us header is smaller than the shared page header`);
  assert.ok(layout.header.bottom < layout.hero.top, `${width}x${height}: header overlaps the support hero`);
  assert.equal(layout.optionCount, 2, `${width}x${height}: configured contact channels are missing`);
  assert.equal(layout.dataCount, '2', `${width}x${height}: contact grid did not adapt to two channels`);
  assert.ok(Math.abs(layout.optionWidths[0] - layout.optionWidths[1]) < 2, `${width}x${height}: contact buttons are not balanced`);
  assert.ok(layout.empty.top < layout.nav.top, `${width}x${height}: conversation state starts behind the bottom navigation`);
  if (width === 390 && height === 844) {
    await page.screenshot({ path: path.join(os.tmpdir(), 'zpay-support-ui.png'), fullPage: false });
  }
  await context.close();
}

async function profileAudit(browser) {
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, serviceWorkers: 'block' });
  const page = await context.newPage();
  const html = fixture('user-profile-page', profileCss, pageSection('api/user/profile.php', 'profileSection'));
  await page.route('http://local.test/assets/brand/zpay-icon.png', (route) => route.fulfill({ status: 200, contentType: 'image/png', path: brandIcon }));
  await page.route('http://local.test/user/profile', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: html }));
  await page.goto('http://local.test/user/profile');
  await page.evaluate(() => {
    window.userState = { csrf: 'LOCAL-CSRF', me: {} };
    window.USER_PROXY_URL = '/api/user/proxy.php';
    window.UserShell = { ready: Promise.resolve(), holdPageLoad: () => () => {} };
    window.showToast = () => {};
    window.setBusy = () => {};
    window.renderUserDrawerProfile = () => {};
    window.proxyGet = async (action) => action === 'profile_get' ? {
      uid: 'U-LOCAL', name: 'LOCAL TEST USER', phone: '60123456789', email: 'test@example.invalid',
      role: 'USER', status: 'ACTIVE', pricing_country: 'MY', wallet_currency: 'MYR'
    } : {};
  });
  await page.addScriptTag({ path: profileJs });
  await page.getByText('LOCAL TEST USER', { exact: true }).waitFor({ state: 'visible' });
  await page.locator('#profileAvatarImage').waitFor({ state: 'visible' });
  const avatar = await page.locator('#profileAvatarImage').evaluate((image) => ({
    src: image.getAttribute('src'),
    naturalWidth: image.naturalWidth,
    fallback: image.dataset.fallback,
    initialsHidden: document.getElementById('profileAvatarInitials').classList.contains('hidden')
  }));
  assert.equal(avatar.src, '/assets/brand/zpay-icon.png', 'Profile did not use the shared brand fallback');
  assert.equal(avatar.fallback, 'brand', 'Profile brand fallback state is incorrect');
  assert.ok(avatar.naturalWidth > 0 && avatar.initialsHidden, 'Profile brand fallback is not visibly rendered');
  await page.screenshot({ path: path.join(os.tmpdir(), 'zpay-profile-avatar-ui.png'), fullPage: false });
  await context.close();
}

(async () => {
  const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
  try {
    for (const [width, height] of [[320, 640], [360, 720], [390, 844], [430, 900]]) {
      await supportAudit(browser, width, height);
    }
    await profileAudit(browser);
    console.log('User Support/Profile browser tests passed (4 Contact Us viewports + profile brand fallback).');
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
