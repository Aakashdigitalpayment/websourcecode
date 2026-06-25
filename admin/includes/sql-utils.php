<?php
/**
 * admin/includes/sql-utils.php
 * ════════════════════════════════════════════════════
 * Shared SQL utilities — db-setup.php + run-migration.php दुवैले use गर्छन्।
 * ════════════════════════════════════════════════════
 */
if (!defined('IS_ADMIN_PAGE')) { http_response_code(403); exit('Access denied.'); }

/* ─────────────────────────────────────────────────────────────────────
 * splitSqlStatements — DELIMITER-aware splitter (v10.3)
 * Handles `DELIMITER $$ ... END$$ DELIMITER ;` blocks (PROCEDURE, TRIGGER, FUNCTION)
 * which the previous explode(';') splitter chopped in half, causing the
 * "PROCEDURE sp_v3_add_index does not exist" cascade of errors.
 * ─────────────────────────────────────────────────────────────────────*/
if (!function_exists('splitSqlStatements')) {
    function splitSqlStatements(string $sql): array {
        // Strip /* ... */ block comments and -- line comments first.
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $sql = preg_replace('/--[^\r\n]*/', '', $sql);

        $delim = ';';
        $buf   = '';
        $out   = [];
        $lines = preg_split('/\r\n|\n|\r/', $sql);

        foreach ($lines as $line) {
            $trim = trim($line);
            // DELIMITER directive — switch terminator and skip the line itself
            if (preg_match('/^DELIMITER\s+(\S+)/i', $trim, $m)) {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                    $buf = '';
                }
                $delim = $m[1];
                continue;
            }
            $buf .= $line . "\n";
            // If buffer ends with the current delimiter (after trim), emit a statement
            $rtrim = rtrim($buf);
            if (substr($rtrim, -strlen($delim)) === $delim) {
                $stmt = trim(substr($rtrim, 0, -strlen($delim)));
                if ($stmt !== '') $out[] = $stmt;
                $buf = '';
            }
        }
        if (trim($buf) !== '') $out[] = trim($buf);
        return array_values(array_filter($out, fn($s) => $s !== ''));
    }
}
