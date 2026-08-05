<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('POST');
api_require_app_key();
znews_require_creator(true);

api_response(
    false,
    'ZNEWS_CREATOR_WITHDRAW_DISABLED',
    'Z Sky 24 no longer keeps a creator balance or accepts withdrawal requests. Approved period revenue is paid directly to the linked Z-Pay wallet.',
    [
        'creator_balance_enabled' => false,
        'withdraw_request_enabled' => false,
        'payout_mode' => 'PERIOD_REVIEW_DIRECT_ZPAY_PAYOUT',
    ],
    410
);
