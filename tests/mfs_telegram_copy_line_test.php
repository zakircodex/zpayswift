<?php
declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require_once dirname(__DIR__) . '/api/lib/mfs.php';

function copy_line_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$personal = [
    'receiver_number' => '01872605597',
    'provider' => 'BKASH',
    'amount_bdt' => 5100.00,
    'account_type' => 'PERSONAL',
];

$line = mfs_telegram_copy_line($personal);
copy_line_expect($line === 'Z-Pay Swift: 01872605597=Bkash= 5100/- P', 'Personal bKash line mismatch');
copy_line_expect(mfs_telegram_copy_block($personal) === '<pre>' . $line . '</pre>', 'Copy block mismatch');

$agent = [
    'receiver_number' => '01309096677',
    'provider' => 'NAGAD',
    'amount_bdt' => 500.50,
    'account_type' => 'AGENT',
];
copy_line_expect(mfs_telegram_copy_line($agent) === 'Z-Pay Swift: 01309096677=Nagad= 500.50/- A', 'Agent Nagad line mismatch');

$invalid = $personal;
$invalid['receiver_number'] = '<script>alert(1)</script>';
copy_line_expect(mfs_telegram_copy_block($invalid) === '', 'Invalid receiver number was included');

$invalid = $personal;
$invalid['account_type'] = 'UNKNOWN';
copy_line_expect(mfs_telegram_copy_block($invalid) === '', 'Unknown account type was included');

echo "MFS Telegram copy line tests passed.\n";
