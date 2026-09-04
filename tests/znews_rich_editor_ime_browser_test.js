'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');

function editorMarkup(id) {
  return `<section class="composer-field composer-body-field" id="${id}Field">
    <span class="composer-field-label" id="${id}Label">Post details</span>
    <div class="composer-format-toolbar" id="${id}Toolbar" role="toolbar">
      <button type="button" data-format-bold aria-pressed="false"><strong>B</strong></button>
      <button type="button" data-format-color-toggle data-color="default" aria-pressed="false" aria-expanded="false"><strong>A</strong></button>
      <div data-format-palette hidden>
        <button type="button" data-format-color="default" aria-checked="true">Default</button>
        <button type="button" data-format-color="green" aria-checked="false">Green</button>
      </div>
    </div>
    <div class="rich-editor-editable" id="${id}" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="${id}Label" data-placeholder="Write the story or update…" data-maxlength="5000" spellcheck="true" dir="auto"></div>
  </section>`;
}

const pageHtml = `<!doctype html><html><head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/znews/assets/znews.css">
  <link rel="stylesheet" href="/znews/assets/znews-premium.css">
  <script defer src="/znews/assets/znews-rich-editor.js"></script>
</head><body><main>${editorMarkup('createText')}${editorMarkup('editText')}</main></body></html>`;

function contentType(file) {
  if (file.endsWith('.js')) return 'application/javascript; charset=utf-8';
  if (file.endsWith('.css')) return 'text/css; charset=utf-8';
  return 'application/octet-stream';
}

