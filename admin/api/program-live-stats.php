<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/program-tables.php';
require_once __DIR__ . '/../../includes/program-attendance-helpers.php';

if (!isAdminLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$programId = (int)($_GET['program_id'] ?? 0);
if ($programId < 1) {
    echo json_encode(['ok' => false, 'error' => 'program_id required']);
    exit;
}

$db = getDB();
ensureProgramTables($db);
$prog = programFetchById($db, $programId);
if (!$prog) {
    echo json_encode(['ok' => false, 'error' => 'not found']);
    exit;
}

$stats = programLiveStatsForProgram($db, $prog);
$scope = $stats['scope'];

$recent = [];
try {
    $st = $db->prepare("SELECT a.attended_at, a.location_label, a.attendance_method, m.name, m.sadasyata_number
                        FROM member_program_attendance a
                        LEFT JOIN members m ON m.id=a.member_id
                        WHERE a.attendance_scope_key=? AND a.attendance_status='VALID'
                        ORDER BY a.attended_at DESC LIMIT 5");
    $st->execute([$scope]);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
}

echo json_encode([
    'ok' => true,
    'attended' => $stats['attended'],
    'prereg' => $stats['prereg'],
    'eligible' => $stats['eligible'],
    'pct' => $stats['pct'],
    'recent' => $recent,
    'occurrences' => $stats['occurrences'],
], JSON_UNESCAPED_UNICODE);
