<?php
/**
 * म्याद सकिएपछि साधारण admin — सन्देश, Pay Now + भुक्तानी सूचना फारम (Superadmin बाहेक)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/site-license-renewal.php';

if (!isAdminLoggedIn()) {
    header('Location: ' . ADMIN_URL . 'index.php');
    exit;
}
if (!empty($_SESSION['is_superadmin'])) {
    header('Location: ' . ADMIN_URL . 'site-license.php');
    exit;
}

if (function_exists('site_license_expired') && !site_license_expired()) {
    header('Location: ' . ADMIN_URL . 'dashboard.php');
    exit;
}

$blockedRenewalError = '';
$blockedRenewalSent = isset($_GET['renewal_sent']) && (string) $_GET['renewal_sent'] === '1';

$dbBlocked = null;
$blockedPendingRow = null;
try {
    $dbBlocked = getDB();
    ensureSiteLicenseRenewalNoticesTable($dbBlocked);
    if (site_license_renewal_pending_count($dbBlocked) > 0) {
        $blockedPendingRow = $dbBlocked->query("SELECT * FROM site_license_renewal_notices WHERE status = 'pending' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    $dbBlocked = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'submit_renewal_notice_blocked') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $blockedRenewalError = 'सुरक्षा जाँच असफल। पृष्ठ refresh गरी पुन: प्रयास गर्नुहोस्।';
    } elseif ($dbBlocked === null) {
        $blockedRenewalError = 'डेटाबेस जडान हुन सकेन।';
    } elseif (!checkRateLimit('site_license_blocked_renewal', 20, 3600)) {
        $blockedRenewalError = 'धेरै पटक पठाइयो। केही समय पछि प्रयास गर्नुहोस्।';
    } else {
        $gateway = trim((string) ($_POST['gateway'] ?? ''));
        $txn = trim((string) ($_POST['txn_reference'] ?? ''));
        $note = trim((string) ($_POST['renewal_note'] ?? ''));
        $submitter = function_exists('getSetting') ? trim((string) getSetting('site_name', 'सहकारी')) : '';
        $aid = (int) ($_SESSION['admin_id'] ?? 0);
        $apply = site_license_renewal_apply_office_notice($dbBlocked, $gateway, $txn, $note, $submitter, $aid > 0 ? $aid : null);
        if (!$apply['ok']) {
            $blockedRenewalError = $apply['error'] ?? 'त्रुटि।';
        } else {
            $newId = (int) ($apply['id'] ?? 0);
            $amtStored = trim((string) getSetting('site_license_renewal_amount', ''));
            site_license_renewal_notify_vendor($dbBlocked, [
                'id' => $newId,
                'gateway' => $gateway,
                'txn_reference' => $txn,
                'amount_reported' => $amtStored,
                'note' => $note,
                'submitted_by_username' => $submitter,
            ]);
            header('Location: ' . ADMIN_URL . 'site-license-blocked.php?renewal_sent=1');
            exit;
        }
    }
}

try {
    if ($dbBlocked !== null) {
        if (site_license_renewal_pending_count($dbBlocked) > 0) {
            $blockedPendingRow = $dbBlocked->query("SELECT * FROM site_license_renewal_notices WHERE status = 'pending' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $blockedPendingRow = null;
        }
    }
} catch (Throwable $e) {
    /* ignore */
}

$blockedPayAmount = trim((string) getSetting('site_license_renewal_amount', ''));
$blockedKhalti = site_license_pay_id_or_default((string) getSetting('site_license_khalti_id', ''));
$blockedEsewa = site_license_pay_id_or_default((string) getSetting('site_license_esewa_id', ''));
$coopNameRenewal = function_exists('getSetting') ? trim((string) getSetting('site_name', '')) : '';
$blockedSubmitterDefault = $coopNameRenewal !== ''
    ? $coopNameRenewal
    : trim((string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? ''));
$blockedSubmitterCoopReadonly = ($coopNameRenewal !== '');

