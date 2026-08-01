<?php
/**
 * careers — admin र public दुवैले प्रयोग गर्ने canonical DDL + helpers
 */
if (!function_exists('careerNormalizeDate')) {
    /** nepali-datepicker BS → AD Y-m-d */
    function careerNormalizeDate(string $dateIn): string
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

if (!function_exists('careerDeadlinePassed')) {
    function careerDeadlinePassed(?array $job): bool
    {
        if (!$job || empty($job['deadline'])) {
            return false;
        }
        $d = substr((string)$job['deadline'], 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return false;
        }
        try {
            $tz = new DateTimeZone('Asia/Kathmandu');
            $today = (new DateTime('today', $tz))->format('Y-m-d');
            return $d < $today;
        } catch (Throwable $e) {
            return strtotime($d) < strtotime('today');
        }
    }
}

if (!function_exists('careerIsOpen')) {
    function careerIsOpen(?array $job): bool
    {
        if (!$job || empty($job['is_active'])) {
            return false;
        }
        return !careerDeadlinePassed($job);
    }
}

if (!function_exists('careerIsNew')) {
    function careerIsNew(?array $job, int $withinDays = 14): bool
    {
        if (!$job || empty($job['created_at'])) {
            return false;
        }
        $ts = strtotime((string)$job['created_at']);
        return $ts && $ts >= (time() - max(1, $withinDays) * 86400);
    }
}

if (!function_exists('careerFormatDeadlineDisplay')) {
    /** Store AD DATE → public/admin BS (or AD fallback) label */
    function careerFormatDeadlineDisplay(?string $adDate): string
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

if (!function_exists('ensureCareersTables')) {
    function ensureCareersTables(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS careers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            title_np VARCHAR(255) DEFAULT NULL,
            department VARCHAR(150) DEFAULT NULL,
            location VARCHAR(150) DEFAULT NULL,
            job_type VARCHAR(50) DEFAULT 'full_time',
            description TEXT,
            description_np TEXT,
            requirements TEXT,
            deadline DATE DEFAULT NULL,
            attachment VARCHAR(255) DEFAULT NULL,
            vacancies INT DEFAULT 1,
            min_qualification VARCHAR(255) DEFAULT NULL,
            experience_required VARCHAR(150) DEFAULT NULL,
            salary_range VARCHAR(150) DEFAULT NULL,
            allow_online_apply TINYINT(1) DEFAULT 1,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_careers_active (is_active),
            INDEX idx_careers_deadline (deadline)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            try {
                $hasActive = function_exists('safeColumnExists') && safeColumnExists('careers', 'is_active');
                $hasStatus = function_exists('safeColumnExists') && safeColumnExists('careers', 'status');
                if (!$hasActive && $hasStatus) {
                    $db->exec('ALTER TABLE careers ADD COLUMN is_active TINYINT(1) DEFAULT 1');
                    $db->exec("UPDATE careers SET is_active = 1 WHERE status = 'active'");
                    $db->exec("UPDATE careers SET is_active = 0 WHERE status IN ('closed','draft')");
                }
            } catch (Throwable $e) {
            }

            if (function_exists('safeAddColumn')) {
                safeAddColumn($db, 'careers', 'title_np', 'VARCHAR(255) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'department', 'VARCHAR(150) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'location', 'VARCHAR(150) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'job_type', "VARCHAR(50) DEFAULT 'full_time'");
                safeAddColumn($db, 'careers', 'description_np', 'TEXT');
                safeAddColumn($db, 'careers', 'requirements', 'TEXT');
                safeAddColumn($db, 'careers', 'attachment', 'VARCHAR(255) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'vacancies', 'INT DEFAULT 1');
                safeAddColumn($db, 'careers', 'min_qualification', 'VARCHAR(255) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'experience_required', 'VARCHAR(150) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'salary_range', 'VARCHAR(150) DEFAULT NULL');
                safeAddColumn($db, 'careers', 'allow_online_apply', 'TINYINT(1) DEFAULT 1');
                safeAddColumn($db, 'careers', 'is_active', 'TINYINT(1) DEFAULT 1');
                safeAddColumn($db, 'careers', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
            }

            $done = true;
        } catch (Throwable $e) {
        }
    }
}
