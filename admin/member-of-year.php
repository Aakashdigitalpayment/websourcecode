    }
}
</script>

/* ── Client-side search (खोज बक्स) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCSRFToken()) {
    setFlash('error', 'सुरक्षा जाँच असफल।');
    redirect(function_exists('adminSelfUrl') ? adminSelfUrl() : (ADMIN_URL . 'dashboard.php'));
}
if (empty($csrfToken)) $csrfToken = generateCSRFToken();

$db     = getDB();
$errors = [];

require_once __DIR__ . '/../includes/ensure-tables.php';
require_once __DIR__ . '/../includes/member-of-year-tables.php';
ensurePublicTables();
ensureMemberOfYearTable($db);

$uploadDir = ROOT_PATH . 'assets/uploads/member-spotlight/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean_text($_POST['action'] ?? '');

    if (in_array($action, ['add', 'update'])) {
        $id            = (int)($_POST['id']             ?? 0);
        $spotlightYear = clean_text($_POST['spotlight_year'] ?? '');
        $memberName    = clean_text($_POST['member_name']    ?? '');
        $memberNameEn  = clean_text($_POST['member_name_en'] ?? '');
        $memberId      = clean_text($_POST['member_id']      ?? '');
        $memberSince   = clean_text($_POST['member_since']   ?? '');
        $quote         = $_POST['quote']          ?? '';
        $quoteEn       = $_POST['quote_en']       ?? '';
        $achievement   = $_POST['achievement']    ?? '';
        $achievementEn = $_POST['achievement_en'] ?? '';
        $isActive      = (int)($_POST['is_active'] ?? 1);

        if (!$spotlightYear || !preg_match('/^\d{4}$/', $spotlightYear)) $errors[] = 'कृपया सही वर्ष राख्नुहोस् (जस्तै: 2026)।';
        elseif (!$memberName) $errors[] = 'सदस्यको नाम अनिवार्य छ।';

        if (empty($errors)) {
            $photoPath = clean_text($_POST['existing_photo'] ?? '');
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['photo'];
                $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    $errors[] = 'केवल JPG, PNG, WebP images allowed।';
                } elseif (@getimagesize($file['tmp_name']) === false) {
                    $errors[] = 'मान्य छवि फाइल मात्र अपलोड गर्नुहोस्।';
                } elseif ($file['size'] > 4 * 1024 * 1024) {
                    $errors[] = 'Photo size 4MB भन्दा कम हुनुपर्छ।';
                } else {
                    if ($photoPath && file_exists(ROOT_PATH . $photoPath)) @unlink(ROOT_PATH . $photoPath);
                    $fname = 'spotlight_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $fname)) {
                        $photoPath = 'assets/uploads/member-spotlight/' . $fname;
                    } else { $errors[] = 'Photo upload गर्न सकिएन।'; }
                }
            }
        }

        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    $db->prepare("INSERT INTO member_of_year (spotlight_year, member_name, member_name_en, member_id, photo, member_since, quote, quote_en, achievement, achievement_en, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE member_name=VALUES(member_name), member_name_en=VALUES(member_name_en), member_id=VALUES(member_id), photo=IF(VALUES(photo)='',photo,VALUES(photo)), member_since=VALUES(member_since), quote=VALUES(quote), quote_en=VALUES(quote_en), achievement=VALUES(achievement), achievement_en=VALUES(achievement_en), is_active=VALUES(is_active)")
                       ->execute([$spotlightYear, $memberName, $memberNameEn, $memberId, $photoPath, $memberSince, $quote, $quoteEn, $achievement, $achievementEn, $isActive]);
                    setFlash('success', $spotlightYear . ' को Member of the Year थपियो! Homepage मा देखिनेछ।');
                } else {
                    $db->prepare("UPDATE member_of_year SET spotlight_year=?, member_name=?, member_name_en=?, member_id=?, photo=IF(?='',photo,?), member_since=?, quote=?, quote_en=?, achievement=?, achievement_en=?, is_active=? WHERE id=?")
                       ->execute([$spotlightYear, $memberName, $memberNameEn, $memberId, $photoPath, $photoPath, $memberSince, $quote, $quoteEn, $achievement, $achievementEn, $isActive, $id]);
                    setFlash('success', 'Record अपडेट भयो।');
                }
                redirect('member-of-year.php');
            } catch (Exception $e) { $errors[] = 'Database error: ' . $e->getMessage(); }
        }
    }

    if ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        if ($id) $db->prepare("UPDATE member_of_year SET is_active=? WHERE id=?")->execute([$val, $id]);
        setFlash('success', $val ? 'Homepage मा देखाइयो।' : 'Homepage बाट हटाइयो।');
        redirect('member-of-year.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $row = $db->prepare("SELECT photo FROM member_of_year WHERE id=?");
            $row->execute([$id]);
            $rec = $row->fetch();
            if ($rec && $rec['photo'] && file_exists(ROOT_PATH . $rec['photo'])) @unlink(ROOT_PATH . $rec['photo']);
            $db->prepare("DELETE FROM member_of_year WHERE id=?")->execute([$id]);
        }
        setFlash('success', 'Record हटाइयो।');
        redirect('member-of-year.php');
    }
}

$records = [];
try { $records = $db->query("SELECT * FROM member_of_year ORDER BY spotlight_year DESC")->fetchAll(); }
catch (Exception $e) { $records = []; }

$defaultYear = date('Y');
$pageTitle   = 'वर्षको सदस्य Spotlight';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$motPart = adminPartitionRowsByIsActive($records);
$recordsLive = $motPart['live'];
$recordsArch = $motPart['archived'];

$flash = getFlash();
?>

<?php echo adminPageHeader(
    'Member of the Year Spotlight',
    'fa-star',
    'वर्षको उत्कृष्ट सदस्य — Spotlight Records।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($records) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="fas fa-eye me-1"></i>देखिने: ' . count($recordsLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="fas fa-archive me-1"></i>लुकेका: ' . count($recordsArch) . '</span>'
); ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger alert-dismissible fade show mb-3"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!empty($flash)) { echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']); } ?>

<div class="alert alert-info mb-3" style="border-left:4px solid #17a2b8;">
    <i class="fas fa-lightbulb me-2"></i>
    <strong>काम गर्ने तरिका:</strong> हरेक वर्ष एउटा नयाँ record थप्नुहोस् — Homepage मा <strong>यो वर्षको</strong> active record मात्र देखिन्छ।
</div>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#mot-list" id="mot-list-btn" title="देखिने / जम्मा">
            <i class="fas fa-list me-2"></i>Spotlight Records
            <span class="badge bg-success ms-1"><?php echo count($recordsLive); ?> / <?php echo count($records); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#mot-form" id="mot-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="motFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="mot-list">
        <div class="card admin-table-card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3" style="flex-wrap:wrap">
                <div class="input-group input-group-sm" style="max-width:300px">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="नाम, विवरण अनुसार खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
                    <?php echo adminListSubtabPills('mot-sub', count($recordsLive), count($recordsArch)); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="mot-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 coop-table">
                        <thead>
                            <tr>
                                <th class="ps-3" width="65">Photo</th>
                                <th>सदस्यको नाम</th>
                                <th width="90" class="text-center">वर्ष</th>
                                <th width="130" class="text-center">Homepage</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">
                                <i class="fas fa-trophy fa-3x mb-2 d-block" style="opacity:.15;color:#f59e0b;"></i>
                                अझै कुनै spotlight छैन।
                            </td></tr>
                            <?php elseif (empty($recordsLive)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">
                                <i class="fas fa-check-circle fa-3x mb-2 d-block opacity-25 text-success"></i>
                                Homepage मा देखिने record छैन। लुकेका हेर्नुहोस्।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($recordsLive as $r):
                                $isCurrentYear = ($r['spotlight_year'] === $defaultYear);
                                $hasPhoto = $r['photo'] && file_exists(ROOT_PATH . $r['photo']);
                            ?>
                            <tr class="<?php echo $isCurrentYear ? 'table-warning' : ''; ?>">
                                <td class="ps-3">
                                    <?php if ($hasPhoto): ?>
                                    <img src="<?php echo SITE_URL . htmlspecialchars($r['photo']); ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #f59e0b;">
                                    <?php else: ?>
                                    <div style="width:48px;height:48px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;"><i class="fas fa-user text-warning"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($r['member_name']); ?></div>
                                    <?php if ($r['member_name_en']): ?><small class="text-muted"><?php echo htmlspecialchars($r['member_name_en']); ?></small><?php endif; ?>
                                    <?php if ($r['achievement']): ?><div><span class="badge bg-success bg-opacity-10 text-success"><?php echo htmlspecialchars(mb_substr($r['achievement'], 0, 40)); ?></span></div><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $isCurrentYear ? 'bg-warning text-dark' : 'bg-secondary'; ?> fs-6 px-3">
                                        <?php echo htmlspecialchars($r['spotlight_year']); ?>
                                    </span>
                                    <?php if ($isCurrentYear): ?><div><small class="text-warning fw-semibold"><i class="fas fa-star me-1"></i>यो वर्ष</small></div><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo $r['is_active'] ? '0' : '1'; ?>">
                                        <button class="btn btn-sm <?php echo $r['is_active'] ? 'btn-success' : 'btn-outline-secondary'; ?>">
                                            <i class="fas fa-<?php echo $r['is_active'] ? 'eye' : 'eye-slash'; ?> me-1"></i>
                                            <?php echo $r['is_active'] ? 'Active' : 'Hidden'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-mot"
                                            data-member='<?php echo htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                            title="सम्पादन">
                                        <i class="fas fa-pen"></i> सम्पादन
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('यो record हटाउने? Photo पनि delete हुन्छ।')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="mot-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 coop-table">
                        <thead>
                            <tr>
                                <th class="ps-3" width="65">Photo</th>
                                <th>सदस्यको नाम</th>
                                <th width="90" class="text-center">वर्ष</th>
                                <th width="130" class="text-center">Homepage</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recordsArch)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-2 d-block opacity-25"></i>
                                लुकेका record छैनन्।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($recordsArch as $r):
                                $isCurrentYear = ($r['spotlight_year'] === $defaultYear);
                                $hasPhoto = $r['photo'] && file_exists(ROOT_PATH . $r['photo']);
                            ?>
                            <tr class="<?php echo $isCurrentYear ? 'table-warning' : ''; ?>">
                                <td class="ps-3">
                                    <?php if ($hasPhoto): ?>
                                    <img src="<?php echo SITE_URL . htmlspecialchars($r['photo']); ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid #f59e0b;">
                                    <?php else: ?>
                                    <div style="width:48px;height:48px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;"><i class="fas fa-user text-warning"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($r['member_name']); ?></div>
                                    <?php if ($r['member_name_en']): ?><small class="text-muted"><?php echo htmlspecialchars($r['member_name_en']); ?></small><?php endif; ?>
                                    <?php if ($r['achievement']): ?><div><span class="badge bg-success bg-opacity-10 text-success"><?php echo htmlspecialchars(mb_substr($r['achievement'], 0, 40)); ?></span></div><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $isCurrentYear ? 'bg-warning text-dark' : 'bg-secondary'; ?> fs-6 px-3">
                                        <?php echo htmlspecialchars($r['spotlight_year']); ?>
                                    </span>
                                    <?php if ($isCurrentYear): ?><div><small class="text-warning fw-semibold"><i class="fas fa-star me-1"></i>यो वर्ष</small></div><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <form method="POST" style="display:inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo $r['is_active'] ? '0' : '1'; ?>">
                                        <button class="btn btn-sm <?php echo $r['is_active'] ? 'btn-success' : 'btn-outline-secondary'; ?>">
                                            <i class="fas fa-<?php echo $r['is_active'] ? 'eye' : 'eye-slash'; ?> me-1"></i>
                                            <?php echo $r['is_active'] ? 'Active' : 'Hidden'; ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-mot"
                                            data-member='<?php echo htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                            title="सम्पादन">
                                        <i class="fas fa-pen"></i> सम्पादन
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('यो record हटाउने? Photo पनि delete हुन्छ।')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    </div>
            </div>
        </div>
    </div>

    <!-- ══ TAB 2: Add / Edit Form ══ -->
    <div class="tab-pane fade" id="mot-form">
        <div class="card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:linear-gradient(135deg,var(--primary-color),var(--primary-light));color:#fff;">
                <h5 class="mb-0 fw-bold" id="motFormTitle">
                    <i class="fas fa-plus-circle me-2"></i>नयाँ Spotlight थप्नुहोस्
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelMot">
                    <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" id="motForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" id="motf_action" value="add">
                    <input type="hidden" name="id" id="motf_id" value="">
                    <input type="hidden" name="existing_photo" id="motf_existing_photo" value="">

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success">वर्ष <span class="text-danger">*</span></label>
                            <input type="number" name="spotlight_year" id="motf_year" class="form-control admin-fancy-input" min="2000" max="2100" required value="<?php echo date('Y'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">नाम (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="member_name" id="motf_name" class="form-control admin-fancy-input" required placeholder="सदस्यको पूरा नाम">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold text-success">Name (English)</label>
                            <input type="text" name="member_name_en" id="motf_name_en" class="form-control admin-fancy-input" placeholder="Full name in English">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success">सदस्य नं.</label>
                            <input type="text" name="member_id" id="motf_member_id" class="form-control admin-fancy-input" placeholder="MBR-0001">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success">सदस्य बनेको वर्ष</label>
                            <input type="text" name="member_since" id="motf_since" class="form-control admin-fancy-input" placeholder="२०५५">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Photo
                                <small class="text-muted fw-normal" id="motf_photo_note"></small>
                            </label>
                            <input type="file" name="photo" class="form-control admin-fancy-input" accept=".jpg,.jpeg,.png,.gif,.webp"
                                   onchange="previewPhotoMot(this)">
                            <div id="motf_photo_prev" class="mt-2"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Quote (नेपाली)</label>
                            <textarea name="quote" id="motf_quote" class="form-control admin-fancy-input" rows="3" placeholder="उद्धरण..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Quote (English)</label>
                            <textarea name="quote_en" id="motf_quote_en" class="form-control admin-fancy-input" rows="3" placeholder="Quote in English..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">उपलब्धि (नेपाली)</label>
                            <input type="text" name="achievement" id="motf_achievement" class="form-control admin-fancy-input" placeholder="प्रमुख उपलब्धि">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Achievement (English)</label>
                            <input type="text" name="achievement_en" id="motf_achievement_en" class="form-control admin-fancy-input" placeholder="Key achievement">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="motf_active" value="1" checked>
                                <label class="form-check-label fw-semibold" for="motf_active">Homepage मा देखाउने</label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="motf_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                        </button>
                        <button type="button" id="motf_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i>रद्द
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    var listBtn = document.getElementById('mot-list-btn');
    var formBtn = document.getElementById('mot-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('motf_action').value        = 'add';
        document.getElementById('motf_id').value            = '';
        document.getElementById('motf_year').value          = new Date().getFullYear();
        document.getElementById('motf_name').value          = '';
        document.getElementById('motf_name_en').value       = '';
        document.getElementById('motf_member_id').value     = '';
        document.getElementById('motf_since').value         = '';
        document.getElementById('motf_quote').value         = '';
        document.getElementById('motf_quote_en').value      = '';
        document.getElementById('motf_achievement').value   = '';
        document.getElementById('motf_achievement_en').value = '';
        document.getElementById('motf_existing_photo').value = '';
        document.getElementById('motf_active').checked      = true;
        document.getElementById('motf_photo_prev').innerHTML = '';
        document.getElementById('motf_photo_note').textContent = '';
        document.getElementById('motf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('motFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ Spotlight थप्नुहोस्';
        document.getElementById('motFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
    }

    var btnAddSpotlight = document.getElementById('btnAddSpotlight');
    if (btnAddSpotlight) btnAddSpotlight.addEventListener('click', function() { clearForm(); switchToForm(); });

    ['btnCancelMot','motf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    document.querySelectorAll('.btn-edit-mot').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var r;
            try { r = JSON.parse(this.dataset.member); } catch(e) { return; }

            document.getElementById('motf_action').value        = 'update';
            document.getElementById('motf_id').value            = r.id;
            document.getElementById('motf_year').value          = r.spotlight_year;
            document.getElementById('motf_name').value          = r.member_name;
            document.getElementById('motf_name_en').value       = r.member_name_en || '';
            document.getElementById('motf_member_id').value     = r.member_id || '';
            document.getElementById('motf_since').value         = r.member_since || '';
            document.getElementById('motf_quote').value         = r.quote || '';
            document.getElementById('motf_quote_en').value      = r.quote_en || '';
            document.getElementById('motf_achievement').value   = r.achievement || '';
            document.getElementById('motf_achievement_en').value = r.achievement_en || '';
            document.getElementById('motf_existing_photo').value = r.photo || '';
            document.getElementById('motf_active').checked      = r.is_active == 1;
            document.getElementById('motf_photo_note').textContent = r.photo ? ' — नयाँ फोटो नचुने भने पुरानै रहन्छ' : '';
            var prev = document.getElementById('motf_photo_prev');
            prev.innerHTML = r.photo
                ? '<img src="<?php echo SITE_URL; ?>' + r.photo + '" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #f59e0b;">'
                : '';
            document.getElementById('motf_submit').innerHTML = '<i class="fas fa-save me-2"></i>अपडेट गर्नुहोस्';
            document.getElementById('motFormTitle').innerHTML = '<i class="fas fa-pen me-2"></i>Spotlight Record सम्पादन';
            document.getElementById('motFormTabLabel').textContent = 'सम्पादन';
            switchToForm();
        });
    });
});

function previewPhotoMot(input) {
    var prev = document.getElementById('motf_photo_prev');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            prev.innerHTML = '<img src="' + e.target.result + '" style="width:70px;height:70px;border-radius:50%;object-fit:cover;border:2px solid var(--primary-light);">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>





/* ── Client-side search (खोज बक्स) ── */
(function() {
    var inp = document.querySelector('#mot-list .admin-table-search');
    var cnt = document.querySelector('#mot-list .search-count');
    if (!inp) return;
    
    function filter() {
        var q = inp.value.toLowerCase();
        var pane = document.querySelector('#mot-list .tab-pane.active');
        if (!pane) return;
        var rows = pane.querySelectorAll('tbody tr');
        var vis = 0;
        rows.forEach(function(r) {
            var show = r.textContent.toLowerCase().includes(q);
            r.style.display = show ? '' : 'none';
            if (show) vis++;
        });
        if (cnt) cnt.textContent = vis + ' / ' + rows.length;
    }
    
    inp.addEventListener('input', filter);
    document.addEventListener('shown.bs.tab', function(e) {
        if (e.target.id === 'mot-sub-live' || e.target.id === 'mot-sub-arch') filter();
    });
    filter();
})();


<?php require_once 'includes/admin-footer.php'; ?>
