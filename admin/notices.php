<?php
/**
 * सूचना व्यवस्थापन — Notices Management
 * Tab UI: List tab + Add/Edit form tab (modal popup हटाइएको)
 * सबै मिति नेपाली (बि.सं.) मात्र
 */
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('सूचना व्यवस्थापन', 'Notices Management');
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

/* ─── Ensure popup_photo_only + popup_image columns exist ─── */
try {
    $__db = getDB();
    foreach ([
        "ALTER TABLE notices ADD COLUMN popup_photo_only TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Popup shows photo only'",
        "ALTER TABLE notices ADD COLUMN popup_image VARCHAR(255) DEFAULT '' COMMENT 'Custom popup image'",
    ] as $__sql) {
        try { $__db->exec($__sql); } catch (Exception $e) { /* column exists */ }
    }
    unset($__db, $__sql);
} catch (Exception $e) {}

$rawAction = $_POST['action'] ?? $_GET['action'] ?? 'list';
$action    = in_array($rawAction, ['list', 'delete', 'bulk_status'], true) ? $rawAction : 'list';
$id        = intval($_POST['id'] ?? 0) ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notice'])) {
    $title      = clean_text($_POST['title']      ?? '');
    $content    = $_POST['content']             ?? '';
    $noticeDate = !empty(trim($_POST['notice_date'] ?? '')) ? clean_text($_POST['notice_date']) : null;
    $isActive          = isset($_POST['is_active'])         ? 1 : 0;
    $isPopup           = isset($_POST['is_popup'])           ? 1 : 0;
    $isPopupPhotoOnly  = isset($_POST['popup_photo_only'])   ? 1 : 0;
    $attachment        = null;
    $popupImage        = null;

    if ($title === '') {
        setFlash('error', $__t('शीर्षक अनिवार्य छ।', 'Title is required.'));
        redirect('notices.php');
    }

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadFile($_FILES['attachment'], 'notices');
        if ($upload['success']) $attachment = $upload['path'];
    }
    if (isset($_FILES['popup_image']) && $_FILES['popup_image']['error'] === UPLOAD_ERR_OK) {
        $upload2 = uploadFile($_FILES['popup_image'], 'notices');
        if ($upload2['success']) $popupImage = $upload2['path'];
    }

    try {
        $db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();
        if (!empty($_POST['notice_id'])) {
            $noticeId = (int)$_POST['notice_id'];
            if ($attachment) {
                $db->prepare("UPDATE notices SET title=?, content=?, notice_date=?, attachment=?, is_active=?, is_popup=?, popup_photo_only=?, popup_image=COALESCE(NULLIF(?,NULL), popup_image) WHERE id=?")
                   ->execute([$title, $content, $noticeDate, $attachment, $isActive, $isPopup, $isPopupPhotoOnly, $popupImage, $noticeId]);
            } else {
                $db->prepare("UPDATE notices SET title=?, content=?, notice_date=?, is_active=?, is_popup=?, popup_photo_only=?, popup_image=COALESCE(NULLIF(?,NULL), popup_image) WHERE id=?")
                   ->execute([$title, $content, $noticeDate, $isActive, $isPopup, $isPopupPhotoOnly, $popupImage, $noticeId]);
            }
            setFlash('success', $__t('सूचना सफलतापूर्वक अपडेट भयो।', 'Notice updated successfully.'));
            writeAuditLog('notice_update', 'Updated: ' . mb_substr($title, 0, 80), 'notice', $noticeId);
        } else {
            $db->prepare("INSERT INTO notices (title, content, notice_date, attachment, is_active, is_popup, popup_photo_only, popup_image) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$title, $content, $noticeDate, $attachment, $isActive, $isPopup, $isPopupPhotoOnly, $popupImage]);
            $newNoticeId = (int)$db->lastInsertId();
            setFlash('success', $__t('नयाँ सूचना सफलतापूर्वक थपियो।', 'New notice added successfully.'));
            writeAuditLog('notice_create', 'Created: ' . mb_substr($title, 0, 80), 'notice', $newNoticeId);
        }
        redirect('notices.php');
    } catch (Exception $e) {
        setFlash('error', $__t('त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।', 'An error occurred. Please try again later.'));
        redirect('notices.php');
    }
}

