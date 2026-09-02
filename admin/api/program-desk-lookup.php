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

$memberQuery = trim((string)($_GET['member_id'] ?? ''));
$programId = (int)($_GET['program_id'] ?? 0);
$occurrenceId = (int)($_GET['occurrence_id'] ?? 0);

if ($memberQuery === '' || $programId < 1) {
    echo json_encode(['ok' => false, 'error' => 'member_id and program_id required', 'error_np' => 'Member ID र कार्यक्रम आवश्यक छ।']);
    exit;
}

$db = getDB();
ensureProgramTables($db);
$prog = programFetchById($db, $programId);
if (!$prog || (int)($prog['is_active'] ?? 0) !== 1) {
    echo json_encode(['ok' => false, 'error' => 'program inactive', 'error_np' => 'कार्यक्रम निष्क्रिय छ।']);
    exit;
}

$member = programResolveMemberBySadasyata($db, $memberQuery);
if (!$member) {
    echo json_encode(['ok' => false, 'error' => 'member not found', 'error_np' => 'Member ID सिस्टममा फेला परेन।']);
    exit;
}

$eligible = programValidateMemberEligible($db, (int)$member['id'], $prog);
if (empty($eligible['ok'])) {
    echo json_encode([
        'ok' => false,
        'error' => 'ineligible',
        'error_np' => $eligible['message_np'] ?? 'सदस्य eligible छैन।',
        'member' => [
            'member_id' => programMemberSadasyataNo($member),
            'name' => (string)($member['name'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$occurrence = $occurrenceId > 0 ? programFetchOccurrenceById($db, $occurrenceId) : null;
$scope = programResolveScopeId($prog, $occurrenceId);
$existing = programFindExistingAttendance($db, (int)$member['id'], $scope);

$photo = (string)($member['photo'] ?? '');
$photoUrl = '';
if ($photo !== '' && function_exists('coop_public_download_url')) {
    $photoUrl = coop_public_download_url($photo);
}

$sadasyata = programMemberSadasyataNo($member);
$window = programIsWindowOpen($prog, $occurrence);
$canRecord = !empty($window['ok']) && !$existing;

echo json_encode([
    'ok' => true,
    'member' => [
        'id' => (int)$member['id'],
        'name' => (string)($member['name'] ?? ''),
        'member_id' => $sadasyata,
        'phone' => (string)($member['phone'] ?? ''),
        'address' => (string)($member['address'] ?? ''),
        'photo_url' => $photoUrl,
        'is_active' => (int)($member['is_active'] ?? 0),
    ],
    'already_attended' => (bool)$existing,
    'existing' => $existing ? [
        'location' => programAttendanceDisplayLocation($existing),
        'attended_at' => (string)($existing['attended_at'] ?? ''),
        'method' => programAttendanceMethodLabel($existing['attendance_method'] ?? ''),
        'method_code' => (string)($existing['attendance_method'] ?? ''),
    ] : null,
    'window' => [
        'open' => !empty($window['ok']),
        'message_np' => (string)($window['message_np'] ?? ''),
        'message_en' => (string)($window['message_en'] ?? ''),
    ],
    'can_record' => $canRecord,
], JSON_UNESCAPED_UNICODE);
