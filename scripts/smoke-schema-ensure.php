<?php
/**
 * Static smoke: schema ensure safety (no DB required).
 * - public schema version strings stay in sync
 * - redundant-index drops never target PRIMARY / never drop===keep
 * - program-tables must not re-add indexes we intentionally drop
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function ok(string $m): void
{
    global $passed;
    $passed++;
    echo "OK  $m\n";
}
function fail(string $m): void
{
    global $failed;
    $failed++;
    echo "FAIL  $m\n";
}

$ensure = (string) file_get_contents($root . '/includes/ensure-tables.php');
$program = (string) file_get_contents($root . '/includes/program-tables.php');

if (!preg_match("/\\\$schemaVersion = '([^']+)'/", $ensure, $m1)) {
    fail('schemaVersion assignment missing');
    $ver = '';
} else {
    $ver = $m1[1];
    ok('schemaVersion=' . $ver);
}
if (!preg_match("/\\\$_publicSchemaVersion = '([^']+)'/", $ensure, $m2)) {
    fail('_publicSchemaVersion missing');
} elseif ($m2[1] !== $ver) {
    fail("version mismatch: schemaVersion={$ver} vs public={$m2[1]}");
} else {
    ok('schemaVersion matches _publicSchemaVersion');
}

if (!str_contains($ensure, 'v13-drop-redundant-indexes-2026')) {
    fail('v13 redundant-index ensure missing');
} else {
    ok('v13 redundant-index ensure present');
}

if (!str_contains($ensure, 'function_exists(\'ensureProgramTables\')')
    || !preg_match('/ensureProgramTables\(\);\s*\} catch/', $ensure)) {
    fail('ensureProgramTables always-run missing');
} else {
    ok('ensureProgramTables always-run wired');
}

if (!str_contains($ensure, '$dropRedundantIndex')) {
    fail('dropRedundantIndex helper missing');
} else {
    ok('dropRedundantIndex helper present');
}

/* Parse redundant drop triples roughly */
if (!preg_match('/\$redundantDrops = \[(.*?)\];/s', $ensure, $block)) {
    fail('redundantDrops list missing');
} else {
    preg_match_all("/\\['([^']+)',\\s*'([^']+)',\\s*'([^']+)'\\]/", $block[1], $rows, PREG_SET_ORDER);
    if (count($rows) < 10) {
        fail('redundantDrops too few entries: ' . count($rows));
    } else {
        ok('redundantDrops count=' . count($rows));
    }
    foreach ($rows as $r) {
        [, $tbl, $drop, $keep] = $r;
        if ($drop === $keep) {
            fail("drop===keep on {$tbl}: {$drop}");
        }
        if (strcasecmp($drop, 'PRIMARY') === 0 || strcasecmp($keep, 'PRIMARY') === 0) {
            fail("PRIMARY involved on {$tbl}");
        }
    }
    ok('redundantDrops have no drop===keep / PRIMARY');
}

if (str_contains($program, 'idx_up_qr')) {
    fail('program-tables still references idx_up_qr (would re-add duplicate)');
} else {
    ok('program-tables does not re-add idx_up_qr');
}
if (str_contains($program, 'idx_pr_member') || str_contains($program, 'idx_pr_program')) {
    fail('program-tables still re-adds idx_pr_* duplicates');
} else {
    ok('program-tables does not re-add idx_pr_*');
}

$notices = (string) file_get_contents($root . '/admin/notices.php');
if (preg_match('/id="noticesTable"[\s\S]*?colspan=/', $notices)) {
    fail('noticesTable still has colspan empty row (DataTables tn/18)');
} else {
    ok('noticesTable has no colspan empty-row trap');
}

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
