<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
/* config.php starts the admin session (coop_session); a bare session_start() here opens PHPSESSID and loses the login */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/program-tables.php';
require_once __DIR__ . '/../../includes/program-attendance-helpers.php';
require_once __DIR__ . '/../../includes/program-member-insights.php';

if (!isAdminLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'error_np' => 'Admin session समाप्त भयो — पेज refresh गरी पुनः login गर्नुहोस्।'], JSON_UNESCAPED_UNICODE);
    exit;
}

$memberQuery = trim((string)($_GET['member_id'] ?? ''));
$programId = (int)($_GET['program_id'] ?? 0);
$occurrenceId = (int)($_GET['occurrence_id'] ?? 0);
$deskId = (int)($_GET['desk_id'] ?? 0);

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

$staffAdminId = (int)($_SESSION['admin_id'] ?? 0) ?: null;
if ($deskId > 0) {
    $dk = $db->prepare('SELECT id FROM program_registration_desks WHERE id=? AND parent_program_id=?');
    $dk->execute([$deskId, $programId]);
    $deskId = (int)$dk->fetchColumn();
}
$occurrence = $occurrenceId > 0 ? programFetchOccurrenceById($db, $occurrenceId) : null;
if ($occurrence && ((int)$occurrence['parent_program_id'] !== $programId || (int)($occurrence['is_active'] ?? 0) !== 1)) {
    $occurrence = null;
}
$occurrenceId = $occurrence ? (int)$occurrence['id'] : 0;
$parentProgramId = programResolveParentProgramId($prog);
$logOcc = $occurrenceId > 0 ? $occurrenceId : null;

$eligible = programValidateMemberEligible($db, (int)$member['id'], $prog);
if (empty($eligible['ok'])) {
    programLogAttemptOnce($db, (int)$member['id'], $parentProgramId, $programId, $logOcc, 'ADMIN_MANUAL', 'INELIGIBLE',
        'Desk lookup: ' . (string)($eligible['message_np'] ?? ''), null, $deskId ?: null, $staffAdminId);
    echo json_encode([
        'ok' => false,
        'error' => 'ineligible',
        'error_np' => $eligible['message_np'] ?? 'सदस्य eligible छैन।',
        'member' => (static function () use ($db, $member): array {
            $idn = programMemberIdentity($db, $member);
            return [
                'member_id' => programMemberSadasyataNo($member),
                'name' => (string)($member['name'] ?? ''),
                'father_name' => $idn['father_name'],
                'dob_bs' => $idn['dob_bs'],
                'dob_ad' => $idn['dob_ad'],
                'age' => $idn['age'],
            ];
        })(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$scope = programResolveScopeId($prog, $occurrenceId);
$existing = programFindExistingAttendance($db, (int)$member['id'], $scope);

$photoUrl = programMemberPhotoUrl((string)(($member['photo'] ?? '') ?: ($member['avatar_url'] ?? '')));

$sadasyata = programMemberSadasyataNo($member);
$identity = programMemberIdentity($db, $member);
$history = programMemberHistorySummary($db, (int)$member['id'], $programId);
$window = programIsWindowOpen($prog, $occurrence);
$needsOccurrence = (int)($prog['is_multi_location'] ?? 0) === 1 && $occurrenceId < 1;
$canRecord = !empty($window['ok']) && !$existing && !$needsOccurrence;

if ($existing) {
    programLogAttemptOnce($db, (int)$member['id'], $parentProgramId, $programId, $logOcc, 'ADMIN_MANUAL', 'DUPLICATE_BLOCKED',
        rtrim('Desk lookup: already attended ' . programAttendanceDisplayLocation($existing)), (int)$existing['id'], $deskId ?: null, $staffAdminId);
} elseif (empty($window['ok'])) {
    programLogAttemptOnce($db, (int)$member['id'], $parentProgramId, $programId, $logOcc, 'ADMIN_MANUAL', 'WINDOW_CLOSED',
        'Desk lookup: ' . (string)($window['message_np'] ?? ''), null, $deskId ?: null, $staffAdminId);
}

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
        'name_np' => trim((string)($member['name_np'] ?? '')) !== trim((string)($member['name'] ?? '')) ? trim((string)($member['name_np'] ?? '')) : '',
        'father_name' => $identity['father_name'],
        'gender' => $identity['gender_key'] === 'unknown' ? '' : $identity['gender_label'],
        'dob_bs' => $identity['dob_bs'],
        'dob_ad' => $identity['dob_ad'],
        'age' => $identity['age'],
    ],
    'history' => $history + ['url' => 'program-member-history.php?member_id=' . rawurlencode($sadasyata)],
    'already_attended' => (bool)$existing,
    'existing' => $existing ? [
        'location' => programAttendanceDisplayLocation($existing),
        'attended_at' => (string)($existing['attended_at'] ?? ''),
        'method' => programAttendanceMethodLabel($existing['attendance_method'] ?? ''),
        'method_code' => (string)($existing['attendance_method'] ?? ''),
    ] + array_intersect_key(programAttendanceRecordedBy($db, $existing), ['by' => 1, 'desk' => 1]) : null,
    'window' => [
        'open' => !empty($window['ok']),
        'message_np' => (string)($window['message_np'] ?? ''),
        'message_en' => (string)($window['message_en'] ?? ''),
    ],
    'can_record' => $canRecord,
    'needs_occurrence' => $needsOccurrence,
], JSON_UNESCAPED_UNICODE);
