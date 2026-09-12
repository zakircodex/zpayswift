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
$scope = strtolower(trim((string)($_GET['scope'] ?? 'full')));
if (!in_array($scope, ['core', 'history', 'full'], true)) {
    api_response(false, 'INVALID_SCOPE', 'Invalid referral data scope.', [], 422);
}
api_response(true, 'SUCCESS', 'Referral details loaded.', referral_status_payload($uid, $limit, $before, $scope));
