<?php
$pageTitle = 'Member-wise Report';
$currentPage = 'program-reports-member';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once 'includes/program-reports-common.php';

$db = programReportsInit();
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$where = "a.attendance_status='VALID'";
$params = [];
if ($q !== '') {
    $where .= ' AND (a.member_card_no LIKE ? OR m.name LIKE ? OR a.program_title LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
$sql = "SELECT a.*, m.name AS member_name, m.phone FROM member_program_attendance a LEFT JOIN members m ON m.id=a.member_id WHERE $where ORDER BY a.attended_at DESC LIMIT 500";
$st = $db->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($export) {
    programReportsCsvHeaders('program-member-attendance.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Member ID', 'Name', 'Program', 'Location', 'Method', 'Attended At']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['member_card_no']??'', $r['member_name']??'', $r['program_title']??'', $r['location_label']??'', programReportsCsvMethodLabel($r['attendance_method']??''), $r['attended_at']??'']);
    }
    fclose($out);
    exit;
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Member-wise Attendance Register', 'fa-users', 'सबै कार्यक्रमको सदस्य उपस्थिति खोज।'); ?>
  <div class="card admin-table-card mb-3"><div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-8"><input name="q" class="form-control" placeholder="Member ID / Name / Program" value="<?php echo htmlspecialchars($q); ?>"></div>
      <div class="col-md-4 d-flex gap-2"><button type="submit" class="btn btn-primary">Search</button><a class="btn btn-success" href="?export=csv<?php echo $q!==''?'&q='.urlencode($q):''; ?>">CSV</a></div>
    </form>
  </div></div>
  <div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Member ID</th><th>Name</th><th>Program</th><th>Location</th><th>Method</th><th>Time</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr>
      <td><?php echo htmlspecialchars($r['member_card_no']??''); ?></td><td><?php echo htmlspecialchars($r['member_name']??''); ?></td>
      <td><?php echo htmlspecialchars($r['program_title']??''); ?></td><td><?php echo htmlspecialchars($r['location_label']??'—'); ?></td>
      <td><?php echo htmlspecialchars(programAttendanceMethodLabel($r['attendance_method']??'')); ?></td><td><?php echo htmlspecialchars(programReportsFormatAttendedAt($r['attended_at']??'')); ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div></div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
