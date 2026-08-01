<?php
/**
 * Public installer lock helpers.
 *
 * Current wizard: install.php (blocked by .htaccess when install.lock OR database.local.php).
 * Legacy: setup.php (if present) gated by .setup.lock — file often removed from packages.
 *
 * Day-to-day admin does not need DB Setup / Migration in the nav: columns/tables
 * auto-heal via ensure*Tables. Keep admin/db-setup.php + run-migration.php for
 * emergency / first bootstrap only (direct URL).
 */

if (!function_exists('coop_installer_root')) {
    function coop_installer_root(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('coop_installer_status')) {
    /**
     * @return array{
     *   public_safe: bool,
     *   install_php: bool,
     *   setup_php: bool,
     *   install_lock: bool,
     *   setup_lock: bool,
     *   local_db: bool,
     *   label: string,
     *   detail: string,
     *   public_url: string
     * }
     */
    function coop_installer_status(): array
    {
        $root = coop_installer_root();
        $installPhp = is_file($root . '/install.php');
        $setupPhp = is_file($root . '/setup.php');
        $installLock = is_file($root . '/install.lock');
        $setupLock = is_file($root . '/.setup.lock');
        $localDb = is_file($root . '/includes/database.local.php')
            || is_file($root . '/includes/database.php');

        /* .htaccess: install.php blocked when lock OR local DB config */
        $installBlocked = !$installPhp || $installLock || $localDb;
        $setupBlocked = !$setupPhp || $setupLock;
        $publicSafe = $installBlocked && $setupBlocked;

        $publicUrl = $installPhp ? '../install.php' : ($setupPhp ? '../setup.php' : '');

        return [
            'public_safe'  => $publicSafe,
            'install_php'  => $installPhp,
            'setup_php'    => $setupPhp,
            'install_lock' => $installLock,
            'setup_lock'   => $setupLock,
            'local_db'     => $localDb,
            'label'        => $publicSafe ? 'Locked' : 'Unlocked',
            'detail'       => $publicSafe
                ? 'Public install wizard बन्द / सुरक्षित। DB credentials: includes/database.local.php वा install.php (पहिलो पटक)।'
                : 'Public install wizard खुला हुन सक्छ — lock गर्नुहोस्।',
            'public_url'   => $publicUrl,
        ];
    }
}

if (!function_exists('coop_installer_auto_lock')) {
    /**
     * When local DB config exists, ensure lock files so public wizard stays closed.
     * Safe on live: no schema/data change.
     */
    function coop_installer_auto_lock(): bool
    {
        $root = coop_installer_root();
        $localDb = is_file($root . '/includes/database.local.php')
            || is_file($root . '/includes/database.php');
        if (!$localDb || !is_writable($root)) {
            return false;
        }
        $wrote = false;
        if (!is_file($root . '/install.lock')) {
            if (@file_put_contents(
                $root . '/install.lock',
                'Auto-locked: ' . date('c') . "\nReason: local DB config present\n"
            ) !== false) {
                $wrote = true;
            }
        }
        if (!is_file($root . '/.setup.lock')) {
            if (@file_put_contents(
                $root . '/.setup.lock',
                date('Y-m-d H:i:s') . " — Auto-lock (legacy setup.php gate)\n"
            ) !== false) {
                $wrote = true;
            }
        }
        return $wrote;
    }
}

if (!function_exists('coop_installer_toggle_lock')) {
    /** Lock or unlock public install gates (install.lock + .setup.lock). */
    function coop_installer_toggle_lock(bool $wantLocked): bool
    {
        $root = coop_installer_root();
        if ($wantLocked) {
            $ok = true;
            if (@file_put_contents($root . '/install.lock', date('c') . " — Locked from admin\n") === false) {
                $ok = false;
            }
            if (@file_put_contents($root . '/.setup.lock', date('Y-m-d H:i:s') . " — Locked from admin\n") === false) {
                $ok = false;
            }
            return $ok;
        }
        @unlink($root . '/install.lock');
        @unlink($root . '/.setup.lock');
        return true;
    }
}
