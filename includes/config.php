<?php
/**
 * =====================================================
 * AAKASH COOPERATIVE WEBSITE - CONFIGURATION FILE
 *
 * Minimum PHP Version : 8.0
 * Recommended PHP Version : 8.2+
 *
 * PHP VERSION UPGRADE गर्दा:
 * → includes/compatibility.php फाइल हेर्नुहोस्
 * → Admin Panel → System Info page check गर्नुहोस्
 * =====================================================
 */

// Output buffering - prevent header issues without stacking nested buffers unnecessarily.
if (!ob_get_level()) {
    ob_start();
}
/* Security HTTP headers — तल session block पछि एकै ठाउँमा (दोहोरो header नपठाउनु) */

if (!defined('ENVIRONMENT')) {
    $__env = strtolower(trim((string)(getenv('APP_ENV') ?: getenv('APPLICATION_ENV') ?: '')));
    define('ENVIRONMENT', in_array($__env, ['development', 'staging', 'production'], true) ? $__env : 'production');
    unset($__env);
}

// PHP Version Check + Polyfills
// (सबै version-related code: includes/compatibility.php मा छ)
require_once __DIR__ . '/compatibility.php';

/**
 * =====================================================
 * DATABASE CONFIGURATION
 * Change these settings according to your cPanel hosting
 * =====================================================
 */

// Database (tracked: database.dist.php — secrets: database.local.php or legacy database.php)
require_once __DIR__ . '/database.dist.php';

/* v3: audit + soft-delete helpers — globally available */
if (file_exists(__DIR__ . '/audit.php')) require_once __DIR__ . '/audit.php';
/* v4: notification template manager — admin-edited subject/body */
if (file_exists(__DIR__ . '/notification-templates.php')) require_once __DIR__ . '/notification-templates.php';

// Site Settings - Dynamic URL based on request host
/* cPanel / Cloudflare / reverse proxy: HTTPS अगाडि नै आए पनि $_SERVER['HTTPS'] खाली हुन सक्छ */
$_protocol = 'http';
if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '0') {
    $_protocol = 'https';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $_protocol = 'https';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
    $_protocol = 'https';
} elseif (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') {
    $_protocol = 'https';
}
$_host = $_SERVER['HTTP_HOST'] ?? 'localhost:5000';
$_site_url = $_protocol . '://' . $_host . '/';
if (!defined('SITE_URL')) define('SITE_URL', $_site_url);
if (!defined('ADMIN_URL')) define('ADMIN_URL', SITE_URL . 'admin/');

// Root path for the website (for file existence checks)
if (!defined('ROOT_PATH')) define('ROOT_PATH', __DIR__ . '/../');

if (file_exists(__DIR__ . '/theme-assets.php')) {
    require_once __DIR__ . '/theme-assets.php';
}

// File Upload Settings
define('UPLOAD_PATH', ROOT_PATH . 'assets/uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB - increased for high-resolution logos/images
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'webp']);
define('ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']); // Image-only extensions

// Session Settings
define('SESSION_NAME', 'coop_session');
define('SESSION_LIFETIME', 3600); // 1 hour

// Timezone - Nepal
date_default_timezone_set('Asia/Kathmandu');

/**
 * =====================================================
 * Office Credential Vault — Master Key (auto-init)
 * =====================================================
 * Encrypted vault को लागि 32+ char random key चाहिन्छ।
 * पहिलो पटक web request मा file मा generate हुन्छ; पछि सधैं उही key।
 * ⚠️ यो file कहिल्यै नहटाउनुहोस् — हटायो भने पुराना saved passwords
 *    decrypt हुन्न (पुनः थप्नुपर्छ)।
 */
if (!defined('CRED_MASTER_KEY')) {
    $_cred_key_file = __DIR__ . '/.cred-master.key';
    if (!file_exists($_cred_key_file)) {
        try {
            $_k = bin2hex(random_bytes(32)); // 64 hex chars
            @file_put_contents($_cred_key_file, $_k, LOCK_EX);
            @chmod($_cred_key_file, 0600);
        } catch (\Throwable $e) { /* फॉलब्याक तल */ }
    }
    $_k = @file_get_contents($_cred_key_file);
    if ($_k && strlen(trim($_k)) >= 16) {
        define('CRED_MASTER_KEY', trim($_k));
    } else {
        // Fallback: derive from SESSION_SECRET (constant takes priority over env var)
        $_session_secret = defined('SESSION_SECRET') ? SESSION_SECRET : (string)(getenv('SESSION_SECRET') ?: '');
        if ($_session_secret !== '') {
            define('CRED_MASTER_KEY', hash('sha256', 'coop-cred-v1:' . $_session_secret));
        } else {
            // Last-resort: server-specific hash (never a hardcoded generic string)
            define(
                'CRED_MASTER_KEY',
                hash('sha256', 'coop-vault:' . (string)($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost')
                    . ':' . (string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__))
            );
        }
        unset($_session_secret);
    }
    unset($_cred_key_file, $_k);
}

/**
 * =====================================================
 * ERROR REPORTING — त्रुटि देखाउने सेटिङ
 *
 * ✅ Production (Live site) मा यसरी राख्नुस्:
 *    error_reporting(0);          ← सबै errors लुकाउँछ (visitors को लागि)
 *    ini_set('display_errors', 0); ← screen मा error देखिँदैन
 *
 * 🔧 Development (testing) को बेला:
 *    error_reporting(E_ALL);      ← सबै errors देखाउँछ
 *    ini_set('display_errors', 1); ← screen मा error देखिन्छ
 *
 * हाल: errors screen मा देखिँदैन तर logs/php_errors.log मा save हुन्छ।
 * यो Production को लागि सही सेटिङ हो।
 * =====================================================
 */
error_reporting(E_ALL);              /* सबै errors log गर्ने; screen visibility env अनुसार */
ini_set('display_startup_errors', ENVIRONMENT === 'development' ? '1' : '0');
ini_set('display_errors', ENVIRONMENT === 'development' ? '1' : '0');
ini_set('log_errors', 1);         /* Log file मा errors save गर्ने? 1 = हो */
ini_set('log_errors_max_len', 1024); /* Prevent excessively large log entries */

/* Error log file को location — public_html/logs/php_errors.log मा save हुन्छ */
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);  /* logs folder नभए automatically बनाउँछ */
}
ini_set('error_log', $logDir . '/php_errors.log');

/* Auto-create frequently-needed folders on first boot */
foreach ([
    __DIR__ . '/../assets/uploads/admin-replies',   /* admin reply attachments */
    __DIR__ . '/../cache',                           /* NRB forex + general cache */
] as $_autoDir) {
    if (!is_dir($_autoDir)) { @mkdir($_autoDir, 0755, true); }
}
unset($_autoDir);

/* Production error handler — visitors ले PHP error नदेखून */
if (file_exists(__DIR__ . '/error-handler.php')) {
    require_once __DIR__ . '/error-handler.php';
}

/**
 * =====================================================
 * DATABASE CONNECTION CLASS
 * =====================================================
 */
class Database {
    private static $instance = null;
    private $conn;
    private static $connectionError = null;

