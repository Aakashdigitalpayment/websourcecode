<?php
/**
 * भेन्डर सूचीकरण — public + admin + ensure-admin एकै DDL
 */
if (!function_exists('ensureVendorsTables')) {
    function ensureVendorsTables(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (!$db && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Throwable $e) {
                return;
            }
        }
        if (!$db instanceof PDO) {
            return;
        }
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS vendors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(255) NOT NULL,
            owner_name VARCHAR(100),
            address VARCHAR(255),
            phone VARCHAR(20),
            email VARCHAR(100),
            pan_no VARCHAR(50),
            business_type VARCHAR(100),
            description TEXT,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            foreach ([
                'ALTER TABLE vendors ADD COLUMN tracking_id VARCHAR(60) UNIQUE NULL',
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Throwable $e) {
                }
            }

            $done = true;
        } catch (Throwable $e) {
        }
    }
}
