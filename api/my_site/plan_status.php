<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_owner_auth.php';

api_require_method('GET');

$ctx = zb_require_owner_session();
$ownerId = (string)($ctx['owner']['owner_id'] ?? '');
$plan = fb_get('Z_BUILDER_OWNER_PLANS/' . $ownerId);
$selection = fb_get('Z_BUILDER_OWNER_PLAN_SELECTIONS/' . $ownerId);
$latestPayment = fb_get('Z_BUILDER_OWNER_LATEST_PAYMENT/' . $ownerId);

$now = time();
$daysLeft = null;
$isExpired = false;
if (is_array($plan) && !empty($plan['expires_at'])) {
    $endTs = strtotime((string)$plan['expires_at']);
    if ($endTs !== false) {
        $daysLeft = max(0, (int)ceil(($endTs - $now) / 86400));
        $isExpired = $endTs < $now;
        if ($isExpired && ($plan['status'] ?? '') === 'ACTIVE') {
            fb_patch('Z_BUILDER_OWNER_PLANS/' . $ownerId, ['status' => 'EXPIRED', 'updated_at' => zb_now_iso()]);
            $plan['status'] = 'EXPIRED';
        }
    }
}

$status = is_array($plan) ? (string)($plan['status'] ?? 'NO_PLAN') : 'NO_PLAN';
if (!is_array($plan)) { $plan = ['status' => 'NO_PLAN']; }

api_response(true, 'SUCCESS', 'Plan status loaded', [
    'plan' => $plan,
    'selection' => is_array($selection) ? $selection : null,
    'latest_payment' => is_array($latestPayment) ? $latestPayment : null,
    'status' => $status,
    'days_left' => $daysLeft,
    'is_expired' => $isExpired,
    'setup_allowed' => in_array($status, ['ACTIVE', 'FREE_ACTIVE', 'PAID_ACTIVE'], true) && !$isExpired,
]);
