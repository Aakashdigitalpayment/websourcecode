<?php
declare(strict_types=1);

/**
 * Public navbar — उप-मेनु / टप लिंकको संख्या चिन्ह (टप बारको बिज्ञापन ब्याज जस्तै)।
 *
 * नयाँ किसिम: $badges मा कुञ्जी थप्नुहोस्, SQL सुरक्षित try/catch भित्र, अनि header.php मा
 * `echo nav_submenu_count_badge_html($navMenuBadges['your_key']);` राख्नुहोस्।
 */
function nav_get_public_submenu_badges(?PDO $db): array
{
    $badges = [
        'career_open' => 0,
        'marketplace_products' => 0,
        'marketplace_skills' => 0,
    ];
    if (!$db instanceof PDO) {
        return $badges;
    }
    if (!function_exists('getCachedData')) {
        $cacheFile = __DIR__ . '/simple-cache.php';
        if (is_file($cacheFile)) {
            require_once $cacheFile;
        }
    }
    if (function_exists('getCachedData')) {
        $cached = getCachedData('nav_career_badge_v3', 90, static function () use ($db) {
            $out = [
                'career_open' => 0,
                'marketplace_products' => 0,
                'marketplace_skills' => 0,
            ];
            try {
                $out['career_open'] = (int) $db->query(
                    'SELECT COUNT(*) FROM careers WHERE is_active = 1 AND deadline >= CURDATE()'
                )->fetchColumn();
            } catch (Throwable $e) {
                $out['career_open'] = 0;
            }
            try {
                $sql = "SELECT listing_type, COUNT(*) AS c FROM member_marketplace_listings
                        WHERE status = 'approved' AND is_active = 1
                          AND (available_from IS NULL OR available_from <= CURDATE())
                          AND (available_until IS NULL OR available_until >= NOW())
                        GROUP BY listing_type";
                foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                    $t = (string) ($r['listing_type'] ?? '');
                    if ($t === 'product') {
                        $out['marketplace_products'] = (int) ($r['c'] ?? 0);
                    } elseif ($t === 'skill') {
                        $out['marketplace_skills'] = (int) ($r['c'] ?? 0);
                    }
                }
            } catch (Throwable $e) {
                /* table may not exist yet */
            }
            return $out;
        });
        if (is_array($cached)) {
            $badges['career_open'] = (int) ($cached['career_open'] ?? 0);
            $badges['marketplace_products'] = (int) ($cached['marketplace_products'] ?? 0);
            $badges['marketplace_skills'] = (int) ($cached['marketplace_skills'] ?? 0);
        }
        return $badges;
    }
    try {
        $badges['career_open'] = (int) $db->query(
            'SELECT COUNT(*) FROM careers WHERE is_active = 1 AND deadline >= CURDATE()'
        )->fetchColumn();
    } catch (Throwable $e) {
        $badges['career_open'] = 0;
    }
    try {
        require_once __DIR__ . '/member-marketplace-tables.php';
        $sql = 'SELECT listing_type, COUNT(*) AS c FROM member_marketplace_listings WHERE listing_type IN (\'product\',\'skill\') AND '
            . mpPublicWhereSql() . ' GROUP BY listing_type';
        foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $t = (string) ($r['listing_type'] ?? '');
            if ($t === 'product') {
                $badges['marketplace_products'] = (int) ($r['c'] ?? 0);
            } elseif ($t === 'skill') {
                $badges['marketplace_skills'] = (int) ($r['c'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        /* table may not exist yet */
    }
    return $badges;
}

/** टप बार र मेनु उप-लिंक दुवै — खाली भए केही output गर्दैन */
function nav_submenu_count_badge_html(int $count): string
{
    if ($count < 1) {
        return '';
    }
    return '<span class="pfl-badge pfl-badge--submenu">' . $count . '</span>';
}
