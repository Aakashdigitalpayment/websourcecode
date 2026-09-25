<?php
/**
 * Pretty-URL regression checks (helpers + built-in server via router.php).
 * Run: php scripts/smoke-pretty-urls.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
$failed = 0;
$passed = 0;

function ok(string $msg): void
{
    global $passed;
    $passed++;
    echo "OK  {$msg}\n";
}
function fail(string $msg): void
{
    global $failed;
    $failed++;
    echo "FAIL {$msg}\n";
}

require_once $root . '/includes/config.php';

/* ── Helper unit checks ─────────────────────────────────────────── */
$cases = [
    ['contact.php', 'contact'],
    ['index.php', '/'],
    ['admin/index.php', 'admin/'],
    ['gallery.php?id=3', 'gallery?id=3'],
    ['about.php#history', 'about#history'],
    ['page.php?slug=privacy-policy', 'page?slug=privacy-policy'],
    ['member/login.php', 'member/login'],
];
foreach ($cases as [$in, $want]) {
    $got = coop_pretty_path($in);
    if ($got === $want) {
        ok("pretty({$in}) => {$got}");
    } else {
        fail("pretty({$in}) => {$got} want {$want}");
    }
}

if (str_ends_with(coop_url('contact.php'), '/contact')) {
    ok('coop_url contact');
} else {
    fail('coop_url contact => ' . coop_url('contact.php'));
}

$_SERVER['REQUEST_URI'] = '/contact.php';
$c = seo_canonical_url();
if (str_ends_with($c, '/contact')) {
    ok("canonical contact.php => {$c}");
} else {
    fail("canonical contact.php => {$c}");
}

$_SERVER['REQUEST_URI'] = '/admin/index.php';
$c = seo_canonical_url();
if (str_ends_with($c, '/admin/')) {
    ok("canonical admin/index.php => {$c}");
} else {
    fail("canonical admin/index.php => {$c}");
}

$_SERVER['REQUEST_URI'] = '/admin/';
$c = seo_canonical_url();
if (str_ends_with($c, '/admin/')) {
    ok("canonical /admin/ => {$c}");
} else {
    fail("canonical /admin/ => {$c}");
}

$_SERVER['SCRIPT_FILENAME'] = $root . '/contact.php';
$_SERVER['SCRIPT_NAME'] = '/contact.php';
$_SERVER['PHP_SELF'] = '/contact.php';
if (getCurrentPage() === 'contact') {
    ok('getCurrentPage contact');
} else {
    fail('getCurrentPage => ' . getCurrentPage());
}

/* ── Static source checks ───────────────────────────────────────── */
$ht = (string) file_get_contents($root . '/.htaccess');
if (str_contains($ht, 'REQUEST_FILENAME}.php -f')) {
    ok('.htaccess internal rewrite');
} else {
    fail('.htaccess internal rewrite');
}
if (str_contains($ht, 'RewriteRule ^ /%1 [R=301,L]')) {
    ok('.htaccess 301 strip');
} else {
    fail('.htaccess 301 strip');
}
if (str_contains($ht, 'DOCUMENT_ROOT}/%1 !-d')) {
    ok('.htaccess skip strip when directory exists (members.php vs members/)');
} else {
    fail('.htaccess directory conflict guard missing');
}
if (str_contains($ht, 'QUERY_STRING} !(^|&)ajax=')) {
    ok('.htaccess skip strip for ajax= downloads');
} else {
    fail('.htaccess ajax= download guard missing');
}
if (str_contains($ht, 'REQUEST_METHOD} ^(GET|HEAD)$')) {
    ok('.htaccess GET|HEAD-only redirect (POST safe)');
} else {
    fail('.htaccess GET|HEAD-only redirect missing');
}
if (str_contains($ht, 'member/session-check')) {
    ok('.htaccess session-check');
} else {
    fail('.htaccess session-check');
}
if (str_contains($ht, '!^share-og$')) {
    ok('.htaccess share-og skip');
} else {
    fail('.htaccess share-og skip');
}

