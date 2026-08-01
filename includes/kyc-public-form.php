<?php
/**
 * सार्वजनिक फारमहरूमा KYC लाई मुख्य आधार बनाउने helper
 * member=yes भए Member ID बाट KYC खोज्छ, personal details KYM बाट लिन्छ।
 */

if (!function_exists('kycApplicationsMemberIdColumnPublic')) {
    function kycApplicationsMemberIdColumnPublic($db)
    {
        foreach (['member_id', 'sadasyata_number'] as $c) {
            try {
                $cc = $db->query('SHOW COLUMNS FROM kyc_applications LIKE ' . $db->quote($c));
                if ($cc && $cc->fetch(PDO::FETCH_ASSOC)) return $c;
            } catch (Exception $ignored) {
            }
        }
        return '';
    }
}

if (!function_exists('verifyPublicFormKycByMemberId')) {
    function verifyPublicFormKycByMemberId($db, $memberIdRaw)
    {
        $memberId = strtoupper(trim($memberIdRaw));
        $fail = static function ($np, $en) {
            return ['ok' => false, 'row' => null, 'msg_np' => $np, 'msg_en' => $en];
        };
        if ($memberId === '') {
            return $fail('सदस्यता नम्बर अनिवार्य छ।', 'Member ID is required.');
        }

        $col = kycApplicationsMemberIdColumnPublic($db);
        if ($col === '') {
            return $fail('केवाइएम तालिका तयार छैन। Admin लाई सम्पर्क गर्नुहोस्।', 'KYC is not configured. Please contact the office.');
        }

        try {
            $sql = "SELECT * FROM kyc_applications
                    WHERE UPPER(TRIM(CAST(`{$col}` AS CHAR))) = ?
                      AND (status IS NULL OR status != 'rejected')
                    ORDER BY (status = 'approved') DESC, id DESC
                    LIMIT 1";
            $st = $db->prepare($sql);
            $st->execute([$memberId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return ['ok' => true, 'row' => $row, 'msg_np' => '', 'msg_en' => ''];

            return $fail('यो सदस्यता नम्बरको केवाइएम रेकर्ड भेटिएन।', 'No KYC record found for this Member ID.');
        } catch (Exception $e) {
            error_log('verifyPublicFormKycByMemberId: ' . $e->getMessage());
            return $fail('केवाइएम जाँच गर्दा त्रुटि भयो।', 'Could not verify against KYC.');
        }
    }
}

if (!function_exists('verifyPublicFormKycApprovedByMemberId')) {
    function verifyPublicFormKycApprovedByMemberId($db, $memberIdRaw)
    {
        $r = verifyPublicFormKycByMemberId($db, $memberIdRaw);
        if (!$r['ok']) return $r;
        $st = strtolower(trim((string)(isset($r['row']['status']) ? $r['row']['status'] : '')));
        if ($st !== 'approved') {
            return [
                'ok' => false,
                'row' => $r['row'],
                'msg_np' => 'यो सेवा प्रयोग गर्न KYM verify (approved) हुनुपर्छ। कृपया पहिले केवाइएम स्वीकृत गराउनुहोस्।',
                'msg_en' => 'KYC must be verified (approved) before using this service.',
            ];
        }
        return $r;
    }
}

if (!function_exists('verifyPublicFormKycTriple')) {
    function verifyPublicFormKycTriple($db, $memberIdRaw, $emailRaw, $phoneRaw)
    {
        $fail = static function ($np, $en) {
            return ['ok' => false, 'row' => null, 'msg_np' => $np, 'msg_en' => $en];
        };
        if ($db instanceof PDO && function_exists('memberSsotRequireMemberIdAndMobile')) {
            $gate = memberSsotRequireMemberIdAndMobile($db, (string)$memberIdRaw, (string)$phoneRaw);
            if (empty($gate['ok'])) {
                return $fail(
                    (string)($gate['error_np'] ?? 'Member ID / मोबाइल मिलेन।'),
                    (string)($gate['error_en'] ?? 'Member ID / mobile mismatch.')
                );
            }
            $kyc = $gate['kyc'] ?? null;
            if (!$kyc && function_exists('memberSsotFindKycByMemberId')) {
                $kyc = memberSsotFindKycByMemberId($db, (string)($gate['member']['sadasyata_number'] ?? $memberIdRaw));
            }
            if ($kyc) {
                $email = strtolower(trim((string)$emailRaw));
                $kycEmail = strtolower(trim((string)($kyc['email'] ?? '')));
                if ($email !== '' && $kycEmail !== '' && $email !== $kycEmail) {
                    return $fail('Member ID सँग इमेल मिलेन।', 'Email does not match this Member ID.');
                }
                return ['ok' => true, 'row' => $kyc, 'msg_np' => '', 'msg_en' => ''];
            }
            /* Ledger match OK but no KYM yet */
            return [
                'ok' => true,
                'row' => [
                    'member_id' => memberSsotNormalizeId((string)$memberIdRaw),
                    'full_name' => (string)(($gate['member']['name'] ?? '')),
                    'mobile' => memberSsotNormalizeMobile((string)$phoneRaw),
                    'email' => strtolower(trim((string)$emailRaw)),
                    'status' => 'incomplete',
                ],
                'msg_np' => '',
                'msg_en' => '',
            ];
        }
        return verifyPublicFormKycByMemberId($db, $memberIdRaw);
    }
}

if (!function_exists('loadKycRowForLoggedMemberPublic')) {
    function loadKycRowForLoggedMemberPublic($db, $loggedMember)
    {
        if (!is_array($loggedMember)) {
            return null;
        }
        if (function_exists('memberSsotLoadLinkedKyc') && $db instanceof PDO) {
            return memberSsotLoadLinkedKyc($db, $loggedMember);
        }
        $mid = (int)(isset($loggedMember['id']) ? $loggedMember['id'] : 0);
        if ($mid < 1) return null;

        try {
            $st = $db->prepare('SELECT kyc_application_id FROM members WHERE id = ? LIMIT 1');
            $st->execute([$mid]);
            $kid = (int)($st->fetchColumn() ?: 0);
            if ($kid > 0) {
                $k = $db->prepare('SELECT * FROM kyc_applications WHERE id = ? AND (status IS NULL OR status != ?) LIMIT 1');
                $k->execute([$kid, 'rejected']);
                $row = $k->fetch(PDO::FETCH_ASSOC);
                if ($row) return $row;
            }
        } catch (Exception $ignored) {
        }

        $memId = trim((string)(isset($loggedMember['sadasyata_number']) ? $loggedMember['sadasyata_number'] : ''));
        if ($memId === '') return null;

        $v = verifyPublicFormKycByMemberId($db, $memId);
        return $v['ok'] ? $v['row'] : null;
    }
}
