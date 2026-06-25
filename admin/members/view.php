<?php
/**
 * Admin Members — View Member Detail
 */
require_once __DIR__ . '/../_bootstrap.php';
requireAdminLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$member = null;
$notifs = [];
try {
    $st = $pdo->prepare("SELECT * FROM members WHERE id=?");
    $st->execute([$id]);
    $member = $st->fetch(PDO::FETCH_ASSOC);
    if (!$member) { header('Location: index.php'); exit; }

    // Notifications
    $ns = $pdo->prepare("SELECT * FROM member_notifications WHERE member_id=? ORDER BY created_at DESC LIMIT 30");
    $ns->execute([$id]);
    $notifs = $ns->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    header('Location: index.php'); exit;
}

// Handle status change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $newStatus = in_array($_POST['approval_status'], ['approved','pending','rejected','suspended'])
            ? $_POST['approval_status'] : 'pending';
        $pdo->prepare("UPDATE members SET approval_status=? WHERE id=?")->execute([$newStatus, $id]);
        header('Location: view.php?id=' . $id); exit;
    }
}
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$page_title = 'सदस्य विवरण — ' . htmlspecialchars($member['full_name'] ?? $member['name'] ?? '');
include __DIR__ . '/../_partials/header.php';
?>

<div class="admin-page-header">
  <h1 class="admin-page-title">
    <i class="fas fa-user-circle"></i>
    <?php echo htmlspecialchars($member['full_name'] ?? $member['name'] ?? '—'); ?>
  </h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a href="edit.php?id=<?php echo $id; ?>" class="admin-btn admin-btn-primary"><i class="fas fa-pen"></i> सम्पादन</a>
    <a href="index.php" class="admin-btn admin-btn-ghost"><i class="fas fa-arrow-left"></i> फिर्ता</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

  <!-- Basic Info -->
  <div class="admin-card">
    <h2 class="admin-card-title"><i class="fas fa-id-card"></i> व्यक्तिगत जानकारी</h2>
    <table class="admin-table">
      <tr><th width="140">Member ID</th><td><code><?php echo htmlspecialchars($member['member_id'] ?? $member['sadasyata_number'] ?? '—'); ?></code></td></tr>
      <tr><th>नाम</th><td><?php echo htmlspecialchars($member['full_name'] ?? $member['name'] ?? '—'); ?></td></tr>
      <tr><th>मोबाइल</th><td><?php echo htmlspecialchars($member['mobile'] ?? $member['phone'] ?? '—'); ?></td></tr>
      <tr><th>Email</th><td><?php echo htmlspecialchars($member['email'] ?? '—'); ?></td></tr>
      <tr><th>ठेगाना</th><td><?php echo htmlspecialchars($member['address'] ?? '—'); ?></td></tr>
      <tr><th>दर्ता मिति</th><td><?php echo $member['created_at'] ? date('Y-m-d', strtotime($member['created_at'])) : '—'; ?></td></tr>
    </table>
  </div>

  <!-- Status & Actions -->
  <div class="admin-card">
    <h2 class="admin-card-title"><i class="fas fa-shield-halved"></i> स्थिति र कार्य</h2>
    <?php
    $s = $member['approval_status'] ?? 'pending';
    $cls = ['approved'=>'success','pending'=>'warning','rejected'=>'danger','suspended'=>'danger'][$s] ?? '';
    $lbl = ['approved'=>'स्वीकृत','pending'=>'पेन्डिङ','rejected'=>'अस्वीकृत','suspended'=>'निलम्बित'][$s] ?? $s;
    ?>
    <p>हालको स्थिति: <span class="admin-badge admin-badge-<?php echo $cls; ?>"><?php echo $lbl; ?></span></p>

    <form method="POST" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
      <input type="hidden" name="set_status" value="1">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <select name="approval_status" class="admin-select" style="max-width:180px;">
          <option value="approved" <?php echo $s==='approved'?'selected':''; ?>>स्वीकृत</option>
          <option value="pending"  <?php echo $s==='pending'?'selected':''; ?>>पेन्डिङ</option>
          <option value="rejected" <?php echo $s==='rejected'?'selected':''; ?>>अस्वीकृत</option>
          <option value="suspended" <?php echo $s==='suspended'?'selected':''; ?>>निलम्बित</option>
        </select>
        <button class="admin-btn admin-btn-primary" onclick="return confirm('स्थिति बदल्ने?')">
          <i class="fas fa-check"></i> अपडेट
        </button>
      </div>
    </form>

    <hr style="margin:16px 0;">
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="../kyc-applications.php?search=<?php echo urlencode($member['email'] ?? ''); ?>"
         class="admin-btn admin-btn-secondary admin-btn-sm">
        <i class="fas fa-file-contract"></i> KYC हेर्नुहोस्
      </a>
    </div>
  </div>
</div>

<!-- Notification History -->
<?php if ($notifs): ?>
<div class="admin-card">
  <h2 class="admin-card-title"><i class="fas fa-bell"></i> Notification इतिहास <span class="admin-badge"><?php echo count($notifs); ?></span></h2>
  <div class="admin-table-wrap">
    <table class="admin-table">
      <thead><tr><th>शीर्षक</th><th>सन्देश</th><th>मिति</th><th>पढिएको</th></tr></thead>
      <tbody>
        <?php foreach ($notifs as $n): ?>
        <tr>
          <td><?php echo htmlspecialchars($n['title'] ?? '—'); ?></td>
          <td><small><?php echo htmlspecialchars(mb_substr($n['message'] ?? '', 0, 80)); ?></small></td>
          <td><?php echo $n['created_at'] ? date('Y-m-d H:i', strtotime($n['created_at'])) : '—'; ?></td>
          <td><?php echo $n['is_read'] ? '<span class="admin-badge admin-badge-success">पढिएको</span>' : '<span class="admin-badge admin-badge-warning">नपढेको</span>'; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../_partials/footer.php'; ?>
