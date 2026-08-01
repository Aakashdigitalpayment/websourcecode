<?php
/**
 * सम्मान दरखास्त — schema + open-window helpers
 * honor_programs / honor_categories / honor_program_categories / honor_applications
 */
if (!function_exists('ensureHonorTables')) {
    function ensureHonorTables($db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (!$db && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Exception $e) {
                return;
            }
        }
        if (!$db instanceof PDO) {
            return;
        }
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS honor_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(60) NOT NULL,
                name_np VARCHAR(160) NOT NULL,
                name_en VARCHAR(160) DEFAULT '',
                requires_nominee TINYINT(1) NOT NULL DEFAULT 1,
                requires_document TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                display_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_honor_cat_slug (slug),
                INDEX idx_honor_cat_active (is_active),
                INDEX idx_honor_cat_order (display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS honor_programs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title_np VARCHAR(200) NOT NULL,
                title_en VARCHAR(200) DEFAULT '',
                event_label VARCHAR(120) DEFAULT '',
                fiscal_year VARCHAR(40) DEFAULT '',
                opens_at DATETIME NOT NULL,
                closes_at DATETIME NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                show_new_badge TINYINT(1) NOT NULL DEFAULT 1,
                instructions_np TEXT,
                instructions_en TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_honor_prog_active (is_active),
                INDEX idx_honor_prog_window (opens_at, closes_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS honor_program_categories (
                program_id INT NOT NULL,
                category_id INT NOT NULL,
                PRIMARY KEY (program_id, category_id),
                INDEX idx_hpc_cat (category_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS honor_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tracking_id VARCHAR(60) NOT NULL,
                program_id INT NOT NULL,
                category_id INT NOT NULL,
                applicant_name VARCHAR(160) NOT NULL,
                phone VARCHAR(20) NOT NULL,
                email VARCHAR(120) DEFAULT '',
                address VARCHAR(255) DEFAULT '',
                is_member TINYINT(1) NOT NULL DEFAULT 0,
                member_id VARCHAR(50) DEFAULT '',
                member_portal_id INT NULL,
                nominee_name VARCHAR(160) DEFAULT '',
                nominee_relation VARCHAR(80) DEFAULT '',
                exam_year VARCHAR(40) DEFAULT '',
                institution VARCHAR(200) DEFAULT '',
                business_note VARCHAR(255) DEFAULT '',
                description TEXT,
                attachment VARCHAR(255) DEFAULT '',
                status ENUM('pending','under_review','shortlisted','selected','rejected','closed') NOT NULL DEFAULT 'pending',
                admin_remarks TEXT,
                reviewed_by VARCHAR(100) DEFAULT '',
                reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_honor_tracking (tracking_id),
                INDEX idx_honor_app_program (program_id),
                INDEX idx_honor_app_category (category_id),
                INDEX idx_honor_app_status (status),
                INDEX idx_honor_app_phone (phone),
                INDEX idx_honor_app_created (created_at),
                INDEX idx_honor_app_portal (member_portal_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            honorSeedDefaultCategories($db);
            $done = true;
        } catch (Throwable $e) {
            error_log('[honor-tables] ' . $e->getMessage());
        }
    }
}

if (!function_exists('honorSeedDefaultCategories')) {
    function honorSeedDefaultCategories(PDO $db): void
    {
        try {
            $count = (int)$db->query('SELECT COUNT(*) FROM honor_categories')->fetchColumn();
            if ($count > 0) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }
        $defaults = [
            ['see', 'SEE पास', 'SEE Pass', 1, 1, 10],
            ['plus2', 'प्लस टू / कक्षा १२ पास', 'Plus Two / Grade 12 Pass', 1, 1, 20],
            ['bachelor', 'स्नातक पास', 'Bachelor Pass', 1, 1, 30],
            ['masters', 'स्नातकोत्तर पास', 'Masters Pass', 1, 1, 40],
            ['phd', 'विद्यावारिधि (PhD) पास', 'PhD Pass', 1, 1, 50],
            ['doctor', 'चिकित्सक बनेको', 'Became a Doctor', 1, 1, 60],
            ['senior_member', 'ज्येष्ठ सदस्य', 'Senior Member', 0, 0, 70],
            ['best_loan', 'असल कारोबारी ऋण', 'Best Business Loan', 0, 0, 80],
            ['best_saving', 'असल कारोबारी बचत', 'Best Business Saving', 0, 0, 90],
            ['best_child_saving', 'असल कारोबारी बाल बचत', 'Best Child Saving', 0, 0, 100],
            ['other', 'अन्य सम्मान', 'Other Honor', 0, 0, 110],
        ];
        $stmt = $db->prepare('INSERT INTO honor_categories (slug, name_np, name_en, requires_nominee, requires_document, is_active, display_order) VALUES (?,?,?,?,?,1,?)');
        foreach ($defaults as $row) {
            try {
                $stmt->execute($row);
            } catch (Throwable $e) {
                /* ignore duplicates */
            }
        }
    }
}

if (!function_exists('honorNowSql')) {
    /** Server-local now for window checks (same as careers CURDATE style). */
    function honorNowSql(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('honorIsProgramOpenRow')) {
    function honorIsProgramOpenRow(?array $program): bool
    {
        if (!$program || empty($program['is_active'])) {
            return false;
        }
        $now = honorNowSql();
        $opens = (string)($program['opens_at'] ?? '');
        $closes = (string)($program['closes_at'] ?? '');
        if ($opens === '' || $closes === '') {
            return false;
        }
        return $now >= $opens && $now <= $closes;
    }
}

if (!function_exists('honorFetchOpenPrograms')) {
    /**
     * @return list<array<string,mixed>>
     */
    function honorFetchOpenPrograms(PDO $db): array
    {
        try {
            ensureHonorTables($db);
            $now = honorNowSql();
            $stmt = $db->prepare('SELECT * FROM honor_programs
                WHERE is_active = 1 AND opens_at <= ? AND closes_at >= ?
                ORDER BY closes_at ASC, id DESC');
            $stmt->execute([$now, $now]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('honorFetchUpcomingPrograms')) {
    /**
     * Active programs that have not opened yet (visible on public as “coming soon”).
     * @return list<array<string,mixed>>
     */
    function honorFetchUpcomingPrograms(PDO $db): array
    {
        try {
            ensureHonorTables($db);
            $now = honorNowSql();
            $stmt = $db->prepare('SELECT * FROM honor_programs
                WHERE is_active = 1 AND opens_at > ?
                ORDER BY opens_at ASC, id DESC
                LIMIT 20');
            $stmt->execute([$now]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('honorHasOpenProgram')) {
    function honorHasOpenProgram(?PDO $db = null): bool
    {
        if (!$db instanceof PDO && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Throwable $e) {
                return false;
            }
        }
        if (!$db instanceof PDO) {
            return false;
        }
        return count(honorFetchOpenPrograms($db)) > 0;
    }
}

if (!function_exists('honorHasPublicProgram')) {
    /** Nav / discoverability: currently open OR upcoming (active). */
    function honorHasPublicProgram(?PDO $db = null): bool
    {
        if (!$db instanceof PDO && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Throwable $e) {
                return false;
            }
        }
        if (!$db instanceof PDO) {
            return false;
        }
        if (honorHasOpenProgram($db)) {
            return true;
        }
        return count(honorFetchUpcomingPrograms($db)) > 0;
    }
}

if (!function_exists('honorFormatDtBs')) {
    /** Display MySQL DATETIME as BS date + time (falls back to AD). */
    function honorFormatDtBs(?string $mysqlDt): string
    {
        $mysqlDt = trim((string)$mysqlDt);
        if ($mysqlDt === '') {
            return '';
        }
        $ad = substr($mysqlDt, 0, 10);
        $time = strlen($mysqlDt) >= 16 ? substr($mysqlDt, 11, 5) : '';
        $bs = (function_exists('adToBs') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ad))
            ? trim((string)adToBs($ad))
            : $ad;
        if ($bs === '') {
            $bs = $ad;
        }
        return trim($bs . ($time !== '' ? ' ' . $time : ''));
    }
}

if (!function_exists('honorCombineBsDateTime')) {
    /** BS (or AD) date + H:i → AD MySQL DATETIME for storage. */
    function honorCombineBsDateTime(string $dateIn, string $timeIn): string
    {
        $dateIn = trim($dateIn);
        $timeIn = trim($timeIn);
        if ($dateIn === '' || $timeIn === '') {
            return '';
        }
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeIn)) {
            return '';
        }
        if (substr_count($timeIn, ':') === 1) {
            $timeIn .= ':00';
        }
        $datePart = substr($dateIn, 0, 10);
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', $datePart, $m)) {
            return '';
        }
        $y = (int)$m[1];
        if ($y >= 2070 && function_exists('bsToAd')) {
            $ad = trim((string)bsToAd($datePart));
            $ad = preg_match('/^\d{4}-\d{2}-\d{2}/', $ad) ? substr($ad, 0, 10) : '';
        } else {
            $ad = $datePart;
        }
        if ($ad === '') {
            return '';
        }
        $ts = strtotime($ad . ' ' . $timeIn);
        return $ts ? date('Y-m-d H:i:s', $ts) : '';
    }
}

if (!function_exists('honorProgramWindowState')) {
    /** @return 'open'|'upcoming'|'closed'|'inactive' */
    function honorProgramWindowState(?array $program): string
    {
        if (!$program || empty($program['is_active'])) {
            return 'inactive';
        }
        $now = honorNowSql();
        $opens = (string)($program['opens_at'] ?? '');
        $closes = (string)($program['closes_at'] ?? '');
        if ($opens === '' || $closes === '') {
            return 'closed';
        }
        if ($now < $opens) {
            return 'upcoming';
        }
        if ($now > $closes) {
            return 'closed';
        }
        return 'open';
    }
}

if (!function_exists('honorFetchProgramById')) {
    function honorFetchProgramById(PDO $db, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM honor_programs WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('honorFetchCategoryById')) {
    function honorFetchCategoryById(PDO $db, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT * FROM honor_categories WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('honorFetchProgramCategories')) {
    /**
     * @return list<array<string,mixed>>
     */
    function honorFetchProgramCategories(PDO $db, int $programId): array
    {
        if ($programId < 1) {
            return [];
        }
        try {
            $stmt = $db->prepare('SELECT c.* FROM honor_categories c
                INNER JOIN honor_program_categories pc ON pc.category_id = c.id
                WHERE pc.program_id = ? AND c.is_active = 1
                ORDER BY c.display_order ASC, c.id ASC');
            $stmt->execute([$programId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('honorProgramAllowsCategory')) {
    function honorProgramAllowsCategory(PDO $db, int $programId, int $categoryId): bool
    {
        if ($programId < 1 || $categoryId < 1) {
            return false;
        }
        try {
            $stmt = $db->prepare('SELECT 1 FROM honor_program_categories WHERE program_id = ? AND category_id = ? LIMIT 1');
            $stmt->execute([$programId, $categoryId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('honorProgramLabel')) {
    function honorProgramLabel(array $program, bool $english = false): string
    {
        if ($english) {
            $t = trim((string)($program['title_en'] ?? ''));
            if ($t !== '') {
                return $t;
            }
        }
        $np = trim((string)($program['title_np'] ?? ''));
        if ($np !== '') {
            return $np;
        }
        return trim((string)($program['title_en'] ?? '')) ?: ('Program #' . (int)($program['id'] ?? 0));
    }
}

if (!function_exists('honorCategoryLabel')) {
    function honorCategoryLabel(array $category, bool $english = false): string
    {
        if ($english) {
            $t = trim((string)($category['name_en'] ?? ''));
            if ($t !== '') {
                return $t;
            }
        }
        $np = trim((string)($category['name_np'] ?? ''));
        if ($np !== '') {
            return $np;
        }
        return trim((string)($category['name_en'] ?? '')) ?: ((string)($category['slug'] ?? 'category'));
    }
}

if (!function_exists('honorStatusLabel')) {
    function honorStatusLabel(string $status, bool $english = false): string
    {
        $map = [
            'pending' => ['विचाराधीन', 'Pending'],
            'under_review' => ['समीक्षामा', 'Under Review'],
            'shortlisted' => ['छनोट सूची', 'Shortlisted'],
            'selected' => ['चयनित', 'Selected'],
            'rejected' => ['अस्वीकृत', 'Rejected'],
            'closed' => ['बन्द', 'Closed'],
        ];
        $row = $map[$status] ?? [$status, $status];
        return $english ? $row[1] : $row[0];
    }
}

if (!function_exists('honorShowNewBadge')) {
    function honorShowNewBadge(?PDO $db = null): bool
    {
        if (!$db instanceof PDO && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Throwable $e) {
                return false;
            }
        }
        if (!$db instanceof PDO) {
            return false;
        }
        foreach (honorFetchOpenPrograms($db) as $p) {
            if (!empty($p['show_new_badge'])) {
                return true;
            }
        }
        foreach (honorFetchUpcomingPrograms($db) as $p) {
            if (!empty($p['show_new_badge'])) {
                return true;
            }
        }
        return false;
    }
}
