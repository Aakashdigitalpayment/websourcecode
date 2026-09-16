<?php /* Standalone offline shell — SITE_URL-aware CSS when available */ ?>
<!DOCTYPE html>
<html lang="ne" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Offline status page for Aakash Cooperative website.">
<meta name="theme-color" content="#1a5f2a">
<title>अफलाइन — सहकारी</title>
<?php $__offCss = (defined('SITE_URL') ? rtrim((string)SITE_URL, '/') . '/' : '/') . 'assets/css/offline-page.css'; ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($__offCss, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
<div class="card">

  <div class="logo-wrap">
    <img src="/assets/images/logo.png" alt="Logo"
         onerror="this.onerror=null;this.src='/assets/images/icon-192x192.png'">
  </div>

  <div class="wifi-icon">
    <!-- wifi-off icon -->
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
         style="color:var(--primary-color)">
      <line x1="1" y1="1" x2="23" y2="23"/>
      <path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/>
      <path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/>
      <path d="M10.71 5.05A16 16 0 0 1 22.56 9"/>
      <path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/>
      <path d="M8.53 16.11a6 6 0 0 1 6.95 0"/>
      <line x1="12" y1="20" x2="12.01" y2="20"/>
    </svg>
  </div>

  <h1>तपाईं अहिले अफलाइन हुनुहुन्छ</h1>
  <div class="en-title">You are offline</div>

  <p class="subtitle">
    इन्टरनेट जडान उपलब्ध छैन।<br>
    कृपया जडान जाँच गरेर पुनः प्रयास गर्नुहोस्।
  </p>

  <ul class="tips">
    <li>WiFi वा Mobile Data जडान जाँच गर्नुहोस्</li>
    <li>Airplane Mode बन्द छ कि जाँच गर्नुहोस्</li>
    <li>Router / Hotspot Restart गर्ने प्रयास गर्नुहोस्</li>
  </ul>

  <button type="button" class="retry-btn" onclick="retryNow()" id="retryBtn">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="23 4 23 10 17 10"/>
      <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
    </svg>
    फेरि प्रयास गर्नुहोस्
  </button>

  <div class="status-bar" id="statusBar"></div>

  <p class="footer-text">सहकारी CMS &mdash; Offline Mode</p>
</div>

<script>
function retryNow() {
  var bar = document.getElementById('statusBar');
  var btn = document.getElementById('retryBtn');
  bar.className = 'status-bar retrying';
  bar.textContent = '⏳ जडान खोज्दैछ...';
  btn.disabled = true;
  btn.style.opacity = '.6';
  setTimeout(function() {
    window.location.reload();
  }, 800);
}

/* Auto-restore when connection comes back */
window.addEventListener('online', function() {
  var bar = document.getElementById('statusBar');
  bar.className = 'status-bar online';
  bar.textContent = '✓ इन्टरनेट जडान भयो! पेज लोड गर्दैछ...';
  setTimeout(function() { window.location.reload(); }, 1200);
});

/* If we're actually online (cached page), show appropriate message */
if (navigator.onLine) {
  document.querySelector('.subtitle').innerHTML =
    'पेज लोड गर्न समस्या भयो।<br>कृपया फेरि प्रयास गर्नुहोस्।';
}
</script>
</body>
</html>
