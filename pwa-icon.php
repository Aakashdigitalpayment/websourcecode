<?php
/**
 * Dynamic PWA icon — serves PNG resized from site favicon / logo.
 * Usage: pwa-icon.php?s=192  |  pwa-icon.php?s=512&maskable=1
 */
require_once __DIR__ . '/includes/config.php';
if (is_file(__DIR__ . '/includes/pwa-icons.php')) {
    require_once __DIR__ . '/includes/pwa-icons.php';
}

$size = (int) ($_GET['s'] ?? $_GET['size'] ?? 192);
$maskable = isset($_GET['maskable']) && (string) $_GET['maskable'] !== '0';

if (!function_exists('pwaServeIcon')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PWA icon helper missing.';
    exit;
}

pwaServeIcon($size, $maskable);
