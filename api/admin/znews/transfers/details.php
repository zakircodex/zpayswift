<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 3) . '/znews/lib/transfers.php';

api_require_method('GET');
auth_require_admin_session(true);
$requestId = znews_firebase_key($_GET['request_id'] ?? '', 'request_id');

api_response(
    true,
    'ZNEWS_TRANSFER_ADMIN_DETAILS_OK',
    'Transfer request details loaded.',
    znews_transfer_admin_details($requestId)
);
