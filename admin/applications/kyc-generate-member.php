<?php
/**
 * Admin endpoint: Generate Member from approved KYC
 * POST /admin/applications/kyc-generate-member.php
 * Body: kyc_id=123 (form-encoded)  + CSRF token
 *
 * v10.0 — Two-step flow: admin clicks button after KYC approve
 */
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';      // session, $pdo, requireAdmin()
require_once __DIR__ . '/../../includes/member-generator.php';

requireAdminLogin();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'message'=>'Method not allowed']);
    exit;
}

// CSRF
$token = $_POST['_csrf'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'message'=>'CSRF token invalid']);
    exit;
}

$kycId   = (int)($_POST['kyc_id'] ?? 0);
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if ($kycId <= 0 || $adminId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>'Invalid KYC ID']);
    exit;
}

$result = generateMemberFromKyc($pdo, $kycId, $adminId);
http_response_code($result['ok'] ? 200 : 400);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
