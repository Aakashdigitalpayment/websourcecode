/* Shared accessibility shim — public, member and admin shells.
 * Fills the gaps the markup audit found on every panel, from one place:
 *  - placeholder-only inputs get an aria-label (42 admin / 6 member / 3 public pages had search boxes with no label);
 *  - Bootstrap .btn-close without aria-label gets one;
 *  - icon-only admin action buttons (.adm-icon-btn--edit/--delete/--view …) get a title + aria-label from their modifier.
 * Runs once on DOMContentLoaded and again for nodes added later (modals, AJAX lists). */
(function () {
  'use strict';
  var isEn = (document.documentElement.lang || '').toLowerCase().indexOf('en') === 0;
  var NAMES = {
    edit: ['सम्पादन', 'Edit'], delete: ['हटाउनुहोस्', 'Delete'], view: ['हेर्नुहोस्', 'View'],
    reply: ['जवाफ', 'Reply'], print: ['प्रिन्ट', 'Print'], copy: ['कपी', 'Copy'], download: ['डाउनलोड', 'Download'],
    approve: ['स्वीकृत', 'Approve'], reject: ['अस्वीकृत', 'Reject'], toggle: ['सक्रिय/निष्क्रिय', 'Toggle'],
    close: ['बन्द गर्नुहोस्', 'Close'], search: ['खोज्नुहोस्', 'Search']
  };
  function t(key) { var v = NAMES[key]; return v ? (isEn ? v[1] : v[0]) : ''; }
  function hasName(el) {
    return !!(el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title'));
  }
  function labelled(input) {
    if (hasName(input) || input.closest('label')) return true;
    if (input.id) { try { if (document.querySelector('label[for="' + CSS.escape(input.id) + '"]')) return true; } catch (e) {} }
    return false;
  }
  function run(root) {
    root = root || document;
    var q = root.querySelectorAll ? root : document;
    q.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=checkbox]):not([type=radio]), select, textarea').forEach(function (el) {
      if (labelled(el)) return;
      var ph = el.getAttribute('placeholder') || '';
      if (!ph && el.tagName === 'SELECT' && el.options && el.options.length) ph = (el.options[0].textContent || '').trim();
      if (!ph) { var n = el.getAttribute('name') || ''; ph = /search|^q$|query/i.test(n) ? t('search') : ''; }
      if (ph) el.setAttribute('aria-label', ph.replace(/[….]+$/, '').trim());
    });
    q.querySelectorAll('.btn-close').forEach(function (el) { if (!hasName(el)) el.setAttribute('aria-label', t('close')); });
    q.querySelectorAll('button, a.btn, a[role=button], [class*="icon-btn"]').forEach(function (el) {
      if (hasName(el) || (el.textContent || '').trim()) return;
      var m = (el.className && el.className.baseVal === undefined ? el.className : '').match(/(?:icon-btn--|btn-q|btn-)(edit|delete|view|reply|print|copy|download|approve|reject|toggle)\b/);
      var ico = el.querySelector('[data-lucide]');
      var name = m ? t(m[1]) : '';
      if (!name && ico) {
        var l = ico.getAttribute('data-lucide') || '';
        name = /pencil|edit/.test(l) ? t('edit') : /trash/.test(l) ? t('delete') : /eye/.test(l) ? t('view') : /printer/.test(l) ? t('print') : /copy/.test(l) ? t('copy') : /download/.test(l) ? t('download') : /search/.test(l) ? t('search') : '';
      }
      if (name) { el.setAttribute('aria-label', name); if (!el.getAttribute('title')) el.setAttribute('title', name); }
    });
  }
  function boot() {
    run(document);
    if (window.MutationObserver) {
      var pending = false;
      new MutationObserver(function () {
        if (pending) return; pending = true;
        setTimeout(function () { pending = false; run(document); }, 150);
      }).observe(document.body, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
