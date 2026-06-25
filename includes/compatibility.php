<?php
/**
 * =====================================================================
 * PHP VERSION COMPATIBILITY FILE
 * Aakash Cooperative Website
 * =====================================================================
 *
 * यो file PHP version upgrade हुँदा हेर्नुपर्ने मुख्य ठाउँ हो।
 *
 * PHP VERSION UPGRADE गर्दा के गर्ने:
 * ─────────────────────────────────────
 * 1. तलको MINIMUM_PHP_VERSION बदल्नुहोस्
 * 2. "VERSION NOTES" section हेरेर deprecated functions check गर्नुहोस्
 * 3. cPanel मा PHP version मिलाउनुहोस् (Software > PHP Version)
 * 4. Admin panel को System Info page हेरेर status confirm गर्नुहोस्
 *
 * =====================================================================
 */

// ─────────────────────────────────────────────────────────────
// SECTION 1: VERSION REQUIREMENT
// यहाँ minimum PHP version set गर्नुहोस्
// ─────────────────────────────────────────────────────────────

define('REQUIRED_PHP_VERSION', '8.0');   // ← यो बदल्नुहोस् upgrade गर्दा
define('RECOMMENDED_PHP_VERSION', '8.2'); // ← recommended version

// Current version check — automatically detect गर्छ
define('CURRENT_PHP_VERSION', PHP_VERSION);
define('PHP_IS_COMPATIBLE', version_compare(PHP_VERSION, REQUIRED_PHP_VERSION, '>='));

// ─────────────────────────────────────────────────────────────
// SECTION 2: VERSION CHECK
// Site load हुँदा automatically check हुन्छ
// ─────────────────────────────────────────────────────────────

