<?php
/**
 * member_of_year — ensure-admin र admin/member-of-year.php (standalone) दुवै
 */
if (!function_exists('ensureMemberOfYearTable')) {
    function ensureMemberOfYearTable(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS member_of_year (
            id INT AUTO_INCREMENT PRIMARY KEY,
            spotlight_year VARCHAR(10) NOT NULL UNIQUE,
            member_name VARCHAR(100) NOT NULL,
            member_name_en VARCHAR(100),
            member_id VARCHAR(50),
            photo VARCHAR(255),
            member_since VARCHAR(20),
            quote TEXT,
            quote_en TEXT,
            achievement TEXT,
            achievement_en TEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* पुरानो standalone install (लामो नाम/फोटो पाथ) सँग मेल */
            try {
                $db->exec('ALTER TABLE member_of_year MODIFY COLUMN member_name VARCHAR(200) NOT NULL');
            } catch (Throwable $e) {
            }
            try {
                $db->exec('ALTER TABLE member_of_year MODIFY COLUMN photo VARCHAR(500)');
            } catch (Throwable $e) {
            }

            $done = true;
        } catch (Throwable $e) {
        }
    }
}
