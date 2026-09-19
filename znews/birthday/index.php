<?php
declare(strict_types=1);

$page = strtolower(trim((string)($_GET['page'] ?? 'home')));
$allowed = ['home', 'create', 'preview', 'templates', 'manage', 'privacy', 'terms', 'contact'];
if (!in_array($page, $allowed, true)) {
    $page = 'home';
}
$draftQuery = (string)($_GET['draft_id'] ?? $_GET['draft'] ?? '');
$draftId = preg_match('/^[A-Za-z0-9_-]{1,160}$/D', $draftQuery) === 1
    ? $draftQuery
    : '';
$slug = preg_match('/^[a-z0-9-]{8,100}$/D', (string)($_GET['slug'] ?? '')) === 1
    ? (string)$_GET['slug']
    : '';
$titles = [
    'home' => 'Digital Birthday Universe',
    'create' => 'Create Your Birthday Universe',
    'preview' => 'Preview Your Birthday Universe',
    'templates' => 'Birthday Universe Templates',
    'manage' => 'Manage Birthday Universe',
    'privacy' => 'Birthday Universe Privacy',
    'terms' => 'Birthday Universe Terms',
    'contact' => 'Birthday Universe Contact',
];
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, max-age=0, must-revalidate');
$robots = in_array($page, ['create', 'preview', 'manage'], true) ? 'noindex,nofollow' : 'index,follow';
$canonicalPaths = [
    'home' => '/birthday',
    'create' => '/birthday/create',
    'preview' => '/birthday/preview',
    'templates' => '/birthday/templates',
    'manage' => '/birthday/manage',
    'privacy' => '/birthday/privacy',
    'terms' => '/birthday/terms',
    'contact' => '/birthday/contact',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#07121a">
  <meta name="description" content="Create a free, personalized digital birthday universe in minutes.">
  <meta name="robots" content="<?= $robots ?>">
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="canonical" href="https://zsky24.com<?= htmlspecialchars($canonicalPaths[$page], ENT_QUOTES, 'UTF-8') ?>">
  <link rel="preload" as="image" href="/znews/birthday/assets/images/birthday-universe-cosmic.webp" fetchpriority="high">
  <link rel="preload" as="image" href="/znews/birthday/assets/images/moon-surface-v1.webp">
  <link rel="stylesheet" href="/znews/birthday/assets/birthday.css?v=4">
  <title><?= htmlspecialchars($titles[$page] . ' | Z Sky 24', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <script defer src="/znews/birthday/assets/birthday-api.js?v=3"></script>
  <script defer src="/znews/birthday/assets/birthday-ad-service.js?v=1"></script>
  <script type="module" src="/znews/birthday/assets/birthday-scene.js?v=2"></script>
  <script defer src="/znews/birthday/assets/birthday-templates.js?v=4"></script>
  <script defer src="/znews/birthday/assets/birthday-app.js?v=4"></script>
</head>
<body data-page="<?= htmlspecialchars($page, ENT_QUOTES, 'UTF-8') ?>" data-draft-id="<?= htmlspecialchars($draftId, ENT_QUOTES, 'UTF-8') ?>" data-slug="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
  <a class="skip-link" href="#mainContent">Skip to content</a>
  <header class="birthday-header">
    <a class="birthday-brand" href="/birthday" aria-label="Digital Birthday Universe home">
      <span class="birthday-logo" aria-hidden="true"><span>✦</span></span>
      <span><strong>Birthday Universe</strong><small>by Z Sky 24</small></span>
    </a>
    <nav class="birthday-nav" aria-label="Birthday Universe navigation">
      <a href="/birthday/templates" data-copy="navTemplates">Templates</a>
      <a href="/" data-copy="navZsky">Z Sky 24</a>
      <div class="language-switch" role="group" aria-label="Language">
        <button type="button" data-locale="en" aria-pressed="true">EN</button>
        <button type="button" data-locale="bn" aria-pressed="false">বাংলা</button>
      </div>
    </nav>
  </header>

  <main id="mainContent">
  <?php if ($page === 'home'): ?>
    <section class="birthday-hero">
      <div class="birthday-hero-visual" aria-hidden="true"></div>
      <div class="birthday-hero-content">
        <p class="eyebrow" data-copy="heroEyebrow">A free digital birthday experience</p>
        <h1 data-copy="heroTitle">Create Your Birthday Universe</h1>
        <p class="hero-lead" data-copy="heroLead">Create a beautiful personalized birthday universe in minutes. It is free.</p>
        <div class="hero-actions">
          <a class="button primary" href="/birthday/create"><span aria-hidden="true">✦</span><span data-copy="createCta">Create My Universe</span></a>
          <a class="button secondary" href="/birthday/templates" data-copy="exploreCta">Explore Templates</a>
        </div>
        <p class="hero-trust" data-copy="heroTrust">No account is needed to create and share. Z-Pay login is required only to edit or delete later.</p>
      </div>
      <a class="hero-next" href="#birthdayFeatures" aria-label="See what is included"><span></span></a>
    </section>

    <section class="birthday-feature-band" id="birthdayFeatures">
      <div class="section-heading"><p class="eyebrow" data-copy="experienceEyebrow">One link, a whole celebration</p><h2 data-copy="experienceTitle">A story made for one special person</h2></div>
      <div class="feature-grid">
        <article><span aria-hidden="true">✦</span><h3 data-copy="featureStar">Personal Star</h3><p data-copy="featureStarText">A fictional digital star identifier made for the birthday page.</p></article>
        <article><span aria-hidden="true">◐</span><h3 data-copy="featureMoon">Birthday Moon</h3><p data-copy="featureMoonText">A personalized moon scene built around the birthday date.</p></article>
        <article><span aria-hidden="true">◇</span><h3 data-copy="featureMessage">Hidden Message</h3><p data-copy="featureMessageText">An elegant message reveal for your birthday wish.</p></article>
        <article><span aria-hidden="true">▧</span><h3 data-copy="featureMemory">Photo Memory</h3><p data-copy="featureMemoryText">Add one optional photo in an optimized keepsake frame.</p></article>
        <article><span aria-hidden="true">♪</span><h3 data-copy="featureMusic">Music</h3><p data-copy="featureMusicText">Choose a licensed track and let the recipient press play.</p></article>
      </div>
    </section>

    <section class="birthday-how">
      <div class="section-heading"><p class="eyebrow" data-copy="howEyebrow">Ready in minutes</p><h2 data-copy="howTitle">You create it. The platform does the rest.</h2></div>
      <ol class="how-list">
        <li><span>01</span><div><strong data-copy="howOne">Add the birthday details</strong><p data-copy="howOneText">Write the name, date and message.</p></div></li>
        <li><span>02</span><div><strong data-copy="howTwo">Choose the atmosphere</strong><p data-copy="howTwoText">Pick a template, photo and optional music.</p></div></li>
        <li><span>03</span><div><strong data-copy="howThree">Generate and share</strong><p data-copy="howThreeText">Receive a unique link and QR code automatically.</p></div></li>
      </ol>
      <a class="button primary" href="/birthday/create" data-copy="startNow">Start Creating</a>
    </section>
  <?php elseif ($page === 'create'): ?>
    <section class="creator-shell" id="creatorShell" aria-labelledby="creatorTitle">
      <header class="creator-heading">
        <a class="icon-link" href="/birthday" aria-label="Back to Birthday Universe">←</a>
        <div><p class="eyebrow" data-copy="creatorEyebrow">Create for free</p><h1 id="creatorTitle" data-copy="creatorTitle">Build a Birthday Universe</h1></div>
        <span class="step-count" id="stepCount">1 / 7</span>
      </header>
      <div class="step-progress" aria-hidden="true"><span id="stepProgress"></span></div>
      <form id="birthdayCreateForm" novalidate>
        <section class="form-step active" data-step="1" aria-labelledby="stepOneTitle">
          <p class="step-kicker">01</p><h2 id="stepOneTitle" data-copy="stepNameTitle">Who are we celebrating?</h2><p data-copy="stepNameText">Enter the birthday person's name.</p>
          <label class="field"><span data-copy="nameLabel">Birthday person name</span><input id="birthdayName" name="name" maxlength="50" autocomplete="name" required><small><span id="nameCount">0</span>/50</small></label>
        </section>
        <section class="form-step" data-step="2" aria-labelledby="stepTwoTitle" hidden>
          <p class="step-kicker">02</p><h2 id="stepTwoTitle" data-copy="stepDateTitle">When is the birthday?</h2><p data-copy="stepDateText">Day and month are required. Year is optional.</p>
          <div class="date-grid">
            <label class="field"><span data-copy="dayLabel">Day</span><input id="birthdayDay" name="birthday_day" type="number" min="1" max="31" inputmode="numeric" required></label>
            <label class="field"><span data-copy="monthLabel">Month</span><select id="birthdayMonth" name="birthday_month" required><option value="">Select</option></select></label>
            <label class="field"><span data-copy="yearLabel">Year (optional)</span><input id="birthdayYear" name="birthday_year" type="number" min="1900" inputmode="numeric" placeholder="Optional"></label>
          </div>
        </section>
        <section class="form-step" data-step="3" aria-labelledby="stepThreeTitle" hidden>
          <p class="step-kicker">03</p><h2 id="stepThreeTitle" data-copy="stepSenderTitle">Add your name</h2><p data-copy="stepSenderText">This is optional and appears as the sender.</p>
          <label class="field"><span data-copy="senderLabel">Sender name</span><input id="senderName" name="sender_name" maxlength="50" autocomplete="name"><small><span id="senderCount">0</span>/50</small></label>
        </section>
        <section class="form-step" data-step="4" aria-labelledby="stepFourTitle" hidden>
          <p class="step-kicker">04</p><h2 id="stepFourTitle" data-copy="stepMessageTitle">Write the birthday message</h2><p data-copy="stepMessageText">The recipient will open this message inside the Universe.</p>
          <label class="field"><span data-copy="messageLabel">Birthday message</span><textarea id="birthdayMessage" name="message" maxlength="500" rows="7"></textarea><small><span id="messageCount">0</span>/500</small></label>
        </section>
        <section class="form-step" data-step="5" aria-labelledby="stepFiveTitle" hidden>
          <p class="step-kicker">05</p><h2 id="stepFiveTitle" data-copy="stepPhotoTitle">Add a photo</h2><p data-copy="stepPhotoText">Optional. JPEG, PNG or WebP, up to 5 MB.</p>
          <label class="photo-picker" id="photoPicker"><input id="birthdayPhoto" name="photo" type="file" accept="image/jpeg,image/png,image/webp"><span class="photo-placeholder" id="photoPlaceholder"><b aria-hidden="true">＋</b><strong data-copy="photoChoose">Choose photo</strong></span><img id="photoPreview" alt="Selected birthday preview" hidden></label>
          <button class="text-button" id="removePhoto" type="button" hidden data-copy="photoRemove">Remove photo</button>
        </section>
        <section class="form-step" data-step="6" aria-labelledby="stepSixTitle" hidden>
          <p class="step-kicker">06</p><h2 id="stepSixTitle" data-copy="stepTemplateTitle">Choose a Universe</h2><p data-copy="stepTemplateText">Each style uses the same birthday details in a different atmosphere.</p>
          <div class="template-picker" id="templatePicker" aria-live="polite"></div>
        </section>
        <section class="form-step" data-step="7" aria-labelledby="stepSevenTitle" hidden>
          <p class="step-kicker">07</p><h2 id="stepSevenTitle" data-copy="stepMusicTitle">Choose the finishing touch</h2><p data-copy="stepMusicText">Sound begins after the recipient enters the Universe.</p>
          <div class="music-picker" id="musicPicker" aria-live="polite"></div>
          <section class="custom-audio-panel" id="customAudioPanel" hidden>
            <label class="audio-picker" id="customAudioPicker"><input id="birthdayAudio" name="audio" type="file" accept="audio/mpeg,audio/mp4,audio/ogg"><span><strong data-copy="audioChoose">Choose your audio</strong><small data-copy="audioLimit">MP3, M4A or OGG, up to 30 seconds.</small></span></label>
            <div class="audio-selection" id="audioSelection" hidden><span id="audioFileName"></span><button class="text-button" id="removeAudio" type="button" data-copy="audioRemove">Remove audio</button></div>
            <label class="check-row"><input id="audioRightsConfirmed" type="checkbox"><span data-copy="audioRightsLabel">I have permission to use this audio on a public birthday page.</span></label>
          </section>
          <fieldset class="privacy-choice"><legend data-copy="visibilityTitle">Search visibility</legend><label><input type="radio" name="visibility" value="UNLISTED" checked><span><strong data-copy="unlistedTitle">Unlisted</strong><small data-copy="unlistedText">Only people with the link can open it.</small></span></label><label id="publicVisibilityOption"><input type="radio" name="visibility" value="PUBLIC"><span><strong data-copy="publicTitle">Public</strong><small data-copy="publicText">Search engines may index the page.</small></span></label></fieldset>
          <label class="check-row"><input id="sharePhoto" type="checkbox"><span data-copy="sharePhotoLabel">Use the uploaded photo in social link previews.</span></label>
          <label class="check-row"><input id="consentConfirmed" type="checkbox" required><span data-copy="consentLabel">I have permission to publish this name, message, photo and audio.</span></label>
        </section>
        <p class="form-error" id="formError" role="alert" hidden></p>
        <div class="form-actions"><button class="button secondary" id="previousStep" type="button" hidden data-copy="backButton">Back</button><button class="button primary" id="nextStep" type="button" data-copy="continueButton">Continue</button></div>
      </form>
    </section>
  <?php elseif ($page === 'preview'): ?>
    <section class="preview-shell" data-draft="<?= htmlspecialchars($draftId, ENT_QUOTES, 'UTF-8') ?>">
      <header class="preview-toolbar"><a class="icon-link" href="/birthday/create" aria-label="Back to editor">←</a><div><p class="eyebrow" data-copy="previewEyebrow">Private preview</p><h1 data-copy="previewTitle">Your Universe is almost ready</h1></div></header>
      <div class="universe-mount preview-mount" id="previewUniverse" aria-live="polite"><div class="loading-state" data-copy="loadingPreview">Loading your preview…</div></div>
      <div class="ad-slot" id="birthdayPreviewAd" hidden><span>Advertisement</span></div>
      <section class="generation-panel" id="generationPanel">
        <div><h2 data-copy="generateTitle">Ready to make it real?</h2><p data-copy="generateText">Generation is automatic. Ads never block access to your birthday page.</p></div>
        <button class="button primary" id="generateUniverse" type="button" data-copy="generateButton">Generate Universe</button>
        <p class="form-error" id="previewError" role="alert" hidden></p>
      </section>
      <section class="success-panel" id="generationSuccess" hidden>
        <p class="eyebrow" data-copy="successEyebrow">Universe created</p><h2 data-copy="successTitle">Your shareable link is ready</h2>
        <div class="share-link-row"><input id="generatedUrl" readonly aria-label="Generated Universe URL"><button class="icon-button" id="copyGeneratedUrl" type="button" title="Copy link" aria-label="Copy link">⧉</button></div>
        <div class="recovery-box"><strong data-copy="recoveryTitle">Save this recovery code</strong><code id="recoveryCode"></code><p data-copy="recoveryText">You will need this code plus Z-Pay login to edit or delete the Universe.</p><button class="button secondary" id="copyRecoveryCode" type="button" data-copy="copyRecovery">Copy recovery code</button></div>
        <div class="success-actions"><a class="button primary" id="openUniverse" href="#" data-copy="openUniverse">Open Universe</a><button class="button secondary" id="shareGenerated" type="button" data-copy="shareButton">Share</button></div>
      </section>
    </section>
  <?php elseif ($page === 'templates'): ?>
    <section class="catalog-shell"><header class="catalog-heading"><p class="eyebrow" data-copy="templatesEyebrow">Three ways to celebrate</p><h1 data-copy="templatesTitle">Choose the feeling of the moment</h1><p data-copy="templatesLead">Every template is responsive, accessible and built for a personal birthday story.</p></header><div class="template-catalog" id="templateCatalog"></div><a class="button primary" href="/birthday/create" data-copy="createCta">Create My Universe</a></section>
  <?php elseif ($page === 'manage'): ?>
    <section class="manage-shell" data-slug="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>">
      <header class="manage-heading"><a class="icon-link" href="<?= $slug !== '' ? '/u/' . rawurlencode($slug) : '/birthday' ?>" aria-label="Back">←</a><div><p class="eyebrow" data-copy="manageEyebrow">Owner controls</p><h1 data-copy="manageTitle">Manage Birthday Universe</h1></div></header>
      <div class="manage-state" id="manageState"><div class="loading-state" data-copy="manageLoading">Checking your Z-Pay access…</div></div>
    </section>
  <?php else: ?>
    <article class="legal-shell">
      <a class="icon-link" href="/birthday" aria-label="Back to Birthday Universe">←</a>
      <?php if ($page === 'privacy'): ?>
        <p class="eyebrow" data-copy="privacyEyebrow">Privacy</p><h1 data-copy="privacyTitle">Birthday Universe Privacy</h1>
        <p data-copy="privacyIntro">Birthday Universe stores the details you submit so the shareable page can work. A birthday day and month are required; the year and photo are optional.</p>
        <h2 data-copy="privacyRetentionTitle">Visibility and retention</h2><p data-copy="privacyRetentionText">New pages are unlisted by default. They remain accessible to anyone who has the unique link, expire after 90 days by default, and may be renewed by a claimed owner.</p>
        <h2 data-copy="privacyAnalyticsTitle">Photos and analytics</h2><p data-copy="privacyAnalyticsText">Photos are validated, optimized and kept in private server storage. We record limited privacy-conscious events such as views, shares and QR downloads without publishing internal identifiers.</p>
        <h2 data-copy="privacyControlsTitle">Your controls</h2><p data-copy="privacyControlsText">After claiming a page with Z-Pay login and its recovery code, you can update, renew or delete it. You may also report a page from its public view.</p>
      <?php elseif ($page === 'terms'): ?>
        <p class="eyebrow" data-copy="termsEyebrow">Terms</p><h1 data-copy="termsTitle">Birthday Universe Terms</h1>
        <p data-copy="termsIntro">You may publish only content you have permission to share. Do not upload unlawful, abusive, deceptive, infringing or private material without consent.</p>
        <h2 data-copy="termsFictionTitle">Fictional digital experience</h2><p data-copy="termsFictionText">Personal stars, moons and universe elements are creative digital features. They do not represent ownership, registration or legal rights to any real astronomical object.</p>
        <h2 data-copy="termsAvailabilityTitle">Availability</h2><p data-copy="termsAvailabilityText">Pages may expire, be removed after a valid report, or become unavailable during maintenance. Advertisements do not require or reward clicks.</p>
      <?php else: ?>
        <p class="eyebrow" data-copy="contactEyebrow">Contact</p><h1 data-copy="contactTitle">Contact Z Sky 24</h1><p data-copy="contactText">For privacy, copyright or abuse concerns, use the Report action on the relevant Birthday Universe. For account support, open the Z-Pay support center.</p><a class="button primary" href="https://zpayswift.com/user/support" data-copy="contactSupport">Open Z-Pay Support</a>
      <?php endif; ?>
    </article>
  <?php endif; ?>
  </main>

  <footer class="birthday-footer"><span data-copy="footerBrand">Digital Birthday Universe · Z Sky 24</span><nav><a href="/birthday/privacy" data-copy="footerPrivacy">Privacy</a><a href="/birthday/terms" data-copy="footerTerms">Terms</a><a href="/birthday/contact" data-copy="footerContact">Contact</a></nav><p data-copy="footerDisclaimer">This personalized star is a fictional digital element and does not represent ownership of a real astronomical object.</p></footer>
  <div class="toast" id="birthdayToast" role="status" aria-live="polite" hidden></div>
</body>
</html>
