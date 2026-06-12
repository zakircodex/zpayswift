<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#07111f">
  <title>Recover Z-Pay Swift User Account</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <link rel="stylesheet" href="/api/user/assets/forgot.css?v=1">
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
</head>
<body>

<div class="page">
  <div class="card">
    <div class="brand">
      <img class="logo brand-icon" src="/assets/brand/zpay-icon.png" alt="">
      <div>
        <h1>Recover Account</h1>
        <p>Reset your password or transaction PIN with OTP verification</p>
      </div>
    </div>

    <div id="forgotError" class="alert error hidden"></div>
    <div id="forgotSuccess" class="alert success hidden"></div>

    <div class="type-grid">
      <button id="typePasswordBtn" class="type-btn active" type="button">Password</button>
      <button id="typePinBtn" class="type-btn" type="button">PIN</button>
    </div>

    <input id="resetType" type="hidden" value="PASSWORD">

    <div class="field">
      <label>Phone Country</label>
      <select id="forgotPhoneCountry" class="input">
        <option value="BD">Bangladesh (+880)</option>
        <option value="MY">Malaysia (+60)</option>
      </select>
      <small class="field-help">OTP gateway is selected from this country.</small>
    </div>

    <div class="field">
      <label>Phone Number</label>
      <input id="forgotPhone" class="input" type="tel" inputmode="tel" placeholder="01XXXXXXXXX">
    </div>

    <button id="sendForgotOtpBtn" class="btn green full-btn" type="button">Send OTP</button>

    <div class="links">
      <a href="/user/">Back to Login</a>
      <a href="/user/register.php">Create New Account</a>
    </div>

    <div class="note">
      Bangladesh numbers use BulkSMSBD. Malaysia numbers use SMS360. Your stored wallet/pricing country is not changed.
    </div>
  </div>
</div>

<div id="forgotOtpModal" class="modal">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <h3 id="modalTitle">Verify Password Reset OTP</h3>
        <p id="modalSub">Enter OTP and set your new password</p>
      </div>
    </div>

    <div class="info-grid">
      <div class="info-box">
        <label>Phone</label>
        <strong id="otpMaskedPhone">-</strong>
      </div>

      <div class="info-box">
        <label>Reset Type</label>
        <strong id="otpResetTypeText">Password</strong>
      </div>
    </div>

    <div class="field mt-14">
      <label>OTP Code</label>
      <input id="otpCode" class="input" maxlength="6" inputmode="numeric" placeholder="Enter 6 digit OTP">
    </div>

    <div id="passwordFields">
      <div class="field">
        <label>New Password</label>
        <input id="newPassword" class="input" type="password" placeholder="Minimum 6 characters">
      </div>

      <div class="field">
        <label>Confirm New Password</label>
        <input id="confirmPassword" class="input" type="password" placeholder="Confirm password">
      </div>
    </div>

    <div id="pinFields" class="hidden">
      <div class="field">
        <label>New PIN</label>
        <input id="newPin" class="input" type="password" inputmode="numeric" placeholder="4 to 8 digit PIN">
      </div>

      <div class="field">
        <label>Confirm New PIN</label>
        <input id="confirmPin" class="input" type="password" inputmode="numeric" placeholder="Confirm PIN">
      </div>
    </div>

    <div id="otpStatus" class="status-box">
      OTP পাঠানোর পরে এখানে status দেখাবে।
    </div>

    <div class="modal-actions">
      <button id="verifyForgotOtpBtn" class="btn green" type="button">Verify & Reset</button>
      <button id="resendForgotOtpBtn" class="btn blue" type="button">Resend OTP</button>
      <button id="cancelForgotOtpBtn" class="btn ghost" type="button">Cancel</button>
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
window.USER_PROXY_URL = '/api/user/proxy.php';
window.USER_LOGIN_URL = '/user/';
</script>
<script src="/api/user/assets/forgot.js?v=1"></script>
</body>
</html>
