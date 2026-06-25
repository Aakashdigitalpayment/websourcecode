<?php
/**
 * ════════════════════════════════════════════════════════════
 * CARD VERIFY HELPERS — v10.4
 * ────────────────────────────────────────────────────────────
 * v10.4 changes (Issue #3, Issue #10):
 *   - Verification code prefix is now derived from the site domain
 *     instead of hard-coded "AKS".
 *     Format: <PREFIX>-XXXX-XXXX  where PREFIX = first 3 letters
 *     of the domain (after "www.", before ".com/.np/etc.").
 *     Example: bandanasigdel.com.np → BAN-XXXX-XXXX
 *              aakashcooperative.org → AAK-XXXX-XXXX
 *   - Admin can override the prefix in Site Settings → "Card Prefix".
 *   - normalizeCardCode() now accepts ANY 3-letter prefix (or no prefix)
 *     and re-formats to the active site prefix → backward compatible
 *     with old AKS-XXXX-XXXX cards already in DB.
 * ════════════════════════════════════════════════════════════
 */

if (!function_exists('getCardPrefix')) {
    /**
     * Active 3-letter card prefix derived from site domain (or admin override).
     * Cached per-request.
     */
    function getCardPrefix(): string {
        static $cached = null;
        if ($cached !== null) return $cached;

        // 1. Admin override (Site Settings → Card Prefix)
        if (function_exists('getSetting')) {
            $override = trim((string) getSetting('card_prefix', ''));
            if ($override !== '') {
                $cached = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $override));
                $cached = substr($cached ?: 'AKS', 0, 3);
                if (strlen($cached) === 3) return $cached;
            }
        }

        // 2. Derive from site_url setting (preferred — admin-controlled)
        $url = function_exists('getSetting') ? (string) getSetting('site_url', '') : '';

        // 3. Fallback: SITE_URL constant (auto-detected from request host)
        if ($url === '' && defined('SITE_URL')) $url = SITE_URL;

        // 4. Last resort: $_SERVER['HTTP_HOST']
        if ($url === '' && !empty($_SERVER['HTTP_HOST'])) $url = $_SERVER['HTTP_HOST'];

        // Strip protocol + www. + path
        $host = preg_replace('#^https?://#i', '', $url);
        $host = preg_replace('#^www\.#i',     '', $host);
        $host = explode('/', $host)[0];   // remove path
        $host = explode('?', $host)[0];   // remove query
        $host = explode(':', $host)[0];   // remove port

        // First 3 letters of the leftmost label, A-Z only
        $label  = explode('.', $host)[0] ?? '';
        $clean  = strtoupper(preg_replace('/[^A-Z]/i', '', $label));
        $prefix = substr($clean, 0, 3);

        if (strlen($prefix) !== 3) $prefix = 'AKS'; // safe fallback

        $cached = $prefix;
        return $prefix;
    }
}

if (!function_exists('generateCardVerification')) {
    /**
     * Cryptographically random unique verification code + 4-digit CVV.
     * Format: <PREFIX>-XXXX-XXXX (uppercase, ambiguous chars removed).
     * @return array{0:string,1:string} [verification_code, cvv]
     */
    function generateCardVerification(PDO $pdo): array {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // 0/O 1/I/L removed
        $alphaLen = strlen($alphabet);
        $prefix   = getCardPrefix();

        for ($try = 0; $try < 8; $try++) {
            $part1 = $part2 = '';
            for ($i = 0; $i < 4; $i++) {
                $part1 .= $alphabet[random_int(0, $alphaLen - 1)];
                $part2 .= $alphabet[random_int(0, $alphaLen - 1)];
            }
            $code = $prefix . '-' . $part1 . '-' . $part2;

            try {
                $chk = $pdo->prepare("SELECT 1 FROM member_id_cards WHERE verification_code = :c LIMIT 1");
                $chk->execute([':c' => $code]);
                if (!$chk->fetchColumn()) {
                    $cvv = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                    return [$code, $cvv];
                }
            } catch (Throwable $e) {
                error_log('[card-verify-gen] ' . $e->getMessage());
                break;
            }
        }
        // Fallback (essentially never hit)
        $fallback = $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4))
                  . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        return [$fallback, str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT)];
    }
}

