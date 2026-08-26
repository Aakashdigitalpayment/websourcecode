<?php
/**
 * HRM Tables ensure helper — auto-create on first load.
 * Targeted CREATE only (never replay full install.sql — PDO 2014 / data mutations).
 */
if (!function_exists('ensureHrmTables')) {
    function ensureHrmTables(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        try {
            $exists = function_exists('dbTableExists')
                ? dbTableExists('hrm_employees')
                : false;
            if (!$exists) {
                $probe = $db->query("SHOW TABLES LIKE 'hrm_employees'");
                $exists = $probe && $probe->fetch(PDO::FETCH_NUM) !== false;
                if ($probe instanceof PDOStatement) {
                    $probe->closeCursor();
                }
            }
            if ($exists) {
                $done = true;
                /* Still ensure messages table (may be missing on older installs) */
                if (function_exists('ensureHrmMessagesTable')) {
                    ensureHrmMessagesTable($db);
                }
                return;
            }
        } catch (Throwable $e) {
            /* fall through to create */
        }

        $ddls = [
            "CREATE TABLE IF NOT EXISTS hrm_departments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name_np VARCHAR(160) NOT NULL,
                name_en VARCHAR(160) DEFAULT NULL,
                code VARCHAR(40) DEFAULT NULL,
                parent_id INT DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_active (is_active),
                INDEX idx_parent (parent_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS hrm_employees (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_code VARCHAR(40) NOT NULL UNIQUE,
                admin_user_id INT DEFAULT NULL,
                full_name_np VARCHAR(160) NOT NULL,
                full_name_en VARCHAR(160) DEFAULT NULL,
                photo VARCHAR(255) DEFAULT NULL,
                gender ENUM('male','female','other') DEFAULT 'male',
                dob_bs VARCHAR(20) DEFAULT NULL,
                dob_ad DATE DEFAULT NULL,
                blood_group VARCHAR(10) DEFAULT NULL,
                marital_status ENUM('single','married','widow','divorced') DEFAULT 'single',
                nationality VARCHAR(60) DEFAULT 'Nepali',
                religion VARCHAR(60) DEFAULT NULL,
                ethnicity VARCHAR(60) DEFAULT NULL,
                citizenship_no VARCHAR(60) DEFAULT NULL,
                citizenship_issued_district VARCHAR(80) DEFAULT NULL,
                citizenship_issued_date_bs VARCHAR(20) DEFAULT NULL,
                pan_no VARCHAR(40) DEFAULT NULL,
                nid_no VARCHAR(40) DEFAULT NULL,
                passport_no VARCHAR(40) DEFAULT NULL,
                driving_license_no VARCHAR(40) DEFAULT NULL,
                mobile VARCHAR(20) DEFAULT NULL,
                alt_mobile VARCHAR(20) DEFAULT NULL,
                email VARCHAR(120) DEFAULT NULL,
                perm_province VARCHAR(60) DEFAULT NULL,
                perm_district VARCHAR(60) DEFAULT NULL,
                perm_municipality VARCHAR(120) DEFAULT NULL,
                perm_ward VARCHAR(10) DEFAULT NULL,
                perm_tole VARCHAR(160) DEFAULT NULL,
                temp_province VARCHAR(60) DEFAULT NULL,
                temp_district VARCHAR(60) DEFAULT NULL,
                temp_municipality VARCHAR(120) DEFAULT NULL,
                temp_ward VARCHAR(10) DEFAULT NULL,
                temp_tole VARCHAR(160) DEFAULT NULL,
                designation VARCHAR(160) DEFAULT NULL,
                department_id INT DEFAULT NULL,
                branch_id INT DEFAULT NULL,
                employment_type ENUM('permanent','contract','probation','temporary','intern','consultant') DEFAULT 'permanent',
                grade VARCHAR(40) DEFAULT NULL,
                level VARCHAR(40) DEFAULT NULL,
                reporting_to INT DEFAULT NULL,
                join_date_bs VARCHAR(20) DEFAULT NULL,
                join_date_ad DATE DEFAULT NULL,
                confirm_date_bs VARCHAR(20) DEFAULT NULL,
                confirm_date_ad DATE DEFAULT NULL,
                probation_months INT DEFAULT 0,
                status ENUM('active','probation','suspended','on_leave','resigned','terminated','retired') DEFAULT 'active',
                exit_date_ad DATE DEFAULT NULL,
                exit_reason TEXT DEFAULT NULL,
                remarks TEXT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                updated_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_dept (department_id),
                INDEX idx_branch (branch_id),
                INDEX idx_designation (designation),
                INDEX idx_admin_user (admin_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            /* Child tables without FKs — safer on shared hosting / partial installs */
            "CREATE TABLE IF NOT EXISTS hrm_employee_contracts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                contract_no VARCHAR(80) DEFAULT NULL,
                contract_type ENUM('appointment','contract','renewal','promotion','transfer','amendment') DEFAULT 'appointment',
                designation VARCHAR(160) DEFAULT NULL,
                department_id INT DEFAULT NULL,
                branch_id INT DEFAULT NULL,
                start_date_bs VARCHAR(20) DEFAULT NULL,
                start_date_ad DATE DEFAULT NULL,
                end_date_bs VARCHAR(20) DEFAULT NULL,
                end_date_ad DATE DEFAULT NULL,
                basic_salary DECIMAL(12,2) DEFAULT 0,
                allowance DECIMAL(12,2) DEFAULT 0,
                notes TEXT DEFAULT NULL,
                file_path VARCHAR(255) DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_emp (employee_id),
                INDEX idx_active (is_active),
                INDEX idx_end (end_date_ad)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS hrm_employee_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                doc_type VARCHAR(80) NOT NULL,
                title VARCHAR(200) NOT NULL,
                doc_number VARCHAR(120) DEFAULT NULL,
                issued_by VARCHAR(160) DEFAULT NULL,
                issued_date_bs VARCHAR(20) DEFAULT NULL,
                issued_date_ad DATE DEFAULT NULL,
                expiry_date_ad DATE DEFAULT NULL,
                file_path VARCHAR(255) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                uploaded_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_emp (employee_id),
                INDEX idx_type (doc_type),
                INDEX idx_expiry (expiry_date_ad)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS hrm_employee_education (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                level VARCHAR(80) NOT NULL,
                board_university VARCHAR(160) DEFAULT NULL,
                institution VARCHAR(200) DEFAULT NULL,
                major VARCHAR(160) DEFAULT NULL,
                passed_year VARCHAR(10) DEFAULT NULL,
                division_grade VARCHAR(40) DEFAULT NULL,
                percentage VARCHAR(20) DEFAULT NULL,
                file_path VARCHAR(255) DEFAULT NULL,
                sort_order INT DEFAULT 0,
                INDEX idx_emp (employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS hrm_employee_experience (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                organization VARCHAR(200) NOT NULL,
                designation VARCHAR(160) DEFAULT NULL,
                from_date_ad DATE DEFAULT NULL,
                to_date_ad DATE DEFAULT NULL,
                responsibilities TEXT DEFAULT NULL,
                sort_order INT DEFAULT 0,
                INDEX idx_emp (employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS hrm_employee_family (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                relation VARCHAR(60) NOT NULL,
                full_name VARCHAR(160) NOT NULL,
                contact VARCHAR(40) DEFAULT NULL,
                occupation VARCHAR(120) DEFAULT NULL,
                is_nominee TINYINT(1) DEFAULT 0,
                nominee_share DECIMAL(5,2) DEFAULT 0,
                notes VARCHAR(255) DEFAULT NULL,
                INDEX idx_emp (employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS hrm_employee_bank (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL UNIQUE,
                bank_name VARCHAR(160) DEFAULT NULL,
                branch VARCHAR(120) DEFAULT NULL,
                account_no VARCHAR(60) DEFAULT NULL,
                account_name VARCHAR(160) DEFAULT NULL,
                pf_no VARCHAR(60) DEFAULT NULL,
                cit_no VARCHAR(60) DEFAULT NULL,
                ssf_no VARCHAR(60) DEFAULT NULL,
                insurance_no VARCHAR(60) DEFAULT NULL,
                notes VARCHAR(255) DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS hrm_employee_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                event_type ENUM('appointment','confirmation','promotion','transfer','suspension','reinstatement','warning','award','leave','resignation','termination','retirement','other') NOT NULL,
                event_date_bs VARCHAR(20) DEFAULT NULL,
                event_date_ad DATE DEFAULT NULL,
                from_designation VARCHAR(160) DEFAULT NULL,
                to_designation VARCHAR(160) DEFAULT NULL,
                from_department_id INT DEFAULT NULL,
                to_department_id INT DEFAULT NULL,
                from_branch_id INT DEFAULT NULL,
                to_branch_id INT DEFAULT NULL,
                reference_no VARCHAR(80) DEFAULT NULL,
                description TEXT DEFAULT NULL,
                file_path VARCHAR(255) DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_emp (employee_id),
                INDEX idx_type (event_type),
                INDEX idx_date (event_date_ad)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($ddls as $sql) {
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                error_log('[ensureHrmTables] ' . $e->getMessage());
            }
        }

        if (function_exists('ensureHrmMessagesTable')) {
            ensureHrmMessagesTable($db);
        }

        $done = true;
    }
}

if (!function_exists('hrmListDepartments')) {
    function hrmListDepartments(PDO $db, bool $activeOnly = true): array
    {
        $sql = 'SELECT id, name_np, name_en, code FROM hrm_departments';
        if ($activeOnly) {
            $sql .= ' WHERE is_active=1';
        }
        $sql .= ' ORDER BY sort_order, id';
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('hrmListBranches')) {
    /**
     * Branches from service_centers (सेवा केन्द्र / शाखा) — shared helper.
     * @return list<array<string,mixed>>
     */
    function hrmListBranches(PDO $db): array
    {
        require_once dirname(__DIR__, 2) . '/includes/service-centers-helpers.php';
        $rows = fetchActiveServiceCenters($db, 300);
        foreach ($rows as &$r) {
            $r['name'] = serviceCenterDisplayName($r);
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('hrmEmploymentTypeLabel')) {
    function hrmEmploymentTypeLabel(string $type): string
    {
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
    function hrmStatusBadge(string $status): string
    {
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
        return '<span class="badge bg-' . $m[0] . '">' . $m[1] . '</span>';
    }
}

if (!function_exists('hrmEmployeePhotoUrl')) {
    function hrmEmployeePhotoUrl(?string $rel): string
    {
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
        $rel = trim((string) $rel);
        if ($rel === '') {
            return $placeholder;
        }
        if (preg_match('#^(https?:)?//#i', $rel) || strpos($rel, 'data:') === 0) {
            return $rel;
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if (defined('SITE_URL') && SITE_URL) {
            return rtrim((string) SITE_URL, '/') . '/' . $rel;
        }
        return '../' . $rel;
    }
}

if (!function_exists('hrmGenerateEmployeeCode')) {
    function hrmGenerateEmployeeCode(PDO $db): string
    {
        $year = date('Y');
        $row = $db->query('SELECT COUNT(*) FROM hrm_employees')->fetchColumn();
        return 'EMP-' . $year . '-' . str_pad((int) $row + 1, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('hrmHandleUpload')) {
    /** Returns relative path (assets/uploads/hrm/...) or null. */
    function hrmHandleUpload(array $file, string $subdir = 'docs'): ?string
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return null;
        }
        $root = dirname(__DIR__, 2); /* admin/includes → project root */
        $base = $root . '/assets/uploads/hrm/' . $subdir;
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf', 'doc', 'docx'];
        if (!in_array($ext, $allowed, true)) {
            return null;
        }
        if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
            return null;
        }
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $base . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return null;
        }
        return 'assets/uploads/hrm/' . $subdir . '/' . $name;
    }
}
