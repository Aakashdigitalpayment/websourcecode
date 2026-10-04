#!/usr/bin/env php
<?php
/**
 * Smoke: मासिक बचत (नियमित / नियमित नभएको) — parser, SSOT wiring, field-only import.
 * Run: php scripts/smoke-monthly-saving.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/member-monthly-saving.php';
require_once $root . '/includes/member-import-helpers.php';

$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "OK  {$msg}\n"; } else { $failed++; echo "FAIL {$msg}\n"; }
}

$cases = [
    'नियमित' => 1, 'नियमित नभएको' => 0, 'Niyamit Nabhayeko' => 0, '१' => 1, '0' => 0,
    'yes' => 1, 'No' => 0, 'regular' => 1, 'not_regular' => 0, 'अनियमित' => 0, '' => null, '  ' => null,
];
foreach ($cases as $in => $want) {
    $p = coop_monthly_saving_parse((string) $in);
    check($p['ok'] && $p['value'] === $want, "parse `{$in}` → " . var_export($want, true));
}
check(coop_monthly_saving_parse('maybe')['ok'] === false, 'unknown text rejected (row error, nothing written)');
check(coop_monthly_saving_from_db(null) === null && coop_monthly_saving_from_db('1') === 1 && coop_monthly_saving_from_db(0) === 0, 'DB cell mapping');
check(coop_monthly_saving_label(null) === 'नतोकिएको', 'unset label');

foreach (['monthly_saving', 'masik_bachat', 'मासिक_बचत', 'Monthly_Saving_Regular'] as $h) {
    check(memberImportNormalizeHeader($h) === 'monthly_saving', "header alias `{$h}`");
}
check(function_exists('memberImportFieldUpdate'), 'field-only update import exists');

$read = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);
$helpers = $read('includes/member-import-helpers.php');
check(str_contains($helpers, "UPDATE members SET {\$col} = ? WHERE id IN"), 'field update writes only the monthly_saving column');
check(!preg_match('/function memberImportFieldUpdate.*?INSERT INTO members/s', $helpers), 'field update never creates members');
check(str_contains($read('admin/member-import-sample.php'), "'monthly_saving'"), 'sample CSV has monthly_saving');
check(str_contains($read('admin/member-import.php'), 'name="field_update"'), 'import page has field-update form');
check(str_contains($read('admin/members.php'), 'coop_monthly_saving_radios_html('), 'member edit form has field');
check(str_contains($read('admin/kyc-applications.php'), 'coop_monthly_saving_set('), 'KYM edit saves to linked member');
check(str_contains($read('admin/api/program-desk-lookup.php'), "'monthly_saving'"), 'desk lookup returns status');
check(str_contains($read('admin/program-registration-desk.php'), 'api/member-monthly-saving.php'), 'desk can change + save');
$api = $read('admin/api/member-monthly-saving.php');
check(str_contains($api, 'verifyCSRFToken') && str_contains($api, "has_role('staff')"), 'save API checks CSRF + role');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
