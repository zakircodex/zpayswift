<?php
declare(strict_types=1);

$title = htmlspecialchars((string)$userPage['title'], ENT_QUOTES, 'UTF-8');
$bodyClass = htmlspecialchars((string)$userPage['body_class'], ENT_QUOTES, 'UTF-8');
$sectionId = htmlspecialchars((string)$userPage['section_id'], ENT_QUOTES, 'UTF-8');
$pageKey = htmlspecialchars((string)$userPage['key'], ENT_QUOTES, 'UTF-8');
$pageCss = basename((string)$userPage['page_css']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="theme-color" content="#07172f">
  <title><?= $title ?></title>
  <link rel="icon" type="image/png" href="/assets/brand/favicon.png">
  <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
  <link rel="stylesheet" href="/assets/brand/brand.css?v=1">
  <link rel="stylesheet" href="/api/user/assets/user-shell.css?v=<?= user_page_asset_version('user-shell.css') ?>">
  <link rel="stylesheet" href="/api/user/assets/user-components.css?v=<?= user_page_asset_version('user-components.css') ?>">
  <link rel="stylesheet" href="/api/user/assets/pages/<?= htmlspecialchars($pageCss, ENT_QUOTES, 'UTF-8') ?>?v=<?= user_page_asset_version('pages/' . $pageCss) ?>">
</head>
<body class="user-authenticated <?= $bodyClass ?>" data-user-page="<?= $pageKey ?>" data-active-section="<?= $sectionId ?>">
<div id="appView">
  <div id="sidebarOverlay" class="sidebar-overlay"></div>
  <div class="app-shell">