    private function __construct() {
        // DB credentials नभए connect गर्दैन (admin panel बाट setup गर्नुपर्छ)
        if (DB_NAME === '' || DB_USER === '') {
            self::$connectionError = 'DB_NOT_CONFIGURED';
            $this->conn = null;
            return;
        }
        try {
            // Support Unix socket via host=localhost:/path/to/sock
            $host = DB_HOST;
            $dsn = "mysql:host=" . $host . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            if (strpos($host, ':') !== false && strpos($host, '/') !== false) {
                [$h, $sock] = explode(':', $host, 2);
                $dsn = "mysql:unix_socket=" . $sock . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            }
            $this->conn = new PDO(
                $dsn,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => 5,
                    /* PERSISTENT बन्द — shared cPanel hosting मा "Too many connections" error रोक्छ */
                    PDO::ATTR_PERSISTENT         => false,
                    /* नेपाली अक्षर ??? देखिने समस्याको पक्का समाधान:
                       MySQL session को character set + collation दुवै utf8mb4 मा set गर्ने */
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, character_set_connection=utf8mb4, character_set_results=utf8mb4, character_set_client=utf8mb4",
                ]
            );
            /* double-safety: connection खुलिसकेपछि पनि SET NAMES चलाउने */
            try { $this->conn->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (\Throwable $e) {}
        } catch (PDOException $e) {
            self::$connectionError = $e->getMessage();
            error_log("Database Connection Failed: " . $e->getMessage());
            $this->conn = null;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    public static function hasError() {
        return self::$connectionError !== null;
    }

    public static function getError() {
        return self::$connectionError;
    }

    // Prevent cloning
    private function __clone() {}
}

/**
 * =====================================================
 * HELPER FUNCTIONS
 * =====================================================
 */

// Get database connection - returns null if connection failed
function getDB() {
    $conn = Database::getInstance()->getConnection();
    if ($conn === null) {
        throw new Exception("Database connection not available");
    }
    return $conn;
}

/**
 * HTML-escape trimmed string — प्रायः output को लागि मात्र।
 * फर्म/DB इनपुटको लागि `clean_text()` + देखाउँदा `e()` प्रयोग गर्नुहोस्।
 */
if (!function_exists('sanitize')) {
    function sanitize($input) {
        if ($input === null) {
            return '';
        }
        return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * फर्म → DB स्टोरेजको लागि मानक: trim, control-char हटाउने, UTF-8 max लम्बाइ।
 * (INSERT अघि HTML escape नगर्नुहोस्; स्क्रिनमा `e()` प्रयोग गर्नुहोस्।)
 */
if (!function_exists('clean_text')) {
    function clean_text($input, int $maxLen = 4096): string {
        if ($input === null) {
            return '';
        }
        $s = trim((string) $input);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        if (function_exists('mb_substr')) {
            return mb_substr($s, 0, $maxLen, 'UTF-8');
        }
        return strlen($s) <= $maxLen ? $s : substr($s, 0, $maxLen);
    }
}

// Escape output for safe HTML display - short helper function
// Use this for displaying any user-generated or dynamic content
function e($string) {
    $text = trim((string)($string ?? ''));
    $text = str_replace("\u{FFFD}", '', $text);
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Canonical URL (query string हटाउँछ) — meta canonical + og:url
 */
function seo_canonical_url(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : '/';
    $path = '/' . ltrim(preg_replace('#//+#', '/', $path), '/');
    if ($path !== '/' && str_ends_with($path, '/')) {
        $path = rtrim($path, '/');
    }
    $base = rtrim(defined('SITE_URL') ? SITE_URL : '', '/');
    return $path === '/' ? $base . '/' : $base . $path;
}

/**
 * Logo/अपलोड पथ → पूर्ण https URL (og:image, JSON-LD)
 */
function seo_absolute_asset_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/assets/images/favicon.png';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/' . ltrim($path, '/');
}

/**
 * Meta description को लागि सुरक्षित छोटो पाठ (HTML strip)
 */
function seo_meta_description_from_html(?string $html, int $maxLen = 158): string
{
    $raw = strip_tags((string) $html);
    $t = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    if ($t === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($t, 'UTF-8') > $maxLen) {
            return mb_substr($t, 0, $maxLen - 1, 'UTF-8') . '…';
        }
        return $t;
    }
    return strlen($t) > $maxLen ? substr($t, 0, $maxLen - 1) . '…' : $t;
}

/**
 * Admin-controlled image path — बाह्य URL वा path traversal रोक्ने (page featured image आदि)
 */
function safe_public_upload_path(?string $path): string
{
    if ($path === null || $path === '') {
        return '';
    }
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || preg_match('#^(https?:)?//#i', $path) || str_contains($path, '..')) {
        return '';
    }
    $p = ltrim($path, '/');
    if (!preg_match('#^(assets/uploads/|uploads/)#i', $p)) {
        return '';
    }
    return $p;
}

/**
 * Logged-in member profile for public forms.
 * If member session exists, return basic profile for auto-fill.
 */
function getLoggedInMemberProfile(): ?array {
    if (empty($_SESSION['member_id'])) return null;
    try {
        $db = getDB();
        $st = $db->prepare("SELECT id, name, email, phone, sadasyata_number, member_card_no FROM members
                            WHERE id = ? AND is_active = 1 LIMIT 1");
        $st->execute([(int)$_SESSION['member_id']]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        return $m ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// Select dropdown option — match भएमा 'selected' print गर्छ
// Usage in HTML option tag: call selected($currentValue, 'expectedValue')
function selected($current, $value) {
    if ((string)$current === (string)$value) echo 'selected';
}

// Checked radio/checkbox — match भएमा 'checked' print गर्छ
function checked($current, $value) {
    if ((string)$current === (string)$value) echo 'checked';
}

// Get site setting from database — statically cached per request
function getSetting($key, $default = '') {
    static $__cache = [];
    // Return from cache if already fetched this request
    if (array_key_exists($key, $__cache)) {
        return $__cache[$key] !== null ? $__cache[$key] : $default;
    }
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        $__cache[$key] = $result ? $result['setting_value'] : null;
        return $__cache[$key] !== null ? $__cache[$key] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Language-aware site logo path.
 * - Nepali UI: logo_np -> site_logo -> logo
 * - English UI: logo_en -> site_logo -> logo
 */
function getLocalizedLogoPath($default = 'assets/images/logo.png') {
    $fallback = trim((string) getSetting('site_logo', getSetting('logo', $default)));
    $isEn = function_exists('isEnglish') ? isEnglish() : false;
    if ($isEn) {
        $enLogo = trim((string) getSetting('logo_en', ''));
        $fallback = $enLogo !== '' ? $enLogo : $fallback;
    } else {
        $npLogo = trim((string) getSetting('logo_np', ''));
        $fallback = $npLogo !== '' ? $npLogo : $fallback;
    }

    /* सुरक्षा जाल: यदि resolved logo path (DB बाट वा default) actually disk मा
       छैन भने (जस्तै admin ले अझै आफ्नो logo upload गरेका छैनन्), broken-image
       icon site-wide देखिनबाट जोगाउन existing PWA icon मा fallback गर्ने। */
    $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
    $isRemote = (bool) preg_match('#^https?://#i', $fallback);
    if ($fallback === '' || (!$isRemote && !is_file($root . ltrim($fallback, '/')))) {
        $knownGoodIcon = 'assets/images/icon-512x512.png';
        if (is_file($root . $knownGoodIcon)) {
            return $knownGoodIcon;
        }
    }

    return $fallback;
}

/**
 * Favicon path resolver — favicon.png default file disk मा नभए (admin ले
 * अझै upload नगरेको अवस्थामा) browser tab मा broken icon नदेखिन existing
 * PWA icon मा safely fallback गर्छ।
 */
function getSiteFaviconPath($default = 'assets/images/favicon.png') {
    $path = trim((string) getSetting('site_favicon', $default));
    $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
    $isRemote = (bool) preg_match('#^https?://#i', $path);
    if ($path === '' || (!$isRemote && !is_file($root . ltrim($path, '/')))) {
        $knownGoodIcon = 'assets/images/icon-192x192.png';
        if (is_file($root . $knownGoodIcon)) {
            return $knownGoodIcon;
        }
    }
    return $path;
}

// Update site setting (INSERT if not exists, UPDATE if exists)
function updateSetting($key, $value) {
    try {
        $db = getDB();
        // Use INSERT ... ON DUPLICATE KEY UPDATE to handle both insert and update
        $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        return $stmt->execute([$key, $value]);
    } catch (Exception $e) {
        error_log("updateSetting error: " . $e->getMessage());
        return false;
    }
}

require_once __DIR__ . '/auth-roles.php';
require_once __DIR__ . '/site-license.php';

// Format date in Nepali format
if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'Y-m-d') {
        return date($format, strtotime($date));
    }
}

// Convert English numbers to Nepali numerals
if (!function_exists('toNepaliNumeral')) {
    function toNepaliNumeral($number) {
        $englishNumerals = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $nepaliNumerals = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];
        return str_replace($englishNumerals, $nepaliNumerals, (string)$number);
    }
}

// Get Nepali month name
function getNepaliMonthName($monthKey) {
    $nepaliMonths = [
        'baisakh' => 'बैशाख', 'jestha' => 'जेठ', 'ashadh' => 'असार',
        'shrawan' => 'श्रावण', 'bhadra' => 'भदौ', 'ashwin' => 'असोज',
        'kartik' => 'कात्तिक', 'mangsir' => 'मंसिर', 'poush' => 'पुष',
        'magh' => 'माघ', 'falgun' => 'फागुन', 'chaitra' => 'चैत्र',
        '1' => 'बैशाख', '2' => 'जेठ', '3' => 'असार',
        '4' => 'श्रावण', '5' => 'भदौ', '6' => 'असोज',
        '7' => 'कात्तिक', '8' => 'मंसिर', '9' => 'पुष',
        '10' => 'माघ', '11' => 'फागुन', '12' => 'चैत्र'
    ];
    return $nepaliMonths[$monthKey] ?? $monthKey;
}

// Format date in Nepali style (for display)
// Uses accurate BS conversion via nepali_ad_to_bs_string() when available.
if (!function_exists('formatNepaliDate')) {
    function formatNepaliDate($date, $showTime = false) {
        if (empty($date)) return '';

        $timestamp = strtotime($date);
        if ($timestamp === false) return $date;

        $adYmd = date('Y-m-d', $timestamp);

        static $_bsMonthNames = [
            1 => 'बैशाख', 2 => 'जेठ',    3 => 'असार',
            4 => 'श्रावण', 5 => 'भदौ',   6 => 'असोज',
            7 => 'कात्तिक', 8 => 'मंसिर', 9 => 'पुष',
            10 => 'माघ',  11 => 'फागुन', 12 => 'चैत्र',
        ];

        if (function_exists('nepali_ad_to_bs_string')) {
            $bsYmd = nepali_ad_to_bs_string($adYmd);
            if ($bsYmd) {
                [$bsY, $bsM, $bsD] = array_map('intval', explode('-', $bsYmd));
                $formatted = toNepaliNumeral($bsY) . ' ' . ($_bsMonthNames[$bsM] ?? $bsM) . ' ' . toNepaliNumeral($bsD);
                if ($showTime) {
                    $formatted .= ' ' . toNepaliNumeral(date('H:i', $timestamp));
                }
                return $formatted;
            }
        }

        // Fallback: simple approximation (only used if converter not loaded)
        $year  = (int) date('Y', $timestamp);
        $month = (int) date('n', $timestamp);
        $day   = (int) date('j', $timestamp);
        $nepaliYear = ($month < 4 || ($month == 4 && $day < 14)) ? $year + 56 : $year + 57;
        $nepaliMonthName = $_bsMonthNames[min(12, max(1, (($month + 8) % 12) + 1))] ?? 'बैशाख';
        $formatted = toNepaliNumeral($nepaliYear) . ' ' . $nepaliMonthName . ' ' . toNepaliNumeral($day);
        if ($showTime) {
            $formatted .= ' ' . toNepaliNumeral(date('H:i', $timestamp));
        }
        return $formatted;
    }
}

// Format currency in Nepali style
if (!function_exists('formatNepaliCurrency')) {
    function formatNepaliCurrency($amount, $showSymbol = true) {
        $formatted = number_format($amount, 2);
        if (isEnglish()) {
            return $showSymbol ? 'Rs. ' . $formatted : $formatted;
        }
        return $showSymbol ? 'रू. ' . toNepaliNumeral($formatted) : toNepaliNumeral($formatted);
    }
}

// Format number in Nepali
if (!function_exists('formatNepaliNumber')) {
    function formatNepaliNumber($number) {
        if (isEnglish()) {
            return number_format($number);
        }
        return toNepaliNumeral(number_format($number));
    }
}

// Generate slug from text (PHP 8.1+ compatible)
if (!function_exists('generateSlug')) {
    function generateSlug($text) {
        if ($text === null) {
            return '';
        }
        $text = (string)$text;
        $text = preg_replace('/[^a-zA-Z0-9\s]/', '', $text);
        $text = strtolower(trim($text));
        $text = preg_replace('/\s+/', '-', $text);
        return $text;
    }
}

// Upload file with auto-resize for images
function uploadFile($file, $folder = 'general') {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error'];
    }

    $fileName = $file['name'];
    $fileSize = $file['size'];
    $fileTmp = $file['tmp_name'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Check file size
    if ($fileSize > MAX_FILE_SIZE) {
        return ['success' => false, 'message' => 'File too large. Maximum size is 10MB.'];
    }

    // Check extension
    if (!in_array($fileExt, ALLOWED_EXTENSIONS)) {
        return ['success' => false, 'message' => 'Invalid file type.'];
    }

    /* Extension-only अपलोड रोक्न — MIME (विशेष गरी PDF/DOC) जाँच */
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($fileTmp);
        $mimeByExt = [
            'jpg'   => ['image/jpeg', 'image/pjpeg'],
            'jpeg'  => ['image/jpeg', 'image/pjpeg'],
            'png'   => ['image/png'],
            'gif'   => ['image/gif'],
            'webp'  => ['image/webp'],
            'pdf'   => ['application/pdf'],
            'doc'   => ['application/msword'],
            'docx'  => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ];
        if (isset($mimeByExt[$fileExt]) && !in_array($mime, $mimeByExt[$fileExt], true)) {
            return ['success' => false, 'message' => 'Invalid file content.'];
        }
    }

    // Create folder if not exists
    $uploadDir = UPLOAD_PATH . $folder . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $newFileName = uniqid() . '_' . time() . '.' . $fileExt;
    $destination = $uploadDir . $newFileName;

    // Check if it's an image - auto-resize for web optimization
    if (in_array($fileExt, ALLOWED_IMAGE_EXTENSIONS)) {
        // Get appropriate dimensions based on folder type
        $maxWidth = 1200;
        $maxHeight = 800;
        $crop = false;

        switch ($folder) {
            case 'gallery':
                $maxWidth = 1200;
                $maxHeight = 900;
                break;
            case 'news':
                $maxWidth = 800;
                $maxHeight = 600;
                break;
            case 'committee':
                $maxWidth = 400;
                $maxHeight = 400;
                $crop = true;
                break;
            case 'logo':
                // Logo clarity: keep much larger source to avoid blur in responsive header
                $maxWidth = 1600;
                $maxHeight = 600;
                break;
            case 'uploads/auctions':
            case 'auctions':
                $maxWidth = 800;
                $maxHeight = 600;
                break;
            case 'welfare_claims':
                $maxWidth = 1200;
                $maxHeight = 1200;
                break;
            case 'seo':
                /* Open Graph / social share — ~1.91:1 */
                $maxWidth = 1200;
                $maxHeight = 630;
                break;
        }

        // Use uploadImage function for auto-resize
        $resizedName = uploadImage($file, $uploadDir, $maxWidth, $maxHeight, $crop);
        if ($resizedName) {
            return [
                'success' => true,
                'filename' => $resizedName,
                'path' => 'assets/uploads/' . $folder . '/' . $resizedName
            ];
        }
        return ['success' => false, 'message' => 'Failed to process image.'];
    }

    // For non-image files, just move them
    if (move_uploaded_file($fileTmp, $destination)) {
        return [
            'success' => true,
            'filename' => $newFileName,
            'path' => 'assets/uploads/' . $folder . '/' . $newFileName
        ];
    }

    return ['success' => false, 'message' => 'Failed to move uploaded file.'];
}

// Delete file
function deleteFile($filePath) {
    $fullPath = __DIR__ . '/../' . $filePath;
    if (file_exists($fullPath)) {
        return unlink($fullPath);
    }
    return false;
}

/**
 * Upload and auto-resize/crop image
 * @param array $file - $_FILES array element
 * @param string $destination - Upload destination folder path
 * @param int $maxWidth - Maximum width (default 1200px)
 * @param int $maxHeight - Maximum height (default 800px)
 * @param bool $crop - Whether to crop to exact dimensions (default false = resize maintaining aspect ratio)
 * @return string|false - Returns filename on success, false on failure
 */
function uploadImage($file, $destination, $maxWidth = 1200, $maxHeight = 800, $crop = false) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $fileName = $file['name'];
    $fileSize = $file['size'];
    $fileTmp = $file['tmp_name'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Check file size (10MB max - using MAX_FILE_SIZE constant)
    if ($fileSize > MAX_FILE_SIZE) {
        return false;
    }

    // Only allow image extensions (using constant)
    if (!in_array($fileExt, ALLOWED_IMAGE_EXTENSIONS)) {
        return false;
    }

    // Create folder if not exists
    $uploadDir = $destination;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $newFileName = uniqid() . '_' . time() . '.' . $fileExt;
    $destinationPath = $uploadDir . $newFileName;

    // Get image info
    $imageInfo = getimagesize($fileTmp);
    if ($imageInfo === false) {
        return false;
    }

    $origWidth = $imageInfo[0];
    $origHeight = $imageInfo[1];
    $imageType = $imageInfo[2];

    // If image is small enough, just move it (but still fix EXIF for JPEG)
    if ($origWidth <= $maxWidth && $origHeight <= $maxHeight && !$crop) {
        if ($imageType === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exifCheck = @exif_read_data($fileTmp);
            $orientation = !empty($exifCheck['Orientation']) ? (int)$exifCheck['Orientation'] : 1;
            if ($orientation !== 1) {
                // Needs EXIF correction — process through GD even though small
                // (fall through to GD processing below)
                goto process_with_gd;
            }
        }
        if (move_uploaded_file($fileTmp, $destinationPath)) {
            return $newFileName;
        }
        return false;
    }
    process_with_gd:

    // Create image resource based on type
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $srcImage = imagecreatefromjpeg($fileTmp);
            break;
        case IMAGETYPE_PNG:
            $srcImage = imagecreatefrompng($fileTmp);
            break;
        case IMAGETYPE_GIF:
            $srcImage = imagecreatefromgif($fileTmp);
            break;
        case IMAGETYPE_WEBP:
            $srcImage = imagecreatefromwebp($fileTmp);
            break;
        default:
            // Unsupported image type, just move the file
            if (move_uploaded_file($fileTmp, $destinationPath)) {
                return $newFileName;
            }
            return false;
    }

    if (!$srcImage) {
        return false;
    }

    // Fix EXIF orientation for JPEG — mobile/camera photos often have rotation metadata
    if ($imageType === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($fileTmp);
        if (!empty($exif['Orientation'])) {
            switch ((int)$exif['Orientation']) {
                case 3:
                    $srcImage = imagerotate($srcImage, 180, 0);
                    break;
                case 6:
                    $srcImage = imagerotate($srcImage, -90, 0);
                    // swap dimensions after rotation
                    [$origWidth, $origHeight] = [$origHeight, $origWidth];
                    break;
                case 8:
                    $srcImage = imagerotate($srcImage, 90, 0);
                    // swap dimensions after rotation
                    [$origWidth, $origHeight] = [$origHeight, $origWidth];
                    break;
            }
        }
    }

    // Calculate new dimensions
    if ($crop) {
        // Crop to exact dimensions (center crop)
        $srcRatio = $origWidth / $origHeight;
        $destRatio = $maxWidth / $maxHeight;

        if ($srcRatio > $destRatio) {
            // Source is wider - crop sides
            $cropWidth = (int)($origHeight * $destRatio);
            $cropHeight = $origHeight;
            $srcX = (int)(($origWidth - $cropWidth) / 2);
            $srcY = 0;
        } else {
            // Source is taller - crop top/bottom
            $cropWidth = $origWidth;
            $cropHeight = (int)($origWidth / $destRatio);
            $srcX = 0;
            $srcY = (int)(($origHeight - $cropHeight) / 2);
        }

        $newWidth = $maxWidth;
        $newHeight = $maxHeight;
    } else {
        // Resize maintaining aspect ratio
        // Never upscale tiny image; keeps original sharpness instead of blur
        $ratio = min(1, $maxWidth / $origWidth, $maxHeight / $origHeight);
        $newWidth = (int)($origWidth * $ratio);
        $newHeight = (int)($origHeight * $ratio);
        $srcX = 0;
        $srcY = 0;
        $cropWidth = $origWidth;
        $cropHeight = $origHeight;
    }

    // Create new image
    $dstImage = imagecreatetruecolor($newWidth, $newHeight);

    // Fill background based on image type
    if ($imageType == IMAGETYPE_PNG || $imageType == IMAGETYPE_GIF) {
        // Preserve transparency for PNG and GIF
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
        $transparent = imagecolorallocatealpha($dstImage, 255, 255, 255, 127);
        imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $transparent);
    } else {
        // Fill white background for JPEG/WebP — prevents black image on resize
        $white = imagecolorallocate($dstImage, 255, 255, 255);
        imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $white);
    }

