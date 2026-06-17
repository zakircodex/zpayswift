<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('POST');
api_require_admin_key();

$body = api_read_json_body();
$paymentId = trim((string)($body['payment_id'] ?? ''));
$action = strtoupper(trim((string)($body['action'] ?? 'APPROVE')));
$note = trim((string)($body['admin_note'] ?? ''));
if ($paymentId === '') { api_response(false, 'PAYMENT_ID_REQUIRED', 'Payment ID required', [], 422); }
if (!in_array($action, ['APPROVE', 'REJECT'], true)) { api_response(false, 'INVALID_ACTION', 'Action must be APPROVE or REJECT', [], 422); }

$path = 'Z_BUILDER_PAYMENTS/' . $paymentId;
$payment = fb_get($path);
if (!is_array($payment)) { api_response(false, 'PAYMENT_NOT_FOUND', 'Payment not found', [], 404); }
if (($payment['status'] ?? '') !== 'PAYMENT_PENDING') { api_response(false, 'PAYMENT_NOT_PENDING', 'Payment is not pending', ['status' => $payment['status'] ?? null], 422); }

$ownerId = (string)($payment['owner_id'] ?? '');
$now = time();
if ($action === 'REJECT') {
    $patch = ['status' => 'REJECTED', 'admin_note' => $note, 'decided_at' => zb_now_iso($now), 'updated_at' => zb_now_iso($now)];
    fb_patch($path, $patch);
    fb_patch('Z_BUILDER_OWNER_PAYMENTS/' . $ownerId . '/' . $paymentId, $patch);
    fb_patch('Z_BUILDER_OWNER_LATEST_PAYMENT/' . $ownerId, $patch);
    fb_patch('Z_BUILDER_OWNER_PLANS/' . $ownerId, ['status' => 'PAYMENT_REJECTED', 'admin_note' => $note, 'updated_at' => zb_now_iso($now)]);
    api_response(true, 'REJECTED', 'Payment rejected', ['payment_id' => $paymentId]);
}

$months = max(1, (int)($payment['months'] ?? 1));
$expires = strtotime('+' . $months . ' months', $now);
$plan = [
    'owner_id' => $ownerId,
    'plan_code' => (string)($payment['plan_code'] ?? ''),
    'title' => (string)($payment['plan_title'] ?? ''),
    'type' => 'PAID',
    'status' => 'PAID_ACTIVE',
    'price' => (float)($payment['amount'] ?? 0),
    'currency' => (string)($payment['currency'] ?? 'BDT'),
    'discount_percent' => (float)($payment['discount_percent'] ?? 0),
    'payment_id' => $paymentId,
    'started_at' => zb_now_iso($now),
    'expires_at' => zb_now_iso($expires ?: ($now + ($months * 30 * 86400))),
    'approved_at' => zb_now_iso($now),
    'updated_at' => zb_now_iso($now),
];
$patch = ['status' => 'APPROVED', 'admin_note' => $note, 'decided_at' => zb_now_iso($now), 'updated_at' => zb_now_iso($now)];
fb_patch($path, $patch);
fb_patch('Z_BUILDER_OWNER_PAYMENTS/' . $ownerId . '/' . $paymentId, $patch);
fb_patch('Z_BUILDER_OWNER_LATEST_PAYMENT/' . $ownerId, $patch);
fb_put('Z_BUILDER_OWNER_PLANS/' . $ownerId, $plan);
api_response(true, 'APPROVED', 'Payment approved and plan activated', ['plan' => $plan]);
