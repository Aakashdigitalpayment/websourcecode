<?php
/**
 * Smoke: Superadmin menu control helpers (no DB required for core checks).
 * Usage: php scripts/smoke-menu-control.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/admin-menu-control.php';

$failed = 0;
$passed = 0;
function ok(string $m): void { global $passed; $passed++; echo "OK  $m\n"; }
function fail(string $m): void { global $failed; $failed++; echo "FAIL $m\n"; }

$cat = admin_menu_control_catalog();
if (count($cat) >= 8 && isset($cat['program'], $cat['nirvachan'], $cat['sadasya'])) {
    ok('catalog has core groups');
} else {
    fail('catalog incomplete');
}

if (admin_menu_control_verify_confirm_code('9856026434')) {
    ok('confirm code accepts expected value');
} else {
    fail('confirm code should accept 9856026434');
}
if (!admin_menu_control_verify_confirm_code('')) {
    ok('confirm code rejects empty');
} else {
    fail('empty confirm should fail');
}
if (!admin_menu_control_verify_confirm_code('0000000000')) {
    ok('confirm code rejects wrong value');
} else {
    fail('wrong confirm should fail');
}

$_SESSION = [];
$pageGroups = [
    'program' => ['programs', 'program-attendance'],
    'nirvachan' => ['election-results'],
    'sampark' => ['messages', 'appointments'],
    'aavedan' => ['loans', 'appointments'],
];

/* Simulate hidden program + nirvachan via reflection of load — monkey by setting getSetting is hard.
   Test page_allowed logic with temporary override: call load after faking via update only if DB.
   Unit-test visibility with empty session (non-SA) and empty hidden = all visible. */
if (admin_menu_group_visible('program') === true && empty($_SESSION['is_superadmin'])) {
    /* empty hidden default → visible */
    ok('non-SA sees group when nothing hidden (default)');
} else {
    fail('default visibility broken');
}

$_SESSION['is_superadmin'] = 1;
if (admin_menu_group_visible('program')) {
    ok('superadmin always sees groups');
} else {
    fail('superadmin should always see groups');
}
unset($_SESSION['is_superadmin']);

if (admin_menu_page_allowed('dashboard', $pageGroups)) {
    ok('dashboard always allowed');
} else {
    fail('dashboard should always be allowed');
}
if (admin_menu_page_allowed('programs', $pageGroups)) {
    ok('programs allowed when nothing hidden');
} else {
    fail('programs should be allowed by default');
}

/* Shared page appointments: if only aavedan "would" be hidden we can't set without DB;
   exercise containing-group logic by ensuring unknown page allowed */
if (admin_menu_page_allowed('unknown-page-xyz', $pageGroups)) {
    ok('unknown page allowed (not in groups)');
} else {
    fail('unknown page should be allowed');
}

$mc = $root . '/admin/menu-control.php';
if (is_file($mc) && str_contains((string)file_get_contents($mc), 'confirm_code')) {
    ok('menu-control.php present with confirm_code');
} else {
    fail('menu-control.php missing confirm_code');
}

$header = (string)file_get_contents($root . '/admin/includes/admin-header.php');
if (str_contains($header, "admin_menu_group_visible('program')") && str_contains($header, 'admin_menu_page_allowed')) {
    ok('header wires group visibility + page gate');
} else {
    fail('header missing menu control wiring');
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
