<?php
$pageTitle = 'कार्यक्रम विवरण';
$currentPage = 'program-detail';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/program-tables.php';
require_once __DIR__ . '/../includes/program-attendance-helpers.php';

$db = getDB();
ensureProgramTables($db);
$id = (int)($_GET['id'] ?? 0);
$prog = $id > 0 ? programFetchById($db, $id) : null;
if (!$prog) {
    setFlash('error', 'कार्यक्रम फेला परेन।');
    redirect('programs.php');
}

$stats = programLiveStatsForProgram($db, $prog);
$scope = $stats['scope'];
$attended = $stats['attended'];
$eligible = $stats['eligible'];
$pct = $stats['pct'];
$prereg = $stats['prereg'];
$occRows = $stats['occurrences'];
$occCount = count($occRows);

$pending = 0;
try {
    $st = $db->prepare("SELECT COUNT(*) FROM member_program_attendance_requests WHERE program_id=? AND status='pending'");
    $st->execute([$id]);
    $pending = (int)$st->fetchColumn();
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';
    if ($action === 'void_attendance') {
        $attId = (int)($_POST['attendance_id'] ?? 0);
        $reason = trim((string)($_POST['void_reason'] ?? ''));
        $res = voidProgramAttendance($db, $attId, (int)($_SESSION['admin_id'] ?? 0), $reason);
        setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'उपस्थिति void भयो।' : ($res['error_np'] ?? 'Error'));
        redirect('program-detail.php?id=' . $id);
    }
}

$recentAtt = $db->prepare("SELECT a.*, m.name FROM member_program_attendance a LEFT JOIN members m ON m.id=a.member_id WHERE a.attendance_scope_key=? AND a.attendance_status='VALID' ORDER BY a.attended_at DESC LIMIT 15");
$recentAtt->execute([$scope]);
$recentRows = $recentAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader(htmlspecialchars($prog['title']), 'fa-eye', programTypeLabel($prog['program_type'] ?? 'General'),
      '<div class="d-flex gap-2 flex-wrap">'
      . '<a href="programs.php?edit='.$id.'" class="btn btn-sm btn-outline-primary">Edit</a>'
      . '<a href="program-registration-desk.php?program_id='.$id.'" class="btn btn-sm btn-success">Desk</a>'
      . '<a href="program-reports-consolidated.php?program_id='.$id.'" class="btn btn-sm btn-outline-secondary">Report</a>'
      . '<a href="program-dashboard.php?program_id='.$id.'" class="btn btn-sm btn-outline-info">Live</a>'
      . '</div>'); ?>

  <div class="row g-3 mb-3">
    <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Occurrences</div><strong class="fs-4"><?php echo $occCount ?: '—'; ?></strong></div></div>
    <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Unique Attended</div><strong class="fs-4 text-success"><?php echo $attended; ?></strong></div></div>
    <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Pre-reg</div><strong class="fs-4"><?php echo $prereg; ?></strong></div></div>
    <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Pending Req</div><strong class="fs-4 text-warning"><?php echo $pending; ?></strong></div></div>
    <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Eligible</div><strong class="fs-4"><?php echo $eligible; ?></strong></div></div>
    <div class="col-md-2"><div class="card p-3 text-center"><div class="small text-muted">Rate</div><strong class="fs-4 text-primary"><?php echo $pct; ?>%</strong></div></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card admin-table-card mb-3"><div class="card-header"><h6 class="mb-0">Overview</h6></div><div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-4">मिति</dt><dd class="col-8"><?php echo htmlspecialchars($prog['event_date'] ?: '—'); ?> <?php echo htmlspecialchars($prog['event_time'] ?? ''); ?></dd>
          <dt class="col-4">स्थान</dt><dd class="col-8"><?php echo htmlspecialchars($prog['location'] ?: '—'); ?></dd>
          <dt class="col-4">Multi-location</dt><dd class="col-8"><?php echo (int)($prog['is_multi_location']??0)===1?'Yes':'No'; ?></dd>
          <dt class="col-4">Instant QR</dt><dd class="col-8"><?php echo !empty($prog['instant_attendance'])?'Yes':'No (approve flow)'; ?></dd>
        </dl>
        <?php if ((int)($prog['is_multi_location']??0)===1): ?><a href="program-occurrences.php?parent_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-info mt-2">Manage Occurrences</a><?php endif; ?>
      </div></div>
      <?php if (!empty($occRows)): ?>
      <div class="card admin-table-card"><div class="card-header"><h6 class="mb-0">Occurrence-wise</h6></div><table class="table table-sm mb-0"><thead><tr><th>Location</th><th>Date</th><th>Attended</th></tr></thead><tbody>
        <?php foreach ($occRows as $o): ?><tr><td><?php echo htmlspecialchars($o['location_name']??''); ?></td><td><?php echo htmlspecialchars($o['event_date']??'—'); ?></td><td><?php echo (int)($o['attended_count']??0); ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </div>
    <div class="col-lg-6">
      <div class="card admin-table-card"><div class="card-header"><h6 class="mb-0">Recent Attendance</h6></div><div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Member</th><th>Location</th><th>Method</th><th>Time</th><th></th></tr></thead>
        <tbody><?php foreach ($recentRows as $ra): ?><tr>
          <td><?php echo htmlspecialchars($ra['name']??$ra['member_card_no']??''); ?></td>
          <td><?php echo htmlspecialchars($ra['location_label']??'—'); ?></td>
          <td><?php echo htmlspecialchars(programAttendanceMethodLabel($ra['attendance_method']??'')); ?></td>
          <td class="small"><?php echo htmlspecialchars(substr((string)($ra['attended_at']??''),0,16)); ?></td>
          <td><form method="POST" class="d-inline" onsubmit="return confirm('Void?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="void_attendance"><input type="hidden" name="attendance_id" value="<?php echo (int)$ra['id']; ?>"><input type="hidden" name="void_reason" value="Admin void from program detail"><button type="submit" class="btn btn-sm btn-outline-danger py-0">Void</button></form></td>
        </tr><?php endforeach; ?></tbody>
      </table></div></div>
    </div>
  </div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
