<?php
/**
 * Quarantine inventory — live-safe retention until access logs show ~0 hits.
 *
 * Phase 5 of the live-safe audit: these paths are intentional redirects.
 * Do NOT delete them without log evidence on the live host (Apache or nginx).
 * install.php and one-shot migrate scripts stay in the package for greenfield / unfinished envs.
 *
 * Run: php scripts/smoke-quarantine-stubs.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;
$passed = 0;

function ok(string $msg): void
{
    global $passed;
    $passed++;
    echo "OK  {$msg}\n";
}

function fail(string $msg): void
{
    global $failed;
    $failed++;
    echo "FAIL {$msg}\n";
}

function assertRedirectStub(string $rel, string $why): void
{
    global $root;
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        fail("{$rel}: missing ({$why}) — restore redirect or replace with server-level rewrite first");
        return;
    }
    $t = (string) file_get_contents($path);
    if (stripos($t, 'Location') === false && stripos($t, 'redirect(') === false) {
        fail("{$rel}: not a redirect stub ({$why})");
        return;
    }
    if (stripos($t, 'X-Robots-Tag') === false) {
        fail("{$rel}: missing noindex robots tag ({$why})");
        return;
    }
    ok("{$rel}: quarantined redirect retained");
}

$rootStubs = [
    'login.php' => 'legacy → member/login.php',
    'oauth.php' => 'legacy → member/oauth.php',
    'password-reset-request.php' => 'legacy → member/password-reset-request.php',
    'backup-restore.php' => 'root → admin backup-restore',
    'program-attendance-verify.php' => 'legacy → program-registration-desk',
    'member/transactions.php' => 'unfinished ledger → member home',
];

foreach ($rootStubs as $rel => $why) {
    assertRedirectStub($rel, $why);
}

foreach (glob($root . '/admin/hrm-*.php') ?: [] as $abs) {
    assertRedirectStub('admin/' . basename($abs), 'HRM removed → dashboard');
}
assertRedirectStub('admin/staff.php', 'legacy staff → manage-admins');

foreach (['index.php', 'add.php', 'edit.php', 'view.php'] as $f) {
    assertRedirectStub('admin/members/' . $f, 'legacy members CRUD → members.php');
}
foreach (['kyc.php', 'kyc-view.php', 'kyc-generate-member.php', 'account.php'] as $f) {
    assertRedirectStub('admin/applications/' . $f, 'legacy applications → kyc-applications');
}

// Keep greenfield / migration tooling
foreach (['install.php', 'scripts/drop-hrm-tables-safe.php', 'scripts/migrate-program-v2.php'] as $rel) {
    if (!is_file($root . '/' . $rel)) {
        fail("{$rel}: should remain in package until all envs migrated / greenfield needs install");
    } else {
        ok("{$rel}: retained in package");
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
