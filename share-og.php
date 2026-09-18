<?php
/**
 * Landscape Open Graph / share card (1200×630).
 * Used when admin has not uploaded seo_og_image — avoids square-logo Facebook crop.
 *
 * Usage: share-og.php  (cached JPEG)
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$w = 1200;
$h = 630;

if (!function_exists('imagecreatetruecolor')) {
    /* No GD — redirect to logo so og:image still resolves */
    $logo = function_exists('getLocalizedLogoPath')
        ? (string) getLocalizedLogoPath('assets/images/icon-512x512.png')
        : trim((string) (function_exists('getSetting') ? getSetting('site_logo', 'assets/images/icon-512x512.png') : 'assets/images/icon-512x512.png'));
    $to = function_exists('seo_absolute_asset_url') ? seo_absolute_asset_url($logo) : (rtrim((string) SITE_URL, '/') . '/' . ltrim($logo, '/'));
    header('Location: ' . $to, true, 302);
    exit;
}

$root = defined('ROOT_PATH') ? rtrim((string) ROOT_PATH, "/\\") . DIRECTORY_SEPARATOR : (__DIR__ . DIRECTORY_SEPARATOR);

$logoRel = function_exists('getLocalizedLogoPath')
    ? trim((string) getLocalizedLogoPath('assets/images/icon-512x512.png'))
    : trim((string) (function_exists('getSetting') ? getSetting('site_logo', getSetting('logo', 'assets/images/icon-512x512.png')) : 'assets/images/icon-512x512.png'));
$logoRel = ltrim(str_replace('\\', '/', $logoRel), '/');
if ($logoRel === '' || str_contains($logoRel, '..') || preg_match('#^https?://#i', $logoRel)) {
    $logoRel = 'assets/images/icon-512x512.png';
}
$logoAbs = $root . str_replace('/', DIRECTORY_SEPARATOR, $logoRel);
if (!is_file($logoAbs)) {
    foreach (['assets/images/icon-512x512.png', 'assets/images/icon-192x192.png', 'assets/images/logo.png'] as $fallback) {
        $try = $root . str_replace('/', DIRECTORY_SEPARATOR, $fallback);
        if (is_file($try)) {
            $logoAbs = $try;
            $logoRel = $fallback;
            break;
        }
    }
}

$primary = trim((string) (function_exists('getSetting') ? getSetting('primary_color', '#1a5f2a') : '#1a5f2a'));
if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $primary)) {
    $primary = '#1a5f2a';
}
$siteName = trim((string) (function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी'));
if ($siteName === '') {
    $siteName = 'सहकारी';
}

/* Light brand colors look empty in Facebook preview — darken for the card frame */
$pr = hexdec(substr($primary, 1, 2));
$pg = hexdec(substr($primary, 3, 2));
$pb = hexdec(substr($primary, 5, 2));
$lum = (0.2126 * $pr + 0.7152 * $pg + 0.0722 * $pb) / 255;
if ($lum > 0.55) {
    $pr = (int) max(0, round($pr * 0.35));
    $pg = (int) max(0, round($pg * 0.35));
    $pb = (int) max(0, round($pb * 0.35));
}

$logoMtime = is_file($logoAbs) ? (int) (@filemtime($logoAbs) ?: 0) : 0;
$cacheDir = $root . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'og_cards' . DIRECTORY_SEPARATOR;
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$cacheKey = substr(hash('sha256', $logoRel . '|' . $logoMtime . '|' . $primary . '|' . $siteName . '|v5jpg'), 0, 28);
$cacheFile = $cacheDir . $cacheKey . '.jpg';

if (!is_file($cacheFile)) {
    $canvas = imagecreatetruecolor($w, $h);
    if (!$canvas) {
        http_response_code(500);
        exit;
    }

    $bg = imagecolorallocate($canvas, $pr, $pg, $pb);
    imagefilledrectangle($canvas, 0, 0, $w, $h, $bg);

    /* Opaque white panel — readable for any logo (including wide wordmarks) */
    $panel = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 64, 64, $w - 64, $h - 64, $panel);

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

    $drewLogo = false;
    if ($src) {
        imagealphablending($src, true);
        imagesavealpha($src, true);
        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw > 0 && $sh > 0) {
            /* Wide banner logos (e.g. 1600×284) need a large max width or they look empty */
            $maxW = 920;
            $maxH = 360;
            $scale = min($maxW / $sw, $maxH / $sh, 1.0);
            $dw = (int) max(1, round($sw * $scale));
            $dh = (int) max(1, round($sh * $scale));
            $dx = (int) (($w - $dw) / 2);
            $dy = (int) (($h - $dh) / 2) - 12;
            imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
            $drewLogo = true;
        }
        imagedestroy($src);
    }

    if (!$drewLogo) {
        /* Fallback label when logo file is missing */
        $ink = imagecolorallocate($canvas, 30, 30, 30);
        $label = function_exists('mb_substr') ? (string) mb_substr($siteName, 0, 48, 'UTF-8') : substr($siteName, 0, 48);
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $label) ?: 'Cooperative';
        $font = 5;
        $tw = imagefontwidth($font) * strlen($ascii);
        $th = imagefontheight($font);
        imagestring($canvas, $font, (int) (($w - $tw) / 2), (int) (($h - $th) / 2), $ascii, $ink);
    }

    @imagejpeg($canvas, $cacheFile, 88);
    imagedestroy($canvas);
}

if (!is_file($cacheFile)) {
    http_response_code(500);
    exit;
}

$mtime = (int) (@filemtime($cacheFile) ?: time());
$etag = '"' . md5($cacheFile . '|' . $mtime) . '"';
header('Content-Type: image/jpeg');
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
