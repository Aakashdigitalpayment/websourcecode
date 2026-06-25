<?php
require_once __DIR__ . '/../includes/election-tables.php';
/**
 * समिति व्यवस्थापन — Committee Management
 * Tab UI: प्रकार / कार्यकाल / सदस्य — URL-based tabs with inline Add/Edit forms
 */
$pageTitle = 'समिति व्यवस्थापन';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db        = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

$activeTab = $_GET['tab'] ?? 'types';
if (!in_array($activeTab, ['types', 'tenures', 'members'], true)) {
    $activeTab = 'types';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        /* ══ समिति प्रकार ══ */
        if ($action === 'add_type' || $action === 'edit_type') {
            $id           = $_POST['type_id'] ?? null;
            $name         = clean_text($_POST['type_name']        ?? '');
            $name_np      = clean_text($_POST['type_name_np']     ?? '');
            $description  = clean_text($_POST['type_description'] ?? '');
            $display_order = (int)($_POST['type_order'] ?? 0);
            $is_active    = isset($_POST['type_active']) ? 1 : 0;
            /* नयाँ: navbar/menu drop-down मा यो समिति देखाउने/नदेखाउने */
            $show_in_navbar = isset($_POST['type_show_in_navbar']) ? 1 : 0;

            if ($action === 'add_type') {
                $db->prepare("INSERT INTO committee_types (name, name_np, description, display_order, is_active, show_in_navbar) VALUES (?,?,?,?,?,?)")
                   ->execute([$name, $name_np, $description, $display_order, $is_active, $show_in_navbar]);
                setFlash('success', 'समिति प्रकार थपियो।');
            } else {
                $db->prepare("UPDATE committee_types SET name=?, name_np=?, description=?, display_order=?, is_active=?, show_in_navbar=? WHERE id=?")
                   ->execute([$name, $name_np, $description, $display_order, $is_active, $show_in_navbar, $id]);
                setFlash('success', 'समिति प्रकार अपडेट भयो।');
            }
        }

        /* ══ कार्यकाल ══ */
        elseif ($action === 'add_tenure' || $action === 'edit_tenure') {
            $id              = $_POST['tenure_edit_id'] ?? null;
            $committee_type_id = (int)$_POST['committee_type_id'];
            $tenure_name     = clean_text($_POST['tenure_name']    ?? '');
            $tenure_name_np  = clean_text($_POST['tenure_name_np'] ?? '');
            $start_date      = $_POST['start_date'];
            $end_date        = $_POST['end_date'];
            $is_current      = isset($_POST['is_current']) ? 1 : 0;
            $is_active       = isset($_POST['tenure_active']) ? 1 : 0;

            if ($is_current) {
                $db->prepare("UPDATE committee_tenures SET is_current=0 WHERE committee_type_id=?")->execute([$committee_type_id]);
            }

            if ($action === 'add_tenure') {
                $db->prepare("INSERT INTO committee_tenures (committee_type_id, tenure_name, tenure_name_np, start_date, end_date, is_current, is_active) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$committee_type_id, $tenure_name, $tenure_name_np, $start_date, $end_date, $is_current, $is_active]);
                setFlash('success', 'कार्यकाल थपियो।');
            } else {
                $db->prepare("UPDATE committee_tenures SET committee_type_id=?, tenure_name=?, tenure_name_np=?, start_date=?, end_date=?, is_current=?, is_active=? WHERE id=?")
                   ->execute([$committee_type_id, $tenure_name, $tenure_name_np, $start_date, $end_date, $is_current, $is_active, $id]);
                setFlash('success', 'कार्यकाल अपडेट भयो।');
            }
        }

        /* ══ सदस्य ══ */
        elseif ($action === 'add_member' || $action === 'edit_member') {
            $id           = $_POST['member_edit_id'] ?? null;
            $tenure_id    = (int)$_POST['tenure_id'];
            $name         = clean_text($_POST['member_name']        ?? '');
            $name_en      = clean_text($_POST['member_name_en']     ?? '');
            $position     = clean_text($_POST['member_position']    ?? '');
            $position_en  = clean_text($_POST['member_position_en'] ?? '');
            $phone        = preg_replace('/[^0-9]/', '', clean_text($_POST['member_phone']       ?? '', 20));
            $email        = strtolower(clean_text($_POST['member_email']       ?? '', 254));
            $address      = clean_text($_POST['member_address']     ?? '');
            $display_order = (int)($_POST['member_order']         ?? 0);
            $is_active    = isset($_POST['member_active']) ? 1 : 0;

            $photo = $_POST['existing_photo'] ?? '';
            if (isset($_FILES['member_photo']) && $_FILES['member_photo']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['member_photo'], 'committee');
                if ($upload['success']) $photo = $upload['path'];
            }
            if (!empty($_POST['webcam_photo'])) {
                $webcamData = str_replace([' ', 'data:image/png;base64,'], ['+', ''], $_POST['webcam_photo']);
                $imageData  = base64_decode($webcamData);
                $uploadDir  = UPLOAD_PATH . 'committee/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $fileName = 'webcam_' . time() . '_' . uniqid() . '.png';
                file_put_contents($uploadDir . $fileName, $imageData);
                $photo = 'assets/uploads/committee/' . $fileName;
            }

            if ($action === 'add_member') {
                $db->prepare("INSERT INTO committee_members (tenure_id, name, name_en, position, position_en, phone, email, address, photo, display_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$tenure_id, $name, $name_en, $position, $position_en, $phone, $email, $address, $photo, $display_order, $is_active]);
                setFlash('success', 'सदस्य थपियो।');
            } else {
                $db->prepare("UPDATE committee_members SET tenure_id=?, name=?, name_en=?, position=?, position_en=?, phone=?, email=?, address=?, photo=?, display_order=?, is_active=? WHERE id=?")
                   ->execute([$tenure_id, $name, $name_en, $position, $position_en, $phone, $email, $address, $photo, $display_order, $is_active, $id]);
                setFlash('success', 'सदस्य अपडेट भयो।');
            }
        }

        /* ══ Delete ══ */
        elseif ($action === 'delete_type') {
            $db->prepare("DELETE FROM committee_types WHERE id=?")->execute([$_POST['delete_id']]);
            setFlash('success', 'समिति प्रकार मेटाइयो।');
        } elseif ($action === 'delete_tenure') {
            $db->prepare("DELETE FROM committee_tenures WHERE id=?")->execute([$_POST['delete_id']]);
            setFlash('success', 'कार्यकाल मेटाइयो।');
        } elseif ($action === 'delete_member') {
            $db->prepare("DELETE FROM committee_members WHERE id=?")->execute([$_POST['delete_id']]);
            setFlash('success', 'सदस्य मेटाइयो।');
        }

    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो।');
    }

    redirect('committees.php?tab=' . $activeTab);
}

