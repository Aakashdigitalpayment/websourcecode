<?php
/**
 * Legacy public attendance entry — thin redirect to Member Portal attend.
 *
 * Old printed QR / bookmarks:
 *   attend.php?token=XXXX  →  member/attend.php?qr_token=XXXX
 * Also accepts qr_token= for consistency with the canonical URL.
 *
 * Concept: venue attendance lives in member portal (login + CSRF + helpers).
 * verify.php remains ID-card / pre-reg verification — not venue check-in.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$raw = (string) ($_GET['qr_token'] ?? $_GET['token'] ?? '');
$token = trim(preg_replace('/[^a-zA-Z0-9_-]/', '', $raw) ?? '');

$base = rtrim(defined('SITE_URL') ? (string) SITE_URL : '/', '/') . '/';
$target = $base . 'member/attend.php';
if ($token !== '') {
    $target .= '?qr_token=' . rawurlencode($token);
}

header('Location: ' . $target, true, 301);
header('Cache-Control: no-store, no-cache, must-revalidate');
exit;
