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

    /** Non-blocking stylesheet — first paint छिटो; polish sheets का लागि */
    function coopThemeLinkDeferred(string $rel, ?string $ver = null): void
    {
        $v = $ver ?? coopThemeCssVer($rel);
        $href = coopThemeCssUrl($rel) . '?v=' . rawurlencode($v);
        if (coopThemeIsUiTestMode()) {
            $href .= '&t=' . rawurlencode((string)time());
        }
        $safe = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
        echo '<link rel="stylesheet" href="' . $safe . '" media="print" onload="this.media=\'all\'">' . "\n";
        echo '<noscript><link rel="stylesheet" href="' . $safe . '"></noscript>' . "\n";
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
        /* Premium font stack — fewer weights; non-blocking for faster first paint */
        $fontsCss = 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap';
        echo '<link rel="preload" href="' . htmlspecialchars($fontsCss, ENT_QUOTES, 'UTF-8') . '" as="style">' . "\n";
        echo '<link href="' . htmlspecialchars($fontsCss, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet" media="print" onload="this.media=\'all\'">' . "\n";
        echo '<noscript><link href="' . htmlspecialchars($fontsCss, ENT_QUOTES, 'UTF-8') . '" rel="stylesheet"></noscript>' . "\n";
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
            echo '<script src="' . htmlspecialchars($base . 'assets/vendor/lucide.min.js?v=' . $mtime, ENT_QUOTES, 'UTF-8') . '" defer></script>' . "\n";
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
    function toPascalCase(name) {
        return name.split("-").map(function (part) {
            return part ? part.charAt(0).toUpperCase() + part.slice(1) : "";
        }).join("");
    }
    function normalizeLucideNames(root) {
        if (typeof lucide === "undefined" || !root) return;
        var registry = lucide.icons || {};
        function lucideIconExists(name) {
            if (!name) return false;
            if (registry[name]) return true;
            return !!registry[toPascalCase(name)];
        }
        var aliases = {
            "building-columns": ["landmark", "building-2"],
            "shield-halved": ["shield-check", "shield-half", "shield"],
            "shield-half": ["shield-check", "shield"],
            "chart-bar": ["bar-chart-3", "chart-column"],
            "chart-line": ["line-chart", "chart-no-axes-column"],
            "user-circle": ["circle-user", "user-round"],
            "circle-question": ["help-circle", "circle-help"],
            "circle-info": ["info", "info-circle"],
            "circle-check": ["check-circle", "circle-check-big"],
            "check-circle": ["circle-check", "circle-check-big"],
            "hand-holding-heart": ["heart-handshake", "heart"],
            "shield-alt": ["shield-check", "shield", "shield-half"],
            "user-shield": ["shield-user", "shield-check", "shield"]
        };
        var nodes = root.querySelectorAll("[data-lucide]");
        nodes.forEach(function (el) {
            var name = (el.getAttribute("data-lucide") || "").trim();
            if (!name || lucideIconExists(name)) return;
            var candidates = aliases[name] || [];
            for (var i = 0; i < candidates.length; i++) {
                var candidate = candidates[i];
                if (lucideIconExists(candidate)) {
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

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", renderLucide);
    } else {
        renderLucide();
    }
    window.addEventListener("load", renderLucide);
    /* NOTE: MutationObserver intentionally removed — createIcons() replaces
       <i data-lucide> with <svg> which re-triggers the observer → infinite
       loop → Page Unresponsive crash. Tabs/modals call lucide.createIcons()
       directly in their own JS where needed. */
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

        /* Load order:
         * panel base → global/forms → enhancements → mid admin patches →
         * panel LATE BUNDLE LAST (concat of previous polish/patch sheets).
         * Source sheets remain in assets/css; regenerate via:
         *   python3 scripts/build-css-late-bundles.py
         */
        if (empty($options['skip_fonts'])) {
            coopThemeGoogleFonts();
        }

        /* ── 0.5 Self-hosted Font Awesome (legacy fas/far icons; CDN webfonts often tofu on live) ── */
        if (empty($options['skip_fa'])) {
            coopThemeLink('assets/vendor/fontawesome/css/all.min.css');
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

        /* ── 1.5. Load unified CSS system (global, forms; admin-ui admin-only) ── */
        coopThemeLink('assets/css/global.css');
        /* forms-tables must stay blocking — public KYC/appointment FOUC avoid */
        coopThemeLink('assets/css/forms-tables.css');
        if (in_array($panel, ['admin', 'admin-auth', 'shell'], true)) {
            coopThemeLink('assets/css/admin-ui-unified.css');
        }

        /* ── 1.7. Load Admin Auth Login Page UI/UX Fixes (Form labels, inputs, buttons, alerts) ── */
        if (in_array($panel, ['admin-auth'], true)) {
            coopThemeLink('assets/css/admin-auth-login-fixes.css');
        }

        /* ── 2. Load UI/UX enhancements (color fixes, contrast, accessibility) ── */
        coopThemeLinkDeferred('assets/css/ui-ux-enhancements.css');

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

        /* ── 4.5. BOOTSTRAP ADMIN OVERRIDES - Override ALL Bootstrap defaults ── */
        if (in_array($panel, ['admin', 'admin-auth', 'shell'], true)) {
            coopThemeLink('assets/css/bootstrap-admin-overrides.css');
        }

        /* ── 4.6. PRIORITY ICON COLOR FIX - AFTER other mid CSS ── */
        if (in_array($panel, ['admin', 'admin-auth', 'shell'], true)) {
            coopThemeLink('assets/css/admin-icon-colors-priority.css');
        }

        /* ── 5. DB-computed brand colors AFTER static CSS so !important wins ── */
        coopThemeRequireGlobal();

        /* ── 6. Panel late polish BUNDLE (absolute last) — order preserved inside file ── */
        $isMemberShell = in_array($panel, ['member', 'auth', 'verify'], true)
            || ($panel === 'shell' && !$isAdminShell && str_contains($script, '/member/'));
        $isAdminPanel = in_array($panel, ['admin', 'admin-auth'], true)
            || ($panel === 'shell' && $isAdminShell);
        if ($panel === 'minimal') {
            coopThemeLink('assets/css/minimal-late-bundle.css');
        } elseif ($isAdminPanel) {
            coopThemeLink('assets/css/admin-late-bundle.css');
        } elseif ($isMemberShell) {
            coopThemeLink('assets/css/member-late-bundle.css');
        } else {
            /* public + default shell */
            coopThemeLink('assets/css/public-late-bundle.css');
        }
    }

    /** @deprecated Use coopThemeHeadAssets('auth') — kept for existing login/password pages */
    function memberHeadAssets(): void
    {
        coopThemeHeadAssets('auth', ['skip_fonts' => false]);
    }
}
