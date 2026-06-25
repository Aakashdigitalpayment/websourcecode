<?php
require_once __DIR__ . '/../../includes/ensure-tables.php';
require_once __DIR__ . '/../../includes/satisfaction-links-tables.php';
require_once __DIR__ . '/../../includes/member-of-year-tables.php';
require_once __DIR__ . '/../../includes/notification-templates-tables.php';
/**
 * =====================================================
 * ENSURE ADMIN TABLES
 * Admin panel मा आवश्यक सबै tables automatically create गर्छ
 * यो file admin-header.php बाट include हुन्छ
 * Re-run safe — CREATE TABLE IF NOT EXISTS प्रयोग गरिएको छ
 * =====================================================
 */
function ensureAdminTables(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $db = getDB();
        ensurePublicTables();
        ensureNotificationTemplatesSchema($db);

        /* ── 0. ADMIN USERS ────────────────────────────── */
        /* login गर्ने admin account हरू — MySQL 5.5 compatible */
        $db->exec("CREATE TABLE IF NOT EXISTS admin_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            role ENUM('super_admin','admin','editor') DEFAULT 'admin',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try {
            $db->exec('ALTER TABLE admin_users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $e) { /* already exists */ }

        /* ── 1. NOTICES ─────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS notices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            title_np VARCHAR(255),
            content TEXT,
            content_np TEXT,
            notice_date DATE,
            attachment VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            is_popup TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 2. INTEREST RATES ──────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS interest_rates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category ENUM('saving','loan') NOT NULL,
            name VARCHAR(100) NOT NULL,
            name_np VARCHAR(100),
            rate DECIMAL(5,2) NOT NULL DEFAULT 0,
            description TEXT,
            description_np TEXT,
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 3. SERVICES ────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(100) NOT NULL,
            title_np VARCHAR(100),
            title_en VARCHAR(100),
            description TEXT,
            description_np TEXT,
            icon VARCHAR(50) DEFAULT 'fas fa-star',
            image VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 4. SLIDERS (Homepage Banners) ──────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS sliders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200),
            subtitle VARCHAR(255),
            image VARCHAR(255) NOT NULL,
            button_text VARCHAR(50),
            button_url VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 5. GALLERY ──────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS gallery (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200),
            title_np VARCHAR(200),
            image VARCHAR(255) NOT NULL,
            category VARCHAR(50) DEFAULT 'general',
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 6. TEAM MEMBERS ────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS team_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            name_en VARCHAR(100),
            position VARCHAR(100),
            position_np VARCHAR(100),
            position_en VARCHAR(100),
            photo VARCHAR(255),
            phone VARCHAR(20),
            email VARCHAR(100),
            category ENUM('board','management','staff') DEFAULT 'staff',
            is_information_officer TINYINT(1) DEFAULT 0,
            is_grievance_officer TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 7. PAGES (Dynamic content) ─────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS pages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(100) NOT NULL UNIQUE,
            title VARCHAR(200) NOT NULL,
            title_np VARCHAR(200),
            title_en VARCHAR(200),
            content LONGTEXT,
            content_np LONGTEXT,
            show_in_menu TINYINT(1) DEFAULT 0,
            menu_position VARCHAR(50) DEFAULT 'about',
            menu_order INT DEFAULT 0,
            is_new TINYINT(1) DEFAULT 0,
            new_until DATE,
            is_active TINYINT(1) DEFAULT 1,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 8. DOWNLOADS ───────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS downloads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            title_np VARCHAR(200),
            file_path VARCHAR(255) NOT NULL,
            file_type VARCHAR(50),
            category VARCHAR(50) DEFAULT 'general',
            download_count INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 9. REPORTS ──────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            title_np VARCHAR(200),
            report_type VARCHAR(50) DEFAULT 'annual',
            report_year VARCHAR(20),
            report_month VARCHAR(20),
            report_quarter VARCHAR(10),
            file_path VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 10. NEWS ───────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS news (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            title_np VARCHAR(200),
            content TEXT,
            content_np TEXT,
            image VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 11. SERVICE CENTERS ────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS service_centers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            name_np VARCHAR(200),
            address VARCHAR(255),
            phone VARCHAR(50),
            email VARCHAR(100),
            province VARCHAR(50),
            opening_hours VARCHAR(100),
            map_url VARCHAR(500),
            is_main_branch TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 12. USEFUL LINKS ───────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS useful_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            title_np VARCHAR(200),
            url VARCHAR(500) NOT NULL,
            icon VARCHAR(100) DEFAULT 'fas fa-link',
            description TEXT,
            description_np TEXT,
            is_popup TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 13. IMPORTANT LINKS (legacy alias) ─────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS important_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            title_np VARCHAR(200),
            url VARCHAR(255) NOT NULL,
            icon VARCHAR(255),
            description VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 14. COMMITTEE TYPES ────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS committee_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            name_np VARCHAR(100) NOT NULL,
            description TEXT,
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 15. COMMITTEE TENURES ──────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS committee_tenures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            committee_type_id INT NOT NULL,
            tenure_name VARCHAR(100) NOT NULL,
            tenure_name_np VARCHAR(100),
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            is_current TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 16. COMMITTEE MEMBERS ──────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS committee_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tenure_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            name_en VARCHAR(100),
            position VARCHAR(100) NOT NULL,
            position_en VARCHAR(100),
            phone VARCHAR(20),
            email VARCHAR(100),
            address VARCHAR(255),
            photo VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 17. CHATBOT FAQs ───────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS chatbot_faqs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question VARCHAR(255) NOT NULL,
            question_en VARCHAR(255),
            answer TEXT NOT NULL,
            answer_en TEXT,
            category VARCHAR(50),
            keywords TEXT,
            display_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 18. FAQs (Admin manageable) ────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS faqs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            question VARCHAR(500) NOT NULL,
            question_np VARCHAR(500),
            answer TEXT NOT NULL,
            answer_np TEXT,
            category VARCHAR(100) DEFAULT 'general',
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 19. AWARDS ──────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS awards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            title_np VARCHAR(255),
            description TEXT,
            description_np TEXT,
            awarded_by VARCHAR(255),
            awarded_by_np VARCHAR(255),
            award_date DATE,
            image VARCHAR(255),
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 20. HELP TOPICS ────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS help_topics (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            title_np VARCHAR(255),
            content TEXT NOT NULL,
            content_np TEXT,
            icon VARCHAR(100) DEFAULT 'fas fa-question-circle',
            category VARCHAR(100) DEFAULT 'general',
            is_active TINYINT(1) DEFAULT 1,
            display_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 21. SITE STATS (Visitor counter) ──────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS site_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            stat_key VARCHAR(100) NOT NULL UNIQUE,
            stat_value BIGINT DEFAULT 0,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 22. VISITOR COUNTER ────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS visitor_counter (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT,
            page_visited VARCHAR(255),
            visit_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_visit_date (visit_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 23. APP FEATURES ───────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS app_features (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(100) NOT NULL,
            title_np VARCHAR(100),
            icon VARCHAR(100) DEFAULT 'fas fa-star',
            description TEXT,
            description_np TEXT,
            is_new TINYINT(1) DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 24. INSTITUTIONAL PROFILE — Complete schema ─── */
        $db->exec("CREATE TABLE IF NOT EXISTS institutional_profile (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fiscal_year VARCHAR(20) NOT NULL,
            report_date_bs VARCHAR(60) DEFAULT '' COMMENT 'मिति बि.सं.',
            report_date_ad DATE NULL COMMENT 'मिति A.D.',
            total_members INT DEFAULT 0,
            total_balance_member INT DEFAULT 0 COMMENT 'शेष सदस्य',
            total_assets DECIMAL(18,2) DEFAULT 0,
            share_capital DECIMAL(18,2) DEFAULT 0,
            share_capital_percent DECIMAL(8,2) DEFAULT 0 COMMENT 'शेयर % वृद्धि',
            reserved_fund DECIMAL(18,2) DEFAULT 0,
            reserved_fund_percent DECIMAL(8,2) DEFAULT 0,
            other_fund DECIMAL(18,2) DEFAULT 0,
            bank_cash_balance DECIMAL(18,2) DEFAULT 0,
            fixed_assets DECIMAL(18,2) DEFAULT 0,
            deposit DECIMAL(18,2) DEFAULT 0,
            deposit_percent DECIMAL(8,2) DEFAULT 0,
            loan DECIMAL(18,2) DEFAULT 0,
            loan_percent DECIMAL(8,2) DEFAULT 0,
            total_loan_members INT DEFAULT 0,
            total_loan_reserve_fund DECIMAL(15,2) DEFAULT 0,
            total_loan_reserve_percent DECIMAL(8,2) DEFAULT 0,
            npa_percent DECIMAL(5,2) DEFAULT 0,
            npl_percent DECIMAL(5,2) DEFAULT 0,
            liquidity_percent DECIMAL(8,2) DEFAULT 0,
            net_profit DECIMAL(18,2) DEFAULT 0,
            branch_count INT DEFAULT 0,
            staff_count INT DEFAULT 0,
            report_note TEXT DEFAULT NULL COMMENT 'थप टिप्पणी',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 24b. ALTER: Add missing columns for existing DB installs ─
                   MySQL 5.7 compatible: no IF NOT EXISTS — catch dup error ── */
        $ipAlters = [
            "ALTER TABLE institutional_profile ADD COLUMN report_date_bs VARCHAR(60) DEFAULT ''",
            "ALTER TABLE institutional_profile ADD COLUMN report_date_ad DATE NULL",
            "ALTER TABLE institutional_profile ADD COLUMN total_balance_member INT DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN share_capital_percent DECIMAL(8,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN reserved_fund DECIMAL(18,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN reserved_fund_percent DECIMAL(8,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN other_fund DECIMAL(18,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN bank_cash_balance DECIMAL(18,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN fixed_assets DECIMAL(18,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN deposit_percent DECIMAL(8,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN loan_percent DECIMAL(8,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN total_loan_members INT DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN total_loan_reserve_fund DECIMAL(15,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN total_loan_reserve_percent DECIMAL(8,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN npl_percent DECIMAL(5,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN liquidity_percent DECIMAL(8,2) DEFAULT 0",
            "ALTER TABLE institutional_profile ADD COLUMN report_note TEXT DEFAULT NULL",
        ];
        foreach ($ipAlters as $sql) { try { $db->exec($sql); } catch (Exception $e) {} }

        /* ── team_members: पुरानो DB मा नयाँ columns थप्ने ── */
        $tmAlters = [
            "ALTER TABLE team_members ADD COLUMN name_en VARCHAR(120) AFTER name",
            "ALTER TABLE team_members ADD COLUMN position_np VARCHAR(100) AFTER position",
            "ALTER TABLE team_members ADD COLUMN position_en VARCHAR(100) AFTER position_np",
            "ALTER TABLE team_members ADD COLUMN is_information_officer TINYINT(1) DEFAULT 0",
            "ALTER TABLE team_members ADD COLUMN is_grievance_officer TINYINT(1) DEFAULT 0",
            "ALTER TABLE team_members ADD COLUMN display_order INT DEFAULT 0",
        ];
        foreach ($tmAlters as $sql) { try { $db->exec($sql); } catch (Exception $e) {} }

        /* ── committee_types: navbar drop-down toggle column ── */
        $ctAlters = [
            "ALTER TABLE committee_types ADD COLUMN show_in_navbar TINYINT(1) DEFAULT 0",
        ];
        foreach ($ctAlters as $sql) { try { $db->exec($sql); } catch (Exception $e) {} }

        /* ── 25. NOTIFICATION LOG — ensurePublicTables + notification-log-tables.php ── */

        /* ── 26–27. MEMBER OF YEAR + SATISFACTION LINKS — shared helpers ── */
        ensureMemberOfYearTable($db);
        ensureSatisfactionLinksTables($db);

        /* ── 28. VENDORS — ensurePublicTables + ensureVendorsTables ── */

        /* ── 29. ACTIVITY LOG ───────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT,
            action VARCHAR(100),
            description TEXT,
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        /* ── 30a. SITE SETTINGS ─────────────────────────────
           satisfaction_widget_enabled लगायत सबै settings यहाँ save हुन्छन्।
           updateSetting() र getSetting() functions यही table use गर्छन्।
           यो table नभए widget enable गर्दा पनि public page मा देखिँदैन।
        ────────────────────────────────────────────────── */
        $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE COMMENT 'Setting को unique key',
            setting_value TEXT          COMMENT 'Setting को value',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Global site settings — admin panel र setup.php दुवैले use गर्छन्'");

        /* ── 30. VISITOR STATS (summary by date) ───────── */
        $db->exec("CREATE TABLE IF NOT EXISTS visitor_stats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            visit_date DATE NOT NULL UNIQUE,
            visitor_count INT DEFAULT 0,
            page_views INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    } catch (\Exception $e) {
        /* Silent — admin देख्छन् errors admin pages मा नै */
    }
}

/* Admin header include हुँदा एकपटक मात्र call हुन्छ — `.admin-schema.lock` बाट guard
 * v2 परिवर्तन: हरेक admin page load मा 30+ tables को CREATE/ALTER overhead हटाइयो।
 * Migration Runner ले lock file हटाएर पुनः verify गराउन सक्छ। */
$_adminLock = dirname(__DIR__, 2) . '/.admin-schema.lock';
if (!file_exists($_adminLock)) {
    ensureAdminTables();
    @file_put_contents($_adminLock, "Admin schema initialized at " . date('Y-m-d H:i:s') . "\n");
}
unset($_adminLock);
