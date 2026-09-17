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
assertFileContains('reports.php', 'public-member-access.php', 'reports member gate helper');
assertFileContains('report-file.php', 'Access denied.', 'report proxy denies locked access');
assertFileContains('report-file.php', 'Access configuration unavailable', 'report proxy fail-closed without access_level');
assertFileContains('institutional-profile-file.php', 'Access denied.', 'IP proxy denies locked access');
assertFileContains('institutional-profile-file.php', 'Access configuration unavailable', 'IP proxy fail-closed without access_level');
assertFileContains('assets/uploads/reports/.htaccess', 'Require all denied', 'reports uploads deny direct HTTP');
assertFileContains('assets/uploads/institutional_profile/.htaccess', 'Require all denied', 'IP uploads deny direct HTTP');
assertFileContains('includes/public-member-access.php', 'COOP_PMA_MAX_FAILS', 'member unlock rate limit');
assertFileContains('includes/public-member-access.php', 'UPPER(TRIM(sadasyata_number))', 'unlock uses SSOT normalize match');
assertFileContains('includes/public-member-access.php', 'coopMemberAccessSafeReturnPath', 'unlock return path allowlist');
assertFileContains('admin/reports.php', 'report-file.php', 'admin reports view via proxy');
assertFileContains('admin/institutional-profile.php', 'institutional-profile-file.php', 'admin IP attachment via proxy');
assertFileContains('includes/member-import-helpers.php', "'soft'", 'CBS import KYM soft-fill only');
assertFileContains('admin/reports.php', "name=\"access_level\"", 'admin reports access_level');
assertFileContains('admin/institutional-profile.php', "'access_level'", 'admin IP persists access_level');
assertFileContains('digital-services.php', 'coop_sanitize_icon_class', 'digital services icon sanitize');
assertFileContains('includes/config.php', 'function coop_new_tracking_id', 'strong tracking id helper');
assertFileContains('includes/config.php', 'COOP_HMAC_LEGACY_SECRET', 'HMAC legacy only via optional local define');
assertFileNotContains('includes/config.php', 'aakash-fallback-secret-2026', 'no public shared HMAC fallback');
assertFileContains('application-tracker.php', 'never auto-loads PII', 'tracker blocks GET auto PII lookup');
assertFileContains('application-tracker.php', 'Disabled: sequential GRV-1', 'tracker legacy numeric id disabled');
assertFileContains('grievance.php', 'tracking_id string only', 'grievance track no sequential id');
assertFileNotContains('grievance.php', 'OR id = ?', 'grievance track no id OR');
assertFileContains('member-welfare.php', 'phone-alone lookup removed', 'welfare track no phone-alone IDOR');
assertFileNotContains('member-welfare.php', 'OR phone = ?', 'welfare track no phone OR');
assertFileContains('includes/config.php', 'SITE_ALLOWED_HOSTS', 'SITE_URL host allowlist support');
assertFileContains('includes/config.php', 'Host-header hardening', 'SITE_URL host sanitization');
assertFileNotContains('includes/config.php', 'new mysqli(', 'unused mysqli second connection removed');
assertFileContains('includes/config.php', 'PDO only (getDB())', 'PDO-only DB access note');
assertFileContains('application-tracker.php', "echo e(basename((string)(\$app['admin_attachment']", 'tracker attachment basename escaped');
assertFileContains('admin/db-setup.php', 'Bootstrap mode skips admin-header CSRF', 'db-setup bootstrap CSRF gate');
assertFileContains('includes/config.php', 'only under assets/uploads', 'deleteFile uploads confinement');
assertFileContains('includes/config.php', 'function coop_request_is_https', 'shared HTTPS detector');
assertFileContains('includes/config.php', 'TRUSTED_PROXIES', 'optional trusted proxy pin');
assertFileContains('includes/config.php', 'Only skip DB-setup wall on local/dev', 'ui_test DB gate restricted');
assertFileContains('committees.php', "coopThemeLinkHtml('assets/css/committees-page.css')", 'committees loads extracted CSS');
assertFileContains('auction.php', "coopThemeLinkHtml('assets/css/auction-page.css')", 'auction loads extracted CSS');
assertFileContains('appointment.php', "coopThemeLinkHtml('assets/css/appointment-page.css')", 'appointment loads extracted CSS');
assertFileContains('admin/welfare-claims.php', 'coop_public_download_url', 'welfare docs use guarded download URL');
assertFileContains('admin/welfare-claims.php', "echo e(\$claim['tracking_id'])", 'welfare tracking_id escaped');
assertFileContains('admin/job-applications.php', "echo e(\$app['phone'])", 'job app phone escaped');
assertFileContains('admin/settings.php', "echo e(\$settings['phone'] ?? '')", 'settings phone escaped');
assertFileContains('member/welfare.php', "coopThemeLinkHtml('assets/css/member-welfare-page.css')", 'member welfare loads extracted CSS');
assertFileContains('member/service-request.php', "coopThemeLinkHtml('assets/css/member-service-request-page.css')", 'member service-request loads extracted CSS');
assertFileContains('member/appointment.php', "coopThemeLinkHtml('assets/css/member-appointment-page.css')", 'member appointment loads extracted CSS');
assertFileContains('member/marketplace.php', "coopThemeLinkHtml('assets/css/member-marketplace-page.css')", 'member marketplace loads extracted CSS');
assertFileContains('member/index.php', "coopThemeLinkHtml('assets/css/member-dashboard-page.css')", 'member dashboard loads extracted CSS');
assertFileContains('member/certificate.php', "coopThemeLinkHtml('assets/css/member-certificate-page.css')", 'member certificate loads extracted CSS');
assertFileContains('member/scan.php', "coopThemeLinkHtml('assets/css/member-scan-page.css')", 'member scan loads extracted CSS');
assertFileContains('member/attend.php', "coopThemeLinkHtml('assets/css/member-attend-page.css')", 'member attend loads extracted CSS');
assertFileContains('index.php', "echo e(\$informationOfficer['phone'])", 'home info officer phone escaped');
assertFileContains('committees.php', "echo e(\$member['phone'])", 'committees phone escaped');
assertFileContains('includes/footer.php', "mailto:<?php echo e(\$email ?? '')", 'footer mailto escaped');
assertFileContains('admin/profile.php', "echo e(\$admin['full_name'])", 'admin profile name escaped');
assertFileContains('member/election-vote.php', "coopThemeLinkHtml('assets/css/member-election-vote-page.css')", 'member election vote loads extracted CSS');
assertFileContains('member/apply-frame.php', "coopThemeLinkHtml('assets/css/member-apply-frame-page.css')", 'member apply-frame loads extracted CSS');
assertFileContains('member/profile.php', "coopThemeLinkHtml('assets/css/member-profile-page.css')", 'member profile loads extracted CSS');
assertFileContains('includes/member-prefill-block.php', "coopThemeLink('assets/css/member-prefill-block.css')", 'prefill block loads extracted CSS');
assertFileContains('admin/program-registration-desk.php', "coopThemeLink('assets/css/admin-program-registration-desk.css')", 'desk loads extracted CSS');
assertFileContains('admin/reports.php', "coopThemeLink('assets/css/admin-reports-page.css')", 'reports loads extracted CSS');
assertFileContains('admin/auctions.php', "echo e(\$pt)", 'auction property type escaped');
assertFileContains('member/password-reset-request.php', "coopThemeLink('assets/css/member-password-reset-page.css')", 'password-reset loads extracted CSS');
assertFileContains('member/password-reset-request.php', 'echo e($error)', 'password-reset error escaped');
assertFileContains('includes/header.php', 'coop_safe_cta_url(getSetting(\'play_store_url\'', 'header play store URL hardened');
assertFileContains('index.php', 'coop_safe_cta_url(getSetting(\'play_store_url\'', 'home play store URL hardened');
assertFileContains('admin/grievances.php', 'echo e($initLetter)', 'grievance initial escaped');
assertFileContains('admin/faqs.php', 'echo e($csrfToken)', 'admin CSRF token escaped');
assertFileContains('contact.php', 'coop_safe_cta_url(getSetting(\'internet_banking_url\'', 'contact banking URL hardened');
assertFileContains('includes/footer.php', "preg_replace('/[^0-9+]/', '', (string)\$whatsappNumber)", 'footer WhatsApp digits-only');
assertFileContains('includes/footer.php', 'coop_safe_cta_url(getSetting(\'developer_url\'', 'footer developer URL hardened');
assertFileContains('member/kyc-print.php', "coopThemeLink('assets/css/member-kyc-print-page.css')", 'kyc-print loads extracted CSS');
assertFileContains('admin/settings.php', "coopThemeLink('assets/css/admin-settings-page.css')", 'settings loads extracted CSS');
assertFileContains('admin/job-applications.php', "echo (int)\$app['id']", 'job app ids cast');
assertFileContains('admin/kyc-applications.php', "echo (int)\$app['id']", 'kyc app ids cast');
assertFileContains('admin/loan-applications.php', "echo (int)\$app['id']", 'loan app ids cast');
assertFileContains('admin/account-applications.php', "echo (int)\$app['id']", 'account app ids cast');
assertFileContains('offline.php', 'assets/css/offline-page.css', 'offline loads extracted CSS');
assertFileContains('500.php', 'assets/css/error-500-page.css', '500 loads extracted CSS');
assertFileContains('admin/site-license-blocked.php', "coopThemeLink('assets/css/admin-site-license-blocked-page.css')", 'license-blocked loads extracted CSS');
assertFileContains('includes/header.php', 'coop_safe_cta_url(getSetting(\'twitter_url\'', 'header twitter URL hardened');
assertFileContains('admin/awards.php', "echo (int)\$a['id']", 'awards ids cast');
assertFileContains('admin/services.php', "echo (int)\$s['id']", 'services ids cast');
assertFileContains('core/init.php', 'core-portal-fatal-page.css', 'core fatal page loads extracted CSS');
assertFileContains('includes/header.php', 'htmlspecialchars($memberPortalHref', 'member portal href escaped');
assertFileContains('member/login.php', "coopThemeLink('assets/css/member-login-page.css')", 'member login loads extracted CSS');
assertFileNotContains('member/login.php', "✅", 'member login messages no checkmark emoji');
assertFileNotContains('member/login.php', "❌", 'member login messages no X emoji');
assertFileNotContains('member/id-card.php', 'idcard-note-success">✅', 'member id-card unlock note no emoji');
assertFileContains('includes/panel-uniform.php', 'data-lucide="circle"', 'panel-uniform Lucide fallback icon');
assertFileNotContains('includes/panel-uniform.php', "htmlspecialchars(\$fa, ENT_QUOTES, 'UTF-8') . ' me-2 coop-page-header-icon'", 'panel-uniform no FA class fallback');
assertFileContains('includes/auction-tables.php', "safeWidenEnumColumn(", 'auction status ENUM via safeWidenEnumColumn');
assertFileContains('includes/ensure-tables.php', "'member_id_cards'", 'member_id_cards referenced in ensure');
assertFileContains('includes/ensure-tables.php', "safeWidenEnumColumn(", 'ensure-tables uses safeWidenEnumColumn');
assertFileContains('includes/header.php', 'data-lucide="file-text"', 'header menu page Lucide fallback');
assertFileContains('includes/config.php', 'setup-gate-page.css', 'setup gate loads extracted CSS');
assertFileContains('admin/members.php', "echo e(\$memEditName)", 'member edit name escaped');
assertFileContains('admin/auctions.php', "echo e(\$key)", 'auction status key escaped');
assertFileContains('includes/header.php', "coopThemeLink('assets/css/public-header-shell.css')", 'header shell CSS extracted');
assertFileContains('member/id-card.php', "coopThemeLinkHtml('assets/css/member-id-card-page.css')", 'id-card CSS extracted');
assertFileContains('admin/print-form.php', "admin-print-form-page.css", 'print-form CSS extracted');
assertFileContains('install.php', 'assets/css/install-page.css', 'install CSS extracted');
assertFileContains('admin/account-applications.php', "echo e(\$v)", 'account status key escaped');
assertFileContains('admin/welfare-claims.php', "echo e(\$label['np'])", 'welfare filter label escaped');
assertFileContains('admin/auctions.php', "echo (int)\$auc['id']", 'auction ids cast');
assertFileContains('contact.php', "htmlspecialchars(\$facebookUrl ?? '#'", 'contact facebook href escaped');
assertFileContains('includes/site-license.php', "site-license-expired-page.css", 'license expired loads extracted CSS');
assertFileContains('admin/downloads.php', "e(ucfirst((string) (\$d['category']", 'downloads category escaped');
assertFileContains('includes/config.php', 'function coop_is_public_tracking_id', 'shared public tracking id validator');
assertFileContains('includes/config.php', 'Identifiers only — definition comes from trusted PHP literals', 'safeAddColumn identifier harden');
assertFileContains('grievance.php', 'coop_is_public_tracking_id', 'grievance uses shared tracking validator');
assertFileContains('member-welfare.php', 'coop_is_public_tracking_id', 'welfare uses shared tracking validator');
assertFileContains('includes/member-auth.php', 'hash_hmac(\'sha256\', $otp', 'OTP stored hashed');
assertFileContains('includes/member-auth.php', 'https://api.sparrowsms.com/v2/sms/', 'Sparrow SMS over HTTPS');
assertFileContains('admin/useful-links.php', 'safe_http_url', 'useful links URL hardened');
assertFileContains('includes/footer.php', 'safe_http_url((string)($link[\'url\']', 'footer useful links escaped');
assertFileContains('member/password-reset-request.php', 'member_otp_send', 'password reset OTP rate limit');
assertFileContains('member/password-reset-request.php', 'If an account matches these details', 'password reset anti-enumeration');

