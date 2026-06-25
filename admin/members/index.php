<?php
/**
 * Member List — v10.0 refactored
 */
require_once __DIR__ . '/../_bootstrap.php';
requireAdminLogin();

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 200, 'UTF-8');
$where = ''; $params = [];
if ($q !== '') {
  $where = "WHERE name LIKE :q OR sadasyata_number LIKE :q OR phone LIKE :q";
  $params[':q'] = '%'.$q.'%';
}
$sql = "SELECT id, sadasyata_number, name, phone, email, approval_status, created_at
        FROM members {$where} ORDER BY created_at DESC LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'सदस्य व्यवस्थापन';
include __DIR__ . '/../_partials/header.php';
?>

<div class="admin-page-header">
  <h1 class="admin-page-title"><i class="fas fa-users"></i> सदस्य सूची</h1>
  <a href="add.php" class="admin-btn admin-btn-primary"><i class="fas fa-user-plus"></i> नयाँ सदस्य</a>
</div>

<div class="admin-card">
  <form method="get" class="admin-flex" style="flex-wrap:wrap;">
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="नाम, Member ID, मोबाइल..." class="admin-input" style="max-width:340px;">
    <button class="admin-btn admin-btn-primary"><i class="fas fa-search"></i> खोज</button>
    <?php if ($q): ?><a href="?" class="admin-btn admin-btn-ghost">Clear</a><?php endif; ?>
  </form>
</div>

<div class="admin-card">
  <h2 class="admin-card-title"><i class="fas fa-list"></i> सदस्यहरू <span class="admin-badge"><?= count($rows) ?></span></h2>

  <?php if (!$rows): ?>
    <div class="admin-empty">
      <div class="empty-icon"><i class="fas fa-users-slash"></i></div>
      <div class="empty-title">कुनै सदस्य फेला परेन</div>
      <div class="empty-text">"नयाँ सदस्य" बटन क्लिक गरेर सुरु गर्नुहोस्।</div>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>Member ID</th><th>नाम</th><th>सम्पर्क</th><th>स्थिति</th><th>दर्ता मिति</th><th>कार्य</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td data-label="ID"><code style="font-weight:700;color:var(--brand-primary);"><?= htmlspecialchars($r['sadasyata_number'] ?? $r['member_id'] ?? '') ?></code></td>
          <td data-label="नाम"><b><?= htmlspecialchars($r['name'] ?? $r['full_name'] ?? '') ?></b></td>
          <td data-label="सम्पर्क"><?= htmlspecialchars($r['phone'] ?? $r['mobile'] ?? '' ?? '-') ?><br><small><?= htmlspecialchars($r['email'] ?? '') ?></small></td>
          <td data-label="स्थिति">
            <?php
              $s = $r['approval_status'];
              $cls = ['approved'=>'success','pending'=>'warning','rejected'=>'danger','suspended'=>'danger'][$s] ?? '';
              $lbl = ['approved'=>'स्वीकृत','pending'=>'पेन्डिङ','rejected'=>'अस्वीकृत','suspended'=>'निलम्बित'][$s] ?? $s;
            ?>
            <span class="admin-badge admin-badge-<?= $cls ?>"><?= $lbl ?></span>
          </td>
          <td data-label="मिति"><?= date('Y-m-d', strtotime($r['created_at'])) ?></td>
          <td data-label="कार्य">
            <a href="view.php?id=<?= (int)$r['id'] ?>" class="admin-btn admin-btn-secondary admin-btn-sm"><i class="fas fa-eye"></i></a>
            <a href="edit.php?id=<?= (int)$r['id'] ?>" class="admin-btn admin-btn-ghost admin-btn-sm"><i class="fas fa-pen"></i></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../_partials/footer.php'; ?>
