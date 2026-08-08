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

// CSP stays report-only (must NOT flip to enforce in this safe pass)
$configSecurity = (string) file_get_contents($root . '/includes/config.php');
if (strpos($configSecurity, 'Content-Security-Policy-Report-Only:') === false) {
    fail('includes/config.php: missing CSP Report-Only header');
} else {
    ok('includes/config.php: CSP Report-Only present');
}
if (preg_match('/header\s*\(\s*[\'"]Content-Security-Policy:/', $configSecurity)) {
    fail('includes/config.php: enforcing CSP header found (unsafe for this pass)');
} else {
    ok('includes/config.php: no enforcing CSP header');
}
assertFileContains('includes/config.php', 'https://unpkg.com', 'CSP allows unpkg (Leaflet)');
assertFileContains('includes/config.php', 'frame-src', 'CSP frame-src for maps/embeds');
assertFileContains('includes/config.php', 'connect-src', 'CSP connect-src for XHR/fetch');
assertFileContains('includes/config.php', 'worker-src', 'CSP worker-src for QR scanner');

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

// Password autocomplete / secret fields
assertFileContains('member/login.php', 'id="loginPw"', 'member login password field');
assertFileContains('member/login.php', 'autocomplete="current-password"', 'member login autocomplete');
assertFileContains('member/password-reset-request.php', 'id="pw1"', 'reset password field');
assertFileContains('member/password-reset-request.php', 'autocomplete="new-password"', 'reset password autocomplete');
assertFileContains('admin/change-password.php', 'autocomplete="current-password"', 'admin change-password autocomplete');
assertFileContains('admin/settings.php', 'id="stg_google_client_secret"', 'google secret field');
assertFileContains('admin/settings.php', 'autocomplete="off"', 'oauth secrets autocomplete off');
assertFileContains('admin/notification-settings.php', 'id="notify_sms_token"', 'sms token field');
assertFileContains('admin/notification-settings.php', 'autocomplete="off"', 'notify secrets autocomplete off');

// Language switcher a11y
assertFileContains('includes/header.php', 'class="skip-link"', 'skip link present');
assertFileContains('includes/header.php', 'aria-label="<?php echo isEnglish() ? \'English\'', 'EN lang aria-label');
assertFileContains('includes/header.php', 'aria-label="<?php echo isEnglish() ? \'Nepali\'', 'NP lang aria-label');
assertFileContains('application-tracker.php', 'id="secCodeToggle"', 'security code toggle');
assertFileContains('application-tracker.php', 'aria-label="<?php echo isEnglish() ? \'Show security code\'', 'security code toggle aria-label');

// CDN Subresource Integrity (pinned versions)
$chartSri = 'integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g"';
assertFileContains('institutional-profile.php', $chartSri, 'Chart.js SRI public');
assertFileContains('admin/analytics.php', $chartSri, 'Chart.js SRI analytics');
assertFileContains('admin/program-attendance.php', $chartSri, 'Chart.js SRI program-attendance');
assertFileContains('member/scan.php', 'integrity="sha384-c9d8RFSL+u3exBOJ4Yp3HUJXS4znl9f+z66d1y54ig+ea249SpqR+w1wyvXz/lk+"', 'html5-qrcode SRI');
assertFileContains('online-kyc.php', 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="', 'Leaflet JS SRI');
assertFileContains('member/login.php', 'rel="noopener noreferrer" class="twofa-qr-link"', 'member 2FA QR noreferrer');
assertFileContains('admin/index.php', 'rel="noopener noreferrer" class="link-primary-strong"', 'admin 2FA QR noreferrer');

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
