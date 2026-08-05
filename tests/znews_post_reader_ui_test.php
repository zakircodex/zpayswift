<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function reader_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function reader_source(string $relative): string
{
    global $root;
    $path = $root . '/' . $relative;
    reader_expect(is_file($path), "Missing file: {$relative}");
    $source = file_get_contents($path);
    reader_expect(is_string($source), "Could not read: {$relative}");
    return (string)$source;
}

$index = reader_source('znews/index.html');
$reader = reader_source('znews/assets/znews-reader.js');
$readerCss = reader_source('znews/assets/znews-reader.css');
$instant = reader_source('znews/assets/znews-instant-comments.js');
$bootstrap = reader_source('znews/assets/znews-bootstrap.js');
$serviceWorker = reader_source('znews/sw.js');

reader_expect(str_contains($index, 'interactive-widget=resizes-content'), 'Android keyboard resize viewport mode is missing.');
reader_expect(str_contains($index, 'znews-reader.css?v=2'), 'Latest reader stylesheet is not activated.');
reader_expect(str_contains($index, 'class="post-reader-shell"'), 'Post reader shell is missing.');
reader_expect(str_contains($index, 'class="post-reader-header"'), 'Sticky post reader header is missing.');
reader_expect(str_contains($index, 'id="postDialogClose"'), 'Reader Back control is missing.');
reader_expect(str_contains($index, 'id="postReaderScroll"'), 'Dedicated reader scroll area is missing.');
reader_expect(str_contains($index, 'class="comments-heading"'), 'Compact comments heading is missing.');
reader_expect(str_contains($index, 'class="comment-dock"'), 'Bottom comment dock is missing.');
reader_expect(str_contains($index, 'id="commentComposerAvatar"'), 'Composer profile avatar is missing.');
reader_expect(str_contains($index, '<textarea id="commentText"'), 'Keyboard-friendly comment textarea is missing.');
reader_expect(str_contains($index, 'class="comment-send-button"'), 'Compact send icon button is missing.');
reader_expect(str_contains($index, 'id="commentGuestCta"'), 'Guest comment CTA is missing.');
reader_expect(str_contains($index, 'Join Z-Pay to comment'), 'Guest comment CTA wording is missing.');
reader_expect(!str_contains($index, '<button class="primary-button compact" type="submit">Send</button>'), 'Legacy oversized Send button remains.');
reader_expect(str_contains($index, 'znews-bootstrap.js?v=20'), 'Reload-safe reader bootstrap version is not activated.');

reader_expect(str_contains($reader, "wrapApiMethod('comments'"), 'Reader does not capture comment pagination responses.');
reader_expect(str_contains($reader, "wrapApiMethod('publicPost'"), 'Reader title is not connected to the opened post.');
reader_expect(str_contains($reader, 'mergeComments('), 'Comment ID deduplication is missing.');
reader_expect(str_contains($reader, 'commentLoadMoreButton'), 'View-more-comments control is missing.');
reader_expect(str_contains($reader, 'api.comments(postId, state.nextCursor)'), 'Comment pagination is not wired.');
reader_expect(str_contains($reader, 'state.openedFromFeed = true'), 'Reader does not remember feed-origin navigation.');
reader_expect(str_contains($reader, 'znewsFeedScrollY'), 'Feed scroll position is not preserved.');
reader_expect(str_contains($reader, 'window.history.back()'), 'Reader Back does not consume browser history.');
reader_expect(str_contains($reader, "window.addEventListener('popstate'"), 'Browser/gesture Back handling is missing.');
reader_expect(str_contains($reader, "input.addEventListener('keydown'"), 'Mobile keyboard submit handling is missing.');
reader_expect(str_contains($reader, '!event.shiftKey && !event.isComposing'), 'Enter/newline composition safety is missing.');
reader_expect(str_contains($reader, 'form.requestSubmit()'), 'Keyboard submit does not use the guarded form flow.');
reader_expect(str_contains($reader, 'guestCta.hidden = authenticated'), 'Guest/creator composer visibility is not enforced.');
reader_expect(str_contains($reader, 'window.visualViewport?.addEventListener'), 'Visual viewport resize tracking is missing.');
reader_expect(str_contains($reader, "window.visualViewport?.addEventListener('scroll'"), 'Visual viewport pan tracking is missing.');
reader_expect(str_contains($reader, '--znews-reader-vv-height'), 'Visual viewport height is not passed to CSS.');
reader_expect(str_contains($reader, '--znews-reader-vv-top'), 'Visual viewport top offset is not passed to CSS.');
reader_expect(str_contains($reader, '--znews-reader-vv-width'), 'Visual viewport width is not passed to CSS.');
reader_expect(str_contains($reader, 'lockUnderlyingPage()'), 'Underlying feed page is not locked while commenting.');
reader_expect(str_contains($reader, '--znews-reader-page-top'), 'Feed scroll lock offset is missing.');
reader_expect(str_contains($reader, "input.addEventListener('focus'"), 'Composer focus viewport refresh is missing.');
reader_expect(str_contains($reader, "input.addEventListener('blur'"), 'Composer blur viewport restore is missing.');
reader_expect(!str_contains($reader, 'Reply'), 'UI must not expose a fake Reply action without backend support.');