/* ── Data ── */
try {
    $committeeTypes = $db->query("SELECT * FROM committee_types ORDER BY display_order, id LIMIT 500")->fetchAll();
    $tenures = $db->query("SELECT t.*, ct.name_np AS type_name FROM committee_tenures t LEFT JOIN committee_types ct ON t.committee_type_id=ct.id ORDER BY t.is_current DESC, t.start_date DESC")->fetchAll();
ensureDesignationsTable(getDB());
$__designations = fetchDesignations(getDB(), ['committee']);
    $members = $db->query("SELECT m.*, t.tenure_name, ct.name_np AS type_name FROM committee_members m LEFT JOIN committee_tenures t ON m.tenure_id=t.id LEFT JOIN committee_types ct ON t.committee_type_id=ct.id ORDER BY m.display_order, m.id")->fetchAll();
} catch (Exception $e) { $committeeTypes = $tenures = $members = []; }

$_flash = getFlash();
?>

<?php
$headerBtns = '<button class="btn btn-primary btn-sm" id="btnAddCmt"><i class="fas fa-plus me-1"></i>नयाँ थप्नुहोस्</button>';
echo adminPageHeader('समिति/उपसमिति व्यवस्थापन', 'fa-users-gear', 'संचालक समिति र उपसमिति सदस्य व्यवस्थापन', $headerBtns);
if ($_flash) echo adminAlert($_flash['type'] === 'success' ? 'success' : 'danger', $_flash['message']);
?>

<!-- URL-based tabs -->
<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'types' ? 'active' : ''; ?>" href="?tab=types">
            <i class="fas fa-layer-group me-2"></i>समिति प्रकार
            <span class="badge bg-success ms-1"><?php echo count($committeeTypes); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'tenures' ? 'active' : ''; ?>" href="?tab=tenures">
            <i class="fas fa-calendar-alt me-2"></i>कार्यकालहरू
            <span class="badge bg-primary ms-1"><?php echo count($tenures); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'members' ? 'active' : ''; ?>" href="?tab=members">
            <i class="fas fa-user-friends me-2"></i>सदस्यहरू
            <span class="badge bg-info ms-1"><?php echo count($members); ?></span>
        </a>
    </li>
