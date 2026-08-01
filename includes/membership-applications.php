<?php
/**
 * Public "सदस्य बन्नुस्" applications — Member ID assigned only by admin (SSOT).
 * After approve: members.sadasyata_number stub → then Online KYM / portal.
 */
declare(strict_types=1);

if (!function_exists('membershipEnsureTable')) {
    function membershipEnsureTable(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS membership_applications (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    tracking_id VARCHAR(40) NOT NULL,
                    full_name VARCHAR(200) NOT NULL,
                    mobile VARCHAR(20) NOT NULL DEFAULT '',
                    email VARCHAR(254) NULL,
                    address TEXT NULL,
                    citizenship_no VARCHAR(80) NULL,
                    remarks TEXT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    assigned_sadasyata VARCHAR(50) NULL,
                    member_pk INT UNSIGNED NULL,
                    admin_remarks TEXT NULL,
                    reviewed_by INT UNSIGNED NULL,
                    reviewed_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NULL,
                    UNIQUE KEY uq_membership_tracking (tracking_id),
                    KEY idx_membership_status (status),
                    KEY idx_membership_mobile (mobile),
                    KEY idx_membership_sadasyata (assigned_sadasyata)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $done = true;
        } catch (Throwable $e) {
            error_log('[membership] ensureTable: ' . $e->getMessage());
        }
    }
}

if (!function_exists('membershipNewTrackingId')) {
    function membershipNewTrackingId(): string
    {
        return 'MEM-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
    }
}

if (!function_exists('membershipCreateRequest')) {
    /**
     * @return array{ok:bool,tracking_id?:string,id?:int,error?:string}
     */
    function membershipCreateRequest(
        PDO $db,
        string $fullName,
        string $mobile,
        string $email,
        string $address,
        string $citizenshipNo = '',
        string $remarks = ''
    ): array {
        membershipEnsureTable($db);
        $fullName = trim(strip_tags($fullName));
        $mobile = preg_replace('/[^0-9]/', '', $mobile) ?? '';
        $email = strtolower(trim($email));
        $address = trim(strip_tags($address));
        $citizenshipNo = trim(strip_tags($citizenshipNo));
        $remarks = trim(strip_tags($remarks));

        if ($fullName === '') {
            return ['ok' => false, 'error' => 'नाम अनिवार्य छ।'];
        }
        if (!preg_match('/^[0-9]{10}$/', $mobile)) {
            return ['ok' => false, 'error' => '१० अंकको मोबाइल नम्बर राख्नुहोस्।'];
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'सही इमेल ठेगाना राख्नुहोस्।'];
        }
        if ($address === '') {
            return ['ok' => false, 'error' => 'ठेगाना अनिवार्य छ।'];
        }

        /* Soft duplicate: pending same mobile */
        try {
            $dup = $db->prepare(
                "SELECT id, tracking_id FROM membership_applications
                 WHERE mobile=? AND status='pending' ORDER BY id DESC LIMIT 1"
            );
            $dup->execute([$mobile]);
            $existing = $dup->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return [
                    'ok' => true,
                    'id' => (int)$existing['id'],
                    'tracking_id' => (string)$existing['tracking_id'],
                    'duplicate' => true,
                    'error' => '',
                    'message' => 'यही मोबाइलमा पेन्डिङ अनुरोध पहिले नै छ — Tracking ID: ' . (string)$existing['tracking_id'],
                ];
            }
        } catch (Throwable $e) {
            /* continue */
        }

        $tracking = membershipNewTrackingId();
        try {
            $st = $db->prepare(
                "INSERT INTO membership_applications
                    (tracking_id, full_name, mobile, email, address, citizenship_no, remarks, status, created_at)
                 VALUES (?,?,?,?,?,?,?,'pending',NOW())"
            );
            $st->execute([
                $tracking,
                $fullName,
                $mobile,
                $email !== '' ? $email : null,
                $address,
                $citizenshipNo !== '' ? $citizenshipNo : null,
                $remarks !== '' ? $remarks : null,
            ]);
            return [
                'ok' => true,
                'id' => (int)$db->lastInsertId(),
                'tracking_id' => $tracking,
            ];
        } catch (Throwable $e) {
            error_log('[membership] create: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'दर्ता असफल। पुनः प्रयास गर्नुहोस्।'];
        }
    }
}

