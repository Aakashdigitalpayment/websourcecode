<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/member-auth.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Member Session Debug ===\n";
echo "Time: " . date('Y-m-d H:i:s') . " (UTC)\n";
echo "PHP Session ID: " . session_id() . "\n";
echo "member_id: " . ($_SESSION['member_id'] ?? 'NOT SET') . "\n";
echo "member_last_activity: " . ($_SESSION['member_last_activity'] ?? 'NOT SET') . "\n";
$last = (int)($_SESSION['member_last_activity'] ?? 0);
if ($last > 0) {
    echo "Idle seconds: " . (time() - $last) . " (limit: 7200)\n";
    echo "Expired? " . ((time() - $last) > 7200 ? 'YES' : 'NO') . "\n";
}
echo "agent_hash stored: " . ($_SESSION['member_agent_hash'] ?? 'NOT SET') . "\n";
echo "current UA hash: " . substr(md5($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 16) . "\n";
echo "ip_partial stored: " . ($_SESSION['member_ip_partial'] ?? 'NOT SET') . "\n";
echo "current IP /24: " . implode('.', array_slice(explode('.', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 3)) . "\n";
echo "memberIsLoggedIn(): " . (memberIsLoggedIn() ? 'TRUE' : 'FALSE') . "\n";
echo "SITE_URL: " . (defined('SITE_URL') ? SITE_URL : 'NOT DEFINED') . "\n";
echo "scan.php exists: " . (file_exists(__DIR__ . '/scan.php') ? 'YES' : 'NO') . "\n";
