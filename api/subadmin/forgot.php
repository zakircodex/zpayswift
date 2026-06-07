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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Recover Subadmin Access</title>
  <style>
    *{box-sizing:border-box}
    html,body{margin:0;padding:0}
    body{
      min-height:100vh;
      font-family:Arial,Helvetica,sans-serif;
      color:#eaf2ff;
      background:
        radial-gradient(circle at top left, rgba(39,214,109,.08), transparent 24%),
        radial-gradient(circle at bottom right, rgba(33,126,255,.10), transparent 28%),
        linear-gradient(180deg,#04162f 0%, #071a35 100%);
    }
    .fp-page{
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:18px;
    }
    .fp-card{
      width:100%;
      max-width:430px;
      background:rgba(9,26,58,.97);
      border:1px solid rgba(82,132,214,.22);
      border-radius:24px;
      padding:22px;
      box-shadow:0 20px 60px rgba(0,0,0,.35);
    }
    .fp-brand{
      display:flex;
      gap:14px;
      align-items:center;
      margin-bottom:16px;
    }
    .fp-logo{
      width:58px;
      height:58px;
      border-radius:18px;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:30px;
      font-weight:800;
      color:#03140a;
      background:linear-gradient(135deg,#2ad667,#18cb59);
      box-shadow:0 10px 22px rgba(42,214,103,.22);
      flex:0 0 58px;
    }
    .fp-title{
      margin:0 0 4px;
      font-size:20px;
      line-height:1.25;
    }
    .fp-subtitle{
      margin:0;
      color:#a9bcdf;
      font-size:14px;
      line-height:1.45;
    }
    .fp-box{
      margin-top:14px;
      background:rgba(255,255,255,.02);
      border:1px solid rgba(100,141,219,.14);
      border-radius:18px;
      padding:16px;
    }
    .fp-select-row{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:10px;
      margin-bottom:14px;
    }
    .fp-type-btn{
      height:48px;
      border-radius:14px;
      border:1px solid #355790;
      background:#102447;
      color:#dfeaff;
      font-size:15px;
      font-weight:800;
      cursor:pointer;
    }
    .fp-type-btn.active{
      background:linear-gradient(135deg,#2ad667,#18cb59);
      color:#03140a;
      border-color:transparent;
    }
    .fp-field{
      margin-bottom:14px;
    }
    .fp-field label{
      display:block;
      margin:0 0 8px;
      font-size:14px;
      font-weight:700;
      color:#dce7ff;
    }
    .fp-input{
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
    .fp-input::placeholder{
      color:#90a8cf;
    }
    .fp-input:focus{
      border-color:#47a0ff;
      box-shadow:0 0 0 3px rgba(71,160,255,.12);
    }
    .fp-btn{
      width:100%;
      height:54px;
      border:none;
      border-radius:16px;
      font-size:16px;
      font-weight:800;
      cursor:pointer;
      margin-top:10px;
      transition:.18s ease;
      text-decoration:none;
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .fp-btn:hover{
      transform:translateY(-1px);
    }
    .fp-btn-green{
      background:linear-gradient(135deg,#2ad667,#18cb59);
      color:#03140a;
    }
    .fp-btn-orange{
      background:linear-gradient(135deg,#ffc930,#ffb100);
      color:#231700;
    }
    .fp-btn-ghost{
      background:#16284c;
      color:#eef4ff;
      border:1px solid rgba(101,143,219,.22);
    }
    .fp-help{
      margin-top:14px;
      color:#b2c1dc;
      font-size:13px;
      line-height:1.5;
    }
    .fp-alert,
    .fp-success,
    .fp-status{
      border-radius:14px;
      padding:12px 14px;
      margin-bottom:14px;
      font-size:14px;
      line-height:1.45;
    }
    .fp-alert{
      background:rgba(200,60,60,.14);
      border:1px solid rgba(255,100,100,.28);
      color:#ffc4c4;
    }
    .fp-success{
      background:rgba(30,180,100,.14);
      border:1px solid rgba(30,180,100,.28);
      color:#bff5d1;
    }
    .hidden{
      display:none !important;
    }
    .fp-modal{
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.58);
      display:none;
      align-items:center;
      justify-content:center;
      padding:18px;
      z-index:9999;
    }
    .fp-modal.open{
      display:flex;
    }
    .fp-modal-card{
      width:100%;
      max-width:430px;
      background:#0b1f43;
      border:1px solid rgba(100,141,219,.2);
      border-radius:24px;
      padding:20px;
      box-shadow:0 20px 60px rgba(0,0,0,.35);
      max-height:92vh;
      overflow:auto;
    }
    .fp-modal-head{
      display:flex;
      justify-content:space-between;
      gap:12px;
      align-items:flex-start;
      margin-bottom:16px;
    }
    .fp-modal-head h3{
      margin:0 0 8px;
      font-size:20px;
    }
    .fp-modal-head p{
      margin:0;
      color:#adc0e2;
      line-height:1.45;
      font-size:14px;
    }
    .fp-close{
      min-width:88px;
      height:42px;
      border-radius:14px;
      border:1px solid rgba(103,143,214,.24);
      background:#16284c;
      color:#fff;
      font-weight:700;
      cursor:pointer;
    }
    .fp-info-grid{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:12px;
      margin-bottom:14px;
    }
    .fp-info-box{
      background:rgba(255,255,255,.03);
      border:1px solid rgba(103,143,214,.16);
      border-radius:18px;
      padding:14px;
    }
    .fp-info-box label{
      display:block;
      color:#aabddd;
      font-size:13px;
      margin-bottom:8px;
    }
    .fp-info-box strong{
      font-size:18px;
      line-height:1.35;
      word-break:break-word;
    }
    .fp-status{
      background:#16345b;
      border:1px solid rgba(71,160,255,.22);
      color:#d9ecff;
      margin-top:12px;
    }
    .fp-loading{
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.45);
      display:none;
      align-items:center;
      justify-content:center;
      z-index:10000;
    }
    .fp-loading.show{
      display:flex;
    }
    .fp-loading-box{
      min-width:180px;
      background:#0b1f43;
      border:1px solid rgba(103,143,214,.18);
      border-radius:20px;
      padding:22px;
      text-align:center;
    }
    .fp-spinner{
      width:38px;
      height:38px;
      border-radius:50%;
      border:4px solid rgba(255,255,255,.18);
      border-top-color:#2ad667;
      margin:0 auto 12px;
      animation:fp-spin 1s linear infinite;
    }
    @keyframes fp-spin{
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
      .fp-page{
        align-items:flex-start;
        padding:14px;
      }
      .fp-card,
      .fp-modal-card{
        border-radius:20px;
      }
      .fp-logo{
        width:52px;
        height:52px;
        font-size:28px;
      }
      .fp-title{
        font-size:18px;
      }
      .fp-info-grid{
        grid-template-columns:1fr;
      }
    }
  </style>
</head>
<body>

<div class="fp-page">
  <div class="fp-card">
    <div class="fp-brand">
      <div class="fp-logo">S</div>
      <div>
        <h1 class="fp-title">Recover Subadmin Access</h1>
        <p class="fp-subtitle">Reset password or PIN with OTP verification</p>
      </div>
    </div>
    

    <div id="forgotError" class="fp-alert hidden"></div>
    <div id="forgotSuccess" class="fp-success hidden"></div>

    <div class="fp-box">
      <div class="fp-select-row">
        <button type="button" id="forgotTypePasswordBtn" class="fp-type-btn active">Password</button>
        <button type="button" id="forgotTypePinBtn" class="fp-type-btn">PIN</button>
      </div>

      <input type="hidden" id="forgotResetType" value="PASSWORD">

      <div class="fp-field">
        <label>Phone</label>
        <input id="forgotPhone" class="fp-input" placeholder="Enter registered phone number">
      </div>

      <button id="sendForgotOtpBtn" class="fp-btn fp-btn-orange" type="button">Send OTP</button>
      <a href="/subadmin/login.php" class="fp-btn fp-btn-ghost">Back to Login</a>


    </div>
  </div>
</div>

<div id="forgotOtpModalWrap" class="fp-modal">
  <div class="fp-modal-card">
    <div class="fp-modal-head">
      <div>
        <h3 id="forgotModalTitle">Verify Password Reset OTP</h3>
        <p id="forgotModalSub">Enter OTP and set your new password</p>
      </div>
    </div>

    <div class="fp-info-grid">
      <div class="fp-info-box">
        <label>Phone</label>
        <strong id="forgotMaskedPhone">-</strong>
      </div>
      <div class="fp-info-box">
        <label>Reset Type</label>
        <strong id="forgotResetTypeLabel">Password</strong>
      </div>
    </div>

    <div class="fp-field">
      <label>OTP Code</label>
      <input id="forgotOtpCode" class="fp-input" maxlength="6" placeholder="Enter 6 digit OTP">
    </div>

    <div class="fp-field">
      <label id="forgotNewValueLabel">New Password</label>
      <input id="forgotNewValue" class="fp-input" type="password" placeholder="Enter new password">
    </div>

    <div class="fp-field">
      <label id="forgotConfirmValueLabel">Confirm New Password</label>
      <input id="forgotConfirmValue" class="fp-input" type="password" placeholder="Confirm new password">
    </div>

    <div id="forgotOtpStatus" class="fp-status">
      OTP পাঠানোর পরে এখানে status দেখাবে।
    </div>

    <button id="verifyForgotOtpBtn" class="fp-btn fp-btn-green" type="button">Verify & Reset</button>
    <button id="resendForgotOtpBtn" class="fp-btn fp-btn-orange" type="button">Resend OTP</button>
    <button id="cancelForgotOtpBtn" class="fp-btn fp-btn-ghost" type="button">Cancel</button>
  </div>
</div>

<div id="loadingWrap" class="fp-loading">
  <div class="fp-loading-box">
    <div class="fp-spinner"></div>
    <div id="loadingText">Loading...</div>
  </div>
</div>

<div id="toastWrap" class="toast-wrap"></div>

<script>
window.SUBADMIN_PROXY_URL = '/api/subadmin/proxy.php';
</script>
<script src="/api/subadmin/assets/subadmin-forgot.js?v=15"></script>
</body>
</html>
