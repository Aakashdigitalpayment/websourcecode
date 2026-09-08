<?php
/**
 * Public live-chat → admin contact_messages
 * Uses shared contact-spam-guard (math, honeypot, spam, rate, optional Turnstile).
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/contact-spam-guard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = function_exists('getDB') ? getDB() : null;
if (!$db) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'DB unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$en = function_exists('isEnglish') && isEnglish();
$name    = trim((string) ($_POST['name'] ?? ''));
$contact = trim((string) ($_POST['contact'] ?? ''));
$body    = trim((string) ($_POST['body'] ?? ''));

$block = coop_contact_guard_block_reason(
    $_POST,
    [$name, $body],
    'live_chat',
    'live_chat_api',
    true
);

$mathFresh = static function () use ($en): array {
    return coop_public_form_math_payload('live_chat', $en);
};

if ($block === 'honeypot') {
    echo json_encode([
        'ok' => true,
        'msg' => $en ? 'Thank you! Your message was sent.' : 'धन्यवाद! तपाईंको सन्देश पठाइयो।',
        'math' => $mathFresh(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($block !== null) {
    if ($block === 'rate') {
        http_response_code(429);
    }
    echo json_encode([
        'ok' => false,
        'msg' => coop_public_form_guard_message($block, $en),
        'math' => $mathFresh(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$errs = [];
if ($name === '' || mb_strlen($name) > 80) {
    $errs[] = $en ? 'Enter your name (≤80 chars)' : 'नाम दिनुहोस् (≤80 अक्षर)';
}
if (mb_strlen($contact) > 120) {
    $errs[] = $en ? 'Contact is too long' : 'सम्पर्क धेरै लामो';
}
if ($body === '' || mb_strlen($body) < 5) {
    $errs[] = $en ? 'Message must be at least 5 characters' : 'सन्देश कम्तीमा 5 अक्षर हुनुपर्छ';
}
if (mb_strlen($body) > 2000) {
    $errs[] = $en ? 'Message ≤2000 characters' : 'सन्देश ≤2000 अक्षर मात्र';
}
if ($errs) {
    echo json_encode(['ok' => false, 'msg' => implode(' • ', $errs), 'math' => $mathFresh()], JSON_UNESCAPED_UNICODE);
    exit;
}

$ip = function_exists('coop_client_ip') ? coop_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

try {
    $st = $db->prepare(
        "SELECT COUNT(*) FROM contact_messages
         WHERE created_at > (NOW() - INTERVAL 1 HOUR)
           AND subject LIKE ?"
    );
    $st->execute(['[Live Chat ' . $ip . ']%']);
    if ((int) $st->fetchColumn() >= 5) {
        http_response_code(429);
        echo json_encode([
            'ok' => false,
            'msg' => coop_public_form_guard_message('rate', $en),
            'math' => $mathFresh(),
        ], JSON_UNESCAPED_UNICODE);
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
    if (function_exists('logSecurityEvent')) {
        logSecurityEvent('live_chat', 'Live chat from: ' . $name);
    }
    echo json_encode([
        'ok'  => true,
        'msg' => $en
            ? 'Thank you! Your message was sent. Our team will contact you soon.'
            : 'धन्यवाद! तपाईंको सन्देश पठाइयो। हाम्रो टोलीले छिट्टै सम्पर्क गर्नेछ।',
        'math' => $mathFresh(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('public-chat insert failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => $en ? 'Could not send. Please try again later.' : 'सन्देश पठाउन सकिएन। केहीबेर पछि प्रयास गर्नुहोस्।',
        'math' => $mathFresh(),
    ], JSON_UNESCAPED_UNICODE);
}