assertFileContains('downloads.php', 'coop_public_download_url($item', 'download file href guarded');
assertFileContains('downloads.php', 'coop_nav_icon_html($fileIcon', 'downloads file type icons Lucide helper');
assertFileNotContains('downloads.php', '<i class="<?php echo htmlspecialchars(coop_sanitize_icon_class($fileIcon)', 'downloads no raw FA i-tag');
assertFileContains('tracker-id-card.php', "data-lucide=\\'user\\'", 'tracker id-card photo fallback Lucide');
assertFileNotContains('tracker-id-card.php', 'fas fa-user', 'tracker id-card no FA user fallback');
assertFileNotContains('includes/member-auth.php', "createMemberNotification(\$memberId, '✅", 'member-auth approve notif no emoji');
assertFileNotContains('includes/member-auth.php', "createMemberNotification(\$memberId, '❌", 'member-auth reject notif no emoji');
assertFileContains('includes/member-auth.php', '"{$svc} — {$sText}"', 'member status notif title text-only');
assertFileContains('assets/css/public-shell-polish.css', '.download-card .download-icon .lucide-icon', 'downloads Lucide icon sizing');
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
assertFileContains('attend.php', 'member/attend.php', 'legacy attend redirects to member portal');
assertFileContains('attend.php', 'qr_token', 'legacy attend maps token to qr_token');
assertFileContains('attend.php', '301', 'legacy attend uses permanent redirect');
assertFileContains('includes/program-tables.php', "'registration_desk' => 'Registration desk'", 'attendance source label covers desk');
assertFileContains('includes/program-tables.php', "'member_portal_instant' => 'Portal QR (instant)'", 'attendance source label covers instant');
assertFileContains('admin/includes/admin-header.php', 'उपस्थिति / Pre-reg', 'program nav clarifies attendance vs reports');
assertFileContains('admin/includes/admin-header.php', 'समेकित रिपोर्ट', 'program report nav has Nepali labels');
assertFileContains('admin/programs.php', 'attendance_* ले जित्छ', 'program form documents window precedence');
assertFileContains('member/scan.php', 'Instant कार्यक्रममा', 'scan copy explains Instant vs approve');
assertFileContains('cooperative-programs.php', 'coop_public_form_bot_block', 'program prereg bot guard');
assertFileContains('cooperative-programs.php', 'coop_public_form_anti_bot_html', 'program prereg anti-bot UI');
assertFileContains('cooperative-programs.php', "checkRateLimit('program_prereg_public'", 'program prereg rate limit');
assertFileContains('verify.php', "coop_public_form_bot_block(\$_POST, 'prog_prereg'", 'verify prereg POST bot guard');
assertFileContains('verify.php', "checkRateLimit('program_prereg_public'", 'verify prereg shares rate bucket');
assertFileContains('assets/js/form-validation.js', 'dataset.origHtml', 'form validation restores footer spinner label');
assertFileContains('assets/js/form-validation.js', 'prefers-reduced-motion', 'form validation respects reduced motion scroll');
assertFileContains('assets/js/coop-mobile.js', 'function prefersReducedMotion', 'coop-mobile reduced-motion helper');
assertFileContains('assets/js/coop-mobile.js', 'form.coop-form-sticky', 'coop-mobile sticky submit selector');
assertFileContains('contact.php', 'coop-form-sticky', 'contact sticky submit class');
assertFileContains('loan-apply.php', 'coop-form-sticky', 'loan sticky submit class');
assertFileContains('assets/css/final-ui-polish.css', 'Phase 4 safe UX', 'final polish phase 4 present');
assertFileContains('assets/css/final-ui-polish.css', '@media print', 'print chrome hide rules');
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
assertFileContains('includes/member-auth.php', 'memberCheckRateLimit($email)', 'memberLogin checks per-email rate limit');
assertFileContains('includes/member-auth.php', 'memberRecordFailedLogin($email)', 'memberLogin records failed attempts');
assertFileContains('includes/member-auth.php', 'memberClearRateLimit($email)', 'memberLogin clears rate limit on success');
assertFileContains('includes/member-auth.php', "'code' => 'rate_limited'", 'memberLogin rate limit returns stable code');
assertFileContains('member/login.php', "=== 'rate_limited'", 'login skips DB attempt bump while session-locked');
assertFileContains('includes/footer.php', "getAttribute('data-submitting') !== '1'", 'footer spinner skips when form-validation owns busy');
assertFileNotContains('includes/member-auth.php', 'if (false && $expectedUA', 'dead UA fingerprint branch removed');
assertFileNotContains('assets/css/app-public.css', '#0d5a1c', 'rates header no hardcoded green middle stop');
assertFileContains('assets/css/global-theme.php', 'match rates/notices brand headers', 'digital cards forced to solid brand headers');
assertFileContains('assets/css/final-ui-polish.css', '--coop-section-title: var(--primary-ink', 'section titles use WCAG primary-ink');
assertFileContains('assets/css/final-ui-polish.css', '--coop-nav-idle-text: var(--primary-ink', 'idle nav uses WCAG primary-ink');
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
assertFileContains('admin/member-success-stories.php', "uploadFile(\$_FILES['photo'], \$uploadSub", 'member success stories uses uploadFile');
assertFileContains('admin/member-success-stories.php', 'verifyCSRFToken', 'member success stories CSRF');
assertFileContains('includes/member-success-stories-tables.php', 'member_success_stories', 'success stories table helper');
assertFileContains('vision-mission.php', 'vision_content', 'vision-mission dedicated page');
assertFileContains('why-choose.php', 'why_choose_features', 'why-choose dedicated page');
assertFileContains('includes/header.php', 'vision-mission.php', 'nav vision dedicated page');
assertFileContains('includes/header.php', 'why-choose.php', 'nav why-choose dedicated page');
assertFileContains('success-stories.php', 'fetchActiveMemberSuccessStories', 'success stories dedicated page');
assertFileContains('includes/ai-chat-instant.php', 'chairman-message.php', 'AI chat links chairman dedicated page');
assertFileContains('includes/ai-chat-instant.php', 'ceo-message.php', 'AI chat links ceo dedicated page');
assertFileNotContains('includes/ai-chat-instant.php', "'#chairman'", 'AI chat no stale about#chairman');
assertFileContains('about.php', "h === '#ceo' || h === '#ceo-message'", 'about redirects #ceo hash');
assertFileContains('includes/header.php', 'success-stories.php', 'nav about dropdown success stories page');
assertFileContains('includes/header.php', 'chairman-message.php', 'nav chairman dedicated page');
assertFileContains('includes/header.php', 'ceo-message.php', 'nav ceo dedicated page');
assertFileContains('includes/leadership-message-helpers.php', 'coop_load_leadership_messages', 'leadership helper present');
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
assertFileContains('cooperative-programs.php', 'type="submit" class="btn btn-sm btn-primary coop-touch-cta"', 'program prereg submit typed');
assertFileContains('admin/help-guide.php', 'id="hgSearch"', 'help-guide search field');
assertFileContains('admin/help-guide.php', 'autocomplete="off"', 'help-guide search autocomplete off');
assertFileContains('admin/print-form.php', 'type="button" onclick="history.back()"', 'print-form back typed');
assertFileContains('includes/satisfaction-widget.php', 'type="button" class="satisfaction-toggle"', 'satisfaction toggle typed');
assertFileContains('includes/satisfaction-widget.php', 'coop_nav_icon_html(', 'satisfaction widget link icons Lucide helper');
assertFileNotContains('includes/satisfaction-widget.php', '<i class="<?php echo htmlspecialchars(trim((string)($link[\'icon\']', 'satisfaction widget no raw FA i-tag');
assertFileContains('team.php', 'coop_nav_icon_html((string)($card[\'badge_icon\']', 'team officer badge Lucide helper');
assertFileNotContains('team.php', '<i class="<?php echo e($card[\'badge_icon\'])', 'team officer badge no raw FA i-tag');
assertFileContains('assets/css/satisfaction-widget.css', '.satisfaction-link-item .lucide-icon', 'satisfaction widget Lucide sizing');
assertFileContains('assets/css/final-ui-polish.css', '.officers-section .officer-badge .lucide-icon', 'officer badge Lucide sizing');
assertFileContains('includes/footer.php', 'type="button" id="uiTestClose"', 'ui-test panel buttons typed');
assertFileContains('contact.php', 'no-univ-phone', 'contact phone skips mobile-only validation');
assertFileContains('assets/js/form-validation.js', 'no-univ-phone', 'form-validation respects no-univ-phone');
assertFileContains('assets/js/form-validation.js', "aria-invalid", 'form-validation sets aria-invalid');
assertFileContains('assets/js/main.js', 'function coopScrollBehavior', 'main.js reduced-motion scroll helper');
assertFileContains('member/includes/chrome-foot.php', 'form-validation.js', 'member chrome loads form-validation');
assertFileContains('member/includes/chrome-foot.php', 'aria-expanded', 'member More drawer aria-expanded');
assertFileContains('includes/footer.php', 'prefers-reduced-motion: reduce', 'footer AOS/scroll-reveal respects reduce');
assertFileContains('includes/header.php', 'Close menu', 'mobile menu close aria-label');

