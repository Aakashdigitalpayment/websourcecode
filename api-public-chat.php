<?php
/**
 * Public live-chat → admin contact_messages (messages.php)
 * v12 — no HRM dependency; honeypot, length limits, IP rate-limit.
 * No login required. POST only. JSON response.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed']);
    exit;
}

$db = function_exists('getDB') ? getDB() : null;
if (!$db) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'DB unavailable']);
    exit;
}

/* ── Honeypot: bots fill this hidden field ── */
if (!empty(trim((string) ($_POST['website'] ?? '')))) {
    echo json_encode(['ok' => true]);
    exit;
}

$name    = trim((string) ($_POST['name'] ?? ''));
$contact = trim((string) ($_POST['contact'] ?? ''));
$body    = trim((string) ($_POST['body'] ?? ''));

/* ── Validation ── */
$errs = [];
if ($name === '' || mb_strlen($name) > 80) {
    $errs[] = 'नाम दिनुहोस् (≤80 अक्षर)';
}
if (mb_strlen($contact) > 120) {
    $errs[] = 'सम्पर्क धेरै लामो';
}
if ($body === '' || mb_strlen($body) < 5) {
    $errs[] = 'सन्देश कम्तीमा 5 अक्षर हुनुपर्छ';
}
if (mb_strlen($body) > 2000) {
    $errs[] = 'सन्देश ≤2000 अक्षर मात्र';
}
if ($errs) {
    echo json_encode(['ok' => false, 'msg' => implode(' • ', $errs)]);
    exit;
}

$ip = function_exists('coop_client_ip') ? coop_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

/* ── IP rate-limit: 5 messages / 10 min ── */
try {
    $st = $db->prepare(
        "SELECT COUNT(*) FROM contact_messages
         WHERE created_at > (NOW() - INTERVAL 10 MINUTE)
           AND subject LIKE ?"
    );
    $st->execute(['[Live Chat ' . $ip . ']%']);
    if ((int) $st->fetchColumn() >= 5) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'msg' => 'धेरै सन्देश। केही समय पछि पुनः प्रयास गर्नुहोस्।']);
        exit;
    }
} catch (Throwable $e) {
    /* best-effort */
}

$email = '';
$phone = '';
if ($contact !== '') {
    if (strpos($contact, '@') !== false) {
        $email = mb_substr($contact, 0, 100);
    } else {
        $phone = mb_substr($contact, 0, 20);
    }
}

$subject = '[Live Chat ' . $ip . '] ' . mb_substr($name, 0, 60);
$composed = ($contact !== '' ? "सम्पर्क: {$contact}\n" : '')
    . "IP: {$ip}\n"
    . "------\n" . $body;

try {
    $st = $db->prepare(
        'INSERT INTO contact_messages (name, email, phone, subject, message, is_read)
         VALUES (?, ?, ?, ?, ?, 0)'
    );
    $st->execute([mb_substr($name, 0, 100), $email ?: null, $phone ?: null, $subject, $composed]);
    echo json_encode([
        'ok'  => true,
        'msg' => 'धन्यवाद! तपाईंको सन्देश पठाइयो। हाम्रो टोलीले छिट्टै सम्पर्क गर्नेछ।',
    ]);
} catch (Throwable $e) {
    error_log('public-chat insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'सन्देश पठाउन सकिएन। केहीबेर पछि प्रयास गर्नुहोस्।']);
}
