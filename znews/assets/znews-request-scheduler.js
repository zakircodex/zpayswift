((root, factory) => {
  'use strict';

  const Scheduler = factory();
  if (typeof module === 'object' && module.exports) module.exports = Scheduler;
  if (root) {
    root.ZNewsRequestScheduler = Scheduler;
    if (!root.ZNEWS_REQUEST_SCHEDULER) root.ZNEWS_REQUEST_SCHEDULER = new Scheduler();
  }
})(typeof window !== 'undefined' ? window : globalThis, () => {
  'use strict';

  const PRIORITIES = Object.freeze({ FEED: 0, MEDIA: 1, LIKE: 2, ANALYTICS: 3 });

  function boundedPriority(value) {
    const numeric = Number(value);
    return Number.isInteger(numeric) && numeric >= 0 && numeric <= 3
      ? numeric
      : PRIORITIES.ANALYTICS;
  }

  class ZNewsRequestScheduler {
    constructor() {
      this.queues = [[], [], [], []];
      this.active = null;
      this.keyedJobs = new Map();
      this.sequence = 0;
      this.drainQueued = false;
    }

    schedule(priority, task, { key = '', preemptible = priority !== PRIORITIES.FEED } = {}) {
      if (typeof task !== 'function') throw new TypeError('Scheduled request requires a task callback.');
      const normalizedPriority = boundedPriority(priority);
      const normalizedKey = String(key || '').trim();
      if (normalizedKey && this.keyedJobs.has(normalizedKey)) {
        return this.keyedJobs.get(normalizedKey).promise;
      }

      let resolveJob;
      let rejectJob;
      const promise = new Promise((resolve, reject) => {
        resolveJob = resolve;
        rejectJob = reject;
      });
      const job = {
        id: ++this.sequence,
        priority: normalizedPriority,
        task,
        key: normalizedKey,
        preemptible: preemptible === true,
        preempted: false,
        controller: null,
        resolve: resolveJob,
        reject: rejectJob,
        promise
      };

      this.queues[normalizedPriority].push(job);
      if (normalizedKey) this.keyedJobs.set(normalizedKey, job);
      if (normalizedPriority === PRIORITIES.FEED) this.preemptBackground();
      this.queueDrain();
      return promise;
    }

    preemptBackground() {
      const active = this.active;
      if (!active || active.priority === PRIORITIES.FEED || !active.preemptible) return;
      active.preempted = true;
      active.controller?.abort();
    }

    hasFeedDemand() {
      return this.active?.priority === PRIORITIES.FEED || this.queues[PRIORITIES.FEED].length > 0;
    }

    snapshot() {
      return Object.freeze({
        activePriority: this.active ? this.active.priority : null,
        pendingByPriority: this.queues.map((queue) => queue.length),
        feedPendingOrRunning: this.hasFeedDemand()
      });
    }

    queueDrain() {
      if (this.drainQueued) return;
      this.drainQueued = true;
      Promise.resolve().then(() => {
        this.drainQueued = false;
        this.drain();
      });
    }

    nextJob() {
      for (const queue of this.queues) {
        if (queue.length) return queue.shift();
      }
      return null;
    }

    drain() {
      if (this.active) return;
      const job = this.nextJob();
      if (!job) return;

      this.active = job;
      job.preempted = false;
      job.controller = new AbortController();

      Promise.resolve()
        .then(() => job.task({
          signal: job.controller.signal,
          priority: job.priority,
          jobId: job.id
        }))
        .then((value) => this.finish(job, null, value))
        .catch((error) => this.finish(job, error));
    }

    finish(job, error = null, value = undefined) {
      if (this.active !== job) return;
      this.active = null;
      job.controller = null;

      if (job.preempted) {
        job.preempted = false;
        this.queues[job.priority].unshift(job);
      } else {
        if (job.key) this.keyedJobs.delete(job.key);
        if (error) job.reject(error); else job.resolve(value);
      }
      this.queueDrain();
    }
  }

  ZNewsRequestScheduler.PRIORITY = PRIORITIES;
  return ZNewsRequestScheduler;
});
