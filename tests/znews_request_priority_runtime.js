'use strict';

const assert = require('node:assert/strict');
const Scheduler = require('../znews/assets/znews-request-scheduler.js');
const ProgressiveFeed = require('../znews/assets/znews-progressive-feed.js');

const P = Scheduler.PRIORITY;

function abortError() {
  const error = new Error('aborted');
  error.name = 'AbortError';
  return error;
}

function delay(ms, signal) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(resolve, ms);
    const abort = () => {
      clearTimeout(timer);
      reject(abortError());
    };
    if (signal?.aborted) return abort();
    signal?.addEventListener('abort', abort, { once: true });
  });
}

async function preemptionTest() {
  const scheduler = new Scheduler();
  const events = [];
  let active = 0;
  let maxActive = 0;

  function job(name, duration) {
    return async ({ signal }) => {
      active += 1;
      maxActive = Math.max(maxActive, active);
      events.push(`${name}:start`);
      try {
        await delay(duration, signal);
        events.push(`${name}:end`);
      } catch (error) {
        events.push(`${name}:abort`);
        throw error;
      } finally {
        active -= 1;
      }
      return name;
    };
  }

  const media = scheduler.schedule(P.MEDIA, job('media', 80), { key: 'media:one' });
  await delay(5);
  const analytics = scheduler.schedule(P.ANALYTICS, job('analytics', 60), { key: 'analytics:one' });
  await delay(5);
  const feedQueuedAt = Date.now();
  const feed = scheduler.schedule(P.FEED, job('feed', 30), { key: 'feed:one', preemptible: false });
  await Promise.all([feed, media, analytics]);

  const feedStartedAt = events.indexOf('feed:start');
  assert.ok(feedStartedAt > events.indexOf('media:abort'), 'P0 must start after the active media request is aborted.');
  assert.ok(feedStartedAt < events.lastIndexOf('media:start'), 'P0 must run before the preempted media retry.');
  assert.ok(feedStartedAt < events.indexOf('analytics:start'), 'P0 must run before queued analytics.');
  assert.ok(Date.now() - feedQueuedAt < 220, 'P0 request was not promoted promptly.');
  assert.equal(maxActive, 1, 'Scheduled same-origin work must never overlap.');
}

function post(id) {
  return { post_id: `POST_${id}`, title: `Post ${id}` };
}

async function tenPostContentionTest() {
  const scheduler = new Scheduler();
  const pages = {
    '': { items: [post(1), post(2), post(3)], next_cursor: 'CURSOR_1', has_more: true },
    CURSOR_1: { items: [post(4), post(5), post(6)], next_cursor: 'CURSOR_2', has_more: true },
    CURSOR_2: { items: [post(7), post(8), post(9)], next_cursor: 'CURSOR_3', has_more: true },
    CURSOR_3: { items: [post(10)], next_cursor: '', has_more: false }
  };
  const timeline = [];
  const rendered = [];
  const cursors = [];
  const background = [];
  const active = [0, 0, 0, 0];
  const maximum = [0, 0, 0, 0];
  let activeTotal = 0;
  let maxTotal = 0;

  function timedTask(priority, path, duration, result) {
    return async ({ signal }) => {
      const startedAt = Date.now();
      active[priority] += 1;
      activeTotal += 1;
      maximum[priority] = Math.max(maximum[priority], active[priority]);
      maxTotal = Math.max(maxTotal, activeTotal);
      timeline.push({ path, priority, event: 'start', startedAt, concurrent: activeTotal });
      try {
        await delay(duration, signal);
        timeline.push({ path, priority, event: 'end', startedAt, endedAt: Date.now() });
        return result;
      } catch (error) {
        timeline.push({ path, priority, event: 'abort', startedAt, endedAt: Date.now() });
        throw error;
      } finally {
        active[priority] -= 1;
        activeTotal -= 1;
      }
    };
  }

  const feed = new ProgressiveFeed({
    batchSize: 3,
    lowWatermark: 1,
    fetchPage: (cursor) => {
      cursors.push(cursor);
      return scheduler.schedule(
        P.FEED,
        timedTask(P.FEED, `/api/znews/public/feed.php?cursor=${cursor}`, 30, pages[cursor]),
        { key: `feed:${cursor || 'initial'}`, preemptible: false }
      );
    },
    renderItem: (item) => {
      rendered.push(item.post_id);
      background.push(scheduler.schedule(
        P.MEDIA,
        timedTask(P.MEDIA, `/api/znews/public/media.php?id=${item.post_id}`, 80, true),
        { key: `media:${item.post_id}`, preemptible: true }
      ));
      background.push(scheduler.schedule(
        P.LIKE,
        timedTask(P.LIKE, `/api/znews/likes/status.php?id=${item.post_id}`, 10, true),
        { key: `like:${item.post_id}`, preemptible: true }
      ));
      background.push(scheduler.schedule(
        P.ANALYTICS,
        timedTask(P.ANALYTICS, `/api/znews/public/impression.php?id=${item.post_id}`, 60, true),
        { key: `impression:${item.post_id}`, preemptible: true }
      ));
    }
  });

  await feed.start();
  while (!feed.snapshot().done) {
    await feed.advance();
    try { await feed.whenIdle(); } catch (_error) { /* no failures expected */ }
  }
  await Promise.all(background);

  assert.equal(rendered.length, 10, 'Ten posts must render under slow background contention.');
  assert.equal(new Set(rendered).size, 10, 'No duplicate post may render.');
  assert.deepEqual(cursors, ['', 'CURSOR_1', 'CURSOR_2', 'CURSOR_3'], 'Feed cursor must advance without reuse.');
  assert.equal(maximum[P.FEED], 1, 'Feed concurrency must remain one.');
  assert.equal(maximum[P.MEDIA], 1, 'Media concurrency must remain one.');
  assert.equal(maximum[P.LIKE], 1, 'Like hydration concurrency must remain one.');
  assert.equal(maximum[P.ANALYTICS], 1, 'Analytics concurrency must remain one.');
  assert.equal(maxTotal, 1, 'Lower-priority work must not overlap feed work.');
  assert.ok(timeline.some((event) => event.priority === P.MEDIA && event.event === 'abort'), 'Slow media should be preempted when feed needs its next cursor.');
  assert.ok(timeline.every((event) => event.event !== 'start' || event.concurrent === 1), 'Every scheduled request must start without same-origin contention.');

  const feedStarts = timeline.filter((event) => event.priority === P.FEED && event.event === 'start');
  assert.equal(feedStarts.length, 4, 'Ten posts must use four three-item feed requests.');
  return { timeline, maximum, maxTotal };
}

(async () => {
  await preemptionTest();
  const result = await tenPostContentionTest();
  console.log(`PASS: priority scheduler simulated feed=3s media=8s analytics=6s at 1:100 scale; events=${result.timeline.length} maxTotal=${result.maxTotal}.`);
})().catch((error) => {
  console.error(error?.stack || String(error));
  process.exit(1);
});
