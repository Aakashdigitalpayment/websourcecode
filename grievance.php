<?php
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
require_once 'includes/kyc-public-form.php';
$pageTitle = isEnglish() ? 'File Grievance' : 'गुनासो दर्ता गर्नुहोस्';
require_once 'includes/header.php';
$L = getLangStrings();

$success = false;
$error = '';
$trackingId = '';
$trackingResult = null;
$loggedMember = getLoggedInMemberProfile();
$lockedMemberFields = $loggedMember ? 'readonly' : '';
$isEmbed = !empty($_GET['embed']);
$trackerUrl = $isEmbed
    ? (rtrim(SITE_URL, '/') . '/member/tracker.php')
    : 'application-tracker.php';

// Handle tracking lookup
if (isset($_GET['track']) && !empty($_GET['track'])) {
    $searchId = clean_text($_GET['track'] ?? '', 80);
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM grievances WHERE tracking_id = ? OR id = ?");
        $trackingNum = (int) preg_replace('/[^0-9]/', '', $searchId);
        $stmt->execute([$searchId, $trackingNum]);
        $trackingResult = $stmt->fetch();
    } catch (Exception $e) {
        // Silent fail
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed.' : 'सुरक्षा जाँच असफल।';
    } elseif (!checkRateLimit('grievance', 10, 3600)) {
        $error = isEnglish() ? 'Too many requests. Please try again after 1 hour.' : 'धेरै अनुरोधहरू। कृपया १ घण्टापछि पुनः प्रयास गर्नुहोस्।';
    } else {
        $name = clean_text($_POST['name'] ?? '', 200);
        $member_id = clean_text($_POST['member_id'] ?? '', 80);
        $phone = preg_replace('/[^0-9]/', '', clean_text($_POST['phone'] ?? '', 15));
        $email = strtolower(clean_text($_POST['email'] ?? '', 254));
        $category = clean_text($_POST['category'] ?? 'other', 40);
        $subject = clean_text($_POST['subject'] ?? '', 300);
        $description = clean_text($_POST['description'] ?? '', 8000);
        $is_anonymous = isset($_POST['is_anonymous']) ? 1 : 0;

        $db = getDB();
        $isCoopMember = $loggedMember ? 'yes' : ((($_POST['is_coop_member'] ?? '') === 'yes') ? 'yes' : 'no');
        if (!$is_anonymous && $loggedMember) {
            $v = loadKycRowForLoggedMemberPublic($db, $loggedMember);
            if (!$v || strtolower(trim((string)($v['status'] ?? ''))) !== 'approved') {
                $error = isEnglish() ? 'KYC verification is required to file grievance as member.' : 'सदस्यका रूपमा गुनासो दर्ता गर्न KYC verified (approved) हुनुपर्छ।';
            }
            if ($v) {
                $name = trim((string)($v['full_name'] ?? $loggedMember['name'] ?? $name));
                $member_id = trim((string)($v['member_id'] ?? $loggedMember['sadasyata_number'] ?? $member_id));
                $phone = preg_replace('/[^0-9]/', '', (string)($v['mobile'] ?? $loggedMember['phone'] ?? $phone));
                $email = strtolower(trim((string)($v['email'] ?? $loggedMember['email'] ?? $email)));
            }
        } elseif (!$is_anonymous && $isCoopMember === 'yes') {
            $v = verifyPublicFormKycApprovedByMemberId($db, $_POST['member_id'] ?? '');
            if (!$v['ok']) {
                $error = isEnglish() ? $v['msg_en'] : $v['msg_np'];
            } else {
                $k = $v['row'];
                $name = trim((string)($k['full_name'] ?? $name));
                $member_id = strtoupper(trim((string)($k['member_id'] ?? $_POST['member_id'] ?? '')));
                $phone = preg_replace('/[^0-9]/', '', (string)($k['mobile'] ?? $phone));
                $email = strtolower(trim((string)($k['email'] ?? $email)));
            }
        }

        /* Logged-in + गुप्त: ट्र्याकिङका लागि फोन/इमेल प्रोफाइलबाट पूरा गर्न सकिन्छ */
        if ($is_anonymous && $loggedMember) {
            if ($phone === '' && !empty($loggedMember['phone'])) {
                $phone = preg_replace('/[^0-9]/', '', (string)$loggedMember['phone']);
            }
            if ($email === '' && !empty($loggedMember['email'])) {
                $email = strtolower(trim((string)$loggedMember['email']));
            }
        }

        /* -------------------------------------------------------
           Server-side validation — grievance form
           Non-anonymous: नाम, फोन (10 digits), इमेल (सदस्य होइन भने)
           गुप्त: नाम छैन; ट्र्याकिङका लागि फोन + इमेल अनिवार्य
        ------------------------------------------------------- */
        if (empty($subject)) {
            $error = isEnglish() ? 'Please enter a subject.' : 'कृपया विषय लेख्नुहोस्।';
        } elseif (empty($description)) {
            $error = isEnglish() ? 'Please describe your grievance.' : 'कृपया गुनासोको विवरण लेख्नुहोस्।';
        } elseif ($is_anonymous && empty($phone)) {
            $error = isEnglish() ? 'Mobile number is required (for tracking updates).' : 'ट्र्याकिङ/जवाफका लागि मोबाइल अनिवार्य छ।';
        } elseif ($is_anonymous && !preg_match('/^[0-9]{10}$/', $phone)) {
            $error = isEnglish() ? 'Please enter a valid 10-digit mobile number.' : 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।';
        } elseif ($is_anonymous && empty($email)) {
            $error = isEnglish() ? 'Email is required (for tracking updates).' : 'ट्र्याकिङ/जवाफका लागि इमेल अनिवार्य छ।';
        } elseif ($is_anonymous && !isValidEmail($email)) {
            $error = isEnglish() ? 'Please enter a valid email address.' : 'कृपया सही इमेल ठेगाना राख्नुहोस्।';
        } elseif (!$is_anonymous && $isCoopMember !== 'yes' && empty($name)) {
            $error = isEnglish() ? 'Please enter your full name.' : 'कृपया पूरा नाम भर्नुहोस्।';
        } elseif (!$is_anonymous && $isCoopMember !== 'yes' && empty($phone)) {
            $error = isEnglish() ? 'Mobile number is required.' : 'मोबाइल नम्बर अनिवार्य छ।';
        } elseif (!$is_anonymous && $isCoopMember !== 'yes' && !preg_match('/^[0-9]{10}$/', $phone)) {
            /* Phone must be exactly 10 digits */
            $error = isEnglish() ? 'Please enter a valid 10-digit mobile number.' : 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।';
        } elseif (!$is_anonymous && $isCoopMember !== 'yes' && empty($email)) {
            $error = isEnglish() ? 'Email address is required.' : 'इमेल ठेगाना अनिवार्य छ।';
        } elseif (!$is_anonymous && !empty($email) && !isValidEmail($email)) {
            /* Email format check */
            $error = isEnglish() ? 'Please enter a valid email address.' : 'कृपया सही इमेल ठेगाना राख्नुहोस्।';
        } else {
            try {
                // Handle attachment
                $attachment = '';
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === 0) {
                    $uploadResult = uploadFile($_FILES['attachment'], 'grievances');
                    if ($uploadResult['success']) {
                        $attachment = $uploadResult['path'];
                    }
                }


                // Generate tracking ID first
                $tempId = date('YmdHis') . rand(100, 999);
                $trackingId = 'GRV-' . date('Ymd') . '-' . strtoupper(substr(md5($tempId), 0, 6));

                // Insert with tracking ID
                $stmt = $db->prepare("INSERT INTO grievances (tracking_id, name, member_id, phone, email, category, subject, description, attachment, is_anonymous, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

                if ($is_anonymous) {
                    /* नाम सार्वजनिक रूपमा गुप्त; फोन/इमेल ट्र्याकिङ र सम्पर्कका लागि DB मा राखिन्छ */
                    $stmt->execute([$trackingId, 'Anonymous', '', $phone, $email, $category, $subject, $description, $attachment, 1]);
                } else {
                    $stmt->execute([$trackingId, $name, $member_id, $phone, $email, $category, $subject, $description, $attachment, 0]);
                }

                $success = true;
                logSecurityEvent('grievance_filed', 'Grievance filed: ' . $subject . ' (Tracking: ' . $trackingId . ')');

                require_once 'includes/notifications.php';

                // Tracking SMS — गुप्त भए पनि फोन दिएमा पठाउने
                if (!empty($phone)) {
                    try {
                        $smsToken = getSetting('notify_sms_token', '');
                        $smsSender = getSetting('notify_sms_sender_id', 'COOP');
                        if (getSetting('notify_sms_enabled', '0') === '1' && $smsToken) {
                            $smsTxt = 'आकाश सहकारी: तपाईंको गुनासो दर्ता भयो। Tracking ID: ' . $trackingId . '. हामी छिट्टै जवाफ दिनेछौं।';
                            $ph = preg_replace('/[^0-9]/', '', $phone);
                            if (strlen($ph) >= 10) {
                                $ch = curl_init('http://api.sparrowsms.com/v2/sms/');
                                curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['token'=>$smsToken,'from'=>$smsSender,'to'=>$ph,'text'=>mb_substr($smsTxt,0,160)]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true]);
                                curl_exec($ch); curl_close($ch);
                            }
                        }
                    } catch (Exception $ignored) {}
                }

                /* Admin notification */
                sendAdminNotification('grievance', [
                    'नाम'       => $is_anonymous ? 'Anonymous' : $name,
                    'सदस्य नं.' => $member_id ?: 'N/A',
                    'फोन'       => $phone ?: 'N/A',
                    'इमेल'      => $email ?: 'N/A',
                    'Category'  => $category,
                    'विषय'      => $subject,
                    'मिति'      => date('Y-m-d H:i'),
                ], $trackingId);
            } catch (Exception $e) {
                $error = isEnglish() ? 'Failed to submit grievance: ' . $e->getMessage() : 'गुनासो दर्ता गर्न सकिएन: ' . $e->getMessage();
            }
        }
    }
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

