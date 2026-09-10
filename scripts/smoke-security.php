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

function assertFileNotContains(string $file, string $needle, string $why): void {
    global $root;
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fail("{$file}: missing ({$why})");
        return;
    }
    $t = file_get_contents($path);
    if ($t === false) {
        fail("{$file}: unreadable ({$why})");
        return;
    }
    if (strpos($t, $needle) !== false) {
        fail("{$file}: unexpectedly contains `{$needle}` ({$why})");
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

// Session debug stub removed; web rules must still deny the path
if (is_file($root . '/member/session-check.php')) {
    fail('member/session-check.php: should be deleted (was always 403)');
} else {
    ok('member/session-check.php: deleted');
}
assertFileContains('.htaccess', 'member/session-check', 'htaccess still forbids session-check path');
assertFileContains('deploy/nginx-security.conf', 'member/session-check.php', 'nginx still denies session-check path');
assertFileContains('member/push-subscribe.php', 'verifyCSRFToken', 'push subscribe CSRF guard');
assertFileContains('member/includes/chrome.php', 'MEMBER_PUSH_CSRF', 'push subscribe client CSRF token');
assertFileContains('includes/config.php', 'function coop_sanitize_icon_class', 'icon class sanitizer');
assertFileContains('reports.php', 'e(getLangField($report', 'report title output escaped');
assertFileContains('digital-services.php', 'coop_sanitize_icon_class', 'digital services icon sanitize');
assertFileContains('includes/config.php', 'function coop_new_tracking_id', 'strong tracking id helper');
assertFileContains('includes/config.php', 'COOP_HMAC_LEGACY_SECRET', 'HMAC legacy only via optional local define');
assertFileNotContains('includes/config.php', 'aakash-fallback-secret-2026', 'no public shared HMAC fallback');
assertFileContains('application-tracker.php', 'never auto-loads PII', 'tracker blocks GET auto PII lookup');
assertFileContains('application-tracker.php', 'Disabled: sequential GRV-1', 'tracker legacy numeric id disabled');
assertFileContains('includes/member-auth.php', 'hash_hmac(\'sha256\', $otp', 'OTP stored hashed');
assertFileContains('includes/member-auth.php', 'https://api.sparrowsms.com/v2/sms/', 'Sparrow SMS over HTTPS');
assertFileContains('admin/useful-links.php', 'safe_http_url', 'useful links URL hardened');
assertFileContains('includes/footer.php', 'safe_http_url((string)($link[\'url\']', 'footer useful links escaped');
assertFileContains('member/password-reset-request.php', 'member_otp_send', 'password reset OTP rate limit');
assertFileContains('member/password-reset-request.php', 'If an account matches these details', 'password reset anti-enumeration');

assertFileContains('downloads.php', 'coop_public_download_url($item', 'download file href guarded');
assertFileContains('api-public-chat.php', 'contact_messages', 'public chat stores in contact_messages');
assertFileContains('api-public-chat.php', 'coop_contact_guard_block_reason', 'public chat uses shared contact guard');
assertFileContains('api-public-chat.php', 'live_chat', 'public chat math bucket');
assertFileContains('contact.php', 'coop_contact_guard_block_reason', 'contact form uses shared guard');
assertFileContains('contact.php', 'coop_public_form_anti_bot_html', 'contact form shared anti-bot UI');
assertFileContains('includes/footer.php', 'coop_public_form_anti_bot_html', 'live chat shared anti-bot UI');
assertFileContains('api-public-chat.php', 'coop_public_form_math_payload', 'public chat refreshes math via shared payload');
assertFileContains('api-public-chat.php', 'coop_public_form_guard_message', 'public chat shared guard messages');
assertFileContains('appointment.php', 'coop_public_form_bot_block', 'appointment bot guard');
assertFileContains('loan-apply.php', 'coop_public_form_bot_block', 'loan bot guard');
assertFileContains('grievance.php', 'coop_public_form_bot_block', 'grievance bot guard');
assertFileContains('online-account.php', 'coop_public_form_bot_block', 'account bot guard');
assertFileContains('online-kyc.php', 'coop_public_form_gate', 'kyc uses shared public form gate');
assertFileContains('online-kyc.php', "kycQuick", 'kyc quick form has anti-bot UI');
assertFileContains('online-kyc.php', 'elseif (!$isMemberLoggedIn && isset($_POST[\'public_kym_verify\']))', 'kyc membership/verify elseif chain');
assertFileContains('member/password-reset-request.php', "in_array(\$__prAction, ['send_otp', 'verify_otp', 'set_password']", 'password-reset math only on primary steps');
assertFileContains('admin/security-settings.php', 'turnstile_clear_secret', 'turnstile secret keep-unless-changed');
assertFileContains('includes/contact-spam-guard.php', 'function coop_public_form_gate', 'shared public form gate helper');
assertFileContains('career-detail.php', 'coop_public_form_bot_block', 'career bot guard');
assertFileContains('digital-services.php', 'coop_public_form_bot_block', 'digital services bot guard');
assertFileContains('vendor-enlistment.php', 'coop_public_form_bot_block', 'vendor bot guard');
assertFileContains('honor-apply.php', 'coop_public_form_bot_block', 'honor bot guard');
assertFileContains('member-welfare.php', 'coop_public_form_bot_block', 'welfare bot guard');
assertFileContains('auction.php', 'coop_public_form_bot_block', 'auction bot guard');
assertFileContains('member-survey.php', 'coop_public_form_bot_block', 'survey bot guard');
assertFileContains('application-tracker.php', 'coop_public_form_bot_block', 'tracker bot guard');
assertFileContains('includes/member-marketplace-public-page.php', 'coop_public_form_bot_block', 'marketplace inquiry bot guard');
assertFileContains('attend.php', 'coop_public_form_bot_block', 'attendance bot guard');
assertFileContains('cooperative-programs.php', 'coop_public_form_bot_block', 'program prereg bot guard');
assertFileContains('cooperative-programs.php', 'coop_public_form_anti_bot_html', 'program prereg anti-bot UI');
assertFileContains('cooperative-programs.php', "checkRateLimit('program_prereg_public'", 'program prereg rate limit');
assertFileContains('verify.php', "coop_public_form_bot_block(\$_POST, 'prog_prereg'", 'verify prereg POST bot guard');
assertFileContains('verify.php', "checkRateLimit('program_prereg_public'", 'verify prereg shares rate bucket');
assertFileContains('assets/js/form-validation.js', 'dataset.origHtml', 'form validation restores footer spinner label');
assertFileContains('includes/contact-spam-guard.php', 'function coop_public_form_anti_bot_html', 'reusable anti-bot HTML');
assertFileContains('includes/contact-spam-guard.php', 'function coop_public_form_bot_block', 'public form bot block helper');
assertFileContains('admin/security-settings.php', 'turnstile_site_key', 'turnstile keys in security settings');
assertFileContains('includes/config.php', 'challenges.cloudflare.com', 'CSP allows Turnstile');
assertFileContains('admin/includes/admin-header.php', 'messages.php', 'admin messages nav present');
assertFileContains('scripts/drop-hrm-tables-safe.php', 'hrm_internal_messages', 'hrm drop script lists messages table');
assertFileContains('database/install.sql', 'HRM module removed', 'install.sql no longer creates hrm tables');
assertFileContains('notices.php', 'coop_public_download_url($attachRaw)', 'notice detail attachment guarded');
assertFileContains('includes/header.php', 'coop_public_download_url($attachRaw)', 'notice popup attachment guarded');
assertFileContains('admin/notices.php', 'coop_stored_upload_exists($item[\'attachment\'])', 'admin notice list file exists check');
assertFileContains('includes/config.php', 'function coop_resolve_stored_upload_rel', 'stored upload path resolver');
assertFileContains('services.php', 'safe_media_src($service', 'service image src guarded');
assertFileContains('member/check-availability.php', 'coop_client_ip', 'availability rate limit client ip');
assertFileContains('includes/footer.php', 'coop_client_ip', 'visitor counter client ip');
assertFileContains('application-tracker.php', 'coop_public_download_url($app[\'admin_attachment\']', 'tracker attachment href guarded');
assertFileContains('awards.php', 'safe_media_src($award', 'awards image src guarded');
assertFileContains('includes/member-auth.php', 'coop_client_ip', 'member session ip binding');
assertFileContains('index.php', 'safe_versioned_media_src', 'homepage versioned media src');
assertFileContains('includes/header.php', 'safe_versioned_media_src', 'header safe logo src');
assertFileContains('includes/config.php', 'function coop_sanitize_cms_html', 'cms html sanitizer');
assertFileContains('page.php', 'coop_sanitize_cms_html', 'cms page body sanitized');
assertFileContains('news-detail.php', 'coop_sanitize_cms_html', 'news detail body sanitized');
assertFileContains('notices.php', 'coop_render_cms_prose', 'notice detail body sanitized');
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
assertFileContains('includes/config.php', 'script-src-elem', 'CSP3 script-src-elem nonce path');
assertFileContains('includes/config.php', 'coop_csp_ob_filter', 'CSP output filter stamps script nonces');
assertFileContains('includes/config.php', "object-src 'none'", 'CSP object-src none');
assertFileContains('includes/config.php', "getSetting('csp_script_nonce', '1')", 'csp_script_nonce kill-switch');
assertFileContains('includes/config.php', "script-src-attr 'unsafe-inline'", 'CSP keeps onclick via script-src-attr');
assertFileContains('admin/security-settings.php', 'name="csp_script_nonce"', 'admin CSP nonce toggle');
assertFileContains('admin/security-settings.php', 'name="csp_enforce"', 'admin CSP enforce toggle');
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
    'admin/program-dashboard.php',
    'admin/program-occurrences.php',
    'admin/program-registration-desk.php',
    'admin/program-reports-consolidated.php',
    'install.php',
    'member/profile.php',
    'member/tracker.php',
    'member/index.php',
    'member/includes/chrome.php',
    'admin/help-guide.php',
    'admin/dashboard.php',
    'admin/settings.php',
    'admin/messages.php',
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
assertFileContains('includes/header.php', 'class="popup-view-full-notice"', 'notice popup attachment link');
assertFileContains('includes/header.php', 'rel="noopener noreferrer" class="popup-view-full-notice"', 'notice popup link hardened');
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
assertFileContains('admin/includes/admin-header.php', 'class="skip-link"', 'admin skip link');
assertFileContains('admin/includes/admin-header.php', 'id="main-content"', 'admin main landmark id');
assertFileContains('admin/includes/admin-header.php', 'aria-controls="group-', 'admin nav group aria-controls');
assertFileContains('admin/includes/admin-header.php', '<button type="button" class="nav-group-header', 'admin nav group headers are buttons');
assertFileContains('member/includes/chrome.php', 'class="skip-link"', 'member skip link');
assertFileContains('member/includes/chrome.php', 'id="main-content"', 'member main landmark id');
assertFileContains('member/includes/chrome.php', 'mem-nav-apply-toggle', 'member Apply/Services group');
assertFileContains('member/includes/chrome-foot.php', '</main><!-- /#main-content -->', 'member main closed in chrome foot');
assertFileContains('verify.php', '<main class="vp-outer" id="main-content"', 'verify main landmark');
assertFileContains('verify.php', 'role="alert"', 'verify error alerts announced');
assertFileContains('includes/header.php', 'aria-label="<?php echo isEnglish() ? \'English\'', 'EN lang aria-label');
assertFileContains('includes/header.php', 'aria-label="<?php echo isEnglish() ? \'Nepali\'', 'NP lang aria-label');
assertFileContains('application-tracker.php', 'name="sec_tracking_id"', 'tracker phone/email requires tracking id factor');
assertFileContains('application-tracker.php', 'Phone + email alone is not enough', 'tracker help text updated');
assertFileNotContains('application-tracker.php', '7000ram', 'old derivable security code removed');
assertFileContains('api-public-chat.php', 'verifyCSRFToken', 'public chat CSRF');
assertFileContains('api-ai-chat.php', 'verifyCSRFToken', 'AI chat CSRF');
assertFileContains('includes/footer.php', "method=\"post\"", 'live chat form posts with method');
assertFileContains('member/login.php', "checkRateLimit('member_2fa'", 'member 2FA rate limit');
assertFileContains('admin/index.php', "checkRateLimit('admin_2fa'", 'admin 2FA rate limit');
assertFileContains('admin/index.php', 'Superadmin + all other admin roles: mandatory', 'admin 2FA mandatory for all roles including superadmin');
assertFileContains('admin/index.php', "pendingModeEarly !== 'backup_ack'", 'admin backup_ack skips 2FA rate budget');
assertFileContains('member/login.php', "pendingModeEarly !== 'backup_ack'", 'member backup_ack skips 2FA rate budget');
assertFileContains('admin/index.php', 'inputmode="text"', 'admin 2FA field allows backup codes on mobile');
assertFileContains('member/login.php', 'inputmode="text"', 'member 2FA field allows backup codes on mobile');
assertFileContains('admin/index.php', 'twoFaQrImageUrl', 'admin Google Authenticator QR image');
assertFileContains('member/login.php', 'Member portal: Google Authenticator 2FA always mandatory', 'member 2FA always on');
assertFileContains('member/login.php', 'twoFaQrImageUrl', 'member Google Authenticator QR image');
assertFileContains('includes/totp-2fa.php', 'function twoFaQrImageUrl', 'TOTP QR helper');
assertFileContains('includes/totp-2fa.php', 'otpauth://totp/', 'Google Authenticator otpauth URI');
assertFileContains('includes/member-auth.php', "'need_2fa' => true", 'OAuth returns 2FA challenge');
assertFileContains('includes/member-auth.php', 'function memberLoginEligibilityError', 'shared login eligibility gate');
assertFileContains('includes/member-auth.php', 'memberLoginEligibilityError($m, $db)', 'OAuth uses eligibility gate');
assertFileContains('member/oauth.php', 'oauthFinishWithTwoFa', 'OAuth finishes via 2FA');
assertFileContains('member/oauth.php', "pending_approval", 'OAuth maps pending approval errors');
assertFileContains('member/login.php', "'mode' => 'backup_ack'", 'member 2FA backup codes ack step');
assertFileContains('member/login.php', 'memberLoginEligibilityError($m, $db)', 'member 2FA re-checks eligibility');
assertFileContains('admin/index.php', "'mode' => 'backup_ack'", 'admin 2FA backup codes ack step');
assertFileContains('online-kyc.php', "coop_math_challenge_issue('kyc')", 'KYC math challenge issued for forms');
assertFileContains('admin/security-settings.php', 'Superadmin सहित सबै Admin अनिवार्य', 'security settings documents mandatory superadmin 2FA');
assertFileNotContains('admin/security-settings.php', 'name="twofa_admin_required"', 'old admin 2FA toggle removed');
assertFileNotContains('admin/security-settings.php', 'name="twofa_member_required"', 'old member 2FA toggle removed');
assertFileNotContains('admin/security-settings.php', 'Superadmin ऐच्छिक', 'old optional superadmin 2FA policy removed');

assertFileContains('deploy/nginx-security.conf', 'location ^~ /includes/', 'nginx security mirror for includes');
assertFileContains('deploy/nginx-security.conf', 'location ^~ /assets/uploads/', 'nginx mirrors uploads harden');
assertFileContains('deploy/nginx-security.conf', 'sitemap.xml', 'nginx mirrors sitemap rewrite');
assertFileContains('deploy/nginx-security.conf', 'location ^~ /vendor/', 'nginx denies composer vendor');
assertFileContains('deploy/nginx-site.example.conf', 'nginx-security.conf', 'example site includes security conf');
assertFileContains('deploy/README.md', 'nginx-security.conf', 'nginx deploy docs');
assertFileNotContains('includes/notifications.php', 'http://api.sparrowsms.com', 'notifications SMS uses HTTPS');
assertFileContains('member/profile.php', 'minlength="8"', 'member profile password min 8');
assertFileContains('admin/change-password.php', 'minlength="8"', 'admin change password min 8');


// CDN Subresource Integrity (pinned versions)
$chartSri = 'integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g"';
assertFileContains('institutional-profile.php', $chartSri, 'Chart.js SRI public');
assertFileContains('admin/analytics.php', $chartSri, 'Chart.js SRI analytics');
assertFileContains('admin/program-attendance.php', $chartSri, 'Chart.js SRI program-attendance');
assertFileContains('includes/program-attendance-helpers.php', 'recordProgramAttendance', 'program attendance core helper');
assertFileContains('includes/program-tables.php', 'program_occurrences', 'program occurrences table migration');
assertFileContains('admin/program-attendance.php', "attendance_status='VALID'", 'attendance list filters voided rows');
assertFileContains('includes/program-tables.php', 'DROP INDEX uniq_member_program', 'drop legacy attendance unique for void re-record');
assertFileContains('includes/program-attendance-helpers.php', 'programLiveStatsForProgram', 'program live stats helper');
assertFileContains('includes/loan-submit-helper.php', 'function submitLoanApplicationUnified', 'shared loan submit helper');
assertFileContains('loan-apply.php', 'loan-submit-helper.php', 'public loan apply uses shared helper');
assertFileContains('member/loan-apply.php', 'loan-submit-helper.php', 'member loan apply uses shared helper');
assertFileContains('loan-apply.php', 'submitLoanApplicationUnified', 'public loan apply calls unified submit');
assertFileContains('member/loan-apply.php', 'submitLoanApplicationUnified', 'member loan apply calls unified submit');
assertFileContains('member/loan-apply.php', "checkRateLimit('loan_portal_", 'member loan portal rate limit');
assertFileContains('member/loan-apply.php', 'Location: loan-apply.php?submitted=1', 'member loan PRG after success');
assertFileContains('member/appointment.php', "checkRateLimit('appt_portal_", 'member appointment portal rate limit');
assertFileContains('member/appointment.php', 'Location: appointment.php?submitted=1', 'member appointment PRG after success');
assertFileContains('member/grievance.php', "checkRateLimit('grievance_portal_", 'member grievance portal rate limit');
assertFileContains('member/grievance.php', 'Location: grievance.php?submitted=1', 'member grievance PRG after success');
assertFileContains('member/digital-service.php', "checkRateLimit('digital_portal_", 'member digital portal rate limit');
assertFileContains('member/account-apply.php', "checkRateLimit('account_portal_", 'member account portal rate limit');
assertFileNotContains('member-welfare.php', 'Failed to submit claim: ', 'public welfare no exception text leak');
assertFileNotContains('member-welfare.php', 'दाबी दर्ता गर्न सकिएन: ', 'public welfare no Nepali exception text leak');
assertFileContains('includes/appointment-submit-helper.php', '/* Never store unconverted BS as MySQL DATE */', 'appointment date normalize fail-closed');
assertFileContains('includes/appointment-submit-helper.php', 'Past dates are not allowed', 'appointment rejects past dates');
assertFileContains('includes/loan-submit-helper.php', "'failed' => false", 'loan upload fail-closed shape');
assertFileContains('includes/grievance-submit-helper.php', 'allowedCategories', 'grievance category allowlist');
assertFileContains('includes/config.php', "preg_match('/^[a-z0-9_\\-]+$/i', \$folder)", 'uploadFile folder allowlist');
assertFileContains('includes/config.php', 'सानो फाइल राख्नुहोस्।', 'upload error text no ini leak');
assertFileNotContains('includes/config.php', "upload_max_filesize=' . \$up", 'upload error text hides php ini sizes');
assertFileContains('includes/welfare-claims-submit-helper.php', 'UPLOAD_FAILED', 'welfare upload fail-closed');
assertFileContains('includes/config.php', 'function coop_safe_cta_url', 'safe CTA URL helper');
assertFileContains('includes/config.php', 'function coop_safe_webhook_url', 'webhook SSRF guard helper');
assertFileContains('includes/config.php', 'random_bytes(8)', 'tracking id 64-bit entropy');
assertFileContains('admin/settings.php', 'google_client_secret_clear', 'oauth secret clear checkbox');
assertFileNotContains('admin/settings.php', "value=\"<?php echo htmlspecialchars(\$settings['google_client_secret']", 'oauth google secret not echoed in HTML');
assertFileContains('admin/settings.php', "'facebook_url', 'youtube_url', 'twitter_url', 'instagram_url'", 'social URLs sanitized on save');
assertFileContains('includes/header.php', 'coop_safe_cta_url', 'header social links sanitized');
assertFileContains('admin/sliders.php', 'coop_safe_cta_url', 'slider button URL sanitized');
assertFileContains('career-detail.php', '[career-detail apply]', 'career apply logs exceptions');
assertFileNotContains('career-detail.php', '$error = $e->getMessage()', 'career apply no exception echo');
assertFileContains('member/election-vote.php', '[election-vote]', 'election vote logs exceptions');
assertFileContains('admin/about-settings.php', "uploadFile(\$file, 'about'", 'about settings uses uploadFile');
assertFileContains('admin/member-of-year.php', "uploadFile(\$_FILES['photo'], 'member-spotlight'", 'member-of-year uses uploadFile');
assertFileContains('includes/notifications.php', 'coop_safe_webhook_url', 'SMS webhook SSRF guard');
assertFileContains('includes/member-auth.php', "str_starts_with(\$rel, 'member/')", 'memberSafeRedirect member-only paths');
assertFileContains('member/password-reset-request.php', 'Same generic copy as unknown account', 'password reset no sent_to leak');
assertFileContains('includes/grievance-submit-helper.php', 'function submitGrievanceUnified', 'shared grievance submit helper');
assertFileContains('grievance.php', 'grievance-submit-helper.php', 'public grievance uses shared helper');
assertFileContains('member/grievance.php', 'grievance-submit-helper.php', 'member grievance uses shared helper');
assertFileContains('grievance.php', 'submitGrievanceUnified', 'public grievance calls unified submit');
assertFileContains('member/grievance.php', 'submitGrievanceUnified', 'member grievance calls unified submit');
assertFileContains('member/service-request.php', 'submitGrievanceUnified', 'service-request uses grievance helper');
assertFileContains('member/service-request.php', 'submitAppointmentUnified', 'service-request uses appointment helper');
assertFileContains('member/service-request.php', 'member-portal-identity.php', 'service-request uses portal identity SSOT');
assertFileContains('member/service-request.php', 'Please choose both preferred date and time', 'service-request requires schedule (no silent default)');
assertFileNotContains('member/service-request.php', "?: date('Y-m-d')", 'service-request no silent today default');
assertFileNotContains('member/service-request.php', "?: '10:00 AM'", 'service-request no silent 10am default');
assertFileContains('login.php', 'X-Robots-Tag', 'quarantine login stub noindex');
assertFileContains('admin/hrm-dashboard.php', 'X-Robots-Tag', 'quarantine HRM stub noindex');
assertFileContains('scripts/smoke-quarantine-stubs.php', 'X-Robots-Tag', 'quarantine smoke asserts noindex');
assertFileContains('verify.php', 'skip-link', 'verify has skip link');
assertFileContains('includes/coop-date-ui.php', 'function coop_date_input_html', 'lang-aware date UI helper');
assertFileContains('includes/config.php', 'coop-date-ui.php', 'config loads date UI helper');
assertFileContains('member/appointment.php', 'coop_date_input_html', 'member appointment uses lang date UI');
assertFileContains('member/service-request.php', 'coop_date_input_html', 'service-request uses lang date UI');
assertFileContains('appointment.php', 'coop_date_input_html', 'public appointment uses lang date UI');
assertFileContains('member/includes/chrome.php', 'nepali.datepicker.min.css', 'member chrome loads nepali datepicker css');
assertFileContains('member/includes/chrome-foot.php', 'nepaliDatePicker', 'member chrome inits nepali datepicker');

assertFileNotContains('member/index.php', 'LOWER(email)=?', 'dashboard no email soft KYC match');
assertFileNotContains('member/id-card.php', 'LOWER(email)=?', 'id-card no email soft KYC match');
assertFileNotContains('member/certificate.php', 'LOWER(email)=?', 'certificate no email soft KYC match');
assertFileNotContains('member/tracker.php', 'LOWER(email)=?', 'tracker no email soft KYC match');
assertFileNotContains('member/kyc-print.php', 'LOWER(email)=?', 'kyc-print no email soft KYC match');
assertFileContains('contact.php', 'public-form-shell', 'contact form uses form shell');
assertFileContains('includes/appointment-submit-helper.php', 'function submitAppointmentUnified', 'shared appointment submit helper');
assertFileContains('appointment.php', 'appointment-submit-helper.php', 'public appointment uses shared helper');
assertFileContains('member/appointment.php', 'appointment-submit-helper.php', 'member appointment uses shared helper');
assertFileContains('appointment.php', 'submitAppointmentUnified', 'public appointment calls unified submit');
assertFileContains('member/appointment.php', 'submitAppointmentUnified', 'member appointment calls unified submit');
assertFileContains('includes/account-submit-helper.php', 'function submitAccountApplicationUnified', 'shared account submit helper');
assertFileContains('online-account.php', 'account-submit-helper.php', 'public account apply uses shared helper');
assertFileContains('member/account-apply.php', 'account-submit-helper.php', 'member account apply uses shared helper');
assertFileContains('online-account.php', 'submitAccountApplicationUnified', 'public account apply calls unified submit');
assertFileContains('member/account-apply.php', 'submitAccountApplicationUnified', 'member account apply calls unified submit');
assertFileContains('includes/digital-service-submit-helper.php', 'function submitDigitalServiceRequestUnified', 'shared digital service submit helper');
assertFileContains('digital-services.php', 'digital-service-submit-helper.php', 'public digital services uses shared helper');
assertFileContains('member/digital-service.php', 'digital-service-submit-helper.php', 'member digital service uses shared helper');
assertFileContains('digital-services.php', 'submitDigitalServiceRequestUnified', 'public digital services calls unified submit');
assertFileContains('member/digital-service.php', 'submitDigitalServiceRequestUnified', 'member digital service calls unified submit');
assertFileContains('member/apply-frame.php', "'digital' => 'digital-service.php'", 'apply-frame redirects digital to native page');
assertFileNotContains('member/apply-frame.php', "'path' => 'digital-services.php'", 'apply-frame no longer iframes digital-services');
assertFileContains('member/welfare.php', 'member-portal-identity.php', 'member welfare uses portal identity SSOT');
assertFileContains('program-attendance-verify.php', 'program-registration-desk.php', 'staff verify redirects to registration desk');
assertFileContains('admin/includes/admin-ui.php', 'function adminLangT', 'admin lang translation helper');
assertFileContains('includes/admin-menu-control.php', 'ADMIN_MENU_CONTROL_CONFIRM_CODE', 'menu control confirm code constant');
assertFileContains('includes/admin-menu-control.php', 'admin_menu_group_visible', 'menu group visibility helper');
assertFileContains('admin/menu-control.php', "empty(\$_SESSION['is_superadmin'])", 'menu control superadmin only');
assertFileContains('admin/menu-control.php', 'confirm_code', 'menu control requires confirm code');
assertFileContains('admin/includes/admin-header.php', 'admin_menu_page_allowed', 'header enforces hidden menu page gate');
assertFileContains('admin/includes/admin-header.php', 'menu-control.php', 'menu control nav link present');
assertFileContains('admin/includes/admin-header.php', "data-group=\"superadmin\"", 'superadmin tools grouped in one nav group');
assertFileContains('admin/includes/admin-header.php', "empty(\$_SESSION['is_superadmin'])", 'superadmin group gated');
assertFileContains('member/scan.php', 'integrity="sha384-c9d8RFSL+u3exBOJ4Yp3HUJXS4znl9f+z66d1y54ig+ea249SpqR+w1wyvXz/lk+"', 'html5-qrcode SRI');
assertFileContains('online-kyc.php', 'integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="', 'Leaflet JS SRI');
assertFileContains('admin/pages.php', 'integrity="sha384-lo8/CN/iRaTSWve/rcVNU06/qOA1Qn47bB4ENNcUQ7tLVBqPca8yRbxhx5ic7UZM"', 'TinyMCE SRI');
assertFileContains('admin/includes/admin-footer.php', 'integrity="sha384-iRqAtUS5zaxUb29RlrazJxjB/+B6yhysd3tFSeMTcmvAgxeXTVWBk4OlbSJWpthT"', 'CKEditor SRI');
assertFileContains('includes/config.php', 'https://cdn.ckeditor.com', 'CSP allows CKEditor CDN');
assertFileContains('member/login.php', 'alt="Google Authenticator QR"', 'member 2FA QR image');
assertFileContains('admin/index.php', 'alt="Google Authenticator QR"', 'admin 2FA QR image');
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
assertFileContains('gallery.php', 'id="galleryPhotoModalCaption"', 'gallery photo caption below image');
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

// Password policy + PRG + credentials hardening (backend round 3)
assertFileContains('includes/member-auth.php', 'function memberPasswordPolicyError', 'shared member password policy');
assertFileContains('member/profile.php', 'memberPasswordPolicyError', 'profile uses password policy');
assertFileContains('member/login.php', 'memberPasswordPolicyError', 'register uses password policy');
assertFileContains('member/password-reset-request.php', 'memberPasswordPolicyError', 'reset uses password policy');
assertFileContains('contact.php', "Location: contact.php?sent=1", 'contact PRG redirect');
assertFileContains('contact.php', "\$success = !empty(\$_GET['sent'])", 'contact success from GET');
assertFileContains('honor-apply.php', 'honor-apply.php?submitted=1', 'honor PRG redirect');
assertFileContains('honor-apply.php', "!empty(\$_GET['submitted'])", 'honor success from GET');
assertFileContains('admin/credentials.php', 'safe_http_url', 'credentials site_url sanitized');
assertFileContains('admin/credentials.php', "error' => 'Unable to reveal'", 'credentials reveal no exception leak');
assertFileContains('admin/help-center.php', "error_log('[help-center]", 'help-center logs exceptions');
assertFileContains('admin/manage-admins.php', "error_log('[manage-admins]", 'manage-admins logs exceptions');

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
    ['cron-cleanup.php'],
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
