<?php
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
ensurePublicTables();
/* Optional includes are guarded so partial deploy won't trigger HTTP 500. */
$kycPublicFormFile = __DIR__ . '/includes/kyc-public-form.php';
if (is_file($kycPublicFormFile)) {
    require_once $kycPublicFormFile;
} else {
    error_log('loan-apply: missing include file includes/kyc-public-form.php');
}
$pageTitle = isEnglish() ? 'Online Loan Application' : 'अनलाइन ऋण आवेदन';
require_once 'includes/header.php';
$L = getLangStrings();

$success = false;
$error = '';
$loanTrackingId = '';
$oldInput = [];
$loggedMember = getLoggedInMemberProfile();
$lockedMemberFields = $loggedMember ? 'readonly' : '';
$isEmbed = !empty($_GET['embed']);
$trackerUrl = $isEmbed ? (SITE_URL . 'member/tracker.php') : 'application-tracker.php';

// Fetch branches/service centers from database (after session start via header.php)
$branches = [];
$loanRates = [];
try {
    $db = getDB();
    $brStmt = $db->query("SELECT * FROM service_centers WHERE is_active = 1 ORDER BY is_main_branch DESC, display_order ASC, name ASC LIMIT 20");
    if ($brStmt) $branches = $brStmt->fetchAll() ?: [];
    $lrStmt = $db->query("SELECT * FROM interest_rates WHERE category = 'loan' AND is_active = 1 ORDER BY display_order ASC LIMIT 10");
    if ($lrStmt) $loanRates = $lrStmt->fetchAll() ?: [];
} catch (\Throwable $e) {
    $branches = [];
    $loanRates = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldInput = $_POST;
    // CSRF Protection
    if (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed. Please try again.' : 'सुरक्षा जाँच असफल। कृपया पुन: प्रयास गर्नुहोस्।';
    }
    // Rate Limiting
    elseif (!checkRateLimit('loan_form', 10, 3600)) {
        $error = isEnglish() ? 'Too many requests. Please try again after 1 hour.' : 'धेरै अनुरोधहरू भए। कृपया १ घण्टापछि पुनः प्रयास गर्नुहोस्।';
    }
    else {
        try {
            $db = getDB();

            // Applicant Information (DB: raw text — HTML escape output मा e() प्रयोग गर्नुहोस्)
            $full_name = clean_text($_POST['full_name'] ?? '', 200);
            $member_id = clean_text($_POST['member_id'] ?? '', 80);
            $mobile = preg_replace('/[^0-9]/', '', clean_text($_POST['mobile'] ?? '', 15));
            $email = strtolower(clean_text($_POST['email'] ?? '', 254));
            $address = clean_text($_POST['address'] ?? '', 2000);
            $citizenship_no = clean_text($_POST['citizenship_no'] ?? '', 80);

            // Loan Information
            $loan_type = clean_text($_POST['loan_type'] ?? '', 120);
            $loan_amount = preg_replace('/[^0-9.]/', '', clean_text($_POST['loan_amount'] ?? '', 24));
            $loan_purpose = clean_text($_POST['loan_purpose'] ?? '', 2000);
            $loan_tenure = clean_text($_POST['loan_tenure'] ?? '', 20);
            $repayment_method = clean_text($_POST['repayment_method'] ?? '', 40);

            // Employment/Income Information
            $occupation = clean_text($_POST['occupation'] ?? '', 80);
            $organization_name = clean_text($_POST['organization_name'] ?? '', 200);
            $monthly_income = preg_replace('/[^0-9.]/', '', clean_text($_POST['monthly_income'] ?? '', 20));
            $other_income = clean_text($_POST['other_income'] ?? '', 500);

            // Collateral Information
            $collateral_type = clean_text($_POST['collateral_type'] ?? '', 80);
            $collateral_description = clean_text($_POST['collateral_description'] ?? '', 2000);
            $collateral_value = preg_replace('/[^0-9.]/', '', clean_text($_POST['collateral_value'] ?? '', 24));

            // Guarantor Information
            $guarantor_name = clean_text($_POST['guarantor_name'] ?? '', 200);
            $guarantor_relation = clean_text($_POST['guarantor_relation'] ?? '', 120);
            $guarantor_phone = preg_replace('/[^0-9]/', '', clean_text($_POST['guarantor_phone'] ?? '', 15));
            $guarantor_address = clean_text($_POST['guarantor_address'] ?? '', 500);

            // Branch
            $branch = clean_text($_POST['branch'] ?? '', 200);

            /* सदस्य / अतिथि — KYC त्रिकोण (सदस्यता + इमेल + मोबाइल) सार्वजनिक सदस्यले "हो" भने अनिवार्य */
            $isCoopMember = $loggedMember ? 'yes' : ((($_POST['is_coop_member'] ?? '') === 'yes') ? 'yes' : 'no');
            $kycMerge = null;
            if ($loggedMember) {
                if (function_exists('loadKycRowForLoggedMemberPublic')) {
                    $kycMerge = loadKycRowForLoggedMemberPublic($db, $loggedMember);
                }
                $fnK = (is_array($kycMerge) && !empty($kycMerge['full_name'])) ? trim((string)$kycMerge['full_name']) : '';
                $full_name = $fnK !== '' ? $fnK : trim((string)($loggedMember['name'] ?? $full_name));
                $midCol = is_array($kycMerge)
                    ? trim((string)($kycMerge['member_id'] ?? $kycMerge['sadasyata_number'] ?? ''))
                    : '';
                $member_id = $midCol !== '' ? $midCol : trim((string)($loggedMember['sadasyata_number'] ?? $member_id));
                $mobile = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $loggedMember['phone'] ?? $mobile));
                $email = strtolower(trim((string)($kycMerge['email'] ?? $loggedMember['email'] ?? $email)));
                if ($kycMerge) {
                    if ($address === '') {
                        $pa = trim((string)($kycMerge['permanent_address'] ?? ''));
                        $ta = trim((string)($kycMerge['temporary_address'] ?? ''));
                        $address = $ta !== '' ? ($pa !== '' ? $pa . ' / ' . $ta : $ta) : $pa;
                    }
                    if ($citizenship_no === '') {
                        $citizenship_no = trim((string)($kycMerge['citizenship_no'] ?? ''));
                    }
                }
            } elseif ($isCoopMember === 'yes') {
                if (!function_exists('verifyPublicFormKycByMemberId')) {
                    $error = isEnglish()
                        ? 'KYC verification service is temporarily unavailable. Please try again.'
                        : 'KYC प्रमाणीकरण सेवा हाल उपलब्ध छैन। कृपया पुनः प्रयास गर्नुहोस्।';
                } else {
                    $v = verifyPublicFormKycByMemberId($db, $_POST['member_id'] ?? '');
                    if (!$v['ok']) {
                        $error = isEnglish() ? $v['msg_en'] : $v['msg_np'];
                    } else {
                        $kycMerge = $v['row'];
                        $full_name = trim((string)($kycMerge['full_name'] ?? ''));
                        $member_id = strtoupper(trim((string)($kycMerge['member_id'] ?? $kycMerge['sadasyata_number'] ?? $_POST['member_id'] ?? '')));
                        $mobile = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $_POST['mobile'] ?? ''));
                        $email = strtolower(trim((string)($kycMerge['email'] ?? $_POST['email'] ?? '')));
                        if ($address === '') {
                            $pa = trim((string)($kycMerge['permanent_address'] ?? ''));
                            $ta = trim((string)($kycMerge['temporary_address'] ?? ''));
                            $address = $ta !== '' ? ($pa !== '' ? $pa . ' / ' . $ta : $ta) : $pa;
                        }
                        if ($citizenship_no === '') {
                            $citizenship_no = trim((string)($kycMerge['citizenship_no'] ?? ''));
                        }
                    }
                }
            }

            /* -------------------------------------------------------
               Server-side validation — loan application
               नाम, मोबाइल (10 digits), इमेल, ऋण प्रकार र रकम required
            ------------------------------------------------------- */
            if (!$error && empty($full_name)) {
                $error = isEnglish() ? 'Please enter your full name.' : 'कृपया पूरा नाम भर्नुहोस्।';
            } elseif (!$error && empty($mobile)) {
                $error = isEnglish() ? 'Mobile number is required.' : 'मोबाइल नम्बर अनिवार्य छ।';
            } elseif (!$error && !preg_match('/^[0-9]{10}$/', $mobile)) {
                $error = isEnglish() ? 'Please enter a valid 10-digit mobile number.' : 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।';
            } elseif (!$error && $isCoopMember !== 'yes' && empty($email ?? '')) {
                $error = isEnglish() ? 'Email address is required.' : 'इमेल ठेगाना अनिवार्य छ।';
            } elseif (!$error && !empty($email ?? '') && !isValidEmail($email ?? '')) {
                $error = isEnglish() ? 'Please enter a valid email address.' : 'कृपया सही इमेल ठेगाना राख्नुहोस्।';
            } elseif (!$error && empty($loan_type)) {
                $error = isEnglish() ? 'Please select a loan type.' : 'कृपया ऋण प्रकार छान्नुहोस्।';
            } elseif (!$error && (empty($loan_amount) || (float) $loan_amount <= 0)) {
                $error = isEnglish() ? 'Please enter the loan amount.' : 'कृपया ऋण रकम भर्नुहोस्।';
            } elseif (!$error) {
                /* validation passed — proceed with file upload and DB insert */
                // Handle file uploads using the secure uploadFile() helper
                $documents = '';
                if (isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
                    $uploadedFiles = [];
                    foreach ($_FILES['documents']['name'] as $key => $name) {
                        if ($_FILES['documents']['error'][$key] === UPLOAD_ERR_OK) {
                            $singleFile = [
                                'name'     => $_FILES['documents']['name'][$key],
                                'type'     => $_FILES['documents']['type'][$key],
                                'tmp_name' => $_FILES['documents']['tmp_name'][$key],
                                'error'    => $_FILES['documents']['error'][$key],
                                'size'     => $_FILES['documents']['size'][$key],
                            ];
                            $result = uploadFile($singleFile, 'loan');
                            if ($result['success']) {
                                $uploadedFiles[] = $result['path'];
                            }
                        }
                    }
                    $documents = implode(',', $uploadedFiles);
                }

                // Generate tracking ID
                $loanTrackingId = 'LNP-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

                $stmt = $db->prepare("INSERT INTO loan_applications (
                    tracking_id,
                    full_name, member_id, mobile, email, address, citizenship_no,
                    loan_type, loan_amount, loan_purpose, loan_tenure, repayment_method,
                    occupation, organization_name, monthly_income, other_income,
                    collateral_type, collateral_description, collateral_value,
                    guarantor_name, guarantor_relation, guarantor_phone, guarantor_address,
                    branch, documents
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $stmt->execute([
                    $loanTrackingId,
                    $full_name, $member_id, $mobile, $email, $address, $citizenship_no,
                    $loan_type, $loan_amount, $loan_purpose, $loan_tenure, $repayment_method,
                    $occupation, $organization_name, $monthly_income, $other_income,
                    $collateral_type, $collateral_description, $collateral_value,
                    $guarantor_name, $guarantor_relation, $guarantor_phone, $guarantor_address,
                    $branch, $documents
                ]);

                $success = true;
                logSecurityEvent('loan_application', 'Loan application submitted by: ' . $full_name . ' (Tracking: ' . $loanTrackingId . ')');

                /* Notifications — guarded so missing/broken file does not 500 the form */
                $__nf = __DIR__ . '/includes/notifications.php';
                if (is_file($__nf)) { require_once $__nf; }
                unset($__nf);

                // Member confirmation SMS
                if (!empty($mobile)) {
                    try {
                        $smsToken = getSetting('notify_sms_token', '');
                        $smsSender = getSetting('notify_sms_sender_id', 'COOP');
                        if (getSetting('notify_sms_enabled', '0') === '1' && $smsToken) {
                            $smsTxt = 'आकाश सहकारी: तपाईंको ऋण आवेदन दर्ता भयो। Tracking ID: ' . $loanTrackingId . '. हाम्रो अधिकृत २-३ कार्यदिनभित्र सम्पर्क गर्नेछन्।';
                            $ph = preg_replace('/[^0-9]/', '', $mobile);
                            if (strlen($ph) >= 10) {
                                $ch = curl_init('http://api.sparrowsms.com/v2/sms/');
                                curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['token'=>$smsToken,'from'=>$smsSender,'to'=>$ph,'text'=>mb_substr($smsTxt,0,160)]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true]);
                                curl_exec($ch); curl_close($ch);
                            }
                        }
                    } catch (Exception $ignored) {}
                }

                /* Admin notification */
                sendAdminNotification('loan_application', [
                    'नाम'        => $full_name,
                    'फोन'        => $mobile,
                    'ऋण रकम'    => 'Rs. ' . number_format((float)$loan_amount),
                    'ऋण प्रकार'  => $loan_type,
                    'Tracking ID'=> $loanTrackingId,
                    'शाखा'       => $branch ?: 'N/A',
                    'मिति'       => date('Y-m-d H:i'),
                ], $loanTrackingId);
            }
        } catch (\Throwable $e) {
            error_log('loan-apply submit error: ' . $e->getMessage());
            $error = isEnglish() ? 'An error occurred. Please try again.' : 'त्रुटि भयो। कृपया पुन: प्रयास गर्नुहोस्।';
        }
    }
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Online Loan Application' : 'अनलाइन ऋण आवेदन'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Loan Application' : 'ऋण आवेदन'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- ═══════════════════════════════════════════════════════════════
     Loan Application — Multi-Step Card UI
