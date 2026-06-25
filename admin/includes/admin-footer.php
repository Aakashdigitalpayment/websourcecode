        </div><!-- End page-content -->

        <!-- v9.6 Mobile bottom-nav (admin) -->
        <style>
        .mob-bottomnav {
            position: fixed; bottom: 0; left: 0; right: 0;
            display: flex; align-items: stretch;
            background: #fff; border-top: 2px solid #e5e7eb;
            z-index: 8999; height: 58px;
            box-shadow: 0 -4px 14px rgba(0,0,0,.10);
        }
        .mob-bottomnav a.mob-bn-item {
            flex: 1; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            color: #6b7280; text-decoration: none;
            font-size: 10px; gap: 3px; padding: 6px 4px;
            transition: color .15s, background .15s;
            border-right: 1px solid #f3f4f6;
        }
        .mob-bottomnav a.mob-bn-item:last-child { border-right: none; }
        .mob-bottomnav a.mob-bn-item i { font-size: 18px; line-height: 1; }
        .mob-bottomnav a.mob-bn-item span { font-size: 9.5px; line-height: 1; }
        .mob-bottomnav a.mob-bn-item.active,
        .mob-bottomnav a.mob-bn-item:hover { color: var(--primary-color,#1a5f2a); background: rgba(26,95,42,.05); }
        body.has-bottomnav { padding-bottom: 58px !important; }
        @media (min-width: 900px) { .mob-bottomnav { display: none; } body.has-bottomnav { padding-bottom: 0 !important; } }
        </style>
        <nav class="mob-bottomnav" aria-label="Admin quick nav">
            <a href="<?php echo ADMIN_URL; ?>dashboard.php" class="mob-bn-item <?php echo ($currentPage??'')==='dashboard'?'active':''; ?>"><i class="lucide-icon" aria-hidden="true" data-lucide="layout-dashboard"></i><span><?php echo !empty($adminIsEnglish) ? 'Dashboard' : 'ड्यासबोर्ड'; ?></span></a>
            <a href="<?php echo ADMIN_URL; ?>notices.php" class="mob-bn-item"><i class="lucide-icon" aria-hidden="true" data-lucide="megaphone"></i><span><?php echo !empty($adminIsEnglish) ? 'Notices' : 'सूचना'; ?></span></a>
            <a href="<?php echo ADMIN_URL; ?>members.php" class="mob-bn-item"><i class="lucide-icon" aria-hidden="true" data-lucide="users"></i><span><?php echo !empty($adminIsEnglish) ? 'Members' : 'सदस्य'; ?></span></a>
            <a href="<?php echo ADMIN_URL; ?>hrm-employees.php" class="mob-bn-item <?php echo (in_array(($currentPage??''), ['hrm-dashboard','hrm-employees','hrm-employee-directory','hrm-departments','hrm-contracts','hrm-documents','hrm-messenger']) ? 'active' : ''); ?>"><i class="lucide-icon" aria-hidden="true" data-lucide="badge-check"></i><span><?php echo !empty($adminIsEnglish) ? 'HRM' : 'HRM'; ?></span></a>
            <a href="<?php echo ADMIN_URL; ?>settings.php" class="mob-bn-item"><i class="lucide-icon" aria-hidden="true" data-lucide="settings"></i><span><?php echo !empty($adminIsEnglish) ? 'Settings' : 'सेटिङ'; ?></span></a>
            <a href="<?php echo ADMIN_URL; ?>logout.php" class="mob-bn-item"><i class="lucide-icon" aria-hidden="true" data-lucide="log-out"></i><span><?php echo !empty($adminIsEnglish) ? 'Logout' : 'लगआउट'; ?></span></a>
        </nav>
        <script>document.body.classList.add('has-bottomnav');</script>

        <!-- v11.1 Floating internal-messenger button (admin only) -->
        <a href="<?php echo ADMIN_URL; ?>hrm-messenger.php"
           class="admin-fab-msg"
           title="<?php echo !empty($adminIsEnglish) ? 'Internal Chat' : 'आन्तरिक च्याट'; ?>"
           aria-label="Internal Chat">
          <i class="lucide-icon" aria-hidden="true" data-lucide="message-circle"></i>
        </a>
        <style>
          .admin-fab-msg{
            position:fixed; right:18px; bottom:90px; z-index:60;
            width:54px; height:54px; border-radius:50%;
            background:var(--primary-color,#1a5f2a); color:#fff;
            display:grid; place-items:center; font-size:20px;
            box-shadow:0 10px 24px rgba(15,23,42,.18);
            text-decoration:none; transition:transform .15s, box-shadow .15s;
          }
          .admin-fab-msg:hover{ transform:translateY(-2px); color:#fff; box-shadow:0 14px 30px rgba(15,23,42,.22); }
          @media (min-width:900px){ .admin-fab-msg{ bottom:24px; } }
        </style>
    </main><!-- End main-content -->
    </div><!-- End admin-wrapper -->

    <!-- Bootstrap JS -->
    <script src="<?php echo defined('SITE_URL') ? SITE_URL : '../'; ?>assets/vendor/bootstrap.bundle.min.js"></script>

    <!-- jQuery (Bootstrap भन्दा पछि, DataTables र Nepali Datepicker को लागि) -->
    <script src="../assets/vendor/jquery.min.js"></script>

    <!-- DataTables JS -->
    <script src="../assets/vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="../assets/vendor/datatables/dataTables.bootstrap5.min.js"></script>

    <!-- CKEditor for rich text editing -->
    <script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>

    <!-- Nepali Datepicker JS v5 (self-hosted — CSS admin-header.php मा load भएको छ) -->
    <script src="../assets/js/nepali.datepicker.min.js"></script>

    <!-- Admin JS -->
    <script src="assets/admin.js"></script>
    <script src="../assets/js/v9-mobile-fix.js?v=9.7" defer></script>

    <!-- PWA — Service Worker + Install Handler -->
    <script src="../assets/js/pwa-register.js" defer></script>

    <script>
    /* =====================================================
       ADMIN FOOTER SCRIPTS
       यहाँ admin panel का सबै global JS functions छन्।
       ===================================================== */

    /* ─────────────────────────────────────────────────────
       Nepali Datepicker initialize गर्ने function
       — Page load र Bootstrap Modal open दुवैमा काम गर्छ
       —
       FIX: jQuery plugin हो — $(input).nepaliDatePicker()
            DOM element.nepaliDatePicker() गर्दा काम गर्दैन
       ───────────────────────────────────────────────────── */
    function initNepaliDatepickers(container) {
        /* jQuery र nepaliDatePicker library दुवै load भएको हुनुपर्छ */
        if (typeof $ === 'undefined' || typeof $.fn.nepaliDatePicker === 'undefined') return;

        var scope  = $(container || document);
        var inputs = scope.find('.nepali-datepicker').addBack('.nepali-datepicker');

        inputs.each(function() {
            var $inp = $(this);

            /* Already initialized? Skip — double-init बाट जोगाउन */
            if ($inp.data('ndp-ready')) return;
            $inp.data('ndp-ready', true);

            /* Nepali datepicker v5 initialize */
            $inp.nepaliDatePicker({
                dateFormat : 'YYYY-MM-DD',
                language   : 'nepali'
            });

            /* Calendar icon button छ भने — click गर्दा datepicker open हुन्छ (v5: focus event) */
            $inp.closest('.input-group, .nepali-datepicker-wrapper')
                .find('.ndp-trigger, .input-group-text').on('click.ndp', function() {
                    $inp.trigger('focus');
                });
        });
    }

    document.addEventListener('DOMContentLoaded', function() {

        /* --- Global: explicit BS calendar only (avoid silent UX mutation) --- */
        document.querySelectorAll('input[type="date"][data-calendar="bs"]:not([data-keep-gregorian])').forEach(function(inp) {
            if (inp.dataset.ndpAutoDone === '1') return;
            inp.dataset.ndpAutoDone = '1';
            try { inp.type = 'text'; } catch (e) {}
            inp.classList.add('nepali-datepicker');
            if (!inp.getAttribute('placeholder')) inp.setAttribute('placeholder', 'YYYY-MM-DD');
            inp.setAttribute('autocomplete', 'off');
        });

        /* --- Page load मा datepicker init --- */
        initNepaliDatepickers(document);

        /* --- Bootstrap modal खुल्दा modal भित्रका datepickers पनि init --- */
        document.addEventListener('shown.bs.modal', function(e) {
            if (e.target && e.target.classList.contains('modal')) {
                initNepaliDatepickers(e.target);
            }
        });

        /* ─────────────────────────────────────────────────────
           CSRF: हरेक POST form मा CSRF token auto-inject गर्नुहोस्
           — JS injection: PHP embedded token नभएका forms को लागि
           ───────────────────────────────────────────────────── */
        var csrfToken = '<?php echo htmlspecialchars($csrfToken ?? generateCSRFToken(), ENT_QUOTES, "UTF-8"); ?>';

        function injectCsrf() {
            document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function(form) {
                if (!form.querySelector('input[name="csrf_token"]')) {
                    var field   = document.createElement('input');
                    field.type  = 'hidden';
                    field.name  = 'csrf_token';
                    field.value = csrfToken;
                    form.appendChild(field);
                }
            });
        }
        injectCsrf();
        /* Modal खुलेपछि पनि CSRF inject गर्नुहोस् */
        document.addEventListener('shown.bs.modal', injectCsrf);

        /* ─────────────────────────────────────────────────────
           AUTO SPLIT: list + form एउटै row भए tabs बनाउने
           उद्देश्य: admin pages मा गजंगुज layout कम गर्नु
           ───────────────────────────────────────────────────── */
        function autoSplitListFormRows() {
            // Pages with manual tab architecture: do not auto-split.
            if (/\/admin\/(election-candidates|election-information|election-posts|designations)\.php/i.test(window.location.pathname)) {
                return;
            }
            var hasEditIntent = /(?:\?|&)(edit|edit_|action=add|action=edit|panel=form|panel=candidates|panel=positions)=?/i.test(window.location.search);
            var rows = document.querySelectorAll('.container-fluid .row, .admin-page .row');
            rows.forEach(function(row, idx) {
                if (row.closest('.tab-content')) return;
                if (row.dataset.autoSplitDone === '1') return;
                // If page already has custom/manual tabs right above this row, do not auto-split again.
                var prev = row.previousElementSibling;
                if (prev && prev.classList && prev.classList.contains('admin-nav-tabs') && !prev.classList.contains('admin-auto-tabs')) return;
                var cols = Array.prototype.slice.call(row.children).filter(function(ch) {
                    return ch.className && /col-/.test(ch.className) && !ch.classList.contains('d-none');
                });
                if (cols.length !== 2) return;

                var c1 = cols[0], c2 = cols[1];
                var c1HasForm = !!c1.querySelector('form');
                var c2HasForm = !!c2.querySelector('form');
                var c1HasList = !!(c1.querySelector('table') || c1.querySelector('.table-responsive'));
                var c2HasList = !!(c2.querySelector('table') || c2.querySelector('.table-responsive'));
                if (!(c1HasForm && c2HasList) && !(c2HasForm && c1HasList)) return;

                var formCol = c1HasForm ? c1 : c2;
                var listCol = c1HasForm ? c2 : c1;
                var nav = document.createElement('ul');
                nav.className = 'nav nav-tabs admin-nav-tabs mb-2 admin-auto-tabs';

                var liList = document.createElement('li');
                liList.className = 'nav-item';
                liList.innerHTML = '<button type="button" class="nav-link"><i class="fas fa-list me-2"></i>सूची</button>';
                var liForm = document.createElement('li');
                liForm.className = 'nav-item';
                liForm.innerHTML = '<button type="button" class="nav-link"><i class="fas fa-pen me-2"></i>फर्म</button>';
                nav.appendChild(liList);
                nav.appendChild(liForm);

                row.parentNode.insertBefore(nav, row);

                var btnList = liList.querySelector('.nav-link');
                var btnForm = liForm.querySelector('.nav-link');
                function show(which) {
                    if (which === 'form') {
                        listCol.style.display = 'none';
                        formCol.style.display = '';
                        btnForm.classList.add('active');
                        btnList.classList.remove('active');
                    } else {
                        formCol.style.display = 'none';
                        listCol.style.display = '';
                        btnList.classList.add('active');
                        btnForm.classList.remove('active');
                    }
                }
                btnList.addEventListener('click', function() { show('list'); });
                btnForm.addEventListener('click', function() { show('form'); });
                show(hasEditIntent ? 'form' : 'list');
                row.dataset.autoSplitDone = '1';
            });
        }
        autoSplitListFormRows();

        /* ─────────────────────────────────────────────────────
           UNIFIED REQUEST DETAIL SHELL
           पुराना single-record detail pages लाई same tab layout मा राख्ने।
           ───────────────────────────────────────────────────── */
        function initLegacyRequestDetailTabs() {
            document.querySelectorAll('.arv-legacy-detail:not([data-arv-legacy-done])').forEach(function(card, cardIndex) {
                var body = Array.prototype.slice.call(card.children).find(function(el) {
                    return el.classList && el.classList.contains('card-body');
                });
                if (!body) return;

                var directRows = Array.prototype.slice.call(body.children).filter(function(el) {
                    return el.classList && el.classList.contains('row');
                });
                var detailRow = directRows.find(function(row) {
                    var cols = Array.prototype.slice.call(row.children).filter(function(ch) {
                        return ch.className && /(^|\s)col-/.test(ch.className);
                    });
                    if (cols.length < 2) return false;
                    var hasInfo = cols.some(function(col) { return !!col.querySelector('.adm-info-group'); });
                    var hasAction = cols.some(function(col) {
                        return !!(col.querySelector('form') || col.querySelector('.card') || col.querySelector('.btn'));
                    });
                    return hasInfo && hasAction;
                });

                var sourceNodes = [];
                var sidebarSource = null;
                if (detailRow) {
                    var cols = Array.prototype.slice.call(detailRow.children).filter(function(ch) {
                        return ch.className && /(^|\s)col-/.test(ch.className);
                    });
                    sidebarSource = cols.find(function(col) { return !!col.querySelector('form'); }) || cols[cols.length - 1];
                    var mainSource = cols.find(function(col) { return col !== sidebarSource && !!col.querySelector('.adm-info-group'); }) || cols[0];
                    sourceNodes = Array.prototype.slice.call(mainSource.children);
                } else {
                    sourceNodes = Array.prototype.slice.call(body.children);
                }

                var tabs = document.createElement('div');
                tabs.className = 'arv-legacy-tabs no-print';
                var shell = document.createElement('div');
                shell.className = 'arv-legacy-shell' + (sidebarSource ? '' : ' arv-legacy-shell--single');
                var main = document.createElement('div');
                main.className = 'arv-legacy-main';
                var sidebar = document.createElement('aside');
                sidebar.className = 'arv-legacy-sidebar no-print';

                var panes = {
                    overview: makePane('overview', true),
                    docs: makePane('docs', false),
                    log: makePane('log', false)
                };

                var DOCS_RE  = /(कागजात|प्रतिलिपि|प्रमाण|document|attached|attachment|संलग्न|फाइल|file|photo|फोटो|signature|हस्ताक्षर|download|डाउनलोड|national\s*id|नागरिकता\s*\/?\s*national)/i;
                var LOG_RE   = /(status\s*\/\s*comment\s*history|history|activity\s*log|gatividhi|गतिविधि|इतिहास|comment\s*history)/i;
                var SKIP_TAG = { SCRIPT: 1, STYLE: 1, LINK: 1, META: 1 };

                function nodeHasImage(n) {
                    return !!n.querySelector('img.img-thumbnail, img[class*="thumb"], img[class*="doc"], .acc-doc-thumb, .img-thumbnail');
                }
                function nodeHasFileLink(n) {
                    return !!n.querySelector('a[download], a[target="_blank"][href*="upload"], a[href$=".pdf"], a[href$=".jpg"], a[href$=".jpeg"], a[href$=".png"], a[href$=".webp"]');
                }
                function nodeIsImageWrap(n) {
                    if (!n.querySelector) return false;
                    var imgs = n.querySelectorAll('img');
                    if (!imgs.length) return false;
                    var headerEl = n.querySelector('.adm-info-group-header, .card-header, h5, h6');
                    return !headerEl;
                }

                sourceNodes.forEach(function(node) {
                    if (!node || node.nodeType !== 1) return;
                    if (SKIP_TAG[node.tagName]) { panes.overview.appendChild(node); node.style.display = 'none'; return; }

                    if (node.id === 'kycInfoTabs' || node.classList && node.classList.contains('kyc-mini-tab-bar')) {
                        node.style.display = 'none';
                        panes.overview.appendChild(node);
                        return;
                    }

                    if (node.style && node.style.display === 'none') node.style.display = '';

                    var text = (node.textContent || '').replace(/\s+/g, ' ').trim();
                    var header = node.querySelector('.adm-info-group-header, .card-header, h5, h6');
                    var headText = ((header && header.textContent) || text).replace(/\s+/g, ' ').trim();

                    if (LOG_RE.test(headText)) {
                        panes.log.appendChild(node);
                    } else if (DOCS_RE.test(headText) || nodeHasImage(node) || nodeHasFileLink(node) || nodeIsImageWrap(node)) {
                        panes.docs.appendChild(node);
                    } else {
                        panes.overview.appendChild(node);
                    }
                });

                ['overview','docs','log'].forEach(function(k) {
                    panes[k].querySelectorAll('.adm-info-group').forEach(function(g) {
                        if (g.style && g.style.display === 'none') g.style.display = '';
                    });
                });

                if (sidebarSource) {
                    Array.prototype.slice.call(sidebarSource.children).forEach(function(node) {
                        sidebar.appendChild(node);
                    });
                }

                body.innerHTML = '';
                if (!panes.docs.children.length) {
                    panes.docs.innerHTML = '<div class="arv-empty-tab"><i class="lucide-icon" aria-hidden="true" data-lucide="folder-open"></i><strong>कागजात छैन</strong><span>यस अनुरोधमा कागजात / attachment भेटिएन।</span></div>';
                }
                if (!panes.log.children.length) {
                    panes.log.innerHTML = '<div class="arv-empty-tab"><i class="fas fa-clock-rotate-left"></i><strong>गतिविधि लग छैन</strong><span>Status/comment history उपलब्ध छैन।</span></div>';
                }
                var availableTabs = [
                    ['overview', '<i class="lucide-icon" aria-hidden="true" data-lucide="id-card"></i> अवलोकन'],
                    ['docs', '<i class="fas fa-paperclip me-1"></i> कागजात'],
                    ['log', '<i class="fas fa-clock-rotate-left me-1"></i> गतिविधि लग']
                ];

                availableTabs.forEach(function(item, index) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = index === 0 ? 'active' : '';
                    btn.innerHTML = item[1];
                    btn.addEventListener('click', function() {
                        Array.prototype.slice.call(tabs.children).forEach(function(b) { b.classList.remove('active'); });
                        Array.prototype.slice.call(main.children).forEach(function(p) { p.classList.remove('active'); });
                        btn.classList.add('active');
                        panes[item[0]].classList.add('active');
                    });
                    tabs.appendChild(btn);
                    if (index !== 0) panes[item[0]].classList.remove('active');
                    main.appendChild(panes[item[0]]);
                });

                body.appendChild(tabs);
                shell.appendChild(main);
                if (sidebarSource && sidebar.children.length) shell.appendChild(sidebar);
                body.appendChild(shell);
                card.dataset.arvLegacyDone = '1';

                function makePane(name, active) {
                    var pane = document.createElement('section');
                    pane.className = 'arv-legacy-pane' + (active ? ' active' : '');
                    pane.dataset.tab = name;
                    return pane;
                }
            });
        }
        initLegacyRequestDetailTabs();

        /* ─────────────────────────────────────────────────────
           TAB STANDARDIZATION (global)
           Rule:
             1) 'सूची' tab first
             2) 'नयाँ थप्नुहोस्' tab second
           ───────────────────────────────────────────────────── */
        function normalizeAdminTabs() {
            /* Respect page-defined tab labels/order; do not rewrite globally. */
            return;
            var tabBars = document.querySelectorAll('.admin-nav-tabs');
            tabBars.forEach(function(bar) {
                var items = Array.prototype.slice.call(bar.querySelectorAll(':scope > .nav-item'));
                if (!items.length) return;

                function getLabelEl(item) {
                    return item.querySelector('.nav-link, button.nav-link, a.nav-link');
                }
                function textOf(item) {
                    var el = getLabelEl(item);
                    return ((el ? el.textContent : item.textContent) || '').replace(/\s+/g, ' ').trim();
                }
                function isListTab(txt) {
                    return /(सूची|list|records|items|data)/i.test(txt);
                }
                function isFormTab(txt) {
                    return /(नयाँ|थप्नुहोस्|add|create|new|edit|सम्पादन)/i.test(txt);
                }

                var listItem = null;
                var formItem = null;
                items.forEach(function(it) {
                    var t = textOf(it);
                    if (!listItem && isListTab(t)) listItem = it;
                    if (!formItem && isFormTab(t)) formItem = it;
                });

                if (listItem && formItem && listItem !== formItem) {
                    // enforce order: list first, form second
                    bar.prepend(listItem);
                    if (listItem.nextSibling !== formItem) {
                        listItem.insertAdjacentElement('afterend', formItem);
                    }

                    // enforce label wording
                    var listEl = getLabelEl(listItem);
                    var formEl = getLabelEl(formItem);
                    if (listEl) {
                        var listBadge = listEl.querySelector('.badge');
                        var listBadgeHtml = listBadge ? listBadge.outerHTML : '';
                        listEl.innerHTML = '<i class="fas fa-list me-2"></i>सूची' + (listBadgeHtml ? (' ' + listBadgeHtml) : '');
                    }
                    if (formEl) {
                        var formBadge = formEl.querySelector('.badge');
                        var formBadgeHtml = formBadge ? formBadge.outerHTML : '';
                        formEl.innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ थप्नुहोस्' + (formBadgeHtml ? (' ' + formBadgeHtml) : '');
                    }
                }
            });
        }
        normalizeAdminTabs();

        /* ─────────────────────────────────────────────────────
           Hide verbose guidance/info blocks for cleaner UI
           (keep dismissible success/error alerts visible)
           ───────────────────────────────────────────────────── */
        function hideAdminGuidanceBlocks() {
            /* Keep author-defined guidance/alerts visible by default. */
            return;
            document.querySelectorAll('.admin-help-tip').forEach(function(el) {
                el.style.display = 'none';
            });

            document.querySelectorAll('.alert.alert-info:not([data-keep-info])').forEach(function(el) {
                el.style.display = 'none';
            });

            document.querySelectorAll('.card .card-header h5, .card .card-header h6').forEach(function(h) {
                var t = (h.textContent || '').replace(/\s+/g, ' ').trim();
                if (/^जानकारी$| जानकारी$|^info$/i.test(t) || /गतिशील पृष्ठहरू|स्थिर विषयवस्तु/i.test(t)) {
                    var card = h.closest('.card');
                    if (card) card.style.display = 'none';
                }
            });
        }
        hideAdminGuidanceBlocks();

        /* ─────────────────────────────────────────────────────
           DELETE links: GET delete link → safe POST+CSRF form
           — a[href*="action=delete"] ले GET delete बाट जोगाउँछ
           ───────────────────────────────────────────────────── */
        document.querySelectorAll('a[href*="action=delete"], a[href*="delete="]').forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                if (!confirm(<?php echo json_encode(!empty($adminIsEnglish) ? 'Are you sure you want to delete this item?' : 'के तपाईं यो मेटाउन निश्चित हुनुहुन्छ?'); ?>)) return;
                var form = document.createElement('form');
                form.method = 'POST';
                var url = new URL(link.href, window.location.origin);
                form.action = url.pathname;
                url.searchParams.forEach(function(val, key) {
                    var inp   = document.createElement('input');
                    inp.type  = 'hidden';
                    inp.name  = key;
                    inp.value = val;
                    form.appendChild(inp);
                });
                var csrf   = document.createElement('input');
                csrf.type  = 'hidden';
                csrf.name  = 'csrf_token';
                csrf.value = csrfToken;
                form.appendChild(csrf);
                document.body.appendChild(form);
                form.submit();
            });
        });

        /* ─────────────────────────────────────────────────────
           DataTable: Auto-initialize all .data-table tables
           ───────────────────────────────────────────────────── */
        if (typeof $ !== 'undefined' && $.fn.DataTable) {
            /* colspan rows in empty tbody ले tn/18 alert दिन्छ — suppress गर्छौं */
            $.fn.dataTable.ext.errMode = 'none';
            document.querySelectorAll('table.data-table:not(.dataTable), table.pf-data-table:not(.dataTable), table[class*="-data-table"]:not(.dataTable)').forEach(function(tbl) {
                try {
                    $(tbl).DataTable({
                        language: {
                            search    : <?php echo json_encode(!empty($adminIsEnglish) ? 'Search:' : 'खोज्नुहोस्:'); ?>,
                            lengthMenu: <?php echo json_encode(!empty($adminIsEnglish) ? '_MENU_ rows per page' : '_MENU_ पङ्क्ति प्रति पृष्ठ'); ?>,
                            info      : <?php echo json_encode(!empty($adminIsEnglish) ? '_START_–_END_ / total _TOTAL_' : '_START_–_END_ / जम्मा _TOTAL_'); ?>,
                            paginate  : { previous: '‹', next: '›' },
                            emptyTable: <?php echo json_encode(!empty($adminIsEnglish) ? 'No data available' : 'कुनै डाटा छैन'); ?>
                        },
                        pageLength: 10,
                        lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
                        responsive: true
                    });
                } catch(dtErr) {
                    console.warn('DataTables init skipped for', tbl.id || tbl.className, ':', dtErr.message);
                }
            });
        }

        /* ─────────────────────────────────────────────────────
           Flash message: 5 सेकेन्डमा auto-hide
           ───────────────────────────────────────────────────── */
        var flash = document.querySelector('.alert-dismissible');
        if (flash) {
            setTimeout(function() {
                flash.style.transition = 'opacity 0.5s';
                flash.style.opacity    = '0';
                setTimeout(function() { flash.remove(); }, 500);
            }, 5000);
        }
    });
    </script>
<?php if (function_exists('coopThemeLucideInit')) { coopThemeLucideInit(); } ?>
</body>
</html>
