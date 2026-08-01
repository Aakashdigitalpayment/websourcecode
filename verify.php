<?php
/**
 * ════════════════════════════════════════════════════════════
 * PUBLIC MEMBER VERIFICATION — v10.2
 * ────────────────────────────────────────────────────────────
 * URL: /verify.php
 *
 * कुनै पनि व्यक्ति (हस्पिटल, पसल, अन्य संस्था) ले member ले
 * देखाएको ID Card को नाम र सदस्यता नं. enter गरेर तुरुन्तै
 * सक्रिय सदस्य हो/होइन verify गर्न सक्छन्। मिल्दा गोप्य CVV
 * (नामको पहिलो ३ + सदस्यताको पछिल्लो ४) tracker जस्तै खुल्छ।
 * पुराना कार्डका लागि Verification Code + CVV path पनि उपलब्ध छ।
 * ════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/card-verify-helpers.php';
require_once __DIR__ . '/includes/program-tables.php';
require_once __DIR__ . '/includes/member-partner-services-tables.php';
require_once __DIR__ . '/includes/partner-facilities-tables.php';
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
$verifyMode = 'name'; // name | legacy
$logSaved = false;
$logError = '';
$programSaved = false;
$programAlreadyRegistered = false;
$preregSaved = false;
$preregAlreadyRegistered = false;
$preregError = '';
$activePrograms = [];
$openPreRegPrograms = [];
$postCsrfError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $postCsrfError = isEnglish() ? 'Security validation failed. Please retry.' : 'सुरक्षा जाँच असफल भयो। कृपया फेरि प्रयास गर्नुहोस्।';
    }
    $verifyMode = (($_POST['verify_mode'] ?? '') === 'legacy') ? 'legacy' : 'name';
    $verifyName = trim((string)($_POST['member_name'] ?? ''));
    $verifyMemberId = trim((string)($_POST['member_id_no'] ?? ''));
    $code = (string)($_POST['code'] ?? '');
    $code = function_exists('normalizeCardCode') ? normalizeCardCode($code) : $code;
    $cvv  = trim((string)($_POST['cvv']  ?? ''));
    if ($cvv === '' && isset($_POST['cvv_legacy'])) {
        $cvv = trim((string)$_POST['cvv_legacy']);
    }
    if (function_exists('normalizeCvvInput')) {
        $cvv = normalizeCvvInput($cvv);
    }
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $runPrimaryVerify = static function () use ($pdo, $ip, &$verifyMode, &$verifyName, &$verifyMemberId, &$code, &$cvv) {
        if (!$pdo) {
            return ['ok' => false, 'error' => 'DB जडान भएन। कृपया पछि प्रयास गर्नुहोस्।'];
        }
        if ($verifyMode === 'legacy') {
            return verifyCardCredentials($pdo, $code, $cvv, $ip);
        }
        return verifyCardByNameAndMemberId($pdo, $verifyName, $verifyMemberId, $ip, $cvv);
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
        $code = trim((string)($_POST['code'] ?? ''));
        $code = function_exists('normalizeCardCode') ? normalizeCardCode($code) : $code;
        $cvv  = trim((string)($_POST['cvv'] ?? ''));
        $verifyMode = (($_POST['verify_mode'] ?? '') === 'legacy') ? 'legacy' : 'name';

        if ($mid < 1 || $partnerId < 1) {
            $logError = $_t('साझेदार संस्था छान्नुहोस्।', 'Please select a partner organization.');
        } elseif (function_exists('checkRateLimit') && !checkRateLimit('partner_service_log', 40, 3600)) {
            $logError = $_t('धेरै पटक लग भयो। केही समयपछि प्रयास गर्नुहोस्।', 'Too many service logs. Please try again later.');
        } else {
            $lr = logMemberPartnerService($pdo, $mid, $cardNo, $partnerId, $serviceNm, $taken, $note, $pin, $ip);
            if (!empty($lr['ok'])) {
                $logSaved = true;
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
        /* Prefer display rebuild — full re-verify can wipe UI via rate-limit */
        $disp = partnerBuildVerifyDisplayResult($pdo, $mid, $cardNo);
        $result = !empty($disp['ok']) ? $disp : $runPrimaryVerify();
    } elseif (($_POST['action'] ?? '') === 'program_preregister') {
        $programId = (int)($_POST['program_id'] ?? 0);
        $memberIdInput = trim((string)($_POST['member_id_input'] ?? ''));
        $note = trim((string)($_POST['prereg_note'] ?? ''));
        if ($programId <= 0 || $memberIdInput === '') {
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
    } elseif (($_POST['action'] ?? '') === 'log_program_attendance') {
        // Legacy action removed — attendance must go via Member Portal QR (pending→approve) or Staff Verify
        $error = $_t('यो मार्ग बन्द छ। Member Portal QR वा Staff Verify प्रयोग गर्नुहोस्।', 'This path is closed. Use Member Portal QR or Staff Verify.');
        $code = trim($_POST['code'] ?? '');
        $code = function_exists('normalizeCardCode') ? normalizeCardCode($code) : $code;
        $cvv  = trim($_POST['cvv']  ?? '');
        $verifyName = trim((string)($_POST['member_name'] ?? $verifyName));
        $verifyMemberId = trim((string)($_POST['member_id_no'] ?? $verifyMemberId));
        $verifyMode = (($_POST['verify_mode'] ?? '') === 'legacy') ? 'legacy' : 'name';
        $result = $runPrimaryVerify();
    } else {
        $result = $runPrimaryVerify();
    }
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

try {
    $openPreRegPrograms = $pdo->query("SELECT id, title, event_date, event_time, location
                                       FROM upcoming_programs
                                       WHERE is_active=1 AND pre_registration_open=1
                                       ORDER BY COALESCE(event_date, '9999-12-31') ASC, id DESC
                                       LIMIT 80")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) { $openPreRegPrograms = []; }

/* Active partner list — only if verify successful, to keep guest queries low */
$partners = [];
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
    } catch (\Throwable $e) {
        $partners = [];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo isEnglish() ? 'en' : 'ne'; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="<?php echo htmlspecialchars($_t('Member ID card सत्यता check गर्नुहोस्। नाम र सदस्यता नं. राखेर सक्रिय सदस्य हो/होइन प्रमाणित गर्नुहोस्।', 'Check Member ID card authenticity. Verify active membership using name and member ID.'), ENT_QUOTES, 'UTF-8'); ?>">
<?php if (function_exists('seo_canonical_url')): ?>
<link rel="canonical" href="<?= htmlspecialchars(seo_canonical_url(), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<?php if (function_exists('coopThemeHeadAssets')) { coopThemeHeadAssets('verify'); } ?>
<style>
/* ── verify.php layout overrides ── */
.vp-back-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 0 1.25rem;
}
.vp-back-link {
    display: inline-flex; align-items: center; gap: 6px;
    color: var(--primary-color, #1a5f2a); font-size: .82rem; font-weight: 600;
    text-decoration: none; padding: 6px 14px; border-radius: 999px;
    background: rgba(var(--primary-rgb, 26,95,42), .07);
    border: 1px solid rgba(var(--primary-rgb, 26,95,42), .15);
    transition: background .15s;
}
.vp-back-link:hover { background: rgba(var(--primary-rgb, 26,95,42), .13); color: var(--primary-dark, #145021); }
.vp-logo-wrap { text-align: center; margin-bottom: 1.35rem; }
.vp-logo-wrap img {
    max-height: 96px;
    max-width: min(420px, 100%);
    width: auto;
    height: auto;
    object-fit: contain;
    border-radius: 10px;
    display: block;
    margin: 0 auto .75rem;
}
.vp-logo-icon { width: 72px; height: 72px; border-radius: 50%; margin: 0 auto .65rem; background: var(--primary-color, #1a5f2a); color: var(--text-on-primary, #fff); font-size: 1.55rem; display: grid; place-items: center; box-shadow: 0 4px 18px rgba(var(--primary-rgb, 26,95,42), .28); }
.vp-site-name { font-weight: 700; font-size: 1.05rem; line-height: 1.35; color: var(--primary-color, #1a5f2a); max-width: 28rem; margin: 0 auto; }
.vp-site-sub  { font-size: .8rem; color: var(--text-muted, #6b7280); margin-top: 4px; }
.vp-main-card { background: #fff; border-radius: 16px; box-shadow: 0 2px 18px rgba(0,0,0,.09); overflow: hidden; border: 1px solid var(--border-color, #e5e7eb); }
.vp-card-head { background: var(--primary-color, #1a5f2a); padding: 18px 22px; display: flex; align-items: center; gap: 14px; }
.vp-card-head-icon { width: 44px; height: 44px; border-radius: 50%; background: rgba(255,255,255,.2); display: grid; place-items: center; font-size: 1.25rem; color: #fff; flex-shrink: 0; }
.vp-card-head-text .vp-card-head-title { color: #fff; font-weight: 700; font-size: 1.05rem; }
.vp-card-head-text .vp-card-head-sub   { color: rgba(255,255,255,.82); font-size: .82rem; margin-top: 2px; }
.vp-card-body  { padding: 22px 24px; }
.vp-field      { margin-bottom: 16px; }
.vp-label      { display: block; font-weight: 600; color: var(--text-primary, #1a2e1f); margin-bottom: 6px; font-size: .92rem; }
.vp-label .req { color: var(--color-danger, #dc2626); }
.vp-input {
    width: 100%; padding: 11px 14px; border: 1.5px solid var(--border-color, #d1d5db);
    border-radius: 10px; font-size: .95rem; font-family: inherit; box-sizing: border-box;
    transition: border-color .15s, box-shadow .15s; background: var(--bg-card, #fff); color: var(--text-primary, #1a2e1f);
}
.vp-input:focus { outline: none; border-color: var(--primary-color, #1a5f2a); box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 26,95,42), .12); }
.vp-btn {
    width: 100%; min-height: 46px; padding: 12px; border: none; border-radius: 10px;
    font-size: .97rem; font-weight: 700; cursor: pointer; display: flex; align-items: center;
    justify-content: center; gap: 8px; font-family: inherit;
    background: var(--primary-color, #1a5f2a); color: var(--text-on-primary, #fff);
    transition: background .18s, transform .12s;
}
.vp-btn:hover { background: var(--primary-dark, #145021); transform: translateY(-1px); }
.vp-alert-error { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; color: #dc2626; display: flex; align-items: center; gap: 10px; font-size: .9rem; }
.vp-secure { text-align: center; margin-top: 16px; font-size: .8rem; color: var(--text-light, #9ca3af); }
</style>
</head>
<body class="auth-portal-page verify-auth-page">

<?php
$__siteName = function_exists('getSetting') ? (getSetting('site_name') ?: getSetting('cooperative_name')) : '';
$__logoSrc  = function_exists('getSetting') ? (getSetting('logo') ?: '') : '';
if ($__logoSrc && strpos($__logoSrc, 'http') === false) {
    $__logoSrc = rtrim(SITE_URL, '/') . '/' . ltrim($__logoSrc, '/');
}
$__pageTitleDisplay = $pageTitle ?? $_t('कार्ड प्रमाणीकरण', 'Member Card Verification');
?>

<div class="vp-outer">

    <!-- Back to homepage + lang toggle -->
    <div class="vp-back-bar">
        <a href="<?php echo SITE_URL; ?>" class="vp-back-link">
            <i class="fas fa-arrow-left"></i> <?= $_t('गृहपृष्ठ', 'Homepage') ?>
        </a>
        <?php if (function_exists('portalLangToggleUrl') && function_exists('portalLangToggleBadge')): ?>
        <a href="<?php echo htmlspecialchars(portalLangToggleUrl(), ENT_QUOTES, 'UTF-8'); ?>" class="vp-back-link notranslate" translate="no" title="<?= htmlspecialchars($_t('भाषा परिवर्तन', 'Switch language'), ENT_QUOTES, 'UTF-8') ?>">
            <i class="fas fa-language"></i> <?= htmlspecialchars(portalLangToggleBadge()) ?>
        </a>
        <?php endif; ?>
    </div>

    <!-- Logo + site name -->
    <div class="vp-logo-wrap">
        <?php if ($__logoSrc): ?>
            <img src="<?= htmlspecialchars($__logoSrc) ?>" alt="Logo">
        <?php else: ?>
            <div class="vp-logo-icon"><i class="fas fa-id-card"></i></div>
        <?php endif; ?>
        <?php if ($__siteName): ?>
        <div class="vp-site-name"><?= htmlspecialchars($__siteName) ?></div>
        <?php endif; ?>
        <div class="vp-site-sub"><?= $_t('सदस्य प्रमाणीकरण पोर्टल', 'Member Verification Portal') ?></div>
    </div>

<?php
$__err = $postCsrfError ?? '';
if (!$__err) $__err = $_dbError ?? '';
if (!$__err && !empty($result['error'])) $__err = $result['error'];
?>

<?php if ($__rateLimited): ?>
<!-- ── Rate-limit countdown card ── -->
<div id="vp-ratelimit-card" class="vp-rate-card">
    <div class="vp-rate-head">
        <span class="vp-result-icon" style="width:46px;height:46px;font-size:1.5rem;">
            <i class="fas fa-shield-halved"></i>
        </span>
        <div>
            <div class="vp-rate-head-title"><?= $_t('धेरै पटक गलत प्रयास', 'Too Many Failed Attempts') ?></div>
            <div class="vp-rate-head-sub"><?= $_t('सुरक्षाका लागि अस्थायी ताल्चा लगाइएको छ।', 'Temporarily locked for security.') ?></div>
        </div>
    </div>
    <div class="vp-rate-body" style="text-align:center;">
        <p style="color:#92400e;font-size:.92rem;margin:0 0 18px;"><?= $_t('५ पटक गलत Verification Code वा CVV प्रविष्ट गरिएकाले यो IP ठेगाना अस्थायी रूपमा ब्लक गरिएको छ।','This IP was temporarily blocked after 5 failed verification attempts.') ?></p>

        <!-- Countdown display -->
        <div id="vp-countdown-wrap" style="display:inline-flex;flex-direction:column;align-items:center;gap:6px;background:#fff7ed;border:2px solid #fed7aa;border-radius:12px;padding:18px 32px;">
            <div style="color:#9a3412;font-size:.78rem;font-weight:600;letter-spacing:.5px;text-transform:uppercase;"><?= $_t('बाँकी समय', 'Time Remaining') ?></div>
            <div id="vp-countdown" style="font-size:2.6rem;font-weight:800;color:#ea580c;font-variant-numeric:tabular-nums;letter-spacing:2px;line-height:1;">--:--</div>
            <div id="vp-countdown-label" style="color:#9a3412;font-size:.8rem;"><?= $_t('मिनेट : सेकेन्ड', 'min : sec') ?></div>
        </div>

        <!-- Auto-unlocked message (hidden until countdown done) -->
        <div id="vp-unlocked-msg" style="display:none;margin-top:18px;">
            <div style="background:#f0fdf4;border:2px solid #86efac;border-radius:10px;padding:14px 18px;color:#16a34a;font-weight:600;margin-bottom:14px;">
                <i class="fas fa-lock-open me-2"></i><?= $_t('समय सकियो। अब पुनः प्रयास गर्न सक्नुहुन्छ।', 'Time is up. You can try again now.') ?>
            </div>
            <a href="verify.php" style="display:inline-flex;align-items:center;gap:8px;padding:12px 28px;background:linear-gradient(135deg,var(--primary-color,#1a5f2a),#0e9b53);color:#fff;text-decoration:none;border-radius:8px;font-weight:700;font-size:.95rem;">
                <i class="fas fa-rotate-right"></i> <?= $_t('फेरि प्रयास गर्नुहोस्', 'Try Again') ?>
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
<div class="vp-alert-error">
    <i class="fas fa-exclamation-circle" style="font-size:1.2rem;flex-shrink:0;"></i>
    <span><?= htmlspecialchars($__err) ?></span>
</div>
<?php endif; ?>

<?php if (!empty($result['ok'])): ?>
<!-- ── Verification Success ── -->
<?php $__m = $result['member'] ?? []; $__c = $result['card'] ?? []; ?>
<div class="vp-result-card">
    <div class="vp-result-head">
        <?php if (!empty($__m['photo_path'])): ?>
        <img src="<?= htmlspecialchars(rtrim(SITE_URL,'/') . '/' . ltrim($__m['photo_path'],'/')) ?>"
             alt="" class="vp-result-photo">
        <?php else: ?>
        <span class="vp-result-icon">
            <i class="fas fa-check-circle"></i>
        </span>
        <?php endif; ?>
        <div>
            <div class="vp-result-name"><?= htmlspecialchars($__m['full_name'] ?? '') ?></div>
            <div class="vp-result-sub"><?= $_t('कार्ड सक्रिय र वैध छ।', 'Card is active and valid.') ?></div>
        </div>
    </div>
    <div class="vp-result-body">
        <?php
        $__secretCvv = (string)($__c['secret_cvv'] ?? '');
        $__fields = [
            [$_t('सदस्यता नं.','Member ID'),  $__m['member_id']   ?? ''],
            [$_t('कार्ड नं.','Card No.'),      $__c['card_no']     ?? ''],
            [$_t('मोबाइल','Mobile'),            $__m['mobile']      ?? ''],
            [$_t('सदस्यता मिति','Member Since'),$__m['member_since']?? ''],
            [$_t('जारी मिति','Issued'),          $__c['issued_date'] ?? ''],
            [$_t('म्याद समाप्ति','Valid Until'), $__c['expires_at']  ?? ''],
        ];
        foreach ($__fields as [$lbl, $val]):
            if (trim((string)$val) === '') continue;
        ?>
        <div class="vp-result-row">
            <span class="vp-result-label"><?= htmlspecialchars($lbl) ?></span>
            <span class="vp-result-value"><?= htmlspecialchars((string)$val) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ($__secretCvv !== ''): ?>
        <div class="vp-result-row vp-secret-row">
            <span class="vp-result-label"><?= $_t('गोप्य CVV / Secret Code','Secret CVV Code') ?></span>
            <span class="vp-result-value vp-secret-code"><?= htmlspecialchars($__secretCvv) ?></span>
        </div>
        <p class="vp-secret-hint"><?= $_t('यो कोड नामको पहिलो ३ अक्षर + सदस्यता नं. को पछिल्लो ४ अङ्कबाट बनेको हो (tracker जस्तै)।', 'Built from first 3 letters of first name + last 4 digits of member ID (like a tracker secret).') ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($logSaved)): ?>
<div class="vp-success-alert">
    <i class="fas fa-check me-2"></i><?= $_t('सेवा सफलतापूर्वक रेकर्ड भयो। अर्को सेवा पनि लग गर्न मिल्छ।', 'Service log recorded. You can log another service below.') ?>
</div>
<?php endif; ?>
<?php if (!empty($logError)): ?>
<div class="vp-alert-error" style="margin-top:12px;">
    <i class="fas fa-exclamation-circle"></i>
    <span><?= htmlspecialchars($logError) ?></span>
</div>
<?php endif; ?>

<?php if (!empty($partners)): ?>
<div class="vp-programs-card vp-partner-log-card" style="margin-top:14px;" id="vpPartnerLog">
    <h3 class="vp-programs-title">
        <i class="fas fa-handshake"></i> <?= $_t('साझेदार सेवा लग','Log partner service') ?>
    </h3>
    <p class="vp-partner-log-hint">
        <?= $_t('यो सदस्यले तपाईंको संस्थामा सेवा/छुट लिए भने तलबाट लग गर्नुहोस् — सदस्य पोर्टलमा इतिहास देखिन्छ।', 'If this member used your discount/service, log it below — it appears in their member portal history.') ?>
    </p>
    <form method="POST" action="" class="vp-partner-log-form">
        <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
        <input type="hidden" name="action" value="log_service">
        <input type="hidden" name="member_id" value="<?= (int)($__m['id'] ?? 0) ?>">
        <input type="hidden" name="member_card_no" value="<?= htmlspecialchars((string)($__c['card_no'] ?? $__m['member_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="verify_mode" value="<?= htmlspecialchars($verifyMode === 'legacy' ? 'legacy' : 'name') ?>">
        <input type="hidden" name="member_name" value="<?= htmlspecialchars($verifyName !== '' ? $verifyName : (string)($__m['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="member_id_no" value="<?= htmlspecialchars($verifyMemberId !== '' ? $verifyMemberId : (string)($__m['member_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="code" value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="cvv" value="<?= htmlspecialchars($cvv, ENT_QUOTES, 'UTF-8') ?>">

        <div class="vp-field">
            <label class="vp-label"><?= $_t('Desk code बाट छिटो','Quick by desk code') ?></label>
            <input type="text" id="vpPartnerCodeQuick" class="vp-input" placeholder="PF-XXXXXX" autocomplete="off" spellcheck="false" style="letter-spacing:.04em;text-transform:uppercase;">
            <div class="vp-partner-code-hint"><?= $_t('Code टाइप गर्दा तलको सूची auto-select हुन्छ।', 'Typing a code auto-selects the partner below.') ?></div>
        </div>
        <div class="vp-field">
            <label class="vp-label"><?= $_t('साझेदार संस्था','Partner organization') ?> <span class="req">*</span></label>
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
                        data-needs-pin="<?= !empty($p['needs_pin']) ? '1' : '0' ?>"<?= $sel ?>>
                    <?= htmlspecialchars($opt) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="vp-field" id="vpPartnerPinWrap" style="display:none;">
            <label class="vp-label"><?= $_t('Desk PIN','Desk PIN') ?> <span class="req">*</span></label>
            <input type="password" name="partner_pin" id="vpPartnerPin" class="vp-input" autocomplete="off" placeholder="••••">
        </div>
        <div class="vp-field">
            <label class="vp-label"><?= $_t('सेवा / वस्तु','Service / item') ?></label>
            <input type="text" name="service_name" class="vp-input" maxlength="255" placeholder="<?= $_t('जस्तै: ल्याब टेस्ट, खाना','e.g. Lab test, meal') ?>" value="">
        </div>
        <div class="vp-field">
            <label class="vp-label"><?= $_t('नोट','Note') ?></label>
            <input type="text" name="service_note" class="vp-input" maxlength="500" placeholder="<?= $_t('ऐच्छिक','Optional') ?>">
        </div>
        <div class="vp-field" style="margin-bottom:14px;">
            <label class="vp-label"><?= $_t('सेवा लिइयो?','Service taken?') ?></label>
            <select name="service_taken" class="vp-input">
                <option value="yes" selected><?= $_t('हो — लिए','Yes — taken') ?></option>
                <option value="no"><?= $_t('होइन — verify मात्र','No — verify only') ?></option>
            </select>
        </div>
        <button type="submit" class="vp-btn vp-partner-log-btn">
            <i class="fas fa-save"></i> <?= $_t('सेवा लग सेभ गर्नुहोस्','Save service log') ?>
        </button>
    </form>
</div>
<script>
(function(){
    var sel = document.getElementById('vpPartnerSelect');
    var wrap = document.getElementById('vpPartnerPinWrap');
    var pin = document.getElementById('vpPartnerPin');
    var quick = document.getElementById('vpPartnerCodeQuick');
    if (!sel || !wrap) return;
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
    if (box) box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    <?php endif; ?>
})();
</script>
<?php elseif (empty($partners) && !empty($result['ok'])): ?>
<div class="vp-programs-card" style="margin-top:14px;">
    <p style="font-size:.85rem;color:#6b7280;margin:0;">
        <?= $_t('अहिले सक्रिय साझेदार सुविधा सूचीमा छैन।','No active partner facilities are listed yet.') ?>
        <a href="<?= htmlspecialchars(rtrim(SITE_URL,'/') . '/partner-facilities.php') ?>"><?= $_t('सूची हेर्नुहोस्','Browse list') ?></a>
    </p>
</div>
<?php endif; ?>

<?php if (!empty($programSaved)): ?>
<div class="vp-success-alert">
    <i class="fas fa-check me-2"></i><?= $_t('उपस्थिति दर्ता भयो।', 'Attendance recorded.') ?>
</div>
<?php endif; ?>

<?php if (!empty($activePrograms)): ?>
<div class="vp-programs-card">
    <h3 class="vp-programs-title">
        <i class="fas fa-calendar-check"></i> <?= $_t('सक्रिय कार्यक्रमहरू','Active Programs') ?>
    </h3>
    <div>
    <?php foreach ($activePrograms as $prog): ?>
        <div class="vp-program-item">
            <strong><?= htmlspecialchars($prog['title'] ?? '') ?></strong>
            <?php if (!empty($prog['program_date'])): ?>
            <span style="color:#6b7280;font-size:.82rem;margin-left:8px;"><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($prog['program_date']) ?></span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Re-verify or new search -->
<div class="vp-reverify-link">
    <a href="verify.php">
        <i class="fas fa-arrow-left me-1"></i><?= $_t('अर्को कार्ड प्रमाणित गर्नुहोस्','Verify another card') ?>
    </a>
</div>

<?php else: ?>
<!-- ── Verification Form ── -->
<div class="vp-main-card">
    <div class="vp-card-head">
        <div class="vp-card-head-icon"><i class="fas fa-id-card"></i></div>
        <div class="vp-card-head-text">
            <div class="vp-card-head-title"><?= htmlspecialchars($__pageTitleDisplay) ?></div>
            <div class="vp-card-head-sub"><?= $_t('सदस्यको नाम र सदस्यता नं. राखेर प्रमाणित गर्नुहोस्। साझेदार डेस्कले सेवा लग पनि गर्न सक्छ।', 'Verify with member name and member ID. Partner desks can also log services after verify.') ?></div>
        </div>
    </div>
    <div class="vp-card-body">
        <form method="POST" action="" id="vpVerifyForm">
            <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
            <input type="hidden" name="verify_mode" id="vpVerifyMode" value="<?= htmlspecialchars($verifyMode === 'legacy' ? 'legacy' : 'name') ?>">

            <div id="vpModeName" style="<?= $verifyMode === 'legacy' ? 'display:none;' : '' ?>">
                <div class="vp-field">
                    <label class="vp-label">
                        <i class="fas fa-user" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                        <?= $_t('सदस्यको नाम', 'Member Name') ?> <span class="req">*</span>
                    </label>
                    <input type="text" name="member_name" class="vp-input" id="vpMemberName"
                           value="<?= htmlspecialchars($verifyName ?? '') ?>"
                           placeholder="<?= $_t('कार्डमा लेखिएको पूरा नाम', 'Full name as on card') ?>"
                           autocomplete="name" <?= $verifyMode === 'legacy' ? '' : 'required' ?>>
                </div>
                <div class="vp-field">
                    <label class="vp-label">
                        <i class="fas fa-hashtag" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                        <?= $_t('सदस्यता नं. / Member ID', 'Member ID') ?> <span class="req">*</span>
                    </label>
                    <input type="text" name="member_id_no" class="vp-input" id="vpMemberId"
                           value="<?= htmlspecialchars($verifyMemberId ?? '') ?>"
                           placeholder="<?= $_t('कार्डमा देखिने सदस्यता नं.', 'Member ID shown on card') ?>"
                           autocomplete="off" spellcheck="false" <?= $verifyMode === 'legacy' ? '' : 'required' ?>>
                </div>
                <div class="vp-field" style="margin-bottom:22px;">
                    <label class="vp-label">
                        <i class="fas fa-lock" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                        <?= $_t('CVV (ऐच्छिक)', 'CVV (optional)') ?>
                    </label>
                    <input type="text" name="cvv" maxlength="20" class="vp-input" id="vpCvv"
                           value="<?= htmlspecialchars($cvv ?? '') ?>"
                           placeholder="<?= $_t('खाली छोड्न सकिन्छ — मिल्दा गोप्य कोड खुल्छ', 'Can leave blank — secret code appears on match') ?>"
                           autocomplete="off" spellcheck="false" style="letter-spacing:1px;">
                    <div style="font-size:.78rem;color:#6b7280;margin-top:6px;">
                        <?= $_t('CVV कार्डको पछाडि छ (नामको पहिलो ३ + सदस्यताको पछिल्लो ४)। खाली छोड्न पनि सकिन्छ।', 'CVV is on the card back (first 3 of name + last 4 of member ID). You can also leave it blank.') ?>
                    </div>
                </div>
            </div>

            <div id="vpModeLegacy" style="<?= $verifyMode === 'legacy' ? '' : 'display:none;' ?>">
                <div class="vp-field">
                    <label class="vp-label">
                        <i class="fas fa-key" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                        <?= $_t('पुरानो Verification Code', 'Legacy Verification Code') ?> <span class="req">*</span>
                    </label>
                    <input type="text" name="code" class="vp-input" id="vpCode"
                           value="<?= htmlspecialchars($code ?? '') ?>"
                           placeholder="<?= $_t('जस्तै: AKS-XXXX-XXXX', 'e.g. AKS-XXXX-XXXX') ?>"
                           autocomplete="off" spellcheck="false" style="letter-spacing:.5px;" <?= $verifyMode === 'legacy' ? 'required' : '' ?>>
                </div>
                <div class="vp-field" style="margin-bottom:22px;">
                    <label class="vp-label">
                        <i class="fas fa-lock" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                        <?= $_t('CVV', 'CVV') ?> <span class="req">*</span>
                    </label>
                    <input type="password" name="cvv_legacy" maxlength="20" class="vp-input" id="vpCvvLegacy"
                           placeholder="****" autocomplete="off" style="letter-spacing:4px;" <?= $verifyMode === 'legacy' ? 'required' : '' ?>>
                    <div style="font-size:.78rem;color:#6b7280;margin-top:6px;">
                        <?= $_t('पुराना कार्डका लागि मात्र।', 'For older cards only.') ?>
                    </div>
                </div>
            </div>

            <button type="submit" class="vp-btn">
                <i class="fas fa-shield-halved"></i> <?= $_t('प्रमाणित गर्नुहोस्', 'Verify Now') ?>
            </button>
            <button type="button" class="vp-btn-link" id="vpToggleMode" style="display:block;width:100%;margin-top:14px;background:none;border:none;color:#6b7280;font-size:.85rem;cursor:pointer;text-decoration:underline;">
                <?= $verifyMode === 'legacy'
                    ? $_t('← नाम + सदस्यता नं. बाट verify', '← Verify with name + member ID')
                    : $_t('पुरानो Verification Code प्रयोग गर्ने?', 'Use legacy verification code?') ?>
            </button>
        </form>
    </div>
</div>

<style>
.vp-secret-row { background: color-mix(in srgb, var(--primary-color,#1a5f2a) 8%, #fff); border-radius: 10px; padding: 10px 12px; margin-top: 8px; }
.vp-secret-code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-weight: 800; letter-spacing: .12em; color: var(--primary-dark,#0a4a25); font-size: 1.15rem; }
.vp-secret-hint { font-size: .78rem; color: #6b7280; margin: 8px 0 0; line-height: 1.45; }
</style>
<script>
(function () {
    var modeInput = document.getElementById('vpVerifyMode');
    var btn = document.getElementById('vpToggleMode');
    var nameBox = document.getElementById('vpModeName');
    var legacyBox = document.getElementById('vpModeLegacy');
    var nameEl = document.getElementById('vpMemberName');
    var midEl = document.getElementById('vpMemberId');
    var codeEl = document.getElementById('vpCode');
    var cvvEl = document.getElementById('vpCvv');
    var cvvLegacy = document.getElementById('vpCvvLegacy');
    var form = document.getElementById('vpVerifyForm');
    if (!btn || !modeInput) return;

    var labels = {
        toLegacy: <?= json_encode($_t('पुरानो Verification Code प्रयोग गर्ने?', 'Use legacy verification code?')) ?>,
        toName: <?= json_encode($_t('← नाम + सदस्यता नं. बाट verify', '← Verify with name + member ID')) ?>
    };

    function setMode(mode) {
        var legacy = mode === 'legacy';
        modeInput.value = legacy ? 'legacy' : 'name';
        if (nameBox) nameBox.style.display = legacy ? 'none' : '';
        if (legacyBox) legacyBox.style.display = legacy ? '' : 'none';
        if (nameEl) nameEl.required = !legacy;
        if (midEl) midEl.required = !legacy;
        if (codeEl) codeEl.required = legacy;
        if (cvvLegacy) cvvLegacy.required = legacy;
        btn.textContent = legacy ? labels.toName : labels.toLegacy;
    }

    btn.addEventListener('click', function () {
        setMode(modeInput.value === 'legacy' ? 'name' : 'legacy');
    });

    if (form) {
        form.addEventListener('submit', function () {
            // Map legacy CVV field into shared name=cvv for PHP
            if (modeInput.value === 'legacy' && cvvLegacy && cvvEl) {
                cvvEl.name = '';
                cvvLegacy.name = 'cvv';
            } else if (cvvLegacy) {
                cvvLegacy.name = 'cvv_legacy';
                if (cvvEl) cvvEl.name = 'cvv';
            }
        });
    }
})();
</script>

<div class="vp-secure">
    <i class="fas fa-shield-halved" style="margin-right:4px;"></i>
    <?= $_t('यो पृष्ठ सुरक्षित र निजी छ।', 'This page is secure and private.') ?>
</div>
<?php endif; ?>

</div><!-- /.vp-outer -->
</body>
</html>
