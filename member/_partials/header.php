<?php
/**
 * Member Portal Header — v11.0 (Hajir Pro style: light, clean, search + actions)
 */
$memberLang = function_exists('getCurrentLang') ? getCurrentLang() : 'np';
$memberIsEnglish = ($memberLang === 'en');
$memberT = static function (string $np, string $en) use ($memberIsEnglish): string {
  return $memberIsEnglish ? $en : $np;
};
$page_title = $page_title ?? $memberT('सदस्य पोर्टल', 'Member Portal');
$siteName   = function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी';
$memberLangQuery = $_GET;
$memberLangQuery['lang'] = $memberIsEnglish ? 'np' : 'en';
$memberLangToggleUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($memberLangQuery);
$memberLangBadge = $memberIsEnglish ? 'EN' : 'ने';

$_mpLogoRaw = function_exists('getLocalizedLogoPath')
    ? trim((string) getLocalizedLogoPath(''))
    : (function_exists('getSetting')
        ? trim((string) getSetting('site_logo', getSetting('logo', '')))
        : '');
$_mpLogoSrc = '';
if ($_mpLogoRaw !== '') {
    $_mpLogoSrc = (preg_match('#^https?://#i', $_mpLogoRaw))
        ? $_mpLogoRaw
        : rtrim(defined('SITE_URL') ? SITE_URL : '/', '/') . '/' . ltrim($_mpLogoRaw, '/');
}

$memberPhotoUrl = '';
try {
  $raw = trim((string)($_SESSION['member_avatar'] ?? ''));
  if ($raw === '' && !empty($_SESSION['member_id']) && function_exists('getDB')) {
    $db = getDB();
    $mid = (int)$_SESSION['member_id'];
    $q = $db->prepare("SELECT COALESCE(NULLIF(k.photo,''), NULLIF(m.avatar_url,'')) AS photo_path FROM members m LEFT JOIN kyc_applications k ON k.id = m.kyc_application_id WHERE m.id = ? LIMIT 1");
    $q->execute([$mid]);
    $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    $raw = trim((string)($row['photo_path'] ?? ''));
    if ($raw !== '') $_SESSION['member_avatar'] = $raw;
  }
  if ($raw !== '') {
    $memberPhotoUrl = preg_match('#^https?://#i', $raw) ? $raw : ((defined('SITE_URL') ? SITE_URL : '/') . ltrim($raw, '/'));
  }
} catch (Throwable $e) { $memberPhotoUrl = ''; }
$memName = $_SESSION['member_name'] ?? $memberT('सदस्य', 'Member');
$memInitial = mb_substr($memName, 0, 1);
?>
<!DOCTYPE html>
<html lang="<?= $memberIsEnglish ? 'en' : 'ne' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<title><?= htmlspecialchars($page_title) ?> · <?= htmlspecialchars($siteName) ?></title>

