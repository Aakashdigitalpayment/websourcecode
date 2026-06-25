<?php
/* v2: bootstrap ले config + member-auth + global error guard load गर्छ */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/kyc-capture-helpers.php';
requireMemberLogin();
memberSecurityHeaders();

$db       = getDB();
$mem      = currentMember();
$memberId = $mem['id'];
$unread   = getMemberUnreadCount($memberId);
$error = $success = '';
$kycRow = null;
$kycLocked = false;
$kycDocCols = ['photo' => true, 'signature' => false, 'left_thumb' => false, 'right_thumb' => false];
$viewName = trim((string)($mem['name'] ?? ''));
$viewEmail = trim((string)($mem['email'] ?? ''));
$viewPhone = trim((string)($mem['phone'] ?? ''));
$viewAddress = trim((string)($mem['address'] ?? ''));
$viewAvatar = trim((string)($mem['avatar_url'] ?? ''));
$kycEditUrl = SITE_URL . 'member/kyc.php';
try {
    try {
        $cols = $db->query("SHOW COLUMNS FROM kyc_applications")->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(static fn($c) => (string)($c['Field'] ?? ''), $cols ?: []);
        $kycDocCols['signature'] = in_array('signature', $names, true);
        $kycDocCols['left_thumb'] = in_array('left_thumb', $names, true);
        $kycDocCols['right_thumb'] = in_array('right_thumb', $names, true);
    } catch (Throwable $ignored) {}

    $kycMemberLinkId = (int)($mem['kyc_application_id'] ?? 0);
    if ($kycMemberLinkId > 0) {
        $ks = $db->prepare("SELECT * FROM kyc_applications WHERE id=? LIMIT 1");
        $ks->execute([$kycMemberLinkId]);
        $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$kycRow) {
        $kw = [];
        $kp = [];
        if (!empty($mem['email'])) { $kw[] = 'LOWER(email)=?'; $kp[] = strtolower(trim((string)$mem['email'])); }
        if (!empty($mem['phone'])) { $kw[] = 'mobile=?'; $kp[] = preg_replace('/[^0-9]/', '', (string)$mem['phone']); }
        if (!empty($kw)) {
            $ks = $db->prepare("SELECT *
                                FROM kyc_applications
                                WHERE (" . implode(' OR ', $kw) . ")
                                ORDER BY id DESC LIMIT 1");
            $ks->execute($kp);
            $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($kycRow && empty($mem['kyc_application_id'])) {
                $db->prepare("UPDATE members SET kyc_application_id=? WHERE id=?")->execute([(int)$kycRow['id'], $memberId]);
                $mem['kyc_application_id'] = (int)$kycRow['id'];
            }
        }
    }
    if ($kycRow) {
        if ($kycDocCols['signature'] || $kycDocCols['left_thumb'] || $kycDocCols['right_thumb']) {
            $docCols = [];
            foreach (['signature', 'left_thumb', 'right_thumb'] as $dcol) {
                if (!empty($kycDocCols[$dcol])) $docCols[] = $dcol;
            }
            if (!empty($docCols)) {
                try {
                    $qd = $db->prepare("SELECT " . implode(', ', $docCols) . " FROM kyc_applications WHERE id=? LIMIT 1");
                    $qd->execute([(int)$kycRow['id']]);
                    $docRow = $qd->fetch(PDO::FETCH_ASSOC) ?: [];
                    foreach ($docCols as $dcol) {
                        if (isset($docRow[$dcol])) $kycRow[$dcol] = $docRow[$dcol];
                    }
                } catch (Throwable $ignored) {}
            }
        }
        $kycLocked = (($kycRow['status'] ?? '') === 'approved');
        // KYC लाई primary display source बनाउने (duplicate mismatch रोक्न)
        $viewName = trim((string)($kycRow['full_name'] ?? '')) !== '' ? trim((string)$kycRow['full_name']) : $viewName;
        $viewEmail = trim((string)($kycRow['email'] ?? '')) !== '' ? trim((string)$kycRow['email']) : $viewEmail;
        $viewPhone = trim((string)($kycRow['mobile'] ?? '')) !== '' ? trim((string)$kycRow['mobile']) : $viewPhone;
        $viewAddress = trim((string)($kycRow['permanent_address'] ?? '')) !== '' ? trim((string)$kycRow['permanent_address']) : $viewAddress;
        if (!empty($kycRow['photo'])) $viewAvatar = trim((string)$kycRow['photo']); // photo source = KYC
        $kycEditUrl = SITE_URL . 'member/kyc.php?kyc_id=' . (int)$kycRow['id'];
        $_SESSION['member_last_profile_kyc_id'] = (int)$kycRow['id'];
    }
} catch (Throwable $e) { $kycRow = null; $kycLocked = false; }

if ($viewAvatar !== '') {
    $viewAvatar = publicSiteAssetUrl($viewAvatar);
}

/* ── Upload missing KYC docs from Member KYC profile (single-source: kyc_applications) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_kyc_docs'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security error.';
    } elseif (!$kycRow || empty($kycRow['id'])) {
        $error = 'KYC रेकर्ड भेटिएन।';
    } else {
        $candidates = [];
        foreach (['photo', 'citizenship_front', 'citizenship_back', 'signature', 'left_thumb', 'right_thumb'] as $f) {
            if (!in_array($f, ['photo', 'citizenship_front', 'citizenship_back'], true) && empty($kycDocCols[$f])) {
                continue;
            }
            if (!kycDocNeedsUpload($kycRow[$f] ?? null)) {
                continue;
            }
            $candidates[] = $f;
        }
        if (empty($candidates)) {
            $error = 'अपडेट गर्नुपर्ने हराइरहेको वा सर्भरमा नभएको KYC कागजात छैन।';
        } else {
            $set = [];
            $vals = [];
            foreach ($candidates as $f) {
                $jpegOnly = !in_array($f, ['signature'], true);
                $path = captureOrUpload($f, 'kyc', $jpegOnly);
                if ($path === '') {
                    continue;
                }
                $set[] = "{$f}=?";
                $vals[] = $path;
                $kycRow[$f] = $path;
                if ($f === 'photo') {
                    $viewAvatar = publicSiteAssetUrl($path);
                }
            }
            if (empty($set)) {
                $error = 'कुनै नयाँ कागजात प्राप्त भएन।';
            } else {
                $vals[] = (int)$kycRow['id'];
                $db->prepare('UPDATE kyc_applications SET ' . implode(', ', $set) . ', updated_at=NOW() WHERE id=?')->execute($vals);
                $success = 'KYC कागजात सफलतापूर्वक अपडेट भयो।';
            }
        }
    }
}

/* ── Update profile ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_update'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security error.';
    } elseif ($kycLocked) {
        $error = 'तपाईंको KYC स्वीकृत भइसकेको छ। Profile जानकारी edit गर्न Admin लाई सम्पर्क गर्नुहोस्।';
    } else {
        $name    = trim($_POST['name']    ?? '');
        $phone   = trim($_POST['phone']   ?? '');
        $address = trim($_POST['address'] ?? '');
        $gender  = trim($_POST['gender']  ?? '');
        $dob     = trim($_POST['dob']     ?? '');

        if (!$name) $error = 'नाम राख्नुहोस्।';
        else {
            // Single-source pattern: KYC छ भने editable status मा KYC लाई नै primary source मान्ने
            if ($kycRow && in_array((string)($kycRow['status'] ?? ''), ['pending','incomplete','partial'], true)) {
                $db->prepare("UPDATE kyc_applications
                              SET full_name=?, mobile=?, permanent_address=?, gender=?, dob_ad=?, updated_at=NOW()
                              WHERE id=?")
                   ->execute([$name, $phone ?: null, $address ?: null, $gender ?: null, $dob ?: null, (int)$kycRow['id']]);
            }
            // KYC single-source: KYC record भएको सदस्यका लागि KYM fields members table मा दोहोर्‍याएर नलेख्ने
            if (!$kycRow) {
                $db->prepare("UPDATE members SET name=?, phone=?, address=?, gender=?, dob=? WHERE id=?")
                   ->execute([$name, $phone ?: null, $address ?: null, $gender ?: null, $dob ?: null, $memberId]);
                $_SESSION['member_name'] = $name;
            } else {
                $viewName = $name;
                $viewPhone = $phone;
                $viewAddress = $address;
            }
            $success = 'प्रोफाइल सफलतापूर्वक अपडेट भयो।';
            $mem = currentMember();
        }
    }
}

/* ── Change password ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_password'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security error.';
    } else {
        $current = $_POST['current_pw'] ?? '';
        $newpw   = $_POST['new_pw']     ?? '';
        $confirm = $_POST['confirm_pw'] ?? '';

        $isOAuthOnly = empty($mem['password_hash']);  /* OAuth account, no password yet */

        if (!$newpw) $error = 'नयाँ पासवर्ड राख्नुहोस्।';
        elseif (strlen($newpw) < 6) $error = 'नयाँ पासवर्ड कम्तीमा ६ अक्षर हुनुपर्छ।';
        elseif ($newpw !== $confirm) $error = 'नयाँ पासवर्ड र Confirm मिलेन।';
        elseif (!$isOAuthOnly && !password_verify($current, $mem['password_hash'])) {
            /* Existing password change — current must match */
            $error = 'हालको पासवर्ड गलत छ।';
        } else {
            $db->prepare("UPDATE members SET password_hash=? WHERE id=?")
               ->execute([password_hash($newpw, PASSWORD_DEFAULT), $memberId]);
            /* Refresh in-memory copy so UI updates immediately without re-login */
            $mem['password_hash'] = password_hash($newpw, PASSWORD_DEFAULT);
            $success = $isOAuthOnly
                ? 'पासवर्ड सेट भयो! अब तपाईं इमेल + पासवर्डले पनि लगिन गर्न सक्नुहुन्छ।'
                : 'पासवर्ड सफलतापूर्वक बदलियो।';
        }
    }
}

