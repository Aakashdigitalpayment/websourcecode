<?php
/**
 * Admin Members — Add New Member
 */
require_once __DIR__ . '/../_bootstrap.php';
requireAdminLogin();

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'CSRF token invalid. पुनः प्रयास गर्नुहोस्।';
    } else {
        $fullName = mb_substr(trim($_POST['full_name'] ?? ''), 0, 200);
        $mobile   = mb_substr(trim($_POST['mobile']    ?? ''), 0, 20);
        $email    = mb_substr(trim($_POST['email']     ?? ''), 0, 200);
        $address  = mb_substr(trim($_POST['address']   ?? ''), 0, 300);
        $status   = in_array($_POST['approval_status'] ?? '', ['approved','pending','rejected'])
                        ? $_POST['approval_status'] : 'pending';

        if (!$fullName)  $errors[] = 'नाम आवश्यक छ।';
        if (!$mobile)    $errors[] = 'मोबाइल आवश्यक छ।';

        if (!$errors) {
            try {
                // Generate Member ID
                $year  = date('Y');
                $count = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
                $memberId = 'MEM-' . $year . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

                $pdo->prepare("INSERT INTO members (sadasyata_number, name, phone, email, address, approval_status, created_at)
                               VALUES (?,?,?,?,?,?,NOW())")
                    ->execute([$memberId, $fullName, $mobile, $email, $address, $status]);

                $newId = $pdo->lastInsertId();
                header('Location: view.php?id=' . $newId . '&added=1'); exit;
            } catch (\Throwable $e) {
                $errors[] = 'सदस्य थप्न सकिएन: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'नयाँ सदस्य थप्नुहोस्';
include __DIR__ . '/../_partials/header.php';
?>

<div class="admin-page-header">
  <h1 class="admin-page-title"><i class="fas fa-user-plus"></i> नयाँ सदस्य थप्नुहोस्</h1>
  <a href="index.php" class="admin-btn admin-btn-ghost"><i class="fas fa-arrow-left"></i> सूचीमा फर्क</a>
</div>

<?php if ($errors): ?>
<div class="admin-alert admin-alert-danger">
  <?php foreach ($errors as $e): ?><div><i class="fas fa-exclamation-circle me-1"></i><?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="admin-card">
  <h2 class="admin-card-title"><i class="fas fa-user-edit"></i> सदस्य जानकारी</h2>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
      <div>
        <label class="admin-label">पूरा नाम <span style="color:red;">*</span></label>
        <input type="text" name="full_name" class="admin-input" required
               value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>"
               placeholder="सदस्यको पूरा नाम">
      </div>
      <div>
        <label class="admin-label">मोबाइल नम्बर <span style="color:red;">*</span></label>
        <input type="tel" name="mobile" class="admin-input" required
               value="<?php echo htmlspecialchars($_POST['mobile'] ?? ''); ?>"
               placeholder="98XXXXXXXX">
      </div>
      <div>
        <label class="admin-label">Email</label>
        <input type="email" name="email" class="admin-input"
               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
               placeholder="member@example.com">
      </div>
      <div>
        <label class="admin-label">स्थिति</label>
        <select name="approval_status" class="admin-select">
          <option value="pending"  <?php echo ($_POST['approval_status']??'pending')==='pending'?'selected':''; ?>>पेन्डिङ</option>
          <option value="approved" <?php echo ($_POST['approval_status']??'')==='approved'?'selected':''; ?>>स्वीकृत</option>
        </select>
      </div>
      <div style="grid-column:1/-1;">
        <label class="admin-label">ठेगाना</label>
        <input type="text" name="address" class="admin-input"
               value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>"
               placeholder="सदस्यको ठेगाना">
      </div>
    </div>
    <div style="margin-top:20px;display:flex;gap:10px;">
      <button type="submit" class="admin-btn admin-btn-primary">
        <i class="fas fa-user-plus"></i> सदस्य थप्नुहोस्
      </button>
      <a href="index.php" class="admin-btn admin-btn-ghost">रद्द</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../_partials/footer.php'; ?>
