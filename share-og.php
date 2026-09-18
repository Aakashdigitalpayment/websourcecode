<?php
/**
 * Landscape Open Graph / share card (1200×630).
 * Used when admin has not uploaded seo_og_image — avoids square-logo Facebook crop.
 *
 * Usage: share-og.php  (cached PNG)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$w = 1200;
$h = 630;

if (!function_exists('imagecreatetruecolor')) {
    /* No GD — redirect to logo so og:image still resolves */
    $logo = trim((string) (function_exists('getSetting') ? getSetting('site_logo', 'assets/images/logo.png') : 'assets/images/logo.png'));
    $to = function_exists('seo_absolute_asset_url') ? seo_absolute_asset_url($logo) : (rtrim((string) SITE_URL, '/') . '/' . ltrim($logo, '/'));
    header('Location: ' . $to, true, 302);
    exit;
}

$root = defined('ROOT_PATH') ? rtrim((string) ROOT_PATH, "/\\") . DIRECTORY_SEPARATOR : (__DIR__ . DIRECTORY_SEPARATOR);
$logoRel = trim((string) (function_exists('getSetting') ? getSetting('site_logo', 'assets/images/logo.png') : 'assets/images/logo.png'));
$logoRel = ltrim(str_replace('\\', '/', $logoRel), '/');
if ($logoRel === '' || str_contains($logoRel, '..')) {
    $logoRel = 'assets/images/logo.png';
}
$logoAbs = $root . str_replace('/', DIRECTORY_SEPARATOR, $logoRel);
if (!is_file($logoAbs)) {
    $logoAbs = $root . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'logo.png';
}

$primary = trim((string) (function_exists('getSetting') ? getSetting('primary_color', '#1a5f2a') : '#1a5f2a'));
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $primary)) {
    $primary = '#1a5f2a';
}
$siteName = trim((string) (function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी'));
if ($siteName === '') {
    $siteName = 'सहकारी';
}

$logoMtime = is_file($logoAbs) ? (int) (@filemtime($logoAbs) ?: 0) : 0;
$cacheDir = $root . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'og_cards' . DIRECTORY_SEPARATOR;
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$cacheKey = substr(hash('sha256', $logoRel . '|' . $logoMtime . '|' . $primary . '|v3'), 0, 28);
$cacheFile = $cacheDir . $cacheKey . '.png';

if (!is_file($cacheFile)) {
    $canvas = imagecreatetruecolor($w, $h);
    if (!$canvas) {
        http_response_code(500);
        exit;
    }

    $pr = hexdec(substr($primary, 1, 2));
    $pg = hexdec(substr($primary, 3, 2));
    $pb = hexdec(substr($primary, 5, 2));
    $bg = imagecolorallocate($canvas, $pr, $pg, $pb);
    imagefilledrectangle($canvas, 0, 0, $w, $h, $bg);

    /* Soft light panel so the logo stays readable on any brand color */
    $panel = imagecolorallocatealpha($canvas, 255, 255, 255, 96);
    imagealphablending($canvas, true);
    imagefilledrectangle($canvas, 72, 72, $w - 72, $h - 72, $panel);

    $src = null;
    if (is_file($logoAbs)) {
        $info = @getimagesize($logoAbs);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($logoAbs),
            'image/png' => @imagecreatefrompng($logoAbs),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($logoAbs) : null,
            'image/gif' => @imagecreatefromgif($logoAbs),
            default => null,
        };
    }

    if ($src) {
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw > 0 && $sh > 0) {
            $maxLogo = 420;
            $scale = min($maxLogo / $sw, $maxLogo / $sh, 1.0);
            $dw = (int) max(1, round($sw * $scale));
            $dh = (int) max(1, round($sh * $scale));
            $dx = (int) (($w - $dw) / 2);
            $dy = (int) (($h - $dh) / 2);
            imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        }
        imagedestroy($src);
    }

    @imagepng($canvas, $cacheFile, 6);
    imagedestroy($canvas);
}

if (!is_file($cacheFile)) {
    http_response_code(500);
    exit;
}

$mtime = (int) (@filemtime($cacheFile) ?: time());
$etag = '"' . md5($cacheFile . '|' . $mtime) . '"';
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('X-Robots-Tag: noindex');
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . (string) filesize($cacheFile));
readfile($cacheFile);
exit;