</ul>

<!-- ══════════════════════════════
     TAB: समिति प्रकार
══════════════════════════════ -->
<?php if ($activeTab === 'types'): ?>
<div class="card admin-table-card cmt-flat-top-card">
    <div class="card-header d-flex align-items-center justify-content-between cmt-header-green">
        <h5 class="mb-0 fw-bold"><i class="fas fa-layer-group me-2"></i>समिति प्रकारहरू</h5>
        <button class="btn btn-outline-light btn-sm" id="btnAddType"><i class="fas fa-plus me-1"></i>नयाँ प्रकार</button>
    </div>

            <!-- खोज बक्स -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3">
                <div class="input-group input-group-sm svc-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th class="ps-3" width="60">क्रम</th>
                    <th>नाम</th>
                    <th>विवरण</th>
                    <th width="110" class="text-center">Navbar मा</th>
                    <th width="90" class="text-center">स्थिति</th>
                    <th width="140" class="text-center">कार्य</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($committeeTypes)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-layer-group fa-3x mb-2 d-block opacity-25"></i>कुनै समिति प्रकार छैन।</td></tr>
                    <?php endif; ?>
                    <?php foreach ($committeeTypes as $t): $showNav = (int)($t['show_in_navbar'] ?? 0); ?>
                    <tr>
                        <td class="ps-3"><span class="badge bg-light text-dark border"><?php echo $t['display_order']; ?></span></td>
                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($t['name_np']); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($t['name']); ?></small>
                        </td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($t['description'] ?? ''); ?></small></td>
                        <td class="text-center">
                            <?php if ($showNav): ?>
                                <span class="badge bg-info-subtle text-info border border-info"><i class="fas fa-eye me-1"></i>देखिन्छ</span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border"><i class="fas fa-eye-slash me-1"></i>लुकाइएको</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge bg-<?php echo $t['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $t['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary me-1 btn-edit-type"
                                    data-id="<?php echo $t['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($t['name'], ENT_QUOTES); ?>"
                                    data-name-np="<?php echo htmlspecialchars($t['name_np'], ENT_QUOTES); ?>"
                                    data-desc="<?php echo htmlspecialchars($t['description'] ?? '', ENT_QUOTES); ?>"
                                    data-order="<?php echo $t['display_order']; ?>"
                                    data-active="<?php echo $t['is_active']; ?>"
                                    data-show-nav="<?php echo $showNav; ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" class="svc-inline-form" onsubmit="return confirm('यो समिति प्रकार मेटाउने?')">
    <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_type">
                                <input type="hidden" name="delete_id" value="<?php echo $t['id']; ?>">
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

