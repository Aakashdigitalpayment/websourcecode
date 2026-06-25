<?php
/**
 * Member Welfare Claims Form
 * सदस्य कल्याण दाबी फारम
 * For: Maternity (सुत्केरी), Death (मृत्यु), Insurance (बीमा), Medical (उपचार), Other
 */
require_once 'includes/config.php';
require_once 'includes/welfare-claims-tables.php';
require_once 'includes/welfare-claims-submit-helper.php';
$kycPublicFormFile = __DIR__ . '/includes/kyc-public-form.php';
if (is_file($kycPublicFormFile)) {
    require_once $kycPublicFormFile;
}
$pageTitle = isEnglish() ? 'Member Welfare Claims' : 'सदस्य कल्याण दाबी';
require_once 'includes/header.php';
$L = getLangStrings();

$success = false;
$error = '';
$trackingId = '';
$oldInput = [];
$trackingResult = null;
$loggedMember = getLoggedInMemberProfile();
$lockedMemberFields = $loggedMember ? 'readonly' : '';

// Handle tracking lookup
if (isset($_GET['track']) && !empty($_GET['track'])) {
    $searchId = clean_text($_GET['track'] ?? '', 80);
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM member_welfare_claims WHERE tracking_id = ? OR phone = ?");
        $phoneKey = preg_replace('/[^0-9]/', '', $searchId);
        $stmt->execute([$searchId, $phoneKey !== '' ? $phoneKey : $searchId]);
        $trackingResult = $stmt->fetch();
    } catch (Exception $e) {
        // Table might not exist yet
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldInput = $_POST;
    if (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed.' : 'सुरक्षा जाँच असफल।';
    } elseif (!checkRateLimit('welfare_claim', 10, 3600)) {
        $error = isEnglish() ? 'Too many requests. Please try again after 1 hour.' : 'धेरै अनुरोधहरू भए। कृपया १ घण्टापछि पुनः प्रयास गर्नुहोस्।';
    } else {
        // Get form data
        $member_name = clean_text($_POST['member_name'] ?? '', 200);
        $member_id = clean_text($_POST['member_id'] ?? '', 80);
        $phone = preg_replace('/[^0-9]/', '', clean_text($_POST['phone'] ?? '', 15));
        $email = strtolower(clean_text($_POST['email'] ?? '', 254));
        $address = clean_text($_POST['address'] ?? '', 2000);
        $claim_type = clean_text($_POST['claim_type'] ?? '', 40);
        $beneficiary_name = clean_text($_POST['beneficiary_name'] ?? '', 200);
        $beneficiary_relation = clean_text($_POST['beneficiary_relation'] ?? '', 120);
        $claim_amount = floatval($_POST['claim_amount'] ?? 0);
        $description = clean_text($_POST['description'] ?? '', 4000);

        // Type-specific fields
        $deceased_name = clean_text($_POST['deceased_name'] ?? '', 200);
        $deceased_relation = clean_text($_POST['deceased_relation'] ?? '', 120);
        $death_date = clean_text($_POST['death_date'] ?? '', 40);
        $delivery_date = clean_text($_POST['delivery_date'] ?? '', 40);
        $hospital_name = clean_text($_POST['hospital_name'] ?? '', 200);
        $disease_illness = clean_text($_POST['disease_illness'] ?? '', 500);
        $treatment_date = clean_text($_POST['treatment_date'] ?? '', 40);
        $hospital_clinic = clean_text($_POST['hospital_clinic'] ?? '', 200);
        $policy_number = clean_text($_POST['policy_number'] ?? '', 80);
        $insurer_name = clean_text($_POST['insurer_name'] ?? '', 150);
        $member_portal_id = $loggedMember ? (int)($loggedMember['id'] ?? 0) : null;

        $db = getDB();
        $isCoopMember = $loggedMember ? 'yes' : ((($_POST['is_coop_member'] ?? '') === 'yes') ? 'yes' : 'no');
        $kycMerge = null;
        if ($loggedMember) {
            if (function_exists('loadKycRowForLoggedMemberPublic')) {
                $kycMerge = loadKycRowForLoggedMemberPublic($db, $loggedMember);
            }
            $fnK = (is_array($kycMerge) && !empty($kycMerge['full_name'])) ? trim((string)$kycMerge['full_name']) : '';
            $member_name = $fnK !== '' ? $fnK : trim((string)($loggedMember['name'] ?? $member_name));
            $midK = (is_array($kycMerge) && !empty($kycMerge['member_id'])) ? trim((string)$kycMerge['member_id']) : '';
            $member_id = $midK !== '' ? $midK : trim((string)($loggedMember['sadasyata_number'] ?? $member_id));
            $phone = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $loggedMember['phone'] ?? $phone));
            $email = strtolower(trim((string)($kycMerge['email'] ?? $loggedMember['email'] ?? $email)));
            if ($kycMerge && $address === '') {
                $pa = trim((string)($kycMerge['permanent_address'] ?? ''));
                $ta = trim((string)($kycMerge['temporary_address'] ?? ''));
                $address = $ta !== '' ? ($pa !== '' ? $pa . ' / ' . $ta : $ta) : $pa;
            }
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
                    $member_name = trim((string)($kycMerge['full_name'] ?? ''));
                    $member_id = strtoupper(trim((string)($kycMerge['member_id'] ?? $_POST['member_id'] ?? '')));
                    $phone = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $_POST['phone'] ?? ''));
                    $email = strtolower(trim((string)($kycMerge['email'] ?? $_POST['email'] ?? '')));
                    if ($address === '') {
                        $pa = trim((string)($kycMerge['permanent_address'] ?? ''));
                        $ta = trim((string)($kycMerge['temporary_address'] ?? ''));
                        $address = $ta !== '' ? ($pa !== '' ? $pa . ' / ' . $ta : $ta) : $pa;
                    }
                }
            }
        }

        $validClaimTypes = ['maternity', 'death', 'insurance', 'medical', 'accident', 'other'];

        // Validation
        if (!$error && (empty($member_name) || empty($phone) || empty($claim_type))) {
            $error = isEnglish() ? 'Please fill all required fields.' : 'कृपया सबै आवश्यक फिल्डहरू भर्नुहोस्।';
        } elseif (!$error && !in_array($claim_type, $validClaimTypes, true)) {
            $error = isEnglish() ? 'Please select a valid claim type.' : 'कृपया मान्य दाबी प्रकार छान्नुहोस्।';
        } elseif (!$error) {
            try {
                $submit = submitWelfareClaimUnified($db, [
                    'member_name' => $member_name,
                    'member_id' => $member_id,
                    'member_portal_id' => $member_portal_id,
                    'phone' => $phone,
                    'email' => $email,
                    'address' => $address,
                    'claim_type' => $claim_type,
                    'beneficiary_name' => $beneficiary_name,
                    'beneficiary_relation' => $beneficiary_relation,
                    'claim_amount' => $claim_amount,
                    'description' => $description,
                    'deceased_name' => $deceased_name,
                    'deceased_relation' => $deceased_relation,
                    'death_date' => $death_date ?: null,
                    'delivery_date' => $delivery_date ?: null,
                    'hospital_name' => $hospital_name,
                    'disease_illness' => $disease_illness,
                    'treatment_date' => $treatment_date ?: null,
                    'hospital_clinic' => $hospital_clinic,
                    'policy_number' => $policy_number ?: null,
                    'insurer_name' => $insurer_name ?: null,
                ], $_FILES);
                $trackingId = $submit['tracking_id'];
                $claimTypeNp = $submit['claim_type_np'];

                $success = true;
                logSecurityEvent('welfare_claim_submitted', 'Welfare claim submitted: ' . $claim_type . ' (Tracking: ' . $trackingId . ')');

                // ---- Member SMS confirmation ----
                if (!empty($phone)) {
                    try {
                        $smsEnabled = getSetting('notify_sms_enabled', '0') === '1';
                        $apiToken   = getSetting('notify_sms_token', '');
                        $senderId   = getSetting('notify_sms_sender_id', 'COOP');
                        if ($smsEnabled && $apiToken) {
                            $smsText = 'आकाश सहकारी: तपाईंको कल्याण दाबी दर्ता भयो। Tracking ID: ' . $trackingId . '. application-tracker.php मा track गर्नुहोस्।';
                            $smsText = mb_substr($smsText, 0, 160);
                            $ph = preg_replace('/[^0-9]/', '', $phone);
                            if (strlen($ph) >= 10) {
                                $ch = curl_init('http://api.sparrowsms.com/v2/sms/');
                                curl_setopt_array($ch, [
                                    CURLOPT_POST           => true,
                                    CURLOPT_POSTFIELDS     => http_build_query(['token'=>$apiToken,'from'=>$senderId,'to'=>$ph,'text'=>$smsText]),
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_TIMEOUT        => 10,
                                    CURLOPT_SSL_VERIFYPEER => true,
                                ]);
                                curl_exec($ch);
                                curl_close($ch);
                            }
                        }
                    } catch (Exception $ignored) {}
                }

                // ---- Admin notification (email) ----
                try {
                    sendAdminNotification('welfare_claim', [
                        'नाम'         => $member_name,
                        'फोन'         => $phone,
                        'इमेल'        => $email ?: '—',
                        'दाबी प्रकार' => $claimTypeNp,
                        'Tracking ID' => $trackingId,
                    ], $trackingId);
                } catch (Exception $ignored) {}

            } catch (Exception $e) {
                $error = isEnglish() ? 'Failed to submit claim: ' . $e->getMessage() : 'दाबी दर्ता गर्न सकिएन: ' . $e->getMessage();
            }
        }
    }
}

