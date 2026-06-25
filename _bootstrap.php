<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * BOOTSTRAP — Single Entry Point for ALL pages
 * ─────────────────────────────────────────────────────────────────────────────
 * यो file सबै requests को शुरुमा load हुन्छ।
 * Database, Auth, Config, Session, Helpers सबै यहाँ setup हुन्छ।
 *
 * USAGE (every page at the very top):
 *   require_once __DIR__ . '/_bootstrap.php';           ← root-level pages
 *   require_once dirname(__DIR__) . '/_bootstrap.php';  ← sub-directory pages
 *
 * DO NOT require 'includes/config.php' directly in individual pages —
 * _bootstrap.php loads it for you.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ─── 0. GUARD — prevent double-bootstrap ─────────────────────────────────────
if (defined('_BOOTSTRAP_LOADED')) return;
define('_BOOTSTRAP_LOADED', true);

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
header('X-XSS-Protection: 1; mode=block');
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
    $__proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    define('SITE_URL', $__proto . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    unset($__proto);
}

if (!defined('SITE_ROOT')) {
    $__sp = dirname($_SERVER['SCRIPT_NAME']);
    define('SITE_ROOT', ($__sp === '/') ? '/' : $__sp . '/');
    unset($__sp);
}

// ─── 7. SESSION ──────────────────────────────────────────────────────────────
// Start exactly once. Guard prevents "headers already sent" from double-start.
if (session_status() === PHP_SESSION_NONE) {
    $__secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '0')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])  && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL'])  === 'on');

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
 * Primary:  getSetting() → site_settings table (static-cached in config.php).
 * Fallback: legacy `settings` table with key/value schema.
 */
if (!function_exists('get_site_setting')) {
    function get_site_setting(string $key, $default = null) {
        if (function_exists('getSetting')) {
            $val = getSetting($key, null);
            if ($val !== null) return $val;
        }
        static $legacy = [];
        if (array_key_exists($key, $legacy)) {
            return $legacy[$key] ?? $default;
        }
        try {
            $db = function_exists('getDB') ? getDB() : null;
            if ($db) {
                $stmt = $db->prepare('SELECT `value` FROM settings WHERE `key` = ? LIMIT 1');
                $stmt->execute([$key]);
                $row = $stmt->fetch();
                $legacy[$key] = $row ? $row['value'] : null;
                return $legacy[$key] ?? $default;
            }
        } catch (Throwable $e) { /* DB may not exist yet — ignore */ }
        return $default;
    }
}

/**
 * set_site_setting(key, value) — write a setting to the database.
 * Uses getDB() (same pattern as get_site_setting — no global $pdo).
 */
if (!function_exists('set_site_setting')) {
    function set_site_setting(string $key, $value): bool {
        try {
            $db = function_exists('getDB') ? getDB() : null;
            if ($db) {
                $db->prepare(
                    'INSERT INTO settings (`key`, `value`, updated_at)
                     VALUES (?, ?, NOW())
                     ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()'
                )->execute([$key, $value]);
                // Clear any session-level cache entry
                unset($_SESSION['site_settings'][$key]);
                return true;
            }
        } catch (Throwable $e) {
            log_error("set_site_setting('{$key}'): " . $e->getMessage());
        }
        return false;
    }
}

// ─── 10. ERROR / EXCEPTION HANDLERS ─────────────────────────────────────────
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