    // Resample image
    imagecopyresampled(
        $dstImage, $srcImage,
        0, 0, $srcX, $srcY,
        $newWidth, $newHeight,
        $cropWidth, $cropHeight
    );

    // Save image
    $success = false;
    switch ($imageType) {
        case IMAGETYPE_JPEG:
            $success = imagejpeg($dstImage, $destinationPath, 92); // higher quality for logos/text
            break;
        case IMAGETYPE_PNG:
            $success = imagepng($dstImage, $destinationPath, 6); // balanced compression/quality
            break;
        case IMAGETYPE_GIF:
            $success = imagegif($dstImage, $destinationPath);
            break;
        case IMAGETYPE_WEBP:
            $success = imagewebp($dstImage, $destinationPath, 92);
            break;
    }

    // Free memory
    imagedestroy($srcImage);
    imagedestroy($dstImage);

    return $success ? $newFileName : false;
}

// Upload URL helper
define('UPLOAD_URL', SITE_URL . 'assets/uploads/');

/**
 * Get full URL for an asset/image path
 * Handles both relative paths like 'assets/uploads/sliders/img.jpg'
 * and paths that might already have SITE_URL
 */
function getAssetUrl($path) {
    if (empty($path)) {
        return '';
    }

    // If already a full URL, return as-is
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return $path;
    }

    // Remove leading slash if present
    $path = ltrim($path, '/');

    // Build full URL
    return SITE_URL . $path;
}

