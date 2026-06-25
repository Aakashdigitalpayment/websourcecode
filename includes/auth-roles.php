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
        $_SESSION['admin_id']        = (int)$adminRow['id'];
        $_SESSION['admin_username']  = $adminRow['username'] ?? '';
        $_SESSION['admin_name']      = $adminRow['name'] ?? $adminRow['username'] ?? 'Admin';
        $_SESSION['admin_role']      = $adminRow['role'] ?? 'admin';
        $_SESSION['is_superadmin']   = admin_db_role_is_superadmin($adminRow['role'] ?? '');
        $_SESSION['admin_logged_in'] = true;
    }
}
