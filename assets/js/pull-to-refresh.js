/* ══════════════════════════════════════════════════════════
   Pull-to-Refresh — Aakash Cooperative Public Portal  v1.4
   Touch-only. Fires window.location.reload() after threshold.
   Does NOT activate inside member portal embed frames.
   v1.4: wait for body (defer-safe) before init.
   v1.3: safer form-focus check (don't block PTR after button focus).
   v1.2: arm only at document top — fixes mid-page false reload
         (stale startY=0 made scroll feel like auto page load).
   v1.1: disabled on long forms + while editing inputs.
   ══════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    function bootPtr() {
        if (!document.body) return;
        if (document.body.classList.contains('embed-in-member-portal')) return;
        if (!('ontouchstart' in window)) return;
        coopPtrInit();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootPtr, { once: true });
    } else {
        bootPtr();
    }

    function coopPtrInit() {
    /* Skip on non-touch devices (also checked in bootPtr) */
    if (!('ontouchstart' in window)) return;

    /* Long public/member forms — pull-to-refresh wipes in-progress input */
    var path = (location.pathname || '').toLowerCase();
    var FORM_PATHS = [
        '/online-kyc.php',
        '/online-account.php',
        '/loan-apply.php',
        '/member/account-apply.php',
        '/member/loan-apply.php',
        '/member/kyc'
    ];
    for (var i = 0; i < FORM_PATHS.length; i++) {
        if (path.indexOf(FORM_PATHS[i]) !== -1) return;
    }

    function isFormInteraction() {
        var el = document.activeElement;
        if (!el || el === document.body || el === document.documentElement) return false;
        var tag = (el.tagName || '').toLowerCase();
        /* Only real text entry — not every button/form focus (blocked PTR after SA clicks) */
        if (tag === 'input' || tag === 'textarea' || tag === 'select') return true;
        if (el.isContentEditable) return true;
        return false;
    }

    function pageScrollY() {
        return window.scrollY || document.documentElement.scrollTop || 0;
    }

    /* Nested scrollers (carousel, tables) — don't steal their gestures */
    function isInsideScrollable(el) {
        var n = el;
        while (n && n !== document.body && n !== document.documentElement) {
            if (n.nodeType === 1) {
                var st = window.getComputedStyle(n);
                var oy = st.overflowY;
                if ((oy === 'auto' || oy === 'scroll' || oy === 'overlay') && n.scrollHeight > n.clientHeight + 4) {
                    return true;
                }
                var ox = st.overflowX;
                if ((ox === 'auto' || ox === 'scroll' || ox === 'overlay') && n.scrollWidth > n.clientWidth + 4) {
                    return true;
                }
            }
            n = n.parentElement;
        }
        return false;
    }

    /* ── Config ─────────────────────────────────────── */
    var THRESHOLD = 88;   /* px of pull needed to trigger (was 72 — less accidental) */
    var MAX_PULL  = 110;  /* max visual travel */
    var DAMPEN    = 0.52; /* how much to dampen finger movement */
    var TOP_MAX   = 2;    /* must stay at document top for PTR */

    /* ── Prevent browser native PTR conflicting ─────── */
    document.documentElement.style.overscrollBehaviorY = 'contain';

    /* ── Build indicator DOM ─────────────────────────── */
    var indicator = document.createElement('div');
    indicator.id  = 'coop-ptr';
    indicator.setAttribute('aria-hidden', 'true');
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
    var startX    = 0;
    var armed     = false; /* true only after a valid top-of-page touchstart */
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

    function cancelGesture() {
        armed = false;
        if (pulling) {
            pulling = false;
            snapBack();
        }
    }

    /* ── Touch handlers ──────────────────────────────── */
    document.addEventListener('touchstart', function (e) {
        armed     = false;
        pulling   = false;
        triggered = false;
        if (pageScrollY() > TOP_MAX) return;
        if (e.touches.length !== 1) return;
        if (isFormInteraction()) return;
        if (e.target && isInsideScrollable(e.target)) return;

        startY = e.touches[0].clientY;
        startX = e.touches[0].clientX;
        armed  = true;
    }, { passive: true });

    document.addEventListener('touchmove', function (e) {
        if (!armed || triggered) return;
        if (isFormInteraction()) {
            cancelGesture();
            return;
        }
        /* Left the top while dragging — this is normal scroll, not PTR */
        if (pageScrollY() > TOP_MAX) {
            cancelGesture();
            return;
        }

        var dy = e.touches[0].clientY - startY;
        if (dy < 8) return;

        /* Ignore mostly-horizontal swipes */
        var dx = Math.abs(e.touches[0].clientX - startX);
        if (dx > dy * 1.2) {
            cancelGesture();
            return;
        }

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

    document.addEventListener('touchend', function () {
        if (!armed) return;
        armed = false;
        if (!pulling) return;
        pulling = false;

        if (indicator.classList.contains('ptr-ready') && !triggered && pageScrollY() <= TOP_MAX) {
            triggered = true;
            showLoading();
            setTimeout(function () { window.location.reload(); }, 420);
        } else {
            snapBack();
        }
    }, { passive: true });

    document.addEventListener('touchcancel', function () {
        if (armed || pulling) cancelGesture();
    }, { passive: true });

    } /* coopPtrInit */
})();
