#!/usr/bin/env php
<?php
/**
 * Smoke: live deploy helpers and ops scripts.
 * Run: php scripts/smoke-deploy.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$pass = 0;

function ok(string $msg): void
{
    global $pass;
    $pass++;
    echo "OK  {$msg}\n";
}

function bad(string $msg): void
{
    global $fail;
    $fail++;
    echo "FAIL {$msg}\n";
}

function assertContains(string $file, string $needle, string $why): void
{
    global $root;
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        bad("{$file}: missing ({$why})");
        return;
    }
    $t = (string) file_get_contents($path);
    if (strpos($t, $needle) === false) {
        bad("{$file}: missing `{$needle}` ({$why})");
        return;
    }
    ok("{$file}: {$why}");
}

assertContains('scripts/deploy-pull-safe.sh', 'git stash push', 'deploy script stashes htaccess');
assertContains('scripts/deploy-pull-safe.sh', 'git pull origin', 'deploy script pulls branch');
assertContains('scripts/run-all-smokes.sh', 'smoke-*.php', 'run-all-smokes loops scripts');
assertContains('.github/workflows/smoke.yml', 'smoke-*.php', 'CI runs smoke scripts');
assertContains('admin/site-health.php', 'Auth signing secret', 'site health auth secret check');
assertContains('admin/site-health.php', 'Deploy pull helper', 'site health deploy helper check');
assertContains('includes/config.php', 'function coop_sanitize_icon_class', 'icon sanitizer in config');
assertContains('reports.php', 'e(getLangField($report, \'title\')', 'report titles escaped');
assertContains('downloads.php', 'e(getLangField($item, \'title\')', 'download titles escaped');
assertContains('downloads.php', 'safe_media_src($item[\'file_path\']', 'download href guarded');
assertContains('scripts/generate-auth-secret.php', 'bin2hex(random_bytes(32))', 'auth secret generator');
assertContains('member/check-availability.php', 'coop_client_ip', 'member availability uses client ip');
assertContains('api-public-chat.php', 'coop_client_ip', 'public chat uses client ip');
assertContains('verify.php', 'coop_client_ip', 'card verify uses client ip');
assertContains('application-tracker.php', 'coop_client_ip', 'tracker guard uses client ip');

foreach (['scripts/deploy-pull-safe.sh', 'scripts/run-all-smokes.sh', 'admin/site-health.php'] as $f) {
    $cmd = 'php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        bad("{$f}: php -l failed — " . implode(' ', $out));
    } else {
        ok("{$f}: php -l");
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
