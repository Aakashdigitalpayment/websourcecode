<?php
/**
 * Public — सम्मान आवेदन (Honor Application)
 */
require_once 'includes/config.php';
require_once 'includes/honor-tables.php';
require_once 'includes/honor-submit-helper.php';
$kycPublicFormFile = __DIR__ . '/includes/kyc-public-form.php';
if (is_file($kycPublicFormFile)) {
    require_once $kycPublicFormFile;
}

$pageTitle = isEnglish() ? 'Honor Application' : 'सम्मान आवेदन';
require_once 'includes/header.php';
$L = getLangStrings();

$db = getDB();
ensureHonorTables($db);

$openPrograms = honorFetchOpenPrograms($db);
$upcomingPrograms = honorFetchUpcomingPrograms($db);
$hasOpen = !empty($openPrograms);
$hasUpcoming = !empty($upcomingPrograms);
$success = false;
$error = '';
$trackingId = '';
$loggedMember = function_exists('getLoggedInMemberProfile') ? getLoggedInMemberProfile() : null;

$selectedProgramId = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);
$openIds = array_map(static fn($p) => (int)$p['id'], $openPrograms);
if ($selectedProgramId < 1 || !in_array($selectedProgramId, $openIds, true)) {
    $selectedProgramId = $openIds[0] ?? 0;
}
$programCats = $selectedProgramId > 0 ? honorFetchProgramCategories($db, $selectedProgramId) : [];
$programMeta = [];
foreach ($openPrograms as $op) {
    $programMeta[(int)$op['id']] = $op;
}
$activeProgram = $programMeta[$selectedProgramId] ?? ($openPrograms[0] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$hasOpen) {
        $error = isEnglish() ? 'Applications are currently closed.' : 'हाल आवेदन बन्द छ।';
    } elseif (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed.' : 'सुरक्षा जाँच असफल।';
    } elseif (!checkRateLimit('honor_application', 8, 3600)) {
        $error = isEnglish() ? 'Too many requests. Please try again later.' : 'धेरै अनुरोधहरू भए। कृपया पछि प्रयास गर्नुहोस्।';
    } else {
        $applicantName = clean_text($_POST['applicant_name'] ?? '', 160);
        $phone = preg_replace('/[^0-9]/', '', clean_text($_POST['phone'] ?? '', 20));
        $email = strtolower(clean_text($_POST['email'] ?? '', 120));
        $address = clean_text($_POST['address'] ?? '', 255);
        $isCoopMember = $loggedMember ? 'yes' : ((($_POST['is_coop_member'] ?? '') === 'yes') ? 'yes' : 'no');
        $memberId = clean_text($_POST['member_id'] ?? '', 50);
        $memberPortalId = $loggedMember ? (int)($loggedMember['id'] ?? 0) : null;
        $programId = (int)($_POST['program_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);

        if ($loggedMember) {
            $kycMerge = null;
            if (function_exists('loadKycRowForLoggedMemberPublic')) {
                $kycMerge = loadKycRowForLoggedMemberPublic($db, $loggedMember);
            }
            $fnK = (is_array($kycMerge) && !empty($kycMerge['full_name'])) ? trim((string)$kycMerge['full_name']) : '';
            $applicantName = $fnK !== '' ? $fnK : trim((string)($loggedMember['name'] ?? $applicantName));
            $midK = (is_array($kycMerge) && !empty($kycMerge['member_id'])) ? trim((string)$kycMerge['member_id']) : '';
            $memberId = $midK !== '' ? $midK : trim((string)($loggedMember['sadasyata_number'] ?? $memberId));
            $phone = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $loggedMember['phone'] ?? $phone));
            $email = strtolower(trim((string)($kycMerge['email'] ?? $loggedMember['email'] ?? $email)));
            $isCoopMember = 'yes';
        } elseif ($isCoopMember === 'yes') {
            if (function_exists('verifyPublicFormKycApprovedByMemberId')) {
                $v = verifyPublicFormKycApprovedByMemberId($db, $_POST['member_id'] ?? '');
                if (!$v['ok']) {
                    $error = isEnglish() ? $v['msg_en'] : $v['msg_np'];
                } else {
                    $kycMerge = $v['row'];
                    $applicantName = trim((string)($kycMerge['full_name'] ?? $applicantName));
                    $memberId = strtoupper(trim((string)($kycMerge['member_id'] ?? $memberId)));
                    $phone = preg_replace('/[^0-9]/', '', (string)($kycMerge['mobile'] ?? $phone));
                    $email = strtolower(trim((string)($kycMerge['email'] ?? $email)));
                }
            }
        }

        if ($error === '') {
            $result = submitHonorApplicationUnified($db, [
                'program_id' => $programId,
                'category_id' => $categoryId,
                'applicant_name' => $applicantName,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'is_member' => $isCoopMember === 'yes',
                'member_id' => $memberId,
                'member_portal_id' => $memberPortalId,
                'nominee_name' => clean_text($_POST['nominee_name'] ?? '', 160),
                'nominee_relation' => clean_text($_POST['nominee_relation'] ?? '', 80),
                'exam_year' => clean_text($_POST['exam_year'] ?? '', 40),
                'institution' => clean_text($_POST['institution'] ?? '', 200),
                'business_note' => clean_text($_POST['business_note'] ?? '', 255),
                'description' => clean_text($_POST['description'] ?? '', 4000),
            ], $_FILES);

            if (!empty($result['ok'])) {
                $success = true;
                $trackingId = (string)$result['tracking_id'];
                if (function_exists('logSecurityEvent')) {
                    logSecurityEvent('honor_application_submitted', 'Honor application ' . $trackingId);
                }
            } else {
                $error = isEnglish()
                    ? (string)($result['error_en'] ?? $result['error'] ?? 'Submit failed.')
                    : (string)($result['error'] ?? 'दर्ता असफल।');
            }
        }

        $selectedProgramId = $programId;
        $programCats = $selectedProgramId > 0 ? honorFetchProgramCategories($db, $selectedProgramId) : [];
        $activeProgram = $programMeta[$selectedProgramId] ?? $activeProgram;
    }
}

