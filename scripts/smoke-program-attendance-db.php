<?php
/**
 * End-to-end program attendance test against a THROWAWAY MySQL/MariaDB database.
 * Never point this at production — it creates and drops tables.
 *
 *   PROGRAM_TEST_DSN='mysql:unix_socket=/tmp/coop-testdb/sock;dbname=cooptest' \
 *   PROGRAM_TEST_USER=root php scripts/smoke-program-attendance-db.php
 */
declare(strict_types=1);

$dsn = getenv('PROGRAM_TEST_DSN') ?: '';
if ($dsn === '') {
    echo "smoke-program-attendance-db: SKIPPED (set PROGRAM_TEST_DSN to a throwaway database)\n";
    exit(0);
}
$db = new PDO($dsn, getenv('PROGRAM_TEST_USER') ?: 'root', getenv('PROGRAM_TEST_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/test/i', $dbName)) {
    fwrite(STDERR, "Refusing to run: database name '{$dbName}' must contain 'test'.\n");
    exit(2);
}

foreach (['member_program_attendance', 'member_program_attendance_requests', 'member_program_preregistrations',
          'program_occurrences', 'program_attendance_attempts', 'program_registration_desks', 'program_audit_logs',
          'upcoming_programs', 'members', 'site_settings'] as $t) {
    $db->exec("DROP TABLE IF EXISTS `{$t}`");
}
$db->exec("CREATE TABLE site_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB");
/* mirrors ensureMemberTables(): note there is NO photo column */
$db->exec("CREATE TABLE members (
    id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150), phone VARCHAR(20), address VARCHAR(255), avatar_url VARCHAR(500) NULL,
    sadasyata_number VARCHAR(50) NOT NULL DEFAULT '', member_card_no VARCHAR(50) NULL,
    is_active TINYINT NOT NULL DEFAULT 1, approval_status VARCHAR(20) DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

require_once __DIR__ . '/../includes/program-attendance-helpers.php';

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

ensureProgramTables($db);
$check('migration flag stored', (string)$db->query("SELECT setting_value FROM site_settings WHERE setting_key='migration_program_tables_v4'")->fetchColumn() === '1');
$check('valid_lock column exists', programColumnExists($db, 'member_program_attendance', 'valid_lock'));
$check('uniq_scope_member_valid index exists', programIndexExists($db, 'member_program_attendance', 'uniq_scope_member_valid'));
$check('old uniq_scope_member_status dropped', !programIndexExists($db, 'member_program_attendance', 'uniq_scope_member_status'));
$check('legacy uniq_member_program dropped', !programIndexExists($db, 'member_program_attendance', 'uniq_member_program'));

$ins = $db->prepare('INSERT INTO members (name, sadasyata_number, is_active, approval_status) VALUES (?,?,?,?)');
$ins->execute(['Ram Approved', '001000552', 1, 'approved']);
$mOk = (int)$db->lastInsertId();
$ins->execute(['Sita Renewal', '001000553', 1, 'renewal_pending']);
$mRenew = (int)$db->lastInsertId();
$ins->execute(['Hari Pending', '001000554', 1, 'pending']);
$mPending = (int)$db->lastInsertId();
$ins->execute(['Gita Inactive', '001000555', 0, 'approved']);
$mInactive = (int)$db->lastInsertId();

$db->exec("INSERT INTO upcoming_programs (title, program_type, is_active) VALUES ('AGM 2083', 'AGM', 1)");
$agm = (int)$db->lastInsertId();
$db->exec("INSERT INTO upcoming_programs (title, program_type, is_active, is_multi_location) VALUES ('AGM Multi', 'AGM', 1, 1)");
$multi = (int)$db->lastInsertId();
$db->exec("INSERT INTO program_occurrences (parent_program_id, location_name) VALUES ({$multi}, 'Banepa'), ({$multi}, 'Panauti')");
$occ1 = (int)$db->query("SELECT id FROM program_occurrences WHERE location_name='Banepa'")->fetchColumn();
$occ2 = (int)$db->query("SELECT id FROM program_occurrences WHERE location_name='Panauti'")->fetchColumn();
$db->exec("INSERT INTO upcoming_programs (title, is_active, attendance_close_at) VALUES ('Closed', 1, DATE_SUB(NOW(), INTERVAL 1 DAY))");
$closed = (int)$db->lastInsertId();

