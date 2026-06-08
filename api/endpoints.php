<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/app_paths.php';

function zps_docs_is_json(): bool
{
    $format = strtolower(trim((string)($_GET['format'] ?? '')));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $format === 'json' || str_contains($accept, 'application/json');
}

function zps_docs_is_developer(): bool
{
    return strtolower(trim((string)($_GET['view'] ?? ''))) === 'developer';
}

function zps_docs_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function zps_docs_endpoint(string $method, string $path, string $access, string $description): array
{
    return [
        'method' => $method,
        'path' => $path,
        'access' => $access,
        'description' => $description,
    ];
}

function zps_docs_service(string $name, string $description, string $safeRoute): array
{
    return [
        'name' => $name,
        'description' => $description,
        'safe_route' => $safeRoute,
    ];
}

$mainLinks = [
    ['label' => 'User Panel', 'url' => 'https://zpayswift.com/user', 'description' => 'Customer dashboard, wallet, topup, send money, bundle, and history.'],
    ['label' => 'Track Request', 'url' => 'https://zpayswift.com/track', 'description' => 'Open a token-based request tracking link.'],
    ['label' => 'Download APK', 'url' => 'https://zpayswift.com/download-apk', 'description' => 'Download the Android app when available.'],
    ['label' => 'Apply as Partner', 'url' => 'https://zpayswift.com/apply-subadmin', 'description' => 'Apply for partner access through the public partner form.'],
    ['label' => 'API Base', 'url' => 'https://zpayswift.com/api', 'description' => 'Clean public API base for app integrations.'],
];

$publicTracking = [
    'title' => 'MFS Tracking / Receipt',
    'description' => 'Tracking links are token-based. Paste a valid token or Z-Pay Swift tracking link on the public tracking page.',
    'tracking_url' => 'https://zpayswift.com/track',
    'token_url' => 'https://zpayswift.com/track/TRACKING_TOKEN',
    'api_base' => 'https://zpayswift.com/api',
];

$serviceActions = [
    zps_docs_service('Login', 'Secure OTP/session based login for users.', '/user'),
    zps_docs_service('Register', 'Customer registration and OTP verification.', '/user'),
    zps_docs_service('Dashboard', 'Wallet balance, request summaries, and quick services.', '/user'),
    zps_docs_service('Topup', 'Create and track mobile topup requests.', '/user'),
    zps_docs_service('bKash/Nagad Send Money', 'Create MFS send money requests and track status with receipt links.', '/user'),
    zps_docs_service('Bundle', 'Browse and request available bundle offers.', '/user'),
    zps_docs_service('History', 'View current and previous request history where available.', '/user'),
    zps_docs_service('Tracking', 'Open token-based receipt/tracking links.', '/track'),
    zps_docs_service('Partner Apply', 'Apply for partner access without sharing any secret key.', '/apply-subadmin'),
];

$developerAccess = [
    'summary' => 'Developer and app access requires valid credentials. Secret values are never displayed on this page.',
    'headers' => [
        ['name' => 'X-APP-KEY', 'purpose' => 'Required by app endpoints where configured.', 'value' => 'Hidden'],
        ['name' => 'X-SESSION-TOKEN', 'purpose' => 'User session token where applicable.', 'value' => 'Hidden'],
        ['name' => 'Authorization: Bearer', 'purpose' => 'Bearer session token where supported.', 'value' => 'Hidden'],
        ['name' => 'X-API-KEY', 'purpose' => 'Partner public API integration key.', 'value' => 'Hidden'],
        ['name' => 'X-WORKER-KEY', 'purpose' => 'Worker app access only.', 'value' => 'Hidden'],
    ],
];

$securityNotice = [
    'Password, PIN, token, hash, private keys, bot tokens, SMS keys, and Firebase credentials are never shown here.',
    'Internal endpoints are protected by app keys, sessions, roles, worker keys, API keys, or webhook secrets.',
    'Public receipt pages require a non-guessable tracking token.',
];

