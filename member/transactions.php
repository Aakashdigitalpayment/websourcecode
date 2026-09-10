<?php
/**
 * Unfinished member ledger surface — send users to portal home.
 */
declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow', true);
require_once __DIR__ . '/_bootstrap.php';
if (function_exists('requireMemberLogin')) {
    requireMemberLogin();
}
header('Location: index.php', true, 302);
exit;