$site = function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी';
$siteH = htmlspecialchars($site, ENT_QUOTES, 'UTF-8');
$untilBs = function_exists('site_license_until_bs') ? site_license_until_bs() : '';
$untilBsNp = function_exists('site_license_until_bs_np') ? site_license_until_bs_np() : '';
$untilAd = function_exists('site_license_until_ad') ? site_license_until_ad() : '';
$untilBsH = htmlspecialchars($untilBs, ENT_QUOTES, 'UTF-8');
$untilBsNpH = htmlspecialchars($untilBsNp, ENT_QUOTES, 'UTF-8');
$untilAdH = htmlspecialchars($untilAd, ENT_QUOTES, 'UTF-8');
$showPayForm = ($blockedPendingRow === null && !$blockedRenewalSent);
?>
<!DOCTYPE html>
<html lang="ne">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>म्याद सकियो — Admin</title>
    <link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Mukta:wght@400;500;600;700;800&family=Noto+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app-admin.css">
    <style>
        /* Match admin panel font stack */
        body, .slb-shell, .slb-card-main, .slb-card-head, .slb-card-body,
        .slb-card-head h1, .slb-dates-box, .slb-pill, .slb-steps,
        .slb-vendor-note, .slb-btn-logout, .slb-amt-locked,
        .form-label, .form-control, .form-select, .btn, .alert {
            font-family: 'Mukta', 'Noto Sans', system-ui, -apple-system, sans-serif !important;
        }
        /* Dates box — consistent card styling */
        .slb-dates-box {
            background: #f8faf8;
            border: 1px solid #d1e7d4;
            border-radius: 10px;
            padding: 14px 18px;
            margin: 16px auto;
            max-width: 380px;
            text-align: left;
            font-size: 0.93rem;
            line-height: 1.6;
        }
        .slb-dates-box .lbl {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #6b7280;
            margin-bottom: 6px;
        }
        .slb-dates-box code {
            background: #e8f5ea;
            color: #1a5f2a;
            border-radius: 4px;
            padding: 1px 6px;
            font-size: 0.88rem;
            font-family: 'Mukta', monospace;
        }
    </style>
