<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/engagement.php';
require_once __DIR__ . '/comments/common.php';
require_once __DIR__ . '/comments/create.php';
require_once __DIR__ . '/comments/update.php';
require_once __DIR__ . '/comments/delete.php';
require_once __DIR__ . '/comments/access.php';
require_once __DIR__ . '/comments/moderation.php';
