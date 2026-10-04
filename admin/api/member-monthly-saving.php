<?php
/**
 * POST: set मासिक बचत (नियमित / नियमित नभएको) for one member — Registration Desk inline save.
 * Body: member_pk, monthly_saving ('1' | '0' | ''), csrf_token. Staff+ (same as recording attendance).
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth-roles.php';
require_once __DIR__ . '/../../includes/member-monthly-saving.php';
require_once __DIR__ . '/../includes/audit-log.php';

$fail = static function (int $code, string $np): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error_np' => $np], JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isAdminLoggedIn()) {
    $fail(401, 'Admin session समाप्त भयो — पेज refresh गरी पुनः login गर्नुहोस्।');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail(405, 'POST मात्र।');
}
if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $fail(403, 'सुरक्षा जाँच असफल — पेज refresh गर्नुहोस्।');
}
if (function_exists('has_role') && !has_role('staff')) {
    $fail(403, 'अनुमति छैन।');
}

$memberPk = (int)($_POST['member_pk'] ?? 0);
$parsed = coop_monthly_saving_parse((string)($_POST['monthly_saving'] ?? ''));
if ($memberPk < 1 || !$parsed['ok']) {
    $fail(422, 'अमान्य सदस्य वा मान।');
}

$res = coop_monthly_saving_set(getDB(), $memberPk, $parsed['value'], 'Registration desk');
if (empty($res['ok'])) {
    $fail(404, 'सदस्य फेला परेन वा save भएन।');
}
echo json_encode([
    'ok' => true,
    'changed' => $res['changed'],
    'value' => $res['new'],
    'label' => coop_monthly_saving_label($res['new']),
], JSON_UNESCAPED_UNICODE);
