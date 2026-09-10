<?php
/**
 * Lang-aware date UI helpers.
 * - Nepali site lang → बि.सं. Nepali datepicker (text)
 * - English site lang → native A.D. <input type="date">
 * DB should always store Gregorian A.D. (Y-m-d); convert on submit.
 */
declare(strict_types=1);

if (!function_exists('coop_date_ui_use_bs')) {
    function coop_date_ui_use_bs(): bool
    {
        return !(function_exists('isEnglish') && isEnglish());
    }
}

if (!function_exists('coop_date_ui_to_display')) {
    /**
     * Prefill value for the visible picker (BS when Nepali UI, AD when English).
     */
    function coop_date_ui_to_display(?string $value): string
    {
        $v = trim((string)$value);
        if ($v === '') {
            return '';
        }
        $ymd = substr($v, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return $ymd;
        }
        $year = (int)substr($ymd, 0, 4);
        $useBs = coop_date_ui_use_bs();
        if ($useBs) {
            if ($year >= 2070) {
                return $ymd;
            }
            if (function_exists('adToBs')) {
                $bs = adToBs($ymd);
                if (is_string($bs) && $bs !== '') {
                    return $bs;
                }
            }
            if (function_exists('nepali_ad_to_bs_string')) {
                $bs = nepali_ad_to_bs_string($ymd);
                if (is_string($bs) && $bs !== '') {
                    return $bs;
                }
            }
            return $ymd;
        }
        if ($year >= 2070) {
            if (function_exists('bsToAd')) {
                $ad = bsToAd($ymd);
                if (is_string($ad) && $ad !== '') {
                    return $ad;
                }
            }
            if (function_exists('nepali_bs_to_ad_string')) {
                $ad = nepali_bs_to_ad_string($ymd);
                if (is_string($ad) && $ad !== '') {
                    return $ad;
                }
            }
        }
        return $ymd;
    }
}

if (!function_exists('coop_date_ui_normalize_ad')) {
    /** Convert posted UI date (BS or AD) to AD Y-m-d for DB. */
    function coop_date_ui_normalize_ad(?string $value): string
    {
        $v = trim((string)$value);
        if ($v === '') {
            return '';
        }
        if (function_exists('appointmentNormalizeDate')) {
            return appointmentNormalizeDate($v);
        }
        if (function_exists('mpNormalizeDate')) {
            return mpNormalizeDate($v);
        }
        $ymd = substr($v, 0, 10);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) && (int)$m[1] >= 2070) {
            if (function_exists('bsToAd')) {
                $ad = bsToAd($ymd);
                if (is_string($ad) && $ad !== '') {
                    return $ad;
                }
            }
            if (function_exists('nepali_bs_to_ad_string')) {
                $ad = nepali_bs_to_ad_string($ymd);
                if (is_string($ad) && $ad !== '') {
                    return $ad;
                }
            }
        }
        return $ymd;
    }
}

if (!function_exists('coop_date_label_calendar')) {
    /** Short calendar tag for labels: (बि.सं.) / (A.D.) */
    function coop_date_label_calendar(): string
    {
        if (coop_date_ui_use_bs()) {
            return function_exists('isEnglish') && isEnglish() ? ' (B.S.)' : ' (बि.सं.)';
        }
        return function_exists('isEnglish') && isEnglish() ? ' (A.D.)' : ' (ई.सं.)';
    }
}

if (!function_exists('coop_date_hint_html')) {
    function coop_date_hint_html(): string
    {
        if (coop_date_ui_use_bs()) {
            $t = function_exists('isEnglish') && isEnglish()
                ? 'Pick a Bikram Sambat date — stored safely as A.D. in the system.'
                : 'नेपाली पात्रो (बि.सं.) बाट छान्नुहोस् — प्रणालीमा ई.सं. सुरक्षित हुन्छ।';
        } else {
            $t = function_exists('isEnglish') && isEnglish()
                ? 'Select a Gregorian (A.D.) date.'
                : 'ईस्वी संवत् (ई.सं.) मिति छान्नुहोस्।';
        }
        return '<div class="form-text coop-date-hint">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

if (!function_exists('coop_date_input_html')) {
    /**
     * Render a user-friendly date field (icon + picker).
     *
     * @param array{
     *   name:string,
     *   id:string,
     *   class?:string,
     *   required?:bool,
     *   value?:string|null,
     *   min_ad?:string,
     *   placeholder?:string,
     *   hint?:bool
     * } $o
     */
    function coop_date_input_html(array $o): string
    {
        $name = (string)($o['name'] ?? 'date');
        $id = (string)($o['id'] ?? $name);
        $baseClass = trim((string)($o['class'] ?? 'form-control'));
        $required = !empty($o['required']);
        $value = coop_date_ui_to_display(isset($o['value']) ? (string)$o['value'] : '');
        $showHint = array_key_exists('hint', $o) ? !empty($o['hint']) : true;
        $useBs = coop_date_ui_use_bs();

        $nameEsc = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $idEsc = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $valEsc = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $reqAttr = $required ? ' required' : '';

        if ($useBs) {
            $ph = htmlspecialchars((string)($o['placeholder'] ?? 'YYYY-MM-DD'), ENT_QUOTES, 'UTF-8');
            $cls = htmlspecialchars(trim($baseClass . ' nepali-datepicker'), ENT_QUOTES, 'UTF-8');
            $html = '<div class="coop-date-wrap input-group">'
                . '<span class="input-group-text coop-date-ico" aria-hidden="true"><i class="fas fa-calendar-alt"></i></span>'
                . '<input type="text" name="' . $nameEsc . '" id="' . $idEsc . '" class="' . $cls . '"'
                . ' value="' . $valEsc . '" placeholder="' . $ph . '" autocomplete="off" inputmode="numeric"'
                . $reqAttr . '>'
                . '</div>';
        } else {
            $min = '';
            if (!empty($o['min_ad'])) {
                $min = ' min="' . htmlspecialchars((string)$o['min_ad'], ENT_QUOTES, 'UTF-8') . '"';
            }
            $cls = htmlspecialchars($baseClass, ENT_QUOTES, 'UTF-8');
            $html = '<div class="coop-date-wrap input-group">'
                . '<span class="input-group-text coop-date-ico" aria-hidden="true"><i class="fas fa-calendar-alt"></i></span>'
                . '<input type="date" name="' . $nameEsc . '" id="' . $idEsc . '" class="' . $cls . '"'
                . ' value="' . $valEsc . '" data-calendar="ad"' . $min . $reqAttr . '>'
                . '</div>';
        }

        if ($showHint) {
            $html .= coop_date_hint_html();
        }
        return $html;
    }
}
