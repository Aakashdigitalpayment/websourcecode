<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
require_once 'includes/ensure-tables.php';
ensurePublicTables();
$_kycFile=__DIR__.'/includes/kyc-public-form.php'; if(is_file($_kycFile)){require_once $_kycFile;} unset($_kycFile);
$pageTitle = isEnglish() ? 'Online Account Opening' : 'अनलाइन खाता खोल्नुहोस्';
require_once 'includes/header.php';
$L = getLangStrings();

$success = false;
$error = '';
$accTrackingId = '';
$oldInput = [];
$loggedMember = getLoggedInMemberProfile();
$isEmbed = !empty($_GET['embed']);
$trackerUrl = $isEmbed ? (SITE_URL . 'member/tracker.php') : 'application-tracker.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldInput = $_POST;
    if (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed.' : 'सुरक्षा जाँच असफल।';
    } elseif (!checkRateLimit('account_open', 10, 3600)) {
        $error = isEnglish() ? 'Too many requests. Please try again after 1 hour.' : 'धेरै अनुरोधहरू। कृपया १ घण्टापछि पुनः प्रयास गर्नुहोस्।';
    } else {
        $account_type = clean_text($_POST['account_type'] ?? '', 40);
        $full_name = clean_text($_POST['full_name'] ?? '', 200);
        $full_name_en = clean_text($_POST['full_name_en'] ?? '', 200);
        $dob_bs = clean_text($_POST['dob_bs'] ?? '', 40);
        $dob_ad = clean_text($_POST['dob_ad'] ?? '', 40);
        $dob_ad = ($dob_ad === '' ? null : $dob_ad);
        $gender = clean_text($_POST['gender'] ?? '', 30);
        $marital_status = clean_text($_POST['marital_status'] ?? '', 30);
        $mobile = preg_replace('/[^0-9]/', '', clean_text($_POST['mobile'] ?? '', 15));
        $email = strtolower(clean_text($_POST['email'] ?? '', 254));
        $permanent_address = clean_text($_POST['permanent_address'] ?? '', 2000);
        $temporary_address = clean_text($_POST['temporary_address'] ?? '', 2000);
        $citizenship_no = clean_text($_POST['citizenship_no'] ?? '', 80);
        $citizenship_issued_date = clean_text($_POST['citizenship_issued_date'] ?? '', 40);
        $citizenship_issued_place = clean_text($_POST['citizenship_issued_place'] ?? '', 200);
        $father_name = clean_text($_POST['father_name'] ?? '', 200);
        $mother_name = clean_text($_POST['mother_name'] ?? '', 200);
        $occupation = clean_text($_POST['occupation'] ?? '', 120);
        $monthly_income = preg_replace('/[^0-9.]/', '', clean_text($_POST['monthly_income'] ?? '', 24));
        $initial_deposit = preg_replace('/[^0-9.]/', '', clean_text($_POST['initial_deposit'] ?? '', 24));
        $nominee_name = clean_text($_POST['nominee_name'] ?? '', 200);
        $nominee_relation = clean_text($_POST['nominee_relation'] ?? '', 120);
        $nominee_phone = preg_replace('/[^0-9]/', '', clean_text($_POST['nominee_phone'] ?? '', 15));
        $branch = clean_text($_POST['branch'] ?? '', 200);

        $db = null;
        try {
            $db = getDB();
        } catch (Throwable $e) {
            error_log('online-account: getDB failed: ' . $e->getMessage());
            $error = isEnglish()
                ? 'Database connection failed. Please try again later.'
                : 'डेटाबेस जडान असफल भयो। कृपया पछि पुनः प्रयास गर्नुहोस्।';
        }
        $isCoopMember = $loggedMember ? 'yes' : ((($_POST['is_coop_member'] ?? '') === 'yes') ? 'yes' : 'no');
        $useKycIdentity = ($loggedMember || $isCoopMember === 'yes');
        $kycMerge = null;
        if ($loggedMember) {
            $kycMerge = loadKycRowForLoggedMemberPublic($db, $loggedMember);
            $fnK = (is_array($kycMerge) && !empty($kycMerge['full_name'])) ? trim((string)$kycMerge['full_name']) : '';
            $full_name = $fnK !== '' ? $fnK : trim((string)($loggedMember['name'] ?? $full_name));
            $full_name_en = (is_array($kycMerge) && !empty($kycMerge['full_name_en']))
                ? trim((string)$kycMerge['full_name_en']) : $full_name_en;
            $mobile = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $loggedMember['phone'] ?? $mobile));
            $email = strtolower(trim((string)($kycMerge['email'] ?? $loggedMember['email'] ?? $email)));
            if ($kycMerge) {
                $dob_bs = $dob_bs ?: trim((string)($kycMerge['dob_bs'] ?? ''));
                $dob_ad = $dob_ad ?: ($kycMerge['dob_ad'] ?? null);
                $gender = $gender ?: trim((string)($kycMerge['gender'] ?? ''));
                $marital_status = $marital_status ?: trim((string)($kycMerge['marital_status'] ?? ''));
                $permanent_address = $permanent_address ?: trim((string)($kycMerge['permanent_address'] ?? ''));
                $temporary_address = $temporary_address ?: trim((string)($kycMerge['temporary_address'] ?? ''));
                $citizenship_no = $citizenship_no ?: trim((string)($kycMerge['citizenship_no'] ?? ''));
                $citizenship_issued_date = $citizenship_issued_date ?: trim((string)($kycMerge['citizenship_issued_date'] ?? ''));
                $citizenship_issued_place = $citizenship_issued_place ?: trim((string)($kycMerge['citizenship_issued_place'] ?? ''));
                $father_name = $father_name ?: trim((string)($kycMerge['father_name'] ?? ''));
                $mother_name = $mother_name ?: trim((string)($kycMerge['mother_name'] ?? ''));
            }
        } elseif ($isCoopMember === 'yes') {
            $v = verifyPublicFormKycByMemberId($db, $_POST['account_member_id'] ?? $_POST['member_id'] ?? '');
            if (!$v['ok']) {
                $error = isEnglish() ? $v['msg_en'] : $v['msg_np'];
            } else {
                $kycMerge = $v['row'];
                $full_name = trim((string)($kycMerge['full_name'] ?? ''));
                $full_name_en = trim((string)($kycMerge['full_name_en'] ?? $full_name_en));
                $mobile = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $_POST['mobile'] ?? ''));
                $email = strtolower(trim((string)($kycMerge['email'] ?? $_POST['email'] ?? '')));
                $dob_bs = trim((string)($kycMerge['dob_bs'] ?? $dob_bs));
                $dob_ad = ($kycMerge['dob_ad'] ?? '') !== '' && ($kycMerge['dob_ad'] ?? null) !== null
                    ? $kycMerge['dob_ad'] : ($dob_ad === '' ? null : $dob_ad);
                $gender = trim((string)($kycMerge['gender'] ?? $gender));
                $marital_status = trim((string)($kycMerge['marital_status'] ?? $marital_status));
                $permanent_address = trim((string)($kycMerge['permanent_address'] ?? $permanent_address));
                $temporary_address = trim((string)($kycMerge['temporary_address'] ?? $temporary_address));
                $citizenship_no = trim((string)($kycMerge['citizenship_no'] ?? $citizenship_no));
                $citizenship_issued_date = trim((string)($kycMerge['citizenship_issued_date'] ?? $citizenship_issued_date));
                $citizenship_issued_place = trim((string)($kycMerge['citizenship_issued_place'] ?? $citizenship_issued_place));
                $father_name = trim((string)($kycMerge['father_name'] ?? $father_name));
                $mother_name = trim((string)($kycMerge['mother_name'] ?? $mother_name));
            }
        }

        /* -------------------------------------------------------
           Server-side validation — account opening application
           खाता प्रकार, नाम, मोबाइल (10 digits), इमेल, नागरिकता required
        ------------------------------------------------------- */
        if (!$error && empty($account_type)) {
            $error = isEnglish() ? 'Please select an account type.' : 'कृपया खाता प्रकार छान्नुहोस्।';
        } elseif (!$error && empty($full_name)) {
            $error = isEnglish() ? 'Please enter your full name.' : 'कृपया पूरा नाम भर्नुहोस्।';
        } elseif (!$error && empty($mobile)) {
            $error = isEnglish() ? 'Mobile number is required.' : 'मोबाइल नम्बर अनिवार्य छ।';
        } elseif (!$error && !preg_match('/^[0-9]{10}$/', $mobile)) {
            $error = isEnglish() ? 'Please enter a valid 10-digit mobile number.' : 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।';
        } elseif (!$error && $isCoopMember !== 'yes' && empty($email)) {
            $error = isEnglish() ? 'Email address is required.' : 'इमेल ठेगाना अनिवार्य छ।';
        } elseif (!$error && !empty($email) && !isValidEmail($email)) {
            $error = isEnglish() ? 'Please enter a valid email address.' : 'कृपया सही इमेल ठेगाना राख्नुहोस्।';
        } elseif (!$error && empty($citizenship_no)) {
            $error = isEnglish() ? 'Citizenship number is required.' : 'नागरिकता नम्बर अनिवार्य छ।';
        } elseif (!$error) {
            try {
                // KYC-based member case मा duplicate documents फेरि upload/save नगर्ने
                $photo = '';
                $citizenship_front = '';
                $citizenship_back = '';
                $signature = '';
                if (!$useKycIdentity) {
                    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
                        $uploadResult = uploadFile($_FILES['photo'], 'accounts');
                        if ($uploadResult['success']) $photo = $uploadResult['path'];
                    }
                    if (isset($_FILES['citizenship_front']) && $_FILES['citizenship_front']['error'] === 0) {
                        $uploadResult = uploadFile($_FILES['citizenship_front'], 'accounts');
                        if ($uploadResult['success']) $citizenship_front = $uploadResult['path'];
                    }
                    if (isset($_FILES['citizenship_back']) && $_FILES['citizenship_back']['error'] === 0) {
                        $uploadResult = uploadFile($_FILES['citizenship_back'], 'accounts');
                        if ($uploadResult['success']) $citizenship_back = $uploadResult['path'];
                    }
                    if (isset($_FILES['signature']) && $_FILES['signature']['error'] === 0) {
                        $uploadResult = uploadFile($_FILES['signature'], 'accounts');
                        if ($uploadResult['success']) $signature = $uploadResult['path'];
                    }
                }

                $accTrackingId = 'ACC-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
                $stmt = $db->prepare("INSERT INTO account_applications (tracking_id, account_type, full_name, full_name_en, dob_bs, dob_ad, gender, marital_status, mobile, email, permanent_address, temporary_address, citizenship_no, citizenship_issued_date, citizenship_issued_place, father_name, mother_name, occupation, monthly_income, initial_deposit, nominee_name, nominee_relation, nominee_phone, branch, photo, citizenship_front, citizenship_back, signature) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$accTrackingId, $account_type, $full_name, $full_name_en, $dob_bs, $dob_ad, $gender, $marital_status, $mobile, $email, $permanent_address, $temporary_address, $citizenship_no, $citizenship_issued_date, $citizenship_issued_place, $father_name, $mother_name, $occupation, $monthly_income, $initial_deposit, $nominee_name, $nominee_relation, $nominee_phone, $branch, $photo, $citizenship_front, $citizenship_back, $signature]);
                $success = true;
                logSecurityEvent('account_application', 'Account application by: ' . $full_name . ' (Tracking: ' . $accTrackingId . ')');

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
                            $smsTxt = 'आकाश सहकारी: तपाईंको खाता खोल्ने आवेदन दर्ता भयो। Tracking ID: ' . $accTrackingId . '. हामी चाँडै सम्पर्क गर्नेछौं।';
                            $ph = preg_replace('/[^0-9]/', '', $mobile);
                            if (strlen($ph) >= 10) {
                                $ch = curl_init('http://api.sparrowsms.com/v2/sms/');
                                curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['token'=>$smsToken,'from'=>$smsSender,'to'=>$ph,'text'=>mb_substr($smsTxt,0,160)]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true]);
                                curl_exec($ch); curl_close($ch);
                            }
                        }
                    } catch (Throwable $ignored) {}
                }

                sendAdminNotification('account_application', [
                    'नाम'             => $full_name,
                    'खाता प्रकार'     => $account_type,
                    'फोन'             => $mobile,
                    'इमेल'            => $email ?: 'N/A',
                    'प्रारम्भिक जम्मा' => 'Rs. ' . number_format((float)($initial_deposit ?: 0)),
                    'Tracking ID'     => $accTrackingId,
                    'शाखा'            => $branch ?: 'N/A',
                    'मिति'            => date('Y-m-d H:i'),
                ], $accTrackingId);
            } catch (Throwable $e) {
                error_log('online-account submit error: ' . $e->getMessage());
                $error = isEnglish() ? 'Failed to submit application.' : 'आवेदन पेश गर्न सकिएन।';
            }
        }
    }
}

