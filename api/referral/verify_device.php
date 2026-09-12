<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/referral.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$uid = referral_clean_uid($auth['user']['uid'] ?? '');
$body = api_read_json_body();
$result = referral_activate_relation_device(
    $uid,
    (string)($body['referral_device_key'] ?? $body['device_key'] ?? '')
);

$data = referral_public_action_data($result);
if (empty($result['ok'])) {
    $code = (string)($result['code'] ?? 'DEVICE_VERIFICATION_FAILED');
    api_response(false, $code, (string)($result['message'] ?? 'Referral device verification failed.'), $data, $code === 'DEVICE_ALREADY_USED' ? 409 : 422);
}

api_response(true, (string)($result['code'] ?? 'SUCCESS'), (string)($result['message'] ?? 'Referral device verified.'), $data);
