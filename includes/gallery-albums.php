<?php
/**
 * Gallery albums — schema, migration, shared queries (public + admin).
 */
declare(strict_types=1);

if (!function_exists('galleryAlbumNormalizeKey')) {
    function galleryAlbumNormalizeKey(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        return preg_replace('/\s+/u', ' ', $lower) ?? $lower;
    }
}

if (!function_exists('galleryAlbumCategoryLabels')) {
    function galleryAlbumCategoryLabels(bool $english = false): array
    {
        return [
            'general'  => $english ? 'General' : 'सामान्य',
            'events'   => $english ? 'Events' : 'कार्यक्रम',
            'office'   => $english ? 'Office' : 'कार्यालय',
            'meetings' => $english ? 'Meetings' : 'बैठक',
        ];
    }
}

if (!function_exists('galleryAlbumLabel')) {
    function galleryAlbumLabel(array $album, bool $english = false): string
    {
        if ($english) {
            $en = trim((string)($album['name_en'] ?? ''));
            if ($en !== '') {
                return $en;
            }
        }
        $np = trim((string)($album['name_np'] ?? ''));
        if ($np !== '') {
            return $np;
        }
        $en = trim((string)($album['name_en'] ?? ''));
        return $en !== '' ? $en : ($english ? 'Album' : 'एल्बम');
    }
}

if (!function_exists('ensureGalleryAlbumsSchema')) {
    function ensureGalleryAlbumsSchema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS gallery_albums (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_np VARCHAR(200) NOT NULL,
                name_en VARCHAR(200) DEFAULT '',
                category VARCHAR(50) NOT NULL DEFAULT 'general',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                display_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_gal_album_active (is_active),
                INDEX idx_gal_album_order (display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            error_log('[gallery-albums] create table: ' . $e->getMessage());
        }

        foreach ([
            'ALTER TABLE gallery ADD COLUMN album_id INT NULL',
            'ALTER TABLE gallery ADD COLUMN album VARCHAR(200) NULL',
            'ALTER TABLE gallery ADD COLUMN media_type VARCHAR(20) DEFAULT \'photo\'',
        ] as $sql) {
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                /* column may exist */
            }
        }

        try {
            $db->exec('ALTER TABLE gallery ADD INDEX idx_gallery_album_id (album_id)');
        } catch (Throwable $e) {
            /* index may exist */
        }

        galleryMigrateLegacyAlbums($db);
    }
}

if (!function_exists('galleryLegacyAlbumKeyExpr')) {
    function galleryLegacyAlbumKeyExpr(bool $hasAlbumStringCol): string
    {
        if ($hasAlbumStringCol) {
            return "COALESCE(NULLIF(TRIM(album), ''), NULLIF(TRIM(title_np), ''), NULLIF(TRIM(title), ''), 'General')";
        }
        return "COALESCE(NULLIF(TRIM(title_np), ''), NULLIF(TRIM(title), ''), 'General')";
    }
}

