<?php
/**
 * Smoke: contact spam guard helpers (math, honeypot, spam filter).
 * Run: php scripts/smoke-contact-guard.php
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;

function ok(string $label): void
{
    global $pass;
    $pass++;
    echo "OK  {$label}\n";
}

function fail(string $label, string $why = ''): void
{
    global $fail;
    $fail++;
    echo "FAIL  {$label}" . ($why !== '' ? " — {$why}" : '') . "\n";
}

require_once dirname(__DIR__) . '/includes/contact-spam-guard.php';

if (!function_exists('coop_contact_looks_like_spam')) {
    fail('helpers loaded');
    exit(1);
}
ok('helpers loaded');

/* Spam positives */
$spamCases = [
    'Visit https://evil.com now',
    'telegra.ph/scam-page',
    'You won a $25,000 jackpot',
    'Claim promo code FOREX now at earn.xyz',
];
foreach ($spamCases as $i => $s) {
    if (coop_contact_looks_like_spam($s)) {
        ok("spam positive #{$i}");
    } else {
        fail("spam positive #{$i}", $s);
    }
}

/* Spam negatives — normal cooperative messages */
$cleanCases = [
    'नमस्ते, बचत खाता खोल्न चाहन्छु।',
    'Please call me about my loan EMI.',
    'सदस्य नम्बर १२३४ को बारेमा सोधपुछ।',
    "sita@gmail.com\nI want to register as a member",
    "ram@outlook.com visit the branch please",
];
foreach ($cleanCases as $i => $s) {
    if (!coop_contact_looks_like_spam($s)) {
        ok("spam negative #{$i}");
    } else {
        fail("spam negative #{$i}", $s);
    }
}

/* Still catch promo + bare domain (not email) */
if (coop_contact_looks_like_spam('Please visit earn.xyz and register now')) {
    ok('spam positive bare promo domain');
} else {
    fail('spam positive bare promo domain');
}

/* Honeypot */
if (coop_contact_honeypot_tripped(['ct_hp' => 'http://bot'])) {
    ok('honeypot trips on ct_hp');
} else {
    fail('honeypot trips on ct_hp');
}
if (!coop_contact_honeypot_tripped(['ct_hp' => '', 'name' => 'Ram'])) {
    ok('honeypot clean when empty');
} else {
    fail('honeypot clean when empty');
}

/* Math challenge */
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$ch = coop_math_challenge_issue('smoke_math');
$sum = $ch['a'] + $ch['b'];
if (coop_math_challenge_verify('smoke_math', $sum)) {
    ok('math verify correct');
} else {
    fail('math verify correct');
}
$ch2 = coop_math_challenge_issue('smoke_math2');
if (!coop_math_challenge_verify('smoke_math2', $ch2['a'] + $ch2['b'] + 1)) {
    ok('math verify rejects wrong');
} else {
    fail('math verify rejects wrong');
}

/* Turnstile pass-through when not configured */
if (coop_turnstile_verify('')) {
    ok('turnstile pass-through without keys');
} else {
    fail('turnstile pass-through without keys');
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
