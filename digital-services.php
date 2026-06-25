<?php
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
ensurePublicTables();
$_dsrtFile = __DIR__ . '/includes/digital-service-requests-tables.php';
if (is_file($_dsrtFile)) { require_once $_dsrtFile; }
unset($_dsrtFile);
$kycPublicFormFile = __DIR__ . '/includes/kyc-public-form.php';
if (is_file($kycPublicFormFile)) {
    require_once $kycPublicFormFile;
}
$pageTitle = isEnglish() ? 'Digital Service Request' : 'डिजिटल सेवा अनुरोध';

$success    = false;
$error      = '';
$trackingId = '';
$loggedMember = getLoggedInMemberProfile();
$lockedMemberFields = $loggedMember ? 'readonly' : '';
$isEmbed = !empty($_GET['embed']);
$trackerUrl = $isEmbed ? (SITE_URL . 'member/tracker.php') : 'application-tracker.php';

/* सेवा प्रकारहरू — icon, Nepali र English नाम */
$serviceTypes = [
    'missed_call_banking' => ['np' => 'मिस्ड कल बैंकिङ',        'en' => 'Missed Call Banking',     'icon' => 'fa-phone-volume',   'color' => 'var(--primary-color)'],
    'statement_request'   => ['np' => 'स्टेटमेन्ट अनुरोध',       'en' => 'Statement Request',        'icon' => 'fa-file-invoice',   'color' => 'var(--primary-color)'],
    'bill_payment'        => ['np' => 'बिल भुक्तानी सहयोग',      'en' => 'Bill Payment Support',     'icon' => 'fa-receipt',        'color' => 'var(--secondary-color)'],
    'mobile_recharge'     => ['np' => 'मोबाइल रिचार्ज अनुरोध',   'en' => 'Mobile Recharge Request',  'icon' => 'fa-mobile-screen',  'color' => 'var(--primary-light)'],
    'internet_banking'    => ['np' => 'इन्टरनेट/मोबाइल बैंकिङ', 'en' => 'Internet/Mobile Banking',  'icon' => 'fa-laptop-code',    'color' => 'var(--accent-color)'],
    'sms_alert'           => ['np' => 'SMS अलर्ट सेवा',          'en' => 'SMS Alert Service',        'icon' => 'fa-bell',           'color' => 'var(--secondary-color)'],
    'card_service'        => ['np' => 'कार्ड सेवा',              'en' => 'Card Service',             'icon' => 'fa-credit-card',    'color' => 'var(--primary-color)'],
    'qr_payment'          => ['np' => 'QR/डिजिटल भुक्तानी',     'en' => 'QR / Digital Payment',     'icon' => 'fa-qrcode',         'color' => 'var(--primary-light)'],
    'share_refund'        => ['np' => 'शेयर फिर्ता (Refund)',    'en' => 'Share Refund',             'icon' => 'fa-money-bill-transfer', 'color' => 'var(--accent-color)'],
    'share_increase'      => ['np' => 'शेयर वृद्धि (Increase)',  'en' => 'Share Increase',           'icon' => 'fa-chart-line',     'color' => 'var(--primary-color)'],
    'other'               => ['np' => 'अन्य डिजिटल सेवा',        'en' => 'Other Digital Service',    'icon' => 'fa-headset',        'color' => 'var(--secondary-color)'],
];

$db = null;
try {
    $db = getDB();
} catch (\Throwable $e) {
}