// Flash message functions
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * displayFlash() — page top मा flash message print गर्ने helper।
 * setFlash('success'|'error'|'warning'|'info', '...') ले set गरिएको
 * message लाई Bootstrap alert मा render गर्छ। एकपटक मात्र देखिन्छ।
 */
if (!function_exists('displayFlash')) {
    function displayFlash(): void {
        $flash = getFlash();
        if (!$flash) return;
        $map = [
            'success' => ['alert-success', 'fa-check-circle'],
            'error'   => ['alert-danger',  'fa-exclamation-circle'],
            'danger'  => ['alert-danger',  'fa-exclamation-circle'],
            'warning' => ['alert-warning', 'fa-exclamation-triangle'],
            'info'    => ['alert-info',    'fa-info-circle'],
        ];
        $type = strtolower($flash['type'] ?? 'info');
        [$cls, $icon] = $map[$type] ?? $map['info'];
        echo '<div class="alert ' . $cls . ' alert-dismissible fade show shadow-sm border-0" role="alert">'
           . '<i class="fas ' . $icon . ' me-2"></i>'
           . htmlspecialchars($flash['message'] ?? '')
           . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
           . '</div>';
    }
}

/**
 * =====================================================
 * CSRF PROTECTION FUNCTIONS
 * =====================================================
 */

