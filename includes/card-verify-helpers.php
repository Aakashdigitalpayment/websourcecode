<?php
/**
 * ════════════════════════════════════════════════════════════
 * CARD VERIFY HELPERS — v10.5
 * ────────────────────────────────────────────────────────────
 * v10.5: Memorable derived CVV (first 3 of first name + last 4 of member ID).
 *        Vendor verify by name + member ID; CVV shown on card back.
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

if (!function_exists('pickNameForCardCvv')) {
    /**
     * Prefer a Latin-script name for CVV so vendors can type it easily.
     */
    function pickNameForCardCvv(string $primary, string $secondary = ''): string {
        $primary = trim($primary);
        $secondary = trim($secondary);
        if ($primary !== '' && preg_match('/[A-Za-z]/', $primary)) return $primary;
        if ($secondary !== '' && preg_match('/[A-Za-z]/', $secondary)) return $secondary;
        return $primary !== '' ? $primary : $secondary;
    }
}

if (!function_exists('normalizeCvvInput')) {
    function normalizeCvvInput(string $cvv): string {
        $cvv = trim($cvv);
        $cvv = preg_replace('/\s+/u', '', $cvv) ?? '';
        return $cvv;
    }
}

if (!function_exists('deriveMemberCardCvv')) {
    /**
     * Memorable CVV derived from member identity (not random).
     * Rule: first 3 characters of first name + last 4 digits of member number.
     * Example: "Sujan Sharma" + "MEM-2081-0123" → "SUJ0123"
     * Nepali names use the first 3 Unicode letters of the first word.
     */
    function deriveMemberCardCvv(string $fullName, string $memberNumber): string {
        $fullName = trim(preg_replace('/\s+/u', ' ', $fullName) ?? '');
        $parts = preg_split('/\s+/u', $fullName, 2) ?: [];
        $first = trim((string)($parts[0] ?? ''));

        // Prefer Latin letters when present (easier for vendors to type).
        $latin = strtoupper(preg_replace('/[^A-Za-z]/', '', $first) ?? '');
        if ($latin !== '') {
            $prefix = substr($latin . 'XXX', 0, 3);
        } else {
            // Devanagari / other scripts: strip digits & punctuation, take first 3 chars
            $clean = preg_replace('/[\s\d\p{P}\p{S}]+/u', '', $first) ?? '';
            $prefix = mb_substr($clean !== '' ? $clean : 'XXX', 0, 3, 'UTF-8');
            if (mb_strlen($prefix, 'UTF-8') < 3) {
                $prefix = mb_str_pad($prefix, 3, 'X', STR_PAD_RIGHT, 'UTF-8');
            }
        }

        $digits = preg_replace('/\D/', '', $memberNumber) ?? '';
        if ($digits === '') {
            $digits = '0000';
        }
        $suffix = substr(str_pad($digits, 4, '0', STR_PAD_LEFT), -4);

        return $prefix . $suffix;
    }
}

if (!function_exists('mb_str_pad')) {
    function mb_str_pad(string $input, int $padLength, string $padString = ' ', int $padType = STR_PAD_RIGHT, string $encoding = 'UTF-8'): string {
        $inputLen = mb_strlen($input, $encoding);
        if ($inputLen >= $padLength) return $input;
        $pad = str_repeat($padString, $padLength - $inputLen);
        if ($padType === STR_PAD_LEFT) return $pad . $input;
        return $input . $pad;
    }
}

if (!function_exists('normalizeMemberNameForMatch')) {
    function normalizeMemberNameForMatch(string $name): string {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if (function_exists('mb_strtolower')) {
            $name = mb_strtolower($name, 'UTF-8');
        } else {
            $name = strtolower($name);
        }
        // Drop common honorifics / noise for loose match
        $name = preg_replace('/\b(mr|mrs|ms|miss|shree|shri|श्री)\b\.?/u', '', $name) ?? $name;
        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }
}

