<?php
$pageTitle = 'स्थान / सत्र';
$currentPage = 'program-occurrences';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/program-tables.php';
require_once __DIR__ . '/../includes/program-attendance-helpers.php';

$db = getDB();
ensureProgramTables($db);

$parentId = (int)($_GET['parent_id'] ?? ($_POST['parent_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $parentId = (int)($_POST['parent_id'] ?? 0);
            $loc = trim((string)($_POST['location_name'] ?? ''));
            $date = trim((string)($_POST['event_date'] ?? '')) ?: null;
            $start = trim((string)($_POST['start_time'] ?? ''));
            $end = trim((string)($_POST['end_time'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $attOpenBs = trim((string)($_POST['attendance_open_bs'] ?? ''));
            $attOpenTime = trim((string)($_POST['attendance_open_time'] ?? ''));
            $attCloseBs = trim((string)($_POST['attendance_close_bs'] ?? ''));
            $attCloseTime = trim((string)($_POST['attendance_close_time'] ?? ''));
            if ($parentId <= 0) throw new Exception('Parent कार्यक्रम छान्नुहोस्।');
            if ($loc === '') throw new Exception('स्थान नाम आवश्यक छ।');
            $pg = programFetchById($db, $parentId);
            if (!$pg || (int)($pg['is_multi_location'] ?? 0) !== 1) {
                throw new Exception('यो कार्यक्रम multi-location होइन। पहिले programs.php मा Multi-location सक्षम गर्नुहोस्।');
            }
            $attOpen = $attOpenBs !== '' ? programCombineBsDateTime($attOpenBs, $attOpenTime !== '' ? $attOpenTime : '00:00') : null;
            $attClose = $attCloseBs !== '' ? programCombineBsDateTime($attCloseBs, $attCloseTime !== '' ? $attCloseTime : '23:59') : null;
            if ($id > 0) {
                $db->prepare('UPDATE program_occurrences SET location_name=?, event_date=?, start_time=?, end_time=?, attendance_open_at=?, attendance_close_at=?, sort_order=?, is_active=? WHERE id=? AND parent_program_id=?')
                    ->execute([$loc, $date, $start, $end, $attOpen, $attClose, $sort, $active, $id, $parentId]);
                setFlash('success', 'Occurrence अपडेट भयो।');
            } else {
                $db->prepare('INSERT INTO program_occurrences (parent_program_id, location_name, event_date, start_time, end_time, attendance_open_at, attendance_close_at, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$parentId, $loc, $date, $start, $end, $attOpen, $attClose, $sort, $active]);
                setFlash('success', 'नयाँ occurrence थपियो।');
            }
        } elseif ($action === 'gen_qr') {
            $id = (int)($_POST['id'] ?? 0);
            $parentId = (int)($_POST['parent_id'] ?? 0);
            if ($id <= 0) throw new Exception('Occurrence छान्नुहोस्।');
            $token = bin2hex(random_bytes(16));
            $db->prepare('UPDATE program_occurrences SET qr_token=?, qr_enabled=1, qr_starts_at=COALESCE(qr_starts_at, NOW()) WHERE id=? AND parent_program_id=?')
                ->execute([$token, $id, $parentId]);
            setFlash('success', 'Occurrence QR तयार भयो।');
        } elseif ($action === 'clear_qr') {
            $id = (int)($_POST['id'] ?? 0);
            $parentId = (int)($_POST['parent_id'] ?? 0);
            $db->prepare('UPDATE program_occurrences SET qr_token=NULL, qr_enabled=0 WHERE id=? AND parent_program_id=?')->execute([$id, $parentId]);
            setFlash('success', 'QR हटाइयो।');
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $parentId = (int)($_POST['parent_id'] ?? 0);
            $db->prepare('DELETE FROM program_occurrences WHERE id=? AND parent_program_id=?')->execute([$id, $parentId]);
            setFlash('success', 'Occurrence हटाइयो।');
        }
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
    }
    redirect('program-occurrences.php' . ($parentId > 0 ? ('?parent_id=' . $parentId) : ''));
}

$multiPrograms = $db->query("SELECT id, title, program_type FROM upcoming_programs WHERE is_active=1 AND is_multi_location=1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$parent = $parentId > 0 ? programFetchById($db, $parentId) : null;
$occurrences = [];
if ($parent && (int)($parent['is_multi_location'] ?? 0) === 1) {
    $st = $db->prepare('SELECT * FROM program_occurrences WHERE parent_program_id=? ORDER BY sort_order ASC, id ASC');
    $st->execute([$parentId]);
    $occurrences = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    foreach ($occurrences as $o) {
        if ((int)$o['id'] === $editId) { $edit = $o; break; }
    }
}
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('स्थान / सत्र (Occurrences)', 'fa-map-marker-alt', 'Multi-location AGM जस्ता कार्यक्रमका लागि Banepa, Panauti, … स्थान/मिति/QR यहाँ व्यवस्थापन गर्नुहोस्।',
      '<a href="programs.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>कार्यक्रम सूची</a>'); ?>
  <?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

  <div class="card admin-table-card mb-3">
    <div class="card-body">
      <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-8">
          <label class="form-label">Parent कार्यक्रम (Multi-location)</label>
          <select name="parent_id" class="form-select" required onchange="this.form.submit()">
            <option value="">— छान्नुहोस् —</option>
            <?php foreach ($multiPrograms as $mp): ?>
              <option value="<?php echo (int)$mp['id']; ?>" <?php echo $parentId === (int)$mp['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($mp['title']); ?> (<?php echo htmlspecialchars(programTypeLabel($mp['program_type'] ?? 'General')); ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <?php if (empty($multiPrograms)): ?>
            <p class="small text-muted mb-0">पहिले <a href="programs.php">programs.php</a> मा कार्यक्रम बनाएर <strong>Multi-location</strong> सक्षम गर्नुहोस्।</p>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <?php if ($parent): ?>
  <div class="card admin-table-card mb-3">
    <div class="card-header gradient-card-header"><h6 class="mb-0"><?php echo $edit ? 'Occurrence सम्पादन' : 'नयाँ Occurrence'; ?> — <?php echo htmlspecialchars($parent['title']); ?></h6></div>
    <div class="card-body">
      <form method="POST" class="row g-3">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="parent_id" value="<?php echo (int)$parentId; ?>">
        <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
        <div class="col-md-4"><label class="form-label">स्थान *</label><input name="location_name" class="form-control" required value="<?php echo htmlspecialchars($edit['location_name'] ?? ''); ?>"></div>
        <div class="col-md-3"><label class="form-label">मिति (वि.सं.)</label><input name="event_date" class="form-control nepali-datepicker" value="<?php echo htmlspecialchars($edit['event_date'] ?? ''); ?>"></div>
        <div class="col-md-2"><label class="form-label">सुरु</label><input name="start_time" class="form-control" placeholder="09:00" value="<?php echo htmlspecialchars($edit['start_time'] ?? ''); ?>"></div>
        <div class="col-md-2"><label class="form-label">अन्त्य</label><input name="end_time" class="form-control" placeholder="17:00" value="<?php echo htmlspecialchars($edit['end_time'] ?? ''); ?>"></div>
        <div class="col-md-1"><label class="form-label">क्रम</label><input type="number" name="sort_order" class="form-control" value="<?php echo (int)($edit['sort_order'] ?? 0); ?>"></div>
        <div class="col-md-3"><label class="form-label">Window सुरु (BS)</label><input name="attendance_open_bs" class="form-control nepali-datepicker" value="<?php echo !empty($edit['attendance_open_at']) ? programMysqlDtToBsDate($edit['attendance_open_at']) : ''; ?>"></div>
        <div class="col-md-2"><label class="form-label">समय</label><input type="time" name="attendance_open_time" class="form-control" value="<?php echo !empty($edit['attendance_open_at']) ? programMysqlDtToTime($edit['attendance_open_at']) : '00:00'; ?>"></div>
        <div class="col-md-3"><label class="form-label">Window अन्त्य (BS)</label><input name="attendance_close_bs" class="form-control nepali-datepicker" value="<?php echo !empty($edit['attendance_close_at']) ? programMysqlDtToBsDate($edit['attendance_close_at']) : ''; ?>"></div>
        <div class="col-md-2"><label class="form-label">समय</label><input type="time" name="attendance_close_time" class="form-control" value="<?php echo !empty($edit['attendance_close_at']) ? programMysqlDtToTime($edit['attendance_close_at']) : '23:59'; ?>"></div>
        <div class="col-12"><label class="form-check-label"><input type="checkbox" class="form-check-input me-1" name="is_active" value="1" <?php echo !isset($edit['is_active']) || (int)$edit['is_active'] === 1 ? 'checked' : ''; ?>>Active</label></div>
        <div class="col-12"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>सेभ</button><?php if ($edit): ?><a href="program-occurrences.php?parent_id=<?php echo (int)$parentId; ?>" class="btn btn-outline-secondary">रद्द</a><?php endif; ?></div>
      </form>
    </div>
  </div>

  <div class="card admin-table-card">
    <div class="card-header"><h6 class="mb-0">Occurrences (<?php echo count($occurrences); ?>)</h6></div>
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0">
        <thead><tr><th>स्थान</th><th>मिति</th><th>समय</th><th>QR</th><th>उपस्थित</th><th>कार्य</th></tr></thead>
        <tbody>
          <?php if (empty($occurrences)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3">अहिले occurrence छैन।</td></tr>
          <?php else: foreach ($occurrences as $o):
            $attCount = 0;
            try {
              $cst = $db->prepare("SELECT COUNT(*) FROM member_program_attendance WHERE occurrence_id=? AND attendance_status='VALID'");
              $cst->execute([(int)$o['id']]);
              $attCount = (int)$cst->fetchColumn();
            } catch (Throwable $e) {}
            $qrUrl = !empty($o['qr_token']) ? rtrim(SITE_URL,'/').'/member/attend.php?qr_token='.rawurlencode($o['qr_token']) : '';
          ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($o['location_name']); ?></strong></td>
              <td><?php echo htmlspecialchars($o['event_date'] ?: '—'); ?></td>
              <td><?php echo htmlspecialchars(trim(($o['start_time']??'').'–'.($o['end_time']??''), '–') ?: '—'); ?></td>
              <td>
                <?php if ($qrUrl): ?>
                  <code class="small"><?php echo htmlspecialchars(substr($o['qr_token'],0,8)); ?>…</code>
                  <form method="POST" class="d-inline"><?php echo csrfField(); ?><input type="hidden" name="action" value="clear_qr"><input type="hidden" name="parent_id" value="<?php echo (int)$parentId; ?>"><input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>"><button class="btn btn-sm btn-outline-danger py-0">×</button></form>
                <?php else: ?>
                  <form method="POST" class="d-inline"><?php echo csrfField(); ?><input type="hidden" name="action" value="gen_qr"><input type="hidden" name="parent_id" value="<?php echo (int)$parentId; ?>"><input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>"><button class="btn btn-sm btn-outline-primary py-0">QR</button></form>
                <?php endif; ?>
              </td>
              <td><?php echo $attCount; ?></td>
              <td>
                <a href="program-occurrences.php?parent_id=<?php echo (int)$parentId; ?>&edit=<?php echo (int)$o['id']; ?>" class="btn btn-sm btn-outline-secondary py-0"><i class="fas fa-pen"></i></a>
                <form method="POST" class="d-inline" onsubmit="return confirm('हटाउने?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="parent_id" value="<?php echo (int)$parentId; ?>"><input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>"><button class="btn btn-sm btn-outline-danger py-0"><i class="fas fa-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
