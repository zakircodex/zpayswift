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
  <title>Create Z-Pay Swift User Account</title>
  <link rel="stylesheet" href="assets/register.css?v=1">
</head>
<body>

<div class="page">
  <div class="card">
    <div class="brand">
      <div class="logo">U</div>
      <div>
        <h1>Create Account</h1>
        <p>Register your Z-Pay Swift user account with OTP verification</p>
      </div>
    </div>

    <div id="registerError" class="alert error hidden"></div>
    <div id="registerSuccess" class="alert success hidden"></div>

    <div class="form-grid">
      <div class="field">
        <label>Full Name</label>
        <input id="regName" class="input" placeholder="Enter full name">
      </div>

      <div class="field">
        <label>Phone Number</label>
        <input id="regPhone" class="input" type="tel" inputmode="tel" placeholder="Enter phone number">
      </div>

      <div class="field">
        <label>Email</label>
        <input id="regEmail" class="input" type="email" placeholder="Enter email address">
      </div>

      <div class="field">
        <label>Password</label>
        <input id="regPassword" class="input" type="password" placeholder="Minimum 6 characters">
      </div>

      <div class="field">
        <label>Confirm Password</label>
        <input id="regConfirmPassword" class="input" type="password" placeholder="Confirm password">
      </div>

      <div class="field">
        <label>Transaction PIN</label>
        <input id="regPin" class="input" type="password" inputmode="numeric" placeholder="4 to 8 digit PIN">
      </div>

      <div class="field">
        <label>Confirm PIN</label>
        <input id="regConfirmPin" class="input" type="password" inputmode="numeric" placeholder="Confirm PIN">
      </div>
    </div>

    <button id="sendRegisterOtpBtn" class="btn green full-btn" type="button">Create Account</button>

    <div class="links">
      <a href="/zawtopup/api/user/dashboard.php">Already have an account? Login</a>
      <a href="/zawtopup/api/user/forgot.php">Forgot Password / PIN</a>
    </div>

    <div class="note">
      OTP will be sent to your registered phone number. Your account will be created only after OTP verification.
    </div>
  </div>
</div>

<div id="registerOtpModal" class="modal">
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <h3>Verify Registration OTP</h3>
        <p>Enter the OTP sent to your phone number</p>
      </div>
    </div>

    <div class="info-grid">
      <div class="info-box">
        <label>Phone</label>
        <strong id="otpMaskedPhone">-</strong>
      </div>

      <div class="info-box">
        <label>Expires In</label>
        <strong id="otpExpiresText">5 minutes</strong>
      </div>
    </div>

    <div class="field mt-14">
      <label>OTP Code</label>
      <input id="otpCode" class="input" maxlength="6" inputmode="numeric" placeholder="Enter 6 digit OTP">
    </div>

    <div id="otpStatus" class="status-box">
      OTP পাঠানোর পরে এখানে status দেখাবে।
    </div>

    <div class="modal-actions">
      <button id="verifyRegisterOtpBtn" class="btn green" type="button">Verify & Create</button>
      <button id="resendRegisterOtpBtn" class="btn blue" type="button">Resend OTP</button>
      <button id="cancelRegisterOtpBtn" class="btn ghost" type="button">Cancel</button>
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
window.USER_PROXY_URL = '/zawtopup/api/user/proxy.php';
window.USER_LOGIN_URL = '/zawtopup/api/user/dashboard.php';
</script>
<script src="assets/register.js?v=1"></script>
</body>
</html>