<?php
/**
 * ════════════════════════════════════════════════════════════
 * MEMBER PANEL — Unified Chrome (Topbar + Nav + Bell)
 * ════════════════════════════════════════════════════════════
 * Use गर्ने तरिका (हरेक member/*.php मा):
 *
 *   require_once __DIR__ . '/_bootstrap.php';
 *   requireMemberLogin();
 *   memberSecurityHeaders();
 *   $mem = currentMember();
 *
 *   // ... page specific PHP ...
 *
 *   $pageTitle = 'Profile — ' . SITE_NAME;
 *   $extraHead = '<style>...page-specific css...</style>';
 *   require __DIR__ . '/includes/chrome.php';   // emits <head>, <body>, topbar, nav
 *
 *   // ... page body ...
 *
 *   require __DIR__ . '/includes/chrome-foot.php';  // closes container, body, html
 * ════════════════════════════════════════════════════════════
 */

if (!defined('SITE_URL')) require_once __DIR__ . '/../../includes/config.php';
if (!defined('COOP_VAPID_PUBLIC_KEY') && is_file(__DIR__ . '/../../includes/push-vapid-config.php')) {
    require_once __DIR__ . '/../../includes/push-vapid-config.php';
}
if (!isset($mem) && function_exists('currentMember')) $mem = currentMember();

$_siteUrl     = SITE_URL;
$_siteName    = function_exists('getSetting') ? getSetting('site_name', 'आकाश सहकारी') : 'आकाश सहकारी';
$_pwaAppName  = function_exists('getSetting') ? trim((string) getSetting('pwa_app_name', ''))  : '';
if ($_pwaAppName  === '') $_pwaAppName  = $_siteName;
$_pwaShortName = function_exists('getSetting') ? trim((string) getSetting('pwa_short_name', '')) : '';
if ($_pwaShortName === '') $_pwaShortName = $_siteName;
$_logoPath = function_exists('getLocalizedLogoPath')
    ? trim((string) getLocalizedLogoPath('assets/images/logo.png'))
    : (function_exists('getSetting')
        ? trim((string) getSetting('site_logo', getSetting('logo', 'assets/images/logo.png')))
        : 'assets/images/logo.png');
$_memName  = $mem['name'] ?? 'Member';
$_memAvatar = trim((string)($mem['avatar_url'] ?? ''));
$_memId    = (int)($mem['id'] ?? 0);
$_hasInfoRoom = !empty($mem['information_room_enabled']);

// Topbar: KYC photo via SSOT (kyc_application_id → sadasyata); no email/mobile soft match
if ($_memAvatar === '') {
    try {
        $_dbA = getDB();
        if ($_dbA instanceof PDO) {
            $_kycA = function_exists('memberSsotLoadLinkedKyc')
                ? memberSsotLoadLinkedKyc($_dbA, $mem)
                : null;
            if (is_array($_kycA)) {
                $_photo = trim((string)($_kycA['photo'] ?? ''));
                if ($_photo !== '') {
                    $_memAvatar = $_photo;
                }
                if (empty($mem['kyc_application_id']) && !empty($_kycA['id']) && $_memId > 0) {
                    $_dbA->prepare('UPDATE members SET kyc_application_id=? WHERE id=? AND (kyc_application_id IS NULL OR kyc_application_id = 0)')
                        ->execute([(int)$_kycA['id'], $_memId]);
                    $mem['kyc_application_id'] = (int)$_kycA['id'];
                }
            }
        }
    } catch (\Throwable $ignored) {
    }
}

// साइट-रूट सापेक्ष पथलाई पूर्ण URL (member/ मा relative नभाँडियोस्)
if ($_memAvatar !== '' && !preg_match('#^(https?:)?//#i', $_memAvatar)) {
    $_memAvatar = rtrim($_siteUrl, '/') . '/' . ltrim($_memAvatar, '/');
}

if (!isset($pageTitle)) $pageTitle = 'Member — ' . $_siteName;
if (!isset($extraHead)) $extraHead = '';

