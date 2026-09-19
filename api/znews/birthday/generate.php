<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/birthday.php';

api_require_method('POST');
birthday_same_origin();
$body = api_read_json_body();
$result = birthday_generate(
    trim((string)($body['draft_id'] ?? '')),
    trim((string)($body['draft_token'] ?? '')),
    trim((string)($body['recovery_code'] ?? '')),
    trim((string)($body['idempotency_key'] ?? api_get_header('X-Idempotency-Key') ?? ''))
);
api_response(
    true,
    !empty($result['idempotent_replay']) ? 'BIRTHDAY_UNIVERSE_ALREADY_GENERATED' : 'BIRTHDAY_UNIVERSE_CREATED',
    !empty($result['idempotent_replay']) ? 'Your Universe was already generated.' : 'Your Birthday Universe is ready.',
    $result,
    !empty($result['idempotent_replay']) ? 200 : 201
);