if ($action === 'bulk_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = getDB();
        checkCSRF();
        $bulk = clean_text($_POST['bulk'] ?? '');
        $selected = $_POST['selected_ids'] ?? [];
        $ids = array_values(array_filter(array_map('intval', (array)$selected), fn($v) => $v > 0));
        if (empty($ids) || !in_array($bulk, ['active','inactive'], true)) {
            setFlash('error', $__t('Bulk update का लागि notice छान्नुहोस्।', 'Please select notices for bulk update.'));
            redirect('notices.php');
        }
        $target = $bulk === 'active' ? 1 : 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("UPDATE notices SET is_active = ? WHERE id IN ($ph)");
        $st->execute(array_merge([$target], $ids));
        setFlash('success', $__t('Bulk status update सफल भयो।', 'Bulk status update succeeded.'));
        writeAuditLog('notice_bulk_status', "Set {$bulk} on " . count($ids) . ' notice(s): IDs ' . implode(', ', $ids), 'notice');
    } catch (Exception $e) {
        setFlash('error', $__t('Bulk status update असफल भयो।', 'Bulk status update failed.'));
    }
    redirect('notices.php');
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    try {
        $db = getDB();
        checkCSRF();
        $db->prepare("DELETE FROM notices WHERE id=?")->execute([$id]);
        setFlash('success', $__t('सूचना मेटाइयो।', 'Notice deleted.'));
        writeAuditLog('notice_delete', "Deleted notice ID: {$id}", 'notice', $id);
    } catch (Exception $e) {
        setFlash('error', $__t('मेटाउन सकिएन।', 'Could not delete notice.'));
    }
    redirect('notices.php');
}

$notices = [];
try {
    $db      = getDB();
    $notices = $db->query("SELECT * FROM notices ORDER BY id DESC")->fetchAll();
} catch (Exception $e) { $notices = []; }

$flash = getFlash();
?>

<?php echo adminPageHeader($__t('सूचना व्यवस्थापन', 'Notices Management'), 'fa-bullhorn', $__t('संस्थाका सूचनाहरू — थप्नुहोस्, सम्पादन गर्नुहोस्।', 'Manage organization notices — add and edit.'),
    '<span class="badge admin-stat-badge ntc-stat-pill me-2"><i class="fas fa-layer-group me-1"></i>' . $__t('जम्मा', 'Total') . ': ' . count($notices) . '</span>'
);
?>
<?php echo adminHelpTip($__t('यो पृष्ठबाट संस्थाका सूचनाहरू थप्न, सम्पादन गर्न र हटाउन सकिन्छ।', 'Use this page to add, edit and remove notices.'), [$__t('नयाँ सूचना थप्न: माथिको "+" बटन थिच्नुहोस्।', 'To add a new notice: click "+" button above.'), $__t('सूचना publish/unpublish गर्न: Active/Inactive बटन थिच्नुहोस्।', 'To publish/unpublish: use Active/Inactive buttons.'), $__t('सूचना हटाउन: रातो Delete बटन थिच्नुहोस् (यो कार्य पूर्ववत हुन सक्दैन)।', 'To delete: click red Delete button (cannot be undone).')]); ?>

