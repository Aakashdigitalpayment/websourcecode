<?php
/**
 * साझेदार सुविधाहरू — DDL + helpers (public / admin / verify)
 */
if (!function_exists('ensurePartnerFacilitiesTables')) {
    function ensurePartnerFacilitiesTables(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (!$db && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Throwable $e) {
                return;
            }
        }
        if (!$db instanceof PDO) {
            return;
        }
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS partner_facilities (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            partner_name    VARCHAR(200) NOT NULL,
            partner_name_en VARCHAR(200) NOT NULL DEFAULT '',
            location        VARCHAR(200) NOT NULL DEFAULT '',
            facility_type   VARCHAR(100) NOT NULL DEFAULT '',
            discount_percent DECIMAL(5,2) DEFAULT 0,
            discount_label  VARCHAR(160) NOT NULL DEFAULT '',
            description     TEXT,
            description_en  TEXT NULL,
            terms_np        TEXT NULL,
            logo_path       VARCHAR(500) NULL DEFAULT NULL,
            contact_phone   VARCHAR(30) NOT NULL DEFAULT '',
            contact_email   VARCHAR(120) NOT NULL DEFAULT '',
            website_url     VARCHAR(255) NOT NULL DEFAULT '',
            partner_code    VARCHAR(32) NULL DEFAULT NULL,
            pin_hash        VARCHAR(255) NULL DEFAULT NULL,
            vendor_id       INT NULL DEFAULT NULL,
            is_featured     TINYINT(1) NOT NULL DEFAULT 0,
            is_active       TINYINT DEFAULT 1,
            display_order   INT DEFAULT 0,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pf_code (partner_code),
            INDEX idx_pf_active (is_active, display_order),
            INDEX idx_pf_type (facility_type),
            INDEX idx_pf_vendor (vendor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            if (function_exists('safeAddColumn')) {
                safeAddColumn($db, 'partner_facilities', 'partner_name_en', "VARCHAR(200) NOT NULL DEFAULT '' AFTER partner_name");
                safeAddColumn($db, 'partner_facilities', 'discount_label', "VARCHAR(160) NOT NULL DEFAULT '' AFTER discount_percent");
                safeAddColumn($db, 'partner_facilities', 'description_en', "TEXT NULL AFTER description");
                safeAddColumn($db, 'partner_facilities', 'terms_np', "TEXT NULL AFTER description_en");
                safeAddColumn($db, 'partner_facilities', 'logo_path', "VARCHAR(500) NULL DEFAULT NULL AFTER terms_np");
                safeAddColumn($db, 'partner_facilities', 'contact_phone', "VARCHAR(30) NOT NULL DEFAULT '' AFTER logo_path");
                safeAddColumn($db, 'partner_facilities', 'contact_email', "VARCHAR(120) NOT NULL DEFAULT '' AFTER contact_phone");
                safeAddColumn($db, 'partner_facilities', 'website_url', "VARCHAR(255) NOT NULL DEFAULT '' AFTER contact_email");
                safeAddColumn($db, 'partner_facilities', 'partner_code', "VARCHAR(32) NULL DEFAULT NULL AFTER website_url");
                safeAddColumn($db, 'partner_facilities', 'pin_hash', "VARCHAR(255) NULL DEFAULT NULL AFTER partner_code");
                safeAddColumn($db, 'partner_facilities', 'vendor_id', "INT NULL DEFAULT NULL AFTER pin_hash");
                safeAddColumn($db, 'partner_facilities', 'is_featured', "TINYINT(1) NOT NULL DEFAULT 0 AFTER vendor_id");
            }

            /* Backfill partner_code for rows missing one */
            try {
                $missing = $db->query("SELECT id FROM partner_facilities WHERE partner_code IS NULL OR partner_code='' LIMIT 200")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                $upd = $db->prepare('UPDATE partner_facilities SET partner_code=? WHERE id=? AND (partner_code IS NULL OR partner_code="")');
                foreach ($missing as $id) {
                    $upd->execute([partnerGenerateCode($db), (int)$id]);
                }
            } catch (Throwable $e) { /* ignore */ }

            $done = true;
        } catch (Throwable $e) {
        }
    }
}

if (!function_exists('partnerGenerateCode')) {
    function partnerGenerateCode(?PDO $db = null): string
    {
        for ($i = 0; $i < 12; $i++) {
            $code = 'PF-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            if (!$db instanceof PDO) {
                return $code;
            }
            try {
                $st = $db->prepare('SELECT COUNT(*) FROM partner_facilities WHERE partner_code=?');
                $st->execute([$code]);
                if ((int)$st->fetchColumn() === 0) {
                    return $code;
                }
            } catch (Throwable $e) {
                return $code;
            }
        }
        return 'PF-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    }
}

