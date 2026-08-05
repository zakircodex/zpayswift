<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

api_require_method('GET');
api_require_app_key();
znews_require_creator(true);

api_response(
    true,
    'ZNEWS_CREATOR_BALANCE_DISABLED',
    'Z Sky 24 uses period reviews and direct Z-Pay payouts.',
    [
        'revenue_mode' => 'PERIOD_REVIEW_DIRECT_ZPAY_PAYOUT',
        'creator_balance_enabled' => false,
        'main_wallet_transfer_enabled' => false,
        'withdraw_request_enabled' => false,
        'balances' => [],
    ]
);
