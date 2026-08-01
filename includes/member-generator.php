<?php
/**
 * Member from KYM — thin SSOT wrapper (legacy BBWW invent path removed).
 * Endpoint: admin/applications/kyc-generate-member.php
 */

if (!function_exists('generateMemberFromKyc')) {

function generateMemberFromKyc(PDO $pdo, int $kycId, int $adminId): array {
    /* Legacy BBWW path disabled — Member ID SSOT uses members.sadasyata_number */
    if (!function_exists('memberSsotUpsertMemberFromKyc')) {
        require_once __DIR__ . '/member-ssot.php';
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM kyc_applications WHERE id = ? LIMIT 1');
        $stmt->execute([$kycId]);
        $kyc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$kyc) {
            return ['ok' => false, 'message' => 'केवाइएम आवेदन फेला परेन।'];
        }
        if (($kyc['status'] ?? '') !== 'approved') {
            return ['ok' => false, 'message' => 'पहिले KYM approve गर्नुहोस्।'];
        }
        $r = memberSsotUpsertMemberFromKyc($pdo, $kyc, $adminId);
        if (empty($r['ok'])) {
            return ['ok' => false, 'message' => $r['message'] ?? 'सदस्य लिंक असफल।'];
        }
        return [
            'ok' => true,
            'member_id' => (string)($kyc['member_id'] ?? ''),
            'password' => '',
            'message' => 'BBWW generate बन्द छ। सदस्यता नं. SSOT बाट members खाता लिंक/बनाइयो'
                . (!empty($r['created']) ? ' (stub — पोर्टल पासवर्ड सेट गर्नुहोस्)।' : '।')
                . ' Portal: member-online-portal.php',
        ];
    } catch (Throwable $e) {
        error_log('generateMemberFromKyc(disabled→ssot): ' . $e->getMessage());
        return ['ok' => false, 'message' => 'त्रुटि: ' . $e->getMessage()];
    }
}

}
