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
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#06162c">
  <title>Login | Z-Pay Swift</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
  <link rel="stylesheet" href="/api/user/assets/pages/login-page.css?v=<?= htmlspecialchars($loginCssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="user-login-page">
<main class="login-wrap" id="loginPageRoot">
  <section class="login-card" aria-labelledby="loginStepTitle">
    <button id="loginStepBack" class="login-step-back" type="button" aria-label="Back" hidden>&larr;</button>

    <header class="login-brand">
      <img class="login-logo brand-icon" src="/assets/brand/zpay-icon.png" alt="Z-Pay Swift">
      <div class="login-product">Z-Pay Swift</div>
      <h1 id="loginStepTitle">Login</h1>
      <p id="loginStepSubtitle">Enter your phone number to continue.</p>
    </header>

    <div class="login-step" id="loginPhoneStep" data-login-step="phone">
      <label class="login-country-card" for="loginPhoneCountry">
        <span>Country</span>
        <select id="loginPhoneCountry" aria-label="Phone country">
          <option value="BD">Bangladesh (+880)</option>
          <option value="MY">Malaysia (+60)</option>
        </select>
      </label>

      <label class="login-field" for="loginPhone">
        <span>Phone Number</span>
        <input id="loginPhone" type="tel" inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX">
      </label>

      <button id="loginPhoneContinue" class="login-primary" type="button">Continue</button>

      <nav class="login-links" aria-label="User account links">
        <a href="/user/register">Register</a>
        <span aria-hidden="true"></span>
        <a href="/user/forgot">Forgot</a>
      </nav>
    </div>

    <div class="login-step" id="loginPasswordStep" data-login-step="password" hidden>
      <div class="login-account-summary">
        <span>Signing in as</span>
        <strong id="loginAccountPhone">-</strong>
      </div>

      <label class="login-field" for="loginPassword">
        <span>Password</span>
        <input id="loginPassword" type="password" autocomplete="current-password" placeholder="Enter password">
      </label>

      <label class="login-trust-row" for="rememberTrustedDevice">
        <input type="checkbox" id="rememberTrustedDevice" checked>
        <span>Trust this browser after OTP verification</span>
      </label>

      <button id="loginPasswordContinue" class="login-primary" type="button">Continue</button>
    </div>

    <div class="login-step" id="loginOtpStep" data-login-step="otp" hidden>
      <div class="login-otp-summary">
        <span>OTP sent to</span>
        <strong id="loginOtpMaskedPhone">-</strong>
        <small id="loginOtpExpiresText">05:00</small>
      </div>

      <label class="login-field" for="loginOtpCode">
        <span>OTP Code</span>
        <input id="loginOtpCode" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter 6 digit OTP">
      </label>

      <p id="loginOtpStatus" class="login-status" aria-live="polite">Enter the OTP to complete login.</p>
      <button id="verifyLoginOtpBtn" class="login-primary" type="button">Verify OTP</button>
      <button id="resendLoginOtpBtn" class="login-secondary" type="button" disabled>Resend OTP</button>
    </div>
  </section>
</main>

<div id="loginFeedbackModal" class="login-feedback-modal" role="dialog" aria-modal="true" aria-labelledby="loginFeedbackTitle" aria-hidden="true">
  <div class="login-feedback-card">
    <div id="loginFeedbackIcon" class="login-feedback-icon" aria-hidden="true">!</div>
    <h2 id="loginFeedbackTitle">Error</h2>
    <p id="loginFeedbackMessage"></p>
    <button id="loginFeedbackOk" class="login-primary" type="button">OK</button>
  </div>
</div>

<div id="loginLoadingModal" class="login-page-loading" role="status" aria-live="polite" aria-hidden="true">
  <div class="login-page-loading-card">
    <div class="login-page-loading-spinner" aria-hidden="true"></div>
    <div id="loginLoadingText">Loading...</div>
  </div>
</div>

<script>window.USER_PROXY_URL = '/api/user/proxy.php';</script>
<script src="/api/user/assets/pages/login-page.js?v=<?= htmlspecialchars($loginJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
