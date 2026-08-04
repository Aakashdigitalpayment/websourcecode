<?php
/**
 * Digital service types — dynamic catalog with hardcoded seed/fallback.
 * Table: digital_service_types
 */

if (!function_exists('digitalServiceTypesDefaults')) {
    /**
     * Hardcoded defaults — same shape as legacy $serviceTypes map.
     * @return array<string, array{np:string,en:string,icon:string,color:string,requires_document:int}>
     */
    function digitalServiceTypesDefaults(): array
    {
        $base = [
            'missed_call_banking' => ['np' => 'मिस्ड कल बैंकिङ',        'en' => 'Missed Call Banking',     'icon' => 'fa-phone-volume',   'color' => 'var(--primary-color)'],
            'statement_request'   => ['np' => 'स्टेटमेन्ट अनुरोध',       'en' => 'Statement Request',        'icon' => 'fa-file-invoice',   'color' => 'var(--primary-color)'],
            'bill_payment'        => ['np' => 'बिल भुक्तानी सहयोग',      'en' => 'Bill Payment Support',     'icon' => 'fa-receipt',        'color' => 'var(--secondary-color)'],
            'mobile_recharge'     => ['np' => 'मोबाइल रिचार्ज अनुरोध',   'en' => 'Mobile Recharge Request',  'icon' => 'fa-mobile-screen',  'color' => 'var(--primary-light)'],
            'internet_banking'    => ['np' => 'इन्टरनेट/मोबाइल बैंकिङ', 'en' => 'Internet/Mobile Banking',  'icon' => 'fa-laptop-code',    'color' => 'var(--accent-color)'],
            'sms_alert'           => ['np' => 'SMS अलर्ट सेवा',          'en' => 'SMS Alert Service',        'icon' => 'fa-bell',           'color' => 'var(--secondary-color)'],
            'card_service'        => ['np' => 'कार्ड सेवा',              'en' => 'Card Service',             'icon' => 'fa-credit-card',    'color' => 'var(--primary-color)'],
            'qr_payment'          => ['np' => 'QR/डिजिटल भुक्तानी',     'en' => 'QR / Digital Payment',     'icon' => 'fa-qrcode',         'color' => 'var(--primary-light)'],
            'share_refund'        => ['np' => 'शेयर फिर्ता (Refund)',    'en' => 'Share Refund',             'icon' => 'fa-money-bill-transfer', 'color' => 'var(--accent-color)'],
            'share_increase'      => ['np' => 'शेयर वृद्धि (Increase)',  'en' => 'Share Increase',           'icon' => 'fa-chart-line',     'color' => 'var(--primary-color)'],
            'other'               => ['np' => 'अन्य डिजिटल सेवा',        'en' => 'Other Digital Service',    'icon' => 'fa-headset',        'color' => 'var(--secondary-color)'],
        ];
        foreach ($base as $k => $row) {
            $base[$k]['requires_document'] = 0;
        }
        return $base;
    }
}

