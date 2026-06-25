<?php
/**
 * निर्वाचन — नतिजा (live count) + विजेताहरूलाई समिति सदस्यमा रूपान्तरण
 */
$pageTitle = 'निर्वाचन नतिजा';
$currentPage = 'election-results';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

require_once __DIR__ . '/../includes/election-tables.php';
$db = getDB();
ensureElectionTables($db);
ensureElectionVotingTables($db);

$cycleId = (int)($_GET['cycle'] ?? 0);
/* cycle param नदिए — पहिलो उपलब्ध cycle auto-select */
if ($cycleId <= 0) {
    $autoId = (int)($db->query('SELECT id FROM election_cycles ORDER BY voting_enabled DESC, sort_order ASC, id DESC LIMIT 1')->fetchColumn() ?: 0);
    if ($autoId > 0) {
        redirect('election-results.php?cycle=' . $autoId);
    } else {
        setFlash('error', 'पहिले «निर्वाचन जानकारी» बाट निर्वाचन चक्र थप्नुहोस्।');
        redirect('election-information.php');
    }
}
$cs = $db->prepare('SELECT * FROM election_cycles WHERE id=? LIMIT 1');
$cs->execute([$cycleId]);
$cycle = $cs->fetch(PDO::FETCH_ASSOC);
if (!$cycle) { setFlash('error','चक्र भेटिएन।'); redirect('election-information.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'convert_winners') {
            $tenureName = clean_text($_POST['tenure_name'] ?? '', 100) ?: ('कार्यकाल ' . date('Y'));
            $tenureNameNp = clean_text($_POST['tenure_name_np'] ?? '', 100) ?: $tenureName;
            $startDate = trim((string)($_POST['start_date'] ?? '')) ?: date('Y-m-d');
            $endDate   = trim((string)($_POST['end_date'] ?? '')) ?: date('Y-m-d', strtotime('+4 years'));

            /* प्रत्येक पदको winners (seats बराबर) committee_members मा थप */
            $positions = $db->prepare('SELECT * FROM election_positions WHERE cycle_id=? AND is_active=1 AND committee_type_id IS NOT NULL ORDER BY display_order, id');
            $positions->execute([$cycleId]);
            $positions = $positions->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $sk = $db->prepare('SELECT COUNT(*) FROM election_positions WHERE cycle_id=? AND is_active=1 AND committee_type_id IS NULL');
            $sk->execute([$cycleId]);
            $skippedCount = (int)$sk->fetchColumn();

            $tenureCache = [];  /* committee_type_id => tenure_id */
            $totalAdded = 0;
            foreach ($positions as $pos) {
                $ctid = (int)$pos['committee_type_id'];
                if (!isset($tenureCache[$ctid])) {
                    /* current tenure clear गर र नयाँ बनाउ */
                    $db->prepare('UPDATE committee_tenures SET is_current=0 WHERE committee_type_id=?')->execute([$ctid]);
                    $db->prepare('INSERT INTO committee_tenures (committee_type_id, tenure_name, tenure_name_np, start_date, end_date, is_current, is_active) VALUES (?,?,?,?,?,1,1)')
                        ->execute([$ctid, $tenureName, $tenureNameNp, $startDate, $endDate]);
                    $tenureCache[$ctid] = (int)$db->lastInsertId();
                }
                $tid = $tenureCache[$ctid];
                $seats = max(1, (int)$pos['seats']);
                /* winners — vote count desc, उही गन्तीमा लाभ id asc */
                $w = $db->prepare(
                    'SELECT c.id, c.name, c.name_en, c.phone, c.email, c.address, c.photo, COUNT(v.id) AS votes
                     FROM election_candidates c
                     LEFT JOIN election_votes v ON v.candidate_id=c.id
                     WHERE c.cycle_id=? AND c.position_id=? AND c.is_active=1
                     GROUP BY c.id ORDER BY votes DESC, c.display_order ASC, c.id ASC LIMIT ' . $seats
                );
                $w->execute([$cycleId, (int)$pos['id']]);
                $winners = $w->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $ord = 0;
                foreach ($winners as $win) {
                    $ord++;
                    /* duplicate बच्न: same tenure मा same candidate नाम पहिल्यै छ कि ? */
                    $dup = $db->prepare('SELECT id FROM committee_members WHERE tenure_id=? AND name=? AND position=? LIMIT 1');
                    $dup->execute([$tid, $win['name'], $pos['title_np']]);
                    if ($dup->fetch()) continue;
                    $db->prepare('INSERT INTO committee_members (tenure_id, name, name_en, position, position_en, phone, email, address, photo, display_order, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,1)')
                        ->execute([$tid, $win['name'], $win['name_en'], $pos['title_np'], $pos['title_en'], $win['phone'], $win['email'], $win['address'], $win['photo'], $ord]);
                    $totalAdded++;
                }
            }
            $db->prepare('UPDATE election_cycles SET results_finalized=1, voting_enabled=0 WHERE id=?')->execute([$cycleId]);
            $msg = $totalAdded . ' विजेता समिति सदस्यमा रूपान्तरण भयो। मतदान बन्द गरियो।';
            if ($skippedCount > 0) $msg .= ' (' . $skippedCount . ' पदमा committee तोकिएको छैन — skip गरियो।)';
            setFlash('success', $msg);
            redirect('election-results.php?cycle=' . $cycleId);
        }
    } catch (Throwable $e) {
        setFlash('error', 'त्रुटि: ' . $e->getMessage());
        redirect('election-results.php?cycle=' . $cycleId);
    }
}

