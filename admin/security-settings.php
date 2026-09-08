<?php
/**
 * Superadmin only — login 2FA policy (admin + member).
 */
define('IS_ADMIN_PAGE', true);
$pageTitle = 'Security Settings';
$currentPage = 'security-settings';

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
        updateSetting('twofa_admin_required', isset($_POST['twofa_admin_required']) ? '1' : '0');
        updateSetting('twofa_member_required', isset($_POST['twofa_member_required']) ? '1' : '0');
        updateSetting('turnstile_site_key', trim((string) ($_POST['turnstile_site_key'] ?? '')));
        $tsSecret = trim((string) ($_POST['turnstile_secret_key'] ?? ''));
        /* Password field often blanks on browser autofill — keep existing secret unless a new value is typed. */
        if ($tsSecret !== '') {
            updateSetting('turnstile_secret_key', $tsSecret);
        }
        if (!empty($_POST['turnstile_clear_secret'])) {
            updateSetting('turnstile_secret_key', '');
        }
        if (function_exists('writeAuditLog')) {
            writeAuditLog(
                'security_settings_update',
                'twofa_admin=' . getSetting('twofa_admin_required', '0')
                    . ' twofa_member=' . getSetting('twofa_member_required', '0')
                    . ' turnstile=' . (getSetting('turnstile_site_key', '') !== '' ? 'on' : 'off'),
                'settings',
                0
            );
        }
        setFlash('success', 'सुरक्षा सेटिङ सेभ भयो।');
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
    }
    redirect('security-settings.php');
}

require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/includes/admin-ui.php';

$adminIsEn = !empty($adminIsEnglish);
$t = static function (string $np, string $en) use ($adminIsEn): string {
    return $adminIsEn ? $en : $np;
};

$adminRequired = getSetting('twofa_admin_required', '0') === '1';
$memberRequired = getSetting('twofa_member_required', '0') === '1';
$turnstileSite = (string) getSetting('turnstile_site_key', '');
$turnstileSecret = (string) getSetting('turnstile_secret_key', '');
?>
<div class="container-fluid py-3">
<?php
echo adminPageHeader(
    $t('सुरक्षा / 2FA', 'Security / 2FA'),
    'shield-halved',
    $t(
        'Admin/Member 2FA नीति र optional Cloudflare Turnstile — केवल Superadmin।',
        'Admin/Member 2FA policy and optional Cloudflare Turnstile — Superadmin only.'
    ),
    '<a href="dashboard.php" class="btn btn-sm btn-outline-secondary">Dashboard</a>'
);
?>
<?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

<div class="card shadow-sm border-0 mb-4" style="max-width:640px">
    <div class="card-body">
        <div class="alert alert-warning py-2 px-3 mb-3">
            <i class="lucide-icon me-1" aria-hidden="true" data-lucide="lock"></i>
            <?php echo $t(
                'तलको toggle अनुसार लगइनमा 2FA लागू हुन्छ। अनिवार्य गर्नु अघि सम्बन्धित प्रयोगकर्तासँग Authenticator सेटअप भएको सुनिश्चित गर्नुहोस्।',
                'Toggles below enforce 2FA at login. Ensure users have Authenticator set up before requiring it.'
            ); ?>
        </div>
        <form method="post" action="security-settings.php" autocomplete="off">
            <?php echo csrfField(); ?>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="twofa_admin_required" name="twofa_admin_required" value="1"
                       <?php echo $adminRequired ? 'checked' : ''; ?>>
                <label class="form-check-label" for="twofa_admin_required">
                    <?php echo $t('Admin Login मा 2FA अनिवार्य', 'Require 2FA for Admin Login'); ?>
                </label>
            </div>
            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" id="twofa_member_required" name="twofa_member_required" value="1"
                       <?php echo $memberRequired ? 'checked' : ''; ?>>
                <label class="form-check-label" for="twofa_member_required">
                    <?php echo $t('Member Login मा 2FA अनिवार्य', 'Require 2FA for Member Login'); ?>
                </label>
            </div>

            <hr class="my-4">
            <h6 class="fw-bold mb-2">
                <i class="lucide-icon me-1" aria-hidden="true" data-lucide="shield"></i>
                <?php echo $t('Cloudflare Turnstile (वैकल्पिक)', 'Cloudflare Turnstile (optional)'); ?>
            </h6>
            <p class="text-muted small mb-3">
                <?php echo $t(
                    'Site + Secret key दुवै भरेपछि public forms (Contact, Live Chat, आवेदन फारमहरू) मा Turnstile चल्छ। खाली छोड्दा math check + honeypot मात्र।',
                    'When both Site and Secret keys are set, Turnstile runs on public forms (Contact, Live Chat, application forms). Leave empty to keep math check + honeypot only.'
                ); ?>
            </p>
            <div class="mb-3">
                <label class="form-label" for="turnstile_site_key"><?php echo $t('Turnstile Site Key', 'Turnstile Site Key'); ?></label>
                <input type="text" class="form-control" id="turnstile_site_key" name="turnstile_site_key"
                       value="<?php echo htmlspecialchars($turnstileSite, ENT_QUOTES, 'UTF-8'); ?>"
                       autocomplete="off" spellcheck="false">
            </div>
            <div class="mb-4">
                <label class="form-label" for="turnstile_secret_key"><?php echo $t('Turnstile Secret Key', 'Turnstile Secret Key'); ?></label>
                <input type="password" class="form-control" id="turnstile_secret_key" name="turnstile_secret_key"
                       value="" placeholder="<?php echo $turnstileSecret !== ''
                           ? htmlspecialchars($t('•••••••• (नयाँ लेख्दा मात्र बदल्नुहोस्)', '•••••••• (leave blank to keep)'), ENT_QUOTES, 'UTF-8')
                           : ''; ?>"
                       autocomplete="new-password" spellcheck="false">
                <?php if ($turnstileSecret !== ''): ?>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="turnstile_clear_secret" name="turnstile_clear_secret" value="1">
                    <label class="form-check-label small text-muted" for="turnstile_clear_secret">
                        <?php echo $t('Secret key हटाउनुहोस् (Turnstile बन्द)', 'Clear secret key (disable Turnstile)'); ?>
                    </label>
                </div>
                <?php endif; ?>
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