<!-- Add/Edit Form Panel -->
<div id="typFormPanel" class="card mt-4 d-none cmt-top-border-green">
    <div class="card-header d-flex justify-content-between align-items-center cmt-header-green">
        <h5 class="mb-0 fw-bold" id="typFormTitle"><i class="fas fa-plus-circle me-2"></i>नयाँ समिति प्रकार</h5>
        <button type="button" class="btn btn-light btn-sm" id="btnCancelType"><i class="fas fa-times me-1"></i>रद्द</button>
    </div>
    <div class="card-body p-4">
        <form method="POST">
    <?php echo csrfField(); ?>
            <input type="hidden" name="action" id="typ_action" value="add_type">
            <input type="hidden" name="type_id" id="typ_id" value="">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-success">नाम (नेपाली) <span class="text-danger">*</span></label>
                    <input type="text" name="type_name_np" id="typ_name_np" class="form-control admin-fancy-input" required placeholder="संचालक समिति">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-success">Name (English)</label>
                    <input type="text" name="type_name" id="typ_name" class="form-control admin-fancy-input" placeholder="Board of Directors">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold text-success">विवरण</label>
                    <input type="text" name="type_description" id="typ_desc" class="form-control admin-fancy-input" placeholder="समितिको संक्षिप्त विवरण">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold text-success">क्रम</label>
                    <input type="number" name="type_order" id="typ_order" class="form-control admin-fancy-input" value="0" min="0">
                </div>
                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div class="form-check form-switch fs-5">
                        <input class="form-check-input" type="checkbox" name="type_active" id="typ_active" checked>
                        <label class="form-check-label fw-semibold" for="typ_active">सक्रिय</label>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end pb-1">
                    <!-- नयाँ: navbar drop-down मा देखाउने/नदेखाउने toggle -->
                    <div class="form-check form-switch fs-5">
                        <input class="form-check-input" type="checkbox" name="type_show_in_navbar" id="typ_show_nav">
                        <label class="form-check-label fw-semibold" for="typ_show_nav">मेनु ड्रप-डाउनमा देखाउनुहोस्</label>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="d-flex gap-3">
                <button type="submit" id="typ_submit" class="btn btn-success px-5 fw-semibold">
                    <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                </button>
                <button type="button" id="typCancelBtn2" class="btn btn-outline-secondary px-4">
                    <i class="fas fa-times me-1"></i>रद्द
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var panel = document.getElementById('typFormPanel');
    function showPanel() { panel.classList.remove('d-none'); panel.scrollIntoView({behavior:'smooth',block:'nearest'}); }
    function hidePanel() { panel.classList.add('d-none'); }
    function clearType() {
        document.getElementById('typ_action').value = 'add_type';
        document.getElementById('typ_id').value     = '';
        document.getElementById('typ_name').value   = '';
        document.getElementById('typ_name_np').value = '';
        document.getElementById('typ_desc').value   = '';
        document.getElementById('typ_order').value  = '0';
        document.getElementById('typ_active').checked = true;
        document.getElementById('typ_show_nav').checked = false;
        document.getElementById('typ_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('typFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ समिति प्रकार';
    }
    var btnAddType = document.getElementById('btnAddType');
    var btnAddCmt  = document.getElementById('btnAddCmt');
    var btnCancelType = document.getElementById('btnCancelType');
    var typCancelBtn2 = document.getElementById('typCancelBtn2');
    if (btnAddType) btnAddType.addEventListener('click', function() { clearType(); showPanel(); });
    if (btnAddCmt)  btnAddCmt.addEventListener('click',  function() { clearType(); showPanel(); });
    if (btnCancelType) btnCancelType.addEventListener('click', hidePanel);
    if (typCancelBtn2) typCancelBtn2.addEventListener('click', hidePanel);
    document.querySelectorAll('.btn-edit-type').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('typ_action').value   = 'edit_type';
            document.getElementById('typ_id').value       = d.id;
            document.getElementById('typ_name').value     = d.name;
            document.getElementById('typ_name_np').value  = d.nameNp;
            document.getElementById('typ_desc').value     = d.desc || '';
            document.getElementById('typ_order').value    = d.order;
            document.getElementById('typ_active').checked = d.active === '1';
            document.getElementById('typ_show_nav').checked = d.showNav === '1';
            document.getElementById('typ_submit').innerHTML = '<i class="fas fa-save me-2"></i>अपडेट गर्नुहोस्';
            document.getElementById('typFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>समिति प्रकार सम्पादन';
            showPanel();
        });
    });
});
</script>
<?php endif; ?>

<!-- ══════════════════════════════
     TAB: कार्यकाल
