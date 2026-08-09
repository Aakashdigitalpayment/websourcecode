<?php
/**
 * KYM Application List + Generate Member action
 * v10.0 — Refactored with new design system
 */
require_once __DIR__ . '/../_bootstrap.php';
requireAdminLogin();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Fetch KYC applications
$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all', 'pending', 'approved', 'rejected'], true)) {
    $status = 'all';
}
$q = trim((string)($_GET['q'] ?? ''));
$whereParts = [];
if ($status !== 'all') {
    $whereParts[] = 'status = ' . $pdo->quote($status);
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $ql = $pdo->quote($like);
    $qCols = ['full_name', 'full_name_en', 'mobile', 'email', 'citizenship_no', 'member_id'];
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM kyc_applications LIKE 'tracking_id'");
        if ($colCheck && $colCheck->fetch()) {
            $qCols[] = 'tracking_id';
        }
    } catch (Throwable $e) { /* ignore */ }
    $or = [];
    foreach ($qCols as $col) {
        $or[] = $col . ' LIKE ' . $ql;
    }
    $whereParts[] = '(' . implode(' OR ', $or) . ')';
}
$where = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';
$rows = $pdo->query("SELECT * FROM kyc_applications {$where} ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

$counts = $pdo->query("SELECT
    COUNT(*) total,
    SUM(status='pending')  pending,
    SUM(status='approved') approved,
    SUM(status='rejected') rejected,
    SUM(member_id_generated IS NOT NULL) generated
  FROM kyc_applications")->fetch(PDO::FETCH_ASSOC);

$page_title = 'केवाइएम आवेदन व्यवस्थापन';
include __DIR__ . '/../_partials/header.php';
?>

<div class="admin-page-header">
  <h1 class="admin-page-title"><i class="fas fa-id-card-clip"></i> केवाइएम आवेदन</h1>
  <a href="/admin/" class="admin-btn admin-btn-ghost"><i class="fas fa-arrow-left"></i> ड्यासबोर्डमा फर्क</a>
</div>

<div class="admin-card" style="margin-bottom:12px;border-left:3px solid var(--primary,#1a5f2a);">
  <p style="margin:0 0 8px;font-size:.9rem;">
    <strong>पूर्ण केवाइएम व्यवस्थापन</strong> मुख्य Admin मा छ (फिल्टर, विवरण, approve/reject)।
    यो पृष्ठ छोटो सूची हो। Member ID SSOT: approve गर्दा सदस्य खाता लिंक/stub बन्छ — पुरानो BBWW Generate बाटो हटाइएको छ।
  </p>
  <a href="/admin/kyc-applications.php" class="admin-btn admin-btn-primary">
    <i class="fas fa-external-link-alt"></i> पूर्ण केवाइएम व्यवस्थापन खोल्नुहोस्
  </a>
</div>

<?php if (function_exists('memberSsotAdminHelpHtml')) { echo memberSsotAdminHelpHtml('kyc'); } ?>

<?php
  $statCards = [
    ['icon'=>'fa-inbox',      'label'=>'जम्मा KYC',    'value'=>(int)$counts['total'],     'color'=>'info',    'link'=>'kyc-applications.php'],
    ['icon'=>'fa-clock',      'label'=>'पेन्डिङ',       'value'=>(int)$counts['pending'],   'color'=>'warning', 'link'=>'kyc-applications.php?status=pending'],
    ['icon'=>'fa-check',      'label'=>'स्वीकृत',       'value'=>(int)$counts['approved'],  'color'=>'success', 'link'=>'kyc-applications.php?status=approved'],
    ['icon'=>'fa-xmark',      'label'=>'अस्वीकृत',      'value'=>(int)$counts['rejected'],  'color'=>'danger',  'link'=>'kyc-applications.php?status=rejected'],
    ['icon'=>'fa-user-check', 'label'=>'सदस्य बनेका',   'value'=>(int)$counts['generated'], 'color'=>'primary', 'link'=>'members.php'],
  ];
  $statColClass = 'col-6 col-sm-4 col-md-2';
  include __DIR__ . '/../../includes/components/stat-card.php';
?>

<div class="admin-card">
  <form method="get" class="admin-flex" style="flex-wrap:wrap;">
    <select name="status" class="admin-select" style="max-width:200px;" onchange="this.form.submit()">
      <option value="all"      <?= $status==='all'?'selected':'' ?>>सबै स्थिति</option>
      <option value="pending"  <?= $status==='pending'?'selected':'' ?>>पेन्डिङ</option>
      <option value="approved" <?= $status==='approved'?'selected':'' ?>>स्वीकृत</option>
      <option value="rejected" <?= $status==='rejected'?'selected':'' ?>>अस्वीकृत</option>
    </select>
    <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
           placeholder="नाम, मोबाइल, Tracking ID..." class="admin-input" style="max-width:300px;">
    <button type="submit" class="admin-btn admin-btn-primary"><i class="fas fa-search"></i> खोज</button>
  </form>
</div>

<div class="admin-card">
  <h2 class="admin-card-title"><i class="fas fa-list"></i> KYC सूची <span class="admin-badge"><?= count($rows) ?></span></h2>

  <?php if (!$rows): ?>
    <div class="admin-empty">
      <div class="empty-icon"><i class="fas fa-inbox"></i></div>
      <div class="empty-title">कुनै केवाइएम आवेदन फेला परेन</div>
      <div class="empty-text">हाल कुनै आवेदन छैन।</div>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>आवेदक</th><th>सम्पर्क</th><th>नागरिकता</th>
          <th>Tracking ID</th><th>दर्ता मिति</th><th>स्थिति</th><th>कार्य</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td data-label="आवेदक"><b><?= htmlspecialchars($r['full_name'] ?? '-') ?></b><br>
              <small style="color:var(--text-muted);"><?= htmlspecialchars($r['address'] ?? '') ?></small></td>
          <td data-label="सम्पर्क"><?= htmlspecialchars($r['mobile'] ?? '-') ?><br>
              <small><?= htmlspecialchars($r['email'] ?? '') ?></small></td>
          <td data-label="नागरिकता"><?= htmlspecialchars($r['citizenship_no'] ?? '-') ?></td>
          <td data-label="Tracking"><code><?= htmlspecialchars($r['tracking_id'] ?? '-') ?></code></td>
          <td data-label="मिति"><?= date('Y-m-d', strtotime($r['created_at'])) ?></td>
          <td data-label="स्थिति">
            <?php
              $s = $r['status'];
              $cls = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$s] ?? '';
              $lbl = ['pending'=>'पेन्डिङ','approved'=>'स्वीकृत','rejected'=>'अस्वीकृत'][$s] ?? $s;
            ?>
            <span class="admin-badge admin-badge-<?= $cls ?>"><?= $lbl ?></span>
          </td>
          <td data-label="कार्य">
            <a href="kyc-view.php?id=<?= (int)$r['id'] ?>" class="admin-btn admin-btn-secondary admin-btn-sm">
              <i class="fas fa-eye"></i> हेर्नुहोस्
            </a>
            <?php if ($r['status'] === 'approved'): ?>
              <a href="/admin/kyc-applications.php?view=<?= (int)$r['id'] ?>" class="admin-btn admin-btn-primary admin-btn-sm">
                <i class="fas fa-link"></i> सदस्य लिंक / Approve
              </a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

</div>

<?php include __DIR__ . '/../_partials/footer.php'; ?>
