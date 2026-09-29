<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/includes/program-reports-common.php';
require_once __DIR__ . '/../includes/program-member-insights.php';

$db = programReportsInit();
$programId = (int)($_GET['program_id'] ?? 0);
$prog = programReportsSelectedProgram($db, $programId);
$sections = [
    'gender'   => ['लिङ्गअनुसार', 'users'],
    'desk'     => ['Desk अनुसार', 'monitor'],
    'staff'    => ['Staff / user अनुसार', 'user-check'],
    'method'   => ['विधिअनुसार', 'tag'],
    'location' => ['स्थानअनुसार', 'map-pin'],
];
$data = $prog ? programAttendanceBreakdown($db, programResolveScopeId($prog)) : [];

if ($prog && ($_GET['export'] ?? '') === 'csv') {
    $rows = [];
    foreach ($sections as $key => [$label]) {
        foreach ($data[$key] ?? [] as $r) {
            $rows[] = [$label, $r['label'], $r['count']];
        }
    }
    programReportsCsvStream('program-breakdown-' . $programId . '.csv', ['Group', 'Value', 'Attendance'], $rows,
        static fn(array $r): array => $r);
}

$pageTitle = 'उपस्थिति विश्लेषण';
$currentPage = 'program-reports-breakdown';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$programs = programReportsProgramList($db);
$total = 0;
foreach ($data['method'] ?? [] as $r) {
    $total += $r['count'];
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('उपस्थिति विश्लेषण', 'fa-chart-pie', 'लिङ्ग, desk, staff user, विधि र स्थानअनुसार उपस्थिति संख्या।'); ?>
  <?php echo programReportsTabs('program-reports-breakdown', $programId); ?>
  <?php echo programReportsFilterCard($programs, $programId); ?>
  <?php if ($prog): ?>
  <div class="mb-3"><span class="badge bg-success fs-6">कुल उपस्थिति: <?php echo $total; ?></span></div>
  <div class="row g-3">
    <?php foreach ($sections as $key => [$label, $icon]): $rows = $data[$key] ?? []; ?>
    <div class="col-md-6 col-xl-4">
      <div class="card admin-table-card h-100">
        <div class="card-header"><h6 class="mb-0"><i class="lucide-icon me-1" data-lucide="<?php echo $icon; ?>" aria-hidden="true"></i><?php echo htmlspecialchars($label); ?></h6></div>
        <div class="card-body">
          <?php if (!$rows): ?><div class="text-muted small">डाटा छैन।</div><?php endif; ?>
          <?php foreach ($rows as $r): $pct = $total > 0 ? round($r['count'] * 100 / $total, 1) : 0; ?>
          <div class="mb-2">
            <div class="d-flex justify-content-between small"><span><?php echo htmlspecialchars($r['label']); ?></span><strong><?php echo (int)$r['count']; ?> <span class="text-muted fw-normal">(<?php echo $pct; ?>%)</span></strong></div>
            <div class="progress" style="height:6px" role="progressbar" aria-label="<?php echo htmlspecialchars($r['label']); ?>" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:<?php echo $pct; ?>%"></div></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="small text-muted mt-3">लिङ्ग: सदस्य रेकर्ड (नभए KYM) बाट। «नखुलेको» = लिङ्ग नराखिएका सदस्य। Staff: दर्ता गर्ने admin; QR / सदस्य आफैंको दर्तामा staff हुँदैन।</div>
  <?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
