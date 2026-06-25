<?php
/**
 * includes/error-handler.php
 * ══════════════════════════════════════════════════════
 * Production-safe PHP Error Handler
 * ══════════════════════════════════════════════════════
 *
 * के गर्छ:
 *   - Uncaught exceptions → 500.php page देखाउँछ
 *   - Fatal errors → 500.php page देखाउँछ
 *   - Visitors लाई PHP error stack नदेखाउने
 *   - logs/php_errors.log मा सबै errors log गर्छ
 *
 * कसरी use गर्ने (config.php मा पहिले नै include भएको छ):
 *   require_once __DIR__ . '/error-handler.php';
 * ══════════════════════════════════════════════════════
 */
if (defined('ERROR_HANDLER_LOADED')) return;
define('ERROR_HANDLER_LOADED', true);

// Ensure runtime error display is always disabled in production-safe handler
@ini_set('display_errors', 0);
@ini_set('display_startup_errors', 0);
@ini_set('log_errors', 1);
@ini_set('log_errors_max_len', 1024);

/* ── Custom exception handler ── */
set_exception_handler(function (Throwable $e) {
    $msg = sprintf(
        "[%s] Uncaught %s: %s in %s:%d\nStack trace:\n%s",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    error_log($msg);

    /* Visitors लाई PHP error नदेखाउने */
    if (!headers_sent()) {
        http_response_code(500);
    }
    _show_error_page();
});

/* ── Custom error handler ── */
set_error_handler(function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
    if (!($errno & error_reporting())) return false; /* silenced with @ */

    $type_map = [
        E_ERROR             => 'Fatal Error',
        E_WARNING           => 'Warning',
        E_NOTICE            => 'Notice',
        E_STRICT            => 'Strict',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated',
    ];
    $type = $type_map[$errno] ?? "Error($errno)";
    error_log(sprintf('[%s] %s: %s in %s:%d', date('Y-m-d H:i:s'), $type, $errstr, $errfile, $errline));

    /* Fatal errors → 500 page */
    if (in_array($errno, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        if (!headers_sent()) http_response_code(500);
        _show_error_page();
        exit(1);
    }
    return false; /* PHP default handler पनि चलोस् (for log) */
});

/* ── Shutdown handler — fatal errors catch गर्न ── */
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log(sprintf('[%s] Shutdown Fatal: %s in %s:%d', date('Y-m-d H:i:s'), $e['message'], $e['file'], $e['line']));
        if (!headers_sent()) http_response_code(500);
        _show_error_page();
    }
});

/* ── Helper: show error page ── */
function _show_error_page(): void {
    /* Already in output? Clear it */
    if (ob_get_level() > 0) {
        ob_clean();
    }
    /* Display 500 page */
    $page = defined('SITE_ROOT') ? SITE_ROOT . '/500.php' : dirname(__DIR__) . '/500.php';
    if (file_exists($page)) {
        include $page;
    } else {
        /* Absolute fallback if 500.php not found */
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Server Error</title></head><body style="font-family:sans-serif;text-align:center;padding:80px 20px;"><h1 style="color:#dc3545">&#9888; Server Error</h1><p>केही गलत भयो। कृपया थोरै पछि पुनः प्रयास गर्नुहोस्।</p><a href="/" style="color:#1a5f2a">&#8592; गृहपृष्ठमा जानुहोस्</a></body></html>';
    }
    exit(1);
}
