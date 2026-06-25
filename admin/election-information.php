<?php
$pageTitle = 'निर्वाचन जानकारी';
$currentPage = 'election-information';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

require_once __DIR__ . '/../includes/election-tables.php';
$db = getDB();
ensureElectionTables($db);
ensureElectionVotingTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save_cycle') {
            $cid = (int)($_POST['cycle_id'] ?? 0);
            $titleNp = clean_text($_POST['title_np'] ?? '', 200);
            $titleEn = clean_text($_POST['title_en'] ?? '', 200);
            if ($titleNp === '') {
                setFlash('error', 'शीर्षक (नेपाली) अनिवार्य छ।');
                $redCid = (int)($_POST['cycle_id'] ?? 0);
                redirect('election-information.php' . ($redCid > 0 ? ('?edit=' . $redCid) : ''));
            } else {
                $introNp = mb_substr(trim((string)($_POST['intro_np'] ?? '')), 0, 60000, 'UTF-8');
                $introEn = mb_substr(trim((string)($_POST['intro_en'] ?? '')), 0, 60000, 'UTF-8');
                $period = clean_text($_POST['period_label'] ?? '', 80);
                $df = trim((string)($_POST['date_from'] ?? ''));
                $dt = trim((string)($_POST['date_to'] ?? ''));
                $df = preg_match('/^\d{4}-\d{2}-\d{2}$/', $df) ? $df : null;
                $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt) ? $dt : null;
                $pub = isset($_POST['is_published']) ? 1 : 0;
                $nav = isset($_POST['show_in_navbar']) ? 1 : 0;
                $sort = (int)($_POST['sort_order'] ?? 0);
                /* मतदान schedule (Nepal Time) */
                $vs = trim((string)($_POST['vote_start_at'] ?? ''));
                $ve = trim((string)($_POST['vote_end_at'] ?? ''));
                if ($vs === '') {
                    $vsDate = trim((string)($_POST['vote_start_date'] ?? ''));
                    $vsTime = trim((string)($_POST['vote_start_time'] ?? ''));
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $vsDate) && preg_match('/^\d{1,2}:\d{2}\s?(AM|PM)$/i', $vsTime)) {
                        $vs = $vsDate . ' ' . strtoupper(str_replace(' ', '', $vsTime));
                    }
                }
                if ($ve === '') {
                    $veDate = trim((string)($_POST['vote_end_date'] ?? ''));
                    $veTime = trim((string)($_POST['vote_end_time'] ?? ''));
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $veDate) && preg_match('/^\d{1,2}:\d{2}\s?(AM|PM)$/i', $veTime)) {
                        $ve = $veDate . ' ' . strtoupper(str_replace(' ', '', $veTime));
                    }
                }
                $vs = preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $vs) ? str_replace('T', ' ', $vs) : null;
                $ve = preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $ve) ? str_replace('T', ' ', $ve) : null;
                if ($vs === null && isset($_POST['vote_start_date'], $_POST['vote_start_time'])) {
                    $vsDate = trim((string)($_POST['vote_start_date'] ?? ''));
                    $vsTime = trim((string)($_POST['vote_start_time'] ?? ''));
                    $ts = strtotime($vsDate . ' ' . $vsTime);
                    if ($ts !== false) $vs = date('Y-m-d H:i:s', $ts);
                }
                if ($ve === null && isset($_POST['vote_end_date'], $_POST['vote_end_time'])) {
                    $veDate = trim((string)($_POST['vote_end_date'] ?? ''));
                    $veTime = trim((string)($_POST['vote_end_time'] ?? ''));
                    $te = strtotime($veDate . ' ' . $veTime);
                    if ($te !== false) $ve = date('Y-m-d H:i:s', $te);
                }
                $vEnabled = isset($_POST['voting_enabled']) ? 1 : 0;
                if ($cid > 0) {
                    $db->prepare(
                        'UPDATE election_cycles SET title_np=?, title_en=?, intro_np=?, intro_en=?, period_label=?, date_from=?, date_to=?, is_published=?, show_in_navbar=?, sort_order=?, vote_start_at=?, vote_end_at=?, voting_enabled=? WHERE id=?'
                    )->execute([$titleNp, $titleEn, $introNp, $introEn, $period ?: null, $df, $dt, $pub, $nav, $sort, $vs, $ve, $vEnabled, $cid]);
                    setFlash('success', 'निर्वाचन चक्र अपडेट भयो।');
                } else {
                    $db->prepare(
                        'INSERT INTO election_cycles (title_np, title_en, intro_np, intro_en, period_label, date_from, date_to, is_published, show_in_navbar, sort_order, vote_start_at, vote_end_at, voting_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    )->execute([$titleNp, $titleEn, $introNp, $introEn, $period ?: null, $df, $dt, $pub, $nav, $sort, $vs, $ve, $vEnabled]);
                    $cid = (int)$db->lastInsertId();
                    setFlash('success', 'नयाँ निर्वाचन चक्र थपियो।');
                }
                redirect('election-information.php?edit=' . $cid);
            }
        } elseif ($action === 'delete_cycle') {
            $cid = (int)($_POST['cycle_id'] ?? 0);
            if ($cid > 0) {
                $db->prepare('DELETE FROM election_milestones WHERE cycle_id=?')->execute([$cid]);
                $db->prepare('DELETE FROM election_cycles WHERE id=?')->execute([$cid]);
                setFlash('success', 'चक्र मेटाइयो।');
            }
            redirect('election-information.php');
        } elseif ($action === 'save_milestone') {
            $mid = (int)($_POST['milestone_id'] ?? 0);
            $cid = (int)($_POST['cycle_id'] ?? 0);
            $titleNp = clean_text($_POST['m_title_np'] ?? '', 220);
            $titleEn = clean_text($_POST['m_title_en'] ?? '', 220);
            if ($cid <= 0 || $titleNp === '') {
                setFlash('error', 'चक्र र शीर्षक (नेपाली) अनिवार्य।');
                redirect('election-information.php' . ($cid > 0 ? ('?milestones=' . $cid) : ''));
            } else {
                $ed = trim((string)($_POST['m_event_date'] ?? ''));
                $ed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed) ? $ed : null;
                $detailNp = mb_substr(trim((string)($_POST['m_detail_np'] ?? '')), 0, 60000, 'UTF-8');
                $detailEn = mb_substr(trim((string)($_POST['m_detail_en'] ?? '')), 0, 60000, 'UTF-8');
                $ord = (int)($_POST['m_display_order'] ?? 0);
                $actv = isset($_POST['m_is_active']) ? 1 : 0;
                $attachment = null;
                if (isset($_FILES['m_attachment']) && $_FILES['m_attachment']['error'] === UPLOAD_ERR_OK) {
                    $up = uploadFile($_FILES['m_attachment'], 'elections');
                    if (!empty($up['success'])) {
                        $attachment = $up['path'];
                    }
                }
                if ($mid > 0) {
                    if ($attachment) {
                        $db->prepare(
                            'UPDATE election_milestones SET event_date=?, title_np=?, title_en=?, detail_np=?, detail_en=?, attachment=?, display_order=?, is_active=? WHERE id=? AND cycle_id=?'
                        )->execute([$ed, $titleNp, $titleEn, $detailNp, $detailEn, $attachment, $ord, $actv, $mid, $cid]);
                    } else {
                        $db->prepare(
                            'UPDATE election_milestones SET event_date=?, title_np=?, title_en=?, detail_np=?, detail_en=?, display_order=?, is_active=? WHERE id=? AND cycle_id=?'
                        )->execute([$ed, $titleNp, $titleEn, $detailNp, $detailEn, $ord, $actv, $mid, $cid]);
                    }
                    setFlash('success', 'कार्यतालिका अपडेट भयो।');
                } else {
                    $db->prepare(
                        'INSERT INTO election_milestones (cycle_id, event_date, title_np, title_en, detail_np, detail_en, attachment, display_order, is_active) VALUES (?,?,?,?,?,?,?,?,?)'
                    )->execute([$cid, $ed, $titleNp, $titleEn, $detailNp, $detailEn, $attachment, $ord, $actv]);
                    setFlash('success', 'नयाँ चरण थपियो।');
                }
                redirect('election-information.php?milestones=' . $cid);
            }
        } elseif ($action === 'delete_milestone') {
            $mid = (int)($_POST['milestone_id'] ?? 0);
            $cid = (int)($_POST['cycle_id'] ?? 0);
            if ($mid > 0) {
                $db->prepare('DELETE FROM election_milestones WHERE id=?')->execute([$mid]);
                setFlash('success', 'चरण मेटाइयो।');
            }
            redirect('election-information.php?milestones=' . $cid);
        }
    } catch (Throwable $e) {
        setFlash('error', 'त्रुटि: ' . $e->getMessage());
        redirect('election-information.php');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$milestonesFor = (int)($_GET['milestones'] ?? 0);

/* Filters */
$tab = (string)($_GET['tab'] ?? 'all'); // all|upcoming|active|past|draft
$qSearch = trim((string)($_GET['q'] ?? ''));
$qFrom   = trim((string)($_GET['from'] ?? ''));
$qTo     = trim((string)($_GET['to'] ?? ''));
$panel   = (string)($_GET['panel'] ?? 'list'); // list|form
if (!in_array($panel, ['list', 'form'], true)) $panel = 'list';

$cycles = $db->query('SELECT * FROM election_cycles ORDER BY sort_order ASC, id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* Bucket each cycle by status (Asia/Kathmandu) */
try { $tz = new DateTimeZone('Asia/Kathmandu'); $now = new DateTime('now', $tz); }
catch (Throwable $e) { $tz = null; $now = null; }

$bucket = ['upcoming'=>[], 'active'=>[], 'past'=>[], 'draft'=>[]];
foreach ($cycles as $c) {
    if (empty($c['is_published'])) { $bucket['draft'][] = $c; continue; }
    $vs = trim((string)($c['vote_start_at'] ?? ''));
    $ve = trim((string)($c['vote_end_at'] ?? ''));
    if ($tz && $vs !== '' && $ve !== '') {
        try {
            $s = new DateTime($vs, $tz); $e = new DateTime($ve, $tz);
            if ($now < $s) $bucket['upcoming'][] = $c;
            elseif ($now > $e) $bucket['past'][] = $c;
            else { if (!empty($c['voting_enabled'])) $bucket['active'][] = $c; else $bucket['upcoming'][] = $c; }
            continue;
        } catch (Throwable $e2) { /* fall through */ }
    }
    if (!empty($c['voting_enabled'])) $bucket['active'][] = $c; else $bucket['upcoming'][] = $c;
}
$counts = ['all'=>count($cycles), 'upcoming'=>count($bucket['upcoming']), 'active'=>count($bucket['active']), 'past'=>count($bucket['past']), 'draft'=>count($bucket['draft'])];

/* Apply tab + search/date filter */
$filtered = $tab === 'all' ? $cycles : ($bucket[$tab] ?? []);
if ($qSearch !== '') {
    $needle = mb_strtolower($qSearch, 'UTF-8');
    $filtered = array_values(array_filter($filtered, static function ($c) use ($needle) {
        $hay = mb_strtolower(($c['title_np'] ?? '') . ' ' . ($c['title_en'] ?? '') . ' ' . ($c['period_label'] ?? ''), 'UTF-8');
        return mb_strpos($hay, $needle) !== false;
    }));
}
if ($qFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $qFrom)) {
    $filtered = array_values(array_filter($filtered, fn($c) => !empty($c['date_from']) && $c['date_from'] >= $qFrom));
}
if ($qTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $qTo)) {
    $filtered = array_values(array_filter($filtered, fn($c) => !empty($c['date_to']) && $c['date_to'] <= $qTo));
}