assertFileContains('member/service-request.php', 'coop-form-sticky', 'member service-request sticky submit');
assertFileContains('online-kyc.php', 'public-form-shell', 'kyc uses public form shell');
assertFileContains('assets/js/scroll-accessibility.js', 'onGuideKeydown', 'SA permission guide focus trap / Escape');
assertFileContains('member/includes/chrome.php', 'aria-label="<?php echo $_t(\'सूचनाहरू\'', 'member bell aria-label');

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


assertFileContains('online-kyc.php', "echo e(\$branch['name'])", 'online-kyc branch name escaped');
assertFileContains('online-account.php', "echo e(\$branch['name'])", 'online-account branch name escaped');
assertFileContains('admin/gallery.php', "echo e(\$flash['message'])", 'gallery flash message escaped');
assertFileContains('admin/settings.php', "echo e(\$card['title'])", 'settings card title escaped');
assertFileContains('sahakari-patro.php', "echo e(\$nk['name'])", 'patro nakshatra name escaped');
assertFileContains('sahakari-patro.php', "echo e(\$gr['fal'])", 'patro rashifal escaped');
assertFileContains('member/tracker.php', "coop_sanitize_icon_class(\$app['service_icon']", 'member tracker service icon sanitized');
assertFileContains('admin/members.php', "coop_sanitize_icon_class(\$app['service_icon']", 'admin members service icon sanitized');
assertFileContains('admin/db-setup.php', "coopThemeLink('assets/css/admin-db-setup-page.css')", 'db-setup CSS extracted');
assertFileContains('exchange-rate.php', "echo e(\$r['buy'])", 'exchange buy rate escaped');
assertFileContains('member/includes/chrome.php', "htmlspecialchars(\$_siteUrl, ENT_QUOTES, 'UTF-8')", 'member chrome siteUrl escaped in attrs');
assertFileContains('news.php', "echo (int)\$item['id']", 'news detail id cast');


