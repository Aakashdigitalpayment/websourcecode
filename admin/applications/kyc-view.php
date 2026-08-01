<?php
/**
 * KYM Application Detail View
 */
require_once __DIR__ . '/../_bootstrap.php';
requireAdminLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: kyc.php'); exit; }

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

$app = null;
try {
    $st = $pdo->prepare("SELECT * FROM kyc_applications WHERE id=?");
    $st->execute([$id]);
    $app = $st->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}
if (!$app) { header('Location: kyc.php'); exit; }

// Handle approve / reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
    $newStatus = in_array($_POST['action'] ?? '', ['approved','rejected','pending'])
                 ? $_POST['action'] : null;
    if ($newStatus) {
        $remarks = mb_substr(trim($_POST['remarks'] ?? ''), 0, 500);
        try {
            $pdo->prepare("UPDATE kyc_applications SET status=?, remarks=?, updated_at=NOW() WHERE id=?")
                ->execute([$newStatus, $remarks, $id]);
            header('Location: kyc-view.php?id=' . $id . '&msg=' . urlencode('स्थिति अपडेट भयो।')); exit;
        } catch (\Throwable $e) {
            $errorMsg = 'अपडेट गर्न सकिएन।';
        }
    }
}

$page_title = 'KYC विवरण — ' . htmlspecialchars($app['full_name'] ?? '');
include __DIR__ . '/../_partials/header.php';

$s   = $app['status'] ?? 'pending';
$cls = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$s] ?? '';
$lbl = ['pending'=>'पेन्डिङ','approved'=>'स्वीकृत','rejected'=>'अस्वीकृत'][$s] ?? $s;
?>

<div class="admin-page-header">
  <h1 class="admin-page-title"><i class="fas fa-id-card-clip"></i> KYC विवरण</h1>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <span class="admin-badge admin-badge-<?php echo $cls; ?>" style="font-size:13px;padding:6px 14px;">
      <?php echo $lbl; ?>
    </span>
    <a href="kyc.php" class="admin-btn admin-btn-ghost"><i class="fas fa-arrow-left"></i> सूचीमा</a>
  </div>
</div>

<?php if (!empty($_GET['msg'])): ?>
<div class="admin-alert admin-alert-success" style="margin-bottom:16px;">
  <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($_GET['msg']); ?>
