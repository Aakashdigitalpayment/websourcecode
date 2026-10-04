/**
 * TOTP setup QR — drawn in the browser so the 2FA secret never leaves the page.
 * (A third-party QR image API would receive the otpauth:// URI = the secret.)
 * Markup comes from twoFaQrImgTag() in includes/totp-2fa.php.
 * Needs assets/vendor/qrcode-generator.js (MIT, Kazuhiko Arase) loaded first.
 */
(function () {
  'use strict';
  function render() {
    if (typeof window.qrcode !== 'function') return;
    var imgs = document.querySelectorAll('img[data-otpauth]');
    for (var i = 0; i < imgs.length; i++) {
      var img = imgs[i];
      var uri = img.getAttribute('data-otpauth') || '';
      if (!uri) continue;
      try {
        var qr = window.qrcode(0, 'M');
        qr.addData(uri);
        qr.make();
        var target = parseInt(img.getAttribute('width'), 10) || 220;
        var cell = Math.max(2, Math.floor(target / (qr.getModuleCount() + 4)));
        img.src = qr.createDataURL(cell, 2 * cell);
        img.removeAttribute('hidden');
      } catch (e) {
        /* Manual secret field on the same screen stays the fallback. */
      }
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
