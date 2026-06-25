<?php
/**
 * ════════════════════════════════════════════════════════════
 * PUBLIC MEMBER VERIFICATION — v10.2
 * ────────────────────────────────────────────────────────────
 * URL: /verify.php
 *
 * कुनै पनि व्यक्ति (हस्पिटल, पसल, अन्य संस्था) ले member ले
 * देखाएको ID Card को Verification Code (AKS-XXXX-XXXX) र
 * 4-अङ्कको CVV enter गरेर तुरुन्तै सक्रिय सदस्य हो/होइन
 * verify गर्न सक्छन्।
 *
 * Card duplicate/नक्कली कि होइन check गर्न सजिलो।
 * ════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/card-verify-helpers.php';
require_once __DIR__ . '/includes/program-tables.php';
require_once __DIR__ . '/includes/member-partner-services-tables.php';
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
    }
} catch (\Throwable $_e) {
    $_dbError = 'DB जडान भएन। कृपया पछि प्रयास गर्नुहोस्।';
    error_log('[verify.php] DB error: ' . $_e->getMessage());
}

$result = null;
$code   = '';
$cvv    = '';
$logSaved = false;
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
    $code = (string)($_POST['code'] ?? '');
    $code = function_exists('normalizeCardCode') ? normalizeCardCode($code) : $code;
    $cvv  = (string)($_POST['cvv']  ?? '');
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    /* (a) Service-log POST — verify पछि सेवा लिएको record छुट्टै submit */
    if ($postCsrfError !== '') {
        $result = ['ok' => false, 'error' => $postCsrfError];
    } elseif (($_POST['action'] ?? '') === 'log_service') {
        $mid       = (int)($_POST['member_id'] ?? 0);
        $cardNo    = trim($_POST['member_card_no'] ?? '');
        $partnerId = (int)($_POST['partner_id'] ?? 0);
        $partnerNm = trim($_POST['partner_name'] ?? '');
        $serviceNm = trim($_POST['service_name'] ?? '');
        $taken     = (isset($_POST['service_taken']) && $_POST['service_taken'] === 'yes') ? 1 : 0;
        $note      = trim($_POST['service_note'] ?? '');
        if ($mid && $partnerNm !== '' && $partnerId > 0) {
            try {
                $ins = $pdo->prepare("INSERT INTO member_partner_services
                    (member_id, member_card_no, partner_id, partner_name, service_name, service_taken, service_note, verified_by_ip)
                    VALUES (?,?,?,?,?,?,?,?)");
                $ins->execute([$mid, $cardNo, $partnerId, $partnerNm, mb_substr($serviceNm, 0, 255), $taken, mb_substr($note, 0, 500), $ip]);
                $logSaved = true;
            } catch (\Throwable $e) { error_log('mps insert: ' . $e->getMessage()); }
        }
        /* re-verify so the success card stays visible after logging */
        $code = trim($_POST['code'] ?? '');
        $code = function_exists('normalizeCardCode') ? normalizeCardCode($code) : $code;
        $cvv  = trim($_POST['cvv']  ?? '');
        if ($code && $cvv) {
            $result = verifyCardCredentials($pdo, $code, $cvv, $ip);
        }
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
        $mid         = (int)($_POST['member_id'] ?? 0);
        $cardNo      = trim($_POST['member_card_no'] ?? '');
        $programId   = (int)($_POST['program_id'] ?? 0);
        $programTit  = trim($_POST['program_title'] ?? '');
        $isPriority  = !empty($_POST['is_priority']) ? 1 : 0;
        $note        = trim($_POST['attendance_note'] ?? '');
        if ($mid > 0 && $programId > 0 && $programTit !== '') {
            try {
                $chk = $pdo->prepare("SELECT id FROM member_program_attendance WHERE member_id=? AND program_id=? LIMIT 1");
                $chk->execute([$mid, $programId]);
                $exists = $chk->fetchColumn();
                if ($exists) {
                    $programAlreadyRegistered = true;
                } else {
                    $ins = $pdo->prepare("INSERT INTO member_program_attendance
                        (member_id, member_card_no, program_id, program_title, is_priority, attendance_note, verified_by_ip, source)
                        VALUES (?,?,?,?,?,?,?,?)");
                    $ins->execute([$mid, $cardNo, $programId, mb_substr($programTit, 0, 180), $isPriority, mb_substr($note, 0, 500), $ip, 'verify_portal']);
                    $programSaved = true;
                }
            } catch (\Throwable $e) { error_log('program attendance insert: ' . $e->getMessage()); }
        }
        $code = trim($_POST['code'] ?? '');
        $code = function_exists('normalizeCardCode') ? normalizeCardCode($code) : $code;
        $cvv  = trim($_POST['cvv']  ?? '');
        if ($code && $cvv) {
            $result = verifyCardCredentials($pdo, $code, $cvv, $ip);
        }
    } else {
        $result = verifyCardCredentials($pdo, $code, $cvv, $ip);
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
if ($result && !empty($result['ok'])) {
    try {
        $partners = $pdo->query("SELECT id, partner_name FROM partner_facilities WHERE is_active=1 ORDER BY partner_name ASC LIMIT 50")
                        ->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $partners = []; }
}
?>
<!DOCTYPE html>
<html lang="<?php echo isEnglish() ? 'en' : 'ne'; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="<?php echo htmlspecialchars($_t('Member ID card सत्यता check गर्नुहोस्। Verification Code र CVV राखेर सक्रिय सदस्य हो/होइन प्रमाणित गर्नुहोस्।', 'Check Member ID card authenticity. Verify active membership using Verification Code and CVV.'), ENT_QUOTES, 'UTF-8'); ?>">
<?php if (function_exists('seo_canonical_url')): ?>
<link rel="canonical" href="<?= htmlspecialchars(seo_canonical_url(), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Mukta:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">

<?php if (function_exists('coopThemeHeadAssets')) { coopThemeHeadAssets('verify', ['skip_fonts' => true]); } ?>
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
.vp-logo-wrap { text-align: center; margin-bottom: 1.5rem; }
.vp-logo-wrap img { max-height: 64px; max-width: 180px; width: auto; height: auto; object-fit: contain; border-radius: 8px; display: block; margin: 0 auto .6rem; }
.vp-logo-icon { width: 62px; height: 62px; border-radius: 50%; margin: 0 auto .65rem; background: var(--primary-color, #1a5f2a); color: var(--text-on-primary, #fff); font-size: 1.45rem; display: grid; place-items: center; box-shadow: 0 4px 18px rgba(var(--primary-rgb, 26,95,42), .28); }
.vp-site-name { font-weight: 700; font-size: 1.02rem; color: var(--primary-color, #1a5f2a); }
.vp-site-sub  { font-size: .78rem; color: var(--text-muted, #6b7280); margin-top: 2px; }
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
        <a href="<?php echo htmlspecialchars(portalLangToggleUrl(), ENT_QUOTES, 'UTF-8'); ?>" class="vp-back-link" title="<?= htmlspecialchars($_t('भाषा परिवर्तन', 'Switch language'), ENT_QUOTES, 'UTF-8') ?>">
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
<div id="vp-ratelimit-card" style="background:#fff;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.08);overflow:hidden;margin-bottom:20px;border:2px solid #fed7aa;">
    <div style="background:linear-gradient(135deg,#ea580c,#f97316);padding:16px 22px;display:flex;align-items:center;gap:14px;">
        <span style="width:46px;height:46px;background:rgba(255,255,255,.2);border-radius:50%;display:grid;place-items:center;font-size:1.5rem;color:#fff;flex-shrink:0;">
            <i class="fas fa-shield-halved"></i>
        </span>
        <div>
            <div style="color:#fff;font-weight:700;font-size:1rem;"><?= $_t('धेरै पटक गलत प्रयास', 'Too Many Failed Attempts') ?></div>
            <div style="color:rgba(255,255,255,.88);font-size:.83rem;"><?= $_t('सुरक्षाका लागि अस्थायी ताल्चा लगाइएको छ।', 'Temporarily locked for security.') ?></div>
        </div>
    </div>
    <div style="padding:24px 22px;text-align:center;">
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
<div style="background:#fff;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.08);overflow:hidden;margin-bottom:24px;">
    <div style="background:linear-gradient(135deg,var(--primary-color,#1a5f2a),#0e9b53);padding:18px 22px;display:flex;align-items:center;gap:14px;">
        <?php if (!empty($__m['photo_path'])): ?>
        <img src="<?= htmlspecialchars(rtrim(SITE_URL,'/') . '/' . ltrim($__m['photo_path'],'/')) ?>"
             alt="" style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,.6);flex-shrink:0;">
        <?php else: ?>
        <span style="width:52px;height:52px;background:rgba(255,255,255,.22);border-radius:50%;display:grid;place-items:center;font-size:1.5rem;color:#fff;flex-shrink:0;">
            <i class="fas fa-check-circle"></i>
        </span>
        <?php endif; ?>
        <div>
            <div style="color:#fff;font-weight:700;font-size:1.1rem;"><?= htmlspecialchars($__m['full_name'] ?? '') ?></div>
            <div style="color:rgba(255,255,255,.85);font-size:.84rem;"><?= $_t('कार्ड सक्रिय र वैध छ।', 'Card is active and valid.') ?></div>
        </div>
    </div>
    <div style="padding:20px 22px;">
        <?php
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
        <div style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #f3f4f6;font-size:.93rem;">
            <span style="color:#6b7280;min-width:130px;flex-shrink:0;"><?= htmlspecialchars($lbl) ?></span>
            <span style="font-weight:600;color:#111827;"><?= htmlspecialchars((string)$val) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (!empty($logSaved)): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#16a34a;font-size:.9rem;">
    <i class="fas fa-check me-2"></i><?= $_t('सेवा सफलतापूर्वक रेकर्ड भयो।', 'Service log recorded successfully.') ?>
</div>
<?php endif; ?>
<?php if (!empty($programSaved)): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px 16px;margin-bottom:16px;color:#16a34a;font-size:.9rem;">
    <i class="fas fa-check me-2"></i><?= $_t('उपस्थिति दर्ता भयो।', 'Attendance recorded.') ?>
</div>
<?php endif; ?>

<?php if (!empty($activePrograms)): ?>
<div style="background:#fff;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:18px 22px;margin-bottom:24px;">
    <h3 style="color:var(--primary-color,#1a5f2a);font-size:1rem;margin:0 0 14px;display:flex;align-items:center;gap:8px;">
        <i class="fas fa-calendar-check"></i> <?= $_t('सक्रिय कार्यक्रमहरू','Active Programs') ?>
    </h3>
    <div style="display:flex;flex-direction:column;gap:8px;">
    <?php foreach ($activePrograms as $prog): ?>
        <div style="padding:10px 14px;background:#f9fafb;border-radius:8px;border-left:4px solid var(--primary-color,#1a5f2a);font-size:.9rem;">
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
<div style="text-align:center;margin-top:8px;">
    <a href="verify.php" style="color:var(--primary-color,#1a5f2a);font-size:.9rem;text-decoration:none;">
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
            <div class="vp-card-head-sub"><?= $_t('Verification Code र CVV राखेर प्रमाणित गर्नुहोस्।', 'Enter Verification Code and CVV to verify.') ?></div>
        </div>
    </div>
    <div class="vp-card-body">
        <form method="POST" action="">
            <?php echo function_exists('csrfInput') ? csrfInput() : ''; ?>
            <div class="vp-field">
                <label class="vp-label">
                    <i class="fas fa-key" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                    <?= $_t('Verification Code', 'Verification Code') ?> <span class="req">*</span>
                </label>
                <input type="text" name="code" required class="vp-input"
                       value="<?= htmlspecialchars($code ?? '') ?>"
                       placeholder="<?= $_t('जस्तै: AKS-2081-00123', 'e.g. AKS-2081-00123') ?>"
                       autocomplete="off" spellcheck="false" style="letter-spacing:.5px;">
            </div>
            <div class="vp-field" style="margin-bottom:22px;">
                <label class="vp-label">
                    <i class="fas fa-lock" style="color:var(--primary-color,#1a5f2a);margin-right:4px;"></i>
                    <?= $_t('4-अङ्कको CVV / PIN', '4-digit CVV / PIN') ?> <span class="req">*</span>
                </label>
                <input type="password" name="cvv" required maxlength="4" inputmode="numeric"
                       pattern="[0-9]{4}" placeholder="****" class="vp-input" style="letter-spacing:4px;">
            </div>
            <button type="submit" class="vp-btn">
                <i class="fas fa-shield-halved"></i> <?= $_t('प्रमाणित गर्नुहोस्', 'Verify Now') ?>
            </button>
        </form>
    </div>
</div>

<div class="vp-secure">
    <i class="fas fa-shield-halved" style="margin-right:4px;"></i>
    <?= $_t('यो पृष्ठ सुरक्षित र निजी छ।', 'This page is secure and private.') ?>
</div>
<?php endif; ?>

</div><!-- /.vp-outer -->
</body>
</html>
