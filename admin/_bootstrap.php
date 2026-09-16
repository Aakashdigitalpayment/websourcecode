<?php
/**
 * ════════════════════════════════════════════════════════════
 * ADMIN PANEL BOOTSTRAP — Dashboard error guard (v2)
 * ════════════════════════════════════════════════════════════
 * Used by admin/dashboard.php (intentionally NOT thin page-boot):
 *   - core/init.php → config + CSRF + boot-shared + session
 *   - $pdo global for dashboard queries
 *   - Friendly fatal / exception handlers (admin shell)
 *
 * Other admin pages use includes/admin-page-boot.php (no core/init
 * double-CSRF). Do not mass-migrate dashboard off this file without
 * preserving fatal handlers + $pdo.
 * ════════════════════════════════════════════════════════════
 */

if (!defined('PORTAL')) {
    define('PORTAL', 'admin');
}
if (!defined('IS_ADMIN_PAGE')) {
    define('IS_ADMIN_PAGE', true);
}

require_once __DIR__ . '/../core/init.php';

/* Belt-and-suspenders — core/init already loads boot-shared (idempotent). */
$__bootShared = __DIR__ . '/../includes/boot-shared.php';
if (is_file($__bootShared)) {
    require_once $__bootShared;
    if (function_exists('coop_require_boot_shared')) {
        coop_require_boot_shared();
    }
}
unset($__bootShared);

/* Global $pdo — dashboard queries */
try {
    $pdo = isset($db) && $db instanceof PDO ? $db : getDB();
} catch (Throwable $e) {
    error_log('[admin-bootstrap-db-fail] ' . $e->getMessage());
    $pdo = null;
}

/* Follow shared environment policy while keeping production safe by default. */
if (function_exists('core_apply_runtime_error_policy')) {
    core_apply_runtime_error_policy();
}

/* Friendly fatal + exception handlers — shared core registrar */
if (function_exists('core_register_portal_fatal_handler')) {
    core_register_portal_fatal_handler([
        'title' => 'त्रुटि — Admin Panel',
        'heading' => 'केहि गलत भयो',
        'message' => 'Admin panel मा अप्रत्याशित त्रुटि भयो। केहि समय पछि पुनः प्रयास गर्नुहोस्।',
        'home' => (defined('ADMIN_URL') ? ADMIN_URL : '/admin/'),
        'buttonText' => 'लगिन पृष्ठमा फर्किनुहोस्',
        'logPrefix' => 'admin-panel-fatal',
    ]);
}
if (function_exists('core_register_portal_exception_handler')) {
    core_register_portal_exception_handler('admin-panel-exception');
}
