/**
 * सम्मान तथा पुरस्कार व्यवस्थापन — Awards Management
 * Tab UI: सूची + Add/Edit form (modal popup हटाइएको)
 */
$pageTitle = 'सम्मान तथा पुरस्कार व्यवस्थापन';
require_once '../includes/config.php';
if (!isAdminLoggedIn()) redirect(ADMIN_URL . 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        setFlash('error', 'सुरक्षा जाँच असफल।');
        redirect('awards.php');
    }
}
$csrfToken = generateCSRFToken();

$success = '';
$error   = '';
$db      = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $act = $_POST['action'];

        if ($act === 'add' || $act === 'edit') {
            $title         = clean_text($_POST['title']          ?? '');
            $title_np      = clean_text($_POST['title_np']       ?? $title);
            $desc          = clean_text($_POST['description']    ?? '');
            $desc_np       = clean_text($_POST['description_np'] ?? $desc);
            $awarded_by    = clean_text($_POST['awarded_by']     ?? '');
            $awarded_by_np = clean_text($_POST['awarded_by_np']  ?? $awarded_by);
            $award_date    = clean_text($_POST['award_date']     ?? null) ?: null;
            $order         = (int)($_POST['display_order']     ?? 0);
            $is_active     = isset($_POST['is_active']) ? 1 : 0;

            $image = $_POST['existing_image'] ?? '';
            if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $awardsDir = UPLOAD_PATH . 'awards/';
                if (!is_dir($awardsDir)) {
                    mkdir($awardsDir, 0755, true);
                }
                $imgName = uploadImage($_FILES['image'], $awardsDir, 1200, 1200, false);
                if ($imgName) {
                    if ($image !== '') {
                        $rel = ltrim(str_replace('\\', '/', (string) $image), '/');
                        $oldFull = ROOT_PATH . $rel;
                        if (is_file($oldFull)) {
                            @unlink($oldFull);
                        }
                    }
                    $image = 'assets/uploads/awards/' . $imgName;
                }
            }

            if ($act === 'add') {
                $db->prepare("INSERT INTO awards (title, title_np, description, description_np, awarded_by, awarded_by_np, award_date, image, display_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$title, $title_np, $desc, $desc_np, $awarded_by, $awarded_by_np, $award_date, $image, $order, $is_active]);
                $success = 'पुरस्कार सफलतापूर्वक थपियो।';
            } else {
                $db->prepare("UPDATE awards SET title=?, title_np=?, description=?, description_np=?, awarded_by=?, awarded_by_np=?, award_date=?, image=?, display_order=?, is_active=? WHERE id=?")
                   ->execute([$title, $title_np, $desc, $desc_np, $awarded_by, $awarded_by_np, $award_date, $image, $order, $is_active, (int)$_POST['id']]);
                $success = 'पुरस्कार सफलतापूर्वक अपडेट भयो।';
            }
        } elseif ($act === 'delete') {
            $db->prepare("DELETE FROM awards WHERE id=?")->execute([(int)$_POST['id']]);
            $success = 'पुरस्कार मेटाइयो।';
        }
    } catch (Exception $e) {
        $error = 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।';
    }
}

$awards = [];
try {
    $check = $db->query("SHOW TABLES LIKE 'awards'");
    if ($check->fetch() !== false) {
        $awards = $db->query("SELECT * FROM awards ORDER BY display_order, id DESC LIMIT 500")->fetchAll();
    }
} catch (Exception $e) { $awards = []; }

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$awPart = adminPartitionRowsByIsActive($awards);
$awardsLive = $awPart['live'];
$awardsArch = $awPart['archived'];
?>

