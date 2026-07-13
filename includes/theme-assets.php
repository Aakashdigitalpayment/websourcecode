<?php
/**
 * Unified theme CSS stack — Public, Admin, Member, Auth, Verify.
 * Load order: design-tokens → global-theme (DB) → panel CSS → coop → overrides v4.
 */
if (!function_exists('coopThemeCssUrl')) {

    function coopThemeIsUiTestMode(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = isset($_GET['ui_test']) && (string)$_GET['ui_test'] === '1';
        return $cache;
    }

    function coopThemeCssUrl(string $rel): string
    {
        $base = defined('SITE_URL') ? SITE_URL : '/';
        return rtrim($base, '/') . '/' . ltrim($rel, '/');
    }

    function coopThemeCssVer(string $rel): string
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : (dirname(__DIR__) . '/');
        $mtime = @filemtime($root . ltrim($rel, '/'));
        return $mtime ? (string) $mtime : '1';
    }

    function coopThemeLink(string $rel, ?string $ver = null): void
    {
        $v = $ver ?? coopThemeCssVer($rel);
        $href = coopThemeCssUrl($rel) . '?v=' . rawurlencode($v);
        if (coopThemeIsUiTestMode()) {
            // While testing on live URLs, force fresh CSS fetches per request.
            $href .= '&t=' . rawurlencode((string)time());
        }
        echo '<link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }

    /** DB brand colors — always after design-tokens.css */
    function coopThemeRequireGlobal(): void
    {
        if (!function_exists('getSetting')) {
            return;
        }
        $file = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__) . '/') . 'assets/css/global-theme.php';
        if (is_file($file)) {
            try {
                require $file;
            } catch (Throwable $e) {
                if (function_exists('error_log')) {
                    error_log('[theme-global] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                }
            }
        }
    }

    function coopThemeGoogleFonts(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
        /* Premium font stack: Plus Jakarta Sans (headings) + Inter (body) + Noto Sans Devanagari (Nepali) */
        echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&family=Inter:wght@300;400;500;600;700&family=Noto+Sans+Devanagari:wght@300;400;500;600;700&display=swap" rel="stylesheet">' . "\n";
    }

    /**
     * Load Lucide icons JS (AkashDigital-style local asset).
     * No CDN dependency — uses assets/vendor/lucide.min.js
     */
    function coopThemeLucide(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        if (function_exists('lucide_asset')) {
            $url = lucide_asset();
            echo '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
        } else {
            // Fallback: construct manually
            $base = defined('SITE_URL') ? SITE_URL : '/';
            $path = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__) . '/';
            $fullPath = $path . 'assets/vendor/lucide.min.js';
            $mtime = @filemtime($fullPath) ?: time();
            echo '<script src="' . htmlspecialchars($base . 'assets/vendor/lucide.min.js?v=' . $mtime, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
        }
    }

    /**
     * Initialize Lucide icons (call before </body> or after lucide.js loads).
     */
    function coopThemeLucideInit(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        echo '<script>
(function () {
    function normalizeLucideNames(root) {
        if (typeof lucide === "undefined" || !root) return;
        var registry = lucide.icons || {};
        var aliases = {
            "building-columns": ["landmark", "building-2"],
            "shield-halved": ["shield", "shield-check"],
            "chart-bar": ["bar-chart-3", "chart-column"],
            "user-circle": ["circle-user", "user-round"],
            "circle-question": ["help-circle", "circle-help"]
        };
        var nodes = root.querySelectorAll("[data-lucide]");
        nodes.forEach(function (el) {
            var name = (el.getAttribute("data-lucide") || "").trim();
            if (!name || registry[name]) return;
            var candidates = aliases[name] || [];
            for (var i = 0; i < candidates.length; i++) {
                var candidate = candidates[i];
                if (registry[candidate]) {
                    el.setAttribute("data-lucide", candidate);
                    break;
                }
            }
        });
    }

    function renderLucide() {
        if (typeof lucide === "undefined") return;
        normalizeLucideNames(document);
        lucide.createIcons();
    }

    document.addEventListener("DOMContentLoaded", renderLucide);
    window.addEventListener("load", renderLucide);
    if (document.readyState !== "loading") renderLucide();

    /* Re-run after any tab/accordion opens new icons */
    if (typeof MutationObserver !== "undefined") {
        var _lucideObs = new MutationObserver(function(muts) {
            var hasNewLucide = muts.some(function(m) {
                return Array.from(m.addedNodes).some(function(n) {
                    return n.nodeType === 1 && (n.hasAttribute("data-lucide") || n.querySelector("[data-lucide]"));
                });
            });
            if (hasNewLucide) renderLucide();
        });
        _lucideObs.observe(document.body || document.documentElement, { childList: true, subtree: true });
    }
})();
</script>' . "\n";
    }

    function coopThemeDetectPanel(): string
    {
        if (defined('PORTAL') && is_string(PORTAL) && PORTAL !== '') {
            return PORTAL;
        }
        if (defined('IS_ADMIN_PAGE') && IS_ADMIN_PAGE) {
            return 'admin';
        }
        $script = (string) ($_SERVER['PHP_SELF'] ?? '');
        if (str_contains($script, '/admin/')) {
            return 'admin';
        }
        if (str_contains($script, '/member/')) {
            return 'member';
        }
        if (str_contains($script, 'verify.php')) {
            return 'verify';
        }
        return 'public';
    }

    /**
     * @param string $panel public|admin|member|auth|verify|minimal|shell|auto
     * @param array{skip_fonts?:bool, extra?:string[]} $options
     */
    function coopThemeHeadAssets(string $panel = 'auto', array $options = []): void
    {
        static $emitted = [];
        $key = $panel . '|' . implode(',', $options['extra'] ?? []);
        if (isset($emitted[$key])) {
            return;
        }
        $emitted[$key] = true;

        if ($panel === 'auto') {
            $panel = coopThemeDetectPanel();
        }

        if (empty($options['skip_fonts'])) {
            coopThemeGoogleFonts();
        }

        /* ── 1. Load the static panel CSS FIRST ── */
        $script = (string) ($_SERVER['PHP_SELF'] ?? '');
        $isAdminShell = str_contains($script, '/admin/');

        switch ($panel) {
            case 'admin':
            case 'admin-auth':
                coopThemeLink('assets/css/app-admin.css');
                break;

            case 'member':
            case 'shell':
                coopThemeLink('assets/css/' . ($isAdminShell ? 'app-admin.css' : 'app-member.css'));
                break;

            case 'auth':
            case 'verify':
                coopThemeLink('assets/css/app-member.css');
                break;

            case 'minimal':
            case 'public':
            default:
                coopThemeLink('assets/css/app-public.css');
                break;
        }

        /* ── 1.5. Load unified CSS system (global, forms, admin-ui) ── */
        coopThemeLink('assets/css/global.css');
        coopThemeLink('assets/css/forms-tables.css');
        coopThemeLink('assets/css/admin-ui-unified.css');

        /* ── 1.6. (admin-serious-form.css removed — replaced by admin-shell-polish.css at end) ── */

        /* ── 1.7. Load Admin Auth Login Page UI/UX Fixes (Form labels, inputs, buttons, alerts) ── */
        if (in_array($panel, ['admin-auth'], true)) {
            coopThemeLink('assets/css/admin-auth-login-fixes.css');
        }

        /* ── 2. Load UI/UX enhancements (color fixes, contrast, accessibility) ── */
        coopThemeLink('assets/css/ui-ux-enhancements.css');

        /* ── 2.5. Load Admin Layout & Icon Color Fixes (tab display, icon colors) ── */
        if (in_array($panel, ['admin', 'admin-auth', 'shell'], true)) {
            coopThemeLink('assets/css/admin-layout-icon-fixes.css');
        }

        /* ── 3. Load Lucide icons (AkashDigital-style, local vendor) ── */
        if (empty($options['skip_lucide'])) {
            coopThemeLucide();
        }

        /* ── 4. Extra CSS files ── */
        foreach ($options['extra'] ?? [] as $rel) {
            coopThemeLink($rel);
        }

        /* ── 4.5. BOOTSTRAP ADMIN OVERRIDES (LAST) - Override ALL Bootstrap defaults ── */
        if (in_array($panel, ['admin', 'admin-auth', 'shell'], true)) {
            coopThemeLink('assets/css/bootstrap-admin-overrides.css');
        }

        /* ── 4.6. PRIORITY ICON COLOR FIX - Load AFTER all other CSS for maximum priority ── */
        if (in_array($panel, ['admin', 'admin-auth', 'shell'], true)) {
            coopThemeLink('assets/css/admin-icon-colors-priority.css');
        }

        /* ── 5. DB-computed brand colors AFTER static CSS so !important wins ── */
        coopThemeRequireGlobal();

        /* ── 6. PREMIUM UI — loaded absolutely last for maximum override priority ── */
        coopThemeLink('assets/css/premium-ui.css');

        /* ── 7. Universal UI/UX polish utilities — opt-in classes, all panels ── */
        coopThemeLink('assets/css/ui-ux-polish.css');

        /* ── 8. Mobile premium polish — additive only, loaded after everything ── */
        coopThemeLink('assets/css/mobile-premium-polish.css');

        /* ── 9. Admin shell polish — forms/tables/bottom-nav/icons/fonts (LAST) ── */
        if (in_array($panel, ['admin', 'admin-auth'], true)
            || ($panel === 'shell' && $isAdminShell)) {
            coopThemeLink('assets/css/admin-shell-polish.css');
        }

        /* ── 10. Member / auth / verify shell polish (LAST for those panels) ── */
        if (in_array($panel, ['member', 'auth', 'verify'], true)
            || ($panel === 'shell' && !$isAdminShell && str_contains($script, '/member/'))) {
            coopThemeLink('assets/css/member-shell-polish.css');
        }

        /* ── 11. Public shell polish — cards/forms/bottom-nav/icons/fonts (LAST) ── */
        if (in_array($panel, ['public', 'minimal'], true)
            || ($panel === 'shell' && !$isAdminShell && !str_contains($script, '/member/'))) {
            coopThemeLink('assets/css/public-shell-polish.css');
        }
    }

    /** @deprecated Use coopThemeHeadAssets('auth') — kept for existing login/password pages */
    function memberHeadAssets(): void
    {
        coopThemeHeadAssets('auth', ['skip_fonts' => false]);
    }
}
