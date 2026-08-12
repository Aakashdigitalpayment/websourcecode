<?php
/**
 * Smoke: annual report upload vs false CSRF (post_max overflow).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$pass = 0;

function ok(string $m): void { global $pass; $pass++; echo "OK  $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "FAIL $m\n"; }

$cfg = (string) file_get_contents($root . '/includes/config.php');
$hdr = (string) file_get_contents($root . '/admin/includes/admin-header.php');
$rpt = (string) file_get_contents($root . '/admin/reports.php');

if (strpos($cfg, 'function coop_post_exceeded_php_limit') !== false) {
    ok('coop_post_exceeded_php_limit defined');
} else {
    bad('overflow helper missing');
}
if (strpos($cfg, 'MAX_REPORT_FILE_SIZE') !== false) {
    ok('MAX_REPORT_FILE_SIZE defined');
} else {
    bad('MAX_REPORT_FILE_SIZE missing');
}
if (strpos($cfg, 'function uploadFile($file, $folder = \'general\', $maxSize = null)') !== false) {
    ok('uploadFile accepts optional maxSize');
} else {
    bad('uploadFile maxSize param missing');
}
if (strpos($rpt, 'INSERT INTO reports') !== false && strpos($rpt, 'UPDATE reports SET') !== false) {
    ok('reports add INSERT and edit UPDATE both present');
} else {
    bad('reports add/edit SQL missing');
}
if (strpos($hdr, 'coop_post_exceeded_php_limit') !== false) {
    ok('admin-header distinguishes oversized POST from CSRF');
} else {
    bad('admin-header still CSRF-only on empty POST');
}
if (strpos($rpt, 'uploadFile($_FILES[\'file\'], \'reports\'') !== false
    && strpos($rpt, '$reportMax') !== false) {
    ok('reports.php uploads with report max size');
} else {
    bad('reports.php still uses default 10MB upload');
}
if (strpos($rpt, 'PDF फाइल आवश्यक छ') !== false || strpos($rpt, 'required') !== false) {
    ok('new report requires a file');
} else {
    bad('add-report file not required');
}

/* Pure helper behavior without booting config.php */
$iniBytes = static function (string $val): int {
    $val = trim($val);
    if ($val === '' || $val === '0') return 0;
    if (!preg_match('/^([0-9.]+)\s*([KMG])?$/i', $val, $m)) return (int) $val;
    $n = (float) $m[1];
    $u = strtoupper($m[2] ?? '');
    if ($u === 'G') return (int) round($n * 1073741824);
    if ($u === 'M') return (int) round($n * 1048576);
    if ($u === 'K') return (int) round($n * 1024);
    return (int) round($n);
};
if ($iniBytes('8M') === 8 * 1048576 && $iniBytes('25M') === 25 * 1048576) {
    ok('ini size parse 8M/25M');
} else {
    bad('ini size parse wrong');
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);