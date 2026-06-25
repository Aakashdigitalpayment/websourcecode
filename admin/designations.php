<?php
/**
 * 🏷️ पद मास्टर (Designations Master)
 * समिति, निर्वाचन, कर्मचारी सबैमा साझा पद list।
 */
$pageTitle = 'पद मास्टर';
$currentPage = 'designations';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/election-tables.php';

$db = getDB();
ensureDesignationsTable($db);

$cats = designationCategories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $titleNp = clean_text($_POST['title_np'] ?? '', 160);
            $titleEn = clean_text($_POST['title_en'] ?? '', 160);
            $cat = clean_text($_POST['category'] ?? 'general', 32);
            if (!isset($cats[$cat])) $cat = 'general';
            $ord = (int)($_POST['display_order'] ?? 0);
            $act = isset($_POST['is_active']) ? 1 : 0;
            if ($titleNp === '') {
                setFlash('error', 'पदको नाम (नेपाली) अनिवार्य।');
            } elseif ($id > 0) {
                $db->prepare('UPDATE designations SET title_np=?, title_en=?, category=?, display_order=?, is_active=? WHERE id=?')
                    ->execute([$titleNp, $titleEn, $cat, $ord, $act, $id]);
                setFlash('success', 'पद अपडेट भयो।');
            } else {
                $db->prepare('INSERT INTO designations (title_np, title_en, category, display_order, is_active) VALUES (?,?,?,?,?)')
                    ->execute([$titleNp, $titleEn, $cat, $ord, $act]);
                setFlash('success', 'नयाँ पद थपियो।');
            }
            redirect('designations.php');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare('DELETE FROM designations WHERE id=?')->execute([$id]);
                setFlash('success', 'पद मेटाइयो।');
            }
            redirect('designations.php');
        }
    } catch (Throwable $e) {
        setFlash('error', 'त्रुटि: ' . $e->getMessage());
        redirect('designations.php');
    }
}

$editRow = null;
if (($eid = (int)($_GET['edit'] ?? 0)) > 0) {
    $st = $db->prepare('SELECT * FROM designations WHERE id=?');
    $st->execute([$eid]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$rows = $db->query('SELECT * FROM designations ORDER BY category, display_order, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$grouped = [];
foreach ($rows as $r) $grouped[$r['category']][] = $r;
$panel = (string)($_GET['panel'] ?? 'list');
if (!in_array($panel, ['list', 'form'], true)) $panel = 'list';
if ($editRow) $panel = 'form';
?>
<div class="container-fluid py-3">
<?php
echo adminPageHeader(
    'पद मास्टर',
    'fa-id-badge',
    'समिति/उपसमिति, निर्वाचन र कर्मचारीका पद यही ठाउँबाट व्यवस्थापन गर्नुहोस्।',
    ''
);
?>
<?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item"><a class="nav-link <?php echo $panel==='list' ? 'active' : ''; ?>" href="?panel=list"><i class="fas fa-list me-2"></i>पद सूची <span class="badge bg-success ms-1"><?php echo count($rows); ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?php echo $panel==='form' ? 'active' : ''; ?>" href="?panel=form"><i class="fas fa-plus-circle me-2"></i><?php echo $editRow ? 'पद सम्पादन' : 'नयाँ पद थप्नुहोस्'; ?></a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade <?php echo $panel==='list' ? 'show active' : ''; ?>" id="desig-list">
    <div class="row g-3">
    <div class="col-12">
        <?php foreach ($cats as $k => $lbl):
            $list = $grouped[$k] ?? []; ?>
            <div class="card admin-table-card mb-3">
                <div class="card-header d-flex justify-content-between">
                    <h6 class="mb-0"><i class="fas fa-folder me-2"></i><?php echo htmlspecialchars($lbl); ?></h6>
                    <span class="badge bg-secondary"><?php echo count($list); ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle coop-table">
                        <thead><tr><th>पद (NP)</th><th>English</th><th>क्रम</th><th>स्थिति</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($list as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['title_np']); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars($r['title_en']); ?></td>
                                <td><?php echo (int)$r['display_order']; ?></td>
                                <td><?php if ($r['is_active']): ?><span class="badge bg-success">सक्रिय</span><?php else: ?><span class="badge bg-secondary">निष्क्रिय</span><?php endif; ?></td>
                                <td class="text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?php echo (int)$r['id']; ?>&panel=form"><i class="fas fa-pen"></i></a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('यो पद मेटाउने? कतै प्रयोग भइरहेको भए text मा फर्किनेछ।');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($list)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">अझै पद थपिएको छैन।</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    </div>
    </div>

    <div class="tab-pane fade <?php echo $panel==='form' ? 'show active' : ''; ?>" id="desig-form">
    <div class="row g-3">
    <div class="col-12">
        <div class="card admin-table-card">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-plus-circle me-2"></i><?php echo $editRow ? 'पद सम्पादन' : 'नयाँ पद थप्नुहोस्'; ?></h6></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo $editRow ? (int)$editRow['id'] : 0; ?>">
                    <div class="col-12"><label class="form-label small">पदको नाम (नेपाली) *</label>
                        <input class="form-control" name="title_np" required value="<?php echo htmlspecialchars($editRow['title_np'] ?? ''); ?>" placeholder="अध्यक्ष, सचिव, प्रबन्धक ...">
                    </div>
                    <div class="col-12"><label class="form-label small">Title (English)</label>
                        <input class="form-control" name="title_en" value="<?php echo htmlspecialchars($editRow['title_en'] ?? ''); ?>" placeholder="Chairperson, Secretary ...">
                    </div>
                    <div class="col-7"><label class="form-label small">वर्ग *</label>
                        <select class="form-select" name="category">
                            <?php foreach ($cats as $k => $lbl): ?>
                                <option value="<?php echo $k; ?>" <?php echo (($editRow['category'] ?? 'committee') === $k) ? 'selected' : ''; ?>><?php echo htmlspecialchars($lbl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-5"><label class="form-label small">क्रम</label>
                        <input type="number" class="form-control" name="display_order" value="<?php echo (int)($editRow['display_order'] ?? 0); ?>">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="dact" value="1" <?php echo (!$editRow || !empty($editRow['is_active'])) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="dact">सक्रिय</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2 mt-2">
                        <button class="btn btn-primary"><i class="fas fa-save me-1"></i>बचत</button>
                        <a class="btn btn-outline-secondary" href="designations.php?panel=form">नयाँ</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    </div>
    </div>
    </div>
</div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
