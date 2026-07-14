<?php
require_once __DIR__ . '/../../includes/config.php';
/* Notification system — email/SMS पठाउन — सबै admin pages मा available */
require_once __DIR__ . '/../../includes/notifications.php';
/* Admin tables auto-create — DB मा tables नभएमा automatically बनाउँछ */
require_once __DIR__ . '/../includes/ensure-admin-tables.php';

/* IS_ADMIN_PAGE — admin-ui.php को security guard — यहाँ एकै पटक define गर्नुहोस् */
if (!defined('IS_ADMIN_PAGE')) define('IS_ADMIN_PAGE', true);
/* adminPageHeader() लगायत UI helper हरू सबै admin pages मा सुनिश्चित */
require_once __DIR__ . '/admin-ui.php';
require_once __DIR__ . '/audit-log.php';

// DB configured छैन भने db-setup.php मा redirect गर्नुस् (login page र db-setup बाहेक)
if (DB_NAME === '') {
    $_cur = basename($_SERVER['PHP_SELF'] ?? '');
    if ($_cur !== 'db-setup.php' && $_cur !== 'index.php') {
        header('Location: ' . ADMIN_URL . 'db-setup.php');
        exit;
    }
}

// Check if admin is logged in - prevent redirect loops
if (!isAdminLoggedIn()) {
    // Use absolute path to prevent loop
    $loginUrl = ADMIN_URL . 'index.php';
    if (!headers_sent()) {
        header("Location: " . $loginUrl);
    } else {
        echo '<script>window.location="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '";</script>';
    }
    exit();
}

$__licPage = basename($_SERVER['PHP_SELF'] ?? '');
$__licExempt = in_array($__licPage, ['index.php', 'logout.php', 'site-license.php', 'site-license-blocked.php', 'db-setup.php'], true);
/* म्याद सकिए पनि Superadmin लाई कुनै redirect छैन — मिति/नवीकरण भित्रै गर्न पाउनुपर्छ। साधारण admin मात्र blocked। */
if (!$__licExempt && function_exists('site_license_expired') && site_license_expired() && empty($_SESSION['is_superadmin'])) {
    header('Location: ' . ADMIN_URL . 'site-license-blocked.php');
    exit;
}

// Global CSRF protection for all admin POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।');
        redirect(function_exists('adminSelfUrl') ? adminSelfUrl() : (ADMIN_URL . 'dashboard.php'));
    }
}

// Pre-generate CSRF token so it is available for all admin forms
$csrfToken = generateCSRFToken();

$adminName    = $_SESSION['admin_name'] ?? 'Admin';
$adminEmail   = '';
$adminPhoto   = '';
/* Superadmin check — सबै admin pages मा available */
$isSuperAdmin = !empty($_SESSION['is_superadmin']);

/* Last-login — session मा stored (previous login, before current session) */
$adminLastLoginRaw = $_SESSION['admin_last_login'] ?? null;
$adminLastLoginLabel = '';
if ($adminLastLoginRaw) {
    $ts  = is_numeric($adminLastLoginRaw) ? (int)$adminLastLoginRaw : strtotime($adminLastLoginRaw);
    $diff = time() - $ts;
    if ($diff < 60)           $adminLastLoginLabel = 'Just now';
    elseif ($diff < 3600)     $adminLastLoginLabel = floor($diff / 60) . 'm ago';
    elseif ($diff < 86400)    $adminLastLoginLabel = floor($diff / 3600) . 'h ago';
    elseif ($diff < 172800)   $adminLastLoginLabel = 'Yesterday ' . date('H:i', $ts);
    else                      $adminLastLoginLabel = date('d M, H:i', $ts);
}
$currentPage  = getCurrentPage();
$siteName     = function_exists('getSetting') ? getSetting('site_name', 'आकाश सहकारी') : 'आकाश सहकारी';
$pwaAppName   = function_exists('getSetting') ? trim((string) getSetting('pwa_app_name', ''))  : '';
if ($pwaAppName   === '') $pwaAppName   = $siteName;
$pwaShortName = function_exists('getSetting') ? trim((string) getSetting('pwa_short_name', '')) : '';
if ($pwaShortName === '') $pwaShortName = $siteName;
$siteLogo     = function_exists('getLocalizedLogoPath')
    ? trim((string) getLocalizedLogoPath('assets/images/logo.png'))
    : (function_exists('getSetting')
        ? trim((string) getSetting('site_logo', getSetting('logo', 'assets/images/logo.png')))
        : 'assets/images/logo.png');
$hasSiteLogo  = !empty($siteLogo);
$adminLang = function_exists('getCurrentLang') ? getCurrentLang() : 'np';
$adminIsEnglish = ($adminLang === 'en');
$adminT = static function (string $np, string $en) use ($adminIsEnglish): string {
    return $adminIsEnglish ? $en : $np;
};
$adminLangQuery = $_GET;
$adminLangQuery['lang'] = $adminIsEnglish ? 'np' : 'en';
$adminLangToggleUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($adminLangQuery);
$adminLangBadge = $adminIsEnglish ? 'EN' : 'ने';

// Get unread messages count — DB नभए gracefully skip गर्छ
$db = null;
try {
    $db = getDB();
    try {
        $aid = (int)($_SESSION['admin_id'] ?? 0);
        if ($aid > 0) {
            $cols = $db->query("SHOW COLUMNS FROM admin_users")->fetchAll(PDO::FETCH_COLUMN, 0);
            $hasPhoto = in_array('photo', $cols, true) || in_array('avatar_url', $cols, true) || in_array('profile_photo', $cols, true);
            $photoExpr = "''";
            if (in_array('photo', $cols, true)) $photoExpr = "NULLIF(photo,'')";
            elseif (in_array('avatar_url', $cols, true)) $photoExpr = "NULLIF(avatar_url,'')";
            elseif (in_array('profile_photo', $cols, true)) $photoExpr = "NULLIF(profile_photo,'')";
            $stAdmin = $db->prepare("SELECT email, COALESCE({$photoExpr}, '') AS photo_path FROM admin_users WHERE id=? LIMIT 1");
            $stAdmin->execute([$aid]);
            $aRow = $stAdmin->fetch(PDO::FETCH_ASSOC) ?: [];
            $adminEmail = trim((string)($aRow['email'] ?? ''));
            $rawAdminPhoto = trim((string)($aRow['photo_path'] ?? ''));
            if ($rawAdminPhoto !== '') {
                if (preg_match('#^https?://#i', $rawAdminPhoto)) {
                    $adminPhoto = $rawAdminPhoto;
                } else {
                    $adminPhoto = SITE_URL . ltrim($rawAdminPhoto, '/');
                }
            }
        }
    } catch (\Throwable $e) {}
    try {
        $msgStmt = $db->query("SELECT COUNT(*) as count FROM contact_messages WHERE is_read = 0");
        $unreadMessages = $msgStmt->fetch()['count'] ?? 0;
    } catch (\Throwable $e) {
        $unreadMessages = 0;
        /* PDO जोगाउनुहोस् — अरु admin पेजले $db चाहिन्छ; मात्र unread count fail भएको हो */
    }
} catch (\Throwable $e) {
    $unreadMessages = 0;
    $db = null;
}

$adminAlertCounts = [
    'job'         => 0,
    'kyc'         => 0,
    'loan'        => 0,
    'feedback'    => 0,
    'grievance'   => 0,
    'welfare'     => 0,
    'auction'     => 0,
    'account'     => 0,
    'digital'     => 0,
    'kyc_risk'    => 0,
    'vendor'      => 0,
    'appointment' => 0,   /* नयाँ: pending भेटघाट */
    'attend'      => 0,   /* कार्यक्रम उपस्थिति अनुरोध */
    'survey'      => 0,   /* नयाँ: unread survey */
];
try {
    /* पुरानो DB मा job_applications.is_read नहुन सक्छ — fallback */
    $hasJobIsRead = function_exists('safeColumnExists') ? safeColumnExists('job_applications', 'is_read') : false;
    if ($hasJobIsRead) {
        $adminAlertCounts['job'] = function_exists('sqCount') ? sqCount($db, "SELECT COUNT(*) FROM job_applications WHERE is_read = 0", '[admin-header job]') : 0;
    } else {
        $adminAlertCounts['job'] = function_exists('sqCount') ? sqCount($db, "SELECT COUNT(*) FROM job_applications WHERE status = 'pending'", '[admin-header job]') : 0;
    }
} catch (\Throwable $e) {}
$__adminCount = static function (string $sql, string $log) use (&$db): int {
    if (!($db instanceof PDO) || !function_exists('sqCount')) {
        return 0;
    }
    try {
        return sqCount($db, $sql, $log);
    } catch (Throwable $e) {
        error_log($log . ' ' . $e->getMessage());
        return 0;
    }
};
$adminAlertCounts['kyc']         = $__adminCount("SELECT COUNT(*) FROM kyc_applications WHERE status IN ('pending','incomplete')", '[admin-header kyc]');
$adminAlertCounts['loan']        = $__adminCount("SELECT COUNT(*) FROM loan_applications WHERE LOWER(TRIM(status)) = 'pending'", '[admin-header loan]');
$adminAlertCounts['feedback']    = $__adminCount("SELECT COUNT(*) FROM member_feedback WHERE LOWER(TRIM(status)) = 'pending'", '[admin-header feedback]');
$adminAlertCounts['grievance']   = $__adminCount("SELECT COUNT(*) FROM grievances WHERE LOWER(TRIM(status)) = 'pending'", '[admin-header grievance]');
$adminAlertCounts['welfare']     = $__adminCount("SELECT COUNT(*) FROM member_welfare_claims WHERE LOWER(TRIM(status)) = 'pending'", '[admin-header welfare]');
$adminAlertCounts['auction']     = $__adminCount("SELECT COUNT(*) FROM auction_bids WHERE LOWER(TRIM(status)) = 'pending'", '[admin-header auction]');
$adminAlertCounts['vendor']      = $__adminCount("SELECT COUNT(*) FROM vendors WHERE LOWER(TRIM(status)) = 'pending'", '[admin-header vendor]');
$adminAlertCounts['account']     = $__adminCount("SELECT COUNT(*) FROM account_applications WHERE LOWER(TRIM(status)) = 'pending'", '[admin-header account]');
$adminAlertCounts['digital']     = $__adminCount("SELECT COUNT(*) FROM digital_service_requests WHERE LOWER(TRIM(status)) = 'pending'", '[admin-header digital]');
$adminAlertCounts['kyc_risk']    = $__adminCount("SELECT COUNT(*) FROM kyc_applications WHERE status='approved' AND risk_review_status='due_review'", '[admin-header kyc-risk]');
$adminAlertCounts['appointment'] = $__adminCount("SELECT COUNT(*) FROM appointments WHERE LOWER(TRIM(status)) = 'pending'", '[admin-header appointment]');
$adminAlertCounts['attend']      = $__adminCount("SELECT COUNT(*) FROM member_program_attendance_requests WHERE LOWER(TRIM(status)) = 'pending'", '[admin-header attend]');
try {
    /* member_survey पुरानो schema मा is_read नहुन सक्छ — fallback */
    $hasSurveyRead = function_exists('safeColumnExists') ? safeColumnExists('member_survey', 'is_read') : false;
    if ($hasSurveyRead) {
        $adminAlertCounts['survey'] = $__adminCount("SELECT COUNT(*) FROM member_survey WHERE is_read = 0", '[admin-header survey]');
    } else {
        $adminAlertCounts['survey'] = $__adminCount("SELECT COUNT(*) FROM member_survey", '[admin-header survey]');
    }
} catch (\Throwable $e) {}
/* Member Online Portal badges */
$adminAlertCounts['mem_pending']  = 0;
$adminAlertCounts['mem_resets']   = 0;
$adminAlertCounts['mem_pending'] = $__adminCount("SELECT COUNT(*) FROM members WHERE approval_status='pending'", '[admin-header mem-pending]');
$adminAlertCounts['mem_resets']  = $__adminCount("SELECT COUNT(*) FROM member_password_reset_requests WHERE status='pending'", '[admin-header mem-resets]');
$memPortalBadge = $adminAlertCounts['mem_pending'] + $adminAlertCounts['mem_resets'];
unset($__adminCount);