if (!function_exists('generateCardNumber')) {
    /**
     * v10.4 (Issue #1, Issue #3): Build the visible card number that BOTH
     * the member card photo and the admin panel must show identically.
     *
     * Format: <PREFIX>-YYYY-NNNNN  (PREFIX from domain, YYYY = issue year,
     * NNNNN = zero-padded members.id)
     */
    function generateCardNumber(int $memberDbId, ?string $issuedDate = null): string {
        $prefix = getCardPrefix();
        $year   = $issuedDate ? date('Y', strtotime($issuedDate)) : date('Y');
        return $prefix . '-' . $year . '-' . str_pad((string) $memberDbId, 5, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('normalizeCardCode')) {
    /**
     * v10.4: accepts ANY 3-letter prefix (or none) and re-formats to the
     * active site prefix. Old AKS-XXXX-XXXX cards still verify against the
     * stored value because lookup uses raw normalized string.
     *
     * "ban9f7k2x4m" / "BAN 9F7K 2X4M" / "BAN-9F7K-2X4M" → "BAN-9F7K-2X4M"
     */
    function normalizeCardCode(string $raw): string {
        $raw   = strtoupper(trim($raw));
        $clean = preg_replace('/[^A-Z0-9]/', '', $raw);
        if ($clean === null || $clean === '') return '';

        // If first 3 chars are all letters AND total length is 11, treat as prefix
        if (strlen($clean) === 11 && ctype_alpha(substr($clean, 0, 3))) {
            $prefix = substr($clean, 0, 3);
            $body   = substr($clean, 3);
        } elseif (strlen($clean) === 8) {
            // No prefix supplied — assume current site prefix
            $prefix = getCardPrefix();
            $body   = $clean;
        } else {
            return $raw; // length mismatch — DB lookup will fail cleanly
        }

        return $prefix . '-' . substr($body, 0, 4) . '-' . substr($body, 4, 4);
    }
}

if (!function_exists('normalizeCardLookupKey')) {
    /**
     * Verification input लाई DB lookup key मा normalize गर्ने:
     * - verification code: BAN-AB12-CD34  -> BANAB12CD34
     * - card number:       BAN-2026-00001 -> BAN202600001
     */
    function normalizeCardLookupKey(string $raw): string {
        $raw = strtoupper(trim($raw));
        $clean = preg_replace('/[^A-Z0-9]/', '', $raw);
        return (string)($clean ?? '');
    }
}

if (!function_exists('verifyCardCredentials')) {
    if (!function_exists('ensureCardSecurityColumns')) {
        /**
         * Verify lock features का लागि schema safety (old DB compatible)
         */
        function ensureCardSecurityColumns(PDO $pdo): void {
            $cols = [
                "ALTER TABLE member_id_cards ADD COLUMN failed_verify_count INT DEFAULT 0",
                "ALTER TABLE member_id_cards ADD COLUMN locked_at TIMESTAMP NULL DEFAULT NULL",
                "ALTER TABLE member_id_cards ADD COLUMN unlock_requested TINYINT(1) DEFAULT 0",
                "ALTER TABLE member_id_cards ADD COLUMN unlock_requested_at TIMESTAMP NULL DEFAULT NULL",
            ];
            foreach ($cols as $sql) {
                try { $pdo->exec($sql); } catch (Throwable $e) { /* exists */ }
            }
        }
    }

    if (!function_exists('cardTableHasColumn')) {
        function cardTableHasColumn(PDO $pdo, string $column): bool {
            try {
                $q = $pdo->query("SHOW COLUMNS FROM member_id_cards LIKE " . $pdo->quote($column));
                return $q && (bool)$q->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                return false;
            }
        }
    }

    if (!function_exists('lockCardOnFailure')) {
        /**
         * एउटै कार्डमा 5+ गलत प्रयास भएपछि lock गर्ने
         */
        function lockCardOnFailure(PDO $pdo, int $cardId): void {
            try {
                $st = $pdo->prepare("SELECT failed_verify_count FROM member_id_cards WHERE id=? LIMIT 1");
                $st->execute([$cardId]);
                $cur = (int)$st->fetchColumn();
                $next = $cur + 1;
                if ($next >= 5) {
                    $u = $pdo->prepare("UPDATE member_id_cards
                                        SET failed_verify_count=?, status='locked', locked_at=NOW()
                                        WHERE id=?");
                    $u->execute([$next, $cardId]);
                } else {
                    $u = $pdo->prepare("UPDATE member_id_cards SET failed_verify_count=? WHERE id=?");
                    $u->execute([$next, $cardId]);
                }
            } catch (Throwable $e) { /* non-fatal */ }
        }
    }

    /**
     * Public verify endpoint बाट call हुने main function।
     */
    function verifyCardCredentials(PDO $pdo, string $code, string $cvv, string $ip): array {
        ensureCardSecurityColumns($pdo);
        $rawInput = trim($code);
        $code = normalizeCardCode($rawInput);
        $lookupKey = normalizeCardLookupKey($rawInput);
        $cvv  = preg_replace('/\D/', '', $cvv);

        // Rate limit — 5 wrong attempts per IP per hour
        try {
            $rl = $pdo->prepare("SELECT COUNT(*) AS cnt, MIN(created_at) AS oldest_attempt
                                  FROM card_verify_attempts
                                  WHERE ip = :ip AND success = 0
                                    AND created_at > (NOW() - INTERVAL 1 HOUR)");
            $rl->execute([':ip' => $ip]);
            $rlRow = $rl->fetch(\PDO::FETCH_ASSOC);
            if ((int)($rlRow['cnt'] ?? 0) >= 5) {
                /* retry_after = oldest attempt in window + 1 hour (Unix timestamp) */
                $oldestTs   = !empty($rlRow['oldest_attempt']) ? strtotime($rlRow['oldest_attempt']) : time();
                $retryAfter = $oldestTs + 3600;
                return [
                    'ok'           => false,
                    'error'        => 'धेरै पटक गलत प्रयास भयो। केही समय पछि पुनः प्रयास गर्नुहोस्।',
                    'rate_limited' => true,
                    'retry_after'  => $retryAfter,
                ];
            }
        } catch (Throwable $e) { /* table missing → ignore */ }

        if ((strlen($code) < 8 && strlen($lookupKey) < 8) || strlen($cvv) !== 4) {
            _logVerifyAttempt($pdo, $ip, $code, false);
            return ['ok' => false, 'error' => 'कृपया Card Code र 4-अङ्कको CVV सही प्रविष्ट गर्नुहोस्।'];
        }

        try {
            $hasFailedCol = cardTableHasColumn($pdo, 'failed_verify_count');
            $failedExpr = $hasFailedCol ? "c.failed_verify_count" : "0";
            $hasSadasyataNumber = function_exists('safeColumnExists') ? safeColumnExists('members', 'sadasyata_number') : true;
            $hasMemberCardNo = function_exists('safeColumnExists') ? safeColumnExists('members', 'member_card_no') : true;
            $hasMemberId = function_exists('safeColumnExists') ? safeColumnExists('members', 'member_id') : false;

            $memberJoinParts = [];
            $memberJoinParts[] = "CAST(m.id AS CHAR) COLLATE utf8mb4_unicode_ci = c.member_id COLLATE utf8mb4_unicode_ci";
            if ($hasSadasyataNumber) {
                $memberJoinParts[] = "m.sadasyata_number COLLATE utf8mb4_unicode_ci = c.member_id COLLATE utf8mb4_unicode_ci";
            }
            if ($hasMemberCardNo) {
                $memberJoinParts[] = "m.member_card_no COLLATE utf8mb4_unicode_ci = c.member_id COLLATE utf8mb4_unicode_ci";
            }
            if ($hasMemberId) {
                $memberJoinParts[] = "m.member_id COLLATE utf8mb4_unicode_ci = c.member_id COLLATE utf8mb4_unicode_ci";
            }
            $memberJoinSql = implode(' OR ', $memberJoinParts);

            $sql = "SELECT c.id AS card_id, c.card_no, c.verification_code, c.cvv,
                           c.issued_date, c.status, c.verify_count, {$failedExpr} AS failed_verify_count,
                           m.id AS member_pk,
                           m.sadasyata_number, m.member_card_no, m.name, m.avatar_url, m.kyc_application_id,
                           m.approval_status, m.created_at AS member_since,
                           m.card_expires_at,
                           k.full_name AS kyc_full_name, k.photo AS kyc_photo,
                           k.mobile AS kyc_mobile, k.email AS kyc_email, k.father_name AS kyc_father_name
                    FROM member_id_cards c
                    INNER JOIN members m
                       ON ({$memberJoinSql})
                    LEFT JOIN kyc_applications k ON k.id = m.kyc_application_id
                    WHERE c.verification_code = :code
                       OR REPLACE(UPPER(c.verification_code), '-', '') = :lookup_key1
                       OR REPLACE(UPPER(c.card_no), '-', '') = :lookup_key2
                    LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':code' => $code,
                ':lookup_key1' => $lookupKey,
                ':lookup_key2' => $lookupKey
            ]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[card-verify-lookup] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'सर्भर त्रुटि। पुनः प्रयास गर्नुहोस्।'];
        }

        if (!$row) {
            _logVerifyAttempt($pdo, $ip, $code, false);
            return ['ok' => false, 'error' => 'Card Code वा CVV मेल खाएन। कार्ड हेरेर पुनः प्रयास गर्नुहोस्।'];
        }

        if (!hash_equals((string) $row['cvv'], $cvv)) {
            _logVerifyAttempt($pdo, $ip, $code, false);
            lockCardOnFailure($pdo, (int)$row['card_id']);
            $remaining = max(0, 5 - ((int)($row['failed_verify_count'] ?? 0) + 1));
            if ($remaining <= 0) {
                return ['ok' => false, 'error' => 'यो कार्ड 5 पटक गलत प्रयासका कारण LOCK भएको छ। कृपया कार्यालय/Admin सँग unlock अनुरोध गर्नुहोस्।'];
            }
            return ['ok' => false, 'error' => "Card Code वा CVV मेल खाएन। बाँकी प्रयास: {$remaining}"];
        }

        if (($row['status'] ?? '') === 'locked') {
            _logVerifyAttempt($pdo, $ip, $code, false);
            return ['ok' => false, 'error' => 'यो कार्ड हाल LOCK छ। कृपया कार्यालय/Admin सँग unlock गर्नुहोस्।'];
        }
        if ($row['status'] !== 'active') {
            _logVerifyAttempt($pdo, $ip, $code, false);
            return ['ok' => false, 'error' => 'यो कार्ड अहिले निष्क्रिय (' . htmlspecialchars($row['status']) . ') छ।'];
        }
        if (($row['approval_status'] ?? '') === 'renewal_pending') {
            _logVerifyAttempt($pdo, $ip, $code, false);
            return ['ok' => false, 'error' => 'यो कार्डको म्याद सकिएको छ — सदस्यले renewal अनुरोध गर्नुपर्नेछ।'];
        }
        if (($row['approval_status'] ?? '') !== 'approved') {
            _logVerifyAttempt($pdo, $ip, $code, false);
            return ['ok' => false, 'error' => 'यो सदस्य अहिले सक्रिय अवस्थामा छैन।'];
        }
        // Expiry hard-stop (defence-in-depth — even if approval_status not yet updated)
        if (!empty($row['card_expires_at']) && strtotime($row['card_expires_at']) < time()) {
            _logVerifyAttempt($pdo, $ip, $code, false);
            return ['ok' => false, 'error' => 'यो कार्डको म्याद सकिएको छ। Renewal आवश्यक।'];
        }

        try {
            $upd = $pdo->prepare("UPDATE member_id_cards
                                  SET verify_count = verify_count + 1,
                                      failed_verify_count = 0,
                                      last_verified_at = NOW()
                                  WHERE id = :id");
            $upd->execute([':id' => $row['card_id']]);
        } catch (Throwable $e) { /* ignore */ }

        _logVerifyAttempt($pdo, $ip, $code, true);

            $displayName = trim((string)($row['kyc_full_name'] ?? ''));
            if ($displayName === '') $displayName = (string)($row['name'] ?? '');
            $displayPhoto = trim((string)($row['kyc_photo'] ?? '')); // photo source = KYC
            if ($displayPhoto === '') $displayPhoto = trim((string)($row['avatar_url'] ?? ''));

        return [
            'ok'     => true,
            'member' => [
                'id'           => (int) $row['member_pk'],
                'member_id'    => $row['sadasyata_number'] ?: ($row['member_card_no'] ?? ''),
                    'full_name'    => $displayName,
                    'photo_path'   => $displayPhoto,
                'mobile'       => (string)($row['kyc_mobile'] ?? ''),
                'email'        => (string)($row['kyc_email'] ?? ''),
                'father_name'  => (string)($row['kyc_father_name'] ?? ''),
                'member_since' => $row['member_since'] ?? '',
            ],
            'card' => [
                'card_no'           => $row['card_no'],
                'verification_code' => $row['verification_code'],
                'issued_date'       => $row['issued_date'] ?? '',
                'expires_at'        => $row['card_expires_at'] ?? '',
                'verify_count'      => (int) $row['verify_count'] + 1,
            ],
        ];
    }
}

if (!function_exists('_logVerifyAttempt')) {
    function _logVerifyAttempt(PDO $pdo, string $ip, string $code, bool $success): void {
        try {
            $st = $pdo->prepare("INSERT INTO card_verify_attempts (ip, code_tried, success) VALUES (:ip, :c, :s)");
            $st->execute([':ip' => $ip, ':c' => substr($code, 0, 20), ':s' => $success ? 1 : 0]);
        } catch (Throwable $e) { /* ignore */ }
    }
}
