<?php
$GLOBALS['ADMIN_PAGE_BOOT_SKIP_LOGIN'] = true;
require_once __DIR__ . '/includes/admin-page-boot.php';

// Log the logout
if (isAdminLoggedIn()) {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO activity_log (admin_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['admin_id'], 'logout', 'Admin logged out', $_SERVER['REMOTE_ADDR']]);
    } catch (Exception $e) {
        // Ignore logging errors
    }
}

// Destroy session
session_unset();
session_destroy();

// Redirect to login
redirect('index.php');
?>
