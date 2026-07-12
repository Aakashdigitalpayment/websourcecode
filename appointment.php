<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
require_once 'includes/ensure-tables.php';
ensurePublicTables();
$_kycFile=__DIR__.'/includes/kyc-public-form.php'; if(is_file($_kycFile)){require_once $_kycFile;} unset($_kycFile);
$pageTitle = isEnglish() ? 'Book Appointment' : 'भेटघाट बुक गर्नुहोस्';

$success        = false;
$error          = '';
$apptTrackingId = '';
$successVisitKind = 'member';
$loggedMember   = getLoggedInMemberProfile();
$isEmbed = !empty($_GET['embed']);
$trackerUrl = $isEmbed ? (SITE_URL . 'member/tracker.php') : 'application-tracker.php';
$activeApptTab = (($_GET['tab'] ?? $_POST['visit_kind'] ?? 'member') === 'cooperative') ? 'cooperative' : 'member';

/* Extra columns for सहकारी भ्रमण (safe if already present) */
try {
    $_dbMig = getDB();
    safeAddColumn($_dbMig, 'appointments', 'tracking_id', 'VARCHAR(60) NULL DEFAULT NULL');
    safeAddColumn($_dbMig, 'appointments', 'visit_kind', "VARCHAR(20) NOT NULL DEFAULT 'member'");
    safeAddColumn($_dbMig, 'appointments', 'organization_address', 'VARCHAR(500) NULL DEFAULT NULL');
    safeAddColumn($_dbMig, 'appointments', 'organization_website', 'VARCHAR(255) NULL DEFAULT NULL');
    safeAddColumn($_dbMig, 'appointments', 'contact_person', 'VARCHAR(120) NULL DEFAULT NULL');
    /* Coop names / times can exceed original schema */
    try { $_dbMig->exec('ALTER TABLE appointments MODIFY name VARCHAR(200) NOT NULL'); } catch (Throwable $e) {}
    try { $_dbMig->exec('ALTER TABLE appointments MODIFY preferred_time VARCHAR(50) NOT NULL'); } catch (Throwable $e) {}
    try { $_dbMig->exec('ALTER TABLE appointments MODIFY branch VARCHAR(200) NULL'); } catch (Throwable $e) {}
    unset($_dbMig);
} catch (Throwable $e) { /* best-effort */ }

if (!function_exists('appointmentInsertRow')) {
    /**
     * Insert into appointments using only columns that exist (avoids hard fail on older DBs).
     * @param array<string,mixed> $data
     */
    function appointmentInsertRow(PDO $db, array $data): void
    {
        static $cols = null;
        if ($cols === null) {
            $cols = [];
            try {
                foreach ($db->query('SHOW COLUMNS FROM appointments') as $row) {
                    $cols[strtolower((string)($row['Field'] ?? ''))] = true;
                }
            } catch (Throwable $e) {
                $cols = [];
            }
        }
        $use = [];
        $vals = [];
        foreach ($data as $col => $val) {
            $key = strtolower((string)$col);
            if (!isset($cols[$key])) {
                continue;
            }
            $use[] = '`' . str_replace('`', '', (string)$col) . '`';
            $vals[] = $val;
        }
        if ($use === []) {
            throw new RuntimeException('appointments table has no matching columns');
        }
        $placeholders = implode(',', array_fill(0, count($use), '?'));
        $sql = 'INSERT INTO appointments (' . implode(',', $use) . ') VALUES (' . $placeholders . ')';
        $db->prepare($sql)->execute($vals);
    }
}

if (!function_exists('appointmentNormalizeDate')) {
    function appointmentNormalizeDate(string $raw): string
    {
        $s = trim($raw);
        /* Devanagari digits → Latin */
        $s = strtr($s, [
            '०'=>'0','१'=>'1','२'=>'2','३'=>'3','४'=>'4','५'=>'5','६'=>'6','७'=>'7','८'=>'8','९'=>'9',
        ]);
        $s = str_replace(['/', '.'], '-', $s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $s, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
        }
        return $s;
    }
}

