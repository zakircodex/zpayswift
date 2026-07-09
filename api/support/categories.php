<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/support.php';

api_require_method('GET');
api_require_app_key();
zpay_dash_require_mobile_user(true);

api_response(true, 'SUPPORT_CATEGORIES_OK', 'Support categories loaded.', [
    'categories' => support_categories(true),
]);