</div>
<?php endif; ?>
<?php if (!empty($errorMsg)): ?>
<div class="admin-alert admin-alert-danger" style="margin-bottom:16px;"><?php echo htmlspecialchars($errorMsg); ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px;">

  <!-- Applicant Details -->
  <div class="admin-card">
    <h2 class="admin-card-title"><i class="fas fa-user"></i> आवेदकको जानकारी</h2>
    <table class="admin-table">
      <tr><th width="160">Tracking ID</th><td><code style="font-weight:700;"><?php echo htmlspecialchars($app['tracking_id'] ?? '—'); ?></code></td></tr>
      <tr><th>पूरा नाम</th><td><b><?php echo htmlspecialchars($app['full_name'] ?? '—'); ?></b></td></tr>
      <tr><th>मोबाइल</th><td><?php echo htmlspecialchars($app['mobile'] ?? '—'); ?></td></tr>
      <tr><th>Email</th><td><?php echo htmlspecialchars($app['email'] ?? '—'); ?></td></tr>
      <tr><th>ठेगाना</th><td><?php echo htmlspecialchars($app['address'] ?? '—'); ?></td></tr>
      <tr><th>नागरिकता नं.</th><td><?php echo htmlspecialchars($app['citizenship_no'] ?? '—'); ?></td></tr>
      <tr><th>जन्म मिति</th><td><?php echo htmlspecialchars($app['dob'] ?? '—'); ?></td></tr>
      <tr><th>दर्ता मिति</th><td><?php echo $app['created_at'] ? date('Y-m-d H:i', strtotime($app['created_at'])) : '—'; ?></td></tr>
      <?php if (!empty($app['remarks'])): ?>
      <tr><th>कैफियत</th><td><?php echo htmlspecialchars($app['remarks']); ?></td></tr>
      <?php endif; ?>
      <?php if (!empty($app['member_id_generated'])): ?>
      <tr><th>सदस्य ID</th><td><span class="admin-badge admin-badge-success"><i class="fas fa-check me-1"></i><?php echo htmlspecialchars($app['member_id_generated']); ?></span></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- Actions -->
  <?php if ($s !== 'approved' || empty($app['member_id_generated'])): ?>
  <div class="admin-card">
    <h2 class="admin-card-title"><i class="fas fa-gavel"></i> निर्णय</h2>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
      <div style="margin-bottom:12px;">
        <label class="admin-label">कैफियत (वैकल्पिक)</label>
        <textarea name="remarks" class="admin-input" rows="3" style="resize:vertical;"
                  placeholder="स्वीकृत/अस्वीकृत गर्नुको कारण..."><?php echo htmlspecialchars($app['remarks'] ?? ''); ?></textarea>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <?php if ($s !== 'approved'): ?>
        <button type="submit" name="action" value="approved"
                class="admin-btn admin-btn-primary"
                onclick="return confirm('केवाइएम स्वीकृत गर्नुहुन्छ?')">
          <i class="fas fa-check-circle"></i> स्वीकृत गर्नुहोस्
        </button>
        <?php endif; ?>
        <?php if ($s !== 'rejected'): ?>
        <button type="submit" name="action" value="rejected"
                class="admin-btn" style="background:#dc2626;color:#fff;border-color:#dc2626;"
                onclick="return confirm('KYC अस्वीकृत गर्नुहुन्छ?')">
          <i class="fas fa-times-circle"></i> अस्वीकृत गर्नुहोस्
        </button>
        <?php endif; ?>
        <?php if ($s === 'approved'): ?>
        <a href="/admin/kyc-applications.php?view=<?php echo (int)$id; ?>"
           class="admin-btn" style="background:var(--brand-primary,#1a5f2a);color:#fff;text-align:center;">
          <i class="fas fa-link"></i> सदस्य खाता लिंक (SSOT)
        </a>
        <p style="font-size:12px;color:#6b7280;margin:8px 0 0;">
          पुरानो BBWW Generate हटाइएको छ। Approve गर्दा Member ID बाट सदस्य stub/लिंक बन्छ।
        </p>
        <?php endif; ?>
      </div>
    </form>
  </div>
  <?php else: ?>
  <div class="admin-card">
    <div style="text-align:center;padding:24px 12px;">
      <i class="fas fa-info-circle" style="font-size:2.5rem;color:#6b7280;margin-bottom:12px;display:block;"></i>
      <p style="font-weight:600;margin:0;">Legacy Generate निष्क्रिय</p>
      <p style="color:#6b7280;font-size:13px;margin:8px 0 0;">Member ID SSOT: <code><?php echo htmlspecialchars((string)($app['member_id'] ?? '')); ?></code></p>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Documents -->
<?php
$docs = [];
foreach (['citizenship_front','citizenship_back','photo','signature'] as $col) {
    if (!empty($app[$col])) $docs[$col] = $app[$col];
}
if ($docs):
?>
<div class="admin-card">
  <h2 class="admin-card-title"><i class="fas fa-file-image"></i> संलग्न कागजातहरू</h2>
  <div style="display:flex;flex-wrap:wrap;gap:16px;">
    <?php
    $labels = ['citizenship_front'=>'नागरिकता (अगाडि)','citizenship_back'=>'नागरिकता (पछाडि)','photo'=>'फोटो','signature'=>'दस्तखत'];
    foreach ($docs as $col => $path):
    ?>
    <div style="text-align:center;">
      <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php echo $labels[$col] ?? $col; ?></div>
      <a href="../../<?php echo htmlspecialchars($path); ?>" target="_blank">
        <img src="../../<?php echo htmlspecialchars($path); ?>"
             style="max-width:180px;max-height:140px;border-radius:8px;border:1px solid #e5e7eb;object-fit:contain;"
             onerror="this.style.display='none';this.nextElementSibling.style.display='block';"
             alt="<?php echo $labels[$col] ?? $col; ?>">
        <div style="display:none;padding:12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;font-size:12px;color:#6b7280;">
          <i class="fas fa-file me-1"></i> फाइल हेर्नुहोस्
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../_partials/footer.php'; ?>
