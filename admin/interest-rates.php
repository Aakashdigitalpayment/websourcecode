<?php
/**
 * ब्याज दर व्यवस्थापन — Interest Rates Management
 * बचत र ऋणको ब्याज दरहरू — Tab UI (सूची + Form Tab)
 */
$pageTitle = 'ब्याज दर व्यवस्थापन';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$action = $_POST['action'] ?? 'list';
$id = intval($_POST['id'] ?? 0) ?: null;
$category = $_POST['category'] ?? $_GET['category'] ?? 'saving';
if (!in_array($category, ['saving', 'loan'], true)) {
    $category = 'saving';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'delete') {
    $name         = clean_text($_POST['name']         ?? '');
    $nameNp       = clean_text($_POST['name_np']      ?? '');
    $rate         = floatval($_POST['rate']         ?? 0);
    $cat          = clean_text($_POST['category']     ?? 'saving');
    if (!in_array($cat, ['saving', 'loan'], true)) {
        $cat = 'saving';
    }
    $description  = clean_text($_POST['description']  ?? '');
    $isActive     = isset($_POST['is_active']) ? 1 : 0;
    $displayOrder = intval($_POST['display_order']  ?? 0);

    try {
        $db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();
        if (!empty($_POST['rate_id'])) {
            $rateId = (int)$_POST['rate_id'];
            $db->prepare("UPDATE interest_rates SET category=?, name=?, name_np=?, rate=?, description=?, is_active=?, display_order=? WHERE id=?")
               ->execute([$cat, $name, $nameNp, $rate, $description, $isActive, $displayOrder, $rateId]);
            setFlash('success', 'ब्याज दर अपडेट भयो।');
        } else {
            $db->prepare("INSERT INTO interest_rates (category, name, name_np, rate, description, is_active, display_order) VALUES (?,?,?,?,?,?,?)")
               ->execute([$cat, $name, $nameNp, $rate, $description, $isActive, $displayOrder]);
            setFlash('success', 'नयाँ ब्याज दर थपियो।');
        }
        redirect('interest-rates.php?category=' . $cat);
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो।');
        redirect('interest-rates.php');
    }
}

if ($action === 'delete' && $id) {
    try {
        $db = getDB();
        $db->prepare("DELETE FROM interest_rates WHERE id=?")->execute([$id]);
        setFlash('success', 'ब्याज दर मेटाइयो।');
    } catch (Exception $e) { setFlash('error', 'मेटाउन सकिएन।'); }
    redirect('interest-rates.php?category=' . $category);
}

$savingRates = [];
$loanRates   = [];
try {
    $db          = getDB();
    $savingRates = $db->query("SELECT * FROM interest_rates WHERE category='saving' ORDER BY display_order, id")->fetchAll();
    $loanRates   = $db->query("SELECT * FROM interest_rates WHERE category='loan'   ORDER BY display_order, id")->fetchAll();
} catch (Exception $e) {}

$totalRates = count($savingRates) + count($loanRates);
$flash = getFlash();
?>

<?php echo adminPageHeader(
    'ब्याज दर व्यवस्थापन', 'fa-percent',
    'बचत, ऋण र अन्य ब्याज दरहरू।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . $totalRates . ' ब्याज दर</span>'
);
?>
<?php echo adminHelpTip('यो पृष्ठबाट Savings र Loan को ब्याज दर अपडेट गर्न सकिन्छ।', ['दर बदल्न: सम्बन्धित row को Edit बटन थिच्नुहोस्।', 'नयाँ category थप्न: "+" बटन थिच्नुहोस्।', 'परिवर्तन live site मा तुरुन्त देखिन्छ।']); ?>

