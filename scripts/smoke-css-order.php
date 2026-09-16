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

function assertNotContains(string $file, string $needle, string $why): void {
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
        fail("{$file}: unexpected `{$needle}` ({$why})");
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
        'mobile-premium-polish.css',
        'public-shell-polish.css',
        'lucide-icon-utils.css',
        'ui-readability-safe-patch.css',
        'final-ui-polish.css',
    ],
    'admin-late-bundle.css' => [
        'premium-ui.css',
        'mobile-premium-polish.css',
        'admin-shell-polish.css',
        'lucide-icon-utils.css',
        'ui-readability-safe-patch.css',
        'admin-ux-deep-patch.css',
        'final-ui-polish.css',
    ],
    'member-late-bundle.css' => [
        'premium-ui.css',
        'mobile-premium-polish.css',
        'member-shell-polish.css',
        'lucide-icon-utils.css',
        'ui-readability-safe-patch.css',
        'final-ui-polish.css',
    ],
    'minimal-late-bundle.css' => [
        'public-shell-polish.css',
        'lucide-icon-utils.css',
        'minimal-pages-patch.css',
        'ui-readability-safe-patch.css',
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

// Homepage readability overrides must stay in final polish (late bundle wins)
assertContains('assets/css/final-ui-polish.css', 'Homepage notices: undo over-compact', 'notice title readability marker');
assertContains('assets/css/final-ui-polish.css', 'Homepage interest rates: undo over-compact', 'rate row readability marker');
assertContains('assets/css/final-ui-polish.css', 'Institutional stats: readable labels', 'stats label readability marker');
assertContains('assets/css/public-shell-polish.css', 'Tools widget: readable footer label', 'tools mini footer readability marker');
assertContains('assets/css/public-shell-polish.css', 'restore primary header chips', 'tools widget primary header chips');
assertContains('assets/css/public-shell-polish.css', 'toolsHeaderShimmerSoft', 'tools widget soft header shimmer restored');
assertContains('assets/css/public-shell-polish.css', 'Homepage tools widget SSOT', 'tools SSOT lives in public shell');
assertNotContains('assets/css/final-ui-polish.css', 'toolsHeaderShimmerSoft', 'tools motion not duplicated in final-ui');
assertNotContains('assets/css/admin-late-bundle.css', 'toolsHeaderShimmerSoft', 'admin late bundle excludes homepage tools motion');
assertNotContains('assets/css/member-late-bundle.css', 'toolsHeaderShimmerSoft', 'member late bundle excludes homepage tools motion');
assertContains('assets/css/public-late-bundle.css', 'toolsHeaderShimmerSoft', 'public late bundle keeps tools motion');
assertNotContains('assets/css/global-theme.php', 'Kill old shimmer pseudo-element', 'dead tools shimmer-kill removed from global-theme');
assertContains('assets/css/final-ui-polish.css', 'body.aos-safe [data-aos]:not(.aos-animate)', 'aos-safe does not kill live scroll fades');
assertContains('includes/footer.php', 'Fail-safe only if AOS left nodes stuck', 'AOS safe timeout is conditional');
assertNotContains('assets/css/final-ui-polish.css', 'transition-delay: 0ms !important', 'AOS delays not globally zeroed');
assertContains('assets/css/final-ui-polish.css', 'restore brand green', 'unified section titles use primary color');
assertContains('assets/css/final-ui-polish.css', 'hide side chevrons on all viewports', 'hero slider side arrows hidden');
assertContains('assets/css/final-ui-polish.css', 'labels + lucide/FA follow admin primary', 'header nav uses primary color');
assertContains('assets/css/final-ui-polish.css', 'even quick-link grid', 'footer quick links even grid');
assertContains('includes/footer.php', 'footer-links-cols', 'footer quick links use even grid class');
assertContains('assets/css/final-ui-polish.css', 'Icon contrast fixes (Lucide + light footer + colored wells)', 'icon contrast root-cause block');
assertContains('assets/css/final-ui-polish.css', '.main-footer .footer-contact .lucide-icon', 'footer contact Lucide stroke on light footer');
assertContains('assets/css/final-ui-polish.css', 'html body .qh-menu .qh-item .qh-ic svg', 'qh-ic wells force white Lucide glyphs');
assertContains('assets/css/final-ui-polish.css', '.qh-fab svg.qh-i-close', 'qh-fab Lucide open/close targets svg');
assertContains('assets/css/final-ui-polish.css', 'body.dark-mode .main-footer .footer-contact .lucide-icon', 'dark footer keeps light contact icons');
assertContains('assets/css/final-ui-polish.css', 'restore icon↔text space', 'footer contact icon-text gap restored');
assertContains('assets/css/final-ui-polish.css', '.cta-section .cta-content .ir-cta-btn', 'CTA solid buttons include ir-cta-btn');
assertContains('assets/css/final-ui-polish.css', 'white-on-white', 'CTA white-on-white root documented');
assertContains('assets/css/ui-readability-safe-patch.css', 'Do NOT paint bare `div` with -webkit-text-fill-color', 'readability no longer fills CTA divs');
assertContains('interest-rates.php', 'btn btn-light btn-lg', 'interest-rates CTA uses btn-light');
assertContains('assets/css/verify-page.css', '.vp-card-head-icon svg.lucide', 'verify card-head Lucide white stroke');
assertContains('includes/footer.php', 'data-lucide="help-circle"', 'FAQ well uses help-circle Lucide name');
assertContains('assets/css/public-late-bundle.css', 'Icon contrast fixes (Lucide + light footer + colored wells)', 'public late embeds icon contrast');
assertContains('assets/css/final-ui-polish.css', 'Drop legacy app-public red/yellow dot on public section dividers only', 'section divider dot override');
assertContains('assets/css/final-ui-polish.css', 'Admin modal keeps flex line dividers', 'admin modal divider scoped');
assertContains('assets/css/final-ui-polish.css', 'Disable duplicate h2 ornaments', 'section h2 pseudo cleanup');
assertContains('assets/css/final-ui-polish.css', 'One badge style', 'section badge unify');
assertContains('assets/css/final-ui-polish.css', 'Corp modal-header tint', 'modal header corp override fix');
assertContains('assets/css/final-ui-polish.css', 'Header: Himal bg + SINCE badge', 'header himal since lang fixes');
assertContains('includes/config.php', 'function safe_versioned_media_src_absolute', 'absolute versioned media helper');
assertContains('includes/header.php', 'safe_versioned_media_src_absolute', 'header himal absolute url');
assertContains('verify.php', 'var(--primary-dark,#145021)', 'verify retry button uses primary-dark not hardcoded teal');
assertNotContains('verify.php', '#0e9b53', 'verify no hardcoded teal gradient stop');
assertContains('assets/css/member-shell-polish.css', 'vp-success-alert', 'member shell success alert present');
assertContains('assets/css/member-shell-polish.css', 'color-mix(in srgb, var(--primary-color', 'member success alert border follows primary');
assertContains('assets/css/global-theme.php', '--light-green:     var(--bg-muted)', 'legacy light-green aliases muted brand');
assertContains('assets/css/global-theme.php', "define('THEME_VERSION', '2.5')", 'theme version 2.5 auto-contrast');
assertContains('assets/css/global-theme.php', '--text-on-topbar:', 'topbar has WCAG text token');
assertContains('assets/css/global-theme.php', '--icon-on-primary:', 'icon-on-primary token present');
assertContains('assets/css/global-theme.php', "getSetting('topbar_color'", 'topbar_color drives --topbar-bg');
assertContains('assets/css/final-ui-polish.css', 'var(--primary-ink, var(--primary-color, #1a5f2a)) !important;', 'footer contact uses primary-ink');
assertContains('assets/css/final-ui-polish.css', 'Admin colour change → use --primary-ink', 'auto-contrast remaps documented');
assertContains('admin/settings.php', 'textOnGradient', 'settings preview uses gradient WCAG contrast');
assertContains('admin/settings.php', "--icon-on-topbar'", 'settings live-preview sets icon-on-topbar');
assertContains('assets/css/final-ui-polish.css', 'Auto-contrast leftovers (THEME 2.5+)', 'auto-contrast leftovers block');
assertContains('assets/css/final-ui-polish.css', '.report-card .report-icon .lucide-icon', 'report card icon wells use on-primary Lucide');
assertContains('assets/css/reports-page.css', '.report-card .report-icon .lucide-icon', 'reports-page forces on-primary type icons');
assertContains('assets/css/global-theme.php', 'Do NOT force color on all descendants', 'topbar no longer paints dropdown panels');
assertContains('assets/css/global-theme.php', '.list-group-item.active', 'list-group active has text-on-primary');
assertContains('assets/css/global-theme.php', 'color: var(--primary-ink, var(--primary-color)) !important;', 'text-primary uses primary-ink');
assertContains('assets/css/final-ui-polish.css', 'Completes multi-page uniform fonts/colors', 'typography lock completion marker');
assertContains('assets/css/final-ui-polish.css', '.pfl-top-bar', 'typography lock covers top bar Mukta');
assertContains('assets/css/final-ui-polish.css', 'background-color: var(--bg-page', 'body bg uses brand surface token');
assertContains('assets/css/final-ui-polish.css', 'Typography SSOT lock', 'final polish locks Inter/Jakarta over Mukta');
assertContains('assets/css/final-ui-polish.css', '--shell-font-body: var(--font-primary', 'final ensures shell font aliases');
assertContains('assets/css/premium-ui.css', 'color-mix(in srgb, var(--primary-color', 'premium selection uses primary tint');
assertNotContains('assets/css/premium-ui.css', 'rgba(22,101,52,0.14)', 'premium selection no hardcoded green');
assertContains('assets/css/global.css', "--font-primary:         'Inter'", 'global.css Inter SSOT');
assertContains('assets/css/global.css', "--font-heading:         'Plus Jakarta Sans'", 'global.css Jakarta SSOT');
assertContains('assets/css/final-ui-polish.css', 'Multi-sahakari brand bridge', 'late brand bridge present');
assertContains('admin/settings.php', '--bg-muted', 'settings live preview updates bg surfaces');
assertContains('assets/css/public-shell-polish.css', 'rgba(var(--primary-rgb),', 'public shell uses primary-rgb shadows');
assertContains('index.php', 'truncateText($awardDescFull, 110', 'homepage awards description truncated');
assertContains('assets/css/final-ui-polish.css', 'Homepage awards — short teaser', 'awards teaser CSS in polish');
assertContains('scripts/deploy-pull-safe.sh', 'run-migration-safe.php --yes', 'deploy-pull includes safe migration');
assertContains('scripts/run-migration-safe.php', 'Safe CLI migration', 'CLI migration script present');

// Page-scoped KYC capture CSS still loaded (not orphaned)
assertContains('member/password-reset-request.php', "coopThemeLink('assets/vendor/bootstrap.min.css')", 'password-reset uses versioned Bootstrap URL');
assertNotContains('member/password-reset-request.php', 'href="assets/vendor/bootstrap.min.css"', 'password-reset no broken relative Bootstrap path');
assertContains('assets/css/global.css', '--shell-radius:', 'shared shell radius lives in global.css');
assertContains('assets/css/global.css', '--font-nepali:', 'global.css exposes nepali font token');
assertContains('assets/css/global.css', '--font-size-sm:         var(--text-sm)', 'global.css font-size aliases text scale');
assertContains('assets/css/global.css', '--fs-sm:                var(--text-sm)', 'global.css fs-* aliases text scale');
assertContains('assets/css/global-theme.php', '--font-size-base:  var(--text-base)', 'global-theme font-size lockstep with text-*');
assertContains('assets/css/global-theme.php', 'var(--font-size-sm, var(--text-sm, 0.8125rem))', 'admin form-label uses type-scale token');
assertContains('admin/includes/admin-ui.php', 'admin-empty-state-title', 'adminEmptyRow uses tokenized empty-state classes');
assertNotContains('member/index.php', 'if (false):', 'member dashboard dead legacy digital grid removed');
assertContains('admin/print-form.php', 'goto landing — if(false) prevents fall-through', 'print-form goto NOT_FOUND documented');
assertContains('assets/css/public-shell-polish.css', 'Service center cards — consolidated below', 'public shell service-center early duplicate removed');
assertContains('assets/css/admin-shell-polish.css', '.admin-empty-state-title', 'admin empty-state title token styles');
assertContains('assets/css/admin-ux-deep-patch.css', 'Removed mid-layer duplicate', 'ux-deep drops empty-state triple override');
assertContains('assets/css/final-ui-polish.css', '.admin-empty-state-title', 'final polish styles empty-state title');
{
    $gc = file_get_contents($root . '/assets/css/global.css');
    $n = substr_count((string)$gc, '.d-none { display: none !important; }');
    if ($n === 1) {
        ok('global.css: single .d-none utility');
    } else {
        fail("global.css: expected 1 .d-none utility, found {$n}");
    }
}
assertContains('assets/css/public-shell-polish.css', '--pub-radius: var(--shell-radius)', 'public shell aliases shared radius');
assertContains('assets/css/member-shell-polish.css', '--mem-radius: var(--shell-radius)', 'member shell aliases shared radius');
assertContains('assets/css/admin-shell-polish.css', '--admin-radius: 10px', 'admin radius stays 10px');
assertContains('includes/theme-assets.php', 'function coopThemeLinkHtml', 'extraHead CSS helper');
assertContains('includes/theme-assets.php', 'function coopThemeColorMeta', 'shared theme-color meta');
assertContains('assets/css/global.css', '.coop-alert--success', 'shared coop-alert type classes');
assertContains('assets/css/member-shell-polish.css', '.bell-dropdown.open', 'member bell CSS in shell polish');
assertContains('member/includes/chrome.php', "coopThemeLink('assets/css/member-bs-compat.css')", 'member chrome versioned bs-compat');
assertContains('admin/includes/admin-header.php', "coopThemeLink('assets/vendor/bootstrap.min.css')", 'admin header versioned Bootstrap');
assertContains('includes/panel-uniform.php', 'coop-alert-body', 'coopAlert uses shared body class');
assertContains('assets/css/final-ui-polish.css', '--final-control-radius:', 'phase 2b control radius contract');
assertContains('assets/css/final-ui-polish.css', 'border-radius: var(--final-control-radius', 'forms use control radius token');
assertContains('assets/css/final-ui-polish.css', 'font-weight: var(--final-table-head-weight', 'admin table head uses weight token');
assertContains('assets/css/kyc-capture.css', 'var(--primary-color, #1a5f2a)', 'kyc capture uses brand token');
assertContains('assets/css/information-room.css', 'var(--shell-radius, 12px)', 'information-room uses shell radius');
assertContains('admin/site-license-blocked.php', "coopThemeLink('assets/vendor/bootstrap.min.css')", 'license-blocked versioned Bootstrap');
assertNotContains('admin/site-license-blocked.php', 'href="assets/vendor/bootstrap.min.css"', 'license-blocked no broken Bootstrap path');
assertContains('assets/css/public-late-bundle.css', '--final-control-radius:', 'public bundle includes phase 2b tokens');
assertContains('assets/css/admin-late-bundle.css', '--final-control-radius:', 'admin bundle includes phase 2b tokens');
assertContains('assets/css/member-late-bundle.css', '--final-control-radius:', 'member bundle includes phase 2b tokens');
assertContains('admin/db-setup.php', "coopThemeLink('assets/vendor/bootstrap.min.css')", 'db-setup versioned Bootstrap');
assertNotContains('admin/db-setup.php', 'href="assets/vendor/bootstrap.min.css"', 'db-setup no broken Bootstrap path');
assertContains('includes/site-license.php', "assets/css/global.css", 'license expired page loads global.css');
assertContains('includes/site-license.php', 'site-license-expired-page.css', 'license expired loads extracted CSS');
assertContains('assets/css/site-license-expired-page.css', '.svc-expired-page', 'license expired CSS extracted');
assertContains('core/init.php', 'core-portal-fatal-page.css', 'core fatal loads extracted CSS');
assertContains('assets/css/core-portal-fatal-page.css', '.err-box', 'core fatal CSS extracted');
assertContains('member/login.php', "coopThemeLink('assets/css/member-login-page.css')", 'member login loads extracted CSS');
assertContains('assets/css/member-login-page.css', '.card-logo-wrap', 'member login CSS extracted');
assertContains('includes/config.php', 'setup-gate-page.css', 'setup gate loads extracted CSS');
assertContains('assets/css/setup-gate-page.css', '.box', 'setup gate CSS extracted');
assertContains('includes/site-license.php', '$brandDynamicStyle', 'license expired injects DB brand style');
assertContains('includes/header.php', "coopThemeLink('assets/vendor/bootstrap.min.css')", 'public header versioned Bootstrap');
assertContains('includes/header.php', "coopThemeLink('assets/css/app-core.css')", 'public header versioned app-core');
assertContains('assets/css/premium-ui.css', '--prem-font-body: var(--font-primary', 'premium fonts alias global');
assertContains('assets/css/final-ui-polish.css', 'var(--primary-color, #1a5f2a), var(--primary-light, #2e7d32)', 'chairman bar uses brand tokens');
assertContains('admin/site-license-blocked.php', "coopThemeLink('assets/css/global.css')", 'license-blocked loads global.css');
assertContains('admin/site-license-blocked.php', "coopThemeLink('assets/css/admin-site-license-blocked-page.css')", 'license-blocked loads extracted CSS');
assertContains('assets/css/admin-site-license-blocked-page.css', 'color: var(--primary-color, #1a5f2a)', 'license-blocked code uses brand token');
assertNotContains('admin/site-license-blocked.php', "font-family: 'Mukta'", 'license-blocked no Mukta hardcode');

assertContains('includes/header.php', 'if (!empty($extraHead))', 'public header supports $extraHead');
assertContains('institutional-profile.php', "coopThemeLinkHtml('assets/css/institutional-profile.css')", 'institutional profile loads extracted CSS');
assertContains('assets/css/institutional-profile.css', '.ip-filter-wrap', 'institutional profile CSS extracted');
assertContains('includes/institutional-profile-helpers.php', 'function coopIpWelfareReliefByType', 'IP helpers expose welfare SSOT aggregator');
assertContains('includes/institutional-profile-helpers.php', "strtr(\$grouped", 'full amount uses Nepali digits for NP UI');
assertContains('institutional-profile.php', 'getLocalizedLogoPath', 'IP poster uses header banner logo path');
assertContains('assets/css/institutional-profile.css', 'max-height: 96px', 'IP poster logo is wide banner style');
assertContains('institutional-profile.php', 'ipFullAmt((float)$p[\'share_capital\'])', 'month ledger share capital is full amount');
assertContains('includes/institutional-profile-helpers.php', 'COALESCE(paid_at, reviewed_at, created_at)', 'welfare cutoff uses effective claim date');
assertContains('includes/institutional-profile-helpers.php', "return '';", 'undated IP profile skips today welfare fallback');
assertContains('institutional-profile.php', 'coopIpWelfareReliefByType', 'monthly IP pulls welfare types from member-welfare SSOT');
assertContains('assets/css/institutional-profile.css', '.ip-share-btn:focus-visible', 'IP share CTA has focus-visible');
assertContains('institutional-profile.php', 'data-ip-poster', 'monthly IP share poster payload');
assertContains('institutional-profile.php', 'id="ipPosterModal"', 'monthly IP share poster modal');
assertContains('assets/css/institutional-profile.css', '.ip-relief-table', 'IP welfare relief table styles');
assertContains('assets/css/institutional-profile.css', '.ip-poster-sheet', 'IP social share poster styles');
assertContains('assets/css/institutional-profile.css', '.ip-poster-brand-row', 'IP poster brand row layout');
assertContains('assets/css/institutional-profile.css', '.ip-poster-stat', 'IP poster stats centered cards');
assertContains('assets/css/institutional-profile.css', 'body.ip-poster-printing', 'IP poster print gated on open class');
assertContains('assets/css/institutional-profile.css', '.ip-share-menu', 'IP share fallback menu mirrors reports');
assertContains('institutional-profile.php', 'ip-poster-printing', 'IP print class toggled from share poster');
assertContains('institutional-profile.php', 'showFallbackMenu', 'IP share uses reports-style fallback menu');
assertContains('assets/css/global-theme.php', "var(--font-primary,'Inter','Noto Sans Devanagari',system-ui,sans-serif)", 'global-theme font fallback matches SSOT');
assertContains('assets/css/final-ui-polish.css', "font-family: var(--font-primary, 'Inter', 'Noto Sans Devanagari', system-ui, sans-serif) !important", 'header nav font uses SSOT');
assertContains('assets/css/member-kyc-print-page.css', "font-family: var(--font-primary", 'kyc-print font uses SSOT with Arial fallback');
assertContains('assets/css/admin-print-form-page.css', "font-family: var(--font-primary", 'print-form font uses SSOT');
assertContains('member/password-reset-request.php', "coopThemeHeadAssets('auth')", 'password-reset uses theme hub auth panel');
assertNotContains('member/index.php', '👋', 'member dashboard greeting no emoji');
assertNotContains('application-tracker.php', '🪪', 'tracker id-card CTA no emoji');
assertNotContains('tracker-id-card.php', '🔐', 'tracker preview no emoji');
assertNotContains('install.php', '🎉', 'install success no emoji');
assertNotContains('member/service-request.php', '📅', 'service-request labels no emoji');
assertNotContains('admin/site-setup.php', '⚠️', 'site-setup status no warn emoji');
assertContains('includes/theme-assets.php', '"sync-alt": ["refresh-cw"', 'Lucide alias covers sync-alt');
assertContains('includes/theme-assets.php', '"cloud-upload-alt": ["cloud-upload"', 'Lucide alias covers cloud-upload-alt');
assertContains('includes/theme-assets.php', '"sign-out-alt": ["log-out"', 'Lucide alias covers sign-out-alt');
assertContains('includes/theme-assets.php', '"circle-xmark": ["circle-x"', 'Lucide alias covers circle-xmark');
assertContains('includes/theme-assets.php', '"whatsapp": ["message-circle"', 'Lucide brand alias fallback exists');
assertContains('index.php', 'fab fa-google-play', 'Play Store uses FA brand icon');
assertNotContains('career-detail.php', 'data-lucide="whatsapp"', 'career share brands not fake Lucide');
assertNotContains('date-converter.php', 'data-lucide="sync-alt"', 'date-converter uses Lucide refresh name');
assertNotContains('services.php', 'data-lucide="shield-alt"', 'services uses Lucide shield name');
assertNotContains('gallery.php', 'data-lucide="search-plus"', 'gallery uses Lucide zoom name');
assertNotContains('member/profile.php', 'data-lucide="sign-out-alt"', 'member profile logout Lucide name');
assertContains('admin/includes/admin-ui.php', "'success' => 'circle-check'", 'adminAlert success Lucide SSOT');
assertContains('core/helpers.php', "'fa-check-circle'     => 'circle-check'", 'fa_to_lucide check-circle → circle-check');
assertContains('assets/css/member-id-card-page.css', "font-family: var(--font-primary", 'id-card font uses SSOT');
assertContains('member/id-card.php', "coopThemeLinkHtml('assets/css/member-id-card-page.css')", 'id-card loads extracted CSS');
assertContains('assets/css/member-password-reset-page.css', "var(--font-primary,'Inter'", 'password-reset font fallback matches SSOT');

assertContains('sahakari-patro.php', "coopThemeLinkHtml('assets/css/sahakari-patro.css')", 'sahakari-patro loads extracted CSS');
assertContains('assets/css/sahakari-patro.css', '--sp-primary:var(--primary-color', 'sahakari-patro CSS brand tokens');
assertContains('verify.php', "coopThemeLink('assets/css/verify-page.css')", 'verify loads extracted CSS');
assertContains('assets/css/verify-page.css', '.vp-back-bar', 'verify page CSS extracted');
assertContains('includes/satisfaction-widget.php', "coopThemeLink('assets/css/satisfaction-widget.css')", 'satisfaction widget loads extracted CSS');
assertContains('assets/css/satisfaction-widget.css', '.satisfaction-widget', 'satisfaction widget CSS extracted');
assertContains('committees.php', "coopThemeLinkHtml('assets/css/committees-page.css')", 'committees loads extracted CSS');
assertContains('auction.php', "coopThemeLinkHtml('assets/css/auction-page.css')", 'auction loads extracted CSS');
assertContains('appointment.php', "coopThemeLinkHtml('assets/css/appointment-page.css')", 'appointment loads extracted CSS');
assertContains('team.php', "coopThemeLinkHtml('assets/css/team-page.css')", 'team loads extracted CSS');
assertContains('about.php', "coopThemeLinkHtml('assets/css/about-success-stories.css')", 'about loads related/success CSS');
assertContains('about.php', "location.replace", 'about redirects old success/chairman hashes');
assertContains('about.php', "h === '#ceo' || h === '#ceo-message'", 'about hash redirects #ceo to dedicated page');
assertContains('about.php', "h === '#success-stories' || h === '#success'", 'about hash redirects success aliases');
assertContains('includes/ai-chat-instant.php', "chairman-message.php", 'AI chat public links include chairman page');
assertContains('includes/ai-chat-instant.php', "ceo-message.php", 'AI chat public links include ceo page');
assertContains('includes/ai-chat-instant.php', "success-stories.php", 'AI chat public links include success stories');
assertNotContains('includes/ai-chat-instant.php', "'#chairman'", 'AI chat chairman no stale about#hash');
assertContains('includes/ai-chat-context.php', "ceo-message.php", 'AI context pack links ceo message page');
assertContains('about.php', 'vision-mission.php', 'about teaser links vision-mission page');
assertContains('about.php', 'id="vision-teaser"', 'about vision is teaser not full duplicate');
assertContains('index.php', 'why-choose.php', 'homepage links why-choose dedicated page');
assertContains('admin/pages.php', '../vision-mission.php', 'pages static preview links vision-mission');
assertContains('admin/help-guide.php', 'vision-mission.php', 'help guide documents vision-mission');
assertContains('vision-mission.php', 'vision_content', 'vision-mission dedicated page');
assertContains('why-choose.php', 'why_choose_features', 'why-choose dedicated page');
assertContains('includes/header.php', 'vision-mission.php', 'about dropdown links vision-mission page');
assertContains('includes/header.php', 'why-choose.php', 'about dropdown links why-choose page');
assertContains('about.php', "h === '#vision' || h === '#mission' || h === '#vision-mission'", 'about hash redirects vision to dedicated page');
assertContains('sitemap.php', 'success-stories.php', 'sitemap includes success stories');
assertContains('sitemap.php', 'chairman-message.php', 'sitemap includes chairman page');
assertContains('sitemap.php', 'vision-mission.php', 'sitemap includes vision-mission');
assertContains('sitemap.php', 'why-choose.php', 'sitemap includes why-choose');
assertContains('success-stories.php', 'fetchActiveMemberSuccessStories', 'success stories dedicated page');
assertContains('success-stories.php', "coopThemeLinkHtml('assets/css/about-success-stories.css')", 'success stories page CSS');
assertContains('chairman-message.php', 'coop_load_leadership_messages', 'chairman dedicated page');
assertContains('chairman-message.php', "coopThemeLinkHtml('assets/css/leadership-message-page.css')", 'chairman page CSS');
assertContains('chairman-message.php', 'leadership-message-stack', 'chairman stacked photo-above layout');
assertContains('ceo-message.php', 'coop_load_leadership_messages', 'ceo dedicated page');
assertContains('ceo-message.php', "coopThemeLinkHtml('assets/css/leadership-message-page.css')", 'ceo page CSS');
assertContains('ceo-message.php', 'leadership-message-stack', 'ceo stacked photo-above layout');
assertContains('assets/css/leadership-message-page.css', '.leadership-messages-about', 'leadership page CSS present');
assertContains('assets/css/leadership-message-page.css', 'leadership-message-stack', 'stack layout CSS present');
assertContains('assets/css/leadership-message-page.css', 'max-width: min(56rem, 100%)', 'leadership card wider default');
assertContains('assets/css/leadership-message-page.css', 'max-width: none', 'message body uses full card width');
assertContains('assets/css/leadership-message-page.css', 'position: static !important', 'stack quote resets frozen absolute');
assertContains('assets/css/leadership-message-page.css', '.leadership-message-stack .message-content-full', 'stack scopes message content');
assertContains('includes/leadership-message-helpers.php', 'chairman_designation_en', 'chairman designation mirrors CEO');
assertContains('ceo-message.php', 'id="ceo-message-body"', 'ceo article id uniform');
assertContains('chairman-message.php', 'id="chairman-message-body"', 'chairman article id uniform');
assertContains('reports.php', "coopThemeLinkHtml('assets/css/reports-page.css')", 'reports page CSS linked');
assertContains('reports.php', 'report-actions-icons', 'reports icon-only action row');
assertContains('reports.php', 'report-action-share', 'reports share action present');
assertContains('reports.php', 'data-share-text', 'share includes report details payload');
assertContains('assets/css/reports-page.css', '.report-action-btn', 'reports icon button styles');
assertContains('assets/css/reports-page.css', 'position: fixed', 'share menu uses fixed positioning');
assertContains('reports.php', 'showFallbackMenu', 'share has desktop fallback menu');
assertContains('reports.php', 'AbortError', 'web share cancel does not force fallback');
assertContains('includes/header.php', 'success-stories.php', 'about dropdown links success stories page');
assertContains('includes/header.php', 'chairman-message.php', 'about dropdown links chairman page');
assertContains('includes/header.php', 'ceo-message.php', 'about dropdown links ceo page');
assertContains('assets/css/about-success-stories.css', '.mss-card', 'success stories CSS present');
assertContains('admin/member-success-stories.php', 'member_success_stories', 'admin success stories CRUD');
assertContains('includes/member-success-stories-tables.php', 'ensureMemberSuccessStoriesTable', 'success stories table helper');
assertContains('cooperative-programs.php', "coopThemeLinkHtml('assets/css/cooperative-programs-page.css')", 'programs loads extracted CSS');
assertContains('services.php', "coopThemeLinkHtml('assets/css/services-page.css')", 'services loads extracted CSS');
assertContains('member/welfare.php', "coopThemeLinkHtml('assets/css/member-welfare-page.css')", 'member welfare loads extracted CSS');
assertContains('assets/css/member-welfare-page.css', '.claim-card', 'member welfare CSS extracted');
assertContains('member/service-request.php', "coopThemeLinkHtml('assets/css/member-service-request-page.css')", 'member service-request loads extracted CSS');
assertContains('member/appointment.php', "coopThemeLinkHtml('assets/css/member-appointment-page.css')", 'member appointment loads extracted CSS');
assertContains('member/marketplace.php', "coopThemeLinkHtml('assets/css/member-marketplace-page.css')", 'member marketplace loads extracted CSS');
assertContains('member/index.php', "coopThemeLinkHtml('assets/css/member-dashboard-page.css')", 'member dashboard loads extracted CSS');
assertContains('assets/css/member-dashboard-page.css', '.midx-greeting', 'member dashboard CSS extracted');
assertContains('member/certificate.php', "coopThemeLinkHtml('assets/css/member-certificate-page.css')", 'member certificate loads extracted CSS');
assertContains('member/scan.php', "coopThemeLinkHtml('assets/css/member-scan-page.css')", 'member scan loads extracted CSS');
assertContains('member/attend.php', "coopThemeLinkHtml('assets/css/member-attend-page.css')", 'member attend loads extracted CSS');
assertContains('member/election-vote.php', "coopThemeLinkHtml('assets/css/member-election-vote-page.css')", 'member election vote loads extracted CSS');
assertContains('member/apply-frame.php', "coopThemeLinkHtml('assets/css/member-apply-frame-page.css')", 'member apply-frame loads extracted CSS');
assertContains('member/profile.php', "coopThemeLinkHtml('assets/css/member-profile-page.css')", 'member profile loads extracted CSS');
assertContains('includes/member-prefill-block.php', "coopThemeLink('assets/css/member-prefill-block.css')", 'prefill block loads extracted CSS');
assertContains('assets/css/member-prefill-block.css', '.coop-prefill-banner', 'prefill CSS extracted');
assertContains('admin/program-registration-desk.php', "coopThemeLink('assets/css/admin-program-registration-desk.css')", 'desk loads extracted CSS');
assertContains('admin/reports.php', "coopThemeLink('assets/css/admin-reports-page.css')", 'reports loads extracted CSS');
assertContains('member/password-reset-request.php', "coopThemeLink('assets/css/member-password-reset-page.css')", 'password-reset loads extracted CSS');
assertContains('assets/css/member-password-reset-page.css', '.step-dot', 'password-reset CSS extracted');
assertContains('member/kyc-print.php', "coopThemeLink('assets/css/member-kyc-print-page.css')", 'kyc-print loads extracted CSS');
assertContains('assets/css/member-kyc-print-page.css', '.toolbar', 'kyc-print CSS extracted');
assertContains('admin/settings.php', "coopThemeLink('assets/css/admin-settings-page.css')", 'settings loads extracted CSS');
assertContains('assets/css/admin-settings-page.css', '.stg-color-row', 'settings CSS extracted');
assertContains('admin/print-form.php', 'coopThemeColorHex', 'print-form uses theme brand hex');
assertContains('offline.php', 'assets/css/offline-page.css', 'public offline loads extracted CSS');
assertContains('assets/css/offline-page.css', '--primary-color:#1a5f2a', 'public offline uses primary token');
assertContains('member/offline.php', 'assets/css/member-offline-page.css', 'member offline loads extracted CSS');
assertContains('assets/css/member-offline-page.css', '--green:var(--primary-color)', 'member offline aliases primary');
assertContains('500.php', 'assets/css/error-500-page.css', '500 page loads extracted CSS');
assertContains('assets/css/error-500-page.css', '--primary-color:#166534', '500 page uses primary token');

assertContains('includes/header.php', "coopThemeLink('assets/css/public-header-shell.css')", 'header loads shell CSS');
assertContains('assets/css/public-header-shell.css', '.pfl-brand-area', 'header shell CSS extracted');
assertContains('includes/header.php', "coopThemeLink('assets/css/public-embed-frame.css')", 'header loads embed CSS');
assertContains('includes/header.php', "coopThemeLink('assets/css/public-mobile-nav-critical.css')", 'header loads mobile-nav CSS');
assertContains('admin/print-form.php', "coopThemeLink('assets/css/admin-print-form-page.css')", 'print-form loads extracted CSS');
assertContains('assets/css/admin-print-form-page.css', 'var(--pf-primary)', 'print-form uses brand CSS vars');
assertContains('install.php', 'assets/css/install-page.css', 'install loads extracted CSS');
assertContains('assets/css/install-page.css', 'body', 'install CSS extracted');
assertContains('admin/includes/admin-header.php', "coopThemeLink('assets/css/admin-header-critical.css')", 'admin-header loads critical CSS');


assertContains('includes/theme-assets.php', 'FROZEN: Do not rewrite app-public', 'app-* freeze documented');

assertContains('includes/theme-assets.php', 'function coopThemeGoogleFontsHtml', 'fonts HTML helper for heredocs');
assertContains('tracker-id-card.php', 'coopThemeGoogleFonts()', 'tracker id-card fonts SSOT');
assertContains('attend.php', 'member/attend.php', 'legacy attend redirects to member portal');
assertContains('includes/header.php', 'coopThemeGoogleFonts()', 'public header fonts SSOT');
assertNotContains('attend.php', 'data-lucide="calendar-check"', 'legacy attend no chrome Lucide page');
assertContains('verify.php', 'data-lucide="shield"', 'verify chrome Lucide');
assertNotContains('install.php', 'wght@400;500;600;700;800', 'install fonts drop weight 800');
assertNotContains('member/id-card.php', 'font-family:Mukta', 'id-card no Mukta');
assertContains('includes/panel-uniform.php', 'data-lucide="inbox"', 'uniform empty row Lucide');
assertContains('includes/panel-uniform.php', 'coop-alert--{$typeKey}', 'uniform alert uses CSS variants only');
assertNotContains('includes/panel-uniform.php', 'style="background:{$m', 'uniform alert no inline bg');
assertContains('assets/css/global.css', '.coop-empty-icon', 'global empty icon styles');
assertContains('assets/css/global.css', '.coop-info-th', 'global info card th styles');


assertContains('assets/css/app-public.css', 'FROZEN PANEL BASE', 'app-public frozen banner');

assertContains('assets/css/app-public.css', 'SECTION INDEX (inventory only', 'app-public section inventory');
assertContains('assets/css/app-admin.css', 'SECTION INDEX (inventory only', 'app-admin section inventory');
assertContains('assets/css/app-member.css', 'SECTION INDEX (inventory only', 'app-member section inventory');
assertContains('assets/css/app-core.css', 'SECTION INDEX (inventory only', 'app-core section inventory');
assertContains('includes/header.php', 'coop_nav_icon_html(', 'header nav icons via helper');

assertContains('assets/css/app-member.css', 'FROZEN PANEL BASE', 'app-member frozen banner');
assertContains('assets/css/app-core.css', 'FROZEN PANEL BASE', 'app-core frozen banner');
assertContains('scripts/build-css-late-bundles.py', 'Never include app-public', 'build script documents app-* freeze');
assertNotContains('scripts/build-css-late-bundles.py', '"app-public.css"', 'late bundles do not concat app-public');
assertContains('scripts/inventory-app-css.py', 'FROZEN PANEL BASE', 'app-* inventory script present');
assertContains('scripts/inventory-app-css.py', 'Never include app-public', 'inventory documents late-bundle policy');
assertContains('scripts/inventory-app-css.py', '*-page.css', 'inventory lists page CSS extracts');
assertContains('scripts/inventory-app-css.py', 'app-css-split-plan.json', 'inventory split plan report path');
assertContains('scripts/inventory-app-css.py', 'thin import shim', 'inventory documents shim split PR');
assertContains('scripts/extract-app-css-section.py', '--verify', 'shadow extract has sha verify mode');
assertContains('scripts/extract-app-css-section.py', '--all-first-pr', 'shadow extract can write all first_pr');
assertContains('scripts/extract-app-css-section.py', '--all-second-pr', 'shadow extract can write second-wave');
assertContains('scripts/extract-app-css-section.py', '--all-third-pr', 'shadow extract can write third-wave');
assertContains('scripts/extract-app-css-section.py', 'THIRD_PR_MAX_BYTES', 'third-wave has byte cap for large deferred bodies');
assertContains('assets/css/app-sections/README.md', 'not live CSS', 'app-sections README marks non-live');
assertContains('assets/css/app-sections/README.md', '--all-third-pr', 'app-sections README documents third wave');
assertContains('assets/css/app-sections/app-admin--admin-tokens.css', 'SHADOW EXTRACT from app-admin.css', 'admin-tokens shadow present');
assertContains('assets/css/app-sections/app-member--mem-utils.css', 'SHADOW EXTRACT from app-member.css', 'mem-utils shadow present');
assertContains('assets/css/app-sections/app-public--header-v2.css', 'SHADOW EXTRACT from app-public.css', 'header-v2 shadow present');
assertContains('assets/css/app-sections/app-core--design-tokens.css', 'SHADOW EXTRACT from app-core.css', 'design-tokens shadow present');
assertContains('assets/css/app-sections/app-core--coop-core.css', 'SHADOW EXTRACT from app-core.css', 'coop-core second-wave shadow present');
assertContains('assets/css/app-sections/app-member--member.css', 'SHADOW EXTRACT from app-member.css', 'member.css second-wave shadow present');
assertContains('assets/css/app-sections/app-core--unified-portal.css', 'SHADOW EXTRACT from app-core.css', 'unified-portal second-wave shadow present');
assertContains('assets/css/app-sections/app-member--theme-overrides-v4.css', 'SHADOW EXTRACT from app-member.css', 'member theme-overrides-v4 third-wave shadow present');
{
    $cmd = 'python3 ' . escapeshellarg($root . '/scripts/extract-app-css-section.py') . ' --verify 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        fail('app-sections shadow verify failed — ' . implode(' ', $out));
    } else {
        ok('app-sections shadow verify');
    }
}
assertContains('includes/footer.php', 'data-lucide="messages-square"', 'footer chatbot Lucide');

