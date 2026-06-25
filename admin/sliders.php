<?php
/**
 * स्लाइडर व्यवस्थापन — Slider Management
 * Tab UI: सूची + Add/Edit form (modal popup हटाइएको)
 * Image 1920×600 auto-resize
 */
$pageTitle = 'स्लाइडर व्यवस्थापन';
require_once '../includes/config.php';
if (!isAdminLoggedIn()) redirect(ADMIN_URL . 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'सुरक्षा जाँच असफल।'];
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? ADMIN_URL . 'dashboard.php')); exit;
    }
}
if (empty($csrfToken)) $csrfToken = generateCSRFToken();

$success = '';
$error   = '';
$db      = getDB();

$slidersDir = UPLOAD_PATH . 'sliders/';
if (!is_dir($slidersDir)) mkdir($slidersDir, 0755, true);

if (!function_exists('sliderUploadErrorText')) {
    function sliderUploadErrorText(int $code): string {
        $iniUpload = (string) ini_get('upload_max_filesize');
        $iniPost = (string) ini_get('post_max_size');
        if ($code === UPLOAD_ERR_INI_SIZE) {
            return 'फाइल साइज server PHP limit भन्दा ठूलो छ। (upload_max_filesize=' . ($iniUpload ?: 'unknown') . ', post_max_size=' . ($iniPost ?: 'unknown') . ')';
        }
        if ($code === UPLOAD_ERR_FORM_SIZE) return 'Form को MAX_FILE_SIZE limit नाघ्यो। Form/JS limit जाँच्नुहोस्।';
        if ($code === UPLOAD_ERR_PARTIAL) return 'फाइल पूरा अपलोड भएन। पुनः प्रयास गर्नुहोस्।';
        if ($code === UPLOAD_ERR_NO_FILE) return 'स्लाइडरका लागि छवि आवश्यक छ।';
        if ($code === UPLOAD_ERR_NO_TMP_DIR) return 'सर्भर temporary folder (tmp) उपलब्ध छैन। Hosting support सँग जाँच्नुहोस्।';
        if ($code === UPLOAD_ERR_CANT_WRITE) return 'सर्भरले फाइल लेख्न सकेन (permission समस्या)।';
        if ($code === UPLOAD_ERR_EXTENSION) return 'Server extension ले upload रोकेको छ।';
        return 'छवि अपलोड गर्दा अज्ञात त्रुटि भयो। (code: ' . $code . ')';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $act      = $_POST['action'];
        $title    = clean_text($_POST['title']       ?? '');
        $subtitle = clean_text($_POST['subtitle']    ?? '');
        $btn_text = clean_text($_POST['button_text'] ?? '');
        $btn_url  = clean_text($_POST['button_url']  ?? '');
        $order    = (int)($_POST['display_order']  ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($act === 'add') {
            if (!isset($_FILES['image'])) {
                $error = 'स्लाइडरका लागि छवि आवश्यक छ।';
            } elseif ((int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $error = sliderUploadErrorText((int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE));
            } else {
                $imgName = uploadImage($_FILES['image'], $slidersDir, 1920, 600, true);
                if ($imgName) {
                    $imgPath = 'assets/uploads/sliders/' . $imgName;
                    $db->prepare("INSERT INTO sliders (title, subtitle, image, button_text, button_url, display_order, is_active) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$title, $subtitle, $imgPath, $btn_text, $btn_url, $order, $is_active]);
                    $success = 'स्लाइडर सफलतापूर्वक थपियो।';
                } else {
                    $error = 'छवि process/resize गर्न सकिएन। JPG/PNG/WebP छवि (landscape) प्रयोग गर्नुहोस्।';
                }
            }
        } elseif ($act === 'edit') {
            $id = (int)$_POST['id'];
            if (isset($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $imgName = uploadImage($_FILES['image'], $slidersDir, 1920, 600, true);
                if ($imgName) {
                    $imgPath = 'assets/uploads/sliders/' . $imgName;
                    $db->prepare("UPDATE sliders SET title=?, subtitle=?, image=?, button_text=?, button_url=?, display_order=?, is_active=? WHERE id=?")
                       ->execute([$title, $subtitle, $imgPath, $btn_text, $btn_url, $order, $is_active, $id]);
                } else {
                    $db->prepare("UPDATE sliders SET title=?, subtitle=?, button_text=?, button_url=?, display_order=?, is_active=? WHERE id=?")
                       ->execute([$title, $subtitle, $btn_text, $btn_url, $order, $is_active, $id]);
                    $error = 'छवि process/resize गर्न सकिएन तर अन्य जानकारी अपडेट भयो।';
                }
            } elseif (isset($_FILES['image']) && (int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                // Edit मा image optional हो; तर file आएको छ भने exact कारण देखाउने
                $db->prepare("UPDATE sliders SET title=?, subtitle=?, button_text=?, button_url=?, display_order=?, is_active=? WHERE id=?")
                   ->execute([$title, $subtitle, $btn_text, $btn_url, $order, $is_active, $id]);
                $error = sliderUploadErrorText((int)($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE)) . ' अन्य जानकारी अपडेट भयो।';
            } else {
                $db->prepare("UPDATE sliders SET title=?, subtitle=?, button_text=?, button_url=?, display_order=?, is_active=? WHERE id=?")
                   ->execute([$title, $subtitle, $btn_text, $btn_url, $order, $is_active, $id]);
            }
            if (empty($error)) $success = 'स्लाइडर सफलतापूर्वक अपडेट भयो।';
        } elseif ($act === 'delete') {
            $db->prepare("DELETE FROM sliders WHERE id=?")->execute([(int)$_POST['id']]);
            $success = 'स्लाइडर मेटाइयो।';
        }
    } catch (Exception $e) { $error = 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।'; }
}

try { $sliders = $db->query("SELECT * FROM sliders ORDER BY display_order, id DESC")->fetchAll(); }
catch (Exception $e) { $sliders = []; }

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$slPart = adminPartitionRowsByIsActive($sliders);
$slidersLive = $slPart['live'];
$slidersArch = $slPart['archived'];
?>

<?php echo adminPageHeader(
    'स्लाइडर व्यवस्थापन',
    'fa-images',
    'Homepage slider — Images 1920×600px, landscape format recommended.',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($sliders) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="fas fa-check-circle me-1"></i>सक्रिय: ' . count($slidersLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="fas fa-archive me-1"></i>अभिलेख: ' . count($slidersArch) . '</span>'
);
?>
<?php echo adminHelpTip('यो पृष्ठबाट Homepage को Slider/Banner Images व्यवस्थापन गर्न सकिन्छ।', ['नयाँ Slider थप्न: "+" बटन थिच्नुहोस्।', 'Image size: 1920×600 pixels उपयुक्त छ।', 'Order मिलाउन: Display Order number बदल्नुहोस् (सानो number = पहिला देखिन्छ)।']); ?>

<?php echo adminAlert('success', $success) . adminAlert('danger', $error); ?>

<div class="alert alert-info mb-3 sl-info-left">
    <i class="fas fa-info-circle me-2"></i>
    <strong>छवि आकार:</strong> स्लाइडर छविहरू स्वतः <strong>1920×600 pixels</strong> मा resize हुन्छ। सिफारिस: landscape (चौडा) छवि प्रयोग गर्नुहोस्।
</div>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sl-list" id="sl-list-btn" title="सक्रिय / जम्मा">
            <i class="fas fa-list me-2"></i>स्लाइडर सूची
            <span class="badge bg-success ms-1"><?php echo count($slidersLive); ?> / <?php echo count($sliders); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sl-form" id="sl-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="slFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="sl-list">
        <div class="card admin-table-card sl-flat-top">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3 sl-search-wrap">
                <div class="input-group input-group-sm sl-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="नाम, विवरण अनुसार खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
                    <?php echo adminListSubtabPills('sl-sub', count($slidersLive), count($slidersArch)); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="sl-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" width="130">पूर्वावलोकन</th>
                                <th>शीर्षक / उपशीर्षक</th>
                                <th width="150">बटन</th>
                                <th width="70" class="text-center">क्रम</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sliders)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-images fa-3x mb-2 d-block opacity-25"></i>
                                कुनै स्लाइडर छैन। माथिको "नयाँ थप्नुहोस्" बटन थिच्नुहोस्।
                            </td></tr>
                            <?php elseif (empty($slidersLive)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-check-circle fa-3x mb-2 d-block opacity-25 text-success"></i>
                                सक्रिय स्लाइडर छैन। अभिलेख हेर्नुहोस्।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($slidersLive as $sl): ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if (!empty($sl['image'])): ?>
                                    <img src="../<?php echo htmlspecialchars($sl['image']); ?>" class="sl-prev-img">
                                    <?php else: ?>
                                    <div class="sl-prev-empty"><i class="fas fa-image text-muted"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($sl['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars(mb_substr($sl['subtitle'] ?? '', 0, 50)); ?></small>
                                </td>
                                <td><small><?php echo htmlspecialchars($sl['button_text'] ?? '—'); ?></small></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $sl['display_order']; ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $sl['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $sl['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-sl"
                                            data-id="<?php echo $sl['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($sl['title'] ?? '', ENT_QUOTES); ?>"
                                            data-subtitle="<?php echo htmlspecialchars($sl['subtitle'] ?? '', ENT_QUOTES); ?>"
                                            data-btn-text="<?php echo htmlspecialchars($sl['button_text'] ?? '', ENT_QUOTES); ?>"
                                            data-btn-url="<?php echo htmlspecialchars($sl['button_url'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $sl['display_order']; ?>"
                                            data-active="<?php echo $sl['is_active']; ?>"
                                            data-image="<?php echo htmlspecialchars($sl['image'] ?? '', ENT_QUOTES); ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="sl-inline-form" onsubmit="return confirm('के तपाईं यो स्लाइडर मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $sl['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="sl-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" width="130">पूर्वावलोकन</th>
                                <th>शीर्षक / उपशीर्षक</th>
                                <th width="150">बटन</th>
                                <th width="70" class="text-center">क्रम</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($slidersArch)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-2 d-block opacity-25"></i>
                                अभिलेखमा कुनै स्लाइडर छैन।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($slidersArch as $sl): ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if (!empty($sl['image'])): ?>
                                    <img src="../<?php echo htmlspecialchars($sl['image']); ?>" class="sl-prev-img">
                                    <?php else: ?>
                                    <div class="sl-prev-empty"><i class="fas fa-image text-muted"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($sl['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars(mb_substr($sl['subtitle'] ?? '', 0, 50)); ?></small>
                                </td>
                                <td><small><?php echo htmlspecialchars($sl['button_text'] ?? '—'); ?></small></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $sl['display_order']; ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $sl['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $sl['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-sl"
                                            data-id="<?php echo $sl['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($sl['title'] ?? '', ENT_QUOTES); ?>"
                                            data-subtitle="<?php echo htmlspecialchars($sl['subtitle'] ?? '', ENT_QUOTES); ?>"
                                            data-btn-text="<?php echo htmlspecialchars($sl['button_text'] ?? '', ENT_QUOTES); ?>"
                                            data-btn-url="<?php echo htmlspecialchars($sl['button_url'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $sl['display_order']; ?>"
                                            data-active="<?php echo $sl['is_active']; ?>"
                                            data-image="<?php echo htmlspecialchars($sl['image'] ?? '', ENT_QUOTES); ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="sl-inline-form" onsubmit="return confirm('के तपाईं यो स्लाइडर मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $sl['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
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
    <div class="tab-pane fade" id="sl-form">
        <div class="card sl-flat-top">
            <div class="card-header d-flex justify-content-between align-items-center sl-form-header">
                <h5 class="mb-0 fw-bold" id="slFormTitle">
                    <i class="fas fa-plus-circle me-2"></i>नयाँ स्लाइडर थप्नुहोस्
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelSl">
                    <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" id="slForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" id="slf_action" value="add">
                    <input type="hidden" name="id" id="slf_id" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">मुख्य शीर्षक</label>
                            <input type="text" name="title" id="slf_title" class="form-control admin-fancy-input" placeholder="Homepage मा देखिने मुख्य पाठ">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">उपशीर्षक</label>
                            <input type="text" name="subtitle" id="slf_subtitle" class="form-control admin-fancy-input" placeholder="थप विवरण वा slogan">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">बटन पाठ</label>
                            <input type="text" name="button_text" id="slf_btn_text" class="form-control admin-fancy-input" placeholder="थप जानकारी">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">बटन URL</label>
                            <input type="text" name="button_url" id="slf_btn_url" class="form-control admin-fancy-input" placeholder="https://... वा /about.php">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success">
                                स्लाइडर छवि
                                <span id="slf_img_required" class="text-danger">*</span>
                                <small class="text-muted fw-normal" id="slf_img_note">— नयाँ थप्दा अनिवार्य छ</small>
                            </label>
                            <input type="file" name="image" class="form-control admin-fancy-input" id="slf_image" accept="image/*"
                                   onchange="previewSliderImg(this)">
                            <div id="slf_img_prev" class="mt-2"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">प्रदर्शन क्रम</label>
                            <input type="number" name="display_order" id="slf_order" class="form-control admin-fancy-input" value="0" min="0">
                        </div>
                        <div class="col-md-8 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="slf_active" checked>
                                <label class="form-check-label fw-semibold" for="slf_active">सक्रिय</label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="slf_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                        </button>
                        <button type="button" id="slf_cancel2" class="btn btn-outline-secondary px-4">
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

    var listBtn = document.getElementById('sl-list-btn');
    var formBtn = document.getElementById('sl-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('slf_action').value    = 'add';
        document.getElementById('slf_id').value        = '';
        document.getElementById('slf_title').value     = '';
        document.getElementById('slf_subtitle').value  = '';
        document.getElementById('slf_btn_text').value  = '';
        document.getElementById('slf_btn_url').value   = '';
        document.getElementById('slf_order').value     = '0';
        document.getElementById('slf_active').checked  = true;
        document.getElementById('slf_img_prev').innerHTML = '';
        document.getElementById('slf_img_note').textContent = '— नयाँ थप्दा अनिवार्य छ';
        document.getElementById('slf_img_required').style.display = '';
        document.getElementById('slf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('slFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ स्लाइडर थप्नुहोस्';
        document.getElementById('slFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
        try { document.getElementById('slf_image').value = ''; } catch(e) {}
    }

    /* v10.3 FIX: btnAddSlider element page मा छैन — null-check नहुँदा JS crash हुन्थ्यो र
       .btn-edit-sl click handler attach हुँदैनथ्यो → edit बटन ले काम गर्दैनथ्यो। */
    var _btnAdd = document.getElementById('btnAddSlider');
    if (_btnAdd) _btnAdd.addEventListener('click', function() { clearForm(); switchToForm(); });

    ['btnCancelSl','slf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    document.querySelectorAll('.btn-edit-sl').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('slf_action').value   = 'edit';
            document.getElementById('slf_id').value       = d.id;
            document.getElementById('slf_title').value    = d.title;
            document.getElementById('slf_subtitle').value = d.subtitle || '';
            document.getElementById('slf_btn_text').value = d.btnText || '';
            document.getElementById('slf_btn_url').value  = d.btnUrl || '';
            document.getElementById('slf_order').value    = d.order || 0;
            document.getElementById('slf_active').checked = d.active === '1';
            document.getElementById('slf_img_note').textContent = d.image ? ' — नयाँ छवि नचुने भने पुरानै रहन्छ' : '';
            document.getElementById('slf_img_required').style.display = 'none';
            var prev = document.getElementById('slf_img_prev');
            prev.innerHTML = d.image
                ? '<img src="../' + d.image + '" class="sl-edit-prev">'
                : '';
            document.getElementById('slf_submit').innerHTML = '<i class="fas fa-save me-2"></i>अपडेट गर्नुहोस्';
            document.getElementById('slFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>स्लाइडर सम्पादन';
            document.getElementById('slFormTabLabel').textContent = 'सम्पादन';
            switchToForm();
        });
    });
});

function previewSliderImg(input) {
    var prev = document.getElementById('slf_img_prev');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            prev.innerHTML = '<img src="' + e.target.result + '" class="sl-prev-upload">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require_once 'includes/admin-footer.php'; ?>
