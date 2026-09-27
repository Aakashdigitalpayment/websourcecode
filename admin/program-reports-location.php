<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/includes/program-reports-common.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$prog = programReportsSelectedProgram($db, $programId);
$isMulti = $prog && (int)($prog['is_multi_location'] ?? 0) === 1;

if ($prog && ($_GET['export'] ?? '') === 'csv') {
    $rows = [];
    if ($isMulti) {
        foreach (programOccurrenceCounts($db, $programId) as $oc) {
            foreach (programFetchValidAttendanceByOccurrence($db, (int)$oc['id'], 0) as $m) {
                $m['_loc'] = $oc['location_name'] ?? '';
                $rows[] = $m;
            }
        }
    } else {
        foreach (programFetchValidAttendanceByProgram($db, $programId, 0) as $m) {
            $m['_loc'] = ($m['location_label'] ?? '') !== '' ? $m['location_label'] : ($prog['location'] ?? '');
            $rows[] = $m;
        }
    }
    programReportsCsvStream('program-location-' . $programId . '.csv',
        ['Location', 'Member ID', 'Name', 'Method', 'Attended At'], $rows,
        static fn(array $m): array => [
            $m['_loc'], $m['member_card_no'] ?? '', $m['member_name'] ?? '',
            programReportsCsvMethodLabel($m['attendance_method'] ?? ''), $m['attended_at'] ?? '',
        ]);
}

$pageTitle = 'Location-wise Report';
$currentPage = 'program-reports-location';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$programs = programReportsProgramList($db);
$occRows = [];
$singleRows = [];
$singleTotal = 0;
if ($isMulti) {
    $occRows = programOccurrenceCounts($db, $programId);
    foreach ($occRows as &$oc) {
        $oc['members'] = programFetchValidAttendanceByOccurrence($db, (int)$oc['id'], PROGRAM_REPORT_DISPLAY_LIMIT);
    }
    unset($oc);
} elseif ($prog) {
    $singleRows = programFetchValidAttendanceByProgram($db, $programId, PROGRAM_REPORT_DISPLAY_LIMIT);
    $singleTotal = (int)programLiveStatsForProgram($db, $prog)['attended'];
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('Location / Occurrence-wise Report', 'fa-map', 'कुन स्थानमा कति सदस्य उपस्थित — विवरण।'); ?>
  <?php echo programReportsTabs('program-reports-location', $programId); ?>
  <?php echo programReportsFilterCard($programs, $programId); ?>
  <?php if ($isMulti): ?>
    <?php if (empty($occRows)): ?><div class="alert alert-warning">यो कार्यक्रममा स्थान थपिएको छैन।</div><?php endif; ?>
    <?php foreach ($occRows as $oc): ?>
    <div class="card admin-table-card mb-3"><div class="card-header d-flex justify-content-between"><strong><?php echo htmlspecialchars($oc['location_name'] ?? ''); ?></strong><span class="badge bg-success"><?php echo (int)($oc['attended_count'] ?? 0); ?> attended</span></div>
    <?php echo programReportsLimitNote(count($oc['members']), (int)($oc['attended_count'] ?? 0)); ?>
    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Member ID</th><th>Name</th><th>Method</th><th>Time</th></tr></thead><tbody>
      <?php foreach ($oc['members'] as $m): ?><tr><td><?php echo htmlspecialchars($m['member_card_no'] ?? ''); ?></td><td><?php echo htmlspecialchars($m['member_name'] ?? ''); ?></td><td><?php echo htmlspecialchars(programAttendanceMethodLabel($m['attendance_method'] ?? '')); ?></td><td><?php echo htmlspecialchars(programReportsFormatAttendedAt($m['attended_at'] ?? '')); ?></td></tr><?php endforeach; ?>
      <?php if (empty($oc['members'])): ?><tr><td colspan="4" class="text-muted text-center py-2">उपस्थिति छैन।</td></tr><?php endif; ?>
    </tbody></table></div></div>
    <?php endforeach; ?>
  <?php elseif ($prog): ?>
    <?php echo programReportsLimitNote(count($singleRows), $singleTotal); ?>
    <div class="card admin-table-card"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Member ID</th><th>Name</th><th>Location</th><th>Method</th><th>Time</th></tr></thead><tbody>
      <?php foreach ($singleRows as $m): ?><tr><td><?php echo htmlspecialchars($m['member_card_no'] ?? ''); ?></td><td><?php echo htmlspecialchars($m['member_name'] ?? ''); ?></td><td><?php echo htmlspecialchars(($m['location_label'] ?? '') !== '' ? $m['location_label'] : ($prog['location'] ?? '')); ?></td><td><?php echo htmlspecialchars(programAttendanceMethodLabel($m['attendance_method'] ?? '')); ?></td><td><?php echo htmlspecialchars(programReportsFormatAttendedAt($m['attended_at'] ?? '')); ?></td></tr><?php endforeach; ?>
      <?php if (empty($singleRows)): ?><tr><td colspan="5" class="text-muted text-center py-3">अहिलेसम्म उपस्थिति छैन।</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