// Generate CSRF token
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Get CSRF input field
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

// Verify CSRF token
function verifyCSRFToken($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? '';
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Check CSRF and redirect if invalid
function checkCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCSRFToken()) {
            setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुन: प्रयास गर्नुहोस्।');
            redirect($_SERVER['PHP_SELF']);
        }
    }
}

/**
 * =====================================================
 * ADDITIONAL SECURITY FUNCTIONS
 * =====================================================
 */

// Sanitize filename for uploads
if (!function_exists('sanitizeFilename')) {
    function sanitizeFilename($filename) {
        // Remove any path components
        $filename = basename($filename);
        // Remove special characters except alphanumeric, dots, hyphens, underscores
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        return $filename;
    }
}

// Validate email
if (!function_exists('isValidEmail')) {
    function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

// Validate phone number (Nepal format)
if (!function_exists('isValidPhone')) {
    function isValidPhone($phone) {
        // Nepal phone: 10 digits starting with 9
        return preg_match('/^9[0-9]{9}$/', preg_replace('/[^0-9]/', '', $phone));
    }
}

// Rate limiting for forms (prevent spam)
if (!function_exists('checkRateLimit')) {
    function checkRateLimit($action, $limit = 5, $period = 60) {
        $key = 'rate_' . $action . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'time' => time()];
        }

        // Reset if period has passed
        if (time() - $_SESSION[$key]['time'] > $period) {
            $_SESSION[$key] = ['count' => 0, 'time' => time()];
        }

        $_SESSION[$key]['count']++;

        return $_SESSION[$key]['count'] <= $limit;
    }
}

// Log security events
function logSecurityEvent($event, $details = '') {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO activity_log (admin_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
        $adminId = $_SESSION['admin_id'] ?? null;
        $stmt->execute([$adminId, $event, $details, $_SERVER['REMOTE_ADDR'] ?? '']);
    } catch (Exception $e) {
        // Silent fail - don't break the app for logging
    }
}

// Check if admin is logged in
function isAdminLoggedIn() {
    if (empty($_SESSION['admin_id'])) return false;

    // Admin session hardening: 10-minute inactivity timeout
    $adminIdleLimit = 600;
    $last = (int)($_SESSION['admin_last_activity'] ?? 0);
    if ($last > 0 && (time() - $last) > $adminIdleLimit) {
        return false;
    }

    // Device fingerprint check (UA + IP /24) to force relogin on device/network change
    $expectedUA = (string)($_SESSION['admin_agent_hash'] ?? '');
    $expectedIP = (string)($_SESSION['admin_ip_partial'] ?? '');
    $currentUA = substr(md5($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 16);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $currentIP = implode('.', array_slice(explode('.', $ip), 0, 3));

    if ($expectedUA !== '' && $currentUA !== $expectedUA) {
        return false;
    }
    if ($expectedIP !== '' && $currentIP !== '' && $currentIP !== $expectedIP) {
        return false;
    }

    $_SESSION['admin_last_activity'] = time();
    return true;
}

// Admin login required — login नभएमा login page redirect गर्छ
// admin pages को top मा: requireAdminLogin(); राख्नुहोस्
function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        @session_destroy();
        redirect(ADMIN_URL . 'index.php');
    }
}

// Redirect function - improved to handle headers already sent
if (!function_exists('redirect')) {
    function redirect($url) {
        if (!headers_sent()) {
            header("Location: " . $url);
        } else {
            echo '<script>window.location="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '";</script>';
        }
        exit();
    }
}

/**
 * CSRF fail आदिमा फर्कने URL — raw HTTP_REFERER प्रयोग नगर्नु (open redirect)।
 * सधैं हालको admin page (ADMIN_URL + basename) वा दिइएको fallback।
 */
if (!function_exists('adminSelfUrl')) {
    function adminSelfUrl(?string $fallbackPage = null): string {
        $base = defined('ADMIN_URL') ? ADMIN_URL : '/admin/';
        $page = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        if ($page === '' || $page === '.' || $page === '/') {
            $page = $fallbackPage ?: 'dashboard.php';
        }
        return rtrim($base, '/') . '/' . ltrim($page, '/');
    }
}

// Get current page name
function getCurrentPage() {
    $page = basename($_SERVER['PHP_SELF'], '.php');
    return $page;
}

// Truncate text (PHP 8.1+ compatible)
function truncateText($text, $length = 100) {
    if ($text === null) {
        return '';
    }
    $text = (string)$text;
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . '...';
}

// Adjust color brightness (for dynamic theme)
function adjustBrightness($hex, $steps) {
    // Remove # if present
    $hex = ltrim($hex, '#');

    // Convert to RGB
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));

    // Adjust brightness
    $r = max(0, min(255, $r + $steps));
    $g = max(0, min(255, $g + $steps));
    $b = max(0, min(255, $b + $steps));

    // Convert back to hex
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

// Start session if not started - with proper error handling
if (session_status() === PHP_SESSION_NONE) {
    /* GC lifetime — server-side session expire */
    @ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    @ini_set('session.use_strict_mode', '1');     /* arbitrary session id stop */
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');
    @ini_set('session.cookie_httponly', '1');

    $_isSecure = false;
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '0') {
        $_isSecure = true;
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $_isSecure = true;
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        $_isSecure = true;
    }

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => $_isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name(SESSION_NAME);
    session_start();

    /* Idle timeout — last activity बाट SESSION_LIFETIME भन्दा बढी भए logout */
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

// Security headers - send on every request (एकै सेट — माथि दोहोरो नभएको)
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    /* KYC / QR scan / verify — camera must be allowed on same-origin
       Both old (allowlist) and new (structured) syntax sent for max compatibility */
    header('Permissions-Policy: geolocation=(), microphone=(self), camera=(self), payment=(), usb=()');
    $_httpsOn = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '0')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    if ($_httpsOn) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }
    unset($_httpsOn);
    /* CSP report-only — बिस्तारै enforce गर्न लग मोनिटर गर्न */
    header(
        "Content-Security-Policy-Report-Only: default-src 'self'; "
        . "img-src 'self' data: blob: https:; "
        . "media-src 'self' blob:; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "frame-ancestors 'self'; base-uri 'self'; form-action 'self';"
    );
}

/**
 * =====================================================
 * MYSQLI CONNECTION (for legacy admin pages)
 * Some admin pages use mysqli - this provides compatibility
 * =====================================================
 */
// Use Unix socket if DB_HOST contains a colon and slash
$_mysqli_host = DB_HOST;
$_mysqli_socket = null;
if (strpos(DB_HOST, ':') !== false && strpos(DB_HOST, '/') !== false) {
    [$_mysqli_host, $_mysqli_socket] = explode(':', DB_HOST, 2);
    $_mysqli_host = 'localhost';
}
// DB credentials नभए mysqli पनि skip (PHP 8 crash हुँदैन)
$conn = null;
if (DB_NAME !== '' && DB_USER !== '') {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = new mysqli($_mysqli_host, DB_USER, DB_PASS, DB_NAME, null, $_mysqli_socket);
        if ($conn->connect_errno) {
            error_log("MySQLi Connection Failed: " . $conn->connect_error);
            $conn = null;
        } else {
            $conn->set_charset("utf8mb4");
        }
    } catch (Throwable $_mysqli_ex) {
        error_log("MySQLi Connection Exception: " . $_mysqli_ex->getMessage());
        $conn = null;
    }
}

