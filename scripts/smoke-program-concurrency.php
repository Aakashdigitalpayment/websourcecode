<?php
/**
 * Smoke: program attendance duplicate / concurrency helpers exist.
 * Usage: php scripts/smoke-program-concurrency.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/program-tables.php';
require_once $root . '/includes/program-attendance-helpers.php';

$errors = [];
$required = [
    'recordProgramAttendance',
    'programResolveMemberBySadasyata',
    'programFindExistingAttendance',
    'programLogAttempt',
    'programResolveScopeId',
    'voidProgramAttendance',
    'programAuditLog',
];
foreach ($required as $fn) {
    if (!function_exists($fn)) {
        $errors[] = "Missing function: $fn";
    }
}

$pdo = getDB();
ensureProgramTables($pdo);

$tables = ['program_occurrences', 'program_attendance_attempts', 'program_audit_logs', 'program_registration_desks'];
foreach ($tables as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
    } catch (Throwable $e) {
        $errors[] = "Table missing or inaccessible: $t";
    }
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

echo "smoke-program-concurrency: OK (" . count($required) . " helpers, " . count($tables) . " tables)\n";
exit(0);
