<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');

$token = zb_session_token_from_request();
if ($token !== '') {
    fb_patch('Z_BUILDER_OWNER_SESSIONS/' . zb_token_hash($token), [
        'status' => 'LOGGED_OUT',
        'logged_out_at' => zb_now_iso(),
        'updated_at' => zb_now_iso(),
    ]);
}
zb_clear_owner_session_cookie();

api_response(true, 'SUCCESS', 'Logged out', []);
