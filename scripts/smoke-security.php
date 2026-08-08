#!/usr/bin/env php
<?php
/**
 * Smoke test: safe security regressions (headers, stubs, tabnabbing).
 * Run: php scripts/smoke-security.php
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

function assertFileContains(string $file, string $needle, string $why): void {
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

function assertNoBareBlankTargets(string $file): void {
    global $root;
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fail("{$file}: missing (noopener sweep)");
        return;
    }
    $t = file_get_contents($path);
    if ($t === false) {
        fail("{$file}: unreadable");
        return;
    }
    // Mask PHP blocks so close-tags inside attrs do not truncate anchors.
    $phpClose = '?' . '>';
    $t = preg_replace('/<\?(?:php|=)?[\s\S]*?' . preg_quote($phpClose, '/') . '/i', ' PHP ', $t);
    if (!is_string($t)) {
        fail("{$file}: php-mask failed");
        return;
    }
    if (!preg_match_all('/<a\b[^>]*>/i', $t, $m)) {
        ok("{$file}: no anchors (noopener sweep)");
        return;
    }
    $bad = 0;
    foreach ($m[0] as $tag) {
        if (!preg_match('/target=["\']_blank["\']/i', $tag)) {
            continue;
        }
        if (!preg_match('/rel=["\'][^"\']*noopener/i', $tag)) {
            $bad++;
        }
    }
    if ($bad > 0) {
        fail("{$file}: {$bad} target=_blank without noopener");
        return;
    }
    ok("{$file}: all target=_blank have noopener");
}

// Session debug stub must not leak
assertFileContains('member/session-check.php', 'http_response_code(403)', 'session-check returns 403');
assertFileContains('member/session-check.php', 'Forbidden', 'session-check body Forbidden');
$session = (string) file_get_contents($root . '/member/session-check.php');
foreach (['session_id(', 'var_dump', 'print_r', 'phpinfo'] as $leak) {
    if (stripos($session, $leak) !== false) {
        fail("member/session-check.php: still contains {$leak}");
    } else {
        ok("member/session-check.php: no {$leak}");
    }
}

// Cron URL token hardening
assertFileContains('cron-cleanup.php', 'strlen($secret) < 20', 'cron requires 20+ token');
assertFileContains('cron-cleanup.php', 'hash_equals', 'cron uses hash_equals');

// Baseline security headers (public bootstrap)
assertFileContains('includes/config.php', "header('X-Frame-Options: SAMEORIGIN')", 'X-Frame-Options');
assertFileContains('includes/config.php', "header('X-Content-Type-Options: nosniff')", 'nosniff');
assertFileContains('includes/config.php', "header('Referrer-Policy: strict-origin-when-cross-origin')", 'Referrer-Policy');

// Tabnabbing — public high-traffic surfaces
$noopenerFiles = [
    'important-links.php',
    'includes/footer.php',
    'includes/header.php',
    'loan-apply.php',
    'institutional-profile.php',
    'index.php',
    'contact.php',
    'news-detail.php',
    'reports.php',
    'downloads.php',
    'application-tracker.php',
    'member/profile.php',
    'member/tracker.php',
    'admin/help-guide.php',
    'admin/dashboard.php',
    'admin/settings.php',
    'admin/hrm-employees.php',
    'admin/notification-settings.php',
    'admin/kyc-applications.php',
];
foreach ($noopenerFiles as $f) {
    assertNoBareBlankTargets($f);
}

// Critical pairs
assertFileContains('important-links.php', 'rel="noopener noreferrer"', 'NRB/gov links hardened');
assertFileContains('includes/footer.php', 'whatsapp-float" target="_blank" rel="noopener noreferrer"', 'WhatsApp float hardened');

assertFileContains('admin/help-guide.php', 'rel="noopener noreferrer"', 'admin help-guide links hardened');

// Syntax
$lintFiles = array_merge(
    ['member/session-check.php', 'cron-cleanup.php'],
    $noopenerFiles
);
foreach ($lintFiles as $f) {
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
