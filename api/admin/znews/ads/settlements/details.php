<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/znews/bootstrap.php';
require_once dirname(__DIR__, 4) . '/znews/lib/settlements.php';

api_require_method('GET');
auth_require_admin_session(true);

$settlementId = znews_firebase_key($_GET['settlement_id'] ?? '', 'settlement_id');

api_response(
    true,
    'ZNEWS_SETTLEMENT_DETAILS_OK',
    'Settlement details loaded.',
    znews_settlement_admin_details($settlementId)
);