$catJson = [];
foreach ($openPrograms as $op) {
    $cats = honorFetchProgramCategories($db, (int)$op['id']);
    $catJson[(int)$op['id']] = array_map(static function ($c) {
        return [
            'id' => (int)$c['id'],
            'label' => honorCategoryLabel($c, isEnglish()),
            'requires_nominee' => !empty($c['requires_nominee']),
            'requires_document' => !empty($c['requires_document']),
            'slug' => (string)$c['slug'],
        ];
    }, $cats);
}
?>

<section class="page-banner">
    <div class="container">
        <h1><i class="fas fa-award"></i> <?php echo htmlspecialchars($pageTitle); ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($pageTitle); ?></li>
            </ol>
        </nav>
    </div>
</section>

<section class="section-padding">
    <div class="container">
        <?php if ($success): ?>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="success-card form-success-card text-center">
                    <div class="success-icon form-success-icon"><i class="fas fa-check-circle"></i></div>
                    <h3><?php echo isEnglish() ? 'Application submitted successfully!' : 'आवेदन सफलतापूर्वक दर्ता भयो!'; ?></h3>
                    <div class="form-tracking-box">
                        <div class="text-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID' : 'तपाईंको Tracking ID'; ?></div>
                        <div class="form-tracking-id" id="hnrTrkId"><?php echo htmlspecialchars($trackingId); ?></div>
                        <div class="form-tracking-help mt-2">
                            <a href="<?php echo SITE_URL; ?>application-tracker.php"><?php echo isEnglish() ? 'Track application' : 'आवेदन ट्र्याक गर्नुहोस्'; ?></a>
                        </div>
                    </div>
                    <div class="action-buttons mt-3">
                        <a href="<?php echo SITE_URL; ?>honor-apply.php" class="btn btn-primary"><?php echo isEnglish() ? 'New application' : 'नयाँ आवेदन'; ?></a>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif (!$hasOpen): ?>
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <?php if ($hasUpcoming): ?>
                <div class="alert alert-warning text-center py-4 mb-4">
                    <i class="fas fa-hourglass-half fa-2x mb-3 d-block"></i>
                    <h4 class="mb-2"><?php echo isEnglish() ? 'Applications open soon' : 'आवेदन चाँडै खुल्नेछ'; ?></h4>
                    <p class="mb-3 text-muted"><?php echo isEnglish()
                        ? 'The program is published. You can apply only after the open date/time.'
                        : 'कार्यक्रम प्रकाशित छ। खुल्ने मिति/समयपछि मात्र आवेदन दिन सकिन्छ।'; ?></p>
                </div>
                <div class="list-group shadow-sm">
                    <?php foreach ($upcomingPrograms as $up): ?>
                    <div class="list-group-item">
                        <div class="fw-bold"><?php echo htmlspecialchars(honorProgramLabel($up, isEnglish())); ?></div>
                        <?php if (!empty($up['event_label'])): ?>
                        <div class="small text-muted"><?php echo htmlspecialchars((string)$up['event_label']); ?><?php echo !empty($up['fiscal_year']) ? ' · ' . htmlspecialchars((string)$up['fiscal_year']) : ''; ?></div>
                        <?php endif; ?>
                        <div class="small mt-2">
                            <span class="badge bg-warning text-dark"><?php echo isEnglish() ? 'Upcoming' : 'चाँडै खुल्ने'; ?></span>
                            <?php echo isEnglish() ? 'Opens' : 'खुल्ने'; ?>:
                            <strong><?php echo htmlspecialchars(honorFormatDtBs((string)$up['opens_at'])); ?></strong>
                            <span class="text-muted">→ <?php echo htmlspecialchars(honorFormatDtBs((string)$up['closes_at'])); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-info text-center py-5">
                    <i class="fas fa-calendar-times fa-3x mb-3 d-block opacity-50"></i>
                    <h4><?php echo isEnglish() ? 'Applications are closed' : 'आवेदन हाल बन्द छ'; ?></h4>
                    <p class="mb-0 text-muted"><?php echo isEnglish()
                        ? 'Honor applications open only during AGM / annual celebration windows set by the cooperative.'
                        : 'सम्मान आवेदन AGM / वार्षिक उत्सवका लागि प्रशासनले तोकेको अवधिमा मात्र खुल्छ।'; ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($activeProgram): ?>
        <div class="alert alert-success">
            <strong><?php echo htmlspecialchars(honorProgramLabel($activeProgram, isEnglish())); ?></strong>
            <?php if (!empty($activeProgram['event_label'])): ?>
            — <?php echo htmlspecialchars((string)$activeProgram['event_label']); ?>
            <?php endif; ?>
            <div class="small mt-1">
                <?php echo isEnglish() ? 'Open until' : 'बन्द हुने'; ?>:
                <?php echo htmlspecialchars(honorFormatDtBs((string)$activeProgram['closes_at'])); ?>
            </div>
            <?php
            $inst = isEnglish()
                ? trim((string)($activeProgram['instructions_en'] ?: $activeProgram['instructions_np'] ?? ''))
                : trim((string)($activeProgram['instructions_np'] ?: $activeProgram['instructions_en'] ?? ''));
            if ($inst !== ''): ?>
            <div class="mt-2"><?php echo nl2br(htmlspecialchars($inst)); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4">
                        <form method="post" enctype="multipart/form-data" id="honorApplyForm">
                            <?php echo csrfField(); ?>

                            <?php if (count($openPrograms) > 1): ?>
                            <div class="mb-3">
                                <label for="honorProgramSelect" class="form-label"><?php echo isEnglish() ? 'Program' : 'कार्यक्रम'; ?> *</label>
                                <select name="program_id" id="honorProgramSelect" class="form-select" required>
                                    <?php foreach ($openPrograms as $op): ?>
                                    <option value="<?php echo (int)$op['id']; ?>" <?php echo $selectedProgramId === (int)$op['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(honorProgramLabel($op, isEnglish())); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php else: ?>
                            <input type="hidden" name="program_id" id="honorProgramSelect" value="<?php echo (int)$selectedProgramId; ?>">
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="honorCategorySelect" class="form-label"><?php echo isEnglish() ? 'Honor category' : 'सम्मान कोटि'; ?> *</label>
                                <select name="category_id" id="honorCategorySelect" class="form-select" required>
                                    <option value=""><?php echo isEnglish() ? 'Select…' : 'छान्नुहोस्…'; ?></option>
                                    <?php foreach ($programCats as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"
                                            data-nominee="<?php echo !empty($c['requires_nominee']) ? '1' : '0'; ?>"
                                            data-doc="<?php echo !empty($c['requires_document']) ? '1' : '0'; ?>"
                                            data-slug="<?php echo htmlspecialchars((string)$c['slug']); ?>"
                                            <?php echo ((int)($_POST['category_id'] ?? 0) === (int)$c['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(honorCategoryLabel($c, isEnglish())); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if (!$loggedMember): ?>
                            <div class="mb-3">
                                <label class="form-label d-block"><?php echo isEnglish() ? 'Are you a cooperative member?' : 'के तपाईं सहकारी सदस्य हुनुहुन्छ?'; ?></label>
                                <div class="d-flex gap-3">
                                    <label class="form-check-label"><input type="radio" name="is_coop_member" value="no" class="form-check-input js-honor-member" <?php echo (($_POST['is_coop_member'] ?? 'no') === 'yes') ? '' : 'checked'; ?>> <?php echo isEnglish() ? 'No' : 'होइन'; ?></label>
                                    <label class="form-check-label"><input type="radio" name="is_coop_member" value="yes" class="form-check-input js-honor-member" <?php echo (($_POST['is_coop_member'] ?? '') === 'yes') ? 'checked' : ''; ?>> <?php echo isEnglish() ? 'Yes' : 'हो'; ?></label>
                                </div>
                            </div>
                            <?php else: ?>
                            <input type="hidden" name="is_coop_member" value="yes">
                            <?php endif; ?>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="honor_applicant_name" class="form-label"><?php echo isEnglish() ? 'Applicant name' : 'आवेदकको नाम'; ?> *</label>
                                    <input type="text" name="applicant_name" id="honor_applicant_name" class="form-control" required maxlength="160"
                                           value="<?php echo htmlspecialchars((string)($_POST['applicant_name'] ?? ($loggedMember['name'] ?? ''))); ?>"
                                           autocomplete="name"
                                           <?php echo $loggedMember ? 'readonly' : ''; ?>>
                                </div>
                                <div class="col-md-6 honor-member-field" style="<?php echo ((!$loggedMember) && (($_POST['is_coop_member'] ?? 'no') !== 'yes')) ? 'display:none' : ''; ?>">
                                    <label for="honor_member_id" class="form-label"><?php echo isEnglish() ? 'Member number' : 'सदस्य नम्बर'; ?> *</label>
                                    <input type="text" name="member_id" id="honor_member_id" class="form-control" maxlength="50"
                                           value="<?php echo htmlspecialchars((string)($_POST['member_id'] ?? ($loggedMember['sadasyata_number'] ?? ''))); ?>"
                                           autocomplete="off"
                                           <?php echo $loggedMember ? 'readonly' : ''; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label for="honor_phone" class="form-label"><?php echo isEnglish() ? 'Phone' : 'फोन'; ?> *</label>
                                    <input type="tel" name="phone" id="honor_phone" class="form-control" required maxlength="20"
                                           value="<?php echo htmlspecialchars((string)($_POST['phone'] ?? ($loggedMember['phone'] ?? ''))); ?>"
                                           autocomplete="tel"
                                           <?php echo $loggedMember ? 'readonly' : ''; ?>>
                                </div>
                                <div class="col-md-6">
                                    <label for="honor_email" class="form-label"><?php echo isEnglish() ? 'Email' : 'इमेल'; ?></label>
                                    <input type="email" name="email" id="honor_email" class="form-control" maxlength="120"
                                           value="<?php echo htmlspecialchars((string)($_POST['email'] ?? ($loggedMember['email'] ?? ''))); ?>"
                                           autocomplete="email">
                                </div>
                                <div class="col-12">
                                    <label for="honor_address" class="form-label"><?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?></label>
                                    <input type="text" name="address" id="honor_address" class="form-control" maxlength="255"
                                           value="<?php echo htmlspecialchars((string)($_POST['address'] ?? '')); ?>"
                                           autocomplete="street-address">
                                </div>
                            </div>

                            <div id="honorNomineeBlock" class="row g-3 mt-1">
                                <div class="col-md-6">
                                    <label for="honor_nominee_name" class="form-label"><?php echo isEnglish() ? 'Nominee name (child / person)' : 'नामांकित नाम (छोरा/छोरी/व्यक्ति)'; ?></label>
                                    <input type="text" name="nominee_name" id="honor_nominee_name" class="form-control" maxlength="160"
                                           value="<?php echo htmlspecialchars((string)($_POST['nominee_name'] ?? '')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label for="honor_nominee_relation" class="form-label"><?php echo isEnglish() ? 'Relation' : 'नाता'; ?></label>
                                    <select name="nominee_relation" id="honor_nominee_relation" class="form-select">
                                        <?php
                                        $rels = isEnglish()
                                            ? ['' => 'Select…', 'छोरा' => 'Son', 'छोरी' => 'Daughter', 'आफैं' => 'Self', 'अन्य' => 'Other']
                                            : ['' => 'छान्नुहोस्…', 'छोरा' => 'छोरा', 'छोरी' => 'छोरी', 'आफैं' => 'आफैं', 'अन्य' => 'अन्य'];
                                        $curRel = (string)($_POST['nominee_relation'] ?? '');
                                        foreach ($rels as $val => $lab):
                                        ?>
                                        <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $curRel === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($lab); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 honor-edu-fields">
                                    <label for="honor_exam_year" class="form-label"><?php echo isEnglish() ? 'Pass / exam year' : 'पास / परीक्षा वर्ष'; ?></label>
                                    <input type="text" name="exam_year" id="honor_exam_year" class="form-control" maxlength="40"
                                           value="<?php echo htmlspecialchars((string)($_POST['exam_year'] ?? '')); ?>">
                                </div>
                                <div class="col-md-6 honor-edu-fields">
                                    <label for="honor_institution" class="form-label"><?php echo isEnglish() ? 'Institution / college' : 'विद्यालय / क्याम्पस'; ?></label>
                                    <input type="text" name="institution" id="honor_institution" class="form-control" maxlength="200"
                                           value="<?php echo htmlspecialchars((string)($_POST['institution'] ?? '')); ?>">
                                </div>
                            </div>

                            <div class="mb-3 mt-3 honor-business-fields" style="display:none">
                                <label for="honor_business_note" class="form-label"><?php echo isEnglish() ? 'Account / product note (optional)' : 'खाता / उत्पादन नोट (ऐच्छिक)'; ?></label>
                                <input type="text" name="business_note" id="honor_business_note" class="form-control" maxlength="255"
                                       value="<?php echo htmlspecialchars((string)($_POST['business_note'] ?? '')); ?>">
                            </div>

                            <div class="mb-3">
                                <label for="honor_description" class="form-label"><?php echo isEnglish() ? 'Brief statement' : 'संक्षिप्त विवरण'; ?></label>
                                <textarea name="description" id="honor_description" class="form-control" rows="4" maxlength="4000"><?php echo htmlspecialchars((string)($_POST['description'] ?? '')); ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="honor_attachment" class="form-label"><?php echo isEnglish() ? 'Supporting document' : 'प्रमाण कागजात'; ?> <span id="honorDocReq" class="text-danger" style="display:none">*</span></label>
                                <input type="file" name="attachment" id="honor_attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.webp">
                                <div class="form-text"><?php echo isEnglish() ? 'PDF or image, max as per site upload limit.' : 'PDF वा फोटो।'; ?></div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-paper-plane me-1"></i>
                                <?php echo isEnglish() ? 'Submit application' : 'आवेदन पठाउनुहोस्'; ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<script>
(function () {
    var map = <?php echo json_encode($catJson, JSON_UNESCAPED_UNICODE); ?> || {};
    var prog = document.getElementById('honorProgramSelect');
    var cat = document.getElementById('honorCategorySelect');
    var nomineeBlock = document.getElementById('honorNomineeBlock');
    var docReq = document.getElementById('honorDocReq');
    var biz = document.querySelector('.honor-business-fields');

    function fillCategories(pid, keep) {
        if (!cat) return;
        var list = map[String(pid)] || map[pid] || [];
        var prev = keep || cat.value;
        cat.innerHTML = '<option value=""><?php echo isEnglish() ? 'Select…' : 'छान्नुहोस्…'; ?></option>';
        list.forEach(function (c) {
            var o = document.createElement('option');
            o.value = c.id;
            o.textContent = c.label;
            o.setAttribute('data-nominee', c.requires_nominee ? '1' : '0');
            o.setAttribute('data-doc', c.requires_document ? '1' : '0');
            o.setAttribute('data-slug', c.slug || '');
            if (String(prev) === String(c.id)) o.selected = true;
            cat.appendChild(o);
        });
        toggleFields();
    }

    function toggleFields() {
        if (!cat) return;
        var opt = cat.options[cat.selectedIndex];
        var needsNominee = opt && opt.getAttribute('data-nominee') === '1';
        var needsDoc = opt && opt.getAttribute('data-doc') === '1';
        var slug = opt ? (opt.getAttribute('data-slug') || '') : '';
        if (nomineeBlock) nomineeBlock.style.display = needsNominee ? '' : 'none';
        document.querySelectorAll('.honor-edu-fields').forEach(function (el) {
            el.style.display = needsNominee ? '' : 'none';
        });
        if (docReq) docReq.style.display = needsDoc ? '' : 'none';
        if (biz) {
            biz.style.display = (slug.indexOf('best_') === 0) ? '' : 'none';
        }
    }

    if (prog && prog.tagName === 'SELECT') {
        prog.addEventListener('change', function () { fillCategories(prog.value); });
        /* Ensure categories match selected program on first paint */
        fillCategories(prog.value, cat ? cat.value : '');
    } else if (prog) {
        fillCategories(prog.value, cat ? cat.value : '');
    }
    if (cat) cat.addEventListener('change', toggleFields);

    document.querySelectorAll('.js-honor-member').forEach(function (el) {
        el.addEventListener('change', function () {
            var yes = document.querySelector('.js-honor-member[value="yes"]');
            var box = document.querySelector('.honor-member-field');
            if (box) box.style.display = (yes && yes.checked) ? '' : 'none';
        });
    });

    toggleFields();
})();
</script>

<?php require_once 'includes/footer.php'; ?>
