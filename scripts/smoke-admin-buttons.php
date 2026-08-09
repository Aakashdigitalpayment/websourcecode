#!/usr/bin/env php
<?php
/**
 * Smoke: every <button> under admin/ must declare type=button|submit|reset.
 * Prevents accidental form submits from tabs/toggles/edit actions.
 * Run: php scripts/smoke-admin-buttons.php
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

/**
 * Find <button ...> opens with PHP-aware attribute scanning.
 * @return list<array{0:int,1:int,2:string}>
 */
function scanButtonOpens(string $s): array {
    $out = [];
    $i = 0;
    $low = strtolower($s);
    $len = strlen($s);
    while (($j = strpos($low, '<button', $i)) !== false) {
        $endkw = $j + 7;
        if ($endkw < $len && !ctype_space($s[$endkw]) && $s[$endkw] !== '/' && $s[$endkw] !== '>') {
            $i = $endkw;
            continue;
        }
        $k = $endkw;
        $inS = false;
        $inD = false;
        while ($k < $len) {
            if (!$inS && !$inD && substr($s, $k, 2) === '<?') {
                $close = strpos($s, '?' . '>', $k + 2);
                if ($close === false) {
                    $k = $len;
                    break;
                }
                $k = $close + 2;
                continue;
            }
            $ch = $s[$k];
            if (!$inD && $ch === "'" && !$inS) {
                $inS = true;
                $k++;
                continue;
            }
            if (!$inS && $ch === '"' && !$inD) {
                $inD = true;
                $k++;
                continue;
            }
            if ($inS && $ch === "'") {
                $inS = false;
                $k++;
                continue;
            }
            if ($inD && $ch === '"') {
                $inD = false;
                $k++;
                continue;
            }
            if (!$inS && !$inD && $ch === '>') {
                $out[] = [$j, $k + 1, substr($s, $endkw, $k - $endkw)];
                $i = $k + 1;
                break;
            }
            $k++;
        }
        if ($k >= $len) {
            break;
        }
    }
    return $out;
}

$adminDir = $root . '/admin';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($adminDir, FilesystemIterator::SKIP_DOTS)
);

$checkedFiles = 0;
$totalButtons = 0;
$bareTotal = 0;
$sampleBare = [];

foreach ($files as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $rel = substr($path, strlen($root) + 1);
    $t = file_get_contents($path);
    if ($t === false) {
        fail("{$rel}: unreadable");
        continue;
    }
    $checkedFiles++;
    $bare = 0;
    foreach (scanButtonOpens($t) as [$start, $end, $attrs]) {
        $totalButtons++;
        if (!preg_match('/\btype\s*=/i', $attrs)) {
            $bare++;
            $bareTotal++;
            if (count($sampleBare) < 8) {
                $sampleBare[] = $rel . ': ' . substr(str_replace("\n", ' ', substr($t, $start, $end - $start)), 0, 100);
            }
        }
    }
    if ($bare > 0) {
        fail("{$rel}: {$bare} button(s) missing type=");
    }
}

if ($bareTotal === 0) {
    ok("admin/**: 0 bare buttons ({$totalButtons} buttons in {$checkedFiles} files)");
}

// Spot markers that interactive controls stayed type=button
$markers = [
    'admin/team.php' => 'type="button" class="nav-link',
    'admin/news.php' => 'type="button" class="nav-link',
    'admin/faqs.php' => 'type="button" class="nav-link',
    'admin/auctions.php' => 'type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle',
    'admin/member-activities.php' => 'type="submit" class="btn btn-success w-100"',
    'admin/member-online-portal.php' => 'type="submit" class="btn btn-success btn-sm w-100" onclick="return confirm',
];
foreach ($markers as $file => $needle) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fail("{$file}: missing");
        continue;
    }
    $t = (string) file_get_contents($path);
    if (strpos($t, $needle) === false) {
        fail("{$file}: missing marker `{$needle}`");
    } else {
        ok("{$file}: marker ok");
    }
}

foreach ($sampleBare as $s) {
    echo "  sample: {$s}\n";
}

// php -l on marker files
foreach (array_keys($markers) as $f) {
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
