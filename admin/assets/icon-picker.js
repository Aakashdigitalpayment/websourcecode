/**
 * Visual Font Awesome icon picker for admin forms.
 * Storage SSOT: input still saves FA class strings (`fas fa-star`) — no DB rewrite.
 * Preview/grid: Lucide for fas/far; brand packs (`fab`) stay Font Awesome.
 * Usage: add class `js-fa-icon-picker` on a wrapper that contains:
 *   - [data-fa-input] text input (name=icon / cat_icon)
 *   - optional [data-fa-preview] element for live preview
 *   - optional [data-fa-open] button
 */
(function (window, document) {
  'use strict';

  var ICON_GROUPS = [
    {
      id: 'financial',
      label: 'Financial',
      icons: [
        'fas fa-piggy-bank', 'fas fa-hand-holding-usd', 'fas fa-money-bill-wave', 'fas fa-coins',
        'fas fa-wallet', 'fas fa-credit-card', 'fas fa-university', 'fas fa-chart-line',
        'fas fa-percentage', 'fas fa-file-invoice-dollar', 'fas fa-cash-register', 'fas fa-sack-dollar'
      ]
    },
    {
      id: 'people',
      label: 'People',
      icons: [
        'fas fa-users', 'fas fa-user', 'fas fa-user-tie', 'fas fa-user-friends',
        'fas fa-user-shield', 'fas fa-user-check', 'fas fa-user-plus', 'fas fa-people-carry',
        'fas fa-hands-helping', 'fas fa-handshake', 'fas fa-child', 'fas fa-female'
      ]
    },
    {
      id: 'health',
      label: 'Health',
      icons: [
        'fas fa-heartbeat', 'fas fa-heart', 'fas fa-notes-medical', 'fas fa-briefcase-medical',
        'fas fa-hospital', 'fas fa-stethoscope', 'fas fa-hand-holding-heart', 'fas fa-plus-square'
      ]
    },
    {
      id: 'education',
      label: 'Education',
      icons: [
        'fas fa-graduation-cap', 'fas fa-book', 'fas fa-book-open', 'fas fa-chalkboard-teacher',
        'fas fa-school', 'fas fa-certificate', 'fas fa-award', 'fas fa-lightbulb'
      ]
    },
    {
      id: 'agriculture',
      label: 'Agriculture',
      icons: [
        'fas fa-seedling', 'fas fa-leaf', 'fas fa-tractor', 'fas fa-tree',
        'fas fa-mountain', 'fas fa-water', 'fas fa-sun', 'fas fa-cloud-sun-rain'
      ]
    },
    {
      id: 'business',
      label: 'Business',
      icons: [
        'fas fa-briefcase', 'fas fa-building', 'fas fa-store', 'fas fa-shopping-bag',
        'fas fa-chart-pie', 'fas fa-balance-scale', 'fas fa-clipboard-list', 'fas fa-file-contract',
        'fas fa-stamp', 'fas fa-gavel'
      ]
    },
    {
      id: 'community',
      label: 'Community',
      icons: [
        'fas fa-home', 'fas fa-house-user', 'fas fa-hands', 'fas fa-praying-hands',
        'fas fa-place-of-worship', 'fas fa-church', 'fas fa-dove', 'fas fa-gift',
        'fas fa-calendar-check', 'fas fa-bullhorn', 'fas fa-flag', 'fas fa-sitemap'
      ]
    },
    {
      id: 'digital',
      label: 'Digital',
      icons: [
        'fas fa-mobile-alt', 'fas fa-laptop', 'fas fa-wifi', 'fas fa-globe',
        'fas fa-qrcode', 'fas fa-shield-alt', 'fas fa-lock', 'fas fa-key',
        'fas fa-cloud', 'fas fa-database', 'fas fa-cogs', 'fas fa-th-large'
      ]
    },
    {
      id: 'brands',
      label: 'Brands (FA)',
      icons: [
        'fab fa-facebook-f', 'fab fa-facebook', 'fab fa-youtube', 'fab fa-whatsapp',
        'fab fa-google', 'fab fa-google-play', 'fab fa-apple', 'fab fa-twitter',
        'fab fa-instagram', 'fab fa-linkedin-in', 'fab fa-tiktok', 'fab fa-viber'
      ]
    }
  ];

  var modal = null;
  var activeInput = null;
  var activePreview = null;
  var activeCat = 'all';
  var searchTerm = '';

  function ensureModal() {
    if (modal) return modal;
    modal = document.createElement('div');
    modal.className = 'fa-ip-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.innerHTML =
      '<div class="fa-ip-backdrop" data-fa-close></div>' +
      '<div class="fa-ip-dialog">' +
        '<div class="fa-ip-head">' +
          '<h5><i class="lucide-icon me-2" aria-hidden="true" data-lucide="layout-grid"></i>Select Icon</h5>' +
          '<button type="button" class="fa-ip-close" data-fa-close aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="fa-ip-storage-note">DB stores FA class (e.g. <code>fas fa-star</code>). Brands stay <code>fab</code>.</div>' +
        '<div class="fa-ip-head" style="padding-top:0;border-bottom:0;">' +
          '<input type="search" class="fa-ip-search" placeholder="Search: piggy, heart, facebook..." data-fa-search>' +
        '</div>' +
        '<div class="fa-ip-cats" data-fa-cats></div>' +
        '<div class="fa-ip-body" data-fa-body></div>' +
      '</div>';
    document.body.appendChild(modal);

    modal.addEventListener('click', function (e) {
      if (e.target.closest('[data-fa-close]')) closeModal();
      var item = e.target.closest('[data-fa-icon]');
      if (item) {
        selectIcon(item.getAttribute('data-fa-icon'));
      }
      var catBtn = e.target.closest('[data-fa-cat]');
      if (catBtn) {
        activeCat = catBtn.getAttribute('data-fa-cat') || 'all';
        renderCats();
        renderGrid();
      }
    });

    var search = modal.querySelector('[data-fa-search]');
    search.addEventListener('input', function () {
      searchTerm = (search.value || '').trim().toLowerCase();
      renderGrid();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });

    return modal;
  }

  function iconMatches(iconClass) {
    if (!searchTerm) return true;
    return iconClass.toLowerCase().indexOf(searchTerm) !== -1 ||
      iconClass.replace(/^(fa[srlb]?\s+)?fa-/i, '').indexOf(searchTerm) !== -1;
  }

  function renderCats() {
    var wrap = modal.querySelector('[data-fa-cats]');
    var html = '<button type="button" class="fa-ip-cat' + (activeCat === 'all' ? ' is-active' : '') + '" data-fa-cat="all">All</button>';
    ICON_GROUPS.forEach(function (g) {
      html += '<button type="button" class="fa-ip-cat' + (activeCat === g.id ? ' is-active' : '') + '" data-fa-cat="' + g.id + '">' + g.label + '</button>';
    });
    wrap.innerHTML = html;
  }

  function faClassToLucideName(iconClass) {
    var s = String(iconClass || '').trim();
    if (!s) return 'layout-grid';
    if (/\bfab\b|\bfa-brands\b/i.test(s)) return null; /* brand → keep FA */
    if (typeof window.coopFaToLucide === 'function') {
      var mapped = window.coopFaToLucide(s);
      if (mapped && /^[a-z0-9-]+$/i.test(mapped)) return mapped.toLowerCase();
    }
    var bare = s.replace(/^(fa[srlb]?\s+)?fa-/i, '').trim().toLowerCase();
    var FIX = {
      'check-circle': 'circle-check', 'x-circle': 'circle-x', 'times-circle': 'circle-x',
      'exclamation-circle': 'circle-alert', 'exclamation-triangle': 'triangle-alert',
      'home': 'house', 'cog': 'settings', 'cogs': 'settings', 'search': 'search',
      'mobile-alt': 'smartphone', 'file-alt': 'file-text', 'map-marker-alt': 'map-pin',
      'calendar-alt': 'calendar', 'shield-alt': 'shield', 'external-link-alt': 'external-link',
      'hand-holding-usd': 'banknote', 'university': 'landmark', 'th-large': 'layout-grid',
      'piggy-bank': 'piggy-bank', 'handshake': 'handshake', 'users': 'users',
      'user-tie': 'user-round-tie', 'graduation-cap': 'graduation-cap', 'heartbeat': 'heart-pulse',
      'balance-scale': 'scale', 'bullhorn': 'megaphone', 'praying-hands': 'hands-praying',
      'hand-holding-heart': 'hand-heart', 'notes-medical': 'notebook-pen',
      'briefcase-medical': 'briefcase-medical', 'seedling': 'sprout', 'tractor': 'tractor',
      'place-of-worship': 'church', 'chalkboard-teacher': 'presentation',
      'user-friends': 'users', 'people-carry': 'users', 'hands-helping': 'handshake',
      'money-bill-wave': 'banknote', 'file-invoice-dollar': 'file-text',
      'cash-register': 'calculator', 'sack-dollar': 'wallet', 'percentage': 'percent',
      'chart-pie': 'chart-pie', 'clipboard-list': 'clipboard-list', 'file-contract': 'file-text',
      'house-user': 'house', 'calendar-check': 'calendar-check', 'sitemap': 'network'
    };
    bare = FIX[bare] || bare;
    return /^[a-z0-9-]+$/.test(bare) ? bare : 'circle';
  }

  function setPreview(previewEl, iconClass) {
    if (!previewEl) return;
    var raw = String(iconClass || 'fas fa-th-large').replace(/"/g, '');
    var lucide = faClassToLucideName(raw);
    if (lucide === null) {
      previewEl.innerHTML = '<i class="' + raw + '" aria-hidden="true"></i>';
      return;
    }
    previewEl.innerHTML = '<i class="lucide-icon" data-lucide="' + lucide + '" aria-hidden="true"></i>';
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      var node = previewEl.querySelector('[data-lucide]');
      if (node) window.lucide.createIcons({ nodes: [node] });
    }
  }

  function renderIconMarkup(iconClass) {
    var lucide = faClassToLucideName(iconClass);
    if (lucide === null) {
      return '<i class="' + iconClass + '" aria-hidden="true"></i>';
    }
    return '<i class="lucide-icon" data-lucide="' + lucide + '" aria-hidden="true"></i>';
  }

  function renderGrid() {
    var body = modal.querySelector('[data-fa-body]');
    var current = activeInput ? (activeInput.value || '') : '';
    var html = '';
    var shown = 0;
    ICON_GROUPS.forEach(function (g) {
      if (activeCat !== 'all' && activeCat !== g.id) return;
      var items = g.icons.filter(iconMatches);
      if (!items.length) return;
      html += '<div class="fa-ip-group-title">' + g.label + '</div><div class="fa-ip-grid">';
      items.forEach(function (icon) {
        shown++;
        var short = icon.replace(/^(fa[srlb]?\s+)?fa-/i, '');
        html += '<button type="button" class="fa-ip-item' + (icon === current ? ' is-selected' : '') + '" data-fa-icon="' + icon + '" title="' + icon + '">' +
          renderIconMarkup(icon) + '<span>' + short + '</span></button>';
      });
      html += '</div>';
    });
    if (!shown) html = '<div class="fa-ip-empty">No icons match your search.</div>';
    body.innerHTML = html;
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons({ nodes: body.querySelectorAll('[data-lucide]') });
    }
  }

  function selectIcon(iconClass) {
    if (!activeInput) return;
    activeInput.value = iconClass;
    setPreview(activePreview, iconClass);
    activeInput.dispatchEvent(new Event('input', { bubbles: true }));
    activeInput.dispatchEvent(new Event('change', { bubbles: true }));
    closeModal();
  }

  function openModal(input, preview) {
    ensureModal();
    activeInput = input;
    activePreview = preview;
    activeCat = 'all';
    searchTerm = '';
    var search = modal.querySelector('[data-fa-search]');
    if (search) search.value = '';
    renderCats();
    renderGrid();
    modal.classList.add('is-open');
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      var headNodes = modal.querySelectorAll('.fa-ip-head [data-lucide]');
      if (headNodes.length) window.lucide.createIcons({ nodes: headNodes });
    }
    setTimeout(function () { if (search) search.focus(); }, 30);
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    activeInput = null;
    activePreview = null;
  }

  function wireField(input, preview, openBtn) {
    if (!input || input.dataset.faReady === '1') return;
    input.dataset.faReady = '1';
    input.setAttribute('data-fa-input', '');

    if (!preview) {
      preview = document.createElement('span');
      preview.className = 'fa-ip-preview input-group-text';
      preview.setAttribute('data-fa-preview', '');
      if (input.parentElement && input.parentElement.classList.contains('input-group')) {
        input.parentElement.insertBefore(preview, input);
      } else {
        input.parentElement.insertBefore(preview, input);
      }
    } else {
      preview.setAttribute('data-fa-preview', '');
    }
    setPreview(preview, input.value || 'fas fa-th-large');

    if (!openBtn) {
      openBtn = document.createElement('button');
      openBtn.type = 'button';
      openBtn.className = 'btn btn-success fa-ip-open';
      openBtn.setAttribute('data-fa-open', '');
      openBtn.setAttribute('title', 'Select icon');
      openBtn.innerHTML = '<i class="lucide-icon me-1" aria-hidden="true" data-lucide="layout-grid"></i><span>छान्नुहोस्</span>';
      if (input.parentElement && input.parentElement.classList.contains('input-group')) {
        input.parentElement.appendChild(openBtn);
      } else {
        var row = document.createElement('div');
        row.className = 'input-group fa-ip-row';
        input.parentNode.insertBefore(row, input);
        row.appendChild(preview);
        row.appendChild(input);
        row.appendChild(openBtn);
      }
    } else if (!openBtn.querySelector('span') && openBtn.textContent.trim() === '') {
      openBtn.innerHTML = '<i class="lucide-icon me-1" aria-hidden="true" data-lucide="layout-grid"></i><span>छान्नुहोस्</span>';
    }
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      var openIcon = openBtn.querySelector('[data-lucide]');
      if (openIcon) window.lucide.createIcons({ nodes: [openIcon] });
      var headIcon = modal && modal.querySelector('.fa-ip-head [data-lucide]');
      if (headIcon) window.lucide.createIcons({ nodes: [headIcon] });
    }

    openBtn.addEventListener('click', function (e) {
      e.preventDefault();
      openModal(input, preview);
    });

    input.addEventListener('input', function () {
      setPreview(preview, input.value || 'fas fa-th-large');
    });
  }

  function looksLikeFaIconField(input) {
    if (!input || input.disabled || input.readOnly) return false;
    if (input.type && input.type !== 'text' && input.type !== 'search') return false;
    var sample = ((input.value || '') + ' ' + (input.placeholder || '')).toLowerCase();
    return /(^|\s)(fas|far|fab|fal|fad)\s+fa-/.test(sample) || sample.indexOf('fa-') !== -1;
  }

  function enhance(root) {
    var scope = root || document;

    scope.querySelectorAll('.js-fa-icon-picker').forEach(function (wrap) {
      if (wrap.dataset.faReady === '1') return;
      wrap.dataset.faReady = '1';
      var input = wrap.querySelector('[data-fa-input]') || wrap.querySelector('input[type="text"]');
      if (!input) return;
      var preview = wrap.querySelector('[data-fa-preview]');
      var openBtn = wrap.querySelector('[data-fa-open]');
      wireField(input, preview, openBtn);
    });

    /* Auto-wire every admin FA icon text field (services, menu categories, links, etc.) */
    scope.querySelectorAll('input[name="icon"], input[name="cat_icon"], input[name="menu_icon"], input[name$="_icon"]').forEach(function (input) {
      if (input.dataset.faReady === '1') return;
      if (input.closest('.js-fa-icon-picker')) return;
      var force = (input.name === 'icon' || input.name === 'cat_icon' || input.name === 'menu_icon');
      if (!force && !looksLikeFaIconField(input)) return;

      var group = input.closest('.input-group');
      var preview = group ? group.querySelector('[data-fa-preview], .input-group-text') : null;
      var openBtn = group ? group.querySelector('[data-fa-open]') : null;
      wireField(input, preview, openBtn);
    });
  }

  window.FaIconPicker = {
    enhance: enhance,
    open: openModal,
    close: closeModal,
    groups: ICON_GROUPS,
    setPreview: setPreview,
    faClassToLucideName: faClassToLucideName
  };

  function boot() {
    enhance(document);
    /* Re-wire when Bootstrap tabs/panels become visible */
    document.addEventListener('shown.bs.tab', function () { enhance(document); });
    document.addEventListener('shown.bs.collapse', function () { enhance(document); });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(window, document);
