<!DOCTYPE html>
<html lang="ne" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Offline status page for Aakash Cooperative website.">
<meta name="theme-color" content="#1a5f2a">
<title>अफलाइन — सहकारी</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:'Segoe UI',system-ui,-apple-system,sans-serif;
  background:linear-gradient(145deg,#f0f7f1 0%,#e8f5e9 100%);
  min-height:100vh;display:flex;align-items:center;
  justify-content:center;padding:20px;color:#1f2937;
}
.card{
  background:#fff;border-radius:24px;padding:44px 36px 36px;
  max-width:420px;width:100%;text-align:center;
  box-shadow:0 12px 48px rgba(26,95,42,.13),0 2px 8px rgba(0,0,0,.06);
}
.logo-wrap{margin-bottom:22px}
.logo-wrap img{height:52px;object-fit:contain}
.wifi-icon{
  width:76px;height:76px;background:#e8f5e9;border-radius:50%;
  display:inline-flex;align-items:center;justify-content:center;
  margin:0 auto 22px;box-shadow:0 0 0 6px #f0f7f1;
}
h1{font-size:1.35rem;font-weight:800;color:#1a2e1d;margin-bottom:8px;letter-spacing:-.01em}
.en-title{font-size:.8rem;color:#6b7280;margin-bottom:18px;letter-spacing:.02em;text-transform:uppercase;font-weight:600}
.subtitle{font-size:.9rem;color:#4b5563;margin-bottom:24px;line-height:1.75}
.tips{
  list-style:none;text-align:left;padding:14px 18px;
  background:#f9fafb;border-radius:12px;border:1px solid #e5e7eb;
  margin-bottom:26px;
}
.tips li{
  font-size:.83rem;color:#374151;padding:3px 0;line-height:1.7;
  display:flex;align-items:flex-start;gap:8px;
}
.tips li::before{content:'•';color:#1a5f2a;font-weight:800;flex-shrink:0;margin-top:1px}
.retry-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:9px;
  background:#1a5f2a;color:#fff;border:none;border-radius:12px;
  padding:14px 28px;font-size:.95rem;font-weight:700;cursor:pointer;
  width:100%;transition:opacity .15s,transform .1s;
  box-shadow:0 4px 14px rgba(26,95,42,.32);
}
.retry-btn:hover{opacity:.88}
.retry-btn:active{transform:scale(.98)}
.retry-btn svg{flex-shrink:0}
.status-bar{
  margin-top:16px;padding:8px 14px;border-radius:8px;
  font-size:.76rem;font-weight:600;letter-spacing:.02em;
  display:none;
}
.status-bar.online{background:#d1fae5;color:#065f46;display:block}
.status-bar.retrying{background:#fef3c7;color:#92400e;display:block}
.footer-text{margin-top:22px;font-size:.72rem;color:#9ca3af}
</style>
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
         stroke="#1a5f2a" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
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

  <button class="retry-btn" onclick="retryNow()" id="retryBtn">
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
