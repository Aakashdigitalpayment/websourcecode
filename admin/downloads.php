<?php
/**
 * डाउनलोड व्यवस्थापन — Downloads Management
 * Tab UI: सूची + Add/Edit form (modal popup हटाइएको)
 */
$pageTitle = 'डाउनलोड व्यवस्थापन';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add' || $action === 'edit') {
            $id        = $_POST['id'] ?? null;
            $title     = clean_text($_POST['title']    ?? '');
            $title_np  = clean_text($_POST['title_np'] ?? '');
            $category  = clean_text($_POST['category'] ?? 'general');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $file_path = $_POST['existing_file'] ?? '';
            $file_type = '';

            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['file'], 'downloads');
                if ($upload['success']) {
                    $file_path = $upload['path'];
                    $file_type = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                }
            }

            if ($action === 'add') {
                $db->prepare("INSERT INTO downloads (title, title_np, category, file_path, file_type, is_active) VALUES (?,?,?,?,?,?)")
                   ->execute([$title, $title_np, $category, $file_path, $file_type, $is_active]);
                setFlash('success', 'फाइल थपियो।');
            } else {
                $db->prepare("UPDATE downloads SET title=?, title_np=?, category=?, file_path=?, is_active=? WHERE id=?")
                   ->execute([$title, $title_np, $category, $file_path, $is_active, $id]);
                setFlash('success', 'फाइल अपडेट भयो।');
            }
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM downloads WHERE id=?")->execute([$_POST['id']]);
            setFlash('success', 'फाइल मेटाइयो।');
        } elseif ($action === 'bulk_status') {
            $bulk = clean_text($_POST['bulk'] ?? '');
            $selected = $_POST['selected_ids'] ?? [];
            $ids = array_values(array_filter(array_map('intval', (array)$selected), fn($v) => $v > 0));
            if (empty($ids) || !in_array($bulk, ['active','inactive'], true)) {
                setFlash('error', 'Bulk update का लागि rows छान्नुहोस्।');
            } else {
                $target = $bulk === 'active' ? 1 : 0;
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $db->prepare("UPDATE downloads SET is_active = ? WHERE id IN ($ph)");
                $st->execute(array_merge([$target], $ids));
                setFlash('success', 'Bulk status update सफल भयो।');
            }
        }
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो।');
    }
    redirect('downloads.php');
}

try { $downloads = $db->query("SELECT * FROM downloads ORDER BY created_at DESC LIMIT 500")->fetchAll(); }
catch (Exception $e) { $downloads = []; }

$dlPart = adminPartitionRowsByIsActive($downloads);
$downloadsLive = $dlPart['live'];
$downloadsArch = $dlPart['archived'];

$catIcons = ['forms'=>'fas fa-file-alt','policies'=>'fas fa-shield-alt','circulars'=>'fas fa-bullhorn','general'=>'fas fa-file'];
$flash = getFlash();
?>

<?php echo adminPageHeader(
    'डाउनलोड व्यवस्थापन',
    'fa-file-arrow-down',
    'Forms, PDFs, र अन्य डाउनलोड सामग्रीहरू।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($downloads) . ' फाइल</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="check-circle"></i>सक्रिय: ' . count($downloadsLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="archive"></i>अभिलेख: ' . count($downloadsArch) . '</span>'
);
?>
<?php echo adminHelpTip('यो पृष्ठबाट डाउनलोड गर्न मिल्ने फाइलहरू (forms, reports, etc.) थप्न र हटाउन सकिन्छ।', ['PDF/Word file थप्न: "+" बटन थिच्नुहोस्।', 'File size: 5MB भन्दा कम राख्नुहोस्।', 'Category छनोट: सही category मा राख्नुहोस् ताकि visitors सजिलै भेट्टाउन सकून्।']); ?>

