<?php
/**
 * Admin Quick Search API
 * GET /admin/api/quick-search.php?q=<query>
 * Returns JSON: { members, kyc, notices, total }
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth-roles.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* ── Auth check ─────────────────────────────────────────────────── */
if (!isAdminLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

/* ── Input ───────────────────────────────────────────────────────── */
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['members' => [], 'kyc' => [], 'notices' => [], 'total' => 0]);
    exit;
}

$db = getDB();
$like = '%' . $q . '%';
$out  = ['members' => [], 'kyc' => [], 'notices' => [], 'total' => 0];

/* ── 1. Members ──────────────────────────────────────────────────── */
try {
    $stmt = $db->prepare(
        'SELECT id, name, email, phone, sadasyata_number, approval_status
         FROM members
         WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? OR sadasyata_number LIKE ?
         ORDER BY created_at DESC LIMIT 6'
    );
    $stmt->execute([$like, $like, $like, $like]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sub = '';
        if (!empty($r['sadasyata_number'])) $sub = 'सदस्य #' . $r['sadasyata_number'];
        elseif (!empty($r['email']))         $sub = $r['email'];
        elseif (!empty($r['phone']))         $sub = $r['phone'];
        $status = $r['approval_status'] ?? 'pending';
        $out['members'][] = [
            'id'    => (int)$r['id'],
            'title' => $r['name'],
            'sub'   => $sub,
            'badge' => $status,
            'url'   => 'members.php?id=' . (int)$r['id'],
        ];
        $out['total']++;
    }
} catch (Throwable $e) { /* table may not exist yet */ }

/* ── 2. KYC Applications ─────────────────────────────────────────── */
try {
    $stmt = $db->prepare(
        'SELECT id, full_name, full_name_en, mobile, status
         FROM kyc_applications
         WHERE full_name LIKE ? OR full_name_en LIKE ? OR mobile LIKE ?
         ORDER BY created_at DESC LIMIT 6'
    );
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sub = !empty($r['full_name_en']) ? $r['full_name_en'] : ($r['mobile'] ?? '');
        $out['kyc'][] = [
            'id'    => (int)$r['id'],
            'title' => $r['full_name'],
            'sub'   => $sub,
            'badge' => $r['status'] ?? 'pending',
            'url'   => 'kyc-applications.php?id=' . (int)$r['id'],
        ];
        $out['total']++;
    }
} catch (Throwable $e) { /* table may not exist yet */ }

/* ── 3. Notices ──────────────────────────────────────────────────── */
try {
    $stmt = $db->prepare(
        'SELECT id, title, title_np, notice_date, is_active
         FROM notices
         WHERE title LIKE ? OR title_np LIKE ?
         ORDER BY notice_date DESC, created_at DESC LIMIT 5'
    );
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $displayTitle = !empty($r['title_np']) ? $r['title_np'] : $r['title'];
        $out['notices'][] = [
            'id'    => (int)$r['id'],
            'title' => $displayTitle,
            'sub'   => $r['notice_date'] ?? '',
            'badge' => $r['is_active'] ? 'active' : 'inactive',
            'url'   => 'notices.php?edit=' . (int)$r['id'],
        ];
        $out['total']++;
    }
} catch (Throwable $e) { /* table may not exist yet */ }

echo json_encode($out, JSON_UNESCAPED_UNICODE);