$developerEndpoints = [
    'User APIs' => [
        zps_docs_endpoint('POST', 'user.login.start', 'Protected app access', 'Start user login.'),
        zps_docs_endpoint('POST', 'user.login.verify', 'Protected app access', 'Verify login and create a session.'),
        zps_docs_endpoint('POST', 'user.register.start', 'Protected app access', 'Start registration.'),
        zps_docs_endpoint('POST', 'user.register.confirm', 'Protected app access', 'Confirm registration.'),
        zps_docs_endpoint('GET/POST', 'user.dashboard', 'Protected user session', 'User dashboard actions.'),
        zps_docs_endpoint('POST', 'topup.create', 'Protected user session', 'Create a topup request.'),
        zps_docs_endpoint('POST', 'send_money.create', 'Protected user session', 'Create a bKash/Nagad send money request.'),
        zps_docs_endpoint('GET', 'request.tracking', 'Public token', 'Open public tracking page.'),
    ],
    'Subadmin Public APIs' => [
        zps_docs_endpoint('POST', 'partner.topup.create', 'Protected API key', 'Create topup request.'),
        zps_docs_endpoint('GET', 'partner.bundle.offers', 'Protected API key', 'List available bundle offers.'),
        zps_docs_endpoint('POST', 'partner.bundle.create', 'Protected API key', 'Create bundle request.'),
    ],
    'Worker App' => [
        zps_docs_endpoint('POST', 'worker.heartbeat', 'Protected worker key', 'Worker heartbeat.'),
        zps_docs_endpoint('POST', 'worker.claim', 'Protected worker key', 'Claim pending topup.'),
        zps_docs_endpoint('POST', 'worker.result', 'Protected worker key', 'Submit topup result.'),
    ],
    'Telegram Webhook' => [
        zps_docs_endpoint('POST', 'telegram.webhook', 'Protected webhook secret', 'Unified Telegram webhook route.'),
        zps_docs_endpoint('POST', 'telegram.bundle_callback', 'Protected webhook secret', 'Bundle callback route.'),
        zps_docs_endpoint('POST', 'telegram.mfs_callback', 'Protected webhook secret', 'MFS callback route.'),
        zps_docs_endpoint('POST', 'telegram.topup_callback', 'Protected webhook secret', 'Topup callback route.'),
    ],
];

