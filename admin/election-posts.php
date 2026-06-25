<?php
/**
 * निर्वाचन — पद Master (एकपटक बनाएर सबै चक्रमा reuse)
 */
$pageTitle = 'पद व्यवस्थापन (Master)';
$currentPage = 'election-posts';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

require_once __DIR__ . '/../includes/election-tables.php';
$db = getDB();
ensureElectionTables($db);
ensureElectionVotingTables($db);
ensureDesignationsTable($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_post') {
            $pid = (int)($_POST['post_id'] ?? 0);
            $designationId = (int)($_POST['designation_id'] ?? 0);
            $ctid = (int)($_POST['committee_type_id'] ?? 0) ?: null;
            $seats = max(1, (int)($_POST['default_seats'] ?? 1));
            $maxV  = max(1, (int)($_POST['default_max_votes'] ?? 1));
            $ord   = (int)($_POST['display_order'] ?? 0);
            $act   = isset($_POST['is_active']) ? 1 : 0;
            $desigStmt = $db->prepare("SELECT id, title_np, title_en FROM designations WHERE id=? AND is_active=1 LIMIT 1");
            $desigStmt->execute([$designationId]);
            $desig = $desigStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$desig) {
                setFlash('error', 'कृपया पद मास्टरबाट पद छान्नुहोस्।');
            } elseif ($pid > 0) {
                $db->prepare('UPDATE election_posts SET designation_id=?, title_np=?, title_en=?, committee_type_id=?, default_seats=?, default_max_votes=?, display_order=?, is_active=? WHERE id=?')
                    ->execute([(int)$desig['id'], clean_text($desig['title_np'] ?? '', 160), clean_text($desig['title_en'] ?? '', 160), $ctid, $seats, $maxV, $ord, $act, $pid]);
                setFlash('success', 'पद अपडेट भयो।');
            } else {
                $db->prepare('INSERT INTO election_posts (designation_id, title_np, title_en, committee_type_id, default_seats, default_max_votes, display_order, is_active) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([(int)$desig['id'], clean_text($desig['title_np'] ?? '', 160), clean_text($desig['title_en'] ?? '', 160), $ctid, $seats, $maxV, $ord, $act]);
                setFlash('success', 'नयाँ पद थपियो।');
            }
            redirect('election-posts.php');
        } elseif ($action === 'delete_post') {
            $pid = (int)($_POST['post_id'] ?? 0);
            if ($pid > 0) {
                /* चक्रमा प्रयोग भएको छ कि check */
                $st = $db->prepare('SELECT COUNT(*) FROM election_positions WHERE post_id=?');
                $st->execute([$pid]);
                if ((int)$st->fetchColumn() > 0) {
                    setFlash('error', 'यो पद कुनै चक्रमा प्रयोग भएको छ — मेटाउन मिलेन।');
                } else {
                    $db->prepare('DELETE FROM election_posts WHERE id=?')->execute([$pid]);
                    setFlash('success', 'पद मेटाइयो।');
                }
            }
            redirect('election-posts.php');
        }
    } catch (Throwable $e) {
        setFlash('error', 'त्रुटि: ' . $e->getMessage());
        redirect('election-posts.php');
    }
}