if (!function_exists('memberNamesMatch')) {
    /**
     * Name match for vendor portal — full / first-word / safe prefix.
     * Deliberately avoids short substring matches (e.g. "sha" ≠ "Sujan Sharma").
     */
    function memberNamesMatch(string $input, string $stored): bool {
        $a = normalizeMemberNameForMatch($input);
        $b = normalizeMemberNameForMatch($stored);
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;

        $aFirst = explode(' ', $a)[0] ?? '';
        $bFirst = explode(' ', $b)[0] ?? '';
        $aFirstLen = mb_strlen($aFirst, 'UTF-8');
        $bFirstLen = mb_strlen($bFirst, 'UTF-8');

        // First-word exact match (min 3 chars; 2 chars allowed for non-Latin only)
        if ($aFirst !== '' && $aFirst === $bFirst) {
            $isLatin = (bool) preg_match('/[a-z]/u', $aFirst);
            if ((!$isLatin && $aFirstLen >= 2) || $aFirstLen >= 3) {
                return true;
            }
        }

        // Prefix of full name (must cover at least the first word of the longer side)
        $aLen = mb_strlen($a, 'UTF-8');
        $bLen = mb_strlen($b, 'UTF-8');
        if ($aLen >= 3 && $bLen >= 3) {
            if (mb_strpos($b, $a, 0, 'UTF-8') === 0 && $aLen >= $bFirstLen) {
                return true;
            }
            if (mb_strpos($a, $b, 0, 'UTF-8') === 0 && $bLen >= $aFirstLen) {
                return true;
            }
        }

        // Latin similarity only when lengths are close (avoids short false positives)
        $aLatin = preg_replace('/[^a-z]/', '', $a) ?? '';
        $bLatin = preg_replace('/[^a-z]/', '', $b) ?? '';
        if ($aLatin !== '' && $bLatin !== ''
            && strlen($aLatin) >= 4 && strlen($bLatin) >= 4
            && abs(strlen($aLatin) - strlen($bLatin)) <= 3
        ) {
            similar_text($aLatin, $bLatin, $pct);
            if ($pct >= 88) return true;
        }
        return false;
    }
}

