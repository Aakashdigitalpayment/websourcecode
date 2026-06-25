<?php
/**
 * Public Digital ID Card Preview — via Tracker verification
 * v9.9 NEW
 *
 * URL: tracker-id-card.php?mid=<member_id>&exp=<unix_ts>&sig=<hmac_sha256>
 * Signed token application-tracker.php मा issue हुन्छ phone+email verify पछि।
 * - Token expiry: 15 minutes
 * - HMAC-SHA256 with AUTH_SECRET / SECRET_KEY (config.php)
 * - Read-only preview: print/download disabled, watermark added
 * - कुनै login आवश्यक छैन
 */
require_once __DIR__ . '/_bootstrap.php';

/* ── Token validation ── */
$mid = (int)($_GET['mid'] ?? 0);
$exp = (int)($_GET['exp'] ?? 0);
$sig = (string)($_GET['sig'] ?? '');

$valid = false;
$errMsg = '';

if (!$mid || !$exp || !$sig) {
    $errMsg = t('अमान्य लिङ्क — Tracker verification बाट मात्र आउनु पर्छ।', 'Invalid link — this must come from tracker verification.');
} elseif ($exp < time()) {
    $errMsg = t('यो लिङ्कको म्याद सकिएको छ (15 मिनेट)। कृपया फेरि Tracker बाट verify गर्नुहोस्।', 'This link has expired (15 minutes). Please verify again from tracker.');
} else {
    $secret  = defined('AUTH_SECRET') ? AUTH_SECRET : (defined('SECRET_KEY') ? SECRET_KEY : 'aakash-fallback-secret-2026');
    $payload = $mid . '.' . $exp;
    $expected= hash_hmac('sha256', $payload, $secret);
    if (hash_equals($expected, $sig)) {
        $valid = true;
    } else {
        $errMsg = t('सुरक्षा हस्ताक्षर मेल खाएन। यो लिङ्क सम्भवतः छेडछाड भएको छ।', 'Security signature mismatch. This link may have been tampered.');
    }
}

/* ── Fetch member ── */
$mem = null;
if ($valid) {
    try {
        $db = getDB();
        $st = $db->prepare("SELECT id, name, member_card_no, phone, email, address, dob, gender,
                                   approval_status, id_card_generated, id_card_generated_at,
                                   profile_image, sadasyata_number, created_at
                            FROM members
                            WHERE id = ? AND is_active = 1
                              AND approval_status = 'approved'
                              AND id_card_generated = 1
                            LIMIT 1");
        $st->execute([$mid]);
        $mem = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$mem) {
            $valid = false;
            $errMsg = t('सदस्य अहिले उपलब्ध छैन वा ID Card अझै Generate भएको छैन।', 'Member is unavailable or ID card is not generated yet.');
        }
    } catch (\Throwable $e) {
        $valid = false;
        $errMsg = t('डेटा लोड गर्दा त्रुटि भयो। पुनः प्रयास गर्नुहोस्।', 'Error loading data. Please try again.');
        error_log('tracker-id-card fetch: ' . $e->getMessage());
    }
}

/* ── Site settings ── */
$siteName = function_exists('getSetting') ? getSetting('site_name', t('आकाश सहकारी', 'Aakash Cooperative')) : t('आकाश सहकारी', 'Aakash Cooperative');
$siteUrl  = SITE_URL;
$logoPath = function_exists('getLocalizedLogoPath')
    ? getLocalizedLogoPath('assets/images/logo.png')
    : (function_exists('getSetting') ? getSetting('logo', 'assets/images/logo.png') : 'assets/images/logo.png');
$address  = function_exists('getSetting') ? getSetting('address', t('काठमाडौं, नेपाल', 'Kathmandu, Nepal')) : t('काठमाडौं, नेपाल', 'Kathmandu, Nepal');

/* No-cache + no-index */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="<?php echo isEnglish() ? 'en' : 'ne'; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
<meta name="description" content="Secure digital ID card preview for verified cooperative members.">
<meta name="robots" content="noindex,nofollow">
<title><?php echo htmlspecialchars(t('डिजिटल परिचयपत्र पूर्वावलोकन', 'Digital ID Card Preview')); ?> — <?php echo htmlspecialchars($siteName); ?></title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Mukta:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php if (function_exists('coopThemeHeadAssets')) { coopThemeHeadAssets('minimal', ['skip_fonts' => true]); } ?>
<script>
/* Disable right-click + key shortcuts to discourage trivial copying.
   यो absolute security होइन — सिर्फ casual abuse रोक्ने। */
document.addEventListener('contextmenu', e => e.preventDefault());
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && ['s','p','u'].includes(e.key.toLowerCase())) e.preventDefault();
    if (e.key === 'PrintScreen') e.preventDefault();
});
</script>
</head>
<body>

