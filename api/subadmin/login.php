<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    session_name('zawtopup_subadmin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!empty($_SESSION['subadmin_session_token']) && !empty($_SESSION['subadmin_user'])) {
    header('Location: /subadmin/');
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#07111f">
  <title>Z-Pay Swift Partner Login</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <style>
    *{box-sizing:border-box}
    html,body{margin:0;padding:0}
    body{
      min-height:100vh;
      font-family:Arial,Helvetica,sans-serif;
      color:#eaf2ff;
      background:
        radial-gradient(circle at top left, rgba(31,218,105,.12), transparent 25%),
        radial-gradient(circle at bottom right, rgba(33,126,255,.10), transparent 28%),
        linear-gradient(180deg,#04162f 0%, #061a36 100%);
    }

    .page{
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:20px;
    }

    .card{
      width:100%;
      max-width:430px;
      background:rgba(9,26,58,.96);
      border:1px solid rgba(74,126,218,.22);
      border-radius:24px;
      padding:24px;
      box-shadow:0 20px 60px rgba(0,0,0,.35);
    }

    .brand{
      display:flex;
      gap:14px;
      align-items:center;
      margin-bottom:18px;
    }

    .logo{
      width:60px;
      height:60px;
      border-radius:18px;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:32px;
      font-weight:800;
      color:#001d0d;
      background:linear-gradient(135deg,#29de70,#17cf98);
      box-shadow:0 10px 24px rgba(41,222,112,.22);
      flex:0 0 60px;
    }

    h1{
      margin:0 0 6px;
      font-size:20px;
      line-height:1.25;
    }

    .sub{
      margin:0;
      color:#a9bcdf;
      font-size:14px;
      line-height:1.45;
    }

    .box{
      margin-top:16px;
      background:rgba(255,255,255,.02);
      border:1px solid rgba(90,140,230,.14);
      border-radius:18px;
      padding:16px;
    }

    .field{
      margin-bottom:14px;
    }

    .field label{
      display:block;
      font-size:14px;
      font-weight:700;
      margin:0 0 8px;
      color:#dce7ff;
    }

    .input{
      width:100%;
      height:54px;
      border-radius:16px;
      border:1px solid #294d92;
      background:#081731;
      color:#eef4ff;
      padding:0 16px;
      font-size:16px;
      outline:none;
    }

    .input::placeholder{
      color:#90a8cf;
    }

    .input:focus{
      border-color:#47a0ff;
      box-shadow:0 0 0 3px rgba(71,160,255,.12);
    }

    .checkline{
      display:flex;
      gap:10px;
      align-items:center;
      margin:6px 0 14px;
      font-size:14px;
      color:#dce7ff;
      line-height:1.45;
    }

    .checkline input{
      width:18px;
      height:18px;
      accent-color:#20d96b;
      flex:0 0 18px;
    }

    .btn{
      width:100%;
      height:54px;
      border:none;
      border-radius:16px;
      font-size:16px;
      font-weight:800;
      cursor:pointer;
      margin-top:10px;
      transition:.18s ease;
    }

    .btn:hover{
      transform:translateY(-1px);
    }

    .btn-green{
      background:linear-gradient(135deg,#2cd466,#18cc57);
      color:#03140a;
    }

    .btn-ghost{
      display:flex;
      align-items:center;
      justify-content:center;
      text-decoration:none;
      background:#16284c;
      color:#eaf2ff;
      border:1px solid rgba(101,143,219,.22);
    }

    .help{
      margin-top:14px;
      color:#b2c1dc;
      font-size:13px;
      line-height:1.5;
    }

    .error,.success,.status-note{
      border-radius:14px;
      padding:12px 14px;
      margin-bottom:14px;
      font-size:14px;
      line-height:1.45;
    }

    .error{
      background:rgba(200,60,60,.14);
      border:1px solid rgba(255,100,100,.28);
      color:#ffc4c4;
    }

    .success{
      background:rgba(30,180,100,.14);
      border:1px solid rgba(30,180,100,.28);
      color:#bff5d1;
    }

    .hidden{
      display:none !important;
    }

    .modal-wrap{
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.55);
      display:none;
      align-items:center;
      justify-content:center;
      padding:18px;
      z-index:9999;
    }

    .modal-wrap.open{
      display:flex;
    }

    .modal-card{
      width:100%;
      max-width:440px;
      background:#0b1f43;
      border:1px solid rgba(90,140,230,.2);
      border-radius:24px;
      padding:22px;
      box-shadow:0 20px 60px rgba(0,0,0,.35);
    }

    .modal-head{
      display:flex;
      justify-content:space-between;
      gap:12px;
      align-items:flex-start;
      margin-bottom:16px;
    }

    .modal-head h3{
      margin:0 0 8px;
      font-size:20px;
    }

    .modal-head p{
      margin:0;
      color:#adc0e2;
      line-height:1.45;
      font-size:14px;
    }

    .modal-close{
      min-width:92px;
      height:44px;
      border-radius:14px;
      border:1px solid rgba(103,143,214,.24);
      background:#16284c;
      color:#fff;
      font-weight:700;
      cursor:pointer;
    }

    .info-grid{
      display:grid;
      grid-template-columns:1fr;
      gap:12px;
    }

    .mini-box{
      background:rgba(255,255,255,.03);
      border:1px solid rgba(103,143,214,.16);
      border-radius:18px;
      padding:14px;
    }

    .mini-box label{
      display:block;
      color:#aabddd;
      font-size:13px;
      margin-bottom:8px;
    }

    .mini-box strong{
      font-size:18px;
      line-height:1.35;
      word-break:break-word;
    }

    .status-note{
      background:#16345b;
      border:1px solid rgba(71,160,255,.22);
      color:#d9ecff;
      margin-top:14px;
    }

    .actions .btn{
      margin-top:12px;
    }

    .btn-orange{
      background:linear-gradient(135deg,#ffc930,#ffb100);
      color:#231700;
    }

    .btn-cancel{
      background:#16284c;
      color:#eaf2ff;
      border:1px solid rgba(101,143,219,.22);
    }

    .loading{
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.45);
      display:none;
      align-items:center;
      justify-content:center;
      z-index:10000;
    }

    .loading.show{
      display:flex;
    }

    .loading-box{
      min-width:180px;
      background:#0b1f43;
      border:1px solid rgba(103,143,214,.18);
      border-radius:20px;
      padding:22px;
      text-align:center;
    }

    .spinner{
      width:38px;
      height:38px;
      border-radius:50%;
      border:4px solid rgba(255,255,255,.18);
      border-top-color:#2cd466;
      margin:0 auto 12px;
      animation:spin 1s linear infinite;
    }

    @keyframes spin{
      to{transform:rotate(360deg)}
    }

    .toast-wrap{
      position:fixed;
      right:16px;
      bottom:16px;
      display:flex;
      flex-direction:column;
      gap:10px;
      z-index:10001;
    }

    .toast{
      min-width:220px;
      max-width:320px;
      padding:12px 14px;
      border-radius:14px;
      background:#16345b;
      color:#fff;
      border:1px solid rgba(71,160,255,.22);
      box-shadow:0 10px 24px rgba(0,0,0,.18);
    }

    .toast.ok{
      background:#103b24;
      border-color:rgba(44,212,102,.28);
    }

    .toast.error{
      background:#4a1e24;
      border-color:rgba(255,100,100,.28);
    }

    @media (max-width:520px){
      .page{
        align-items:flex-start;
        padding:14px;
      }

      .card,
      .modal-card{
        border-radius:20px;
      }

      .logo{
        width:54px;
        height:54px;
        font-size:28px;
      }

      h1{
        font-size:18px;
      }
    }
  </style>
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
</head>
<body>

<div class="page">
  <div class="card">
    <div class="brand">
      <img class="logo brand-icon" src="/assets/brand/zpay-icon.png" alt="">
      <div>
        <h1>Z-Pay Swift Partner</h1>
        <p class="sub">Secure access for wallet, users, requests and API tools</p>
      </div>
    </div>

    <div id="loginError" class="error hidden"></div>
    <div id="loginSuccess" class="success hidden"></div>

    <div class="box">
      <div class="field">
        <label>Phone</label>
        <input id="loginPhone" class="input" placeholder="Enter phone number">
      </div>

      <div class="field">
        <label>Password</label>
        <input id="loginPassword" class="input" type="password" placeholder="Enter password">
      </div>

      <label class="checkline">
        <input type="checkbox" id="rememberTrustedDevice" checked>
        <span>Trust this device after OTP verification</span>
      </label>

      <button id="loginBtn" class="btn btn-green" type="button">Login</button>
      <a href="/subadmin/forgot.php" class="btn btn-ghost">Forgot Password / PIN</a>

      
    </div>
  </div>
</div>

<div id="loginOtpModalWrap" class="modal-wrap">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <h3>Verify Login OTP</h3>
        <p>Enter the OTP sent to your registered phone number</p>
      </div>
    </div>

    <div class="info-grid">
      <div class="mini-box">
        <label>Phone</label>
        <strong id="loginOtpMaskedPhone">-</strong>
      </div>
      <div class="mini-box">
        <label>Expires In</label>
        <strong id="loginOtpExpiresText">5 minutes</strong>
      </div>
    </div>

    <div class="field" style="margin-top:16px">
      <label>OTP Code</label>
      <input id="loginOtpCode" class="input" maxlength="6" placeholder="Enter 6 digit OTP">
    </div>

    <div class="actions">
      <button id="verifyLoginOtpBtn" class="btn btn-green" type="button">Verify OTP</button>
      <button id="resendLoginOtpBtn" class="btn btn-orange" type="button">Resend OTP</button>
      <button id="cancelLoginOtpBtn" class="btn btn-cancel" type="button">Cancel</button>
    </div>
  </div>
</div>

<div id="loadingWrap" class="loading">
  <div class="loading-box">
    <div class="spinner"></div>
    <div id="loadingText">Loading...</div>
  </div>
</div>

<div id="toastWrap" class="toast-wrap"></div>

<script>
window.SUBADMIN_PROXY_URL = '/api/subadmin/proxy.php';
</script>
<script src="/api/subadmin/assets/subadmin-login.js?v=5"></script>
</body>
</html>
