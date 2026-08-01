<?php
/**
 * Admin — सम्मान आवेदन कार्यक्रम (Honor Programs)
 */
$pageTitle = 'सम्मान आवेदन कार्यक्रम';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/honor-tables.php';

$db = getDB();
ensureHonorTables($db);
checkCSRF();

$__t = static function (string $np, string $en): string {
    return (function_exists('isEnglish') && isEnglish()) ? $en : $np;
};

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'add', 'edit'], true)) {
    $action = 'list';
}
$editId = (int)($_GET['id'] ?? 0);

$allCategories = [];
try {
    $allCategories = $db->query('SELECT * FROM honor_categories WHERE is_active = 1 ORDER BY display_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $allCategories = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = clean_text($_POST['action'] ?? '', 40);
    try {
        if ($postAction === 'save_program') {
            $id = (int)($_POST['id'] ?? 0);
            $titleNp = clean_text($_POST['title_np'] ?? '', 200);
            $titleEn = clean_text($_POST['title_en'] ?? '', 200);
            $eventLabel = clean_text($_POST['event_label'] ?? '', 120);
            $fiscalYear = clean_text($_POST['fiscal_year'] ?? '', 40);
            $opensBs = clean_text($_POST['opens_at_bs'] ?? '', 40);
            $opensTime = clean_text($_POST['opens_at_time'] ?? '', 12);
            $closesBs = clean_text($_POST['closes_at_bs'] ?? '', 40);
            $closesTime = clean_text($_POST['closes_at_time'] ?? '', 12);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $showNew = isset($_POST['show_new_badge']) ? 1 : 0;
            $instNp = clean_text($_POST['instructions_np'] ?? '', 8000);
            $instEn = clean_text($_POST['instructions_en'] ?? '', 8000);
            $catIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['category_ids'] ?? [])))));

            $opensNorm = honorCombineBsDateTime($opensBs, $opensTime !== '' ? $opensTime : '00:00');
            $closesNorm = honorCombineBsDateTime($closesBs, $closesTime !== '' ? $closesTime : '23:59');

            if ($titleNp === '' || $opensNorm === '' || $closesNorm === '') {
                setFlash('error', $__t('शीर्षक र खुल्ने/बन्द मिति अनिवार्य छन्।', 'Title and open/close dates are required.'));
                redirect($id > 0 ? 'honor-programs.php?action=edit&id=' . $id : 'honor-programs.php?action=add');
            }
            if (strtotime($closesNorm) < strtotime($opensNorm)) {
                setFlash('error', $__t('बन्द मिति खुल्ने मितिभन्दा पछि हुनुपर्छ।', 'Close date must be after open date.'));
                redirect($id > 0 ? 'honor-programs.php?action=edit&id=' . $id : 'honor-programs.php?action=add');
            }
            if (empty($catIds)) {
                setFlash('error', $__t('कम्तीमा एक सम्मान कोटि छान्नुहोस्।', 'Select at least one honor category.'));
                redirect($id > 0 ? 'honor-programs.php?action=edit&id=' . $id : 'honor-programs.php?action=add');
            }

            if ($id > 0) {
                $stmt = $db->prepare('UPDATE honor_programs SET
                    title_np=?, title_en=?, event_label=?, fiscal_year=?,
                    opens_at=?, closes_at=?, is_active=?, show_new_badge=?,
                    instructions_np=?, instructions_en=?
                    WHERE id=?');
                $stmt->execute([$titleNp, $titleEn, $eventLabel, $fiscalYear, $opensNorm, $closesNorm, $isActive, $showNew, $instNp, $instEn, $id]);
                $programId = $id;
            } else {
                $stmt = $db->prepare('INSERT INTO honor_programs (
                    title_np, title_en, event_label, fiscal_year,
                    opens_at, closes_at, is_active, show_new_badge,
                    instructions_np, instructions_en
                ) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$titleNp, $titleEn, $eventLabel, $fiscalYear, $opensNorm, $closesNorm, $isActive, $showNew, $instNp, $instEn]);
                $programId = (int)$db->lastInsertId();
            }

            $db->prepare('DELETE FROM honor_program_categories WHERE program_id = ?')->execute([$programId]);
            $ins = $db->prepare('INSERT INTO honor_program_categories (program_id, category_id) VALUES (?,?)');
            foreach ($catIds as $cid) {
                $ins->execute([$programId, $cid]);
            }

            setFlash('success', $__t('कार्यक्रम सुरक्षित भयो।', 'Program saved.'));
            redirect('honor-programs.php');
        }

        if ($postAction === 'delete_program') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $cStmt = $db->prepare('SELECT COUNT(*) FROM honor_applications WHERE program_id = ?');
                $cStmt->execute([$id]);
                $appCount = (int)$cStmt->fetchColumn();
                if ($appCount > 0) {
                    setFlash('error', $__t('यो कार्यक्रममा आवेदन छन् — मेटाउन सकिँदैन। निष्क्रिय गर्नुहोस्।', 'This program has applications — deactivate instead of deleting.'));
                } else {
                    $db->prepare('DELETE FROM honor_program_categories WHERE program_id = ?')->execute([$id]);
                    $db->prepare('DELETE FROM honor_programs WHERE id = ?')->execute([$id]);
                    setFlash('success', $__t('कार्यक्रम हटाइयो।', 'Program deleted.'));
                }
            }
            redirect('honor-programs.php');
        }

        if ($postAction === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare('UPDATE honor_programs SET is_active = IF(is_active=1,0,1) WHERE id = ?')->execute([$id]);
                setFlash('success', $__t('स्थिति अद्यावधिक भयो।', 'Status updated.'));
            }
            redirect('honor-programs.php');
        }
    } catch (Throwable $e) {
        error_log('[honor-programs] ' . $e->getMessage());
        setFlash('error', $__t('त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।', 'An error occurred. Please try again later.'));
        redirect('honor-programs.php');
    }
}

