#!/usr/bin/env php
<?php
/**
 * Smoke: Information Room wiring + vault helpers.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$pass = 0;

function ok(string $m): void { global $pass; $pass++; echo "OK  $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "FAIL $m\n"; }

function assertFileContains(string $rel, string $needle, string $label): void
{
    global $root;
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        bad($label . ' — missing file ' . $rel);
        return;
    }
    $src = (string) file_get_contents($path);
    if (strpos($src, $needle) !== false) {
        ok($label);
    } else {
        bad($label . ' — missing "' . $needle . '" in ' . $rel);
    }
}

foreach ([
    'includes/information-room-tables.php',
    'includes/information-room-viewer.php',
    'information-room-file.php',
    'admin/information-room.php',
    'admin/information-room-browse.php',
    'admin/information-room-view.php',
    'admin/information-room-logs.php',
    'member/information-room.php',
    'member/information-room-view.php',
    'assets/js/information-room-viewer.js',
    'assets/css/information-room.css',
    'assets/uploads/information_room/.htaccess',
    'assets/uploads/information_room/index.php',
] as $rel) {
    if (is_file($root . '/' . $rel)) {
        ok('exists ' . $rel);
    } else {
        bad('missing ' . $rel);
    }
}

assertFileContains('admin/includes/admin-header.php', 'information-room.php', 'admin menu has Information Room');
assertFileContains('admin/includes/admin-header.php', 'information-room-logs', 'admin group includes access logs');
assertFileContains('member/includes/chrome.php', 'information-room.php', 'member nav has Information Room');
assertFileContains('admin/members.php', 'toggle_information_room', 'member enable toggle');
assertFileContains('includes/information-room-tables.php', 'irResolveFilePath', 'path confinement helper');
assertFileContains('includes/information-room-tables.php', 'assets/uploads/information_room/', 'upload folder confined');
assertFileContains('includes/information-room-tables.php', 'irDeleteStoredFile', 'file cleanup helper');
assertFileContains('includes/information-room-tables.php', 'irFetchAccessLogs', 'access log fetch helper');
assertFileContains('information-room-file.php', 'irNormalizeStoredPath', 'proxy uses path normalize');
assertFileContains('information-room-file.php', "\$GLOBALS['db'] = \$db", 'file proxy sets global db for currentMember');
assertFileContains('includes/information-room-viewer.php', '$irRestrict = true', 'viewer always restricts copy');

$viewerSrc = (string) file_get_contents($root . '/includes/information-room-viewer.php');
if (strpos($viewerSrc, 'sandbox=') === false) {
    ok('PDF iframe has no sandbox (browser PDF works)');
} else {
    bad('PDF iframe still sandboxed — preview may be blank');
}
assertFileContains('admin/information-room.php', 'irDeleteStoredFile', 'delete cleans disk file');
assertFileContains('admin/information-room.php', 'irAllowedUploadExtensions', 'upload type restricted');
assertFileContains('admin/information-room.php', '$restrictCopy = 1', 'restrict_copy always on');
assertFileContains('database/install.sql', 'information_room_items', 'install.sql has IR tables');
assertFileContains('database/install.sql', 'information_room_enabled', 'install.sql has member flag');
assertFileContains('admin/includes/ensure-admin-tables.php', 'ensureInformationRoomTables', 'admin ensure calls IR schema');

/* Unit-ish: path confinement without DB */
require_once $root . '/includes/information-room-tables.php';
$bad = irResolveFilePath('../includes/config.php');
$bad2 = irResolveFilePath('assets/uploads/gallery/x.pdf');
$okPath = irResolveFilePath('assets/uploads/information_room/sample.pdf');
if ($bad === '' && $bad2 === '') {
    ok('path confinement rejects traversal and other folders');
} else {
    bad('path confinement leaked unsafe path');
}
if ($okPath !== '' && str_contains(str_replace('\\', '/', $okPath), 'information_room')) {
    ok('path confinement accepts IR folder');
} else {
    bad('path confinement rejected valid IR path');
}

echo "\nPassed: $pass  Failed: $fail\n";
exit($fail > 0 ? 1 : 0);
