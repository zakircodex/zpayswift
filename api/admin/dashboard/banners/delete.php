<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/lib/mobile_dashboard.php';

api_require_method('POST');
api_require_app_key();
zpay_dash_require_admin_or_subadmin(true);
$body = api_read_json_body();

$bannerId = zpay_dash_clean_string($body['banner_id'] ?? '', 80);
if ($bannerId === '') {
    api_response(false, 'VALIDATION_ERROR', 'banner_id is required.', [], 422);
}

if (!fb_delete('DASHBOARD_BANNERS/' . $bannerId)) {
    api_response(false, 'SERVER_ERROR', 'Failed to delete banner.', [], 500);
}

api_response(true, 'BANNER_DELETED', 'Banner deleted.', ['banner_id' => $bannerId]);
