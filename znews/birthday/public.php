<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/znews/bootstrap.php';
require_once dirname(__DIR__, 2) . '/api/znews/lib/birthday.php';

$slug = strtolower(trim((string)($_GET['slug'] ?? '')));
$universe = birthday_universe_by_slug($slug);
$available = is_array($universe);
$name = $available ? (string)$universe['name'] : 'Birthday Universe';
$isBn = $available && ($universe['locale'] ?? '') === 'bn';
$text = static fn(string $english, string $bangla): string => $isBn ? $bangla : $english;
$title = $available
    ? ($isBn ? $name . '-এর Birthday Universe' : $name . "'s Birthday Universe")
    : 'Birthday Universe unavailable';
$description = $available
    ? $text('A special digital birthday universe created for ' . $name . '.', $name . '-এর জন্য তৈরি একটি বিশেষ ডিজিটাল জন্মদিনের মহাবিশ্ব।')
    : 'This Birthday Universe is unavailable or has expired.';
$canonical = 'https://zsky24.com/u/' . rawurlencode($slug);
$public = $available ? birthday_public_universe($universe) : [];
$photoUrl = $available && !empty($universe['share_photo']) && trim((string)($universe['photo_media_id'] ?? '')) !== ''
    ? 'https://zsky24.com/api/znews/birthday/media.php?id=' . rawurlencode((string)$universe['photo_media_id'])
    : 'https://zsky24.com/znews/birthday/assets/images/birthday-universe-cosmic.webp';
