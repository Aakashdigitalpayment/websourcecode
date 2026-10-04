<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/../includes/program-tables.php';
require_once __DIR__ . '/../includes/program-attendance-helpers.php';
require_once __DIR__ . '/../includes/program-member-insights.php';
require_once __DIR__ . '/../includes/qr-local.php';

$db = getDB();
ensureProgramTables($db);
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$prog = $id > 0 ? programFetchById($db, $id) : null;
if (!$prog) {
    setFlash('error', 'कार्यक्रम फेला परेन।');
    redirect('programs.php');
}

/** Occurrence must belong to this program; staff must be an active admin. 0 = none. */
$deskOccurrence = static function (PDO $db, int $programId, int $occ): int {
    if ($occ < 1) {
        return 0;
    }
    $st = $db->prepare('SELECT id FROM program_occurrences WHERE id=? AND parent_program_id=?');
    $st->execute([$occ, $programId]);
    return (int)$st->fetchColumn();
};
$deskStaff = static function (PDO $db, int $staff): int {
    if ($staff < 1) {
        return 0;
    }
    $st = $db->prepare('SELECT id FROM admin_users WHERE id=? AND is_active=1');
    $st->execute([$staff]);
    return (int)$st->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुन: प्रयास गर्नुहोस्।');
        redirect('program-detail.php?id=' . $id);
    }
    $action = (string)($_POST['action'] ?? '');
    $redirectHash = '';
    if ($action === 'add_desk') {
        $label = mb_substr(trim((string)($_POST['desk_label'] ?? '')), 0, 60);
        $occ = $deskOccurrence($db, $id, (int)($_POST['desk_occurrence_id'] ?? 0));
        $staff = $deskStaff($db, (int)($_POST['desk_staff_id'] ?? 0));
        $dup = $db->prepare('SELECT id FROM program_registration_desks WHERE parent_program_id=? AND LOWER(TRIM(desk_label))=LOWER(?) AND is_active=1 LIMIT 1');
        $dup->execute([$id, $label]);
        if ($label === '') {
            setFlash('error', 'Desk को नाम राख्नुहोस्।');
        } elseif ($dup->fetchColumn()) {
            setFlash('error', '«' . $label . '» नामको desk पहिले नै छ।');
        } else {
            $db->prepare('INSERT INTO program_registration_desks (parent_program_id, occurrence_id, desk_label, assigned_staff_admin_id) VALUES (?,?,?,?)')
                ->execute([$id, $occ ?: null, $label, $staff ?: null]);
            setFlash('success', 'Desk «' . $label . '» थपियो।');
        }
        $redirectHash = '#desks';
    } elseif ($action === 'update_desk' || $action === 'toggle_desk') {
        $deskId = (int)($_POST['desk_id'] ?? 0);
        $own = $db->prepare('SELECT id, is_active FROM program_registration_desks WHERE id=? AND parent_program_id=?');
        $own->execute([$deskId, $id]);
        $desk = $own->fetch(PDO::FETCH_ASSOC);
        if (!$desk) {
            setFlash('error', 'Desk फेला परेन।');
        } elseif ($action === 'toggle_desk') {
            $db->prepare('UPDATE program_registration_desks SET is_active=? WHERE id=?')->execute([(int)$desk['is_active'] === 1 ? 0 : 1, $deskId]);
            setFlash('success', (int)$desk['is_active'] === 1 ? 'Desk निष्क्रिय गरियो (पुराना दर्ता रिपोर्टमा रहन्छन्)।' : 'Desk सक्रिय गरियो।');
        } else {
            $staff = $deskStaff($db, (int)($_POST['desk_staff_id'] ?? 0));
            $db->prepare('UPDATE program_registration_desks SET assigned_staff_admin_id=? WHERE id=?')->execute([$staff ?: null, $deskId]);
            setFlash('success', 'Desk staff अद्यावधिक भयो।');
        }
        $redirectHash = '#desks';
    } elseif ($action === 'void_attendance') {
        $attId = (int)($_POST['attendance_id'] ?? 0);
        $reason = trim((string)($_POST['void_reason'] ?? ''));
        $own = $db->prepare('SELECT 1 FROM member_program_attendance WHERE id=? AND (program_id=? OR parent_program_id=?) LIMIT 1');
        $own->execute([$attId, $id, $id]);
        $res = $own->fetchColumn()
            ? voidProgramAttendance($db, $attId, (int)($_SESSION['admin_id'] ?? 0), $reason !== '' ? $reason : 'Admin void from program hub')
            : ['ok' => false, 'error_np' => 'यो उपस्थिति यस कार्यक्रमको होइन।'];
        setFlash($res['ok'] ? 'success' : 'error', $res['ok'] ? 'उपस्थिति void भयो।' : ($res['error_np'] ?? 'Error'));
    }
    redirect('program-detail.php?id=' . $id . $redirectHash);
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

