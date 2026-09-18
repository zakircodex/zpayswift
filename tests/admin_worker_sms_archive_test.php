<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;

function admin_worker_sms_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$workerEndpoint = (string)file_get_contents($root . '/api/worker/sms_archive.php');
$listEndpoint = (string)file_get_contents($root . '/api/admin/workers/sms_list.php');
$deleteEndpoint = (string)file_get_contents($root . '/api/admin/workers/sms_delete.php');
$proxy = (string)file_get_contents($root . '/api/admin/proxy.php');
$dashboard = (string)file_get_contents($root . '/api/admin/dashboard.php');
$dashboardJs = (string)file_get_contents($root . '/api/admin/assets/dashboard.js');
$dashboardCss = (string)file_get_contents($root . '/api/admin/assets/admin-worker-sms.css');

admin_worker_sms_expect(str_contains($workerEndpoint, 'api_require_worker_key();'), 'Worker SMS upload must require the worker key');
admin_worker_sms_expect(str_contains($listEndpoint, 'auth_require_admin_session(true);'), 'SMS list must require an admin session');
admin_worker_sms_expect(str_contains($deleteEndpoint, 'auth_require_admin_session(true);'), 'SMS delete must require an admin session');
admin_worker_sms_expect(str_contains($deleteEndpoint, "admin_action_log('DELETE_WORKER_SMS'"), 'SMS deletion must be audit logged');
admin_worker_sms_expect(!str_contains($deleteEndpoint, "\$record['body']"), 'SMS contents must not be copied into deletion logs');

admin_worker_sms_expect(str_contains($proxy, "case 'worker_sms_list':"), 'Admin proxy must expose the SMS list');
admin_worker_sms_expect(str_contains($proxy, "case 'worker_sms_delete':"), 'Admin proxy must expose SMS deletion');
admin_worker_sms_expect(str_contains($proxy, "proxy_forward_admin_post('workers/sms_delete.php'"), 'SMS deletion must use the authenticated CSRF-protected proxy helper');

foreach (['workerSmsSection', 'workerSmsTableBody', 'reloadWorkerSmsBtn', 'workerSmsPrevBtn', 'workerSmsNextBtn'] as $id) {
    admin_worker_sms_expect(str_contains($dashboard, 'id="' . $id . '"'), 'Missing Worker SMS admin control: ' . $id);
}
admin_worker_sms_expect(str_contains($dashboard, 'data-section="workerSmsSection"'), 'Worker SMS must be reachable from the admin sidebar');
admin_worker_sms_expect(str_contains($dashboard, 'admin-worker-sms.css'), 'Worker SMS stylesheet must be loaded');

admin_worker_sms_expect(str_contains($dashboardJs, "proxyGet('worker_sms_list'"), 'Dashboard must load worker SMS through the proxy');
admin_worker_sms_expect(str_contains($dashboardJs, "proxyPost('worker_sms_delete'"), 'Dashboard must delete worker SMS through the proxy');
admin_worker_sms_expect(str_contains($dashboardJs, '${esc(row.body || \'\')}'), 'SMS table output must HTML-escape the message body');
admin_worker_sms_expect(str_contains($dashboardJs, '${esc(row.archive_id || \'-\')}'), 'SMS details must HTML-escape archive identifiers');
admin_worker_sms_expect(str_contains($dashboardJs, "renderCursorPagination('workerSms'"), 'Worker SMS must use bounded cursor pagination controls');
admin_worker_sms_expect(str_contains($dashboardCss, '#workerSmsSection'), 'Worker SMS view must have scoped responsive styles');

echo "Admin worker SMS archive tests passed ({$assertions} assertions).\n";
