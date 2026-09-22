<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require_once dirname(__DIR__) . '/api/lib/mfs.php';

function receipt_visibility_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$receipt = [
    'receipt_id' => 'MFS_TEST_RECEIPT',
    'request_id' => 'MFS_TEST_REQUEST',
    'sender_role' => 'USER',
    'country_code' => 'MY',
    'service_mode' => 'REMITTANCE',
    'wallet_currency' => 'MYR',
    'amount_bdt' => 500.00,
    'amount_rm' => 16.13,
    'rate_myr_to_bdt' => 31.00,
    'exchange_rate' => 31.00,
    'fee_rm' => 5.00,
    'fee_bdt' => 0.00,
    'total_debit_rm' => 21.13,
    'total_pay' => 21.13,
    'total_debit' => 21.13,
    'wallet_debit' => 21.13,
    'total_pay_text' => 'RM 21.13',
    'total_debit_text' => 'RM 21.13',
    'wallet_debit_text' => 'RM 21.13',
];

$user = mfs_public_receipt($receipt);
receipt_visibility_expect(mfs_receipt_shows_payment_breakdown($user), 'USER receipt must show payment details');
foreach (['amount_rm', 'rate_myr_to_bdt', 'exchange_rate', 'fee_rm', 'total_debit_rm', 'total_pay', 'total_pay_text'] as $field) {
    receipt_visibility_expect(array_key_exists($field, $user), "USER receipt lost {$field}");
}

$hiddenFields = [
    'amount_myr', 'amount_rm', 'rate_myr_bdt', 'rate_myr_to_bdt', 'exchange_rate',
    'fee_amount', 'fee_bdt', 'fee_rm', 'total_debit_rm', 'total_pay',
    'total_debit', 'wallet_debit', 'total_pay_text', 'total_debit_text', 'wallet_debit_text',
];
foreach (['RETAILER', 'SUBADMIN', 'ADMIN', ''] as $role) {
    $restricted = mfs_public_receipt(array_replace($receipt, ['sender_role' => $role]));
    receipt_visibility_expect(!mfs_receipt_shows_payment_breakdown($restricted), "{$role} receipt must hide payment details");
    receipt_visibility_expect($restricted['amount_bdt'] === 500.00, "{$role} receipt lost received amount");
    receipt_visibility_expect($restricted['request_id'] === 'MFS_TEST_REQUEST', "{$role} receipt lost request ID");
    foreach ($hiddenFields as $field) {
        receipt_visibility_expect(!array_key_exists($field, $restricted), "{$role} receipt exposed {$field}");
    }
}

$bdReceipt = mfs_public_receipt(array_replace($receipt, [
    'sender_role' => 'RETAILER',
    'country_code' => 'BD',
    'service_mode' => 'LOCAL',
    'wallet_currency' => 'BDT',
    'amount_rm' => 0.00,
    'total_pay' => 510.00,
]));
receipt_visibility_expect(!array_key_exists('fee_bdt', $bdReceipt), 'BD retailer receipt exposed fee');
receipt_visibility_expect(($bdReceipt['total_pay'] ?? 0) === 510.00, 'BD retailer receipt lost BDT total');
receipt_visibility_expect($receipt['rate_myr_to_bdt'] === 31.00, 'Public filtering must not modify the stored receipt');

echo "MFS receipt role visibility tests passed\n";
