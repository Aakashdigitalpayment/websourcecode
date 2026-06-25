<?php
/**
 * 📒 HRM — Employee Directory + Send Message
 * v11.1 — search list with contact / email / post / join-date + inline messaging.
 */
$currentPage = 'hrm-employee-directory';
$pageTitle   = 'कर्मचारी डाइरेक्टरी';
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/includes/hrm-tables.php';
require_once __DIR__ . '/includes/hrm-messages-table.php';
require_role('admin');

$db = getDB();
ensureHrmTables($db);
ensureHrmMessagesTable($db);

$me   = (int)($_SESSION['admin_id'] ?? 0);
$flash = '';

/* ── POST: send message ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_message') {
    $rid = (int)($_POST['receiver_employee_id'] ?? 0);
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    if ($rid > 0 && $body !== '') {
        $st = $db->prepare("INSERT INTO hrm_internal_messages
            (sender_admin_id, receiver_employee_id, subject, body, created_at)
            VALUES (?, ?, ?, ?, NOW())");
        $st->execute([$me ?: null, $rid, $subject !== '' ? $subject : null, $body]);
        $flash = 'सन्देश पठाइयो ✓';
    } else {
        $flash = 'सन्देश खाली हुन सक्दैन';
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$dept = (int)($_GET['dept'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));

$where = ['1=1'];
$params = [];
if ($q !== '') {
    $where[] = '(e.full_name_np LIKE ? OR e.full_name_en LIKE ? OR e.email LIKE ? OR e.mobile LIKE ? OR e.employee_code LIKE ? OR e.designation LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($dept > 0) { $where[] = 'e.department_id = ?'; $params[] = $dept; }
if ($status !== '') { $where[] = 'e.status = ?'; $params[] = $status; }

$sql = "SELECT e.id, e.employee_code, e.full_name_np, e.full_name_en, e.photo,
               e.designation, e.mobile, e.alt_mobile, e.email,
               e.join_date_bs, e.join_date_ad, e.status,
               d.name_np AS dept_name
        FROM hrm_employees e
        LEFT JOIN hrm_departments d ON d.id = e.department_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY e.full_name_np ASC LIMIT 500";
$st = $db->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$departments = hrmListDepartments($db);
?>
<div class="container-fluid p-3 p-md-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h2 class="h4 mb-0"><i class="fas fa-address-book text-primary me-2"></i>कर्मचारी डाइरेक्टरी</h2>
    <a href="hrm-employees.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-list me-1"></i> सबै कर्मचारी</a>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success py-2 small"><?= htmlspecialchars($flash) ?></div>
  <?php endif; ?>

  <form method="get" class="card p-3 mb-3 shadow-sm border-0" style="background:var(--card-bg,#fff);">
    <div class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label small text-muted mb-1">खोज</label>
        <div class="input-group">
          <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
          <input type="search" class="form-control" name="q" value="<?= htmlspecialchars($q) ?>"
                 placeholder="नाम / पद / मोबाइल / इमेल / कोड">
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label small text-muted mb-1">विभाग</label>
        <select class="form-select" name="dept">
          <option value="">— सबै विभाग —</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= $dept===(int)$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name_np']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small text-muted mb-1">स्थिति</label>
        <select class="form-select" name="status">
          <option value="">— सबै —</option>
          <?php foreach (['active'=>'सक्रिय','probation'=>'परीक्षणकाल','on_leave'=>'बिदामा','suspended'=>'निलम्बित','resigned'=>'राजीनामा'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-primary"><i class="fas fa-filter me-1"></i> लागू</button>
      </div>
    </div>
  </form>

  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>कर्मचारी</th>
            <th>पद / विभाग</th>
            <th>सम्पर्क</th>
            <th>इमेल</th>
            <th>नियुक्ति मिति</th>
            <th>स्थिति</th>
            <th class="text-end">कार्य</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">कुनै कर्मचारी फेला परेन</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td>
              <div class="d-flex align-items-center gap-2">
                <img src="<?= htmlspecialchars(hrmEmployeePhotoUrl($r['photo'])) ?>" alt=""
                     class="rounded-circle" style="width:40px;height:40px;object-fit:cover;border:1px solid #e6e9ef;">
                <div>
                  <div class="fw-semibold"><?= htmlspecialchars($r['full_name_np'] ?: $r['full_name_en']) ?></div>
                  <div class="small text-muted"><?= htmlspecialchars($r['employee_code']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <div class="fw-semibold small"><?= htmlspecialchars($r['designation'] ?? '—') ?></div>
              <div class="small text-muted"><?= htmlspecialchars($r['dept_name'] ?? '—') ?></div>
            </td>
            <td class="small">
              <?php if (!empty($r['mobile'])): ?>
                <a href="tel:<?= htmlspecialchars($r['mobile']) ?>" class="text-decoration-none">
                  <i class="fas fa-phone text-success me-1"></i><?= htmlspecialchars($r['mobile']) ?>
                </a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="small">
              <?php if (!empty($r['email'])): ?>
                <a href="mailto:<?= htmlspecialchars($r['email']) ?>" class="text-decoration-none">
                  <i class="fas fa-envelope text-primary me-1"></i><?= htmlspecialchars($r['email']) ?>
                </a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="small"><?= htmlspecialchars($r['join_date_bs'] ?: ($r['join_date_ad'] ?? '—')) ?></td>
            <td><?= hrmStatusBadge($r['status']) ?></td>
            <td class="text-end text-nowrap">
              <a href="hrm-employee-view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary" title="विवरण"><i class="fas fa-eye"></i></a>
              <a href="hrm-employee-id-card.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-info" title="ID Card"><i class="fas fa-id-card"></i></a>
              <button type="button" class="btn btn-sm btn-primary"
                      data-bs-toggle="modal" data-bs-target="#msgModal"
                      data-id="<?= (int)$r['id'] ?>"
                      data-name="<?= htmlspecialchars($r['full_name_np'] ?: $r['full_name_en'], ENT_QUOTES) ?>"
                      title="सन्देश पठाउनुहोस्">
                <i class="fas fa-paper-plane"></i>
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Send Message Modal -->
<div class="modal fade" id="msgModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="send_message">
      <input type="hidden" name="receiver_employee_id" id="msg_rid">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-paper-plane text-primary me-2"></i> सन्देश पठाउनुहोस्</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">प्राप्तकर्ता: <strong id="msg_name">—</strong></p>
        <div class="mb-3">
          <label class="form-label small">विषय</label>
          <input type="text" name="subject" class="form-control" maxlength="200" placeholder="वैकल्पिक">
        </div>
        <div class="mb-2">
          <label class="form-label small">सन्देश *</label>
          <textarea name="body" class="form-control" rows="5" required placeholder="यहाँ सन्देश लेख्नुहोस् ..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">रद्द</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i> पठाउनुहोस्</button>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('msgModal')?.addEventListener('show.bs.modal', function(e){
  const btn = e.relatedTarget;
  this.querySelector('#msg_rid').value = btn?.dataset?.id || '';
  this.querySelector('#msg_name').textContent = btn?.dataset?.name || '—';
});
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php';