══════════════════════════════ -->
<?php if ($activeTab === 'tenures'): ?>
<div class="card admin-table-card cmt-flat-top-card">
    <div class="card-header d-flex align-items-center justify-content-between cmt-header-blue">
        <h5 class="mb-0 fw-bold"><i class="fas fa-calendar-alt me-2"></i>कार्यकालहरू</h5>
        <button class="btn btn-outline-light btn-sm" id="btnAddTenure"><i class="fas fa-plus me-1"></i>नयाँ कार्यकाल</button>
    </div>

            <!-- खोज बक्स -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3">
                <div class="input-group input-group-sm svc-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th class="ps-3">समिति</th>
                    <th>कार्यकाल</th>
                    <th>अवधि</th>
                    <th width="90" class="text-center">स्थिति</th>
                    <th width="140" class="text-center">कार्य</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($tenures)): ?>
                    <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fas fa-calendar fa-3x mb-2 d-block opacity-25"></i>कुनै कार्यकाल छैन।</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tenures as $tn): ?>
                    <tr class="<?php echo $tn['is_current'] ? 'table-success' : ''; ?>">
                        <td class="ps-3"><small class="fw-semibold"><?php echo htmlspecialchars($tn['type_name'] ?? '—'); ?></small></td>
                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($tn['tenure_name']); ?></div>
                            <?php if ($tn['is_current']): ?><span class="badge bg-success">हालको</span><?php endif; ?>
                            <?php if ($tn['tenure_name_np'] ?? ''): ?><small class="text-muted"><?php echo htmlspecialchars($tn['tenure_name_np']); ?></small><?php endif; ?>
                        </td>
                        <td>
                            <small>
                                <?php echo formatNepaliDate($tn['start_date']); ?> –
                                <?php echo formatNepaliDate($tn['end_date']); ?>
                            </small>
                        </td>
                        <td class="text-center"><span class="badge bg-<?php echo $tn['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $tn['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary me-1 btn-edit-tenure"
                                    data-id="<?php echo $tn['id']; ?>"
                                    data-type-id="<?php echo $tn['committee_type_id']; ?>"
                                    data-name="<?php echo htmlspecialchars($tn['tenure_name'], ENT_QUOTES); ?>"
                                    data-name-np="<?php echo htmlspecialchars($tn['tenure_name_np'] ?? '', ENT_QUOTES); ?>"
                                    data-start="<?php echo htmlspecialchars($tn['start_date'], ENT_QUOTES); ?>"
                                    data-end="<?php echo htmlspecialchars($tn['end_date'], ENT_QUOTES); ?>"
                                    data-current="<?php echo $tn['is_current']; ?>"
                                    data-active="<?php echo $tn['is_active']; ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" class="svc-inline-form" onsubmit="return confirm('यो कार्यकाल मेटाउने?')">
    <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_tenure">
                                <input type="hidden" name="delete_id" value="<?php echo $tn['id']; ?>">
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

<!-- Tenure Add/Edit Form Panel -->
<div id="tenFormPanel" class="card mt-4 d-none cmt-top-border-blue">
    <div class="card-header d-flex justify-content-between align-items-center cmt-header-blue">
        <h5 class="mb-0 fw-bold" id="tenFormTitle"><i class="fas fa-plus-circle me-2"></i>नयाँ कार्यकाल</h5>
        <button type="button" class="btn btn-light btn-sm" id="btnCancelTenure"><i class="fas fa-times me-1"></i>रद्द</button>
    </div>
    <div class="card-body p-4">
        <form method="POST">
    <?php echo csrfField(); ?>
            <input type="hidden" name="action" id="ten_action" value="add_tenure">
            <input type="hidden" name="tenure_edit_id" id="ten_id" value="">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-success">समिति प्रकार <span class="text-danger">*</span></label>
                    <select name="committee_type_id" id="ten_type_id" class="form-select admin-fancy-input" required>
                        <option value="">— छान्नुहोस् —</option>
                        <?php foreach ($committeeTypes as $ct): ?>
                        <option value="<?php echo $ct['id']; ?>"><?php echo htmlspecialchars($ct['name_np']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-success">कार्यकाल नाम <span class="text-danger">*</span></label>
                    <input type="text" name="tenure_name" id="ten_name" class="form-control admin-fancy-input" required placeholder="2080-2084">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-success">कार्यकाल नाम (नेपाली)</label>
                    <input type="text" name="tenure_name_np" id="ten_name_np" class="form-control admin-fancy-input" placeholder="२०८०-२०८४">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold text-success">सुरु मिति (वि.सं.)</label>
                    <input type="text" name="start_date" id="ten_start" class="form-control admin-fancy-input nepali-datepicker" placeholder="YYYY-MM-DD">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold text-success">अन्त्य मिति (वि.सं.)</label>
                    <input type="text" name="end_date" id="ten_end" class="form-control admin-fancy-input nepali-datepicker" placeholder="YYYY-MM-DD">
                </div>
                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div class="form-check form-switch fs-5">
                        <input class="form-check-input" type="checkbox" name="is_current" id="ten_current">
                        <label class="form-check-label fw-semibold" for="ten_current">हालको कार्यकाल</label>
                    </div>
                </div>
                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div class="form-check form-switch fs-5">
                        <input class="form-check-input" type="checkbox" name="tenure_active" id="ten_active" checked>
                        <label class="form-check-label fw-semibold" for="ten_active">सक्रिय</label>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="d-flex gap-3">
                <button type="submit" id="ten_submit" class="btn btn-primary px-5 fw-semibold">
                    <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                </button>
                <button type="button" id="tenCancelBtn2" class="btn btn-outline-secondary px-4"><i class="fas fa-times me-1"></i>रद्द</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var panel = document.getElementById('tenFormPanel');
    function showPanel() { panel.classList.remove('d-none'); panel.scrollIntoView({behavior:'smooth',block:'nearest'}); }
    function hidePanel() { panel.classList.add('d-none'); }
    function clearTenure() {
        document.getElementById('ten_action').value  = 'add_tenure';
        document.getElementById('ten_id').value      = '';
        document.getElementById('ten_type_id').value = '';
        document.getElementById('ten_name').value    = '';
        document.getElementById('ten_name_np').value = '';
        document.getElementById('ten_start').value   = '';
        document.getElementById('ten_end').value     = '';
        document.getElementById('ten_current').checked = false;
        document.getElementById('ten_active').checked  = true;
        document.getElementById('ten_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('tenFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ कार्यकाल';
    }
    var btnAddTenure = document.getElementById('btnAddTenure');
    var btnCancelTenure = document.getElementById('btnCancelTenure');
    if (btnAddTenure) btnAddTenure.addEventListener('click', function() { clearTenure(); showPanel(); });
    if (btnAddCmt)    btnAddCmt.addEventListener('click', function() { clearTenure(); showPanel(); });
    if (btnCancelTenure) btnCancelTenure.addEventListener('click', hidePanel);
    document.getElementById('tenCancelBtn2').addEventListener('click', hidePanel);
    document.querySelectorAll('.btn-edit-tenure').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('ten_action').value    = 'edit_tenure';
            document.getElementById('ten_id').value        = d.id;
            document.getElementById('ten_type_id').value   = d.typeId;
            document.getElementById('ten_name').value      = d.name;
            document.getElementById('ten_name_np').value   = d.nameNp || '';
            document.getElementById('ten_start').value     = d.start;
            document.getElementById('ten_end').value       = d.end;
            document.getElementById('ten_current').checked = d.current === '1';
            document.getElementById('ten_active').checked  = d.active === '1';
            document.getElementById('ten_submit').innerHTML = '<i class="fas fa-save me-2"></i>अपडेट गर्नुहोस्';
            document.getElementById('tenFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>कार्यकाल सम्पादन';
            showPanel();
        });
    });
});
</script>
<?php endif; ?>

