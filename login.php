<?php
/**
 * Compatibility redirect — member login lives at member/login.php
 * (Old bookmarks / external links to /login.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
$dest = rtrim(defined('SITE_URL') ? SITE_URL : '/', '/') . '/member/login.php';
if ($qs !== '') {
    $dest .= '?' . $qs;
}

header('Location: ' . $dest, true, 301);
exit;
