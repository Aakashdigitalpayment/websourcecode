<?php
$pageTitle = 'प्रोफाइल';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

// Handle form submission — PRG pattern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $fullName = clean_text($_POST['full_name'] ?? '', 200);
    $email    = strtolower(clean_text($_POST['email'] ?? '', 254));
    if (empty($fullName)) {
        setFlash('error', 'नाम आवश्यक छ।');
    } else {
        try {
            $db = getDB();
            $adminId = $_SESSION['admin_id'];
            $db->prepare("UPDATE admin_users SET full_name = ?, email = ? WHERE id = ?")->execute([$fullName, $email, $adminId]);
            $_SESSION['admin_name'] = $fullName;
            setFlash('success', 'प्रोफाइल सफलतापूर्वक अपडेट भयो।');
        } catch (Exception $e) {
            error_log('Profile update error: ' . $e->getMessage());
            setFlash('error', 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।');
        }
    }
    redirect(ADMIN_URL . 'profile.php');
}

// GET: load admin data
$admin = null;
try {
    $db = getDB();
    $adminId = $_SESSION['admin_id'];
    $stmt = $db->prepare("SELECT * FROM admin_users WHERE id = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();
} catch (Exception $e) {
    error_log('Profile load error: ' . $e->getMessage());
}
?>
<?php
echo adminPageHeader('प्रोफाइल', 'fa-user-circle', 'Admin प्रोफाइल जानकारी अपडेट गर्नुहोस्');
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-user-edit"></i> प्रोफाइल जानकारी</h5>
            </div>
            <div class="card-body">
                <?php
                $flashErr = getFlash('error');
                $flashOk  = getFlash('success');
                if ($flashErr): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-1"></i> <?php echo e($flashErr); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; if ($flashOk): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-1"></i> <?php echo e($flashOk); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($admin): ?>
                <form method="POST" action="" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">युजरनेम</label>
                            <input type="text" class="form-control" value="<?php echo $admin['username']; ?>" disabled>
                            <small class="text-muted">युजरनेम परिवर्तन गर्न मिल्दैन</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">पूरा नाम <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="<?php echo $admin['full_name']; ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">इमेल</label>
                            <input type="email" name="email" class="form-control" value="<?php echo $admin['email']; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">अन्तिम लगइन</label>
                            <input type="text" class="form-control" value="<?php echo $admin['last_login'] ? formatDate($admin['last_login'], 'Y-m-d H:i:s') : 'N/A'; ?>" disabled>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span><i class="fas fa-save me-1"></i> प्रोफाइल अपडेट गर्नुहोस्
                        </button>
                        <a href="change-password.php" class="btn btn-outline-primary">
                            <i class="fas fa-key"></i> पासवर्ड बदल्नुहोस्
                        </a>
                    </div>
                </form>
                <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> प्रोफाइल जानकारी लोड गर्न सकिएन।
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