assertContains('includes/header.php', 'data-lucide="layout-grid"', 'header tools icon Lucide');

assertContains('includes/header.php', 'data-lucide="moon"', 'header theme toggle Lucide');
assertNotContains('includes/header.php', 'fas fa-bars', 'header no FA hamburger');

assertNotContains('includes/header.php', 'fas fa-download', 'header no FA download in chrome');

assertNotContains('install.php', "family=Mukta", 'install wizard no Mukta Google font');
assertContains('install.php', 'coopThemeGoogleFonts()', 'install fonts via SSOT helper');
assertContains('install.php', 'family=Inter', 'install wizard uses Inter');
assertContains('404.php', 'data-lucide="house"', '404 home Lucide');
assertContains('includes/header.php', "htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8')", 'header SITE_URL escaped');


assertContains('admin/db-setup.php', "coopThemeLink('assets/css/admin-db-setup-page.css')", 'db-setup loads extracted CSS');
assertContains('assets/css/admin-db-setup-page.css', '.bootstrap-banner', 'db-setup banner styles present');

assertContains('includes/header.php', '--pfl-mobile-logo: url(', 'header dynamic CSS vars on html style');
assertContains('includes/header.php', "coopThemeLink('assets/css/public-header-shell.css')", 'header shell CSS extracted');
assertContains('member/includes/chrome.php', 'data-lucide="house"', 'member chrome Lucide marker');
assertContains('assets/css/admin-shell-polish.css', 'Lucide size/spin utilities', 'lucide size utilities in admin shell');
assertContains('assets/css/public-shell-polish.css', 'Lucide size/spin utilities', 'lucide size utilities in public shell');
assertContains('assets/css/lucide-icon-utils.css', 'lucide-spin-kf', 'shared Lucide size/spin utilities file');
assertContains('scripts/build-css-late-bundles.py', 'lucide-icon-utils.css', 'late-bundle sources include Lucide utils');
assertContains('assets/css/public-late-bundle.css', 'BEGIN lucide-icon-utils.css', 'public late bundle embeds Lucide utils');
assertContains('assets/css/admin-late-bundle.css', 'BEGIN lucide-icon-utils.css', 'admin late bundle embeds Lucide utils');
assertContains('assets/css/member-late-bundle.css', 'BEGIN lucide-icon-utils.css', 'member late bundle embeds Lucide utils');
assertContains('assets/css/minimal-late-bundle.css', 'BEGIN lucide-icon-utils.css', 'minimal late bundle embeds Lucide utils');
assertNotContains('assets/css/public-shell-polish.css', '@keyframes lucide-spin-kf', 'public shell no duplicated Lucide spin');
assertNotContains('assets/css/admin-shell-polish.css', '@keyframes lucide-spin-kf', 'admin shell no duplicated Lucide spin');
assertNotContains('assets/css/member-shell-polish.css', '@keyframes lucide-spin-kf', 'member shell no duplicated Lucide spin');
assertContains('includes/theme-assets.php', 'lucide-icon-utils.css', 'theme hub documents Lucide utils');
assertContains('scripts/inventory-css-deep-audit.py', 'css-deep-audit.json', 'CSS deep audit inventory script');
assertNotContains('verify.php', 'fonts.googleapis.com', 'verify fonts via SSOT only (no rogue Google link)');

