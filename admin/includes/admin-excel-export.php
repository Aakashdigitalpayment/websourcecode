<?php
/**
 * Uniform Excel/CSV export helpers for admin online-request lists.
 * UTF-8 BOM CSV — Excel ले सिधै खोल्छ। Data-safe (SELECT only).
 */
declare(strict_types=1);

if (!function_exists('adminExcelStartBuffer')) {
    function adminExcelStartBuffer(): void
    {
        if (!ob_get_level()) {
            ob_start();
        }
    }
}

if (!function_exists('adminExcelDateRangeFromGet')) {
    /**
     * @return array{0: string, 1: string} [date_from, date_to] Y-m-d or ''
     */
    function adminExcelDateRangeFromGet(): array
    {
        $from = trim((string)($_GET['date_from'] ?? ''));
        $to   = trim((string)($_GET['date_to'] ?? ''));
        if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = '';
        }
        if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = '';
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            $tmp = $from;
            $from = $to;
            $to = $tmp;
        }
        return [$from, $to];
    }
}

if (!function_exists('adminExcelAppendDateWhere')) {
    function adminExcelAppendDateWhere(string &$where, array &$params, string $dateFrom, string $dateTo, string $column = 'created_at'): void
    {
        if ($dateFrom !== '') {
            $where .= " AND DATE({$column}) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where .= " AND DATE({$column}) <= ?";
            $params[] = $dateTo;
        }
    }
}

if (!function_exists('adminExcelIsExportRequest')) {
    function adminExcelIsExportRequest(): bool
    {
        $m = (string)($_GET['export'] ?? '');
        return $m === 'csv' || $m === 'excel';
    }
}

if (!function_exists('adminExcelStreamCsv')) {
    /**
     * @param list<string> $headers
     * @param iterable<int, list<scalar|null>> $rows
     */
    function adminExcelStreamCsv(string $filename, array $headers, iterable $rows): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $filename = preg_replace('/[^\w.\-]+/', '-', $filename) ?: 'export.csv';
        if (!str_ends_with(strtolower($filename), '.csv')) {
            $filename .= '.csv';
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            http_response_code(500);
            echo 'Export failed.';
            exit;
        }
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = [];
            foreach ($row as $cell) {
                if (is_bool($cell)) {
                    $line[] = $cell ? 'Yes' : 'No';
                } elseif (is_array($cell)) {
                    $line[] = json_encode($cell, JSON_UNESCAPED_UNICODE);
                } else {
                    $line[] = (string)($cell ?? '');
                }
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }
}

if (!function_exists('adminExcelMapRows')) {
    /**
     * @param list<array<string, mixed>> $dbRows
     * @param array<string, string|callable> $columns header => column key or fn(array $row): mixed
     * @return list<list<scalar|null>>
     */
    function adminExcelMapRows(array $dbRows, array $columns): array
    {
        $out = [];
        foreach ($dbRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = [];
            foreach ($columns as $keyOrFn) {
                if (is_callable($keyOrFn)) {
                    $line[] = $keyOrFn($row);
                } else {
                    $line[] = $row[$keyOrFn] ?? '';
                }
            }
            $out[] = $line;
        }
        return $out;
    }
}

if (!function_exists('adminExcelDateInputsHtml')) {
    function adminExcelDateInputsHtml(string $dateFrom, string $dateTo, string $colClass = 'col-md-2 col-6'): string
    {
        $from = htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8');
        $to   = htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8');
        $c    = htmlspecialchars($colClass, ENT_QUOTES, 'UTF-8');
        return <<<HTML
        <div class="{$c}">
            <label>मिति देखि</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="{$from}">
        </div>
        <div class="{$c}">
            <label>मिति सम्म</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="{$to}">
        </div>
HTML;
    }
}

