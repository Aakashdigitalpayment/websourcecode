<?php
/**
 * AAKASH SAHAKARI — Member Auto-Generator
 * v10.4 (Issues #1, #3, #4)
 *  - card_no now built via generateCardNumber() so it ALWAYS matches
 *    the prefix/format used everywhere else (admin + verify + member card).
 *  - card_expires_at = issued + 5 years saved on members row at create time.
 */

if (!function_exists('generateMemberFromKyc')) {

function _genMemberId(PDO $pdo, string $branchCode = '00', string $wardCode = '01'): string {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(member_id,'-',-1) AS UNSIGNED)),0)+1 AS next FROM members WHERE member_id LIKE :prefix");
    $prefix = sprintf('%s%s-%%', $branchCode, $wardCode);
    $stmt->execute([':prefix' => $prefix]);
    $next = (int) $stmt->fetchColumn();
    return sprintf('%s%s-%03d', $branchCode, $wardCode, $next);
}

function _genPassword(): string {
    return str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
}

function _sendCredentialsSms(string $mobile, string $memberId, string $password): bool {
    if (empty($mobile)) return false;
    if (function_exists('sendSms')) {
        $loginUrl = function_exists('getSetting') ? rtrim(getSetting('site_url', defined('SITE_URL') ? SITE_URL : ''), '/') . '/member' : '/member';
        $msg = "नमस्ते! सहकारीमा तपाईंको सदस्य खाता सक्रिय भयो। ID: {$memberId} | Password: {$password} | Login: {$loginUrl}";
        try { return (bool) sendSms($mobile, $msg); } catch (Throwable $e) { error_log('SMS failed: ' . $e->getMessage()); }
    }
    return false;
}

function _sendCredentialsEmail(string $email, string $name, string $memberId, string $password): bool {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    if (function_exists('sendMail')) {
        $siteName = function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी';
        $loginUrl = function_exists('getSetting') ? rtrim(getSetting('site_url', defined('SITE_URL') ? SITE_URL : ''), '/') . '/member/' : '/member/';
        $subject  = htmlspecialchars($siteName) . ' — सदस्य पोर्टल credentials';
        $body = "<p>नमस्ते <b>" . htmlspecialchars($name) . "</b>,</p>
            <p>तपाईंको KYC स्वीकृत भयो र सदस्य पोर्टल खाता सक्रिय गरिएको छ।</p>
            <table cellpadding='8' style='border-collapse:collapse;border:1px solid #ccc;'>
              <tr><td><b>Member ID</b></td><td>" . htmlspecialchars($memberId) . "</td></tr>
              <tr><td><b>Password</b></td><td>" . htmlspecialchars($password) . "</td></tr>
            </table>
            <p>Login: <a href='" . htmlspecialchars($loginUrl) . "'>" . htmlspecialchars($loginUrl) . "</a></p>
            <p style='color:#888;font-size:12px;'>पहिलोपटक login गरेपछि कृपया password परिवर्तन गर्नुहोस्।</p>";
        try { return (bool) sendMail($email, $subject, $body); } catch (Throwable $e) { error_log('Mail failed: ' . $e->getMessage()); }
    }
    return false;
}

