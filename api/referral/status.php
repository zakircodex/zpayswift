<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/referral.php';

api_require_method('GET');
api_require_app_key();

$auth = auth_require_user(true);
$uid = referral_clean_uid($auth['user']['uid'] ?? '');
if ($uid === '') {
    api_response(false, 'UNAUTHORIZED', 'User session invalid.', [], 401);
}

$limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));
$before = max(0, (int)($_GET['before'] ?? 0));
api_response(true, 'SUCCESS', 'Referral details loaded.', referral_status_payload($uid, $limit, $before));
