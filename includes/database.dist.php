<?php

/*
|--------------------------------------------------------------------------
| DATABASE + INSTALL (tracked — git pull safe)
|--------------------------------------------------------------------------
| Live / multi-site: secrets ONLY in includes/database.local.php (gitignored).
| Example: includes/database.local.php.example → copy + fill DB_HOST/NAME/USER/PASS.
| Legacy cPanel: includes/database.php (if still present) also loads.
| Pull/merge ले credentials overwrite गर्दैन — data/config सुरक्षित रहन्छ।
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