<?php if (function_exists('coopThemeHeadAssets')) { coopThemeHeadAssets('shell'); } ?>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: 'Inter','Noto Sans Devanagari',sans-serif; background: #f7f9f8; color: #1f2937; }
  :root { --hp-border:#e6e9ef; --hp-muted:#6b7280; --hp-text:#1f2937; --hp-soft:#f5f7fb; }
  .mp-header{
    background:#fff; color:var(--hp-text);
    padding:10px 18px; display:flex; align-items:center; gap:14px;
    border-bottom:1px solid var(--hp-border);
    box-shadow:0 1px 0 rgba(15,23,42,.04);
    position:sticky; top:0; z-index:50;
  }
  .mp-brand{ display:flex; align-items:center; gap:6px; flex-shrink:0; text-decoration:none; }
  .mp-brand-text{ font-weight:800; font-size:18px; color:var(--primary-color,#1a5f2a); letter-spacing:.2px; }
  .mp-company{
    display:flex; align-items:center; gap:10px;
    background:#fff; border:1px solid var(--hp-border); border-radius:12px;
    padding:5px 14px 5px 6px; min-width:0; max-width:260px;
    box-shadow:0 1px 2px rgba(15,23,42,.04);
  }
  .mp-company-logo{
    width:38px; height:38px; border-radius:8px; background:var(--hp-soft);
    display:grid; place-items:center; flex-shrink:0; overflow:hidden;
  }
  .mp-company-logo img{ width:100%; height:100%; object-fit:contain; }
  .mp-company-meta{ min-width:0; line-height:1.15; }
  .mp-company-name{ font-weight:700; font-size:13px; color:var(--hp-text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .mp-company-sub{ font-size:11px; color:var(--hp-muted); display:flex; align-items:center; gap:4px; }
  .mp-company-sub .fa-circle-check{ color:#10b981; font-size:10px; }
  .mp-search{
    flex:1 1 auto; min-width:0; max-width:560px;
    display:flex; align-items:center; gap:10px;
    background:var(--hp-soft); border:1px solid transparent; border-radius:12px;
    padding:9px 14px; transition:border-color .15s, background .15s;
  }
  .mp-search:focus-within{ background:#fff; border-color:var(--primary-color,#1a5f2a); }
  .mp-search i{ color:var(--hp-muted); font-size:14px; }
  .mp-search input{ flex:1; border:0; outline:0; background:transparent; font:500 14px 'Inter','Noto Sans Devanagari',sans-serif; color:var(--hp-text); }
  .mp-actions{ display:flex; align-items:center; gap:6px; flex-shrink:0; margin-left:auto; }
  .mp-icon{
    width:40px; height:40px; border-radius:10px; background:transparent; border:0; color:#475569;
    display:grid; place-items:center; cursor:pointer; text-decoration:none; font-size:15px; position:relative; transition:background .15s, color .15s;
  }
  .mp-icon:hover{ background:var(--hp-soft); color:var(--primary-color,#1a5f2a); }
  .mp-dot{ position:absolute; top:8px; right:9px; width:8px; height:8px; background:#ef4444; border-radius:50%; border:2px solid #fff; }
  .mp-lang{
    border:1px solid var(--hp-border); border-radius:10px; padding:0 10px; height:38px;
    display:inline-flex; align-items:center; font-size:12px; font-weight:700; color:var(--hp-text);
    text-decoration:none; background:#fff;
  }
  .mp-lang:hover{ background:var(--hp-soft); }
  .mp-avatar{
    width:38px; height:38px; border-radius:50%;
    background:linear-gradient(135deg,#dbeafe,#bfdbfe);
    color:#1d4ed8; font-weight:700; font-size:14px;
    display:grid; place-items:center; margin-left:4px; text-decoration:none; flex-shrink:0; overflow:hidden;
  }
  .mp-avatar img{ width:100%; height:100%; object-fit:cover; }
  @media (max-width: 768px){
    .mp-header{ padding:8px 10px; gap:8px; }
    .mp-brand-text{ display:none; }
    .mp-company{ max-width:170px; padding:4px 10px 4px 4px; gap:8px; }
    .mp-company-logo{ width:32px; height:32px; }
    .mp-company-name{ font-size:12px; }
    .mp-company-sub{ font-size:10px; }
    .mp-search{ display:none; }
    .mp-actions{ gap:2px; }
    .mp-icon{ width:36px; height:36px; font-size:14px; }
    .mp-lang{ height:34px; padding:0 8px; font-size:11px; }
    .mp-avatar{ width:34px; height:34px; font-size:13px; }
  }
  @media (max-width: 380px){ .mp-company-meta{ display:none; } .mp-company{ padding:4px; } }
</style>
</head>
<body>

<header class="mp-header">
  <a href="/member/index.php" class="mp-brand">
    <span class="mp-brand-text"><?= htmlspecialchars($siteName) ?></span>
  </a>

  <div class="mp-company" title="<?= htmlspecialchars($siteName) ?>">
    <span class="mp-company-logo">
      <?php if ($_mpLogoSrc !== ''): ?>
        <img src="<?= htmlspecialchars($_mpLogoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>">
      <?php else: ?>
        <i style="color:var(--primary-color,#1a5f2a);font-size:.95rem;" class="lucide-icon" aria-hidden="true" data-lucide="landmark"></i>
      <?php endif; ?>
    </span>
    <div class="mp-company-meta">
      <div class="mp-company-name"><?= htmlspecialchars($memName) ?></div>
      <div class="mp-company-sub"><?= htmlspecialchars($memberT('सदस्य', 'Member')) ?> <i class="lucide-icon" aria-hidden="true" data-lucide="circle-check"></i></div>
    </div>
  </div>

  <form class="mp-search" method="get" action="/member/tracker.php" role="search">
    <i class="lucide-icon" aria-hidden="true" data-lucide="search"></i>
    <input type="search" name="q" placeholder="<?= htmlspecialchars($memberT('खोज्नुहोस् ...', 'Search ...')) ?>" autocomplete="off">
  </form>

  <div class="mp-actions">
    <a href="<?= htmlspecialchars($memberLangToggleUrl, ENT_QUOTES, 'UTF-8') ?>" class="mp-lang" title="<?= htmlspecialchars($memberT('भाषा', 'Language')) ?>"><?= htmlspecialchars($memberLangBadge) ?></a>
    <a href="/member/scan.php" class="mp-icon" title="<?= htmlspecialchars($memberT('QR स्क्यान','Scan QR')) ?>"><i class="fas fa-qrcode"></i></a>
    <a href="/member/notifications.php" class="mp-icon" title="<?= htmlspecialchars($memberT('सूचना', 'Notifications')) ?>"><i class="lucide-icon" aria-hidden="true" data-lucide="bell"></i><span class="mp-dot"></span></a>
    <a href="/member/profile.php" class="mp-avatar" title="<?= htmlspecialchars($memName) ?>">
      <?php if ($memberPhotoUrl !== ''): ?>
        <img src="<?= htmlspecialchars($memberPhotoUrl) ?>" alt="" onerror="this.parentNode.textContent=<?= json_encode(mb_strtoupper($memInitial)) ?>;">
      <?php else: ?>
        <?= htmlspecialchars(mb_strtoupper($memInitial)) ?>
      <?php endif; ?>
    </a>
    <a href="/member/logout.php" class="mp-icon" title="<?= htmlspecialchars($memberT('लगआउट', 'Logout')) ?>"><i class="fas fa-right-from-bracket"></i></a>
  </div>
</header>

<main>
