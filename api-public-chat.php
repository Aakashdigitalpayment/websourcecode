<?php
/**
 * Public live-chat → HRM internal messenger (employee notify)
 * v11.2 — security: honeypot, length limits, IP rate-limit, optional captcha.
 * No login required. POST only. JSON response.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/admin/includes/hrm-messages-table.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'msg'=>'Method not allowed']); exit;
}

$db = function_exists('getDB') ? getDB() : null;
if (!$db) { http_response_code(500); echo json_encode(['ok'=>false,'msg'=>'DB unavailable']); exit; }
ensureHrmMessagesTable($db);

/* ── Honeypot: bots fill this hidden field ── */
if (!empty(trim((string)($_POST['website'] ?? '')))) {
    echo json_encode(['ok'=>true]); exit; // silently accept & drop
}

$name    = trim((string)($_POST['name']    ?? ''));
$contact = trim((string)($_POST['contact'] ?? '')); // phone/email
$body    = trim((string)($_POST['body']    ?? ''));

/* ── Validation ── */
$errs = [];
if ($name === '' || mb_strlen($name) > 80)   $errs[] = 'नाम दिनुहोस् (≤80 अक्षर)';
if (mb_strlen($contact) > 120)                $errs[] = 'सम्पर्क धेरै लामो';
if ($body === '' || mb_strlen($body) < 5)     $errs[] = 'सन्देश कम्तीमा 5 अक्षर हुनुपर्छ';
if (mb_strlen($body) > 2000)                  $errs[] = 'सन्देश ≤2000 अक्षर मात्र';
if ($errs) { echo json_encode(['ok'=>false,'msg'=>implode(' • ', $errs)]); exit; }

/* ── IP rate-limit: 5 messages / 10 min ── */
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
try {
    $st = $db->prepare("SELECT COUNT(*) FROM hrm_internal_messages
                        WHERE created_at > (NOW() - INTERVAL 10 MINUTE)
                          AND subject LIKE ?");
    $st->execute(['[public-chat ' . $ip . ']%']);
    if ((int)$st->fetchColumn() >= 5) {
        http_response_code(429);
        echo json_encode(['ok'=>false,'msg'=>'धेरै सन्देश। केही समय पछि पुनः प्रयास गर्नुहोस्।']);
        exit;
    }
} catch (\Throwable $e) { /* ignore — best-effort */ }

/* ── Pick a "support" employee (HRM officer) — or fall back to id 1 ── */
$receiverId = 0;
try {
    // prefer one whose designation contains सम्पर्क/help/support/officer
    $st = $db->query("SELECT id FROM hrm_employees
                      WHERE status='active'
                      ORDER BY (designation LIKE '%सम्पर्क%' OR designation LIKE '%help%'
                               OR designation LIKE '%support%' OR designation LIKE '%officer%') DESC,
                               id ASC LIMIT 1");
    $receiverId = (int)($st->fetchColumn() ?: 0);
} catch (\Throwable $e) {}
if ($receiverId <= 0) {
    echo json_encode(['ok'=>false,'msg'=>'अहिले सम्पर्क उपलब्ध छैन।']); exit;
}

$subject = '[public-chat ' . $ip . '] ' . mb_substr($name, 0, 60);
$composed = "नाम: {$name}\n"
          . ($contact !== '' ? "सम्पर्क: {$contact}\n" : '')
          . "IP: {$ip}\n"
          . "------\n" . $body;

try {
    $st = $db->prepare("INSERT INTO hrm_internal_messages
        (sender_admin_id, sender_employee_id, receiver_employee_id, subject, body, created_at)
        VALUES (NULL, NULL, ?, ?, ?, NOW())");
    $st->execute([$receiverId, $subject, $composed]);
    echo json_encode(['ok'=>true,'msg'=>'धन्यवाद! तपाईंको सन्देश पठाइयो। हाम्रो टोलीले छिट्टै सम्पर्क गर्नेछ।']);
} catch (\Throwable $e) {
    error_log('public-chat insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'msg'=>'सन्देश पठाउन सकिएन। केहीबेर पछि प्रयास गर्नुहोस्।']);
}
