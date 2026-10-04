<?php
/**
 * QR image drawn locally in the browser (assets/js/totp-qr.js) — the encoded link never goes
 * to a third-party QR API. Scripts are printed once per page.
 */
declare(strict_types=1);

if (!function_exists('coop_qr_scripts_html')) {
    function coop_qr_scripts_html(): string
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;
        $base = rtrim(defined('SITE_URL') ? SITE_URL : '/', '/') . '/';
        $ver = static fn (string $rel): string => function_exists('coopThemeCssVer') ? (string) coopThemeCssVer($rel) : '1';
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        return '<script src="' . $h($base . 'assets/vendor/qrcode-generator.js?v=' . $ver('assets/vendor/qrcode-generator.js')) . '" defer></script>'
            . '<script src="' . $h($base . 'assets/js/totp-qr.js?v=' . $ver('assets/js/totp-qr.js')) . '" defer></script>';
    }
}

if (!function_exists('coop_qr_img_tag')) {
    function coop_qr_img_tag(string $data, int $size = 120, string $alt = 'QR', string $class = '', string $style = ''): string
    {
        $size = max(48, min(480, $size));
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        return '<img hidden data-qr="' . $h($data) . '" alt="' . $h($alt) . '" width="' . $size . '" height="' . $size . '"'
            . ($class !== '' ? ' class="' . $h($class) . '"' : '')
            . ($style !== '' ? ' style="' . $h($style) . '"' : '') . '>' . coop_qr_scripts_html();
    }
}
