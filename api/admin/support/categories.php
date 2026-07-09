<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/support.php';

api_require_method('GET');
auth_require_admin_session(true);

api_response(true, 'ADMIN_SUPPORT_CATEGORIES_OK', 'Support categories loaded.', [
    'categories' => support_categories(false),
]);

