<?php
/**
 * ════════════════════════════════════════════════════════════
 * PUBLIC MEMBER VERIFICATION — v10.2
 * ────────────────────────────────────────────────────────────
 * URL: /verify.php
 *
 * कुनै पनि व्यक्ति (हस्पिटल, पसल, अन्य संस्था) ले member ले
 * देखाएको ID Card को नाम, सदस्यता नं. र मोबाइल enter गरेर तुरुन्तै
 * सक्रिय सदस्य हो/होइन verify गर्न सक्छन्। मिल्दा गोप्य CVV
 * (नामको पहिलो ३ + सदस्यताको पछिल्लो ४) tracker जस्तै खुल्छ।
 * सुरक्षा: नाम + सदस्यता नं. + मोबाइल मिल्नुपर्छ — गलत व्यक्तिले दुरुपयोग गर्न नसकोस्।
 * पुराना कार्डका लागि Verification Code + CVV path पनि उपलब्ध छ।
 *
 * NOT venue program check-in: attendance = member/attend + member/scan
 * or admin program-registration-desk. Legacy attend.php redirects there.
 * ════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/card-verify-helpers.php';
require_once __DIR__ . '/includes/program-tables.php';
require_once __DIR__ . '/includes/member-partner-services-tables.php';
require_once __DIR__ . '/includes/partner-facilities-tables.php';
require_once __DIR__ . '/includes/contact-spam-guard.php';
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

$pdo = null;
$_dbError = '';
try {
    $pdo = getDB();
    if ($pdo) {
        if (function_exists('ensureProgramTables')) { ensureProgramTables($pdo); }
        if (function_exists('ensureMemberPartnerServicesTable')) { ensureMemberPartnerServicesTable($pdo); }
        if (function_exists('ensurePartnerFacilitiesTables')) { ensurePartnerFacilitiesTables($pdo); }
    }
} catch (\Throwable $_e) {
    $_dbError = 'DB जडान भएन। कृपया पछि प्रयास गर्नुहोस्।';
    error_log('[verify.php] DB error: ' . $_e->getMessage());
}

$result = null;
$code   = '';
$cvv    = '';
$verifyName = '';
$verifyMemberId = '';
$verifyMobile = '';
$verifyMode = 'name'; // name | legacy
$logSaved = false;
$logError = '';
$preregSaved = false;
$preregAlreadyRegistered = false;
$preregError = '';
$attendancePathClosedNotice = '';
$activePrograms = [];
$postCsrfError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $postCsrfError = isEnglish() ? 'Security validation failed. Please retry.' : 'सुरक्षा जाँच असफल भयो। कृपया फेरि प्रयास गर्नुहोस्।';
    }
    $verifyMode = (($_POST['verify_mode'] ?? '') === 'legacy') ? 'legacy' : 'name';
    $verifyName = trim((string)($_POST['member_name'] ?? ''));
    $verifyMemberId = trim((string)($_POST['member_id_no'] ?? ''));
    $verifyMobile = function_exists('memberSsotNormalizeMobile')
        ? memberSsotNormalizeMobile((string)($_POST['member_mobile'] ?? ''))
        : preg_replace('/\D+/', '', (string)($_POST['member_mobile'] ?? ''));
    $code = (string)($_POST['code'] ?? '');
    $code = function_exists('normalizeCardCode') ? normalizeCardCode($code) : $code;
    $cvv  = trim((string)($_POST['cvv']  ?? ''));
    if ($cvv === '' && isset($_POST['cvv_legacy'])) {
        $cvv = trim((string)$_POST['cvv_legacy']);
    }
    if (function_exists('normalizeCvvInput')) {
        $cvv = normalizeCvvInput($cvv);
    }
    $ip   = function_exists('coop_client_ip') ? coop_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    $runPrimaryVerify = static function () use ($pdo, $ip, &$verifyMode, &$verifyName, &$verifyMemberId, &$verifyMobile, &$code, &$cvv) {
        if (!$pdo) {
            return ['ok' => false, 'error' => 'DB जडान भएन। कृपया पछि प्रयास गर्नुहोस्।'];
        }
        if ($verifyMode === 'legacy') {
            return verifyCardCredentials($pdo, $code, $cvv, $ip);
        }
        return verifyCardByNameAndMemberId($pdo, $verifyName, $verifyMemberId, $ip, $cvv, (string)$verifyMobile);
    };

    /* (a) Service-log POST — verify पछि सेवा लिएको record छुट्टै submit */
    if ($postCsrfError !== '') {
        $result = ['ok' => false, 'error' => $postCsrfError];
    } elseif (($_POST['action'] ?? '') === 'log_service') {
        $mid       = (int)($_POST['member_id'] ?? 0);
        $cardNo    = trim((string)($_POST['member_card_no'] ?? ''));
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        $serviceNm = trim((string)($_POST['service_name'] ?? ''));
        $taken     = (isset($_POST['service_taken']) && $_POST['service_taken'] === 'yes');
        $note      = trim((string)($_POST['service_note'] ?? ''));
        $pin       = (string)($_POST['partner_pin'] ?? '');
        $verifyName = trim((string)($_POST['member_name'] ?? $verifyName));
        $verifyMemberId = trim((string)($_POST['member_id_no'] ?? $verifyMemberId));
        $verifyMobile = function_exists('memberSsotNormalizeMobile')
            ? memberSsotNormalizeMobile((string)($_POST['member_mobile'] ?? $verifyMobile))
            : preg_replace('/\D+/', '', (string)($_POST['member_mobile'] ?? $verifyMobile));
        $code = trim((string)($_POST['code'] ?? ''));
        $code = function_exists('normalizeCardCode') ? normalizeCardCode($code) : $code;
        $cvv  = trim((string)($_POST['cvv'] ?? ''));
        $verifyMode = (($_POST['verify_mode'] ?? '') === 'legacy') ? 'legacy' : 'name';

        if (!$pdo) {
            $logError = $_t('DB जडान भएन। कृपया पछि प्रयास गर्नुहोस्।', 'Database unavailable. Please try again later.');
            $result = ['ok' => false, 'error' => $logError];
        } elseif ($mid < 1 || $partnerId < 1) {
            $logError = $_t('साझेदार संस्था छान्नुहोस्।', 'Please select a partner organization.');
            $result = ['ok' => false, 'error' => $logError];
        } else {
            /* Desk must have verified this member recently (or re-submit credentials). */
            $sessMid = (int)($_SESSION['vp_ok_mid'] ?? 0);
            $sessAt  = (int)($_SESSION['vp_ok_at'] ?? 0);
            $sessOk  = ($sessMid === $mid && $sessMid > 0 && (time() - $sessAt) <= 1800);

            if (!$sessOk) {
                $vr = $runPrimaryVerify();
                if (!empty($vr['ok']) && (int)($vr['member']['id'] ?? 0) === $mid) {
                    $sessOk = true;
                    $_SESSION['vp_ok_mid'] = $mid;
                    $_SESSION['vp_ok_at'] = time();
                    $result = $vr;
                } else {
                    $logError = $_t('पहिले सदस्य verify गर्नुहोस्, अनि मात्र सेवा लग गर्नुहोस्।', 'Please verify the member first, then log the service.');
                    $result = is_array($vr) ? $vr : ['ok' => false, 'error' => $logError];
                    if (empty($result['ok'])) {
                        $result['error'] = $result['error'] ?? $logError;
                    }
                }
            }

            if ($sessOk) {
                if (function_exists('checkRateLimit') && !checkRateLimit('partner_service_log', 40, 3600)) {
                    $logError = $_t('धेरै पटक लग भयो। केही समयपछि प्रयास गर्नुहोस्।', 'Too many service logs. Please try again later.');
                } else {
                    $lr = logMemberPartnerService($pdo, $mid, $cardNo, $partnerId, $serviceNm, $taken, $note, $pin, $ip);
                    if (!empty($lr['ok'])) {
                        $logSaved = true;
                        $_SESSION['vp_ok_mid'] = $mid;
                        $_SESSION['vp_ok_at'] = time();
                    } else {
                        $errMap = [
                            'pin' => $_t('साझेदार Desk PIN गलत भयो।', 'Partner desk PIN is incorrect.'),
                            'partner' => $_t('साझेदार सक्रिय छैन वा भेटिएन।', 'Partner not found or inactive.'),
                            'duplicate' => $_t('यो साझेदारमा भर्खरै लग भइसकेको छ (९० सेकेन्ड)।', 'Already logged for this partner just now (90s).'),
                            'db' => $_t('लग सेभ गर्न सकिएन।', 'Could not save service log.'),
                        ];
                        $logError = $errMap[$lr['error'] ?? ''] ?? $_t('सेवा लग असफल।', 'Service log failed.');
                    }
                }
                /* Prefer display rebuild — avoids rate-limit wipe after a valid desk session */
                $disp = partnerBuildVerifyDisplayResult($pdo, $mid, $cardNo);
                if (!empty($disp['ok'])) {
                    $result = $disp;
                } elseif (empty($result['ok'])) {
                    $result = $runPrimaryVerify();
                }
            }
        }
    } elseif (($_POST['action'] ?? '') === 'program_preregister') {
        $programId = (int)($_POST['program_id'] ?? 0);
        $memberIdInput = trim((string)($_POST['member_id_input'] ?? ''));
        $note = trim((string)($_POST['prereg_note'] ?? ''));
        if (($__bot = coop_public_form_bot_block($_POST, 'prog_prereg', [
            function_exists('clean_text') ? clean_text($note, 500) : mb_substr($note, 0, 500),
        ], true)) === 'honeypot') {
            $preregSaved = true;
        } elseif ($__bot) {
            $preregError = coop_public_form_guard_message($__bot, isEnglish());
        } elseif (function_exists('checkRateLimit') && !checkRateLimit('program_prereg_public', 12, 3600)) {
            $preregError = $_t('धेरै पटक प्रयास भयो। केही समयपछि फेरि प्रयास गर्नुहोस्।', 'Too many attempts. Please try again later.');
        } elseif ($programId <= 0 || $memberIdInput === '') {
            $preregError = $_t('कृपया कार्यक्रम र सदस्यता नं. दुवै भर्नुहोस्।', 'Please fill both program and member number.');
        } else {
            try {
                $pst = $pdo->prepare("SELECT id, title, pre_registration_open, is_active FROM upcoming_programs WHERE id=? LIMIT 1");
                $pst->execute([$programId]);
                $pg = $pst->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$pg || (int)$pg['is_active'] !== 1 || (int)$pg['pre_registration_open'] !== 1) {
                    $preregError = $_t('यो कार्यक्रमको pre-registration अहिले खुला छैन।', 'Pre-registration is currently closed for this program.');
                } else {
                    $mst = $pdo->prepare("SELECT m.id, m.name, m.phone, m.sadasyata_number, m.member_card_no, m.kyc_application_id, m.approval_status, m.is_active
                                          FROM members m
                                          WHERE m.sadasyata_number = ? OR m.member_card_no = ? OR m.id = ?
                                          LIMIT 1");
                    $mst->execute([$memberIdInput, $memberIdInput, (int)$memberIdInput]);
                    $member = $mst->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$member || (string)($member['approval_status'] ?? '') !== 'approved' || (int)($member['is_active'] ?? 0) !== 1) {
                        $preregError = $_t('Not member. कृपया पहिला सदस्य बन्नुहोस्।', 'Not a member. Please become a member first.');
                    } else {
                        $kycOk = false;
                        if (!empty($member['kyc_application_id'])) {
                            $kst = $pdo->prepare("SELECT id FROM kyc_applications WHERE id=? LIMIT 1");
                            $kst->execute([(int)$member['kyc_application_id']]);
                            $kycOk = (bool)$kst->fetchColumn();
                        } else {
                            $kst = $pdo->prepare("SELECT id FROM kyc_applications WHERE member_id=? OR mobile=? LIMIT 1");
                            $kst->execute([(string)($member['sadasyata_number'] ?? ''), preg_replace('/[^0-9]/', '', (string)($member['phone'] ?? ($member['phone'] ?? '') ?? ''))]);
                            $kycOk = (bool)$kst->fetchColumn();
                        }
                        if (!$kycOk) {
                            $preregError = $_t('Not member. कृपया पहिला सदस्य बन्नुहोस्।', 'Not a member. Please become a member first.');
                        } else {
                            $chk = $pdo->prepare("SELECT id FROM member_program_preregistrations WHERE member_id=? AND program_id=? LIMIT 1");
                            $chk->execute([(int)$member['id'], $programId]);
                            if ($chk->fetchColumn()) {
                                $preregAlreadyRegistered = true;
                            } else {
                                $ins = $pdo->prepare("INSERT INTO member_program_preregistrations
                                    (member_id, member_card_no, member_name, phone, program_id, program_title, note, source)
                                    VALUES (?,?,?,?,?,?,?,?)");
                                $ins->execute([
                                    (int)$member['id'],
                                    (string)($member['sadasyata_number'] ?: ($member['member_card_no'] ?? '')),
                                    mb_substr((string)($member['name'] ?? ''), 0, 150),
                                    mb_substr((string)($member['phone'] ?: (($member['phone'] ?? '') ?? '')), 0, 30),
                                    $programId,
                                    mb_substr((string)$pg['title'], 0, 180),
                                    mb_substr($note, 0, 500),
                                    'public_verify'
                                ]);
                                $preregSaved = true;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $preregError = $_t('Pre-registration सुरक्षित गर्न समस्या भयो।', 'Could not save pre-registration.');
                error_log('program prereg insert: ' . $e->getMessage());
            }
        }
        /* Pre-reg UI is on cooperative-programs.php — redirect so feedback is not silent. */
        if ($preregSaved || $preregAlreadyRegistered || $preregError !== '') {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            $q = ['tab' => 'upcoming', 'pid' => $programId];
            if ($preregSaved) {
                $q['ok'] = '1';
            } elseif ($preregAlreadyRegistered) {
                $q['dup'] = '1';
            } else {
                $_SESSION['cp_prereg_error'] = $preregError;
                $_SESSION['cp_prereg_member'] = $memberIdInput;
                $_SESSION['cp_prereg_note'] = $note;
                $q['err'] = '1';
            }
            $dest = rtrim(defined('SITE_URL') ? (string) SITE_URL : '', '/') . '/cooperative-programs.php?' . http_build_query($q);
            if ($programId > 0) {
                $dest .= '#prog-' . $programId;
            }
            header('Location: ' . $dest, true, 303);
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'log_program_attendance') {
        // Legacy attendance path closed — still verify card, surface clear notice
        $attendancePathClosedNotice = $_t('यो मार्ग बन्द छ। Member Portal QR वा Admin Registration Desk प्रयोग गर्नुहोस्।', 'This path is closed. Use Member Portal QR or Admin Registration Desk.');
        $code = trim((string)($_POST['code'] ?? ''));
        $code = function_exists('normalizeCardCode') ? normalizeCardCode($code) : $code;
        $cvv  = trim((string)($_POST['cvv']  ?? ''));
        $verifyName = trim((string)($_POST['member_name'] ?? $verifyName));
        $verifyMemberId = trim((string)($_POST['member_id_no'] ?? $verifyMemberId));
        $verifyMobile = function_exists('memberSsotNormalizeMobile')
            ? memberSsotNormalizeMobile((string)($_POST['member_mobile'] ?? $verifyMobile))
            : preg_replace('/\D+/', '', (string)($_POST['member_mobile'] ?? $verifyMobile));
        $verifyMode = (($_POST['verify_mode'] ?? '') === 'legacy') ? 'legacy' : 'name';
        $result = $runPrimaryVerify();
    } else {
        $result = $runPrimaryVerify();
    }
}

/* Remember successful desk verify for partner service-log (30 min) */
if (!empty($result['ok']) && !empty($result['member']['id'])) {
    $_SESSION['vp_ok_mid'] = (int)$result['member']['id'];
    $_SESSION['vp_ok_at'] = time();
}

/* ── Rate-limit info for countdown timer ── */
$__rateLimited = !empty($result['rate_limited']);
$__retryAfter  = $__rateLimited ? (int)($result['retry_after'] ?? (time() + 3600)) : 0;

$pageTitle  = $_t('सदस्य प्रमाणीकरण — Member Verify', 'Member Verification');
$siteName   = defined('SITE_URL') ? SITE_URL : '/';
$cardPrefix = function_exists('getCardPrefix') ? getCardPrefix() : 'AKS';
$coopPhone = function_exists('getSetting') ? getSetting('phone', getSetting('mobile', '01-XXXXXXX')) : '01-XXXXXXX';
$coopWebsite = function_exists('getSetting') ? trim((string)getSetting('site_url', (defined('SITE_URL') ? SITE_URL : ''))) : (defined('SITE_URL') ? SITE_URL : '');
$coopWebsite = preg_replace('#^https?://#i', '', rtrim((string)$coopWebsite, '/'));
$coopLogo = function_exists('getLocalizedLogoPath')
    ? trim((string) getLocalizedLogoPath(''))
    : (function_exists('getSetting') ? trim((string)getSetting('site_logo', getSetting('logo', ''))) : '');

/* DOCUMENT_ROOT बाट photo URL build गर्ने helper */
$photoUrl = '';
if ($result && !empty($result['ok'])) {
    $pp = $result['member']['photo_path'] ?? '';
    if ($pp) {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot && file_exists($docRoot . '/' . ltrim($pp, '/'))) {
            $photoUrl = '/' . ltrim($pp, '/');
        }
    }
    if (!$photoUrl) $photoUrl = '/member/assets/photo-placeholder.svg';
    try {
        $activePrograms = $pdo->query("SELECT id, title, event_date, event_time, location
                                       FROM upcoming_programs
                                       WHERE is_active=1
                                       ORDER BY COALESCE(event_date, '9999-12-31') ASC, id DESC
                                       LIMIT 80")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) { $activePrograms = []; }
}

/* Active partner list — only if verify successful, to keep guest queries low */
$partners = [];
$memberPartnerLogs = [];
$preselectPartnerId = (int)($_POST['partner_id'] ?? $_GET['partner_id'] ?? 0);
$preselectPartnerCode = strtoupper(trim((string)($_GET['partner'] ?? $_POST['partner_code_hint'] ?? '')));
if ($result && !empty($result['ok']) && $pdo) {
    try {
        $partners = $pdo->query(
            "SELECT id, partner_name, partner_name_en, partner_code, facility_type,
                    (pin_hash IS NOT NULL AND pin_hash<>'') AS needs_pin
             FROM partner_facilities WHERE is_active=1
             ORDER BY is_featured DESC, partner_name ASC LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($preselectPartnerId < 1 && $preselectPartnerCode !== '') {
            foreach ($partners as $p) {
                if (strcasecmp((string)($p['partner_code'] ?? ''), $preselectPartnerCode) === 0) {
                    $preselectPartnerId = (int)$p['id'];
                    break;
                }
            }
        }
        $midForLogs = (int)($result['member']['id'] ?? 0);
        if ($midForLogs > 0 && function_exists('fetchMemberPartnerServiceLogs')) {
            $memberPartnerLogs = fetchMemberPartnerServiceLogs($pdo, $midForLogs, 0, 40);
        }
    } catch (\Throwable $e) {
        $partners = [];
        $memberPartnerLogs = [];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo isEnglish() ? 'en' : 'ne'; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<meta name="description" content="<?php echo htmlspecialchars($_t('Member ID card सत्यता check गर्नुहोस्। नाम, सदस्यता नं. र मोबाइल राखेर सक्रिय सदस्य हो/होइन प्रमाणित गर्नुहोस्।', 'Check Member ID card authenticity. Verify active membership using name, member ID and mobile.'), ENT_QUOTES, 'UTF-8'); ?>">
<?php if (function_exists('seo_canonical_url')): ?>
<link rel="canonical" href="<?= htmlspecialchars(seo_canonical_url(), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<?php /* fonts preconnect+load via coopThemeHeadAssets → coopThemeGoogleFonts */ ?>
<?php if (function_exists('coopThemeHeadAssets')) { coopThemeHeadAssets('verify'); } ?>
<?php if (function_exists('coopThemeLink')) { coopThemeLink('assets/css/verify-page.css'); } ?>
</head>
<body class="auth-portal-page verify-auth-page">

<a class="skip-link" href="#main-content"><?= htmlspecialchars($_t('मुख्य सामग्रीमा जानुहोस्', 'Skip to main content'), ENT_QUOTES, 'UTF-8') ?></a>

<?php
$__siteName = function_exists('getSetting') ? (getSetting('site_name') ?: getSetting('cooperative_name')) : '';
$__logoSrc  = function_exists('getSetting') ? (getSetting('logo') ?: '') : '';
if ($__logoSrc && strpos($__logoSrc, 'http') === false) {
    $__logoSrc = rtrim(SITE_URL, '/') . '/' . ltrim($__logoSrc, '/');
}
$__pageTitleDisplay = $pageTitle ?? $_t('कार्ड प्रमाणीकरण', 'Member Card Verification');
?>

<main class="vp-outer" id="main-content" tabindex="-1">

    <!-- Back to homepage + lang toggle -->
    <div class="vp-back-bar">
        <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>" class="vp-back-link">
            <i class="lucide-icon" data-lucide="arrow-left" aria-hidden="true"></i> <?= $_t('गृहपृष्ठ', 'Homepage') ?>
        </a>
        <?php if (function_exists('portalLangToggleUrl') && function_exists('portalLangToggleBadge')): ?>
        <a href="<?php echo htmlspecialchars(portalLangToggleUrl(), ENT_QUOTES, 'UTF-8'); ?>" class="vp-back-link notranslate" translate="no" title="<?= htmlspecialchars($_t('भाषा परिवर्तन', 'Switch language'), ENT_QUOTES, 'UTF-8') ?>">
            <i class="lucide-icon" data-lucide="languages" aria-hidden="true"></i> <?= htmlspecialchars(portalLangToggleBadge()) ?>
        </a>
        <?php endif; ?>
    </div>

    <!-- Logo + site name -->
    <div class="vp-logo-wrap">
        <?php if ($__logoSrc): ?>
            <img src="<?= htmlspecialchars($__logoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Logo">
        <?php else: ?>
            <div class="vp-logo-icon"><i class="lucide-icon" data-lucide="id-card" aria-hidden="true"></i></div>
        <?php endif; ?>
        <?php if ($__siteName): ?>
        <div class="vp-site-name"><?= htmlspecialchars($__siteName, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="vp-site-sub"><?= $_t('सदस्य ID कार्ड प्रमाणीकरण (कार्यक्रम स्थल check-in होइन)', 'Member ID card verification (not venue program check-in)') ?></div>
    </div>

<?php
$__err = $postCsrfError ?? '';
if (!$__err) $__err = $_dbError ?? '';
if (!$__err && !empty($result['error'])) $__err = $result['error'];
?>

<?php if ($__rateLimited): ?>
<!-- ── Rate-limit countdown card ── -->
<div id="vp-ratelimit-card" class="vp-rate-card" role="alert" aria-live="assertive">
    <div class="vp-rate-head">
        <span class="vp-result-icon" style="width:46px;height:46px;font-size:1.5rem;">
            <i class="lucide-icon" data-lucide="shield" aria-hidden="true"></i>
        </span>
        <div>
            <div class="vp-rate-head-title"><?= $_t('धेरै पटक गलत प्रयास', 'Too Many Failed Attempts') ?></div>
            <div class="vp-rate-head-sub"><?= $_t('सुरक्षाका लागि अस्थायी ताल्चा लगाइएको छ।', 'Temporarily locked for security.') ?></div>
        </div>
    </div>
    <div class="vp-rate-body" style="text-align:center;">
        <p style="color:#92400e;font-size:.92rem;margin:0 0 18px;"><?= $_t('५ पटक गलत नाम / सदस्यता नं. / मोबाइल वा CVV प्रविष्ट गरिएकाले यो IP ठेगाना अस्थायी रूपमा ब्लक गरिएको छ।','This IP was temporarily blocked after 5 failed name / member ID / mobile or CVV attempts.') ?></p>

        <!-- Countdown display -->
        <div id="vp-countdown-wrap" style="display:inline-flex;flex-direction:column;align-items:center;gap:6px;background:#fff7ed;border:2px solid #fed7aa;border-radius:12px;padding:18px 32px;">
            <div style="color:#9a3412;font-size:.78rem;font-weight:600;letter-spacing:.5px;text-transform:uppercase;"><?= $_t('बाँकी समय', 'Time Remaining') ?></div>
            <div id="vp-countdown" style="font-size:2.6rem;font-weight:800;color:#ea580c;font-variant-numeric:tabular-nums;letter-spacing:2px;line-height:1;">--:--</div>
            <div id="vp-countdown-label" style="color:#9a3412;font-size:.8rem;"><?= $_t('मिनेट : सेकेन्ड', 'min : sec') ?></div>
        </div>

        <!-- Auto-unlocked message (hidden until countdown done) -->
        <div id="vp-unlocked-msg" style="display:none;margin-top:18px;">
            <div style="background:#f0fdf4;border:2px solid #86efac;border-radius:10px;padding:14px 18px;color:#16a34a;font-weight:600;margin-bottom:14px;">
                <i class="lucide-icon me-2" data-lucide="lock-open" aria-hidden="true"></i><?= $_t('समय सकियो। अब पुनः प्रयास गर्न सक्नुहुन्छ।', 'Time is up. You can try again now.') ?>
            </div>
            <a href="verify.php" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;background:linear-gradient(135deg,var(--primary-color,#1a5f2a),#0e9b53);color:#fff;text-decoration:none;border-radius:8px;font-weight:700;font-size:.95rem;">
                <i class="lucide-icon" data-lucide="rotate-cw" aria-hidden="true"></i> <?= $_t('फेरि प्रयास गर्नुहोस्', 'Try Again') ?>
            </a>
        </div>
    </div>
</div>
<script>
(function() {
    var retryAfter = <?= $__retryAfter ?> * 1000; // convert to ms
    var cdEl  = document.getElementById('vp-countdown');
    var wrapEl = document.getElementById('vp-countdown-wrap');
    var unlockedEl = document.getElementById('vp-unlocked-msg');

    function tick() {
        var remaining = Math.max(0, Math.floor((retryAfter - Date.now()) / 1000));
        if (remaining <= 0) {
            clearInterval(timer);
            if (cdEl)    cdEl.textContent = '00:00';
            if (wrapEl)  wrapEl.style.display = 'none';
            if (unlockedEl) unlockedEl.style.display = '';
            return;
        }
        var m = Math.floor(remaining / 60);
        var s = remaining % 60;
        if (cdEl) cdEl.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');

        // pulse red when under 60 s
        if (cdEl && remaining < 60) {
            cdEl.style.color = remaining % 2 === 0 ? '#dc2626' : '#ea580c';
        }
    }
    tick();
    var timer = setInterval(tick, 1000);
})();
</script>

<?php elseif (!empty($__err)): ?>
<div class="vp-alert-error" role="alert" aria-live="assertive">
    <i class="lucide-icon" data-lucide="circle-alert" aria-hidden="true" style="font-size:1.2rem;flex-shrink:0;"></i>
    <span><?= htmlspecialchars($__err, ENT_QUOTES, 'UTF-8') ?></span>
</div>
<?php endif; ?>

<?php if (!empty($result['ok'])): ?>
<!-- ── Verification Success — employee-style member card ── -->
<?php
$__m = $result['member'] ?? [];
$__c = $result['card'] ?? [];
$__photoRaw = trim((string)($__m['photo_path'] ?? ''));
$__photoSrc = '';
if ($__photoRaw !== '') {
    if (preg_match('#^https?://#i', $__photoRaw)) {
        $__photoSrc = $__photoRaw;
    } else {
        $__photoSrc = rtrim(SITE_URL, '/') . '/' . ltrim($__photoRaw, '/');
    }
}
$__dob = trim((string)($__m['dob_bs'] ?? ''));
if ($__dob === '') {
    $__dob = trim((string)($__m['dob_ad'] ?? ''));
} elseif (!empty($__m['dob_ad'])) {
    $__dob .= ' / ' . trim((string)$__m['dob_ad']);
}
$__father = trim((string)($__m['father_name'] ?? ''));
$__secretCvv = (string)($__c['secret_cvv'] ?? '');
$__idFields = [
    [$_t('सदस्यता नं.','Member ID'),  $__m['member_id']   ?? ''],
    [$_t('मोबाइल','Mobile'),            $__m['mobile']      ?? ''],
    [$_t('बुबाको नाम',"Father's Name"), $__father],
    [$_t('जन्म मिति','Date of Birth'), $__dob],
    [$_t('सदस्यता मिति','Member Since'),$__m['member_since']?? ''],
    [$_t('जारी मिति','Issued'),          $__c['issued_date'] ?? ''],
    [$_t('म्याद समाप्ति','Valid Until'), $__c['expires_at']  ?? ''],
];
$__hasPartnerCol = !empty($partners);
?>
<?php if (!empty($logSaved) || !empty($logError)): ?>
<div class="vp-success-alerts">
<?php if (!empty($logSaved)): ?>
<div class="vp-success-alert" style="margin-bottom:0;">
    <i class="lucide-icon" data-lucide="circle-check" aria-hidden="true" style="flex-shrink:0;margin-top:2px;"></i>
    <span><?= $_t('सेवा सफलतापूर्वक रेकर्ड भयो। अर्को सेवा पनि लग गर्न मिल्छ।', 'Service log recorded. You can log another service below.') ?></span>
</div>
<?php endif; ?>
<?php if (!empty($logError)): ?>
<div class="vp-alert-error" role="alert" aria-live="assertive" style="margin-bottom:0;<?= !empty($logSaved) ? 'margin-top:10px;' : '' ?>">
    <i class="lucide-icon" data-lucide="circle-alert" aria-hidden="true"></i>
    <span><?= htmlspecialchars($logError, ENT_QUOTES, 'UTF-8') ?></span>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<div class="vp-success-layout<?= $__hasPartnerCol ? ' has-partner' : '' ?>">
<div class="vp-success-col vp-success-col-id">
<div class="vp-id-card vp-result-card" role="region" aria-label="<?= htmlspecialchars($_t('सदस्य परिचय पत्र', 'Member ID Card'), ENT_QUOTES, 'UTF-8') ?>">
    <div class="vp-id-band">
        <span class="vp-id-band-title"><span class="vp-step" style="width:22px;height:22px;font-size:.68rem;border-radius:7px;display:inline-grid;place-items:center;margin-right:8px;box-shadow:none;vertical-align:middle;">१</span><?= $_t('सदस्य परिचय पत्र', 'Member ID Card') ?></span>
        <span class="vp-id-band-badge"><i class="lucide-icon me-1" data-lucide="shield" aria-hidden="true"></i><?= $_t('प्रमाणित', 'Verified') ?></span>
    </div>
    <div class="vp-id-main">
        <div class="vp-id-photo-wrap">
            <?php if ($__photoSrc !== ''): ?>
            <img src="<?= htmlspecialchars($__photoSrc, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars((string)($__m['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                 class="vp-result-photo"
                 loading="lazy"
                 onerror="this.style.display='none';this.parentElement.insertAdjacentHTML('beforeend','<span class=\'vp-id-photo-fallback\'><i class=\'lucide-icon\' aria-hidden=\'true\' data-lucide=\'user\'></i></span>');">
            <?php else: ?>
            <span class="vp-id-photo-fallback" aria-hidden="true"><i class="lucide-icon" data-lucide="user" aria-hidden="true"></i></span>
            <?php endif; ?>
        </div>
        <div class="vp-id-info">
            <h2 class="vp-id-name"><?= htmlspecialchars((string)($__m['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="vp-id-status"><i class="lucide-icon" data-lucide="circle-check" aria-hidden="true"></i> <?= $_t('कार्ड सक्रिय र वैध छ।', 'Card is active and valid.') ?></div>
            <div class="vp-id-grid">
                <?php foreach ($__idFields as [$lbl, $val]):
                    if (trim((string)$val) === '') continue;
                ?>
                <div class="vp-id-row">
                    <span class="vp-id-label"><?= htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="vp-id-value"><?= htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($__secretCvv !== ''): ?>
            <div class="vp-id-secret">
                <div class="vp-id-row">
                    <span class="vp-id-label"><?= $_t('गोप्य CVV','Secret CVV') ?></span>
                    <span class="vp-id-value vp-secret-code"><?= htmlspecialchars($__secretCvv, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <p class="vp-secret-hint" style="margin:6px 0 0;font-size:.75rem;color:#6b7280;line-height:1.4;">
                    <?= $_t('यो कोड नामको पहिलो ३ अक्षर + सदस्यता नं. को पछिल्लो ४ अङ्कबाट बनेको हो।', 'Built from first 3 letters of first name + last 4 digits of member ID.') ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div><!-- /.vp-success-col-id -->

<?php if ($__hasPartnerCol): ?>
<div class="vp-success-col vp-success-col-action">
<div class="vp-desk-card vp-partner-action-card" id="vpPartnerLog">
    <div class="vp-desk-head">
        <span class="vp-step">२</span>
        <div class="vp-desk-head-text">
            <h3><i class="lucide-icon" data-lucide="pen-square" aria-hidden="true"></i> <?= $_t('सेवा लग्नुहोस्', 'Log service') ?></h3>
            <p><?= $_t('डेस्कबाट सेवा/छुट दिएपछि यहाँ सेभ गर्नुहोस्।', 'Save here after the desk provides a service or discount.') ?></p>
        </div>
    </div>
    <div class="vp-desk-body">
    <form method="POST" action="" class="vp-partner-log-form">
        <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
        <input type="hidden" name="action" value="log_service">
        <input type="hidden" name="member_id" value="<?= (int)($__m['id'] ?? 0) ?>">
        <input type="hidden" name="member_card_no" value="<?= htmlspecialchars((string)($__c['card_no'] ?? $__m['member_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="verify_mode" value="<?= htmlspecialchars($verifyMode === 'legacy' ? 'legacy' : 'name', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="member_name" value="<?= htmlspecialchars($verifyName !== '' ? $verifyName : (string)($__m['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="member_id_no" value="<?= htmlspecialchars($verifyMemberId !== '' ? $verifyMemberId : (string)($__m['member_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="member_mobile" value="<?= htmlspecialchars($verifyMobile !== '' ? $verifyMobile : (string)($__m['mobile'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="code" value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="cvv" value="<?= htmlspecialchars($cvv, ENT_QUOTES, 'UTF-8') ?>">

        <div class="vp-field">
            <label for="vpPartnerCodeQuick" class="vp-label"><?= $_t('Desk code बाट छिटो','Quick by desk code') ?></label>
            <input type="text" id="vpPartnerCodeQuick" class="vp-input" placeholder="PF-XXXXXX" autocomplete="off" spellcheck="false" style="letter-spacing:.04em;text-transform:uppercase;">
            <div class="vp-partner-code-hint"><?= $_t('Code टाइप गर्दा तलको सूची auto-select हुन्छ।', 'Typing a code auto-selects the partner below.') ?></div>
        </div>
        <div class="vp-field">
            <label for="vpPartnerSelect" class="vp-label"><?= $_t('साझेदार संस्था','Partner organization') ?> <span class="req">*</span></label>
            <select name="partner_id" id="vpPartnerSelect" class="vp-input" required>
                <option value=""><?= $_t('— छान्नुहोस् —','— Select —') ?></option>
                <?php foreach ($partners as $p):
                    $label = partnerFacilityDisplayName($p);
                    $codeL = trim((string)($p['partner_code'] ?? ''));
                    $typeL = trim((string)($p['facility_type'] ?? ''));
                    $opt = $label . ($codeL !== '' ? ' (' . $codeL . ')' : '') . ($typeL !== '' ? ' · ' . $typeL : '');
                    $sel = ((int)$p['id'] === $preselectPartnerId) ? ' selected' : '';
                ?>
                <option value="<?= (int)$p['id'] ?>"
                        data-code="<?= htmlspecialchars(strtoupper($codeL), ENT_QUOTES, 'UTF-8') ?>"
                        data-needs-pin="<?= !empty($p['needs_pin']) ? '1' : '0' ?>"
                        data-name="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"<?= $sel ?>>
                    <?= htmlspecialchars($opt, ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="vp-field" id="vpPartnerPinWrap" style="display:none;">
            <label for="vpPartnerPin" class="vp-label"><?= $_t('Desk PIN','Desk PIN') ?> <span class="req">*</span></label>
            <input type="password" name="partner_pin" id="vpPartnerPin" class="vp-input" autocomplete="off" placeholder="••••">
        </div>
        <div class="vp-field">
            <label for="vp_service_name" class="vp-label"><?= $_t('सेवा / वस्तु','Service / item') ?></label>
            <input type="text" name="service_name" id="vp_service_name" class="vp-input" maxlength="255" placeholder="<?= $_t('जस्तै: ल्याब टेस्ट, खाना','e.g. Lab test, meal') ?>" value="">
        </div>
        <div class="vp-field">
            <label for="vp_service_note" class="vp-label"><?= $_t('नोट','Note') ?></label>
            <input type="text" name="service_note" id="vp_service_note" class="vp-input" maxlength="500" placeholder="<?= $_t('ऐच्छिक','Optional') ?>">
        </div>
        <div class="vp-field" style="margin-bottom:14px;">
            <label for="vp_service_taken" class="vp-label"><?= $_t('सेवा लिइयो?','Service taken?') ?></label>
            <select name="service_taken" id="vp_service_taken" class="vp-input">
                <option value="yes" selected><?= $_t('हो — लिए','Yes — taken') ?></option>
                <option value="no"><?= $_t('होइन — verify मात्र','No — verify only') ?></option>
            </select>
        </div>
        <button type="submit" class="vp-btn vp-partner-log-btn">
            <i class="lucide-icon" data-lucide="save" aria-hidden="true"></i> <?= $_t('सेवा लग सेभ गर्नुहोस्','Save service log') ?>
        </button>
    </form>
    </div>
</div>
</div><!-- /.vp-success-col-action -->

<div class="vp-success-col vp-success-col-log">
<div class="vp-desk-card vp-partner-history-card" id="vpVisitPanel">
    <div class="vp-desk-head">
        <span class="vp-step">३</span>
        <div class="vp-desk-head-text">
            <h3><i class="lucide-icon" data-lucide="history" aria-hidden="true"></i> <?= $_t('सेवा इतिहास', 'Service history') ?></h3>
            <p id="vpVisitTitleText"><?= $_t('यस सदस्यका साझेदार सेवा लगहरू', "This member's partner service logs") ?></p>
        </div>
        <span class="vp-visit-count" id="vpVisitCount"><?= (int)count($memberPartnerLogs) ?></span>
    </div>
    <div class="vp-desk-body">
    <div class="vp-visit-list" id="vpVisitList">
        <?php if (empty($memberPartnerLogs)): ?>
        <div class="vp-visit-empty" data-empty-all="1">
            <i class="lucide-icon" data-lucide="inbox" aria-hidden="true"></i>
            <span><?= $_t('अहिलेसम्म कुनै साझेदार सेवा लग छैन। Action बाट सेभ गर्नुहोस्।', 'No partner service logs yet. Save from Action.') ?></span>
        </div>
        <?php else:
            foreach ($memberPartnerLogs as $vl):
                $taken = !empty($vl['service_taken']);
                $pid = (int)($vl['partner_id'] ?? 0);
                $when = function_exists('formatNepaliDate')
                    ? formatNepaliDate($vl['created_at'] ?? '', true)
                    : (string)($vl['created_at'] ?? '');
        ?>
        <div class="vp-visit-row" data-partner-id="<?= $pid ?>">
            <div class="vp-visit-dot <?= $taken ? 'is-taken' : 'is-skip' ?>"></div>
            <div class="vp-visit-body">
                <div class="vp-visit-org"><?php
                    $vOrg = (string)($vl['partner_name'] ?? '—');
                    if (function_exists('partnerFacilityDisplayName')) {
                        $vOrg = partnerFacilityDisplayName([
                            'partner_name' => (string)($vl['partner_name'] ?? ''),
                            'partner_name_en' => (string)($vl['partner_name_en'] ?? ''),
                        ]) ?: $vOrg;
                    }
                    echo htmlspecialchars($vOrg, ENT_QUOTES, 'UTF-8');
                ?></div>
                <div class="vp-visit-svc">
                    <?= htmlspecialchars((string)(($vl['service_name'] ?? '') !== '' ? $vl['service_name'] : $_t('सेवा उल्लेख छैन', 'Service not specified'))) ?>
                    <?php if (!empty($vl['service_note'])): ?>
                        <span class="vp-visit-note">· <?= htmlspecialchars((string)$vl['service_note']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="vp-visit-meta">
                    <time><?= htmlspecialchars($when, ENT_QUOTES, 'UTF-8') ?></time>
                    <span class="vp-visit-badge <?= $taken ? 'yes' : 'no' ?>">
                        <?= $taken ? $_t('सेवा लिइयो', 'Taken') : $_t('verify मात्र', 'Verify only') ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
        <div class="vp-visit-empty vp-visit-empty-filter" id="vpVisitEmptyFilter" hidden>
            <i class="lucide-icon" data-lucide="building" aria-hidden="true"></i>
            <span id="vpVisitEmptyFilterText"><?= $_t('यस संस्थामा यस सदस्यको लग अहिलेसम्म छैन।', 'No visits by this member at this partner yet.') ?></span>
        </div>
    </div>
    </div>
</div>
</div><!-- /.vp-success-col-log -->
</div><!-- /.vp-success-layout -->
<script>
(function(){
    var sel = document.getElementById('vpPartnerSelect');
    var wrap = document.getElementById('vpPartnerPinWrap');
    var pin = document.getElementById('vpPartnerPin');
    var quick = document.getElementById('vpPartnerCodeQuick');
    var list = document.getElementById('vpVisitList');
    var countEl = document.getElementById('vpVisitCount');
    var titleEl = document.getElementById('vpVisitTitleText');
    var emptyFilter = document.getElementById('vpVisitEmptyFilter');
    var titleAll = <?= json_encode($_t('यस सदस्यका साझेदार सेवा लगहरू', "This member's partner service logs"), JSON_UNESCAPED_UNICODE) ?>;
    var titleAt = <?= json_encode($_t('यस संस्थामा भेट / सेवा लग', 'Visits / service logs at this partner'), JSON_UNESCAPED_UNICODE) ?>;
    if (!sel || !wrap) return;

    function filterVisits() {
        if (!list) return;
        var pid = sel.value || '';
        var rows = list.querySelectorAll('.vp-visit-row');
        var emptyAll = list.querySelector('[data-empty-all="1"]');
        var visible = 0;
        rows.forEach(function (row) {
            var match = !pid || String(row.getAttribute('data-partner-id') || '') === String(pid);
            row.hidden = !match;
            if (match) visible++;
        });
        if (emptyAll) emptyAll.hidden = !!pid || rows.length > 0;
        if (emptyFilter) emptyFilter.hidden = !(pid && visible === 0);
        if (countEl) countEl.textContent = String(pid ? visible : rows.length);
        if (titleEl) {
            var opt = sel.options[sel.selectedIndex];
            var nm = opt && opt.value ? (opt.getAttribute('data-name') || '') : '';
            titleEl.textContent = pid ? (titleAt + (nm ? ' — ' + nm : '')) : titleAll;
        }
    }

    function sync() {
        var opt = sel.options[sel.selectedIndex];
        var need = opt && opt.getAttribute('data-needs-pin') === '1';
        wrap.style.display = need ? '' : 'none';
        if (pin) pin.required = !!need;
        if (!need && pin) pin.value = '';
        if (quick && opt && opt.value) {
            var c = opt.getAttribute('data-code') || '';
            if (c && document.activeElement !== quick) quick.value = c;
        }
        filterVisits();
    }
    sel.addEventListener('change', sync);
    if (quick) {
        quick.addEventListener('input', function () {
            var q = (quick.value || '').trim().toUpperCase();
            if (q.length < 4) return;
            for (var i = 0; i < sel.options.length; i++) {
                var oc = (sel.options[i].getAttribute('data-code') || '').toUpperCase();
                if (oc && oc === q) {
                    sel.selectedIndex = i;
                    sync();
                    break;
                }
            }
        });
    }
    sync();
    <?php if (!empty($logSaved)): ?>
    var box = document.getElementById('vpPartnerLog');
    if (box) box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    <?php endif; ?>
})();
</script>
<?php else: ?>
</div><!-- /.vp-success-layout (ID only) -->
<div class="vp-programs-card" style="margin-top:14px;">
    <p style="font-size:.85rem;color:#6b7280;margin:0;">
        <?= $_t('अहिले सक्रिय साझेदार सुविधा सूचीमा छैन।','No active partner facilities are listed yet.') ?>
        <a href="<?= htmlspecialchars(rtrim(SITE_URL,'/') . '/partner-facilities.php') ?>"><?= $_t('सूची हेर्नुहोस्','Browse list') ?></a>
    </p>
</div>
<?php endif; ?>

<?php if ($attendancePathClosedNotice !== ''): ?>
<div class="vp-success-alert" style="background:#fff7ed;border-color:#fdba74;color:#9a3412;">
    <i class="lucide-icon me-2" data-lucide="circle-alert" aria-hidden="true"></i><?= htmlspecialchars($attendancePathClosedNotice, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<?php if (!empty($activePrograms)): ?>
<div class="vp-programs-card">
    <h3 class="vp-programs-title">
        <i class="lucide-icon" data-lucide="calendar-check" aria-hidden="true"></i> <?= $_t('सक्रिय कार्यक्रम (जानकारी मात्र)','Active programs (info only)') ?>
    </h3>
    <p style="font-size:.82rem;color:#6b7280;margin:0 0 10px;">
        <?= $_t('यो सूची उपस्थिति दर्ता होइन। स्थल check-in का लागि Member Portal → Scan / Attend प्रयोग गर्नुहोस्।', 'This list is not attendance. For venue check-in use Member Portal → Scan / Attend.') ?>
    </p>
    <div>
    <?php foreach ($activePrograms as $prog): ?>
        <div class="vp-program-item">
            <strong><?= htmlspecialchars($prog['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
            <?php if (!empty($prog['program_date'])): ?>
            <span style="color:#6b7280;font-size:.82rem;margin-left:8px;"><i class="lucide-icon" data-lucide="calendar" aria-hidden="true"></i> <?= htmlspecialchars($prog['program_date'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Re-verify or new search -->
<div class="vp-reverify-link">
    <a href="verify.php">
        <i class="lucide-icon me-1" data-lucide="arrow-left" aria-hidden="true"></i><?= $_t('अर्को कार्ड प्रमाणित गर्नुहोस्','Verify another card') ?>
    </a>
</div>

<?php else: ?>
<!-- ── Verification Form ── -->
<div class="vp-main-card">
    <div class="vp-card-head">
        <div class="vp-card-head-icon"><i class="lucide-icon" data-lucide="id-card" aria-hidden="true"></i></div>
        <div class="vp-card-head-text">
            <div class="vp-card-head-title"><?= htmlspecialchars($__pageTitleDisplay, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="vp-card-head-sub"><?= $_t('नाम, सदस्यता नं. र मोबाइल राखेर सक्रिय सदस्य हो/होइन प्रमाणित गर्नुहोस्। स्थल उपस्थिति = Member Portal QR / दर्ता डेस्क।', 'Verify active membership with name, member ID and mobile. Venue attendance = Member Portal QR / Registration Desk.') ?></div>
        </div>
    </div>
    <div class="vp-card-body">
        <form method="POST" action="" id="vpVerifyForm">
            <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
            <input type="hidden" name="verify_mode" value="name">

            <div id="vpModeName">
                <div class="vp-field">
                    <label for="vpMemberName" class="vp-label">
                        <i class="lucide-icon" data-lucide="user" aria-hidden="true" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                        <?= $_t('सदस्यको नाम', 'Member Name') ?> <span class="req">*</span>
                    </label>
                    <input type="text" name="member_name" class="vp-input" id="vpMemberName"
                           value="<?= htmlspecialchars($verifyName ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="<?= $_t('कार्डमा लेखिएको पूरा नाम', 'Full name as on card') ?>"
                           autocomplete="name" required>
                </div>
                <div class="vp-field">
                    <label for="vpMemberId" class="vp-label">
                        <i class="lucide-icon" data-lucide="hash" aria-hidden="true" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                        <?= $_t('सदस्यता नं. / Member ID', 'Member ID') ?> <span class="req">*</span>
                    </label>
                    <input type="text" name="member_id_no" class="vp-input" id="vpMemberId"
                           value="<?= htmlspecialchars($verifyMemberId ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="<?= $_t('कार्डमा देखिने सदस्यता नं.', 'Member ID shown on card') ?>"
                           autocomplete="off" spellcheck="false" required>
                </div>
                <div class="vp-field">
                    <label for="vpMemberMobile" class="vp-label">
                        <i class="lucide-icon" data-lucide="smartphone" aria-hidden="true" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                        <?= $_t('मोबाइल नम्बर', 'Mobile number') ?> <span class="req">*</span>
                    </label>
                    <input type="tel" name="member_mobile" class="vp-input" id="vpMemberMobile"
                           value="<?= htmlspecialchars($verifyMobile ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="<?= $_t('दर्ता भएको १० अंकको मोबाइल', 'Registered 10-digit mobile') ?>"
                           inputmode="numeric" autocomplete="tel" maxlength="15" required>
                    <div style="font-size:.78rem;color:#6b7280;margin-top:6px;">
                        <?= $_t('सहकारीमा दर्ता मोबाइल मिल्नुपर्छ — सुरक्षाका लागि।', 'Must match the registered cooperative mobile — for security.') ?>
                    </div>
                </div>
                <div class="vp-field" style="margin-bottom:22px;">
                    <label for="vpCvv" class="vp-label">
                        <i class="lucide-icon" data-lucide="lock" aria-hidden="true" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                        <?= $_t('CVV (ऐच्छिक)', 'CVV (optional)') ?>
                    </label>
                    <input type="text" name="cvv" maxlength="20" class="vp-input" id="vpCvv"
                           value="<?= htmlspecialchars($cvv ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="<?= $_t('खाली छोड्न सकिन्छ — मिल्दा गोप्य कोड खुल्छ', 'Can leave blank — secret code appears on match') ?>"
                           autocomplete="off" spellcheck="false" style="letter-spacing:1px;">
                    <div style="font-size:.78rem;color:#6b7280;margin-top:6px;">
                        <?= $_t('CVV कार्डको पछाडि छ (नामको पहिलो ३ + सदस्यताको पछिल्लो ४)। खाली छोड्न पनि सकिन्छ।', 'CVV is on the card back (first 3 of name + last 4 of member ID). You can also leave it blank.') ?>
                    </div>
                </div>
            </div>

            <button type="submit" class="vp-btn">
                <i class="lucide-icon" data-lucide="shield" aria-hidden="true"></i> <?= $_t('प्रमाणित गर्नुहोस्', 'Verify Now') ?>
            </button>
        </form>
    </div>
</div>


<div class="vp-secure">
    <i class="lucide-icon" data-lucide="shield" aria-hidden="true" style="margin-right:4px;"></i>
    <?= $_t('यो पृष्ठ सुरक्षित र निजी छ।', 'This page is secure and private.') ?>
</div>
<?php endif; ?>

</main><!-- /.vp-outer /#main-content -->
</body>
</html>