/* =============================================
   फारम submit भएमा process गर्नुहोस्
   ============================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visitKind = (($_POST['visit_kind'] ?? 'member') === 'cooperative') ? 'cooperative' : 'member';
    $activeApptTab = $visitKind;

    if (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed.' : 'सुरक्षा जाँच असफल।';
    } elseif (!checkRateLimit('appointment', 3, 60)) {
        $error = isEnglish() ? 'Too many requests. Please wait.' : 'धेरै अनुरोधहरू। कृपया पर्खनुहोस्।';
    } else {
        $db = null;
        try { $db = getDB(); } catch (\Throwable $_dbEx) {
            $error = isEnglish() ? 'Database temporarily unavailable.' : 'डेटाबेस अस्थायी उपलब्ध छैन।';
        }

        if (!$error && $visitKind === 'cooperative') {
            /* ── सहकारी भ्रमण (separate tab — does not alter member flow) ── */
            $name           = clean_text($_POST['organization_name'] ?? '', 200);
            $contactPerson  = clean_text($_POST['contact_person'] ?? '', 120);
            $phone          = preg_replace('/[^0-9]/', '', clean_text($_POST['phone'] ?? '', 15));
            $email          = strtolower(clean_text($_POST['email'] ?? '', 254));
            $orgAddress     = clean_text($_POST['organization_address'] ?? '', 500);
            $orgWebsite     = clean_text($_POST['organization_website'] ?? '', 255);
            $purpose_detail = clean_text($_POST['purpose_detail'] ?? '', 1000);
            $preferred_date = clean_text($_POST['preferred_date'] ?? '', 30);
            $preferred_time = clean_text($_POST['preferred_time'] ?? '', 30);
            $branch         = clean_text($_POST['branch'] ?? '', 200);

            if (empty($name)) {
                $error = isEnglish() ? 'Please enter the cooperative name.' : 'कृपया सहकारीको नाम भर्नुहोस्।';
            } elseif (empty($contactPerson)) {
                $error = isEnglish() ? 'Please enter a contact person.' : 'कृपया सम्पर्क व्यक्तिको नाम भर्नुहोस्।';
            } elseif (empty($phone)) {
                $error = isEnglish() ? 'Phone number is required.' : 'फोन नम्बर अनिवार्य छ।';
            } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
                $error = isEnglish() ? 'Please enter a valid 10-digit mobile number.' : 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।';
            } elseif (!empty($email) && !isValidEmail($email)) {
                $error = isEnglish() ? 'Please enter a valid email address.' : 'कृपया सही इमेल ठेगाना राख्नुहोस्।';
            } elseif (empty($orgAddress)) {
                $error = isEnglish() ? 'Please enter the cooperative address.' : 'कृपया सहकारीको ठेगाना भर्नुहोस्।';
            } elseif (empty($purpose_detail)) {
                $error = isEnglish() ? 'Please enter visit details.' : 'कृपया भ्रमण विवरण भर्नुहोस्।';
            } elseif (empty($preferred_date)) {
                $error = isEnglish() ? 'Please select a preferred date.' : 'कृपया मिति छान्नुहोस्।';
            } elseif (empty($preferred_time)) {
                $error = isEnglish() ? 'Please select a preferred time.' : 'कृपया समय छान्नुहोस्।';
            } else {
                $preferred_date = appointmentNormalizeDate($preferred_date);
                $preferred_time = mb_substr($preferred_time, 0, 50, 'UTF-8');
                $name = mb_substr($name, 0, 200, 'UTF-8');
                $branch = mb_substr($branch, 0, 200, 'UTF-8');
                try {
                    $apptTrackingId = 'APT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
                    appointmentInsertRow($db, [
                        'tracking_id' => $apptTrackingId,
                        'name' => $name,
                        'phone' => $phone,
                        'email' => $email,
                        'member_id' => '',
                        'purpose' => 'other',
                        'purpose_detail' => $purpose_detail,
                        'preferred_date' => $preferred_date,
                        'preferred_time' => $preferred_time,
                        'branch' => $branch,
                        'visit_kind' => 'cooperative',
                        'organization_address' => $orgAddress,
                        'organization_website' => $orgWebsite,
                        'contact_person' => $contactPerson,
                        'status' => 'pending',
                    ]);
                    $success = true;
                    $successVisitKind = 'cooperative';
                    logSecurityEvent('appointment_booking', 'Cooperative visit booked: ' . $name . ' (Tracking: ' . $apptTrackingId . ')');
                } catch (Throwable $e) {
                    error_log('[appointment cooperative] ' . $e->getMessage());
                    $error = isEnglish()
                        ? ('Failed to book appointment. Please try again. (' . $e->getCode() . ')')
                        : ('भेटघाट बुक गर्न सकिएन। कृपया फेरि प्रयास गर्नुहोस्। (' . $e->getCode() . ')');
                }
                if ($success) {
                    try {
                        $__nf = __DIR__ . '/includes/notifications.php';
                        if (is_file($__nf)) { require_once $__nf; }
                        unset($__nf);
                        sendAdminNotification('appointment', [
                            'नाम'            => $name,
                            'प्रकार'         => 'सहकारी भ्रमण',
                            'सहकारी'         => $name,
                            'सम्पर्क व्यक्ति' => $contactPerson,
                            'फोन'            => $phone,
                            'ठेगाना'         => $orgAddress,
                            'वेबसाइट'        => $orgWebsite ?: 'N/A',
                            'मिति'           => $preferred_date . ' ' . $preferred_time,
                        ], $apptTrackingId);
                    } catch (Throwable $e) {
                        error_log('[appointment cooperative notify] ' . $e->getMessage());
                    }
                }
            }
        } elseif (!$error) {
            /* ── सदस्य / सामान्य भेटघाट (existing flow) ── */
            $name           = clean_text($_POST['name']           ?? '', 200);
            $phone          = preg_replace('/[^0-9]/', '', clean_text($_POST['phone']          ?? '', 15));
            $email          = strtolower(clean_text($_POST['email']          ?? '', 254));
            $member_id      = clean_text($_POST['member_id']      ?? '', 80);
            $purposeRaw     = clean_text($_POST['purpose']        ?? 'other', 40);
            $allowedPurpose = ['account_inquiry', 'loan_inquiry', 'kyc_update', 'loan_repayment', 'account_opening', 'other'];
            $purpose        = in_array($purposeRaw, $allowedPurpose, true) ? $purposeRaw : 'other';
            $purpose_detail = clean_text($_POST['purpose_detail'] ?? '', 500);
            $preferred_date = clean_text($_POST['preferred_date'] ?? '', 30);
            $preferred_time = clean_text($_POST['preferred_time'] ?? '', 30);
            $branch         = clean_text($_POST['branch']         ?? '', 200);

            $isCoopMember = $loggedMember ? 'yes' : ((($_POST['is_coop_member'] ?? '') === 'yes') ? 'yes' : 'no');
            $kycMerge = null;
            if ($loggedMember) {
                $kycMerge = loadKycRowForLoggedMemberPublic($db, $loggedMember);
                if (!$kycMerge || strtolower(trim((string)($kycMerge['status'] ?? ''))) !== 'approved') {
                    $error = isEnglish() ? 'KYC verification is required to book appointment.' : 'भेटघाट बुक गर्न KYC verified (approved) हुनुपर्छ।';
                }
                $fnK = (is_array($kycMerge) && !empty($kycMerge['full_name'])) ? trim((string)$kycMerge['full_name']) : '';
                $name = $fnK !== '' ? $fnK : trim((string)($loggedMember['name'] ?? $name));
                $midK = (is_array($kycMerge) && !empty($kycMerge['member_id'])) ? trim((string)$kycMerge['member_id']) : '';
                $member_id = $midK !== '' ? $midK : trim((string)($loggedMember['sadasyata_number'] ?? $member_id));
                $phone = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $loggedMember['phone'] ?? $phone));
                $email = strtolower(trim((string)($kycMerge['email'] ?? $loggedMember['email'] ?? $email)));
            } elseif ($isCoopMember === 'yes') {
                $v = $db ? verifyPublicFormKycApprovedByMemberId($db, $_POST['member_id'] ?? '') : ['ok'=>false,'row'=>null,'msg_np'=>'डेटाबेस उपलब्ध छैन।','msg_en'=>'Database unavailable.'];
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

            if (!$error && empty($name)) {
                $error = isEnglish() ? 'Please enter your full name.' : 'कृपया पूरा नाम भर्नुहोस्।';
            } elseif (!$error && empty($phone)) {
                $error = isEnglish() ? 'Phone number is required.' : 'फोन नम्बर अनिवार्य छ।';
            } elseif (!$error && !preg_match('/^[0-9]{10}$/', $phone)) {
                $error = isEnglish() ? 'Please enter a valid 10-digit mobile number.' : 'कृपया १० अंकको मोबाइल नम्बर राख्नुहोस्।';
            } elseif (!$error && $isCoopMember !== 'yes' && empty($email)) {
                $error = isEnglish() ? 'Email address is required.' : 'इमेल ठेगाना अनिवार्य छ।';
            } elseif (!$error && !empty($email) && !isValidEmail($email)) {
                $error = isEnglish() ? 'Please enter a valid email address.' : 'कृपया सही इमेल ठेगाना राख्नुहोस्।';
            } elseif (!$error && empty($preferred_date)) {
                $error = isEnglish() ? 'Please select a preferred date.' : 'कृपया मिति छान्नुहोस्।';
            } elseif (!$error && empty($preferred_time)) {
                $error = isEnglish() ? 'Please select a preferred time.' : 'कृपया समय छान्नुहोस्।';
            } elseif (!$error) {
                $preferred_date = appointmentNormalizeDate($preferred_date);
                $preferred_time = mb_substr($preferred_time, 0, 50, 'UTF-8');
                $name = mb_substr($name, 0, 200, 'UTF-8');
                $branch = mb_substr($branch, 0, 200, 'UTF-8');
                try {
                    $apptTrackingId = 'APT-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
                    appointmentInsertRow($db, [
                        'tracking_id' => $apptTrackingId,
                        'name' => $name,
                        'phone' => $phone,
                        'email' => $email,
                        'member_id' => $member_id,
                        'purpose' => $purpose,
                        'purpose_detail' => $purpose_detail,
                        'preferred_date' => $preferred_date,
                        'preferred_time' => $preferred_time,
                        'branch' => $branch,
                        'visit_kind' => 'member',
                        'status' => 'pending',
                    ]);
                    $success = true;
                    $successVisitKind = 'member';
                    logSecurityEvent('appointment_booking', 'Appointment booked by: ' . $name . ' (Tracking: ' . $apptTrackingId . ')');
                } catch (Throwable $e) {
                    error_log('[appointment member] ' . $e->getMessage());
                    $error = isEnglish() ? 'Failed to book appointment.' : 'भेटघाट बुक गर्न सकिएन।';
                }
                if ($success) {
                    try {
                        $__nf = __DIR__ . '/includes/notifications.php';
                        if (is_file($__nf)) { require_once $__nf; }
                        unset($__nf);
                        sendAdminNotification('appointment', [
                            'नाम'       => $name,
                            'फोन'       => $phone,
                            'इमेल'      => $email ?: 'N/A',
                            'सदस्य नं.' => $member_id ?: 'N/A',
                            'उद्देश्य'   => $purpose,
                            'मिति'      => $preferred_date . ' ' . $preferred_time,
                            'शाखा'      => $branch ?: 'N/A',
                        ], $apptTrackingId);
                    } catch (Throwable $e) {
                        error_log('[appointment member notify] ' . $e->getMessage());
                    }
                }
            }
        }
    }
}

