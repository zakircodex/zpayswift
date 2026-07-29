<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

require_once __DIR__ . '/engagement.php';

/*
 * Creator endpoints such as comments/create.php, update.php and delete.php
 * intentionally share basenames with guarded library submodules. Temporarily
 * expose a distinct entrypoint name while loading those internal files.
 */
$znewsOriginalScriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
$_SERVER['SCRIPT_FILENAME'] = __FILE__ . '.aggregate';

try {
    require_once __DIR__ . '/comments/common.php';
    require_once __DIR__ . '/comments/publication.php';
    require_once __DIR__ . '/comments/create.php';
    require_once __DIR__ . '/comments/update.php';
    require_once __DIR__ . '/comments/delete.php';
    require_once __DIR__ . '/comments/access.php';
    require_once __DIR__ . '/comments/moderation.php';
} finally {
    if ($znewsOriginalScriptFilename === null) {
        unset($_SERVER['SCRIPT_FILENAME']);
    } else {
        $_SERVER['SCRIPT_FILENAME'] = $znewsOriginalScriptFilename;
    }
}
