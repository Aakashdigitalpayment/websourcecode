<?php
/**
 * सदस्य कल्याण दाबी — DDL एकै ठाउँ (ensure-tables + admin/public पृष्ठहरू)
 */
if (!function_exists('ensureWelfareClaimsTables')) {
    function ensureWelfareClaimsTables($db = null)
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (!$db && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Exception $e) {
                return;
            }
        }
        if (!$db instanceof PDO) {
            return;
        }
        try {
            foreach ([
                "ALTER TABLE member_welfare_claims ADD COLUMN tracking_id VARCHAR(60) UNIQUE NULL",
                "ALTER TABLE member_welfare_claims ADD COLUMN status ENUM('pending','processing','approved','rejected') DEFAULT 'pending'",
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Exception $e) {
                }
            }

            $db->exec("CREATE TABLE IF NOT EXISTS member_welfare_claims (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tracking_id VARCHAR(60) UNIQUE NULL,
            member_name VARCHAR(100) NOT NULL,
            member_id VARCHAR(50),
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(100),
            address VARCHAR(255),
            claim_type ENUM('maternity','death','insurance','medical','other') DEFAULT 'other',
            claim_type_np VARCHAR(100),
            beneficiary_name VARCHAR(100),
            beneficiary_relation VARCHAR(50),
            claim_amount DECIMAL(12,2),
            approved_amount DECIMAL(12,2),
            description TEXT,
            supporting_documents TEXT,
            deceased_name VARCHAR(100),
            deceased_relation VARCHAR(50),
            death_date DATE,
            death_certificate VARCHAR(255),
            delivery_date DATE,
            hospital_name VARCHAR(200),
            disease_illness VARCHAR(200),
            treatment_date DATE,
            hospital_clinic VARCHAR(200),
            status ENUM('pending','under_review','approved','rejected','paid','completed') DEFAULT 'pending',
            admin_remarks TEXT,
            reviewed_by VARCHAR(100),
            reviewed_at TIMESTAMP NULL,
            paid_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_tracking (tracking_id),
            INDEX idx_phone (phone),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            foreach ([
                'ALTER TABLE member_welfare_claims ADD COLUMN policy_number VARCHAR(80) DEFAULT NULL',
                'ALTER TABLE member_welfare_claims ADD COLUMN insurer_name VARCHAR(150) DEFAULT NULL',
                'ALTER TABLE member_welfare_claims ADD COLUMN member_portal_id INT DEFAULT NULL',
                'ALTER TABLE member_welfare_claims ADD COLUMN attachment_path VARCHAR(255) DEFAULT NULL',
                "ALTER TABLE member_welfare_claims MODIFY COLUMN claim_type ENUM('maternity','death','insurance','medical','accident','other') NOT NULL",
                'ALTER TABLE member_welfare_claims ADD INDEX idx_claim_type (claim_type)',
                'ALTER TABLE member_welfare_claims ADD INDEX idx_created (created_at)',
                'ALTER TABLE member_welfare_claims ADD INDEX idx_member_id (member_id)',
                'ALTER TABLE member_welfare_claims ADD INDEX idx_portal (member_portal_id)',
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Exception $e) {
                }
            }

            try {
                $db->exec('ALTER TABLE member_welfare_claims ADD COLUMN full_name VARCHAR(120) GENERATED ALWAYS AS (member_name) VIRTUAL');
            } catch (Exception $e) {
            }

            $done = true;
        } catch (Exception $e) {
        }
    }
}
