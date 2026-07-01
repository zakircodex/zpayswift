<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/mobile_dashboard.php';

api_require_method('GET');
api_require_app_key();
zpay_dash_require_admin_or_subadmin(true);

api_response(true, 'DASHBOARD_CONFIG_OK', 'Dashboard config loaded.', zpay_dash_config());
