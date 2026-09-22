<?php
/**
 * Router for PHP built-in server (php -S localhost:8080 router.php).
 * Mirrors Apache pretty-URL rules: /contact → contact.php when that file exists.
 * Production should use Apache .htaccess or nginx try_files — not this file.
 */
declare(strict_types=1);

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$uri = '/' . ltrim(str_replace('\\', '/', $uri), '/');
$hadSlash = ($uri !== '/' && str_ends_with($uri, '/'));
if ($hadSlash) {
    $uri = rtrim($uri, '/') ?: '/';
}
$file = __DIR__ . $uri;

$servePhp = static function (string $scriptPath, string $scriptName): bool {
    $_SERVER['SCRIPT_FILENAME'] = $scriptPath;
    $_SERVER['SCRIPT_NAME'] = $scriptName;
    $_SERVER['PHP_SELF'] = $scriptName;
    require $scriptPath;
    return true;
};

/* Block private paths FIRST (before is_file pass-through) — parity with .htaccess */
$blocked = ['/includes/', '/cache/', '/logs/', '/scripts/', '/database/', '/core/', '/deploy/'];
foreach ($blocked as $prefix) {
    if (str_starts_with($uri, $prefix) || $uri === rtrim($prefix, '/')) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}
if ($uri === '/member/session-check' || $uri === '/member/session-check.php') {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

if ($uri !== '/' && is_file($file)) {
    return false; // serve static / real file as-is
}

if ($uri === '/sitemap.xml') {
    return $servePhp(__DIR__ . '/sitemap.php', '/sitemap.php');
}
if ($uri === '/robots.txt') {
    return $servePhp(__DIR__ . '/robots.php', '/robots.php');
}
if ($uri === '/manifest.json') {
    return $servePhp(__DIR__ . '/manifest.php', '/manifest.php');
}

/* Directory → index.php (e.g. /admin/ → admin/index.php) */
if ($uri !== '/' && is_dir($file)) {
    $index = $file . '/index.php';
    if (is_file($index)) {
        return $servePhp($index, $uri . '/index.php');
    }
}

$phpCandidate = $file . '.php';
if (!str_ends_with(strtolower($uri), '.php') && is_file($phpCandidate)) {
    return $servePhp($phpCandidate, $uri . '.php');
}

if ($uri === '/' || $uri === '') {
    return $servePhp(__DIR__ . '/index.php', '/index.php');
}

http_response_code(404);
echo 'Not Found';
return true;
