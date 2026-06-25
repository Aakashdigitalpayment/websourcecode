<?php
/**
 * 🔗 Cross-Panel Uniformity Helpers
 * ─────────────────────────────────────────────────────────────
 * Public, Member, Admin तीनै panel मा consistent UI helpers।
 *
 * Auto-loaded via header.php / _bootstrap.php / admin-header.php।
 * सबैले एउटै flash message, breadcrumb, alert markup प्रयोग गर्छन्।
 */

if (!function_exists('coopAlert')) {
    /**
     * Universal alert box — public/member/admin तीनै ठाउँमा एउटै style।
     * Uses Lucide icons (SVG inline) — no external dependency needed.
     */
    function coopAlert(string $type, string $message, bool $dismissible = true): string {
        $map = [
            'success' => ['bg' => 'var(--color-success)',  'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>'],
            'error'   => ['bg' => 'var(--color-danger)',   'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'],
            'danger'  => ['bg' => 'var(--color-danger)',   'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'],
            'warning' => ['bg' => 'var(--color-warning)',  'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'],
            'info'    => ['bg' => 'var(--color-info)',     'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'],
        ];
        $m   = $map[$type] ?? $map['info'];
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $btn = $dismissible
            ? '<button type="button" class="coop-alert-close" onclick="this.parentElement.remove()" aria-label="Close">×</button>'
            : '';
        return <<<HTML
<div class="coop-alert" style="background:{$m['bg']};color:var(--text-on-primary,white);padding:12px 16px;border-radius:var(--radius-md,10px);display:flex;align-items:center;gap:10px;margin:12px 0;box-shadow:var(--shadow-sm,0 1px 4px rgba(var(--primary-rgb,26,95,42),.12));font-family:var(--font-primary);">
    <span class="coop-alert-icon" style="display:flex;align-items:center;justify-content:center;">{$m['icon']}</span>
    <span style="flex:1;">{$msg}</span>
    {$btn}
</div>
HTML;
    }
}

if (!function_exists('coopFlash')) {
    /**
     * Echo flash message in unified style.
     */
    function coopFlash(): void {
        if (function_exists('getFlash')) {
            $f = getFlash();
            if ($f) echo coopAlert($f['type'] ?? 'info', $f['message'] ?? '');
        }
    }
}

if (!function_exists('coopPanelType')) {
    /**
     * Detect current panel: 'admin' | 'member' | 'public'.
     */
    function coopPanelType(): string {
        $script = $_SERVER['PHP_SELF'] ?? '';
        if (defined('IS_ADMIN_PAGE') && IS_ADMIN_PAGE) return 'admin';
        if (str_contains($script, '/admin/')) return 'admin';
        if (str_contains($script, '/member/')) return 'member';
        return 'public';
    }
}

if (!function_exists('coopCurrentUser')) {
    /**
     * Universal current-user info across panels.
     * Returns: ['type' => 'admin|member|guest', 'id' => int, 'name' => string, 'role' => string]
     */
    function coopCurrentUser(): array {
        if (!empty($_SESSION['admin_id'])) {
            return [
                'type' => 'admin',
                'id'   => (int)$_SESSION['admin_id'],
                'name' => $_SESSION['admin_name'] ?? 'Admin',
                'role' => $_SESSION['admin_role'] ?? 'admin',
            ];
        }
        if (!empty($_SESSION['member_id'])) {
            return [
                'type' => 'member',
                'id'   => (int)$_SESSION['member_id'],
                'name' => $_SESSION['member_name'] ?? 'Member',
                'role' => 'member',
            ];
        }
        return ['type' => 'guest', 'id' => 0, 'name' => '', 'role' => ''];
    }
}

if (!function_exists('coopBreadcrumb')) {
    /**
     * Universal breadcrumb — public/member/admin तीनै ठाउँमा एउटै style।
     * @param array $items [['label' => 'Home', 'url' => '/'], ['label' => 'Current']]
     */
    function coopBreadcrumb(array $items): string {
        $html = '<nav class="coop-breadcrumb" style="font-family:var(--font-primary);font-size:.85rem;color:var(--text-muted);margin-bottom:14px;">';
        $last = count($items) - 1;
        foreach ($items as $i => $it) {
            $label = htmlspecialchars($it['label'] ?? '', ENT_QUOTES);
            if ($i === $last || empty($it['url'])) {
                $html .= "<span style='color:var(--text-primary);font-weight:500;'>{$label}</span>";
            } else {
                $url = htmlspecialchars($it['url'], ENT_QUOTES);
                $html .= "<a href='{$url}' style='color:var(--primary-color);text-decoration:none;'>{$label}</a>";
            }
            if ($i !== $last) $html .= ' <span style="margin:0 6px;color:var(--border-color);">›</span> ';
        }
        $html .= '</nav>';
        return $html;
    }
}

