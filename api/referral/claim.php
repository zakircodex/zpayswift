<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/referral.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$uid = referral_clean_uid($auth['user']['uid'] ?? '');
$body = api_read_json_body();
$result = referral_claim(
    $uid,
    (string)($body['referral_code'] ?? $body['code'] ?? ''),
    'ANDROID',
    (string)($body['referral_device_key'] ?? $body['device_key'] ?? '')
);

$data = referral_public_action_data($result);
if (empty($result['ok'])) {
    $code = (string)($result['code'] ?? 'REFERRAL_CLAIM_FAILED');
    $status = in_array($code, ['INVALID_REFERRAL_CODE'], true) ? 404 : (in_array($code, [
        'REFERRAL_ALREADY_CLAIMED',
        'SELF_REFERRAL_NOT_ALLOWED',
        'REFERRAL_CYCLE_NOT_ALLOWED',
        'REFERRAL_MARKET_MISMATCH',
        'DEVICE_ALREADY_USED',
    ], true) ? 409 : 422);
    api_response(false, $code, (string)($result['message'] ?? 'Referral code could not be claimed.'), $data, $status);
}

api_response(true, (string)($result['code'] ?? 'SUCCESS'), (string)($result['message'] ?? 'Referral linked.'), $data);