/* =============================================
   FORM SUBMIT — POST handler
   ============================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed.' : 'सुरक्षा जाँच असफल।';
    } elseif (!checkRateLimit('digital_service_request', 5, 600)) {
        $error = isEnglish() ? 'Too many requests. Please wait.' : 'धेरै अनुरोधहरू। कृपया पर्खनुहोस्।';
    } else {
        $requesterName   = clean_text($_POST['requester_name']   ?? '', 120);
        $memberId        = clean_text($_POST['member_id']         ?? '', 50);
        $phone           = preg_replace('/[^0-9]/', '', clean_text($_POST['phone']             ?? '', 20));
        $email           = strtolower(clean_text($_POST['email']             ?? '', 120));
        $serviceType     = clean_text($_POST['service_type']      ?? '', 60);
        $accountNumber   = clean_text($_POST['account_number']    ?? '', 50);
        $statementFrom   = clean_text($_POST['statement_from']    ?? '', 20);
        $statementTo     = clean_text($_POST['statement_to']      ?? '', 20);
        $billerName      = clean_text($_POST['biller_name']       ?? '', 120);
        $billReference   = clean_text($_POST['bill_reference']    ?? '', 120);
        $rechargeNumber  = preg_replace('/[^0-9]/', '', clean_text($_POST['recharge_number']   ?? '', 20));
        $rechargeAmount  = ($_POST['recharge_amount'] ?? '') !== '' ? (float)$_POST['recharge_amount'] : null;
        $serviceAmount   = ($_POST['service_amount'] ?? '') !== '' ? (float)$_POST['service_amount'] : null;
        $requestDetails  = clean_text($_POST['request_details']   ?? '', 4000);
        $rawPref         = clean_text($_POST['preferred_contact'] ?? 'phone', 20);
        $preferredContact = in_array($rawPref, ['phone', 'email', 'branch'], true) ? $rawPref : 'phone';

        try {
            $db = getDB();
        } catch (\Throwable $dbErr) {
            $error = isEnglish() ? 'Service temporarily unavailable. Please try again.' : 'सेवा अस्थायी उपलब्ध छैन। पुनः प्रयास गर्नुहोस्।';
        }
        $isCoopMember = $loggedMember ? 'yes' : ((($_POST['is_coop_member'] ?? '') === 'yes') ? 'yes' : 'no');
        $kycMerge = null;
        if ($loggedMember) {
            if (function_exists('loadKycRowForLoggedMemberPublic')) {
                $kycMerge = loadKycRowForLoggedMemberPublic($db, $loggedMember);
            }
            if (!$kycMerge || strtolower(trim((string)($kycMerge['status'] ?? ''))) !== 'approved') {
                $error = isEnglish() ? 'KYC verification is required for digital services.' : 'डिजिटल सेवा प्रयोग गर्न KYC verified (approved) हुनुपर्छ।';
            }
            $fnK = (is_array($kycMerge) && !empty($kycMerge['full_name'])) ? trim((string)$kycMerge['full_name']) : '';
            $requesterName = $fnK !== '' ? $fnK : trim((string)($loggedMember['name'] ?? $requesterName));
            $midK = (is_array($kycMerge) && !empty($kycMerge['member_id'])) ? trim((string)$kycMerge['member_id']) : '';
            $memberId = $midK !== '' ? $midK : trim((string)($loggedMember['sadasyata_number'] ?? $memberId));
            $phone = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $loggedMember['phone'] ?? $phone));
            $email = strtolower(trim((string)($kycMerge['email'] ?? $loggedMember['email'] ?? $email)));
        } elseif ($isCoopMember === 'yes') {
            if (!function_exists('verifyPublicFormKycApprovedByMemberId')) {
                $error = isEnglish()
                    ? 'KYC verification service is temporarily unavailable. Please try again.'
                    : 'KYC प्रमाणीकरण सेवा हाल उपलब्ध छैन। कृपया पुनः प्रयास गर्नुहोस्।';
            } else {
                $v = verifyPublicFormKycApprovedByMemberId($db, $_POST['member_id'] ?? '');
                if (!$v['ok']) {
                    $error = isEnglish() ? $v['msg_en'] : $v['msg_np'];
                } else {
                    $kycMerge = $v['row'];
                    $requesterName = trim((string)($kycMerge['full_name'] ?? ''));
                    $memberId = strtoupper(trim((string)($kycMerge['member_id'] ?? $_POST['member_id'] ?? '')));
                    $phone = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $_POST['phone'] ?? ''));
                    $email = strtolower(trim((string)($kycMerge['email'] ?? $_POST['email'] ?? '')));
                }
            }
        }

        if (!$error && $requesterName === '') {
            $error = isEnglish() ? 'Please enter your full name.' : 'कृपया पूरा नाम भर्नुहोस्।';
        } elseif (!$error && $phone === '') {
            $error = isEnglish() ? 'Mobile number is required.' : 'मोबाइल नम्बर अनिवार्य छ।';
        } elseif (!$error && !preg_match('/^[0-9]{10}$/', $phone)) {
            $error = isEnglish() ? 'Enter a valid 10-digit mobile number.' : 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।';
        } elseif (!$error && $email === '') {
            $error = isEnglish() ? 'Email address is required.' : 'इमेल ठेगाना अनिवार्य छ।';
        } elseif (!$error && !empty($email) && !isValidEmail($email)) {
            $error = isEnglish() ? 'Please enter a valid email address.' : 'कृपया सही इमेल ठेगाना राख्नुहोस्।';
        } elseif (!$error && ($serviceType === '' || !isset($serviceTypes[$serviceType]))) {
            $error = isEnglish() ? 'Please select a valid service.' : 'कृपया सही सेवा छान्नुहोस्।';
        } elseif (!$error && in_array($serviceType, ['share_refund', 'share_increase'], true) && ($serviceAmount === null || $serviceAmount <= 0)) {
            $error = isEnglish() ? 'Please enter a valid amount for selected share service.' : 'छानिएको शेयर सेवाको लागि सही रकम राख्नुहोस्।';
        } elseif (!$error) {
            try {
                $trackingId = 'DSR-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
                $attachment = '';
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                    $upload = uploadFile($_FILES['attachment'], 'digital_services');
                    if ($upload['success']) {
                        $attachment = $upload['path'];
                    }
                }

                $stmt = $db->prepare("INSERT INTO digital_service_requests
                    (tracking_id, requester_name, member_id, phone, email,
                     service_type, service_type_np, account_number,
                     statement_from, statement_to, biller_name, bill_reference,
                     recharge_number, recharge_amount, service_amount, request_details, attachment, preferred_contact)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    $trackingId, $requesterName, $memberId, $phone, $email,
                    $serviceType, $serviceTypes[$serviceType]['np'], $accountNumber,
                    $statementFrom ?: null, $statementTo ?: null,
                    $billerName, $billReference,
                    $rechargeNumber, $rechargeAmount, $serviceAmount,
                    $requestDetails, $attachment,
                    in_array($preferredContact, ['phone','email','branch'], true) ? $preferredContact : 'phone'
                ]);
                $success = true;
                logSecurityEvent('digital_service_request', 'Request submitted: ' . $trackingId);

                /* Notifications — guarded so missing/broken file does not 500 the form */
                $__nf = __DIR__ . '/includes/notifications.php';
                if (is_file($__nf)) { require_once $__nf; }
                unset($__nf);

                // Member confirmation SMS
                if (!empty($phone)) {
                    try {
                        $smsApiToken = getSetting('notify_sms_token', '');
                        $smsSenderId = getSetting('notify_sms_sender_id', 'COOP');
                        $smsOn = getSetting('notify_sms_enabled', '0') === '1';
                        if ($smsOn && $smsApiToken) {
                            $smsTxt = 'आकाश सहकारी: तपाईंको डिजिटल सेवा अनुरोध दर्ता भयो। Tracking ID: ' . $trackingId . '. application-tracker.php मा track गर्नुहोस्।';
                            $smsTxt = mb_substr($smsTxt, 0, 160);
                            $ph2 = preg_replace('/[^0-9]/', '', $phone);
                            if (strlen($ph2) >= 10) {
                                $ch2 = curl_init('http://api.sparrowsms.com/v2/sms/');
                                curl_setopt_array($ch2, [
                                    CURLOPT_POST           => true,
                                    CURLOPT_POSTFIELDS     => http_build_query(['token'=>$smsApiToken,'from'=>$smsSenderId,'to'=>$ph2,'text'=>$smsTxt]),
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_TIMEOUT        => 10,
                                    CURLOPT_SSL_VERIFYPEER => true,
                                ]);
                                curl_exec($ch2);
                                curl_close($ch2);
                            }
                        }
                    } catch (Exception $ignored) {}
                }

                sendAdminNotification('digital_service', [
                    'नाम'           => $requesterName,
                    'फोन'           => $phone,
                    'इमेल'          => $email ?: 'N/A',
                    'सेवा प्रकार'    => $serviceTypes[$serviceType]['en'],
                    'सम्पर्क माध्यम' => $preferredContact,
                    'मिति'          => date('Y-m-d H:i'),
                ], $trackingId);
            } catch (\Throwable $e) {
                $error = isEnglish() ? 'Unable to submit. Please try again.' : 'अनुरोध पेश गर्न सकिएन। पुनः प्रयास गर्नुहोस्।';
            }
        }
    }
}

