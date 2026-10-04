#!/usr/bin/env php
<?php
/**
 * Smoke: notice date BS/AD helpers + admin page RBAC map.
 * Run: php scripts/smoke-notice-date-rbac.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/nepali-bs-convert.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/auth-roles.php';

$failed = 0;
$passed = 0;
function check(bool $cond, string $msg): void {
    global $failed, $passed;
    if ($cond) { $passed++; echo "OK  {$msg}\n"; } else { $failed++; echo "FAIL {$msg}\n"; }
}

check(coop_notice_date_bs('2083-06-18') === '2083-06-18', 'BS stays BS');
check(coop_notice_date_bs('2026-10-04') === '2083-06-18', 'legacy AD → BS for display');
check(coop_notice_date_ad('2083-06-18') === '2026-10-04', 'BS → AD for sitemap/JSON-LD');
check(coop_notice_date_ad('2026-10-04') === '2026-10-04', 'AD stays AD');
check(coop_notice_date_ad('') === '', 'empty → empty AD');
check(coop_notice_date_normalize_input('') === null, 'empty input → null');
check(coop_notice_date_normalize_input('२०८३/६/१८') === '2083-06-18', 'Nepali digits + slashes normalized');
check(coop_notice_date_normalize_input('2026-10-04') === '2083-06-18', 'AD typed → stored as BS');
check(coop_notice_date_normalize_input('2083-14-40') === false, 'invalid BS rejected');
check(coop_notice_date_normalize_input('hello') === false, 'garbage rejected');
check(coop_notice_bs_day_month('2026-10-04')['month'] === 'असोज', 'homepage card month from legacy AD');

foreach (['settings.php', 'error-log.php', 'audit-log.php', 'app-features.php', 'notification-templates.php'] as $p) {
    check(coop_admin_page_min_role($p) === 'admin', "{$p} needs admin+");
}
foreach (['notices.php', 'news.php', 'gallery.php', 'dashboard.php'] as $p) {
    check(coop_admin_page_min_role($p) === null, "{$p} open to editor/staff");
}
$boot = (string) file_get_contents($root . '/admin/includes/admin-page-boot.php');
check(str_contains($boot, 'coop_admin_page_min_role('), 'admin-page-boot enforces RBAC map');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
