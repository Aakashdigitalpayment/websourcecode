<?php
/**
 * कार्यक्रम / उपस्थिति / pre-registration — तालिकाहरू (schema lock भए पनि idempotent)
 */
if (!function_exists('ensureProgramTables')) {
    function ensureProgramTables(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (!$db && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Throwable $e) {
                return;
            }
        }
        if (!$db instanceof PDO) {
            return;
        }
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS upcoming_programs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(180) NOT NULL,
                description TEXT NULL,
                event_date DATE NULL,
                event_time VARCHAR(30) NULL,
                location VARCHAR(180) NULL,
                is_active TINYINT(1) DEFAULT 1,
                pre_registration_open TINYINT(1) DEFAULT 0,
                qr_token VARCHAR(64) UNIQUE NULL,
                qr_starts_at DATETIME NULL,
                qr_expires_at DATETIME NULL,
                created_by VARCHAR(100) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_up_date (event_date),
                INDEX idx_up_active (is_active),
                INDEX idx_up_prereg (pre_registration_open),
                INDEX idx_up_qr (qr_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            foreach ([
                'ALTER TABLE upcoming_programs ADD COLUMN pre_registration_open TINYINT(1) DEFAULT 0 AFTER is_active',
                'ALTER TABLE upcoming_programs ADD COLUMN qr_token VARCHAR(64) UNIQUE NULL',
                'ALTER TABLE upcoming_programs ADD COLUMN qr_starts_at DATETIME NULL AFTER qr_token',
                'ALTER TABLE upcoming_programs ADD COLUMN qr_expires_at DATETIME NULL AFTER qr_starts_at',
                'ALTER TABLE upcoming_programs ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
                'ALTER TABLE upcoming_programs ADD COLUMN created_by VARCHAR(100) NULL',
                'ALTER TABLE upcoming_programs ADD COLUMN qr_enabled TINYINT(1) DEFAULT 1 AFTER pre_registration_open',
                'ALTER TABLE upcoming_programs ADD INDEX idx_up_qr (qr_token)',
                'ALTER TABLE upcoming_programs ADD INDEX idx_up_prereg (pre_registration_open)',
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Throwable $e) {
                }
            }

            $db->exec("CREATE TABLE IF NOT EXISTS member_program_attendance (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                member_card_no VARCHAR(60) DEFAULT '',
                program_id INT NOT NULL,
                program_title VARCHAR(180) NOT NULL,
                is_priority TINYINT(1) DEFAULT 0,
                attendance_note VARCHAR(500) DEFAULT '',
                verified_by_ip VARCHAR(45) DEFAULT '',
                source VARCHAR(30) DEFAULT 'verify_portal',
                attended_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_member_program (member_id, program_id),
                INDEX idx_mpa_member (member_id),
                INDEX idx_mpa_program (program_id),
                INDEX idx_mpa_date (attended_at),
                INDEX idx_mpa_prog_att (program_id, attended_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            foreach ([
                'ALTER TABLE member_program_attendance ADD INDEX idx_mpa_date (attended_at)',
                'ALTER TABLE member_program_attendance ADD INDEX idx_mpa_prog_att (program_id, attended_at)',
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Throwable $e) {
                }
            }

            $db->exec("CREATE TABLE IF NOT EXISTS member_program_attendance_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                member_card_no VARCHAR(60) DEFAULT '',
                member_name VARCHAR(150) DEFAULT '',
                member_phone VARCHAR(30) DEFAULT '',
                member_address VARCHAR(255) DEFAULT '',
                program_id INT NOT NULL,
                program_title VARCHAR(180) NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL DEFAULT NULL,
                verified_by_ip VARCHAR(45) DEFAULT '',
                user_agent VARCHAR(255) DEFAULT '',
                admin_id INT NULL,
                admin_note VARCHAR(500) DEFAULT '',
                source VARCHAR(40) DEFAULT 'public_qr_request',
                INDEX idx_mpar_status (status),
                INDEX idx_mpar_program (program_id),
                INDEX idx_mpar_member (member_id),
                INDEX idx_mpar_status_prog (status, program_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            try {
                $db->exec('ALTER TABLE member_program_attendance_requests ADD INDEX idx_mpar_status_prog (status, program_id)');
            } catch (Throwable $e) {
            }
            foreach ([
                'ALTER TABLE member_program_attendance_requests ADD COLUMN member_phone VARCHAR(30) DEFAULT "" AFTER member_name',
                'ALTER TABLE member_program_attendance_requests ADD COLUMN member_address VARCHAR(255) DEFAULT "" AFTER member_phone',
                'ALTER TABLE member_program_attendance_requests ADD COLUMN user_agent VARCHAR(255) DEFAULT "" AFTER verified_by_ip',
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Throwable $e) {
                }
            }

            $db->exec("CREATE TABLE IF NOT EXISTS member_program_preregistrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                member_card_no VARCHAR(60) DEFAULT '',
                member_name VARCHAR(150) DEFAULT '',
                phone VARCHAR(30) DEFAULT '',
                email VARCHAR(120) DEFAULT '',
                program_id INT NOT NULL,
                program_title VARCHAR(180) NOT NULL,
                event_date DATE NULL,
                note VARCHAR(500) DEFAULT '',
                source VARCHAR(30) DEFAULT 'member_portal',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_member_program_prereg (member_id, program_id),
                INDEX idx_mppr_member (member_id),
                INDEX idx_mppr_program (program_id),
                INDEX idx_mppr_date (created_at),
                INDEX idx_mppr_prog_created (program_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            foreach ([
                'ALTER TABLE member_program_preregistrations ADD COLUMN email VARCHAR(120) DEFAULT \'\'',
                'ALTER TABLE member_program_preregistrations ADD COLUMN event_date DATE NULL',
                'ALTER TABLE member_program_preregistrations ADD COLUMN note VARCHAR(500) DEFAULT \'\'',
                'ALTER TABLE member_program_preregistrations ADD INDEX idx_mppr_prog_created (program_id, created_at)',
                'ALTER TABLE member_program_preregistrations ADD INDEX idx_pr_member (member_id)',
                'ALTER TABLE member_program_preregistrations ADD INDEX idx_pr_program (program_id)',
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Throwable $e) {
                }
            }

            /* ── Program v2: parent/occurrence/multi-location (additive) ── */
            foreach ([
                "ALTER TABLE upcoming_programs ADD COLUMN program_type ENUM('General','AGM','SGM','Orientation','Training','Seminar','Workshop','Financial_Literacy','Other') NOT NULL DEFAULT 'General' AFTER title",
                'ALTER TABLE upcoming_programs ADD COLUMN parent_program_id INT NULL AFTER program_type',
                'ALTER TABLE upcoming_programs ADD COLUMN is_multi_location TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_program_id',
                'ALTER TABLE upcoming_programs ADD COLUMN attendance_open_at DATETIME NULL AFTER qr_expires_at',
                'ALTER TABLE upcoming_programs ADD COLUMN attendance_close_at DATETIME NULL AFTER attendance_open_at',
                "ALTER TABLE upcoming_programs ADD COLUMN eligible_member_scope VARCHAR(30) NOT NULL DEFAULT 'all_active' AFTER attendance_close_at",
                'ALTER TABLE upcoming_programs ADD COLUMN instant_attendance TINYINT(1) NOT NULL DEFAULT 0 AFTER eligible_member_scope',
                'ALTER TABLE upcoming_programs ADD COLUMN shared_qr_mode TINYINT(1) NOT NULL DEFAULT 1 AFTER instant_attendance',
                'ALTER TABLE upcoming_programs ADD INDEX idx_up_parent (parent_program_id)',
                'ALTER TABLE upcoming_programs ADD INDEX idx_up_multi (is_multi_location)',
                'ALTER TABLE upcoming_programs ADD INDEX idx_up_type (program_type)',
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Throwable $e) {
                }
            }

            $db->exec("CREATE TABLE IF NOT EXISTS program_occurrences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                parent_program_id INT NOT NULL,
                location_name VARCHAR(180) NOT NULL DEFAULT '',
                venue_id INT NULL,
                event_date DATE NULL,
                start_time VARCHAR(30) NULL,
                end_time VARCHAR(30) NULL,
                attendance_open_at DATETIME NULL,
                attendance_close_at DATETIME NULL,
                qr_token VARCHAR(64) UNIQUE NULL,
                qr_enabled TINYINT(1) NOT NULL DEFAULT 1,
                qr_starts_at DATETIME NULL,
                qr_expires_at DATETIME NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_po_parent (parent_program_id),
                INDEX idx_po_active (is_active),
                INDEX idx_po_date (event_date),
                INDEX idx_po_qr (qr_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            foreach ([
                'ALTER TABLE member_program_attendance ADD COLUMN occurrence_id INT NULL AFTER program_id',
                'ALTER TABLE member_program_attendance ADD COLUMN parent_program_id INT NULL AFTER occurrence_id',
                'ALTER TABLE member_program_attendance ADD COLUMN attendance_scope_key INT NOT NULL DEFAULT 0 AFTER parent_program_id',
                "ALTER TABLE member_program_attendance ADD COLUMN attendance_method ENUM('MEMBER_SELF','ADMIN_MANUAL','QR_SCAN','STAFF_VERIFY','ADMIN_APPROVE','ADMIN_PREREG') NOT NULL DEFAULT 'STAFF_VERIFY' AFTER source",
                "ALTER TABLE member_program_attendance ADD COLUMN attendance_status ENUM('VALID','VOID') NOT NULL DEFAULT 'VALID' AFTER attendance_method",
                'ALTER TABLE member_program_attendance ADD COLUMN location_label VARCHAR(180) NULL AFTER attendance_status',
                'ALTER TABLE member_program_attendance ADD COLUMN desk_id INT NULL AFTER location_label',
                'ALTER TABLE member_program_attendance ADD COLUMN staff_admin_id INT NULL AFTER desk_id',
                'ALTER TABLE member_program_attendance ADD COLUMN device_fingerprint VARCHAR(120) NULL AFTER staff_admin_id',
                'ALTER TABLE member_program_attendance ADD COLUMN voided_by INT NULL AFTER device_fingerprint',
                'ALTER TABLE member_program_attendance ADD COLUMN voided_at DATETIME NULL AFTER voided_by',
                'ALTER TABLE member_program_attendance ADD COLUMN void_reason VARCHAR(500) NULL AFTER voided_at',
                'ALTER TABLE member_program_attendance ADD INDEX idx_mpa_occurrence (occurrence_id)',
                'ALTER TABLE member_program_attendance ADD INDEX idx_mpa_parent (parent_program_id)',
                'ALTER TABLE member_program_attendance ADD INDEX idx_mpa_scope (attendance_scope_key)',
                'ALTER TABLE member_program_attendance ADD INDEX idx_mpa_scope_member (attendance_scope_key, member_id)',
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Throwable $e) {
                }
            }
            try {
                $db->exec('ALTER TABLE member_program_attendance ADD UNIQUE KEY uniq_scope_member_status (attendance_scope_key, member_id, attendance_status)');
            } catch (Throwable $e) {
            }
            /* Legacy uniq_member_program blocks void + re-record; scope+status unique is canonical */
            try {
                $db->exec('ALTER TABLE member_program_attendance DROP INDEX uniq_member_program');
            } catch (Throwable $e) {
            }
            try {
                $db->exec('ALTER TABLE member_program_attendance ADD INDEX idx_mpa_member_program (member_id, program_id)');
            } catch (Throwable $e) {
            }

            foreach ([
                'ALTER TABLE member_program_attendance_requests ADD COLUMN occurrence_id INT NULL AFTER program_id',
                'ALTER TABLE member_program_attendance_requests ADD INDEX idx_mpar_occurrence (occurrence_id)',
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Throwable $e) {
                }
            }

            $db->exec("CREATE TABLE IF NOT EXISTS program_attendance_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                parent_program_id INT NOT NULL,
                program_id INT NOT NULL,
                occurrence_id INT NULL,
                attempted_method VARCHAR(40) NOT NULL DEFAULT '',
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                previous_attendance_id INT NULL,
                result ENUM('DUPLICATE_BLOCKED','WINDOW_CLOSED','INELIGIBLE','INVALID_MEMBER','INVALID_PROGRAM','OTHER') NOT NULL DEFAULT 'OTHER',
                result_message VARCHAR(500) DEFAULT '',
                verified_by_ip VARCHAR(45) DEFAULT '',
                desk_id INT NULL,
                staff_admin_id INT NULL,
                INDEX idx_paa_member (member_id),
                INDEX idx_paa_parent (parent_program_id),
                INDEX idx_paa_program (program_id),
                INDEX idx_paa_result (result),
                INDEX idx_paa_at (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS program_registration_desks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                parent_program_id INT NOT NULL,
                occurrence_id INT NULL,
                desk_label VARCHAR(60) NOT NULL DEFAULT 'Desk 01',
                assigned_staff_admin_id INT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_prd_parent (parent_program_id),
                INDEX idx_prd_occurrence (occurrence_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS program_audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                action_type VARCHAR(60) NOT NULL,
                program_id INT NULL,
                occurrence_id INT NULL,
                attendance_id INT NULL,
                member_id INT NULL,
                admin_id INT NULL,
                reason VARCHAR(500) DEFAULT '',
                payload_json TEXT NULL,
                ip_address VARCHAR(45) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pal_program (program_id),
                INDEX idx_pal_attendance (attendance_id),
                INDEX idx_pal_action (action_type),
                INDEX idx_pal_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            if (function_exists('programBackfillV2Columns')) {
                programBackfillV2Columns($db);
            }

            $done = true;
        } catch (Throwable $e) {
        }
    }
}

/**
 * कार्यक्रम event_date (BS वा AD) लाई AD Y-m-d मा।
 * Admin nepali-datepicker ले BS (२०७०+) राख्छ — strtotime सिधै चलाउँदा expiry/past गलत हुन्छ।
 */
if (!function_exists('programEventDateToAd')) {
    function programEventDateToAd(?string $date): string
    {
        $date = trim((string)$date);
        if ($date === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m)) {
            return '';
        }
        $y = (int)$m[1];
        if ($y >= 2070 && function_exists('bsToAd')) {
            $ad = trim((string)bsToAd(substr($date, 0, 10)));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $ad)) {
                return substr($ad, 0, 10);
            }
        }
        return substr($date, 0, 10);
    }
}

/** BS (or AD) date + H:i → AD MySQL DATETIME for QR window storage. */
if (!function_exists('programCombineBsDateTime')) {
    function programCombineBsDateTime(string $dateIn, string $timeIn): string
    {
        $dateIn = trim($dateIn);
        $timeIn = trim($timeIn);
        if ($dateIn === '') {
            return '';
        }
        if ($timeIn === '') {
            $timeIn = '00:00:00';
        }
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeIn)) {
            return '';
        }
        if (substr_count($timeIn, ':') === 1) {
            $timeIn .= ':00';
        }
        $datePart = substr($dateIn, 0, 10);
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', $datePart, $m)) {
            return '';
        }
        $y = (int)$m[1];
        if ($y >= 2070 && function_exists('bsToAd')) {
            $ad = trim((string)bsToAd($datePart));
            $ad = preg_match('/^\d{4}-\d{2}-\d{2}/', $ad) ? substr($ad, 0, 10) : '';
        } else {
            $ad = $datePart;
        }
        if ($ad === '') {
            return '';
        }
        $ts = strtotime($ad . ' ' . $timeIn);
        return $ts ? date('Y-m-d H:i:s', $ts) : '';
    }
}

/** Stored AD DATETIME → BS Y-m-d for nepali-datepicker display. */
if (!function_exists('programMysqlDtToBsDate')) {
    function programMysqlDtToBsDate(?string $mysqlDt): string
    {
        if (!$mysqlDt) {
            return '';
        }
        $ad = substr((string)$mysqlDt, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ad)) {
            return '';
        }
        if (function_exists('adToBs')) {
            $bs = trim((string)adToBs($ad));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $bs)) {
                return substr($bs, 0, 10);
            }
        }
        return $ad;
    }
}

if (!function_exists('programMysqlDtToTime')) {
    function programMysqlDtToTime(?string $mysqlDt, string $fallback = '00:00'): string
    {
        if (!$mysqlDt || strlen((string)$mysqlDt) < 16) {
            return $fallback;
        }
        return substr((string)$mysqlDt, 11, 5) ?: $fallback;
    }
}

/** Program type options for admin forms */
if (!function_exists('programTypeOptions')) {
    function programTypeOptions(): array
    {
        return [
            'General' => ['np' => 'सामान्य कार्यक्रम', 'en' => 'General Program'],
            'AGM' => ['np' => 'वार्षिक साधारण सभा (AGM)', 'en' => 'Annual General Meeting'],
            'SGM' => ['np' => 'विशेष साधारण सभा (SGM)', 'en' => 'Special General Meeting'],
            'Orientation' => ['np' => 'सदस्य परिचय', 'en' => 'Member Orientation'],
            'Training' => ['np' => 'प्रशिक्षण', 'en' => 'Training'],
            'Seminar' => ['np' => 'गोष्ठी', 'en' => 'Seminar'],
            'Workshop' => ['np' => 'कार्यशाला', 'en' => 'Workshop'],
            'Financial_Literacy' => ['np' => 'वित्तीय साक्षरता', 'en' => 'Financial Literacy'],
            'Other' => ['np' => 'अन्य', 'en' => 'Other'],
        ];
    }
}

if (!function_exists('programTypeLabel')) {
    function programTypeLabel(?string $type, bool $english = false): string
    {
        $opts = programTypeOptions();
        $t = trim((string)$type);
        if ($t === '' || !isset($opts[$t])) {
            return $english ? 'General Program' : 'सामान्य कार्यक्रम';
        }
        return $english ? $opts[$t]['en'] : $opts[$t]['np'];
    }
}

/** One-time / idempotent backfill for v2 attendance columns */
if (!function_exists('programBackfillV2Columns')) {
    function programBackfillV2Columns(PDO $db): void
    {
        static $ran = false;
        if ($ran) {
            return;
        }
        $ran = true;
        try {
            $db->exec("UPDATE member_program_attendance
                SET attendance_scope_key = program_id,
                    parent_program_id = COALESCE(parent_program_id, program_id),
                    attendance_status = COALESCE(attendance_status, 'VALID')
                WHERE attendance_scope_key = 0 OR attendance_scope_key IS NULL OR parent_program_id IS NULL");
            $db->exec("UPDATE member_program_attendance a
                INNER JOIN upcoming_programs p ON p.id = a.program_id
                SET a.attendance_method = CASE a.source
                    WHEN 'member_portal_qr_pending' THEN 'QR_SCAN'
                    WHEN 'member_portal_pending' THEN 'MEMBER_SELF'
                    WHEN 'program_verify_page' THEN 'STAFF_VERIFY'
                    WHEN 'admin_request_approve' THEN 'ADMIN_APPROVE'
                    WHEN 'admin_prereg' THEN 'ADMIN_PREREG'
                    WHEN 'registration_desk' THEN 'ADMIN_MANUAL'
                    ELSE 'STAFF_VERIFY'
                END
                WHERE a.attendance_method = 'STAFF_VERIFY' AND a.source IS NOT NULL");
            $db->exec("UPDATE member_program_attendance a
                INNER JOIN upcoming_programs p ON p.id = a.program_id
                SET a.location_label = COALESCE(a.location_label, NULLIF(p.location, ''))
                WHERE a.location_label IS NULL OR a.location_label = ''");
            try {
                $db->exec('ALTER TABLE member_program_attendance DROP INDEX uniq_member_program');
            } catch (Throwable $e) {
            }
            try {
                $db->exec('ALTER TABLE member_program_attendance ADD INDEX idx_mpa_member_program (member_id, program_id)');
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
            error_log('[programBackfillV2Columns] ' . $e->getMessage());
        }
    }
}

/** Admin UI मा attendance request source लेबल */
if (!function_exists('programAttendanceSourceLabel')) {
    function programAttendanceSourceLabel(?string $source): string
    {
        $s = trim((string)$source);
        $map = [
            'member_portal_qr_pending' => 'Portal QR',
            'member_portal_pending' => 'Portal Attend',
            'public_qr_unmatched_request' => 'Public (unmatched)',
            'public_attend' => 'Public attend',
            'program_verify_page' => 'Staff Verify',
            'admin_request_approve' => 'Admin approve',
            'verify_portal' => 'Verify portal',
        ];
        return $map[$s] ?? ($s !== '' ? $s : '—');
    }
}
