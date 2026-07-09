<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/support.php';

api_require_method('POST');
$auth = auth_require_admin_session(true);
$body = api_read_json_body();

$code = support_clean_code($body['code'] ?? '');
$name = support_clean_text($body['name'] ?? '', 80);
if ($code === '' || $name === '') {
    api_response(false, 'SUPPORT_CATEGORY_INVALID', 'Category code and name are required.', [], 422);
}

$payload = [
    'code' => $code,
    'name' => $name,
    'active' => support_bool($body['active'] ?? true, true),
    'sort_order' => support_int($body['sort_order'] ?? 100, 100, 1, 999),
    'related_request_enabled' => support_bool($body['related_request_enabled'] ?? false, false),
    'attachment_enabled' => support_bool($body['attachment_enabled'] ?? true, true),
    'updated_at' => support_now(),
    'updated_by' => (string)($auth['user']['uid'] ?? ''),
];

if (!fb_put('SUPPORT_CATEGORIES/' . $code, $payload)) {
    api_response(false, 'SUPPORT_CATEGORY_SAVE_FAILED', 'Support category could not be saved.', [], 500);
}

api_response(true, 'ADMIN_SUPPORT_CATEGORY_SAVED', 'Support category saved.', [
    'category' => $payload,
]);

