<?php
/**
 * निर्वाचन — पद र उम्मेदवार व्यवस्थापन
 * (admin/election-information.php को sub-page)
 */
$pageTitle = 'उम्मेदवार व्यवस्थापन';
$currentPage = 'election-candidates';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

require_once __DIR__ . '/../includes/election-tables.php';
$db = getDB();
ensureElectionTables($db);
ensureElectionVotingTables($db);

$cycleId = (int)($_GET['cycle'] ?? 0);
/* cycle param नदिए — पहिलो उपलब्ध cycle auto-select; एउटै नभए information page मा cycle बनाउन भन्ने */
if ($cycleId <= 0) {
    $autoId = (int)($db->query('SELECT id FROM election_cycles ORDER BY voting_enabled DESC, sort_order ASC, id DESC LIMIT 1')->fetchColumn() ?: 0);
    if ($autoId > 0) {
        redirect('election-candidates.php?cycle=' . $autoId);
    } else {
        setFlash('error', 'पहिले «निर्वाचन जानकारी» बाट निर्वाचन चक्र थप्नुहोस्।');
        redirect('election-information.php');
    }
}
$cs = $db->prepare('SELECT * FROM election_cycles WHERE id=? LIMIT 1');
$cs->execute([$cycleId]);
$cycle = $cs->fetch(PDO::FETCH_ASSOC);
if (!$cycle) {
    setFlash('error', 'चक्र भेटिएन।');
    redirect('election-information.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_position') {
            $pid = (int)($_POST['position_id'] ?? 0);
            $postId = (int)($_POST['post_id'] ?? 0) ?: null;
            $titleNp = clean_text($_POST['title_np'] ?? '', 160);
            $titleEn = clean_text($_POST['title_en'] ?? '', 160);
            $seats = max(1, (int)($_POST['seats'] ?? 1));
            $maxV  = max(1, (int)($_POST['max_votes_per_voter'] ?? 1));
            $ctid  = (int)($_POST['committee_type_id'] ?? 0) ?: null;
            $ord   = (int)($_POST['display_order'] ?? 0);
            $act   = isset($_POST['is_active']) ? 1 : 0;

            /* post master select गरिएको भए — auto-fill */
            if ($postId) {
                $pst = $db->prepare('SELECT * FROM election_posts WHERE id=?');
                $pst->execute([$postId]);
                if ($pm = $pst->fetch(PDO::FETCH_ASSOC)) {
                    if ($titleNp === '') $titleNp = (string)$pm['title_np'];
                    if ($titleEn === '') $titleEn = (string)$pm['title_en'];
                    if (!$ctid) $ctid = $pm['committee_type_id'] ?: null;
                    if (empty($_POST['seats'])) $seats = max(1, (int)$pm['default_seats']);
                    if (empty($_POST['max_votes_per_voter'])) $maxV = max(1, (int)$pm['default_max_votes']);
                }
            }
            if ($titleNp === '') {
                setFlash('error', 'पदको नाम (नेपाली) अनिवार्य।');
            } elseif ($pid > 0) {
                $db->prepare('UPDATE election_positions SET post_id=?, title_np=?, title_en=?, seats=?, max_votes_per_voter=?, committee_type_id=?, display_order=?, is_active=? WHERE id=? AND cycle_id=?')
                    ->execute([$postId, $titleNp, $titleEn, $seats, $maxV, $ctid, $ord, $act, $pid, $cycleId]);
                setFlash('success', 'पद अपडेट भयो।');
            } else {
                $db->prepare('INSERT INTO election_positions (cycle_id, post_id, title_np, title_en, seats, max_votes_per_voter, committee_type_id, display_order, is_active) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$cycleId, $postId, $titleNp, $titleEn, $seats, $maxV, $ctid, $ord, $act]);
                setFlash('success', 'नयाँ पद थपियो।');
            }
            redirect('election-candidates.php?cycle=' . $cycleId);
        } elseif ($action === 'delete_position') {
            $pid = (int)($_POST['position_id'] ?? 0);
            if ($pid > 0) {
                $db->prepare('DELETE FROM election_candidates WHERE position_id=? AND cycle_id=?')->execute([$pid, $cycleId]);
                $db->prepare('DELETE FROM election_votes WHERE position_id=? AND cycle_id=?')->execute([$pid, $cycleId]);
                $db->prepare('DELETE FROM election_positions WHERE id=? AND cycle_id=?')->execute([$pid, $cycleId]);
                setFlash('success', 'पद मेटाइयो।');
            }
            redirect('election-candidates.php?cycle=' . $cycleId);
        } elseif ($action === 'save_candidate') {
            $cid = (int)($_POST['candidate_id'] ?? 0);
            $pid = (int)($_POST['position_id'] ?? 0);
            $name = clean_text($_POST['name'] ?? '', 160);
            $nameEn = clean_text($_POST['name_en'] ?? '', 160);
            $bioNp = mb_substr(trim((string)($_POST['bio_np'] ?? '')), 0, 60000, 'UTF-8');
            $bioEn = mb_substr(trim((string)($_POST['bio_en'] ?? '')), 0, 60000, 'UTF-8');
            $phone = preg_replace('/[^0-9]/', '', clean_text($_POST['phone'] ?? '', 20));
            $email = strtolower(clean_text($_POST['email'] ?? '', 120));
            $address = clean_text($_POST['address'] ?? '', 255);
            $sym = clean_text($_POST['symbol_no'] ?? '', 20);
            $ord = (int)($_POST['display_order'] ?? 0);
            $act = isset($_POST['is_active']) ? 1 : 0;
            if ($pid <= 0 || $name === '') {
                setFlash('error', 'पद र नाम अनिवार्य।');
                redirect('election-candidates.php?cycle=' . $cycleId);
            }
            $photo = $_POST['existing_photo'] ?? '';
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $up = uploadFile($_FILES['photo'], 'elections');
                if (!empty($up['success'])) $photo = $up['path'];
            }
            if ($cid > 0) {
                $db->prepare('UPDATE election_candidates SET position_id=?, name=?, name_en=?, photo=?, bio_np=?, bio_en=?, phone=?, email=?, address=?, symbol_no=?, display_order=?, is_active=? WHERE id=? AND cycle_id=?')
                    ->execute([$pid, $name, $nameEn, $photo ?: null, $bioNp, $bioEn, $phone ?: null, $email ?: null, $address ?: null, $sym ?: null, $ord, $act, $cid, $cycleId]);
                setFlash('success', 'उम्मेदवार अपडेट भयो।');
            } else {
                $db->prepare('INSERT INTO election_candidates (cycle_id, position_id, name, name_en, photo, bio_np, bio_en, phone, email, address, symbol_no, display_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$cycleId, $pid, $name, $nameEn, $photo ?: null, $bioNp, $bioEn, $phone ?: null, $email ?: null, $address ?: null, $sym ?: null, $ord, $act]);
                setFlash('success', 'नयाँ उम्मेदवार थपियो।');
            }
            redirect('election-candidates.php?cycle=' . $cycleId);
        } elseif ($action === 'delete_candidate') {
            $cid = (int)($_POST['candidate_id'] ?? 0);
            if ($cid > 0) {
                $db->prepare('DELETE FROM election_votes WHERE candidate_id=? AND cycle_id=?')->execute([$cid, $cycleId]);
                $db->prepare('DELETE FROM election_candidates WHERE id=? AND cycle_id=?')->execute([$cid, $cycleId]);
                setFlash('success', 'उम्मेदवार मेटाइयो।');
            }
            redirect('election-candidates.php?cycle=' . $cycleId);
        }
    } catch (Throwable $e) {
        setFlash('error', 'त्रुटि: ' . $e->getMessage());
        redirect('election-candidates.php?cycle=' . $cycleId);
    }
}

