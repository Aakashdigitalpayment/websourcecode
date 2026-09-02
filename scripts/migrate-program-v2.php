<?php
/**
 * One-shot program v2 backfill (safe to re-run).
 * Usage: php scripts/migrate-program-v2.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/program-tables.php';

$pdo = getDB();
ensureProgramTables($pdo);
programBackfillV2Columns($pdo);

$flag = $root . '/cache/.program-v2-migrated';
if (!is_dir(dirname($flag))) {
    @mkdir(dirname($flag), 0755, true);
}
file_put_contents($flag, date('c') . "\n");

echo "Program v2 migration complete.\n";
