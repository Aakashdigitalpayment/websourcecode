/**
 * उपयोगी लिंकहरू व्यवस्थापन — Useful Links Management
 * Tab UI: सूची + Add/Edit form (modal popup हटाइएको)
 */
$pageTitle = 'उपयोगी लिंकहरू व्यवस्थापन';
require_once '../includes/config.php';
if (!isAdminLoggedIn()) redirect(ADMIN_URL . 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।'];
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? ADMIN_URL . 'dashboard.php')); exit;
    }
}
if (empty($csrfToken)) $csrfToken = generateCSRFToken();

$success = '';
$error   = '';
$db      = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $act = $_POST['action'];
        if ($act === 'add' || $act === 'edit') {
            $title     = clean_text($_POST['title']    ?? '');
            $title_np  = clean_text($_POST['title_np'] ?? $title);
            $url       = clean_text($_POST['url']      ?? '');
            $icon      = clean_text($_POST['icon']     ?? 'fas fa-link');
            $desc      = clean_text($_POST['description'] ?? '');
            $is_popup  = isset($_POST['is_popup'])  ? 1 : 0;
            $order     = (int)($_POST['display_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($act === 'add') {
                $db->prepare("INSERT INTO useful_links (title, title_np, url, icon, description, is_popup, display_order, is_active) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$title, $title_np, $url, $icon, $desc, $is_popup, $order, $is_active]);
                $success = 'लिंक सफलतापूर्वक थपियो।';
            } else {
                $db->prepare("UPDATE useful_links SET title=?, title_np=?, url=?, icon=?, description=?, is_popup=?, display_order=?, is_active=? WHERE id=?")
                   ->execute([$title, $title_np, $url, $icon, $desc, $is_popup, $order, $is_active, (int)$_POST['id']]);
                $success = 'लिंक सफलतापूर्वक अपडेट भयो।';
            }
        } elseif ($act === 'delete') {
            $db->prepare("DELETE FROM useful_links WHERE id=?")->execute([(int)$_POST['id']]);
            $success = 'लिंक मेटाइयो।';
        }
    } catch (Exception $e) {
        $error = 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।';
    }
}

$links = [];
try {
    $check = $db->query("SHOW TABLES LIKE 'useful_links'");
    if ($check->fetch() !== false) {
        $links = $db->query("SELECT * FROM useful_links ORDER BY display_order, id DESC")->fetchAll();
    } else {
        $error = 'useful_links टेबल छैन। कृपया migration चलाउनुहोस्।';
    }
} catch (Exception $e) { $links = []; }

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$lnkPart = adminPartitionRowsByIsActive($links);
$linksLive = $lnkPart['live'];
$linksArch = $lnkPart['archived'];
?>