<?php if (!empty($flash)) { echo adminAlert($flash['type']==='success'?'success':'danger', $flash['message']); } ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#dl-list" id="dl-list-btn" title="सक्रिय / जम्मा">
            <i class="fas fa-list me-2"></i>डाउनलोड सूची
            <span class="badge bg-success ms-1"><?php echo count($downloadsLive); ?> / <?php echo count($downloads); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#dl-form" id="dl-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="dlFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="dl-list">
        <div class="card admin-table-card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3" style="flex-wrap:wrap">
                <div class="input-group input-group-sm" style="max-width:300px">
                    <span class="input-group-text bg-white border-end-0"><i class="lucide-icon text-muted" aria-hidden="true" data-lucide="search"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="नाम, विवरण अनुसार खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
                    <form method="POST">
                    <?php echo csrfField(); ?>
                    <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-end gap-2">
                        <input type="hidden" name="action" value="bulk_status">
                        <button type="submit" name="bulk" value="active" class="btn btn-sm btn-outline-success">Bulk Active</button>
                        <button type="submit" name="bulk" value="inactive" class="btn btn-sm btn-outline-secondary">Bulk Inactive</button>
                    </div>
                    <?php echo adminListSubtabPills('dl-sub', count($downloadsLive), count($downloadsArch)); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="dl-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('#dl-sub-live .dl-select').forEach(c=>c.checked=this.checked)"></th>
                                <th class="ps-3">शीर्षक</th>
                                <th width="120" class="text-center">वर्ग</th>
                                <th width="90" class="text-center">फाइल</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($downloads)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-download fa-3x mb-2 d-block opacity-25"></i>
                                कुनै डाउनलोड छैन।
                            </td></tr>
                            <?php elseif (empty($downloadsLive)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="lucide-icon fa-3x mb-2 d-block opacity-25 text-success" aria-hidden="true" data-lucide="check-circle"></i>
                                सक्रिय फाइल छैन। अभिलेख हेर्नुहोस्।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($downloadsLive as $d): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="dl-select" name="selected_ids[]" value="<?php echo (int)$d['id']; ?>"></td>
                                <td class="ps-3">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($d['title_np'] ?: $d['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($d['title']); ?></small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info text-white">
                                        <i class="<?php echo $catIcons[$d['category']] ?? 'fas fa-file'; ?> me-1"></i>
                                        <?php echo ucfirst($d['category']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($d['file_path'])): ?>
                                    <a href="../<?php echo htmlspecialchars($d['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-success" title="हेर्नुहोस्">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td class="text-center"><span class="badge bg-<?php echo $d['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $d['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-dl"
                                            data-id="<?php echo $d['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($d['title'], ENT_QUOTES); ?>"
                                            data-title-np="<?php echo htmlspecialchars($d['title_np'] ?? '', ENT_QUOTES); ?>"
                                            data-category="<?php echo htmlspecialchars($d['category'], ENT_QUOTES); ?>"
                                            data-file="<?php echo htmlspecialchars($d['file_path'] ?? '', ENT_QUOTES); ?>"
                                            data-active="<?php echo $d['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('के तपाईं यो फाइल मेटाउन निश्चित हुनुहुन्छ?')">
    <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="dl-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('#dl-sub-arch .dl-select').forEach(c=>c.checked=this.checked)"></th>
                                <th class="ps-3">शीर्षक</th>
                                <th width="120" class="text-center">वर्ग</th>
                                <th width="90" class="text-center">फाइल</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($downloadsArch)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="lucide-icon fa-3x mb-2 d-block opacity-25" aria-hidden="true" data-lucide="folder-open"></i>
                                अभिलेखमा कुनै फाइल छैन।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($downloadsArch as $d): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="dl-select" name="selected_ids[]" value="<?php echo (int)$d['id']; ?>"></td>
                                <td class="ps-3">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($d['title_np'] ?: $d['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($d['title']); ?></small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-info text-white">
                                        <i class="<?php echo $catIcons[$d['category']] ?? 'fas fa-file'; ?> me-1"></i>
                                        <?php echo ucfirst($d['category']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($d['file_path'])): ?>
                                    <a href="../<?php echo htmlspecialchars($d['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-success" title="हेर्नुहोस्">
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td class="text-center"><span class="badge bg-<?php echo $d['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $d['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-dl"
                                            data-id="<?php echo $d['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($d['title'], ENT_QUOTES); ?>"
                                            data-title-np="<?php echo htmlspecialchars($d['title_np'] ?? '', ENT_QUOTES); ?>"
                                            data-category="<?php echo htmlspecialchars($d['category'], ENT_QUOTES); ?>"
                                            data-file="<?php echo htmlspecialchars($d['file_path'] ?? '', ENT_QUOTES); ?>"
                                            data-active="<?php echo $d['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('के तपाईं यो फाइल मेटाउन निश्चित हुनुहुन्छ?')">
    <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
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
                    </form>
            </div>
        </div>
    </div>

    <!-- ══ TAB 2: Add / Edit Form ══ -->
    <div class="tab-pane fade" id="dl-form">
        <div class="card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:linear-gradient(135deg,var(--primary-color),var(--primary-light));color:#fff;">
                <h5 class="mb-0 fw-bold" id="dlFormTitle">
                    <i class="fas fa-plus-circle me-2"></i>नयाँ फाइल थप्नुहोस्
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelDl">
                    <i class="lucide-icon me-1" aria-hidden="true" data-lucide="arrow-left"></i>सूचीमा फर्कनुहोस्
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" id="dlForm" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" id="dlf_action" value="add">
                    <input type="hidden" name="id" id="dlf_id" value="">
                    <input type="hidden" name="existing_file" id="dlf_file" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">शीर्षक (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="title_np" id="dlf_title_np" class="form-control admin-fancy-input" required placeholder="फाइलको शीर्षक नेपालीमा">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Title (English)</label>
                            <input type="text" name="title" id="dlf_title" class="form-control admin-fancy-input" placeholder="File title in English">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">वर्ग</label>
                            <select name="category" id="dlf_category" class="form-select admin-fancy-input">
                                <option value="forms">Forms</option>
                                <option value="policies">Policies</option>
                                <option value="circulars">Circulars</option>
                                <option value="general">General</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">फाइल अपलोड
                                <small class="text-muted fw-normal" id="dlf_file_note"></small>
                            </label>
                            <input type="file" name="file" class="form-control admin-fancy-input" id="dlf_file_input">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="dlf_active" checked>
                                <label class="form-check-label fw-semibold" for="dlf_active">सक्रिय</label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="dlf_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                        </button>
                        <button type="button" id="dlf_cancel2" class="btn btn-outline-secondary px-4">
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

    var listBtn = document.getElementById('dl-list-btn');
    var formBtn = document.getElementById('dl-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('dlf_action').value   = 'add';
        document.getElementById('dlf_id').value       = '';
        document.getElementById('dlf_file').value     = '';
        document.getElementById('dlf_title').value    = '';
        document.getElementById('dlf_title_np').value = '';
        document.getElementById('dlf_active').checked = true;
        document.getElementById('dlf_category').selectedIndex = 3;
        document.getElementById('dlf_file_note').textContent  = '';
        document.getElementById('dlf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('dlFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ फाइल थप्नुहोस्';
        document.getElementById('dlFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
        try { document.getElementById('dlf_file_input').value = ''; } catch(e) {}
    }

    document.getElementById('btnAddDl')?.addEventListener('click', function() { clearForm(); switchToForm(); });

    ['btnCancelDl','dlf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    document.querySelectorAll('.btn-edit-dl').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('dlf_action').value   = 'edit';
            document.getElementById('dlf_id').value       = d.id;
            document.getElementById('dlf_title').value    = d.title;
            document.getElementById('dlf_title_np').value = d.titleNp || '';
            document.getElementById('dlf_file').value     = d.file || '';
            document.getElementById('dlf_active').checked = d.active === '1';
            document.getElementById('dlf_file_note').textContent = d.file
                ? ' — नयाँ फाइल नचुने भने पुरानै रहन्छ'
                : '';
            var sel = document.getElementById('dlf_category');
            for (var i=0; i<sel.options.length; i++) {
                if (sel.options[i].value === d.category) { sel.selectedIndex = i; break; }
            }
            document.getElementById('dlf_submit').innerHTML = '<i class="lucide-icon me-2" aria-hidden="true" data-lucide="save"></i>अपडेट गर्नुहोस्';
            document.getElementById('dlFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>डाउनलोड सम्पादन';
            document.getElementById('dlFormTabLabel').textContent = 'सम्पादन';
            switchToForm();
        });
    });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
