/**
 * Visual Font Awesome icon picker for admin forms.
 * Usage: add class `js-fa-icon-picker` on a wrapper that contains:
 *   - [data-fa-input] text input (name=icon / cat_icon)
 *   - optional [data-fa-preview] element for live <i>
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
          '<h5><i class="fas fa-icons me-2"></i>Select Icon</h5>' +
          '<button type="button" class="fa-ip-close" data-fa-close aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="fa-ip-head" style="padding-top:0;border-bottom:0;">' +
          '<input type="search" class="fa-ip-search" placeholder="Search: piggy, heart, hands..." data-fa-search>' +
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
      iconClass.replace(/^fas\s+fa-/, '').indexOf(searchTerm) !== -1;
  }

  function renderCats() {
    var wrap = modal.querySelector('[data-fa-cats]');
    var html = '<button type="button" class="fa-ip-cat' + (activeCat === 'all' ? ' is-active' : '') + '" data-fa-cat="all">All</button>';
    ICON_GROUPS.forEach(function (g) {
      html += '<button type="button" class="fa-ip-cat' + (activeCat === g.id ? ' is-active' : '') + '" data-fa-cat="' + g.id + '">' + g.label + '</button>';
    });
    wrap.innerHTML = html;
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
        var short = icon.replace(/^fas\s+fa-/, '');
        html += '<button type="button" class="fa-ip-item' + (icon === current ? ' is-selected' : '') + '" data-fa-icon="' + icon + '" title="' + icon + '">' +
          '<i class="' + icon + '"></i><span>' + short + '</span></button>';
      });
      html += '</div>';
    });
    if (!shown) html = '<div class="fa-ip-empty">No icons match your search.</div>';
    body.innerHTML = html;
  }

  function setPreview(previewEl, iconClass) {
    if (!previewEl) return;
    previewEl.innerHTML = '<i class="' + String(iconClass || 'fas fa-th-large').replace(/"/g, '') + '"></i>';
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
    setTimeout(function () { if (search) search.focus(); }, 30);
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    activeInput = null;
    activePreview = null;
  }

  function enhance(root) {
    var scope = root || document;
    scope.querySelectorAll('.js-fa-icon-picker').forEach(function (wrap) {
      if (wrap.dataset.faReady === '1') return;
      wrap.dataset.faReady = '1';

      var input = wrap.querySelector('[data-fa-input]') || wrap.querySelector('input[type="text"]');
      if (!input) return;
      input.setAttribute('data-fa-input', '');

      var preview = wrap.querySelector('[data-fa-preview]');
      if (!preview) {
        preview = document.createElement('span');
        preview.className = 'fa-ip-preview';
        preview.setAttribute('data-fa-preview', '');
        wrap.insertBefore(preview, wrap.firstChild);
      }
      setPreview(preview, input.value || 'fas fa-th-large');

      var openBtn = wrap.querySelector('[data-fa-open]');
      if (!openBtn) {
        openBtn = document.createElement('button');
        openBtn.type = 'button';
        openBtn.className = 'btn btn-success fa-ip-open';
        openBtn.setAttribute('data-fa-open', '');
        openBtn.innerHTML = '<i class="fas fa-th"></i> Icons';
        if (input.parentElement && input.parentElement.classList.contains('input-group')) {
          input.parentElement.appendChild(openBtn);
        } else {
          wrap.appendChild(openBtn);
        }
      }

      openBtn.addEventListener('click', function (e) {
        e.preventDefault();
        openModal(input, preview);
      });

      input.addEventListener('input', function () {
        setPreview(preview, input.value || 'fas fa-th-large');
      });
    });
  }

  function mountField(options) {
    /* Helper if needed by other pages later */
    return options;
  }

  window.FaIconPicker = {
    enhance: enhance,
    open: openModal,
    close: closeModal,
    groups: ICON_GROUPS
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { enhance(document); });
  } else {
    enhance(document);
  }
})(window, document);
