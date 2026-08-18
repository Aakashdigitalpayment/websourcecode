#!/usr/bin/env php
<?php
/**
 * Smoke: member marketplace / skill workers wiring.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$pass = 0;

function ok(string $m): void { global $pass; $pass++; echo "OK  $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "FAIL $m\n"; }

function assertFileContains(string $rel, string $needle, string $label): void
{
    global $root;
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        bad($label . ' — missing file ' . $rel);
        return;
    }
    $src = (string) file_get_contents($path);
    if (strpos($src, $needle) !== false) {
        ok($label);
    } else {
        bad($label . ' — missing "' . $needle . '" in ' . $rel);
    }
}

foreach ([
    'includes/member-marketplace-tables.php',
    'includes/member-marketplace-public-page.php',
    'member-marketplace.php',
    'member-skills.php',
    'member/marketplace.php',
    'admin/member-marketplace.php',
] as $rel) {
    if (is_file($root . '/' . $rel)) {
        ok('exists ' . $rel);
    } else {
        bad('missing ' . $rel);
    }
}

assertFileContains('includes/header.php', 'member-marketplace.php', 'public More menu has marketplace');
assertFileContains('includes/header.php', 'member-skills.php', 'public More menu has skills');
assertFileContains('includes/header.php', "foreach (\$navCmsPages['more']", 'CMS more pages still loop');
assertFileContains('member/includes/chrome.php', 'member/marketplace.php', 'member nav has marketplace');
assertFileContains('admin/includes/admin-header.php', 'member-marketplace.php', 'admin sidebar has marketplace');
assertFileContains('sitemap.php', 'member-marketplace.php', 'sitemap includes marketplace');
assertFileContains('cron-cleanup.php', 'mpExpireStaleListings', 'cron expires listings');
assertFileContains('includes/member-marketplace-tables.php', "status = 'approved'", 'public query requires approval');
assertFileContains('includes/member-marketplace-tables.php', 'available_until', 'expiry column present');

$hdr = (string) file_get_contents($root . '/includes/header.php');
if (substr_count($hdr, 'member-marketplace.php') >= 2) {
    ok('marketplace in both public navs');
} else {
    bad('marketplace missing from a public nav copy');
}

echo "\nPassed: $pass  Failed: $fail\n";
exit($fail > 0 ? 1 : 0);
