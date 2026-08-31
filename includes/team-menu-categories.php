<?php
/**
 * मानवीय श्रोत मेनुका parent श्रेणीहरू (व्यवस्थापन, समिति/उपसमिति, …)
 * Leaf items होइनन् — flyout को दोस्रो तह (submenu categories).
 */
if (!function_exists('ensureCommitteeTypesExtendedColumns')) {
    /**
     * committee_types columns needed for team admin group CRUD + public menu.
     * Must run even when schema.lock skips CREATE TABLE paths.
     */
    function ensureCommitteeTypesExtendedColumns(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = $db ?: getDB();
        try {
            $cols = $db->query('SHOW COLUMNS FROM committee_types')->fetchAll(PDO::FETCH_ASSOC);
            $fields = array_column($cols ?: [], 'Field');
            if ($fields === []) {
                return;
            }
            $alters = [
                'show_in_navbar' => "ALTER TABLE committee_types ADD COLUMN show_in_navbar TINYINT(1) DEFAULT 0",
                'icon' => "ALTER TABLE committee_types ADD COLUMN icon VARCHAR(80) DEFAULT 'fas fa-users-gear'",
                'menu_category_id' => 'ALTER TABLE committee_types ADD COLUMN menu_category_id INT NULL DEFAULT NULL',
            ];
            foreach ($alters as $col => $sql) {
                if (!in_array($col, $fields, true)) {
                    $db->exec($sql);
                    $fields[] = $col;
                }
            }
        } catch (Throwable $e) { /* best-effort */ }
    }
}

if (!function_exists('syncTeamMenuCategoryLinks')) {
    /** Link staff/committee groups to default menu categories when unset. */
    function syncTeamMenuCategoryLinks(?PDO $db = null): void
    {
        $db = $db ?: getDB();
        ensureCommitteeTypesExtendedColumns($db);

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
            $cmtCatId = (int)$db->query("SELECT id FROM team_menu_categories WHERE source_type='committees' ORDER BY display_order, id LIMIT 1")->fetchColumn();
            if ($cmtCatId > 0) {
                $db->exec('UPDATE committee_types SET menu_category_id = ' . $cmtCatId . ' WHERE menu_category_id IS NULL');
            }
        } catch (Throwable $e) { /* best-effort */ }
    }
}