<?php if (!empty($flash)) { echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']); } ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0" id="rateTabs">
    <li class="nav-item">
        <button class="nav-link <?php echo $category !== 'loan' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#panel-saving" id="tab-saving-btn">
            <i class="fas fa-piggy-bank me-2 text-success"></i>बचत ब्याज दर
            <span class="badge bg-success ms-1"><?php echo count($savingRates); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?php echo $category === 'loan' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#panel-loan" id="tab-loan-btn">
            <i class="fas fa-hand-holding-usd me-2 text-primary"></i>ऋण ब्याज दर
            <span class="badge bg-primary ms-1"><?php echo count($loanRates); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#panel-rate-form" id="tab-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="rateFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB: बचत ══ -->
    <div class="tab-pane fade <?php echo $category !== 'loan' ? 'show active' : ''; ?>" id="panel-saving">
        <div class="card admin-table-card ir-flat-top">
            <div class="card-header gradient-card-header d-flex align-items-center justify-content-between ir-head-primary">
                <h5 class="mb-0 text-white fw-bold"><i class="fas fa-piggy-bank me-2"></i>बचत ब्याज दर</h5>
                <button class="btn btn-outline-light btn-sm px-3 fw-semibold add-rate-btn" data-category="saving">
                    <i class="fas fa-plus me-1"></i>बचत दर थप्नुहोस्
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr>
                            <th class="ps-3" width="50">#</th>
                            <th>नाम</th>
                            <th width="130" class="text-center">ब्याज दर (%)</th>
                            <th width="90" class="text-center">स्थिति</th>
                            <th width="140" class="text-center">कार्य</th>
                        </tr></thead>
                        <tbody>
                            <?php if (empty($savingRates)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fas fa-piggy-bank fa-3x mb-2 d-block opacity-25"></i>बचत ब्याज दर छैन।</td></tr>
                            <?php endif; ?>
                            <?php foreach ($savingRates as $i => $item): ?>
                            <tr>
                                <td class="ps-3 text-muted"><?php echo $i+1; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($item['name_np'] ?: $item['name']); ?></div>
                                    <?php if ($item['description']): ?><small class="text-muted"><?php echo htmlspecialchars($item['description']); ?></small><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge fs-6 fw-bold px-3 py-2 ir-rate-badge">
                                        <?php echo number_format($item['rate'], 2); ?>%
                                    </span>
                                </td>
                                <td class="text-center"><span class="badge bg-<?php echo $item['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $item['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-rate"
                                            data-id="<?php echo $item['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>"
                                            data-name-np="<?php echo htmlspecialchars($item['name_np'] ?? '', ENT_QUOTES); ?>"
                                            data-rate="<?php echo $item['rate']; ?>"
                                            data-category="saving"
                                            data-description="<?php echo htmlspecialchars($item['description'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $item['display_order']; ?>"
                                            data-active="<?php echo $item['is_active']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="ir-inline-form" onsubmit="return confirm('यो ब्याजदर मेटाउने हो?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                        <input type="hidden" name="category" value="saving">
                                        <?php echo csrfField(); ?>
                                        <button type="submit" class="btn btn-sm btn-danger" title="मेटाउनुहोस्">
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

    <!-- ══ TAB: ऋण ══ -->
    <div class="tab-pane fade <?php echo $category === 'loan' ? 'show active' : ''; ?>" id="panel-loan">
        <div class="card admin-table-card ir-flat-top">
            <div class="card-header d-flex align-items-center justify-content-between ir-head-loan ir-head-theme">
                <h5 class="mb-0 text-white fw-bold"><i class="fas fa-hand-holding-usd me-2"></i>ऋण ब्याज दर</h5>
                <button class="btn btn-outline-light btn-sm px-3 fw-semibold add-rate-btn" data-category="loan">
                    <i class="fas fa-plus me-1"></i>ऋण दर थप्नुहोस्
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr>
                            <th class="ps-3" width="50">#</th>
                            <th>नाम</th>
                            <th width="130" class="text-center">ब्याज दर (%)</th>
                            <th width="90" class="text-center">स्थिति</th>
                            <th width="140" class="text-center">कार्य</th>
                        </tr></thead>
                        <tbody>
                            <?php if (empty($loanRates)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fas fa-hand-holding-usd fa-3x mb-2 d-block opacity-25"></i>ऋण ब्याज दर छैन।</td></tr>
                            <?php endif; ?>
                            <?php foreach ($loanRates as $i => $item): ?>
                            <tr>
                                <td class="ps-3 text-muted"><?php echo $i+1; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($item['name_np'] ?: $item['name']); ?></div>
                                    <?php if ($item['description']): ?><small class="text-muted"><?php echo htmlspecialchars($item['description']); ?></small><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge fs-6 fw-bold px-3 py-2 ir-rate-badge">
                                        <?php echo number_format($item['rate'], 2); ?>%
                                    </span>
                                </td>
                                <td class="text-center"><span class="badge bg-<?php echo $item['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $item['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-rate"
                                            data-id="<?php echo $item['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>"
                                            data-name-np="<?php echo htmlspecialchars($item['name_np'] ?? '', ENT_QUOTES); ?>"
                                            data-rate="<?php echo $item['rate']; ?>"
                                            data-category="loan"
                                            data-description="<?php echo htmlspecialchars($item['description'] ?? '', ENT_QUOTES); ?>"
                                            data-order="<?php echo $item['display_order']; ?>"
                                            data-active="<?php echo $item['is_active']; ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" class="ir-inline-form" onsubmit="return confirm('यो ब्याजदर मेटाउने हो?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                        <input type="hidden" name="category" value="loan">
                                        <?php echo csrfField(); ?>
                                        <button type="submit" class="btn btn-sm btn-danger" title="मेटाउनुहोस्">
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

    <!-- ══ TAB 3: Add / Edit Form ══ -->
    <div class="tab-pane fade" id="panel-rate-form">
        <div class="card ir-flat-top">
            <div class="card-header d-flex justify-content-between align-items-center ir-head-primary ir-head-theme">
                <h5 class="mb-0 fw-bold" id="rateFormTitle">
                    <i class="fas fa-plus-circle me-2"></i>नयाँ ब्याज दर थप्नुहोस्
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelRate">
                    <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="interest-rates.php" id="rateForm" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
                    <input type="hidden" name="rate_id" id="fld_rate_id" value="">
                    <input type="hidden" name="category" id="fld_category" value="saving">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success">वर्ग <span class="text-danger">*</span></label>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-success flex-fill cat-btn" data-val="saving" id="catSaving">
                                    <i class="fas fa-piggy-bank me-1"></i>बचत (Saving)
                                </button>
                                <button type="button" class="btn btn-outline-primary flex-fill cat-btn" data-val="loan" id="catLoan">
                                    <i class="fas fa-hand-holding-usd me-1"></i>ऋण (Loan)
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">नाम (English) <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="fld_name" class="form-control admin-fancy-input" placeholder="e.g. Regular Saving" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">नाम (नेपाली)</label>
                            <input type="text" name="name_np" id="fld_name_np" class="form-control admin-fancy-input" placeholder="साधारण बचत">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold text-success">ब्याज दर (%) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="rate" id="fld_rate" class="form-control admin-fancy-input" step="0.01" min="0" max="100" placeholder="0.00" required>
                                <span class="input-group-text bg-success text-white border-success">%</span>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success">प्रदर्शन क्रम</label>
                            <input type="number" name="display_order" id="fld_order" class="form-control admin-fancy-input" min="0" value="0">
                        </div>
                        <div class="col-md-4 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="fld_active" checked>
                                <label class="form-check-label fw-semibold" for="fld_active">सक्रिय</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success">विवरण / नोट</label>
                            <input type="text" name="description" id="fld_desc" class="form-control admin-fancy-input" placeholder="थप विवरण (वैकल्पिक)">
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="rate_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                        </button>
                        <button type="button" id="rate_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i>रद्द
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    var tabSaving = document.getElementById('tab-saving-btn');
    var tabLoan   = document.getElementById('tab-loan-btn');
    var tabForm   = document.getElementById('tab-form-btn');

    /* adminSwitchTab(showEl, hideEl) — admin.js मा defined; fallback to Bootstrap Tab */
    function _switchTab(showEl, hideEl) {
        if (!showEl) return;
        if (typeof adminSwitchTab === 'function') {
            adminSwitchTab(showEl, hideEl || tabSaving);
        } else if (window.bootstrap && bootstrap.Tab) {
            try { bootstrap.Tab.getOrCreateInstance(showEl).show(); } catch(e) {}
        } else {
            showEl.click();
        }
    }
    function switchToForm() {
        var hideEl = (document.getElementById('fld_category').value === 'loan') ? tabLoan : tabSaving;
        _switchTab(tabForm, hideEl);
    }

    function setCategory(cat) {
        document.getElementById('fld_category').value = cat;
        if (cat === 'saving') {
            document.getElementById('catSaving').className = 'btn btn-success flex-fill cat-btn';
            document.getElementById('catLoan').className   = 'btn btn-outline-primary flex-fill cat-btn';
        } else {
            document.getElementById('catLoan').className   = 'btn btn-primary flex-fill cat-btn';
            document.getElementById('catSaving').className = 'btn btn-outline-success flex-fill cat-btn';
        }
    }

    function clearForm(cat) {
        cat = cat || 'saving';
        document.getElementById('rateForm').reset();
        document.getElementById('fld_rate_id').value = '';
        document.getElementById('fld_active').checked = true;
        setCategory(cat);
        document.getElementById('rate_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('rateFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ ब्याज दर थप्नुहोस्';
        document.getElementById('rateFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
    }

    /* "बचत/ऋण दर थप्नुहोस्" buttons (top-right) */
    document.querySelectorAll('.add-rate-btn').forEach(function(btn) {
        btn.addEventListener('click', function() { clearForm(this.dataset.category); switchToForm(); });
    });

    /* Optional global "Add" button (if exists) */
    var btnAdd = document.getElementById('btnAddRate');
    if (btnAdd) btnAdd.addEventListener('click', function() { clearForm('saving'); switchToForm(); });

    document.querySelectorAll('.cat-btn').forEach(function(btn) {
        btn.addEventListener('click', function() { setCategory(this.dataset.val); });
    });

    ['btnCancelRate','rate_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() {
            var cat = document.getElementById('fld_category').value || 'saving';
            if (cat === 'loan' && tabLoan) _switchTab(tabLoan, tabForm);
            else if (tabSaving)            _switchTab(tabSaving, tabForm);
        });
    });

    document.querySelectorAll('.btn-edit-rate').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('fld_rate_id').value = d.id;
            document.getElementById('fld_name').value    = d.name;
            document.getElementById('fld_name_np').value = d.nameNp || '';
            document.getElementById('fld_rate').value    = d.rate;
            document.getElementById('fld_desc').value    = d.description || '';
            document.getElementById('fld_order').value   = d.order || 0;
            document.getElementById('fld_active').checked = d.active === '1';
            setCategory(d.category);
            document.getElementById('rate_submit').innerHTML = '<i class="fas fa-save me-2"></i>अपडेट गर्नुहोस्';
            document.getElementById('rateFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>ब्याज दर सम्पादन';
            document.getElementById('rateFormTabLabel').textContent = 'सम्पादन';
            switchToForm();
        });
    });

    /* URL मा category=loan छ भने loan tab active गर्ने */
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('category') === 'loan' && tabLoan) adminSwitchTab(tabLoan);
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
