<?php
/**
 * Information Room — Admin CRUD (board decisions, policies, bylaws)
 */
$__t = static function (string $np, string $en): string {
    $lang = (string) ($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};

$pageTitle = $__t('Information Room व्यवस्थापन', 'Information Room Management');
$currentPage = 'information-room';

require_once __DIR__ . '/../includes/information-room-tables.php';

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();
ensureInformationRoomTables($db);
ensureInformationRoomMemberColumn($db);

checkCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add' || $action === 'edit') {
            $id = (int) ($_POST['id'] ?? 0);
            $title = clean_text($_POST['title'] ?? '', 255);
            $titleNp = clean_text($_POST['title_np'] ?? '', 255);
            $description = trim((string) ($_POST['description'] ?? ''));
            $descriptionNp = trim((string) ($_POST['description_np'] ?? ''));
            $category = clean_text($_POST['category'] ?? 'other', 50);
            if (!isset(irCategories()[$category])) {
                $category = 'other';
            }
            $referenceNo = clean_text($_POST['reference_no'] ?? '', 100);
            $meetingDate = trim((string) ($_POST['meeting_date'] ?? ''));
            if ($meetingDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate)) {
                $meetingDate = '';
            }
            $displayOrder = (int) ($_POST['display_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            /* Vault default: view-only + copy deterrence always on for members */
            $allowDownload = isset($_POST['allow_download']) ? 1 : 0;
            $restrictCopy = 1;
            $filePath = '';
            $fileType = '';
            $oldFilePath = '';

            if ($action === 'edit') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id < 1) {
                    setFlash('error', $__t('अमान्य ID।', 'Invalid ID.'));
                    redirect('information-room.php');
                }
                $existing = irFetchItem($db, $id, false);
                if (!$existing) {
                    setFlash('error', $__t('कागजात भेटिएन।', 'Document not found.'));
                    redirect('information-room.php');
                }
                $filePath = irNormalizeStoredPath((string) ($existing['file_path'] ?? ''));
                $fileType = clean_text((string) ($existing['file_type'] ?? ''), 50);
                $oldFilePath = $filePath;
            }

            if (isset($_FILES['file']) && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $upExt = strtolower(pathinfo((string) ($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));
                if (!in_array($upExt, irAllowedUploadExtensions(), true)) {
                    setFlash('error', $__t('PDF वा तस्बिर (JPG/PNG/WebP) मात्र अपलोड गर्नुहोस्।', 'Upload PDF or image (JPG/PNG/WebP) only.'));
                    redirect('information-room.php');
                }
                $upload = uploadFile($_FILES['file'], 'information_room');
                if (!empty($upload['success'])) {
                    $newPath = irNormalizeStoredPath((string) $upload['path']);
                    if ($newPath === '') {
                        setFlash('error', $__t('फाइल पथ अमान्य।', 'Invalid file path.'));
                        redirect('information-room.php');
                    }
                    $filePath = $newPath;
                    $fileType = $upExt;
                } else {
                    setFlash('error', (string) ($upload['message'] ?? $__t('फाइल अपलोड असफल।', 'File upload failed.')));
                    redirect('information-room.php');
                }
            }

            if ($title === '' && $titleNp !== '') {
                $title = $titleNp;
            }
            if ($title === '') {
                setFlash('error', $__t('शीर्षक अनिवार्य छ।', 'Title is required.'));
                redirect('information-room.php');
            }
            if ($filePath === '') {
                setFlash('error', $__t('कागजात फाइल अनिवार्य छ।', 'Document file is required.'));
                redirect('information-room.php');
            }

            $adminId = (int) ($_SESSION['admin_id'] ?? 0);

            if ($action === 'add') {
                $db->prepare(
                    'INSERT INTO information_room_items
                     (title, title_np, description, description_np, category, file_path, file_type,
                      meeting_date, reference_no, allow_download, restrict_copy, display_order, is_active, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $title, $titleNp, $description, $descriptionNp, $category, $filePath, $fileType,
                    $meetingDate !== '' ? $meetingDate : null,
                    $referenceNo !== '' ? $referenceNo : null,
                    $allowDownload, $restrictCopy, $displayOrder, $isActive, $adminId > 0 ? $adminId : null,
                ]);
                $newId = (int) $db->lastInsertId();
                if (function_exists('writeAuditLog')) {
                    writeAuditLog('ir_item_create', 'Information Room: ' . mb_substr($title, 0, 80), 'information_room', $newId);
                }
                setFlash('success', $__t('कागजात थपियो।', 'Document added.'));
            } else {
                $db->prepare(
                    'UPDATE information_room_items SET
                     title=?, title_np=?, description=?, description_np=?, category=?, file_path=?, file_type=?,
                     meeting_date=?, reference_no=?, allow_download=?, restrict_copy=?, display_order=?, is_active=?
                     WHERE id=?'
                )->execute([
                    $title, $titleNp, $description, $descriptionNp, $category, $filePath, $fileType,
                    $meetingDate !== '' ? $meetingDate : null,
                    $referenceNo !== '' ? $referenceNo : null,
                    $allowDownload, $restrictCopy, $displayOrder, $isActive, $id,
                ]);
                if ($oldFilePath !== '' && $oldFilePath !== $filePath) {
                    irDeleteStoredFile($oldFilePath);
                }
                if (function_exists('writeAuditLog')) {
                    writeAuditLog('ir_item_update', 'Information Room updated: ' . mb_substr($title, 0, 80), 'information_room', $id);
                }
                setFlash('success', $__t('कागजात अपडेट भयो।', 'Document updated.'));
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $row = irFetchItem($db, $id, false);
                $db->prepare('DELETE FROM information_room_access_log WHERE item_id=?')->execute([$id]);
                $db->prepare('DELETE FROM information_room_items WHERE id=?')->execute([$id]);
                if ($row && !empty($row['file_path'])) {
                    irDeleteStoredFile((string) $row['file_path']);
                }
                if (function_exists('writeAuditLog')) {
                    writeAuditLog('ir_item_delete', 'Information Room deleted ID ' . $id, 'information_room', $id);
                }
                setFlash('success', $__t('कागजात मेटाइयो।', 'Document deleted.'));
            }
        } elseif ($action === 'bulk_status') {
            $bulk = clean_text($_POST['bulk'] ?? '', 20);
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['selected_ids'] ?? [])), static fn ($v) => $v > 0));
            if ($ids && in_array($bulk, ['active', 'inactive'], true)) {
                $target = $bulk === 'active' ? 1 : 0;
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("UPDATE information_room_items SET is_active = ? WHERE id IN ($ph)")
                    ->execute(array_merge([$target], $ids));
                setFlash('success', $__t('Bulk status update सफल।', 'Bulk status updated.'));
            } else {
                setFlash('error', $__t('Rows छान्नुहोस्।', 'Select rows.'));
            }
        }
    } catch (Throwable $e) {
        error_log('[admin/information-room] ' . $e->getMessage());
        setFlash('error', $__t('त्रुटि भयो।', 'An error occurred.'));
    }
    redirect('information-room.php');
}

