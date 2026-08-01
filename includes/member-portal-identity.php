<?php
/**
 * Member portal apply pages — resolve Member ID + linked KYM (SSOT).
 * Expects: $db (PDO), $mem (members row). Sets: $memSadasyata, $kycRow; updates $memName if set.
 */
if (!isset($db, $mem) || !is_array($mem)) {
    return;
}

$memSadasyata = function_exists('memberSsotResolveSadasyata')
    ? memberSsotResolveSadasyata($mem)
    : trim((string)($mem['sadasyata_number'] ?? ''));

$kycRow = null;
try {
    if (function_exists('memberSsotLoadLinkedKyc')) {
        $kycRow = memberSsotLoadLinkedKyc($db, $mem);
    } elseif (function_exists('loadKycRowForLoggedMemberPublic')) {
        $kycRow = loadKycRowForLoggedMemberPublic($db, $mem);
    }
} catch (Throwable $e) {
    $kycRow = null;
}

if ($kycRow) {
    if (isset($memName)) {
        $fn = trim((string)($kycRow['full_name'] ?? ''));
        if ($fn !== '') {
            $memName = $fn;
        }
    }
    if ($memSadasyata === '') {
        $memSadasyata = trim((string)($kycRow['member_id'] ?? ''));
    }
}

if (!is_array($kycRow)) {
    $kycRow = [];
}
