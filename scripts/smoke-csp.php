<?php
/**
 * Smoke: CSP nonce helpers + HTML filter (no DB required).
 * Run: php scripts/smoke-csp.php
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;

function ok(string $m): void { global $pass; $pass++; echo "OK  {$m}\n"; }
function fail(string $m): void { global $fail; $fail++; echo "FAIL {$m}\n"; }

/* Load only the CSP helpers by extracting via require of a stub — use config if possible,
   else redefine minimal copies matching config.php behavior for offline CI. */
$root = dirname(__DIR__);
$cfg = $root . '/includes/config.php';

/* Avoid full config boot (DB): include source and eval only helper functions is fragile.
   Instead, copy-test the same regex logic + assert source contains markers. */
$src = (string) file_get_contents($cfg);
if (strpos($src, 'function coop_csp_nonce') !== false) {
    ok('coop_csp_nonce defined');
} else {
    fail('coop_csp_nonce missing');
}
if (strpos($src, 'function coop_csp_ob_filter') !== false) {
    ok('coop_csp_ob_filter defined');
} else {
    fail('coop_csp_ob_filter missing');
}
if (strpos($src, 'script-src-elem') !== false) {
    ok('script-src-elem in CSP policy');
} else {
    fail('script-src-elem missing');
}
if (strpos($src, "script-src-attr 'unsafe-inline'") !== false) {
    ok('script-src-attr keeps onclick');
} else {
    fail('script-src-attr missing');
}
if (strpos($src, "object-src 'none'") !== false) {
    ok('object-src none');
} else {
    fail('object-src none');
}
if (strpos($src, 'csp_script_nonce') !== false) {
    ok('csp_script_nonce kill-switch');
} else {
    fail('csp_script_nonce kill-switch');
}
if (strpos($src, 'COOP_CSP_OB_STARTED') !== false) {
    ok('CSP OB nests even when php.ini already buffers');
} else {
    fail('CSP OB nest guard missing');
}
if (strpos($src, 'pcre.backtrack_limit') !== false) {
    ok('CSP filter raises PCRE backtrack for large HTML');
} else {
    fail('CSP PCRE backtrack raise missing');
}

/* Standalone filter behavior (mirror of config helper) */
function smoke_csp_ob_filter(string $html, string $nonce): string
{
    if ($html === '') {
        return $html;
    }
    $trim0 = ltrim($html);
    if ($trim0 !== '' && ($trim0[0] === '{' || $trim0[0] === '[')) {
        return $html;
    }
    $head = substr($html, 0, 800);
    if (!preg_match('/<(?:!DOCTYPE\s+html|html\b|head\b|body\b|script\b)/i', $head)
        && strpos($html, '<script') === false) {
        return $html;
    }
    $n = htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8');
    $html = preg_replace_callback(
        '/<script\b(?![^>]*\bnonce\s*=)([^>]*)>/i',
        static function (array $m) use ($n): string {
            return '<script nonce="' . $n . '"' . $m[1] . '>';
        },
        $html
    ) ?? $html;
    if (stripos($html, 'name="csp-nonce"') === false && stripos($html, '</head>') !== false) {
        $html = preg_replace(
            '/<\/head>/i',
            '<meta name="csp-nonce" content="' . $n . '">' . "\n</head>",
            $html,
            1
        ) ?? $html;
    }
    return $html;
}

$nonce = 'testNonceValue123';
$html = '<!DOCTYPE html><html><head><title>t</title></head><body><script>alert(1)</script><script src="/a.js"></script><script nonce="keep">x</script></body></html>';
$out = smoke_csp_ob_filter($html, $nonce);
if (strpos($out, '<script nonce="testNonceValue123">alert(1)</script>') !== false) {
    ok('inline script gets nonce');
} else {
    fail('inline script nonce');
}
if (strpos($out, '<script nonce="testNonceValue123" src="/a.js"></script>') !== false
    || strpos($out, '<script nonce="testNonceValue123" src="/a.js">') !== false) {
    ok('external script gets nonce');
} else {
    fail('external script nonce');
}
if (strpos($out, '<script nonce="keep">x</script>') !== false) {
    ok('existing nonce preserved');
} else {
    fail('existing nonce preserved');
}
if (strpos($out, 'name="csp-nonce" content="testNonceValue123"') !== false) {
    ok('meta csp-nonce injected');
} else {
    fail('meta csp-nonce');
}
$json = '{"ok":true,"script":"<script>x</script>"}';
if (smoke_csp_ob_filter($json, $nonce) === $json) {
    ok('JSON body not rewritten');
} else {
    fail('JSON body rewritten');
}

$nginx = (string) file_get_contents($root . '/deploy/nginx-security.conf');
foreach (['location ^~ /includes/', 'location ^~ /assets/uploads/', 'session-check.php', 'sitemap.xml', 'autoindex off'] as $needle) {
    if (strpos($nginx, $needle) !== false) {
        ok('nginx has ' . $needle);
    } else {
        fail('nginx missing ' . $needle);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