try {
    $items = $db->query('SELECT * FROM information_room_items ORDER BY display_order ASC, meeting_date DESC, id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $items = [];
}

$part = adminPartitionRowsByIsActive($items);
$itemsLive = $part['live'];
$itemsArch = $part['archived'];
$flash = getFlash();
$cats = irCategories();
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    foreach ($items as $row) {
        if ((int) $row['id'] === $editId) {
            $editRow = $row;
            break;
        }
    }
}
?>

<?php echo adminPageHeader(
    $__t('Information Room', 'Information Room'),
    'fa-vault',
    $__t('बोर्ड निर्णय, नीति, कार्यविधि, बिनियम — admin मात्र अपडेट; authorized सदस्य हेर्न सक्छन्।', 'Board decisions, policies, procedures, bylaws — admin-only updates; authorized members can view.'),
    '<a href="information-room-browse.php" class="btn btn-sm btn-outline-primary me-2"><i class="fas fa-eye me-1"></i>'
    . $__t('हेर्नुहोस् (Reader)', 'Browse (Reader)') . '</a>'
    . '<a href="information-room-logs.php" class="btn btn-sm btn-outline-secondary me-2"><i class="fas fa-clipboard-list me-1"></i>'
    . $__t('पहुँच लग', 'Access log') . '</a>'
    . '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2">'
    . $__t('जम्मा', 'Total') . ': ' . count($items) . '</span>'
); ?>

<?php echo adminHelpTip(
    $__t(
        'Information Room मा संवेदनशील कागजात राख्नुहोस्। पूर्वनिर्धारित: हेर्न मात्र + copy/screenshot रोक। वेबमा १००% रोक सम्भव छैन।',
        'Store sensitive documents here. Default: view-only + copy/screenshot deterrence. 100% prevention is not possible on the web.'
    ),
    [
        $__t('Member पहुँच: Members → विवरण → Information Room Enable', 'Member access: Members → detail → Information Room Enable'),
        $__t('PDF / तस्बिर मात्र — inline preview + vault सुरक्षा', 'PDF / images only — inline preview + vault safety'),
        $__t('डाउनलोड सामान्यतया बन्द राख्नुहोस्', 'Keep download off unless necessary'),
    ]
); ?>

