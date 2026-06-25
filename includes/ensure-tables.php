<?php
/**
 * =====================================================
 * ENSURE TABLES — Auto Table Creation
 * includes/ensure-tables.php
 *
 * यो file ले database migration नचलाए पनि सबै
 * public form tables automatically बनाउँछ।
 *
 * हरेक public form page को top मा यो include गर्नुहोस्:
 *   require_once 'includes/ensure-tables.php';
 *
 * यो file idempotent छ — बारम्बार run गर्दा
 * existing tables लाई कुनै असर गर्दैन।
 * =====================================================
 */

/* Optional includes — is_file() guard: missing file ले HTTP 500 नदिनु */
foreach ([
    __DIR__ . '/program-tables.php',
    __DIR__ . '/welfare-claims-tables.php',
    __DIR__ . '/digital-service-requests-tables.php',
    __DIR__ . '/partner-facilities-tables.php',
    __DIR__ . '/auction-tables.php',
    __DIR__ . '/vendors-tables.php',
    __DIR__ . '/careers-tables.php',
    __DIR__ . '/notification-log-tables.php',
    __DIR__ . '/why-choose-tables.php',
    __DIR__ . '/service-products-tables.php',
] as $_etFile) {
    if (is_file($_etFile)) { require_once $_etFile; }
}
unset($_etFile);

