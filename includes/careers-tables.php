<?php
/**
 * careers — admin र public दुवैले प्रयोग गर्ने canonical DDL
 * (पहिले ensure-tables मा «सरल» र ensure-admin मा «विस्तृत» फरक थियो —
 *  ensurePublic पहिले चलेमा admin CREATE IF NOT EXISTS skip भई कलम हराउन सक्थ्यो।)
 */
if (!function_exists('ensureCareersTables')) {
    function ensureCareersTables(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS careers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            title_np VARCHAR(255) DEFAULT NULL,
            department VARCHAR(150) DEFAULT NULL,
            location VARCHAR(150) DEFAULT NULL,
            job_type VARCHAR(50) DEFAULT 'full_time',
            description TEXT,
            description_np TEXT,
            requirements TEXT,
            deadline DATE DEFAULT NULL,
            attachment VARCHAR(255) DEFAULT NULL,
            vacancies INT DEFAULT 1,
            min_qualification VARCHAR(255) DEFAULT NULL,
            experience_required VARCHAR(150) DEFAULT NULL,
            salary_range VARCHAR(150) DEFAULT NULL,
            allow_online_apply TINYINT(1) DEFAULT 1,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_careers_active (is_active),
            INDEX idx_careers_deadline (deadline)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* पुरानो «status» स्किमा — safeAddColumn भन्दा अगाडि */
            try {
                $hasActive = function_exists('safeColumnExists') && safeColumnExists('careers', 'is_active');
                $hasStatus = function_exists('safeColumnExists') && safeColumnExists('careers', 'status');
                if (!$hasActive && $hasStatus) {
                    $db->exec('ALTER TABLE careers ADD COLUMN is_active TINYINT(1) DEFAULT 1');
                    $db->exec("UPDATE careers SET is_active = 1 WHERE status = 'active'");
                    $db->exec("UPDATE careers SET is_active = 0 WHERE status IN ('closed','draft')");
                }
            } catch (Throwable $e) {
            }

            if (function_exists('safeAddColumn')) {
                safeAddColumn($db, 'careers', 'title_np', 'VARCHAR(255) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'department', 'VARCHAR(150) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'job_type', "VARCHAR(50) DEFAULT 'full_time'");
                safeAddColumn($db, 'careers', 'description_np', 'TEXT');
                safeAddColumn($db, 'careers', 'attachment', 'VARCHAR(255) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'vacancies', 'INT DEFAULT 1');
                safeAddColumn($db, 'careers', 'min_qualification', 'VARCHAR(255) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'experience_required', 'VARCHAR(150) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'allow_online_apply', 'TINYINT(1) DEFAULT 1');
                safeAddColumn($db, 'careers', 'is_active', 'TINYINT(1) DEFAULT 1');
                safeAddColumn($db, 'careers', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
            }

            $done = true;
        } catch (Throwable $e) {
        }
    }
}
