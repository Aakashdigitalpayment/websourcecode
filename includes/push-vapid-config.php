<?php
/**
 * Web Push VAPID keys — per-install overrides via push-vapid.local.php (gitignored).
 * Fallback keys below keep existing installs working until a local file is added.
 */
declare(strict_types=1);

$__vapidLocal = __DIR__ . '/push-vapid.local.php';
if (is_file($__vapidLocal)) {
    require_once $__vapidLocal;
}

if (!defined('COOP_VAPID_PUBLIC_KEY')) {
    define(
        'COOP_VAPID_PUBLIC_KEY',
        'BGBgAPEKj2nvCF8aAxIn1Vw1rMo_2YQKFsR2W2E-L38e1HDA8QLIzMgtjz9Kvze7-rfVzj8_c6Glrd-KEtgxDUo'
    );
}

if (!defined('COOP_VAPID_PRIVATE_PEM')) {
    define(
        'COOP_VAPID_PRIVATE_PEM',
        "-----BEGIN EC PRIVATE KEY-----\n" .
        "MHcCAQEEIGq4QLbnsW8dGTUchWXlUxaFOT05u45rMoKD5hBIyJbioAoGCCqGSM49\n" .
        "AwEHoUQDQgAEYGAA8QqPae8IXxoDEifVXDWsyj/ZhAoWxHZbYT4vfx7UcMDxAsjM\n" .
        "yC2PP0q/N7v6t9XOPz9zoaWt34oS2DENSg==\n" .
        "-----END EC PRIVATE KEY-----"
    );
}

if (!defined('COOP_VAPID_SUBJECT')) {
    define(
        'COOP_VAPID_SUBJECT',
        function_exists('getSetting')
            ? ('mailto:' . (getSetting('admin_email', '') ?: 'admin@aakashcooperative.com'))
            : 'mailto:admin@aakashcooperative.com'
    );
}
