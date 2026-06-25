/* ═════════════════════════════════════════════════════════════════════
   Sahakari CMS — PWA Install Handler  v3
   - Buttons are visible by default (not waiting for beforeinstallprompt)
   - Works on iOS Safari (manual guide) and Chrome/Android (native prompt)
   - Dismissible slide-up install banner with sessionStorage memory
   - Early standalone detection so no flash on installed devices
   ═════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  /* ── 1. Standalone detection — runs SYNCHRONOUSLY at script parse ── */
  var _standalone = window.matchMedia('(display-mode: standalone)').matches
                  || window.navigator.standalone === true;
  if (_standalone) {
    document.documentElement.classList.add('pwa-standalone');
  }

  var _deferred  = null;
  var _appName   = '';
  var _shortName = '';
  var _iconSrc   = '/assets/images/icon-192x192.png';

  /* ── 2. Read app name from meta tag injected by PHP ──────────────── */
  function _readMeta() {
    var m = document.querySelector('meta[name="pwa-app-name"]');
    if (m && m.content) _appName = m.content;
    var s = document.querySelector('meta[name="pwa-short-name"]');
    if (s && s.content) _shortName = s.content;
    var ic = document.querySelector('link[rel="apple-touch-icon"]');
    if (ic && ic.href) _iconSrc = ic.href;
    if (!_appName) _appName = document.title.split(' - ')[0] || 'सहकारी App';
    if (!_shortName) _shortName = _appName;
  }

  /* ── 3. Expose global install trigger (all portals call this) ─────── */
  window.pwaTriggerInstall = function () {
    if (_standalone) { _toast('App पहिले नै Install भइसकेको छ।', 'info'); return; }
    if (_deferred) {
      _deferred.prompt();
      _deferred.userChoice.then(function (c) {
        if (c.outcome === 'accepted') {
          _deferred = null;
          document.documentElement.classList.remove('pwa-installable');
        } else {
          _toast('Install रद्द गरियो।', 'info');
        }
      });
    } else {
      _showGuide();
    }
  };

  /* ── 4. Capture install prompt (Chrome/Android/Edge) ─────────────── */
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    _deferred = e;
    document.documentElement.classList.add('pwa-installable');
  });

  /* ── 5. After successful install ─────────────────────────────────── */
  window.addEventListener('appinstalled', function () {
    _standalone = true;
    _deferred   = null;
    document.documentElement.classList.remove('pwa-installable');
    document.documentElement.classList.add('pwa-standalone', 'pwa-installed');
    _dismissBanner();
    _toast('✓ App सफलतापूर्वक Install भयो! Home screen मा हेर्नुहोस्।', 'success');
  });

  /* ── 6. On page load: show banner (unless standalone / dismissed) ── */
  window.addEventListener('load', function () {
    _readMeta();
    if (_standalone) return; // already installed — CSS hides buttons via .pwa-standalone
    /* Show banner unless dismissed in the last 24 h */
    var _lastDismissed = parseInt(localStorage.getItem('pwa-banner-dismissed-ts') || '0', 10);
    var _bannerCoolOff = 24 * 60 * 60 * 1000; /* 24 hours */
    if (!sessionStorage.getItem('pwa-banner-dismissed') && (Date.now() - _lastDismissed > _bannerCoolOff)) {
      setTimeout(_showBanner, 1400);
    }
  });

  /* ── 7. Online / Offline ─────────────────────────────────────────── */
  window.addEventListener('online',  function () { document.body.classList.remove('is-offline'); });
  window.addEventListener('offline', function () { document.body.classList.add('is-offline'); });
  if (!navigator.onLine) document.body.classList.add('is-offline');

  /* ── 8. Service Worker registration ─────────────────────────────── */
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then(function (reg) {
          setInterval(function () { reg.update(); }, 60000);
          reg.addEventListener('updatefound', function () {
            var nw = reg.installing;
            nw.addEventListener('statechange', function () {
              if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                _showUpdateBar(reg);
              }
            });
          });
        })
        .catch(function () { /* silent — SW not available in dev/http */ });
      navigator.serviceWorker.addEventListener('controllerchange', function () {
        window.location.reload();
      });
    });
  }

  /* ═══════════════════════════════════════════════════════════════════
     INSTALL BANNER — slides up from bottom, dismissible
     ═══════════════════════════════════════════════════════════════════ */
  function _showBanner() {
    if (_standalone) return;
    if (document.getElementById('pwa-install-banner')) return;

    var banner = document.createElement('div');
    banner.id = 'pwa-install-banner';
    banner.setAttribute('role', 'complementary');
    banner.setAttribute('aria-label', 'App Install');
    banner.innerHTML = [
      '<div class="pwa-bi-inner">',
        '<img class="pwa-bi-icon" src="', _iconSrc, '"',
             ' alt="App" loading="lazy"',
             ' onerror="this.onerror=null;this.src=\'/assets/images/icon-72x72.png\'">',
        '<div class="pwa-bi-text">',
          '<div class="pwa-bi-name">', _escHtml(_appName), '</div>',
          '<div class="pwa-bi-sub">Home screen मा थप्नुहोस् — faster access</div>',
        '</div>',
        '<button type="button" class="pwa-bi-install-btn"',
                ' onclick="pwaTriggerInstall()" aria-label="Install App">',
          '<i class="fas fa-download"></i> Install',
        '</button>',
        '<button type="button" class="pwa-bi-close"',
                ' onclick="pwaDismissBanner()" aria-label="Dismiss">',
          '<i class="fas fa-times"></i>',
        '</button>',
      '</div>'
    ].join('');

    document.body.appendChild(banner);
    /* trigger CSS transition */
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        banner.classList.add('pwa-banner-show');
      });
    });
  }

  function _dismissBanner() {
    var b = document.getElementById('pwa-install-banner');
    if (!b) return;
    b.classList.remove('pwa-banner-show');
    setTimeout(function () { if (b.parentNode) b.parentNode.removeChild(b); }, 380);
  }

  /* Exposed globally so onclick in banner HTML works */
  window.pwaDismissBanner = function () {
    sessionStorage.setItem('pwa-banner-dismissed', '1');
    localStorage.setItem('pwa-banner-dismissed-ts', String(Date.now()));
    _dismissBanner();
  };

  /* ═══════════════════════════════════════════════════════════════════
     MANUAL INSTALL GUIDE — for iOS / browsers without native prompt
     ═══════════════════════════════════════════════════════════════════ */
  function _showGuide() {
    var isIOS     = /iPhone|iPad|iPod/.test(navigator.userAgent);
    var isSafari  = isIOS || /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    var isAndroid = /android/i.test(navigator.userAgent);

    var steps;
    if (isIOS) {
      steps = [
        '<b><i class="fas fa-share-from-square"></i> Share</b> बटन (तलतिर) थिच्नुहोस्',
        '<b>"Add to Home Screen"</b> छान्नुहोस्',
        '<b>"Add"</b> थिच्नुहोस् — सकियो!'
      ].map(function (s, i) { return '<li>' + (i + 1) + '. ' + s + '</li>'; }).join('');
    } else if (isAndroid) {
      steps = [
        '<b><i class="fas fa-ellipsis-vertical"></i> Menu</b> (माथि दायाँ) खोल्नुहोस्',
        '<b>"Add to Home screen"</b> वा <b>"Install App"</b> छान्नुहोस्',
        '<b>"Add"</b> थिच्नुहोस् — सकियो!'
      ].map(function (s, i) { return '<li>' + (i + 1) + '. ' + s + '</li>'; }).join('');
    } else {
      steps = [
        'Browser address bar को <b>Install icon (⊕)</b> थिच्नुहोस्',
        '<b>"Install"</b> थिच्नुहोस्'
      ].map(function (s, i) { return '<li>' + (i + 1) + '. ' + s + '</li>'; }).join('');
    }

    var ov = document.createElement('div');
    ov.className = 'pwa-guide-overlay';
    ov.innerHTML = [
      '<div class="pwa-guide-card">',
        '<div class="pwa-guide-head">',
          '<img class="pwa-guide-icon" src="', _iconSrc, '"',
               ' onerror="this.onerror=null;this.src=\'/assets/images/icon-72x72.png\'">',
          '<div>',
            '<div class="pwa-guide-title">', _escHtml(_appName), '</div>',
            '<div class="pwa-guide-sub">Home Screen मा Add गर्नुहोस्</div>',
          '</div>',
          '<button type="button" class="pwa-guide-x" onclick="this.closest(\'.pwa-guide-overlay\').remove()">',
            '<i class="fas fa-times"></i>',
          '</button>',
        '</div>',
        '<ol class="pwa-guide-steps">', steps, '</ol>',
        '<button type="button" class="pwa-guide-ok"',
                ' onclick="this.closest(\'.pwa-guide-overlay\').remove()">',
          'ठीक छ, Install गर्छु',
        '</button>',
      '</div>'
    ].join('');
    document.body.appendChild(ov);
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
    requestAnimationFrame(function () { ov.classList.add('pwa-guide-show'); });
  }

  /* ═══════════════════════════════════════════════════════════════════
     UPDATE BAR
     ═══════════════════════════════════════════════════════════════════ */
  function _showUpdateBar(reg) {
    if (document.getElementById('pwa-update-bar')) return;
    var bar = document.createElement('div');
    bar.id = 'pwa-update-bar';
    bar.innerHTML = [
      '<span><i class="fas fa-rotate" style="margin-right:6px;"></i>',
        'नयाँ संस्करण उपलब्ध छ!</span>',
      '<div style="display:flex;gap:8px;flex-shrink:0;">',
        '<button id="pwa-upd-now">Update गर्नुहोस्</button>',
        '<button id="pwa-upd-later">पछि</button>',
      '</div>'
    ].join('');
    document.body.appendChild(bar);
    document.getElementById('pwa-upd-now').onclick = function () {
      if (reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
      bar.remove();
    };
    document.getElementById('pwa-upd-later').onclick = function () { bar.remove(); };
  }

  /* ═══════════════════════════════════════════════════════════════════
     UTILITIES
     ═══════════════════════════════════════════════════════════════════ */
  function _toast(msg, type) {
    var bg = { success: '#1a5f2a', info: '#075985', warning: '#92400e' }[type] || '#075985';
    var t = document.createElement('div');
    t.className = 'pwa-toast';
    t.innerHTML = msg;
    t.style.background = bg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('pwa-toast-show'); });
    setTimeout(function () {
      t.classList.remove('pwa-toast-show');
      setTimeout(function () { if (t.parentNode) t.remove(); }, 380);
    }, 3000);
  }

  function _escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

})();
