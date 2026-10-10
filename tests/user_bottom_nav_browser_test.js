'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const shellCss = fs.readFileSync(path.join(root, 'api', 'user', 'assets', 'user-shell.css'), 'utf8');

const pageModes = [
  { name: 'shared', bodyClass: 'user-dashboard-page', css: '' },
  {
    name: 'transfer',
    bodyClass: 'user-transfer-page',
    css: fs.readFileSync(path.join(root, 'api', 'user', 'assets', 'pages', 'transfer-page.css'), 'utf8')
  },
  {
    name: 'mfs',
    bodyClass: 'user-mfs-page',
    css: fs.readFileSync(path.join(root, 'api', 'user', 'assets', 'pages', 'mfs-page.css'), 'utf8')
  },
  {
    name: 'topup',
    bodyClass: 'user-topup-page',
    css: fs.readFileSync(path.join(root, 'api', 'user', 'assets', 'pages', 'topup-page.css'), 'utf8')
  }
];

const widths = [320, 390, 430];

function fixture(mode) {
  const buttons = ['Home', 'Add Money', 'Transfer', 'History', 'Profile']
    .map((label) => `<a class="bottom-btn"><span class="bottom-icon"></span><span>${label}</span></a>`)
    .join('');

  return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>${shellCss}\n${mode.css}</style></head>
    <body class="user-authenticated ${mode.bodyClass}">
      <div style="min-height:1400px;background:#d0228b"></div>
      <nav class="bottom-nav"><div class="bottom-nav-inner">${buttons}</div></nav>
    </body></html>`;
}

async function main() {
  assert.match(shellCss, /--z-nav-bottom-gap:\s*6px;/, 'Shared navigation bottom gap is not centralized.');
  assert.match(shellCss, /padding:\s*0 16px var\(--z-nav-bottom-gap\);/, 'Shared navigation still uses a device safe-area offset.');

  for (const mode of pageModes.filter((item) => item.name === 'transfer' || item.name === 'mfs')) {
    assert.match(mode.css, /padding:\s*0 (?:12px|0) var\(--z-nav-bottom-gap\);/, `${mode.name} navigation still uses a device safe-area offset.`);
  }

  const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
  try {
    for (const width of widths) {
      for (const mode of pageModes) {
        const context = await browser.newContext({
          viewport: { width, height: 844 },
          hasTouch: true,
          isMobile: true,
          serviceWorkers: 'block',
          userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/140.0 Mobile Safari/537.36'
        });
        const page = await context.newPage();
        await page.setContent(fixture(mode), { waitUntil: 'domcontentloaded' });

        const layout = await page.evaluate(() => {
          const nav = document.querySelector('.bottom-nav');
          const inner = document.querySelector('.bottom-nav-inner');
          const navRect = nav.getBoundingClientRect();
          const innerRect = inner.getBoundingClientRect();
          const style = getComputedStyle(nav);
          return {
            viewportHeight: window.innerHeight,
            navBottom: navRect.bottom,
            innerBottom: innerRect.bottom,
            paddingBottom: parseFloat(style.paddingBottom),
            backgroundColor: style.backgroundColor
          };
        });

        const outerGap = Math.round(layout.viewportHeight - layout.navBottom);
        const innerGap = Math.round(layout.viewportHeight - layout.innerBottom);
        assert.ok(outerGap >= 0 && outerGap <= 1, `${mode.name} ${width}px navigation is not fixed to the viewport bottom.`);
        assert.ok(innerGap >= 5 && innerGap <= 7, `${mode.name} ${width}px navigation is raised by ${innerGap}px.`);
        assert.equal(layout.paddingBottom, 6, `${mode.name} ${width}px navigation has an unexpected bottom gap.`);
        assert.equal(layout.backgroundColor, 'rgb(4, 16, 31)', `${mode.name} ${width}px content can show below the navigation.`);
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }

  console.log(`User bottom navigation browser tests passed (${pageModes.length} page modes x ${widths.length} Android viewports).`);
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
