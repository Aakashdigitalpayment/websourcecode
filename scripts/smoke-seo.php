#!/usr/bin/env php
<?php
/**
 * Smoke test: SEO basics — robots, sitemap, header meta, rewrites.
 * Run: php scripts/smoke-seo.php
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

// Rewrite rules for crawler-friendly URLs
assertFileContains('.htaccess', 'RewriteRule ^includes/', 'block includes');
assertFileContains('.htaccess', 'RewriteRule ^cache/', 'block cache');
assertFileContains('.htaccess', 'RewriteRule ^logs/', 'block logs');
assertFileContains('.htaccess', 'RewriteRule ^scripts/', 'block scripts');
assertFileContains('.htaccess', 'RewriteRule ^database/', 'block database');
assertFileContains('.htaccess', 'RewriteRule ^core/', 'block core');
assertFileContains('.htaccess', 'RewriteRule ^sitemap\\.xml$ sitemap.php', 'sitemap.xml rewrite');
assertFileContains('.htaccess', 'RewriteRule ^robots\\.txt$ robots.php', 'robots.txt rewrite');
assertFileContains('admin/pages.php', 'tinymce@6.8.5/tinymce.min.js', 'TinyMCE pinned version');

// robots.php policy
$robotsNeedles = [
    'Disallow: /admin/' => 'block admin',
    'Disallow: /member/' => 'block member portal',
    'Disallow: /includes/' => 'block includes',
    'Disallow: /cache/' => 'block cache',
    'Disallow: /core/' => 'block core',
    'Disallow: /assets/uploads/kyc/' => 'block kyc uploads',
    'Disallow: /assets/uploads/honor_applications/' => 'block honor uploads',
    'Disallow: /assets/uploads/admin-replies/' => 'block admin-replies uploads',
    'Disallow: /assets/uploads/hrm/' => 'block hrm uploads',
    'Disallow: /online-kyc.php' => 'block online-kyc utility',
    'Sitemap: ' => 'sitemap directive echoed',
];
foreach ($robotsNeedles as $needle => $why) {
    assertFileContains('robots.php', $needle, $why);
}

// sitemap.php structure
assertFileContains('sitemap.php', 'application/xml', 'sitemap XML content-type');
assertFileContains('sitemap.php', 'about.php', 'sitemap includes about');
assertFileContains('sitemap.php', 'services.php', 'sitemap includes services');
assertFileContains('sitemap.php', '<urlset', 'sitemap writes urlset');

// Public header SEO / a11y anchors
assertFileContains('includes/header.php', 'rel="canonical"', 'canonical link');
assertFileContains('includes/header.php', 'name="description"', 'meta description');
assertFileContains('includes/header.php', 'property="og:title"', 'og:title');
assertFileContains('includes/header.php', 'name="twitter:card"', 'twitter card');
assertFileContains('includes/header.php', 'class="skip-link"', 'skip link');
assertFileContains('includes/header.php', 'id="main-content"', 'main landmark id');

// Multi-coop SEO: no hardcoded city; settings-driven tagline/city; richer schema
assertFileContains('includes/config.php', 'seo_tagline', 'title uses seo_tagline setting');
assertFileContains('includes/config.php', 'site_city', 'title/schema use site_city');
assertFileContains('includes/config.php', 'CreditUnion', 'Organization schema includes CreditUnion');
assertFileContains('includes/config.php', 'openingHoursSpecification', 'hours in Organization schema when set');
assertFileContains('includes/config.php', 'hasMap', 'map URL in Organization schema when set');
$cfg = (string) file_get_contents($root . '/includes/config.php');
if (strpos($cfg, "'Pokhara'") === false && strpos($cfg, '"Pokhara"') === false && strpos($cfg, 'पोखरा') === false) {
    ok('includes/config.php: no hardcoded Pokhara/पोखरा in SEO title path');
} else {
    fail('includes/config.php: still hardcodes Pokhara/पोखरा');
}
assertFileContains('admin/settings.php', 'seo_tagline', 'admin can save seo_tagline');
assertFileContains('admin/settings.php', 'site_city', 'admin can save site_city');
assertFileContains('admin/settings.php', 'sitemap.xml', 'GSC tip uses sitemap.xml');
assertFileContains('sitemap.php', 'filemtime', 'static sitemap lastmod from filemtime');
assertFileContains('committees.php', '$pageDescription', 'committees unique meta description');
assertFileContains('loan-apply.php', '$pageDescription', 'loan-apply unique meta description');
assertFileContains('emi-calculator.php', '$pageDescription', 'emi-calculator unique meta description');
assertFileContains('institutional-profile.php', '$pageDescription', 'institutional-profile unique meta description');

// Print control should be a button (not href="#")
assertFileContains('member/kyc-print.php', 'onclick="window.print();"', 'kyc print action');
assertFileContains('member/kyc-print.php', '<button type="button" class="btn"', 'kyc print is button');

$lint = ['robots.php', 'sitemap.php', 'includes/header.php', 'member/kyc-print.php', 'admin/settings.php'];
foreach ($lint as $f) {
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
