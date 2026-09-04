(() => {
  'use strict';

  const COLOR_IDS = Object.freeze(['default', 'white', 'light_blue', 'green', 'yellow', 'orange', 'red']);
  const COLOR_LABELS = Object.freeze({
    default: 'Default',
    white: 'White',
    light_blue: 'Light blue',
    green: 'Green',
    yellow: 'Yellow',
    orange: 'Orange',
    red: 'Red'
  });
  const CATEGORY_LABELS = Object.freeze({
    INTERNATIONAL_NEWS: 'International news',
    BD_NEWS: 'BD news',
    MOBILE_PRICING: 'Mobile pricing'
  });
  const editorStates = new WeakMap();
  const pickerStates = new WeakMap();
  const progressTokens = new Set();
  let visualViewportBound = false;

  const text = (value) => String(value ?? '');
  const points = (value) => Array.from(text(value));
  const escapeHtml = (value) => text(value).replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[char]);

  function normalizeBoldRanges(value, content) {
    const length = points(content).length;
    if (!Array.isArray(value)) return [];
    const ranges = value.slice(0, 100).map((range) => ({
      start: Number(range?.start),
      end: Number(range?.end)
    })).filter((range) => Number.isInteger(range.start)
      && Number.isInteger(range.end)
      && range.start >= 0
      && range.end > range.start
      && range.end <= length)
      .sort((left, right) => left.start - right.start || left.end - right.end);
    const normalized = [];
    ranges.forEach((range) => {
      if (!normalized.length || range.start >= normalized[normalized.length - 1].end) normalized.push(range);
    });
    return normalized;
  }

  function normalizeFormattingRuns(value, content, boldRanges = []) {
    const length = points(content).length;
    const source = Array.isArray(value) && value.length
      ? value
      : normalizeBoldRanges(boldRanges, content).map((range) => ({ ...range, bold: true }));
    const runs = source.slice(0, 200).map((run) => ({
      start: Number(run?.start),
      end: Number(run?.end),
      bold: run?.bold === true,
      color: COLOR_IDS.includes(text(run?.color)) ? text(run.color) : 'default'
    })).filter((run) => Number.isInteger(run.start)
      && Number.isInteger(run.end)
      && run.start >= 0
      && run.end > run.start
      && run.end <= length
      && (run.bold || run.color !== 'default'))
      .sort((left, right) => left.start - right.start || left.end - right.end);
    const normalized = [];
    runs.forEach((run) => {
      if (!normalized.length || run.start >= normalized[normalized.length - 1].end) normalized.push(run);
    });
    return normalized;
  }

  function stylesFor(content, formattingRuns = [], boldRanges = []) {
    const styles = points(content).map(() => ({ bold: false, color: 'default' }));
    normalizeFormattingRuns(formattingRuns, content, boldRanges).forEach((run) => {
      for (let index = run.start; index < run.end; index += 1) {
        styles[index] = { bold: run.bold, color: run.color };
      }
    });
    return styles;
  }

  function runsFromStyles(styles) {
    const runs = [];
    let start = 0;
    while (start < styles.length) {
      const style = styles[start] || { bold: false, color: 'default' };
      let end = start + 1;
      while (end < styles.length
        && styles[end]?.bold === style.bold
        && styles[end]?.color === style.color) end += 1;
      if (style.bold || style.color !== 'default') {
        const run = { start, end };
        if (style.bold) run.bold = true;
        if (style.color !== 'default') run.color = style.color;
        runs.push(run);
      }
      start = end;
    }
    return runs.slice(0, 200);
  }

  function boldRangesFromRuns(runs) {
    const ranges = [];
    runs.forEach((run) => {
      if (run.bold !== true) return;
      const last = ranges[ranges.length - 1];
      if (last && last.end === run.start) last.end = run.end;
      else ranges.push({ start: run.start, end: run.end });
    });
    return ranges.slice(0, 100);
  }

  function selectionPoints(textarea) {
    const value = textarea.value;
    return {
      start: points(value.slice(0, textarea.selectionStart)).length,
      end: points(value.slice(0, textarea.selectionEnd)).length
    };
  }

  function pointToCodeUnit(content, pointIndex) {
    return points(content).slice(0, Math.max(0, pointIndex)).join('').length;
  }

  function liveSelection(textarea) {
    const length = textarea.value.length;
    const start = Math.max(0, Math.min(length, Number(textarea.selectionStart || 0)));
    const end = Math.max(start, Math.min(length, Number(textarea.selectionEnd || start)));
    return { start, end, direction: textarea.selectionDirection || 'none' };
  }

  function rememberSelection(textarea) {
    const state = editorStates.get(textarea);
    if (!state) return liveSelection(textarea);
    state.selection = liveSelection(textarea);
    return state.selection;
  }

  function resolvedSelection(textarea) {
    const state = ensureState(textarea);
    const live = liveSelection(textarea);
    if (document.activeElement === textarea || live.start !== live.end || !state.selection) {
      state.selection = live;
    }
    return { ...(state.selection || live) };
  }

  function restoreSelection(textarea, selection = null) {
    const state = ensureState(textarea);
    const saved = selection || state.selection || liveSelection(textarea);
    const length = textarea.value.length;
    const start = Math.max(0, Math.min(length, Number(saved.start || 0)));
    const end = Math.max(start, Math.min(length, Number(saved.end || start)));
    textarea.focus({ preventScroll: true });
    textarea.setSelectionRange(start, end, saved.direction || 'none');
    state.selection = { start, end, direction: saved.direction || 'none' };
    return state.selection;
  }

  function updateVisualViewportMetrics() {
    const viewport = window.visualViewport;
    const layoutHeight = Math.max(window.innerHeight || 0, document.documentElement.clientHeight || 0);
    const visibleHeight = viewport ? viewport.height : layoutHeight;
    const offsetTop = viewport ? viewport.offsetTop : 0;
    const keyboardInset = Math.max(0, Math.round(layoutHeight - visibleHeight - offsetTop));
    document.documentElement.style.setProperty('--znews-keyboard-inset', `${keyboardInset}px`);
    document.documentElement.style.setProperty('--znews-visual-height', `${Math.round(visibleHeight)}px`);
  }

  function bindVisualViewport() {
    if (visualViewportBound) return;
    visualViewportBound = true;
    updateVisualViewportMetrics();
    window.addEventListener('resize', updateVisualViewportMetrics, { passive: true });
    window.visualViewport?.addEventListener('resize', updateVisualViewportMetrics, { passive: true });
    window.visualViewport?.addEventListener('scroll', updateVisualViewportMetrics, { passive: true });
  }

  function keepSelectionVisible(textarea, selection = null) {
    const saved = selection || resolvedSelection(textarea);
    const style = window.getComputedStyle(textarea);
    const lineHeight = Number.parseFloat(style.lineHeight) || 24;
    const paddingTop = Number.parseFloat(style.paddingTop) || 0;
    const linesBefore = textarea.value.slice(0, saved.start).split('\n').length - 1;
    const caretTop = paddingTop + (linesBefore * lineHeight);
    const safeInsideTop = textarea.scrollTop + Math.min(48, textarea.clientHeight / 3);
    const safeInsideBottom = textarea.scrollTop + textarea.clientHeight - 48;
    if (caretTop < safeInsideTop) textarea.scrollTop = Math.max(0, caretTop - 56);
    if (caretTop > safeInsideBottom) textarea.scrollTop = Math.max(0, caretTop - textarea.clientHeight + 72);

    const viewport = window.visualViewport;
    const visibleTop = (viewport?.offsetTop || 0) + 126;
    const visibleBottom = (viewport?.offsetTop || 0) + (viewport?.height || window.innerHeight) - 104;
    const surface = textarea.closest('.rich-editor-surface') || textarea;
    const rect = surface.getBoundingClientRect();
    const form = textarea.closest('.composer-form');
    if (form && rect.top < visibleTop) form.scrollBy({ top: rect.top - visibleTop, behavior: 'auto' });
    else if (form && rect.bottom > visibleBottom) form.scrollBy({ top: rect.bottom - visibleBottom, behavior: 'auto' });
    renderEditorPreview(textarea);
  }

  function reconcileInput(textarea) {
    const state = editorStates.get(textarea);
    if (!state) return;
    const before = points(state.text);
    const after = points(textarea.value);
    let prefix = 0;
    while (prefix < before.length && prefix < after.length && before[prefix] === after[prefix]) prefix += 1;
    let suffix = 0;
    while (suffix < before.length - prefix
      && suffix < after.length - prefix
      && before[before.length - suffix - 1] === after[after.length - suffix - 1]) suffix += 1;
    const insertedLength = after.length - prefix - suffix;
    const inherited = state.styles[Math.max(0, prefix - 1)] || { bold: false, color: 'default' };
    state.styles = [
      ...state.styles.slice(0, prefix),
      ...Array.from({ length: insertedLength }, () => ({ ...inherited })),
      ...state.styles.slice(before.length - suffix)
    ];
    state.text = textarea.value;
    updateToolbar(textarea);
    renderEditorPreview(textarea);
  }

  function setEditorContent(textarea, content = '', formattingRuns = [], boldRanges = []) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    const plain = text(content);
    const current = editorStates.get(textarea);
    textarea.value = plain;
    editorStates.set(textarea, {
      text: plain,
      styles: stylesFor(plain, formattingRuns, boldRanges),
      toolbar: current?.toolbar || null,
      preview: current?.preview || null,
      selection: { start: 0, end: 0, direction: 'none' }
    });
    updateToolbar(textarea);
    renderEditorPreview(textarea);
    textarea.dispatchEvent(new Event('znews:format-change', { bubbles: true }));
  }

  function ensureState(textarea) {
    if (!editorStates.has(textarea)) setEditorContent(textarea, textarea.value);
    return editorStates.get(textarea);
  }

  function getEditorPayload(textarea) {
    if (!(textarea instanceof HTMLTextAreaElement)) return { text: '', formattingRuns: [], boldRanges: [] };
    reconcileInput(textarea);
    const state = ensureState(textarea);
    const all = points(textarea.value.replace(/\r\n?/g, '\n'));
    let start = 0;
    let end = all.length;
    while (start < end && /\s/u.test(all[start])) start += 1;
    while (end > start && /\s/u.test(all[end - 1])) end -= 1;
    const plain = all.slice(start, end).join('');
    const runs = runsFromStyles(state.styles.slice(start, end));
    return { text: plain, formattingRuns: runs, boldRanges: boldRangesFromRuns(runs) };
  }

  function applyStyle(textarea, update, savedSelection = null) {
    if (!(textarea instanceof HTMLTextAreaElement)) return;
    reconcileInput(textarea);
    const state = ensureState(textarea);
    const codeSelection = restoreSelection(textarea, savedSelection || resolvedSelection(textarea));
    const selection = {
      start: points(textarea.value.slice(0, codeSelection.start)).length,
      end: points(textarea.value.slice(0, codeSelection.end)).length
    };
    if (selection.start === selection.end) {
      updateToolbar(textarea);
      return;
    }
    for (let index = selection.start; index < selection.end; index += 1) {
      state.styles[index] = update({ ...(state.styles[index] || { bold: false, color: 'default' }) });
    }
    state.text = textarea.value;
    const start = pointToCodeUnit(textarea.value, selection.start);
    const end = pointToCodeUnit(textarea.value, selection.end);
    textarea.setSelectionRange(start, end);
    state.selection = { start, end, direction: codeSelection.direction || 'none' };
    updateToolbar(textarea);
    renderEditorPreview(textarea);
    keepSelectionVisible(textarea, state.selection);
    textarea.dispatchEvent(new Event('znews:format-change', { bubbles: true }));
  }

  function toggleBold(textarea) {
    const state = ensureState(textarea);
    const saved = resolvedSelection(textarea);
    const selection = {
      start: points(textarea.value.slice(0, saved.start)).length,
      end: points(textarea.value.slice(0, saved.end)).length
    };
    const selected = state.styles.slice(selection.start, selection.end);
    const shouldBold = selected.length > 0 && !selected.every((style) => style.bold === true);
    applyStyle(textarea, (style) => ({ ...style, bold: shouldBold }), saved);
  }

  function applyColor(textarea, color) {
    const safeColor = COLOR_IDS.includes(color) ? color : 'default';
    applyStyle(textarea, (style) => ({ ...style, color: safeColor }), resolvedSelection(textarea));
  }

  function selectedStyle(textarea) {
    const state = ensureState(textarea);
    const saved = resolvedSelection(textarea);
    const selection = {
      start: points(textarea.value.slice(0, saved.start)).length,
      end: points(textarea.value.slice(0, saved.end)).length
    };
    const selected = state.styles.slice(selection.start, selection.end);
    if (!selected.length) {
      const index = selection.start < state.styles.length
        ? selection.start
        : Math.max(0, state.styles.length - 1);
      const caretStyle = state.styles[index] || { bold: false, color: 'default' };
      return { bold: caretStyle.bold === true, boldMixed: false, color: caretStyle.color, colorMixed: false };
    }
    const allBold = selected.every((style) => style.bold === true);
    const anyBold = selected.some((style) => style.bold === true);
    const colors = new Set(selected.map((style) => style.color || 'default'));
    return {
      bold: allBold,
      boldMixed: anyBold && !allBold,
      color: colors.size === 1 ? selected[0].color : 'default',
      colorMixed: colors.size > 1
    };
  }

  function updateToolbar(textarea) {
    const state = editorStates.get(textarea);
    const toolbar = state?.toolbar;
    if (!toolbar) return;
    const selected = selectedStyle(textarea);
    const bold = toolbar.querySelector('[data-format-bold]');
    const color = toolbar.querySelector('[data-format-color-toggle]');
    bold?.classList.toggle('active', selected.bold);
    bold?.classList.toggle('mixed', selected.boldMixed);
    bold?.setAttribute('aria-pressed', selected.boldMixed ? 'mixed' : (selected.bold ? 'true' : 'false'));
    if (color) {
      color.dataset.color = selected.colorMixed ? 'mixed' : selected.color;
      color.classList.toggle('mixed', selected.colorMixed);
      color.classList.toggle('active', selected.colorMixed || selected.color !== 'default');
      color.setAttribute('aria-pressed', selected.colorMixed ? 'mixed' : (selected.color !== 'default' ? 'true' : 'false'));
      const colorLabel = selected.colorMixed ? 'Mixed' : COLOR_LABELS[selected.color];
      color.setAttribute('aria-label', `Text color: ${colorLabel}`);
      color.querySelector('[data-color-name]')?.replaceChildren(document.createTextNode(colorLabel));
    }
    toolbar.querySelectorAll('[data-format-color]').forEach((button) => {
      const active = !selected.colorMixed && button.dataset.formatColor === selected.color;
      button.classList.toggle('active', active);
      button.setAttribute('aria-checked', active ? 'true' : 'false');
    });
  }

  function renderEditorPreview(textarea) {
    const state = editorStates.get(textarea);
    const preview = state?.preview;
    if (!preview) return;
    const value = textarea.value.replace(/\r\n?/g, '\n');
    preview.innerHTML = value
      ? `${formattedTextHtml(value, runsFromStyles(state.styles))}<span class="rich-editor-caret-space" aria-hidden="true">\u200b</span>`
      : '';
    preview.hidden = value === '';
    preview.scrollTop = textarea.scrollTop;
    preview.scrollLeft = textarea.scrollLeft;
  }

  function ensureVisualSurface(textarea) {
    const state = ensureState(textarea);
    if (state.preview?.isConnected) return state.preview;
    const surface = document.createElement('div');
    surface.className = 'rich-editor-surface';
    const preview = document.createElement('div');
    preview.className = 'rich-editor-live-preview';
    preview.setAttribute('aria-hidden', 'true');
    textarea.parentNode.insertBefore(surface, textarea);
    surface.append(preview, textarea);
    textarea.classList.add('rich-editor-input');
    state.preview = preview;
    renderEditorPreview(textarea);
    return preview;
  }

  function closePalette(toolbar, { restore = false } = {}) {
    const toggle = toolbar?.querySelector('[data-format-color-toggle]');
    const palette = toolbar?.querySelector('[data-format-palette]');
    if (!toggle || !palette) return;
    palette.hidden = true;
    toolbar.classList.remove('palette-open');
    toggle.setAttribute('aria-expanded', 'false');
    if (restore) restoreSelection(toolbar._znewsTextarea);
  }

  function bindToolbar(textarea, toolbar) {
    if (!(textarea instanceof HTMLTextAreaElement) || !(toolbar instanceof HTMLElement)) return;
    const state = ensureState(textarea);
    state.toolbar = toolbar;
    toolbar._znewsTextarea = textarea;
    bindVisualViewport();
    ensureVisualSurface(textarea);
    toolbar.querySelectorAll('button').forEach((button) => {
      button.addEventListener('pointerdown', (event) => {
        rememberSelection(textarea);
        event.preventDefault();
      });
      button.addEventListener('touchstart', () => rememberSelection(textarea), { passive: true });
    });
    toolbar.querySelector('[data-format-bold]')?.addEventListener('click', () => {
      restoreSelection(textarea);
      toggleBold(textarea);
    });
    toolbar.querySelector('[data-format-color-toggle]')?.addEventListener('click', () => {
      const saved = resolvedSelection(textarea);
      const palette = toolbar.querySelector('[data-format-palette]');
      const open = palette.hidden;
      document.querySelectorAll('[data-format-palette]:not([hidden])').forEach((item) => {
        if (item !== palette) closePalette(item.closest('.composer-format-toolbar'));
      });
      palette.hidden = !open;
      toolbar.classList.toggle('palette-open', open);
      toolbar.querySelector('[data-format-color-toggle]').setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        restoreSelection(textarea, saved);
        keepSelectionVisible(textarea, saved);
      }
    });
    toolbar.querySelectorAll('[data-format-color]').forEach((button) => {
      button.addEventListener('click', () => {
        restoreSelection(textarea);
        applyColor(textarea, text(button.dataset.formatColor));
        closePalette(toolbar);
      });
    });
    ['select', 'keyup', 'click', 'focus'].forEach((name) => textarea.addEventListener(name, () => {
      const live = liveSelection(textarea);
      if (document.activeElement === textarea || live.start !== live.end) rememberSelection(textarea);
      updateToolbar(textarea);
      if (name === 'select' && live.start !== live.end) {
        window.requestAnimationFrame(() => keepSelectionVisible(textarea, live));
      }
    }));
    textarea.addEventListener('input', () => reconcileInput(textarea));
    textarea.addEventListener('scroll', () => renderEditorPreview(textarea), { passive: true });
    textarea.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closePalette(toolbar, { restore: true });
    });
    updateToolbar(textarea);
    renderEditorPreview(textarea);
  }

  function formattedTextHtml(content, formattingRuns = [], boldRanges = []) {
    const plain = text(content);
    const chars = points(plain);
    const runs = normalizeFormattingRuns(formattingRuns, plain, boldRanges);
    let cursor = 0;
    let html = '';
    runs.forEach((run) => {
      html += escapeHtml(chars.slice(cursor, run.start).join(''));
      let segment = escapeHtml(chars.slice(run.start, run.end).join(''));
      if (run.color !== 'default') segment = `<span class="znews-text-color-${run.color}">${segment}</span>`;
      if (run.bold) segment = `<strong>${segment}</strong>`;
      html += segment;
      cursor = run.end;
    });
    return html + escapeHtml(chars.slice(cursor).join(''));
  }

  function categoryLabel(value) {
    return CATEGORY_LABELS[text(value).toUpperCase()] || 'Choose a category';
  }

  function closeCategoryPicker(input, { restore = true } = {}) {
    const state = pickerStates.get(input);
    if (!state?.dialog?.open) return;
    state.dialog.close();
    state.button.setAttribute('aria-expanded', 'false');
    if (restore) state.button.focus();
  }

  function setCategory(input, value, { notify = true } = {}) {
    const state = pickerStates.get(input);
    const normalized = Object.hasOwn(CATEGORY_LABELS, text(value).toUpperCase()) ? text(value).toUpperCase() : '';
    input.value = normalized;
    if (state) {
      state.label.textContent = categoryLabel(normalized);
      state.button.classList.toggle('has-value', normalized !== '');
      state.dialog.querySelectorAll('[data-category-option]').forEach((option) => {
        const selected = option.dataset.categoryOption === normalized;
        option.classList.toggle('selected', selected);
        option.setAttribute('aria-checked', selected ? 'true' : 'false');
      });
    }
    if (notify) input.dispatchEvent(new CustomEvent('change', { bubbles: true, detail: { source: 'user' } }));
  }

  function bindCategoryPicker(input, button, dialog) {
    if (!(input instanceof HTMLInputElement)
      || !(button instanceof HTMLButtonElement)
      || !(dialog instanceof HTMLDialogElement)) return;
    const label = button.querySelector('[data-category-label]');
    if (!label) return;
    pickerStates.set(input, { button, dialog, label });
    button.addEventListener('click', () => {
      button.setAttribute('aria-expanded', 'true');
      if (!dialog.open) dialog.showModal();
      dialog.querySelector('[aria-checked="true"]')?.focus();
    });
    dialog.querySelectorAll('[data-category-option]').forEach((option) => {
      option.addEventListener('click', () => {
        setCategory(input, option.dataset.categoryOption);
        closeCategoryPicker(input);
      });
    });
    dialog.querySelector('[data-category-close]')?.addEventListener('click', () => closeCategoryPicker(input));
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      closeCategoryPicker(input);
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) closeCategoryPicker(input);
    });
    setCategory(input, input.value, { notify: false });
  }

  function ensureTopProgress() {
    let progress = document.querySelector('#znewsTopProgress');
    if (progress) return progress;
    progress = document.createElement('div');
    progress.id = 'znewsTopProgress';
    progress.className = 'znews-top-progress';
    progress.setAttribute('role', 'progressbar');
    progress.setAttribute('aria-label', 'Working');
    progress.setAttribute('aria-valuemin', '0');
    progress.setAttribute('aria-valuemax', '100');
    progress.setAttribute('aria-valuetext', 'Working');
    progress.innerHTML = '<span aria-hidden="true"></span>';
    document.body.appendChild(progress);
    return progress;
  }

  function beginProgress() {
    const progress = ensureTopProgress();
    const token = Symbol('znews-progress');
    progressTokens.add(token);
    progress.classList.remove('complete');
    progress.classList.add('active');
    return () => {
      if (!progressTokens.delete(token) || progressTokens.size) return;
      progress.classList.add('complete');
      window.setTimeout(() => {
        if (progressTokens.size) return;
        progress.classList.remove('active', 'complete');
      }, 220);
    };
  }

  function setButtonLoading(button, busy, label, { spinner = true } = {}) {
    if (!(button instanceof HTMLButtonElement)) return;
    if (busy) {
      if (button.dataset.znewsLoading !== 'true') {
        button.dataset.znewsIdleLabel = button.textContent;
        button.dataset.znewsWasDisabled = button.disabled ? 'true' : 'false';
      }
      button.dataset.znewsLoading = 'true';
      button.textContent = label;
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
      button.classList.toggle('znews-button-loading', spinner);
      button.classList.toggle('znews-button-working', !spinner);
      return;
    }
    button.textContent = button.dataset.znewsIdleLabel || button.textContent;
    button.disabled = button.dataset.znewsWasDisabled === 'true';
    button.removeAttribute('aria-busy');
    button.classList.remove('znews-button-loading', 'znews-button-working');
    delete button.dataset.znewsWasDisabled;
    delete button.dataset.znewsLoading;
  }

  document.addEventListener('pointerdown', (event) => {
    document.querySelectorAll('[data-format-palette]:not([hidden])').forEach((palette) => {
      const toolbar = palette.closest('.composer-format-toolbar');
      if (toolbar && !toolbar.contains(event.target)) closePalette(toolbar);
    });
  });

  window.ZNewsRichText = Object.freeze({
    colorIds: COLOR_IDS,
    normalizeBoldRanges,
    normalizeFormattingRuns,
    formattedTextHtml,
    setEditorContent,
    getEditorPayload,
    bindToolbar,
    toggleBold,
    applyColor,
    updateToolbar
  });
  window.ZNewsCategoryPicker = Object.freeze({ bind: bindCategoryPicker, set: setCategory, close: closeCategoryPicker });
  window.ZNewsUiFeedback = Object.freeze({ beginProgress, setButtonLoading });
})();
