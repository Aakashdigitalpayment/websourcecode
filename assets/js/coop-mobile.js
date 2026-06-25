/**
 * ════════════════════════════════════════════════════════════
 * COOP MOBILE — Unified Mobile UX Enhancement
 * Dev Bandana Cooperative — v6.5
 * ════════════════════════════════════════════════════════════
 *
 * Covers:
 *  1. Bottom nav active state (member portal)
 *  2. Touch-optimised card ripple
 *  3. Sticky form submit bar on mobile
 *  4. Auto-dismiss flash alerts
 *  5. Smooth scroll to top
 *  6. Table → card-view data-label injection guard
 *  7. Pull-to-refresh indicator (visual only)
 *
 * No backend/session code touched.
 * ════════════════════════════════════════════════════════════
 */
(function () {
    'use strict';

    /* ─── 1. BOTTOM NAV ACTIVE STATE ────────────────────────── */
    function setBottomNavActive() {
        var items = document.querySelectorAll('.mp-bottom-nav-item, .mem-bottom-nav-item');
        if (!items.length) return;
        var current = window.location.pathname.split('/').pop() || 'index.php';
        items.forEach(function (el) {
            var href = (el.getAttribute('href') || '').split('?')[0].split('/').pop();
            var isActive = href && current === href;
            el.classList.toggle('active', isActive);
            el.setAttribute('aria-current', isActive ? 'page' : 'false');
        });
    }

    /* ─── 2. TOUCH RIPPLE on clickable cards ─────────────────── */
    function attachRipple() {
        document.querySelectorAll('.card-clickable, .stat-card, .mem-stat, .ds-card[href]').forEach(function (el) {
            if (el.dataset.ripple) return;
            el.dataset.ripple = '1';
            el.style.position = el.style.position || 'relative';
            el.style.overflow = 'hidden';
            el.addEventListener('pointerdown', function (e) {
                var r = document.createElement('span');
                var rect = el.getBoundingClientRect();
                var sz = Math.max(rect.width, rect.height) * 2;
                r.style.cssText = [
                    'position:absolute',
                    'border-radius:50%',
                    'background:rgba(255,255,255,.3)',
                    'pointer-events:none',
                    'transform:scale(0)',
                    'animation:coopRipple .5s linear',
                    'width:' + sz + 'px',
                    'height:' + sz + 'px',
                    'left:' + (e.clientX - rect.left - sz / 2) + 'px',
                    'top:' + (e.clientY - rect.top - sz / 2) + 'px',
                ].join(';');
                el.appendChild(r);
                setTimeout(function () { r.remove(); }, 550);
            });
        });
        /* Inject keyframes once */
        if (!document.getElementById('coop-ripple-kf')) {
            var s = document.createElement('style');
            s.id = 'coop-ripple-kf';
            s.textContent = '@keyframes coopRipple{to{transform:scale(1);opacity:0}}';
            document.head.appendChild(s);
        }
    }

    /* ─── 3. AUTO-DISMISS flash alerts (after 6 s) ───────────── */
    function autoDismissAlerts() {
        var alerts = document.querySelectorAll('.alert.alert-success, .alert.alert-info');
        alerts.forEach(function (a) {
            if (a.dataset.autoDismiss) return;
            a.dataset.autoDismiss = '1';
            setTimeout(function () {
                a.style.transition = 'opacity .5s, transform .5s';
                a.style.opacity = '0';
                a.style.transform = 'translateY(-6px)';
                setTimeout(function () { a.remove(); }, 520);
            }, 6000);
        });
    }

    /* ─── 4. SCROLL-TO-TOP button ────────────────────────────── */
    function initScrollTop() {
        var btn = document.getElementById('scrollToTop') ||
                  document.querySelector('.scroll-to-top, .back-to-top');
        if (!btn) return;
        window.addEventListener('scroll', function () {
            btn.style.display = window.scrollY > 300 ? '' : 'none';
        }, { passive: true });
        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    /* ─── 5. MOBILE BOTTOM NAV — body padding guard ─────────── */
    function fixBottomNavPadding() {
        var nav = document.querySelector('.mp-bottom-nav, .mem-bottom-nav');
        if (!nav) return;
        var h = nav.offsetHeight || 64;
        /* Only apply on small screens */
        if (window.innerWidth <= 768) {
            document.body.style.paddingBottom = h + 'px';
        }
    }

    /* ─── 6. TABLE CARD-VIEW — enforce data-label on th-less rows ── */
    function guardTableLabels() {
        document.querySelectorAll('table.coop-table, table.table-responsive-stack').forEach(function (t) {
            var ths = Array.from(t.querySelectorAll('thead th')).map(function (th) {
                return th.textContent.trim();
            });
            if (!ths.length) return;
            t.querySelectorAll('tbody tr').forEach(function (tr) {
                var tds = tr.querySelectorAll('td');
                tds.forEach(function (td, i) {
                    if (!td.dataset.label && ths[i]) {
                        td.dataset.label = ths[i];
                    }
                });
            });
        });
    }

    /* ─── 7. STICKY SUBMIT BAR — shows when form submit is off-screen ── */
    function initStickySubmit() {
        if (window.innerWidth > 768) return;
        document.querySelectorAll('form.coop-form-sticky').forEach(function (form) {
            var origBtn = form.querySelector('[type=submit]');
            if (!origBtn || origBtn.dataset.stickied) return;
            origBtn.dataset.stickied = '1';
            var bar = document.createElement('div');
            bar.className = 'coop-sticky-submit-bar';
            bar.innerHTML = '<button type="submit" class="btn btn-primary w-100" style="height:48px;font-size:1rem;font-weight:700;border-radius:14px;">' + (origBtn.textContent.trim() || 'पेश गर्नुहोस्') + '</button>';
            bar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;padding:10px 16px 16px;background:#fff;border-top:1px solid var(--border-color,#e5e7eb);z-index:800;box-shadow:0 -4px 16px rgba(0,0,0,.07);display:none;';
            document.body.appendChild(bar);
            bar.querySelector('button').addEventListener('click', function () {
                origBtn.click();
            });
            var ob = new IntersectionObserver(function (entries) {
                bar.style.display = entries[0].isIntersecting ? 'none' : 'block';
            }, { threshold: 0.1 });
            ob.observe(origBtn);
        });
    }

    /* ─── 8. IMG LAZY LOAD — native lazy where not already set ─ */
    function lazyImages() {
        document.querySelectorAll('img:not([loading])').forEach(function (img) {
            img.setAttribute('loading', 'lazy');
        });
    }

    /* ─── INIT ───────────────────────────────────────────────── */
    function init() {
        setBottomNavActive();
        attachRipple();
        autoDismissAlerts();
        initScrollTop();
        fixBottomNavPadding();
        guardTableLabels();
        initStickySubmit();
        lazyImages();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /* Re-run ripple + active on Turbo/HTMX navigations if applicable */
    document.addEventListener('turbo:load', init);
    document.addEventListener('htmx:afterSwap', function () {
        attachRipple();
        guardTableLabels();
        autoDismissAlerts();
    });

})();