/* शाखाहरू load गर्नुहोस् */
try {
    $db       = getDB();
    require_once __DIR__ . '/includes/service-centers-helpers.php';
    $branches = fetchActiveServiceCenters($db);
} catch (Throwable $e) {
    $branches = [];
}

require_once 'includes/header.php';
$L = getLangStrings();
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

<?php if ($success): ?>
<section class="form-success-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7 form-success-card">
                <div class="form-success-icon"><i class="fas fa-check-circle"></i></div>
                <h3 class="mt-3 fw-bold text-success"><?php
                    if ($successVisitKind === 'cooperative') {
                        echo isEnglish() ? 'Cooperative Visit Request Submitted!' : 'सहकारी भ्रमण अनुरोध पेश भयो!';
                    } else {
                        echo isEnglish() ? 'Appointment Request Submitted!' : 'भेटघाट अनुरोध पेश भयो!';
                    }
                ?></h3>
                <p class="text-muted mb-3"><?php
                    if ($successVisitKind === 'cooperative') {
                        echo isEnglish() ? 'We will confirm your cooperative visit soon. Save your Tracking ID and check status in Tracker.' : 'हाम्रो टोलीले छिट्टै सहकारी भ्रमण पुष्टि गर्नेछ। Tracking ID सुरक्षित राख्नुहोस् र Tracker बाट स्थिति हेर्नुहोस्।';
                    } else {
                        echo isEnglish() ? 'We will confirm your appointment soon. Check your phone/email.' : 'हाम्रो टोलीले छिट्टै तपाईंको भेटघाट पुष्टि गर्नेछ। फोन/इमेल हेर्नुहोस्।';
                    }
                ?></p>
                <?php if ($apptTrackingId): ?>
                <div class="form-tracking-box mb-4">
                    <div class="text-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID — save this!' : 'तपाईंको Tracking ID — सुरक्षित राख्नुहोस्!'; ?></div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="form-tracking-id" id="apptTrkId"><?php echo e($apptTrackingId); ?></div>
                        <button type="button" onclick="copyTrk('apptTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2" title="Copy" style="font-size:11px;line-height:1.8;"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
                    </div>
                    <div class="form-tracking-help"><a href="<?php echo e($trackerUrl); ?>" class="text-success text-decoration-none fw-semibold"><?php echo isEnglish() ? 'Click here' : 'यहाँ बाट'; ?></a> <?php echo isEnglish() ? 'to check status in Tracker.' : 'Tracker मा स्थिति हेर्नुहोस्।'; ?></div>
                </div>
                <?php endif; ?>
                <div class="d-flex justify-content-center flex-wrap gap-2">
                <a href="appointment.php<?php echo $isEmbed ? '?embed=1' : ''; ?>" class="btn btn-success px-4"><i class="fas fa-plus me-1"></i><?php echo isEnglish() ? 'New Request' : 'नयाँ अनुरोध'; ?></a>
                <a href="<?php echo e($trackerUrl); ?>" class="btn btn-outline-primary px-4"><i class="fas fa-search me-1"></i><?php echo isEnglish() ? 'Track Status' : 'स्थिति ट्र्याक'; ?></a>
                <a href="<?php echo SITE_URL; ?>" class="btn btn-outline-secondary px-4"><i class="fas fa-home me-1"></i><?php echo isEnglish() ? 'Home' : 'गृहपृष्ठ'; ?></a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php else: ?>
