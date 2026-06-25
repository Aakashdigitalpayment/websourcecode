<?php
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
ensurePublicTables();
require_once 'includes/kyc-public-form.php';
$pageTitle = isEnglish() ? 'Member Survey & Feedback' : 'सदस्य सुझाव तथा प्रतिक्रिया';

$success    = false;
$error      = '';
$trackingId = '';
$L          = getLangStrings();
$loggedMember = getLoggedInMemberProfile();
$lockedMemberFields = $loggedMember ? 'readonly' : '';

/* =============================================
   फारम submit भएमा process गर्नुहोस्
   ============================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed. Please try again.' : 'सुरक्षा जाँच असफल। कृपया पुन: प्रयास गर्नुहोस्।';
    } elseif (!checkRateLimit('feedback_form', 3, 60)) {
        $error = isEnglish() ? 'Too many requests. Please wait a moment.' : 'धेरै अनुरोधहरू। कृपया केही समय पर्खनुहोस्।';
    } else {
        $name      = clean_text($_POST['name']      ?? '', 120);
        $member_id = clean_text($_POST['member_id'] ?? '', 50);
        $phone     = preg_replace('/[^0-9]/', '', clean_text($_POST['phone']     ?? '', 20));
        $email     = strtolower(clean_text($_POST['email']     ?? '', 120));
        $typeRaw   = clean_text($_POST['type']      ?? 'feedback', 20);
        $type      = in_array($typeRaw, ['feedback', 'suggestion', 'complaint', 'inquiry'], true) ? $typeRaw : 'feedback';
        $subject   = clean_text($_POST['subject']   ?? '', 255);
        $message   = clean_text($_POST['message']   ?? '', 8000);

        $db = getDB();
        $isCoopMember = $loggedMember ? 'yes' : ((($_POST['is_coop_member'] ?? '') === 'yes') ? 'yes' : 'no');
        $kycMerge = null;
        if ($loggedMember) {
            $kycMerge = loadKycRowForLoggedMemberPublic($db, $loggedMember);
            if (!$kycMerge || strtolower(trim((string)($kycMerge['status'] ?? ''))) !== 'approved') {
                $error = isEnglish() ? 'KYC verification is required for suggestions/feedback.' : 'सुझाव/प्रतिक्रिया पठाउन KYC verified (approved) हुनुपर्छ।';
            }
            $fnK = (is_array($kycMerge) && !empty($kycMerge['full_name'])) ? trim((string)$kycMerge['full_name']) : '';
            $name = $fnK !== '' ? $fnK : trim((string)($loggedMember['name'] ?? $name));
            $midK = (is_array($kycMerge) && !empty($kycMerge['member_id'])) ? trim((string)$kycMerge['member_id']) : '';
            $member_id = $midK !== '' ? $midK : trim((string)($loggedMember['sadasyata_number'] ?? $member_id));
            $phone = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $loggedMember['phone'] ?? $phone));
            $email = strtolower(trim((string)($kycMerge['email'] ?? $loggedMember['email'] ?? $email)));
        } elseif ($isCoopMember === 'yes') {
            $v = verifyPublicFormKycApprovedByMemberId($db, $_POST['member_id'] ?? '');
            if (!$v['ok']) {
                $error = isEnglish() ? $v['msg_en'] : $v['msg_np'];
            } else {
                $kycMerge = $v['row'];
                $name = trim((string)($kycMerge['full_name'] ?? ''));
                $member_id = strtoupper(trim((string)($kycMerge['member_id'] ?? $_POST['member_id'] ?? '')));
                $phone = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $_POST['phone'] ?? ''));
                $email = strtolower(trim((string)($kycMerge['email'] ?? $_POST['email'] ?? '')));
            }
        }

        if (!$error && (empty($name) || empty($phone) || empty($message))) {
            $error = isEnglish() ? 'Please fill in all required fields.' : 'कृपया सबै आवश्यक फिल्डहरू भर्नुहोस्।';
        } elseif (!$error && !empty($email) && !isValidEmail($email)) {
            $error = isEnglish() ? 'Please enter a valid email.' : 'कृपया सही इमेल ठेगाना राख्नुहोस्।';
        } elseif (!$error) {
            try {

                /* ── Tracking ID बनाउँछु — FBK-YYYY-XXXXXX format ──
                   जस्तै: FBK-2082-A3K9X1
                   यो number दिएर application-tracker.php मा पनि खोज्न सकिन्छ */
                $yearBs   = date('Y') + 57; /* approximate BS year */
                $randPart = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
                $newTrackingId = 'FBK-' . $yearBs . '-' . $randPart;

                $stmt = $db->prepare("INSERT INTO member_feedback
                    (tracking_id, name, member_id, phone, email, type, subject, message)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$newTrackingId, $name, $member_id, $phone, $email, $type, $subject, $message]);

                $success    = true;
                $trackingId = $newTrackingId;
                logSecurityEvent('feedback_form', 'Feedback submitted by: ' . $name . ' (' . $type . ') — Tracking: ' . $newTrackingId);
            } catch (Exception $e) {
                $error = isEnglish() ? 'An error occurred. Please try again.' : 'त्रुटि भयो। कृपया पुन: प्रयास गर्नुहोस्।';
            }
        }
    }
}

