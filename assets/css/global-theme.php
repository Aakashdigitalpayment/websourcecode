<?php

/* ══════════════════════════════════════════════════════════════════
 * ✅ कतै पनि color hardcode नगर्नुस् — सधैं CSS variable प्रयोग गर्नुस्।
 * ═══════════════════════════════════════════════════════════════
 */

if (!function_exists('getSetting')) {
    return; // config.php include नभई यो file load नगर्नुस्
}

define('THEME_VERSION', '2.0');

/* ─── Hex normalizer ─── */
$__hex = function (string $raw, string $fallback = '#1a5f2a'): string {
    $v = trim($raw);
    if ($v === '') return strtolower($fallback);
    if (preg_match('/^#([0-9a-fA-F]{3})$/', $v, $m)) {
        $h = strtolower($m[1]);
        return '#' . $h[0].$h[0] . $h[1].$h[1] . $h[2].$h[2];
    }
    if (preg_match('/^#([0-9a-fA-F]{6})$/', $v)) {
        return strtolower($v);
    }
    return strtolower($fallback);
};

/* ─── Darken/Lighten helper ─── */
$__shift = function (string $hex, int $amt): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#' . $hex;
    $clamp = fn($v) => max(0, min(255, (int)$v));
    $r = $clamp(hexdec(substr($hex, 0, 2)) - $amt);
    $g = $clamp(hexdec(substr($hex, 2, 2)) - $amt);
    $b = $clamp(hexdec(substr($hex, 4, 2)) - $amt);
    return sprintf('#%02x%02x%02x', $r, $g, $b);
};

/* ─── rgba() helper ─── */
$__rgba = function (string $hex, float $alpha): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return "rgba(26,95,42,{$alpha})";
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "rgba({$r},{$g},{$b},{$alpha})";
};

/* ─── Best foreground text for a background ─── */
/* WCAG 2.1 relative luminance threshold: 0.179 (midpoint between white=1 and black=0) */
$__textOn = function (string $hex) use ($__rgba): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '#ffffff';
    $toLinear = fn($c) => ($c <= 0.03928) ? ($c / 12.92) : pow(($c + 0.055) / 1.055, 2.4);
    $r = $toLinear(hexdec(substr($hex, 0, 2)) / 255);
    $g = $toLinear(hexdec(substr($hex, 2, 2)) / 255);
    $b = $toLinear(hexdec(substr($hex, 4, 2)) / 255);
    $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    // WCAG: contrast ratio white vs bg vs black vs bg — pick higher contrast
    $contrastWhite = (1.05) / ($lum + 0.05);
    $contrastBlack = ($lum + 0.05) / (0.05);
    return ($contrastBlack > $contrastWhite) ? '#111827' : '#ffffff';
};

/* ─── Best text color for 2-stop gradients ─── */
$__textOnGradient = function (string $hexA, string $hexB): string {
    $contrastAgainst = function (string $fg, string $bgHex): float {
        $hex = ltrim($bgHex, '#');
        if (strlen($hex) !== 6) {
            return $fg === '#ffffff' ? 21.0 : 1.0;
        }
        $toLinear = fn($c) => ($c <= 0.03928) ? ($c / 12.92) : pow(($c + 0.055) / 1.055, 2.4);
        $r = $toLinear(hexdec(substr($hex, 0, 2)) / 255);
        $g = $toLinear(hexdec(substr($hex, 2, 2)) / 255);
        $b = $toLinear(hexdec(substr($hex, 4, 2)) / 255);
        $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        if ($fg === '#ffffff') {
            return 1.05 / ($lum + 0.05);
        }
        return ($lum + 0.05) / 0.05;
    };

    $whiteWorst = min($contrastAgainst('#ffffff', $hexA), $contrastAgainst('#ffffff', $hexB));
    $blackWorst = min($contrastAgainst('#111827', $hexA), $contrastAgainst('#111827', $hexB));
    return ($blackWorst > $whiteWorst) ? '#111827' : '#ffffff';
};

/* ─── RGB components ─── */
$__rgb = function (string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return '26, 95, 42';
    return hexdec(substr($hex, 0, 2)) . ', '
         . hexdec(substr($hex, 2, 2)) . ', '
         . hexdec(substr($hex, 4, 2));
};

/* ═══════════════════════════════════════════════════════════
   DB बाट colors पढ्ने
   ═══════════════════════════════════════════════════════════ */
$_p  = $__hex((string) getSetting('primary_color',   '#1a5f2a'), '#1a5f2a'); // Primary brand
$_s  = $__hex((string) getSetting('secondary_color', '#c0392b'), '#c0392b'); // Accent/secondary
$_h  = $__hex((string) getSetting('header_color',    $_s),       $_s);       // Header/topbar
$_f  = $__hex((string) getSetting('footer_color',    $_p),       $_p);       // Footer

/* Shades */
$_pDark   = $__shift($_p, 36);
$_pLight  = $__shift($_p, -28);
$_pXLight = $__shift($_p, -48);
$_sDark   = $__shift($_s, 30);
$_hDark   = $__shift($_h, 30);
$_fDark   = $__shift($_f, 24);

/* Foreground text colors */
$_onP = $__textOnGradient($_p, $_pDark);
$_onS = $__textOnGradient($_s, $_sDark);
$_onH = $__textOnGradient($_h, $_hDark);
$_onF = $__textOnGradient($_f, $_fDark);

/* RGB for rgba() usage */
$_pRgb = $__rgb($_p);
$_sRgb = $__rgb($_s);

/* Shadows */
$_shadowP  = "0 4px 20px " . $__rgba($_p, 0.20);
$_shadowS  = "0 4px 16px " . $__rgba($_s, 0.20);
$_shadowFocus = "0 0 0 3px " . $__rgba($_p, 0.18);

/* ─── Current panel detection ─── */
$_panel = 'public';
if (defined('IS_ADMIN_PAGE') && IS_ADMIN_PAGE) {
    $_panel = 'admin';
} elseif (!empty($_SERVER['PHP_SELF']) && str_contains((string)$_SERVER['PHP_SELF'], '/member/')) {
    $_panel = 'member';
} elseif (!empty($_SERVER['PHP_SELF']) && str_contains((string)$_SERVER['PHP_SELF'], '/verify')) {
    $_panel = 'verify';
}

$__p = $_p ?? '#1a5f2a';
$__pDark = $_pDark ?? '#164d22';
$__pLight = $_pLight ?? '#3b7a47';
$__pXLight = $_pXLight ?? '#5f9b67';
$__pRgb = $_pRgb ?? '26, 95, 42';
$__s = $_s ?? '#c0392b';
$__sDark = $_sDark ?? '#9f3025';
$__sRgb = $_sRgb ?? '192, 57, 43';
$__h = $_h ?? $__s;
$__hDark = $_hDark ?? $__sDark;
$__f = $_f ?? $__p;
$__fDark = $_fDark ?? '#134826';
$__onP = $_onP ?? '#ffffff';
$__onS = $_onS ?? '#ffffff';
$__onH = $_onH ?? '#ffffff';
$__onF = $_onF ?? '#ffffff';
$__shadowP = $_shadowP ?? '0 4px 20px rgba(26,95,42,0.20)';
$__shadowS = $_shadowS ?? '0 4px 16px rgba(192,57,43,0.20)';
$__shadowFocus = $_shadowFocus ?? '0 0 0 3px rgba(26,95,42,0.18)';
?>
<style id="coop-global-theme" data-panel="<?= htmlspecialchars($_panel ?? 'public', ENT_QUOTES) ?>">
/* ═══════════════════════════════════════════════════════════
   🎨 GLOBAL THEME — Admin बाट DB मा save गरिएका रङहरू
    Portal: <?= htmlspecialchars($_panel ?? 'public', ENT_QUOTES) ?> | Version: <?= htmlspecialchars(defined('THEME_VERSION') ? THEME_VERSION : '2.0', ENT_QUOTES) ?>
   ═══════════════════════════════════════════════════════════ */
:root {
    /* ── Brand Colors ── */
    --primary-color:    <?= $__p ?>;
    --primary-dark:     <?= $__pDark ?>;
    --primary-light:    <?= $__pLight ?>;
    --primary-xlight:   <?= $__pXLight ?>;
    --primary-rgb:      <?= $__pRgb ?>;

    --secondary-color:  <?= $__s ?>;
    --secondary-dark:   <?= $__sDark ?>;
    --secondary-rgb:    <?= $__sRgb ?>;

    --header-color:     <?= $__h ?>;
    --header-dark:      <?= $__hDark ?>;
    --topbar-bg:        <?= $__h ?>;

    --footer-color:     <?= $__f ?>;
    --footer-dark:      <?= $__fDark ?>;

    /* ── Text on brand backgrounds ── */
    --text-on-primary:   <?= $__onP ?>;
    --text-on-secondary: <?= $__onS ?>;
    --text-on-header:    <?= $__onH ?>;
    --text-on-footer:    <?= $__onF ?>;

    /* ── Shadows derived from brand ── */
    --shadow-primary:  <?= $__shadowP ?>;
    --shadow-secondary:<?= $__shadowS ?>;
    --shadow-focus:    <?= $__shadowFocus ?>;

    /* ── Semantic surface colors (light mode defaults) ── */
    --bg-page:         #f8faf9;
    --bg-card:         #ffffff;
    --bg-soft:         #f5faf6;
    --bg-muted:        #e8f5e9;
    --bg-hover:        rgba(<?= $__pRgb ?>, 0.04);

    /* ── Text scale ── */
    --text-primary:    #1a2e1f;
    --text-secondary:  #4a5a4f;
    --text-muted:      #6b7280;
    --text-light:      #9ca3af;

    /* ── Borders ── */
    --border-color:    #e5e7eb;
    --border-soft:     #f0f0f0;
    --border-focus:    <?= $__p ?>;

    /* ── Status Colors (fixed, not brand-dependent) ── */
    --color-success:   #16a34a;
    --color-warning:   #d97706;
    --color-danger:    #dc2626;
    --color-info:      #0891b2;

    --color-success-bg: #f0fdf4;
    --color-warning-bg: #fffbeb;
    --color-danger-bg:  #fef2f2;
    --color-info-bg:    #ecfeff;

    --color-success-border: #bbf7d0;
    --color-warning-border: #fde68a;
    --color-danger-border:  #fecaca;
    --color-info-border:    #a5f3fc;

    /* ── Typography ── */
    --font-primary:    'Mukta', 'Noto Sans Devanagari', 'Inter', 'Segoe UI', sans-serif;
    --font-nepali:     'Noto Sans Devanagari', 'Mukta', sans-serif;
    --font-english:    'Inter', 'Poppins', 'Segoe UI', sans-serif;
    --font-mono:       'JetBrains Mono', 'Fira Code', monospace;

    --font-size-xs:    0.75rem;
    --font-size-sm:    0.8125rem;
    --font-size-base:  0.9375rem;
    --font-size-md:    1rem;
    --font-size-lg:    1.125rem;
    --font-size-xl:    1.25rem;
    --font-size-2xl:   1.5rem;
    --font-size-3xl:   1.875rem;

    /* ── Spacing scale ── */
    --space-xs:  4px;
    --space-sm:  8px;
    --space-md:  16px;
    --space-lg:  24px;
    --space-xl:  40px;
    --space-2xl: 64px;

    /* ── Border radius scale ── */
    --radius-sm:  6px;
    --radius-md:  10px;
    --radius-lg:  16px;
    --radius-xl:  24px;
    --radius-full: 9999px;

    /* ── Shadow scale ── */
    --shadow-xs:  0 1px 2px rgba(0,0,0,0.06);
    --shadow-sm:  0 1px 4px rgba(0,0,0,0.08);
    --shadow-md:  0 4px 16px rgba(0,0,0,0.10);
    --shadow-lg:  0 8px 32px rgba(0,0,0,0.12);
    --shadow-xl:  0 16px 48px rgba(0,0,0,0.14);

    /* ── Layout ── */
    --container-max:   1280px;
    --container-pad:   20px;

    /* ── Animation ── */
    --transition-fast:   0.15s ease;
    --transition-base:   0.25s ease;
    --transition-slow:   0.4s ease;

    /* ── Z-index scale ── */
    --z-dropdown:  100;
    --z-sticky:    200;
    --z-overlay:   300;
    --z-modal:     400;
    --z-toast:     500;
    --z-tooltip:   600;
}

