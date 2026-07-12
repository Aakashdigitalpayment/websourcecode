<?php
require_once __DIR__ . '/config.php';
if (file_exists(__DIR__ . '/member-auth.php')) {
    require_once __DIR__ . '/member-auth.php';
}

if (function_exists('site_license_public_guard')) {
    site_license_public_guard();
}

/* Member portal apply-frame iframe: हल्का UI, लोडर/नेभ नछोपोस् */
$__embed_frame = isset($_GET['embed']) && (string)$_GET['embed'] === '1';
$__ui_test_mode = isset($_GET['ui_test']) && (string)$_GET['ui_test'] === '1';
if ($__ui_test_mode && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Get site settings
$siteName = getSetting('site_name', 'आकाश सहकारी');
$siteNameEn = getSetting('site_name_en', 'Aakash Cooperative');
// PWA app name (admin-configurable, falls back to site name)
$pwaAppName   = trim((string) getSetting('pwa_app_name', ''));
if ($pwaAppName === '') $pwaAppName = $siteName;
$pwaShortName = trim((string) getSetting('pwa_short_name', ''));
if ($pwaShortName === '') $pwaShortName = $siteNameEn ?: $siteName;
$siteSlogan = getSetting('site_slogan', 'समुदायमा आधारित सहकारी संस्था');
$siteSloganEn = trim((string) getSetting('site_slogan_en', ''));
$phone = getSetting('phone', '061590067');
$mobile = getSetting('mobile', '9827157000');
$email = getSetting('email', 'info@sahakari.org.np');
$address = getSetting('address', 'काठमाडौं, नेपाल');
$facebookUrl = getSetting('facebook_url', '#');
$youtubeUrl = getSetting('youtube_url', '#');
$twitterUrl = getSetting('twitter_url', '');
$instagramUrl = getSetting('instagram_url', '');
$logo = function_exists('getLocalizedLogoPath')
    ? trim((string) getLocalizedLogoPath('assets/images/logo.png'))
    : trim((string) getSetting('site_logo', getSetting('logo', 'assets/images/logo.png')));
$mobileAppPhoto = getSetting('mobile_app_photo', 'assets/images/mobile-app.png');
$himalBg      = getSetting('himal_bg', '');
$himalOpacity = max(0, min(100, (int)(float)getSetting('himal_bg_opacity', '100')));
$visionMenuLabelNp = trim((string)getSetting('vision_content_title_np', 'हाम्रो दृष्टिकोण'));
$visionMenuLabelEn = trim((string)getSetting('vision_content_title_en', 'Our Vision'));
$missionMenuLabelNp = trim((string)getSetting('mission_content_title_np', 'हाम्रो लक्ष्य'));
$missionMenuLabelEn = trim((string)getSetting('mission_content_title_en', 'Our Mission'));
$valuesMenuLabelNp = trim((string)getSetting('values_content_title_np', 'मूल मान्यताहरू'));
$valuesMenuLabelEn = trim((string)getSetting('values_content_title_en', 'Core Values'));
$chairmanMenuLabelNp = trim((string)getSetting('chairman_message_title_np', 'अध्यक्षको सन्देश'));
$chairmanMenuLabelEn = trim((string)getSetting('chairman_message_title_en', "Chairman's Message"));
$ceoMenuLabelNp = trim((string)getSetting('ceo_message_title_np', 'प्रमुख कार्यकारी अधिकृतको सन्देश'));
$ceoMenuLabelEn = trim((string)getSetting('ceo_message_title_en', "CEO's Message"));
$visionMissionMenuNp = trim($visionMenuLabelNp . ' / ' . $missionMenuLabelNp);
$visionMissionMenuEn = trim($visionMenuLabelEn . ' / ' . $missionMenuLabelEn);

/* ── Shared DB handle for header queries ── */
try { $db = getDB(); } catch (Throwable $e) { $db = null; }
if ($db) {
    require_once __DIR__ . '/election-tables.php';
    ensureElectionTables($db);
}

/* ── Bell Notification: latest active notices ── */
$bellNotices    = [];
$bellNewCount   = 0;
try {
    $bellNotices = $db->query(
        "SELECT id, title, notice_date, attachment
         FROM notices WHERE is_active = 1
         ORDER BY id DESC LIMIT 6"
    )->fetchAll();
    /* count notices created within the last 30 days as "new" */
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    foreach ($bellNotices as $bn) {
        $noticeCreated = (string)($bn['created_at'] ?? '');
        if ($noticeCreated !== '' && substr($noticeCreated, 0, 10) >= $thirtyDaysAgo) {
            $bellNewCount++;
        }
    }
    /* Fallback: if no created_at data, treat top 2 as new */
    if ($bellNewCount === 0 && count($bellNotices) > 0) {
        $bellNewCount = min(count($bellNotices), 2);
    }
} catch (Throwable $e) { $bellNotices = []; $bellNewCount = 0; }

/* ── Navbar dropdown: admin बाट select गरिएका committees मात्र देखाउने ── */
$navCommittees = [];
try {
    if ($db) {
        $navCommittees = $db->query(
            "SELECT id, name, name_np, menu_category_id FROM committee_types
             WHERE is_active = 1 AND show_in_navbar = 1
             ORDER BY display_order, id"
        )->fetchAll();
    }
} catch (Exception $e) {
    try {
        if ($db) {
            $navCommittees = $db->query(
                "SELECT id, name, name_np FROM committee_types
                 WHERE is_active = 1 AND show_in_navbar = 1
                 ORDER BY display_order, id"
            )->fetchAll();
            foreach ($navCommittees as &$_ncRow) { $_ncRow['menu_category_id'] = null; }
            unset($_ncRow);
        }
    } catch (Exception $e2) { $navCommittees = []; }
}

/* ── कर्मचारी वर्ग / समूह (team_staff_groups, show_in_nav) ── */
$navStaffGroups = [];
$navTeamMenuCategories = [];
try {
    require_once __DIR__ . '/team-staff-groups.php';
    require_once __DIR__ . '/team-menu-categories.php';
    if ($db) {
        ensureTeamStaffGroupsTable($db);
        ensureTeamMenuCategoriesTable($db);
        foreach (fetchTeamStaffGroups($db, true) as $_sg) {
            if (!empty($_sg['show_in_nav']) && !empty($_sg['slug'])) {
                $navStaffGroups[] = $_sg;
            }
        }
        $navTeamMenuCategories = fetchTeamMenuCategories($db, true);
    }
} catch (Throwable $e) {
    $navStaffGroups = [];
    $navTeamMenuCategories = [];
}

/* Fallback: seeded categories missing (DB issue) — keep old 2 parents */
if (empty($navTeamMenuCategories)) {
    $navTeamMenuCategories = [
        ['id' => 0, 'slug' => 'management', 'name_np' => 'व्यवस्थापन', 'name_en' => 'Management', 'icon' => 'fas fa-briefcase', 'source_type' => 'staff', 'include_contact_officers' => 1, 'include_board' => 0],
        ['id' => 0, 'slug' => 'committees', 'name_np' => 'समिति / उपसमिति', 'name_en' => 'Committees / Subcommittees', 'icon' => 'fas fa-sitemap', 'source_type' => 'committees', 'include_contact_officers' => 0, 'include_board' => 1],
    ];
}

/* ── Active cooperative programs count (for notices dropdown badge) ── */
$activeProgramCount = 0;
$hasRecentNotice = false;
$hasRecentProgram = false;
try {
    if ($db) {
        $activeProgramCount = (int)$db->query("SELECT COUNT(*) FROM upcoming_programs WHERE is_active=1")->fetchColumn();
        $recentNoticeStmt = $db->query("SELECT COUNT(*) FROM notices WHERE is_active=1 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $hasRecentNotice = ((int)($recentNoticeStmt ? $recentNoticeStmt->fetchColumn() : 0) > 0);
        $recentProgramStmt = $db->query("SELECT COUNT(*) FROM upcoming_programs WHERE is_active=1 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $hasRecentProgram = ((int)($recentProgramStmt ? $recentProgramStmt->fetchColumn() : 0) > 0);
    }
} catch (Exception $e) { $activeProgramCount = 0; }

/* ── निर्वाचन जानकारी — मेनुमा देखाउने चक्र (admin बाट show_in_navbar) ── */
$electionNavOn = false;
$hasRecentElectionMilestone = false;
try {
    if ($db) {
        $electionNavOn = ((int)$db->query(
            "SELECT COUNT(*) FROM election_cycles WHERE is_published = 1 AND show_in_navbar = 1"
        )->fetchColumn() > 0);
        if ($electionNavOn) {
            $emStmt = $db->query(
                "SELECT COUNT(*) FROM election_milestones em
                 INNER JOIN election_cycles ec ON ec.id = em.cycle_id
                 WHERE ec.is_published = 1 AND ec.show_in_navbar = 1 AND em.is_active = 1
                   AND (em.created_at >= DATE_SUB(NOW(), INTERVAL 21 DAY)
                        OR (em.event_date IS NOT NULL AND em.event_date >= DATE_SUB(CURDATE(), INTERVAL 21 DAY)))"
            );
            $hasRecentElectionMilestone = ((int)($emStmt ? $emStmt->fetchColumn() : 0) > 0);
        }
    }
} catch (Exception $e) {
    $electionNavOn = false;
    $hasRecentElectionMilestone = false;
}

if (is_file(__DIR__ . '/nav-menu-badges.php')) { require_once __DIR__ . '/nav-menu-badges.php'; }
if (is_file(__DIR__ . '/service-products-tables.php')) { require_once __DIR__ . '/service-products-tables.php'; }
$navMenuBadges = nav_get_public_submenu_badges($db);
$currentLang = getCurrentLang();

/* ── Services dropdown/mega-menu links (admin/services.php बाट) ── */
$navServiceLinks  = [];
$navServiceGroups = [];
$__navGrpLabels   = [];
$__navGrpIcons    = [];
try {
    if ($db) {
        if (function_exists('ensureServiceProductsTables')) { ensureServiceProductsTables($db); }
        try {
            // Join with service_categories for dynamic mega-menu grouping
            $svcRows = $db->query("
                SELECT s.id, s.title, s.title_en, s.title_np, s.icon,
                       s.service_category_id,
                       sc.id        AS cat_id,
                       sc.name      AS cat_name,
                       sc.name_en   AS cat_name_en,
                       sc.name_np   AS cat_name_np,
                       sc.icon      AS cat_icon,
                       sc.display_order AS cat_order
                FROM services s
                LEFT JOIN service_categories sc ON sc.id = s.service_category_id AND sc.is_active = 1
                WHERE s.is_active = 1
                ORDER BY sc.display_order, sc.id, s.display_order, s.id
                LIMIT 60
            ")->fetchAll();
        } catch (Throwable $e2) {
            // Fallback: columns not migrated yet
            $svcRows = $db->query("SELECT id, title, title_en, title_np, icon FROM services WHERE is_active = 1 ORDER BY display_order, id LIMIT 40")->fetchAll();
        }
        $serviceAnchorId = static function (array $service): string {
            $id = (int)($service['id'] ?? 0);
            $title = trim((string)($service['title'] ?? ''));
            $titleNp = trim((string)($service['title_np'] ?? ''));
            $label = $title !== '' ? $title : $titleNp;
            $norm = mb_strtolower($label, 'UTF-8');
            if (mb_strpos($norm, 'बचत') !== false || mb_strpos($norm, 'saving') !== false) return 'saving';
            if (mb_strpos($norm, 'ऋण') !== false || mb_strpos($norm, 'loan') !== false) return 'loan';
            if (mb_strpos($norm, 'रेमिट') !== false || mb_strpos($norm, 'remit') !== false) return 'remittance';
            $ascii = trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $title)), '-');
            return $ascii ?: ($id > 0 ? 'service-'.$id : 'service');
        };
        foreach ($svcRows as $sv) {
            $rawTitle = (string)(($currentLang === 'en' && !empty($sv['title_en'])) ? $sv['title_en'] : (!empty($sv['title_np']) ? $sv['title_np'] : ($sv['title'] ?? '')));
            $title = trim($rawTitle);
            if ($title === '') continue;
            $icon   = trim((string)($sv['icon'] ?? 'fas fa-star')) ?: 'fas fa-star';
            $anchor = $serviceAnchorId($sv);
            $catId  = (int)($sv['cat_id'] ?? 0);
            $navServiceLinks[] = ['title' => $title, 'icon' => $icon, 'anchor' => $anchor, 'cat_id' => $catId];
            if ($catId > 0) {
                if (!isset($navServiceGroups[$catId])) {
                    $catLabel = '';
                    if ($currentLang === 'en' && !empty($sv['cat_name_en'])) $catLabel = $sv['cat_name_en'];
                    elseif (!empty($sv['cat_name_np'])) $catLabel = $sv['cat_name_np'];
                    else $catLabel = $sv['cat_name'] ?? '';
                    $__navGrpLabels[$catId] = $catLabel;
                    $__navGrpIcons[$catId]  = $sv['cat_icon'] ?? 'fas fa-th-large';
                    $navServiceGroups[$catId] = [];
                }
                $navServiceGroups[$catId][] = ['title' => $title, 'icon' => $icon, 'anchor' => $anchor];
            }
        }
        /* Uncategorized services → auto "अन्य / Other" group so nothing gets hidden */
        $uncatKey = 0;
        foreach ($svcRows as $sv) {
            if ((int)($sv['cat_id'] ?? 0) > 0) continue;
            $rawTitle = (string)(($currentLang === 'en' && !empty($sv['title_en'])) ? $sv['title_en'] : (!empty($sv['title_np']) ? $sv['title_np'] : ($sv['title'] ?? '')));
            $title = trim($rawTitle);
            if ($title === '') continue;
            $icon   = trim((string)($sv['icon'] ?? 'fas fa-star')) ?: 'fas fa-star';
            $anchor = $serviceAnchorId($sv);
            if (!isset($navServiceGroups[$uncatKey])) {
                $__navGrpLabels[$uncatKey] = isEnglish() ? 'Other Services' : 'अन्य सेवाहरू';
                $__navGrpIcons[$uncatKey]  = 'fas fa-ellipsis-h';
                $navServiceGroups[$uncatKey] = [];
            }
            $navServiceGroups[$uncatKey][] = ['title' => $title, 'icon' => $icon, 'anchor' => $anchor];
        }
        /* Sort: categorized groups first (id > 0), uncategorized (key=0) last */
        uksort($navServiceGroups, function($a, $b) { return ($a === 0 ? 1 : ($b === 0 ? -1 : $a - $b)); });
    }
} catch (Throwable $e) {
    $navServiceLinks = []; $navServiceGroups = [];
}


$currentPage = getCurrentPage();
$L = getLangStrings();

$siteBrandName = ($currentLang === 'en' && trim($siteNameEn) !== '') ? $siteNameEn : $siteName;

$__defaultMetaDesc = ($currentLang === 'en')
    ? trim((string) getSetting('meta_description_en', ''))
    : trim((string) getSetting('meta_description', ''));
if ($__defaultMetaDesc === '') {
    $__defaultMetaDesc = ($currentLang === 'en' && $siteSloganEn !== '')
        ? $siteSloganEn
        : (string) $siteSlogan;
}
$__seoDesc = isset($pageDescription) && (string) $pageDescription !== ''
    ? (string) $pageDescription
    : $__defaultMetaDesc;

$__seoKeywords = trim((string) getSetting('meta_keywords', ''));
if ($__seoKeywords === '') {
    $__seoKeywords = $siteName . ', सहकारी, cooperative, बचत, ऋण, Nepal';
}

$__seoCanon = function_exists('seo_canonical_url') ? seo_canonical_url() : (rtrim(SITE_URL, '/') . '/');
$__seoOgImg = '';
if (isset($pageOgImage) && (string) $pageOgImage !== '') {
    $__seoOgImg = function_exists('seo_absolute_asset_url') ? seo_absolute_asset_url((string) $pageOgImage) : (SITE_URL . ltrim((string) $pageOgImage, '/'));
} else {
    $seoOgPath = trim((string) getSetting('seo_og_image', ''));
    $seoOgSafe = $seoOgPath !== '' && function_exists('safe_public_upload_path') ? safe_public_upload_path($seoOgPath) : '';
    if ($seoOgSafe !== '' && function_exists('seo_absolute_asset_url')) {
        $__seoOgImg = seo_absolute_asset_url($seoOgSafe);
    } else {
        $__seoOgImg = function_exists('seo_absolute_asset_url') ? seo_absolute_asset_url($logo) : (SITE_URL . ltrim($logo, '/'));
    }
}
$__htmlLang = ($currentLang === 'en') ? 'en' : 'ne';
$__ogLocale = ($currentLang === 'en') ? 'en_US' : 'ne_NP';
$__ogLocaleAlt = ($currentLang === 'en') ? 'ne_NP' : 'en_US';
$__robots = isset($robotsMeta) && (string) $robotsMeta !== '' ? (string) $robotsMeta : 'index, follow';
$__hrefLangSep = str_contains($__seoCanon, '?') ? '&' : '?';
$__hrefLangNe = $__seoCanon . $__hrefLangSep . 'lang=np';
$__hrefLangEn = $__seoCanon . $__hrefLangSep . 'lang=en';
?>
<!DOCTYPE html>
<html lang="<?php echo e($__htmlLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1a5f2a">
    <meta name="robots" content="<?php echo e($__robots); ?>">
    <meta name="description" content="<?php echo e($__seoDesc); ?>">
    <meta name="keywords" content="<?php echo e($__seoKeywords); ?>">
    <meta name="author" content="<?php echo e($siteBrandName); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($pwaShortName, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="<?= SITE_URL ?>assets/images/icon-192x192.png">
    <link rel="manifest" href="<?= SITE_URL ?>manifest.php">
    <meta name="pwa-app-name"   content="<?php echo htmlspecialchars($pwaAppName,   ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="pwa-short-name" content="<?php echo htmlspecialchars($pwaShortName, ENT_QUOTES, 'UTF-8'); ?>">
    <script>if(window.matchMedia('(display-mode:standalone)').matches||navigator.standalone)document.documentElement.classList.add('pwa-standalone');</script>
    <title><?php echo isset($pageTitle) ? e($pageTitle) . ' - ' : ''; ?><?php echo e($siteBrandName); ?></title>

    <!-- Canonical URL (query string बिना) -->
    <link rel="canonical" href="<?php echo e($__seoCanon); ?>">

    <!-- Open Graph / Social Media -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo e($siteBrandName); ?>">
    <meta property="og:url" content="<?php echo e($__seoCanon); ?>">
    <meta property="og:title" content="<?php echo isset($pageTitle) ? e($pageTitle) . ' - ' : ''; ?><?php echo e($siteBrandName); ?>">
    <meta property="og:description" content="<?php echo e($__seoDesc); ?>">
    <meta property="og:image" content="<?php echo e($__seoOgImg); ?>">
    <meta property="og:locale" content="<?php echo e($__ogLocale); ?>">
    <meta property="og:locale:alternate" content="<?php echo e($__ogLocaleAlt); ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo isset($pageTitle) ? e($pageTitle) . ' - ' : ''; ?><?php echo e($siteBrandName); ?>">
    <meta name="twitter:description" content="<?php echo e($__seoDesc); ?>">
    <meta name="twitter:image" content="<?php echo e($__seoOgImg); ?>">

    <!-- hreflang — NP/EN (यो साइट ?lang= ले भाषा बदल्छ) -->
    <link rel="alternate" hreflang="ne-NP" href="<?php echo e($__hrefLangNe); ?>" />
    <link rel="alternate" hreflang="en" href="<?php echo e($__hrefLangEn); ?>" />
    <link rel="alternate" hreflang="x-default" href="<?php echo e($__seoCanon); ?>" />

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?php echo SITE_URL . (function_exists('getSiteFaviconPath') ? getSiteFaviconPath() : 'public/icon.svg'); ?>">
    <link rel="icon" type="image/png" href="<?php echo SITE_URL . (function_exists('getSiteFaviconPath') ? getSiteFaviconPath() : 'assets/images/icon-192x192.png'); ?>" sizes="192x192">

    <!-- Preload Logo for faster display -->
    <link rel="preload" href="<?php echo SITE_URL . $logo; ?>" as="image">

    <!-- Google Fonts — Inter (body) + Plus Jakarta Sans (headings) + Noto Sans Devanagari (नेपाली) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&family=Inter:wght@300;400;500;600;700&family=Noto+Sans+Devanagari:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome Icons -->
    

    <!-- Bootstrap CSS -->
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">

    <!-- AOS Animation CSS -->
    <link rel="stylesheet" href="https://unpkg.com/aos@2.3.1/dist/aos.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Nepali Datepicker CSS (self-hosted v5) -->
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>assets/css/nepali.datepicker.min.css">

    <!-- Core CSS bundle (tokens + variables + animations + mobile + shared layout) -->
    <link rel="stylesheet" href="<?php echo SITE_URL; ?>assets/css/app-core.css?v=<?php echo @filemtime((defined("ROOT_PATH")?ROOT_PATH:dirname(__DIR__).DIRECTORY_SEPARATOR).'assets/css/app-core.css') ?: '1'; ?>">

    <?php if (function_exists('coopThemeHeadAssets')) { coopThemeHeadAssets('public', ['skip_fonts' => true]); } ?>

    <!-- Dynamic Theme Color -->
    <style>
        /* NOTE:
           Color tokens (primary/secondary/header/footer + text-on-*) are already injected
           by `assets/css/global-theme.php`. We intentionally DO NOT override them here,
           otherwise text contrast (e.g., footer links) can become unreadable when colors change. */
        /* Hide page loader after load */
        .page-loaded .page-loader {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }

        /* Expose logo URL to mobile drawer */
        :root {
            --pfl-mobile-logo: url('<?php echo SITE_URL . ltrim($logo, '/'); ?>');
            --pfl-site-name: '<?php echo addslashes($siteName); ?>';
        }

        /* ── Mobile Header Responsive Fixes ── */
        @media (max-width: 991px) {
            /* Top utility bar — hide most links on mobile to save space */
            .pfl-top-bar .pfl-quick-links { display: none !important; }
            .pfl-top-bar .container { justify-content: flex-end; }
            .pfl-top-bar { padding: 5px 0; }
            .pfl-top-bar .container { min-height: 42px; }

            /* Brand area — compact on mobile */
            .pfl-brand-area { padding: 8px 12px; flex: 1; min-width: 0; }
            .pfl-brand-content.has-logo .pfl-brand-logo {
                max-height: 44px !important;
                height: auto !important;
                width: auto !important;
                max-width: min(220px, calc(100vw - 120px)) !important;
                object-fit: contain !important;
            }
            .pfl-brand-content.no-logo .pfl-brand-logo { height: 42px !important; width: auto !important; }
            .pfl-brand-name-np { font-size: 1rem !important; }
            .pfl-brand-name-en, .pfl-brand-slogan { font-size: 0.68rem !important; }
            .pfl-since-badge { display: none !important; }

            /* Mobile toggle button — ensure visibility */
            .pfl-mobile-toggle {
                display: flex !important;
                align-items: center; justify-content: center;
                width: 44px; height: 44px;
                min-width: 44px; min-height: 44px;
                background: var(--primary-color);
                color: #fff;
                border: none;
                border-radius: 8px;
                font-size: 1.05rem;
                cursor: pointer;
                flex-shrink: 0;
                margin-left: 6px;
            }

            /* Mobile drawer base — DARK admin-style */
            .main-nav {
                position: fixed !important;
                top: 0; left: 0; right: auto;
                width: min(300px, 85vw);
                max-width: 300px;
                height: 100vh;
                height: 100dvh;
                z-index: 200010 !important;
                overflow-y: auto;
                overflow-x: hidden;
                -webkit-overflow-scrolling: touch;
                transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
                transform: translate3d(-110%, 0, 0);
                box-shadow: 4px 0 24px rgba(0,0,0,.25);
                padding: 0;
            }
            .main-nav.nav-open,
            .main-nav.open,
            .main-nav.active { transform: translate3d(0, 0, 0) !important; }
            .close-menu { display: flex !important; }

            /* ── Mobile drawer: nav items — white text on dark background ── */
            .main-nav .nav-menu > li > a {
                color: rgba(255,255,255,.90) !important;
                font-weight: 500;
            }
            .main-nav .nav-menu > li > a:hover,
            .main-nav .nav-menu > li > a:active,
            .main-nav .nav-menu > li.active > a {
                background: rgba(255,255,255,.12) !important;
                color: #fff !important;
            }
            .main-nav .nav-menu > li {
                border-bottom-color: rgba(255,255,255,.10) !important;
            }
            .main-nav .dropdown li > a {
                color: #1f2937 !important;
                background: transparent !important;
            }
            .main-nav .dropdown li > a:hover {
                background: #eef6f1 !important;
                color: #14532d !important;
            }
            .main-nav .close-menu {
                background: rgba(255,255,255,.15) !important;
                color: #fff !important;
            }

            /* FIX: Dropdown inside drawer — no absolute, full width, no content leak */
            .main-nav .dropdown,
            .main-nav .nav-menu .dropdown {
                position: static !important;
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                border: none !important;
                padding: 4px 0 !important;
                display: none;
            }
            .main-nav .has-dropdown.open > .dropdown,
            .main-nav .has-sub.open > .dropdown {
                display: block !important;
            }
            .main-nav .dropdown li > a {
                padding-left: 2.2rem !important;
            }
            /* Body scroll lock when drawer open */
            body.mobile-nav-open {
                overflow: hidden !important;
                position: fixed;
                width: 100%;
            }

            /* Overlay backdrop — below main-nav; backdrop-filter removed (mobile rendering fix) */
            .mobile-nav-backdrop {
                display: none;
                position: fixed; top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 199998;
                -webkit-backdrop-filter: none !important;
                backdrop-filter: none !important;
            }
            #pflMobileBackdrop { z-index: 200000 !important; }
            .mobile-nav-backdrop.active { display: block; }

            /* Main header flex layout fix */
            .pfl-main-header {
                display: flex !important;
                align-items: center !important;
                padding: 5px 10px !important;
                gap: 6px !important;
            }
            .pfl-nav-area {
                display: flex !important;
                align-items: center !important;
                justify-content: flex-end !important;
                gap: 6px !important;
                flex: 0 0 auto !important;
            }

            /* Mobile मा right utility icons neat/compact राख्ने */
            .pfl-top-right { display: flex !important; align-items: center; gap: 6px; }
            .pfl-top-right > li > a,
            .pfl-top-right .pfl-bell-btn {
                min-width: 34px;
                height: 34px;
                padding: 0 9px;
                border-radius: 8px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            .pfl-top-right .pfl-lang-wrap {
                display: inline-flex !important;
                align-items: center;
                gap: 4px;
                background: transparent;
                border: 0;
                box-shadow: none;
                border-radius: 999px;
                padding: 0;
            }
            .pfl-top-right .pfl-lang-wrap a {
                width: 30px !important;
                min-width: 30px !important;
                height: 30px !important;
                min-height: 30px !important;
                text-align: center;
                padding: 0 !important;
                border-radius: 999px;
                font-size: 10px;
                line-height: 1;
                font-weight: 800;
                letter-spacing: .1px;
                color: rgba(255,255,255,.92) !important;
                border: 1px solid rgba(255,255,255,.2);
                background: rgba(255,255,255,.10);
                transition: all .18s ease;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 0 !important;
            }
            .pfl-top-right .pfl-lang-wrap .pfl-lang-link .pfl-lang-dot {
                display: none !important;
            }
            .pfl-top-right .pfl-lang-wrap a.active {
                background: #fff !important;
                color: #14532d !important;
                border-color: rgba(255,255,255,.72);
                box-shadow: 0 1px 2px rgba(0,0,0,.15);
            }
            .pfl-top-right .pfl-lang-wrap a:not(.active):hover {
                background: rgba(255,255,255,.22) !important;
            }
            .pfl-top-right .pfl-dark-wrap > a {
                min-width: 30px !important;
                width: 30px !important;
                height: 30px !important;
                padding: 0 !important;
                border-radius: 8px !important;
            }
            .pfl-top-right .pfl-dark-wrap > a i { font-size: 13px; }
            .pfl-login-drop-wrap { position: relative; z-index: 210000; }
            .pfl-login-menu { z-index: 210001; right: 0; left: auto; }
            .pfl-top-right a,
            .pfl-top-right button,
            .pfl-mobile-toggle {
                -webkit-tap-highlight-color: transparent;
                outline: none !important;
                box-shadow: none !important;
            }
            .pfl-top-right a:focus-visible,
            .pfl-top-right button:focus-visible,
            .pfl-mobile-toggle:focus-visible {
                outline: 2px solid rgba(255,255,255,.45) !important;
                outline-offset: 1px;
            }

            /* Fixed mobile order: EN/NP -> Dark -> Bell -> Login */
            .pfl-top-right { display: flex !important; }
            .pfl-top-right .pfl-lang-wrap { order: 1; }
            .pfl-top-right .pfl-dark-wrap { order: 2; }
            .pfl-top-right .pfl-bell-wrap { order: 3; }
            .pfl-top-right .pfl-login-drop-wrap { order: 4; }

            /* ── Mobile drawer modernisation ────────────────────────── */

            /* Drawer header (close button area) — brand strip */
            .main-nav .close-menu {
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
                gap: 10px !important;
                padding: 14px 16px !important;
                font-size: 0.95rem !important;
                font-weight: 700 !important;
                letter-spacing: .01em !important;
                color: #fff !important;
                background: rgba(0,0,0,.22) !important;
                border-bottom: 1px solid rgba(255,255,255,.12) !important;
                border-radius: 0 !important;
                margin-bottom: 6px !important;
                width: 100% !important;
                cursor: pointer !important;
            }
            .main-nav .close-menu i {
                font-size: 1.1rem !important;
                opacity: .85;
            }

            /* Nav items — bigger touch targets, cleaner look */
            .main-nav .nav-menu > li > a {
                padding: 12px 44px 12px 16px !important;
                font-size: 0.97rem !important;
                font-weight: 500 !important;
                line-height: 1.4 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: flex-start !important;
                gap: 8px !important;
                border-bottom: 1px solid rgba(255,255,255,.07) !important;
                border-radius: 0 !important;
                transition: background .15s !important;
                text-align: left !important;
                white-space: normal !important;
                overflow-wrap: anywhere !important;
                position: relative !important;
            }
            .main-nav .nav-menu > li > a > .mnav-main-label {
                text-align: left !important;
                white-space: normal !important;
                overflow-wrap: anywhere !important;
            }
            .main-nav .nav-menu > li > a .mnav-main-icon,
            .main-nav .nav-menu > li > a > .lucide-icon:first-child,
            .main-nav .nav-menu > li > a > svg:first-child {
                width: 18px !important;
                min-width: 18px !important;
                height: 18px !important;
            }
            .main-nav .nav-menu > li.has-dropdown > a > .lucide-chevron-down,
            .main-nav .nav-menu > li.has-sub > a > .lucide-chevron-down,
            .main-nav .nav-menu > li.has-dropdown > a > .fa-chevron-down,
            .main-nav .nav-menu > li.has-sub > a > .fa-chevron-down,
            .main-nav .nav-menu > li.has-dropdown > a > i[data-lucide="chevron-down"],
            .main-nav .nav-menu > li.has-sub > a > i[data-lucide="chevron-down"] {
                position: absolute !important;
                right: 14px !important;
                top: 50% !important;
                transform: translateY(-50%) !important;
                margin: 0 !important;
            }
            .main-nav .nav-menu > li:last-child > a { border-bottom: none !important; }

            /* Dropdown sub-items */
            .main-nav .dropdown li > a {
                min-height: 42px !important;
                padding: 9px 10px 9px 11px !important;
                font-size: 0.86rem !important;
                border: 1px solid #e2ece6 !important;
                border-radius: 10px !important;
                background: #ffffff !important;
                display: flex !important;
                align-items: center !important;
                gap: 9px !important;
                color: #1f2937 !important;
                font-weight: 640 !important;
            }
            .main-nav .dropdown li + li > a { margin-top: 6px !important; }
            .main-nav .dropdown li:last-child > a { border-bottom: 1px solid #e2ece6 !important; }

            /* Active item highlight */
            .main-nav .nav-menu > li.active > a {
                background: rgba(255,255,255,.14) !important;
                color: #fff !important;
            }
            /* Dropdown container panel */
            .main-nav .dropdown {
                background: #f2f7f4 !important;
                margin: 8px 2px 2px !important;
                padding: 7px !important;
                border: 1px solid #d9e7df !important;
                border-radius: 14px !important;
                box-shadow: inset 0 1px 0 rgba(255,255,255,.75) !important;
            }

            /* ── dd-chevron-btn: disabled; parent row handles submenu toggle ── */
            .main-nav .dd-chevron-btn {
                display: none !important;
                visibility: hidden !important;
                pointer-events: none !important;
            }
            .main-nav .dd-chevron-btn:hover,
            .main-nav .dd-chevron-btn:active {
                background: transparent !important;
            }
            .main-nav .has-dropdown.open > .dd-chevron-btn .fa-chevron-down,
            .main-nav .has-sub.open > .dd-chevron-btn .fa-chevron-down {
                transform: rotate(180deg) !important;
            }
            .main-nav .has-dropdown > a { padding-right: 16px !important; }

            /* Ensure li is relative for absolute chevron button */
            .main-nav .has-dropdown,
            .main-nav .has-sub { position: relative !important; }

            /* Submenu open animation */
            .main-nav .has-dropdown.open > .dropdown,
            .main-nav .has-sub.open > .dropdown {
                display: block !important;
            }
        }

        @media (max-width: 575px) {
            /* Very small screens */
            .pfl-brand-content.has-logo .pfl-brand-logo {
                max-height: 38px !important;
                height: auto !important;
                width: auto !important;
                max-width: min(190px, calc(100vw - 112px)) !important;
            }
            .pfl-brand-content.no-logo .pfl-brand-logo { height: 36px !important; }
            .pfl-brand-name-np { font-size: 0.88rem !important; }
            .pfl-top-right { gap: 4px !important; }
            .pfl-top-right .pfl-lang-wrap { display: inline-flex !important; }
            .pfl-top-right .pfl-lang-wrap .pfl-lang-divider { display: none !important; }
            .pfl-top-right .pfl-lang-wrap { padding: 0 !important; gap: 4px !important; }
            .pfl-top-right .pfl-lang-wrap a {
                min-width: 40px !important;
                min-height: 40px !important;
                width: auto !important;
                height: auto !important;
                font-size: 11px !important;
                padding: 8px 6px !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
            }
            .pfl-top-right .pfl-lang-wrap .pfl-lang-link {
                gap: 0 !important;
                border-radius: 999px !important;
                min-height: 40px !important;
            }
            .pfl-top-right .pfl-lang-wrap .pfl-lang-link span:not(.pfl-lang-dot) {
                display: inline !important;
                font-size: 11px !important;
                line-height: 1 !important;
            }
            .pfl-top-right .pfl-login-toggle { padding: 0 10px; min-height: 40px; }
            .pfl-top-right .pfl-login-toggle .pfl-login-caret { display: none; }
            .pfl-top-right .pfl-login-toggle { font-size: 0; }
            .pfl-top-right .pfl-login-toggle i { font-size: 15px; margin: 0; }
            .pfl-top-right #topbarSearchBtn { display: none !important; }
            .pfl-mobile-toggle { width: 44px; height: 44px; min-width: 44px; min-height: 44px; }
            .pfl-main-header { padding: 4px 8px !important; }

            /* Login dropdown: mobile मा full visible panel (no awkward overlap/cutoff) */
            .pfl-top-bar { z-index: 300000 !important; }
            .pfl-login-drop-wrap { position: static !important; }
            .pfl-login-menu {
                position: fixed !important;
                top: calc(env(safe-area-inset-top, 0px) + 52px) !important;
                left: 8px !important;
                right: 8px !important;
                width: auto !important;
                max-width: none !important;
                max-height: min(72vh, 520px) !important;
                overflow-y: auto !important;
                z-index: 300010 !important;
            }
        }
    </style>
    <meta name="csrf-token" content="<?php echo generateCSRFToken(); ?>">
    <?php if (!empty($__embed_frame)): ?>
    <style id="public-embed-frame-css">
    /* Member portal भित्र iframe — mixed/ठूलो हेडर बिना फारम देखिने */
    body.embed-in-member-portal { overflow: auto !important; padding-top: 0 !important; }
    body.embed-in-member-portal .pfl-header-wrapper { display: none !important; }
    body.embed-in-member-portal .page-banner { display: none !important; }
    body.embed-in-member-portal .main-footer { display: none !important; }
    </style>
    <?php endif; ?>
<script src="<?= SITE_URL ?>assets/js/pwa-register.js" defer></script>
<script src="<?= SITE_URL ?>assets/js/pull-to-refresh.js?v=1.0" defer></script>
</head>
<?php $useHeaderV2 = true; ?>
<body class="<?php echo $useHeaderV2 ? 'header-v2' : ''; ?><?php echo !empty($__embed_frame) ? ' embed-in-member-portal' : ''; ?>">
    <a class="skip-link" href="#main-content"><?php echo isEnglish() ? 'Skip to content' : 'मुख्य सामग्रीमा जानुहोस्'; ?></a>
    <?php if (empty($__embed_frame)): ?>
    <!-- Page Loader -->
    <div class="page-loader" id="pageLoader">
        <div class="loader-inner">
            <div class="loader-spinner-wrap">
                <div class="loader-spinner"></div>
            </div>
            <div class="loader-progress">
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <div class="progress-text"><span id="progressPercent">0</span>%</div>
            </div>
            <div class="loader-text"><?php echo isEnglish() ? 'Loading...' : 'लोड हुँदैछ...'; ?></div>
        </div>
    </div>
    <?php endif; ?>

        <!-- ═══════════════════════════════════════════════
            HEADER v2 — Pokhara Finance Style
            Public pages now render only the v2 header path here.
            ═══════════════════════════════════════════════ -->

    <!-- PFL Sticky Wrapper — utility bar + main header stick together -->
    <div class="pfl-header-wrapper">
    <!-- PFL Top Utility Bar -->
    <div class="pfl-top-bar">
        <div class="container">
            <!-- Left: Quick Utility Links -->
            <ul class="pfl-quick-links">
                <li>
                    <a href="<?php echo SITE_URL; ?>auction.php">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="gavel"></i>
                        <?php echo isEnglish() ? 'Auction Portal' : 'लिलामी पोर्टल'; ?>
                    </a>
                </li>
                <li>
                    <a href="<?php echo SITE_URL; ?>downloads.php">
                        <i class="fas fa-download"></i>
                        <?php echo isEnglish() ? 'Downloads' : 'डाउनलोड'; ?>
                    </a>
                </li>
                <li>
                      <a href="<?php echo SITE_URL; ?>career.php">
                          <i class="lucide-icon" aria-hidden="true" data-lucide="briefcase"></i>
                          <?php echo isEnglish() ? 'Career' : 'बिज्ञापन'; ?>
                          <?php echo nav_submenu_count_badge_html($navMenuBadges['career_open']); ?>
                      </a>
                </li>
                <li>
                    <a href="<?php echo SITE_URL; ?>contact.php">
                        <i class="fas fa-envelope"></i>
                        <?php echo isEnglish() ? 'Contact Us' : 'सम्पर्क'; ?>
                    </a>
                </li>
                <li>
                    <a href="<?php echo SITE_URL; ?>digital-services.php">
                        <i class="fas fa-laptop"></i>
                        <?php echo isEnglish() ? 'Digital Services' : 'डिजिटल सेवा'; ?>
                    </a>
                </li>
                <li class="has-drop">
                    <a href="javascript:void(0);">
                        <i class="fas fa-th"></i>
                        <?php echo isEnglish() ? 'Quick Links' : 'छिटो लिंक'; ?>
                        <i class="fas fa-caret-down ms-1" style="font-size:10px;"></i>
                    </a>
                    <div class="pfl-drop">
                        <a href="<?php echo SITE_URL; ?>emi-calculator.php"><i class="fas fa-calculator me-1"></i><span class="pfl-drop-label"><?php echo isEnglish() ? 'EMI Calculator' : 'ईएमआई क्याल्कुलेटर'; ?></span></a>
                        <a href="<?php echo SITE_URL; ?>exchange-rate.php"><i class="fas fa-exchange-alt me-1"></i><span class="pfl-drop-label"><?php echo isEnglish() ? 'Exchange Rate' : 'विनिमय दर'; ?></span></a>
                        <a href="<?php echo SITE_URL; ?>date-converter.php"><i class="fas fa-calendar-alt me-1"></i><span class="pfl-drop-label"><?php echo isEnglish() ? 'Date Converter' : 'मिति परिवर्तन'; ?></span></a>
                        <a href="<?php echo SITE_URL; ?>partner-facilities.php"><i class="lucide-icon" aria-hidden="true" data-lucide="handshake"></i><span class="pfl-drop-label"><?php echo isEnglish() ? 'Partner Facilities' : 'अन्य सुविधा'; ?></span></a>
                        <a href="<?php echo SITE_URL; ?>application-tracker.php"><i class="lucide-icon" aria-hidden="true" data-lucide="search"></i><span class="pfl-drop-label"><?php echo isEnglish() ? 'Track Application' : 'आवेदन ट्र्याक'; ?></span></a>
                        <a href="<?php echo SITE_URL; ?>service-centers.php"><i class="fas fa-map-marker-alt me-1"></i><span class="pfl-drop-label"><?php echo isEnglish() ? 'Service Centers' : 'सेवा कार्यालयहरू'; ?></span></a>
                        <a href="<?php echo SITE_URL; ?>reports.php"><i class="lucide-icon" aria-hidden="true" data-lucide="chart-bar"></i><span class="pfl-drop-label"><?php echo isEnglish() ? 'Reports' : 'प्रतिवेदन'; ?></span></a>
                        <a href="<?php echo SITE_URL; ?>faqs.php"><i class="fas fa-question-circle me-1"></i><span class="pfl-drop-label"><?php echo isEnglish() ? 'FAQs' : 'प्रश्नोत्तर'; ?></span></a>
                    </div>
                </li>
            </ul>

            <!-- Right: Login + Language + Notification + Search -->
            <ul class="pfl-top-right">
                <!-- Login Dropdown -->
                <li class="pfl-login-btn pfl-login-drop-wrap">
                    <a href="javascript:void(0);" class="pfl-login-toggle" aria-haspopup="true" aria-expanded="false">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="user-circle"></i>
                        <?php echo isEnglish() ? 'Login' : 'लगिन'; ?>
                        <i class="fas fa-caret-down pfl-login-caret"></i>
                    </a>
                    <ul class="pfl-login-menu" role="menu">
                        <?php $ibUrl = getSetting('internet_banking_url',''); if($ibUrl): ?>
                        <li>
                            <a href="<?php echo $ibUrl; ?>" target="_blank" rel="noopener">
                                <span class="pfl-lm-icon pfl-lm-web"><i class="lucide-icon" aria-hidden="true" data-lucide="globe"></i></span>
                                <span class="pfl-lm-text">
                                    <strong><?php echo isEnglish() ? 'E‑banking login' : 'इ‑बैंकिङ लगिन'; ?></strong>
                                    <small><?php echo isEnglish() ? 'Secure web banking' : 'अनलाइन बैंकिङ प्रवेश'; ?></small>
                                </span>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php $psUrl = getSetting('play_store_url',''); if($psUrl): ?>
                        <li>
                            <a href="<?php echo $psUrl; ?>" target="_blank" rel="noopener">
                                <span class="pfl-lm-icon pfl-lm-android"><i class="fab fa-google-play"></i></span>
                                <span class="pfl-lm-text">
                                    <strong><?php echo isEnglish() ? 'Mobile app (Android)' : 'मोबाइल एप (एन्ड्रोइड)'; ?></strong>
                                    <small><?php echo isEnglish() ? 'Google Play' : 'गुगल प्ले बाट डाउनलोड'; ?></small>
                                </span>
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php $asUrl = getSetting('app_store_url',''); if($asUrl): ?>
                        <li>
                            <a href="<?php echo $asUrl; ?>" target="_blank" rel="noopener">
                                <span class="pfl-lm-icon pfl-lm-ios"><i class="fab fa-apple"></i></span>
                                <span class="pfl-lm-text">
                                    <strong><?php echo isEnglish() ? 'Mobile app (iOS)' : 'मोबाइल एप (आइओएस)'; ?></strong>
                                    <small><?php echo isEnglish() ? 'App Store' : 'एप स्टोर बाट डाउनलोड'; ?></small>
                                </span>
                            </a>
                        </li>
                        <?php endif; ?>
                        <li class="pfl-lm-divider"></li>
                        <!-- Member Portal -->
                        <li>
                            <?php
                            $isMemberLoggedIn = function_exists('memberIsLoggedIn') && memberIsLoggedIn();
                            $memberPortalHref = $isMemberLoggedIn ? SITE_URL . 'member/' : SITE_URL . 'member/login.php';
                            ?>
                            <a href="<?php echo $memberPortalHref; ?>">
                                <span class="pfl-lm-icon pfl-lm-member"><i class="lucide-icon" aria-hidden="true" data-lucide="user-check"></i></span>
                                <span class="pfl-lm-text">
                                    <strong><?php echo isEnglish() ? 'Member login' : 'सदस्य लगिन'; ?></strong>
                                    <small><?php echo $isMemberLoggedIn ? htmlspecialchars($_SESSION['member_name'] ?? 'Member') : (isEnglish() ? 'Sign in · New registration' : 'लगिन · नयाँ दर्ता'); ?></small>
                                </span>
                                <?php if ($isMemberLoggedIn && function_exists('getMemberUnreadCount') && ($unreadMemCount = getMemberUnreadCount($_SESSION['member_id'])) > 0): ?>
                                <span class="pfl-lm-badge"><?php echo $unreadMemCount > 9 ? '9+' : $unreadMemCount; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="pfl-lm-divider"></li>
                        <!-- Member Verify (public card verification — for hospitals, vendors, etc.) -->
                        <li>
                            <a href="<?php echo SITE_URL; ?>verify.php">
                                <span class="pfl-lm-icon pfl-lm-verify"><i class="lucide-icon" aria-hidden="true" data-lucide="shield-halved"></i></span>
                                <span class="pfl-lm-text">
                                    <strong><?php echo isEnglish() ? 'Member verify' : 'सदस्य परिचयपत्र जाँच'; ?></strong>
                                    <small><?php echo isEnglish() ? 'ID card check — code & CVV' : 'कोड र CVV ले प्रमाणित गर्नुहोस्'; ?></small>
                                </span>
                            </a>
                        </li>
                        <li class="pfl-lm-divider"></li>
                        <li>
                            <a href="<?php echo SITE_URL; ?>admin/">
                                <span class="pfl-lm-icon pfl-lm-admin"><i class="fas fa-user-shield"></i></span>
                                <span class="pfl-lm-text">
                                    <strong><?php echo isEnglish() ? 'Office login' : 'कार्यालय लगिन'; ?></strong>
                                    <small><?php echo isEnglish() ? 'Staff & admin access' : 'कर्मचारी / प्रशासन प्रवेश'; ?></small>
                                </span>
                            </a>
                        </li>
                    </ul>
                </li>
                <!-- Bell Notification Dropdown -->
                <li class="pfl-bell-wrap" id="pflBellWrap">
                    <button class="pfl-bell-btn" id="pflBellBtn"
                            aria-label="<?php echo isEnglish() ? 'Notifications' : 'सूचनाहरू'; ?>"
                            aria-expanded="false">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="bell"></i>
                        <?php if ($bellNewCount > 0): ?>
                        <span class="pfl-bell-badge"><?php echo $bellNewCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <!-- Dropdown panel -->
                    <div class="pfl-bell-drop" id="pflBellDrop" role="dialog" aria-label="सूचनाहरू">
                        <div class="pfl-bell-header">
                            <span><i class="lucide-icon" aria-hidden="true" data-lucide="bell"></i><?php echo isEnglish() ? 'Notices' : 'सूचनाहरू'; ?></span>
                            <?php if ($bellNewCount > 0): ?>
                            <span class="pfl-bell-new-chip"><?php echo $bellNewCount; ?> New</span>
                            <?php endif; ?>
                        </div>
                        <ul class="pfl-bell-list">
                        <?php if (empty($bellNotices)): ?>
                            <li class="pfl-bell-empty">
                                <i class="fas fa-bell-slash"></i>
                                <span><?php echo isEnglish() ? 'No notices available' : 'कुनै सूचना छैन'; ?></span>
                            </li>
                        <?php else: foreach ($bellNotices as $bni => $bn): ?>
                            <li class="pfl-bell-item<?php echo $bni < 3 ? ' pfl-bell-item--new' : ''; ?>">
                                <a href="<?php echo SITE_URL; ?>notices.php?id=<?php echo (int)$bn['id']; ?>">
                                    <span class="pfl-bell-item-icon">
                                        <?php if (!empty($bn['attachment'])): ?>
                                            <i class="fas fa-paperclip"></i>
                                        <?php else: ?>
                                            <i class="fas fa-file-alt"></i>
                                        <?php endif; ?>
                                    </span>
                                    <span class="pfl-bell-item-body">
                                        <span class="pfl-bell-item-title"><?php echo e(mb_substr($bn['title'], 0, 70) . (mb_strlen($bn['title']) > 70 ? '…' : '')); ?></span>
                                        <?php if (!empty($bn['notice_date'])): ?>
                                        <span class="pfl-bell-item-date"><?php echo e($bn['notice_date']); ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($bni < 3): ?>
                                    <span class="pfl-bell-new-dot" title="नयाँ">NEW</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; endif; ?>
                        </ul>
                        <div class="pfl-bell-footer">
                            <a href="<?php echo SITE_URL; ?>notices.php">
                                <?php echo isEnglish() ? 'View All Notices' : 'सबै सूचना हेर्नुहोस्'; ?>
                                <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                    </div>
                </li>
                <li class="pfl-lang-wrap">
                    <a href="?lang=en" class="pfl-lang-link <?php echo $currentLang === 'en' ? 'active' : ''; ?>"><span class="pfl-lang-dot" aria-hidden="true"></span><span>EN</span></a>
                    <span class="pfl-lang-divider">|</span>
                    <a href="?lang=np" class="pfl-lang-link <?php echo $currentLang === 'np' ? 'active' : ''; ?>"><span class="pfl-lang-dot" aria-hidden="true"></span><span>NP</span></a>
                </li>
                <li>
                    <a href="javascript:void(0);" id="topbarSearchBtn" title="<?php echo isEnglish() ? 'Search' : 'खोज्नुहोस्'; ?>">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="search"></i>
                    </a>
                </li>
                <li class="pfl-dark-wrap">
                    <a href="javascript:void(0);" id="topbarDarkModeToggle" title="<?php echo isEnglish() ? 'Dark Mode' : 'डार्क मोड'; ?>">
                        <i class="fas fa-moon"></i>
                    </a>
                </li>
                <li class="pfl-pwa-wrap">
                    <a href="javascript:void(0);" onclick="if(typeof pwaTriggerInstall==='function')pwaTriggerInstall();"
                       class="pwa-install-btn pfl-pwa-btn"
                       title="<?php echo isEnglish() ? 'Install App' : 'App Install गर्नुहोस्'; ?>">
                        <i class="fas fa-mobile-screen-button"></i>
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- PFL Main Header: Brand + Navigation (himal bg on nav/right side) -->
    <header class="pfl-main-header" id="pflMainHeader"<?php if ($himalBg): ?> style="--himal-bg:url('<?php echo SITE_URL . htmlspecialchars($himalBg, ENT_QUOTES, 'UTF-8'); ?>');--himal-opacity:<?php echo $himalOpacity; ?>"<?php endif; ?>>
        <!-- LEFT: Logo area — clean white background -->
        <div class="pfl-brand-area">
            <div class="pfl-himal-silhouette"></div>
            <a href="<?php echo SITE_URL; ?>" class="pfl-brand-content <?php echo !empty($logo) ? 'has-logo' : 'no-logo'; ?>">
                <?php if (!empty($logo)): ?>
                <img src="<?php echo SITE_URL . $logo; ?>"
                     alt="<?php echo e($siteNameEn); ?>"
                     class="pfl-brand-logo"
                     onerror="this.style.display='none';var p=this.parentElement;p.classList.remove('has-logo');p.classList.add('no-logo');if(!p.querySelector('.pfl-brand-logo-fallback')){var fb=document.createElement('div');fb.className='pfl-brand-logo-fallback';fb.innerHTML='<i class=\'fas fa-landmark\'></i>';p.insertBefore(fb,this);}">
                <?php else: ?>
                <div class="pfl-brand-logo-fallback"><i class="lucide-icon" aria-hidden="true" data-lucide="landmark"></i></div>
                <?php endif; ?>
                <?php if (empty($logo)): ?>
                <div class="pfl-brand-text">
                    <span class="pfl-brand-name-np"><?php echo e($siteName); ?></span>
                    <span class="pfl-brand-name-en"><?php echo e($siteNameEn); ?></span>
                    <span class="pfl-brand-slogan"><?php echo e($siteSlogan); ?></span>
                </div>
                <?php endif; ?>
                <?php
                $estYear = getSetting('established_year', '');
                if ($estYear):
                ?>
                <div class="pfl-since-badge">
                    <span class="since-text">Since</span>
                    <span class="since-year"><?php echo htmlspecialchars($estYear); ?></span>
                </div>
                <?php endif; ?>
            </a>
        </div>

        <!-- RIGHT: Navigation (reuses existing nav styles) -->
        <div class="pfl-nav-area">
            <!-- Mobile Toggle (visible < lg) -->
            <button type="button" class="pfl-mobile-toggle d-lg-none" id="mobileMenuToggle2" aria-label="Menu" aria-controls="mainNavV2" aria-expanded="false" data-testid="public-mobile-menu-toggle-button">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Navigation — same structure as original -->
            <nav class="main-nav" id="mainNavV2" aria-hidden="true" data-mobile-drawer="public">
                <button type="button" class="close-menu d-lg-none" id="closeMenuV2" data-testid="public-mobile-menu-close-button">
                    <span class="close-menu-label"><i class="fas fa-bars me-2"></i><?php echo isEnglish() ? 'Navigation' : 'मेनु'; ?></span>
                    <i class="fas fa-times"></i>
                </button>
                <ul class="nav-menu">
                    <li class="<?php echo $currentPage == 'index' ? 'active' : ''; ?>">
                        <a href="<?php echo SITE_URL; ?>index.php"><i class="lucide-icon mnav-main-icon" aria-hidden="true" data-lucide="landmark"></i><span class="mnav-main-label"><?php echo $L['home']; ?></span></a>
                    </li>
                    <li class="has-dropdown <?php echo $currentPage == 'about' ? 'active' : ''; ?>">
                        <a href="<?php echo SITE_URL; ?>about.php"><i class="lucide-icon mnav-main-icon" aria-hidden="true" data-lucide="info"></i><span class="mnav-main-label"><?php echo $L['about']; ?></span><i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i></a>
                        <ul class="dropdown">
                            <li><a href="<?php echo SITE_URL; ?>about.php"><i class="fas fa-info-circle"></i> <?php echo isEnglish() ? 'About Us' : 'हाम्रो बारेमा'; ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>about.php#history"><i class="lucide-icon" aria-hidden="true" data-lucide="clock"></i> <?php echo isEnglish() ? 'History' : 'हाम्रो इतिहास'; ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>about.php#vision"><i class="lucide-icon" aria-hidden="true" data-lucide="eye"></i> <?php echo htmlspecialchars(isEnglish() ? $visionMissionMenuEn : $visionMissionMenuNp, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>about.php#values"><i class="fas fa-heart"></i> <?php echo htmlspecialchars(isEnglish() ? $valuesMenuLabelEn : $valuesMenuLabelNp, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>about.php#chairman"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars(isEnglish() ? $chairmanMenuLabelEn : $chairmanMenuLabelNp, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>about.php#ceo-message"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i> <?php echo htmlspecialchars(isEnglish() ? $ceoMenuLabelEn : $ceoMenuLabelNp, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>institutional-profile.php"><i class="fas fa-building-columns"></i> <?php echo isEnglish() ? 'Institutional Profile' : 'संस्थागत प्रोफाइल'; ?></a></li>
                        </ul>
                    </li>
                    <li class="has-dropdown <?php echo $currentPage == 'services' ? 'active' : ''; ?>">
                        <a href="<?php echo SITE_URL; ?>services.php"><i class="lucide-icon mnav-main-icon" aria-hidden="true" data-lucide="briefcase"></i><span class="mnav-main-label"><?php echo $L['services']; ?></span><i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i></a>
                        <?php if (!empty($navServiceGroups)): ?>
                        <!-- Cascade: Menu → category submenu → hover shows mapped items -->
                        <ul class="dropdown">
                            <?php foreach ($__navGrpLabels as $__gk => $__gl):
                                if (empty($navServiceGroups[$__gk])) continue;
                                $__gsvcs = $navServiceGroups[$__gk]; ?>
                            <li class="has-sub">
                                <a href="#" aria-haspopup="true" aria-expanded="false">
                                    <i class="<?php echo htmlspecialchars($__navGrpIcons[$__gk] ?? 'fas fa-th-large'); ?>"></i>
                                    <span><?php echo htmlspecialchars($__gl); ?></span>
                                    <i class="fas fa-chevron-right nav-flyout-arrow" aria-hidden="true"></i>
                                </a>
                                <ul class="sub-menu">
                                    <?php foreach ($__gsvcs as $_gsvc): ?>
                                    <li>
                                        <a href="<?php echo SITE_URL; ?>services.php#<?php echo htmlspecialchars($_gsvc['anchor']); ?>">
                                            <i class="<?php echo htmlspecialchars($_gsvc['icon']); ?>"></i>
                                            <?php echo htmlspecialchars($_gsvc['title']); ?>
                                        </a>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                            <?php endforeach; ?>
                            <li>
                                <a href="<?php echo SITE_URL; ?>services.php">
                                    <i class="fas fa-th-list"></i>
                                    <?php echo isEnglish() ? 'All Services' : 'सबै सेवाहरू'; ?>
                                </a>
                            </li>
                        </ul>
                        <?php else: ?>
                        <ul class="dropdown">
                            <?php if (!empty($navServiceLinks)): ?>
                                <?php foreach ($navServiceLinks as $_svc): ?>
                                    <li><a href="<?php echo SITE_URL; ?>services.php#<?php echo htmlspecialchars($_svc['anchor']); ?>"><i class="<?php echo htmlspecialchars($_svc['icon']); ?>"></i> <?php echo htmlspecialchars($_svc['title']); ?></a></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li><a href="<?php echo SITE_URL; ?>services.php#saving"><i class="fas fa-piggy-bank"></i> <?php echo $L['saving']; ?></a></li>
                                <li><a href="<?php echo SITE_URL; ?>services.php#loan"><i class="fas fa-hand-holding-usd"></i> <?php echo $L['loan']; ?></a></li>
                                <li><a href="<?php echo SITE_URL; ?>services.php#remittance"><i class="fas fa-money-bill-wave"></i> <?php echo $L['remittance']; ?></a></li>
                            <?php endif; ?>
                        </ul>
                        <?php endif; ?>
                    </li>
                    <li class="<?php echo $currentPage == 'interest-rates' ? 'active' : ''; ?>">
                        <a href="<?php echo SITE_URL; ?>interest-rates.php"><i class="lucide-icon mnav-main-icon" aria-hidden="true" data-lucide="chart-line"></i><span class="mnav-main-label"><?php echo $L['interest_rates']; ?></span></a>
                    </li>
                    <li class="has-dropdown <?php echo in_array($currentPage, ['notices', 'cooperative-programs', 'election-information']) ? 'active' : ''; ?>">
                        <a href="<?php echo SITE_URL; ?>notices.php"><i class="lucide-icon mnav-main-icon" aria-hidden="true" data-lucide="bell"></i><span class="mnav-main-label"><?php echo $L['notices']; ?></span><i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i></a>
                        <ul class="dropdown">
                            <li><a href="<?php echo SITE_URL; ?>notices.php"><i class="fas fa-bullhorn"></i> <?php echo isEnglish() ? 'Latest Notices' : 'नवीनतम सूचना'; ?><?php if ($hasRecentNotice): ?><span class="nav-new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span><?php endif; ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>cooperative-programs.php"><i class="lucide-icon" aria-hidden="true" data-lucide="calendar-check"></i> <?php echo isEnglish() ? 'Cooperative Programs' : 'सहकारी कार्यक्रम'; ?><?php if ($hasRecentProgram): ?><span class="nav-new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span><?php endif; ?><?php if ($activeProgramCount > 0): ?><span class="nav-new-badge"><?php echo (int)$activeProgramCount; ?></span><?php endif; ?></a></li>
                            <?php if ($electionNavOn): ?>
                            <li><a href="<?php echo SITE_URL; ?>election-information.php"><i class="fas fa-check-to-slot"></i> <?php echo htmlspecialchars($L['election_information'] ?? 'निर्वाचन जानकारी'); ?><?php if ($hasRecentElectionMilestone): ?><span class="nav-new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span><?php endif; ?></a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                    <li class="<?php echo $currentPage == 'gallery' ? 'active' : ''; ?>">
                        <a href="<?php echo SITE_URL; ?>gallery.php"><i class="lucide-icon mnav-main-icon" aria-hidden="true" data-lucide="image"></i><span class="mnav-main-label"><?php echo $L['gallery']; ?></span></a>
                    </li>
                    <li class="has-dropdown <?php echo in_array($currentPage, ['team', 'committees']) ? 'active' : ''; ?>">
                        <a href="<?php echo SITE_URL; ?>team.php"><i class="lucide-icon mnav-main-icon" aria-hidden="true" data-lucide="users"></i><span class="mnav-main-label"><?php echo $L['team']; ?></span><i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i></a>
                        <ul class="dropdown">
                            <?php foreach ($navTeamMenuCategories as $_tmc):
                                $_tmcId = (int)($_tmc['id'] ?? 0);
                                $_tmcLabel = isEnglish()
                                    ? (($_tmc['name_en'] ?: $_tmc['name_np']) ?: '')
                                    : (($_tmc['name_np'] ?: $_tmc['name_en']) ?: '');
                                $_tmcIcon = (string)($_tmc['icon'] ?? 'fas fa-folder') ?: 'fas fa-folder';
                                $_tmcSource = (string)($_tmc['source_type'] ?? 'staff');
                            ?>
                            <li class="has-sub">
                                <a href="#" aria-haspopup="true" aria-expanded="false">
                                    <i class="<?php echo htmlspecialchars($_tmcIcon); ?>"></i>
                                    <span><?php echo htmlspecialchars($_tmcLabel); ?></span>
                                    <i class="fas fa-chevron-right nav-flyout-arrow" aria-hidden="true"></i>
                                </a>
                                <ul class="sub-menu">
                                    <?php if ($_tmcSource === 'staff'): ?>
                                        <?php if (!empty($_tmc['include_contact_officers'])): ?>
                                        <li>
                                            <a href="<?php echo SITE_URL; ?>team.php#contact-officers">
                                                <i class="fas fa-id-card-clip"></i>
                                                <?php echo isEnglish() ? 'Contact Officers' : 'सम्पर्क अधिकारी'; ?>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <?php foreach ($navStaffGroups as $_sg):
                                            if ($_tmcId > 0 && (int)($_sg['menu_category_id'] ?? 0) !== $_tmcId) continue;
                                            $_slug = (string)$_sg['slug'];
                                            $_anchor = teamStaffGroupAnchor($_slug);
                                            $_label = isEnglish()
                                                ? (($_sg['name_en'] ?: $_sg['name_np']) ?: $_slug)
                                                : (($_sg['name_np'] ?: $_sg['name_en']) ?: $_slug);
                                            $_icon = 'fas fa-user-tie';
                                            if ($_slug === 'top_management' || $_slug === 'admin') $_icon = 'fas fa-user-shield';
                                            elseif ($_slug === 'staff') $_icon = 'fas fa-users';
                                        ?>
                                        <li>
                                            <a href="<?php echo SITE_URL; ?>team.php#<?php echo htmlspecialchars($_anchor); ?>">
                                                <i class="<?php echo $_icon; ?>"></i>
                                                <?php echo htmlspecialchars($_label); ?>
                                            </a>
                                        </li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php if (!empty($_tmc['include_board'])): ?>
                                        <li>
                                            <a href="<?php echo SITE_URL; ?>team.php#board">
                                                <i class="fas fa-landmark"></i>
                                                <?php echo isEnglish() ? 'Board Committee' : 'सञ्चालक समिति'; ?>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        <?php
                                        $_catCommittees = [];
                                        foreach ($navCommittees as $_nc) {
                                            $ncid = (int)($_nc['menu_category_id'] ?? 0);
                                            if ($_tmcId <= 0 || $ncid === 0 || $ncid === $_tmcId) {
                                                $_catCommittees[] = $_nc;
                                            }
                                        }
                                        foreach ($_catCommittees as $_nc):
                                        ?>
                                        <li>
                                            <a href="<?php echo SITE_URL; ?>team.php?cat=committees&cmt=<?php echo (int)$_nc['id']; ?>#committees">
                                                <i class="fas fa-users-gear"></i>
                                                <?php echo isEnglish() ? htmlspecialchars($_nc['name']) : htmlspecialchars($_nc['name_np']); ?>
                                            </a>
                                        </li>
                                        <?php endforeach; ?>
                                        <?php if (empty($_catCommittees)): ?>
                                        <li>
                                            <a href="<?php echo SITE_URL; ?>committees.php">
                                                <i class="fas fa-sitemap"></i>
                                                <?php echo isEnglish() ? 'All Committees' : 'सबै समिति'; ?>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </ul>
                            </li>
                            <?php endforeach; ?>
                            <li>
                                <a href="<?php echo SITE_URL; ?>team.php">
                                    <i class="fas fa-th-list"></i>
                                    <?php echo isEnglish() ? 'All Team' : 'सम्पूर्ण टोली'; ?>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <li class="has-dropdown">
                        <a href="javascript:void(0);"><i class="lucide-icon mnav-main-icon" aria-hidden="true" data-lucide="newspaper"></i><span class="mnav-main-label"><?php echo isEnglish() ? 'More' : 'थप'; ?></span><i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i></a>
                        <ul class="dropdown">
                            <li><a href="<?php echo SITE_URL; ?>news.php"><i class="lucide-icon" aria-hidden="true" data-lucide="newspaper"></i> <?php echo isEnglish() ? 'News & Activities' : 'समाचार'; ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>career.php"><i class="lucide-icon" aria-hidden="true" data-lucide="briefcase"></i> <?php echo isEnglish() ? 'Career' : 'बिज्ञापन'; ?><?php echo nav_submenu_count_badge_html($navMenuBadges['career_open']); ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>reports.php"><i class="lucide-icon" aria-hidden="true" data-lucide="chart-line"></i> <?php echo isEnglish() ? 'Reports & Publications' : 'प्रतिवेदन'; ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>downloads.php"><i class="fas fa-download"></i> <?php echo isEnglish() ? 'Downloads' : 'डाउनलोड'; ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>service-centers.php"><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Service Centers' : 'सेवा कार्यालयहरू'; ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>faqs.php"><i class="fas fa-question-circle"></i> <?php echo isEnglish() ? 'FAQs' : 'प्रश्नोत्तर'; ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>member-survey.php"><i class="fas fa-comment-dots"></i> <?php echo isEnglish() ? 'Suggestion Box' : 'सुझाव बक्स'; ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>partner-facilities.php"><i class="lucide-icon" aria-hidden="true" data-lucide="handshake"></i> <?php echo isEnglish() ? 'Partner Facilities' : 'अन्य सुविधा'; ?></a></li>
                            <li><a href="<?php echo SITE_URL; ?>application-tracker.php"><i class="lucide-icon" aria-hidden="true" data-lucide="search"></i> <?php echo isEnglish() ? 'Track Application' : 'आवेदन ट्र्याक'; ?></a></li>
                        </ul>
                    </li>
                    <li class="<?php echo $currentPage == 'contact' ? 'active' : ''; ?>">
                        <a href="<?php echo SITE_URL; ?>contact.php"><i class="lucide-icon mnav-main-icon" aria-hidden="true" data-lucide="phone"></i><span class="mnav-main-label"><?php echo $L['contact']; ?></span></a>
                    </li>
                </ul>
            </nav>
        </div>
    </header>

    </div><!-- /pfl-header-wrapper -->
    <div id="main-content" tabindex="-1"></div>

    <!-- PFL Mobile Toggle + Scroll Shadow Script -->
    <script>
    (function(){
        /* Mobile toggle handled by v9-mobile-fix.js — inline duplicate removed in v9.4 */
        /* Scroll shadow on sticky wrapper */
        var pflWrapper = document.querySelector('.pfl-header-wrapper');
        if (pflWrapper) {
            window.addEventListener('scroll', function () {
                pflWrapper.classList.toggle('scrolled', window.scrollY > 10);
            }, { passive: true });
        }

        /* ── Bell Notification Dropdown ── */
        var bellBtn  = document.getElementById('pflBellBtn');
        var bellDrop = document.getElementById('pflBellDrop');
        var bellWrap = document.getElementById('pflBellWrap');
        if (bellBtn && bellDrop) {
            bellBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                var isOpen = bellDrop.classList.toggle('open');
                bellBtn.setAttribute('aria-expanded', isOpen);
            });
            /* Close on outside click */
            document.addEventListener('click', function(e) {
                if (bellWrap && !bellWrap.contains(e.target)) {
                    bellDrop.classList.remove('open');
                    bellBtn.setAttribute('aria-expanded', 'false');
                }
            });
            /* Close on Escape */
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    bellDrop.classList.remove('open');
                    bellBtn.setAttribute('aria-expanded', 'false');
                }
            });
        }

        /* ── Login Dropdown ── */
        (function() {
            var wrap   = document.querySelector('.pfl-login-drop-wrap');
            var toggle = document.querySelector('.pfl-login-toggle');
            if (!wrap || !toggle) return;
            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                /* Mobile: login open गर्दा drawer/menu side effects बन्द */
                if (typeof window.__pflMobileMenuClose === 'function') {
                    window.__pflMobileMenuClose();
                } else {
                    var nav = document.getElementById('mainNavV2');
                    var backdrop = document.getElementById('pflMobileBackdrop');
                    var menuToggle = document.getElementById('mobileMenuToggle2');
                    var menuIcon = menuToggle ? menuToggle.querySelector('i') : null;
                    if (nav) nav.classList.remove('nav-open', 'open', 'active');
                    if (backdrop) backdrop.classList.remove('active');
                    document.body.classList.remove('mobile-nav-open');
                    document.documentElement.classList.remove('mobile-nav-open');
                    if (menuToggle) {
                        menuToggle.classList.remove('is-open');
                        menuToggle.setAttribute('aria-expanded', 'false');
                    }
                    if (menuIcon) {
                        menuIcon.classList.remove('fa-xmark');
                        menuIcon.classList.add('fa-bars');
                    }
                }
                var open = wrap.classList.toggle('open');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function(e) {
                if (!wrap.contains(e.target)) {
                    wrap.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    wrap.classList.remove('open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        })();
    })();
    </script>

    <?php if (!$useHeaderV2): ?>
    <!-- Top Bar (OLD — hidden when header-v2 active) -->
    <div class="top-bar">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-8 col-md-12">
                    <ul class="top-info">
                        <li><i class="fas fa-phone-alt"></i> <?php echo $phone; ?></li>
                        <li><i class="fas fa-mobile-alt"></i> <?php echo $mobile; ?></li>
                        <li><i class="fas fa-envelope"></i> <?php echo $email; ?></li>
                        <li><i class="fas fa-map-marker-alt"></i> <?php echo $address; ?></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-md-12 text-lg-end">
                    <ul class="social-links">
                        <li class="lang-switch">
                            <a href="?lang=en" class="lang-btn <?php echo $currentLang === 'en' ? 'active' : ''; ?>">EN</a>
                            <span class="lang-divider">|</span>
                            <a href="?lang=np" class="lang-btn <?php echo $currentLang === 'np' ? 'active' : ''; ?>">NP</a>
                        </li>
                        <li class="internet-banking-btn">
                            <a href="<?php echo getSetting('internet_banking_url', '#'); ?>" target="_blank" title="<?php echo isEnglish() ? 'Internet Banking' : 'इन्टरनेट बैंकिङ'; ?>">
                                <i class="fas fa-laptop"></i>
                            </a>
                        </li>
                        <li><a href="<?php echo $facebookUrl; ?>" target="_blank"><i class="fab fa-facebook-f"></i></a></li>
                        <li><a href="<?php echo $youtubeUrl; ?>" target="_blank"><i class="fab fa-youtube"></i></a></li>
                        <li><a href="mailto:<?php echo $email; ?>"><i class="fas fa-envelope"></i></a></li>
                            <li class="topbar-search-btn d-none d-lg-inline-block">
                                <a href="javascript:void(0);" id="topbarSearchBtn" title="<?php echo isEnglish() ? 'Search' : 'खोज्नुहोस्'; ?>">
                                <i class="lucide-icon" aria-hidden="true" data-lucide="search"></i>
                            </a>
                        </li>
                            <li class="topbar-darkmode-btn d-none d-lg-inline-block">
                                <a href="javascript:void(0);" id="topbarDarkModeToggle" title="<?php echo isEnglish() ? 'Dark Mode' : 'डार्क मोड'; ?>">
                                <i class="fas fa-moon"></i>
                            </a>
                        </li>
                        <li class="topbar-pwa-btn d-none d-lg-inline-block">
                            <a href="javascript:void(0);" onclick="if(typeof pwaTriggerInstall==='function')pwaTriggerInstall();"
                               class="pwa-install-btn topbar-pwa-icon"
                               title="<?php echo isEnglish() ? 'Install App' : 'App Install गर्नुहोस्'; ?>">
                                <i class="fas fa-mobile-screen-button"></i>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Header -->
    <header class="main-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-4 col-md-5 col-8">
                    <div class="logo-banner">
                        <a href="<?php echo SITE_URL; ?>" class="logo-banner-link">
                            <img src="<?php echo SITE_URL . $logo; ?>?v=<?php echo time(); ?>" alt="<?php echo $siteNameEn; ?>" class="logo-banner-img">
                        </a>
                    </div>
                </div>
                <div class="col-lg-8 col-md-7 col-4">
                    <!-- Mobile Menu Toggle -->
                    <button class="mobile-menu-toggle d-lg-none" id="mobileMenuToggle" aria-label="<?php echo isEnglish() ? 'Open Menu' : 'मेनु खोल्नुहोस्'; ?>">
                        <i class="fas fa-bars"></i>
                    </button>

                    <!-- Navigation -->
                    <nav class="main-nav" id="mainNav">
                        <button class="close-menu d-lg-none" id="closeMenu" aria-label="Close" title="Close">
                            <i class="fas fa-times"></i>
                        </button>
                        <ul class="nav-menu">
                            <li class="<?php echo $currentPage == 'index' ? 'active' : ''; ?>">
                                <a href="<?php echo SITE_URL; ?>index.php"><?php echo $L['home']; ?></a>
                            </li>
                            <li class="has-dropdown <?php echo $currentPage == 'about' ? 'active' : ''; ?>">
                                <a href="<?php echo SITE_URL; ?>about.php"><?php echo $L['about']; ?> <i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i></a>
                                <ul class="dropdown">
                                    <li><a href="<?php echo SITE_URL; ?>about.php"><i class="fas fa-info-circle"></i> <?php echo isEnglish() ? 'About Us' : 'हाम्रो बारेमा'; ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>about.php#history"><i class="lucide-icon" aria-hidden="true" data-lucide="clock"></i> <?php echo isEnglish() ? 'History' : 'हाम्रो इतिहास'; ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>about.php#vision"><i class="lucide-icon" aria-hidden="true" data-lucide="eye"></i> <?php echo htmlspecialchars(isEnglish() ? $visionMissionMenuEn : $visionMissionMenuNp, ENT_QUOTES, 'UTF-8'); ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>about.php#values"><i class="fas fa-heart"></i> <?php echo htmlspecialchars(isEnglish() ? $valuesMenuLabelEn : $valuesMenuLabelNp, ENT_QUOTES, 'UTF-8'); ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>about.php#chairman"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars(isEnglish() ? $chairmanMenuLabelEn : $chairmanMenuLabelNp, ENT_QUOTES, 'UTF-8'); ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>about.php#ceo-message"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i> <?php echo htmlspecialchars(isEnglish() ? $ceoMenuLabelEn : $ceoMenuLabelNp, ENT_QUOTES, 'UTF-8'); ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>institutional-profile.php"><i class="fas fa-building-columns"></i> <?php echo isEnglish() ? 'Institutional Profile' : 'संस्थागत प्रोफाइल'; ?></a></li>
                                    <?php
                                    // Fetch dynamic pages that should show in about menu
                                    // Safe query with error handling for missing columns
                                    try {
                                        $db = getDB();
                                        // Check if show_in_menu column exists
                                        $checkCol = $db->query("SHOW COLUMNS FROM pages LIKE 'show_in_menu'");
                                        if ($checkCol && $checkCol->fetch() !== false) {
                                            $pagesStmt = $db->query("SELECT id, slug, title,
                                                COALESCE(title_en, '') as title_en,
                                                COALESCE(is_new, 0) as is_new,
                                                new_until
                                                FROM pages
                                                WHERE is_active = 1 AND show_in_menu = 1 AND menu_position = 'about'
                                                ORDER BY menu_order ASC LIMIT 5");
                                            if ($pagesStmt) {
                                                $menuPages = $pagesStmt->fetchAll();
                                                foreach ($menuPages as $mp):
                                                    $mpTitle = isEnglish() ? ($mp['title_en'] ?: $mp['title']) : ($mp['title'] ?: $mp['title_en']);
                                                    $isNewPage = !empty($mp['is_new']) && (!$mp['new_until'] || strtotime($mp['new_until']) >= time());
                                    ?>
                                    <li><a href="<?php echo SITE_URL; ?>page.php?slug=<?php echo htmlspecialchars($mp['slug']); ?>"><?php echo htmlspecialchars($mpTitle); ?><?php if ($isNewPage): ?><span class="nav-new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span><?php endif; ?></a></li>
                                    <?php endforeach; } } } catch (Exception $e) { /* Columns may not exist yet - safe to ignore */ } ?>
                                </ul>
                            </li>
                            <li class="has-dropdown <?php echo $currentPage == 'services' ? 'active' : ''; ?>">
                                <a href="<?php echo SITE_URL; ?>services.php"><?php echo $L['services']; ?> <i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i></a>
                                <ul class="dropdown">
                                    <?php if (!empty($navServiceLinks)): ?>
                                        <?php foreach ($navServiceLinks as $_svc): ?>
                                            <li><a href="<?php echo SITE_URL; ?>services.php#<?php echo htmlspecialchars($_svc['anchor']); ?>"><i class="<?php echo htmlspecialchars($_svc['icon']); ?>"></i> <?php echo htmlspecialchars($_svc['title']); ?></a></li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li><a href="<?php echo SITE_URL; ?>services.php#saving"><i class="fas fa-piggy-bank"></i> <?php echo $L['saving']; ?></a></li>
                                        <li><a href="<?php echo SITE_URL; ?>services.php#loan"><i class="fas fa-hand-holding-usd"></i> <?php echo $L['loan']; ?></a></li>
                                        <li><a href="<?php echo SITE_URL; ?>services.php#remittance"><i class="fas fa-money-bill-wave"></i> <?php echo $L['remittance']; ?></a></li>
                                    <?php endif; ?>
                                    <?php
                                    // Fetch dynamic pages for services menu
                                    try {
                                        $db = getDB();
                                        $checkCol = $db->query("SHOW COLUMNS FROM pages LIKE 'show_in_menu'");
                                        if ($checkCol && $checkCol->fetch() !== false) {
                                            $servicesPagesStmt = $db->query("SELECT id, slug, title,
                                                COALESCE(title_en, '') as title_en,
                                                COALESCE(is_new, 0) as is_new,
                                                new_until
                                                FROM pages
                                                WHERE is_active = 1 AND show_in_menu = 1 AND menu_position = 'services'
                                                ORDER BY menu_order ASC LIMIT 5");
                                            if ($servicesPagesStmt) {
                                                $servicesMenuPages = $servicesPagesStmt->fetchAll();
                                                foreach ($servicesMenuPages as $sp):
                                                    $spTitle = isEnglish() ? ($sp['title_en'] ?: $sp['title']) : ($sp['title'] ?: $sp['title_en']);
                                                    $isNewPage = !empty($sp['is_new']) && (!$sp['new_until'] || strtotime($sp['new_until']) >= time());
                                    ?>
                                    <li><a href="<?php echo SITE_URL; ?>page.php?slug=<?php echo htmlspecialchars($sp['slug']); ?>"><?php echo htmlspecialchars($spTitle); ?><?php if ($isNewPage): ?><span class="nav-new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span><?php endif; ?></a></li>
                                    <?php endforeach; } } } catch (Exception $e) { /* Safe to ignore */ } ?>
                                </ul>
                            </li>
                            <li class="<?php echo $currentPage == 'interest-rates' ? 'active' : ''; ?>">
                                <a href="<?php echo SITE_URL; ?>interest-rates.php"><?php echo $L['interest_rates']; ?></a>
                            </li>
                            <li class="has-dropdown <?php echo in_array($currentPage, ['notices', 'cooperative-programs', 'election-information']) ? 'active' : ''; ?>">
                                <a href="<?php echo SITE_URL; ?>notices.php"><?php echo $L['notices']; ?> <i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i></a>
                                <ul class="dropdown">
                                    <li><a href="<?php echo SITE_URL; ?>notices.php"><i class="fas fa-bullhorn"></i> <?php echo isEnglish() ? 'Latest Notices' : 'नवीनतम सूचना'; ?><?php if ($hasRecentNotice): ?><span class="nav-new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span><?php endif; ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>cooperative-programs.php"><i class="lucide-icon" aria-hidden="true" data-lucide="calendar-check"></i> <?php echo isEnglish() ? 'Cooperative Programs' : 'सहकारी कार्यक्रम'; ?><?php if ($hasRecentProgram): ?><span class="nav-new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span><?php endif; ?><?php if ($activeProgramCount > 0): ?><span class="nav-new-badge"><?php echo (int)$activeProgramCount; ?></span><?php endif; ?></a></li>
                                    <?php if ($electionNavOn): ?>
                                    <li><a href="<?php echo SITE_URL; ?>election-information.php"><i class="fas fa-check-to-slot"></i> <?php echo htmlspecialchars($L['election_information'] ?? 'निर्वाचन जानकारी'); ?><?php if ($hasRecentElectionMilestone): ?><span class="nav-new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span><?php endif; ?></a></li>
                                    <?php endif; ?>
                                </ul>
                            </li>
                            <li class="<?php echo $currentPage == 'gallery' ? 'active' : ''; ?>">
                                <a href="<?php echo SITE_URL; ?>gallery.php"><?php echo $L['gallery']; ?></a>
                            </li>
                            <li class="has-dropdown <?php echo in_array($currentPage, ['team', 'committees']) ? 'active' : ''; ?>">
                                <a href="<?php echo SITE_URL; ?>team.php"><?php echo $L['team']; ?> <i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i></a>
                                <ul class="dropdown">
                                    <?php
                                    $_deskCatIdx = 0;
                                    foreach ($navTeamMenuCategories as $_tmc):
                                        $_tmcId = (int)($_tmc['id'] ?? 0);
                                        $_tmcLabel = isEnglish()
                                            ? (($_tmc['name_en'] ?: $_tmc['name_np']) ?: '')
                                            : (($_tmc['name_np'] ?: $_tmc['name_en']) ?: '');
                                        $_tmcSource = (string)($_tmc['source_type'] ?? 'staff');
                                        if ($_deskCatIdx > 0):
                                    ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <?php endif; $_deskCatIdx++; ?>
                                    <li class="dropdown-header"><?php echo htmlspecialchars($_tmcLabel); ?></li>
                                    <?php if ($_tmcSource === 'staff'): ?>
                                        <?php if (!empty($_tmc['include_contact_officers'])): ?>
                                    <li><a href="<?php echo SITE_URL; ?>team.php#contact-officers"><i class="fas fa-id-card-clip"></i> <?php echo isEnglish() ? 'Contact Officers' : 'सम्पर्क अधिकारी'; ?></a></li>
                                        <?php endif; ?>
                                        <?php foreach ($navStaffGroups as $_sg):
                                            if ($_tmcId > 0 && (int)($_sg['menu_category_id'] ?? 0) !== $_tmcId) continue;
                                            $_slug = (string)$_sg['slug'];
                                            $_anchor = teamStaffGroupAnchor($_slug);
                                            $_label = isEnglish()
                                                ? (($_sg['name_en'] ?: $_sg['name_np']) ?: $_slug)
                                                : (($_sg['name_np'] ?: $_sg['name_en']) ?: $_slug);
                                            $_icon = 'fas fa-user-tie';
                                            if ($_slug === 'top_management' || $_slug === 'admin') $_icon = 'fas fa-user-shield';
                                            elseif ($_slug === 'staff') $_icon = 'fas fa-users';
                                        ?>
                                    <li><a href="<?php echo SITE_URL; ?>team.php#<?php echo htmlspecialchars($_anchor); ?>"><i class="<?php echo $_icon; ?>"></i> <?php echo htmlspecialchars($_label); ?></a></li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php if (!empty($_tmc['include_board'])): ?>
                                    <li><a href="<?php echo SITE_URL; ?>team.php#board"><i class="fas fa-landmark"></i> <?php echo isEnglish() ? 'Board Committee' : 'सञ्चालक समिति'; ?></a></li>
                                        <?php endif; ?>
                                        <?php
                                        $_catCommittees = [];
                                        foreach ($navCommittees as $_nc) {
                                            $ncid = (int)($_nc['menu_category_id'] ?? 0);
                                            if ($_tmcId <= 0 || $ncid === 0 || $ncid === $_tmcId) {
                                                $_catCommittees[] = $_nc;
                                            }
                                        }
                                        foreach ($_catCommittees as $_nc):
                                        ?>
                                    <li><a href="<?php echo SITE_URL; ?>team.php?cat=committees&cmt=<?php echo (int)$_nc['id']; ?>#committees"><i class="fas fa-users-gear"></i> <?php echo isEnglish() ? htmlspecialchars($_nc['name']) : htmlspecialchars($_nc['name_np']); ?></a></li>
                                        <?php endforeach; ?>
                                        <?php if (empty($_catCommittees)): ?>
                                    <li><a href="<?php echo SITE_URL; ?>committees.php"><i class="fas fa-sitemap"></i> <?php echo isEnglish() ? 'All Committees' : 'सबै समिति'; ?></a></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                            <li class="has-dropdown">
                                <a href="javascript:void(0);"><?php echo isEnglish() ? 'More' : 'थप'; ?> <i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i></a>
                                <ul class="dropdown">
                                    <li><a href="<?php echo SITE_URL; ?>news.php"><i class="lucide-icon" aria-hidden="true" data-lucide="newspaper"></i> <?php echo isEnglish() ? 'News & Activities' : 'समाचार'; ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>career.php"><i class="lucide-icon" aria-hidden="true" data-lucide="briefcase"></i> <?php echo isEnglish() ? 'Career' : 'बिज्ञापन'; ?><?php echo nav_submenu_count_badge_html($navMenuBadges['career_open']); ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>reports.php"><i class="lucide-icon" aria-hidden="true" data-lucide="chart-line"></i> <?php echo isEnglish() ? 'Reports & Publications' : 'प्रतिवेदन तथा प्रकाशनहरू'; ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>downloads.php"><i class="fas fa-download"></i> <?php echo isEnglish() ? 'Downloads' : 'डाउनलोड'; ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>service-centers.php"><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Service Centers' : 'सेवा कार्यालयहरू'; ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>faqs.php"><i class="fas fa-question-circle"></i> <?php echo isEnglish() ? 'FAQs' : 'प्रश्नोत्तर'; ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>member-survey.php"><i class="fas fa-comment-dots"></i> <?php echo isEnglish() ? 'Suggestion Box' : 'सुझाव बक्स'; ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>partner-facilities.php"><i class="lucide-icon" aria-hidden="true" data-lucide="handshake"></i> <?php echo isEnglish() ? 'Partner Facilities' : 'अन्य सुविधा'; ?></a></li>
                                    <li><a href="<?php echo SITE_URL; ?>application-tracker.php"><i class="lucide-icon" aria-hidden="true" data-lucide="search"></i> <?php echo isEnglish() ? 'Track Application' : 'आवेदन ट्र्याक'; ?></a></li>
                                    <?php
                                    // Fetch dynamic pages for more menu
                                    try {
                                        $db = getDB();
                                        $checkCol = $db->query("SHOW COLUMNS FROM pages LIKE 'show_in_menu'");
                                        if ($checkCol && $checkCol->fetch() !== false) {
                                            $morePagesStmt = $db->query("SELECT id, slug, title,
                                                COALESCE(title_en, '') as title_en,
                                                COALESCE(is_new, 0) as is_new,
                                                new_until
                                                FROM pages
                                                WHERE is_active = 1 AND show_in_menu = 1 AND menu_position = 'more'
                                                ORDER BY menu_order ASC LIMIT 5");
                                            if ($morePagesStmt) {
                                                $moreMenuPages = $morePagesStmt->fetchAll();
                                                foreach ($moreMenuPages as $mmp):
                                                    $mmpTitle = isEnglish() ? ($mmp['title_en'] ?: $mmp['title']) : ($mmp['title'] ?: $mmp['title_en']);
                                                    $isNewPage = !empty($mmp['is_new']) && (!$mmp['new_until'] || strtotime($mmp['new_until']) >= time());
                                    ?>
                                    <li><a href="<?php echo SITE_URL; ?>page.php?slug=<?php echo htmlspecialchars($mmp['slug']); ?>"><?php echo htmlspecialchars($mmpTitle); ?><?php if ($isNewPage): ?><span class="nav-new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span><?php endif; ?></a></li>
                                    <?php endforeach; } } } catch (Exception $e) { /* Safe to ignore */ } ?>
                                </ul>
                            </li>
                            <li class="<?php echo $currentPage == 'contact' ? 'active' : ''; ?>">
                                <a href="<?php echo SITE_URL; ?>contact.php"><?php echo $L['contact']; ?></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </header>
    <?php endif; ?>

    <!-- PFL Mobile Nav Backdrop -->
    <div class="mobile-nav-backdrop" id="pflMobileBackdrop" aria-hidden="true" data-testid="public-mobile-menu-backdrop"></div>

    <style id="emg-mobile-nav-critical">
    @media (max-width: 991.98px) {
        body.header-v2 #mainNavV2.main-nav {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            bottom: 0 !important;
            width: min(82vw, 320px) !important;
            max-width: 320px !important;
            height: 100dvh !important;
            transform: translate3d(-105%,0,0) !important;
            transition: transform .24s cubic-bezier(.22,.61,.36,1) !important;
            background: #0b3f24 !important;
            color: #fff !important;
            z-index: 2147483001 !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding: 0 10px 18px !important;
            box-shadow: 18px 0 36px rgba(2,6,23,.34) !important;
            opacity: 1 !important;
            visibility: visible !important;
            display: block !important;
        }
        body.header-v2 #mainNavV2.nav-open,
        body.header-v2 #mainNavV2.open,
        body.header-v2 #mainNavV2.active { transform: translate3d(0,0,0) !important; }

        /* Hard guard: closed drawer must never leak clickable/visible close button */
        body.header-v2 #mainNavV2.main-nav:not(.nav-open):not(.open):not(.active) {
            pointer-events: none !important;
        }
        body.header-v2 #mainNavV2.main-nav:not(.nav-open):not(.open):not(.active) .close-menu {
            opacity: 0 !important;
            visibility: hidden !important;
            pointer-events: none !important;
        }
        body.header-v2 #mainNavV2.nav-open .close-menu,
        body.header-v2 #mainNavV2.open .close-menu,
        body.header-v2 #mainNavV2.active .close-menu {
            opacity: 1 !important;
            visibility: visible !important;
            pointer-events: auto !important;
        }

        body.header-v2 #pflMobileBackdrop {
            position: fixed !important;
            inset: 0 !important;
            background: rgba(2,6,23,.62) !important;
            z-index: 2147483000 !important;
            opacity: 0 !important;
            visibility: hidden !important;
            pointer-events: none !important;
            display: block !important;
        }
        body.header-v2 #pflMobileBackdrop.active {
            opacity: 1 !important;
            visibility: visible !important;
            pointer-events: auto !important;
        }
        body.header-v2.mobile-nav-open { overflow: hidden !important; }

        /* ── CRITICAL FIX: sticky .pfl-header-wrapper (z-index:1000) creates its own
           stacking context, trapping #mainNavV2 (drawer) inside it. The backdrop sits
           outside the wrapper at z-index 2147483000 → backdrop ends up on top of the
           drawer and dims it. When mobile nav opens, lift the wrapper above backdrop
           so the drawer becomes visible at full contrast. ── */
        body.header-v2.mobile-nav-open .pfl-header-wrapper {
            z-index: 2147483002 !important;
            isolation: isolate;
        }
    }
    </style>
    <script>
    (function(){
        function bindPflMobileMenu(){
            var toggle = document.getElementById('mobileMenuToggle2');
            var nav = document.getElementById('mainNavV2');
            var closeBtn = document.getElementById('closeMenuV2');
            var backdrop = document.getElementById('pflMobileBackdrop');
            var toggleIcon = toggle ? toggle.querySelector('i') : null;
            if (!toggle || !nav || toggle.dataset.emgMobileBound === '1') return;
            toggle.dataset.emgMobileBound = '1';
            toggle.dataset.v96Bound = '1';
            var savedY = 0;

            function syncToggleVisualState(){
                var isOpen = nav.classList.contains('nav-open') || nav.classList.contains('open') || nav.classList.contains('active');
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                toggle.classList.toggle('is-open', isOpen);
                if (toggleIcon) {
                    toggleIcon.classList.toggle('fa-xmark', isOpen);
                    toggleIcon.classList.toggle('fa-bars', !isOpen);
                }
            }
            function cleanupDdButtons() {
                nav.querySelectorAll(':scope .dd-chevron-btn').forEach(function(btn){ btn.remove(); });
            }
            nav.querySelectorAll('.has-dropdown, .has-sub').forEach(function(li){
                var link = li.querySelector(':scope > a');
                if (!link) return;
                // Remove side chevron buttons (they caused large controls and toggle conflicts).
                var existingBtns = li.querySelectorAll(':scope > .dd-chevron-btn');
                existingBtns.forEach(function(btn){ btn.remove(); });
                var inlineChevron = link.querySelector('.fa-chevron-down');
                if (inlineChevron) {
                    inlineChevron.style.display = 'inline-flex';
                    inlineChevron.style.marginLeft = 'auto';
                    inlineChevron.style.fontSize = '.82rem';
                    inlineChevron.style.opacity = '.9';
                }
            });
            cleanupDdButtons();
            setTimeout(cleanupDdButtons, 80);
            setTimeout(cleanupDdButtons, 220);
            function openNav(){
                cleanupDdButtons();
                savedY = window.scrollY || document.documentElement.scrollTop || 0;
                nav.classList.add('nav-open','open','active');
                nav.style.setProperty('transform', 'translate3d(0,0,0)', 'important');
                nav.style.setProperty('visibility', 'visible', 'important');
                nav.style.setProperty('pointer-events', 'auto', 'important');
                if (backdrop) backdrop.classList.add('active');
                document.body.classList.add('mobile-nav-open');
                document.documentElement.classList.add('mobile-nav-open');
                document.body.style.top = '-' + savedY + 'px';
                document.body.style.overflow = 'hidden';
                toggle.setAttribute('aria-expanded','true');
                toggle.classList.add('is-open');
                if (toggleIcon) {
                    toggleIcon.classList.remove('fa-bars');
                    toggleIcon.classList.add('fa-xmark');
                }
                nav.setAttribute('aria-hidden','false');
                syncToggleVisualState();
            }
            function closeNav(){
                nav.classList.remove('nav-open','open','active');
                nav.style.removeProperty('transform');
                nav.style.removeProperty('visibility');
                nav.style.removeProperty('pointer-events');
                if (backdrop) backdrop.classList.remove('active');
                document.body.classList.remove('mobile-nav-open');
                document.documentElement.classList.remove('mobile-nav-open');
                document.body.style.top = '';
                document.body.style.overflow = '';
                toggle.setAttribute('aria-expanded','false');
                toggle.classList.remove('is-open');
                if (toggleIcon) {
                    toggleIcon.classList.remove('fa-xmark');
                    toggleIcon.classList.add('fa-bars');
                }
                nav.setAttribute('aria-hidden','true');
                nav.querySelectorAll('.open').forEach(function(el){ el.classList.remove('open'); });
                nav.querySelectorAll('.dd-chevron-btn[aria-expanded="true"]').forEach(function(btn){ btn.setAttribute('aria-expanded','false'); });
                if (savedY) window.scrollTo(0, savedY);
                syncToggleVisualState();
            }
            toggle.addEventListener('click', function(e){
                e.preventDefault(); e.stopPropagation();
                (nav.classList.contains('nav-open') ? closeNav : openNav)();
            }, true);
            if (closeBtn) closeBtn.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); closeNav(); }, true);
            if (backdrop) backdrop.addEventListener('click', function(e){ e.preventDefault(); closeNav(); }, true);
            nav.addEventListener('click', function(e){
                if (window.innerWidth >= 992) return;
                var parentLink = e.target.closest('.has-dropdown > a, .has-sub > a');
                var li = parentLink ? parentLink.parentElement : null;
                if (!li || !nav.contains(li)) return;
                e.preventDefault(); e.stopPropagation();
                li.classList.toggle('open');
            }, true);
            document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeNav(); });
            window.addEventListener('pageshow', syncToggleVisualState);
            window.addEventListener('resize', function(){ if (window.innerWidth >= 992) closeNav(); else syncToggleVisualState(); });
            syncToggleVisualState();
            window.__pflMobileMenuOpen = openNav;
            window.__pflMobileMenuClose = closeNav;
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindPflMobileMenu);
        else bindPflMobileMenu();
    })();
    </script>
    <script src="<?php echo SITE_URL; ?>assets/js/coop-mobile.js?v=6.5" defer></script>
    <script>
    /* Cascade flyout: keep category parent links from jumping to # on desktop click */
    (function () {
        function bindCascadeParents() {
            document.querySelectorAll('#mainNavV2 .nav-menu .has-sub > a[href="#"]').forEach(function (link) {
                if (link.dataset.cascadeBound === '1') return;
                link.dataset.cascadeBound = '1';
                link.addEventListener('click', function (e) {
                    if (window.innerWidth >= 992) e.preventDefault();
                });
            });
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindCascadeParents);
        else bindCascadeParents();
    })();
    </script>

    <?php
    // Get notices for ticker - with safe query
    $tickerNotices = [];
    try {
        $db = getDB();
        $tickerStmt = $db->query("SELECT id, title, title_np FROM notices WHERE is_active = 1 ORDER BY id DESC LIMIT 10");
        if ($tickerStmt) $tickerNotices = $tickerStmt->fetchAll() ?: [];
    } catch (Exception $e) {
        $tickerNotices = [];
    }
    ?>

    <?php if (!empty($tickerNotices)): ?>
    <!-- Notice Ticker -->
    <div class="notice-ticker">
        <div class="container">
            <div class="ticker-wrapper">
                <div class="ticker-label">
                    <i class="fas fa-bullhorn"></i>
                    <span><?php echo isEnglish() ? 'NOTICES' : 'सूचना'; ?></span>
                </div>
                <div class="ticker-content">
                    <div class="ticker-scroll">
                        <?php foreach ($tickerNotices as $index => $tNotice): ?>
                            <a href="<?php echo SITE_URL; ?>notices.php?id=<?php echo $tNotice['id']; ?>" class="ticker-item">
                                <i class="fas fa-circle"></i>
                                <?php echo isEnglish() ? ($tNotice['title'] ?: $tNotice['title_np']) : ($tNotice['title_np'] ?: $tNotice['title']); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // Check for popup notices - fetch multiple for carousel
    $popupNotices = [];
    try {
        $db = getDB();
        $popupStmt = $db->query("SELECT * FROM notices WHERE is_popup = 1 AND is_active = 1 ORDER BY id DESC LIMIT 5");
        if ($popupStmt) $popupNotices = $popupStmt->fetchAll();
    } catch (Exception $e) {
        $popupNotices = [];
    }

    // Only output popup HTML if notices exist
    if (!empty($popupNotices)):
    $noticeIds = implode(',', array_column($popupNotices, 'id'));
    ?>
    <!-- Popup Notice Modal - Enhanced V3 with Carousel -->
    <div class="notice-popup-enhanced" id="noticePopup" data-notice-ids="<?php echo $noticeIds; ?>" data-testid="notice-popup-modal">
        <div class="popup-overlay" data-testid="notice-popup-overlay"></div>
        <div class="popup-dialog popup-v3">
            <div class="popup-top-accent"></div>
            <div class="popup-actions-top">
                <div class="popup-doc-actions" id="popupDocActions">
                    <!-- Dynamic PDF button will be shown here -->
                </div>
                <button type="button" class="popup-close-btn" id="popupClose" title="<?php echo isEnglish() ? 'Close' : 'बन्द गर्नुहोस्'; ?>" data-testid="notice-popup-close-button">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Carousel Container -->
            <div class="popup-carousel" id="popupCarousel">
                <?php foreach ($popupNotices as $index => $notice):
                    $attachPath = '';
                    if (!empty($notice['attachment'])) {
                        $attachPath = $notice['attachment'];
                        if (strpos($attachPath, 'uploads/') !== 0 && strpos($attachPath, '/') !== 0) {
                            $attachPath = 'uploads/notices/' . $attachPath;
                        }
                    }
                    /* photo-only popup: use popup_image if set, else attachment if it's an image */
                    $isPhotoOnly = !empty($notice['popup_photo_only']);
                    $photoOnlySrc = '';
                    if ($isPhotoOnly) {
                        if (!empty($notice['popup_image'])) {
                            $photoOnlySrc = $notice['popup_image'];
                        } elseif (!empty($attachPath) && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $attachPath)) {
                            $photoOnlySrc = $attachPath;
                        }
                    }
                ?>
                <div class="popup-slide <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>" data-attachment="<?php echo $attachPath; ?>" data-photo-only="<?php echo $isPhotoOnly ? '1' : '0'; ?>">
                    <?php if ($isPhotoOnly && $photoOnlySrc): ?>
                    <!-- Photo-only popup mode -->
                    <div class="popup-photo-only-wrap">
                        <img src="<?php echo htmlspecialchars(SITE_URL . ltrim($photoOnlySrc, '/'), ENT_QUOTES, 'UTF-8'); ?>"
                             alt="<?php echo htmlspecialchars(isEnglish() ? ($notice['title'] ?: '') : ($notice['title_np'] ?: ''), ENT_QUOTES, 'UTF-8'); ?>"
                             data-testid="notice-popup-photo-only-image">
                    </div>
                    <?php else: ?>
                    <div class="popup-header-text">
                        <span class="popup-badge animated"><?php echo isEnglish() ? 'Important Notice' : 'महत्त्वपूर्ण सूचना'; ?></span>
                    </div>
                    <div class="popup-content-body">
                        <h4 class="popup-title"><?php echo htmlspecialchars(isEnglish() ? ($notice['title'] ?: ($notice['title_np'] ?? '')) : (($notice['title_np'] ?? '') ?: $notice['title']), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <div class="popup-text">
                            <?php echo nl2br(htmlspecialchars(isEnglish() ? ($notice['content'] ?: ($notice['content_np'] ?? '')) : (($notice['content_np'] ?? '') ?: $notice['content']), ENT_QUOTES, 'UTF-8')); ?>
                        </div>
                        <?php if (!empty($notice['notice_date'])): ?>
                        <div class="popup-date">
                            <i class="fas fa-calendar-alt"></i>
                            <?php echo formatDate($notice['notice_date'], 'Y-m-d'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (count($popupNotices) > 1): ?>
            <!-- Navigation Controls -->
            <div class="popup-nav">
                <button type="button" class="popup-nav-btn popup-prev" id="popupPrev" title="<?php echo isEnglish() ? 'Previous' : 'अघिल्लो'; ?>" data-testid="notice-popup-prev-button">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <div class="popup-dots" id="popupDots">
                    <?php foreach ($popupNotices as $index => $notice): ?>
                    <button type="button" class="popup-dot <?php echo $index === 0 ? 'active' : ''; ?>" data-index="<?php echo $index; ?>" data-testid="notice-popup-dot-<?php echo $index + 1; ?>" aria-label="Notice <?php echo $index + 1; ?>"></button>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="popup-nav-btn popup-next" id="popupNext" title="<?php echo isEnglish() ? 'Next' : 'अर्को'; ?>" data-testid="notice-popup-next-button">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="chevron-right"></i>
                </button>
            </div>
            <!-- Progress Bar for Auto-rotate -->
            <div class="popup-progress">
                <div class="popup-progress-bar" id="popupProgressBar"></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
