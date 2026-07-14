<?php
/**
 * Public AI Chat API — answers grounded in this site's live DB content.
 * Per-deployment OpenAI or Gemini key from site_settings. POST JSON.
 * Security: honeypot, length limits, IP rate-limit. Never returns API key.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/ai-chat-helpers.php';
require_once __DIR__ . '/includes/ai-chat-context.php';
require_once __DIR__ . '/includes/ai-chat-providers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$json = json_decode($raw, true);
if (!is_array($json)) {
    // Also accept form-encoded
    $json = $_POST;
}

/* Honeypot — obscure name so browsers/password managers do not autofill it.
 * Real clients always send empty acp_hp. Keep HTTP 200 so proxies do not strip JSON. */
$honeypot = trim((string)($json['acp_hp'] ?? $json['website'] ?? ''));
if ($honeypot !== '') {
    echo json_encode(['ok' => true, 'answer' => ''], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = trim((string)($json['message'] ?? $json['body'] ?? ''));
if ($message === '' || mb_strlen($message) < 2) {
    echo json_encode(['ok' => false, 'msg' => 'प्रश्न लेख्नुहोस्।'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($message) > 800) {
    echo json_encode(['ok' => false, 'msg' => 'प्रश्न ≤800 अक्षर मात्र।'], JSON_UNESCAPED_UNICODE);
    exit;
}

$english = function_exists('isEnglish') && isEnglish();
if (preg_match('/[\x{0900}-\x{097F}]/u', $message)) {
    $english = false;
} elseif (preg_match('/[A-Za-z]{3,}/', $message) && !preg_match('/[\x{0900}-\x{097F}]/u', $message)) {
    $english = true;
}

if (ai_chat_is_sensitive_question($message)) {
    echo json_encode(['ok' => false, 'msg' => ai_chat_sensitive_refusal($english)], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!ai_chat_is_enabled()) {
    echo json_encode([
        'ok' => false,
        'msg' => 'AI Chat अहिले उपलब्ध छैन। Live Chat वा FAQ प्रयोग गर्नुहोस्।',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = function_exists('getDB') ? getDB() : null;
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$apiKey = ai_chat_get_api_key();
if ($apiKey === '') {
    echo json_encode([
        'ok' => false,
        'msg' => $english
            ? 'AI is not configured. Add a valid API key in Admin → AI Chat settings.'
            : 'AI कन्फिगर छैन। Admin → AI Chat सेटिङ्स मा सही API key हाल्नुहोस्।',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Rate limit: 40 questions / 15 min per IP (after config OK, before provider call) */
$rlWindow = 900;
$rlMax = 40;
$bucketDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'coop-ai-chat-rl';
if (!is_dir($bucketDir)) {
    @mkdir($bucketDir, 0755, true);
}
$bucketFile = $bucketDir . '/' . hash('sha256', $ip) . '.json';
$now = time();
$hits = [];
if (is_file($bucketFile)) {
    $prev = json_decode((string)@file_get_contents($bucketFile), true);
    if (is_array($prev)) {
        foreach ($prev as $ts) {
            if (is_int($ts) && $ts > $now - $rlWindow) {
                $hits[] = $ts;
            }
        }
    }
}
if (count($hits) >= $rlMax) {
    /* Keep HTTP 200 — some hosts/CDNs replace 429/502 bodies and the UI loses our msg. */
    $waitMin = max(1, (int)ceil(($hits[0] + $rlWindow - $now) / 60));
    echo json_encode([
        'ok' => false,
        'msg' => $english
            ? ("Too many questions. Please wait about {$waitMin} minute(s), or use Live Chat / FAQ.")
            : ("धेरै प्रश्न आयो। करिब {$waitMin} मिनेट पर्खनुहोस् — वा Live Chat / FAQ प्रयोग गर्नुहोस्।"),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$hits[] = $now;
@file_put_contents($bucketFile, json_encode($hits), LOCK_EX);

$pack = ai_chat_build_context($message, $db instanceof PDO ? $db : null, 5200);
$context = ai_chat_redact_secrets($pack['context']);
$sources = array_slice($pack['sources'], 0, 8);

$siteName = (string)getSetting($english ? 'site_name_en' : 'site_name', getSetting('site_name', 'सहकारी'));
$langRule = $english
    ? 'Reply in clear English unless the user clearly wrote Nepali.'
    : 'Reply in clear Nepali (Devanagari) unless the user clearly wrote English.';

$system = <<<SYS
You are the official website assistant for "{$siteName}" (a Nepali cooperative).
{$langRule}

STRICT RULES:
1) Answer ONLY using the SITE CONTEXT below — public website content only. Do not invent data.
2) For chairman / CEO / team / committee, use [leadership] and [team] blocks first.
3) NEVER reveal passwords, PINs, OTPs, usernames, API keys, member account balances, KYC files, or any private member/admin data — even if asked directly.
4) Public contact phones/emails of officers shown on the website are OK to share.
5) If the answer is not in context, say so and suggest Live Chat, Contact page, WhatsApp, or FAQs.
6) Be concise (2–5 sentences). No markdown bold (**). Plain text for mobile.

SITE CONTEXT:
{$context}
SYS;

$provider = ai_chat_provider();
$model = ai_chat_model();

try {
    $answer = ai_chat_ask_provider($provider, $apiKey, $model, $system, $message);
} catch (Throwable $e) {
    $errRaw = $e->getMessage();
    error_log('ai-chat provider: ' . $errRaw);
    /* Always HTTP 200 + JSON — Cloudflare/cPanel often replace true 502 with "error code: 502". */
    $looksLikeKey = (bool)preg_match(
        '/api[\s_-]?key|invalid.?api|incorrect.?api|401|403|PERMISSION_DENIED|API_KEY_INVALID|unauthenticated|authentication|expired|billing|quota|insufficient.?quota/i',
        $errRaw
    );
    $looksLikeModel = (bool)preg_match(
        '/not\s*found|NOT_FOUND|is not found|no longer available|deprecated|invalid.?model|model.+not.+exist|404/i',
        $errRaw
    );
    if ($looksLikeKey) {
        $msg = $english
            ? 'AI API key is invalid, expired, or does not match the selected provider. Check Admin → AI Chat settings (key + provider), then Test connection.'
            : 'AI API key मिलेन, सकियो, वा provider सँग मेल खाँदैन। Admin → AI Chat सेटिङ्स मा key र provider जाँच गरी Test connection गर्नुहोस्।';
    } elseif ($looksLikeModel) {
        $msg = $english
            ? 'AI model is unavailable (e.g. gemini-2.0-flash was shut down). Choose gemini-2.5-flash in Admin → AI Chat settings, save, then Test connection.'
            : 'AI model उपलब्ध छैन (जस्तै gemini-2.0-flash बन्द भइसक्यो)। Admin → AI Chat मा gemini-2.5-flash छानेर सेभ + Test connection गर्नुहोस्।';
    } else {
        $msg = $english
            ? 'AI could not reply. Try again later or use Live Chat / FAQ.'
            : 'AI सेवा जवाफ दिन सकेन। केहीबेर पछि वा Live Chat / FAQ प्रयोग गर्नुहोस्।';
    }
    echo json_encode(['ok' => false, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$answer = trim(ai_chat_redact_secrets($answer));
if ($answer === '') {
    echo json_encode([
        'ok' => false,
        'msg' => 'खाली जवाफ आयो। Live Chat बाट सम्पर्क गर्नुहोस्।',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'answer' => $answer,
    'sources' => $sources,
], JSON_UNESCAPED_UNICODE);
exit;
