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
        if (!preg_match('/rel=["\'][^"\']*noopener/i', $tag)
            || !preg_match('/rel=["\'][^"\']*noreferrer/i', $tag)) {
            $bad++;
        }
    }
    if ($bad > 0) {
        fail("{$file}: {$bad} target=_blank without noopener noreferrer");
        return;
    }
    ok("{$file}: all target=_blank have noopener noreferrer");
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

// CSP — enforce by default (kill-switch: CSP_ENFORCE false or csp_enforce=0)
$configSecurity = (string) file_get_contents($root . '/includes/config.php');
assertFileContains('includes/config.php', "header('Content-Security-Policy: ' . \$_cspPolicy)", 'CSP enforce path');
assertFileContains('includes/config.php', "header('Content-Security-Policy-Report-Only: ' . \$_cspPolicy)", 'CSP Report-Only kill-switch path');
assertFileContains('includes/config.php', "getSetting('csp_enforce', '1')", 'csp_enforce setting kill-switch');
assertFileContains('includes/config.php', 'defined(\'CSP_ENFORCE\')', 'CSP_ENFORCE constant kill-switch');
assertFileContains('includes/config.php', 'https://unpkg.com', 'CSP allows unpkg (Leaflet)');
assertFileContains('includes/config.php', 'frame-src', 'CSP frame-src for maps/embeds');
assertFileContains('includes/config.php', 'connect-src', 'CSP connect-src for XHR/fetch');
assertFileContains('includes/config.php', 'worker-src', 'CSP worker-src for QR scanner');
assertFileContains('includes/config.php', 'Permissions-Policy: geolocation=(self)', 'KYC map geolocation allowed same-origin');
assertFileContains('includes/member-auth.php', 'Permissions-Policy: geolocation=(self)', 'member headers geolocation same-origin');
// Ensure we did not leave a total geo deny that breaks KYC locate
$ppConfig = (string) file_get_contents($root . '/includes/config.php');
if (strpos($ppConfig, 'geolocation=(),') !== false) {
    fail('includes/config.php: geolocation still fully denied');
} else {
    ok('includes/config.php: geolocation not fully denied');
}
$boot = (string) file_get_contents($root . '/_bootstrap.php');
if (preg_match('/header\s*\(\s*[\'"]X-XSS-Protection/i', $boot)) {
    fail('_bootstrap.php: deprecated X-XSS-Protection still set');
} else {
    ok('_bootstrap.php: no deprecated X-XSS-Protection header');
}

// javascript: URLs break under enforcing CSP — must be gone from chrome
foreach (['includes/header.php', 'member/includes/chrome.php', 'admin/includes/admin-header.php'] as $f) {
    $t = (string) file_get_contents($root . '/' . $f);
    if (preg_match('/href\s*=\s*[\'"]javascript:/i', $t)) {
        fail("{$f}: still has javascript: href (CSP unsafe)");
    } else {
        ok("{$f}: no javascript: href");
    }
}
assertFileContains('includes/header.php', 'href="#" id="topbarSearchBtn"', 'search control uses hash href');
assertFileContains('includes/header.php', 'onclick="event.preventDefault();"', 'hash controls preventDefault');

