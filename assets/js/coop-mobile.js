/**
 * ════════════════════════════════════════════════════════════
 * COOP MOBILE — Unified Mobile UX Enhancement
 * Dev Bandana Cooperative — v6.9
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
 *  8. prefers-reduced-motion respect for motion helpers
 *
 * No backend/session code touched.
 * ════════════════════════════════════════════════════════════
 */
(function () {
    'use strict';

    function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function isEnglishUi() {
        var lang = (document.documentElement.lang || '').toLowerCase();
        return lang.indexOf('en') === 0;
    }

    /* ─── 1. BOTTOM NAV ACTIVE STATE ────────────────────────── */
    function setBottomNavActive() {
        var items = document.querySelectorAll('.mp-bottom-nav__item, .mp-bottom-nav-item, .mem-bottom-nav-item, .mp-bottom-nav a, .mem-bottom-nav a, .mob-bottomnav a, .mob-bn-item');
        if (!items.length) return;
        var current = window.location.pathname.split('/').pop() || 'index.php';
        items.forEach(function (el) {
            var href = (el.getAttribute('href') || '').split('?')[0].split('/').pop();
            var isActive = href && current === href;
            el.classList.toggle('active', isActive);
            if (isActive) {
                el.setAttribute('aria-current', 'page');
            } else {
                el.removeAttribute('aria-current');
            }
        });
    }

    /* ─── 2. TOUCH RIPPLE on clickable cards ─────────────────── */
    function attachRipple() {
        if (prefersReducedMotion()) return;
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
                /* Keep alerts readable when motion is reduced */
                if (prefersReducedMotion()) return;
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
        if (!btn.getAttribute('type') && btn.tagName === 'BUTTON') {
            btn.setAttribute('type', 'button');
        }
        if (!btn.getAttribute('aria-label')) {
            btn.setAttribute('aria-label', isEnglishUi() ? 'Back to top' : 'माथि जानुहोस्');
        }
        window.addEventListener('scroll', function () {
            btn.style.display = window.scrollY > 300 ? '' : 'none';
        }, { passive: true });
        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        });
    }

    /* ─── 5. MOBILE BOTTOM NAV — body padding guard ─────────── */
    function fixBottomNavPadding() {
        var nav = document.querySelector('.mp-bottom-nav, .mem-bottom-nav, .mob-bottomnav');
        if (!nav) return;
        var h = nav.offsetHeight || 64;
        /* Align with CSS bottom-nav cutoff (~900px) */
        if (window.innerWidth <= 899) {
            document.body.style.paddingBottom = h + 'px';
            document.body.classList.add('has-bottomnav');
        } else {
            document.body.style.paddingBottom = '';
            document.body.classList.remove('has-bottomnav');
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
        if (window.matchMedia && !window.matchMedia('(max-width: 899px)').matches) return;
        document.querySelectorAll('form.coop-form-sticky').forEach(function (form) {
            var origBtn = form.querySelector('button[type="submit"], input[type="submit"], [type=submit]');
            if (!origBtn || origBtn.dataset.stickied) return;
            origBtn.dataset.stickied = '1';
            var bar = document.createElement('div');
            bar.className = 'coop-sticky-submit-bar';
            bar.setAttribute('role', 'region');
            bar.setAttribute('aria-label', isEnglishUi() ? 'Submit form' : 'फारम पेश गर्नुहोस्');
            var label = (origBtn.textContent || origBtn.value || '').trim() || (isEnglishUi() ? 'Submit' : 'पेश गर्नुहोस्');
            bar.innerHTML = '<button type="button" class="btn btn-primary w-100"></button>';
            bar.querySelector('button').textContent = label;
            bar.style.cssText = 'position:fixed;left:0;right:0;padding:10px 16px 16px;display:none;z-index:1040;';
            document.body.appendChild(bar);
            bar.querySelector('button').addEventListener('click', function () {
                if (origBtn.disabled || origBtn.getAttribute('aria-busy') === 'true') return;
                if (typeof form.requestSubmit === 'function') form.requestSubmit(origBtn);
                else origBtn.click();
            });
            try {
                var mo = new MutationObserver(function () {
                    var stickyBtn = bar.querySelector('button');
                    if (!stickyBtn) return;
                    if (origBtn.getAttribute('aria-busy') === 'true') {
                        stickyBtn.setAttribute('aria-busy', 'true');
                        stickyBtn.disabled = true;
                        stickyBtn.textContent = (origBtn.textContent || '').trim() || stickyBtn.textContent;
                    } else {
                        stickyBtn.removeAttribute('aria-busy');
                        stickyBtn.disabled = !!origBtn.disabled;
                    }
                });
                mo.observe(origBtn, { attributes: true, attributeFilter: ['aria-busy', 'disabled'], childList: true, characterData: true, subtree: true });
            } catch (err) { /* ignore */ }
            var ob = new IntersectionObserver(function (entries) {
                bar.style.display = entries[0].isIntersecting ? 'none' : 'block';
            }, { threshold: 0.1 });
            ob.observe(origBtn);
        });
    }

    /* ─── 8. IMG LAZY LOAD — native lazy where not already set ─ */
    function lazyImages() {
        document.querySelectorAll('img:not([loading])').forEach(function (img) {
            /* Keep LCP / above-fold logos eager if marked */
            if (img.getAttribute('fetchpriority') === 'high') return;
            img.setAttribute('loading', 'lazy');
        });
        document.querySelectorAll('img:not([decoding])').forEach(function (img) {
            img.setAttribute('decoding', 'async');
        });
        document.querySelectorAll(
            '.news-card img, .notice-card img, .gallery-card img, .service-card img'
        ).forEach(function (img) {
            if (img.dataset.coopImgBound) return;
            img.dataset.coopImgBound = '1';
            function markReady() {
                img.classList.add('coop-img-ready');
            }
            if (img.complete && img.naturalWidth > 0) {
                markReady();
            } else {
                img.addEventListener('load', markReady, { once: true });
                img.addEventListener('error', markReady, { once: true });
            }
        });
    }

    /* ─── 9. CALM SCROLL REVEAL — opt-in + safe auto targets ─── */
    function initCoopReveal() {
        var reduce = prefersReducedMotion();
        var nodes = document.querySelectorAll(
            '.coop-reveal, .news-card:not([data-aos]), .notice-card:not([data-aos]), .service-card:not([data-aos]), .gallery-card:not([data-aos]), .midx-ds-card:not([data-aos]), .section-header-unified:not([data-aos]), .faq-accordion .accordion-item:not([data-aos])'
        );
        if (!nodes.length) return;

        if (reduce || !('IntersectionObserver' in window)) {
            nodes.forEach(function (el) {
                el.classList.add('coop-reveal', 'is-in');
            });
            return;
        }

        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-in');
                io.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });

        nodes.forEach(function (el, idx) {
            /* Skip above-the-fold / already visible — avoid flash */
            var rect = el.getBoundingClientRect();
            if (rect.top < window.innerHeight * 0.88 && rect.bottom > 0) {
                el.classList.add('coop-reveal', 'is-in');
                return;
            }
            el.classList.add('coop-reveal');
            if (idx < 24) {
                el.style.transitionDelay = Math.min(idx * 0.04, 0.28) + 's';
            }
            io.observe(el);
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
        initCoopReveal();
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
