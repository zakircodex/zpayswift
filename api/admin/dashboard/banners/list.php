<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/lib/mobile_dashboard.php';

api_require_method('GET');
api_require_app_key();
zpay_dash_require_admin_or_subadmin(true);

api_response(true, 'BANNERS_OK', 'Dashboard banners loaded.', [
    'items' => zpay_dash_all_banners(false),
]);
