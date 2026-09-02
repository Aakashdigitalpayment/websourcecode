<?php
/**
 * Shared program attendance core — duplicate-safe recording, window validation, attempt logging.
 */
require_once __DIR__ . '/program-tables.php';

if (!function_exists('programResolveMemberBySadasyata')) {
    /**
     * Member ID (sadasyata_number) SSOT lookup — program attendance flows use this, not internal PK.
     * @return array<string,mixed>|null
     */
    function programResolveMemberBySadasyata(PDO $db, ?string $memberIdInput): ?array
    {
        $memberIdInput = trim((string)$memberIdInput);
        if ($memberIdInput === '') {
            return null;
        }
        if (!function_exists('memberSsotFindBySadasyata')) {
            require_once __DIR__ . '/member-ssot.php';
        }
        return memberSsotFindBySadasyata($db, $memberIdInput);
    }
}

if (!function_exists('programMemberSadasyataNo')) {
    function programMemberSadasyataNo(?array $member): string
    {
        if (!$member) {
            return '';
        }
        if (function_exists('memberSsotResolveSadasyata')) {
            return memberSsotResolveSadasyata($member);
        }
        return strtoupper(trim((string)($member['sadasyata_number'] ?? '')));
    }
}

if (!function_exists('programFilterBsDateToAd')) {
    /** Report filter: BS YYYY-MM-DD → AD YYYY-MM-DD for MySQL DATE() compare. Legacy AD URLs still work. */
    function programFilterBsDateToAd(string $dateIn): string
    {
        $dateIn = trim($dateIn);
        if ($dateIn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIn)) {
            return '';
        }
        $y = (int)substr($dateIn, 0, 4);
        if ($y >= 2070) {
            if (!function_exists('bsToAd')) {
                $h = dirname(__DIR__) . '/core/helpers.php';
                if (is_file($h)) {
                    require_once $h;
                }
            }
            if (function_exists('bsToAd')) {
                $ad = trim((string)bsToAd($dateIn));
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ad)) {
                    return $ad;
                }
            }
            return '';
        }
        return $dateIn;
    }
}

if (!function_exists('programAttendanceMethodLabel')) {
    function programAttendanceMethodLabel(?string $method, bool $english = false): string
    {
        $m = strtoupper(trim((string)$method));
        $map = [
            'MEMBER_SELF' => ['np' => 'सदस्य Portal', 'en' => 'Member Portal'],
            'ADMIN_MANUAL' => ['np' => 'दर्ता डेस्क', 'en' => 'Registration Desk'],
            'QR_SCAN' => ['np' => 'QR स्क्यान', 'en' => 'QR Scan'],
            'STAFF_VERIFY' => ['np' => 'Staff Verify', 'en' => 'Staff Verify'],
            'ADMIN_APPROVE' => ['np' => 'Admin स्वीकृति', 'en' => 'Admin Approve'],
            'ADMIN_PREREG' => ['np' => 'Pre-reg', 'en' => 'Pre-registration'],
        ];
        if (!isset($map[$m])) {
            return $method !== null && $method !== '' ? $method : '—';
        }
        return $english ? $map[$m]['en'] : $map[$m]['np'];
    }
}

if (!function_exists('mapSourceToMethod')) {
    function mapSourceToMethod(?string $source): string
    {
        $s = trim((string)$source);
        $map = [
            'member_portal_qr_pending' => 'QR_SCAN',
            'member_portal_pending' => 'MEMBER_SELF',
            'member_portal_qr' => 'QR_SCAN',
            'member_portal_instant' => 'QR_SCAN',
            'program_verify_page' => 'STAFF_VERIFY',
            'admin_request_approve' => 'ADMIN_APPROVE',
            'admin_prereg' => 'ADMIN_PREREG',
            'registration_desk' => 'ADMIN_MANUAL',
            'verify_portal' => 'STAFF_VERIFY',
        ];
        return $map[$s] ?? 'STAFF_VERIFY';
    }
}

