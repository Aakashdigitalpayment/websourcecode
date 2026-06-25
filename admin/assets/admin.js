/**
 * =====================================================
 * ADMIN PANEL — JAVASCRIPT FILE
 * फाइल: admin/assets/admin.js
 *
 * यो फाइलमा admin panel को सबै JavaScript code छ।
 * यसलाई बिना कारण परिवर्तन नगर्नुहोस्।
 * =====================================================
 */


/* =====================================================
   MODAL FUNCTIONS
   — Pop-up box खोल्ने र बन्द गर्ने functions
   — यी functions HTML मा onclick="openModal('...')" गरेर call हुन्छन्
   ===================================================== */

/**
 * Modal (popup box) खोल्ने function
 * @param {string} modalId - HTML मा modal को id attribute
 * Usage: onclick="openModal('myModal')"
 */
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'block';
        modal.classList.add('show');
        document.body.style.overflow = 'hidden'; /* पछाडि scroll बन्द गर्छ */
    }
}

/**
 * Modal (popup box) बन्द गर्ने function
 * @param {string} modalId - HTML मा modal को id attribute
 * Usage: onclick="closeModal('myModal')"
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
        document.body.style.overflow = ''; /* scroll फेरि सुरु गर्छ */
    }
}

/* Modal को बाहिर (dark area) click गर्दा बन्द हुन्छ */
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
        e.target.classList.remove('show');
        document.body.style.overflow = '';
    }
});

/* Keyboard को Escape key थिच्दा सबै modal बन्द हुन्छन् */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(function(modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
            document.body.style.overflow = '';
        });
    }
});


/* =====================================================
   ADMIN TAB SWITCHER — Bootstrap-independent
   — bootstrap.Tab API fail भएमा पनि काम गर्छ
   — Usage: adminSwitchTab(showBtn, hideBtn)
   — showBtn/hideBtn: DOM elements with data-bs-target
   ===================================================== */
