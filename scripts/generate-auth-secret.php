#!/usr/bin/env php
<?php
/**
 * Create includes/.auth-secret for production HMAC signing (tracker / id-card links).
 * Safe to run multiple times — skips if a valid secret already exists.
 *
 * Usage: php scripts/generate-auth-secret.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/includes/.auth-secret';

if (is_readable($file)) {
    $existing = trim((string) file_get_contents($file));
    if (strlen($existing) >= 32) {
        echo "OK  includes/.auth-secret already exists (" . strlen($existing) . " chars)\n";
        exit(0);
    }
}

if (defined('AUTH_SECRET') && (string) AUTH_SECRET !== '' && strlen((string) AUTH_SECRET) >= 32) {
    echo "OK  AUTH_SECRET constant already configured in local config\n";
    exit(0);
}

$secret = bin2hex(random_bytes(32));
$dir = dirname($file);
if (!is_dir($dir)) {
    fwrite(STDERR, "FAIL includes/ directory missing\n");
    exit(1);
}

if (@file_put_contents($file, $secret . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "FAIL could not write includes/.auth-secret (check permissions)\n");
    exit(1);
}

@chmod($file, 0600);
echo "OK  Created includes/.auth-secret (64 hex chars, mode 0600)\n";
echo "    Re-check: Admin → Site Health → Auth signing secret\n";
