<?php
/**
 * Audit Log Helper — Admin Activity Tracking
 * Writes to admin_activity_log table (defined in install.sql).
 * Include once from admin-header.php so every admin page has access.
 */

if (!function_exists('writeAuditLog')) {
    /**
     * Write an admin action to the audit log.
     *
     * @param string $action      Short code: 'notice_create', 'settings_update', etc.
     * @param string $details     Human-readable description (stored as plain text in `details` JSON field).
     * @param string $target_type Entity type: 'notice', 'member', 'settings', …
     * @param int    $target_id   Entity PK (0 = not applicable).
     */
    function writeAuditLog(string $action, string $details = '', string $target_type = '', int $target_id = 0): void {
        try {
            $db      = getDB();
            $adminId = (int)($_SESSION['admin_id'] ?? 0);

            // Proxy-aware real IP (take first value if comma-separated)
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? $_SERVER['HTTP_X_REAL_IP']
                ?? $_SERVER['REMOTE_ADDR']
                ?? '0.0.0.0';
            $ip = trim(explode(',', (string)$ip)[0]);
            if (strlen($ip) > 45) $ip = substr($ip, 0, 45);

            $db->prepare(
                "INSERT INTO admin_activity_log
                    (admin_id, action, target_type, target_id, details, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            )->execute([
                $adminId,
                $action,
                $target_type !== '' ? $target_type : null,
                $target_id  >  0   ? $target_id   : null,
                $details    !== '' ? $details      : null,
                $ip,
            ]);
        } catch (\Throwable $e) {
            // Never break a page because of a log write failure
            error_log('writeAuditLog error: ' . $e->getMessage());
        }
    }
}
