<?php
/**
 * Secure file proxy — Information Room documents (no direct URL access)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/information-room-tables.php';

$isAdmin = function_exists('isAdminLoggedIn') && isAdminLoggedIn();
$member = null;
if (!$isAdmin) {
    if (file_exists(__DIR__ . '/includes/member-auth.php')) {
        require_once __DIR__ . '/includes/member-auth.php';
    }
    if (function_exists('memberIsLoggedIn') && memberIsLoggedIn()) {
        $member = function_exists('currentMember') ? currentMember() : null;
    }
}

if (!$isAdmin && !irMemberHasAccess($member)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Access denied.';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$wantDownload = isset($_GET['dl']) && (string) $_GET['dl'] === '1';

try {
    $db = getDB();
} catch (Throwable $e) {
    http_response_code(503);
    exit;
}

$item = irFetchItem($db, $id, !$isAdmin);
if (!$item || !irCanAccessItem($item, $isAdmin, $member)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Document not found.';
    exit;
}

/* View-only vault: downloads blocked unless explicitly allowed AND admin/member still authenticated */
if ($wantDownload && empty($item['allow_download'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Download is not permitted for this document.';
    exit;
}

$relPath = irNormalizeStoredPath((string) ($item['file_path'] ?? ''));
$filePath = $relPath !== '' ? irResolveFilePath($relPath) : '';
if ($filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File missing.';
    exit;
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];
if (!isset($mimeMap[$ext])) {
    http_response_code(415);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unsupported file type.';
    exit;
}
$mime = $mimeMap[$ext];
$safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', (string) ($item['title'] ?? 'document')) . '.' . $ext;

$viewerType = $isAdmin ? 'admin' : 'member';
$viewerId = $isAdmin ? (int) ($_SESSION['admin_id'] ?? 0) : (int) ($member['id'] ?? 0);
$viewerName = $isAdmin
    ? (string) ($_SESSION['admin_username'] ?? $_SESSION['admin_name'] ?? 'Admin')
    : (string) ($member['name'] ?? 'Member');
irLogAccess($db, $id, $viewerType, $viewerId, $viewerName, $wantDownload ? 'download' : 'view');

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($wantDownload && !empty($item['allow_download'])) {
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
} else {
    /* Discourage Save As from browser chrome */
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    if ($ext === 'pdf' && empty($item['allow_download'])) {
        header('X-Robots-Tag: noindex, nofollow, noarchive');
    }
}

header('Content-Length: ' . (string) filesize($filePath));
readfile($filePath);
exit;
