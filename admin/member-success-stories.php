<?php
/**
 * सदस्य सफलताका कथाहरू — Member Success Stories (About page)
 * Multiple members: जीवन/आर्जन सुधार + संस्थाले सहयोग
 */
$pageTitle = 'सदस्य सफलताका कथाहरू';
$currentPage = 'member-success-stories';
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/../includes/member-success-stories-tables.php';
require_once __DIR__ . '/../includes/simple-cache.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        setFlash('error', 'सुरक्षा जाँच असफल।');
        redirect('member-success-stories.php');
    }
}
$csrfToken = generateCSRFToken();

$db = getDB();
ensureMemberSuccessStoriesTable($db);
$errors = [];

$uploadSub = 'member-success';
$uploadDir = (defined('UPLOAD_PATH') ? UPLOAD_PATH : (ROOT_PATH . 'assets/uploads/')) . $uploadSub . '/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = clean_text($_POST['action'] ?? '');

    if ($act === 'add' || $act === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $memberName = clean_text($_POST['member_name'] ?? '');
        $memberNameEn = clean_text($_POST['member_name_en'] ?? '');
        $memberIdNo = clean_text($_POST['member_id_no'] ?? '');
        $memberSince = clean_text($_POST['member_since'] ?? '');
        $location = clean_text($_POST['location'] ?? '');
        $locationEn = clean_text($_POST['location_en'] ?? '');
        $profession = clean_text($_POST['profession'] ?? '');
        $professionEn = clean_text($_POST['profession_en'] ?? '');
        $headlineNp = clean_text($_POST['headline_np'] ?? '');
        $headlineEn = clean_text($_POST['headline_en'] ?? '');
        $storyNp = trim((string)($_POST['story_np'] ?? ''));
        $storyEn = trim((string)($_POST['story_en'] ?? ''));
        $helpNp = trim((string)($_POST['institution_help_np'] ?? ''));
        $helpEn = trim((string)($_POST['institution_help_en'] ?? ''));
        $order = (int)($_POST['display_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($memberName === '') {
            $errors[] = 'सदस्यको नाम अनिवार्य छ।';
        }
        if ($headlineNp === '') {
            $errors[] = 'शीर्षक (नेपाली) अनिवार्य छ।';
        }
        if ($storyNp === '') {
            $errors[] = 'सफलताको कथा (नेपाली) अनिवार्य छ।';
        }

        $photoPath = clean_text($_POST['existing_photo'] ?? '');
        if (empty($errors) && !empty($_FILES['photo']['name']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = function_exists('coop_upload_error_text')
                    ? coop_upload_error_text((int)$_FILES['photo']['error'])
                    : 'Photo upload error.';
            } elseif (function_exists('uploadFile')) {
                $upload = uploadFile($_FILES['photo'], $uploadSub, 4 * 1024 * 1024);
                if (!empty($upload['success']) && !empty($upload['path'])) {
                    if ($photoPath !== '' && is_file(ROOT_PATH . ltrim($photoPath, '/'))) {
                        @unlink(ROOT_PATH . ltrim($photoPath, '/'));
                    }
                    $photoPath = $upload['path'];
                } else {
                    $errors[] = $upload['message'] ?? 'Photo upload गर्न सकिएन।';
                }
            } elseif (function_exists('uploadImage')) {
                $imgName = uploadImage($_FILES['photo'], $uploadDir, 1200, 1200, false);
                if ($imgName) {
                    if ($photoPath !== '' && is_file(ROOT_PATH . ltrim($photoPath, '/'))) {
                        @unlink(ROOT_PATH . ltrim($photoPath, '/'));
                    }
                    $photoPath = 'assets/uploads/' . $uploadSub . '/' . $imgName;
                } else {
                    $errors[] = 'Photo upload गर्न सकिएन।';
                }
            }
        }

        if (empty($errors)) {
            try {
                if ($act === 'add') {
                    $db->prepare(
                        "INSERT INTO member_success_stories
                        (member_name, member_name_en, member_id_no, photo, member_since, location, location_en,
                         profession, profession_en, headline_np, headline_en, story_np, story_en,
                         institution_help_np, institution_help_en, display_order, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                    )->execute([
                        $memberName, $memberNameEn, $memberIdNo, $photoPath, $memberSince, $location, $locationEn,
                        $profession, $professionEn, $headlineNp, $headlineEn, $storyNp, $storyEn,
                        $helpNp, $helpEn, $order, $isActive,
                    ]);
                    setFlash('success', 'सफलताको कथा थपियो — About पृष्ठमा देखिनेछ।');
                } else {
                    if ($id < 1) {
                        $errors[] = 'अमान्य रेकर्ड।';
                    } else {
                        $db->prepare(
                            "UPDATE member_success_stories SET
                             member_name=?, member_name_en=?, member_id_no=?,
                             photo=IF(?='', photo, ?), member_since=?, location=?, location_en=?,
                             profession=?, profession_en=?, headline_np=?, headline_en=?,
                             story_np=?, story_en=?, institution_help_np=?, institution_help_en=?,
                             display_order=?, is_active=?
                             WHERE id=?"
                        )->execute([
                            $memberName, $memberNameEn, $memberIdNo,
                            $photoPath, $photoPath, $memberSince, $location, $locationEn,
                            $profession, $professionEn, $headlineNp, $headlineEn,
                            $storyNp, $storyEn, $helpNp, $helpEn,
                            $order, $isActive, $id,
                        ]);
                        setFlash('success', 'सफलताको कथा अपडेट भयो।');
                    }
                }
                if (empty($errors)) {
                    if (function_exists('clearHomepageCache')) {
                        clearHomepageCache();
                    }
                    redirect('member-success-stories.php');
                }
            } catch (Throwable $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif ($act === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        if ($id > 0) {
            $db->prepare('UPDATE member_success_stories SET is_active=? WHERE id=?')->execute([$val, $id]);
            if (function_exists('clearHomepageCache')) {
                clearHomepageCache();
            }
            setFlash('success', $val ? 'About मा देखाइयो।' : 'About बाट लुकाइयो।');
        }
        redirect('member-success-stories.php');
    } elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $db->prepare('SELECT photo FROM member_success_stories WHERE id=?');
            $st->execute([$id]);
            $rec = $st->fetch(PDO::FETCH_ASSOC);
            if ($rec && !empty($rec['photo'])) {
                $rel = ltrim(str_replace('\\', '/', (string)$rec['photo']), '/');
                if ($rel !== '' && is_file(ROOT_PATH . $rel)) {
                    @unlink(ROOT_PATH . $rel);
                }
            }
            $db->prepare('DELETE FROM member_success_stories WHERE id=?')->execute([$id]);
            if (function_exists('clearHomepageCache')) {
                clearHomepageCache();
            }
            setFlash('success', 'कथा मेटाइयो।');
        }
        redirect('member-success-stories.php');
    }
}

$records = [];
try {
    $records = $db->query('SELECT * FROM member_success_stories ORDER BY display_order ASC, id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $records = [];
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    foreach ($records as $r) {
        if ((int)$r['id'] === $editId) {
            $edit = $r;
            break;
        }
    }
}

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$part = adminPartitionRowsByIsActive($records);
$live = $part['live'];
$arch = $part['archived'];
$flash = getFlash();
$formOpen = $edit || !empty($errors) || isset($_GET['new']);
?>

<?php echo adminPageHeader(
    adminLangT('सदस्य सफलताका कथाहरू', 'Member Success Stories'),
    'fa-book-open',
    adminLangT('सदस्य बनेपछि जीवन/आर्जन सुधार र संस्थाले गरेको सहयोग — About ड्रपडाउनमा।', 'Life/livelihood improvement after joining and how the cooperative helped — About dropdown.'),
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="lucide-icon me-1" data-lucide="layers" aria-hidden="true"></i>' . count($records) . '</span>'
    . '<a class="btn btn-outline-secondary btn-sm" href="../success-stories.php" target="_blank" rel="noopener"><i class="lucide-icon me-1" data-lucide="external-link" aria-hidden="true"></i>Public page</a>'
); ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger alert-dismissible fade show mb-3"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!empty($flash)) { echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']); } ?>

<div class="alert alert-info mb-3" style="border-left:4px solid #17a2b8;">
    <i class="lucide-icon me-2" data-lucide="lightbulb" aria-hidden="true"></i>
    <strong>अवधारणा:</strong> सदस्य बनिसेकेपछि जीवन/योजनमा कसरी सुधार भयो, र संस्थाले कसरी सहयोग गर्‍यो — बहु सदस्यका कथाहरू About → <em>सदस्यको सफलताको कथा</em> मा।
</div>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button type="button" class="nav-link <?php echo $formOpen ? '' : 'active'; ?>" data-bs-toggle="tab" data-bs-target="#mss-list" id="mss-list-btn">
            <i class="lucide-icon me-2" data-lucide="list" aria-hidden="true"></i>सूची
            <span class="badge bg-success ms-1"><?php echo count($records); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button type="button" class="nav-link <?php echo $formOpen ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#mss-form" id="mss-form-btn">
            <i class="lucide-icon me-2" data-lucide="<?php echo $edit ? 'pencil' : 'circle-plus'; ?>" aria-hidden="true"></i><span id="mssFormTabLabel"><?php echo $edit ? 'सम्पादन' : 'नयाँ थप्नुहोस्'; ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade <?php echo $formOpen ? '' : 'show active'; ?>" id="mss-list">
        <div class="card admin-table-card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3" style="flex-wrap:wrap">
                <div class="input-group input-group-sm" style="max-width:300px">
                    <span class="input-group-text bg-white border-end-0"><i class="lucide-icon text-muted" data-lucide="search" aria-hidden="true"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="नाम / शीर्षक खोज्नुहोस्..." autocomplete="off">
                </div>
                <button type="button" class="btn btn-sm btn-success" id="btnAddMss"><i class="lucide-icon me-1" data-lucide="circle-plus" aria-hidden="true"></i>नयाँ कथा</button>
            </div>
            <div class="card-body p-0">
                <?php echo adminListSubtabPills('mss-sub', count($live), count($arch)); ?>
                <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="mss-sub-live" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 coop-table">
                                <thead>
                                    <tr>
                                        <th class="ps-3" width="65">फोटो</th>
                                        <th>सदस्य / शीर्षक</th>
                                        <th width="90" class="text-center">क्रम</th>
                                        <th width="100" class="text-center">स्थिति</th>
                                        <th width="160" class="text-center">कार्य</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($live)): ?>
                                    <tr><td colspan="5" class="text-center py-5 text-muted">
                                        <i class="lucide-icon lucide-3x mb-2 d-block opacity-25" data-lucide="book-open" aria-hidden="true"></i>
                                        सक्रिय कथा छैन। नयाँ थप्नुहोस् वा लुकेका हेर्नुहोस्।
                                    </td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($live as $r):
                                        $hasPhoto = !empty($r['photo']) && is_file(ROOT_PATH . ltrim((string)$r['photo'], '/'));
                                    ?>
                                    <tr>
                                        <td class="ps-3">
                                            <?php if ($hasPhoto): ?>
                                            <img src="../<?php echo htmlspecialchars($r['photo'], ENT_QUOTES, 'UTF-8'); ?>" class="news-thumb-img" alt="">
                                            <?php else: ?>
                                            <div class="news-thumb-placeholder"><i class="lucide-icon text-success" data-lucide="user" aria-hidden="true"></i></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars((string)$r['member_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars((string)$r['headline_np'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        </td>
                                        <td class="text-center"><?php echo (int)$r['display_order']; ?></td>
                                        <td class="text-center"><span class="badge bg-success">सक्रिय</span></td>
                                        <td class="text-center">
                                            <a class="adm-icon-btn adm-icon-btn--edit" href="member-success-stories.php?edit=<?php echo (int)$r['id']; ?>" title="सम्पादन"><i class="lucide-icon" data-lucide="pencil" aria-hidden="true"></i></a>
                                            <form method="POST" class="svc-inline-form d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <input type="hidden" name="is_active" value="0">
                                                <button type="submit" class="adm-icon-btn" title="लुकाउनुहोस्"><i class="lucide-icon" data-lucide="eye-off" aria-hidden="true"></i></button>
                                            </form>
                                            <form method="POST" class="svc-inline-form d-inline" onsubmit="return confirm('यो कथा मेटाउन निश्चित हुनुहुन्छ?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="adm-icon-btn adm-icon-btn--delete" title="मेटाउनुहोस्"><i class="lucide-icon" data-lucide="trash-2" aria-hidden="true"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="mss-sub-arch" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 coop-table">
                                <thead>
                                    <tr>
                                        <th class="ps-3" width="65">फोटो</th>
                                        <th>सदस्य / शीर्षक</th>
                                        <th width="100" class="text-center">स्थिति</th>
                                        <th width="160" class="text-center">कार्य</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($arch)): ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">लुकेका कथा छैनन्।</td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($arch as $r):
                                        $hasPhoto = !empty($r['photo']) && is_file(ROOT_PATH . ltrim((string)$r['photo'], '/'));
                                    ?>
                                    <tr>
                                        <td class="ps-3">
                                            <?php if ($hasPhoto): ?>
                                            <img src="../<?php echo htmlspecialchars($r['photo'], ENT_QUOTES, 'UTF-8'); ?>" class="news-thumb-img" alt="">
                                            <?php else: ?>
                                            <div class="news-thumb-placeholder"><i class="lucide-icon" data-lucide="user" aria-hidden="true"></i></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo htmlspecialchars((string)$r['member_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars((string)$r['headline_np'], ENT_QUOTES, 'UTF-8'); ?></small>
                                        </td>
                                        <td class="text-center"><span class="badge bg-secondary">निष्क्रिय</span></td>
                                        <td class="text-center">
                                            <a class="adm-icon-btn adm-icon-btn--edit" href="member-success-stories.php?edit=<?php echo (int)$r['id']; ?>" title="सम्पादन"><i class="lucide-icon" data-lucide="pencil" aria-hidden="true"></i></a>
                                            <form method="POST" class="svc-inline-form d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <input type="hidden" name="is_active" value="1">
                                                <button type="submit" class="adm-icon-btn" title="सक्रिय"><i class="lucide-icon" data-lucide="eye" aria-hidden="true"></i></button>
                                            </form>
                                            <form method="POST" class="svc-inline-form d-inline" onsubmit="return confirm('यो कथा मेटाउन निश्चित हुनुहुन्छ?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="adm-icon-btn adm-icon-btn--delete" title="मेटाउनुहोस्"><i class="lucide-icon" data-lucide="trash-2" aria-hidden="true"></i></button>
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

    <div class="tab-pane fade <?php echo $formOpen ? 'show active' : ''; ?>" id="mss-form">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="mssFormTitle">
                    <i class="lucide-icon me-2" data-lucide="<?php echo $edit ? 'pencil' : 'circle-plus'; ?>" aria-hidden="true"></i><?php echo $edit ? 'कथा सम्पादन' : 'नयाँ सफलताको कथा'; ?>
                </h5>
                <a href="member-success-stories.php" class="btn btn-light btn-sm"><i class="lucide-icon me-1" data-lucide="arrow-left" aria-hidden="true"></i>सूची</a>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                    <input type="hidden" name="action" value="<?php echo $edit ? 'edit' : 'add'; ?>">
                    <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
                    <input type="hidden" name="existing_photo" value="<?php echo htmlspecialchars((string)($edit['photo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success" for="mss_name">सदस्यको नाम (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="member_name" id="mss_name" class="form-control admin-fancy-input" required value="<?php echo htmlspecialchars((string)($edit['member_name'] ?? $_POST['member_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success" for="mss_name_en">Member name (English)</label>
                            <input type="text" name="member_name_en" id="mss_name_en" class="form-control admin-fancy-input" value="<?php echo htmlspecialchars((string)($edit['member_name_en'] ?? $_POST['member_name_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success" for="mss_mid">सदस्यता नं. (ऐच्छिक)</label>
                            <input type="text" name="member_id_no" id="mss_mid" class="form-control admin-fancy-input" value="<?php echo htmlspecialchars((string)($edit['member_id_no'] ?? $_POST['member_id_no'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success" for="mss_since">सदस्य भएको वर्ष/मिति</label>
                            <input type="text" name="member_since" id="mss_since" class="form-control admin-fancy-input" placeholder="जस्तै: २०७५ / 2018" value="<?php echo htmlspecialchars((string)($edit['member_since'] ?? $_POST['member_since'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success" for="mss_photo">फोटो</label>
                            <input type="file" name="photo" id="mss_photo" class="form-control admin-fancy-input" accept="image/*">
                            <?php if (!empty($edit['photo'])): ?>
                            <div class="mt-2"><img src="../<?php echo htmlspecialchars((string)$edit['photo'], ENT_QUOTES, 'UTF-8'); ?>" class="news-preview-img" alt=""></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success" for="mss_loc">स्थान (नेपाली)</label>
                            <input type="text" name="location" id="mss_loc" class="form-control admin-fancy-input" value="<?php echo htmlspecialchars((string)($edit['location'] ?? $_POST['location'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success" for="mss_loc_en">Location (English)</label>
                            <input type="text" name="location_en" id="mss_loc_en" class="form-control admin-fancy-input" value="<?php echo htmlspecialchars((string)($edit['location_en'] ?? $_POST['location_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success" for="mss_prof">पेशा / व्यवसाय (नेपाली)</label>
                            <input type="text" name="profession" id="mss_prof" class="form-control admin-fancy-input" value="<?php echo htmlspecialchars((string)($edit['profession'] ?? $_POST['profession'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success" for="mss_prof_en">Profession (English)</label>
                            <input type="text" name="profession_en" id="mss_prof_en" class="form-control admin-fancy-input" value="<?php echo htmlspecialchars((string)($edit['profession_en'] ?? $_POST['profession_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success" for="mss_h_np">शीर्षक (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="headline_np" id="mss_h_np" class="form-control admin-fancy-input" required placeholder="जस्तै: बचतबाट व्यवसाय विस्तार" value="<?php echo htmlspecialchars((string)($edit['headline_np'] ?? $_POST['headline_np'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success" for="mss_h_en">Headline (English)</label>
                            <input type="text" name="headline_en" id="mss_h_en" class="form-control admin-fancy-input" value="<?php echo htmlspecialchars((string)($edit['headline_en'] ?? $_POST['headline_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success" for="mss_story_np">सफलताको कथा — जीवन/आर्जन सुधार (नेपाली) <span class="text-danger">*</span></label>
                            <textarea name="story_np" id="mss_story_np" class="form-control admin-fancy-input" rows="5" required placeholder="सदस्य बनेपछि जीवन वा आर्जनमा कसरी सुधार भयो..."><?php echo htmlspecialchars((string)($edit['story_np'] ?? $_POST['story_np'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success" for="mss_story_en">Success story — life/livelihood (English)</label>
                            <textarea name="story_en" id="mss_story_en" class="form-control admin-fancy-input" rows="4"><?php echo htmlspecialchars((string)($edit['story_en'] ?? $_POST['story_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success" for="mss_help_np">संस्थाले गरेको सहयोग (नेपाली)</label>
                            <textarea name="institution_help_np" id="mss_help_np" class="form-control admin-fancy-input" rows="4" placeholder="ऋण, बचत, तालिम, सल्लाह आदि..."><?php echo htmlspecialchars((string)($edit['institution_help_np'] ?? $_POST['institution_help_np'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success" for="mss_help_en">How the cooperative helped (English)</label>
                            <textarea name="institution_help_en" id="mss_help_en" class="form-control admin-fancy-input" rows="4"><?php echo htmlspecialchars((string)($edit['institution_help_en'] ?? $_POST['institution_help_en'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success" for="mss_order">क्रम</label>
                            <input type="number" name="display_order" id="mss_order" class="form-control admin-fancy-input" value="<?php echo (int)($edit['display_order'] ?? $_POST['display_order'] ?? 0); ?>" min="0">
                        </div>
                        <div class="col-md-3 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="mss_active" <?php echo ((int)($edit['is_active'] ?? $_POST['is_active'] ?? 1) === 1) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="mss_active">सक्रिय (About मा देखाउने)</label>
                            </div>
                        </div>
                    </div>
                    <hr class="my-4">
                    <button type="submit" class="btn btn-success px-5 fw-semibold">
                        <i class="lucide-icon me-2" data-lucide="<?php echo $edit ? 'save' : 'circle-plus'; ?>" aria-hidden="true"></i><?php echo $edit ? 'अपडेट गर्नुहोस्' : 'थप्नुहोस्'; ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var addBtn = document.getElementById('btnAddMss');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            window.location.href = 'member-success-stories.php?new=1';
        });
    }
});
</script>
<?php require_once 'includes/admin-footer.php'; ?>
