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
<?php if (function_exists('coopThemeLink')) { coopThemeLink('assets/css/satisfaction-widget.css'); } ?>
<div class="satisfaction-widget" id="satisfactionWidget" role="complementary" aria-label="<?php echo isEnglish() ? 'Member Feedback' : 'सदस्य सन्तुष्टि'; ?>">
    <button type="button" class="satisfaction-toggle" id="satisfactionToggle"
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
            <span><i class="lucide-icon" data-lucide="heart" aria-hidden="true"></i>
                <?php echo isEnglish() ? 'Your Feedback' : 'तपाईंको प्रतिक्रिया'; ?>
            </span>
            <button type="button" class="satisfaction-close-btn" id="satisfactionClose"
                    aria-label="<?php echo isEnglish() ? 'Close' : 'बन्द'; ?>" title="<?php echo isEnglish() ? 'Close' : 'बन्द'; ?>">
                <i class="lucide-icon" data-lucide="x" aria-hidden="true"></i>
            </button>
        </div>
        <?php foreach ($satisfactionLinks as $link): ?>
        <a href="<?php echo htmlspecialchars((string)$link['url'], ENT_QUOTES, 'UTF-8'); ?>"
           class="satisfaction-link-item"
           target="_blank"
           rel="noopener noreferrer"
           role="menuitem">
            <?php echo function_exists('coop_nav_icon_html')
                ? coop_nav_icon_html(trim((string)($link['icon'] ?? '')) !== '' ? (string)$link['icon'] : 'fas fa-link', 'fas fa-link')
                : '<i class="lucide-icon" aria-hidden="true" data-lucide="link"></i>'; ?>
            <span><?php echo htmlspecialchars(satisfactionLinkTitle($link)); ?></span>
            <i class="lucide-icon ms-auto small" data-lucide="external-link" aria-hidden="true"></i>
        </a>
        <?php endforeach; ?>
    </div>
</div>



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
