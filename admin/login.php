<?php
/**
 * Compatibility redirect — old bookmarks pointing to /admin/login.php
 * Real login lives at /admin/index.php
 */
header('Location: /admin/', true, 301);
exit;