$cycleIds = array_map(static fn($r) => (int)$r['id'], $cycles);
if ($milestonesFor > 0 && !in_array($milestonesFor, $cycleIds, true)) {
    $milestonesFor = 0;
}
$editRow = null;
if ($editId > 0) {
    $st = $db->prepare('SELECT * FROM election_cycles WHERE id=? LIMIT 1');
    $st->execute([$editId]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editRow) $panel = 'form';
}
$milestoneRows = [];
if ($milestonesFor > 0) {
    $ms = $db->prepare('SELECT * FROM election_milestones WHERE cycle_id=? ORDER BY display_order ASC, event_date ASC, id ASC');
    $ms->execute([$milestonesFor]);
    $milestoneRows = $ms->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$navOnCount = (int)$db->query('SELECT COUNT(*) FROM election_cycles WHERE is_published=1 AND show_in_navbar=1')->fetchColumn();
$voteTimeOptions = function_exists('getUnifiedTimeOptions') ? getUnifiedTimeOptions('06:00', '20:00', 30) : [];
$voteStartDateVal = '';
$voteEndDateVal = '';
$voteStartTimeVal = '';
$voteEndTimeVal = '';
if ($editRow) {
    $vsRaw = trim((string)($editRow['vote_start_at'] ?? ''));
    $veRaw = trim((string)($editRow['vote_end_at'] ?? ''));
    if ($vsRaw !== '') {
        $ts = strtotime($vsRaw);
        if ($ts !== false) {
            $voteStartDateVal = date('Y-m-d', $ts);
            $voteStartTimeVal = date('h:i A', $ts);
        }
    }
    if ($veRaw !== '') {
        $te = strtotime($veRaw);
        if ($te !== false) {
            $voteEndDateVal = date('Y-m-d', $te);
            $voteEndTimeVal = date('h:i A', $te);
        }
    }
}

/* status badge helper */
$statusBadge = function (array $c) use ($tz, $now): string {
    if (empty($c['is_published'])) return '<span class="badge bg-secondary">मस्यौदा</span>';
    $vs = trim((string)($c['vote_start_at'] ?? '')); $ve = trim((string)($c['vote_end_at'] ?? ''));
    if ($tz && $vs && $ve) {
        try { $s = new DateTime($vs,$tz); $e = new DateTime($ve,$tz);
            if ($now < $s) return '<span class="badge bg-info text-dark">आगामी</span>';
            if ($now > $e) return '<span class="badge bg-dark">सकिएको</span>';
            return !empty($c['voting_enabled']) ? '<span class="badge bg-success">सक्रिय</span>' : '<span class="badge bg-warning text-dark">तय</span>';
        } catch (Throwable $e) {}
    }
    return !empty($c['voting_enabled']) ? '<span class="badge bg-success">सक्रिय</span>' : '<span class="badge bg-info text-dark">तय</span>';
};

?>
<div class="container-fluid py-3">
<?php
echo adminPageHeader(
    'निर्वाचन जानकारी',
    'fa-check-to-slot',
    'सञ्चालक/लेखा समिति निर्वाचन — सार्वजनिक पृष्ठ, मेनु देखाउने, कार्यतालिका।',
    '<a class="btn btn-outline-primary btn-sm" href="' . SITE_URL . 'election-information.php" target="_blank" rel="noopener"><i class="fas fa-external-link-alt me-1"></i>सार्वजनिक पृष्ठ</a>'
);
?>
<?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <?php if ($navOnCount > 0): ?>
        <span class="badge bg-success-subtle text-success border border-success border-opacity-25"><i class="fas fa-bars me-1"></i>मेनुमा सक्रिय: <?php echo $navOnCount; ?></span>
    <?php else: ?>
        <span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="fas fa-eye-slash me-1"></i>मेनुमा लुकेको</span>
    <?php endif; ?>
    <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 ms-1" data-bs-toggle="collapse" data-bs-target="#electionMenuHelp" aria-expanded="false" aria-controls="electionMenuHelp" title="कसरी मेनुमा देखाउने?">
        <i class="fas fa-circle-question"></i>
    </button>
</div>
<div class="collapse mb-3" id="electionMenuHelp">
    <div class="alert alert-info border-0 shadow-sm small mb-0">
        <strong>मेनु कहिले देखिन्छ?</strong> कुनै पनि चक्रमा <em>प्रकाशित</em> र <em>सूचना मेनुमा देखाउनुहोस्</em> दुवै चेक भए सूचना ड्रपडाउनमा «निर्वाचन जानकारी» देखिन्छ। निर्वाचन सकिएपछि चेक हटाए पुग्छ।
    </div>
</div>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <a class="nav-link <?php echo $panel === 'list' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['panel' => 'list'])); ?>">
            <i class="fas fa-list me-2"></i>सूची
            <span class="badge bg-success ms-1"><?php echo count($filtered); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $panel === 'form' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['panel' => 'form'])); ?>">
            <i class="fas fa-plus-circle me-2"></i><?php echo $editRow ? 'चक्र सम्पादन' : 'नयाँ चक्र थप्नुहोस्'; ?>
        </a>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade <?php echo $panel === 'list' ? 'show active' : ''; ?>" id="ec-list">
    <div class="row g-3">
    <div class="col-12">
        <div class="card admin-table-card h-100">
            <div class="card-header py-2">
                <ul class="nav nav-pills nav-sm gap-1 small">
                    <?php
                    $tabs = [
                        'all' => ['सबै', 'fa-list'],
                        'upcoming' => ['आगामी', 'fa-hourglass-start'],
                        'active' => ['सक्रिय', 'fa-circle-dot'],
                        'past' => ['सकिएको', 'fa-flag-checkered'],
                        'draft' => ['मस्यौदा', 'fa-pen-ruler'],
                    ];
                    foreach ($tabs as $tk => $tinfo):
                        $qs = $_GET; $qs['tab'] = $tk; ?>
                        <li class="nav-item"><a class="nav-link py-1 px-2 <?php echo $tab===$tk ? 'active' : ''; ?>" href="?<?php echo http_build_query($qs); ?>"><i class="fas <?php echo $tinfo[1]; ?> me-1"></i><?php echo $tinfo[0]; ?> <span class="badge bg-light text-dark ms-1"><?php echo (int)$counts[$tk]; ?></span></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="card-body py-2 border-bottom">
                <form method="get" class="row g-2 align-items-end">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                    <div class="col-12"><input type="search" class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($qSearch); ?>" placeholder="शीर्षक/अवधि खोज्नुहोस्..."></div>
                    <div class="col-6"><input type="text" class="form-control form-control-sm nepali-datepicker" name="from" value="<?php echo htmlspecialchars($qFrom); ?>" title="मिति देखि" placeholder="YYYY-MM-DD" autocomplete="off"></div>
                    <div class="col-6"><input type="text" class="form-control form-control-sm nepali-datepicker" name="to" value="<?php echo htmlspecialchars($qTo); ?>" title="मिति सम्म" placeholder="YYYY-MM-DD" autocomplete="off"></div>
                    <div class="col-12 d-flex gap-1">
                        <button class="btn btn-sm btn-primary flex-grow-1"><i class="fas fa-filter me-1"></i>फिल्टर</button>
                        <a class="btn btn-sm btn-outline-secondary" href="election-information.php" title="रिसेट"><i class="fas fa-undo"></i></a>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead><tr><th>शीर्षक</th><th>अवधि</th><th>स्थिति</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($filtered as $c): ?>
                        <tr<?php echo ($editId === (int)$c['id']) ? ' class="table-primary"' : ''; ?>>
                            <td>
                                <strong><?php echo htmlspecialchars(mb_substr($c['title_np'] ?? '', 0, 40)); ?></strong>
                                <?php if (!empty($c['period_label'])): ?><div class="small text-muted"><?php echo htmlspecialchars((string)$c['period_label']); ?></div><?php endif; ?>
                            </td>
                            <td class="small text-muted"><?php
                                echo htmlspecialchars((string)($c['date_from'] ?? ''));
                                if (!empty($c['date_to'])) echo '<br>—'.htmlspecialchars((string)$c['date_to']);
                            ?></td>
                            <td class="small">
                                <?php echo $statusBadge($c); ?>
                                <?php if (!empty($c['show_in_navbar'])): ?><span class="badge bg-primary">मेनु</span><?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="election-information.php?edit=<?php echo (int)$c['id']; ?>&panel=form" title="सम्पादन"><i class="fas fa-pen"></i></a>
                                <a class="btn btn-sm btn-outline-secondary" href="election-information.php?milestones=<?php echo (int)$c['id']; ?>" title="तालिका"><i class="fas fa-list-ol"></i></a>
                                <a class="btn btn-sm btn-outline-success" href="election-candidates.php?cycle=<?php echo (int)$c['id']; ?>" title="उम्मेदवार"><i class="fas fa-user-tie"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($filtered)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">कुनै रेकर्ड भेटिएन।</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    </div>
    </div>
    <div class="tab-pane fade <?php echo $panel === 'form' ? 'show active' : ''; ?>" id="ec-form">
    <div class="row g-3">
    <div class="col-12">
        <div class="card admin-table-card">
            <div class="card-header"><h6 class="mb-0"><?php echo $editRow ? 'चक्र सम्पादन' : 'नयाँ चक्र थप्नुहोस्'; ?></h6></div>
            <div class="card-body">
                <form method="post" class="row g-2">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save_cycle">
                    <input type="hidden" name="cycle_id" value="<?php echo $editRow ? (int)$editRow['id'] : 0; ?>">
                    <div class="col-md-6">
                        <label class="form-label small">शीर्षक (नेपाली) *</label>
                        <input class="form-control" name="title_np" required value="<?php echo htmlspecialchars($editRow['title_np'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Title (English)</label>
                        <input class="form-control" name="title_en" value="<?php echo htmlspecialchars($editRow['title_en'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">अवधि लेबल</label>
                        <input class="form-control" name="period_label" placeholder="उदा. २०८१/८२" value="<?php echo htmlspecialchars($editRow['period_label'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">मिति देखि <span class="text-muted">(वि.सं.)</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control nepali-datepicker" name="date_from" id="ec_date_from" autocomplete="off" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($editRow['date_from'] ?? ''); ?>">
                            <span class="input-group-text cursor-pointer" role="button" tabindex="0" title="पात्रो"><i class="fas fa-calendar-alt"></i></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">मिति सम्म <span class="text-muted">(वि.सं.)</span></label>
                        <div class="input-group">
                            <input type="text" class="form-control nepali-datepicker" name="date_to" id="ec_date_to" autocomplete="off" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($editRow['date_to'] ?? ''); ?>">
                            <span class="input-group-text cursor-pointer" role="button" tabindex="0" title="पात्रो"><i class="fas fa-calendar-alt"></i></span>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">परिचय (नेपाली)</label>
                        <textarea class="form-control" name="intro_np" rows="3"><?php echo htmlspecialchars($editRow['intro_np'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Intro (English)</label>
                        <textarea class="form-control" name="intro_en" rows="2"><?php echo htmlspecialchars($editRow['intro_en'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small">क्रम (sort)</label>
                        <input type="number" class="form-control" name="sort_order" value="<?php echo (int)($editRow['sort_order'] ?? 0); ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_published" id="pubc" value="1" <?php echo !empty($editRow['is_published']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="pubc">प्रकाशित (सार्वजनिक)</label>
                        </div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="show_in_navbar" id="navc" value="1" <?php echo !empty($editRow['show_in_navbar']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="navc">सूचना मेनुमा देखाउनुहोस्</label>
                        </div>
                    </div>
                    <div class="col-12"><hr class="my-2"><h6 class="small text-muted mb-2"><i class="fas fa-clock me-1"></i>मतदान समय (नेपाल समय) — सञ्चालक/लेखा समिति निर्वाचन</h6></div>
                    <div class="col-md-5">
                        <label class="form-label small">मतदान सुरु (NPT)</label>
                        <div class="input-group mb-1">
                            <input type="text" class="form-control nepali-datepicker" name="vote_start_date" autocomplete="off" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($voteStartDateVal); ?>">
                            <span class="input-group-text cursor-pointer" role="button" tabindex="0" title="पात्रो"><i class="fas fa-calendar-alt"></i></span>
                        </div>
                        <select class="form-select form-select-sm" name="vote_start_time">
                            <option value="">समय छान्नुहोस्</option>
                            <?php foreach ($voteTimeOptions as $tv => $tl): ?>
                            <option value="<?php echo htmlspecialchars($tv, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $voteStartTimeVal === $tv ? 'selected' : ''; ?>><?php echo htmlspecialchars($tl, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small">मतदान समाप्ति (NPT)</label>
                        <div class="input-group mb-1">
                            <input type="text" class="form-control nepali-datepicker" name="vote_end_date" autocomplete="off" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($voteEndDateVal); ?>">
                            <span class="input-group-text cursor-pointer" role="button" tabindex="0" title="पात्रो"><i class="fas fa-calendar-alt"></i></span>
                        </div>
                        <select class="form-select form-select-sm" name="vote_end_time">
                            <option value="">समय छान्नुहोस्</option>
                            <?php foreach ($voteTimeOptions as $tv => $tl): ?>
                            <option value="<?php echo htmlspecialchars($tv, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $voteEndTimeVal === $tv ? 'selected' : ''; ?>><?php echo htmlspecialchars($tl, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="voting_enabled" id="vec" value="1" <?php echo !empty($editRow['voting_enabled']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="vec">मतदान सक्रिय</label>
                        </div>
                    </div>
                    <?php if ($editRow): ?>
                    <div class="col-12">
                        <a href="election-candidates.php?cycle=<?php echo (int)$editRow['id']; ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-user-tie me-1"></i>उम्मेदवार/पद व्यवस्थापन</a>
                        <a href="election-voting-attendance.php?cycle=<?php echo (int)$editRow['id']; ?>" class="btn btn-outline-warning btn-sm"><i class="fas fa-person-booth me-1"></i>Voting Attendance</a>
                        <a href="election-results.php?cycle=<?php echo (int)$editRow['id']; ?>" class="btn btn-outline-success btn-sm"><i class="fas fa-chart-bar me-1"></i>नतिजा हेर्नुहोस्</a>
                    </div>
                    <?php endif; ?>
                    <div class="col-12 d-flex flex-wrap gap-2 mt-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>बचत गर्नुहोस्</button>
                        <?php if ($editRow): ?>
                            <a href="election-information.php?milestones=<?php echo (int)$editRow['id']; ?>" class="btn btn-success"><i class="fas fa-list-ol me-1"></i>कार्यतालिका व्यवस्थापन</a>
                        <?php endif; ?>
                        <a href="election-information.php?panel=form" class="btn btn-outline-secondary">नयाँ फारम</a>
                    </div>
                </form>
                <?php if ($editRow): ?>
                    <form method="post" class="mt-2" onsubmit="return confirm('यो चक्र र सबै चरण मेटाउने?');">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="delete_cycle">
                        <input type="hidden" name="cycle_id" value="<?php echo (int)$editRow['id']; ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i>चक्र मेटाउनुहोस्</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
    </div>
</div>

<?php if ($milestonesFor > 0): ?>
    <?php
        $cycleLabel = '';
        foreach ($cycles as $cx) {
            if ((int)$cx['id'] === $milestonesFor) {
                $cycleLabel = $cx['title_np'] ?? ('#' . $milestonesFor);
                break;
            }
        }
    ?>
    <div class="card admin-table-card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0"><i class="fas fa-list-ol me-2"></i>कार्यतालिका — <?php echo htmlspecialchars($cycleLabel); ?></h6>
            <a href="election-information.php?edit=<?php echo $milestonesFor; ?>" class="btn btn-sm btn-outline-primary">चक्र फर्कनुहोस्</a>
        </div>
        <div class="card-body border-bottom">
            <h6 class="small text-muted">नयाँ चरण थप्नुहोस्</h6>
            <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_milestone">
                <input type="hidden" name="cycle_id" value="<?php echo $milestonesFor; ?>">
                <input type="hidden" name="milestone_id" value="0">
                <div class="col-md-3">
                    <label class="form-label small">मिति <span class="text-muted">(वि.सं.)</span></label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control nepali-datepicker" name="m_event_date" id="em_event_new" autocomplete="off" placeholder="YYYY-MM-DD">
                        <span class="input-group-text cursor-pointer" role="button" tabindex="0"><i class="fas fa-calendar-alt"></i></span>
                    </div>
                </div>
                <div class="col-md-2"><label class="form-label small">शीर्षक (NP) *</label><input class="form-control" name="m_title_np" required></div>
                <div class="col-md-2"><label class="form-label small">Title (EN)</label><input class="form-control" name="m_title_en"></div>
                <div class="col-md-2"><label class="form-label small">क्रम</label><input type="number" class="form-control" name="m_display_order" value="0"></div>
                <div class="col-md-2"><label class="form-label small d-block">सक्रिय</label><input type="checkbox" class="form-check-input" name="m_is_active" value="1" checked></div>
                <div class="col-md-6"><label class="form-label small">विवरण (NP)</label><textarea class="form-control" name="m_detail_np" rows="2"></textarea></div>
                <div class="col-md-6"><label class="form-label small">Detail (EN)</label><textarea class="form-control" name="m_detail_en" rows="2"></textarea></div>
                <div class="col-md-8"><label class="form-label small">PDF / फाइल</label><input type="file" class="form-control" name="m_attachment" accept=".pdf,.doc,.docx,image/*"></div>
                <div class="col-md-4"><button type="submit" class="btn btn-primary w-100 mt-4"><i class="fas fa-plus me-1"></i>थप्नुहोस्</button></div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>क्रम</th><th>मिति</th><th>शीर्षक</th><th>फाइल</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($milestoneRows as $mr): ?>
                    <tr>
                        <td><?php echo (int)$mr['display_order']; ?></td>
                        <td><?php
                            $__ed = (string)($mr['event_date'] ?? '');
                            echo $__ed !== '' ? htmlspecialchars(toNepaliNumeral($__ed)) : '—';
                        ?></td>
                        <td><?php echo htmlspecialchars(mb_substr($mr['title_np'] ?? '', 0, 50)); ?></td>
                        <td><?php
                        if (!empty($mr['attachment'])) {
                            $__p = (string)$mr['attachment'];
                            $__u = (strpos($__p, 'http://') === 0 || strpos($__p, 'https://') === 0) ? $__p : (SITE_URL . ltrim($__p, '/'));
                            echo '<a target="_blank" rel="noopener" href="' . htmlspecialchars($__u, ENT_QUOTES, 'UTF-8') . '">फाइल</a>';
                        } else {
                            echo '—';
                        }
                        ?></td>
                        <td class="text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#em-<?php echo (int)$mr['id']; ?>">सम्पादन</button>
                            <form method="post" class="d-inline" onsubmit="return confirm('मेटाउने?');"><?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_milestone">
                                <input type="hidden" name="cycle_id" value="<?php echo $milestonesFor; ?>">
                                <input type="hidden" name="milestone_id" value="<?php echo (int)$mr['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">मेटाउनुहोस्</button>
                            </form>
                        </td>
                    </tr>
                    <tr class="collapse" id="em-<?php echo (int)$mr['id']; ?>">
                        <td colspan="5" class="bg-light">
                            <form method="post" enctype="multipart/form-data" class="row g-2 p-2">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="save_milestone">
                                <input type="hidden" name="cycle_id" value="<?php echo $milestonesFor; ?>">
                                <input type="hidden" name="milestone_id" value="<?php echo (int)$mr['id']; ?>">
                                <div class="col-md-3">
                                    <label class="form-label small">मिति <span class="text-muted">(वि.सं.)</span></label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control nepali-datepicker" name="m_event_date" id="em_ev_<?php echo (int)$mr['id']; ?>" autocomplete="off" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($mr['event_date'] ?? ''); ?>">
                                        <span class="input-group-text cursor-pointer" role="button" tabindex="0"><i class="fas fa-calendar-alt"></i></span>
                                    </div>
                                </div>
                                <div class="col-md-3"><label class="form-label small">शीर्षक NP</label><input class="form-control" name="m_title_np" required value="<?php echo htmlspecialchars($mr['title_np'] ?? ''); ?>"></div>
                                <div class="col-md-3"><label class="form-label small">Title EN</label><input class="form-control" name="m_title_en" value="<?php echo htmlspecialchars($mr['title_en'] ?? ''); ?>"></div>
                                <div class="col-md-3"><label class="form-label small">क्रम</label><input type="number" class="form-control" name="m_display_order" value="<?php echo (int)$mr['display_order']; ?>"></div>
                                <div class="col-12"><label class="form-label small">विवरण NP</label><textarea class="form-control" name="m_detail_np" rows="2"><?php echo htmlspecialchars($mr['detail_np'] ?? ''); ?></textarea></div>
                                <div class="col-12"><label class="form-label small">Detail EN</label><textarea class="form-control" name="m_detail_en" rows="2"><?php echo htmlspecialchars($mr['detail_en'] ?? ''); ?></textarea></div>
                                <div class="col-md-6"><label class="form-label small">नयाँ फाइल (छोड्नुभयो भने पुरानो जोगिन्छ)</label><input type="file" class="form-control" name="m_attachment" accept=".pdf,.doc,.docx,image/*"></div>
                                <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="m_is_active" value="1" id="ac<?php echo (int)$mr['id']; ?>" <?php echo !empty($mr['is_active']) ? 'checked' : ''; ?>><label class="form-check-label" for="ac<?php echo (int)$mr['id']; ?>">सक्रिय</label></div></div>
                                <div class="col-md-3 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100">अपडेट</button></div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($milestoneRows)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">अझै कुनै चरण छैन।</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

</div>
<script>
/* कार्यतालिका सम्पादन collapse भित्रका नेपाली पात्रो — खुलेपछि init */
document.addEventListener('DOMContentLoaded', function() {
    document.addEventListener('shown.bs.collapse', function(ev) {
        if (typeof initNepaliDatepickers === 'function' && ev.target) {
            initNepaliDatepickers(ev.target);
        }
    });
});
</script>
<?php require_once 'includes/admin-footer.php'; ?>
