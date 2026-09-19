<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/birthday.php';

api_require_method('POST');
birthday_same_origin();
$body = api_read_json_body();
$universe = birthday_universe_by_slug(strtolower(trim((string)($body['slug'] ?? ''))));
if (!is_array($universe)) {
    api_response(false, 'BIRTHDAY_UNIVERSE_NOT_FOUND', 'Universe not found.', [], 404);
}
$result = birthday_record_event(
    $universe,
    trim((string)($body['event_type'] ?? '')),
    is_array($body['metadata'] ?? null) ? (array)$body['metadata'] : []
);
api_response(true, 'BIRTHDAY_EVENT_RECORDED', 'Event recorded.', $result);
