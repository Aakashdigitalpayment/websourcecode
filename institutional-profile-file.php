<?php
/**
 * Secure institutional-profile attachment proxy — gates member-only docs.
 * Usage: institutional-profile-file.php?id=123&dl=1
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/public-member-access.php';

$id = (int) ($_GET['id'] ?? 0);
$wantDownload = isset($_GET['dl']) && (string) $_GET['dl'] === '1';

if ($id < 1) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Document not found.';
    exit;
}

/* Cold Facebook/Instagram taps: land on HTML page before any DB/PDF work */
coopMemberAccessBounceSocialInAppToPage('institutional-profile.php', $id);

/* Keep inline for View — Android FB attachment handoff drops cookies (Access denied). */

try {
    $db = getDB();
} catch (Throwable $e) {
    http_response_code(503);
    exit;
}

$row = false;
$hasAccessCol = true;
try {
    $st = $db->prepare(
        'SELECT id, fiscal_year, attachment_path, access_level, is_active
         FROM institutional_profile WHERE id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $hasAccessCol = false;
    try {
        $st = $db->prepare(
            'SELECT id, fiscal_year, attachment_path, is_active
             FROM institutional_profile WHERE id = ? LIMIT 1'
        );
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        $row = false;
    }
}

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Document not found.';
    exit;
}

if (!$hasAccessCol && !array_key_exists('access_level', $row)) {
    if (!coopMemberAccessIsAdmin()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access configuration unavailable. Please try again shortly.';
        exit;
    }
    $row['access_level'] = 'none';
}

if ((int) ($row['is_active'] ?? 0) !== 1 && !coopMemberAccessIsAdmin()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Document not found.';
    exit;
}

$level = coopAccessLevelNormalize((string) ($row['access_level'] ?? 'none'));
$tokenOk = coopMemberAccessCheckFileToken($id, isset($_GET['t']) ? (string) $_GET['t'] : null);
if ($level === 'member' && !coopMemberAccessCanOpen('member') && !$tokenOk) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Access denied.';
    exit;
}

$abs = coopMemberAccessResolveAbsolutePath((string) ($row['attachment_path'] ?? ''));
if ($abs === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File missing.';
    exit;
}

$fy = preg_replace('/[^\d\/\-]/', '', (string) ($row['fiscal_year'] ?? '')) ?: 'profile';
coopMemberAccessStreamFile($abs, 'institutional-profile-' . $fy, $wantDownload);
