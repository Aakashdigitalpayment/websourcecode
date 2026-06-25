<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════════
 * UNIFIED ADMIN UI HELPER FUNCTIONS v3.0
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * Single source of truth for ALL admin UI components across the entire project.
 * REPLACES the scattered implementations in admin/includes/admin-ui.php
 *
 * USAGE (in admin pages):
 *   require_once BASEDIR . '/includes/admin-ui-unified.php';
 *   echo adminCard('Page Title', 'fa-cog', 'This is a card.');
 *
 * COMPONENTS:
 *   - adminCard()           — Reusable card wrapper
 *   - adminForm()           — Form builder with validation
 *   - adminTable()          — Data table with sorting/filtering
 *   - adminAlert()          — Dismissible alert boxes
 *   - adminBadge()          — Status/category badges
 *   - adminButton()         — Unified button styles
 *   - adminStatCard()       — Stat/metric display card
 *   - adminEmpty()          — Empty state container
 *   - adminHeader()         — Page header with subtitle + actions
 *   - adminFooter()         — Form action footer (submit/cancel)
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 */

if (!defined('IS_ADMIN_PAGE') && !function_exists('getDB')) {
    http_response_code(403);
    exit('Access denied.');
}

/* ───────────────────────────────────────────────────────────────────────────────
   UTILITY: Translation Helper
   ─────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('_t')) {
    function _t($nepali, $english = '') {
        static $isEn = null;
        if ($isEn === null) {
            $isEn = function_exists('isEnglish') ? (bool)isEnglish() : false;
        }
        return $isEn && $english !== '' ? $english : $nepali;
    }
}

/* ───────────────────────────────────────────────────────────────────────────────
   COMPONENT: Page Header
   ─────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('adminHeader')) {
    /**
     * adminHeader($title, $icon, $subtitle, $rightHtml, $color)
     *
     * Renders a page header with icon, title, subtitle, and right-aligned actions.
     *
     * @param string $title      — Page title (required)
     * @param string $icon       — FontAwesome icon (e.g., 'fa-cog')
     * @param string $subtitle   — Optional subtitle/description
     * @param string $rightHtml  — Optional HTML for right side (buttons, etc.)
     * @param string $color      — Color theme ('primary', 'secondary', 'danger', etc.)
     * @return string            — Rendered HTML
     */
    function adminHeader($title = '', $icon = '', $subtitle = '', $rightHtml = '', $color = 'primary') {
        if (empty(trim($title))) {
            return '';
        }

        $iconHtml = !empty($icon) ? '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . ' admin-header-icon"></i>' : '';
        $subBlock = !empty($subtitle) ? '<small class="admin-header-subtitle">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</small>' : '';

        $html = '<div class="admin-header admin-header-' . htmlspecialchars($color, ENT_QUOTES) . '">';
        $html .= '<div class="admin-header-left">';
        $html .= $iconHtml;
        $html .= '<div class="admin-header-text">';
        $html .= '<h1 class="admin-header-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        $html .= $subBlock;
        $html .= '</div>';
        $html .= '</div>';

        if (!empty($rightHtml)) {
            $html .= '<div class="admin-header-right">' . $rightHtml . '</div>';
        }

        $html .= '</div>';

        return $html;
    }
}

/* ───────────────────────────────────────────────────────────────────────────────
   COMPONENT: Alert Box
   ─────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('adminAlert')) {
    /**
     * adminAlert($type, $message, $dismissible)
     *
     * Renders an alert/notification box.
     *
     * @param string $type          — 'success', 'danger', 'warning', 'info'
     * @param string $message       — Alert message
     * @param bool   $dismissible   — Show close button
     * @return string               — Rendered HTML
     */
    function adminAlert($type = 'info', $message = '', $dismissible = true) {
        if (empty(trim($message))) {
            return '';
        }

        $icons = [
            'success' => 'fa-check-circle',
            'danger'  => 'fa-exclamation-circle',
            'warning' => 'fa-exclamation-triangle',
            'info'    => 'fa-info-circle'
        ];

        $icon = $icons[$type] ?? 'fa-info-circle';
        $closeBtn = $dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . _t('बन्द', 'Close') . '"></button>' : '';

        return '<div class="alert alert-' . htmlspecialchars($type, ENT_QUOTES) . ' alert-dismissible fade show" role="alert">'
            . '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . ' me-2"></i>'
            . '<span>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</span>'
            . $closeBtn
            . '</div>';
    }
}

