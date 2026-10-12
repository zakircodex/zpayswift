<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/auth_android.php';

api_require_method('POST');
api_require_app_key();
$body = api_read_json_body();
$country = auth_normalize_country_code((string)($body['phone_country'] ?? ''));
$decision = market_registration_decision($body, $country);
if (empty($decision['ok'])) {
    api_response(false, (string)($decision['code'] ?? 'LOCATION_REQUIRED'),
        (string)($decision['message'] ?? 'Location permission is required to create an account.'), [], 422);
}
unset($decision['ok'], $decision['created_ip'], $decision['ip_risk_reason']);
api_response(true, (string)($decision['code'] ?? 'SUCCESS'),
    (string)($decision['message'] ?? 'Registration market verified'), $decision);
