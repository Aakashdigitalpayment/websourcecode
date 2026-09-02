<?php
$pageTitle = 'Absent Members';
$currentPage = 'program-reports-absent';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once 'includes/program-reports-common.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$programs = programReportsProgramList($db);
$prog = programReportsSelectedProgram($db, $programId);
$rows = [];

if ($prog) {
    $scope = programResolveScopeId($prog);
    $st = $db->prepare("SELECT m.id, m.sadasyata_number, m.name, m.phone
                        FROM members m
                        WHERE m.is_active=1
                          AND LOWER(COALESCE(m.approval_status,'')) IN ('approved','active','confirmed','')
                          AND NOT EXISTS (
                            SELECT 1 FROM member_program_attendance a
                            WHERE a.member_id=m.id AND a.attendance_scope_key=? AND a.attendance_status='VALID'
                          )
                        ORDER BY m.name ASC LIMIT 2000");
    $st->execute([$scope]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($export && $prog) {
    programReportsCsvHeaders('program-absent-' . $programId . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Member ID', 'Name', 'Phone']);
    foreach ($rows as $r) { fputcsv($out, [$r['sadasyata_number']??'', $r['name']??'', $r['phone']??'']); }
    fclose($out);
    exit;
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Absent Members Report', 'fa-user-times', 'Eligible active members जो यो कार्यक्रममा उपस्थित भएका छैनन्।'); ?>
  <div class="card admin-table-card mb-3"><div class="card-body">
    <form method="GET" class="row g-2"><div class="col-md-8"><select name="program_id" class="form-select" onchange="this.form.submit()"><option value="">— कार्यक्रम —</option><?php foreach ($programs as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $programId===(int)$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['title']); ?></option><?php endforeach; ?></select></div><?php if($prog): ?><div class="col-md-4"><span class="badge bg-warning text-dark fs-6"><?php echo count($rows); ?> absent</span> <a class="btn btn-sm btn-success" href="?program_id=<?php echo $programId; ?>&export=csv">CSV</a></div><?php endif; ?></form>
  </div></div>
  <?php if ($prog): ?><div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Member ID</th><th>Name</th><th>Phone</th></tr></thead><tbody><?php foreach ($rows as $r): ?><tr><td><?php echo htmlspecialchars($r['sadasyata_number']??''); ?></td><td><?php echo htmlspecialchars($r['name']??''); ?></td><td><?php echo htmlspecialchars($r['phone']??''); ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
