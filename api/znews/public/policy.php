<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/creator_payout_batches.php';
require_once dirname(__DIR__) . '/lib/creator_view_policy.php';
require_once dirname(__DIR__) . '/lib/comments/publication.php';

api_require_method('GET');

api_response(
    true,
    'ZNEWS_CREATOR_POLICY_OK',
    'Z Sky 24 creator revenue policy loaded.',
    [
        'revenue_mode' => 'PERIOD_REVIEW_DIRECT_ZPAY_PAYOUT',
        'revenue_provider' => 'ADSTERRA',
        'revenue_base_currency' => 'USD',
        'performance_review_days' => 7,
        'payout_cycle' => 'MONTHLY',
        'safety_reserve_percent' => 10,
        'creator_pool_percent_of_net' => 40,
        'platform_share_percent_of_net' => 60,
        'creator_effective_percent_of_gross' => 36,
        'platform_effective_percent_of_gross' => 54,
        'creator_balance_enabled' => false,
        'withdraw_request_enabled' => false,
        'automatic_per_ad_credit_enabled' => false,
        'payout_destination' => 'LINKED_ZPAY_WALLET',
        'supported_wallet_currencies' => ['BDT', 'MYR'],
        'active_creator_required' => true,
        'active_zpay_account_required' => true,
        'payout_batch_limit' => znews_creator_payout_batch_limit(),
        'ads_for_authenticated_creators' => false,
        'guest_view_window_limit' => znews_guest_view_window_limit(),
        'guest_view_window_seconds' => znews_guest_view_window_seconds(),
        'client_submitted_revenue_allowed' => false,
        'ad_clicks_used_for_creator_share' => false,
        'instant_comments_enabled' => znews_instant_comments_enabled(),
    ]
);
