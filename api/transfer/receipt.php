<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/wallet.php';
require_once dirname(__DIR__) . '/lib/mobile_dashboard.php';
require_once dirname(__DIR__) . '/lib/mobile_transfer.php';

$token = trim((string)($_GET['t'] ?? $_GET['token'] ?? ''));
$receipt = zpay_transfer_load_receipt_by_token($token);
$data = $receipt ? zpay_transfer_public_receipt($receipt) : [];
$ok = !empty($data);

http_response_code($ok ? 200 : 404);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow', true);

function transfer_receipt_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function transfer_receipt_time($value): string
{
    $ts = (int)$value;
    return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '-';
}

function transfer_receipt_row(string $label, $value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    return '<div class="r-row"><span>' . transfer_receipt_h($label) . '</span><strong>' . transfer_receipt_h($text) . '</strong></div>';
}

$status = strtoupper((string)($data['status'] ?? ''));
$displayStatus = in_array($status, ['SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'DONE'], true)
    ? 'Successful'
    : ($status === '' ? 'Pending' : ucfirst(strtolower($status)));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#07111f">
  <title><?= transfer_receipt_h($ok ? 'Z-Pay Swift Transfer Receipt' : 'Receipt Not Found') ?></title>
  <style>
    :root{color-scheme:dark;--bg:#07101d;--card:#101827;--line:rgba(148,163,184,.22);--text:#f8fafc;--muted:#94a3b8;--green:#22c55e;--blue:#3b82f6}
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif;background:radial-gradient(circle at top left,rgba(34,197,94,.18),transparent 34%),linear-gradient(180deg,#07101d,#0f172a);color:var(--text);padding:24px}
    .wrap{max-width:760px;margin:0 auto}
    .card{border:1px solid var(--line);border-radius:28px;background:linear-gradient(180deg,rgba(16,24,39,.96),rgba(16,24,39,.9));box-shadow:0 24px 70px rgba(0,0,0,.34);overflow:hidden}
    .head{padding:28px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;gap:16px;align-items:flex-start}
    .brand{display:flex;gap:14px;align-items:center}
    .logo{width:52px;height:52px;border-radius:18px;background:linear-gradient(135deg,var(--green),var(--blue));display:grid;place-items:center;font-weight:900;color:#03111f}
    h1{margin:0;font-size:26px;letter-spacing:-.03em}
    p{margin:6px 0 0;color:var(--muted)}
    .status{border:1px solid rgba(34,197,94,.35);background:rgba(34,197,94,.12);color:#bbf7d0;border-radius:999px;padding:8px 12px;font-weight:800;font-size:12px}
    .body{padding:24px;display:grid;gap:18px}
    .section{border:1px solid var(--line);border-radius:20px;background:rgba(15,23,42,.62);padding:18px}
    .section h2{margin:0 0 12px;font-size:15px;color:#bfdbfe;text-transform:uppercase;letter-spacing:.12em}
    .r-row{display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid rgba(148,163,184,.12)}
    .r-row:last-child{border-bottom:0}
    .r-row span{color:var(--muted);font-weight:700}
    .r-row strong{text-align:right}
    .not-found{padding:48px;text-align:center}
    @media(max-width:640px){body{padding:12px}.head{display:block}.status{display:inline-flex;margin-top:16px}.r-row{display:block}.r-row strong{display:block;text-align:left;margin-top:4px}.card{border-radius:22px}}
    @media print{body{background:#fff !important;color:#111 !important;padding:0}.card{box-shadow:none;border:1px solid #ddd;background:#fff;color:#111}.section{background:#fff;color:#111}.r-row span,p{color:#555}}
  </style>
</head>
<body>
  <main class="wrap">
    <article class="card">
      <?php if (!$ok): ?>
        <div class="not-found">
          <h1>Receipt Not Found</h1>
          <p>This transfer receipt link is invalid or expired.</p>
        </div>
      <?php else: ?>
        <header class="head">
          <div class="brand">
            <div class="logo">Z</div>
            <div>
              <h1>Z-Pay Transfer Receipt</h1>
              <p>Secure same-currency wallet transfer receipt</p>
            </div>
          </div>
          <div class="status"><?= transfer_receipt_h($displayStatus) ?></div>
        </header>
        <section class="body">
          <div class="section">
            <h2>Transfer</h2>
            <?= transfer_receipt_row('Transfer ID', $data['transfer_id'] ?? '') ?>
            <?= transfer_receipt_row('Sender', $data['sender_name'] ?? '') ?>
            <?= transfer_receipt_row('Receiver', $data['receiver_name'] ?? '') ?>
            <?= transfer_receipt_row('Receiver Account', $data['receiver_account'] ?? '') ?>
            <?= transfer_receipt_row('Amount', $data['amount_text'] ?? '') ?>
            <?= transfer_receipt_row('Fee', $data['fee_text'] ?? '') ?>
            <?= transfer_receipt_row('Total Paid', $data['total_paid_text'] ?? '') ?>
            <?= transfer_receipt_row('Reference', $data['reference'] ?? '') ?>
            <?= transfer_receipt_row('Date', transfer_receipt_time($data['created_at'] ?? 0)) ?>
          </div>
        </section>
      <?php endif; ?>
    </article>
  </main>
</body>
</html>
