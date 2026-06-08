<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/app_paths.php';

function zps_docs_is_json(): bool
{
    $format = strtolower(trim((string)($_GET['format'] ?? '')));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $format === 'json' || str_contains($accept, 'application/json');
}

function zps_docs_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function zps_docs_endpoint(string $method, string $path, string $auth, string $description): array
{
    return [
        'method' => $method,
        'path' => $path,
        'auth' => $auth,
        'description' => $description,
    ];
}

$sections = [
    'User APIs' => [
        zps_docs_endpoint('POST', '/api/auth/user_login_start.php', 'X-APP-KEY', 'Start user login OTP flow.'),
        zps_docs_endpoint('POST', '/api/auth/user_login_verify_otp.php', 'X-APP-KEY', 'Verify user login OTP and receive a session token.'),
        zps_docs_endpoint('POST', '/api/auth/user_register_send_otp.php', 'X-APP-KEY', 'Start user registration OTP flow.'),
        zps_docs_endpoint('POST', '/api/auth/user_register_confirm.php', 'X-APP-KEY', 'Confirm registration OTP.'),
        zps_docs_endpoint('POST', '/api/auth/user_forgot_send_otp.php', 'X-APP-KEY', 'Start password or PIN reset OTP flow.'),
        zps_docs_endpoint('POST', '/api/auth/user_forgot_verify_otp.php', 'X-APP-KEY', 'Confirm reset OTP and update credentials.'),
        zps_docs_endpoint('GET/POST', '/api/user/proxy.php', 'X-APP-KEY + X-SESSION-TOKEN', 'User dashboard, wallet, history, topup, bundle, and MFS actions.'),
        zps_docs_endpoint('GET', '/api/topup/status.php?request_id=ID', 'X-APP-KEY + X-SESSION-TOKEN', 'Check a user topup request status.'),
        zps_docs_endpoint('GET', '/api/mfs/receipt.php?t=TOKEN', 'Receipt token', 'Public token-based MFS tracking and receipt page.'),
        zps_docs_endpoint('GET', '/api/mfs/receipt_api.php?t=TOKEN', 'Receipt token', 'JSON receipt data for apps.'),
    ],
    'Admin APIs' => [
        zps_docs_endpoint('POST', '/api/auth/admin_login_start.php', 'X-APP-KEY', 'Start admin login OTP flow.'),
        zps_docs_endpoint('POST', '/api/auth/admin_login_verify_otp.php', 'X-APP-KEY', 'Verify admin login OTP.'),
        zps_docs_endpoint('GET/POST', '/api/admin/proxy.php', 'Admin session', 'Admin dashboard, users, wallet, topup, bundle, MFS, config, and worker actions.'),
        zps_docs_endpoint('POST', '/api/admin/topup/create.php', 'Admin session', 'Create an admin topup request.'),
        zps_docs_endpoint('GET', '/api/admin/operators/list.php', 'Admin session', 'List operator runtime configuration with secret values masked.'),
        zps_docs_endpoint('GET', '/api/admin/operators/get.php', 'Admin session', 'Load one operator runtime configuration with secret values masked.'),
        zps_docs_endpoint('POST', '/api/admin/operators/save.php', 'Admin session', 'Save operator runtime/private configuration.'),
        zps_docs_endpoint('GET/POST', '/api/admin/mfs.php', 'Admin session', 'Admin bKash/Nagad management page and actions.'),
    ],
    'Subadmin APIs' => [
        zps_docs_endpoint('GET/POST', '/api/subadmin/proxy.php', 'Subadmin session', 'Subadmin dashboard, wallet, API keys, MFS, topup, bundle, and request logs.'),
        zps_docs_endpoint('POST', '/api/public_api/topup_create.php', 'X-API-KEY', 'Public subadmin/API topup creation.'),
        zps_docs_endpoint('GET', '/api/public_api/bundle_offers.php', 'X-API-KEY', 'Public subadmin/API bundle offer list.'),
        zps_docs_endpoint('POST', '/api/public_api/bundle_create.php', 'X-API-KEY', 'Public subadmin/API bundle request creation.'),
    ],
    'Worker APIs' => [
        zps_docs_endpoint('POST', '/api/worker/heartbeat.php', 'X-WORKER-KEY', 'Report worker device availability.'),
        zps_docs_endpoint('POST', '/api/worker/active.php', 'X-WORKER-KEY', 'Mark worker device active or busy.'),
        zps_docs_endpoint('POST', '/api/worker/claim.php', 'X-WORKER-KEY', 'Claim a pending topup request for automatic processing.'),
        zps_docs_endpoint('POST', '/api/worker/start_dial.php', 'X-WORKER-KEY', 'Mark claimed work as dial started where applicable.'),
        zps_docs_endpoint('POST', '/api/worker/result.php', 'X-WORKER-KEY', 'Submit worker topup success or failure result.'),
    ],
    'Telegram Webhooks' => [
        zps_docs_endpoint('POST', '/api/telegram/webhook.php?key=WEBHOOK_SECRET', 'Telegram secret token or hidden key', 'Unified Telegram webhook router for Bundle, MFS, and Topup callbacks.'),
        zps_docs_endpoint('POST', '/api/telegram/bundle_webhook.php?key=WEBHOOK_SECRET', 'Telegram secret token or hidden key', 'Bundle callback handler.'),
        zps_docs_endpoint('POST', '/api/telegram/mfs_webhook.php?key=WEBHOOK_SECRET', 'Telegram secret token or hidden key', 'MFS callback and sender-detail handler.'),
        zps_docs_endpoint('POST', '/api/telegram/topup_webhook.php?key=WEBHOOK_SECRET', 'Telegram secret token or hidden key', 'Topup manual fallback callback handler.'),
    ],
];

