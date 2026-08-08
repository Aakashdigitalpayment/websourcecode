#!/usr/bin/env php
<?php
/**
 * Smoke test: CSS load-order contracts (cleanup without merge).
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
assertContains('includes/theme-assets.php', "coopThemeLinkDeferred('assets/css/ui-readability-safe-patch.css')", 'readability patch deferred');
assertContains('includes/theme-assets.php', "coopThemeLink('assets/css/final-ui-polish.css')", 'final polish present');
assertContains('includes/theme-assets.php', 'final-ui-polish LAST', 'load-order comment documents last sheet');

$readabilityPos = strpos($theme, "coopThemeLinkDeferred('assets/css/ui-readability-safe-patch.css')");
$finalPos = strpos($theme, "coopThemeLink('assets/css/final-ui-polish.css')");
if ($readabilityPos === false || $finalPos === false) {
    fail('includes/theme-assets.php: cannot locate readability/final link positions');
} elseif ($readabilityPos < $finalPos) {
    ok('includes/theme-assets.php: readability patch before final-ui-polish');
} else {
    fail('includes/theme-assets.php: final-ui-polish must load after readability patch');
}

// Page-scoped KYC capture CSS still loaded (not orphaned)
assertContains('online-kyc.php', 'assets/css/kyc-capture.css', 'online-kyc loads kyc-capture.css');
assertContains('online-kyc.php', 'assets/js/kyc-capture.js?v=10.10', 'online-kyc capture js version');
assertContains('member/profile.php', 'assets/js/kyc-capture.js?v=10.10', 'member profile capture js synced');

// KYC soft polish markers
assertContains('online-kyc.php', 'id="kymWizardNav"', 'wizard nav id');
assertContains('online-kyc.php', 'aria-label="<?php echo isEnglish() ? \'KYM sections\'', 'wizard nav aria-label');
assertContains('online-kyc.php', "b.setAttribute('aria-current', 'step')", 'wizard step aria-current');
assertContains('online-kyc.php', 'id="kymNextBtn" aria-label=', 'next button aria-label');

foreach (['includes/theme-assets.php', 'online-kyc.php', 'member/profile.php'] as $f) {
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