assertFileContains('_bootstrap.php', "define('PORTAL', 'public')", 'public bootstrap sets PORTAL');
assertFileContains('_bootstrap.php', 'boot-shared.php', 'public bootstrap uses boot-shared SSOT');
assertFileContains('includes/boot-shared.php', 'function coop_require_boot_shared', 'boot-shared helper exists');
assertFileContains('includes/boot-shared.php', "includes/auth-roles.php", 'boot-shared loads auth-roles');
assertFileContains('_bootstrap.php', 'site_license_public_guard', 'public bootstrap license guard');
assertFileContains('includes/auth-roles.php', 'function coop_widen_admin_role_enum', 'additive admin role ENUM widen helper');
assertFileContains('includes/auth-roles.php', 'function admin_canonical_db_role', 'canonical DB role helper');
assertFileContains('admin/includes/ensure-admin-tables.php', "ENUM('superadmin','super_admin','admin','staff','editor')", 'ensure-admin role ENUM aligned with install.sql');
assertFileContains('admin/db-setup.php', "ENUM('superadmin','super_admin','admin','staff','editor')", 'db-setup core tables role ENUM aligned');
assertFileContains('admin/includes/ensure-admin-tables.php', 'v16-reports-ip-access-level-2026', 'admin schema lock bumped for reports/IP access_level');
assertFileContains('admin/reports.php', "safeAddColumn(\$__rptDb, 'reports', 'access_level'", 'admin reports ensures access_level column');
assertFileNotContains('admin/reports.php', 'Column missing mid-migrate', 'reports no longer silently drop access_level');
assertFileContains('includes/auth-roles.php', 'function coop_normalize_admin_role_aliases', 'alias-only role row normalize helper');
assertFileContains('includes/auth-roles.php', "SET `role` = 'super_admin' WHERE `role` = 'superadmin'", 'role normalize is alias-only UPDATE');
assertFileContains('includes/auth-roles.php', 'admin_canonical_db_role($role)', 'set_admin_session canonicalizes role alias');
assertFileContains('admin/index.php', 'coop_normalize_admin_role_aliases($db)', '2FA login normalizes role aliases');
assertFileContains('includes/config.php', 'function coop_canonicalize_icon_db_rows', 'safe icon DB FA spelling canonicalize');
assertFileContains('scripts/inventory-icon-db-canonicalize.php', 'NOT FA→Lucide rewrite', 'icon DB tool documents no Lucide rewrite');
assertFileContains('scripts/extract-app-css-section.py', 'SHADOW EXTRACT', 'app-* section shadow extract script');
assertFileContains('scripts/extract-app-css-section.py', '--verify', 'shadow extract verify mode');
assertFileContains('scripts/extract-app-css-section.py', 'SECOND_PR', 'shadow extract second-wave mid-size set');
assertFileContains('scripts/extract-app-css-section.py', 'THIRD_PR', 'shadow extract third-wave ≤110KB set');
assertFileContains('scripts/extract-app-css-section.py', '--all-third-pr', 'shadow extract third-wave flag');
assertFileContains('scripts/inventory-app-css.py', 'extract-app-css-section.py', 'app-* inventory documents shadow extract');
assertFileContains('scripts/inventory-app-css.py', '--all-third-pr', 'inventory documents third-wave shadow extract');
assertFileContains('includes/config.php', "['help_topics', 'icon', 'fas fa-question-circle']", 'icon canonicalize covers help_topics');
assertFileContains('includes/config.php', "['important_links', 'icon', 'fas fa-link']", 'icon canonicalize covers important_links');
assertFileContains('includes/welfare-claim-types.php', "DEFAULT 'fas fa-gift'", 'welfare schema default uses fas spelling');
assertFileContains('database/install.sql', "DEFAULT 'fas fa-gift'", 'install.sql welfare icon default fas spelling');
assertFileContains('scripts/run-migration-safe.php', 'install.sql', 'CLI safe migration uses install.sql');
assertFileContains('scripts/run-migration-safe.php', 'DROP', 'CLI migration skips DROP/TRUNCATE');
assertFileContains('scripts/run-migration-safe.php', '--yes', 'CLI migration requires --yes');
assertFileContains('scripts/run-migration-safe.php', 'coop_record_schema_migration', 'CLI migration records schema version');
assertFileContains('scripts/deploy-pull-safe.sh', 'run-migration-safe.php --yes', 'deploy-pull runs safe migration');
assertFileContains('admin/run-migration.php', 'coop_canonicalize_icon_db_rows', 'migration applies FA icon spelling canonicalize');
assertFileContains('admin/index.php', 'coop_normalize_admin_role_aliases', 'login normalizes role aliases');
assertFileContains('admin/index.php', 'Alias-only role spelling (same as password login', '2FA login documents alias-only normalize');
assertFileContains('includes/schema-migrations.php', 'function coop_record_schema_migration', 'versioned migration ledger helper');
assertFileContains('admin/run-migration.php', 'coop_record_schema_migration', 'migration runner records schema version');
assertFileContains('admin/run-migration.php', 'coop_normalize_admin_role_aliases', 'migration runner normalizes role aliases');
assertFileContains('admin/run-migration.php', 'schema_migrations', 'migration UI mentions schema_migrations');
assertFileContains('scripts/inventory-app-css.py', '--write', 'app-* inventory can write split plan JSON');
assertFileContains('scripts/inventory-app-css.py', 'thin import shim', 'inventory documents future split shim');
assertFileContains('includes/header.php', 'data-lucide="download"', 'header downloads icon Lucide');
assertFileContains('includes/header.php', 'data-lucide="mail"', 'header contact icon Lucide');
assertFileContains('includes/theme-assets.php', 'FROZEN: Do not rewrite app-public', 'app-* panels frozen for polish');
assertFileContains('core/helpers.php', "'fa-exchange-alt'", 'FA→Lucide map has exchange-alt');


