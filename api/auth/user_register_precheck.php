<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/auth_android.php';
require_once dirname(__DIR__) . '/lib/register_android.php';
require_once dirname(__DIR__) . '/lib/user_web_credentials.php';
require_once dirname(__DIR__) . '/lib/user_registration_identity.php';
require_once dirname(__DIR__) . '/lib/user_registration_precheck.php';

api_require_method('POST');
api_require_app_key();
$result = user_registration_precheck(api_read_json_body());
api_response($result['ok'], $result['code'], $result['message'], $result['data'], $result['http_status']);
