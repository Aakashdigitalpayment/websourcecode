<?php
/**
 * Member Portal — निर्वाचन मतदान
 * - Nepal Time window मा मात्र खुल्छ
 * - One-time submission (election_vote_submissions UNIQUE key)
 * - Confirmation step पछि submit
 * - Live tally (केवल member portal/admin मा)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/election-tables.php';
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};
requireMemberLogin();
memberSecurityHeaders();

/**
 * Analyze voting pattern for member behavior tracking
 */
function analyzeVotingPattern($selectedCandidates, $positions, $cycle) {
    global $candByPos;
    $pattern = [
        'type' => 'mixed',
        'score' => 50,
        'diversity_score' => 0
    ];
    
    // Count candidates per position
    $candidatesPerPosition = [];
    foreach ($positions as $pos) {
        $candidatesPerPosition[(int)$pos['id']] = 0;
    }
    
    // Analyze selection pattern
    $totalPositions = count($positions);
    $filledPositions = 0;
    
    foreach ($selectedCandidates as $candId) {
        // Find which position this candidate belongs to
        foreach ($positions as $pos) {
            if (isset($candByPos[(int)$pos['id']])) {
                foreach ($candByPos[(int)$pos['id']] as $candidate) {
                    if ((int)$candidate['id'] === (int)$candId) {
                        $candidatesPerPosition[(int)$pos['id']]++;
                        if ($candidatesPerPosition[(int)$pos['id']] > 0) {
                            $filledPositions++;
                        }
                        break 2;
                    }
                }
                break;
            }
        }
    }
    
    // Calculate pattern score and diversity
    if ($totalPositions > 0) {
        $fillRate = $filledPositions / $totalPositions;
        $candidateCount = count(array_unique($selectedCandidates));
        
        // Pattern types
        if ($fillRate >= 0.8) {
            $pattern['type'] = 'comprehensive';
            $pattern['score'] = 85 + ($candidateCount * 3);
        } elseif ($fillRate >= 0.5) {
            $pattern['type'] = 'selective';
            $pattern['score'] = 60 + ($candidateCount * 2);
        } else {
            $pattern['type'] = 'minimal';
            $pattern['score'] = 30 + $candidateCount;
        }
        
        // Diversity bonus
        $pattern['diversity_score'] = min($candidateCount * 10, 50);
    }
    
    return $pattern;
}

$db = getDB();
ensureElectionTables($db);
ensureElectionVotingTables($db);

$mem = currentMember();
if (!$mem) { header('Location: login.php?msg=session_expired'); exit; }
$memberId = (int)$mem['id'];