$_lang = function_exists('getCurrentLang') ? getCurrentLang() : 'np';
$_isEn = ($_lang === 'en');
$_t = static function (string $np, string $en) use ($_isEn): string {
    return $_isEn ? $en : $np;
};
$_langQuery = $_GET;
$_langQuery['lang'] = $_isEn ? 'np' : 'en';
$_langToggleUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($_langQuery);
$_langBadge = $_isEn ? 'EN' : 'ने';

/* Auto-detect active nav from filename */
$_self  = basename($_SERVER['PHP_SELF'] ?? '');
$_applyFrameP = $_self === 'apply-frame.php' ? ($_GET['p'] ?? '') : '';
$_activeMap = [
    'index.php'          => 'dashboard',
    ''                   => 'dashboard',
    'notifications.php'  => 'notifications',
    'id-card.php'        => 'idcard',
    'tracker.php'        => 'tracker',
    'profile.php'        => 'profile',
    'welfare.php'        => 'welfare',
    'certificate.php'    => 'certificate',
    'scan.php'           => 'scan',
    'attend.php'         => 'attend',
    'service-request.php'=> 'service',
    'marketplace.php'    => 'marketplace',
    'appointment.php'    => 'apply-appointment',
    'loan-apply.php'     => 'apply-loan',
    'account-apply.php'  => 'apply-account',
    'grievance.php'      => 'apply-grievance',
    'digital-service.php'=> 'apply-digital',
    'information-room.php' => 'info-room',
    'information-room-view.php' => 'info-room',
];
if (isset($_activeMap[$_self])) {
    $_active = $_activeMap[$_self];
} elseif ($_applyFrameP === 'digital') {
    $_active = 'apply-digital';
} else {
    $_active = '';
}

/* Notifications for bell */
$_unread = function_exists('getMemberUnreadCount') ? getMemberUnreadCount($_memId) : 0;
$_bellNotifs = [];
if ($_memId) {
    try {
        $_db = getDB();
        $_st = $_db->prepare("SELECT * FROM member_notifications WHERE member_id=? ORDER BY created_at DESC LIMIT 5");
        $_st->execute([$_memId]);
        $_bellNotifs = $_st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $_bellNotifs = []; }
}

/* ID Card link सधैं देखाउने — generate नभएको भए id-card.php भित्रै "Pending" screen देखाउँछ */
$_hasIdCard = true;

/* ─────────────────────────────────────────────────────────────
   Election nav state
     - 'none'       : कुनै published निर्वाचन छैन वा उम्मेदवार छैन → nav link छैन
     - 'candidates' : चक्र प्रकाशित + उम्मेदवार छन्, तर मतदान अहिले बन्द → 'उम्मेदवारहरू' (users icon)
     - 'voting'     : मतदान खुला छ → 'मतदान' (check-to-slot icon)
   ───────────────────────────────────────────────────────────── */
$_electionState = 'none';
$_electionCycleId = 0;
try {
    $_dbE = function_exists('getDB') ? getDB() : null;
    if ($_dbE) {
        if (file_exists(__DIR__ . '/../../includes/election-tables.php')) {
            require_once __DIR__ . '/../../includes/election-tables.php';
            if (function_exists('ensureElectionTables'))       ensureElectionTables($_dbE);
            if (function_exists('ensureElectionVotingTables')) ensureElectionVotingTables($_dbE);
        }
        $_eRows = $_dbE->query("SELECT id, voting_enabled, vote_start_at, vote_end_at, is_published FROM election_cycles WHERE is_published=1 ORDER BY voting_enabled DESC, sort_order ASC, id DESC LIMIT 30")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $_eCy = function_exists('electionPickDefaultPublicCycle')
            ? electionPickDefaultPublicCycle($_eRows)
            : ($_eRows[0] ?? null);
        if ($_eCy) {
            $_eCs = $_dbE->prepare("SELECT COUNT(*) FROM election_candidates WHERE cycle_id=? AND is_active=1");
            $_eCs->execute([(int)$_eCy['id']]);
            $_eCandCount = (int)$_eCs->fetchColumn();
            if ($_eCandCount > 0) {
                $_electionCycleId = (int)$_eCy['id'];
                $_isOpen = function_exists('isElectionVotingOpen') ? isElectionVotingOpen($_eCy) : false;
                $_electionState = $_isOpen ? 'voting' : 'candidates';
            }
        }
    }
} catch (\Throwable $_eIgnore) {
    $_electionState = 'none';
}
?>
<!DOCTYPE html>
<html lang="<?php echo $_isEn ? 'en' : 'ne'; ?>" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?php echo htmlspecialchars($pageTitle); ?></title>
<?php
if (function_exists('coopThemeLink')) {
    coopThemeLink('assets/css/member-bs-compat.css');
    coopThemeLink('assets/css/app-core.css');
} else {
    echo '<link rel="stylesheet" href="../assets/css/member-bs-compat.css">' . "\n";
    echo '<link rel="stylesheet" href="../assets/css/app-core.css">' . "\n";
}
?>
<!-- Member portal styles come from coopThemeHeadAssets('member') — do not double-link app-member.css -->
<!-- Bell / apply-menu CSS: assets/css/member-shell-polish.css (member-late-bundle) -->

