<?php
/**
 * notification_templates — admin/notification-templates.php + ensure-admin
 */
if (!function_exists('ensureNotificationTemplatesSchema')) {
    function ensureNotificationTemplatesSchema(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS notification_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(64) NOT NULL,
            audience ENUM('admin','member') NOT NULL DEFAULT 'admin',
            channel ENUM('email','sms') NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            subject VARCHAR(255) NULL,
            body TEXT NOT NULL,
            variables VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_event_audience_channel (event_type, audience, channel)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $done = true;
        } catch (Throwable $e) {
            error_log('[notif-tpl-schema] ' . $e->getMessage());
        }
    }
}
