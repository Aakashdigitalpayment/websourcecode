<?php
/**
 * साइट लाइसेन्स नवीकरण — manual भुक्तानी सूचना (Khalti/eSewa + ref)
 * कार्यालय/लग इन बाहिर पनि पठाउन मिल्छ। रकम सधैं Superadmin को सेटिङ (`site_license_renewal_amount`) बाट — फारमबाट बदलिँदैन।
 */
declare(strict_types=1);

/** Khalti / eSewa मा पैसा **ग्रहण** गर्ने ID (विक्रेता) — ग्राहकले आफ्नो wallet बाट यही नम्बरमा पठाउँछन्। सेटिङ खाली भए यही देखिन्छ। */
if (!defined('SITE_LICENSE_VENDOR_PAY_ID_DEFAULT')) {
    define('SITE_LICENSE_VENDOR_PAY_ID_DEFAULT', '9856026434');
}

if (!function_exists('site_license_pay_id_or_default')) {
    function site_license_pay_id_or_default(string $fromSetting): string {
        $t = trim($fromSetting);
        return $t !== '' ? $t : SITE_LICENSE_VENDOR_PAY_ID_DEFAULT;
    }
}

if (!function_exists('ensureSiteLicenseRenewalNoticesTable')) {
    function ensureSiteLicenseRenewalNoticesTable(PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS site_license_renewal_notices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            status ENUM('pending','cleared','cancelled') NOT NULL DEFAULT 'pending',
            gateway VARCHAR(32) NOT NULL DEFAULT '',
            txn_reference VARCHAR(180) NOT NULL DEFAULT '',
            amount_reported VARCHAR(40) NOT NULL DEFAULT '',
            note TEXT,
            submitted_by_admin_id INT NULL,
            submitted_by_username VARCHAR(80) NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('site_license_renewal_pending_count')) {
    function site_license_renewal_pending_count(PDO $db): int {
        try {
            $n = (int) $db->query("SELECT COUNT(*) FROM site_license_renewal_notices WHERE status = 'pending'")->fetchColumn();
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('site_license_renewal_clear_pending')) {
    /** नयाँ म्याद सेभ भएपछि पेन्डिङ सूचना बन्द गर्ने */
    function site_license_renewal_clear_pending(PDO $db): void {
        try {
            $db->exec("UPDATE site_license_renewal_notices SET status = 'cleared' WHERE status = 'pending'");
        } catch (Throwable $e) { /* ignore */ }
    }
}

if (!function_exists('site_license_renewal_cancel_pending')) {
    function site_license_renewal_cancel_pending(PDO $db): void {
        try {
            $db->exec("UPDATE site_license_renewal_notices SET status = 'cancelled' WHERE status = 'pending'");
        } catch (Throwable $e) { /* ignore */ }
    }
}

if (!function_exists('site_license_renewal_apply_office_notice')) {
    /**
     * भुक्तानी सूचना बचत — रकम POST बाट होइन, सधैं getSetting('site_license_renewal_amount')।
     *
     * @return array{ok:bool, id?:int, error?:string}
     */
    function site_license_renewal_apply_office_notice(PDO $db, string $gateway, string $txn, string $note, string $submitter, ?int $adminId): array {
        $allowedGw = ['khalti', 'esewa', 'other'];
        if (!in_array($gateway, $allowedGw, true)) {
            return ['ok' => false, 'error' => 'गेटवेइ छान्नुहोस्।'];
        }
        $txn = trim($txn);
        if (mb_strlen($txn) < 3) {
            return ['ok' => false, 'error' => 'कारोबार नम्बर / Ref कम्तिमा ३ अक्षर हुनुपर्छ।'];
        }
        // Outside-office form मा submitter editable हुँदैन — सधैं Settings को site_name प्रयोग गर्ने।
        if (function_exists('getSetting')) {
            $submitter = trim((string) getSetting('site_name', 'सहकारी'));
        } else {
            $submitter = trim($submitter);
        }
        if (mb_strlen($submitter) < 2) {
            $submitter = 'सहकारी';
        }
        ensureSiteLicenseRenewalNoticesTable($db);
        if (site_license_renewal_pending_count($db) > 0) {
            return ['ok' => false, 'error' => 'पहिले नै भुक्तानी सूचना पेन्डिङ छ। दोहोरो नपठाउनुहोस्। Superadmin वा विक्रेता सम्पर्क गर्नुहोस्।'];
        }
        $amt = function_exists('getSetting') ? trim((string) getSetting('site_license_renewal_amount', '')) : '';
        $aid = ($adminId !== null && $adminId > 0) ? $adminId : null;
        $st = $db->prepare("INSERT INTO site_license_renewal_notices (status, gateway, txn_reference, amount_reported, note, submitted_by_admin_id, submitted_by_username) VALUES ('pending',?,?,?,?,?,?)");
        $st->execute([$gateway, $txn, $amt, trim($note), $aid, $submitter]);
        $newId = (int) $db->lastInsertId();

        return ['ok' => true, 'id' => $newId];
    }
}

if (!function_exists('site_license_renewal_notify_vendor')) {
    function site_license_renewal_notify_vendor(PDO $db, array $notice): void {
        if (!function_exists('getSetting')) {
            return;
        }
        $to = trim((string) getSetting('site_license_vendor_email', ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $site = (string) getSetting('site_name', 'सहकारी');
        $siteUrl = defined('SITE_URL') ? SITE_URL : '';
        $subject = '[लाइसेन्स] नवीकरण भुक्तानी सूचना — ' . $site;

        $gw = htmlspecialchars((string) ($notice['gateway'] ?? ''), ENT_QUOTES, 'UTF-8');
        $txn = htmlspecialchars((string) ($notice['txn_reference'] ?? ''), ENT_QUOTES, 'UTF-8');
        $amt = htmlspecialchars((string) ($notice['amount_reported'] ?? ''), ENT_QUOTES, 'UTF-8');
        $note = htmlspecialchars((string) ($notice['note'] ?? ''), ENT_QUOTES, 'UTF-8');
        $user = htmlspecialchars((string) ($notice['submitted_by_username'] ?? ''), ENT_QUOTES, 'UTF-8');
        $id = (int) ($notice['id'] ?? 0);

        $body = '<p><strong>साइट:</strong> ' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>पठाउने (कार्यालय/नाम):</strong> ' . $user . '</p>'
            . '<p><strong>गेटवे:</strong> ' . $gw . '</p>'
            . '<p><strong>Txn / Ref:</strong> ' . $txn . '</p>'
            . '<p><strong>रकम (नवीकरण सेटिङ अनुसार):</strong> ' . $amt . '</p>'
            . '<p><strong>टिप्पणी:</strong> ' . nl2br($note) . '</p>'
            . '<p><strong>Notice ID:</strong> ' . $id . '</p>'
            . '<p><small>Admin: ' . htmlspecialchars($siteUrl . 'admin/site-license.php', ENT_QUOTES, 'UTF-8') . '</small></p>';

        if (function_exists('sendSmtpEmail')) {
            sendSmtpEmail($to, $subject, $body);
            return;
        }
        $fromEmail = getSetting('notify_email_from', getSetting('site_email', 'noreply@localhost'));
        $fromName = getSetting('site_name', $site);
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $headers .= 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n";
        @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    }
}
