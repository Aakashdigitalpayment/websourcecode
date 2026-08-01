<?php
/**
 * लिलामी — admin/auctions.php सँग मिल्ने canonical DDL + helpers
 */
if (!function_exists('auctionNormalizeDate')) {
    /** nepali-datepicker BS (२०७०+) → AD Y-m-d for DATE columns. */
    function auctionNormalizeDate(string $dateIn): string
    {
        $dateIn = trim($dateIn);
        if ($dateIn === '' || !preg_match('/^(\d{4})-\d{2}-\d{2}/', $dateIn, $m)) {
            return '';
        }
        $datePart = substr($dateIn, 0, 10);
        $y = (int)$m[1];
        if ($y >= 2070 && function_exists('bsToAd')) {
            $ad = trim((string)bsToAd($datePart));
            return preg_match('/^\d{4}-\d{2}-\d{2}/', $ad) ? substr($ad, 0, 10) : '';
        }
        return $datePart;
    }
}

if (!function_exists('auctionFormatDateDisplay')) {
    function auctionFormatDateDisplay(?string $adDate): string
    {
        $adDate = trim((string)$adDate);
        if ($adDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $adDate)) {
            return '—';
        }
        $ad = substr($adDate, 0, 10);
        if (function_exists('formatNepaliDate')) {
            return (string)formatNepaliDate($ad);
        }
        if (function_exists('adToBs')) {
            $bs = trim((string)adToBs($ad));
            if ($bs !== '') {
                return $bs;
            }
        }
        return $ad;
    }
}

if (!function_exists('auctionIsOpenForBids')) {
    function auctionIsOpenForBids(array $auction): bool
    {
        if (empty($auction['is_active'])) {
            return false;
        }
        $st = (string)($auction['status'] ?? '');
        return in_array($st, ['upcoming', 'ongoing'], true);
    }
}

if (!function_exists('auctionSanitizeMapEmbed')) {
    /** Allow only iframe embeds (strip scripts). */
    function auctionSanitizeMapEmbed(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/<iframe\b[^>]*>.*?<\/iframe>/is', $raw, $m)) {
            $iframe = $m[0];
            if (!preg_match('/\ssrc\s*=\s*["\']https?:\/\//i', $iframe)) {
                return '';
            }
            /* Drop on* handlers */
            $iframe = preg_replace('/\s+on\w+\s*=\s*("|\')[^\1]*\1/i', '', $iframe);
            return $iframe;
        }
        /* Plain Google Maps URL → leave empty (use map_link field) */
        return '';
    }
}

if (!function_exists('auctionTitle')) {
    function auctionTitle(array $row): string
    {
        if (function_exists('isEnglish') && isEnglish()) {
            $en = trim((string)($row['title_en'] ?? ''));
            if ($en !== '') {
                return $en;
            }
        }
        return trim((string)($row['title'] ?? ''));
    }
}

if (!function_exists('auctionDescription')) {
    function auctionDescription(array $row): string
    {
        if (function_exists('isEnglish') && isEnglish()) {
            $en = trim((string)($row['description_en'] ?? ''));
            if ($en !== '') {
                return $en;
            }
        }
        return trim((string)($row['description'] ?? ''));
    }
}

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
        tracking_id VARCHAR(40) NULL DEFAULT NULL,
        bidder_name VARCHAR(120) NOT NULL,
        bidder_phone VARCHAR(20) NOT NULL,
        bidder_email VARCHAR(120),
        bidder_address VARCHAR(255),
        bid_amount DECIMAL(15,2) NOT NULL,
        message TEXT,
        status ENUM('pending','accepted','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_auction (auction_id),
        INDEX idx_status (status),
        UNIQUE KEY uniq_bid_tracking (tracking_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            if (function_exists('safeAddColumn')) {
                safeAddColumn($db, 'auction_bids', 'bidder_address', 'VARCHAR(255)');
                safeAddColumn($db, 'auction_bids', 'tracking_id', 'VARCHAR(40) NULL DEFAULT NULL');
            }

            $done = true;
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('auctionGenerateBidTrackingId')) {
    function auctionGenerateBidTrackingId(?PDO $db = null): string
    {
        for ($i = 0; $i < 8; $i++) {
            $id = 'BID-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            if (!$db instanceof PDO) {
                return $id;
            }
            try {
                $st = $db->prepare('SELECT COUNT(*) FROM auction_bids WHERE tracking_id=?');
                $st->execute([$id]);
                if ((int)$st->fetchColumn() === 0) {
                    return $id;
                }
            } catch (Throwable $e) {
                return $id;
            }
        }
        return 'BID-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    }
}
