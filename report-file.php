<?php
/**
 * Secure report file proxy — gates member-only reports.
 * Usage: report-file.php?id=123&dl=1
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/public-member-access.php';

try {
    $db = getDB();
} catch (Throwable $e) {
    http_response_code(503);
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$wantDownload = isset($_GET['dl']) && (string) $_GET['dl'] === '1';

if ($id < 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Report not found.';
    exit;
}

try {
    $st = $db->prepare('SELECT id, title, title_np, file_path, access_level, is_active FROM reports WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    /* Older DB without access_level — fall back */
    try {
        $st = $db->prepare('SELECT id, title, title_np, file_path, is_active FROM reports WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $row['access_level'] = 'none';
        }
    } catch (Throwable $e2) {
        $row = false;
    }
}

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Report not found.';
    exit;
}

if ((int) ($row['is_active'] ?? 0) !== 1 && !coopMemberAccessIsAdmin()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Report not found.';
    exit;
}

$level = coopAccessLevelNormalize((string) ($row['access_level'] ?? 'none'));
if ($level === 'member' && !coopMemberAccessCanOpen('member')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Access denied.';
    exit;
}

$abs = coopMemberAccessResolveAbsolutePath((string) ($row['file_path'] ?? ''));
if ($abs === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File missing.';
    exit;
}

$title = trim((string) ($row['title_np'] ?? ''));
if ($title === '') {
    $title = trim((string) ($row['title'] ?? 'report'));
}
coopMemberAccessStreamFile($abs, $title !== '' ? $title : 'report', $wantDownload);
