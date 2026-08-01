<?php
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
require_once 'includes/careers-tables.php';
ensurePublicTables();

$jobId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$jobId) {
    redirect('career.php');
}

try {
    $db = getDB();
    ensureCareersTables($db);
    $stmt = $db->prepare('SELECT * FROM careers WHERE id = ? AND is_active = 1');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

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
    $pageOgType = 'article';
    $pageOgImageAlt = $pageTitle;
    $seoBreadcrumbs = [
        ['name' => isEnglish() ? 'Home' : 'गृहपृष्ठ', 'url' => SITE_URL],
        ['name' => isEnglish() ? 'Careers' : 'रोजगारी', 'url' => rtrim(SITE_URL, '/') . '/career.php'],
        ['name' => $pageTitle],
    ];
} catch (Exception $e) {
    redirect('career.php');
}

$deadlinePassed = careerDeadlinePassed($job);
$allowOnlineApply = !empty($job['allow_online_apply']) && !$deadlinePassed;
$deadlineLabel = careerFormatDeadlineDisplay($job['deadline'] ?? null);
$postedLabel = careerFormatDeadlineDisplay(substr((string)($job['created_at'] ?? ''), 0, 10));
$vacancyCount = max(1, (int)($job['vacancies'] ?? $job['vacancy_count'] ?? 1));
$jobTypeLabel = ucwords(str_replace(['_', '-'], ' ', (string)($job['job_type'] ?? 'full_time')));
$showApplyForm = $allowOnlineApply && (
    (isset($_GET['apply']) && $_GET['apply'] !== '0')
);

$success = false;
$error = '';
$successTrackingId = '';

