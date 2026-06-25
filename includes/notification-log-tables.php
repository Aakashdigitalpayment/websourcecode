<?php
/**
 * notification_log — notifications.php र admin schema दुवै
 */
if (!function_exists('ensureNotificationLogTable')) {
    function ensureNotificationLogTable(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS notification_log (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                event_type  VARCHAR(100) NOT NULL COMMENT 'loan_application, grievance, etc.',
                channel     ENUM('email','sms') NOT NULL,
                recipient   VARCHAR(200) NOT NULL,
                subject     VARCHAR(500),
                message     TEXT,
                status      ENUM('sent','failed') DEFAULT 'sent',
                error_msg   VARCHAR(500),
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event (event_type),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            COMMENT='Notification send log'");
            $done = true;
        } catch (Throwable $e) {
        }
    }
}
