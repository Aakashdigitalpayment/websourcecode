// Main JavaScript File

// Page Loader with percentage - Fixed to prevent infinite loading
(function() {
    const pageLoader = document.getElementById('pageLoader');
    const progressFill = document.getElementById('progressFill');
    const progressPercent = document.getElementById('progressPercent');

    // Maximum time to show loader (5 seconds) - prevents infinite loading
    const MAX_LOADER_TIME = 5000;

    if (pageLoader && progressFill && progressPercent) {
        let progress = 0;
        document.body.style.overflow = 'hidden';

        const interval = setInterval(function() {
            progress += Math.random() * 12;
            if (progress > 90) progress = 90;
            progressFill.style.width = progress + '%';
            progressPercent.textContent = Math.round(progress);
        }, 100);

        // Function to hide loader
        function hideLoader() {
            clearInterval(interval);
            if (progressFill) progressFill.style.width = '100%';
            if (progressPercent) progressPercent.textContent = '100';

            setTimeout(function() {
                if (pageLoader) {
                    pageLoader.classList.add('loaded');
                    pageLoader.style.display = 'none';
                }
                document.body.style.overflow = '';
                document.body.classList.add('page-loaded');
            }, 300);
        }

        // Hide loader on window load
        window.addEventListener('load', hideLoader);

        // Safety timeout - hide loader after MAX_LOADER_TIME even if page not fully loaded
        // This prevents infinite loading on PHP errors
        setTimeout(function() {
            if (pageLoader && !pageLoader.classList.contains('loaded')) {
                /* loader timeout — hide silently */
                hideLoader();
            }
        }, MAX_LOADER_TIME);

        // Also hide on DOMContentLoaded as fallback for faster hiding
        document.addEventListener('DOMContentLoaded', function() {
            // Wait a bit after DOM ready, then check if still loading
            setTimeout(function() {
                if (pageLoader && !pageLoader.classList.contains('loaded')) {
                    hideLoader();
                }
            }, 2000);
        });
    } else {
        // If loader elements not found, make sure body is scrollable
        document.body.style.overflow = '';
        document.body.classList.add('page-loaded');
    }
})();

