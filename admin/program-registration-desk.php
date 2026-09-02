<?php
$pageTitle = 'दर्ता डेस्क';
$currentPage = 'program-registration-desk';
require_once 'includes/admin-header.php';
require_once __DIR__ . '/../includes/program-tables.php';
require_once __DIR__ . '/../includes/program-attendance-helpers.php';

$db = getDB();
ensureProgramTables($db);

$programId = (int)($_POST['program_id'] ?? ($_GET['program_id'] ?? 0));
$occurrenceId = (int)($_POST['occurrence_id'] ?? ($_GET['occurrence_id'] ?? 0));
$deskId = (int)($_POST['desk_id'] ?? ($_GET['desk_id'] ?? 0));
$saved = false;
$duplicate = false;
$error = '';
$existingInfo = null;
$memberPreview = null;

$programs = $db->query("SELECT id, title, is_multi_location FROM upcoming_programs WHERE is_active=1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
if ($programId > 0) {
    $st = $db->prepare('SELECT * FROM program_registration_desks WHERE parent_program_id=? AND is_active=1 ORDER BY id ASC');
    $st->execute([$programId]);
    $desks = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        if ($programId <= 0) {
            $error = 'कार्यक्रम छान्नुहोस्।';
        } elseif ($memberQuery === '') {
            $error = 'Member ID (सदस्यता नं.) राख्नुहोस्।';
        } else {
            $member = programResolveMemberBySadasyata($db, $memberQuery);
            if (!$member) {
                $error = 'Member ID "' . htmlspecialchars($memberQuery) . '" सिस्टममा फेला परेन।';
            } else {
            $occId = $occurrenceId > 0 ? $occurrenceId : null;
            if ($prog && (int)($prog['is_multi_location'] ?? 0) === 1 && !$occId) {
                $error = 'Multi-location कार्यक्रममा occurrence/स्थान छान्नुहोस्।';
            } else {
                $occRow = $occId ? programFetchOccurrenceById($db, $occId) : null;
                $window = programIsWindowOpen($prog, $occRow);
                if (empty($window['ok'])) {
                    $error = $window['message_np'] ?? 'उपस्थिति window बन्द छ।';
                } else {
                $result = recordProgramAttendance($db, [
                    'member_id' => (int)$member['id'],
                    'member_card_no' => programMemberSadasyataNo($member),
                    'program_id' => $programId,
                    'occurrence_id' => $occId,
                    'attendance_method' => 'ADMIN_MANUAL',
                    'source' => 'registration_desk',
                    'attendance_note' => 'Registration Desk',
                    'desk_id' => $deskId > 0 ? $deskId : null,
                    'staff_admin_id' => (int)($_SESSION['admin_id'] ?? 0),
                ]);
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
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_desk') {
    checkCSRF();
    $parentId = (int)($_POST['parent_id'] ?? 0);
    $label = trim((string)($_POST['desk_label'] ?? 'Desk 01'));
    $occ = (int)($_POST['desk_occurrence_id'] ?? 0);
    if ($parentId > 0 && $label !== '') {
        $db->prepare('INSERT INTO program_registration_desks (parent_program_id, occurrence_id, desk_label, assigned_staff_admin_id) VALUES (?,?,?,?)')
            ->execute([$parentId, $occ > 0 ? $occ : null, mb_substr($label, 0, 60), (int)($_SESSION['admin_id'] ?? 0) ?: null]);
        redirect('program-registration-desk.php?program_id=' . $parentId);
    }
}
?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registration Desk - Admin</title>
<link rel="stylesheet" href="<?php echo SITE_URL; ?>assets/vendor/bootstrap.min.css">
<?php if (function_exists('coopThemeHeadAssets')) coopThemeHeadAssets('admin'); ?>
<style>
.desk-shell { min-height:100vh; background:linear-gradient(135deg,#064e3b 0%,#0f766e 100%); padding:1rem; }
.desk-card { background:#fff; border-radius:16px; box-shadow:0 20px 50px rgba(0,0,0,.15); max-width:920px; margin:0 auto; }
.desk-header { background:#ecfdf5; border-radius:16px 16px 0 0; padding:1rem 1.25rem; border-bottom:1px solid #d1fae5; }
.desk-member-id { font-size:1.5rem; font-weight:700; letter-spacing:.02em; }
.desk-photo { width:120px; height:120px; object-fit:cover; border-radius:12px; border:3px solid #10b981; background:#f3f4f6; }
.desk-success { background:#ecfdf5; border:2px solid #10b981; border-radius:12px; }
.desk-duplicate { background:#fef3c7; border:2px solid #f59e0b; border-radius:12px; }
.desk-preview { background:#f8fafc; border:2px solid #e2e8f0; border-radius:12px; padding:1rem; }
.desk-status { border-radius:10px; padding:.5rem .75rem; font-size:.85rem; }
.desk-status-open { background:#ecfdf5; color:#065f46; border:1px solid #6ee7b7; }
.desk-status-closed { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
.desk-inline-warn { background:#fffbeb; border:1px solid #fcd34d; border-radius:10px; padding:.65rem .85rem; font-size:.85rem; color:#92400e; }
.desk-kbd { font-size:.72rem; color:#64748b; }
</style>
</head>
<body class="desk-shell">
<div class="desk-card">
  <div class="desk-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <strong><i class="fas fa-desktop me-1"></i> Registration Desk</strong>
      <div class="small text-muted">कार्डको Member ID (सदस्यता नं.) → lookup → Confirm · Staff को एक मात्र उपस्थिति दर्ता ठाउँ</div>
    </div>
    <a href="program-dashboard.php" class="btn btn-sm btn-outline-secondary">← Dashboard</a>
  </div>
  <div class="p-3 p-md-4">
    <?php if ($saved && $memberPreview): ?>
    <div class="desk-success p-3 mb-3 text-center">
      <i class="fas fa-check-circle text-success fa-2x"></i>
      <div class="fw-bold mt-2 fs-5">✓ उपस्थिति दर्ता भयो!</div>
      <div class="mt-1"><?php echo htmlspecialchars($memberPreview['name'] ?? ''); ?></div>
      <div class="small text-muted font-monospace"><?php echo htmlspecialchars(programMemberSadasyataNo($memberPreview)); ?></div>
      <?php if ($selectedOccurrence): ?><div class="small mt-1"><i class="fas fa-location-dot me-1"></i><?php echo htmlspecialchars($selectedOccurrence['location_name'] ?? ''); ?></div><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($duplicate && $existingInfo): ?>
    <div class="desk-duplicate p-3 mb-3">
      <div class="fw-bold"><i class="fas fa-exclamation-triangle me-1"></i> Already Attended</div>
      <div class="mt-1"><?php echo htmlspecialchars($memberPreview['name'] ?? ''); ?> — <?php echo htmlspecialchars(programMemberSadasyataNo($memberPreview ?? [])); ?></div>
      <div class="small mt-2">
        <div><i class="fas fa-location-dot me-1"></i><?php echo htmlspecialchars(programAttendanceDisplayLocation($existingInfo)); ?></div>
        <?php if (!empty($existingInfo['attended_at'])): ?><div><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string)$existingInfo['attended_at']))); ?></div><?php endif; ?>
        <?php if (!empty($existingInfo['attendance_method'])): ?><div><i class="fas fa-tag me-1"></i><?php echo htmlspecialchars(programAttendanceMethodLabel($existingInfo['attendance_method'])); ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php if ($prog): ?>
    <div class="desk-status mb-3 <?php echo !empty($windowStatus['ok']) ? 'desk-status-open' : 'desk-status-closed'; ?>">
      <i class="fas fa-<?php echo !empty($windowStatus['ok']) ? 'door-open' : 'door-closed'; ?> me-1"></i>
      <?php if (!empty($windowStatus['ok'])): ?>
        उपस्थिति window <strong>खुला</strong> छ — दर्ता गर्न सकिन्छ।
      <?php else: ?>
        <?php echo htmlspecialchars($windowStatus['message_np'] ?? 'उपस्थिति window बन्द छ।'); ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($programQrUrl !== ''): ?>
    <div class="text-center mb-3 p-2 border rounded bg-light">
      <div class="small text-muted mb-1"><i class="fas fa-qrcode me-1"></i>सदस्य QR scan (Member Portal)</div>
      <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&margin=4&amp;data=<?php echo urlencode($programQrUrl); ?>" alt="Program QR" width="120" height="120" class="rounded border bg-white">
      <div class="mt-1"><a href="<?php echo htmlspecialchars($programQrUrl); ?>" class="small" target="_blank" rel="noopener noreferrer">Attendance link</a></div>
    </div>
    <?php endif; ?>

    <form method="POST" id="deskForm">
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="confirm">
      <div class="row g-3 mb-3">
        <div class="col-md-6"><label class="form-label">कार्यक्रम *</label>
          <select name="program_id" id="deskProgram" class="form-select" required onchange="location.href='program-registration-desk.php?program_id='+this.value">
            <option value="">— छान्नुहोस् —</option>
            <?php foreach ($programs as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $programId===(int)$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['title']); ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php if (!empty($occurrences)): ?>
        <div class="col-md-6"><label class="form-label">स्थान / Occurrence *</label>
          <select name="occurrence_id" id="deskOccurrence" class="form-select" required>
            <option value="">— छान्नुहोस् —</option>
            <?php foreach ($occurrences as $o): ?><option value="<?php echo (int)$o['id']; ?>" <?php echo $occurrenceId===(int)$o['id']?'selected':''; ?>><?php echo htmlspecialchars($o['location_name']); ?> (<?php echo htmlspecialchars($o['event_date']??''); ?>)</option><?php endforeach; ?>
          </select>
          <?php if (count($occurrences) === 1): ?><div class="form-text text-success"><i class="fas fa-check me-1"></i>एक मात्र स्थान — स्वतः छानियो</div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label">Desk</label>
          <select name="desk_id" class="form-select">
            <option value="0">— Default —</option>
            <?php foreach ($desks as $d): ?><option value="<?php echo (int)$d['id']; ?>" <?php echo $deskId===(int)$d['id']?'selected':''; ?>><?php echo htmlspecialchars($d['desk_label']); ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>

      <div id="deskInlineMsg" class="desk-inline-warn mb-3 d-none"></div>

      <div id="deskPreview" class="desk-preview text-center mb-3 d-none">
        <img id="deskPhoto" class="desk-photo mb-2" src="" alt="">
        <div id="deskName" class="fs-5 fw-bold"></div>
        <div id="deskMeta" class="text-muted small"></div>
        <div id="deskDupInfo" class="small text-warning mt-2 d-none"></div>
      </div>

      <label class="form-label desk-member-id">Member ID (कार्ड / सदस्यता नं.) *</label>
      <input type="text" name="member_id_input" id="deskMemberInput" class="form-control form-control-lg desk-member-id mb-2" placeholder="कार्डमा भएको Member ID — उदा. AKS-2080-0001" autocomplete="off" autocapitalize="characters" autofocus required>
      <div class="desk-kbd mb-3">Member ID टाइप गर्नुहोस् → auto lookup → Confirm · <kbd>Esc</kbd> clear</div>
      <div class="d-flex gap-2">
        <button type="button" id="deskLookupBtn" class="btn btn-outline-primary btn-lg flex-fill">Lookup</button>
        <button type="submit" id="deskConfirmBtn" class="btn btn-success btn-lg flex-fill" disabled>Confirm Attendance</button>
      </div>
    </form>

    <?php if ($programId > 0): ?>
    <hr class="my-4">
    <details><summary class="small text-muted" style="cursor:pointer">Add Desk</summary>
      <form method="POST" class="row g-2 mt-2">
        <?php echo csrfField(); ?><input type="hidden" name="action" value="save_desk"><input type="hidden" name="parent_id" value="<?php echo $programId; ?>">
        <div class="col-md-4"><input name="desk_label" class="form-control" placeholder="Desk 01" required></div>
        <div class="col-md-4"><select name="desk_occurrence_id" class="form-select"><option value="0">All occurrences</option><?php foreach ($occurrences as $o): ?><option value="<?php echo (int)$o['id']; ?>"><?php echo htmlspecialchars($o['location_name']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><button type="submit" class="btn btn-outline-secondary w-100">Add Desk</button></div>
      </form>
    </details>
    <?php endif; ?>
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
    showInline('');
  }

  function lookup(){
    var q = (input && input.value || '').trim();
    if (!q) { resetPreview(); return; }
    var pid = document.getElementById('deskProgram') ? document.getElementById('deskProgram').value : '';
    if (!pid) { showInline('पहिले कार्यक्रम छान्नुहोस्।', true); return; }
    var oid = document.getElementById('deskOccurrence') ? document.getElementById('deskOccurrence').value : '0';
    lastLookup = q;
    fetch('api/program-desk-lookup.php?member_id='+encodeURIComponent(q)+'&program_id='+pid+'&occurrence_id='+oid, {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) {
          showInline(d.error_np || d.error || 'Not found', true);
          resetPreview();
          return;
        }
        preview.classList.remove('d-none');
        document.getElementById('deskName').textContent = d.member.name + ' (' + d.member.member_id + ')';
        document.getElementById('deskMeta').textContent = [d.member.phone, d.member.address].filter(Boolean).join(' · ');
        var img = document.getElementById('deskPhoto');
        if (d.member.photo_url) { img.src = d.member.photo_url; img.classList.remove('d-none'); } else { img.classList.add('d-none'); }

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
          var dupTxt = 'पहिले नै दर्ता' + (ex.location ? ' — ' + ex.location : '') + (ex.attended_at ? ' (' + ex.attended_at + ')' : '');
          showInline(dupTxt, false);
          if (dupInfo) { dupInfo.textContent = dupTxt + (ex.method ? ' · ' + ex.method : ''); dupInfo.classList.remove('d-none'); }
          confirmBtn.disabled = true;
          lookupReady = false;
        } else {
          showInline('✓ दर्ता गर्न तयार — Enter थिच्नुहोस् वा Confirm', false);
          confirmBtn.disabled = false;
          lookupReady = true;
        }
      })
      .catch(function(){ showInline('Lookup असफल।', true); resetPreview(); });
  }

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
          form.submit();
        } else {
          lookup();
        }
      }
    });
  }
  var occSel = document.getElementById('deskOccurrence');
  if (occSel) occSel.addEventListener('change', function(){ if ((input.value||'').trim()) lookup(); });

  <?php if ($saved || $duplicate): ?>
  setTimeout(function(){
    if (input) { input.value = ''; input.focus(); }
    resetPreview();
  }, 1200);
  <?php endif; ?>
})();
</script>
</body>
</html>
