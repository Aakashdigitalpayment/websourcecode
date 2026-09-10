<?php
/**
 * Legacy Staff Verify URL — redirects to Registration Desk (Member ID SSOT).
 * Card number = Member ID (sadasyata_number); CVV flow retired.
 */
header('X-Robots-Tag: noindex, nofollow', true);
require_once __DIR__ . '/includes/config.php';

if (!isAdminLoggedIn()) {
    redirect(ADMIN_URL . 'index.php?redirect=' . rawurlencode('program-registration-desk.php'));
}

$params = [];
$programId = (int)($_GET['program_id'] ?? 0);
$occurrenceId = (int)($_GET['occurrence_id'] ?? 0);
if ($programId > 0) {
    $params['program_id'] = $programId;
}
if ($occurrenceId > 0) {
    $params['occurrence_id'] = $occurrenceId;
}

$target = ADMIN_URL . 'program-registration-desk.php';
if ($params) {
    $target .= '?' . http_build_query($params);
}

header('Location: ' . $target, true, 301);
exit;
