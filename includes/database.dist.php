<?php

/*
|--------------------------------------------------------------------------
| DATABASE + INSTALL (tracked — git pull safe)
|--------------------------------------------------------------------------
| Secrets: includes/database.local.php (gitignored) — example: database.local.php.example
| Legacy: यदि includes/database.php अझै छ (पुरानो cPanel) त्यो पनि load हुन्छ।
|--------------------------------------------------------------------------
*/

if (is_readable(__DIR__ . '/database.local.php')) {
    require_once __DIR__ . '/database.local.php';
} elseif (is_readable(__DIR__ . '/database.php')) {
    require_once __DIR__ . '/database.php';
}

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', '');
}
if (!defined('DB_USER')) {
    define('DB_USER', '');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}

/*
| SITE_URL: database.local.php वा legacy database.php मा define गर्नुहोस्।
| नभए admin panel / config.php ले dynamic URL प्रयोग गर्छ।
*/