$kycParts = programKycJoinParts($db);
$recentAtt = $db->prepare("SELECT a.*, m.name, {$kycParts['select']} FROM member_program_attendance a LEFT JOIN members m ON m.id=a.member_id {$kycParts['join']} WHERE a.attendance_scope_key=? AND a.attendance_status='VALID' ORDER BY a.attended_at DESC LIMIT 15");
$recentAtt->execute([$stats['scope']]);
$recentRows = $recentAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$deskRows = programDesksForProgram($db, $id);
$staffList = $db->query("SELECT id, full_name, username FROM admin_users WHERE is_active=1 ORDER BY full_name ASC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$occChoices = [];
if ($isMulti) {
    $st = $db->prepare('SELECT id, location_name FROM program_occurrences WHERE parent_program_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC');
    $st->execute([$id]);
    $occChoices = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$staffOptions = static function (array $staffList, int $selected): string {
    $html = '<option value="0">— Staff नतोकिएको —</option>';
    foreach ($staffList as $u) {
        $html .= '<option value="' . (int)$u['id'] . '"' . ((int)$u['id'] === $selected ? ' selected' : '') . '>'
            . htmlspecialchars(trim((string)$u['full_name']) . ' (@' . $u['username'] . ')') . '</option>';
    }
    return $html;
};

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

  <div class="row g-3" data-no-autosplit>
    <div class="col-lg-5">
      <div class="card admin-table-card mb-3">
        <div class="card-header"><h6 class="mb-0"><i class="lucide-icon me-1" data-lucide="user-check" aria-hidden="true"></i>उपस्थिति लिने तरिका</h6></div>
        <div class="card-body">
          <a href="<?php echo htmlspecialchars($deskUrl); ?>" class="btn btn-success w-100 mb-1"><i class="lucide-icon me-1" data-lucide="monitor" aria-hidden="true"></i>दर्ता डेस्क — Member ID टाइप गरी दर्ता</a>
          <div class="small text-muted mb-3">Staff ले कार्डको Member ID टाइप गर्छ → तुरुन्तै उपस्थिति।</div>

          <div class="fw-semibold small mb-1"><i class="lucide-icon me-1" data-lucide="qr-code" aria-hidden="true"></i>Member Portal QR</div>
          <?php if ($qrUrl !== ''): ?>
            <div class="d-flex gap-3 align-items-start">
              <?php echo coop_qr_img_tag($qrUrl, 120, 'Program QR', 'border rounded bg-white flex-shrink-0'); ?>
              <div class="small">
                <div class="mb-1"><?php echo !empty($prog['instant_attendance']) ? 'Scan पछि तुरुन्तै उपस्थित।' : 'Scan → pending अनुरोध → Admin approve।'; ?></div>
                <?php if (!empty($prog['qr_expires_at'])): ?><div class="text-muted mb-1">QR समाप्त: <?php echo htmlspecialchars(substr((string)$prog['qr_expires_at'], 0, 16)); ?> (AD)</div><?php endif; ?>
                <input type="text" class="form-control form-control-sm font-monospace mb-1" readonly value="<?php echo htmlspecialchars($qrUrl); ?>" onclick="this.select()">
                <form method="POST" action="programs.php" class="d-inline" onsubmit="return confirm('QR हटाउने?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="clear_qr"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="return" value="detail"><button type="submit" class="btn btn-sm btn-outline-danger">QR हटाउनुहोस्</button></form>
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

      <div class="card admin-table-card mb-3" id="desks">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0"><i class="lucide-icon me-1" data-lucide="monitor" aria-hidden="true"></i>दर्ता डेस्कहरू</h6>
          <span class="small text-muted">ऐच्छिक — धेरै staff हुँदा</span>
        </div>
        <?php if ($deskRows): ?>
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Desk</th><th>Staff</th><th class="text-end">दर्ता</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($deskRows as $d): $dActive = (int)$d['is_active'] === 1; ?>
            <tr class="<?php echo $dActive ? '' : 'text-muted'; ?>">
              <td><strong><?php echo htmlspecialchars((string)$d['desk_label']); ?></strong>
                <?php if (!empty($d['location_name'])): ?><div class="small text-muted"><?php echo htmlspecialchars((string)$d['location_name']); ?></div><?php endif; ?>
                <?php if (!$dActive): ?><span class="badge bg-secondary">निष्क्रिय</span><?php endif; ?></td>
              <td>
                <form method="POST" class="m-0"><?php echo csrfField(); ?><input type="hidden" name="action" value="update_desk"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="desk_id" value="<?php echo (int)$d['id']; ?>">
                  <select name="desk_staff_id" class="form-select form-select-sm" aria-label="Desk staff" onchange="this.form.submit()"><?php echo $staffOptions($staffList, (int)($d['assigned_staff_admin_id'] ?? 0)); ?></select>
                </form>
              </td>
              <td class="text-end"><?php echo (int)$d['attended_count']; ?></td>
              <td class="text-end text-nowrap">
                <?php if ($dActive): ?><a class="btn btn-sm btn-outline-success" href="<?php echo htmlspecialchars($deskUrl . '&desk_id=' . (int)$d['id'] . (!empty($d['occurrence_id']) ? '&occurrence_id=' . (int)$d['occurrence_id'] : '')); ?>">खोल्नुहोस्</a><?php endif; ?>
                <form method="POST" class="d-inline"><?php echo csrfField(); ?><input type="hidden" name="action" value="toggle_desk"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="desk_id" value="<?php echo (int)$d['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo $dActive ? 'बन्द' : 'सक्रिय'; ?></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
        <div class="card-body <?php echo $deskRows ? 'border-top' : ''; ?>">
          <?php if (!$deskRows): ?><div class="small text-muted mb-2">Desk बनाएर staff तोक्दा रिपोर्टमा «कुन desk / staff बाट कति दर्ता» देखिन्छ। एउटै staff भए desk नबनाए पनि हुन्छ।</div><?php endif; ?>
          <form method="POST" class="row g-2 align-items-end">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="add_desk"><input type="hidden" name="id" value="<?php echo $id; ?>">
            <div class="col-sm-<?php echo $occChoices ? '4' : '6'; ?>"><label class="form-label small mb-0" for="desk_label">Desk नाम</label><input name="desk_label" id="desk_label" class="form-control form-control-sm" maxlength="60" placeholder="उदा. Desk 1 / गेट A" value="Desk <?php echo count($deskRows) + 1; ?>" required></div>
            <?php if ($occChoices): ?>
            <div class="col-sm-4"><label class="form-label small mb-0" for="desk_occ">स्थान</label><select name="desk_occurrence_id" id="desk_occ" class="form-select form-select-sm"><option value="0">सबै स्थान</option><?php foreach ($occChoices as $o): ?><option value="<?php echo (int)$o['id']; ?>"><?php echo htmlspecialchars((string)$o['location_name']); ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="col-sm-<?php echo $occChoices ? '4' : '6'; ?>"><label class="form-label small mb-0" for="desk_staff">Staff</label><select name="desk_staff_id" id="desk_staff" class="form-select form-select-sm"><?php echo $staffOptions($staffList, 0); ?></select></div>
            <div class="col-12"><button type="submit" class="btn btn-sm btn-primary w-100"><i class="lucide-icon me-1" data-lucide="plus" aria-hidden="true"></i>Desk थप्नुहोस्</button></div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <?php if ($isMulti): ?>
      <div class="card admin-table-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0"><i class="lucide-icon me-1" data-lucide="map-pin" aria-hidden="true"></i>स्थान / सत्र</h6>
          <a href="program-occurrences.php?parent_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">व्यवस्थापन / QR</a>
        </div>
        <div class="table-responsive"><table class="table table-sm mb-0">
          <thead><tr><th>स्थान</th><th>मिति</th><th class="text-end">उपस्थित</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($occRows)): ?>
              <tr><td colspan="4" class="text-muted text-center py-3">अहिले स्थान छैन — तलबाट थप्नुहोस्।</td></tr>
            <?php else: foreach ($occRows as $o): ?>
              <tr>
                <td><?php echo htmlspecialchars($o['location_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($o['event_date'] ?? '—'); ?></td>
                <td class="text-end"><?php echo (int)($o['attended_count'] ?? 0); ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-success" href="<?php echo htmlspecialchars($deskUrl . '&occurrence_id=' . (int)$o['id']); ?>">डेस्क</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table></div>
        <div class="card-body border-top">
          <form method="POST" action="program-occurrences.php" class="row g-2 align-items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="parent_id" value="<?php echo $id; ?>">
            <input type="hidden" name="is_active" value="1">
            <input type="hidden" name="return" value="detail">
            <div class="col-md-6"><label class="form-label small mb-0" for="hub_loc">नयाँ स्थान</label><input name="location_name" id="hub_loc" class="form-control form-control-sm" placeholder="उदा. Banepa" required></div>
            <div class="col-md-4"><label class="form-label small mb-0" for="hub_loc_date">मिति (वि.सं.)</label><input type="text" name="event_date" id="hub_loc_date" class="form-control form-control-sm nepali-datepicker" placeholder="YYYY-MM-DD" autocomplete="off" value="<?php echo htmlspecialchars((string)($prog['event_date'] ?? '')); ?>"></div>
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
                <td><a href="program-member-history.php?member_id=<?php echo rawurlencode((string)($ra['member_card_no'] ?? '')); ?>" class="text-decoration-none"><?php echo htmlspecialchars((string)($ra['name'] ?? '')); ?></a> <span class="small text-muted font-monospace"><?php echo htmlspecialchars((string)($ra['member_card_no'] ?? '')); ?></span>
                  <?php if (($ra['father_name'] ?? '') !== ''): ?><div class="small text-muted">बुबा: <?php echo htmlspecialchars((string)$ra['father_name']); ?></div><?php endif; ?></td>
                <td><?php echo htmlspecialchars(programAttendanceDisplayLocation($ra) ?: '—'); ?></td>
                <td><?php echo htmlspecialchars(programAttendanceMethodLabel($ra['attendance_method'] ?? '')); ?></td>
                <td class="small"><?php echo htmlspecialchars(substr((string)($ra['attended_at'] ?? ''), 0, 16)); ?></td>
                <td><form method="POST" class="d-inline" onsubmit="var r=prompt('Void गर्ने कारण?');if(!r)return false;this.void_reason.value=r;return true;"><?php echo csrfField(); ?><input type="hidden" name="action" value="void_attendance"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="attendance_id" value="<?php echo (int)$ra['id']; ?>"><input type="hidden" name="void_reason" value=""><button type="submit" class="btn btn-sm btn-outline-danger" aria-label="उपस्थिति रद्द (Void)"><i class="lucide-icon" data-lucide="ban" aria-hidden="true"></i>Void</button></form></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
