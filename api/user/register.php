<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$registerCssVersion = (string)(filemtime(__DIR__ . '/assets/register.css') ?: 1);
$registerJsVersion = (string)(filemtime(__DIR__ . '/assets/register.js') ?: 1);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#06162c">
  <title>Create Account | Z-Pay Swift</title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
  <link rel="stylesheet" href="/api/user/assets/register.css?v=<?= htmlspecialchars($registerCssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="user-register-page">
<main class="register-page" id="registerPageRoot">
  <section class="register-card" aria-labelledby="registerTitle">
    <button id="registerBackButton" class="register-back" type="button" aria-label="Back">&larr;</button>

    <header class="register-brand">
      <img class="register-logo brand-icon" src="/assets/brand/zpay-icon.png" alt="Z-Pay Swift">
      <h1 id="registerTitle">Create Account</h1>
      <p id="registerStepDescription">Step 1: Enter your account details.</p>
    </header>

    <div class="register-progress" aria-label="Registration progress">
      <span class="active"></span><span></span><span></span><span></span><span></span>
    </div>

    <div class="register-step" data-register-step="details">
      <label class="register-field" for="regName">
        <span>Full Name</span>
        <input id="regName" autocomplete="name" placeholder="Enter full name">
      </label>

      <label class="register-country-card" for="regPhoneCountry">
        <span>Phone Country</span>
        <select id="regPhoneCountry">
          <option value="BD">Bangladesh (+880)</option>
          <option value="MY">Malaysia (+60)</option>
        </select>
      </label>

      <label class="register-field" for="regPhone">
        <span>Phone Number</span>
        <input id="regPhone" type="tel" inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX">
        <small>Used only for phone validation and OTP delivery.</small>
      </label>
      <button class="register-primary" type="button" data-register-next="contact">Continue</button>
    </div>

    <div class="register-step" data-register-step="contact" hidden>
      <label class="register-field" for="regEmail">
        <span>Email</span>
        <input id="regEmail" type="email" inputmode="email" autocomplete="email" placeholder="Enter email address">
      </label>

      <div class="register-two-column">
        <label class="register-field" for="regIdentityType">
          <span>Identity Type</span>
          <select id="regIdentityType">
            <option value="NID">National ID (NID)</option>
            <option value="PASSPORT">Passport</option>
          </select>
        </label>
        <label class="register-field" for="regIdentityNumber">
          <span>Document Number</span>
          <input id="regIdentityNumber" autocomplete="off" maxlength="40" placeholder="NID or Passport number">
        </label>
      </div>
      <button class="register-primary" type="button" data-register-next="security">Continue</button>
    </div>

    <div class="register-step" data-register-step="security" hidden>
      <label class="register-field" for="regPassword">
        <span>Password</span>
        <input id="regPassword" type="password" autocomplete="new-password" placeholder="Minimum 6 characters">
      </label>
      <label class="register-field" for="regConfirmPassword">
        <span>Confirm Password</span>
        <input id="regConfirmPassword" type="password" autocomplete="new-password" placeholder="Confirm password">
      </label>
      <div class="register-two-column">
        <label class="register-field" for="regPin">
          <span>Transaction PIN</span>
          <input id="regPin" type="password" inputmode="numeric" autocomplete="new-password" placeholder="4 to 8 digits">
        </label>
        <label class="register-field" for="regConfirmPin">
          <span>Confirm PIN</span>
          <input id="regConfirmPin" type="password" inputmode="numeric" autocomplete="new-password" placeholder="Confirm PIN">
        </label>
      </div>
      <button class="register-primary" type="button" data-register-next="location">Continue</button>
    </div>

    <div class="register-step" data-register-step="location" hidden>
      <section class="register-location-card" aria-labelledby="regLocationTitle">
        <div>
          <span class="register-location-label">Verify Location &amp; Pricing Country</span>
          <strong id="regLocationTitle">Location permission required</strong>
          <p id="regLocationStatus">Verify your current location before creating the account.</p>
        </div>
        <button id="verifyLocationBtn" class="register-secondary" type="button">Verify Location</button>
      </section>

      <div class="register-pricing-result">
        <span>Pricing Country</span>
        <strong id="regPricingCountryDisplay">Location verification required</strong>
      </div>
      <p id="regCountryHint" class="register-hint">Phone country controls OTP only. GPS/IP verification controls wallet pricing.</p>

      <label class="register-terms" for="regTermsAccepted">
        <input id="regTermsAccepted" type="checkbox" value="1">
        <span>I accept the <a href="/terms" target="_blank" rel="noopener">Terms &amp; Conditions</a>.</span>
      </label>

      <button id="sendRegisterOtpBtn" class="register-primary" type="button">Create Account</button>
    </div>

    <div class="register-step" data-register-step="otp" hidden>
      <div class="register-otp-summary">
        <span>OTP sent to</span>
        <strong id="otpMaskedPhone">-</strong>
        <small id="otpExpiresText">05:00</small>
      </div>
      <label class="register-field" for="otpCode">
        <span>OTP Code</span>
        <input id="otpCode" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter 6 digit OTP">
      </label>
      <p id="otpStatus" class="register-status" aria-live="polite">Enter the OTP to create your account.</p>
      <button id="verifyRegisterOtpBtn" class="register-primary" type="button">Verify &amp; Create Account</button>
      <button id="resendRegisterOtpBtn" class="register-secondary" type="button" disabled>Resend OTP</button>
    </div>

    <nav class="register-links" aria-label="Registration links">
      <a href="/user/">Login</a>
      <a href="/user/forgot">Forgot Password / PIN</a>
    </nav>
  </section>
</main>

<div id="registerFeedbackModal" class="register-modal" role="dialog" aria-modal="true" aria-labelledby="registerFeedbackTitle" aria-hidden="true">
  <div class="register-modal-card">
    <div id="registerFeedbackIcon" class="register-modal-icon" aria-hidden="true">!</div>
    <h2 id="registerFeedbackTitle">Error</h2>
    <p id="registerFeedbackMessage"></p>
    <button id="registerFeedbackOk" class="register-primary" type="button">OK</button>
  </div>
</div>

<div id="registerReviewModal" class="register-modal" role="dialog" aria-modal="true" aria-labelledby="registerReviewTitle" aria-hidden="true">
  <div class="register-modal-card">
    <div class="register-modal-icon review" aria-hidden="true">!</div>
    <h2 id="registerReviewTitle">Account Under Review</h2>
    <p>Your account is waiting for admin approval. Existing review and notification rules remain active.</p>
    <a class="register-primary register-link-button" href="https://api.whatsapp.com/send?text=I%20need%20support%20with%20my%20Z-Pay%20Swift%20account%20review" target="_blank" rel="noopener">WhatsApp Support</a>
    <button id="closeRegisterReviewBtn" class="register-secondary" type="button">Done</button>
  </div>
</div>

<div id="registerLoadingModal" class="register-loading" role="status" aria-live="polite" aria-hidden="true">
  <div class="register-loading-card">
    <div class="register-spinner" aria-hidden="true"></div>
    <div id="registerLoadingText">Loading...</div>
  </div>
</div>

<script>
window.USER_PROXY_URL = '/api/user/proxy.php';
window.USER_LOGIN_URL = '/user/';
</script>
<script src="/api/user/assets/register.js?v=<?= htmlspecialchars($registerJsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