$r = programResolveMemberBySadasyata($db, ' 001000552 ');
$check('Member ID lookup trims input', $r !== null && (int)$r['id'] === $mOk);
$r = programResolveMemberBySadasyata($db, '००१०००५५२');
$check('Member ID lookup accepts Nepali digits', $r !== null && (int)$r['id'] === $mOk);
$check('unknown Member ID → null', programResolveMemberBySadasyata($db, '999999') === null);

$rec = static fn(int $mid, int $pid, ?int $occ = null, array $extra = []) => recordProgramAttendance($db, array_merge([
    'member_id' => $mid, 'program_id' => $pid, 'occurrence_id' => $occ,
    'attendance_method' => 'ADMIN_MANUAL', 'source' => 'registration_desk', 'staff_admin_id' => 1, 'ip' => '127.0.0.1',
], $extra));

$a = $rec($mOk, $agm);
$check('desk attendance recorded', !empty($a['ok']));
$b = $rec($mOk, $agm);
$check('second attempt blocked as duplicate', empty($b['ok']) && !empty($b['duplicate']));

$v1 = voidProgramAttendance($db, (int)$a['attendance_id'], 1, 'wrong member');
$check('first void ok', !empty($v1['ok']));
$c = $rec($mOk, $agm);
$check('re-record after void ok', !empty($c['ok']));
$v2 = voidProgramAttendance($db, (int)$c['attendance_id'], 1, 'wrong again');
$check('second void ok (was failing on unique key)', !empty($v2['ok']));
$d = $rec($mOk, $agm);
$check('third record ok', !empty($d['ok']));
$counts = $db->query("SELECT attendance_status, COUNT(*) c FROM member_program_attendance WHERE member_id={$mOk} AND program_id={$agm} GROUP BY attendance_status")->fetchAll(PDO::FETCH_KEY_PAIR);
$check('1 VALID + 2 VOID rows', (int)($counts['VALID'] ?? 0) === 1 && (int)($counts['VOID'] ?? 0) === 2);
$check('void without reason rejected', empty(voidProgramAttendance($db, (int)$d['attendance_id'], 1, '')['ok']));

$o = $rec($mOk, $agm, null, ['allow_override' => true, 'override_reason' => 'desk correction']);
$check('override with reason replaces VALID row', !empty($o['ok']));
$check('still exactly one VALID row after override', (int)$db->query("SELECT COUNT(*) FROM member_program_attendance WHERE member_id={$mOk} AND program_id={$agm} AND attendance_status='VALID'")->fetchColumn() === 1);
$check('override without reason rejected', empty($rec($mOk, $agm, null, ['allow_override' => true])['ok']));

$check('renewal_pending member can attend', !empty($rec($mRenew, $agm)['ok']));
$check('pending (unapproved) member blocked', empty($rec($mPending, $agm)['ok']));
$check('inactive member blocked', empty($rec($mInactive, $agm)['ok']));

$check('multi-location requires a location', empty($rec($mOk, $multi)['ok']));
$check('multi-location attendance at Banepa ok', !empty($rec($mOk, $multi, $occ1)['ok']));
$dup = $rec($mOk, $multi, $occ2);
$check('same member at Panauti blocked (one AGM scope)', empty($dup['ok']) && !empty($dup['duplicate']));
$check('occurrence of another program rejected', empty($rec($mRenew, $agm, $occ1)['ok']));

$check('closed window blocks attendance', empty($rec($mOk, $closed)['ok']));
$check('closed window check can be skipped by admin flows', !empty($rec($mOk, $closed, null, ['skip_window_check' => true])['ok']));

$stats = programLiveStatsForProgram($db, programFetchById($db, $agm));
$check('live stats: 2 unique attended', (int)$stats['attended'] === 2);
$check('live stats: eligible counts approved + renewal_pending', (int)$stats['eligible'] === 2);
$multiStats = programLiveStatsForProgram($db, programFetchById($db, $multi));
$check('occurrence counts: Banepa 1, Panauti 0', array_column($multiStats['occurrences'], 'attended_count', 'location_name') == ['Banepa' => 1, 'Panauti' => 0]);

$check('duplicate attempts logged', (int)$db->query("SELECT COUNT(*) FROM program_attendance_attempts WHERE result='DUPLICATE_BLOCKED'")->fetchColumn() >= 2);

$dupSt = $db->prepare('SELECT id FROM upcoming_programs WHERE LOWER(TRIM(title)) = LOWER(TRIM(?)) AND event_date <=> ? LIMIT 1');
$dupSt->execute(['  agm 2083 ', null]);
$check('duplicate-program guard matches same title + empty date', (int)$dupSt->fetchColumn() === $agm);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