/* चक्र चयन: ?cycle=ID, या hal active चक्र */
$cycleId = (int)($_GET['cycle'] ?? 0);
if ($cycleId > 0) {
    $cs = $db->prepare('SELECT * FROM election_cycles WHERE id=? AND is_published=1 LIMIT 1');
    $cs->execute([$cycleId]);
    $cycle = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
    $cycle = $db->query("SELECT * FROM election_cycles WHERE is_published=1 ORDER BY voting_enabled DESC, sort_order ASC, id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($cycle) $cycleId = (int)$cycle['id'];
}

$pageTitle = $_t('मतदान', 'Voting') . ' — ' . ($cycle['title_np'] ?? $_t('निर्वाचन', 'Election'));
$_active = 'election';

$flash = '';
$flashType = 'info';

/* पहिल्यै vote गरेको छ ? */
$alreadyVoted = false;
if ($cycle) {
    $vs = $db->prepare('SELECT id, submitted_at FROM election_vote_submissions WHERE cycle_id=? AND member_id=? LIMIT 1');
    $vs->execute([$cycleId, $memberId]);
    $sub = $vs->fetch(PDO::FETCH_ASSOC);
    $alreadyVoted = (bool)$sub;
}

$votingOpen = $cycle ? isElectionVotingOpen($cycle) : false;
$voteEnded = false;
if ($cycle && !empty($cycle['vote_end_at'])) {
    try {
        $tz = new DateTimeZone('Asia/Kathmandu');
        $voteEnded = (new DateTime('now', $tz)) > (new DateTime((string)$cycle['vote_end_at'], $tz));
    } catch (Throwable $e) {
        $voteEnded = false;
    }
}

/* Submit handle */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_vote' && $cycle) {
    if (!verifyCSRFToken()) {
        $flash = $_t('सुरक्षा जाँच असफल।', 'Security check failed.'); $flashType = 'danger';
    } elseif ($alreadyVoted) {
        $flash = $_t('तपाईंले पहिल्यै मतदान गरिसक्नु भएको छ।', 'You have already voted.'); $flashType = 'warning';
    } elseif (!$votingOpen) {
        $flash = $_t('मतदान समय बाहिर छ।', 'Voting is closed for this time window.'); $flashType = 'warning';
    } else {
        $picks = $_POST['picks'] ?? [];   /* picks[position_id] = [candidate_id,...] */
        if (!is_array($picks)) $picks = [];
        try {
            $positions = $db->prepare('SELECT id, max_votes_per_voter FROM election_positions WHERE cycle_id=? AND is_active=1');
            $positions->execute([$cycleId]);
            $positions = $positions->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $posLimit = []; foreach ($positions as $p) $posLimit[(int)$p['id']] = (int)$p['max_votes_per_voter'];

            $validCandIds = [];
            $cs2 = $db->prepare('SELECT id, position_id FROM election_candidates WHERE cycle_id=? AND is_active=1');
            $cs2->execute([$cycleId]);
            foreach ($cs2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) $validCandIds[(int)$c['id']] = (int)$c['position_id'];

            $db->beginTransaction();
            $db->prepare('INSERT INTO election_vote_submissions (cycle_id, member_id, source, ip, user_agent) VALUES (?,?,?,?,?)')
                ->execute([$cycleId, $memberId, 'member_portal', substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 64), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);

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
            $db->commit();
            $alreadyVoted = true;
            $flash = $_t('तपाईंको मत सफलतापूर्वक रेकर्ड भयो। धन्यवाद!', 'Your vote has been recorded successfully. Thank you!');
            $flashType = 'success';
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $sqlState = ($e instanceof PDOException) ? ($e->getCode() ?: '') : '';
            if ($sqlState === '23000' || str_contains($e->getMessage(), 'uniq_cycle_member') || str_contains($e->getMessage(), 'Duplicate')) {
                $alreadyVoted = true;
                $flash = $_t('तपाईंले पहिल्यै मतदान गरिसक्नु भएको छ।', 'You have already voted.'); $flashType = 'warning';
            } else {
                $flash = $_t('त्रुटि', 'Error') . ': ' . $e->getMessage(); $flashType = 'danger';
            }
        }
    }
}

/* Recompute after submit handling so UI reflects latest state immediately */
$canVoteNow = (bool)($cycle && !$alreadyVoted && $votingOpen);
$resultsVisible = (bool)($cycle && !empty($cycle['results_finalized']));

/* डाटा लोड — समिति-wise grouping */
$positions = []; $candByPos = []; $samitiGroups = [];
if ($cycle) {
    $ps = $db->prepare('SELECT p.*, ct.name_np AS ctype_np FROM election_positions p
                        LEFT JOIN committee_types ct ON ct.id=p.committee_type_id
                        WHERE p.cycle_id=? AND p.is_active=1 ORDER BY p.display_order, p.id');
    $ps->execute([$cycleId]);
    $positions = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!empty($positions)) {
        $cs2 = $db->prepare('SELECT * FROM election_candidates WHERE cycle_id=? AND is_active=1 ORDER BY position_id, display_order, id');
        $cs2->execute([$cycleId]);
        foreach ($cs2->fetchAll(PDO::FETCH_ASSOC) ?: [] as $c) $candByPos[(int)$c['position_id']][] = $c;

        foreach ($positions as $pp) {
            $key = (int)($pp['committee_type_id'] ?? 0);
            if (!isset($samitiGroups[$key])) {
                $samitiGroups[$key] = ['name' => $pp['ctype_np'] ?: $_t('अन्य', 'Other'), 'positions' => []];
            }
            $samitiGroups[$key]['positions'][] = $pp;
        }
    }
}

/* Live tally — सदस्य पोर्टलमा देखाइन्छ */
$tally = [];
if ($cycle) {
    $tq = $db->prepare('SELECT candidate_id, COUNT(*) AS n FROM election_votes WHERE cycle_id=? GROUP BY candidate_id');
    $tq->execute([$cycleId]);
    foreach ($tq->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) $tally[(int)$t['candidate_id']] = (int)$t['n'];
}
$totalVoters = 0;
if ($cycle) {
    $tv = $db->prepare('SELECT COUNT(*) FROM election_vote_submissions WHERE cycle_id=?');
    $tv->execute([$cycleId]);
    $totalVoters = (int)$tv->fetchColumn();
}

