#!/usr/bin/env php
<?php
/**
 * Smoke test: CSS late-bundle load-order contracts (local build).
 * Run: php scripts/smoke-css-order.php
 * Exit 0 = pass.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;
$passed = 0;

function ok(string $msg): void {
    global $passed;
    $passed++;
    echo "OK  {$msg}\n";
}
function fail(string $msg): void {
    global $failed;
    $failed++;
    echo "FAIL {$msg}\n";
}

function assertContains(string $file, string $needle, string $why): void {
    global $root;
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fail("{$file}: missing ({$why})");
        return;
    }
    $t = file_get_contents($path);
    if ($t === false || strpos($t, $needle) === false) {
        fail("{$file}: missing `{$needle}` ({$why})");
        return;
    }
    ok("{$file}: {$why}");
}

$theme = (string) file_get_contents($root . '/includes/theme-assets.php');

assertContains('includes/theme-assets.php', "coopThemeLink('assets/css/app-public.css')", 'public base sheet');
assertContains('includes/theme-assets.php', "coopThemeLink('assets/css/app-admin.css')", 'admin base sheet');
assertContains('includes/theme-assets.php', "coopThemeLink('assets/css/app-member.css')", 'member base sheet');
assertContains('includes/theme-assets.php', "coopThemeLink('assets/css/public-late-bundle.css')", 'public late bundle');
assertContains('includes/theme-assets.php', "coopThemeLink('assets/css/admin-late-bundle.css')", 'admin late bundle');
assertContains('includes/theme-assets.php', "coopThemeLink('assets/css/member-late-bundle.css')", 'member late bundle');
assertContains('includes/theme-assets.php', "coopThemeLink('assets/css/minimal-late-bundle.css')", 'minimal late bundle');
assertContains('includes/theme-assets.php', 'build-css-late-bundles.py', 'local regenerator documented');
assertContains('includes/theme-assets.php', 'LATE BUNDLE LAST', 'load-order comment documents late bundle last');

// Mega app sheets must NOT be merged away
foreach (['app-public.css', 'app-admin.css', 'app-member.css'] as $sheet) {
    $path = $root . '/assets/css/' . $sheet;
    if (!is_file($path)) {
        fail("assets/css/{$sheet}: missing base sheet");
    } else {
        ok("assets/css/{$sheet}: base sheet kept separate");
    }
}

$bundles = [
    'public-late-bundle.css' => [
        'premium-ui.css',
        'ui-ux-polish.css',
        'mobile-premium-polish.css',
        'public-shell-polish.css',
        'ui-readability-safe-patch.css',
        'final-ui-polish.css',
    ],
    'admin-late-bundle.css' => [
        'premium-ui.css',
        'admin-shell-polish.css',
        'admin-ux-deep-patch.css',
        'final-ui-polish.css',
    ],
    'member-late-bundle.css' => [
        'member-shell-polish.css',
        'final-ui-polish.css',
    ],
    'minimal-late-bundle.css' => [
        'minimal-pages-patch.css',
        'final-ui-polish.css',
    ],
];

foreach ($bundles as $bundle => $markers) {
    $path = $root . '/assets/css/' . $bundle;
    if (!is_file($path)) {
        fail("assets/css/{$bundle}: missing");
        continue;
    }
    $t = (string) file_get_contents($path);
    if (strpos($t, 'AUTO-GENERATED') === false) {
        fail("assets/css/{$bundle}: missing AUTO-GENERATED banner");
    } else {
        ok("assets/css/{$bundle}: AUTO-GENERATED banner");
    }
    $prev = -1;
    $orderOk = true;
    foreach ($markers as $m) {
        $pos = strpos($t, 'BEGIN ' . $m);
        if ($pos === false) {
            fail("assets/css/{$bundle}: missing section {$m}");
            $orderOk = false;
            break;
        }
        if ($pos < $prev) {
            fail("assets/css/{$bundle}: order broken at {$m}");
            $orderOk = false;
            break;
        }
        $prev = $pos;
    }
    if ($orderOk && $prev >= 0) {
        ok("assets/css/{$bundle}: source order preserved");
    }
}

// Late bundle must appear after mid enhancements in theme-assets
$enhPos = strpos($theme, "coopThemeLinkDeferred('assets/css/ui-ux-enhancements.css')");
$latePos = strpos($theme, "coopThemeLink('assets/css/public-late-bundle.css')");
if ($enhPos === false || $latePos === false) {
    fail('includes/theme-assets.php: cannot locate enhancements/late bundle positions');
} elseif ($enhPos < $latePos) {
    ok('includes/theme-assets.php: enhancements before late bundle');
} else {
    fail('includes/theme-assets.php: late bundle must load after enhancements');
}

assertContains('scripts/build-css-late-bundles.py', 'public-late-bundle.css', 'local build script lists public bundle');

// Page-scoped KYC capture CSS still loaded (not orphaned)
assertContains('online-kyc.php', 'assets/css/kyc-capture.css', 'online-kyc loads kyc-capture.css');
assertContains('online-kyc.php', 'assets/js/kyc-capture.js?v=10.10', 'online-kyc capture js version');
assertContains('member/profile.php', 'assets/js/kyc-capture.js?v=10.10', 'member profile capture js synced');

// KYC soft polish markers
assertContains('online-kyc.php', 'id="kymWizardNav"', 'wizard nav id');
assertContains('online-kyc.php', 'aria-label="<?php echo isEnglish() ? \'KYM sections\'', 'wizard nav aria-label');
assertContains('online-kyc.php', "b.setAttribute('aria-current', 'step')", 'wizard step aria-current');
assertContains('online-kyc.php', 'id="kymNextBtn" aria-label=', 'next button aria-label');
assertContains('online-kyc.php', 'kymWizardBusy', 'wizard busy / double-advance guard');
assertContains('online-kyc.php', "submitBtn.setAttribute('aria-busy', 'true')", 'submit aria-busy on submit');
assertContains('online-kyc.php', 'kymFocusOnStep', 'wizard focus only after user navigation');

foreach (['includes/theme-assets.php', 'online-kyc.php', 'member/profile.php', 'scripts/build-css-late-bundles.py'] as $f) {
    if (str_ends_with($f, '.py')) {
        if (!is_file($root . '/' . $f)) {
            fail("{$f}: missing");
        } else {
            ok("{$f}: present");
        }
        continue;
    }
    $cmd = 'php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        fail("{$f}: php -l failed — " . implode(' ', $out));
    } else {
        ok("{$f}: php -l");
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
