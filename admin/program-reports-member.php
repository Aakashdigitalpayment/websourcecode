<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/includes/program-reports-common.php';

$db = programReportsInit();
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);

$where = "a.attendance_status='VALID'";
$params = [];
if ($q !== '') {
    $where .= ' AND (a.member_card_no LIKE ? OR m.name LIKE ? OR a.program_title LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}
$from = "FROM member_program_attendance a LEFT JOIN members m ON m.id=a.member_id WHERE $where";

if (($_GET['export'] ?? '') === 'csv') {
    $st = $db->prepare("SELECT a.*, m.name AS member_name $from ORDER BY a.attended_at DESC");
    $st->execute($params);
    programReportsCsvStream('program-member-attendance.csv',
        ['Member ID', 'Name', 'Program', 'Location', 'Method', 'Attended At'], $st,
        static fn(array $r): array => [
            $r['member_card_no'] ?? '', $r['member_name'] ?? '', $r['program_title'] ?? '', $r['location_label'] ?? '',
            programReportsCsvMethodLabel($r['attendance_method'] ?? ''), $r['attended_at'] ?? '',
        ]);
}

$pageTitle = 'Member-wise Report';
$currentPage = 'program-reports-member';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$cst = $db->prepare("SELECT COUNT(*) $from");
$cst->execute($params);
$total = (int)$cst->fetchColumn();
$st = $db->prepare("SELECT a.*, m.name AS member_name $from ORDER BY a.attended_at DESC LIMIT " . PROGRAM_REPORT_DISPLAY_LIMIT);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Member-wise Attendance Register', 'fa-users', 'सबै कार्यक्रमको सदस्य उपस्थिति खोज।'); ?>
  <?php echo programReportsTabs('program-reports-member', 0); ?>
  <div class="card admin-table-card mb-3"><div class="card-body">
    <form method="GET" class="row g-2">
      <div class="col-md-8"><input name="q" class="form-control" aria-label="खोज" placeholder="Member ID / Name / Program" value="<?php echo htmlspecialchars($q); ?>"></div>
      <div class="col-md-4 d-flex gap-2"><button type="submit" class="btn btn-primary">Search</button><a class="btn btn-success" href="?export=csv<?php echo $q !== '' ? '&amp;q=' . urlencode($q) : ''; ?>">CSV (सबै)</a></div>
    </form>
  </div></div>
  <?php echo programReportsLimitNote(count($rows), $total); ?>
  <div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm table-hover mb-0">
    <thead><tr><th>Member ID</th><th>Name</th><th>Program</th><th>Location</th><th>Method</th><th>Time</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr>
      <td><?php echo htmlspecialchars($r['member_card_no'] ?? ''); ?></td><td><?php echo htmlspecialchars($r['member_name'] ?? ''); ?></td>
      <td><?php echo htmlspecialchars($r['program_title'] ?? ''); ?></td><td><?php echo htmlspecialchars(($r['location_label'] ?? '') !== '' ? $r['location_label'] : '—'); ?></td>
      <td><?php echo htmlspecialchars(programAttendanceMethodLabel($r['attendance_method'] ?? '')); ?></td><td><?php echo htmlspecialchars(programReportsFormatAttendedAt($r['attended_at'] ?? '')); ?></td>
    </tr><?php endforeach; if (empty($rows)): ?><tr><td colspan="6" class="text-muted text-center py-3">कुनै रेकर्ड भेटिएन।</td></tr><?php endif; ?></tbody>
  </table></div></div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
