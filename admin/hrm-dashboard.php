<?php
/**
 * 📊 HRM Dashboard — सहकारी मानव संशाधन
 * Headcount, status mix, expiring contracts/documents, recent joiners.
 */
$currentPage = 'hrm-dashboard';
$pageTitle   = 'HRM ड्यासबोर्ड';
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/includes/hrm-tables.php';
require_role('admin');

$db = getDB();
ensureHrmTables($db);

// Guard: robust HRM table check (avoid false negative when SHOW TABLES is restricted)
$hasHrmTables = false;
try {
  $db->query("SELECT 1 FROM hrm_employees LIMIT 1");
  $hasHrmTables = true;
} catch (Throwable $e) {
  $err = (string)$e->getMessage();

  // If table truly missing, try one more ensure+probe cycle.
  if (stripos($err, '42S02') !== false || stripos($err, 'base table or view not found') !== false) {
    try {
      ensureHrmTables($db);
      $db->query("SELECT 1 FROM hrm_employees LIMIT 1");
      $hasHrmTables = true;
    } catch (Throwable $e2) {
      $hasHrmTables = false;
    }
  } else {
    // Fallback for DB users where SELECT probe fails but SHOW TABLES works.
    try {
      $exists = $db->query("SHOW TABLES LIKE 'hrm_employees'")->fetchColumn();
      $hasHrmTables = !empty($exists);
    } catch (Throwable $e3) {
      $hasHrmTables = false;
    }
  }
}

if (!$hasHrmTables) {
  echo '<div class="admin-content"><div class="alert alert-warning"><h4>HRM Module Not Installed</h4><p>The HRM tables were not detected in the current app database connection. Please run Admin &gt; Database Setup once, and verify phpMyAdmin तथा app दुवै एउटै database (DB_NAME) प्रयोग गरिरहेका छन्।</p></div></div>';
    return;
}