if (!function_exists('coopTableOpen')) {
    /**
     * Responsive table wrapper — mobile मा horizontal scroll,
     * table-responsive-stack class थपेमा card-row view पाइन्छ।
     *
     * Usage:
     *   echo coopTableOpen(['सि.नं.', 'नाम', 'रकम', 'कार्य']);
     *   // ... <tr><td data-label="नाम">राम</td>...</tr> ...
     *   echo coopTableClose();
     *
     * @param array  $headers    Column header labels (Nepali/English)
     * @param bool   $stackOnMobile  true = card-stack on mobile (<768px)
     * @param string $extraClass Additional class on <table>
     */
    function coopTableOpen(array $headers, bool $stackOnMobile = true, string $extraClass = ''): string {
        $stackClass = $stackOnMobile ? ' table-responsive-stack' : '';
        $headCells  = '';
        foreach ($headers as $h) {
            $label     = htmlspecialchars((string)$h, ENT_QUOTES, 'UTF-8');
            $headCells .= "<th>{$label}</th>";
        }
        return <<<HTML
<div class="v9-table-scroll">
<table class="table table-striped table-hover align-middle coop-table{$stackClass} {$extraClass}">
<thead><tr>{$headCells}</tr></thead>
<tbody>
HTML;
    }
}

if (!function_exists('coopTableClose')) {
    /**
     * Close the table opened by coopTableOpen().
     */
    function coopTableClose(): string {
        return "</tbody></table></div>\n";
    }
}

if (!function_exists('coopTableCell')) {
    /**
     * Render a <td> with a data-label attribute for mobile card view.
     * Use inside a <tr> within coopTableOpen() / coopTableClose().
     *
     * @param string $label   Column header (same value passed to coopTableOpen)
     * @param string $content Raw HTML allowed (pre-escaped by caller)
     * @param string $class   Extra TD class
     */
    function coopTableCell(string $label, string $content, string $class = ''): string {
        $dataLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $classAttr = $class !== '' ? " class=\"{$class}\"" : '';
        return "<td data-label=\"{$dataLabel}\"{$classAttr}>{$content}</td>";
    }
}

if (!function_exists('coopEmptyRow')) {
    /**
     * Full-width "no records" row for tables.
     *
     * @param int    $cols    Number of table columns (for colspan)
     * @param string $message Localised message (already HTML-safe)
     */
    function coopEmptyRow(int $cols, string $message = 'कुनै रेकर्ड फेला परेन।'): string {
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<tr>
  <td colspan="{$cols}" class="text-center text-muted py-4">
    <i class="fas fa-inbox fa-2x d-block mb-2 opacity-25"></i>
    {$msg}
  </td>
</tr>
HTML;
    }
}

if (!function_exists('coopStatusBadge')) {
    /**
     * Render a consistent status badge cross-portal.
     * Supports: active, inactive, pending, approved, rejected, review, success,
     *           danger, warning, info, primary, secondary, default.
     *
     * @param string $status  Raw status string (case-insensitive)
     * @param string $label   Override display label (defaults to ucfirst $status)
     */
    function coopStatusBadge(string $status, string $label = ''): string {
        $s   = strtolower(trim($status));
        $map = [
            'active'      => 'badge-approved',
            'approved'    => 'badge-approved',
            'success'     => 'badge-approved',
            'completed'   => 'badge-approved',
            'resolved'    => 'badge-approved',
            'inactive'    => 'badge bg-secondary',
            'pending'     => 'badge-pending',
            'under_review'=> 'badge-review',
            'incomplete'  => 'badge-pending',
            'rejected'    => 'badge-rejected',
            'failed'      => 'badge-rejected',
            'danger'      => 'badge-rejected',
            'review'      => 'badge-review',
            'due_review'  => 'badge-review',
            'info'        => 'badge-review',
            'warning'     => 'badge-pending',
        ];
        $cls  = $map[$s] ?? 'badge bg-secondary text-white';
        $text = $label !== '' ? htmlspecialchars($label, ENT_QUOTES) : htmlspecialchars(ucfirst($status), ENT_QUOTES);
        return "<span class=\"badge {$cls}\">{$text}</span>";
    }
}

