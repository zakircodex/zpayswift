<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$forgotCssVersion = (string)(filemtime(__DIR__ . '/assets/forgot.css') ?: 1);
$forgotJsVersion = (string)(filemtime(__DIR__ . '/assets/forgot.js') ?: 1);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#06162c">
  <title>Forgot Password &amp; PIN | Z-Pay Swift</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
  <link rel="stylesheet" href="/api/user/assets/forgot.css?v=<?= htmlspecialchars($forgotCssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="user-forgot-page">
<main class="forgot-page" id="forgotPageRoot">
  <section class="forgot-card" aria-labelledby="forgotTitle">
    <button id="forgotBackButton" class="forgot-back" type="button" aria-label="Back">&larr;</button>

    <header class="forgot-brand">
      <img class="forgot-logo brand-icon" src="/assets/brand/zpay-icon.png" alt="Z-Pay Swift">
      <h1 id="forgotTitle">Forgot Password &amp; PIN</h1>
      <p id="forgotStepDescription">Step 1: Enter your phone number.</p>
    </header>

    <div class="forgot-progress" aria-label="Recovery progress">
      <span class="active"></span><span></span><span></span><span></span>
    </div>

    <div class="forgot-step" data-forgot-step="phone">
      <div class="forgot-type-grid" role="group" aria-label="Reset type">
        <button id="typePasswordBtn" class="active" type="button">Password</button>
        <button id="typePinBtn" type="button">PIN</button>
      </div>
      <input id="resetType" type="hidden" value="PASSWORD">

      <label class="forgot-country-card" for="forgotPhoneCountry">
        <span>Country</span>
        <select id="forgotPhoneCountry">
          <option value="BD">Bangladesh (+880)</option>
          <option value="MY">Malaysia (+60)</option>
        </select>
      </label>

      <label class="forgot-field" for="forgotPhone">
        <span>Phone Number</span>
        <input id="forgotPhone" type="tel" inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX">
      </label>
      <button id="forgotPhoneContinue" class="forgot-primary" type="button">Continue</button>
    </div>

    <div class="forgot-step" data-forgot-step="identity" hidden>
      <div class="forgot-account-summary">
        <span>Account</span>
        <strong id="forgotAccountPhone">-</strong>
      </div>
      <label class="forgot-field" for="forgotIdentityType">
        <span>Identity Type</span>
        <select id="forgotIdentityType">
          <option value="NID">National ID (NID)</option>
          <option value="PASSPORT">Passport</option>
        </select>
      </label>
      <label class="forgot-field" for="forgotIdentityNumber">
        <span>NID or Passport Number</span>
        <input id="forgotIdentityNumber" autocomplete="off" maxlength="40" placeholder="Enter registered document number">
      </label>
      <p class="forgot-hint">This is checked securely against your existing account before reset.</p>
      <button id="sendForgotOtpBtn" class="forgot-primary" type="button">Send OTP</button>
    </div>

    <div class="forgot-step" data-forgot-step="otp" hidden>
      <div class="forgot-otp-summary">
        <span>OTP sent to</span>
        <strong id="otpMaskedPhone">-</strong>
        <small id="otpExpiresText">05:00</small>
      </div>
      <label class="forgot-field" for="otpCode">
        <span>OTP Code</span>
        <input id="otpCode" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter 6 digit OTP">
      </label>
      <p id="otpStatus" class="forgot-status" aria-live="polite">Enter the OTP sent to your phone.</p>
      <button id="forgotOtpContinue" class="forgot-primary" type="button">Continue</button>
      <button id="resendForgotOtpBtn" class="forgot-secondary" type="button" disabled>Resend OTP</button>
    </div>

    <div class="forgot-step" data-forgot-step="credential" hidden>
      <div id="passwordFields">
        <label class="forgot-field" for="newPassword">
          <span>New Password</span>
          <input id="newPassword" type="password" autocomplete="new-password" placeholder="Minimum 6 characters">
        </label>
        <label class="forgot-field" for="confirmPassword">
          <span>Confirm New Password</span>
          <input id="confirmPassword" type="password" autocomplete="new-password" placeholder="Confirm password">
        </label>
      </div>
      <div id="pinFields" hidden>
        <label class="forgot-field" for="newPin">
          <span>New PIN</span>
          <input id="newPin" type="password" inputmode="numeric" autocomplete="new-password" placeholder="4 to 8 digit PIN">
        </label>
        <label class="forgot-field" for="confirmPin">
          <span>Confirm New PIN</span>
          <input id="confirmPin" type="password" inputmode="numeric" autocomplete="new-password" placeholder="Confirm PIN">
        </label>
      </div>
      <button id="verifyForgotOtpBtn" class="forgot-primary" type="button">Reset Password</button>
    </div>

    <nav class="forgot-links" aria-label="Recovery links">
      <a href="/user/">Back to Login</a>
      <a href="/user/register">Create Account</a>
    </nav>
  </section>
</main>

<div id="forgotFeedbackModal" class="forgot-modal" role="dialog" aria-modal="true" aria-labelledby="forgotFeedbackTitle" aria-hidden="true">
  <div class="forgot-modal-card">
    <div id="forgotFeedbackIcon" class="forgot-modal-icon" aria-hidden="true">!</div>
    <h2 id="forgotFeedbackTitle">Error</h2>
    <p id="forgotFeedbackMessage"></p>
    <button id="forgotFeedbackOk" class="forgot-primary" type="button">OK</button>
  </div>
</div>

<div id="forgotLoadingModal" class="forgot-loading" role="status" aria-live="polite" aria-hidden="true">
  <div class="forgot-loading-card">
    <div class="forgot-spinner" aria-hidden="true"></div>
    <div id="forgotLoadingText">Loading...</div>
  </div>
</div>

<script>
window.USER_PROXY_URL = '/api/user/proxy.php';
window.USER_LOGIN_URL = '/user/';
</script>
<script src="/api/user/assets/forgot.js?v=<?= htmlspecialchars($forgotJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
