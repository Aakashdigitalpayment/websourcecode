<?php
/**
 * Compatibility redirect — old bookmarks pointing to /admin/login.php
 * Real login lives at /admin/index.php
 */
$target = '/admin/';
if (is_file(__DIR__ . '/../includes/config.php')) {
    require_once __DIR__ . '/../includes/config.php';
    if (defined('ADMIN_URL') && ADMIN_URL !== '') {
        $target = rtrim((string) ADMIN_URL, '/') . '/';
    }
}
header('Location: ' . $target, true, 301);
exit;