if (!PHP_IS_COMPATIBLE) {
    $msg  = "⚠️  PHP Version Error\n\n";
    $msg .= "यो website चलाउन PHP " . REQUIRED_PHP_VERSION . " वा माथि चाहिन्छ।\n";
    $msg .= "अहिले: PHP " . PHP_VERSION . "\n\n";
    $msg .= "cPanel → Software → PHP Version मा गएर update गर्नुहोस्।";

    if (php_sapi_name() === 'cli') {
        echo $msg;
    } else {
        http_response_code(500);
        echo '<div style="font-family:sans-serif;padding:40px;background:#fff3cd;border-left:5px solid #ff9800;margin:20px;border-radius:8px;">';
        echo '<h2 style="color:#e65100">⚠️ PHP Version पुरानो भयो</h2>';
        echo '<p>यो website चलाउन <strong>PHP ' . REQUIRED_PHP_VERSION . '+</strong> चाहिन्छ।</p>';
        echo '<p>अहिलेको version: <strong>PHP ' . PHP_VERSION . '</strong></p>';
        echo '<p>cPanel &rarr; Software &rarr; PHP Version मा गएर update गर्नुहोस्।</p>';
        echo '</div>';
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// SECTION 3: REQUIRED PHP EXTENSIONS CHECK
// यी extensions नभए site काम गर्दैन
// ─────────────────────────────────────────────────────────────

$_required_extensions = [
    'pdo'      => 'Database connection (PDO)',
    'pdo_mysql'=> 'MySQL database support',
    'mbstring' => 'Nepali/Unicode text support',
    'gd'       => 'Image upload/resize',
    'json'     => 'JSON data handling',
    'session'  => 'Login/session management',
    'openssl'  => 'Secure password hashing',
    'fileinfo' => 'File upload type checking',
];

$_missing_extensions = [];
foreach ($_required_extensions as $_ext => $_desc) {
    if (!extension_loaded($_ext)) {
        $_missing_extensions[$_ext] = $_desc;
    }
}

if (!empty($_missing_extensions) && !defined('SKIP_EXTENSION_CHECK')) {
    // Admin pages मात्र error देखाउँछ, public pages silent fail हुन्छ
    if (defined('IS_ADMIN_PAGE') && IS_ADMIN_PAGE) {
        error_log('Missing PHP extensions: ' . implode(', ', array_keys($_missing_extensions)));
    }
}

// ─────────────────────────────────────────────────────────────
// SECTION 4: POLYFILLS
// पुरानो PHP versions मा नभएका functions को replacement
// PHP version upgrade गर्दा यो section हटाउन सकिन्छ
// ─────────────────────────────────────────────────────────────

/**
 * str_contains() — PHP 8.0 मा आएको
 * PHP 7.x मा यो function छैन, तलको code ले replace गर्छ
 */
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

/**
 * str_starts_with() — PHP 8.0 मा आएको
 * PHP 7.x मा यो function छैन
 */
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

/**
 * str_ends_with() — PHP 8.0 मा आएको
 * PHP 7.x मा यो function छैन
 */
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

/**
 * array_is_list() — PHP 8.1 मा आएको
 */
if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool {
        if (empty($arr)) return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}

// ─────────────────────────────────────────────────────────────
// SECTION 5: VERSION NOTES — UPGRADE GUIDE
// PHP version upgrade गर्दा यो section पढ्नुहोस्
// ─────────────────────────────────────────────────────────────

/*
 * ┌─────────────────────────────────────────────────────────────┐
 * │  PHP 8.0 → 8.1 UPGRADE                                      │
 * ├─────────────────────────────────────────────────────────────┤
 * │  • array_is_list() polyfill माथि हटाउन सकिन्छ               │
 * │  • Enum support थपिएको — optional                            │
 * │  • Readonly properties support थपिएको — optional            │
 * │  • Intersection types support — optional                    │
 * └─────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  PHP 8.1 → 8.2 UPGRADE                                      │
 * ├─────────────────────────────────────────────────────────────┤
 * │  • Dynamic properties deprecated — check गर्नुहोस्            │
 * │    ( $obj->undeclared_prop = 'x' type code )                │
 * │  • ${var} string interpolation deprecated:                  │
 * │    "${foo}" → "{$foo}" मा बदल्नुहोस्                         │
 * │  • utf8_encode() / utf8_decode() deprecated:                │
 * │    mb_convert_encoding() use गर्नुहोस्                        │
 * └─────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  PHP 8.2 → 8.3 UPGRADE                                      │
 * ├─────────────────────────────────────────────────────────────┤
 * │  • json_validate() नयाँ function थपिएको                      │
 * │  • Typed class constants support थपिएको                     │
 * │  • #[Override] attribute थपिएको                             │
 * │  • Code changes सामान्यतः minimal हुन्छ                      │
 * └─────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  DATABASE: PDO — version independent                        │
 * ├─────────────────────────────────────────────────────────────┤
 * │  यो project PDO use गर्छ जुन stable छ।                      │
 * │  PHP version upgrade गर्दा database code बदल्न पर्दैन।      │
 * └─────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  SESSION — version independent                              │
 * ├─────────────────────────────────────────────────────────────┤
 * │  session_start() सबै PHP 7.x / 8.x मा काम गर्छ।            │
 * │  config.php को line 739 मा session config छ।                │
 * └─────────────────────────────────────────────────────────────┘
 *
 * ┌─────────────────────────────────────────────────────────────┐
 * │  match() EXPRESSION — PHP 8.0+ मात्र                        │
 * ├─────────────────────────────────────────────────────────────┤
 * │  Files: application-tracker.php                             │
 * │  PHP 7.x मा downgrade गर्नुपरे switch() मा बदल्नुहोस्       │
 * └─────────────────────────────────────────────────────────────┘
 */

// ─────────────────────────────────────────────────────────────
// SECTION 6: SYSTEM INFO (Admin use को लागि)
// ─────────────────────────────────────────────────────────────

/**
 * PHP version र extensions को status return गर्छ
 * Admin System Info page ले यो use गर्छ
 */
function getSystemCompatibilityInfo(): array {
    global $_required_extensions, $_missing_extensions;

    $extensions_status = [];
    foreach (($GLOBALS['_required_extensions'] ?? []) as $ext => $desc) {
        $extensions_status[$ext] = [
            'name'      => $desc,
            'loaded'    => extension_loaded($ext),
            'version'   => phpversion($ext) ?: 'built-in',
        ];
    }

    return [
        'php_version'        => PHP_VERSION,
        'required_version'   => REQUIRED_PHP_VERSION,
        'recommended_version'=> RECOMMENDED_PHP_VERSION,
        'is_compatible'      => PHP_IS_COMPATIBLE,
        'is_recommended'     => version_compare(PHP_VERSION, RECOMMENDED_PHP_VERSION, '>='),
        'extensions'         => $extensions_status,
        'missing_extensions' => $GLOBALS['_missing_extensions'] ?? [],
        'os'                 => PHP_OS,
        'sapi'               => PHP_SAPI,
        'max_upload'         => ini_get('upload_max_filesize'),
        'max_post'           => ini_get('post_max_size'),
        'memory_limit'       => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time') . 's',
    ];
}
