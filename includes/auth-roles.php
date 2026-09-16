<?php
/**
 * 🔐 Role-Based Access Control
 * ─────────────────────────────────────────────────────────────
 * तपाईंको existing `isAdminLoggedIn()` र `requireAdminLogin()`
 * (config.php मा छन्) लाई extend गरेर role hierarchy add गर्छ।
 *
 * Hierarchy:  superadmin (3)  >  admin (2)  >  staff/editor (1)
 *
 * Usage:
 *   require_role('admin');     // staff blocked, admin+ allowed
 *   require_role('superadmin'); // admin पनि blocked
 *   if (is_staff_or_above()) { ... }
 */

if (!function_exists('admin_db_role_is_superadmin')) {
    /** DB मा `superadmin` वा `super_admin` — दुवै superadmin */
    function admin_db_role_is_superadmin(?string $role): bool {
        $r = strtolower(trim((string) $role));
        return $r === 'superadmin' || $r === 'super_admin';
    }
}

if (!function_exists('admin_canonical_db_role')) {
    /**
     * Preferred DB spelling for new writes.
     * Keep reading both spellings via admin_db_role_is_superadmin / role_level.
     * Does not rewrite existing rows.
     */
    function admin_canonical_db_role(?string $role): string {
        $r = strtolower(trim((string) $role));
        if ($r === 'superadmin' || $r === 'super_admin') {
            return 'super_admin';
        }
        if ($r === 'staff') {
            return 'staff';
        }
        if ($r === 'editor') {
            return 'editor';
        }
        if ($r === 'admin') {
            return 'admin';
        }
        return 'admin';
    }
}

if (!function_exists('coop_widen_admin_role_enum')) {
    /**
     * Additive ENUM widen only — never DROP values / recreate table.
     * Aligns live admin_users.role with database/install.sql.
     */
    function coop_widen_admin_role_enum(PDO $db): void {
        $need = ['superadmin', 'super_admin', 'admin', 'staff', 'editor'];
        if (function_exists('safeWidenEnumColumn')) {
            safeWidenEnumColumn($db, 'admin_users', 'role', $need, 'admin');
            return;
        }
        try {
            $chk = $db->query("SHOW COLUMNS FROM `admin_users` LIKE 'role'");
            $col = $chk ? $chk->fetch(PDO::FETCH_ASSOC) : false;
            if (!$col) {
                return;
            }
            $type = strtolower((string) ($col['Type'] ?? ''));
            foreach ($need as $v) {
                if (!str_contains($type, "'" . $v . "'")) {
                    $db->exec(
                        "ALTER TABLE `admin_users` MODIFY COLUMN `role` "
                        . "ENUM('superadmin','super_admin','admin','staff','editor') DEFAULT 'admin'"
                    );
                    return;
                }
            }
        } catch (Throwable $e) {
            error_log('[coop_widen_admin_role_enum] ' . $e->getMessage());
        }
    }
}

if (!function_exists('coop_normalize_admin_role_aliases')) {
    /**
     * Alias-only row normalize after ENUM widen (NOT a privilege mass UPDATE).
     * Updates spelling only: superadmin → super_admin. Never changes privilege level
     * (admin/staff/editor rows untouched). Dual-read still accepts both via
     * admin_db_role_is_superadmin(). Privilege-level mass UPDATE remains deferred.
     *
     * @return array{updated:int, skipped:bool, error:?string}
     */
    function coop_normalize_admin_role_aliases(PDO $db): array {
        $out = ['updated' => 0, 'skipped' => false, 'error' => null];
        try {
            if (function_exists('coop_widen_admin_role_enum')) {
                coop_widen_admin_role_enum($db);
            }
            $chk = $db->query("SHOW COLUMNS FROM `admin_users` LIKE 'role'");
            $col = $chk ? $chk->fetch(PDO::FETCH_ASSOC) : false;
            if (!$col) {
                $out['skipped'] = true;
                return $out;
            }
            $type = strtolower((string) ($col['Type'] ?? ''));
            if (!str_contains($type, "'super_admin'") || !str_contains($type, "'superadmin'")) {
                /* Both spellings must exist in ENUM before UPDATE */
                $out['skipped'] = true;
                return $out;
            }
            $n = $db->exec("UPDATE `admin_users` SET `role` = 'super_admin' WHERE `role` = 'superadmin'");
            $out['updated'] = is_int($n) ? max(0, $n) : 0;
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
            error_log('[coop_normalize_admin_role_aliases] ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('current_admin_role')) {
    function current_admin_role(): string {
        if (!empty($_SESSION['admin_role'])) {
            return strtolower(trim((string) $_SESSION['admin_role']));
        }

        return !empty($_SESSION['is_superadmin']) ? 'superadmin' : 'admin';
    }
}

if (!function_exists('role_level')) {
    function role_level(string $role): int {
        $r = strtolower($role);
        if ($r === 'superadmin' || $r === 'super_admin') {
            return 3;
        }
        if ($r === 'admin') {
            return 2;
        }
        if ($r === 'staff' || $r === 'editor') {
            return 1;
        }
        return 0;
    }
}

if (!function_exists('has_role')) {
    function has_role(string $minRole): bool {
        return role_level(current_admin_role()) >= role_level($minRole);
    }
}

if (!function_exists('is_superadmin')) {
    function is_superadmin(): bool { return has_role('superadmin'); }
}
if (!function_exists('is_admin_or_above')) {
    function is_admin_or_above(): bool { return has_role('admin'); }
}
if (!function_exists('is_staff_or_above')) {
    function is_staff_or_above(): bool { return has_role('staff'); }
}

if (!function_exists('require_role')) {
    /**
     * Page को सुरुमा call गर्नुहोस्। तपाईंको existing
     * requireAdminLogin() पछि भए राम्रो।
     */
    function require_role(string $minRole): void {
        if (!isAdminLoggedIn()) {
            header('Location: ' . ADMIN_URL . 'index.php');
            exit;
        }
        if (!has_role($minRole)) {
            setFlash('error', 'यो पृष्ठ हेर्ने अनुमति छैन। (आवश्यक: ' . htmlspecialchars($minRole) . ')');
            header('Location: ' . ADMIN_URL . 'dashboard.php');
            exit;
        }
    }
}

/**
 * Login हुँदा role session मा राख्ने helper।
 * तपाईंको admin/index.php मा successful login पछि:
 *   set_admin_session($adminRow);
 */
if (!function_exists('set_admin_session')) {
    function set_admin_session(array $adminRow): void {
        $role = strtolower(trim((string) ($adminRow['role'] ?? 'admin')));
        if ($role === '') {
            $role = 'admin';
        }
        /* Alias-only session spelling (superadmin → super_admin); privilege unchanged */
        if (function_exists('admin_canonical_db_role')) {
            $role = admin_canonical_db_role($role);
        }
        $_SESSION['admin_id']        = (int)$adminRow['id'];
        $_SESSION['admin_username']  = $adminRow['username'] ?? '';
        $_SESSION['admin_name']      = $adminRow['name'] ?? $adminRow['full_name'] ?? $adminRow['username'] ?? 'Admin';
        $_SESSION['admin_role']      = $role;
        $_SESSION['is_superadmin']   = admin_db_role_is_superadmin($role);
        $_SESSION['admin_logged_in'] = true;
    }
}
