<?php
$pageTitle = 'निर्वाचन मतदान उपस्थिति';
$currentPage = 'election-voting-attendance';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

require_once __DIR__ . '/../includes/election-tables.php';
$db = getDB();
ensureElectionTables($db);
ensureElectionVotingTables($db);

$cycleId = (int)($_GET['cycle'] ?? 0);
if ($cycleId <= 0) {
    $cycleId = (int)($db->query('SELECT id FROM election_cycles ORDER BY voting_enabled DESC, sort_order ASC, id DESC LIMIT 1')->fetchColumn() ?: 0);
}
if ($cycleId <= 0) {
    setFlash('error', 'पहिले निर्वाचन चक्र बनाउनुहोस्।');
    redirect('election-information.php');
}
$cs = $db->prepare('SELECT * FROM election_cycles WHERE id=? LIMIT 1');
$cs->execute([$cycleId]);
$cycle = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$cycle) {
    setFlash('error', 'निर्वाचन चक्र भेटिएन।');
    redirect('election-information.php');
}

$manualVoteOpen = isElectionVotingOpen($cycle);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'mark_manual_vote') {
        $memberKey = trim((string)($_POST['member_key'] ?? ''));
        $phone = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
        $proofType = (string)($_POST['proof_type'] ?? 'id_card');
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500, 'UTF-8');
        $picks = $_POST['picks'] ?? [];
        if (!in_array($proofType, ['id_card', 'license', 'citizenship', 'passport', 'other'], true)) {
            $proofType = 'other';
        }
        try {
            if ($memberKey === '' && $phone === '') {
                throw new RuntimeException('Member ID वा फोन आवश्यक छ।');
            }
            $where = [];
            $params = [];
            if ($memberKey !== '') {
                $where[] = '(m.sadasyata_number=? OR CAST(m.id AS CHAR)=?)';
                array_push($params, $memberKey, $memberKey);
            }
            if ($phone !== '') {
                $where[] = 'm.phone=?';
                $params[] = $phone;
            }
            $ms = $db->prepare('SELECT m.id, m.name, m.sadasyata_number, m.phone FROM members m WHERE (' . implode(' OR ', $where) . ') AND m.is_active=1 LIMIT 1');
            $ms->execute($params);
            $member = $ms->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$member) {
                throw new RuntimeException('Member ID/phone match हुने सक्रिय सदस्य भेटिएन।');
            }
            $memberId = (int)$member['id'];
            $dup = $db->prepare('SELECT id, source, submitted_at FROM election_vote_submissions WHERE cycle_id=? AND member_id=? LIMIT 1');
            $dup->execute([$cycleId, $memberId]);
            if ($old = $dup->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Duplicate: यो सदस्यले पहिल्यै voting attendance/vote गरिसक्नु भएको छ (' . (string)$old['source'] . ').');
            }

            $positions = $db->prepare('SELECT id, max_votes_per_voter FROM election_positions WHERE cycle_id=? AND is_active=1');
            $positions->execute([$cycleId]);
            $posRows = $positions->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $posLimit = [];
            foreach ($posRows as $p) {
                $posLimit[(int)$p['id']] = max(1, (int)$p['max_votes_per_voter']);
            }
            $validCandIds = [];
            $cs2 = $db->prepare('SELECT id, position_id FROM election_candidates WHERE cycle_id=? AND is_active=1');
            $cs2->execute([$cycleId]);
            foreach ($cs2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) {
                $validCandIds[(int)$c['id']] = (int)$c['position_id'];
            }

            $db->beginTransaction();
            $adminId = (int)($_SESSION['admin_id'] ?? 0);
            $adminName = (string)($_SESSION['admin_name'] ?? 'Admin');
            $db->prepare('INSERT INTO election_vote_submissions (cycle_id, member_id, source, proof_type, verified_by_admin_id, verified_by_name, note, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$cycleId, $memberId, 'manual_staff', $proofType, $adminId ?: null, $adminName, $note, substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 64), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);

            if (is_array($picks) && !empty($picks)) {
                if (!$manualVoteOpen) {
                    throw new RuntimeException('मतदान समय बन्द छ — attendance मात्र वा समय खोलेर vote दर्ता गर्नुहोस्।');
                }
                $ins = $db->prepare('INSERT INTO election_votes (cycle_id, position_id, candidate_id, member_id) VALUES (?,?,?,?)');
                foreach ($picks as $pid => $candList) {
                    $pid = (int)$pid;
                    if (!isset($posLimit[$pid])) continue;
                    if (!is_array($candList)) $candList = [$candList];
                    $candList = array_slice(array_unique(array_map('intval', $candList)), 0, $posLimit[$pid]);
                    foreach ($candList as $candId) {
                        if (($validCandIds[$candId] ?? 0) !== $pid) continue;
                        $ins->execute([$cycleId, $pid, $candId, $memberId]);
                    }
                }
            }
            $db->commit();
            setFlash('success', 'Manual voting attendance सुरक्षित भयो।');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('error', $e->getMessage());
        }
        redirect('election-voting-attendance.php?cycle=' . $cycleId);
    }
}

