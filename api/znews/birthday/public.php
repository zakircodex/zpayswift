<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/birthday.php';

api_require_method('GET');
$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
$universe = birthday_universe_by_slug($slug);
if (!is_array($universe)) {
    api_response(false, 'BIRTHDAY_UNIVERSE_NOT_FOUND', 'This Birthday Universe is unavailable or has expired.', [], 404);
}
api_response(true, 'BIRTHDAY_UNIVERSE_OK', 'Birthday Universe loaded.', [
    'universe' => birthday_public_universe($universe),
]);
