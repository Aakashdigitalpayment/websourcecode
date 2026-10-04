#!/usr/bin/env php
<?php
/**
 * Smoke: program module UI — uniform time controls, local QR, button system.
 * Run: php scripts/smoke-program-ui.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/program-tables.php';
require_once $root . '/includes/qr-local.php';

$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "OK  {$msg}\n"; } else { $failed++; echo "FAIL {$msg}\n"; }
}
$read = static fn (string $f): string => (string) file_get_contents($root . '/' . $f);

$sel = programWindowTimeSelectHtml('attendance_open_time', 'x', '');
check(str_contains($sel, '<option value="00:00" selected>12:00 AM</option>'), 'window time default 00:00');
check(str_contains(programWindowTimeSelectHtml('a', 'b', '', '23:59'), 'value="23:59" selected'), 'window close default 23:59');
check(str_contains(programWindowTimeSelectHtml('a', 'b', '10:15'), 'value="10:15" selected'), 'unlisted saved time kept selectable');

foreach (['admin/programs.php', 'admin/program-occurrences.php'] as $f) {
    check(!str_contains($read($f), 'type="time"'), "{$f}: no native time inputs (all time fields are one select style)");
}
foreach (['admin/programs.php', 'admin/program-detail.php', 'admin/program-registration-desk.php'] as $f) {
    $src = $read($f);
    check(!str_contains($src, 'api.qrserver.com') && !str_contains($src, 'chart.googleapis.com'), "{$f}: attendance QR drawn locally");
}
check(str_contains(coop_qr_img_tag('https://x.test/a?b=1'), 'data-qr="https://x.test/a?b=1"'), 'coop_qr_img_tag markup');
check(str_contains($read('assets/js/totp-qr.js'), 'img[data-qr]') && str_contains($read('assets/js/totp-qr.js'), 'coopQrDataUrl'), 'local QR renderer handles data-qr + JS API');
check(str_contains($read('admin/programs.php'), '<textarea name="description"'), 'program description is a textarea');
$css = $read('assets/css/admin-ux-deep-patch.css');
check(str_contains($css, 'body[class*="admin-page-program"]:not(.dark-mode) .main-content .btn'), 'program button system present');
check(str_contains($css, 'table.table:not(:has(td[data-label]))'), 'unlabelled tables stay tables on phones');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
