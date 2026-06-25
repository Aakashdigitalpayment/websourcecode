<?php
/**
 * ════════════════════════════════════════════════════════════
 * MEMBER PANEL BOOTSTRAP — Global Error Guard (v2)
 * ════════════════════════════════════════════════════════════
 * सबै member/*.php files को सुरुमा यो file include गरिन्छ।
 * के गर्छ?
 *   - PHP fatal error → user-friendly Nepali error page (white-screen रोक्छ)
 *   - Unhandled exceptions लाई gracefully handle गर्छ
 *   - Production मा error details hide, log मा मात्र लेख्छ
 *   - Development mode मा detailed error देखाउँछ (?debug=1)
 * ════════════════════════════════════════════════════════════
 */

define('PORTAL', 'member');

require_once __DIR__ . '/../core/init.php';
if (function_exists('site_license_public_guard')) {
    site_license_public_guard();
}

/* Follow shared environment policy while keeping production safe by default. */
core_apply_runtime_error_policy();

/* Friendly fatal + exception handlers — shared core registrar */
$memberHome = (defined('SITE_URL') ? SITE_URL : '/') . 'member/login.php';
core_register_portal_fatal_handler([
    'title' => 'त्रुटि — Member Portal',
    'heading' => 'केहि गलत भयो',
    'message' => 'हाम्रो team लाई स्वतः सूचित गरियो। केहि समय पछि पुनः प्रयास गर्नुहोस्।',
    'home' => $memberHome,
    'buttonText' => 'लगिन पृष्ठमा फर्किनुहोस्',
    'logPrefix' => 'member-panel-fatal',
]);
core_register_portal_exception_handler('member-panel-exception');
