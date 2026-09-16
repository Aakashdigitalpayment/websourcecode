<?php
/**
 * 500 Internal Server Error page
 * Referenced by _bootstrap.php exception handler.
 * Do NOT require _bootstrap.php here — this page may be included mid-bootstrap.
 */
if (!defined('BASEDIR')) define('BASEDIR', __DIR__);
$pageTitle = 'Server Error — 500';
$isEnglish = function_exists('isEnglish') ? isEnglish() : true;
?><!DOCTYPE html>
<html lang="<?= $isEnglish ? 'en' : 'ne' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>500 — <?= $isEnglish ? 'Server Error' : 'सर्भर त्रुटि' ?></title>
<?php $__errCss = (defined('SITE_URL') ? rtrim((string)SITE_URL, '/') . '/' : '/') . 'assets/css/error-500-page.css'; ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($__errCss, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="box">
  <div class="num">500</div>
  <h2><?= $isEnglish ? 'Server Error' : 'सर्भर त्रुटि' ?></h2>
  <p><?= $isEnglish
    ? 'Something went wrong on our end. Please try again in a moment.'
    : 'हाम्रो तर्फबाट केही गडबडी भयो। कृपया केही समयपछि पुनः प्रयास गर्नुहोस्।' ?></p>
  <a href="/"><?= $isEnglish ? '← Go Home' : '← गृहपृष्ठमा जानुहोस्' ?></a>
</div>
</body>
</html>
