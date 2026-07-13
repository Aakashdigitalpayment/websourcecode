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
header('Cache-Control: public, max-age=300');

$appName    = function_exists('getSetting') ? trim((string) getSetting('pwa_app_name',  '')) : '';
$shortName  = function_exists('getSetting') ? trim((string) getSetting('pwa_short_name', '')) : '';
$themeColor = function_exists('getSetting') ? trim((string) getSetting('primary_color',  '#1a5f2a')) : '#1a5f2a';

if ($appName   === '') $appName   = function_exists('getSetting') ? trim((string) getSetting('site_name', 'सहकारी HRM & CMS System')) : 'सहकारी HRM & CMS System';
if ($shortName === '') $shortName = function_exists('getSetting') ? trim((string) getSetting('site_name_en', 'HRM System')) : 'HRM System';
if ($themeColor === '' || !preg_match('/^#[A-Fa-f0-9]{3,6}$/', $themeColor)) $themeColor = '#1a5f2a';

/* Chrome omnibox install needs valid PNG icons (192 + 512) with matching MIME. */
$manifest = [
    'id'           => '/',
    'name'         => $appName,
    'short_name'   => $shortName,
    'description'  => 'सहकारी संस्थाको लागि समग्र मानव संशाधन तथा सामग्री व्यवस्थापन प्रणाली',
    'start_url'    => '/?source=pwa',
    'scope'        => '/',
    'display'      => 'standalone',
    'orientation'  => 'any',
    'theme_color'  => $themeColor,
    'background_color' => '#ffffff',
    'lang'         => 'ne',
    'dir'          => 'ltr',
    'prefer_related_applications' => false,
    'icons' => [
        ['src' => '/assets/images/icon-72x72.png',  'sizes' => '72x72',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-96x96.png',  'sizes' => '96x96',   'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-128x128.png','sizes' => '128x128', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-144x144.png','sizes' => '144x144', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-152x152.png','sizes' => '152x152', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-192x192.png','sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-384x384.png','sizes' => '384x384', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-512x512.png','sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/images/icon-512x512-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'categories' => ['finance', 'business', 'productivity'],
    'shortcuts' => [
        [
            'name'        => 'Home',
            'short_name'  => 'Home',
            'description' => 'मुख्य पृष्ठ',
            'url'         => '/index.php',
            'icons'       => [['src' => '/assets/images/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']],
        ],
        [
            'name'        => 'Services',
            'short_name'  => 'Services',
            'description' => 'सेवाहरू',
            'url'         => '/services.php',
            'icons'       => [['src' => '/assets/images/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']],
        ],
        [
            'name'        => 'Contact',
            'short_name'  => 'Contact',
            'description' => 'सम्पर्क',
            'url'         => '/contact.php',
            'icons'       => [['src' => '/assets/images/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png']],
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
