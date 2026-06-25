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
<style>
  body{font-family:sans-serif;background:#f4f5f4;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
  .box{text-align:center;padding:3rem 2rem;background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.08);max-width:480px;width:90%}
  .num{font-size:6rem;font-weight:900;color:#e8e8e8;line-height:1}
  h2{color:#166534;margin:.5rem 0 1rem}
  p{color:#475569}
  a{color:#166534;font-weight:600}
</style>
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
