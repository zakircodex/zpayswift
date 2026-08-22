<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/mfs.php';

api_require_method('GET');
auth_require_admin_session();

$result = mfs_status_counts();
if (empty($result['ok'])) {
    api_response(false, 'MFS_STATUS_COUNTS_UNAVAILABLE', 'MFS summary could not be loaded', [], 503);
}

api_response(true, 'SUCCESS', 'MFS status counts loaded', [
    'counts' => (array)($result['counts'] ?? []),
    'rebuilt' => !empty($result['rebuilt']),
]);
