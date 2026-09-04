<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$app = file_get_contents($root . '/znews/assets/znews.js');
$creator = file_get_contents($root . '/znews/assets/znews-creator.js');
$premium = file_get_contents($root . '/znews/assets/znews-premium.css');
$bootstrap = file_get_contents($root . '/znews/assets/znews-bootstrap.js');
$embeddedWorker = file_get_contents($root . '/znews/sw.js');
$standaloneWorker = file_get_contents($root . '/znews/sw-root.js');

function my_posts_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

my_posts_expect(str_contains($app, "openPost(route.id, { syncHistory: false })"), 'Popstate/deep-link post opening must not create another history entry.');
my_posts_expect(str_contains($app, "appHistoryState(state.route, { postId, znewsPostOverlay: true })"), 'Feed-opened posts must carry an overlay history marker.');
my_posts_expect(str_contains($app, "history.state?.znewsPostOverlay === true"), 'Reader close must distinguish an overlay from a direct post URL.');
my_posts_expect(!str_contains($app, "history.pushState({}, '', config.publicPath())"), 'Closing a post must not append a stale feed history entry.');
my_posts_expect(str_contains($app, "routeTo(restoredView, { syncHistory: false })"), 'Back navigation must restore the internal view without mutating history.');

my_posts_expect(str_contains($creator, "showActionLoading('Loading post…'"), 'Edit must show polished loading feedback for a non-trivial request.');
my_posts_expect(str_contains($creator, "confirm.querySelector('[data-action-confirm-label]').textContent = 'Deleting…'"), 'Delete must show blocking progress feedback.');
my_posts_expect(str_contains($premium, '.creator-action-buttons{display:flex;width:100%;gap:12px')
    && str_contains($premium, '.creator-action-buttons button{box-sizing:border-box;flex:1 1 0;width:0;min-width:0;height:52px;min-height:52px;padding:0 14px;border:1px solid transparent'), 'Delete confirmation actions are not dimensionally equal.');
my_posts_expect(str_contains($premium, '.creator-card-menu-actions button')
    && str_contains($premium, '.creator-card-menu-dialog{position:fixed;inset:auto 0 0'), 'Mobile post options is not a bottom action sheet.');
my_posts_expect(!str_contains($creator, "document.createElement('style')")
    && !str_contains($creator, 'style.textContent'), 'Creator UI styling must not rely on CSP-blocked inline style injection.');
my_posts_expect(str_contains($premium, '.creator-card-menu-dialog{')
    && str_contains($premium, '.creator-action-dialog{'), 'Creator dialogs are missing from the CSP-compatible external stylesheet.');
my_posts_expect(str_contains($creator, "trigger.setAttribute('aria-haspopup', 'menu')"), 'Post options trigger does not expose menu semantics.');
my_posts_expect(str_contains($creator, 'function deletePost(postId, card, returnFocus)'), 'Delete must use the custom confirmation modal.');
my_posts_expect(str_contains($creator, 'if (busy) return;'), 'Delete modal lacks double-submit protection.');
my_posts_expect(!str_contains($creator, 'window.confirm('), 'Native delete confirmation must not bypass the action modal.');
my_posts_expect(str_contains($creator, "dialog.setAttribute('aria-busy', 'true')"), 'Loading modal must expose its busy state to assistive technology.');

my_posts_expect(str_contains($app, 'window.ZNEWS_APP_INITIALIZED = true'), 'Latest navigation behavior is not activated.');
my_posts_expect(str_contains($bootstrap, 'znews-creator.js?v=14'), 'Latest creator modal behavior is not activated.');
my_posts_expect(str_contains($embeddedWorker, "zsky24-embedded-shell-v27"), 'Embedded cache namespace is stale.');
my_posts_expect(str_contains($standaloneWorker, "zsky24-standalone-shell-v27"), 'Standalone cache namespace is stale.');
my_posts_expect(str_contains($embeddedWorker, 'znews.js?v=27') && str_contains($embeddedWorker, 'znews-creator.js?v=14'), 'Embedded shell is missing the updated scripts.');
my_posts_expect(str_contains($standaloneWorker, 'znews.js?v=27') && str_contains($standaloneWorker, 'znews-creator.js?v=14'), 'Standalone shell is missing the updated scripts.');

fwrite(STDOUT, "Z Sky 24 My Posts navigation/loading checks passed.\n");
