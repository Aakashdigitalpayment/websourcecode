<?php
$pageTitle = 'कार्यक्रम ड्यासबोर्ड';
$currentPage = 'program-dashboard';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/program-tables.php';
require_once __DIR__ . '/../includes/program-attendance-helpers.php';

$db = getDB();
ensureProgramTables($db);

$activePrograms = (int)$db->query("SELECT COUNT(*) FROM upcoming_programs WHERE is_active=1")->fetchColumn();
$pendingReq = (int)$db->query("SELECT COUNT(*) FROM member_program_attendance_requests WHERE status='pending'")->fetchColumn();
$todayAttend = (int)$db->query("SELECT COUNT(*) FROM member_program_attendance WHERE attendance_status='VALID' AND DATE(attended_at)=CURDATE()")->fetchColumn();
$duplicateAttempts = (int)$db->query("SELECT COUNT(*) FROM program_attendance_attempts WHERE result='DUPLICATE_BLOCKED'")->fetchColumn();
$eligibleMembers = programCountActiveMembers($db);

$recentAttend = [];
try {
    $recentAttend = $db->query("SELECT a.*, m.name AS member_name
                                FROM member_program_attendance a
                                LEFT JOIN members m ON m.id=a.member_id
                                WHERE a.attendance_status='VALID'
                                ORDER BY a.attended_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $recentAttend = [];
}

$activeList = $db->query("SELECT id, title, program_type, is_multi_location, event_date, location
                          FROM upcoming_programs WHERE is_active=1
                          ORDER BY COALESCE(event_date,'9999-12-31') ASC, id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedProgramId = (int)($_GET['program_id'] ?? 0);
$liveStats = null;
if ($selectedProgramId > 0) {
    $prog = programFetchById($db, $selectedProgramId);
    if ($prog) {
        $liveStats = programLiveStatsForProgram($db, $prog);
    }
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader(
      adminLangT('कार्यक्रम ड्यासबोर्ड', 'Program Dashboard'),
      'fa-chart-pie',
      adminLangT('Parent Program → Session/Location → Verification → Registration → Attendance → Reporting', 'Parent Program → Session/Location → Verification → Registration → Attendance → Reporting'),
      '<div class="d-flex gap-2 flex-wrap">'
      . '<a href="programs.php" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i>' . adminLangT('कार्यक्रम', 'Programs') . '</a>'
      . '<a href="program-registration-desk.php" class="btn btn-success btn-sm"><i class="fas fa-desktop me-1"></i>' . adminLangT('दर्ता डेस्क', 'Registration Desk') . '</a>'
      . '<a href="program-attendance.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-clipboard-check me-1"></i>' . adminLangT('रिपोर्ट', 'Reports') . '</a>'
      . '</div>'
  ); ?>

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card admin-stat-card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small"><?php echo adminLangT('सक्रिय कार्यक्रम', 'Active Programs'); ?></div><div class="fs-3 fw-bold text-primary"><?php echo $activePrograms; ?></div></div></div></div>
    <div class="col-6 col-md-3">
      <a href="program-attendance.php#pa-tab-req" class="text-decoration-none">
        <div class="card admin-stat-card border-0 shadow-sm h-100">
          <div class="card-body">
            <div class="text-muted small"><?php echo adminLangT('Pending अनुरोध', 'Pending Requests'); ?> <i class="fas fa-arrow-right small"></i></div>
            <div class="fs-3 fw-bold text-warning"><?php echo $pendingReq; ?></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3"><div class="card admin-stat-card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small"><?php echo adminLangT('आजको उपस्थिति', 'Today Attendance'); ?></div><div class="fs-3 fw-bold text-success"><?php echo $todayAttend; ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card admin-stat-card border-0 shadow-sm h-100"><div class="card-body"><div class="text-muted small"><?php echo adminLangT('Duplicate प्रयास', 'Duplicate Attempts'); ?></div><div class="fs-3 fw-bold text-danger"><?php echo $duplicateAttempts; ?></div></div></div></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card admin-table-card mb-3">
        <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0"><?php echo adminLangT('Live उपस्थिति', 'Live Attendance'); ?></h6>
          <form method="GET" class="d-flex gap-2 align-items-center">
            <select name="program_id" class="form-select form-select-sm" style="min-width:200px" onchange="this.form.submit()">
              <option value=""><?php echo adminLangT('— कार्यक्रम छान्नुहोस् —', '— Select program —'); ?></option>
              <?php foreach ($activeList as $p): ?>
                <option value="<?php echo (int)$p['id']; ?>" <?php echo $selectedProgramId === (int)$p['id'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($p['title']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <div class="card-body" id="programLiveStatsPanel">
          <?php if ($liveStats): ?>
            <div class="row g-2 text-center mb-3">
              <div class="col-4"><div class="p-2 bg-light rounded"><div class="small text-muted"><?php echo adminLangT('Unique उपस्थित', 'Unique Attended'); ?></div><strong class="fs-4 text-success" data-live="attended"><?php echo (int)$liveStats['attended']; ?></strong></div></div>
              <div class="col-4"><div class="p-2 bg-light rounded"><div class="small text-muted"><?php echo adminLangT('Pre-reg', 'Pre-reg'); ?></div><strong class="fs-4" data-live="prereg"><?php echo (int)$liveStats['prereg']; ?></strong></div></div>
              <div class="col-4"><div class="p-2 bg-light rounded"><div class="small text-muted"><?php echo adminLangT('प्रतिशत', 'Percentage'); ?></div><strong class="fs-4 text-primary" data-live="pct"><?php echo htmlspecialchars((string)$liveStats['pct']); ?>%</strong></div></div>
            </div>
            <?php if (!empty($liveStats['occurrences'])): ?>
              <div class="table-responsive">
                <table class="table table-sm mb-0">
                  <thead><tr><th><?php echo adminLangT('स्थान', 'Location'); ?></th><th><?php echo adminLangT('मिति', 'Date'); ?></th><th class="text-end"><?php echo adminLangT('उपस्थित', 'Attended'); ?></th></tr></thead>
                  <tbody>
                    <?php foreach ($liveStats['occurrences'] as $oc): ?>
                      <tr><td><?php echo htmlspecialchars($oc['location_name'] ?? ''); ?></td><td><?php echo htmlspecialchars($oc['event_date'] ?? '—'); ?></td><td class="text-end"><?php echo (int)($oc['attended_count'] ?? 0); ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-muted mb-0"><?php echo adminLangT('Live stats हेर्न माथिबाट कार्यक्रम छान्नुहोस्।', 'Select a program above to view live stats.'); ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card admin-table-card">
        <div class="card-header"><h6 class="mb-0"><?php echo adminLangT('भर्खरको उपस्थिति', 'Latest Attendance'); ?></h6></div>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th><?php echo adminLangT('सदस्य', 'Member'); ?></th><th><?php echo adminLangT('कार्यक्रम', 'Program'); ?></th><th><?php echo adminLangT('स्थान', 'Location'); ?></th><th><?php echo adminLangT('विधि', 'Method'); ?></th><th><?php echo adminLangT('समय', 'Time'); ?></th></tr></thead>
            <tbody>
              <?php if (empty($recentAttend)): ?>
                <tr><td colspan="5" class="text-muted text-center py-3"><?php echo adminLangT('अहिले कुनै record छैन।', 'No records yet.'); ?></td></tr>
              <?php else: foreach ($recentAttend as $ra): ?>
                <tr>
                  <td><?php echo htmlspecialchars((string)($ra['member_name'] ?? $ra['member_card_no'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string)($ra['program_title'] ?? '')); ?></td>
                  <td><?php echo htmlspecialchars((string)($ra['location_label'] ?? '—')); ?></td>
                  <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars(programAttendanceMethodLabel($ra['attendance_method'] ?? '', strtolower((string)($_SESSION['admin_lang'] ?? 'np')) === 'en')); ?></span></td>
                  <td class="small text-muted"><?php echo htmlspecialchars(substr((string)($ra['attended_at'] ?? ''), 0, 16)); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card admin-table-card mb-3 border-success">
        <div class="card-header bg-success bg-opacity-10"><h6 class="mb-0"><i class="fas fa-lightbulb me-2 text-success"></i><?php echo adminLangT('कार्यक्रम दिनको Flow', 'Event Day Workflow'); ?></h6></div>
        <div class="card-body small">
          <ol class="mb-0 ps-3">
            <li class="mb-2"><?php echo adminLangT('<strong>Registration Desk</strong> — कार्डको Member ID टाइप गरेर तत्काल दर्ता', '<strong>Registration Desk</strong> — instant entry using Member ID on card'); ?></li>
            <li class="mb-2"><?php echo adminLangT('<strong>Member Portal QR</strong> — सदस्यले scan गर्छ → Admin approve', '<strong>Member Portal QR</strong> — member scans → admin approves'); ?></li>
            <li><?php echo adminLangT('<strong>Reports</strong> — consolidated / location / absent हेर्नुहोस्', '<strong>Reports</strong> — view consolidated / location / absent'); ?></li>
          </ol>
          <?php if ($pendingReq > 0): ?>
          <div class="alert alert-warning py-2 px-3 mt-3 mb-0">
            <i class="fas fa-hourglass-half me-1"></i>
            <?php echo adminLangT($pendingReq . ' वटा pending अनुरोध छ — ', $pendingReq . ' pending request(s) — '); ?>
            <a href="program-attendance.php#pa-tab-req"><?php echo adminLangT('अहिले approve गर्नुहोस्', 'Approve now'); ?></a>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card admin-table-card mb-3">
        <div class="card-header"><h6 class="mb-0"><?php echo adminLangT('Quick Links', 'Quick Links'); ?></h6></div>
        <div class="list-group list-group-flush">
          <a class="list-group-item list-group-item-action" href="programs.php"><i class="fas fa-calendar-plus me-2 text-primary"></i><?php echo adminLangT('कार्यक्रम बनाउने / सूची', 'Create / List Programs'); ?></a>
          <a class="list-group-item list-group-item-action" href="program-occurrences.php"><i class="fas fa-map-marker-alt me-2 text-info"></i><?php echo adminLangT('स्थान / सत्र', 'Locations / Sessions'); ?></a>
          <a class="list-group-item list-group-item-action" href="program-registration-desk.php"><i class="fas fa-desktop me-2 text-success"></i><?php echo adminLangT('दर्ता डेस्क (Staff)', 'Registration Desk (Staff)'); ?></a>
          <a class="list-group-item list-group-item-action" href="program-attendance.php"><i class="fas fa-clipboard-check me-2 text-warning"></i><?php echo adminLangT('उपस्थिति अनुरोध / रिपोर्ट', 'Attendance Requests / Report'); ?></a>
          <a class="list-group-item list-group-item-action" href="program-reports-consolidated.php"><i class="fas fa-chart-bar me-2 text-danger"></i><?php echo adminLangT('Consolidated Report', 'Consolidated Report'); ?></a>
          <a class="list-group-item list-group-item-action" href="program-settings.php"><i class="fas fa-cog me-2"></i><?php echo adminLangT('कार्यक्रम सेटिङ', 'Program Settings'); ?></a>
        </div>
      </div>

      <div class="card admin-table-card">
        <div class="card-header"><h6 class="mb-0"><?php echo adminLangT('सक्रिय कार्यक्रम', 'Active Programs'); ?></h6></div>
        <div class="list-group list-group-flush">
          <?php if (empty($activeList)): ?>
            <div class="list-group-item text-muted"><?php echo adminLangT('कुनै सक्रिय कार्यक्रम छैन।', 'No active programs.'); ?></div>
          <?php else: foreach ($activeList as $p): ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="program-detail.php?id=<?php echo (int)$p['id']; ?>">
              <span><?php echo htmlspecialchars($p['title']); ?><?php if ((int)($p['is_multi_location'] ?? 0) === 1): ?> <span class="badge bg-info ms-1">Multi</span><?php endif; ?></span>
              <i class="fas fa-chevron-right small text-muted"></i>
            </a>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php if ($selectedProgramId > 0): ?>
<script>
(function(){
  var pid = <?php echo (int)$selectedProgramId; ?>;
  function poll(){
    fetch('api/program-live-stats.php?program_id=' + pid, {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d || !d.ok) return;
        var el;
        if ((el = document.querySelector('[data-live="attended"]'))) el.textContent = d.attended;
        if ((el = document.querySelector('[data-live="prereg"]'))) el.textContent = d.prereg;
        if ((el = document.querySelector('[data-live="pct"]'))) el.textContent = d.pct + '%';
      }).catch(function(){});
  }
  setInterval(poll, 15000);
})();
</script>
<?php endif; ?>
<?php require_once 'includes/admin-footer.php'; ?>
