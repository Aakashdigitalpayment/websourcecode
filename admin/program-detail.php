<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/../includes/program-tables.php';
require_once __DIR__ . '/../includes/program-attendance-helpers.php';

$db = getDB();
ensureProgramTables($db);
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$prog = $id > 0 ? programFetchById($db, $id) : null;
if (!$prog) {
    setFlash('error', 'कार्यक्रम फेला परेन।');
    redirect('programs.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    if (($_POST['action'] ?? '') === 'void_attendance') {
        $attId = (int)($_POST['attendance_id'] ?? 0);
        $reason = trim((string)($_POST['void_reason'] ?? ''));
        $res = voidProgramAttendance($db, $attId, (int)($_SESSION['admin_id'] ?? 0), $reason !== '' ? $reason : 'Admin void from program hub');
        setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'उपस्थिति void भयो।' : ($res['error_np'] ?? 'Error'));
    }
    redirect('program-detail.php?id=' . $id);
}

$pageTitle = 'कार्यक्रम — ' . (string)$prog['title'];
$currentPage = 'program-detail';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once 'includes/program-reports-common.php';

$isMulti = (int)($prog['is_multi_location'] ?? 0) === 1;
$stats = programLiveStatsForProgram($db, $prog);
$occRows = $stats['occurrences'];
$activeOccCount = 0;
if ($isMulti) {
    $st = $db->prepare('SELECT COUNT(*) FROM program_occurrences WHERE parent_program_id=? AND is_active=1');
    $st->execute([$id]);
    $activeOccCount = (int)$st->fetchColumn();
}

$pending = 0;
try {
    $st = $db->prepare("SELECT COUNT(*) FROM member_program_attendance_requests WHERE program_id=? AND status='pending'");
    $st->execute([$id]);
    $pending = (int)$st->fetchColumn();
} catch (Throwable $e) {
}

$recentAtt = $db->prepare("SELECT a.*, m.name FROM member_program_attendance a LEFT JOIN members m ON m.id=a.member_id WHERE a.attendance_scope_key=? AND a.attendance_status='VALID' ORDER BY a.attended_at DESC LIMIT 15");
$recentAtt->execute([$stats['scope']]);
$recentRows = $recentAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$window = programIsWindowOpen($prog, null);
$isActive = (int)($prog['is_active'] ?? 0) === 1;
$qrUrl = !empty($prog['qr_token'])
    ? rtrim(SITE_URL, '/') . '/member/attend.php?qr_token=' . rawurlencode((string)$prog['qr_token'])
    : '';
$deskUrl = 'program-registration-desk.php?program_id=' . $id;

