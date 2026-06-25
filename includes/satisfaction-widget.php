<?php
/**
 * Member Satisfaction Floating Widget
 * File: includes/satisfaction-widget.php
 *
 * यो file footer.php ले automatically include गर्छ।
 * Admin ले admin/satisfaction-settings.php बाट links manage गर्न सक्छ।
 *
 * Features:
 *   - Page को दाया मध्य भागमा floating icon देखिन्छ
 *   - Click गर्दा 1-5 links देखिन्छ (desktop र mobile दुवैमा)
 *   - Mouse/Touch drag गरेर widget माथि/तल सार्न सकिन्छ
 *   - Popup WhatsApp र अन्य floating buttons भन्दा माथि आउँछ
 *   - Admin ले enable/disable गर्न सक्छ (disable भए icon देखिँदैन)
 */

// Database बाट satisfaction links ल्याउने — safe try-catch
$satisfactionLinks   = [];
$satisfactionEnabled = false;

try {
    $db = getDB();

    /* ── Table existence check — fetch() प्रयोग गर्नुहोस्, rowCount() होइन ──
       PDO को rowCount() ले SHOW TABLES query मा MySQL मा गलत result
       दिन्छ (always 0 फर्काउन सक्छ)। fetch() !== false मात्र reliable छ। */
    $tableCheck = $db->query("SHOW TABLES LIKE 'satisfaction_links'");
    if ($tableCheck && $tableCheck->fetch() !== false) {

        /* Widget enabled छ कि छैन — getSetting() function use गर्नुहोस् */
        $satisfactionEnabled = (getSetting('satisfaction_widget_enabled', '0') == '1');

        /* Active links ल्याउनुहोस् — enabled भएमा मात्र */
        if ($satisfactionEnabled) {
            $linksStmt = $db->prepare("SELECT * FROM satisfaction_links WHERE is_active = 1 ORDER BY display_order ASC LIMIT 5");
            $linksStmt->execute();
            $satisfactionLinks = $linksStmt->fetchAll() ?: [];
        }
    }
} catch (Exception $e) {
    /* Table छैन वा DB error — widget नदेखाउनुहोस्, कुनै error नदेखाउनुहोस् */
    $satisfactionEnabled = false;
    $satisfactionLinks   = [];
}

// Widget enabled छ र links छन् भने मात्र HTML render गर्नुहोस्
if (!$satisfactionEnabled || empty($satisfactionLinks)) {
    return; // widget नदेखाउनुहोस्
}
?>

<!-- Member Satisfaction Floating Widget -->
<!-- Page को दाया side मा fixed position मा float गर्छ -->
<div class="satisfaction-widget" id="satisfactionWidget" role="complementary" aria-label="सदस्य सन्तुष्टि">

    <!-- Drag handle — माउस/touch ले माथि-तल सार्न -->
    <div class="satisfaction-drag-handle" id="satisfactionDragHandle" title="माथि-तल सार्न drag गर्नुहोस्">
        <span class="satisfaction-drag-dots">⠿</span>
    </div>

    <!-- Main toggle button — click गर्दा popup खुल्छ/बन्द हुन्छ -->
    <button class="satisfaction-toggle" id="satisfactionToggle"
            aria-expanded="false"
            aria-controls="satisfactionPopup"
            title="<?php echo isEnglish() ? 'Member Satisfaction' : 'सदस्य सन्तुष्टि'; ?>">
        <i class="lucide-icon" aria-hidden="true" data-lucide="smile"></i>
        <!-- Tooltip label — hover र active दुवैमा देखिन्छ -->
        <span class="satisfaction-label">
            <?php echo isEnglish() ? 'Feedback' : 'प्रतिक्रिया'; ?>
        </span>
    </button>

    <!-- Links popup — click गर्दा देखिन्छ (desktop र mobile दुवैमा) -->
    <div class="satisfaction-links-popup" id="satisfactionPopup" role="menu" aria-hidden="true">
        <div class="satisfaction-popup-header">
            <span><i class="fas fa-heart" aria-hidden="true"></i>
                <?php echo isEnglish() ? 'Your Feedback' : 'तपाईंको प्रतिक्रिया'; ?>
            </span>
            <!-- Close button — desktop र mobile दुवैमा -->
            <button class="satisfaction-close-btn" id="satisfactionClose"
                    aria-label="बन्द" title="बन्द">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <?php foreach ($satisfactionLinks as $link): ?>
        <!-- प्रत्येक link — नयाँ tab मा खुल्छ -->
        <a href="<?php echo htmlspecialchars($link['url']); ?>"
           class="satisfaction-link-item"
           target="_blank"
           rel="noopener noreferrer"
           role="menuitem">
            <i class="lucide-icon" aria-hidden="true" data-lucide="link"></i>
            <span><?php echo htmlspecialchars(
                isEnglish()
                    ? ($link['title_en'] ?? $link['title'])
                    : ($link['title'] ?? $link['title_en'])
            ); ?></span>
            <i class="fas fa-external-link-alt ms-auto small" aria-hidden="true"></i>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<style>
