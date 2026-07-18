<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once API_ROOT . '/lib/favorites.php';

api_require_method('POST');
api_require_app_key();
$auth = auth_require_user(true);
$uid = (string)($auth['user']['uid'] ?? '');
$body = api_read_json_body();

$result = favorite_create_for_user($uid, $body);
if (empty($result['ok'])) {
    api_response(false, (string)$result['code'], (string)$result['message'], null, (int)($result['http_status'] ?? 400));
}

api_response(true, 'FAVORITE_SAVED', 'Favorite number saved.', $result['data']);