if (!function_exists('partnerFacilityDisplayName')) {
    function partnerFacilityDisplayName(array $row): string
    {
        $en = trim((string)($row['partner_name_en'] ?? ''));
        $np = trim((string)($row['partner_name'] ?? ''));
        if (function_exists('isEnglish') && isEnglish() && $en !== '') {
            return $en;
        }
        return $np !== '' ? $np : $en;
    }
}

if (!function_exists('partnerFacilityDescription')) {
    function partnerFacilityDescription(array $row): string
    {
        $en = trim((string)($row['description_en'] ?? ''));
        $np = trim((string)($row['description'] ?? ''));
        if (function_exists('isEnglish') && isEnglish() && $en !== '') {
            return $en;
        }
        return $np !== '' ? $np : $en;
    }
}

if (!function_exists('partnerFacilityLogoUrl')) {
    function partnerFacilityLogoUrl(array $row): string
    {
        $p = trim((string)($row['logo_path'] ?? ''));
        if ($p === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $p)) {
            return $p;
        }
        $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') . '/' : '/';
        return $base . ltrim($p, '/');
    }
}

if (!function_exists('partnerDiscountDisplay')) {
    /** Human discount chip text (bilingual). */
    function partnerDiscountDisplay(array $row): string
    {
        $label = trim((string)($row['discount_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        $pct = (float)($row['discount_percent'] ?? 0);
        if ($pct > 0) {
            $n = rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.');
            return function_exists('isEnglish') && isEnglish()
                ? ($n . '% off')
                : ($n . '% छुट');
        }
        return '';
    }
}

if (!function_exists('getActivePartnerFacilities')) {
    /**
     * @return list<array<string,mixed>>
     */
    function getActivePartnerFacilities(PDO $db, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        try {
            ensurePartnerFacilitiesTables($db);
            return $db->query(
                "SELECT * FROM partner_facilities
                 WHERE is_active=1
                 ORDER BY is_featured DESC, display_order ASC, partner_name ASC
                 LIMIT {$limit}"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('partnerFindById')) {
    function partnerFindById(PDO $db, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        try {
            $st = $db->prepare('SELECT * FROM partner_facilities WHERE id=? LIMIT 1');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('partnerFindByCode')) {
    function partnerFindByCode(PDO $db, string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        try {
            $st = $db->prepare('SELECT * FROM partner_facilities WHERE partner_code=? AND is_active=1 LIMIT 1');
            $st->execute([$code]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('partnerVerifyPin')) {
    function partnerVerifyPin(array $partner, string $pin): bool
    {
        $hash = (string)($partner['pin_hash'] ?? '');
        if ($hash === '') {
            return true; /* PIN not required */
        }
        $pin = trim($pin);
        if ($pin === '') {
            return false;
        }
        return password_verify($pin, $hash);
    }
}

if (!function_exists('partnerHasServiceLogs')) {
    function partnerHasServiceLogs(PDO $db, int $partnerId): bool
    {
        if ($partnerId < 1) {
            return false;
        }
        try {
            if (function_exists('ensureMemberPartnerServicesTable')) {
                ensureMemberPartnerServicesTable($db);
            }
            $st = $db->prepare('SELECT COUNT(*) FROM member_partner_services WHERE partner_id=?');
            $st->execute([$partnerId]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('partnerUsageCount')) {
    function partnerUsageCount(PDO $db, int $partnerId): int
    {
        if ($partnerId < 1) {
            return 0;
        }
        try {
            $st = $db->prepare('SELECT COUNT(*) FROM member_partner_services WHERE partner_id=?');
            $st->execute([$partnerId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('logMemberPartnerService')) {
    /**
     * @return array{ok:bool,error?:string}
     */
    function logMemberPartnerService(
        PDO $db,
        int $memberId,
        string $cardNo,
        int $partnerId,
        string $serviceName = '',
        bool $taken = true,
        string $note = '',
        string $pin = '',
        ?string $ip = null
    ): array {
        if ($memberId < 1 || $partnerId < 1) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        ensurePartnerFacilitiesTables($db);
        if (function_exists('ensureMemberPartnerServicesTable')) {
            ensureMemberPartnerServicesTable($db);
        }
        $partner = partnerFindById($db, $partnerId);
        if (!$partner || empty($partner['is_active'])) {
            return ['ok' => false, 'error' => 'partner'];
        }
        if (!partnerVerifyPin($partner, $pin)) {
            return ['ok' => false, 'error' => 'pin'];
        }
        if (partnerRecentDuplicateLog($db, $memberId, $partnerId, 90)) {
            return ['ok' => false, 'error' => 'duplicate'];
        }
        $ip = $ip ?? (string)($_SERVER['REMOTE_ADDR'] ?? '');
        try {
            $ins = $db->prepare(
                'INSERT INTO member_partner_services
                 (member_id, member_card_no, partner_id, partner_name, service_name, service_taken, service_note, verified_by_ip)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $ins->execute([
                $memberId,
                mb_substr($cardNo, 0, 50),
                $partnerId,
                mb_substr((string)$partner['partner_name'], 0, 255),
                mb_substr(trim($serviceName), 0, 255),
                $taken ? 1 : 0,
                mb_substr(trim($note), 0, 500),
                mb_substr($ip, 0, 45),
            ]);
            return ['ok' => true];
        } catch (Throwable $e) {
            error_log('logMemberPartnerService: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db'];
        }
    }
}

if (!function_exists('partnerBuildVerifyDisplayResult')) {
    /**
     * After service log: show success card without re-running CVV verify (avoids rate-limit wipe).
     * @return array{ok:bool,member?:array,card?:array,error?:string}
     */
    function partnerBuildVerifyDisplayResult(PDO $db, int $memberId, string $cardNo = ''): array
    {
        if ($memberId < 1) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        try {
            $st = $db->prepare(
                "SELECT m.id, m.name, m.sadasyata_number, m.member_card_no, m.phone, m.avatar_url,
                        m.approval_status, m.is_active, m.created_at
                 FROM members m WHERE m.id=? LIMIT 1"
            );
            $st->execute([$memberId]);
            $m = $st->fetch(PDO::FETCH_ASSOC);
            if (!$m) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            if ((string)($m['approval_status'] ?? '') !== 'approved' || (int)($m['is_active'] ?? 0) !== 1) {
                return ['ok' => false, 'error' => 'inactive'];
            }
            $dispId = $cardNo !== ''
                ? $cardNo
                : (string)($m['sadasyata_number'] ?: ($m['member_card_no'] ?: $m['id']));
            return [
                'ok' => true,
                'member' => [
                    'id' => (int)$m['id'],
                    'member_id' => $dispId,
                    'full_name' => (string)($m['name'] ?? ''),
                    'photo_path' => (string)($m['avatar_url'] ?? ''),
                    'mobile' => (string)($m['phone'] ?? ''),
                    'email' => '',
                    'father_name' => '',
                    'member_since' => (string)($m['created_at'] ?? ''),
                ],
                'card' => [
                    'card_no' => $dispId,
                    'legacy_card_no' => (string)($m['member_card_no'] ?? ''),
                    'verification_code' => '',
                    'issued_date' => '',
                    'expires_at' => '',
                    'verify_count' => 0,
                    'secret_cvv' => '',
                ],
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db'];
        }
    }
}

if (!function_exists('partnerRecentDuplicateLog')) {
    /** Same member+partner logged within N seconds (spam guard). */
    function partnerRecentDuplicateLog(PDO $db, int $memberId, int $partnerId, int $withinSeconds = 120): bool
    {
        if ($memberId < 1 || $partnerId < 1) {
            return false;
        }
        try {
            $since = date('Y-m-d H:i:s', time() - max(30, $withinSeconds));
            $st = $db->prepare(
                'SELECT COUNT(*) FROM member_partner_services
                 WHERE member_id=? AND partner_id=? AND created_at >= ?'
            );
            $st->execute([$memberId, $partnerId, $since]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('fetchMemberPartnerServiceLogs')) {
    /**
     * Member's partner visit / service-use history (newest first).
     * @return list<array<string,mixed>>
     */
    function fetchMemberPartnerServiceLogs(PDO $db, int $memberId, int $partnerId = 0, int $limit = 40): array
    {
        if ($memberId < 1) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            if (function_exists('ensureMemberPartnerServicesTable')) {
                ensureMemberPartnerServicesTable($db);
            }
            if ($partnerId > 0) {
                $st = $db->prepare(
                    "SELECT s.id, s.member_id, s.member_card_no, s.partner_id, s.partner_name,
                            s.service_name, s.service_taken, s.service_note, s.created_at,
                            p.facility_type, p.logo_path, p.partner_code, p.partner_name_en
                     FROM member_partner_services s
                     LEFT JOIN partner_facilities p ON p.id = s.partner_id
                     WHERE s.member_id = ? AND s.partner_id = ?
                     ORDER BY s.created_at DESC
                     LIMIT {$limit}"
                );
                $st->execute([$memberId, $partnerId]);
            } else {
                $st = $db->prepare(
                    "SELECT s.id, s.member_id, s.member_card_no, s.partner_id, s.partner_name,
                            s.service_name, s.service_taken, s.service_note, s.created_at,
                            p.facility_type, p.logo_path, p.partner_code, p.partner_name_en
                     FROM member_partner_services s
                     LEFT JOIN partner_facilities p ON p.id = s.partner_id
                     WHERE s.member_id = ?
                     ORDER BY s.created_at DESC
                     LIMIT {$limit}"
                );
                $st->execute([$memberId]);
            }
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('fetchPartnerFacilityServiceLogs')) {
    /**
     * Desk / admin: recent service logs for one partner facility.
     * @return list<array<string,mixed>>
     */
    function fetchPartnerFacilityServiceLogs(PDO $db, int $partnerId, int $limit = 50, bool $todayOnly = false): array
    {
        if ($partnerId < 1) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        try {
            if (function_exists('ensureMemberPartnerServicesTable')) {
                ensureMemberPartnerServicesTable($db);
            }
            $sql = "SELECT s.id, s.member_id, s.member_card_no, s.partner_id, s.partner_name,
                           s.service_name, s.service_taken, s.service_note, s.created_at,
                           m.name AS member_name
                    FROM member_partner_services s
                    LEFT JOIN members m ON m.id = s.member_id
                    WHERE s.partner_id = ?";
            $params = [$partnerId];
            if ($todayOnly) {
                $sql .= ' AND s.created_at >= ?';
                $params[] = date('Y-m-d 00:00:00');
            }
            $sql .= " ORDER BY s.created_at DESC LIMIT {$limit}";
            $st = $db->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('promoteVendorToPartner')) {
    /**
     * Approved vendor → partner_facilities (idempotent via vendor_id).
     * @return array{ok:bool,partner_id?:int,created?:bool,error?:string}
     */
    function promoteVendorToPartner(PDO $db, int $vendorId): array
    {
        if ($vendorId < 1) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        ensurePartnerFacilitiesTables($db);
        if (function_exists('ensureVendorsTables')) {
            ensureVendorsTables($db);
        }
        try {
            $st = $db->prepare('SELECT * FROM vendors WHERE id=? LIMIT 1');
            $st->execute([$vendorId]);
            $v = $st->fetch(PDO::FETCH_ASSOC);
            if (!$v) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            if (($v['status'] ?? '') !== 'approved') {
                return ['ok' => false, 'error' => 'not_approved'];
            }
            $exist = $db->prepare('SELECT id FROM partner_facilities WHERE vendor_id=? LIMIT 1');
            $exist->execute([$vendorId]);
            $eid = (int)($exist->fetchColumn() ?: 0);
            if ($eid > 0) {
                return ['ok' => true, 'partner_id' => $eid, 'created' => false];
            }

            $typeMap = [
                'supplier' => 'आपूर्तिकर्ता',
                'contractor' => 'ठेकेदार',
                'service_provider' => 'सेवा प्रदायक',
                'trader' => 'व्यापारी',
                'other' => 'अन्य',
            ];
            $bt = (string)($v['business_type'] ?? '');
            $ftype = $typeMap[$bt] ?? ($bt !== '' ? $bt : 'अन्य');
            $code = partnerGenerateCode($db);
            $db->prepare(
                'INSERT INTO partner_facilities
                 (partner_name, location, facility_type, description, contact_phone, contact_email, partner_code, vendor_id, is_active, display_order)
                 VALUES (?,?,?,?,?,?,?,?,1,0)'
            )->execute([
                mb_substr((string)$v['company_name'], 0, 200),
                mb_substr((string)($v['address'] ?? ''), 0, 200),
                mb_substr($ftype, 0, 100),
                mb_substr((string)($v['description'] ?? ''), 0, 5000),
                preg_replace('/[^0-9+]/', '', (string)($v['phone'] ?? '')),
                mb_substr(strtolower((string)($v['email'] ?? '')), 0, 120),
                $code,
                $vendorId,
            ]);
            $pid = (int)$db->lastInsertId();
            /* optional linked_partner_id on vendors */
            try {
                if (function_exists('safeAddColumn')) {
                    safeAddColumn($db, 'vendors', 'linked_partner_id', 'INT NULL DEFAULT NULL');
                }
                $db->prepare('UPDATE vendors SET linked_partner_id=? WHERE id=?')->execute([$pid, $vendorId]);
            } catch (Throwable $e) { /* ignore */ }

            return ['ok' => true, 'partner_id' => $pid, 'created' => true];
        } catch (Throwable $e) {
            error_log('promoteVendorToPartner: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db'];
        }
    }
}
