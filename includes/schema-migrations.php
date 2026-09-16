<?php
/**
 * Versioned schema migration ledger (additive / idempotent).
 *
 * Tracks applied versions in `schema_migrations` (created by install.sql).
 * Does NOT replace ensure*Tables — only records successful migration steps.
 */

if (!function_exists('coop_ensure_schema_migrations_table')) {
    function coop_ensure_schema_migrations_table(PDO $db): void
    {
        if (function_exists('safeAddColumn')) {
            /* Table create first — safeAddColumn is for columns only */
        }
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS schema_migrations (
                    version     VARCHAR(50) PRIMARY KEY,
                    applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    description VARCHAR(255) NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            error_log('[coop_ensure_schema_migrations_table] ' . $e->getMessage());
        }
    }
}

if (!function_exists('coop_schema_migration_applied')) {
    function coop_schema_migration_applied(PDO $db, string $version): bool
    {
        $version = trim($version);
        if ($version === '' || !preg_match('/^[A-Za-z0-9._-]{1,50}$/', $version)) {
            return false;
        }
        try {
            coop_ensure_schema_migrations_table($db);
            $st = $db->prepare('SELECT 1 FROM schema_migrations WHERE version = ? LIMIT 1');
            $st->execute([$version]);
            return (bool) $st->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('coop_record_schema_migration')) {
    /**
     * Idempotent insert — re-run safe (PRIMARY KEY version).
     */
    function coop_record_schema_migration(PDO $db, string $version, string $description = ''): void
    {
        $version = trim($version);
        if ($version === '' || !preg_match('/^[A-Za-z0-9._-]{1,50}$/', $version)) {
            return;
        }
        $description = mb_substr(trim($description), 0, 255);
        try {
            coop_ensure_schema_migrations_table($db);
            $st = $db->prepare(
                'INSERT INTO schema_migrations (version, description) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE description = VALUES(description)'
            );
            $st->execute([$version, $description !== '' ? $description : null]);
        } catch (Throwable $e) {
            error_log('[coop_record_schema_migration] ' . $e->getMessage());
        }
    }
}

if (!function_exists('coop_list_schema_migrations')) {
    /** @return list<array{version:string,applied_at:?string,description:?string}> */
    function coop_list_schema_migrations(PDO $db, int $limit = 40): array
    {
        $limit = max(1, min(200, $limit));
        try {
            coop_ensure_schema_migrations_table($db);
            $q = $db->query(
                'SELECT version, applied_at, description FROM schema_migrations
                 ORDER BY applied_at DESC, version DESC LIMIT ' . $limit
            );
            $rows = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
            return is_array($rows) ? $rows : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
