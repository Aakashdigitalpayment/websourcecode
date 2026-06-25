<?php
$pageTitle = 'कार्यक्रम व्यवस्थापन';
$currentPage = 'programs';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();
require_once __DIR__ . '/../includes/program-tables.php';
ensureProgramTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $date = trim((string)($_POST['event_date'] ?? '')) ?: null;
            $time = trim((string)($_POST['event_time'] ?? ''));
            $loc  = trim((string)($_POST['location'] ?? ''));
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $preRegOpen = !empty($_POST['pre_registration_open']) ? 1 : 0;
            $qrStartsAt = trim((string)($_POST['qr_starts_at'] ?? '')) ?: null;
            $qrExpiresAt = trim((string)($_POST['qr_expires_at'] ?? '')) ?: null;
            if ($title === '') throw new Exception('कार्यक्रम शीर्षक आवश्यक छ।');
            if ($id > 0) {
                $st = $db->prepare("UPDATE upcoming_programs SET title=?, description=?, event_date=?, event_time=?, location=?, is_active=?, pre_registration_open=?, qr_starts_at=?, qr_expires_at=? WHERE id=?");
                $st->execute([$title, $desc, $date, $time, $loc, $active, $preRegOpen, $qrStartsAt, $qrExpiresAt, $id]);
                setFlash('success', 'कार्यक्रम अपडेट भयो।');
            } else {
                $st = $db->prepare("INSERT INTO upcoming_programs (title, description, event_date, event_time, location, is_active, pre_registration_open, qr_starts_at, qr_expires_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$title, $desc, $date, $time, $loc, $active, $preRegOpen, $qrStartsAt, $qrExpiresAt, $_SESSION['admin_name'] ?? 'Admin']);
                setFlash('success', 'नयाँ कार्यक्रम थपियो।');
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE upcoming_programs SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            setFlash('success', 'कार्यक्रम स्थिति परिवर्तन भयो।');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM upcoming_programs WHERE id=?")->execute([$id]);
            setFlash('success', 'कार्यक्रम हटाइयो।');
        } elseif ($action === 'gen_qr') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('कार्यक्रम छान्नुहोस्।');
            }
            $token = bin2hex(random_bytes(16));
            $db->prepare('UPDATE upcoming_programs SET qr_token=?, qr_starts_at=COALESCE(qr_starts_at, NOW()), qr_expires_at=COALESCE(qr_expires_at, DATE_ADD(NOW(), INTERVAL 1 DAY)) WHERE id=?')->execute([$token, $id]);
            setFlash('success', 'QR लिंक तयार भयो। सदस्यले Member Portal बाट scan गर्छन्।');
        } elseif ($action === 'clear_qr') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare('UPDATE upcoming_programs SET qr_token=NULL, qr_starts_at=NULL, qr_expires_at=NULL WHERE id=?')->execute([$id]);
                setFlash('success', 'QR हटाइयो।');
            }
        }
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
    }
    redirect('programs.php');
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $st = $db->prepare("SELECT * FROM upcoming_programs WHERE id=?");
    $st->execute([$editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$rows = $db->query("SELECT * FROM upcoming_programs ORDER BY COALESCE(event_date,'9999-12-31') ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

$preregByProgram = [];
try {
    $pc = $db->query('SELECT program_id, COUNT(*) AS c FROM member_program_preregistrations GROUP BY program_id');
    if ($pc) {
        while ($row = $pc->fetch(PDO::FETCH_ASSOC)) {
            $preregByProgram[(int)$row['program_id']] = (int)$row['c'];
        }
    }
} catch (Throwable $e) {
    $preregByProgram = [];
}
$attendByProgram = [];
try {
    $ac = $db->query('SELECT program_id, COUNT(*) AS c FROM member_program_attendance GROUP BY program_id');
    if ($ac) {
        while ($row = $ac->fetch(PDO::FETCH_ASSOC)) {
            $attendByProgram[(int)$row['program_id']] = (int)$row['c'];
        }
    }
} catch (Throwable $e) {
    $attendByProgram = [];
}
$pendingByProgram = [];
try {
    $pc = $db->query("SELECT program_id, COUNT(*) AS c FROM member_program_attendance_requests WHERE status='pending' GROUP BY program_id");
    if ($pc) {
        while ($row = $pc->fetch(PDO::FETCH_ASSOC)) {
            $pendingByProgram[(int)$row['program_id']] = (int)$row['c'];
        }
    }
} catch (Throwable $e) {
    $pendingByProgram = [];
}

$rowsActive = [];
$rowsInactive = [];
foreach ($rows as $_r) {
    if ((int)($_r['is_active'] ?? 0) === 1) {
        $rowsActive[] = $_r;
    } else {
        $rowsInactive[] = $_r;
    }
}
?>

<div class="container-fluid py-3">
  <?php echo adminPageHeader('कार्यक्रम व्यवस्थापन', 'fa-calendar-check', 'Pre-registration = अगाडि नाम दर्ता। QR = स्थल उपस्थिति — सदस्य Member Portal बाट scan गर्दा attendance सूची र इतिहासमा थपिन्छ (कार्यक्रम जति, गणना उति)।',
      '<div class="d-flex gap-2 flex-wrap">'
      . '<a href="../program-attendance-verify.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-user-check me-1"></i>Attendance Verify</a>'
      . '<a href="program-attendance.php" class="btn btn-outline-success btn-sm"><i class="fas fa-file-excel me-1"></i>उपस्थिति रिपोर्ट</a>'
      . '</div>'); ?>
  <?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

  <div class="card admin-table-card mb-3">
    <div class="card-header gradient-card-header"><h6 class="mb-0"><?php echo $edit ? 'कार्यक्रम सम्पादन' : 'नयाँ कार्यक्रम थप्नुहोस्'; ?></h6></div>
    <div class="card-body">
      <form method="POST" class="row g-3">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
        <div class="col-md-6"><label class="form-label">शीर्षक *</label><input required name="title" class="form-control" value="<?php echo htmlspecialchars($edit['title'] ?? ''); ?>"></div>
        <div class="col-md-3">
          <label class="form-label">मिति (वि.सं.)</label>
          <div class="input-group">
            <input type="text" name="event_date" class="form-control nepali-datepicker" placeholder="YYYY-MM-DD" value="<?php echo htmlspecialchars($edit['event_date'] ?? ''); ?>">
            <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">समय</label>
          <?php $eventTimeValue = trim((string)($edit['event_time'] ?? '')); $eventTimeOptions = function_exists('getOfficeTimeOptions') ? getOfficeTimeOptions(30) : []; ?>
          <select name="event_time" class="form-select">
            <option value="">— समय छान्नुहोस् —</option>
            <?php foreach ($eventTimeOptions as $optVal => $optLabel): ?>
              <option value="<?php echo htmlspecialchars($optVal, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $eventTimeValue === $optVal ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
            <?php if ($eventTimeValue !== '' && !isset($eventTimeOptions[$eventTimeValue])): ?>
              <option value="<?php echo htmlspecialchars($eventTimeValue, ENT_QUOTES, 'UTF-8'); ?>" selected>
                <?php echo htmlspecialchars($eventTimeValue, ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endif; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">स्थान</label><input name="location" class="form-control" value="<?php echo htmlspecialchars($edit['location'] ?? ''); ?>"></div>
        <div class="col-md-6"><label class="form-label">विवरण</label><input name="description" class="form-control" value="<?php echo htmlspecialchars($edit['description'] ?? ''); ?>"></div>
        <div class="col-12 d-flex flex-wrap gap-3">
          <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="is_active" value="1" <?php echo !isset($edit['is_active']) || (int)$edit['is_active']===1 ? 'checked' : ''; ?>>Active</label>
          <label class="form-check-label"><input class="form-check-input me-1" type="checkbox" name="pre_registration_open" value="1" <?php echo !empty($edit['pre_registration_open']) ? 'checked' : ''; ?>>Pre-registration Open</label>
        </div>
        <div class="col-md-6"><label class="form-label">QR सुरु समय</label><input type="datetime-local" name="qr_starts_at" class="form-control" value="<?php echo htmlspecialchars(!empty($edit['qr_starts_at']) ? date('Y-m-d\TH:i', strtotime($edit['qr_starts_at'])) : ''); ?>"></div>
        <div class="col-md-6"><label class="form-label">QR समाप्त समय</label><input type="datetime-local" name="qr_expires_at" class="form-control" value="<?php echo htmlspecialchars(!empty($edit['qr_expires_at']) ? date('Y-m-d\TH:i', strtotime($edit['qr_expires_at'])) : ''); ?>"></div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary"><i class="fas fa-save me-1"></i>सेभ</button>
          <?php if ($edit): ?><a href="programs.php" class="btn btn-outline-secondary">रद्द</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <?php
  $programTableHead = '<thead><tr><th>शीर्षक</th><th>मिति</th><th>स्थान</th><th>स्थिति</th><th>Pre-reg / उपस्थिति</th><th>QR</th><th>कार्य</th></tr></thead>';
  $renderProgramRows = function (array $list) use ($preregByProgram, $attendByProgram, $pendingByProgram): void {
      foreach ($list as $r) {
          $prc = (int)($preregByProgram[(int)$r['id']] ?? 0);
          $atc = (int)($attendByProgram[(int)$r['id']] ?? 0);
          $pnc = (int)($pendingByProgram[(int)$r['id']] ?? 0);
          $prLink = 'program-attendance.php?program_id=' . (int)$r['id'];
          $memberQrUrl = rtrim(SITE_URL, '/') . '/member/attend.php?qr_token=' . rawurlencode((string)($r['qr_token'] ?? ''));
          $legacyQrUrl = rtrim(SITE_URL, '/') . '/attend.php?token=' . rawurlencode((string)($r['qr_token'] ?? ''));
          $dMember = htmlspecialchars($memberQrUrl, ENT_QUOTES, 'UTF-8');
          $dLegacy = htmlspecialchars($legacyQrUrl, ENT_QUOTES, 'UTF-8');
          $dTitle = htmlspecialchars((string)($r['title'] ?? ''), ENT_QUOTES, 'UTF-8');
          ?>
          <tr>
            <td><strong><?php echo htmlspecialchars($r['title']); ?></strong><div class="small text-muted"><?php echo htmlspecialchars($r['description'] ?? ''); ?></div></td>
            <td><?php echo htmlspecialchars($r['event_date'] ?: '—'); ?> <span class="small text-muted"><?php echo htmlspecialchars($r['event_time'] ?? ''); ?></span></td>
            <td><?php echo htmlspecialchars($r['location'] ?: '—'); ?></td>
            <td><?php echo (int)$r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">निष्क्रिय</span>'; ?></td>
            <td>
              <?php if (!empty($r['pre_registration_open'])): ?>
                <span class="badge bg-primary">Pre-reg खुला</span>
              <?php else: ?>
                <span class="badge bg-light text-muted border">Pre-reg बन्द</span>
              <?php endif; ?>
              <div class="small mt-1 lh-sm">
                <a href="<?php echo htmlspecialchars($prLink); ?>" class="fw-semibold text-decoration-none"><?php echo $prc; ?> दर्ता</a>
                <span class="text-muted">·</span>
                <a href="<?php echo htmlspecialchars($prLink); ?>" class="fw-semibold text-success text-decoration-none"><?php echo $atc; ?> उपस्थिति</a>
                <?php if ($pnc > 0): ?><span class="text-muted">·</span> <a href="<?php echo htmlspecialchars($prLink); ?>" class="fw-semibold text-warning text-decoration-none"><?php echo $pnc; ?> pending</a><?php endif; ?>
              </div>
              <a href="<?php echo htmlspecialchars($prLink); ?>" class="small text-decoration-none">रिपोर्ट →</a>
            </td>
            <td>
              <?php if (!empty($r['qr_token'])): ?>
                <div class="d-flex flex-column align-items-center gap-1 py-1">
                  <button type="button" class="btn btn-link p-0 border-0 js-program-qr-open shadow-none"
                          data-bs-toggle="modal" data-bs-target="#programQrModal"
                          data-qr-member="<?php echo $dMember; ?>" data-qr-legacy="<?php echo $dLegacy; ?>" data-qr-title="<?php echo $dTitle; ?>"
                          title="थिचेर ठूलो QR र लिंक">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?data=<?php echo rawurlencode($memberQrUrl); ?>&size=56x56&margin=1" width="56" height="56" alt="QR"
                         style="border:1px solid #e5e7eb;border-radius:6px;display:block;background:#fff;">
                  </button>
                  <span class="text-muted" style="font-size:.62rem;white-space:nowrap;">थिच्नुहोस् → ठूलो</span>
                  <code class="small text-muted user-select-all" style="font-size:.58rem;max-width:88px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($r['qr_token']); ?>"><?php echo htmlspecialchars(substr($r['qr_token'], 0, 8)) . '…'; ?></code>
                  <form method="POST" class="m-0" onsubmit="return confirm('QR हटाउने?');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="clear_qr">
                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size:.62rem;">Remove</button>
                  </form>
                </div>
              <?php else: ?>
                <form method="POST" class="m-0">
                  <?php echo csrfField(); ?>
                  <input type="hidden" name="action" value="gen_qr">
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-outline-primary btn-sm py-0 px-2" style="font-size:.72rem;"><i class="fas fa-qrcode me-1"></i>QR बनाउनुहोस्</button>
                </form>
              <?php endif; ?>
            </td>
            <td>
              <a class="btn btn-sm btn-outline-primary" href="programs.php?edit=<?php echo (int)$r['id']; ?>"><i class="fas fa-edit"></i></a>
              <form method="POST" class="d-inline"><?php echo csrfField(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-warning" title="सक्रिय/निष्क्रिय"><i class="fas fa-power-off"></i></button></form>
              <form method="POST" class="d-inline" onsubmit="return confirm('हटाउने?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button></form>
            </td>
          </tr>
          <?php
      }
  };
  ?>

  <div class="card admin-table-card mb-3">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h6 class="mb-0"><i class="fas fa-circle-play text-success me-1"></i>सक्रिय कार्यक्रम</h6>
      <span class="small text-muted"><?php echo count($rowsActive); ?> वटा — निष्क्रिय <?php echo count($rowsInactive); ?> वटा तल छुट्टै</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover table-sm align-middle mb-0">
        <?php echo $programTableHead; ?>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">अहिलेसम्म कार्यक्रम छैन।</td></tr>
          <?php elseif (empty($rowsActive)): ?>
          <tr><td colspan="7" class="text-center text-muted py-3">कुनै सक्रिय कार्यक्रम छैन। Power बटनले पुनः सक्रिय गर्नुहोस्।</td></tr>
          <?php else: ?>
          <?php $renderProgramRows($rowsActive); ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (!empty($rowsInactive)): ?>
  <div class="card admin-table-card border-secondary">
    <div class="card-header bg-light d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h6 class="mb-0 text-secondary"><i class="fas fa-archive me-1"></i>समाप्त / निष्क्रिय कार्यक्रम</h6>
      <span class="small text-muted">पुराना कार्यक्रम यहीँ राख्नुहोस् — सूची छोटो रहन्छ</span>
    </div>
    <div class="table-responsive">
      <table class="table table-hover table-sm align-middle mb-0">
        <?php echo $programTableHead; ?>
        <tbody>
          <?php $renderProgramRows($rowsInactive); ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- QR — ठूलो देखाउने + लिंक कपि (Member Portal प्रवाह) -->
<div class="modal fade" id="programQrModal" tabindex="-1" aria-labelledby="programQrModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg" style="max-width:min(96vw, 620px);">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="programQrModalLabel"><i class="fas fa-qrcode me-2"></i><span id="programQrModalTitleText">कार्यक्रम QR</span></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center px-2 px-md-3">
        <p class="small text-muted text-start mb-2">
          <strong>QR = कार्यक्रम स्थल उपस्थिति दर्ता</strong> (pre-registration भन्दा फरक)। सदस्य <strong>कार्यक्रममा उपस्थित भइसकेपछि</strong> आफ्नो मोबाइलबाट Member Portal खोली यो QR scan गर्छन् (लगिन आवश्यक)। एक ट्यापमा check-in भएपछि:
          <br>• <strong>Admin</strong> → उपस्थिति रिपोर्ट / सूचीमा सदस्य विवरणसहित देखिन्छ
          <br>• <strong>सदस्य पोर्टल</strong> → उपस्थिति इतिहासमा थपिन्छ — <strong>जति कार्यक्रममा आउनुहुन्छ, त्यति नै गणना बढ्दै जान्छ</strong>
          <br><br>
          <strong>Pre-registration</strong> भनेको “कार्यक्रममा आउँछु” भनी अगाडि दर्ता गर्ने छुट्टै प्रक्रिया हो; त्यसले उपस्थिति गणना बढाउँदैन। उपस्थिति गणना QR / आजको मितिमा check-in बटन / Staff verify बाट मात्र।
          <br><br>
          <span class="text-secondary">थप कडा प्रमाण चाहिएमा Staff <strong>Attendance Verify</strong> (कार्ड + CVV) पनि प्रयोग गर्न सकिन्छ।</span>
        </p>
        <div class="p-2 p-md-3 bg-light rounded-3 d-inline-block mb-3 shadow-sm">
          <img id="programQrModalImg" src="" alt="QR" width="480" height="480" class="img-fluid mx-auto d-block" style="max-width:min(92vw, 480px);width:100%;height:auto;border:1px solid #dee2e6;border-radius:12px;background:#fff;">
        </div>
        <div class="mb-2">
          <label class="form-label small text-muted mb-0">Member Portal URL (मुख्य)</label>
          <div class="input-group">
            <input type="text" class="form-control font-monospace small" id="programQrModalUrlInput" readonly>
            <button class="btn btn-outline-primary" type="button" id="programQrModalCopyBtn" aria-label="Copy" title="Copy"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
          </div>
        </div>
        <details class="text-start small">
          <summary class="text-muted" style="cursor:pointer;">पुरानो / पब्लिक लिंक (लगिन बिना म्यानुअल कार्ड)</summary>
          <div class="input-group mt-1">
            <input type="text" class="form-control font-monospace small" id="programQrModalLegacyInput" readonly>
            <button class="btn btn-outline-secondary" type="button" id="programQrModalLegacyCopyBtn" aria-label="Copy" title="Copy"><i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i></button>
          </div>
        </details>
      </div>
    </div>
  </div>
</div>
<script>
var PROGRAM_QR_PIXELS = 480;
function programQrModalSet(memberUrl, legacyUrl, title) {
  var img = document.getElementById('programQrModalImg');
  var tEl = document.getElementById('programQrModalTitleText');
  var inp = document.getElementById('programQrModalUrlInput');
  var leg = document.getElementById('programQrModalLegacyInput');
  if (tEl) tEl.textContent = title || 'कार्यक्रम';
  if (inp) inp.value = memberUrl || '';
  if (leg) leg.value = legacyUrl || '';
  if (img) {
    if (memberUrl) {
      var px = PROGRAM_QR_PIXELS;
      img.width = px;
      img.height = px;
      img.alt = 'QR';
      img.onerror = function () {
        img.onerror = null;
        img.src = 'https://chart.googleapis.com/chart?chs=' + px + 'x' + px + '&cht=qr&chld=M|2&chl=' + encodeURIComponent(memberUrl);
      };
      img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=' + px + 'x' + px + '&margin=8&data=' + encodeURIComponent(memberUrl);
    } else {
      img.removeAttribute('src');
    }
  }
}
(function () {
  function copyEl(el) {
    if (!el || !el.value) return;
    el.select();
    el.setSelectionRange(0, 99999);
    try { navigator.clipboard.writeText(el.value); } catch (e) {}
  }
  document.querySelectorAll('.js-program-qr-open').forEach(function (btn) {
    btn.addEventListener('click', function () {
      programQrModalSet(
        this.getAttribute('data-qr-member') || '',
        this.getAttribute('data-qr-legacy') || '',
        this.getAttribute('data-qr-title') || ''
      );
    });
  });
  var b = document.getElementById('programQrModalCopyBtn');
  var bl = document.getElementById('programQrModalLegacyCopyBtn');
  if (b) b.addEventListener('click', function () { copyEl(document.getElementById('programQrModalUrlInput')); });
  if (bl) bl.addEventListener('click', function () { copyEl(document.getElementById('programQrModalLegacyInput')); });
})();
</script>

<?php require_once 'includes/admin-footer.php'; ?>