if (!function_exists('programFetchById')) {
    function programFetchById(PDO $db, int $programId): ?array
    {
        if ($programId < 1) {
            return null;
        }
        $st = $db->prepare('SELECT * FROM upcoming_programs WHERE id=? LIMIT 1');
        $st->execute([$programId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('programFetchOccurrenceById')) {
    function programFetchOccurrenceById(PDO $db, int $occurrenceId): ?array
    {
        if ($occurrenceId < 1) {
            return null;
        }
        $st = $db->prepare('SELECT * FROM program_occurrences WHERE id=? LIMIT 1');
        $st->execute([$occurrenceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('programResolveScopeId')) {
    /** Duplicate-check scope: parent program id (multi-location shares one scope per member). */
    function programResolveScopeId(?array $program, ?int $occurrenceId = null): int
    {
        unset($occurrenceId);
        return $program ? (int)($program['id'] ?? 0) : 0;
    }
}

if (!function_exists('programResolveParentProgramId')) {
    function programResolveParentProgramId(?array $program): int
    {
        return programResolveScopeId($program);
    }
}

if (!function_exists('programResolveQrContext')) {
    /** Resolve program + occurrence from shared or occurrence QR token. */
    function programResolveQrContext(PDO $db, string $qrToken): array
    {
        $qrToken = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $qrToken));
        if ($qrToken === '') {
            return ['program' => null, 'occurrence' => null];
        }
        $st = $db->prepare('SELECT * FROM program_occurrences WHERE qr_token=? AND is_active=1 LIMIT 1');
        $st->execute([$qrToken]);
        $occ = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($occ) {
            $prog = programFetchById($db, (int)$occ['parent_program_id']);
            return ['program' => $prog, 'occurrence' => $occ];
        }
        $st = $db->prepare('SELECT * FROM upcoming_programs WHERE qr_token=? AND is_active=1 LIMIT 1');
        $st->execute([$qrToken]);
        $prog = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return ['program' => $prog, 'occurrence' => null];
    }
}

if (!function_exists('programIsWindowOpen')) {
    function programIsWindowOpen(?array $program, ?array $occurrence = null): array
    {
        $now = time();
        $openAt = null;
        $closeAt = null;
        if ($occurrence) {
            $openAt = $occurrence['attendance_open_at'] ?? null;
            $closeAt = $occurrence['attendance_close_at'] ?? null;
            if (!$openAt && !empty($occurrence['qr_starts_at'])) {
                $openAt = $occurrence['qr_starts_at'];
            }
            if (!$closeAt && !empty($occurrence['qr_expires_at'])) {
                $closeAt = $occurrence['qr_expires_at'];
            }
        }
        if ($program) {
            if (!$openAt && !empty($program['attendance_open_at'])) {
                $openAt = $program['attendance_open_at'];
            }
            if (!$closeAt && !empty($program['attendance_close_at'])) {
                $closeAt = $program['attendance_close_at'];
            }
            if (!$openAt && !empty($program['qr_starts_at'])) {
                $openAt = $program['qr_starts_at'];
            }
            if (!$closeAt && !empty($program['qr_expires_at'])) {
                $closeAt = $program['qr_expires_at'];
            }
        }
        if ($openAt && strtotime((string)$openAt) > $now) {
            return ['ok' => false, 'code' => 'WINDOW_CLOSED', 'message_np' => 'उपस्थिति window अझ सुरु भएको छैन।', 'message_en' => 'Attendance window has not opened yet.'];
        }
        if ($closeAt && strtotime((string)$closeAt) < $now) {
            return ['ok' => false, 'code' => 'WINDOW_CLOSED', 'message_np' => 'उपस्थिति window समाप्त भइसकेको छ।', 'message_en' => 'Attendance window has closed.'];
        }
        return ['ok' => true, 'code' => 'OK'];
    }
}

if (!function_exists('programIsQrEnabled')) {
    function programIsQrEnabled(?array $program, ?array $occurrence = null): bool
    {
        if ($occurrence) {
            return (int)($occurrence['qr_enabled'] ?? 0) === 1;
        }
        return $program && (int)($program['qr_enabled'] ?? 0) === 1;
    }
}

if (!function_exists('programFindExistingAttendance')) {
    function programFindExistingAttendance(PDO $db, int $memberId, int $scopeId): ?array
    {
        if ($memberId < 1 || $scopeId < 1) {
            return null;
        }
        $st = $db->prepare("SELECT a.*, p.location AS program_location, p.event_date AS program_event_date, p.event_time AS program_event_time,
                                   o.location_name AS occurrence_location, o.event_date AS occurrence_event_date, o.start_time AS occurrence_start_time
                            FROM member_program_attendance a
                            LEFT JOIN upcoming_programs p ON p.id = a.program_id
                            LEFT JOIN program_occurrences o ON o.id = a.occurrence_id
                            WHERE a.member_id=? AND a.attendance_scope_key=? AND a.attendance_status='VALID'
                            LIMIT 1");
        $st->execute([$memberId, $scopeId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('programAttendanceDisplayLocation')) {
    function programAttendanceDisplayLocation(array $row): string
    {
        $loc = trim((string)($row['location_label'] ?? ''));
        if ($loc !== '') {
            return $loc;
        }
        if (!empty($row['occurrence_location'])) {
            return (string)$row['occurrence_location'];
        }
        return (string)($row['program_location'] ?? '');
    }
}

if (!function_exists('programLogAttempt')) {
    function programLogAttempt(
        PDO $db,
        int $memberId,
        int $parentProgramId,
        int $programId,
        ?int $occurrenceId,
        string $method,
        string $result,
        string $message = '',
        ?int $previousAttendanceId = null,
        ?int $deskId = null,
        ?int $staffAdminId = null,
        ?string $ip = null
    ): void {
        try {
            $ip = $ip ?? (function_exists('coop_client_ip') ? coop_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
            $st = $db->prepare("INSERT INTO program_attendance_attempts
                (member_id, parent_program_id, program_id, occurrence_id, attempted_method, previous_attendance_id, result, result_message, verified_by_ip, desk_id, staff_admin_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([
                $memberId,
                $parentProgramId,
                $programId,
                $occurrenceId,
                mb_substr($method, 0, 40),
                $previousAttendanceId,
                $result,
                mb_substr($message, 0, 500),
                mb_substr((string)$ip, 0, 45),
                $deskId,
                $staffAdminId,
            ]);
        } catch (Throwable $e) {
            error_log('[programLogAttempt] ' . $e->getMessage());
        }
    }
}

if (!function_exists('programAuditLog')) {
    function programAuditLog(
        PDO $db,
        string $actionType,
        ?int $programId = null,
        ?int $occurrenceId = null,
        ?int $attendanceId = null,
        ?int $memberId = null,
        ?int $adminId = null,
        string $reason = '',
        ?array $payload = null
    ): void {
        try {
            $ip = function_exists('coop_client_ip') ? coop_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
            $st = $db->prepare("INSERT INTO program_audit_logs
                (action_type, program_id, occurrence_id, attendance_id, member_id, admin_id, reason, payload_json, ip_address)
                VALUES (?,?,?,?,?,?,?,?,?)");
            $st->execute([
                mb_substr($actionType, 0, 60),
                $programId,
                $occurrenceId,
                $attendanceId,
                $memberId,
                $adminId,
                mb_substr($reason, 0, 500),
                $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                mb_substr((string)$ip, 0, 45),
            ]);
        } catch (Throwable $e) {
            error_log('[programAuditLog] ' . $e->getMessage());
        }
    }
}

if (!function_exists('programClosePendingRequests')) {
    function programClosePendingRequests(PDO $db, int $memberId, int $programId, ?int $adminId = null, string $note = 'Closed by attendance record'): void
    {
        try {
            $db->prepare("UPDATE member_program_attendance_requests
                SET status='approved', processed_at=NOW(), admin_id=?, admin_note=?
                WHERE member_id=? AND program_id=? AND status='pending'")
                ->execute([$adminId ?: null, $note, $memberId, $programId]);
        } catch (Throwable $e) {
            error_log('[programClosePendingRequests] ' . $e->getMessage());
        }
    }
}

if (!function_exists('programValidateMemberEligible')) {
    function programValidateMemberEligible(PDO $db, int $memberId, ?array $program = null): array
    {
        if ($memberId < 1) {
            return ['ok' => false, 'code' => 'INVALID_MEMBER', 'message_np' => 'सदस्य फेला परेन।', 'message_en' => 'Member not found.'];
        }
        $st = $db->prepare('SELECT id, name, sadasyata_number, is_active, approval_status, photo FROM members WHERE id=? LIMIT 1');
        $st->execute([$memberId]);
        $member = $st->fetch(PDO::FETCH_ASSOC);
        if (!$member) {
            return ['ok' => false, 'code' => 'INVALID_MEMBER', 'message_np' => 'सदस्य फेला परेन।', 'message_en' => 'Member not found.'];
        }
        if (programMemberSadasyataNo($member) === '') {
            return ['ok' => false, 'code' => 'INVALID_MEMBER', 'message_np' => 'Member ID (सदस्यता नं.) अनुपलब्ध छ।', 'message_en' => 'Member ID is missing on record.'];
        }
        if ((int)($member['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'code' => 'INELIGIBLE', 'message_np' => 'सदस्य सक्रिय छैन।', 'message_en' => 'Member is not active.', 'member' => $member];
        }
        $status = strtolower(trim((string)($member['approval_status'] ?? '')));
        if ($status !== '' && !in_array($status, ['approved', 'active', 'confirmed'], true)) {
            return ['ok' => false, 'code' => 'INELIGIBLE', 'message_np' => 'सदस्य अनुमोदित छैन।', 'message_en' => 'Member is not approved.', 'member' => $member];
        }
        return ['ok' => true, 'member' => $member];
    }
}

if (!function_exists('recordProgramAttendance')) {
    /**
     * @return array{ok:bool, duplicate?:bool, attendance_id?:int, existing?:array, error?:string, error_np?:string, error_en?:string}
     */
    function recordProgramAttendance(PDO $db, array $opts): array
    {
        ensureProgramTables($db);

        $memberId = (int)($opts['member_id'] ?? 0);
        $programId = (int)($opts['program_id'] ?? 0);
        $occurrenceId = isset($opts['occurrence_id']) ? (int)$opts['occurrence_id'] : null;
        if ($occurrenceId !== null && $occurrenceId < 1) {
            $occurrenceId = null;
        }
        $method = strtoupper(trim((string)($opts['attendance_method'] ?? mapSourceToMethod($opts['source'] ?? ''))));
        $source = trim((string)($opts['source'] ?? 'verify_portal'));
        $note = mb_substr(trim((string)($opts['attendance_note'] ?? '')), 0, 500);
        $ip = $opts['ip'] ?? (function_exists('coop_client_ip') ? coop_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        $deskId = isset($opts['desk_id']) ? (int)$opts['desk_id'] : null;
        $staffAdminId = isset($opts['staff_admin_id']) ? (int)$opts['staff_admin_id'] : null;
        $deviceFingerprint = isset($opts['device_fingerprint']) ? mb_substr((string)$opts['device_fingerprint'], 0, 120) : null;
        $memberCardNo = trim((string)($opts['member_card_no'] ?? ''));
        $allowOverride = !empty($opts['allow_override']);
        $overrideReason = trim((string)($opts['override_reason'] ?? ''));

        $program = programFetchById($db, $programId);
        if (!$program || (int)($program['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'error_np' => 'कार्यक्रम फेला परेन वा निष्क्रिय छ।', 'error_en' => 'Program not found or inactive.'];
        }

        $occurrence = $occurrenceId ? programFetchOccurrenceById($db, $occurrenceId) : null;
        if ($occurrenceId && (!$occurrence || (int)$occurrence['parent_program_id'] !== (int)$program['id'])) {
            return ['ok' => false, 'error_np' => 'स्थान/सत्र फेला परेन।', 'error_en' => 'Occurrence not found.'];
        }
        if ($occurrence && (int)($occurrence['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'error_np' => 'यो स्थान/सत्र निष्क्रिय छ।', 'error_en' => 'This occurrence is inactive.'];
        }
        if ((int)($program['is_multi_location'] ?? 0) === 1 && !$occurrenceId) {
            return ['ok' => false, 'error_np' => 'Multi-location कार्यक्रममा स्थान/सत्र छान्नुहोस्।', 'error_en' => 'Please select a location for this multi-location program.'];
        }

        $memberCheck = programValidateMemberEligible($db, $memberId, $program);
        if (empty($memberCheck['ok'])) {
            programLogAttempt($db, $memberId, programResolveParentProgramId($program), $programId, $occurrenceId, $method, 'INELIGIBLE', (string)($memberCheck['message_np'] ?? ''));
            return ['ok' => false, 'error_np' => $memberCheck['message_np'] ?? '', 'error_en' => $memberCheck['message_en'] ?? ''];
        }
        $member = $memberCheck['member'];
        if ($memberCardNo === '') {
            $memberCardNo = programMemberSadasyataNo($member);
        }

        if (empty($opts['skip_window_check'])) {
            $window = programIsWindowOpen($program, $occurrence);
            if (empty($window['ok'])) {
                programLogAttempt($db, $memberId, programResolveParentProgramId($program), $programId, $occurrenceId, $method, 'WINDOW_CLOSED', (string)($window['message_np'] ?? ''));
                return ['ok' => false, 'error_np' => $window['message_np'] ?? '', 'error_en' => $window['message_en'] ?? ''];
            }
        }

        $scopeId = programResolveScopeId($program, $occurrenceId);
        $parentProgramId = programResolveParentProgramId($program);
        $locationLabel = $occurrence ? (string)($occurrence['location_name'] ?? '') : (string)($program['location'] ?? '');

        try {
            $db->beginTransaction();

            $lock = $db->prepare("SELECT id FROM member_program_attendance WHERE member_id=? AND attendance_scope_key=? AND attendance_status='VALID' FOR UPDATE");
            $lock->execute([$memberId, $scopeId]);

            $existing = programFindExistingAttendance($db, $memberId, $scopeId);
            if ($existing && !$allowOverride) {
                programLogAttempt(
                    $db,
                    $memberId,
                    $parentProgramId,
                    $programId,
                    $occurrenceId,
                    $method,
                    'DUPLICATE_BLOCKED',
                    'Already attended at ' . programAttendanceDisplayLocation($existing),
                    (int)$existing['id'],
                    $deskId,
                    $staffAdminId,
                    $ip
                );
                $db->commit();
                return ['ok' => false, 'duplicate' => true, 'existing' => $existing, 'error_np' => 'उपस्थिति पहिले नै दर्ता भइसकेको छ।', 'error_en' => 'Attendance already recorded.'];
            }

            if ($existing && $allowOverride) {
                if ($overrideReason === '') {
                    $db->rollBack();
                    return ['ok' => false, 'error_np' => 'Override को लागि कारण अनिवार्य छ।', 'error_en' => 'Override reason is required.'];
                }
                $voidAdmin = $staffAdminId ?: (int)($_SESSION['admin_id'] ?? 0);
                $db->prepare("UPDATE member_program_attendance SET attendance_status='VOID', voided_by=?, voided_at=NOW(), void_reason=? WHERE id=?")
                    ->execute([$voidAdmin ?: null, mb_substr($overrideReason, 0, 500), (int)$existing['id']]);
                programAuditLog($db, 'attendance_void_override', $programId, $occurrenceId, (int)$existing['id'], $memberId, $voidAdmin ?: null, $overrideReason);
            }

            $ins = $db->prepare("INSERT INTO member_program_attendance
                (member_id, member_card_no, program_id, occurrence_id, parent_program_id, attendance_scope_key, program_title,
                 is_priority, attendance_note, verified_by_ip, source, attendance_method, attendance_status, location_label,
                 desk_id, staff_admin_id, device_fingerprint)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $memberId,
                mb_substr($memberCardNo, 0, 60),
                $programId,
                $occurrenceId,
                $parentProgramId,
                $scopeId,
                mb_substr((string)($program['title'] ?? ''), 0, 180),
                !empty($opts['is_priority']) ? 1 : 0,
                $note,
                mb_substr((string)$ip, 0, 45),
                mb_substr($source, 0, 30),
                $method,
                'VALID',
                mb_substr($locationLabel, 0, 180),
                $deskId ?: null,
                $staffAdminId ?: null,
                $deviceFingerprint,
            ]);
            $attendanceId = (int)$db->lastInsertId();

            programClosePendingRequests($db, $memberId, $programId, $staffAdminId, 'Closed by attendance record #' . $attendanceId);
            programAuditLog($db, 'attendance_create', $programId, $occurrenceId, $attendanceId, $memberId, $staffAdminId ?: null, $note, [
                'method' => $method,
                'source' => $source,
            ]);

            $db->commit();
            return ['ok' => true, 'attendance_id' => $attendanceId, 'member' => $member];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $sqlState = $e instanceof PDOException ? ($e->errorInfo[0] ?? '') : '';
            if ($sqlState === '23000' || str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'uniq_')) {
                $existing = programFindExistingAttendance($db, $memberId, $scopeId);
                if ($existing) {
                    programLogAttempt($db, $memberId, $parentProgramId, $programId, $occurrenceId, $method, 'DUPLICATE_BLOCKED', 'Race duplicate', (int)$existing['id'], $deskId, $staffAdminId, $ip);
                    return ['ok' => false, 'duplicate' => true, 'existing' => $existing, 'error_np' => 'उपस्थिति पहिले नै दर्ता भइसकेको छ।', 'error_en' => 'Attendance already recorded.'];
                }
            }
            error_log('[recordProgramAttendance] ' . $e->getMessage());
            return ['ok' => false, 'error_np' => 'उपस्थिति सुरक्षित गर्न सकिएन।', 'error_en' => 'Could not save attendance.'];
        }
    }
}

if (!function_exists('voidProgramAttendance')) {
    function voidProgramAttendance(PDO $db, int $attendanceId, int $adminId, string $reason): array
    {
        ensureProgramTables($db);
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error_np' => 'Void कारण अनिवार्य छ।', 'error_en' => 'Void reason is required.'];
        }
        $st = $db->prepare("SELECT * FROM member_program_attendance WHERE id=? AND attendance_status='VALID' LIMIT 1");
        $st->execute([$attendanceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error_np' => 'उपस्थिति record फेला परेन।', 'error_en' => 'Attendance record not found.'];
        }
        $db->prepare("UPDATE member_program_attendance SET attendance_status='VOID', voided_by=?, voided_at=NOW(), void_reason=? WHERE id=?")
            ->execute([$adminId ?: null, mb_substr($reason, 0, 500), $attendanceId]);
        programAuditLog($db, 'attendance_void', (int)$row['program_id'], (int)($row['occurrence_id'] ?? 0) ?: null, $attendanceId, (int)$row['member_id'], $adminId, $reason);
        return ['ok' => true];
    }
}

if (!function_exists('programCountUniqueAttended')) {
    function programCountUniqueAttended(PDO $db, int $parentProgramId): int
    {
        if ($parentProgramId < 1) {
            return 0;
        }
        $st = $db->prepare("SELECT COUNT(DISTINCT member_id) FROM member_program_attendance
                            WHERE attendance_scope_key=? AND attendance_status='VALID'");
        $st->execute([$parentProgramId]);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('programCountActiveMembers')) {
    function programCountActiveMembers(PDO $db): int
    {
        try {
            return (int)$db->query("SELECT COUNT(*) FROM members WHERE is_active=1 AND LOWER(COALESCE(approval_status,'')) IN ('approved','active','confirmed','')")->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('programOccurrenceCounts')) {
    function programOccurrenceCounts(PDO $db, int $parentProgramId): array
    {
        $st = $db->prepare("SELECT o.id, o.location_name, o.event_date,
                                   COUNT(a.id) AS attended_count
                            FROM program_occurrences o
                            LEFT JOIN member_program_attendance a ON a.occurrence_id=o.id AND a.attendance_status='VALID'
                            WHERE o.parent_program_id=?
                            GROUP BY o.id
                            ORDER BY o.sort_order ASC, o.id ASC");
        $st->execute([$parentProgramId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('programFormatExistingAttendanceMessage')) {
    function programFormatExistingAttendanceMessage(array $existing, bool $english = false): string
    {
        $loc = programAttendanceDisplayLocation($existing);
        $dt = !empty($existing['attended_at']) ? date('Y-m-d H:i', strtotime((string)$existing['attended_at'])) : '';
        if ($english) {
            return 'Already recorded' . ($loc !== '' ? ' at ' . $loc : '') . ($dt !== '' ? ' on ' . $dt : '') . '.';
        }
        return 'पहिले नै दर्ता' . ($loc !== '' ? ' — ' . $loc : '') . ($dt !== '' ? ' (' . $dt . ')' : '') . '।';
    }
}

if (!function_exists('programFormatAttendedAt')) {
    function programFormatAttendedAt(?string $dt): string
    {
        $dt = trim((string)$dt);
        return $dt !== '' ? substr($dt, 0, 16) : '';
    }
}

if (!function_exists('programMemberPhotoUrl')) {
    function programMemberPhotoUrl(?string $photo): string
    {
        $photo = trim((string)$photo);
        if ($photo === '') {
            return '';
        }
        if (function_exists('coop_public_download_url')) {
            return coop_public_download_url($photo);
        }
        return (defined('SITE_URL') ? SITE_URL : '../') . ltrim($photo, '/');
    }
}

if (!function_exists('programHasPendingAttendanceRequest')) {
    function programHasPendingAttendanceRequest(PDO $db, int $memberId, int $programId): bool
    {
        if ($memberId < 1 || $programId < 1) {
            return false;
        }
        $st = $db->prepare("SELECT 1 FROM member_program_attendance_requests WHERE member_id=? AND program_id=? AND status='pending' LIMIT 1");
        $st->execute([$memberId, $programId]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('programCountPreregistrations')) {
    function programCountPreregistrations(PDO $db, int $programId): int
    {
        if ($programId < 1) {
            return 0;
        }
        try {
            $st = $db->prepare('SELECT COUNT(*) FROM member_program_preregistrations WHERE program_id=?');
            $st->execute([$programId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('programLiveStatsForProgram')) {
    /** @return array{scope:int,title:string,attended:int,eligible:int,prereg:int,pct:float,occurrences:array} */
    function programLiveStatsForProgram(PDO $db, array $prog): array
    {
        $programId = (int)($prog['id'] ?? 0);
        $scope = programResolveScopeId($prog);
        $attended = programCountUniqueAttended($db, $scope);
        $eligible = programCountActiveMembers($db);
        $prereg = programCountPreregistrations($db, $programId);
        return [
            'scope' => $scope,
            'title' => (string)($prog['title'] ?? ''),
            'attended' => $attended,
            'eligible' => $eligible,
            'prereg' => $prereg,
            'pct' => $eligible > 0 ? round(($attended / $eligible) * 100, 1) : 0.0,
            'occurrences' => (int)($prog['is_multi_location'] ?? 0) === 1
                ? programOccurrenceCounts($db, $programId) : [],
        ];
    }
}

if (!function_exists('programFetchValidAttendanceByScope')) {
    function programFetchValidAttendanceByScope(PDO $db, int $scopeId): array
    {
        if ($scopeId < 1) {
            return [];
        }
        $st = $db->prepare("SELECT a.*, m.name AS member_name, m.phone
                            FROM member_program_attendance a
                            LEFT JOIN members m ON m.id=a.member_id
                            WHERE a.attendance_scope_key=? AND a.attendance_status='VALID'
                            ORDER BY a.attended_at DESC");
        $st->execute([$scopeId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('programFetchValidAttendanceByOccurrence')) {
    function programFetchValidAttendanceByOccurrence(PDO $db, int $occurrenceId): array
    {
        if ($occurrenceId < 1) {
            return [];
        }
        $st = $db->prepare("SELECT a.*, m.name AS member_name
                            FROM member_program_attendance a
                            LEFT JOIN members m ON m.id=a.member_id
                            WHERE a.occurrence_id=? AND a.attendance_status='VALID'
                            ORDER BY a.attended_at DESC");
        $st->execute([$occurrenceId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('programFetchValidAttendanceByProgram')) {
    function programFetchValidAttendanceByProgram(PDO $db, int $programId): array
    {
        if ($programId < 1) {
            return [];
        }
        $st = $db->prepare("SELECT a.*, m.name AS member_name
                            FROM member_program_attendance a
                            LEFT JOIN members m ON m.id=a.member_id
                            WHERE a.program_id=? AND a.attendance_status='VALID'
                            ORDER BY a.attended_at DESC");
        $st->execute([$programId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
