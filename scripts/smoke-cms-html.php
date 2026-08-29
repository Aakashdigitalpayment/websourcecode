#!/usr/bin/env php
<?php
/**
 * Smoke: CMS HTML sanitizer markers + mirrored behavior (no DB bootstrap).
 * Run: php scripts/smoke-cms-html.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$pass = 0;

function ok(string $msg): void
{
    global $pass;
    $pass++;
    echo "OK  {$msg}\n";
}

function bad(string $msg): void
{
    global $fail;
    $fail++;
    echo "FAIL {$msg}\n";
}

/** Mirror includes/config.php coop_sanitize_cms_html — keep aligned on change */
function smoke_cms_sanitize(?string $html): string
{
    $html = trim((string) $html);
    if ($html === '') {
        return '';
    }
    $allowed = '<p><br><br/><strong><b><em><i><u><ul><ol><li><h2><h3><h4><h5><h6>'
        . '<a><img><table><thead><tbody><tr><th><td><blockquote><hr><span><div><sub><sup>';
    $clean = strip_tags($html, $allowed);
    $clean = preg_replace('/\s(on\w+|formaction)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $clean) ?? $clean;
    $clean = preg_replace('/(<(?:a|img)\b[^>]*\s(?:href|src)\s*=\s*["\']?)\s*javascript:[^"\'>\s]*/iu', '$1#', $clean) ?? $clean;
    return $clean;
}

/** Mirror includes/config.php coop_render_cms_prose — keep aligned on change */
function smoke_cms_render_prose(?string $html): string
{
    $html = trim((string) $html);
    if ($html === '') {
        return '';
    }
    if ($html === strip_tags($html)) {
        return '<p>' . nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'), false) . '</p>';
    }
    return smoke_cms_sanitize($html);
}

$config = (string) file_get_contents($root . '/includes/config.php');
if (strpos($config, 'function coop_sanitize_cms_html') === false) {
    bad('includes/config.php: coop_sanitize_cms_html missing');
} else {
    ok('includes/config.php: coop_sanitize_cms_html defined');
}
if (strpos($config, 'function coop_render_cms_prose') === false) {
    bad('includes/config.php: coop_render_cms_prose missing');
} else {
    ok('includes/config.php: coop_render_cms_prose defined');
}

foreach ([
    ['page.php', 'coop_sanitize_cms_html', 'cms page body sanitized'],
    ['news-detail.php', 'coop_sanitize_cms_html', 'news detail sanitized'],
    ['notices.php', 'coop_sanitize_cms_html', 'notice detail sanitized'],
    ['about.php', 'coop_render_cms_prose', 'about prose rendered'],
    ['about.php', 'safe_versioned_media_src', 'about visual safe src'],
    ['awards.php', 'coop_render_cms_prose', 'award description prose rendered'],
    ['career.php', 'coop_render_cms_prose', 'career list excerpt prose'],
    ['faqs.php', "e(isEnglish()", 'faq question escaped'],
    ['career-detail.php', 'coop_render_cms_prose', 'job description prose rendered'],
    ['career-detail.php', 'safe_media_src', 'job attachment safe src'],
    ['includes/header.php', 'safe_versioned_media_src_absolute', 'header himal absolute bg url'],
    ['admin/includes/admin-header.php', 'safe_versioned_media_src($siteLogo)', 'admin logo safe src'],
    ['admin/team.php', 'safe_media_src', 'team photo safe src'],
    ['admin/auctions.php', 'safe_media_src', 'auction media safe src'],
    ['admin/member-of-year.php', 'safe_media_src', 'member-of-year photo safe src'],
    ['admin/welfare-claims.php', 'safe_media_src', 'welfare certificate safe src'],
    ['admin/account-applications.php', 'safe_media_src', 'account doc safe src'],
    ['admin/job-applications.php', 'safe_media_src', 'job application docs safe src'],
    ['index.php', 'e($heroTitle)', 'hero title escaped'],
    ['includes/footer.php', 'coop_render_cms_prose', 'footer about prose rendered'],
    ['includes/information-room-tables.php', 'coop_client_ip', 'information room log ip'],
] as [$file, $needle, $why]) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        bad("{$file}: missing ({$why})");
        continue;
    }
    $t = (string) file_get_contents($path);
    if (strpos($t, $needle) === false) {
        bad("{$file}: missing `{$needle}` ({$why})");
    } else {
        ok("{$file}: {$why}");
    }
}

$xss = '<p>Hello</p><script>alert(1)</script><img src=x onerror=alert(1)>';
$out = smoke_cms_sanitize($xss);
if (stripos($out, '<script') !== false || stripos($out, 'onerror') !== false) {
    bad('mirrored sanitizer: script/onerror not stripped');
} else {
    ok('mirrored sanitizer: script/onerror removed');
}

if (stripos($out, '<p>Hello</p>') === false) {
    bad('mirrored sanitizer: allowed p tag removed');
} else {
    ok('mirrored sanitizer: allowed p tag preserved');
}

$linkOut = smoke_cms_sanitize('<a href="javascript:alert(1)">x</a>');
if (stripos($linkOut, 'javascript:') !== false) {
    bad('mirrored sanitizer: javascript href not neutralized');
} else {
    ok('mirrored sanitizer: javascript href neutralized');
}

$plainOut = smoke_cms_render_prose("Line one\nLine two");
if (strpos($plainOut, '<p>') === false || strpos($plainOut, '<br') === false) {
    bad('mirrored prose: plain text not wrapped in paragraph');
} else {
    ok('mirrored prose: plain text wrapped with nl2br');
}

$htmlProseOut = smoke_cms_render_prose('<p>Rich</p><script>x</script>');
if (stripos($htmlProseOut, '<script') !== false) {
    bad('mirrored prose: html path did not sanitize');
} elseif (stripos($htmlProseOut, '<p>Rich</p>') === false) {
    bad('mirrored prose: allowed html not preserved');
} else {
    ok('mirrored prose: html sanitized and preserved');
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
