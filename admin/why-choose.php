<?php
/**
 * किन हामीलाई छान्ने? — Why Choose Us Management
 * Admin page: CRUD for homepage "Why Choose Us" feature cards
 */
$pageTitle = 'किन हामीलाई छान्ने?';
require_once '../includes/config.php';
if (!isAdminLoggedIn()) redirect(ADMIN_URL . 'index.php');

$db = getDB();

require_once __DIR__ . '/../includes/why-choose-tables.php';
ensureWhyChooseFeaturesTable($db);

/* Insert defaults if empty */
try {
    $cnt = $db->query("SELECT COUNT(*) FROM why_choose_features")->fetchColumn();
    if ($cnt == 0) {
        $defaults = [
            ['fas fa-shield-alt', 'सुरक्षित बचत',       'Safe Savings',         'तपाईंको बचत हामीसँग पूर्ण रूपमा सुरक्षित छ।',     'Your savings are fully secure with us.',       1],
            ['fas fa-percentage',  'आकर्षक ब्याज',       'Attractive Interest',  'बजारमा प्रतिस्पर्धी ब्याज दरहरू।',                'Competitive interest rates in the market.',    2],
            ['fas fa-clock',       'छिटो सेवा',          'Quick Service',        'द्रुत र प्रभावकारी ग्राहक सेवा।',                  'Fast and effective customer service.',         3],
            ['fas fa-users',       'समुदायमा आधारित',    'Community Based',      'समुदायको विकासमा समर्पित।',                        'Dedicated to community development.',          4],
        ];
        $ins = $db->prepare("INSERT INTO why_choose_features (icon, title_np, title_en, desc_np, desc_en, sort_order) VALUES (?,?,?,?,?,?)");
        foreach ($defaults as $d) $ins->execute($d);
    }
} catch (Exception $e) { /* ignore */ }

/* CSRF check */
checkCSRF();
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add' || $action === 'edit') {
            $icon     = clean_text($_POST['icon']     ?? 'fas fa-star');
            $title_np = clean_text($_POST['title_np'] ?? '');
            $title_en = clean_text($_POST['title_en'] ?? $title_np);
            $desc_np  = $_POST['desc_np'] ?? '';
            $desc_en  = $_POST['desc_en'] ?? '';
            $sort     = (int)($_POST['sort_order'] ?? 0);
            $active   = isset($_POST['is_active']) ? 1 : 0;

            if ($action === 'add') {
                $db->prepare("INSERT INTO why_choose_features (icon,title_np,title_en,desc_np,desc_en,sort_order,is_active) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$icon,$title_np,$title_en,$desc_np,$desc_en,$sort,$active]);
                setFlash('success', 'नयाँ कारण थपियो।');
            } else {
                $id = (int)$_POST['id'];
                $db->prepare("UPDATE why_choose_features SET icon=?,title_np=?,title_en=?,desc_np=?,desc_en=?,sort_order=?,is_active=? WHERE id=?")
                   ->execute([$icon,$title_np,$title_en,$desc_np,$desc_en,$sort,$active,$id]);
                setFlash('success', 'कारण अपडेट भयो।');
            }
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM why_choose_features WHERE id=?")->execute([(int)$_POST['id']]);
            setFlash('success', 'कारण हटाइयो।');
        } elseif ($action === 'toggle') {
            $db->prepare('UPDATE why_choose_features SET is_active = 1 - is_active WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
            setFlash('success', 'स्थिति परिवर्तन भयो।');
        }
    } catch (Exception $e) { setFlash('error', 'त्रुटि भयो: ' . $e->getMessage()); }
    redirect('why-choose.php');
}

$features = $db->query("SELECT * FROM why_choose_features ORDER BY sort_order, id")->fetchAll();
$flash = getFlash();

echo adminPageHeader(
    'किन हामीलाई छान्ने?',
    'fa-star',
    'गृहपृष्ठमा देखिने "किन हामीलाई छान्ने?" खण्डका कारणहरू।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2">
        <i class="fas fa-list me-1"></i>जम्मा: ' . count($features) . ' कारणहरू
     </span>'
);
if (!empty($flash)) echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']);
?>

