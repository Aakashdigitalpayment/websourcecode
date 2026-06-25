<?php
/**
 * Superadmin — तपाईंको “पहिले जस्तै” मोडेल (admin login नै प्रवेशद्वार)
 *
 * १) cPanel → `includes/superadmin-config.local.php` (example बाट कपी)।
 * २) त्यसमा `SUPER_ADMIN_USERNAME` + `SUPER_ADMIN_INITIAL_PASSWORD` — यही superadmin को hardcode।
 * ३) DB पहिले नमिलेको: `includes/database.local.php` भरिसकेको छ भने `…/admin/db-setup.php` सिधै खुल्छ;
 *    नभए `superadmin-config.local.php` को user/pass ले unlock। install.sql पछि `admin/index.php` login।
 * ४) Login ले DB मा super_admin row बनाउँछ/मिलाउँछ; पछि panel खुल्छ।
 * ५) Superadmin ले `manage-admins.php` मा अरू admin/editor create गर्छ (नयाँ staff को पासवर्ड DB)।
 * ६) Superadmin पासवर्ड बदल्न फेरि मात्र फाइल edit + login (hash sync)।
 * ७) `SUPER_ADMIN_INITIAL_PASSWORD` खाली नभएसम्म यो username admin सूची/UI मा देखिँदैन।
 *
 * Local फाइल छैन भने: `install.sql` को `admin` / `password` (backup) प्रयोग हुन सक्छ।
 */
declare(strict_types=1);

if (is_readable(__DIR__ . '/superadmin-config.local.php')) {
    require_once __DIR__ . '/superadmin-config.local.php';
}

if (!defined('SUPER_ADMIN_USERNAME')) {
    define('SUPER_ADMIN_USERNAME', 'admin');
}
if (!defined('SUPER_ADMIN_DISPLAY_NAME')) {
    define('SUPER_ADMIN_DISPLAY_NAME', 'Administrator');
}
if (!defined('SUPER_ADMIN_INITIAL_PASSWORD')) {
    define('SUPER_ADMIN_INITIAL_PASSWORD', '');
}

/** फाइलबाट पासवर्ड चल्ने superadmin को username — UI सूचीबाट लुकाउन */
if (!function_exists('file_managed_superadmin_username')) {
    function file_managed_superadmin_username(): ?string {
        if (!defined('SUPER_ADMIN_USERNAME') || !defined('SUPER_ADMIN_INITIAL_PASSWORD')) {
            return null;
        }
        if ((string) SUPER_ADMIN_INITIAL_PASSWORD === '') {
            return null;
        }
        $u = trim((string) SUPER_ADMIN_USERNAME);
        return $u !== '' ? $u : null;
    }
}

/** @param array<int, array<string, mixed>> $rows */
if (!function_exists('filter_out_file_managed_superadmin_rows')) {
    function filter_out_file_managed_superadmin_rows(array $rows): array {
        $hide = file_managed_superadmin_username();
        if ($hide === null) {
            return $rows;
        }
        return array_values(array_filter($rows, static function ($row) use ($hide): bool {
            return (string) ($row['username'] ?? '') !== $hide;
        }));
    }
}

if (!function_exists('admin_row_is_file_managed_superadmin')) {
    /** @param array<string, mixed>|false|null $row */
    function admin_row_is_file_managed_superadmin(array|false|null $row): bool {
        if (!is_array($row)) {
            return false;
        }
        $hide = file_managed_superadmin_username();
        return $hide !== null && (string) ($row['username'] ?? '') === $hide;
    }
}

if (!function_exists('admin_user_id_is_file_managed_superadmin')) {
    function admin_user_id_is_file_managed_superadmin(PDO $db, int $userId): bool {
        if ($userId < 1) {
            return false;
        }
        $hide = file_managed_superadmin_username();
        if ($hide === null) {
            return false;
        }
        try {
            $st = $db->prepare('SELECT username FROM admin_users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return is_array($r) && (string) ($r['username'] ?? '') === $hide;
        } catch (Throwable $e) {
            return false;
        }
    }
}
