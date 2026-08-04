<?php
/**
 * Welfare claim types — dynamic catalog with hardcoded seed/fallback.
 * Table: welfare_claim_types
 */

if (!function_exists('welfareClaimTypesDefaults')) {
    /**
     * Hardcoded defaults — same shape as legacy $claimTypes + form profile.
     * @return array<string, array{np:string,en:string,icon:string,color:string,profile:string}>
     */
    function welfareClaimTypesDefaults(): array
    {
        return [
            'maternity' => ['np' => 'सुत्केरी सुविधा', 'en' => 'Maternity Benefit', 'icon' => 'fa-baby', 'color' => '#e91e63', 'profile' => 'maternity'],
            'death'     => ['np' => 'मृत्यु सुविधा', 'en' => 'Death Benefit', 'icon' => 'fa-heart-broken', 'color' => '#607d8b', 'profile' => 'death'],
            'insurance' => ['np' => 'बीमा दाबी', 'en' => 'Insurance Claim', 'icon' => 'fa-shield-alt', 'color' => '#2196f3', 'profile' => 'insurance'],
            'medical'   => ['np' => 'उपचार खर्च', 'en' => 'Medical Expense', 'icon' => 'fa-hospital', 'color' => '#4caf50', 'profile' => 'medical'],
            'accident'  => ['np' => 'दुर्घटना सुविधा', 'en' => 'Accident Benefit', 'icon' => 'fa-car-burst', 'color' => '#f59e0b', 'profile' => 'accident'],
            'other'     => ['np' => 'अन्य सुविधा', 'en' => 'Other Benefit', 'icon' => 'fa-gift', 'color' => '#ff9800', 'profile' => 'other'],
        ];
    }
}

if (!function_exists('welfareClaimTypeSlugify')) {
    function welfareClaimTypeSlugify(string $name, string $fallback = 'welfare'): string
    {
        $name = trim($name);
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
        $slug = trim((string)$slug, '-');
        if ($slug === '' || strlen($slug) < 2) {
            $slug = $fallback . '-' . substr(md5($name !== '' ? $name : (string)microtime(true)), 0, 8);
        }
        if (strlen($slug) > 50) {
            $slug = rtrim(substr($slug, 0, 50), '-');
        }
        return $slug;
    }
}

if (!function_exists('welfareClaimTypeProfiles')) {
    /** @return list<string> */
    function welfareClaimTypeProfiles(): array
    {
        return ['maternity', 'death', 'insurance', 'medical', 'accident', 'other'];
    }
}

if (!function_exists('ensureWelfareClaimTypes')) {
    function ensureWelfareClaimTypes(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS welfare_claim_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(60) NOT NULL,
                name_np VARCHAR(160) NOT NULL,
                name_en VARCHAR(160) DEFAULT '',
                icon VARCHAR(80) NOT NULL DEFAULT 'fa-gift',
                color VARCHAR(40) NOT NULL DEFAULT '#ff9800',
                form_profile VARCHAR(40) NOT NULL DEFAULT 'other',
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_builtin TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_wct_slug (slug),
                INDEX idx_wct_active (is_active),
                INDEX idx_wct_order (display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* Allow custom slugs on existing claims table */
            try {
                $db->exec("ALTER TABLE member_welfare_claims MODIFY COLUMN claim_type VARCHAR(60) NOT NULL DEFAULT 'other'");
            } catch (Throwable $e) {
                /* ignore if table missing / already VARCHAR */
            }

            $count = 0;
            try {
                $count = (int)$db->query('SELECT COUNT(*) FROM welfare_claim_types')->fetchColumn();
            } catch (Throwable $e) {
                return;
            }
            if ($count > 0) {
                $done = true;
                return;
            }

            $defaults = welfareClaimTypesDefaults();
            $order = 10;
            $stmt = $db->prepare(
                'INSERT INTO welfare_claim_types (slug, name_np, name_en, icon, color, form_profile, display_order, is_active, is_builtin)
                 VALUES (?,?,?,?,?,?,?,1,1)'
            );
            foreach ($defaults as $slug => $row) {
                try {
                    $stmt->execute([
                        $slug,
                        $row['np'],
                        $row['en'],
                        $row['icon'],
                        $row['color'],
                        $row['profile'],
                        $order,
                    ]);
                } catch (Throwable $e) {
                    /* ignore duplicates */
                }
                $order += 10;
            }
            $done = true;
        } catch (Throwable $e) {
            error_log('[welfare-claim-types] ' . $e->getMessage());
        }
    }
}

if (!function_exists('welfareClaimTypesMap')) {
    /**
     * @return array<string, array{np:string,en:string,icon:string,color:string,profile:string}>
     */
    function welfareClaimTypesMap(?PDO $db = null, bool $activeOnly = true): array
    {
        $defaults = welfareClaimTypesDefaults();
        if (!$db instanceof PDO && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Throwable $e) {
                return $defaults;
            }
        }
        if (!$db instanceof PDO) {
            return $defaults;
        }
        try {
            ensureWelfareClaimTypes($db);
            $sql = 'SELECT slug, name_np, name_en, icon, color, form_profile FROM welfare_claim_types';
            if ($activeOnly) {
                $sql .= ' WHERE is_active = 1';
            }
            $sql .= ' ORDER BY display_order ASC, id ASC';
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$rows) {
                return $defaults;
            }
            $validProfiles = welfareClaimTypeProfiles();
            $map = [];
            foreach ($rows as $r) {
                $slug = (string)($r['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $icon = trim((string)($r['icon'] ?? ''));
                if ($icon === '') {
                    $icon = $defaults[$slug]['icon'] ?? 'fa-gift';
                }
                if (stripos($icon, 'fas ') === 0) {
                    $icon = trim(substr($icon, 4));
                }
                $profile = (string)($r['form_profile'] ?? 'other');
                if (!in_array($profile, $validProfiles, true)) {
                    $profile = 'other';
                }
                $map[$slug] = [
                    'np' => (string)($r['name_np'] ?? ''),
                    'en' => (string)($r['name_en'] ?? ''),
                    'icon' => $icon,
                    'color' => (string)($r['color'] ?? '') ?: '#ff9800',
                    'profile' => $profile,
                ];
            }
            return $map ?: $defaults;
        } catch (Throwable $e) {
            return $defaults;
        }
    }
}

if (!function_exists('welfareClaimTypeLabel')) {
    function welfareClaimTypeLabel(string $slug, bool $english = false, ?PDO $db = null): string
    {
        $map = welfareClaimTypesMap($db, false);
        if (!isset($map[$slug])) {
            return $slug;
        }
        if ($english) {
            $t = trim((string)($map[$slug]['en'] ?? ''));
            if ($t !== '') {
                return $t;
            }
        }
        $np = trim((string)($map[$slug]['np'] ?? ''));
        if ($np !== '') {
            return $np;
        }
        return trim((string)($map[$slug]['en'] ?? '')) ?: $slug;
    }
}

if (!function_exists('welfareClaimTypeUsageCount')) {
    function welfareClaimTypeUsageCount(PDO $db, string $slug): int
    {
        try {
            $st = $db->prepare('SELECT COUNT(*) FROM member_welfare_claims WHERE claim_type = ?');
            $st->execute([$slug]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
