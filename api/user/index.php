<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth-guard.php';
user_page_start_session();

if (trim((string)($_SESSION['user_session_token'] ?? '')) !== '') {
    header('Location: /user/dashboard', true, 302);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$loginCssVersion = (string)(filemtime(__DIR__ . '/assets/pages/login-page.css') ?: 1);
$loginJsVersion = (string)(filemtime(__DIR__ . '/assets/pages/login-page.js') ?: 1);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#07172f">
  <title>Z-Pay Swift User</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
  <link rel="stylesheet" href="/api/user/assets/pages/login-page.css?v=<?= htmlspecialchars($loginCssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
<main class="login-wrap">
  <section class="login-card" aria-labelledby="loginTitle">
    <div class="brand">
      <img class="logo brand-icon" src="/assets/brand/zpay-icon.png" alt="">
      <div>
        <h1 id="loginTitle">Z-Pay Swift<br>User</h1>
        <p>Secure wallet, topup, bundle and bKash/Nagad requests</p>
      </div>
    </div>

    <div id="loginError" class="login-error" role="alert"></div>

    <div class="field">
      <label for="loginPhoneCountry">Phone Country</label>
      <select id="loginPhoneCountry" class="input">
        <option value="BD">Bangladesh (+880)</option>
        <option value="MY">Malaysia (+60)</option>
      </select>
    </div>
    <div class="field">
      <label for="loginPhone">Phone Number</label>
      <input id="loginPhone" class="input" type="tel" inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX">
    </div>
    <div class="field">
      <label for="loginPassword">Password</label>
      <input id="loginPassword" class="input" type="password" autocomplete="current-password" placeholder="Enter password">
    </div>
    <div class="login-check-row">
      <label class="login-check">
        <input type="checkbox" id="rememberTrustedDevice" checked>
        <span>Trust this device after OTP verification</span>
      </label>
    </div>
    <button id="loginBtn" class="btn green full-btn" type="button">Login</button>
    <div class="login-links">
      <a href="/user/register" class="link-btn">Create Account</a>
      <a href="/user/forgot" class="link-btn">Forgot Password / PIN</a>
    </div>
  </section>
</main>

<div id="loginOtpModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="loginOtpTitle" aria-hidden="true">
  <div class="modal-card modal-card-sm">
    <button id="closeLoginOtpModalBtn" class="modal-close" type="button" aria-label="Close">&times;</button>
    <h2 class="modal-title" id="loginOtpTitle">Verify Login OTP</h2>
    <p class="modal-sub">Enter the OTP sent to your registered phone number</p>
    <div class="detail-grid">
      <div class="detail-box"><label>Phone</label><strong id="loginOtpMaskedPhone">-</strong></div>
      <div class="detail-box"><label>Expires In</label><strong id="loginOtpExpiresText">5 minutes</strong></div>
    </div>
    <div class="field field-top-gap">
      <label for="loginOtpCode">OTP Code</label>
      <input id="loginOtpCode" class="input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter 6 digit OTP">
    </div>
    <div id="loginOtpStatus" class="otp-status" aria-live="polite">OTP status will appear here.</div>
    <div class="otp-actions">
      <button id="verifyLoginOtpBtn" class="btn green" type="button">Verify OTP</button>
      <button id="resendLoginOtpBtn" class="btn blue" type="button">Resend OTP</button>
      <button id="cancelLoginOtpBtn" class="btn ghost" type="button">Cancel</button>
    </div>
  </div>
</div>

<div id="loginLoadingModal" class="login-page-loading" role="status" aria-live="polite" aria-hidden="true">
  <div class="login-page-loading-card">
    <div class="login-page-loading-spinner" aria-hidden="true"></div>
    <div id="loginLoadingText">Loading...</div>
  </div>
</div>
<div id="toastWrap" class="toast-wrap" aria-live="polite"></div>

<script>window.USER_PROXY_URL = '/api/user/proxy.php';</script>
<script src="/api/user/assets/pages/login-page.js?v=<?= htmlspecialchars($loginJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
