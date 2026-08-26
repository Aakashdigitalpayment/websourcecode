<?php
/**
 * PWA icon helpers — resolve cooperative logo/favicon and serve sized PNGs.
 */

if (!function_exists('getSitePwaIconSourcePath')) {
    /**
     * Prefer: site_favicon → localized logo → site_logo → logo → default PWA icon
     */
    function getSitePwaIconSourcePath(): string
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
        $candidates = [];

        $favicon = trim((string) (function_exists('getSetting') ? getSetting('site_favicon', '') : ''));
        if ($favicon !== '') {
            $candidates[] = ltrim($favicon, '/');
        }
        if (function_exists('getLocalizedLogoPath')) {
            $candidates[] = ltrim((string) getLocalizedLogoPath(''), '/');
        }
        foreach (['site_logo', 'logo', 'logo_np', 'logo_en'] as $key) {
            $v = trim((string) (function_exists('getSetting') ? getSetting($key, '') : ''));
            if ($v !== '') {
                $candidates[] = ltrim($v, '/');
            }
        }
        $candidates[] = 'assets/images/icon-512x512.png';
        $candidates[] = 'assets/images/icon-192x192.png';

        foreach ($candidates as $path) {
            if ($path === '' || preg_match('#^https?://#i', $path)) {
                continue;
            }
            if (is_file($root . $path)) {
                return $path;
            }
        }
        return 'assets/images/icon-192x192.png';
    }
}

if (!function_exists('getPwaIconPublicUrl')) {
    function getPwaIconPublicUrl(int $size = 192, bool $maskable = false): string
    {
        $size = pwaClampIconSize($size);
        $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') . '/' : '/';
        $srcPath = function_exists('getSitePwaIconSourcePath') ? getSitePwaIconSourcePath() : 'assets/images/icon-192x192.png';
        $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
        $ver = @filemtime($root . ltrim($srcPath, '/')) ?: time();
        $q = 's=' . $size . '&v=' . (int) $ver;
        if ($maskable) {
            $q .= '&maskable=1';
        }
        return $base . 'pwa-icon.php?' . $q;
    }
}

if (!function_exists('pwaClampIconSize')) {
    function pwaClampIconSize(int $size): int
    {
        $allowed = [72, 96, 128, 144, 152, 180, 192, 256, 384, 512];
        if (in_array($size, $allowed, true)) {
            return $size;
        }
        /* nearest */
        $best = 192;
        $bestDiff = PHP_INT_MAX;
        foreach ($allowed as $a) {
            $d = abs($a - $size);
            if ($d < $bestDiff) {
                $bestDiff = $d;
                $best = $a;
            }
        }
        return $best;
    }
}

if (!function_exists('pwaClearIconCache')) {
    function pwaClearIconCache(): void
    {
        $dir = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__) . '/') . 'assets/uploads/pwa_icons/';
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '*.png') ?: [] as $f) {
            @unlink($f);
        }
    }
}

if (!function_exists('pwaServeIcon')) {
    function pwaServeIcon(int $size, bool $maskable = false): void
    {
        $size = pwaClampIconSize($size);
        $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
        $relSrc = getSitePwaIconSourcePath();
        $absSrc = $root . ltrim($relSrc, '/');

        /* Static default icons — serve as-is when no custom branding file */
        $isDefaultPack = (bool) preg_match('#^assets/images/icon-\d#', $relSrc);
        if ($isDefaultPack && !$maskable) {
            $static = $root . 'assets/images/icon-' . $size . 'x' . $size . '.png';
            if (is_file($static)) {
                pwaEmitPngFile($static);
                return;
            }
            if ($size === 512 && is_file($root . 'assets/images/icon-512x512.png')) {
                pwaEmitPngFile($root . 'assets/images/icon-512x512.png');
                return;
            }
        }
        if ($isDefaultPack && $maskable && is_file($root . 'assets/images/icon-512x512-maskable.png')) {
            if ($size === 512) {
                pwaEmitPngFile($root . 'assets/images/icon-512x512-maskable.png');
                return;
            }
        }

        if (!is_file($absSrc) || !function_exists('imagecreatetruecolor')) {
            $fallback = $root . 'assets/images/icon-' . ($size >= 384 ? '512' : '192') . 'x' . ($size >= 384 ? '512' : '192') . '.png';
            if (is_file($fallback)) {
                pwaEmitPngFile($fallback);
                return;
            }
            http_response_code(404);
            exit;
        }

        $mtime = (int) (@filemtime($absSrc) ?: 0);
        $cacheDir = $root . 'assets/uploads/pwa_icons/';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheKey = substr(hash('sha256', $relSrc . '|' . $mtime . '|' . $size . '|' . ($maskable ? 'm' : 'a')), 0, 24);
        $cacheFile = $cacheDir . $cacheKey . '.png';

        if (!is_file($cacheFile)) {
            $ok = pwaBuildIconPng($absSrc, $cacheFile, $size, $maskable);
            if (!$ok) {
                $fallback = $root . 'assets/images/icon-192x192.png';
                if (is_file($fallback)) {
                    pwaEmitPngFile($fallback);
                    return;
                }
                http_response_code(500);
                exit;
            }
        }

        pwaEmitPngFile($cacheFile);
    }
}

