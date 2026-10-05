<?php
/**
 * मासिक बचत (नियमित / नियमित नभएको) — single source of truth.
 *
 * Stored on members.monthly_saving_regular (SSOT; KYM view/edit reads + writes the linked member):
 *   1    = नियमित (regular)
 *   0    = नियमित नभएको (not regular)
 *   NULL = अहिलेसम्म नतोकिएको (unknown — old rows stay untouched until someone sets it)
 *
 * Used by: admin Members, KYM, member import (full + field-update), Program Registration Desk.
 */
declare(strict_types=1);

if (!defined('COOP_MONTHLY_SAVING_COL')) {
    define('COOP_MONTHLY_SAVING_COL', 'monthly_saving_regular');
}

if (!function_exists('coop_monthly_saving_ensure_column')) {
    function coop_monthly_saving_ensure_column(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            if (function_exists('safeAddColumn')) {
                safeAddColumn($db, 'members', COOP_MONTHLY_SAVING_COL, 'TINYINT(1) NULL DEFAULT NULL');
                return;
            }
            $has = $db->query("SHOW COLUMNS FROM members LIKE '" . COOP_MONTHLY_SAVING_COL . "'");
            if ($has && !$has->fetch()) {
                $db->exec('ALTER TABLE members ADD COLUMN ' . COOP_MONTHLY_SAVING_COL . ' TINYINT(1) NULL DEFAULT NULL');
            }
        } catch (Throwable $e) {
            error_log('[monthly-saving] ensure column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('coop_monthly_saving_parse')) {
    /**
     * CSV / form value → 1 | 0 | null.
     * Blank = null (keep existing). Unknown text = ok:false (row error, nothing changed).
     *
     * @return array{ok:bool, value:?int}
     */
    function coop_monthly_saving_parse($raw): array
    {
        if ($raw === null) {
            return ['ok' => true, 'value' => null];
        }
        if (is_int($raw) || is_bool($raw)) {
            return ['ok' => true, 'value' => $raw ? 1 : 0];
        }
        $v = trim((string) $raw);
        $v = strtr($v, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
        $v = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
        $v = trim((string) preg_replace('/[\s_\-.]+/u', ' ', $v));
        if ($v === '') {
            return ['ok' => true, 'value' => null];
        }
        static $yes = [
            '1', 'yes', 'y', 'true', 'regular', 'niyamit', 'नियमित', 'हो', 'छ', 'ho', 'cha', 'chha', '✓',
        ];
        static $no = [
            '0', 'no', 'n', 'false', 'irregular', 'not regular', 'aniyamit', 'अनियमित', 'होइन', 'छैन', 'hoina', 'chaina', '✗',
            'नियमित नभएको', 'नियमित नभएका', 'नियमित छैन', 'niyamit nabhayeko', 'niyamit nabhaeko', 'niyamit chaina',
        ];
        if (in_array($v, $yes, true)) {
            return ['ok' => true, 'value' => 1];
        }
        if (in_array($v, $no, true)) {
            return ['ok' => true, 'value' => 0];
        }
        return ['ok' => false, 'value' => null];
    }
}

if (!function_exists('coop_monthly_saving_from_db')) {
    /** DB cell → 1 | 0 | null */
    function coop_monthly_saving_from_db($cell): ?int
    {
        if ($cell === null || $cell === '') {
            return null;
        }
        return ((int) $cell) === 1 ? 1 : 0;
    }
}

if (!function_exists('coop_monthly_saving_label')) {
    function coop_monthly_saving_label(?int $value, bool $english = false): string
    {
        if ($value === 1) {
            return $english ? 'Regular' : 'नियमित';
        }
        if ($value === 0) {
            return $english ? 'Not regular' : 'नियमित नभएको';
        }
        return $english ? 'Not set' : 'नतोकिएको';
    }
}

if (!function_exists('coop_monthly_saving_modifier')) {
    /** CSS modifier shared by the status chip and the choice buttons: yes | no | unset */
    function coop_monthly_saving_modifier(?int $value): string
    {
        return $value === 1 ? 'yes' : ($value === 0 ? 'no' : 'unset');
    }
}

if (!function_exists('coop_monthly_saving_badge_html')) {
    function coop_monthly_saving_badge_html(?int $value, bool $english = false): string
    {
        /* Own colours (ms-badge--*), not Bootstrap bg-* — site themes recolour those and hid the text */
        $mod = coop_monthly_saving_modifier($value);
        $icon = $value === 1 ? '✓ ' : ($value === 0 ? '! ' : '');
        return '<span class="ms-badge ms-badge--' . $mod . '">' . $icon . htmlspecialchars(coop_monthly_saving_label($value, $english), ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('coop_monthly_saving_get')) {
    function coop_monthly_saving_get(PDO $db, int $memberPk): ?int
    {
        if ($memberPk < 1) {
            return null;
        }
        coop_monthly_saving_ensure_column($db);
        try {
            $st = $db->prepare('SELECT ' . COOP_MONTHLY_SAVING_COL . ' FROM members WHERE id = ? LIMIT 1');
            $st->execute([$memberPk]);
            return coop_monthly_saving_from_db($st->fetchColumn());
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('coop_monthly_saving_set')) {
    /**
     * Update only this one column. No-op when unchanged. Audit-logged with source (bulk import passes $audit=false).
     *
     * @return array{ok:bool, changed:bool, old:?int, new:?int}
     */
    function coop_monthly_saving_set(PDO $db, int $memberPk, ?int $value, string $source = 'admin', bool $audit = true): array
    {
        $out = ['ok' => false, 'changed' => false, 'old' => null, 'new' => $value];
        if ($memberPk < 1 || ($value !== null && $value !== 0 && $value !== 1)) {
            return $out;
        }
        coop_monthly_saving_ensure_column($db);
        try {
            $st = $db->prepare('SELECT ' . COOP_MONTHLY_SAVING_COL . ' FROM members WHERE id = ? LIMIT 1');
            $st->execute([$memberPk]);
            $cell = $st->fetchColumn();
            if ($cell === false) {
                return $out;
            }
            $old = coop_monthly_saving_from_db($cell);
            $out['old'] = $old;
            $out['ok'] = true;
            if ($old === $value) {
                return $out;
            }
            $db->prepare('UPDATE members SET ' . COOP_MONTHLY_SAVING_COL . ' = ? WHERE id = ? LIMIT 1')
                ->execute([$value, $memberPk]);
            $out['changed'] = true;
            if ($audit && function_exists('writeAuditLog')) {
                writeAuditLog(
                    'member_monthly_saving',
                    'मासिक बचत: ' . coop_monthly_saving_label($old) . ' → ' . coop_monthly_saving_label($value) . ' (' . $source . ')',
                    'member',
                    $memberPk
                );
            }
        } catch (Throwable $e) {
            error_log('[monthly-saving] set: ' . $e->getMessage());
            $out['ok'] = false;
        }
        return $out;
    }
}

if (!function_exists('coop_monthly_saving_radios_html')) {
    /**
     * Shared form control (Members, KYM). Posts `monthly_saving` = '1' | '0' | '' (blank = नतोकिएको).
     */
    function coop_monthly_saving_radios_html(?int $value, string $idPrefix = 'ms', string $name = 'monthly_saving'): string
    {
        $opts = [['1', 'नियमित', 1], ['0', 'नियमित नभएको', 0], ['', 'नतोकिएको', null]];
        $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $html = '<div class="ms-choice" role="radiogroup" aria-label="मासिक बचत">';
        foreach ($opts as $i => [$val, $label, $cmp]) {
            $id = htmlspecialchars($idPrefix . '_' . $i, ENT_QUOTES, 'UTF-8');
            $checked = $value === $cmp ? ' checked' : '';
            /* Plain labels (no .btn) so theme button rules can't flatten them; styled by .ms-opt */
            $html .= '<input type="radio" class="btn-check" name="' . $nameEsc . '" id="' . $id . '" value="' . $val . '"' . $checked . '>'
                . '<label class="ms-opt ms-opt--' . coop_monthly_saving_modifier($cmp) . '" for="' . $id . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</label>';
        }
        return $html . '</div>';
    }
}
