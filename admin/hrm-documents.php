<?php
/**
 * 🪪 HRM — सबै कर्मचारीका कागजात (vault view + expiry alerts)
 */
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/includes/hrm-tables.php';
require_role('admin');

$db = getDB();
ensureHrmTables($db);

$type = $_GET['type'] ?? '';
$expFilter = $_GET['exp'] ?? ''; // expiring | expired
$where = ['1=1']; $args = [];
if ($type !== '')      { $where[] = "d.doc_type=?"; $args[] = $type; }
if ($expFilter==='expiring') { $where[] = "d.expiry_date_ad BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)"; }
if ($expFilter==='expired')  { $where[] = "d.expiry_date_ad IS NOT NULL AND d.expiry_date_ad < CURDATE()"; }

$stmt = $db->prepare("SELECT d.*, e.full_name_np, e.employee_code
                      FROM hrm_employee_documents d
                      JOIN hrm_employees e ON e.id=d.employee_id
                      WHERE ".implode(' AND ',$where)."
                      ORDER BY d.expiry_date_ad IS NULL, d.expiry_date_ad ASC, d.id DESC");
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="admin-content">
  <div class="page-header stf-page-head">
    <div>
      <h1 class="stf-title">🪪 कागजात भण्डार</h1>
      <p class="stf-subtitle">सबै कर्मचारीका सम्पूर्ण कागजात</p>
    </div>
  </div>

  <form class="card-coop p-3 mb-3 d-flex flex-wrap gap-2 align-items-end">
    <div>
      <label class="small text-muted">प्रकार</label>
      <select class="field-coop" name="type">
        <option value="">— सबै —</option>
        <?php foreach (['citizenship'=>'नागरिकता','pan'=>'PAN','license'=>'सवारी','passport'=>'राहदानी','certificate'=>'प्रमाण-पत्र','training'=>'तालिम','medical'=>'मेडिकल','other'=>'अन्य'] as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $type===$k?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="small text-muted">म्याद</label>
      <select class="field-coop" name="exp">
        <option value="">— सबै —</option>
        <option value="expiring" <?= $expFilter==='expiring'?'selected':'' ?>>चाँडै सकिने (90 दिन)</option>
        <option value="expired"  <?= $expFilter==='expired'?'selected':'' ?>>सकिएका</option>
      </select>
    </div>
    <button class="btn-coop"><i class="fas fa-filter"></i> फिल्टर</button>
  </form>

  <div class="card-coop">
    <table class="table table-hover mb-0">
      <thead><tr><th>कर्मचारी</th><th>शीर्षक</th><th>प्रकार</th><th>नं.</th><th>जारी</th><th>म्याद</th><th>फाइल</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">कुनै कागजात फेला परेन।</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $exp = $r['expiry_date_ad']; $soon = $exp && strtotime($exp) <= strtotime('+90 days'); $expired = $exp && strtotime($exp) < strtotime('today'); ?>
        <tr>
          <td><a href="hrm-employee-view.php?id=<?= (int)$r['employee_id'] ?>#documents"><?= e($r['full_name_np']) ?></a><br><small class="text-muted"><?= e($r['employee_code']) ?></small></td>
          <td><strong><?= e($r['title']) ?></strong></td>
          <td><small><?= e($r['doc_type']) ?></small></td>
          <td><code><?= e($r['doc_number']) ?></code></td>
          <td><small><?= e($r['issued_date_ad'] ?: $r['issued_date_bs']) ?></small></td>
          <td>
            <?php if (!$exp): ?><small class="text-muted">—</small>
            <?php elseif ($expired): ?><span class="badge bg-danger"><?= e($exp) ?></span>
            <?php elseif ($soon): ?><span class="badge bg-warning text-dark"><?= e($exp) ?></span>
            <?php else: ?><span class="badge bg-light text-dark"><?= e($exp) ?></span>
            <?php endif; ?>
          </td>
          <td><?php if ($r['file_path']): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="../<?= e($r['file_path']) ?>"><i class="fas fa-file"></i></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
