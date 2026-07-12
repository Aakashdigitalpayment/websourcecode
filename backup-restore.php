<?php
/**
 * Deprecated public/root entry — backup/restore lives in the admin panel only.
 * Kept as a safe redirect so old bookmarks do not break.
 */
require_once __DIR__ . '/_bootstrap.php';
if (!function_exists('isAdminLoggedIn') || !isAdminLoggedIn()) {
    if (defined('ADMIN_URL')) {
        header('Location: ' . ADMIN_URL . 'index.php');
    } else {
        header('Location: admin/index.php');
    }
    exit;
}
$target = (defined('ADMIN_URL') ? ADMIN_URL : 'admin/') . 'backup-restore.php';
header('Location: ' . $target, true, 302);
exit;
