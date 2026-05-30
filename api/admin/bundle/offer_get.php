<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/bundle.php';

api_require_method('GET');
auth_require_admin_session();

$offerId = trim((string)($_GET['offer_id'] ?? ''));

if ($offerId === '') {
    api_response(false, 'VALIDATION_ERROR', 'offer_id is required', [], 422);
}

$offer = bundle_load_offer($offerId);

if (!is_array($offer) || empty($offer)) {
    api_response(false, 'NOT_FOUND', 'Bundle offer not found', [], 404);
}

$offer['offer_id'] = (string)($offer['offer_id'] ?? $offerId);
$offer['expired'] = bundle_is_expired($offer);

api_response(true, 'SUCCESS', 'Bundle offer loaded', [
    'offer' => $offer,
]);