<?php
/**
 * Session debug endpoint — disabled on live sites.
 * Kept as a stub so old bookmarks return 403 instead of leaking session data.
 */
declare(strict_types=1);

http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
echo 'Forbidden';
exit;
