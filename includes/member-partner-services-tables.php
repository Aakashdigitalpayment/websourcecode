<?php
/**
 * member_partner_services — verify.php (partner service log)
 */
if (!function_exists('ensureMemberPartnerServicesTable')) {
    function ensureMemberPartnerServicesTable(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS member_partner_services (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        member_id       INT NOT NULL,
        member_card_no  VARCHAR(50) DEFAULT '',
        partner_id      INT NULL,
        partner_name    VARCHAR(255) NOT NULL,
        service_name    VARCHAR(255) DEFAULT '',
        service_taken   TINYINT(1) DEFAULT 0,
        service_note    VARCHAR(500) DEFAULT '',
        verified_by_ip  VARCHAR(45) DEFAULT '',
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mps_member  (member_id),
        INDEX idx_mps_partner (partner_id),
        INDEX idx_mps_date    (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            if (function_exists('safeAddColumn')) {
                safeAddColumn($db, 'member_partner_services', 'service_name', "VARCHAR(255) DEFAULT ''");
            }
            $done = true;
        } catch (Throwable $e) {
        }
    }
}
