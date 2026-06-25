<?php
/**
 * शाखा व्यवस्थापन — Service Centers / Branch Management
 * Tab UI: सूची + Add/Edit form (modal popup हटाइएको)
 */
$pageTitle = 'शाखा व्यवस्थापन';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add' || $action === 'edit') {
            $id   = $_POST['id'] ?? null;
            $data = [
                clean_text($_POST['name']          ?? '', 200),
                clean_text($_POST['name_np']        ?? '', 200),
                clean_text($_POST['address']        ?? '', 500),
                preg_replace('/[^0-9]/', '', clean_text($_POST['phone']          ?? '', 20)),
                strtolower(clean_text($_POST['email']          ?? '', 254)),
                clean_text($_POST['province']       ?? '', 80),
                clean_text($_POST['opening_hours']  ?? '', 200),
                clean_text($_POST['map_url']        ?? '', 2000),
                isset($_POST['is_main_branch']) ? 1 : 0,
                isset($_POST['is_active'])      ? 1 : 0,
                (int)($_POST['display_order']   ?? 0),
            ];
            if ($action === 'add') {
                $db->prepare("INSERT INTO service_centers (name, name_np, address, phone, email, province, opening_hours, map_url, is_main_branch, is_active, display_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute($data);
                setFlash('success', 'शाखा थपियो।');
            } else {
                $data[] = $id;
                $db->prepare("UPDATE service_centers SET name=?, name_np=?, address=?, phone=?, email=?, province=?, opening_hours=?, map_url=?, is_main_branch=?, is_active=?, display_order=? WHERE id=?")
                   ->execute($data);
                setFlash('success', 'शाखा अपडेट भयो।');
            }
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM service_centers WHERE id=?")->execute([$_POST['id']]);
            setFlash('success', 'शाखा मेटाइयो।');
        }
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो।');
    }
    redirect('service-centers.php');
}

try { $centers = $db->query("SELECT * FROM service_centers ORDER BY display_order, name")->fetchAll(); }
catch (Exception $e) { $centers = []; }

$scPart = adminPartitionRowsByIsActive($centers);
$centersLive = $scPart['live'];
$centersArch = $scPart['archived'];

$provinces = ['1'=>'प्रदेश नं. १','2'=>'मधेश','3'=>'बागमती','4'=>'गण्डकी','5'=>'लुम्बिनी','6'=>'कर्णाली','7'=>'सुदूरपश्चिम'];
?>

