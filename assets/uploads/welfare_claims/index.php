<?php
// Directory index only — individual uploaded files stay accessible to authorized UI links.
http_response_code(403);
header("Content-Type: text/plain; charset=UTF-8");
header("X-Robots-Tag: noindex, nofollow");
echo "Forbidden";
exit;
