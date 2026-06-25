<!DOCTYPE html>
<html lang="ne" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1a5f2a">
<title>अफलाइन — सदस्य पोर्टल</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --green:#1a5f2a;--green-light:#e8f5e9;--green-mid:#2e7d3a;
  --text:#1f2937;--muted:#6b7280;--border:#e5e7eb;
  --radius:16px;--shadow:0 8px 32px rgba(26,95,42,.13),0 2px 8px rgba(0,0,0,.06);
}
body{
  font-family:'Segoe UI',system-ui,-apple-system,sans-serif;
  background:linear-gradient(145deg,#f0f7f1 0%,#e8f5e9 60%,#f0f7f1 100%);
  min-height:100vh;display:flex;align-items:center;
  justify-content:center;padding:20px;color:var(--text);
}
.wrap{width:100%;max-width:440px;}

/* ── Card ── */
.card{
  background:#fff;border-radius:24px;
  box-shadow:var(--shadow);overflow:hidden;
}

/* ── Brand header ── */
.card-head{
  background:linear-gradient(135deg,var(--green),var(--green-mid));
  padding:22px 24px 18px;display:flex;align-items:center;gap:14px;
}
.card-head img{height:36px;object-fit:contain;filter:brightness(0) invert(1);}
.card-head-text{color:#fff;}
.card-head-title{font-size:1rem;font-weight:700;line-height:1.3;}
.card-head-sub{font-size:.75rem;opacity:.82;margin-top:1px;}

/* ── Wifi icon ── */
.icon-wrap{
  margin:28px auto 18px;
  width:80px;height:80px;background:var(--green-light);
  border-radius:50%;display:flex;align-items:center;justify-content:center;
  box-shadow:0 0 0 10px #f0f7f1;
}
.icon-wrap svg{display:block;}

/* ── Body ── */
.card-body{padding:0 24px 24px;text-align:center;}
h1{font-size:1.3rem;font-weight:800;color:#1a2e1d;margin-bottom:6px;letter-spacing:-.01em;}
.en-sub{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:16px;}
.desc{font-size:.88rem;color:#4b5563;line-height:1.7;margin-bottom:22px;}

/* ── Cached member card ── */
.mem-card{
  display:none;
  background:var(--green-light);border-radius:var(--radius);
  padding:14px 16px;margin-bottom:18px;text-align:left;
  border:1px solid rgba(26,95,42,.12);
}
.mem-card.show{display:block;}
.mem-card-label{font-size:.68rem;color:var(--green);font-weight:700;
  text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;
  display:flex;align-items:center;gap:6px;}
.mem-card-label::before{content:'';display:inline-block;width:6px;height:6px;
  border-radius:50%;background:var(--green);}
.mem-row{display:flex;align-items:center;gap:10px;margin-bottom:6px;}
.mem-avatar{
  width:40px;height:40px;border-radius:50%;
  background:var(--green);color:#fff;
  display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;font-weight:700;flex-shrink:0;
}
.mem-info-name{font-weight:700;font-size:.92rem;color:#1a2e1d;}
.mem-info-ts{font-size:.72rem;color:var(--muted);margin-top:1px;}
.notif-pill{
  display:inline-flex;align-items:center;gap:5px;
  background:var(--green);color:#fff;
  border-radius:20px;padding:3px 10px;font-size:.72rem;font-weight:700;
  margin-top:6px;
}

/* ── Tips ── */
.tips{
  list-style:none;text-align:left;padding:12px 16px;
  background:#f9fafb;border-radius:12px;border:1px solid var(--border);
  margin-bottom:22px;
}
.tips li{
  font-size:.81rem;color:#374151;padding:3px 0;line-height:1.7;
  display:flex;align-items:flex-start;gap:8px;
}
.tips li::before{content:'•';color:var(--green);font-weight:800;flex-shrink:0;}

/* ── Buttons ── */
.btn-row{display:flex;gap:10px;flex-direction:column;}
.btn-retry{
  display:flex;align-items:center;justify-content:center;gap:9px;
  background:var(--green);color:#fff;border:none;border-radius:12px;
  padding:14px 24px;font-size:.95rem;font-weight:700;cursor:pointer;
  width:100%;transition:opacity .15s,transform .1s;
  box-shadow:0 4px 14px rgba(26,95,42,.28);
}
.btn-retry:hover{opacity:.88;}
.btn-retry:active{transform:scale(.98);}
.btn-dashboard{
  display:flex;align-items:center;justify-content:center;gap:8px;
  background:var(--green-light);color:var(--green);
  border:2px solid rgba(26,95,42,.18);
  border-radius:12px;padding:12px 24px;
  font-size:.88rem;font-weight:600;cursor:pointer;
  width:100%;text-decoration:none;
  transition:background .15s,transform .1s;
}
.btn-dashboard:active{transform:scale(.98);}
.btn-dashboard:hover{background:rgba(26,95,42,.1);}

/* ── Status bar ── */
.status-bar{
  margin-top:14px;padding:8px 14px;border-radius:8px;
  font-size:.76rem;font-weight:600;letter-spacing:.02em;
  display:none;text-align:center;
}
.status-bar.online{background:#d1fae5;color:#065f46;display:block;}
.status-bar.retrying{background:#fef3c7;color:#92400e;display:block;}

.footer-text{margin-top:20px;font-size:.7rem;color:#9ca3af;text-align:center;}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">

    <!-- Brand header -->
    <div class="card-head">
      <img src="/assets/images/logo.png" alt="Logo"
           onerror="this.onerror=null;this.style.display='none';">
      <div class="card-head-text">
        <div class="card-head-title" id="siteName">आकाश सहकारी</div>
        <div class="card-head-sub">सदस्य पोर्टल — Offline</div>
      </div>
    </div>

    <div class="card-body">

      <!-- Wifi off icon -->
      <div class="icon-wrap">
        <svg width="38" height="38" viewBox="0 0 24 24" fill="none"
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
      <div class="en-sub">You are offline</div>
      <p class="desc">
        इन्टरनेट जडान उपलब्ध छैन।<br>
        कृपया जडान जाँच गरेर पुनः प्रयास गर्नुहोस्।
      </p>

      <!-- Cached member info (shown if localStorage has data) -->
      <div class="mem-card" id="memCard">
        <div class="mem-card-label">अन्तिम भेटिएको खाता</div>
        <div class="mem-row">
          <div class="mem-avatar" id="memInitial">?</div>
          <div>
            <div class="mem-info-name" id="memName">—</div>
            <div class="mem-info-ts" id="memTs">—</div>
          </div>
        </div>
        <div id="memNotifWrap" style="display:none;">
          <span class="notif-pill">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M15 17h5l-1.405-1.405A2.032 2.032 0 0 1 18 14.158V11a6.002 6.002 0 0 0-4-5.659V5a2 2 0 1 0-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9"/></svg>
            <span id="memNotifCount">0</span> अपठित सूचना
          </span>
        </div>
      </div>

      <!-- Tips -->
      <ul class="tips">
        <li>WiFi वा Mobile Data जडान जाँच गर्नुहोस्</li>
        <li>Airplane Mode बन्द छ कि जाँच गर्नुहोस्</li>
        <li>Router / Hotspot Restart गर्ने प्रयास गर्नुहोस्</li>
      </ul>

      <div class="btn-row">
        <button class="btn-retry" onclick="retryNow()" id="retryBtn">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="23 4 23 10 17 10"/>
            <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
          </svg>
          फेरि प्रयास गर्नुहोस्
        </button>
        <a href="/member/" class="btn-dashboard">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
          </svg>
          ड्यासबोर्डमा जानुहोस्
        </a>
      </div>

      <div class="status-bar" id="statusBar"></div>

    </div>
  </div>
  <p class="footer-text">
    आकाश सहकारी — Offline Mode &nbsp;|&nbsp;
    जडान भएपछि स्वतः रिफ्रेश हुनेछ
  </p>
</div>

<script>
(function () {
  /* ── Load cached member data from localStorage ── */
  try {
    var name    = localStorage.getItem('coop_mem_name')   || '';
    var unread  = parseInt(localStorage.getItem('coop_mem_unread') || '0', 10);
    var ts      = localStorage.getItem('coop_mem_ts')     || '';
    var site    = localStorage.getItem('coop_mem_site')   || '';

    if (site) document.getElementById('siteName').textContent = site;

    if (name) {
      document.getElementById('memCard').classList.add('show');
      document.getElementById('memName').textContent = name;
      document.getElementById('memInitial').textContent = name.charAt(0).toUpperCase();
      if (ts) {
        try {
          var d = new Date(parseInt(ts, 10));
          var opts = { year:'numeric', month:'short', day:'numeric',
                       hour:'2-digit', minute:'2-digit' };
          document.getElementById('memTs').textContent =
            'अन्तिम भेट: ' + d.toLocaleString('ne-NP', opts);
        } catch(e) {}
      }
      if (unread > 0) {
        document.getElementById('memNotifWrap').style.display = 'block';
        document.getElementById('memNotifCount').textContent = unread > 9 ? '9+' : unread;
      }
    }
  } catch (e) { /* localStorage unavailable */ }

  /* ── Retry ── */
  window.retryNow = function () {
    var bar = document.getElementById('statusBar');
    var btn = document.getElementById('retryBtn');
    bar.className = 'status-bar retrying';
    bar.textContent = '⏳ जडान खोज्दैछ...';
    btn.disabled = true;
    btn.style.opacity = '.6';
    setTimeout(function () { window.location.reload(); }, 900);
  };

  /* ── Auto-restore when connection returns ── */
  window.addEventListener('online', function () {
    var bar = document.getElementById('statusBar');
    bar.className = 'status-bar online';
    bar.textContent = '✓ इन्टरनेट जडान भयो! पेज लोड गर्दैछ...';
    setTimeout(function () {
      /* Go back to dashboard or previous member page */
      var prev = localStorage.getItem('coop_mem_last_url') || '/member/';
      window.location.href = prev;
    }, 1200);
  });

  /* If actually online (cached page served but connected) */
  if (navigator.onLine) {
    document.querySelector('.desc').innerHTML =
      'पेज लोड गर्न समस्या भयो।<br>कृपया फेरि प्रयास गर्नुहोस्।';
  }
})();
</script>
</body>
</html>
