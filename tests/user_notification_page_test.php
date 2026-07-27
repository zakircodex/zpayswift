<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)file_get_contents($root . '/api/user/notifications.php');
$js = (string)file_get_contents($root . '/api/user/assets/pages/notifications-page.js');
$css = (string)file_get_contents($root . '/api/user/assets/pages/notifications-page.css');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
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
    str_contains($page, 'data-notification-filter="ALL"')
    && str_contains($page, 'data-notification-filter="UNREAD"')
    && str_contains($page, 'class="notification-page-fixed-area"')
    && str_contains($page, 'class="notification-page-scroll-body"')
    && str_contains($page, 'id="notificationPageLive"'),
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
    && !str_contains($js, 'notificationModal'),
    'Notification content is not rendered safely or legacy modal remains'
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
    && str_contains($css, '@media (max-width: 360px)'),
    'Responsive notification page styling is incomplete'
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
