<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/includes/program-reports-common.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$prog = programReportsSelectedProgram($db, $programId);

/* Eligible = same rule as programCountActiveMembers(); absent = no VALID attendance in this program's scope */
$absentFrom = "FROM members m
               WHERE m.is_active=1
                 AND LOWER(COALESCE(m.approval_status,'')) IN ('approved','active','confirmed','renewal_pending','')
                 AND NOT EXISTS (
                   SELECT 1 FROM member_program_attendance a
                   WHERE a.member_id=m.id AND a.attendance_scope_key=? AND a.attendance_status='VALID'
                 )";
$scope = $prog ? programResolveScopeId($prog) : 0;

if ($prog && ($_GET['export'] ?? '') === 'csv') {
    $st = $db->prepare("SELECT m.sadasyata_number, m.name, m.phone {$absentFrom} ORDER BY m.name ASC");
    $st->execute([$scope]);
    programReportsCsvStream('program-absent-' . $programId . '.csv', ['Member ID', 'Name', 'Phone'], $st,
        static fn(array $r): array => [$r['sadasyata_number'] ?? '', $r['name'] ?? '', $r['phone'] ?? '']);
}

$pageTitle = 'Absent Members';
$currentPage = 'program-reports-absent';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$programs = programReportsProgramList($db);
$rows = [];
$total = 0;
if ($prog) {
    $cst = $db->prepare("SELECT COUNT(*) {$absentFrom}");
    $cst->execute([$scope]);
    $total = (int)$cst->fetchColumn();
    $st = $db->prepare("SELECT m.id, m.sadasyata_number, m.name, m.phone {$absentFrom} ORDER BY m.name ASC LIMIT " . PROGRAM_REPORT_DISPLAY_LIMIT);
    $st->execute([$scope]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Absent Members Report', 'fa-user-times', 'Eligible active members जो यो कार्यक्रममा उपस्थित भएका छैनन्।'); ?>
  <?php echo programReportsTabs('program-reports-absent', $programId); ?>
  <?php echo programReportsFilterCard($programs, $programId); ?>
  <?php if ($prog): ?>
  <div class="mb-2"><span class="badge bg-warning text-dark fs-6"><?php echo $total; ?> absent</span></div>
  <?php echo programReportsLimitNote(count($rows), $total); ?>
  <div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Member ID</th><th>Name</th><th>Phone</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr>
      <td><?php echo htmlspecialchars($r['sadasyata_number'] ?? ''); ?></td>
      <td><?php echo htmlspecialchars($r['name'] ?? ''); ?></td>
      <td><?php echo htmlspecialchars($r['phone'] ?? ''); ?></td>
    </tr><?php endforeach; if (empty($rows)): ?><tr><td colspan="3" class="text-muted text-center py-3">सबै योग्य सदस्य उपस्थित।</td></tr><?php endif; ?></tbody>
  </table></div></div>
  <?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
