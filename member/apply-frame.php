<?php
/**
 * सार्वजनिक आवेदन फारमहरू — सदस्य पोर्टल भित्र (iframe, same-origin session)
 * Quick Apply लिंक यहीँबाट खुल्छन्; welfare जस्तो native पेज होइन तर लगिन र सत्र एउटै हुन्छ।
 */
require_once __DIR__ . '/_bootstrap.php';
requireMemberLogin();
memberSecurityHeaders();
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

$frames = [
    'appointment' => [
        'path' => 'appointment.php',
        'title' => $_t('भेटघाट बुक गर्नुहोस्', 'Book Appointment'),
        'hint' => $_t('तलको फारममा तपाईंको प्रोफाइल/KYC बाट विवरण auto-fill हुनेछ। मिति र उद्देश्य भर्नुहोस्।', 'Your profile/KYC details will auto-fill below. Fill date and purpose.'),
    ],
    'kyc' => [
        'path' => 'online-kyc.php',
        'title' => $_t('KYC दर्ता / अपडेट', 'KYC Register / Update'),
        'hint' => $_t('अनलाइन KYC फारम — लगिन सत्र प्रयोग भइरहेको छ।', 'Online KYC form using your current login session.'),
    ],
    'loan' => [
        'path' => 'loan-apply.php',
        'title' => $_t('ऋण आवेदन', 'Loan Application'),
        'hint' => $_t('सदस्य प्रोफाइल अनुसार नाम, सम्पर्क आदि भरिनेछन्। ऋण विवरण मात्र थप्नुहोस्।', 'Name/contact will auto-fill from profile. Add loan details only.'),
    ],
    'account' => [
        'path' => 'online-account.php',
        'title' => $_t('खाता खोल्ने आवेदन', 'Open Account Application'),
        'hint' => $_t('व्यक्तिगत विवरण प्रोफाइलबाट लिइन्छ। खाता प्रकार र अन्य विवरण भर्नुहोस्।', 'Personal details come from profile. Fill account type and other details.'),
    ],
    'digital' => [
        'path' => 'digital-services.php',
        'title' => $_t('डिजिटल सेवा अनुरोध', 'Digital Service Request'),
        'hint' => $_t('डिजिटल सेवा छानेर विवरण पठाउनुहोस्।', 'Choose digital service and submit details.'),
    ],
    'grievance' => [
        'path' => 'grievance.php',
        'title' => $_t('गुनासो दर्ता', 'Submit Grievance'),
        'hint' => $_t('सम्पर्क विवरण प्रोफाइलबाट भरिनेछ। गुनासो विवरण लेख्नुहोस्।', 'Contact details auto-fill from profile. Write grievance details.'),
    ],
    'career' => [
        'path' => 'career.php',
        'title' => $_t('रोजगार / जागिर', 'Career / Job'),
        'hint' => $_t('खुला पदहरू र आवेदन यही फ्रेमभित्र।', 'Open vacancies and applications are available in this frame.'),
    ],
    'emi' => [
        'path' => 'emi-calculator.php',
        'title' => $_t('EMI गणना', 'EMI Calculator'),
        'hint' => $_t('किस्ता क्यालकुलेटर — सार्वजनिक उपकरण।', 'Installment calculator — public tool.'),
    ],
];

$p = $_GET['p'] ?? '';
if (!isset($frames[$p])) {
    header('Location: ' . SITE_URL . 'member/');
    exit;
}

$meta = $frames[$p];
$siteName = getSetting('site_name', 'सहकारी');
$pageTitle = $meta['title'] . ' — ' . $siteName;
/*
 * Path-only URL — iframe सधैं अहिलेको पृष्ठ जस्तै https/host प्रयोग गर्छ (mixed content ब्लक हुँदैन)।
 * embed=1 ले public header मा हेडर/लोडर लुकाउँछ — iframe भित्र फारम देखिन्छ।
 */
$pu = parse_url(rtrim(SITE_URL, '/') . '/');
$pathPrefix = isset($pu['path']) ? rtrim((string)$pu['path'], '/') : '';
$frameSrc = ($pathPrefix === '' ? '' : $pathPrefix) . '/' . ltrim($meta['path'], '/');
$frameSrc = preg_replace('#/{2,}#', '/', $frameSrc);
$qParts = [];
$extraQ = trim((string)($GLOBALS['member_frame_extra_query'] ?? ''));
if ($extraQ !== '') {
    parse_str($extraQ, $extraParsed);
    if (is_array($extraParsed)) {
        $qParts = $extraParsed;
    }
}
$qParts['embed'] = '1';
$frameSrc .= '?' . http_build_query($qParts);
unset($GLOBALS['member_frame_extra_query']);

require __DIR__ . '/includes/chrome.php';
?>

<div class="mem-alert mem-alert-info mem-apply-hint">
    <i class="fas fa-shield-halved"></i>
    <?php echo htmlspecialchars($meta['hint']); ?>
    <span class="d-block mt-1 mem-apply-hint-sub"><?php echo $_t('सम्पूर्ण आवेदन सुरक्षित रूपमा सहकारीमा पठाइन्छ।', 'All applications are sent securely to the cooperative.'); ?></span>
</div>

<div class="mem-card mem-apply-frame-card">
    <div class="mem-card-header mem-apply-frame-head">
        <div class="mem-card-title mem-apply-frame-title"><i class="fas fa-file-signature"></i><?php echo htmlspecialchars($meta['title']); ?></div>
        <a href="<?php echo SITE_URL; ?>member/tracker.php" class="mem-apply-frame-link"><?php echo $_t('ट्र्याकर', 'Tracker'); ?> →</a>
    </div>
    <div class="mem-card-body mem-apply-frame-body">
        <iframe class="mem-public-form-frame" title="<?php echo htmlspecialchars($meta['title']); ?>"
                src="<?php echo htmlspecialchars($frameSrc); ?>"
                loading="eager" referrerpolicy="same-origin"></iframe>
    </div>
</div>

<style>
.mem-apply-frame-card { overflow: hidden; }
.mem-apply-hint{margin-bottom:14px;font-size:.86rem;line-height:1.5;}
.mem-apply-hint-sub{opacity:.9;}
.mem-apply-frame-head{padding:12px 16px;}
.mem-apply-frame-title{font-size:.92rem;}
.mem-apply-frame-link{font-size:.78rem;font-weight:700;color:var(--mem-primary);text-decoration:none;white-space:nowrap;}
.mem-apply-frame-body{padding:0;}
.mem-public-form-frame {
    display: block;
    width: 100%;
    min-height: min(78vh, 900px);
    height: 78vh;
    border: 0;
    background: color-mix(in srgb, var(--primary-color) 8%, white);
}
@media (max-width: 768px) {
    .mem-public-form-frame {
        min-height: 70vh;
        height: 70vh;
    }
}
</style>

<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