<?php echo adminPageHeader(
    'शाखा व्यवस्थापन',
    'fa-map-marker-alt',
    'संस्थाका कार्यालय तथा शाखाहरू।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($centers) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="fas fa-check-circle me-1"></i>सक्रिय: ' . count($centersLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="fas fa-archive me-1"></i>अभिलेख: ' . count($centersArch) . '</span>'
); ?>

<?php $flash = getFlash(); if ($flash): ?>
<div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3">
    <i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i>
    <?php echo htmlspecialchars($flash['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sc-list" id="sc-list-btn" title="सक्रिय / जम्मा">
            <i class="fas fa-list me-2"></i>शाखा सूची
            <span class="badge bg-success ms-1"><?php echo count($centersLive); ?> / <?php echo count($centers); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sc-form" id="sc-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="scFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="sc-list" style="border-top-left-radius:0!important;">
        <div class="card admin-table-card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3" style="flex-wrap:wrap">
                <div class="input-group input-group-sm" style="max-width:300px">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="नाम, विवरण अनुसार खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
                    <?php echo adminListSubtabPills('sc-sub', count($centersLive), count($centersArch)); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="sc-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">शाखाको नाम</th>
                                <th>ठेगाना</th>
                                <th width="130">फोन</th>
                                <th width="110" class="text-center">प्रदेश</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($centers)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-map-marker-alt fa-3x mb-2 d-block opacity-25"></i>
                                कुनै शाखा छैन। माथिको "नयाँ थप्नुहोस्" बटन थिच्नुहोस्।
                            </td></tr>
                            <?php elseif (empty($centersLive)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-check-circle fa-3x mb-2 d-block opacity-25 text-success"></i>
                                सक्रिय शाखा छैन। अभिलेख हेर्नुहोस्।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($centersLive as $c): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-semibold">
                                        <?php echo htmlspecialchars($c['name_np'] ?: $c['name']); ?>
                                        <?php if ($c['is_main_branch']): ?><span class="badge bg-primary ms-1">मुख्य</span><?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?php echo htmlspecialchars($c['name']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($c['address']); ?></td>
                                <td><?php echo htmlspecialchars($c['phone']); ?></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $provinces[$c['province']] ?? $c['province']; ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $c['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $c['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-sc"
                                            data-id="<?php echo $c['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>"
                                            data-name-np="<?php echo htmlspecialchars($c['name_np'] ?? '', ENT_QUOTES); ?>"
                                            data-address="<?php echo htmlspecialchars($c['address'] ?? '', ENT_QUOTES); ?>"
                                            data-phone="<?php echo htmlspecialchars($c['phone'] ?? '', ENT_QUOTES); ?>"
                                            data-email="<?php echo htmlspecialchars($c['email'] ?? '', ENT_QUOTES); ?>"
                                            data-province="<?php echo htmlspecialchars($c['province'] ?? '', ENT_QUOTES); ?>"
                                            data-hours="<?php echo htmlspecialchars($c['opening_hours'] ?? '', ENT_QUOTES); ?>"
                                            data-map="<?php echo htmlspecialchars($c['map_url'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $c['display_order']; ?>"
                                            data-main="<?php echo $c['is_main_branch']; ?>"
                                            data-active="<?php echo $c['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('के तपाईं यो शाखा मेटाउन निश्चित हुनुहुन्छ?')">
    <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="sc-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">शाखाको नाम</th>
                                <th>ठेगाना</th>
                                <th width="130">फोन</th>
                                <th width="110" class="text-center">प्रदेश</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($centersArch)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-2 d-block opacity-25"></i>
                                अभिलेखमा कुनै शाखा छैन।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($centersArch as $c): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-semibold">
                                        <?php echo htmlspecialchars($c['name_np'] ?: $c['name']); ?>
                                        <?php if ($c['is_main_branch']): ?><span class="badge bg-primary ms-1">मुख्य</span><?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?php echo htmlspecialchars($c['name']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($c['address']); ?></td>
                                <td><?php echo htmlspecialchars($c['phone']); ?></td>
                                <td class="text-center"><span class="badge bg-light text-dark border"><?php echo $provinces[$c['province']] ?? $c['province']; ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $c['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $c['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-sc"
                                            data-id="<?php echo $c['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>"
                                            data-name-np="<?php echo htmlspecialchars($c['name_np'] ?? '', ENT_QUOTES); ?>"
                                            data-address="<?php echo htmlspecialchars($c['address'] ?? '', ENT_QUOTES); ?>"
                                            data-phone="<?php echo htmlspecialchars($c['phone'] ?? '', ENT_QUOTES); ?>"
                                            data-email="<?php echo htmlspecialchars($c['email'] ?? '', ENT_QUOTES); ?>"
                                            data-province="<?php echo htmlspecialchars($c['province'] ?? '', ENT_QUOTES); ?>"
                                            data-hours="<?php echo htmlspecialchars($c['opening_hours'] ?? '', ENT_QUOTES); ?>"
                                            data-map="<?php echo htmlspecialchars($c['map_url'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $c['display_order']; ?>"
                                            data-main="<?php echo $c['is_main_branch']; ?>"
                                            data-active="<?php echo $c['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('के तपाईं यो शाखा मेटाउन निश्चित हुनुहुन्छ?')">
    <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
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
    <div class="tab-pane fade" id="sc-form">
        <div class="card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:linear-gradient(135deg,var(--primary-color),var(--primary-light));color:#fff;">
                <h5 class="mb-0 fw-bold" id="scFormTitle">
                    <i class="fas fa-plus-circle me-2"></i>नयाँ शाखा थप्नुहोस्
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelSc">
                    <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="scForm" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" id="scf_action" value="add">
                    <input type="hidden" name="id" id="scf_id" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">शाखाको नाम (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="name_np" id="scf_name_np" class="form-control admin-fancy-input" required placeholder="शाखाको नाम नेपालीमा">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Branch Name (English)</label>
                            <input type="text" name="name" id="scf_name" class="form-control admin-fancy-input" placeholder="Branch name in English">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success">ठेगाना</label>
                            <input type="text" name="address" id="scf_address" class="form-control admin-fancy-input" placeholder="पूरा ठेगाना">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">फोन</label>
                            <input type="text" name="phone" id="scf_phone" class="form-control admin-fancy-input" placeholder="०१-XXXXXXX">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">इमेल</label>
                            <input type="email" name="email" id="scf_email" class="form-control admin-fancy-input" placeholder="branch@example.com">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">प्रदेश</label>
                            <select name="province" id="scf_province" class="form-select admin-fancy-input">
                                <option value="">छान्नुहोस्</option>
                                <?php foreach ($provinces as $k => $v): ?>
                                <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">कार्यालय समय</label>
                            <input type="text" name="opening_hours" id="scf_hours" class="form-control admin-fancy-input" placeholder="आइत–शुक्र, बिहान ९–साँझ ५">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">प्रदर्शन क्रम</label>
                            <input type="number" name="display_order" id="scf_order" class="form-control admin-fancy-input" value="0" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">Google Map URL</label>
                            <input type="url" name="map_url" id="scf_map" class="form-control admin-fancy-input" placeholder="https://maps.google.com/...">
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_main_branch" id="scf_main">
                                <label class="form-check-label fw-semibold" for="scf_main">मुख्य शाखा</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="scf_active" checked>
                                <label class="form-check-label fw-semibold" for="scf_active">सक्रिय</label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="scf_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                        </button>
                        <button type="button" id="scf_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i>रद्द
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div><!-- end tab-content -->

<script>
document.addEventListener('DOMContentLoaded', function() {

    var listBtn = document.getElementById('sc-list-btn');
    var formBtn = document.getElementById('sc-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('scf_action').value  = 'add';
        document.getElementById('scf_id').value      = '';
        document.getElementById('scf_name').value    = '';
        document.getElementById('scf_name_np').value = '';
        document.getElementById('scf_address').value = '';
        document.getElementById('scf_phone').value   = '';
        document.getElementById('scf_email').value   = '';
        document.getElementById('scf_hours').value   = '';
        document.getElementById('scf_map').value     = '';
        document.getElementById('scf_order').value   = '0';
        document.getElementById('scf_main').checked  = false;
        document.getElementById('scf_active').checked = true;
        document.getElementById('scf_province').selectedIndex = 0;
        document.getElementById('scf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('scFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ शाखा थप्नुहोस्';
        document.getElementById('scFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
    }

    var btnAddCenter = document.getElementById('btnAddCenter');
    if (btnAddCenter) btnAddCenter.addEventListener('click', function() { clearForm(); switchToForm(); });

    ['btnCancelSc','scf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    document.querySelectorAll('.btn-edit-sc').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('scf_action').value  = 'edit';
            document.getElementById('scf_id').value      = d.id;
            document.getElementById('scf_name').value    = d.name;
            document.getElementById('scf_name_np').value = d.nameNp || '';
            document.getElementById('scf_address').value = d.address || '';
            document.getElementById('scf_phone').value   = d.phone || '';
            document.getElementById('scf_email').value   = d.email || '';
            document.getElementById('scf_hours').value   = d.hours || '';
            document.getElementById('scf_map').value     = d.map || '';
            document.getElementById('scf_order').value   = d.order || 0;
            document.getElementById('scf_main').checked   = d.main === '1';
            document.getElementById('scf_active').checked = d.active === '1';
            var sel = document.getElementById('scf_province');
            for (var i=0; i<sel.options.length; i++) {
                if (sel.options[i].value === d.province) { sel.selectedIndex = i; break; }
            }
            document.getElementById('scf_submit').innerHTML = '<i class="fas fa-save me-2"></i>अपडेट गर्नुहोस्';
            document.getElementById('scFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>शाखा सम्पादन';
            document.getElementById('scFormTabLabel').textContent = 'सम्पादन';
            switchToForm();
        });
    });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