/* ───────────────────────────────────────────────────────────────────────────────
   COMPONENT: Card Container
   ─────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('adminCard')) {
    /**
     * adminCard($title, $icon, $body, $footer, $class)
     *
     * Renders a card wrapper (start). Close with adminCardClose().
     *
     * @param string $title  — Card title
     * @param string $icon   — Optional icon
     * @param string $body   — Optional body content (if empty, caller can add content and close with adminCardClose)
     * @param string $footer — Optional footer content
     * @param string $class  — Extra CSS classes
     * @return string        — HTML
     */
    function adminCard($title = '', $icon = '', $body = '', $footer = '', $class = '') {
        $titleBlock = '';
        if (!empty($title)) {
            $iconHtml = !empty($icon) ? '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . ' me-2"></i>' : '';
            $titleBlock = '<div class="admin-card-header">'
                . '<h5 class="admin-card-title">' . $iconHtml . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h5>'
                . '</div>';
        }

        $classAttr = !empty($class) ? " {$class}" : '';

        $html = '<div class="admin-card' . $classAttr . '">'
            . $titleBlock
            . '<div class="admin-card-body">'
            . (empty($body) ? '' : htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));

        // If body provided, close immediately. Otherwise, caller must call adminCardClose().
        if (!empty($body)) {
            $html .= '</div>';
            if (!empty($footer)) {
                $html .= '<div class="admin-card-footer">' . $footer . '</div>';
            }
            $html .= '</div>';
        } else {
            $GLOBALS['__admin_card_footer'] = $footer;
        }

        return $html;
    }

    function adminCardClose($footer = '') {
        $f = $footer ?: ($GLOBALS['__admin_card_footer'] ?? '');
        unset($GLOBALS['__admin_card_footer']);
        $html = '</div>';
        if (!empty($f)) {
            $html .= '<div class="admin-card-footer">' . $f . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}

/* ───────────────────────────────────────────────────────────────────────────────
   COMPONENT: Badge
   ─────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('adminBadge')) {
    /**
     * adminBadge($text, $type, $icon)
     *
     * Renders a status/category badge.
     *
     * @param string $text  — Badge text
     * @param string $type  — Badge type ('success', 'warning', 'danger', 'info', 'primary')
     * @param string $icon  — Optional icon
     * @return string       — HTML
     */
    function adminBadge($text = '', $type = 'primary', $icon = '') {
        $iconHtml = !empty($icon) ? '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . ' me-1"></i>' : '';
        return '<span class="badge badge-' . htmlspecialchars($type, ENT_QUOTES) . '">'
            . $iconHtml
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . '</span>';
    }
}

/* ───────────────────────────────────────────────────────────────────────────────
   COMPONENT: Button
   ─────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('adminButton')) {
    /**
     * adminButton($text, $url, $type, $icon, $class)
     *
     * Renders a button or link button.
     *
     * @param string $text   — Button text
     * @param string $url    — Link URL (if empty, renders <button>)
     * @param string $type   — Button type ('primary', 'secondary', 'danger', 'outline', 'ghost')
     * @param string $icon   — Optional icon
     * @param string $class  — Extra CSS classes
     * @return string        — HTML
     */
    function adminButton($text = '', $url = '', $type = 'primary', $icon = '', $class = '') {
        $iconHtml = !empty($icon) ? '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . ' me-2"></i>' : '';
        $btnClass = 'btn btn-' . htmlspecialchars($type, ENT_QUOTES);
        if (!empty($class)) {
            $btnClass .= ' ' . $class;
        }

        $textHtml = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        if (!empty($url)) {
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="' . $btnClass . '">'
                . $iconHtml . $textHtml . '</a>';
        } else {
            return '<button type="button" class="' . $btnClass . '">'
                . $iconHtml . $textHtml . '</button>';
        }
    }
}

