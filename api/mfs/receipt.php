<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/mfs.php';

$token = trim((string)($_GET['t'] ?? $_GET['token'] ?? ''));
$receipt = function_exists('mfs_load_receipt_by_token') ? mfs_load_receipt_by_token($token) : [];
if ($receipt && function_exists('mfs_find_request')) {
    $latest = mfs_find_request((string)($receipt['request_id'] ?? ''));

    if (is_array($latest) && $latest) {
        foreach ([
            'status',
            'public_status',
            'process_status',
            'message',
            'updated_at',
            'processing_at',
            'completed_at',
            'success_at',
            'receipt_url',
            'tracking_url',
        ] as $key) {
            if (array_key_exists($key, $latest)) {
                $receipt[$key] = $latest[$key];
            }
        }
    }
}
$data = $receipt ? mfs_public_receipt($receipt) : [];
$ok = !empty($data);

http_response_code($ok ? 200 : 404);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);

function receipt_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function receipt_money($value): string
{
    return number_format((float)$value, 2, '.', '');
}

function receipt_time($value): string
{
    $ts = (int)$value;
    return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '-';
}

function receipt_row(string $label, $value): string
{
    return '<div class="r-row"><span>' . receipt_h($label) . '</span><strong>' . receipt_h($value === '' ? '-' : $value) . '</strong></div>';
}

$rawStatus = strtoupper((string)($data['status'] ?? ''));
$isFinalReceipt = in_array($rawStatus, ['SUCCESS', 'SUCCESSFUL', 'DONE', 'COMPLETED'], true);
$isFailedReceipt = in_array($rawStatus, ['FAILED', 'CANCELLED'], true);
$isProcessingReceipt = $rawStatus === 'PROCESSING';
$isPendingReceipt = in_array($rawStatus, ['', 'PENDING', 'WAITING', 'WAITING_ADMIN'], true);
$displayStatus = $isFinalReceipt
    ? 'Successful'
    : ($isFailedReceipt ? 'Failed' : ($isProcessingReceipt ? 'Processing Securely' : ($isPendingReceipt ? 'Pending' : $rawStatus)));
$statusMessage = $isFinalReceipt
    ? 'Your remittance has been completed successfully.'
    : ($isFailedReceipt
        ? 'Your request could not be completed. Any held balance has been returned if applicable.'
        : ($isProcessingReceipt
            ? 'Your request is being processed securely.'
            : 'Your request has been submitted successfully.'));
$title = $ok
    ? ($isFinalReceipt ? 'Z-Pay Swift Remittance Receipt' : 'Z-Pay Swift MFS Request Tracking')
    : 'Receipt Not Found';
$subtitle = $isFinalReceipt
    ? 'Secure receipt for your completed MFS request'
    : 'Secure tracking link for your MFS request';
$receiptUrl = (string)($data['receipt_url'] ?? '');
$walletCurrency = strtoupper((string)($data['wallet_currency'] ?? 'BDT'));
$isMy = strtoupper((string)($data['country_code'] ?? '')) === 'MY'
    || strtoupper((string)($data['service_mode'] ?? $data['mode'] ?? '')) === 'REMITTANCE'
    || (float)($data['amount_rm'] ?? 0) > 0;