/* Aggregate results */
$rows = $db->prepare('
    SELECT p.id AS position_id, p.title_np AS position_np, p.seats, p.committee_type_id,
           c.id AS candidate_id, c.name, c.symbol_no, c.photo,
           COUNT(v.id) AS votes
    FROM election_positions p
    LEFT JOIN election_candidates c ON c.position_id=p.id AND c.is_active=1
    LEFT JOIN election_votes v ON v.candidate_id=c.id
    WHERE p.cycle_id=? AND p.is_active=1
    GROUP BY p.id, c.id
    ORDER BY p.display_order, p.id, votes DESC, c.display_order, c.id');
$rows->execute([$cycleId]);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];

$grouped = [];
foreach ($rows as $r) {
    $pid = (int)$r['position_id'];
    if (!isset($grouped[$pid])) {
        $grouped[$pid] = ['position_np' => $r['position_np'], 'seats' => (int)$r['seats'], 'committee_type_id' => $r['committee_type_id'], 'candidates' => []];
    }
    if ($r['candidate_id']) $grouped[$pid]['candidates'][] = $r;
}
$totalVoters = (int)$db->query('SELECT COUNT(*) FROM election_vote_submissions WHERE cycle_id=' . $cycleId)->fetchColumn();
$sourceCounts = ['member_portal' => 0, 'manual_staff' => 0];
try {
    $sc = $db->prepare('SELECT source, COUNT(*) AS c FROM election_vote_submissions WHERE cycle_id=? GROUP BY source');
    $sc->execute([$cycleId]);
    foreach ($sc->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sr) {
        $sourceCounts[(string)($sr['source'] ?? '')] = (int)($sr['c'] ?? 0);
    }
} catch (Throwable $e) {
}
?>
<div class="container-fluid py-3">
<?php
echo adminPageHeader(
    'निर्वाचन नतिजा',
    'fa-chart-bar',
    htmlspecialchars($cycle['title_np']) . ' — कुल मतदाता: ' . $totalVoters . ' (Digital: ' . (int)$sourceCounts['member_portal'] . ', Manual: ' . (int)$sourceCounts['manual_staff'] . ')' . (empty($cycle['results_finalized']) ? '' : ' • अन्तिम भयो'),
    '<a class="btn btn-outline-secondary btn-sm" href="election-candidates.php?cycle=' . $cycleId . '"><i class="fas fa-arrow-left me-1"></i>उम्मेदवार</a> '
    . '<a class="btn btn-outline-primary btn-sm" href="election-voting-attendance.php?cycle=' . $cycleId . '"><i class="fas fa-person-booth me-1"></i>Voting Attendance</a>'
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

<div class="row g-3">
<?php foreach ($grouped as $pid => $g): ?>
    <div class="col-lg-6">
        <div class="card admin-table-card h-100">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0"><?php echo htmlspecialchars($g['position_np']); ?></h6>
                <span class="badge bg-primary">सिट: <?php echo (int)$g['seats']; ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead><tr><th></th><th>उम्मेदवार</th><th class="text-end">मत</th></tr></thead>
                    <tbody>
                    <?php $rank = 0; $maxVotes = max(1, (int)($g['candidates'][0]['votes'] ?? 1));
                          foreach ($g['candidates'] as $i => $c): $rank++; $isWin = $i < (int)$g['seats']; ?>
                        <tr class="<?php echo $isWin ? 'table-success' : ''; ?>">
                            <td><?php if ($isWin): ?><i class="fas fa-trophy text-warning"></i><?php endif; ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if (!empty($c['photo'])): ?><img src="<?php echo SITE_URL . htmlspecialchars(ltrim($c['photo'], '/')); ?>" style="width:32px;height:32px;object-fit:cover;border-radius:50%;"><?php endif; ?>
                                    <div><?php echo htmlspecialchars($c['name']); ?> <?php if (!empty($c['symbol_no'])): ?><small class="text-muted">#<?php echo htmlspecialchars($c['symbol_no']); ?></small><?php endif; ?></div>
                                </div>
                                <div class="progress mt-1" style="height:4px;"><div class="progress-bar" style="width:<?php echo round((int)$c['votes']/$maxVotes*100); ?>%"></div></div>
                            </td>
                            <td class="text-end fw-bold"><?php echo (int)$c['votes']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($g['candidates'])): ?><tr><td colspan="3" class="text-center text-muted py-3">उम्मेदवार छैन।</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($grouped)): ?>
    <div class="col-12"><div class="alert alert-info">अझै पद/उम्मेदवार थपिएको छैन।</div></div>