$siteName  = getSetting('site_name', 'आकाश सहकारी');
$siteUrl   = SITE_URL;
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};
$pageTitle = $_t('मेरो प्रोफाइल', 'My Profile') . ' — ' . $siteName;

$profileKycCapture = false;
if ($kycRow) {
    foreach (['photo', 'citizenship_front', 'citizenship_back', 'signature', 'left_thumb', 'right_thumb'] as $fk) {
        if (!in_array($fk, ['photo', 'citizenship_front', 'citizenship_back'], true) && empty($kycDocCols[$fk])) {
            continue;
        }
        if (kycDocNeedsUpload($kycRow[$fk] ?? null)) {
            $profileKycCapture = true;
            break;
        }
    }
}
$extraHead = '';
if ($profileKycCapture) {
    $extraHead = '<link rel="stylesheet" href="' . htmlspecialchars($siteUrl) . 'assets/css/kyc-capture.css?v=10.6">' . "\n"
        . '<script defer src="' . htmlspecialchars($siteUrl) . 'assets/js/kyc-capture.js?v=10.8"></script>';
}

require __DIR__ . '/includes/chrome.php';

$kymStatusKey = (string)($kycRow['status'] ?? 'not_submitted');
$kymStatusClassMap = [
    'approved' => 'mem-kym-pill--ok',
    'pending' => 'mem-kym-pill--pending',
    'rejected' => 'mem-kym-pill--bad',
];
$kymStatusLabelMap = [
    'approved' => $_t('अनुमोदित', 'Approved'),
    'pending' => $_t('प्रक्रियामा', 'In Progress'),
    'rejected' => $_t('अस्वीकृत', 'Rejected'),
];
$kymStatusIconMap = [
    'approved' => 'fa-circle-check',
    'pending' => 'fa-clock',
    'rejected' => 'fa-circle-xmark',
];
$kymStatusClass = $kymStatusClassMap[$kymStatusKey] ?? 'mem-kym-pill--muted';
$kymStatusLabel = $kymStatusLabelMap[$kymStatusKey] ?? $_t('दर्ता नभएको', 'Not Submitted');
$kymStatusIcon = $kymStatusIconMap[$kymStatusKey] ?? 'fa-circle-question';
$kymDobKr = $kycRow ?? [];
$kymDobDisplay = (trim((string)($kymDobKr['dob_bs'] ?? '')) !== '')
    ? ((string)$kymDobKr['dob_bs'] . ' (BS)')
    : ((trim((string)($kymDobKr['dob_ad'] ?? '')) !== '') ? (string)$kymDobKr['dob_ad'] : '—');