<?php if (function_exists('coopThemeHeadAssets')) { coopThemeHeadAssets('member'); } ?>
<?php echo $extraHead; ?>
<meta name="pwa-app-name"   content="<?php echo htmlspecialchars($_pwaAppName,   ENT_QUOTES, 'UTF-8'); ?>">
<meta name="pwa-short-name" content="<?php echo htmlspecialchars($_pwaShortName, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="manifest" href="<?php echo htmlspecialchars(rtrim((string)$_siteUrl, '/') . '/manifest.php', ENT_QUOTES, 'UTF-8'); ?>">
<?php if (function_exists('coopThemeColorMeta')) { coopThemeColorMeta(); } else { ?>
<meta name="theme-color" content="#1a5f2a">
<?php } ?>
<meta name="apple-mobile-web-app-capable" content="yes">
<?php
if (!function_exists('getPwaIconPublicUrl') && is_file(__DIR__ . '/../../includes/pwa-icons.php')) {
    require_once __DIR__ . '/../../includes/pwa-icons.php';
}
$_pwaApple = function_exists('getPwaIconPublicUrl')
    ? getPwaIconPublicUrl(180, false)
    : (rtrim((string) $_siteUrl, '/') . '/assets/images/icon-192x192.png');
?>
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($_pwaApple, ENT_QUOTES, 'UTF-8'); ?>">
<link rel="icon" href="<?php echo htmlspecialchars(function_exists('getPwaIconPublicUrl') ? getPwaIconPublicUrl(192, false) : $_pwaApple, ENT_QUOTES, 'UTF-8'); ?>" type="image/png" sizes="192x192">
<link rel="stylesheet" href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>assets/css/nepali.datepicker.min.css">
<meta name="vapid-public-key" content="<?php echo htmlspecialchars((string) COOP_VAPID_PUBLIC_KEY, ENT_QUOTES, 'UTF-8'); ?>">
<script>if(window.matchMedia('(display-mode:standalone)').matches||navigator.standalone)document.documentElement.classList.add('pwa-standalone');</script>
<script src="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>assets/js/coop-mobile.js?v=6.9" defer></script>
<script src="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>assets/js/pwa-register.js?v=3.3" defer></script>
</head>
<body class="mem-wrapper">
<a class="skip-link" href="#main-content"><?php echo $_t('मुख्य सामग्रीमा जानुहोस्', 'Skip to content'); ?></a>

<!-- ══ Offline Banner ══ -->
<div id="coopOfflineBanner" role="alert" aria-live="polite">
  <span>
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2.2" stroke-linecap="round" style="vertical-align:-2px;margin-right:6px;">
      <line x1="1" y1="1" x2="23" y2="23"/>
      <path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/>
      <path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/>
      <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
      <line x1="12" y1="20" x2="12.01" y2="20"/>
    </svg>
    अफलाइन मोड — Cached data हेर्दै हुनुहुन्छ
  </span>
  <button type="button" onclick="window.location.reload()"
          style="background:rgba(255,255,255,.18);border:none;color:#fff;
                 border-radius:6px;padding:4px 10px;font-size:.78rem;
                 font-weight:700;cursor:pointer;">फेरि प्रयास</button>
