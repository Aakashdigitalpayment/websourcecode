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

            $done = true;
        } catch (Throwable $e) {
        }
    }
}
