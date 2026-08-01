<?php
/**
 * Member ID Single Source of Truth (SSOT)
 * ─────────────────────────────────────────────────────────────
 * Canonical key: members.sadasyata_number (= coop सदस्यता नं. / KYM member_id)
 * - members row = one person / membership
 * - kyc_applications = compliance docs linked via members.kyc_application_id
 * - portal auth = same members row (password + approval_status + is_active)
 *
 * Legacy BBWW generateMemberFromKyc is disabled — use upsert from KYM approve.
 */
declare(strict_types=1);

if (!function_exists('memberSsotNormalizeId')) {
    function memberSsotNormalizeId(?string $id): string
    {
        /* Coop Member IDs are Latin alphanumeric — normalize case for consistent match/store */
        return strtoupper(trim((string)$id));
    }
}

if (!function_exists('memberSsotResolveSadasyata')) {
    /** Prefer coop Member ID from members row (never invent from PK). */
    function memberSsotResolveSadasyata(?array $member): string
    {
        if (!$member) {
            return '';
        }
        foreach (['sadasyata_number', 'sadasyata_no'] as $k) {
            $v = trim((string)($member[$k] ?? ''));
            if ($v !== '') {
                return memberSsotNormalizeId($v);
            }
        }
        return '';
    }
}

if (!function_exists('memberSsotLoadLinkedKyc')) {
    /**
     * SSOT: linked KYM for a members row — by kyc_application_id then by sadasyata_number.
     * @param array<string,mixed> $member
     * @return array<string,mixed>|null
     */
    function memberSsotLoadLinkedKyc(PDO $db, array $member): ?array
    {
        $kid = (int)($member['kyc_application_id'] ?? 0);
        if ($kid > 0) {
            try {
                $st = $db->prepare(
                    "SELECT * FROM kyc_applications WHERE id=? AND (status IS NULL OR status <> 'rejected') LIMIT 1"
                );
                $st->execute([$kid]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            } catch (Throwable $e) { /* fall through */ }
        }
        $sid = memberSsotResolveSadasyata($member);
        if ($sid === '') {
            return null;
        }
        return memberSsotFindKycByMemberId($db, $sid);
    }
}

if (!function_exists('memberSsotHasDocPath')) {
    function memberSsotHasDocPath(?string $path): bool
    {
        $path = trim((string)$path);
        if ($path === '') {
            return false;
        }
        if (str_starts_with($path, 'data:image')) {
            return true;
        }
        return !preg_match('#^(https?:)?//#i', $path) || str_contains($path, 'uploads');
    }
}

if (!function_exists('memberSsotExistingDocFlags')) {
    /**
     * @param array<string,mixed> $prefillOrKyc
     * @return array{photo:bool,citizenship_front:bool,citizenship_back:bool,signature:bool,national_id_card:bool,left_thumb:bool,right_thumb:bool,any:bool}
     */
    function memberSsotExistingDocFlags(array $prefillOrKyc): array
    {
        $keys = ['photo', 'citizenship_front', 'citizenship_back', 'signature', 'national_id_card', 'left_thumb', 'right_thumb'];
        $out = [];
        $any = false;
        foreach ($keys as $k) {
            $ok = memberSsotHasDocPath(isset($prefillOrKyc[$k]) ? (string)$prefillOrKyc[$k] : '');
            $out[$k] = $ok;
            if ($ok) {
                $any = true;
            }
        }
        $out['any'] = $any;
        return $out;
    }
}

/** Feature flag: online KYM must use an existing members.sadasyata_number */
if (!function_exists('memberSsotKymRequiresExistingMember')) {
    function memberSsotKymRequiresExistingMember(): bool
    {
        if (defined('MEMBER_SSOT_KYM_REQUIRE_EXISTING')) {
            return (bool)MEMBER_SSOT_KYM_REQUIRE_EXISTING;
        }
        if (function_exists('getSetting')) {
            $v = strtolower(trim((string)getSetting('member_ssot_kym_require_existing', '1')));
            return !in_array($v, ['0', 'false', 'no', 'off'], true);
        }
        return true;
    }
}

if (!function_exists('memberSsotFindBySadasyata')) {
    /** @return array<string,mixed>|null */
    function memberSsotFindBySadasyata(PDO $db, string $memberId): ?array
    {
        $memberId = memberSsotNormalizeId($memberId);
        if ($memberId === '') {
            return null;
        }
        try {
            $st = $db->prepare(
                'SELECT * FROM members WHERE UPPER(TRIM(sadasyata_number)) = ? LIMIT 1'
            );
            $st->execute([$memberId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[member-ssot] findBySadasyata: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('memberSsotFindKycByMemberId')) {
    /**
     * Prefer approved, then newest non-rejected.
     * @return array<string,mixed>|null
     */
    function memberSsotFindKycByMemberId(PDO $db, string $memberId): ?array
    {
        $memberId = memberSsotNormalizeId($memberId);
        if ($memberId === '') {
            return null;
        }
        try {
            $st = $db->prepare(
                "SELECT * FROM kyc_applications
                 WHERE UPPER(TRIM(member_id)) = ?
                   AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
                   AND status <> 'rejected'
                 ORDER BY FIELD(status,'approved','pending','incomplete','partial') ASC, id DESC
                 LIMIT 1"
            );
            $st->execute([$memberId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            /* older schema without deleted_at */
            try {
                $st = $db->prepare(
                    "SELECT * FROM kyc_applications
                     WHERE UPPER(TRIM(member_id)) = ? AND status <> 'rejected'
                     ORDER BY FIELD(status,'approved','pending','incomplete','partial') ASC, id DESC
                     LIMIT 1"
                );
                $st->execute([$memberId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                return $row ?: null;
            } catch (Throwable $e2) {
                error_log('[member-ssot] findKyc: ' . $e2->getMessage());
            }
        }
        return null;
    }
}

if (!function_exists('memberSsotLinkMemberToKyc')) {
    function memberSsotLinkMemberToKyc(PDO $db, int $memberPk, int $kycId): bool
    {
        if ($memberPk < 1 || $kycId < 1) {
            return false;
        }
        try {
            $st = $db->prepare(
                'UPDATE members SET kyc_application_id = ? WHERE id = ? AND (kyc_application_id IS NULL OR kyc_application_id = 0 OR kyc_application_id <> ?)'
            );
            $st->execute([$kycId, $memberPk, $kycId]);
            /* Always force link to this KYM when called from approve */
            $st2 = $db->prepare('UPDATE members SET kyc_application_id = ? WHERE id = ?');
            $st2->execute([$kycId, $memberPk]);
            return true;
        } catch (Throwable $e) {
            error_log('[member-ssot] linkMemberToKyc: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('memberSsotAttachKycToMemberBySadasyata')) {
    /** After members import: attach matching KYM if present. */
    function memberSsotAttachKycToMemberBySadasyata(PDO $db, int $memberPk, string $sadasyata): bool
    {
        $kyc = memberSsotFindKycByMemberId($db, $sadasyata);
        if (!$kyc || empty($kyc['id'])) {
            return false;
        }
        return memberSsotLinkMemberToKyc($db, $memberPk, (int)$kyc['id']);
    }
}

if (!function_exists('memberSsotLinkMemberBySadasyataToKyc')) {
    /** After online KYM save: link members row (by Member ID) to this KYM id. */
    function memberSsotLinkMemberBySadasyataToKyc(PDO $db, string $sadasyata, int $kycId): bool
    {
        $m = memberSsotFindBySadasyata($db, $sadasyata);
        if (!$m || empty($m['id']) || $kycId < 1) {
            return false;
        }
        return memberSsotLinkMemberToKyc($db, (int)$m['id'], $kycId);
    }
}

if (!function_exists('memberSsotStatusBadgeHtml')) {
    function memberSsotStatusBadgeHtml(string $code, bool $en = false): string
    {
        $cls = memberSsotLinkStatusBadgeClass($code);
        $label = memberSsotLinkStatusLabel($code, $en);
        return '<span class="badge ' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('memberSsotBatchStatusForKycRows')) {
    /**
     * Avoid N+1 on KYM list pages.
     * @param list<array<string,mixed>> $apps
     * @return array<int,string> kyc id => status code
     */
    function memberSsotBatchStatusForKycRows(PDO $db, array $apps): array
    {
        $out = [];
        $ids = [];
        foreach ($apps as $app) {
            $mid = memberSsotNormalizeId((string)($app['member_id'] ?? ''));
            $kid = (int)($app['id'] ?? 0);
            if ($kid < 1) {
                continue;
            }
            if ($mid === '') {
                $out[$kid] = 'kym_only';
                continue;
            }
            $ids[$mid] = true;
        }
        $map = [];
        $keys = array_keys($ids);
        if ($keys) {
            try {
                $ph = implode(',', array_fill(0, count($keys), '?'));
                $st = $db->prepare(
                    "SELECT id, sadasyata_number, kyc_application_id, password_hash
                     FROM members WHERE UPPER(TRIM(sadasyata_number)) IN ($ph)"
                );
                $st->execute($keys);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $map[memberSsotNormalizeId((string)$row['sadasyata_number'])] = $row;
                }
            } catch (Throwable $e) {
                error_log('[member-ssot] batchStatus: ' . $e->getMessage());
            }
        }
        foreach ($apps as $app) {
            $kid = (int)($app['id'] ?? 0);
            if ($kid < 1 || isset($out[$kid])) {
                continue;
            }
            $mid = memberSsotNormalizeId((string)($app['member_id'] ?? ''));
            $m = $map[$mid] ?? null;
            if (!$m) {
                $out[$kid] = 'kym_only';
                continue;
            }
            $out[$kid] = empty($m['password_hash']) ? 'no_password' : 'linked';
        }
        return $out;
    }
}

if (!function_exists('memberSsotKycLinkFilterSql')) {
    /**
     * SQL fragment for KYM list link filter (table alias kyc_applications).
     * @return array{0:string,1:list<mixed>} sql + params
     */
    function memberSsotKycLinkFilterSql(string $linkFilter): array
    {
        $linkFilter = trim($linkFilter);
        if ($linkFilter === 'kym_only') {
            return [
                " AND (
                    kyc_applications.member_id IS NULL OR TRIM(kyc_applications.member_id) = ''
                    OR NOT EXISTS (
                        SELECT 1 FROM members m
                        WHERE UPPER(TRIM(m.sadasyata_number)) = UPPER(TRIM(kyc_applications.member_id))
                    )
                )",
                [],
            ];
        }
        if ($linkFilter === 'linked') {
            return [
                " AND EXISTS (
                    SELECT 1 FROM members m
                    WHERE UPPER(TRIM(m.sadasyata_number)) = UPPER(TRIM(kyc_applications.member_id))
                      AND m.kyc_application_id IS NOT NULL AND m.kyc_application_id <> 0
                      AND m.password_hash IS NOT NULL AND m.password_hash <> ''
                )",
                [],
            ];
        }
        if ($linkFilter === 'no_password') {
            return [
                " AND EXISTS (
                    SELECT 1 FROM members m
                    WHERE UPPER(TRIM(m.sadasyata_number)) = UPPER(TRIM(kyc_applications.member_id))
                      AND (m.password_hash IS NULL OR m.password_hash = '')
                )",
                [],
            ];
        }
        if ($linkFilter === 'unlinked_member') {
            /* Member row exists but kyc_application_id not set to this/any KYM */
            return [
                " AND EXISTS (
                    SELECT 1 FROM members m
                    WHERE UPPER(TRIM(m.sadasyata_number)) = UPPER(TRIM(kyc_applications.member_id))
                      AND (m.kyc_application_id IS NULL OR m.kyc_application_id = 0)
                )",
                [],
            ];
        }
        return ['', []];
    }
}

if (!function_exists('memberSsotLinkStatusLabel')) {
    /**
     * @param 'linked'|'kym_only'|'member_only'|'no_password'|'unknown' $code
     */
    function memberSsotLinkStatusLabel(string $code, bool $en = false): string
    {
        $map = [
            'linked' => $en ? 'Linked (KYM + Member)' : 'लिंक (KYM + सदस्य)',
            'kym_only' => $en ? 'KYM only (no member row)' : 'KYM मात्र (सदस्य खाता छैन)',
            'member_only' => $en ? 'Member only (no KYM)' : 'सदस्य मात्र (KYM छैन)',
            'no_password' => $en ? 'Member stub (no portal password)' : 'सदस्य stub (पोर्टल पासवर्ड छैन)',
            'unknown' => $en ? 'Unknown' : 'अज्ञात',
        ];
        return $map[$code] ?? $map['unknown'];
    }
}

if (!function_exists('memberSsotLinkStatusBadgeClass')) {
    function memberSsotLinkStatusBadgeClass(string $code): string
    {
        return match ($code) {
            'linked' => 'bg-success',
            'kym_only' => 'bg-warning text-dark',
            'member_only' => 'bg-info text-dark',
            'no_password' => 'bg-secondary',
            default => 'bg-light text-dark',
        };
    }
}

if (!function_exists('memberSsotStatusForMemberRow')) {
    /** @param array<string,mixed> $member */
    function memberSsotStatusForMemberRow(array $member): string
    {
        $linked = !empty($member['kyc_application_id']);
        $hasPass = !empty($member['password_hash']);
        if ($linked && $hasPass) {
            return 'linked';
        }
        if (!$hasPass) {
            return 'no_password';
        }
        if (!$linked) {
            return 'member_only';
        }
        return 'linked';
    }
}

if (!function_exists('memberSsotStatusForKycRow')) {
    /** @param array<string,mixed> $kyc */
    function memberSsotStatusForKycRow(PDO $db, array $kyc): string
    {
        $mid = memberSsotNormalizeId((string)($kyc['member_id'] ?? ''));
        if ($mid === '') {
            return 'kym_only';
        }
        $m = memberSsotFindBySadasyata($db, $mid);
        if (!$m) {
            return 'kym_only';
        }
        $kycId = (int)($kyc['id'] ?? 0);
        $linkedId = (int)($m['kyc_application_id'] ?? 0);
        $hasPass = !empty($m['password_hash']);
        if ($linkedId === $kycId) {
            return $hasPass ? 'linked' : 'no_password';
        }
        if ($linkedId === 0) {
            /* Same Member ID, KYM not attached yet */
            return $hasPass ? 'member_only' : 'no_password';
        }
        /* Linked to another KYM row but same Member ID — still one person */
        return $hasPass ? 'linked' : 'no_password';
    }
}

if (!function_exists('memberSsotRequireExistingMember')) {
    /**
     * Online KYM gate.
     * @return array{ok:bool,member?:array,error_np?:string,error_en?:string}
     */
    function memberSsotRequireExistingMember(PDO $db, string $memberId): array
    {
        $memberId = memberSsotNormalizeId($memberId);
        if ($memberId === '') {
            return [
                'ok' => false,
                'error_np' => 'सदस्यता नम्बर अनिवार्य छ।',
                'error_en' => 'Member ID is required.',
            ];
        }
        if (!memberSsotKymRequiresExistingMember()) {
            return ['ok' => true];
        }
        $m = memberSsotFindBySadasyata($db, $memberId);
        if (!$m) {
            return [
                'ok' => false,
                'error_np' => 'यो सदस्यता नम्बर सदस्य सूचीमा छैन। पहिले सहकारीले सदस्य दर्ता/import गर्नुपर्छ, अनि मात्र केवाइएम भर्नुहोस्।',
                'error_en' => 'This Member ID is not in the members list. The cooperative must register/import the member first, then submit KYM.',
            ];
        }
        return ['ok' => true, 'member' => $m];
    }
}

if (!function_exists('memberSsotNormalizeMobile')) {
    function memberSsotNormalizeMobile(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string)$phone) ?? '';
        if (strlen($d) > 10 && str_starts_with($d, '977')) {
            $d = substr($d, -10);
        }
        return $d;
    }
}

if (!function_exists('memberSsotPhonesMatch')) {
    function memberSsotPhonesMatch(?string $a, ?string $b): bool
    {
        $a = memberSsotNormalizeMobile($a);
        $b = memberSsotNormalizeMobile($b);
        if ($a === '' || $b === '') {
            return false;
        }
        return $a === $b || str_ends_with($a, $b) || str_ends_with($b, $a);
    }
}

if (!function_exists('memberSsotRequireMemberIdAndMobile')) {
    /**
     * Public KYM / portal attach gate: Member ID exists AND mobile matches ledger (and KYM if present).
     * @return array{ok:bool,member?:array,kyc?:?array,error_np?:string,error_en?:string}
     */
    function memberSsotRequireMemberIdAndMobile(PDO $db, string $memberId, string $mobile): array
    {
        $base = memberSsotRequireExistingMember($db, $memberId);
        if (empty($base['ok'])) {
            return $base;
        }
        $mobile = memberSsotNormalizeMobile($mobile);
        if (!preg_match('/^[0-9]{10}$/', $mobile)) {
            return [
                'ok' => false,
                'error_np' => '१० अंकको मोबाइल नम्बर राख्नुहोस् (सदस्य सूचीसँग मिल्नुपर्छ)।',
                'error_en' => 'Enter a valid 10-digit mobile that matches the members list.',
            ];
        }
        if (!memberSsotKymRequiresExistingMember()) {
            return $base + ['kyc' => null];
        }
        $m = $base['member'] ?? null;
        if (!$m) {
            return $base + ['kyc' => null];
        }
        $ledgerPhone = memberSsotNormalizeMobile((string)($m['phone'] ?? ''));
        if ($ledgerPhone === '') {
            return [
                'ok' => false,
                'error_np' => 'सदस्य सूचीमा यो Member ID को मोबाइल खाली छ। कार्यालयमा सम्पर्क गरी मोबाइल अपडेट गराउनुहोस्।',
                'error_en' => 'This Member ID has no mobile on the members list. Please ask the office to update it.',
            ];
        }
        if (!memberSsotPhonesMatch($ledgerPhone, $mobile)) {
            return [
                'ok' => false,
                'error_np' => 'Member ID र मोबाइल मिलेन। सहकारीमा दर्ता भएको मोबाइल नै प्रयोग गर्नुहोस्।',
                'error_en' => 'Member ID and mobile do not match. Use the mobile registered with the cooperative.',
            ];
        }
        $kyc = memberSsotLoadLinkedKyc($db, $m);
        if ($kyc) {
            $kycMobile = memberSsotNormalizeMobile((string)($kyc['mobile'] ?? ''));
            if ($kycMobile !== '' && !memberSsotPhonesMatch($kycMobile, $mobile)) {
                return [
                    'ok' => false,
                    'error_np' => 'यो Member ID को केवाइएममा अर्कै मोबाइल छ। गलत मान्छेको डेटा अपडेट गर्न मिल्दैन।',
                    'error_en' => 'Linked KYM has a different mobile. You cannot update another person\'s record.',
                ];
            }
        }
        return ['ok' => true, 'member' => $m, 'kyc' => $kyc];
    }
}

if (!function_exists('memberSsotMergePublicKycFillEmpty')) {
    /**
     * Public path: never overwrite non-empty DB values (text or docs).
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $incoming
     * @return array{merged:array<string,mixed>,filled:int,blocked:int}
     */
    function memberSsotMergePublicKycFillEmpty(array $existing, array $incoming): array
    {
        $docKeys = ['photo', 'citizenship_front', 'citizenship_back', 'national_id_card', 'signature', 'left_thumb', 'right_thumb'];
        $merged = $existing;
        $filled = 0;
        $blocked = 0;
        foreach ($incoming as $key => $val) {
            if ($key === 'id' || $key === 'tracking_id' || $key === 'member_id' || $key === 'status') {
                continue;
            }
            if (in_array($key, $docKeys, true)) {
                $have = memberSsotHasDocPath(isset($existing[$key]) ? (string)$existing[$key] : '');
                $new = is_string($val) ? trim($val) : '';
                if ($have) {
                    if ($new !== '' && $new !== (string)($existing[$key] ?? '')) {
                        $blocked++;
                    }
                    continue;
                }
                if ($new !== '') {
                    $merged[$key] = $new;
                    $filled++;
                }
                continue;
            }
            $cur = trim((string)($existing[$key] ?? ''));
            $new = is_array($val) ? '' : trim((string)$val);
            if ($cur !== '') {
                if ($new !== '' && $new !== $cur) {
                    $blocked++;
                }
                continue;
            }
            if ($new !== '') {
                $merged[$key] = $val;
                $filled++;
            }
        }
        return ['merged' => $merged, 'filled' => $filled, 'blocked' => $blocked];
    }
}

if (!function_exists('memberSsotPublicGateStore')) {
    function memberSsotPublicGateStore(string $memberId, string $mobile): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['public_kym_gate'] = [
            'member_id' => memberSsotNormalizeId($memberId),
            'mobile' => memberSsotNormalizeMobile($mobile),
            'at' => time(),
        ];
    }
}

if (!function_exists('memberSsotPublicGateClear')) {
    function memberSsotPublicGateClear(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['public_kym_gate']);
        }
    }
}

if (!function_exists('memberSsotPublicGateCheck')) {
    /** @return array{ok:bool,member_id?:string,mobile?:string} */
    function memberSsotPublicGateCheck(?string $memberId = null, ?string $mobile = null): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return ['ok' => false];
        }
        $g = $_SESSION['public_kym_gate'] ?? null;
        if (!is_array($g)) {
            return ['ok' => false];
        }
        $at = (int)($g['at'] ?? 0);
        if ($at < 1 || (time() - $at) > 7200) {
            memberSsotPublicGateClear();
            return ['ok' => false];
        }
        $sid = memberSsotNormalizeId((string)($g['member_id'] ?? ''));
        $mob = memberSsotNormalizeMobile((string)($g['mobile'] ?? ''));
        if ($sid === '' || $mob === '') {
            return ['ok' => false];
        }
        if ($memberId !== null && memberSsotNormalizeId($memberId) !== $sid) {
            return ['ok' => false];
        }
        if ($mobile !== null && !memberSsotPhonesMatch($mobile, $mob)) {
            return ['ok' => false];
        }
        return ['ok' => true, 'member_id' => $sid, 'mobile' => $mob];
    }
}

if (!function_exists('memberSsotPrefillEmptyOnlyFromKyc')) {
    /**
     * For public verified session: expose existing values for display/lock, mark which are locked.
     * @param array<string,mixed> $kyc
     * @return array{prefill:array<string,mixed>,locked:array<string,bool>}
     */
    function memberSsotPrefillEmptyOnlyFromKyc(array $kyc): array
    {
        $keys = [
            'full_name', 'full_name_en', 'member_id', 'dob_bs', 'dob_ad', 'gender', 'marital_status',
            'mobile', 'email', 'citizenship_no', 'citizenship_issued_date', 'citizenship_issued_place',
            'national_id_number', 'risk_category', 'occupation', 'organization_name', 'monthly_income',
            'account_type', 'branch',
            'permanent_province', 'permanent_district', 'permanent_municipality', 'permanent_ward', 'permanent_tole',
            'temporary_province', 'temporary_district', 'temporary_municipality', 'temporary_ward', 'temporary_tole',
            'photo', 'citizenship_front', 'citizenship_back', 'national_id_card', 'signature', 'left_thumb', 'right_thumb',
            'father_name', 'mother_name', 'grandfather_name', 'spouse_name',
        ];
        $prefill = [];
        $locked = [];
        foreach ($keys as $k) {
            $v = isset($kyc[$k]) ? trim((string)$kyc[$k]) : '';
            if ($v === '') {
                continue;
            }
            $prefill[$k] = (string)$kyc[$k];
            $locked[$k] = true;
        }
        return ['prefill' => $prefill, 'locked' => $locked];
    }
}

if (!function_exists('memberSsotUpsertMemberFromKyc')) {
    /**
     * On KYM approve: ensure a members row exists for kyc.member_id (SSOT).
     * Does NOT invent BBWW IDs. Creates stub without password if missing.
     *
     * @param array<string,mixed> $kyc
     * @return array{ok:bool,member_pk?:int,created?:bool,message?:string}
     */
    function memberSsotUpsertMemberFromKyc(PDO $db, array $kyc, int $adminId = 0): array
    {
        $sadasyata = memberSsotNormalizeId((string)($kyc['member_id'] ?? ''));
        $kycId = (int)($kyc['id'] ?? 0);
        if ($sadasyata === '') {
            return ['ok' => false, 'message' => 'KYM मा सदस्यता नम्बर छैन — Member ID बिना सदस्य खाता बनाइँदैन।'];
        }
        if ($kycId < 1) {
            return ['ok' => false, 'message' => 'Invalid KYM id'];
        }

        $name = trim((string)(($kyc['full_name'] ?? '') ?: ($kyc['full_name_en'] ?? '')));
        if ($name === '') {
            $name = 'Member ' . $sadasyata;
        }
        $phone = preg_replace('/[^0-9]/', '', (string)($kyc['mobile'] ?? '')) ?: null;
        $email = trim((string)($kyc['email'] ?? ''));
        $email = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;

        $existing = memberSsotFindBySadasyata($db, $sadasyata);
        if ($existing) {
            $pk = (int)$existing['id'];
            memberSsotLinkMemberToKyc($db, $pk, $kycId);
            /* Soft-fill empty contact fields from KYM */
            try {
                $db->prepare(
                    "UPDATE members SET
                        name = CASE WHEN name IS NULL OR name = '' THEN ? ELSE name END,
                        phone = CASE WHEN (phone IS NULL OR phone = '') AND ? IS NOT NULL THEN ? ELSE phone END,
                        email = CASE WHEN (email IS NULL OR email = '') AND ? IS NOT NULL THEN ? ELSE email END
                     WHERE id = ?"
                )->execute([$name, $phone, $phone, $email, $email, $pk]);
            } catch (Throwable $e) {
                error_log('[member-ssot] upsert update: ' . $e->getMessage());
            }
            return ['ok' => true, 'member_pk' => $pk, 'created' => false, 'message' => 'Existing member linked to KYM'];
        }

        /* Create stub — pending until portal password / approve */
        try {
            $cardNo = 'M-' . date('Y') . '-' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
            $st = $db->prepare(
                "INSERT INTO members
                    (name, email, phone, sadasyata_number, password_hash, member_card_no,
                     approval_status, is_active, kyc_application_id, approved_at, approved_by)
                 VALUES (?,?,?,?,NULL,?, 'pending', 1, ?, NULL, NULL)"
            );
            $st->execute([$name, $email, $phone, $sadasyata, $cardNo, $kycId]);
            $pk = (int)$db->lastInsertId();
            return [
                'ok' => true,
                'member_pk' => $pk,
                'created' => true,
                'message' => 'Member stub created from KYM (set portal password to enable login)',
            ];
        } catch (Throwable $e) {
            error_log('[member-ssot] upsert insert: ' . $e->getMessage());
            /* Race: another insert won */
            $again = memberSsotFindBySadasyata($db, $sadasyata);
            if ($again) {
                memberSsotLinkMemberToKyc($db, (int)$again['id'], $kycId);
                return ['ok' => true, 'member_pk' => (int)$again['id'], 'created' => false, 'message' => 'Linked after race'];
            }
            return ['ok' => false, 'message' => 'सदस्य खाता बनाउन सकिएन: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('memberSsotRegisterOrAttach')) {
    /**
     * Portal self-register: if sadasyata already has a stub (no password), set password
     * instead of creating a duplicate row.
     *
     * @return array{id?:int,card_no?:string,approval_status?:string,error?:string,attached?:bool}
     */
    function memberSsotRegisterOrAttach(
        PDO $db,
        string $name,
        string $email,
        string $phone,
        string $password,
        string $sadasyataNumber,
        int $kycApplicationId = 0
    ): array {
        $sadasyataNumber = memberSsotNormalizeId($sadasyataNumber);
        if ($sadasyataNumber === '') {
            return ['error' => 'सदस्यता नम्बर अनिवार्य छ।'];
        }

        $existing = memberSsotFindBySadasyata($db, $sadasyataNumber);
        if ($existing) {
            $pk = (int)$existing['id'];
            if (!empty($existing['password_hash'])) {
                return ['error' => 'यो सदस्यता नम्बर पहिले नै दर्ता छ। लगिन गर्नुहोस् वा सम्पर्क गर्नुहोस्।'];
            }
            /* Stub from KYM approve — attach password */
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $name = strip_tags(trim($name));
            $phoneClean = preg_replace('/[^0-9]/', '', $phone) ?: null;
            $emailClean = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
            try {
                $db->prepare(
                    "UPDATE members SET
                        name = ?,
                        email = COALESCE(?, email),
                        phone = COALESCE(?, phone),
                        password_hash = ?,
                        kyc_application_id = COALESCE(NULLIF(?, 0), kyc_application_id),
                        approval_status = CASE WHEN approval_status = 'rejected' THEN 'pending' ELSE approval_status END
                     WHERE id = ?"
                )->execute([
                    $name !== '' ? $name : ($existing['name'] ?? 'Member'),
                    $emailClean,
                    $phoneClean,
                    $hash,
                    $kycApplicationId,
                    $pk,
                ]);
            } catch (Throwable $e) {
                error_log('[member-ssot] register attach: ' . $e->getMessage());
                return ['error' => 'दर्ता अपडेट असफल। पुनः प्रयास गर्नुहोस्।'];
            }
            return [
                'id' => $pk,
                'card_no' => (string)($existing['member_card_no'] ?? ''),
                'approval_status' => (string)($existing['approval_status'] ?? 'pending'),
                'attached' => true,
            ];
        }

        /* No existing row — normal register (KYM must already be matched by caller) */
        if (!function_exists('memberRegister')) {
            require_once __DIR__ . '/member-auth.php';
        }
        return memberRegister($name, $email, $phone, $password, $sadasyataNumber, null, null, null, $kycApplicationId);
    }
}

if (!function_exists('memberSsotDuplicateSadasyataReport')) {
    /**
     * @return list<array{sadasyata_number:string,cnt:int,ids:string}>
     */
    function memberSsotDuplicateSadasyataReport(PDO $db): array
    {
        try {
            $st = $db->query(
                "SELECT sadasyata_number, COUNT(*) AS cnt, GROUP_CONCAT(id ORDER BY id) AS ids
                 FROM members
                 WHERE sadasyata_number IS NOT NULL AND TRIM(sadasyata_number) <> ''
                 GROUP BY sadasyata_number
                 HAVING COUNT(*) > 1
                 ORDER BY cnt DESC
                 LIMIT 500"
            );
            return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            error_log('[member-ssot] dup report: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('memberSsotEmptySadasyataCount')) {
    function memberSsotEmptySadasyataCount(PDO $db): int
    {
        try {
            return (int)$db->query(
                "SELECT COUNT(*) FROM members
                 WHERE sadasyata_number IS NULL OR TRIM(sadasyata_number) = ''"
            )->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('memberSsotTryAddUniqueIndex')) {
    /**
     * Live-safe: only add UNIQUE if no duplicates and no empty strings.
     * @return array{ok:bool,message:string}
     */
    function memberSsotTryAddUniqueIndex(PDO $db): array
    {
        $dups = memberSsotDuplicateSadasyataReport($db);
        if ($dups) {
            return [
                'ok' => false,
                'message' => 'UNIQUE थप्न सकिएन: ' . count($dups) . ' वटा दोहोरो सदस्यता नम्बर छन्। पहिले मिलाउनुहोस्।',
            ];
        }
        $empty = memberSsotEmptySadasyataCount($db);
        if ($empty > 0) {
            return [
                'ok' => false,
                'message' => "UNIQUE थप्न सकिएन: {$empty} सदस्यमा खाली सदस्यता नम्बर छ।",
            ];
        }
        try {
            /* Check if unique already exists */
            $idx = $db->query("SHOW INDEX FROM members WHERE Column_name='sadasyata_number' AND Non_unique=0");
            if ($idx && $idx->fetch()) {
                return ['ok' => true, 'message' => 'sadasyata_number मा UNIQUE पहिले नै छ।'];
            }
            $db->exec('ALTER TABLE members ADD UNIQUE INDEX uq_members_sadasyata (sadasyata_number)');
            return ['ok' => true, 'message' => 'UNIQUE INDEX uq_members_sadasyata थपियो।'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Index थप्दा त्रुटि: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('memberSsotAdminHelpHtml')) {
    function memberSsotAdminHelpHtml(string $context = 'general'): string
    {
        $title = 'एक Member ID = एक मान्छे (SSOT)';
        $flow = '<strong>Members</strong> = पातलो ledger (ID, नाम, मोबाइल, पोर्टल पासवर्ड) · '
            . '<strong>KYM</strong> = बाक्लो फाइल (पूरा फारम + कागजात + AML + approve/reject) · '
            . '<strong>Portal</strong> = लगइन मात्र · '
            . 'नयाँ व्यक्ति = सदस्यता अनुरोध → Admin ले Member ID।';
        $body = $flow;
        if ($context === 'kyc') {
            $body = 'यो पेज = <strong>KYM फाइल समीक्षा</strong> (फारम + फोटो/नागरिकता + AML) — Members ledger होइन। '
                . 'Online भरिएको डेटा पहिले यहाँ बस्छ; <strong>approve</strong> गर्दा members मा खाली name/phone/email मात्र soft-fill। '
                . 'Excel bulk = पहिले Members मा भएको ID का लागि KYM seed (नयाँ सदस्य बनाउँदैन)। '
                . 'Verify: <strong>Member ID + मोबाइल</strong> · खाली field मात्र भर्ने। '
                . 'पहिले <a href="member-import.php">Members Import</a>।';
        } elseif ($context === 'portal') {
            $body = 'पोर्टल अनुरोध: <strong>Member ID + मोबाइल</strong> members सूचीसँग मिल्नुपर्छ। यो पेजले लगइन unlock मात्र गर्छ। '
                . 'KYM approved देखिन्छ; update गरेपछि admin ले फेरि approve गर्छ।';
        } elseif ($context === 'members') {
            $body = 'यो सूची = सहकारी सदस्यता खाता (Member ID SSOT)। विस्तृत पहिचान/कागजात यहाँ होइन — <a href="kyc-applications.php">KYM</a> मा। '
                . 'CBS Excel → <a href="member-import.php">Members Import</a> · नयाँ: <a href="membership-applications.php">सदस्यता अनुरोध</a> · लगइन: <a href="member-online-portal.php">Portal unlock</a>।';
        } elseif ($context === 'import') {
            $body = 'Members CSV = <strong>सदस्यता ledger</strong> मात्र (CBS)। KYM Excel अर्कै हो — <a href="kyc-applications.php">KYM पेज</a> मा bulk; त्यसले members बनाउँदैन। '
                . 'पोर्टल verify का लागि मोबाइल सही राख्नुहोस्।';
        } elseif ($context === 'membership') {
            $body = 'नयाँ व्यक्ति (Member ID बिना) → यहाँ approve गर्दा Member ID दिनुहोस् → members stub। '
                . 'त्यसपछि Online KYM (ID+mobile) / Portal register (उही)।';
        }
        return '<div class="alert alert-info border-0 shadow-sm py-2 px-3 mb-3 member-ssot-help" role="note">'
            . '<div class="fw-semibold small mb-1"><i class="fas fa-link me-1"></i>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div class="small mb-0 text-secondary">' . $body . '</div>'
            . '</div>';
    }
}
