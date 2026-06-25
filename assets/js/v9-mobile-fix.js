/* ============================================================
 * v9.11 — Mobile menu (stable drawer + dropdowns never navigate on mobile)
 * Public + Admin drawers. Plain delegation, no z-index wars.
 * Submenus toggle on parent-link tap (mobile only) when href === '#' or 'javascript:'
 * Otherwise the link navigates.
 * ============================================================ */
(function () {
  'use strict';

  /* ── Wrap wide tables for horizontal scroll ── */
  function wrapTables() {
    document.querySelectorAll('table').forEach(function (table) {
      if (table.closest('.v9-table-scroll, .dataTables_wrapper, .cke, .ndp-container, .id-card-wrap')) return;
      var firstRow = table.querySelector('tr');
      if (!firstRow) return;
      var cols = firstRow.querySelectorAll('th, td').length;
      if (cols < 3) return;
      var wrap = document.createElement('div');
      wrap.className = 'v9-table-scroll';
      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
    });
  }

  /* ── Public site drawer ── */
  function attachPublicDrawer(toggleId, navId, closeId) {
    var toggle = document.getElementById(toggleId);
    var nav    = document.getElementById(navId);
    if (!toggle || !nav) return;
    if (toggle.dataset.v96Bound === '1') return;

    /* Match admin behavior: replace nodes to remove any pre-existing listeners */
    var freshToggle = toggle.cloneNode(true);
    toggle.parentNode.replaceChild(freshToggle, toggle);
    toggle = freshToggle;
    toggle.dataset.v96Bound = '1';
    var toggleIcon = toggle.querySelector('i');

    var closeBtn = document.getElementById(closeId);
    if (closeBtn && closeBtn.parentNode) {
      var freshClose = closeBtn.cloneNode(true);
      closeBtn.parentNode.replaceChild(freshClose, closeBtn);
      closeBtn = freshClose;
    }
    var overlays = ['menuOverlay', 'pflMobileBackdrop']
      .map(function (id) { return document.getElementById(id); })
      .filter(Boolean);
    var savedScrollY = 0;

    function cleanupSubmenuUI() {
      nav.querySelectorAll('.has-dropdown, .has-sub').forEach(function (li) {
        li.querySelectorAll(':scope > .dd-chevron-btn').forEach(function (btn) { btn.remove(); });
        var link = li.querySelector(':scope > a');
        if (!link) return;
        var inlineChevron = link.querySelector('.fa-chevron-down');
        if (inlineChevron) {
          inlineChevron.style.display = 'inline-flex';
          inlineChevron.style.marginLeft = 'auto';
          inlineChevron.style.fontSize = '.78rem';
          inlineChevron.style.opacity = '.85';
        }
      });
      nav.querySelectorAll('.dropdown li > a, .sub-menu li > a').forEach(function (a) {
        a.style.color = '#1f2937';
        a.style.fontSize = '.86rem';
        a.style.fontWeight = '640';
        a.style.lineHeight = '1.26';
      });
      nav.querySelectorAll('.dropdown li > a i, .sub-menu li > a i, .dropdown li > a .lucide-icon, .sub-menu li > a .lucide-icon').forEach(function (icon) {
        icon.style.width = '23px';
        icon.style.minWidth = '23px';
        icon.style.height = '23px';
        icon.style.fontSize = '.8rem';
        icon.style.borderRadius = '7px';
      });
    }

    function syncToggleVisualState() {
      var isOpen = nav.classList.contains('nav-open') || nav.classList.contains('open') || nav.classList.contains('active');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      toggle.classList.toggle('is-open', isOpen);
      if (toggleIcon) {
        toggleIcon.classList.toggle('fa-xmark', isOpen);
        toggleIcon.classList.toggle('fa-bars', !isOpen);
      }
      if (closeBtn) {
        // JS-level safety so leaked CSS cannot leave a floating close icon visible.
        closeBtn.style.visibility = isOpen ? 'visible' : 'hidden';
        closeBtn.style.opacity = isOpen ? '1' : '0';
        closeBtn.style.pointerEvents = isOpen ? 'auto' : 'none';
      }
      nav.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    }

    function openNav() {
      cleanupSubmenuUI();
      savedScrollY = window.scrollY || document.documentElement.scrollTop || 0;
      nav.classList.add('nav-open', 'open', 'active');
      nav.style.setProperty('transform', 'translate3d(0,0,0)', 'important');
      nav.style.setProperty('visibility', 'visible', 'important');
      nav.style.setProperty('pointer-events', 'auto', 'important');
      overlays.forEach(function (el) { el.classList.add('active'); });
      document.body.classList.add('mobile-nav-open');
      document.documentElement.classList.add('mobile-nav-open');
      document.body.style.top = '-' + savedScrollY + 'px';
      document.body.style.overflow = 'hidden';
      syncToggleVisualState();
      setTimeout(function () {
        var first = nav.querySelector('#' + closeId + ', a, button');
        if (first && typeof first.focus === 'function') first.focus({ preventScroll: true });
      }, 30);
    }
    function closeNav() {
      nav.classList.remove('nav-open', 'open', 'active');
      nav.style.removeProperty('transform');
      nav.style.removeProperty('visibility');
      nav.style.removeProperty('pointer-events');
      overlays.forEach(function (el) { el.classList.remove('active'); });
      document.body.classList.remove('mobile-nav-open');
      document.documentElement.classList.remove('mobile-nav-open');
      document.body.style.top = '';
      document.body.style.overflow = '';
      nav.querySelectorAll('.has-dropdown.open, .has-sub.open').forEach(function (el) {
        el.classList.remove('open');
      });
      nav.querySelectorAll('.dd-chevron-btn[aria-expanded="true"]').forEach(function (btn) {
        btn.setAttribute('aria-expanded', 'false');
      });
      cleanupSubmenuUI();
      if (savedScrollY) window.scrollTo(0, savedScrollY);
      syncToggleVisualState();
    }

    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (nav.classList.contains('nav-open') || nav.classList.contains('open') || nav.classList.contains('active')) {
        closeNav();
      } else {
        openNav();
      }
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        closeNav();
      });
    }
    overlays.forEach(function (el) {
      el.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        closeNav();
      });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeNav(); });

    /* ── Single delegated click handler on the nav ── */
    nav.addEventListener('click', function (e) {
      if (window.innerWidth >= 992) return;
      var link = e.target.closest('a');
      if (!link || !nav.contains(link)) return;
      var li      = link.parentElement;
      var hasSub  = li && (li.classList.contains('has-dropdown') || li.classList.contains('has-sub'));
      if (hasSub) {
        /* Mobile UX: parent rows with submenu ALWAYS toggle; child links navigate. */
        e.preventDefault();
        e.stopPropagation();
        li.classList.toggle('open');
        var directBtn = li.querySelector(':scope > .dd-chevron-btn');
        if (directBtn) directBtn.setAttribute('aria-expanded', li.classList.contains('open') ? 'true' : 'false');
        return;
      }
      /* Leaf link → navigate, close drawer */
      setTimeout(closeNav, 50);
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth >= 992) closeNav();
      else syncToggleVisualState();
    });

    window.addEventListener('pageshow', syncToggleVisualState);
    // On fresh paint, force a deterministic closed state to avoid stale class/icon artifacts.
    cleanupSubmenuUI();
    setTimeout(cleanupSubmenuUI, 80);
    setTimeout(cleanupSubmenuUI, 220);
    closeNav();
    syncToggleVisualState();

    /* Allow other modules (e.g. login dropdown) to close the public drawer safely */
    if (toggleId === 'mobileMenuToggle2' && navId === 'mainNavV2') {
      window.__pflMobileMenuOpen = openNav;
      window.__pflMobileMenuClose = closeNav;
    }
  }

  /* ── Admin sidebar drawer ── */
  function setupAdminSidebar() {
    var sidebar = document.getElementById('sidebar');
    var toggle  = document.getElementById('sidebarToggle');
    if (!sidebar || !toggle) return;
    if (toggle.dataset.v96Bound === '1') return;

    /* Replace toggle node to drop ANY pre-existing click listeners (e.g. admin.js) */
    var fresh = toggle.cloneNode(true);
    toggle.parentNode.replaceChild(fresh, toggle);
    toggle = fresh;
    toggle.dataset.v96Bound = '1';

    var overlay = document.querySelector('.sidebar-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'sidebar-overlay';
      document.body.appendChild(overlay);
    }
    var closeBtn = document.getElementById('sidebarClose');

    function openSidebar() {
      sidebar.classList.add('active');
      overlay.classList.add('active');
      document.body.classList.add('admin-nav-open');
    }
    function closeSidebar() {
      sidebar.classList.remove('active');
      overlay.classList.remove('active');
      document.body.classList.remove('admin-nav-open');
    }

    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      if (sidebar.classList.contains('active')) closeSidebar();
      else openSidebar();
    }, true); /* capture phase — fire before any bubbling listener */

    if (closeBtn) closeBtn.addEventListener('click', function(e){ e.preventDefault(); closeSidebar(); });
    overlay.addEventListener('click', closeSidebar);
    window.addEventListener('resize', function () {
      if (window.innerWidth > 991) closeSidebar();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeSidebar();
    });

    /* Sidebar internal collapsible groups */
    sidebar.addEventListener('click', function (e) {
      var trigger = e.target.closest('.has-submenu > a, [data-toggle-submenu]');
      if (!trigger) return;
      var parent = trigger.parentElement;
      if (!parent || (!parent.classList.contains('has-submenu') && !trigger.dataset.toggleSubmenu)) return;
      var href = (trigger.getAttribute('href') || '').trim();
      if (!href || href === '#' || href.indexOf('javascript:') === 0) {
        e.preventDefault();
        parent.classList.toggle('open');
      }
    });
  }


  /* ── Keep parent-row-only submenu toggles; remove any side chevron buttons ── */
  function addDropdownChevrons() {
    document.querySelectorAll('.main-nav .has-dropdown, .main-nav .has-sub').forEach(function (li) {
      var link = li.querySelector(':scope > a');
      if (!link) return;
      li.querySelectorAll(':scope > .dd-chevron-btn').forEach(function (btn) { btn.remove(); });
      var inlineChevron = link.querySelector('.fa-chevron-down');
      if (inlineChevron) {
        inlineChevron.style.display = 'inline-flex';
        inlineChevron.style.marginLeft = 'auto';
        inlineChevron.style.fontSize = '.78rem';
      }
    });
  }

  function init() {
    wrapTables();
    /* Always bind whichever nav exists — both can coexist on transition pages */
    if (document.getElementById('mobileMenuToggle2') && document.getElementById('mainNavV2')) {
      attachPublicDrawer('mobileMenuToggle2', 'mainNavV2', 'closeMenuV2');
    }
    if (document.getElementById('mobileMenuToggle') && document.getElementById('mainNav')) {
      attachPublicDrawer('mobileMenuToggle', 'mainNav', 'closeMenu');
    }
    addDropdownChevrons();
    setupAdminSidebar();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
