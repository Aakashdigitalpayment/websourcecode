<?php
/**
 * HRM Internal Messages — auto-create helper.
 */
if (!function_exists('ensureHrmMessagesTable')) {
    function ensureHrmMessagesTable(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        try {
            $exists = function_exists('dbTableExists')
                ? dbTableExists('hrm_internal_messages')
                : false;
            if (!$exists) {
                try {
                    $probe = $db->query("SHOW TABLES LIKE 'hrm_internal_messages'");
                    $exists = $probe && $probe->fetch(PDO::FETCH_NUM) !== false;
                    if ($probe instanceof PDOStatement) {
                        $probe->closeCursor();
                    }
                } catch (Throwable $e) {
                    $exists = false;
                }
            }
            if ($exists) {
                $done = true;
                return;
            }
        } catch (Throwable $e) {
            /* fall through to create */
        }

        /* Create only this table — never replay full install.sql (causes PDO 2014). */
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS hrm_internal_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    sender_admin_id INT DEFAULT NULL,
                    sender_employee_id INT DEFAULT NULL,
                    receiver_employee_id INT NOT NULL,
                    subject VARCHAR(200) DEFAULT NULL,
                    body TEXT NOT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    read_at DATETIME DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_receiver (receiver_employee_id, is_read),
                    INDEX idx_sender (sender_admin_id),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            error_log('[ensureHrmMessagesTable] ' . $e->getMessage());
        }
        $done = true;
    }
}

if (!function_exists('hrmCountUnreadFor')) {
    function hrmCountUnreadFor(PDO $db, int $employeeId): int
    {
        try {
            $st = $db->prepare(
                'SELECT COUNT(*) FROM hrm_internal_messages WHERE receiver_employee_id=? AND is_read=0'
            );
            $st->execute([$employeeId]);
            $n = (int) $st->fetchColumn();
            $st->closeCursor();
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }
}
