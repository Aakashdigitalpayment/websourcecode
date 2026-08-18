<?php
/**
 * सदस्य बजार / सीप कामदार — schema + helpers
 * Member portal listings (product + skill) → admin approve → public थप menu
 */
if (!function_exists('ensureMemberMarketplaceTables')) {
    function ensureMemberMarketplaceTables(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS member_marketplace_listings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                listing_type ENUM('product','skill') NOT NULL,
                category VARCHAR(80) NOT NULL DEFAULT '',
                title VARCHAR(200) NOT NULL,
                description TEXT,
                unit VARCHAR(40) NOT NULL DEFAULT '',
                price DECIMAL(12,2) NULL DEFAULT NULL,
                price_note VARCHAR(120) NOT NULL DEFAULT '',
                quantity VARCHAR(80) NOT NULL DEFAULT '',
                experience_years TINYINT UNSIGNED NULL DEFAULT NULL,
                location VARCHAR(200) NOT NULL DEFAULT '',
                contact_name VARCHAR(120) NOT NULL DEFAULT '',
                contact_phone VARCHAR(20) NOT NULL DEFAULT '',
                image VARCHAR(255) NULL DEFAULT NULL,
                available_from DATE NULL DEFAULT NULL,
                available_until DATETIME NULL DEFAULT NULL,
                available_time_from TIME NULL DEFAULT NULL,
                available_time_to TIME NULL DEFAULT NULL,
                status ENUM('pending','approved','rejected','expired','withdrawn') NOT NULL DEFAULT 'pending',
                admin_note VARCHAR(500) NOT NULL DEFAULT '',
                approved_at DATETIME NULL DEFAULT NULL,
                approved_by INT NULL DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_mkt_public (listing_type, status, is_active, available_until),
                INDEX idx_mkt_member (member_id, created_at),
                INDEX idx_mkt_status (status, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS member_marketplace_inquiries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                listing_id INT NOT NULL,
                member_id INT NULL DEFAULT NULL,
                inquirer_name VARCHAR(120) NOT NULL,
                inquirer_phone VARCHAR(20) NOT NULL DEFAULT '',
                message VARCHAR(1000) NOT NULL DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_mkt_inq_listing (listing_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $done = true;
        } catch (Throwable $e) {
            /* page नभाँड्ने */
        }
    }
}

if (!function_exists('mpExpireStaleListings')) {
    /** मूल्य/उपलब्ध समय सकिएका स्वीकृत सूची सार्वजनिकबाट हटाउने */
    function mpExpireStaleListings(?PDO $db = null): int
    {
        if (!$db instanceof PDO) {
            return 0;
        }
        try {
            ensureMemberMarketplaceTables($db);
            $st = $db->prepare(
                "UPDATE member_marketplace_listings
                 SET status = 'expired'
                 WHERE status = 'approved'
                   AND available_until IS NOT NULL
                   AND available_until < NOW()"
            );
            $st->execute();
            return (int) $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('mpProductCategories')) {
    /** @return array<string, array{np:string,en:string,icon:string}> */
    function mpProductCategories(): array
    {
        return [
            'vegetables' => ['np' => 'तरकारी', 'en' => 'Vegetables', 'icon' => 'fa-carrot'],
            'fruits'     => ['np' => 'फलफूल', 'en' => 'Fruits', 'icon' => 'fa-apple-whole'],
            'grains'     => ['np' => 'अन्नबाली', 'en' => 'Grains', 'icon' => 'fa-wheat-awn'],
            'dairy'      => ['np' => 'दूध / दुग्ध', 'en' => 'Dairy', 'icon' => 'fa-cow'],
            'livestock'  => ['np' => 'पशुपक्षी', 'en' => 'Livestock', 'icon' => 'fa-dove'],
            'honey'      => ['np' => 'मह / मौरी', 'en' => 'Honey', 'icon' => 'fa-jar'],
            'spices'     => ['np' => 'मसला', 'en' => 'Spices', 'icon' => 'fa-mortar-pestle'],
            'handicraft' => ['np' => 'हस्तकला', 'en' => 'Handicraft', 'icon' => 'fa-hands'],
            'processed'  => ['np' => 'प्रशोधित खाद्य', 'en' => 'Processed food', 'icon' => 'fa-bowl-food'],
            'other'      => ['np' => 'अन्य उत्पादन', 'en' => 'Other produce', 'icon' => 'fa-basket-shopping'],
        ];
    }
}

if (!function_exists('mpSkillCategories')) {
    /** @return array<string, array{np:string,en:string,icon:string}> */
    function mpSkillCategories(): array
    {
        return [
            'plumber'     => ['np' => 'प्लम्बर', 'en' => 'Plumber', 'icon' => 'fa-wrench'],
            'electrician' => ['np' => 'विद्युत मिस्त्री', 'en' => 'Electrician', 'icon' => 'fa-bolt'],
            'carpenter'   => ['np' => 'सिकर्मी', 'en' => 'Carpenter', 'icon' => 'fa-hammer'],
            'mason'       => ['np' => 'राजमिस्त्री', 'en' => 'Mason', 'icon' => 'fa-trowel-bricks'],
            'beautician'  => ['np' => 'सौन्दर्य / ब्युटीसियन', 'en' => 'Beautician', 'icon' => 'fa-spa'],
            'tailor'      => ['np' => 'सिलाइ', 'en' => 'Tailor', 'icon' => 'fa-scissors'],
            'driver'      => ['np' => 'चालक', 'en' => 'Driver', 'icon' => 'fa-car'],
            'tutor'       => ['np' => 'शिक्षण / ट्युसन', 'en' => 'Tutor', 'icon' => 'fa-chalkboard-user'],
            'mechanic'    => ['np' => 'मेकानिक', 'en' => 'Mechanic', 'icon' => 'fa-gears'],
            'painter'     => ['np' => 'रंगरोगन', 'en' => 'Painter', 'icon' => 'fa-paint-roller'],
            'agri'        => ['np' => 'कृषि प्राविधिक', 'en' => 'Agri technician', 'icon' => 'fa-seedling'],
            'skill_other' => ['np' => 'अन्य सीप', 'en' => 'Other skill', 'icon' => 'fa-briefcase'],
        ];
    }
}

if (!function_exists('mpCategoryLabel')) {
    function mpCategoryLabel(string $type, string $key, bool $english = false): string
    {
        if ($type === 'skill' && $key === 'other') {
            $key = 'skill_other';
        }
        $map = ($type === 'skill') ? mpSkillCategories() : mpProductCategories();
        if (!isset($map[$key])) {
            return $key !== '' ? $key : '—';
        }
        return $english ? $map[$key]['en'] : $map[$key]['np'];
    }
}

if (!function_exists('mpCategoryIcon')) {
    function mpCategoryIcon(string $type, string $key): string
    {
        $map = ($type === 'skill') ? mpSkillCategories() : mpProductCategories();
        return $map[$key]['icon'] ?? 'fa-tag';
    }
}

if (!function_exists('mpNormalizeDate')) {
    function mpNormalizeDate(string $dateIn): string
    {
        $dateIn = trim($dateIn);
        if ($dateIn === '' || !preg_match('/^(\d{4})-\d{2}-\d{2}/', $dateIn, $m)) {
            return '';
        }
        $datePart = substr($dateIn, 0, 10);
        $y = (int) $m[1];
        if ($y >= 2070 && function_exists('bsToAd')) {
            $ad = trim((string) bsToAd($datePart));
            return preg_match('/^\d{4}-\d{2}-\d{2}/', $ad) ? substr($ad, 0, 10) : '';
        }
        return $datePart;
    }
}

if (!function_exists('mpNormalizeTime')) {
    function mpNormalizeTime(string $timeIn): string
    {
        $timeIn = trim($timeIn);
        if ($timeIn === '') {
            return '';
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $timeIn, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            $s = isset($m[3]) ? (int) $m[3] : 0;
            if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
                return sprintf('%02d:%02d:%02d', $h, $i, $s);
            }
        }
        return '';
    }
}

if (!function_exists('mpCombineUntil')) {
    function mpCombineUntil(string $dateAd, string $timeHms = ''): ?string
    {
        if ($dateAd === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateAd)) {
            return null;
        }
        $time = $timeHms !== '' ? $timeHms : '23:59:59';
        return $dateAd . ' ' . $time;
    }
}

if (!function_exists('mpIsPubliclyVisible')) {
    function mpIsPubliclyVisible(array $row): bool
    {
        if ((string) ($row['status'] ?? '') !== 'approved') {
            return false;
        }
        if ((int) ($row['is_active'] ?? 0) !== 1) {
            return false;
        }
        $from = trim((string) ($row['available_from'] ?? ''));
        if ($from !== '') {
            $fromTs = strtotime($from . ' 00:00:00');
            if ($fromTs !== false && $fromTs > time()) {
                return false;
            }
        }
        $until = trim((string) ($row['available_until'] ?? ''));
        if ($until !== '') {
            $ts = strtotime($until);
            if ($ts !== false && $ts < time()) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('mpPublicWhereSql')) {
    function mpPublicWhereSql(): string
    {
        return "status = 'approved' AND is_active = 1
                AND (available_from IS NULL OR available_from <= CURDATE())
                AND (available_until IS NULL OR available_until >= NOW())";
    }
}

if (!function_exists('mpIsApproveEligible')) {
    /** Admin स्वीकृत गर्न मिल्ने — available_until पहिले नै सकिएको भए false */
    function mpIsApproveEligible(array $row): bool
    {
        $until = trim((string) ($row['available_until'] ?? ''));
        if ($until === '') {
            return true;
        }
        $ts = strtotime($until);
        return $ts === false || $ts >= time();
    }
}

if (!function_exists('mpFetchPublicListings')) {
    /**
     * @return list<array<string,mixed>>
     */
    function mpFetchPublicListings(PDO $db, string $type, int $limit = 120, string $category = '', string $q = ''): array
    {
        $type = $type === 'skill' ? 'skill' : 'product';
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT * FROM member_marketplace_listings WHERE listing_type = ? AND ' . mpPublicWhereSql();
        $params = [$type];
        if ($category !== '') {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        if ($q !== '') {
            $sql .= ' AND (title LIKE ? OR description LIKE ? OR location LIKE ? OR contact_name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY approved_at DESC, id DESC LIMIT ' . $limit;
        try {
            $st = $db->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('mpFetchPublicCount')) {
    function mpFetchPublicCount(PDO $db, string $type): int
    {
        $type = $type === 'skill' ? 'skill' : 'product';
        try {
            $st = $db->prepare(
                'SELECT COUNT(*) FROM member_marketplace_listings WHERE listing_type = ? AND ' . mpPublicWhereSql()
            );
            $st->execute([$type]);
            return (int) $st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('mpFetchListingById')) {
    /** @return array<string,mixed>|null */
    function mpFetchListingById(PDO $db, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        try {
            $st = $db->prepare('SELECT * FROM member_marketplace_listings WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('mpPriceDisplay')) {
    function mpPriceDisplay(array $row, bool $english = false): string
    {
        $price = $row['price'] ?? null;
        $note = trim((string) ($row['price_note'] ?? ''));
        $unit = trim((string) ($row['unit'] ?? ''));
        if ($price === null || $price === '' || (float) $price <= 0) {
            if ($note !== '') {
                return $note;
            }
            return $english ? 'Negotiable' : 'सम्झौता अनुसार';
        }
        $txt = 'रु. ' . number_format((float) $price, 0);
        if ($note !== '') {
            $txt .= ' / ' . $note;
        } elseif ($unit !== '') {
            $txt .= ' / ' . $unit;
        }
        return $txt;
    }
}

if (!function_exists('mpListingImageUrl')) {
    function mpListingImageUrl(array $row): string
    {
        $img = trim((string) ($row['image'] ?? ''));
        if ($img === '') {
            return '';
        }
        if (preg_match('#^(https?:)?//#i', $img)) {
            return $img;
        }
        $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') . '/' : '';
        return $base . ltrim($img, '/');
    }
}

if (!function_exists('mpPhoneDigits')) {
    function mpPhoneDigits(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }
}

if (!function_exists('mpWhatsAppUrl')) {
    function mpWhatsAppUrl(string $phone, string $text = ''): string
    {
        $d = mpPhoneDigits($phone);
        if (strlen($d) === 10 && $d[0] === '9') {
            $d = '977' . $d;
        }
        if (strlen($d) < 10) {
            return '';
        }
        $url = 'https://wa.me/' . $d;
        if ($text !== '') {
            $url .= '?text=' . rawurlencode($text);
        }
        return $url;
    }
}

if (!function_exists('mpStatusMeta')) {
    /** @return array{np:string,en:string,class:string} */
    function mpStatusMeta(string $status): array
    {
        $map = [
            'pending'   => ['np' => 'स्वीकृति पर्खाइमा', 'en' => 'Pending approval', 'class' => 'warning'],
            'approved'  => ['np' => 'स्वीकृत / सार्वजनिक', 'en' => 'Approved / public', 'class' => 'success'],
            'rejected'  => ['np' => 'अस्वीकृत', 'en' => 'Rejected', 'class' => 'danger'],
            'expired'   => ['np' => 'अवधि सकियो', 'en' => 'Expired', 'class' => 'secondary'],
            'withdrawn' => ['np' => 'फिर्ता', 'en' => 'Withdrawn', 'class' => 'secondary'],
        ];
        return $map[$status] ?? ['np' => $status, 'en' => $status, 'class' => 'secondary'];
    }
}

if (!function_exists('mpTypeLabel')) {
    function mpTypeLabel(string $type, bool $english = false): string
    {
        if ($type === 'skill') {
            return $english ? 'Skill / worker' : 'सीप / कामदार';
        }
        return $english ? 'Product' : 'उत्पादन';
    }
}

if (!function_exists('mpPublicPageUrl')) {
    function mpPublicPageUrl(string $type, int $id = 0): string
    {
        $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') . '/' : '';
        $file = $type === 'skill' ? 'member-skills.php' : 'member-marketplace.php';
        if ($id > 0) {
            return $base . $file . '?id=' . $id;
        }
        return $base . $file;
    }
}

if (!function_exists('mpDefaultUntilDays')) {
    function mpDefaultUntilDays(): int
    {
        return 30;
    }
}
