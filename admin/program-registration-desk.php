<?php
require_once __DIR__ . '/includes/admin-page-boot.php';
$pageTitle = 'दर्ता डेस्क';
$currentPage = 'program-registration-desk';
require_once 'includes/admin-header.php';
require_once __DIR__ . '/../includes/program-tables.php';
require_once __DIR__ . '/../includes/program-attendance-helpers.php';
require_once __DIR__ . '/../includes/program-member-insights.php';
require_once __DIR__ . '/../includes/member-monthly-saving.php';
require_once __DIR__ . '/../includes/qr-local.php';

$db = getDB();
ensureProgramTables($db);
coop_monthly_saving_ensure_column($db);

$programId = (int)($_POST['program_id'] ?? ($_GET['program_id'] ?? 0));
$occurrenceId = (int)($_POST['occurrence_id'] ?? ($_GET['occurrence_id'] ?? 0));
$deskId = (int)($_POST['desk_id'] ?? ($_GET['desk_id'] ?? 0));
$saved = false;
$duplicate = false;
$error = '';
$existingInfo = null;
$memberPreview = null;

$programs = $db->query("SELECT id, title, program_type, event_date, location, is_multi_location FROM upcoming_programs WHERE is_active=1 ORDER BY COALESCE(event_date,'9999-12-31') ASC, title ASC, id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($programId <= 0 && count($programs) === 1) {
    $programId = (int)$programs[0]['id'];
}
$occurrences = [];
$desks = [];
$prog = $programId > 0 ? programFetchById($db, $programId) : null;
if ($prog && (int)($prog['is_multi_location'] ?? 0) === 1) {
    $st = $db->prepare('SELECT id, location_name, event_date FROM program_occurrences WHERE parent_program_id=? AND is_active=1 ORDER BY sort_order ASC, id ASC');
    $st->execute([$programId]);
    $occurrences = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($occurrences) === 1 && $occurrenceId <= 0) {
        $occurrenceId = (int)$occurrences[0]['id'];
    }
}
if ($occurrenceId > 0 && !in_array($occurrenceId, array_map('intval', array_column($occurrences, 'id')), true)) {
    $occurrenceId = 0;
}
$myAdminId = (int)($_SESSION['admin_id'] ?? 0);
$myDeskId = 0;
if ($programId > 0) {
    foreach (programDesksForProgram($db, $programId, true) as $d) {
        $dOcc = (int)($d['occurrence_id'] ?? 0);
        if ($dOcc > 0 && $occurrenceId > 0 && $dOcc !== $occurrenceId) {
            continue;
        }
        $desks[(int)$d['id']] = $d;
        if ($myDeskId === 0 && (int)($d['assigned_staff_admin_id'] ?? 0) === $myAdminId && $myAdminId > 0) {
            $myDeskId = (int)$d['id'];
        }
    }
}
if (!isset($desks[$deskId])) {
    $deskId = $_SERVER['REQUEST_METHOD'] === 'POST' ? 0 : $myDeskId;
}