/* ===================================================
   Member Satisfaction Floating Widget Styles
   Desktop: दाया side, draggable — initially centered
   Mobile:  दाया side — popup button भन्दा माथि खुल्छ
   =================================================== */

/* Main wrapper — fixed position, दाया side */
.satisfaction-widget {
    --sw-accent-1: var(--secondary-color, #ec4899);
    --sw-accent-2: color-mix(in srgb, var(--sw-accent-1) 78%, #5b0b34);
    --sw-accent-soft: color-mix(in srgb, var(--sw-accent-1) 20%, #ffffff);
    --sw-accent-border: color-mix(in srgb, var(--sw-accent-1) 34%, #ffffff);
    --sw-accent-dark: color-mix(in srgb, var(--sw-accent-1) 68%, #111827);
}

/* Main wrapper — fixed position, दाया side */
.satisfaction-widget {
    position: fixed;
    right: 0;
    top: 42%;                   /* JS ले drag position override गर्छ */
    transform: translateY(-50%);
    z-index: 10001;             /* WhatsApp (9998) र अन्य सबै floating buttons भन्दा माथि */
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
    /* Drag active हुँदा smooth छैन — transition JS ले control गर्छ */
}

/* Drag handle — widget को top मा grip dots */
.satisfaction-drag-handle {
    width: 34px;
    height: 18px;
    background: var(--sw-accent-soft);
    border-radius: 10px 0 0 0;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: ns-resize;          /* माथि-तल drag cursor */
    user-select: none;
    -webkit-user-select: none;
    color: var(--sw-accent-dark);
    font-size: 0.9rem;
    line-height: 1;
    transition: background 0.2s;
    border-left: 1px solid var(--sw-accent-border);
    border-top: 1px solid var(--sw-accent-border);
}

.satisfaction-drag-handle:hover {
    background: rgba(233, 30, 99, 0.3);
}

/* Toggle button */
.satisfaction-toggle {
    display: flex;
    align-items: center;
    gap: 0;
    background: linear-gradient(135deg, var(--sw-accent-1), var(--sw-accent-2));
    color: #fff;
    border: none;
    border-radius: 12px 0 0 12px; /* left side rounded */
    padding: 11px 14px 11px 11px;
    cursor: pointer;
    box-shadow: -6px 8px 22px color-mix(in srgb, var(--sw-accent-1) 36%, transparent);
    transition: max-width 0.3s ease, padding 0.3s ease, background 0.2s ease;
    overflow: hidden;
    max-width: 50px;
    position: relative;
}

/* Hover र active दुवैमा label देखिन्छ */
.satisfaction-toggle:hover,
.satisfaction-toggle.active {
    max-width: 170px;
    padding: 11px 16px 11px 11px;
    background: linear-gradient(135deg, var(--sw-accent-2), var(--sw-accent-dark));
}

.satisfaction-toggle i.fa-smile {
    font-size: 1.2rem;
    flex-shrink: 0;
}

/* Label text — normally hidden, hover/active मा देखिन्छ */
.satisfaction-label {
    white-space: nowrap;
    overflow: hidden;
    max-width: 0;
    opacity: 0;
    transition: max-width 0.3s ease, opacity 0.3s ease;
    margin-left: 8px;
    font-size: 0.85rem;
    font-weight: 600;
}

.satisfaction-toggle:hover .satisfaction-label,
.satisfaction-toggle.active .satisfaction-label {
    max-width: 120px;
    opacity: 1;
}

/* ── Links popup ──
   Desktop: toggle button को बाँया side मा absolute position
   Popup सधैं viewport भित्र रहन्छ — JS ले adjust गर्छ
*/
.satisfaction-links-popup {
    position: fixed;            /* absolute होइन — viewport clamp को लागि fixed */
    right: 52px;                /* toggle button को width ~48px + gap */
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.22);
    min-width: 230px;
    max-width: 280px;
    max-height: min(62vh, 420px);
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.22s ease, visibility 0.22s ease, transform 0.15s ease;
    transform: translateX(8px);
    z-index: 10002;             /* Widget भन्दा एक माथि — सधैं सबैभन्दा अगाडि */
}

/* Popup visible state — JS ले active class थप्छ */
.satisfaction-links-popup.active {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transform: translateX(0);
}

/* Popup header */
.satisfaction-popup-header {
    background: linear-gradient(135deg, var(--sw-accent-1), var(--sw-accent-2));
    color: #fff;
    padding: 10px 14px;
    font-size: 0.85rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}

/* Close button — popup header भित्र */
.satisfaction-close-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: #fff;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.75rem;
    flex-shrink: 0;
    transition: background 0.2s;
}

.satisfaction-close-btn:hover {
    background: rgba(255,255,255,0.38);
}

/* Individual link items */
.satisfaction-link-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    color: #333;
    text-decoration: none;
    font-size: 0.875rem;
    border-bottom: 1px solid #f0f0f0;
    transition: background 0.18s ease;
}
.satisfaction-links-popup .satisfaction-link-item {
    overflow-wrap: anywhere;
}
.satisfaction-links-popup .satisfaction-link-item .small {
    margin-left: auto;
}
.satisfaction-links-popup.active {
    overflow-y: auto;
}

.satisfaction-link-item:last-child {
    border-bottom: none;
}

.satisfaction-link-item:hover {
    background: color-mix(in srgb, var(--sw-accent-1) 12%, #ffffff);
    color: var(--sw-accent-dark);
    text-decoration: none;
}

.satisfaction-link-item i:first-child {
    color: var(--sw-accent-1);
    font-size: 0.9rem;
    width: 18px;
    flex-shrink: 0;
}

/* ── Mobile Styles ── */
@media (max-width: 767px) {
    /* Mobile: drag handle थोरै सानो */
    .satisfaction-drag-handle {
        width: 28px;
        height: 16px;
        font-size: 0.8rem;
    }

    .satisfaction-toggle {
        padding: 10px;
        max-width: 40px;
    }

    /* Mobile popup — थोरै साँघुरो */
    .satisfaction-links-popup {
        right: 44px;
        min-width: 200px;
        max-width: 240px;
        max-height: min(56vh, 330px);
    }

    /* Mobile मा link items थोरै ठूलो — touch target */
    .satisfaction-link-item {
        padding: 13px 14px;
        font-size: 0.9rem;
    }
}

/* Very small screens (320px) */
@media (max-width: 380px) {
    .satisfaction-links-popup {
        min-width: 180px;
        max-width: 210px;
        right: 42px;
    }
}
</style>

<script>
/* =====================================================
   Satisfaction Widget — Click Toggle + Drag Support

   Desktop/Mobile:
     - Toggle button click ले popup खोल्छ/बन्द गर्छ
     - Drag handle drag गरेर widget माथि-तल सार्न सकिन्छ
     - Popup सधैं viewport भित्र (क्लिप) रहन्छ
     - Position sessionStorage मा save हुन्छ
   ===================================================== */
(function() {
    'use strict';

    var widget     = document.getElementById('satisfactionWidget');
    var toggle     = document.getElementById('satisfactionToggle');
    var popup      = document.getElementById('satisfactionPopup');
    var closeBtn   = document.getElementById('satisfactionClose');
    var dragHandle = document.getElementById('satisfactionDragHandle');

    if (!toggle || !popup || !widget) return;

    /* ── Stored position (session across page navigation) ── */
    var STORAGE_KEY = 'sw_top_pct_v2';  /* viewport percentage */
    var SAFE_BOTTOM_GAP = 132;         /* WhatsApp/other widgets भन्दा माथि */

    /* ─────────────────────────────────
       POPUP OPEN/CLOSE
    ───────────────────────────────── */
    function positionPopup() {
        /* Popup लाई toggle button को साथमा रहने गरी top align गर्नुहोस् */
        var rect = toggle.getBoundingClientRect();
        var popH = popup.offsetHeight || 250;  /* अनुमानित height */
        var vh   = window.innerHeight;

        /* Toggle button को center मा popup center गर्ने कोशिश */
        var idealTop = rect.top + (rect.height / 2) - (popH / 2);

        /* Viewport clamp — माथि र तल बाट 8px gap */
        var maxTop = Math.max(8, vh - popH - SAFE_BOTTOM_GAP);
        var clampedTop = Math.max(8, Math.min(idealTop, maxTop));

        popup.style.top = clampedTop + 'px';
    }

    function openWidget() {
        popup.classList.add('active');
        toggle.classList.add('active');
        toggle.setAttribute('aria-expanded', 'true');
        popup.setAttribute('aria-hidden', 'false');
        positionPopup();   /* खोल्दा position adjust */
    }

    function closeWidget() {
        popup.classList.remove('active');
        toggle.classList.remove('active');
        toggle.setAttribute('aria-expanded', 'false');
        popup.setAttribute('aria-hidden', 'true');
    }

    /* Toggle button click */
    toggle.addEventListener('click', function(e) {
        e.stopPropagation();
        if (popup.classList.contains('active')) {
            closeWidget();
        } else {
            openWidget();
        }
    });

    /* Close button */
    if (closeBtn) {
        closeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            closeWidget();
        });
    }

    /* Widget बाहिर click गर्दा बन्द */
    document.addEventListener('click', function(e) {
        if (!widget.contains(e.target) && !popup.contains(e.target)) {
            closeWidget();
        }
    });

    /* Escape key */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeWidget();
    });

    /* Window resize हुँदा popup position re-adjust */
    window.addEventListener('resize', function() {
        if (popup.classList.contains('active')) positionPopup();
    });

    /* ─────────────────────────────────
       DRAG — माथि-तल सार्न
       Mouse: mousedown → mousemove → mouseup
       Touch: touchstart → touchmove → touchend
    ───────────────────────────────── */
    var dragging   = false;
    var dragStartY = 0;     /* pointer Y जहाँ drag सुरु भयो */
    var widgetStartTop = 0; /* widget top px जहाँ drag सुरु भयो */

    function getWidgetTopPx() {
        /* widget को current top — px value लिन्छ */
        return widget.getBoundingClientRect().top;
    }

    function applyTop(topPx) {
        /* Viewport boundary clamp */
        var wh  = widget.offsetHeight || 70;
        var vh  = window.innerHeight;
        var min = 8;
        var max = vh - wh - SAFE_BOTTOM_GAP;
        var clamped = Math.max(min, Math.min(topPx, max));

        /* fixed top set गर्नुहोस् — transform हटाउनुहोस् */
        widget.style.transform = 'none';
        widget.style.top = clamped + 'px';

        /* Popup खुलेको छ भने re-position गर्नुहोस् */
        if (popup.classList.contains('active')) positionPopup();

        /* percentage save — page height independent */
        try {
            sessionStorage.setItem(STORAGE_KEY, (clamped / vh * 100).toFixed(2));
        } catch(e) {}
    }

    function onDragStart(clientY) {
        dragging       = true;
        dragStartY     = clientY;
        widgetStartTop = getWidgetTopPx();

        /* Drag को समयमा transition disable — laggy हुँदैन */
        widget.style.transition = 'none';
        document.body.style.userSelect = 'none';
        dragHandle.style.background = 'rgba(233,30,99,0.4)';
    }

    function onDragMove(clientY) {
        if (!dragging) return;
        var delta  = clientY - dragStartY;
        applyTop(widgetStartTop + delta);
    }

    function onDragEnd() {
        if (!dragging) return;
        dragging = false;
        widget.style.transition = '';
        document.body.style.userSelect = '';
        dragHandle.style.background = '';
    }

    /* Mouse events */
    dragHandle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        onDragStart(e.clientY);
    });
    document.addEventListener('mousemove', function(e) {
        onDragMove(e.clientY);
    });
    document.addEventListener('mouseup', onDragEnd);

    /* Touch events */
    dragHandle.addEventListener('touchstart', function(e) {
        e.preventDefault();
        onDragStart(e.touches[0].clientY);
    }, { passive: false });
    document.addEventListener('touchmove', function(e) {
        if (dragging) {
            e.preventDefault();
            onDragMove(e.touches[0].clientY);
        }
    }, { passive: false });
    document.addEventListener('touchend', onDragEnd);

    /* ─────────────────────────────────
       RESTORE SAVED POSITION
    ───────────────────────────────── */
    (function restorePosition() {
        try {
            var saved = sessionStorage.getItem(STORAGE_KEY);
            if (saved !== null) {
                var pct    = parseFloat(saved);
                var topPx  = (pct / 100) * window.innerHeight;
                /* Immediately set — no animation */
                widget.style.transition = 'none';
                widget.style.transform  = 'none';
                widget.style.top        = topPx + 'px';
                /* Next frame मा transition restore */
                requestAnimationFrame(function() {
                    widget.style.transition = '';
                });
            } else {
                /* पहिलो पटक: center भन्दा अलि माथि default */
                applyTop(window.innerHeight * 0.42);
            }
        } catch(e) {}
    })();

    /* ─────────────────────────────────
       TOUCH DEVICE — hover disable
    ───────────────────────────────── */
    var isTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
    if (isTouchDevice) {
        toggle.style.maxWidth = '40px';
    }

})();
</script>