$rate = (float)($data['rate_myr_to_bdt'] ?? $data['exchange_rate'] ?? 0);
$amountBdt = (float)($data['amount_bdt'] ?? 0);
$amountRm = (float)($data['amount_rm'] ?? $data['amount_myr'] ?? 0);
if ($isMy && $amountRm <= 0 && $rate > 0 && $amountBdt > 0) {
    $amountRm = round($amountBdt / $rate, 2);
}
$feeRm = (float)($data['fee_rm'] ?? 0);
if ($isMy && $feeRm <= 0 && strtoupper((string)($data['fee_currency'] ?? '')) === 'MYR') {
    $feeRm = (float)($data['fee_amount'] ?? 0);
}
if ($isMy && $feeRm <= 0 && $rate > 0 && (float)($data['fee_bdt'] ?? 0) > 0) {
    $feeRm = round((float)$data['fee_bdt'] / $rate, 2);
}
$totalRm = (float)($data['total_debit_rm'] ?? $data['total_pay_myr'] ?? 0);
if ($isMy && $totalRm <= 0 && $amountRm > 0) {
    $totalRm = round($amountRm + $feeRm, 2);
}
if ($isMy && $totalRm <= 0 && $walletCurrency === 'MYR') {
    $totalRm = (float)($data['total_pay'] ?? 0);
}
if ($isMy && $totalRm <= 0 && $rate > 0 && (float)($data['total_pay'] ?? 0) > 0) {
    $totalRm = round((float)$data['total_pay'] / $rate, 2);
}
$feeBdt = (float)($data['fee_bdt'] ?? $data['fee_amount'] ?? 0);
$totalBdt = (float)($data['total_debit_bdt'] ?? 0);
if (!$isMy && $totalBdt <= 0) {
    $totalBdt = (float)($data['total_pay'] ?? 0);
}
if (!$isMy && $totalBdt <= 0) {
    $totalBdt = round($amountBdt + $feeBdt, 2);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#07111f">
  <title><?= receipt_h($title) ?></title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <style>
    :root{color-scheme:dark;--bg:#07101d;--card:#101827;--line:rgba(148,163,184,.22);--text:#f8fafc;--muted:#94a3b8;--green:#22c55e;--blue:#3b82f6}
    *{box-sizing:border-box}
    html,body{height:auto}
    body{margin:0;min-height:100vh;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;background:radial-gradient(circle at top left,rgba(59,130,246,.18),transparent 35%),linear-gradient(180deg,#07101d,#0f172a);color:var(--text);padding:24px}
    .wrap{max-width:820px;margin:0 auto}
    .card{border:1px solid var(--line);border-radius:28px;background:linear-gradient(180deg,rgba(16,24,39,.96),rgba(16,24,39,.9));box-shadow:0 24px 70px rgba(0,0,0,.34);overflow:hidden}
    .head{padding:28px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
    .brand{display:flex;gap:14px;align-items:center}
    .logo{width:52px;height:52px;border-radius:18px;background:linear-gradient(135deg,var(--green),var(--blue));display:grid;place-items:center;font-weight:900;color:#03111f}
    h1{margin:0;font-size:26px;letter-spacing:-.03em}
    p{margin:6px 0 0;color:var(--muted)}
    .status{border:1px solid rgba(245,158,11,.38);background:rgba(245,158,11,.13);color:#fde68a;border-radius:999px;padding:8px 12px;font-weight:800;font-size:12px}
    .status.success{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.12);color:#bbf7d0}
    .status.failed{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.12);color:#fecaca}
    .body{padding:24px;display:grid;gap:18px}
    .section{border:1px solid var(--line);border-radius:20px;background:rgba(15,23,42,.62);padding:18px}
    .section h2{margin:0 0 12px;font-size:15px;color:#bfdbfe;text-transform:uppercase;letter-spacing:.12em}
    .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 18px}
    .r-row{display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid rgba(148,163,184,.12)}
    .r-row:last-child{border-bottom:0}
    .r-row span{color:var(--muted);font-weight:700}
    .r-row strong{text-align:right}
    .actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
    button,a.btn{appearance:none;border:1px solid rgba(59,130,246,.32);border-radius:14px;background:rgba(59,130,246,.14);color:#dbeafe;padding:11px 14px;text-decoration:none;font-weight:800;cursor:pointer}
    button.green,a.green{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.14);color:#dcfce7}
    .copy-status{margin-top:10px;color:#bbf7d0;font-weight:800;min-height:20px}
    .not-found{padding:48px;text-align:center}
    @media(max-width:640px){body{padding:12px}.head{display:block}.status{display:inline-flex;margin-top:16px}.grid{grid-template-columns:1fr}.r-row{display:block}.r-row strong{display:block;text-align:left;margin-top:4px}.card{border-radius:22px}}
    @page{size:auto;margin:12mm}
    @media print{
      :root{color-scheme:light}
      html,body{height:auto !important;min-height:0 !important;background:#fff !important;color:#111 !important}
      body{padding:0 !important;margin:0 !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
      .wrap{max-width:100% !important;margin:0 auto !important;padding:0 !important}
      .card{box-shadow:none !important;border:1px solid #ddd !important;background:#fff !important;color:#111 !important;overflow:visible !important;border-radius:14px !important;break-before:auto !important;page-break-before:auto !important}
      .head{padding:16px 18px !important;break-inside:avoid;page-break-inside:avoid}
      .body{padding:16px 18px !important;gap:12px !important}
      .section{box-shadow:none !important;border-color:#ddd !important;background:#fff !important;color:#111 !important;break-inside:avoid;page-break-inside:avoid;padding:12px !important}
      .actions,.copy-status{display:none !important}
      .status{border-color:#ddd !important;color:#111 !important;background:#f8fafc !important}
      .status.success{color:#166534 !important;background:#dcfce7 !important}
      .status.failed{color:#991b1b !important;background:#fee2e2 !important}
      .r-row span,p{color:#555 !important}
      h1{font-size:22px !important}
    }
  </style>
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
</head>
<body>
  <main class="wrap">
    <article class="card">
      <?php if (!$ok): ?>
        <div class="not-found">
          <h1>Receipt Not Found</h1>
          <p>The receipt token is invalid or expired.</p>
        </div>
      <?php else: ?>
        <header class="head">
          <div class="brand">
            <img class="logo brand-icon" src="/assets/brand/zpay-icon.png" alt="">
            <div>
              <h1><?= receipt_h($title) ?></h1>
              <p><?= receipt_h($subtitle) ?></p>
            </div>
          </div>
          <span class="status<?= $isFinalReceipt ? ' success' : ($isFailedReceipt ? ' failed' : '') ?>"><?= receipt_h($displayStatus) ?></span>
        </header>

        <div class="body">
          <section class="section">
            <h2>Receipt</h2>
            <div class="grid">
              <?= receipt_row('Receipt ID', $data['receipt_id'] ?? '') ?>
              <?= receipt_row('Request ID', $data['request_id'] ?? '') ?>
              <?= receipt_row('Provider', $data['provider_name'] ?? $data['provider'] ?? '') ?>
              <?= receipt_row('Receiver Number', $data['receiver_number'] ?? '') ?>
              <?= receipt_row('Country', trim((string)($data['country_code'] ?? '') . ' ' . (string)($data['country'] ?? ''))) ?>
              <?= receipt_row('Mode', $data['service_mode'] ?? $data['mode'] ?? '') ?>
            </div>
          </section>

          <section class="section">
            <h2>Sender</h2>
            <div class="grid">
              <?= receipt_row('Name', $data['sender_name'] ?? '') ?>
              <?= receipt_row('Phone', $data['sender_phone'] ?? '') ?>
              <?= receipt_row('Role', strtoupper((string)($data['sender_role'] ?? ''))) ?>
              <?= receipt_row('Sender Last Digit', $data['sender_last_digit'] ?? $data['sender_details'] ?? '') ?>
            </div>
          </section>

          <section class="section">
            <h2>Amount</h2>
            <div class="grid">
              <?= receipt_row('Received Amount', 'BDT ' . receipt_money($amountBdt)) ?>
              <?php if ($isMy): ?>
                <?= receipt_row('Send Amount', 'RM ' . receipt_money($amountRm)) ?>
                <?= receipt_row('Rate', 'RM 1 = BDT ' . receipt_money($rate)) ?>
                <?= receipt_row('Fee', 'RM ' . receipt_money($feeRm)) ?>
                <?= receipt_row('Total Paid', 'RM ' . receipt_money($totalRm)) ?>
              <?php else: ?>
                <?= receipt_row('Fee', 'BDT ' . receipt_money($feeBdt)) ?>
                <?= receipt_row('Total Paid', 'BDT ' . receipt_money($totalBdt)) ?>
              <?php endif; ?>
              <?= receipt_row('Reference', $data['reference'] ?? '') ?>
              <?= receipt_row('TRXID', $data['trxid'] ?? '') ?>
            </div>
          </section>

          <section class="section">
            <h2>Timeline</h2>
            <div class="grid">
              <?= receipt_row('Created', receipt_time($data['created_at'] ?? 0)) ?>
              <?= receipt_row($isFinalReceipt ? 'Successful At' : 'Status', $isFinalReceipt ? receipt_time($data['success_at'] ?? 0) : $statusMessage) ?>
            </div>
            <div class="actions">
              <button class="green" type="button" onclick="window.print()">Download / Print PDF</button>
              <button type="button" onclick="copyReceipt()">Copy Link</button>
              <button type="button" onclick="shareReceipt()">Share</button>
            </div>
            <div class="copy-status" id="copyStatus"></div>
          </section>
        </div>
      <?php endif; ?>
    </article>
  </main>
  <script>
    const receiptUrl = <?= json_encode($receiptUrl, JSON_UNESCAPED_SLASHES) ?>;
    function receiptStatus(text){ const node=document.getElementById('copyStatus'); if(node) node.textContent=text || ''; }
    async function copyReceipt(){
      try{ await navigator.clipboard.writeText(receiptUrl || location.href); receiptStatus('Receipt link copied.'); }
      catch(e){ receiptStatus(receiptUrl || location.href); }
    }
    async function shareReceipt(){
      if(navigator.share){ await navigator.share({title:'Z-Pay Swift Receipt',url:receiptUrl || location.href}); return; }
      copyReceipt();
    }
  </script>
</body>
</html>
