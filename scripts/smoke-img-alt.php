#!/usr/bin/env php
<?php
/**
 * Smoke test: img tags should expose alt= (decorative may use alt="").
 * Run: php scripts/smoke-img-alt.php
 * Exit 0 = pass.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;
$passed = 0;

function ok(string $msg): void {
    global $passed;
    $passed++;
    echo "OK  {$msg}\n";
}
function fail(string $msg): void {
    global $failed;
    $failed++;
    echo "FAIL {$msg}\n";
}

function assertContains(string $file, string $needle, string $why): void {
    global $root;
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fail("{$file}: missing ({$why})");
        return;
    }
    $t = file_get_contents($path);
    if ($t === false || strpos($t, $needle) === false) {
        fail("{$file}: missing `{$needle}` ({$why})");
        return;
    }
    ok("{$file}: {$why}");
}

/**
 * Count <img> opening tags lacking alt=, skipping quote/PHP-aware.
 */
function countBareImgs(string $file): int {
    global $root;
    $t = file_get_contents($root . '/' . $file);
    if ($t === false) {
        return -1;
    }
    $n = 0;
    $len = strlen($t);
    $i = 0;
    while ($i < $len) {
        $pos = stripos($t, '<img', $i);
        if ($pos === false) {
            break;
        }
        // Skip if this is inside a // comment line
        $lineStart = strrpos(substr($t, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $linePrefix = substr($t, $lineStart, $pos - $lineStart);
        if (preg_match('/^\s*\/\//', $linePrefix) || strpos($linePrefix, '//') !== false) {
            $i = $pos + 4;
            continue;
        }
        $end = findHtmlTagEnd($t, $pos);
        if ($end === null) {
            break;
        }
        $tag = substr($t, $pos, $end - $pos);
        if (!preg_match('/\balt\s*=/i', $tag) && !preg_match('/aria-hidden\s*=\s*["\']true["\']/i', $tag)) {
            $n++;
        }
        $i = $end;
    }
    return $n;
}

function findHtmlTagEnd(string $t, int $start): ?int {
    $len = strlen($t);
    $i = $start + 1;
    $inQuote = null;
    $inPhp = false;
    while ($i < $len) {
        if ($inPhp) {
            if ($t[$i] === '?' && ($i + 1) < $len && $t[$i + 1] === '>') {
                $inPhp = false;
                $i += 2;
                continue;
            }
            $i++;
            continue;
        }
        $c = $t[$i];
        if ($inQuote !== null) {
            if ($c === $inQuote) {
                $inQuote = null;
            }
            $i++;
            continue;
        }
        if ($c === '<' && ($i + 1) < $len && $t[$i + 1] === '?') {
            $inPhp = true;
            $i += 2;
            continue;
        }
        if ($c === '"' || $c === "'") {
            $inQuote = $c;
            $i++;
            continue;
        }
        if ($c === '>') {
            return $i + 1;
        }
        $i++;
    }
    return null;
}

$zeroBare = [
    'member/election-vote.php',
    'admin/team.php',
    'admin/news.php',
    'admin/awards.php',
    'admin/sliders.php',
    'admin/member-of-year.php',
    'admin/committees.php',
    'admin/member-online-portal.php',
    'admin/election-results.php',
    'admin/election-candidates.php',
    'important-links.php',
    'about.php',
    'team.php',
    'index.php',
];

foreach ($zeroBare as $f) {
    $n = countBareImgs($f);
    if ($n < 0) {
        fail("{$f}: unreadable");
    } elseif ($n === 0) {
        ok("{$f}: 0 bare img");
    } else {
        fail("{$f}: {$n} img(s) missing alt");
    }
}

assertContains('member/election-vote.php', 'alt="<?php echo htmlspecialchars((string)$cd[\'name\']', 'tally photo uses candidate name');
assertContains('admin/team.php', 'class="tm-avatar-photo" alt="', 'team avatar alt');
assertContains('admin/member-online-portal.php', 'portal-avatar-lg" alt="', 'portal large avatar alt');
assertContains('important-links.php', 'alt="NRB"', 'important-links NRB logo alt');

foreach ($zeroBare as $f) {
    $cmd = 'php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        fail("{$f}: php -l failed — " . implode(' ', $out));
    } else {
        ok("{$f}: php -l");
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
