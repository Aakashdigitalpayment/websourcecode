<?php
/**
 * Legacy staff admin page — use Manage Admins (manage-admins.php).
 * Safe redirect so old direct URLs/bookmarks do not break.
 */
declare(strict_types=1);

header('Location: manage-admins.php', true, 301);
exit;