═══════════════════════════════════════════════════════════════ -->
<section class="loan-form-section section-padding">
<div class="container">

<?php if ($success): ?>
<!-- ── Success State ── -->
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="form-success-card form-success-card--token text-center py-5 px-4 rounded-4 shadow-sm">
            <div class="form-success-icon"><i class="fas fa-check-circle"></i></div>
            <h3 class="mt-3 fw-bold text-success"><?php echo isEnglish() ? 'Loan Application Submitted!' : 'ऋण आवेदन सफलतापूर्वक पेश भयो!'; ?></h3>
            <p class="text-muted mb-3"><?php echo isEnglish() ? 'Our loan officer will contact you within 2-3 business days.' : 'हाम्रो ऋण अधिकृत २-३ कार्य दिनभित्र सम्पर्क गर्नेछन्।'; ?></p>
            <?php if ($loanTrackingId): ?>
            <div class="form-tracking-box">
                <div class="text-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID — save this!' : 'तपाईंको Tracking ID — सुरक्षित राख्नुहोस्!'; ?></div>
                <div class="d-flex align-items-center gap-2 justify-content-center mb-2">
                    <div class="form-tracking-id" id="loanTrkId"><?php echo e($loanTrackingId); ?></div>
                    <button type="button" onclick="copyTrk('loanTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2" title="Copy"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
                </div>
                <div class="form-tracking-help"><a href="<?php echo e($trackerUrl); ?>" class="text-success text-decoration-none fw-semibold"><?php echo isEnglish() ? 'Click here' : 'यहाँ बाट'; ?></a> <?php echo isEnglish() ? 'to check status in Tracker.' : 'Tracker मा स्थिति हेर्नुहोस्।'; ?></div>
            </div>
            <?php endif; ?>
            <div class="mt-4 d-flex flex-wrap gap-2 justify-content-center">
                <a href="<?php echo e($trackerUrl); ?>" class="btn btn-success px-4"><i class="fas fa-search me-1"></i><?php echo isEnglish() ? 'Track Application' : 'आवेदन ट्र्याक'; ?></a>
                <a href="emi-calculator.php" class="btn btn-outline-success px-4"><i class="fas fa-calculator me-1"></i>EMI Calculator</a>
                <a href="loan-apply.php" class="btn btn-outline-secondary px-4"><i class="fas fa-plus me-1"></i><?php echo isEnglish() ? 'New Application' : 'नयाँ आवेदन'; ?></a>
            </div>
        </div>
    </div>
