<?php
/**
 * Legacy URL — KYM Excel import हटाइयो (confusion / mismatch)।
 * CBS → Members Import मात्र; KYM stub auto; बाँकी online/portal।
 */
require_once __DIR__ . '/../includes/config.php';

if (!isAdminLoggedIn()) {
    header('Location: ' . ADMIN_URL . 'index.php');
    exit;
}

if (function_exists('setFlash')) {
    setFlash('info', 'KYM Excel import हटाइएको छ। CBS डेटा Members Import मा हाल्नुहोस्; बाँकी online/portal बाट भर्नुहोस्।');
}
header('Location: member-import.php');
exit;
