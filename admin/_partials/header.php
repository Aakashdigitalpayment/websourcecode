<?php
/**
 * Unified admin header — v11.0 (Hajir Pro style: light, clean, search + action icons)
 * Include at the TOP of every admin page (after _bootstrap.php).
 */
$page_title = $page_title ?? 'Admin';
$page_icon  = $page_icon  ?? 'fa-gauge';
$base       = '/admin';

$_apLogoRaw = function_exists('getLocalizedLogoPath')
    ? trim((string) getLocalizedLogoPath(''))
    : (function_exists('getSetting')
        ? trim((string) getSetting('site_logo', getSetting('logo', '')))
        : '');
$_apLogoSrc = '';
if ($_apLogoRaw !== '') {
    $_apLogoSrc = (preg_match('#^https?://#i', $_apLogoRaw))
        ? $_apLogoRaw
        : rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/' . ltrim($_apLogoRaw, '/');
}
$_apSiteName = function_exists('getSetting') ? getSetting('site_name', 'Admin Panel') : 'Admin Panel';
$_apAdminName = $_SESSION['admin_name'] ?? 'Admin';
$_apInitial = mb_substr($_apAdminName, 0, 1);
?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1a5f2a">
<meta name="description" content="सहकारी HRM & CMS System - मानव संशाधन व्यवस्थापन प्रणाली">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="HRM System">
<link rel="apple-touch-icon" href="<?= SITE_URL ?>assets/images/icon-192x192.png">
<link rel="manifest" href="<?= SITE_URL ?>manifest.json">
<title><?= htmlspecialchars($page_title) ?> · <?= htmlspecialchars($_apSiteName) ?></title>
<?php
if (function_exists('coopThemeHeadAssets')) {
    coopThemeHeadAssets('shell');
}
?>
<style>
  :root { --hp-border:#e6e9ef; --hp-muted:#6b7280; --hp-text:#1f2937; --hp-soft:#f5f7fb; }
  .hp-header{
    background:#fff; color:var(--hp-text);
    padding:10px 18px; display:flex; align-items:center; gap:14px;
    border-bottom:1px solid var(--hp-border);
    box-shadow:0 1px 0 rgba(15,23,42,.04);
    position:sticky; top:0; z-index:50;
  }
  .hp-burger{
    background:var(--hp-soft); border:1px solid var(--hp-border); color:var(--hp-text);
    width:40px; height:40px; border-radius:10px; cursor:pointer; font-size:15px; flex-shrink:0;
    display:grid; place-items:center; transition:background .15s;
  }
  .hp-burger:hover{ background:#eef1f6; }
  .hp-brand{ display:flex; align-items:center; gap:6px; flex-shrink:0; text-decoration:none; }
  .hp-brand-text{ font-weight:800; font-size:18px; color:var(--primary,#1a5f2a); letter-spacing:.2px; }
  .hp-brand-text small{ color:var(--secondary,#c0392b); font-weight:700; margin-left:2px; }
  .hp-company{
    display:flex; align-items:center; gap:10px;
    background:#fff; border:1px solid var(--hp-border); border-radius:12px;
    padding:5px 14px 5px 6px; min-width:0; max-width:260px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  .hp-company-logo{
    width:38px; height:38px; border-radius:8px; background:var(--hp-soft);
    display:grid; place-items:center; flex-shrink:0; overflow:hidden;
  }
  .hp-company-logo img{ width:100%; height:100%; object-fit:contain; }
  .hp-company-meta{ min-width:0; line-height:1.15; }
  .hp-company-name{ font-weight:700; font-size:13px; color:var(--hp-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .hp-company-sub{ font-size:11px; color:var(--hp-muted); display:flex; align-items:center; gap:4px; }
  .hp-company-sub .fa-circle-check{ color:#10b981; font-size:10px; }
  .hp-search{
    flex:1 1 auto; min-width:0; max-width:560px;
    display:flex; align-items:center; gap:10px;
    background:var(--hp-soft); border:1px solid transparent; border-radius:12px;
    padding:9px 14px; transition:border-color .15s, background .15s;
  }
  .hp-search:focus-within{ background:#fff; border-color:var(--primary-color,#1a5f2a); }
  .hp-search i{ color:var(--hp-muted); font-size:14px; }
  .hp-search input{ flex:1; border:0; outline:0; background:transparent; font:500 14px 'Mukta',sans-serif; color:var(--hp-text); }
  .hp-actions{ display:flex; align-items:center; gap:6px; flex-shrink:0; margin-left:auto; }
  .hp-icon{
    width:40px; height:40px; border-radius:10px;
    background:transparent; border:0; color:#475569;
    display:grid; place-items:center; cursor:pointer; text-decoration:none;
    font-size:15px; position:relative; transition:background .15s, color .15s;
  }
  .hp-icon:hover{ background:var(--hp-soft); color:var(--primary-color,#1a5f2a); }
  .hp-badge{
    position:absolute; top:4px; right:4px;
    min-width:16px; height:16px; padding:0 4px;
    background:#ef4444; color:#fff; font-size:10px; font-weight:700;
    border-radius:999px; display:grid; place-items:center; line-height:1;
    border:2px solid #fff;
  }
  .hp-dot{
    position:absolute; top:8px; right:9px;
    width:8px; height:8px; background:#ef4444; border-radius:50%; border:2px solid #fff;
  }
  .hp-avatar{
    width:38px; height:38px; border-radius:50%;
    background:linear-gradient(135deg,#dbeafe,#bfdbfe);
    color:#1d4ed8; font-weight:700; font-size:14px;
    display:grid; place-items:center; margin-left:4px;
    text-decoration:none; flex-shrink:0;
  }
  /* Mobile */
  @media (max-width: 768px){
    .hp-header{ padding:8px 10px; gap:8px; }
    .hp-burger{ display:grid !important; }
    .hp-brand-text{ display:none; }
    .hp-company{ max-width:160px; padding:4px 10px 4px 4px; gap:8px; }
    .hp-company-logo{ width:32px; height:32px; }
    .hp-company-name{ font-size:12px; }
    .hp-company-sub{ font-size:10px; }
    .hp-search{ display:none; }
    .hp-actions{ gap:2px; }
    .hp-icon{ width:36px; height:36px; font-size:14px; }
    .hp-avatar{ width:34px; height:34px; font-size:13px; }
  }
  @media (max-width: 380px){
    .hp-company-meta{ display:none; }
    .hp-company{ padding:4px; }
  }
  @media (min-width: 769px){
    .hp-burger{ display:none !important; }
  }
</style>
<script src="<?= SITE_URL ?>assets/js/pwa-register.js" defer></script>
</head>
<body class="admin-shell">

<header class="hp-header">
  <button class="hp-burger" onclick="document.getElementById('adminSidebar').classList.toggle('open')" aria-label="Menu">
    <?php if (function_exists('icon')) { echo icon('menu', 18); } else { ?><i class="fas fa-bars"></i><?php } ?>
  </button>

  <a href="<?= $base ?>/" class="hp-brand">
    <span class="hp-brand-text"><?= htmlspecialchars($_apSiteName) ?></span>
  </a>

  <div class="hp-company" title="<?= htmlspecialchars($_apSiteName) ?>">
    <span class="hp-company-logo">
      <?php if ($_apLogoSrc !== ''): ?>
        <img src="<?= htmlspecialchars($_apLogoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($_apSiteName, ENT_QUOTES, 'UTF-8') ?>">
      <?php else: ?>
        <?php if (function_exists('icon')) { echo icon(fa_to_lucide($page_icon), 22, 'color:var(--primary,#1a5f2a);'); } else { ?><i class="fas <?= htmlspecialchars($page_icon) ?>"></i><?php } ?>
      <?php endif; ?>
    </span>
    <div class="hp-company-meta">
      <div class="hp-company-name"><?= htmlspecialchars($_apSiteName) ?></div>
      <div class="hp-company-sub"><?= htmlspecialchars($page_title) ?> <?php if (function_exists('icon')) { echo icon('check-circle', 12, 'color:#10b981;'); } else { ?><i class="lucide-icon" aria-hidden="true" data-lucide="circle-check"></i><?php } ?></div>
    </div>
  </div>

  <form class="hp-search" method="get" action="<?= $base ?>/search.php" role="search">
    <?php if (function_exists('icon')) { echo icon('search', 16, 'color:var(--hp-muted,#6b7280);'); } else { ?><i class="lucide-icon" aria-hidden="true" data-lucide="search"></i><?php } ?>
    <input type="search" name="q" placeholder="<?= htmlspecialchars('Search ...') ?>" autocomplete="off">
  </form>

  <div class="hp-actions">
    <a href="<?= $base ?>/messages.php" class="hp-icon" title="Messages"><?php if (function_exists('icon')) { echo icon('message-circle', 18); } else { ?><i class="fas fa-comment-dots"></i><?php } ?></a>
    <a href="<?= $base ?>/scan.php" class="hp-icon" title="QR"><?php if (function_exists('icon')) { echo icon('qr-code', 18); } else { ?><i class="fas fa-qrcode"></i><?php } ?></a>
    <a href="<?= $base ?>/notifications.php" class="hp-icon" title="Notifications">
      <?php if (function_exists('icon')) { echo icon('bell', 18); } else { ?><i class="lucide-icon" aria-hidden="true" data-lucide="bell"></i><?php } ?>
      <?php $_n = (int)($_SESSION['admin_unread'] ?? 0); if ($_n > 0): ?>
        <span class="hp-badge"><?= $_n > 99 ? '99+' : $_n ?></span>
      <?php else: ?>
        <span class="hp-dot"></span>
      <?php endif; ?>
    </a>
    <a href="<?= $base ?>/settings.php" class="hp-icon" title="Settings"><?php if (function_exists('icon')) { echo icon('settings', 18); } else { ?><i class="fas fa-cog"></i><?php } ?></a>
    <a href="<?= $base ?>/profile.php" class="hp-avatar" title="<?= htmlspecialchars($_apAdminName) ?>"><?= htmlspecialchars(mb_strtoupper($_apInitial)) ?></a>
  </div>
</header>

<div class="admin-page">
