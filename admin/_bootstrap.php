<?php
/**
 * ════════════════════════════════════════════════════════════
 * ADMIN PANEL BOOTSTRAP — Global Error Guard (v1)
 * ════════════════════════════════════════════════════════════
 * सबै admin/*.php files को सुरुमा यो file include गरिन्छ।
 * के गर्छ?
 *   - includes/config.php load गर्छ (getDB, requireAdminLogin, etc.)
 *   - $pdo global PDO connection उपलब्ध गराउँछ
 *   - PHP fatal error → user-friendly Nepali error page
 *   - Production मा error details hide, log मा मात्र लेख्छ
 *   - Debug mode (?debug=1) मा detailed error देखाउँछ
 * ════════════════════════════════════════════════════════════
 */

define('PORTAL', 'admin');
define('IS_ADMIN_PAGE', true);

require_once __DIR__ . '/../core/init.php';

/* Global $pdo — admin pages ले direct query गर्न मिल्ने गरि */
try {
    $pdo = isset($db) && $db instanceof PDO ? $db : getDB();
} catch (Throwable $e) {
    error_log('[admin-bootstrap-db-fail] ' . $e->getMessage());
    $pdo = null;
}

/* Follow shared environment policy while keeping production safe by default. */
core_apply_runtime_error_policy();

/* Friendly fatal + exception handlers — shared core registrar */
core_register_portal_fatal_handler([
    'title' => 'त्रुटि — Admin Panel',
    'heading' => 'केहि गलत भयो',
    'message' => 'Admin panel मा अप्रत्याशित त्रुटि भयो। केहि समय पछि पुनः प्रयास गर्नुहोस्।',
    'home' => (defined('ADMIN_URL') ? ADMIN_URL : '/admin/'),
    'buttonText' => 'लगिन पृष्ठमा फर्किनुहोस्',
    'logPrefix' => 'admin-panel-fatal',
]);
core_register_portal_exception_handler('admin-panel-exception');
