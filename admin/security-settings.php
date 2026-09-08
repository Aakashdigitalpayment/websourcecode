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
        updateSetting('csp_enforce', isset($_POST['csp_enforce']) ? '1' : '0');
        updateSetting('csp_script_nonce', isset($_POST['csp_script_nonce']) ? '1' : '0');
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
                'csp_enforce=' . getSetting('csp_enforce', '1')
                    . ' csp_script_nonce=' . getSetting('csp_script_nonce', '1')
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

$cspEnforce = getSetting('csp_enforce', '1') !== '0';
$cspScriptNonce = getSetting('csp_script_nonce', '1') !== '0';
$turnstileSite = (string) getSetting('turnstile_site_key', '');
$turnstileSecret = (string) getSetting('turnstile_secret_key', '');
?>
<div class="container-fluid py-3">
<?php
echo adminPageHeader(
    $t('सुरक्षा / 2FA', 'Security / 2FA'),
    'shield-halved',
    $t(
        'Google Authenticator 2FA नीति, CSP, र optional Cloudflare Turnstile — केवल Superadmin।',
        'Google Authenticator 2FA policy, CSP, and optional Cloudflare Turnstile — Superadmin only.'
    ),
    '<a href="dashboard.php" class="btn btn-sm btn-outline-secondary">Dashboard</a>'
);
?>
<?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

<div class="card shadow-sm border-0 mb-4" style="max-width:640px">
    <div class="card-body">
        <div class="alert alert-info py-2 px-3 mb-3">
            <i class="lucide-icon me-1" aria-hidden="true" data-lucide="smartphone"></i>
            <?php echo $t(
                '2FA = Google Authenticator (TOTP + QR)। नीति: Superadmin सहित सबै Admin अनिवार्य; Member Portal अनिवार्य (password र Google/Facebook login दुवै)।',
                '2FA = Google Authenticator (TOTP + QR). Policy: all Admins including Superadmin mandatory; Member Portal mandatory (password and Google/Facebook login).'
            ); ?>
        </div>
        <ul class="small text-muted mb-4 ps-3">
            <li><?php echo $t('Superadmin — पहिलो login मा QR setup अनिवार्य', 'Superadmin — QR setup required on first login'); ?></li>
            <li><?php echo $t('अन्य Admin / Staff — पहिलो login मा QR setup अनिवार्य', 'Other Admin / Staff — QR setup required on first login'); ?></li>
            <li><?php echo $t('Member — हरेक login मा Google Authenticator अनिवार्य', 'Member — Google Authenticator required on every login'); ?></li>
        </ul>
        <form method="post" action="security-settings.php" autocomplete="off">
            <?php echo csrfField(); ?>

            <hr class="my-4">
            <h6 class="fw-bold mb-2">
                <i class="lucide-icon me-1" aria-hidden="true" data-lucide="shield"></i>
                <?php echo $t('Content Security Policy (CSP)', 'Content Security Policy (CSP)'); ?>
            </h6>
            <p class="text-muted small mb-3">
                <?php echo $t(
                    'Modern browsers मा script-src-elem + nonce ले XSS बाट inject भएको &lt;script&gt; रोक्छ। onclick अझै चल्छ। समस्या आए CSP Enforce वा Script Nonce बन्द गर्नुहोस्। Nginx मा deploy/nginx-security.conf include गर्न नबिर्सनुहोस्।',
                    'Modern browsers use script-src-elem + nonce to block XSS-injected &lt;script&gt; tags. onclick still works. If something breaks, turn off CSP Enforce or Script Nonce. On nginx, include deploy/nginx-security.conf.'
                ); ?>
            </p>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="csp_enforce" name="csp_enforce" value="1"
                       <?php echo $cspEnforce ? 'checked' : ''; ?>>
                <label class="form-check-label" for="csp_enforce">
                    <?php echo $t('CSP Enforce (बन्द = Report-Only)', 'CSP Enforce (off = Report-Only)'); ?>
                </label>
            </div>
            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" id="csp_script_nonce" name="csp_script_nonce" value="1"
                       <?php echo $cspScriptNonce ? 'checked' : ''; ?>>
                <label class="form-check-label" for="csp_script_nonce">
                    <?php echo $t('Script nonce (script-src-elem — XSS script block)', 'Script nonce (script-src-elem — block injected scripts)'); ?>
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
