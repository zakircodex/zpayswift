'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');
const asset = (relative) => path.join(root, relative);

async function mount(page) {
  const markup = `<!doctype html><html><body class="admin-premium-body">
    <main><section class="section active" id="zsky24Section" aria-labelledby="zsky24AdminTitle"></section></main>
    <div class="drawer" id="drawer" aria-hidden="true" inert>
      <div class="drawer-head"><h3 id="drawerTitle"></h3><p id="drawerSub"></p></div>
      <div class="drawer-body" id="drawerBody"></div><div class="drawer-foot" id="drawerFoot"></div>
    </div>
    <div class="modal-wrap" id="modalWrap"><div class="modal"><div class="modal-head"><h3 id="modalTitle"></h3></div><div class="modal-body" id="modalBody"></div><div class="modal-foot" id="modalFoot"></div></div></div>
    <div id="toastWrap"></div>
  </body></html>`;
  await page.route('http://zsky.test/', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: markup }));
  await page.goto('http://zsky.test/');
  await page.addStyleTag({ path: asset('api/admin/assets/dashboard.css') });
  await page.addStyleTag({ path: asset('api/admin/assets/admin-ux.css') });
  await page.addStyleTag({ path: asset('api/admin/assets/zsky24-admin.css') });
  await page.evaluate(() => {
    window.state = { csrf: 'test-csrf' };
    window.esc = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[character]);
    window.fmtTs = () => '05 Sep 2026';
    window.setBusy = () => {};
    window.showLogin = () => {};
    window.showToast = () => {};
    window.closeModal = () => document.querySelector('#modalWrap')?.classList.remove('open');
    window.openModal = (title, body, foot) => {
      document.querySelector('#modalTitle').textContent = title;
      document.querySelector('#modalBody').innerHTML = body;
      document.querySelector('#modalFoot').innerHTML = foot;
      document.querySelector('#modalWrap .modal').removeAttribute('data-modal-scope');
      document.querySelector('#modalWrap').classList.add('open');
    };
    window.setModalPresentationScope = (scope) => {
      document.querySelector('#modalWrap .modal').dataset.modalScope = scope;
    };
    window.closeDrawer = () => {
      const drawer = document.querySelector('#drawer');
      drawer.classList.remove('open');
      drawer.setAttribute('aria-hidden', 'true');
      drawer.setAttribute('inert', '');
    };
    window.openDrawer = (title, sub, body, foot) => {
      const drawer = document.querySelector('#drawer');
      document.querySelector('#drawerTitle').textContent = title;
      document.querySelector('#drawerSub').textContent = sub;
      document.querySelector('#drawerBody').innerHTML = body;
      document.querySelector('#drawerFoot').innerHTML = foot;
      drawer.removeAttribute('inert');
      drawer.setAttribute('aria-hidden', 'false');
      drawer.classList.add('open');
    };

    const ok = (data) => new Response(JSON.stringify({ ok: true, data }), {
      status: 200, headers: { 'Content-Type': 'application/json' }
    });
    window.fetch = async (input) => {
      const url = new URL(typeof input === 'string' ? input : input.url, location.href);
      if (url.pathname.endsWith('/api/znews/public/policy.php')) {
        return ok({
          revenue_mode: 'PERIOD_REVIEW_DIRECT_ZPAY_PAYOUT', performance_review_days: 7,
          payout_cycle: 'MONTHLY', payout_destination: 'LINKED_ZPAY_WALLET',
          revenue_provider: 'ADSTERRA', revenue_base_currency: 'USD',
          creator_pool_percent_of_net: 40, platform_share_percent_of_net: 60,
          creator_effective_percent_of_gross: 36, platform_effective_percent_of_gross: 54,
          safety_reserve_percent: 10, payout_batch_limit: 5,
          creator_balance_enabled: false, withdraw_request_enabled: false,
          automatic_per_ad_credit_enabled: false, instant_comments_enabled: true,
          supported_wallet_currencies: ['BDT', 'MYR']
        });
      }
      const action = url.searchParams.get('action');
      if (action === 'creators_list') {
        const active = url.searchParams.get('status') === 'ACTIVE';
        return ok({ items: active ? [{ creator_uid: 'CREATOR_A', name: 'Creator A', status: 'ACTIVE', wallet_currency_snapshot: 'BDT' }] : [] });
      }
      if (action === 'posts_queue') {
        return ok({ items: [{ post_id: 'POST_A', creator_uid: 'CREATOR_A', creator_name: 'Creator A', title: 'Review post', text: 'Body', status: 'REVIEW', moderation_status: 'PENDING', created_at: 1, updated_at: 2 }], has_more: false });
      }
      if (action === 'comments_queue') return ok({ items: [], has_more: false });
      if (action === 'monthly_periods') {
        const month = { month_id: '2026-08', month_start_date: '2026-08-01', month_end_date: '2026-08-31', completed: true };
        return ok({ default_month: month, items: [month] });
      }
      if (action === 'revenue_status') {
        return ok({
          month: { month_id: '2026-08', month_start_date: '2026-08-01', month_end_date: '2026-08-31', completed: true },
          sync: {}, lock: {}, fx: { USD_BDT: {}, USD_MYR: {} }, provider_configured: false
        });
      }
      if (action === 'post_details') {
        return ok({ post: { post_id: 'POST_A', creator_uid: 'CREATOR_A', creator_name: 'Creator A', title: 'Review post', text: 'Body', status: 'REVIEW', moderation_status: 'PENDING', created_at: 1, updated_at: 2 } });
      }
      return ok({ items: [] });
    };
  });
  await page.addScriptTag({ path: asset('api/admin/assets/zsky24-admin.js') });
  await page.waitForSelector('#zsky24Section[data-creator-admin-ready="true"]');
  await page.waitForSelector('[data-zsky-block-creator]', { state: 'attached' });
}