?>
    <?php if ($error): ?><div class="mem-alert mem-alert-error"><i class="fas fa-circle-exclamation"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mem-alert mem-alert-success"><i class="fas fa-circle-check"></i><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="mem-profile-tab-shell">
        <div class="mem-profile-tab-rail" role="tablist" aria-label="<?php echo $_t('प्रोफाइल सेक्सनहरू', 'Profile sections'); ?>">
            <button type="button" role="tab" class="mem-profile-tab active" id="tabBtnProfile" aria-selected="true">
                <span class="mem-profile-tab-ic"><i class="fas fa-user"></i></span><span><?php echo $_t('क. प्रोफाइल', 'A. Profile'); ?></span>
            </button>
            <button type="button" role="tab" class="mem-profile-tab" id="tabBtnSecurity" aria-selected="false">
                <span class="mem-profile-tab-ic"><i class="fas fa-lock"></i></span><span><?php echo $_t('ख. खाता / सुरक्षा', 'B. Account / Security'); ?></span>
            </button>
            <button type="button" role="tab" class="mem-profile-tab" id="tabBtnKyc" aria-selected="false">
                <span class="mem-profile-tab-ic"><i class="fas fa-id-card"></i></span><span><?php echo $_t('ग. KYC विवरण', 'C. KYC Details'); ?></span>
            </button>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr;gap:18px;" class="mem-grid-2">

        <!-- KYC Summary -->
        <div class="mem-card mem-kym-hero-card" id="panelProfileInfo">
            <div class="mem-card-header mem-kym-card-header">
                <div class="mem-kym-heading">
                    <span class="mem-kym-heading-icon"><i class="fas fa-user-circle"></i></span>
                    <div>
                        <div class="mem-kym-heading-title"><?php echo $_t('KYM सारांश', 'KYM Summary'); ?></div>
                        <div class="mem-kym-heading-sub"><?php echo $_t('प्रोफाइल — तपाईंको दर्ता विवरण', 'Profile — your registration details'); ?></div>
                    </div>
                </div>
                <a href="<?php echo htmlspecialchars($kycEditUrl); ?>" class="mem-kym-cta">
                    <i class="fas fa-pen-to-square"></i><span><?php echo $_t('पूर्ण KYM फारम भर्नुहोस् / अपडेट गर्नुहोस्', 'Open / update full KYM form'); ?></span>
                </a>
            </div>
            <div class="mem-card-body mem-kym-body">
                <div class="mem-kym-hero">
                    <div class="mem-kym-hero-main">
                        <?php if ($viewAvatar): ?>
                        <img class="mem-kym-avatar" src="<?php echo htmlspecialchars($viewAvatar); ?>" alt=""
                             onerror="this.style.display='none';this.nextElementSibling.classList.add('mem-kym-avatar-fallback--show');">
                        <div class="mem-kym-avatar-fallback" aria-hidden="true"><?php echo htmlspecialchars(mb_substr($viewName, 0, 1)); ?></div>
                        <?php else: ?>
                        <div class="mem-kym-avatar-fallback mem-kym-avatar-fallback--show" aria-hidden="true"><?php echo htmlspecialchars(mb_substr($viewName, 0, 1)); ?></div>
                        <?php endif; ?>
                        <div class="mem-kym-hero-text">
                            <h2 class="mem-kym-name"><?php echo htmlspecialchars($viewName ?: '—'); ?></h2>
                            <?php if (!empty($mem['member_card_no'])): ?>
                            <p class="mem-kym-member-id"><i class="fas fa-id-badge" aria-hidden="true"></i><?php echo htmlspecialchars($mem['member_card_no']); ?></p>
                            <?php endif; ?>
                            <div class="mem-kym-social-row">
                                <?php if ($mem['google_id']): ?><span class="mem-kym-oauth mem-kym-oauth--google"><i class="fab fa-google" aria-hidden="true"></i>Google</span><?php endif; ?>
                                <?php if ($mem['facebook_id']): ?><span class="mem-kym-oauth mem-kym-oauth--fb"><i class="fab fa-facebook-f" aria-hidden="true"></i>Facebook</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="mem-kym-hero-aside">
                        <span class="mem-kym-pill <?php echo htmlspecialchars($kymStatusClass); ?>"><i class="fas <?php echo htmlspecialchars($kymStatusIcon); ?>" aria-hidden="true"></i><?php echo htmlspecialchars($kymStatusLabel); ?></span>
                    </div>
                </div>

                <div class="mem-kym-details" role="list">
                    <div class="mem-kym-item" role="listitem">
                        <span class="mem-kym-item-icon mem-kym-ic--cyan" aria-hidden="true"><i class="fas fa-envelope"></i></span>
                        <div class="mem-kym-item-body">
                            <span class="mem-kym-item-label">इमेल</span>
                            <span class="mem-kym-item-value mem-kym-item-value--break"><?php echo htmlspecialchars($viewEmail ?: '—'); ?></span>
                        </div>
                    </div>
                    <div class="mem-kym-item" role="listitem">
                        <span class="mem-kym-item-icon mem-kym-ic--violet" aria-hidden="true"><i class="fas fa-phone"></i></span>
                        <div class="mem-kym-item-body">
                            <span class="mem-kym-item-label">मोबाइल</span>
                            <span class="mem-kym-item-value"><?php echo htmlspecialchars($viewPhone ?: '—'); ?></span>
                        </div>
                    </div>
                    <div class="mem-kym-item" role="listitem">
                        <span class="mem-kym-item-icon mem-kym-ic--amber" aria-hidden="true"><i class="fas fa-calendar-days"></i></span>
                        <div class="mem-kym-item-body">
                            <span class="mem-kym-item-label">जन्म मिति</span>
                            <span class="mem-kym-item-value"><?php echo htmlspecialchars($kymDobDisplay); ?></span>
                        </div>
                    </div>
                    <div class="mem-kym-item mem-kym-item--wide" role="listitem">
                        <span class="mem-kym-item-icon mem-kym-ic--rose" aria-hidden="true"><i class="fas fa-location-dot"></i></span>
                        <div class="mem-kym-item-body">
                            <span class="mem-kym-item-label">ठेगाना</span>
                            <span class="mem-kym-item-value mem-kym-item-value--break"><?php echo htmlspecialchars($viewAddress ?: '—'); ?></span>
                        </div>
                    </div>
                </div>

                <div class="mem-kym-note" role="note">
                    <span class="mem-kym-note-icon" aria-hidden="true"><i class="fas fa-circle-info"></i></span>
                    <p><strong>Profile = KYM Summary</strong> हो। DOB वा अन्य विवरण सम्पादन गर्न <a href="<?php echo htmlspecialchars($kycEditUrl); ?>">पूर्ण KYM फारम</a> मार्फत मात्र अपडेट गर्नुहोस्।</p>
                </div>
            </div>
        </div>

        <!-- Password + Account info -->
        <div id="panelSecurityInfo" class="d-none" style="display:none;">
            <div id="panelSecurityCards">
            <!-- Change Password -->
            <div class="mem-card" style="margin-bottom:18px;">
                <?php $hasPwd = !empty($mem['password_hash']); ?>
                <div class="mem-card-header">
                    <div class="mem-card-title"><i class="fas fa-lock"></i><?php echo $hasPwd ? 'पासवर्ड बदल्नुहोस्' : 'पासवर्ड सेट गर्नुहोस्'; ?></div>
                </div>
                <div class="mem-card-body">
                    <?php if (!$hasPwd): ?>
                    <div class="mem-alert" style="background:#fef2f2;border-left:3px solid var(--secondary-color,#c0392b);color:var(--secondary-dark,#922b21);font-size:0.8rem;padding:9px 12px;margin-bottom:12px;">
                        <i class="fas fa-info-circle me-1"></i>
                        तपाईंको account OAuth (Google/Facebook) बाट बनेको छ। तल नयाँ पासवर्ड सेट गर्नुहोस् — त्यसपछि इमेल + पासवर्डले पनि लगिन गर्न सक्नुहुनेछ।
                    </div>
                    <?php endif; ?>
                    <form method="POST" novalidate class="needs-validation">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="do_password" value="1">
                        <?php if ($hasPwd): ?>
                        <div class="mem-field">
                            <label>हालको पासवर्ड</label>
                            <input type="password" name="current_pw" required placeholder="••••••">
                        </div>
                        <?php endif; ?>
                        <div class="mem-field">
                            <label>नयाँ पासवर्ड</label>
                            <input type="password" name="new_pw" required placeholder="कम्तीमा ६ अक्षर" minlength="6">
                        </div>
                        <div class="mem-field">
                            <label>पुनः नयाँ पासवर्ड</label>
                            <input type="password" name="confirm_pw" required placeholder="माथिको जस्तै" minlength="6">
                        </div>
                        <button type="submit" class="mem-submit-btn" style="background:linear-gradient(135deg,#374151,#1f2937);">
                            <i class="fas fa-key me-2"></i><?php echo $hasPwd ? 'पासवर्ड बदल्नुहोस्' : 'पासवर्ड सेट गर्नुहोस्'; ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Account info card -->
            <div class="mem-card">
                <div class="mem-card-header">
                    <div class="mem-card-title"><i class="fas fa-info-circle"></i>Account जानकारी</div>
                </div>
                <div class="mem-card-body">
                    <table style="width:100%;font-size:0.82rem;">
                        <tr><td style="color:#6b7280;padding:5px 0;font-weight:600;">Member ID</td><td><code><?php echo htmlspecialchars($mem['member_card_no']); ?></code></td></tr>
                        <tr><td style="color:#6b7280;padding:5px 0;font-weight:600;">दर्ता मिति</td><td><?php echo formatNepaliDate($mem['created_at']); ?></td></tr>
                        <tr><td style="color:#6b7280;padding:5px 0;font-weight:600;">अन्तिम Login</td><td><?php echo $mem['last_login'] ? formatNepaliDate($mem['last_login'], true) : 'पहिलो पटक'; ?></td></tr>
                        <tr><td style="color:#6b7280;padding:5px 0;font-weight:600;">Login विधि</td><td>
                            <?php
                            $methods = [];
                            if ($mem['password_hash']) $methods[] = '<span style="background:#f0fdf4;color:var(--primary-color);padding:2px 7px;border-radius:8px;font-size:0.72rem;font-weight:700;">Email</span>';
                            if ($mem['google_id']) $methods[] = '<span style="background:#fef2f2;color:#ea4335;padding:2px 7px;border-radius:8px;font-size:0.72rem;font-weight:700;"><i class="fab fa-google me-1"></i>Google</span>';
                            if ($mem['facebook_id']) $methods[] = '<span style="background:#fef2f2;color:var(--secondary-color,#c0392b);padding:2px 7px;border-radius:8px;font-size:0.72rem;font-weight:700;"><i class="fab fa-facebook-f me-1"></i>Facebook</span>';
                            echo implode(' ', $methods) ?: '—';
                            ?>
                        </td></tr>
                    </table>
                    <hr style="margin:14px 0;border:none;border-top:1px solid #f3f4f6;">
                    <a href="<?php echo $siteUrl; ?>member/logout.php"
                       style="display:block;text-align:center;padding:10px;background:#fef2f2;color:#dc2626;border-radius:10px;text-decoration:none;font-size:0.85rem;font-weight:700;border:1px solid #fecaca;">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout गर्नुहोस्
                    </a>
                </div>
            </div>
            </div>

            <div class="mem-card d-none" style="margin-top:18px;display:none;" id="panelKycInfo">
                <div class="mem-card-header">
                    <div class="mem-card-title"><i class="fas fa-id-card"></i>KYC जानकारी</div>
                    <?php if ($kycRow && !empty($kycRow['id'])): ?>
                    <a href="<?php echo SITE_URL; ?>member/kyc-print.php" target="_blank" style="margin-left:auto;font-size:.78rem;background:#f0fdf4;border:1px solid #86efac;color:#166534;padding:6px 10px;border-radius:8px;text-decoration:none;font-weight:700;">
                        <i class="fas fa-print me-1"></i>Print KYC
                    </a>
                    <?php endif; ?>
                </div>
                <div class="mem-card-body">
                    <?php if (!$kycRow): ?>
                        <div class="text-muted small">KYC रेकर्ड भेटिएन। <a href="<?php echo SITE_URL; ?>member/kyc.php">KYC अपडेट गर्नुहोस्</a></div>
                    <?php else: ?>
                        <div class="mb-2">
                            <span class="badge <?php echo ($kycRow['status']==='approved' ? 'bg-success' : (($kycRow['status']==='pending') ? 'bg-warning text-dark' : 'bg-secondary')); ?>">
                                <?php echo htmlspecialchars($kycRow['status']); ?>
                            </span>
                        </div>
                        <?php
                        $amlDetails = [];
                        $amlRaw = trim((string)($kycRow['aml_details_json'] ?? ''));
                        if ($amlRaw !== '') {
                            $amlDecoded = json_decode($amlRaw, true);
                            if (is_array($amlDecoded)) $amlDetails = $amlDecoded;
                        }
                        $incomeItems = isset($amlDetails['income_items']) && is_array($amlDetails['income_items']) ? $amlDetails['income_items'] : [];
                        $expenseItems = isset($amlDetails['expense_items']) && is_array($amlDetails['expense_items']) ? $amlDetails['expense_items'] : [];
                        $incomeLines = [];
                        foreach ($incomeItems as $it) {
                            $n = trim((string)($it['name'] ?? ''));
                            $a = (float)($it['amount'] ?? 0);
                            if ($n !== '' && $a > 0) $incomeLines[] = $n . ' (Rs. ' . number_format($a, 2) . ')';
                        }
                        $expenseLines = [];
                        foreach ($expenseItems as $it) {
                            $n = trim((string)($it['name'] ?? ''));
                            $a = (float)($it['amount'] ?? 0);
                            if ($n !== '' && $a > 0) $expenseLines[] = $n . ' (Rs. ' . number_format($a, 2) . ')';
                        }
                        ?>
                        <div class="table-responsive">
                            <table class="kyc-detail-table" style="width:100%;font-size:0.8rem;border-collapse:collapse;">
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;width:38%;">सदस्यता नं.</td><td><code><?php echo htmlspecialchars($kycRow['member_id'] ?? '—'); ?></code></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">नाम</td><td><?php echo htmlspecialchars($kycRow['full_name'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">नाम (EN)</td><td><?php echo htmlspecialchars($kycRow['full_name_en'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">मोबाइल</td><td><?php echo htmlspecialchars($kycRow['mobile'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">इमेल</td><td><?php echo htmlspecialchars($kycRow['email'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">लिङ्ग</td><td><?php echo htmlspecialchars($kycRow['gender'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">वैवाहिक स्थिति</td><td><?php echo htmlspecialchars($kycRow['marital_status'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">DOB (BS)</td><td><?php echo htmlspecialchars($kycRow['dob_bs'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">DOB (AD)</td><td><?php echo htmlspecialchars($kycRow['dob_ad'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">स्थायी ठेगाना</td><td><?php echo htmlspecialchars($kycRow['permanent_address'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">अस्थायी ठेगाना</td><td><?php echo htmlspecialchars($kycRow['temporary_address'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">नागरिकता नं.</td><td><code><?php echo htmlspecialchars($kycRow['citizenship_no'] ?? '—'); ?></code></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">जारी जिल्ला</td><td><?php echo htmlspecialchars($kycRow['citizenship_issued_place'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">जारी मिति</td><td><?php echo htmlspecialchars($kycRow['citizenship_issued_date'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">National ID नं.</td><td><code><?php echo htmlspecialchars($kycRow['national_id_number'] ?? '—'); ?></code></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">Risk Category</td><td><?php echo htmlspecialchars(strtoupper((string)($kycRow['risk_category'] ?? 'MEDIUM'))); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">KYC Verified Date</td><td><?php echo htmlspecialchars($kycRow['kyc_verified_at'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">Next Review Due</td><td><?php echo htmlspecialchars($kycRow['risk_review_due_at'] ?? '—'); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">Review Status</td><td><?php echo (($kycRow['risk_review_status'] ?? 'normal') === 'due_review') ? 'Due Review' : 'Normal'; ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">बुबा/आमा</td><td><?php echo htmlspecialchars(($kycRow['father_name'] ?? '—') . ' / ' . ($kycRow['mother_name'] ?? '—')); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">हजुरबुबा/पति-पत्नी</td><td><?php echo htmlspecialchars(($kycRow['grandfather_name'] ?? '—') . ' / ' . ($kycRow['spouse_name'] ?? '—')); ?></td></tr>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">फोटो स्कोर</td><td><?php echo !empty($kycRow['photo_quality_score']) ? (int)$kycRow['photo_quality_score'] . '/100' : '—'; ?></td></tr>
                                <?php
                                $amlLabelMap = [
                                    'passport_no' => 'Passport नं.',
                                    'pan_no' => 'PAN नं.',
                                    'driving_license_no' => 'Driving License नं.',
                                    'education_qualification' => 'शैक्षिक योग्यता',
                                    'religion' => 'धर्म',
                                    'caste' => 'जात',
                                    'occupation_location' => 'पेशा/व्यवसाय स्थान',
                                    'occupation_business_name' => 'पेशा/व्यवसाय नाम',
                                    'business_pan_no' => 'Business PAN नं.',
                                    'business_registration_type' => 'Business दर्ता प्रकार',
                                    'business_registration_no' => 'Business दर्ता नं.',
                                    'business_registration_office' => 'दर्ता निकाय',
                                    'business_registration_date_bs' => 'Business दर्ता मिति (BS)',
                                    'business_nature' => 'व्यवसाय प्रकृति',
                                    'estimated_annual_income' => 'अनुमानित वार्षिक आय',
                                    'politically_exposed' => 'PEP स्थिति',
                                    'past_crime_declared' => 'अपराध घोषणा',
                                    'landlord_name' => 'घरधनीको नाम',
                                    'landlord_contact' => 'घरधनी सम्पर्क',
                                    'voter_id_card_no' => 'मतदाता परिचयपत्र नं.',
                                    'polling_station' => 'मतदान स्थल',
                                    'member_purpose' => 'सदस्यता उद्देश्य',
                                    'self_other_coop_member' => 'आफू अन्य सहकारी सदस्य',
                                    'self_other_coop_details' => 'अन्य सहकारी विवरण',
                                    'family_same_coop_member' => 'परिवार यसै सहकारीमा',
                                    'family_same_coop_details' => 'परिवार सदस्य विवरण',
                                    'annual_family_income' => 'वार्षिक पारिवारिक आम्दानी',
                                    'net_worth_details' => 'सम्पत्ति/Net Worth',
                                    'annual_debit_credit_estimate' => 'वार्षिक डेबिट/क्रेडिट',
                                    'annual_turnover_numbers' => 'वार्षिक कारोबार संख्या',
                                    'annual_deposit_estimate' => 'वार्षिक जम्मा अनुमान',
                                    'institution_debt_estimate' => 'संस्थासँग ऋणधन अनुमान',
                                    'nearest_person_name' => 'नजिकको व्यक्ति नाम',
                                    'nearest_person_relation' => 'नजिकको व्यक्ति नाता',
                                    'nominee_name' => 'हकवाला नाम',
                                    'nominee_dob' => 'हकवाला जन्म मिति',
                                    'nominee_citizenship_no' => 'हकवाला नागरिकता नं.',
                                    'nominee_relation' => 'हकवालासँग नाता',
                                    'nominee_issue_district' => 'हकवाला जारी जिल्ला',
                                    'nominee_issue_date' => 'हकवाला जारी मिति',
                                    'nominee_permanent_address' => 'हकवाला स्थायी ठेगाना',
                                    'nominee_temporary_address' => 'हकवाला अस्थायी ठेगाना',
                                    'longitude_latitude' => 'देशान्तर/अक्षांश',
                                    'map_resolved_address' => 'Map बाट प्राप्त ठेगाना',
                                    'other_attached_docs' => 'अन्य संलग्न कागजात',
                                ];
                                foreach ($amlLabelMap as $k => $lbl):
                                    $v = trim((string)($amlDetails[$k] ?? ''));
                                    if ($v === '') continue;
                                ?>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;"><?php echo htmlspecialchars($lbl); ?></td><td><?php echo htmlspecialchars($v); ?></td></tr>
                                <?php endforeach; ?>
                                <?php if (!empty($incomeLines)): ?><tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">आय स्रोतहरू</td><td><?php echo htmlspecialchars(implode(', ', $incomeLines)); ?></td></tr><?php endif; ?>
                                <?php if (!empty($expenseLines)): ?><tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">खर्च स्रोतहरू</td><td><?php echo htmlspecialchars(implode(', ', $expenseLines)); ?></td></tr><?php endif; ?>
                                <?php if (isset($amlDetails['income_total']) || isset($amlDetails['expense_total'])): ?>
                                <tr><td style="color:#6b7280;padding:4px 0;font-weight:600;">आय/खर्च/अन्तर</td><td>
                                    Rs. <?php echo number_format((float)($amlDetails['income_total'] ?? 0), 2); ?> /
                                    Rs. <?php echo number_format((float)($amlDetails['expense_total'] ?? 0), 2); ?> /
                                    Rs. <?php echo number_format((float)($amlDetails['net_saving_estimate'] ?? ((float)($amlDetails['income_total'] ?? 0) - (float)($amlDetails['expense_total'] ?? 0))), 2); ?>
                                </td></tr>
                                <?php endif; ?>
                            </table>
                        </div>

                        <?php
                        $missing = [];
                        foreach (['photo' => 'फोटो', 'signature' => 'हस्ताक्षर', 'left_thumb' => 'बायाँ औंठाछाप', 'right_thumb' => 'दायाँ औंठाछाप'] as $k => $lbl) {
                            if ($k !== 'photo' && empty($kycDocCols[$k])) {
                                continue;
                            }
                            if (kycDocNeedsUpload($kycRow[$k] ?? null)) {
                                $missing[] = $lbl;
                            }
                        }
                        ?>
                        <hr style="margin:10px 0 12px;border:none;border-top:1px solid #eef2f7;">
                        <div class="small mb-2">
                            <b>कागजात स्थिति:</b>
                            <?php if (empty($missing)): ?>
                                <span style="color:#15803d;">पूर्ण (फोटो/हस्ताक्षर/औंठाछाप)</span>
                            <?php else: ?>
                                <span style="color:#b45309;">अपूर्ण — <?php echo htmlspecialchars(implode(', ', $missing)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:8px 0 12px;">
                            <?php
                            $docPreviewMap = [
                                'photo' => 'फोटो',
                                'signature' => 'हस्ताक्षर',
                                'left_thumb' => 'बायाँ औंठाछाप',
                                'right_thumb' => 'दायाँ औंठाछाप',
                            ];
                            foreach ($docPreviewMap as $k => $label):
                                if ($k !== 'photo' && empty($kycDocCols[$k])) {
                                    continue;
                                }
                                $srcRaw = trim((string)($kycRow[$k] ?? ''));
                                $docShowImg = $srcRaw !== '' && !kycDocNeedsUpload($srcRaw);
                                $docImgUrl = $docShowImg ? publicSiteAssetUrl($srcRaw) : '';
                            ?>
                            <div style="border:1px solid #e5e7eb;border-radius:10px;padding:8px;background:#f9fafb;">
                                <div style="font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:6px;"><?php echo htmlspecialchars($label); ?></div>
                                <?php if ($docShowImg && $docImgUrl !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($docImgUrl); ?>" alt="<?php echo htmlspecialchars($label); ?>" style="width:100%;height:74px;object-fit:cover;border-radius:8px;border:1px solid #fecaca;background:#fff;">
                                <?php else: ?>
                                    <div style="height:74px;border:1px dashed #d1d5db;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.72rem;color:#9ca3af;background:#fff;text-align:center;padding:6px;"><?php echo $srcRaw !== '' ? 'फाइल भेटिएन / URL मिलेन' : 'अपलोड छैन'; ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($missing)): ?>
                        <form method="POST" novalidate enctype="multipart/form-data" class="kyc-form mem-kyc-cap-form needs-validation" style="margin-top:8px;">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="do_kyc_docs" value="1">
                            <?php if ($kycLocked): ?>
                            <div class="mem-alert" style="margin-bottom:12px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:0.8rem;padding:10px 12px;">
                                <i class="fas fa-circle-info me-1"></i> KYC अनुमोदित छ। <strong>हराइरहेका वा फाइल नभएका</strong> कागजात मात्र थप्न सकिन्छ; पहिले नै भएको फाइल यहाँबाट बदल्न मिल्दैन।
                            </div>
                            <?php endif; ?>
                            <div class="small text-muted mb-2">KYM single-source कायम राख्दै, हराइरहेको कागजात यहींबाट अपडेट गर्न सक्नुहुन्छ।</div>
                            <?php if (kycDocNeedsUpload($kycRow['photo'] ?? null)): ?>
                            <div class="mem-field">
                                <label>फोटो <span style="color:#dc2626;">*</span></label>
                                <div class="kyc-cap-field" data-kyc-cap="passport">
                                    <span class="kyc-cap-label">पासपोर्ट साइज फोटो — दुवै आँखा र दुवै कान स्पष्ट हुनुपर्छ</span>
                                    <input type="hidden" name="photo">
                                </div>
                                <div style="font-size:.75rem;color:#b45309;margin-top:4px;"><i class="fas fa-triangle-exclamation"></i> आँखा र कान स्पष्ट नदेखिएमा Admin ले Reject गर्नेछन्।</div>
                            </div>
                            <?php endif; ?>
                            <?php if (kycDocNeedsUpload($kycRow['citizenship_front'] ?? null)): ?>
                            <div class="mem-field">
                                <label>नागरिकता अगाडि <span style="color:#dc2626;">*</span></label>
                                <div class="kyc-cap-field" data-kyc-cap="citizen_front">
                                    <span class="kyc-cap-label">नागरिकता अगाडिको फोटो — असली नागरिकता पत्र मात्र</span>
                                    <input type="hidden" name="citizenship_front">
                                </div>
                                <div style="font-size:.75rem;color:#b45309;margin-top:4px;"><i class="fas fa-triangle-exclamation"></i> राष्ट्रिय परिचयपत्र वा अन्य कागजात हाल्न नहोस्।</div>
                            </div>
                            <?php endif; ?>
                            <?php if (kycDocNeedsUpload($kycRow['citizenship_back'] ?? null)): ?>
                            <div class="mem-field">
                                <label>नागरिकता पछाडि <span style="color:#dc2626;">*</span></label>
                                <div class="kyc-cap-field" data-kyc-cap="citizen_back">
                                    <span class="kyc-cap-label">नागरिकता पछाडिको फोटो — असली नागरिकता पत्र मात्र</span>
                                    <input type="hidden" name="citizenship_back">
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($kycDocCols['signature']) && kycDocNeedsUpload($kycRow['signature'] ?? null)): ?>
                            <div class="mem-field">
                                <label>हस्ताक्षर</label>
                                <div class="kyc-sig-wrap" data-kyc-signature>
                                    <input type="hidden" name="signature">
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($kycDocCols['left_thumb']) && kycDocNeedsUpload($kycRow['left_thumb'] ?? null)): ?>
                            <div class="mem-field">
                                <label>बायाँ औंठाछाप</label>
                                <div class="kyc-cap-field" data-kyc-cap="thumb">
                                    <span class="kyc-cap-label">बायाँ औंठा</span>
                                    <input type="hidden" name="left_thumb">
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($kycDocCols['right_thumb']) && kycDocNeedsUpload($kycRow['right_thumb'] ?? null)): ?>
                            <div class="mem-field">
                                <label>दायाँ औंठाछाप</label>
                                <div class="kyc-cap-field" data-kyc-cap="thumb">
                                    <span class="kyc-cap-label">दायाँ औंठा</span>
                                    <input type="hidden" name="right_thumb">
                                </div>
                            </div>
                            <?php endif; ?>
                            <button type="submit" class="mem-submit-btn" style="margin-top:4px;"><i class="fas fa-upload me-2"></i>हराइरहेको KYC कागजात अपडेट</button>
                        </form>
                        <?php else: ?>
                        <div class="mem-alert" style="margin-top:8px;background:#fef2f2;border-left:3px solid var(--secondary-color,#c0392b);color:var(--secondary-dark,#922b21);font-size:0.8rem;padding:9px 12px;">
                            <i class="fas fa-database me-1"></i>
                            सबै KYM data single-source रूपमा सुरक्षित छ। थप field/नयाँ आवेदनको लागि
                            <a href="<?php echo htmlspecialchars($kycEditUrl); ?>" style="margin-left:6px;font-weight:700;">KYM फर्म खोल्नुहोस्</a>।
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>

<script>
(function () {
    var btnProfile = document.getElementById('tabBtnProfile');
    var btnSecurity = document.getElementById('tabBtnSecurity');
    var btnKyc = document.getElementById('tabBtnKyc');
    var panelProfile = document.getElementById('panelProfileInfo');
    var panelSecurityWrap = document.getElementById('panelSecurityInfo');
    var panelSecurityCards = document.getElementById('panelSecurityCards');
    var panelKyc = document.getElementById('panelKycInfo');
    if (!btnProfile || !btnSecurity || !btnKyc || !panelProfile || !panelSecurityWrap || !panelSecurityCards || !panelKyc) return;

    function setBtn(btn, active) {
        btn.classList.toggle('active', active);
        btn.setAttribute('aria-selected', active ? 'true' : 'false');
    }

    function showTab(tab) {
        var isProfile = tab === 'profile';
        var isSecurity = tab === 'security';
        var isKyc = tab === 'kyc';

        panelProfile.classList.toggle('d-none', !isProfile);
        panelSecurityWrap.classList.toggle('d-none', isProfile);
        panelSecurityCards.classList.toggle('d-none', !isSecurity);
        panelKyc.classList.toggle('d-none', !isKyc);

        // Bootstrap utility class load नभए पनि tab switch काम गर्न direct display fallback
        panelProfile.style.display = isProfile ? '' : 'none';
        panelSecurityWrap.style.display = isProfile ? 'none' : '';
        panelSecurityCards.style.display = isSecurity ? '' : 'none';
        panelKyc.style.display = isKyc ? '' : 'none';

        setBtn(btnProfile, isProfile);
        setBtn(btnSecurity, isSecurity);
        setBtn(btnKyc, isKyc);

        var nextHash = '#' + tab;
        if (window.location.hash !== nextHash) {
            if (history.replaceState) history.replaceState(null, '', nextHash);
            else window.location.hash = nextHash;
        }
    }

    btnProfile.addEventListener('click', function () { showTab('profile'); });
    btnSecurity.addEventListener('click', function () { showTab('security'); });
    btnKyc.addEventListener('click', function () {
        showTab('kyc');
        if (window.KYCCapture && window.KYCCapture.initAllKYCCapture) {
            setTimeout(window.KYCCapture.initAllKYCCapture, 50);
        }
    });
    var initial = (window.location.hash || '').toLowerCase().replace('#', '');
    if (initial !== 'profile' && initial !== 'security' && initial !== 'kyc') initial = 'profile';
    showTab(initial);

    window.addEventListener('hashchange', function () {
        var tab = (window.location.hash || '').toLowerCase().replace('#', '');
        if (tab === 'profile' || tab === 'security' || tab === 'kyc') showTab(tab);
    });
})();
</script>
<style>
@media (max-width:640px) { .mem-grid-2 { grid-template-columns:1fr !important; } }
@media (max-width:640px) {
    .mem-kym-card-header { flex-direction:column; align-items:stretch !important; }
    .mem-kym-cta { justify-content:center; width:100%; }
    .kyc-detail-table tr { display:block; border-bottom:1px solid #eef2f7; padding:6px 0; }
    .kyc-detail-table td { display:block; width:100% !important; padding:2px 0 !important; }
    .kyc-detail-table td:first-child { color:#475569; font-weight:700; font-size:0.76rem; }
    .kyc-detail-table td:last-child { font-size:0.82rem; word-break:break-word; }
}
</style>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