if (!function_exists('pwaEmitPngFile')) {
    function pwaEmitPngFile(string $path): void
    {
        $mtime = @filemtime($path) ?: time();
        $etag = '"' . md5($path . '|' . $mtime) . '"';
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }
}

if (!function_exists('pwaBuildIconPng')) {
    function pwaBuildIconPng(string $srcPath, string $destPath, int $size, bool $maskable): bool
    {
        $info = @getimagesize($srcPath);
        if ($info === false) {
            return false;
        }
        $mime = $info['mime'] ?? '';
        $src = null;
        switch ($mime) {
            case 'image/jpeg':
                $src = @imagecreatefromjpeg($srcPath);
                break;
            case 'image/png':
                $src = @imagecreatefrompng($srcPath);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $src = @imagecreatefromwebp($srcPath);
                }
                break;
            case 'image/gif':
                $src = @imagecreatefromgif($srcPath);
                break;
        }
        if (!$src) {
            return false;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw < 1 || $sh < 1) {
            imagedestroy($src);
            return false;
        }

        $canvas = imagecreatetruecolor($size, $size);
        if (!$canvas) {
            imagedestroy($src);
            return false;
        }
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $theme = function_exists('getSetting') ? trim((string) getSetting('primary_color', '#1a5f2a')) : '#1a5f2a';
        if (!preg_match('/^#([A-Fa-f0-9]{6})$/', $theme, $m)) {
            $theme = '#1a5f2a';
            $m = [1 => '1a5f2a'];
        }
        $r = hexdec(substr($m[1], 0, 2));
        $g = hexdec(substr($m[1], 2, 2));
        $b = hexdec(substr($m[1], 4, 2));

        if ($maskable) {
            $bg = imagecolorallocate($canvas, $r, $g, $b);
            imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);
            imagealphablending($canvas, true);
            /* Safe zone ~80% for maskable */
            $inner = (int) floor($size * 0.72);
            $pad = (int) floor(($size - $inner) / 2);
            $scale = min($inner / $sw, $inner / $sh);
            $dw = max(1, (int) floor($sw * $scale));
            $dh = max(1, (int) floor($sh * $scale));
            $dx = $pad + (int) floor(($inner - $dw) / 2);
            $dy = $pad + (int) floor(($inner - $dh) / 2);
            imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        } else {
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $size, $size, $transparent);
            imagealphablending($canvas, true);
            /* Contain logo with small padding on transparent / soft brand wash */
            $wash = imagecolorallocatealpha($canvas, $r, $g, $b, 110);
            imagefilledrectangle($canvas, 0, 0, $size, $size, $wash);
            $pad = (int) floor($size * 0.08);
            $inner = $size - (2 * $pad);
            $scale = min($inner / $sw, $inner / $sh);
            $dw = max(1, (int) floor($sw * $scale));
            $dh = max(1, (int) floor($sh * $scale));
            $dx = (int) floor(($size - $dw) / 2);
            $dy = (int) floor(($size - $dh) / 2);
            imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        }

        imagesavealpha($canvas, true);
        $ok = imagepng($canvas, $destPath, 6);
        /* imagedestroy deprecated on PHP 8.5+ — GC frees GD objects */
        unset($canvas, $src);
        return (bool) $ok;
    }
}
