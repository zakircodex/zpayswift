<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function zsky_admin_ui_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function zsky_admin_ui_read(string $relative): string
{
    global $root;
    $path = $root . '/' . $relative;
    zsky_admin_ui_expect(is_file($path), 'Missing file: ' . $relative);
    $source = file_get_contents($path);
    zsky_admin_ui_expect(is_string($source), 'Unreadable file: ' . $relative);
    return (string)$source;
}

$dashboard = zsky_admin_ui_read('api/admin/dashboard.php');
$gateway = zsky_admin_ui_read('api/admin/zsky24_creator_admin.php');
$script = zsky_admin_ui_read('api/admin/assets/zsky24-admin.js');
$style = zsky_admin_ui_read('api/admin/assets/zsky24-admin.css');

foreach ([
    'id="zsky24Section"',
    'id="zsky24AdminTitle"',
    'assets/zsky24-admin.css',
    'assets/zsky24-admin.js',
] as $marker) {
    zsky_admin_ui_expect(str_contains($dashboard, $marker), 'Dashboard Z Sky shell marker missing: ' . $marker);
}

zsky_admin_ui_expect(str_contains($script, "mode: 'OVERVIEW'"), 'Overview is not the default Z Sky Admin tab.');
foreach ([
    'data-zsky-mode="OVERVIEW"',
    'data-zsky-mode="MODERATION"',
    'data-zsky-mode="CREATORS"',
    'data-zsky-mode="WEEKLY"',
    'data-zsky-mode="MONTHLY"',
    'data-zsky-mode="PAYOUT"',
    'data-zsky-mode="POLICY"',
    'Overview',
    'Posts / Moderation',
    'Creator accounts',
    'Weekly reviews',
    'Monthly summary',
    'Payout readiness',
    'Settings',
] as $marker) {
    zsky_admin_ui_expect(str_contains($script, $marker), 'Admin tab/feature marker missing: ' . $marker);
}

foreach ([
    'data-zsky-moderation-tab="POSTS"',
    'data-zsky-moderation-tab="COMMENTS"',
    'data-zsky-view-post',
    'data-zsky-view-comment',
    'data-zsky-post-decision',
    'data-zsky-comment-decision',
    'data-zsky-block-creator',
    'data-zsky-unblock-creator',
    'data-zsky-weekly-approve',
    'data-zsky-weekly-hold',
    'id="zskyPayoutPreflightBtn"',
    'id="zskyPolicyBody"',
] as $marker) {
    zsky_admin_ui_expect(str_contains($script, $marker), 'Existing action binding missing: ' . $marker);
}

zsky_admin_ui_expect(str_contains($script, 'const PAGE_SIZE = 10;'), 'Admin list page size is not 10.');
zsky_admin_ui_expect(str_contains($script, '.slice(0, PAGE_SIZE)'), 'Moderation responses are not hard-capped before rendering.');
zsky_admin_ui_expect(str_contains($script, 'safeRows.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE)'), 'Creator/review/performance paging does not cap rendered items.');
foreach (['zskyModerationPrevious', 'zskyModerationNext', 'zskyCreatorPrevious', 'zskyCreatorNext', 'zskyWeeklyPrevious', 'zskyWeeklyNext', 'zskyMonthlyPrevious', 'zskyMonthlyNext'] as $id) {
    zsky_admin_ui_expect(str_contains($script, 'id="' . $id . '"'), 'Pagination control missing: ' . $id);
}

foreach ([
    "if (\$action === 'posts_queue')",
    "if (\$action === 'post_details')",
    "if (\$action === 'post_decision')",
    "if (\$action === 'comments_queue')",
    "if (\$action === 'comment_details')",
    "if (\$action === 'comment_decision')",
    'znews_admin_queue($limit, $cursor)',
    'znews_admin_moderate_post_with_media(',
    'znews_admin_comment_queue($limit, $cursor)',
    'znews_admin_moderate_comment(',
    'max(1, min(10,',
] as $marker) {
    zsky_admin_ui_expect(str_contains($gateway, $marker), 'Protected moderation gateway marker missing: ' . $marker);
}

zsky_admin_ui_expect(str_contains($gateway, 'auth_require_admin_session(true)'), 'Live Admin authorization was removed.');
zsky_admin_ui_expect(str_contains($gateway, 'hash_equals($storedCsrf, $providedCsrf)'), 'POST CSRF protection was removed.');
zsky_admin_ui_expect(str_contains($script, "headers['X-CSRF-TOKEN'] = csrf"), 'Admin mutations do not send CSRF.');
zsky_admin_ui_expect(str_contains($script, "fetch('/api/znews/public/policy.php'"), 'Read-only settings do not use the canonical policy endpoint.');
zsky_admin_ui_expect(!str_contains($script, 'policy_save'), 'The read-only policy screen invented a settings mutation.');

foreach (['is-success', 'is-warning', 'is-danger', 'is-neutral'] as $marker) {
    zsky_admin_ui_expect(str_contains($script, $marker) && str_contains($style, $marker), 'Status badge state missing: ' . $marker);
}

foreach (['wallet_credit_available', 'Approve transfer', 'creator_amount:', 'reported_revenue_micros:', 'APP_KEY', 'ADMIN_KEY', 'retailer_secret_pin'] as $forbidden) {
    zsky_admin_ui_expect(!str_contains($script, $forbidden), 'Unsafe/retired browser capability rendered: ' . $forbidden);
}

foreach ([
    'What each area does',
    'How creator value can reach Z-Pay',
    'No balance movement here',
    'The readiness button cannot add user balance.',
    "setModalPresentationScope('zsky24')",
    'zsky-admin-button',
] as $marker) {
    zsky_admin_ui_expect(str_contains($script, $marker), 'Admin explanation or normalized action marker missing: ' . $marker);
}

foreach ([
    '.modal[data-modal-scope="zsky24"] .modal-foot',
    'grid-template-columns:repeat(2,minmax(0,1fr))',
    '#zsky24Section .btn.danger',
    'height:48px',
] as $marker) {
    zsky_admin_ui_expect(str_contains($style, $marker), 'Admin action sizing/style marker missing: ' . $marker);
}

foreach ([
    '@media(max-width:560px)',
    '@media(max-width:350px)',
    '.zsky-primary-tabs',
    '.zsky-pagination',
    '.zsky-status-badge',
    'overflow-x:clip',
    'overflow-wrap:anywhere',
] as $marker) {
    zsky_admin_ui_expect(str_contains($style, $marker), 'Responsive Admin style marker missing: ' . $marker);
}

echo "Z Sky 24 Admin panel UI contract passed ({$assertions} assertions).\n";
