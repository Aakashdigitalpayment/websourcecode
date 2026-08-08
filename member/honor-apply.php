<?php
/**
 * Member Portal — सम्मान आवेदन
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/honor-tables.php';
require_once __DIR__ . '/../includes/honor-submit-helper.php';
requireMemberLogin();
memberSecurityHeaders();

$db = getDB();
$mem = currentMember();
if (!$mem) {
    header('Location: login.php?msg=session_expired');
    exit;
}
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

$memberPortalId = (int)$mem['id'];
$memEmail = trim((string)($mem['email'] ?? ''));
$memPhone = preg_replace('/[^0-9]/', '', (string)($mem['phone'] ?? ''));
$memName = trim((string)($mem['name'] ?? ''));
require __DIR__ . '/../includes/member-portal-identity.php';

$rPhone = $memPhone ?: preg_replace('/[^0-9]/', '', (string)($kycRow['mobile'] ?? ''));
$rEmail = $memEmail ?: trim((string)($kycRow['email'] ?? ''));
if ($rPhone !== '') {
    $memPhone = $rPhone;
}
if ($rEmail !== '') {
    $memEmail = $rEmail;
}

ensureHonorTables($db);
$openPrograms = honorFetchOpenPrograms($db);
$upcomingPrograms = honorFetchUpcomingPrograms($db);
$hasOpen = !empty($openPrograms);
$hasUpcoming = !empty($upcomingPrograms);

$selectedProgramId = (int)($_POST['program_id'] ?? $_GET['program_id'] ?? 0);
$openIds = array_map(static fn($p) => (int)$p['id'], $openPrograms);
if ($selectedProgramId < 1 || !in_array($selectedProgramId, $openIds, true)) {
    $selectedProgramId = $openIds[0] ?? 0;
}
$programCats = $selectedProgramId > 0 ? honorFetchProgramCategories($db, $selectedProgramId) : [];

$successMsg = '';
$errorMsg = '';
$trackingId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_honor') {
    if (!verifyCSRFToken()) {
        $errorMsg = $_t('सुरक्षा जाँच असफल।', 'Security check failed.');
    } elseif (!$hasOpen) {
        $errorMsg = $_t('हाल आवेदन बन्द छ।', 'Applications are closed.');
    } elseif ($memSadasyata === '') {
        $errorMsg = $_t('सदस्य नम्बर फेला परेन। कृपया KYM / प्रोफाइल अपडेट गर्नुहोस्।', 'Member number missing. Please update KYC / profile.');
    } elseif (!checkRateLimit('honor_portal_' . $memberPortalId, 5, 3600)) {
        $errorMsg = $_t('धेरै अनुरोधहरू भए।', 'Too many requests.');
    } else {
        $result = submitHonorApplicationUnified($db, [
            'program_id' => (int)($_POST['program_id'] ?? 0),
            'category_id' => (int)($_POST['category_id'] ?? 0),
            'applicant_name' => $memName,
            'phone' => $memPhone,
            'email' => $memEmail,
            'address' => clean_text($_POST['address'] ?? '', 255),
            'is_member' => true,
            'member_id' => $memSadasyata,
            'member_portal_id' => $memberPortalId,
            'nominee_name' => clean_text($_POST['nominee_name'] ?? '', 160),
            'nominee_relation' => clean_text($_POST['nominee_relation'] ?? '', 80),
            'exam_year' => clean_text($_POST['exam_year'] ?? '', 40),
            'institution' => clean_text($_POST['institution'] ?? '', 200),
            'business_note' => clean_text($_POST['business_note'] ?? '', 255),
            'description' => clean_text($_POST['description'] ?? '', 4000),
        ], $_FILES);
        if (!empty($result['ok'])) {
            $successMsg = $_t('आवेदन दर्ता भयो।', 'Application submitted.');
            $trackingId = (string)$result['tracking_id'];
        } else {
            $errorMsg = isEnglish()
                ? (string)($result['error_en'] ?? 'Submit failed.')
                : (string)($result['error'] ?? 'दर्ता असफल।');
        }
    }
}

$selectedProgramId = (int)($_POST['program_id'] ?? $selectedProgramId);
if ($selectedProgramId < 1 || !in_array($selectedProgramId, $openIds, true)) {
    $selectedProgramId = $openIds[0] ?? 0;
}
$programCats = $selectedProgramId > 0 ? honorFetchProgramCategories($db, $selectedProgramId) : [];

$myApps = [];
try {
    $st = $db->prepare('SELECT a.*, p.title_np AS program_title, c.name_np AS category_name
        FROM honor_applications a
        LEFT JOIN honor_programs p ON p.id = a.program_id
        LEFT JOIN honor_categories c ON c.id = a.category_id
        WHERE a.member_portal_id = ? OR a.phone = ? OR (a.email <> "" AND a.email = ?)
        ORDER BY a.created_at DESC LIMIT 20');
    $st->execute([$memberPortalId, $memPhone, $memEmail]);
    $myApps = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $myApps = [];
}

$pageTitle = $_t('सम्मान आवेदन', 'Honor Application');
require __DIR__ . '/includes/chrome.php';
?>
<div class="container py-3">
    <h4 class="mb-3"><i class="fas fa-award me-2"></i><?php echo htmlspecialchars($pageTitle); ?></h4>

    <?php if ($successMsg): ?>
    <div class="alert alert-success">
        <?php echo htmlspecialchars($successMsg); ?>
        <?php if ($trackingId): ?>
        <div class="mt-1"><strong>Tracking:</strong> <code><?php echo htmlspecialchars($trackingId); ?></code></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <?php if (!$hasOpen): ?>
    <?php if ($hasUpcoming): ?>
    <div class="alert alert-warning">
        <strong><?php echo $_t('आवेदन चाँडै खुल्नेछ', 'Applications open soon'); ?></strong>
        <p class="mb-2 small"><?php echo $_t('खुल्ने मितिपछि मात्र फारम भर्न सकिन्छ।', 'You can submit only after the open date.'); ?></p>
        <ul class="mb-0 small">
            <?php foreach ($upcomingPrograms as $up): ?>
            <li>
                <?php echo htmlspecialchars(honorProgramLabel($up, isEnglish())); ?> —
                <?php echo $_t('खुल्ने', 'Opens'); ?>:
                <strong><?php echo htmlspecialchars(honorFormatDtBs((string)$up['opens_at'])); ?></strong>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php else: ?>
    <div class="alert alert-info"><?php echo $_t('हाल कुनै खुला सम्मान आवेदन कार्यक्रम छैन।', 'No honor application program is open right now.'); ?></div>
    <?php endif; ?>
    <?php elseif ($memSadasyata === ''): ?>
    <div class="alert alert-warning"><?php echo $_t('सदस्य नम्बर फेला परेन। आवेदन दिन पहिले KYM / प्रोफाइलमा सदस्य नम्बर अपडेट गर्नुहोस्।', 'Member number missing. Update KYC/profile before applying.'); ?></div>
    <?php else: ?>
    <div class="card mb-4">
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="submit_honor">

                <?php if (count($openPrograms) > 1): ?>
                <div class="mb-3">
                    <label for="mha_program_id" class="form-label"><?php echo $_t('कार्यक्रम', 'Program'); ?></label>
                    <select name="program_id" id="mha_program_id" class="form-select" required onchange="location.href='honor-apply.php?program_id='+encodeURIComponent(this.value)">
                        <?php foreach ($openPrograms as $op): ?>
                        <option value="<?php echo (int)$op['id']; ?>" <?php echo $selectedProgramId === (int)$op['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(honorProgramLabel($op, isEnglish())); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <input type="hidden" name="program_id" value="<?php echo (int)$selectedProgramId; ?>">
                <p class="small text-muted"><?php echo htmlspecialchars(honorProgramLabel($openPrograms[0], isEnglish())); ?> · <?php echo $_t('बन्द', 'Closes'); ?>: <?php echo htmlspecialchars(honorFormatDtBs((string)$openPrograms[0]['closes_at'])); ?></p>
                <?php endif; ?>

                <div class="mb-3">
                    <label for="memberHonorCategory" class="form-label"><?php echo $_t('कोटि', 'Category'); ?> *</label>
                    <select name="category_id" id="memberHonorCategory" class="form-select" required>
                        <option value=""><?php echo $_t('छान्नुहोस्…', 'Select…'); ?></option>
                        <?php foreach ($programCats as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>"
                                data-nominee="<?php echo !empty($c['requires_nominee']) ? '1' : '0'; ?>"
                                data-doc="<?php echo !empty($c['requires_document']) ? '1' : '0'; ?>"
                                data-slug="<?php echo htmlspecialchars((string)$c['slug']); ?>">
                            <?php echo htmlspecialchars(honorCategoryLabel($c, isEnglish())); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label for="mha_name" class="form-label"><?php echo $_t('नाम', 'Name'); ?></label>
                        <input type="text" id="mha_name" class="form-control" value="<?php echo htmlspecialchars($memName); ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label for="mha_member_no" class="form-label"><?php echo $_t('सदस्य नं.', 'Member No.'); ?></label>
                        <input type="text" id="mha_member_no" class="form-control" value="<?php echo htmlspecialchars($memSadasyata); ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label for="mha_phone" class="form-label"><?php echo $_t('फोन', 'Phone'); ?></label>
                        <input type="text" id="mha_phone" class="form-control" value="<?php echo htmlspecialchars($memPhone); ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label for="mha_email" class="form-label"><?php echo $_t('इमेल', 'Email'); ?></label>
                        <input type="text" id="mha_email" class="form-control" value="<?php echo htmlspecialchars($memEmail); ?>" readonly>
                    </div>
                    <div class="col-12">
                        <label for="mha_address" class="form-label"><?php echo $_t('ठेगाना', 'Address'); ?></label>
                        <input type="text" name="address" id="mha_address" class="form-control" maxlength="255" value="<?php echo htmlspecialchars((string)($_POST['address'] ?? '')); ?>">
                    </div>
                    <div class="col-md-6 member-honor-nominee">
                        <label for="mha_nominee_name" class="form-label"><?php echo $_t('नामांकित नाम', 'Nominee name'); ?></label>
                        <input type="text" name="nominee_name" id="mha_nominee_name" class="form-control" maxlength="160">
                    </div>
                    <div class="col-md-6 member-honor-nominee">
                        <label for="mha_nominee_relation" class="form-label"><?php echo $_t('नाता', 'Relation'); ?></label>
                        <select name="nominee_relation" id="mha_nominee_relation" class="form-select">
                            <option value=""><?php echo $_t('छान्नुहोस्…', 'Select…'); ?></option>
                            <option value="छोरा"><?php echo $_t('छोरा', 'Son'); ?></option>
                            <option value="छोरी"><?php echo $_t('छोरी', 'Daughter'); ?></option>
                            <option value="आफैं"><?php echo $_t('आफैं', 'Self'); ?></option>
                            <option value="अन्य"><?php echo $_t('अन्य', 'Other'); ?></option>
                        </select>
                    </div>
                    <div class="col-md-6 member-honor-edu">
                        <label for="mha_exam_year" class="form-label"><?php echo $_t('परीक्षा वर्ष', 'Exam year'); ?></label>
                        <input type="text" name="exam_year" id="mha_exam_year" class="form-control" maxlength="40">
                    </div>
                    <div class="col-md-6 member-honor-edu">
                        <label for="mha_institution" class="form-label"><?php echo $_t('संस्था', 'Institution'); ?></label>
                        <input type="text" name="institution" id="mha_institution" class="form-control" maxlength="200">
                    </div>
                    <div class="col-12 member-honor-biz" style="display:none">
                        <label for="mha_business_note" class="form-label"><?php echo $_t('कारोबार नोट', 'Business note'); ?></label>
                        <input type="text" name="business_note" id="mha_business_note" class="form-control" maxlength="255">
                    </div>
                    <div class="col-12">
                        <label for="mha_description" class="form-label"><?php echo $_t('विवरण', 'Description'); ?></label>
                        <textarea name="description" id="mha_description" class="form-control" rows="3" maxlength="4000"></textarea>
                    </div>
                    <div class="col-12">
                        <label for="mha_attachment" class="form-label"><?php echo $_t('प्रमाण कागजात', 'Document'); ?> <span id="memberHonorDocReq" class="text-danger" style="display:none">*</span></label>
                        <input type="file" name="attachment" id="mha_attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.webp">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $_t('पठाउनुहोस्', 'Submit'); ?></button>
            </form>
        </div>
    </div>
    <script>
    (function () {
        var cat = document.getElementById('memberHonorCategory');
        var docReq = document.getElementById('memberHonorDocReq');
        function toggle() {
            if (!cat) return;
            var opt = cat.options[cat.selectedIndex];
            var needsNominee = opt && opt.getAttribute('data-nominee') === '1';
            var needsDoc = opt && opt.getAttribute('data-doc') === '1';
            var slug = opt ? (opt.getAttribute('data-slug') || '') : '';
            document.querySelectorAll('.member-honor-nominee, .member-honor-edu').forEach(function (el) {
                el.style.display = needsNominee ? '' : 'none';
            });
            document.querySelectorAll('.member-honor-biz').forEach(function (el) {
                el.style.display = (slug.indexOf('best_') === 0) ? '' : 'none';
            });
            if (docReq) docReq.style.display = needsDoc ? '' : 'none';
        }
        if (cat) {
            cat.addEventListener('change', toggle);
            toggle();
        }
    })();
    </script>
    <?php endif; ?>

    <?php if (!empty($myApps)): ?>
    <h5 class="mb-2"><?php echo $_t('मेरा आवेदन', 'My applications'); ?></h5>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr>
                <th>ID</th><th><?php echo $_t('कार्यक्रम', 'Program'); ?></th><th><?php echo $_t('कोटि', 'Category'); ?></th><th><?php echo $_t('स्थिति', 'Status'); ?></th><th><?php echo $_t('मिति', 'Date'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($myApps as $a): ?>
                <tr>
                    <td><code><?php echo htmlspecialchars((string)$a['tracking_id']); ?></code></td>
                    <td><?php echo htmlspecialchars((string)($a['program_title'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($a['category_name'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars(honorStatusLabel((string)$a['status'], isEnglish())); ?></td>
                    <td class="small"><?php echo htmlspecialchars((string)$a['created_at']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