/* ─── Bootstrap overrides: सबै panel मा consistent ─── */
.btn-primary, .bg-primary                    { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; color: var(--text-on-primary) !important; }
.btn-primary:hover, .btn-primary:focus       { background-color: var(--primary-dark) !important; border-color: var(--primary-dark) !important; color: var(--text-on-primary) !important; }
.btn-outline-primary                         { color: var(--primary-color) !important; border-color: var(--primary-color) !important; }
.btn-outline-primary:hover                   { background-color: var(--primary-color) !important; color: var(--text-on-primary) !important; }
.text-primary                                { color: var(--primary-color) !important; }
.border-primary                              { border-color: var(--primary-color) !important; }

.form-control:focus, .form-select:focus      { border-color: var(--primary-color) !important; box-shadow: var(--shadow-focus) !important; }
.form-check-input:checked                    { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; }
.form-check-input:focus                      { box-shadow: var(--shadow-focus) !important; }

.nav-pills .nav-link.active,
.nav-tabs .nav-link.active                   { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; color: var(--text-on-primary) !important; }
.page-item.active .page-link                 { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; color: var(--text-on-primary) !important; }
.list-group-item.active                      { background-color: var(--primary-color) !important; border-color: var(--primary-color) !important; }
.progress-bar                                { background-color: var(--primary-color) !important; }

/* Header / Topbar */
.top-bar, .topbar, .site-topbar, .header-top, .navbar-top { background-color: var(--header-color) !important; color: var(--text-on-header) !important; }
.top-bar a, .topbar a, .site-topbar a       { color: var(--text-on-header) !important; }

/* Footer */
footer, .site-footer, .footer-main, .main-footer { background-color: var(--footer-color) !important; color: var(--text-on-footer) !important; }
footer a, .site-footer a, .main-footer a:not(.btn) {
    color: color-mix(in srgb, var(--text-on-footer) 88%, transparent);
}
footer a:hover, .site-footer a:hover, .main-footer a:not(.btn):hover {
    color: var(--text-on-footer);
}

/* Sidebar active state (admin/member) */
.sidebar-nav li.active > a,
.sidebar-nav a.active,
.mem-sidebar .active > a,
.admin-sidebar .active                      { color: var(--text-on-primary) !important; background: var(--primary-color) !important; }

/* ─── Auto text-contrast on brand-coloured backgrounds ─────────────────
   Rule: जुनसुकै element मा primary/secondary/header/footer रङ्को background
   आउँदा text colour automatically readable हुनुपर्छ।
   NOTE: global-theme.php ले --text-on-* variables WCAG luminance बाट
   compute गर्छ (light bg → dark text, dark bg → white text).
   ─────────────────────────────────────────────────────────────────────── */

/* Admin table headers using primary brand colour */
.admin-table thead th,
table.table-primary thead th,
.table > thead.bg-primary > tr > th,
.table > thead > tr.bg-primary > th        { background: var(--primary-color) !important;
                                             color: var(--text-on-primary) !important; }

/* Page banners & hero sections */
.page-banner, .page-banner-modern,
.page-banner h1, .page-banner h2, .page-banner h3,
.page-banner-modern h1, .page-banner-modern h2,
.page-banner .breadcrumb-item,
.page-banner .breadcrumb-item a,
.page-banner-modern .breadcrumb-item,
.page-banner-modern .breadcrumb-item a     { color: var(--text-on-primary) !important; }

/* Admin topbar — सेतो/हल्का bar; header strip (--header-color) होइन */
.admin-header, .admin-topbar {
    color: var(--text-primary) !important;
}
.admin-header .page-title,
.admin-header a:not(.btn):not(.admin-menu a),
.admin-topbar a:not(.btn) {
    color: var(--text-primary) !important;
}

/* Sidebar header brand area */
.sidebar .sidebar-header,
.sidebar .sidebar-brand-text               { color: var(--text-on-primary) !important; }

/* Coop-style buttons */
.btn-coop                                  { background: var(--primary-color) !important;
                                             border-color: var(--primary-color) !important;
                                             color: var(--text-on-primary) !important; }
.btn-coop:hover, .btn-coop:focus           { background: var(--primary-dark) !important;
                                             border-color: var(--primary-dark) !important;
                                             color: var(--text-on-primary) !important; }

/* Uniform form field styling */
.field-coop {
    display: block !important;
    width: 100% !important;
    padding: 0.375rem 0.75rem !important;
    font-size: 0.875rem !important;
    line-height: 1.5 !important;
    color: #212529 !important;
    background-color: #fff !important;
    background-clip: padding-box !important;
    border: 1px solid #ced4da !important;
    border-radius: 0.25rem !important;
    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out !important;
    -webkit-appearance: none !important;
    appearance: none !important;
}
.field-coop:focus {
    color: #212529 !important;
    background-color: #fff !important;
    border-color: #86b7fe !important;
    outline: 0 !important;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
}
.field-coop::placeholder {
    color: #6c757d !important;
    opacity: 1 !important;
}

/* Uniform inline form */
.stf-inline-form {
    display: inline-flex !important;
    vertical-align: middle !important;
}

/* ── LUCIDE NAV ICONS — Standardize sidebar icon colors & sizes ──── */
.nav-icon-wrap {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 32px !important;
    height: 32px !important;
    flex-shrink: 0 !important;
}
.nav-icon-wrap i,
.nav-icon-wrap svg {
    width: 18px !important;
    height: 18px !important;
    stroke-width: 1.75 !important;
}