async function assertViewport(browser, width) {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  page.on('pageerror', (error) => console.error(`PAGE_ERROR(${width}): ${error.message}`));
  try {
    await mount(page);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    assert.ok(overflow <= 1, `${width}px Admin panel has horizontal overflow (${overflow}px).`);

    const tabHeights = await page.locator('.zsky-primary-tabs .zsky-admin-tab').evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect().height));
    assert.equal(new Set(tabHeights.map((height) => Math.round(height))).size, 1, `${width}px primary tabs have unequal heights.`);
    assert.ok(tabHeights.every((height) => height >= 42), `${width}px primary tab touch target is too small.`);

    await page.getByRole('tab', { name: 'Settings' }).click();
    await page.waitForSelector('.zsky-admin-guide');
    assert.equal(await page.locator('.zsky-admin-guide-grid article').count(), 7);
    assert.match(await page.locator('.zsky-balance-flow').innerText(), /canonical wallet helper credits the exact native amount once/i);

    await page.getByRole('tab', { name: 'Creator accounts' }).click();
    await page.locator('[data-zsky-block-creator]').click();
    await page.waitForSelector('#modalWrap.open .modal[data-modal-scope="zsky24"]');
    const modalButtons = await page.locator('#modalFoot .zsky-admin-button').evaluateAll((nodes) => nodes.map((node) => {
      const box = node.getBoundingClientRect();
      return { width: box.width, height: box.height, background: getComputedStyle(node).backgroundColor };
    }));
    assert.equal(modalButtons.length, 2);
    assert.ok(Math.abs(modalButtons[0].width - modalButtons[1].width) <= 1, `${width}px modal buttons are not equal width.`);
    assert.ok(modalButtons.every((button) => button.height === 48), `${width}px modal buttons are not 48px high.`);
    assert.notEqual(modalButtons[0].background, modalButtons[1].background, 'Destructive action is not visually distinct.');
    await page.locator('#modalFoot .btn.ghost').click();

    await page.getByRole('tab', { name: 'Payout readiness' }).click();
    await page.waitForSelector('#zskyPayoutSettlementPanel:not([hidden])');
    assert.equal(await page.locator('#zskyRevenueStatus').getByText('Pending', { exact: true }).count(), 1);
    const settlementButtons = await page.locator('#zskyPayoutSettlementPanel .zsky-admin-button').evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect()));
    assert.equal(settlementButtons.length, 4);
    assert.ok(settlementButtons.every((button) => button.height === 48), `${width}px settlement action heights differ.`);
    const settlementOverflow = await page.locator('#zskyPayoutSettlementPanel').evaluate((node) => node.scrollWidth - node.clientWidth);
    assert.ok(settlementOverflow <= 1, `${width}px settlement panel overflows (${settlementOverflow}px).`);

    await page.getByRole('tab', { name: 'Posts / Moderation' }).click();
    await page.locator('[data-zsky-view-post]').click();
    await page.waitForSelector('#drawer.open');
    const drawerButtons = await page.locator('#drawerFoot .zsky-admin-button').evaluateAll((nodes) => nodes.map((node) => node.getBoundingClientRect()));
    assert.equal(drawerButtons.length, 3);
    assert.ok(drawerButtons.every((button) => button.height === 48), `${width}px drawer action heights differ.`);
    const widths = drawerButtons.map((button) => Math.round(button.width));
    assert.equal(Math.max(...widths) - Math.min(...widths) <= 1, true, `${width}px drawer action widths differ.`);
  } finally {
    await page.close();
  }
}

async function main() {
  const browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome', headless: true });
  try {
    for (const width of [320, 360, 390, 412, 430, 1280]) {
      await assertViewport(browser, width);
    }
  } finally {
    await browser.close();
  }
  console.log('Z Sky 24 Admin browser UI test passed (320/360/390/412/430/1280).');
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
