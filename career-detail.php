<?php
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
ensurePublicTables();

// Get job ID
$jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$jobId) {
    redirect('career.php');
}

// Get job details
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM careers WHERE id = ? AND is_active = 1");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        redirect('career.php');
    }

    $pageTitle = getLangField($job, 'title');
    $descHtml = getLangField($job, 'description');
    $pageDescription = function_exists('seo_meta_description_from_html')
        ? seo_meta_description_from_html($descHtml)
        : '';
    if ($pageDescription === '') {
        $pageDescription = $pageTitle . (isEnglish() ? ' — Career opportunity' : ' — रोजगारी अवसर');
    }
} catch (Exception $e) {
    redirect('career.php');
}

// Check if deadline has passed
$deadlinePassed = !empty($job['deadline']) && strtotime($job['deadline']) < strtotime('today');
$allowOnlineApply = ($job['allow_online_apply'] ?? 1) && !$deadlinePassed;

// Handle form submission
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allowOnlineApply) {
    /* CSRF सुरक्षा जाँच */
    if (!verifyCSRFToken()) {
        setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुन: प्रयास गर्नुहोस्।');
        redirect($_SERVER['PHP_SELF'] . '?slug=' . urlencode($_GET['slug'] ?? ''));
    }
    try {
        $db = getDB();

        // Validate required fields
        $fullName = clean_text($_POST['full_name'] ?? '', 200);
        $email = strtolower(clean_text($_POST['email'] ?? '', 254));
        $phone = preg_replace('/[^0-9]/', '', clean_text($_POST['phone'] ?? '', 20));
        $address = clean_text($_POST['address'] ?? '', 500);
        $dob = clean_text($_POST['date_of_birth'] ?? '', 40);
        $gRaw = clean_text($_POST['gender'] ?? 'male', 20);
        $gender = in_array($gRaw, ['male', 'female', 'other'], true) ? $gRaw : 'male';
        $education = clean_text($_POST['education'] ?? '', 500);
        $experience = clean_text($_POST['experience'] ?? '', 2000);
        $currentEmployer = clean_text($_POST['current_employer'] ?? '', 200);
        $expectedSalary = clean_text($_POST['expected_salary'] ?? '', 80);
        $coverLetter = clean_text($_POST['cover_letter'] ?? '', 8000);

        if (empty($fullName) || empty($email) || empty($phone)) {
            throw new Exception(isEnglish() ? 'Please fill all required fields.' : 'कृपया सबै आवश्यक फिल्डहरू भर्नुहोस्।');
        }

        // Check if already applied
        $checkStmt = $db->prepare("SELECT id FROM job_applications WHERE career_id = ? AND email = ?");
        $checkStmt->execute([$jobId, $email]);
        if ($checkStmt->fetch()) {
            throw new Exception(isEnglish() ? 'You have already applied for this position.' : 'तपाईंले यस पदको लागि पहिले नै आवेदन दिनुभएको छ।');
        }

        // Handle file uploads
        $resumePath = '';
        $photoPath = '';
        $citizenshipPath = '';
        $certificatesPath = '';

        // Upload resume
        if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['resume'], 'job-applications/resumes');
            if ($upload['success']) {
                $resumePath = $upload['path'];
            }
        }

        // Upload photo
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['photo'], 'job-applications/photos');
            if ($upload['success']) {
                $photoPath = $upload['path'];
            }
        }

        // Upload citizenship
        if (isset($_FILES['citizenship']) && $_FILES['citizenship']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['citizenship'], 'job-applications/documents');
            if ($upload['success']) {
                $citizenshipPath = $upload['path'];
            }
        }

        // Upload certificates
        if (isset($_FILES['certificates']) && $_FILES['certificates']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['certificates'], 'job-applications/documents');
            if ($upload['success']) {
                $certificatesPath = $upload['path'];
            }
        }

        // Generate tracking ID
        $trackingId = 'JOB-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

        // Insert application with tracking ID
        $stmt = $db->prepare("INSERT INTO job_applications (career_id, full_name, email, phone, address, date_of_birth, gender, education, experience, current_employer, expected_salary, cover_letter, resume_path, photo_path, citizenship_path, certificates_path, tracking_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $jobId, $fullName, $email, $phone, $address, $dob, $gender,
            $education, $experience, $currentEmployer, $expectedSalary, $coverLetter,
            $resumePath, $photoPath, $citizenshipPath, $certificatesPath, $trackingId
        ]);

        $success = true;
        $successTrackingId = $trackingId;

        /* v3 connection-fix: job application ले admin notification trigger गर्दैनथ्यो। */
        if (file_exists(__DIR__ . '/includes/notifications.php')) {
            require_once __DIR__ . '/includes/notifications.php';
            try {
                sendAdminNotification('job_application', [
                    'पद'         => getLangField($job, 'title'),
                    'आवेदकको नाम' => $fullName,
                    'इमेल'        => $email,
                    'फोन'         => $phone,
                    'योग्यता'     => $education ?: '—',
                    'अनुभव'       => $experience ?: '—',
                ], $trackingId);
            } catch (\Throwable $e) { error_log('career notify: ' . $e->getMessage()); }
        }
        if (function_exists('auditLog')) auditLog('job_apply', 'job_applications', null, null, ['tracking'=>$trackingId,'name'=>$fullName]);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

