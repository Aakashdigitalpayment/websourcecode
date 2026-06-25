<?php
/**
 * डिजिटल सेवा अनुरोध — DDL एकै ठाउँ
 */
if (!function_exists('ensureDigitalServiceRequestsTables')) {
    function ensureDigitalServiceRequestsTables($db = null)
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
            $db->exec("CREATE TABLE IF NOT EXISTS digital_service_requests (
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
            reviewed_by VARCHAR(100),
            reviewed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_tracking (tracking_id),
            INDEX idx_phone (phone),
            INDEX idx_email (email),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            foreach ([
                'ALTER TABLE digital_service_requests MODIFY COLUMN service_type VARCHAR(60) NOT NULL',
                'ALTER TABLE digital_service_requests ADD COLUMN service_amount DECIMAL(12,2) NULL AFTER recharge_amount',
                "ALTER TABLE digital_service_requests ADD COLUMN admin_attachment VARCHAR(500) DEFAULT '' COMMENT 'Admin reply file'",
                'ALTER TABLE digital_service_requests ADD INDEX idx_service_type (service_type)',
                'ALTER TABLE digital_service_requests ADD INDEX idx_created (created_at)',
            ] as $sql) {
                try {
                    $db->exec($sql);
                } catch (Exception $e) {
                }
            }

            $done = true;
        } catch (Exception $e) {
        }
    }
}
