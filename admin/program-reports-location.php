<?php
$pageTitle = 'Location-wise Report';
$currentPage = 'program-reports-location';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once 'includes/program-reports-common.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$programs = programReportsProgramList($db);
$prog = programReportsSelectedProgram($db, $programId);
$occRows = [];
$singleRows = [];

if ($prog) {
    if ((int)($prog['is_multi_location'] ?? 0) === 1) {
        $occRows = programOccurrenceCounts($db, $programId);
        foreach ($occRows as &$oc) {
            $oc['members'] = programFetchValidAttendanceByOccurrence($db, (int)$oc['id']);
        }
        unset($oc);
    } else {
        $singleRows = programFetchValidAttendanceByProgram($db, $programId);
    }
}

if ($export && $prog) {
    programReportsCsvHeaders('program-location-' . $programId . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Location', 'Member ID', 'Name', 'Method', 'Attended At']);
    if ((int)($prog['is_multi_location'] ?? 0) === 1) {
        foreach ($occRows as $oc) {
            foreach ($oc['members'] ?? [] as $m) {
                fputcsv($out, [$oc['location_name']??'', $m['member_card_no']??'', $m['member_name']??'', programReportsCsvMethodLabel($m['attendance_method']??''), $m['attended_at']??'']);
            }
        }
    } else {
        foreach ($singleRows as $m) {
            fputcsv($out, [$prog['location']??'', $m['member_card_no']??'', $m['member_name']??'', programReportsCsvMethodLabel($m['attendance_method']??''), $m['attended_at']??'']);
        }
    }
    fclose($out);
    exit;
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Location / Occurrence-wise Report', 'fa-map', 'कुन स्थानमा कति सदस्य उपस्थित — विवरण।'); ?>
  <div class="card admin-table-card mb-3"><div class="card-body">
    <form method="GET" class="row g-2"><div class="col-md-8"><select name="program_id" class="form-select" onchange="this.form.submit()"><option value="">— कार्यक्रम —</option><?php foreach ($programs as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $programId===(int)$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['title']); ?></option><?php endforeach; ?></select></div><?php if($prog): ?><div class="col-md-4"><a class="btn btn-success" href="?program_id=<?php echo $programId; ?>&export=csv">CSV</a></div><?php endif; ?></form>
  </div></div>
  <?php if ($prog && (int)($prog['is_multi_location']??0)===1): foreach ($occRows as $oc): ?>
    <div class="card admin-table-card mb-3"><div class="card-header d-flex justify-content-between"><strong><?php echo htmlspecialchars($oc['location_name']??''); ?></strong><span class="badge bg-success"><?php echo (int)($oc['attended_count']??0); ?> attended</span></div>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Member ID</th><th>Name</th><th>Method</th><th>Time</th></tr></thead><tbody>
      <?php foreach ($oc['members']??[] as $m): ?><tr><td><?php echo htmlspecialchars($m['member_card_no']??''); ?></td><td><?php echo htmlspecialchars($m['member_name']??''); ?></td><td><?php echo htmlspecialchars(programAttendanceMethodLabel($m['attendance_method']??'')); ?></td><td><?php echo htmlspecialchars(programReportsFormatAttendedAt($m['attended_at']??'')); ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php endforeach; elseif ($prog): ?>
    <div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Member ID</th><th>Name</th><th>Location</th><th>Method</th><th>Time</th></tr></thead><tbody>
      <?php foreach ($singleRows as $m): ?><tr><td><?php echo htmlspecialchars($m['member_card_no']??''); ?></td><td><?php echo htmlspecialchars($m['member_name']??''); ?></td><td><?php echo htmlspecialchars($m['location_label']??($prog['location']??'')); ?></td><td><?php echo htmlspecialchars(programAttendanceMethodLabel($m['attendance_method']??'')); ?></td><td><?php echo htmlspecialchars(programReportsFormatAttendedAt($m['attended_at']??'')); ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
