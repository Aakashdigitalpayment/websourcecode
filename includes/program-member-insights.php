<?php
/**
 * Program attendance — member identity (बुबाको नाम / लिङ्ग), attendance history, AGM streak,
 * and per-program breakdowns (gender / desk / staff). Read-only helpers.
 */
require_once __DIR__ . '/program-attendance-helpers.php';

if (!function_exists('programKycJoinParts')) {
    /**
     * SELECT + JOIN fragments exposing father_name and gender_raw for alias `m` (members).
     * Degrades to empty values when kyc_applications / columns are missing.
     * @return array{select:string,join:string,gender:string}
     */
    function programKycJoinParts(PDO $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $memberGender = programColumnExists($db, 'members', 'gender') ? "NULLIF(TRIM(m.gender),'')" : 'NULL';
        if (programColumnExists($db, 'members', 'kyc_application_id')
            && programColumnExists($db, 'kyc_applications', 'father_name')) {
            $kycGender = programColumnExists($db, 'kyc_applications', 'gender') ? "NULLIF(TRIM(k.gender),'')" : 'NULL';
            $gender = "COALESCE({$memberGender}, {$kycGender}, '')";
            return $cache = [
                'select' => "COALESCE(k.father_name,'') AS father_name, {$gender} AS gender_raw",
                'join' => 'LEFT JOIN kyc_applications k ON k.id = m.kyc_application_id',
                'gender' => $gender,
            ];
        }
        $gender = "COALESCE({$memberGender}, '')";
        return $cache = [
            'select' => "'' AS father_name, {$gender} AS gender_raw",
            'join' => '',
            'gender' => $gender,
        ];
    }
}

if (!function_exists('programGenderKey')) {
    /** Normalise free-text gender (English / नेपाली / legacy codes) → male|female|other|unknown */
    function programGenderKey(?string $raw): string
    {
        $g = mb_strtolower(trim((string)$raw), 'UTF-8');
        if ($g === '') {
            return 'unknown';
        }
        if (in_array($g, ['male', 'm', 'man', 'पुरुष', 'purush', 'purus', 'boy'], true)) {
            return 'male';
        }
        if (in_array($g, ['female', 'f', 'woman', 'महिला', 'mahila', 'स्त्री', 'girl'], true)) {
            return 'female';
        }
        if (in_array($g, ['other', 'o', 'others', 'अन्य', 'third', 'third gender', 'तेस्रो लिङ्गी'], true)) {
            return 'other';
        }
        return 'unknown';
    }
}

if (!function_exists('programGenderLabel')) {
    function programGenderLabel(string $key): string
    {
        return ['male' => 'पुरुष', 'female' => 'महिला', 'other' => 'अन्य'][$key] ?? 'नखुलेको';
    }
}

