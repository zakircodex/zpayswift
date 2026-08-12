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
    <button id="registerBackButton" class="register-back" type="button" aria-label="Previous step" hidden>&larr;</button>

    <header class="register-brand">
      <img class="register-logo brand-icon" src="/assets/brand/zpay-icon.png" alt="Z-Pay Swift">
      <h1 id="registerTitle">Create Account</h1>
      <p id="registerStepDescription">Enter your phone number to get started.</p>
    </header>

    <div class="register-progress" aria-label="Registration progress">
      <span class="active"></span><span></span><span></span><span></span><span></span><span></span><span></span>
    </div>

    <div class="register-step" data-register-step="phone">
      <div class="register-country-card" aria-label="Detected phone country">
        <strong id="regCountryDisplay">Country: Detecting...</strong>
      </div>
      <input id="regPhoneCountry" type="hidden" value="">

      <label class="register-field" for="regPhone">
        <span>Phone Number</span>
        <input id="regPhone" type="tel" inputmode="tel" autocomplete="tel" placeholder="Phone number">
      </label>
      <button id="registerPhoneContinue" class="register-primary" type="button" disabled>Continue</button>
    </div>

    <div class="register-step" data-register-step="personal" hidden>
      <label class="register-field" for="regName">
        <span>Full Name</span>
        <input id="regName" autocomplete="name" maxlength="100" placeholder="Enter your full name">
      </label>
      <label class="register-field" for="regEmail">
        <span>Email</span>
        <input id="regEmail" type="email" inputmode="email" autocomplete="email" maxlength="160" placeholder="Enter your email">
      </label>
      <button id="registerPersonalContinue" class="register-primary" type="button">Continue</button>
    </div>

    <div class="register-step" data-register-step="identity" hidden>
      <div class="register-identity-selector" role="group" aria-label="Identity type">
        <button type="button" class="active" data-register-identity="NID" aria-pressed="true">NID</button>
        <button type="button" data-register-identity="PASSPORT" aria-pressed="false">Passport</button>
      </div>
      <input id="regIdentityType" type="hidden" value="NID">

      <label class="register-field" for="regIdentityNumber">
        <span id="regIdentityLabel">NID Number</span>
        <input id="regIdentityNumber" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="40" placeholder="Enter your NID number">
      </label>
      <p class="register-privacy-note">Your identity number is verified securely and is not shown in the account review.</p>
      <button id="registerIdentityContinue" class="register-primary" type="button">Continue</button>
    </div>

    <div class="register-step" data-register-step="password" hidden>
      <label class="register-field" for="regPassword">
        <span>Password</span>
        <input id="regPassword" type="password" inputmode="numeric" autocomplete="new-password" maxlength="6" placeholder="6 digits">
      </label>
      <label class="register-field" for="regConfirmPassword">
        <span>Confirm Password</span>
        <input id="regConfirmPassword" type="password" inputmode="numeric" autocomplete="new-password" maxlength="6" placeholder="Confirm 6-digit password">
      </label>
      <button id="registerPasswordContinue" class="register-primary" type="button">Continue</button>
    </div>

    <div class="register-step" data-register-step="pin" hidden>
      <label class="register-field" for="regPin">
        <span>PIN</span>
        <input id="regPin" type="password" inputmode="numeric" autocomplete="new-password" maxlength="4" placeholder="4 digits">
      </label>
      <label class="register-field" for="regConfirmPin">
        <span>Confirm PIN</span>
        <input id="regConfirmPin" type="password" inputmode="numeric" autocomplete="new-password" maxlength="4" placeholder="Confirm 4-digit PIN">
      </label>
      <button id="registerPinContinue" class="register-primary" type="button">Continue</button>
    </div>

    <div class="register-step" data-register-step="review" hidden>
      <div class="register-review-list" aria-label="Registration summary">
        <div><span>Full Name</span><strong id="reviewName">-</strong></div>
        <div><span>Phone</span><strong id="reviewPhone">-</strong></div>
        <div><span>Email</span><strong id="reviewEmail">-</strong></div>
        <div><span>Identity</span><strong id="reviewIdentity">-</strong></div>
        <div><span>Phone Country</span><strong id="reviewPhoneCountry">-</strong></div>
        <div><span>Pricing Country</span><strong id="reviewPricingCountry">Not verified</strong></div>
        <div><span>Wallet Currency</span><strong id="reviewCurrency">-</strong></div>
      </div>

      <h2 class="register-section-title">Verify Location</h2>
      <section class="register-location-card" aria-labelledby="regLocationTitle">
        <div class="register-location-icon" aria-hidden="true">GPS</div>
        <div>
          <strong id="regLocationTitle">Location permission required</strong>
          <p id="regLocationStatus">Use your current GPS location to continue.</p>
        </div>
      </section>

      <div class="register-pricing-grid">
        <div class="register-pricing-result">
          <span>Pricing Country</span>
          <strong id="regPricingCountryDisplay">Not verified</strong>
        </div>
        <div class="register-pricing-result">
          <span>Wallet Currency</span>
          <strong id="regCurrencyDisplay">-</strong>
        </div>
      </div>
      <p id="regCountryHint" class="register-hint">Phone country is used for OTP. GPS and security checks determine pricing.</p>
      <button id="verifyLocationBtn" class="register-secondary register-location-action" type="button">Verify Location</button>

      <label class="register-terms" for="regTermsAccepted">
        <input id="regTermsAccepted" type="checkbox" value="1">
        <span>I agree to the <a href="/terms" target="_blank" rel="noopener">Terms &amp; Conditions</a> and <a href="/privacy" target="_blank" rel="noopener">Privacy Policy</a>.</span>
      </label>
      <button id="sendRegisterOtpBtn" class="register-primary" type="button" disabled>Create Account</button>
    </div>

    <div class="register-step" data-register-step="otp" hidden>
      <div class="register-otp-summary">
        <span>OTP sent to</span>
        <strong id="otpMaskedPhone">-</strong>
        <small id="otpExpiresText">05:00</small>
      </div>
      <label class="register-field" for="otpCode">
        <span>OTP Code</span>
        <input id="otpCode" maxlength="6" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter OTP">
      </label>
      <p id="otpStatus" class="register-status" aria-live="polite">Enter the OTP to create your account.</p>
      <button id="verifyRegisterOtpBtn" class="register-primary" type="button">Verify OTP</button>
      <button id="resendRegisterOtpBtn" class="register-secondary" type="button" disabled>Didn't receive the code? Resend OTP</button>
    </div>

    <nav class="register-login-action" aria-label="Registration link">
      <a href="/user/">Already have an account? <strong>Log in</strong></a>
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
    <p>Your account is under admin review. Please wait for approval or contact support.</p>
    <button id="closeRegisterReviewBtn" class="register-primary" type="button">Go to Login</button>
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
