<?php
/**
 * सार्वजनिक फारमहरूमा KYC लाई मुख्य आधार बनाउने helper
 * member=yes भए Member ID बाट KYC खोज्छ, personal details KYC बाट लिन्छ।
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
            return $fail('KYC तालिका तयार छैन। Admin लाई सम्पर्क गर्नुहोस्।', 'KYC is not configured. Please contact the office.');
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

            return $fail('यो सदस्यता नम्बरको KYC रेकर्ड भेटिएन।', 'No KYC record found for this Member ID.');
        } catch (Exception $e) {
            error_log('verifyPublicFormKycByMemberId: ' . $e->getMessage());
            return $fail('KYC जाँच गर्दा त्रुटि भयो।', 'Could not verify against KYC.');
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
                'msg_np' => 'यो सेवा प्रयोग गर्न KYC verify (approved) हुनुपर्छ। कृपया पहिले KYC स्वीकृत गराउनुहोस्।',
                'msg_en' => 'KYC must be verified (approved) before using this service.',
            ];
        }
        return $r;
    }
}

if (!function_exists('verifyPublicFormKycTriple')) {
    function verifyPublicFormKycTriple($db, $memberIdRaw, $emailRaw, $phoneRaw)
    {
        return verifyPublicFormKycByMemberId($db, $memberIdRaw);
    }
}

if (!function_exists('loadKycRowForLoggedMemberPublic')) {
    function loadKycRowForLoggedMemberPublic($db, $loggedMember)
    {
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
