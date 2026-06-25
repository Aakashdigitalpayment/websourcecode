<?php
/**
 * Admin Members — Edit Member
 */
require_once __DIR__ . '/../_bootstrap.php';
requireAdminLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$errors = [];
$member = null;
try {
    $st = $pdo->prepare("SELECT * FROM members WHERE id=?");
    $st->execute([$id]);
    $member = $st->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}
if (!$member) { header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF token invalid. पुनः प्रयास गर्नुहोस्।';
    } else {
        $fullName = mb_substr(trim($_POST['full_name'] ?? ''), 0, 200);
        $mobile   = mb_substr(trim($_POST['mobile']    ?? ''), 0, 20);
        $email    = mb_substr(trim($_POST['email']     ?? ''), 0, 200);
        $address  = mb_substr(trim($_POST['address']   ?? ''), 0, 300);
        $status   = in_array($_POST['approval_status'] ?? '', ['approved','pending','rejected','suspended'])
                        ? $_POST['approval_status'] : 'pending';

        if (!$fullName)  $errors[] = 'नाम आवश्यक छ।';
        if (!$mobile)    $errors[] = 'मोबाइल आवश्यक छ।';

        if (!$errors) {
            try {
                $pdo->prepare("UPDATE members SET name=?, phone=?, email=?, address=?, approval_status=? WHERE id=?")
                    ->execute([$fullName, $mobile, $email, $address, $status, $id]);
                header('Location: view.php?id=' . $id . '&updated=1'); exit;
            } catch (\Throwable $e) {
                $errors[] = 'अपडेट गर्न सकिएन: ' . $e->getMessage();
            }
        }
        // Repopulate
        $member['full_name'] = $fullName;
        $member['mobile']    = $mobile;
        $member['email']     = $email;
        $member['address']   = $address;
        $member['approval_status'] = $status;
    }
}

$page_title = 'सदस्य सम्पादन — ' . htmlspecialchars($member['full_name'] ?? $member['name'] ?? '');
include __DIR__ . '/../_partials/header.php';
?>

<div class="admin-page-header">
  <h1 class="admin-page-title"><i class="fas fa-user-pen"></i>
    <?php echo htmlspecialchars($member['full_name'] ?? $member['name'] ?? '—'); ?> — सम्पादन
  </h1>
  <div style="display:flex;gap:8px;">
    <a href="view.php?id=<?php echo $id; ?>" class="admin-btn admin-btn-secondary"><i class="fas fa-eye"></i> हेर्नुहोस्</a>
    <a href="index.php" class="admin-btn admin-btn-ghost"><i class="fas fa-arrow-left"></i> सूचीमा</a>
  </div>
</div>

<?php if ($errors): ?>
<div class="admin-alert admin-alert-danger">
  <?php foreach ($errors as $e): ?><div><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="admin-card">
  <h2 class="admin-card-title"><i class="fas fa-user-edit"></i> जानकारी अपडेट</h2>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
      <div>
        <label class="admin-label">Member ID</label>
        <input type="text" class="admin-input" value="<?php echo htmlspecialchars($member['member_id'] ?? $member['sadasyata_number'] ?? '—'); ?>" disabled>
        <small style="color:#9ca3af;">Member ID परिवर्तन हुँदैन।</small>
      </div>
      <div>
        <label class="admin-label">स्थिति</label>
        <select name="approval_status" class="admin-select">
          <?php foreach (['approved'=>'स्वीकृत','pending'=>'पेन्डिङ','rejected'=>'अस्वीकृत','suspended'=>'निलम्बित'] as $v => $l): ?>
          <option value="<?php echo $v; ?>" <?php echo ($member['approval_status']??'')===$v?'selected':''; ?>><?php echo $l; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="admin-label">पूरा नाम <span style="color:red;">*</span></label>
        <input type="text" name="full_name" class="admin-input" required
               value="<?php echo htmlspecialchars($member['full_name'] ?? $member['name'] ?? ''); ?>">
      </div>
      <div>
        <label class="admin-label">मोबाइल <span style="color:red;">*</span></label>
        <input type="tel" name="mobile" class="admin-input" required
               value="<?php echo htmlspecialchars($member['mobile'] ?? $member['phone'] ?? ''); ?>">
      </div>
      <div>
        <label class="admin-label">Email</label>
        <input type="email" name="email" class="admin-input"
               value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>">
      </div>
      <div>
        <label class="admin-label">ठेगाना</label>
        <input type="text" name="address" class="admin-input"
               value="<?php echo htmlspecialchars($member['address'] ?? ''); ?>">
      </div>
    </div>
    <div style="margin-top:20px;display:flex;gap:10px;">
      <button type="submit" class="admin-btn admin-btn-primary">
        <i class="fas fa-save"></i> परिवर्तन सुरक्षित
      </button>
      <a href="view.php?id=<?php echo $id; ?>" class="admin-btn admin-btn-ghost">रद्द</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../_partials/footer.php'; ?>
