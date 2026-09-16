<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * BOOTSTRAP — Public / root-page entry (aligned with core/init public case)
 * ─────────────────────────────────────────────────────────────────────────────
 * Public pages: require_once __DIR__ . '/_bootstrap.php';
 * Member/Admin: use member/_bootstrap.php or admin/_bootstrap.php → core/init.php
 *
 * Safe unify (incremental): this file keeps public session/headers/helpers, then
 * loads the same shared modules as core/init's `public` portal case. Full thin
 * shim to core/init only is deferred (admin CSRF auto-check must not run here).
 *
 * DO NOT require 'includes/config.php' directly in individual pages —
 * _bootstrap.php loads it for you.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ─── 0. GUARD — prevent double-bootstrap ─────────────────────────────────────
if (defined('_BOOTSTRAP_LOADED')) return;
define('_BOOTSTRAP_LOADED', true);

/* Portal tag — matches core/init.php default when unset */
if (!defined('PORTAL')) {
    define('PORTAL', 'public');
}

// ─── 1. ENVIRONMENT — define FIRST, everything below depends on it ────────────
if (!defined('ENVIRONMENT')) {
    $__env = strtolower(trim((string)(getenv('APP_ENV') ?: getenv('APPLICATION_ENV') ?: '')));
    define('ENVIRONMENT', in_array($__env, ['development', 'staging', 'production'], true)
        ? $__env : 'production');
    unset($__env);
}

// Error reporting
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('log_errors_max_len', '1024');
}

// Timezone — Nepal Standard Time (UTC+5:45)
date_default_timezone_set('Asia/Kathmandu');

// Character encoding
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// ─── 2. SECURITY HEADERS — sent once here, never repeat in individual pages ──
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
/* X-XSS-Protection omitted — deprecated; CSP Report-Only is the XSS path */
header('Referrer-Policy: strict-origin-when-cross-origin');

// ─── 3. PATH CONSTANTS ────────────────────────────────────────────────────────
if (!defined('BASEDIR'))      define('BASEDIR',      __DIR__);
if (!defined('ROOT_PATH'))    define('ROOT_PATH',    BASEDIR . '/');
if (!defined('INCLUDES_DIR')) define('INCLUDES_DIR', BASEDIR . '/includes');
if (!defined('CORE_DIR'))     define('CORE_DIR',     BASEDIR . '/core');
if (!defined('ADMIN_DIR'))    define('ADMIN_DIR',    BASEDIR . '/admin');
if (!defined('MEMBER_DIR'))   define('MEMBER_DIR',   BASEDIR . '/member');
if (!defined('ASSETS_DIR'))   define('ASSETS_DIR',   BASEDIR . '/assets');
if (!defined('UPLOADS_DIR'))  define('UPLOADS_DIR',  ASSETS_DIR . '/uploads');
if (!defined('DATABASE_DIR')) define('DATABASE_DIR', BASEDIR . '/database');

// ─── 4. DATABASE CONNECTION ───────────────────────────────────────────────────
// database.local.php is written by the installer.
$__dbConfig = INCLUDES_DIR . '/database.local.php';
if (file_exists($__dbConfig)) {
    require_once $__dbConfig;
}
unset($__dbConfig);

// ─── 5. CORE UTILITIES (config.php) ──────────────────────────────────────────
// config.php provides: getDB(), getSetting(), isEnglish(), getLangStrings(),
// getLangField(), e(), clean_text(), sanitize(), verifyCSRFToken(),
// checkRateLimit(), formatDate(), redirect(), and more.
// Load it BEFORE any legacy core files to avoid duplicate-function fatals.
foreach ([INCLUDES_DIR . '/config.php'] as $__cFile) {
    if (file_exists($__cFile)) {
        require_once $__cFile;
        break;
    }
}
unset($__cFile);

// Optional legacy core files — load only when their sentinel function is absent.
// This keeps older installs compatible without breaking current pages.
$__legacyCoreFiles = [
    CORE_DIR  . '/helpers.php'    => 'clean_int',
    CORE_DIR  . '/auth.php'       => 'requireLogin',
    CORE_DIR  . '/validation.php' => 'validateRequired',
    INCLUDES_DIR . '/helpers.php' => 'clean_int',
    INCLUDES_DIR . '/auth.php'    => 'requireLogin',
];
foreach ($__legacyCoreFiles as $__legFile => $__sentinel) {
    if (file_exists($__legFile) && !function_exists($__sentinel)) {
        require_once $__legFile;
    }
}
unset($__legacyCoreFiles, $__legFile, $__sentinel);

// Member auth (requireMemberLogin, memberIsLoggedIn, memberSetSession, etc.)
foreach ([BASEDIR . '/includes/member-auth.php'] as $__mAuth) {
    if (file_exists($__mAuth)) {
        require_once $__mAuth;
        break;
    }
}
unset($__mAuth);

