<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$app = file_get_contents($root . '/znews/assets/znews.js');
$creator = file_get_contents($root . '/znews/assets/znews-creator.js');
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

my_posts_expect(str_contains($creator, "showActionLoading('Loading post…'"), 'Edit must show immediate loading feedback.');
my_posts_expect(str_contains($creator, "showActionLoading('Deleting post…'"), 'Delete must show blocking progress feedback.');
my_posts_expect(str_contains($creator, 'function confirmDelete()'), 'Delete must use the custom confirmation modal.');
my_posts_expect(!str_contains($creator, 'window.confirm('), 'Native delete confirmation must not bypass the action modal.');
my_posts_expect(str_contains($creator, "dialog.setAttribute('aria-busy', 'true')"), 'Loading modal must expose its busy state to assistive technology.');

my_posts_expect(str_contains($bootstrap, 'znews.js?v=10'), 'Latest navigation behavior is not activated.');
my_posts_expect(str_contains($bootstrap, 'znews-creator.js?v=6'), 'Latest creator modal behavior is not activated.');
my_posts_expect(str_contains($embeddedWorker, "zsky24-embedded-shell-v8"), 'Embedded cache namespace is stale.');
my_posts_expect(str_contains($standaloneWorker, "zsky24-standalone-shell-v8"), 'Standalone cache namespace is stale.');
my_posts_expect(str_contains($embeddedWorker, 'znews.js?v=10') && str_contains($embeddedWorker, 'znews-creator.js?v=6'), 'Embedded shell is missing the updated scripts.');
my_posts_expect(str_contains($standaloneWorker, 'znews.js?v=10') && str_contains($standaloneWorker, 'znews-creator.js?v=6'), 'Standalone shell is missing the updated scripts.');

fwrite(STDOUT, "Z Sky 24 My Posts navigation/loading checks passed.\n");