/**
 * =====================================================
 * LANGUAGE — per browser only (session + cookie).
 * Never write language to DB/settings — one visitor's
 * EN/NP choice must not change the site for everyone.
 * Default for new visitors: Nepali (np).
 * =====================================================
 */

if (!defined('UI_LANG_COOKIE')) {
    define('UI_LANG_COOKIE', 'coop_ui_lang');
}

if (!function_exists('coopUiLangIsSecureRequest')) {
    function coopUiLangIsSecureRequest(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off' && (string)$_SERVER['HTTPS'] !== '0') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }
        return isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443';
    }
}

if (!function_exists('coopSetUiLang')) {
    /** Store UI language for this browser only (session + cookie). */
    function coopSetUiLang(string $lang): void
    {
        if (!in_array($lang, ['en', 'np'], true)) {
            return;
        }
        $_SESSION['lang'] = $lang;
        if (headers_sent()) {
            return;
        }
        setcookie(UI_LANG_COOKIE, $lang, [
            'expires'  => time() + (86400 * 400),
            'path'     => '/',
            'secure'   => coopUiLangIsSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('coopResolveUiLang')) {
    /** Resolve language for this request: session → cookie → default np. */
    function coopResolveUiLang(): string
    {
        $sessionLang = (string)($_SESSION['lang'] ?? '');
        if (in_array($sessionLang, ['en', 'np'], true)) {
            return $sessionLang;
        }
        $cookieLang = (string)($_COOKIE[UI_LANG_COOKIE] ?? '');
        if (in_array($cookieLang, ['en', 'np'], true)) {
            $_SESSION['lang'] = $cookieLang;
            return $cookieLang;
        }
        return 'np';
    }
}

// Apply ?lang= for THIS browser only, then strip it from the URL (so shared
// links / caches do not force English onto other visitors).
if (
    isset($_GET['lang'])
    && in_array($_GET['lang'], ['en', 'np'], true)
    && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET'
) {
    coopSetUiLang((string)$_GET['lang']);
    if (!headers_sent()) {
        $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $q = $_GET;
        unset($q['lang']);
        $target = $path . ($q !== [] ? ('?' . http_build_query($q)) : '');
        header('Cache-Control: private, no-store');
        header('Location: ' . $target, true, 303);
        exit;
    }
} else {
    $_SESSION['lang'] = coopResolveUiLang();
}

// Get current language
function getCurrentLang() {
    return coopResolveUiLang();
}

// Check if current language is English
function isEnglish() {
    return getCurrentLang() === 'en';
}

// Unified time options (dropdown only; avoid free-typed time strings)
if (!function_exists('getUnifiedTimeOptions')) {
    function getUnifiedTimeOptions(string $start = '06:00', string $end = '20:00', int $stepMinutes = 30): array {
        $step = max(5, $stepMinutes);
        $s = DateTimeImmutable::createFromFormat('H:i', $start);
        $e = DateTimeImmutable::createFromFormat('H:i', $end);
        if (!$s || !$e) {
            return [];
        }
        if ($s > $e) {
            [$s, $e] = [$e, $s];
        }

        $out = [];
        for ($t = $s; $t <= $e; $t = $t->modify('+' . $step . ' minutes')) {
            $v = $t->format('h:i A');
            $out[$v] = $v;
        }
        return $out;
    }
}

// Office time options (all booking/request time selections should use this)
if (!function_exists('getOfficeTimeOptions')) {
    function getOfficeTimeOptions(int $stepMinutes = 30): array {
        $start = function_exists('getSetting') ? trim((string)getSetting('office_time_start', '10:00')) : '10:00';
        $end   = function_exists('getSetting') ? trim((string)getSetting('office_time_end', '17:00')) : '17:00';
        if ($start === '') $start = '10:00';
        if ($end === '') $end = '17:00';
        return getUnifiedTimeOptions($start, $end, $stepMinutes);
    }
}

/**
 * Public / login / verify surfaces — तपाईं यही URL मा रही `?lang=` बाट भाषा बदल्न सक्नुहुन्छ।
 */
if (!function_exists('portalLangToggleUrl')) {
    function portalLangToggleUrl(): string {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = strtok($uri, '?');
        if ($path === false || $path === '') {
            $path = '/';
        }
        $q = $_GET;
        $q['lang'] = isEnglish() ? 'np' : 'en';
        return $path . '?' . http_build_query($q);
    }
}

if (!function_exists('portalLangToggleBadge')) {
    function portalLangToggleBadge(): string {
        return isEnglish() ? 'NP' : 'EN';
    }
}

// Get translated text - use appGetText to avoid PHP gettext() name collision
if (!function_exists('appGetText')) {
    function appGetText($nepaliText, $englishText = null) {
        if (isEnglish() && $englishText !== null) {
            return $englishText;
        }
        return $nepaliText;
    }
}

// Get field value based on language (for database fields like title/title_np)
function getLangField($row, $fieldName) {
    $lang = getCurrentLang();
    if ($lang === 'en') {
        // For English, use the base field (e.g., 'title', 'description')
        return !empty($row[$fieldName]) ? $row[$fieldName] : ($row[$fieldName . '_np'] ?? '');
    } else {
        // For Nepali, use the _np field first, fallback to base field
        $npField = $fieldName . '_np';
        return !empty($row[$npField]) ? $row[$npField] : ($row[$fieldName] ?? '');
    }
}

// Language strings for common UI elements
function getLangStrings() {
    $lang = getCurrentLang();

    if ($lang === 'en') {
        return [
            'home' => 'Home',
            'about' => 'About Us',
            'services' => 'Services',
            'interest_rates' => 'Interest Rates',
            'notices' => 'Notices',
            'gallery' => 'Gallery',
            'team' => 'Contact Officers',
            'contact' => 'Contact',
            'saving' => 'Saving',
            'loan' => 'Loan',
            'remittance' => 'Remittance',
            'read_more' => 'Read More',
            'view_all' => 'View All',
            'download' => 'Download',
            'our_services' => 'Our Services',
            'our_rates' => 'Interest Rates',
            'our_notices' => 'Notices',
            'why_us' => 'Why Choose Us',
            'join_us' => 'Join Us Today!',
            'contact_us' => 'Contact Us',
            'quick_links' => 'Quick Links',
            'latest_notices' => 'Latest Notices',
            'contact_info' => 'Contact Information',
            'about_short' => 'About',
            'no_data' => 'No data available',
            'no_notice' => 'No notices available',
            'saving_rate' => 'Saving Interest Rate',
            'loan_rate' => 'Loan Interest Rate',
            'view_all_rates' => 'View All Rates',
            'view_all_notices' => 'View All Notices',
            'emi_calculator' => 'EMI Calculator',
            'exchange_rate' => 'Foreign Exchange Rate',
            'date_converter' => 'Date Converter',
            'important_links' => 'Important Links',
            'downloads' => 'Downloads',
            'vendor' => 'Vendor Enlistment',
            'calculate' => 'Calculate',
            'loan_amount' => 'Loan Amount',
            'interest_rate' => 'Interest Rate (%)',
            'loan_tenure' => 'Loan Tenure (Years)',
            'monthly_emi' => 'Monthly EMI',
            'total_interest' => 'Total Interest',
            'total_payment' => 'Total Payment',
            'from_date' => 'From',
            'to_date' => 'To',
            'convert' => 'Convert',
            'bs_to_ad' => 'BS to AD',
            'ad_to_bs' => 'AD to BS',
            'internet_banking' => 'Internet Banking',
            'mobile_banking' => 'Mobile Banking',
            'digital_payments' => 'Manage your Digital Payments',
            'anytime_anywhere' => 'Anytime, Anywhere.',
            'download_app' => 'Download our Mobile Banking app!',
            'app_description' => 'Quick, Secure, and Convenient: Your all-in-one mobile banking app for seamless financial control.',
            'election_information' => 'Election information',
            'election_intro' => 'Official timeline and documents for the cooperative election process.',
            'election_timeline' => 'Schedule / timeline',
            'election_documents' => 'Documents & details',
            'election_archive' => 'Other published cycles',
            'election_view_committees' => 'Current committees',
            'election_view_notices' => 'All notices',
            'election_no_data' => 'No election information has been published yet.',
        ];
    }

    // Nepali (default)
    return [
        'home' => 'गृहपृष्ठ',
        'about' => 'हाम्रो बारेमा',
        'services' => 'सेवाहरू',
        'interest_rates' => 'ब्याज दर',
        'notices' => 'सूचना',
        'gallery' => 'ग्यालरी',
        'team' => 'मानवीय श्रोत',
        'contact' => 'सम्पर्क',
        'saving' => 'बचत सेवा',
        'loan' => 'ऋण सेवा',
        'remittance' => 'रेमिट्यान्स',
        'read_more' => 'थप पढ्नुहोस्',
        'view_all' => 'सबै हेर्नुहोस्',
        'download' => 'डाउनलोड',
        'our_services' => 'हाम्रो सेवाहरू',
        'our_rates' => 'ब्याज दरहरू',
        'our_notices' => 'सूचनाहरू',
        'why_us' => 'किन हामीलाई छान्ने?',
        'join_us' => 'आज नै सदस्य बन्नुहोस्!',
        'contact_us' => 'सम्पर्क गर्नुहोस्',
        'quick_links' => 'द्रुत लिंकहरू',
        'latest_notices' => 'ताजा सूचनाहरू',
        'contact_info' => 'सम्पर्क जानकारी',
        'about_short' => 'हाम्रो बारेमा',
        'no_data' => 'डाटा उपलब्ध छैन',
        'no_notice' => 'कुनै सूचना छैन',
        'saving_rate' => 'बचत ब्याज दर',
        'loan_rate' => 'ऋण ब्याज दर',
        'view_all_rates' => 'सबै ब्याज दरहरू हेर्नुहोस्',
        'view_all_notices' => 'सबै सूचनाहरू हेर्नुहोस्',
        'emi_calculator' => 'EMI क्याल्कुलेटर',
        'exchange_rate' => 'विदेशी विनिमय दर',
        'date_converter' => 'मिति रूपान्तरण',
        'important_links' => 'महत्त्वपूर्ण लिंकहरू',
        'downloads' => 'डाउनलोडहरू',
        'vendor' => 'भेन्डर सूचीकरण',
        'calculate' => 'गणना गर्नुहोस्',
        'loan_amount' => 'ऋण रकम',
        'interest_rate' => 'ब्याज दर (%)',
        'loan_tenure' => 'ऋण अवधि (वर्ष)',
        'monthly_emi' => 'मासिक EMI',
        'total_interest' => 'कुल ब्याज',
        'total_payment' => 'कुल भुक्तानी',
        'from_date' => 'बाट',
        'to_date' => 'सम्म',
        'convert' => 'रूपान्तरण',
        'bs_to_ad' => 'बि.सं. बाट ई.सं.',
        'ad_to_bs' => 'ई.सं. बाट बि.सं.',
        'internet_banking' => 'इन्टरनेट बैंकिङ',
        'mobile_banking' => 'मोबाइल बैंकिङ',
        'digital_payments' => 'आफ्नो डिजिटल भुक्तानी व्यवस्थापन गर्नुहोस्',
        'anytime_anywhere' => 'जुनसुकै समय, जहाँबाट पनि।',
        'download_app' => 'हाम्रो मोबाइल बैंकिङ एप डाउनलोड गर्नुहोस्!',
        'app_description' => 'छिटो, सुरक्षित र सुविधाजनक: तपाईंको वित्तीय नियन्त्रणको लागि सबै-मा-एक मोबाइल बैंकिङ एप।',
        'election_information' => 'निर्वाचन जानकारी',
        'election_intro' => 'सञ्चालक समिति / लेखा समिति आदि निर्वाचन सम्बन्धी सार्वजनिक कार्यतालिका र कागजातहरू।',
        'election_timeline' => 'मिति / कार्यतालिका',
        'election_documents' => 'विवरण र कागजात',
        'election_archive' => 'अन्य प्रकाशित निर्वाचन',
        'election_view_committees' => 'हालको समिति हेर्नुहोस्',
        'election_view_notices' => 'सबै सूचना',
        'election_no_data' => 'अहिले कुनै निर्वाचन जानकारी प्रकाशित गरिएको छैन।',
    ];
}

// Get a specific language string
function lang($key) {
    $strings = getLangStrings();
    return $strings[$key] ?? $key;
}

/* ============================================================
   adminUploadFile() — Admin Reply मा file upload गर्ने helper
   ============================================================
   सबै ६ वटा admin status-update forms ले यो function use गर्छन्।
   Allowed: PDF, JPG, PNG, DOC, DOCX — max 5MB
   Save गर्ने ठाउँ: assets/uploads/admin-replies/

   Return:
     - Success भए → 'assets/uploads/admin-replies/xxxxx.pdf' (relative path)
     - File नभएमा वा error भएमा → null

   Usage:
     $filePath = adminUploadFile('admin_attachment');
     if ($filePath) {
         // DB मा $filePath save गर्नुहोस्
     }
============================================================ */
/**
 * safeAddColumn — MySQL 5.7+ compatible ALTER TABLE helper
 * ADD COLUMN IF NOT EXISTS MySQL 8.0+ मा मात्र काम गर्छ;
 * यो function SHOW COLUMNS check गरेर safely column थप्छ।
 */
function safeAddColumn(PDO $db, string $table, string $col, string $definition): void {
    try {
        $chk = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
        if ($chk && $chk->rowCount() === 0) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$definition}");
        }
    } catch (Exception $e) {}
}

function adminUploadFile(string $fieldName = 'admin_attachment'): ?string {
    /* File upload हुन नपर्नेमा null return गर्छ — required छैन */
    if (
        !isset($_FILES[$fieldName]) ||
        $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE ||
        empty($_FILES[$fieldName]['name'])
    ) {
        return null;
    }

    $file = $_FILES[$fieldName];

    /* Upload error check */
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    /* Size check — 5MB max */
    $maxBytes = 5 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return null; /* File धेरै ठूलो छ */
    }

    /* Extension check — allowed types मात्र */
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    if (!in_array($ext, $allowed)) {
        return null; /* Allowed type: PDF, JPG, PNG, DOC, DOCX */
    }

    /* MIME type double-check */
    $allowedMimes = [
        'application/pdf', 'image/jpeg', 'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimes)) {
        return null;
    }

    /* Upload directory तयार गर्ने — नभएमा create गर्ने */
    $subdir    = 'admin-replies';
    $uploadDir = dirname(__DIR__) . '/assets/uploads/' . $subdir . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    /* Unique filename — collision हुँदैन */
    $newName = 'reply_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest    = $uploadDir . $newName;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return 'assets/uploads/' . $subdir . '/' . $newName;
    }
    return null;
}

