<?php
/**
 * Information Room — board decisions, policies, bylaws (view-only vault)
 */
if (!function_exists('ensureInformationRoomTables')) {
    function ensureInformationRoomTables(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (!$db instanceof PDO) {
            try {
                $db = function_exists('getDB') ? getDB() : null;
            } catch (Throwable $e) {
                return;
            }
        }
        if (!$db instanceof PDO) {
            return;
        }

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS information_room_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                title_np VARCHAR(255) NULL,
                description TEXT NULL,
                description_np TEXT NULL,
                category VARCHAR(50) NOT NULL DEFAULT 'other',
                file_path VARCHAR(255) NOT NULL,
                file_type VARCHAR(50) NULL,
                meeting_date DATE NULL,
                reference_no VARCHAR(100) NULL,
                allow_download TINYINT(1) NOT NULL DEFAULT 0,
                restrict_copy TINYINT(1) NOT NULL DEFAULT 1,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ir_active (is_active, category, display_order),
                INDEX idx_ir_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS information_room_access_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                item_id INT NOT NULL,
                viewer_type ENUM('admin','member') NOT NULL,
                viewer_id INT NOT NULL,
                viewer_name VARCHAR(200) NULL,
                action VARCHAR(30) NOT NULL DEFAULT 'view',
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ir_log_item (item_id, created_at),
                INDEX idx_ir_log_viewer (viewer_type, viewer_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $done = true;
        } catch (Throwable $e) {
            error_log('[ensureInformationRoomTables] ' . $e->getMessage());
        }
    }
}

if (!function_exists('ensureInformationRoomMemberColumn')) {
    function ensureInformationRoomMemberColumn(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (!$db instanceof PDO) {
            try {
                $db = function_exists('getDB') ? getDB() : null;
            } catch (Throwable $e) {
                return;
            }
        }
        if (!$db instanceof PDO) {
            return;
        }

        $flagKey = 'migration_information_room_v1';
        try {
            $st = $db->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
            $st->execute([$flagKey]);
            if ((string) $st->fetchColumn() === '1') {
                return;
            }
        } catch (Throwable $e) { /* continue */ }

        try {
            $db->query('SELECT 1 FROM members LIMIT 1');
        } catch (Throwable $e) {
            $done = false;
            return;
        }

        if (function_exists('safeAddColumn')) {
            safeAddColumn($db, 'members', 'information_room_enabled', 'TINYINT(1) NOT NULL DEFAULT 0');
        } else {
            try {
                $db->exec('ALTER TABLE members ADD COLUMN information_room_enabled TINYINT(1) NOT NULL DEFAULT 0');
            } catch (Throwable $e) { /* exists */ }
        }

        try {
            if (function_exists('updateSetting')) {
                updateSetting($flagKey, '1');
            } else {
                $db->exec(
                    "INSERT INTO site_settings (setting_key, setting_value)
                     VALUES ('{$flagKey}', '1')
                     ON DUPLICATE KEY UPDATE setting_value = '1'"
                );
            }
        } catch (Throwable $e) {
            error_log('[ensureInformationRoomMemberColumn flag] ' . $e->getMessage());
        }
    }
}

if (!function_exists('irCategories')) {
    /** @return array<string, array{np:string,en:string,icon:string}> */
    function irCategories(): array
    {
        return [
            'board_decision'  => ['np' => 'बोर्ड निर्णय', 'en' => 'Board Decision', 'icon' => 'fa-gavel'],
            'policy'          => ['np' => 'नीति', 'en' => 'Policy', 'icon' => 'fa-shield-halved'],
            'karyabidhi'      => ['np' => 'कार्यविधि', 'en' => 'Procedure', 'icon' => 'fa-list-check'],
            'biniyam'         => ['np' => 'बिनियम', 'en' => 'Bylaws', 'icon' => 'fa-book'],
            'meeting_minutes' => ['np' => 'बैठक माइन्युट', 'en' => 'Meeting Minutes', 'icon' => 'fa-clipboard-list'],
            'circular'        => ['np' => 'परिपत्र', 'en' => 'Circular', 'icon' => 'fa-bullhorn'],
            'other'           => ['np' => 'अन्य', 'en' => 'Other', 'icon' => 'fa-folder'],
        ];
    }
}

if (!function_exists('irCategoryLabel')) {
    function irCategoryLabel(string $key, bool $english = false): string
    {
        $map = irCategories();
        if (!isset($map[$key])) {
            return $key !== '' ? $key : '—';
        }
        return $english ? $map[$key]['en'] : $map[$key]['np'];
    }
}

if (!function_exists('irMemberHasAccess')) {
    function irMemberHasAccess(?array $member): bool
    {
        return is_array($member) && !empty($member['id']) && !empty($member['information_room_enabled']);
    }
}

