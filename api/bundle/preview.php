<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/operators.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/bundle.php';

api_require_method('POST');
api_require_app_key();

$auth = auth_require_user(true);
$user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
$uid = trim((string)($user['uid'] ?? ''));
$body = api_read_json_body();

$offerId = trim((string)($body['offer_id'] ?? ''));
$bundleNumber = trim((string)($body['bundle_number'] ?? $body['number'] ?? ''));
$checkOnly = (bool)($body['check_only'] ?? false);

$res = bundle_preview_for_user($uid, $offerId, $bundleNumber, $user);
if (!($res['ok'] ?? false)) {
    $code = (string)($res['code'] ?? 'BUNDLE_PREVIEW_FAILED');
    $httpStatus = 422;
    if ($code === 'ACCOUNT_INACTIVE') {
        $httpStatus = 403;
    } elseif ($code === 'USER_NOT_FOUND') {
        $httpStatus = 404;
    }
    api_response(false, $code, (string)($res['message'] ?? 'Bundle preview failed'), (array)($res['data'] ?? []), $httpStatus);
}

$data = bundle_with_financial_aliases((array)($res['data'] ?? []));

if (!$checkOnly) {
    $previewPayload = $data;
    $previewPayload['uid'] = $uid;
    $previewPayload['verified_by'] = bundle_clean_string((string)($body['verified_by'] ?? ''));
    $previewPayload['expires_at'] = bundle_now() + 300;

    $previewToken = bundle_create_preview_token($previewPayload);
    if ($previewToken === '') {
        api_response(false, 'BUNDLE_PREVIEW_FAILED', 'Bundle preview could not be created.', [], 500);
    }

    $data['preview_token'] = $previewToken;
    $data['expires_in'] = 300;
}

api_response(true, (string)($res['code'] ?? 'SUCCESS'), (string)($res['message'] ?? 'Bundle preview ready'), $data);
