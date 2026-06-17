<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/operators.php';
require_once __DIR__ . '/../lib/operator_private.php';
require_once __DIR__ . '/../lib/worker.php';
require_once __DIR__ . '/../lib/topup.php';
require_once __DIR__ . '/_owner_auth.php';
require_once __DIR__ . '/_worker_app_auth.php';

api_require_method('POST');

function zb_worker_app_claim_request(string $deviceId, string $ownerId): ?array
{
    $device = worker_get_device($deviceId);
    if (!$device || !worker_is_available($device)) { return null; }
    if ((string)($device['z_builder_owner_id'] ?? '') !== $ownerId) { return null; }

    $pending = fb_get('TOPUP_REQUESTS/PENDING');
    if (!is_array($pending) || empty($pending)) { return null; }

    foreach ($pending as $requestId => $request) {
        if (!is_array($request)) { continue; }
        $requestOwner = (string)($request['z_builder_owner_id'] ?? $request['tenant_owner_id'] ?? '');
        if ($requestOwner !== $ownerId) { continue; }

        $operator = normalize_operator($request['operator'] ?? '');
        if ($operator === '') { continue; }
        $slot = worker_find_matching_slot($device, $operator);
        if ($slot === null) { continue; }

        $current = fb_get('TOPUP_REQUESTS/PENDING/' . $requestId);
        if (!is_array($current)) { continue; }

        $claimed = $current;
        $claimed['status'] = 'CLAIMED';
        $claimed['assigned_device_id'] = $deviceId;
        $claimed['assigned_slot'] = $slot;
        $claimed['claimed_at'] = now_ts();
        $claimed['updated_at'] = now_ts();

        if (!fb_put('TOPUP_REQUESTS/CLAIMED/' . $requestId, $claimed)) { continue; }
        if (!fb_delete('TOPUP_REQUESTS/PENDING/' . $requestId)) {
            fb_delete('TOPUP_REQUESTS/CLAIMED/' . $requestId);
            continue;
        }

        update_request_status((string)$requestId, 'CLAIMED', 'Request claimed by Z Builder worker');
        $runtime = get_operator_runtime($operator);
        $private = get_operator_private_config($operator);
        if (!is_array($runtime) || !is_array($private)) { return null; }

        return [
            'request_id' => (string)$requestId,
            'uid' => (string)($claimed['uid'] ?? ''),
            'topup_number' => (string)($claimed['topup_number'] ?? ''),
            'operator' => $operator,
            'amount' => (float)($claimed['amount'] ?? 0),
            'assigned_slot' => $slot,
            'assigned_device_id' => $deviceId,
            'dial_template' => (string)($runtime['dial_template'] ?? ''),
            'retailer_secret_pin' => (string)($private['retailer_secret_pin'] ?? ''),
            'dial_preview_masked' => (string)($runtime['masked_template'] ?? ''),
        ];
    }

    return null;
}

$body = api_read_json_body();
$ctx = zb_require_worker_app($body);
$app = $ctx['app'];
$ownerId = (string)($app['owner_id'] ?? '');
$deviceId = trim((string)($body['device_id'] ?? ''));
if ($deviceId === '') { api_response(false, 'VALIDATION_ERROR', 'device_id is required', [], 422); }

$claimed = zb_worker_app_claim_request($deviceId, $ownerId);
if (!$claimed) { api_response(false, 'NO_PENDING_REQUEST', 'No pending request found', []); }

$slot = (string)$claimed['assigned_slot'];
$dialTemplate = (string)$claimed['dial_template'];
$number = (string)$claimed['topup_number'];
$amount = (float)$claimed['amount'];
$retailerPin = (string)$claimed['retailer_secret_pin'];
$preview = str_replace(['{NUMBER}', '{AMOUNT}', '{PIN}'], [$number, (string)$amount, '*****'], $dialTemplate);
worker_mark_processing((string)$claimed['request_id'], $deviceId, $slot, $preview);

api_response(true, 'REQUEST_CLAIMED', 'Request claimed', [
    'request_id' => (string)$claimed['request_id'],
    'topup_number' => $number,
    'operator' => (string)$claimed['operator'],
    'amount' => $amount,
    'assigned_slot' => $slot,
    'dial_template' => $dialTemplate,
    'retailer_secret_pin' => $retailerPin,
    'dial_preview_masked' => $preview,
]);