<!-- Inline Appointment Form — no popup -->
<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">

            <!-- Steps sidebar -->
            <div class="col-lg-4 mb-4 mb-lg-0 order-lg-2">
                <div class="card border-0 shadow-sm h-100 appt-steps-card">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-4"><i class="fas fa-info-circle me-2"></i><?php echo isEnglish() ? 'How It Works' : 'कसरी काम गर्छ'; ?></h5>
                        <?php
                        $steps = isEnglish()
                            ? [['fa-edit','Fill the form','Complete all required fields below.'],['fa-clock','Await Confirmation','Our team will call/email to confirm.'],['fa-building','Visit Office','Come to the branch on scheduled date.'],['fa-handshake','Get Served','Receive personalised service.']]
                            : [['fa-edit','फारम भर्नुहोस्','तलका सबै आवश्यक जानकारी भर्नुहोस्।'],['fa-clock','पुष्टिको प्रतीक्षा','हाम्रो टोलीले फोन/इमेलबाट पुष्टि गर्नेछ।'],['fa-building','कार्यालय आउनुहोस्','तोकिएको मितिमा शाखामा आउनुहोस्।'],['fa-handshake','सेवा पाउनुहोस्','व्यक्तिगत सेवा प्राप्त गर्नुहोस्।']];
                        foreach ($steps as $i => $s): ?>
                        <div class="d-flex gap-3 mb-3">
                            <div class="appt-step-icon-wrap">
                                <i class="fas <?php echo $s[0]; ?>" style="font-size:15px;"></i>
                            </div>
                            <div>
                                <div class="fw-semibold" style="font-size:13.5px;"><?php echo ($i+1) . '. ' . $s[1]; ?></div>
                                <div style="font-size:12px;opacity:.8;"><?php echo $s[2]; ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <hr class="appt-step-divider">
                        <div class="appt-step-help">
                            <i class="fas fa-search me-1"></i>
                            <?php echo isEnglish() ? 'After booking, use your Tracking ID on the ' : 'बुक गरेपछि '; ?>
                            <a href="<?php echo e($trackerUrl); ?>" class="appt-step-link">
                                <?php echo isEnglish() ? 'Application Tracker' : 'Application Tracker'; ?>
                            </a>
                            <?php echo isEnglish() ? ' page to check status.' : ' मा Tracking ID बाट स्थिति हेर्नुहोस्।'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form card -->
            <div class="col-lg-8 order-lg-1">
                <div class="card border-0 shadow-sm appt-form-card">
                    <div class="card-header border-0 rounded-top-3 py-3 px-4" style="background:linear-gradient(135deg,var(--primary-dark),var(--primary-color));">
                        <h5 class="mb-0 text-white fw-bold">
                            <i class="fas fa-calendar-check me-2"></i>
                            <?php echo isEnglish() ? 'Book Appointment' : 'भेटघाट बुक गर्नुहोस्'; ?>
                        </h5>
                    </div>
                    <div class="card-body p-4">

                        <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show mb-4">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>

                        <?php
                        $apptTimeValueMember = trim((string)((($_POST['visit_kind'] ?? 'member') !== 'cooperative') ? ($_POST['preferred_time'] ?? '') : ''));
                        $apptTimeValueCoop = trim((string)((($_POST['visit_kind'] ?? '') === 'cooperative') ? ($_POST['preferred_time'] ?? '') : ''));
                        $apptTimeOptions = function_exists('getOfficeTimeOptions') ? getOfficeTimeOptions(30) : [];
                        $postIsMember = (($_POST['visit_kind'] ?? 'member') !== 'cooperative');
                        ?>

                        <ul class="nav nav-pills appt-kind-tabs mb-4 gap-2" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button type="button" class="nav-link <?php echo $activeApptTab === 'member' ? 'active' : ''; ?>"
                                        id="appt-tab-member-btn" data-bs-toggle="pill" data-bs-target="#appt-tab-member" role="tab"
                                        aria-controls="appt-tab-member" aria-selected="<?php echo $activeApptTab === 'member' ? 'true' : 'false'; ?>">
                                    <i class="fas fa-user me-1"></i><?php echo isEnglish() ? 'Member Appointment' : 'सदस्य भेटघाट'; ?>
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button type="button" class="nav-link <?php echo $activeApptTab === 'cooperative' ? 'active' : ''; ?>"
                                        id="appt-tab-coop-btn" data-bs-toggle="pill" data-bs-target="#appt-tab-coop" role="tab"
                                        aria-controls="appt-tab-coop" aria-selected="<?php echo $activeApptTab === 'cooperative' ? 'true' : 'false'; ?>">
                                    <i class="fas fa-handshake me-1"></i><?php echo isEnglish() ? 'Cooperative Visit' : 'सहकारी भ्रमण'; ?>
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <div class="tab-pane fade <?php echo $activeApptTab === 'member' ? 'show active' : ''; ?>" id="appt-tab-member" role="tabpanel" aria-labelledby="appt-tab-member-btn" tabindex="0">

                        <form method="POST" id="appointmentForm" class="needs-validation" novalidate>
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="visit_kind" value="member">
                            <?php if ($loggedMember):
                                $kycForDisplay = null;
                                try { $kycForDisplay = loadKycRowForLoggedMemberPublic(getDB(), $loggedMember); } catch(Throwable $e) {}
                                require ROOT_PATH . 'includes/member-prefill-block.php';
                            else: ?>
                            <div class="border rounded-3 p-3 mb-3 bg-light">
                                <label class="form-label fw-semibold d-block mb-2"><?php echo isEnglish() ? 'Cooperative member?' : 'सहकारी सदस्य?'; ?></label>
                                <div class="d-flex flex-wrap gap-3">
                                    <label class="form-check-label"><input type="radio" name="is_coop_member" value="no" class="form-check-input me-1 js-appt-coop" <?php echo (($postIsMember ? ($_POST['is_coop_member'] ?? 'no') : 'no') === 'yes') ? '' : 'checked'; ?>> <?php echo isEnglish() ? 'No' : 'होइन'; ?></label>
                                    <label class="form-check-label"><input type="radio" name="is_coop_member" value="yes" class="form-check-input me-1 js-appt-coop" <?php echo ($postIsMember && (($_POST['is_coop_member'] ?? '') === 'yes')) ? 'checked' : ''; ?>> <?php echo isEnglish() ? 'Yes (Member ID based KYC)' : 'हो (Member ID आधारित KYC)'; ?></label>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!$loggedMember): ?>
                            <div class="form-card-title mb-3">
                                <i class="fas fa-user"></i>
                                <?php echo isEnglish() ? 'Personal Information' : 'व्यक्तिगत जानकारी'; ?>
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6 js-appt-name-wrap">
                                    <label class="form-label"><?php echo isEnglish() ? 'Full Name' : 'पूरा नाम'; ?> <span class="req">*</span></label>
                                    <input type="text" name="name" class="form-control js-appt-personal" <?php echo $activeApptTab === 'member' ? 'required' : ''; ?>
                                           value="<?php echo htmlspecialchars($postIsMember ? ($_POST['name'] ?? '') : '', ENT_QUOTES); ?>"
                                           placeholder="<?php echo isEnglish() ? 'Your full name' : 'तपाईंको पूरा नाम'; ?>">
                                </div>
                                <div class="col-md-6 js-hide-if-appt-coop-yes">
                                    <label class="form-label"><?php echo isEnglish() ? 'Phone Number' : 'फोन नम्बर'; ?> <span class="req">*</span></label>
                                    <input type="tel" name="phone" class="form-control js-appt-personal" <?php echo $activeApptTab === 'member' ? 'required' : ''; ?>
                                           maxlength="10" pattern="[0-9]{10}" placeholder="98XXXXXXXX"
                                           value="<?php echo htmlspecialchars($postIsMember ? ($_POST['phone'] ?? '') : '', ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-6 js-hide-if-appt-coop-yes">
                                    <label class="form-label"><?php echo isEnglish() ? 'Email' : 'इमेल'; ?> <span class="req">*</span></label>
                                    <input type="email" name="email" class="form-control js-appt-personal" <?php echo $activeApptTab === 'member' ? 'required' : ''; ?>
                                           placeholder="example@gmail.com"
                                           value="<?php echo htmlspecialchars($postIsMember ? ($_POST['email'] ?? '') : '', ENT_QUOTES); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Member ID' : 'सदस्य नं.'; ?> <span class="text-danger js-appt-mid-req" style="display:none;">*</span><span class="text-muted small js-appt-mid-opt">(<?php echo isEnglish() ? 'optional' : 'ऐच्छिक'; ?>)</span></label>
                                    <input type="text" name="member_id" class="form-control js-appt-mid"
                                           value="<?php echo htmlspecialchars($postIsMember ? ($_POST['member_id'] ?? '') : '', ENT_QUOTES); ?>">
                                </div>
                            </div>
                            <script>
                            (function(){
                              function syncApptCoop(){
                                var f=document.getElementById('appointmentForm'); if(!f) return;
                                var yes=f.querySelector('input.js-appt-coop[value=yes]:checked');
                                var nameWrap=f.querySelector('.js-appt-name-wrap');
                                var pers=f.querySelectorAll('.js-appt-personal');
                                var mid=f.querySelector('input.js-appt-mid');
                                var midReq=f.querySelector('.js-appt-mid-req');
                                var midOpt=f.querySelector('.js-appt-mid-opt');
                                if(yes){
                                  f.querySelectorAll('.js-hide-if-appt-coop-yes').forEach(function(el){el.style.display='none';});
                                  if(nameWrap) nameWrap.style.display='none';
                                  pers.forEach(function(el){ el.removeAttribute('required'); });
                                  if(mid) mid.setAttribute('required','required');
                                  if(midReq) midReq.style.display='';
                                  if(midOpt) midOpt.style.display='none';
                                }else{
                                  f.querySelectorAll('.js-hide-if-appt-coop-yes').forEach(function(el){el.style.display='';});
                                  if(nameWrap) nameWrap.style.display='';
                                  pers.forEach(function(el){ el.setAttribute('required','required'); });
                                  if(mid) mid.removeAttribute('required');
                                  if(midReq) midReq.style.display='none';
                                  if(midOpt) midOpt.style.display='';
                                }
                              }
                              document.querySelectorAll('#appointmentForm input.js-appt-coop').forEach(function(r){ r.addEventListener('change',syncApptCoop); });
                              document.addEventListener('DOMContentLoaded',syncApptCoop);
                            })();
                            </script>
                            <?php endif; ?>

                            <div class="form-card-title mb-3">
                                <i class="fas fa-clipboard-list"></i>
                                <?php echo isEnglish() ? 'Appointment Details' : 'भेटघाट विवरण'; ?>
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Purpose' : 'उद्देश्य'; ?> <span class="req">*</span></label>
                                    <select name="purpose" class="form-select" <?php echo $activeApptTab === 'member' ? 'required' : ''; ?>>
                                        <?php
                                        $purposes = [
                                            'account_inquiry'  => isEnglish() ? 'Account Inquiry'  : 'खाता जानकारी',
                                            'loan_inquiry'     => isEnglish() ? 'Loan Inquiry'      : 'ऋण जानकारी',
                                            'kyc_update'       => isEnglish() ? 'KYC Update'        : 'केवाइसी अपडेट',
                                            'loan_repayment'   => isEnglish() ? 'Loan Repayment'    : 'ऋण भुक्तानी',
                                            'account_opening'  => isEnglish() ? 'Account Opening'   : 'खाता खोल्ने',
                                            'other'            => isEnglish() ? 'Other'             : 'अन्य',
                                        ];
                                        $postPurpose = $postIsMember ? ($_POST['purpose'] ?? '') : '';
                                        foreach ($purposes as $val => $label):
                                            $sel = ($postPurpose === $val) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo $val; ?>" <?php echo $sel; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Branch' : 'शाखा'; ?></label>
                                    <select name="branch" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select Branch' : 'शाखा छान्नुहोस्'; ?></option>
                                        <?php foreach ($branches as $br): ?>
                                        <option value="<?php echo htmlspecialchars($br['name']); ?>"><?php echo htmlspecialchars($br['name']); ?></option>
                                        <?php endforeach; ?>
                                        <option value="head_office"><?php echo isEnglish() ? 'Head Office' : 'प्रधान कार्यालय'; ?></option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label"><?php echo isEnglish() ? 'Purpose Details' : 'उद्देश्य विवरण'; ?></label>
                                    <textarea name="purpose_detail" class="form-control" rows="2"
                                              placeholder="<?php echo isEnglish() ? 'Briefly describe your purpose...' : 'आफ्नो उद्देश्य संक्षेपमा लेख्नुहोस्...'; ?>"><?php echo htmlspecialchars($postIsMember ? ($_POST['purpose_detail'] ?? '') : '', ENT_QUOTES); ?></textarea>
                                </div>
                            </div>

                            <div class="form-card-title mb-3">
                                <i class="fas fa-clock"></i>
                                <?php echo isEnglish() ? 'Preferred Date & Time' : 'रुचाइएको मिति र समय'; ?>
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Preferred Date (B.S.)' : 'रुचाइएको मिति (बि.सं.)'; ?> <span class="req">*</span></label>
                                    <div class="input-group">
                                        <input type="text" name="preferred_date" id="apptDate"
                                               class="form-control nepali-datepicker" placeholder="YYYY-MM-DD" <?php echo $activeApptTab === 'member' ? 'required' : ''; ?>
                                               value="<?php echo htmlspecialchars($postIsMember ? ($_POST['preferred_date'] ?? '') : '', ENT_QUOTES); ?>">
                                        <span class="input-group-text cursor-pointer" onclick="document.getElementById('apptDate').focus();">
                                            <i class="fas fa-calendar-alt"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Preferred Time' : 'रुचाइएको समय'; ?> <span class="req">*</span></label>
                                    <select name="preferred_time" class="form-select" <?php echo $activeApptTab === 'member' ? 'required' : ''; ?>>
                                        <option value=""><?php echo isEnglish() ? 'Select time' : 'समय छान्नुहोस्'; ?></option>
                                        <?php foreach ($apptTimeOptions as $optVal => $optLabel): ?>
                                        <option value="<?php echo htmlspecialchars($optVal, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $apptTimeValueMember === $optVal ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <?php if ($apptTimeValueMember !== '' && !isset($apptTimeOptions[$apptTimeValueMember])): ?>
                                        <option value="<?php echo htmlspecialchars($apptTimeValueMember, ENT_QUOTES, 'UTF-8'); ?>" selected>
                                            <?php echo htmlspecialchars($apptTimeValueMember, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="d-flex gap-2 justify-content-end pt-2 border-top mt-2">
                                <a href="<?php echo SITE_URL; ?>" class="btn btn-light px-4">
                                    <i class="fas fa-arrow-left me-1"></i><?php echo isEnglish() ? 'Cancel' : 'फर्कनुहोस्'; ?>
                                </a>
                                <button type="submit" class="btn btn-primary px-5">
                                    <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span><i class="fas fa-calendar-check me-2"></i>
                                    <?php echo isEnglish() ? 'Book Appointment' : 'भेटघाट बुक गर्नुहोस्'; ?>
                                </button>
                            </div>
                        </form>
                            </div>

                            <div class="tab-pane fade <?php echo $activeApptTab === 'cooperative' ? 'show active' : ''; ?>" id="appt-tab-coop" role="tabpanel" aria-labelledby="appt-tab-coop-btn" tabindex="0">
                        <form method="POST" id="cooperativeVisitForm" class="needs-validation" novalidate>
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="visit_kind" value="cooperative">

                            <p class="text-muted small mb-3">
                                <?php echo isEnglish()
                                    ? 'For institutional / cooperative-to-cooperative visits. Member appointments stay on the other tab.'
                                    : 'संस्थागत / सहकारी–सहकारी भ्रमणका लागि। सदस्य भेटघाट अर्को ट्याबमा रहन्छ।'; ?>
                            </p>

                            <div class="form-card-title mb-3">
                                <i class="fas fa-building"></i>
                                <?php echo isEnglish() ? 'Cooperative Details' : 'सहकारी विवरण'; ?>
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Cooperative Name' : 'सहकारीको नाम'; ?> <span class="req">*</span></label>
                                    <input type="text" name="organization_name" class="form-control" required
                                           value="<?php echo htmlspecialchars(!$postIsMember ? ($_POST['organization_name'] ?? '') : '', ENT_QUOTES); ?>"
                                           placeholder="<?php echo isEnglish() ? 'Cooperative / institution name' : 'सहकारी / संस्थाको नाम'; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Contact Person' : 'सम्पर्क व्यक्ति'; ?> <span class="req">*</span></label>
                                    <input type="text" name="contact_person" class="form-control" required
                                           value="<?php echo htmlspecialchars(!$postIsMember ? ($_POST['contact_person'] ?? '') : '', ENT_QUOTES); ?>"
                                           placeholder="<?php echo isEnglish() ? 'Full name of contact person' : 'सम्पर्क व्यक्तिको पूरा नाम'; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Phone Number' : 'फोन नम्बर'; ?> <span class="req">*</span></label>
                                    <input type="tel" name="phone" class="form-control" required
                                           maxlength="10" inputmode="numeric" pattern="[0-9]{10}" placeholder="98XXXXXXXX"
                                           value="<?php echo htmlspecialchars(!$postIsMember ? ($_POST['phone'] ?? '') : '', ENT_QUOTES); ?>">
                                    <div class="invalid-feedback"><?php echo isEnglish() ? 'Enter a valid 10-digit mobile number.' : '१० अंकको मोबाइल नम्बर राख्नुहोस्।'; ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Website' : 'वेबसाइट'; ?> <span class="text-muted small">(<?php echo isEnglish() ? 'optional' : 'ऐच्छिक'; ?>)</span></label>
                                    <input type="text" name="organization_website" class="form-control" inputmode="url" autocomplete="url"
                                           value="<?php echo htmlspecialchars(!$postIsMember ? ($_POST['organization_website'] ?? '') : '', ENT_QUOTES); ?>"
                                           placeholder="https://example.coop.np">
                                </div>
                                <div class="col-12">
                                    <label class="form-label"><?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?> <span class="req">*</span></label>
                                    <input type="text" name="organization_address" class="form-control" required
                                           value="<?php echo htmlspecialchars(!$postIsMember ? ($_POST['organization_address'] ?? '') : '', ENT_QUOTES); ?>"
                                           placeholder="<?php echo isEnglish() ? 'Full address of the cooperative' : 'सहकारीको पूरा ठेगाना'; ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label"><?php echo isEnglish() ? 'Visit Details' : 'भ्रमण विवरण'; ?> <span class="req">*</span></label>
                                    <textarea name="purpose_detail" class="form-control" rows="3" required
                                              placeholder="<?php echo isEnglish() ? 'Purpose of the visit, agenda, number of visitors...' : 'भ्रमणको उद्देश्य, एजेन्डा, आउने व्यक्ति संख्या...'; ?>"><?php echo htmlspecialchars(!$postIsMember ? ($_POST['purpose_detail'] ?? '') : '', ENT_QUOTES); ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Preferred Branch' : 'रुचाइएको शाखा'; ?></label>
                                    <select name="branch" class="form-select">
                                        <option value=""><?php echo isEnglish() ? 'Select Branch' : 'शाखा छान्नुहोस्'; ?></option>
                                        <?php foreach ($branches as $br): ?>
                                        <option value="<?php echo htmlspecialchars($br['name']); ?>"><?php echo htmlspecialchars($br['name']); ?></option>
                                        <?php endforeach; ?>
                                        <option value="head_office"><?php echo isEnglish() ? 'Head Office' : 'प्रधान कार्यालय'; ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Email' : 'इमेल'; ?> <span class="text-muted small">(<?php echo isEnglish() ? 'optional' : 'ऐच्छिक'; ?>)</span></label>
                                    <input type="email" name="email" class="form-control"
                                           value="<?php echo htmlspecialchars(!$postIsMember ? ($_POST['email'] ?? '') : '', ENT_QUOTES); ?>"
                                           placeholder="example@gmail.com">
                                </div>
                            </div>

                            <div class="form-card-title mb-3">
                                <i class="fas fa-clock"></i>
                                <?php echo isEnglish() ? 'Preferred Visit Date & Time' : 'रुचाइएको भ्रमण मिति र समय'; ?>
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Preferred Date (B.S.)' : 'रुचाइएको मिति (बि.सं.)'; ?> <span class="req">*</span></label>
                                    <div class="input-group">
                                        <input type="text" name="preferred_date" id="apptDateCoop"
                                               class="form-control nepali-datepicker" placeholder="YYYY-MM-DD" required
                                               value="<?php echo htmlspecialchars(!$postIsMember ? ($_POST['preferred_date'] ?? '') : '', ENT_QUOTES); ?>">
                                        <span class="input-group-text cursor-pointer" onclick="document.getElementById('apptDateCoop').focus();">
                                            <i class="fas fa-calendar-alt"></i>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label"><?php echo isEnglish() ? 'Preferred Time' : 'रुचाइएको समय'; ?> <span class="req">*</span></label>
                                    <select name="preferred_time" class="form-select" required>
                                        <option value=""><?php echo isEnglish() ? 'Select time' : 'समय छान्नुहोस्'; ?></option>
                                        <?php foreach ($apptTimeOptions as $optVal => $optLabel): ?>
                                        <option value="<?php echo htmlspecialchars($optVal, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $apptTimeValueCoop === $optVal ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                        <?php if ($apptTimeValueCoop !== '' && !isset($apptTimeOptions[$apptTimeValueCoop])): ?>
                                        <option value="<?php echo htmlspecialchars($apptTimeValueCoop, ENT_QUOTES, 'UTF-8'); ?>" selected>
                                            <?php echo htmlspecialchars($apptTimeValueCoop, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="d-flex gap-2 justify-content-end pt-2 border-top mt-2">
                                <a href="<?php echo SITE_URL; ?>" class="btn btn-light px-4">
                                    <i class="fas fa-arrow-left me-1"></i><?php echo isEnglish() ? 'Cancel' : 'फर्कनुहोस्'; ?>
                                </a>
                                <button type="submit" class="btn btn-primary px-5">
                                    <i class="fas fa-handshake me-2"></i>
                                    <?php echo isEnglish() ? 'Request Cooperative Visit' : 'सहकारी भ्रमण अनुरोध'; ?>
                                </button>
                            </div>
                        </form>
                            </div>
                        </div>


                    </div><!-- /card-body -->
                </div><!-- /card -->
            </div>

        </div><!-- /row -->
    </div><!-- /container -->
</section>
<style>
.appt-kind-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
}
.appt-kind-tabs .nav-item { flex: 0 0 auto; }
.appt-kind-tabs .nav-link {
    border: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 22%, #d1d5db);
    color: var(--primary-color, #1a5f2a);
    font-weight: 600;
    border-radius: 999px;
    padding: .45rem 1rem;
    background: #fff;
    white-space: nowrap;
}
.appt-kind-tabs .nav-link.active {
    background: var(--primary-color, #1a5f2a);
    border-color: var(--primary-color, #1a5f2a);
    color: #fff;
}
</style>
<script>
(function () {
  var form = document.getElementById('cooperativeVisitForm');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    if (form.checkValidity()) return;
    e.preventDefault();
    e.stopPropagation();
    form.classList.add('was-validated');
    var bad = form.querySelector(':invalid');
    if (bad && typeof bad.scrollIntoView === 'function') {
      bad.scrollIntoView({ behavior: 'smooth', block: 'center' });
      try { bad.focus({ preventScroll: true }); } catch (err) { bad.focus(); }
    }
  }, true);
})();
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