</div>

<!-- ══ Unified Topbar ══ -->
<div class="mem-topbar">
    <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/" class="mem-topbar-brand <?php echo !empty($_logoPath) ? 'has-logo' : 'no-logo'; ?>">
        <?php if ($_logoPath): ?>
        <img src="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8') . htmlspecialchars($_logoPath); ?>" alt="Logo">
        <?php else: ?>
        <div class="mem-logo-fallback"><i class="lucide-icon" data-lucide="leaf" aria-hidden="true"></i></div>
        <div class="mem-brand-text">
            <span class="mem-brand-name"><?php echo htmlspecialchars($_siteName); ?></span>
            <span class="mem-brand-sub"><?php echo $_t('सदस्य पोर्टल', 'MEMBER PORTAL'); ?></span>
        </div>
        <?php endif; ?>
    </a>
    <div class="mem-topbar-right">

        <!-- PWA Install -->
        <a href="#" onclick="event.preventDefault();if(typeof pwaTriggerInstall==='function')pwaTriggerInstall();"
           class="pwa-install-btn mem-pwa-btn"
           title="<?php echo $_t('App Install गर्नुहोस्', 'Install App'); ?>">
            <i class="lucide-icon" data-lucide="smartphone" aria-hidden="true"></i>
        </a>

        <!-- Push Notification Enable Button -->
        <button type="button" id="pushEnableBtn"
                class="mem-pwa-btn"
                title="<?php echo $_t('Push Notification सक्षम गर्नुहोस्', 'Enable Push Notifications'); ?>"
                aria-label="<?php echo $_t('Push Notification सक्षम गर्नुहोस्', 'Enable Push Notifications'); ?>"
                style="display:none;"
                onclick="coopSubscribePush()">
            <i class="lucide-icon" data-lucide="bell-off" aria-hidden="true" id="pushBellIcon" style="color:#f59e0b;"></i>
        </button>

        <!-- Bell -->
        <div class="bell-wrap">
            <a class="mem-bell-btn mem-lang-btn" href="<?php echo htmlspecialchars($_langToggleUrl, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo $_t('भाषा परिवर्तन', 'Switch Language'); ?>" aria-label="<?php echo $_t('भाषा परिवर्तन', 'Switch Language'); ?>">
                <small class="mem-lang-code"><?php echo htmlspecialchars($_langBadge); ?></small>
            </a>
            <button type="button" class="mem-bell-btn" id="bellBtn" title="<?php echo $_t('सूचनाहरू', 'Notifications'); ?>" aria-label="<?php echo $_t('सूचनाहरू', 'Notifications'); ?>" aria-haspopup="true" aria-expanded="false">
                <i class="lucide-icon" data-lucide="bell" aria-hidden="true"></i>
                <?php if ($_unread > 0): ?><span class="mem-notif-dot"><?php echo $_unread > 9 ? '9+' : $_unread; ?></span><?php endif; ?>
            </button>
            <div class="bell-dropdown" id="bellDropdown">
                <div class="bell-dd-head">
                    <span class="title"><i class="lucide-icon" data-lucide="bell" aria-hidden="true"></i> <?php echo $_t('सूचनाहरू', 'Notifications'); ?></span>
                    <?php if ($_unread > 0): ?><span class="badge"><?php echo $_unread; ?> <?php echo $_t('नयाँ', 'new'); ?></span><?php endif; ?>
                </div>
                <?php if (empty($_bellNotifs)): ?>
                <div class="bell-dd-empty">
                    <i class="lucide-icon" data-lucide="bell-off" aria-hidden="true" style="font-size:1.4rem;display:block;margin-bottom:6px;opacity:.5;"></i>
                    <?php echo $_t('कुनै सूचना छैन।', 'No notifications.'); ?>
                </div>
                <?php else:
                    $_iconMap = [
                        'success'=>['circle-check','var(--primary-color)','color-mix(in srgb, var(--primary-color) 12%, white)'],
                        'error'  =>['circle-x','var(--secondary-color)','color-mix(in srgb, var(--secondary-color) 14%, white)'],
                        'warning'=>['triangle-alert','var(--secondary-color)','color-mix(in srgb, var(--secondary-color) 12%, white)'],
                        'info'   =>['info','var(--accent-color,#17a2b8)','color-mix(in srgb, var(--accent-color,#17a2b8) 12%, white)'],
                    ];
                    foreach ($_bellNotifs as $_n):
                        $_ic = $_iconMap[$_n['type']] ?? $_iconMap['info'];
                ?>
                <div class="bell-dd-item <?php echo !$_n['is_read'] ? 'unread' : ''; ?>" onclick="window.location='<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/notifications.php'">
                    <div class="bell-dd-icon" style="background:<?php echo $_ic[2]; ?>;color:<?php echo $_ic[1]; ?>;">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="<?php echo htmlspecialchars($_ic[0], ENT_QUOTES, 'UTF-8'); ?>"></i>
                    </div>
                    <div class="bell-dd-body">
                        <div class="bell-dd-title"><?php echo htmlspecialchars($_n['title']); ?></div>
                        <div class="bell-dd-msg"><?php echo htmlspecialchars($_n['message'] ?? ''); ?></div>
                        <div class="bell-dd-time"><?php echo function_exists('formatNepaliDate') ? formatNepaliDate($_n['created_at'], true) : $_n['created_at']; ?></div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
                <div class="bell-dd-foot">
                    <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/notifications.php"><?php echo $_t('सबै सूचना हेर्नुहोस्', 'View all notifications'); ?> →</a>
                </div>
            </div>
        </div>

        <?php if ($_memAvatar !== ''): ?>
        <div class="mem-topbar-avatar-stack">
            <img src="<?php echo htmlspecialchars($_memAvatar); ?>"
                 class="mem-topbar-avatar mem-topbar-avatar-img" alt=""
                 onerror="this.style.display='none';var f=this.nextElementSibling;if(f)f.classList.add('mem-topbar-avatar-fallback--show');">
            <div class="mem-topbar-avatar mem-topbar-avatar-fallback" aria-hidden="true"><?php echo htmlspecialchars(mb_substr($_memName, 0, 1)); ?></div>
        </div>
        <?php else: ?>
        <div class="mem-topbar-avatar mem-topbar-avatar-fallback mem-topbar-avatar-fallback--show" aria-hidden="true">
            <?php echo htmlspecialchars(mb_substr($_memName, 0, 1)); ?>
        </div>
        <?php endif; ?>
        <span class="mem-topbar-name"><?php echo htmlspecialchars($_memName); ?></span>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/logout.php" class="mem-topbar-btn mem-topbar-logout">
            <i class="lucide-icon" data-lucide="log-out" aria-hidden="true"></i><span class="mem-logout-text"> <?php echo $_t('लगआउट', 'Logout'); ?></span>
        </a>
    </div>
