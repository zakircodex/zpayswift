<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dashboard = (string)file_get_contents($root . '/api/user/dashboard.php');
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
notification_expect(str_contains($dashboard, 'id="notificationsMarkAllButton"'), 'Mark-all action is missing');
notification_expect(
    str_contains($dashboard, 'data-notification-filter="ALL"')
    && str_contains($dashboard, 'data-notification-filter="UNREAD"'),
    'Android-style All and Unread tabs are missing'
);
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
    && str_contains($appJs, "'notifications_mark_all_read'"),
    'Existing read-state APIs are not preserved'
);
notification_expect(
    str_contains($appJs, "title.textContent = String(item.title")
    && str_contains($appJs, "body.textContent = String(item.body"),
    'Notification content is not rendered through the XSS-safe text path'
);
notification_expect(
    str_contains($css, "body.user-authenticated[data-active-section='notificationsSection'] .bottom-nav")
    && str_contains($css, '.notification-page-header')
    && str_contains($css, '.notification-page-card.unread'),
    'Android-aligned notification page styling is incomplete'
);
notification_expect(
    str_contains($css, '@media (max-width: 360px)')
    && str_contains($css, '.notification-page-shell'),
    'Narrow mobile notification layout is not covered'
);

echo "User notification page tests passed ({$tests} assertions).\n";
