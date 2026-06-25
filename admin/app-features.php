<?php
/**
 * एप सुविधाहरू व्यवस्थापन — App Features Management
 * Tab UI: सूची + Add/Edit form (modal popup हटाइएको)
 */
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('एप सुविधाहरू', 'App Features');
require_once '../includes/config.php';
if (!isAdminLoggedIn()) redirect(ADMIN_URL . 'index.php');

$db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add' || $action === 'edit') {
            $id       = $_POST['id'] ?? null;
            $title    = clean_text($_POST['title']    ?? '');
            $title_np = clean_text($_POST['title_np'] ?? $title);
            $icon     = clean_text($_POST['icon']     ?? 'fas fa-star');
            $desc     = $_POST['description']       ?? '';
            $desc_np  = $_POST['description_np']   ?? '';
            $is_new   = isset($_POST['is_new'])    ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $order    = (int)($_POST['sort_order'] ?? 0);

            if ($action === 'add') {
                $db->prepare("INSERT INTO app_features (title, title_np, icon, description, description_np, is_new, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$title, $title_np, $icon, $desc, $desc_np, $is_new, $is_active, $order]);
                setFlash('success', 'सुविधा थपियो।');
            } else {
                $db->prepare("UPDATE app_features SET title=?, title_np=?, icon=?, description=?, description_np=?, is_new=?, is_active=?, sort_order=? WHERE id=?")
                   ->execute([$title, $title_np, $icon, $desc, $desc_np, $is_new, $is_active, $order, $id]);
                setFlash('success', 'सुविधा अपडेट भयो।');
            }
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM app_features WHERE id=?")->execute([$_POST['id']]);
            setFlash('success', 'सुविधा हटाइयो।');
        } elseif ($action === 'toggle_new') {
            $db->prepare('UPDATE app_features SET is_new = NOT is_new WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            setFlash('success', 'New badge परिवर्तन भयो।');
        }
    } catch (Exception $e) { setFlash('error', 'त्रुटि भयो।'); }
    redirect('app-features.php');
}

try { $features = $db->query("SELECT * FROM app_features ORDER BY sort_order, id LIMIT 500")->fetchAll(); }
catch (Exception $e) { $features = []; }

$flash = getFlash();
?>

<?php echo adminPageHeader(
    $__t('एप सुविधाहरू', 'App Features'),
    'fa-mobile-alt',
    $__t('मोबाइल एपमा देखिने सुविधाहरू।', 'Features shown in mobile app.'),
    '<span class="badge admin-stat-badge appfeat-stat-pill me-2"><i class="fas fa-layer-group me-1"></i>' . $__t('जम्मा', 'Total') . ': ' . count($features) . ' ' . $__t('सुविधाहरू', 'features') . '</span>'
); ?>