if (!function_exists('membershipApproveWithMemberId')) {
    /**
     * Admin assigns coop Member ID → create members SSOT stub (no password).
     * @return array{ok:bool,member_pk?:int,message?:string}
     */
    function membershipApproveWithMemberId(
        PDO $db,
        int $applicationId,
        string $sadasyata,
        int $adminId = 0,
        string $adminRemarks = ''
    ): array {
        membershipEnsureTable($db);
        $sadasyata = function_exists('memberSsotNormalizeId')
            ? memberSsotNormalizeId($sadasyata)
            : trim($sadasyata);
        if ($applicationId < 1) {
            return ['ok' => false, 'message' => 'अवैध आवेदन।'];
        }
        if ($sadasyata === '') {
            return ['ok' => false, 'message' => 'Member ID (सदस्यता नं.) अनिवार्य छ।'];
        }

        try {
            $st = $db->prepare('SELECT * FROM membership_applications WHERE id=? LIMIT 1');
            $st->execute([$applicationId]);
            $app = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'आवेदन पढ्न सकिएन।'];
        }
        if (!$app) {
            return ['ok' => false, 'message' => 'आवेदन फेला परेन।'];
        }
        if (($app['status'] ?? '') === 'approved' && !empty($app['member_pk'])) {
            return [
                'ok' => true,
                'member_pk' => (int)$app['member_pk'],
                'message' => 'पहिले नै स्वीकृत छ।',
            ];
        }

        $existing = function_exists('memberSsotFindBySadasyata')
            ? memberSsotFindBySadasyata($db, $sadasyata)
            : null;
        if ($existing) {
            return [
                'ok' => false,
                'message' => 'यो Member ID पहिले नै members सूचीमा छ। अर्को नम्बर हाल्नुहोस् वा अवस्थित सदस्यसँग मिलाउनुहोस्।',
            ];
        }

        if (function_exists('ensureMemberTables')) {
            try {
                ensureMemberTables();
            } catch (Throwable $e) { /* continue */ }
        }

        $name = trim((string)($app['full_name'] ?? ''));
        if ($name === '') {
            $name = 'Member ' . $sadasyata;
        }
        $phone = preg_replace('/[^0-9]/', '', (string)($app['mobile'] ?? '')) ?: null;
        $email = trim((string)($app['email'] ?? ''));
        $email = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        $address = trim((string)($app['address'] ?? '')) ?: null;

        try {
            $cardNo = 'M-' . date('Y') . '-' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
            $ins = $db->prepare(
                "INSERT INTO members
                    (name, email, phone, sadasyata_number, password_hash, member_card_no, address,
                     approval_status, is_active, kyc_application_id, approved_at, approved_by)
                 VALUES (?,?,?,?,NULL,?,?, 'pending', 1, NULL, NULL, NULL)"
            );
            $ins->execute([$name, $email, $phone, $sadasyata, $cardNo, $address]);
            $memberPk = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[membership] approve insert member: ' . $e->getMessage());
            if (stripos($e->getMessage(), 'Duplicate') !== false) {
                return ['ok' => false, 'message' => 'Member ID दोहोरो भयो। अर्को नम्बर हाल्नुहोस्।'];
            }
            return ['ok' => false, 'message' => 'सदस्य खाता बनाउन सकिएन: ' . $e->getMessage()];
        }

        try {
            $up = $db->prepare(
                "UPDATE membership_applications SET
                    status='approved',
                    assigned_sadasyata=?,
                    member_pk=?,
                    admin_remarks=?,
                    reviewed_by=?,
                    reviewed_at=NOW(),
                    updated_at=NOW()
                 WHERE id=?"
            );
            $up->execute([
                $sadasyata,
                $memberPk,
                $adminRemarks !== '' ? $adminRemarks : null,
                $adminId > 0 ? $adminId : null,
                $applicationId,
            ]);
        } catch (Throwable $e) {
            error_log('[membership] approve update app: ' . $e->getMessage());
        }

        return [
            'ok' => true,
            'member_pk' => $memberPk,
            'message' => "स्वीकृत। Member ID {$sadasyata} — members stub बन्यो। अब Online KYM भर्न सकिन्छ।",
        ];
    }
}

if (!function_exists('membershipReject')) {
    /** @return array{ok:bool,message?:string} */
    function membershipReject(PDO $db, int $applicationId, int $adminId = 0, string $adminRemarks = ''): array
    {
        membershipEnsureTable($db);
        if ($applicationId < 1) {
            return ['ok' => false, 'message' => 'अवैध आवेदन।'];
        }
        try {
            $st = $db->prepare(
                "UPDATE membership_applications SET
                    status='rejected',
                    admin_remarks=?,
                    reviewed_by=?,
                    reviewed_at=NOW(),
                    updated_at=NOW()
                 WHERE id=? AND status <> 'approved'"
            );
            $st->execute([
                $adminRemarks !== '' ? $adminRemarks : null,
                $adminId > 0 ? $adminId : null,
                $applicationId,
            ]);
            return ['ok' => true, 'message' => 'अस्वीकृत भयो।'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('membershipPendingCount')) {
    function membershipPendingCount(PDO $db): int
    {
        try {
            membershipEnsureTable($db);
            return (int)$db->query(
                "SELECT COUNT(*) FROM membership_applications WHERE status='pending'"
            )->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
