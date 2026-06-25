<?php
/**
 * HRM Internal Messages — auto-create helper.
 */
if (!function_exists('ensureHrmMessagesTable')) {
    function ensureHrmMessagesTable(PDO $db): void {
        static $done = false;
        if ($done) return;
        try {
            $exists = $db->query("SHOW TABLES LIKE 'hrm_internal_messages'")->fetchColumn();
            if ($exists) { $done = true; return; }
        } catch (\Throwable $e) {}
        $sqlFile = __DIR__ . '/../../database/install.sql';
        if (!is_file($sqlFile)) { $done = true; return; }
        $sql = file_get_contents($sqlFile);
        if (!$sql) { $done = true; return; }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $sql = preg_replace('~/\*.*?\*/~s', '', (string)$sql);
        foreach (preg_split('/;\s*/u', (string)$sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') continue;
            try { $db->exec($stmt); } catch (\Throwable $e) {}
        }
        $done = true;
    }
}

if (!function_exists('hrmCountUnreadFor')) {
    function hrmCountUnreadFor(PDO $db, int $employeeId): int {
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM hrm_internal_messages WHERE receiver_employee_id=? AND is_read=0");
            $st->execute([$employeeId]);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) { return 0; }
    }
}