require_once 'includes/header.php';
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Member Survey & Feedback' : 'सदस्य सुझाव तथा प्रतिक्रिया'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Survey & Feedback' : 'सुझाव तथा प्रतिक्रिया'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<?php if ($success): ?>
<section class="form-success-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7 form-success-card">
                <div class="form-success-icon"><i class="fas fa-star-of-life"></i></div>
                <h3 class="mt-3 fw-bold text-success"><?php echo isEnglish() ? 'Survey Submitted Successfully!' : 'सर्वेक्षण सफलतापूर्वक पेश भयो!'; ?></h3>
                <p class="text-muted mb-4"><?php echo isEnglish() ? 'Thank you for your valuable feedback!' : 'तपाईंको बहुमूल्य प्रतिक्रियाको लागि धन्यवाद!'; ?></p>
                <?php if ($trackingId): ?>
                <div class="form-tracking-box mb-3">
                    <div class="text-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID — save this!' : 'तपाईंको Tracking ID — सुरक्षित राख्नुहोस्!'; ?></div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="form-tracking-id" id="srvTrkId"><?php echo e($trackingId); ?></div>
                        <button type="button" onclick="copyTrk('srvTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2" title="Copy" style="font-size:11px;line-height:1.8;"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
                    </div>
                    <div class="form-tracking-help"><a href="application-tracker.php" class="text-success text-decoration-none fw-semibold">यहाँ बाट</a> Application Tracker मा स्थिति हेर्नुहोस्।</div>
                </div>
                <?php endif; ?>
                <a href="application-tracker.php" class="btn btn-success px-4 me-2"><i class="fas fa-search me-1"></i><?php echo isEnglish() ? 'Track Status' : 'स्थिति ट्र्याक'; ?></a>
                <a href="member-survey.php" class="btn btn-outline-primary px-4 me-2"><i class="fas fa-plus me-1"></i><?php echo isEnglish() ? 'New Survey' : 'नयाँ सर्वेक्षण'; ?></a>
                <a href="<?php echo SITE_URL; ?>" class="btn btn-outline-secondary px-4"><i class="fas fa-home me-1"></i><?php echo isEnglish() ? 'Home' : 'गृहपृष्ठ'; ?></a>
            </div>
        </div>
    </div>
