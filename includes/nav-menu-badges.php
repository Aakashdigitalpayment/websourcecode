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
    ];
    if (!$db instanceof PDO) {
        return $badges;
    }
    try {
        $badges['career_open'] = (int) $db->query(
            'SELECT COUNT(*) FROM careers WHERE is_active = 1 AND deadline >= CURDATE()'
        )->fetchColumn();
    } catch (Throwable $e) {
        $badges['career_open'] = 0;
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