$claimTypes = [
    'maternity' => ['np' => 'सुत्केरी सुविधा', 'en' => 'Maternity Benefit', 'icon' => 'fa-baby', 'color' => '#e91e63'],
    'death' => ['np' => 'मृत्यु सुविधा', 'en' => 'Death Benefit', 'icon' => 'fa-heart-broken', 'color' => '#607d8b'],
    'insurance' => ['np' => 'बीमा दाबी', 'en' => 'Insurance Claim', 'icon' => 'fa-shield-alt', 'color' => '#2196f3'],
    'medical' => ['np' => 'उपचार खर्च', 'en' => 'Medical Expense', 'icon' => 'fa-hospital', 'color' => '#4caf50'],
    'accident' => ['np' => 'दुर्घटना सुविधा', 'en' => 'Accident Benefit', 'icon' => 'fa-car-burst', 'color' => '#f59e0b'],
    'other' => ['np' => 'अन्य सुविधा', 'en' => 'Other Benefit', 'icon' => 'fa-gift', 'color' => '#ff9800']
];
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><i class="fas fa-hand-holding-heart"></i> <?php echo $pageTitle; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $pageTitle; ?></li>
            </ol>
        </nav>
    </div>
</section>

<section class="section-padding">
    <div class="container">

        <?php if ($success): ?>
        <!-- Success Message -->
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="success-card form-success-card text-center">
                    <div class="success-icon form-success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3><?php echo isEnglish() ? 'Claim Submitted Successfully!' : 'दाबी सफलतापूर्वक दर्ता भयो!'; ?></h3>
                    <div class="form-tracking-box">
                        <div class="text-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID — save this!' : 'तपाईंको Tracking ID — सुरक्षित राख्नुहोस्!'; ?></div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="form-tracking-id" id="wlfTrkId"><?php echo e($trackingId); ?></div>
                            <button type="button" onclick="copyTrk('wlfTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2" title="Copy" style="font-size:11px;line-height:1.8;"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
                        </div>
                        <div class="form-tracking-help"><a href="application-tracker.php" class="text-success text-decoration-none fw-semibold">यहाँ बाट</a> Application Tracker मा स्थिति हेर्नुहोस्।</div>
                    </div>
                    <div class="action-buttons">
                        <a href="member-welfare.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> <?php echo isEnglish() ? 'New Claim' : 'नयाँ दाबी'; ?>
                        </a>
                        <a href="application-tracker.php" class="btn btn-outline-primary">
                            <i class="fas fa-search"></i> <?php echo isEnglish() ? 'Track Application' : 'आवेदन ट्र्याक'; ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>

        <!-- Error Message -->
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column - Claim Types Info -->
            <div class="col-lg-4 mb-4">
                <div class="claim-types-sidebar">
                    <h5><i class="fas fa-list"></i> <?php echo isEnglish() ? 'Available Benefits' : 'उपलब्ध सुविधाहरू'; ?></h5>
                    <div class="claim-types-list">
                        <?php foreach ($claimTypes as $key => $type): ?>
                        <div class="claim-type-item" data-type="<?php echo $key; ?>" onclick="selectClaimType('<?php echo $key; ?>')">
                            <div class="type-icon" style="background-color: <?php echo $type['color']; ?>">
                                <i class="fas <?php echo $type['icon']; ?>"></i>
                            </div>
                            <div class="type-info">
                                <h6><?php echo isEnglish() ? $type['en'] : $type['np']; ?></h6>
                                <small><?php echo isEnglish() ? $type['np'] : $type['en']; ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Track Claim -->
                    <div class="track-claim-box mt-4">
                        <h5><i class="fas fa-search"></i> <?php echo isEnglish() ? 'Track Your Claim' : 'दाबी ट्र्याक गर्नुहोस्'; ?></h5>
                        <form method="GET" action="">
                            <div class="input-group">
                                <input type="text" name="track" class="form-control" placeholder="<?php echo isEnglish() ? 'Tracking ID / Phone' : 'ट्र्याकिङ ID / फोन'; ?>" value="<?php echo e($_GET['track'] ?? ''); ?>">
                                <button type="submit" class="btn btn-primary" aria-label="Search" title="Search"><i class="fas fa-search"></i></button>
                            </div>
                        </form>

                        <?php if ($trackingResult): ?>
                        <div class="tracking-result mt-3">
                            <div class="result-header">
                                <?php
                                $status = (string)($trackingResult['status'] ?? '');
                                $statusBgMap = [
                                    'pending' => 'warning',
                                    'under_review' => 'info',
                                    'approved' => 'success',
                                    'rejected' => 'danger',
                                    'paid' => 'primary',
                                    'completed' => 'success',
                                ];
                                $statusLabelMap = [
                                    'pending' => isEnglish() ? 'Pending' : 'पेन्डिङ',
                                    'under_review' => isEnglish() ? 'Under Review' : 'समीक्षाधीन',
                                    'approved' => isEnglish() ? 'Approved' : 'स्वीकृत',
                                    'rejected' => isEnglish() ? 'Rejected' : 'अस्वीकृत',
                                    'paid' => isEnglish() ? 'Paid' : 'भुक्तान भयो',
                                    'completed' => isEnglish() ? 'Completed' : 'सम्पन्न',
                                ];
                                $statusBg = $statusBgMap[$status] ?? 'secondary';
                                $statusLabel = $statusLabelMap[$status] ?? $status;
                                ?>
                                <span class="badge bg-<?php echo $statusBg; ?>">
                                    <?php echo $statusLabel; ?>
                                </span>
                            </div>
                            <table class="table table-sm mt-2">
                                <tr>
                                    <th><?php echo isEnglish() ? 'Tracking ID' : 'ट्र्याकिङ ID'; ?></th>
                                    <td><?php echo $trackingResult['tracking_id']; ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo isEnglish() ? 'Claim Type' : 'दाबी प्रकार'; ?></th>
                                    <td><?php echo $trackingResult['claim_type_np'] ?? ucfirst($trackingResult['claim_type']); ?></td>
                                </tr>
                                <tr>
                                    <th><?php echo isEnglish() ? 'Amount' : 'रकम'; ?></th>
                                    <td>रू. <?php echo number_format($trackingResult['claim_amount'], 2); ?></td>
                                </tr>
                                <?php if ($trackingResult['approved_amount']): ?>
                                <tr>
                                    <th><?php echo isEnglish() ? 'Approved' : 'स्वीकृत'; ?></th>
                                    <td class="text-success fw-bold">रू. <?php echo number_format($trackingResult['approved_amount'], 2); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th><?php echo isEnglish() ? 'Date' : 'मिति'; ?></th>
                                    <td><?php echo date('Y-m-d', strtotime($trackingResult['created_at'])); ?></td>
                                </tr>
                                <?php if ($trackingResult['admin_remarks']): ?>
                                <tr>
                                    <th><?php echo isEnglish() ? 'Remarks' : 'टिप्पणी'; ?></th>
                                    <td><?php echo nl2br(e($trackingResult['admin_remarks'])); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                        <?php elseif (isset($_GET['track']) && !empty($_GET['track'])): ?>
                        <div class="alert alert-warning mt-3 mb-0">
                            <small><i class="fas fa-exclamation-triangle"></i> <?php echo isEnglish() ? 'No claim found.' : 'दाबी फेला परेन।'; ?></small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column - Claim Form -->
            <div class="col-lg-8">
                <div class="claim-form-card">
                    <div class="form-header text-center mb-4">
                        <div class="form-icon"><i class="fas fa-hand-holding-heart"></i></div>
                        <h3><?php echo isEnglish() ? 'Submit Welfare Claim' : 'कल्याण दाबी पेश गर्नुहोस्'; ?></h3>
                        <p><?php echo isEnglish() ? 'Fill the form below to submit your welfare claim' : 'तल दिइएको फारम भर्नुहोस्'; ?></p>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="needs-validation claim-form" id="welfareClaimForm" novalidate>
                        <?php echo csrfField(); ?>
                        <?php if ($loggedMember): ?>
                        <div class="alert alert-success py-2 small mb-3">
                            <i class="fas fa-user-check me-1"></i><?php echo isEnglish() ? 'Logged in — member details from profile / KYC.' : 'लगइन — सदस्य विवरण प्रोफाइल / KYC बाट।'; ?>
                        </div>
                        <?php else: ?>
                        <div class="border rounded-3 p-3 mb-3 bg-light">
                            <label class="form-label fw-semibold d-block mb-2"><?php echo isEnglish() ? 'Cooperative member?' : 'सहकारी सदस्य?'; ?></label>
                            <div class="d-flex flex-wrap gap-3">
                                <label class="form-check-label"><input type="radio" name="is_coop_member" value="no" class="form-check-input me-1 js-wlf-coop" <?php echo (($_POST['is_coop_member'] ?? 'no') === 'yes') ? '' : 'checked'; ?>> <?php echo isEnglish() ? 'No' : 'होइन'; ?></label>
                                <label class="form-check-label"><input type="radio" name="is_coop_member" value="yes" class="form-check-input me-1 js-wlf-coop" <?php echo (($_POST['is_coop_member'] ?? '') === 'yes') ? 'checked' : ''; ?>> <?php echo isEnglish() ? 'Yes (KYC triple)' : 'हो (KYC तीन विवरण)'; ?></label>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Claim Type Selection -->
                        <div class="form-section">
                            <h5><i class="fas fa-tags"></i> <?php echo isEnglish() ? 'Claim Type' : 'दाबी प्रकार'; ?> <span class="text-danger">*</span></h5>
                            <div class="claim-type-selector">
                                <?php foreach ($claimTypes as $key => $type): ?>
                                <label class="type-option">
                                    <input type="radio" name="claim_type" value="<?php echo $key; ?>" <?php echo ($_POST['claim_type'] ?? '') === $key ? 'checked' : ''; ?> required onchange="showTypeFields('<?php echo $key; ?>')">
                                    <span class="type-box" style="--type-color: <?php echo $type['color']; ?>">
                                        <i class="fas <?php echo $type['icon']; ?>"></i>
                                        <span><?php echo isEnglish() ? $type['en'] : $type['np']; ?></span>
                                    </span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Member Information -->
                        <div class="form-section">
                            <h5><i class="fas fa-user"></i> <?php echo isEnglish() ? 'Member Information' : 'सदस्य जानकारी'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3 js-wlf-name-wrap">
                                    <label class="form-label"><?php echo isEnglish() ? 'Full Name' : 'पूरा नाम'; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="member_name" class="form-control js-wlf-nameonly" required value="<?php echo e($_POST['member_name'] ?? ($loggedMember['name'] ?? '')); ?>" <?php echo $lockedMemberFields; ?>>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Member ID' : 'सदस्य नं.'; ?> <span class="text-danger js-wlf-mid-req" style="display:none;">*</span><span class="text-muted small js-wlf-mid-opt">(<?php echo isEnglish() ? 'optional' : 'ऐच्छिक'; ?>)</span></label>
                                    <input type="text" name="member_id" class="form-control js-wlf-mid" value="<?php echo e($_POST['member_id'] ?? ($loggedMember['sadasyata_number'] ?? '')); ?>" placeholder="<?php echo isEnglish() ? 'If available' : 'भएमा'; ?>" <?php echo $lockedMemberFields; ?>>
                                </div>
                                <div class="col-md-6 mb-3 js-hide-if-wlf-coop-yes">
                                    <label class="form-label"><?php echo isEnglish() ? 'Phone' : 'फोन'; ?> <span class="text-danger">*</span></label>
                                    <input type="tel" name="phone" class="form-control js-wlf-triple" required maxlength="15" value="<?php echo e($_POST['phone'] ?? ($loggedMember['phone'] ?? '')); ?>" <?php echo $lockedMemberFields; ?>>
                                </div>
                                <div class="col-md-6 mb-3 js-hide-if-wlf-coop-yes">
                                    <label class="form-label"><?php echo isEnglish() ? 'Email' : 'इमेल'; ?> <span class="text-danger js-wlf-email-req">*</span></label>
                                    <input type="email" name="email" class="form-control js-wlf-triple" value="<?php echo e($_POST['email'] ?? ($loggedMember['email'] ?? '')); ?>" <?php echo $lockedMemberFields; ?>>
                                </div>
                                <div class="col-12 mb-3 js-wlf-addr-wrap">
                                    <label class="form-label"><?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?></label>
                                    <input type="text" name="address" class="form-control js-wlf-addronly" value="<?php echo e($_POST['address'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <?php if (!$loggedMember): ?>
                        <script>
                        (function(){
                          function syncWlfCoop(){
                            var f=document.getElementById('welfareClaimForm'); if(!f) return;
                            var yes=f.querySelector('input.js-wlf-coop[value=yes]:checked');
                            var nameWrap=f.querySelector('.js-wlf-name-wrap');
                            var addrWrap=f.querySelector('.js-wlf-addr-wrap');
                            var hidden=f.querySelectorAll('.js-hide-if-wlf-coop-yes');
                            var triple=f.querySelectorAll('.js-wlf-triple');
                            var nm=f.querySelector('.js-wlf-nameonly');
                            var em=f.querySelector('.js-wlf-email-req');
                            var mid=f.querySelector('input.js-wlf-mid');
                            var midReq=f.querySelector('.js-wlf-mid-req');
                            var midOpt=f.querySelector('.js-wlf-mid-opt');
                            if(yes){
                              hidden.forEach(function(el){el.style.display='none';});
                              if(nameWrap) nameWrap.style.display='none';
                              if(addrWrap) addrWrap.style.display='none';
                              if(nm) nm.removeAttribute('required');
                              triple.forEach(function(el){ el.setAttribute('required','required'); });
                              if(mid) mid.setAttribute('required','required');
                              if(midReq) midReq.style.display='';
                              if(midOpt) midOpt.style.display='none';
                              if(em) em.style.display='';
                            }else{
                              hidden.forEach(function(el){el.style.display='';});
                              if(nameWrap) nameWrap.style.display='';
                              if(addrWrap) addrWrap.style.display='';
                              if(nm) nm.setAttribute('required','required');
                              triple.forEach(function(el){
                                if(el.name==='phone') el.setAttribute('required','required');
                                else el.removeAttribute('required');
                              });
                              if(mid) mid.removeAttribute('required');
                              if(midReq) midReq.style.display='none';
                              if(midOpt) midOpt.style.display='';
                              if(em) em.style.display='none';
                            }
                          }
                          document.querySelectorAll('#welfareClaimForm input.js-wlf-coop').forEach(function(r){ r.addEventListener('change',syncWlfCoop); });
                          document.addEventListener('DOMContentLoaded',syncWlfCoop);
                        })();
                        </script>
                        <?php endif; ?>

                        <!-- Maternity Fields -->
                        <div class="form-section type-fields" id="maternity-fields" style="display:none;">
                            <h5><i class="fas fa-baby"></i> <?php echo isEnglish() ? 'Maternity Details' : 'सुत्केरी विवरण'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Delivery Date' : 'प्रसूति मिति'; ?></label>
                                    <input type="date" name="delivery_date" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Hospital/Clinic' : 'अस्पताल/क्लिनिक'; ?></label>
                                    <input type="text" name="hospital_name" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- Death Claim Fields -->
                        <div class="form-section type-fields" id="death-fields" style="display:none;">
                            <h5><i class="fas fa-heart-broken"></i> <?php echo isEnglish() ? 'Death Claim Details' : 'मृत्यु दाबी विवरण'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Deceased Name' : 'मृतकको नाम'; ?></label>
                                    <input type="text" name="deceased_name" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Relation' : 'नाता'; ?></label>
                                    <select name="deceased_relation" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select' : 'छान्नुहोस्'; ?></option>
                                        <option value="self"><?php echo isEnglish() ? 'Self (Member)' : 'आफैं (सदस्य)'; ?></option>
                                        <option value="spouse"><?php echo isEnglish() ? 'Spouse' : 'पति/पत्नी'; ?></option>
                                        <option value="parent"><?php echo isEnglish() ? 'Parent' : 'अभिभावक'; ?></option>
                                        <option value="child"><?php echo isEnglish() ? 'Child' : 'छोरा/छोरी'; ?></option>
                                        <option value="other"><?php echo isEnglish() ? 'Other' : 'अन्य'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Death Date' : 'मृत्यु मिति'; ?></label>
                                    <input type="date" name="death_date" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Death Certificate' : 'मृत्यु प्रमाणपत्र'; ?></label>
                                    <input type="file" name="death_certificate" class="form-control" accept="image/*,.pdf">
                                </div>
                            </div>
                        </div>

                        <!-- Medical Fields -->
                        <div class="form-section type-fields" id="medical-fields" style="display:none;">
                            <h5><i class="fas fa-notes-medical"></i> <?php echo isEnglish() ? 'Medical Claim Details' : 'उपचार दाबी विवरण'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Disease / Illness' : 'रोग / समस्या'; ?></label>
                                    <input type="text" name="disease_illness" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Treatment Date' : 'उपचार मिति'; ?></label>
                                    <input type="date" name="treatment_date" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Hospital / Clinic' : 'अस्पताल / क्लिनिक'; ?></label>
                                    <input type="text" name="hospital_clinic" class="form-control">
                                </div>
                            </div>
                        </div>

                        <!-- Common Claim Details -->
                        <div class="form-section" id="beneficiary-fields" style="display:none;">
                            <h5><i class="fas fa-user-friends"></i> <?php echo isEnglish() ? 'Beneficiary Details' : 'लाभग्राही विवरण'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Beneficiary Name' : 'लाभग्राही नाम'; ?></label>
                                    <input type="text" name="beneficiary_name" class="form-control" value="<?php echo e($_POST['beneficiary_name'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Relation' : 'नाता'; ?></label>
                                    <input type="text" name="beneficiary_relation" class="form-control" value="<?php echo e($_POST['beneficiary_relation'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h5><i class="fas fa-file-invoice-dollar"></i> <?php echo isEnglish() ? 'Claim Summary' : 'दाबी सारांश'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Claim Amount (Rs.)' : 'दाबी रकम (रु.)'; ?></label>
                                    <input type="number" name="claim_amount" class="form-control" min="0" step="0.01" value="<?php echo e($_POST['claim_amount'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Supporting Documents' : 'सहयोगी कागजात'; ?></label>
                                    <input type="file" name="documents[]" class="form-control" multiple accept="image/*,.pdf,.doc,.docx">
                                </div>
                                <div class="col-12 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Description' : 'विवरण'; ?></label>
                                    <textarea name="description" class="form-control" rows="3"><?php echo e($_POST['description'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-paper-plane me-2"></i><?php echo isEnglish() ? 'Submit Claim' : 'दाबी पेश गर्नुहोस्'; ?>
                            </button>
                            <div class="form-submit-hint"><?php echo isEnglish() ? 'You will receive a tracking ID after submission.' : 'पेश गरेपछि Tracking ID प्राप्त हुन्छ।'; ?></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>


<script>
function selectClaimType(type) {
    document.querySelector('input[name="claim_type"][value="' + type + '"]').checked = true;
    showTypeFields(type);

    // Highlight selected type in sidebar
    document.querySelectorAll('.claim-type-item').forEach(item => {
        item.classList.remove('active');
    });
    document.querySelector('.claim-type-item[data-type="' + type + '"]').classList.add('active');
}

function showTypeFields(type) {
    // Hide all type-specific fields
    document.querySelectorAll('.type-fields').forEach(el => {
        el.style.display = 'none';
    });

    // Show fields based on type
    switch(type) {
        case 'maternity':
            document.getElementById('maternity-fields').style.display = 'block';
            break;
        case 'death':
            document.getElementById('death-fields').style.display = 'block';
            document.getElementById('beneficiary-fields').style.display = 'block';
            break;
        case 'medical':
            document.getElementById('medical-fields').style.display = 'block';
            break;
        case 'insurance':
            // Can show insurance-specific fields if needed
            break;
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    var checkedType = document.querySelector('input[name="claim_type"]:checked');
    if (checkedType) {
        showTypeFields(checkedType.value);
    }
});
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
