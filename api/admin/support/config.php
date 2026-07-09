<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/support.php';

api_require_method('GET');
auth_require_admin_session(true);

$config = support_config();
$public = support_public_config();

api_response(true, 'ADMIN_SUPPORT_CONFIG_OK', 'Support config loaded.', [
    'config' => $config,
    'public_config' => $public,
]);

