<?php
/**
 * member_success_stories — About page success stories (admin CRUD).
 * Multiple members: life/livelihood improvement after joining + how the coop helped.
 */
if (!function_exists('ensureMemberSuccessStoriesTable')) {
    function ensureMemberSuccessStoriesTable(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS member_success_stories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_name VARCHAR(200) NOT NULL,
                member_name_en VARCHAR(200) NULL,
                member_id_no VARCHAR(50) NULL,
                photo VARCHAR(500) NULL,
                member_since VARCHAR(40) NULL,
                location VARCHAR(150) NULL,
                location_en VARCHAR(150) NULL,
                profession VARCHAR(150) NULL,
                profession_en VARCHAR(150) NULL,
                headline_np VARCHAR(255) NOT NULL,
                headline_en VARCHAR(255) NULL,
                story_np TEXT NOT NULL,
                story_en TEXT NULL,
                institution_help_np TEXT NULL,
                institution_help_en TEXT NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_mss_active_order (is_active, display_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $done = true;
        } catch (Throwable $e) {
            error_log('[member-success-stories] ensure: ' . $e->getMessage());
        }
    }
}

if (!function_exists('fetchActiveMemberSuccessStories')) {
    /**
     * @return list<array<string,mixed>>
     */
    function fetchActiveMemberSuccessStories(?PDO $db = null, int $limit = 48): array
    {
        if (!$db && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Throwable $e) {
                return [];
            }
        }
        if (!$db instanceof PDO) {
            return [];
        }
        if (function_exists('ensureMemberSuccessStoriesTable')) {
            ensureMemberSuccessStoriesTable($db);
        }
        $limit = max(1, min(100, $limit));
        try {
            $st = $db->query(
                "SELECT * FROM member_success_stories
                 WHERE is_active = 1
                 ORDER BY display_order ASC, id DESC
                 LIMIT {$limit}"
            );
            return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
