<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

api_require_method('GET');
auth_require_admin_session();

$countPending = fb_get('TOPUP_REQUESTS/PENDING');
$countClaimed = fb_get('TOPUP_REQUESTS/CLAIMED');
$countProcessing = fb_get('TOPUP_REQUESTS/PROCESSING');
$countDone = fb_get('TOPUP_REQUESTS/DONE');

api_response(true, 'SUCCESS', 'Topup status counts loaded', [
    'pending' => is_array($countPending) ? count($countPending) : 0,
    'claimed' => is_array($countClaimed) ? count($countClaimed) : 0,
    'processing' => is_array($countProcessing) ? count($countProcessing) : 0,
    'done' => is_array($countDone) ? count($countDone) : 0,
]);