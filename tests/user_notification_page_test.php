<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/api/user/notifications.php');
$js = (string)file_get_contents($root . '/api/user/assets/pages/notifications-page.js');
$css = (string)file_get_contents($root . '/api/user/assets/pages/notifications-page.css');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$library = (string)file_get_contents($root . '/api/lib/notifications.php');
$listEndpoint = (string)file_get_contents($root . '/api/notifications/list.php');
$shell = (string)file_get_contents($root . '/api/user/assets/user-shell.js');
$rules = (string)file_get_contents($root . '/database.rules.json');
$tests = 0;

function notification_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

notification_expect(str_contains($page, 'id="notificationsSection"'), 'Notification page root is missing');
notification_expect(
    str_contains($page, 'href="/user/dashboard"')
    && str_contains($page, 'id="notificationsEditButton"')
    && str_contains($page, 'id="notificationEditBar"')
    && str_contains($page, 'id="notificationsSelectAllButton"')
    && str_contains($page, 'id="notificationsDeleteButton"'),
    'Android-style notification controls are incomplete'
);
notification_expect(
    str_contains($page, 'id="notificationDetailModal"')
    && str_contains($page, 'id="notificationDetailDeleteButton"')
    && str_contains($page, 'id="notificationDetailOpenButton"'),
    'Notification detail sheet is missing'
);
notification_expect(
    !str_contains($page . $js, 'notificationsRefreshButton')
    && str_contains($page, 'Updates from the last 30 days')
    && str_contains($page, 'id="notificationPullIndicator"')
    && str_contains($page, 'id="notificationDetailRetryButton"'),
    'Notification pull refresh, heading, or detail retry UI is incomplete'
);
notification_expect(
    str_contains($page, "'show_drawer' => false")
    && str_contains($page, "'show_bottom_nav' => true")
    && !str_contains($css, "data-active-section='notificationsSection'] .bottom-nav"),
    'Notifications must use the shared bottom navigation without the side drawer'
);
notification_expect(
    str_contains($page, 'data-notification-filter="ALL"')
    && str_contains($page, 'data-notification-filter="UNREAD"')
    && str_contains($page, 'class="notification-page-fixed-area"')
    && str_contains($page, 'class="notification-page-scroll-body"')
    && str_contains($page, 'id="notificationPageLive"')
    && str_contains($page, 'id="notificationLoadMore"'),
    'Notification tabs/fixed-scroll architecture is incomplete'
);
notification_expect(
    str_contains($js, "'notifications_list'")
    && str_contains($js, "'notification_mark_read'")
    && str_contains($js, "'notification_details'")
    && str_contains($js, "'notifications_delete'"),
    'Notification APIs are not wired'
);
notification_expect(
    str_contains($js, 'shell.escapeHtml(item.title')
    && str_contains($js, 'shell.escapeHtml(item.body')
    && str_contains($js, "$('notificationDetailTitle').textContent")
    && str_contains($js, "$('notificationDetailBody').textContent")
    && str_contains($js, "results[0].status === 'rejected'")
    && str_contains($js, "load({ preserve: true })")
    && str_contains($js, "addEventListener('touchstart'")
    && str_contains($js, "addEventListener('touchmove'")
    && str_contains($js, "addEventListener('touchend'")
    && str_contains($js, 'const pageSize = 10;')
    && str_contains($js, 'function loadMore()')
    && str_contains($js, 'bindProgressiveLoading()')
    && str_contains($js, 'const before = state.nextBefore;')
    && str_contains($js, 'before_id: beforeId')
    && str_contains($js, "holdPageLoad?.('Loading notifications...')")
    && !str_contains($js, 'notificationModal'),
    'Notification safe rendering, error handling, refresh, or loading lifecycle is incomplete'
);
notification_expect(
    str_contains($js, 'history.pushState')
    && str_contains($js, 'popstate')
    && str_contains($js, 'closeDetail'),
    'Notification in-page detail history behavior is missing'
);
notification_expect(
    str_contains($css, '.notification-page-fixed-area')
    && str_contains($css, '.notification-page-scroll-body')
    && str_contains($css, 'overflow-y: auto')
    && str_contains($css, '.notification-page-card-meta')
    && str_contains($css, '.notification-load-more')
    && str_contains($css, '.notification-detail-retry')
    && str_contains($css, '@media (max-width: 360px)'),
    'Responsive notification page styling is incomplete'
);
notification_expect(
    str_contains($library, 'function notification_rows_for_user')
    && str_contains($library, 'function notification_recent_cutoff')
    && str_contains($library, "'orderBy' => json_encode('created_at'")
    && str_contains($library, "'orderBy' => json_encode('\$key'")
    && str_contains($library, "'limitToLast' => notification_recent_query_limit()")
    && str_contains($library, 'function notification_timestamp_seconds')
    && str_contains($library, '30 * 24 * 60 * 60')
    && str_contains($library, 'function notification_list_from_rows')
    && str_contains($library, 'function notification_page_from_rows')
    && str_contains($library, 'function notification_unread_count_from_rows')
    && str_contains($listEndpoint, '$rows = notification_rows_for_user($uid);')
    && str_contains($listEndpoint, 'notification_page_from_rows($rows')
    && !str_contains($listEndpoint, 'notification_unread_count($uid)'),
    'Notification list endpoint still performs duplicate user-tree reads'
);
notification_expect(
    str_contains($rules, '"USER_NOTIFICATIONS"')
    && str_contains($rules, '".indexOn": ["created_at"]'),
    'Notification created_at Firebase index is missing'
);
notification_expect(
    strpos($js, 'Object.assign(item, results[0].value.notification || {});')
      < strpos($js, 'item.is_read = true;')
    && str_contains($js, 'data.deleted_count : data.marked_count')
    && str_contains($js, 'Notification could not be deleted.'),
    'Notification read/delete results are not applied defensively'
);
notification_expect(
    str_contains($library, 'function notification_mark_many_read_result')
    && str_contains($library, 'function notification_delete_many_result')
    && str_contains($library, 'fb_patch(\'USER_NOTIFICATIONS/\' . $uid, $updates)')
    && !str_contains($proxy, "'notifications/mark_read.php'")
    && !str_contains($proxy, "'notifications/delete.php'"),
    'Notification bulk actions are not using the direct batched write path'
);
notification_expect(
    str_contains($proxy, '$notificationBefore = max(0, (int)($_GET[\'before\'] ?? 0));')
    && str_contains($proxy, '$notificationBeforeId = trim((string)($_GET[\'before_id\'] ?? \'\'));')
    && str_contains($proxy, 'notification_page_from_rows(')
    && !str_contains($proxy, "'notifications/list.php?'"),
    'Notification list proxy is not using direct cursor pagination'
);
notification_expect(
    str_contains($shell, "!== 'notifications') loadUnread()"),
    'Notification page still starts a redundant unread-count request during bootstrap'
);
notification_expect(
    !str_contains($page . $js, 'openSection(')
    && !str_contains($page, 'data-page-section'),
    'Notification page still depends on SPA routing'
);

foreach (['notifications_list', 'notification_mark_read', 'notification_details', 'notifications_delete'] as $action) {
    notification_expect(
        str_contains($proxy, "case '{$action}':"),
        "missing notification proxy action {$action}"
    );
}

$markReadCase = strstr($proxy, "case 'notification_mark_read':");
$markReadCase = $markReadCase === false ? '' : substr($markReadCase, 0, strpos($markReadCase, "case 'notifications_mark_all_read':") ?: 0);
notification_expect(
    $markReadCase !== ''
    && strpos($markReadCase, 'user_proxy_require_login(true, false);') < strpos($markReadCase, 'user_proxy_require_csrf();'),
    'Notification write proxy must resolve session before CSRF'
);

echo "User notification page tests passed ({$tests} assertions).\n";
