<?php
/**
 * Member Satisfaction — mobile floating fallback
 * Desktop: top utility bar in includes/header.php (सम्मान आवेदन जस्तै)
 * Mobile: compact floating (quick-links hidden on small screens)
 */
require_once __DIR__ . '/satisfaction-links-tables.php';

$satisfactionLinks = [];
try {
    $satisfactionLinks = satisfactionFetchActiveLinks(null, 5);
} catch (Throwable $e) {
    $satisfactionLinks = [];
}

if (empty($satisfactionLinks)) {
    return;
}
?>

<!-- Mobile-only floating fallback (desktop uses top header) -->
<div class="satisfaction-widget" id="satisfactionWidget" role="complementary" aria-label="<?php echo isEnglish() ? 'Member Feedback' : 'सदस्य सन्तुष्टि'; ?>">
    <button class="satisfaction-toggle" id="satisfactionToggle"
            aria-expanded="false"
            aria-controls="satisfactionPopup"
            title="<?php echo isEnglish() ? 'Member Feedback' : 'सदस्य सन्तुष्टि'; ?>">
        <i class="lucide-icon" aria-hidden="true" data-lucide="smile"></i>
        <span class="satisfaction-label">
            <?php echo isEnglish() ? 'Feedback' : 'प्रतिक्रिया'; ?>
        </span>
    </button>

    <div class="satisfaction-links-popup" id="satisfactionPopup" role="menu" aria-hidden="true">
        <div class="satisfaction-popup-header">
            <span><i class="fas fa-heart" aria-hidden="true"></i>
                <?php echo isEnglish() ? 'Your Feedback' : 'तपाईंको प्रतिक्रिया'; ?>
            </span>
            <button class="satisfaction-close-btn" id="satisfactionClose"
                    aria-label="<?php echo isEnglish() ? 'Close' : 'बन्द'; ?>" title="<?php echo isEnglish() ? 'Close' : 'बन्द'; ?>">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <?php foreach ($satisfactionLinks as $link): ?>
        <a href="<?php echo htmlspecialchars((string)$link['url'], ENT_QUOTES, 'UTF-8'); ?>"
           class="satisfaction-link-item"
           target="_blank"
           rel="noopener noreferrer"
           role="menuitem">
            <i class="<?php echo htmlspecialchars(trim((string)($link['icon'] ?? '')) !== '' ? (string)$link['icon'] : 'fas fa-link', ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars(satisfactionLinkTitle($link)); ?></span>
            <i class="fas fa-external-link-alt ms-auto small" aria-hidden="true"></i>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<style>
.satisfaction-widget {
    --sw-accent-1: var(--secondary-color, #ec4899);
    --sw-accent-2: color-mix(in srgb, var(--sw-accent-1) 78%, #5b0b34);
    --sw-accent-soft: color-mix(in srgb, var(--sw-accent-1) 20%, #ffffff);
    --sw-accent-border: color-mix(in srgb, var(--sw-accent-1) 34%, #ffffff);
    --sw-accent-dark: color-mix(in srgb, var(--sw-accent-1) 68%, #111827);
    position: fixed;
    right: 0;
    top: 42%;
    transform: translateY(-50%);
    z-index: 10001;
    display: none; /* desktop: header top-bar; mobile: show below */
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
}
@media (max-width: 991.98px) {
    .satisfaction-widget { display: flex; }
}
.satisfaction-toggle {
    display: flex;
    align-items: center;
    gap: 0;
    background: linear-gradient(135deg, var(--sw-accent-1), var(--sw-accent-2));
    color: #fff;
    border: none;
    border-radius: 12px 0 0 12px;
    padding: 10px 12px 10px 10px;
    cursor: pointer;
    box-shadow: -6px 8px 22px color-mix(in srgb, var(--sw-accent-1) 36%, transparent);
    transition: max-width 0.3s ease, padding 0.3s ease, background 0.2s ease;
    overflow: hidden;
    max-width: 44px;
    position: relative;
}
.satisfaction-toggle:hover,
.satisfaction-toggle.active {
    max-width: 160px;
    padding: 10px 14px 10px 10px;
    background: linear-gradient(135deg, var(--sw-accent-2), var(--sw-accent-dark));
}
.satisfaction-label {
    white-space: nowrap;
    overflow: hidden;
    max-width: 0;
    opacity: 0;
    transition: max-width 0.3s ease, opacity 0.3s ease;
    margin-left: 8px;
    font-size: 0.82rem;
    font-weight: 600;
}
.satisfaction-toggle:hover .satisfaction-label,
.satisfaction-toggle.active .satisfaction-label {
    max-width: 110px;
    opacity: 1;
}
.satisfaction-links-popup {
    position: fixed;
    right: 48px;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.22);
    min-width: 200px;
    max-width: 250px;
    max-height: min(56vh, 360px);
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.22s ease, visibility 0.22s ease, transform 0.15s ease;
    transform: translateX(8px);
    z-index: 10002;
}
.satisfaction-links-popup.active {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transform: translateX(0);
    overflow-y: auto;
}
.satisfaction-popup-header {
    background: linear-gradient(135deg, var(--sw-accent-1), var(--sw-accent-2));
    color: #fff;
    padding: 10px 14px;
    font-size: 0.82rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}
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
}
.satisfaction-link-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 14px;
    color: #333;
    text-decoration: none;
    font-size: 0.85rem;
    border-bottom: 1px solid #f0f0f0;
    overflow-wrap: anywhere;
}
.satisfaction-link-item:last-child { border-bottom: none; }
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
.satisfaction-link-item .small { margin-left: auto; }
</style>

<script>
(function() {
    'use strict';
    var widget = document.getElementById('satisfactionWidget');
    var toggle = document.getElementById('satisfactionToggle');
    var popup = document.getElementById('satisfactionPopup');
    var closeBtn = document.getElementById('satisfactionClose');
    if (!toggle || !popup || !widget) return;
    var SAFE_BOTTOM_GAP = 132;

    function positionPopup() {
        var rect = toggle.getBoundingClientRect();
        var popH = popup.offsetHeight || 220;
        var vh = window.innerHeight;
        var idealTop = rect.top + (rect.height / 2) - (popH / 2);
        var maxTop = Math.max(8, vh - popH - SAFE_BOTTOM_GAP);
        popup.style.top = Math.max(8, Math.min(idealTop, maxTop)) + 'px';
    }
    function openWidget() {
        popup.classList.add('active');
        toggle.classList.add('active');
        toggle.setAttribute('aria-expanded', 'true');
        popup.setAttribute('aria-hidden', 'false');
        positionPopup();
    }
    function closeWidget() {
        popup.classList.remove('active');
        toggle.classList.remove('active');
        toggle.setAttribute('aria-expanded', 'false');
        popup.setAttribute('aria-hidden', 'true');
    }
    toggle.addEventListener('click', function(e) {
        e.stopPropagation();
        if (popup.classList.contains('active')) closeWidget();
        else openWidget();
    });
    if (closeBtn) closeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        closeWidget();
    });
    document.addEventListener('click', function(e) {
        if (!widget.contains(e.target) && !popup.contains(e.target)) closeWidget();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeWidget();
    });
    window.addEventListener('resize', function() {
        if (popup.classList.contains('active')) positionPopup();
    });
})();
</script>
