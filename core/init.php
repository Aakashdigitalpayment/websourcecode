<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * 🚀 CORE INIT — सबै Portal को एकमात्र Entry Point
 * ═══════════════════════════════════════════════════════════════
 * फाइल: core/init.php
 *
 * यो एउटा file include गरे पुग्छ — सबै portal को हरेक page मा।
 *
 * 📌 प्रयोग गर्ने तरिका:
 * ───────────────────────────────────────────────────────────────
 *
 *  Public pages (index.php, about.php, etc.):
 *    define('PORTAL', 'public');
 *    require_once __DIR__ . '/core/init.php';
 *
 *  Admin portal (admin/*.php):
 *    define('PORTAL', 'admin');
 *    define('IS_ADMIN_PAGE', true);
 *    require_once __DIR__ . '/../core/init.php';
 *
 *  Member portal (member/*.php):
 *    define('PORTAL', 'member');
 *    require_once __DIR__ . '/../core/init.php';
 *
 *  Verify portal (verify.php):
 *    define('PORTAL', 'verify');
 *    require_once __DIR__ . '/core/init.php';
 *
 * ✅ यसले गर्छ:
 *   - Output buffering सुरु
 *   - PHP version + extension check
 *   - Database connection (PDO)
 *   - Session start (secure)
 *   - CSRF protection
 *   - Security HTTP headers
 *   - Error handler (production-safe)
 *   - core/helpers.php load
 *   - panel-uniform.php load
 *   - auth-roles.php load
 *   - Portal-specific bootstrap
 * ═══════════════════════════════════════════════════════════════
 */

// ─── Duplicate load रोक्ने ───
if (defined('CORE_INIT_LOADED')) return;
define('CORE_INIT_LOADED', true);

// ─── Output buffering — header errors रोक्न ───
if (!ob_get_level()) {
    ob_start();
}

// ─── Portal detection — define गर्न बिर्सेमा default 'public' ───
if (!defined('PORTAL')) {
    define('PORTAL', 'public');
}

if (!function_exists('core_allow_local_debug_url')) {
    function core_allow_local_debug_url(): bool {
        $hostLc = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        return str_starts_with($hostLc, '127.0.0.1')
            || str_starts_with($hostLc, 'localhost')
            || str_starts_with($hostLc, '[::1]');
    }
}

if (!function_exists('core_is_debug_request')) {
    function core_is_debug_request(): bool {
        return core_allow_local_debug_url()
            && isset($_GET['debug'])
            && (string)$_GET['debug'] === '1';
    }
}

if (!function_exists('core_apply_runtime_error_policy')) {
    function core_apply_runtime_error_policy(): void {
        $displayErrors = (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? '1' : '0';
        @ini_set('display_errors', $displayErrors);
        @ini_set('display_startup_errors', $displayErrors);
        @ini_set('log_errors', '1');
        @ini_set('log_errors_max_len', '1024');
        error_reporting(E_ALL);
    }
}

if (!function_exists('core_require_member_auth')) {
    function core_require_member_auth(): void {
        core_require_if_exists('includes/member-auth.php');
    }
}

if (!function_exists('core_require_if_exists')) {
    function core_require_if_exists(string $relativePath): bool {
        $path = ROOT_PATH . ltrim($relativePath, '/');
        if (!file_exists($path)) {
            return false;
        }
        require_once $path;
        return true;
    }
}

if (!function_exists('core_forward_exception_to_shutdown')) {
    function core_forward_exception_to_shutdown(Throwable $e, string $logPrefix): void {
        error_log('[' . $logPrefix . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (headers_sent()) {
            return;
        }
        @http_response_code(500);
        trigger_error($e->getMessage(), E_USER_ERROR);
    }
}

if (!function_exists('core_is_fatal_error_type')) {
    function core_is_fatal_error_type(int $errorType): bool {
        return in_array($errorType, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    }
}

if (!function_exists('core_render_portal_fatal_page')) {
    function core_render_portal_fatal_page(array $opts): void {
        $title = (string)($opts['title'] ?? 'त्रुटि');
        $heading = (string)($opts['heading'] ?? 'केहि गलत भयो');
        $message = (string)($opts['message'] ?? 'अप्रत्याशित त्रुटि भयो।');
        $home = (string)($opts['home'] ?? '/');
        $buttonText = (string)($opts['buttonText'] ?? 'होमपेजमा फर्किनुहोस्');
        $showDetail = !empty($opts['showDetail']);
        $detail = (string)($opts['detail'] ?? '');

        echo '<!DOCTYPE html><html lang="ne"><head><meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<style>
            body{margin:0;background:linear-gradient(135deg,#fef2f2,#fee2e2);min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:"Mukta","Noto Sans Devanagari","Segoe UI",sans-serif;padding:20px}
            .err-box{max-width:480px;background:#fff;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.1);padding:32px;text-align:center}
            .err-icon{width:72px;height:72px;background:#fee2e2;color:#b91c1c;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;margin:0 auto 18px}
            h1{color:#1f2937;font-size:1.25rem;margin:0 0 10px}
            p{color:#6b7280;font-size:.9rem;line-height:1.6;margin:0 0 22px}
            .err-detail{background:#fef2f2;color:#991b1b;padding:10px 14px;border-radius:8px;font-family:monospace;font-size:.78rem;margin:14px 0;text-align:left;border:1px solid #fecaca;word-break:break-all}
            .err-btn{display:inline-block;background:linear-gradient(135deg,var(--primary-color),var(--primary-light));color:#fff;text-decoration:none;padding:11px 26px;border-radius:10px;font-weight:600;font-size:.88rem}
            .err-btn:hover{opacity:.92}
        </style></head><body>';
        echo '<div class="err-box">';
        echo '<div class="err-icon">⚠</div>';
        echo '<h1>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>';
        echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        if ($showDetail && $detail !== '') {
            echo '<div class="err-detail">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        echo '<a class="err-btn" href="' . htmlspecialchars($home, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . '</a>';
        echo '</div></body></html>';
    }
}

if (!function_exists('core_register_portal_fatal_handler')) {
    function core_register_portal_fatal_handler(array $opts): void {
        $title = (string)($opts['title'] ?? 'त्रुटि');
        $heading = (string)($opts['heading'] ?? 'केहि गलत भयो');
        $message = (string)($opts['message'] ?? 'अप्रत्याशित त्रुटि भयो।');
        $home = (string)($opts['home'] ?? '/');
        $buttonText = (string)($opts['buttonText'] ?? 'होमपेजमा फर्किनुहोस्');
        $logPrefix = (string)($opts['logPrefix'] ?? 'portal-fatal');

        register_shutdown_function(static function () use ($title, $heading, $message, $home, $buttonText, $logPrefix): void {
            $err = error_get_last();
            if (!$err || !core_is_fatal_error_type((int)($err['type'] ?? 0))) {
                return;
            }
            if (headers_sent()) {
                echo "\n<!-- Fatal: see server error log -->\n";
                return;
            }

            @http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');

            $isDebug = core_is_debug_request();
            $debugDetail = ($err['message'] ?? 'Unknown error')
                . ' @ '
                . basename((string)($err['file'] ?? 'unknown'))
                . ':'
                . (string)($err['line'] ?? 0);

            error_log('[' . $logPrefix . '] '
                . ($err['message'] ?? 'Unknown error')
                . ' @ '
                . (string)($err['file'] ?? 'unknown')
                . ':'
                . (string)($err['line'] ?? 0));

            core_render_portal_fatal_page([
                'title' => $title,
                'heading' => $heading,
                'message' => $message,
                'home' => $home,
                'buttonText' => $buttonText,
                'showDetail' => $isDebug,
                'detail' => $debugDetail,
            ]);
        });
    }
}

if (!function_exists('core_register_portal_exception_handler')) {
    function core_register_portal_exception_handler(string $logPrefix): void {
        set_exception_handler(static function ($e) use ($logPrefix): void {
            core_forward_exception_to_shutdown($e, $logPrefix);
        });
    }
}

if (!function_exists('core_safe_count')) {
    function core_safe_count(PDO $db, string $sql, string $logPrefix = '[core-safe-count]'): int {
        if (function_exists('sqCount')) {
            return sqCount($db, $sql, $logPrefix);
        }
        try {
            return (int)($db->query($sql)->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            error_log($logPrefix . ' ' . $e->getMessage());
            return 0;
        }
    }
}

// ─── Root path detect — init.php कहाँ छ त्यसबाट ───
if (!defined('CORE_PATH')) {
    define('CORE_PATH', __DIR__);
}
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . '/');
}

// ═══════════════════════════════════════════════════════════════
// STEP 1: PHP Version + Extension Check
// ═══════════════════════════════════════════════════════════════
if (file_exists(ROOT_PATH . 'includes/compatibility.php')) {
    require_once ROOT_PATH . 'includes/compatibility.php';
} else {
    // Inline fallback check
    if (version_compare(PHP_VERSION, '8.0', '<')) {
        http_response_code(500);
        die('<p style="font-family:sans-serif;padding:2rem;">⚠️ PHP 8.0+ चाहिन्छ। अहिले: PHP ' . PHP_VERSION . '</p>');
    }
    foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl'] as $_ext) {
        if (!extension_loaded($_ext)) {
            die("⚠️ PHP extension '{$_ext}' चाहिन्छ तर install भएको छैन।");
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// STEP 2: Core Config (DB connection, getSetting, session, etc.)
// ═══════════════════════════════════════════════════════════════
require_once ROOT_PATH . 'includes/config.php';

// ═══════════════════════════════════════════════════════════════
// STEP 3: Core Helpers (date, currency, interest, sanitize, etc.)
// ═══════════════════════════════════════════════════════════════
require_once CORE_PATH . '/helpers.php';

// ═══════════════════════════════════════════════════════════════
// STEP 4: BS/AD Date Converter
// ═══════════════════════════════════════════════════════════════
core_require_if_exists('includes/nepali-bs-convert.php');

// ═══════════════════════════════════════════════════════════════
// STEP 5: Audit + Soft-delete helpers
// ═══════════════════════════════════════════════════════════════
core_require_if_exists('includes/audit.php');

// ═══════════════════════════════════════════════════════════════
// STEP 6: Notification Templates
// ═══════════════════════════════════════════════════════════════
core_require_if_exists('includes/notification-templates.php');

// ═══════════════════════════════════════════════════════════════
// STEP 7: Role-Based Access Control
// ═══════════════════════════════════════════════════════════════
core_require_if_exists('includes/auth-roles.php');

// ═══════════════════════════════════════════════════════════════
// STEP 8: Cross-Panel Uniform UI helpers
// ═══════════════════════════════════════════════════════════════
core_require_if_exists('includes/panel-uniform.php');

// ═══════════════════════════════════════════════════════════════
// STEP 9: Safe Query Helpers
// ═══════════════════════════════════════════════════════════════
core_require_if_exists('includes/safe-query.php');

// ═══════════════════════════════════════════════════════════════
// STEP 10: Portal-Specific Bootstrap
// ═══════════════════════════════════════════════════════════════
switch (PORTAL) {

    case 'admin':
        // IS_ADMIN_PAGE define (admin-ui.php को security guard)
        if (!defined('IS_ADMIN_PAGE')) define('IS_ADMIN_PAGE', true);

        // Admin-specific tables ensure
        core_require_if_exists('admin/includes/ensure-admin-tables.php');
        // Admin UI helpers
        core_require_if_exists('admin/includes/admin-ui.php');
        // Notifications (email/SMS)
        core_require_if_exists('includes/notifications.php');

        // DB not configured redirect (login र db-setup बाहेक)
        if (defined('DB_NAME') && DB_NAME === '') {
            $_curPage = basename($_SERVER['PHP_SELF'] ?? '');
            if (!in_array($_curPage, ['db-setup.php', 'index.php', 'login.php'], true)) {
                header('Location: ' . (defined('ADMIN_URL') ? ADMIN_URL : '/admin/') . 'db-setup.php');
                exit;
            }
        }

        // Admin auth check
        if (function_exists('isAdminLoggedIn') && !isAdminLoggedIn()) {
            $_curPage = basename($_SERVER['PHP_SELF'] ?? '');
            if (!in_array($_curPage, ['index.php', 'login.php', 'db-setup.php'], true)) {
                header('Location: ' . (defined('ADMIN_URL') ? ADMIN_URL : '/admin/') . 'index.php');
                exit;
            }
        }

        // CSRF check for all admin POST requests
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('verifyCSRFToken')) {
            if (!verifyCSRFToken()) {
                if (function_exists('setFlash')) setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।');
                $referer = $_SERVER['HTTP_REFERER'] ?? (defined('ADMIN_URL') ? ADMIN_URL . 'dashboard.php' : '/admin/');
                header('Location: ' . $referer);
                exit;
            }
        }

        // Pre-generate CSRF token
        if (function_exists('generateCSRFToken')) {
            $GLOBALS['csrfToken'] = generateCSRFToken();
        }

        // Site license check (non-superadmin)
        $_licPage = basename($_SERVER['PHP_SELF'] ?? '');
        $_licExempt = in_array($_licPage, ['index.php', 'logout.php', 'site-license.php', 'site-license-blocked.php', 'db-setup.php'], true);
        if (!$_licExempt
            && function_exists('site_license_expired')
            && site_license_expired()
            && empty($_SESSION['is_superadmin'])) {
            header('Location: ' . (defined('ADMIN_URL') ? ADMIN_URL : '/admin/') . 'site-license-blocked.php');
            exit;
        }
        unset($_licPage, $_licExempt, $_curPage);
        break;

    case 'member':
        // Member auth helpers
        core_require_member_auth();
        // Member-specific security headers
        if (function_exists('memberSecurityHeaders')) {
            memberSecurityHeaders();
        }
        break;

    case 'verify':
        // Verify portal — minimal, no auth required
        break;

    case 'public':
    default:
        // Public site license guard
        if (function_exists('site_license_public_guard')) {
            site_license_public_guard();
        }
        // Member auth helpers (for nav badge, logged-in member check)
        core_require_member_auth();
        break;
}

// ═══════════════════════════════════════════════════════════════
// STEP 11: Global convenience variables (सबै portal मा available)
// ═══════════════════════════════════════════════════════════════

/** @var string $currentLang — 'np' वा 'en' */
$currentLang = function_exists('getCurrentLang') ? getCurrentLang() : 'np';

/** @var bool $isEnglish */
$isEnglish = ($currentLang === 'en');

/** @var \PDO|null $db — global DB handle */
try {
    $db = function_exists('getDB') ? getDB() : null;
} catch (\Throwable $e) {
    $db = null;
}

/** @var string $siteName */
$siteName = function_exists('getSetting')
    ? getSetting('site_name', 'आकाश सहकारी')
    : 'आकाश सहकारी';

/** @var string $siteNameEn */
$siteNameEn = function_exists('getSetting')
    ? getSetting('site_name_en', 'Aakash Cooperative')
    : 'Aakash Cooperative';

// ─── Translation shortcut — $t('नेपाली', 'English') ───
$t = static function (string $np, string $en) use ($isEnglish): string {
    return $isEnglish ? $en : $np;
};

// ─── CSRF token shortcut ───
$csrf = function_exists('generateCSRFToken') ? generateCSRFToken() : '';

// ═══════════════════════════════════════════════════════════════
// DONE — init.php successfully loaded
// ═══════════════════════════════════════════════════════════════
