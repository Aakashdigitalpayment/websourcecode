<?php
/**
 * Dynamic PWA manifest — app name + cooperative branding icons from settings.
 */
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', __DIR__ . '/');
}
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/pwa-icons.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$appName    = function_exists('getSetting') ? trim((string) getSetting('pwa_app_name',  '')) : '';
$shortName  = function_exists('getSetting') ? trim((string) getSetting('pwa_short_name', '')) : '';
$themeColor = function_exists('getSetting') ? trim((string) getSetting('primary_color',  '#1a5f2a')) : '#1a5f2a';
$siteName   = function_exists('getSetting') ? trim((string) getSetting('site_name', 'सहकारी')) : 'सहकारी';
$desc       = function_exists('getSetting') ? trim((string) getSetting('meta_description', '')) : '';

if ($appName   === '') $appName   = $siteName !== '' ? $siteName : 'सहकारी App';
if ($shortName === '') $shortName = $siteName !== '' ? mb_substr($siteName, 0, 12) : 'सहकारी';
if ($themeColor === '' || !preg_match('/^#[A-Fa-f0-9]{3,6}$/', $themeColor)) $themeColor = '#1a5f2a';
if ($desc === '') {
    $desc = $siteName . ' — सदस्य सेवा र सूचना';
}

$base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
$startUrl = ($base !== '' ? $base : '') . '/?source=pwa';
$scope = ($base !== '' ? $base : '') . '/';

$icon = static function (int $s, bool $mask = false) use ($base): array {
    $url = function_exists('getPwaIconPublicUrl')
        ? getPwaIconPublicUrl($s, $mask)
        : ($base . '/assets/images/icon-' . $s . 'x' . $s . '.png');
    return [
        'src'     => $url,
        'sizes'   => $s . 'x' . $s,
        'type'    => 'image/png',
        'purpose' => $mask ? 'maskable' : 'any',
    ];
};

$icon192 = $icon(192, false);
$manifest = [
    'id'               => $scope,
    'name'             => $appName,
    'short_name'       => $shortName,
    'description'      => $desc,
    'start_url'        => $startUrl,
    'scope'            => $scope,
    'display'          => 'standalone',
    'orientation'      => 'any',
    'theme_color'      => $themeColor,
    'background_color' => '#ffffff',
    'lang'             => 'ne',
    'dir'              => 'ltr',
    'prefer_related_applications' => false,
    'icons' => [
        $icon(72),
        $icon(96),
        $icon(128),
        $icon(144),
        $icon(152),
        $icon192,
        $icon(384),
        $icon(512),
        $icon(512, true),
    ],
    'categories' => ['finance', 'business', 'productivity'],
    'shortcuts' => [
        [
            'name'        => $siteName !== '' ? $siteName : 'Home',
            'short_name'  => 'Home',
            'description' => 'मुख्य पृष्ठ',
            'url'         => ($base !== '' ? $base : '') . '/index.php',
            'icons'       => [$icon192],
        ],
        [
            'name'        => 'Services',
            'short_name'  => 'Services',
            'description' => 'सेवाहरू',
            'url'         => ($base !== '' ? $base : '') . '/services.php',
            'icons'       => [$icon192],
        ],
        [
            'name'        => 'Contact',
            'short_name'  => 'Contact',
            'description' => 'सम्पर्क',
            'url'         => ($base !== '' ? $base : '') . '/contact.php',
            'icons'       => [$icon192],
        ],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
