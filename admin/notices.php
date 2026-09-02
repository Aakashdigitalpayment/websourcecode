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
require_once dirname(__DIR__) . '/includes/simple-cache.php';

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

$ntcRedirect = static function (?int $editId = null): void {
    redirect($editId > 0 ? 'notices.php?edit=' . $editId : 'notices.php');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notice'])) {
    checkCSRF();

    $noticeIdPost      = (int) ($_POST['notice_id'] ?? 0);
    $title             = clean_text($_POST['title'] ?? '');
    $content           = function_exists('coop_sanitize_cms_html')
        ? coop_sanitize_cms_html($_POST['content'] ?? '')
        : trim((string) ($_POST['content'] ?? ''));
    $noticeDate        = !empty(trim($_POST['notice_date'] ?? '')) ? clean_text($_POST['notice_date']) : null;
    $isActive          = isset($_POST['is_active']) ? 1 : 0;
    $isPopup           = isset($_POST['is_popup']) ? 1 : 0;
    $isPopupPhotoOnly  = isset($_POST['popup_photo_only']) ? 1 : 0;
    $removeAttachment  = isset($_POST['remove_attachment']);
    $removePopupImage  = isset($_POST['remove_popup_image']);
    $newAttachment     = null;
    $newPopupImage     = null;
    $uploadErrors      = [];

    if ($title === '') {
        setFlash('error', $__t('शीर्षक अनिवार्य छ।', 'Title is required.'));
        $ntcRedirect($noticeIdPost > 0 ? $noticeIdPost : null);
    }

    if (isset($_FILES['attachment']) && (int) ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $errCode = (int) $_FILES['attachment']['error'];
        if ($errCode !== UPLOAD_ERR_OK) {
            $uploadErrors[] = coop_upload_error_text($errCode);
        } else {
            $upload = uploadFile($_FILES['attachment'], 'notices');
            if ($upload['success']) {
                $newAttachment = $upload['path'];
            } else {
                $uploadErrors[] = (string) ($upload['message'] ?? $__t('फाइल अपलोड असफल।', 'File upload failed.'));
            }
        }
    }
    if (isset($_FILES['popup_image']) && (int) ($_FILES['popup_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $errCode = (int) $_FILES['popup_image']['error'];
        if ($errCode !== UPLOAD_ERR_OK) {
            $uploadErrors[] = coop_upload_error_text($errCode);
        } else {
            $upload2 = uploadFile($_FILES['popup_image'], 'notices');
            if ($upload2['success']) {
                $newPopupImage = $upload2['path'];
            } else {
                $uploadErrors[] = (string) ($upload2['message'] ?? $__t('पप-अप फोटो अपलोड असफल।', 'Popup image upload failed.'));
            }
        }
    }

    if (!empty($uploadErrors)) {
        setFlash('error', implode(' ', $uploadErrors));
        $ntcRedirect($noticeIdPost > 0 ? $noticeIdPost : null);
    }

    try {
        $db = getDB();
        $oldRow = null;
        if ($noticeIdPost > 0) {
            $oldStmt = $db->prepare('SELECT * FROM notices WHERE id = ? LIMIT 1');
            $oldStmt->execute([$noticeIdPost]);
            $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldRow) {
                setFlash('error', $__t('सूचना भेटिएन।', 'Notice not found.'));
                redirect('notices.php');
            }
        }

        $finalAttachment = trim((string) ($oldRow['attachment'] ?? ''));
        $finalPopupImage = trim((string) ($oldRow['popup_image'] ?? ''));

        if ($removeAttachment && $finalAttachment !== '') {
            deleteFile($finalAttachment);
            $finalAttachment = '';
        }
        if ($newAttachment) {
            if ($finalAttachment !== '') {
                deleteFile($finalAttachment);
            }
            $finalAttachment = $newAttachment;
        }

        if ($removePopupImage && $finalPopupImage !== '') {
            deleteFile($finalPopupImage);
            $finalPopupImage = '';
        }
        if ($newPopupImage) {
            if ($finalPopupImage !== '') {
                deleteFile($finalPopupImage);
            }
            $finalPopupImage = $newPopupImage;
        }

        if ($isPopup && $isPopupPhotoOnly) {
            $hasPopupImg = $finalPopupImage !== ''
                || ($finalAttachment !== '' && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $finalAttachment));
            if (!$hasPopupImg) {
                setFlash(
                    'error',
                    $__t(
                        'फोटो-मात्र पप-अपको लागि पप-अप फोटो वा image फाइल (JPG/PNG) attachment चाहिन्छ।',
                        'Photo-only popup needs a popup image or an image file attachment (JPG/PNG).'
                    )
                );
                $ntcRedirect($noticeIdPost > 0 ? $noticeIdPost : null);
            }
        }

        $attachDb = $finalAttachment !== '' ? $finalAttachment : null;
        $popupDb  = $finalPopupImage !== '' ? $finalPopupImage : null;

        if ($noticeIdPost > 0) {
            $st = $db->prepare(
                'UPDATE notices
                 SET title=?, content=?, notice_date=?, attachment=?, is_active=?, is_popup=?, popup_photo_only=?, popup_image=?
                 WHERE id=?'
            );
            $st->execute([$title, $content, $noticeDate, $attachDb, $isActive, $isPopup, $isPopupPhotoOnly, $popupDb, $noticeIdPost]);
            setFlash('success', $__t('सूचना सफलतापूर्वक अपडेट भयो।', 'Notice updated successfully.'));
            writeAuditLog('notice_update', 'Updated: ' . mb_substr($title, 0, 80), 'notice', $noticeIdPost);
            if (function_exists('clearHomepageCache')) {
                clearHomepageCache();
            }
            redirect('notices.php?edit=' . $noticeIdPost);
        }

        $db->prepare(
            'INSERT INTO notices (title, content, notice_date, attachment, is_active, is_popup, popup_photo_only, popup_image)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$title, $content, $noticeDate, $attachDb, $isActive, $isPopup, $isPopupPhotoOnly, $popupDb]);
        $newNoticeId = (int) $db->lastInsertId();
        setFlash('success', $__t('नयाँ सूचना सफलतापूर्वक थपियो।', 'New notice added successfully.'));
        writeAuditLog('notice_create', 'Created: ' . mb_substr($title, 0, 80), 'notice', $newNoticeId);
        if (function_exists('clearHomepageCache')) {
            clearHomepageCache();
        }
        redirect('notices.php?edit=' . $newNoticeId);
    } catch (Exception $e) {
        setFlash('error', $__t('त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।', 'An error occurred. Please try again later.'));
        $ntcRedirect($noticeIdPost > 0 ? $noticeIdPost : null);
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
        if (function_exists('clearHomepageCache')) clearHomepageCache();
    } catch (Exception $e) {
        setFlash('error', $__t('Bulk status update असफल भयो।', 'Bulk status update failed.'));
    }
    redirect('notices.php');
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    try {
        $db = getDB();
        checkCSRF();
        $fileStmt = $db->prepare('SELECT attachment, popup_image FROM notices WHERE id = ? LIMIT 1');
        $fileStmt->execute([$id]);
        $fileRow = $fileStmt->fetch(PDO::FETCH_ASSOC);
        if ($fileRow) {
            if (!empty($fileRow['attachment'])) {
                deleteFile((string) $fileRow['attachment']);
            }
            if (!empty($fileRow['popup_image'])) {
                deleteFile((string) $fileRow['popup_image']);
            }
        }
        $db->prepare('DELETE FROM notices WHERE id=?')->execute([$id]);
        setFlash('success', $__t('सूचना मेटाइयो।', 'Notice deleted.'));
        writeAuditLog('notice_delete', "Deleted notice ID: {$id}", 'notice', $id);
        if (function_exists('clearHomepageCache')) clearHomepageCache();
    } catch (Exception $e) {
        setFlash('error', $__t('मेटाउन सकिएन।', 'Could not delete notice.'));
    }
    redirect('notices.php');
}

$notices = [];
try {
    $db      = getDB();
    $notices = $db->query("SELECT * FROM notices ORDER BY id DESC LIMIT 500")->fetchAll();
} catch (Exception $e) { $notices = []; }

$flash = getFlash();
$editNoticeId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editNotice = null;
if ($editNoticeId > 0) {
    try {
        $stEdit = $db->prepare('SELECT * FROM notices WHERE id = ? LIMIT 1');
        $stEdit->execute([$editNoticeId]);
        $editNotice = $stEdit->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        $editNotice = null;
    }
}
if ($editNoticeId > 0 && !$editNotice && empty($flash)) {
    $flash = ['type' => 'error', 'message' => $__t('सूचना भेटिएन वा मेटिसकिएको छ।', 'Notice not found or was deleted.')];
}
$startOnFormTab = $editNotice !== null;
$ef = is_array($editNotice) ? $editNotice : [];
$efAttachment = trim((string) ($ef['attachment'] ?? ''));
$efPopupImage = trim((string) ($ef['popup_image'] ?? ''));
$ntcBasename = static function (?string $path): string {
    $path = trim(str_replace('\\', '/', (string) $path));
    return $path !== '' ? basename($path) : '';
};
?>

<?php echo adminPageHeader($__t('सूचना व्यवस्थापन', 'Notices Management'), 'fa-bullhorn', $__t('संस्थाका सूचनाहरू — थप्नुहोस्, सम्पादन गर्नुहोस्।', 'Manage organization notices — add and edit.'),
    '<span class="badge admin-stat-badge ntc-stat-pill me-2"><i class="fas fa-layer-group me-1"></i>' . $__t('जम्मा', 'Total') . ': ' . count($notices) . '</span>'
);
?>
<?php echo adminHelpTip($__t('यो पृष्ठबाट संस्थाका सूचनाहरू थप्न, सम्पादन गर्न र हटाउन सकिन्छ।', 'Use this page to add, edit and remove notices.'), [$__t('नयाँ सूचना थप्न: "नयाँ थप्नुहोस्" tab थिच्नुहोस्।', 'To add a new notice: open the "Add New" tab.'), $__t('सक्रिय/निष्क्रिय: सूचना छानेर Bulk सक्रिय वा Bulk निष्क्रिय थिच्नुहोस्।', 'Active/inactive: select notices and use Bulk Active or Bulk Inactive.'), $__t('सूचना हटाउन: रातो Delete बटन थिच्नुहोस् (यो कार्य पूर्ववत हुन सक्दैन)।', 'To delete: click red Delete button (cannot be undone).')]); ?>

<?php if (!empty($flash)) { echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']); } ?>

<!-- Tab Navigation -->
<ul class="nav nav-tabs admin-nav-tabs mb-0" id="noticeTabs">
    <li class="nav-item">
        <button type="button" class="nav-link<?php echo $startOnFormTab ? '' : ' active'; ?>" data-bs-toggle="tab" data-bs-target="#tab-list" id="tab-list-btn">
            <i class="fas fa-list me-2"></i><?php echo $__t('सूचना सूची', 'Notice List'); ?>
            <span class="badge ntc-count-badge ms-1"><?php echo count($notices); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button type="button" class="nav-link<?php echo $startOnFormTab ? ' active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-form" id="tab-form-btn">
            <i class="fas fa-<?php echo $startOnFormTab ? 'edit' : 'plus-circle'; ?> me-2"></i><span id="noticeFormTabLabel"><?php echo $startOnFormTab ? $__t('सम्पादन', 'Edit') : $__t('नयाँ थप्नुहोस्', 'Add New'); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade<?php echo $startOnFormTab ? '' : ' show active'; ?>" id="tab-list">
        <div class="card admin-table-card svc-flat-top-card">
            <div class="card-body p-0">
                <div class="table-responsive table-responsive-stack">
                    <form method="POST" id="noticeBulkForm">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="bulk_status">
                        <div class="px-3 py-2 border-bottom ntc-soft-bg d-flex gap-2 justify-content-end">
                            <button type="submit" name="bulk" value="active" class="btn btn-sm ntc-bulk-active admin-bulk-btn">
                                <i class="fas fa-check-circle me-1"></i><?php echo $__t('Bulk सक्रिय', 'Bulk Active'); ?>
                            </button>
                            <button type="submit" name="bulk" value="inactive" class="btn btn-sm ntc-bulk-inactive admin-bulk-btn">
                                <i class="fas fa-ban me-1"></i><?php echo $__t('Bulk निष्क्रिय', 'Bulk Inactive'); ?>
                            </button>
                        </div>
                    </form>
                    <table class="table table-hover align-middle mb-0" id="noticesTable">
                        <thead>
                            <tr>
                                <th width="40" class="text-center"><input type="checkbox" form="noticeBulkForm" id="ntSelectAll" title="<?php echo $__t('यो पृष्ठका सबै', 'All on this page'); ?>"></th>
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
                                <td colspan="7">
                                    <div class="admin-empty-state">
                                        <i class="fas fa-bullhorn"></i>
                                        <p><?php echo $__t('कुनै सूचना छैन। "नयाँ थप्नुहोस्" tab खोल्नुहोस्।', 'No notices yet. Open the "Add New" tab.'); ?></p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($notices as $idx => $item): ?>
                            <tr>
                                <td class="text-center" data-label=""><input type="checkbox" class="nt-select" form="noticeBulkForm" name="selected_ids[]" value="<?php echo (int)$item['id']; ?>"></td>
                                <td class="ps-3 ntc-muted" data-label="#"><?php echo $idx + 1; ?></td>
                                <td data-label="शीर्षक">
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($item['title']); ?></div>
                                    <?php if ($item['attachment']): ?>
                                        <small class="ntc-muted"><i class="fas fa-paperclip me-1 ntc-file-icon"></i><?php echo $__t('फाइल संलग्न', 'File attached'); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($item['is_active'])): ?>
                                    <div class="mt-1">
                                        <a href="../notices.php?id=<?php echo (int)$item['id']; ?>" class="small text-decoration-none" target="_blank" rel="noopener noreferrer">
                                            <i class="fas fa-external-link-alt me-1"></i><?php echo $__t('Public हेर्नुहोस्', 'View public'); ?>
                                        </a>
                                    </div>
                                    <?php else: ?>
                                    <div class="mt-1"><small class="text-muted"><?php echo $__t('निष्क्रिय — public मा देखिँदैन', 'Inactive — hidden on public site'); ?></small></div>
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
                                    <div class="d-inline-flex align-items-center gap-1">
                                    <a href="notices.php?edit=<?php echo (int) $item['id']; ?>"
                                        class="adm-icon-btn adm-icon-btn--edit"
                                        title="<?php echo $__t('सम्पादन', 'Edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" class="svc-inline-form d-inline" onsubmit="return confirm('<?php echo $__t('यो सूचना मेटाउने हो?', 'Delete this notice?'); ?>')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                        <?php echo csrfField(); ?>
                                        <button type="submit" class="adm-icon-btn adm-icon-btn--delete" title="<?php echo $__t('मेटाउनुहोस्', 'Delete'); ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ TAB 2: Add / Edit Form ══ -->
    <div class="tab-pane fade<?php echo $startOnFormTab ? ' show active' : ''; ?>" id="tab-form">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="noticeFormTitle">
                    <?php if ($startOnFormTab): ?>
                    <i class="fas fa-edit me-2"></i><?php echo $__t('सूचना सम्पादन', 'Edit Notice'); ?>
                    <?php else: ?>
                    <i class="fas fa-plus-circle me-2"></i><?php echo $__t('नयाँ सूचना थप्नुहोस्', 'Add New Notice'); ?>
                    <?php endif; ?>
                </h5>
                <button type="button" class="btn btn-sm ntc-soft-bg" id="btnCancelNotice">
                    <i class="fas fa-arrow-left me-1"></i><?php echo $__t('सूचीमा फर्कनुहोस्', 'Back to List'); ?>
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="notices.php" enctype="multipart/form-data" id="noticeForm" class="needs-validation" novalidate>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="save_notice" value="1">
                    <input type="hidden" name="notice_id" id="ntf_id" value="<?php echo (int) ($ef['id'] ?? 0); ?>">
                    <input type="hidden" name="existing_attachment" id="ntf_existing_attachment" value="<?php echo htmlspecialchars($efAttachment, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="existing_popup_image" id="ntf_existing_popup_image" value="<?php echo htmlspecialchars($efPopupImage, ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="ntf_title" class="form-label fw-semibold ntc-label ntc-title-label">
                                    <i class="fas fa-heading me-1"></i><?php echo $__t('शीर्षक', 'Title'); ?> <span class="ntc-required">*</span>
                                </label>
                                <input type="text" name="title" id="ntf_title" class="form-control admin-fancy-input" required placeholder="<?php echo $__t('सूचनाको शीर्षक', 'Notice title'); ?>" value="<?php echo htmlspecialchars((string) ($ef['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="mb-3">
                                <label for="ntf_content" class="form-label fw-semibold ntc-label ntc-content-label">
                                    <i class="fas fa-align-left me-1"></i><?php echo $__t('विवरण (वैकल्पिक)', 'Description (optional)'); ?>
                                </label>
                                <textarea name="content" id="ntf_content" class="form-control admin-fancy-input" rows="6" placeholder="<?php echo $__t('सूचनाको विवरण...', 'Notice details...'); ?>"><?php echo htmlspecialchars((string) ($ef['content'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="ntf_date" class="form-label fw-semibold ntc-label ntc-date-label">
                                    <i class="fas fa-calendar-alt me-1"></i><?php echo $__t('मिति (बि.सं.)', 'Date (BS)'); ?>
                                </label>
                                <div class="input-group">
                                    <input type="text" name="notice_date" id="ntf_date"
                                           class="form-control admin-fancy-input nepali-datepicker"
                                           placeholder="YYYY-MM-DD" autocomplete="off"
                                           value="<?php echo htmlspecialchars((string) ($ef['notice_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
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
                                <div id="ntf_att_link" class="mb-2<?php echo $efAttachment !== '' ? '' : ' d-none'; ?>">
                                    <div class="alert alert-success py-2 px-3 mb-0 small">
                                        <div class="fw-semibold mb-1"><i class="fas fa-check-circle me-1"></i><?php echo $__t('हाल upload भएको फाइल', 'Currently uploaded file'); ?></div>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <i class="<?php echo preg_match('/\.pdf$/i', $efAttachment) ? 'fas fa-file-pdf text-danger' : (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $efAttachment) ? 'fas fa-file-image text-success' : 'fas fa-file text-secondary'); ?>" id="ntf_att_icon" aria-hidden="true"></i>
                                            <span id="ntf_att_name" class="text-truncate fw-semibold"><?php echo htmlspecialchars($ntcBasename($efAttachment), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <a id="ntf_att_href" href="<?php echo $efAttachment !== '' ? '../' . htmlspecialchars($efAttachment, ENT_QUOTES, 'UTF-8') : '#'; ?>" target="_blank" class="fw-semibold ntc-attach-link ms-auto" rel="noopener noreferrer">
                                                <i class="fas fa-external-link-alt me-1"></i><?php echo $__t('हेर्नुहोस्', 'View'); ?>
                                            </a>
                                        </div>
                                        <div class="form-check mt-2 mb-0">
                                            <input class="form-check-input" type="checkbox" name="remove_attachment" id="ntf_remove_attachment" value="1">
                                            <label class="form-check-label small text-danger" for="ntf_remove_attachment"><?php echo $__t('यो फाइल हटाउनुहोस्', 'Remove this file'); ?></label>
                                        </div>
                                    </div>
                                </div>
                                <div id="ntf_att_empty" class="mb-2 small text-muted<?php echo $efAttachment !== '' ? ' d-none' : ''; ?>">
                                    <i class="fas fa-info-circle me-1"></i><?php echo $__t('हाल कुनै फाइल upload भएको छैन', 'No file uploaded yet'); ?>
                                </div>
                                <label for="ntf_attachment" class="form-label small text-muted mb-1"><?php echo $__t('नयाँ फाइल बदल्न (वैकल्पिक)', 'Replace with new file (optional)'); ?></label>
                                <input type="file" name="attachment" id="ntf_attachment" class="form-control admin-fancy-input" accept=".pdf,.jpg,.jpeg,.png,.webp,.gif">
                                <small class="text-muted"><?php echo $__t('PDF/JPG/PNG/WebP/GIF — अधिकतम 10MB', 'PDF/JPG/PNG/WebP/GIF — max 10MB'); ?></small>
                            </div>
                            <div class="mb-2 d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="ntf_active"<?php echo ($startOnFormTab ? !empty($ef['is_active']) : true) ? ' checked' : ''; ?>>
                                </div>
                                <label class="form-label mb-0 fw-semibold" for="ntf_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                            </div>
                            <div class="mb-1 d-flex align-items-center gap-2">
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" name="is_popup" id="ntf_popup"<?php echo !empty($ef['is_popup']) ? ' checked' : ''; ?>>
                                </div>
                                <label class="form-label mb-0 fw-semibold d-flex align-items-center gap-1" for="ntf_popup">
                                    <i class="fas fa-bell ntc-bell-icon"></i>
                                    <?php echo $__t('पप-अप देखाउनुहोस्', 'Show as popup'); ?>
                                </label>
                            </div>
                            <!-- Popup advanced options (visible only when is_popup is checked) -->
                            <div id="ntf_popup_opts" class="ms-4 mt-2 p-3 rounded" style="display:<?php echo !empty($ef['is_popup']) ? '' : 'none'; ?>;background:rgba(16,185,129,0.07);border:1px solid rgba(16,185,129,0.2);">
                                <div class="mb-2 d-flex align-items-center gap-2">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="popup_photo_only" id="ntf_popup_photo_only"<?php echo !empty($ef['popup_photo_only']) ? ' checked' : ''; ?>>
                                    </div>
                                    <label class="form-label mb-0 fw-semibold d-flex align-items-center gap-1" for="ntf_popup_photo_only">
                                        <i class="fas fa-image text-success"></i>
                                        <?php echo $__t('फोटो मात्र देखाउनुहोस् (Photo-only popup)', 'Photo-only popup'); ?>
                                    </label>
                                </div>
                                <div class="mt-2" id="ntf_popup_photo_wrap">
                                    <label class="form-label fw-semibold small mb-1">
                                        <i class="fas fa-image me-1 text-success"></i>
                                        <?php echo $__t('पप-अप फोटो (वैकल्पिक)', 'Popup image (optional)'); ?>
                                    </label>
                                    <small class="text-muted d-block mb-2"><?php echo $__t('Photo-only popup मा मुख्य रूपमा प्रयोग हुन्छ।', 'Used mainly for photo-only popup mode.'); ?></small>
                                    <div id="ntf_popup_img_link" class="mb-2<?php echo $efPopupImage !== '' ? '' : ' d-none'; ?>">
                                        <div class="alert alert-success py-2 px-3 mb-0 small">
                                            <div class="fw-semibold mb-1"><i class="fas fa-check-circle me-1"></i><?php echo $__t('हाल upload भएको पप-अप फोटो', 'Current popup image'); ?></div>
                                            <div class="d-flex align-items-start gap-2 flex-wrap">
                                                <img id="ntf_popup_img_preview" src="<?php echo $efPopupImage !== '' ? '../' . htmlspecialchars($efPopupImage, ENT_QUOTES, 'UTF-8') : ''; ?>" alt="" class="rounded border<?php echo $efPopupImage !== '' ? '' : ' d-none'; ?>" style="max-height:72px;max-width:110px;object-fit:contain;">
                                                <div class="flex-grow-1">
                                                    <span id="ntf_popup_img_name" class="d-block text-truncate small fw-semibold"><?php echo htmlspecialchars($ntcBasename($efPopupImage), ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <a id="ntf_popup_img_href" href="<?php echo $efPopupImage !== '' ? '../' . htmlspecialchars($efPopupImage, ENT_QUOTES, 'UTF-8') : '#'; ?>" target="_blank" class="fw-semibold small" rel="noopener noreferrer">
                                                        <i class="fas fa-external-link-alt me-1"></i><?php echo $__t('हेर्नुहोस्', 'View'); ?>
                                                    </a>
                                                </div>
                                            </div>
                                            <div class="form-check mt-2 mb-0">
                                                <input class="form-check-input" type="checkbox" name="remove_popup_image" id="ntf_remove_popup_image" value="1">
                                                <label class="form-check-label small text-danger" for="ntf_remove_popup_image"><?php echo $__t('यो फोटो हटाउनुहोस्', 'Remove this image'); ?></label>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="ntf_popup_img_empty" class="mb-2 small text-muted<?php echo $efPopupImage !== '' ? ' d-none' : ''; ?>">
                                        <i class="fas fa-info-circle me-1"></i><?php echo $__t('हाल कुनै पप-अप फोटो छैन', 'No popup image uploaded yet'); ?>
                                    </div>
                                    <label for="ntf_popup_image" class="form-label small text-muted mb-1"><?php echo $__t('नयाँ फोटो बदल्न (वैकल्पिक)', 'Replace with new image (optional)'); ?></label>
                                    <input type="file" name="popup_image" id="ntf_popup_image"
                                           class="form-control admin-fancy-input form-control-sm"
                                           accept=".jpg,.jpeg,.png,.webp,.gif">
                                    <small class="text-muted d-block"><?php echo $__t('JPG/PNG/WebP/GIF — अधिकतम 10MB', 'JPG/PNG/WebP/GIF — max 10MB'); ?></small>
                                    <small class="text-muted d-block"><?php echo $__t('Photo-only बन्द भए पप-अप फोटो सामान्य popup मा पनि देखिन सक्छ।', 'When not photo-only, popup image may still show in the standard popup.'); ?></small>
                                    <small class="text-muted"><?php echo $__t('फाइल (PDF) पनि भए photo click गर्दा फाइल खुल्छ।', 'If a file (PDF) is also attached, clicking the popup photo opens that file.'); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="ntf_submit" class="btn ntc-submit px-5 fw-semibold">
                            <?php if ($startOnFormTab): ?>
                            <i class="fas fa-save me-2"></i><?php echo $__t('अपडेट गर्नुहोस्', 'Update'); ?>
                            <?php else: ?>
                            <i class="fas fa-plus-circle me-2"></i><?php echo $__t('थप्नुहोस्', 'Add'); ?>
                            <?php endif; ?>
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

    function basenamePath(path) {
        if (!path) return '';
        var p = String(path).replace(/\\/g, '/');
        return p.split('/').pop() || p;
    }

    function fileIconClass(path) {
        if (/\.pdf$/i.test(path)) return 'fas fa-file-pdf text-danger';
        if (/\.(jpg|jpeg|png|webp|gif)$/i.test(path)) return 'fas fa-file-image text-success';
        return 'fas fa-file text-secondary';
    }

    function setCurrentUploadUi(wrapId, hrefId, nameId, iconId, previewId, path, noteId, emptyId, hiddenId, removeId) {
        var wrap = document.getElementById(wrapId);
        if (!wrap) return;
        path = path ? String(path).trim() : '';
        if (hiddenId) {
            var hiddenEl = document.getElementById(hiddenId);
            if (hiddenEl) hiddenEl.value = path;
        }
        if (removeId) {
            var removeEl = document.getElementById(removeId);
            if (removeEl) {
                removeEl.checked = false;
                removeEl.closest('.form-check')?.classList.toggle('d-none', !path);
            }
        }
        if (!path) {
            wrap.classList.add('d-none');
            if (emptyId) {
                var emptyEl = document.getElementById(emptyId);
                if (emptyEl) emptyEl.classList.remove('d-none');
            }
            if (noteId) {
                var noteEl = document.getElementById(noteId);
                if (noteEl) noteEl.textContent = '';
            }
            return;
        }
        wrap.classList.remove('d-none');
        if (emptyId) {
            var emptyHide = document.getElementById(emptyId);
            if (emptyHide) emptyHide.classList.add('d-none');
        }
        var hrefEl = document.getElementById(hrefId);
        if (hrefEl) hrefEl.href = '../' + path;
        var nameEl = document.getElementById(nameId);
        if (nameEl) nameEl.textContent = basenamePath(path);
        if (iconId) {
            var iconEl = document.getElementById(iconId);
            if (iconEl) iconEl.className = fileIconClass(path);
        }
        if (previewId) {
            var prev = document.getElementById(previewId);
            if (prev) {
                if (/\.(jpg|jpeg|png|webp|gif)$/i.test(path)) {
                    prev.src = '../' + path;
                    prev.classList.remove('d-none');
                } else {
                    prev.removeAttribute('src');
                    prev.classList.add('d-none');
                }
            }
        }
        if (noteId) {
            var note = document.getElementById(noteId);
            if (note) note.textContent = ' — <?php echo $__t('नयाँ फाइल नचुने भने पुरानै रहन्छ', 'old file is kept if no new file is selected'); ?>';
        }
    }

    function syncPopupPhotoWrap() {
        var popupOn = document.getElementById('ntf_popup')?.checked;
        var opts = document.getElementById('ntf_popup_opts');
        if (opts) opts.style.display = popupOn ? '' : 'none';
    }

    function syncRemoveCheckboxes() {
        ['ntf_remove_attachment', 'ntf_remove_popup_image'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            var hasFile = id === 'ntf_remove_attachment'
                ? (document.getElementById('ntf_existing_attachment')?.value || '').trim() !== ''
                : (document.getElementById('ntf_existing_popup_image')?.value || '').trim() !== '';
            el.closest('.form-check')?.classList.toggle('d-none', !hasFile);
            el.checked = false;
        });
    }

    function resetFileInputs() {
        ['ntf_attachment', 'ntf_popup_image'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) {
                try { el.value = ''; } catch (e) {}
            }
        });
    }

    function clearForm() {
        document.getElementById('ntf_id').value      = '';
        document.getElementById('ntf_title').value   = '';
        document.getElementById('ntf_content').value = '';
        document.getElementById('ntf_date').value    = '';
        document.getElementById('ntf_active').checked= true;
        document.getElementById('ntf_popup').checked = false;
        document.getElementById('ntf_popup_photo_only').checked = false;
        document.getElementById('ntf_popup_opts').style.display = 'none';
        setCurrentUploadUi('ntf_popup_img_link', 'ntf_popup_img_href', 'ntf_popup_img_name', null, 'ntf_popup_img_preview', '', null, 'ntf_popup_img_empty', 'ntf_existing_popup_image', 'ntf_remove_popup_image');
        setCurrentUploadUi('ntf_att_link', 'ntf_att_href', 'ntf_att_name', 'ntf_att_icon', null, '', 'ntf_att_note', 'ntf_att_empty', 'ntf_existing_attachment', 'ntf_remove_attachment');
        resetFileInputs();
        document.getElementById('ntf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i><?php echo $__t('थप्नुहोस्', 'Add'); ?>';
        document.getElementById('noticeFormTitle').innerHTML  = '<i class="fas fa-plus-circle me-2"></i><?php echo $__t('नयाँ सूचना थप्नुहोस्', 'Add New Notice'); ?>';
        document.getElementById('noticeFormTabLabel').textContent = '<?php echo $__t('नयाँ थप्नुहोस्', 'Add New'); ?>';
    }

    /* Edit mode flag — edit गर्दा tab switch हुँदा form clear नहोस् */
    var _isEditMode = <?php echo $startOnFormTab ? 'true' : 'false'; ?>;
    /* Add New tab direct-click गर्दा मात्र form clear हुन्छ */
    if (tabFormBtn) tabFormBtn.addEventListener('show.bs.tab', function() {
        if (!_isEditMode) clearForm();
    });
    if (tabFormBtn) tabFormBtn.addEventListener('shown.bs.tab', function() {
        _isEditMode = false;
    });

    /* is_popup toggle → show/hide popup advanced options */
    var ntfPopupChk = document.getElementById('ntf_popup');
    if (ntfPopupChk) {
        ntfPopupChk.addEventListener('change', syncPopupPhotoWrap);
    }
    syncPopupPhotoWrap();
    syncRemoveCheckboxes();

    var bulkForm = document.getElementById('noticeBulkForm');
    if (bulkForm) {
        bulkForm.addEventListener('submit', function (e) {
            var checked = bulkForm.querySelectorAll('.nt-select:checked');
            if (!checked.length) {
                e.preventDefault();
                alert('<?php echo $__t('कृपया कम्तीमा एउटा सूचना छान्नुहोस्।', 'Please select at least one notice.'); ?>');
                return;
            }
            var action = e.submitter ? e.submitter.value : '';
            var label = action === 'active'
                ? '<?php echo $__t('सक्रिय', 'active'); ?>'
                : '<?php echo $__t('निष्क्रिय', 'inactive'); ?>';
            if (!confirm(checked.length + ' <?php echo $__t('वटा सूचना', 'notice(s)'); ?> ' + label + ' <?php echo $__t('गर्ने?', 'apply?'); ?>')) {
                e.preventDefault();
            }
        });
    }

    /* Cancel बटनहरू */
    ['btnCancelNotice','ntf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    /* DataTable */
    var noticesTable = null;
    if (typeof $ !== 'undefined' && $.fn.DataTable) {
        try {
            noticesTable = $('#noticesTable').DataTable({
                autoWidth: false,
                language: {
                    search    : '<?php echo $__t('खोज्नुहोस्', 'Search'); ?>:',
                    lengthMenu: '_MENU_ <?php echo $__t('पङ्क्ति', 'rows'); ?>',
                    info      : '_START_–_END_ / _TOTAL_ <?php echo $__t('सूचना', 'notices'); ?>',
                    paginate  : { previous: '‹', next: '›' },
                    emptyTable: '<?php echo $__t('कुनै सूचना छैन', 'No notices found'); ?>'
                },
                order     : [],
                pageLength: 15,
                columnDefs: [{ orderable: false, targets: [0, 6] }]
            });
        } catch(e) {}
    }

    var selectAll = document.getElementById('ntSelectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function () {
            if (noticesTable) {
                noticesTable.rows({ page: 'current', search: 'applied' }).nodes().to$().find('.nt-select').prop('checked', selectAll.checked);
            } else {
                document.querySelectorAll('#noticesTable tbody .nt-select').forEach(function (el) {
                    el.checked = selectAll.checked;
                });
            }
        });
    }
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
