<?php
/**
 * Compatibility redirect — member password reset lives under /member/
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
$dest = rtrim(defined('SITE_URL') ? SITE_URL : '/', '/') . '/member/password-reset-request.php';
if ($qs !== '') {
    $dest .= '?' . $qs;
}

header('Location: ' . $dest, true, 301);
exit;