<?php if (!$valid || !$mem): ?>
    <div class="error-card">
        <div class="error-icon"><i class="fas fa-shield-exclamation"></i></div>
        <h3><?php echo t('लिङ्क पहुँच असफल', 'Link Access Failed'); ?></h3>
        <p><?php echo htmlspecialchars($errMsg ?: t('यो लिङ्क मान्य छैन।', 'This link is invalid.')); ?></p>
        <a href="<?php echo $siteUrl; ?>application-tracker.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> <?php echo t('Tracker मा फर्किनुहोस्', 'Back to Tracker'); ?>
        </a>
    </div>
<?php else: ?>
    <?php
        $cardNo   = htmlspecialchars($mem['member_card_no'] ?: ('MEM-'.str_pad($mem['id'],6,'0',STR_PAD_LEFT)));
        $sadNo    = htmlspecialchars($mem['sadasyata_number'] ?? '—');
        $name     = htmlspecialchars($mem['name']);
        $phone    = htmlspecialchars($mem['phone'] ?? '—');
        $emailMa  = $mem['email'] ?? '';
        /* mask middle of email for privacy: r***@gmail.com */
        if ($emailMa && strpos($emailMa,'@') !== false) {
            [$u,$d] = explode('@',$emailMa,2);
            $u = strlen($u)>2 ? substr($u,0,1).str_repeat('*',max(1,strlen($u)-2)).substr($u,-1) : $u[0].'*';
            $emailMa = $u . '@' . $d;
        }
        $emailMa  = htmlspecialchars($emailMa ?: '—');
        $genTs    = $mem['id_card_generated_at'] ? date('d/m/Y', strtotime($mem['id_card_generated_at'])) : '—';
        $photo    = $mem['profile_image'] ?? '';
    ?>
    <div class="preview-wrap">
        <div class="preview-header">
            <i class="fas fa-shield-halved"></i>
            Verified Preview · <?php echo date('d M Y, H:i'); ?>
        </div>
        <div class="id-card">
            <div class="id-top">
                <?php if ($logoPath): ?>
                    <img src="<?php echo $siteUrl . htmlspecialchars($logoPath); ?>" alt="" onerror="this.style.display='none'">
                <?php endif; ?>
                <div>
                    <div class="org"><?php echo htmlspecialchars($siteName); ?></div>
                    <div class="org-sub">DIGITAL MEMBER ID</div>
                </div>
            </div>
            <div class="id-body">
                <div class="id-photo">
                    <?php if ($photo): ?>
                        <img src="<?php echo $siteUrl . htmlspecialchars($photo); ?>" alt="" onerror="this.parentNode.innerHTML='<i class=\'fas fa-user\'></i>'">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                </div>
                <div class="id-fields">
                    <div class="id-name"><?php echo $name; ?></div>
                    <div class="id-num"><?php echo $cardNo; ?></div>
                    <div class="id-row"><span class="lbl"><?php echo t('सदस्यता', 'Member'); ?>:</span><span class="val"><?php echo $sadNo; ?></span></div>
                    <div class="id-row"><span class="lbl"><?php echo t('फोन', 'Phone'); ?>:</span><span class="val"><?php echo $phone; ?></span></div>
                    <div class="id-row"><span class="lbl"><?php echo t('इमेल', 'Email'); ?>:</span><span class="val"><?php echo $emailMa; ?></span></div>
                    <div class="id-row"><span class="lbl"><?php echo t('जारी मिति', 'Issued Date'); ?>:</span><span class="val"><?php echo $genTs; ?></span></div>
                </div>
            </div>
            <div class="id-footer">
                <?php echo htmlspecialchars($address); ?> · <?php echo t('आधिकारिक डिजिटल परिचयपत्र', 'Official Digital Identity Card'); ?>
            </div>
        </div>
        <div class="preview-meta">
            <strong>🔐 Verified Public Preview</strong><br>
            <?php echo t('यो preview', 'This preview was generated at'); ?> <?php echo date('H:i'); ?> <?php echo t('मा generate भयो र', 'and will'); ?> <strong><?php echo t('१५ मिनेटमा expire', 'expire in 15 minutes'); ?></strong> <?php echo t('हुन्छ।', '.'); ?><br>
            <?php echo t('पूर्ण उपयोग र Download को लागि', 'For full access and download, login to'); ?> <a href="<?php echo $siteUrl; ?>member/login.php" style="color:var(--primary-color);font-weight:600;">Member Portal</a>.
            <br>
            <a href="<?php echo $siteUrl; ?>application-tracker.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> <?php echo t('Tracker मा फर्किनुहोस्', 'Back to Tracker'); ?>
            </a>
        </div>
    </div>
<?php endif; ?>

</body>
</html>
