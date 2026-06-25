<?php
/**
 * ════════════════════════════════════════════════════════════════════
 * AUDIT LOG HELPER — v3
 * ════════════════════════════════════════════════════════════════════
 * सबै admin/system actions audit_log table मा record गर्ने sole API।
 *
 * Usage:
 *   require_once __DIR__ . '/audit.php';
 *   auditLog('admin_login', 'admin', $adminId, ['email'=>$email]);
 *   auditLog('member_approve', 'member', $memberId, null, ['status'=>'approved']);
 *   auditLog('loan_status_change', 'loan_application', $loanId,
 *            ['status'=>'pending'], ['status'=>'approved']);
 *
 * Failures NEVER bubble up — audit failure ले actual operation रोक्नु हुँदैन।
 * ════════════════════════════════════════════════════════════════════
 */

if (!function_exists('auditLog')) {
function auditLog(
    string $action,
    string $entityType,
    $entityId = null,
    $oldValues = null,
    $newValues = null,
    string $summary = ''
): void {
    try {
        global $db;
        if (!$db) { try { $db = getDB(); } catch (\Throwable $e) { return; } }
        if (!$db) return;

        /* Actor identify (admin / member / system) */
        $actorType = 'system';
        $actorId   = null;
        $actorName = null;

        if (!empty($_SESSION['admin_id'])) {
            $actorType = !empty($_SESSION['is_superadmin']) ? 'superadmin' : 'admin';
            $actorId   = (int) $_SESSION['admin_id'];
            $actorName = $_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? null;
        } elseif (!empty($_SESSION['member_id'])) {
            $actorType = 'member';
            $actorId   = (int) $_SESSION['member_id'];
            $actorName = $_SESSION['member_name'] ?? null;
        } elseif (!empty($_SESSION['is_superadmin'])) {
            $actorType = 'superadmin';
            $actorName = $_SESSION['admin_email'] ?? 'superadmin';
        }

        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip && strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        $oldJson = $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null;
        $newJson = $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null;

        if (!$summary) {
            $summary = ucfirst(str_replace('_',' ',$action)) . ' on ' . $entityType
                     . ($entityId ? ' #' . $entityId : '');
        }
        $summary = substr($summary, 0, 255);

        $st = $db->prepare(
            "INSERT INTO audit_log
              (actor_type, actor_id, actor_name, action, entity_type, entity_id,
               summary, old_values, new_values, ip_address, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            $actorType, $actorId, $actorName, $action, $entityType, $entityId,
            $summary, $oldJson, $newJson, $ip, $ua
        ]);
    } catch (\Throwable $e) {
        /* Audit failure ले कुनै feature रोक्नु हुँदैन — log मा मात्र राख्ने */
        error_log('[audit] failed: ' . $e->getMessage());
    }
}
}

/* ────────────────────────────────────────────────────────────────────
   Soft-delete helpers — accidental DELETE को साटो यो use गर्ने
   ──────────────────────────────────────────────────────────────────── */

if (!function_exists('softDelete')) {
function softDelete(string $table, int $id, string $idCol = 'id'): bool {
    try {
        global $db;
        if (!$db) { try { $db = getDB(); } catch (\Throwable $e) { return false; } }
        if (!$db) return false;
        /* deleted_at column छ कि छैन check (graceful) */
        $col = $db->prepare("SELECT 1 FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                                AND COLUMN_NAME = 'deleted_at' LIMIT 1");
        $col->execute([$table]);
        if (!$col->fetchColumn()) return false; /* table मा column छैन — caller ले hard-delete गर्न पर्छ */

        $st = $db->prepare("UPDATE `$table` SET deleted_at = NOW() WHERE `$idCol` = ?");
        $st->execute([$id]);
        auditLog('soft_delete', $table, $id);
        return $st->rowCount() > 0;
    } catch (\Throwable $e) {
        error_log('[softDelete] ' . $e->getMessage());
        return false;
    }
}
}

if (!function_exists('softRestore')) {
function softRestore(string $table, int $id, string $idCol = 'id'): bool {
    try {
        global $db;
        if (!$db) { try { $db = getDB(); } catch (\Throwable $e) { return false; } }
        if (!$db) return false;
        $st = $db->prepare("UPDATE `$table` SET deleted_at = NULL WHERE `$idCol` = ?");
        $st->execute([$id]);
        auditLog('restore', $table, $id);
        return $st->rowCount() > 0;
    } catch (\Throwable $e) {
        error_log('[softRestore] ' . $e->getMessage());
        return false;
    }
}
}