</div>

<div class="mem-container">

    <!-- ══ Unified Nav ══ -->
    <nav class="mem-nav">
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/" class="mem-nav-item <?php echo $_active==='dashboard'?'active':''; ?>"><i class="lucide-icon" data-lucide="house" aria-hidden="true"></i><?php echo $_t('ड्यासबोर्ड', 'Dashboard'); ?></a>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/tracker.php" class="mem-nav-item <?php echo $_active==='tracker'?'active':''; ?>"><i class="lucide-icon" data-lucide="search" aria-hidden="true"></i><?php echo $_t('ट्र्याकर', 'Tracker'); ?></a>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/notifications.php" class="mem-nav-item mem-nav-item-rel <?php echo $_active==='notifications'?'active':''; ?>">
            <i class="lucide-icon" data-lucide="bell" aria-hidden="true"></i><?php echo $_t('सूचनाहरू', 'Notifications'); ?>
            <?php if ($_unread > 0): ?><span class="mem-notif-dot mem-notif-dot-inline"><?php echo $_unread; ?></span><?php endif; ?>
        </a>
        <?php if ($_hasIdCard): ?>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/id-card.php" class="mem-nav-item <?php echo $_active==='idcard'?'active':''; ?>"><i class="lucide-icon" data-lucide="id-card" aria-hidden="true"></i><?php echo $_t('परिचयपत्र', 'ID Card'); ?></a>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/welfare.php" class="mem-nav-item <?php echo $_active==='welfare'?'active':''; ?>"><i class="lucide-icon" data-lucide="heart-pulse" aria-hidden="true"></i><?php echo $_t('कल्याण दाबी', 'Welfare Claim'); ?></a>
        <?php if (!empty($_hasInfoRoom)): ?>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/information-room.php" class="mem-nav-item <?php echo $_active==='info-room'?'active':''; ?>"><i class="lucide-icon" data-lucide="vault" aria-hidden="true"></i><?php echo $_t('Information Room', 'Information Room'); ?></a>
        <?php endif; ?>
        <?php if ($_electionState === 'voting'): ?>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/election-vote.php" class="mem-nav-item mem-nav-vote-live <?php echo $_active==='election'?'active':''; ?>"><i class="lucide-icon" data-lucide="vote" aria-hidden="true"></i><?php echo $_t('मतदान', 'Vote'); ?> <span class="mem-vote-live-dot" aria-hidden="true"></span></a>
        <?php elseif ($_electionState === 'candidates'): ?>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/election-vote.php" class="mem-nav-item <?php echo $_active==='election'?'active':''; ?>"><i class="lucide-icon" data-lucide="users" aria-hidden="true"></i><?php echo $_t('उम्मेदवारहरू', 'Candidates'); ?></a>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/scan.php" class="mem-nav-item <?php echo $_active==='scan'?'active':''; ?>"><i class="lucide-icon" data-lucide="qr-code" aria-hidden="true"></i><?php echo $_t('QR स्क्यान', 'QR Scan'); ?></a>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/attend.php" class="mem-nav-item <?php echo $_active==='attend'?'active':''; ?>"><i class="lucide-icon" data-lucide="calendar-check" aria-hidden="true"></i><?php echo $_t('उपस्थिति', 'Attendance'); ?></a>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/marketplace.php" class="mem-nav-item <?php echo $_active==='marketplace'?'active':''; ?>"><i class="lucide-icon" data-lucide="store" aria-hidden="true"></i><?php echo $_t('बजार / सीप', 'Market / Skills'); ?></a>
        <?php
        $_applyKeys = ['service', 'apply-appointment', 'apply-loan', 'apply-account', 'apply-digital', 'apply-grievance'];
        $_applyOpen = in_array($_active, $_applyKeys, true);
        ?>
        <div class="mem-nav-apply-wrap<?php echo $_applyOpen ? ' open' : ''; ?>" id="memNavApplyWrap">
            <button type="button"
                    class="mem-nav-item mem-nav-apply-toggle<?php echo $_applyOpen ? ' active' : ''; ?>"
                    id="memNavApplyToggle"
                    aria-expanded="<?php echo $_applyOpen ? 'true' : 'false'; ?>"
                    aria-controls="mem-nav-apply-panel">
                <i class="lucide-icon" data-lucide="file-pen" aria-hidden="true"></i><?php echo $_t('आवेदन / सेवा', 'Apply / Services'); ?>
                <span class="mem-nav-apply-chevron" aria-hidden="true">▾</span>
            </button>
            <div class="mem-nav-apply-panel" id="mem-nav-apply-panel" role="group" aria-label="<?php echo htmlspecialchars($_t('आवेदन / सेवा', 'Apply / Services'), ENT_QUOTES, 'UTF-8'); ?>">
                <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/service-request.php" class="mem-nav-item <?php echo $_active==='service'?'active':''; ?>"><i class="lucide-icon" data-lucide="bell" aria-hidden="true"></i><?php echo $_t('सेवा अनुरोध', 'Service Request'); ?></a>
                <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/appointment.php" class="mem-nav-item <?php echo $_active==='apply-appointment'?'active':''; ?>"><i class="lucide-icon" data-lucide="calendar-check" aria-hidden="true"></i><?php echo $_t('भेटघाट', 'Appointment'); ?></a>
                <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/loan-apply.php" class="mem-nav-item <?php echo $_active==='apply-loan'?'active':''; ?>"><i class="lucide-icon" data-lucide="hand-coins" aria-hidden="true"></i><?php echo $_t('ऋण आवेदन', 'Loan Apply'); ?></a>
                <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/account-apply.php" class="mem-nav-item <?php echo $_active==='apply-account'?'active':''; ?>"><i class="lucide-icon" data-lucide="landmark" aria-hidden="true"></i><?php echo $_t('खाता खोल्ने', 'Open Account'); ?></a>
                <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/digital-service.php" class="mem-nav-item <?php echo $_active==='apply-digital'?'active':''; ?>"><i class="lucide-icon" data-lucide="laptop" aria-hidden="true"></i><?php echo $_t('डिजिटल सेवा', 'Digital Service'); ?></a>
                <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/grievance.php" class="mem-nav-item <?php echo $_active==='apply-grievance'?'active':''; ?>"><i class="lucide-icon" data-lucide="message-circle" aria-hidden="true"></i><?php echo $_t('गुनासो', 'Grievance'); ?></a>
            </div>
        </div>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/certificate.php" class="mem-nav-item <?php echo $_active==='certificate'?'active':''; ?>"><i class="lucide-icon" data-lucide="badge" aria-hidden="true"></i><?php echo $_t('प्रमाणपत्र', 'Certificates'); ?></a>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/profile.php" class="mem-nav-item <?php echo $_active==='profile'?'active':''; ?>"><i class="lucide-icon" data-lucide="circle-user" aria-hidden="true"></i><?php echo $_t('प्रोफाइल', 'Profile'); ?></a>
        <a href="<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>" class="mem-nav-item" target="_blank" rel="noopener noreferrer"><i class="lucide-icon" data-lucide="globe" aria-hidden="true"></i><?php echo $_t('मुख्य साइट', 'Main Site'); ?></a>
    </nav>

