<?php
/**
 * why_choose_features — homepage «किन हामीलाई छान्ने?» कार्डहरू
 */
if (!function_exists('ensureWhyChooseFeaturesTable')) {
    function ensureWhyChooseFeaturesTable(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS why_choose_features (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            icon        VARCHAR(100)  NOT NULL DEFAULT 'fas fa-star',
            title_np    VARCHAR(200)  NOT NULL DEFAULT '',
            title_en    VARCHAR(200)  NOT NULL DEFAULT '',
            desc_np     TEXT,
            desc_en     TEXT,
            sort_order  INT           NOT NULL DEFAULT 0,
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $done = true;
        } catch (Throwable $e) {
        }
    }
}