<?php if (!empty($flash)) {
    echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']);
} ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button type="button" class="nav-link <?php echo $editRow ? '' : 'active'; ?>" data-bs-toggle="tab" data-bs-target="#ir-list"><?php echo $__t('सूची', 'List'); ?></button>
    </li>
    <li class="nav-item">
        <button type="button" class="nav-link <?php echo $editRow ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#ir-form" id="ir-form-tab-btn">
            <?php echo $editRow ? $__t('सम्पादन', 'Edit') : $__t('नयाँ थप्नुहोस्', 'Add New'); ?>
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade <?php echo $editRow ? '' : 'show active'; ?>" id="ir-list">
        <div class="card admin-table-card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">
            <div class="card-body p-0">
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="bulk_status">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 admin-data-table dt-no-responsive">
                            <thead>
                                <tr>
                                    <th style="width:36px"><input type="checkbox" class="form-check-input js-select-all"></th>
                                    <th><?php echo $__t('शीर्षक', 'Title'); ?></th>
                                    <th><?php echo $__t('श्रेणी', 'Category'); ?></th>
                                    <th><?php echo $__t('मिति', 'Date'); ?></th>
                                    <th><?php echo $__t('पहुँच', 'Access'); ?></th>
                                    <th><?php echo $__t('स्थिति', 'Status'); ?></th>
                                    <th class="text-end"><?php echo $__t('कार्य', 'Actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4"><?php echo $__t('कुनै कागजात छैन।', 'No documents yet.'); ?></td></tr>
                            <?php else: foreach ($items as $row): ?>
                                <tr>
                                    <td><input type="checkbox" class="form-check-input" name="selected_ids[]" value="<?php echo (int) $row['id']; ?>"></td>
                                    <td>
                                        <a href="information-room-view.php?id=<?php echo (int) $row['id']; ?>" class="fw-semibold text-decoration-none">
                                            <?php echo htmlspecialchars($row['title_np'] ?: $row['title'], ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                        <?php if (!empty($row['reference_no'])): ?>
                                        <div><small class="text-muted"><?php echo htmlspecialchars($row['reference_no'], ENT_QUOTES, 'UTF-8'); ?></small></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(irCategoryLabel((string) $row['category'], strtolower((string)($_SESSION['admin_lang'] ?? 'np')) === 'en'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($row['meeting_date'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php if (!empty($row['allow_download'])): ?>
                                        <span class="badge bg-success-subtle text-success"><?php echo $__t('डाउनलोड OK', 'Download OK'); ?></span>
                                        <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis"><?php echo $__t('हेर्न मात्र', 'View only'); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($row['restrict_copy'])): ?>
                                        <span class="badge bg-secondary-subtle text-secondary"><?php echo $__t('सुरक्षित', 'Protected'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo !empty($row['is_active']) ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo !empty($row['is_active']) ? $__t('सक्रिय', 'Active') : $__t('अभिलेख', 'Archived'); ?>
                                        </span>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <div class="adm-action-icons">
                                            <a href="information-room-view.php?id=<?php echo (int) $row['id']; ?>"
                                               class="adm-icon-btn adm-icon-btn--view"
                                               title="<?php echo $__t('हेर्नुहोस्', 'View'); ?>"
                                               aria-label="<?php echo $__t('हेर्नुहोस्', 'View'); ?>">
                                                <i class="fas fa-eye" aria-hidden="true"></i>
                                            </a>
                                            <a href="information-room.php?edit=<?php echo (int) $row['id']; ?>#ir-form"
                                               class="adm-icon-btn adm-icon-btn--edit"
                                               title="<?php echo $__t('सम्पादन', 'Edit'); ?>"
                                               aria-label="<?php echo $__t('सम्पादन', 'Edit'); ?>">
                                                <i class="fas fa-pen" aria-hidden="true"></i>
                                            </a>
                                            <button type="submit"
                                                    form="ir-delete-<?php echo (int) $row['id']; ?>"
                                                    class="adm-icon-btn adm-icon-btn--delete"
                                                    title="<?php echo $__t('मेटाउनुहोस्', 'Delete'); ?>"
                                                    aria-label="<?php echo $__t('मेटाउनुहोस्', 'Delete'); ?>">
                                                <i class="fas fa-trash" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 border-top d-flex gap-2 flex-wrap">
                        <select name="bulk" class="form-select form-select-sm" style="max-width:160px">
                            <option value="active"><?php echo $__t('सक्रिय', 'Active'); ?></option>
                            <option value="inactive"><?php echo $__t('अभिलेख', 'Archive'); ?></option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary"><?php echo $__t('Bulk Update', 'Bulk Update'); ?></button>
                    </div>
                </form>
                <?php if (!empty($items)): foreach ($items as $row): ?>
                <form method="POST" id="ir-delete-<?php echo (int) $row['id']; ?>" class="d-none" onsubmit="return confirm('<?php echo $__t('मेटाउने?', 'Delete?'); ?>');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                </form>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="tab-pane fade <?php echo $editRow ? 'show active' : ''; ?>" id="ir-form">
        <div class="card admin-table-card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="<?php echo $editRow ? 'edit' : 'add'; ?>">
                    <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?php echo (int) $editRow['id']; ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo $__t('शीर्षक (नेपाली)', 'Title (Nepali)'); ?></label>
                            <input type="text" name="title_np" class="form-control" maxlength="255" value="<?php echo htmlspecialchars((string) ($editRow['title_np'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo $__t('शीर्षक (English)', 'Title (English)'); ?></label>
                            <input type="text" name="title" class="form-control" maxlength="255" value="<?php echo htmlspecialchars((string) ($editRow['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="form-text"><?php echo $__t('नेपाली वा English मध्ये कम्तीमा एक अनिवार्य।', 'At least one of Nepali or English title is required.'); ?></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo $__t('श्रेणी', 'Category'); ?></label>
                            <select name="category" class="form-select">
                                <?php foreach ($cats as $k => $c): ?>
                                <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (($editRow['category'] ?? 'other') === $k) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['np'] . ' / ' . $c['en'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo $__t('बैठक/निर्णय मिति', 'Meeting/Decision Date'); ?></label>
                            <input type="date" name="meeting_date" class="form-control" value="<?php echo htmlspecialchars((string) ($editRow['meeting_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo $__t('सन्दर्भ नं.', 'Reference No.'); ?></label>
                            <input type="text" name="reference_no" class="form-control" maxlength="100" value="<?php echo htmlspecialchars((string) ($editRow['reference_no'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo $__t('विवरण (नेपाली)', 'Description (Nepali)'); ?></label>
                            <textarea name="description_np" class="form-control" rows="3"><?php echo htmlspecialchars((string) ($editRow['description_np'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo $__t('विवरण (English)', 'Description (English)'); ?></label>
                            <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars((string) ($editRow['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo $__t('कागजात फाइल', 'Document file'); ?> <?php echo $editRow ? '' : '*'; ?></label>
                            <input type="file" name="file" class="form-control" accept=".pdf,image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" <?php echo $editRow ? '' : 'required'; ?>>
                            <div class="form-text"><?php echo $__t('PDF वा JPG/PNG/WebP मात्र (preview का लागि)।', 'PDF or JPG/PNG/WebP only (for preview).'); ?></div>
                            <?php if ($editRow && !empty($editRow['file_path'])): ?>
                            <div class="form-text"><?php echo $__t('हाल:', 'Current:'); ?> <?php echo htmlspecialchars(basename((string) $editRow['file_path']), ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><?php echo $__t('क्रम', 'Order'); ?></label>
                            <input type="number" name="display_order" class="form-control" value="<?php echo (int) ($editRow['display_order'] ?? 0); ?>">
                        </div>
                        <div class="col-md-3 d-flex flex-column justify-content-end gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="ir_active" <?php echo (!$editRow || !empty($editRow['is_active'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="ir_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="allow_download" id="ir_dl" <?php echo !empty($editRow['allow_download']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="ir_dl"><?php echo $__t('डाउनलोड अनुमति (सावधानी)', 'Allow download (caution)'); ?></label>
                            </div>
                            <div class="small text-muted"><?php echo $__t('Copy/screenshot रोक सधैं सक्रिय रहन्छ।', 'Copy/screenshot deterrence stays always on.'); ?></div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-success"><?php echo $editRow ? $__t('अपडेट', 'Update') : $__t('थप्नुहोस्', 'Add'); ?></button>
                        <a href="information-room.php" class="btn btn-outline-secondary"><?php echo $__t('रद्द', 'Cancel'); ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.js-select-all').forEach(function (el) {
    el.addEventListener('change', function () {
        document.querySelectorAll('input[name="selected_ids[]"]').forEach(function (cb) { cb.checked = el.checked; });
    });
});
<?php if ($editRow): ?>
document.getElementById('ir-form-tab-btn')?.click();
<?php endif; ?>
</script>

<?php require_once 'includes/admin-footer.php'; ?>