if (!$isActive) {
    $nextStep = ['warning', 'यो कार्यक्रम निष्क्रिय छ — उपस्थिति लिन पहिले सक्रिय गर्नुहोस्।', null, null];
} elseif ($isMulti && $activeOccCount === 0) {
    $nextStep = ['warning', 'Multi-location कार्यक्रम — उपस्थिति लिनुअघि कम्तीमा एउटा स्थान थप्नुहोस्।', 'program-occurrences.php?parent_id=' . $id, 'स्थान थप्नुहोस्'];
} elseif (empty($window['ok'])) {
    $nextStep = ['info', ($window['message_np'] ?? 'उपस्थिति window बन्द छ।') . ' आवश्यक भए सम्पादनमा गएर window मिति मिलाउनुहोस्।', 'programs.php?edit=' . $id, 'Window मिलाउनुहोस्'];
} else {
    $nextStep = ['success', 'सबै तयार — दर्ता डेस्कमा Member ID टाइप गरेर उपस्थिति लिनुहोस्।', $deskUrl, 'दर्ता डेस्क खोल्नुहोस्'];
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader(
      (string)$prog['title'],
      'calendar-check',
      programTypeLabel($prog['program_type'] ?? 'General')
        . ' · ' . (string)($prog['event_date'] ?: 'मिति छैन')
        . (!empty($prog['event_time']) ? ' ' . (string)$prog['event_time'] : '')
        . ($isMulti ? ' · Multi-location' : (!empty($prog['location']) ? ' · ' . (string)$prog['location'] : '')),
      '<div class="d-flex gap-2 flex-wrap">'
      . '<a href="programs.php?edit=' . $id . '" class="btn btn-sm btn-outline-primary"><i class="lucide-icon me-1" data-lucide="pen" aria-hidden="true"></i>सम्पादन</a>'
      . '<a href="programs.php" class="btn btn-sm btn-outline-secondary"><i class="lucide-icon me-1" data-lucide="list" aria-hidden="true"></i>सबै कार्यक्रम</a>'
      . '</div>'
  ); ?>
  <?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

  <div class="alert alert-<?php echo $nextStep[0]; ?> d-flex flex-wrap align-items-center gap-2 py-2">
    <i class="lucide-icon" data-lucide="<?php echo $nextStep[0] === 'success' ? 'circle-check' : 'info'; ?>" aria-hidden="true"></i>
    <span class="flex-grow-1"><?php echo htmlspecialchars($nextStep[1]); ?></span>
    <?php if ($nextStep[2]): ?><a href="<?php echo htmlspecialchars($nextStep[2]); ?>" class="btn btn-sm btn-<?php echo $nextStep[0] === 'success' ? 'success' : 'dark'; ?>"><?php echo htmlspecialchars($nextStep[3]); ?> →</a><?php endif; ?>
    <?php if (!$isActive): ?>
      <form method="POST" action="programs.php" class="m-0"><?php echo csrfField(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="return" value="detail"><button type="submit" class="btn btn-sm btn-dark">सक्रिय गर्नुहोस्</button></form>
    <?php endif; ?>
  </div>

  <div class="row g-2 mb-3">
    <?php
      $cards = [
          ['उपस्थित (unique)', $stats['attended'], 'text-success'],
          ['दर', $stats['pct'] . '%', 'text-primary'],
          ['योग्य सदस्य', $stats['eligible'], ''],
          ['Pre-reg', $stats['prereg'], ''],
          ['Pending अनुरोध', $pending, $pending > 0 ? 'text-warning' : ''],
          ['स्थान', $isMulti ? $activeOccCount : '—', ''],
      ];
      foreach ($cards as [$lbl, $val, $cls]): ?>
      <div class="col-6 col-md-2"><div class="card p-2 text-center h-100"><div class="small text-muted"><?php echo htmlspecialchars($lbl); ?></div><strong class="fs-4 <?php echo $cls; ?>"><?php echo htmlspecialchars((string)$val); ?></strong></div></div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card admin-table-card mb-3">
        <div class="card-header"><h6 class="mb-0"><i class="lucide-icon me-1" data-lucide="user-check" aria-hidden="true"></i>उपस्थिति लिने तरिका</h6></div>
        <div class="card-body">
          <a href="<?php echo htmlspecialchars($deskUrl); ?>" class="btn btn-success w-100 mb-1"><i class="lucide-icon me-1" data-lucide="monitor" aria-hidden="true"></i>दर्ता डेस्क — Member ID टाइप गरी दर्ता</a>
          <div class="small text-muted mb-3">Staff ले कार्डको Member ID टाइप गर्छ → तुरुन्तै उपस्थिति।</div>

          <div class="fw-semibold small mb-1"><i class="lucide-icon me-1" data-lucide="qr-code" aria-hidden="true"></i>Member Portal QR</div>
          <?php if ($qrUrl !== ''): ?>
            <div class="d-flex gap-3 align-items-start">
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&margin=4&data=<?php echo rawurlencode($qrUrl); ?>" width="120" height="120" alt="QR" class="border rounded bg-white">
              <div class="small">
                <div class="mb-1"><?php echo !empty($prog['instant_attendance']) ? 'Scan पछि तुरुन्तै उपस्थित।' : 'Scan → pending अनुरोध → Admin approve।'; ?></div>
                <?php if (!empty($prog['qr_expires_at'])): ?><div class="text-muted mb-1">QR समाप्त: <?php echo htmlspecialchars(substr((string)$prog['qr_expires_at'], 0, 16)); ?> (AD)</div><?php endif; ?>
                <input type="text" class="form-control form-control-sm font-monospace mb-1" readonly value="<?php echo htmlspecialchars($qrUrl); ?>" onclick="this.select()">
                <form method="POST" action="programs.php" class="d-inline" onsubmit="return confirm('QR हटाउने?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="clear_qr"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="return" value="detail"><button type="submit" class="btn btn-sm btn-outline-danger py-0">QR हटाउनुहोस्</button></form>
              </div>
            </div>
          <?php else: ?>
            <form method="POST" action="programs.php" class="m-0"><?php echo csrfField(); ?><input type="hidden" name="action" value="gen_qr"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="return" value="detail"><button type="submit" class="btn btn-sm btn-outline-primary"><i class="lucide-icon me-1" data-lucide="qr-code" aria-hidden="true"></i>QR बनाउनुहोस्</button></form>
            <div class="small text-muted mt-1">QR ऐच्छिक हो — डेस्क मात्र प्रयोग गर्दा पनि हुन्छ।</div>
          <?php endif; ?>
          <?php if ($pending > 0): ?>
            <a href="program-attendance.php?program_id=<?php echo $id; ?>#pa-tab-req" class="btn btn-sm btn-warning w-100 mt-3"><?php echo $pending; ?> pending QR अनुरोध approve गर्नुहोस् →</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="card admin-table-card mb-3">
        <div class="card-header"><h6 class="mb-0"><i class="lucide-icon me-1" data-lucide="bar-chart-3" aria-hidden="true"></i>रिपोर्ट</h6></div>
        <div class="card-body pb-0"><?php echo programReportsTabs('', $id); ?></div>
      </div>
    </div>

    <div class="col-lg-7">
      <?php if ($isMulti): ?>
      <div class="card admin-table-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0"><i class="lucide-icon me-1" data-lucide="map-pin" aria-hidden="true"></i>स्थान / सत्र</h6>
          <a href="program-occurrences.php?parent_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-info py-0">व्यवस्थापन / QR</a>
        </div>
        <table class="table table-sm mb-0">
          <thead><tr><th>स्थान</th><th>मिति</th><th class="text-end">उपस्थित</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($occRows)): ?>
              <tr><td colspan="4" class="text-muted text-center py-3">अहिले स्थान छैन — तलबाट थप्नुहोस्।</td></tr>
            <?php else: foreach ($occRows as $o): ?>
              <tr>
                <td><?php echo htmlspecialchars($o['location_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($o['event_date'] ?? '—'); ?></td>
                <td class="text-end"><?php echo (int)($o['attended_count'] ?? 0); ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-success py-0" href="<?php echo htmlspecialchars($deskUrl . '&occurrence_id=' . (int)$o['id']); ?>">डेस्क</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <div class="card-body border-top">
          <form method="POST" action="program-occurrences.php" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="parent_id" value="<?php echo $id; ?>">
            <input type="hidden" name="is_active" value="1">
            <input type="hidden" name="return" value="detail">
            <div class="col-md-6"><label class="form-label small mb-0" for="hub_loc">नयाँ स्थान</label><input name="location_name" id="hub_loc" class="form-control form-control-sm" placeholder="उदा. Banepa" required></div>
            <div class="col-md-4"><label class="form-label small mb-0" for="hub_loc_date">मिति (वि.सं.)</label><input name="event_date" id="hub_loc_date" class="form-control form-control-sm nepali-datepicker" value="<?php echo htmlspecialchars((string)($prog['event_date'] ?? '')); ?>"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-sm btn-primary w-100">थप्नुहोस्</button></div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <div class="card admin-table-card">
        <div class="card-header"><h6 class="mb-0"><i class="lucide-icon me-1" data-lucide="clock" aria-hidden="true"></i>भर्खरको उपस्थिति</h6></div>
        <div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>सदस्य</th><th>स्थान</th><th>विधि</th><th>समय</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($recentRows)): ?>
              <tr><td colspan="5" class="text-muted text-center py-3">अहिलेसम्म उपस्थिति छैन।</td></tr>
            <?php else: foreach ($recentRows as $ra): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)($ra['name'] ?? '')); ?> <span class="small text-muted font-monospace"><?php echo htmlspecialchars((string)($ra['member_card_no'] ?? '')); ?></span></td>
                <td><?php echo htmlspecialchars((string)($ra['location_label'] ?? '—')); ?></td>
                <td><?php echo htmlspecialchars(programAttendanceMethodLabel($ra['attendance_method'] ?? '')); ?></td>
                <td class="small"><?php echo htmlspecialchars(substr((string)($ra['attended_at'] ?? ''), 0, 16)); ?></td>
                <td><form method="POST" class="d-inline" onsubmit="var r=prompt('Void गर्ने कारण?');if(!r)return false;this.void_reason.value=r;return true;"><?php echo csrfField(); ?><input type="hidden" name="action" value="void_attendance"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="attendance_id" value="<?php echo (int)$ra['id']; ?>"><input type="hidden" name="void_reason" value=""><button type="submit" class="btn btn-sm btn-outline-danger py-0">Void</button></form></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