$robots = $available && strtoupper((string)($universe['visibility'] ?? 'UNLISTED')) === 'PUBLIC' ? 'index,follow' : 'noindex,follow';
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: ' . ($robots === 'index,follow' ? 'public, max-age=120, stale-while-revalidate=300' : 'private, no-store, max-age=0'));
?>
<!doctype html>
<html lang="<?= $isBn ? 'bn' : 'en' ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#07121a">
  <meta name="robots" content="<?= $robots ?>">
  <meta name="description" content="<?= htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Z Sky 24">
  <meta property="og:title" content="<?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <meta property="og:description" content="<?= htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <meta property="og:url" content="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>">
  <meta property="og:image" content="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>">
  <link rel="preload" as="image" href="/znews/birthday/assets/images/birthday-universe-cosmic.webp" fetchpriority="high">
  <link rel="preload" as="image" href="/znews/birthday/assets/images/moon-surface-v1.webp">
  <link rel="stylesheet" href="/znews/birthday/assets/birthday.css?v=5">
  <title><?= htmlspecialchars($title . ' | Z Sky 24', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <script defer src="/znews/birthday/assets/lib/qrcode.min.js?v=1"></script>
  <script defer src="/znews/birthday/assets/birthday-api.js?v=4"></script>
  <script defer src="/znews/birthday/assets/birthday-ad-service.js?v=1"></script>
  <script type="module" src="/znews/birthday/assets/birthday-scene.js?v=3"></script>
  <script defer src="/znews/birthday/assets/birthday-templates.js?v=5"></script>
  <script defer src="/znews/birthday/assets/birthday-public.js?v=4"></script>
</head>
<body class="public-universe-page" data-slug="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>" data-available="<?= $available ? 'true' : 'false' ?>">
  <a class="skip-link" href="#mainContent">Skip to birthday universe</a>
  <header class="public-universe-header"><a class="birthday-brand" href="/birthday"><span class="birthday-logo" aria-hidden="true"><span>✦</span></span><span><strong>Birthday Universe</strong><small>by Z Sky 24</small></span></a><button class="icon-button" id="publicShareTop" type="button" title="Share" aria-label="Share this Birthday Universe">↗</button></header>
  <main id="mainContent">
    <?php if ($available): ?>
      <div class="universe-mount public-mount" id="publicUniverse"><div class="loading-state"><?= htmlspecialchars($text('Opening ' . $name . "'s universe...", $name . '-এর Universe খোলা হচ্ছে...'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div></div>
      <section class="public-share-panel" aria-labelledby="shareTitle">
        <p class="eyebrow"><?= htmlspecialchars($text('Keep the celebration moving', 'উদযাপনটি সবার সঙ্গে ভাগ করুন'), ENT_QUOTES, 'UTF-8') ?></p><h2 id="shareTitle"><?= htmlspecialchars($text('Share this Birthday Universe', 'এই Birthday Universe শেয়ার করুন'), ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="share-buttons"><button class="button primary" id="nativeShare" type="button"><?= htmlspecialchars($text('Share', 'শেয়ার'), ENT_QUOTES, 'UTF-8') ?></button><button class="button secondary" id="copyPublicLink" type="button"><?= htmlspecialchars($text('Copy Link', 'লিংক কপি'), ENT_QUOTES, 'UTF-8') ?></button><a class="button secondary" id="whatsappShare" href="#" rel="noopener">WhatsApp</a><a class="button secondary" id="telegramShare" href="#" rel="noopener">Telegram</a><a class="button secondary" id="facebookShare" href="#" rel="noopener">Facebook</a></div>
        <div class="qr-panel"><div id="qrCode" aria-label="QR code for this Birthday Universe"></div><div><strong><?= htmlspecialchars($text('Scan to Open This Birthday Universe', 'Birthday Universe খুলতে স্ক্যান করুন'), ENT_QUOTES, 'UTF-8') ?></strong><p><?= htmlspecialchars($text('The QR contains only this public URL.', 'QR code-এ শুধু এই public URL রয়েছে।'), ENT_QUOTES, 'UTF-8') ?></p><button class="button secondary" id="downloadQr" type="button"><?= htmlspecialchars($text('Download QR PNG', 'QR PNG ডাউনলোড'), ENT_QUOTES, 'UTF-8') ?></button></div></div>
      </section>
      <div class="ad-slot public-ad-slot" id="birthdayPublicAd" hidden><span>Advertisement</span></div>
      <section class="public-owner-actions"><a href="/birthday/manage/<?= rawurlencode($slug) ?>"><?= htmlspecialchars($text('Manage this Universe', 'এই Universe পরিচালনা করুন'), ENT_QUOTES, 'UTF-8') ?></a><button id="reportUniverse" type="button"><?= htmlspecialchars($text('Report', 'রিপোর্ট'), ENT_QUOTES, 'UTF-8') ?></button></section>
    <?php else: ?>
      <section class="unavailable-state"><span aria-hidden="true">✦</span><h1>This Universe is unavailable</h1><p>It may have expired, been deleted, or the link may be incorrect.</p><a class="button primary" href="/birthday/create">Create a Birthday Universe</a></section>
    <?php endif; ?>
  </main>
  <footer class="birthday-footer"><span>Digital Birthday Universe · Z Sky 24</span><nav><a href="/birthday/privacy"><?= htmlspecialchars($text('Privacy', 'গোপনীয়তা'), ENT_QUOTES, 'UTF-8') ?></a><a href="/birthday/terms"><?= htmlspecialchars($text('Terms', 'শর্তাবলি'), ENT_QUOTES, 'UTF-8') ?></a><a href="/birthday/contact"><?= htmlspecialchars($text('Contact', 'যোগাযোগ'), ENT_QUOTES, 'UTF-8') ?></a></nav><p><?= htmlspecialchars($text('This personalized star is a fictional digital element and does not represent ownership of a real astronomical object.', 'এই ব্যক্তিগত তারাটি একটি কাল্পনিক ডিজিটাল উপাদান; এটি কোনো বাস্তব মহাজাগতিক বস্তুর মালিকানা নির্দেশ করে না।'), ENT_QUOTES, 'UTF-8') ?></p></footer>
  <dialog class="report-dialog" id="reportDialog"><form method="dialog" id="reportForm"><button class="dialog-close" value="cancel" aria-label="Close">×</button><h2><?= htmlspecialchars($text('Report this Universe', 'এই Universe রিপোর্ট করুন'), ENT_QUOTES, 'UTF-8') ?></h2><label class="field"><span><?= htmlspecialchars($text('Reason', 'কারণ'), ENT_QUOTES, 'UTF-8') ?></span><select id="reportReason"><option value="PRIVACY"><?= htmlspecialchars($text('Privacy', 'গোপনীয়তা'), ENT_QUOTES, 'UTF-8') ?></option><option value="ABUSE"><?= htmlspecialchars($text('Abuse', 'অপব্যবহার'), ENT_QUOTES, 'UTF-8') ?></option><option value="SPAM">Spam</option><option value="COPYRIGHT">Copyright</option><option value="OTHER"><?= htmlspecialchars($text('Other', 'অন্যান্য'), ENT_QUOTES, 'UTF-8') ?></option></select></label><label class="field"><span><?= htmlspecialchars($text('Details (optional)', 'বিস্তারিত (ঐচ্ছিক)'), ENT_QUOTES, 'UTF-8') ?></span><textarea id="reportDetails" maxlength="300" rows="4"></textarea></label><button class="button primary" value="submit"><?= htmlspecialchars($text('Submit report', 'রিপোর্ট জমা দিন'), ENT_QUOTES, 'UTF-8') ?></button></form></dialog>
  <div class="toast" id="birthdayToast" role="status" aria-live="polite" hidden></div>
</body>
</html>
