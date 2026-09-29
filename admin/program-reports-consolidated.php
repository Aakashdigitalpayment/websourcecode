<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/includes/program-reports-common.php';
require_once __DIR__ . '/../includes/program-member-insights.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$prog = programReportsSelectedProgram($db, $programId);

if ($prog && ($_GET['export'] ?? '') === 'csv') {
    $live = programLiveStatsForProgram($db, $prog);
    $fathers = programFatherNamesForScope($db, $live['scope']);
    programReportsCsvStream('program-consolidated-' . $programId . '.csv',
        ['Member ID', 'Name', 'Father', 'Program', 'Location', 'Method', 'Attended At'],
        programFetchValidAttendanceByScope($db, $live['scope'], 0),
        static fn(array $r): array => [
            $r['member_card_no'] ?? '', $r['member_name'] ?? '', $fathers[(int)($r['member_id'] ?? 0)] ?? '', $r['program_title'] ?? '',
            programAttendanceDisplayLocation($r), programReportsCsvMethodLabel($r['attendance_method'] ?? ''), $r['attended_at'] ?? '',
        ]);
}

$pageTitle = 'Consolidated Report';
$currentPage = 'program-reports-consolidated';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$programs = programReportsProgramList($db);
$rows = [];
$stats = ['attended' => 0, 'eligible' => 0, 'prereg' => 0, 'pct' => 0];
if ($prog) {
    $stats = programLiveStatsForProgram($db, $prog);
    $rows = programFetchValidAttendanceByScope($db, $stats['scope'], PROGRAM_REPORT_DISPLAY_LIMIT);
    $fathers = programFatherNamesForScope($db, $stats['scope']);
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Consolidated Program Report', 'fa-chart-bar', 'Multi-location AGM मा unique members एक पटक मात्र गणना।'); ?>
  <?php echo programReportsTabs('program-reports-consolidated', $programId); ?>
  <?php echo programReportsFilterCard($programs, $programId); ?>
  <?php if ($prog): ?>
  <div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Unique Attended</div><div class="fs-3 fw-bold text-success"><?php echo (int)$stats['attended']; ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Pre-reg</div><div class="fs-3 fw-bold"><?php echo (int)$stats['prereg']; ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Eligible Members</div><div class="fs-3 fw-bold"><?php echo (int)$stats['eligible']; ?></div></div></div>
    <div class="col-md-3"><div class="card p-3"><div class="small text-muted">Attendance %</div><div class="fs-3 fw-bold text-primary"><?php echo htmlspecialchars((string)$stats['pct']); ?>%</div></div></div>
  </div>
  <?php echo programReportsLimitNote(count($rows), (int)$stats['attended']); ?>
  <div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Member ID</th><th>Name</th><th>बुबाको नाम</th><th>Location</th><th>Method</th><th>Time</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr>
      <td><?php echo htmlspecialchars($r['member_card_no'] ?? ''); ?></td>
      <td><a href="program-member-history.php?member_id=<?php echo rawurlencode((string)($r['member_card_no'] ?? '')); ?>" class="text-decoration-none"><?php echo htmlspecialchars($r['member_name'] ?? ''); ?></a></td>
      <td class="small"><?php echo htmlspecialchars($fathers[(int)($r['member_id'] ?? 0)] ?? ''); ?></td>
      <td><?php echo htmlspecialchars(programAttendanceDisplayLocation($r)); ?></td>
      <td><?php echo htmlspecialchars(programAttendanceMethodLabel($r['attendance_method'] ?? '')); ?></td>
      <td><?php echo htmlspecialchars(programReportsFormatAttendedAt($r['attended_at'] ?? '')); ?></td>
    </tr><?php endforeach; if (empty($rows)): ?><tr><td colspan="6" class="text-muted text-center py-3">अहिलेसम्म उपस्थिति छैन।</td></tr><?php endif; ?></tbody>
  </table></div></div>
  <?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
