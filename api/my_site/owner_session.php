<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('GET');

$ctx = zb_require_owner_session();
$owner = $ctx['owner'];
api_response(true, 'SUCCESS', 'Owner session active', [
    'owner' => [
        'owner_id' => (string)($owner['owner_id'] ?? ''),
        'name' => (string)($owner['name'] ?? ''),
        'phone_local' => (string)($owner['phone_local'] ?? ''),
        'email' => (string)($owner['email'] ?? ''),
        'dob' => (string)($owner['dob'] ?? ''),
        'address' => (string)($owner['address'] ?? ''),
        'status' => (string)($owner['status'] ?? ''),
        'phone_verified' => (bool)($owner['phone_verified'] ?? false),
    ],
]);