<!-- ══════════════════════════════
     TAB: सदस्य
══════════════════════════════ -->
<?php if ($activeTab === 'members'): ?>
<div class="card admin-table-card cmt-flat-top-card">
    <div class="card-header d-flex align-items-center justify-content-between cmt-header-cyan">
        <h5 class="mb-0 fw-bold"><i class="fas fa-user-friends me-2"></i>समिति सदस्यहरू</h5>
        <button class="btn btn-outline-light btn-sm" id="btnAddMember"><i class="fas fa-plus me-1"></i>नयाँ सदस्य</button>
    </div>

            <!-- खोज बक्स -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3">
                <div class="input-group input-group-sm svc-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th class="ps-3" width="60">फोटो</th>
                    <th>नाम / पद</th>
                    <th>समिति / कार्यकाल</th>
                    <th>सम्पर्क</th>
                    <th width="90" class="text-center">स्थिति</th>
                    <th width="140" class="text-center">कार्य</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($members)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-users fa-3x mb-2 d-block opacity-25"></i>कुनै सदस्य छैन।</td></tr>
                    <?php endif; ?>
                    <?php foreach ($members as $m): ?>
                    <tr>
                        <td class="ps-3">
                            <?php if ($m['photo']): ?>
                            <img src="../<?php echo htmlspecialchars($m['photo']); ?>"
                                 class="cmt-mem-avatar">
                            <?php else: ?>
                            <div class="cmt-mem-avatar-fallback">
                                <i class="fas fa-user text-secondary"></i>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-semibold"><?php echo htmlspecialchars($m['name']); ?></div>
                            <small class="text-success"><?php echo htmlspecialchars($m['position']); ?></small>
                        </td>
                        <td>
                            <small class="fw-semibold"><?php echo htmlspecialchars($m['type_name'] ?? ''); ?></small><br>
                            <small class="text-muted"><?php echo htmlspecialchars($m['tenure_name'] ?? ''); ?></small>
                        </td>
                        <td>
                            <?php if ($m['phone']): ?><small><i class="fas fa-phone fa-xs text-muted me-1"></i><?php echo htmlspecialchars($m['phone']); ?></small><br><?php endif; ?>
                            <?php if ($m['email']): ?><small><i class="fas fa-envelope fa-xs text-muted me-1"></i><?php echo htmlspecialchars($m['email']); ?></small><?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge bg-<?php echo $m['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $m['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-primary me-1 btn-edit-member"
                                    data-member='<?php echo htmlspecialchars(json_encode($m, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                    title="सम्पादन">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" class="svc-inline-form" onsubmit="return confirm('यो सदस्य मेटाउने?')">
    <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_member">
                                <input type="hidden" name="delete_id" value="<?php echo $m['id']; ?>">
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

<!-- Member Add/Edit Form Panel -->
<div id="memFormPanel" class="card mt-4 d-none cmt-top-border-cyan">
    <div class="card-header d-flex justify-content-between align-items-center cmt-header-cyan">
        <h5 class="mb-0 fw-bold" id="memFormTitle"><i class="fas fa-plus-circle me-2"></i>नयाँ समिति सदस्य</h5>
        <button type="button" class="btn btn-light btn-sm" id="btnCancelMember"><i class="fas fa-times me-1"></i>रद्द</button>
    </div>
    <div class="card-body p-4">
        <form method="POST" enctype="multipart/form-data" id="memForm" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
            <input type="hidden" name="action" id="mem_action" value="add_member">
            <input type="hidden" name="member_edit_id" id="mem_id" value="">
            <input type="hidden" name="existing_photo" id="mem_existing_photo" value="">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold text-success">कार्यकाल <span class="text-danger">*</span></label>
                    <select name="tenure_id" id="mem_tenure_id" class="form-select admin-fancy-input" required>
                        <option value="">— कार्यकाल छान्नुहोस् —</option>
                        <?php foreach ($tenures as $tn): ?>
                        <option value="<?php echo $tn['id']; ?>">
                            <?php echo htmlspecialchars(($tn['type_name'] ?? '') . ' — ' . $tn['tenure_name']); ?>
                            <?php echo $tn['is_current'] ? ' (हालको)' : ''; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-success">नाम (नेपाली) <span class="text-danger">*</span></label>
                    <input type="text" name="member_name" id="mem_name" class="form-control admin-fancy-input" required placeholder="पूरा नाम नेपालीमा">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-success">Name (English)</label>
                    <input type="text" name="member_name_en" id="mem_name_en" class="form-control admin-fancy-input" placeholder="Full name in English">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-success">पद (नेपाली) <span class="text-danger">*</span></label>
                    <select class="form-select admin-fancy-input" name="member_position" id="mem_position" required onchange="(function(sel){var o=sel.options[sel.selectedIndex];var en=document.getElementById('mem_position_en');if(en && o.dataset.en) en.value=o.dataset.en;})(this)">
                        <option value="">— पद छान्नुहोस् —</option>
                        <?php foreach ($__designations as $__d): ?>
                            <option value="<?php echo htmlspecialchars($__d['title_np']); ?>" data-en="<?php echo htmlspecialchars($__d['title_en']); ?>"><?php echo htmlspecialchars($__d['title_np']); ?><?php if ($__d['title_en']): ?> — <?php echo htmlspecialchars($__d['title_en']); ?><?php endif; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="small text-muted mt-1">नयाँ पद <a href="designations.php" target="_blank">पद मास्टर</a> मा थप्नुहोस्।</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-success">Position (English)</label>
                    <input type="text" name="member_position_en" id="mem_position_en" class="form-control admin-fancy-input" placeholder="Chairperson, Secretary ...">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold text-success">फोन</label>
                    <input type="text" name="member_phone" id="mem_phone" class="form-control admin-fancy-input" placeholder="९८xxxxxxxx">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold text-success">इमेल</label>
                    <input type="email" name="member_email" id="mem_email" class="form-control admin-fancy-input" placeholder="email@example.com">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold text-success">ठेगाना</label>
                    <input type="text" name="member_address" id="mem_address" class="form-control admin-fancy-input" placeholder="काठमाडौं ...">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold text-success">फोटो
                        <small class="text-muted fw-normal" id="mem_photo_note"></small>
                    </label>
                    <input type="file" name="member_photo" class="form-control admin-fancy-input" accept="image/*"
                           onchange="previewMemPhoto(this)">
                    <div id="mem_photo_prev" class="mt-2"></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold text-success">क्रम</label>
                    <input type="number" name="member_order" id="mem_order" class="form-control admin-fancy-input" value="0" min="0">
                </div>
                <div class="col-md-3 d-flex align-items-end pb-1">
                    <div class="form-check form-switch fs-5">
                        <input class="form-check-input" type="checkbox" name="member_active" id="mem_active" checked>
                        <label class="form-check-label fw-semibold" for="mem_active">सक्रिय</label>
                    </div>
                </div>
            </div>

            <hr class="my-4">
            <div class="d-flex gap-3">
                <button type="submit" id="mem_submit" class="btn btn-primary px-5 fw-semibold">
                    <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                </button>
                <button type="button" id="memCancelBtn2" class="btn btn-outline-secondary px-4"><i class="fas fa-times me-1"></i>रद्द</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var panel = document.getElementById('memFormPanel');
    function showPanel() { panel.classList.remove('d-none'); panel.scrollIntoView({behavior:'smooth',block:'nearest'}); }
    function hidePanel() { panel.classList.add('d-none'); }
    function clearMember() {
        document.getElementById('mem_action').value           = 'add_member';
        document.getElementById('mem_id').value               = '';
        document.getElementById('mem_tenure_id').value        = '';
        document.getElementById('mem_name').value             = '';
        document.getElementById('mem_name_en').value          = '';
        document.getElementById('mem_position').value         = '';
        document.getElementById('mem_position_en').value      = '';
        document.getElementById('mem_phone').value            = '';
        document.getElementById('mem_email').value            = '';
        document.getElementById('mem_address').value          = '';
        document.getElementById('mem_order').value            = '0';
        document.getElementById('mem_active').checked         = true;
        document.getElementById('mem_existing_photo').value   = '';
        document.getElementById('mem_photo_prev').innerHTML   = '';
        document.getElementById('mem_photo_note').textContent = '';
        document.getElementById('mem_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('memFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ समिति सदस्य';
    }
    var btnAddMember = document.getElementById('btnAddMember');
    var btnCancelMember = document.getElementById('btnCancelMember');
    if (btnAddMember) btnAddMember.addEventListener('click', function() { clearMember(); showPanel(); });
    if (btnAddCmt)    btnAddCmt.addEventListener('click', function() { clearMember(); showPanel(); });
    if (btnCancelMember) btnCancelMember.addEventListener('click', hidePanel);
    document.getElementById('memCancelBtn2').addEventListener('click', hidePanel);
    document.querySelectorAll('.btn-edit-member').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var m;
            try { m = JSON.parse(this.dataset.member); } catch(e) { return; }
            document.getElementById('mem_action').value           = 'edit_member';
            document.getElementById('mem_id').value               = m.id;
            document.getElementById('mem_tenure_id').value        = m.tenure_id;
            document.getElementById('mem_name').value             = m.name;
            document.getElementById('mem_name_en').value          = m.name_en || '';
            document.getElementById('mem_position').value         = m.position;
            document.getElementById('mem_position_en').value      = m.position_en || '';
            document.getElementById('mem_phone').value            = m.phone || '';
            document.getElementById('mem_email').value            = m.email || '';
            document.getElementById('mem_address').value          = m.address || '';
            document.getElementById('mem_order').value            = m.display_order || 0;
            document.getElementById('mem_active').checked         = m.is_active == 1;
            document.getElementById('mem_existing_photo').value   = m.photo || '';
            document.getElementById('mem_photo_note').textContent = m.photo ? ' — नयाँ नचुने भने पुरानै रहन्छ' : '';
            document.getElementById('mem_photo_prev').innerHTML   = m.photo
                ? '<img src="../' + m.photo + '" class="cmt-preview-img cmt-preview-img-cyan">'
                : '';
            document.getElementById('mem_submit').innerHTML = '<i class="fas fa-save me-2"></i>अपडेट गर्नुहोस्';
            document.getElementById('memFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>सदस्य सम्पादन';
            showPanel();
        });
    });
});
function previewMemPhoto(input) {
    var prev = document.getElementById('mem_photo_prev');
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            prev.innerHTML = '<img src="' + e.target.result + '" class="cmt-preview-img cmt-preview-img-green">';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