/* अस्थायी पासवर्ड — अनिवार्य परिवर्तन (public reset URL छैन)।
 * Superadmin: पासवर्ड `superadmin-config.local.php` मा राखिन्छ — UI बाट change गर्नु पर्दैन। */
$mustChangeExempt = in_array(getCurrentPage(), ['change-password', 'logout', 'index'], true)
    || !empty($_SESSION['is_superadmin']);
if (!$mustChangeExempt && $db instanceof PDO && (int) ($_SESSION['admin_id'] ?? 0) > 0) {
    try {
        if (function_exists('safeColumnExists') && safeColumnExists('admin_users', 'must_change_password')) {
            $qMc = $db->prepare('SELECT must_change_password FROM admin_users WHERE id = ? LIMIT 1');
            $qMc->execute([(int) $_SESSION['admin_id']]);
            if ((int) ($qMc->fetchColumn() ?: 0) === 1) {
                redirect(ADMIN_URL . 'change-password.php');
                exit;
            }
        }
    } catch (Throwable $e) { /* ignore */ }
}

// Determine which group the current page belongs to (for auto-open)
$pageGroups = [
    'samgri' => ['notices','designations','news','sliders','gallery','services','interest-rates','pages','pages-v2','downloads','faqs','useful-links','awards','reports','app-features','why-choose','partner-facilities'],
    'toli'   => ['team','team-karmachari','committees','info-officer','grievance-officer'],
    'hrm'    => ['hrm-dashboard','hrm-employees','hrm-employee-directory','hrm-departments','hrm-contracts','hrm-documents','hrm-messenger','hrm-employee-view','hrm-employee-id-card'],
    'rojgar' => ['careers','job-applications'],
    'aavedan'=> ['kyc-applications','kyc-risk-reviews','loan-applications','account-applications','digital-service-requests','appointments','auctions','auction-bids','vendor-enlistment'],
    'program' => ['programs','program-attendance','sahakari-calendar-events'],
    'nirvachan' => ['election-information','election-posts','election-candidates','election-results'],
    /* appointments also listed under आबेदनहरू for discoverability; keep sampark entry for old habit */
    'sampark'=> ['messages','feedbacks','grievances','appointments','welfare-claims','help-center','members','member-activities'],
    'memportal'=> ['member-online-portal'],
    'sanstha'=> ['service-centers','institutional-profile','notification-settings','notification-templates','push-notifications','member-of-year','about-settings','satisfaction-settings','settings'],
    'prawidhi'=> ['system-info','run-migration','backup-restore','update-checklist','site-health','db-setup','site-license'],
    /* admin management pages */
    'superadmin'=> ['manage-admins','site-setup'],
];
$activeGroup = '';
foreach ($pageGroups as $group => $pages) {
    if (in_array($currentPage, $pages)) {
        $activeGroup = $group;
        break;
    }
}

/* ─────────────────────────────────────────────────────────────
   GLOBAL EXCEPTION HANDLER
   Admin pages मा uncaught DB/PHP exceptions भएमा blank page को
   सट्टा Nepali friendly error card देखाउँछ + HTML properly closes.
   NOTE: admin/_bootstrap.php ले already register गरेको छ — यो
   in-page handler भने HTML output पछि पनि काम गर्छ (alert card)।
   ───────────────────────────────────────────────────────────── */