$allCycles = $db->query('SELECT id, title_np, voting_enabled FROM election_cycles ORDER BY voting_enabled DESC, sort_order ASC, id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$positionsSt = $db->prepare('SELECT * FROM election_positions WHERE cycle_id=? AND is_active=1 ORDER BY display_order, id');
$positionsSt->execute([$cycleId]);
$positions = $positionsSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$candSt = $db->prepare('SELECT * FROM election_candidates WHERE cycle_id=? AND is_active=1 ORDER BY position_id, display_order, id');
$candSt->execute([$cycleId]);
$candidates = $candSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$candByPos = [];
foreach ($candidates as $c) {
    $candByPos[(int)$c['position_id']][] = $c;
}

$recent = $db->prepare('SELECT s.*, m.name, m.sadasyata_number, m.phone FROM election_vote_submissions s LEFT JOIN members m ON m.id=s.member_id WHERE s.cycle_id=? ORDER BY s.submitted_at DESC LIMIT 50');
$recent->execute([$cycleId]);
$recentRows = $recent->fetchAll(PDO::FETCH_ASSOC) ?: [];
$digitalCount = 0;
$manualCount = 0;
try {
    $sc = $db->prepare('SELECT source, COUNT(*) AS c FROM election_vote_submissions WHERE cycle_id=? GROUP BY source');
    $sc->execute([$cycleId]);
    foreach ($sc->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sr) {
        if (($sr['source'] ?? '') === 'member_portal') $digitalCount = (int)$sr['c'];
        if (($sr['source'] ?? '') === 'manual_staff') $manualCount = (int)$sr['c'];
    }
} catch (Throwable $e) {
}
?>
<div class="container-fluid py-3">
<?php echo adminPageHeader('निर्वाचन मतदान उपस्थिति', 'fa-person-booth', htmlspecialchars((string)$cycle['title_np']) . ' — digital/manual duplicate-safe attendance', '<a class="btn btn-outline-secondary btn-sm" href="election-information.php"><i class="fas fa-arrow-left me-1"></i>निर्वाचन</a> <a class="btn btn-outline-success btn-sm" href="election-results.php?cycle=' . $cycleId . '"><i class="fas fa-chart-bar me-1"></i>नतिजा</a>'); ?>
<?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

<div class="card admin-table-card mb-3"><div class="card-body">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-6"><label class="form-label small">निर्वाचन चक्र</label><select name="cycle" class="form-select" onchange="this.form.submit()">
            <?php foreach ($allCycles as $cy): ?><option value="<?php echo (int)$cy['id']; ?>" <?php echo (int)$cy['id']===$cycleId?'selected':''; ?>><?php echo htmlspecialchars((string)$cy['title_np']); ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-md-6"><div class="alert <?php echo $manualVoteOpen ? 'alert-success' : 'alert-warning'; ?> mb-0 py-2">
            <?php echo $manualVoteOpen ? 'मतदान समय खुला छ — manual vote पनि दर्ता गर्न मिल्छ।' : 'मतदान समय बन्द छ — duplicate check मात्र; मत दर्ता हुँदैन।'; ?>
            <div class="small mt-1">Digital: <?php echo (int)$digitalCount; ?> · Manual: <?php echo (int)$manualCount; ?></div>
        </div></div>
    </form>
</div></div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card admin-table-card h-100">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-id-card me-2"></i>Manual Voting Attendance</h6></div>
            <div class="card-body">
                <form method="post" class="row g-2" onsubmit="return confirm('यो सदस्यको voting attendance/vote confirm गर्ने? Confirm पछि duplicate मानिन्छ।');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="mark_manual_vote">
                    <div class="col-md-6"><label class="form-label small">Member ID / सदस्य नं.</label><input name="member_key" class="form-control" placeholder="MEM-... / ID"></div>
                    <div class="col-md-6"><label class="form-label small">Phone</label><input name="phone" class="form-control" placeholder="98XXXXXXXX"></div>
                    <div class="col-md-6"><label class="form-label small">Proof type</label><select name="proof_type" class="form-select">
                        <option value="id_card">सदस्य ID Card</option>
                        <option value="license">License</option>
                        <option value="citizenship">नागरिकता</option>
                        <option value="passport">Passport</option>
                        <option value="other">Other</option>
                    </select></div>
                    <div class="col-md-6"><label class="form-label small">Note</label><input name="note" class="form-control" placeholder="कर्मचारी नोट"></div>
                    <div class="col-12">
                        <div class="alert alert-info small py-2 mb-0">
                            Member Portal बाट vote गरेको सदस्य manual मा आए duplicate देखिन्छ। Manual मा proof verify गरेर confirm गरेपछि यो सदस्य फेरि digital/manual vote गर्न पाउँदैन।
                        </div>
                    </div>
                    <?php if ($manualVoteOpen && !empty($positions)): ?>
                    <div class="col-12"><hr><div class="small text-muted mb-2">कागजी/manual ballot यहीँ दर्ता गर्नुपरे उम्मेदवार छान्नुहोस्। उम्मेदवार खाली राखे <strong>attendance only</strong> record हुन्छ।</div></div>
                    <?php foreach ($positions as $pos): $list = $candByPos[(int)$pos['id']] ?? []; $maxV = max(1, (int)$pos['max_votes_per_voter']); ?>
                        <div class="col-12 border rounded p-2">
                            <div class="fw-semibold small mb-2"><?php echo htmlspecialchars((string)$pos['title_np']); ?> <span class="badge bg-info text-dark">Max <?php echo $maxV; ?></span></div>
                            <?php foreach ($list as $cd): ?>
                                <label class="form-check form-check-inline">
                                    <input class="form-check-input" type="<?php echo $maxV > 1 ? 'checkbox' : 'radio'; ?>" name="picks[<?php echo (int)$pos['id']; ?>]<?php echo $maxV > 1 ? '[]' : ''; ?>" value="<?php echo (int)$cd['id']; ?>">
                                    <span class="form-check-label"><?php echo htmlspecialchars((string)$cd['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                            <?php if (empty($list)): ?><div class="small text-muted">उम्मेदवार छैन।</div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="col-12"><button class="btn btn-primary w-100"><i class="fas fa-check me-1"></i>Confirm Manual Attendance</button></div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card admin-table-card">
            <div class="card-header d-flex justify-content-between"><h6 class="mb-0">Voting Members List</h6><span class="badge bg-secondary">Recent <?php echo count($recentRows); ?></span></div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>Member</th><th>Source</th><th>Proof</th><th>Verified By</th><th>Time</th></tr></thead><tbody>
                <?php foreach ($recentRows as $r): ?>
                    <tr><td><strong><?php echo htmlspecialchars((string)($r['name'] ?? '—')); ?></strong><div class="small text-muted"><?php echo htmlspecialchars((string)($r['sadasyata_number'] ?? '')); ?> <?php echo htmlspecialchars((string)($r['phone'] ?? '')); ?></div></td><td><?php echo htmlspecialchars((string)$r['source']); ?></td><td><?php echo htmlspecialchars((string)$r['proof_type']); ?></td><td><?php echo htmlspecialchars((string)$r['verified_by_name']); ?></td><td class="small"><?php echo htmlspecialchars((string)$r['submitted_at']); ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($recentRows)): ?><tr><td colspan="5" class="text-center text-muted py-3">अझै voting attendance छैन।</td></tr><?php endif; ?>
                </tbody></table></div>
        </div>
    </div>
</div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
