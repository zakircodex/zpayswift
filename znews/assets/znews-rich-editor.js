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
  const DEFAULT_STYLE = Object.freeze({ bold: false, color: 'default' });
  const BLOCK_TAGS = new Set(['DIV', 'P']);

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

  function pointToCodeUnit(content, pointIndex) {
    return points(content).slice(0, Math.max(0, pointIndex)).join('').length;
  }

  function inlineStyle(node, inherited = DEFAULT_STYLE) {
    if (!(node instanceof HTMLElement)) return inherited;
    let bold = inherited.bold === true;
    let color = inherited.color || 'default';
    if (node.matches('strong,b')) bold = true;
    const colorClass = [...node.classList].find((name) => name.startsWith('znews-text-color-'));
    if (colorClass) {
      const candidate = colorClass.slice('znews-text-color-'.length);
      color = COLOR_IDS.includes(candidate) ? candidate : color;
    }
    return { bold, color };
  }

  function readEditorContent(root) {
    const characters = [];
    const styles = [];
    const append = (value, style) => points(value).forEach((character) => {
      characters.push(character);
      styles.push({ bold: style.bold === true, color: COLOR_IDS.includes(style.color) ? style.color : 'default' });
    });
    const visit = (node, inherited) => {
      if (node.nodeType === Node.TEXT_NODE) {
        append(node.nodeValue || '', inherited);
        return;
      }
      if (!(node instanceof HTMLElement)) {
        node.childNodes.forEach((child) => visit(child, inherited));
        return;
      }
      if (node.tagName === 'BR') {
        append('\n', inherited);
        return;
      }
      const style = inlineStyle(node, inherited);
      const block = BLOCK_TAGS.has(node.tagName);
      if (block && characters.length && characters[characters.length - 1] !== '\n') append('\n', inherited);
      node.childNodes.forEach((child) => visit(child, style));
      if (block && node.nextSibling && characters[characters.length - 1] !== '\n') append('\n', inherited);
    };
    root.childNodes.forEach((child) => visit(child, DEFAULT_STYLE));
    return { text: characters.join(''), styles };
  }

  function selectionInside(editor, selection) {
    return Boolean(selection?.rangeCount
      && editor.contains(selection.anchorNode)
      && editor.contains(selection.focusNode));
  }

  function offsetAtDomPoint(editor, container, offset) {
    const range = document.createRange();
    range.selectNodeContents(editor);
    try {
      range.setEnd(container, offset);
    } catch (_error) {
      return readEditorContent(editor).text.length;
    }
    return readEditorContent(range.cloneContents()).text.length;
  }

  function editorSelection(editor) {
    const selection = window.getSelection();
    if (!selectionInside(editor, selection)) return null;
    const anchor = offsetAtDomPoint(editor, selection.anchorNode, selection.anchorOffset);
    const focus = offsetAtDomPoint(editor, selection.focusNode, selection.focusOffset);
    return {
      start: Math.min(anchor, focus),
      end: Math.max(anchor, focus),
      direction: anchor > focus ? 'backward' : 'forward'
    };
  }

  function storeSelection(editor, selection) {
    const state = ensureState(editor);
    const length = state.text.length;
    const start = Math.max(0, Math.min(length, Number(selection?.start || 0)));
    const end = Math.max(start, Math.min(length, Number(selection?.end || start)));
    state.selection = { start, end, direction: selection?.direction || 'none' };
    return { ...state.selection };
  }

  function rememberSelection(editor) {
    const live = editorSelection(editor);
    if (live) return storeSelection(editor, live);
    const state = ensureState(editor);
    return storeSelection(editor, state.selection);
  }

  function resolvedSelection(editor) {
    return rememberSelection(editor);
  }

  function domPointAtOffset(editor, requestedOffset) {
    let remaining = Math.max(0, Number(requestedOffset || 0));
    const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
    let current = walker.nextNode();
    let last = null;
    while (current) {
      last = current;
      if (remaining < current.nodeValue.length) return { node: current, offset: remaining };
      if (remaining === current.nodeValue.length) {
        const next = walker.nextNode();
        if (next) return { node: next, offset: 0 };
        let boundary = current;
        while (boundary.parentNode && boundary.parentNode !== editor) boundary = boundary.parentNode;
        if (boundary.parentNode === editor) {
          return { node: editor, offset: [...editor.childNodes].indexOf(boundary) + 1 };
        }
        return { node: current, offset: current.nodeValue.length };
      }
      remaining -= current.nodeValue.length;
      current = walker.nextNode();
    }
    if (last) return { node: last, offset: last.nodeValue.length };
    return { node: editor, offset: 0 };
  }

  function restoreSelection(editor, selection = null) {
    const state = ensureState(editor);
    const saved = storeSelection(editor, selection || state.selection);
    const start = domPointAtOffset(editor, saved.start);
    const end = domPointAtOffset(editor, saved.end);
    const range = document.createRange();
    range.setStart(start.node, start.offset);
    range.setEnd(end.node, end.offset);
    const live = window.getSelection();
    live.removeAllRanges();
    live.addRange(range);
    editor.focus({ preventScroll: true });
    return saved;
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

  function keepSelectionVisible(editor, selection = null) {
    const saved = selection || resolvedSelection(editor);
    const viewport = window.visualViewport;
    const visibleTop = (viewport?.offsetTop || 0) + 126;
    const visibleBottom = (viewport?.offsetTop || 0) + (viewport?.height || window.innerHeight) - 104;
    const surface = editor.closest('.rich-editor-surface') || editor;
    const rect = surface.getBoundingClientRect();
    const form = editor.closest('.composer-form');
    if (form && rect.top < visibleTop) form.scrollBy({ top: rect.top - visibleTop, behavior: 'auto' });
    else if (form && rect.bottom > visibleBottom) form.scrollBy({ top: rect.bottom - visibleBottom, behavior: 'auto' });
    storeSelection(editor, saved);
  }

  function renderEditorContent(editor, selection = null) {
    const state = ensureState(editor);
    if (state.composing) return;
    const characters = points(state.text);
    const fragment = document.createDocumentFragment();
    let start = 0;
    while (start < characters.length) {
      const style = state.styles[start] || DEFAULT_STYLE;
      let end = start + 1;
      while (end < characters.length
        && (state.styles[end]?.bold === true) === (style.bold === true)
        && (state.styles[end]?.color || 'default') === (style.color || 'default')) end += 1;
      const segment = document.createTextNode(characters.slice(start, end).join(''));
      let node = segment;
      if (style.color !== 'default') {
        const color = document.createElement('span');
        color.className = `znews-text-color-${style.color}`;
        color.appendChild(node);
        node = color;
      }
      if (style.bold) {
        const strong = document.createElement('strong');
        strong.appendChild(node);
        node = strong;
      }
      if (!style.bold && style.color === 'default') {
        const plain = document.createElement('span');
        plain.dataset.znewsPlain = '';
        plain.appendChild(node);
        node = plain;
      }
      fragment.appendChild(node);
      start = end;
    }
    editor.replaceChildren(fragment);
    if (selection) restoreSelection(editor, selection);
  }

  function syncFromEditor(editor, { updateControls = true } = {}) {
    const state = ensureState(editor);
    if (state.composing) return;
    const selected = editorSelection(editor) || state.selection;
    const snapshot = readEditorContent(editor);
    state.text = snapshot.text;
    state.styles = snapshot.styles;
    storeSelection(editor, selected);
    if (updateControls) updateToolbar(editor);
  }

  function finishComposition(editor) {
    const state = ensureState(editor);
    state.composing = false;
    syncFromEditor(editor);
    editor.dispatchEvent(new Event('znews:editor-sync', { bubbles: true }));
    state.ignoreNextCompositionInput = true;
    window.setTimeout(() => { state.ignoreNextCompositionInput = false; }, 0);
  }

  function insertPlainText(editor, value) {
    const selection = window.getSelection();
    if (!selectionInside(editor, selection)) return;
    const range = selection.getRangeAt(0);
    range.deleteContents();
    const node = document.createTextNode(text(value).replace(/\r\n?/g, '\n'));
    range.insertNode(node);
    range.setStartAfter(node);
    range.collapse(true);
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function sameStyle(left, right) {
    return (left?.bold === true) === (right?.bold === true)
      && (left?.color || 'default') === (right?.color || 'default');
  }

  function atFormattingBoundary(editor) {
    const state = ensureState(editor);
    const selected = editorSelection(editor);
    if (!selected || selected.start !== selected.end) return false;
    const index = points(state.text.slice(0, selected.start)).length;
    const before = index > 0 ? state.styles[index - 1] : DEFAULT_STYLE;
    const after = index < state.styles.length ? state.styles[index] : DEFAULT_STYLE;
    return !sameStyle(before, after);
  }

  function isEditorElement(editor) {
    return editor instanceof HTMLElement && editor.isContentEditable;
  }

  function ensureState(editor) {
    let state = editorStates.get(editor);
    if (state) return state;
    const snapshot = readEditorContent(editor);
    state = {
      text: snapshot.text,
      styles: snapshot.styles,
      toolbar: null,
      selection: { start: 0, end: 0, direction: 'none' },
      composing: false,
      ignoreNextCompositionInput: false,
      bound: false
    };
    editorStates.set(editor, state);
    return state;
  }

  function setEditorContent(editor, content = '', formattingRuns = [], boldRanges = []) {
    if (!isEditorElement(editor)) return;
    const plain = text(content);
    const state = ensureState(editor);
    state.text = plain;
    state.styles = stylesFor(plain, formattingRuns, boldRanges);
    state.selection = { start: 0, end: 0, direction: 'none' };
    state.composing = false;
    state.ignoreNextCompositionInput = false;
    renderEditorContent(editor);
    updateToolbar(editor);
    editor.dispatchEvent(new Event('znews:format-change', { bubbles: true }));
  }

  function getEditorPayload(editor) {
    if (!isEditorElement(editor)) return { text: '', formattingRuns: [], boldRanges: [] };
    const state = ensureState(editor);
    if (!state.composing) syncFromEditor(editor, { updateControls: false });
    const all = points(state.text.replace(/\r\n?/g, '\n'));
    let start = 0;
    let end = all.length;
    while (start < end && /\s/u.test(all[start])) start += 1;
    while (end > start && /\s/u.test(all[end - 1])) end -= 1;
    const plain = all.slice(start, end).join('');
    const runs = runsFromStyles(state.styles.slice(start, end));
    return { text: plain, formattingRuns: runs, boldRanges: boldRangesFromRuns(runs) };
  }

  function applyStyle(editor, update, savedSelection = null) {
    if (!isEditorElement(editor)) return;
    const state = ensureState(editor);
    if (state.composing) return;
    const codeSelection = savedSelection || resolvedSelection(editor);
    syncFromEditor(editor, { updateControls: false });
    const selection = {
      start: points(state.text.slice(0, codeSelection.start)).length,
      end: points(state.text.slice(0, codeSelection.end)).length
    };
    if (selection.start === selection.end) {
      updateToolbar(editor);
      return;
    }
    for (let index = selection.start; index < selection.end; index += 1) {
      state.styles[index] = update({ ...(state.styles[index] || { bold: false, color: 'default' }) });
    }
    const start = pointToCodeUnit(state.text, selection.start);
    const end = pointToCodeUnit(state.text, selection.end);
    state.selection = { start, end, direction: codeSelection.direction || 'none' };
    renderEditorContent(editor, state.selection);
    updateToolbar(editor);
    keepSelectionVisible(editor, state.selection);
    editor.dispatchEvent(new Event('znews:format-change', { bubbles: true }));
  }

  function toggleBold(editor) {
    const state = ensureState(editor);
    const saved = resolvedSelection(editor);
    const selection = {
      start: points(state.text.slice(0, saved.start)).length,
      end: points(state.text.slice(0, saved.end)).length
    };
    const selected = state.styles.slice(selection.start, selection.end);
    const shouldBold = selected.length > 0 && !selected.every((style) => style.bold === true);
    applyStyle(editor, (style) => ({ ...style, bold: shouldBold }), saved);
  }

  function applyColor(editor, color) {
    const safeColor = COLOR_IDS.includes(color) ? color : 'default';
    applyStyle(editor, (style) => ({ ...style, color: safeColor }), resolvedSelection(editor));
  }

  function selectedStyle(editor) {
    const state = ensureState(editor);
    const saved = resolvedSelection(editor);
    const selection = {
      start: points(state.text.slice(0, saved.start)).length,
      end: points(state.text.slice(0, saved.end)).length
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

  function updateToolbar(editor) {
    const state = editorStates.get(editor);
    const toolbar = state?.toolbar;
    if (!toolbar || state.composing) return;
    const selected = selectedStyle(editor);
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

  function getEditorElement(editor) {
    return isEditorElement(editor) ? editor : null;
  }

  function closePalette(toolbar, { restore = false } = {}) {
    const toggle = toolbar?.querySelector('[data-format-color-toggle]');
    const palette = toolbar?.querySelector('[data-format-palette]');
    if (!toggle || !palette) return;
    palette.hidden = true;
    toolbar.classList.remove('palette-open');
    toggle.setAttribute('aria-expanded', 'false');
    if (restore && toolbar._znewsEditor) restoreSelection(toolbar._znewsEditor);
  }

  function bindToolbar(editor, toolbar) {
    if (!isEditorElement(editor) || !(toolbar instanceof HTMLElement)) return;
    const state = ensureState(editor);
    if (state.bound) return;
    state.bound = true;
    state.toolbar = toolbar;
    toolbar._znewsEditor = editor;
    bindVisualViewport();
    toolbar.querySelectorAll('button').forEach((button) => {
      button.addEventListener('pointerdown', (event) => {
        rememberSelection(editor);
        event.preventDefault();
      });
      button.addEventListener('touchstart', () => rememberSelection(editor), { passive: true });
    });
    toolbar.querySelector('[data-format-bold]')?.addEventListener('click', () => {
      restoreSelection(editor);
      toggleBold(editor);
    });
    toolbar.querySelector('[data-format-color-toggle]')?.addEventListener('click', () => {
      const saved = resolvedSelection(editor);
      const palette = toolbar.querySelector('[data-format-palette]');
      const open = palette.hidden;
      document.querySelectorAll('[data-format-palette]:not([hidden])').forEach((item) => {
        if (item !== palette) closePalette(item.closest('.composer-format-toolbar'));
      });
      palette.hidden = !open;
      toolbar.classList.toggle('palette-open', open);
      toolbar.querySelector('[data-format-color-toggle]').setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        restoreSelection(editor, saved);
        keepSelectionVisible(editor, saved);
      }
    });
    toolbar.querySelectorAll('[data-format-color]').forEach((button) => {
      button.addEventListener('click', () => {
        restoreSelection(editor);
        applyColor(editor, text(button.dataset.formatColor));
        closePalette(toolbar);
      });
    });
    document.addEventListener('selectionchange', () => {
      if (state.composing || !selectionInside(editor, window.getSelection())) return;
      rememberSelection(editor);
      updateToolbar(editor);
    });
    editor.addEventListener('compositionstart', () => {
      state.composing = true;
      state.ignoreNextCompositionInput = false;
      rememberSelection(editor);
    });
    editor.addEventListener('compositionupdate', () => {});
    editor.addEventListener('compositionend', () => {
      finishComposition(editor);
    });
    editor.addEventListener('beforeinput', (event) => {
      if (state.composing || event.isComposing) return;
      if (!state.ignoreNextCompositionInput
        && (event.inputType === 'insertText' || event.inputType === 'insertReplacementText')
        && typeof event.data === 'string'
        && atFormattingBoundary(editor)) {
        event.preventDefault();
        insertPlainText(editor, event.data);
        syncFromEditor(editor);
        editor.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: event.inputType, data: event.data }));
        return;
      }
      if (event.inputType === 'insertParagraph' || event.inputType === 'insertLineBreak') {
        event.preventDefault();
        insertPlainText(editor, '\n');
        syncFromEditor(editor);
        editor.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: event.inputType, data: null }));
      }
    });
    editor.addEventListener('input', (event) => {
      if (state.composing || event.isComposing) return;
      if (state.ignoreNextCompositionInput && event.inputType === 'insertCompositionText') {
        state.ignoreNextCompositionInput = false;
        return;
      }
      syncFromEditor(editor);
    });
    editor.addEventListener('paste', (event) => {
      event.preventDefault();
      insertPlainText(editor, event.clipboardData?.getData('text/plain') || '');
      syncFromEditor(editor);
      editor.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromPaste', data: null }));
    });
    editor.addEventListener('drop', (event) => {
      event.preventDefault();
      insertPlainText(editor, event.dataTransfer?.getData('text/plain') || '');
      syncFromEditor(editor);
      editor.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertFromDrop', data: null }));
    });
    ['keyup', 'click', 'focus'].forEach((name) => editor.addEventListener(name, () => {
      if (state.composing) return;
      rememberSelection(editor);
      updateToolbar(editor);
    }));
    editor.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closePalette(toolbar, { restore: true });
    });
    updateToolbar(editor);
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
    getEditorElement,
    bindToolbar,
    toggleBold,
    applyColor,
    updateToolbar
  });
  window.ZNewsCategoryPicker = Object.freeze({ bind: bindCategoryPicker, set: setCategory, close: closeCategoryPicker });
  window.ZNewsUiFeedback = Object.freeze({ beginProgress, setButtonLoading });
})();
