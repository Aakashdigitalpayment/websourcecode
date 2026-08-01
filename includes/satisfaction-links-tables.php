<?php
/**
 * satisfaction_links — satisfaction widget (admin + ensure-admin)
 */
if (!function_exists('ensureSatisfactionLinksTables')) {
    function ensureSatisfactionLinksTables(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS satisfaction_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            title_en VARCHAR(200),
            url VARCHAR(500) NOT NULL,
            icon VARCHAR(100) DEFAULT 'fas fa-smile',
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $done = true;
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('satisfactionWidgetEnabled')) {
    function satisfactionWidgetEnabled(): bool
    {
        return function_exists('getSetting') && getSetting('satisfaction_widget_enabled', '0') === '1';
    }
}

if (!function_exists('satisfactionFetchActiveLinks')) {
    /**
     * Active feedback / satisfaction links for public header + mobile widget.
     * @return list<array<string,mixed>>
     */
    function satisfactionFetchActiveLinks(?PDO $db = null, int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));
        try {
            $db = $db ?: (function_exists('getDB') ? getDB() : null);
            if (!$db instanceof PDO) {
                return [];
            }
            ensureSatisfactionLinksTables($db);
            if (!satisfactionWidgetEnabled()) {
                return [];
            }
            $tableOk = function_exists('dbTableExists')
                ? dbTableExists('satisfaction_links')
                : true;
            if (!$tableOk) {
                return [];
            }
            $st = $db->prepare(
                "SELECT id, title, title_en, url, icon, created_at, updated_at
                 FROM satisfaction_links
                 WHERE is_active = 1
                 ORDER BY display_order ASC, id ASC
                 LIMIT {$limit}"
            );
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('satisfactionHasNewBadge')) {
    /** Show नयाँ if any active link was created/updated in the last N days. */
    function satisfactionHasNewBadge(array $links, int $withinDays = 21): bool
    {
        if ($links === []) {
            return false;
        }
        $cut = time() - (max(1, $withinDays) * 86400);
        foreach ($links as $row) {
            foreach (['updated_at', 'created_at'] as $k) {
                $ts = strtotime((string)($row[$k] ?? ''));
                if ($ts && $ts >= $cut) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('satisfactionLinkTitle')) {
    function satisfactionLinkTitle(array $row): string
    {
        $en = trim((string)($row['title_en'] ?? ''));
        $np = trim((string)($row['title'] ?? ''));
        if (function_exists('isEnglish') && isEnglish() && $en !== '') {
            return $en;
        }
        return $np !== '' ? $np : $en;
    }
}
