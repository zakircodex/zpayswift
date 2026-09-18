<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/worker_sms_archive.php';

api_require_method('POST');
$actor = auth_require_admin_session(true);
$body = api_read_json_body();
$archiveId = strtolower(trim((string)($body['archive_id'] ?? '')));
$result = worker_sms_archive_delete($archiveId);

if (empty($result['ok'])) {
    api_response(
        false,
        (string)($result['code'] ?? 'SERVER_ERROR'),
        (string)($result['message'] ?? 'Failed to delete worker SMS'),
        [],
        ($result['code'] ?? '') === 'VALIDATION_ERROR' ? 422 : 500
    );
}

$record = is_array($result['record'] ?? null) ? (array)$result['record'] : [];
if (!empty($result['deleted'])) {
    admin_action_log('DELETE_WORKER_SMS', $archiveId, 'Admin deleted worker SMS archive record', [
        'device_id' => (string)($record['device_id'] ?? ''),
        'received_at' => (int)($record['received_at'] ?? 0),
        'actor_uid' => (string)($actor['uid'] ?? ''),
    ]);
    system_log('ADMIN_DELETE_WORKER_SMS', $archiveId, 'Admin deleted worker SMS archive record', [
        'device_id' => (string)($record['device_id'] ?? ''),
        'received_at' => (int)($record['received_at'] ?? 0),
        'actor_uid' => (string)($actor['uid'] ?? ''),
    ]);
}

api_response(true, 'SMS_DELETED', 'Worker SMS deleted', [
    'archive_id' => $archiveId,
    'deleted' => !empty($result['deleted']),
]);