<script>
/* ── Offline Banner + localStorage member cache ──────────────────
   Saves member identity so /member/offline.php can display it
   when served from the service worker cache while offline.
   ──────────────────────────────────────────────────────────────── */
(function () {
  var banner = document.getElementById('coopOfflineBanner');

  function showBanner() {
    if (banner) {
      banner.style.display = 'flex';
      document.body.style.paddingTop = (banner.offsetHeight || 40) + 'px';
    }
    document.body.classList.add('is-offline');
  }
  function hideBanner() {
    if (banner) { banner.style.display = 'none'; document.body.style.paddingTop = ''; }
    document.body.classList.remove('is-offline');
  }

  if (!navigator.onLine) { showBanner(); }
  window.addEventListener('offline', showBanner);
  window.addEventListener('online', function () {
    hideBanner();
    window.location.reload();
  });

  /* Persist key member data for offline.php to read */
  try {
    var n = <?php echo json_encode((string)$_memName, JSON_UNESCAPED_UNICODE); ?>;
    var u = <?php echo (int)$_unread; ?>;
    var s = <?php echo json_encode((string)$_siteName, JSON_UNESCAPED_UNICODE); ?>;
    if (n) localStorage.setItem('coop_mem_name',     n);
    if (s) localStorage.setItem('coop_mem_site',     s);
    localStorage.setItem('coop_mem_unread',   String(u));
    localStorage.setItem('coop_mem_ts',       String(Date.now()));
    localStorage.setItem('coop_mem_last_url', window.location.href);
  } catch (e) { /* private browsing mode — skip silently */ }
})();
</script>

