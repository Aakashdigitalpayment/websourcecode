<?php
declare(strict_types=1);

if (!function_exists('ensureRequestStatusHistoryTable')) {
    function ensureRequestStatusHistoryTable(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS request_status_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module VARCHAR(64) NOT NULL,
            request_id INT NOT NULL,
            old_status VARCHAR(64) DEFAULT NULL,
            new_status VARCHAR(64) DEFAULT NULL,
            admin_comment TEXT,
            notify_sent TINYINT(1) NOT NULL DEFAULT 0,
            actor_admin_id INT DEFAULT NULL,
            actor_name VARCHAR(120) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_module_request (module, request_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── Backward-compatible column upgrades ──
           v2: per-channel notification audit columns. Run only if missing. */
        $newCols = [
            'admin_chose_to_notify' => "TINYINT(1) NOT NULL DEFAULT 0",
            'notify_email_status'   => "VARCHAR(20) NOT NULL DEFAULT 'not_attempted'", /* sent | failed | skipped | not_attempted */
            'notify_email_reason'   => "VARCHAR(180) DEFAULT NULL",
            'notify_email_to'       => "VARCHAR(180) DEFAULT NULL",
            'notify_sms_status'     => "VARCHAR(20) NOT NULL DEFAULT 'not_attempted'",
            'notify_sms_reason'     => "VARCHAR(180) DEFAULT NULL",
            'notify_sms_to'         => "VARCHAR(40) DEFAULT NULL",
        ];
        try {
            $existing = [];
            foreach ($db->query("SHOW COLUMNS FROM request_status_history") as $row) {
                $existing[$row['Field']] = true;
            }
            foreach ($newCols as $col => $ddl) {
                if (!isset($existing[$col])) {
                    $db->exec("ALTER TABLE request_status_history ADD COLUMN `{$col}` {$ddl}");
                }
            }
        } catch (\Throwable $e) {
            error_log('[request-status-history] migration warning: ' . $e->getMessage());
        }
    }
}

if (!function_exists('logRequestStatusHistory')) {
    /**
     * Insert one audit row.
     *
     * @param array|null $notify  Optional structured notification outcome:
     *  [
     *    'admin_chose'  => bool,            // admin ले "send notification" choose गर्‍यो?
     *    'email'        => ['status'=>'sent|failed|skipped|not_attempted','reason'=>'','to'=>''],
     *    'sms'          => ['status'=>'sent|failed|skipped|not_attempted','reason'=>'','to'=>''],
     *  ]
     */
    function logRequestStatusHistory(
        PDO $db,
        string $module,
        int $requestId,
        ?string $oldStatus,
        ?string $newStatus,
        string $comment = '',
        bool $notifySent = false,           /* legacy bool — true if EITHER channel sent */
        ?int $actorAdminId = null,
        ?string $actorName = null,
        ?array $notify = null
    ): void {
        if ($requestId <= 0 || trim($module) === '') {
            return;
        }
        ensureRequestStatusHistoryTable($db);

        $email = ($notify['email'] ?? []) + ['status' => 'not_attempted', 'reason' => null, 'to' => null];
        $sms   = ($notify['sms']   ?? []) + ['status' => 'not_attempted', 'reason' => null, 'to' => null];
        $adminChose = !empty($notify['admin_chose']) ? 1 : 0;

        $emailStatus = in_array($email['status'], ['sent','failed','skipped','not_attempted'], true) ? $email['status'] : 'not_attempted';
        $smsStatus   = in_array($sms['status'],   ['sent','failed','skipped','not_attempted'], true) ? $sms['status']   : 'not_attempted';

        $st = $db->prepare("INSERT INTO request_status_history
            (module, request_id, old_status, new_status, admin_comment, notify_sent,
             actor_admin_id, actor_name,
             admin_chose_to_notify,
             notify_email_status, notify_email_reason, notify_email_to,
             notify_sms_status,   notify_sms_reason,   notify_sms_to)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            trim($module),
            $requestId,
            $oldStatus !== null ? trim($oldStatus) : null,
            $newStatus !== null ? trim($newStatus) : null,
            trim($comment),
            $notifySent ? 1 : 0,
            $actorAdminId,
            $actorName !== null ? trim($actorName) : null,
            $adminChose,
            $emailStatus,
            $email['reason'] !== null ? mb_substr((string)$email['reason'], 0, 180) : null,
            $email['to']     !== null ? mb_substr((string)$email['to'],     0, 180) : null,
            $smsStatus,
            $sms['reason'] !== null ? mb_substr((string)$sms['reason'], 0, 180) : null,
            $sms['to']     !== null ? mb_substr((string)$sms['to'],     0, 40)  : null,
        ]);
    }
}

if (!function_exists('fetchRequestStatusHistory')) {
    function fetchRequestStatusHistory(PDO $db, string $module, int $requestId, int $limit = 30): array
    {
        if ($requestId <= 0 || trim($module) === '') {
            return [];
        }
        ensureRequestStatusHistoryTable($db);
        $limit = max(1, min(200, $limit));
        $st = $db->prepare("SELECT * FROM request_status_history
            WHERE module = ? AND request_id = ?
            ORDER BY id DESC
            LIMIT {$limit}");
        $st->execute([trim($module), $requestId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
