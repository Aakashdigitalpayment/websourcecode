<?php
/**
 * BS fiscal-year <option> list for admin report forms.
 * Additive helper — no schema / URL changes.
 */
if (!function_exists('getBSFiscalYears')) {
    function getBSFiscalYears(string $selected = ''): string
    {
        $start = 2070;
        $end = 2086;
        if (function_exists('nepali_ad_to_bs_string')) {
            $bs = nepali_ad_to_bs_string(date('Y-m-d'));
            if (is_string($bs) && preg_match('/^(\d{4})-/', $bs, $m)) {
                $end = max($end, ((int) $m[1]) + 3);
            }
        }
        $html = '';
        for ($y = $start; $y <= $end; $y++) {
            $next = $y + 1 - 2000;
            $label = $y . '/' . str_pad((string) $next, 2, '0', STR_PAD_LEFT);
            $sel = ($selected === $label) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</option>\n";
        }
        return $html;
    }
}
