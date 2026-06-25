<?php
/**
 * Member Portal — AJAX handler
 */
require_once '../includes/config.php';
require_once '../includes/member-auth.php';

header('Content-Type: application/json');

if (!memberIsLoggedIn()) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$db       = getDB();
$memberId = $_SESSION['member_id'];
$action   = $_GET['action'] ?? '';

$allowed = ['mark_read', 'mark_notif_read', 'unread_count', 'count'];
if (!in_array($action, $allowed, true)) {
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// mark_read and mark_notif_read are aliases — both mark a notification as read
if ($action === 'mark_read' || $action === 'mark_notif_read') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $db->prepare("UPDATE member_notifications SET is_read=1 WHERE id=? AND member_id=?")
           ->execute([$id, $memberId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'unread_count' || $action === 'count') {
    $cnt = 0;
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM member_notifications WHERE member_id=? AND is_read=0");
        $st->execute([$memberId]);
        $cnt = (int)$st->fetchColumn();
    } catch (Exception $e) { $cnt = 0; }
    echo json_encode(['count' => $cnt]);
    exit;
}
