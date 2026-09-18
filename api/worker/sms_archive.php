<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/worker_sms_archive.php';

api_require_method('POST');
api_require_worker_key();

$record = worker_sms_archive_record(api_read_json_body());
if (!$record) {
    api_response(false, 'VALIDATION_ERROR', 'SMS archive payload is invalid', [], 422);
}
if (!worker_sms_archive_store($record)) {
    api_response(false, 'SERVER_ERROR', 'Failed to store worker SMS', [], 500);
}

api_response(true, 'SMS_ARCHIVED', 'Worker SMS archived', [
    'archive_id' => (string)$record['archive_id'],
]);