require __DIR__ . '/includes/chrome.php';
?>
<style>
.vote-card{border:2px solid transparent;transition:.2s;cursor:pointer;}
.vote-card.selected{border-color:var(--primary-color);background:rgba(26,95,42,.04);}
.vote-photo{width:100%;height:180px;object-fit:cover;border-radius:8px;}
.vote-photo-empty{height:180px;display:flex;align-items:center;justify-content:center;background:#f3f4f6;border-radius:8px;color:#9ca3af;}
.tally-bar{height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;margin-top:6px;}
.tally-bar > div{height:100%;background:linear-gradient(90deg,var(--primary-color),var(--primary-light));}
.vote-cycle-head{display:flex;align-items:flex-start;justify-content:space-between;gap:.65rem;flex-wrap:wrap;}
.vote-cycle-tenure{background:var(--primary-color);color:#fff;border-radius:999px;padding:.26rem .62rem;font-size:.74rem;font-weight:700;white-space:nowrap;}
@media (max-width:575px){.vote-cycle-tenure{width:100%;text-align:left;}}
</style>
<main class="mp-main py-4">
<div class="mp-container mp-container-medium">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h1 class="h4 mb-0"><i class="fas fa-check-to-slot me-2"></i><?php echo $_t('मतदान', 'Voting'); ?></h1>
        <?php if ($cycle): ?><span class="badge bg-light text-dark border"><?php echo $_t('कुल मतदाता', 'Total Voters'); ?>: <?php echo $totalVoters; ?></span><?php endif; ?>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>

    <?php if (!$cycle): ?>
        <div class="alert alert-info"><?php echo $_t('कुनै सक्रिय निर्वाचन छैन।', 'No active election found.'); ?></div>
    <?php else: ?>
        <div class="card mb-3 shadow-sm">
            <div class="card-body">
                <div class="vote-cycle-head">
                    <h2 class="h5 mb-1"><?php echo htmlspecialchars($cycle['title_np']); ?></h2>
                    <?php if (!empty($cycle['period_label'])): ?>
                        <span class="vote-cycle-tenure"><i class="fas fa-calendar-alt me-1"></i><?php echo $_t('कार्यकाल', 'Tenure'); ?>: <?php echo htmlspecialchars((string)$cycle['period_label']); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($cycle['vote_start_at'])): ?>
                    <div class="small mt-1"><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars((string)$cycle['vote_start_at']); ?> <?php echo $_t('देखि', 'to'); ?> <?php echo htmlspecialchars((string)$cycle['vote_end_at']); ?> <?php echo $_t('सम्म (नेपाल समय)', '(Nepal Time)'); ?></div>
                <?php endif; ?>
                <div class="mt-2">
                    <?php if ($alreadyVoted): ?>
                        <span class="badge bg-success"><i class="fas fa-check me-1"></i><?php echo $_t('मत दिइसकिएको छ', 'Vote already submitted'); ?></span>
                    <?php elseif ($votingOpen): ?>
                        <span class="badge bg-success"><?php echo $_t('मतदान खुला छ', 'Voting is open'); ?></span>
                    <?php elseif (!empty($cycle['voting_enabled'])): ?>
                        <span class="badge bg-warning text-dark"><?php echo $_t('समय बाहिर', 'Outside time window'); ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><?php echo $_t('मतदान बन्द', 'Voting closed'); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!$alreadyVoted && !empty($cycle['vote_start_at']) && !empty($cycle['vote_end_at'])): ?>
                    <div class="small mt-2 text-muted" id="voteCountdown"
                         data-start="<?php echo htmlspecialchars((string)$cycle['vote_start_at'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-end="<?php echo htmlspecialchars((string)$cycle['vote_end_at'], ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="fas fa-hourglass-half me-1"></i> समय गणना हुँदैछ...
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($positions)): ?>
        <form method="post" id="voteForm" novalidate>
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="submit_vote">
            <?php if (!$canVoteNow): ?>
                <div class="alert alert-light border mb-3">
                    <?php if ($alreadyVoted): ?>
                        <i class="fas fa-check-circle text-success me-1"></i> तपाईंले मतदान गरिसक्नुभएको छ। उम्मेदवार सूची हेर्न सक्नुहुन्छ।
                    <?php else: ?>
                        <i class="fas fa-clock text-warning me-1"></i> मतदान अहिले खुला छैन। मतदान समय खुल्दा मात्र मत दिन मिल्छ।
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php
            $samitiKeys = array_keys($samitiGroups);
            $firstKey = $samitiKeys[0] ?? 0;
            ?>
            <?php if (count($samitiGroups) > 1): ?>
            <ul class="nav nav-pills justify-content-center mb-3 vote-samiti-tabs" role="tablist">
                <?php foreach ($samitiGroups as $sk => $grp): ?>
                    <li class="nav-item"><button class="nav-link <?php echo $sk===$firstKey ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#vgrp-<?php echo (int)$sk; ?>" type="button"><i class="fas fa-users-gear me-1"></i><?php echo htmlspecialchars($grp['name']); ?></button></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <div class="tab-content">
            <?php foreach ($samitiGroups as $sk => $grp): ?>
                <div class="tab-pane fade <?php echo $sk===$firstKey ? 'show active' : ''; ?>" id="vgrp-<?php echo (int)$sk; ?>">
                    <?php if (count($samitiGroups) > 1): ?><h6 class="text-center text-muted mb-3"><i class="fas fa-users-gear me-1"></i><?php echo htmlspecialchars($grp['name']); ?></h6><?php endif; ?>
                    <?php foreach ($grp['positions'] as $pos):
                        $list = $candByPos[(int)$pos['id']] ?? []; $maxV = (int)$pos['max_votes_per_voter']; ?>
                        <div class="card mb-3 shadow-sm">
                            <div class="card-header d-flex justify-content-between flex-wrap">
                                <h6 class="mb-0"><i class="fas fa-briefcase me-1"></i><?php echo htmlspecialchars($pos['title_np']); ?></h6>
                                <span class="badge bg-info text-dark"><?php echo $_t('अधिकतम मत', 'Max Votes'); ?>: <?php echo $maxV; ?></span>
                            </div>
                            <div class="card-body">
                                <?php if (empty($list)): ?>
                                    <div class="text-muted small"><?php echo $_t('यस पदमा उम्मेदवार छैन।', 'No candidates for this position.'); ?></div>
                                <?php else: ?>
                                <div class="row g-3" data-position="<?php echo (int)$pos['id']; ?>" data-max="<?php echo $maxV; ?>">
                                    <?php foreach ($list as $cd): $cnt = $tally[(int)$cd['id']] ?? 0; $maxT = max(1, !empty($tally) ? max($tally) : 1); ?>
                                        <div class="col-md-4 col-sm-6">
                                            <label class="vote-card card h-100 p-2 mb-0">
                                                <input type="<?php echo $maxV > 1 ? 'checkbox' : 'radio'; ?>" name="picks[<?php echo (int)$pos['id']; ?>]<?php echo $maxV > 1 ? '[]' : ''; ?>" value="<?php echo (int)$cd['id']; ?>" class="form-check-input mb-2 vote-input" <?php echo $canVoteNow ? '' : 'disabled'; ?>>
                                                <?php if (!empty($cd['photo'])): ?>
                                                    <img src="<?php echo SITE_URL . htmlspecialchars(ltrim((string)$cd['photo'], '/')); ?>" class="vote-photo" alt="">
                                                <?php else: ?>
                                                    <div class="vote-photo-empty"><i class="fas fa-user fa-3x"></i></div>
                                                <?php endif; ?>
                                                <div class="mt-2">
                                                    <strong><?php echo htmlspecialchars($cd['name']); ?></strong>
                                                    <?php if (!empty($cd['symbol_no'])): ?> <span class="badge bg-secondary">#<?php echo htmlspecialchars($cd['symbol_no']); ?></span><?php endif; ?>
                                                </div>
                                                <?php if (!empty($cd['bio_np'])): ?>
                                                    <div class="small text-muted mt-1"><?php echo nl2br(htmlspecialchars(mb_substr($cd['bio_np'], 0, 120))); ?><?php echo mb_strlen($cd['bio_np']) > 120 ? '…' : ''; ?></div>
                                                <?php endif; ?>
                                                <?php if ($resultsVisible): ?>
                                                <div class="small text-muted mt-2"><?php echo $_t('हालसम्म मत', 'Votes so far'); ?>: <strong><?php echo $cnt; ?></strong>
                                                    <div class="tally-bar"><div style="width:<?php echo round($cnt/$maxT*100); ?>%"></div></div>
                                                </div>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>

            <?php if ($canVoteNow): ?>
                <div class="d-flex justify-content-end gap-2 mb-5">
                    <button type="button" class="btn btn-primary btn-lg" id="reviewBtn"><i class="fas fa-eye me-1"></i><?php echo $_t('समीक्षा र पुष्टि', 'Review & Confirm'); ?></button>
                </div>
            <?php endif; ?>

            <!-- Confirmation Modal -->
            <div class="modal fade" id="confirmModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header"><h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i><?php echo $_t('पुष्टि गर्नुहोस्', 'Confirm'); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body" id="confirmBody"></div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo $_t('सच्याउनुहोस्', 'Edit'); ?></button>
                            <button type="submit" class="btn btn-success"><i class="fas fa-check me-1"></i><?php echo $_t('मत दर्ज गर्नुहोस्', 'Submit Vote'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($canVoteNow): ?>
        <script>
        (function(){
            var picks = {};
            document.querySelectorAll('[data-position]').forEach(function(g){
                var max = parseInt(g.dataset.max,10) || 1;
                g.addEventListener('change', function(e){
                    if (!e.target.classList.contains('vote-input')) return;
                    var checked = g.querySelectorAll('.vote-input:checked');
                    if (checked.length > max) {
                        e.target.checked = false;
                        alert('<?php echo addslashes($_t('यस पदमा अधिकतम', 'You can cast maximum')); ?> ' + max + ' <?php echo addslashes($_t('मत मात्र दिन सकिन्छ।', 'votes for this position.')); ?>');
                    }
                    g.querySelectorAll('.vote-card').forEach(function(c){ c.classList.remove('selected'); });
                    g.querySelectorAll('.vote-input:checked').forEach(function(i){ i.closest('.vote-card').classList.add('selected'); });
                });
            });
            document.getElementById('reviewBtn').addEventListener('click', function(){
                var html = '<p class="small text-muted"><?php echo addslashes($_t('तल देखाइएका उम्मेदवारहरूलाई मत दिनुभएको हो? पुष्टि पछि सच्याउन मिल्दैन।', 'Are you sure you want to vote for the candidates below? You cannot edit after confirmation.')); ?></p><ul class="list-group">';
                var any = false;
                document.querySelectorAll('[data-position]').forEach(function(g){
                    var posTitle = g.closest('.card').querySelector('.card-header h6').innerText;
                    var checked = g.querySelectorAll('.vote-input:checked');
                    var names = [];
                    checked.forEach(function(i){ names.push(i.closest('.vote-card').querySelector('strong').innerText); });
                    if (names.length) any = true;
                    html += '<li class="list-group-item"><strong>' + posTitle + ':</strong> ' + (names.length ? names.join(', ') : '<em class="text-muted"><?php echo addslashes($_t('कोही छानिएको छैन', 'No one selected')); ?></em>') + '</li>';
                });
                html += '</ul>';
                if (!any) { alert('<?php echo addslashes($_t('कम्तिमा एक उम्मेदवार छान्नुहोस्।', 'Please select at least one candidate.')); ?>'); return; }
                document.getElementById('confirmBody').innerHTML = html;
                new bootstrap.Modal(document.getElementById('confirmModal')).show();
            });
        })();
        </script>
        <?php endif; ?>

        <?php if ($resultsVisible): ?>
            <h2 class="h6 mb-3"><i class="fas fa-chart-bar me-2"></i><?php echo $_t('हालको परिणाम (live)', 'Current Results (Live)'); ?></h2>
            <?php foreach ($positions as $pos): $list = $candByPos[(int)$pos['id']] ?? [];
                  usort($list, fn($a,$b) => ($tally[(int)$b['id']]??0) - ($tally[(int)$a['id']]??0));
                  $maxT = max(1, !empty($tally) ? max($tally) : 1); ?>
                <div class="card mb-3 shadow-sm">
                    <div class="card-header"><h6 class="mb-0"><?php echo htmlspecialchars($pos['title_np']); ?> <small class="text-muted">(सिट: <?php echo (int)$pos['seats']; ?>)</small></h6></div>
                    <div class="card-body">
                        <?php $i=0; foreach ($list as $cd): $i++; $cnt=$tally[(int)$cd['id']]??0; $isLead = $i <= (int)$pos['seats']; ?>
                            <div class="d-flex align-items-center gap-3 py-2 border-bottom">
                                <?php if (!empty($cd['photo'])): ?><img src="<?php echo SITE_URL . htmlspecialchars(ltrim((string)$cd['photo'], '/')); ?>" style="width:42px;height:42px;object-fit:cover;border-radius:50%;"><?php else: ?><span class="text-muted"><i class="fas fa-user-circle fa-2x"></i></span><?php endif; ?>
                                <div class="flex-grow-1">
                                    <div><?php if ($isLead): ?><i class="fas fa-trophy text-warning me-1"></i><?php endif; ?><strong><?php echo htmlspecialchars($cd['name']); ?></strong>
                                        <?php if (!empty($cd['symbol_no'])): ?> <span class="badge bg-secondary">#<?php echo htmlspecialchars($cd['symbol_no']); ?></span><?php endif; ?>
                                    </div>
                                    <div class="tally-bar"><div style="width:<?php echo round($cnt/$maxT*100); ?>%"></div></div>
                                </div>
                                <div class="fw-bold"><?php echo $cnt; ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($list)): ?><div class="text-muted small"><?php echo $_t('उम्मेदवार छैन।', 'No candidates.'); ?></div><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php elseif ($cycle): ?>
            <div class="alert alert-secondary mt-3">
                <i class="fas fa-hourglass-half me-1"></i> परिणाम निर्वाचन समितिले final status राखेपछि मात्र देखाइनेछ।
            </div>
        <?php endif; ?>
        <?php else: ?>
            <div class="alert alert-info"><?php echo $_t('यस निर्वाचनका लागि उम्मेदवार/पदहरू अझै प्रकाशित गरिएको छैन।', 'Candidates/positions are not published for this election yet.'); ?></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</main>
