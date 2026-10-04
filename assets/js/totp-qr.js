/**
 * Local QR codes — drawn in the browser so the encoded data never goes to a third-party API.
 * - img[data-otpauth]: TOTP setup (twoFaQrImgTag() in includes/totp-2fa.php) — the URI is the 2FA secret.
 * - img[data-qr]: any other QR, e.g. program attendance links (coop_qr_img_tag() in includes/qr-local.php).
 * - window.coopQrDataUrl(text, px) for QR images built in JS (program QR modal).
 * Needs assets/vendor/qrcode-generator.js (MIT, Kazuhiko Arase) loaded first.
 */
(function () {
  'use strict';
  function dataUrl(text, px) {
    if (typeof window.qrcode !== 'function' || !text) return '';
    var qr = window.qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    var cell = Math.max(2, Math.floor((px || 220) / (qr.getModuleCount() + 4)));
    return qr.createDataURL(cell, 2 * cell);
  }
  window.coopQrDataUrl = function (text, px) {
    try { return dataUrl(text, px); } catch (e) { return ''; }
  };
  function render() {
    if (typeof window.qrcode !== 'function') return;
    var imgs = document.querySelectorAll('img[data-otpauth], img[data-qr]');
    for (var i = 0; i < imgs.length; i++) {
      var img = imgs[i];
      var text = img.getAttribute('data-otpauth') || img.getAttribute('data-qr') || '';
      if (!text) continue;
      try {
        img.src = dataUrl(text, parseInt(img.getAttribute('width'), 10) || 220);
        img.removeAttribute('hidden');
      } catch (e) {
        /* Manual secret / link text on the same screen stays the fallback. */
      }
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