<?php if (!empty($flash)) { echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']); } ?>

<!-- Tab Navigation -->
<ul class="nav nav-tabs admin-nav-tabs mb-0" id="noticeTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-list" id="tab-list-btn">
            <i class="fas fa-list me-2"></i><?php echo $__t('सूचना सूची', 'Notice List'); ?>
            <span class="badge ntc-count-badge ms-1"><?php echo count($notices); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-form" id="tab-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="noticeFormTabLabel"><?php echo $__t('नयाँ थप्नुहोस्', 'Add New'); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="tab-list">
        <div class="card admin-table-card svc-flat-top-card">
            <div class="card-body p-0">
                <div class="table-responsive table-responsive-stack">
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="bulk_status">
                        <div class="px-3 py-2 border-bottom ntc-soft-bg d-flex gap-2 justify-content-end">
                            <button type="submit" name="bulk" value="active" class="btn btn-sm ntc-bulk-active">
                                <i class="fas fa-check-circle me-1"></i><?php echo $__t('Bulk सक्रिय', 'Bulk Active'); ?>
                            </button>
                            <button type="submit" name="bulk" value="inactive" class="btn btn-sm ntc-bulk-inactive">
                                <i class="fas fa-ban me-1"></i><?php echo $__t('Bulk निष्क्रिय', 'Bulk Inactive'); ?>
                            </button>
                        </div>
                    <table class="table table-hover align-middle mb-0" id="noticesTable">
                        <thead>
                            <tr>
                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('.nt-select').forEach(c=>c.checked=this.checked)"></th>
                                <th class="ps-3" width="50">#</th>
                                <th><?php echo $__t('शीर्षक', 'Title'); ?></th>
                                <th width="140"><?php echo $__t('मिति (बि.सं.)', 'Date (BS)'); ?></th>
                                <th width="90" class="text-center"><?php echo $__t('पप-अप', 'Popup'); ?></th>
                                <th width="100" class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                                <th width="130" class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($notices)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="admin-empty-state">
                                        <i class="fas fa-bullhorn"></i>
                                        <p><?php echo $__t('कुनै सूचना छैन। माथिको "नयाँ सूचना" बटन थिच्नुहोस्।', 'No notices yet. Click "New Notice" button above.'); ?></p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($notices as $idx => $item): ?>
                            <tr>
                                <td class="text-center" data-label=""><input type="checkbox" class="nt-select" name="selected_ids[]" value="<?php echo (int)$item['id']; ?>"></td>
                                <td class="ps-3 ntc-muted" data-label="#"><?php echo $idx + 1; ?></td>
                                <td data-label="शीर्षक">
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($item['title']); ?></div>
                                    <?php if ($item['attachment']): ?>
                                        <small class="ntc-muted"><i class="fas fa-paperclip me-1 ntc-file-icon"></i><?php echo $__t('फाइल संलग्न', 'File attached'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="मिति">
                                    <span class="text-secondary">
                                        <i class="far fa-calendar-alt me-1 ntc-date-icon"></i>
                                        <?php echo htmlspecialchars($item['notice_date'] ?? '—'); ?>
                                    </span>
                                </td>
                                <td class="text-center" data-label="पप-अप">
                                    <?php if ($item['is_popup']): ?>
                                        <span class="badge ntc-popup-badge"><i class="fas fa-bell me-1"></i><?php echo $__t('पप-अप', 'Popup'); ?></span>
                                    <?php else: ?>
                                        <span class="badge ntc-no-badge"><?php echo $__t('होइन', 'No'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" data-label="स्थिति">
                                    <?php if ($item['is_active']): ?>
                                        <span class="badge ntc-status-on"><i class="fas fa-check-circle me-1"></i><?php echo $__t('सक्रिय', 'Active'); ?></span>
                                    <?php else: ?>
                                        <span class="badge ntc-status-off"><i class="fas fa-times-circle me-1"></i><?php echo $__t('निष्क्रिय', 'Inactive'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center" data-label="कार्य">
                                    <button type="button"
                                        class="btn btn-sm ntc-btn-edit me-1 btn-edit-notice"
                                        title="<?php echo $__t('सम्पादन', 'Edit'); ?>"
                                        data-id="<?php echo $item['id']; ?>"
                                        data-title="<?php echo htmlspecialchars($item['title'], ENT_QUOTES); ?>"
                                        data-content="<?php echo htmlspecialchars($item['content'] ?? '', ENT_QUOTES); ?>"
                                        data-date="<?php echo htmlspecialchars($item['notice_date'] ?? '', ENT_QUOTES); ?>"
                                        data-active="<?php echo $item['is_active']; ?>"
                                        data-popup="<?php echo $item['is_popup']; ?>"
                                        data-attachment="<?php echo htmlspecialchars($item['attachment'] ?? '', ENT_QUOTES); ?>"
                                        data-popup_photo_only="<?php echo (int)($item['popup_photo_only'] ?? 0); ?>"
                                        data-popup_image="<?php echo htmlspecialchars($item['popup_image'] ?? '', ENT_QUOTES); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('<?php echo $__t('यो सूचना मेटाउने हो?', 'Delete this notice?'); ?>')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                        <?php echo csrfField(); ?>
                                        <button type="submit" class="btn btn-sm ntc-btn-delete" title="<?php echo $__t('मेटाउनुहोस्', 'Delete'); ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ TAB 2: Add / Edit Form ══ -->
    <div class="tab-pane fade" id="tab-form">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="noticeFormTitle">
                    <i class="fas fa-plus-circle me-2"></i><?php echo $__t('नयाँ सूचना थप्नुहोस्', 'Add New Notice'); ?>
                </h5>
                <button type="button" class="btn btn-sm ntc-soft-bg" id="btnCancelNotice">
                    <i class="fas fa-arrow-left me-1"></i><?php echo $__t('सूचीमा फर्कनुहोस्', 'Back to List'); ?>
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="notices.php" enctype="multipart/form-data" id="noticeForm" class="needs-validation" novalidate>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="save_notice" value="1">
                    <input type="hidden" name="notice_id" id="ntf_id" value="">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label fw-semibold ntc-label ntc-title-label">
                                    <i class="fas fa-heading me-1"></i><?php echo $__t('शीर्षक', 'Title'); ?> <span class="ntc-required">*</span>
                                </label>
                                <input type="text" name="title" id="ntf_title" class="form-control admin-fancy-input" required placeholder="<?php echo $__t('सूचनाको शीर्षक', 'Notice title'); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold ntc-label ntc-content-label">
                                    <i class="fas fa-align-left me-1"></i><?php echo $__t('विवरण (वैकल्पिक)', 'Description (optional)'); ?>
                                </label>
                                <textarea name="content" id="ntf_content" class="form-control admin-fancy-input" rows="6" placeholder="<?php echo $__t('सूचनाको विवरण...', 'Notice details...'); ?>"></textarea>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label fw-semibold ntc-label ntc-date-label">
                                    <i class="fas fa-calendar-alt me-1"></i><?php echo $__t('मिति (बि.सं.)', 'Date (BS)'); ?>
                                </label>
                                <div class="input-group">
                                    <input type="text" name="notice_date" id="ntf_date"
                                           class="form-control admin-fancy-input nepali-datepicker"
                                           placeholder="YYYY-MM-DD" autocomplete="off">
                                    <span class="input-group-text ntc-date-trigger ndp-trigger ntf-cursor-pointer">
                                        <i class="fas fa-calendar-alt"></i>
                                    </span>
                                </div>
                                <small class="ntc-muted"><?php echo $__t('बि.सं. मिति (नेपाली क्यालेन्डर)', 'BS date (Nepali calendar)'); ?></small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold ntc-label ntc-attach-label">
                                    <i class="fas fa-paperclip me-1"></i><?php echo $__t('फाइल (वैकल्पिक)', 'File (optional)'); ?>
                                    <small class="ntc-muted fw-normal" id="ntf_att_note"></small>
                                </label>
                                <input type="file" name="attachment" class="form-control admin-fancy-input" accept=".pdf,.jpg,.jpeg,.png">
                                <div id="ntf_att_link" class="mt-1 d-none">
                                    <small class="ntc-muted"><?php echo $__t('हालको फाइल', 'Current file'); ?>:
                                        <a id="ntf_att_href" href="#" target="_blank" class="fw-semibold ntc-attach-link">
                                            <i class="fas fa-external-link-alt me-1"></i><?php echo $__t('हेर्नुहोस्', 'View'); ?>
                                        </a>
                                    </small>
                                </div>
                            </div>
                            <div class="mb-2 d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="ntf_active">
                                </div>
                                <label class="form-label mb-0 fw-semibold" for="ntf_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                            </div>
                            <div class="mb-1 d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="is_popup" id="ntf_popup">
                                </div>
                                <label class="form-label mb-0 fw-semibold d-flex align-items-center gap-1" for="ntf_popup">
                                    <i class="fas fa-bell ntc-bell-icon"></i>
                                    <?php echo $__t('पप-अप देखाउनुहोस्', 'Show as popup'); ?>
                                </label>
                            </div>
                            <!-- Popup advanced options (visible only when is_popup is checked) -->
                            <div id="ntf_popup_opts" class="ms-4 mt-2 p-3 rounded" style="display:none;background:rgba(16,185,129,0.07);border:1px solid rgba(16,185,129,0.2);">
                                <div class="mb-2 d-flex align-items-center gap-2">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="popup_photo_only" id="ntf_popup_photo_only">
                                    </div>
                                    <label class="form-label mb-0 fw-semibold d-flex align-items-center gap-1" for="ntf_popup_photo_only">
                                        <i class="fas fa-image text-success"></i>
                                        <?php echo $__t('फोटो मात्र देखाउनुहोस् (Photo-only popup)', 'Photo-only popup'); ?>
                                    </label>
                                </div>
                                <div class="mt-2">
                                    <label class="form-label fw-semibold small mb-1">
                                        <i class="fas fa-image me-1 text-success"></i>
                                        <?php echo $__t('पप-अप फोटो (वैकल्पिक)', 'Popup image (optional)'); ?>
                                    </label>
                                    <input type="file" name="popup_image" id="ntf_popup_image"
                                           class="form-control admin-fancy-input form-control-sm"
                                           accept=".jpg,.jpeg,.png,.webp">
                                    <div id="ntf_popup_img_link" class="mt-1 d-none">
                                        <small><?php echo $__t('हालको फोटो', 'Current image'); ?>:
                                            <a id="ntf_popup_img_href" href="#" target="_blank" class="fw-semibold">
                                                <i class="fas fa-external-link-alt me-1"></i><?php echo $__t('हेर्नुहोस्', 'View'); ?>
                                            </a>
                                        </small>
                                    </div>
                                    <small class="text-muted"><?php echo $__t('फोटो नराखे attachment को image प्रयोग हुनेछ।', 'If not set, the attachment image will be used.'); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="ntf_submit" class="btn ntc-submit px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i><?php echo $__t('थप्नुहोस्', 'Add'); ?>
                        </button>
                        <button type="button" id="ntf_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i><?php echo $__t('रद्द', 'Cancel'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div><!-- end tab-content -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    var tabListBtn = document.getElementById('tab-list-btn');
    var tabFormBtn = document.getElementById('tab-form-btn');

    function switchToList() { adminSwitchTab(tabListBtn, tabFormBtn); }
    function switchToForm() { adminSwitchTab(tabFormBtn, tabListBtn); }

    function clearForm() {
        document.getElementById('ntf_id').value      = '';
        document.getElementById('ntf_title').value   = '';
        document.getElementById('ntf_content').value = '';
        document.getElementById('ntf_date').value    = '';
        document.getElementById('ntf_active').checked= true;
        document.getElementById('ntf_popup').checked = false;
        document.getElementById('ntf_popup_photo_only').checked = false;
        document.getElementById('ntf_popup_opts').style.display = 'none';
        document.getElementById('ntf_popup_img_link').classList.add('d-none');
        document.getElementById('ntf_att_link').classList.add('d-none');
        document.getElementById('ntf_att_note').textContent   = '';
        document.getElementById('ntf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i><?php echo $__t('थप्नुहोस्', 'Add'); ?>';
        document.getElementById('noticeFormTitle').innerHTML  = '<i class="fas fa-plus-circle me-2"></i><?php echo $__t('नयाँ सूचना थप्नुहोस्', 'Add New Notice'); ?>';
        document.getElementById('noticeFormTabLabel').textContent = '<?php echo $__t('नयाँ थप्नुहोस्', 'Add New'); ?>';
    }

    /* Edit mode flag — edit गर्दा tab switch हुँदा form clear नहोस् */
    var _isEditMode = false;
    /* Add New tab direct-click गर्दा मात्र form clear हुन्छ */
    if (tabFormBtn) tabFormBtn.addEventListener('show.bs.tab', function() {
        if (!_isEditMode) clearForm();
        _isEditMode = false;
    });

    /* is_popup toggle → show/hide popup advanced options */
    var ntfPopupChk = document.getElementById('ntf_popup');
    if (ntfPopupChk) {
        ntfPopupChk.addEventListener('change', function() {
            document.getElementById('ntf_popup_opts').style.display = this.checked ? '' : 'none';
        });
    }

    /* Cancel बटनहरू */
    ['btnCancelNotice','ntf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    /* Edit बटनहरू */
    document.querySelectorAll('.btn-edit-notice').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = this.dataset;
            document.getElementById('ntf_id').value      = d.id;
            document.getElementById('ntf_title').value   = d.title;
            document.getElementById('ntf_content').value = d.content || '';
            document.getElementById('ntf_date').value    = d.date;
            document.getElementById('ntf_active').checked= d.active === '1';
            document.getElementById('ntf_popup').checked = d.popup  === '1';
            document.getElementById('ntf_popup_photo_only').checked = d.popup_photo_only === '1';
            document.getElementById('ntf_popup_opts').style.display = d.popup === '1' ? '' : 'none';
            if (d.popup_image) {
                document.getElementById('ntf_popup_img_link').classList.remove('d-none');
                document.getElementById('ntf_popup_img_href').href = '../' + d.popup_image;
            } else {
                document.getElementById('ntf_popup_img_link').classList.add('d-none');
            }

            if (d.attachment) {
                document.getElementById('ntf_att_link').classList.remove('d-none');
                document.getElementById('ntf_att_href').href = '../' + d.attachment;
                document.getElementById('ntf_att_note').textContent = ' — <?php echo $__t('नयाँ फाइल नचुने भने पुरानै रहन्छ', 'old file is kept if no new file is selected'); ?>';
            } else {
                document.getElementById('ntf_att_link').classList.add('d-none');
                document.getElementById('ntf_att_note').textContent   = '';
            }
            document.getElementById('ntf_submit').innerHTML = '<i class="fas fa-save me-2"></i><?php echo $__t('अपडेट गर्नुहोस्', 'Update'); ?>';
            document.getElementById('noticeFormTitle').innerHTML  = '<i class="fas fa-edit me-2"></i><?php echo $__t('सूचना सम्पादन', 'Edit Notice'); ?>';
            document.getElementById('noticeFormTabLabel').textContent = '<?php echo $__t('सम्पादन', 'Edit'); ?>';
            _isEditMode = true;
            switchToForm();
        });
    });

    /* DataTable */
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        try {
            $('#noticesTable').DataTable({
                autoWidth: false,
                language: {
                    search    : '<?php echo $__t('खोज्नुहोस्', 'Search'); ?>:',
                    lengthMenu: '_MENU_ <?php echo $__t('पङ्क्ति', 'rows'); ?>',
                    info      : '_START_–_END_ / _TOTAL_ <?php echo $__t('सूचना', 'notices'); ?>',
                    paginate  : { previous: '‹', next: '›' },
                    emptyTable: '<?php echo $__t('कुनै सूचना छैन', 'No notices found'); ?>'
                },
                order     : [[0, 'desc']],
                pageLength: 15,
                columnDefs: [{ orderable: false, targets: [0,6] }]
            });
        } catch(e) {}
    }
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
