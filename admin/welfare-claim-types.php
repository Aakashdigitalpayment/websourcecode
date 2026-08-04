<?php
/**
 * Admin — Welfare claim types catalog
 */
if (!ob_get_level()) {
    ob_start();
}
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('कल्याण दाबी प्रकारहरू', 'Welfare Claim Types');
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/welfare-claims-tables.php';
require_once __DIR__ . '/../includes/welfare-claim-types.php';
require_once __DIR__ . '/../includes/auth-roles.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
}

$db = getDB();
ensureWelfareClaimsTables($db);
ensureWelfareClaimTypes($db);
checkCSRF();

$editId = (int)($_GET['edit'] ?? 0);
$editRow = null;
$profiles = welfareClaimTypeProfiles();
$profileLabels = [
    'maternity' => $__t('सुत्केरी', 'Maternity'),
    'death' => $__t('मृत्यु', 'Death'),
    'insurance' => $__t('बीमा', 'Insurance'),
    'medical' => $__t('उपचार', 'Medical'),
    'accident' => $__t('दुर्घटना', 'Accident'),
    'other' => $__t('अन्य (विशेष फिल्ड छैन)', 'Other (no special fields)'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = clean_text($_POST['action'] ?? '', 40);
    try {
        if ($postAction === 'save_type') {
            $tid = (int)($_POST['type_id'] ?? 0);
            $nameNp = clean_text($_POST['name_np'] ?? '', 160);
            $nameEn = clean_text($_POST['name_en'] ?? '', 160);
            $icon = clean_text($_POST['icon'] ?? 'fa-gift', 80) ?: 'fa-gift';
            $color = clean_text($_POST['color'] ?? '#ff9800', 40) ?: '#ff9800';
            $profile = clean_text($_POST['form_profile'] ?? 'other', 40);
            if (!in_array($profile, $profiles, true)) {
                $profile = 'other';
            }
            $order = (int)($_POST['display_order'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($nameNp === '') {
                setFlash('error', $__t('नाम (नेपाली) अनिवार्य छ।', 'Name (Nepali) is required.'));
                redirect('welfare-claim-types.php' . ($tid > 0 ? '?edit=' . $tid : ''));
            }

            if ($tid > 0) {
                $db->prepare(
                    'UPDATE welfare_claim_types SET name_np=?, name_en=?, icon=?, color=?, form_profile=?, display_order=?, is_active=? WHERE id=?'
                )->execute([$nameNp, $nameEn, $icon, $color, $profile, $order, $isActive, $tid]);
                setFlash('success', $__t('दाबी प्रकार अद्यावधिक भयो।', 'Claim type updated.'));
            } else {
                $base = welfareClaimTypeSlugify($nameEn !== '' ? $nameEn : $nameNp, 'welfare');
                $slug = $base;
                $n = 0;
                $chk = $db->prepare('SELECT id FROM welfare_claim_types WHERE slug = ? LIMIT 1');
                while (true) {
                    $chk->execute([$slug]);
                    if (!$chk->fetchColumn()) {
                        break;
                    }
                    $n++;
                    $slug = substr($base, 0, 40) . '-' . $n;
                    if ($n > 50) {
                        $slug = 'welfare-' . substr(md5((string)microtime(true)), 0, 10);
                        break;
                    }
                }
                if ($order <= 0) {
                    try {
                        $order = (int)$db->query('SELECT COALESCE(MAX(display_order),0) + 10 FROM welfare_claim_types')->fetchColumn();
                    } catch (Throwable $e) {
                        $order = 10;
                    }
                }
                $db->prepare(
                    'INSERT INTO welfare_claim_types (slug, name_np, name_en, icon, color, form_profile, display_order, is_active, is_builtin) VALUES (?,?,?,?,?,?,?,?,0)'
                )->execute([$slug, $nameNp, $nameEn, $icon, $color, $profile, $order, $isActive]);
                setFlash('success', $__t('नयाँ दाबी प्रकार थपियो।', 'Claim type added.'));
            }
            redirect('welfare-claim-types.php');
        }

        if ($postAction === 'toggle_type') {
            $tid = (int)($_POST['type_id'] ?? 0);
            if ($tid > 0) {
                $db->prepare('UPDATE welfare_claim_types SET is_active = IF(is_active=1,0,1) WHERE id = ?')->execute([$tid]);
                setFlash('success', $__t('स्थिति बदलियो।', 'Status updated.'));
            }
            redirect('welfare-claim-types.php');
        }

        if ($postAction === 'delete_type') {
            $tid = (int)($_POST['type_id'] ?? 0);
            if ($tid > 0) {
                $row = null;
                try {
                    $st = $db->prepare('SELECT * FROM welfare_claim_types WHERE id = ? LIMIT 1');
                    $st->execute([$tid]);
                    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) {
                    $row = null;
                }
                if ($row) {
                    $slug = (string)($row['slug'] ?? '');
                    $inUse = $slug !== '' ? welfareClaimTypeUsageCount($db, $slug) : 0;
                    $isBuiltin = !empty($row['is_builtin']);
                    if ($isBuiltin || $inUse > 0) {
                        $db->prepare('UPDATE welfare_claim_types SET is_active = 0 WHERE id = ?')->execute([$tid]);
                        setFlash('warning', $__t(
                            'प्रयोगमा / builtin प्रकार — मेटाउन सकिएन, निष्क्रिय गरियो।',
                            'In use or builtin — deactivated instead of deleted.'
                        ));
                    } else {
                        $db->prepare('DELETE FROM welfare_claim_types WHERE id = ?')->execute([$tid]);
                        setFlash('success', $__t('दाबी प्रकार हटाइयो।', 'Claim type deleted.'));
                    }
                }
            }
            redirect('welfare-claim-types.php');
        }
    } catch (Throwable $e) {
        error_log('[welfare-claim-types admin] ' . $e->getMessage());
        setFlash('error', $__t('त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।', 'An error occurred. Please try again later.'));
        redirect('welfare-claim-types.php');
    }
}

$manageRows = [];
try {
    $manageRows = $db->query('SELECT * FROM welfare_claim_types ORDER BY display_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        redirect('welfare-claim-types.php');
    }
}

$form = $editRow ?: [
    'id' => 0,
    'name_np' => '',
    'name_en' => '',
    'icon' => 'fa-gift',
    'color' => '#ff9800',
    'form_profile' => 'other',
    'display_order' => 0,
    'is_active' => 1,
];

echo adminPageHeader(
    $__t('कल्याण दाबी प्रकारहरू', 'Welfare Claim Types'),
    'fa-hand-holding-heart',
    $__t('सार्वजनिक फारममा देखिने दाबी प्रकार थप्नुहोस् / सम्पादन गर्नुहोस्', 'Add or edit claim types shown on the public form'),
    '<a href="welfare-claims.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>' . $__t('दाबीहरू', 'Claims') . '</a>'
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
                <strong><?php echo !empty($form['id']) ? $__t('प्रकार सम्पादन', 'Edit type') : $__t('नयाँ दाबी प्रकार', 'New claim type'); ?></strong>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_type">
                    <input type="hidden" name="type_id" value="<?php echo (int)($form['id'] ?? 0); ?>">
                    <div class="col-12">
                        <label class="form-label"><?php echo $__t('नाम (नेपाली)', 'Name (Nepali)'); ?> *</label>
                        <input type="text" name="name_np" class="form-control" required maxlength="160"
                               value="<?php echo htmlspecialchars((string)$form['name_np']); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label"><?php echo $__t('नाम (अंग्रेजी)', 'Name (English)'); ?></label>
                        <input type="text" name="name_en" class="form-control" maxlength="160"
                               value="<?php echo htmlspecialchars((string)$form['name_en']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php echo $__t('आइकन (FA)', 'Icon (FA)'); ?></label>
                        <input type="text" name="icon" class="form-control" maxlength="80"
                               placeholder="fa-gift"
                               value="<?php echo htmlspecialchars((string)$form['icon']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php echo $__t('रङ', 'Color'); ?></label>
                        <input type="text" name="color" class="form-control" maxlength="40"
                               value="<?php echo htmlspecialchars((string)$form['color']); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label"><?php echo $__t('फारम प्रोफाइल', 'Form profile'); ?></label>
                        <select name="form_profile" class="form-select">
                            <?php foreach ($profiles as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo (($form['form_profile'] ?? 'other') === $p) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($profileLabels[$p] ?? $p); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?php echo $__t('कुन विशेष फिल्डहरू देखाउने भन्ने निर्धारण गर्छ।', 'Controls which special fields are shown.'); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php echo $__t('क्रम', 'Order'); ?></label>
                        <input type="number" name="display_order" class="form-control" value="<?php echo (int)($form['display_order'] ?? 0); ?>">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="is_active" id="wctActive" <?php echo !empty($form['is_active']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="wctActive"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i><?php echo $__t('सुरक्षित', 'Save'); ?></button>
                        <?php if (!empty($form['id'])): ?>
                        <a href="welfare-claim-types.php" class="btn btn-outline-secondary"><?php echo $__t('रद्द', 'Cancel'); ?></a>
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
                                <th class="text-center"><?php echo $__t('प्रोफाइल', 'Profile'); ?></th>
                                <th class="text-center"><?php echo $__t('क्रम', 'Order'); ?></th>
                                <th class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                                <th class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($manageRows)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4"><?php echo $__t('अहिले कुनै प्रकार छैन।', 'No types yet.'); ?></td></tr>
                        <?php else: foreach ($manageRows as $mc):
                            $iconShow = (string)($mc['icon'] ?? 'fa-gift');
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
                                <td class="text-center"><span class="badge bg-light text-dark"><?php echo htmlspecialchars((string)($mc['form_profile'] ?? 'other')); ?></span></td>
                                <td class="text-center"><?php echo (int)$mc['display_order']; ?></td>
                                <td class="text-center">
                                    <?php if (!empty($mc['is_active'])): ?>
                                    <span class="badge bg-success"><?php echo $__t('सक्रिय', 'Active'); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo $__t('निष्क्रिय', 'Inactive'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="welfare-claim-types.php?edit=<?php echo (int)$mc['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
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
