<?php
$pageTitle = 'Consolidated Report';
$currentPage = 'program-reports-consolidated';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once 'includes/program-reports-common.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$programs = programReportsProgramList($db);
$prog = programReportsSelectedProgram($db, $programId);

$rows = [];
$stats = ['attended' => 0, 'eligible' => 0, 'prereg' => 0, 'pct' => 0];
if ($prog) {
    $scope = programResolveScopeId($prog);
    $stats['attended'] = programCountUniqueAttended($db, $scope);
    $stats['eligible'] = programCountActiveMembers($db);
    $stats['pct'] = $stats['eligible'] > 0 ? round(($stats['attended'] / $stats['eligible']) * 100, 1) : 0;
    try {
        $st = $db->prepare('SELECT COUNT(*) FROM member_program_preregistrations WHERE program_id=?');
        $st->execute([$programId]);
        $stats['prereg'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
    $st = $db->prepare("SELECT a.*, m.name AS member_name, m.phone
                        FROM member_program_attendance a
                        LEFT JOIN members m ON m.id=a.member_id
                        WHERE a.attendance_scope_key=? AND a.attendance_status='VALID'
                        ORDER BY a.attended_at DESC");
    $st->execute([$scope]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($export && $prog) {
    programReportsCsvHeaders('program-consolidated-' . $programId . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Member ID', 'Name', 'Program', 'Location', 'Method', 'Attended At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['member_card_no'] ?? '',
            $r['member_name'] ?? '',
            $r['program_title'] ?? '',
            $r['location_label'] ?? '',
            $r['attendance_method'] ?? '',
            $r['attended_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Consolidated Program Report', 'fa-chart-bar', 'Multi-location AGM मा unique members एक पटक मात्र गणना।', '<a href="program-dashboard.php" class="btn btn-sm btn-outline-secondary">Dashboard</a>'); ?>
  <div class="card admin-table-card mb-3"><div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-8"><label class="form-label">कार्यक्रम</label>
        <select name="program_id" class="form-select" required onchange="this.form.submit()">
          <option value="">— छान्नुहोस् —</option>
          <?php foreach ($programs as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $programId===(int)$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['title']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if ($prog): ?><div class="col-md-4"><a href="?program_id=<?php echo $programId; ?>&export=csv" class="btn btn-success"><i class="fas fa-download me-1"></i>CSV Export</a></div><?php endif; ?>
    </form>
  </div></div>
  <?php if ($prog): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Unique Attended</div><div class="fs-3 fw-bold text-success"><?php echo $stats['attended']; ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Pre-reg</div><div class="fs-3 fw-bold"><?php echo $stats['prereg']; ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Eligible Members</div><div class="fs-3 fw-bold"><?php echo $stats['eligible']; ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Attendance %</div><div class="fs-3 fw-bold text-primary"><?php echo $stats['pct']; ?>%</div></div></div>
  </div>
  <div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Member ID</th><th>Name</th><th>Location</th><th>Method</th><th>Time</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr>
      <td><?php echo htmlspecialchars($r['member_card_no']??''); ?></td>
      <td><?php echo htmlspecialchars($r['member_name']??''); ?></td>
      <td><?php echo htmlspecialchars($r['location_label']??'—'); ?></td>
      <td><?php echo htmlspecialchars(programAttendanceMethodLabel($r['attendance_method']??'')); ?></td>
      <td><?php echo htmlspecialchars(substr((string)($r['attended_at']??''),0,16)); ?></td>
    </tr><?php endforeach; if(empty($rows)): ?><tr><td colspan="5" class="text-muted text-center py-3">No attendance yet.</td></tr><?php endif; ?></tbody>
  </table></div></div>
  <?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