<!-- Popular FA icons quick picker (reference) -->
<div class="alert alert-info py-2 mb-3 small">
    <i class="fas fa-info-circle me-1"></i>
    <strong>Icon classes:</strong>
    <code>fas fa-shield-alt</code> &nbsp;
    <code>fas fa-percentage</code> &nbsp;
    <code>fas fa-clock</code> &nbsp;
    <code>fas fa-users</code> &nbsp;
    <code>fas fa-hand-holding-usd</code> &nbsp;
    <code>fas fa-chart-line</code> &nbsp;
    <code>fas fa-leaf</code> &nbsp;
    <code>fas fa-lock</code> &nbsp;
    <code>fas fa-headset</code> &nbsp;
    <code>fas fa-award</code>
    &nbsp;—&nbsp;
    <a href="https://fontawesome.com/icons" target="_blank" class="alert-link">सबै icon हेर्नुस् <i class="fas fa-external-link-alt"></i></a>
</div>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#wc-list" id="wc-list-btn">
            <i class="fas fa-list me-2"></i>कारणहरूको सूची
            <span class="badge bg-success ms-1"><?php echo count($features); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#wc-form" id="wc-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="wcFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- LIST TAB -->
    <div class="tab-pane fade show active" id="wc-list">
        <div class="table-responsive">
            <table class="table table-hover align-middle admin-table mb-0 coop-table">
                <thead>
                    <tr>
                        <th width="50">क्र.</th>
                        <th width="60">Icon</th>
                        <th>शीर्षक (नेपाली)</th>
                        <th>विवरण</th>
                        <th width="80" class="text-center">क्रम</th>
                        <th width="90" class="text-center">स्थिति</th>
                        <th width="120" class="text-center">कार्य</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($features)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">कुनै कारण छैन।</td></tr>
                <?php else: foreach ($features as $i => $f): ?>
                    <tr class="<?php echo $f['is_active'] ? '' : 'table-secondary opacity-50'; ?>">
                        <td><?php echo $i + 1; ?></td>
                        <td class="text-center">
                            <span class="d-inline-flex align-items-center justify-content-center rounded-circle text-white"
                                  style="width:38px;height:38px;background:var(--bs-success);">
                                <i class="<?php echo e($f['icon']); ?>"></i>
                            </span>
                        </td>
                        <td>
                            <strong><?php echo e($f['title_np']); ?></strong>
                            <?php if ($f['title_en']): ?><br><small class="text-muted"><?php echo e($f['title_en']); ?></small><?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted"><?php echo e(mb_substr($f['desc_np'] ?? '', 0, 60)) . (mb_strlen($f['desc_np'] ?? '') > 60 ? '…' : ''); ?></small>
                        </td>
                        <td class="text-center"><span class="badge bg-secondary"><?php echo (int)$f['sort_order']; ?></span></td>
                        <td class="text-center">
                            <form method="POST" style="display:inline">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $f['is_active'] ? 'btn-success' : 'btn-outline-secondary'; ?>">
                                    <?php echo $f['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary wc-edit-btn"
                                    data-id="<?php echo $f['id']; ?>"
                                    data-icon="<?php echo e($f['icon']); ?>"
                                    data-title_np="<?php echo e($f['title_np']); ?>"
                                    data-title_en="<?php echo e($f['title_en']); ?>"
                                    data-desc_np="<?php echo e($f['desc_np'] ?? ''); ?>"
                                    data-desc_en="<?php echo e($f['desc_en'] ?? ''); ?>"
                                    data-sort_order="<?php echo (int)$f['sort_order']; ?>"
                                    data-is_active="<?php echo (int)$f['is_active']; ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display:inline"
                                  onsubmit="return confirm('\"<?php echo addslashes($f['title_np']); ?>\" हटाउने?')">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Live Preview -->
        <div class="card mt-4" style="border-left:4px solid var(--primary-color);">
            <div class="card-header" style="background:#f0f9f2;">
                <h6 class="mb-0 text-success"><i class="fas fa-eye me-2"></i>गृहपृष्ठमा यसरी देखिन्छ</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                <?php foreach ($features as $f): if (!$f['is_active']) continue; ?>
                    <div class="col-md-3 col-sm-6">
                        <div class="text-center p-3 border rounded-3 h-100 bg-white">
                            <div class="mx-auto mb-2 d-flex align-items-center justify-content-center rounded-circle text-white"
                                 style="width:52px;height:52px;background:var(--bs-success);">
                                <i class="<?php echo e($f['icon']); ?> fa-lg"></i>
                            </div>
                            <h6 class="mb-1 fw-bold" style="font-size:14px;"><?php echo e($f['title_np']); ?></h6>
                            <p class="mb-0 text-muted" style="font-size:12px;"><?php echo e($f['desc_np'] ?? ''); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD/EDIT FORM TAB -->
    <div class="tab-pane fade" id="wc-form">
        <div class="card border-0">
            <div class="card-body">
                <form method="POST" id="wcForm" class="needs-validation" novalidate>
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" id="wcAction" value="add">
                    <input type="hidden" name="id" id="wcId" value="">

                    <div class="row g-3">
                        <!-- Icon -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Font Awesome Icon Class <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text" id="iconPreview"><i class="fas fa-star"></i></span>
                                <input type="text" name="icon" id="wcIcon" class="form-control"
                                       value="fas fa-star" placeholder="fas fa-shield-alt" required
                                       oninput="document.getElementById('iconPreview').innerHTML='<i class=\''+this.value.trim()+'\'></i>'">
                            </div>
                            <small class="text-muted">जस्तै: <code>fas fa-shield-alt</code></small>
                        </div>

                        <!-- Sort order -->
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">क्रम (Sort)</label>
                            <input type="number" name="sort_order" id="wcSort" class="form-control" value="0" min="0">
                        </div>

                        <!-- Active -->
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check form-switch pb-2">
                                <input type="checkbox" name="is_active" id="wcActive" class="form-check-input" checked>
                                <label for="wcActive" class="form-check-label">सक्रिय</label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">शीर्षक (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="title_np" id="wcTitleNp" class="form-control" required
                                   placeholder="जस्तै: सुरक्षित बचत">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Title (English)</label>
                            <input type="text" name="title_en" id="wcTitleEn" class="form-control"
                                   placeholder="e.g. Safe Savings">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">विवरण (नेपाली)</label>
                            <textarea name="desc_np" id="wcDescNp" class="form-control" rows="3"
                                      placeholder="छोटो विवरण नेपालीमा..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Description (English)</label>
                            <textarea name="desc_en" id="wcDescEn" class="form-control" rows="3"
                                      placeholder="Short description in English..."></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-success px-4">
                            <i class="fas fa-save me-1"></i>
                            <span id="wcSubmitLabel">थप्नुहोस्</span>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="wcReset()">
                            <i class="fas fa-undo me-1"></i> रद्द गर्नुहोस्
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function wcReset() {
    document.getElementById('wcAction').value = 'add';
    document.getElementById('wcId').value = '';
    document.getElementById('wcFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
    document.getElementById('wcSubmitLabel').textContent = 'थप्नुहोस्';
    document.getElementById('wcForm').reset();
    document.getElementById('iconPreview').innerHTML = '<i class="fas fa-star"></i>';
}

document.querySelectorAll('.wc-edit-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var d = this.dataset;
        document.getElementById('wcAction').value = 'edit';
        document.getElementById('wcId').value = d.id;
        document.getElementById('wcIcon').value = d.icon;
        document.getElementById('wcTitleNp').value = d.title_np;
        document.getElementById('wcTitleEn').value = d.title_en;
        document.getElementById('wcDescNp').value = d.desc_np;
        document.getElementById('wcDescEn').value = d.desc_en;
        document.getElementById('wcSort').value = d.sort_order;
        document.getElementById('wcActive').checked = d.is_active == '1';
        document.getElementById('iconPreview').innerHTML = '<i class="' + d.icon + '"></i>';
        document.getElementById('wcFormTabLabel').textContent = 'सम्पादन गर्नुहोस्';
        document.getElementById('wcSubmitLabel').textContent = 'अपडेट गर्नुहोस्';
        document.getElementById('wc-form-btn').click();
        window.scrollTo({top: 0, behavior: 'smooth'});
    });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
