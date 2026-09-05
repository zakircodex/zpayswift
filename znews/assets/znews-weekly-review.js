(() => {
  'use strict';

  const config = window.ZNEWS_CONFIG;
  const ApiClient = window.ZNewsApiClient;
  if (!config || !ApiClient) return;

  const api = window.ZNEWS_API_CLIENT || new ApiClient(config);
  const requestScheduler = window.ZNEWS_REQUEST_SCHEDULER;
  const highPriority = window.ZNewsRequestScheduler?.PRIORITY?.FEED ?? 0;
  const state = {
    loading: false,
    loaded: false,
    currentLoaded: false,
    items: [],
    cursor: '',
    hasMore: false,
    detailTrigger: null,
    skeletonTimer: 0,
    statusTimer: 0,
    retryMode: 'refresh'
  };
  const $ = (selector) => document.querySelector(selector);
  const text = (value) => String(value ?? '');
  const number = (value) => {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
  };

  function escapeHtml(value) {
    return text(value).replace(/[&<>'"]/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[char]);
  }

  function formatCompactNumber(value) {
    const exact = Math.floor(number(value));
    if (exact < 1000) return String(exact);
    const units = [
      { threshold: 1_000_000, suffix: 'M' },
      { threshold: 1_000, suffix: 'K' }
    ];
    const unit = units.find((item) => exact >= item.threshold);
    if (!unit) return String(exact);
    const scaled = exact / unit.threshold;
    return `${scaled >= 100 ? Math.round(scaled) : scaled.toFixed(1).replace(/\.0$/, '')}${unit.suffix}`;
  }

  function requestReviews(cursor, options, key) {
    if (!requestScheduler || typeof requestScheduler.schedule !== 'function') {
      return api.weeklyReviews(cursor, options);
    }
    return requestScheduler.schedule(
      highPriority,
      ({ signal }) => api.weeklyReviews(cursor, { ...options, signal }),
      { key, preemptible: false }
    );
  }

  function formatPercent(value) {
    const safe = Math.max(0, Math.min(100, number(value)));
    return `${safe.toFixed(1).replace(/\.0$/, '')}%`;
  }

  function formatDate(value, options) {
    const match = text(value).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) return '';
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));
    return new Intl.DateTimeFormat('en', { timeZone: 'UTC', ...options }).format(date);
  }

  function formatPeriod(row) {
    const start = formatDate(row?.period_start_date || row?.period_id, { day: 'numeric', month: 'short' });
    const end = formatDate(row?.period_end_date, { day: 'numeric', month: 'short', year: 'numeric' });
    return start && end ? `${start} – ${end}` : start || end || 'Current week';
  }

  function canonicalStatus(row) {
    if (row?.live_preview) return 'LIVE';
    const status = text(row?.review_status || 'UNDER_REVIEW').toUpperCase();
    return ['UNDER_REVIEW', 'APPROVED', 'HELD', 'REJECTED'].includes(status)
      ? status
      : 'UNDER_REVIEW';
  }

  function statusLabel(status) {
    return status === 'UNDER_REVIEW' ? 'UNDER REVIEW' : status;
  }

  function statusClass(status) {
    return status.toLowerCase().replaceAll('_', '-');
  }

  function statusMarkup(row) {
    const status = canonicalStatus(row);
    const label = statusLabel(status);
    return `<span class="weekly-status ${statusClass(status)}" aria-label="Review status: ${escapeHtml(label)}">${escapeHtml(label)}</span>`;
  }

  function metricMarkup(label, value, tone = '', suffix = '') {
    const exact = Math.floor(number(value));
    const formatted = suffix ? `${number(value).toFixed(1).replace(/\.0$/, '')}${suffix}` : formatCompactNumber(exact);
    const title = suffix ? formatted : exact.toLocaleString('en-US');
    return `<article class="weekly-performance-metric ${tone}"><span>${escapeHtml(label)}</span><strong title="${escapeHtml(title)}">${escapeHtml(formatted)}</strong></article>`;
  }

  function breakdownValues(row) {
    const raw = number(row?.raw_views);
    const eligible = Math.min(raw, number(row?.eligible_views));
    const invalid = Math.min(Math.max(0, raw - eligible), number(row?.invalid_views));
    const excluded = Math.min(Math.max(0, raw - eligible - invalid), number(row?.creator_views_excluded));
    const pending = Math.max(0, raw - eligible - invalid - excluded);
    return { raw, eligible, invalid, excluded, pending };
  }

  function breakdownMarkup(row, idPrefix) {
    const values = breakdownValues(row);
    const denominator = values.raw || 1;
    const segments = [
      ['eligible', 'Eligible', values.eligible],
      ['invalid', 'Invalid', values.invalid],
      ['excluded', 'Excluded', values.excluded],
      ['pending', 'Pending', values.pending]
    ];
    const bar = segments.map(([kind, label, value]) => {
      const width = values.raw > 0 ? (value / denominator) * 100 : 0;
      return `<span class="weekly-bar-segment ${kind}" data-weekly-width="${width.toFixed(4)}" title="${escapeHtml(label)}: ${Math.floor(value)}"></span>`;
    }).join('');
    const legend = segments.slice(0, 3).map(([kind, label, value]) => {
      const percent = values.raw > 0 ? (value / denominator) * 100 : 0;
      return `<span><i class="${kind}" aria-hidden="true"></i>${escapeHtml(label)} <strong>${escapeHtml(formatPercent(percent))}</strong></span>`;
    }).join('');
    return `<div class="weekly-bar" role="img" aria-label="Eligible ${Math.floor(values.eligible)}, invalid ${Math.floor(values.invalid)}, excluded ${Math.floor(values.excluded)} out of ${Math.floor(values.raw)} total views">${bar}</div><div class="weekly-bar-legend" id="${escapeHtml(idPrefix)}Legend">${legend}</div>`;
  }

  function applyBreakdownWidths(root) {
    root?.querySelectorAll('[data-weekly-width]').forEach((segment) => {
      const width = Math.max(0, Math.min(100, number(segment.dataset.weeklyWidth)));
      segment.style.flexBasis = `${width}%`;
    });
  }

  function renderCurrent(review, creator) {
    const status = canonicalStatus(review);
    $('#weeklyCurrentPeriod').textContent = formatPeriod(review);
    const badge = $('#weeklyCurrentStatus');
    badge.textContent = statusLabel(status);
    badge.className = `weekly-status ${statusClass(status)}`;
    badge.setAttribute('aria-label', `Review status: ${statusLabel(status)}`);

    const eligible = Math.floor(number(review?.eligible_views));
    const eligibleNode = $('#weeklyEligibleViews');
    eligibleNode.textContent = formatCompactNumber(eligible);
    eligibleNode.title = eligible.toLocaleString('en-US');

    $('#weeklyHeroMetrics').innerHTML = [
      metricMarkup('Total views', review?.raw_views),
      metricMarkup('Invalid', review?.invalid_views, 'danger'),
      metricMarkup('Self views', review?.self_views_excluded),
      metricMarkup('Spam / Bot', review?.spam_views, 'danger')
    ].join('');

    const verification = $('#weeklyVerification');
    verification.innerHTML = breakdownMarkup(review, 'weeklyCurrent');
    applyBreakdownWidths(verification);

    const metrics = [
      metricMarkup('Eligible views', review?.eligible_views, 'good'),
      metricMarkup('Total views', review?.raw_views),
      metricMarkup('Invalid views', review?.invalid_views, 'danger'),
      metricMarkup('Creator views excluded', review?.creator_views_excluded),
      metricMarkup('Self views', review?.self_views_excluded),
      metricMarkup('Spam / Bot', review?.spam_views, 'danger'),
      metricMarkup('Duplicate views', review?.duplicate_views),
      metricMarkup('Pending verification', review?.pending_views)
    ];
    if (!review?.traffic_share_pending) {
      metrics.push(metricMarkup('Traffic share', review?.traffic_share_percent, 'accent', '%'));
    }
    $('#weeklyCurrentMetrics').innerHTML = metrics.join('');

    const note = $('#weeklyCurrentNote');
    const creatorStatus = text(creator?.status || review?.creator_status || 'ACTIVE').toUpperCase();
    if (creatorStatus === 'BLOCKED') {
      note.textContent = 'Your creator account is blocked. Previous reports remain available for reference.';
      note.className = 'weekly-performance-note danger';
    } else if (review?.traffic_share_pending) {
      note.textContent = 'Traffic share is finalized after the week closes and the review is generated.';
      note.className = 'weekly-performance-note';
    } else {
      note.textContent = `Verified traffic share for this review: ${formatPercent(review?.traffic_share_percent)}.`;
      note.className = 'weekly-performance-note';
    }

    state.currentLoaded = true;
    $('#weeklyCurrentLoading').hidden = true;
    $('#weeklyCurrentError').hidden = true;
    $('#weeklyCurrentBody').hidden = false;
    $('#weeklyOverview').hidden = false;
    $('#weeklyCurrentReview').setAttribute('aria-busy', 'false');
  }

  function historyMarkup(row) {
    const periodId = text(row?.period_id);
    return `<button class="weekly-history-row" type="button" data-weekly-period="${escapeHtml(periodId)}" aria-haspopup="dialog" aria-expanded="false">
      <span class="weekly-history-main"><strong>${escapeHtml(formatPeriod(row))}</strong><small>${escapeHtml(formatCompactNumber(row?.raw_views))} total views</small></span>
      <span class="weekly-history-stat"><small>Eligible</small><strong title="${Math.floor(number(row?.eligible_views)).toLocaleString('en-US')}">${escapeHtml(formatCompactNumber(row?.eligible_views))}</strong></span>
      <span class="weekly-history-stat"><small>Invalid / excluded</small><strong>${escapeHtml(formatCompactNumber(number(row?.invalid_views) + number(row?.creator_views_excluded)))}</strong></span>
      <span class="weekly-history-share"><small>Traffic share</small><strong>${escapeHtml(row?.traffic_share_pending ? 'Pending' : formatPercent(row?.traffic_share_percent))}</strong></span>
      ${statusMarkup(row)}<span class="weekly-history-chevron" aria-hidden="true">›</span>
    </button>`;
  }

  function renderHistory() {
    const list = $('#weeklyReviewHistory');
    if (!state.items.length) {
      list.innerHTML = '<div class="weekly-empty"><span class="weekly-empty-icon" aria-hidden="true">◷</span><strong>No weekly reviews yet</strong><p>Your verified activity will appear here after a review period is available.</p></div>';
    } else {
      list.innerHTML = state.items.map(historyMarkup).join('');
    }
    const more = $('#weeklyReviewLoadMore');
    more.hidden = !state.hasMore || !state.cursor;
  }

  function renderDetail(row, trigger) {
    const dialog = $('#weeklyDetailDialog');
    const body = $('#weeklyDetailBody');
    if (!dialog || !body) return;
    state.detailTrigger = trigger;
    trigger?.setAttribute('aria-expanded', 'true');
    $('#weeklyDetailTitle').textContent = formatPeriod(row);
    const metrics = [
      metricMarkup('Eligible views', row?.eligible_views, 'good'),
      metricMarkup('Total views', row?.raw_views),
      metricMarkup('Invalid', row?.invalid_views, 'danger'),
      metricMarkup('Creator excluded', row?.creator_views_excluded),
      metricMarkup('Self views', row?.self_views_excluded),
      metricMarkup('Spam / Bot', row?.spam_views, 'danger')
    ];
    if (!row?.traffic_share_pending) metrics.push(metricMarkup('Traffic share', row?.traffic_share_percent, 'accent', '%'));
    const reason = text(row?.review_reason).trim();
    body.innerHTML = `<div class="weekly-detail-status">${statusMarkup(row)}</div>
      <div class="weekly-detail-metrics">${metrics.join('')}</div>
      <div class="weekly-detail-breakdown">${breakdownMarkup(row, 'weeklyDetail')}</div>
      ${reason ? `<div class="weekly-review-note"><strong>Review note</strong><p>${escapeHtml(reason)}</p></div>` : ''}`;
    applyBreakdownWidths(body);
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    $('#weeklyDetailClose')?.focus();
  }

  function closeDetail() {
    const dialog = $('#weeklyDetailDialog');
    if (dialog?.open && typeof dialog.close === 'function') dialog.close();
    else dialog?.removeAttribute('open');
  }

  function setInlineStatus(message = '', { error = false, retry = false } = {}) {
    const region = $('#weeklyInlineStatus');
    if (!region) return;
    window.clearTimeout(state.statusTimer);
    region.hidden = !message;
    region.classList.toggle('danger', error);
    region.innerHTML = message
      ? `<span>${escapeHtml(message)}</span>${retry ? '<button type="button" data-weekly-retry>Retry</button>' : ''}`
      : '';
  }

  function setLoading(loading, { append = false, refresh = false } = {}) {
    const refreshButton = $('#weeklyReviewRefresh');
    const loadMore = $('#weeklyReviewLoadMore');
    if (refreshButton) {
      refreshButton.disabled = loading;
      refreshButton.classList.toggle('is-loading', loading && !append);
      const label = refreshButton.querySelector('.weekly-refresh-label');
      if (label) label.textContent = loading && !append ? 'Refreshing…' : 'Refresh';
    }
    if (loadMore) {
      loadMore.disabled = loading;
      loadMore.classList.toggle('is-loading', loading && append);
      loadMore.textContent = loading && append ? 'Loading…' : 'Load more';
    }
    if (refresh && state.loaded && loading) setInlineStatus('Refreshing…');
  }

  function scheduleSkeleton() {
    window.clearTimeout(state.skeletonTimer);
    state.skeletonTimer = window.setTimeout(() => {
      if (!state.loading || state.loaded) return;
      const skeleton = $('#weeklyPerformanceSkeleton');
      if (skeleton) skeleton.hidden = false;
    }, 160);
  }

  function finishInitialLoading() {
    window.clearTimeout(state.skeletonTimer);
    const skeleton = $('#weeklyPerformanceSkeleton');
    if (skeleton) skeleton.hidden = true;
  }

  function setCurrentLoading() {
    const review = $('#weeklyCurrentReview');
    const loading = $('#weeklyCurrentLoading');
    const error = $('#weeklyCurrentError');
    review?.setAttribute('aria-busy', 'true');
    if (error) error.hidden = true;
    if (loading) loading.hidden = state.currentLoaded;
  }

  function setCurrentError() {
    const review = $('#weeklyCurrentReview');
    const loading = $('#weeklyCurrentLoading');
    const error = $('#weeklyCurrentError');
    if (loading) loading.hidden = true;
    if (error) error.hidden = false;
    review?.setAttribute('aria-busy', 'false');
  }

  async function loadReviews({ force = false, append = false, currentOnly = false } = {}) {
    if (state.loading || (state.loaded && !force && !append)) return;
    if (append && (!state.hasMore || !state.cursor)) return;
    if (!window.ZNEWS_AUTH_VERIFIED || !api.isAuthenticated()) return;

    state.loading = true;
    const refreshing = force && state.loaded && !append && !currentOnly;
    setLoading(true, { append, refresh: refreshing });
    if (!state.loaded && !currentOnly) scheduleSkeleton();
    if (!append && !currentOnly) setInlineStatus(refreshing ? 'Refreshing…' : '');

    try {
      if (currentOnly) {
        setCurrentLoading();
        const currentResult = await requestReviews('', {
          includeCurrent: true,
          includeHistory: false
        }, 'weekly:current');
        renderCurrent(currentResult.data?.current_preview || {}, currentResult.data?.creator || {});
        setInlineStatus('');
        return;
      }

      const historyResult = await requestReviews(append ? state.cursor : '', {
        includeCurrent: false,
        includeHistory: true
      }, `weekly:history:${append ? state.cursor : 'first'}`);
      const incoming = Array.isArray(historyResult.data?.items) ? historyResult.data.items : [];
      if (append) {
        const known = new Set(state.items.map((item) => text(item?.period_id)));
        state.items.push(...incoming.filter((item) => !known.has(text(item?.period_id))));
      } else {
        state.items = incoming;
      }
      state.cursor = text(historyResult.data?.next_cursor);
      state.hasMore = historyResult.data?.has_more === true;
      state.retryMode = 'refresh';
      state.loaded = true;
      $('#weeklyPerformanceContent').hidden = false;
      renderHistory();
      finishInitialLoading();

      if (!append) {
        setCurrentLoading();
        try {
          const currentResult = await requestReviews('', {
            includeCurrent: true,
            includeHistory: false
          }, 'weekly:current');
          renderCurrent(currentResult.data?.current_preview || {}, currentResult.data?.creator || {});
          setInlineStatus(refreshing ? 'Weekly performance refreshed.' : '');
          if (refreshing) {
            state.statusTimer = window.setTimeout(() => {
              if (!state.loading) setInlineStatus('');
            }, 2400);
          }
        } catch (_currentError) {
          setCurrentError();
          setInlineStatus(refreshing ? 'Previous reviews refreshed. Current week needs another try.' : '');
        }
      }
    } catch (_error) {
      state.retryMode = append ? 'append' : 'refresh';
      setInlineStatus('Weekly performance could not be loaded.', { error: true, retry: true });
      if (!state.loaded) $('#weeklyPerformanceContent').hidden = true;
    } finally {
      state.loading = false;
      finishInitialLoading();
      setLoading(false, { append, refresh: refreshing });
    }
  }

  function openPerformance() {
    if (!window.ZNEWS_AUTH_VERIFIED || !api.isAuthenticated()) return;
    void loadReviews();
  }

  document.addEventListener('click', (event) => {
    const historyButton = event.target.closest('[data-weekly-period]');
    if (historyButton) {
      const row = state.items.find((item) => text(item?.period_id) === historyButton.dataset.weeklyPeriod);
      if (row) renderDetail(row, historyButton);
      return;
    }
    if (event.target.closest('#weeklyReviewRefresh')) {
      void loadReviews({ force: true });
      return;
    }
    if (event.target.closest('#weeklyReviewLoadMore')) {
      void loadReviews({ append: true });
      return;
    }
    if (event.target.closest('[data-weekly-retry]')) {
      void loadReviews(state.retryMode === 'append' ? { append: true } : { force: true });
      return;
    }
    if (event.target.closest('[data-weekly-current-retry]')) {
      void loadReviews({ force: true, currentOnly: true });
      return;
    }
    if (event.target.closest('#weeklyDetailClose')) closeDetail();
  });

  const detailDialog = $('#weeklyDetailDialog');
  detailDialog?.addEventListener('click', (event) => {
    if (event.target === detailDialog) closeDetail();
  });
  detailDialog?.addEventListener('close', () => {
    state.detailTrigger?.setAttribute('aria-expanded', 'false');
    state.detailTrigger?.focus();
    state.detailTrigger = null;
  });

  window.addEventListener('znews:weekly-performance-open', openPerformance);
  window.ZNewsWeeklyPerformance = Object.freeze({
    open: openPerformance,
    refresh: () => loadReviews({ force: true }),
    formatCompactNumber,
    formatPercent,
    snapshot: () => ({
      loading: state.loading,
      loaded: state.loaded,
      currentLoaded: state.currentLoaded,
      itemCount: state.items.length,
      cursor: state.cursor,
      hasMore: state.hasMore
    })
  });

  if (document.documentElement.dataset.znewsRoute === 'performance') openPerformance();
})();
