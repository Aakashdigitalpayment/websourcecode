#!/usr/bin/env php
<?php
/**
 * Safe icon DB inventory / canonicalize (FA spelling only — NOT FA→Lucide rewrite).
 *
 * Usage:
 *   php scripts/inventory-icon-db-canonicalize.php           # dry-run
 *   php scripts/inventory-icon-db-canonicalize.php --apply   # UPDATE spelling only
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';

$apply = in_array('--apply', $argv ?? [], true);

$pdo = class_exists('Database') ? Database::getInstance()->getConnection() : null;
if (!$pdo instanceof PDO) {
    echo "inventory-icon-db-canonicalize: DB not connected — dry inventory skipped\n";
    echo "Safe policy: coop_canonicalize_icon_db_rows() FA spelling only; Lucide rewrite deferred.\n";
    exit(0);
}

if (!function_exists('coop_canonicalize_icon_db_rows')) {
    fwrite(STDERR, "Missing coop_canonicalize_icon_db_rows()\n");
    exit(1);
}

$r = coop_canonicalize_icon_db_rows($pdo, !$apply);
echo "=== Icon DB canonicalize (FA spelling; NOT Lucide rewrite) ===\n";
echo '  dry_run=' . (!empty($r['dry_run']) ? 'true' : 'false') . "\n";
echo '  scanned=' . (int) ($r['scanned'] ?? 0) . ' changed=' . (int) ($r['changed'] ?? 0) . "\n";
if (!empty($r['error'])) {
    echo '  error=' . $r['error'] . "\n";
}
foreach (($r['tables'] ?? []) as $k => $stat) {
    echo '  ' . $k . ': scanned=' . (int) ($stat['scanned'] ?? 0)
        . ' changed=' . (int) ($stat['changed'] ?? 0) . "\n";
}
echo "Deferred bang: FA→Lucide bare-name mass UPDATE (dual-read render covers display).\n";
exit(0);
