<?php
/**
 * साझेदार सुविधाहरू — DDL एकै ठाउँ
 */
if (!function_exists('ensurePartnerFacilitiesTables')) {
    function ensurePartnerFacilitiesTables(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS partner_facilities (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            partner_name    VARCHAR(200) NOT NULL,
            location        VARCHAR(200) NOT NULL DEFAULT '',
            facility_type   VARCHAR(100) NOT NULL DEFAULT '',
            discount_percent DECIMAL(5,2) DEFAULT 0,
            description     TEXT,
            is_active       TINYINT DEFAULT 1,
            display_order   INT DEFAULT 0,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $done = true;
        } catch (Throwable $e) {
        }
    }
}