async function main() {
  const server = http.createServer((request, response) => {
    const pathname = new URL(request.url, 'http://127.0.0.1').pathname;
    if (pathname === '/') {
      response.writeHead(200, {
        'Content-Type': 'text/html; charset=utf-8',
        'Cache-Control': 'no-store',
        'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self'"
      });
      response.end(pageHtml);
      return;
    }
    const file = path.resolve(root, pathname.replace(/^\/+/, ''));
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file)) {
      response.writeHead(404);
      response.end();
      return;
    }
    response.writeHead(200, { 'Content-Type': contentType(file), 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(response);
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ headless: true, channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
  const context = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2.75,
    hasTouch: true,
    isMobile: true,
    serviceWorkers: 'block',
    userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/140.0 Mobile Safari/537.36'
  });
  const page = await context.newPage();

  try {
    await page.goto(`http://127.0.0.1:${server.address().port}/`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => Boolean(window.ZNewsRichText));
    await page.evaluate(() => {
      window.__setRichSelection = (editor, requestedStart, requestedEnd = requestedStart) => {
        const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
        const nodes = [];
        let node;
        while ((node = walker.nextNode())) nodes.push(node);
        const point = (requested) => {
          let remaining = Math.max(0, requested);
          for (let index = 0; index < nodes.length; index += 1) {
            const item = nodes[index];
            if (remaining < item.nodeValue.length) return [item, remaining];
            if (remaining === item.nodeValue.length) {
              return nodes[index + 1] ? [nodes[index + 1], 0] : [item, item.nodeValue.length];
            }
            remaining -= item.nodeValue.length;
          }
          return [editor, editor.childNodes.length];
        };
        const [startNode, startOffset] = point(requestedStart);
        const [endNode, endOffset] = point(requestedEnd);
        const range = document.createRange();
        range.setStart(startNode, startOffset);
        range.setEnd(endNode, endOffset);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        editor.focus();
        document.dispatchEvent(new Event('selectionchange'));
      };
      ['createText', 'editText'].forEach((id) => {
        const editor = document.querySelector(`#${id}`);
        window.ZNewsRichText.setEditorContent(editor, '');
        window.ZNewsRichText.bindToolbar(editor, document.querySelector(`#${id}Toolbar`));
      });
    });

    const surfaceAudit = await page.evaluate(() => ['createText', 'editText'].map((id) => {
      const field = document.querySelector(`#${id}Field`);
      const editor = document.querySelector(`#${id}`);
      const toolbar = document.querySelector(`#${id}Toolbar`);
      const style = getComputedStyle(editor);
      return {
        editableCount: field.querySelectorAll('[contenteditable="true"],textarea,input:not([type="hidden"])').length,
        textareas: field.querySelectorAll('textarea').length,
        activeTarget: window.ZNewsRichText.getEditorElement(editor) === editor,
        toolbarBeforeEditor: toolbar.compareDocumentPosition(editor) === Node.DOCUMENT_POSITION_FOLLOWING,
        border: style.borderTopWidth,
        minHeight: Number.parseFloat(style.minHeight),
        display: style.display
      };
    }));
    surfaceAudit.forEach((audit) => {
      assert.deepEqual(audit, {
        editableCount: 1,
        textareas: 0,
        activeTarget: true,
        toolbarBeforeEditor: true,
        border: '0px',
        minHeight: 156,
        display: 'block'
      }, 'Create/Edit does not use exactly one borderless editable surface.');
    });

    const compose = async (id, intended) => page.evaluate(({ editorId, value }) => {
      const rich = window.ZNewsRichText;
      const editor = document.querySelector(`#${editorId}`);
      rich.setEditorContent(editor, '');
      window.__setRichSelection(editor, 0);
      let syncCount = 0;
      editor.addEventListener('znews:editor-sync', () => { syncCount += 1; }, { once: true });
      editor.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true, data: '' }));
      editor.dispatchEvent(new CompositionEvent('compositionupdate', { bubbles: true, data: value }));
      editor.dispatchEvent(new InputEvent('beforeinput', {
        bubbles: true, cancelable: true, inputType: 'insertCompositionText', data: value, isComposing: true
      }));
      const selection = window.getSelection();
      const range = selection.getRangeAt(0);
      const composedNode = document.createTextNode(value);
      range.insertNode(composedNode);
      range.setStartAfter(composedNode);
      range.collapse(true);
      selection.removeAllRanges();
      selection.addRange(range);
      editor.dispatchEvent(new InputEvent('input', {
        bubbles: true, inputType: 'insertCompositionText', data: value, isComposing: true
      }));
      const during = rich.getEditorPayload(editor).text;
      editor.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: value }));
      editor.dispatchEvent(new InputEvent('input', {
        bubbles: true, inputType: 'insertCompositionText', data: value, isComposing: false
      }));
      return {
        during,
        text: rich.getEditorPayload(editor).text,
        domText: editor.textContent,
        syncCount,
        nodePreserved: editor.firstChild === composedNode,
        activeTarget: document.activeElement === editor
      };
    }, { editorId: id, value: intended });

    for (const [id, intended] of [
      ['createText', 'আমি বাংলাদেশে থাকি'],
      ['editText', 'Malaysia আমার দেশ নয়']
    ]) {
      const result = await compose(id, intended);
      assert.equal(result.during, '', `${id} synchronized while composition was active.`);
      assert.equal(result.text, intended, `${id} duplicated or changed composition text.`);
      assert.equal(result.domText, intended, `${id} DOM does not match composition text.`);
      assert.equal(result.syncCount, 1, `${id} did not commit composition exactly once.`);
      assert.equal(result.nodePreserved, true, `${id} rebuilt the DOM at composition end.`);
      assert.equal(result.activeTarget, true, `${id} moved focus to another typing target.`);
    }

    const typeExactly = async (id, intended, delay = 0) => {
      await page.evaluate((editorId) => window.ZNewsRichText.setEditorContent(document.querySelector(`#${editorId}`), ''), id);
      await page.locator(`#${id}`).focus();
      await page.locator(`#${id}`).pressSequentially(intended, { delay });
      return page.evaluate((editorId) => {
        const editor = document.querySelector(`#${editorId}`);
        return { payload: window.ZNewsRichText.getEditorPayload(editor).text, dom: editor.textContent };
      }, id);
    };
    for (const [id, intended, delay] of [
      ['createText', 'Hi welcome to Malaysia', 12],
      ['editText', 'Jsssjzzb test typing', 0]
    ]) {
      const result = await typeExactly(id, intended, delay);
      assert.deepEqual(result, { payload: intended, dom: intended }, `${id} character typing was duplicated or garbled.`);
    }

    const boundaryAudit = async (offset, inserted) => {
      const source = 'Hi Malaysia test';
      const start = source.indexOf('Malaysia');
      await page.evaluate(({ sourceText, rangeStart, rangeEnd, caretOffset }) => {
        const editor = document.querySelector('#createText');
        window.ZNewsRichText.setEditorContent(editor, sourceText);
        window.__setRichSelection(editor, rangeStart, rangeEnd);
        window.ZNewsRichText.toggleBold(editor);
        window.ZNewsRichText.applyColor(editor, 'green');
        window.__setRichSelection(editor, rangeStart + caretOffset);
      }, { sourceText: source, rangeStart: start, rangeEnd: start + 'Malaysia'.length, caretOffset: offset });
      await page.keyboard.type(inserted);
      return page.evaluate(() => {
        const editor = document.querySelector('#createText');
        return {
          text: window.ZNewsRichText.getEditorPayload(editor).text,
          styled: editor.querySelector('strong .znews-text-color-green')?.textContent || '',
          active: document.activeElement === editor
        };
      });
    };
    assert.deepEqual(await boundaryAudit(0, 'X'), { text: 'Hi XMalaysia test', styled: 'XMalaysia', active: true });
    assert.deepEqual(await boundaryAudit(3, 'X'), { text: 'Hi MalXaysia test', styled: 'MalXaysia', active: true });
    assert.deepEqual(await boundaryAudit('Malaysia'.length, 'X'), { text: 'Hi MalaysiaX test', styled: 'Malaysia', active: true });

    await page.evaluate(() => {
      const editor = document.querySelector('#editText');
      window.ZNewsRichText.setEditorContent(editor, 'abc def');
      window.__setRichSelection(editor, 3);
    });
    await page.keyboard.type('X');
    await page.keyboard.press('Backspace');
    await page.keyboard.press('Enter');
    await page.keyboard.type('next');
    assert.equal(await page.evaluate(() => window.ZNewsRichText.getEditorPayload(document.querySelector('#editText')).text), 'abc\nnext def', 'Middle caret, Backspace, or Enter moved unexpectedly.');

    const formatAudit = await page.evaluate(() => {
      const rich = window.ZNewsRichText;
      const editor = document.querySelector('#createText');
      const source = 'Hi welcome to Malaysia আমার সোনার বাংলা 🎉';
      const start = source.indexOf('Malaysia');
      rich.setEditorContent(editor, source);
      window.__setRichSelection(editor, start, start + 'Malaysia'.length);
      rich.toggleBold(editor);
      rich.applyColor(editor, 'green');
      const payload = rich.getEditorPayload(editor);
      return {
        text: payload.text,
        runs: payload.formattingRuns,
        styled: editor.querySelector('strong .znews-text-color-green')?.textContent || '',
        spansInline: [...editor.querySelectorAll('strong,span')].every((item) => getComputedStyle(item).display === 'inline'),
        whiteSpace: getComputedStyle(editor).whiteSpace,
        wordBreak: getComputedStyle(editor).wordBreak,
        overflowWrap: getComputedStyle(editor).overflowWrap
      };
    });
    assert.equal(formatAudit.text, 'Hi welcome to Malaysia আমার সোনার বাংলা 🎉');
    assert.deepEqual(formatAudit.runs, [{ start: 14, end: 22, bold: true, color: 'green' }]);
    assert.equal(formatAudit.styled, 'Malaysia');
    assert.equal(formatAudit.spansInline, true);
    assert.deepEqual([formatAudit.whiteSpace, formatAudit.wordBreak, formatAudit.overflowWrap], ['pre-wrap', 'normal', 'break-word']);

    for (const width of [320, 360, 390, 412, 430]) {
      await page.setViewportSize({ width, height: 844 });
      const layout = await page.evaluate(() => ['createText', 'editText'].map((id) => {
        const field = document.querySelector(`#${id}Field`);
        const editor = document.querySelector(`#${id}`);
        const toolbar = document.querySelector(`#${id}Toolbar`);
        const editorRect = editor.getBoundingClientRect();
        return {
          activeSurfaces: field.querySelectorAll('[contenteditable="true"],textarea').length,
          editorBelowToolbar: toolbar.compareDocumentPosition(editor) === Node.DOCUMENT_POSITION_FOLLOWING,
          editorInsideViewport: editorRect.left >= 0 && editorRect.right <= window.innerWidth,
          editorHeight: Math.round(editorRect.height),
          overflow: document.documentElement.scrollWidth > window.innerWidth
        };
      }));
      layout.forEach((audit) => {
        assert.equal(audit.activeSurfaces, 1, `Multiple editor surfaces at ${width}px.`);
        assert.equal(audit.editorBelowToolbar && audit.editorInsideViewport && !audit.overflow, true, `Editor layout split or overflowed at ${width}px: ${JSON.stringify(audit)}`);
        assert.equal(audit.editorHeight >= 156, true, `Editor is too short at ${width}px.`);
      });
    }

    process.stdout.write('PASS: Z Sky single-surface Android IME and exact typing assertions.\n');
  } finally {
    await context.close();
    await browser.close();
    await new Promise((resolve) => server.close(resolve));
  }
}

main().catch((error) => {
  process.stderr.write(`FAIL: ${error.stack || error.message}\n`);
  process.exit(1);
});
