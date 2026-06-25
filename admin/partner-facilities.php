<?php
/**
 * Admin: साझेदार सुविधा व्यवस्थापन (Partner Facilities CRUD)
 * Pattern: services.php जस्तै — Tab UI (List + Add/Edit)
 */
$pageTitle   = 'साझेदार सुविधा व्यवस्थापन';
$currentPage = 'partner-facilities';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/partner-facilities-tables.php';

$db = getDB();
ensurePartnerFacilitiesTables($db);

/* ── CSRF check (admin-header already handles POST CSRF globally) ── */
$success = '';
$error   = '';

/* ── ADD / EDIT ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if (in_array($act, ['add','edit','delete'])) {
        try {
            if ($act === 'add' || $act === 'edit') {
                $pname    = clean_text($_POST['partner_name']    ?? '');
                $location = clean_text($_POST['location']        ?? '');
                $ftype    = clean_text($_POST['facility_type']   ?? '');
                $discount = floatval($_POST['discount_percent'] ?? 0);
                $desc     = clean_text($_POST['description']     ?? '');
                $order    = (int)($_POST['display_order']      ?? 0);
                $active   = isset($_POST['is_active']) ? 1 : 0;

                if (empty($pname)) throw new \Exception('साझेदार संस्थाको नाम अनिवार्य छ।');

                if ($act === 'add') {
                    $db->prepare("INSERT INTO partner_facilities
                        (partner_name, location, facility_type, discount_percent, description, display_order, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?)")
                       ->execute([$pname, $location, $ftype, $discount, $desc, $order, $active]);
                    $success = 'साझेदार सुविधा सफलतापूर्वक थपियो।';
                } else {
                    $db->prepare("UPDATE partner_facilities SET
                        partner_name=?, location=?, facility_type=?, discount_percent=?,
                        description=?, display_order=?, is_active=? WHERE id=?")
                       ->execute([$pname, $location, $ftype, $discount, $desc, $order, $active, (int)$_POST['id']]);
                    $success = 'साझेदार सुविधा अपडेट भयो।';
                }
            } elseif ($act === 'delete') {
                $db->prepare("DELETE FROM partner_facilities WHERE id=?")->execute([(int)$_POST['id']]);
                $success = 'साझेदार सुविधा मेटाइयो।';
            }
        } catch (\Exception $e) {
            $error = $e->getMessage() ?: 'त्रुटि भयो। कृपया पुनः प्रयास गर्नुहोस्।';
        }
    }
}

try {
    $facilities = $db->query("SELECT * FROM partner_facilities ORDER BY display_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    /* ── Facility types for filter dropdown ── */
    $types = array_unique(array_filter(array_column($facilities, 'facility_type')));
} catch (\Exception $e) { $facilities = []; $types = []; }

/* ── Distinct types for form datalist ── */
$typeFilter = trim((string)($_GET['type'] ?? ''));
if ($typeFilter !== '' && !in_array($typeFilter, $types, true)) {
    $typeFilter = '';
}
$filtered = $typeFilter
    ? array_filter($facilities, fn($f) => $f['facility_type'] === $typeFilter)
    : $facilities;

$pfPart = adminPartitionRowsByIsActive($facilities);
$facilitiesLive = $pfPart['live'];
$facilitiesArch = $pfPart['archived'];

?>

<?php echo adminPageHeader(
    'साझेदार सुविधा व्यवस्थापन',
    'fa-handshake',
    'सदस्यहरूले पाउने छुट तथा सुविधाहरू — साझेदार संस्थाको सूची',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2">
        <i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($facilities) . '
    </span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="fas fa-check-circle me-1"></i>सक्रिय: ' . count($facilitiesLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="fas fa-archive me-1"></i>अभिलेख: ' . count($facilitiesArch) . '</span>'
); ?>

<?php echo adminAlert('success', $success) . adminAlert('danger', $error); ?>