assertFileContains('admin/includes/admin-header.php', "define('PORTAL', 'admin')", 'admin-header sets PORTAL');
assertFileContains('admin/includes/admin-header.php', 'boot-shared.php', 'admin-header uses boot-shared SSOT');
assertFileContains('admin/manage-admins.php', 'admin_canonical_db_role', 'manage-admins canonicalizes role on create');
assertFileContains('appointment.php', "echo e(\$val)", 'appointment purpose value escaped');
assertFileContains('auction.php', 'echo (int)$aId', 'auction bid id cast');
assertFileContains('digital-services.php', "echo e(\$key)", 'digital-services type key escaped');
assertFileContains('includes/header.php', 'data-lucide="bell-off"', 'header empty notices Lucide');
assertFileContains('includes/header.php', 'data-lucide="menu"', 'header hamburger Lucide');


assertFileContains('core/init.php', 'coop_require_boot_shared', 'core/init uses boot-shared SSOT');
assertFileContains('admin/run-migration.php', 'coop_widen_admin_role_enum', 'migration runner widens role ENUM');
assertFileContains('admin/index.php', 'set_admin_session($user)', 'admin 2FA login uses set_admin_session');
assertFileContains('admin/index.php', 'admin_canonical_db_role', 'local debug login canonical role');
assertFileContains('includes/footer.php', 'data-lucide="map-pin"', 'footer contact map Lucide');
assertFileContains('includes/footer.php', 'data-lucide="mail"', 'footer email Lucide');
assertFileContains('includes/footer.php', "setAttribute('data-lucide', name)", 'dark mode toggles Lucide icon');
assertFileContains('assets/css/app-public.css', 'FROZEN PANEL BASE', 'app-public freeze banner');
assertFileContains('assets/css/app-admin.css', 'FROZEN PANEL BASE', 'app-admin freeze banner');
assertFileContains('scripts/build-css-late-bundles.py', 'Never include app-public', 'late-bundle build excludes app-*');


assertFileContains('admin/includes/admin-page-boot.php', 'ADMIN_PAGE_BOOT_LOADED', 'thin admin page boot exists');
assertFileContains('admin/includes/admin-page-boot.php', 'coop_require_boot_shared', 'thin boot uses boot-shared');
assertFileContains('admin/faqs.php', 'admin-page-boot.php', 'faqs migrated to thin admin boot');
assertFileContains('admin/about-settings.php', 'admin-page-boot.php', 'about-settings migrated to thin admin boot');
assertFileContains('admin/awards.php', 'admin-page-boot.php', 'awards migrated to thin admin boot');
assertFileContains('admin/help-center.php', 'admin-page-boot.php', 'help-center migrated to thin admin boot');
assertFileContains('admin/ai-settings.php', 'admin-page-boot.php', 'ai-settings migrated to thin admin boot');
assertFileContains('admin/grievances.php', 'admin-page-boot.php', 'grievances migrated to thin admin boot');
assertFileContains('includes/config.php', 'function coop_nav_icon_html', 'nav icon Lucide renderer');
assertFileContains('includes/header.php', 'coop_nav_icon_html(', 'header uses nav icon Lucide helper');
assertFileNotContains('admin/faqs.php', "require_once '../includes/config.php'", 'faqs no raw config require');


assertFileContains('admin/app-features.php', 'admin-page-boot.php', 'app-features thin boot');
assertFileContains('admin/services.php', 'admin-page-boot.php', 'services thin boot');
assertFileContains('admin/sliders.php', 'admin-page-boot.php', 'sliders thin boot');
assertFileContains('admin/backup-restore.php', 'admin-page-boot.php', 'backup-restore thin boot');
assertFileContains('admin/security-settings.php', 'admin-page-boot.php', 'security-settings thin boot');
assertFileContains('admin/menu-control.php', 'admin-page-boot.php', 'menu-control thin boot');
assertFileContains('admin/notification-settings.php', 'admin-page-boot.php', 'notification-settings thin boot');
assertFileContains('admin/institutional-profile.php', 'admin-page-boot.php', 'institutional-profile thin boot');
assertFileContains('admin/sahakari-calendar-events.php', 'admin-page-boot.php', 'calendar-events thin boot');
assertFileContains('admin/member-activities.php', 'admin-page-boot.php', 'member-activities thin boot');


assertFileContains('admin/useful-links.php', 'admin-page-boot.php', 'useful-links thin boot');
assertFileContains('admin/why-choose.php', 'admin-page-boot.php', 'why-choose thin boot');


assertFileContains('admin/print-form.php', 'admin-page-boot.php', 'print-form thin boot');
assertFileContains('admin/members.php', 'admin-page-boot.php', 'members thin boot');
assertFileContains('admin/member-import.php', 'ADMIN_PAGE_BOOT_SKIP_LOGIN', 'member-import keeps AJAX 401 path');
assertFileContains('admin/member-import.php', 'admin-page-boot.php', 'member-import thin boot');
assertFileContains('admin/member-import.php', 'value="update" checked', 'member import defaults to update/replace');
assertFileContains('admin/program-attendance.php', 'admin-page-boot.php', 'program-attendance export thin boot');
assertFileContains('admin/site-license-blocked.php', 'admin-page-boot.php', 'license-blocked thin boot');
assertFileContains('admin/kyc-import-sample.php', 'admin-page-boot.php', 'kyc-import-sample thin boot');
assertFileContains('admin/member-import-sample.php', 'admin-page-boot.php', 'member-import-sample thin boot');
assertFileContains('admin/member-import-sample.php', "'member_id'", 'sample leads with member_id SSOT column');
assertFileContains('includes/member-import-helpers.php', "string \$mode = 'update'", 'import createJob default update');
assertFileContains('includes/member-import-helpers.php', 'memberImportIsValidContact', 'import validates compulsory contact');
assertFileContains('includes/member-import-helpers.php', 'Updated by Member ID', 're-import replaces by Member ID');
assertFileContains('tracker-id-card.php', "htmlspecialchars(\$siteUrl, ENT_QUOTES, 'UTF-8')", 'tracker id-card siteUrl escaped');
assertFileContains('admin/includes/admin-page-boot.php', 'ADMIN_PAGE_BOOT_SKIP_LOGIN', 'thin boot supports login skip for AJAX');


