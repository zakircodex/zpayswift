((root, factory) => {
  'use strict';

  const ProgressiveFeed = factory();
  if (typeof module === 'object' && module.exports) module.exports = ProgressiveFeed;
  if (root) root.ZNewsProgressiveFeed = ProgressiveFeed;
})(typeof window !== 'undefined' ? window : globalThis, () => {
  'use strict';

  class ZNewsProgressiveFeed {
    constructor({
      fetchPage,
      renderItem,
      itemId = (item) => String(item?.post_id || '').trim(),
      onReset = () => {},
      onStateChange = () => {},
      onInitialError = () => {},
      onPaginationError = () => {},
      batchSize = 3,
      lowWatermark = 1
    } = {}) {
      if (typeof fetchPage !== 'function' || typeof renderItem !== 'function') {
        throw new TypeError('Progressive feed requires fetchPage and renderItem callbacks.');
      }

      this.fetchPage = fetchPage;
      this.renderItem = renderItem;
      this.itemId = itemId;
      this.onReset = onReset;
      this.onStateChange = onStateChange;
      this.onInitialError = onInitialError;
      this.onPaginationError = onPaginationError;
      this.batchSize = Math.max(2, Math.min(5, Number(batchSize) || 3));
      this.lowWatermark = Math.max(0, Math.min(this.batchSize - 1, Number(lowWatermark) || 0));
      this.generation = 0;
      this.inFlight = null;
      this.resetState();
    }

    resetState() {
      this.buffer = [];
      this.knownIds = new Set();
      this.cursor = '';
      this.hasMore = true;
      this.loaded = false;
      this.error = null;
      this.renderedCount = 0;
    }

    snapshot() {
      return Object.freeze({
        bufferSize: this.buffer.length,
        cursor: this.cursor,
        hasMore: this.hasMore,
        loaded: this.loaded,
        loading: this.inFlight !== null,
        error: this.error,
        renderedCount: this.renderedCount,
        canAdvance: this.buffer.length > 0 || (this.hasMore && this.error === null),
        done: this.loaded && this.buffer.length === 0 && !this.hasMore
      });
    }

    notify() {
      this.onStateChange(this.snapshot());
    }

    start() {
      if (this.inFlight) return this.inFlight;
      this.generation += 1;
      this.resetState();
      this.onReset();
      this.notify();
      return this.requestPage({ initial: true, renderAfterFetch: true });
    }

    advance() {
      if (this.buffer.length > 0) {
        this.renderOne();
        this.prefetchIfLow();
        return Promise.resolve(true);
      }
      if (this.error || (this.loaded && !this.hasMore)) return Promise.resolve(false);
      if (this.inFlight) return this.inFlight;
      return this.requestPage({ initial: !this.loaded, renderAfterFetch: true });
    }

    retry() {
      if (this.inFlight) return this.inFlight;
      if (!this.error) return this.advance();
      this.error = null;
      this.notify();
      return this.requestPage({ initial: !this.loaded, renderAfterFetch: true });
    }

    whenIdle() {
      return this.inFlight || Promise.resolve(this.snapshot());
    }

    renderOne() {
      const item = this.buffer.shift();
      if (!item) {
        this.notify();
        return false;
      }
      const index = this.renderedCount;
      this.renderItem(item, index);
      this.renderedCount += 1;
      this.notify();
      return true;
    }

    prefetchIfLow() {
      if (this.buffer.length > this.lowWatermark
        || !this.hasMore
        || this.error
        || this.inFlight) {
        return;
      }
      void this.requestPage({ initial: false, renderAfterFetch: false }).catch(() => {});
    }

    requestPage({ initial, renderAfterFetch }) {
      if (this.inFlight) return this.inFlight;
      if (!initial && (!this.hasMore || this.error)) return Promise.resolve(false);

      const generation = this.generation;
      const requestCursor = initial ? '' : this.cursor;
      const request = Promise.resolve()
        .then(() => this.fetchPage(requestCursor, this.batchSize))
        .then((page) => {
          if (generation !== this.generation) return false;
          const data = page && typeof page === 'object' ? page : {};
          const items = Array.isArray(data.items) ? data.items : [];
          items.forEach((item) => {
            const id = this.itemId(item);
            if (!id || this.knownIds.has(id)) return;
            this.knownIds.add(id);
            this.buffer.push(item);
          });
          this.cursor = String(data.next_cursor || '').trim();
          this.hasMore = data.has_more === true && this.cursor !== '';
          this.loaded = true;
          this.error = null;
          if (renderAfterFetch) this.renderOne();
          return true;
        })
        .catch((error) => {
          if (generation !== this.generation) return false;
          this.error = error;
          if (this.renderedCount === 0) this.onInitialError(error);
          else this.onPaginationError(error);
          throw error;
        });

      const tracked = request.finally(() => {
        if (this.inFlight === tracked) this.inFlight = null;
        this.notify();
        if (!this.error) this.prefetchIfLow();
      });
      this.inFlight = tracked;
      this.notify();
      return tracked;
    }
  }

  return ZNewsProgressiveFeed;
});