window.adminSwitchTab = function(showEl, hideEl) {
    if (!showEl) return;
    var showPaneId = showEl.getAttribute('data-bs-target');
    if (!showPaneId) return;

    /* ── Step 1: Deactivate ALL sibling tabs in the same <ul> ──
       Works for 2-tab pages (list/form) AND 3-tab pages (saving/loan/form) */
    var parentNav = showEl.closest('ul.nav, ol.nav');
    if (parentNav) {
        parentNav.querySelectorAll('.nav-link').forEach(function(btn) {
            var pid = btn.getAttribute('data-bs-target');
            btn.classList.remove('active');
            btn.setAttribute('aria-selected', 'false');
            if (pid) {
                var p = document.querySelector(pid);
                if (p) p.classList.remove('show', 'active');
            }
        });
    } else if (hideEl) {
        /* Fallback: manually hide the specified tab only */
        hideEl.classList.remove('active');
        hideEl.setAttribute('aria-selected', 'false');
        var hidePaneId = hideEl.getAttribute('data-bs-target');
        if (hidePaneId) {
            var hidePane = document.querySelector(hidePaneId);
            if (hidePane) hidePane.classList.remove('show', 'active');
        }
    }

    /* ── Step 2: Activate target tab button + pane ── */
    showEl.classList.add('active');
    showEl.setAttribute('aria-selected', 'true');
    var showPane = document.querySelector(showPaneId);
    if (showPane) {
        showPane.classList.add('show', 'active');
        setTimeout(function() {
            showPane.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 50);
    }

    /* ── Step 3: Bootstrap Tab API — runs if Bootstrap is loaded ── */
    try {
        if (typeof bootstrap !== 'undefined' && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(showEl).show();
        }
    } catch(e) { /* CSS-only fallback above handles it */ }
};


/* =====================================================
   PAGE LOAD — DOMContentLoaded
   — Page पूरा load भएपछि यो section चल्छ
   ===================================================== */
document.addEventListener('DOMContentLoaded', function() {

    /* -------------------------------------------------
       GLOBAL EDIT BUTTON SAFETY
       — धेरै list pages मा edit buttons table/form भित्र हुन्छन्
       — type नदिएको button default submit हुन सक्छ (form खुल्दैन)
       — सबै edit buttons लाई safe type="button" enforce गर्नुहोस्
    ------------------------------------------------- */
    document.querySelectorAll('button[class*="btn-edit"], button.wc-edit-btn').forEach(function(btn) {
        var t = (btn.getAttribute('type') || '').trim().toLowerCase();
        if (t === '' || t === 'submit') {
            btn.setAttribute('type', 'button');
        }
    });

    /* -------------------------------------------------
       GLOBAL EDIT -> FORM TAB FALLBACK
       — केही page मा page-specific JS crash/skip हुँदा पनि
         edit click गरेपछि form tab खुलोस्।
       — Existing per-page handlers लाई interfere नगरी
         fallback रूपमा मात्र काम गर्छ।
    ------------------------------------------------- */
    function __autoOpenFormTabFrom(btn) {
        if (!btn) return;
        var scope = btn.closest('.main-content, .container, .container-fluid') || document;
        var candidates = Array.from(scope.querySelectorAll('.nav-link[data-bs-target]')).filter(function (el) {
            var trg = (el.getAttribute('data-bs-target') || '').toLowerCase();
            return trg.indexOf('form') !== -1;
        });
        if (!candidates.length) return;
        var formBtn = candidates.find(function (el) { return !el.classList.contains('active'); }) || candidates[0];
        if (!formBtn) return;
        var nav = formBtn.closest('ul.nav, .nav');
        var activeBtn = nav ? nav.querySelector('.nav-link.active[data-bs-target]') : null;
        if (typeof adminSwitchTab === 'function') {
            adminSwitchTab(formBtn, activeBtn || undefined);
        } else if (typeof bootstrap !== 'undefined' && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(formBtn).show();
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button[class*="btn-edit"], button.wc-edit-btn');
        if (!btn) return;
        setTimeout(function () { __autoOpenFormTabFrom(btn); }, 10);
    });

    /* -------------------------------------------------
       SIDEBAR TOGGLE (मोबाइल मा menu खोल्ने/बन्द गर्ने)
       — हाम्बर्गर button (≡) click गर्दा sidebar slide हुन्छ
    ------------------------------------------------- */
    /* SIDEBAR TOGGLE — fully delegated to v9-mobile-fix.js (v9.6+).
       Inline binding removed to avoid open-then-instant-close race. */


    /* -------------------------------------------------
       DATATABLES — NEPALI LANGUAGE
       — Table मा search, pagination र sorting को लागि
       — class="data-table" भएका सबै table मा automatically काम गर्छ
       — Language: Nepali मा set गरिएको छ
    ------------------------------------------------- */
    if (typeof $.fn.DataTable !== 'undefined') {
        $('.data-table').DataTable({
            language: {
                search:        "खोज्नुहोस्:",
                lengthMenu:    "_MENU_ प्रति पृष्ठ देखाउनुहोस्",
                info:          "_TOTAL_ मध्ये _START_ देखि _END_ देखाइएको",
                infoEmpty:     "कुनै डाटा उपलब्ध छैन",
                infoFiltered:  "(कुल _MAX_ डाटाबाट फिल्टर गरिएको)",
                paginate: {
                    first:    "पहिलो",
                    last:     "अन्तिम",
                    next:     "अर्को",
                    previous: "अघिल्लो"
                },
                emptyTable:   "कुनै डाटा उपलब्ध छैन",
                zeroRecords:  "मेल खाने डाटा भेटिएन"
            },
            pageLength: 10,   /* एक पटकमा कतिवटा rows देखाउने — चाहे परिवर्तन गर्न सकिन्छ */
            responsive: true  /* मोबाइलमा पनि table fit हुने */
        });
    }


    /* -------------------------------------------------
       CKEDITOR — Rich Text Editor
       — id="contentEditor" भएको textarea मा text editor load हुन्छ
       — Admin ले news, notices आदि लेख्दा use हुन्छ
    ------------------------------------------------- */
    const contentEditor = document.getElementById('contentEditor');
    if (contentEditor && typeof ClassicEditor !== 'undefined') {
        ClassicEditor
            .create(contentEditor, {
                /* Toolbar मा देखिने buttons — थप्न वा हटाउन सकिन्छ */
                toolbar: ['heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', '|', 'undo', 'redo'],
                language: 'ne' /* Nepali language */
            })
            .catch(function(error) {
                /* CKEditor load हुन नसके error log मा जान्छ — screen मा देखिँदैन */
                /* यो normal हो — कुनै action चाहिँदैन */
            });
    }


    /* -------------------------------------------------
       AUTO-DISMISS ALERTS
       — Success/Error message ५ सेकेन्डपछि automatically बन्द हुन्छ
       — 5000 = 5 seconds, परिवर्तन गर्न सकिन्छ
    ------------------------------------------------- */
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    });


    /* -------------------------------------------------
       DELETE CONFIRMATION
       — "action=delete" भएको सबै link मा click गर्दा confirm popup आउँछ
       — User ले "Cancel" गरे delete हुँदैन
    ------------------------------------------------- */
    const deleteLinks = document.querySelectorAll('a[href*="action=delete"]');
    deleteLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            if (!confirm('के तपाईं यो मेटाउन निश्चित हुनुहुन्छ?')) {
                e.preventDefault(); /* Cancel गरे link follow हुँदैन */
            }
        });
    });


    /* -------------------------------------------------
       IMAGE PREVIEW ON FILE SELECT
       — Image file choose गर्दा तुरुन्त preview देखिन्छ
       — type="file" accept="image/*" भएका सबै inputs मा काम गर्छ
    ------------------------------------------------- */
    const fileInputs = document.querySelectorAll('input[type="file"][accept*="image"]');
    fileInputs.forEach(function(input) {
        input.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    /* पहिले नै preview छ भने नयाँ नबनाउने */
                    let preview = input.parentElement.querySelector('.image-preview');
                    if (!preview) {
                        preview = document.createElement('img');
                        preview.className = 'image-preview mt-2';
                        preview.style.maxHeight = '100px';  /* preview को height — परिवर्तन गर्न सकिन्छ */
                        preview.style.borderRadius = '8px';
                        input.parentElement.appendChild(preview);
                    }
                    preview.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        });
    });

}); /* DOMContentLoaded end */


/**
 * Password visibility toggle
 * @param {string} inputId - input element id
 * @param {string} eyeId   - eye icon element id
 * Usage: onclick="togglePwd('password', 'eyeIcon')"
 */
function togglePwd(inputId, eyeId) {
    var inp = document.getElementById(inputId);
    var eye = document.getElementById(eyeId);
    if (!inp || !eye) return;
    inp.type = (inp.type === 'password') ? 'text' : 'password';
    eye.className = (inp.type === 'text') ? 'fas fa-eye-slash' : 'fas fa-eye';
}