assertFileContains('admin/logout.php', 'admin-page-boot.php', 'logout uses thin boot');
assertFileContains('admin/logout.php', 'ADMIN_PAGE_BOOT_SKIP_LOGIN', 'logout skips login require');
assertFileContains('admin/index.php', 'ADMIN_PAGE_BOOT_SKIP_LOGIN', 'admin login skips requireAdminLogin');
assertFileContains('admin/index.php', 'admin-page-boot.php', 'admin login uses thin page boot');
assertFileContains('admin/dashboard.php', "/_bootstrap.php", 'dashboard keeps fatal-handler bootstrap');
assertFileContains('admin/_bootstrap.php', 'coop_require_boot_shared', 'admin _bootstrap aligns boot-shared');
assertFileContains('admin/_bootstrap.php', 'core/init.php', 'admin _bootstrap keeps core/init');
assertFileContains('admin/db-setup.php', 'boot-shared.php', 'db-setup loads boot-shared in bootstrap path');
assertFileContains('admin/db-setup.php', 'admin-page-boot.php', 'db-setup normal mode thin boot');
assertFileContains('admin/dashboard.php', "htmlspecialchars((string)\$credsError, ENT_QUOTES, 'UTF-8')", 'dashboard credsError escaped');
assertFileContains('admin/dashboard.php', 'data-lucide="gauge"', 'dashboard chrome Lucide');
assertFileContains('admin/dashboard.php', "'icon' => 'users'", 'dashboard stat cards lucide icon names');
assertFileContains('auction.php', 'data-lucide="gavel"', 'auction content Lucide');
assertFileContains('auction.php', 'data-lucide="filter"', 'auction filter Lucide');
assertFileNotContains('auction.php', 'fas fa-', 'auction no FA icon classes');
assertFileContains('application-tracker.php', 'data-lucide="chart-no-axes-combined"', 'tracker search Lucide');
assertFileContains('application-tracker.php', 'coop_nav_icon_html(', 'tracker type icons via Lucide helper');
assertFileNotContains('application-tracker.php', '<i class="fas fa-', 'tracker no static FA i-tags');
assertFileContains('index.php', 'data-lucide=', 'home page Lucide icons');
assertFileNotContains('index.php', '<i class="fas fa-', 'home no static FA i-tags');
assertFileContains('online-kyc.php', 'data-lucide="user-plus"', 'online-kyc success Lucide');
assertFileContains('member/attend.php', 'data-lucide=', 'member attend Lucide');
assertFileNotContains('member/attend.php', '<i class="fas fa-', 'member attend no static FA i-tags');
assertFileContains('career.php', 'data-lucide=', 'career Lucide');
assertFileContains('admin/committees.php', 'coop_nav_icon_html(', 'committees icons via Lucide helper');
assertFileContains('services.php', 'coop_nav_icon_html(', 'services category icons Lucide helper');
assertFileContains('admin/kyc-applications.php', 'data-lucide=', 'kyc-applications Lucide');
assertFileNotContains('admin/kyc-applications.php', '<i class="fas fa-', 'kyc-applications no static FA i-tags');
assertFileContains('install.php', 'data-lucide="sprout"', 'install wizard Lucide brand');
assertFileNotContains('install.php', '<i class="fas fa-', 'install no static FA i-tags');
assertFileContains('admin/settings.php', 'data-lucide=', 'settings Lucide');
assertFileContains('admin/manage-admins.php', 'data-lucide="eye"', 'manage-admins eye Lucide');
assertFileContains('admin/assets/admin.js', "setAttribute('data-lucide'", 'togglePwd supports Lucide');
assertFileContains('admin/notices.php', 'fileIconMeta', 'notices attachment icons Lucide meta');
assertFileContains('admin/loan-applications.php', 'data-lucide=', 'loan-applications Lucide');
assertFileContains('admin/members.php', 'data-lucide=', 'members Lucide');
assertFileContains('auction.php', "htmlspecialchars(rtrim(SITE_URL", 'auction photo/doc SITE_URL escaped');
assertFileContains('includes/header.php', 'json_encode((string)$siteName', 'header CSS site name JSON-safe');
assertFileContains('includes/header.php', "htmlspecialchars(rtrim(SITE_URL", 'header mobile logo URL escaped');
assertFileContains('admin/login.php', 'ADMIN_URL', 'login redirect prefers ADMIN_URL');
assertFileContains('admin/db-setup.php', 'data-lucide="ban"', 'db-setup alert Lucide');
assertFileContains('admin/db-setup.php', 'data-lucide="database"', 'db-setup status Lucide');
assertFileContains('admin/appointments.php', "echo (int)\$counts['pending']", 'appointment pending count cast');
assertFileContains('admin/notification-settings.php', "coop_sanitize_icon_class(\$info['icon']", 'notify settings icon sanitized');
assertFileContains('admin/notification-settings.php', "echo e(\$info['label'])", 'notify settings label escaped');
assertFileContains('member/index.php', "htmlspecialchars(\$siteUrl, ENT_QUOTES, 'UTF-8')", 'member index ajax siteUrl escaped');
assertFileContains('member/notifications.php', "htmlspecialchars(\$siteUrl, ENT_QUOTES, 'UTF-8')", 'member notifications ajax siteUrl escaped');
assertFileContains('admin/help-guide.php', 'data-lucide="list"', 'help-guide TOC Lucide');
assertFileContains('admin/institutional-profile.php', "echo e(\$p['share_capital_percent'])", 'institutional percent escaped');