<?php if (!empty($flash)) { echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']); } ?>


<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#feat-list" id="feat-list-btn">
            <i class="fas fa-list me-2"></i><?php echo $__t('सुविधा सूची', 'Feature List'); ?>
            <span class="badge appfeat-count-badge ms-1"><?php echo count($features); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#feat-form" id="feat-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="featFormTabLabel"><?php echo $__t('नयाँ थप्नुहोस्', 'Add New'); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="feat-list">
        <div class="card admin-table-card appfeat-flat-top">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" width="60"><?php echo $__t('आइकन', 'Icon'); ?></th>
                                <th><?php echo $__t('शीर्षक', 'Title'); ?></th>
                                <th><?php echo $__t('विवरण', 'Description'); ?></th>
                                <th width="80" class="text-center"><?php echo $__t('क्रम', 'Order'); ?></th>
                                <th width="80" class="text-center"><?php echo $__t('नयाँ', 'New'); ?></th>
                                <th width="90" class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                                <th width="140" class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($features)): ?>
                            <tr><td colspan="7" class="text-center py-5 appfeat-muted">
                                <i class="fas fa-mobile-alt fa-3x mb-2 d-block opacity-25"></i>
                                <?php echo $__t('कुनै सुविधा छैन।', 'No features found.'); ?>
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($features as $f): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="appfeat-icon-wrap">
                                        <i class="<?php echo htmlspecialchars($f['icon']); ?> appfeat-icon fa-lg"></i>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($f['title_np'] ?: $f['title']); ?></div>
                                    <small class="appfeat-muted"><?php echo htmlspecialchars($f['title']); ?></small>
                                </td>
                                <td><small class="appfeat-muted"><?php echo htmlspecialchars(mb_substr($f['description_np'] ?: ($f['description'] ?: ''), 0, 70)); ?>…</small></td>
                                <td class="text-center"><span class="badge appfeat-order-badge"><?php echo $f['sort_order']; ?></span></td>
                                <td class="text-center">
                                    <form method="POST" class="appfeat-inline-form">
    <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle_new">
                                        <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                        <button class="badge border-0 appfeat-toggle-badge <?php echo $f['is_new'] ? 'appfeat-toggle-on' : 'appfeat-toggle-off'; ?>" title="<?php echo $__t('टगल गर्नुहोस्', 'Toggle'); ?>">
                                            <?php echo $f['is_new'] ? ('✓ ' . $__t('नयाँ', 'NEW')) : $__t('छैन', 'No'); ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="text-center"><span class="badge <?php echo $f['is_active'] ? 'appfeat-status-on' : 'appfeat-status-off'; ?>"><?php echo $f['is_active'] ? $__t('सक्रिय', 'Active') : $__t('निष्क्रिय', 'Inactive'); ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm appfeat-btn-edit me-1 btn-edit-feat"
                                            data-id="<?php echo $f['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($f['title'], ENT_QUOTES); ?>"
                                            data-title-np="<?php echo htmlspecialchars($f['title_np'] ?? '', ENT_QUOTES); ?>"
                                            data-icon="<?php echo htmlspecialchars($f['icon'], ENT_QUOTES); ?>"
                                            data-desc="<?php echo htmlspecialchars($f['description'] ?? '', ENT_QUOTES); ?>"
                                            data-desc-np="<?php echo htmlspecialchars($f['description_np'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $f['sort_order']; ?>"
                                            data-is-new="<?php echo $f['is_new']; ?>"
                                            data-active="<?php echo $f['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="appfeat-inline-form" onsubmit="return confirm('<?php echo $__t('के तपाईं यो सुविधा हटाउन निश्चित हुनुहुन्छ?', 'Are you sure you want to delete this feature?'); ?>')">
    <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                        <button class="btn btn-sm appfeat-btn-delete" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
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

    <!-- ══ TAB 2: Add / Edit Form ══ -->
    <div class="tab-pane fade" id="feat-form">
        <div class="card appfeat-flat-top">
            <div class="card-header d-flex justify-content-between align-items-center appfeat-form-header">
                <h5 class="mb-0 fw-bold" id="featFormTitle">
                    <i class="fas fa-plus-circle me-2"></i><?php echo $__t('नयाँ सुविधा थप्नुहोस्', 'Add New Feature'); ?>
                </h5>
                <button type="button" class="btn btn-sm appfeat-cancel-btn" id="btnCancelFeat">
                    <i class="fas fa-arrow-left me-1"></i><?php echo $__t('सूचीमा फर्कनुहोस्', 'Back to List'); ?>
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="featForm" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" id="fef_action" value="add">
                    <input type="hidden" name="id" id="fef_id" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold appfeat-label"><?php echo $__t('शीर्षक (नेपाली)', 'Title (Nepali)'); ?> <span class="appfeat-required">*</span></label>
                            <input type="text" name="title_np" id="fef_title_np" class="form-control admin-fancy-input" required placeholder="<?php echo $__t('सुविधाको नाम नेपालीमा', 'Feature name in Nepali'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold appfeat-label">Title (English)</label>
                            <input type="text" name="title" id="fef_title" class="form-control admin-fancy-input" placeholder="Feature name in English">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold appfeat-label"><?php echo $__t('Font Awesome आइकन', 'Font Awesome Icon'); ?></label>
                            <div class="input-group">
                                <span class="input-group-text appfeat-icon-prev" id="fefIconPrev"><i class="fas fa-star"></i></span>
                                <input type="text" name="icon" id="fef_icon" class="form-control admin-fancy-input"
                                       value="fas fa-star" placeholder="fas fa-star"
                                       oninput="document.getElementById('fefIconPrev').innerHTML='<i class=\''+this.value+'\'></i>'">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold appfeat-label"><?php echo $__t('विवरण (नेपाली)', 'Description (Nepali)'); ?></label>
                            <textarea name="description_np" id="fef_desc_np" class="form-control admin-fancy-input" rows="3" placeholder="<?php echo $__t('सुविधाको विवरण नेपालीमा...', 'Feature description in Nepali...'); ?>"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold appfeat-label">Description (English)</label>
                            <textarea name="description" id="fef_desc" class="form-control admin-fancy-input" rows="3" placeholder="Feature description in English..."></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold appfeat-label"><?php echo $__t('क्रम', 'Order'); ?></label>
                            <input type="number" name="sort_order" id="fef_order" class="form-control admin-fancy-input" value="0" min="0">
                        </div>
                        <div class="col-md-4 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_new" id="fef_is_new">
                                <label class="form-check-label fw-semibold" for="fef_is_new"><?php echo $__t('New Badge देखाउने', 'Show New Badge'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="fef_active" checked>
                                <label class="form-check-label fw-semibold" for="fef_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="fef_submit" class="btn appfeat-submit px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i><?php echo $__t('थप्नुहोस्', 'Add'); ?>
                        </button>
                        <button type="button" id="fef_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i><?php echo $__t('रद्द', 'Cancel'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    var listBtn = document.getElementById('feat-list-btn');
    var formBtn = document.getElementById('feat-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('fef_action').value   = 'add';
        document.getElementById('fef_id').value       = '';
        document.getElementById('fef_title').value    = '';
        document.getElementById('fef_title_np').value = '';
        document.getElementById('fef_icon').value     = 'fas fa-star';
        document.getElementById('fef_desc').value     = '';
        document.getElementById('fef_desc_np').value  = '';
        document.getElementById('fef_order').value    = '0';
        document.getElementById('fef_is_new').checked = false;
        document.getElementById('fef_active').checked = true;
        document.getElementById('fefIconPrev').innerHTML = '<i class="fas fa-star"></i>';
        document.getElementById('fef_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i><?php echo $__t('थप्नुहोस्', 'Add'); ?>';
        document.getElementById('featFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i><?php echo $__t('नयाँ सुविधा थप्नुहोस्', 'Add New Feature'); ?>';
        document.getElementById('featFormTabLabel').textContent = '<?php echo $__t('नयाँ थप्नुहोस्', 'Add New'); ?>';
    }

    document.getElementById('btnAddFeature')?.addEventListener('click', function() { clearForm(); switchToForm(); });

    ['btnCancelFeat','fef_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    document.querySelectorAll('.btn-edit-feat').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('fef_action').value   = 'edit';
            document.getElementById('fef_id').value       = d.id;
            document.getElementById('fef_title').value    = d.title;
            document.getElementById('fef_title_np').value = d.titleNp || '';
            document.getElementById('fef_icon').value     = d.icon || 'fas fa-star';
            document.getElementById('fef_desc').value     = d.desc || '';
            document.getElementById('fef_desc_np').value  = d.descNp || '';
            document.getElementById('fef_order').value    = d.order || 0;
            document.getElementById('fef_is_new').checked = d.isNew === '1';
            document.getElementById('fef_active').checked = d.active === '1';
            document.getElementById('fefIconPrev').innerHTML = '<i class="' + (d.icon || 'fas fa-star') + '"></i>';
            document.getElementById('fef_submit').innerHTML = '<i class="fas fa-save me-2"></i><?php echo $__t('अपडेट गर्नुहोस्', 'Update'); ?>';
            document.getElementById('featFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i><?php echo $__t('एप सुविधा सम्पादन', 'Edit App Feature'); ?>';
            document.getElementById('featFormTabLabel').textContent = '<?php echo $__t('सम्पादन', 'Edit'); ?>';
            switchToForm();
        });
    });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