require_once 'includes/header.php';
$L = getLangStrings();
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><i class="fas fa-mobile-alt me-2"></i><?php echo $pageTitle; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $pageTitle; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Success Banner (POST success पछि inline देखिन्छ) -->
<?php if ($success && $trackingId): ?>
<section class="form-success-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7 form-success-card">
                <div class="form-success-icon"><i class="fas fa-check-circle"></i></div>
                <h3 class="mt-3 fw-bold ds-title-ok"><?php echo isEnglish() ? 'Request Submitted Successfully!' : 'अनुरोध सफलतापूर्वक पेश भयो!'; ?></h3>
                <p class="ds-muted mb-4"><?php echo isEnglish() ? 'Save your Tracking ID to check your request status.' : 'तपाईंको Tracking ID तल छ। Status हेर्न यो ID सुरक्षित राख्नुहोस्।'; ?></p>
                <div class="form-tracking-box">
                    <div class="ds-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID — save this!' : 'तपाईंको Tracking ID — सुरक्षित राख्नुहोस्!'; ?></div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="form-tracking-id" id="dsTrkId"><?php echo e($trackingId); ?></div>
                        <button type="button" onclick="copyTrk('dsTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2 ds-track-copy" title="Copy"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
                    </div>
                    <div class="form-tracking-help"><a href="<?php echo e($trackerUrl); ?>" class="ds-track-help-link"><?php echo isEnglish() ? 'Click here' : 'यहाँ बाट'; ?></a> <?php echo isEnglish() ? 'to check status in Tracker.' : 'Tracker मा स्थिति हेर्नुहोस्।'; ?></div>
                </div>
                <div class="mt-3">
                    <a href="<?php echo e($trackerUrl); ?>" class="btn ds-btn-success px-4 me-2">
                        <i class="fas fa-search me-1"></i><?php echo isEnglish() ? 'Track My Request' : 'अनुरोध ट्र्याक'; ?>
                    </a>
                    <a href="digital-services.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="btn btn-outline-secondary px-4">
                        <i class="fas fa-plus me-1"></i><?php echo isEnglish() ? 'New Request' : 'नयाँ अनुरोध'; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php else: ?>
