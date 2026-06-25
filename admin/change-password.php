<?php
$pageTitle = 'पासवर्ड परिवर्तन';
require_once __DIR__ . '/../includes/superadmin-config.php';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

/* Superadmin + local फाइलमा पासवर्ड: सामान्यतया `superadmin-config.local.php` edit गर्नुहोस्।
 * यो पृष्ठ अरू admin वा superadmin (फाइलमा पासवर्ड खाली पारेपछि) को लागि। */

$mustChangeBanner = false;
try {
    $dbc = getDB();
    if (function_exists('safeColumnExists') && safeColumnExists('admin_users', 'must_change_password')) {
        $stb = $dbc->prepare('SELECT must_change_password FROM admin_users WHERE id = ? LIMIT 1');
        $stb->execute([(int) ($_SESSION['admin_id'] ?? 0)]);
        $mustChangeBanner = ((int) $stb->fetchColumn() === 1);
    }
} catch (Throwable $e) { /* ignore */ }

$fileManagedSuperadmin = !empty($_SESSION['is_superadmin'])
    && defined('SUPER_ADMIN_INITIAL_PASSWORD')
    && SUPER_ADMIN_INITIAL_PASSWORD !== '';

// Handle form submission — PRG pattern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        setFlash('error', 'सबै फिल्डहरू भर्नुहोस्।');
    } elseif ($newPassword !== $confirmPassword) {
        setFlash('error', 'नयाँ पासवर्ड र पुष्टि पासवर्ड मेल खाएन।');
    } elseif (strlen($newPassword) < 6) {
        setFlash('error', 'पासवर्ड कम्तिमा ६ अक्षर हुनुपर्छ।');
    } else {
        try {
            $db = getDB();
            $adminId = $_SESSION['admin_id'];
            $stmt = $db->prepare("SELECT password FROM admin_users WHERE id = ?");
            $stmt->execute([$adminId]);
            $user = $stmt->fetch();
            if ($user && password_verify($currentPassword, $user['password'])) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                try {
                    $db->prepare('UPDATE admin_users SET password = ?, must_change_password = 0 WHERE id = ?')
                       ->execute([$hashedPassword, $adminId]);
                } catch (Throwable $e) {
                    $db->prepare('UPDATE admin_users SET password = ? WHERE id = ?')
                       ->execute([$hashedPassword, $adminId]);
                }
                $db->prepare("INSERT INTO activity_log (admin_id, action, description, ip_address) VALUES (?, ?, ?, ?)")
                   ->execute([$adminId, 'password_change', 'Admin changed password', $_SERVER['REMOTE_ADDR']]);
                setFlash('success', 'पासवर्ड सफलतापूर्वक परिवर्तन भयो।');
            } else {
                setFlash('error', 'हालको पासवर्ड गलत छ।');
            }
        } catch (Exception $e) {
            error_log('Password change error: ' . $e->getMessage());
            setFlash('error', 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।');
        }
    }
    redirect(ADMIN_URL . 'change-password.php');
}
?>
<?php
echo adminPageHeader('पासवर्ड परिवर्तन', 'fa-key', 'Admin खाताको पासवर्ड परिवर्तन गर्नुहोस्');
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-key"></i> पासवर्ड परिवर्तन गर्नुहोस्</h5>
            </div>
            <div class="card-body">
                <?php if ($fileManagedSuperadmin && !$success): ?>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" data-bs-toggle="collapse" data-bs-target="#superadminPwHelp" aria-expanded="false" aria-controls="superadminPwHelp">
                        <i class="fas fa-circle-question me-1"></i>Superadmin पासवर्ड कसरी बदल्ने?
                    </button>
                </div>
                <div class="collapse mb-3" id="superadminPwHelp">
                    <div class="alert alert-info border-info small mb-0">
                        <i class="fas fa-file-code me-1"></i>
                        <strong>Superadmin</strong> को पासवर्ड <code>includes/superadmin-config.local.php</code> मा
                        <code>SUPER_ADMIN_INITIAL_PASSWORD</code> edit गरेर बदल्नुहोस् — login गर्दा DB सँग sync हुन्छ।
                        यो फारम admin panel बाट पासवर्ड बदल्न <em>चाहनुभए</em> मात्र प्रयोग गर्नुहोस् (फाइलमा पासवर्ड खाली पारेपछि)।
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($mustChangeBanner && !$success && !$fileManagedSuperadmin): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-shield-halved me-1"></i>
                    Superadmin/Admin ले तपाईंको खाताको पासवर्ड सेट गरेको छ — अब आफ्नो <strong>हालको</strong> (अस्थायी) पासवर्ड र <strong>नयाँ</strong> पासवर्ड राखेर सेभ गर्नुहोस्। Public password-reset URL छैन।
                </div>
                <?php endif; ?>
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

                <form method="POST" action="" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
                    <div class="mb-3">
                        <label class="form-label">हालको पासवर्ड <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" name="current_password" class="form-control" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">नयाँ पासवर्ड <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                            <input type="password" name="new_password" class="form-control" minlength="6" required>
                        </div>
                        <small class="text-muted">कम्तिमा ६ अक्षर</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">नयाँ पासवर्ड पुष्टि गर्नुहोस् <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                            <input type="password" name="confirm_password" class="form-control" minlength="6" required>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> पासवर्ड परिवर्तन गर्नुहोस्
                        </button>
                        <a href="dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> फिर्ता
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h5><i class="fas fa-info-circle"></i> पासवर्ड नीति</h5>
            </div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>पासवर्ड कम्तिमा ६ अक्षर हुनुपर्छ।</li>
                    <li>ठूला र सानो अक्षरहरू मिसाउनुहोस्।</li>
                    <li>अंक र विशेष चिन्हहरू समावेश गर्नुहोस्।</li>
                    <li>सजिलो अनुमान लगाउन नमिल्ने पासवर्ड प्रयोग गर्नुहोस्।</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
