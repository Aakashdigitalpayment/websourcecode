<?php
declare(strict_types=1);
header('X-Robots-Tag: noindex, nofollow', true);
$id = (int) ($_GET['id'] ?? 0);
header('Location: ../kyc-applications.php' . ($id > 0 ? ('?view=' . $id) : ''), true, 301);
exit;
