<?php
/**
 * Smoke: member import optional KYC columns (father, citizenship, membership_date).
 * Run: php scripts/smoke-member-import-optional.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;
$passed = 0;

function ok(string $m): void { global $passed; $passed++; echo "OK  {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "FAIL {$m}\n"; }

require_once $root . '/includes/member-import-helpers.php';

$headers = [
    'father_name' => 'father_name',
    'Father' => 'father_name',
    'citizenship_no' => 'citizenship_no',
    'Citizenship Number' => 'citizenship_no',
    'नागरिकता_नं' => 'citizenship_no',
    'membership_date' => 'membership_date',
    'membership_date_bs' => 'membership_date_bs',
    'join_date' => 'membership_date',
    'सदस्यता_मिति' => 'membership_date_bs',
];
foreach ($headers as $in => $want) {
    $got = memberImportNormalizeHeader($in);
    if ($got === $want) {
        ok("alias `{$in}` → {$got}");
    } else {
        fail("alias `{$in}` → {$got} want {$want}");
    }
}

$cit = memberImportNormalizeCitizenship('४१-०२-७१-०५६७८');
if ($cit === '41-02-71-05678') {
    ok("citizenship Devanagari → {$cit}");
} else {
    fail("citizenship Devanagari → {$cit}");
}

$md = memberImportNormalizeDob('2075-04-15', 'bs');
if (is_string($md) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $md)) {
    ok("membership_date BS convert → {$md}");
} else {
    fail('membership_date BS convert failed');
}

$sample = (string) file_get_contents($root . '/admin/member-import-sample.php');
foreach (['father_name', 'citizenship_no', 'membership_date'] as $col) {
    if (str_contains($sample, "'{$col}'")) {
        ok("sample CSV has {$col}");
    } else {
        fail("sample CSV missing {$col}");
    }
}

$helpers = (string) file_get_contents($root . '/includes/member-import-helpers.php');
if (str_contains($helpers, 'function memberImportApplyOptionalExtras')) {
    ok('apply optional extras helper');
} else {
    fail('apply optional extras helper missing');
}
if (str_contains($helpers, 'kyc_applications SET') && str_contains($helpers, 'father_name = CASE')) {
    ok('KYM soft-fill father/citizenship');
} else {
    fail('KYM soft-fill missing');
}
if (str_contains($helpers, 'function memberImportShouldGenerateCards')) {
    ok('bulk card defer helper');
} else {
    fail('bulk card defer helper missing');
}

/* Soft mobile / email from messy CBS cells */
$m1 = memberImportNormalizeMobile('9865707553, 9861436227');
if ($m1 === '9865707553') {
    ok("dual mobile → first {$m1}");
} else {
    fail("dual mobile → {$m1}");
}
$m977 = memberImportNormalizeMobile('9779841234567');
if ($m977 === '9841234567') {
    ok("977 country code → {$m977}");
} else {
    fail("977 country code → {$m977} want 9841234567");
}
$m0 = memberImportNormalizeMobile('0');
if ($m0 === '') {
    ok('mobile 0 → blank');
} else {
    fail("mobile 0 → {$m0}");
}
$e1 = memberImportNormalizeEmail('NULL');
if ($e1 === '') {
    ok('email NULL → blank');
} else {
    fail("email NULL → {$e1}");
}
if (!memberImportShouldGenerateCards(['total_rows' => 34000])) {
    ok('large job defers cards');
} else {
    fail('large job should defer cards');
}
if (memberImportShouldGenerateCards(['total_rows' => 100])) {
    ok('small job generates cards');
} else {
    fail('small job should generate cards');
}
if (!memberImportShouldRunKyc(['total_rows' => 34000])) {
    ok('large job skips KYM stubs');
} else {
    fail('large job should skip KYM');
}
if (function_exists('memberImportClearOutputBuffers')) {
    ok('clear output buffers helper');
} else {
    fail('clear output buffers missing');
}
if (function_exists('memberImportReconcileJobCounts')) {
    ok('reconcile job counts helper');
} else {
    fail('reconcile helper missing');
}
if (function_exists('memberImportExportErrors')) {
    ok('export errors helper');
} else {
    fail('export errors missing');
}

/* Export must survive CSP output buffer (was emptying Error CSV in browser) */
ob_start(static function (string $html): string {
    return $html; /* nest like config.php CSP OB */
});
ob_start();
$tmpDb = new PDO('sqlite::memory:');
/* Export without DB tables — should still emit CSV header via ensure or catch.
   Use a mock by only testing buffer clear + fputcsv path through a tiny shim. */
memberImportClearOutputBuffers();
$levelAfter = ob_get_level();
if ($levelAfter === 0) {
    ok('ClearOutputBuffers drops nested CSP buffers');
} else {
    fail("ClearOutputBuffers left ob_level={$levelAfter}");
}

$ui = (string) file_get_contents($root . '/admin/member-import.php');
if (str_contains($ui, 'father_name') && str_contains($ui, 'citizenship_no') && str_contains($ui, 'membership_date')) {
    ok('admin import UI documents new columns');
} else {
    fail('admin import UI docs');
}

foreach (['includes/member-import-helpers.php', 'admin/member-import-sample.php', 'admin/member-import.php', 'includes/member-auth.php', 'includes/card-verify-helpers.php'] as $f) {
    $cmd = 'php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code === 0) {
        ok("php -l {$f}");
    } else {
        fail("php -l {$f}: " . implode(' ', $out));
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