/* PRG success / flash errors */
if (isset($_GET['applied']) && $_GET['applied'] === '1' && !empty($_GET['tid'])) {
    $success = true;
    $successTrackingId = clean_text((string)$_GET['tid'], 60);
}
$flash = function_exists('getFlash') ? getFlash() : null;
if ($flash && ($flash['type'] ?? '') === 'error' && empty($error)) {
    $error = (string)($flash['message'] ?? '');
    $showApplyForm = $allowOnlineApply;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $allowOnlineApply) {
    if (!verifyCSRFToken()) {
        setFlash('error', isEnglish() ? 'Security check failed. Please try again.' : 'सुरक्षा जाँच असफल। कृपया पुन: प्रयास गर्नुहोस्।');
        redirect('career-detail.php?id=' . $jobId . '&apply=1#apply-form');
    }
    if (!checkRateLimit('job_apply', 5, 3600)) {
        $error = isEnglish() ? 'Too many applications. Please try again later.' : 'धेरै आवेदन। पछि प्रयास गर्नुहोस्।';
        $showApplyForm = true;
    } else {
        try {
            $db = getDB();

            $fullName = clean_text($_POST['full_name'] ?? '', 200);
            $email = strtolower(clean_text($_POST['email'] ?? '', 254));
            $phone = preg_replace('/[^0-9]/', '', clean_text($_POST['phone'] ?? '', 20));
            $address = clean_text($_POST['address'] ?? '', 500);
            $dobRaw = clean_text($_POST['date_of_birth'] ?? '', 40);
            $dob = $dobRaw !== '' ? careerNormalizeDate($dobRaw) : '';
            if ($dob === '' && $dobRaw !== '') {
                $dob = preg_match('/^\d{4}-\d{2}-\d{2}/', $dobRaw) ? substr($dobRaw, 0, 10) : '';
            }
            $gRaw = clean_text($_POST['gender'] ?? 'male', 20);
            $gender = in_array($gRaw, ['male', 'female', 'other'], true) ? $gRaw : 'male';
            $education = clean_text($_POST['education'] ?? '', 500);
            $experience = clean_text($_POST['experience'] ?? '', 2000);
            $currentEmployer = clean_text($_POST['current_employer'] ?? '', 200);
            $expectedSalary = clean_text($_POST['expected_salary'] ?? '', 80);
            $coverLetter = clean_text($_POST['cover_letter'] ?? '', 8000);

            if ($fullName === '' || $email === '' || $phone === '') {
                throw new Exception(isEnglish() ? 'Please fill all required fields.' : 'कृपया सबै आवश्यक फिल्डहरू भर्नुहोस्।');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception(isEnglish() ? 'Enter a valid email address.' : 'सही इमेल ठेगाना लेख्नुहोस्।');
            }
            if (!preg_match('/^9\d{9}$/', $phone)) {
                throw new Exception(isEnglish() ? 'Enter a valid 10-digit mobile (9XXXXXXXXX).' : 'सही १० अङ्कको मोबाइल (९XXXXXXXXX) लेख्नुहोस्।');
            }

            $checkStmt = $db->prepare(
                "SELECT id FROM job_applications
                 WHERE career_id = ? AND (email = ? OR phone = ?)
                 LIMIT 1"
            );
            $checkStmt->execute([$jobId, $email, $phone]);
            if ($checkStmt->fetch()) {
                throw new Exception(isEnglish()
                    ? 'You have already applied for this position.'
                    : 'तपाईंले यस पदको लागि पहिले नै आवेदन दिनुभएको छ।');
            }

            $resumePath = '';
            $photoPath = '';
            $citizenshipPath = '';
            $certificatesPath = '';

            if (!isset($_FILES['resume']) || $_FILES['resume']['error'] === UPLOAD_ERR_NO_FILE) {
                throw new Exception(isEnglish() ? 'Resume/CV is required.' : 'बायोडाटा/CV अनिवार्य छ।');
            }
            if ($_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception(isEnglish() ? 'Resume upload failed. Please try again.' : 'बायोडाटा अपलोड असफल। पुन: प्रयास गर्नुहोस्।');
            }
            $upload = uploadFile($_FILES['resume'], 'job-applications/resumes');
            if (empty($upload['success'])) {
                throw new Exception($upload['message'] ?? (isEnglish() ? 'Resume upload failed.' : 'बायोडाटा अपलोड असफल।'));
            }
            $resumePath = $upload['path'];

            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['photo'], 'job-applications/photos');
                if (!empty($upload['success'])) {
                    $photoPath = $upload['path'];
                }
            }
            if (isset($_FILES['citizenship']) && $_FILES['citizenship']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['citizenship'], 'job-applications/documents');
                if (!empty($upload['success'])) {
                    $citizenshipPath = $upload['path'];
                }
            }
            if (isset($_FILES['certificates']) && $_FILES['certificates']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['certificates'], 'job-applications/documents');
                if (!empty($upload['success'])) {
                    $certificatesPath = $upload['path'];
                }
            }

            $trackingId = 'JOB-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

            $stmt = $db->prepare(
                'INSERT INTO job_applications
                (career_id, full_name, email, phone, address, date_of_birth, gender, education, experience,
                 current_employer, expected_salary, cover_letter, resume_path, photo_path, citizenship_path,
                 certificates_path, tracking_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $jobId, $fullName, $email, $phone, $address, $dob !== '' ? $dob : null, $gender,
                $education, $experience, $currentEmployer, $expectedSalary, $coverLetter,
                $resumePath, $photoPath, $citizenshipPath, $certificatesPath, $trackingId,
            ]);

            $success = true;
            $successTrackingId = $trackingId;
            $showApplyForm = false;

            if (file_exists(__DIR__ . '/includes/notifications.php')) {
                require_once __DIR__ . '/includes/notifications.php';
                try {
                    sendAdminNotification('job_application', [
                        'पद' => getLangField($job, 'title'),
                        'आवेदकको नाम' => $fullName,
                        'इमेल' => $email,
                        'फोन' => $phone,
                        'योग्यता' => $education ?: '—',
                        'अनुभव' => $experience ?: '—',
                    ], $trackingId);
                } catch (Throwable $e) {
                    error_log('career notify: ' . $e->getMessage());
                }
            }
            if (function_exists('auditLog')) {
                auditLog('job_apply', 'job_applications', null, null, ['tracking' => $trackingId, 'name' => $fullName]);
            }
            redirect('career-detail.php?id=' . $jobId . '&applied=1&tid=' . urlencode($trackingId));
        } catch (Exception $e) {
            $error = $e->getMessage();
            $showApplyForm = true;
        }
    }
}