$editRow = null;
$editCatIds = [];
if ($action === 'edit' && $editId > 0) {
    $editRow = honorFetchProgramById($db, $editId);
    if (!$editRow) {
        setFlash('error', $__t('कार्यक्रम फेला परेन।', 'Program not found.'));
        redirect('honor-programs.php');
    }
    foreach (honorFetchProgramCategories($db, $editId) as $c) {
        $editCatIds[] = (int)$c['id'];
    }
}

$programs = [];
try {
    $programs = $db->query('SELECT p.*,
        (SELECT COUNT(*) FROM honor_applications a WHERE a.program_id = p.id) AS app_count,
        (SELECT COUNT(*) FROM honor_program_categories pc WHERE pc.program_id = p.id) AS cat_count
        FROM honor_programs p
        ORDER BY p.opens_at DESC, p.id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $programs = [];
}

function honorAdminBsDate(?string $mysqlDt): string
{
    if (!$mysqlDt) {
        return '';
    }
    $ad = substr((string)$mysqlDt, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ad)) {
        return '';
    }
    if (function_exists('adToBs')) {
        $bs = trim((string)adToBs($ad));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $bs)) {
            return substr($bs, 0, 10);
        }
    }
    return $ad;
}

function honorAdminTimePart(?string $mysqlDt, string $fallback = '00:00'): string
{
    if (!$mysqlDt || strlen((string)$mysqlDt) < 16) {
        return $fallback;
    }
    return substr((string)$mysqlDt, 11, 5) ?: $fallback;
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h4 class="mb-1"><?php echo $__t('सम्मान आवेदन कार्यक्रम', 'Honor Application Programs'); ?></h4>
        <p class="text-muted mb-0 small"><?php echo $__t('AGM / वार्षिक उत्सवका लागि खुल्ने-बन्द मिति सहित कार्यक्रम बनाउनुहोस्। सक्रिय भए पनि खुल्ने मिति अगाडि भए public मा “चाँडै खुल्ने” देखिन्छ; आवेदन त्यसपछि मात्र।', 'Create programs with open/close dates. Even when Active, before opens_at the public shows “coming soon”; apply only after open.'); ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="honor-applications.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-inbox me-1"></i><?php echo $__t('आवेदनहरू', 'Applications'); ?></a>
        <?php if ($action === 'list'): ?>
        <a href="honor-programs.php?action=add" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i><?php echo $__t('नयाँ कार्यक्रम', 'New Program'); ?></a>
        <?php else: ?>
        <a href="honor-programs.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i><?php echo $__t('सूची', 'List'); ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : 'success'; ?> alert-dismissible fade show">
    <?php echo htmlspecialchars((string)$flash['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($action === 'add' || $action === 'edit'): ?>
<?php
$form = $editRow ?: [
    'title_np' => '', 'title_en' => '', 'event_label' => '', 'fiscal_year' => '',
    'opens_at' => date('Y-m-d 00:00:00'), 'closes_at' => date('Y-m-d 23:59:59', strtotime('+14 days')),
    'is_active' => 1, 'show_new_badge' => 1, 'instructions_np' => '', 'instructions_en' => '',
];
$selectedCats = $editCatIds;
?>
<div class="card admin-table-card">
    <div class="card-header gradient-card-header">
        <h5 class="mb-0"><?php echo $action === 'edit' ? $__t('कार्यक्रम सम्पादन', 'Edit Program') : $__t('नयाँ कार्यक्रम', 'New Program'); ?></h5>
    </div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_program">
            <input type="hidden" name="id" value="<?php echo (int)($form['id'] ?? 0); ?>">

            <div class="col-md-6">
                <label class="form-label"><?php echo $__t('शीर्षक (नेपाली)', 'Title (Nepali)'); ?> *</label>
                <input type="text" name="title_np" class="form-control" required maxlength="200"
                       value="<?php echo htmlspecialchars((string)$form['title_np']); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo $__t('शीर्षक (अंग्रेजी)', 'Title (English)'); ?></label>
                <input type="text" name="title_en" class="form-control" maxlength="200"
                       value="<?php echo htmlspecialchars((string)$form['title_en']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo $__t('कार्यक्रम लेबल', 'Event label'); ?></label>
                <input type="text" name="event_label" class="form-control" maxlength="120"
                       placeholder="<?php echo $__t('जस्तै: २५ औं AGM', 'e.g. 25th AGM'); ?>"
                       value="<?php echo htmlspecialchars((string)$form['event_label']); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label"><?php echo $__t('आर्थिक वर्ष', 'Fiscal year'); ?></label>
                <input type="text" name="fiscal_year" class="form-control" maxlength="40"
                       placeholder="2082/83"
                       value="<?php echo htmlspecialchars((string)$form['fiscal_year']); ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end gap-3 pb-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" id="hpActive" <?php echo !empty($form['is_active']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="hpActive"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="show_new_badge" id="hpNew" <?php echo !empty($form['show_new_badge']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="hpNew"><?php echo $__t('नयाँ ब्याज', 'NEW badge'); ?></label>
                </div>
            </div>
            <?php
            $opensBsVal = honorAdminBsDate((string)$form['opens_at']);
            $closesBsVal = honorAdminBsDate((string)$form['closes_at']);
            $opensTimeVal = honorAdminTimePart((string)$form['opens_at'], '00:00');
            $closesTimeVal = honorAdminTimePart((string)$form['closes_at'], '23:59');
            ?>
            <div class="col-md-3">
                <label class="form-label"><?php echo $__t('खुल्ने मिति (वि.सं.)', 'Opens date (BS)'); ?> *</label>
                <div class="input-group">
                    <input type="text" name="opens_at_bs" class="form-control nepali-datepicker" required
                           placeholder="YYYY-MM-DD" autocomplete="off"
                           value="<?php echo htmlspecialchars($opensBsVal); ?>">
                    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?php echo $__t('खुल्ने समय', 'Opens time'); ?> *</label>
                <input type="time" name="opens_at_time" class="form-control" required
                       value="<?php echo htmlspecialchars($opensTimeVal); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label"><?php echo $__t('बन्द मिति (वि.सं.)', 'Closes date (BS)'); ?> *</label>
                <div class="input-group">
                    <input type="text" name="closes_at_bs" class="form-control nepali-datepicker" required
                           placeholder="YYYY-MM-DD" autocomplete="off"
                           value="<?php echo htmlspecialchars($closesBsVal); ?>">
                    <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label"><?php echo $__t('बन्द समय', 'Closes time'); ?> *</label>
                <input type="time" name="closes_at_time" class="form-control" required
                       value="<?php echo htmlspecialchars($closesTimeVal); ?>">
            </div>
            <div class="col-12">
                <div class="form-text">
                    <?php echo $__t('मिति नेपाली (वि.सं.) छान्नुहोस् — DB मा AD मा सुरक्षित हुन्छ। सक्रिय ≠ खुला: खुल्ने मिति आएपछि मात्र public मा आवेदन खुल्छ; त्यसअघि “चाँडै खुल्ने” देखिन्छ।', 'Pick Nepali (BS) dates — stored as AD. Active ≠ Open: applications open only after opens_at; before that the public shows “coming soon”.'); ?>
                </div>
            </div>
            <div class="col-12">
                <label class="form-label"><?php echo $__t('सम्मान कोटिहरू', 'Honor categories'); ?> *</label>
                <div class="border rounded p-3 bg-light">
                    <div class="row g-2">
                        <?php foreach ($allCategories as $cat): ?>
                        <div class="col-md-4 col-sm-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="category_ids[]"
                                       value="<?php echo (int)$cat['id']; ?>"
                                       id="cat<?php echo (int)$cat['id']; ?>"
                                       <?php echo in_array((int)$cat['id'], $selectedCats, true) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="cat<?php echo (int)$cat['id']; ?>">
                                    <?php echo htmlspecialchars(honorCategoryLabel($cat, false)); ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (empty($allCategories)): ?>
                    <p class="text-muted mb-0 small"><?php echo $__t('कोटि सूची खाली छ — schema seed जाँच गर्नुहोस्।', 'No categories — check schema seed.'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo $__t('निर्देशन (नेपाली)', 'Instructions (Nepali)'); ?></label>
                <textarea name="instructions_np" class="form-control" rows="4"><?php echo htmlspecialchars((string)$form['instructions_np']); ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label"><?php echo $__t('निर्देशन (अंग्रेजी)', 'Instructions (English)'); ?></label>
                <textarea name="instructions_en" class="form-control" rows="4"><?php echo htmlspecialchars((string)$form['instructions_en']); ?></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?php echo $__t('सुरक्षित गर्नुहोस्', 'Save'); ?></button>
                <a href="honor-programs.php" class="btn btn-outline-secondary"><?php echo $__t('रद्द', 'Cancel'); ?></a>
            </div>
        </form>
    </div>
</div>

<?php else: ?>

<div class="card admin-table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th><?php echo $__t('कार्यक्रम', 'Program'); ?></th>
                        <th><?php echo $__t('खुला अवधि', 'Window'); ?></th>
                        <th class="text-center"><?php echo $__t('कोटि', 'Cats'); ?></th>
                        <th class="text-center"><?php echo $__t('आवेदन', 'Apps'); ?></th>
                        <th class="text-center"><?php echo $__t('स्थिति', 'Status'); ?></th>
                        <th class="text-center"><?php echo $__t('कार्य', 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($programs)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><?php echo $__t('अहिले कुनै कार्यक्रम छैन।', 'No programs yet.'); ?></td></tr>
                <?php else: foreach ($programs as $p):
                    $winState = honorProgramWindowState($p);
                ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars(honorProgramLabel($p, false)); ?></strong>
                            <?php if (!empty($p['event_label'])): ?>
                            <div class="small text-muted"><?php echo htmlspecialchars((string)$p['event_label']); ?><?php echo $p['fiscal_year'] ? ' · ' . htmlspecialchars((string)$p['fiscal_year']) : ''; ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php echo htmlspecialchars(honorFormatDtBs((string)$p['opens_at'])); ?><br>
                            <span class="text-muted">→ <?php echo htmlspecialchars(honorFormatDtBs((string)$p['closes_at'])); ?></span>
                            <?php if ($winState === 'open'): ?>
                            <div><span class="badge bg-success"><?php echo $__t('खुला (आवेदन)', 'Open (apply)'); ?></span></div>
                            <?php elseif ($winState === 'upcoming'): ?>
                            <div><span class="badge bg-warning text-dark"><?php echo $__t('चाँडै खुल्ने', 'Upcoming'); ?></span></div>
                            <?php elseif ($winState === 'closed' && !empty($p['is_active'])): ?>
                            <div><span class="badge bg-secondary"><?php echo $__t('अवधि सकियो', 'Window ended'); ?></span></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?php echo (int)$p['cat_count']; ?></td>
                        <td class="text-center">
                            <a href="honor-applications.php?program_id=<?php echo (int)$p['id']; ?>"><?php echo (int)$p['app_count']; ?></a>
                        </td>
                        <td class="text-center">
                            <?php if (!empty($p['is_active'])): ?>
                            <span class="badge bg-primary"><?php echo $__t('सक्रिय', 'Active'); ?></span>
                            <?php else: ?>
                            <span class="badge bg-secondary"><?php echo $__t('निष्क्रिय', 'Inactive'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <a href="honor-programs.php?action=edit&id=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                            <form method="post" class="d-inline" onsubmit="return confirm('<?php echo $__t('स्थिति परिवर्तन गर्ने?', 'Toggle status?'); ?>');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Toggle"><i class="fas fa-power-off"></i></button>
                            </form>
                            <?php if ((int)$p['app_count'] === 0): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('<?php echo $__t('मेटाउने निश्चित?', 'Delete permanently?'); ?>');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_program">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
