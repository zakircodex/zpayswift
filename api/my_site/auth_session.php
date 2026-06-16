<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('GET');

$ctx = zb_require_owner_session();
api_response(true, 'SUCCESS', 'Owner session active', [
    'owner' => zb_public_owner($ctx['owner']),
]);
