<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UNIFIED BOOTSTRAP — Single Source of Truth v3.0
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * REPLACES the three separate bootstrap files:
 *   - _bootstrap.php (root)
 *   - admin/_bootstrap.php
 *   - member/_bootstrap.php
 *
 * This is the SINGLE entry point for application initialization.
 *
 * USAGE:
 *   // In root _bootstrap.php:
 *   require_once __DIR__ . '/includes/bootstrap-unified.php';
 *   bootstrapApplication('public');
 *
 *   // In admin/_bootstrap.php:
 *   require_once dirname(__DIR__) . '/includes/bootstrap-unified.php';
 *   bootstrapApplication('admin');
 *
 *   // In member/_bootstrap.php:
 *   require_once dirname(__DIR__) . '/includes/bootstrap-unified.php';
 *   bootstrapApplication('member');
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 */

// ───────────────────────────────────────────────────────────────────────────────
// Initialize Application Bootstrap
// ───────────────────────────────────────────────────────────────────────────────

if (!function_exists('log_error')) {
    function log_error($message, $level = 'ERROR') {
        $logDir = BASEDIR . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/error.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [{$level}] {$message}\n";

        @error_log($logEntry, 3, $logFile);
    }
}

/**
 * bootstrapApplication($context)
 *
 * Universal bootstrap function that initializes the entire application
 * for the specified context (public, admin, member).
 *
 * @param string $context — 'public', 'admin', or 'member'
 * @return void
 */