if (!function_exists('adminExcelExportButtonHtml')) {
    /**
     * @param array<string, scalar|null> $queryParams filters without export key
     */
    function adminExcelExportButtonHtml(array $queryParams, int $total = 0, string $btnLabel = 'Excel डाउनलोड'): string
    {
        $q = array_filter(
            $queryParams,
            static fn($v) => $v !== null && $v !== ''
        );
        $q['export'] = 'csv';
        $href = '?' . htmlspecialchars(http_build_query($q), ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($btnLabel, ENT_QUOTES, 'UTF-8');
        $count = (int)$total;
        $countHtml = $count > 0
            ? ' <span class="opacity-75">(' . $count . ')</span>'
            : '';
        return '<div class="d-flex flex-wrap gap-2 mt-2 align-items-center">'
            . '<a href="' . $href . '" class="btn btn-success btn-sm">'
            . '<i class="fas fa-file-excel me-1"></i>' . $label . $countHtml
            . '</a>'
            . '<span class="small text-muted">मिति / फिल्टर अनुसार CSV — Excel ले खोल्छ।</span>'
            . '</div>';
    }
}

if (!function_exists('adminExcelSingleLink')) {
    function adminExcelSingleLink(string $baseUrl, int $id, string $class = 'btn btn-success btn-sm'): string
    {
        if ($id < 1) {
            return '';
        }
        $href = htmlspecialchars($baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'export=csv&id=' . $id, ENT_QUOTES, 'UTF-8');
        $cls  = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        return '<a href="' . $href . '" class="' . $cls . '"><i class="fas fa-file-excel me-1"></i>Excel</a>';
    }
}

if (!function_exists('adminPrintFormLink')) {
    /**
     * Uniform bank-style print page link: admin/print-form.php?type=…&id=…
     * @param 'kyc'|'loan'|'account'|'digital'|'welfare'|'honor'|'appointment'|'grievance'|'job' $type
     */
    function adminPrintFormLink(string $type, int $id, string $class = 'btn btn-light btn-sm', string $label = 'Print Form'): string
    {
        if ($id < 1) {
            return '';
        }
        $type = preg_replace('/[^a-z_]/', '', strtolower($type)) ?: '';
        $allowed = ['kyc','loan','account','digital','welfare','honor','appointment','grievance','job'];
        if (!in_array($type, $allowed, true)) {
            return '';
        }
        $href = htmlspecialchars('print-form.php?type=' . $type . '&id=' . $id, ENT_QUOTES, 'UTF-8');
        $cls  = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        $lab  = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return '<a href="' . $href . '" target="_blank" rel="noopener" class="' . $cls . '">'
            . '<i class="fas fa-print me-1"></i>' . $lab . '</a>';
    }
}

if (!function_exists('adminPrintFormIcon')) {
    /** Compact list-row print icon */
    function adminPrintFormIcon(string $type, int $id): string
    {
        if ($id < 1) {
            return '';
        }
        $type = preg_replace('/[^a-z_]/', '', strtolower($type)) ?: '';
        $allowed = ['kyc','loan','account','digital','welfare','honor','appointment','grievance','job'];
        if (!in_array($type, $allowed, true)) {
            return '';
        }
        $href = htmlspecialchars('print-form.php?type=' . $type . '&id=' . $id, ENT_QUOTES, 'UTF-8');
        return '<a href="' . $href . '" target="_blank" rel="noopener" class="adm-icon-btn adm-icon-btn--print" title="Print" aria-label="Print">'
            . '<i class="fas fa-print"></i></a>';
    }
}

if (!function_exists('adminPublicFileUrl')) {
    /** Join SITE_URL + relative upload path safely (always one slash). */
    function adminPublicFileUrl(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        if (!defined('SITE_URL')) {
            return '/' . ltrim($path, '/');
        }
        return rtrim((string)SITE_URL, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('adminExcelIcon')) {
    /** Compact list-row Excel download icon */
    function adminExcelIcon(string $baseUrl, int $id): string
    {
        if ($id < 1) {
            return '';
        }
        $href = htmlspecialchars($baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'export=csv&id=' . $id, ENT_QUOTES, 'UTF-8');
        return '<a href="' . $href . '" class="adm-icon-btn" title="Excel" aria-label="Excel">'
            . '<i class="fas fa-file-excel text-success"></i></a>';
    }
}

if (!function_exists('adminExcelFilename')) {
    function adminExcelFilename(string $prefix, string $dateFrom = '', string $dateTo = ''): string
    {
        $prefix = preg_replace('/[^\w\-]+/', '-', $prefix) ?: 'export';
        if ($dateFrom !== '' || $dateTo !== '') {
            return $prefix . '-' . ($dateFrom !== '' ? $dateFrom : 'start')
                . '_to_' . ($dateTo !== '' ? $dateTo : 'end') . '-' . date('His') . '.csv';
        }
        return $prefix . '-' . date('Ymd-His') . '.csv';
    }
}