if (!function_exists('digitalServiceTypeSlugify')) {
    function digitalServiceTypeSlugify(string $name, string $fallback = 'svc'): string
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

if (!function_exists('ensureDigitalServiceTypes')) {
    function ensureDigitalServiceTypes(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS digital_service_types (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(60) NOT NULL,
                name_np VARCHAR(160) NOT NULL,
                name_en VARCHAR(160) DEFAULT '',
                icon VARCHAR(80) NOT NULL DEFAULT 'fas fa-laptop',
                color VARCHAR(40) NOT NULL DEFAULT 'var(--primary-color)',
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_builtin TINYINT(1) NOT NULL DEFAULT 0,
                requires_document TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_dst_slug (slug),
                INDEX idx_dst_active (is_active),
                INDEX idx_dst_order (display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            try {
                $db->exec('ALTER TABLE digital_service_types ADD COLUMN requires_document TINYINT(1) NOT NULL DEFAULT 0');
            } catch (Throwable $e) {
                /* already present */
            }

            $count = 0;
            try {
                $count = (int)$db->query('SELECT COUNT(*) FROM digital_service_types')->fetchColumn();
            } catch (Throwable $e) {
                return;
            }
            if ($count > 0) {
                $done = true;
                return;
            }

            $defaults = digitalServiceTypesDefaults();
            $order = 10;
            $stmt = $db->prepare(
                'INSERT INTO digital_service_types (slug, name_np, name_en, icon, color, display_order, is_active, is_builtin)
                 VALUES (?,?,?,?,?,?,1,1)'
            );
            foreach ($defaults as $slug => $row) {
                try {
                    $stmt->execute([
                        $slug,
                        $row['np'],
                        $row['en'],
                        $row['icon'],
                        $row['color'],
                        $order,
                    ]);
                } catch (Throwable $e) {
                    /* ignore duplicates */
                }
                $order += 10;
            }
            $done = true;
        } catch (Throwable $e) {
            error_log('[digital-service-types] ' . $e->getMessage());
        }
    }
}

if (!function_exists('digitalServiceTypesMap')) {
    /**
     * @return array<string, array{np:string,en:string,icon:string,color:string,requires_document:int}>
     */
    function digitalServiceTypesMap(?PDO $db = null, bool $activeOnly = true): array
    {
        $defaults = digitalServiceTypesDefaults();
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
            ensureDigitalServiceTypes($db);
            $sql = 'SELECT slug, name_np, name_en, icon, color, requires_document FROM digital_service_types';
            if ($activeOnly) {
                $sql .= ' WHERE is_active = 1';
            }
            $sql .= ' ORDER BY display_order ASC, id ASC';
            $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$rows) {
                return $defaults;
            }
            $map = [];
            foreach ($rows as $r) {
                $slug = (string)($r['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $icon = trim((string)($r['icon'] ?? ''));
                if ($icon === '') {
                    $icon = $defaults[$slug]['icon'] ?? 'fa-laptop';
                }
                /* Normalize "fas fa-x" → "fa-x" for templates that prepend fas */
                if (stripos($icon, 'fas ') === 0) {
                    $icon = trim(substr($icon, 4));
                }
                $map[$slug] = [
                    'np' => (string)($r['name_np'] ?? ''),
                    'en' => (string)($r['name_en'] ?? ''),
                    'icon' => $icon,
                    'color' => (string)($r['color'] ?? '') ?: 'var(--primary-color)',
                    'requires_document' => !empty($r['requires_document']) ? 1 : 0,
                ];
            }
            return $map ?: $defaults;
        } catch (Throwable $e) {
            /* Older DBs without column — retry without requires_document */
            try {
                $sql = 'SELECT slug, name_np, name_en, icon, color FROM digital_service_types';
                if ($activeOnly) {
                    $sql .= ' WHERE is_active = 1';
                }
                $sql .= ' ORDER BY display_order ASC, id ASC';
                $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $map = [];
                foreach ($rows as $r) {
                    $slug = (string)($r['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $icon = trim((string)($r['icon'] ?? ''));
                    if (stripos($icon, 'fas ') === 0) {
                        $icon = trim(substr($icon, 4));
                    }
                    $map[$slug] = [
                        'np' => (string)($r['name_np'] ?? ''),
                        'en' => (string)($r['name_en'] ?? ''),
                        'icon' => $icon !== '' ? $icon : ($defaults[$slug]['icon'] ?? 'fa-laptop'),
                        'color' => (string)($r['color'] ?? '') ?: 'var(--primary-color)',
                        'requires_document' => 0,
                    ];
                }
                return $map ?: $defaults;
            } catch (Throwable $e2) {
                return $defaults;
            }
        }
    }
}

if (!function_exists('digitalServiceTypeLabel')) {
    function digitalServiceTypeLabel(string $slug, bool $english = false, ?PDO $db = null): string
    {
        $map = digitalServiceTypesMap($db, false);
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

if (!function_exists('digitalServiceTypeUsageCount')) {
    function digitalServiceTypeUsageCount(PDO $db, string $slug): int
    {
        try {
            $st = $db->prepare('SELECT COUNT(*) FROM digital_service_requests WHERE service_type = ?');
            $st->execute([$slug]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