if (!function_exists('cvvCredentialsMatch')) {
    /**
     * Accept stored CVV or newly derived CVV (case-insensitive for Latin part).
     */
    function cvvCredentialsMatch(string $inputCvv, string $storedCvv, string $derivedCvv): bool {
        $input = normalizeCvvInput($inputCvv);
        if ($input === '') return false;
        $candidates = array_filter(
            [normalizeCvvInput($storedCvv), normalizeCvvInput($derivedCvv)],
            static fn($v) => $v !== ''
        );
        foreach ($candidates as $cand) {
            $left = mb_strtoupper($input, 'UTF-8');
            $right = mb_strtoupper((string)$cand, 'UTF-8');
            if (strlen($left) !== strlen($right)) {
                // hash_equals requires equal length; different-length ≠ match
                continue;
            }
            if (hash_equals($right, $left)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('generateCardVerification')) {
    /**
     * Unique verification code + derived (or random fallback) CVV.
     * Format: <PREFIX>-XXXX-XXXX (uppercase, ambiguous chars removed).
     * Pass $fullName + $memberNumber to store memorable derived CVV.
     * @return array{0:string,1:string} [verification_code, cvv]
     */
    function generateCardVerification(PDO $pdo, ?string $fullName = null, ?string $memberNumber = null): array {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // 0/O 1/I/L removed
        $alphaLen = strlen($alphabet);
        $prefix   = getCardPrefix();

        $cvv = ($fullName !== null && $fullName !== '' && $memberNumber !== null && $memberNumber !== '')
            ? deriveMemberCardCvv($fullName, $memberNumber)
            : str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

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
        return [$fallback, $cvv];
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
                // Derived CVV = 3 name chars + 4 digits (was CHAR(4) random)
                "ALTER TABLE member_id_cards MODIFY COLUMN cvv VARCHAR(20) NULL",
            ];
            foreach ($cols as $sql) {
                try { $pdo->exec($sql); } catch (Throwable $e) { /* exists / already widened */ }
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

    if (!function_exists('_cardVerifyRateLimited')) {
        function _cardVerifyRateLimited(PDO $pdo, string $ip): ?array {
            try {
                $rl = $pdo->prepare("SELECT COUNT(*) AS cnt, MIN(created_at) AS oldest_attempt
                                      FROM card_verify_attempts
                                      WHERE ip = :ip AND success = 0
                                        AND created_at > (NOW() - INTERVAL 1 HOUR)");
                $rl->execute([':ip' => $ip]);
                $rlRow = $rl->fetch(\PDO::FETCH_ASSOC);
                if ((int)($rlRow['cnt'] ?? 0) >= 5) {
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
            return null;
        }
    }

    if (!function_exists('_cardVerifyMemberJoinSql')) {
        function _cardVerifyMemberJoinSql(PDO $pdo): string {
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
            return implode(' OR ', $memberJoinParts);
        }
    }

    if (!function_exists('_cardVerifySelectSql')) {
        function _cardVerifySelectSql(PDO $pdo): string {
            $hasFailedCol = cardTableHasColumn($pdo, 'failed_verify_count');
            $failedExpr = $hasFailedCol ? "c.failed_verify_count" : "0";
            $memberJoinSql = _cardVerifyMemberJoinSql($pdo);
            $hasMemberFullName = function_exists('safeColumnExists') && safeColumnExists('members', 'full_name');
            $memberNameExpr = $hasMemberFullName
                ? "COALESCE(NULLIF(TRIM(m.name), ''), NULLIF(TRIM(m.full_name), '')) AS name"
                : "m.name";
            return "SELECT c.id AS card_id, c.card_no, c.verification_code, c.cvv,
                           c.issued_date, c.status, c.verify_count, {$failedExpr} AS failed_verify_count,
                           m.id AS member_pk,
                           m.sadasyata_number, m.member_card_no, {$memberNameExpr}, m.avatar_url, m.kyc_application_id,
                           m.approval_status, m.created_at AS member_since,
                           m.card_expires_at,
                           k.full_name AS kyc_full_name, k.photo AS kyc_photo,
                           k.mobile AS kyc_mobile, k.email AS kyc_email, k.father_name AS kyc_father_name,
                           k.dob_bs AS kyc_dob_bs, k.dob_ad AS kyc_dob_ad
                    FROM member_id_cards c
                    INNER JOIN members m
                       ON ({$memberJoinSql})
                    LEFT JOIN kyc_applications k ON k.id = m.kyc_application_id";
        }
    }

    if (!function_exists('_cardDisplayNameFromRow')) {
        function _cardDisplayNameFromRow(array $row): string {
            return pickNameForCardCvv(
                (string)($row['kyc_full_name'] ?? ''),
                (string)($row['name'] ?? '')
            );
        }
    }

    if (!function_exists('_finalizeCardVerifySuccess')) {
        /**
         * Shared success path: bump counters, sync derived CVV, build response.
         */
        function _finalizeCardVerifySuccess(PDO $pdo, array $row, string $ip, string $logCode): array {
            $displayName = _cardDisplayNameFromRow($row);
            if ($displayName === '') {
                $displayName = trim((string)(($row['kyc_full_name'] ?? '') ?: ($row['name'] ?? '')));
            }
            $displayPhoto = trim((string)($row['kyc_photo'] ?? ''));
            if ($displayPhoto === '') $displayPhoto = trim((string)($row['avatar_url'] ?? ''));
            $memberIdDisp = (string)(($row['sadasyata_number'] ?? '') ?: ($row['member_card_no'] ?? ''));
            $derivedCvv = deriveMemberCardCvv($displayName, $memberIdDisp !== '' ? $memberIdDisp : (string)($row['member_pk'] ?? ''));

            $cardId = (int)($row['card_id'] ?? 0);
            if ($cardId > 0) {
                try {
                    $upd = $pdo->prepare("UPDATE member_id_cards
                                          SET verify_count = verify_count + 1,
                                              failed_verify_count = 0,
                                              last_verified_at = NOW(),
                                              cvv = :cvv
                                          WHERE id = :id");
                    $upd->execute([':id' => $cardId, ':cvv' => $derivedCvv]);
                } catch (Throwable $e) {
                    try {
                        $upd = $pdo->prepare("UPDATE member_id_cards
                                              SET verify_count = verify_count + 1,
                                                  failed_verify_count = 0,
                                                  last_verified_at = NOW()
                                              WHERE id = :id");
                        $upd->execute([':id' => $cardId]);
                    } catch (Throwable $e2) { /* ignore */ }
                }
            }

            _logVerifyAttempt($pdo, $ip, $logCode, true);

            return [
                'ok'     => true,
                'member' => [
                    'id'           => (int) $row['member_pk'],
                    'member_id'    => $memberIdDisp,
                    'full_name'    => $displayName,
                    'photo_path'   => $displayPhoto,
                    'mobile'       => (string)($row['kyc_mobile'] ?? ''),
                    'email'        => (string)($row['kyc_email'] ?? ''),
                    'father_name'  => (string)($row['kyc_father_name'] ?? ''),
                    'dob_bs'       => (string)($row['kyc_dob_bs'] ?? ''),
                    'dob_ad'       => (string)($row['kyc_dob_ad'] ?? ''),
                    'member_since' => $row['member_since'] ?? '',
                ],
                'card' => [
                    'card_no'           => $memberIdDisp !== '' ? $memberIdDisp : ($row['card_no'] ?? ''),
                    'legacy_card_no'    => $row['card_no'] ?? '',
                    'verification_code' => $row['verification_code'] ?? '',
                    'issued_date'       => $row['issued_date'] ?? '',
                    'expires_at'        => $row['card_expires_at'] ?? '',
                    'verify_count'      => (int)($row['verify_count'] ?? 0) + ($cardId > 0 ? 1 : 0),
                    'secret_cvv'        => $derivedCvv,
                ],
            ];
        }
    }

    if (!function_exists('_cardVerifyStatusGate')) {
        function _cardVerifyStatusGate(PDO $pdo, array $row, string $ip, string $logCode): ?array {
            if (($row['status'] ?? '') === 'locked') {
                _logVerifyAttempt($pdo, $ip, $logCode, false);
                return ['ok' => false, 'error' => 'यो कार्ड हाल LOCK छ। कृपया कार्यालय/Admin सँग unlock गर्नुहोस्।'];
            }
            if ($row['status'] !== 'active') {
                _logVerifyAttempt($pdo, $ip, $logCode, false);
                return ['ok' => false, 'error' => 'यो कार्ड अहिले निष्क्रिय (' . htmlspecialchars((string)$row['status']) . ') छ।'];
            }
            if (($row['approval_status'] ?? '') === 'renewal_pending') {
                _logVerifyAttempt($pdo, $ip, $logCode, false);
                return ['ok' => false, 'error' => 'यो कार्डको म्याद सकिएको छ — सदस्यले renewal अनुरोध गर्नुपर्नेछ।'];
            }
            if (($row['approval_status'] ?? '') !== 'approved') {
                _logVerifyAttempt($pdo, $ip, $logCode, false);
                return ['ok' => false, 'error' => 'यो सदस्य अहिले सक्रिय अवस्थामा छैन।'];
            }
            if (!empty($row['card_expires_at']) && strtotime($row['card_expires_at']) < time()) {
                _logVerifyAttempt($pdo, $ip, $logCode, false);
                return ['ok' => false, 'error' => 'यो कार्डको म्याद सकिएको छ। Renewal आवश्यक।'];
            }
            return null;
        }
    }

    /**
     * Legacy path: verification code / card_no + CVV.
     * CVV may be old 4-digit OR new derived (name3+member4).
     */
    function verifyCardCredentials(PDO $pdo, string $code, string $cvv, string $ip): array {
        ensureCardSecurityColumns($pdo);
        $rawInput = trim($code);
        $code = normalizeCardCode($rawInput);
        $lookupKey = normalizeCardLookupKey($rawInput);
        $cvvInput = normalizeCvvInput($cvv);

        $rl = _cardVerifyRateLimited($pdo, $ip);
        if ($rl !== null) return $rl;

        if ((strlen($code) < 8 && strlen($lookupKey) < 8) || $cvvInput === '') {
            _logVerifyAttempt($pdo, $ip, $code, false);
            return ['ok' => false, 'error' => 'कृपया Card Code र CVV सही प्रविष्ट गर्नुहोस्।'];
        }

        try {
            $sql = _cardVerifySelectSql($pdo) . "
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

        $displayName = _cardDisplayNameFromRow($row);
        $memberIdDisp = (string)(($row['sadasyata_number'] ?? '') ?: ($row['member_card_no'] ?? ''));
        $derivedCvv = deriveMemberCardCvv($displayName, $memberIdDisp !== '' ? $memberIdDisp : (string)($row['member_pk'] ?? ''));

        if (!cvvCredentialsMatch($cvvInput, (string)($row['cvv'] ?? ''), $derivedCvv)) {
            _logVerifyAttempt($pdo, $ip, $code, false);
            lockCardOnFailure($pdo, (int)$row['card_id']);
            $remaining = max(0, 5 - ((int)($row['failed_verify_count'] ?? 0) + 1));
            if ($remaining <= 0) {
                return ['ok' => false, 'error' => 'यो कार्ड 5 पटक गलत प्रयासका कारण LOCK भएको छ। कृपया कार्यालय/Admin सँग unlock अनुरोध गर्नुहोस्।'];
            }
            return ['ok' => false, 'error' => "Card Code वा CVV मेल खाएन। बाँकी प्रयास: {$remaining}"];
        }

        $gate = _cardVerifyStatusGate($pdo, $row, $ip, $code);
        if ($gate !== null) return $gate;

        return _finalizeCardVerifySuccess($pdo, $row, $ip, $code);
    }

    /**
     * Primary vendor path: member name + member ID (CVV optional).
     * On match, derived CVV is revealed like a secret tracker code.
     */
    function verifyCardByNameAndMemberId(PDO $pdo, string $name, string $memberId, string $ip, string $optionalCvv = ''): array {
        ensureCardSecurityColumns($pdo);
        $name = trim($name);
        $memberId = trim($memberId);
        $optionalCvv = normalizeCvvInput($optionalCvv);

        $rl = _cardVerifyRateLimited($pdo, $ip);
        if ($rl !== null) return $rl;

        if ($name === '' || $memberId === '') {
            _logVerifyAttempt($pdo, $ip, $memberId, false);
            return ['ok' => false, 'error' => 'कृपया सदस्यको नाम र सदस्यता नं. दुवै प्रविष्ट गर्नुहोस्।'];
        }

        try {
            $sql = _cardVerifySelectSql($pdo) . "
                    WHERE m.sadasyata_number = :mid1
                       OR m.member_card_no = :mid2
                       OR CAST(m.id AS CHAR) = :mid3
                       OR c.member_id = :mid4
                    ORDER BY c.id DESC
                    LIMIT 5";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':mid1' => $memberId,
                ':mid2' => $memberId,
                ':mid3' => $memberId,
                ':mid4' => $memberId,
            ]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[card-verify-name-mid] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'सर्भर त्रुटि। पुनः प्रयास गर्नुहोस्।'];
        }

        // Fallback: approved member exists but card row not yet created
        if (!$rows) {
            try {
                $hasMemberFullName = function_exists('safeColumnExists') && safeColumnExists('members', 'full_name');
                $nameSelect = $hasMemberFullName
                    ? "COALESCE(NULLIF(TRIM(m.name), ''), NULLIF(TRIM(m.full_name), '')) AS name"
                    : "m.name";
                $mst = $pdo->prepare(
                    "SELECT 0 AS card_id, NULL AS card_no, NULL AS verification_code, NULL AS cvv,
                            NULL AS issued_date, 'active' AS status, 0 AS verify_count, 0 AS failed_verify_count,
                            m.id AS member_pk,
                            m.sadasyata_number, m.member_card_no, {$nameSelect}, m.avatar_url, m.kyc_application_id,
                            m.approval_status, m.created_at AS member_since,
                            m.card_expires_at,
                            k.full_name AS kyc_full_name, k.photo AS kyc_photo,
                            k.mobile AS kyc_mobile, k.email AS kyc_email, k.father_name AS kyc_father_name,
                            k.dob_bs AS kyc_dob_bs, k.dob_ad AS kyc_dob_ad
                     FROM members m
                     LEFT JOIN kyc_applications k ON k.id = m.kyc_application_id
                     WHERE m.sadasyata_number = :mid1
                        OR m.member_card_no = :mid2
                        OR CAST(m.id AS CHAR) = :mid3
                     LIMIT 1"
                );
                $mst->execute([
                    ':mid1' => $memberId,
                    ':mid2' => $memberId,
                    ':mid3' => $memberId,
                ]);
                $fallback = $mst->fetch(PDO::FETCH_ASSOC);
                if ($fallback) {
                    $rows = [$fallback];
                }
            } catch (Throwable $e) {
                error_log('[card-verify-member-fallback] ' . $e->getMessage());
            }
        }

        if (!$rows) {
            _logVerifyAttempt($pdo, $ip, $memberId, false);
            return ['ok' => false, 'error' => 'नाम वा सदस्यता नं. मेल खाएन। कार्ड हेरेर पुनः प्रयास गर्नुहोस्।'];
        }

        $row = null;
        foreach ($rows as $cand) {
            $candName = _cardDisplayNameFromRow($cand);
            if ($candName === '') {
                $candName = trim((string)(($cand['kyc_full_name'] ?? '') ?: ($cand['name'] ?? '')));
            }
            if (memberNamesMatch($name, $candName)) {
                $row = $cand;
                break;
            }
        }

        if ($row === null) {
            _logVerifyAttempt($pdo, $ip, $memberId, false);
            if (!empty($rows[0]['card_id'])) {
                lockCardOnFailure($pdo, (int)$rows[0]['card_id']);
            }
            $remaining = max(0, 5 - ((int)($rows[0]['failed_verify_count'] ?? 0) + 1));
            if ($remaining <= 0 && !empty($rows[0]['card_id'])) {
                return ['ok' => false, 'error' => 'यो कार्ड 5 पटक गलत प्रयासका कारण LOCK भएको छ। कृपया कार्यालय/Admin सँग unlock अनुरोध गर्नुहोस्।'];
            }
            return ['ok' => false, 'error' => "नाम वा सदस्यता नं. मेल खाएन। बाँकी प्रयास: {$remaining}"];
        }

        $displayName = _cardDisplayNameFromRow($row);
        if ($displayName === '') {
            $displayName = trim((string)(($row['kyc_full_name'] ?? '') ?: ($row['name'] ?? '')));
        }
        $memberIdDisp = (string)(($row['sadasyata_number'] ?? '') ?: ($row['member_card_no'] ?? ''));
        $derivedCvv = deriveMemberCardCvv($displayName, $memberIdDisp !== '' ? $memberIdDisp : (string)($row['member_pk'] ?? ''));

        if ($optionalCvv !== '' && !cvvCredentialsMatch($optionalCvv, (string)($row['cvv'] ?? ''), $derivedCvv)) {
            _logVerifyAttempt($pdo, $ip, $memberId, false);
            if (!empty($row['card_id'])) {
                lockCardOnFailure($pdo, (int)$row['card_id']);
            }
            $remaining = max(0, 5 - ((int)($row['failed_verify_count'] ?? 0) + 1));
            if ($remaining <= 0 && !empty($row['card_id'])) {
                return ['ok' => false, 'error' => 'यो कार्ड 5 पटक गलत प्रयासका कारण LOCK भएको छ। कृपया कार्यालय/Admin सँग unlock अनुरोध गर्नुहोस्।'];
            }
            return ['ok' => false, 'error' => "CVV मेल खाएन। बाँकी प्रयास: {$remaining}"];
        }

        $gate = _cardVerifyStatusGate($pdo, $row, $ip, $memberId);
        if ($gate !== null) return $gate;

        // Lazily create card so future legacy / admin views stay consistent
        if ((int)($row['card_id'] ?? 0) <= 0 && !empty($row['member_pk'])) {
            try {
                [$vCode, $gCvv] = generateCardVerification($pdo, $displayName, $memberIdDisp !== '' ? $memberIdDisp : (string)$row['member_pk']);
                $newCardNo = generateCardNumber((int)$row['member_pk']);
                $ins = $pdo->prepare(
                    "INSERT INTO member_id_cards
                        (member_id, card_no, verification_code, cvv, issued_date, status)
                     VALUES (:mid, :card, :vcode, :cvv, CURDATE(), 'active')"
                );
                $ins->execute([
                    ':mid'   => (string)(($row['sadasyata_number'] ?? '') ?: $row['member_pk']),
                    ':card'  => $newCardNo,
                    ':vcode' => $vCode,
                    ':cvv'   => $gCvv,
                ]);
                $row['card_id'] = (int)$pdo->lastInsertId();
                $row['card_no'] = $newCardNo;
                $row['verification_code'] = $vCode;
                $row['cvv'] = $gCvv;
                $row['issued_date'] = date('Y-m-d');
                $row['verify_count'] = 0;
            } catch (Throwable $e) {
                error_log('[card-verify-autocreate] ' . $e->getMessage());
            }
        }

        return _finalizeCardVerifySuccess($pdo, $row, $ip, $memberId);
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
