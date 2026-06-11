<?php
declare(strict_types=1);

$apkPath = __DIR__ . '/downloads/zpay-swift.apk';
$downloadName = 'zpay-swift.apk';

if (is_file($apkPath) && is_readable($apkPath)) {
    $size = filesize($apkPath);
    if ($size === false) {
        $size = 0;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string)$size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($apkPath);
    exit;
}

http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#07111f">
  <title>APK Download | Z-Pay Swift</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <style>
    :root{
      color-scheme:dark;
      --bg:#07111f;
      --bg2:#0b1c35;
      --card:rgba(18,40,77,.94);
      --card2:rgba(11,31,67,.94);
      --line:rgba(110,149,221,.20);
      --text:#ecf4ff;
      --muted:#9fb5d8;
      --green:#1fd760;
      --green2:#5be99c;
      --blue:#60afe5;
    }
    *{box-sizing:border-box}
    body{
      min-height:100vh;
      margin:0;
      display:grid;
      place-items:center;
      padding:20px;
      font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      background:
        radial-gradient(circle at 18% -10%,rgba(31,215,96,.18),transparent 32%),
        radial-gradient(circle at 88% 0%,rgba(96,175,229,.16),transparent 30%),
        linear-gradient(135deg,var(--bg) 0%,var(--bg2) 44%,#081325 100%);
      color:var(--text);
    }
    a{color:inherit;text-decoration:none}
    .card{
      width:min(520px,100%);
      padding:24px;
      border:1px solid var(--line);
      border-radius:26px;
      background:linear-gradient(180deg,var(--card),var(--card2));
      box-shadow:0 24px 70px rgba(0,0,0,.32);
      text-align:center;
    }
    .mark{
      width:58px;
      height:58px;
      margin:0 auto 16px;
      display:grid;
      place-items:center;
      border-radius:20px;
      color:#04120b;
      font-weight:1000;
      background:linear-gradient(135deg,var(--green),var(--green2));
      box-shadow:0 14px 28px rgba(31,215,96,.18);
    }
    h1{
      margin:0;
      color:#f8fbff;
      font-size:28px;
      line-height:1.1;
      letter-spacing:-.04em;
    }
    p{
      margin:12px 0 0;
      color:var(--muted);
      font-size:15px;
      line-height:1.6;
    }
    .message{
      margin-top:16px;
      padding:14px;
      border:1px solid rgba(96,175,229,.22);
      border-radius:18px;
      background:rgba(255,255,255,.045);
      color:#dbeafe;
      font-weight:800;
    }
    .actions{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
      margin-top:18px;
    }
    .btn{
      min-height:42px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:0 14px;
      border-radius:15px;
      font-size:14px;
      font-weight:900;
      background:linear-gradient(135deg,var(--green),var(--green2));
      color:#04120b;
    }
    .btn.secondary{
      color:#e6f0ff;
      border:1px solid var(--line);
      background:linear-gradient(180deg,rgba(255,255,255,.07),rgba(255,255,255,.03));
    }
    @media(max-width:420px){
      body{padding:14px}
      .card{padding:18px;border-radius:22px}
      h1{font-size:24px}
      p{font-size:15px}
      .actions{grid-template-columns:1fr}
    }
  </style>
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
</head>
<body>
  <main class="card">
    <img class="mark brand-icon" src="/assets/brand/zpay-icon.png" alt="Z-Pay Swift">
    <h1>Android APK</h1>
    <p>Download the latest Z-Pay Swift app when available.</p>
    <div class="message">APK download is not available yet. Please contact support.</div>
    <div class="actions">
      <a class="btn" href="/">Back Home</a>
      <a class="btn secondary" href="/user">Open User Panel</a>
    </div>
  </main>
</body>
</html>
