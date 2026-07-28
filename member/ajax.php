<?php
/**
 * Member Portal — AJAX handler (read-only via GET; mutations via POST + CSRF)
 */
require_once '../includes/config.php';
require_once '../includes/member-auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!memberIsLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$db       = getDB();
$memberId = (int) $_SESSION['member_id'];
$method   = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action   = (string) (($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '')));

$readOnly = ['unread_count', 'count'];
$mutate   = ['mark_read', 'mark_notif_read'];

if (!in_array($action, array_merge($readOnly, $mutate), true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

if (in_array($action, $mutate, true)) {
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required']);
        exit;
    }
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Security check failed']);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $db->prepare('UPDATE member_notifications SET is_read=1 WHERE id=? AND member_id=?')
           ->execute([$id, $memberId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$cnt = 0;
try {
    $st = $db->prepare('SELECT COUNT(*) FROM member_notifications WHERE member_id=? AND is_read=0');
    $st->execute([$memberId]);
    $cnt = (int) $st->fetchColumn();
} catch (Exception $e) {
    $cnt = 0;
}
echo json_encode(['count' => $cnt]);
exit;