function generateMemberFromKyc(PDO $pdo, int $kycId, int $adminId): array {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM kyc_applications WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $kycId]);
        $kyc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$kyc) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'KYC आवेदन फेला परेन।'];
        }
        if ($kyc['status'] !== 'approved') {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'पहिले KYC approve गर्नुहोस्, अनि मात्र member create गर्न मिल्छ।'];
        }
        if (!empty($kyc['member_id_generated'])) {
            $pdo->commit();
            return ['ok' => true,
                    'member_id' => $kyc['member_id_generated'],
                    'password'  => '••••••• (पहिले नै create भइसकेको)',
                    'message'   => 'यो KYC बाट पहिले नै सदस्य create भइसकेको छ।'];
        }

        $branchCode = str_pad((string) ($kyc['branch_code'] ?? '00'), 2, '0', STR_PAD_LEFT);
        $wardCode   = str_pad((string) ($kyc['ward_no']     ?? '01'), 2, '0', STR_PAD_LEFT);
        $memberId   = _genMemberId($pdo, $branchCode, $wardCode);
        $password   = _genPassword();
        $hash       = password_hash($password, PASSWORD_BCRYPT);

        /* v10.4: card_expires_at = NOW + 5 years (Issue #4) */
        $sql = "INSERT INTO members
                  (member_id, password_hash, full_name, full_name_np,
                   father_name, mother_name, dob, gender, citizenship_no,
                   mobile, email, address, photo_path, signature_path,
                   approval_status, kyc_id, created_at, approved_at, created_by,
                   card_expires_at)
                VALUES
                  (:mid, :hash, :fn, :fnp, :father, :mother, :dob, :gender, :cit,
                   :mobile, :email, :addr, :photo, :sig,
                   'approved', :kid, NOW(), NOW(), :admin,
                   DATE_ADD(NOW(), INTERVAL 5 YEAR))";
        $ins = $pdo->prepare($sql);
        $ins->execute([
            ':mid'    => $memberId,
            ':hash'   => $hash,
            ':fn'     => $kyc['full_name']    ?? '',
            ':fnp'    => $kyc['full_name_np'] ?? ($kyc['full_name'] ?? ''),
            ':father' => $kyc['father_name']  ?? '',
            ':mother' => $kyc['mother_name']  ?? '',
            ':dob'    => $kyc['dob']          ?? null,
            ':gender' => $kyc['gender']       ?? null,
            ':cit'    => $kyc['citizenship_no'] ?? '',
            ':mobile' => $kyc['mobile']       ?? '',
            ':email'  => $kyc['email']        ?? '',
            ':addr'   => $kyc['address']      ?? '',
            ':photo'  => $kyc['photo_path']   ?? null,
            ':sig'    => $kyc['signature_path'] ?? null,
            ':kid'    => $kycId,
            ':admin'  => $adminId,
        ]);
        $newMemberDbId = (int) $pdo->lastInsertId();

        /* v10.4: ID card with domain-prefixed card_no + verification_code */
        require_once __DIR__ . '/card-verify-helpers.php';
        $cardNo                 = generateCardNumber($newMemberDbId);
        [$verifyCode, $cvv]     = generateCardVerification($pdo);
        $card = $pdo->prepare("INSERT INTO member_id_cards
                  (member_id, card_no, verification_code, cvv, issued_date, status, created_by)
                VALUES (:mid, :card, :vcode, :cvv, CURDATE(), 'active', :admin)");
        $card->execute([
            ':mid'   => $memberId,
            ':card'  => $cardNo,
            ':vcode' => $verifyCode,
            ':cvv'   => $cvv,
            ':admin' => $adminId,
        ]);

        /* Also write card_no into members.member_card_no so admin list shows it */
        try {
            $pdo->prepare("UPDATE members SET member_card_no = :c WHERE id = :id")
                ->execute([':c' => $cardNo, ':id' => $newMemberDbId]);
        } catch (Throwable $e) { /* column may not exist on legacy installs */ }

        $upd = $pdo->prepare("UPDATE kyc_applications
                              SET member_id_generated = :mid,
                                  member_generated_at = NOW(),
                                  member_generated_by = :admin
                              WHERE id = :id");
        $upd->execute([':mid' => $memberId, ':admin' => $adminId, ':id' => $kycId]);

        $log = $pdo->prepare("INSERT INTO admin_activity_log
                  (admin_id, action, target_type, target_id, details, created_at)
                VALUES (:a, 'member.generate_from_kyc', 'kyc', :tid, :det, NOW())");
        $log->execute([
            ':a' => $adminId, ':tid' => $kycId,
            ':det' => json_encode(['member_id' => $memberId, 'card_no' => $cardNo], JSON_UNESCAPED_UNICODE),
        ]);

        $pdo->commit();

        $smsSent  = _sendCredentialsSms($kyc['mobile'] ?? '', $memberId, $password);
        $mailSent = _sendCredentialsEmail($kyc['email'] ?? '', $kyc['full_name'] ?? '', $memberId, $password);

        return [
            'ok'        => true,
            'member_id' => $memberId,
            'password'  => $password,
            'card_no'   => $cardNo,
            'sms_sent'  => $smsSent,
            'mail_sent' => $mailSent,
            'message'   => 'सदस्य खाता सफलतापूर्वक create भयो। SMS ' . ($smsSent ? 'पठाइयो' : 'पठाउन सकिएन') .
                           ', Email ' . ($mailSent ? 'पठाइयो' : 'पठाउन सकिएन') . '।',
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('generateMemberFromKyc: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'त्रुटि: ' . $e->getMessage()];
    }
}

}