function bootstrapApplication($context = 'public') {
    // ─────────────────────────────────────────────────────────
    // STEP 1: ENVIRONMENT & ERROR SETUP
    // ─────────────────────────────────────────────────────────

    // Define application environment
    if (!defined('ENVIRONMENT')) {
        $env = strtolower(trim((string)(getenv('APP_ENV') ?: getenv('APPLICATION_ENV') ?: '')));
        define('ENVIRONMENT', in_array($env, ['development', 'staging', 'production'], true) ? $env : 'production');
    }

    // Set error reporting based on environment
    if (ENVIRONMENT === 'development') {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
    } else {
        error_reporting(E_ALL);
        ini_set('display_errors', 0);
        ini_set('display_startup_errors', 0);
        ini_set('log_errors', 1);
        ini_set('log_errors_max_len', 1024);
    }

    // ─────────────────────────────────────────────────────────
    // STEP 2: APPLICATION CONTEXT
    // ─────────────────────────────────────────────────────────

    if (!defined('APP_CONTEXT')) {
        define('APP_CONTEXT', $context); // 'public', 'admin', 'member'
    }

    // Set context-specific flags
    if (APP_CONTEXT === 'admin') {
        define('IS_ADMIN_PAGE', true);
    } elseif (APP_CONTEXT === 'member') {
        define('IS_MEMBER_PAGE', true);
    } else {
        define('IS_PUBLIC_PAGE', true);
    }

    // ─────────────────────────────────────────────────────────
    // STEP 3: TIMEZONE & LOCALIZATION
    // ─────────────────────────────────────────────────────────

    date_default_timezone_set('Asia/Kathmandu');
    mb_internal_encoding('UTF-8');
    header('Content-Type: text/html; charset=utf-8');

    // ─────────────────────────────────────────────────────────
    // STEP 4: PATH DEFINITIONS
    // ─────────────────────────────────────────────────────────

    if (!defined('BASEDIR')) {
        define('BASEDIR', dirname(__DIR__));
    }

    if (!defined('INCLUDES_DIR')) {
        define('INCLUDES_DIR', BASEDIR . '/includes');
    }

    if (!defined('ADMIN_DIR')) {
        define('ADMIN_DIR', BASEDIR . '/admin');
    }

    if (!defined('MEMBER_DIR')) {
        define('MEMBER_DIR', BASEDIR . '/member');
    }

    if (!defined('ASSETS_DIR')) {
        define('ASSETS_DIR', BASEDIR . '/assets');
    }

    if (!defined('UPLOADS_DIR')) {
        define('UPLOADS_DIR', ASSETS_DIR . '/uploads');
    }

    if (!defined('CORE_DIR')) {
        define('CORE_DIR', BASEDIR . '/core');
    }

    // ─────────────────────────────────────────────────────────
    // STEP 5: LOAD CORE CONFIGURATION
    // ─────────────────────────────────────────────────────────

    $configFile = INCLUDES_DIR . '/config.php';
    if (file_exists($configFile)) {
        require_once $configFile;
    }

    // ─────────────────────────────────────────────────────────
    // STEP 6: DEFINE SITE URLS
    // ─────────────────────────────────────────────────────────

    if (!defined('SITE_URL')) {
        $protocol = 'http';
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '0') {
            $protocol = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            $protocol = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            $protocol = 'https';
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:5000';
        define('SITE_URL', $protocol . '://' . $host . '/');
        define('ADMIN_URL', SITE_URL . 'admin/');
    }

    // ─────────────────────────────────────────────────────────
    // STEP 7: SESSION MANAGEMENT
    // ─────────────────────────────────────────────────────────

    if (session_status() === PHP_SESSION_NONE) {
        $_isSecure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '0')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on');

        @ini_set('session.use_trans_sid', '0');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.cookie_secure', $_isSecure ? '1' : '0');
        @ini_set('session.cookie_samesite', 'Lax');

        session_start([
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
            'cookie_httponly' => 1,
            'cookie_secure' => $_isSecure,
            'cookie_samesite' => 'Lax',
            'sid_length' => 48,
            'sid_bits_per_character' => 6,
        ]);

        if (!isset($_SESSION['session_created'])) {
            $_SESSION['session_created'] = time();
        }
    }

    // ─────────────────────────────────────────────────────────
    // STEP 8: SECURITY HEADERS
    // ─────────────────────────────────────────────────────────

    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');

    // Prevent MIME sniffing
    header('X-Content-Type-Options: nosniff');

    // Enable XSS protection
    header('X-XSS-Protection: 1; mode=block');

    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // ─────────────────────────────────────────────────────────
    // STEP 9: ERROR LOGGING
    // ─────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────
    // STEP 10: CONTEXT-SPECIFIC BOOTSTRAP
    // ─────────────────────────────────────────────────────────

    switch (APP_CONTEXT) {
        case 'admin':
            _bootstrap_admin();
            break;

        case 'member':
            _bootstrap_member();
            break;

        default:
            _bootstrap_public();
            break;
    }

    // ─────────────────────────────────────────────────────────
    // STEP 11: ERROR HANDLERS
    // ─────────────────────────────────────────────────────────

    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        log_error("PHP Error: {$errstr} in {$errfile} on line {$errline}", 'PHP');
        return true;
    });

    set_exception_handler(function($exception) {
        log_error("Exception: " . $exception->getMessage(), 'EXCEPTION');

        if (ENVIRONMENT !== 'production' && APP_CONTEXT !== 'admin') {
            throw $exception;
        }

        header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);

        $errorPage = BASEDIR . '/500.php';
        if (file_exists($errorPage)) {
            include $errorPage;
        } else {
            echo '500 Internal Server Error';
        }

        exit;
    });
}

// ───────────────────────────────────────────────────────────────────────────────
// Context-Specific Bootstrap Functions
// ───────────────────────────────────────────────────────────────────────────────

/**
 * _bootstrap_admin()
 * Admin panel specific initialization
 */