if (!function_exists('galleryMigrateLegacyAlbums')) {
    function galleryMigrateLegacyAlbums(PDO $db): void
    {
        static $migrated = false;
        if ($migrated) {
            return;
        }
        $migrated = true;

        $hasAlbumId = function_exists('dbColumnExists') && dbColumnExists('gallery', 'album_id');
        if (!$hasAlbumId) {
            try {
                $chk = $db->query("SHOW COLUMNS FROM gallery LIKE 'album_id'");
                $hasAlbumId = $chk && $chk->fetch() !== false;
            } catch (Throwable $e) {
                return;
            }
        }
        if (!$hasAlbumId) {
            return;
        }

        $hasAlbumStr = function_exists('dbColumnExists') && dbColumnExists('gallery', 'album');
        if (!$hasAlbumStr) {
            try {
                $chk = $db->query("SHOW COLUMNS FROM gallery LIKE 'album'");
                $hasAlbumStr = $chk && $chk->fetch() !== false;
            } catch (Throwable $e) {
                $hasAlbumStr = false;
            }
        }

        $hasMediaType = function_exists('dbColumnExists') && dbColumnExists('gallery', 'media_type');
        $photoWhere = $hasMediaType
            ? "(media_type = 'photo' OR media_type IS NULL OR media_type = '')"
            : '1=1';

        $keyExpr = galleryLegacyAlbumKeyExpr($hasAlbumStr);

        try {
            $groups = $db->query(
                "SELECT {$keyExpr} AS album_key,
                        MAX(category) AS cat,
                        MAX(id) AS max_id,
                        COUNT(*) AS cnt
                 FROM gallery
                 WHERE {$photoWhere}
                 GROUP BY {$keyExpr}
                 HAVING album_key IS NOT NULL AND TRIM(album_key) <> ''
                 ORDER BY max_id DESC
                 LIMIT 200"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[gallery-albums] migration group: ' . $e->getMessage());
            return;
        }

        $existing = [];
        try {
            $rows = $db->query('SELECT id, name_np, name_en FROM gallery_albums')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                foreach (['name_np', 'name_en'] as $col) {
                    $k = galleryAlbumNormalizeKey((string)($row[$col] ?? ''));
                    if ($k !== '') {
                        $existing[$k] = (int)$row['id'];
                    }
                }
            }
        } catch (Throwable $e) {
            return;
        }

        $insertAlbum = $db->prepare(
            'INSERT INTO gallery_albums (name_np, name_en, category, is_active, display_order)
             VALUES (?, ?, ?, 1, ?)'
        );
        $updatePhotoAlbum = $db->prepare(
            'UPDATE gallery SET album_id = ? WHERE album_id IS NULL AND (' . $keyExpr . ') = ? AND ' . $photoWhere
        );

        $order = 1000;
        foreach ($groups as $g) {
            $name = trim((string)($g['album_key'] ?? ''));
            if ($name === '') {
                continue;
            }
            $norm = galleryAlbumNormalizeKey($name);
            $cat = trim((string)($g['cat'] ?? 'general'));
            if ($cat === '') {
                $cat = 'general';
            }

            if (isset($existing[$norm])) {
                $albumId = $existing[$norm];
            } else {
                try {
                    $insertAlbum->execute([$name, $name, $cat, $order]);
                    $albumId = (int)$db->lastInsertId();
                    $existing[$norm] = $albumId;
                    $order--;
                } catch (Throwable $e) {
                    error_log('[gallery-albums] insert album: ' . $e->getMessage());
                    continue;
                }
            }

            try {
                $updatePhotoAlbum->execute([$albumId, $name]);
            } catch (Throwable $e) {
                error_log('[gallery-albums] assign album_id: ' . $e->getMessage());
            }
        }
    }
}