if (!function_exists('coopPageHeader')) {
    /**
     * Render a page-level heading row (title + optional action button).
     * Admin र public दुवैमा एउटै style।
     *
     * Usage:
     *   echo coopPageHeader('सदस्यहरू', 'fa-users', 'नयाँ थप्नुहोस्', 'add.php', 'fa-plus');
     *
     * @param string $title      Page heading text
     * @param string $icon       FontAwesome icon class (e.g. 'fa-users')
     * @param string $btnLabel   Action button label (optional)
     * @param string $btnUrl     Action button URL (optional)
     * @param string $btnIcon    Action button icon (optional)
     * @param string $btnClass   Bootstrap btn variant class (default: btn-primary)
     */
    function coopPageHeader(
        string $title,
        string $icon    = '',
        string $btnLabel = '',
        string $btnUrl   = '',
        string $btnIcon  = 'fa-plus',
        string $btnClass = 'btn-primary'
    ): string {
        $iconHtml = $icon
            ? '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . ' me-2" style="color:var(--primary-color);"></i>'
            : '';
        $btnHtml = ($btnLabel && $btnUrl)
            ? '<a href="' . htmlspecialchars($btnUrl, ENT_QUOTES) . '" class="btn btn-sm ' . htmlspecialchars($btnClass, ENT_QUOTES) . '">'
              . '<i class="fas ' . htmlspecialchars($btnIcon, ENT_QUOTES) . ' me-1"></i>'
              . htmlspecialchars($btnLabel, ENT_QUOTES, 'UTF-8')
              . '</a>'
            : '';
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<div class="page-header mb-3">
    <h4 class="page-title mb-0 d-flex align-items-center">{$iconHtml}{$t}</h4>
    {$btnHtml}
</div>
HTML;
    }
}

if (!function_exists('coopInfoCard')) {
    /**
     * Simple key-value info card — for member/kyc detail views.
     *
     * Usage:
     *   echo coopInfoCard('व्यक्तिगत विवरण', 'fa-user', [
     *     'पूरा नाम'  => $name,
     *     'इमेल'     => $email,
     *     'मोबाइल'   => $phone,
     *   ]);
     *
     * @param string $title   Card heading
     * @param string $icon    FA icon
     * @param array  $rows    ['label' => 'value'] pairs
     * @param string $class   Extra CSS class on wrapper
     */
    function coopInfoCard(string $title, string $icon, array $rows, string $class = ''): string {
        $iconHtml = $icon
            ? '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES) . ' me-2" style="color:var(--primary-color);"></i>'
            : '';
        $t     = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $extra = $class ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '';
        $rows_html = '';
        foreach ($rows as $k => $v) {
            $key = htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8');
            $val = $v !== null && $v !== '' ? htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>';
            $rows_html .= "<tr><th style='width:38%;font-weight:600;color:var(--text-muted,#6b7280);padding:8px 12px;font-size:.84rem;'>{$key}</th>"
                        . "<td style='padding:8px 12px;font-size:.88rem;word-break:break-word;'>{$val}</td></tr>";
        }
        return <<<HTML
<div class="form-card{$extra}">
    <div class="form-card-title">{$iconHtml}{$t}</div>
    <table class="table table-sm mb-0" style="font-family:var(--font-primary);">
        <tbody>{$rows_html}</tbody>
    </table>
</div>
HTML;
    }
}

if (!function_exists('coopPaginationLinks')) {
    /**
     * Simple Bootstrap 5 pagination links.
     *
     * Usage:
     *   echo coopPaginationLinks($currentPage, $totalPages, 'members.php?status=approved');
     *
     * @param int    $current   Current page number (1-based)
     * @param int    $total     Total number of pages
     * @param string $baseUrl   Base URL (without &page=N)
     * @param string $pageParam Query parameter name (default: 'page')
     */
    function coopPaginationLinks(int $current, int $total, string $baseUrl = '', string $pageParam = 'page'): string {
        if ($total <= 1) return '';
        $sep   = str_contains($baseUrl, '?') ? '&' : '?';
        $html  = '<nav aria-label="Pagination" class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0">';
        $prev  = $current > 1
            ? "<a class='page-link' href='{$baseUrl}{$sep}{$pageParam}=" . ($current - 1) . "'>«</a>"
            : "<span class='page-link text-muted'>«</span>";
        $html .= "<li class='page-item" . ($current <= 1 ? ' disabled' : '') . "'>{$prev}</li>";
        $start = max(1, $current - 2);
        $end   = min($total, $current + 2);
        if ($start > 1) {
            $html .= "<li class='page-item'><a class='page-link' href='{$baseUrl}{$sep}{$pageParam}=1'>1</a></li>";
            if ($start > 2) $html .= "<li class='page-item disabled'><span class='page-link'>…</span></li>";
        }
        for ($i = $start; $i <= $end; $i++) {
            $active = $i === $current ? ' active' : '';
            $html  .= "<li class='page-item{$active}'>"
                    . "<a class='page-link' href='{$baseUrl}{$sep}{$pageParam}={$i}'>{$i}</a></li>";
        }
        if ($end < $total) {
            if ($end < $total - 1) $html .= "<li class='page-item disabled'><span class='page-link'>…</span></li>";
            $html .= "<li class='page-item'><a class='page-link' href='{$baseUrl}{$sep}{$pageParam}={$total}'>{$total}</a></li>";
        }
        $next  = $current < $total
            ? "<a class='page-link' href='{$baseUrl}{$sep}{$pageParam}=" . ($current + 1) . "'>»</a>"
            : "<span class='page-link text-muted'>»</span>";
        $html .= "<li class='page-item" . ($current >= $total ? ' disabled' : '') . "'>{$next}</li>";
        $html .= '</ul></nav>';
        return $html;
    }
}
