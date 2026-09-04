'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '..');

function editorMarkup(id) {
  return `<section class="composer-field composer-body-field">
    <label for="${id}">Post details</label>
    <div class="composer-format-toolbar" id="${id}Toolbar" role="toolbar">
      <button type="button" data-format-bold aria-pressed="false"><strong>B</strong></button>
      <button type="button" data-format-color-toggle data-color="default" aria-pressed="false" aria-expanded="false"><strong>A</strong></button>
      <div data-format-palette hidden>
        <button type="button" data-format-color="default" aria-checked="true">Default</button>
        <button type="button" data-format-color="green" aria-checked="false">Green</button>
      </div>
    </div>
    <textarea id="${id}" maxlength="5000" placeholder="Write the story or update"></textarea>
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
  const context = await browser.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await context.newPage();

  try {
    await page.goto(`http://127.0.0.1:${server.address().port}/`, { waitUntil: 'networkidle' });
    await page.waitForFunction(() => Boolean(window.ZNewsRichText));
    await page.evaluate(() => {
      ['createText', 'editText'].forEach((id) => {
        const textarea = document.querySelector(`#${id}`);
        window.ZNewsRichText.setEditorContent(textarea, '');
        window.ZNewsRichText.bindToolbar(textarea, document.querySelector(`#${id}Toolbar`));
      });
    });

    const composition = await page.evaluate(async () => {
      const rich = window.ZNewsRichText;
      const run = async (id, phrase) => {
        const textarea = document.querySelector(`#${id}`);
        const editor = rich.getEditorElement(textarea);
        const bold = document.querySelector(`#${id}Toolbar [data-format-bold]`);
        editor.focus();
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(editor);
        range.collapse(false);
        selection.removeAllRanges();
        selection.addRange(range);
        editor.dispatchEvent(new CompositionEvent('compositionstart', { bubbles: true, data: '' }));
        const inserted = document.createTextNode(phrase);
        range.insertNode(inserted);
        range.setStartAfter(inserted);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
        editor.dispatchEvent(new CompositionEvent('compositionupdate', { bubbles: true, data: phrase }));
        editor.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertCompositionText', data: phrase, isComposing: true }));
        const during = { value: textarea.value, pressed: bold.getAttribute('aria-pressed'), insertedPreserved: editor.firstChild === inserted };
        editor.dispatchEvent(new CompositionEvent('compositionend', { bubbles: true, data: phrase }));
        await Promise.resolve();
        await Promise.resolve();
        return {
          during,
          after: rich.getEditorPayload(textarea),
          insertedPreserved: editor.firstChild === inserted,
          sameEditor: rich.getEditorElement(textarea) === editor
        };
      };
      return {
        create: await run('createText', 'আমার সোনার বাংলা আমি তোমায় ভালোবাসি'),
        edit: await run('editText', 'Hi welcome to Malaysia আমার সোনার বাংলা')
      };
    });
    assert.equal(composition.create.during.value, '', 'Create synced the model during IME composition.');
    assert.equal(composition.edit.during.value, '', 'Edit synced the model during IME composition.');
    assert.equal(composition.create.during.pressed, 'false', 'Toolbar mutated during IME composition.');
    assert.equal(composition.create.insertedPreserved && composition.create.sameEditor, true, 'Create rebuilt its editor DOM after composition.');
    assert.equal(composition.edit.insertedPreserved && composition.edit.sameEditor, true, 'Edit rebuilt its editor DOM after composition.');
    assert.equal(composition.create.after.text, 'আমার সোনার বাংলা আমি তোমায় ভালোবাসি');
    assert.equal(composition.edit.after.text, 'Hi welcome to Malaysia আমার সোনার বাংলা');

    const formatAndType = await page.evaluate(async () => {
      const rich = window.ZNewsRichText;
      const textarea = document.querySelector('#createText');
      const editor = rich.getEditorElement(textarea);
      const setRange = (start, end = start) => {
        const walker = document.createTreeWalker(editor, NodeFilter.SHOW_TEXT);
        const nodes = [];
        let node;
        while ((node = walker.nextNode())) nodes.push(node);
        const point = (offset) => {
          let remaining = offset;
          for (const item of nodes) {
            if (remaining < item.nodeValue.length) return [item, remaining];
            if (remaining === item.nodeValue.length) {
              const next = nodes[nodes.indexOf(item) + 1];
              return next ? [next, 0] : [item, item.nodeValue.length];
            }
            remaining -= item.nodeValue.length;
          }
          return [editor, editor.childNodes.length];
        };
        const [startNode, startOffset] = point(start);
        const [endNode, endOffset] = point(end);
        const range = document.createRange();
        range.setStart(startNode, startOffset);
        range.setEnd(endNode, endOffset);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        editor.focus();
        document.dispatchEvent(new Event('selectionchange'));
      };
      const typeText = (value) => {
        const event = new InputEvent('beforeinput', { bubbles: true, cancelable: true, inputType: 'insertText', data: value });
        editor.dispatchEvent(event);
        if (!event.defaultPrevented) document.execCommand('insertText', false, value);
      };

      const original = 'Hi welcome to Malaysia আমার সোনার বাংলা';
      rich.setEditorContent(textarea, original);
      const start = original.indexOf('Malaysia');
      const end = start + 'Malaysia'.length;
      setRange(start, end);
      rich.toggleBold(textarea);
      rich.applyColor(textarea, 'green');
      const formattedNode = editor.querySelector('strong');
      const formattedHtml = editor.innerHTML;
      setRange(end);
      typeText(' সুন্দর');
      await Promise.resolve();
      const typed = rich.getEditorPayload(textarea);
      const nodePreservedWhileTyping = editor.querySelector('strong') === formattedNode;
      const typedHtml = editor.innerHTML;

      rich.setEditorContent(textarea, 'A word B');
      setRange(2, 6);
      rich.toggleBold(textarea);
      const boundaryNode = editor.querySelector('strong');
      setRange(6);
      typeText(' ');
      document.execCommand('delete', false);
      const spacing = rich.getEditorPayload(textarea);
      const boundaryPreserved = editor.querySelector('strong') === boundaryNode;

      rich.setEditorContent(textarea, 'abCD');
      setRange(2, 4);
      rich.applyColor(textarea, 'green');
      setRange(2);
      document.execCommand('delete', false);
      const backspaceBoundary = rich.getEditorPayload(textarea);

      rich.setEditorContent(textarea, 'লাইন এক');
      setRange(textarea.value.length);
      editor.dispatchEvent(new InputEvent('beforeinput', { bubbles: true, cancelable: true, inputType: 'insertParagraph' }));
      typeText('লাইন দুই। 🎉');
      const multiline = rich.getEditorPayload(textarea);
      const computed = getComputedStyle(editor);
      const inlineDisplays = [...editor.querySelectorAll('strong,span')].map((item) => getComputedStyle(item).display);
      return { typed, nodePreservedWhileTyping, formattedHtml, typedHtml, spacing, boundaryPreserved, backspaceBoundary, multiline, whiteSpace: computed.whiteSpace, wordBreak: computed.wordBreak, overflowWrap: computed.overflowWrap, lineHeight: computed.lineHeight, inlineDisplays };
    });
    assert.equal(formatAndType.typed.text, 'Hi welcome to Malaysia সুন্দর আমার সোনার বাংলা');
    assert.deepEqual(formatAndType.typed.formattingRuns, [{ start: 14, end: 22, bold: true, color: 'green' }]);
    assert.equal(formatAndType.nodePreservedWhileTyping, true, `Normal typing rebuilt existing formatting DOM: ${formatAndType.formattedHtml} -> ${formatAndType.typedHtml}`);
    assert.equal(formatAndType.spacing.text, 'A word B', 'Space insertion/deletion around a formatted word was not exact.');
    assert.equal(formatAndType.boundaryPreserved, true, 'Boundary typing replaced the formatted span.');
    assert.equal(formatAndType.backspaceBoundary.text, 'aCD', 'Backspace did not cross the formatting boundary naturally.');
    assert.equal(formatAndType.multiline.text, 'লাইন এক\nলাইন দুই। 🎉', 'Enter, Bangla punctuation, or emoji was altered.');
    assert.equal(formatAndType.whiteSpace, 'pre-wrap');
    assert.equal(formatAndType.wordBreak, 'normal');
    assert.equal(formatAndType.overflowWrap, 'break-word');
    assert.equal(Number.parseFloat(formatAndType.lineHeight) >= 24, true, 'Editor line height is not comfortable for mobile typing.');
    assert.equal(formatAndType.inlineDisplays.every((display) => display === 'inline'), true, 'Formatting spans are not true inline elements.');

    process.stdout.write('PASS: Z Sky rich editor IME/composition and caret-boundary assertions.\n');
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