// Tabnabbing — public/member/admin surfaces (noopener + noreferrer)
$noopenerFiles = [
    'important-links.php',
    'includes/footer.php',
    'includes/header.php',
    'loan-apply.php',
    'institutional-profile.php',
    'index.php',
    'contact.php',
    'news-detail.php',
    'notices.php',
    'career-detail.php',
    'auction.php',
    'election-information.php',
    'reports.php',
    'downloads.php',
    'application-tracker.php',
    'program-attendance-verify.php',
    'install.php',
    'member/profile.php',
    'member/tracker.php',
    'member/index.php',
    'member/includes/chrome.php',
    'admin/help-guide.php',
    'admin/dashboard.php',
    'admin/settings.php',
    'admin/hrm-employees.php',
    'admin/notification-settings.php',
    'admin/kyc-applications.php',
    'admin/ai-settings.php',
    'admin/auctions.php',
    'admin/credentials.php',
    'admin/includes/admin-excel-export.php',
    'admin/includes/admin-request-view.php',
];
foreach ($noopenerFiles as $f) {
    assertNoBareBlankTargets($f);
}
assertFileContains('assets/js/main.js', 'target="_blank" rel="noopener noreferrer" class="popup-doc-btn"', 'popup PDF link hardened');
assertFileContains('install.php', 'id="linkAdmin" class="btn-site btn-site-primary" target="_blank" rel="noopener noreferrer"', 'install admin link hardened');

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
assertFileContains('admin/pages.php', 'integrity="sha384-lo8/CN/iRaTSWve/rcVNU06/qOA1Qn47bB4ENNcUQ7tLVBqPca8yRbxhx5ic7UZM"', 'TinyMCE SRI');
assertFileContains('admin/includes/admin-footer.php', 'integrity="sha384-iRqAtUS5zaxUb29RlrazJxjB/+B6yhysd3tFSeMTcmvAgxeXTVWBk4OlbSJWpthT"', 'CKEditor SRI');
assertFileContains('includes/config.php', 'https://cdn.ckeditor.com', 'CSP allows CKEditor CDN');
assertFileContains('member/login.php', 'rel="noopener noreferrer" class="twofa-qr-link"', 'member 2FA QR noreferrer');
assertFileContains('admin/index.php', 'rel="noopener noreferrer" class="link-primary-strong"', 'admin 2FA QR noreferrer');
assertFileContains('member/login.php', 'id="twofa_code"', 'member 2FA code field');
assertFileContains('member/login.php', 'autocomplete="one-time-code"', 'member 2FA OTP autocomplete');
assertFileContains('admin/index.php', 'id="admin_twofa_code"', 'admin 2FA code field');
assertFileContains('admin/index.php', 'autocomplete="one-time-code"', 'admin 2FA OTP autocomplete');
assertFileContains('member/password-reset-request.php', 'id="otpInput"', 'password-reset OTP field');
assertFileContains('member/password-reset-request.php', 'autocomplete="one-time-code"', 'password-reset OTP autocomplete');
assertFileContains('install.php', 'id="admin_password"', 'install admin password field');
assertFileContains('install.php', 'autocomplete="new-password"', 'install password autocomplete');
assertFileContains('includes/auction-tables.php', 'title="Auction location map"', 'auction map iframe title');
assertFileContains('includes/auction-tables.php', 'loading="lazy"', 'auction map iframe lazy');
assertFileContains('gallery.php', 'id="galleryVideoFrame"', 'gallery video iframe');
assertFileContains('gallery.php', 'loading="lazy"', 'gallery video iframe lazy');
assertFileContains('404.php', 'type="button" onclick="history.back()"', '404 back button typed');
assertFileContains('includes/footer.php', 'type="button" class="chatbot-close"', 'chatbot close typed');
assertFileContains('includes/footer.php', 'type="button" class="search-modal-close"', 'search close typed');
assertFileContains('member/login.php', 'type="button" class="tab-btn', 'member login tabs typed');
assertFileContains('includes/header.php', 'type="button" class="pfl-bell-btn"', 'header bell typed');
assertFileContains('includes/header.php', 'type="button" class="mobile-menu-toggle', 'legacy mobile menu typed');
assertFileContains('contact.php', 'type="button" class="btn ct-btn-primary btn-lg w-100" data-bs-toggle="modal"', 'contact modal open typed');
assertFileContains('application-tracker.php', 'type="button" class="tracker-tab-btn active"', 'tracker tabs typed');
assertFileContains('member/password-reset-request.php', 'type="submit" class="btn btn-outline-danger btn-sm w-100"', 'password-reset cancel typed submit');
assertFileContains('cooperative-programs.php', 'type="submit" class="btn btn-sm btn-primary"', 'program prereg submit typed');
assertFileContains('admin/help-guide.php', 'id="hgSearch"', 'help-guide search field');
assertFileContains('admin/help-guide.php', 'autocomplete="off"', 'help-guide search autocomplete off');
assertFileContains('admin/print-form.php', 'type="button" onclick="history.back()"', 'print-form back typed');
assertFileContains('includes/satisfaction-widget.php', 'type="button" class="satisfaction-toggle"', 'satisfaction toggle typed');
assertFileContains('includes/footer.php', 'type="button" id="uiTestClose"', 'ui-test panel buttons typed');
assertFileContains('auction.php', 'type="button" class="auc2-fchip active"', 'auction filter chips typed');

// Ensure high-traffic interactive buttons declare an explicit type=
$typedButtonFiles = [
    'contact.php',
    'application-tracker.php',
    'includes/header.php',
    'includes/satisfaction-widget.php',
    'member/password-reset-request.php',
    'member/attend.php',
    'offline.php',
];
foreach ($typedButtonFiles as $f) {
    $path = $root . '/' . $f;
    if (!is_file($path)) {
        fail("{$f}: missing (button type sweep)");
        continue;
    }
    $t = (string) file_get_contents($path);
    $phpClose = '?' . '>';
    $t = preg_replace('/<\?(?:php|=)?[\s\S]*?' . preg_quote($phpClose, '/') . '/i', ' PHP ', $t);
    if (!is_string($t)) {
        fail("{$f}: php-mask failed (button type)");
        continue;
    }
    $bad = 0;
    if (preg_match_all('/<button\b([^>]*)>/i', $t, $m)) {
        foreach ($m[1] as $attrs) {
            if (!preg_match('/\btype\s*=/i', $attrs)) {
                $bad++;
            }
        }
    }
    if ($bad > 0) {
        fail("{$f}: {$bad} button(s) missing type=");
    } else {
        ok("{$f}: all buttons have explicit type");
    }
}

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