<?php echo adminPageHeader(
    'सम्मान तथा पुरस्कार',
    'fa-trophy',
    'संस्थाले प्राप्त गरेका पुरस्कार र सम्मानहरू।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($awards) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="check-circle"></i>सक्रिय: ' . count($awardsLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="archive"></i>अभिलेख: ' . count($awardsArch) . '</span>'
);
if ($flash = getFlash()):
?>
<div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3"><i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php echo adminAlert('success', $success) . adminAlert('danger', $error); ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#aw-list" id="aw-list-btn" title="सक्रिय / जम्मा">
            <i class="fas fa-list me-2"></i>पुरस्कार सूची
            <span class="badge bg-success ms-1"><?php echo count($awardsLive); ?> / <?php echo count($awards); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#aw-form" id="aw-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="awFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="aw-list">
        <div class="card admin-table-card svc-flat-top-card">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3 svc-search-wrap">
                <div class="input-group input-group-sm svc-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="lucide-icon text-muted" aria-hidden="true" data-lucide="search"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="नाम, विवरण अनुसार खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
                    <?php echo adminListSubtabPills('aw-sub', count($awardsLive), count($awardsArch)); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="aw-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 coop-table">
                        <thead>
                            <tr>
                                <th class="ps-3" width="70">फोटो</th>
                                <th>पुरस्कारको नाम</th>
                                <th>प्रदान गर्ने</th>
                                <th width="100" class="text-center">मिति</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($awards)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="lucide-icon fa-3x mb-2 d-block opacity-25" aria-hidden="true" data-lucide="trophy"></i>
                                कुनै पुरस्कार छैन।
                            </td></tr>
                            <?php elseif (empty($awardsLive)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="lucide-icon fa-3x mb-2 d-block opacity-25 text-success" aria-hidden="true" data-lucide="check-circle"></i>
                                सक्रिय पुरस्कार छैन। अभिलेख हेर्नुहोस्।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($awardsLive as $a): ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if (!empty($a['image'])): ?>
                                    <img src="../<?php echo htmlspecialchars($a['image']); ?>" class="news-thumb-img">
                                    <?php else: ?>
                                    <div class="news-thumb-placeholder"><i class="lucide-icon text-success fa-lg" aria-hidden="true" data-lucide="trophy"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($a['title_np'] ?: $a['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($a['title']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($a['awarded_by_np'] ?: $a['awarded_by']); ?></td>
                                <td class="text-center"><small><?php echo $a['award_date'] ? date('Y', strtotime($a['award_date'])) : '—'; ?></small></td>
                                <td class="text-center"><span class="badge bg-<?php echo $a['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $a['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-aw"
                                            data-id="<?php echo $a['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($a['title'], ENT_QUOTES); ?>"
                                            data-title-np="<?php echo htmlspecialchars($a['title_np'] ?? '', ENT_QUOTES); ?>"
                                            data-awarded-by="<?php echo htmlspecialchars($a['awarded_by'] ?? '', ENT_QUOTES); ?>"
                                            data-awarded-by-np="<?php echo htmlspecialchars($a['awarded_by_np'] ?? '', ENT_QUOTES); ?>"
                                            data-desc="<?php echo htmlspecialchars($a['description'] ?? '', ENT_QUOTES); ?>"
                                            data-desc-np="<?php echo htmlspecialchars($a['description_np'] ?? '', ENT_QUOTES); ?>"
                                            data-date="<?php echo htmlspecialchars($a['award_date'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $a['display_order']; ?>"
                                            data-active="<?php echo $a['is_active']; ?>"
                                            data-image="<?php echo htmlspecialchars($a['image'] ?? '', ENT_QUOTES); ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('के तपाईं यो पुरस्कार मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="aw-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 coop-table">
                        <thead>
                            <tr>
                                <th class="ps-3" width="70">फोटो</th>
                                <th>पुरस्कारको नाम</th>
                                <th>प्रदान गर्ने</th>
                                <th width="100" class="text-center">मिति</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($awardsArch)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="lucide-icon fa-3x mb-2 d-block opacity-25" aria-hidden="true" data-lucide="folder-open"></i>
                                अभिलेखमा कुनै पुरस्कार छैन।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($awardsArch as $a): ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if (!empty($a['image'])): ?>
                                    <img src="../<?php echo htmlspecialchars($a['image']); ?>" class="news-thumb-img">
                                    <?php else: ?>
                                    <div class="news-thumb-placeholder"><i class="lucide-icon text-success fa-lg" aria-hidden="true" data-lucide="trophy"></i></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($a['title_np'] ?: $a['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($a['title']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($a['awarded_by_np'] ?: $a['awarded_by']); ?></td>
                                <td class="text-center"><small><?php echo $a['award_date'] ? date('Y', strtotime($a['award_date'])) : '—'; ?></small></td>
                                <td class="text-center"><span class="badge bg-<?php echo $a['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $a['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-aw"
                                            data-id="<?php echo $a['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($a['title'], ENT_QUOTES); ?>"
                                            data-title-np="<?php echo htmlspecialchars($a['title_np'] ?? '', ENT_QUOTES); ?>"
                                            data-awarded-by="<?php echo htmlspecialchars($a['awarded_by'] ?? '', ENT_QUOTES); ?>"
                                            data-awarded-by-np="<?php echo htmlspecialchars($a['awarded_by_np'] ?? '', ENT_QUOTES); ?>"
                                            data-desc="<?php echo htmlspecialchars($a['description'] ?? '', ENT_QUOTES); ?>"
                                            data-desc-np="<?php echo htmlspecialchars($a['description_np'] ?? '', ENT_QUOTES); ?>"
                                            data-date="<?php echo htmlspecialchars($a['award_date'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $a['display_order']; ?>"
                                            data-active="<?php echo $a['is_active']; ?>"
                                            data-image="<?php echo htmlspecialchars($a['image'] ?? '', ENT_QUOTES); ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('के तपाईं यो पुरस्कार मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
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
    <div class="tab-pane fade" id="aw-form">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="awFormTitle">
                    <i class="fas fa-plus-circle me-2"></i>नयाँ पुरस्कार थप्नुहोस्
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelAw">
                    <i class="lucide-icon me-1" aria-hidden="true" data-lucide="arrow-left"></i>सूचीमा फर्कनुहोस्
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" id="awForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" id="awf_action" value="add">
                    <input type="hidden" name="id" id="awf_id" value="">
                    <input type="hidden" name="existing_image" id="awf_img" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">नाम (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="title_np" id="awf_title_np" class="form-control admin-fancy-input" required placeholder="पुरस्कारको नाम नेपालीमा">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Name (English)</label>
                            <input type="text" name="title" id="awf_title" class="form-control admin-fancy-input" placeholder="Award name in English">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">प्रदान गर्ने (नेपाली)</label>
                            <input type="text" name="awarded_by_np" id="awf_awarded_by_np" class="form-control admin-fancy-input" placeholder="संस्थाको नाम नेपालीमा">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Awarded By</label>
                            <input type="text" name="awarded_by" id="awf_awarded_by" class="form-control admin-fancy-input" placeholder="Organization name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">विवरण (नेपाली)</label>
                            <textarea name="description_np" id="awf_desc_np" class="form-control admin-fancy-input" rows="3" placeholder="पुरस्कारको विवरण..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Description</label>
                            <textarea name="description" id="awf_desc" class="form-control admin-fancy-input" rows="3" placeholder="Award description..."></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">मिति (बि.सं.)</label>
                            <div class="input-group">
                                <input type="text" name="award_date" id="awf_date" class="form-control admin-fancy-input nepali-datepicker" placeholder="२०७८-०१-१५">
                                <span class="input-group-text bg-success text-white"><i class="fas fa-calendar-alt"></i></span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">फोटो
                                <small class="text-muted fw-normal" id="awf_img_note"></small>
                            </label>
                            <input type="file" name="image" class="form-control admin-fancy-input" accept="image/*">
                            <div id="awf_img_prev" class="mt-2"></div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold text-success">क्रम</label>
                            <input type="number" name="display_order" id="awf_order" class="form-control admin-fancy-input" value="0" min="0">
                        </div>
                        <div class="col-md-2 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="awf_active" checked>
                                <label class="form-check-label fw-semibold" for="awf_active">सक्रिय</label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="awf_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                        </button>
                        <button type="button" id="awf_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i>रद्द
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

(removed PHP open) </script>

/* ── Client-side search (खोज बक्स) ── */
    var formBtn = document.getElementById('aw-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('awf_action').value      = 'add';
        document.getElementById('awf_id').value          = '';
        document.getElementById('awf_img').value         = '';
        document.getElementById('awf_title').value       = '';
        document.getElementById('awf_title_np').value    = '';
        document.getElementById('awf_awarded_by').value  = '';
        document.getElementById('awf_awarded_by_np').value = '';
        document.getElementById('awf_desc').value        = '';
        document.getElementById('awf_desc_np').value     = '';
        document.getElementById('awf_date').value        = '';
        document.getElementById('awf_order').value       = '0';
        document.getElementById('awf_active').checked    = true;
        document.getElementById('awf_img_prev').innerHTML = '';
        document.getElementById('awf_img_note').textContent = '';
        document.getElementById('awf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('awFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ पुरस्कार थप्नुहोस्';
        document.getElementById('awFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
    }

    document.getElementById('btnAddAward')?.addEventListener('click', function() { clearForm(); switchToForm(); });

    ['btnCancelAw','awf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    document.querySelectorAll('.btn-edit-aw').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('awf_action').value      = 'edit';
            document.getElementById('awf_id').value          = d.id;
            document.getElementById('awf_title').value       = d.title;
            document.getElementById('awf_title_np').value    = d.titleNp || '';
            document.getElementById('awf_awarded_by').value  = d.awardedBy || '';
            document.getElementById('awf_awarded_by_np').value = d.awardedByNp || '';
            document.getElementById('awf_desc').value        = d.desc || '';
            document.getElementById('awf_desc_np').value     = d.descNp || '';
            document.getElementById('awf_date').value        = d.date || '';
            document.getElementById('awf_order').value       = d.order || 0;
            document.getElementById('awf_img').value         = d.image || '';
            document.getElementById('awf_active').checked    = d.active === '1';
            var prev = document.getElementById('awf_img_prev');
            prev.innerHTML = d.image
                ? '<img src="../' + d.image + '" class="news-preview-img">'
                : '';
            document.getElementById('awf_img_note').textContent = d.image ? ' — नयाँ फोटो नचुने भने पुरानै रहन्छ' : '';
            document.getElementById('awf_submit').innerHTML = '<i class="lucide-icon me-2" aria-hidden="true" data-lucide="save"></i>अपडेट गर्नुहोस्';
            document.getElementById('awFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>पुरस्कार सम्पादन';
            document.getElementById('awFormTabLabel').textContent = 'सम्पादन';
            switchToForm();
        });
    });
});
</script>





/* ── Client-side search (खोज बक्स) ── */
(function() {
    var inp = document.querySelector('#aw-list .admin-table-search');
    var cnt = document.querySelector('#aw-list .search-count');
    if (!inp) return;
    
    function filter() {
        var q = inp.value.toLowerCase();
        var pane = document.querySelector('#aw-list .tab-pane.active');
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
        if (e.target.id === 'aw-sub-live' || e.target.id === 'aw-sub-arch') filter();
    });
    filter();
})();


<?php require_once 'includes/admin-footer.php'; ?>
