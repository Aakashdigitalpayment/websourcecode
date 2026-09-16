<?php
/**
 * Shared boot modules — used by public `_bootstrap.php`, `core/init.php`,
 * and admin-header path (pages that skip admin/_bootstrap).
 *
 * Safe unify: one require list, no portal CSRF / auth side-effects.
 */
if (!function_exists('coop_require_boot_shared')) {
    function coop_require_boot_shared(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $root = defined('ROOT_PATH')
            ? ROOT_PATH
            : ((defined('BASEDIR') ? BASEDIR : dirname(__DIR__)) . '/');
        $root = rtrim(str_replace('\\', '/', (string) $root), '/') . '/';

        foreach ([
            'includes/nepali-bs-convert.php',
            'includes/audit.php',
            'includes/notification-templates.php',
            'includes/auth-roles.php',
            'includes/panel-uniform.php',
            'includes/safe-query.php',
        ] as $rel) {
            $path = $root . $rel;
            if (is_file($path)) {
                require_once $path;
            }
        }
        $loaded = true;
    }
}