if (!function_exists('ensurePublicTables')) {

function ensurePublicTables(): void {
    static $done = false;
    if ($done) return; /* एकपटक मात्र run गर्ने */
    $done = true;

    try {
        $db = getDB();

        /* ──────────────────────────────────────────────────
           1. CONTACT MESSAGES
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS contact_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            phone VARCHAR(20),
            subject VARCHAR(200),
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ──────────────────────────────────────────────────
           2. SITE SETTINGS
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ──────────────────────────────────────────────────
           2b. MEMBER ID CARDS (verify.php को लागि आवश्यक)
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS member_id_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id VARCHAR(50) NOT NULL,
            card_no VARCHAR(40) NOT NULL UNIQUE,
            verification_code VARCHAR(20) NULL UNIQUE,
            cvv CHAR(4) NULL,
            issued_date DATE NOT NULL,
            expiry_date DATE NULL,
            status ENUM('active','expired','revoked') DEFAULT 'active',
            verify_count INT DEFAULT 0,
            last_verified_at DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_card_member (member_id),
            INDEX idx_card_status (status),
            INDEX idx_card_verify (verification_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ──────────────────────────────────────────────────
           3. GRIEVANCES (गुनासो तालिका)
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS grievances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracking_id VARCHAR(60) UNIQUE NULL,
            name VARCHAR(100) NOT NULL,
            member_id VARCHAR(50),
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(100),
            category ENUM('service','staff','loan','account','branch','other') DEFAULT 'other',
            subject VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            attachment VARCHAR(255),
            is_anonymous TINYINT(1) DEFAULT 0,
            status ENUM('pending','in_progress','resolved','closed') DEFAULT 'pending',
            admin_response TEXT,
            resolved_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_tracking (tracking_id),
            INDEX idx_phone (phone),
            INDEX idx_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ──────────────────────────────────────────────────
           4. APPOINTMENTS (भेटघाट बुकिङ)
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS appointments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(100),
            member_id VARCHAR(50),
            purpose ENUM('account_inquiry','loan_inquiry','kyc_update','loan_repayment','account_opening','other') DEFAULT 'other',
            purpose_detail TEXT,
            preferred_date DATE NOT NULL,
            preferred_time VARCHAR(20) NOT NULL,
            branch VARCHAR(100),
            status ENUM('pending','confirmed','completed','cancelled') DEFAULT 'pending',
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_date (preferred_date),
            INDEX idx_phone (phone)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ──────────────────────────────────────────────────
           5. LOAN APPLICATIONS (ऋण आवेदन)
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS loan_applications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracking_id VARCHAR(60) UNIQUE NULL,
            full_name VARCHAR(100) NOT NULL,
            member_id VARCHAR(50),
            mobile VARCHAR(20) NOT NULL,
            email VARCHAR(100),
            address TEXT,
            citizenship_no VARCHAR(50),
            loan_type VARCHAR(100),
            loan_amount DECIMAL(15,2),
            loan_purpose TEXT,
            loan_tenure INT,
            repayment_method VARCHAR(50),
            occupation VARCHAR(100),
            organization_name VARCHAR(200),
            monthly_income DECIMAL(15,2),
            collateral_type VARCHAR(100),
            collateral_description TEXT,
            guarantor_name VARCHAR(100),
            guarantor_phone VARCHAR(20),
            branch VARCHAR(100),
            status ENUM('pending','processing','approved','rejected','disbursed') DEFAULT 'pending',
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_mobile (mobile),
            INDEX idx_tracking (tracking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            'ALTER TABLE loan_applications ADD COLUMN other_income TEXT NULL AFTER monthly_income',
            'ALTER TABLE loan_applications ADD COLUMN collateral_value DECIMAL(15,2) NULL AFTER collateral_description',
            'ALTER TABLE loan_applications ADD COLUMN guarantor_relation VARCHAR(100) NULL AFTER guarantor_name',
            'ALTER TABLE loan_applications ADD COLUMN guarantor_address TEXT NULL AFTER guarantor_phone',
            'ALTER TABLE loan_applications ADD COLUMN documents TEXT NULL AFTER branch'
        ] as $sql) {
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
            }
        }

        /* ──────────────────────────────────────────────────
           6. KYC APPLICATIONS (केवाइसी)
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS kyc_applications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id VARCHAR(50),
            full_name VARCHAR(100) NOT NULL,
            full_name_en VARCHAR(100),
            dob_bs VARCHAR(20),
            dob_ad DATE,
            gender VARCHAR(20),
            marital_status VARCHAR(30),
            nationality VARCHAR(50) DEFAULT 'नेपाली',
            mobile VARCHAR(20) NOT NULL,
            email VARCHAR(100),
            permanent_address TEXT,
            temporary_address TEXT,
            citizenship_no VARCHAR(50),
            citizenship_issued_date VARCHAR(20),
            citizenship_issued_place VARCHAR(100),
            national_id_number VARCHAR(50),
            national_id_card VARCHAR(255),
            photo_quality_score TINYINT UNSIGNED NULL,
            risk_category ENUM('low','medium','high') DEFAULT 'medium',
            kyc_verified_at DATETIME NULL,
            risk_review_due_at DATE NULL,
            risk_review_status ENUM('normal','due_review') DEFAULT 'normal',
            father_name VARCHAR(100),
            mother_name VARCHAR(100),
            family_details_json TEXT,
            occupation VARCHAR(100),
            organization_name VARCHAR(200),
            monthly_income VARCHAR(50),
            account_type VARCHAR(50),
            branch VARCHAR(100),
            photo VARCHAR(255),
            citizenship_front VARCHAR(255),
            citizenship_back VARCHAR(255),
            signature VARCHAR(255),
            aml_details_json LONGTEXT NULL,
            status ENUM('pending','approved','rejected','incomplete','partial') DEFAULT 'pending',
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_mobile (mobile),
            INDEX idx_kyc_member (member_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ──────────────────────────────────────────────────
           7. ACCOUNT APPLICATIONS (खाता आवेदन)
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS account_applications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracking_id VARCHAR(60) UNIQUE NULL,
            account_type VARCHAR(50) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            full_name_en VARCHAR(100),
            dob_bs VARCHAR(20),
            dob_ad DATE,
            gender VARCHAR(20),
            marital_status VARCHAR(30),
            mobile VARCHAR(20) NOT NULL,
            email VARCHAR(100),
            permanent_address TEXT,
            temporary_address TEXT,
            citizenship_no VARCHAR(50),
            citizenship_issued_place VARCHAR(100),
            father_name VARCHAR(100),
            mother_name VARCHAR(100),
            occupation VARCHAR(100),
            monthly_income VARCHAR(50),
            initial_deposit VARCHAR(50),
            nominee_name VARCHAR(100),
            nominee_relation VARCHAR(50),
            nominee_phone VARCHAR(20),
            branch VARCHAR(100),
            photo VARCHAR(255),
            citizenship_front VARCHAR(255),
            citizenship_back VARCHAR(255),
            signature VARCHAR(255),
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_mobile (mobile),
            INDEX idx_tracking (tracking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            'ALTER TABLE account_applications ADD COLUMN citizenship_issued_date VARCHAR(40) NULL AFTER citizenship_no'
        ] as $sql) {
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
            }
        }

        /* ──────────────────────────────────────────────────
           8. MEMBER FEEDBACK (सदस्य सर्वेक्षण)
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS member_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracking_id VARCHAR(60) UNIQUE NULL,
            name VARCHAR(120) NOT NULL,
            member_id VARCHAR(50),
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(120),
            type ENUM('feedback','suggestion','complaint','inquiry') DEFAULT 'feedback',
            subject VARCHAR(255),
            message TEXT NOT NULL,
            status ENUM('pending','reviewed','resolved') DEFAULT 'pending',
            admin_reply TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phone (phone),
            INDEX idx_email (email),
            INDEX idx_tracking (tracking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ──────────────────────────────────────────────────
           9–10. WELFARE, DIGITAL SERVICE, PARTNER — includes/*-tables.php
        ────────────────────────────────────────────────── */
        if (function_exists('ensureWelfareClaimsTables')) { ensureWelfareClaimsTables($db); }
        if (function_exists('ensureDigitalServiceRequestsTables')) { ensureDigitalServiceRequestsTables($db); }

        /* ──────────────────────────────────────────────────
           10b. UPCOMING PROGRAMS + MEMBER ATTENDANCE — includes/program-tables.php
        ────────────────────────────────────────────────── */
        if (function_exists('ensureProgramTables')) { ensureProgramTables($db); }

        /* निर्वाचन तालिकाहरू — DDL एकै ठाउँ includes/election-tables.php */
        if (is_file(__DIR__ . '/election-tables.php')) {
            require_once __DIR__ . '/election-tables.php';
        }
        if (function_exists('ensureElectionTables')) { ensureElectionTables($db); }

        /* ──────────────────────────────────────────────────
           11. CAREERS — includes/careers-tables.php (admin/public एकै)
        ────────────────────────────────────────────────── */
        if (function_exists('ensureCareersTables')) { ensureCareersTables($db); }
        if (function_exists('ensureNotificationLogTable')) { ensureNotificationLogTable($db); }
        if (function_exists('ensureWhyChooseFeaturesTable')) { ensureWhyChooseFeaturesTable($db); }

        /* ──────────────────────────────────────────────────
           12. JOB APPLICATIONS (रोजगारी आवेदन)
               tracking_id column WITHOUT FOREIGN KEY to avoid career_id constraint
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS job_applications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracking_id VARCHAR(60) UNIQUE NULL,
            career_id INT NOT NULL DEFAULT 0,
            full_name VARCHAR(200) NOT NULL,
            email VARCHAR(100) NOT NULL,
            phone VARCHAR(20) NOT NULL,
            address VARCHAR(255),
            date_of_birth DATE,
            gender ENUM('male','female','other') DEFAULT 'male',
            education VARCHAR(255),
            experience VARCHAR(255),
            current_employer VARCHAR(200),
            expected_salary VARCHAR(50),
            cover_letter TEXT,
            resume_path VARCHAR(255),
            photo_path VARCHAR(255),
            citizenship_path VARCHAR(255),
            certificates_path VARCHAR(255),
            status ENUM('pending','shortlisted','interviewed','selected','rejected') DEFAULT 'pending',
            admin_notes TEXT,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_career (career_id),
            INDEX idx_status (status),
            INDEX idx_tracking (tracking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ──────────────────────────────────────────────────
           13. AUCTION + VENDORS (canonical helpers)
        ────────────────────────────────────────────────── */
        if (function_exists('ensureAuctionTables')) { ensureAuctionTables($db); }
        if (function_exists('ensureVendorsTables')) { ensureVendorsTables($db); }

        if (function_exists('ensurePartnerFacilitiesTables')) { ensurePartnerFacilitiesTables($db); }
        if (function_exists('ensureServiceProductsTables')) { ensureServiceProductsTables($db); }

        /* ──────────────────────────────────────────────────
           14. ADMIN USERS (यदि setup भएको छैन भने)
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(120),
            role VARCHAR(30) DEFAULT 'admin',
            is_active TINYINT(1) DEFAULT 1,
            last_login TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ──────────────────────────────────────────────────
           15. Column migrations — SHOW COLUMNS दिएर safe check
               IF NOT EXISTS सबै MySQL version मा support छैन,
               त्यसैले SHOW COLUMNS प्रयोग गरेर check गर्ने।
        ────────────────────────────────────────────────── */
        /* helper: column छ/छैन check गर्ने */
        $hasColumn = function(string $table, string $col) use ($db): bool {
            try {
                $r = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
                return $r && $r->rowCount() > 0;
            } catch (\Throwable $e) { return true; /* table नभए पनि skip */ }
        };
        /* helper: column थप गर्ने */
        $addColumn = function(string $sql) use ($db): void {
            try { $db->exec($sql); } catch (\Throwable $e) { /* skip */ }
        };
        /* helper: index छ/छैन check गर्ने */
        $hasIndex = function(string $table, string $idxName) use ($db): bool {
            try {
                $q = $db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $db->quote($idxName));
                return $q && $q->fetch(PDO::FETCH_ASSOC) !== false;
            } catch (\Throwable $e) { return true; }
        };
        /* helper: index add गर्ने */
        $addIndex = function(string $table, string $idxName, string $cols) use ($db, $hasIndex): void {
            if ($hasIndex($table, $idxName)) return;
            try { $db->exec("ALTER TABLE `{$table}` ADD INDEX `{$idxName}` ({$cols})"); } catch (\Throwable $e) {}
        };

        /* admin_users: पुरानो `name` कलम → `full_name` (install.sql / admin login सँग मेल) */
        if ($hasColumn('admin_users', 'name')) {
            if (!$hasColumn('admin_users', 'full_name')) {
                $addColumn('ALTER TABLE admin_users ADD COLUMN full_name VARCHAR(100) NOT NULL DEFAULT \'\' AFTER password');
            }
            try {
                $db->exec('UPDATE admin_users SET full_name = COALESCE(NULLIF(TRIM(full_name), \'\'), NULLIF(TRIM(`name`), \'\'), username)');
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $db->exec('ALTER TABLE admin_users DROP COLUMN `name`');
            } catch (\Throwable $e) { /* ignore */ }
        }

        /* tracking_id columns */
        foreach (['grievances','loan_applications','account_applications','job_applications','member_welfare_claims','member_feedback','appointments'] as $tbl) {
            if (!$hasColumn($tbl, 'tracking_id')) {
                $addColumn("ALTER TABLE `{$tbl}` ADD COLUMN tracking_id VARCHAR(60) UNIQUE NULL");
            }
        }

        /* date_of_birth — पुरानो DB मा नभएमा थप गर्ने (career-detail.php को लागि) */
        if (!$hasColumn('job_applications', 'date_of_birth')) {
            $addColumn("ALTER TABLE job_applications ADD COLUMN date_of_birth DATE NULL AFTER address");
        }

        /* gender — पुरानो job_applications (career-detail / admin) */
        if (!$hasColumn('job_applications', 'gender')) {
            $addColumn("ALTER TABLE job_applications ADD COLUMN gender ENUM('male','female','other') DEFAULT 'male' AFTER date_of_birth");
        }

        /* KYC मा member_id mandatory flow का लागि column ensure */
        if (!$hasColumn('kyc_applications', 'member_id')) {
            $addColumn("ALTER TABLE kyc_applications ADD COLUMN member_id VARCHAR(50) NULL AFTER id");
        }

        /* v10.4 KYC capture — औंठा छाप, structured address (online-kyc.php) */
        foreach ([
            'left_thumb' => 'VARCHAR(255) NULL',
            'right_thumb' => 'VARCHAR(255) NULL',
            'national_id_number' => 'VARCHAR(50) NULL',
            'national_id_card' => 'VARCHAR(255) NULL',
            'photo_quality_score' => 'TINYINT UNSIGNED NULL',
            'risk_category' => "ENUM('low','medium','high') DEFAULT 'medium'",
            'kyc_verified_at' => 'DATETIME NULL',
            'risk_review_due_at' => 'DATE NULL',
            'risk_review_status' => "ENUM('normal','due_review') DEFAULT 'normal'",
            'grandfather_name' => 'VARCHAR(100) NULL',
            'spouse_name' => 'VARCHAR(100) NULL',
            'family_details_json' => 'TEXT NULL',
            'aml_details_json' => 'LONGTEXT NULL',
            'permanent_province' => 'VARCHAR(60) NULL',
            'permanent_district' => 'VARCHAR(60) NULL',
            'permanent_municipality' => 'VARCHAR(120) NULL',
            'permanent_ward' => 'VARCHAR(10) NULL',
            'permanent_tole' => 'VARCHAR(120) NULL',
            'temporary_province' => 'VARCHAR(60) NULL',
            'temporary_district' => 'VARCHAR(60) NULL',
            'temporary_municipality' => 'VARCHAR(120) NULL',
            'temporary_ward' => 'VARCHAR(10) NULL',
            'temporary_tole' => 'VARCHAR(120) NULL',
            'tracking_id' => 'VARCHAR(60) NULL',
            'want_id_card' => 'TINYINT DEFAULT 0',
        ] as $kcol => $kdef) {
            if (!$hasColumn('kyc_applications', $kcol)) {
                $addColumn("ALTER TABLE kyc_applications ADD COLUMN `{$kcol}` {$kdef}");
            }
        }

        if (!$hasColumn('upcoming_programs', 'pre_registration_open')) {
            $addColumn("ALTER TABLE upcoming_programs ADD COLUMN pre_registration_open TINYINT(1) DEFAULT 0 AFTER is_active");
        }

        /* High-scale indexing (20k+ members / KYC rows) */
        $addIndex('kyc_applications', 'idx_kyc_member_status', 'member_id, status');
        $addIndex('kyc_applications', 'idx_kyc_status_created', 'status, created_at');
        $addIndex('kyc_applications', 'idx_kyc_mobile_status', 'mobile, status');
        $addIndex('kyc_applications', 'idx_kyc_email_status', 'email, status');
        $addIndex('kyc_applications', 'idx_kyc_updated', 'updated_at');
        $addIndex('kyc_applications', 'idx_kyc_risk_review', 'risk_review_status, risk_review_due_at');

        /* Pre-live performance hardening: tracker/admin list sort + lookup indexes */
        $addIndex('grievances', 'idx_grievances_status_created', 'status, created_at');
        $addIndex('grievances', 'idx_grievances_phone_created', 'phone, created_at');
        $addIndex('grievances', 'idx_grievances_email_created', 'email, created_at');

        $addIndex('loan_applications', 'idx_loan_mobile_created', 'mobile, created_at');
        $addIndex('loan_applications', 'idx_loan_email_created', 'email, created_at');
        $addIndex('account_applications', 'idx_account_mobile_created', 'mobile, created_at');
        $addIndex('account_applications', 'idx_account_email_created', 'email, created_at');
        $addIndex('member_feedback', 'idx_feedback_phone_created', 'phone, created_at');
        $addIndex('member_feedback', 'idx_feedback_email_created', 'email, created_at');
        $addIndex('member_welfare_claims', 'idx_welfare_phone_created', 'phone, created_at');
        $addIndex('member_welfare_claims', 'idx_welfare_email_created', 'email, created_at');
        $addIndex('digital_service_requests', 'idx_dsr_phone_created', 'phone, created_at');
        $addIndex('digital_service_requests', 'idx_dsr_email_created', 'email, created_at');
        $addIndex('member_program_attendance', 'idx_mpa_prog_att', 'program_id, attended_at');
        $addIndex('member_program_preregistrations', 'idx_mppr_prog_created', 'program_id, created_at');
        $addIndex('member_program_attendance_requests', 'idx_mpar_status_prog', 'status, program_id');
        $addIndex('vendors', 'idx_vendors_status_created', 'status, created_at');
        $addIndex('news', 'idx_news_created', 'created_at');

        /* Risk based KYC review auto-flag (verified date बाट) */
        try {
            $db->exec("UPDATE kyc_applications
                       SET risk_review_due_at = CASE
                            WHEN kyc_verified_at IS NULL THEN NULL
                            WHEN risk_category = 'high' THEN DATE_ADD(DATE(kyc_verified_at), INTERVAL 1 YEAR)
                            WHEN risk_category = 'low' THEN DATE_ADD(DATE(kyc_verified_at), INTERVAL 3 YEAR)
                            ELSE DATE_ADD(DATE(kyc_verified_at), INTERVAL 2 YEAR)
                       END
                       WHERE status = 'approved'");

            $db->exec("UPDATE kyc_applications
                       SET risk_review_status = CASE
                            WHEN status <> 'approved' OR risk_review_due_at IS NULL THEN 'normal'
                            WHEN risk_review_due_at <= CURDATE() THEN 'due_review'
                            ELSE 'normal'
                       END");
        } catch (\Throwable $e) {}

        $addIndex('members', 'idx_members_kyc_app', 'kyc_application_id');
        $addIndex('members', 'idx_members_sadasyata', 'sadasyata_number');
        $addIndex('members', 'idx_members_phone_active', 'phone, is_active');
        $addIndex('members', 'idx_members_email_active', 'email, is_active');

    } catch (\Throwable $e) {
        /* Silent fail — tables नबने पनि page break नगर्ने */
        /* Production debugging को लागि: error_log('ensure-tables: ' . $e->getMessage()); */
    }
}

} /* end: if (!function_exists('ensurePublicTables')) */

/**
 * AUTO-RUN — एकपटक मात्र (lock file आधारित)
 * v2 परिवर्तन: हरेक request मा 39 tables को CREATE/ALTER chalaउँदैन।
 * `.schema.lock` file देखिए skip गर्छ — admin panel "Migration Runner" बाट
 * lock हटाएर पुनः run गर्न सकिन्छ।
 */
$_lockFile = __DIR__ . '/../.schema.lock';
if (!file_exists($_lockFile)) {
    ensurePublicTables();
    @file_put_contents($_lockFile, "Schema initialized at " . date('Y-m-d H:i:s') . "\n"
        . "Delete this file र admin/db-setup.php बाट Migration Runner चलाउँदा\n"
        . "schema पुनः verify हुन्छ।\n");
}
unset($_lockFile);