<script>
(function () {
    var el = document.getElementById('voteCountdown');
    if (!el) return;
    var startRaw = el.getAttribute('data-start') || '';
    var endRaw = el.getAttribute('data-end') || '';
    if (!startRaw || !endRaw) return;

    // Parse "YYYY-MM-DD HH:MM:SS" into local Date.
    function parseDbDateTime(s) {
        var m = String(s).trim().match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (!m) return null;
        return new Date(
            Number(m[1]),
            Number(m[2]) - 1,
            Number(m[3]),
            Number(m[4]),
            Number(m[5]),
            Number(m[6] || 0)
        );
    }
    var start = parseDbDateTime(startRaw);
    var end = parseDbDateTime(endRaw);
    if (!start || !end) return;

    function fmt(ms) {
        if (ms < 0) ms = 0;
        var sec = Math.floor(ms / 1000);
        var d = Math.floor(sec / 86400); sec %= 86400;
        var h = Math.floor(sec / 3600); sec %= 3600;
        var m = Math.floor(sec / 60); var s = sec % 60;
        var parts = [];
        if (d) parts.push(d + ' दिन');
        if (h || d) parts.push(h + ' घण्टा');
        if (m || h || d) parts.push(m + ' मिनेट');
        parts.push(s + ' सेकेन्ड');
        return parts.join(' ');
    }

    function tick() {
        var now = new Date();
        if (now < start) {
            el.innerHTML = '<i class="fas fa-hourglass-start me-1"></i> <?php echo addslashes($_t('मतदान सुरु हुन बाँकी', 'Voting starts in')); ?>: <strong>' + fmt(start - now) + '</strong>';
        } else if (now <= end) {
            el.innerHTML = '<i class="fas fa-hourglass-half me-1"></i> <?php echo addslashes($_t('मतदान बन्द हुन बाँकी', 'Voting ends in')); ?>: <strong>' + fmt(end - now) + '</strong>';
        } else {
            el.innerHTML = '<i class="fas fa-hourglass-end me-1"></i> <?php echo addslashes($_t('मतदान समय समाप्त भयो।', 'Voting time has ended.')); ?>';
            clearInterval(timer);
        }
    }
    tick();
    var timer = setInterval(tick, 1000);
})();
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
