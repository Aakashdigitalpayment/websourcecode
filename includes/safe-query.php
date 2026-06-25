<?php
/**
 * 🔒 Safe Query Helpers
 * ─────────────────────────────────────────────────────────────
 * SQL injection बाट बच्न ्standardized utilities।
 *
 * Usage:
 *   $w = sqWhere(['is_read' => 0], ['name','email','subject'], $searchTerm);
 *   $stmt = $db->prepare("SELECT * FROM contact_messages {$w['sql']} ORDER BY created_at DESC");
 *   $stmt->execute($w['params']);
 *   $rows = $stmt->fetchAll();
 */

if (!function_exists('sqWhere')) {
    /**
     * Safe WHERE builder — equality filters + LIKE search across columns.
     *
     * @param array  $filters       ['col' => value]  exact-match filters
     * @param array  $searchColumns ['col1', 'col2']  columns to search with LIKE
     * @param string $searchTerm    user-typed search keyword
     * @return array ['sql' => 'WHERE ...', 'params' => [...]]
     */
    function sqWhere(array $filters, array $searchColumns = [], string $searchTerm = ''): array {
        $clauses = [];
        $params  = [];

        foreach ($filters as $col => $val) {
            // whitelist: column name मा letters, digits, underscore मात्र
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) continue;
            $clauses[] = "`{$col}` = ?";
            $params[]  = $val;
        }

        if ($searchTerm !== '' && $searchColumns) {
            $likes = [];
            foreach ($searchColumns as $col) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) continue;
                $likes[]  = "`{$col}` LIKE ?";
                $params[] = '%' . $searchTerm . '%';
            }
            if ($likes) $clauses[] = '(' . implode(' OR ', $likes) . ')';
        }

        $sql = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        return ['sql' => $sql, 'params' => $params];
    }
}

if (!function_exists('sqTable')) {
    /**
     * Whitelist table names (for queries which legitimately need
     * dynamic table names like dashboard stats).
     */
    function sqTable(string $name, array $allowed): ?string {
        return in_array($name, $allowed, true) ? $name : null;
    }
}

if (!function_exists('sqOrderBy')) {
    /**
     * Whitelist ORDER BY column.
     */
    function sqOrderBy(string $col, array $allowed, string $dir = 'DESC'): string {
        $col = in_array($col, $allowed, true) ? $col : $allowed[0];
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';
        return "ORDER BY `{$col}` {$dir}";
    }
}

if (!function_exists('sqCount')) {
    /**
     * Run a COUNT-style query safely and return 0 on failure.
     * Useful for dashboards/widgets where partial schema is acceptable.
     */
    function sqCount(PDO $db, string $sql, string $logPrefix = '[sqCount]'): int {
        try {
            return (int)($db->query($sql)->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            error_log($logPrefix . ' ' . $e->getMessage());
            return 0;
        }
    }
}