<!-- ── Tabs ── -->
<ul class="nav nav-tabs admin-nav-tabs mb-0" id="pfTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pf-list" id="pf-list-btn" title="सक्रिय / जम्मा">
            <i class="fas fa-list me-2"></i>सुविधा सूची
            <span class="badge bg-success ms-1"><?php echo count($facilitiesLive); ?> / <?php echo count($facilities); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#pf-form" id="pf-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="pfFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="pf-list">
        <div class="card admin-table-card svc-flat-top-card">

            <!-- Filter bar -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3 flex-wrap">
                <div class="input-group input-group-sm pf-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 pf-list-search"
                           placeholder="संस्था, स्थान, विवरण खोज्नुहोस्..." autocomplete="off">
                </div>
                <select class="form-select form-select-sm pf-type-filter" id="pfTypeFilter">
                    <option value="">— सुविधा प्रकार —</option>
                    <?php foreach ($types as $t): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $typeFilter===$t?'selected':''; ?>>
                        <?php echo htmlspecialchars($t); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted search-count"></small>
            </div>

            <div class="card-body p-0">
                    <?php echo adminListSubtabPills('pf-sub', count($facilitiesLive), count($facilitiesArch)); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="pf-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 pf-data-table coop-table">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" width="50">क्र.स.</th>
                                <th>साझेदार संस्था</th>
                                <th>स्थान</th>
                                <th>सुविधा प्रकार</th>
                                <th width="100" class="text-center">छुट (%)</th>
                                <th>विवरण</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="130" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($facilities)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">
                                <i class="fas fa-handshake fa-3x mb-2 d-block opacity-25"></i>
                                कुनै साझेदार सुविधा थपिएको छैन।<br>
                                <button type="button" class="btn btn-sm btn-success mt-2" onclick="document.getElementById('pf-form-btn').click()">
                                    <i class="fas fa-plus me-1"></i>पहिलो सुविधा थप्नुहोस्
                                </button>
                            </td></tr>
                            <?php elseif (empty($facilitiesLive)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">
                                <i class="fas fa-check-circle fa-3x mb-2 d-block opacity-25 text-success"></i>
                                सक्रिय सुविधा छैन। अभिलेख हेर्नुहोस्।
                            </td></tr>
                            <?php endif; ?>
                            <?php $sn = 1; foreach ($facilitiesLive as $f): ?>
                            <tr data-type="<?php echo htmlspecialchars($f['facility_type']); ?>">
                                <td class="ps-3 text-muted fw-semibold"><?php echo $sn++; ?></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($f['partner_name']); ?></div>
                                </td>
                                <td>
                                    <span class="text-muted"><i class="fas fa-location-dot me-1 text-success pf-location-icon"></i>
                                    <?php echo htmlspecialchars($f['location'] ?: '—'); ?></span>
                                </td>
                                <td>
                                    <?php if ($f['facility_type']): ?>
                                    <span class="badge pf-type-badge">
                                        <?php echo htmlspecialchars($f['facility_type']); ?>
                                    </span>
                                    <?php else: echo '—'; endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($f['discount_percent'] > 0): ?>
                                    <span class="badge bg-warning text-dark fw-bold pf-discount-badge">
                                        <?php echo number_format($f['discount_percent'], 0); ?>%
                                    </span>
                                    <?php else: echo '<span class="text-muted">—</span>'; endif; ?>
                                </td>
                                <td><span class="text-muted"><?php echo htmlspecialchars(mb_substr($f['description'] ?? '', 0, 60)); ?><?php echo mb_strlen($f['description'] ?? '') > 60 ? '…' : ''; ?></span></td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $f['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $f['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-pf"
                                            data-id="<?php echo $f['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($f['partner_name'], ENT_QUOTES); ?>"
                                            data-location="<?php echo htmlspecialchars($f['location'], ENT_QUOTES); ?>"
                                            data-type="<?php echo htmlspecialchars($f['facility_type'], ENT_QUOTES); ?>"
                                            data-discount="<?php echo $f['discount_percent']; ?>"
                                            data-desc="<?php echo htmlspecialchars($f['description'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $f['display_order']; ?>"
                                            data-active="<?php echo $f['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form"
                                          onsubmit="return confirm('के तपाईं यो सुविधा मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="pf-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 pf-data-table coop-table">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" width="50">क्र.स.</th>
                                <th>साझेदार संस्था</th>
                                <th>स्थान</th>
                                <th>सुविधा प्रकार</th>
                                <th width="100" class="text-center">छुट (%)</th>
                                <th>विवरण</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="130" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($facilitiesArch)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-2 d-block opacity-25"></i>
                                अभिलेखमा कुनै सुविधा छैन।
                            </td></tr>
                            <?php endif; ?>
                            <?php $sn = 1; foreach ($facilitiesArch as $f): ?>
                            <tr data-type="<?php echo htmlspecialchars($f['facility_type']); ?>">
                                <td class="ps-3 text-muted fw-semibold"><?php echo $sn++; ?></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($f['partner_name']); ?></div>
                                </td>
                                <td>
                                    <span class="text-muted"><i class="fas fa-location-dot me-1 text-success pf-location-icon"></i>
                                    <?php echo htmlspecialchars($f['location'] ?: '—'); ?></span>
                                </td>
                                <td>
                                    <?php if ($f['facility_type']): ?>
                                    <span class="badge pf-type-badge">
                                        <?php echo htmlspecialchars($f['facility_type']); ?>
                                    </span>
                                    <?php else: echo '—'; endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($f['discount_percent'] > 0): ?>
                                    <span class="badge bg-warning text-dark fw-bold pf-discount-badge">
                                        <?php echo number_format($f['discount_percent'], 0); ?>%
                                    </span>
                                    <?php else: echo '<span class="text-muted">—</span>'; endif; ?>
                                </td>
                                <td><span class="text-muted"><?php echo htmlspecialchars(mb_substr($f['description'] ?? '', 0, 60)); ?><?php echo mb_strlen($f['description'] ?? '') > 60 ? '…' : ''; ?></span></td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $f['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $f['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-pf"
                                            data-id="<?php echo $f['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($f['partner_name'], ENT_QUOTES); ?>"
                                            data-location="<?php echo htmlspecialchars($f['location'], ENT_QUOTES); ?>"
                                            data-type="<?php echo htmlspecialchars($f['facility_type'], ENT_QUOTES); ?>"
                                            data-discount="<?php echo $f['discount_percent']; ?>"
                                            data-desc="<?php echo htmlspecialchars($f['description'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $f['display_order']; ?>"
                                            data-active="<?php echo $f['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="svc-inline-form"
                                          onsubmit="return confirm('के तपाईं यो सुविधा मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्">
                                            <i class="fas fa-trash"></i>
                                        </button>
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
    <div class="tab-pane fade" id="pf-form">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="pfFormTitle">
                    <i class="fas fa-plus-circle me-2"></i>नयाँ साझेदार सुविधा थप्नुहोस्
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelPf">
                    <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="pfForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" id="pff_action" value="add">
                    <input type="hidden" name="id" id="pff_id" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">
                                साझेदार संस्थाको नाम <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="partner_name" id="pff_name" class="form-control admin-fancy-input"
                                   required placeholder="जस्तै: ABC Hospital, XYZ Pharmacy">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">स्थान</label>
                            <input type="text" name="location" id="pff_location" class="form-control admin-fancy-input"
                                   placeholder="जस्तै: काठमाडौं, पोखरा">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">सुविधा प्रकार</label>
                            <input type="text" name="facility_type" id="pff_type" class="form-control admin-fancy-input"
                                   list="pfTypeList" placeholder="जस्तै: स्वास्थ्य, शिक्षा, किराना">
                            <datalist id="pfTypeList">
                                <option value="स्वास्थ्य सेवा">
                                <option value="शिक्षा">
                                <option value="किराना तथा खाद्यान्न">
                                <option value="पोशाक">
                                <option value="यातायात">
                                <option value="होटल तथा खाजा">
                                <option value="फोटो तथा प्रिन्टिङ">
                                <option value="कृषि सामग्री">
                                <option value="इलेक्ट्रोनिक्स">
                                <option value="अन्य">
                                <?php foreach ($types as $t): ?>
                                <option value="<?php echo htmlspecialchars($t); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success">छुट (%) <small class="text-muted fw-normal">— 0 भए छैन</small></label>
                            <div class="input-group">
                                <input type="number" name="discount_percent" id="pff_discount"
                                       class="form-control admin-fancy-input" min="0" max="100" step="0.5"
                                       placeholder="0" value="0">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success">प्रदर्शन क्रम</label>
                            <input type="number" name="display_order" id="pff_order"
                                   class="form-control admin-fancy-input" min="0" value="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success">विवरण</label>
                            <textarea name="description" id="pff_desc" class="form-control admin-fancy-input"
                                      rows="3" placeholder="सुविधाको विस्तृत विवरण — सदस्यले के-के पाउँछन्..."></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active"
                                       id="pff_active" checked>
                                <label class="form-check-label fw-semibold" for="pff_active">
                                    सक्रिय (Public page मा देखिने)
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-success px-4">
                            <i class="fas fa-save me-2"></i><span id="pfSubmitLabel">सुविधा सेभ गर्नुहोस्</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="btnResetPf">
                            <i class="fas fa-rotate-left me-1"></i>Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div><!-- /tab-content -->

<script>
/* ── Client-side search + type filter (खुला उप-ट्याबको पङ्क्ति मात्र) ── */
function pfActiveRows() {
    var pane = document.querySelector('#pf-list .admin-table-subtab-content .tab-pane.active');
    if (!pane) return [];
    return Array.from(pane.querySelectorAll('tbody tr[data-type]'));
}
const searchInp = document.querySelector('#pf-list .pf-list-search');
const typeSelEl = document.getElementById('pfTypeFilter');
const cntEl     = document.querySelector('#pf-list .search-count');

function pfFilter() {
    const q   = (searchInp && searchInp.value || '').toLowerCase();
    const typ = typeSelEl && typeSelEl.value || '';
    let vis = 0;
    let total = 0;
    pfActiveRows().forEach(function (r) {
        total++;
        const txt  = r.textContent.toLowerCase();
        const rtyp = r.dataset.type || '';
        const show = txt.includes(q) && (!typ || rtyp === typ);
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    if (cntEl) cntEl.textContent = vis + ' / ' + total;
}
if (searchInp) searchInp.addEventListener('input', pfFilter);
if (typeSelEl) typeSelEl.addEventListener('change', pfFilter);
document.addEventListener('shown.bs.tab', function (e) {
    var t = e.target && e.target.getAttribute('data-bs-target');
    if (t === '#pf-sub-live' || t === '#pf-sub-arch') pfFilter();
});
pfFilter();

/* ── Edit button → fill form ── */
document.querySelectorAll('.btn-edit-pf').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('pff_action').value   = 'edit';
        document.getElementById('pff_id').value       = this.dataset.id;
        document.getElementById('pff_name').value     = this.dataset.name;
        document.getElementById('pff_location').value = this.dataset.location;
        document.getElementById('pff_type').value     = this.dataset.type;
        document.getElementById('pff_discount').value = this.dataset.discount;
        document.getElementById('pff_desc').value     = this.dataset.desc;
        document.getElementById('pff_order').value    = this.dataset.order;
        document.getElementById('pff_active').checked = this.dataset.active === '1';

        document.getElementById('pfFormTitle').innerHTML =
            '<i class="fas fa-edit me-2"></i>साझेदार सुविधा सम्पादन';
        document.getElementById('pfFormTabLabel').textContent = 'सम्पादन';
        document.getElementById('pfSubmitLabel').textContent  = 'अपडेट गर्नुहोस्';
        document.getElementById('pf-form-btn').click();
    });
});

/* ── Cancel / Reset ── */
document.getElementById('btnCancelPf')?.addEventListener('click', () => {
    document.getElementById('pf-list-btn').click();
});
document.getElementById('btnResetPf')?.addEventListener('click', () => {
    document.getElementById('pff_action').value = 'add';
    document.getElementById('pff_id').value     = '';
    document.getElementById('pfForm').reset();
    document.getElementById('pfFormTitle').innerHTML =
        '<i class="fas fa-plus-circle me-2"></i>नयाँ साझेदार सुविधा थप्नुहोस्';
    document.getElementById('pfFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
    document.getElementById('pfSubmitLabel').textContent  = 'सुविधा सेभ गर्नुहोस्';
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
