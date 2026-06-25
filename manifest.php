<?php
/**
 * Dynamic PWA manifest — reads app name from site_settings DB table.
 * Admin can update pwa_app_name and pwa_short_name via Settings → Site Information.
 */
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__ . '/');
}
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$appName    = function_exists('getSetting') ? trim((string) getSetting('pwa_app_name',  '')) : '';
$shortName  = function_exists('getSetting') ? trim((string) getSetting('pwa_short_name', '')) : '';
$themeColor = function_exists('getSetting') ? trim((string) getSetting('primary_color',  '#1a5f2a')) : '#1a5f2a';

if ($appName   === '') $appName   = function_exists('getSetting') ? trim((string) getSetting('site_name', 'सहकारी HRM & CMS System')) : 'सहकारी HRM & CMS System';
if ($shortName === '') $shortName = function_exists('getSetting') ? trim((string) getSetting('site_name_en', 'HRM System')) : 'HRM System';
if ($themeColor === '' || !preg_match('/^#[A-Fa-f0-9]{3,6}$/', $themeColor)) $themeColor = '#1a5f2a';

$manifest = [
    'name'         => $appName,
    'short_name'   => $shortName,
    'description'  => 'सहकारी संस्थाको लागि समग्र मानव संशाधन तथा सामग्री व्यवस्थापन प्रणाली',
    'start_url'    => '/index.php',
    'scope'        => '/',
    'display'      => 'standalone',
    'orientation'  => 'portrait-primary',
    'theme_color'  => $themeColor,
    'background_color' => '#ffffff',
    'prefer_related_applications' => false,
    'icons' => [
        ['src' => '/assets/images/icon-72x72.png',  'sizes' => '72x72',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-96x96.png',  'sizes' => '96x96',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-128x128.png','sizes' => '128x128', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-144x144.png','sizes' => '144x144', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-152x152.png','sizes' => '152x152', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-192x192.png','sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-384x384.png','sizes' => '384x384', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-512x512.png','sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
    'screenshots' => [
        ['src' => '/assets/images/screenshot-narrow.png', 'sizes' => '540x720',  'type' => 'image/png', 'form_factor' => 'narrow'],
        ['src' => '/assets/images/screenshot-wide.png',   'sizes' => '1280x720', 'type' => 'image/png', 'form_factor' => 'wide'],
    ],
    'categories' => ['productivity', 'business'],
    'shortcuts' => [
        [
            'name'        => 'Dashboard',
            'short_name'  => 'Dashboard',
            'description' => 'मुख्य Dashboard',
            'url'         => '/admin/dashboard.php',
            'icons'       => [['src' => '/assets/images/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']],
        ],
        [
            'name'        => 'Members',
            'short_name'  => 'Members',
            'description' => 'सदस्य व्यवस्थापन',
            'url'         => '/admin/members.php',
            'icons'       => [['src' => '/assets/images/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']],
        ],
        [
            'name'        => 'Loans',
            'short_name'  => 'Loans',
            'description' => 'ऋण आवेदन',
            'url'         => '/admin/loan-applications.php',
            'icons'       => [['src' => '/assets/images/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']],
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
