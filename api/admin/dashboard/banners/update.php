<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/lib/mobile_dashboard.php';

api_require_method('POST');
api_require_app_key();
$auth = zpay_dash_require_admin_or_subadmin(true);
$actor = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$body = zpay_dash_request_data();

$bannerId = zpay_dash_clean_string($body['banner_id'] ?? '', 80);
if ($bannerId === '') {
    api_response(false, 'VALIDATION_ERROR', 'banner_id is required.', [], 422);
}

$existing = fb_get('DASHBOARD_BANNERS/' . $bannerId);
if (!is_array($existing)) {
    api_response(false, 'NOT_FOUND', 'Banner not found.', [], 404);
}

$upload = zpay_dash_save_banner_upload('image');
if (empty($upload['ok'])) {
    api_response(false, (string)$upload['code'], (string)$upload['message'], [], 422);
}

$imageUrl = !empty($upload['uploaded'])
    ? (string)$upload['image_url']
    : zpay_dash_clean_string($body['image_url'] ?? $existing['image_url'] ?? '', 300);

$row = zpay_dash_banner_row(array_merge($existing, [
    'banner_id' => $bannerId,
    'title' => array_key_exists('title', $body) ? $body['title'] : ($existing['title'] ?? ''),
    'image_url' => $imageUrl,
    'active' => array_key_exists('active', $body) ? $body['active'] : ($existing['active'] ?? true),
    'sort_order' => array_key_exists('sort_order', $body) ? $body['sort_order'] : ($existing['sort_order'] ?? 999),
    'action_type' => array_key_exists('action_type', $body) ? $body['action_type'] : ($existing['action_type'] ?? 'NONE'),
    'action_value' => array_key_exists('action_value', $body) ? $body['action_value'] : ($existing['action_value'] ?? ''),
    'start_at' => array_key_exists('start_at', $body) ? $body['start_at'] : ($existing['start_at'] ?? 0),
    'end_at' => array_key_exists('end_at', $body) ? $body['end_at'] : ($existing['end_at'] ?? 0),
    'updated_at' => now_ts(),
    'updated_by' => (string)($actor['uid'] ?? ''),
]), $bannerId);

if ($row['image_url'] === '') {
    api_response(false, 'IMAGE_REQUIRED', 'Banner image is required.', [], 422);
}

if (!fb_put('DASHBOARD_BANNERS/' . $bannerId, $row)) {
    api_response(false, 'SERVER_ERROR', 'Failed to update banner.', [], 500);
}

api_response(true, 'BANNER_UPDATED', 'Banner updated.', ['banner' => $row]);
