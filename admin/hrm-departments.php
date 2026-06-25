<?php
/**
 * 🏢 HRM — विभाग व्यवस्थापन
 */
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/includes/hrm-tables.php';
require_role('admin');

$db = getDB();
ensureHrmTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $vals = [trim($_POST['name_np'] ?? ''), trim($_POST['name_en'] ?? '') ?: null, trim($_POST['code'] ?? '') ?: null, (int)($_POST['sort_order'] ?? 0), !empty($_POST['is_active']) ? 1 : 0];
        if ($id > 0) { $vals[] = $id; $db->prepare("UPDATE hrm_departments SET name_np=?,name_en=?,code=?,sort_order=?,is_active=? WHERE id=?")->execute($vals); }
        else         { $db->prepare("INSERT INTO hrm_departments (name_np,name_en,code,sort_order,is_active) VALUES (?,?,?,?,?)")->execute($vals); }
        setFlash('success','सुरक्षित भयो।');
    }
    if ($a === 'delete') { $db->prepare("DELETE FROM hrm_departments WHERE id=?")->execute([(int)$_POST['id']]); setFlash('success','हटाइयो।'); }
    header('Location: hrm-departments.php'); exit;
}

$rows = $db->query("SELECT * FROM hrm_departments ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="admin-content">
  <div class="page-header stf-page-head">
    <div><h1 class="stf-title">🏢 विभाग व्यवस्थापन</h1><p class="stf-subtitle">HRM विभाग मास्टर</p></div>
    <button class="btn-coop" onclick="document.getElementById('dForm').reset(); document.getElementById('d_id').value=0; document.getElementById('dModal').style.display='flex'"><i class="fas fa-plus"></i> नयाँ विभाग</button>
  </div>
  <?php if ($f = getFlash()): ?><div class="alert alert-<?= $f['type']==='error'?'danger':'success' ?>"><?= e($f['message']) ?></div><?php endif; ?>
  <div class="card-coop">
    <table class="table table-hover mb-0">
      <thead><tr><th>क्रम</th><th>नाम (नेपाली)</th><th>Name (Eng)</th><th>कोड</th><th>अवस्था</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['sort_order'] ?></td>
          <td><strong><?= e($r['name_np']) ?></strong></td>
          <td><?= e($r['name_en']) ?></td>
          <td><code><?= e($r['code']) ?></code></td>
          <td><?= $r['is_active']?'<span class="badge bg-success">सक्रिय</span>':'<span class="badge bg-secondary">निष्क्रिय</span>' ?></td>
          <td class="text-end">
            <button class="btn btn-sm btn-outline-secondary" onclick='editD(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-pen"></i></button>
            <form method="post" class="stf-inline-form" onsubmit="return confirm('हटाउने?');"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<div id="dModal" class="stf-modal-backdrop">
  <div class="card-coop stf-modal-card">
    <h3 class="stf-section-title">विभाग</h3>
    <form id="dForm" method="post">
      <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="d_id" value="0">
      <input class="field-coop mb-2" name="name_np" id="d_np" placeholder="नाम (नेपाली) *" required>
      <input class="field-coop mb-2" name="name_en" id="d_en" placeholder="Name (English)">
      <div class="d-flex gap-2 mb-2">
        <input class="field-coop" name="code" id="d_code" placeholder="कोड">
        <input class="field-coop" type="number" name="sort_order" id="d_sort" placeholder="क्रम" value="0" style="max-width:100px;">
      </div>
      <label><input type="checkbox" name="is_active" id="d_active" checked> सक्रिय</label>
      <div class="stf-actions-row mt-2">
        <button type="button" class="btn-coop btn-outline" onclick="document.getElementById('dModal').style.display='none'">रद्द</button>
        <button type="submit" class="btn-coop">सुरक्षित</button>
      </div>
    </form>
  </div>
</div>
<script>
function editD(r){
  document.getElementById('d_id').value=r.id;
  document.getElementById('d_np').value=r.name_np||'';
  document.getElementById('d_en').value=r.name_en||'';
  document.getElementById('d_code').value=r.code||'';
  document.getElementById('d_sort').value=r.sort_order||0;
  document.getElementById('d_active').checked = r.is_active==1;
  document.getElementById('dModal').style.display='flex';
}
</script>
<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
