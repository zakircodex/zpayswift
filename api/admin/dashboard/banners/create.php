<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/lib/mobile_dashboard.php';

api_require_method('POST');
api_require_app_key();
$auth = zpay_dash_require_admin_or_subadmin(true);
$actor = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$body = zpay_dash_request_data();

$upload = zpay_dash_save_banner_upload('image');
if (empty($upload['ok'])) {
    api_response(false, (string)$upload['code'], (string)$upload['message'], [], 422);
}

$imageUrl = zpay_dash_clean_string($upload['image_url'] ?? $body['image_url'] ?? '', 300);
if ($imageUrl === '') {
    api_response(false, 'IMAGE_REQUIRED', 'Banner image is required.', [], 422);
}

$bannerId = 'BNR' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
$now = now_ts();
$row = zpay_dash_banner_row([
    'banner_id' => $bannerId,
    'title' => $body['title'] ?? '',
    'image_url' => $imageUrl,
    'active' => $body['active'] ?? true,
    'sort_order' => $body['sort_order'] ?? 999,
    'action_type' => $body['action_type'] ?? 'NONE',
    'action_value' => $body['action_value'] ?? '',
    'start_at' => $body['start_at'] ?? 0,
    'end_at' => $body['end_at'] ?? 0,
    'created_at' => $now,
    'updated_at' => $now,
    'created_by' => (string)($actor['uid'] ?? ''),
    'updated_by' => (string)($actor['uid'] ?? ''),
], $bannerId);

if (!fb_put('DASHBOARD_BANNERS/' . $bannerId, $row)) {
    api_response(false, 'SERVER_ERROR', 'Failed to create banner.', [], 500);
}

api_response(true, 'BANNER_CREATED', 'Banner created.', ['banner' => $row]);
