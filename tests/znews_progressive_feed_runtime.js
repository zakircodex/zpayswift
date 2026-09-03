'use strict';

const assert = require('node:assert/strict');
const ProgressiveFeed = require('../znews/assets/znews-progressive-feed.js');

function post(id) {
  return { post_id: `POST_${id}`, title: `Post ${id}` };
}

function deferred() {
  let resolve;
  let reject;
  const promise = new Promise((ok, fail) => {
    resolve = ok;
    reject = fail;
  });
  return { promise, resolve, reject };
}

async function sequentialFeedTest() {
  const pages = {
    '': { items: [post(1), post(2), post(3)], next_cursor: 'CURSOR_1', has_more: true },
    CURSOR_1: { items: [post(3), post(4), post(5)], next_cursor: 'CURSOR_2', has_more: true },
    CURSOR_2: { items: [post(6), post(7), post(8)], next_cursor: 'CURSOR_3', has_more: true },
    CURSOR_3: { items: [post(9), post(10)], next_cursor: '', has_more: false }
  };
  const requests = [];
  const rendered = [];
  let active = 0;
  let maxActive = 0;
  const feed = new ProgressiveFeed({
    batchSize: 3,
    lowWatermark: 1,
    fetchPage: async (cursor, limit) => {
      requests.push({ cursor, limit });
      active += 1;
      maxActive = Math.max(maxActive, active);
      await Promise.resolve();
      active -= 1;
      return pages[cursor];
    },
    renderItem: (item) => rendered.push(item.post_id)
  });

  await feed.start();
  assert.deepEqual(rendered, ['POST_1'], 'Initial request must render exactly one card.');
  assert.equal(requests[0].limit, 3, 'Feed request batch must remain small.');

  while (!feed.snapshot().done) {
    const before = rendered.length;
    await feed.advance();
    try { await feed.whenIdle(); } catch (_error) { /* covered by retry test */ }
    assert.ok(rendered.length - before <= 1, 'Each advance may append at most one card.');
  }

  assert.deepEqual(
    rendered,
    Array.from({ length: 10 }, (_value, index) => `POST_${index + 1}`),
    'Ten sequential posts must render once and in order.'
  );
  assert.equal(new Set(rendered).size, 10, 'Duplicate posts must not render.');
  assert.equal(maxActive, 1, 'Only one feed request may be in flight.');
  const requestCountAtEnd = requests.length;
  await feed.advance();
  assert.equal(requests.length, requestCountAtEnd, 'has_more=false must stop requests.');
}

async function retryTest() {
  const rendered = [];
  const requests = [];
  let failNextPage = true;
  let paginationErrors = 0;
  const feed = new ProgressiveFeed({
    batchSize: 2,
    lowWatermark: 1,
    fetchPage: async (cursor) => {
      requests.push(cursor);
      if (!cursor) return { items: [post(1), post(2)], next_cursor: 'RETRY_CURSOR', has_more: true };
      if (failNextPage) {
        failNextPage = false;
        throw Object.assign(new Error('timeout'), { code: 'REQUEST_TIMEOUT' });
      }
      return { items: [post(3)], next_cursor: '', has_more: false };
    },
    renderItem: (item) => rendered.push(item.post_id),
    onPaginationError: () => { paginationErrors += 1; }
  });

  await feed.start();
  await feed.advance();
  try { await feed.whenIdle(); } catch (_error) { /* expected background timeout */ }
  assert.deepEqual(rendered, ['POST_1', 'POST_2'], 'Pagination failure must preserve rendered posts.');
  assert.equal(paginationErrors, 1, 'Pagination error must use its non-destructive callback.');
  assert.equal(feed.snapshot().cursor, 'RETRY_CURSOR', 'Failed pagination must preserve its cursor.');

  await feed.retry();
  assert.deepEqual(rendered, ['POST_1', 'POST_2', 'POST_3'], 'Retry must append the next post.');
  assert.deepEqual(requests, ['', 'RETRY_CURSOR', 'RETRY_CURSOR'], 'Retry must safely reuse only the failed cursor.');
}

async function inFlightGuardTest() {
  const pending = deferred();
  let requests = 0;
  const feed = new ProgressiveFeed({
    fetchPage: () => {
      requests += 1;
      return pending.promise;
    },
    renderItem: () => {}
  });

  const first = feed.start();
  const second = feed.advance();
  const third = feed.retry();
  assert.equal(requests, 0, 'Request starts on the next microtask.');
  await Promise.resolve();
  assert.equal(requests, 1, 'Concurrent actions must share one request.');
  assert.equal(second, first, 'Advance must reuse the active request.');
  assert.equal(third, first, 'Retry must reuse the active request.');
  pending.resolve({ items: [post(1)], next_cursor: '', has_more: false });
  await first;
}

async function initialRetryTest() {
  let attempts = 0;
  let initialErrors = 0;
  const rendered = [];
  const feed = new ProgressiveFeed({
    fetchPage: async () => {
      attempts += 1;
      if (attempts === 1) throw new Error('initial timeout');
      return { items: [post(1)], next_cursor: '', has_more: false };
    },
    renderItem: (item) => rendered.push(item.post_id),
    onInitialError: () => { initialErrors += 1; }
  });

  await assert.rejects(feed.start(), /initial timeout/);
  assert.equal(initialErrors, 1, 'Initial error callback must run.');
  await feed.retry();
  assert.deepEqual(rendered, ['POST_1'], 'Initial retry must recover the feed.');
}

(async () => {
  await sequentialFeedTest();
  await retryTest();
  await inFlightGuardTest();
  await initialRetryTest();
  console.log('PASS: Z Sky progressive feed runtime assertions.');
})().catch((error) => {
  console.error(error?.stack || String(error));
  process.exit(1);
});