if (!function_exists('irFetchItem')) {
    /** @return array<string,mixed>|null */
    function irFetchItem(PDO $db, int $id, bool $activeOnly = true): ?array
    {
        if ($id < 1) {
            return null;
        }
        ensureInformationRoomTables($db);
        $sql = 'SELECT * FROM information_room_items WHERE id = ?';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' LIMIT 1';
        try {
            $st = $db->prepare($sql);
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('irFetchActiveItems')) {
    /** @return list<array<string,mixed>> */
    function irFetchActiveItems(PDO $db, string $category = '', int $limit = 500): array
    {
        ensureInformationRoomTables($db);
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM information_room_items WHERE is_active = 1';
        $params = [];
        if ($category !== '' && isset(irCategories()[$category])) {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }
        $sql .= ' ORDER BY display_order ASC, meeting_date DESC, id DESC LIMIT ' . $limit;
        try {
            $st = $db->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('irLogAccess')) {
    function irLogAccess(PDO $db, int $itemId, string $viewerType, int $viewerId, string $viewerName, string $action = 'view'): void
    {
        if ($itemId < 1 || !in_array($viewerType, ['admin', 'member'], true)) {
            return;
        }
        ensureInformationRoomTables($db);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        try {
            $db->prepare(
                'INSERT INTO information_room_access_log (item_id, viewer_type, viewer_id, viewer_name, action, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $itemId,
                $viewerType,
                $viewerId,
                function_exists('mb_substr') ? mb_substr($viewerName, 0, 200, 'UTF-8') : substr($viewerName, 0, 200),
                $action,
                $ip,
            ]);
        } catch (Throwable $e) {
            /* non-blocking */
        }
    }
}

if (!function_exists('irResolveFilePath')) {
    /**
     * Only allow files under assets/uploads/information_room/
     * Returns absolute path or empty string if unsafe/missing path.
     */
    function irResolveFilePath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return '';
        }
        /* Normalize traversal */
        $parts = [];
        foreach (explode('/', $relativePath) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return '';
            }
            $parts[] = $seg;
        }
        $relativePath = implode('/', $parts);
        $prefix = 'assets/uploads/information_room/';
        if (!str_starts_with($relativePath, $prefix)) {
            return '';
        }
        $baseName = basename($relativePath);
        if ($baseName === '' || $baseName === '.' || $baseName === '..'
            || $baseName === '.htaccess' || $baseName === 'index.php'
            || str_starts_with($baseName, '.')) {
            return '';
        }
        $root = defined('ROOT_PATH') ? rtrim((string) ROOT_PATH, '/\\') . '/' : dirname(__DIR__) . '/';
        $full = $root . $relativePath;
        $realUpload = realpath($root . 'assets/uploads/information_room');
        if ($realUpload === false) {
            return $full; /* dir may not exist yet; caller checks is_file */
        }
        $realFile = realpath($full);
        if ($realFile !== false) {
            if (!str_starts_with($realFile, $realUpload . DIRECTORY_SEPARATOR)
                && $realFile !== $realUpload) {
                return '';
            }
            return $realFile;
        }
        /* New path not yet on disk — still confine under upload dir */
        $fullRealParent = realpath(dirname($full));
        if ($fullRealParent === false
            || (!str_starts_with($fullRealParent, $realUpload)
                && $fullRealParent !== $realUpload)) {
            return '';
        }
        return $full;
    }
}

if (!function_exists('irDeleteStoredFile')) {
    function irDeleteStoredFile(string $relativePath): bool
    {
        $full = irResolveFilePath($relativePath);
        if ($full === '' || !is_file($full)) {
            return false;
        }
        try {
            return @unlink($full);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('irAllowedUploadExtensions')) {
    /** @return list<string> */
    function irAllowedUploadExtensions(): array
    {
        return ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    }
}

if (!function_exists('irIsPreviewableExt')) {
    function irIsPreviewableExt(string $ext): bool
    {
        $ext = strtolower($ext);
        return in_array($ext, irAllowedUploadExtensions(), true);
    }
}

if (!function_exists('irNormalizeStoredPath')) {
    /** Validate a DB/upload path string; return safe relative path or '' */
    function irNormalizeStoredPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        if ($path === '' || !str_starts_with($path, 'assets/uploads/information_room/')) {
            return '';
        }
        if (irResolveFilePath($path) === '') {
            return '';
        }
        return $path;
    }
}

if (!function_exists('irFetchAccessLogs')) {
    /** @return list<array<string,mixed>> */
    function irFetchAccessLogs(PDO $db, int $limit = 200, int $itemId = 0): array
    {
        ensureInformationRoomTables($db);
        $limit = max(1, min(500, $limit));
        try {
            if ($itemId > 0) {
                $st = $db->prepare(
                    'SELECT l.*, i.title, i.title_np
                     FROM information_room_access_log l
                     LEFT JOIN information_room_items i ON i.id = l.item_id
                     WHERE l.item_id = ?
                     ORDER BY l.id DESC LIMIT ' . $limit
                );
                $st->execute([$itemId]);
            } else {
                $st = $db->query(
                    'SELECT l.*, i.title, i.title_np
                     FROM information_room_access_log l
                     LEFT JOIN information_room_items i ON i.id = l.item_id
                     ORDER BY l.id DESC LIMIT ' . $limit
                );
            }
            return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('irCanAccessItem')) {
    function irCanAccessItem(array $item, bool $isAdmin, ?array $member): bool
    {
        if (empty($item['is_active']) && !$isAdmin) {
            return false;
        }
        if ($isAdmin) {
            return true;
        }
        return irMemberHasAccess($member);
    }
}