</head>
<body>
<div class="slb-shell">
    <div class="slb-card-main">
        <div class="slb-card-head">
            <div class="slb-ico-wrap"><i class="fas fa-calendar-xmark" aria-hidden="true"></i></div>
            <h1>साइट सेवा म्याद सकियो</h1>
            <p class="sub"><strong><?php echo $siteH; ?></strong> — साधारण Admin को प्यानल अस्थायी बन्द छ। तल नवीकरण वा भुक्तानी सूचना गर्नुहोस्।</p>
        </div>
        <div class="slb-card-body text-center">
            <div class="slb-pill-row">
                <span class="slb-pill"><i class="fas fa-circle-exclamation me-1"></i>म्याद सकियो · Expired</span>
            </div>

            <div class="slb-vendor-note">
                <strong>सूचना:</strong> SSL certificates तथा domain active शुल्क कृपया तुरुन्तै अनलाइनमार्फत भुक्तानी गर्नुहोला, अन्यथा domain स्वतः suspend हुन सक्नेछ।
                अन्य cloud, maintenance, support तथा license सम्बन्धी लागतको विस्तृत जानकारी तथा भुक्तानी प्रक्रियाका लागि कृपया सम्बन्धित vendor सँग सम्पर्क गर्नुहुन अनुरोध गरिन्छ।
            </div>

            <?php if ($untilBs !== ''): ?>
            <div class="slb-dates-box">
                <div class="lbl">अन्तिम वैध दिन / Last valid day</div>
                <div><strong>बि.सं.</strong> <?php echo $untilBsNpH; ?> <span class="text-muted">(<code><?php echo $untilBsH; ?></code>)</span></div>
                <?php if ($untilAd !== ''): ?>
                <div class="mt-2"><strong>ई.सं. / AD</strong> <code><?php echo $untilAdH; ?></code></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($blockedRenewalError !== ''): ?>
            <div class="alert alert-danger text-start small py-2 mb-3"><?php echo htmlspecialchars($blockedRenewalError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($blockedRenewalSent): ?>
            <div class="alert alert-success text-start small py-3 mb-3">
                <strong><i class="fas fa-check-circle me-1"></i>भुक्तानी सूचना पठाइयो।</strong>
                भुक्तानी सूचना प्राप्त भएको छ। पुष्टि/सक्रिय हुन केही समय प्रतीक्षा गर्नुहोस् वा विक्रेता सम्पर्क गर्नुहोस्।
            </div>
            <?php elseif ($blockedPendingRow !== null): ?>
            <div class="alert alert-warning text-start small py-3 mb-3">
                <div class="fw-bold mb-2"><i class="fas fa-hourglass-half me-1"></i>भुक्तानी सूचना पेन्डिङ</div>
                <ul class="mb-0 ps-3">
                    <li>गेटवेइ: <strong><?php echo htmlspecialchars((string) $blockedPendingRow['gateway'], ENT_QUOTES, 'UTF-8'); ?></strong></li>
                    <li>Txn / Ref: <strong><?php echo htmlspecialchars((string) $blockedPendingRow['txn_reference'], ENT_QUOTES, 'UTF-8'); ?></strong></li>
                    <?php if (trim((string) $blockedPendingRow['amount_reported']) !== ''): ?>
                    <li>रकम (सेटिङ): <strong><?php echo htmlspecialchars((string) $blockedPendingRow['amount_reported'], ENT_QUOTES, 'UTF-8'); ?></strong></li>
                    <?php endif; ?>
                </ul>
                <div class="mt-2 text-muted">पुष्टि/सक्रिय अपडेट नआउँदासम्म प्रतीक्षा गर्नुहोस्। दोहोरो भुक्तानी नगर्नुहोस्।</div>
            </div>
            <?php elseif ($showPayForm): ?>
            <div class="slb-pay-box">
                <div class="fw-bold mb-2 text-success"><i class="fas fa-mobile-screen-button me-1"></i>Pay Now — आफ्नो wallet बाट पठाउनुहोस्</div>
                <p class="small text-secondary mb-2">तलका नम्बर <strong>विक्रेता खाता</strong> हुन्। आफ्नो Khalti वा eSewa बाट Send/Transfer गर्नुहोस्।</p>
                <?php if ($blockedPayAmount !== ''): ?>
                <p class="mb-1 small"><strong>रकम (नवीकरण सेटिङ — बदल्न मिल्दैन):</strong></p>
                <div class="slb-amt-locked mb-2 text-start"><?php echo htmlspecialchars($blockedPayAmount, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                <p class="small text-warning mb-2">रकम अझै सेट भएको छैन। व्यवस्थापक वा विक्रेता सम्पर्क गर्नुहोस्।</p>
                <?php endif; ?>
                <?php if ($blockedKhalti !== ''): ?>
                <p class="mb-1 small"><strong>Khalti:</strong> <code><?php echo htmlspecialchars($blockedKhalti, ENT_QUOTES, 'UTF-8'); ?></code></p>
                <?php endif; ?>
                <?php if ($blockedEsewa !== ''): ?>
                <p class="mb-0 small"><strong>eSewa:</strong> <code><?php echo htmlspecialchars($blockedEsewa, ENT_QUOTES, 'UTF-8'); ?></code></p>
                <?php endif; ?>
            </div>

            <form method="post" class="text-start mb-3">
                <input type="hidden" name="action" value="submit_renewal_notice_blocked">
                <?php echo csrfField(); ?>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">संस्थाको नाम (साइट सेटिङबाट स्वतः)</label>
                    <div class="form-control form-control-sm" style="background:#f3f4f6;cursor:default;"><?php echo htmlspecialchars($blockedSubmitterDefault !== '' ? $blockedSubmitterDefault : 'सहकारी', ENT_QUOTES, 'UTF-8'); ?></div>
                    <input type="hidden" name="submitter_name" value="<?php echo htmlspecialchars($blockedSubmitterDefault !== '' ? $blockedSubmitterDefault : 'सहकारी', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">गेटवेइ</label>
                    <select name="gateway" class="form-select form-select-sm" required>
                        <option value="khalti">Khalti</option>
                        <option value="esewa" selected>eSewa</option>
                        <option value="other">अन्य</option>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">कारोबार नम्बर / Ref <span class="text-danger">*</span></label>
                    <input type="text" name="txn_reference" class="form-control form-control-sm" required minlength="3" maxlength="180" placeholder="wallet ref" autocomplete="off">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">टिप्पणी</label>
                    <textarea name="renewal_note" class="form-control form-control-sm" rows="2" maxlength="2000" placeholder="ऐच्छिक"></textarea>
                </div>
                <button type="submit" class="btn btn-success w-100"><i class="fas fa-paper-plane me-1"></i>Pay SSL certificates तथा domain active Charge now</button>
            </form>
            <?php endif; ?>

            <div class="slb-steps">
                <div class="fw-semibold text-success mb-2 small"><i class="fas fa-list-check me-1"></i>संक्षेप</div>
                <ol class="mb-0">
                    <li>यो पृष्ठ कार्यालय/साधारण Admin का लागि हो — माथि Pay Now + सूचना उपलब्ध छ।</li>
                    <li>भुक्तानी सूचना पठाएपछि व्यवस्थापनतर्फबाट पुष्टि/सक्रिय प्रक्रिया हुन्छ।</li>
                </ol>
            </div>

            <a href="logout.php" class="btn btn-outline-secondary slb-btn-logout">
                <i class="fas fa-right-from-bracket me-2"></i>लगआउट
            </a>
        </div>
    </div>
</div>
</body>
</html>