<!-- Hero Intro Section -->
<section class="section-padding pb-4">
    <div class="container">
        <div class="row justify-content-center mb-5">
            <div class="col-lg-8 text-center">
                <div class="section-header text-center mb-3">
                    <h2><?php echo isEnglish() ? 'Digital Services at Your Fingertips' : 'डिजिटल सेवाहरू, घरैबाट'; ?></h2>
                </div>
                <p class="ds-muted mb-4">
                    <?php echo isEnglish()
                        ? 'Request any digital banking service online — no need to visit the office. Select a service below, fill the form, and track your request anytime.'
                        : 'कुनै पनि डिजिटल बैंकिङ सेवा अनलाइनै अनुरोध गर्नुहोस् — कार्यालय आउन परदैन। तलबाट सेवा छानेर फारम भर्नुहोस् र जुनसुकै बेला track गर्नुहोस्।'; ?>
                </p>
                <a href="#ds-form-section" class="btn ds-btn-primary btn-lg px-5">
                    <i class="fas fa-paper-plane me-2"></i>
                    <?php echo isEnglish() ? 'Request a Service' : 'सेवा अनुरोध गर्नुहोस्'; ?>
                </a>
                <?php if ($success): ?>
                <a href="<?php echo e($trackerUrl); ?>" class="btn btn-outline-success btn-lg px-4 ms-2">
                    <i class="fas fa-search me-2"></i>
                    <?php echo isEnglish() ? 'Track My Request' : 'अनुरोध ट्र्याक गर्नुहोस्'; ?>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Process steps — ४ चरण -->
        <div class="row g-4 mb-5">
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm text-center p-3 h-100">
                    <div class="mb-2 ds-step-icon ds-step-icon-primary"><i class="fas fa-th-large"></i></div>
                    <h6 class="fw-600"><?php echo isEnglish() ? '1. Choose Service' : '१. सेवा छान्नुहोस्'; ?></h6>
                    <p class="ds-muted small mb-0"><?php echo isEnglish() ? 'Pick the service you need.' : 'चाहिएको डिजिटल सेवा छान्नुहोस्।'; ?></p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm text-center p-3 h-100">
                    <div class="mb-2 ds-step-icon ds-step-icon-secondary"><i class="fas fa-edit"></i></div>
                    <h6 class="fw-600"><?php echo isEnglish() ? '2. Fill Form' : '२. फारम भर्नुहोस्'; ?></h6>
                    <p class="ds-muted small mb-0"><?php echo isEnglish() ? 'Submit your request details.' : 'आफ्नो विवरण भर्नुहोस्।'; ?></p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm text-center p-3 h-100">
                    <div class="mb-2 ds-step-icon ds-step-icon-accent"><i class="fas fa-id-card"></i></div>
                    <h6 class="fw-600"><?php echo isEnglish() ? '3. Get Tracking ID' : '३. Tracking ID पाउनुहोस्'; ?></h6>
                    <p class="ds-muted small mb-0"><?php echo isEnglish() ? 'A unique code is issued instantly.' : 'तुरुन्तै unique code पाउनुहुन्छ।'; ?></p>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card border-0 shadow-sm text-center p-3 h-100">
                    <div class="mb-2 ds-step-icon ds-step-icon-light"><i class="fas fa-check-circle"></i></div>
                    <h6 class="fw-600"><?php echo isEnglish() ? '4. Service Delivered' : '४. सेवा पाउनुहोस्'; ?></h6>
                    <p class="ds-muted small mb-0"><?php echo isEnglish() ? 'We contact you to fulfill the request.' : 'हामी सम्पर्क गरेर सेवा दिन्छौं।'; ?></p>
                </div>
            </div>
        </div>

        <!-- Service cards grid — प्रत्येक सेवा एउटा card -->
        <div class="section-header text-center mb-4">
            <h3><?php echo isEnglish() ? 'Available Digital Services' : 'उपलब्ध डिजिटल सेवाहरू'; ?></h3>
            <p class="ds-muted"><?php echo isEnglish() ? 'Click any card to jump to the request form for that service.' : 'जुन सेवा चाहिएको छ, त्यो card मा click गर्नुहोस् — तलको फारममा सिधै जान्छ।'; ?></p>
        </div>
        <div class="row g-3 mb-2">
            <?php foreach ($serviceTypes as $key => $type): ?>
            <div class="col-lg-4 col-md-6">
                <button type="button"
                    class="ds-service-card w-100 text-start"
                    data-service="<?php echo $key; ?>"
                    style="--card-color:<?php echo $type['color']; ?>;">
                    <span class="ds-icon"><i class="fas <?php echo $type['icon']; ?>"></i></span>
                    <span class="ds-label">
                        <strong><?php echo isEnglish() ? $type['en'] : $type['np']; ?></strong>
                        <?php if (!isEnglish()): ?>
                        <small><?php echo $type['en']; ?></small>
                        <?php endif; ?>
                    </span>
                    <span class="ds-arrow"><i class="fas fa-chevron-right"></i></span>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</section>