<!-- Grievance Form Section -->
<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <?php if ($success): ?>
                <div class="form-success-card text-center py-5 px-4 rounded-4 shadow-sm" style="border:2px solid #c8e6c9;">
                    <div class="form-success-icon"><i class="fas fa-check-circle"></i></div>
                    <h3 class="mt-3 fw-bold text-success"><?php echo isEnglish() ? 'Grievance Submitted Successfully!' : 'गुनासो सफलतापूर्वक दर्ता भयो!'; ?></h3>
                    <p class="text-muted mb-3"><?php echo isEnglish() ? 'We will review and respond to your grievance soon.' : 'हामी तपाईंको गुनासो छिट्टै समीक्षा गरी जवाफ दिनेछौं।'; ?></p>
                    <?php if ($trackingId): ?>
                    <div class="form-tracking-box">
                        <div class="text-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID — save this!' : 'तपाईंको Tracking ID — सुरक्षित राख्नुहोस्!'; ?></div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="form-tracking-id" id="grvTrkId"><?php echo e($trackingId); ?></div>
                            <button type="button" onclick="copyTrk('grvTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2" title="Copy" style="font-size:11px;line-height:1.8;"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
                        </div>
                        <div class="form-tracking-help"><a href="<?php echo e($trackerUrl); ?>" class="text-success text-decoration-none fw-semibold"><?php echo isEnglish() ? 'Open Tracker' : 'ट्र्याकर खोल्नुहोस्'; ?></a> <?php echo isEnglish() ? 'or track this ID below.' : 'वा तलबाट यो ID ट्र्याक गर्नुहोस्।'; ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <a href="<?php echo e($trackerUrl); ?>" class="btn btn-success px-4 me-2">
                            <i class="fas fa-search me-1"></i><?php echo isEnglish() ? 'Application Tracker' : 'आवेदन ट्र्याकर'; ?>
                        </a>
                        <?php if ($trackingId): ?>
                        <a href="grievance.php?track=<?php echo urlencode($trackingId); ?>" class="btn btn-outline-success px-4 me-2">
                            <i class="fas fa-clipboard-list me-1"></i><?php echo isEnglish() ? 'This grievance' : 'यो गुनासो'; ?>
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo SITE_URL; ?>" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-home me-1"></i><?php echo $L['home']; ?>
                        </a>
                    </div>
                </div>
                <?php else: ?>

                <?php if ($error): ?>
                <script>document.addEventListener('DOMContentLoaded',function(){var e=document.querySelector('.alert-danger');if(e)e.scrollIntoView({behavior:'smooth',block:'center'});});</script>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
                <?php endif; ?>

                <!-- Tracking Form -->
                <div class="tracking-form mb-4">
                    <h5><i class="fas fa-search"></i> <?php echo isEnglish() ? 'Track Your Grievance' : 'आफ्नो गुनासो ट्र्याक गर्नुहोस्'; ?></h5>
                    <form method="GET" class="d-flex gap-2">
                        <input type="text" name="track" class="form-control" placeholder="<?php echo isEnglish() ? 'Enter Tracking ID (e.g. GRV-000001)' : 'ट्र्याकिङ आईडी राख्नुहोस् (जस्तै: GRV-000001)'; ?>" value="<?php echo htmlspecialchars($_GET['track'] ?? ''); ?>">
                        <button type="submit" class="btn btn-primary" aria-label="Search" title="Search"><i class="fas fa-search"></i></button>
                    </form>

                    <?php if ($trackingResult): ?>
                    <div class="tracking-result mt-3">
                        <h5><i class="fas fa-clipboard-list"></i> <?php echo isEnglish() ? 'Grievance Status' : 'गुनासो स्थिति'; ?></h5>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <strong><?php echo isEnglish() ? 'Tracking ID:' : 'ट्र्याकिङ आईडी:'; ?></strong>
                                <?php echo $trackingResult['tracking_id'] ?? 'GRV-' . str_pad($trackingResult['id'], 6, '0', STR_PAD_LEFT); ?>
                            </div>
                            <div class="col-md-6 mb-2">
                                <strong><?php echo isEnglish() ? 'Status:' : 'स्थिति:'; ?></strong>
                                <span class="status-badge <?php echo $trackingResult['status']; ?>">
                                    <?php
                                    $statusLabels = [
                                        'pending' => isEnglish() ? 'Pending' : 'पेन्डिङ',
                                        'in_progress' => isEnglish() ? 'In Progress' : 'प्रक्रियामा',
                                        'resolved' => isEnglish() ? 'Resolved' : 'समाधान भयो',
                                        'closed' => isEnglish() ? 'Closed' : 'बन्द'
                                    ];
                                    echo $statusLabels[$trackingResult['status']] ?? $trackingResult['status'];
                                    ?>
                                </span>
                            </div>
                            <div class="col-md-6 mb-2">
                                <strong><?php echo isEnglish() ? 'Category:' : 'वर्ग:'; ?></strong>
                                <?php echo ucfirst($trackingResult['category']); ?>
                            </div>
                            <div class="col-md-6 mb-2">
                                <strong><?php echo isEnglish() ? 'Filed On:' : 'दर्ता मिति:'; ?></strong>
                                <?php echo date('Y-m-d', strtotime($trackingResult['created_at'])); ?>
                            </div>
                            <div class="col-12 mb-2">
                                <strong><?php echo isEnglish() ? 'Subject:' : 'विषय:'; ?></strong>
                                <?php echo htmlspecialchars($trackingResult['subject']); ?>
                            </div>
                            <?php if (!empty($trackingResult['admin_response'])): ?>
                            <div class="col-12">
                                <strong><?php echo isEnglish() ? 'Response:' : 'प्रतिक्रिया:'; ?></strong>
                                <p class="mb-0 mt-1 p-3 bg-light rounded"><?php echo nl2br(htmlspecialchars($trackingResult['admin_response'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php elseif (isset($_GET['track']) && !empty($_GET['track'])): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo isEnglish() ? 'No grievance found with this tracking ID.' : 'यो ट्र्याकिङ आईडी भएको गुनासो फेला परेन।'; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <hr class="my-4">

                <div class="grievance-form-box">
                    <div class="form-header text-center mb-4">
                        <div class="form-icon"><i class="fas fa-exclamation-circle"></i></div>
                        <h3><?php echo isEnglish() ? 'Submit Your Grievance' : 'आफ्नो गुनासो पेश गर्नुहोस्'; ?></h3>
                        <p><?php echo isEnglish() ? 'Your grievance will be addressed directly by our management' : 'तपाईंको गुनासो हाम्रो व्यवस्थापनले प्रत्यक्ष रूपमा सम्बोधन गर्नेछ'; ?></p>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="needs-validation grievance-form" id="grievanceForm" novalidate>
                        <?php echo csrfField(); ?>

                        <!-- Anonymous Option -->
                        <div class="form-section">
                            <div class="form-check mb-2">
                                <input type="checkbox" name="is_anonymous" id="is_anonymous" class="form-check-input" onchange="applyGrievancePersonalFieldsState()">
                                <label for="is_anonymous" class="form-check-label">
                                    <?php echo isEnglish() ? 'Submit Anonymously (गुप्त रूपमा पेश गर्नुहोस्)' : 'गुप्त रूपमा पेश गर्नुहोस् (Submit Anonymously)'; ?>
                                </label>
                            </div>
                            <p class="small text-muted mb-0" id="grvAnonymousHint" style="display:none;">
                                <?php echo isEnglish()
                                    ? 'Your name will not be collected. Phone and email are still required so we can send tracking updates.'
                                    : 'नाम संकलन हुँदैन। ट्र्याकिङ ID र जवाफका लागि फोन र इमेल अनिवार्य रहन्छन्।'; ?>
                            </p>
                        </div>

                        <?php if ($loggedMember):
                            $kycForDisplay = null;
                            try { $kycForDisplay = loadKycRowForLoggedMemberPublic(getDB(), $loggedMember); } catch(Throwable $e) {}
                            require ROOT_PATH . 'includes/member-prefill-block.php';
                        else: ?>
                        <div class="form-section border rounded-3 p-3 mb-3 bg-light">
                            <label class="form-label fw-semibold d-block mb-2"><?php echo isEnglish() ? 'Cooperative member?' : 'सहकारी सदस्य?'; ?></label>
                            <div class="d-flex flex-wrap gap-3">
                                <label class="form-check-label"><input type="radio" name="is_coop_member" value="no" class="form-check-input me-1 js-grv-coop" <?php echo (($_POST['is_coop_member'] ?? 'no') === 'yes') ? '' : 'checked'; ?>> <?php echo isEnglish() ? 'No' : 'होइन'; ?></label>
                                <label class="form-check-label"><input type="radio" name="is_coop_member" value="yes" class="form-check-input me-1 js-grv-coop" <?php echo (($_POST['is_coop_member'] ?? '') === 'yes') ? 'checked' : ''; ?>> <?php echo isEnglish() ? 'Yes (Member ID based KYC)' : 'हो (Member ID आधारित KYC)'; ?></label>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Personal Info — guest only -->
                        <?php if (!$loggedMember): ?>
                        <div class="form-section" id="personalInfoSection">
                            <h5><i class="fas fa-user"></i> <?php echo isEnglish() ? 'Personal Information' : 'व्यक्तिगत जानकारी'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3 js-grv-name-wrap">
                                    <label class="form-label"><?php echo isEnglish() ? 'Full Name' : 'पूरा नाम'; ?> <span class="text-danger required-star">*</span></label>
                                    <input type="text" name="name" id="nameField" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Member ID (if any)' : 'सदस्य नं. (भएमा)'; ?></label>
                                    <input type="text" name="member_id" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label" id="phoneLabel"><?php echo isEnglish() ? 'Phone' : 'फोन'; ?> <span class="text-danger required-star">*</span></label>
                                    <input type="tel" name="phone" id="phoneField" class="form-control"
                                           required maxlength="10" pattern="[0-9]{10}"
                                           placeholder="98XXXXXXXX">
                                </div>
                                <div class="col-md-6 mb-3 js-hide-if-grv-coop-yes">
                                    <label class="form-label" id="emailLabel">
                                        <?php echo isEnglish() ? 'Email' : 'इमेल'; ?>
                                        <span class="text-danger required-star" id="emailStar">*</span>
                                    </label>
                                    <input type="email" name="email" id="emailField" class="form-control"
                                           required placeholder="akashpame@gmail.com">
                                </div>
                            </div>
                        </div>
                        <?php endif; /* !$loggedMember */ ?>

                        <!-- Grievance Details -->
                        <div class="form-section">
                            <h5><i class="fas fa-file-alt"></i> <?php echo isEnglish() ? 'Grievance Details' : 'गुनासो विवरण'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Category' : 'वर्ग'; ?></label>
                                    <select name="category" class="form-select">
                                        <option value="service"><?php echo isEnglish() ? 'Service Related' : 'सेवा सम्बन्धी'; ?></option>
                                        <option value="staff"><?php echo isEnglish() ? 'Staff Behavior' : 'कर्मचारी व्यवहार'; ?></option>
                                        <option value="loan"><?php echo isEnglish() ? 'Loan Related' : 'ऋण सम्बन्धी'; ?></option>
                                        <option value="account"><?php echo isEnglish() ? 'Account Related' : 'खाता सम्बन्धी'; ?></option>
                                        <option value="branch"><?php echo isEnglish() ? 'Branch Related' : 'शाखा सम्बन्धी'; ?></option>
                                        <option value="other"><?php echo isEnglish() ? 'Other' : 'अन्य'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Subject' : 'विषय'; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="subject" class="form-control" required>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Description' : 'विवरण'; ?> <span class="text-danger">*</span></label>
                                    <textarea name="description" class="form-control" rows="5" required placeholder="<?php echo isEnglish() ? 'Describe your grievance in detail...' : 'आफ्नो गुनासो विस्तृत रूपमा लेख्नुहोस्...'; ?>"></textarea>
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Attachment (if any)' : 'संलग्न (भएमा)'; ?></label>
                                    <input type="file" name="attachment" class="form-control" accept="image/*,.pdf,.doc,.docx" capture="environment">
                                    <small class="text-muted"><?php echo isEnglish() ? 'Max 5MB. Supported: Images, PDF, DOC' : 'अधिकतम ५MB। समर्थित: फोटो, PDF, DOC'; ?></small>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-danger btn-lg">
                                <i class="fas fa-paper-plane"></i> <?php echo isEnglish() ? 'Submit Grievance' : 'गुनासो पेश गर्नुहोस्'; ?>
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script>
/* गुप्त: नाम लुकाउने; फोन/इमेल ट्र्याकिङका लागि अनिवार्य */
function applyGrievancePersonalFieldsState() {
    var form = document.getElementById('grievanceForm');
    if (!form) return;
    var anonEl = document.getElementById('is_anonymous');
    var isAnonymous = anonEl && anonEl.checked;
    var hint = document.getElementById('grvAnonymousHint');
    if (hint) hint.style.display = isAnonymous ? 'block' : 'none';

    var coopYes = !!form.querySelector('input.js-grv-coop[value="yes"]:checked');
    var personalSection = document.getElementById('personalInfoSection');
    var nameWrap = form.querySelector('.js-grv-name-wrap');
    var nameField = document.getElementById('nameField');
    var phoneField = document.getElementById('phoneField');
    var emailField = document.getElementById('emailField');
    var memberField = form.querySelector('input[name="member_id"]');
    var emailStar = document.getElementById('emailStar');
    var hiddenCoop = form.querySelectorAll('.js-hide-if-grv-coop-yes');

    if (personalSection) personalSection.style.opacity = '1';

    /* सदस्य हो + गुप्त होइन: इमेल KYC बाट — लुकाउने */
    hiddenCoop.forEach(function (el) {
        el.style.display = coopYes && !isAnonymous ? 'none' : '';
    });

    /* नाम: सदस्य KYC हो वा गुप्त हो — दुवैमा नाम फाँट लुकाउने */
    if (nameWrap) {
        nameWrap.style.display = coopYes || isAnonymous ? 'none' : '';
    }
    if (nameField) {
        if (isAnonymous || coopYes) {
            nameField.removeAttribute('required');
            nameField.value = '';
        }
    }

    if (isAnonymous) {
        if (phoneField) {
            phoneField.setAttribute('required', 'required');
        }
        if (emailField) {
            emailField.setAttribute('required', 'required');
        }
        if (emailStar) emailStar.style.display = 'inline';
        if (memberField) memberField.removeAttribute('required');
    } else if (coopYes) {
        if (phoneField) phoneField.removeAttribute('required');
        if (emailField) emailField.removeAttribute('required');
        if (emailStar) emailStar.style.display = 'none';
        if (memberField) memberField.setAttribute('required', 'required');
    } else {
        if (nameField) nameField.setAttribute('required', 'required');
        if (phoneField) phoneField.setAttribute('required', 'required');
        if (emailField) emailField.setAttribute('required', 'required');
        if (emailStar) emailStar.style.display = 'inline';
        if (memberField) memberField.removeAttribute('required');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('grievanceForm');
    if (form) {
        form.querySelectorAll('input.js-grv-coop').forEach(function (r) {
            r.addEventListener('change', applyGrievancePersonalFieldsState);
        });
    }
    applyGrievancePersonalFieldsState();
});
</script>

<?php require_once 'includes/footer.php'; ?>
