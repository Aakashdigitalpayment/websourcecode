<?php
/**
 * Admin — Digital service types catalog
 */
if (!ob_get_level()) {
    ob_start();
}
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('डिजिटल सेवा प्रकारहरू', 'Digital Service Types');
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/digital-service-requests-tables.php';
require_once __DIR__ . '/../includes/digital-service-types.php';
require_once __DIR__ . '/../includes/auth-roles.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
}

$db = getDB();
ensureDigitalServiceRequestsTables($db);
ensureDigitalServiceTypes($db);
checkCSRF();

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = clean_text($_POST['action'] ?? '', 40);
    try {
        if ($postAction === 'save_type') {
            $tid = (int)($_POST['type_id'] ?? 0);
            $nameNp = clean_text($_POST['name_np'] ?? '', 160);
            $nameEn = clean_text($_POST['name_en'] ?? '', 160);
            $icon = clean_text($_POST['icon'] ?? 'fa-laptop', 80) ?: 'fa-laptop';
            $color = clean_text($_POST['color'] ?? 'var(--primary-color)', 40) ?: 'var(--primary-color)';
            $order = (int)($_POST['display_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $requiresDocument = isset($_POST['requires_document']) ? 1 : 0;

            if ($nameNp === '') {
                setFlash('error', $__t('नाम (नेपाली) अनिवार्य छ।', 'Name (Nepali) is required.'));
                redirect('digital-service-types.php' . ($tid > 0 ? '?edit=' . $tid : ''));
            }

            if ($tid > 0) {
                $db->prepare(
                    'UPDATE digital_service_types SET name_np=?, name_en=?, icon=?, color=?, display_order=?, is_active=?, requires_document=? WHERE id=?'
                )->execute([$nameNp, $nameEn, $icon, $color, $order, $isActive, $requiresDocument, $tid]);
                setFlash('success', $__t('सेवा प्रकार अद्यावधिक भयो।', 'Service type updated.'));
            } else {
                $base = digitalServiceTypeSlugify($nameEn !== '' ? $nameEn : $nameNp, 'svc');
                $slug = $base;
                $n = 0;
                $chk = $db->prepare('SELECT id FROM digital_service_types WHERE slug = ? LIMIT 1');
                while (true) {
                    $chk->execute([$slug]);
                    if (!$chk->fetchColumn()) {
                        break;
                    }
                    $n++;
                    $slug = substr($base, 0, 40) . '-' . $n;
                    if ($n > 50) {
                        $slug = 'svc-' . substr(md5((string)microtime(true)), 0, 10);
                        break;
                    }
                }
                if ($order <= 0) {
                    try {
                        $order = (int)$db->query('SELECT COALESCE(MAX(display_order),0) + 10 FROM digital_service_types')->fetchColumn();
                    } catch (Throwable $e) {
                        $order = 10;
                    }
                }
                $db->prepare(
                    'INSERT INTO digital_service_types (slug, name_np, name_en, icon, color, display_order, is_active, is_builtin, requires_document) VALUES (?,?,?,?,?,?,?,0,?)'
                )->execute([$slug, $nameNp, $nameEn, $icon, $color, $order, $isActive, $requiresDocument]);
                setFlash('success', $__t('नयाँ सेवा प्रकार थपियो।', 'Service type added.'));
            }
            redirect('digital-service-types.php');
        }

        if ($postAction === 'toggle_type') {
            $tid = (int)($_POST['type_id'] ?? 0);
            if ($tid > 0) {
                $db->prepare('UPDATE digital_service_types SET is_active = IF(is_active=1,0,1) WHERE id = ?')->execute([$tid]);
                setFlash('success', $__t('स्थिति बदलियो।', 'Status updated.'));
            }
            redirect('digital-service-types.php');
        }

        if ($postAction === 'delete_type') {
            $tid = (int)($_POST['type_id'] ?? 0);
            if ($tid > 0) {
                $row = null;
                try {
                    $st = $db->prepare('SELECT * FROM digital_service_types WHERE id = ? LIMIT 1');
                    $st->execute([$tid]);
                    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) {
                    $row = null;
                }
                if ($row) {
                    $slug = (string)($row['slug'] ?? '');
                    $inUse = $slug !== '' ? digitalServiceTypeUsageCount($db, $slug) : 0;
                    $isBuiltin = !empty($row['is_builtin']);
                    if ($isBuiltin || $inUse > 0) {
                        $db->prepare('UPDATE digital_service_types SET is_active = 0 WHERE id = ?')->execute([$tid]);
                        setFlash('warning', $__t(
                            'प्रयोगमा / builtin प्रकार — मेटाउन सकिएन, निष्क्रिय गरियो।',
                            'In use or builtin — deactivated instead of deleted.'
                        ));
                    } else {
                        $db->prepare('DELETE FROM digital_service_types WHERE id = ?')->execute([$tid]);
                        setFlash('success', $__t('सेवा प्रकार हटाइयो।', 'Service type deleted.'));
                    }
                }
            }
            redirect('digital-service-types.php');
        }
    } catch (Throwable $e) {
        error_log('[digital-service-types admin] ' . $e->getMessage());
        setFlash('error', $__t('त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।', 'An error occurred. Please try again later.'));
        redirect('digital-service-types.php');
    }
}

$manageRows = [];
try {
    $manageRows = $db->query('SELECT * FROM digital_service_types ORDER BY display_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $manageRows = [];
}

if ($editId > 0) {
    foreach ($manageRows as $r) {
        if ((int)$r['id'] === $editId) {
            $editRow = $r;
            break;
        }
    }
    if (!$editRow) {
        setFlash('error', $__t('प्रकार फेला परेन।', 'Type not found.'));
        redirect('digital-service-types.php');
    }
}

$form = $editRow ?: [
    'id' => 0,
    'name_np' => '',
    'name_en' => '',
    'icon' => 'fa-laptop',
    'color' => 'var(--primary-color)',
    'display_order' => 0,
    'is_active' => 1,
    'requires_document' => 0,
];

echo adminPageHeader(
    $__t('डिजिटल सेवा प्रकारहरू', 'Digital Service Types'),
    'fa-tags',
    $__t('सार्वजनिक फारममा देखिने सेवा प्रकार थप्नुहोस् / सम्पादन गर्नुहोस्', 'Add or edit service types shown on the public form'),
    '<a href="digital-service-requests.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>' . $__t('अनुरोधहरू', 'Requests') . '</a>'
);
$_flash = getFlash();
if ($_flash) {
    echo adminAlert($_flash['type'], $_flash['message']);
}
?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card admin-table-card border-0 shadow-sm">
            <div class="card-header bg-light py-2">
                <strong><?php echo !empty($form['id']) ? $__t('प्रकार सम्पादन', 'Edit type') : $__t('नयाँ सेवा प्रकार', 'New service type'); ?></strong>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_type">
                    <input type="hidden" name="type_id" value="<?php echo (int)($form['id'] ?? 0); ?>">
                    <div class="col-12">
                        <label for="dst_name_np" class="form-label"><?php echo $__t('नाम (नेपाली)', 'Name (Nepali)'); ?> *</label>
                        <input type="text" name="name_np" id="dst_name_np" class="form-control" required maxlength="160"
                               value="<?php echo htmlspecialchars((string)$form['name_np']); ?>">
                    </div>
                    <div class="col-12">
                        <label for="dst_name_en" class="form-label"><?php echo $__t('नाम (अंग्रेजी)', 'Name (English)'); ?></label>
                        <input type="text" name="name_en" id="dst_name_en" class="form-control" maxlength="160"
                               value="<?php echo htmlspecialchars((string)$form['name_en']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="dst_icon" class="form-label"><?php echo $__t('आइकन (FA)', 'Icon (FA)'); ?></label>
                        <input type="text" name="icon" id="dst_icon" class="form-control" maxlength="80"
                               placeholder="fa-laptop"
                               value="<?php echo htmlspecialchars((string)$form['icon']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="dst_color" class="form-label"><?php echo $__t('रङ', 'Color'); ?></label>
                        <input type="text" name="color" id="dst_color" class="form-control" maxlength="40"
                               value="<?php echo htmlspecialchars((string)$form['color']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="dst_order" class="form-label"><?php echo $__t('क्रम', 'Order'); ?></label>
                        <input type="number" name="display_order" id="dst_order" class="form-control" value="<?php echo (int)($form['display_order'] ?? 0); ?>">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="is_active" id="dstActive" <?php echo !empty($form['is_active']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="dstActive"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="requires_document" id="dstDoc" <?php echo !empty($form['requires_document']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="dstDoc"><?php echo $__t('कागजात चाहिन्छ', 'Requires document'); ?></label>
                        </div>
                        <div class="form-text"><?php echo $__t('सक्रिय गर्दा सदस्यले संलग्न फाइल अनिवार्य अपलोड गर्नुपर्छ। रिचार्ज/शेयर जस्ता विशेष फिल्ड जस्ताको तस्तै रहन्छ।', 'When enabled, members must upload an attachment. Built-in panels (recharge/share/etc.) stay unchanged.'); ?></div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i><?php echo $__t('सुरक्षित', 'Save'); ?></button>
                        <?php if (!empty($form['id'])): ?>
                        <a href="digital-service-types.php" class="btn btn-outline-secondary"><?php echo $__t('रद्द', 'Cancel'); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card admin-table-card border-0 shadow-sm">
            <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                <strong><?php echo $__t('सबै प्रकारहरू', 'All types'); ?></strong>
                <span class="badge bg-secondary"><?php echo count($manageRows); ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th><?php echo $__t('प्रकार', 'Type'); ?></th>
                                <th class="text-center"><?php echo $__t('कागजात', 'Doc'); ?></th>
                                <th class="text-center"><?php echo $__t('क्रम', 'Order'); ?></th>
                                <th class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                                <th class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($manageRows)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4"><?php echo $__t('अहिले कुनै प्रकार छैन।', 'No types yet.'); ?></td></tr>
                        <?php else: foreach ($manageRows as $mc):
                            $iconShow = (string)($mc['icon'] ?? 'fa-laptop');
                            if (stripos($iconShow, 'fas ') === 0) {
                                $iconShow = trim(substr($iconShow, 4));
                            }
                        ?>
                            <tr>
                                <td>
                                    <span class="me-1" style="color:<?php echo htmlspecialchars((string)($mc['color'] ?? '')); ?>"><i class="fas <?php echo htmlspecialchars($iconShow); ?>"></i></span>
                                    <strong><?php echo htmlspecialchars((string)$mc['name_np']); ?></strong>
                                    <?php if (!empty($mc['is_builtin'])): ?><span class="badge bg-info ms-1">builtin</span><?php endif; ?>
                                    <?php if (!empty($mc['name_en'])): ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars((string)$mc['name_en']); ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars((string)$mc['slug']); ?></div>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($mc['requires_document'])): ?>
                                    <span class="badge bg-warning text-dark"><?php echo $__t('चाहिन्छ', 'Required'); ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo (int)$mc['display_order']; ?></td>
                                <td class="text-center">
                                    <?php if (!empty($mc['is_active'])): ?>
                                    <span class="badge bg-success"><?php echo $__t('सक्रिय', 'Active'); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo $__t('निष्क्रिय', 'Inactive'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="digital-service-types.php?edit=<?php echo (int)$mc['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('<?php echo $__t('स्थिति परिवर्तन गर्ने?', 'Toggle status?'); ?>');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="toggle_type">
                                        <input type="hidden" name="type_id" value="<?php echo (int)$mc['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Toggle"><i class="fas fa-power-off"></i></button>
                                    </form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('<?php echo $__t('मेटाउने? प्रयोगमा भए निष्क्रिय हुन्छ।', 'Delete? If in use it will be deactivated.'); ?>');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete_type">
                                        <input type="hidden" name="type_id" value="<?php echo (int)$mc['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
