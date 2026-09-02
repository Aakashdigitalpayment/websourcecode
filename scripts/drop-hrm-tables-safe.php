#!/usr/bin/env php
<?php
/**
 * One-time safe drop of retired HRM module tables.
 * No application code references these tables after HRM UI removal.
 * Public live chat uses contact_messages instead of hrm_internal_messages.
 *
 * Usage:
 *   php scripts/drop-hrm-tables-safe.php          # dry-run
 *   php scripts/drop-hrm-tables-safe.php --yes    # execute drops
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$execute = in_array('--yes', $argv ?? [], true);

$tables = [
    'hrm_internal_messages',
    'hrm_employee_contracts',
    'hrm_employee_documents',
    'hrm_employee_education',
    'hrm_employee_experience',
    'hrm_employee_family',
    'hrm_employee_bank',
    'hrm_employee_history',
    'hrm_employees',
    'hrm_departments',
];

$dbDist = $root . '/includes/database.dist.php';
if (!is_file($dbDist)) {
    fwrite(STDERR, "SKIP: database.dist.php not found\n");
    exit(2);
}

require_once $dbDist;

if (!defined('DB_NAME') || DB_NAME === '' || !defined('DB_USER') || DB_USER === '') {
    fwrite(STDERR, "SKIP: DB not configured\n");
    exit(2);
}

$host = defined('DB_HOST') ? DB_HOST : 'localhost';
$dsn = 'mysql:host=' . $host . ';dbname=' . DB_NAME . ';charset=utf8mb4';

try {
    $db = new PDO($dsn, DB_USER, DB_PASS ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, 'SKIP: DB connection failed (' . $e->getMessage() . ")\n");
    exit(2);
}

$existing = [];
foreach ($tables as $table) {
    try {
        $st = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $st->execute([$table]);
        if ((int) $st->fetchColumn() > 0) {
            $existing[] = $table;
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "WARN: could not probe {$table}: " . $e->getMessage() . "\n");
    }
}

if ($existing === []) {
    echo "OK  No HRM tables present — nothing to drop.\n";
    exit(0);
}

echo ($execute ? 'DROP' : 'DRY-RUN') . '  HRM tables: ' . implode(', ', $existing) . "\n";

if (!$execute) {
    echo "Run with --yes to drop these tables.\n";
    exit(0);
}

/* Migrate legacy public-chat rows stored in HRM messenger before drop */
if (in_array('hrm_internal_messages', $existing, true)) {
    try {
        $hasContact = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'contact_messages'"
        )->fetchColumn();
        if ($hasContact > 0) {
            $legacy = $db->query(
                "SELECT id, subject, body, created_at FROM hrm_internal_messages
                 WHERE subject LIKE '[public-chat %' OR subject LIKE '[Live Chat %'
                 ORDER BY id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            $migrated = 0;
            $ins = $db->prepare(
                'INSERT INTO contact_messages (name, email, phone, subject, message, is_read, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $dup = $db->prepare(
                'SELECT id FROM contact_messages WHERE subject = ? AND message = ? LIMIT 1'
            );
            foreach ($legacy as $row) {
                $subject = (string) ($row['subject'] ?? '');
                $body = (string) ($row['body'] ?? '');
                $dup->execute([$subject, $body]);
                if ($dup->fetchColumn()) {
                    continue;
                }
                $name = 'Visitor';
                if (preg_match('/\\]\\s*(.+)$/u', $subject, $m)) {
                    $name = mb_substr(trim($m[1]), 0, 100);
                }
                $email = '';
                $phone = '';
                $msgBody = $body;
                if (preg_match('/^नाम:\\s*(.+)$/mu', $body, $m)) {
                    $name = mb_substr(trim($m[1]), 0, 100);
                }
                if (preg_match('/^सम्पर्क:\\s*(.+)$/mu', $body, $m)) {
                    $contact = trim($m[1]);
                    if (strpos($contact, '@') !== false) {
                        $email = mb_substr($contact, 0, 100);
                    } else {
                        $phone = mb_substr($contact, 0, 20);
                    }
                }
                if (preg_match('/------\\s*(.+)$/s', $body, $m)) {
                    $msgBody = trim($m[1]);
                }
                $ins->execute([
                    $name,
                    $email !== '' ? $email : null,
                    $phone !== '' ? $phone : null,
                    $subject,
                    $msgBody,
                    0,
                    $row['created_at'] ?? date('Y-m-d H:i:s'),
                ]);
                $migrated++;
            }
            if ($migrated > 0) {
                echo "OK  Migrated {$migrated} legacy public-chat row(s) to contact_messages\n";
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'WARN: legacy public-chat migration skipped: ' . $e->getMessage() . "\n");
    }
}

try {
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($existing as $table) {
        $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        echo "OK  Dropped {$table}\n";
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "Done. HRM module tables removed from database.\n";
    exit(0);
} catch (Throwable $e) {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
