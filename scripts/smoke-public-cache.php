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
if (strpos($sw, 'coop-static-v8') !== false) {
    ok('SW cache bumped to v8');
} elseif (preg_match("/const STATIC_CACHE = 'coop-static-v(\d+)'/", $sw, $m)) {
    ok('SW cache version v' . $m[1] . ' (update smoke if bumped)');
} else {
    bad('SW missing coop-static-v* cache name');
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

/* Homepage-affecting admin writers must bust homepage_data_v2 */
$homepageWriters = [
    'admin/reports.php',
    'admin/notices.php',
    'admin/services.php',
    'admin/news.php',
    'admin/sliders.php',
    'admin/interest-rates.php',
    'admin/awards.php',
    'admin/app-features.php',
    'admin/why-choose.php',
    'admin/team.php',
    'admin/member-of-year.php',
    'admin/institutional-profile.php',
];
foreach ($homepageWriters as $rel) {
    $src = (string) @file_get_contents($root . '/' . $rel);
    if ($src === '') {
        bad($rel . ' missing');
        continue;
    }
    if (strpos($src, 'clearHomepageCache') !== false) {
        ok($rel . ' clears homepage cache');
    } else {
        bad($rel . ' missing clearHomepageCache (homepage stale risk)');
    }
}

$rpt = (string) file_get_contents($root . '/admin/reports.php');
if (strpos($rpt, 'bs-fiscal-years.php') !== false) {
    ok('reports.php uses shared bs-fiscal-years helper');
} else {
    bad('reports.php should require bs-fiscal-years.php');
}

$hdr = (string) file_get_contents($root . '/includes/header.php');
if (strpos($hdr, 'coop_versioned_asset_url') !== false) {
    ok('header uses versioned asset helper (favicon)');
} else {
    bad('header favicon bust missing');
}

/* Inventory: every static getCachedData('key') must be cleared by clearHomepageCache (or listed ephemeral) */
$cachePhp = (string) file_get_contents($root . '/includes/simple-cache.php');
if (strpos($cachePhp, 'function coop_homepage_cache_keys') !== false) {
    ok('coop_homepage_cache_keys SSOT present');
} else {
    bad('coop_homepage_cache_keys missing from simple-cache.php');
}

$scanFiles = [
    'index.php',
    'includes/header.php',
    'includes/footer.php',
    'includes/nav-menu-badges.php',
];
$usedKeys = [];
foreach ($scanFiles as $rel) {
    $src = (string) @file_get_contents($root . '/' . $rel);
    if ($src === '') {
        bad('missing scan file ' . $rel);
        continue;
    }
    if (preg_match_all("/getCachedData\\(\\s*'([^']+)'/", $src, $m)) {
        foreach ($m[1] as $k) {
            $usedKeys[$k] = $rel;
        }
    }
}

$ephemeralPrefix = ['visitor_today_', 'nav_notices_extra_v2_'];
$missing = [];
foreach ($usedKeys as $key => $from) {
    $isEphemeral = false;
    foreach ($ephemeralPrefix as $pre) {
        if (str_starts_with($key, $pre)) {
            $isEphemeral = true;
            break;
        }
    }
    if ($isEphemeral) {
        continue;
    }
    if (!preg_match("/['\"]" . preg_quote($key, '/') . "['\"]/", $cachePhp)
        || strpos($cachePhp, 'coop_homepage_cache_keys') === false
        || !preg_match('/function coop_homepage_cache_keys[\\s\\S]*?' . preg_quote($key, '/') . '/', $cachePhp)
    ) {
        /* Prefer exact membership in the keys() array body */
        if (!preg_match('/function coop_homepage_cache_keys\\(\\)[\\s\\S]*?return \\[([\\s\\S]*?)\\];/', $cachePhp, $body)
            || strpos($body[1], "'" . $key . "'") === false) {
            $missing[] = $key . ' (from ' . $from . ')';
        }
    }
}

if ($missing === []) {
    ok('all static getCachedData keys listed in coop_homepage_cache_keys (' . count($usedKeys) . ' scanned)');
} else {
    bad('cache keys missing from coop_homepage_cache_keys: ' . implode(', ', $missing));
}

if (strpos($cachePhp, "nav_notices_extra_v2_") !== false
    && strpos($cachePhp, 'foreach ([-1, 0, 1]') !== false) {
    ok('notice popup cache clears today±1');
} else {
    bad('clearHomepageCache should clear nav_notices_extra_v2_ for today±1');
}

$adminHdr = (string) file_get_contents($root . '/admin/includes/admin-header.php');
$bootPos = strpos($adminHdr, 'coop_require_boot_shared');
$countPos = strpos($adminHdr, "if (!function_exists('core_safe_count'))");
if ($bootPos !== false && $countPos !== false && $bootPos < $countPos) {
    ok('admin-header loads boot-shared before COUNT fallbacks');
} else {
    bad('admin-header should load boot-shared before core_safe_count fallback');
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);