$nginx = (string) file_get_contents($root . '/deploy/nginx-site.example.conf');
if (str_contains($nginx, '@extensionless') && str_contains($nginx, 'rewrite ^/(.*)$ /$1.php last')) {
    ok('nginx extensionless via named location (not raw $uri.php serve)');
} else {
    fail('nginx extensionless named location missing');
}
if (!preg_match('/try_files\s+\$uri\s+\$uri\/\s+\$uri\.php/', $nginx)) {
    ok('nginx avoids try_files $uri.php under location /');
} else {
    fail('nginx still uses unsafe try_files $uri.php');
}

$contact = (string) file_get_contents($root . '/contact.php');
if (str_contains($contact, 'action="contact.php"')) {
    ok('contact POST action keeps .php');
} else {
    fail('contact POST action');
}
if (str_contains($contact, 'coop_pretty_path') && str_contains($contact, 'sent=1')) {
    ok('contact PRG uses pretty path');
} else {
    fail('contact PRG pretty path');
}

$header = (string) file_get_contents($root . '/includes/header.php');
if (str_contains($header, "coop_url('contact.php')") && !str_contains($header, 'windsurf')) {
    ok('header coop_url clean');
} else {
    fail('header coop_url');
}
$footer = (string) file_get_contents($root . '/includes/footer.php');
if (str_contains($footer, 'api-public-chat.php') && str_contains($footer, "coop_url('contact.php')")) {
    ok('footer: pretty links + API .php kept');
} else {
    fail('footer links/API');
}

/* ── Built-in server HTTP checks ────────────────────────────────── */
$port = 8768;
$cmd = sprintf(
    'php -S 127.0.0.1:%d %s > /tmp/pretty-urls-smoke.log 2>&1',
    $port,
    escapeshellarg($root . '/router.php')
);
$proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root);
if (!is_resource($proc)) {
    fail('could not start php -S');
} else {
    usleep(600000);
    $http = static function (string $path, string $method = 'GET') use ($port): array {
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'ignore_errors' => true,
                'timeout' => 5,
                'header' => "Connection: close\r\n",
            ],
        ]);
        $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $ctx);
        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? []);
        $code = 0;
        if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
            $code = (int) $m[1];
        }
        return [$code, is_string($body) ? $body : ''];
    };

    $expect = [
        ['/', 200],
        ['/contact', 200],
        ['/gallery', 200],
        ['/about', 200],
        ['/admin', 200],
        ['/admin/', 200],
        ['/member/login', 200],
        ['/member/session-check', 403],
        ['/member/session-check.php', 403],
        ['/includes/config.php', 403],
        ['/no-such-page-xyz', 404],
        ['/sitemap.xml', 200],
        ['/robots.txt', 200],
        ['/share-og.php', 200],
    ];
    foreach ($expect as [$path, $want]) {
        [$code] = $http($path);
        if ($code === $want) {
            ok("HTTP {$path} → {$code}");
        } else {
            fail("HTTP {$path} → {$code} (want {$want})");
        }
    }

    [$code] = $http('/contact.php', 'POST');
    if (in_array($code, [200, 302, 303], true)) {
        ok("HTTP POST /contact.php → {$code}");
    } else {
        fail("HTTP POST /contact.php → {$code}");
    }

    [, $html] = $http('/contact');
    if (str_contains($html, 'action="contact.php"')) {
        ok('rendered form keeps contact.php');
    } else {
        fail('rendered form action');
    }
    if (preg_match('#href="[^"]*/contact"#', $html) || preg_match('#href="[^"]*/contact\?[^"]*"#', $html)) {
        ok('rendered nav uses /contact');
    } else {
        fail('rendered nav /contact');
    }
    if (str_contains($html, 'rel="canonical"') && str_contains($html, '/contact')) {
        ok('rendered canonical /contact');
    } else {
        fail('rendered canonical');
    }
    if (!preg_match('#href="[^"]*contact\.php"#', $html)) {
        ok('no href=contact.php in public nav (forms OK)');
    } else {
        // form action is contact.php — href specifically
        if (preg_match('#<a[^>]+href="[^"]*contact\.php"#', $html)) {
            fail('anchor still points at contact.php');
        } else {
            ok('anchors extensionless (form .php only)');
        }
    }

    proc_terminate($proc);
    proc_close($proc);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