<?php echo adminPageHeader(
    'उपयोगी लिंकहरू',
    'fa-link',
    'महत्त्वपूर्ण बाह्य लिंकहरू — NRB, सरकारी निकाय, अन्य।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($links) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="fas fa-check-circle me-1"></i>सक्रिय: ' . count($linksLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="fas fa-archive me-1"></i>अभिलेख: ' . count($linksArch) . '</span>'
); ?>

<?php echo adminAlert('success', $success) . adminAlert('danger', $error); ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#link-list" id="link-list-btn" title="सक्रिय / जम्मा">
            <i class="fas fa-list me-2"></i>लिंक सूची
            <span class="badge bg-success ms-1"><?php echo count($linksLive); ?> / <?php echo count($links); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#link-form" id="link-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="linkFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="link-list">
        <div class="card admin-table-card svc-flat-top-card">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3 svc-search-wrap">
                <div class="input-group input-group-sm svc-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="नाम, विवरण अनुसार खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
                    <?php echo adminListSubtabPills('link-sub', count($linksLive), count($linksArch)); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="link-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 coop-table">
                        <thead>
                            <tr>
                                <th class="ps-3" width="50">आइकन</th>
                                <th>शीर्षक</th>
                                <th>URL</th>
                                <th width="70" class="text-center">क्रम</th>
                                <th width="90" class="text-center">खोल्ने</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($links)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-link fa-3x mb-2 d-block opacity-25"></i>
                                कुनै लिंक छैन।
                            </td></tr>
                            <?php elseif (empty($linksLive)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-check-circle fa-3x mb-2 d-block opacity-25 text-success"></i>
                                सक्रिय लिंक छैन। अभिलेख हेर्नुहोस्।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($linksLive as $l): ?>
                            <tr>
                                <td class="ps-3"><i class="<?php echo htmlspecialchars($l['icon']); ?> fa-lg svc-icon-mark"></i></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($l['title_np'] ?: $l['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($l['title']); ?></small>
                                </td>
                                <td><a href="<?php echo htmlspecialchars($l['url']); ?>" target="_blank" class="text-truncate d-inline-block ul-link-clamp"><?php echo htmlspecialchars($l['url']); ?></a></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $l['display_order']; ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $l['is_popup'] ? 'warning text-dark' : 'secondary'; ?>"><?php echo $l['is_popup'] ? 'पप-अप' : 'नयाँ ट्याब'; ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $l['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $l['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-link"
                                            data-id="<?php echo $l['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($l['title'], ENT_QUOTES); ?>"
                                            data-title-np="<?php echo htmlspecialchars($l['title_np'] ?? '', ENT_QUOTES); ?>"
                                            data-url="<?php echo htmlspecialchars($l['url'], ENT_QUOTES); ?>"
                                            data-icon="<?php echo htmlspecialchars($l['icon'], ENT_QUOTES); ?>"
                                            data-desc="<?php echo htmlspecialchars($l['description'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $l['display_order']; ?>"
                                            data-popup="<?php echo $l['is_popup']; ?>"
                                            data-active="<?php echo $l['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('के तपाईं यो लिंक मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $l['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="link-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 coop-table">
                        <thead>
                            <tr>
                                <th class="ps-3" width="50">आइकन</th>
                                <th>शीर्षक</th>
                                <th>URL</th>
                                <th width="70" class="text-center">क्रम</th>
                                <th width="90" class="text-center">खोल्ने</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($linksArch)): ?>
                            <tr><td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-2 d-block opacity-25"></i>
                                अभिलेखमा कुनै लिंक छैन।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($linksArch as $l): ?>
                            <tr>
                                <td class="ps-3"><i class="<?php echo htmlspecialchars($l['icon']); ?> fa-lg svc-icon-mark"></i></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($l['title_np'] ?: $l['title']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($l['title']); ?></small>
                                </td>
                                <td><a href="<?php echo htmlspecialchars($l['url']); ?>" target="_blank" class="text-truncate d-inline-block ul-link-clamp"><?php echo htmlspecialchars($l['url']); ?></a></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $l['display_order']; ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $l['is_popup'] ? 'warning text-dark' : 'secondary'; ?>"><?php echo $l['is_popup'] ? 'पप-अप' : 'नयाँ ट्याब'; ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $l['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $l['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-link"
                                            data-id="<?php echo $l['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($l['title'], ENT_QUOTES); ?>"
                                            data-title-np="<?php echo htmlspecialchars($l['title_np'] ?? '', ENT_QUOTES); ?>"
                                            data-url="<?php echo htmlspecialchars($l['url'], ENT_QUOTES); ?>"
                                            data-icon="<?php echo htmlspecialchars($l['icon'], ENT_QUOTES); ?>"
                                            data-desc="<?php echo htmlspecialchars($l['description'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $l['display_order']; ?>"
                                            data-popup="<?php echo $l['is_popup']; ?>"
                                            data-active="<?php echo $l['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form" onsubmit="return confirm('के तपाईं यो लिंक मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $l['id']; ?>">
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
    <div class="tab-pane fade" id="link-form">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="linkFormTitle">
                    <i class="fas fa-plus-circle me-2"></i>नयाँ लिंक थप्नुहोस्
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelLink">
                    <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="linkForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" id="lnkf_action" value="add">
                    <input type="hidden" name="id" id="lnkf_id" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">शीर्षक (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="title_np" id="lnkf_title_np" class="form-control admin-fancy-input" required placeholder="लिंकको शीर्षक नेपालीमा">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Title (English)</label>
                            <input type="text" name="title" id="lnkf_title" class="form-control admin-fancy-input" placeholder="Link title in English">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success">URL <span class="text-danger">*</span></label>
                            <input type="url" name="url" id="lnkf_url" class="form-control admin-fancy-input" required placeholder="https://example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">आइकन (Font Awesome)</label>
                            <div class="input-group">
                                <span class="input-group-text bg-success text-white" id="lnkIconPrev"><i class="fas fa-link"></i></span>
                                <input type="text" name="icon" id="lnkf_icon" class="form-control admin-fancy-input"
                                       value="fas fa-link" placeholder="fas fa-link"
                                       oninput="document.getElementById('lnkIconPrev').innerHTML='<i class=\''+this.value+'\'></i>'">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">विवरण (वैकल्पिक)</label>
                            <input type="text" name="description" id="lnkf_desc" class="form-control admin-fancy-input" placeholder="संक्षिप्त विवरण">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success">क्रम</label>
                            <input type="number" name="display_order" id="lnkf_order" class="form-control admin-fancy-input" value="0" min="0">
                        </div>
                        <div class="col-md-4 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_popup" id="lnkf_popup">
                                <label class="form-check-label fw-semibold" for="lnkf_popup">पप-अपमा खोल्नुहोस्</label>
                            </div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="lnkf_active" checked>
                                <label class="form-check-label fw-semibold" for="lnkf_active">सक्रिय</label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="lnkf_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                        </button>
                        <button type="button" id="lnkf_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i>रद्द
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>



    var listBtn = document.getElementById('link-list-btn');
    var formBtn = document.getElementById('link-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('lnkf_action').value   = 'add';
        document.getElementById('lnkf_id').value       = '';
        document.getElementById('lnkf_title').value    = '';
        document.getElementById('lnkf_title_np').value = '';
        document.getElementById('lnkf_url').value      = '';
        document.getElementById('lnkf_icon').value     = 'fas fa-link';
        document.getElementById('lnkf_desc').value     = '';
        document.getElementById('lnkf_order').value    = '0';
        document.getElementById('lnkf_popup').checked  = false;
        document.getElementById('lnkf_active').checked = true;
        document.getElementById('lnkIconPrev').innerHTML = '<i class="fas fa-link"></i>';
        document.getElementById('lnkf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('linkFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ लिंक थप्नुहोस्';
        document.getElementById('linkFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
    }

    var btnAddLink = document.getElementById('btnAddLink');
    if (btnAddLink) btnAddLink.addEventListener('click', function() { clearForm(); switchToForm(); });

    ['btnCancelLink','lnkf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    document.querySelectorAll('.btn-edit-link').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('lnkf_action').value   = 'edit';
            document.getElementById('lnkf_id').value       = d.id;
            document.getElementById('lnkf_title').value    = d.title;
            document.getElementById('lnkf_title_np').value = d.titleNp || '';
            document.getElementById('lnkf_url').value      = d.url;
            document.getElementById('lnkf_icon').value     = d.icon;
            document.getElementById('lnkf_desc').value     = d.desc || '';
            document.getElementById('lnkf_order').value    = d.order;
            document.getElementById('lnkf_popup').checked  = d.popup === '1';
            document.getElementById('lnkf_active').checked = d.active === '1';
            document.getElementById('lnkIconPrev').innerHTML = '<i class="' + d.icon + '"></i>';
            document.getElementById('lnkf_submit').innerHTML = '<i class="fas fa-save me-2"></i>अपडेट गर्नुहोस्';
            document.getElementById('linkFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>लिंक सम्पादन';
            document.getElementById('linkFormTabLabel').textContent = 'सम्पादन';
            switchToForm();
        });
    });
});
</script>





/* ── Client-side search (खोज बक्स) ── */
(function() {
    var inp = document.querySelector('#link-list .admin-table-search');
    var cnt = document.querySelector('#link-list .search-count');
    if (!inp) return;
    
    function filter() {
        var q = inp.value.toLowerCase();
        var pane = document.querySelector('#link-list .tab-pane.active');
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
        if (e.target.id === 'link-sub-live' || e.target.id === 'link-sub-arch') filter();
    });
    filter();
})();


<?php require_once 'includes/admin-footer.php'; ?>
