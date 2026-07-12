<?php
/**
 * Single source for active service centers / branches (शाखाहरू).
 * Prefer this helper instead of ad-hoc SELECT FROM service_centers in forms.
 */
if (!function_exists('fetchActiveServiceCenters')) {
    /**
     * @return list<array<string,mixed>>
     */
    function fetchActiveServiceCenters(?PDO $db = null, int $limit = 0): array
    {
        $db = $db ?: getDB();
        try {
            $sql = 'SELECT * FROM service_centers
                    WHERE is_active = 1
                    ORDER BY is_main_branch DESC, display_order ASC, name ASC';
            if ($limit > 0) {
                $sql .= ' LIMIT ' . (int)$limit;
            }
            return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('serviceCenterDisplayName')) {
    /** Prefer Nepali name, fall back to English/name. */
    function serviceCenterDisplayName(array $row): string
    {
        $np = trim((string)($row['name_np'] ?? ''));
        if ($np !== '') {
            return $np;
        }
        $en = trim((string)($row['name'] ?? ''));
        if ($en !== '') {
            return $en;
        }
        return 'शाखा #' . (int)($row['id'] ?? 0);
    }
}
