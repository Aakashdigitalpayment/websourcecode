#!/usr/bin/env php
<?php
/**
 * Safe CLI migration — same idempotent install.sql path as admin/run-migration.php.
 * Does NOT wipe data: CREATE IF NOT EXISTS / additive ALTERs; skips DROP/TRUNCATE.
 *
 * Usage (cPanel Terminal):
 *   cd ~/public_html
 *   git pull origin main
 *   php scripts/run-migration-safe.php --yes
 *
 * Dry-run (no DB writes):
 *   php scripts/run-migration-safe.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$execute = in_array('--yes', $argv ?? [], true);
$sqlFile = $root . '/database/install.sql';
$version = 'install-sql-consolidated';

echo "==> Safe migration (install.sql)\n";
echo "    Root: {$root}\n";
echo "    Mode: " . ($execute ? 'EXECUTE (--yes)' : 'DRY-RUN') . "\n";

if (!is_file($sqlFile)) {
    fwrite(STDERR, "FAIL: missing database/install.sql\n");
    exit(1);
}

require_once $root . '/includes/config.php';
if (is_file($root . '/includes/schema-migrations.php')) {
    require_once $root . '/includes/schema-migrations.php';
}
if (is_file($root . '/includes/auth-roles.php')) {
    require_once $root . '/includes/auth-roles.php';
}

/* Shared splitter — admin guard expects IS_ADMIN_PAGE */
if (!defined('IS_ADMIN_PAGE')) {
    define('IS_ADMIN_PAGE', true);
}
require_once $root . '/admin/includes/sql-utils.php';

if (!function_exists('getDB')) {
    fwrite(STDERR, "FAIL: getDB() unavailable — check includes/database.local.php\n");
    exit(2);
}

try {
    $db = getDB();
    $db->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: DB connection failed (' . $e->getMessage() . ")\n");
    exit(2);
}

$sql = (string) file_get_contents($sqlFile);
if (trim($sql) === '') {
    fwrite(STDERR, "FAIL: install.sql is empty\n");
    exit(1);
}

$parts = splitSqlStatements($sql);
$dangerous = 0;
$runnable = [];
foreach ($parts as $stmt) {
    $trim = trim($stmt);
    if ($trim === '') {
        continue;
    }
    if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s+\w)/i', $trim)) {
        continue;
    }
    if (preg_match('/\bDROP\s+(TABLE|DATABASE)\b/i', $trim) || preg_match('/\bTRUNCATE\b/i', $trim)) {
        $dangerous++;
        echo "SKIP dangerous: " . substr(preg_replace('/\s+/', ' ', $trim), 0, 72) . "…\n";
        continue;
    }
    $runnable[] = $trim;
}

echo "    Statements: " . count($runnable) . " runnable, {$dangerous} dangerous skipped\n";

if (!$execute) {
    echo "Dry-run OK. Re-run with --yes to apply (idempotent; no row wipe).\n";
    exit(0);
}

$ok = 0;
$warn = 0;
$err = 0;
foreach ($runnable as $stmt) {
    try {
        $db->exec($stmt);
        $ok++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (
            stripos($msg, 'Duplicate column') !== false ||
            stripos($msg, 'already exists') !== false ||
            stripos($msg, 'Duplicate key') !== false ||
            strpos((string) $e->getCode(), '42S21') !== false
        ) {
            $warn++;
        } else {
            $err++;
            fwrite(STDERR, 'ERR: ' . $msg . "\n");
        }
    }
}

if ($err > 0) {
    fwrite(STDERR, "FAIL: {$err} error(s); {$ok} ok; {$warn} already-exists skips\n");
    exit(1);
}

/* Post-steps — same as admin/run-migration.php (additive only) */
if (function_exists('coop_widen_admin_role_enum')) {
    try {
        coop_widen_admin_role_enum($db);
        echo "    Role ENUM widen: OK\n";
    } catch (Throwable $e) {
        fwrite(STDERR, '[role-enum] ' . $e->getMessage() . "\n");
    }
}
if (function_exists('coop_normalize_admin_role_aliases')) {
    try {
        $norm = coop_normalize_admin_role_aliases($db);
        $n = (int) ($norm['updated'] ?? 0);
        echo "    Role alias normalize: {$n} row(s)\n";
    } catch (Throwable $e) {
        fwrite(STDERR, '[role-alias] ' . $e->getMessage() . "\n");
    }
}
if (function_exists('coop_canonicalize_icon_db_rows')) {
    try {
        $icon = coop_canonicalize_icon_db_rows($db, false);
        $c = (int) ($icon['changed'] ?? 0);
        echo "    Icon FA canonicalize: {$c} cell(s)\n";
    } catch (Throwable $e) {
        fwrite(STDERR, '[icon-canon] ' . $e->getMessage() . "\n");
    }
}
if (function_exists('coop_record_schema_migration')) {
    coop_record_schema_migration($db, $version, 'CLI run-migration-safe.php');
    coop_record_schema_migration($db, 'v13-drop-redundant-indexes-2026', 'Public ensure schemaVersion (lock-aligned)');
}

echo "OK: migration complete ({$ok} executed, {$warn} already-exists skips). Data rows not wiped.\n";
exit(0);
