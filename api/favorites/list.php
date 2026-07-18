<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once API_ROOT . '/lib/favorites.php';

api_require_method('GET');
api_require_app_key();
$auth = auth_require_user(true);
$uid = (string)($auth['user']['uid'] ?? '');

$node = fb_get(favorite_numbers_path($uid));
$favorites = is_array($node) ? favorite_rows_list($node) : [];

api_response(true, 'FAVORITES_LOADED', 'Favorite numbers loaded.', [
    'favorites' => $favorites,
    'count' => count($favorites),
    'limit' => FAVORITE_NUMBERS_LIMIT,
]);