</section>
<?php else: ?>
<!-- Page Content -->
<section class="section-padding">
    <div class="container">

        <!-- Hero / Introduction -->
        <div class="row justify-content-center mb-5">
            <div class="col-lg-8 text-center">
                <div class="section-header text-center mb-3">
                    <h2><?php echo isEnglish() ? 'Your Voice Matters' : 'तपाईंको आवाज महत्वपूर्ण छ'; ?></h2>
                </div>
                <p class="text-muted mb-4">
                    <?php echo isEnglish()
                        ? 'We value your feedback and are committed to improving our services. Share your experience, suggestions, or concerns with us.'
                        : 'हामी तपाईंको प्रतिक्रियालाई महत्व दिन्छौं र हाम्रो सेवाहरू सुधार गर्न प्रतिबद्ध छौं। आफ्नो अनुभव, सुझाव वा चिन्ता हामीसँग साझा गर्नुहोस्।'; ?>
                </p>

                <?php if ($error): ?>
                <!-- ❌ Error — page उपर नै देखिन्छ -->
                <div class="alert alert-danger d-inline-flex align-items-center gap-2 px-4 py-3 rounded-3 mb-3" role="alert">
                    <i class="fas fa-exclamation-circle fs-5"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div><br>
                <?php endif; ?>

                <?php if (!$success): ?>
                <!-- CTA button — inline form section मा जान्छ -->
                <a href="#surveyModal" class="btn btn-primary btn-lg px-5 <?php echo $error ? 'mt-2' : ''; ?>">
                    <i class="fas fa-comments me-2"></i>
                    <?php echo isEnglish() ? 'Submit Feedback' : 'प्रतिक्रिया पठाउनुहोस्'; ?>
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Feature Cards -->
        <div class="row g-4 mb-5">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm text-center p-4 h-100">
                    <div class="mb-3" style="font-size:2.5rem;color:var(--primary-color);"><i class="fas fa-lightbulb"></i></div>
                    <h5><?php echo isEnglish() ? 'Suggestions' : 'सुझाव'; ?></h5>
                    <p class="text-muted small">
                        <?php echo isEnglish() ? 'Help us improve with your innovative ideas.' : 'तपाईंको नवीन विचारले हामीलाई सुधार गर्न मद्दत गर्छ।'; ?>
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm text-center p-4 h-100">
                    <div class="mb-3" style="font-size:2.5rem;color:var(--secondary-color,#ffc107);"><i class="fas fa-star"></i></div>
                    <h5><?php echo isEnglish() ? 'Rate Services' : 'सेवा मूल्यांकन'; ?></h5>
                    <p class="text-muted small">
                        <?php echo isEnglish() ? 'Rate our services and help us serve you better.' : 'हाम्रा सेवाहरू मूल्यांकन गर्नुहोस् र हामीलाई राम्रो सेवा दिन मद्दत गर्नुहोस्।'; ?>
                    </p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm text-center p-4 h-100">
                    <div class="mb-3" style="font-size:2.5rem;color:var(--danger,#dc3545);"><i class="fas fa-exclamation-circle"></i></div>
                    <h5><?php echo isEnglish() ? 'Suggestion Box' : 'सुझाव बक्स'; ?></h5>
                    <p class="text-muted small">
                        <?php echo isEnglish() ? 'Let us know about any service issues you faced.' : 'तपाईंले सामना गरेको सेवा समस्याहरूको बारेमा हामीलाई जानकारी दिनुहोस्।'; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Contact Options -->
        <div class="row g-3">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm text-center p-3">
                    <i class="fas fa-phone-alt fa-2x mb-2" style="color:var(--primary-color);"></i>
                    <h6><?php echo isEnglish() ? 'Call Us' : 'फोन गर्नुहोस्'; ?></h6>
                    <p class="text-muted mb-0 small"><?php echo getSetting('phone', '061590067'); ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm text-center p-3">
                    <i class="fas fa-envelope fa-2x mb-2" style="color:var(--primary-color);"></i>
                    <h6><?php echo isEnglish() ? 'Email Us' : 'इमेल गर्नुहोस्'; ?></h6>
                    <p class="text-muted mb-0 small"><?php echo getSetting('email', 'info@sahakari.org.np'); ?></p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm text-center p-3">
                    <i class="fas fa-map-marker-alt fa-2x mb-2" style="color:var(--primary-color);"></i>
                    <h6><?php echo isEnglish() ? 'Visit Us' : 'भेट्नुहोस्'; ?></h6>
                    <p class="text-muted mb-0 small"><?php echo getSetting('address', 'Kathmandu, Nepal'); ?></p>
                </div>
            </div>
        </div>

    </div>
</section>

<!-- =============================================
     Feedback / Survey Modal — Bootstrap 5
     ============================================= -->