/* ───────────────────────────────────────────────────────────────────────────────
   COMPONENT: Stat Card (Dashboard Metrics)
   ─────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('adminStatCard')) {
    /**
     * adminStatCard($value, $label, $icon, $color, $trend)
     *
     * Renders a stat/metric card for dashboards.
     *
     * @param string $value  — Stat value (number, currency, etc.)
     * @param string $label  — Stat label
     * @param string $icon   — Icon
     * @param string $color  — Color ('primary', 'success', 'warning', 'danger')
     * @param string $trend  — Optional trend indicator ('+5%', '-2%')
     * @return string        — HTML
     */
    function adminStatCard($value = '0', $label = '', $icon = 'fa-chart-bar', $color = 'primary', $trend = '') {
        $trendHtml = '';
        if (!empty($trend)) {
            $trendClass = strpos($trend, '-') === 0 ? 'trend-down' : 'trend-up';
            $trendHtml = '<span class="admin-stat-trend ' . $trendClass . '">' . htmlspecialchars($trend, ENT_QUOTES) . '</span>';
        }

        return '<div class="admin-stat-card admin-stat-' . htmlspecialchars($color, ENT_QUOTES) . '">'
            . '<div class="admin-stat-icon"><i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . '"></i></div>'
            . '<div class="admin-stat-body">'
            . '<div class="admin-stat-value">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div class="admin-stat-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
            . $trendHtml
            . '</div>'
            . '</div>';
    }
}

/* ───────────────────────────────────────────────────────────────────────────────
   COMPONENT: Empty State
   ─────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('adminEmpty')) {
    /**
     * adminEmpty($icon, $title, $message, $action)
     *
     * Renders an empty state container.
     *
     * @param string $icon    — Icon class
     * @param string $title   — Title
     * @param string $message — Message
     * @param string $action  — Optional action HTML (button, link)
     * @return string         — HTML
     */
    function adminEmpty($icon = 'fa-inbox', $title = '', $message = '', $action = '') {
        $titleBlock = !empty($title) ? '<h4 class="admin-empty-title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h4>' : '';
        $msgBlock = !empty($message) ? '<p class="admin-empty-message">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>' : '';
        $actionBlock = !empty($action) ? '<div class="admin-empty-action">' . $action . '</div>' : '';

        return '<div class="admin-empty-state">'
            . '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . ' admin-empty-icon"></i>'
            . $titleBlock
            . $msgBlock
            . $actionBlock
            . '</div>';
    }
}

/* ───────────────────────────────────────────────────────────────────────────────
   COMPONENT: Form Footer (Submit/Cancel Buttons)
   ─────────────────────────────────────────────────────────────────────────────── */

if (!function_exists('adminFooter')) {
    /**
     * adminFooter($submitText, $submitType, $cancelUrl, $extraBtns)
     *
     * Renders a form action footer (submit + cancel buttons).
     *
     * @param string $submitText  — Submit button text
     * @param string $submitType  — Submit button type ('primary', 'success', etc.)
     * @param string $cancelUrl   — Cancel button URL (back link)
     * @param string $extraBtns   — Extra HTML buttons
     * @return string             — HTML
     */
    function adminFooter($submitText = _t('जमा गर्नुहोस्', 'Submit'), $submitType = 'primary', $cancelUrl = 'javascript:history.back();', $extraBtns = '') {
        $html = '<div class="form-actions">';

        if (!empty($extraBtns)) {
            $html .= $extraBtns;
        }

        $html .= '<button type="submit" class="btn btn-' . htmlspecialchars($submitType, ENT_QUOTES) . '">'
            . htmlspecialchars($submitText, ENT_QUOTES, 'UTF-8')
            . '</button>';

        $html .= '<a href="' . htmlspecialchars($cancelUrl, ENT_QUOTES) . '" class="btn btn-ghost">'
            . _t('रद्द गर्नुहोस्', 'Cancel')
            . '</a>';

        $html .= '</div>';

        return $html;
    }
}

/* ═══════════════════════════════════════════════════════════════════════════════
   IMPORTS/INCLUDES
   ═══════════════════════════════════════════════════════════════════════════════ */

// Load CSS that powers these components
$cssPath = defined('BASEDIR') ? BASEDIR . '/assets/css/forms-tables.css' : '';

/**
 * END UNIFIED ADMIN UI
 * ═══════════════════════════════════════════════════════════════════════════════
 */
