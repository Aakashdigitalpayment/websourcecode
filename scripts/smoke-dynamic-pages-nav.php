#!/usr/bin/env php
<?php
/**
 * Smoke: dynamic CMS pages appear in header-v2 About/Services/More menus.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$pass = 0;

function ok(string $m): void { global $pass; $pass++; echo "OK  $m\n"; }
function bad(string $m): void { global $fail; $fail++; echo "FAIL $m\n"; }

$hdr = (string) file_get_contents($root . '/includes/header.php');
$pages = (string) file_get_contents($root . '/admin/pages.php');
$cache = (string) file_get_contents($root . '/includes/simple-cache.php');

if (strpos($hdr, 'function coop_nav_cms_page_li') !== false) {
    ok('CMS nav helper defined');
} else {
    bad('CMS nav helper missing');
}
if (strpos($hdr, "nav_cms_pages_v2") !== false) {
    ok('nav CMS cache v2 (includes menu_icon)');
} else {
    bad('nav CMS cache not bumped');
}
if (substr_count($hdr, "foreach (\$navCmsPages['about']") >= 2) {
    ok('About CMS pages in v2 + legacy nav');
} else {
    bad('About CMS pages missing from a nav');
}
if (strpos($hdr, "mainNavV2") !== false
    && preg_match('/id="mainNavV2"[\s\S]{0,8000}foreach \(\$navCmsPages\[\'about\'\]/', $hdr)) {
    ok('header-v2 About dropdown includes CMS pages');
} else {
    bad('header-v2 About missing CMS loop');
}
if (preg_match('/id="mainNavV2"[\s\S]{0,40000}foreach \(\$navCmsPages\[\'more\'\]/', $hdr)) {
    ok('header-v2 More dropdown includes CMS pages');
} else {
    bad('header-v2 More missing CMS loop');
}
if (strpos($pages, 'js-fa-icon-picker') !== false && strpos($pages, 'menu_icon') !== false) {
    ok('dynamic page form has icon picker');
} else {
    bad('icon picker missing on dynamic form');
}
if (strpos($pages, 'new page: default ON') !== false || strpos($pages, 'default ON so About') !== false) {
    ok('new dynamic pages default show_in_menu ON');
} else {
    bad('show_in_menu still defaults off for new pages');
}
if (strpos($pages, 'type="submit"') !== false && strpos($pages, 'यो पृष्ठ मेटाउने हो') !== false) {
    ok('delete button is submit (not inert type=button)');
} else {
    bad('delete still broken type=button');
}
if (strpos($hdr, 'rawurlencode($slug)') !== false && strpos($hdr, "str_contains(\$slug, '..')") !== false) {
    ok('CMS nav keeps unicode slugs and blocks URL/path injection');
} else {
    bad('CMS nav slug hardening missing');
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
