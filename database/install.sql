-- =====================================================
-- आकाश सहकारी — COMPLETE DATABASE SQL
-- phpMyAdmin बाट import गर्नुस् (database select गरेर वा USE statement ले)
-- Compatible: MySQL 5.7+, MariaDB 10.3+
-- नयाँ कलम/टेबल थप्दा includes/*-tables.php र includes/ensure-tables.php सँग मेल राख्नुहोस्
-- (यो फाइल fresh import को लागि; पुरानो डाटा migration यहाँ छैन।)
-- =====================================================

-- v10.3 FIX: hardcoded USE statement हटाइयो — Admin DB Setup ले selected database मा execute गर्छ।
-- (पहिले `USE bandanas_aakashsaccos_db;` थियो जुन अन्य hosting users को लागि कुनै मतलब थिएन।)

-- Foreign key checks बन्द (clean install को लागि)
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- 1. ADMIN USERS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS admin_users;
CREATE TABLE IF NOT EXISTS admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    role ENUM('superadmin','super_admin','admin','staff','editor') DEFAULT 'admin',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin (password: password) — जब `includes/superadmin-config.local.php` हुँदैन तब पहिलो login को लागि।
-- Production: local फाइलमा SUPER_ADMIN_USERNAME/PASSWORD राख्नुहोस् → admin/index.php त्यही ले login;
--     पहिलो login ले DB मा super_admin seed/sync गर्छ, अनि manage-admins बाट अरू staff थप्नुहोस्।
-- =====================================================
-- 1b. MEMBERS TABLE  (v10.3 FIX — पहिले PHP runtime बाट मात्र create हुन्थ्यो)
-- अब install.sql ले पनि create गर्छ ताकि subsequent ALTER/INDEX statements safely run हुन्।
-- IMPORTANT: यहाँ `IF NOT EXISTS` मात्र — `DROP TABLE` छैन (existing data जोगाउन)।
-- =====================================================

CREATE TABLE IF NOT EXISTS members (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(255) NOT NULL,
    email                VARCHAR(255) UNIQUE,
    phone                VARCHAR(20),
    sadasyata_number     VARCHAR(50) NOT NULL DEFAULT '',
    password_hash        VARCHAR(255),
    google_id            VARCHAR(255),
    facebook_id          VARCHAR(255),
    avatar_url           VARCHAR(500),
    member_card_no       VARCHAR(50),
    address              TEXT,
    dob                  DATE,
    gender               VARCHAR(20),
    approval_status      VARCHAR(20) DEFAULT 'pending',
    approved_at          TIMESTAMP NULL DEFAULT NULL,
    approved_by          INT NULL DEFAULT NULL,
    rejection_reason     TEXT,
    id_card_generated    TINYINT DEFAULT 0,
    id_card_generated_at TIMESTAMP NULL DEFAULT NULL,
    is_verified          TINYINT DEFAULT 0,
    is_active            TINYINT DEFAULT 1,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login           TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_sadasyata_number (sadasyata_number),
    INDEX idx_approval_status (approval_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 2. SITE SETTINGS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS site_settings;
CREATE TABLE IF NOT EXISTS site_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type ENUM('text', 'textarea', 'image', 'number', 'color', 'url') DEFAULT 'text',
    setting_label VARCHAR(200),
    setting_group VARCHAR(50) DEFAULT 'general',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key),
    INDEX idx_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 3. NOTICES TABLE
-- =====================================================

-- DROP TABLE IF EXISTS notices;
CREATE TABLE IF NOT EXISTS notices (
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
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_popup (is_popup),
    INDEX idx_date (notice_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 4. INTEREST RATES TABLE
-- =====================================================

-- DROP TABLE IF EXISTS interest_rates;
CREATE TABLE IF NOT EXISTS interest_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category ENUM('saving', 'loan') NOT NULL,
    name VARCHAR(100) NOT NULL,
    name_np VARCHAR(100),
    rate DECIMAL(5,2) NOT NULL,
    description TEXT,
    description_np TEXT,
    is_active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_active (is_active),
    INDEX idx_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 5. SERVICES TABLE
-- =====================================================

-- DROP TABLE IF EXISTS services;
CREATE TABLE IF NOT EXISTS services (
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 6. SLIDERS TABLE (Homepage Banners)
-- =====================================================

-- DROP TABLE IF EXISTS sliders;
CREATE TABLE IF NOT EXISTS sliders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200),
    subtitle VARCHAR(255),
    image VARCHAR(255) NOT NULL,
    button_text VARCHAR(50),
    button_url VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_order (display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 7. GALLERY TABLE
-- =====================================================

-- DROP TABLE IF EXISTS gallery;
CREATE TABLE IF NOT EXISTS gallery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200),
    title_np VARCHAR(200),
    image VARCHAR(255) NOT NULL,
    category VARCHAR(50) DEFAULT 'general',
    is_active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 8. TEAM MEMBERS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS team_members;
CREATE TABLE IF NOT EXISTS team_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_en VARCHAR(100),
    position VARCHAR(100),
    position_np VARCHAR(100),
    position_en VARCHAR(100),
    photo VARCHAR(255),
    phone VARCHAR(20),
    email VARCHAR(100),
    category ENUM('board', 'management', 'staff') DEFAULT 'staff',
    is_information_officer TINYINT(1) DEFAULT 0,
    is_grievance_officer TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 9. CONTACT MESSAGES TABLE
-- =====================================================

-- DROP TABLE IF EXISTS contact_messages;
CREATE TABLE IF NOT EXISTS contact_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    subject VARCHAR(200),
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_read (is_read),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 10. PAGES TABLE (Dynamic pages)
-- =====================================================

-- DROP TABLE IF EXISTS pages;
CREATE TABLE IF NOT EXISTS pages (
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
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_slug (slug),
    INDEX idx_active (is_active),
    INDEX idx_menu (show_in_menu, menu_position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 11. DOWNLOADS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS downloads;
CREATE TABLE IF NOT EXISTS downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    title_np VARCHAR(200),
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(50),
    category VARCHAR(50) DEFAULT 'general',
    download_count INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 12. CAREERS/JOBS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS careers;
-- Canonical: includes/careers-tables.php (admin/public career.php सँग मेल)
CREATE TABLE IF NOT EXISTS careers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    title_np VARCHAR(255) DEFAULT NULL,
    department VARCHAR(150) DEFAULT NULL,
    location VARCHAR(150) DEFAULT NULL,
    job_type VARCHAR(50) DEFAULT 'full_time',
    description TEXT,
    description_np TEXT,
    requirements TEXT,
    deadline DATE DEFAULT NULL,
    attachment VARCHAR(255) DEFAULT NULL,
    vacancies INT DEFAULT 1,
    min_qualification VARCHAR(255) DEFAULT NULL,
    experience_required VARCHAR(150) DEFAULT NULL,
    salary_range VARCHAR(150) DEFAULT NULL,
    allow_online_apply TINYINT(1) DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_careers_active (is_active),
    INDEX idx_careers_deadline (deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 13. REPORTS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS reports;
CREATE TABLE IF NOT EXISTS reports (
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_type (report_type),
    INDEX idx_year (report_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 14. MEMBER FEEDBACK TABLE
-- =====================================================

-- DROP TABLE IF EXISTS member_feedback;
CREATE TABLE IF NOT EXISTS member_feedback (
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
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_type (type),
    INDEX idx_phone (phone),
    INDEX idx_email (email),
    INDEX idx_tracking (tracking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 15. NEWS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS news;
CREATE TABLE IF NOT EXISTS news (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    title_np VARCHAR(200),
    content TEXT,
    content_np TEXT,
    image VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- why_choose_features / satisfaction_links — includes/why-choose-tables.php, satisfaction-links-tables.php
CREATE TABLE IF NOT EXISTS why_choose_features (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    icon        VARCHAR(100)  NOT NULL DEFAULT 'fas fa-star',
    title_np    VARCHAR(200)  NOT NULL DEFAULT '',
    title_en    VARCHAR(200)  NOT NULL DEFAULT '',
    desc_np     TEXT,
    desc_en     TEXT,
    sort_order  INT           NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS satisfaction_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    title_en VARCHAR(200),
    url VARCHAR(500) NOT NULL,
    icon VARCHAR(100) DEFAULT 'fas fa-smile',
    is_active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- member_of_year — includes/member-of-year-tables.php (admin/member-of-year.php)
CREATE TABLE IF NOT EXISTS member_of_year (
    id INT AUTO_INCREMENT PRIMARY KEY,
    spotlight_year VARCHAR(10) NOT NULL UNIQUE,
    member_name VARCHAR(200) NOT NULL,
    member_name_en VARCHAR(100),
    member_id VARCHAR(50),
    photo VARCHAR(500),
    member_since VARCHAR(20),
    quote TEXT,
    quote_en TEXT,
    achievement TEXT,
    achievement_en TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 16. SERVICE CENTERS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS service_centers;
CREATE TABLE IF NOT EXISTS service_centers (
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_main (is_main_branch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 17. VENDORS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS vendors;
CREATE TABLE IF NOT EXISTS vendors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_id VARCHAR(60) UNIQUE NULL,
    company_name VARCHAR(255) NOT NULL,
    owner_name VARCHAR(100),
    address VARCHAR(255),
    phone VARCHAR(20),
    email VARCHAR(100),
    pan_no VARCHAR(50),
    business_type VARCHAR(100),
    description TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_vendors_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 17b. DIGITAL SERVICE REQUESTS — includes/digital-service-requests-tables.php
-- =====================================================

CREATE TABLE IF NOT EXISTS digital_service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_id VARCHAR(50) UNIQUE NOT NULL,
    requester_name VARCHAR(120) NOT NULL,
    member_id VARCHAR(50),
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(120),
    service_type VARCHAR(60) NOT NULL,
    service_type_np VARCHAR(120),
    account_number VARCHAR(50),
    statement_from DATE NULL,
    statement_to DATE NULL,
    biller_name VARCHAR(120),
    bill_reference VARCHAR(120),
    recharge_number VARCHAR(20),
    recharge_amount DECIMAL(12,2),
    service_amount DECIMAL(12,2) NULL,
    request_details TEXT,
    attachment VARCHAR(255),
    preferred_contact ENUM('phone','email','branch') DEFAULT 'phone',
    status ENUM('pending','processing','approved','rejected','completed') DEFAULT 'pending',
    admin_remarks TEXT,
    admin_attachment VARCHAR(500) DEFAULT '' COMMENT 'Admin reply file',
    reviewed_by VARCHAR(100),
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tracking (tracking_id),
    INDEX idx_phone (phone),
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_service_type (service_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 18. USEFUL LINKS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS useful_links;
CREATE TABLE IF NOT EXISTS useful_links (
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 19. IMPORTANT LINKS TABLE (Legacy - use useful_links instead)
-- =====================================================

-- DROP TABLE IF EXISTS important_links;
CREATE TABLE IF NOT EXISTS important_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    title_np VARCHAR(200),
    url VARCHAR(255) NOT NULL,
    icon VARCHAR(255),
    description VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 20. ACTIVITY LOG TABLE
-- =====================================================

-- DROP TABLE IF EXISTS activity_log;
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT,
    action VARCHAR(100),
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin (admin_id),
    INDEX idx_action (action),
    INDEX idx_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 20b. NOTIFICATION LOG — includes/notification-log-tables.php
-- =====================================================

CREATE TABLE IF NOT EXISTS notification_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    event_type  VARCHAR(100) NOT NULL COMMENT 'loan_application, grievance, etc.',
    channel     ENUM('email','sms') NOT NULL,
    recipient   VARCHAR(200) NOT NULL,
    subject     VARCHAR(500),
    message     TEXT,
    status      ENUM('sent','failed') DEFAULT 'sent',
    error_msg   VARCHAR(500),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event_type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Notification send log';

-- =====================================================
-- 21. COMMITTEE TYPES TABLE
-- =====================================================

-- DROP TABLE IF EXISTS committee_types;
CREATE TABLE IF NOT EXISTS committee_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_np VARCHAR(100) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    show_in_navbar TINYINT(1) DEFAULT 0,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_navbar (show_in_navbar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 22. COMMITTEE TENURES TABLE
-- =====================================================

-- DROP TABLE IF EXISTS committee_tenures;
CREATE TABLE IF NOT EXISTS committee_tenures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    committee_type_id INT NOT NULL,
    tenure_name VARCHAR(100) NOT NULL,
    tenure_name_np VARCHAR(100),
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_committee (committee_type_id),
    INDEX idx_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 23. COMMITTEE MEMBERS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS committee_members;
CREATE TABLE IF NOT EXISTS committee_members (
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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tenure (tenure_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 24. KYC APPLICATIONS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS kyc_applications;
CREATE TABLE IF NOT EXISTS kyc_applications (
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
    father_name VARCHAR(100),
    mother_name VARCHAR(100),
    grandfather_name VARCHAR(100),
    spouse_name VARCHAR(100),
    occupation VARCHAR(100),
    organization_name VARCHAR(200),
    monthly_income VARCHAR(50),
    account_type VARCHAR(50),
    branch VARCHAR(100),
    photo VARCHAR(255),
    citizenship_front VARCHAR(255),
    citizenship_back VARCHAR(255),
    signature VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_mobile (mobile),
    INDEX idx_kyc_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 25. LOAN APPLICATIONS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS loan_applications;
CREATE TABLE IF NOT EXISTS loan_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
    other_income TEXT,
    collateral_type VARCHAR(100),
    collateral_description TEXT,
    collateral_value DECIMAL(15,2),
    guarantor_name VARCHAR(100),
    guarantor_relation VARCHAR(50),
    guarantor_phone VARCHAR(20),
    guarantor_address TEXT,
    branch VARCHAR(100),
    documents TEXT,
    status ENUM('pending', 'processing', 'approved', 'rejected', 'disbursed') DEFAULT 'pending',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 26. APPOINTMENTS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS appointments;
-- tracking_id — appointment.php / ensure-tables migration सँग मेल
CREATE TABLE IF NOT EXISTS appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_id VARCHAR(60) UNIQUE NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    member_id VARCHAR(50),
    purpose ENUM('account_inquiry', 'loan_inquiry', 'kyc_update', 'loan_repayment', 'account_opening', 'other') DEFAULT 'other',
    purpose_detail TEXT,
    preferred_date DATE NOT NULL,
    preferred_time VARCHAR(20) NOT NULL,
    branch VARCHAR(100),
    status ENUM('pending', 'confirmed', 'completed', 'cancelled') DEFAULT 'pending',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_date (preferred_date),
    INDEX idx_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 27. AUCTION NOTICES TABLE
-- =====================================================

-- DROP TABLE IF EXISTS auction_notices;
-- Canonical: includes/auction-tables.php (admin/auctions.php)
CREATE TABLE IF NOT EXISTS auction_notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_number VARCHAR(30) UNIQUE,
    title VARCHAR(255) NOT NULL,
    title_en VARCHAR(255),
    description TEXT,
    description_en TEXT,
    property_type VARCHAR(100),
    location VARCHAR(255),
    google_map_link VARCHAR(600),
    google_map_embed TEXT,
    area_bigha DECIMAL(10,2) DEFAULT 0,
    area_ropani DECIMAL(10,2) DEFAULT 0,
    area_aana DECIMAL(10,2) DEFAULT 0,
    area_paisa DECIMAL(10,2) DEFAULT 0,
    area VARCHAR(100),
    minimum_price DECIMAL(15,2) DEFAULT 0,
    auction_date DATE NULL,
    auction_time VARCHAR(30),
    contact_person VARCHAR(120),
    contact_phone VARCHAR(20),
    image VARCHAR(255),
    images TEXT COMMENT 'JSON array of additional images',
    document VARCHAR(255) COMMENT 'PDF/Word document path',
    status ENUM('upcoming','ongoing','completed','cancelled') DEFAULT 'upcoming',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_date (auction_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 28. AUCTION BIDS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS auction_bids;
CREATE TABLE IF NOT EXISTS auction_bids (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auction_id INT NOT NULL,
    bidder_name VARCHAR(120) NOT NULL,
    bidder_phone VARCHAR(20) NOT NULL,
    bidder_email VARCHAR(120),
    bidder_address VARCHAR(255),
    bid_amount DECIMAL(15,2) NOT NULL,
    message TEXT,
    status ENUM('pending','accepted','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auction (auction_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 29. ACCOUNT APPLICATIONS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS account_applications;
CREATE TABLE IF NOT EXISTS account_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
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
    citizenship_issued_date VARCHAR(20),
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
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_mobile (mobile)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 30. GRIEVANCES TABLE
-- =====================================================

-- DROP TABLE IF EXISTS grievances;
CREATE TABLE IF NOT EXISTS grievances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- tracking_id: यो column grievance.php बाट generate हुन्छ (GRV-YYYYMMDD-XXXXXX)
    tracking_id VARCHAR(60) UNIQUE NULL,
    name VARCHAR(100) NOT NULL,
    member_id VARCHAR(50),
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100),
    category ENUM('service', 'staff', 'loan', 'account', 'branch', 'other') DEFAULT 'other',
    subject VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    attachment VARCHAR(255),
    is_anonymous TINYINT(1) DEFAULT 0,
    status ENUM('pending', 'in_progress', 'resolved', 'closed') DEFAULT 'pending',
    admin_response TEXT,
    resolved_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_category (category),
    INDEX idx_tracking (tracking_id),
    INDEX idx_phone (phone),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 31. CHATBOT FAQS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS chatbot_faqs;
CREATE TABLE IF NOT EXISTS chatbot_faqs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question VARCHAR(255) NOT NULL,
    question_en VARCHAR(255),
    answer TEXT NOT NULL,
    answer_en TEXT,
    category VARCHAR(50),
    keywords TEXT,
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 32. FAQS TABLE (Admin manageable FAQs)
-- =====================================================

-- DROP TABLE IF EXISTS faqs;
CREATE TABLE IF NOT EXISTS faqs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question VARCHAR(500) NOT NULL,
    question_np VARCHAR(500),
    answer TEXT NOT NULL,
    answer_np TEXT,
    category VARCHAR(100) DEFAULT 'general',
    is_active TINYINT(1) DEFAULT 1,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 33. AWARDS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS awards;
CREATE TABLE IF NOT EXISTS awards (
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
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 34. HELP TOPICS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS help_topics;
CREATE TABLE IF NOT EXISTS help_topics (
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
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 35. SITE STATS TABLE (For visitor counter)
-- =====================================================

-- DROP TABLE IF EXISTS site_stats;
CREATE TABLE IF NOT EXISTS site_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stat_key VARCHAR(100) NOT NULL UNIQUE,
    stat_value BIGINT DEFAULT 0,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (stat_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize visitor counter
-- =====================================================
-- 36. VISITOR COUNTER TABLE
-- =====================================================

-- DROP TABLE IF EXISTS visitor_counter;
CREATE TABLE IF NOT EXISTS visitor_counter (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    page_visited VARCHAR(255),
    visit_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visit_date (visit_date),
    INDEX idx_ip_date (ip_address, visit_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 37. APP FEATURES TABLE
-- =====================================================

-- DROP TABLE IF EXISTS app_features;
CREATE TABLE IF NOT EXISTS app_features (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    title_np VARCHAR(100),
    icon VARCHAR(100) DEFAULT 'fas fa-star',
    description TEXT,
    description_np TEXT,
    is_new TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =====================================================
-- 38. INSTITUTIONAL PROFILE TABLE
-- =====================================================

-- ─────────────────────────────────────────────────
-- SAFE MIGRATION: run once on existing databases
-- ALTER TABLE institutional_profile ADD UNIQUE KEY uniq_fiscal_year (fiscal_year);
-- ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS institutional_profile (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fiscal_year VARCHAR(20) NOT NULL,
    total_members INT DEFAULT 0,
    share_capital DECIMAL(18,2) DEFAULT 0,
    deposit DECIMAL(18,2) DEFAULT 0,
    loan DECIMAL(18,2) DEFAULT 0,
    total_assets DECIMAL(18,2) DEFAULT 0,
    other_fund DECIMAL(18,2) DEFAULT 0,
    bank_cash_balance DECIMAL(18,2) DEFAULT 0,
    fixed_assets DECIMAL(18,2) DEFAULT 0,
    total_loan_members INT DEFAULT 0,
    npa_percent DECIMAL(5,2) DEFAULT 0,
    profit_loss DECIMAL(18,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_active (is_active),
    INDEX idx_year (fiscal_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade-safe: पुरानो DB मा यदि profit_loss column छैन भने थप्ने
ALTER TABLE institutional_profile ADD COLUMN IF NOT EXISTS profit_loss DECIMAL(18,2) DEFAULT 0 AFTER npa_percent;
ALTER TABLE institutional_profile ADD COLUMN IF NOT EXISTS other_fund DECIMAL(18,2) DEFAULT 0 AFTER total_assets;
ALTER TABLE institutional_profile ADD COLUMN IF NOT EXISTS bank_cash_balance DECIMAL(18,2) DEFAULT 0 AFTER other_fund;
ALTER TABLE institutional_profile ADD COLUMN IF NOT EXISTS fixed_assets DECIMAL(18,2) DEFAULT 0 AFTER bank_cash_balance;
ALTER TABLE institutional_profile ADD COLUMN IF NOT EXISTS total_loan_members INT DEFAULT 0 AFTER loan;
-- =====================================================
-- 39. JOB APPLICATIONS TABLE
-- =====================================================

-- DROP TABLE IF EXISTS job_applications;
-- Canonical: includes/ensure-tables.php + career-detail.php
CREATE TABLE IF NOT EXISTS job_applications (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Re-enable foreign key checks

-- =====================================================
-- MEMBER WELFARE CLAIMS TABLE — कल्याण दाबी
-- =====================================================
-- DROP TABLE IF EXISTS member_welfare_claims;
CREATE TABLE IF NOT EXISTS member_welfare_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_id VARCHAR(60) UNIQUE NULL,
    member_name VARCHAR(120) NOT NULL,
    member_id VARCHAR(50) DEFAULT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(120) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    claim_type VARCHAR(60) NOT NULL,
    claim_amount DECIMAL(12,2) DEFAULT 0.00,
    description TEXT,
    claim_date_bs VARCHAR(20) DEFAULT NULL,
    claim_date_ad DATE DEFAULT NULL,
    status ENUM('pending','processing','approved','rejected') DEFAULT 'pending',
    approved_amount DECIMAL(12,2) DEFAULT NULL,
    admin_remarks TEXT,
    attachment_path VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tracking (tracking_id),
    INDEX idx_phone (phone),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- MEMBER SURVEY TABLE — सदस्य सर्वेक्षण
-- =====================================================
-- DROP TABLE IF EXISTS member_survey;
CREATE TABLE IF NOT EXISTS member_survey (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tracking_id VARCHAR(50) UNIQUE NOT NULL,
    member_name VARCHAR(120) NOT NULL,
    member_id VARCHAR(50),
    phone VARCHAR(20),
    email VARCHAR(120),
    satisfaction_level TINYINT DEFAULT 3 COMMENT '1-5 scale',
    service_quality TINYINT DEFAULT 3,
    staff_behavior TINYINT DEFAULT 3,
    response_time TINYINT DEFAULT 3,
    suggestions TEXT,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- सबै columns CREATE TABLE मा नै समावेश छन् — ALTER TABLE आवश्यक छैन
-- =====================================================


-- ═══════════════════════════════════════════════════════════════
-- 📦  CONSOLIDATED MIGRATIONS (v2 + v3 + v4 + v6 + member-portal-v2)
-- ═══════════════════════════════════════════════════════════════
-- सबै migrations यहीँ एकै ठाउँमा छन् — IF NOT EXISTS pattern ले safe छ।
-- फेरि-फेरि run गर्दा कुनै data हानी हुदैन।

-- ─────────────────────────────────────────────────────────────
-- 📊 v2 — Performance & Scalability (indexes + schema_migrations)
-- ─────────────────────────────────────────────────────────────
-- ════════════════════════════════════════════════════════════════
-- AAKASH SAHAKARI — v2 Performance & Scalability Migration
-- ════════════════════════════════════════════════════════════════
-- यो file admin/db-setup.php → Migration Runner बाट run गर्नुहोस्।
-- 5,000+ members हुँदा admin listing/search slow हुने रोक्न
-- composite indexes थपिएको छ।
--
-- सबै ALTER statements safe छन् — index पहिले नै भए skip हुन्छ
-- (procedure मा wrap गरिएको छ)।
-- ════════════════════════════════════════════════════════════════

DELIMITER $$

DROP PROCEDURE IF EXISTS add_index_if_missing $$
CREATE PROCEDURE add_index_if_missing(
    IN p_table   VARCHAR(64),
    IN p_index   VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    DECLARE idx_exists   INT DEFAULT 0;
    DECLARE table_exists INT DEFAULT 0;
    DECLARE missing_cols INT DEFAULT 0;
    DECLARE first_col    VARCHAR(64);
    DECLARE col_list     VARCHAR(255);

    -- Table नै नभए silently skip (fresh install ma kunai table runtime बाट आउँछ)
    SELECT COUNT(*) INTO table_exists
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;
    IF table_exists = 0 THEN
        -- Skip silently
        SELECT 1; -- no-op result set; caller (PHP) ले consume गर्नुपर्छ
    ELSE
        -- v10.3 FIX: हरेक column को existence check गर्नुहोस्। एउटा पनि column missing भए skip।
        SET col_list = REPLACE(REPLACE(p_columns, ' ', ''), '`', '');
        SELECT COUNT(*) INTO missing_cols
          FROM (
              SELECT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(col_list, ',', n.n), ',', -1)) AS col
                FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) n
               WHERE n.n <= 1 + LENGTH(col_list) - LENGTH(REPLACE(col_list, ',', ''))
          ) AS req
         WHERE NOT EXISTS (
              SELECT 1 FROM information_schema.COLUMNS c
               WHERE c.TABLE_SCHEMA = DATABASE()
                 AND c.TABLE_NAME = p_table
                 AND c.COLUMN_NAME = req.col
         );

        IF missing_cols = 0 THEN
            SELECT COUNT(*) INTO idx_exists
              FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = p_table
               AND INDEX_NAME = p_index;
            IF idx_exists = 0 THEN
                SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_columns, ')');
                PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
            END IF;
        END IF;
    END IF;
END $$

DROP PROCEDURE IF EXISTS table_exists $$
CREATE PROCEDURE table_exists(IN p_table VARCHAR(64), OUT p_exists INT)
BEGIN
    SELECT COUNT(*) INTO p_exists
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;
END $$

DELIMITER ;

-- ── Members: admin listing speed (status + date) ──
-- NOTE: members table includes/member-auth.php मा runtime create हुन्छ। यदि अहिले छैन भने skip हुन्छ।
CALL add_index_if_missing('members', 'idx_approval_created', 'approval_status, created_at');
CALL add_index_if_missing('members', 'idx_phone',            'phone');
CALL add_index_if_missing('members', 'idx_active_verified',  'is_active, is_verified');
CALL add_index_if_missing('members', 'idx_sadasyata',        'sadasyata_number');

-- ── Loan applications: admin filter ──
-- v10.3 FIX: column 'phone' छैन — actual column 'mobile' हो
CALL add_index_if_missing('loan_applications', 'idx_status_created', 'status, created_at');
CALL add_index_if_missing('loan_applications', 'idx_mobile',         'mobile');
CALL add_index_if_missing('loan_applications', 'idx_member',         'member_id');

-- ── KYC applications ──
-- v10.3 FIX: column 'phone' छैन — actual column 'mobile' हो
CALL add_index_if_missing('kyc_applications', 'idx_status_created', 'status, created_at');
CALL add_index_if_missing('kyc_applications', 'idx_mobile',         'mobile');

-- ── Account applications ──
-- v10.3 FIX: column 'phone' छैन — actual column 'mobile' हो
CALL add_index_if_missing('account_applications', 'idx_status_created', 'status, created_at');
CALL add_index_if_missing('account_applications', 'idx_mobile',         'mobile');

-- ── Appointments ──
-- v10.3 FIX: column 'appointment_date' छैन — actual column 'preferred_date' हो
CALL add_index_if_missing('appointments', 'idx_status_date', 'status, preferred_date');
CALL add_index_if_missing('appointments', 'idx_phone',       'phone');

-- ── Grievances ──
CALL add_index_if_missing('grievances', 'idx_status_created', 'status, created_at');

-- ── Job applications ──
CALL add_index_if_missing('job_applications', 'idx_status_created', 'status, created_at');
CALL add_index_if_missing('job_applications', 'idx_career',         'career_id');

-- ── Activity log: cleanup query speed ──
CALL add_index_if_missing('activity_log', 'idx_created', 'created_at');
CALL add_index_if_missing('activity_log', 'idx_admin',   'admin_id');

-- ── Member notifications ──
CALL add_index_if_missing('member_notifications', 'idx_member_read', 'member_id, is_read');
CALL add_index_if_missing('member_notifications', 'idx_created',     'created_at');

-- ── Contact messages ──
CALL add_index_if_missing('contact_messages', 'idx_read_created', 'is_read, created_at');

-- ── News & Notices: public listing ──
CALL add_index_if_missing('news',    'idx_active_date', 'is_active, created_at');
CALL add_index_if_missing('notices', 'idx_active_date', 'is_active, notice_date');

-- ── Login attempts: brute-force lookup speed ──
CALL add_index_if_missing('login_attempts', 'idx_lookup',    'username, ip_address, attempted_at');
CALL add_index_if_missing('login_attempts', 'idx_attempted', 'attempted_at');

-- ── Member OTP tokens: cleanup speed ──
CALL add_index_if_missing('member_otp_tokens', 'idx_expires', 'expires_at');

-- Cleanup
DROP PROCEDURE IF EXISTS add_index_if_missing;
DROP PROCEDURE IF EXISTS table_exists;

-- ════════════════════════════════════════════════════════════════
-- Schema version tracking (अबदेखि सबै schema changes track गर्न)
-- ════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS schema_migrations (
    version     VARCHAR(50) PRIMARY KEY,
    applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    description VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- ─────────────────────────────────────────────────────────────
-- 📋 v3 — Audit log + Soft delete + Composite indexes
-- ─────────────────────────────────────────────────────────────
-- ═══════════════════════════════════════════════════════════════════════
-- v3 ADVANCED MIGRATION — Aakash Sahakari Portal
-- ═══════════════════════════════════════════════════════════════════════
-- के थप्छ?
--   1. Performance indexes (composite, FK lookup, status filter, search)
--   2. audit_log table — हरेक admin action को permanent record
--   3. soft-delete (deleted_at) — सबै main tables मा accidental delete recovery
--   4. Foreign-key style integrity (logical FK indexes — strict FK नथपिएको
--      किनकि MyISAM legacy + cross-engine compat को लागि)
--
-- Idempotent: यो file जति पटक चलाए पनि safe छ
-- (हरेक statement पहिले existence check गर्छ)।
--
-- कसरी चलाउने?
--   Admin → DB Setup → "v3 Advanced Migration चलाउनुहोस्" बटन
--   (वा cPanel → phpMyAdmin → SQL tab मा paste गरेर run)
-- ═══════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────
-- SECTION 1: AUDIT LOG TABLE
-- हरेक admin action (login, edit, delete, approve, reject) को record
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type    ENUM('admin','superadmin','member','system','public') NOT NULL DEFAULT 'system',
    actor_id      INT UNSIGNED NULL,
    actor_name    VARCHAR(150) NULL,
    action        VARCHAR(64) NOT NULL,           -- 'login','logout','create','update','delete','approve','reject','status_change'
    entity_type   VARCHAR(64) NOT NULL,           -- 'member','loan_application','grievance','news', etc.
    entity_id     BIGINT UNSIGNED NULL,
    summary       VARCHAR(255) NULL,              -- short human-readable description
    old_values    JSON NULL,                      -- previous state (for updates/deletes)
    new_values    JSON NULL,                      -- new state
    ip_address    VARCHAR(45) NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_actor       (actor_type, actor_id),
    INDEX idx_entity      (entity_type, entity_id),
    INDEX idx_action_date (action, created_at),
    INDEX idx_created     (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─────────────────────────────────────────────────────────────────────
-- SECTION 2: SOFT-DELETE — deleted_at column
-- सबै main tables मा थप्छ। NULL = active, NOT NULL = deleted timestamp
-- ─────────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS sp_v3_add_column;
DELIMITER $$
CREATE PROCEDURE sp_v3_add_column(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_def    TEXT
)
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_table_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO v_table_exists
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;
    IF v_table_exists = 0 THEN
        SELECT CONCAT('SKIP: table missing — ', p_table) AS info;
    ELSE
        SELECT COUNT(*) INTO v_count
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column;
        IF v_count = 0 THEN
            SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
            PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
            SELECT CONCAT('OK: added ', p_table, '.', p_column) AS info;
        END IF;
    END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS sp_v3_add_index;
DELIMITER $$
CREATE PROCEDURE sp_v3_add_index(
    IN p_table  VARCHAR(64),
    IN p_index  VARCHAR(64),
    IN p_cols   VARCHAR(255)
)
BEGIN
    DECLARE v_count        INT DEFAULT 0;
    DECLARE v_table_exists INT DEFAULT 0;
    DECLARE v_missing_cols INT DEFAULT 0;
    DECLARE v_col_list     VARCHAR(255);

    SELECT COUNT(*) INTO v_table_exists
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table;
    IF v_table_exists > 0 THEN
        -- v10.3 FIX: column existence check (legacy schema mismatch बाट बच्न)
        SET v_col_list = REPLACE(REPLACE(p_cols, ' ', ''), '`', '');
        SELECT COUNT(*) INTO v_missing_cols
          FROM (
              SELECT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(v_col_list, ',', n.n), ',', -1)) AS col
                FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) n
               WHERE n.n <= 1 + LENGTH(v_col_list) - LENGTH(REPLACE(v_col_list, ',', ''))
          ) AS req
         WHERE NOT EXISTS (
              SELECT 1 FROM information_schema.COLUMNS c
               WHERE c.TABLE_SCHEMA = DATABASE()
                 AND c.TABLE_NAME = p_table
                 AND c.COLUMN_NAME = req.col
         );

        IF v_missing_cols = 0 THEN
            SELECT COUNT(*) INTO v_count
              FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index;
            IF v_count = 0 THEN
                SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` (', p_cols, ')');
                PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
            END IF;
        END IF;
    END IF;
END$$
DELIMITER ;

-- ─── Soft-delete columns ───
CALL sp_v3_add_column('members',              'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('admin_users',          'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('admin_users',          'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 0');
CALL sp_v3_add_column('loan_applications',    'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('account_applications', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('kyc_applications',     'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('appointments',         'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('grievances',           'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('contact_messages',     'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('news',                 'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('notices',              'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('downloads',            'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('gallery',              'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('careers',              'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL sp_v3_add_column('job_applications',     'deleted_at', 'DATETIME NULL DEFAULT NULL');

-- ─── Soft-delete indexes (active row queries fast हुन्) ───
CALL sp_v3_add_index('members',              'idx_deleted_at', 'deleted_at');
CALL sp_v3_add_index('loan_applications',    'idx_deleted_at', 'deleted_at');
CALL sp_v3_add_index('account_applications', 'idx_deleted_at', 'deleted_at');
CALL sp_v3_add_index('kyc_applications',     'idx_deleted_at', 'deleted_at');
CALL sp_v3_add_index('grievances',           'idx_deleted_at', 'deleted_at');
CALL sp_v3_add_index('contact_messages',     'idx_deleted_at', 'deleted_at');
CALL sp_v3_add_index('appointments',         'idx_deleted_at', 'deleted_at');


-- ─────────────────────────────────────────────────────────────────────
-- SECTION 3: PERFORMANCE INDEXES
-- सबैभन्दा बढी use हुने query patterns को लागि
-- ─────────────────────────────────────────────────────────────────────

-- ─── members table ───
CALL sp_v3_add_index('members', 'idx_email',           'email');
CALL sp_v3_add_index('members', 'idx_phone',           'phone');
CALL sp_v3_add_index('members', 'idx_sadasyata',       'sadasyata_number');
CALL sp_v3_add_index('members', 'idx_approval_active', 'approval_status, is_active');
CALL sp_v3_add_index('members', 'idx_created',         'created_at');

-- ─── loan_applications ───
CALL sp_v3_add_index('loan_applications', 'idx_tracking',     'tracking_id');
CALL sp_v3_add_index('loan_applications', 'idx_status_date',  'status, created_at');
CALL sp_v3_add_index('loan_applications', 'idx_email',        'email');
CALL sp_v3_add_index('loan_applications', 'idx_mobile',       'mobile');

-- ─── account_applications ───
CALL sp_v3_add_index('account_applications', 'idx_tracking',    'tracking_id');
CALL sp_v3_add_index('account_applications', 'idx_status_date', 'status, created_at');

-- ─── kyc_applications ───
CALL sp_v3_add_index('kyc_applications', 'idx_tracking',    'tracking_id');
CALL sp_v3_add_index('kyc_applications', 'idx_status_date', 'status, created_at');

-- ─── appointments ───
-- v10.3 FIX: column 'appointment_date' छैन — actual column 'preferred_date' हो
CALL sp_v3_add_index('appointments', 'idx_tracking',      'tracking_id');
CALL sp_v3_add_index('appointments', 'idx_status_date',   'status, preferred_date');

-- ─── grievances ───
CALL sp_v3_add_index('grievances', 'idx_tracking',     'tracking_id');
CALL sp_v3_add_index('grievances', 'idx_status_date',  'status, created_at');

-- ─── contact_messages ───
CALL sp_v3_add_index('contact_messages', 'idx_status_date', 'is_read, created_at');

-- ─── news / notices (public listing) ───
CALL sp_v3_add_index('news',    'idx_published_date', 'is_published, published_at');
CALL sp_v3_add_index('notices', 'idx_published_date', 'is_published, published_at');

-- ─── activity_log (already exists, ensure indexes) ───
CALL sp_v3_add_index('activity_log',   'idx_created',  'created_at');
CALL sp_v3_add_index('activity_log',   'idx_user',     'user_id');

-- ─── notification_log ───
CALL sp_v3_add_index('notification_log', 'idx_status_date', 'status, created_at');
CALL sp_v3_add_index('notification_log', 'idx_event_type',  'event_type, channel');

-- ─── member_notifications ───
CALL sp_v3_add_index('member_notifications', 'idx_member_unread', 'member_id, is_read, created_at');

-- ─── login_attempts (cleanup performance) ───
CALL sp_v3_add_index('login_attempts',  'idx_email_date', 'email, created_at');
CALL sp_v3_add_index('login_attempts',  'idx_ip_date',    'ip_address, created_at');


-- ─────────────────────────────────────────────────────────────────────
-- SECTION 4: CLEANUP — temporary procedures हटाउने
-- ─────────────────────────────────────────────────────────────────────
DROP PROCEDURE IF EXISTS sp_v3_add_column;
DROP PROCEDURE IF EXISTS sp_v3_add_index;

-- ═══════════════════════════════════════════════════════════════════════
-- v3 Migration complete!
-- अब admin panel ले audit_log मा हरेक action record गर्छ,
-- soft-delete ले accidental data loss बाट जोगाउँछ,
-- र indexes ले large data मा पनि fast queries दिन्छ।
-- ═══════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────
-- ✉️  v4 — Notification Templates + Widget support
-- ─────────────────────────────────────────────────────────────
-- ═══════════════════════════════════════════════════════════════════════
-- v4 MIGRATION — Notification Template Manager + Widget Support
-- ═══════════════════════════════════════════════════════════════════════
-- के थप्छ?
--   1. notification_templates — admin बाट edit मिल्ने subject + body
--      (हरेक event/audience/channel combo को छुट्टै template)
--   2. Default templates seed (existing 8 events × 2 audiences × 2 channels)
--   3. Idempotent — जति पटक चलाए पनि safe
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS notification_templates (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type    VARCHAR(64)  NOT NULL,         -- loan_application, grievance, etc.
    audience      ENUM('admin','member') NOT NULL DEFAULT 'admin',
    channel       ENUM('email','sms')    NOT NULL,
    enabled       TINYINT(1) NOT NULL DEFAULT 1,
    subject       VARCHAR(255) NULL,             -- email मात्र (SMS मा NULL)
    body          TEXT NOT NULL,                 -- placeholders: {name},{tracking_id},{status},{remarks},{site_name}
    variables     VARCHAR(255) NULL,             -- comma list of supported placeholders (UI hint)
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_event_audience_channel (event_type, audience, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Seed default templates (idempotent — INSERT IGNORE) ───
-- Admin templates (8 events × email)
-- Admin SMS templates
-- Member status-update templates (status + remarks)
-- Member SMS templates
-- ═══════════════════════════════════════════════════════════════════════
-- v4 Migration complete!
-- अब admin → Notification Templates पेज बाट हरेक event को subject/body
-- live edit गर्न सकिन्छ। Per-channel toggle + preview/test send पनि।
-- ═══════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────
-- 👥 Member Portal v2 — Sadasyata + Approval flow + ID card
-- ─────────────────────────────────────────────────────────────
-- ============================================================
-- Member Portal v2 — Database Migration
-- आकाश सहकारी — Member Portal सुधार
-- ============================================================
-- यो file run गर्नुस् भने नयाँ columns र tables create हुन्छन्।
-- PHP code ले पनि automatically migrate गर्छ तर manually
-- पनि run गर्न सकिन्छ।
-- ============================================================

-- Members table मा नयाँ columns थप्ने
ALTER TABLE members ADD COLUMN IF NOT EXISTS sadasyata_number VARCHAR(50) NOT NULL DEFAULT '';
ALTER TABLE members ADD COLUMN IF NOT EXISTS approval_status VARCHAR(20) DEFAULT 'pending';
ALTER TABLE members ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE members ADD COLUMN IF NOT EXISTS approved_by INT NULL DEFAULT NULL;
ALTER TABLE members ADD COLUMN IF NOT EXISTS rejection_reason TEXT;
ALTER TABLE members ADD COLUMN IF NOT EXISTS id_card_generated TINYINT DEFAULT 0;
ALTER TABLE members ADD COLUMN IF NOT EXISTS id_card_generated_at TIMESTAMP NULL DEFAULT NULL;

-- Existing approved members लाई approved status दिने
UPDATE members SET approval_status = 'approved' WHERE approval_status IS NULL OR approval_status = '';
UPDATE members SET approval_status = 'approved' WHERE google_id IS NOT NULL AND google_id != '';
UPDATE members SET approval_status = 'approved' WHERE facebook_id IS NOT NULL AND facebook_id != '';

-- Password Reset Requests table
CREATE TABLE IF NOT EXISTS member_password_reset_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    member_id       INT NOT NULL,
    status          VARCHAR(20) DEFAULT 'pending',
    requested_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    admin_id        INT NULL DEFAULT NULL,
    resolved_at     TIMESTAMP NULL DEFAULT NULL,
    temp_password   VARCHAR(255) NULL DEFAULT NULL,
    admin_note      TEXT,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- नोट: MySQL 5.x मा ADD COLUMN IF NOT EXISTS support छैन।
-- त्यस्तो case मा PHP code ले try-catch गरेर automatically
-- handle गर्नेछ। सीधै PHP code बाट access गर्नुस्।
-- ============================================================

-- ─────────────────────────────────────────────────────────────
-- 🔐 v6 — Role hierarchy (superadmin/admin/staff) + Office Credentials Vault
-- ─────────────────────────────────────────────────────────────
-- ═══════════════════════════════════════════════════════════════
-- 🔐 v6 Migration: Role Management + Smart Credential Manager
-- ═══════════════════════════════════════════════════════════════
-- तपाईंको existing admin_users table सँग compatible।
-- सुरक्षित — IF NOT EXISTS / IGNORE patterns प्रयोग गरिएको।
-- 
-- Run गर्ने तरिका:
--   1. cPanel → phpMyAdmin → आफ्नो DB छान्नुहोस्
--   2. SQL tab → यो पूरै file paste → Go
--   3. वा admin/run-migration.php मा यो file को path राखेर चलाउनुहोस्
-- ═══════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────
-- 1. admin_users मा थप columns (role पहिले नै छ — hierarchy clarify)
-- ─────────────────────────────────────────
-- Roles: 'superadmin' > 'admin' > 'staff'
-- (existing rows मा role = 'admin' default राखिएको छ — backward compatible)

-- status column (active/disabled) — गलत प्रयोग रोक्न
ALTER TABLE admin_users
    ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active' AFTER is_active;

-- created_by — को-को ले बनायो track गर्ने
ALTER TABLE admin_users
    ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER created_at;

-- last_password_change — security audit
ALTER TABLE admin_users
    ADD COLUMN IF NOT EXISTS last_password_change TIMESTAMP NULL AFTER last_login;

-- ─────────────────────────────────────────
-- 2. office_credentials — Smart Credential Manager
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS office_credentials (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    site_name       VARCHAR(120) NOT NULL,
    site_url        VARCHAR(500) NOT NULL,
    site_logo       VARCHAR(255) DEFAULT NULL,
    username        VARCHAR(255) NOT NULL,
    password_enc    TEXT NOT NULL,            -- AES-256-CBC encrypted
    password_iv     VARCHAR(64) NOT NULL,     -- per-row IV (hex)
    category        VARCHAR(60) DEFAULT 'general',
    notes           TEXT DEFAULT NULL,
    is_active       TINYINT(1) DEFAULT 1,
    sort_order      INT DEFAULT 0,
    created_by      INT NOT NULL,
    updated_by      INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- 3. office_credentials_log — audit trail
-- ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS office_credentials_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    credential_id   INT NOT NULL,
    admin_id        INT NOT NULL,
    admin_username  VARCHAR(50),
    action          VARCHAR(30) NOT NULL,     -- view, copy_user, copy_pass, open, edit, delete
    ip_address      VARCHAR(45),
    user_agent      VARCHAR(255),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cred (credential_id),
    INDEX idx_admin (admin_id),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────
-- 4. existing admin_users मा superadmin promote
--    (एउटा admin पहिले नै exist गर्छ भने त्यसलाई superadmin बनाउने)
-- ─────────────────────────────────────────
UPDATE admin_users
SET role = 'superadmin'
WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM admin_users WHERE is_active = 1) AS t);



-- ════════════════════════════════════════════════════════════
-- ✦ v10.0 + v10.2 — KYC link, Member ID Cards, Verify Code/CVV
-- ════════════════════════════════════════════════════════════
-- ============================================================
-- AAKASH SAHAKARI — v10.0 Database Migration
-- Run ONCE on production database before deploying patch.
-- Safe to re-run: all statements use IF NOT EXISTS.
-- ============================================================

-- 1. Link KYC application to generated member
ALTER TABLE kyc_applications
  ADD COLUMN IF NOT EXISTS member_id_generated VARCHAR(20) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS member_generated_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS member_generated_by INT NULL,
  ADD INDEX IF NOT EXISTS idx_kyc_member (member_id_generated);

-- 2. Members table — link back to source KYC
ALTER TABLE members
  ADD COLUMN IF NOT EXISTS kyc_id INT NULL AFTER approval_status,
  ADD COLUMN IF NOT EXISTS created_by INT NULL,
  ADD INDEX IF NOT EXISTS idx_members_kyc (kyc_id);

-- 3. Member ID Cards table (if missing)
CREATE TABLE IF NOT EXISTS member_id_cards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id VARCHAR(20) NOT NULL,
  card_no   VARCHAR(40) NOT NULL UNIQUE,
  issued_date DATE NOT NULL,
  expiry_date DATE NULL,
  status ENUM('active','expired','revoked') DEFAULT 'active',
  created_by INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_card_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Admin activity log (if missing)
CREATE TABLE IF NOT EXISTS admin_activity_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  action VARCHAR(64) NOT NULL,
  target_type VARCHAR(32) NULL,
  target_id INT NULL,
  details JSON NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_admin (admin_id, created_at),
  INDEX idx_activity_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════
-- v10.2 — Member ID Card Verification (Unique Code + CVV)
-- ────────────────────────────────────────────────────────────
-- के थप्छ?
--   * member_id_cards.verification_code  (12-char unique, e.g. "AKS-9F7K-2X4M")
--   * member_id_cards.cvv               (4-digit numeric, e.g. "8431")
--   * member_id_cards.verify_count      (कति पटक verify भयो — analytics)
--   * member_id_cards.last_verified_at  (अन्तिम verify time)
--
-- सुरक्षा कुरा:
--   - cvv लाई card मा print/show गर्नु पर्छ (CVV जस्तै gopya code)
--   - public verify page ले code + cvv दुबै match भएमा मात्र member detail देखाउँछ
--   - 5 wrong attempts/IP/hour rate limit (verify_attempts table)
-- ════════════════════════════════════════════════════════════

ALTER TABLE member_id_cards
  ADD COLUMN IF NOT EXISTS verification_code VARCHAR(20) NULL UNIQUE AFTER card_no,
  ADD COLUMN IF NOT EXISTS cvv               CHAR(4)     NULL        AFTER verification_code,
  ADD COLUMN IF NOT EXISTS verify_count      INT         DEFAULT 0   AFTER status,
  ADD COLUMN IF NOT EXISTS last_verified_at  DATETIME    NULL        AFTER verify_count;

CREATE INDEX IF NOT EXISTS idx_card_verify ON member_id_cards (verification_code);

-- Rate limit table — IP बाट कति wrong attempts भए track गर्न
CREATE TABLE IF NOT EXISTS card_verify_attempts (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  ip          VARCHAR(45) NOT NULL,
  code_tried  VARCHAR(20) NULL,
  success     TINYINT(1)  NOT NULL DEFAULT 0,
  created_at  DATETIME    DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_attempt_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ⚠️ Migration पछि: scripts/backfill-card-codes.php run गर्नुहोस् —
-- त्यसले पहिले देखि बनेका सबै active cards लाई verification_code + cvv assign गर्छ।

-- ── v10.7 listing indexes (idempotent; पहिले अलग .sql मा थियो) ──
CREATE INDEX IF NOT EXISTS idx_grievances_status_created ON grievances (status, created_at);
CREATE INDEX IF NOT EXISTS idx_grievances_phone_created ON grievances (phone, created_at);
CREATE INDEX IF NOT EXISTS idx_grievances_email_created ON grievances (email, created_at);
CREATE INDEX IF NOT EXISTS idx_loan_mobile_created ON loan_applications (mobile, created_at);
CREATE INDEX IF NOT EXISTS idx_loan_email_created ON loan_applications (email, created_at);
CREATE INDEX IF NOT EXISTS idx_account_mobile_created ON account_applications (mobile, created_at);
CREATE INDEX IF NOT EXISTS idx_account_email_created ON account_applications (email, created_at);
CREATE INDEX IF NOT EXISTS idx_feedback_phone_created ON member_feedback (phone, created_at);
CREATE INDEX IF NOT EXISTS idx_feedback_email_created ON member_feedback (email, created_at);
CREATE INDEX IF NOT EXISTS idx_welfare_phone_created ON member_welfare_claims (phone, created_at);
CREATE INDEX IF NOT EXISTS idx_welfare_email_created ON member_welfare_claims (email, created_at);
CREATE INDEX IF NOT EXISTS idx_dsr_phone_created ON digital_service_requests (phone, created_at);
CREATE INDEX IF NOT EXISTS idx_dsr_email_created ON digital_service_requests (email, created_at);
CREATE INDEX IF NOT EXISTS idx_vendors_status_created ON vendors (status, created_at);
CREATE INDEX IF NOT EXISTS idx_news_created ON news (created_at);

-- ✅ install.sql v10.3 — UNIFIED FILE (no separate migrations needed)
UPDATE admin_users SET must_change_password = 1 WHERE username = 'admin' LIMIT 1;

-- ═══════════════════════════════════════════════════════════════

-- =====================================================
-- UPCOMING PROGRAMS + ATTENDANCE TABLES
-- =====================================================
CREATE TABLE IF NOT EXISTS upcoming_programs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    event_date DATE NULL,
    event_time VARCHAR(30) NULL,
    location VARCHAR(180) NULL,
    is_active TINYINT(1) DEFAULT 1,
    pre_registration_open TINYINT(1) DEFAULT 0,
    qr_token VARCHAR(64) UNIQUE NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_up_date (event_date),
    INDEX idx_up_active (is_active),
    INDEX idx_up_prereg (pre_registration_open),
    INDEX idx_up_qr (qr_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_program_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    member_card_no VARCHAR(60) DEFAULT '',
    program_id INT NOT NULL,
    program_title VARCHAR(180) NOT NULL,
    is_priority TINYINT(1) DEFAULT 0,
    attendance_note VARCHAR(500) DEFAULT '',
    verified_by_ip VARCHAR(45) DEFAULT '',
    source VARCHAR(30) DEFAULT 'verify_portal',
    attended_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_member_program (member_id, program_id),
    INDEX idx_mpa_member (member_id),
    INDEX idx_mpa_program (program_id),
    INDEX idx_mpa_date (attended_at),
    INDEX idx_mpa_prog_att (program_id, attended_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_program_attendance_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    member_card_no VARCHAR(60) DEFAULT '',
    member_name VARCHAR(150) DEFAULT '',
    program_id INT NOT NULL,
    program_title VARCHAR(180) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    verified_by_ip VARCHAR(45) DEFAULT '',
    admin_id INT NULL,
    admin_note VARCHAR(500) DEFAULT '',
    source VARCHAR(40) DEFAULT 'public_qr_request',
    INDEX idx_mpar_status (status),
    INDEX idx_mpar_program (program_id),
    INDEX idx_mpar_member (member_id),
    INDEX idx_mpar_status_prog (status, program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS member_program_preregistrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL,
    member_card_no VARCHAR(60) DEFAULT '',
    member_name VARCHAR(150) DEFAULT '',
    phone VARCHAR(30) DEFAULT '',
    email VARCHAR(120) DEFAULT '',
    program_id INT NOT NULL,
    program_title VARCHAR(180) NOT NULL,
    event_date DATE NULL,
    note VARCHAR(500) DEFAULT '',
    source VARCHAR(30) DEFAULT 'member_portal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_member_program_prereg (member_id, program_id),
    INDEX idx_mppr_member (member_id),
    INDEX idx_mppr_program (program_id),
    INDEX idx_mppr_date (created_at),
    INDEX idx_mppr_prog_created (program_id, created_at),
    INDEX idx_pr_member (member_id),
    INDEX idx_pr_program (program_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- member_partner_services — includes/member-partner-services-tables.php (verify.php)
CREATE TABLE IF NOT EXISTS member_partner_services (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    member_id       INT NOT NULL,
    member_card_no  VARCHAR(50) DEFAULT '',
    partner_id      INT NULL,
    partner_name    VARCHAR(255) NOT NULL,
    service_name    VARCHAR(255) DEFAULT '',
    service_taken   TINYINT(1) DEFAULT 0,
    service_note    VARCHAR(500) DEFAULT '',
    verified_by_ip  VARCHAR(45) DEFAULT '',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mps_member  (member_id),
    INDEX idx_mps_partner (partner_id),
    INDEX idx_mps_date    (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS election_cycles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title_np VARCHAR(200) NOT NULL,
    title_en VARCHAR(200) NOT NULL DEFAULT '',
    intro_np TEXT NULL,
    intro_en TEXT NULL,
    period_label VARCHAR(80) NULL DEFAULT NULL,
    date_from DATE NULL DEFAULT NULL,
    date_to DATE NULL DEFAULT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    show_in_navbar TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ec_pub_nav (is_published, show_in_navbar),
    INDEX idx_ec_sort (sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS election_milestones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NOT NULL,
    event_date DATE NULL DEFAULT NULL,
    title_np VARCHAR(220) NOT NULL,
    title_en VARCHAR(220) NOT NULL DEFAULT '',
    detail_np TEXT NULL,
    detail_en TEXT NULL,
    attachment VARCHAR(500) NULL DEFAULT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_em_cycle (cycle_id),
    INDEX idx_em_cycle_ord (cycle_id, display_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- HRM MODULE (full schema — merged from hrm_install.sql)
-- सहकारी मानव संशाधन व्यवस्थापन
-- =====================================================
SET FOREIGN_KEY_CHECKS = 0;

-- 1) DEPARTMENTS / BRANCHES (विभाग/शाखा)
CREATE TABLE IF NOT EXISTS hrm_departments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) EMPLOYEE MASTER (कर्मचारी मास्टर)
CREATE TABLE IF NOT EXISTS hrm_employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(40) NOT NULL UNIQUE,
    admin_user_id INT DEFAULT NULL,                 -- link to admin_users (optional login)

    -- Personal
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

    -- Identity
    citizenship_no VARCHAR(60) DEFAULT NULL,
    citizenship_issued_district VARCHAR(80) DEFAULT NULL,
    citizenship_issued_date_bs VARCHAR(20) DEFAULT NULL,
    pan_no VARCHAR(40) DEFAULT NULL,
    nid_no VARCHAR(40) DEFAULT NULL,
    passport_no VARCHAR(40) DEFAULT NULL,
    driving_license_no VARCHAR(40) DEFAULT NULL,

    -- Contact
    mobile VARCHAR(20) DEFAULT NULL,
    alt_mobile VARCHAR(20) DEFAULT NULL,
    email VARCHAR(120) DEFAULT NULL,

    -- Address
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

    -- Employment
    designation VARCHAR(160) DEFAULT NULL,        -- joins designations master (title_np)
    department_id INT DEFAULT NULL,
    branch_id INT DEFAULT NULL,                   -- service_centers.id (optional)
    employment_type ENUM('permanent','contract','probation','temporary','intern','consultant') DEFAULT 'permanent',
    grade VARCHAR(40) DEFAULT NULL,
    level VARCHAR(40) DEFAULT NULL,
    reporting_to INT DEFAULT NULL,                -- another employee id
    join_date_bs VARCHAR(20) DEFAULT NULL,
    join_date_ad DATE DEFAULT NULL,
    confirm_date_bs VARCHAR(20) DEFAULT NULL,
    confirm_date_ad DATE DEFAULT NULL,
    probation_months INT DEFAULT 0,
    status ENUM('active','probation','suspended','on_leave','resigned','terminated','retired') DEFAULT 'active',
    exit_date_ad DATE DEFAULT NULL,
    exit_reason TEXT DEFAULT NULL,

    -- Misc
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) CONTRACTS (नियुक्ति/करार पत्र)
CREATE TABLE IF NOT EXISTS hrm_employee_contracts (
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
    end_date_ad DATE DEFAULT NULL,                -- NULL = permanent
    basic_salary DECIMAL(12,2) DEFAULT 0,
    allowance DECIMAL(12,2) DEFAULT 0,
    notes TEXT DEFAULT NULL,
    file_path VARCHAR(255) DEFAULT NULL,          -- scanned PDF
    is_active TINYINT(1) DEFAULT 1,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_emp (employee_id),
    INDEX idx_active (is_active),
    INDEX idx_end (end_date_ad),
    CONSTRAINT fk_contract_emp FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) DOCUMENTS (नागरिकता, PAN, प्रमाण-पत्र)
CREATE TABLE IF NOT EXISTS hrm_employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    doc_type VARCHAR(80) NOT NULL,                -- citizenship, pan, license, passport, certificate, training, other
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
    INDEX idx_expiry (expiry_date_ad),
    CONSTRAINT fk_doc_emp FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) EDUCATION (शैक्षिक योग्यता)
CREATE TABLE IF NOT EXISTS hrm_employee_education (
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
    INDEX idx_emp (employee_id),
    CONSTRAINT fk_edu_emp FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) EXPERIENCE (पूर्व अनुभव)
CREATE TABLE IF NOT EXISTS hrm_employee_experience (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    organization VARCHAR(200) NOT NULL,
    designation VARCHAR(160) DEFAULT NULL,
    from_date_ad DATE DEFAULT NULL,
    to_date_ad DATE DEFAULT NULL,
    responsibilities TEXT DEFAULT NULL,
    sort_order INT DEFAULT 0,
    INDEX idx_emp (employee_id),
    CONSTRAINT fk_exp_emp FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) FAMILY / NOMINEE (परिवार/इच्छाएको व्यक्ति)
CREATE TABLE IF NOT EXISTS hrm_employee_family (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    relation VARCHAR(60) NOT NULL,                 -- बुबा/आमा/श्रीमान्/श्रीमती/छोरा/छोरी
    full_name VARCHAR(160) NOT NULL,
    contact VARCHAR(40) DEFAULT NULL,
    occupation VARCHAR(120) DEFAULT NULL,
    is_nominee TINYINT(1) DEFAULT 0,
    nominee_share DECIMAL(5,2) DEFAULT 0,
    notes VARCHAR(255) DEFAULT NULL,
    INDEX idx_emp (employee_id),
    CONSTRAINT fk_fam_emp FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) BANK / PF / CIT
CREATE TABLE IF NOT EXISTS hrm_employee_bank (
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
    notes VARCHAR(255) DEFAULT NULL,
    CONSTRAINT fk_bank_emp FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9) SERVICE HISTORY (बढुवा/सरुवा/निलम्बन/अवकाश)
CREATE TABLE IF NOT EXISTS hrm_employee_history (
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
    INDEX idx_date (event_date_ad),
    CONSTRAINT fk_hist_emp FOREIGN KEY (employee_id) REFERENCES hrm_employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- HRM MESSAGES (merged from hrm_messages.sql)
-- =====================================================
CREATE TABLE IF NOT EXISTS hrm_internal_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_admin_id INT DEFAULT NULL,
    sender_employee_id INT DEFAULT NULL,
    receiver_employee_id INT NOT NULL,
    subject VARCHAR(200) DEFAULT NULL,
    body TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_receiver (receiver_employee_id, is_read),
    INDEX idx_sender (sender_admin_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- COLUMN FIXES (merged from fix-missing-columns.sql)
-- =====================================================
-- Fix missing columns in various tables
-- These columns are referenced in queries but don't exist

-- Add missing columns to admin_users table if they don't exist
ALTER TABLE admin_users 
ADD COLUMN IF NOT EXISTS full_name_np VARCHAR(255) DEFAULT NULL AFTER full_name,
ADD COLUMN IF NOT EXISTS published TINYINT(1) DEFAULT 1 AFTER is_active,
ADD COLUMN IF NOT EXISTS risk_review_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER role;

-- Add missing columns to members table if they don't exist
ALTER TABLE members 
ADD COLUMN IF NOT EXISTS full_name_np VARCHAR(255) DEFAULT NULL AFTER name,
ADD COLUMN IF NOT EXISTS published TINYINT(1) DEFAULT 1 AFTER is_active,
ADD COLUMN IF NOT EXISTS risk_review_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER status;

-- Add missing columns to loan_applications table if they don't exist
ALTER TABLE loan_applications 
ADD COLUMN IF NOT EXISTS full_name VARCHAR(255) DEFAULT NULL AFTER member_id,
ADD COLUMN IF NOT EXISTS full_name_np VARCHAR(255) DEFAULT NULL AFTER full_name,
ADD COLUMN IF NOT EXISTS published TINYINT(1) DEFAULT 1 AFTER status,
ADD COLUMN IF NOT EXISTS risk_review_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER status;

-- Add missing columns to kyc_applications table if they don't exist
ALTER TABLE kyc_applications 
ADD COLUMN IF NOT EXISTS full_name VARCHAR(255) DEFAULT NULL AFTER member_id,
ADD COLUMN IF NOT EXISTS full_name_np VARCHAR(255) DEFAULT NULL AFTER full_name,
ADD COLUMN IF NOT EXISTS published TINYINT(1) DEFAULT 1 AFTER status,
ADD COLUMN IF NOT EXISTS risk_review_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER status;

-- Add missing columns to news table if they don't exist
ALTER TABLE news 
ADD COLUMN IF NOT EXISTS published TINYINT(1) DEFAULT 1 AFTER is_active;

-- Add missing columns to notices table if they don't exist
ALTER TABLE notices 
ADD COLUMN IF NOT EXISTS published TINYINT(1) DEFAULT 1 AFTER is_active;

-- Add missing columns to committees table if they don't exist
ALTER TABLE committees 
ADD COLUMN IF NOT EXISTS published TINYINT(1) DEFAULT 1 AFTER is_active;

-- Add missing columns to careers table if they don't exist
ALTER TABLE careers 
ADD COLUMN IF NOT EXISTS published TINYINT(1) DEFAULT 1 AFTER is_active;

-- Add missing columns to digital_service_requests table if they don't exist
ALTER TABLE digital_service_requests 
ADD COLUMN IF NOT EXISTS full_name VARCHAR(255) DEFAULT NULL AFTER member_id,
ADD COLUMN IF NOT EXISTS full_name_np VARCHAR(255) DEFAULT NULL AFTER full_name,
ADD COLUMN IF NOT EXISTS published TINYINT(1) DEFAULT 1 AFTER status,
ADD COLUMN IF NOT EXISTS risk_review_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER status;

-- Update existing records to have default values
UPDATE admin_users SET published = 1 WHERE published IS NULL;
UPDATE admin_users SET risk_review_status = 'approved' WHERE risk_review_status IS NULL;

UPDATE members SET published = 1 WHERE published IS NULL;
UPDATE members SET risk_review_status = 'approved' WHERE risk_review_status IS NULL;

UPDATE loan_applications SET published = 1 WHERE published IS NULL;
UPDATE loan_applications SET risk_review_status = 'pending' WHERE risk_review_status IS NULL;

UPDATE kyc_applications SET published = 1 WHERE published IS NULL;
UPDATE kyc_applications SET risk_review_status = 'pending' WHERE risk_review_status IS NULL;

UPDATE news SET published = 1 WHERE published IS NULL;
UPDATE notices SET published = 1 WHERE published IS NULL;
UPDATE committees SET published = 1 WHERE published IS NULL;
UPDATE careers SET published = 1 WHERE published IS NULL;

UPDATE digital_service_requests SET published = 1 WHERE published IS NULL;
UPDATE digital_service_requests SET risk_review_status = 'pending' WHERE risk_review_status IS NULL;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;
-- ═══════════════════════════════════════════════════════════════
-- ✅  सबै tables (Core + HRM + Messages), columns, indexes, seed data लोड भयो।
-- ═══════════════════════════════════════════════════════════════