require_once 'includes/header.php';
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo getLangField($job, 'title'); ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item"><a href="career.php"><?php echo isEnglish() ? 'Career' : 'क्यारियर'; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Details' : 'विवरण'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Job Detail Content -->
<section class="section-padding">
    <div class="container">
        <?php if ($success): ?>
        <div class="text-center py-4 px-3 rounded-4 mb-4 cd-success-wrap">
            <div class="cd-success-icon"><i class="fas fa-check-circle"></i></div>
            <h4 class="mt-2 fw-bold text-success"><?php echo isEnglish() ? 'Application Submitted Successfully!' : 'आवेदन सफलतापूर्वक पेश भयो!'; ?></h4>
            <p class="text-muted mb-3"><?php echo isEnglish() ? 'We will contact you soon.' : 'हामी चाँडै तपाईंलाई सम्पर्क गर्नेछौं।'; ?></p>
            <div class="d-inline-block px-4 py-3 rounded-3 mb-3 cd-success-track-wrap">
                <div class="text-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID — save this!' : 'तपाईंको Tracking ID — सुरक्षित राख्नुहोस्!'; ?></div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div class="fw-bold fs-5 text-success font-monospace" id="jobTrkId"><?php echo e($successTrackingId ?? ''); ?></div>
                    <button type="button" onclick="copyTrk('jobTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2 cd-copy-btn" title="Copy"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
                </div>
                <div class="small text-muted"><a href="application-tracker.php" class="text-success text-decoration-none fw-semibold">यहाँ बाट</a> Application Tracker मा स्थिति हेर्नुहोस्।</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Job Details -->
            <div class="col-lg-8">
                <div class="job-detail-card">
                    <div class="job-detail-header">
                        <div class="job-title-wrap">
                            <h2><?php echo getLangField($job, 'title'); ?></h2>
                            <div class="job-meta">
                                <?php if ($job['department']): ?>
                                <span><i class="fas fa-building"></i> <?php echo $job['department']; ?></span>
                                <?php endif; ?>
                                <?php if ($job['location']): ?>
                                <span><i class="fas fa-map-marker-alt"></i> <?php echo $job['location']; ?></span>
                                <?php endif; ?>
                                <span class="job-type-badge"><?php echo ucfirst(str_replace('-', ' ', $job['job_type'] ?? 'Full Time')); ?></span>
                            </div>
                        </div>
                        <?php if ($deadlinePassed): ?>
                        <span class="deadline-badge expired">
                            <i class="fas fa-times-circle"></i> <?php echo isEnglish() ? 'Deadline Expired' : 'म्याद सकियो'; ?>
                        </span>
                        <?php else: ?>
                        <span class="deadline-badge active">
                            <i class="fas fa-clock"></i> <?php echo isEnglish() ? 'Deadline:' : 'म्याद:'; ?> <?php echo formatDate($job['deadline'], 'Y-m-d'); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="job-detail-body">
                        <?php if ($job['vacancies'] ?? 0): ?>
                        <div class="job-info-row">
                            <span class="label"><?php echo isEnglish() ? 'No. of Vacancies' : 'रिक्त पद संख्या'; ?>:</span>
                            <span class="value"><?php echo $job['vacancies']; ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($job['min_qualification'] ?? ''): ?>
                        <div class="job-info-row">
                            <span class="label"><?php echo isEnglish() ? 'Minimum Qualification' : 'न्यूनतम योग्यता'; ?>:</span>
                            <span class="value"><?php echo $job['min_qualification']; ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($job['experience_required'] ?? ''): ?>
                        <div class="job-info-row">
                            <span class="label"><?php echo isEnglish() ? 'Experience Required' : 'आवश्यक अनुभव'; ?>:</span>
                            <span class="value"><?php echo $job['experience_required']; ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($job['salary_range'] ?? ''): ?>
                        <div class="job-info-row">
                            <span class="label"><?php echo isEnglish() ? 'Salary Range' : 'तलब दायरा'; ?>:</span>
                            <span class="value"><?php echo $job['salary_range']; ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (getLangField($job, 'description')): ?>
                        <div class="job-section">
                            <h4><?php echo isEnglish() ? 'Job Description' : 'कामको विवरण'; ?></h4>
                            <div class="job-content">
                                <?php echo nl2br(getLangField($job, 'description')); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($job['requirements']): ?>
                        <div class="job-section">
                            <h4><?php echo isEnglish() ? 'Requirements' : 'आवश्यकताहरू'; ?></h4>
                            <div class="job-content">
                                <?php echo nl2br($job['requirements']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="job-detail-footer">
                        <?php if (!empty($job['attachment'])): ?>
                        <a href="<?php echo $job['attachment']; ?>" class="btn btn-outline-primary" download>
                            <i class="fas fa-download"></i> <?php echo isEnglish() ? 'Download Details' : 'विवरण डाउनलोड गर्नुहोस्'; ?>
                        </a>
                        <?php endif; ?>

                        <?php if ($allowOnlineApply && !$success): ?>
                        <button type="button" class="btn btn-primary" id="showApplicationFormBtn" onclick="showApplicationForm()">
                            <i class="fas fa-paper-plane"></i> <?php echo isEnglish() ? 'Apply Now' : 'अहिले आवेदन दिनुहोस्'; ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Online Application Form - Hidden by default, shown when "Apply Now" is clicked -->
                <?php if ($allowOnlineApply && !$success): ?>
                <div class="application-form-section" id="apply-form" style="display: none;">
                    <h3><i class="fas fa-file-alt"></i> <?php echo isEnglish() ? 'Online Application Form' : 'अनलाइन आवेदन फारम'; ?></h3>
                    <p class="form-subtitle"><?php echo isEnglish() ? 'Fill out the form below to apply for this position. Fields marked with * are required.' : 'यस पदको लागि आवेदन दिन तलको फारम भर्नुहोस्। * चिन्ह भएका फिल्डहरू अनिवार्य छन्।'; ?></p>

                    <form method="POST" enctype="multipart/form-data" class="needs-validation job-application-form" novalidate>
    <?php echo csrfField(); ?>
                        <div class="form-section">
                            <h5><?php echo isEnglish() ? 'Personal Information' : 'व्यक्तिगत जानकारी'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Full Name' : 'पूरा नाम'; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control" required placeholder="<?php echo isEnglish() ? 'Enter your full name' : 'पूरा नाम लेख्नुहोस्'; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Email Address' : 'इमेल ठेगाना'; ?> <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" required placeholder="akashpame@gmail.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Phone Number' : 'फोन नम्बर'; ?> <span class="text-danger">*</span></label>
                                    <input type="tel" name="phone" class="form-control" required placeholder="98XXXXXXXX">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?></label>
                                    <input type="text" name="address" class="form-control" placeholder="<?php echo isEnglish() ? 'Your current address' : 'हालको ठेगाना'; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Date of Birth (B.S.)' : 'जन्म मिति (बि.सं.)'; ?></label>
                                    <div class="input-group nepali-datepicker-wrapper">
                                        <input type="text" name="date_of_birth" id="dob_nepali" class="form-control nepali-datepicker" placeholder="YYYY-MM-DD">
                                        <span class="input-group-text cursor-pointer" onclick="$(this).siblings('.nepali-datepicker').focus();"><i class="fas fa-calendar-alt"></i></span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Gender' : 'लिङ्ग'; ?></label>
                                    <select name="gender" class="form-select">
                                        <option value="male"><?php echo isEnglish() ? 'Male' : 'पुरुष'; ?></option>
                                        <option value="female"><?php echo isEnglish() ? 'Female' : 'महिला'; ?></option>
                                        <option value="other"><?php echo isEnglish() ? 'Other' : 'अन्य'; ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h5><?php echo isEnglish() ? 'Education & Experience' : 'शिक्षा र अनुभव'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Highest Education' : 'उच्चतम शिक्षा'; ?></label>
                                    <input type="text" name="education" class="form-control" placeholder="<?php echo isEnglish() ? 'e.g., Bachelor in Business' : 'जस्तै: व्यवसायमा स्नातक'; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Years of Experience' : 'अनुभवको वर्ष'; ?></label>
                                    <input type="text" name="experience" class="form-control" placeholder="<?php echo isEnglish() ? 'e.g., 2 years' : 'जस्तै: २ वर्ष'; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Current Employer' : 'हालको रोजगारदाता'; ?></label>
                                    <input type="text" name="current_employer" class="form-control" placeholder="<?php echo isEnglish() ? 'Company name (if employed)' : 'कम्पनीको नाम (कार्यरत भए)'; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Expected Salary' : 'अपेक्षित तलब'; ?></label>
                                    <input type="text" name="expected_salary" class="form-control" placeholder="<?php echo isEnglish() ? 'e.g., Rs. 30,000 - 40,000' : 'जस्तै: रु. ३०,००० - ४०,०००'; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h5><?php echo isEnglish() ? 'Cover Letter' : 'आवेदन पत्र'; ?></h5>
                            <div class="mb-3">
                                <label class="form-label"><?php echo isEnglish() ? 'Why should we hire you?' : 'हामीले तपाईंलाई किन नियुक्त गर्नुपर्छ?'; ?></label>
                                <textarea name="cover_letter" class="form-control" rows="4" placeholder="<?php echo isEnglish() ? 'Write about your skills, experience, and why you are suitable for this position...' : 'तपाईंको सीप, अनुभव, र यस पदको लागि तपाईं किन उपयुक्त हुनुहुन्छ भनेर लेख्नुहोस्...'; ?>"></textarea>
                            </div>
                        </div>

                        <div class="form-section">
                            <h5><?php echo isEnglish() ? 'Document Upload' : 'कागजात अपलोड'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Resume/CV' : 'बायोडाटा/CV'; ?></label>
                                    <input type="file" name="resume" class="form-control" accept=".pdf,.doc,.docx">
                                    <small class="text-muted"><?php echo isEnglish() ? 'PDF, DOC (Max 5MB)' : 'PDF, DOC (अधिकतम ५MB)'; ?></small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Passport Photo' : 'पासपोर्ट साइज फोटो'; ?></label>
                                    <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png">
                                    <small class="text-muted"><?php echo isEnglish() ? 'JPG, PNG (Max 2MB)' : 'JPG, PNG (अधिकतम २MB)'; ?></small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Citizenship Copy' : 'नागरिकताको प्रतिलिपि'; ?></label>
                                    <input type="file" name="citizenship" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                    <small class="text-muted"><?php echo isEnglish() ? 'PDF, JPG, PNG' : 'PDF, JPG, PNG'; ?></small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Certificates' : 'प्रमाणपत्रहरू'; ?></label>
                                    <input type="file" name="certificates" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                    <small class="text-muted"><?php echo isEnglish() ? 'Academic certificates (PDF recommended)' : 'शैक्षिक प्रमाणपत्र (PDF सिफारिश)'; ?></small>
                                </div>
                            </div>
                        </div>

                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" id="agreeTerms" required>
                            <label class="form-check-label" for="agreeTerms">
                                <?php echo isEnglish() ? 'I confirm that all the information provided is accurate and I agree to the terms and conditions.' : 'मैले दिएका सबै जानकारी सही छन् र म नियम र सर्तहरूमा सहमत छु।'; ?>
                            </label>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-paper-plane"></i> <?php echo isEnglish() ? 'Submit Application' : 'आवेदन पेश गर्नुहोस्'; ?>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Job Summary Card -->
                <div class="sidebar-card job-summary">
                    <h4><?php echo isEnglish() ? 'Job Summary' : 'कामको सारांश'; ?></h4>
                    <ul class="summary-list">
                        <li>
                            <i class="fas fa-briefcase"></i>
                            <div>
                                <span class="label"><?php echo isEnglish() ? 'Job Type' : 'काम प्रकार'; ?></span>
                                <span class="value"><?php echo ucfirst(str_replace('-', ' ', $job['job_type'] ?? 'Full Time')); ?></span>
                            </div>
                        </li>
                        <li>
                            <i class="fas fa-map-marker-alt"></i>
                            <div>
                                <span class="label"><?php echo isEnglish() ? 'Location' : 'स्थान'; ?></span>
                                <span class="value"><?php echo $job['location'] ?? 'Head Office'; ?></span>
                            </div>
                        </li>
                        <li>
                            <i class="fas fa-users"></i>
                            <div>
                                <span class="label"><?php echo isEnglish() ? 'Vacancies' : 'रिक्त पद'; ?></span>
                                <span class="value"><?php echo $job['vacancies'] ?? 1; ?></span>
                            </div>
                        </li>
                        <li>
                            <i class="fas fa-calendar-alt"></i>
                            <div>
                                <span class="label"><?php echo isEnglish() ? 'Posted Date' : 'प्रकाशित मिति'; ?></span>
                                <span class="value"><?php echo formatDate($job['created_at'], 'Y-m-d'); ?></span>
                            </div>
                        </li>
                        <li>
                            <i class="fas fa-hourglass-end"></i>
                            <div>
                                <span class="label"><?php echo isEnglish() ? 'Deadline' : 'म्याद'; ?></span>
                                <span class="value <?php echo $deadlinePassed ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo formatDate($job['deadline'], 'Y-m-d'); ?>
                                    <?php if ($deadlinePassed): ?>
                                    <small>(<?php echo isEnglish() ? 'Expired' : 'समाप्त'; ?>)</small>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Share Job Card -->
                <div class="sidebar-card">
                    <h4><?php echo isEnglish() ? 'Share This Job' : 'यो जागिर साझा गर्नुहोस्'; ?></h4>
                    <div class="share-buttons">
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(SITE_URL . 'career-detail.php?id=' . $jobId); ?>" target="_blank" class="share-btn facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode(SITE_URL . 'career-detail.php?id=' . $jobId); ?>&text=<?php echo urlencode(getLangField($job, 'title')); ?>" target="_blank" class="share-btn twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode(SITE_URL . 'career-detail.php?id=' . $jobId); ?>" target="_blank" class="share-btn linkedin">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                        <a href="https://api.whatsapp.com/send?text=<?php echo urlencode(getLangField($job, 'title') . ' - ' . SITE_URL . 'career-detail.php?id=' . $jobId); ?>" target="_blank" class="share-btn whatsapp">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    </div>
                </div>

                <!-- Contact HR Card -->
                <div class="sidebar-card">
                    <div class="sidebar-icon">
                        <i class="fas fa-headset"></i>
                    </div>
                    <h4><?php echo isEnglish() ? 'Need Help?' : 'मद्दत चाहिन्छ?'; ?></h4>
                    <p><?php echo isEnglish() ? 'Contact our HR department for any queries.' : 'कुनै प्रश्नको लागि हाम्रो HR विभागलाई सम्पर्क गर्नुहोस्।'; ?></p>
                    <a href="mailto:<?php echo getSetting('email', 'info@sahakari.org.np'); ?>" class="btn btn-outline-primary btn-block">
                        <i class="fas fa-envelope"></i> <?php echo isEnglish() ? 'Contact HR' : 'HR सम्पर्क'; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>


<script>
// Show application form when "Apply Now" button is clicked
function showApplicationForm() {
    var form = document.getElementById('apply-form');
    var btn = document.getElementById('showApplicationFormBtn');

    if (form) {
        form.style.display = 'block';
        // Hide the Apply Now button
        if (btn) {
            btn.style.display = 'none';
        }
        // Smooth scroll to form
        setTimeout(function() {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 100);

        // Initialize Nepali date picker after form is shown (v5 API)
        setTimeout(function() {
            if (typeof $ !== 'undefined' && typeof $.fn.nepaliDatePicker !== 'undefined') {
                initNepaliDatePicker('#dob_nepali');
            }
        }, 200);
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
