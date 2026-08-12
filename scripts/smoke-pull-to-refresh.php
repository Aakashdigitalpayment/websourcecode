<?php
/**
 * Smoke: pull-to-refresh must arm only at document top (no mid-page reload).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$pass = 0;

function ok(string $m): void { global $pass; $pass++; echo "OK  $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "FAIL $m\n"; }

$js = (string) file_get_contents($root . '/assets/js/pull-to-refresh.js');
$hdr = (string) file_get_contents($root . '/includes/header.php');

if (strpos($js, 'v1.3') !== false) {
    ok('pull-to-refresh.js is v1.3');
} else {
    bad('expected v1.3 banner');
}

if (preg_match('/\bvar armed\b|\barmed\s*=/', $js)) {
    ok('armed flag present');
} else {
    bad('armed flag missing');
}

if (strpos($js, 'if (!armed') !== false || strpos($js, 'if(!armed') !== false) {
    ok('touchmove/end gated on armed');
} else {
    bad('handlers not gated on armed');
}

if (strpos($js, 'pageScrollY()') !== false) {
    ok('uses pageScrollY helper');
} else {
    bad('pageScrollY missing');
}

/* Regression: mid-page must not use stale startY=0 without arming */
if (preg_match('/touchmove[\s\S]{0,200}if \(!armed/', $js)
    || preg_match("/addEventListener\\('touchmove'[\\s\\S]{0,120}!armed/", $js)
) {
    ok('touchmove checks armed early');
} else {
    bad('touchmove may still run unarmed');
}

if (strpos($hdr, 'pull-to-refresh.js?v=1.3') !== false) {
    ok('header cache-busts PTR to v=1.3');
} else {
    bad('header still on old PTR ?v=');
}

/* Simulate old bug vs fix logic in PHP (mirrors intent) */
$simulate = static function (bool $useArmed, float $scrollY, float $startY, float $clientY): bool {
    $armed = false;
    if ($scrollY <= 2) {
        $armed = true;
        // startY set
    }
    if ($useArmed && !$armed) {
        return false; // no reload
    }
    $dy = $clientY - ($useArmed ? $startY : 0); // old bug: startY stayed 0
    if (!$useArmed && $scrollY > 2) {
        // old code still computed dy from stale 0
        $dy = $clientY - 0;
    }
    $pull = min($dy * 0.52, 110);
    return $pull >= 88;
};

if ($simulate(false, 400, 0, 300) === true) {
    ok('reproduced old mid-page false trigger');
} else {
    bad('could not reproduce old bug in sim');
}
if ($simulate(true, 400, 0, 300) === false) {
    ok('armed fix blocks mid-page trigger');
} else {
    bad('armed fix still triggers mid-page');
}
if ($simulate(true, 0, 40, 220) === true) {
    ok('intentional top pull still triggers');
} else {
    bad('top pull no longer works');
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);