<!-- ══ Web Push Subscription JS ═══════════════════════════════════ -->
<script>
(function () {
  'use strict';

  /* Only run if Push + SW supported */
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

  var VAPID_KEY = document.querySelector('meta[name="vapid-public-key"]')
                    ? document.querySelector('meta[name="vapid-public-key"]').content
                    : '';
  if (!VAPID_KEY) return;

  var MEMBER_PUSH_CSRF = <?php echo json_encode(generateCSRFToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  var btn      = document.getElementById('pushEnableBtn');
  var icon     = document.getElementById('pushBellIcon');
  var STORAGE  = 'coop_push_subscribed';

  /* ── Helpers ──────────────────────────────────────────────────── */
  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var b64     = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw     = atob(b64);
    var arr     = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  function setSubscribed(yes) {
    try { localStorage.setItem(STORAGE, yes ? '1' : '0'); } catch (_) {}
    if (!btn) return;
    if (icon) {
      icon.className = 'lucide-icon';
      icon.setAttribute('data-lucide', yes ? 'bell' : 'bell-off');
      icon.setAttribute('aria-hidden', 'true');
      icon.innerHTML = '';
      if (window.lucide && typeof lucide.createIcons === 'function') {
        lucide.createIcons({ nodes: [icon] });
      }
      icon.style.color = yes ? 'var(--primary-color, #1a5f2a)' : '#f59e0b';
    }
    btn.title = yes ? 'Push Notification सक्षम छ' : 'Push Notification सक्षम गर्नुहोस्';
  }

  /* ── Save subscription to server ─────────────────────────────── */
  function saveToServer(subscription) {
    var obj = subscription.toJSON();
    fetch('<?php echo htmlspecialchars($_siteUrl, ENT_QUOTES, 'UTF-8'); ?>member/push-subscribe.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        endpoint:   obj.endpoint,
        keys:       { p256dh: obj.keys.p256dh, auth: obj.keys.auth },
        csrf_token: MEMBER_PUSH_CSRF,
      }),
      credentials: 'same-origin',
    }).catch(function () {});
  }

  /* ── Subscribe ────────────────────────────────────────────────── */
  window.coopSubscribePush = function () {
    if (!('Notification' in window)) return;
    if (Notification.permission === 'denied') {
      alert('Browser settings मा Notification permission allow गर्नुहोस् र पेज reload गर्नुहोस्।');
      return;
    }
    navigator.serviceWorker.ready.then(function (reg) {
      return reg.pushManager.subscribe({
        userVisibleOnly:      true,
        applicationServerKey: urlBase64ToUint8Array(VAPID_KEY),
      });
    }).then(function (sub) {
      setSubscribed(true);
      saveToServer(sub);
      if (btn) btn.style.display = 'none';   /* hide after success */
      /* Quick in-page toast */
      if (typeof showCoopToast === 'function') {
        showCoopToast('Push Notification सक्षम भयो!', 'success');
      }
    }).catch(function (err) {
      console.warn('Push subscribe failed:', err);
    });
  };

  /* ── Init: show button only when notification not yet granted ─── */
  navigator.serviceWorker.ready.then(function (reg) {
    return reg.pushManager.getSubscription();
  }).then(function (existing) {
    var alreadyGranted = Notification.permission === 'granted' && existing;
    var denied         = Notification.permission === 'denied';

    setSubscribed(!!alreadyGranted);

    if (denied || alreadyGranted) {
      /* Already set up or blocked — keep button hidden */
      if (existing) saveToServer(existing);   /* re-sync in case of new device */
    } else {
      /* Not yet subscribed — show invite button */
      if (btn) btn.style.display = '';
    }
  }).catch(function () {});

})();
</script>
<script>
(function () {
  var wrap = document.getElementById('memNavApplyWrap');
  var btn = document.getElementById('memNavApplyToggle');
  var panel = document.getElementById('mem-nav-apply-panel');
  if (!wrap || !btn || !panel) return;

  function setOpen(open) {
    wrap.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    setOpen(!wrap.classList.contains('open'));
  });
  document.addEventListener('click', function (e) {
    if (!wrap.contains(e.target)) setOpen(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') setOpen(false);
  });
})();
</script>

<main id="main-content" class="mem-main-content" tabindex="-1">
