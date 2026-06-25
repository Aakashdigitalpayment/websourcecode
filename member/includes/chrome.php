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

// Topbar: KYC photo जोड्ने (id लिंक वा इमेल/मोबाइल मिलान — profile.php जस्तै)
if ($_memAvatar === '') {
    try {
        $_dbA = getDB();
        if ($_dbA) {
            if (!empty($mem['kyc_application_id'])) {
                $_stA = $_dbA->prepare('SELECT photo FROM kyc_applications WHERE id=? LIMIT 1');
                $_stA->execute([(int)$mem['kyc_application_id']]);
                $_photo = trim((string)($_stA->fetchColumn() ?: ''));
                if ($_photo !== '') {
                    $_memAvatar = $_photo;
                }
            }
            if ($_memAvatar === '') {
                $_kw = [];
                $_kp = [];
                $_em = strtolower(trim((string)($mem['email'] ?? '')));
                $_ph = preg_replace('/[^0-9]/', '', (string)($mem['phone'] ?? ''));
                if ($_em !== '') {
                    $_kw[] = 'LOWER(email)=?';
                    $_kp[] = $_em;
                }
                if ($_ph !== '') {
                    $_kw[] = 'mobile=?';
                    $_kp[] = $_ph;
                }
                if ($_kw !== []) {
                    $_sql = 'SELECT photo FROM kyc_applications WHERE (' . implode(' OR ', $_kw) . ')
                            AND TRIM(IFNULL(photo,\'\')) != \'\' ORDER BY id DESC LIMIT 1';
                    $_stA = $_dbA->prepare($_sql);
                    $_stA->execute($_kp);
                    $_photo = trim((string)($_stA->fetchColumn() ?: ''));
                    if ($_photo !== '') {
                        $_memAvatar = $_photo;
                    }
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
    'appointment.php'    => 'apply-appointment',
    'loan-apply.php'     => 'apply-loan',
    'account-apply.php'  => 'apply-account',
    'grievance.php'      => 'apply-grievance',
    'digital-service.php'=> 'apply-digital',
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
        $_eCy = $_dbE->query("SELECT id, voting_enabled, vote_start_at, vote_end_at FROM election_cycles WHERE is_published=1 ORDER BY voting_enabled DESC, sort_order ASC, id DESC LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC) ?: null;
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
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo htmlspecialchars($pageTitle); ?></title>
<!-- Bootstrap utility compatibility layer (self-hosted, replaces CDN Bootstrap) -->
<link rel="stylesheet" href="../assets/css/member-bs-compat.css?v=<?= filemtime(__DIR__."/../../assets/css/member-bs-compat.css") ?>">
<!-- Design tokens + base styles (core.css) must load before app-member.css -->
<link rel="stylesheet" href="../assets/css/app-core.css?v=<?= filemtime(__DIR__."/../../assets/css/app-core.css") ?>">
<!-- Member portal styles -->
<link rel="stylesheet" href="../assets/css/app-member.css?v=<?= filemtime(__DIR__."/../../assets/css/app-member.css") ?>">
<!-- Font Awesome (still needed for existing member page icons) -->

<?php if (function_exists('coopThemeHeadAssets')) { coopThemeHeadAssets('member'); } ?>
<style>
/* ── Member Bell Dropdown (unified) ── */
.bell-wrap{position:relative;}
.bell-dropdown{display:none;position:absolute;top:calc(100% + 8px);right:0;width:340px;max-height:460px;overflow-y:auto;background:white;border-radius:12px;box-shadow:0 10px 30px rgba(var(--primary-rgb,26,95,42),.18);z-index:1000;color:var(--text-color,#1f2937);}
.bell-dropdown.open{display:block;}
.bell-dd-head{padding:12px 14px;border-bottom:1px solid color-mix(in srgb, var(--primary-color) 14%, #e5e7eb);display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,var(--primary-color),var(--primary-light));color:var(--text-on-primary,white);border-radius:12px 12px 0 0;}
.bell-dd-head .title{font-weight:700;font-size:.9rem;}
.bell-dd-head .badge{background:var(--secondary-color);color:var(--text-on-secondary,var(--text-on-primary,white));font-size:.7rem;padding:2px 7px;border-radius:10px;font-weight:700;}
.bell-dd-empty{padding:30px 16px;text-align:center;color:var(--text-light,#6b7280);font-size:.85rem;}
.bell-dd-item{display:flex;gap:10px;padding:10px 14px;border-bottom:1px solid color-mix(in srgb, var(--primary-color) 10%, #f3f4f6);cursor:pointer;transition:background .15s;}
.bell-dd-item:hover{background:color-mix(in srgb, var(--primary-color) 8%, white);}
.bell-dd-item.unread{background:color-mix(in srgb, var(--primary-color) 12%, white);}
.bell-dd-icon{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.85rem;}
.bell-dd-body{flex:1;min-width:0;}
.bell-dd-title{font-weight:600;font-size:.82rem;color:var(--text-color,#111827);}
.bell-dd-msg{font-size:.74rem;color:var(--text-light,#6b7280);margin-top:2px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}
.bell-dd-time{font-size:.68rem;color:var(--text-muted,#9ca3af);margin-top:3px;}
.bell-dd-foot{padding:10px 14px;text-align:center;border-top:1px solid color-mix(in srgb, var(--primary-color) 14%, #e5e7eb);background:color-mix(in srgb, var(--primary-color) 8%, white);border-radius:0 0 12px 12px;}
.bell-dd-foot a{color:var(--primary-color);font-weight:600;font-size:.82rem;text-decoration:none;}
.mem-bell-btn{position:relative;background:none;border:0;cursor:pointer;color:inherit;padding:8px;border-radius:8px;transition:background .15s;}
.mem-bell-btn:hover{background:rgba(255,255,255,.15);}
.mem-bell-btn .mem-notif-dot{position:absolute;top:4px;right:4px;background:var(--secondary-color);color:var(--text-on-secondary,var(--text-on-primary,white));border-radius:10px;font-size:.62rem;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;}
.mem-lang-btn{text-decoration:none;display:inline-flex;align-items:center;justify-content:center;}
.mem-lang-code{font-size:11px;font-weight:800;line-height:1;}
.mem-nav-item-rel{position:relative;}
.mem-notif-dot-inline{position:static;margin-left:4px;}
.mem-nav-vote-live{position:relative;}
.mem-vote-live-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#16a34a;margin-left:6px;box-shadow:0 0 0 0 rgba(22,163,74,.55);animation:memVoteLivePulse 1.4s infinite;}
@keyframes memVoteLivePulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.55);}70%{box-shadow:0 0 0 8px rgba(22,163,74,0);}100%{box-shadow:0 0 0 0 rgba(22,163,74,0);}}
</style>
<?php echo $extraHead; ?>
<meta name="pwa-app-name"   content="<?php echo htmlspecialchars($_pwaAppName,   ENT_QUOTES, 'UTF-8'); ?>">
<meta name="pwa-short-name" content="<?php echo htmlspecialchars($_pwaShortName, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="vapid-public-key" content="BGBgAPEKj2nvCF8aAxIn1Vw1rMo_2YQKFsR2W2E-L38e1HDA8QLIzMgtjz9Kvze7-rfVzj8_c6Glrd-KEtgxDUo">
<script>if(window.matchMedia('(display-mode:standalone)').matches||navigator.standalone)document.documentElement.classList.add('pwa-standalone');</script>
<script src="<?php echo $_siteUrl; ?>assets/js/coop-mobile.js?v=6.5" defer></script>
<script src="<?php echo $_siteUrl; ?>assets/js/pwa-register.js" defer></script>
</head>
<body class="mem-wrapper">

<!-- ══ Offline Banner ══ -->
<div id="coopOfflineBanner" style="display:none;position:fixed;top:0;left:0;right:0;z-index:9999;
  background:#1a5f2a;color:#fff;padding:9px 16px;font-size:.82rem;font-weight:600;
  display:none;align-items:center;justify-content:space-between;gap:12px;
  box-shadow:0 2px 8px rgba(0,0,0,.22);" role="alert" aria-live="polite">
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
  <button onclick="window.location.reload()"
          style="background:rgba(255,255,255,.18);border:none;color:#fff;
                 border-radius:6px;padding:4px 10px;font-size:.78rem;
                 font-weight:700;cursor:pointer;">फेरि प्रयास</button>
</div>

<!-- ══ Unified Topbar ══ -->
<div class="mem-topbar">
    <a href="<?php echo $_siteUrl; ?>member/" class="mem-topbar-brand <?php echo !empty($_logoPath) ? 'has-logo' : 'no-logo'; ?>">
        <?php if ($_logoPath): ?>
        <img src="<?php echo $_siteUrl . htmlspecialchars($_logoPath); ?>" alt="Logo">
        <?php else: ?>
        <div class="mem-logo-fallback"><i class="fas fa-leaf"></i></div>
        <div class="mem-brand-text">
            <span class="mem-brand-name"><?php echo htmlspecialchars($_siteName); ?></span>
            <span class="mem-brand-sub"><?php echo $_t('सदस्य पोर्टल', 'MEMBER PORTAL'); ?></span>
        </div>
        <?php endif; ?>
    </a>
    <div class="mem-topbar-right">

        <!-- PWA Install -->
        <a href="javascript:void(0);" onclick="if(typeof pwaTriggerInstall==='function')pwaTriggerInstall();"
           class="pwa-install-btn mem-pwa-btn"
           title="<?php echo $_t('App Install गर्नुहोस्', 'Install App'); ?>">
            <i class="fas fa-mobile-screen-button"></i>
        </a>

        <!-- Push Notification Enable Button -->
        <button type="button" id="pushEnableBtn"
                class="mem-pwa-btn"
                title="<?php echo $_t('Push Notification सक्षम गर्नुहोस्', 'Enable Push Notifications'); ?>"
                style="display:none;"
                onclick="coopSubscribePush()">
            <i class="fas fa-bell-slash" id="pushBellIcon" style="color:#f59e0b;"></i>
        </button>

        <!-- Bell -->
        <div class="bell-wrap">
            <a class="mem-bell-btn mem-lang-btn" href="<?php echo htmlspecialchars($_langToggleUrl, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo $_t('भाषा परिवर्तन', 'Switch Language'); ?>">
                <small class="mem-lang-code"><?php echo htmlspecialchars($_langBadge); ?></small>
            </a>
            <button class="mem-bell-btn" id="bellBtn" title="<?php echo $_t('सूचनाहरू', 'Notifications'); ?>" type="button">
                <i class="fas fa-bell"></i>
                <?php if ($_unread > 0): ?><span class="mem-notif-dot"><?php echo $_unread > 9 ? '9+' : $_unread; ?></span><?php endif; ?>
            </button>
            <div class="bell-dropdown" id="bellDropdown">
                <div class="bell-dd-head">
                    <span class="title"><i class="fas fa-bell"></i> <?php echo $_t('सूचनाहरू', 'Notifications'); ?></span>
                    <?php if ($_unread > 0): ?><span class="badge"><?php echo $_unread; ?> <?php echo $_t('नयाँ', 'new'); ?></span><?php endif; ?>
                </div>
                <?php if (empty($_bellNotifs)): ?>
                <div class="bell-dd-empty">
                    <i class="fas fa-bell-slash" style="font-size:1.4rem;display:block;margin-bottom:6px;opacity:.5;"></i>
                    <?php echo $_t('कुनै सूचना छैन।', 'No notifications.'); ?>
                </div>
                <?php else:
                    $_iconMap = [
                        'success'=>['fas fa-circle-check','var(--primary-color)','color-mix(in srgb, var(--primary-color) 12%, white)'],
                        'error'  =>['fas fa-circle-xmark','var(--secondary-color)','color-mix(in srgb, var(--secondary-color) 14%, white)'],
                        'warning'=>['fas fa-triangle-exclamation','var(--secondary-color)','color-mix(in srgb, var(--secondary-color) 12%, white)'],
                        'info'   =>['fas fa-circle-info','var(--accent-color,#17a2b8)','color-mix(in srgb, var(--accent-color,#17a2b8) 12%, white)'],
                    ];
                    foreach ($_bellNotifs as $_n):
                        $_ic = $_iconMap[$_n['type']] ?? $_iconMap['info'];
                ?>
                <div class="bell-dd-item <?php echo !$_n['is_read'] ? 'unread' : ''; ?>" onclick="window.location='<?php echo $_siteUrl; ?>member/notifications.php'">
                    <div class="bell-dd-icon" style="background:<?php echo $_ic[2]; ?>;color:<?php echo $_ic[1]; ?>;">
                        <i class="<?php echo $_ic[0]; ?>"></i>
                    </div>
                    <div class="bell-dd-body">
                        <div class="bell-dd-title"><?php echo htmlspecialchars($_n['title']); ?></div>
                        <div class="bell-dd-msg"><?php echo htmlspecialchars($_n['message'] ?? ''); ?></div>
                        <div class="bell-dd-time"><?php echo function_exists('formatNepaliDate') ? formatNepaliDate($_n['created_at'], true) : $_n['created_at']; ?></div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
                <div class="bell-dd-foot">
                    <a href="<?php echo $_siteUrl; ?>member/notifications.php"><?php echo $_t('सबै सूचना हेर्नुहोस्', 'View all notifications'); ?> →</a>
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
        <a href="<?php echo $_siteUrl; ?>member/logout.php" class="mem-topbar-btn mem-topbar-logout">
            <i class="fas fa-sign-out-alt"></i><span class="mem-logout-text"> <?php echo $_t('लगआउट', 'Logout'); ?></span>
        </a>
    </div>
</div>

<div class="mem-container">

    <!-- ══ Unified Nav ══ -->
    <nav class="mem-nav">
        <a href="<?php echo $_siteUrl; ?>member/" class="mem-nav-item <?php echo $_active==='dashboard'?'active':''; ?>"><i class="fas fa-house"></i><?php echo $_t('ड्यासबोर्ड', 'Dashboard'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/tracker.php" class="mem-nav-item <?php echo $_active==='tracker'?'active':''; ?>"><i class="fas fa-magnifying-glass-chart"></i><?php echo $_t('ट्र्याकर', 'Tracker'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/notifications.php" class="mem-nav-item mem-nav-item-rel <?php echo $_active==='notifications'?'active':''; ?>">
            <i class="fas fa-bell"></i><?php echo $_t('सूचनाहरू', 'Notifications'); ?>
            <?php if ($_unread > 0): ?><span class="mem-notif-dot mem-notif-dot-inline"><?php echo $_unread; ?></span><?php endif; ?>
        </a>
        <?php if ($_hasIdCard): ?>
        <a href="<?php echo $_siteUrl; ?>member/id-card.php" class="mem-nav-item <?php echo $_active==='idcard'?'active':''; ?>"><i class="fas fa-id-card"></i><?php echo $_t('परिचयपत्र', 'ID Card'); ?></a>
        <?php endif; ?>
        <a href="<?php echo $_siteUrl; ?>member/welfare.php" class="mem-nav-item <?php echo $_active==='welfare'?'active':''; ?>"><i class="fas fa-heart-pulse"></i><?php echo $_t('कल्याण दाबी', 'Welfare Claim'); ?></a>
        <?php if ($_electionState === 'voting'): ?>
        <a href="<?php echo $_siteUrl; ?>member/election-vote.php" class="mem-nav-item mem-nav-vote-live <?php echo $_active==='election'?'active':''; ?>"><i class="fas fa-check-to-slot"></i><?php echo $_t('मतदान', 'Vote'); ?> <span class="mem-vote-live-dot" aria-hidden="true"></span></a>
        <?php elseif ($_electionState === 'candidates'): ?>
        <a href="<?php echo $_siteUrl; ?>member/election-vote.php" class="mem-nav-item <?php echo $_active==='election'?'active':''; ?>"><i class="fas fa-users"></i><?php echo $_t('उम्मेदवारहरू', 'Candidates'); ?></a>
        <?php endif; ?>
        <a href="<?php echo $_siteUrl; ?>member/scan.php" class="mem-nav-item <?php echo $_active==='scan'?'active':''; ?>"><i class="fas fa-qrcode"></i><?php echo $_t('QR स्क्यान', 'QR Scan'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/attend.php" class="mem-nav-item <?php echo $_active==='attend'?'active':''; ?>"><i class="fas fa-calendar-check"></i><?php echo $_t('उपस्थिति', 'Attendance'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/service-request.php" class="mem-nav-item <?php echo $_active==='service'?'active':''; ?>"><i class="fas fa-concierge-bell"></i><?php echo $_t('सेवा अनुरोध', 'Service Request'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/appointment.php" class="mem-nav-item <?php echo $_active==='apply-appointment'?'active':''; ?>"><i class="fas fa-calendar-check"></i><?php echo $_t('भेटघाट', 'Appointment'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/loan-apply.php" class="mem-nav-item <?php echo $_active==='apply-loan'?'active':''; ?>"><i class="fas fa-hand-holding-dollar"></i><?php echo $_t('ऋण आवेदन', 'Loan Apply'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/account-apply.php" class="mem-nav-item <?php echo $_active==='apply-account'?'active':''; ?>"><i class="fas fa-landmark"></i><?php echo $_t('खाता खोल्ने', 'Open Account'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/digital-service.php" class="mem-nav-item <?php echo $_active==='apply-digital'?'active':''; ?>"><i class="fas fa-laptop"></i><?php echo $_t('डिजिटल सेवा', 'Digital Service'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/grievance.php" class="mem-nav-item <?php echo $_active==='apply-grievance'?'active':''; ?>"><i class="fas fa-comment-dots"></i><?php echo $_t('गुनासो', 'Grievance'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/certificate.php" class="mem-nav-item <?php echo $_active==='certificate'?'active':''; ?>"><i class="fas fa-certificate"></i><?php echo $_t('प्रमाणपत्र', 'Certificates'); ?></a>
        <a href="<?php echo $_siteUrl; ?>member/profile.php" class="mem-nav-item <?php echo $_active==='profile'?'active':''; ?>"><i class="fas fa-user-circle"></i><?php echo $_t('प्रोफाइल', 'Profile'); ?></a>
        <a href="<?php echo $_siteUrl; ?>" class="mem-nav-item" target="_blank" rel="noopener"><i class="fas fa-globe"></i><?php echo $_t('मुख्य साइट', 'Main Site'); ?></a>
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
    if (yes) {
      icon.className  = 'fas fa-bell';
      icon.style.color = 'var(--primary-color, #1a5f2a)';
      btn.title = 'Push Notification सक्षम छ';
    } else {
      icon.className  = 'fas fa-bell-slash';
      icon.style.color = '#f59e0b';
      btn.title = 'Push Notification सक्षम गर्नुहोस्';
    }
  }

  /* ── Save subscription to server ─────────────────────────────── */
  function saveToServer(subscription) {
    var obj = subscription.toJSON();
    fetch('<?php echo $_siteUrl; ?>member/push-subscribe.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({
        endpoint: obj.endpoint,
        keys:     { p256dh: obj.keys.p256dh, auth: obj.keys.auth },
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
