<?php
/**
 * Smoke: program attendance duplicate / concurrency helpers exist.
 * Usage: php scripts/smoke-program-concurrency.php
 *
 * DB table checks run only when MySQL is reachable. Without DB, helper
 * existence is still validated (CI / sandbox safe).
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
    'programHasPendingAttendanceRequest',
    'programLiveStatsForProgram',
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

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

/* Soft DB probe — avoid getDB() throw → CLI HTML 500 dump */
$pdo = null;
if (class_exists('Database')) {
    $pdo = Database::getInstance()->getConnection();
}

if (!$pdo instanceof PDO) {
    echo 'smoke-program-concurrency: OK (' . count($required) . " helpers; DB skipped — not connected)\n";
    exit(0);
}

ensureProgramTables($pdo);

$tables = ['program_occurrences', 'program_attendance_attempts', 'program_audit_logs', 'program_registration_desks'];
$tablesOk = 0;
foreach ($tables as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        $tablesOk++;
    } catch (Throwable $e) {
        $errors[] = "Table missing or inaccessible: $t";
    }
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

echo 'smoke-program-concurrency: OK (' . count($required) . ' helpers, ' . $tablesOk . " tables)\n";
exit(0);