require_once 'includes/header.php';
$L = getLangStrings();
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo e(getLangField($job, 'title')); ?></h1>
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
                <div class="d-flex align-items-center gap-2 mb-2 justify-content-center">
                    <div class="fw-bold fs-5 text-success font-monospace" id="jobTrkId"><?php echo e($successTrackingId); ?></div>
                    <button type="button" onclick="copyTrk('jobTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2 cd-copy-btn" title="Copy"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
                </div>
                <div class="small text-muted">
                    <a href="application-tracker.php?id=<?php echo urlencode($successTrackingId); ?>" class="text-success text-decoration-none fw-semibold">
                        <?php echo isEnglish() ? 'Open Application Tracker' : 'Application Tracker खोल्नुहोस्'; ?>
                    </a>
                </div>
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
            <div class="col-lg-8">
                <div class="job-detail-card">
                    <div class="job-detail-header">
                        <div class="job-title-wrap">
                            <h2><?php echo e(getLangField($job, 'title')); ?></h2>
                            <div class="job-meta">
                                <?php if (!empty($job['department'])): ?>
                                <span><i class="fas fa-building"></i> <?php echo e($job['department']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($job['location'])): ?>
                                <span><i class="fas fa-map-marker-alt"></i> <?php echo e($job['location']); ?></span>
                                <?php endif; ?>
                                <span class="job-type-badge"><?php echo e($jobTypeLabel); ?></span>
                            </div>
                        </div>
                        <?php if ($deadlinePassed): ?>
                        <span class="deadline-badge expired">
                            <i class="fas fa-times-circle"></i> <?php echo isEnglish() ? 'Deadline Expired' : 'म्याद सकियो'; ?>
                        </span>
                        <?php else: ?>
                        <span class="deadline-badge active">
                            <i class="fas fa-clock"></i> <?php echo isEnglish() ? 'Deadline:' : 'म्याद:'; ?> <?php echo e($deadlineLabel); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="job-detail-body">
                        <div class="job-info-row">
                            <span class="label"><?php echo isEnglish() ? 'No. of Vacancies' : 'रिक्त पद संख्या'; ?>:</span>
                            <span class="value"><?php echo (int)$vacancyCount; ?></span>
                        </div>

                        <?php if (!empty($job['min_qualification'])): ?>
                        <div class="job-info-row">
                            <span class="label"><?php echo isEnglish() ? 'Minimum Qualification' : 'न्यूनतम योग्यता'; ?>:</span>
                            <span class="value"><?php echo e($job['min_qualification']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($job['experience_required'])): ?>
                        <div class="job-info-row">
                            <span class="label"><?php echo isEnglish() ? 'Experience Required' : 'आवश्यक अनुभव'; ?>:</span>
                            <span class="value"><?php echo e($job['experience_required']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($job['salary_range'])): ?>
                        <div class="job-info-row">
                            <span class="label"><?php echo isEnglish() ? 'Salary Range' : 'तलब दायरा'; ?>:</span>
                            <span class="value"><?php echo e($job['salary_range']); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php $jobDesc = getLangField($job, 'description'); if ($jobDesc !== ''): ?>
                        <div class="job-section">
                            <h4><?php echo isEnglish() ? 'Job Description' : 'कामको विवरण'; ?></h4>
                            <div class="job-content">
                                <?php echo nl2br(e($jobDesc)); ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($job['requirements'])): ?>
                        <div class="job-section">
                            <h4><?php echo isEnglish() ? 'Requirements' : 'आवश्यकताहरू'; ?></h4>
                            <div class="job-content">
                                <?php echo nl2br(e($job['requirements'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="job-detail-footer">
                        <?php if (!empty($job['attachment'])): ?>
                        <a href="<?php echo e($job['attachment']); ?>" class="btn btn-outline-primary" download>
                            <i class="fas fa-download"></i> <?php echo isEnglish() ? 'Download Details' : 'विवरण डाउनलोड गर्नुहोस्'; ?>
                        </a>
                        <?php endif; ?>

                        <?php if ($allowOnlineApply && !$success): ?>
                        <button type="button" class="btn btn-primary" id="showApplicationFormBtn" onclick="showApplicationForm()"<?php echo $showApplyForm ? ' style="display:none"' : ''; ?>>
                            <i class="fas fa-paper-plane"></i> <?php echo isEnglish() ? 'Apply Now' : 'अहिले आवेदन दिनुहोस्'; ?>
                        </button>
                        <?php elseif ($deadlinePassed): ?>
                        <span class="text-muted"><i class="fas fa-lock me-1"></i><?php echo isEnglish() ? 'Applications closed' : 'आवेदन बन्द'; ?></span>
                        <?php elseif (!$success): ?>
                        <a href="mailto:<?php echo e(getSetting('email', 'info@sahakari.org.np')); ?>?subject=<?php echo rawurlencode('Job Application: ' . getLangField($job, 'title')); ?>" class="btn btn-outline-primary">
                            <i class="fas fa-envelope"></i> <?php echo isEnglish() ? 'Email HR to apply' : 'आवेदनका लागि HR मा इमेल'; ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($allowOnlineApply && !$success): ?>
                <div class="application-form-section" id="apply-form" style="<?php echo $showApplyForm ? '' : 'display: none;'; ?>">
                    <h3><i class="fas fa-file-alt"></i> <?php echo isEnglish() ? 'Online Application Form' : 'अनलाइन आवेदन फारम'; ?></h3>
                    <p class="form-subtitle"><?php echo isEnglish() ? 'Fill out the form below to apply for this position. Fields marked with * are required.' : 'यस पदको लागि आवेदन दिन तलको फारम भर्नुहोस्। * चिन्ह भएका फिल्डहरू अनिवार्य छन्।'; ?></p>

                    <form method="POST" enctype="multipart/form-data" class="needs-validation job-application-form" novalidate action="career-detail.php?id=<?php echo (int)$jobId; ?>#apply-form">
                        <?php echo csrfField(); ?>
                        <div class="form-section">
                            <h5><?php echo isEnglish() ? 'Personal Information' : 'व्यक्तिगत जानकारी'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Full Name' : 'पूरा नाम'; ?> <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control" required maxlength="200" value="<?php echo e($_POST['full_name'] ?? ''); ?>" placeholder="<?php echo isEnglish() ? 'Enter your full name' : 'पूरा नाम लेख्नुहोस्'; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Email Address' : 'इमेल ठेगाना'; ?> <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" required maxlength="254" value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="name@example.com">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Phone Number' : 'फोन नम्बर'; ?> <span class="text-danger">*</span></label>
                                    <input type="tel" name="phone" class="form-control" required pattern="9[0-9]{9}" maxlength="10" value="<?php echo e($_POST['phone'] ?? ''); ?>" placeholder="98XXXXXXXX">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?></label>
                                    <input type="text" name="address" class="form-control" maxlength="500" value="<?php echo e($_POST['address'] ?? ''); ?>" placeholder="<?php echo isEnglish() ? 'Your current address' : 'हालको ठेगाना'; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Date of Birth (B.S.)' : 'जन्म मिति (बि.सं.)'; ?></label>
                                    <div class="input-group nepali-datepicker-wrapper">
                                        <input type="text" name="date_of_birth" id="dob_nepali" class="form-control nepali-datepicker" placeholder="YYYY-MM-DD" value="<?php echo e($_POST['date_of_birth'] ?? ''); ?>">
                                        <span class="input-group-text cursor-pointer" onclick="$(this).siblings('.nepali-datepicker').focus();"><i class="fas fa-calendar-alt"></i></span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Gender' : 'लिङ्ग'; ?></label>
                                    <select name="gender" class="form-select">
                                        <?php $selG = $_POST['gender'] ?? 'male'; ?>
                                        <option value="male" <?php echo $selG === 'male' ? 'selected' : ''; ?>><?php echo isEnglish() ? 'Male' : 'पुरुष'; ?></option>
                                        <option value="female" <?php echo $selG === 'female' ? 'selected' : ''; ?>><?php echo isEnglish() ? 'Female' : 'महिला'; ?></option>
                                        <option value="other" <?php echo $selG === 'other' ? 'selected' : ''; ?>><?php echo isEnglish() ? 'Other' : 'अन्य'; ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h5><?php echo isEnglish() ? 'Education & Experience' : 'शिक्षा र अनुभव'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Highest Education' : 'उच्चतम शिक्षा'; ?></label>
                                    <input type="text" name="education" class="form-control" value="<?php echo e($_POST['education'] ?? ''); ?>" placeholder="<?php echo isEnglish() ? 'e.g., Bachelor in Business' : 'जस्तै: व्यवसायमा स्नातक'; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Years of Experience' : 'अनुभवको वर्ष'; ?></label>
                                    <input type="text" name="experience" class="form-control" value="<?php echo e($_POST['experience'] ?? ''); ?>" placeholder="<?php echo isEnglish() ? 'e.g., 2 years' : 'जस्तै: २ वर्ष'; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Current Employer' : 'हालको रोजगारदाता'; ?></label>
                                    <input type="text" name="current_employer" class="form-control" value="<?php echo e($_POST['current_employer'] ?? ''); ?>" placeholder="<?php echo isEnglish() ? 'Company name (if employed)' : 'कम्पनीको नाम (कार्यरत भए)'; ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Expected Salary' : 'अपेक्षित तलब'; ?></label>
                                    <input type="text" name="expected_salary" class="form-control" value="<?php echo e($_POST['expected_salary'] ?? ''); ?>" placeholder="<?php echo isEnglish() ? 'e.g., Rs. 30,000 - 40,000' : 'जस्तै: रु. ३०,००० - ४०,०००'; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h5><?php echo isEnglish() ? 'Cover Letter' : 'आवेदन पत्र'; ?></h5>
                            <div class="mb-3">
                                <label class="form-label"><?php echo isEnglish() ? 'Why should we hire you?' : 'हामीले तपाईंलाई किन नियुक्त गर्नुपर्छ?'; ?></label>
                                <textarea name="cover_letter" class="form-control" rows="4" placeholder="<?php echo isEnglish() ? 'Write about your skills, experience, and why you are suitable for this position...' : 'तपाईंको सीप, अनुभव, र यस पदको लागि तपाईं किन उपयुक्त हुनुहुन्छ भनेर लेख्नुहोस्...'; ?>"><?php echo e($_POST['cover_letter'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="form-section">
                            <h5><?php echo isEnglish() ? 'Document Upload' : 'कागजात अपलोड'; ?></h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><?php echo isEnglish() ? 'Resume/CV' : 'बायोडाटा/CV'; ?> <span class="text-danger">*</span></label>
                                    <input type="file" name="resume" class="form-control" accept=".pdf,.doc,.docx" required>
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

            <div class="col-lg-4">
                <div class="sidebar-card job-summary">
                    <h4><?php echo isEnglish() ? 'Job Summary' : 'कामको सारांश'; ?></h4>
                    <ul class="summary-list">
                        <li>
                            <i class="fas fa-briefcase"></i>
                            <div>
                                <span class="label"><?php echo isEnglish() ? 'Job Type' : 'काम प्रकार'; ?></span>
                                <span class="value"><?php echo e($jobTypeLabel); ?></span>
                            </div>
                        </li>
                        <li>
                            <i class="fas fa-map-marker-alt"></i>
                            <div>
                                <span class="label"><?php echo isEnglish() ? 'Location' : 'स्थान'; ?></span>
                                <span class="value"><?php echo e($job['location'] ?: (isEnglish() ? 'Head Office' : 'केन्द्रीय कार्यालय')); ?></span>
                            </div>
                        </li>
                        <li>
                            <i class="fas fa-users"></i>
                            <div>
                                <span class="label"><?php echo isEnglish() ? 'Vacancies' : 'रिक्त पद'; ?></span>
                                <span class="value"><?php echo (int)$vacancyCount; ?></span>
                            </div>
                        </li>
                        <li>
                            <i class="fas fa-calendar-alt"></i>
                            <div>
                                <span class="label"><?php echo isEnglish() ? 'Posted Date' : 'प्रकाशित मिति'; ?></span>
                                <span class="value"><?php echo e($postedLabel); ?></span>
                            </div>
                        </li>
                        <li>
                            <i class="fas fa-hourglass-end"></i>
                            <div>
                                <span class="label"><?php echo isEnglish() ? 'Deadline' : 'म्याद'; ?></span>
                                <span class="value <?php echo $deadlinePassed ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo e($deadlineLabel); ?>
                                    <?php if ($deadlinePassed): ?>
                                    <small>(<?php echo isEnglish() ? 'Expired' : 'समाप्त'; ?>)</small>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </li>
                    </ul>
                </div>

                <div class="sidebar-card">
                    <h4><?php echo isEnglish() ? 'Share This Job' : 'यो जागिर साझा गर्नुहोस्'; ?></h4>
                    <div class="share-buttons">
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(rtrim(SITE_URL, '/') . '/career-detail.php?id=' . $jobId); ?>" target="_blank" rel="noopener" class="share-btn facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode(rtrim(SITE_URL, '/') . '/career-detail.php?id=' . $jobId); ?>&text=<?php echo urlencode(getLangField($job, 'title')); ?>" target="_blank" rel="noopener" class="share-btn twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode(rtrim(SITE_URL, '/') . '/career-detail.php?id=' . $jobId); ?>" target="_blank" rel="noopener" class="share-btn linkedin">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                        <a href="https://api.whatsapp.com/send?text=<?php echo urlencode(getLangField($job, 'title') . ' - ' . rtrim(SITE_URL, '/') . '/career-detail.php?id=' . $jobId); ?>" target="_blank" rel="noopener" class="share-btn whatsapp">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                    </div>
                </div>

                <div class="sidebar-card">
                    <div class="sidebar-icon">
                        <i class="fas fa-headset"></i>
                    </div>
                    <h4><?php echo isEnglish() ? 'Need Help?' : 'मद्दत चाहिन्छ?'; ?></h4>
                    <p><?php echo isEnglish() ? 'Contact our HR department for any queries.' : 'कुनै प्रश्नको लागि हाम्रो HR विभागलाई सम्पर्क गर्नुहोस्।'; ?></p>
                    <a href="mailto:<?php echo e(getSetting('email', 'info@sahakari.org.np')); ?>" class="btn btn-outline-primary btn-block">
                        <i class="fas fa-envelope"></i> <?php echo isEnglish() ? 'Contact HR' : 'HR सम्पर्क'; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>


<script>
function showApplicationForm() {
    var form = document.getElementById('apply-form');
    var btn = document.getElementById('showApplicationFormBtn');
    if (!form) return;
    form.style.display = 'block';
    if (btn) btn.style.display = 'none';
    setTimeout(function() {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 80);
    setTimeout(function() {
        if (typeof $ !== 'undefined' && typeof $.fn.nepaliDatePicker !== 'undefined') {
            if (typeof initNepaliDatePicker === 'function') {
                initNepaliDatePicker('#dob_nepali');
            }
        }
    }, 200);
}

document.addEventListener('DOMContentLoaded', function() {
    if (window.location.hash === '#apply-form') {
        showApplicationForm();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