if (!function_exists('galleryHasMediaTypeColumn')) {
    function galleryHasMediaTypeColumn(PDO $db): bool
    {
        if (function_exists('dbColumnExists') && dbColumnExists('gallery', 'media_type')) {
            return true;
        }
        try {
            $chk = $db->query("SHOW COLUMNS FROM gallery LIKE 'media_type'");
            return $chk && $chk->fetch() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('galleryPhotoWhereSql')) {
    function galleryPhotoWhereSql(bool $hasMediaType): string
    {
        return $hasMediaType
            ? 'g.is_active = 1 AND (g.media_type = \'photo\' OR g.media_type IS NULL OR g.media_type = \'\')'
            : 'g.is_active = 1';
    }
}

if (!function_exists('galleryFetchAdminAlbums')) {
    function galleryFetchAdminAlbums(PDO $db): array
    {
        ensureGalleryAlbumsSchema($db);
        try {
            return $db->query(
                'SELECT a.*,
                        (SELECT COUNT(*) FROM gallery g
                         WHERE g.album_id = a.id
                           AND (g.media_type = \'photo\' OR g.media_type IS NULL OR g.media_type = \'\')) AS photo_count
                 FROM gallery_albums a
                 ORDER BY a.display_order DESC, a.id DESC
                 LIMIT 500'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('galleryFetchPublicAlbums')) {
    function galleryFetchPublicAlbums(PDO $db, bool $hasMediaType): array
    {
        ensureGalleryAlbumsSchema($db);
        $photoWhere = galleryPhotoWhereSql($hasMediaType);
        try {
            $rows = $db->query(
                "SELECT a.*,
                        COUNT(g.id) AS photo_count,
                        MAX(g.id) AS max_photo_id,
                        SUBSTRING_INDEX(GROUP_CONCAT(g.image ORDER BY g.id DESC SEPARATOR '||'), '||', 1) AS cover_image
                 FROM gallery_albums a
                 INNER JOIN gallery g ON g.album_id = a.id AND {$photoWhere}
                 WHERE a.is_active = 1
                 GROUP BY a.id
                 ORDER BY a.display_order DESC, max_photo_id DESC
                 LIMIT 100"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['count'] = (int)($row['photo_count'] ?? 0);
            $row['cover'] = (string)($row['cover_image'] ?? '');
            $row['id'] = (int)($row['id'] ?? 0);
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('galleryResolveAlbumParam')) {
    function galleryResolveAlbumParam(PDO $db, ?string $param): ?array
    {
        ensureGalleryAlbumsSchema($db);
        if ($param === null || $param === '' || $param === 'all') {
            return null;
        }

        if (ctype_digit($param)) {
            $id = (int)$param;
            if ($id <= 0) {
                return null;
            }
            $stmt = $db->prepare('SELECT * FROM gallery_albums WHERE id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        /* Legacy name-based URL compatibility */
        $norm = galleryAlbumNormalizeKey($param);
        if ($norm === '') {
            return null;
        }
        $rows = $db->query('SELECT * FROM gallery_albums WHERE is_active = 1 LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            foreach (['name_np', 'name_en'] as $col) {
                if (galleryAlbumNormalizeKey((string)($row[$col] ?? '')) === $norm) {
                    return $row;
                }
            }
        }
        return null;
    }
}

if (!function_exists('galleryFetchAlbumPhotos')) {
    function galleryFetchAlbumPhotos(PDO $db, int $albumId, int $page, int $limit, bool $hasMediaType): array
    {
        $photoWhere = $hasMediaType
            ? 'is_active = 1 AND album_id = ? AND (media_type = \'photo\' OR media_type IS NULL OR media_type = \'\')'
            : 'is_active = 1 AND album_id = ?';

        $cntStmt = $db->prepare("SELECT COUNT(*) FROM gallery WHERE {$photoWhere}");
        $cntStmt->execute([$albumId]);
        $total = (int)$cntStmt->fetchColumn();
        $pages = max(1, (int)ceil($total / $limit));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $limit;

        $stmt = $db->prepare(
            "SELECT * FROM gallery WHERE {$photoWhere} ORDER BY id DESC LIMIT "
            . (int)$limit . ' OFFSET ' . (int)$offset
        );
        $stmt->execute([$albumId]);

        return [
            'photos' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total'  => $total,
            'pages'  => $pages,
            'page'   => $page,
        ];
    }
}

if (!function_exists('galleryAlbumPhotoCount')) {
    function galleryAlbumPhotoCount(PDO $db, int $albumId): int
    {
        try {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM gallery
                 WHERE album_id = ?
                   AND (media_type = 'photo' OR media_type IS NULL OR media_type = '')"
            );
            $stmt->execute([$albumId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('galleryFetchAlbumById')) {
    function galleryFetchAlbumById(PDO $db, int $albumId): ?array
    {
        ensureGalleryAlbumsSchema($db);
        $stmt = $db->prepare('SELECT * FROM gallery_albums WHERE id = ? LIMIT 1');
        $stmt->execute([$albumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
