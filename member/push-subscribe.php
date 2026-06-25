<?php
/**
 * Member Portal — Push Subscription Endpoint
 * POST /member/push-subscribe.php
 * Body (JSON): { endpoint, keys: { p256dh, auth } }
 * Returns JSON: { ok: bool }
 */
require_once __DIR__ . '/_bootstrap.php';
requireMemberLogin();
memberSecurityHeaders();

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$endpoint = trim((string)($data['endpoint']             ?? ''));
$p256dh   = trim((string)($data['keys']['p256dh']       ?? ''));
$auth     = trim((string)($data['keys']['auth']          ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing subscription fields']);
    exit;
}

$mem      = currentMember();
$memberId = (int)($mem['id'] ?? 0);
if ($memberId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthenticated']);
    exit;
}

require_once __DIR__ . '/../includes/push-helper.php';

try {
    $db = getDB();
    ensurePushTables($db);

    /* Upsert: same endpoint may re-subscribe with new keys */
    $db->prepare("
        INSERT INTO member_push_subscriptions
            (member_id, endpoint, p256dh, auth, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            member_id  = VALUES(member_id),
            p256dh     = VALUES(p256dh),
            auth       = VALUES(auth),
            user_agent = VALUES(user_agent)
    ")->execute([
        $memberId,
        $endpoint,
        $p256dh,
        $auth,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);

    echo json_encode(['ok' => true]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
}