$data = [
    'title' => 'Z-Pay Swift API & Service Links',
    'main_links' => $mainLinks,
    'public_tracking' => $publicTracking,
    'service_actions' => $serviceActions,
    'developer_access' => $developerAccess,
    'security_notice' => $securityNotice,
    'developer_endpoints' => $developerEndpoints,
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
        'message' => 'Z-Pay Swift API and service links',
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$developerView = zps_docs_is_developer();
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Z-Pay Swift API & Service Links</title>
  <style>
    :root {
      color-scheme: dark;
      --bg: #061326;
      --bg2: #0b2a51;
      --card: rgba(13, 30, 58, .86);
      --card2: rgba(20, 42, 78, .72);
      --line: rgba(148, 163, 184, .22);
      --text: #e6f0ff;
      --muted: #9fb2cc;
      --brand: #34d399;
      --blue: #60a5fa;
      --warn: #fbbf24;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif;
      background:
        radial-gradient(circle at 20% 0%, rgba(52, 211, 153, .22), transparent 28rem),
        radial-gradient(circle at 90% 10%, rgba(96, 165, 250, .18), transparent 24rem),
        linear-gradient(145deg, var(--bg2), var(--bg));
      color: var(--text);
    }
    a { color: inherit; text-decoration: none; }
    main { width: min(1120px, calc(100% - 32px)); margin: 32px auto; }
    header, section {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 24px;
      padding: 24px;
      box-shadow: 0 26px 80px rgba(0,0,0,.30);
      margin-bottom: 18px;
      backdrop-filter: blur(14px);
    }
    .hero {
      display: grid;
      grid-template-columns: 1.3fr .7fr;
      gap: 18px;
      align-items: stretch;
    }
    .hero h1 { font-size: clamp(28px, 5vw, 48px); line-height: 1.05; margin: 0 0 14px; }
    h2 { margin: 0 0 12px; font-size: 22px; }
    h3 { margin: 0 0 8px; font-size: 17px; }
    p { color: var(--muted); line-height: 1.6; margin: 0 0 12px; }
    code {
      color: #bbf7d0;
      background: rgba(52, 211, 153, .10);
      border: 1px solid rgba(52, 211, 153, .22);
      padding: 3px 7px;
      border-radius: 9px;
      overflow-wrap: anywhere;
    }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px; }
    .card, .link-card {
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 16px;
      background: var(--card2);
      min-width: 0;
    }
    .link-card { display: flex; flex-direction: column; gap: 10px; }
    .url-row { display: flex; gap: 8px; align-items: center; }
    .url-row code { flex: 1; min-width: 0; }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid rgba(52, 211, 153, .32);
      background: rgba(52, 211, 153, .14);
      color: var(--text);
      padding: 9px 12px;
      border-radius: 12px;
      font-weight: 700;
      cursor: pointer;
      white-space: nowrap;
    }
    .btn.secondary { border-color: var(--line); background: rgba(255,255,255,.06); }
    .btn:hover { transform: translateY(-1px); }
    .badge {
      display: inline-flex;
      width: fit-content;
      gap: 6px;
      align-items: center;
      color: #d1fae5;
      background: rgba(52, 211, 153, .12);
      border: 1px solid rgba(52, 211, 153, .25);
      padding: 6px 10px;
      border-radius: 999px;
      font-size: 13px;
      font-weight: 800;
    }
    .notice-list { display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; }
    .notice-list li { border: 1px solid var(--line); border-radius: 14px; padding: 12px; background: rgba(255,255,255,.04); color: var(--muted); }
    .tracking {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    table { width: 100%; border-collapse: collapse; overflow: hidden; border-radius: 16px; }
    th, td { text-align: left; border-bottom: 1px solid var(--line); padding: 12px; vertical-align: top; }
    th { color: #93c5fd; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
    td { color: var(--text); }
    .method { color: var(--brand); font-weight: 800; white-space: nowrap; }
    .muted { color: var(--muted); }
    .developer-note { border-color: rgba(251, 191, 36, .25); background: rgba(251, 191, 36, .08); }
    @media (max-width: 760px) {
      main { width: min(100% - 20px, 1120px); margin: 18px auto; }
      header, section { padding: 18px; border-radius: 20px; }
      .hero, .tracking { grid-template-columns: 1fr; }
      .url-row { flex-direction: column; align-items: stretch; }
      .btn { width: 100%; }
      table, thead, tbody, tr, th, td { display: block; width: 100%; }
      thead { display: none; }
      tr { border: 1px solid var(--line); border-radius: 14px; margin-bottom: 10px; padding: 10px; background: rgba(255,255,255,.04); }
      td { border-bottom: 0; padding: 6px 0; }
      td::before { content: attr(data-label); display: block; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
    }
  </style>
</head>
<body>
<main>
  <header class="hero">
    <div>
      <span class="badge">Z-Pay Swift</span>
      <h1>Z-Pay Swift API & Service Links</h1>
      <p>Clean public links for user services, tracking, partner access, and app setup. Internal operations and secret values are intentionally hidden.</p>
      <p>
        <a class="btn" href="?view=developer">Developer View</a>
        <a class="btn secondary" href="?format=json">JSON</a>
      </p>
    </div>
    <div class="card developer-note">
      <h3>Security-first docs</h3>
      <p>No passwords, PINs, tokens, hashes, private keys, bot tokens, SMS keys, or server paths are shown here.</p>
    </div>
  </header>

  <section>
    <h2>Main Links</h2>
    <div class="grid">
      <?php foreach ($mainLinks as $link): ?>
        <div class="link-card">
          <h3><?= zps_docs_h($link['label']) ?></h3>
          <p><?= zps_docs_h($link['description']) ?></p>
          <div class="url-row">
            <code><?= zps_docs_h($link['url']) ?></code>
            <button class="btn secondary" type="button" data-copy="<?= zps_docs_h($link['url']) ?>">Copy</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section>
    <h2>Public Tracking</h2>
    <p><?= zps_docs_h($publicTracking['description']) ?></p>
    <div class="tracking">
      <div class="card">
        <h3>Receipt Page</h3>
        <div class="url-row">
          <code><?= zps_docs_h($publicTracking['token_url']) ?></code>
          <button class="btn secondary" type="button" data-copy="<?= zps_docs_h($publicTracking['token_url']) ?>">Copy</button>
        </div>
      </div>
      <div class="card">
        <h3>API Base</h3>
        <div class="url-row">
          <code><?= zps_docs_h($publicTracking['api_base']) ?></code>
          <button class="btn secondary" type="button" data-copy="<?= zps_docs_h($publicTracking['api_base']) ?>">Copy</button>
        </div>
      </div>
    </div>
  </section>

  <section>
    <h2>Services</h2>
    <div class="grid">
      <?php foreach ($serviceActions as $service): ?>
        <div class="card">
          <h3><?= zps_docs_h($service['name']) ?></h3>
          <p><?= zps_docs_h($service['description']) ?></p>
          <code><?= zps_docs_h($service['safe_route']) ?></code>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section>
    <h2>App / Developer Access</h2>
    <p><?= zps_docs_h($developerAccess['summary']) ?></p>
    <div class="grid">
      <?php foreach ($developerAccess['headers'] as $header): ?>
        <div class="card">
          <h3><?= zps_docs_h($header['name']) ?></h3>
          <p><?= zps_docs_h($header['purpose']) ?></p>
          <code>Value: <?= zps_docs_h($header['value']) ?></code>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section>
    <h2>Security Notice</h2>
    <ul class="notice-list">
      <?php foreach ($securityNotice as $notice): ?>
        <li><?= zps_docs_h($notice) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($developerView): ?>
    <?php foreach ($developerEndpoints as $title => $endpoints): ?>
      <section>
        <h2><?= zps_docs_h($title) ?></h2>
        <table>
          <thead>
            <tr><th>Method</th><th>Route Name</th><th>Access</th><th>Description</th></tr>
          </thead>
          <tbody>
            <?php foreach ($endpoints as $endpoint): ?>
              <tr>
                <td class="method" data-label="Method"><?= zps_docs_h($endpoint['method']) ?></td>
                <td data-label="Path"><code><?= zps_docs_h($endpoint['path']) ?></code></td>
                <td class="muted" data-label="Access"><?= zps_docs_h($endpoint['access']) ?></td>
                <td data-label="Description"><?= zps_docs_h($endpoint['description']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<script>
document.querySelectorAll('[data-copy]').forEach((button) => {
  button.addEventListener('click', async () => {
    const value = button.getAttribute('data-copy') || '';
    try {
      await navigator.clipboard.writeText(value);
      button.textContent = 'Copied';
      setTimeout(() => { button.textContent = 'Copy'; }, 1200);
    } catch (error) {
      button.textContent = 'Select & copy';
      setTimeout(() => { button.textContent = 'Copy'; }, 1600);
    }
  });
});
</script>
</body>
</html>