// Get branches
try {
    $db = getDB();
    require_once __DIR__ . '/includes/service-centers-helpers.php';
    $branches = fetchActiveServiceCenters($db);
} catch (Throwable $e) {
    $branches = [];
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $pageTitle; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $pageTitle; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Account Form Section -->
<section class="section-padding bg-light">
    <div class="container">
        <?php if ($success): ?>
        <div class="row justify-content-center">
            <div class="col-lg-7">
                <div class="form-success-card text-center py-5 px-4 rounded-4 shadow-sm" style="border:2px solid #c8e6c9;">
                    <div class="form-success-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="check-circle"></i></div>
                    <h3 class="mt-3 fw-bold text-success"><?php echo isEnglish() ? 'Account Application Submitted!' : 'खाता खोल्ने आवेदन पेश भयो!'; ?></h3>
                    <p class="text-muted mb-3"><?php echo isEnglish() ? 'We will contact you shortly to complete the process.' : 'हामी प्रक्रिया पूरा गर्न छिट्टै सम्पर्क गर्नेछौं।'; ?></p>
                    <?php if ($accTrackingId): ?>
                    <div class="form-tracking-box">
                        <div class="text-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID — save this!' : 'तपाईंको Tracking ID — सुरक्षित राख्नुहोस्!'; ?></div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="form-tracking-id" id="accTrkId"><?php echo e($accTrackingId); ?></div>
                            <button type="button" onclick="copyTrk('accTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2" title="Copy" style="font-size:11px;line-height:1.8;"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
                        </div>
                        <div class="form-tracking-help"><a href="<?php echo e($trackerUrl); ?>" class="text-success text-decoration-none fw-semibold"><?php echo isEnglish() ? 'Click here' : 'यहाँ बाट'; ?></a> <?php echo isEnglish() ? 'to check status in Tracker.' : 'Tracker मा स्थिति हेर्नुहोस्।'; ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <a href="<?php echo e($trackerUrl); ?>" class="btn btn-success px-4 me-2">
                            <i class="lucide-icon me-1" aria-hidden="true" data-lucide="search"></i><?php echo isEnglish() ? 'Track Application' : 'आवेदन ट्र्याक'; ?>
                        </a>
                        <a href="online-account.php" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-plus me-1"></i><?php echo isEnglish() ? 'New Application' : 'नयाँ आवेदन'; ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-1"></i><?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <script>document.addEventListener('DOMContentLoaded',function(){var e=document.querySelector('.alert-danger');if(e)e.scrollIntoView({behavior:'smooth',block:'center'});});</script>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="account-form-box">
                    <div class="form-header text-center mb-4">
                        <div class="form-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="user-plus"></i></div>
                        <h3><?php echo isEnglish() ? 'Open Your Account Online' : 'अनलाइन खाता खोल्नुहोस्'; ?></h3>
                        <p><?php echo isEnglish() ? 'Fill the form below to open a new account' : 'नयाँ खाता खोल्न तलको फारम भर्नुहोस्'; ?></p>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="account-form needs-validation" id="accountOpenForm" novalidate>
                        <?php echo csrfField(); ?>
                        <?php if ($loggedMember):
                            $kycForDisplay = isset($kycMerge) ? $kycMerge : null;
                            if (!$kycForDisplay) {
                                try { $kycForDisplay = loadKycRowForLoggedMemberPublic(getDB(), $loggedMember); } catch(Throwable $e) {}
                            }
                            require ROOT_PATH . 'includes/member-prefill-block.php';
                        ?>
                        <?php else: ?>
                        <div class="border rounded-3 p-3 mb-3 bg-light">
                            <label class="form-label fw-semibold d-block mb-2"><?php echo isEnglish() ? 'Are you already a KYC-registered member?' : 'पहिले नै KYC दर्ता भएको सदस्य?'; ?></label>
                            <div class="d-flex flex-wrap gap-3">
                                <label class="form-check-label"><input type="radio" name="is_coop_member" value="no" class="form-check-input me-1 js-acc-coop" <?php echo (($_POST['is_coop_member'] ?? 'no') === 'yes') ? '' : 'checked'; ?>> <?php echo isEnglish() ? 'No (new applicant)' : 'होइन'; ?></label>
                                <label class="form-check-label"><input type="radio" name="is_coop_member" value="yes" class="form-check-input me-1 js-acc-coop" <?php echo (($_POST['is_coop_member'] ?? '') === 'yes') ? 'checked' : ''; ?>> <?php echo isEnglish() ? 'Yes — Member ID + email + mobile (same as KYC)' : 'हो — सदस्यता + इमेल + मोबाइल (KYC जस्तै)'; ?></label>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Account Type -->
                        <div class="form-section">
                            <h5><i class="fas fa-wallet"></i> <?php echo isEnglish() ? 'Account Type' : 'खाता प्रकार'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Select Account Type' : 'खाता प्रकार छान्नुहोस्'; ?> <span class="text-danger">*</span></label>
                                    <select name="account_type" class="form-select" required>
                                        <option value="saving"><?php echo isEnglish() ? 'Saving Account' : 'बचत खाता'; ?></option>
                                        <option value="current"><?php echo isEnglish() ? 'Current Account' : 'चल्ती खाता'; ?></option>
                                        <option value="fixed"><?php echo isEnglish() ? 'Fixed Deposit' : 'मुद्दती निक्षेप'; ?></option>
                                        <option value="recurring"><?php echo isEnglish() ? 'Recurring Deposit' : 'आवधिक बचत'; ?></option>
                                        <option value="child"><?php echo isEnglish() ? 'Child Saving' : 'बाल बचत'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Initial Deposit' : 'प्रारम्भिक जम्मा'; ?></label>
                                    <select name="initial_deposit" class="form-select">
                                        <option value="1000">रु. १,०००</option>
                                        <option value="5000">रु. ५,०००</option>
                                        <option value="10000">रु. १०,०००</option>
                                        <option value="25000">रु. २५,०००</option>
                                        <option value="50000">रु. ५०,०००</option>
                                        <option value="100000">रु. १,००,०००+</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Personal Info -->
                        <?php if (!$loggedMember): ?>
                        <div class="form-section">
                            <h5><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i> <?php echo isEnglish() ? 'Personal Information' : 'व्यक्तिगत जानकारी'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3 js-acc-mid-wrap" style="display:none;">
                                    <label class="form-label"><?php echo isEnglish() ? 'Member ID (KYC)' : 'सदस्यता नम्बर (KYC)'; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="account_member_id" class="form-control js-acc-mid" autocomplete="off"
                                           value="<?php echo htmlspecialchars($_POST['account_member_id'] ?? '', ENT_QUOTES); ?>"
                                           placeholder="<?php echo isEnglish() ? 'Same as KYC' : 'KYC जस्तै'; ?>">
                                </div>
                                <div class="col-md-6 mb-3 js-acc-name-wrap">
                                    <label class="form-label"><?php echo isEnglish() ? 'Full Name (Nepali)' : 'पूरा नाम (नेपाली)'; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control js-acc-pers" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Full Name (English)' : 'पूरा नाम (अंग्रेजी)'; ?></label>
                                    <input type="text" name="full_name_en" class="form-control" value="<?php echo htmlspecialchars($_POST['full_name_en'] ?? '', ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Date of Birth (BS)' : 'जन्म मिति (बि.सं.)'; ?></label>
                                    <input type="text" name="dob_bs" class="form-control nepali-datepicker" placeholder="YYYY-MM-DD" autocomplete="off" value="<?php echo htmlspecialchars($_POST['dob_bs'] ?? '', ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Gender' : 'लिङ्ग'; ?></label>
                                    <select name="gender" class="form-select">
                                        <option value="male"><?php echo isEnglish() ? 'Male' : 'पुरुष'; ?></option>
                                        <option value="female"><?php echo isEnglish() ? 'Female' : 'महिला'; ?></option>
                                        <option value="other"><?php echo isEnglish() ? 'Other' : 'अन्य'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Marital Status' : 'वैवाहिक स्थिति'; ?></label>
                                    <select name="marital_status" class="form-select">
                                        <option value="single"><?php echo isEnglish() ? 'Single' : 'अविवाहित'; ?></option>
                                        <option value="married"><?php echo isEnglish() ? 'Married' : 'विवाहित'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3 js-hide-if-acc-coop-yes">
                                    <label class="form-label"><?php echo isEnglish() ? 'Mobile Number' : 'मोबाइल नम्बर'; ?> <span class="text-danger">*</span></label>
                                    <input type="tel" name="mobile" class="form-control js-acc-contact" required maxlength="10" pattern="[0-9]{10}" placeholder="98XXXXXXXX" value="<?php echo htmlspecialchars($_POST['mobile'] ?? '', ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-6 mb-3 js-hide-if-acc-coop-yes">
                                    <label class="form-label"><?php echo isEnglish() ? 'Email' : 'इमेल'; ?> <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control js-acc-contact" required placeholder="akashpame@gmail.com" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Permanent Address' : 'स्थायी ठेगाना'; ?></label>
                                    <textarea name="permanent_address" class="form-control" rows="2"><?php echo htmlspecialchars($_POST['permanent_address'] ?? '', ENT_QUOTES); ?></textarea>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Temporary Address' : 'अस्थायी ठेगाना'; ?></label>
                                    <textarea name="temporary_address" class="form-control" rows="2"><?php echo htmlspecialchars($_POST['temporary_address'] ?? '', ENT_QUOTES); ?></textarea>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <script>
                        (function(){
                          function syncAccCoop(){
                            var f=document.getElementById('accountOpenForm'); if(!f) return;
                            var yes=f.querySelector('input.js-acc-coop[value=yes]:checked');
                            var midW=f.querySelector('.js-acc-mid-wrap');
                            var nameW=f.querySelector('.js-acc-name-wrap');
                            var mid=f.querySelector('input.js-acc-mid');
                            var pers=f.querySelectorAll('.js-acc-pers');
                            f.querySelectorAll('.js-acc-kyc-hide').forEach(function(el){ el.style.display = yes ? 'none' : ''; });
                            f.querySelectorAll('.js-acc-cit-req').forEach(function(el){
                              if(yes) el.removeAttribute('required'); else el.setAttribute('required','required');
                            });
                            if(yes){
                              f.querySelectorAll('.js-hide-if-acc-coop-yes').forEach(function(el){el.style.display='none';});
                              if(midW) midW.style.display='';
                              if(nameW) nameW.style.display='none';
                              pers.forEach(function(el){ el.removeAttribute('required'); });
                              if(mid) mid.setAttribute('required','required');
                            }else{
                              f.querySelectorAll('.js-hide-if-acc-coop-yes').forEach(function(el){el.style.display='';});
                              if(midW) midW.style.display='none';
                              if(nameW) nameW.style.display='';
                              pers.forEach(function(el){ el.setAttribute('required','required'); });
                              if(mid) mid.removeAttribute('required');
                            }
                          }
                          document.querySelectorAll('#accountOpenForm input.js-acc-coop').forEach(function(r){ r.addEventListener('change',syncAccCoop); });
                          document.addEventListener('DOMContentLoaded',syncAccCoop);
                          var accForm=document.getElementById('accountOpenForm');
                          if(accForm){
                            accForm.addEventListener('keydown',function(e){
                              if(e.key!=='Enter') return;
                              var t=e.target; if(!t) return;
                              var tag=(t.tagName||'').toLowerCase();
                              if(tag==='textarea') return;
                              if(tag==='button'||(tag==='input'&&(t.type==='submit'||t.type==='button'))) return;
                              e.preventDefault();
                            });
                          }
                        })();
                        </script>
                        

                        <!-- Citizenship Info -->
                        <div class="form-section js-acc-kyc-hide">
                            <h5><i class="lucide-icon" aria-hidden="true" data-lucide="id-card"></i> <?php echo isEnglish() ? 'Citizenship Details' : 'नागरिकता विवरण'; ?></h5>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Citizenship No.' : 'नागरिकता नं.'; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="citizenship_no" class="form-control js-acc-cit-req" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Issued Date' : 'जारी मिति'; ?></label>
                                    <input type="text" name="citizenship_issued_date" class="form-control nepali-datepicker" placeholder="YYYY-MM-DD" autocomplete="off">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Issued Place' : 'जारी स्थान'; ?></label>
                                    <input type="text" name="citizenship_issued_place" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? "Father's Name" : 'बुबाको नाम'; ?></label>
                                    <input type="text" name="father_name" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? "Mother's Name" : 'आमाको नाम'; ?></label>
                                    <input type="text" name="mother_name" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Occupation' : 'पेशा'; ?></label>
                                    <input type="text" name="occupation" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- Nominee Info -->
                        <div class="form-section">
                            <h5><i class="fas fa-user-shield"></i> <?php echo isEnglish() ? 'Guarantor Details' : 'धन जमानी विवरण'; ?></h5>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Guarantor Name' : 'धन जमानीको नाम'; ?></label>
                                    <input type="text" name="nominee_name" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Relation' : 'सम्बन्ध'; ?></label>
                                    <input type="text" name="nominee_relation" class="form-control">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Guarantor Phone' : 'धन जमानीको फोन'; ?></label>
                                    <input type="tel" name="nominee_phone" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- Branch Selection -->
                        <div class="form-section">
                            <h5><i class="fas fa-building"></i> <?php echo isEnglish() ? 'Branch' : 'शाखा'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Preferred Branch' : 'रुचाइएको शाखा'; ?></label>
                                    <select name="branch" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select Branch' : 'शाखा छान्नुहोस्'; ?></option>
                                        <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['name']; ?>"><?php echo $branch['name']; ?></option>
                                        <?php endforeach; ?>
                                        <option value="head_office"><?php echo isEnglish() ? 'Head Office' : 'प्रधान कार्यालय'; ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Documents -->
                        <div class="form-section js-hide-if-acc-coop-yes" <?php echo $loggedMember ? 'style="display:none;"' : ''; ?>>
                            <h5><i class="fas fa-file-upload"></i> <?php echo isEnglish() ? 'Upload Documents' : 'कागजातहरू अपलोड गर्नुहोस्'; ?></h5>
                            <div class="small text-muted mb-2">
                                <?php echo isEnglish()
                                    ? 'Already KYC member? Documents are reused from KYC; re-upload is not required.'
                                    : 'पहिले नै KYC सदस्य भएमा कागजात KYC बाट नै प्रयोग हुन्छ, फेरि अपलोड गर्नुपर्दैन।'; ?>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Passport Photo' : 'पासपोर्ट फोटो'; ?></label>
                                    <input type="file" name="photo" class="form-control" accept="image/*" capture="environment">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Signature' : 'हस्ताक्षर'; ?></label>
                                    <input type="file" name="signature" class="form-control" accept="image/*" capture="environment">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Citizenship Front' : 'नागरिकता अगाडि'; ?></label>
                                    <input type="file" name="citizenship_front" class="form-control" accept="image/*" capture="environment">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Citizenship Back' : 'नागरिकता पछाडि'; ?></label>
                                    <input type="file" name="citizenship_back" class="form-control" accept="image/*" capture="environment">
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span><i class="fas fa-paper-plane me-1"></i> <?php echo isEnglish() ? 'Submit Application' : 'आवेदन पेश गर्नुहोस्'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

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
