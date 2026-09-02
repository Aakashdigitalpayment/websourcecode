<?php
$pageTitle = 'Duplicate Attempts';
$currentPage = 'program-reports-duplicates';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once 'includes/program-reports-common.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$programs = programReportsProgramList($db);

$where = '1=1';
$params = [];
if ($programId > 0) { $where .= ' AND pa.parent_program_id=?'; $params[] = $programId; }

$st = $db->prepare("SELECT pa.*, m.name AS member_name, m.sadasyata_number, p.title AS program_title,
                           prev.location_label AS prev_location, prev.attended_at AS prev_attended_at
                    FROM program_attendance_attempts pa
                    LEFT JOIN members m ON m.id=pa.member_id
                    LEFT JOIN upcoming_programs p ON p.id=pa.parent_program_id
                    LEFT JOIN member_program_attendance prev ON prev.id=pa.previous_attendance_id
                    WHERE $where
                    ORDER BY pa.attempted_at DESC LIMIT 500");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Duplicate / Blocked Attempts Log', 'fa-shield-alt', 'Duplicate attendance, window closed, वा ineligible प्रयासहरूको audit trail।'); ?>
  <div class="card admin-table-card mb-3"><div class="card-body">
    <form method="GET"><select name="program_id" class="form-select" onchange="this.form.submit()"><option value="">— सबै कार्यक्रम —</option><?php foreach ($programs as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $programId===(int)$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['title']); ?></option><?php endforeach; ?></select></form>
  </div></div>
  <div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Time</th><th>Member</th><th>Program</th><th>Method</th><th>Result</th><th>Previous</th><th>Message</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr>
      <td class="small"><?php echo htmlspecialchars(programReportsFormatAttendedAt($r['attempted_at']??'')); ?></td>
      <td><?php echo htmlspecialchars(($r['sadasyata_number']??'').' '.($r['member_name']??'')); ?></td>
      <td><?php echo htmlspecialchars($r['program_title']??''); ?></td>
      <td><?php echo htmlspecialchars(programAttendanceMethodLabel($r['attempted_method']??'')); ?></td>
      <td><span class="badge bg-danger"><?php echo htmlspecialchars($r['result']??''); ?></span></td>
      <td class="small"><?php echo htmlspecialchars(($r['prev_location']??'').' '.programReportsFormatAttendedAt($r['prev_attended_at']??'')); ?></td>
      <td class="small"><?php echo htmlspecialchars($r['result_message']??''); ?></td>
    </tr><?php endforeach; if(empty($rows)): ?><tr><td colspan="7" class="text-muted text-center py-3">No attempts logged.</td></tr><?php endif; ?></tbody>
  </table></div></div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