if (!function_exists('ensureTeamMenuCategoriesTable')) {
    function ensureTeamMenuCategoriesTable(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = $db ?: getDB();

        $skipCreate = false;
        $lockFile = dirname(__DIR__) . '/.schema.lock';
        if (@is_file($lockFile)) {
            try {
                $db->query('SELECT 1 FROM team_menu_categories LIMIT 1');
                $skipCreate = true;
            } catch (Throwable $e) {
                /* fall through — create */
            }
        }

        if (!$skipCreate) {
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
        }

        syncTeamMenuCategoryLinks($db);
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
        $sql .= ' ORDER BY display_order, id LIMIT 100';
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('fetchAllTeamMenuCategories')) {
    /** @return list<array<string,mixed>> */
    function fetchAllTeamMenuCategories(?PDO $db = null): array
    {
        $db = $db ?: getDB();
        ensureTeamMenuCategoriesTable($db);
        return $db->query('SELECT * FROM team_menu_categories ORDER BY display_order, id LIMIT 100')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('teamFallbackCommitteesMenuId')) {
    function teamFallbackCommitteesMenuId(?PDO $db = null): int
    {
        static $id = null;
        if ($id !== null) {
            return $id;
        }
        $id = 0;
        $db = $db ?: getDB();
        try {
            ensureTeamMenuCategoriesTable($db);
            $id = (int)$db->query("SELECT id FROM team_menu_categories WHERE source_type='committees' AND is_active=1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
        } catch (Throwable $e) { /* best-effort */ }
        return $id;
    }
}

if (!function_exists('teamFallbackStaffMenuId')) {
    function teamFallbackStaffMenuId(?PDO $db = null): int
    {
        static $id = null;
        if ($id !== null) {
            return $id;
        }
        $id = 0;
        $db = $db ?: getDB();
        try {
            ensureTeamMenuCategoriesTable($db);
            $id = (int)$db->query("SELECT id FROM team_menu_categories WHERE source_type='staff' AND is_active=1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
        } catch (Throwable $e) { /* best-effort */ }
        return $id;
    }
}

if (!function_exists('teamCommitteeBelongsToMenuCategory')) {
    function teamCommitteeBelongsToMenuCategory(array $committeeType, int $menuCategoryId, int $fallbackCommitteesMenuId = 0): bool
    {
        if ($menuCategoryId <= 0) {
            return false;
        }
        $ctMenu = (int)($committeeType['menu_category_id'] ?? 0);
        return $ctMenu === $menuCategoryId
            || ($ctMenu === 0 && $fallbackCommitteesMenuId > 0 && $menuCategoryId === $fallbackCommitteesMenuId);
    }
}

if (!function_exists('teamStaffGroupBelongsToMenuCategory')) {
    function teamStaffGroupBelongsToMenuCategory(array $staffGroup, int $menuCategoryId, int $fallbackStaffMenuId = 0): bool
    {
        if ($menuCategoryId <= 0) {
            return false;
        }
        $sgMenu = (int)($staffGroup['menu_category_id'] ?? 0);
        return $sgMenu === $menuCategoryId
            || ($sgMenu === 0 && $fallbackStaffMenuId > 0 && $menuCategoryId === $fallbackStaffMenuId);
    }
}

if (!function_exists('healBoardAliasTeamMembers')) {
    /** Move members saved under board-alias cmt_* back to fixed category=board. */
    function healBoardAliasTeamMembers(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = $db ?: getDB();
        try {
            $types = $db->query('SELECT id, name, name_np FROM committee_types WHERE is_active = 1 ORDER BY display_order, id LIMIT 200')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($types as $ct) {
                if (!function_exists('isBoardCommitteeTypeAlias') || !isBoardCommitteeTypeAlias($ct)) {
                    continue;
                }
                $db->prepare("UPDATE team_members SET category='board' WHERE category=?")->execute(['cmt_' . (int)$ct['id']]);
            }
        } catch (Throwable $e) { /* best-effort */ }
    }
}

if (!function_exists('healMigratedTeamNavData')) {
    /**
     * After DB migration: link null menu categories and surface committees that already have members.
     */
    function healMigratedTeamNavData(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = $db ?: getDB();
        ensureTeamMenuCategoriesTable($db);
        ensureCommitteeTypesExtendedColumns($db);
        syncTeamMenuCategoryLinks($db);
        healBoardAliasTeamMembers($db);

        try {
            $hasBoard = (int)$db->query("SELECT COUNT(*) FROM team_members WHERE category='board' AND is_active=1")->fetchColumn() > 0;
            if ($hasBoard) {
                $boardMenuCatIds = [];
                $fallbackId = teamFallbackCommitteesMenuId($db);
                if ($fallbackId > 0) {
                    $boardMenuCatIds[$fallbackId] = true;
                }
                $aliasRows = $db->query('SELECT id, name, name_np, menu_category_id FROM committee_types WHERE is_active=1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($aliasRows as $row) {
                    if (function_exists('isBoardCommitteeTypeAlias') && isBoardCommitteeTypeAlias($row)) {
                        $mcid = (int)($row['menu_category_id'] ?? 0);
                        if ($mcid > 0) {
                            $boardMenuCatIds[$mcid] = true;
                        }
                    }
                }
                foreach (array_keys($boardMenuCatIds) as $catId) {
                    $db->prepare("UPDATE team_menu_categories SET include_board=1 WHERE id=? AND source_type='committees' AND include_board=0")
                       ->execute([(int)$catId]);
                }
            }
        } catch (Throwable $e) { /* best-effort */ }

        try {
            require_once __DIR__ . '/team-staff-groups.php';
            foreach (fetchTeamStaffGroups($db, false) as $sg) {
                $slug = (string)($sg['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $st = $db->prepare('SELECT COUNT(*) FROM team_members WHERE is_active=1 AND category=?');
                $st->execute([$slug]);
                if ((int)$st->fetchColumn() > 0) {
                    $db->prepare('UPDATE team_staff_groups SET show_in_nav=1 WHERE slug=? AND show_in_nav=0')->execute([$slug]);
                }
            }
        } catch (Throwable $e) { /* best-effort */ }

        try {
            $types = $db->query('SELECT id FROM committee_types WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($types as $ct) {
                $ctId = (int)($ct['id'] ?? 0);
                if ($ctId <= 0) {
                    continue;
                }
                $cat = 'cmt_' . $ctId;
                $st = $db->prepare('SELECT COUNT(*) FROM team_members WHERE is_active=1 AND category=?');
                $st->execute([$cat]);
                $memberCount = (int)$st->fetchColumn();
                $tenureCount = 0;
                try {
                    $st2 = $db->prepare('SELECT COUNT(*) FROM committee_tenures WHERE committee_type_id=? AND is_active=1');
                    $st2->execute([$ctId]);
                    $tenureCount = (int)$st2->fetchColumn();
                } catch (Throwable $e) { /* older schema */ }
                if ($memberCount > 0 || $tenureCount > 0) {
                    $db->prepare('UPDATE committee_types SET show_in_navbar=1 WHERE id=? AND show_in_navbar=0')->execute([$ctId]);
                }
            }
        } catch (Throwable $e) { /* best-effort */ }
    }
}

if (!function_exists('fetchPublicNavCommittees')) {
    /**
     * Active committee types for public nav — includes migrated rows with members even if show_in_navbar was left off.
     *
     * @return list<array<string,mixed>>
     */
    function fetchPublicNavCommittees(?PDO $db = null): array
    {
        $db = $db ?: getDB();
        healMigratedTeamNavData($db);
        try {
            $rows = $db->query(
                "SELECT id, name, name_np, menu_category_id, icon, show_in_navbar
                 FROM committee_types
                 WHERE is_active = 1
                 ORDER BY display_order, id
                 LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $visible = [];
        foreach ($rows as $row) {
            $ctId = (int)($row['id'] ?? 0);
            if ($ctId <= 0) {
                continue;
            }
            if (!empty($row['show_in_navbar'])) {
                $visible[] = $row;
                continue;
            }
            $cat = 'cmt_' . $ctId;
            try {
                $st = $db->prepare('SELECT COUNT(*) FROM team_members WHERE is_active=1 AND category=?');
                $st->execute([$cat]);
                if ((int)$st->fetchColumn() > 0) {
                    $visible[] = $row;
                    continue;
                }
            } catch (Throwable $e) { /* ignore */ }
            try {
                $st2 = $db->prepare('SELECT COUNT(*) FROM committee_tenures WHERE committee_type_id=? AND is_active=1');
                $st2->execute([$ctId]);
                if ((int)$st2->fetchColumn() > 0) {
                    $visible[] = $row;
                }
            } catch (Throwable $e) { /* ignore */ }
        }
        return $visible;
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

if (!function_exists('findBoardCommitteeTypeAlias')) {
    /** @param list<array<string,mixed>> $committeeTypes */
    function findBoardCommitteeTypeAlias(array $committeeTypes, int $id = 0): ?array
    {
        foreach ($committeeTypes as $ct) {
            if ($id > 0 && (int)($ct['id'] ?? 0) !== $id) {
                continue;
            }
            if (isBoardCommitteeTypeAlias($ct)) {
                return $ct;
            }
            if ($id > 0) {
                return null;
            }
        }
        return null;
    }
}
