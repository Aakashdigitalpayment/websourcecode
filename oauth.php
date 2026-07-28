<?php
/**
 * Compatibility redirect — OAuth callbacks live at member/oauth.php
 * (Google/Facebook redirect URIs in admin settings use the member path).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
$dest = rtrim(defined('SITE_URL') ? SITE_URL : '/', '/') . '/member/oauth.php';
if ($qs !== '') {
    $dest .= '?' . $qs;
}

header('Location: ' . $dest, true, 301);
exit;