$total = $active = $probation = $onLeave = $exited = 0;
$expiringContracts = $expiringDocs = $recentJoiners = $byDept = [];
try {
  $total       = function_exists('sqCount') ? sqCount($db, "SELECT COUNT(*) FROM hrm_employees", '[hrm-dashboard total]') : 0;
  $active      = function_exists('sqCount') ? sqCount($db, "SELECT COUNT(*) FROM hrm_employees WHERE status='active'", '[hrm-dashboard active]') : 0;
  $probation   = function_exists('sqCount') ? sqCount($db, "SELECT COUNT(*) FROM hrm_employees WHERE status='probation'", '[hrm-dashboard probation]') : 0;
  $onLeave     = function_exists('sqCount') ? sqCount($db, "SELECT COUNT(*) FROM hrm_employees WHERE status='on_leave'", '[hrm-dashboard on-leave]') : 0;
  $exited      = function_exists('sqCount') ? sqCount($db, "SELECT COUNT(*) FROM hrm_employees WHERE status IN ('resigned','terminated','retired')", '[hrm-dashboard exited]') : 0;

    $expiringContracts = $db->query(
        "SELECT c.*, e.full_name_np, e.employee_code
           FROM hrm_employee_contracts c
           JOIN hrm_employees e ON e.id = c.employee_id
          WHERE c.is_active=1 AND c.end_date_ad IS NOT NULL
            AND c.end_date_ad BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
          ORDER BY c.end_date_ad ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    $expiringDocs = $db->query(
        "SELECT d.*, e.full_name_np, e.employee_code
           FROM hrm_employee_documents d
           JOIN hrm_employees e ON e.id = d.employee_id
          WHERE d.expiry_date_ad IS NOT NULL
            AND d.expiry_date_ad BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
          ORDER BY d.expiry_date_ad ASC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    $recentJoiners = $db->query(
        "SELECT id, employee_code, full_name_np, designation, join_date_ad, status
           FROM hrm_employees ORDER BY id DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);

    $byDept = $db->query(
        "SELECT d.name_np, COUNT(e.id) AS cnt
           FROM hrm_departments d
           LEFT JOIN hrm_employees e ON e.department_id = d.id AND e.status='active'
          GROUP BY d.id ORDER BY d.sort_order, d.id"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[hrm-dashboard] ' . $e->getMessage());
}
?>
<div class="admin-content">
    <div class="page-header stf-page-head">
        <div>
            <h1 class="stf-title">🧑‍💼 मानव संशाधन ड्यासबोर्ड</h1>
            <p class="stf-subtitle">कर्मचारी, करार र कागजातको समग्र अवस्था</p>
        </div>
        <a class="btn-coop" href="hrm-employees.php"><i class="fas fa-users"></i> कर्मचारी सूची</a>
    </div>

    <div class="alert alert-light border small py-2 mb-3">
      <i class="fas fa-info-circle text-success me-1"></i>
      <strong>छुट्याएर बुझ्नुहोस्:</strong>
      HRM = आन्तरिक कर्मचारी रेकर्ड ·
      <a href="team-karmachari.php">कर्मचारी / व्यवस्थापन</a> = website मा देखिने टोली (HRM फारममा नाम select गरेर fill गर्न मिल्छ) ·
      <a href="manage-admins.php">Manage Admins</a> = panel login user ·
      <a href="service-centers.php">शाखाहरू</a> = सेवा केन्द्र (शाखा dropdown को स्रोत) ·
      <a href="designations.php">पद मास्टर</a> = पदको नाम ·
      <a href="hrm-employees.php">नयाँ कर्मचारी</a> मा टोलीबाट छानेर वा टाइप गरेर थप्न सकिन्छ।

    <?php
    $statCards = [
      ['icon'=>'fa-users',        'label'=>'कुल कर्मचारी', 'value'=>$total,     'color'=>'primary',   'link'=>'hrm-employees.php'],
      ['icon'=>'fa-circle-check', 'label'=>'सक्रिय',        'value'=>$active,    'color'=>'success',   'link'=>'hrm-employees.php?status=active'],
      ['icon'=>'fa-flask',        'label'=>'परीक्षणकाल',    'value'=>$probation, 'color'=>'info',      'link'=>'hrm-employees.php?status=probation'],
      ['icon'=>'fa-umbrella',     'label'=>'बिदामा',         'value'=>$onLeave,   'color'=>'warning',   'link'=>'hrm-employees.php?status=on_leave'],
      ['icon'=>'fa-right-from-bracket','label'=>'छोडेका',   'value'=>$exited,    'color'=>'secondary', 'link'=>'hrm-employees.php?status=resigned'],
    ];
    $statColClass = 'col-6 col-sm-4 col-md-2';
    include __DIR__ . '/../includes/components/stat-card.php';
    ?>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card-coop p-3 h-100">
          <h3 class="stf-section-title">🏢 विभाग अनुसार सक्रिय कर्मचारी</h3>
          <table class="table table-sm mb-0">
            <thead><tr><th>विभाग</th><th class="text-end">सक्रिय</th></tr></thead>
            <tbody>
            <?php foreach ($byDept as $d): ?>
              <tr><td><?= e($d['name_np']) ?></td><td class="text-end"><strong><?= (int)$d['cnt'] ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="card-coop p-3 h-100">
          <h3 class="stf-section-title">🆕 हालसालै नियुक्त</h3>
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th>कोड</th><th>नाम</th><th>पद</th><th>नियुक्ति</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recentJoiners as $r): ?>
              <tr>
                <td><code><?= e($r['employee_code']) ?></code></td>
                <td><?= e($r['full_name_np']) ?></td>
                <td><small><?= e($r['designation']) ?></small></td>
                <td><small><?= e($r['join_date_ad'] ?? '—') ?></small></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="hrm-employee-view.php?id=<?= (int)$r['id'] ?>"><i class="fas fa-eye"></i></a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card-coop p-3 h-100">
          <h3 class="stf-section-title">📄 म्याद सकिँदै गरेका करार (६० दिन भित्र)</h3>
          <?php if (!$expiringContracts): ?>
            <p class="text-muted mb-0">कुनै पनि करार चाँडै सकिने देखिएन।</p>
          <?php else: ?>
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th>कर्मचारी</th><th>प्रकार</th><th>म्याद सम्म</th></tr></thead>
            <tbody>
            <?php foreach ($expiringContracts as $c): ?>
              <tr>
                <td><a href="hrm-employee-view.php?id=<?= (int)$c['employee_id'] ?>#contracts"><?= e($c['full_name_np']) ?></a><br><small class="text-muted"><?= e($c['employee_code']) ?></small></td>
                <td><small><?= e($c['contract_type']) ?></small></td>
                <td><span class="badge bg-warning text-dark"><?= e($c['end_date_ad']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card-coop p-3 h-100">
          <h3 class="stf-section-title">🪪 म्याद सकिँदै गरेका कागजात (९० दिन भित्र)</h3>
          <?php if (!$expiringDocs): ?>
            <p class="text-muted mb-0">सबै कागजात अद्यावधिक देखिन्छन्।</p>
          <?php else: ?>
          <table class="table table-sm table-hover mb-0">
            <thead><tr><th>कर्मचारी</th><th>कागजात</th><th>म्याद</th></tr></thead>
            <tbody>
            <?php foreach ($expiringDocs as $d): ?>
              <tr>
                <td><a href="hrm-employee-view.php?id=<?= (int)$d['employee_id'] ?>#documents"><?= e($d['full_name_np']) ?></a></td>
                <td><small><?= e($d['title']) ?> <span class="text-muted">(<?= e($d['doc_type']) ?>)</span></small></td>
                <td><span class="badge bg-warning text-dark"><?= e($d['expiry_date_ad']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
