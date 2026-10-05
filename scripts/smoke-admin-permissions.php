#!/usr/bin/env php
<?php
/**
 * Smoke: custom role permission matrix — registry covers the sidebar, POST classification,
 * normalisation, superadmin pages never grantable, enforcement hooks present.
 * Run: php scripts/smoke-admin-permissions.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/admin-permissions.php';

$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "OK  {$msg}\n"; } else { $failed++; echo "FAIL {$msg}\n"; }
}
$read = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);

/* Every sidebar link is either a grantable menu, always allowed, or a superadmin page */
$header = $read('admin/includes/admin-header.php');
$aside = substr($header, (int)strpos($header, '<aside'), (int)strpos($header, '</aside>') - (int)strpos($header, '<aside'));
preg_match_all('/<a href="([a-z0-9\-]+\.php)/', $aside, $m);
$registry = [];
foreach (coop_perm_registry() as $g) { $registry += $g['items']; }
$superOnly = ['manage-admins.php', 'admin-roles.php', 'menu-control.php', 'footer-settings.php', 'security-settings.php', 'site-setup.php',
    'backup-restore.php', 'site-license.php', 'run-migration.php', 'db-setup.php'];
$missing = [];
foreach (array_unique($m[1]) as $link) {
    if (!isset($registry[$link]) && !coop_perm_always_allowed($link) && !in_array($link, $superOnly, true) && $link !== 'logout.php') {
        $missing[] = $link;
    }
}
check($missing === [], 'every sidebar menu is in the permission registry' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
foreach ($superOnly as $sp) {
    check(!isset($registry[$sp]), "{$sp} is never grantable to a custom role");
}

check(coop_perm_normalize(['notices.php' => 'ce', 'manage-admins.php' => 'vced', 'bogus.php' => 'v']) === ['notices.php' => 'vce'], 'normalize: c/e imply v, unknown + superadmin pages dropped');
check(coop_perm_normalize('{"news.php":{"v":"1","d":"1"}}') === ['news.php' => 'vd'], 'normalize: form array/JSON input');

$cases = [
    [['action' => 'delete', 'id' => '4'], 'd'], [['delete_admin' => '1'], 'd'], [['bulk_action' => 'delete_selected'], 'd'],
    [['action' => 'save', 'id' => '0'], 'c'], [['action' => 'save', 'id' => '7'], 'e'],
    [['save_notice' => '1', 'notice_id' => '0'], 'c'], [['save_notice' => '1', 'notice_id' => '12'], 'e'],
    [['action' => 'add_desk'], 'c'], [['action' => 'toggle', 'id' => '3'], 'e'], [['update_status' => '1'], 'e'],
    [['action' => 'clear_qr', 'id' => '2'], 'e'],
];
foreach ($cases as [$post, $want]) {
    $_POST = $post;
    check(coop_perm_post_action() === $want, 'POST ' . json_encode($post) . " → {$want}");
}
$_POST = [];
check(coop_perm_resolve_page('program-detail.php') === 'programs.php' && coop_perm_resolve_page('member-import-sample.php') === 'member-import.php', 'sub-pages map to their menu');
$_GET['type'] = 'loan';
check(coop_perm_resolve_page('print-form.php') === 'loan-applications.php', 'print-form follows its application menu');
unset($_GET['type']);

check(str_contains($read('admin/includes/admin-page-boot.php'), 'coop_perm_enforce_request()'), 'page boot enforces the matrix');
check(str_contains($read('admin/member-import.php'), 'coop_perm_enforce_request()'), 'member-import (skips boot login) enforces too');
check(str_contains($read('includes/auth-roles.php'), 'coop_perm_current_request_allowed'), 'has_role defers to the matrix for custom roles');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