assertFileContains('admin/awards.php', "echo (int)\$a['display_order']", 'awards display_order cast');
assertFileContains('admin/faqs.php', "echo (int)\$f['is_active']", 'faqs is_active cast');
assertFileContains('admin/news.php', "echo (int)\$n['is_active']", 'news is_active cast');
assertFileContains('admin/system-info.php', 'admin-page-boot.php', 'system-info uses thin admin page boot');
assertFileContains('admin/site-setup.php', 'admin-page-boot.php', 'site-setup thin boot');
assertFileContains('includes/footer.php', 'data-lucide="banknote"', 'footer quick-help loan Lucide');
assertFileContains('includes/footer.php', 'data-lucide="map-pin"', 'footer quick-help branch Lucide');
assertFileContains('application-tracker.php', "htmlspecialchars(\$publicIdCardLink, ENT_QUOTES, 'UTF-8')", 'tracker id-card link ENT_QUOTES');
assertFileContains('404.php', "htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8')", '404 SITE_URL escaped');
assertFileContains('contact.php', "htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8')", 'contact SITE_URL escaped');
assertFileContains('includes/header.php', "htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8')", 'header SITE_URL escaped');
assertFileContains('admin/includes/admin-footer.php', 'data-lucide="history"', 'admin footer history Lucide');
assertFileContains('admin/help-guide.php', 'data-lucide="rocket"', 'help-guide rocket Lucide');
assertFileContains('admin/help-guide.php', 'data-lucide="gauge"', 'help-guide dashboard Lucide');
assertFileContains('admin/help-guide.php', 'data-lucide="key"', 'help-guide login Lucide');
assertFileContains('attend.php', 'member/attend.php', 'legacy attend redirects to member portal');
assertFileNotContains('attend.php', 'coop_public_form_bot_block', 'legacy attend no longer hosts form bot guard');
assertFileContains('verify.php', "htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8')", 'verify SITE_URL escaped');
assertFileContains('admin/appointments.php', 'admin-page-boot.php', 'appointments thin boot');
assertFileContains('admin/careers.php', 'admin-page-boot.php', 'careers thin boot');
assertFileContains('admin/committees.php', 'admin-page-boot.php', 'committees thin boot');
assertFileContains('admin/news.php', 'admin-page-boot.php', 'news thin boot');
assertFileContains('admin/kyc-applications.php', 'admin-page-boot.php', 'kyc-applications thin boot');
assertFileContains('admin/settings.php', 'admin-page-boot.php', 'settings thin boot');
assertFileContains('admin/welfare-claims.php', 'admin-page-boot.php', 'welfare-claims thin boot');
assertFileContains('admin/reports.php', 'admin-page-boot.php', 'reports thin boot');
assertFileContains('admin/change-password.php', 'admin-page-boot.php', 'change-password thin boot');
assertFileContains('admin/honor-applications.php', "htmlspecialchars(rtrim(SITE_URL", 'honor attachment URL escaped');
assertFileContains('admin/pages.php', "htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8')", 'pages preview SITE_URL escaped');
assertFileContains('admin/partner-facilities.php', 'echo (int)$uid', 'partner facility id cast');
assertFileContains('admin/committees.php', 'echo (int)$showNav', 'committees showNav cast');
assertFileContains('member/includes/chrome.php', 'data-lucide="house"', 'member chrome dashboard Lucide');
assertFileContains('member/includes/chrome.php', 'data-lucide="bell-off"', 'member chrome push bell Lucide');
assertFileContains('member/includes/chrome.php', "setAttribute('data-lucide'", 'member chrome push toggle Lucide-aware');
assertFileContains('includes/coop-date-ui.php', 'data-lucide="calendar"', 'coop date UI Lucide calendar');
assertFileContains('includes/information-room-viewer.php', 'data-lucide="arrow-left"', 'IR viewer back Lucide');
assertFileContains('includes/member-marketplace-public-page.php', 'coop_nav_icon_html', 'marketplace cards use nav icon helper');
assertFileContains('includes/config.php', "data-lucide=\"' . htmlspecialchars(\$icon", 'displayFlash Lucide icons');
assertFileContains('includes/config.php', 'rel="noopener noreferrer"', 'adminAttachmentHtml noopener');
assertFileNotContains('online-kyc.php', 'province-select">c-permanent', 'online-kyc province select not corrupted');
assertFileContains('online-kyc.php', 'for="kyc_permanent_province"', 'online-kyc permanent province label for=');
assertFileContains('online-kyc.php', 'for="kyc_temporary_tole"', 'online-kyc temporary tole label for=');
assertFileContains('admin/program-registration-desk.php', 'for="deskMemberInput"', 'desk member label for=');
assertFileContains('admin/loan-applications.php', 'for="qf_status"', 'loan filter status label for=');
assertFileContains('member/welfare.php', 'for="wlfClaimType"', 'welfare claim type label for=');
assertFileContains('includes/config.php', 'Dual-read SSOT', 'icon dual-read SSOT comment');
assertFileContains('includes/config.php', 'Brand packs (`fab`', 'brand FA retained comment');
assertFileContains('includes/config.php', 'Formal migrations framework', 'migrations bang deferred note');
assertFileContains('includes/config.php', 'function safeAddIndex', 'safeAddIndex helper');
assertFileContains('includes/config.php', 'function safeWidenEnumColumn', 'safeWidenEnumColumn helper');
assertFileContains('includes/auth-roles.php', 'safeWidenEnumColumn', 'role widen prefers safeWidenEnumColumn');
assertFileContains('admin/includes/ensure-admin-tables.php', "safeAddColumn(\$db, 'institutional_profile'", 'ensure uses safeAddColumn');
assertFileContains('scripts/inventory-app-css.py', 'do not rewrite', 'app-* inventory script');
assertFileContains('admin/index.php', "safeAddColumn(\$db, 'admin_users', 'twofa_enabled'", 'admin login 2FA cols via safeAddColumn');
assertFileContains('admin/notices.php', "safeAddColumn(\$__db, 'notices', 'popup_photo_only'", 'notices popup cols via safeAddColumn');
assertFileContains('includes/member-auth.php', "'twofa_enabled' => 'TINYINT DEFAULT 0'", 'member-auth twofa column in safeAddColumn map');
assertFileContains('includes/member-auth.php', "safeAddColumn(\$db, 'members', \$col, \$def)", 'member-auth columns via safeAddColumn loop');
assertFileContains('includes/welfare-claims-tables.php', "safeAddColumn(\$db, 'member_welfare_claims', 'tracking_id'", 'welfare claims tracking via safeAddColumn');
assertFileContains('application-tracker.php', 'data-lucide="mail"', 'tracker notify chips Lucide');
assertFileContains('member/index.php', 'coop_nav_icon_html', 'member dashboard service icons Lucide helper');
assertFileContains('member/index.php', 'data-lucide="inbox"', 'member dashboard empty apps Lucide');
assertFileContains('member/index.php', "['circle-check'", 'member dashboard notif iconMap Lucide');
assertFileContains('member/notifications.php', 'data-lucide="bell"', 'member notifications empty Lucide');
assertFileContains('member/notifications.php', "['circle-check'", 'member notifications iconMap Lucide');
assertFileContains('member/tracker.php', 'data-lucide="inbox"', 'member tracker empty Lucide');
assertFileContains('member/tracker.php', 'trk-chevron', 'member tracker chevron Lucide class');
assertFileContains('assets/js/v9-mobile-fix.js', "setToggleLucide(isOpen ? 'x' : 'menu')", 'mobile drawer Lucide menu/x toggle');
assertFileNotContains('assets/js/v9-mobile-fix.js', "toggleIcon.classList.toggle('fa-xmark'", 'mobile drawer no FA xmark toggle');
assertFileContains('assets/css/member-shell-polish.css', '.mem-empty-icon .lucide-icon', 'member empty Lucide sizing');
assertFileContains('admin/includes/admin-request-view.php', "\$icon = \$channel === 'email' ? 'mail' : 'smartphone'", 'admin request view chips Lucide names');
assertFileContains('assets/js/scroll-accessibility.js', 'data-lucide="accessibility"', 'scroll a11y Lucide toggle');

assertFileContains('assets/js/kyc-capture.js', 'function kycLucideRefresh', 'kyc-capture Lucide refresh helper');
assertFileNotContains('assets/js/kyc-capture.js', 'fas fa-camera', 'kyc-capture no FA camera');
assertFileNotContains('assets/js/pull-to-refresh.js', 'fas fa-arrow-down', 'PTR no FA arrow');
assertFileContains('assets/js/pull-to-refresh.js', 'data-lucide="arrow-down"', 'PTR Lucide arrow');
assertFileNotContains('assets/js/pwa-register.js', 'fas fa-download', 'PWA no FA download');
assertFileContains('assets/js/search-improved.js', 'data-lucide="mic"', 'search mic Lucide');
assertFileNotContains('admin/assets/admin.js', "fas fa-eye", 'admin togglePwd no FA eye');