set_exception_handler(function (\Throwable $ex) {
    error_log('Admin page exception [' . basename($_SERVER['PHP_SELF'] ?? '') . ']: '
        . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    echo '<div class="container-fluid mt-4">'
       . '<div class="alert alert-danger border-start border-danger border-4 shadow-sm" role="alert">'
       . '<h5 class="mb-2"><i class="lucide-icon me-2" aria-hidden="true" data-lucide="triangle-alert"></i>डेटाबेस त्रुटि भयो</h5>'
       . '<p class="mb-1">यो पृष्ठ लोड गर्दा समस्या आयो। सम्भावित कारणहरू:</p>'
       . '<ul class="mb-2 ps-3">'
       . '<li>डेटाबेस तालिका अझसम्म बनिसकेको छैन</li>'
       . '<li>डेटाबेस जडान अस्थायी रूपमा अनुपलब्ध</li>'
       . '<li>Migration अपूर्ण — <a href="run-migration.php" class="alert-link">Migration चलाउनुहोस्</a></li>'
       . '</ul>'
       . '<small class="text-muted d-block font-monospace">'
       . '<i class="lucide-icon me-1" aria-hidden="true" data-lucide="code"></i>'
       . htmlspecialchars($ex->getMessage(), ENT_QUOTES, 'UTF-8')
       . '</small>'
       . '</div></div>';
    @include_once __DIR__ . '/admin-footer.php';
    exit;
});
?>
<!DOCTYPE html>
<html lang="<?php echo $adminIsEnglish ? 'en' : 'ne'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo $adminT('एडमिन प्यानल', 'Admin Panel'); ?></title>

    <!-- Bootstrap CSS (required for admin pages) -->
    <link rel="stylesheet" href="<?php echo defined('SITE_URL') ? SITE_URL : '../'; ?>assets/vendor/bootstrap.min.css">

    <!-- DataTables CSS (required for admin table pages) -->
    <link rel="stylesheet" href="<?php echo defined('SITE_URL') ? SITE_URL : '../'; ?>assets/vendor/datatables/dataTables.bootstrap5.min.css">

    <!-- Nepali Datepicker CSS (self-hosted) -->
    <link rel="stylesheet" href="<?php echo defined('SITE_URL') ? SITE_URL : '../'; ?>assets/css/nepali.datepicker.min.css">

    <!-- Font Awesome: loaded via coopThemeHeadAssets (self-hosted assets/vendor/fontawesome) -->

    <?php if (function_exists('coopThemeHeadAssets')) { coopThemeHeadAssets('admin'); } ?>

    <!-- PWA manifest + Apple tags -->
    <meta name="theme-color" content="<?php echo htmlspecialchars(function_exists('getSetting') ? (string)getSetting('primary_color', '#1a5f2a') : '#1a5f2a', ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($pwaShortName, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="<?php echo defined('SITE_URL') ? SITE_URL : '../'; ?>assets/images/icon-192x192.png">
    <link rel="manifest" href="<?php echo defined('SITE_URL') ? rtrim((string)SITE_URL, '/') . '/' : '../'; ?>manifest.php">
    <meta name="pwa-app-name"   content="<?php echo htmlspecialchars($pwaAppName,   ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="pwa-short-name" content="<?php echo htmlspecialchars($pwaShortName, ENT_QUOTES, 'UTF-8'); ?>">
    <script>if(window.matchMedia('(display-mode:standalone)').matches||navigator.standalone)document.documentElement.classList.add('pwa-standalone');</script>

</head>
<body class="admin-page-<?php echo htmlspecialchars((string)$currentPage, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <a href="<?php echo SITE_URL; ?>" class="logo sidebar-brand <?php echo $hasSiteLogo ? 'has-logo' : 'no-logo'; ?>">
                    <?php if ($hasSiteLogo): ?>
                    <img src="<?php echo SITE_URL . htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>" class="sidebar-brand-logo">
                    <?php else: ?>
                    <div class="admin-logo-fallback"><i class="lucide-icon" aria-hidden="true" data-lucide="landmark"></i></div>
                    <span class="sidebar-brand-text"><?php echo $adminT('एडमिन प्यानल', 'Admin Panel'); ?></span>
                    <?php endif; ?>
                </a>
                <button type="button" class="sidebar-close" id="sidebarClose" aria-label="Close menu">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="x"></i>
                </button>
            </div>

            <nav class="sidebar-nav">
                <ul>
                    <!-- ── ड्यासबोर्ड (direct link) ── -->
                    <li class="<?php echo $currentPage == 'dashboard' ? 'active' : ''; ?>">
                        <?php $__dash_total = array_sum($adminAlertCounts) + $unreadMessages; ?>
                        <a href="dashboard.php" class="sidebar-link-flex">
                            <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="gauge"></i></span>
                            <span class="sidebar-link-label"><?php echo $adminT('ड्यासबोर्ड', 'Dashboard'); ?></span>
                            <?php if ($__dash_total > 0): ?>
                            <span class="group-badge"><?php echo $__dash_total; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <!-- ── Admin User Management — सबै admin ले देख्छन् ── -->
                    <li class="<?php echo $currentPage === 'manage-admins' ? 'active' : ''; ?>">
                        <a href="manage-admins.php">
                            <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="users-round"></i></span>
                            <span><?php echo $adminT('Admin व्यवस्थापन', 'Admin Management'); ?></span>
                            <?php if (!empty($_SESSION['is_superadmin'])): ?>
                            <span class="sa-mini-badge">SA</span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <!-- ── सामग्री ── -->
                    <li class="nav-group-wrap">
                        <div class="nav-group-header <?php echo $activeGroup=='samgri' ? 'open' : ''; ?>" data-group="samgri">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="folder-open"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('सामग्री', 'Content'); ?></span>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='samgri' ? 'open' : ''; ?>" id="group-samgri">
                            <li class="<?php echo $currentPage=='notices' ? 'active' : ''; ?>">
                                <a href="notices.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="megaphone"></i></span><span><?php echo $adminT('सूचनाहरू', 'Notices'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='designations' ? 'active' : ''; ?>">
                                <a href="designations.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="award"></i></span><span><?php echo $adminT('पद मास्टर', 'Designation Master'); ?></span></a>
                            </li>
                            <?php /* निर्वाचन सम्बन्धी menus छुट्टै “निर्वाचन” group मा (पद Master = election-posts) */ ?>
                            <li class="<?php echo $currentPage=='news' ? 'active' : ''; ?>">
                                <a href="news.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="newspaper"></i></span><span><?php echo $adminT('समाचार', 'News'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='sliders' ? 'active' : ''; ?>">
                                <a href="sliders.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="images"></i></span><span><?php echo $adminT('स्लाइडर', 'Sliders'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='gallery' ? 'active' : ''; ?>">
                                <a href="gallery.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="image-play"></i></span><span><?php echo $adminT('ग्यालरी', 'Gallery'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='services' ? 'active' : ''; ?>">
                                <a href="services.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="heart"></i></span><span><?php echo $adminT('सेवाहरू', 'Services'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='interest-rates' ? 'active' : ''; ?>">
                                <a href="interest-rates.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="percent"></i></span><span><?php echo $adminT('ब्याज दर', 'Interest Rates'); ?></span></a>
                            </li>
                            <li class="<?php echo ($currentPage === 'pages-v2' && (($_GET['tab'] ?? 'dynamic') === 'dynamic')) ? 'active' : ''; ?>">
                                <a href="pages.php?tab=dynamic">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="file-text"></i></span>
                                    <span><?php echo $adminT('गतिशील पृष्ठ', 'Dynamic Pages'); ?></span>
                                </a>
                            </li>
                            <li class="<?php echo ($currentPage === 'pages-v2' && (($_GET['tab'] ?? '') === 'static')) ? 'active' : ''; ?>">
                                <a href="pages.php?tab=static">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="layers"></i></span>
                                    <span><?php echo $adminT('स्थिर पृष्ठ', 'Static Pages'); ?></span>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='downloads' ? 'active' : ''; ?>">
                                <a href="downloads.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="file-down"></i></span><span><?php echo $adminT('डाउनलोड', 'Downloads'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='faqs' ? 'active' : ''; ?>">
                                <a href="faqs.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="help-circle"></i></span><span><?php echo $adminT('प्रश्नोत्तर (FAQs)', 'FAQs'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='useful-links' ? 'active' : ''; ?>">
                                <a href="useful-links.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="link"></i></span><span><?php echo $adminT('उपयोगी लिंकहरू', 'Useful Links'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='awards' ? 'active' : ''; ?>">
                                <a href="awards.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="trophy"></i></span><span><?php echo $adminT('सम्मान/पुरस्कार', 'Awards'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='reports' ? 'active' : ''; ?>">
                                <a href="reports.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="chart-column"></i></span><span><?php echo $adminT('प्रतिवेदन', 'Reports'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='app-features' ? 'active' : ''; ?>">
                                <a href="app-features.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="smartphone"></i></span><span><?php echo $adminT('एप सुविधाहरू', 'App Features'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='why-choose' ? 'active' : ''; ?>">
                                <a href="why-choose.php"><span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-gold" aria-hidden="true" data-lucide="star"></i></span><span><?php echo $adminT('किन हामीलाई छान्ने?', 'Why Choose Us'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='partner-facilities' ? 'active' : ''; ?>">
                                <a href="partner-facilities.php"><span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-primary-soft" aria-hidden="true" data-lucide="handshake"></i></span><span><?php echo $adminT('साझेदार सुविधा', 'Partner Facilities'); ?></span></a>
                            </li>
                        </ul>
                    </li>

                    <!-- ── मानवीय स्रोत ── -->
                    <li class="nav-group-wrap">
                        <div class="nav-group-header <?php echo $activeGroup=='toli' ? 'open' : ''; ?>" data-group="toli">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="users"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('मानवीय स्रोत', 'Human Resources'); ?></span>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='toli' ? 'open' : ''; ?>" id="group-toli">
                            <li class="<?php echo $currentPage=='team' ? 'active' : ''; ?>">
                                <a href="team.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="landmark"></i></span><span><?php echo $adminT('सञ्चालक / समिति', 'Directors / Committee'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='team-karmachari' ? 'active' : ''; ?>">
                                <a href="team-karmachari.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="user-round"></i></span><span><?php echo $adminT('कर्मचारी / व्यवस्थापन', 'Staff / Management'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='committees' ? 'active' : ''; ?>">
                                <a href="committees.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="users-round"></i></span><span><?php echo $adminT('समिति/उपसमिति', 'Committee/Subcommittee'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='info-officer' ? 'active' : ''; ?>">
                                <a href="info-officer.php"><span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-cyan" aria-hidden="true" data-lucide="shield-user"></i></span><span><?php echo $adminT('सूचना अधिकारी (RTI)', 'Information Officer (RTI)'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='grievance-officer' ? 'active' : ''; ?>">
                                <a href="grievance-officer.php"><span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-purple" aria-hidden="true" data-lucide="user-round"></i></span><span><?php echo $adminT('गुनासो अधिकारी', 'Grievance Officer'); ?></span></a>
                            </li>
                        </ul>
                    </li>

                    <!-- ── HRM (मानव संसाधन) ── v11.1 ── -->
                    <li class="nav-group-wrap">
                        <div class="nav-group-header <?php echo $activeGroup=='hrm' ? 'open' : ''; ?>" data-group="hrm">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="award"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('मानव संसाधन (HRM)', 'HRM'); ?></span>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='hrm' ? 'open' : ''; ?>" id="group-hrm">
                            <li class="<?php echo $currentPage=='hrm-dashboard' ? 'active' : ''; ?>">
                                <a href="hrm-dashboard.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="gauge"></i></span><span><?php echo $adminT('HRM ड्यासबोर्ड', 'HRM Dashboard'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='hrm-employees' ? 'active' : ''; ?>">
                                <a href="hrm-employees.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="users"></i></span><span><?php echo $adminT('कर्मचारीहरू', 'Employees'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='hrm-employee-directory' ? 'active' : ''; ?>">
                                <a href="hrm-employee-directory.php"><span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-cyan" aria-hidden="true" data-lucide="book-user"></i></span><span><?php echo $adminT('कर्मचारी डाइरेक्टरी', 'Employee Directory'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='hrm-departments' ? 'active' : ''; ?>">
                                <a href="hrm-departments.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="network"></i></span><span><?php echo $adminT('विभागहरू', 'Departments'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='hrm-contracts' ? 'active' : ''; ?>">
                                <a href="hrm-contracts.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="file-pen"></i></span><span><?php echo $adminT('करार/नियुक्ति', 'Contracts'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='hrm-documents' ? 'active' : ''; ?>">
                                <a href="hrm-documents.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="folder-open"></i></span><span><?php echo $adminT('दस्तावेजहरू', 'Documents'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='hrm-messenger' ? 'active' : ''; ?>">
                                <a href="hrm-messenger.php"><span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-primary-soft" aria-hidden="true" data-lucide="message-square"></i></span><span><?php echo $adminT('आन्तरिक च्याट', 'Internal Chat'); ?></span></a>
                            </li>
                        </ul>
                    </li>

                    <!-- ── रोजगारी ── -->
                    <li class="nav-group-wrap">
                        <?php $rojgarBadge = $adminAlertCounts['job']; ?>
                        <div class="nav-group-header <?php echo $activeGroup=='rojgar' ? 'open' : ''; ?>" data-group="rojgar">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="briefcase"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('रोजगारी', 'Career'); ?></span>
                            <?php if ($rojgarBadge > 0): ?><span class="group-badge"><?php echo $rojgarBadge; ?></span><?php endif; ?>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='rojgar' ? 'open' : ''; ?>" id="group-rojgar">
                            <li class="<?php echo $currentPage=='careers' ? 'active' : ''; ?>">
                                <a href="careers.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="briefcase"></i></span><span><?php echo $adminT('रोजगारी पोस्ट', 'Career Posts'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='job-applications' ? 'active' : ''; ?>">
                                <a href="job-applications.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="file-check"></i></span>
                                    <span><?php echo $adminT('जागिर आवेदन', 'Job Applications'); ?></span>
                                    <?php if ($adminAlertCounts['job'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['job']; ?></span><?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- ── आवेदनहरू ── -->
                    <li class="nav-group-wrap">
                        <?php $aavedan_total = $adminAlertCounts['kyc'] + $adminAlertCounts['kyc_risk'] + $adminAlertCounts['loan'] + $adminAlertCounts['account'] + $adminAlertCounts['digital'] + $adminAlertCounts['appointment'] + $adminAlertCounts['auction'] + $adminAlertCounts['vendor']; ?>
                        <div class="nav-group-header <?php echo $activeGroup=='aavedan' ? 'open' : ''; ?>" data-group="aavedan">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="inbox"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('आवेदनहरू', 'Applications'); ?></span>
                            <?php if ($aavedan_total > 0): ?><span class="group-badge"><?php echo $aavedan_total; ?></span><?php endif; ?>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='aavedan' ? 'open' : ''; ?>" id="group-aavedan">
                            <li class="<?php echo $currentPage=='kyc' ? 'active' : ''; ?>">
                                <a href="kyc-applications.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="id-card"></i></span>
                                    <span><?php echo $adminT('KYC आवेदन', 'KYC Applications'); ?></span>
                                    <?php if ($adminAlertCounts['kyc'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['kyc']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='kyc-risk-reviews' ? 'active' : ''; ?>">
                                <a href="kyc-risk-reviews.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="shield-half"></i></span>
                                    <span><?php echo $adminT('KYC जोखिम समीक्षा', 'KYC Risk Review'); ?></span>
                                    <?php if ($adminAlertCounts['kyc_risk'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['kyc_risk']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='loans' ? 'active' : ''; ?>">
                                <a href="loan-applications.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="hand-coins"></i></span>
                                    <span><?php echo $adminT('ऋण आवेदन', 'Loan Applications'); ?></span>
                                    <?php if ($adminAlertCounts['loan'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['loan']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='account-apps' ? 'active' : ''; ?>">
                                <a href="account-applications.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="user-plus"></i></span>
                                    <span><?php echo $adminT('खाता आवेदन', 'Account Applications'); ?></span>
                                    <?php if ($adminAlertCounts['account'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['account']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='digital-service-requests' ? 'active' : ''; ?>">
                                <a href="digital-service-requests.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="laptop-code"></i></span>
                                    <span><?php echo $adminT('डिजिटल सेवा', 'Digital Services'); ?></span>
                                    <?php if ($adminAlertCounts['digital'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['digital']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='appointments' ? 'active' : ''; ?>">
                                <a href="appointments.php?status=pending" class="sidebar-link-flex">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="calendar-check"></i></span>
                                    <span class="sidebar-link-label"><?php echo $adminT('भेटघाट / सहकारी भ्रमण', 'Appointments / Coop Visit'); ?></span>
                                    <?php if ($adminAlertCounts['appointment'] > 0): ?><span class="badge"><?php echo (int)$adminAlertCounts['appointment']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='auctions' ? 'active' : ''; ?>">
                                <a href="auctions.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="gavel"></i></span>
                                    <span><?php echo $adminT('लिलामी / बोलपत्र', 'Auction / Bids'); ?></span>
                                    <?php if ($adminAlertCounts['auction'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['auction']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='auction-bids' ? 'active' : ''; ?>">
                                <a href="auction-bids.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="list-ordered"></i></span>
                                    <span><?php echo $adminT('बोलपत्र सूची', 'Bid List'); ?></span>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='vendor-enlistment' ? 'active' : ''; ?>">
                                <a href="vendor-enlistment.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="store"></i></span>
                                    <span><?php echo $adminT('भेन्डर सूचीकरण', 'Vendor Enlistment'); ?></span>
                                    <?php if ($adminAlertCounts['vendor'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['vendor']; ?></span><?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- ── कार्यक्रम व्यवस्थापन (All program tools) ── -->
                    <li class="nav-group-wrap">
                        <?php $program_total = (int)($adminAlertCounts['attend'] ?? 0); ?>
                        <div class="nav-group-header <?php echo $activeGroup=='program' ? 'open' : ''; ?>" data-group="program">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="calendar-check"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('कार्यक्रम व्यवस्थापन', 'Program Management'); ?></span>
                            <?php if ($program_total > 0): ?><span class="group-badge"><?php echo $program_total; ?></span><?php endif; ?>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='program' ? 'open' : ''; ?>" id="group-program">
                            <li class="<?php echo $currentPage=='programs' ? 'active' : ''; ?>">
                                <a href="programs.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="calendar-plus"></i></span>
                                    <span><?php echo $adminT('कार्यक्रम बनाउने / सूची', 'Program Create / List'); ?></span>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='sahakari-calendar-events' ? 'active' : ''; ?>">
                                <a href="sahakari-calendar-events.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="calendar-days"></i></span>
                                    <span><?php echo $adminT('सहकारी पात्रो कार्यक्रम', 'Sahakari Patro Events'); ?></span>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='program-attendance-verify' ? 'active' : ''; ?>">
                                <a href="../program-attendance-verify.php" class="sidebar-link-flex">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="user-check"></i></span>
                                    <span class="sidebar-link-label"><?php echo $adminT('उपस्थिति प्रमाणिकरण', 'Attendance Verify'); ?></span>
                                    <?php if (!empty($adminAlertCounts['attend'])): ?><span class="badge"><?php echo (int)$adminAlertCounts['attend']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='program-attendance' ? 'active' : ''; ?>">
                                <a href="program-attendance.php" class="sidebar-link-flex">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="clipboard-check"></i></span>
                                    <span class="sidebar-link-label"><?php echo $adminT('उपस्थिति रिपोर्ट', 'Attendance Report'); ?></span>
                                    <?php if (!empty($adminAlertCounts['attend'])): ?><span class="badge"><?php echo (int)$adminAlertCounts['attend']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <?php /* निर्वाचन जानकारी छुट्टै group मा सरेको */ ?>
                            <li class="<?php echo $currentPage=='analytics' ? 'active' : ''; ?>">
                                <a href="analytics.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="chart-line"></i></span>
                                    <span><?php echo $adminT('विश्लेषण ड्यासबोर्ड', 'Analytics Dashboard'); ?></span>
                                </a>
                            </li>
                        </ul>
                    </li>


                    <!-- ── निर्वाचन (छुट्टै group) ── -->
                    <li class="nav-group-wrap">
                        <div class="nav-group-header <?php echo $activeGroup=='nirvachan' ? 'open' : ''; ?>" data-group="nirvachan">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="square-check-big"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('निर्वाचन', 'Election'); ?></span>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='nirvachan' ? 'open' : ''; ?>" id="group-nirvachan">
                            <li class="<?php echo $currentPage=='election-information' ? 'active' : ''; ?>">
                                <a href="election-information.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="info"></i></span><span><?php echo $adminT('निर्वाचन जानकारी', 'Election Information'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='election-posts' ? 'active' : ''; ?>">
                                <a href="election-posts.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="briefcase"></i></span><span><?php echo $adminT('पद Master', 'Post Master'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='election-candidates' ? 'active' : ''; ?>">
                                <a href="election-candidates.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="user-round"></i></span><span><?php echo $adminT('उम्मेदवार/पद', 'Candidates/Posts'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='election-results' ? 'active' : ''; ?>">
                                <a href="election-results.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="chart-bar"></i></span><span><?php echo $adminT('निर्वाचन नतिजा', 'Election Results'); ?></span></a>
                            </li>
                        </ul>
                    </li>

                    <!-- ── सम्पर्क / संचार ── -->
                    <li class="nav-group-wrap">
                        <?php $sampark_total = $unreadMessages + $adminAlertCounts['feedback'] + $adminAlertCounts['grievance'] + $adminAlertCounts['welfare'] + $adminAlertCounts['appointment']; ?>
                        <div class="nav-group-header <?php echo $activeGroup=='sampark' ? 'open' : ''; ?>" data-group="sampark">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="message-square"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('सम्पर्क', 'Contact'); ?></span>
                            <?php if ($sampark_total > 0): ?><span class="group-badge"><?php echo $sampark_total; ?></span><?php endif; ?>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='sampark' ? 'open' : ''; ?>" id="group-sampark">
                            <li class="<?php echo $currentPage=='messages' ? 'active' : ''; ?>">
                                <a href="messages.php" class="sidebar-link-flex">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="mail-open"></i></span>
                                    <span class="sidebar-link-label"><?php echo $adminT('सन्देशहरू', 'Messages'); ?></span>
                                    <?php if ($unreadMessages > 0): ?><span class="badge"><?php echo $unreadMessages; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='feedbacks' ? 'active' : ''; ?>">
                                <a href="feedbacks.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="message-square"></i></span>
                                    <span><?php echo $adminT('सुझाव/प्रतिक्रिया', 'Feedback'); ?></span>
                                    <?php if ($adminAlertCounts['feedback'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['feedback']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='grievances' ? 'active' : ''; ?>">
                                <a href="grievances.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="triangle-alert"></i></span>
                                    <span><?php echo $adminT('गुनासो', 'Grievances'); ?></span>
                                    <?php if ($adminAlertCounts['grievance'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['grievance']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='appointments' ? 'active' : ''; ?>">
                                <a href="appointments.php?status=pending" class="sidebar-link-flex">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="calendar-check"></i></span>
                                    <span class="sidebar-link-label"><?php echo $adminT('भेटघाट / सहकारी भ्रमण', 'Appointments / Coop Visit'); ?></span>
                                    <?php if ($adminAlertCounts['appointment'] > 0): ?><span class="badge"><?php echo (int)$adminAlertCounts['appointment']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='welfare-claims' ? 'active' : ''; ?>">
                                <a href="welfare-claims.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="heart-plus"></i></span>
                                    <span><?php echo $adminT('कल्याण दाबी', 'Welfare Claims'); ?></span>
                                    <?php if ($adminAlertCounts['welfare'] > 0): ?><span class="badge"><?php echo $adminAlertCounts['welfare']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='help-center' ? 'active' : ''; ?>">
                                <a href="help-center.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="headset"></i></span><span><?php echo $adminT('सहायता केन्द्र', 'Help Center'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='members' ? 'active' : ''; ?>">
                                <a href="members.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-primary" aria-hidden="true" data-lucide="user-check"></i></span>
                                    <span><?php echo $adminT('सदस्य पोर्टल', 'Member Portal'); ?></span>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='member-activities' ? 'active' : ''; ?>">
                                <a href="member-activities.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-amber" aria-hidden="true" data-lucide="bar-chart-2"></i></span>
                                    <span><?php echo $adminT('सदस्य गतिविधि खोज', 'Member Activities Search'); ?></span>
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- ── Member Online Portal ── -->
                    <li class="nav-group-wrap">
                        <?php $memPortalBadgeTotal = $memPortalBadge ?? 0; ?>
                        <div class="nav-group-header <?php echo $activeGroup=='memportal' ? 'open' : ''; ?>" data-group="memportal">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="globe"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('सदस्य अनलाइन पोर्टल', 'Member Online Portal'); ?></span>
                            <?php if ($memPortalBadgeTotal > 0): ?><span class="group-badge"><?php echo $memPortalBadgeTotal; ?></span><?php endif; ?>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='memportal' ? 'open' : ''; ?>" id="group-memportal">
                            <li class="<?php echo $currentPage=='member-online-portal' ? 'active' : ''; ?>">
                                <a href="member-online-portal.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-primary" aria-hidden="true" data-lucide="users"></i></span>
                                    <span><?php echo $adminT('दर्ता अनुमोदन', 'Registration Approval'); ?></span>
                                    <?php if (!empty($adminAlertCounts['mem_pending'])): ?><span class="badge"><?php echo $adminAlertCounts['mem_pending']; ?></span><?php endif; ?>
                                </a>
                            </li>
                            <li class="<?php echo ($currentPage=='member-online-portal' && ($_GET['tab'] ?? '')=='resets') ? 'active' : ''; ?>">
                                <a href="member-online-portal.php?tab=resets">
                                    <span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-amber" aria-hidden="true" data-lucide="key"></i></span>
                                    <span><?php echo $adminT('पासवर्ड Reset', 'Password Reset'); ?></span>
                                    <?php if (!empty($adminAlertCounts['mem_resets'])): ?><span class="badge"><?php echo $adminAlertCounts['mem_resets']; ?></span><?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- ── संस्था ── -->
                    <li class="nav-group-wrap">
                        <div class="nav-group-header <?php echo $activeGroup=='sanstha' ? 'open' : ''; ?>" data-group="sanstha">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="landmark"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('संस्था', 'Institution'); ?></span>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='sanstha' ? 'open' : ''; ?>" id="group-sanstha">
                            <li class="<?php echo $currentPage=='service-centers' ? 'active' : ''; ?>">
                                <a href="service-centers.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="map-pin"></i></span><span><?php echo $adminT('शाखाहरू', 'Branches'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='institutional-profile' ? 'active' : ''; ?>">
                                <a href="institutional-profile.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="landmark"></i></span><span><?php echo $adminT('संस्थागत प्रोफाइल', 'Institutional Profile'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='notification-settings' ? 'active' : ''; ?>">
                                <a href="notification-settings.php">
                                <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="bell"></i></span>
                                <span><?php echo $adminT('सूचना सेटिङ्स', 'Notification Settings'); ?></span>
                            </a>
                            </li>
                            <li class="<?php echo $currentPage=='notification-templates' ? 'active' : ''; ?>">
                                <a href="notification-templates.php">
                                <span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-violet" aria-hidden="true" data-lucide="mail-open"></i></span>
                                <span><?php echo $adminT('सूचना Templates', 'Notification Templates'); ?></span>
                            </a>
                            </li>
                            <li class="<?php echo $currentPage=='push-notifications' ? 'active' : ''; ?>">
                                <a href="push-notifications.php">
                                <span class="nav-icon-wrap"><i style="color:#f59e0b;" class="lucide-icon" aria-hidden="true" data-lucide="bell-ring"></i></span>
                                <span><?php echo $adminT('Push Notifications', 'Push Notifications'); ?></span>
                            </a>
                            </li>
                            <li class="<?php echo $currentPage=='member-of-year' ? 'active' : ''; ?>">
                                <a href="member-of-year.php"><span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-gold" aria-hidden="true" data-lucide="trophy"></i></span><span><?php echo $adminT('वर्षको सर्वश्रेष्ठ सदस्य', 'Member of the Year'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='about-settings' ? 'active' : ''; ?>">
                                <a href="about-settings.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="landmark"></i></span><span><?php echo $adminT('बारेमा पृष्ठ', 'About Page'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='satisfaction-settings' ? 'active' : ''; ?>">
                                <a href="satisfaction-settings.php"><span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-pink" aria-hidden="true" data-lucide="smile"></i></span><span><?php echo $adminT('सन्तुष्टि Widget', 'Satisfaction Widget'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='settings' ? 'active' : ''; ?>">
                                <a href="settings.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="sliders"></i></span><span><?php echo $adminT('सेटिङ्स', 'Settings'); ?></span></a>
                            </li>
                        </ul>
                    </li>

                    <!-- ── प्रविधि ── -->
                    <li class="nav-group-wrap">
                        <div class="nav-group-header <?php echo $activeGroup=='prawidhi' ? 'open' : ''; ?>" data-group="prawidhi">
                            <span class="nav-group-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="server"></i></span>
                            <span class="nav-group-label"><?php echo $adminT('प्रविधि', 'Technical'); ?></span>
                            <i class="lucide-icon nav-arrow" aria-hidden="true" data-lucide="chevron-right"></i>
                        </div>
                        <ul class="nav-submenu <?php echo $activeGroup=='prawidhi' ? 'open' : ''; ?>" id="group-prawidhi">
                            <li class="<?php echo $currentPage=='system-info' ? 'active' : ''; ?>">
                                <a href="system-info.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="server"></i></span><span><?php echo $adminT('प्रणाली जानकारी', 'System Info'); ?></span></a>
                            </li>
                            <?php if (!empty($_SESSION['is_superadmin'])): ?>
                            <li class="<?php echo $currentPage=='run-migration' ? 'active' : ''; ?>">
                                <a href="run-migration.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="database"></i></span>
                                    <span><?php echo $adminT('डेटाबेस Migration', 'Database Migration'); ?></span>
                                    <span class="sa-label-badge">SA</span>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='backup-restore' ? 'active' : ''; ?>">
                                <a href="backup-restore.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="shield"></i></span>
                                    <span><?php echo $adminT('ब्याकअप / पुनर्स्थापना', 'Backup / Restore'); ?></span>
                                    <span class="sa-label-badge">SA</span>
                                </a>
                            </li>
                            <?php endif; ?>
                            <li class="<?php echo $currentPage=='update-checklist' ? 'active' : ''; ?>">
                                <a href="update-checklist.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="list-checks"></i></span><span><?php echo $adminT('अपडेट सूची', 'Update Checklist'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='site-health' ? 'active' : ''; ?>">
                                <a href="site-health.php"><span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="heart-pulse"></i></span><span><?php echo $adminT('साइट स्वास्थ्य', 'Site Health'); ?></span></a>
                            </li>
                            <li class="<?php echo $currentPage=='audit-log' ? 'active' : ''; ?>">
                                <a href="audit-log.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-blue" aria-hidden="true" data-lucide="shield-half"></i></span>
                                    <span><?php echo $adminT('अडिट लग', 'Audit Log'); ?></span>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='error-log' ? 'active' : ''; ?>">
                                <a href="error-log.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-red" aria-hidden="true" data-lucide="bug"></i></span>
                                    <span><?php echo $adminT('त्रुटि लग', 'Error Log'); ?></span>
                                </a>
                            </li>
                            <!-- v5: In-app User Manual / Help & Guide (non-developer friendly) -->
                            <li class="<?php echo ($currentPage=='help-guide' || $currentPage=='help-center') ? 'active' : ''; ?>">
                                <a href="help-center.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-green" aria-hidden="true" data-lucide="book-open"></i></span>
                                    <span><?php echo $adminT('📖 सहायता / Help', '📖 Help / Guide'); ?></span>
                                </a>
                            </li>
                            <!-- Site Setup Manager — setup.php को काम admin panel भित्रबाट -->
                            <li class="<?php echo $currentPage=='site-setup' ? 'active' : ''; ?>">
                                <a href="site-setup.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon" aria-hidden="true" data-lucide="sliders"></i></span>
                                    <span><?php echo $adminT('साइट सेटअप', 'Site Setup'); ?></span>
                                </a>
                            </li>
                            <!-- साइट म्याद — Superadmin only -->
                            <?php if (!empty($_SESSION['is_superadmin'])): ?>
                            <li class="<?php echo $currentPage=='site-license' ? 'active' : ''; ?>">
                                <a href="site-license.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-amber" aria-hidden="true" data-lucide="calendar-check"></i></span>
                                    <span><?php echo $adminT('साइट म्याद', 'Site License'); ?></span>
                                    <span class="sa-label-badge">SA</span>
                                </a>
                            </li>
                            <li class="<?php echo $currentPage=='db-setup' ? 'active' : ''; ?>">
                                <a href="db-setup.php">
                                    <span class="nav-icon-wrap"><i class="lucide-icon nav-icon-accent nav-icon-primary-soft" aria-hidden="true" data-lucide="database"></i></span>
                                    <span><?php echo $adminT('DB सेटअप', 'DB Setup'); ?></span>
                                    <span class="sa-label-badge">SA</span>
                                </a>
                            </li>
                            <?php endif; ?>

                            <!-- ── PWA Install App ── -->
                            <li id="pwa-nav-install-li">
                                <a href="javascript:void(0)" onclick="if(typeof pwaTriggerInstall==='function')pwaTriggerInstall();"
                                   class="pwa-admin-install-link"
                                   title="<?php echo $adminT('App Install गर्नुहोस्', 'Install App'); ?>">
                                    <span class="nav-icon-wrap">
                                        <i class="lucide-icon nav-icon-accent nav-icon-green" aria-hidden="true" data-lucide="smartphone"></i>
                                    </span>
                                    <span><?php echo $adminT('App Install गर्नुहोस्', 'Install App'); ?></span>
                                    <span class="badge bg-success ms-auto" style="font-size:.65rem;padding:2px 6px;">PWA</span>
                                </a>
                            </li>
                        </ul>
                    </li>

                </ul>
            </nav>

            <!-- v9.6 — sidebar "Website हेर्नुहोस्" link removed per request -->

            <!-- Sidebar user strip — logged-in admin को नाम तल देखाउँछ -->
            <div class="sidebar-user-strip">
                <div class="sidebar-user-avatar sidebar-user-avatar-media">
                    <?php if ($adminPhoto !== ''): ?>
                        <img src="<?php echo htmlspecialchars($adminPhoto, ENT_QUOTES, 'UTF-8'); ?>" alt="Admin"
                             class="sidebar-user-avatar-img"
                             onerror="this.style.display='none';this.parentNode.innerHTML='<i data-lucide=&quot;user&quot; class=&quot;sidebar-user-fallback-icon&quot;></i>';">
                    <?php else: ?>
                        <i class="lucide-icon sidebar-user-fallback-icon" aria-hidden="true" data-lucide="user"></i>
                    <?php endif; ?>
                </div>
                <div class="sidebar-user-meta">
                    <div class="sidebar-user-name">
                        <?php echo htmlspecialchars($adminName ?? 'Admin'); ?>
                    </div>
                    <div class="sidebar-user-role">
                        <?php echo !empty($_SESSION['is_superadmin']) ? $adminT('सुपर एडमिन', 'Superadmin') : $adminT('प्रशासक', 'Administrator'); ?>
                    </div>
                </div>
                <a href="logout.php" title="<?php echo $adminT('लगआउट', 'Logout'); ?>" class="sidebar-strip-logout">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="log-out"></i>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Navbar -->
            <header class="admin-header admin-header--compact">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar" aria-expanded="false" title="Toggle sidebar">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="menu"></i>
                    </button>
                    <a href="<?php echo ADMIN_URL; ?>dashboard.php" class="admin-topbar-brand <?php echo $hasSiteLogo ? 'has-logo' : 'no-logo'; ?>">
                        <!-- Always keep a fallback UI; if logo fails to load, show this -->
                        <div class="admin-logo-fallback" style="<?php echo $hasSiteLogo ? 'display:none;' : ''; ?>">
                            <i class="lucide-icon" aria-hidden="true" data-lucide="landmark"></i>
                        </div>
                        <?php if ($hasSiteLogo): ?>
                        <img
                            src="<?php echo SITE_URL . htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8'); ?>"
                            alt="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>"
                            onerror="this.style.display='none';var p=this.closest('a');if(p){p.classList.remove('has-logo');p.classList.add('no-logo');var f=p.querySelector('.admin-logo-fallback');var t=p.querySelector('.brand-text');if(f)f.style.display='grid';if(t)t.style.display='inline';}"
                        >
                        <?php endif; ?>
                        <span class="brand-text" style="<?php echo $hasSiteLogo ? 'display:none;' : ''; ?>">
                            <?php echo $adminT('एडमिन', 'Admin'); ?>
                        </span>
                    </a>
                    <div class="page-title-wrap">
                        <h1 class="page-title"><?php echo $pageTitle ?? $adminT('ड्यासबोर्ड', 'Dashboard'); ?></h1>
                        <span class="header-date-pill" title="<?php echo $adminT('आजको मिति', 'Today'); ?>">
                            <i class="lucide-icon" aria-hidden="true" data-lucide="calendar"></i>
                            <?php echo function_exists('formatNepaliDate') ? formatNepaliDate(date('Y-m-d')) : date('Y-m-d'); ?>
                        </span>
                    </div>
                </div>

                <div class="header-right admin-header-actions">
                    <?php $siteVer = getSetting('site_version') ?? '1.0.0'; ?>
                    <!-- Global quick-search (Ctrl+K) -->
                    <button type="button" class="admin-header-icon admin-qs-trigger"
                            id="adminQsBtn" onclick="adminQsOpen()"
                            title="<?php echo $adminT('खोज्नुहोस्', 'Search'); ?> (Ctrl+K)"
                            aria-label="<?php echo $adminT('खोज्नुहोस्', 'Search'); ?>">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="search"></i>
                        <span class="admin-qs-kbd d-none d-xl-inline">⌘K</span>
                    </button>
                    <a href="<?php echo htmlspecialchars($adminLangToggleUrl, ENT_QUOTES, 'UTF-8'); ?>" class="admin-header-icon" title="<?php echo $adminT('भाषा परिवर्तन', 'Switch Language'); ?>" aria-label="<?php echo $adminT('भाषा', 'Language'); ?>">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="languages"></i>
                        <span class="admin-header-icon-label d-none d-xl-inline"><?php echo $adminLangBadge; ?></span>
                    </a>
                    <a href="<?php echo ADMIN_URL; ?>settings.php#version"
                       class="admin-header-icon d-none d-lg-inline-flex"
                       title="<?php echo $adminT('संस्करण', 'Version'); ?> v<?php echo htmlspecialchars($siteVer); ?>">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="git-branch"></i>
                    </a>

                    <!-- Notifications bell — clickable dropdown -->
                    <?php
                    $totalAlerts = array_sum($adminAlertCounts) + $unreadMessages;

                    /* Dropdown मा देखाउने items — label, count, link, icon, tone */
                    $notifItems = [
                        ['label'=>$adminT('अपठित सन्देश', 'Unread Messages'),     'count'=>$unreadMessages,                        'href'=>'messages.php',                       'icon'=>'fa-envelope',            'tone'=>'red'],
                        ['label'=>$adminT('KYC आवेदन', 'KYC Applications'),        'count'=>$adminAlertCounts['kyc'],               'href'=>'kyc-applications.php?status=pending', 'icon'=>'fa-id-card',             'tone'=>'orange'],
                        ['label'=>$adminT('KYC जोखिम समीक्षा', 'KYC Risk Review'), 'count'=>$adminAlertCounts['kyc_risk'],        'href'=>'kyc-risk-reviews.php',                'icon'=>'fa-shield-halved',       'tone'=>'amber'],
                        ['label'=>$adminT('ऋण आवेदन', 'Loan Applications'),         'count'=>$adminAlertCounts['loan'],              'href'=>'loan-applications.php?status=pending','icon'=>'fa-hand-holding-usd',   'tone'=>'amber'],
                        ['label'=>$adminT('खाता आवेदन', 'Account Applications'),       'count'=>$adminAlertCounts['account'],           'href'=>'account-applications.php?status=pending','icon'=>'fa-university',       'tone'=>'purple'],
                        ['label'=>$adminT('डिजिटल सेवा', 'Digital Services'),      'count'=>$adminAlertCounts['digital'],           'href'=>'digital-service-requests.php?status=pending','icon'=>'fa-mobile-alt', 'tone'=>'cyan'],
                        ['label'=>$adminT('भेटघाट / सहकारी भ्रमण', 'Appointments / Coop Visit'), 'count'=>$adminAlertCounts['appointment'], 'href'=>'appointments.php?status=pending', 'icon'=>'fa-calendar-check', 'tone'=>'red'],
                        ['label'=>$adminT('जागिर आवेदन', 'Job Applications'),      'count'=>$adminAlertCounts['job'],               'href'=>'job-applications.php?status=pending', 'icon'=>'fa-briefcase',           'tone'=>'green'],
                        ['label'=>$adminT('गुनासो', 'Grievances'),            'count'=>$adminAlertCounts['grievance'],         'href'=>'grievances.php?status=pending',       'icon'=>'fa-comment-dots',        'tone'=>'red'],
                        ['label'=>$adminT('सुझाव/प्रतिक्रिया', 'Feedback'), 'count'=>$adminAlertCounts['feedback'],          'href'=>'feedbacks.php?status=pending',        'icon'=>'fa-star',                'tone'=>'orange'],
                        ['label'=>$adminT('कल्याण दाबी', 'Welfare Claims'),      'count'=>$adminAlertCounts['welfare'],           'href'=>'welfare-claims.php?status=pending',   'icon'=>'fa-hand-holding-heart',  'tone'=>'teal'],
                        ['label'=>$adminT('लिलामी बिड', 'Auction Bids'),       'count'=>$adminAlertCounts['auction'],           'href'=>'auction-bids.php',                    'icon'=>'fa-gavel',               'tone'=>'slate'],
                        ['label'=>$adminT('भेन्डर आवेदन', 'Vendor Requests'),      'count'=>$adminAlertCounts['vendor'],            'href'=>'vendor-enlistment.php?status=pending','icon'=>'fa-store',               'tone'=>'blue'],
                        ['label'=>$adminT('सदस्य दर्ता', 'Member Registrations'),  'count'=>$adminAlertCounts['mem_pending'],       'href'=>'member-online-portal.php?status=pending','icon'=>'fa-user-plus',        'tone'=>'orange'],
                        ['label'=>$adminT('Password Reset', 'Password Reset'),    'count'=>$adminAlertCounts['mem_resets'],        'href'=>'member-online-portal.php?tab=resets', 'icon'=>'fa-key',                 'tone'=>'red'],
                        ['label'=>$adminT('उपस्थिति अनुरोध', 'Attendance Requests'), 'count'=>$adminAlertCounts['attend'] ?? 0,   'href'=>'program-attendance.php',              'icon'=>'fa-clipboard-check',     'tone'=>'cyan'],
                    ];
                    /* pending मात्र filter गर्ने — count > 0 भएका मात्र dropdown मा देखाउने */
                    $activeNotifs = array_values(array_filter($notifItems, function ($i) {
                        return ($i['count'] ?? 0) > 0;
                    }));
                    /* Login/session: नयाँ पेन्डिङ बढेको बेला मात्र popup (सजिलो notice) */
                    $lastSeenPending = (int)($_SESSION['admin_pending_last_total'] ?? 0);
                    $showPendingNotice = ($totalAlerts > 0) && ($totalAlerts > $lastSeenPending);
                    if ($showPendingNotice) {
                        $_SESSION['admin_pending_last_total'] = $totalAlerts;
                    }
                    ?>
                    <div class="header-item notif-wrapper">
                        <!-- Bell button — सधैं देखिन्छ, count > 0 भए red badge -->
                        <button type="button" class="notification-bell notif-toggle-btn"
                                title="<?php echo $adminT('सूचनाहरू', 'Notifications'); ?> — <?php echo $totalAlerts > 0 ? $totalAlerts . ' pending' : $adminT('सबै हेरिएको', 'All clear'); ?>"
                                aria-haspopup="true" aria-expanded="false">
                            <i class="lucide-icon" aria-hidden="true" data-lucide="bell"></i>
                            <?php if ($totalAlerts > 0): ?>
                            <span class="notif-count"><?php echo $totalAlerts; ?></span>
                            <?php endif; ?>
                        </button>

                        <!-- Dropdown panel -->
                        <div class="notif-dropdown" id="notifDropdown">
                            <div class="notif-dropdown-header">
                                <span><i class="lucide-icon me-1" aria-hidden="true" data-lucide="bell"></i><?php echo $adminT('सूचनाहरू', 'Notifications'); ?></span>
                                <?php if ($totalAlerts > 0): ?>
                                <span class="notif-total-badge"><?php echo $totalAlerts; ?> <?php echo $adminT('पेन्डिङ', 'pending'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="notif-dropdown-body">
                                <?php if (empty($activeNotifs)): ?>
                                <div class="notif-empty">
                                    <i class="lucide-icon text-success fa-2x mb-2" aria-hidden="true" data-lucide="circle-check"></i>
                                    <p class="mb-0"><?php echo $adminT('सबै हेरिएको छ!', 'All caught up!'); ?></p>
                                </div>
                                <?php else: ?>
                                <?php foreach ($activeNotifs as $ni): ?>
                                <a href="<?php echo ADMIN_URL . htmlspecialchars($ni['href']); ?>"
                                   class="notif-item">
                                    <span class="notif-item-icon notif-tone-<?php echo htmlspecialchars($ni['tone'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <i class="lucide-icon" aria-hidden="true" data-lucide="<?php echo htmlspecialchars(fa_to_lucide($ni['icon']), ENT_QUOTES, 'UTF-8'); ?>"></i>
                                    </span>
                                    <span class="notif-item-label"><?php echo $ni['label']; ?></span>
                                    <span class="notif-item-count notif-tone-bg-<?php echo htmlspecialchars($ni['tone'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo $ni['count']; ?>
                                    </span>
                                </a>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="notif-dropdown-footer">
                                <a href="<?php echo ADMIN_URL; ?>dashboard.php">
                                    <i class="lucide-icon me-1" aria-hidden="true" data-lucide="layout-grid"></i><?php echo $adminT('ड्यासबोर्ड हेर्नुहोस्', 'Open Dashboard'); ?>
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="header-item">
                        <div class="admin-info" title="<?php echo htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if ($adminPhoto !== ''): ?>
                                <img src="<?php echo htmlspecialchars($adminPhoto, ENT_QUOTES, 'UTF-8'); ?>" alt=""
                                     class="admin-avatar-sm"
                                     onerror="this.style.display='none';">
                            <?php else: ?>
                                <span class="admin-avatar-sm admin-avatar-fallback" aria-hidden="true"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></span>
                            <?php endif; ?>
                            <span class="admin-name admin-name-inline d-none"><?php echo htmlspecialchars($adminName); ?></span>
                            <i class="lucide-icon admin-info-chevron d-none d-md-inline" aria-hidden="true" data-lucide="chevron-down"></i>
                            <div class="admin-menu">
                                <div class="admin-menu-head">
                                    <strong><?php echo htmlspecialchars($adminName); ?></strong>
                                    <?php if (!empty($_SESSION['is_superadmin'])): ?>
                                    <span class="superadmin-pill"><?php echo $adminT('सुपर एडमिन', 'Super Admin'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($adminLastLoginLabel !== ''): ?>
                                    <small class="admin-menu-meta"><i class="lucide-icon" aria-hidden="true" data-lucide="clock"></i> <?php echo $adminT('अघिल्लो', 'Last'); ?>: <?php echo htmlspecialchars($adminLastLoginLabel); ?></small>
                                    <?php endif; ?>
                                </div>
                                <?php if (empty($_SESSION['is_superadmin'])): ?>
                                    <!-- Normal admin मात्र profile र change-password देख्छ -->
                                    <a href="profile.php"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i> <?php echo $adminT('प्रोफाइल', 'Profile'); ?></a>
                                    <a href="change-password.php"><i class="lucide-icon" aria-hidden="true" data-lucide="key"></i> <?php echo $adminT('पासवर्ड', 'Password'); ?></a>
                                <?php else: ?>
                                    <!-- Superadmin को लागि admin management link -->
                                    <a href="manage-admins.php"><i class="lucide-icon" aria-hidden="true" data-lucide="users-round"></i> <?php echo $adminT('Admin व्यवस्थापन', 'Admin Management'); ?></a>
                                <?php endif; ?>
                                <a href="logout.php"><i class="lucide-icon" aria-hidden="true" data-lucide="log-out"></i> <?php echo $adminT('लगआउट', 'Logout'); ?></a>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

          <!-- Breadcrumb hटाइएको — space बचाउनुस् -->

              <!-- Flash Messages — icon थपिएको, modern border-left style -->
              <?php
              $flash = getFlash();
              if ($flash):
                  $fType  = $flash['type'] === 'error' ? 'danger' : ($flash['type'] ?: 'info');
                  $fIcons = ['success' => 'fa-circle-check', 'danger' => 'fa-circle-xmark',
                             'warning' => 'fa-triangle-exclamation', 'info' => 'fa-circle-info'];
                  $fIcon  = $fIcons[$fType] ?? 'fa-circle-info';
              ?>
              <div class="alert alert-<?php echo $fType; ?> alert-dismissible fade show mx-3 mt-3" role="alert">
                  <i class="lucide-icon fa-fw flex-shrink-0" aria-hidden="true" data-lucide="<?php echo htmlspecialchars(fa_to_lucide($fIcon), ENT_QUOTES, 'UTF-8'); ?>"></i>
                  <span><?php echo htmlspecialchars($flash['message']); ?></span>
                  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
              </div>
              <?php endif; ?>

              <?php if (!empty($showPendingNotice) && !empty($activeNotifs)): ?>
              <div class="modal fade" id="adminPendingNoticeModal" tabindex="-1" aria-labelledby="adminPendingNoticeTitle" aria-hidden="true">
                  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                      <div class="modal-content border-0 shadow">
                          <div class="modal-header text-white" style="background:linear-gradient(135deg,#b91c1c,#ef4444);">
                              <h5 class="modal-title" id="adminPendingNoticeTitle">
                                  <i class="fas fa-bell me-2"></i><?php echo $adminT('पेन्डिङ अनुरोधहरू', 'Pending Requests'); ?>
                                  <span class="badge bg-light text-danger ms-2"><?php echo (int)$totalAlerts; ?></span>
                              </h5>
                              <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                          </div>
                          <div class="modal-body">
                              <p class="text-muted small mb-3"><?php echo $adminT('तलका मेनुमा नयाँ अनुरोध आएका छन् — हेरेर कारबाही गर्नुहोस्।', 'New requests are waiting in the menus below — review and take action.'); ?></p>
                              <div class="list-group list-group-flush">
                                  <?php foreach ($activeNotifs as $ni): ?>
                                  <a href="<?php echo ADMIN_URL . htmlspecialchars($ni['href'], ENT_QUOTES, 'UTF-8'); ?>"
                                     class="list-group-item list-group-item-action d-flex align-items-center gap-3 px-0">
                                      <span class="rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                            style="width:36px;height:36px;background:rgba(239,68,68,.12);color:#b91c1c;">
                                          <i class="fas <?php echo htmlspecialchars($ni['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                                      </span>
                                      <span class="flex-grow-1 fw-semibold"><?php echo $ni['label']; ?></span>
                                      <span class="badge rounded-pill" style="background:#ef4444;"><?php echo (int)$ni['count']; ?></span>
                                  </a>
                                  <?php endforeach; ?>
                              </div>
                          </div>
                          <div class="modal-footer">
                              <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal"><?php echo $adminT('पछि हेर्छु', 'Later'); ?></button>
                              <a href="<?php echo ADMIN_URL; ?>dashboard.php" class="btn btn-danger btn-sm">
                                  <i class="fas fa-gauge-high me-1"></i><?php echo $adminT('ड्यासबोर्ड', 'Dashboard'); ?>
                              </a>
                          </div>
                      </div>
                  </div>
              </div>
              <script>
              document.addEventListener('DOMContentLoaded', function () {
                  var el = document.getElementById('adminPendingNoticeModal');
                  if (!el || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
                  try { new bootstrap.Modal(el).show(); } catch (e) {}
              });
              </script>
              <?php endif; ?>

          <!-- Page content wrapper — admin-footer.php मा </div> छ -->
          <div class="page-content">

          <!-- Page content starts here -->

    <script>
    // ── Collapsible Nav Groups ──
    document.addEventListener('DOMContentLoaded', function () {
        var headers = document.querySelectorAll('.nav-group-header');
        headers.forEach(function (header) {
            var group = header.getAttribute('data-group');
            var submenu = document.getElementById('group-' + group);
            if (!submenu) return;

            header.addEventListener('click', function () {
                var isOpen = header.classList.contains('open');
                // Close all others
                headers.forEach(function (h) {
                    h.classList.remove('open');
                    var g = h.getAttribute('data-group');
                    var s = document.getElementById('group-' + g);
                    if (s) s.classList.remove('open');
                });
                // Toggle clicked
                if (!isOpen) {
                    header.classList.add('open');
                    submenu.classList.add('open');
                }
            });
        });
    });

    // ── Notification Bell Dropdown Toggle ──
    (function () {
        var btn   = document.querySelector('.notif-toggle-btn');
        var panel = document.getElementById('notifDropdown');
        if (!btn || !panel) return;

        /* Bell click — dropdown खोल्नुहोस् / बन्द गर्नुहोस् */
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = panel.style.display === 'block';
            panel.style.display = isOpen ? 'none' : 'block';
            btn.setAttribute('aria-expanded', String(!isOpen));
        });

        /* Panel बाहिर click गर्यो भने बन्द गर्नुहोस् */
        document.addEventListener('click', function (e) {
            if (!btn.contains(e.target) && !panel.contains(e.target)) {
                panel.style.display = 'none';
                btn.setAttribute('aria-expanded', 'false');
            }
        });

        /* Escape key — dropdown बन्द */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                panel.style.display = 'none';
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    })();

    // ── Global Client-side Table Search ──
    // Card भित्र .admin-table-subtab-content भए खुला उप-ट्याबको tbody मात्र
    function adminRunTableSearch(input) {
        if (!input || !input.classList.contains('admin-table-search')) return;
        var val = input.value.toLowerCase().trim();
        var card = input.closest('.card');
        var subContent = card ? card.querySelector('.admin-table-subtab-content') : null;
        var tbody;
        if (subContent) {
            var pane = subContent.querySelector('.tab-pane.active');
            tbody = pane ? pane.querySelector('tbody') : null;
        } else {
            var container = input.closest('.tab-pane') || input.closest('.card') || input.closest('[id$="-list"]') || document;
            tbody = container ? container.querySelector('tbody') : null;
        }
        if (!tbody) return;
        var rows = tbody.querySelectorAll('tr');
        var shown = 0;
        var total = 0;
        rows.forEach(function (row) {
            var cells = row.querySelectorAll('td');
            var isPlaceholder = cells.length === 1 && cells[0].getAttribute('colspan');
            if (isPlaceholder) {
                row.style.display = val ? 'none' : '';
                return;
            }
            total++;
            var match = !val || row.textContent.toLowerCase().includes(val);
            row.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        var badge = input.closest('.admin-search-wrap') ? input.closest('.admin-search-wrap').querySelector('.search-count') : null;
        if (badge) badge.textContent = shown + ' / ' + total;
    }
    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('admin-table-search')) {
            adminRunTableSearch(e.target);
        }
    });
    document.addEventListener('shown.bs.tab', function (e) {
        var t = e.target;
        if (!t || t.getAttribute('data-bs-toggle') !== 'tab') return;
        var sel = t.getAttribute('data-bs-target');
        if (!sel) return;
        var panel = document.querySelector(sel);
        if (!panel || !panel.closest('.admin-table-subtab-content')) return;
        var c = t.closest('.card');
        if (!c) return;
        var inp = c.querySelector('.admin-table-search');
        if (inp) adminRunTableSearch(inp);
    });
    function adminInitAllTableSearches() {
        document.querySelectorAll('.admin-table-search').forEach(adminRunTableSearch);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', adminInitAllTableSearches);
    } else {
        adminInitAllTableSearches();
    }
    </script>


    <!-- QS overlay: inline override ensures no backdrop-filter when hidden -->
    <style>
    #admin-qs-overlay { backdrop-filter: none !important; -webkit-backdrop-filter: none !important; }
    #admin-qs-overlay.qs-visible { backdrop-filter: blur(2px) !important; -webkit-backdrop-filter: blur(2px) !important; }
    </style>
    <!-- ── Quick-Search Overlay (Ctrl+K) ─────────────────────────── -->
    <div id="admin-qs-overlay" class="admin-qs-overlay" role="dialog" aria-modal="true"
         aria-label="Quick Search" onclick="if(event.target===this)adminQsClose()"
         style="display:none;">
        <div class="admin-qs-box">
            <div class="admin-qs-head">
                <i class="lucide-icon admin-qs-head-icon" aria-hidden="true" data-lucide="search"></i>
                <input type="search" id="admin-qs-input" class="admin-qs-input"
                       placeholder="<?php echo $adminT('सदस्य, KYC, सूचना खोज्नुहोस्…', 'Search members, KYC, notices…'); ?>"
                       autocomplete="off" spellcheck="false">
                <button type="button" class="admin-qs-close-btn" onclick="adminQsClose()"
                        title="Close (Esc)" aria-label="Close">&times;</button>
            </div>
            <div id="admin-qs-results" class="admin-qs-results">
                <div class="qs-hint">
                    <span><kbd>↑</kbd><kbd>↓</kbd> नेभिगेट</span>
                    <span><kbd>↵</kbd> खोल्नुहोस्</span>
                    <span><kbd>Esc</kbd> बन्द</span>
                </div>
            </div>
        </div>
    </div>

    <script>
    /* ── Admin Quick-Search ──────────────────────────────────────── */
    (function () {
        'use strict';
        var _api    = '<?php echo htmlspecialchars(ADMIN_URL, ENT_QUOTES, 'UTF-8'); ?>api/quick-search.php';
        var _timer  = null;
        var _focus  = -1;
        var _open   = false;

        /* ─ Open / Close ─ */
        window.adminQsOpen = function () {
            var _ov = document.getElementById('admin-qs-overlay');
            _ov.style.display = 'flex';
            requestAnimationFrame(function () { _ov.classList.add('qs-visible'); });
            document.getElementById('admin-qs-input').value = '';
            document.getElementById('admin-qs-results').innerHTML = _hintHtml();
            document.getElementById('admin-qs-input').focus();
            _focus = -1; _open = true;
        };
        window.adminQsClose = function () {
            var _ov = document.getElementById('admin-qs-overlay');
            if (_ov) {
                _ov.classList.remove('qs-visible');
                /* hide completely after CSS transition (180ms) — no backdrop-filter leaks */
                setTimeout(function () { if (_ov && !_ov.classList.contains('qs-visible')) { _ov.style.display = 'none'; } }, 220);
            }
            clearTimeout(_timer); _open = false; _focus = -1;
        };

        /* ─ Keyboard shortcuts ─ */
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                _open ? adminQsClose() : adminQsOpen();
                return;
            }
            /* Esc always closes — checked BEFORE _open guard so state drift cannot block it */
            if (e.key === 'Escape') { adminQsClose(); return; }
            if (!_open) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); _moveFocus(1); }
            else if (e.key === 'ArrowUp')  { e.preventDefault(); _moveFocus(-1); }
            else if (e.key === 'Enter')    { _openFocused(); }
        });

        /* ─ Live search on input ─ */
        document.addEventListener('input', function (e) {
            if (!e.target || e.target.id !== 'admin-qs-input') return;
            clearTimeout(_timer);
            var q = e.target.value.trim();
            if (q.length < 2) {
                document.getElementById('admin-qs-results').innerHTML = _hintHtml();
                _focus = -1; return;
            }
            document.getElementById('admin-qs-results').innerHTML =
                '<div class="qs-loading"><i class="lucide-icon animate-spin" aria-hidden="true" data-lucide="loader-circle"></i> खोज्दैछ…</div>';
            _timer = setTimeout(function () { _search(q); }, 280);
        });

        function _search(q) {
            fetch(_api + '?q=' + encodeURIComponent(q))
                .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                .then(_render)
                .catch(function () {
                    document.getElementById('admin-qs-results').innerHTML =
                        '<div class="qs-empty"><i class="lucide-icon" aria-hidden="true" data-lucide="circle-alert"></i> खोज्न सकिएन</div>';
                });
        }

        function _render(data) {
            var items = [];
            var html = '';
            function _group(list, lucideIcon, label, type) {
                if (!list || !list.length) return;
                html += '<div class="qs-group"><div class="qs-glabel"><i class="lucide-icon" aria-hidden="true" data-lucide="' + lucideIcon + '"></i> ' + label + '</div>';
                list.forEach(function (r) {
                    var bc = r.badge === 'approved' || r.badge === 'active' ? 'qs-b-green' :
                             r.badge === 'pending' ? 'qs-b-orange' : 'qs-b-gray';
                    html += '<div class="qs-item" data-url="<?php echo htmlspecialchars(rtrim(ADMIN_URL,'/').'/', ENT_QUOTES, 'UTF-8'); ?>' + _esc(r.url) + '" tabindex="-1">' +
                        '<span class="qs-item-type qs-t-' + type + '"><i class="lucide-icon" aria-hidden="true" data-lucide="' + lucideIcon + '"></i></span>' +
                        '<span class="qs-item-body"><span class="qs-item-title">' + _esc(r.title) + '</span>' +
                        (r.sub ? '<span class="qs-item-sub">' + _esc(r.sub) + '</span>' : '') + '</span>' +
                        (r.badge ? '<span class="qs-badge ' + bc + '">' + _esc(r.badge) + '</span>' : '') +
                        '<i class="lucide-icon qs-arrow" aria-hidden="true" data-lucide="chevron-right"></i></div>';
                    items.push(r);
                });
                html += '</div>';
            }
            _group(data.members, 'users',           '<?php echo $adminT('सदस्यहरू', 'Members'); ?>', 'member');
            _group(data.kyc,     'id-card',         'KYC <?php echo $adminT('आवेदन', 'Applications'); ?>', 'kyc');
            _group(data.notices, 'megaphone',        '<?php echo $adminT('सूचनाहरू', 'Notices'); ?>', 'notice');
            if (!html) {
                document.getElementById('admin-qs-results').innerHTML =
                    '<div class="qs-empty"><i class="lucide-icon" aria-hidden="true" data-lucide="circle-x"></i> <?php echo $adminT('कुनै नतिजा भेटिएन', 'No results found'); ?></div>';
                return;
            }
            document.getElementById('admin-qs-results').innerHTML = html;
            _focus = -1;
            /* attach events */
            document.querySelectorAll('#admin-qs-results .qs-item').forEach(function (el, i) {
                el.addEventListener('click', function () { window.location.href = el.dataset.url; });
                el.addEventListener('mouseenter', function () { _setFocus(i); });
            });
        }

        function _moveFocus(dir) {
            var all = document.querySelectorAll('#admin-qs-results .qs-item');
            if (!all.length) return;
            _focus = Math.max(0, Math.min(all.length - 1, _focus + dir));
            _setFocus(_focus);
        }
        function _setFocus(idx) {
            _focus = idx;
            document.querySelectorAll('#admin-qs-results .qs-item').forEach(function (el, i) {
                el.classList.toggle('qs-focused', i === idx);
            });
            var focused = document.querySelectorAll('#admin-qs-results .qs-item')[idx];
            if (focused) focused.scrollIntoView({ block: 'nearest' });
        }
        function _openFocused() {
            var focused = document.querySelectorAll('#admin-qs-results .qs-item')[_focus];
            if (focused && focused.dataset.url) window.location.href = focused.dataset.url;
        }
        function _hintHtml() {
            return '<div class="qs-hint"><span><kbd>↑</kbd><kbd>↓</kbd> नेभिगेट</span>' +
                   '<span><kbd>↵</kbd> खोल्नुहोस्</span><span><kbd>Esc</kbd> बन्द</span></div>';
        }
        function _esc(s) {
            return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
    })();
    </script>

    <script src="../assets/js/coop-mobile.js?v=6.5" defer></script>
