<?php
/**
 * Smoke: public cache-bust + SW navigation strategy (slider/settings freshness).
 * Static checks only — does not boot config.php (avoids local setup gate).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$pass = 0;

function ok(string $msg): void
{
    global $pass;
    $pass++;
    echo "OK  $msg\n";
}

function bad(string $msg): void
{
    global $fail;
    $fail++;
    echo "FAIL $msg\n";
}

$cfg = (string) file_get_contents($root . '/includes/config.php');
if (strpos($cfg, 'function coop_versioned_asset_url') !== false) {
    ok('coop_versioned_asset_url defined in config.php');
} else {
    bad('coop_versioned_asset_url missing from config.php');
}

/* Local copy of bust logic for behavior check (mirrors config helper) */
$versioned = static function (string $path) use ($root): string {
    $path = trim($path);
    if ($path === '' || preg_match('#^(https?:|data:|//)#i', $path)) {
        return $path;
    }
    $rel = ltrim(str_replace('\\', '/', explode('?', $path, 2)[0]), '/');
    $fs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $ver = is_file($fs) ? (string) @filemtime($fs) : '1';
    return $rel . '?v=' . rawurlencode($ver);
};

$v = $versioned('assets/images/logo.png');
if (strpos($v, 'assets/images/logo.png?v=') === 0) {
    ok('versioned logo path shape ok: ' . $v);
} else {
    bad('bad versioned logo: ' . $v);
}
if ($versioned('https://cdn.example/x.png') === 'https://cdn.example/x.png') {
    ok('absolute URL left unchanged');
} else {
    bad('absolute URL was rewritten');
}

$sw = (string) file_get_contents($root . '/sw.js');
if (strpos($sw, 'coop-static-v7') !== false) {
    ok('SW cache bumped to v7');
} else {
    bad('SW still on old cache name');
}
if (strpos($sw, "request.mode === 'navigate'") !== false) {
    ok('SW handles navigate mode');
} else {
    bad('SW missing navigate network-first');
}
if (preg_match("/if \(request\.mode === 'navigate'\) \{[\s\S]*?return;\n  \}/", $sw, $m)
    && strpos($m[0], 'cacheFirst') === false
    && (strpos($m[0], 'networkFirst') !== false || strpos($m[0], 'memberPageStrategy') !== false)
) {
    ok('navigate block uses network-first / member strategy');
} else {
    bad('navigate block missing or still cacheFirst');
}
if (strpos($sw, 'STATIC_EXT_RE') !== false) {
    ok('SW limits cache-first to static extensions');
} else {
    bad('STATIC_EXT_RE missing');
}
/* Ensure "/" is not left as bare cacheFirst fallback */
if (preg_match('/Static assets — cache-first/', $sw) && !preg_match('/STATIC_EXT_RE/', $sw)) {
    bad('old static catch-all still present');
} else {
    ok('no old static catch-all without STATIC_EXT_RE');
}

$idx = (string) file_get_contents($root . '/index.php');
if (strpos($idx, 'coop_versioned_asset_url') !== false) {
    ok('index.php versions slider images');
} else {
    bad('index.php missing versioned slider URLs');
}

$settings = (string) file_get_contents($root . '/admin/settings.php');
if (strpos($settings, 'clearHomepageCache') !== false) {
    ok('settings.php clears homepage cache');
} else {
    bad('settings.php missing clearHomepageCache');
}

$hdr = (string) file_get_contents($root . '/includes/header.php');
if (strpos($hdr, 'coop_versioned_asset_url') !== false) {
    ok('header uses versioned asset helper (favicon)');
} else {
    bad('header favicon bust missing');
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);