document.addEventListener('DOMContentLoaded', function() {

    // Enhanced Popup Notice V3 - Carousel with Auto-Rotate
    document.querySelectorAll('#noticePopup').forEach(function (popup, index) {
        if (index > 0) popup.remove();
    });
    const noticePopup = document.getElementById('noticePopup');
    const popupClose = document.getElementById('popupClose');
    const popupOverlay = noticePopup ? noticePopup.querySelector('.popup-overlay') : null;

    if (noticePopup && popupClose) {
        const noticeIds = noticePopup.getAttribute('data-notice-ids');
        const storageKey = 'popup_notices_seen_' + noticeIds;
        const slides = noticePopup.querySelectorAll('.popup-slide');
        const dots = noticePopup.querySelectorAll('.popup-dot');
        const prevBtn = document.getElementById('popupPrev');
        const nextBtn = document.getElementById('popupNext');
        const progressBar = document.getElementById('popupProgressBar');
        const docActions = document.getElementById('popupDocActions');
        const popupDialog = noticePopup.querySelector('.popup-dialog');

        let currentSlide = 0;
        let autoRotateInterval = null;
        let progressInterval = null;
        let progress = 0;
        const autoRotateDelay = 5000; // 5 seconds
        const progressStep = 50; // Update every 50ms

        // Check if already seen in this browser session only.
        // Old localStorage values no longer suppress the desktop popup forever.
        const alreadySeen = sessionStorage.getItem(storageKey);

        // Function to update PDF button based on current slide
        function updateDocButton() {
            if (!docActions) return;
            const activeSlide = slides[currentSlide];
            const attachment = activeSlide ? activeSlide.getAttribute('data-attachment') : '';
            const photoOnly = activeSlide ? activeSlide.getAttribute('data-photo-only') === '1' : false;

            if (attachment && !photoOnly) {
                const siteUrl = window.SITE_URL || '/';
                docActions.innerHTML = `<a href="${siteUrl}${attachment}" target="_blank" class="popup-doc-btn" title="View PDF"><i class="fas fa-file-pdf"></i></a>`;
            } else {
                docActions.innerHTML = '';
            }
        }

        // Function to go to specific slide
        function goToSlide(index) {
            if (slides.length <= 1) return;

            // Remove active from all
            slides.forEach((slide, i) => {
                slide.classList.remove('active', 'prev');
                if (i < index) slide.classList.add('prev');
            });
            dots.forEach(dot => dot.classList.remove('active'));

            // Set new active
            currentSlide = index;
            if (currentSlide >= slides.length) currentSlide = 0;
            if (currentSlide < 0) currentSlide = slides.length - 1;

            slides[currentSlide].classList.add('active');
            if (dots[currentSlide]) dots[currentSlide].classList.add('active');

            // Update PDF button
            updateDocButton();

            // Reset progress
            resetProgress();
        }

        // Progress bar functions
        function resetProgress() {
            progress = 0;
            if (progressBar) progressBar.style.width = '0%';
        }

        function updateProgress() {
            progress += (progressStep / autoRotateDelay) * 100;
            if (progressBar) progressBar.style.width = Math.min(progress, 100) + '%';

            if (progress >= 100) {
                goToSlide(currentSlide + 1);
            }
        }

        // Auto-rotate functions
        function startAutoRotate() {
            if (slides.length <= 1) return;
            stopAutoRotate();
            resetProgress();
            progressInterval = setInterval(updateProgress, progressStep);
        }

        function stopAutoRotate() {
            if (progressInterval) {
                clearInterval(progressInterval);
                progressInterval = null;
            }
        }

        // Function to close popup and mark as seen
        function closePopup() {
            noticePopup.classList.remove('show');
            stopAutoRotate();
            document.body.classList.remove('notice-popup-open');
            sessionStorage.setItem(storageKey, 'true');
        }

        // Only show if not already seen
        if (!alreadySeen) {
            setTimeout(function() {
                noticePopup.classList.add('show');
                document.body.classList.add('notice-popup-open');
                updateDocButton();
                startAutoRotate();
            }, 800);
        }

        // Navigation button events
        if (prevBtn) {
            prevBtn.addEventListener('click', function() {
                goToSlide(currentSlide - 1);
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function() {
                goToSlide(currentSlide + 1);
            });
        }

        // Dot click events
        dots.forEach((dot, index) => {
            dot.addEventListener('click', function() {
                goToSlide(index);
            });
        });

        // Pause on hover
        if (popupDialog) {
            popupDialog.addEventListener('mouseenter', function() {
                stopAutoRotate();
            });

            popupDialog.addEventListener('mouseleave', function() {
                if (noticePopup.classList.contains('show')) {
                    startAutoRotate();
                }
            });

            // Touch events for mobile
            popupDialog.addEventListener('touchstart', function() {
                stopAutoRotate();
            }, { passive: true });

            popupDialog.addEventListener('touchend', function() {
                setTimeout(function() {
                    if (noticePopup.classList.contains('show')) {
                        startAutoRotate();
                    }
                }, 2000);
            }, { passive: true });
        }

        // Close button click
        popupClose.addEventListener('click', function() {
            closePopup();
        });

        // Close on overlay click
        if (popupOverlay) {
            popupOverlay.addEventListener('click', function() {
                closePopup();
            });
        }

        // Close on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && noticePopup.classList.contains('show')) {
                closePopup();
            }
        });

        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (!noticePopup.classList.contains('show')) return;
            if (e.key === 'ArrowLeft') goToSlide(currentSlide - 1);
            if (e.key === 'ArrowRight') goToSlide(currentSlide + 1);
        });
    }

    // Mobile Menu Toggle - Critical Fix for Touch/Click Issues
    // SKIP entirely on header-v2 — drawer is owned by v9-mobile-fix.js to avoid double-binding (which caused the "blur but no menu" bug).
    const _isHeaderV2 = document.body.classList.contains('header-v2');
    const mobileMenuToggle = _isHeaderV2 ? null : document.getElementById('mobileMenuToggle');
    const mainNav          = _isHeaderV2 ? null : document.getElementById('mainNav');
    const closeMenu        = _isHeaderV2 ? null : document.getElementById('closeMenu');
    const menuOverlay      = _isHeaderV2 ? null : document.getElementById('menuOverlay');

    /* मोबाइल मेनु: स्क्रोल पोजिसन सुरक्षित राखेर बन्द गर्ने */
    var _menuScrollY = 0; // Store scroll position before locking

    function openMobileMenu() {
        // 1. Save scroll before ANY body class change
        _menuScrollY = window.scrollY || document.documentElement.scrollTop;
        // 2. Set top offset BEFORE adding menu-open class
        //    This keeps the visual scroll position when position:fixed kicks in
        document.body.style.top = '-' + _menuScrollY + 'px';
        // 3. Now add class (CSS applies position:fixed + overflow:hidden)
        document.body.classList.add('menu-open');
        if (mainNav) {
            mainNav.classList.add('active');
        }
        if (menuOverlay) {
            menuOverlay.classList.add('active');
        }
    }

    function closeMobileMenu() {
        if (mainNav) mainNav.classList.remove('active');
        if (menuOverlay) menuOverlay.classList.remove('active');
        // Close all open dropdowns when menu is closed
        document.querySelectorAll('.has-dropdown.open').forEach(function(item) {
            item.classList.remove('open');
        });
        // Capture saved scroll BEFORE removing class
        var savedScroll = _menuScrollY;
        // Remove class (removes position:fixed) — THEN clear top — THEN restore scroll
        document.body.classList.remove('menu-open');
        document.body.style.top = '';
        document.body.style.overflow = '';
        // Restore exact scroll position (iOS/Android safe)
        window.scrollTo(0, savedScroll);
    }

    if (mobileMenuToggle) {
          var _menuToggleTouched = false;
          mobileMenuToggle.style.pointerEvents = 'auto';
          mobileMenuToggle.style.cursor = 'pointer';
          mobileMenuToggle.style.zIndex   = '10001';

          mobileMenuToggle.addEventListener('touchstart', function() {
              _menuToggleTouched = true;
          }, { passive: true });

          mobileMenuToggle.addEventListener('touchend', function(e) {
              e.preventDefault();
              openMobileMenu();
              setTimeout(function() { _menuToggleTouched = false; }, 400);
          }, { passive: false });

          mobileMenuToggle.addEventListener('click', function(e) {
              if (_menuToggleTouched) { _menuToggleTouched = false; return; }
              e.preventDefault();
              openMobileMenu();
          });
      }

    if (closeMenu) {
          var _closeTouched = false;
          closeMenu.style.pointerEvents = 'auto';
          closeMenu.style.cursor = 'pointer';
          closeMenu.addEventListener('touchstart', function() { _closeTouched = true; }, { passive: true });
          closeMenu.addEventListener('touchend', function(e) {
              e.preventDefault();
              closeMobileMenu();
              setTimeout(function() { _closeTouched = false; }, 400);
          }, { passive: false });
          closeMenu.addEventListener('click', function(e) {
              if (_closeTouched) { _closeTouched = false; return; }
              e.preventDefault();
              closeMobileMenu();
          });
      }

    if (menuOverlay) {
        menuOverlay.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeMobileMenu();
        });
        menuOverlay.addEventListener('touchend', function(e) {
            e.preventDefault();
            e.stopPropagation();
            closeMobileMenu();
        });
    }

    // Legacy mobile menu support
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const navMenu = document.querySelector('.nav-menu');

    if (mobileMenuBtn && navMenu) {
        mobileMenuBtn.addEventListener('click', function() {
            navMenu.classList.toggle('active');
            this.classList.toggle('active');
        });
    }

    // Sticky Header
    const header = document.querySelector('.header');
    let lastScroll = 0;

    window.addEventListener('scroll', function() {
        const currentScroll = window.pageYOffset;

        if (currentScroll > 100) {
            header.classList.add('sticky');
        } else {
            header.classList.remove('sticky');
        }

        lastScroll = currentScroll;
    });

    // Hero Slider
    const slides = document.querySelectorAll('.hero-slide');
    const dots = document.querySelectorAll('.slider-dot');
    let currentSlide = 0;

    function showSlide(index) {
        slides.forEach((slide, i) => {
            slide.classList.remove('active');
            if (dots[i]) dots[i].classList.remove('active');
        });

        slides[index].classList.add('active');
        if (dots[index]) dots[index].classList.add('active');
    }

    function nextSlide() {
        currentSlide = (currentSlide + 1) % slides.length;
        showSlide(currentSlide);
    }

    // Auto slide every 5 seconds
    if (slides.length > 0) {
        setInterval(nextSlide, 5000);

        // Dot navigation
        dots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                currentSlide = index;
                showSlide(currentSlide);
            });
        });
    }

    // Counter Animation
    const counters = document.querySelectorAll('.counter-number');
    const speed = 200;

    const animateCounters = () => {
        counters.forEach(counter => {
            const target = +counter.getAttribute('data-target');
            const count = +counter.innerText.replace(/,/g, '');
            const increment = target / speed;

            if (count < target) {
                counter.innerText = Math.ceil(count + increment).toLocaleString();
                setTimeout(animateCounters, 10);
            } else {
                counter.innerText = target.toLocaleString();
            }
        });
    };

    // Intersection Observer for counter animation
    const counterSection = document.querySelector('.counter-section');
    if (counterSection) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCounters();
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        observer.observe(counterSection);
    }

    // Smooth Scroll for anchor links - but not for dropdown toggles
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        // Skip if this is a dropdown toggle (has fa-chevron-down icon)
        if (anchor.querySelector('.fa-chevron-down') || anchor.closest('.has-dropdown')) {
            return;
        }

        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            // Only handle if it's a valid anchor and not just "#"
            if (href && href !== '#' && href.length > 1) {
                const target = document.querySelector(href);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            }
        });
    });

    // Notice Ticker - Clone items for continuous scrolling
    const tickerScroll = document.querySelector('.ticker-scroll');
    if (tickerScroll) {
        // Clone all ticker items for seamless loop
        const tickerItems = tickerScroll.innerHTML;
        tickerScroll.innerHTML = tickerItems + tickerItems;

        // Pause on hover
        tickerScroll.addEventListener('mouseenter', function() {
            this.style.animationPlayState = 'paused';
        });
        tickerScroll.addEventListener('mouseleave', function() {
            this.style.animationPlayState = 'running';
        });
    }

    // Notice Marquee Pause on Hover
    const marquee = document.querySelector('.notice-marquee');
    if (marquee) {
        marquee.addEventListener('mouseenter', function() {
            this.style.animationPlayState = 'paused';
        });
        marquee.addEventListener('mouseleave', function() {
            this.style.animationPlayState = 'running';
        });
    }

    // Gallery Lightbox
    const galleryItems = document.querySelectorAll('.gallery-item img');

    galleryItems.forEach(item => {
        item.addEventListener('click', function() {
            const lightbox = document.createElement('div');
            lightbox.className = 'lightbox';
            lightbox.innerHTML = `
                <div class="lightbox-content">
                    <span class="lightbox-close">&times;</span>
                    <img src="${this.src}" alt="${this.alt}">
                </div>
            `;
            document.body.appendChild(lightbox);

            lightbox.addEventListener('click', function(e) {
                if (e.target === lightbox || e.target.classList.contains('lightbox-close')) {
                    lightbox.remove();
                }
            });
        });
    });

    // Form Validation
    const contactForm = document.querySelector('.contact-form form');
    if (contactForm) {
        contactForm.addEventListener('submit', function(e) {
            let isValid = true;
            const inputs = this.querySelectorAll('input[required], textarea[required]');

            inputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    input.classList.add('error');
                } else {
                    input.classList.remove('error');
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('कृपया सबै आवश्यक फिल्डहरू भर्नुहोस्।');
            }
        });
    }

    // Scroll Progress Bar and Navigation
    const scrollProgressBar = document.getElementById('scrollProgressBar');
    const scrollNav = document.getElementById('scrollNav');
    const scrollPercent = document.getElementById('scrollPercent');
    const scrollUpBtn = document.getElementById('scrollUp');
    const scrollDownBtn = document.getElementById('scrollDown');

    function updateScrollProgress() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
        const scrollPercentage = (scrollTop / scrollHeight) * 100;

        // Update progress bar
        if (scrollProgressBar) {
            scrollProgressBar.style.width = scrollPercentage + '%';
        }

        // Update percentage text
        if (scrollPercent) {
            scrollPercent.textContent = Math.round(scrollPercentage) + '%';
        }

        // Show/hide scroll nav buttons
        if (scrollNav) {
            if (scrollTop > 200) {
                scrollNav.classList.add('show');
            } else {
                scrollNav.classList.remove('show');
            }
        }
    }

    // Scroll event listener
    window.addEventListener('scroll', updateScrollProgress);

    // Scroll Up Button
    if (scrollUpBtn) {
        scrollUpBtn.addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
    }

    // Scroll Down Button — एक screen तल जान्छ (पहिले: एकदमै तल जान्थ्यो)
    if (scrollDownBtn) {
        scrollDownBtn.addEventListener('click', function() {
            window.scrollBy({
                top: window.innerHeight * 0.85,
                behavior: 'smooth'
            });
        });
    }

    // ─────────────────────────────────────────────────────────
    // KEYBOARD ARROW KEY SCROLL SUPPORT
    // ↑ / ↓ arrow keys ले smooth scroll हुन्छ
    // Input/Textarea मा type गर्दा trigger हुँदैन
    // ─────────────────────────────────────────────────────────
    document.addEventListener('keydown', function(e) {
        var tag = (document.activeElement || {}).tagName || '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

        if (e.key === 'ArrowDown' || e.key === 'PageDown') {
            e.preventDefault();
            window.scrollBy({ top: window.innerHeight * 0.75, behavior: 'smooth' });
        } else if (e.key === 'ArrowUp' || e.key === 'PageUp') {
            e.preventDefault();
            window.scrollBy({ top: -(window.innerHeight * 0.75), behavior: 'smooth' });
        } else if (e.key === 'Home') {
            e.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else if (e.key === 'End') {
            e.preventDefault();
            window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
        }
    });

    // ─────────────────────────────────────────────────────────
    // TOUCH SWIPE GESTURE SUPPORT (Mobile)
    // छोटो swipe = normal scroll (native)
    // छिटो flick (velocity > 0.4px/ms) = एक screen scroll
    // ─────────────────────────────────────────────────────────
    var _swipeLock    = false;   /* एकपटक swipe भएपछि lock */
    var _swipeStartY  = 0;
    var _swipeStartX  = 0;
    var _swipeStartT  = 0;

    document.addEventListener('touchstart', function(e) {
        _swipeStartY = e.touches[0].clientY;
        _swipeStartX = e.touches[0].clientX;
        _swipeStartT = Date.now();
    }, { passive: true });

    document.addEventListener('touchend', function(e) {
        if (_swipeLock) return;

        var deltaY   = e.changedTouches[0].clientY - _swipeStartY;
        var deltaX   = e.changedTouches[0].clientX - _swipeStartX;
        var deltaT   = Date.now() - _swipeStartT;
        var velocity = Math.abs(deltaY) / Math.max(deltaT, 1); /* px/ms */

        /* Horizontal swipe हो भने skip गर्नुहोस् */
        if (Math.abs(deltaX) > Math.abs(deltaY)) return;

        /* Fast flick: velocity > 0.4px/ms AND swipe distance > 40px */
        if (velocity > 0.4 && Math.abs(deltaY) > 40) {
            _swipeLock = true;
            var scrollAmount = window.innerHeight * 0.80;

            window.scrollBy({
                top: deltaY < 0 ? scrollAmount : -scrollAmount, /* swipe up = scroll down */
                behavior: 'smooth'
            });

            /* Visual feedback: scroll nav flash */
            if (scrollNav) {
                scrollNav.classList.add('swipe-flash');
                setTimeout(function() { scrollNav.classList.remove('swipe-flash'); }, 400);
            }

            setTimeout(function() { _swipeLock = false; }, 700);
        }
    }, { passive: true });

    // Initialize on load
    updateScrollProgress();

    // Dropdown Menu for Mobile - scope legacy handlers to OLD header only.
    // Header v2 drawer is handled by assets/js/v9-mobile-fix.js.
    const legacyDropdownToggles = document.querySelectorAll('#mainNav .has-dropdown > a');

    legacyDropdownToggles.forEach(function(toggle) {
        function handleDropdownToggle(e) {
            if (window.innerWidth <= 991) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                const parentItem = toggle.parentElement;
                const isOpen = parentItem.classList.contains('open');

                document.querySelectorAll('#mainNav .has-dropdown.open').forEach(function(item) {
                    if (item !== parentItem) {
                        item.classList.remove('open');
                    }
                });

                if (isOpen) {
                    parentItem.classList.remove('open');
                } else {
                    parentItem.classList.add('open');
                }

                return false;
            }
        }

        toggle.addEventListener('click', handleDropdownToggle);

        toggle.addEventListener('touchend', function(e) {
            if (window.innerWidth <= 991) {
                e.preventDefault();
                e.stopPropagation();
                handleDropdownToggle(e);
            }
        }, { passive: false });
    });

    // Legacy menu close handlers — keep them scoped to #mainNav only.
    document.querySelectorAll('#mainNav .nav-menu > li:not(.has-dropdown) > a').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 991) {
                closeMobileMenu();
            }
        });
    });

    document.querySelectorAll('#mainNav .nav-menu .dropdown li a').forEach(function(link) {
        link.addEventListener('click', function(e) {
            if (window.innerWidth <= 991) {
                // Small delay to allow navigation to start
                setTimeout(function() {
                    closeMobileMenu();
                }, 50);
            }
        });
    });

    /* ============================================================
       Issue #8: Smooth Scroll with natural "physics" feel
       - All internal links स्वतः smooth scroll हुन्छ
       - Scroll speed: medium (natural feel)
       - Touch/finger swipe मा पनि काम गर्छ (CSS-level)
       ============================================================ */
    (function initSmoothScroll() {
        /* CSS smooth-scroll पहिले enable गर्नुहोस् */
        document.documentElement.style.scrollBehavior = 'smooth';

        /* Anchor links ("#section") मा smooth scroll */
        document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
            anchor.addEventListener('click', function (e) {
                var targetId = this.getAttribute('href');
                if (!targetId || targetId === '#') return;
                var target = document.querySelector(targetId);
                if (!target) return;
                e.preventDefault();

                /* Header height को लागि offset */
                var headerHeight = document.querySelector('.main-header, .navbar, header')?.offsetHeight || 70;
                var targetPos = target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 16;

                /* Custom smooth scroll (easing) */
                var startPos = window.pageYOffset;
                var distance = targetPos - startPos;
                var duration = Math.min(Math.abs(distance) * 0.5, 800); /* max 800ms */
                var startTime = null;

                /* Ease-in-out cubic — natural feel */
                function easeInOutCubic(t) {
                    return t < 0.5 ? 4*t*t*t : 1 - Math.pow(-2*t + 2, 3) / 2;
                }

                function scrollStep(timestamp) {
                    if (!startTime) startTime = timestamp;
                    var elapsed = timestamp - startTime;
                    var progress = Math.min(elapsed / duration, 1);
                    window.scrollTo(0, startPos + distance * easeInOutCubic(progress));
                    if (progress < 1) requestAnimationFrame(scrollStep);
                }

                requestAnimationFrame(scrollStep);
            });
        });

        /* Touch scroll: momentum-based feel (CSS) */
        document.body.style.webkitOverflowScrolling = 'touch';
    })();

    /* ============================================================
       Issue #13: Mobile Menu Click/Blur Fix
       - Mobile मा nav link click गर्दा menu automatically बन्द हुन्छ
       - Outside click गर्दा पनि menu बन्द हुन्छ
       - Bootstrap navbar toggle को सही behavior
       ============================================================ */
    (function initMobileMenuFix() {
        /* Bootstrap navbar collapse */
        var navbarCollapse = document.querySelector('.navbar-collapse');
        var navbarToggler  = document.querySelector('.navbar-toggler');

        if (!navbarCollapse) return;

        /* Nav links click गर्दा menu बन्द गर्नुहोस् */
        navbarCollapse.querySelectorAll('.nav-link, .dropdown-item').forEach(function (link) {
            link.addEventListener('click', function () {
                /* Mobile viewport मा मात्र */
                if (window.innerWidth < 992 && navbarCollapse.classList.contains('show')) {
                    /* Bootstrap collapse बन्द गर्नुहोस् */
                    if (window.bootstrap && window.bootstrap.Collapse) {
                        var bsCollapse = window.bootstrap.Collapse.getInstance(navbarCollapse);
                        if (bsCollapse) bsCollapse.hide();
                    } else {
                        navbarCollapse.classList.remove('show');
                        if (navbarToggler) navbarToggler.setAttribute('aria-expanded', 'false');
                    }
                }
            });
        });

        /* Outside click गर्दा menu बन्द गर्नुहोस् */
        document.addEventListener('click', function (e) {
            if (window.innerWidth >= 992) return; /* desktop मा skip */
            if (!navbarCollapse.classList.contains('show')) return;
            var navbar = navbarCollapse.closest('nav, .navbar, header');
            if (navbar && !navbar.contains(e.target)) {
                if (window.bootstrap && window.bootstrap.Collapse) {
                    var bsCollapse = window.bootstrap.Collapse.getInstance(navbarCollapse);
                    if (bsCollapse) bsCollapse.hide();
                } else {
                    navbarCollapse.classList.remove('show');
                    if (navbarToggler) navbarToggler.setAttribute('aria-expanded', 'false');
                }
            }
        });

        /* Escape key press गर्दा menu बन्द गर्नुहोस् */
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && navbarCollapse.classList.contains('show')) {
                if (window.bootstrap && window.bootstrap.Collapse) {
                    var bsCollapse = window.bootstrap.Collapse.getInstance(navbarCollapse);
                    if (bsCollapse) bsCollapse.hide();
                } else {
                    navbarCollapse.classList.remove('show');
                }
                if (navbarToggler) navbarToggler.focus();
            }
        });
    })();

    // App Features Show More/Less functionality
    const showMoreBtn = document.getElementById('showMoreFeatures');
    const showLessBtn = document.getElementById('showLessFeatures');
    const hiddenFeatures = document.querySelectorAll('.app-feature-item.hidden-feature');

    if (showMoreBtn && hiddenFeatures.length > 0) {
        showMoreBtn.addEventListener('click', function() {
            // Show all hidden features with animation
            hiddenFeatures.forEach(function(feature, index) {
                setTimeout(function() {
                    feature.classList.add('show');
                }, index * 100);
            });

            // Toggle buttons
            showMoreBtn.classList.add('d-none');
            if (showLessBtn) {
                showLessBtn.classList.remove('d-none');
            }
        });
    }

    if (showLessBtn && hiddenFeatures.length > 0) {
        showLessBtn.addEventListener('click', function() {
            // Hide all hidden features
            hiddenFeatures.forEach(function(feature) {
                feature.classList.remove('show');
            });

            // Toggle buttons
            showLessBtn.classList.add('d-none');
            if (showMoreBtn) {
                showMoreBtn.classList.remove('d-none');
            }

            // Scroll to section header
            const appFeaturesSection = document.querySelector('.app-features-section');
            if (appFeaturesSection) {
                appFeaturesSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }
});