if (!function_exists('programMemberIdentity')) {
    /**
     * बुबाको नाम + लिङ्ग for one member (desk lookup / history page).
     * @return array{father_name:string,gender_key:string,gender_label:string}
     */
    function programMemberIdentity(PDO $db, array $member): array
    {
        $father = '';
        $gender = trim((string)($member['gender'] ?? ''));
        $kyc = null;
        try {
            if (!function_exists('memberSsotLoadLinkedKyc')) {
                require_once __DIR__ . '/member-ssot.php';
            }
            $kyc = memberSsotLoadLinkedKyc($db, $member);
            if ($kyc) {
                $father = trim((string)($kyc['father_name'] ?? ''));
                if ($gender === '') {
                    $gender = trim((string)($kyc['gender'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            error_log('[program-insights] identity: ' . $e->getMessage());
        }
        $key = programGenderKey($gender);
        return ['father_name' => $father, 'gender_key' => $key, 'gender_label' => programGenderLabel($key)]
            + programMemberDob($member, $kyc ?: []);
    }
}

if (!function_exists('programMemberDob')) {
    /** जन्म मिति from KYC (BS/AD) or members.dob (AD) → ['dob_bs','dob_ad','dob_label','age']; blanks when unknown. */
    function programMemberDob(array $member, array $kyc): array
    {
        if (!function_exists('nepali_bs_to_ad_string') && is_file(__DIR__ . '/nepali-bs-convert.php')) {
            require_once __DIR__ . '/nepali-bs-convert.php';
        }
        if (!function_exists('bsToAd') && is_file(dirname(__DIR__) . '/core/helpers.php')) {
            require_once dirname(__DIR__) . '/core/helpers.php';
        }
        $valid = static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && $d !== '0000-00-00';
        $bs = trim((string)($kyc['dob_bs'] ?? ''));
        $ad = trim((string)($kyc['dob_ad'] ?? ''));
        $bs = $valid($bs) ? $bs : '';
        $ad = $valid(substr($ad, 0, 10)) ? substr($ad, 0, 10) : '';
        if ($ad === '') {
            $m = substr(trim((string)($member['dob'] ?? '')), 0, 10);
            $ad = $valid($m) ? $m : '';
        }
        if ($bs === '' && $ad !== '' && function_exists('adToBs')) {
            $conv = substr(trim((string)adToBs($ad)), 0, 10);
            $bs = ($conv !== $ad && $valid($conv)) ? $conv : '';
        }
        if ($ad === '' && $bs !== '' && function_exists('bsToAd')) {
            $conv = substr(trim((string)bsToAd($bs)), 0, 10);
            $ad = ($conv !== $bs && $valid($conv)) ? $conv : '';
        }
        $age = null;
        if ($ad !== '') {
            try {
                $years = (new DateTimeImmutable($ad))->diff(new DateTimeImmutable('today'))->y;
                $age = ($years >= 0 && $years <= 120 && $ad <= date('Y-m-d')) ? $years : null;
            } catch (Throwable $e) {
                $age = null;
            }
        }
        $parts = array_filter([$bs !== '' ? $bs . ' वि.सं.' : '', $ad !== '' ? $ad . ' AD' : '']);
        return [
            'dob_bs' => $bs,
            'dob_ad' => $ad,
            'dob_label' => implode(' · ', $parts),
            'age' => $age,
        ];
    }
}

if (!function_exists('programAttendanceRecordedBy')) {
    /** Who / which desk recorded an attendance row → ['by','desk','channel','summary'] for duplicate warnings. */
    function programAttendanceRecordedBy(PDO $db, array $row): array
    {
        $by = '';
        $desk = '';
        $staffId = (int)($row['staff_admin_id'] ?? 0);
        if ($staffId > 0) {
            try {
                $st = $db->prepare('SELECT full_name, username FROM admin_users WHERE id=? LIMIT 1');
                $st->execute([$staffId]);
                $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $name = trim((string)($u['full_name'] ?? ''));
                $user = trim((string)($u['username'] ?? ''));
                $by = $name !== '' ? $name . ($user !== '' ? ' (@' . $user . ')' : '') : ($user !== '' ? '@' . $user : 'Admin #' . $staffId);
            } catch (Throwable $e) {
                $by = 'Admin #' . $staffId;
            }
        }
        $deskId = (int)($row['desk_id'] ?? 0);
        if ($deskId > 0) {
            try {
                $st = $db->prepare('SELECT desk_label FROM program_registration_desks WHERE id=? LIMIT 1');
                $st->execute([$deskId]);
                $desk = trim((string)$st->fetchColumn()) ?: 'Desk #' . $deskId;
            } catch (Throwable $e) {
                $desk = 'Desk #' . $deskId;
            }
        }
        $method = strtoupper(trim((string)($row['attendance_method'] ?? '')));
        if ($by === '' && in_array($method, ['QR_SCAN', 'MEMBER_SELF'], true)) {
            $by = 'सदस्य आफैं';
        }
        $channel = programAttendanceMethodLabel($method);
        $summary = implode(' · ', array_filter([$by, $desk, $channel !== '—' ? $channel : '']));
        return ['by' => $by, 'desk' => $desk, 'channel' => $channel, 'summary' => $summary];
    }
}

if (!function_exists('programFatherNamesForScope')) {
    /** member_id → बुबाको नाम for everyone with VALID attendance in a scope (one query, no N+1). */
    function programFatherNamesForScope(PDO $db, int $scopeId): array
    {
        $kyc = programKycJoinParts($db);
        if ($scopeId < 1 || $kyc['join'] === '') {
            return [];
        }
        $st = $db->prepare("SELECT a.member_id, k.father_name
                            FROM member_program_attendance a
                            JOIN members m ON m.id = a.member_id
                            {$kyc['join']}
                            WHERE a.attendance_scope_key = ? AND a.attendance_status = 'VALID'
                              AND k.father_name IS NOT NULL AND k.father_name <> ''");
        $st->execute([$scopeId]);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $map[(int)$r['member_id']] = (string)$r['father_name'];
        }
        return $map;
    }
}

if (!function_exists('programMemberAttendanceLog')) {
    /** All VALID attendances of a member, newest first, with parent program info. */
    function programMemberAttendanceLog(PDO $db, int $memberId, int $limit = 300): array
    {
        if ($memberId < 1) {
            return [];
        }
        $st = $db->prepare("SELECT a.id, a.attendance_scope_key, a.program_title, a.location_label, a.attendance_method,
                                   a.attended_at, a.occurrence_id, a.desk_id,
                                   p.title AS parent_title, p.program_type, p.event_date
                            FROM member_program_attendance a
                            LEFT JOIN upcoming_programs p ON p.id = a.attendance_scope_key
                            WHERE a.member_id = ? AND a.attendance_status = 'VALID'
                            ORDER BY a.attended_at DESC
                            LIMIT " . max(1, $limit));
        $st->execute([$memberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('programMemberTypeRegister')) {
    /**
     * Held programs of one type (default AGM), newest first, each marked attended / absent for the member.
     * "Held" = at least one VALID attendance exists, so future/cancelled programs never count as absences.
     * @return array{rows:array<int,array<string,mixed>>,attended:int,total:int,streak:int}
     */
    function programMemberTypeRegister(PDO $db, int $memberId, string $type = 'AGM', int $limit = 10, int $excludeProgramId = 0): array
    {
        $out = ['rows' => [], 'attended' => 0, 'total' => 0, 'streak' => 0];
        if ($memberId < 1) {
            return $out;
        }
        $typeSql = ($type !== '' ? ' AND p.program_type = ?' : '') . ($excludeProgramId > 0 ? ' AND p.id <> ?' : '');
        $params = [$memberId];
        if ($type !== '') {
            $params[] = $type;
        }
        if ($excludeProgramId > 0) {
            $params[] = $excludeProgramId;
        }
        $st = $db->prepare("SELECT p.id, p.title, p.program_type, p.event_date,
                                   EXISTS(SELECT 1 FROM member_program_attendance x
                                          WHERE x.attendance_scope_key = p.id AND x.member_id = ? AND x.attendance_status = 'VALID') AS attended
                            FROM upcoming_programs p
                            WHERE (p.parent_program_id IS NULL OR p.parent_program_id = 0){$typeSql}
                              AND EXISTS(SELECT 1 FROM member_program_attendance h
                                         WHERE h.attendance_scope_key = p.id AND h.attendance_status = 'VALID')
                            ORDER BY COALESCE(NULLIF(p.event_date,''), DATE_FORMAT(p.created_at,'%Y-%m-%d')) DESC, p.id DESC
                            LIMIT " . max(1, $limit));
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $streakOpen = true;
        foreach ($rows as &$r) {
            $r['attended'] = (int)$r['attended'] === 1;
            if ($r['attended']) {
                $out['attended']++;
                if ($streakOpen) {
                    $out['streak']++;
                }
            } else {
                $streakOpen = false;
            }
        }
        unset($r);
        $out['rows'] = $rows;
        $out['total'] = count($rows);
        return $out;
    }
}

if (!function_exists('programMemberHistorySummary')) {
    /** Compact summary for the desk lookup card; the program being checked in is left out of the AGM tally. */
    function programMemberHistorySummary(PDO $db, int $memberId, int $currentProgramId = 0): array
    {
        $log = programMemberAttendanceLog($db, $memberId, 3);
        $cnt = $db->prepare("SELECT COUNT(*) FROM member_program_attendance WHERE member_id=? AND attendance_status='VALID'");
        $cnt->execute([$memberId]);
        $agm = programMemberTypeRegister($db, $memberId, 'AGM', 3, $currentProgramId);
        return [
            'total' => (int)$cnt->fetchColumn(),
            'agm_last3_attended' => $agm['attended'],
            'agm_last3_total' => $agm['total'],
            'agm_streak' => $agm['streak'],
            'recent' => array_map(static fn(array $r): array => [
                'title' => (string)($r['parent_title'] ?: $r['program_title']),
                'date' => (string)($r['event_date'] ?? ''),
            ], $log),
        ];
    }
}

if (!function_exists('programAttendanceBreakdown')) {
    /**
     * Counts of VALID attendance in a scope grouped by gender, desk, staff user, method and location.
     * @return array<string,array<int,array{label:string,count:int}>>
     */
    function programAttendanceBreakdown(PDO $db, int $scopeId): array
    {
        $out = ['gender' => [], 'desk' => [], 'staff' => [], 'method' => [], 'location' => []];
        if ($scopeId < 1) {
            return $out;
        }
        $kyc = programKycJoinParts($db);
        $st = $db->prepare("SELECT {$kyc['gender']} AS gender_raw, COUNT(*) AS c
                            FROM member_program_attendance a
                            LEFT JOIN members m ON m.id = a.member_id
                            {$kyc['join']}
                            WHERE a.attendance_scope_key = ? AND a.attendance_status = 'VALID'
                            GROUP BY gender_raw");
        $st->execute([$scopeId]);
        $g = ['male' => 0, 'female' => 0, 'other' => 0, 'unknown' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $g[programGenderKey($r['gender_raw'] ?? '')] += (int)$r['c'];
        }
        foreach ($g as $k => $c) {
            if ($c > 0) {
                $out['gender'][] = ['label' => programGenderLabel($k), 'count' => $c];
            }
        }

        $st = $db->prepare("SELECT a.desk_id, d.desk_label, o.location_name, COUNT(*) AS c
                            FROM member_program_attendance a
                            LEFT JOIN program_registration_desks d ON d.id = a.desk_id
                            LEFT JOIN program_occurrences o ON o.id = d.occurrence_id
                            WHERE a.attendance_scope_key = ? AND a.attendance_status = 'VALID'
                            GROUP BY a.desk_id, d.desk_label, o.location_name ORDER BY c DESC");
        $st->execute([$scopeId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $label = (int)($r['desk_id'] ?? 0) > 0
                ? (string)($r['desk_label'] ?: 'Desk #' . (int)$r['desk_id']) . (!empty($r['location_name']) ? ' — ' . $r['location_name'] : '')
                : 'Desk नतोकिएको (QR / approve / default)';
            $out['desk'][] = ['label' => $label, 'count' => (int)$r['c']];
        }

        $st = $db->prepare("SELECT a.staff_admin_id, u.full_name, u.username, COUNT(*) AS c
                            FROM member_program_attendance a
                            LEFT JOIN admin_users u ON u.id = a.staff_admin_id
                            WHERE a.attendance_scope_key = ? AND a.attendance_status = 'VALID'
                            GROUP BY a.staff_admin_id, u.full_name, u.username ORDER BY c DESC");
        try {
            $st->execute([$scopeId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $label = (int)($r['staff_admin_id'] ?? 0) > 0
                    ? trim((string)($r['full_name'] ?: $r['username'] ?: 'Admin #' . (int)$r['staff_admin_id'])) . (!empty($r['username']) ? ' (@' . $r['username'] . ')' : '')
                    : 'Staff बिना (सदस्य आफैं / QR)';
                $out['staff'][] = ['label' => $label, 'count' => (int)$r['c']];
            }
        } catch (Throwable $e) {
            error_log('[program-insights] staff breakdown: ' . $e->getMessage());
        }

        $st = $db->prepare("SELECT attendance_method, COUNT(*) AS c FROM member_program_attendance
                            WHERE attendance_scope_key = ? AND attendance_status = 'VALID'
                            GROUP BY attendance_method ORDER BY c DESC");
        $st->execute([$scopeId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out['method'][] = ['label' => programAttendanceMethodLabel($r['attendance_method'] ?? ''), 'count' => (int)$r['c']];
        }

        $st = $db->prepare("SELECT COALESCE(NULLIF(location_label,''), 'मुख्य स्थान') AS loc, COUNT(*) AS c FROM member_program_attendance
                            WHERE attendance_scope_key = ? AND attendance_status = 'VALID'
                            GROUP BY loc ORDER BY c DESC");
        $st->execute([$scopeId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out['location'][] = ['label' => (string)$r['loc'], 'count' => (int)$r['c']];
        }
        return $out;
    }
}

if (!function_exists('programDesksForProgram')) {
    /** Desks of a program with location, assigned staff and VALID attendance count. */
    function programDesksForProgram(PDO $db, int $programId, bool $activeOnly = false): array
    {
        $st = $db->prepare("SELECT d.*, o.location_name, u.full_name AS staff_name, u.username AS staff_username,
                                   (SELECT COUNT(*) FROM member_program_attendance a WHERE a.desk_id = d.id AND a.attendance_status = 'VALID') AS attended_count
                            FROM program_registration_desks d
                            LEFT JOIN program_occurrences o ON o.id = d.occurrence_id
                            LEFT JOIN admin_users u ON u.id = d.assigned_staff_admin_id
                            WHERE d.parent_program_id = ?" . ($activeOnly ? ' AND d.is_active = 1' : '') . "
                            ORDER BY d.is_active DESC, d.desk_label ASC, d.id ASC");
        $st->execute([$programId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