<div class="modal fade show" id="surveyModal" tabindex="-1" aria-labelledby="surveyModalLabel" aria-hidden="false">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">

            <!-- Modal Header -->
            <div class="modal-header" style="background:linear-gradient(135deg,var(--primary-dark),var(--primary-color));color:#fff;">
                <h5 class="modal-title" id="surveyModalLabel">
                    <i class="fas fa-comments me-2"></i>
                    <?php echo isEnglish() ? 'Share Your Feedback' : 'आफ्नो प्रतिक्रिया साझा गर्नुहोस्'; ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4">

                <!-- Error alert -->
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <i class="fas fa-exclamation-circle me-1"></i>
                    <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <!-- Survey Form -->
                <form method="POST" id="surveyForm" class="needs-validation" novalidate>
                    <?php echo csrfField(); ?>
                    <?php if ($loggedMember): ?>
                    <div class="alert alert-success py-2 small mb-3">
                        <i class="fas fa-user-check me-1"></i><?php echo isEnglish() ? 'Logged in — identity from profile / KYC.' : 'लगइन — पहिचान प्रोफाइल / KYC बाट।'; ?>
                    </div>
                    <?php else: ?>
                    <div class="border rounded-3 p-3 mb-3 bg-light">
                        <label class="form-label fw-semibold d-block mb-2"><?php echo isEnglish() ? 'Cooperative member?' : 'सहकारी सदस्य?'; ?></label>
                        <div class="d-flex flex-wrap gap-3">
                            <label class="form-check-label"><input type="radio" name="is_coop_member" value="no" class="form-check-input me-1 js-svy-coop" <?php echo (($_POST['is_coop_member'] ?? 'no') === 'yes') ? '' : 'checked'; ?>> <?php echo isEnglish() ? 'No' : 'होइन'; ?></label>
                            <label class="form-check-label"><input type="radio" name="is_coop_member" value="yes" class="form-check-input me-1 js-svy-coop" <?php echo (($_POST['is_coop_member'] ?? '') === 'yes') ? 'checked' : ''; ?>> <?php echo isEnglish() ? 'Yes (Member ID based KYC)' : 'हो (Member ID आधारित KYC)'; ?></label>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <!-- नाम -->
                        <div class="col-md-6 js-svy-name-wrap">
                            <label class="form-label"><?php echo isEnglish() ? 'Full Name' : 'पूरा नाम'; ?> <span class="req">*</span></label>
                            <input type="text" name="name" class="form-control js-svy-nameonly" required
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ($loggedMember['name'] ?? ''), ENT_QUOTES); ?>"
                                   placeholder="<?php echo isEnglish() ? 'Enter your name' : 'आफ्नो नाम लेख्नुहोस्'; ?>" <?php echo $lockedMemberFields; ?>>
                        </div>
                        <!-- सदस्य नं. -->
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isEnglish() ? 'Member ID' : 'सदस्य नं.'; ?> <span class="text-danger js-svy-mid-req" style="display:none;">*</span><span class="text-muted small js-svy-mid-opt">(<?php echo isEnglish() ? 'optional' : 'ऐच्छिक'; ?>)</span></label>
                            <input type="text" name="member_id" class="form-control js-svy-mid"
                                   value="<?php echo htmlspecialchars($_POST['member_id'] ?? ($loggedMember['sadasyata_number'] ?? ''), ENT_QUOTES); ?>"
                                   placeholder="<?php echo isEnglish() ? 'If you are a member' : 'यदि सदस्य हुनुहुन्छ भने'; ?>" <?php echo $lockedMemberFields; ?>>
                        </div>
                        <!-- फोन -->
                        <div class="col-md-6 js-hide-if-svy-coop-yes">
                            <label class="form-label"><?php echo isEnglish() ? 'Phone Number' : 'फोन नम्बर'; ?> <span class="req">*</span></label>
                            <input type="tel" name="phone" class="form-control js-svy-triple" required maxlength="15"
                                   value="<?php echo htmlspecialchars($_POST['phone'] ?? ($loggedMember['phone'] ?? ''), ENT_QUOTES); ?>"
                                   placeholder="<?php echo isEnglish() ? 'Your phone number' : 'तपाईंको फोन नम्बर'; ?>" <?php echo $lockedMemberFields; ?>>
                        </div>
                        <!-- इमेल -->
                        <div class="col-md-6 js-hide-if-svy-coop-yes">
                            <label class="form-label"><?php echo isEnglish() ? 'Email' : 'इमेल'; ?> <span class="text-danger js-svy-email-req" style="display:none;">*</span></label>
                            <input type="email" name="email" class="form-control js-svy-triple"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ($loggedMember['email'] ?? ''), ENT_QUOTES); ?>"
                                   placeholder="<?php echo isEnglish() ? 'Your email address' : 'तपाईंको इमेल ठेगाना'; ?>" <?php echo $lockedMemberFields; ?>>
                        </div>
                        <!-- प्रकार -->
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isEnglish() ? 'Feedback Type' : 'प्रतिक्रियाको प्रकार'; ?></label>
                            <select name="type" class="form-select">
                                <option value="feedback"   <?php selected($_POST['type'] ?? '', 'feedback');   ?>><?php echo isEnglish() ? 'General Feedback' : 'सामान्य प्रतिक्रिया'; ?></option>
                                <option value="suggestion" <?php selected($_POST['type'] ?? '', 'suggestion'); ?>><?php echo isEnglish() ? 'Suggestion' : 'सुझाव'; ?></option>
                                <option value="complaint"  <?php selected($_POST['type'] ?? '', 'complaint');  ?>><?php echo isEnglish() ? 'Issue / Complaint' : 'सेवा समस्या / उजुरी'; ?></option>
                                <option value="inquiry"    <?php selected($_POST['type'] ?? '', 'inquiry');    ?>><?php echo isEnglish() ? 'Inquiry' : 'सोधपुछ'; ?></option>
                            </select>
                        </div>
                        <!-- विषय -->
                        <div class="col-md-6">
                            <label class="form-label"><?php echo isEnglish() ? 'Subject' : 'विषय'; ?></label>
                            <input type="text" name="subject" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['subject'] ?? '', ENT_QUOTES); ?>"
                                   placeholder="<?php echo isEnglish() ? 'Brief subject' : 'संक्षिप्त विषय'; ?>">
                        </div>
                        <!-- सन्देश -->
                        <div class="col-12">
                            <label class="form-label"><?php echo isEnglish() ? 'Your Message' : 'तपाईंको सन्देश'; ?> <span class="req">*</span></label>
                            <textarea name="message" class="form-control" rows="4" required
                                      placeholder="<?php echo isEnglish() ? 'Write your message in detail...' : 'आफ्नो सन्देश विस्तृतमा लेख्नुहोस्...'; ?>"><?php echo htmlspecialchars($_POST['message'] ?? '', ENT_QUOTES); ?></textarea>
                        </div>
                    </div>
                    <?php if (!$loggedMember): ?>
                    <script>
                    (function(){
                      function syncSvyCoop(){
                        var f=document.getElementById('surveyForm'); if(!f) return;
                        var yes=f.querySelector('input.js-svy-coop[value=yes]:checked');
                        var nameWrap=f.querySelector('.js-svy-name-wrap');
                        var nm=f.querySelector('.js-svy-nameonly');
                        var triple=f.querySelectorAll('.js-svy-triple');
                        var mid=f.querySelector('input.js-svy-mid');
                        var midReq=f.querySelector('.js-svy-mid-req');
                        var midOpt=f.querySelector('.js-svy-mid-opt');
                        var em=f.querySelector('.js-svy-email-req');
                        if(yes){
                          f.querySelectorAll('.js-hide-if-svy-coop-yes').forEach(function(el){el.style.display='none';});
                          if(nameWrap) nameWrap.style.display='none';
                          if(nm) nm.removeAttribute('required');
                          triple.forEach(function(el){ el.setAttribute('required','required'); });
                          if(mid) mid.setAttribute('required','required');
                          if(midReq) midReq.style.display='';
                          if(midOpt) midOpt.style.display='none';
                          if(em) em.style.display='';
                        }else{
                          f.querySelectorAll('.js-hide-if-svy-coop-yes').forEach(function(el){el.style.display='';});
                          if(nameWrap) nameWrap.style.display='';
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
                      document.querySelectorAll('#surveyForm input.js-svy-coop').forEach(function(r){ r.addEventListener('change',syncSvyCoop); });
                      document.addEventListener('DOMContentLoaded',syncSvyCoop);
                    })();
                    </script>
                    <?php endif; ?>

                    <div class="modal-footer px-0 pb-0 mt-4">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> <?php echo isEnglish() ? 'Cancel' : 'रद्द'; ?>
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span><i class="fas fa-paper-plane me-1"></i>
                            <?php echo isEnglish() ? 'Submit Feedback' : 'प्रतिक्रिया पठाउनुहोस्'; ?>
                        </button>
                    </div>
                </form>

            </div><!-- /modal-body -->
        </div>
    </div>
</div>

<!-- JS — POST पछि modal auto-open -->
<script>
(function () {
    var useInlineForm = true;
    if (useInlineForm) return;
    var wasPosted = <?php echo ($_SERVER['REQUEST_METHOD'] === 'POST') ? 'true' : 'false'; ?>;
    if (!wasPosted) return;

    var modalEl = document.getElementById('surveyModal');
    if (!modalEl) return;
    var modal = new bootstrap.Modal(modalEl);

    // Inline form mode मा success UI माथि देखिन्छ, modal toggle आवश्यक छैन।
})();
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