// ─── 6. SITE URL / ROOT ──────────────────────────────────────────────────────
if (!defined('SITE_URL')) {
    $__proto = (function_exists('coop_request_is_https') && coop_request_is_https())
        ? 'https://'
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://');
    $__host = preg_replace('/[\x00-\x1f\x7f\/\\\\]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?? '';
    if ($__host === '' || !preg_match('/^(\[[0-9a-fA-F:]+\]|[A-Za-z0-9.-]+)(:\d{1,5})?$/', $__host)) {
        $__host = 'localhost';
    }
    $__hostOnly = strtolower((string) preg_replace('/:\d+$/', '', $__host));
    $__hostOnly = trim($__hostOnly, '[]');
    $__allowed = [];
    if (defined('SITE_ALLOWED_HOSTS')) {
        foreach (explode(',', (string) SITE_ALLOWED_HOSTS) as $__ah) {
            $__ah = strtolower(trim($__ah));
            if ($__ah !== '') {
                $__allowed[] = $__ah;
            }
        }
    }
    $__local = in_array($__hostOnly, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($__hostOnly, '.localhost')
        || str_ends_with($__hostOnly, '.local');
    if ($__allowed !== [] && !$__local && !in_array($__hostOnly, $__allowed, true)) {
        $__host = $__allowed[0];
    }
    define('SITE_URL', $__proto . $__host . '/');
    unset($__proto, $__host, $__hostOnly, $__allowed, $__local, $__ah);
}

if (!defined('SITE_ROOT')) {
    $__sp = dirname($_SERVER['SCRIPT_NAME']);
    define('SITE_ROOT', ($__sp === '/') ? '/' : $__sp . '/');
    unset($__sp);
}

// ─── 7. SESSION ──────────────────────────────────────────────────────────────
// Start exactly once. Guard prevents "headers already sent" from double-start.
if (session_status() === PHP_SESSION_NONE) {
    $__secure = function_exists('coop_request_is_https')
        ? coop_request_is_https()
        : ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '0')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])  && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL'])  === 'on'));

    @ini_set('session.use_trans_sid', '0');
    @ini_set('session.cookie_secure',   $__secure ? '1' : '0');
    @ini_set('session.cookie_samesite', 'Lax');

    session_start([
        'use_strict_mode'        => 1,
        'use_only_cookies'       => 1,
        'cookie_httponly'        => 1,
        'cookie_secure'          => $__secure,
        'cookie_samesite'        => 'Lax',
        'sid_length'             => 48,
        'sid_bits_per_character' => 6,
    ]);
    unset($__secure);
}

if (!isset($_SESSION['session_created'])) {
    $_SESSION['session_created'] = time();
}

// ─── 8. ERROR LOGGING ────────────────────────────────────────────────────────
if (!function_exists('log_error')) {
    function log_error(string $message, string $level = 'ERROR'): void {
        $logFile = BASEDIR . '/logs/error.log';
        if (!is_dir(dirname($logFile))) {
            @mkdir(dirname($logFile), 0755, true);
        }
        @error_log('[' . date('Y-m-d H:i:s') . "] [{$level}] {$message}\n", 3, $logFile);
    }
}

// ─── 9. GLOBAL HELPERS ───────────────────────────────────────────────────────

/**
 * t(np, en) — Bilingual text helper. Single, global replacement for the
 * per-page  `$_t = static function(string $np, string $en): string { ... }`
 * closures that were duplicated across login.php, attend.php, oauth.php, etc.
 *
 * Usage:   echo t('नेपाली', 'English');
 */
if (!function_exists('t')) {
    function t(string $np, string $en): string {
        return (function_exists('isEnglish') && isEnglish()) ? $en : $np;
    }
}

/**
 * e(value) — HTML-escape shorthand. Always use for user-facing output.
 * Defined here as a global fallback; config.php may define it first.
 *
 * Usage:   <?= e($row['title']) ?>
 */
if (!function_exists('e')) {
    function e($val): string {
        return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * get_site_setting(key, default) — unified setting lookup.
 * Uses site_settings via getSetting() (canonical). Legacy `settings` table
 * is not created by the app and is intentionally not queried.
 */
if (!function_exists('get_site_setting')) {
    function get_site_setting(string $key, $default = null) {
        if (function_exists('getSetting')) {
            return getSetting($key, $default);
        }
        return $default;
    }
}

/**
 * set_site_setting(key, value) — write via updateSetting() → site_settings.
 */
if (!function_exists('set_site_setting')) {
    function set_site_setting(string $key, $value): bool {
        try {
            if (function_exists('updateSetting')) {
                return (bool) updateSetting($key, $value);
            }
        } catch (Throwable $e) {
            if (function_exists('log_error')) {
                log_error("set_site_setting('{$key}'): " . $e->getMessage());
            }
        }
        return false;
    }
}

// ─── 10. SHARED MODULES (SSOT: includes/boot-shared.php) ──────────────────────
// Skip when core/init already ran. Do not run admin CSRF here.
if (!defined('CORE_INIT_LOADED')) {
    $__bootSharedFile = INCLUDES_DIR . '/boot-shared.php';
    if (is_file($__bootSharedFile)) {
        require_once $__bootSharedFile;
        if (function_exists('coop_require_boot_shared')) {
            coop_require_boot_shared();
        }
    }
    unset($__bootSharedFile);

    if (function_exists('site_license_public_guard')) {
        site_license_public_guard();
    }
}

// ─── 11. ERROR / EXCEPTION HANDLERS ─────────────────────────────────────────
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    log_error("PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}", 'PHP');
    return true; // suppress PHP's default handler
});

set_exception_handler(static function (Throwable $ex): void {
    log_error(
        'Uncaught ' . get_class($ex) . ': ' . $ex->getMessage()
        . ' in ' . $ex->getFile() . ':' . $ex->getLine(),
        'EXCEPTION'
    );
    if (defined('ENVIRONMENT') && ENVIRONMENT !== 'production') {
        throw $ex;
    }
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 500 Internal Server Error', true, 500);
    $page500 = (defined('BASEDIR') ? BASEDIR : __DIR__) . '/500.php';
    if (file_exists($page500)) {
        include $page500;
    } else {
        echo '<h1>500 — Server Error</h1>'
           . '<p>कृपया पछि पुन: प्रयास गर्नुहोस् / Please try again later.</p>';
    }
    exit;
});

// ─── BOOTSTRAP COMPLETE ──────────────────────────────────────────────────────