$editPost = null;
if (($epid = (int)($_GET['edit'] ?? 0)) > 0) {
    $st = $db->prepare('SELECT * FROM election_posts WHERE id=?');
    $st->execute([$epid]);
    $editPost = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$posts = $db->query('SELECT p.*, ct.name_np AS ctype_name, d.title_np AS desig_name
                     FROM election_posts p
                     LEFT JOIN committee_types ct ON ct.id=p.committee_type_id
                     LEFT JOIN designations d ON d.id=p.designation_id
                     ORDER BY p.display_order, p.id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$committeeTypes = $db->query('SELECT id, name_np FROM committee_types WHERE is_active=1 ORDER BY display_order, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$designations = fetchDesignations($db, ['committee', 'staff']);
$panel = (string)($_GET['panel'] ?? 'list');
if (!in_array($panel, ['list', 'form'], true)) $panel = 'list';
if ($editPost) $panel = 'form';
?>
<div class="container-fluid py-3">
<?php
echo adminPageHeader(
    'पद व्यवस्थापन (Master)',
    'fa-briefcase',
    'Designation-driven only: पद मास्टरबाट छानेर सबै निर्वाचन चक्रमा प्रयोग गर्नुहोस्।',
    '<a class="btn btn-outline-secondary btn-sm" href="election-information.php"><i class="fas fa-arrow-left me-1"></i>निर्वाचन</a>'
);
?>
<?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item"><a class="nav-link <?php echo $panel==='list' ? 'active' : ''; ?>" href="?panel=list"><i class="fas fa-list me-2"></i>पद सूची <span class="badge bg-success ms-1"><?php echo count($posts); ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?php echo $panel==='form' ? 'active' : ''; ?>" href="?panel=form"><i class="fas fa-plus-circle me-2"></i><?php echo $editPost ? 'पद सम्पादन' : 'नयाँ पद थप्नुहोस्'; ?></a></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade <?php echo $panel==='list' ? 'show active' : ''; ?>" id="ep-list">
    <div class="row g-3">
    <div class="col-12">
        <div class="card admin-table-card">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2"></i>पद सूची (<?php echo count($posts); ?>)</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>पद</th><th>Master स्रोत</th><th>समिति</th><th>सिट</th><th>मत</th><th>क्रम</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($posts as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['title_np']); ?>
                                <?php if (empty($p['is_active'])): ?><span class="badge bg-secondary ms-1">निष्क्रिय</span><?php endif; ?>
                            </td>
                            <td class="small text-muted"><?php echo htmlspecialchars($p['desig_name'] ?? '—'); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($p['ctype_name'] ?? '—'); ?></td>
                            <td><?php echo (int)$p['default_seats']; ?></td>
                            <td><?php echo (int)$p['default_max_votes']; ?></td>
                            <td><?php echo (int)$p['display_order']; ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="?edit=<?php echo (int)$p['id']; ?>&panel=form"><i class="fas fa-pen"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('यो पद मेटाउने?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete_post">
                                    <input type="hidden" name="post_id" value="<?php echo (int)$p['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($posts)): ?><tr><td colspan="7" class="text-center text-muted py-3">अझै पद थपिएको छैन।</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    </div>
    </div>

    <div class="tab-pane fade <?php echo $panel==='form' ? 'show active' : ''; ?>" id="ep-form">
    <div class="row g-3">
    <div class="col-12">
        <div class="card admin-table-card">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-plus-circle me-2"></i><?php echo $editPost ? 'पद सम्पादन' : 'नयाँ पद थप्नुहोस्'; ?></h6></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_post">
                    <input type="hidden" name="post_id" value="<?php echo $editPost ? (int)$editPost['id'] : 0; ?>">
                    <div class="col-12"><label class="form-label small">पद मास्टरबाट पद छान्नुहोस् *</label>
                        <select class="form-select" name="designation_id" required>
                            <option value="">— पद छान्नुहोस् —</option>
                            <?php foreach ($designations as $d): ?>
                                <?php $dt = trim((string)($d['title_np'] ?? '')); if ($dt === '') continue; ?>
                                <option value="<?php echo (int)$d['id']; ?>" <?php echo ((int)($editPost['designation_id'] ?? 0) === (int)$d['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dt); ?><?php if (!empty($d['title_en'])): ?> (<?php echo htmlspecialchars((string)$d['title_en']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small text-muted mt-1">नयाँ पद चाहियो भने <a href="designations.php" target="_blank">पद मास्टर</a> मा थप्नुहोस्।</div>
                    </div>
                    <div class="col-12"><label class="form-label small">समिति</label>
                        <select class="form-select" name="committee_type_id">
                            <option value="">— छान्नुहोस् —</option>
                            <?php foreach ($committeeTypes as $ct): ?>
                                <option value="<?php echo (int)$ct['id']; ?>" <?php echo ((int)($editPost['committee_type_id'] ?? 0) === (int)$ct['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ct['name_np']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><label class="form-label small">default सिट</label>
                        <input type="number" min="1" class="form-control" name="default_seats" value="<?php echo (int)($editPost['default_seats'] ?? 1); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label small">default मत/मतदाता</label>
                        <input type="number" min="1" class="form-control" name="default_max_votes" value="<?php echo (int)($editPost['default_max_votes'] ?? 1); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label small">क्रम</label>
                        <input type="number" class="form-control" name="display_order" value="<?php echo (int)($editPost['display_order'] ?? 0); ?>">
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="pact" value="1" <?php echo (!empty($editPost['is_active']) || !$editPost) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="pact">सक्रिय</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary"><i class="fas fa-save me-1"></i>बचत</button>
                        <a class="btn btn-outline-secondary" href="election-posts.php?panel=form">नयाँ</a>
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
