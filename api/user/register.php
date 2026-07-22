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
  <meta name="theme-color" content="#07172f">
  <title>Create Z-Pay Swift User Account</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <link rel="stylesheet" href="/api/user/assets/register.css?v=2">
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
  <link rel="stylesheet" href="/api/user/assets/user-app.css?v=1">
</head>
<body class="user-auth-page">

<div class="page">
  <div class="card">
    <div class="brand">
      <img class="logo brand-icon" src="/assets/brand/zpay-icon.png" alt="">
      <div>
        <h1>Create Account</h1>
        <p>Register your Z-Pay Swift user account with OTP verification</p>
      </div>
    </div>

    <div id="registerError" class="alert error hidden"></div>
    <div id="registerSuccess" class="alert success hidden"></div>

    <div class="form-grid">
      <div class="field form-full">
        <label>Full Name</label>
        <input id="regName" class="input" placeholder="Enter full name">
      </div>

      <div class="field">
        <label>Phone Country</label>
        <select id="regPhoneCountry" class="input">
          <option value="BD">Bangladesh (+880)</option>
          <option value="MY">Malaysia (+60)</option>
        </select>
        <small class="field-help">Used only for phone validation and OTP delivery.</small>
      </div>

      <div class="field">
        <label>Phone Number</label>
        <input id="regPhone" class="input" type="tel" inputmode="tel" placeholder="01XXXXXXXXX">
      </div>

      <div class="field form-full">
        <label>Email</label>
        <input id="regEmail" class="input" type="email" placeholder="Enter email address">
      </div>

      <div class="field">
        <label>Identity Type</label>
        <select id="regIdentityType" class="input">
          <option value="NID">National ID (NID)</option>
          <option value="PASSPORT">Passport</option>
        </select>
      </div>

      <div class="field">
        <label>NID or Passport Number</label>
        <input id="regIdentityNumber" class="input" type="text" autocomplete="off" maxlength="40" placeholder="Enter document number">
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

      <div class="field form-full location-field">
        <label>Verify Location & Pricing Country</label>
        <div class="location-panel">
          <div>
            <strong id="regLocationTitle">Location permission required</strong>
            <p id="regLocationStatus">Click Verify Location before creating your account.</p>
          </div>
          <button id="verifyLocationBtn" class="btn blue" type="button">Verify Location</button>
        </div>
        <div class="pricing-result">
          <span>Detected Pricing Country</span>
          <strong id="regPricingCountryDisplay">Location verification required</strong>
        </div>
        <small class="field-help">Pricing country is assigned from verified GPS/IP market and cannot be selected manually.</small>
      </div>

      <label class="terms-check form-full" for="regTermsAccepted">
        <input id="regTermsAccepted" type="checkbox" value="1">
        <span>I accept the <a href="/terms" target="_blank" rel="noopener">Terms &amp; Conditions</a>.</span>
      </label>
    </div>

    <button id="sendRegisterOtpBtn" class="btn green full-btn" type="button">Create Account</button>

    <div class="links">
      <a href="/user/">Already have an account? Login</a>
      <a href="/user/forgot.php">Forgot Password / PIN</a>
    </div>

    <div class="note">
      <span id="regCountryHint">OTP gateway follows Phone Country. Wallet and fees follow Pricing Country.</span>
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
      <input id="otpCode" class="input" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter 6 digit OTP">
    </div>

    <div id="otpStatus" class="status-box">
      OTP status will appear here after sending.
    </div>

    <div class="modal-actions">
      <button id="verifyRegisterOtpBtn" class="btn green" type="button">Verify &amp; Create Account</button>
      <button id="resendRegisterOtpBtn" class="btn blue" type="button">Resend OTP</button>
      <button id="cancelRegisterOtpBtn" class="btn ghost" type="button">Cancel</button>
    </div>
  </div>
</div>

<div id="registerReviewModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="registerReviewTitle">
  <div class="modal-card review-modal-card">
    <div class="review-icon" aria-hidden="true">!</div>
    <div class="modal-head review-modal-head">
      <h3 id="registerReviewTitle">Account Under Review</h3>
      <p>Your account is under admin review. Please wait for approval. For urgent help, contact us on WhatsApp.</p>
    </div>
    <div class="modal-actions">
      <a id="registerWhatsAppSupport" class="btn green btn-link" href="https://api.whatsapp.com/send?text=I%20need%20support%20with%20my%20Z-Pay%20Swift%20account%20review" target="_blank" rel="noopener">WhatsApp Support</a>
      <button id="closeRegisterReviewBtn" class="btn ghost" type="button">Close</button>
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
<script src="/api/user/assets/register.js?v=5"></script>
</body>
</html>
