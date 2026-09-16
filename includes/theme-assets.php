<?php
/**
 * Unified theme CSS stack — Public, Admin, Member, Auth, Verify.
 * Load order: panel base (app-*) → global.css / forms-tables.css →
 * enhancements + admin mid patches → global-theme.php (DB) → late bundle last.
 * Tokens live in global.css (and app-core.css on public/member). There is no
 * separate design-tokens.css.
 *
 * Font SSOT:
 *   - Google load: coopThemeGoogleFontsHtml() (Plus Jakarta + Inter + Noto Sans Devanagari)
 *   - Tokens: --font-primary / --font-heading in global.css
 *   - Shell aliases: --shell-font-* → --pub-font-* / --prem-font-* (premium-ui)
 * Do not add a second Google Fonts <link> stack on pages.
 *
 * Lucide size utilities: assets/css/lucide-icon-utils.css (via late-bundles).
 * FROZEN: Do not rewrite app-public / app-admin / app-member / app-core for polish.
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

    /** Return a stylesheet tag as a string (for $extraHead). */
    function coopThemeLinkHtml(string $rel, ?string $ver = null): string
    {
        ob_start();
        coopThemeLink($rel, $ver);
        return (string) ob_get_clean();
    }

    /** Sanitized brand hex for theme-color meta (admin/public/member). */
    function coopThemeColorHex(): string
    {
        $fallback = '#1a5f2a';
        if (!function_exists('getSetting')) {
            return $fallback;
        }
        $v = trim((string) getSetting('primary_color', $fallback));
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v)) {
            return strtolower($v);
        }
        return $fallback;
    }

    function coopThemeColorMeta(): void
    {
        echo '<meta name="theme-color" content="' . htmlspecialchars(coopThemeColorHex(), ENT_QUOTES, 'UTF-8') . '">' . "\n";
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

    /** DB brand colors — after static CSS so computed vars win */
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

    function coopThemeGoogleFontsHtml(): string
    {
        $fontsCss = 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap';
        $h = htmlspecialchars($fontsCss, ENT_QUOTES, 'UTF-8');
        return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
            . '<link rel="preload" href="' . $h . '" as="style">' . "\n"
            . '<link href="' . $h . '" rel="stylesheet" media="print" onload="this.media=\'all\'">' . "\n"
            . '<noscript><link href="' . $h . '" rel="stylesheet"></noscript>' . "\n";
    }

    function coopThemeGoogleFonts(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        /* Premium font stack — fewer weights; non-blocking for faster first paint */
        echo coopThemeGoogleFontsHtml();
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
            "building-user": ["building-2", "landmark"],
            "shield-halved": ["shield-check", "shield-half", "shield"],
            "shield-half": ["shield-check", "shield"],
            "shield-alt": ["shield-check", "shield", "shield-half"],
            "shield-exclamation": ["shield-alert", "shield"],
            "user-shield": ["shield-user", "shield-check", "shield"],
            "chart-bar": ["bar-chart-3", "chart-column"],
            "chart-line": ["trending-up", "line-chart", "chart-no-axes-column"],
            "chart-simple": ["chart-column", "bar-chart-3"],
            "user-circle": ["circle-user", "user-round"],
            "user-friends": ["users", "users-round"],
            "users-cog": ["users-round", "user-cog", "users"],
            "user-gear": ["user-cog", "user-round-cog"],
            "user-edit": ["user-pen", "square-pen"],
            "user-slash": ["user-x", "user-round-x"],
            "users-slash": ["users", "user-x"],
            "user-round-clock": ["user-round", "clock"],
            "user-round-tie": ["user-round", "briefcase"],
            "circle-question": ["circle-help", "help-circle"],
            "help-circle": ["circle-help", "info"],
            "circle-help": ["help-circle", "info"],
            "circle-info": ["info"],
            "circle-check": ["check-circle", "circle-check-big"],
            "check-circle": ["circle-check", "circle-check-big"],
            "check-double": ["check-check", "check"],
            "circle-xmark": ["circle-x", "x-circle"],
            "circle-exclamation": ["circle-alert", "triangle-alert"],
            "circle-half-stroke": ["contrast", "circle"],
            "hand-holding-heart": ["heart-handshake", "heart"],
            "hands-helping": ["handshake", "heart-handshake"],
            "sync-alt": ["refresh-cw", "refresh-ccw"],
            "arrows-rotate": ["refresh-cw", "rotate-cw"],
            "arrow-right-arrow-left": ["arrow-right-left", "arrow-left-right"],
            "rotate-left": ["rotate-ccw", "undo-2"],
            "rotate-right": ["rotate-cw", "redo-2"],
            "search-plus": ["zoom-in", "search"],
            "search-location": ["map-pin", "search"],
            "cloud-upload-alt": ["cloud-upload", "upload"],
            "sign-out-alt": ["log-out", "door-open"],
            "alert-triangle": ["triangle-alert", "octagon-alert"],
            "exclamation-triangle": ["triangle-alert", "octagon-alert"],
            "trash-alt": ["trash-2", "trash"],
            "trash-can": ["trash-2", "trash"],
            "file-alt": ["file-text", "file"],
            "file-upload": ["file-up", "upload"],
            "file-invoice": ["file-text", "file"],
            "file-invoice-dollar": ["badge-dollar-sign", "file-text"],
            "file-circle-exclamation": ["file-warning", "file-x"],
            "file-circle-xmark": ["file-x", "file-warning"],
            "folder-xmark": ["folder-x", "folder"],
            "filter-circle-xmark": ["filter-x", "filter"],
            "home": ["house"],
            "cog": ["settings", "sliders-horizontal"],
            "sitemap": ["network", "git-fork"],
            "layer-group": ["layers"],
            "bell-concierge": ["bell", "concierge-bell"],
            "calendar-xmark": ["calendar-x", "calendar-off"],
            "calendar-times": ["calendar-x", "calendar-off"],
            "calendar-star": ["calendar", "star"],
            "hourglass-end": ["hourglass", "timer"],
            "hourglass-start": ["hourglass", "timer"],
            "angle-double-right": ["chevrons-right", "chevron-right"],
            "angle-double-left": ["chevrons-left", "chevron-left"],
            "angle-right": ["chevron-right"],
            "angle-left": ["chevron-left"],
            "seedling": ["sprout", "leaf"],
            "laptop-code": ["laptop", "code"],
            "laptop-house": ["house", "laptop"],
            "money-bill-transfer": ["banknote", "arrow-right-left"],
            "hand-pointer": ["hand", "pointer"],
            "address-book": ["contact", "book-user"],
            "clone": ["copy", "files"],
            "font": ["type", "case-sensitive"],
            "formula": ["sigma", "calculator"],
            "wifi-slash": ["wifi-off", "wifi"],
            "wand-magic-sparkles": ["sparkles", "wand-sparkles"],
            "comment-exclamation": ["message-circle-warning", "message-square-warning"],
            "comment-slash": ["message-square-off", "message-circle-off"],
            "notes-medical": ["clipboard-plus", "notebook-pen"],
            "party-horn": ["party-popper", "sparkles"],
            "pen-nib": ["pen", "pen-line"],
            "pen-to-square": ["square-pen", "pen"],
            "percentage": ["percent"],
            "puzzle-piece": ["puzzle"],
            "ruler-combined": ["ruler", "pencil-ruler"],
            "star-of-life": ["star", "cross"],
            "swatchbook": ["swatch-book", "palette"],
            "table-cells-large": ["table", "grid-2x2"],
            "venus-mars": ["venus-and-mars", "users"],
            "weight-hanging": ["weight", "dumbbell"],
            "location-crosshairs": ["crosshair", "map-pin"],
            "external-link-alt": ["external-link", "square-arrow-out-up-right"],
            /* Brands — Lucide has none; map to neutral glyphs (fab stays for real brand marks) */
            "whatsapp": ["message-circle", "phone"],
            "twitter": ["share-2", "at-sign"],
            "facebook": ["share-2", "thumbs-up"],
            "facebook-f": ["share-2", "thumbs-up"],
            "youtube": ["play", "clapperboard"],
            "instagram": ["camera", "image"],
            "google": ["search", "globe"],
            "google-play": ["play", "smartphone"],
            "linkedin-in": ["briefcase", "share-2"]
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
         * DB brand (global-theme.php) → panel LATE BUNDLE LAST.
         * Brand colour: admin settings. Shared tokens: global.css.
         * Visual polish: polish sources, then:
         *   python3 scripts/build-css-late-bundles.py
         * FROZEN: Do not rewrite app-public / app-admin / app-member / app-core
         * for polish or layout tweaks — use *-page.css, *-shell-polish.css,
         * final-ui-polish.css, then rebuild late bundles. Split/slim of app-*
         * is a separate deferred track.
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

        /* ── 4.6. Admin layout + icon colors (mid-layer, after bootstrap overrides) ── */
        if (in_array($panel, ['admin', 'admin-auth', 'shell'], true)) {
            coopThemeLink('assets/css/admin-layout-icon-fixes.css');
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
