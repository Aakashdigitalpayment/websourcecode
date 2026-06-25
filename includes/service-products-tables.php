<?php
/**
 * सेवाका products/sub-items तालिका
 */
if (!function_exists('ensureServiceProductsTables')) {
    function ensureServiceProductsTables(?PDO $db = null): void
    {
        static $done = false;
        if ($done) return;
        if (!$db && function_exists('getDB')) {
            try { $db = getDB(); } catch (Throwable $e) { return; }
        }
        if (!$db instanceof PDO) return;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS service_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                service_id INT NOT NULL,
                title_np VARCHAR(255) NOT NULL,
                title_en VARCHAR(255) DEFAULT '',
                description_np TEXT NULL,
                description_en TEXT NULL,
                display_order INT DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_service (service_id),
                INDEX idx_service_active (service_id, is_active),
                CONSTRAINT fk_service_products_service
                    FOREIGN KEY (service_id) REFERENCES services(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $done = true;
        } catch (Throwable $e) {
        }
    }
}

