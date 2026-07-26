<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/user/dashboard.php');
$proxy = (string)file_get_contents($root . '/api/user/proxy.php');
$dashboardJs = (string)file_get_contents($root . '/api/user/assets/dashboard.js');
$appJs = (string)file_get_contents($root . '/api/user/assets/user-app.js');
$css = (string)file_get_contents($root . '/api/user/assets/user-app.css');
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

notification_expect(str_contains($dashboard, 'id="notificationsSection"'), 'Dedicated notification section is missing');
notification_expect(str_contains($dashboard, 'id="notificationsBackButton"'), 'Notification back button is missing');
notification_expect(
    str_contains($dashboard, 'id="notificationsEditButton"')
    && str_contains($dashboard, 'id="notificationEditBar"')
    && str_contains($dashboard, 'id="notificationsSelectAllButton"')
    && str_contains($dashboard, 'id="notificationsDeleteButton"')
    && str_contains($dashboard, 'id="notificationsMarkSelectedButton"'),
    'Android-style notification edit controls are missing'
);
notification_expect(
    str_contains($dashboard, 'id="notificationDetailModal"')
    && str_contains($dashboard, 'id="notificationDetailDeleteButton"')
    && str_contains($dashboard, 'id="notificationDetailOpenButton"'),
    'Android-style notification detail sheet is missing'
);
notification_expect(
    str_contains($dashboard, 'data-notification-filter="ALL"')
    && str_contains($dashboard, 'data-notification-filter="UNREAD"'),
    'Android-style All and Unread tabs are missing'
);
notification_expect(str_contains($dashboard, 'class="notification-page-fixed-area"'), 'Fixed notification header/filter area is missing');
notification_expect(str_contains($dashboard, 'class="notification-page-scroll-body"'), 'Dedicated notification scroll body is missing');
notification_expect(str_contains($dashboard, 'id="notificationPageLive"'), 'Notification page live region is missing');
notification_expect(
    str_contains($dashboardJs, "p === '/user/notifications'")
    && str_contains($dashboardJs, "notificationsSection: { title: 'Notifications', path: '/user/notifications' }"),
    'Dedicated notification route is not mapped'
);
notification_expect(
    str_contains($appJs, "$('heroNotificationButton')?.addEventListener('click', openNotificationsPage)")
    && str_contains($appJs, "window.openSection?.('notificationsSection')")
    && str_contains($appJs, "$('notificationsBackButton')?.addEventListener('click', closeNotificationsPage)"),
    'Dashboard bell does not open the notification page'
);
notification_expect(!str_contains($appJs, 'notificationModal'), 'Legacy notification modal remains');
notification_expect(
    str_contains($appJs, "'notifications_list'")
    && str_contains($appJs, "filter: app.notifications.filter"),
    'Notification page does not use the existing filtered list API'
);
notification_expect(
    str_contains($appJs, "'notification_mark_read'")
    && str_contains($appJs, "'notification_details'")
    && str_contains($appJs, "'notifications_delete'"),
    'Notification read, details and delete APIs are not wired'
);
notification_expect(
    str_contains($appJs, 'function refreshCsrfToken()')
    && str_contains($appJs, 'function postWithFreshCsrf(')
    && str_contains($appJs, 'isCsrfError(error)')
    && str_contains($appJs, "postWithFreshCsrf(\n        'notification_mark_read'")
    && str_contains($appJs, "postWithFreshCsrf(\n        'notifications_delete'"),
    'Notification write actions do not refresh and retry stale CSRF safely'
);
notification_expect(
    str_contains($appJs, 'function handleNotificationSessionExpired()')
    && str_contains($appJs, 'Please login again to view your notifications.'),
    'Notification session failures are not mapped to a safe user message'
);
notification_expect(
    str_contains($appJs, "title.textContent = String(item.title")
    && str_contains($appJs, "body.textContent = String(item.body"),
    'Notification content is not rendered through the XSS-safe text path'
);
notification_expect(
    str_contains($css, "body.user-authenticated[data-active-section='notificationsSection'] .bottom-nav")
    && str_contains($css, '.notification-page-header')
    && str_contains($css, '.notification-page-card.unread')
    && str_contains($css, '.notification-edit-bar')
    && str_contains($css, '.notification-detail-sheet'),
    'Android-aligned notification page styling is incomplete'
);
notification_expect(
    str_contains($css, "body.user-authenticated[data-active-section='notificationsSection']")
    && str_contains($css, 'overflow: hidden;')
    && str_contains($css, '.notification-page-fixed-area')
    && str_contains($css, '.notification-page-scroll-body')
    && str_contains($css, 'overflow-y: auto')
    && str_contains($css, 'height: 100dvh'),
    'Notification page does not lock the page shell while only the list scrolls'
);
notification_expect(
    str_contains($css, '@media (max-width: 360px)')
    && str_contains($css, '.notification-page-shell'),
    'Narrow mobile notification layout is not covered'
);

$markReadCase = strstr($proxy, "case 'notification_mark_read':");
$markReadCase = $markReadCase === false ? '' : substr($markReadCase, 0, strpos($markReadCase, "case 'notifications_mark_all_read':") ?: 0);
$markAllCase = strstr($proxy, "case 'notifications_mark_all_read':");
$markAllCase = $markAllCase === false ? '' : substr($markAllCase, 0, strpos($markAllCase, "case 'register':") ?: 0);
notification_expect(
    $markReadCase !== ''
    && strpos($markReadCase, 'user_proxy_require_login(true, false);') < strpos($markReadCase, 'user_proxy_require_csrf();')
    && $markAllCase !== ''
    && strpos($markAllCase, 'user_proxy_require_login(true, false);') < strpos($markAllCase, 'user_proxy_require_csrf();'),
    'Notification write proxy must resolve session before validating CSRF'
);
notification_expect(
    str_contains($proxy, "case 'notification_details':")
    && str_contains($proxy, "'notifications/details.php?'")
    && str_contains($proxy, "case 'notifications_delete':")
    && str_contains($proxy, "'notifications/delete.php'"),
    'Own-user notification details/delete proxy routes are missing'
);
notification_expect(
    str_contains($appJs, 'if (app.notifications.activeDetail)')
    && str_contains($appJs, 'closeNotificationDetails({ fromHistory: true })')
    && str_contains($appJs, 'function handleNotificationDetailKeydown('),
    'Notification details do not close safely with browser/device back and keyboard'
);
notification_expect(
    str_contains($appJs, 'title.textContent = String(item.title')
    && str_contains($appJs, 'body.textContent = String(item.body')
    && str_contains($appJs, "$('notificationDetailBody').textContent"),
    'Notification list/details content is not rendered through textContent'
);

echo "User notification page tests passed ({$tests} assertions).\n";