assertFileNotContains('member/includes/chrome.php', 'fas fa-circle-check', 'member chrome bell icons Lucide');
assertFileNotContains('member/includes/chrome.php', 'fas fa-bell', 'member chrome push toggle no FA');
assertFileNotContains('includes/footer.php', 'fas fa-sun', 'footer dark toggle no FA sun');
assertFileNotContains('admin/site-health.php', 'fas fa-eye', 'site-health pw toggle no FA');
assertFileNotContains('admin/credentials.php', 'far fa-eye', 'credentials reveal no FA eye');
assertFileContains('includes/header.php', 'data-lucide="link"', 'header sat icon Lucide fallback');

assertFileContains('includes/gallery-albums.php', "safeAddColumn(\$db, 'gallery', 'album_id'", 'gallery albums via safeAddColumn');
assertFileContains('includes/digital-service-requests-tables.php', "safeAddColumn(\$db, 'digital_service_requests', 'service_amount'", 'digital service requests via safeAddColumn');
assertFileNotContains('assets/js/scroll-accessibility.js', 'fas fa-universal-access', 'scroll a11y no FA toggle');
assertFileContains('admin/assets/icon-picker.js', 'faClassToLucideName', 'icon-picker Lucide preview helper');
assertFileContains('admin/assets/icon-picker.js', "id: 'brands'", 'icon-picker brands group stays FA');
assertFileContains('admin/assets/icon-picker.js', 'DB stores FA class', 'icon-picker documents FA storage SSOT');
assertFileContains('admin/assets/icon-picker.css', '.fa-ip-preview .fab', 'icon-picker brand preview FA fonts');
assertFileContains('admin/includes/admin-footer.php', 'icon-picker.js?v=6', 'icon-picker cache-bust v6');
assertFileContains('admin/manage-admins.php', 'normalize_role_aliases', 'manage-admins alias-only normalize action');
assertFileNotContains('admin/member-online-portal.php', "'❌ पासवर्ड Reset", 'portal reject notif no emoji title');
assertFileContains('admin/member-online-portal.php', "'पासवर्ड Reset अस्वीकृत भयो'", 'portal reject notif text title');
assertFileNotContains('admin/account-applications.php', '>>⏳', 'account apps filter no pending emoji');
assertFileContains('admin/account-applications.php', "?'selected':''; ?>><?php echo \$__t('पेन्डिङ'", 'account apps option markup intact');
assertFileNotContains('admin/kyc-applications.php', '>>✅', 'kyc apps filter no approved emoji');
assertFileNotContains('admin/job-applications.php', '>>📋', 'job apps filter no clipboard emoji');
assertFileContains('admin/manage-admins.php', 'Privilege mass UPDATE', 'manage-admins documents no privilege mass UPDATE');
assertFileContains('includes/auth-roles.php', 'Privilege-level mass UPDATE remains deferred', 'role helper documents deferred privilege UPDATE');
assertFileContains('includes/config.php', 'function coop_canonical_icon_for_storage', 'icon storage canonicalize helper');
assertFileContains('includes/config.php', 'NOT a FA→Lucide mass row rewrite', 'icon storage docs no DB mass rewrite');
assertFileNotContains('admin/includes/admin-header.php', '📖 सहायता', 'admin help nav no emoji');
assertFileNotContains('admin/loan-applications.php', '💰', 'loan filter no emoji');
assertFileNotContains('admin/feedbacks.php', '👁', 'feedbacks filter no emoji');
assertFileNotContains('admin/members.php', '📘', 'members notif type no emoji');
assertFileNotContains('admin/push-notifications.php', '🔴', 'push type filter no emoji');
assertFileContains('includes/error-handler.php', "PHP_SAPI === 'cli'", 'CLI errors skip HTML 500 dump');
assertFileContains('includes/config.php', 'Pdo\\Mysql::ATTR_INIT_COMMAND', 'PHP 8.5 PDO MySQL init command');
assertFileContains('scripts/smoke-program-concurrency.php', 'DB skipped', 'program concurrency smoke soft-skips without DB');
assertFileContains('admin/pages.php', 'coop_canonical_icon_for_storage', 'pages menu_icon write canonicalize');
assertFileContains('admin/services.php', 'coop_canonical_icon_for_storage', 'services icon write canonicalize');
assertFileContains('admin/committees.php', 'coop_canonical_icon_for_storage', 'committees icon write canonicalize');
assertFileContains('admin/team.php', 'coop_canonical_icon_for_storage', 'team menu icon write canonicalize');
assertFileContains('admin/app-features.php', 'coop_canonical_icon_for_storage', 'app-features icon write canonicalize');
assertFileContains('admin/useful-links.php', 'coop_canonical_icon_for_storage', 'useful-links icon write canonicalize');
assertFileContains('admin/why-choose.php', 'coop_canonical_icon_for_storage', 'why-choose icon write canonicalize');
assertFileContains('admin/satisfaction-settings.php', 'coop_canonical_icon_for_storage', 'satisfaction links icon write canonicalize');
assertFileContains('admin/welfare-claim-types.php', 'coop_canonical_icon_for_storage', 'welfare claim types icon write canonicalize');
assertFileContains('admin/digital-service-types.php', 'coop_canonical_icon_for_storage', 'digital service types icon write canonicalize');
assertFileContains('scripts/inventory-icon-dual-read.py', 'no DB mass rewrite', 'icon dual-read inventory script');
assertFileContains('scripts/inventory-css-deep-audit.py', 'lucide-icon-utils.css', 'CSS deep audit knows Lucide utils');
assertFileContains('scripts/inventory-css-deep-audit.py', 'no_fa_db_mass_rewrite', 'CSS deep audit documents FA DB freeze');
assertFileContains('scripts/inventory-app-css.py', 'first_pr_candidates', 'app-* inventory first-PR extract list');
assertFileContains('scripts/inventory-app-css.py', 'fa_to_lucide_db_row_rewrite', 'inventory documents deferred FA DB rewrite');
assertFileContains('scripts/inventory-app-css.py', 'safe_alternatives', 'inventory documents safe alternatives');
assertFileNotContains('includes/auth-roles.php', "SET `role` = 'admin' WHERE", 'no privilege mass demote to admin');
assertFileNotContains('includes/auth-roles.php', "SET `role` = 'staff' WHERE", 'no privilege mass demote to staff');
assertFileContains('includes/auth-roles.php', "SET `role` = 'super_admin' WHERE `role` = 'superadmin'", 'only alias-only role UPDATE');
assertFileContains('includes/config.php', 'Brand packs (`fab`', 'brand FA retained comment');
assertFileContains('includes/config.php', "preg_match('/\\bfab\\b|\\bfa-brands\\b/i'", 'coop_nav_icon_html keeps fab brands');
assertFileContains('admin/includes/admin-footer.php', 'COOP_FA_LUCIDE_MAP', 'admin footer exposes FA→Lucide map');
assertFileContains('includes/header.php', '--pfl-mobile-logo: url(', 'header logo CSS var on html');
assertFileNotContains('includes/header.php', 'id="public-header-dynamic-vars"', 'header no separate dynamic style block');

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