<!-- =============================================
     Inline Service Request Form (no modal)
     ============================================= -->
<section class="section-padding pt-2" id="ds-form-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9">

                <!-- Error alert (shown above the card) -->
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" id="ds-error-alert" role="alert">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <div class="card shadow-sm border-0 ds-form-card-shell">
                    <!-- Card header -->
                    <div class="card-header py-3 px-4 text-white ds-form-card-head">
                        <h5 class="mb-0">
                            <i class="fas fa-paper-plane me-2"></i>
                            <?php echo isEnglish() ? 'Digital Service Request Form' : 'डिजिटल सेवा अनुरोध फारम'; ?>
                        </h5>
                        <p class="mb-0 mt-1 opacity-75 ds-form-card-sub">
                            <?php echo isEnglish()
                                ? 'Fill in the details below. You will receive a Tracking ID on submission.'
                                : 'तलको फारम भर्नुहोस्। पेश गरेपछि Tracking ID पाउनुहुन्छ।'; ?>
                        </p>
                    </div>
                    <div class="card-body p-4">

                <!-- Request Form -->
                <form method="POST" enctype="multipart/form-data" id="digitalServiceForm" class="needs-validation" novalidate>
                    <?php echo csrfField(); ?>
                    <?php if ($loggedMember):
                        $kycForDisplay = isset($kycMerge) ? $kycMerge : null;
                        if (!$kycForDisplay) {
                            try { $kycForDisplay = loadKycRowForLoggedMemberPublic(getDB(), $loggedMember); } catch(Throwable $e) {}
                        }
                        require ROOT_PATH . 'includes/member-prefill-block.php';
                    else: ?>
                    <div class="border rounded-3 p-3 mb-3 ds-soft-bg ds-coop-card">
                        <label class="form-label fw-semibold d-block mb-2"><?php echo isEnglish() ? 'Are you a cooperative member?' : 'तपाईं सहकारी सदस्य हुनुहुन्छ?'; ?></label>
                        <div class="d-flex flex-wrap gap-3 ds-coop-radio-row">
                            <label class="form-check-label ds-coop-option"><input type="radio" name="is_coop_member" value="no" class="form-check-input js-ds-coop" <?php echo (($_POST['is_coop_member'] ?? 'no') === 'yes') ? '' : 'checked'; ?>> <span><?php echo isEnglish() ? 'No' : 'होइन'; ?></span></label>
                            <label class="form-check-label ds-coop-option"><input type="radio" name="is_coop_member" value="yes" class="form-check-input js-ds-coop" <?php echo (($_POST['is_coop_member'] ?? '') === 'yes') ? 'checked' : ''; ?>> <span><?php echo isEnglish() ? 'Yes (Member ID + email + mobile = KYC)' : 'हो (KYC जस्तै तीन विवरण)'; ?></span></label>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Section: व्यक्तिगत जानकारी — guest only -->
                    <?php if (!$loggedMember): ?>
                    <div class="form-card-title mb-3">
                        <i class="fas fa-user"></i>
                        <?php echo isEnglish() ? 'Personal Information' : 'व्यक्तिगत जानकारी'; ?>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6 js-ds-name-wrap">
                            <label class="form-label"><?php echo isEnglish() ? 'Full Name' : 'पूरा नाम'; ?> <span class="req">*</span></label>
                            <input type="text" name="requester_name" class="form-control js-ds-personal" required
                                   placeholder="<?php echo isEnglish() ? 'Your full name' : 'तपाईंको पूरा नाम'; ?>"
                                   value="<?php echo e($_POST['requester_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                <?php echo isEnglish() ? 'Member No.' : 'सदस्य नं.'; ?>
                                <span class="ds-req-mark js-ds-mid-req ds-mid-req">*</span>
                                <small class="ds-muted js-ds-mid-opt">(<?php echo isEnglish() ? 'optional' : 'ऌच्छिक'; ?>)</small>
                            </label>
                            <input type="text" name="member_id" class="form-control js-ds-mid"
                                   placeholder="<?php echo isEnglish() ? 'e.g. 12345' : 'जस्तै: १२३४५'; ?>"
                                   value="<?php echo e($_POST['member_id'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 js-hide-if-ds-coop-yes">
                            <label class="form-label"><?php echo isEnglish() ? 'Mobile Number' : 'मोबाइल नम्बर'; ?> <span class="req">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-mobile-alt"></i></span>
                                <input type="tel" name="phone" class="form-control js-ds-personal" required
                                       placeholder="9827157000" pattern="[0-9]{10}" maxlength="10"
                                       value="<?php echo e($_POST['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6 js-hide-if-ds-coop-yes">
                            <label class="form-label"><?php echo isEnglish() ? 'Email Address' : 'इमेल ठेगाना'; ?> <span class="req">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="email" class="form-control js-ds-personal" required
                                       placeholder="akashpame@gmail.com"
                                       value="<?php echo e($_POST['email'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <?php endif; /* !$loggedMember */?>
                    <script>
                    (function(){
                      function syncDsCoop(){
                        var f=document.getElementById('digitalServiceForm'); if(!f) return;
                        var yes=f.querySelector('input.js-ds-coop[value=yes]:checked');
                        var nameWrap=f.querySelector('.js-ds-name-wrap');
                        var pers=f.querySelectorAll('.js-ds-personal');
                        var mid=f.querySelector('input.js-ds-mid');
                        var midReq=f.querySelector('.js-ds-mid-req');
                        var midOpt=f.querySelector('.js-ds-mid-opt');
                        if(yes){
                          f.querySelectorAll('.js-hide-if-ds-coop-yes').forEach(function(el){el.style.display='none';});
                          if(nameWrap) nameWrap.style.display='none';
                          pers.forEach(function(el){ el.removeAttribute('required'); });
                          if(mid){ mid.setAttribute('required','required'); }
                          if(midReq) midReq.style.display='';
                          if(midOpt) midOpt.style.display='none';
                        }else{
                          f.querySelectorAll('.js-hide-if-ds-coop-yes').forEach(function(el){el.style.display='';});
                          if(nameWrap) nameWrap.style.display='';
                          pers.forEach(function(el){ el.setAttribute('required','required'); });
                          if(mid){ mid.removeAttribute('required'); }
                          if(midReq) midReq.style.display='none';
                          if(midOpt) midOpt.style.display='';
                        }
                      }
                      document.querySelectorAll('#digitalServiceForm input.js-ds-coop').forEach(function(r){ r.addEventListener('change',syncDsCoop); });
                      document.addEventListener('DOMContentLoaded',syncDsCoop);
                    })();
                    </script>

                    <!-- Section: सेवा विवरण -->
                    <div class="form-card-title mb-3">
                        <i class="fas fa-mobile-alt"></i>
                        <?php echo isEnglish() ? 'Service Details' : 'सेवा विवरण'; ?>
                    </div>
                    <div class="row g-3 mb-3">
                        <!-- सेवा प्रकार -->
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isEnglish() ? 'Service Type' : 'सेवा प्रकार'; ?> <span class="req">*</span></label>
                            <select name="service_type" id="serviceType" class="form-select" required>
                                <option value=""><?php echo isEnglish() ? 'Select a service...' : 'सेवा छान्नुहोस्...'; ?></option>
                                <?php foreach ($serviceTypes as $key => $type): ?>
                                <option value="<?php echo $key; ?>"
                                    <?php echo (($_POST['service_type'] ?? '') === $key) ? 'selected' : ''; ?>>
                                    <?php echo isEnglish() ? $type['en'] : ($type['np'] . ' / ' . $type['en']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- खाता नं. -->
                        <div class="col-md-6">
                            <label class="form-label">
                                <?php echo isEnglish() ? 'Account No.' : 'खाता नं.'; ?>
                                <small class="ds-muted">(<?php echo isEnglish() ? 'optional' : 'ऐच्छिक'; ?>)</small>
                            </label>
                            <input type="text" name="account_number" class="form-control"
                                   placeholder="<?php echo isEnglish() ? 'Account number' : 'खाता नम्बर'; ?>"
                                   value="<?php echo e($_POST['account_number'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Conditional: Statement fields (statement_request छानेमा मात्र देखिन्छ) -->
                    <div class="conditional-fields statement-fields">
                        <div class="ds-conditional-box">
                            <div class="ds-conditional-label">
                                <i class="fas fa-file-invoice me-1"></i>
                                <?php echo isEnglish() ? 'Statement Period' : 'Statement अवधि'; ?>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'From Date' : 'देखि मिति'; ?></label>
                                    <input type="text" name="statement_from" class="form-control nepali-datepicker"
                                           value="<?php echo e($_POST['statement_from'] ?? ''); ?>" placeholder="YYYY-MM-DD" autocomplete="off">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'To Date' : 'सम्म मिति'; ?></label>
                                    <input type="text" name="statement_to" class="form-control nepali-datepicker"
                                           value="<?php echo e($_POST['statement_to'] ?? ''); ?>" placeholder="YYYY-MM-DD" autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Conditional: Bill Payment fields -->
                    <div class="conditional-fields bill-fields">
                        <div class="ds-conditional-box">
                            <div class="ds-conditional-label">
                                <i class="fas fa-receipt me-1"></i>
                                <?php echo isEnglish() ? 'Bill Details' : 'बिल विवरण'; ?>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Biller / Service Provider' : 'बिल/सेवा प्रदायक'; ?></label>
                                    <input type="text" name="biller_name" class="form-control"
                                           placeholder="<?php echo isEnglish() ? 'e.g. NEA, Municipality' : 'जस्तै: NEA, नगरपालिका'; ?>"
                                           value="<?php echo e($_POST['biller_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Bill / Reference No.' : 'बिल/Reference नं.'; ?></label>
                                    <input type="text" name="bill_reference" class="form-control"
                                           value="<?php echo e($_POST['bill_reference'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Conditional: Mobile Recharge fields -->
                    <div class="conditional-fields recharge-fields">
                        <div class="ds-conditional-box">
                            <div class="ds-conditional-label">
                                <i class="fas fa-mobile-screen me-1"></i>
                                <?php echo isEnglish() ? 'Recharge Details' : 'रिचार्ज विवरण'; ?>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Recharge Number' : 'रिचार्ज नम्बर'; ?></label>
                                    <input type="tel" name="recharge_number" class="form-control"
                                           placeholder="9827157000"
                                           value="<?php echo e($_POST['recharge_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Amount (Rs.)' : 'रकम (रु.)'; ?></label>
                                    <input type="number" name="recharge_amount" class="form-control"
                                           min="0" step="1" placeholder="100"
                                           value="<?php echo e($_POST['recharge_amount'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Conditional: Share services fields -->
                    <div class="conditional-fields share-fields">
                        <div class="ds-conditional-box">
                            <div class="ds-conditional-label">
                                <i class="fas fa-money-bill-transfer me-1"></i>
                                <?php echo isEnglish() ? 'Share Service Details' : 'शेयर सेवा विवरण'; ?>
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Amount (Rs.)' : 'रकम (रु.)'; ?> <span class="req">*</span></label>
                                    <input type="number" name="service_amount" class="form-control"
                                           min="0" step="1" placeholder="1000"
                                           value="<?php echo e($_POST['service_amount'] ?? ''); ?>">
                                    <small class="ds-muted"><?php echo isEnglish() ? 'Enter requested share refund/increase amount.' : 'मागिएको शेयर refund/increase रकम राख्नुहोस्।'; ?></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- थप विवरण र Attachment -->
                    <div class="row g-3 mt-1">
                        <div class="col-12">
                            <label class="form-label"><?php echo isEnglish() ? 'Additional Details' : 'थप विवरण'; ?></label>
                            <textarea name="request_details" class="form-control" rows="3"
                                placeholder="<?php echo isEnglish() ? 'Describe your request in detail...' : 'सेवा सम्बन्धी थप विवरण लेख्नुहोस्...'; ?>"
                            ><?php echo e($_POST['request_details'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                <?php echo isEnglish() ? 'Attachment' : 'संलग्न कागजात'; ?>
                                <small class="ds-muted">(<?php echo isEnglish() ? 'optional' : 'ऐच्छिक'; ?>)</small>
                            </label>
                            <input type="file" name="attachment" class="form-control"
                                   accept="image/*,.pdf,.doc,.docx">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isEnglish() ? 'Preferred Contact' : 'सम्पर्क माध्यम'; ?></label>
                            <select name="preferred_contact" class="form-select">
                                <option value="phone"  <?php echo (($_POST['preferred_contact'] ?? 'phone') === 'phone') ? 'selected' : ''; ?>><?php echo isEnglish() ? 'Phone Call' : 'फोन'; ?></option>
                                <option value="email"  <?php echo (($_POST['preferred_contact'] ?? '') === 'email') ? 'selected' : ''; ?>><?php echo isEnglish() ? 'Email' : 'इमेल'; ?></option>
                                <option value="branch" <?php echo (($_POST['preferred_contact'] ?? '') === 'branch') ? 'selected' : ''; ?>><?php echo isEnglish() ? 'Visit Branch' : 'शाखा भ्रमण'; ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="d-grid mt-4">
                        <button type="submit" id="dsSubmitBtn" class="btn ds-btn-primary btn-lg">
                            <i class="fas fa-paper-plane me-2"></i>
                            <?php echo isEnglish() ? 'Submit Digital Service Request' : 'डिजिटल सेवा अनुरोध पेश गर्नुहोस्'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>


<!-- JS — service card → scroll to form + pre-select, conditional fields, copy tracking ID -->
<script>
(function () {
    var wasPosted  = <?php echo ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'true' : 'false'; ?>;
    var hasError   = <?php echo $error ? 'true' : 'false'; ?>;
    var preService = <?php echo json_encode($_POST['service_type'] ?? ''); ?>;

    var serviceEl  = document.getElementById('serviceType');
    var formSection= document.getElementById('ds-form-section');

    /* Conditional fields toggle — सेवा अनुसार extra fields देखाउने */
    function updateConditionalFields() {
        var type = serviceEl ? serviceEl.value : '';
        document.querySelectorAll('.conditional-fields').forEach(function(el) {
            el.style.display = 'none';
        });
        if (type === 'statement_request') {
            var el = document.querySelector('.statement-fields');
            if (el) el.style.display = 'block';
        }
        if (type === 'bill_payment') {
            var el = document.querySelector('.bill-fields');
            if (el) el.style.display = 'block';
        }
        if (type === 'mobile_recharge') {
            var el = document.querySelector('.recharge-fields');
            if (el) el.style.display = 'block';
        }
        if (type === 'share_refund' || type === 'share_increase') {
            var shareEl = document.querySelector('.share-fields');
            if (shareEl) shareEl.style.display = 'block';
        }
    }

    if (serviceEl) {
        serviceEl.addEventListener('change', updateConditionalFields);
        updateConditionalFields();
    }

    /* Service cards — click गर्दा form section मा scroll हुन्छ र service pre-select हुन्छ */
    document.querySelectorAll('.ds-service-card').forEach(function(card) {
        card.addEventListener('click', function() {
            var svc = this.dataset.service;
            if (serviceEl && svc) {
                serviceEl.value = svc;
                updateConditionalFields();
            }
            if (formSection) {
                formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    /* Submit button — disable to prevent double-submit */
    var form      = document.getElementById('digitalServiceForm');
    var submitBtn = document.getElementById('dsSubmitBtn');
    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> <?php echo isEnglish() ? 'Submitting...' : 'पेश गर्दै...'; ?>';
        });
    }

    /* Error पछि — form section मा scroll गर्नुहोस् */
    if (wasPosted && hasError && formSection) {
        setTimeout(function() {
            formSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);
    }

    /* Error पछि service type pre-select */
    if (wasPosted && serviceEl && preService) {
        serviceEl.value = preService;
        updateConditionalFields();
    }
})();

/* copyTrk() is defined globally in footer.php */
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