/* ============================================================
   adminAttachmentHtml() — Existing admin attachment देखाउने
   ============================================================
   Table मा भएको attachment path लिएर download link बनाउँछ।
   Usage:  echo adminAttachmentHtml($row['admin_attachment']);
============================================================ */
function adminAttachmentHtml(?string $path): string {
    if (!$path) return '';
    $name = basename($path);
    $url  = SITE_URL . '/' . ltrim($path, '/');
    $icon = str_ends_with(strtolower($path), '.pdf') ? 'fa-file-pdf text-danger' : 'fa-file text-primary';
    return '<a href="' . htmlspecialchars($url) . '" target="_blank" class="btn btn-sm btn-outline-secondary mt-1">
        <i class="fas ' . $icon . ' me-1"></i>' . htmlspecialchars($name) . '
    </a>';
}

/* ============================================================
   DB NOT CONFIGURED — Public Pages को लागि Setup Message

/* ============================================================
   DB NOT CONFIGURED — Public Pages ko lagi Setup Message
============================================================ */
$_cfg_self = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
$_cfg_is_admin = (
    defined('IS_ADMIN_PAGE') ||
    strpos($_cfg_self, '/admin/') !== false ||
    strpos($_cfg_self, 'debug.php') !== false ||
    strpos($_cfg_self, 'setup.php') !== false
);
$_cfg_ui_test = isset($_GET['ui_test']) && (string)$_GET['ui_test'] === '1';
if (!$_cfg_is_admin && DB_NAME === '' && !$_cfg_ui_test) {
    http_response_code(200);
    $_cfg_proto = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $_cfg_host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $_cfg_admin = $_cfg_proto . '://' . $_cfg_host . '/admin/';
    echo '<!DOCTYPE html><html lang="ne"><head>'
       . '<meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Setup - Aakash Cooperative</title>'
       . '<style>'
       . 'body{margin:0;font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--primary-color)}'
       . '.box{background:#fff;border-radius:12px;padding:40px;max-width:400px;width:90%;text-align:center}'
       . 'h1{color:var(--primary-color);margin-bottom:10px;font-size:22px}'
       . 'p{color:#555;margin-bottom:24px;line-height:1.6}'
       . 'a{background:var(--primary-color);color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-size:16px}'
       . '</style></head><body>'
       . '<div class="box">'
       . '<h1>Aakash Cooperative</h1>'
       . '<p>Website setup in progress.<br>Go to Admin Panel to configure the database.</p>'
       . '<a href="' . htmlspecialchars($_cfg_admin) . '">Admin Panel Setup</a>'
       . '</div></body></html>';
    exit;
}
unset($_cfg_self, $_cfg_is_admin, $_cfg_ui_test, $_cfg_proto, $_cfg_host, $_cfg_admin);

