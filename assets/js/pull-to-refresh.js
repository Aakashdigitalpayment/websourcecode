/* ══════════════════════════════════════════════════════════
   Pull-to-Refresh — Aakash Cooperative Public Portal  v1.0
   Touch-only. Fires window.location.reload() after threshold.
   Does NOT activate inside member portal embed frames.
   ══════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    /* Skip inside iframe embeds (member portal) */
    if (document.body.classList.contains('embed-in-member-portal')) return;
    /* Skip on non-touch devices */
    if (!('ontouchstart' in window)) return;

    /* ── Config ─────────────────────────────────────── */
    var THRESHOLD = 72;   /* px of pull needed to trigger */
    var MAX_PULL  = 110;  /* max visual travel */
    var DAMPEN    = 0.52; /* how much to dampen finger movement */

    /* ── Prevent browser native PTR conflicting ─────── */
    document.documentElement.style.overscrollBehaviorY = 'contain';

    /* ── Build indicator DOM ─────────────────────────── */
    var indicator = document.createElement('div');
    indicator.id  = 'coop-ptr';
    indicator.innerHTML =
        '<div class="coop-ptr-ring">' +
            '<svg viewBox="0 0 44 44"><circle cx="22" cy="22" r="18"/></svg>' +
            '<i class="fas fa-arrow-down coop-ptr-icon"></i>' +
        '</div>' +
        '<span class="coop-ptr-lbl" data-pull="\u0916\u093f\u091a\u094d\u0928\u0941\u0939\u094b\u0938\u094d \u0930\u093f\u092b\u094d\u0930\u0947\u0938 \u0917\u0930\u094d\u0928" data-release="\u091b\u094b\u0921\u094d\u0928\u0941\u0939\u094b\u0938\u094d \u0930\u093f\u092b\u094d\u0930\u0947\u0938 \u0917\u0930\u094d\u0928" data-loading="\u0932\u094b\u0921 \u0939\u0941\u0901\u0926\u0948\u091b...">\u0916\u093f\u091a\u094d\u0928\u0941\u0939\u094b\u0938\u094d \u0930\u093f\u092b\u094d\u0930\u0947\u0938 \u0917\u0930\u094d\u0928</span>';

    /* ── Styles ──────────────────────────────────────── */
    var css = document.createElement('style');
    css.textContent = [
        '#coop-ptr{',
            'position:fixed;top:0;left:50%;z-index:10000;',
            'transform:translateX(-50%) translateY(-110%);',
            'display:flex;flex-direction:column;align-items:center;gap:5px;',
            'padding:10px 22px 12px;',
            'background:#fff;',
            'border-radius:0 0 22px 22px;',
            'box-shadow:0 6px 24px rgba(0,0,0,0.14),0 1px 4px rgba(0,0,0,0.06);',
            'border:1px solid rgba(0,0,0,0.07);border-top:none;',
            'pointer-events:none;min-width:130px;',
            'will-change:transform;',
        '}',
        '#coop-ptr.ptr-snap{transition:transform .32s cubic-bezier(.34,1.56,.64,1);}',
        '#coop-ptr.ptr-loading{',
            'transform:translateX(-50%) translateY(0)!important;',
            'transition:transform .28s ease;',
        '}',

        '.coop-ptr-ring{position:relative;width:38px;height:38px;display:flex;align-items:center;justify-content:center;}',
        '.coop-ptr-ring svg{position:absolute;inset:0;width:100%;height:100%;}',
        '.coop-ptr-ring svg circle{',
            'fill:none;',
            'stroke:var(--primary-color,#1a5f2a);',
            'stroke-width:3.5;',
            'stroke-linecap:round;',
            'stroke-dasharray:113;',
            'stroke-dashoffset:113;',
            'transform-origin:center;',
            'transform:rotate(-90deg);',
            'transition:stroke-dashoffset .08s linear;',
        '}',
        '#coop-ptr.ptr-loading .coop-ptr-ring svg{animation:coop-ptr-spin .75s linear infinite;}',
        '#coop-ptr.ptr-loading .coop-ptr-ring svg circle{stroke-dashoffset:28;}',
        '@keyframes coop-ptr-spin{to{transform:rotate(270deg)}}',

        '.coop-ptr-icon{',
            'font-size:.9rem;color:var(--primary-color,#1a5f2a);',
            'position:relative;z-index:1;',
            'transition:transform .2s ease;',
        '}',
        '#coop-ptr.ptr-ready .coop-ptr-icon{transform:rotate(180deg);}',
        '#coop-ptr.ptr-loading .coop-ptr-icon{display:none;}',

        '.coop-ptr-lbl{',
            'font-size:.68rem;font-weight:600;white-space:nowrap;',
            'color:#94a3b8;font-family:sans-serif;',
            'transition:color .15s;',
        '}',
        '#coop-ptr.ptr-ready .coop-ptr-lbl{color:var(--primary-color,#1a5f2a);}',
        '#coop-ptr.ptr-loading .coop-ptr-lbl{color:var(--primary-color,#1a5f2a);}',
    ].join('');

    document.head.appendChild(css);

    function appendIndicator() {
        document.body.insertBefore(indicator, document.body.firstChild);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', appendIndicator);
    } else {
        appendIndicator();
    }

    /* ── State ───────────────────────────────────────── */
    var startY    = 0;
    var pulling   = false;
    var triggered = false;
    var circle    = null;
    var lbl       = null;

    function getEls() {
        circle = circle || indicator.querySelector('circle');
        lbl    = lbl    || indicator.querySelector('.coop-ptr-lbl');
    }

    function setProgress(ratio) {
        getEls();
        circle.style.strokeDashoffset = 113 - (113 * Math.min(ratio, 1));
    }

    function showLoading() {
        getEls();
        lbl.textContent = lbl.dataset.loading;
        indicator.classList.remove('ptr-ready', 'ptr-snap');
        indicator.classList.add('ptr-loading');
    }

    function snapBack() {
        indicator.classList.add('ptr-snap');
        indicator.classList.remove('ptr-ready');
        indicator.style.transform = '';
        setTimeout(function () {
            indicator.classList.remove('ptr-snap');
            setProgress(0);
            getEls();
            lbl.textContent = lbl.dataset.pull;
        }, 350);
    }

    /* ── Touch handlers ──────────────────────────────── */
    document.addEventListener('touchstart', function (e) {
        if (window.scrollY > 4)      return;
        if (e.touches.length !== 1)  return;
        startY    = e.touches[0].clientY;
        pulling   = false;
        triggered = false;
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (triggered) return;
        var dy = e.touches[0].clientY - startY;
        if (dy < 6) return;

        /* Ignore mostly-horizontal swipes */
        var dx = Math.abs(e.touches[0].clientX - (window._ptrStartX || e.touches[0].clientX));
        if (dx > dy * 1.4) return;

        pulling = true;
        var pull  = Math.min(dy * DAMPEN, MAX_PULL);
        var ratio = pull / THRESHOLD;

        /* Slide indicator down from top */
        indicator.style.transform =
            'translateX(-50%) translateY(calc(-100% + ' + (pull * 0.82) + 'px))';
        indicator.classList.remove('ptr-snap', 'ptr-loading');

        setProgress(ratio);
        getEls();
        if (ratio >= 1) {
            indicator.classList.add('ptr-ready');
            lbl.textContent = lbl.dataset.release;
        } else {
            indicator.classList.remove('ptr-ready');
            lbl.textContent = lbl.dataset.pull;
        }
    }, { passive: true });

    document.addEventListener('touchstart', function (e) {
        window._ptrStartX = e.touches[0].clientX;
    }, { passive: true });

    document.addEventListener('touchend', function () {
        if (!pulling) return;
        pulling = false;

        if (indicator.classList.contains('ptr-ready') && !triggered) {
            triggered = true;
            showLoading();
            setTimeout(function () { window.location.reload(); }, 420);
        } else {
            snapBack();
        }
    }, { passive: true });

    document.addEventListener('touchcancel', function () {
        if (pulling) { pulling = false; snapBack(); }
    }, { passive: true });

})();