/* Edit pre-load */
$editPos = null;
if (($epid = (int)($_GET['edit_pos'] ?? 0)) > 0) {
    $st = $db->prepare('SELECT * FROM election_positions WHERE id=? AND cycle_id=?');
    $st->execute([$epid, $cycleId]);
    $editPos = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$editCand = null;
if (($ecid = (int)($_GET['edit_cand'] ?? 0)) > 0) {
    $st = $db->prepare('SELECT * FROM election_candidates WHERE id=? AND cycle_id=?');
    $st->execute([$ecid, $cycleId]);
    $editCand = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$positions = $db->prepare('SELECT * FROM election_positions WHERE cycle_id=? ORDER BY display_order, id');
$positions->execute([$cycleId]);
$positions = $positions->fetchAll(PDO::FETCH_ASSOC) ?: [];
$posMap = [];
foreach ($positions as $p) $posMap[(int)$p['id']] = $p;

$cands = $db->prepare('SELECT * FROM election_candidates WHERE cycle_id=? ORDER BY position_id, display_order, id');
$cands->execute([$cycleId]);
$cands = $cands->fetchAll(PDO::FETCH_ASSOC) ?: [];

$committeeTypes = $db->query('SELECT id, name_np FROM committee_types WHERE is_active=1 ORDER BY display_order, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$postsMaster = $db->query('SELECT * FROM election_posts WHERE is_active=1 ORDER BY display_order, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$panel = (string)($_GET['panel'] ?? 'positions'); // positions|candidates
if (!in_array($panel, ['positions', 'candidates'], true)) $panel = 'positions';
if ($editCand) $panel = 'candidates';
if ($editPos) $panel = 'positions';
?>
<div class="container-fluid py-3">
<?php
echo adminPageHeader(
    'उम्मेदवार व्यवस्थापन',
    'fa-user-tie',
    htmlspecialchars($cycle['title_np']) . ' — पद र उम्मेदवार थप/सम्पादन',
    '<a class="btn btn-outline-secondary btn-sm" href="election-information.php?edit=' . $cycleId . '"><i class="fas fa-arrow-left me-1"></i>चक्र फर्कनुहोस्</a> '
    . '<a class="btn btn-outline-primary btn-sm" href="election-voting-attendance.php?cycle=' . $cycleId . '"><i class="fas fa-person-booth me-1"></i>Voting Attendance</a> '
    . '<a class="btn btn-outline-success btn-sm" href="election-results.php?cycle=' . $cycleId . '"><i class="fas fa-chart-bar me-1"></i>नतिजा</a>'
);
?>
<?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

<?php
$allCycles = $db->query('SELECT id, title_np FROM election_cycles ORDER BY sort_order ASC, id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (count($allCycles) > 1):
?>
<div class="card admin-table-card mb-3"><div class="card-body py-2">
    <form method="get" class="row g-2 align-items-center">
        <div class="col-auto"><label class="form-label small mb-0">चक्र छान्नुहोस्:</label></div>
        <div class="col-md-5"><select class="form-select form-select-sm" name="cycle" onchange="this.form.submit()">
            <?php foreach ($allCycles as $oc): ?>
                <option value="<?php echo (int)$oc['id']; ?>" <?php echo ((int)$oc['id'] === $cycleId) ? 'selected' : ''; ?>><?php echo htmlspecialchars($oc['title_np']); ?></option>
            <?php endforeach; ?>
        </select></div>
    </form>
</div></div>
<?php endif; ?>

<ul class="nav nav-tabs admin-nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $panel === 'positions' ? 'active' : ''; ?>" href="?cycle=<?php echo $cycleId; ?>&panel=positions">
            <i class="fas fa-briefcase me-2"></i>पद व्यवस्थापन
            <span class="badge bg-success ms-1"><?php echo count($positions); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $panel === 'candidates' ? 'active' : ''; ?>" href="?cycle=<?php echo $cycleId; ?>&panel=candidates">
            <i class="fas fa-user-plus me-2"></i>उम्मेदवार व्यवस्थापन
            <span class="badge bg-success ms-1"><?php echo count($cands); ?></span>
        </a>
    </li>
</ul>

<div class="row g-3">
    <?php /* पहिले col-lg-5 + लुकेको col-lg-7 = आधा चौडाइ मात्र देखिन्थ्यो; सक्रिय ट्याब पूर्ण चौडाइ */ ?>
    <div class="col-12 <?php echo $panel === 'positions' ? '' : 'd-none'; ?>">
        <div class="card admin-table-card mb-3">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-briefcase me-2"></i><?php echo $editPos ? 'पद सम्पादन' : 'नयाँ पद थप्नुहोस्'; ?></h6></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_position">
                    <input type="hidden" name="position_id" value="<?php echo $editPos ? (int)$editPos['id'] : 0; ?>">

                    <?php if (!empty($postsMaster)): ?>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Master पदबाट छान्नुहोस् <small class="text-muted">(title, समिति, सिट auto-fill हुन्छ)</small></label>
                        <select class="form-select form-select-lg post-master-select" name="post_id" id="post_master_sel"
                                style="background-color:#fff;border:1px solid #d1d5db;border-radius:10px;padding:10px 36px 10px 14px;font-size:14px;font-weight:600;color:#1f2937;box-shadow:0 1px 2px rgba(15,23,42,.04);width:100%;">
                            <option value="">— Master पद छान्नुहोस् —</option>
                            <?php foreach ($postsMaster as $pm): ?>
                                <option value="<?php echo (int)$pm['id']; ?>"
                                    data-title-np="<?php echo htmlspecialchars($pm['title_np']); ?>"
                                    data-title-en="<?php echo htmlspecialchars($pm['title_en']); ?>"
                                    data-ctid="<?php echo (int)($pm['committee_type_id'] ?? 0); ?>"
                                    data-seats="<?php echo (int)$pm['default_seats']; ?>"
                                    data-maxv="<?php echo (int)$pm['default_max_votes']; ?>"
                                    <?php echo ((int)($editPos['post_id'] ?? 0) === (int)$pm['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pm['title_np']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><a href="election-posts.php" target="_blank">+ नयाँ Master पद थप्नुहोस्</a></div>
                    </div>
                    <?php else: ?>
                    <div class="col-12"><div class="alert alert-info py-2 mb-1 small">पहिले <a href="election-posts.php">पद Master</a> मा पद बनाउनुहोस्, अनि यहाँ छनोट गर्न मिल्छ।</div></div>
                    <?php endif; ?>

                    <div class="col-md-7"><label class="form-label small">पदको नाम (नेपाली) *</label>
                        <input class="form-control" name="title_np" id="pos_title_np" required value="<?php echo htmlspecialchars($editPos['title_np'] ?? ''); ?>">
                    </div>
                    <div class="col-md-5"><label class="form-label small">Title (English)</label>
                        <input class="form-control" name="title_en" id="pos_title_en" value="<?php echo htmlspecialchars($editPos['title_en'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label small">सिट संख्या</label>
                        <input type="number" min="1" class="form-control" name="seats" id="pos_seats" value="<?php echo (int)($editPos['seats'] ?? 1); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label small">प्रति-मतदाता मत</label>
                        <input type="number" min="1" class="form-control" name="max_votes_per_voter" id="pos_maxv" value="<?php echo (int)($editPos['max_votes_per_voter'] ?? 1); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label small">क्रम</label>
                        <input type="number" class="form-control" name="display_order" value="<?php echo (int)($editPos['display_order'] ?? 0); ?>">
                    </div>
                    <div class="col-md-8"><label class="form-label small">समिति (नतिजापछि स्वतः थप्न)</label>
                        <select class="form-select" name="committee_type_id" id="pos_ctype">
                            <option value="">— छान्नुहोस् —</option>
                            <?php foreach ($committeeTypes as $ct): ?>
                                <option value="<?php echo (int)$ct['id']; ?>" <?php echo ((int)($editPos['committee_type_id'] ?? 0) === (int)$ct['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ct['name_np']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="pact" value="1" <?php echo !empty($editPos['is_active']) || !$editPos ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="pact">सक्रिय</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="fas fa-save me-1"></i>बचत</button>
                        <a class="btn btn-outline-secondary" href="election-candidates.php?cycle=<?php echo $cycleId; ?>&panel=positions">नयाँ</a></div>
                </form>
                <script>
                (function(){
                    var sel = document.getElementById('post_master_sel');
                    if (!sel) return;
                    sel.addEventListener('change', function(){
                        var o = sel.options[sel.selectedIndex];
                        if (!o || !o.value) return;
                        var setIfEmpty = function(id, val){ var el=document.getElementById(id); if(el && !el.value){ el.value = val||''; } };
                        setIfEmpty('pos_title_np', o.dataset.titleNp);
                        setIfEmpty('pos_title_en', o.dataset.titleEn);
                        var ct = document.getElementById('pos_ctype');
                        if (ct && (!ct.value || ct.value==='0') && o.dataset.ctid && o.dataset.ctid !== '0') ct.value = o.dataset.ctid;
                        var s = document.getElementById('pos_seats');
                        if (s && (s.value==='' || s.value==='1') && o.dataset.seats) s.value = o.dataset.seats;
                        var mv = document.getElementById('pos_maxv');
                        if (mv && (mv.value==='' || mv.value==='1') && o.dataset.maxv) mv.value = o.dataset.maxv;
                    });
                })();
                </script>
            </div>
        </div>
        <div class="card admin-table-card">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2"></i>पद सूची (<?php echo count($positions); ?>)</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>पद</th><th>सिट</th><th>उम्मेदवार</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($positions as $p):
                        $cnt = 0; foreach ($cands as $cd) if ((int)$cd['position_id'] === (int)$p['id']) $cnt++; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['title_np']); ?> <?php if (empty($p['is_active'])): ?><span class="badge bg-secondary">निष्क्रिय</span><?php endif; ?></td>
                            <td><?php echo (int)$p['seats']; ?></td>
                            <td><?php echo $cnt; ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="?cycle=<?php echo $cycleId; ?>&panel=positions&edit_pos=<?php echo (int)$p['id']; ?>"><i class="fas fa-pen"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('यो पद, सबै उम्मेदवार र मत मेटाउने?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete_position">
                                    <input type="hidden" name="position_id" value="<?php echo (int)$p['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($positions)): ?><tr><td colspan="4" class="text-center text-muted py-3">अझै पद थपिएको छैन।</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 <?php echo $panel === 'candidates' ? '' : 'd-none'; ?>">
        <div class="card admin-table-card mb-3">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-user-plus me-2"></i><?php echo $editCand ? 'उम्मेदवार सम्पादन' : 'नयाँ उम्मेदवार थप्नुहोस्'; ?></h6></div>
            <div class="card-body">
                <?php if (empty($positions)): ?>
                    <div class="alert alert-warning mb-0">पहिले पद थप्नुहोस्।</div>
                <?php else: ?>
                <form method="post" enctype="multipart/form-data" class="row g-2">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_candidate">
                    <input type="hidden" name="candidate_id" value="<?php echo $editCand ? (int)$editCand['id'] : 0; ?>">
                    <input type="hidden" name="existing_photo" value="<?php echo htmlspecialchars($editCand['photo'] ?? ''); ?>">
                    <div class="col-md-6"><label class="form-label small">पद *</label>
                        <select class="form-select" name="position_id" required>
                            <option value="">— छान्नुहोस् —</option>
                            <?php foreach ($positions as $p): ?>
                                <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)($editCand['position_id'] ?? 0) === (int)$p['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['title_np']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label small">चिन्ह नं.</label>
                        <input class="form-control" name="symbol_no" value="<?php echo htmlspecialchars($editCand['symbol_no'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3"><label class="form-label small">क्रम</label>
                        <input type="number" class="form-control" name="display_order" value="<?php echo (int)($editCand['display_order'] ?? 0); ?>">
                    </div>
                    <div class="col-md-6"><label class="form-label small">पूरा नाम (नेपाली) *</label>
                        <input class="form-control" name="name" required value="<?php echo htmlspecialchars($editCand['name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6"><label class="form-label small">Name (English)</label>
                        <input class="form-control" name="name_en" value="<?php echo htmlspecialchars($editCand['name_en'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label small">फोन</label>
                        <input class="form-control" name="phone" value="<?php echo htmlspecialchars($editCand['phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label small">इमेल</label>
                        <input class="form-control" name="email" value="<?php echo htmlspecialchars($editCand['email'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4"><label class="form-label small">ठेगाना</label>
                        <input class="form-control" name="address" value="<?php echo htmlspecialchars($editCand['address'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6"><label class="form-label small">फोटो</label>
                        <input type="file" class="form-control" name="photo" accept="image/*">
                        <?php if (!empty($editCand['photo'])): ?>
                            <div class="small mt-1"><img src="<?php echo SITE_URL . htmlspecialchars(ltrim($editCand['photo'], '/')); ?>" style="width:60px;height:60px;object-fit:cover;border-radius:8px;"></div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="cact" value="1" <?php echo !empty($editCand['is_active']) || !$editCand ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="cact">सक्रिय</label>
                        </div>
                    </div>
                    <div class="col-12"><label class="form-label small">परिचय / Bio (नेपाली)</label>
                        <textarea class="form-control" name="bio_np" rows="3"><?php echo htmlspecialchars($editCand['bio_np'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12"><label class="form-label small">Bio (English)</label>
                        <textarea class="form-control" name="bio_en" rows="2"><?php echo htmlspecialchars($editCand['bio_en'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="fas fa-save me-1"></i>बचत</button>
                        <a class="btn btn-outline-secondary" href="election-candidates.php?cycle=<?php echo $cycleId; ?>&panel=candidates">नयाँ</a></div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card admin-table-card">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-users me-2"></i>उम्मेदवार सूची (<?php echo count($cands); ?>)</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>फोटो</th><th>नाम</th><th>पद</th><th>चिन्ह</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($cands as $cd):
                        $pos = $posMap[(int)$cd['position_id']] ?? null; ?>
                        <tr>
                            <td><?php if (!empty($cd['photo'])): ?><img src="<?php echo SITE_URL . htmlspecialchars(ltrim($cd['photo'], '/')); ?>" style="width:36px;height:36px;object-fit:cover;border-radius:50%;"><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
                            <td><?php echo htmlspecialchars($cd['name']); ?> <?php if (empty($cd['is_active'])): ?><span class="badge bg-secondary">निष्क्रिय</span><?php endif; ?></td>
                            <td class="small"><?php echo $pos ? htmlspecialchars($pos['title_np']) : '—'; ?></td>
                            <td><?php echo htmlspecialchars($cd['symbol_no'] ?? ''); ?></td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="?cycle=<?php echo $cycleId; ?>&panel=candidates&edit_cand=<?php echo (int)$cd['id']; ?>"><i class="fas fa-pen"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('उम्मेदवार र सम्बन्धित मतहरू मेटाउने?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete_candidate">
                                    <input type="hidden" name="candidate_id" value="<?php echo (int)$cd['id']; ?>">
                                    <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($cands)): ?><tr><td colspan="5" class="text-center text-muted py-3">अझै उम्मेदवार छैन।</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
