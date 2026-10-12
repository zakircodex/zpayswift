<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/api/mfs/receipt.php');
$tests = 0;

function mfs_receipt_ui_expect(bool $condition, string $message): void
{
    global $tests;
    $tests++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

mfs_receipt_ui_expect(
    str_contains($source, '.wrap{max-width:820px')
    && str_contains($source, 'grid-template-columns:repeat(2,minmax(0,1fr))'),
    'Desktop receipt layout changed unexpectedly'
);
mfs_receipt_ui_expect(
    str_contains($source, '@media(max-width:640px)')
    && str_contains($source, '@media(max-width:360px)'),
    'Mobile receipt breakpoints are missing'
);
mfs_receipt_ui_expect(
    str_contains($source, '.head{display:block;padding:20px 16px 16px}')
    && str_contains($source, '.body{padding:14px;gap:12px}')
    && str_contains($source, '.section{padding:14px;border-radius:16px;min-width:0}'),
    'Mobile receipt spacing is not compact and stable'
);
mfs_receipt_ui_expect(
    str_contains($source, 'overflow-wrap:anywhere')
    && str_contains($source, 'word-break:break-word'),
    'Long receipt identifiers can overflow on mobile'
);
mfs_receipt_ui_expect(
    str_contains($source, '.actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))')
    && str_contains($source, '.actions .green{grid-column:1/-1}')
    && str_contains($source, 'min-height:46px'),
    'Mobile receipt actions are not touch-friendly'
);
mfs_receipt_ui_expect(
    !str_contains($source, 'letter-spacing:-.03em'),
    'Receipt title uses compressed negative letter spacing'
);

echo "MFS receipt mobile UI tests passed ({$tests} assertions)\n";