/* Icon accent color variants */
.nav-icon-accent { color: var(--primary-color) !important; }
.nav-icon-gold { color: #f59e0b !important; }
.nav-icon-cyan { color: #06b6d4 !important; }
.nav-icon-purple { color: #8b5cf6 !important; }
.nav-icon-primary-soft { color: #60a5fa !important; }

/* Admin sidebar nav link styling */
.sidebar-link-flex {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    padding: 8px 12px !important;
    color: rgba(255,255,255,0.85) !important;
    text-decoration: none !important;
    border-radius: 6px !important;
    transition: background-color 0.15s, color 0.15s !important;
}
.sidebar-link-flex:hover {
    background-color: rgba(255,255,255,0.1) !important;
    color: #fff !important;
}
.sidebar-link-flex.active {
    background-color: rgba(255,255,255,0.15) !important;
    color: #fff !important;
    font-weight: 500 !important;
}
.sidebar-link-label { flex: 1 !important; }

/* Admin logo fallback */
.admin-logo-fallback {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 40px !important;
    height: 40px !important;
    background: rgba(255,255,255,0.2) !important;
    border-radius: 8px !important;
    color: #fff !important;
}
.admin-logo-fallback svg,
.admin-logo-fallback i {
    width: 24px !important;
    height: 24px !important;
}

/* Sidebar nav arrow */
.nav-arrow {
    transition: transform 0.2s !important;
    color: rgba(255,255,255,0.5) !important;
}
.nav-submenu.open .nav-arrow,
.nav-group-header.open .nav-arrow {
    transform: rotate(90deg) !important;
}

/* Badges & pills */
.badge.bg-primary, .badge-primary          { color: var(--text-on-primary) !important; }
.badge.bg-secondary, .badge-secondary      { color: var(--text-on-secondary) !important; }

/* stat-card — white background, so use dark text (not --text-on-primary which is for brand-bg) */
/* Only stat-card variants with explicit brand background get light text */
.stat-card { color: var(--text-primary) !important; }
.stat-card h3, .stat-card .stat-number { color: var(--text-primary) !important; }
.stat-card p, .stat-card .stat-label    { color: var(--text-muted) !important; }
/* Brand-background variant: add class .stat-card-brand for colored cards */
.stat-card.stat-card-brand,
.stat-card.stat-card-brand h3,
.stat-card.stat-card-brand p,
.stat-card.stat-card-brand .stat-number,
.stat-card.stat-card-brand .stat-label  { color: var(--text-on-primary) !important; }

/* ══════════════════════════════════════════════════════════════════
   HERO SECTION — Text always white (overlay ensures dark background)
   app-public.css को color:#fff override हुन सक्छ; यहाँ directly fix
   ══════════════════════════════════════════════════════════════════ */
.hero-title-modern,
.slider-content .hero-title-modern,
.hero-content-modern .hero-title-modern,
.hero-text-wrapper h1                  {
    color: #fff !important;
    text-shadow: 0 2px 10px rgba(0,0,0,.32) !important;
}
.hero-subtitle-modern,
.slider-content .hero-subtitle-modern,
.hero-content-modern .hero-subtitle-modern,
.hero-text-wrapper p                   {
    color: rgba(255,255,255,.93) !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.22) !important;
}
.hero-text-wrapper, .hero-content-modern {
    color: #fff !important;
}
.hero-btn-modern                       {
    background: var(--accent-color, var(--secondary-color)) !important;
    color: #fff !important;
    border-color: transparent !important;
}
.hero-btn-modern:hover                 {
    filter: brightness(1.12) !important;
    transform: translateY(-2px) !important;
}
/* Auction hero text */
.auc2-h, .auc2-sub                    {
    color: var(--text-on-primary,#fff) !important;
    text-shadow: 0 2px 8px rgba(0,0,0,.25) !important;
}

/* ══════════════════════════════════════════════════════════════════
   AUTH-PORTAL-PAGE — Login/Verify pages background (no pink tinge)
   ══════════════════════════════════════════════════════════════════ */
body.auth-portal-page                  {
    background: linear-gradient(160deg,
        color-mix(in srgb, var(--primary-color) 7%, var(--bg-page)),
        var(--bg-page) 55%,
        color-mix(in srgb, var(--primary-color) 4%, var(--bg-page))
    ) !important;
}

/* ══════════════════════════════════════════════════════════════════
   PANEL UNIFORM — Public / Member / Admin सबैमा एकैनाश CSS
   ══════════════════════════════════════════════════════════════════ */

/* ── Alert messages ── */
.alert-error, .alert-danger, .coop-alert-error  {
    background: var(--color-danger-bg,#fef2f2) !important;
    border-color: var(--color-danger-border,#fecaca) !important;
    color: var(--color-danger,#b91c1c) !important;
}
.alert-success, .coop-alert-success    {
    background: var(--color-success-bg,#f0fdf4) !important;
    border-color: var(--color-success-border,#bbf7d0) !important;
    color: var(--color-success,#15803d) !important;
}
.alert-warning, .coop-alert-warning    {
    background: var(--color-warning-bg,#fffbeb) !important;
    border-color: var(--color-warning-border,#fde68a) !important;
    color: var(--color-warning,#92400e) !important;
}
.alert-info, .coop-alert-info          {
    background: var(--color-info-bg,#eff6ff) !important;
    border-color: var(--color-info-border,#bfdbfe) !important;
    color: var(--color-info,#1e40af) !important;
}
.alert-error i, .alert-danger i        { color: var(--color-danger,#b91c1c) !important; }
.alert-success i, .coop-alert-success i{ color: var(--color-success,#15803d) !important; }
.alert-warning i                       { color: var(--color-warning,#92400e) !important; }
.alert-info i                          { color: var(--color-info,#1e40af) !important; }

/* ── Form fields ── */
.form-control, .form-select, .form-control-sm {
    border-color: var(--border-color) !important;
    background: var(--bg-card) !important;
    color: var(--text-primary) !important;
}
.form-control::placeholder             { color: var(--text-light) !important; }
.form-label, label.form-label          { color: var(--text-secondary) !important; }
.form-text                             { color: var(--text-muted) !important; }
.input-group-text                      {
    background: var(--bg-soft) !important;
    border-color: var(--border-color) !important;
    color: var(--text-secondary) !important;
}

/* ── Cards ── */
.card                                  { border-color: var(--border-color) !important; }
.card-title                            { color: var(--text-primary) !important; }
.card-text                             { color: var(--text-secondary); }
.card-footer                           {
    border-color: var(--border-soft) !important;
    background: var(--bg-soft) !important;
}
.card-header:not([class*="bg-"])       {
    background: var(--bg-soft) !important;
    border-color: var(--border-soft) !important;
    color: var(--text-primary) !important;
}

/* ── Tables ── */
.table                                 { color: var(--text-primary); }
.table th                              { color: var(--text-secondary) !important; font-weight: 600; }
.table-hover tbody tr:hover td         {
    background-color: var(--bg-hover) !important;
    color: var(--text-primary) !important;
}
.table > thead                         { background: var(--bg-soft); }
.table-bordered, .table-bordered td,
.table-bordered th                     { border-color: var(--border-soft) !important; }

/* ── Status badges ── */
.status-active, .badge-status-active   {
    background: var(--color-success-bg) !important;
    color: var(--color-success) !important;
    border: 1px solid var(--color-success-border) !important;
}
.status-pending, .badge-status-pending {
    background: var(--color-warning-bg) !important;
    color: var(--color-warning) !important;
    border: 1px solid var(--color-warning-border) !important;
}
.status-inactive, .status-rejected     {
    background: var(--color-danger-bg) !important;
    color: var(--color-danger) !important;
    border: 1px solid var(--color-danger-border) !important;
}

/* ── Text helpers ── */
.text-muted                            { color: var(--text-muted) !important; }
small, .small                          { color: var(--text-muted); }

/* ── OAuth / Social buttons ── */
.oauth-btn                             {
    border-color: var(--border-color) !important;
    background: var(--bg-soft) !important;
    color: var(--text-primary) !important;
}
.oauth-btn:hover                       {
    background: var(--bg-card) !important;
    border-color: var(--primary-color) !important;
}
.oauth-divider                         { color: var(--text-muted) !important; }
.oauth-divider::before,
.oauth-divider::after                  { background: var(--border-color) !important; }

/* ── Password rules ── */
.pw-rules                              { color: var(--text-muted); }
.pw-rules li.rule-ok                   { color: var(--color-success) !important; }
.pw-rules li.rule-muted                { color: var(--text-light) !important; }

/* ── Empty states ── */
.empty-state, .empty-msg, .no-data     { color: var(--text-muted) !important; }
.empty-state i, .empty-msg i,
.no-data i                             { color: var(--text-light) !important; }

/* ── Footer links ── */
.foot-link                             { color: var(--text-muted) !important; }
.foot-link a                           { color: var(--primary-color) !important; }
.foot-link a:hover                     { color: var(--primary-dark) !important; }

/* ── Dropdowns ── */
.dropdown-menu                         {
    border-color: var(--border-color) !important;
    background: var(--bg-card) !important;
    box-shadow: var(--shadow-md) !important;
}
.dropdown-item                         { color: var(--text-primary) !important; }
.dropdown-item:hover, .dropdown-item:focus {
    background: var(--bg-hover) !important;
    color: var(--primary-color) !important;
}
.dropdown-item.active, .dropdown-item:active {
    background: var(--primary-color) !important;
    color: var(--text-on-primary) !important;
}
.dropdown-divider                      { border-color: var(--border-soft) !important; }

/* ── Modals ── */
.modal-header                          { border-color: var(--border-soft) !important; }
.modal-footer                          {
    border-color: var(--border-soft) !important;
    background: var(--bg-soft) !important;
}
.modal-title                           { color: var(--text-primary) !important; }

/* ── Pagination ── */
.page-link                             {
    border-color: var(--border-color) !important;
    color: var(--primary-color) !important;
}
.page-link:hover                       {
    background: var(--bg-hover) !important;
    border-color: var(--primary-color) !important;
}
.page-item.disabled .page-link         {
    color: var(--text-light) !important;
    background: var(--bg-soft) !important;
}

/* ── Verify page logo ── */
.vp-page-logo img                      {
    max-height: 64px !important;
    max-width: 180px !important;
    width: auto !important;
    height: auto !important;
    object-fit: contain !important;
}
body.auth-portal-page .vp-page-logo img,
body.verify-page .vp-page-logo img     {
    max-height: 72px !important;
    max-width: 200px !important;
}

/* ══════════════════════════════════════════════════════════════════════
   AUTO-CONTRAST MASTER BLOCK
   ──────────────────────────────────────────────────────────────────────
   global-theme.php अब सबै panel CSS पछि load हुन्छ (theme-assets.php fix)
   त्यसैले यी rules ले app-public.css / app-admin.css / app-member.css
   का कुनै पनि same-specificity color declaration लाई override गर्छ।

   सिद्धान्त: colored background → सही text color variable प्रयोग गर्नु
   ══════════════════════════════════════════════════════════════════════ */

/* ── A. TOPBAR / HEADER ─────────────────────────────────────── */
.top-bar, .topbar, .site-topbar,
.header-top, .navbar-top, .quick-links-bar,
.header-utility-bar                            {
    background-color: var(--header-color) !important;
    color:            var(--text-on-header) !important;
}
.top-bar *,  .topbar *,  .site-topbar *,
.header-top *, .navbar-top *, .quick-links-bar *,
.header-utility-bar *                          {
    color: var(--text-on-header) !important;
}
/* Links inside topbar get own contrast colour */
.top-bar a, .topbar a, .site-topbar a,
.header-top a, .quick-links-bar a             {
    color: var(--text-on-header) !important;
}
.top-bar a:hover, .topbar a:hover,
.site-topbar a:hover, .quick-links-bar a:hover {
    opacity: .82 !important;
    color:   var(--text-on-header) !important;
}
/* Icons stay same colour */
.top-bar i, .topbar i, .site-topbar i         {
    color: var(--text-on-header) !important;
}

/* ── B. FOOTER ───────────────────────────────────────────────── */
footer, .site-footer, .footer-main,
.main-footer, .footer-wrap, .footer-top        {
    background-color: var(--footer-color) !important;
    color:            var(--text-on-footer) !important;
}
footer *, .site-footer *, .footer-main *,
.main-footer *, .footer-wrap *                 {
    color: var(--text-on-footer) !important;
}
footer a, .site-footer a, .footer-main a,
.main-footer a                                 {
    color: color-mix(in srgb, var(--text-on-footer) 88%, transparent) !important;
}
footer a:hover, .site-footer a:hover,
.footer-main a:hover                           {
    color: var(--text-on-footer) !important;
    opacity: .9 !important;
}
footer h1, footer h2, footer h3, footer h4,
footer h5, footer h6,
.site-footer h1, .site-footer h2, .site-footer h3,
.site-footer h4, .site-footer h5, .site-footer h6,
.footer-main h1, .footer-main h2, .footer-main h3,
.footer-main h4, .footer-main h5, .footer-main h6  {
    color: var(--text-on-footer) !important;
}

/* ── C. HERO / SLIDER SECTION ───────────────────────────────── */
.slider-section, .hero-section, .hero-wrap,
.slider-wrap, .main-slider, .hero-area          {
    color: var(--text-on-primary) !important;
}
/* ALL children of slider/hero get contrasted text */
.slider-section h1, .slider-section h2,
.slider-section h3, .slider-section p,
.slider-section span:not(.btn):not([class*="badge"]),
.hero-section h1, .hero-section h2,
.hero-section h3, .hero-section p               {
    color: var(--text-on-primary) !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.22) !important;
}
/* slider-content direct descendants */
.slider-content                                 {
    color: var(--text-on-primary) !important;
}
.slider-content h1, .slider-content h2,
.slider-content h3, .slider-content h4,
.slider-content p                               {
    color: var(--text-on-primary) !important;
    text-shadow: 0 1px 8px rgba(0,0,0,.25) !important;
}
/* Hero modern classes — highest-priority */
.hero-title-modern                              {
    color: var(--text-on-primary) !important;
    text-shadow: 0 2px 10px rgba(0,0,0,.30) !important;
}
.hero-subtitle-modern                           {
    color: color-mix(in srgb, var(--text-on-primary) 93%, transparent) !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.20) !important;
}
.hero-text-wrapper, .hero-content-modern,
.hero-text-block                                {
    color: var(--text-on-primary) !important;
}
.hero-text-wrapper *,  .hero-content-modern *,
.hero-text-block *                              {
    color: var(--text-on-primary) !important;
}
.hero-badge                                     {
    background: rgba(255,255,255,.18) !important;
    color: var(--text-on-primary) !important;
    border-color: rgba(255,255,255,.35) !important;
}
/* Slider button — accent colour so it pops against hero bg */
.slider-content .btn,
.hero-btn-modern                                {
    background-color: var(--secondary-color) !important;
    border-color:     var(--secondary-color) !important;
    color:            var(--text-on-secondary) !important;
}
.slider-content .btn:hover,
.hero-btn-modern:hover                          {
    filter: brightness(1.12) !important;
    color:  var(--text-on-secondary) !important;
}
/* Auction hero */
.auc2-hero-section                              {
    color: var(--text-on-primary) !important;
}
.auc2-h, .auc2-sub                             {
    color: var(--text-on-primary) !important;
    text-shadow: 0 2px 8px rgba(0,0,0,.25) !important;
}

/* ── D. PAGE BANNER ─────────────────────────────────────────── */
.page-banner, .page-banner-modern,
.inner-banner, .page-header-banner              {
    color: var(--text-on-primary) !important;
}
.page-banner h1, .page-banner h2,
.page-banner-modern h1, .page-banner-modern h2,
.inner-banner h1, .page-header-banner h1        {
    color: var(--text-on-primary) !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.20) !important;
}
.page-banner .breadcrumb-item,
.page-banner .breadcrumb-item a,
.page-banner .breadcrumb-item.active,
.page-banner-modern .breadcrumb-item,
.page-banner-modern .breadcrumb-item a,
.page-banner-modern .breadcrumb-item.active     {
    color: var(--text-on-primary) !important;
}
.page-banner .breadcrumb-item a:hover,
.page-banner-modern .breadcrumb-item a:hover    {
    opacity: .82 !important;
    color: var(--text-on-primary) !important;
}
.page-banner .breadcrumb-item + .breadcrumb-item::before,
.page-banner-modern .breadcrumb-item + .breadcrumb-item::before {
    color: color-mix(in srgb, var(--text-on-primary) 65%, transparent) !important;
}

/* ── E. CTA SECTION ─────────────────────────────────────────── */
.cta-section, .cta-block, .cta-area,
.call-to-action                                 {
    color: var(--text-on-primary) !important;
}
.cta-content, .cta-text                         {
    color: var(--text-on-primary) !important;
}
.cta-content h1, .cta-content h2,
.cta-content h3, .cta-content p,
.cta-content span                               {
    color: var(--text-on-primary) !important;
}

/* ── F. MOBILE APP / DOWNLOAD SECTION ──────────────────────── */
.mobile-app-section, .app-download-section      {
    color: var(--text-on-primary) !important;
}
.mobile-app-section h1, .mobile-app-section h2,
.mobile-app-section h3, .mobile-app-section h4,
.mobile-app-section p, .mobile-app-section span,
.mobile-app-section .app-tagline,
.mobile-app-section .app-description,
.mobile-app-section .app-content *              {
    color: var(--text-on-primary) !important;
}

/* ── G. STAT BOXES ON BRAND BG ──────────────────────────────── */
.stat-box, .stat-number, .stat-label-brand,
.coop-stat-box, .stat-card-primary              {
    color: var(--text-on-primary) !important;
}
.stat-box *, .coop-stat-box *                   {
    color: var(--text-on-primary) !important;
}

/* ── H. SECTION BADGE ───────────────────────────────────────── */
.section-badge                                  {
    background-color: var(--primary-color) !important;
    color:            var(--text-on-primary) !important;
}

/* ── I. RATES SECTION HEADERS ───────────────────────────────── */
.rates-header, .rates-header-modern             {
    color: var(--text-on-primary) !important;
}
.rates-header h1, .rates-header h2, .rates-header h3,
.rates-header p,
.rates-header-modern h1, .rates-header-modern h2,
.rates-header-modern h3, .rates-header-modern p {
    color: var(--text-on-primary) !important;
}

/* ── J. BOOTSTRAP BG-* UTILITIES — brand-colour overrides ──── */
.bg-primary                                     {
    background-color: var(--primary-color) !important;
    color:            var(--text-on-primary) !important;
}
.bg-primary *                                   {
    color: var(--text-on-primary) !important;
}
.bg-secondary                                   {
    background-color: var(--secondary-color) !important;
    color:            var(--text-on-secondary) !important;
}
.bg-secondary *                                 {
    color: var(--text-on-secondary) !important;
}

/* ── K. BUTTONS ─────────────────────────────────────────────── */
.btn-primary, [class*="btn-coop-primary"]       {
    background-color: var(--primary-color) !important;
    border-color:     var(--primary-dark) !important;
    color:            var(--text-on-primary) !important;
}
.btn-primary:hover, .btn-primary:focus          {
    background-color: var(--primary-dark) !important;
    color:            var(--text-on-primary) !important;
}
.btn-outline-primary                            {
    border-color: var(--primary-color) !important;
    color:        var(--primary-color) !important;
}
.btn-outline-primary:hover,
.btn-outline-primary:focus                      {
    background-color: var(--primary-color) !important;
    color:            var(--text-on-primary) !important;
}
.btn-secondary, [class*="btn-coop-secondary"]   {
    background-color: var(--secondary-color) !important;
    border-color:     var(--secondary-dark) !important;
    color:            var(--text-on-secondary) !important;
}

/* ── L. BADGES / PILLS ──────────────────────────────────────── */
.badge.bg-primary, .badge-primary               {
    background-color: var(--primary-color) !important;
    color:            var(--text-on-primary) !important;
}
.badge.bg-secondary, .badge-secondary           {
    background-color: var(--secondary-color) !important;
    color:            var(--text-on-secondary) !important;
}

/* ── M. ADMIN SIDEBAR ───────────────────────────────────────── */
.admin-sidebar, .sidebar-dark, #adminSidebar    {
    background-color: var(--primary-dark) !important;
    color:            var(--text-on-primary) !important;
}
.admin-sidebar a, .sidebar-dark a               {
    color: color-mix(in srgb, var(--text-on-primary) 85%, transparent) !important;
}
.admin-sidebar a:hover, .sidebar-dark a:hover,
.admin-sidebar .active, .sidebar-dark .active   {
    color:            var(--text-on-primary) !important;
    background-color: rgba(255,255,255,.10) !important;
}
.admin-sidebar .sidebar-brand-text              {
    color: var(--text-on-primary) !important;
}

/* ── N. MEMBER TOPBAR ───────────────────────────────────────── */
.member-topbar, .mem-header, .mem-nav-bar       {
    background-color: var(--primary-color) !important;
    color:            var(--text-on-primary) !important;
}
.member-topbar *, .mem-header *, .mem-nav-bar * {
    color: var(--text-on-primary) !important;
}

/* ── O. TABS / NAV ON PRIMARY ───────────────────────────────── */
.nav-tabs .nav-link.active,
.nav-pills .nav-link.active                     {
    background-color: var(--primary-color) !important;
    border-color:     var(--primary-color) !important;
    color:            var(--text-on-primary) !important;
}
.page-item.active .page-link                    {
    background-color: var(--primary-color) !important;
    border-color:     var(--primary-color) !important;
    color:            var(--text-on-primary) !important;
}

/* ── P. CARD HEADERS WITH BRAND COLOR ──────────────────────── */
.card-header.bg-primary                        {
    color: var(--text-on-primary) !important;
}
.card-header.bg-secondary                       {
    background-color: var(--secondary-color) !important;
    color:            var(--text-on-secondary) !important;
}

/* ── Q. AUTH / LOGIN PORTAL BACKGROUND ─────────────────────── */
body.auth-portal-page                           {
    background: linear-gradient(160deg,
        color-mix(in srgb, var(--primary-color, #1a5f2a) 9%, var(--bg-page, #f8faf9)),
        var(--bg-page, #f8faf9) 55%,
        color-mix(in srgb, var(--primary-color, #1a5f2a) 5%, var(--bg-page, #f8faf9))
    ) !important;
    color: var(--text-primary, #1a2e1f) !important;
    min-height: 100dvh;
}

/* ── S. ALERT MESSAGES ──────────────────────────────────────── */
.alert-error,  .alert-danger, .coop-alert-error  {
    background: var(--color-danger-bg,  #fef2f2) !important;
    border-color:var(--color-danger-border,#fecaca) !important;
    color:       var(--color-danger,    #b91c1c) !important;
}
.alert-success, .coop-alert-success              {
    background: var(--color-success-bg, #f0fdf4) !important;
    border-color:var(--color-success-border,#bbf7d0) !important;
    color:       var(--color-success,   #15803d) !important;
}
.alert-warning, .coop-alert-warning              {
    background: var(--color-warning-bg, #fffbeb) !important;
    border-color:var(--color-warning-border,#fde68a) !important;
    color:       var(--color-warning,   #92400e) !important;
}
.alert-info,    .coop-alert-info                 {
    background: var(--color-info-bg,    #eff6ff) !important;
    border-color:var(--color-info-border,#bfdbfe) !important;
    color:       var(--color-info,      #1e40af) !important;
}

/* ── T. FORM FIELDS ─────────────────────────────────────────── */
.form-control, .form-select, .form-control-sm   {
    border-color: var(--border-color) !important;
    background:   var(--bg-card)      !important;
    color:        var(--text-primary)  !important;
}
.form-control::placeholder                      { color: var(--text-light) !important; }
.form-label, label.form-label                   { color: var(--text-secondary) !important; }
.form-text                                      { color: var(--text-muted) !important; }
.input-group-text                               {
    background:   var(--bg-soft)     !important;
    border-color: var(--border-color)!important;
    color:        var(--text-secondary)!important;
}

/* ── U. DROPDOWNS ───────────────────────────────────────────── */
.dropdown-menu                                  {
    border-color: var(--border-color) !important;
    background:   var(--bg-card)      !important;
}
.dropdown-item                                  { color: var(--text-primary) !important; }
.dropdown-item:hover, .dropdown-item:focus      {
    background: var(--bg-hover)    !important;
    color:      var(--primary-color)!important;
}
.dropdown-item.active, .dropdown-item:active    {
    background: var(--primary-color)      !important;
    color:      var(--text-on-primary)    !important;
}

/* ── W. OAUTH / SOCIAL BUTTONS ──────────────────────────────── */
.oauth-btn                                      {
    border-color: var(--border-color) !important;
    background:   var(--bg-soft)      !important;
    color:        var(--text-primary)  !important;
}
.oauth-btn:hover                                {
    background:   var(--bg-card)       !important;
    border-color: var(--primary-color) !important;
}

/* ── X. PAGINATION ──────────────────────────────────────────── */
.page-link                                      {
    border-color: var(--border-color)  !important;
    color:        var(--primary-color) !important;
}
.page-link:hover                                {
    background:   var(--bg-hover)      !important;
    border-color: var(--primary-color) !important;
    color:        var(--primary-color) !important;
}
.page-item.disabled .page-link                  {
    color:      var(--text-light) !important;
    background: var(--bg-soft)    !important;
}

/* ── Y. DARK MODE (body.dark-mode) ──────────────────────────── */
body.dark-mode .page-banner,
body.dark-mode .slider-section,
body.dark-mode .hero-section                    {
    color: var(--text-on-primary) !important;
}


/* ══════════════════════════════════════════════════════════════════════
   HOVER & ICON CONTRAST PATCH
   ──────────────────────────────────────────────────────────────────────
   app-public.css मा केही hover states ले brand-color background लगाउँछन्
   तर text/icon color update गर्दैनन् → dark text on dark bg।
   यो block ले ती सबैलाई fix गर्छ।
   ══════════════════════════════════════════════════════════════════════ */

/* ── Z1. NAV-MENU — active/hover link ───────────────────────── */
.nav-menu > li > a:hover,
.nav-menu > li.active > a                       {
    background-color: var(--primary-color) !important;
    color:            var(--text-on-primary) !important;
}
.nav-menu > li > a:hover i,
.nav-menu > li.active > a i                     {
    color: var(--text-on-primary) !important;
}

/* ── Z2. NAV-PILLS / SHOW active (app-core L354 missing color) */
.nav-pills .nav-link.active,
.nav-pills .show > .nav-link                    {
    background-color: var(--primary-color) !important;
    color:            var(--text-on-primary) !important;
}

/* ── Z3. SCROLL BUTTON (floating scroll-to-top / scroll-down) – */
.scroll-btn.up, .scroll-btn.up:hover            {
    color: var(--text-on-primary) !important;
}
.scroll-btn.up i, .scroll-btn.up:hover i        {
    color: var(--text-on-primary) !important;
}

/* ── Z4. CAROUSEL CONTROLS on hero slider ───────────────────── */
.hero-slider .carousel-control-prev:hover,
.hero-slider .carousel-control-next:hover       {
    background: var(--secondary-color) !important;
    color:      var(--text-on-secondary) !important;
    opacity: 1 !important;
}
.hero-slider .carousel-control-prev:hover .carousel-control-prev-icon,
.hero-slider .carousel-control-next:hover .carousel-control-next-icon,
.hero-slider .carousel-control-prev:hover i,
.hero-slider .carousel-control-next:hover i     {
    filter: none !important;
    color:  var(--text-on-secondary) !important;
}

/* ── Z5. TOPBAR DARK-MODE / SEARCH BUTTON HOVER ─────────────── */
.social-links .topbar-darkmode-btn a:hover,
.social-links .topbar-search-btn a:hover        {
    background: var(--secondary-color) !important;
    color:      var(--text-on-secondary) !important;
}
.social-links .topbar-darkmode-btn a:hover i,
.social-links .topbar-search-btn a:hover i      {
    color: var(--text-on-secondary) !important;
}

/* ── Z6. TOOL-WIDGET CARD HOVER — all child text ────────────── */
.tool-widget-card:hover,
.tool-widget-card.highlight-widget:hover        {
    background: var(--primary-color) !important;
    color:      var(--text-on-primary) !important;
}
.tool-widget-card:hover h5,
.tool-widget-card.highlight-widget:hover h5,
.tool-widget-card:hover p,
.tool-widget-card.highlight-widget:hover p,
.tool-widget-card:hover span:not(.widget-icon),
.tool-widget-card.highlight-widget:hover span:not(.widget-icon),
.tool-widget-card:hover .widget-label,
.tool-widget-card:hover .widget-desc           {
    color: var(--text-on-primary) !important;
}
/* Widget icon container stays white so icon colour (primary) pops */
.tool-widget-card:hover .widget-icon,
.tool-widget-card.highlight-widget:hover .widget-icon {
    background: rgba(255,255,255,.18) !important;
}
.tool-widget-card:hover .widget-icon i,
.tool-widget-card.highlight-widget:hover .widget-icon i {
    color: var(--text-on-primary) !important;
}

/* ── Z7. NDP DATE PICKER — selected/hover day ───────────────── */
.ndp-container .ndp-day.selected,
.ndp-container .ndp-day:hover                   {
    background: var(--primary-color) !important;
    color:      var(--text-on-primary) !important;
    border-radius: 50% !important;
}

/* ── Z8. SERVICE ICON HOVERS (use var, not hardcoded #fff) ───── */
/* quick-service-card */
.quick-service-card:hover .service-icon         {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)) !important;
}
.quick-service-card:hover .service-icon i       {
    color: var(--text-on-primary) !important;
}
/* service-card / service-card-modern */
.service-card:hover .service-icon,
.service-card-modern:hover .service-icon-modern {
    background: var(--primary-color) !important;
}
.service-card:hover .service-icon i,
.service-card-modern:hover .service-icon-modern i {
    color: var(--text-on-primary) !important;
}
/* feature-box */
.feature-box:hover .feature-icon                {
    background: var(--primary-color) !important;
}
.feature-box:hover .feature-icon i              {
    color: var(--text-on-primary) !important;
}
/* file-upload icon */
.file-upload-enhanced:hover .upload-icon        {
    background: linear-gradient(135deg, var(--primary-color), var(--primary-dark)) !important;
}
.file-upload-enhanced:hover .upload-icon i,
.file-upload-enhanced:hover .upload-icon svg    {
    color: var(--text-on-primary) !important;
}

/* ── Z9. STAT CARD ICON (already #fff hardcoded — add safety) ── */
.stat-card .stat-icon, .stat-card .stat-icon i  {
    color: var(--text-on-primary) !important;
}

/* ── Z10. BTN-PRIMARY HOVER — gradient light→dark needs contrast */
.btn-primary:hover, .btn-primary:focus          {
    background: linear-gradient(135deg,
        var(--primary-dark),
        var(--primary-color)) !important;
    color: var(--text-on-primary) !important;
    border-color: var(--primary-dark) !important;
}
.btn-primary:hover i, .btn-primary:focus i      {
    color: var(--text-on-primary) !important;
}

/* ── Z11. ADMIN SIDEBAR LINK ACTIVE ─────────────────────────── */
.sidebar-nav a.active,
.admin-nav a.active,
.sidebar-menu a.active                          {
    background: var(--primary-color) !important;
    color:      var(--text-on-primary) !important;
}

/* ── Z12. RATE-VALUE / RATE-CARD SAVING/LOAN CONTEXT ────────── */
/* These are on dark-coloured backgrounds → keep white but use var */
.rates-icon.saving, .rates-icon.loan,
.rate-value.saving, .rate-value.loan            {
    color: var(--text-on-primary) !important;
}

/* ── Z13. CONTACT SECTION — text on primary-coloured bg ─────── */
.contact-info-box .contact-details,
.contact-info-box .contact-details p,
.contact-info-box .contact-details p a {
    color: var(--text-on-primary) !important;
}
.contact-info-box .contact-details p a:hover   {
    opacity: .82 !important;
    color: var(--text-on-primary) !important;
}
.contact-info-box .contact-icon,
.contact-info-box .contact-icon i              {
    color: var(--text-on-primary) !important;
}

/* ── Z14. SECTION TOOLS / CATEGORY CARDS ────────────────────── */
.tools-category-card h5,
.tools-category-card h5 i                       {
    color: var(--text-on-primary) !important;
}

/* ── Z15. FISCAL YEAR / INSTITUTIONAL STATS BADGES ──────────── */
.institutional-stats-section .fiscal-year-badge,
.home-ip-preview-head, .home-ip-rich-header     {
    color: var(--text-on-primary) !important;
}

/* ── Z16. CHATBOT WIDGET ────────────────────────────────────── */
.chatbot-toggle                                 {
    background: var(--primary-color) !important;
    color:      var(--text-on-primary) !important;
}
.chatbot-header                                 {
    background: var(--primary-color) !important;
    color:      var(--text-on-primary) !important;
}
.chatbot-header *, .chatbot-close              {
    color: var(--text-on-primary) !important;
}

/* ── Z17. --white safety fallback (should always resolve) ────── */
:root {
    --white: #ffffff;
    --black: #000000;
}


/* ══════════════════════════════════════════════════════════════════════
   AA. AUTH PORTAL — UNIFORM CARD + BODY (admin-auth, member, verify)
   ─────────────────────────────────────────────────────────────────────
   admin/index.php loads app-admin.css only (no app-member.css) so the
   auth-card styles from app-member.css are MISSING there. We reproduce
   the essential card rules here so global-theme.php covers every panel.
   ══════════════════════════════════════════════════════════════════════ */

/* Centering shell */
body.auth-portal-page                               {
    display:         flex !important;
    flex-direction:  column !important;
    align-items:     center !important;
    justify-content: center !important;
    padding:         60px 16px 24px !important;
    font-family:     var(--font-primary,'Mukta','Noto Sans Devanagari','Segoe UI',sans-serif) !important;
}
body.auth-portal-page.verify-auth-page              {
    align-items:     center !important;
    padding-top:     16px !important;
}

/* Auth card — uniform across admin / member */
body.auth-portal-page .auth-card,
body.auth-portal-page .verify-form-card            {
    width:         100% !important;
    max-width:     400px !important;
    border-radius: 16px !important;
    border:        1px solid var(--border-color, #e5e7eb) !important;
    box-shadow:
        0 2px 6px color-mix(in srgb, var(--primary-color,#1a5f2a) 6%, transparent),
        0 18px 40px color-mix(in srgb, var(--text-primary,#1a2e1f) 7%, transparent) !important;
    background:    var(--bg-card, #fff) !important;
    overflow:      hidden !important;
}
body.auth-portal-page.admin-auth-page .auth-card    {
    max-width: 420px !important;
}

/* Card header */
body.auth-portal-page .card-header,
body.auth-portal-page .verify-form-card__head       {
    padding:       1.4rem 1.5rem 1rem !important;
    text-align:    center !important;
    border-bottom: 1px solid var(--border-soft, #f0f0f0) !important;
    background:    var(--bg-card, #fff) !important;
}

/* Logo image inside card */
body.auth-portal-page .card-logo-wrap img           {
    max-height:   54px !important;
    max-width:    180px !important;
    object-fit:   contain !important;
    border-radius: 8px !important;
    display:      block !important;
    margin:       0 auto 0.5rem !important;
}

/* Portal label */
body.auth-portal-page .card-portal-label            {
    display:         inline-flex !important;
    align-items:     center !important;
    gap:             5px !important;
    background:      transparent !important;
    border:          none !important;
    color:           var(--text-muted, #6b7280) !important;
    font-size:       0.7rem !important;
    font-weight:     600 !important;
    letter-spacing:  0.04em !important;
}

/* Card body */
body.auth-portal-page .card-body,
body.auth-portal-page .verify-form-card__body       {
    padding: 1.2rem 1.5rem 1.5rem !important;
}

/* Title / subtitle */
body.auth-portal-page .card-title                   {
    font-size:    1.08rem !important;
    font-weight:  700 !important;
    text-align:   center !important;
    color:        var(--text-primary, #1a2e1f) !important;
    margin-bottom: 3px !important;
    line-height:  1.4 !important;
}
body.auth-portal-page .card-sub                     {
    font-size:    0.79rem !important;
    color:        var(--text-muted, #6b7280) !important;
    text-align:   center !important;
    margin-bottom: 1rem !important;
    line-height:  1.55 !important;
}

/* Tabs */
body.auth-portal-page .tabs                        {
    display:       flex !important;
    gap:           5px !important;
    padding:       5px !important;
    border-radius: 12px !important;
    border:        1px solid var(--border-color, #e5e7eb) !important;
    background:    var(--bg-soft, #f5faf6) !important;
    margin-bottom: 16px !important;
}
body.auth-portal-page .tab-btn                      {
    flex:          1 !important;
    min-height:    34px !important;
    padding:       0 8px !important;
    border:        none !important;
    border-radius: 9px !important;
    background:    transparent !important;
    color:         var(--text-muted, #6b7280) !important;
    font-size:     0.83rem !important;
    font-weight:   600 !important;
    cursor:        pointer !important;
    display:       inline-flex !important;
    align-items:   center !important;
    justify-content: center !important;
    gap:           6px !important;
    transition:    all 0.15s !important;
}
body.auth-portal-page .tab-btn.active               {
    background:    var(--bg-card, #fff) !important;
    color:         var(--primary-color, #1a5f2a) !important;
    box-shadow:    0 1px 4px rgba(0,0,0,.08) !important;
}

/* Fields */
body.auth-portal-page .field,
body.auth-portal-page .vp-field,
body.auth-portal-page .input-icon                   {
    width:         100% !important;
    margin-bottom: 14px !important;
}
body.auth-portal-page .field label,
body.auth-portal-page .vp-field label               {
    display:       block !important;
    font-size:     0.79rem !important;
    font-weight:   600 !important;
    color:         var(--text-secondary, #4a5a4f) !important;
    margin-bottom: 7px !important;
    letter-spacing: 0.03em !important;
}
body.auth-portal-page .field input,
body.auth-portal-page .field select,
body.auth-portal-page .field textarea,
body.auth-portal-page .vp-field input,
body.auth-portal-page .vp-field select,
body.auth-portal-page .vp-field textarea,
body.auth-portal-page .vp-field-input              {
    width:         100% !important;
    min-height:    44px !important;
    padding:       10px 14px !important;
    border:        1px solid var(--border-color, #e5e7eb) !important;
    border-radius: 10px !important;
    background:    var(--bg-soft, #f5faf6) !important;
    color:         var(--text-primary, #1a2e1f) !important;
    font-size:     0.9rem !important;
    font-family:   inherit !important;
    box-sizing:    border-box !important;
    transition:    border-color 0.15s, box-shadow 0.15s !important;
}
body.auth-portal-page .field input:focus,
body.auth-portal-page .vp-field input:focus         {
    border-color:  var(--primary-color, #1a5f2a) !important;
    background:    var(--bg-card, #fff) !important;
    box-shadow:    0 0 0 3px color-mix(in srgb,var(--primary-color,#1a5f2a) 16%,transparent) !important;
    outline:       none !important;
}

/* Input icon wrapper */
body.auth-portal-page .input-icon                   {
    position: relative !important;
    display:  block !important;
}
body.auth-portal-page .input-icon .input-icon-left  {
    position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
    color: var(--text-muted,#6b7280); font-size: .85rem; pointer-events: none;
}
body.auth-portal-page .input-icon input             {
    padding-left: 38px !important;
}

/* Submit button */
body.auth-portal-page .submit-btn,
body.auth-portal-page .vp-btn                       {
    width:         100% !important;
    min-height:    46px !important;
    padding:       11px 20px !important;
    border:        none !important;
    border-radius: 10px !important;
    background:    var(--primary-color, #1a5f2a) !important;
    color:         var(--text-on-primary, #fff) !important;
    font-size:     0.93rem !important;
    font-weight:   700 !important;
    cursor:        pointer !important;
    box-shadow:    0 2px 10px color-mix(in srgb,var(--primary-color,#1a5f2a) 24%,transparent) !important;
    transition:    background 0.18s, transform 0.12s !important;
    display:       flex !important;
    align-items:   center !important;
    justify-content: center !important;
    gap:           8px !important;
    margin-top:    4px !important;
}
body.auth-portal-page .submit-btn:hover,
body.auth-portal-page .vp-btn:hover                 {
    background: var(--primary-dark, #134d22) !important;
    transform:  translateY(-1px) !important;
    box-shadow: 0 4px 14px color-mix(in srgb,var(--primary-color,#1a5f2a) 30%,transparent) !important;
}
body.auth-portal-page .submit-btn:active,
body.auth-portal-page .vp-btn:active                {
    transform: none !important;
}

/* Foot link */
body.auth-portal-page .foot-link                    {
    text-align:   center !important;
    margin-top:   1rem !important;
    font-size:    0.79rem !important;
    color:        var(--text-muted, #6b7280) !important;
}
body.auth-portal-page .foot-link a                  {
    color:       var(--primary-color, #1a5f2a) !important;
    font-weight: 600 !important;
    text-decoration: none !important;
}
body.auth-portal-page .foot-link a:hover            {
    text-decoration: underline !important;
}

/* Alerts inside auth cards */
body.auth-portal-page .alert,
body.auth-portal-page .alert-error,
body.auth-portal-page .alert-success,
body.auth-portal-page .alert-warning,
body.auth-portal-page .alert-info                   {
    border-radius: 10px !important;
    padding:       10px 14px !important;
    margin-bottom: 13px !important;
    font-size:     0.82rem !important;
    display:       flex !important;
    align-items:   flex-start !important;
    gap:           9px !important;
    line-height:   1.5 !important;
}

/* Password toggle button */
body.auth-portal-page .pw-wrap                      {
    position: relative !important;
    width: 100% !important;
}
body.auth-portal-page .pw-wrap input                { padding-right: 44px !important; }
body.auth-portal-page .pw-toggle                    {
    position:   absolute !important;
    right:      12px !important;
    top:        50% !important;
    transform:  translateY(-50%) !important;
    background: none !important;
    border:     none !important;
    cursor:     pointer !important;
    color:      var(--text-muted,#6b7280) !important;
    padding:    4px !important;
    font-size:  0.9rem !important;
}
body.auth-portal-page .pw-toggle:hover              {
    color: var(--primary-color,#1a5f2a) !important;
}

/* Forgot-password link */
body.auth-portal-page .forgot-link,
body.auth-portal-page a.forgot-link                 {
    color:       var(--primary-color,#1a5f2a) !important;
    font-size:   0.79rem !important;
    font-weight: 600 !important;
    text-decoration: none !important;
    display:     block !important;
    text-align:  right !important;
    margin-bottom: 12px !important;
}

/* Security note (admin) */
body.auth-portal-page .security-note                {
    background:    color-mix(in srgb,var(--primary-color,#1a5f2a) 7%,var(--bg-soft,#f5faf6)) !important;
    border:        1px solid color-mix(in srgb,var(--primary-color,#1a5f2a) 16%,var(--border-color,#e5e7eb)) !important;
    border-radius: 10px !important;
    padding:       10px 14px !important;
    font-size:     0.78rem !important;
    color:         var(--text-secondary, #4a5a4f) !important;
    margin-top:    14px !important;
}
body.auth-portal-page .security-note i              {
    color: var(--primary-color,#1a5f2a) !important;
}

/* Back link + lang toggle */
body.auth-portal-page:not(.verify-auth-page) .page-back,
body.auth-portal-page:not(.verify-auth-page) .auth-lang-toggle {
    position: fixed !important;
    top: 16px !important;
    z-index: 20 !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 7px 15px !important;
    border-radius: 999px !important;
    font-size: 0.79rem !important;
    font-weight: 700 !important;
    text-decoration: none !important;
    border: 1px solid var(--border-color,#e5e7eb) !important;
    background: rgba(255,255,255,.88) !important;
    backdrop-filter: blur(8px) !important;
    box-shadow: 0 1px 4px rgba(0,0,0,.07) !important;
    color: var(--primary-color,#1a5f2a) !important;
    transition: background 0.15s !important;
}
body.auth-portal-page:not(.verify-auth-page) .page-back   { right: 20px !important; }
body.auth-portal-page:not(.verify-auth-page) .auth-lang-toggle { left: 20px !important; }
body.auth-portal-page:not(.verify-auth-page) .page-back:hover,
body.auth-portal-page:not(.verify-auth-page) .auth-lang-toggle:hover {
    background: var(--bg-card,#fff) !important;
    border-color: color-mix(in srgb,var(--primary-color,#1a5f2a) 22%,var(--border-color,#e5e7eb)) !important;
}

/* ══════════════════════════════════════════════════════════════════════
   AB. FORM FIELD UNIFORMITY — admin + member panels
   ─────────────────────────────────────────────────────────────────────
   Bootstrap and browser defaults cause wildly inconsistent field heights:
   native <input type="date"> is taller/shorter than text inputs, selects
   have different border-radius, etc. This block enforces a uniform 42px
   height, 8px radius, and consistent border across ALL admin/member forms.
   ══════════════════════════════════════════════════════════════════════ */

/* ── Uniform base for all non-auth-portal inputs ────────────────── */
.admin-shell .form-control,
.admin-shell .form-select,
.admin-shell input[type="text"],
.admin-shell input[type="email"],
.admin-shell input[type="number"],
.admin-shell input[type="tel"],
.admin-shell input[type="url"],
.admin-shell input[type="search"],
.admin-shell input[type="password"],
.admin-shell input[type="date"],
.admin-shell input[type="time"],
.admin-shell input[type="month"],
.admin-shell input[type="week"],
.admin-shell input[type="datetime-local"],
.admin-shell select,
.admin-shell textarea                               {
    min-height:    42px !important;
    border-radius: 8px !important;
    border:        1px solid var(--border, #e2e8f0) !important;
    font-size:     0.9rem !important;
    font-family:   var(--font-primary,'Mukta','Noto Sans Devanagari',sans-serif) !important;
    padding-top:   8px !important;
    padding-bottom: 8px !important;
    color:         var(--text-primary,#1a2e1f) !important;
    background-color: var(--surface,#fff) !important;
}

/* ── Date input gets the same treatment (overrides browser chrome) ─ */
.admin-shell input[type="date"],
.admin-shell input[type="time"],
.admin-shell input[type="datetime-local"],
.admin-shell input[type="month"]                    {
    -webkit-appearance: none !important;
    appearance:         none !important;
    padding:            8px 12px !important;
    line-height:        normal !important;
    width:              100% !important;
}

/* ── Member panel (same rule set with .member-body or body.member-page) */
.member-page .form-control,
.member-page .form-select,
.member-page input[type="text"],
.member-page input[type="email"],
.member-page input[type="number"],
.member-page input[type="tel"],
.member-page input[type="date"],
.member-page input[type="time"],
.member-page input[type="datetime-local"],
.member-page select                                 {
    min-height:    42px !important;
    border-radius: 8px !important;
    border:        1px solid var(--border-color,#e5e7eb) !important;
    font-size:     0.9rem !important;
    font-family:   var(--font-primary,'Mukta','Noto Sans Devanagari',sans-serif) !important;
}

/* ── Uniform focus ring (brand colour) ──────────────────────────── */
.admin-shell .form-control:focus,
.admin-shell .form-select:focus,
.admin-shell input:focus,
.admin-shell select:focus,
.admin-shell textarea:focus                         {
    border-color: var(--primary-color,#1a5f2a) !important;
    box-shadow:   0 0 0 3px color-mix(in srgb,var(--primary-color,#1a5f2a) 15%,transparent) !important;
    outline:      none !important;
}

/* ── Form labels — uniform size/weight ──────────────────────────── */
.admin-shell .form-label,
.admin-shell label                                  {
    font-size:   0.82rem !important;
    font-weight: 600 !important;
    color:       var(--text-secondary,#4a5a4f) !important;
    margin-bottom: 6px !important;
    display:     block !important;
}

/* ── Form groups — consistent spacing ───────────────────────────── */
.admin-shell .mb-3,
.admin-shell .mb-4                                  {
    margin-bottom: 1.1rem !important;
}

/* ── Inline input-group (BS date + calendar icon btn) ───────────── */
.admin-shell .input-group .form-control,
.admin-shell .input-group input                     {
    border-right: none !important;
    border-radius: 8px 0 0 8px !important;
}
.admin-shell .input-group .input-group-text,
.admin-shell .input-group .btn                      {
    border-radius: 0 8px 8px 0 !important;
    border: 1px solid var(--border,#e2e8f0) !important;
    background: var(--surface-muted,#f1f5f9) !important;
    color: var(--primary-color,#1a5f2a) !important;
    min-height: 42px !important;
    padding: 0 13px !important;
}

/* ── Textarea uniform ────────────────────────────────────────────── */
.admin-shell textarea.form-control                  {
    min-height:   80px !important;
    resize:       vertical !important;
    padding-top:  10px !important;
}

/* ── Section card headers (used in admin profile edit form) ─────── */
.admin-shell .card,
.admin-shell .form-section                          {
    border-radius: 12px !important;
    border: 1px solid var(--border,#e2e8f0) !important;
    background: var(--surface,#fff) !important;
    box-shadow: 0 1px 4px rgba(0,0,0,.05) !important;
    margin-bottom: 1.25rem !important;
}
.admin-shell .card-header,
.admin-shell .form-section-header                   {
    border-radius: 12px 12px 0 0 !important;
    padding: 0.9rem 1.25rem !important;
    font-size: 0.9rem !important;
    font-weight: 700 !important;
    border-bottom: 1px solid var(--border,#e2e8f0) !important;
    background: var(--surface-muted,#f8fafc) !important;
    color: var(--text-primary,#1a2e1f) !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}
.admin-shell .card-body,
.admin-shell .form-section-body                     {
    padding: 1.1rem 1.25rem !important;
}

/* ── Page banner contrast (all public pages) ─────────────────────── */
.page-banner, .page-header-section                  {
    background-color: var(--primary-color,#1a5f2a) !important;
}
.page-banner h1, .page-banner h2,
.page-banner .page-title,
.page-banner .breadcrumb-item,
.page-banner .breadcrumb-item a,
.page-banner .breadcrumb-item.active,
.page-banner li, .page-banner span,
.page-banner p, .page-banner *                      {
    color: var(--text-on-primary,#fff) !important;
}
.page-banner .breadcrumb-item + .breadcrumb-item::before {
    color: color-mix(in srgb,var(--text-on-primary,#fff) 65%,transparent) !important;
}

/* ── Verify page vp-outer centering ─────────────────────────────── */
body.verify-auth-page .vp-outer                     {
    width: 100% !important;
    max-width: 560px !important;
    margin: 0 auto !important;
}
body.verify-auth-page .vp-card                      {
    background: var(--bg-card,#fff) !important;
    border-radius: 14px !important;
    border: 1px solid var(--border-color,#e5e7eb) !important;
    box-shadow: 0 10px 30px rgba(0,0,0,.09) !important;
    padding: 1.5rem !important;
}

/* ══════════════════════════════════════════════════════════════════════
   FINAL UNIFORMITY PATCH — loaded LAST so it beats every earlier rule
   ──────────────────────────────────────────────────────────────────────
   Fixes consolidated from full-project deep review:
   1. a.btn underlines (Devanagari + white-on-green hide)
   2. .btn overflow:hidden clipping Devanagari descenders ँ ी ु etc.
   3. Inactive tab pills inside green/primary strips (institutional profile)
   4. Button icon visibility — icons always inherit button text color
   5. Bottom mobile nav inactive icon visibility (admin/member/public)
   6. stat-uniform-card / stat-card final canonical look
   ══════════════════════════════════════════════════════════════════════ */

/* ── 1. BUTTONS: kill underline + fix descender clipping ────────── */
.btn, a.btn, button.btn,
.btn:hover, .btn:focus, .btn:active,
a.btn:hover, a.btn:focus, a.btn:active             {
    text-decoration: none !important;
}
.btn                                               {
    overflow: visible !important;          /* fix Devanagari descender clipping */
    line-height: 1.45 !important;          /* breathing room for ँ ी ु */
    text-decoration: none !important;
}
.btn:not(.btn-sm):not(.btn-xs)                     {
    min-height: 38px;
    padding-top: 8px !important;
    padding-bottom: 8px !important;
}
/* Icon inside any button inherits the button's text colour (no white-on-white) */
.btn > i, .btn > svg, .btn i.fas, .btn i.fa, .btn i.far, .btn i.fab,
a.btn i, button.btn i                              {
    color: inherit !important;
    vertical-align: middle;
    line-height: 1;
}

/* ── 2. INACTIVE TAB PILLS ON GREEN STRIP — readable white text ── */
.admin-inner-tabstrip .nav-link:not(.active),
.main-content ul.nav.admin-inner-tabstrip .nav-link:not(.active),
ul.nav-pills.admin-inner-tabstrip .nav-link:not(.active) {
    color: #ffffff !important;
    opacity: 0.96 !important;
    text-shadow: 0 1px 2px rgba(0,0,0,.25) !important;
}
.admin-inner-tabstrip .nav-link:not(.active) i,
.main-content ul.nav.admin-inner-tabstrip .nav-link:not(.active) i {
    color: #ffffff !important;
    opacity: 0.96 !important;
}
.admin-inner-tabstrip .nav-link:not(.active):hover,
.main-content ul.nav.admin-inner-tabstrip .nav-link:not(.active):hover {
    background: rgba(255,255,255,.18) !important;
    color: #ffffff !important;
    opacity: 1 !important;
}

/* ── 3. BUTTON ICON CONTRAST — primary-colored buttons keep icon visible ── */
.btn-primary i, .btn-primary svg,
.btn-success i, .btn-success svg,
.btn-danger  i, .btn-danger  svg,
.btn-secondary i, .btn-secondary svg,
.btn-info i, .btn-info svg,
.btn-warning i, .btn-warning svg                   {
    color: inherit !important;
}

/* ── 4. PAGE HEADER ACTION BUTTONS — explicit visible padding so icon bottoms never clip ── */
.admin-page-header .btn,
.content-header .btn,
.btn.adminAddBtn-like                              {
    padding-top: 9px !important;
    padding-bottom: 9px !important;
    line-height: 1.4 !important;
    text-decoration: none !important;
}

/* ── 5. ADMIN BOTTOM NAV ICONS — always visible at rest ────────── */
.admin-bottom-nav .admin-nav-item                  {
    color: #475569 !important;            /* darker default than #6b7280 */
}
.admin-bottom-nav .admin-nav-item i                {
    color: inherit !important;
    opacity: 1 !important;
}
.admin-bottom-nav .admin-nav-item.active,
.admin-bottom-nav .admin-nav-item:hover            {
    color: var(--primary-color, #1a5f2a) !important;
    background: rgba(var(--primary-rgb, 26,95,42), .10) !important;
}
.admin-bottom-nav .admin-nav-item.active i,
.admin-bottom-nav .admin-nav-item:hover i          {
    color: var(--primary-color, #1a5f2a) !important;
}

/* ── 6. NAV-TABS ICONS visibility (Bootstrap default behaviour) ── */
.nav-tabs .nav-link i, .nav-pills .nav-link i      {
    color: inherit !important;
    opacity: 1 !important;
}

/* ── 7. STAT-UNIFORM CARD — final visible icon + value ───────────── */
.stat-uniform-card .stat-uniform-icon              {
    color: var(--primary-color, #1a5f2a) !important;
    font-size: 1.4rem !important;
    margin-bottom: 4px !important;
    opacity: 1 !important;
}
.stat-uniform-card[data-bg="danger"]  .stat-uniform-icon { color: var(--color-danger,  #dc2626) !important; }
.stat-uniform-card[data-bg="warning"] .stat-uniform-icon { color: var(--color-warning, #d97706) !important; }
.stat-uniform-card[data-bg="success"] .stat-uniform-icon { color: var(--color-success, #16a34a) !important; }
.stat-uniform-card[data-bg="info"]    .stat-uniform-icon { color: var(--color-info,    #0891b2) !important; }
.stat-uniform-card[data-bg="secondary"] .stat-uniform-icon { color: var(--secondary-color, #6b7280) !important; }

/* ── 8. ICON-ONLY ACTION BUTTONS (table row edit/delete) — visible color ── */
.admin-icon-btn i, .admin-action-group .btn i      {
    color: inherit !important;
    opacity: 1 !important;
    font-size: 0.92em;
}

/* ── 9. PAGE BANNER ON SETUP — fonts uniform ──────────────────── */
body, .admin-shell, .member-page                   {
    font-family: var(--font-primary, 'Mukta', 'Noto Sans Devanagari', 'Segoe UI', sans-serif) !important;
}

/* ── 10. SIDEBAR HOVER + ACTIVE → icon contrast guaranteed ────── */
.sidebar a:hover i, .sidebar a.active i,
.admin-sidebar a:hover i, .admin-sidebar a.active i,
.sidebar-nav a:hover i, .sidebar-nav a.active i    {
    color: inherit !important;
}

/* ── 11. NAV-MENU (public) hover → icon visible ─────────────── */
.nav-menu > li > a:hover i, .nav-menu > li.active > a i {
    color: inherit !important;
    opacity: 1 !important;
}

/* ── 12. CARD-HEADER GRADIENT — text/icon always white ────────── */
.main-content .card-header.bg-gradient-primary,
.admin-card .card-header                           {
    color: var(--text-on-primary, #fff) !important;
}
.main-content .card-header.bg-gradient-primary *,
.admin-card .card-header *                         {
    color: var(--text-on-primary, #fff) !important;
}
.admin-card .card-header i, .main-content .card-header i {
    opacity: 1 !important;
}

/* ══════════════════════════════════════════════════════════════════════
   FIX-PASS 2 (2026-06-10) — targeted issues from user feedback
   ══════════════════════════════════════════════════════════════════════ */

/* ── A. PUBLIC HOMEPAGE: "अन्य डिजिटल सेवाहरू" cards — h5 contrast ── */
/* Earlier rule in app-public.css used white-on-gradient; later override used
   light-green-on-light-green which was reported as "white on light gray" in
   some browser cache states. Force a guaranteed-readable dark-green text on
   soft-green chip for h5 inside .tools-category-card. */
.tools-widget-section .tools-category-card h5,
.tools-category-card.tools-cat-forms h5,
.tools-category-card.tools-cat-tools h5,
.tools-category-card.tools-cat-member h5 {
    color: var(--primary-dark, #144a21) !important;
    background: color-mix(in srgb, var(--primary-color, #1a5f2a) 10%, #ffffff) !important;
    border: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 18%, #ffffff) !important;
    border-radius: 10px !important;
    padding: 10px 14px !important;
    text-shadow: none !important;
    animation: none !important;
}
.tools-widget-section .tools-category-card h5 i,
.tools-category-card h5 i {
    color: var(--text-on-primary, #fff) !important;
    background: var(--primary-color, #1a5f2a) !important;
    border-radius: 6px !important;
    width: 24px !important; height: 24px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 0.8rem !important;
}
/* Kill old shimmer pseudo-element that creates white wash on h5 */
.tools-widget-section .tools-category-card h5::after,
.tools-category-card h5::after {
    display: none !important;
}

/* ── B. HRM .btn-coop — Devanagari-safe padding + no text clip ── */
/* HRM pages use .btn-coop (NOT Bootstrap .btn), so our earlier .btn overflow
   fix didn't apply. The HRM action buttons ("कर्मचारी सूची", "ड्यासबोर्ड",
   "+ नयाँ कर्मचारी") had their Devanagari descenders + icons clipped at the
   bottom. Same fix as .btn now applied to .btn-coop. */
.btn-coop, a.btn-coop, button.btn-coop                {
    overflow: visible !important;
    text-decoration: none !important;
    line-height: 1.45 !important;
    min-height: 40px;
    padding-top: 9px !important;
    padding-bottom: 9px !important;
    align-items: center !important;
    white-space: nowrap;
}
a.btn-coop, a.btn-coop:hover, a.btn-coop:focus, a.btn-coop:active {
    text-decoration: none !important;
}
.btn-coop > i, .btn-coop > svg                        {
    color: inherit !important;
    font-size: 0.95em !important;
    line-height: 1 !important;
    flex-shrink: 0;
}
/* Page-header row containing .btn-coop — proper wrap on small screens */
.stf-page-head, .page-header                          {
    align-items: center !important;
    flex-wrap: wrap !important;
    row-gap: 8px !important;
}
.stf-page-head .d-flex.gap-2, .page-header .d-flex.gap-2 {
    flex-wrap: wrap !important;
    row-gap: 8px !important;
}

/* ── C. INSTITUTIONAL PROFILE create/save button — explicit visibility ── */
/* Defensive override for any leftover narrow padding causing clip */
.admin-content .admin-page-header .btn,
#profileMainForm + * .btn-primary,
button[form="profileMainForm"].btn,
.admin-content .btn.btn-primary                       {
    padding-top: 9px !important;
    padding-bottom: 9px !important;
    min-height: 40px;
    line-height: 1.45 !important;
    text-decoration: none !important;
}

/* ══════════════════════════════════════════════════════════════════════
   FIX-PASS 3 (2026-06-10) — global icon scaling + Devanagari safety
   Two passive, project-wide overrides:
   (A) Icons inside dropdowns/buttons/nav scaled to text size (0.92em, ~14px)
   (B) Buttons/badges never use fixed heights — flexible padding so Devanagari
       descenders (ँ ी ु etc.) never clip.
   ══════════════════════════════════════════════════════════════════════ */

/* ── A. ICON SIZING — proportional to text, never oversized ────── */
/* Dropdown menus (Bootstrap + custom .pfl-drop / .qh-menu) */
.dropdown-menu .dropdown-item i,
.dropdown-menu .dropdown-item svg,
.dropdown-menu a i,
.dropdown-menu a svg,
.pfl-drop a i, .pfl-drop a svg,
.qh-menu a i, .qh-menu a svg,
.quick-links-dropdown a i,
ul.dropdown-menu i, ul.dropdown-menu svg               {
    font-size: 0.92em !important;
    width: 1.15em !important;
    min-width: 1.15em !important;
    max-width: 1.4em !important;
    height: auto !important;
    line-height: 1 !important;
    text-align: center !important;
    vertical-align: middle !important;
    flex-shrink: 0 !important;
}
/* Button icons — proportional, never bigger than text */
.btn > i, .btn > svg,
.btn-coop > i, .btn-coop > svg,
button > i:only-child, a > i:only-child                {
    font-size: 0.95em !important;
    line-height: 1 !important;
    vertical-align: middle;
    flex-shrink: 0;
}
/* Badge icons — even smaller */
.badge > i, .badge > svg                               {
    font-size: 0.88em !important;
    line-height: 1 !important;
    vertical-align: middle;
}
/* Nav-link & sidebar icons — uniform 16-18px */
.navbar .nav-link > i,
.nav-menu > li > a > i,
.sidebar-nav a > i,
.admin-sidebar a > i                                   {
    font-size: 0.95em !important;
    width: 1.2em;
    text-align: center;
    flex-shrink: 0;
}
/* Dropdown items must use flex so icon + text align perfectly */
.dropdown-menu .dropdown-item,
.dropdown-menu > li > a,
.pfl-drop a, .qh-menu a                                {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    line-height: 1.45 !important;
}

/* ── B. NEPALI/DEVANAGARI DESCENDER SAFETY ─────────────────────── */
/* Convert any fixed-height button/badge to min-height with flex padding so
   ँ ी ु ृ ँ̱ etc. never clip. */
.btn, .btn-coop, .badge,
.btn-sm, .btn-lg,
.nav-link, .dropdown-item,
.pfl-drop a, .qh-menu a                                {
    height: auto !important;
    line-height: 1.45 !important;
}
/* Badge Devanagari-safe padding (was 4px → too tight for descenders) */
.badge                                                 {
    padding-top: 5px !important;
    padding-bottom: 5px !important;
    line-height: 1.4 !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    vertical-align: middle;
    min-height: 22px;
}
/* Small badge variant — keep readable */
.badge-sm, .badge.badge-sm                             {
    padding: 4px 7px !important;
    font-size: 0.68rem !important;
    line-height: 1.35 !important;
    min-height: 20px;
}
/* Pills (Bootstrap .badge.rounded-pill) keep border-radius */
.badge.rounded-pill                                    { border-radius: 999px !important; }

/* Form labels, table cells — line-height safe for Devanagari */
.form-label, .table th, .table td                      {
    line-height: 1.5 !important;
}

/* Status chip-style spans used on profile pages */
.status-chip, .chip, .pill, .tag                       {
    padding-top: 5px !important;
    padding-bottom: 5px !important;
    line-height: 1.4 !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    height: auto !important;
    min-height: 24px;
}

/* ── C. CASCADE-RESET: kill any inline height:XXpx on buttons/links
   that might have been set by older templates ──────────────────── */
.btn[style*="height"],
.btn-coop[style*="height"],
.badge[style*="height"]                                {
    /* inline style still wins but we add min-height fallback */
    min-height: 36px;
}

/* ══════════════════════════════════════════════════════════════════════
   FIX-PASS 4 (2026-06-22) — STAT CARD & STAT-MINI CONSOLIDATION
   Previous fixes applied to global-theme.php and override multiple
   conflicting definitions in app-admin.css (which has .stat-card defined
   7x and .stat-mini defined 3x causing specificity wars).
   This pass creates ONE canonical definition for each.
   ══════════════════════════════════════════════════════════════════════ */

/* ── CANONICAL .stat-card — Single source of truth ───────────────── */
/* This overrides all 7+ previous definitions in app-admin.css */
.stat-card {
    padding: 20px !important;
    border-radius: 12px !important;
    color: white !important;
    position: relative !important;
    overflow: hidden !important;
    display: flex !important;
    align-items: center !important;
    gap: 15px !important;
    min-height: 95px !important;
    transition: transform 0.18s ease, box-shadow 0.18s ease !important;
}
.stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
}
/* Color variants — all use CSS variables for consistency */
.stat-card.bg-primary  { background: linear-gradient(135deg, var(--primary-color, #1a5f2a), var(--color-success, #28a745)) !important; }
.stat-card.bg-success { background: linear-gradient(135deg, var(--primary-color, #1a5f2a), var(--color-success, #28a745)) !important; }
.stat-card.bg-info    { background: linear-gradient(135deg, #0dcaf0, #0891b2) !important; }
.stat-card.bg-warning { background: linear-gradient(135deg, #f59e0b, #d97706) !important; }
.stat-card.bg-danger  { background: linear-gradient(135deg, #dc2626, #ef4444) !important; }
.stat-card.bg-dark    { background: linear-gradient(135deg, #374151, #1f2937) !important; }
.stat-card.bg-secondary { background: linear-gradient(135deg, #6b7280, #4b5563) !important; }
/* Stat card internals - FIX: Remove overlay effect for clear icon visibility */
.stat-card .stat-icon { 
    width: 50px; 
    height: 50px; 
    background: #ffffff; 
    border-radius: 10px; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    font-size: 1.4rem; 
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1); /* Clean shadow instead of overlay */
}
.stat-card .stat-icon.warn  { background: #fef3c7; color: #b45309; box-shadow: 0 2px 8px rgba(245,158,11,0.25); }
.stat-card .stat-icon.danger{ background: #fee2e2; color: #b91c1c; box-shadow: 0 2px 8px rgba(220,38,38,0.25); }
.stat-card .stat-icon.info  { background: #e0f2fe; color: #0369a1; box-shadow: 0 2px 8px rgba(13,202,240,0.25); }
.stat-card .stat-icon i { opacity: 1 !important; color: inherit; } /* Ensure icon is fully visible */
.stat-card .stat-info h3 { font-size: 1.6rem !important; font-weight: 700 !important; margin: 0 !important; line-height: 1 !important; }
.stat-card .stat-info p  { margin: 0.2rem 0 0 !important; font-size: 0.8rem !important; opacity: 0.9 !important; }

/* ── UNIFORM EDIT BUTTONS — Consolidate all .btn-edit-* variants ──── */
/* All custom edit buttons now use same styling for consistency */
[class*="btn-edit-"] {
    background: #0d6efd !important;
    border-color: #0d6efd !important;
    color: #fff !important;
}
[class*="btn-edit-"]:hover {
    background: #0b5ed7 !important;
    border-color: #0a58ca !important;
    color: #fff !important;
}
.btn-edit-notice,
.btn-edit-aw,
.btn-edit-career,
.btn-edit-dl,
.btn-edit-faq,
.btn-edit-feat,
.btn-edit-link,
.btn-edit-member,
.btn-edit-mot,
.btn-edit-news,
.btn-edit-pf,
.btn-edit-rate,
.btn-edit-sc,
.btn-edit-sl,
.btn-edit-sp,
.btn-edit-svc,
.btn-edit-tenure,
.btn-edit-type {
    padding: 0.25rem 0.5rem !important;
    font-size: 0.75rem !important;
    border-radius: 0.25rem !important;
}

/* ── CANONICAL .stat-mini — Single source of truth ───────────────── */
.stat-mini-row { display: flex !important; gap: 12px !important; flex-wrap: wrap !important; margin-bottom: 20px !important; }
.stat-mini {
    flex: 1 !important; 
    min-width: 130px !important; 
    background: #fff !important; 
    border-radius: 10px !important;
    border: 1.5px solid #e8edf3 !important; 
    padding: 14px 16px !important; 
    text-decoration: none !important;
    transition: box-shadow 0.18s, border-color 0.18s !important; 
    display: flex !important; 
    flex-direction: column !important;
    justify-content: flex-start !important; 
    min-height: 122px !important;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04) !important;
}
.stat-mini:hover { box-shadow: 0 4px 18px rgba(26,95,42,0.12) !important; border-color: #22c55e !important; }
.stat-mini.active-filter { border-color: var(--primary-color, #1a5f2a) !important; background: #f0fdf4 !important; box-shadow: 0 2px 10px rgba(26,95,42,0.15) !important; }
.stat-mini .sm-val { font-size: 1.6rem !important; font-weight: 800 !important; color: #1a2e1d !important; line-height: 1 !important; }
.stat-mini .sm-lbl { font-size: 0.73rem !important; color: #6b7280 !important; margin-top: 2px !important; line-height: 1.35 !important; min-height: 2em !important; }
.stat-mini .sm-icon { width: 34px !important; height: 34px !important; border-radius: 8px !important; display: flex !important; align-items: center !important; justify-content: center !important; font-size: 0.9rem !important; margin-bottom: 8px !important; flex-shrink: 0 !important; }
/* Icon color variants */
.sm-icon.ic-total     { background: #fef2f2 !important; color: var(--secondary-dark, #922b21) !important; }
.sm-icon.ic-pending   { background: #fef9c3 !important; color: #b45309 !important; }
.sm-icon.ic-process   { background: #fef2f2 !important; color: var(--secondary-color, #c0392b) !important; }
.sm-icon.ic-approved  { background: #dcfce7 !important; color: #15803d !important; }
.sm-icon.ic-rejected  { background: #fee2e2 !important; color: #b91c1c !important; }
.sm-icon.ic-disbursed { background: #fef2f2 !important; color: var(--secondary-dark, #922b21) !important; }
.sm-icon.ic-amount    { background: #fef3c7 !important; color: #92400e !important; }
.sm-icon.ic-resolved  { background: #dcfce7 !important; color: #15803d !important; }
.sm-icon.ic-anon      { background: #f3f4f6 !important; color: #6b7280 !important; }

/* ── CANONICAL .stat-uniform-card — Bootstrap card-based stat ────── */
.stat-uniform-card {
    background: #fff !important;
    border: 1px solid #e5e7eb !important;
    border-radius: 10px !important;
    padding: 16px !important;
    transition: box-shadow 0.2s, transform 0.2s !important;
}
.stat-uniform-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08) !important; transform: translateY(-1px) !important; }
.stat-uniform-card .stat-uniform-icon { font-size: 1.5rem !important; margin-bottom: 8px !important; }
.stat-uniform-card .stat-uniform-value { font-size: 1.4rem !important; font-weight: 700 !important; color: var(--text-primary, #1f2937) !important; }
.stat-uniform-card .stat-uniform-label { font-size: 0.75rem !important; color: var(--text-muted, #6b7280) !important; }

/* ── ADMIN TABLE CARD — Standard wrapper for tables ───────────────── */
.admin-table-card {
    border: none !important;
    border-radius: 14px !important;
    box-shadow: 0 4px 18px rgba(16,24,40,0.07) !important;
    overflow: hidden !important;
    background: #fff !important;
}
.admin-table-card .table thead th {
    background: linear-gradient(135deg, rgba(26,95,42,0.08), rgba(40,167,69,0.12)) !important;
    color: var(--primary-color, #1a5f2a) !important;
    font-weight: 700 !important;
    font-size: 0.8rem !important;
}

/* ── COOP TABLE — Uniform table styling for all panels ────────────── */
.coop-table {
    font-family: var(--font-primary) !important;
    font-size: 0.875rem !important;
}
.coop-table thead th {
    background: linear-gradient(135deg, rgba(26,95,42,0.08), rgba(40,167,69,0.12)) !important;
    color: var(--primary-color, #1a5f2a) !important;
    font-weight: 600 !important;
    border-bottom: 2px solid var(--primary-color, #1a5f2a) !important;
}
.coop-table tbody tr:hover {
    background-color: rgba(26,95,42,0.04) !important;
}

/* Mobile table card view */
.table-responsive-stack tbody tr {
    display: flex !important;
    flex-direction: column !important;
    margin-bottom: 1rem !important;
    border: 1px solid #e5e7eb !important;
    border-radius: 8px !important;
    padding: 0.75rem !important;
}
.table-responsive-stack tbody td {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    border: none !important;
    padding: 0.25rem 0.5rem !important;
}
.table-responsive-stack tbody td::before {
    content: attr(data-label) !important;
    font-weight: 600 !important;
    color: var(--text-muted, #6b7280) !important;
    font-size: 0.75rem !important;
    min-width: 100px !important;
}

/* Ticker animation protection — never suppress */
.ticker-scroll,
.notice-ticker .ticker-scroll {
    animation: tickerScroll 30s linear infinite !important;
}

/* Lucide compatibility after FA migration */
.lucide-icon {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 1rem !important;
    height: 1rem !important;
    min-width: 1rem !important;
    min-height: 1rem !important;
    flex: 0 0 auto !important;
    vertical-align: -0.125em !important;
    line-height: 1 !important;
}
.lucide-icon.fa-lg {
    width: 1.125rem !important;
    height: 1.125rem !important;
}
.lucide-icon.fa-2x {
    width: 1.5rem !important;
    height: 1.5rem !important;
}
.lucide-icon.fa-3x {
    width: 2rem !important;
    height: 2rem !important;
}
.lucide-icon.fa-4x {
    width: 2.75rem !important;
    height: 2.75rem !important;
}

</style>