/**
 * =====================================================
 * v2 SECURITY HELPERS — Akash Cooperative
 * =====================================================
 * थपिएको: DB-based brute force protection, file MIME
 * validation, activity log auto-cleanup।
 * =====================================================
 */

/* ── Login attempts table auto-create (एकपटक मात्र runtime मा) ── */
function ensureLoginAttemptsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            username     VARCHAR(100) NOT NULL,
            ip_address   VARCHAR(45)  NOT NULL,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lookup (username, ip_address, attempted_at),
            INDEX idx_attempted (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* silent */ }
}

/**
 * checkLoginAttempts — DB-आधारित brute force protection
 * Returns false (block) यदि पछिल्लो 15 मिनेटमा 5+ failed attempts भए।
 * Session-based छैन — cookie clear गरेर bypass गर्न मिल्दैन।
 */
function checkLoginAttempts(string $username, string $ip, int $maxAttempts = 5, int $windowSeconds = 900): bool {
    ensureLoginAttemptsTable();
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts
                              WHERE (username = ? OR ip_address = ?)
                              AND attempted_at > (NOW() - INTERVAL ? SECOND)");
        $stmt->execute([$username, $ip, $windowSeconds]);
        return ((int)$stmt->fetchColumn()) < $maxAttempts;
    } catch (\Throwable $e) {
        return true; /* DB issue — block गर्दैन (legit users disrupt नगर्न) */
    }
}

function recordLoginAttempt(string $username, string $ip): void {
    ensureLoginAttemptsTable();
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)");
        $stmt->execute([substr($username, 0, 100), substr($ip, 0, 45)]);
    } catch (\Throwable $e) { /* silent */ }
}

function resetLoginAttempts(string $username, string $ip): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE username = ? OR ip_address = ?");
        $stmt->execute([$username, $ip]);
    } catch (\Throwable $e) { /* silent */ }
}

/**
 * purgeOldRecords — पुरानो data auto-cleanup
 * Cron बाट दैनिक call गर्न सकिन्छ। Default: 90 दिन भन्दा पुरानो।
 */
function purgeOldRecords(int $days = 90): array {
    $report = [];
    try {
        $db = getDB();
        /* Activity log */
        $stmt = $db->prepare("DELETE FROM activity_log WHERE created_at < (NOW() - INTERVAL ? DAY)");
        $stmt->execute([$days]);
        $report['activity_log'] = $stmt->rowCount();
        /* Login attempts */
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL ? DAY)");
        $stmt->execute([1]); /* login_attempts मात्र 1 दिन — sliding window पर्याप्त */
        $report['login_attempts'] = $stmt->rowCount();
        /* OTP tokens (member portal) */
        try {
            $stmt = $db->prepare("DELETE FROM member_otp_tokens WHERE expires_at < NOW() OR used = 1");
            $stmt->execute();
            $report['member_otp_tokens'] = $stmt->rowCount();
        } catch (\Throwable $e) {}
    } catch (\Throwable $e) {
        $report['error'] = $e->getMessage();
    }
    return $report;
}

/**
 * validateUploadedFile — Magic-byte आधारित MIME validation
 * Extension मात्र होइन, file को actual content बाट verify गर्छ।
 *
 * @param array  $file       — $_FILES element
 * @param array  $allowedExt — ['jpg','png','pdf'] etc.
 * @param int    $maxSize    — bytes
 * @return array ['ok'=>bool, 'error'=>string, 'safe_name'=>string, 'ext'=>string]
 */
function validateUploadedFile(array $file, array $allowedExt = [], int $maxSize = 0): array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'File upload failed.'];
    }
    if ($maxSize <= 0) $maxSize = MAX_FILE_SIZE;
    if ($file['size'] > $maxSize) {
        return ['ok' => false, 'error' => 'File too large.'];
    }
    if ($file['size'] <= 0) {
        return ['ok' => false, 'error' => 'Empty file.'];
    }

    $allowedExt = $allowedExt ?: ALLOWED_EXTENSIONS;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'error' => 'File type not allowed.'];
    }

    /* Dangerous extensions hard-block */
    $blockedExt = ['php','php3','php4','php5','phtml','phar','pl','py','sh','cgi','htaccess','htm','html','js','exe','bat','asp','aspx','jsp'];
    if (in_array($ext, $blockedExt, true)) {
        return ['ok' => false, 'error' => 'File type not allowed.'];
    }

    /* Double-extension attack: file.php.jpg */
    if (preg_match('/\.(php|phtml|phar|pl|py|sh|cgi|exe|js|html?)\./i', $file['name'])) {
        return ['ok' => false, 'error' => 'Invalid file name.'];
    }

    /* Magic-byte MIME check */
    $detectedMime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $file['tmp_name']) ?: '';
        finfo_close($finfo);
    } elseif (function_exists('mime_content_type')) {
        $detectedMime = mime_content_type($file['tmp_name']) ?: '';
    }

    $extToMime = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip'],
        'xls'  => ['application/vnd.ms-excel', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'],
    ];
    if ($detectedMime && isset($extToMime[$ext])) {
        if (!in_array($detectedMime, $extToMime[$ext], true)) {
            return ['ok' => false, 'error' => 'File content does not match its extension.'];
        }
    }

    /* Image: getimagesize() ले actual image हो कि verify गर्छ */
    if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return ['ok' => false, 'error' => 'Invalid image file.'];
        }
    }

    /* Safe random filename */
    $safeName = bin2hex(random_bytes(12)) . '_' . time() . '.' . $ext;

    return ['ok' => true, 'error' => '', 'safe_name' => $safeName, 'ext' => $ext, 'mime' => $detectedMime];
}

/**
 * safeShowColumns — SQL injection-safe column existence check
 * `SHOW COLUMNS FROM x LIKE 'col'` पुरानो pattern को सुरक्षित विकल्प।
 */
function safeColumnExists(string $table, string $column): bool {
    /* Whitelist: identifier मा अल्फानुमेरिक र underscore मात्र */
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) return false;
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) return false;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT 1 FROM information_schema.COLUMNS
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
                              LIMIT 1");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

function safeTableExists(string $table): bool {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) return false;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT 1 FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}