$data = [
    'base_urls' => [
        'web_user' => app_url('user'),
        'admin' => app_url('admin'),
        'subadmin' => app_url('subadmin'),
        'api_base' => app_api_url(),
        'legacy_api' => [
            app_url('zpayswift/api'),
            app_url('zawtopup/api'),
        ],
    ],
    'auth_headers' => [
        'X-APP-KEY' => 'Required where applicable. Value is private and not shown.',
        'X-SESSION-TOKEN' => 'User/admin/subadmin session token where applicable.',
        'Authorization' => 'Bearer SESSION_TOKEN is accepted where implemented.',
        'X-WORKER-KEY' => 'Worker-only key. Value is private and not shown.',
        'X-API-KEY' => 'Subadmin public API key. Value is private and not shown.',
    ],
    'endpoints' => $sections,
    'response_shape' => [
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Human readable status message',
        'data' => [],
    ],
];

if (zps_docs_is_json()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'code' => 'SUCCESS',
        'message' => 'Z-Pay Swift API endpoints',
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Z-Pay Swift API Endpoints</title>
  <style>
    :root { color-scheme: dark; --bg:#071528; --card:rgba(15,31,58,.86); --line:rgba(148,163,184,.22); --text:#e5eefc; --muted:#9fb2cc; --brand:#34d399; }
    * { box-sizing: border-box; }
    body { margin:0; font-family: Inter, system-ui, -apple-system, Segoe UI, sans-serif; background: radial-gradient(circle at top left, #0d2a52, var(--bg)); color:var(--text); }
    main { width:min(1120px, calc(100% - 32px)); margin:32px auto; }
    header, section { background:var(--card); border:1px solid var(--line); border-radius:22px; padding:24px; box-shadow:0 24px 70px rgba(0,0,0,.28); margin-bottom:18px; }
    h1, h2 { margin:0 0 10px; }
    p { color:var(--muted); line-height:1.6; }
    code { color:#bbf7d0; background:rgba(52,211,153,.1); border:1px solid rgba(52,211,153,.2); padding:2px 6px; border-radius:8px; }
    .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; }
    .pill { border:1px solid var(--line); border-radius:16px; padding:12px; background:rgba(255,255,255,.04); }
    table { width:100%; border-collapse:collapse; overflow:hidden; border-radius:16px; }
    th, td { text-align:left; border-bottom:1px solid var(--line); padding:12px; vertical-align:top; }
    th { color:#93c5fd; font-size:12px; text-transform:uppercase; letter-spacing:.08em; }
    td { color:var(--text); }
    .muted { color:var(--muted); }
    .method { color:var(--brand); font-weight:700; white-space:nowrap; }
    @media (max-width: 720px) { main { width:min(100% - 20px, 1120px); margin:18px auto; } header, section { padding:18px; border-radius:18px; } th:nth-child(4), td:nth-child(4) { display:none; } }
  </style>
</head>
<body>
<main>
  <header>
    <h1>Z-Pay Swift API Endpoints</h1>
    <p>Public developer reference for clean routes and API paths. Secrets, tokens, PINs, hashes, and private keys are intentionally hidden.</p>
    <p><a href="?format=json"><code>?format=json</code></a></p>
  </header>

  <section>
    <h2>Base URLs</h2>
    <div class="grid">
      <?php foreach ($data['base_urls'] as $label => $value): ?>
        <div class="pill">
          <strong><?= zps_docs_h(str_replace('_', ' ', strtoupper($label))) ?></strong><br>
          <?php if (is_array($value)): ?>
            <?php foreach ($value as $item): ?><code><?= zps_docs_h($item) ?></code><br><?php endforeach; ?>
          <?php else: ?>
            <code><?= zps_docs_h($value) ?></code>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section>
    <h2>Auth Headers</h2>
    <div class="grid">
      <?php foreach ($data['auth_headers'] as $header => $description): ?>
        <div class="pill"><code><?= zps_docs_h($header) ?></code><p><?= zps_docs_h($description) ?></p></div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php foreach ($sections as $title => $endpoints): ?>
    <section>
      <h2><?= zps_docs_h($title) ?></h2>
      <table>
        <thead>
          <tr><th>Method</th><th>Path</th><th>Auth</th><th>Description</th></tr>
        </thead>
        <tbody>
          <?php foreach ($endpoints as $endpoint): ?>
            <tr>
              <td class="method"><?= zps_docs_h($endpoint['method']) ?></td>
              <td><code><?= zps_docs_h($endpoint['path']) ?></code></td>
              <td class="muted"><?= zps_docs_h($endpoint['auth']) ?></td>
              <td><?= zps_docs_h($endpoint['description']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endforeach; ?>

  <section>
    <h2>Response Shape</h2>
    <pre><code>{
  "ok": true,
  "code": "SUCCESS",
  "message": "Human readable status message",
  "data": {}
}</code></pre>
  </section>
</main>
</body>
</html>
