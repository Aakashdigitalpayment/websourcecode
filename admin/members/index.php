<?php
/**
 * Legacy members CRUD — use admin/members.php.
 * Keep this folder: old bookmarks hit /admin/members/ ; must not fight pretty-URL strip.
 */
declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow', true);
/* Relative redirect — .htaccess must not strip members.php while admin/members/ exists */
header('Location: ../members.php', true, 302);
exit;