</div>

<?php else: ?>

<?php if ($error): ?>
<script>document.addEventListener('DOMContentLoaded',function(){var e=document.querySelector('.alert-danger');if(e)e.scrollIntoView({behavior:'smooth',block:'center'});});</script>
<div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($error,ENT_QUOTES,'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-10">

<!-- ── Step Progress Bar ── -->
<div class="loan-wizard-bar mb-4" id="loanWizardBar">
    <div class="loan-wiz-step active" data-step="1">
        <div class="loan-wiz-circle"><i class="fas fa-user"></i></div>
        <div class="loan-wiz-label"><?php echo isEnglish() ? 'Applicant' : 'आवेदक'; ?></div>
    </div>
    <div class="loan-wiz-connector"></div>
    <div class="loan-wiz-step" data-step="2">
        <div class="loan-wiz-circle"><i class="fas fa-money-bill-wave"></i></div>
        <div class="loan-wiz-label"><?php echo isEnglish() ? 'Loan Details' : 'ऋण विवरण'; ?></div>
    </div>
    <div class="loan-wiz-connector"></div>
    <div class="loan-wiz-step" data-step="3">
        <div class="loan-wiz-circle"><i class="fas fa-briefcase"></i></div>
        <div class="loan-wiz-label"><?php echo isEnglish() ? 'Income / Security' : 'आय / धितो'; ?></div>
    </div>
    <div class="loan-wiz-connector"></div>
    <div class="loan-wiz-step" data-step="4">
        <div class="loan-wiz-circle"><i class="fas fa-file-upload"></i></div>
        <div class="loan-wiz-label"><?php echo isEnglish() ? 'Documents' : 'कागजात'; ?></div>
    </div>
</div>

<!-- ── Wizard Form ── -->
<div class="loan-form-box" data-aos="fade-up">
<form method="POST" enctype="multipart/form-data" class="loan-form needs-validation" id="loanApplyForm" novalidate>
<?php echo csrfField(); ?>

<!-- ════════════════════ STEP 1: APPLICANT ════════════════════ -->
<div class="loan-step-pane" id="loanPane1">

    <?php if ($loggedMember):
        $kycForDisplay = isset($kycMerge) ? $kycMerge : null;
        if (!$kycForDisplay) {
            try { $kycForDisplay = loadKycRowForLoggedMemberPublic(getDB(), $loggedMember); } catch(Throwable $e) {}
        }
        require ROOT_PATH . 'includes/member-prefill-block.php';
    else: ?>
    <!-- Member check -->
    <div class="form-section-card mb-3">
        <div class="form-section-card-hdr">
            <span class="form-section-icon bg-primary-soft"><i class="fas fa-users"></i></span>
            <span class="fw-semibold"><?php echo isEnglish() ? 'Are you an existing cooperative member?' : 'तपाईं सहकारी सदस्य हुनुहुन्छ?'; ?></span>
        </div>
        <div class="d-flex flex-wrap gap-3 mt-2">
            <label class="form-check-label d-flex align-items-center gap-2 px-3 py-2 border rounded-3 cursor-pointer" style="cursor:pointer;">
                <input type="radio" name="is_coop_member" value="no" class="form-check-input js-coop-member" <?php echo (($_POST['is_coop_member'] ?? 'no') === 'yes') ? '' : 'checked'; ?>>
                <span><?php echo isEnglish() ? 'No — guest applicant' : 'होइन (साधारण आवेदक)'; ?></span>
            </label>
            <label class="form-check-label d-flex align-items-center gap-2 px-3 py-2 border rounded-3" style="cursor:pointer;">
                <input type="radio" name="is_coop_member" value="yes" class="form-check-input js-coop-member" <?php echo (($_POST['is_coop_member'] ?? '') === 'yes') ? 'checked' : ''; ?>>
                <span><?php echo isEnglish() ? 'Yes — verify with Member ID + email + mobile' : 'हो — सदस्यता नम्बर + इमेल + मोबाइल मिलाउनुहोस्'; ?></span>
            </label>
        </div>
        <p class="text-muted small mt-2 mb-0"><i class="fas fa-info-circle me-1"></i><?php echo isEnglish() ? 'If Yes, personal details will be loaded from KYC after matching ID, email and phone.' : '"हो" भए KYC मा भएको तीन विवरण मिलाएपछि नाम/ठेगाना KYC बाटै लिइन्छ।'; ?></p>
    </div>
    <?php endif; ?>

    <!-- Applicant details card -->
    <?php if (!$loggedMember): ?>
    <div class="form-section-card">
        <div class="form-section-card-hdr mb-3">
            <span class="form-section-icon bg-primary-soft"><i class="fas fa-id-card"></i></span>
            <span class="fw-bold"><?php echo isEnglish() ? 'Personal Information' : 'व्यक्तिगत जानकारी'; ?></span>
        </div>
        <div class="row g-3">
            <div class="col-md-6 js-loan-fullname-wrap">
                <label class="form-label"><?php echo isEnglish() ? 'Full Name' : 'पूरा नाम'; ?> <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control js-loan-personal" required
                    value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
                    placeholder="<?php echo isEnglish() ? 'Your full name' : 'पूरा नाम लेख्नुहोस्'; ?>">
                <div class="invalid-feedback"><?php echo isEnglish() ? 'Full name is required.' : 'पूरा नाम अनिवार्य छ।'; ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">
                    <?php echo isEnglish() ? 'Member ID' : 'सदस्यता नम्बर'; ?>
                    <span class="text-danger js-mid-req" style="display:none;">*</span>
                </label>
                <input type="text" name="member_id" class="form-control js-loan-mid"
                    value="<?php echo htmlspecialchars($_POST['member_id'] ?? ''); ?>"
                    placeholder="MEM-XXXX">
                <div class="invalid-feedback"><?php echo isEnglish() ? 'Member ID is required for verification.' : 'सदस्यता नम्बर अनिवार्य छ।'; ?></div>
            </div>
            <div class="col-md-4 js-hide-if-coop-yes">
                <label class="form-label"><?php echo isEnglish() ? 'Mobile Number' : 'मोबाइल नम्बर'; ?> <span class="text-danger">*</span></label>
                <input type="tel" name="mobile" class="form-control js-loan-personal" maxlength="15" required
                    placeholder="98XXXXXXXX" value="<?php echo htmlspecialchars($_POST['mobile'] ?? ''); ?>">
                <div class="invalid-feedback"><?php echo isEnglish() ? 'Valid 10-digit mobile required.' : '१० अंकको मोबाइल नम्बर अनिवार्य।'; ?></div>
            </div>
            <div class="col-md-4 js-hide-if-coop-yes">
                <label class="form-label"><?php echo isEnglish() ? 'Email Address' : 'इमेल ठेगाना'; ?> <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control js-loan-personal" required
                    placeholder="you@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                <div class="invalid-feedback"><?php echo isEnglish() ? 'Valid email address required.' : 'सही इमेल ठेगाना अनिवार्य।'; ?></div>
            </div>
            <div class="col-md-4 js-hide-if-coop-yes">
                <label class="form-label"><?php echo isEnglish() ? 'Citizenship No.' : 'नागरिकता नम्बर'; ?></label>
                <input type="text" name="citizenship_no" class="form-control"
                    value="<?php echo htmlspecialchars($_POST['citizenship_no'] ?? ''); ?>"
                    placeholder="XX-XX-XXXXX">
            </div>
            <div class="col-12 js-hide-if-coop-yes">
                <label class="form-label"><?php echo isEnglish() ? 'Permanent Address' : 'स्थायी ठेगाना'; ?></label>
                <textarea name="address" class="form-control js-loan-personal" rows="2"
                    placeholder="<?php echo isEnglish() ? 'District / VDC / Ward' : 'जिल्ला / गाउँपालिका / वडा'; ?>"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>
    <?php endif; /* !$loggedMember */ ?>
    <script>
    (function(){
        function syncLoanMemberUi(){
            var yes=document.querySelector('#loanApplyForm input.js-coop-member[value="yes"]:checked');
            var full=document.querySelector('#loanApplyForm .js-loan-fullname-wrap');
            var mids=document.querySelector('#loanApplyForm .js-mid-req');
            var mid=document.querySelector('#loanApplyForm input.js-loan-mid');
            var pers=document.querySelectorAll('#loanApplyForm .js-loan-personal');
            document.querySelectorAll('#loanApplyForm .js-hide-if-coop-yes').forEach(function(el){ el.style.display=yes?'none':''; });
            if(yes){
                if(full) full.style.display='none';
                pers.forEach(function(el){ el.removeAttribute('required'); });
                if(mid){ mid.setAttribute('required','required'); if(mids) mids.style.display=''; }
            } else {
                if(full) full.style.display='';
                pers.forEach(function(el){ if(el.name==='full_name'||el.name==='mobile'||el.name==='email') el.setAttribute('required','required'); });
                if(mid){ mid.removeAttribute('required'); if(mids) mids.style.display='none'; }
            }
        }
        document.querySelectorAll('#loanApplyForm input.js-coop-member').forEach(function(r){ r.addEventListener('change',syncLoanMemberUi); });
        document.addEventListener('DOMContentLoaded',syncLoanMemberUi);
    })();
    </script>
</div><!-- /loanPane1 -->

<!-- ════════════════════ STEP 2: LOAN DETAILS ════════════════════ -->
<div class="loan-step-pane" id="loanPane2" style="display:none;">
    <div class="form-section-card">
        <div class="form-section-card-hdr mb-3">
            <span class="form-section-icon bg-success-soft"><i class="fas fa-money-bill-wave"></i></span>
            <span class="fw-bold"><?php echo isEnglish() ? 'Loan Information' : 'ऋण जानकारी'; ?></span>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label"><?php echo isEnglish() ? 'Loan Type' : 'ऋणको प्रकार'; ?> <span class="text-danger">*</span></label>
                <select name="loan_type" class="form-select" required>
                    <option value=""><?php echo isEnglish() ? 'Select Loan Type' : 'ऋणको प्रकार छान्नुहोस्'; ?></option>
                    <?php foreach ($loanRates as $lr): ?>
                    <option value="<?php echo htmlspecialchars($lr['name_np'] ?: $lr['name']); ?>"><?php echo htmlspecialchars($lr['name_np'] ?: $lr['name']); ?> (<?php echo number_format($lr['rate'],2); ?>%)</option>
                    <?php endforeach; ?>
                    <option value="व्यापार ऋण"><?php echo isEnglish() ? 'Business Loan' : 'व्यापार ऋण'; ?></option>
                    <option value="घर ऋण"><?php echo isEnglish() ? 'Home Loan' : 'घर ऋण'; ?></option>
                    <option value="शिक्षा ऋण"><?php echo isEnglish() ? 'Education Loan' : 'शिक्षा ऋण'; ?></option>
                    <option value="वाहन ऋण"><?php echo isEnglish() ? 'Vehicle Loan' : 'वाहन ऋण'; ?></option>
                    <option value="व्यक्तिगत ऋण"><?php echo isEnglish() ? 'Personal Loan' : 'व्यक्तिगत ऋण'; ?></option>
                    <option value="कृषि ऋण"><?php echo isEnglish() ? 'Agriculture Loan' : 'कृषि ऋण'; ?></option>
                </select>
                <div class="invalid-feedback"><?php echo isEnglish() ? 'Please select a loan type.' : 'ऋणको प्रकार छान्नुहोस्।'; ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo isEnglish() ? 'Loan Amount (Rs.)' : 'ऋण रकम (रु.)'; ?> <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text fw-semibold">रु.</span>
                    <input type="number" name="loan_amount" class="form-control" required min="1000" step="1000"
                        value="<?php echo htmlspecialchars($_POST['loan_amount'] ?? ''); ?>"
                        placeholder="5,00,000">
                </div>
                <div class="invalid-feedback"><?php echo isEnglish() ? 'Please enter the loan amount.' : 'ऋण रकम अनिवार्य छ।'; ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo isEnglish() ? 'Loan Tenure (Months)' : 'ऋण अवधि (महिना)'; ?></label>
                <select name="loan_tenure" class="form-select">
                    <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                    <?php foreach ([12,24,36,48,60,84,120] as $m): ?>
                    <option value="<?php echo $m; ?>" <?php echo ($_POST['loan_tenure'] ?? '') == $m ? 'selected' : ''; ?>><?php echo $m; ?> <?php echo isEnglish() ? 'Months' : 'महिना'; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo isEnglish() ? 'Repayment Method' : 'भुक्तानी विधि'; ?></label>
                <select name="repayment_method" class="form-select">
                    <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                    <option value="emi" <?php echo ($_POST['repayment_method'] ?? '') === 'emi' ? 'selected' : ''; ?>><?php echo isEnglish() ? 'EMI (Monthly Installment)' : 'ईएमआई (मासिक किस्ता)'; ?></option>
                    <option value="quarterly" <?php echo ($_POST['repayment_method'] ?? '') === 'quarterly' ? 'selected' : ''; ?>><?php echo isEnglish() ? 'Quarterly' : 'त्रैमासिक'; ?></option>
                    <option value="bullet" <?php echo ($_POST['repayment_method'] ?? '') === 'bullet' ? 'selected' : ''; ?>><?php echo isEnglish() ? 'Bullet Payment' : 'एकमुष्ट भुक्तानी'; ?></option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label"><?php echo isEnglish() ? 'Loan Purpose' : 'ऋणको उद्देश्य'; ?></label>
                <textarea name="loan_purpose" class="form-control" rows="3"
                    placeholder="<?php echo isEnglish() ? 'Briefly describe the purpose of the loan...' : 'ऋण किन चाहिएको छ संक्षिप्तमा बताउनुहोस्...'; ?>"><?php echo htmlspecialchars($_POST['loan_purpose'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    <!-- EMI Calculator hint -->
    <div class="d-flex align-items-center gap-2 mt-2 text-muted small">
        <i class="fas fa-calculator text-primary"></i>
        <?php echo isEnglish() ? 'Estimate your monthly installment:' : 'मासिक किस्ता अनुमान गर्नुहोस्:'; ?>
        <a href="emi-calculator.php" target="_blank" class="text-decoration-none fw-semibold" style="color:var(--primary-color);">EMI Calculator <i class="fas fa-arrow-up-right-from-square fa-xs"></i></a>
    </div>
</div><!-- /loanPane2 -->

<!-- ════════════════════ STEP 3: INCOME / COLLATERAL / GUARANTOR ════════════════════ -->
<div class="loan-step-pane" id="loanPane3" style="display:none;">

    <!-- Income -->
    <div class="form-section-card mb-3">
        <div class="form-section-card-hdr mb-3">
            <span class="form-section-icon bg-warning-soft"><i class="fas fa-briefcase"></i></span>
            <span class="fw-bold"><?php echo isEnglish() ? 'Employment & Income' : 'रोजगार र आय'; ?></span>
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label"><?php echo isEnglish() ? 'Occupation' : 'पेशा'; ?></label>
                <select name="occupation" class="form-select">
                    <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                    <?php foreach ([
                        ['government', isEnglish() ? 'Government Job' : 'सरकारी नोकरी'],
                        ['private',    isEnglish() ? 'Private Job' : 'निजी नोकरी'],
                        ['business',   isEnglish() ? 'Business' : 'व्यापार/व्यवसाय'],
                        ['agriculture',isEnglish() ? 'Agriculture' : 'कृषि'],
                        ['foreign',    isEnglish() ? 'Foreign Employment' : 'वैदेशिक रोजगार'],
                        ['other',      isEnglish() ? 'Other' : 'अन्य'],
                    ] as [$val,$lbl]): ?>
                    <option value="<?php echo $val; ?>" <?php echo ($_POST['occupation'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo isEnglish() ? 'Organization / Business Name' : 'संस्था / व्यवसायको नाम'; ?></label>
                <input type="text" name="organization_name" class="form-control"
                    value="<?php echo htmlspecialchars($_POST['organization_name'] ?? ''); ?>"
                    placeholder="<?php echo isEnglish() ? 'Name of employer or business' : 'संस्था वा व्यापारको नाम'; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo isEnglish() ? 'Monthly Income (Rs.)' : 'मासिक आय (रु.)'; ?></label>
                <div class="input-group">
                    <span class="input-group-text">रु.</span>
                    <input type="number" name="monthly_income" class="form-control" min="0"
                        value="<?php echo htmlspecialchars($_POST['monthly_income'] ?? ''); ?>">
                </div>
            </div>
            <div class="col-12">
                <label class="form-label"><?php echo isEnglish() ? 'Other Income Sources' : 'अन्य आयको स्रोत'; ?></label>
                <textarea name="other_income" class="form-control" rows="2"
                    placeholder="<?php echo isEnglish() ? 'Rental income, remittance, etc.' : 'भाडा, रेमिट्यान्स, कृषि आदि'; ?>"><?php echo htmlspecialchars($_POST['other_income'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    <!-- Collateral -->
    <div class="form-section-card mb-3">
        <div class="form-section-card-hdr mb-3">
            <span class="form-section-icon bg-info-soft"><i class="fas fa-home"></i></span>
            <span class="fw-bold"><?php echo isEnglish() ? 'Collateral / Security' : 'धितो / जमानत'; ?></span>
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label"><?php echo isEnglish() ? 'Collateral Type' : 'धितोको प्रकार'; ?></label>
                <select name="collateral_type" class="form-select">
                    <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                    <?php foreach ([
                        ['land',      isEnglish() ? 'Land'               : 'जग्गा'],
                        ['house',     isEnglish() ? 'House/Building'      : 'घर/भवन'],
                        ['vehicle',   isEnglish() ? 'Vehicle'             : 'सवारी साधन'],
                        ['fd',        isEnglish() ? 'Fixed Deposit'       : 'मुद्दती निक्षेप'],
                        ['gold',      isEnglish() ? 'Gold/Jewelry'        : 'सुन/गहना'],
                        ['share',     isEnglish() ? 'Share Certificate'   : 'शेयर प्रमाणपत्र'],
                        ['guarantor', isEnglish() ? 'Personal Guarantee'  : 'व्यक्तिगत जमानत'],
                    ] as [$val,$lbl]): ?>
                    <option value="<?php echo $val; ?>" <?php echo ($_POST['collateral_type'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo isEnglish() ? 'Estimated Value (Rs.)' : 'अनुमानित मूल्य (रु.)'; ?></label>
                <div class="input-group">
                    <span class="input-group-text">रु.</span>
                    <input type="number" name="collateral_value" class="form-control" min="0"
                        value="<?php echo htmlspecialchars($_POST['collateral_value'] ?? ''); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo isEnglish() ? 'Description / Location' : 'विवरण / स्थान'; ?></label>
                <input type="text" name="collateral_description" class="form-control"
                    value="<?php echo htmlspecialchars($_POST['collateral_description'] ?? ''); ?>"
                    placeholder="<?php echo isEnglish() ? 'e.g., Plot no, address' : 'जस्तै: कित्ता नं, ठेगाना'; ?>">
            </div>
        </div>
    </div>

    <!-- Guarantor -->
    <div class="form-section-card">
        <div class="form-section-card-hdr mb-3">
            <span class="form-section-icon bg-secondary-soft"><i class="fas fa-user-shield"></i></span>
            <span class="fw-bold"><?php echo isEnglish() ? 'Guarantor Information' : 'धनजमानीको जानकारी'; ?></span>
            <span class="badge bg-light text-muted ms-auto fw-normal"><?php echo isEnglish() ? 'Optional' : 'ऐच्छिक'; ?></span>
        </div>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label"><?php echo isEnglish() ? 'Guarantor Name' : 'धनजमानीको नाम'; ?></label>
                <input type="text" name="guarantor_name" class="form-control"
                    value="<?php echo htmlspecialchars($_POST['guarantor_name'] ?? ''); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo isEnglish() ? 'Relationship' : 'सम्बन्ध'; ?></label>
                <input type="text" name="guarantor_relation" class="form-control"
                    value="<?php echo htmlspecialchars($_POST['guarantor_relation'] ?? ''); ?>"
                    placeholder="<?php echo isEnglish() ? 'e.g. Father, Spouse, Friend' : 'जस्तै: बाबा, श्रीमान/श्रीमती'; ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo isEnglish() ? 'Phone Number' : 'फोन नम्बर'; ?></label>
                <input type="tel" name="guarantor_phone" class="form-control"
                    value="<?php echo htmlspecialchars($_POST['guarantor_phone'] ?? ''); ?>"
                    placeholder="98XXXXXXXX">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?></label>
                <input type="text" name="guarantor_address" class="form-control"
                    value="<?php echo htmlspecialchars($_POST['guarantor_address'] ?? ''); ?>">
            </div>
        </div>
    </div>
</div><!-- /loanPane3 -->

<!-- ════════════════════ STEP 4: DOCUMENTS + BRANCH ════════════════════ -->
<div class="loan-step-pane" id="loanPane4" style="display:none;">

    <!-- Branch -->
    <div class="form-section-card mb-3">
        <div class="form-section-card-hdr mb-3">
            <span class="form-section-icon bg-primary-soft"><i class="fas fa-building"></i></span>
            <span class="fw-bold"><?php echo isEnglish() ? 'Preferred Branch / Service Center' : 'मनपर्ने शाखा / सेवा केन्द्र'; ?></span>
        </div>
        <select name="branch" class="form-select">
            <option value=""><?php echo isEnglish() ? 'Select Branch' : 'शाखा छान्नुहोस्'; ?></option>
            <?php if (!empty($branches)): ?>
                <?php foreach ($branches as $brn): ?>
                <option value="<?php echo htmlspecialchars($brn['name_np'] ?: $brn['name']); ?>" <?php echo ($_POST['branch'] ?? '') === ($brn['name_np'] ?: $brn['name']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($brn['name_np'] ?: $brn['name']); ?><?php if ($brn['is_main_branch']): ?> (<?php echo isEnglish() ? 'Main' : 'प्रधान'; ?>)<?php endif; ?><?php if ($brn['address']): ?> — <?php echo htmlspecialchars($brn['address']); ?><?php endif; ?>
                </option>
                <?php endforeach; ?>
            <?php else: ?>
                <option value="प्रधान कार्यालय"><?php echo isEnglish() ? 'Main Office' : 'प्रधान कार्यालय'; ?></option>
            <?php endif; ?>
        </select>
    </div>

    <!-- Documents upload -->
    <div class="form-section-card mb-3">
        <div class="form-section-card-hdr mb-3">
            <span class="form-section-icon bg-warning-soft"><i class="fas fa-paperclip"></i></span>
            <span class="fw-bold"><?php echo isEnglish() ? 'Supporting Documents' : 'सहयोगी कागजातहरू'; ?></span>
            <span class="badge bg-light text-muted ms-auto fw-normal"><?php echo isEnglish() ? 'Optional' : 'ऐच्छिक'; ?></span>
        </div>
        <div class="document-upload text-center py-4 border rounded-3 bg-light" id="docDropZone" style="border-style:dashed!important;">
            <i class="fas fa-cloud-upload-alt fa-3x mb-3" style="color:var(--primary-color);opacity:.6;"></i>
            <p class="mb-1 fw-semibold"><?php echo isEnglish() ? 'Click to upload or drag & drop' : 'Click गर्नुहोस् वा तान्नुहोस्'; ?></p>
            <p class="text-muted small mb-2"><?php echo isEnglish() ? 'Citizenship, income proof, collateral documents' : 'नागरिकता, आय प्रमाण, धितो कागजातहरू'; ?></p>
            <input type="file" name="documents[]" id="docFileInput" class="d-none" multiple accept=".pdf,.jpg,.jpeg,.png,image/*">
            <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="document.getElementById('docFileInput').click();">
                <i class="fas fa-folder-open me-1"></i><?php echo isEnglish() ? 'Choose Files' : 'फाइल छान्नुहोस्'; ?>
            </button>
            <div id="docFileList" class="mt-3 text-start small"></div>
            <p class="text-muted small mt-2 mb-0"><?php echo isEnglish() ? 'Max 5MB per file | PDF/JPG/PNG' : 'प्रति फाइल अधिकतम 5MB | PDF/JPG/PNG'; ?></p>
        </div>
    </div>
</div><!-- /loanPane4 -->

<div class="d-flex align-items-center justify-content-between mt-4 loan-wiz-nav">
    <button type="button" class="btn btn-outline-secondary px-4" id="loanPrevBtn">
        <i class="fas fa-arrow-left me-1"></i><?php echo isEnglish() ? 'Previous' : 'अघिल्लो'; ?>
    </button>
    <div id="loanStepLabel" class="small text-muted fw-semibold"><?php echo isEnglish() ? 'Step 1 of 4' : 'चरण 1 / 4'; ?></div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary px-4" id="loanNextBtn">
            <?php echo isEnglish() ? 'Next' : 'अर्को'; ?> <i class="fas fa-arrow-right ms-1"></i>
        </button>
        <button type="submit" class="btn btn-success px-4" id="loanSubmitBtn" style="display:none;">
            <i class="fas fa-paper-plane me-1"></i><?php echo isEnglish() ? 'Submit Application' : 'आवेदन पेश गर्नुहोस्'; ?>
        </button>
    </div>
</div>

</form>
</div><!-- /loan-form-box -->
</div><!-- /col -->
</div><!-- /row -->
</div><!-- /container -->
</section><!-- /loan-form-section -->


<script>
(function(){
    var panes  = document.querySelectorAll('.loan-step-pane');
    var steps  = document.querySelectorAll('#loanWizardBar .loan-wiz-step');
    var conns  = document.querySelectorAll('#loanWizardBar .loan-wiz-connector');
    var prevBtn= document.getElementById('loanPrevBtn');
    var nextBtn= document.getElementById('loanNextBtn');
    var subBtn = document.getElementById('loanSubmitBtn');
    var stepLbl= document.getElementById('loanStepLabel');
    var cur    = 0;
    var total  = panes.length;
    var hasError = !!document.querySelector('.alert-danger');
    var labels  = <?php echo json_encode(isEnglish()
        ? ['Step','of'] : ['चरण','/']); ?>;

    function showStep(idx){
        panes.forEach(function(p,i){ p.style.display = i===idx ? '' : 'none'; });
        steps.forEach(function(s,i){
            s.classList.toggle('active', i===idx);
            s.classList.toggle('done',   i<idx);
        });
        conns.forEach(function(c,i){ c.classList.toggle('done', i<idx); });
        prevBtn.style.display = idx===0 ? 'none' : '';
        nextBtn.style.display = idx===total-1 ? 'none' : '';
        subBtn.style.display  = idx===total-1 ? '' : 'none';
        if(stepLbl) stepLbl.textContent = labels[0]+' '+(idx+1)+' '+labels[1]+' '+total;
        cur = idx;
        window.scrollTo({top: document.getElementById('loanWizardBar').offsetTop - 80, behavior:'smooth'});
    }

    function validatePane(idx){
        var pane  = panes[idx];
        var valid = true;
        pane.querySelectorAll('[required]').forEach(function(inp){
            if(!inp.checkValidity()){
                inp.classList.add('is-invalid');
                valid = false;
            } else {
                inp.classList.remove('is-invalid');
                inp.classList.add('is-valid');
            }
        });
        if(!valid){
            var first = pane.querySelector('.is-invalid');
            if(first) first.focus();
        }
        return valid;
    }

    if(hasError){
        // Server error — show all panes so user can see what needs fixing
        panes.forEach(function(p){ p.style.display = ''; });
        nextBtn.style.display = 'none';
        prevBtn.style.display = 'none';
        subBtn.style.display  = '';
        steps.forEach(function(s){ s.classList.add('done'); });
        conns.forEach(function(c){ c.classList.add('done'); });
    } else {
        showStep(0);
    }

    nextBtn.addEventListener('click', function(){ if(validatePane(cur)) showStep(cur+1); });
    prevBtn.addEventListener('click', function(){ if(cur>0) showStep(cur-1); });

    // File drop zone
    var dropZone = document.getElementById('docDropZone');
    var fileInput= document.getElementById('docFileInput');
    var fileList = document.getElementById('docFileList');
    if(dropZone && fileInput){
        ['dragenter','dragover'].forEach(function(ev){
            dropZone.addEventListener(ev,function(e){ e.preventDefault(); dropZone.style.borderColor='var(--primary-color)'; });
        });
        ['dragleave','drop'].forEach(function(ev){
            dropZone.addEventListener(ev,function(e){ e.preventDefault(); dropZone.style.borderColor=''; });
        });
        dropZone.addEventListener('drop',function(e){
            fileInput.files = e.dataTransfer.files;
            renderFileList(e.dataTransfer.files);
        });
        fileInput.addEventListener('change',function(){ renderFileList(this.files); });
    }
    function renderFileList(files){
        if(!fileList) return;
        fileList.innerHTML = '';
        if(!files || !files.length) return;
        for(var i=0;i<files.length;i++){
            var sz = (files[i].size/1024/1024).toFixed(2);
            var ok = files[i].size <= 5*1024*1024;
            fileList.innerHTML += '<div class="d-flex align-items-center gap-1 py-1">'
                +'<i class="fas fa-file-alt '+(ok?'text-success':'text-danger')+'"></i>'
                +'<span class="'+(ok?'':'text-danger')+'">'
                +files[i].name+' <em class="text-muted">('+sz+' MB)</em></span></div>';
        }
    }
})();
</script>
<?php endif; ?>

<?php if (!$success && !empty($oldInput)): ?>
<script>
(function () {
    var old = <?php echo json_encode($oldInput, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    if (!old || typeof old !== 'object') return;
    Object.keys(old).forEach(function (key) {
        var val = old[key];
        var nodes = document.querySelectorAll('[name="' + key.replace(/"/g, '\\"') + '"]');
        if (!nodes.length) return;
        nodes.forEach(function (el) {
            var type = (el.type || '').toLowerCase();
            var tag = (el.tagName || '').toLowerCase();
            if (type === 'file') return;
            if (type === 'checkbox') {
                el.checked = Array.isArray(val) ? val.indexOf(el.value) !== -1 : (String(val) === '1' || String(val).toLowerCase() === 'on' || String(val).toLowerCase() === 'yes');
                return;
            }
            if (type === 'radio') { el.checked = String(el.value) === String(val); return; }
            if (tag === 'select') { el.value = String(val); return; }
            el.value = String(val ?? '');
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
