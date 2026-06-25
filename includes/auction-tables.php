<?php
/**
 * लिलामी — admin/auctions.php सँग मिल्ने canonical DDL + migration
 */
if (!function_exists('ensureAuctionTables')) {
    function ensureAuctionTables(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS auction_notices (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        tracking_number  VARCHAR(30) UNIQUE,
        title            VARCHAR(255) NOT NULL,
        title_en         VARCHAR(255),
        description      TEXT,
        description_en   TEXT,
        property_type    VARCHAR(100),
        location         VARCHAR(255),
        google_map_link  VARCHAR(600),
        google_map_embed TEXT,
        area_bigha       DECIMAL(10,2) DEFAULT 0,
        area_ropani      DECIMAL(10,2) DEFAULT 0,
        area_aana        DECIMAL(10,2) DEFAULT 0,
        area_paisa       DECIMAL(10,2) DEFAULT 0,
        area             VARCHAR(100),
        minimum_price    DECIMAL(15,2) DEFAULT 0,
        auction_date     DATE NULL,
        auction_time     VARCHAR(30),
        contact_person   VARCHAR(120),
        contact_phone    VARCHAR(20),
        image            VARCHAR(255),
        images           TEXT COMMENT 'JSON array of additional images',
        document         VARCHAR(255) COMMENT 'PDF/Word document path',
        status           ENUM('upcoming','ongoing','completed','cancelled') DEFAULT 'upcoming',
        is_active        TINYINT(1) DEFAULT 1,
        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_date   (auction_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            if (function_exists('safeAddColumn')) {
                safeAddColumn($db, 'auction_notices', 'tracking_number', "VARCHAR(30)");
                safeAddColumn($db, 'auction_notices', 'google_map_link', "VARCHAR(600)");
                safeAddColumn($db, 'auction_notices', 'google_map_embed', 'TEXT');
                safeAddColumn($db, 'auction_notices', 'area_bigha', 'DECIMAL(10,2) DEFAULT 0');
                safeAddColumn($db, 'auction_notices', 'area_ropani', 'DECIMAL(10,2) DEFAULT 0');
                safeAddColumn($db, 'auction_notices', 'area_aana', 'DECIMAL(10,2) DEFAULT 0');
                safeAddColumn($db, 'auction_notices', 'area_paisa', 'DECIMAL(10,2) DEFAULT 0');
                safeAddColumn($db, 'auction_notices', 'images', 'TEXT');
                safeAddColumn($db, 'auction_notices', 'document', 'VARCHAR(255)');
                safeAddColumn($db, 'auction_notices', 'title_en', 'VARCHAR(255)');
                safeAddColumn($db, 'auction_notices', 'description_en', 'TEXT');
            }

            try {
                $db->exec("ALTER TABLE auction_notices MODIFY COLUMN status ENUM('upcoming','ongoing','completed','cancelled') DEFAULT 'upcoming'");
            } catch (Throwable $e) {
            }

            $db->exec("CREATE TABLE IF NOT EXISTS auction_bids (
        id INT AUTO_INCREMENT PRIMARY KEY,
        auction_id INT NOT NULL,
        bidder_name VARCHAR(120) NOT NULL,
        bidder_phone VARCHAR(20) NOT NULL,
        bidder_email VARCHAR(120),
        bidder_address VARCHAR(255),
        bid_amount DECIMAL(15,2) NOT NULL,
        message TEXT,
        status ENUM('pending','accepted','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_auction (auction_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            if (function_exists('safeAddColumn')) {
                safeAddColumn($db, 'auction_bids', 'bidder_address', 'VARCHAR(255)');
            }

            $done = true;
        } catch (Throwable $e) {
        }
    }
}
