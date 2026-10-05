<?php
/**
 * Thin admin page boot — safe incremental alternative to raw config.php.
 *
 * Use at top of admin/*.php BEFORE business logic:
 *   require_once __DIR__ . '/includes/admin-page-boot.php';
 *
 * Loads: PORTAL + config + boot-shared + requireAdminLogin.
 * Does NOT run core/init CSRF (admin-header still owns CSRF) — avoids
 * double-verify when migrating off mass admin/_bootstrap.php.
 *
 * AJAX/export endpoints that need custom 401 JSON may set before include:
 *   $GLOBALS['ADMIN_PAGE_BOOT_SKIP_LOGIN'] = true;
 */
if (defined('ADMIN_PAGE_BOOT_LOADED')) {
    return;
}
define('ADMIN_PAGE_BOOT_LOADED', true);

if (!defined('PORTAL')) {
    define('PORTAL', 'admin');
}
if (!defined('IS_ADMIN_PAGE')) {
    define('IS_ADMIN_PAGE', true);
}

require_once __DIR__ . '/../../includes/config.php';

$__bootShared = __DIR__ . '/../../includes/boot-shared.php';
if (is_file($__bootShared)) {
    require_once $__bootShared;
    if (function_exists('coop_require_boot_shared')) {
        coop_require_boot_shared();
    }
}
unset($__bootShared);

/* Public chrome cache helpers — available before admin-header for POST-before-header pages */
$__simpleCache = __DIR__ . '/../../includes/simple-cache.php';
if (is_file($__simpleCache)) {
    require_once $__simpleCache;
}
unset($__simpleCache);

$__coreHelpers = __DIR__ . '/../../core/helpers.php';
if (is_file($__coreHelpers)) {
    require_once $__coreHelpers;
}
unset($__coreHelpers);

if (empty($GLOBALS['ADMIN_PAGE_BOOT_SKIP_LOGIN']) && !defined('ADMIN_PAGE_BOOT_SKIP_LOGIN')) {
    if (function_exists('requireAdminLogin')) {
        requireAdminLogin();
    }
    /* Page-level RBAC (SSOT map in includes/auth-roles.php) — editor/staff stay on content pages */
    $__authRoles = __DIR__ . '/../../includes/auth-roles.php';
    if (is_file($__authRoles)) {
        require_once $__authRoles;
        $__minRole = function_exists('coop_admin_page_min_role')
            ? coop_admin_page_min_role(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')))
            : null;
        if ($__minRole !== null && function_exists('require_role')) {
            require_role($__minRole);
        }
        unset($__minRole);
        /* Custom roles: per-menu view / create / edit / delete (no-op for other users) */
        if (function_exists('coop_perm_enforce_request')) {
            coop_perm_enforce_request();
        }
    }
    unset($__authRoles);
}