$selectedOccurrence = ($occurrenceId > 0 && $prog) ? programFetchOccurrenceById($db, $occurrenceId) : null;
$windowStatus = $prog ? programIsWindowOpen($prog, $selectedOccurrence) : null;
$programQrUrl = '';
if ($prog) {
    $qrToken = '';
    if ($selectedOccurrence && trim((string)($selectedOccurrence['qr_token'] ?? '')) !== '') {
        $qrToken = trim((string)$selectedOccurrence['qr_token']);
    } elseif (trim((string)($prog['qr_token'] ?? '')) !== '') {
        $qrToken = trim((string)$prog['qr_token']);
    }
    if ($qrToken !== '') {
        $programQrUrl = rtrim(SITE_URL, '/') . '/member/attend.php?qr_token=' . rawurlencode($qrToken);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    checkCSRF();
    $memberQuery = trim((string)($_POST['member_id_input'] ?? ''));
    $member = null;
    if ($programId <= 0) {
        $error = 'कार्यक्रम छान्नुहोस्।';
    } elseif ($memberQuery === '') {
        $error = 'Member ID (सदस्यता नं.) राख्नुहोस्।';
    } elseif (!($member = programResolveMemberBySadasyata($db, $memberQuery))) {
        $error = 'Member ID "' . $memberQuery . '" सिस्टममा फेला परेन।';
    } elseif ($prog && (int)($prog['is_multi_location'] ?? 0) === 1 && $occurrenceId <= 0) {
        $error = 'Multi-location कार्यक्रममा occurrence/स्थान छान्नुहोस्।';
    } else {
        $result = recordProgramAttendance($db, [
            'member_id' => (int)$member['id'],
            'member_card_no' => programMemberSadasyataNo($member),
            'program_id' => $programId,
            'occurrence_id' => $occurrenceId > 0 ? $occurrenceId : null,
            'attendance_method' => 'ADMIN_MANUAL',
            'source' => 'registration_desk',
            'attendance_note' => 'Registration Desk',
            'desk_id' => $deskId > 0 ? $deskId : null,
            'staff_admin_id' => $myAdminId,
        ]);
        if (array_key_exists('monthly_saving', $_POST) && (!empty($result['ok']) || !empty($result['duplicate']))) {
            $msParsed = coop_monthly_saving_parse((string) $_POST['monthly_saving']);
            if ($msParsed['ok'] && $msParsed['value'] !== null) {
                coop_monthly_saving_set($db, (int) $member['id'], $msParsed['value'], 'Registration desk');
            }
        }
        if (!empty($result['ok'])) {
            $saved = true;
            $memberPreview = $member;
        } elseif (!empty($result['duplicate'])) {
            $duplicate = true;
            $existingInfo = $result['existing'] ?? null;
            $memberPreview = $member;
        } else {
            $error = $result['error_np'] ?? $result['error_en'] ?? 'Error';
        }
    }
}

?>
<?php
if (function_exists('coopThemeLink')) {
    coopThemeLink('assets/css/admin-program-registration-desk.css');
} elseif (function_exists('coopThemeLinkHtml')) {
    echo coopThemeLinkHtml('assets/css/admin-program-registration-desk.css');
}
?>
<div class="container-fluid desk-page-wrap">
<?php echo adminPageHeader('Registration Desk', 'monitor', 'कार्डको Member ID (सदस्यता नं.) → lookup → Confirm · Staff को एक मात्र उपस्थिति दर्ता ठाउँ',
    $programId > 0
      ? '<a href="program-detail.php?id=' . (int)$programId . '" class="btn btn-sm btn-outline-secondary">← कार्यक्रम hub</a>'
      : '<a href="programs.php" class="btn btn-sm btn-outline-secondary">← कार्यक्रमहरू</a>'); ?>
<div class="desk-shell">
<div class="desk-card">
  <div class="desk-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <strong><?php echo function_exists('icon') ? icon('monitor', 16, 'margin-right:6px;') : '<i class="lucide-icon me-1" data-lucide="monitor" aria-hidden="true"></i>'; ?> Registration Desk</strong>
      <div class="small text-muted">Member ID टाइप → auto lookup → Confirm</div>
    </div>
  </div>
  <div class="p-3 p-md-4">
    <?php if ($saved && $memberPreview): ?>
    <div class="desk-success p-3 mb-3 text-center">
      <i class="lucide-icon text-success lucide-2x" data-lucide="circle-check" aria-hidden="true"></i>
      <div class="fw-bold mt-2 fs-5">✓ उपस्थिति दर्ता भयो!</div>
      <div class="mt-1"><?php echo htmlspecialchars($memberPreview['name'] ?? ''); ?></div>
      <?php $okIdn = programMemberIdentity($db, $memberPreview); ?>
      <?php if ($okIdn['father_name'] !== ''): ?><div class="small text-muted">बुबा: <?php echo htmlspecialchars($okIdn['father_name']); ?></div><?php endif; ?>
      <?php if ($okIdn['dob_label'] !== ''): ?><div class="small text-muted">जन्म मिति: <?php echo htmlspecialchars($okIdn['dob_label']); ?></div><?php endif; ?>
      <div class="small text-muted font-monospace"><?php echo htmlspecialchars(programMemberSadasyataNo($memberPreview)); ?></div>
      <?php if ($selectedOccurrence): ?><div class="small mt-1"><i class="lucide-icon me-1" data-lucide="map-pin" aria-hidden="true"></i><?php echo htmlspecialchars($selectedOccurrence['location_name'] ?? ''); ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($duplicate && $existingInfo): ?>
    <div class="desk-duplicate p-3 mb-3">
      <div class="fw-bold"><i class="lucide-icon me-1" data-lucide="triangle-alert" aria-hidden="true"></i> Already Attended</div>
      <div class="mt-1"><?php echo htmlspecialchars($memberPreview['name'] ?? ''); ?> — <?php echo htmlspecialchars(programMemberSadasyataNo($memberPreview ?? [])); ?></div>
      <?php $dupBy = programAttendanceRecordedBy($db, $existingInfo); ?>
      <div class="small mt-2">
        <div><i class="lucide-icon me-1" data-lucide="user-check" aria-hidden="true"></i>दर्ता गर्ने: <strong><?php echo htmlspecialchars($dupBy['by'] !== '' ? $dupBy['by'] : 'अज्ञात'); ?></strong><?php if ($dupBy['desk'] !== ''): ?> · <?php echo htmlspecialchars($dupBy['desk']); ?><?php endif; ?></div>
        <div><i class="lucide-icon me-1" data-lucide="map-pin" aria-hidden="true"></i><?php echo htmlspecialchars(programAttendanceDisplayLocation($existingInfo)); ?></div>
        <?php if (!empty($existingInfo['attended_at'])): ?><div><i class="lucide-icon me-1" data-lucide="clock" aria-hidden="true"></i><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string)$existingInfo['attended_at']))); ?></div><?php endif; ?>
        <?php if (!empty($existingInfo['attendance_method'])): ?><div><i class="lucide-icon me-1" data-lucide="tag" aria-hidden="true"></i><?php echo htmlspecialchars(programAttendanceMethodLabel($existingInfo['attendance_method'])); ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($prog): ?>
    <div class="desk-status mb-3 <?php echo !empty($windowStatus['ok']) ? 'desk-status-open' : 'desk-status-closed'; ?>">
      <i class="lucide-icon me-1" data-lucide="<?php echo !empty($windowStatus['ok']) ? 'door-open' : 'door-closed'; ?>" aria-hidden="true"></i>
      <?php if (!empty($windowStatus['ok'])): ?>
        उपस्थिति window <strong>खुला</strong> छ — दर्ता गर्न सकिन्छ।
      <?php else: ?>
        <?php echo htmlspecialchars($windowStatus['message_np'] ?? 'उपस्थिति window बन्द छ।'); ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($programQrUrl !== ''): ?>
    <div class="text-center mb-3 p-2 border rounded bg-light">
      <div class="small text-muted mb-1"><i class="lucide-icon me-1" data-lucide="qr-code" aria-hidden="true"></i>सदस्य QR scan (Member Portal)</div>
      <?php echo coop_qr_img_tag($programQrUrl, 120, 'Program QR', 'rounded border bg-white'); ?>
      <div class="mt-1"><a href="<?php echo htmlspecialchars($programQrUrl); ?>" class="small" target="_blank" rel="noopener noreferrer">Attendance link</a></div>
    </div>
    <?php endif; ?>

    <form method="POST" id="deskForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="confirm">
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label" for="deskProgram">कार्यक्रम *</label>
          <select name="program_id" id="deskProgram" class="form-select" required onchange="location.href='program-registration-desk.php?program_id='+this.value">
            <option value="">— छान्नुहोस् —</option>
            <?php foreach ($programs as $p):
              $pLabel = (string)$p['title'];
              $pMeta = array_filter([
                  ($p['program_type'] ?? 'General') !== 'General' ? (string)$p['program_type'] : '',
                  (string)($p['event_date'] ?? ''),
                  (int)($p['is_multi_location'] ?? 0) === 1 ? 'Multi' : (string)($p['location'] ?? ''),
              ]);
              if ($pMeta) { $pLabel .= ' — ' . implode(' · ', $pMeta); }
            ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $programId===(int)$p['id']?'selected':''; ?>><?php echo htmlspecialchars($pLabel); ?> (#<?php echo (int)$p['id']; ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <?php if (!empty($occurrences)): ?>
        <div class="col-md-6"><label class="form-label" for="deskOccurrence">स्थान / Occurrence *</label>
          <select name="occurrence_id" id="deskOccurrence" class="form-select" required>
            <option value="">— छान्नुहोस् —</option>
            <?php foreach ($occurrences as $o): ?><option value="<?php echo (int)$o['id']; ?>" <?php echo $occurrenceId===(int)$o['id']?'selected':''; ?>><?php echo htmlspecialchars($o['location_name']); ?> (<?php echo htmlspecialchars($o['event_date']??''); ?>)</option><?php endforeach; ?>
          </select>
          <?php if (count($occurrences) === 1): ?><div class="form-text text-success"><i class="lucide-icon me-1" data-lucide="check" aria-hidden="true"></i>एक मात्र स्थान — स्वतः छानियो</div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($programId > 0): ?>
        <div class="col-md-6"><label class="form-label" for="deskDeskId">Desk</label>
          <?php if ($desks): ?>
          <select name="desk_id" id="deskDeskId" class="form-select" data-program="<?php echo $programId; ?>" data-server-pick="<?php echo $deskId; ?>">
            <option value="0">— Desk नतोकिएको —</option>
            <?php foreach ($desks as $d):
              $dl = (string)$d['desk_label'] . (!empty($d['location_name']) ? ' — ' . $d['location_name'] : '') . (!empty($d['staff_name']) ? ' (' . $d['staff_name'] . ')' : '');
            ?><option value="<?php echo (int)$d['id']; ?>" <?php echo $deskId===(int)$d['id']?'selected':''; ?>><?php echo htmlspecialchars($dl); ?></option><?php endforeach; ?>
          </select>
          <div class="form-text"><?php echo $myDeskId > 0 ? 'तपाईंलाई तोकिएको desk स्वतः छानियो।' : 'रिपोर्टमा कुन desk बाट कति दर्ता भयो देखिन्छ।'; ?> <a href="program-detail.php?id=<?php echo $programId; ?>#desks">Desk व्यवस्थापन</a></div>
          <?php else: ?>
          <input type="hidden" name="desk_id" value="0">
          <div class="form-control-plaintext small text-muted py-1">Desk बनाइएको छैन — <a href="program-detail.php?id=<?php echo $programId; ?>#desks">कार्यक्रम hub बाट Desk थप्नुहोस्</a> (ऐच्छिक)</div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <div id="deskInlineMsg" class="desk-inline-warn mb-3 d-none"></div>

      <div id="deskPreview" class="desk-idcard mb-3 d-none" aria-live="polite">
        <div class="desk-idcard-head">
          <span><i class="lucide-icon me-1" data-lucide="id-card" aria-hidden="true"></i>सदस्य परिचय</span>
          <span id="deskStatusBadge" class="desk-idcard-badge"></span>
        </div>
        <div class="desk-idcard-body">
          <div class="desk-idcard-photo">
            <img id="deskPhoto" src="" alt="" class="d-none">
            <span id="deskInitials" aria-hidden="true"></span>
          </div>
          <div class="desk-idcard-info">
            <div id="deskName" class="desk-idcard-name"></div>
            <div id="deskNameNp" class="desk-idcard-sub d-none"></div>
            <div id="deskMemberNo" class="desk-idcard-no"></div>
            <dl id="deskFields" class="desk-idcard-fields"></dl>
            <div id="deskSaving" class="desk-saving d-none">
              <span class="desk-saving-label" id="deskSavingLbl">मासिक बचत</span>
              <span id="deskSavingBadge"></span>
              <div class="btn-group btn-group-sm ms-choice" role="radiogroup" aria-labelledby="deskSavingLbl">
                <input type="radio" class="btn-check" name="monthly_saving" id="deskMs1" value="1" disabled>
                <label class="btn btn-outline-success" for="deskMs1">नियमित</label>
                <input type="radio" class="btn-check" name="monthly_saving" id="deskMs0" value="0" disabled>
                <label class="btn btn-outline-warning" for="deskMs0">नियमित नभएको</label>
              </div>
              <span id="deskSavingMsg" class="small" role="status" aria-live="polite"></span>
            </div>
          </div>
        </div>
        <div id="deskDupInfo" class="desk-idcard-dup d-none"></div>
        <div id="deskHistory" class="desk-history small d-none"></div>
      </div>

      <label class="form-label desk-member-id" for="deskMemberInput">Member ID (कार्ड / सदस्यता नं.) *</label>
      <input type="text" name="member_id_input" id="deskMemberInput" class="form-control form-control-lg desk-member-id mb-2" placeholder="कार्डमा भएको Member ID — उदा. AKS-2080-0001" autocomplete="off" autocapitalize="characters" autofocus required>
      <div class="desk-kbd mb-3">Member ID टाइप गर्नुहोस् → auto lookup → Confirm · <kbd>Esc</kbd> clear</div>
      <div class="d-flex gap-2">
        <button type="button" id="deskLookupBtn" class="btn btn-outline-primary btn-lg flex-fill">Lookup</button>
        <button type="submit" id="deskConfirmBtn" class="btn btn-success btn-lg flex-fill" disabled>Confirm Attendance</button>
      </div>
    </form>

  </div>
</div>
</div>
</div>
<script>
(function(){
  var input = document.getElementById('deskMemberInput');
  var confirmBtn = document.getElementById('deskConfirmBtn');
  var lookupBtn = document.getElementById('deskLookupBtn');
  var preview = document.getElementById('deskPreview');
  var inlineMsg = document.getElementById('deskInlineMsg');
  var dupInfo = document.getElementById('deskDupInfo');
  var form = document.getElementById('deskForm');
  var lookupReady = false;
  var lookupTimer = null;
  var lastLookup = '';

  function showInline(msg, isError) {
    if (!inlineMsg) return;
    if (!msg) { inlineMsg.classList.add('d-none'); inlineMsg.textContent = ''; return; }
    inlineMsg.textContent = msg;
    inlineMsg.classList.remove('d-none');
    inlineMsg.style.background = isError ? '#fef2f2' : '#fffbeb';
    inlineMsg.style.borderColor = isError ? '#fecaca' : '#fcd34d';
    inlineMsg.style.color = isError ? '#991b1b' : '#92400e';
  }

  function resetPreview() {
    lookupReady = false;
    if (confirmBtn) confirmBtn.disabled = true;
    if (preview) preview.classList.add('d-none');
    if (dupInfo) { dupInfo.classList.add('d-none'); dupInfo.textContent = ''; }
    var sb = document.getElementById('deskSaving');
    if (sb) {
      sb.classList.add('d-none');
      Array.prototype.forEach.call(sb.querySelectorAll('input[name="monthly_saving"]'), function (r) { r.checked = false; r.disabled = true; });
    }
    showInline('');
  }

  function renderHistory(h) {
    var box = document.getElementById('deskHistory');
    if (!box) return;
    box.textContent = '';
    if (!h) { box.classList.add('d-none'); return; }
    var line = document.createElement('div');
    line.textContent = 'कुल उपस्थिति: ' + h.total + ' कार्यक्रम';
    if (h.agm_last3_total > 0) {
      var b = document.createElement('span');
      var all = h.agm_last3_attended === h.agm_last3_total;
      b.className = 'badge ms-2 ' + (all ? 'bg-success' : (h.agm_last3_attended ? 'bg-warning text-dark' : 'bg-secondary'));
      b.textContent = 'पछिल्ला ' + h.agm_last3_total + ' AGM: ' + h.agm_last3_attended + ' मा उपस्थित';
      line.appendChild(b);
    }
    box.appendChild(line);
    if (h.recent && h.recent.length) {
      var r = document.createElement('div');
      r.className = 'text-muted';
      r.textContent = 'पछिल्लो: ' + h.recent.map(function (x) { return x.title + (x.date ? ' (' + x.date + ')' : ''); }).join(', ');
      box.appendChild(r);
    }
    if (h.url) {
      var a = document.createElement('a');
      a.href = h.url; a.target = '_blank'; a.rel = 'noopener';
      a.textContent = 'पूरा इतिहास हेर्नुहोस् →';
      box.appendChild(a);
    }
    box.classList.remove('d-none');
  }

  function addField(dl, label, value) {
    if (!value) return;
    var dt = document.createElement('dt'); dt.textContent = label;
    var dd = document.createElement('dd'); dd.textContent = value;
    dl.appendChild(dt); dl.appendChild(dd);
  }

  function renderCard(m, isDup) {
    preview.classList.remove('d-none');
    preview.classList.toggle('desk-idcard--dup', !!isDup);
    preview.classList.toggle('desk-idcard--inactive', !isDup && !m.is_active);
    document.getElementById('deskName').textContent = m.name || '';
    var np = document.getElementById('deskNameNp');
    np.textContent = m.name_np || '';
    np.classList.toggle('d-none', !m.name_np);
    document.getElementById('deskMemberNo').textContent = m.member_id || '';
    var badge = document.getElementById('deskStatusBadge');
    badge.textContent = isDup ? 'पहिले नै दर्ता' : (m.is_active ? 'सक्रिय सदस्य' : 'निष्क्रिय सदस्य');
    var img = document.getElementById('deskPhoto');
    var initials = document.getElementById('deskInitials');
    initials.textContent = (m.name || '?').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase();
    if (m.photo_url) {
      img.onerror = function () { img.classList.add('d-none'); initials.classList.remove('d-none'); };
      img.src = m.photo_url; img.alt = m.name || '';
      img.classList.remove('d-none'); initials.classList.add('d-none');
    } else {
      img.removeAttribute('src'); img.classList.add('d-none'); initials.classList.remove('d-none');
    }
    var dl = document.getElementById('deskFields');
    dl.textContent = '';
    addField(dl, 'बुबाको नाम', m.father_name);
    var dob = [m.dob_bs ? m.dob_bs + ' वि.सं.' : '', m.dob_ad ? m.dob_ad + ' AD' : ''].filter(Boolean).join(' · ');
    if (dob && m.age !== null && m.age !== undefined) dob += ' (उमेर ' + m.age + ' वर्ष)';
    addField(dl, 'जन्म मिति', dob);
    addField(dl, 'लिङ्ग', m.gender);
    addField(dl, 'फोन', m.phone);
    addField(dl, 'ठेगाना', m.address);
    renderSaving(m);
  }

  /* मासिक बचत — shows current value; change saves immediately (works for already-attended too) */
  var savingBox = document.getElementById('deskSaving');
  var savingMsg = document.getElementById('deskSavingMsg');
  var savingBadge = document.getElementById('deskSavingBadge');
  var savingMemberPk = 0;
  var msRadios = savingBox ? savingBox.querySelectorAll('input[name="monthly_saving"]') : [];
  function savingBadgeSet(v) {
    if (!savingBadge) return;
    savingBadge.className = 'badge ' + (v === 1 ? 'bg-success' : (v === 0 ? 'bg-warning text-dark' : 'bg-secondary'));
    savingBadge.textContent = v === 1 ? 'नियमित' : (v === 0 ? 'नियमित नभएको' : 'नतोकिएको');
  }
  function renderSaving(m) {
    if (!savingBox) return;
    savingMemberPk = parseInt(m.id || 0, 10) || 0;
    var v = (m.monthly_saving === 0 || m.monthly_saving === 1) ? m.monthly_saving : null;
    savingBadgeSet(v);
    Array.prototype.forEach.call(msRadios, function (r) {
      r.checked = v !== null && String(v) === r.value;
      r.disabled = savingMemberPk < 1;
    });
    if (savingMsg) { savingMsg.textContent = v === null ? 'अहिलेसम्म नतोकिएको — छान्नुहोस्' : ''; savingMsg.className = 'small text-muted'; }
    savingBox.classList.toggle('desk-saving--unset', v === null);
    savingBox.classList.remove('d-none');
  }
  Array.prototype.forEach.call(msRadios, function (r) {
    r.addEventListener('change', function () {
      if (!r.checked || savingMemberPk < 1) return;
      var fd = new FormData();
      fd.append('member_pk', String(savingMemberPk));
      fd.append('monthly_saving', r.value);
      var tok = form ? form.querySelector('[name="csrf_token"]') : null;
      if (tok) fd.append('csrf_token', tok.value);
      if (savingMsg) { savingMsg.textContent = 'सेभ गर्दै…'; savingMsg.className = 'small text-muted'; }
      fetch('api/member-monthly-saving.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (d) {
          if (!d || !d.ok) throw new Error((d && d.error_np) || 'save failed');
          savingBadgeSet(d.value);
          savingBox.classList.remove('desk-saving--unset');
          if (savingMsg) { savingMsg.textContent = d.changed ? '✓ सेभ भयो' : '✓ पहिले नै यही'; savingMsg.className = 'small text-success'; }
        })
        .catch(function (err) {
          if (savingMsg) { savingMsg.textContent = (err && err.message && err.message !== 'save failed' ? err.message : 'सेभ भएन') + ' — Confirm गर्दा पनि save हुन्छ'; savingMsg.className = 'small text-danger'; }
        });
    });
  });

  function renderDuplicate(ex) {
    if (!dupInfo) return;
    dupInfo.textContent = '';
    var t = document.createElement('div');
    t.className = 'desk-idcard-dup-title';
    t.textContent = '⚠ यो सदस्यको उपस्थिति पहिले नै दर्ता भइसकेको छ';
    dupInfo.appendChild(t);
    var dl = document.createElement('dl');
    dl.className = 'desk-idcard-fields mb-0';
    addField(dl, 'दर्ता गर्ने', ex.by || 'अज्ञात');
    addField(dl, 'Desk', ex.desk);
    addField(dl, 'तरिका', ex.method);
    addField(dl, 'स्थान', ex.location);
    addField(dl, 'समय', ex.attended_at);
    dupInfo.appendChild(dl);
    dupInfo.classList.remove('d-none');
  }

  var deskSel = document.getElementById('deskDeskId');
  if (deskSel && window.localStorage) {
    var deskKey = 'coopDesk:' + deskSel.getAttribute('data-program');
    if (deskSel.getAttribute('data-server-pick') === '0') {
      var saved = localStorage.getItem(deskKey);
      if (saved && deskSel.querySelector('option[value="' + saved.replace(/[^0-9]/g, '') + '"]')) deskSel.value = saved;
    }
    deskSel.addEventListener('change', function () { localStorage.setItem(deskKey, deskSel.value); });
  }

  function lookup(){
    var q = (input && input.value || '').trim();
    if (!q) { resetPreview(); return; }
    var pid = document.getElementById('deskProgram') ? document.getElementById('deskProgram').value : '';
    if (!pid) { showInline('पहिले कार्यक्रम छान्नुहोस्।', true); return; }
    var oid = document.getElementById('deskOccurrence') ? document.getElementById('deskOccurrence').value : '0';
    lastLookup = q;
    fetch('api/program-desk-lookup.php?member_id='+encodeURIComponent(q)+'&program_id='+pid+'&occurrence_id='+oid+'&desk_id='+encodeURIComponent(deskSel ? deskSel.value : '0'), {credentials:'same-origin', cache:'no-store'})
      .then(function(r){
        return r.text().then(function(t){
          try { return JSON.parse(t); } catch (e) { return {ok:false, transport:true, error_np:'Lookup response मिलेन (HTTP ' + r.status + ')।'}; }
        });
      })
      .then(function(d){
        if ((input.value || '').trim() !== q) return;
        if (!d.ok && (d.transport || d.error === 'unauthorized')) {
          if (preview) preview.classList.add('d-none');
          showInline((d.error_np || 'Lookup असफल।') + ' Confirm थिच्दा server ले फेरि जाँच गर्छ।', true);
          confirmBtn.disabled = false;
          lookupReady = true;
          return;
        }
        if (!d.ok) {
          showInline(d.error_np || d.error || 'Not found', true);
          resetPreview();
          return;
        }
        renderCard(d.member, d.already_attended);
        if (dupInfo) { dupInfo.classList.add('d-none'); dupInfo.textContent = ''; }
        renderHistory(d.history);

        if (d.window && !d.window.open) {
          showInline(d.window.message_np || 'Window closed', true);
          confirmBtn.disabled = true;
          lookupReady = false;
        } else if (d.needs_occurrence) {
          showInline('Multi-location: पहिले स्थान छान्नुहोस्।', true);
          confirmBtn.disabled = true;
          lookupReady = false;
        } else if (d.already_attended) {
          var ex = d.existing || {};
          showInline('⚠ पहिले नै दर्ता भइसकेको' + (ex.by ? ' — ' + ex.by + ' बाट' : '') + (ex.attended_at ? ' (' + ex.attended_at + ')' : ''), false);
          renderDuplicate(ex);
          confirmBtn.disabled = true;
          lookupReady = false;
        } else {
          showInline('✓ दर्ता गर्न तयार — Enter थिच्नुहोस् वा Confirm', false);
          confirmBtn.disabled = false;
          lookupReady = true;
        }
      })
      .catch(function(){
        if ((input.value || '').trim() !== q) return;
        resetPreview();
        showInline('Lookup असफल (network)। Confirm थिच्दा server ले फेरि जाँच गर्छ।', true);
        confirmBtn.disabled = false;
        lookupReady = true;
      });
  }

  var submitting = false;
  if (form) form.addEventListener('submit', function(e){
    if (submitting) { e.preventDefault(); return; }
    submitting = true;
    if (confirmBtn) confirmBtn.disabled = true;
  });

  if (lookupBtn) lookupBtn.addEventListener('click', lookup);
  if (input) {
    input.addEventListener('input', function(){
      this.value = this.value.toUpperCase();
      clearTimeout(lookupTimer);
      if ((this.value || '').trim().length < 3) { resetPreview(); return; }
      lookupTimer = setTimeout(lookup, 450);
    });
    input.addEventListener('keydown', function(e){
      if (e.key === 'Escape') { this.value = ''; resetPreview(); this.focus(); e.preventDefault(); return; }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (lookupReady && confirmBtn && !confirmBtn.disabled) {
          if (form.requestSubmit) { form.requestSubmit(confirmBtn); } else { form.submit(); }
        } else {
          lookup();
        }
      }
    });
  }
  var occSel = document.getElementById('deskOccurrence');
  if (occSel) occSel.addEventListener('change', function(){
    if ((input.value||'').trim()) { lookup(); return; }
    location.href = 'program-registration-desk.php?program_id=' + encodeURIComponent(document.getElementById('deskProgram').value) + '&occurrence_id=' + encodeURIComponent(occSel.value);
  });

  <?php if ($saved || $duplicate): ?>
  setTimeout(function(){
    if (input) { input.value = ''; input.focus(); }
    resetPreview();
  }, 1200);
  <?php endif; ?>
})();
</script>
<?php require_once 'includes/admin-footer.php'; ?>
