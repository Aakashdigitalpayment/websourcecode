<?php
/**
 * HRM Tables ensure helper — auto-create on first load.
 * Mirrors pattern of includes/election-tables.php in the base project.
 */
if (!function_exists('ensureHrmTables')) {
    function ensureHrmTables(PDO $db): void {
        static $done = false;
        if ($done) return;
        $sqlFile = dirname(__DIR__, 2) . '/database/install.sql';
        if (!is_file($sqlFile)) return;
        // Quick existence probe — skip heavy import on every page load.
        try {
            $exists = $db->query("SHOW TABLES LIKE 'hrm_employees'")->fetchColumn();
            if ($exists) { $done = true; return; }
        } catch (\Throwable $e) {}
        $sql = file_get_contents($sqlFile);
        if (!$sql) return;
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $sql = preg_replace('~/\*.*?\*/~s', '', (string)$sql);
        // Split on ; at line end (safe for our schema — no stored routines).
        $stmts = preg_split('/;\s*/u', (string)$sql);
        foreach ($stmts as $s) {
            $s = trim($s);
            if ($s === '') continue;
            try { $db->exec($s); } catch (\Throwable $e) { /* ignore re-runs */ }
        }
        $done = true;
    }
}

if (!function_exists('hrmListDepartments')) {
    function hrmListDepartments(PDO $db, bool $activeOnly = true): array {
        $sql = "SELECT id, name_np, name_en, code FROM hrm_departments";
        if ($activeOnly) $sql .= " WHERE is_active=1";
        $sql .= " ORDER BY sort_order, id";
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('hrmListBranches')) {
    /**
     * Branches from service_centers (सेवा केन्द्र / शाखा) — shared helper.
     * @return list<array<string,mixed>>
     */
    function hrmListBranches(PDO $db): array {
        require_once dirname(__DIR__, 2) . '/includes/service-centers-helpers.php';
        $rows = fetchActiveServiceCenters($db);
        foreach ($rows as &$r) {
            $r['name'] = serviceCenterDisplayName($r);
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('hrmEmploymentTypeLabel')) {
    function hrmEmploymentTypeLabel(string $type): string {
        $map = [
            'permanent'  => 'स्थायी',
            'contract'   => 'करार',
            'probation'  => 'परीक्षणकाल',
            'temporary'  => 'अस्थायी',
            'intern'     => 'इन्टर्न',
            'consultant' => 'परामर्शदाता',
        ];
        return $map[$type] ?? $type;
    }
}

if (!function_exists('hrmStatusBadge')) {
    function hrmStatusBadge(string $status): string {
        $map = [
            'active'      => ['success', 'सक्रिय'],
            'probation'   => ['info', 'परीक्षणकाल'],
            'on_leave'    => ['warning', 'बिदामा'],
            'suspended'   => ['warning', 'निलम्बित'],
            'resigned'    => ['secondary', 'राजीनामा'],
            'terminated'  => ['danger', 'बर्खास्त'],
            'retired'     => ['dark', 'अवकाश'],
        ];
        $m = $map[$status] ?? ['secondary', $status];
        return '<span class="badge bg-'.$m[0].'">'.$m[1].'</span>';
    }
}

if (!function_exists('hrmEmployeePhotoUrl')) {
    function hrmEmployeePhotoUrl(?string $rel): string {
        static $placeholder = null;
        if ($placeholder === null) {
            $placeholder = 'data:image/svg+xml,' . rawurlencode(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
                . '<rect width="64" height="64" fill="#e5e7eb"/>'
                . '<circle cx="32" cy="24" r="12" fill="#9ca3af"/>'
                . '<path d="M10 58c5-14 16-20 22-20s17 6 22 20" fill="#9ca3af"/>'
                . '</svg>'
            );
        }
        $rel = trim((string)$rel);
        if ($rel === '') {
            return $placeholder;
        }
        if (preg_match('#^(https?:)?//#i', $rel) || strpos($rel, 'data:') === 0) {
            return $rel;
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (defined('SITE_URL') && SITE_URL) {
            return rtrim((string)SITE_URL, '/') . '/' . $rel;
        }
        return '../' . $rel;
    }
}

if (!function_exists('hrmGenerateEmployeeCode')) {
    function hrmGenerateEmployeeCode(PDO $db): string {
        $year = date('Y');
        $row  = $db->query("SELECT COUNT(*) FROM hrm_employees")->fetchColumn();
        return 'EMP-' . $year . '-' . str_pad((int)$row + 1, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('hrmHandleUpload')) {
    /** Returns relative path (assets/uploads/hrm/...) or null. */
    function hrmHandleUpload(array $file, string $subdir = 'docs'): ?string {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
        $root = dirname(__DIR__, 2); /* admin/includes → project root */
        $base = $root . '/assets/uploads/hrm/' . $subdir;
        if (!is_dir($base)) @mkdir($base, 0775, true);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp','pdf','doc','docx'];
        if (!in_array($ext, $allowed, true)) return null;
        if (($file['size'] ?? 0) > 8 * 1024 * 1024) return null;
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $base . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
        return 'assets/uploads/hrm/' . $subdir . '/' . $name;
    }
}
