<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/includes/program-reports-common.php';
$pageTitle = 'Duplicate Attempts';
$currentPage = 'program-reports-duplicates';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$programs = programReportsProgramList($db);

$where = '1=1';
$params = [];
if ($programId > 0) {
    $where .= ' AND pa.parent_program_id=?';
    $params[] = $programId;
}
$cst = $db->prepare("SELECT COUNT(*) FROM program_attendance_attempts pa WHERE $where");
$cst->execute($params);
$total = (int)$cst->fetchColumn();

$st = $db->prepare("SELECT pa.*, m.name AS member_name, m.sadasyata_number, p.title AS program_title,
                           prev.location_label AS prev_location, prev.attended_at AS prev_attended_at
                    FROM program_attendance_attempts pa
                    LEFT JOIN members m ON m.id=pa.member_id
                    LEFT JOIN upcoming_programs p ON p.id=pa.parent_program_id
                    LEFT JOIN member_program_attendance prev ON prev.id=pa.previous_attendance_id
                    WHERE $where
                    ORDER BY pa.attempted_at DESC LIMIT " . PROGRAM_REPORT_DISPLAY_LIMIT);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Duplicate / Blocked Attempts Log', 'fa-shield-alt', 'Duplicate attendance, window closed, वा ineligible प्रयासहरूको audit trail।'); ?>
  <?php echo programReportsTabs('program-reports-duplicates', $programId); ?>
  <?php echo programReportsFilterCard($programs, $programId, '— सबै कार्यक्रम —', false); ?>
  <?php echo programReportsLimitNote(count($rows), $total); ?>
  <div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Time</th><th>Member</th><th>Program</th><th>Method</th><th>Result</th><th>Previous</th><th>Message</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr>
      <td class="small"><?php echo htmlspecialchars(programReportsFormatAttendedAt($r['attempted_at'] ?? '')); ?></td>
      <td><?php echo htmlspecialchars(trim(($r['sadasyata_number'] ?? '') . ' ' . ($r['member_name'] ?? ''))); ?></td>
      <td><?php echo htmlspecialchars($r['program_title'] ?? ''); ?></td>
      <td><?php echo htmlspecialchars(programAttendanceMethodLabel($r['attempted_method'] ?? '')); ?></td>
      <td><span class="badge bg-danger"><?php echo htmlspecialchars($r['result'] ?? ''); ?></span></td>
      <td class="small"><?php echo htmlspecialchars(trim(($r['prev_location'] ?? '') . ' ' . programReportsFormatAttendedAt($r['prev_attended_at'] ?? ''))); ?></td>
      <td class="small"><?php echo htmlspecialchars($r['result_message'] ?? ''); ?></td>
    </tr><?php endforeach; if (empty($rows)): ?><tr><td colspan="7" class="text-muted text-center py-3">No attempts logged.</td></tr><?php endif; ?></tbody>
  </table></div></div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