assertContains('online-kyc.php', 'assets/css/kyc-capture.css', 'online-kyc loads kyc-capture.css');
assertContains('online-kyc.php', 'assets/js/kyc-capture.js?v=10.11', 'online-kyc capture js version');
assertContains('member/profile.php', 'assets/js/kyc-capture.js?v=10.11', 'member profile capture js synced');

// KYC soft polish markers
assertContains('online-kyc.php', 'id="kymWizardNav"', 'wizard nav id');
assertContains('online-kyc.php', 'aria-label="<?php echo isEnglish() ? \'KYM sections\'', 'wizard nav aria-label');
assertContains('online-kyc.php', "b.setAttribute('aria-current', 'step')", 'wizard step aria-current');
assertContains('online-kyc.php', 'id="kymNextBtn" aria-label=', 'next button aria-label');
assertContains('online-kyc.php', 'kymWizardBusy', 'wizard busy / double-advance guard');
assertContains('online-kyc.php', "submitBtn.setAttribute('aria-busy', 'true')", 'submit aria-busy on submit');
assertContains('online-kyc.php', 'kymFocusOnStep', 'wizard focus only after user navigation');

foreach (['includes/theme-assets.php', 'includes/header.php', 'institutional-profile.php', 'sahakari-patro.php', 'verify.php', 'includes/satisfaction-widget.php', 'team.php', 'cooperative-programs.php', 'services.php', 'committees.php', 'auction.php', 'appointment.php', 'online-kyc.php', 'member/profile.php', 'member/password-reset-request.php', 'member/login.php', 'member/id-card.php', 'member/welfare.php', 'member/service-request.php', 'member/appointment.php', 'member/marketplace.php', 'member/index.php', 'member/certificate.php', 'member/scan.php', 'member/attend.php', 'member/election-vote.php', 'member/apply-frame.php', 'member/kyc-print.php', 'includes/member-prefill-block.php', 'includes/site-license.php', 'core/init.php', 'admin/program-registration-desk.php', 'admin/reports.php', 'admin/settings.php', 'admin/site-license-blocked.php', 'admin/db-setup.php', 'admin/print-form.php', 'admin/includes/admin-header.php', 'install.php', 'index.php', 'contact.php', 'offline.php', 'member/offline.php', '500.php', 'scripts/build-css-late-bundles.py'] as $f) {
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
