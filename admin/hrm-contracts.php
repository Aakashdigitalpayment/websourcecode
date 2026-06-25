<?php
/**
 * 📄 HRM — सबै करार पत्रहरू (Cross-employee view)
 */
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/includes/hrm-tables.php';
require_role('admin');

$db = getDB();
ensureHrmTables($db);

$f = $_GET['filter'] ?? 'all'; // all | active | expired | expiring
$where = '1';
if ($f === 'active')   $where = "c.is_active=1 AND (c.end_date_ad IS NULL OR c.end_date_ad >= CURDATE())";
if ($f === 'expired')  $where = "c.end_date_ad IS NOT NULL AND c.end_date_ad < CURDATE()";
if ($f === 'expiring') $where = "c.end_date_ad BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)";

$rows = $db->query("SELECT c.*, e.full_name_np, e.employee_code
                    FROM hrm_employee_contracts c
                    JOIN hrm_employees e ON e.id = c.employee_id
                    WHERE $where
                    ORDER BY c.end_date_ad IS NULL, c.end_date_ad ASC, c.id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="admin-content">
  <div class="page-header stf-page-head">
    <div>
      <h1 class="stf-title">📄 करार पत्रहरू</h1>
      <p class="stf-subtitle">सबै कर्मचारीका नियुक्ति/करार/नविकरण</p>
    </div>
    <div class="btn-group">
      <?php foreach (['all'=>'सबै','active'=>'सक्रिय','expiring'=>'चाँडै सकिने','expired'=>'सकिएका'] as $k=>$v): ?>
        <a class="btn btn-sm <?= $f===$k?'btn-primary':'btn-outline-secondary' ?>" href="?filter=<?= $k ?>"><?= $v ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card-coop">
    <table class="table table-hover mb-0">
      <thead><tr><th>कर्मचारी</th><th>करार नं.</th><th>प्रकार</th><th>पद</th><th>सुरु</th><th>अन्त्य</th><th>तलब</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">कुनै रेकर्ड छैन।</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $exp = $r['end_date_ad']; $soon = $exp && strtotime($exp) <= strtotime('+60 days'); $expired = $exp && strtotime($exp) < strtotime('today'); ?>
        <tr>
          <td><a href="hrm-employee-view.php?id=<?= (int)$r['employee_id'] ?>#contracts"><?= e($r['full_name_np']) ?></a><br><small class="text-muted"><?= e($r['employee_code']) ?></small></td>
          <td><code><?= e($r['contract_no']) ?></code></td>
          <td><small><?= e($r['contract_type']) ?></small></td>
          <td><?= e($r['designation']) ?></td>
          <td><?= e($r['start_date_ad']) ?></td>
          <td>
            <?php if (!$exp): ?><span class="badge bg-success">स्थायी</span>
            <?php elseif ($expired): ?><span class="badge bg-danger"><?= e($exp) ?></span>
            <?php elseif ($soon): ?><span class="badge bg-warning text-dark"><?= e($exp) ?></span>
            <?php else: ?><span class="badge bg-light text-dark"><?= e($exp) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-end">रू <?= number_format((float)$r['basic_salary'], 2) ?></td>
          <td class="text-end"><?php if ($r['file_path']): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="../<?= e($r['file_path']) ?>"><i class="fas fa-file-pdf"></i></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