<?php endif; ?>
</div>

<?php if (!empty($grouped) && empty($cycle['results_finalized'])): ?>
<div class="card admin-table-card mt-4">
    <div class="card-header"><h6 class="mb-0"><i class="fas fa-arrow-right-arrow-left me-2"></i>विजेताहरूलाई समिति सदस्यमा रूपान्तरण</h6></div>
    <div class="card-body">
        <p class="small text-muted">प्रत्येक पदको शीर्ष उम्मेदवारहरू (सिट संख्या बराबर) सम्बन्धित समितिमा नयाँ कार्यकालमा थपिनेछन्। यो action ले मतदान पनि बन्द गर्नेछ।</p>
        <form method="post" class="row g-2" onsubmit="return confirm('विजेताहरू समितिमा थप्ने र मतदान बन्द गर्ने?');">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="convert_winners">
            <div class="col-md-4"><label class="form-label small">कार्यकाल नाम</label>
                <input class="form-control" name="tenure_name_np" value="कार्यकाल <?php echo date('Y'); ?>" required></div>
            <div class="col-md-3"><label class="form-label small">सुरु मिति</label>
                <input type="date" class="form-control" name="start_date" value="<?php echo date('Y-m-d'); ?>"></div>
            <div class="col-md-3"><label class="form-label small">अन्त्य मिति</label>
                <input type="date" class="form-control" name="end_date" value="<?php echo date('Y-m-d', strtotime('+4 years')); ?>"></div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-success w-100"><i class="fas fa-check me-1"></i>रूपान्तरण</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