function _bootstrap_admin() {
    // Admin error handler: friendly error page
    register_shutdown_function(function() {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        if (headers_sent()) {
            echo "\n<!-- Fatal: see server error log -->\n";
            return;
        }

        @http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');

        $hostLc = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $allowDebugUrl = str_starts_with($hostLc, '127.0.0.1')
            || str_starts_with($hostLc, 'localhost')
            || str_starts_with($hostLc, '[::1]');
        $isDebug = $allowDebugUrl && isset($_GET['debug']) && (string) $_GET['debug'] === '1';
        $msg = $isDebug
            ? htmlspecialchars($err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line'])
            : 'अप्रत्याशित त्रुटि भयो। कृपया पछि पुनः प्रयास गर्नुहोस्।';

        log_error('[admin-panel-fatal] ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);

        echo '<!DOCTYPE html><html lang="ne"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>त्रुटि — Admin Panel</title>';
        echo '<style>';
        echo 'body{margin:0;background:linear-gradient(135deg,#fef2f2,#fee2e2);min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:"Mukta","Noto Sans Devanagari","Segoe UI",sans-serif;padding:20px}';
        echo '.err-box{max-width:480px;background:#fff;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.1);padding:32px;text-align:center}';
        echo '.err-icon{width:72px;height:72px;background:#fee2e2;color:#b91c1c;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 18px}';
        echo 'h1{color:#1f2937;font-size:1.25rem;margin:0 0 10px}';
        echo 'p{color:#6b7280;font-size:.9rem;line-height:1.6;margin:0 0 22px}';
        echo '.err-detail{background:#fef2f2;color:#991b1b;padding:10px 14px;border-radius:8px;font-family:monospace;font-size:.78rem;margin:14px 0;text-align:left;border:1px solid #fecaca;word-break:break-all}';
        echo '.err-btn{display:inline-block;background:#1a5f2a;color:#fff;text-decoration:none;padding:11px 26px;border-radius:10px;font-weight:600;font-size:.88rem}';
        echo '</style></head><body>';
        echo '<div class="err-box"><div class="err-icon">⚠</div><h1>केहि गलत भयो</h1><p>Admin panel मा अप्रत्याशित त्रुटि भयो।</p>';
        if ($isDebug) echo '<div class="err-detail">' . $msg . '</div>';
        echo '<a class="err-btn" href="' . htmlspecialchars(ADMIN_URL) . '">लगिन पृष्ठमा फर्किनुहोस्</a></div></body></html>';
    });

    // Load admin-specific utilities
    if (file_exists(INCLUDES_DIR . '/admin-ui-unified.php')) {
        require_once INCLUDES_DIR . '/admin-ui-unified.php';
    }
}

/**
 * _bootstrap_member()
 * Member portal specific initialization
 */
function _bootstrap_member() {
    // Member error handler: friendly error page
    register_shutdown_function(function() {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        if (headers_sent()) {
            echo "\n<!-- Fatal: see server error log -->\n";
            return;
        }

        @http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');

        log_error('[member-panel-fatal] ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);

        echo '<!DOCTYPE html><html lang="ne"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>त्रुटि — Member Portal</title>';
        echo '<style>';
        echo 'body{margin:0;background:linear-gradient(135deg,#fef2f2,#fee2e2);min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:"Mukta","Noto Sans Devanagari","Segoe UI",sans-serif;padding:20px}';
        echo '.err-box{max-width:480px;background:#fff;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.1);padding:32px;text-align:center}';
        echo '.err-icon{width:72px;height:72px;background:#fee2e2;color:#b91c1c;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 18px}';
        echo 'h1{color:#1f2937;font-size:1.25rem;margin:0 0 10px}';
        echo 'p{color:#6b7280;font-size:.9rem;line-height:1.6;margin:0 0 22px}';
        echo '.err-btn{display:inline-block;background:#1a5f2a;color:#fff;text-decoration:none;padding:11px 26px;border-radius:10px;font-weight:600;font-size:.88rem}';
        echo '</style></head><body>';
        echo '<div class="err-box"><div class="err-icon">⚠</div><h1>केहि गलत भयो</h1><p>कृपया कार्यालयमा सम्पर्क गर्नुहोस्।</p>';
        echo '<a class="err-btn" href="' . htmlspecialchars(SITE_URL) . 'member/login.php">लगिन पृष्ठमा फर्किनुहोस्</a></div></body></html>';
    });

    // Load member-specific auth
    if (file_exists(INCLUDES_DIR . '/member-auth.php')) {
        require_once INCLUDES_DIR . '/member-auth.php';
    }
}

/**
 * _bootstrap_public()
 * Public site specific initialization
 */
function _bootstrap_public() {
    // Public site startup actions
    // (Currently minimal, can be expanded)
}

// ═══════════════════════════════════════════════════════════════════════════════
// INITIALIZATION COMPLETE
// ═══════════════════════════════════════════════════════════════════════════════

// Bootstrap the application based on context
// This is called automatically when this file is included
if (!function_exists('_bootstrap_called')) {
    define('_BOOTSTRAP_UNIFIED_LOADED', true);
}
