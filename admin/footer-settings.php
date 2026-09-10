<?php
/**
 * Superadmin only — public footer credits (Developed By / Supported By).
 * Copyright text is derived from cooperative site_name (not free-edit).
 */
define('IS_ADMIN_PAGE', true);
$pageTitle = 'Footer Settings';
$currentPage = 'footer-settings';

require_once __DIR__ . '/../includes/config.php';

if (!isAdminLoggedIn()) {
    redirect(ADMIN_URL . 'index.php');
}
if (empty($_SESSION['is_superadmin'])) {
    setFlash('error', 'यो पृष्ठ केवल Superadmin ले प्रयोग गर्न सक्छ।');
    redirect(ADMIN_URL . 'dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    try {
        $keys = ['developer_name', 'developer_url', 'supported_name', 'supported_url'];
        foreach ($keys as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $value = trim((string) $_POST[$key]);
            if (in_array($key, ['developer_url', 'supported_url'], true)) {
                $value = function_exists('safe_http_url') ? safe_http_url($value) : $value;
            } else {
                $value = function_exists('clean_text') ? clean_text($value, 120) : $value;
            }
            updateSetting($key, $value);
        }
        /* Keep legacy footer_text aligned with site name */
        if (function_exists('coop_footer_copyright_text')) {
            updateSetting('footer_text', coop_footer_copyright_text(false));
        }
        if (function_exists('writeAuditLog')) {
            writeAuditLog('footer_settings_update', 'developer/supported credits', 'settings', 0);
        }
        setFlash('success', 'फुटर सेटिङ सेभ भयो।');
    } catch (Throwable $e) {
        error_log('[footer-settings] ' . $e->getMessage());
        setFlash('error', 'सेटिङ सुरक्षित गर्न सकिएन।');
    }
    redirect('footer-settings.php');
}

require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/includes/admin-ui.php';

$adminIsEn = !empty($adminIsEnglish);
$t = static function (string $np, string $en) use ($adminIsEn): string {
    return $adminIsEn ? $en : $np;
};

$developerName = (string) getSetting('developer_name', 'Tanka Adhikari');
$developerUrl = (string) getSetting('developer_url', 'https://www.tankaadhikari.com.np/');
$supportedName = (string) getSetting('supported_name', '');
$supportedUrl = (string) getSetting('supported_url', '');
$copyrightPreview = function_exists('coop_footer_copyright_text')
    ? coop_footer_copyright_text(false)
    : ('© ' . date('Y') . ' ' . getSetting('site_name', 'सहकारी') . '। सर्वाधिकार सुरक्षित।');
?>
<div class="container-fluid py-3">
<?php
echo adminPageHeader(
    $t('फुटर सेटिङ', 'Footer Settings'),
    'copyright',
    $t(
        'सार्वजनिक फुटरको Developed By / Supported By — केवल Superadmin। Copyright सहकारीको नामबाट स्वतः बन्छ।',
        'Public footer Developed By / Supported By — Superadmin only. Copyright is generated from the cooperative site name.'
    ),
    '<a href="settings.php" class="btn btn-sm btn-outline-secondary">' . $t('साइट नाम', 'Site name') . '</a>'
);
?>
<?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

<div class="card shadow-sm border-0 mb-4" style="max-width:720px">
    <div class="card-body">
        <form method="post" action="footer-settings.php" autocomplete="off">
            <?php echo csrfField(); ?>

            <div class="mb-3">
                <label class="form-label"><?php echo $t('Copyright Text', 'Copyright Text'); ?></label>
                <input type="text" class="form-control" readonly value="<?php echo htmlspecialchars($copyrightPreview, ENT_QUOTES, 'UTF-8'); ?>">
                <small class="text-muted d-block mt-1">
                    <?php echo $t(
                        'यो पाठ सहकारीको नाम (साइट सेटिङ्स → साइट नाम) बाट स्वतः बन्छ।',
                        'Generated from the cooperative name in Site Settings → Site name.'
                    ); ?>
                    <a href="settings.php"><?php echo $t('नाम बदल्नुहोस्', 'Change name'); ?></a>
                </small>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="developer_name" class="form-label">Developed By (Name)</label>
                    <input type="text" name="developer_name" id="developer_name" class="form-control"
                           value="<?php echo htmlspecialchars($developerName, ENT_QUOTES, 'UTF-8'); ?>" maxlength="120">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="developer_url" class="form-label">Developed By URL</label>
                    <input type="url" name="developer_url" id="developer_url" class="form-control"
                           value="<?php echo htmlspecialchars($developerUrl, ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://">
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="supported_name" class="form-label"><?php echo $t('Supported By (Name)', 'Supported By (Name)'); ?></label>
                    <input type="text" name="supported_name" id="supported_name" class="form-control"
                           value="<?php echo htmlspecialchars($supportedName, ENT_QUOTES, 'UTF-8'); ?>" maxlength="120">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="supported_url" class="form-label"><?php echo $t('Supported By URL', 'Supported By URL'); ?></label>
                    <input type="url" name="supported_url" id="supported_url" class="form-control"
                           value="<?php echo htmlspecialchars($supportedUrl, ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="lucide-icon me-1" aria-hidden="true" data-lucide="save"></i>
                <?php echo $t('सेभ गर्नुहोस्', 'Save'); ?>
            </button>
        </form>
    </div>
</div>
</div>
<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
