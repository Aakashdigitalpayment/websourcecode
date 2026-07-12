<?php
/**
 * मानवीय श्रोत मेनुका parent श्रेणीहरू (व्यवस्थापन, समिति/उपसमिति, …)
 * Leaf items होइनन् — flyout को दोस्रो तह (submenu categories).
 */
if (!function_exists('ensureTeamMenuCategoriesTable')) {
    function ensureTeamMenuCategoriesTable(?PDO $db = null): void
    {
        $db = $db ?: getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS team_menu_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL,
            name_np VARCHAR(120) NOT NULL DEFAULT '',
            name_en VARCHAR(120) NOT NULL DEFAULT '',
            icon VARCHAR(80) NOT NULL DEFAULT 'fas fa-folder',
            source_type VARCHAR(20) NOT NULL DEFAULT 'staff',
            include_contact_officers TINYINT(1) NOT NULL DEFAULT 0,
            include_board TINYINT(1) NOT NULL DEFAULT 0,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            show_in_nav TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_team_menu_categories_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $count = (int)$db->query('SELECT COUNT(*) FROM team_menu_categories')->fetchColumn();
        if ($count === 0) {
            $ins = $db->prepare(
                'INSERT INTO team_menu_categories
                 (slug, name_np, name_en, icon, source_type, include_contact_officers, include_board, display_order, is_active, show_in_nav)
                 VALUES (?,?,?,?,?,?,?,?,1,1)'
            );
            $ins->execute(['management', 'व्यवस्थापन', 'Management', 'fas fa-briefcase', 'staff', 1, 0, 10]);
            $ins->execute(['committees', 'समिति / उपसमिति', 'Committees / Subcommittees', 'fas fa-sitemap', 'committees', 0, 1, 20]);
        }

        /* staff groups → menu category link */
        try {
            $cols = $db->query('SHOW COLUMNS FROM team_staff_groups')->fetchAll(PDO::FETCH_ASSOC);
            $fields = array_column($cols ?: [], 'Field');
            if (!in_array('menu_category_id', $fields, true)) {
                $db->exec('ALTER TABLE team_staff_groups ADD COLUMN menu_category_id INT NULL DEFAULT NULL');
            }
            $mgmtId = (int)$db->query("SELECT id FROM team_menu_categories WHERE source_type='staff' ORDER BY display_order, id LIMIT 1")->fetchColumn();
            if ($mgmtId > 0) {
                $db->exec('UPDATE team_staff_groups SET menu_category_id = ' . $mgmtId . ' WHERE menu_category_id IS NULL');
            }
        } catch (Throwable $e) { /* best-effort */ }

        /* committee_types → optional menu category */
        try {
            $cols = $db->query('SHOW COLUMNS FROM committee_types')->fetchAll(PDO::FETCH_ASSOC);
            $fields = array_column($cols ?: [], 'Field');
            if (!in_array('menu_category_id', $fields, true)) {
                $db->exec('ALTER TABLE committee_types ADD COLUMN menu_category_id INT NULL DEFAULT NULL');
            }
            $cmtCatId = (int)$db->query("SELECT id FROM team_menu_categories WHERE source_type='committees' ORDER BY display_order, id LIMIT 1")->fetchColumn();
            if ($cmtCatId > 0) {
                $db->exec('UPDATE committee_types SET menu_category_id = ' . $cmtCatId . ' WHERE menu_category_id IS NULL');
            }
        } catch (Throwable $e) { /* best-effort */ }
    }
}

if (!function_exists('teamMenuCategorySlugify')) {
    function teamMenuCategorySlugify(string $source, string $fallback = 'menu'): string
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
        return $slug;
    }
}

if (!function_exists('fetchTeamMenuCategories')) {
    /**
     * @return list<array<string,mixed>>
     */
    function fetchTeamMenuCategories(?PDO $db = null, bool $navOnly = false): array
    {
        $db = $db ?: getDB();
        ensureTeamMenuCategoriesTable($db);
        $sql = 'SELECT * FROM team_menu_categories WHERE is_active = 1';
        if ($navOnly) {
            $sql .= ' AND show_in_nav = 1';
        }
        $sql .= ' ORDER BY display_order, id';
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('fetchAllTeamMenuCategories')) {
    /** @return list<array<string,mixed>> */
    function fetchAllTeamMenuCategories(?PDO $db = null): array
    {
        $db = $db ?: getDB();
        ensureTeamMenuCategoriesTable($db);
        return $db->query('SELECT * FROM team_menu_categories ORDER BY display_order, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('isBoardCommitteeTypeAlias')) {
    /**
     * सञ्चालक समिति is the fixed team_members.category = 'board'.
     * A committee_types row with the same name must not also appear as cmt_* in the form.
     */
    function isBoardCommitteeTypeAlias(array $ct): bool
    {
        $en = strtolower(trim((string)($ct['name'] ?? '')));
        $np = trim((string)($ct['name_np'] ?? ''));
        $npNorm = preg_replace('/\s+/u', ' ', $np) ?? $np;
        if ($npNorm === 'सञ्चालक समिति' || $npNorm === 'संचालक समिति') {
            return true;
        }
        if ($npNorm !== '' && (str_contains($npNorm, 'सञ्चालक समिति') || str_contains($npNorm, 'संचालक समिति'))) {
            return true;
        }
        if ($en !== '' && (
            $en === 'board'
            || $en === 'board of directors'
            || $en === 'board committee'
            || str_contains($en, 'board of director')
        )) {
            return true;
        }
        return false;
    }
}
