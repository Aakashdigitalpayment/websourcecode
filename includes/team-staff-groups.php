<?php
/**
 * कर्मचारी / व्यवस्थापन वर्ग (team staff groups)
 * Dynamic master for team_members.category slugs used on karmachari pages.
 */
if (!function_exists('ensureTeamStaffGroupsTable')) {
    function ensureTeamStaffGroupsTable(?PDO $db = null): void
    {
        $db = $db ?: getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS team_staff_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL,
            name_np VARCHAR(120) NOT NULL DEFAULT '',
            name_en VARCHAR(120) NOT NULL DEFAULT '',
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            show_in_nav TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_team_staff_groups_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $count = (int)$db->query('SELECT COUNT(*) FROM team_staff_groups')->fetchColumn();
        if ($count === 0) {
            $seed = [
                ['top_management', 'शीर्ष व्यवस्थापन टोली', 'Top Management Team', 10],
                ['management', 'व्यवस्थापन', 'Management', 20],
                ['staff', 'कर्मचारी', 'Staff', 30],
                ['admin', 'एडमिन', 'Admin', 40],
            ];
            $ins = $db->prepare('INSERT INTO team_staff_groups (slug, name_np, name_en, display_order, is_active, show_in_nav) VALUES (?,?,?,?,1,1)');
            foreach ($seed as $row) {
                $ins->execute($row);
            }
        }
    }
}

if (!function_exists('teamStaffGroupSlugify')) {
    function teamStaffGroupSlugify(string $source, string $fallback = 'group'): string
    {
        $slug = strtolower(trim($source));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = $fallback . '_' . substr(md5($source . microtime(true)), 0, 6);
        }
        if (strlen($slug) > 50) {
            $slug = substr($slug, 0, 50);
        }
        // Reserve governance / system prefixes
        if (strpos($slug, 'cmt_') === 0 || $slug === 'board' || $slug === 'leadership') {
            $slug = 'staff_' . $slug;
        }
        return $slug;
    }
}

if (!function_exists('fetchTeamStaffGroups')) {
    /**
     * @return list<array<string,mixed>>
     */
    function fetchTeamStaffGroups(?PDO $db = null, bool $activeOnly = true): array
    {
        $db = $db ?: getDB();
        ensureTeamStaffGroupsTable($db);
        $sql = 'SELECT * FROM team_staff_groups';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY display_order, id';
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('teamStaffGroupAnchor')) {
    /** Public section id / filter key from slug */
    function teamStaffGroupAnchor(string $slug): string
    {
        return str_replace('_', '-', $slug);
    }
}