reader_expect(str_contains($instant, 'appendPublishedComment(comment)'), 'Published comments are not appended immediately.');
reader_expect(str_contains($instant, "new CustomEvent('znews:comment-created'"), 'Instant comment event is missing.');
reader_expect(!str_contains($instant, 'await api.comments(postId)'), 'Comment submit still reloads the complete comment list.');
reader_expect(strpos($instant, "input.value = ''") > strpos($instant, 'await api.createComment'), 'Input is cleared before the server accepts the comment.');
reader_expect(str_contains($instant, "button.dataset.sending = sending ? 'true' : 'false'"), 'Duplicate-send busy state is missing.');
reader_expect(str_contains($instant, 'updateCommentCount'), 'Instant comment count reconciliation is missing.');

reader_expect(str_contains($readerCss, 'body.znews-post-reader-open{position:fixed'), 'Body-level scroll lock is missing.');
reader_expect(str_contains($readerCss, 'width:var(--znews-reader-vv-width,100%)'), 'Mobile reader width does not follow the visual viewport.');
reader_expect(str_contains($readerCss, 'height:var(--znews-reader-vv-height,100dvh)'), 'Mobile reader height does not follow the visual viewport.');
reader_expect(str_contains($readerCss, 'top:var(--znews-reader-vv-top,0px)'), 'Mobile reader top does not follow keyboard pan offset.');
reader_expect(!str_contains($readerCss, 'width:100vw'), '100vw clipping regression remains in the mobile reader.');
reader_expect(str_contains($readerCss, 'max-inline-size:none'), 'Browser dialog inline-size cap is not reset.');
reader_expect(str_contains($readerCss, 'overflow-x:hidden'), 'Reader horizontal overflow is not blocked.');
reader_expect(str_contains($readerCss, '-webkit-overflow-scrolling:touch'), 'Mobile momentum scrolling is missing.');
reader_expect(str_contains($readerCss, 'touch-action:pan-y'), 'Reader vertical touch scrolling is not explicit.');
reader_expect(str_contains($readerCss, '.post-reader-header'), 'Reader header styles are missing.');
reader_expect(str_contains($readerCss, '.post-reader-scroll'), 'Independent reader scrolling is missing.');
reader_expect(str_contains($readerCss, '.comment-bubble'), 'Facebook-style comment bubble styles are missing.');
reader_expect(str_contains($readerCss, '.comment-action-row'), 'Comment metadata row styles are missing.');
reader_expect(str_contains($readerCss, '.comment-dock'), 'Keyboard-safe bottom dock styles are missing.');
reader_expect(str_contains($readerCss, '.comment-input-shell:focus-within'), 'Composer focus state is missing.');
reader_expect(str_contains($readerCss, '.comment-send-button.is-ready:not(:disabled)'), 'Active send-button state is missing.');
reader_expect(str_contains($readerCss, '#postDetail>.ad-slot:empty{display:none}'), 'Failed/empty reader ads leave a blank gap.');
reader_expect(str_contains($readerCss, '#postDetail .post-media'), 'Full reader media style is missing.');
reader_expect(str_contains($readerCss, 'object-fit:contain'), 'Reader media is still cropped.');

reader_expect(str_contains($bootstrap, 'znews-reader.js?v=3'), 'Latest reader interaction module is not loaded.');
reader_expect(strpos($bootstrap, 'znews-reader.js?v=3') < strpos($bootstrap, 'znews.js?v=18'), 'Reader API capture must load before the main app.');
reader_expect(str_contains($bootstrap, 'znews-instant-comments.js?v=4'), 'Updated instant-comment module is not loaded.');
reader_expect(str_contains($bootstrap, 'void prepareServiceWorker();'), 'Reader shell refresh is not started before app boot.');

reader_expect(str_contains($serviceWorker, "const CACHE_NAME = 'zsky24-embedded-shell-v14'"), 'Mobile viewport PWA cache version is stale.');
reader_expect(str_contains($serviceWorker, 'znews-reader.css?v=2'), 'Latest reader CSS is missing from the shell cache.');
reader_expect(str_contains($serviceWorker, 'znews-reader.js?v=3'), 'Latest reader JS is missing from the shell cache.');
reader_expect(str_contains($serviceWorker, 'znews-bootstrap.js?v=20'), 'Reload-safe bootstrap is missing from the shell cache.');
reader_expect(str_contains($serviceWorker, 'znews-instant-comments.js?v=4'), 'Latest comment module is missing from the shell cache.');
reader_expect(str_contains($serviceWorker, 'networkFirst(request'), 'Reader shell may serve stale JavaScript while online.');

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    foreach (['znews/assets/znews-reader.js', 'znews/assets/znews-instant-comments.js', 'znews/assets/znews-bootstrap.js'] as $relative) {
        $command = escapeshellarg($node) . ' --check ' . escapeshellarg($root . '/' . $relative) . ' 2>&1';
        exec($command, $output, $status);
        reader_expect($status === 0, "JavaScript syntax failed: {$relative} " . implode("\n", $output));
        $output = [];
    }
}

echo "PASS: {$assertions} Z News post reader UI assertions.\n";
