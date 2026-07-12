<?php
/**
 * ═══════════════════════════════════════════════════════════════
 * 🛠️ CORE HELPERS — Global Utility Functions
 * ═══════════════════════════════════════════════════════════════
 * फाइल: core/helpers.php
 *
 * यो file core/init.php बाट automatically load हुन्छ।
 * सबै portal (Public, Admin, Member, Verify) मा available।
 *
 * SECTIONS:
 *   1.  Input Sanitization & Validation
 *   2.  Date & Time (AD/BS)
 *   3.  Currency & Number Formatting
 *   4.  Banking & Interest Calculations
 *   5.  String Utilities
 *   6.  File & Upload Helpers
 *   7.  Security Utilities
 *   8.  Array & Data Utilities
 *   9.  URL & SEO Helpers
 *   10. Debug Utilities (dev only)
 *
 * ⚠️ यहाँ define गरिएका functions config.php मा पहिले नै छन् भने
 *    if (!function_exists(...)) check ले duplicate error रोक्छ।
 * ═══════════════════════════════════════════════════════════════
 */

// ═══════════════════════════════════════════════════════════════
// SECTION 1: INPUT SANITIZATION & VALIDATION
// ═══════════════════════════════════════════════════════════════

/**
 * HTML display को लागि sanitize — XSS रोक्न।
 * DB मा store गर्दा प्रयोग नगर्नुस्, screen मा print गर्दा प्रयोग गर्नुस्।
 */
if (!function_exists('sanitize')) {
    function sanitize(mixed $input): string {
        if ($input === null) return '';
        return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * DB storage को लागि clean — HTML escape नगरी trim + control chars हटाउँछ।
 */
if (!function_exists('clean_text')) {
    function clean_text(mixed $input, int $maxLen = 4096): string {
        if ($input === null) return '';
        $s = trim((string)$input);
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return function_exists('mb_substr')
            ? mb_substr($s, 0, $maxLen, 'UTF-8')
            : substr($s, 0, $maxLen);
    }
}

/**
 * Display को लागि shortcut alias — sanitize() जस्तै।
 */
if (!function_exists('e')) {
    function e(mixed $val): string {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Integer input sanitize — DB query को लागि।
 */
if (!function_exists('clean_int')) {
    function clean_int(mixed $input, int $default = 0): int {
        $val = filter_var($input, FILTER_VALIDATE_INT);
        return ($val !== false) ? (int)$val : $default;
    }
}

/**
 * Float input sanitize।
 */
if (!function_exists('clean_float')) {
    function clean_float(mixed $input, float $default = 0.0): float {
        $val = filter_var($input, FILTER_VALIDATE_FLOAT);
        return ($val !== false) ? (float)$val : $default;
    }
}

/**
 * POST data bulk sanitize।
 * Usage: $data = sanitize_post(['name', 'email', 'phone']);
 */
if (!function_exists('sanitize_post')) {
    function sanitize_post(array $fields): array {
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = clean_text($_POST[$field] ?? '');
        }
        return $out;
    }
}

/**
 * Email validation।
 */
if (!function_exists('isValidEmail')) {
    function isValidEmail(mixed $email): bool {
        return filter_var(trim((string)$email), FILTER_VALIDATE_EMAIL) !== false;
    }
}

/**
 * Nepal phone validation (10 digits, 9 बाट सुरु)।
 */
if (!function_exists('isValidPhone')) {
    function isValidPhone(mixed $phone): bool {
        $clean = preg_replace('/[^0-9]/', '', (string)$phone);
        return (bool)preg_match('/^9[0-9]{9}$/', $clean);
    }
}

/**
 * NRB Citizenship number validation।
 */
if (!function_exists('isValidCitizenshipNo')) {
    function isValidCitizenshipNo(mixed $no): bool {
        $clean = trim((string)$no);
        // Format: XX-XX-XXXXX-XXXXX (flexible)
        return strlen($clean) >= 6 && strlen($clean) <= 30;
    }
}

/**
 * Nepal Permanent Account Number (PAN) validation।
 */
if (!function_exists('isValidPAN')) {
    function isValidPAN(mixed $pan): bool {
        return (bool)preg_match('/^\d{9}$/', preg_replace('/[^0-9]/', '', (string)$pan));
    }
}


// ═══════════════════════════════════════════════════════════════
// SECTION 2: DATE & TIME (AD / BS)
// ═══════════════════════════════════════════════════════════════

/**
 * AD date format गर्ने।
 */
if (!function_exists('formatDate')) {
    function formatDate(mixed $date, string $format = 'Y-m-d'): string {
        if (empty($date)) return '';
        $ts = strtotime((string)$date);
        return ($ts !== false) ? date($format, $ts) : (string)$date;
    }
}

/**
 * AD मिति लाई Nepali BS मिति string मा बदल्ने।
 * Return: "२०८१ बैशाख १५" वा English mode मा "2081 Baisakh 15"
 */
if (!function_exists('formatNepaliDate')) {
    function formatNepaliDate(mixed $date, bool $showTime = false): string {
        if (empty($date)) return '';
        $timestamp = strtotime((string)$date);
        if ($timestamp === false) return (string)$date;

        static $bsMonths = [
            1 => 'बैशाख', 2 => 'जेठ',    3 => 'असार',
            4 => 'श्रावण', 5 => 'भदौ',   6 => 'असोज',
            7 => 'कात्तिक', 8 => 'मंसिर', 9 => 'पुष',
            10 => 'माघ',  11 => 'फागुन', 12 => 'चैत्र',
        ];
        static $bsMonthsEn = [
            1 => 'Baisakh', 2 => 'Jestha', 3 => 'Ashadh',
            4 => 'Shrawan', 5 => 'Bhadra', 6 => 'Ashwin',
            7 => 'Kartik',  8 => 'Mangsir',9 => 'Poush',
            10 => 'Magh',  11 => 'Falgun',12 => 'Chaitra',
        ];

        $isEn = function_exists('isEnglish') ? isEnglish() : false;

        if (function_exists('nepali_ad_to_bs_string')) {
            $adYmd = date('Y-m-d', $timestamp);
            $bsYmd = nepali_ad_to_bs_string($adYmd);
            if ($bsYmd) {
                [$bsY, $bsM, $bsD] = array_map('intval', explode('-', $bsYmd));
                $monthName = $isEn
                    ? ($bsMonthsEn[$bsM] ?? $bsM)
                    : ($bsMonths[$bsM] ?? $bsM);

                if ($isEn) {
                    $result = "{$bsY} {$monthName} {$bsD}";
                    if ($showTime) $result .= ' ' . date('H:i', $timestamp);
                } else {
                    $result = toNepaliNumeral($bsY) . ' ' . $monthName . ' ' . toNepaliNumeral($bsD);
                    if ($showTime) $result .= ' ' . toNepaliNumeral(date('H:i', $timestamp));
                }
                return $result;
            }
        }

        // Fallback: AD format
        return $isEn
            ? date('d M Y', $timestamp) . ($showTime ? ' ' . date('H:i', $timestamp) : '')
            : toNepaliNumeral(date('Y-m-d', $timestamp));
    }
}

/**
 * अहिलेको मिति BS मा।
 */
if (!function_exists('todayBS')) {
    function todayBS(): string {
        if (function_exists('nepali_kathmandu_today_bs')) {
            return nepali_kathmandu_today_bs();
        }
        if (function_exists('nepali_ad_to_bs_string')) {
            return nepali_ad_to_bs_string(date('Y-m-d')) ?: date('Y-m-d');
        }
        return date('Y-m-d');
    }
}

/**
 * BS date string लाई AD (Y-m-d) मा बदल्ने।
 */
if (!function_exists('bsToAd')) {
    function bsToAd(string $bsDate): string {
        if (function_exists('nepali_bs_to_ad_string')) {
            return nepali_bs_to_ad_string($bsDate) ?: $bsDate;
        }
        return $bsDate;
    }
}

/**
 * AD date लाई BS string मा बदल्ने।
 */
if (!function_exists('adToBs')) {
    function adToBs(string $adDate): string {
        if (function_exists('nepali_ad_to_bs_string')) {
            return nepali_ad_to_bs_string($adDate) ?: $adDate;
        }
        return $adDate;
    }
}

/**
 * दुई मितिबिचको दिन गणना।
 */
if (!function_exists('daysBetween')) {
    function daysBetween(string $date1, string $date2): int {
        $d1 = new DateTime($date1);
        $d2 = new DateTime($date2);
        return (int)$d1->diff($d2)->days;
    }
}

/**
 * दुई मितिबिचको महिना गणना (floating point)।
 */
if (!function_exists('monthsBetween')) {
    function monthsBetween(string $from, string $to): float {
        return round(daysBetween($from, $to) / 30.4167, 4);
    }
}

/**
 * मिति कति अगाडि/पछाडि — human readable।
 * उदाहरण: "३ दिन अगाडि", "२ महिना अगाडि"
 */
if (!function_exists('timeAgo')) {
    function timeAgo(mixed $date): string {
        $isEn = function_exists('isEnglish') ? isEnglish() : false;
        $ts = is_numeric($date) ? (int)$date : strtotime((string)$date);
        if (!$ts) return '';

        $diff = time() - $ts;

        if ($diff < 60) {
            return $isEn ? 'Just now' : 'भर्खरै';
        } elseif ($diff < 3600) {
            $m = (int)($diff / 60);
            return $isEn ? "{$m} min ago" : toNepaliNumeral($m) . ' मिनेट अगाडि';
        } elseif ($diff < 86400) {
            $h = (int)($diff / 3600);
            return $isEn ? "{$h} hr ago" : toNepaliNumeral($h) . ' घण्टा अगाडि';
        } elseif ($diff < 2592000) {
            $d = (int)($diff / 86400);
            return $isEn ? "{$d} day" . ($d > 1 ? 's' : '') . ' ago' : toNepaliNumeral($d) . ' दिन अगाडि';
        } elseif ($diff < 31536000) {
            $mo = (int)($diff / 2592000);
            return $isEn ? "{$mo} month" . ($mo > 1 ? 's' : '') . ' ago' : toNepaliNumeral($mo) . ' महिना अगाडि';
        } else {
            $y = (int)($diff / 31536000);
            return $isEn ? "{$y} year" . ($y > 1 ? 's' : '') . ' ago' : toNepaliNumeral($y) . ' वर्ष अगाडि';
        }
    }
}

/**
 * English अंक → Nepali देवनागरी अंक।
 */
if (!function_exists('toNepaliNumeral')) {
    function toNepaliNumeral(mixed $number): string {
        static $en = ['0','1','2','3','4','5','6','7','8','9'];
        static $np = ['०','१','२','३','४','५','६','७','८','९'];
        return str_replace($en, $np, (string)$number);
    }
}


// ═══════════════════════════════════════════════════════════════
// SECTION 3: CURRENCY & NUMBER FORMATTING
// ═══════════════════════════════════════════════════════════════

/**
 * Nepal currency format — रू. १,२३,४५६.७८
 * Nepali mode: देवनागरी अंक + लाख/करोड system।
 * English mode: Rs. 1,23,456.78
 */
if (!function_exists('formatNepaliCurrency')) {
    function formatNepaliCurrency(mixed $amount, bool $showSymbol = true, int $decimals = 2): string {
        $isEn = function_exists('isEnglish') ? isEnglish() : false;
        $num = (float)$amount;

        // Nepal number system: लाख/करोड comma placement
        $formatted = _nepalNumberFormat($num, $decimals);

        if ($isEn) {
            return $showSymbol ? 'Rs. ' . $formatted : $formatted;
        }
        return $showSymbol ? 'रू. ' . toNepaliNumeral($formatted) : toNepaliNumeral($formatted);
    }
}

/**
 * Nepal number format — लाख/करोड comma system।
 * 12,34,56,789.00 (not 123,456,789.00)
 */
if (!function_exists('_nepalNumberFormat')) {
    function _nepalNumberFormat(float $num, int $decimals = 2): string {
        $negative = $num < 0;
        $num = abs($num);

        $dec = $decimals > 0 ? '.' . str_pad((string)round(fmod($num, 1) * pow(10, $decimals)), $decimals, '0', STR_PAD_LEFT) : '';
        $int = (string)(int)floor($num);

        // Apply Nepal comma system: last 3 digits, then groups of 2
        if (strlen($int) <= 3) {
            $formatted = $int;
        } else {
            $last3  = substr($int, -3);
            $rest   = substr($int, 0, -3);
            $groups = str_split(str_pad($rest, (int)ceil(strlen($rest) / 2) * 2, '0', STR_PAD_LEFT), 2);
            // Remove leading zero-padded empty group
            $groups = array_filter($groups, fn($g) => (int)$g > 0 || $g !== '00');
            // Remove leading zeros from first group
            $groups = array_values($groups);
            if (!empty($groups)) $groups[0] = ltrim($groups[0], '0') ?: '0';
            $formatted = implode(',', $groups) . ',' . $last3;
        }

        return ($negative ? '-' : '') . $formatted . $dec;
    }
}

/**
 * Simple number format (Nepali numerals, standard commas)।
 */
if (!function_exists('formatNepaliNumber')) {
    function formatNepaliNumber(mixed $number, int $decimals = 0): string {
        $isEn = function_exists('isEnglish') ? isEnglish() : false;
        $formatted = number_format((float)$number, $decimals);
        return $isEn ? $formatted : toNepaliNumeral($formatted);
    }
}

/**
 * ठूलो संख्या — संक्षेप (1.2 लाख, 3.5 करोड)।
 */
if (!function_exists('formatShortAmount')) {
    function formatShortAmount(mixed $amount): string {
        $isEn = function_exists('isEnglish') ? isEnglish() : false;
        $n = (float)$amount;

        if ($n >= 10000000) { // 1 करोड+
            $val = round($n / 10000000, 2);
            $label = $isEn ? 'Cr' : 'करोड';
            $num = $isEn ? $val : toNepaliNumeral($val);
            return "रू. {$num} {$label}";
        }
        if ($n >= 100000) { // 1 लाख+
            $val = round($n / 100000, 2);
            $label = $isEn ? 'Lakh' : 'लाख';
            $num = $isEn ? $val : toNepaliNumeral($val);
            return "रू. {$num} {$label}";
        }
        if ($n >= 1000) { // 1 हजार+
            $val = round($n / 1000, 1);
            $label = $isEn ? 'K' : 'हजार';
            $num = $isEn ? $val : toNepaliNumeral($val);
            return "रू. {$num} {$label}";
        }
        return formatNepaliCurrency($n);
    }
}


// ═══════════════════════════════════════════════════════════════
// SECTION 4: BANKING & INTEREST CALCULATIONS
// ═══════════════════════════════════════════════════════════════

/**
 * Simple Interest — साधारण ब्याज।
 * Formula: SI = (Principal × Rate × Time) / 100
 *
 * @param float $principal   मूलधन (रकम)
 * @param float $ratePercent वार्षिक ब्याजदर (%)
 * @param float $timeDays    दिनमा समय
 * @return array ['interest' => float, 'total' => float, 'daily_rate' => float]
 */
if (!function_exists('calcSimpleInterest')) {
    function calcSimpleInterest(float $principal, float $ratePercent, float $timeDays): array {
        $dailyRate = $ratePercent / 100 / 365;
        $interest  = $principal * $dailyRate * $timeDays;
        return [
            'principal'   => round($principal, 2),
            'rate'        => $ratePercent,
            'days'        => $timeDays,
            'daily_rate'  => round($dailyRate, 8),
            'interest'    => round($interest, 2),
            'total'       => round($principal + $interest, 2),
        ];
    }
}

/**
 * Compound Interest — चक्रवृद्धि ब्याज।
 * Formula: A = P(1 + r/n)^(nt)
 *
 * @param float  $principal     मूलधन
 * @param float  $ratePercent   वार्षिक दर (%)
 * @param float  $years         वर्षमा समय
 * @param int    $compoundPerYear प्रति वर्ष compound हुने पटक (12=monthly, 4=quarterly, 2=half-yearly, 1=yearly)
 * @return array
 */
if (!function_exists('calcCompoundInterest')) {
    function calcCompoundInterest(float $principal, float $ratePercent, float $years, int $compoundPerYear = 12): array {
        $r = $ratePercent / 100;
        $n = $compoundPerYear;
        $t = $years;
        $amount   = $principal * pow(1 + $r / $n, $n * $t);
        $interest = $amount - $principal;
        return [
            'principal'    => round($principal, 2),
            'rate'         => $ratePercent,
            'years'        => $years,
            'frequency'    => $n,
            'interest'     => round($interest, 2),
            'total'        => round($amount, 2),
        ];
    }
}

/**
 * EMI Calculator — समान मासिक किस्ता।
 * Formula: EMI = P × r × (1+r)^n / ((1+r)^n - 1)
 *
 * @param float $principal  ऋणको मूलधन
 * @param float $ratePercent वार्षिक ब्याजदर (%)
 * @param int   $months     ऋण अवधि (महिनामा)
 * @return array
 */
if (!function_exists('calcEMI')) {
    function calcEMI(float $principal, float $ratePercent, int $months): array {
        if ($months <= 0 || $principal <= 0) {
            return ['emi' => 0, 'total_payment' => 0, 'total_interest' => 0];
        }

        $monthlyRate = ($ratePercent / 100) / 12;

        if ($monthlyRate == 0) {
            // Zero interest
            $emi = $principal / $months;
        } else {
            $emi = $principal * $monthlyRate * pow(1 + $monthlyRate, $months)
                 / (pow(1 + $monthlyRate, $months) - 1);
        }

        $totalPayment  = $emi * $months;
        $totalInterest = $totalPayment - $principal;

        return [
            'principal'      => round($principal, 2),
            'rate'           => $ratePercent,
            'months'         => $months,
            'monthly_rate'   => round($monthlyRate, 8),
            'emi'            => round($emi, 2),
            'total_payment'  => round($totalPayment, 2),
            'total_interest' => round($totalInterest, 2),
        ];
    }
}

/**
 * EMI Amortization Schedule — किस्ताको तालिका।
 * हरेक महिनाको मूलधन र ब्याज कति भन्ने विवरण।
 *
 * @return array[] — हरेक row: ['month', 'emi', 'principal', 'interest', 'balance']
 */
if (!function_exists('calcEMISchedule')) {
    function calcEMISchedule(float $principal, float $ratePercent, int $months): array {
        $emiData = calcEMI($principal, $ratePercent, $months);
        $emi     = $emiData['emi'];
        $monthlyRate = $emiData['monthly_rate'];

        $schedule = [];
        $balance  = $principal;

        for ($i = 1; $i <= $months; $i++) {
            $interestPart  = round($balance * $monthlyRate, 2);
            $principalPart = round($emi - $interestPart, 2);
            $balance       = round($balance - $principalPart, 2);

            if ($i === $months && abs($balance) < 1) $balance = 0; // rounding fix

            $schedule[] = [
                'month'     => $i,
                'emi'       => $emi,
                'principal' => $principalPart,
                'interest'  => $interestPart,
                'balance'   => max(0, $balance),
            ];
        }
        return $schedule;
    }
}

/**
 * Savings Interest — बचत खाताको ब्याज।
 * Cooperative मा प्रायः quarterly compound वा simple interest।
 *
 * @param float  $balance         बचत रकम
 * @param float  $ratePercent     वार्षिक ब्याजदर (%)
 * @param string $fromDate        सुरु मिति (Y-m-d)
 * @param string $toDate          अन्त मिति (Y-m-d)
 * @param string $compoundMethod  'simple' | 'quarterly' | 'monthly'
 */
if (!function_exists('calcSavingsInterest')) {
    function calcSavingsInterest(
        float $balance,
        float $ratePercent,
        string $fromDate,
        string $toDate,
        string $compoundMethod = 'quarterly'
    ): array {
        $days  = daysBetween($fromDate, $toDate);
        $years = $days / 365;

        if ($compoundMethod === 'simple') {
            return calcSimpleInterest($balance, $ratePercent, $days);
        }

        $n = match($compoundMethod) {
            'monthly'   => 12,
            'half-yearly' => 2,
            'yearly'    => 1,
            default     => 4, // quarterly
        };

        return calcCompoundInterest($balance, $ratePercent, $years, $n);
    }
}

/**
 * Penalty Interest — जरिवाना ब्याज।
 * EMI time मा नतिरेमा penalty calculation।
 */
if (!function_exists('calcPenaltyInterest')) {
    function calcPenaltyInterest(float $overdueAmount, float $penaltyRatePercent, int $overdueDays): array {
        return calcSimpleInterest($overdueAmount, $penaltyRatePercent, $overdueDays);
    }
}

/**
 * Loan Eligibility — ऋण पाउन योग्य रकम।
 * Based on income/collateral and max EMI ratio।
 *
 * @param float $monthlyIncome    मासिक आय
 * @param float $maxEmiRatio      आयको कति % EMI हुन सक्छ (0.0-1.0, default 0.4 = 40%)
 * @param float $ratePercent      वार्षिक ब्याजदर (%)
 * @param int   $months           ऋण अवधि (महिना)
 */
if (!function_exists('calcLoanEligibility')) {
    function calcLoanEligibility(float $monthlyIncome, float $ratePercent, int $months, float $maxEmiRatio = 0.4): array {
        $maxEmi      = $monthlyIncome * $maxEmiRatio;
        $monthlyRate = ($ratePercent / 100) / 12;

        if ($monthlyRate == 0) {
            $eligibleAmount = $maxEmi * $months;
        } else {
            $eligibleAmount = $maxEmi * (pow(1 + $monthlyRate, $months) - 1)
                            / ($monthlyRate * pow(1 + $monthlyRate, $months));
        }

        return [
            'monthly_income'   => round($monthlyIncome, 2),
            'max_emi'          => round($maxEmi, 2),
            'emi_ratio'        => $maxEmiRatio,
            'eligible_amount'  => round($eligibleAmount, 2),
            'rate'             => $ratePercent,
            'months'           => $months,
        ];
    }
}

/**
 * FD/RD Maturity — Fixed/Recurring Deposit maturity calculation।
 */
if (!function_exists('calcFDMaturity')) {
    function calcFDMaturity(float $principal, float $ratePercent, int $months): array {
        return calcCompoundInterest($principal, $ratePercent, $months / 12, 4); // quarterly
    }
}


// ═══════════════════════════════════════════════════════════════
// SECTION 5: STRING UTILITIES
// ═══════════════════════════════════════════════════════════════

/**
 * Text truncate — लामो text छोट्याउने।
 */
if (!function_exists('truncateText')) {
    function truncateText(string $text, int $length = 100, string $suffix = '...'): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $text = str_replace(["\u{FFFD}", '�'], '', $text);
        if ($text === '') return '';
        $length = max(1, $length);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $length) return $text;
            return rtrim(mb_substr($text, 0, $length, 'UTF-8')) . $suffix;
        }
        if (strlen($text) <= $length) return $text;
        $cut = substr($text, 0, $length);
        if (preg_match('/^([\x00-\x7F]|[\xC2-\xF4][\x80-\xBF]*)*/', $cut, $m)) {
            $cut = $m[0];
        }
        return rtrim($cut) . $suffix;
    }
}

/**
 * Slug generate गर्ने।
 */
if (!function_exists('generateSlug')) {
    function generateSlug(mixed $text): string {
        if ($text === null) return '';
        $text = preg_replace('/[^a-zA-Z0-9\s\-_]/u', '', (string)$text) ?? '';
        return strtolower(trim(preg_replace('/[\s\-]+/', '-', $text) ?? '', '-'));
    }
}

/**
 * String मा हरेक word को पहिलो letter capital — Nepali names को लागि।
 */
if (!function_exists('titleCase')) {
    function titleCase(string $str): string {
        return function_exists('mb_convert_case')
            ? mb_convert_case($str, MB_CASE_TITLE, 'UTF-8')
            : ucwords(strtolower($str));
    }
}

/**
 * Membership number generate गर्ने।
 * Example: ACB-2081-00123
 */
if (!function_exists('generateMemberNo')) {
    function generateMemberNo(int $sequence, string $prefix = 'ACB', string $bsYear = ''): string {
        if (!$bsYear) {
            $bsYear = function_exists('todayBS')
                ? substr(todayBS(), 0, 4)
                : date('Y');
        }
        return strtoupper($prefix) . '-' . $bsYear . '-' . str_pad((string)$sequence, 5, '0', STR_PAD_LEFT);
    }
}

/**
 * Phone number mask — display को लागि।
 * 9801234567 → 98****4567
 */
if (!function_exists('maskPhone')) {
    function maskPhone(string $phone): string {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean) < 7) return str_repeat('*', strlen($clean));
        return substr($clean, 0, 2) . str_repeat('*', strlen($clean) - 6) . substr($clean, -4);
    }
}

/**
 * Email mask — display को लागि।
 * test@gmail.com → t***@gmail.com
 */
if (!function_exists('maskEmail')) {
    function maskEmail(string $email): string {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return '***';
        $name = $parts[0];
        $domain = $parts[1];
        $visible = substr($name, 0, 1);
        return $visible . str_repeat('*', max(2, strlen($name) - 1)) . '@' . $domain;
    }
}

/**
 * HTML बाट plain text।
 */
if (!function_exists('stripHtmlSafe')) {
    function stripHtmlSafe(string $html, int $maxLen = 0): string {
        $text = strip_tags(html_entity_decode($html, ENT_QUOTES, 'UTF-8'));
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        $text = trim($text);
        return ($maxLen > 0) ? truncateText($text, $maxLen) : $text;
    }
}


// ═══════════════════════════════════════════════════════════════
// SECTION 6: FILE & UPLOAD HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * File size human-readable format।
 */
if (!function_exists('formatFileSize')) {
    function formatFileSize(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}

/**
 * File extension check — upload validation।
 */
if (!function_exists('isAllowedFileType')) {
    function isAllowedFileType(string $filename, array $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'webp']): bool {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $allowed, true);
    }
}

/**
 * Safe filename generate — special chars हटाउने।
 */
if (!function_exists('sanitizeFilename')) {
    function sanitizeFilename(string $filename): string {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $ext  = pathinfo($filename, PATHINFO_EXTENSION);
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name) ?? 'file';
        return $safeName . '_' . time() . '.' . strtolower($ext);
    }
}

/**
 * Profile photo URL — placeholder fallback।
 */
if (!function_exists('memberPhotoUrl')) {
    function memberPhotoUrl(?string $photo, string $gender = 'M'): string {
        if (!empty($photo) && file_exists(ROOT_PATH . $photo)) {
            return defined('SITE_URL') ? SITE_URL . ltrim($photo, '/') : '/' . ltrim($photo, '/');
        }
        // SVG placeholder
        $color = ($gender === 'F') ? 'e8a5b0' : 'a5c8e8';
        $icon  = ($gender === 'F') ? 'F' : 'M';
        return "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80'%3E%3Ccircle cx='40' cy='40' r='40' fill='%23{$color}'/%3E%3Ctext x='40' y='50' text-anchor='middle' fill='%23fff' font-size='28' font-family='sans-serif'%3E{$icon}%3C/text%3E%3C/svg%3E";
    }
}


// ═══════════════════════════════════════════════════════════════
// SECTION 7: SECURITY UTILITIES
// ═══════════════════════════════════════════════════════════════

/**
 * Rate limiting — form spam रोक्न।
 */
if (!function_exists('checkRateLimit')) {
    function checkRateLimit(string $action, int $limit = 5, int $period = 60): bool {
        $key = 'rate_' . $action . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'time' => time()];
        }
        if (time() - $_SESSION[$key]['time'] > $period) {
            $_SESSION[$key] = ['count' => 0, 'time' => time()];
        }
        $_SESSION[$key]['count']++;
        return $_SESSION[$key]['count'] <= $limit;
    }
}

/**
 * IP address — proxy-aware।
 */
if (!function_exists('getClientIP')) {
    function getClientIP(): string {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', (string)$_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

/**
 * OTP generate गर्ने।
 */
if (!function_exists('generateOTP')) {
    function generateOTP(int $length = 6): string {
        return str_pad((string)random_int(0, (int)pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}

/**
 * Random token generate गर्ने (password reset, verification)।
 */
if (!function_exists('generateToken')) {
    function generateToken(int $bytes = 32): string {
        return bin2hex(random_bytes($bytes));
    }
}


// ═══════════════════════════════════════════════════════════════
// SECTION 8: ARRAY & DATA UTILITIES
// ═══════════════════════════════════════════════════════════════

/**
 * Array बाट safe value get गर्ने।
 */
if (!function_exists('arr')) {
    function arr(array $data, string $key, mixed $default = null): mixed {
        return $data[$key] ?? $default;
    }
}

/**
 * Array of rows बाट unique column values।
 */
if (!function_exists('pluck')) {
    function pluck(array $rows, string $column): array {
        return array_values(array_unique(array_column($rows, $column)));
    }
}

/**
 * Array key-value flip — select dropdown को लागि।
 */
if (!function_exists('arrayForSelect')) {
    function arrayForSelect(array $rows, string $valueCol, string $labelCol): array {
        $out = [];
        foreach ($rows as $row) {
            $out[$row[$valueCol]] = $row[$labelCol];
        }
        return $out;
    }
}

/**
 * Paginate array।
 */
if (!function_exists('paginateArray')) {
    function paginateArray(array $data, int $page, int $perPage): array {
        $total  = count($data);
        $offset = ($page - 1) * $perPage;
        return [
            'data'        => array_slice($data, $offset, $perPage),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
            'has_prev'    => $page > 1,
            'has_next'    => ($offset + $perPage) < $total,
        ];
    }
}


// ═══════════════════════════════════════════════════════════════
// SECTION 9: URL & SEO HELPERS
// ═══════════════════════════════════════════════════════════════

/**
 * Current URL (full)।
 */
if (!function_exists('currentUrl')) {
    function currentUrl(): string {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
    }
}

/**
 * Redirect (safe)।
 */
if (!function_exists('redirect')) {
    function redirect(string $url, int $code = 302): never {
        // URL safety check
        if (str_starts_with($url, 'javascript:') || str_starts_with($url, 'data:')) {
            $url = '/';
        }
        header('Location: ' . $url, true, $code);
        exit;
    }
}

/**
 * Query string build — existing params preserve गर्दै।
 */
if (!function_exists('buildQuery')) {
    function buildQuery(array $params, array $base = []): string {
        $merged = array_merge($base ?: $_GET, $params);
        return '?' . http_build_query($merged);
    }
}

/**
 * Pagination URL — page param बदल्ने।
 */
if (!function_exists('pageUrl')) {
    function pageUrl(int $page): string {
        return buildQuery(['page' => $page]);
    }
}

/**
 * Asset URL with cache bust।
 */
if (!function_exists('asset')) {
    function asset(string $path, bool $cacheBust = true): string {
        $base = defined('SITE_URL') ? SITE_URL : '/';
        $url  = $base . ltrim($path, '/');
        if ($cacheBust) {
            $fullPath = defined('ROOT_PATH') ? ROOT_PATH . ltrim($path, '/') : null;
            $mtime = ($fullPath && file_exists($fullPath)) ? filemtime($fullPath) : time();
            $url .= '?v=' . $mtime;
        }
        return $url;
    }
}


// ═══════════════════════════════════════════════════════════════
// SECTION 10: DEBUG UTILITIES (Development only)
// ═══════════════════════════════════════════════════════════════

/**
 * Debug dump — development मा मात्र।
 * Production मा automatically disable हुन्छ।
 */
if (!function_exists('dd')) {
    function dd(mixed ...$vars): void {
        // Only allow dump-and-die in explicit development debug mode
        $isDebug = !empty($_GET['debug']) && defined('APP_ENV') && constant('APP_ENV') === 'development';
        if (!$isDebug) {
            error_log('dd() called in production — remove it! ' . (debug_backtrace()[0]['file'] ?? '?') . ':' . (debug_backtrace()[0]['line'] ?? '?'));
            // Do NOT terminate execution in production; just log and return
            return;
        }
        echo '<pre style="background:#1e293b;color:#94cf95;padding:16px;border-radius:8px;font-size:13px;margin:10px;overflow:auto;">';
        foreach ($vars as $var) {
            var_dump($var);
            echo "\n";
        }
        echo '</pre>';
        // Terminate only in debug mode
        exit;
    }
}

/**
 * Log to file (error_log wrapper)।
 */
if (!function_exists('coopLog')) {
    function coopLog(string $message, mixed $context = null, string $level = 'INFO'): void {
        $log = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($level) . '] ' . $message;
        if ($context !== null) {
            $log .= ' ' . (is_string($context) ? $context : json_encode($context));
        }
        error_log($log);
    }
}


// ═══════════════════════════════════════════════════════════════
// SECTION 11: LUCIDE ICON SYSTEM (AkashDigital-style)
// ═══════════════════════════════════════════════════════════════

/**
 * Font Awesome → Lucide icon name mapping table.
 * Use this to migrate from FA icons to Lucide.
 */
if (!function_exists('fa_to_lucide_map')) {
    function fa_to_lucide_map(): array {
        static $map = [
            // Navigation & UI
            'fa-gauge-high'       => 'layout-dashboard',
            'fa-gauge'            => 'layout-dashboard',
            'fa-bars'             => 'menu',
            'fa-xmark'            => 'x',
            'fa-times'            => 'x',
            'fa-search'           => 'search',
            'fa-bell'             => 'bell',
            'fa-bell-slash'       => 'bell-off',
            'fa-gear'             => 'settings',
            'fa-cog'              => 'settings',
            'fa-gears'            => 'settings-2',
            'fa-home'             => 'home',
            'fa-user'             => 'user',
            'fa-users'            => 'users',
            'fa-users-gear'       => 'users-cog',
            'fa-user-circle'      => 'circle-user',
            'fa-user-plus'        => 'user-plus',
            'fa-user-minus'       => 'user-minus',
            'fa-user-edit'        => 'user-edit',
            'fa-id-card-clip'     => 'id-card',
            'fa-clipboard'        => 'clipboard',
            'fa-clipboard-check'  => 'clipboard-check',
            'fa-clipboard-list'   => 'clipboard-list',
            // Actions
            'fa-plus'             => 'plus',
            'fa-plus-circle'      => 'plus-circle',
            'fa-minus'            => 'minus',
            'fa-minus-circle'     => 'minus-circle',
            'fa-edit'             => 'pencil',
            'fa-pencil'           => 'pencil',
            'fa-trash'            => 'trash-2',
            'fa-trash-alt'        => 'trash',
            'fa-eye'              => 'eye',
            'fa-eye-slash'        => 'eye-off',
            'fa-download'         => 'download',
            'fa-upload'           => 'upload',
            'fa-share'            => 'share',
            'fa-share-alt'        => 'share-2',
            'fa-copy'             => 'copy',
            'fa-link'             => 'link',
            'fa-external-link'   => 'external-link',
            'fa-lock'             => 'lock',
            'fa-unlock'           => 'lock-open',
            'fa-key'              => 'key',
            'fa-check'            => 'check',
            'fa-check-circle'     => 'check-circle',
            'fa-check-square'     => 'check-square',
            'fa-xmark-circle'     => 'x-circle',
            'fa-ban'              => 'ban',
            'fa-filter'           => 'filter',
            'fa-sort'             => 'arrow-up-down',
            'fa-sort-up'          => 'arrow-up',
            'fa-sort-down'        => 'arrow-down',
            'fa-undo'             => 'undo-2',
            'fa-redo'             => 'redo-2',
            'fa-refresh'          => 'refresh-cw',
            'fa-sync'             => 'refresh-cw',
            'fa-save'             => 'save',
            'fa-print'            => 'printer',
            'fa-file'             => 'file',
            'fa-file-alt'         => 'file-text',
            'fa-folder'           => 'folder',
            'fa-folder-open'      => 'folder-open',
            'fa-folder-plus'      => 'folder-plus',
            'fa-archive'          => 'archive',
            // Communications
            'fa-comment'          => 'message-circle',
            'fa-comment-dots'     => 'message-circle',
            'fa-comment-alt'      => 'message-square',
            'fa-comments'         => 'messages-square',
            'fa-paper-plane'      => 'send',
            'fa-envelope'         => 'mail',
            'fa-envelope-open'    => 'mail-open',
            'fa-phone'            => 'phone',
            'fa-phone-alt'        => 'phone-outgoing',
            'fa-mobile'           => 'smartphone',
            'fa-mobile-alt'       => 'smartphone',
            // Content & Media
            'fa-image'            => 'image',
            'fa-images'           => 'images',
            'fa-camera'           => 'camera',
            'fa-video'            => 'video',
            'fa-play'             => 'play',
            'fa-pause'            => 'pause',
            'fa-stop'             => 'square',
            'fa-forward'          => 'fast-forward',
            'fa-backward'         => 'rewind',
            'fa-microphone'       => 'mic',
            'fa-microphone-alt'   => 'mic',
            'fa-volume-up'        => 'volume-2',
            'fa-volume-mute'      => 'volume-x',
            // Business & Finance
            'fa-money-bill'       => 'indian-rupee',
            'fa-indian-rupee'     => 'indian-rupee',
            'fa-credit-card'      => 'credit-card',
            'fa-wallet'           => 'wallet',
            'fa-coins'            => 'coins',
            'fa-chart-line'       => 'trending-up',
            'fa-chart-bar'        => 'bar-chart-3',
            'fa-chart-pie'        => 'pie-chart',
            'fa-chart-area'       => 'area-chart',
            'fa-calendar'        => 'calendar',
            'fa-calendar-alt'    => 'calendar',
            'fa-calendar-check'  => 'calendar-check',
            'fa-calendar-plus'   => 'calendar-plus',
            'fa-calendar-minus'  => 'calendar-minus',
            'fa-clock'            => 'clock',
            'fa-history'          => 'history',
            'fa-stopwatch'        => 'timer',
            // People & Society
            'fa-bullhorn'         => 'megaphone',
            'fa-graduation-cap'   => 'graduation-cap',
            'fa-landmark'         => 'landmark',
            'fa-building'         => 'building',
            'fa-building-columns' => 'landmark',
            'fa-hotel'           => 'building-2',
            'fa-hospital'         => 'plus-square',
            'fa-school'           => 'school',
            'fa-church'           => 'church',
            // Status & Info
            'fa-info'             => 'info',
            'fa-info-circle'      => 'info',
            'fa-question'         => 'help-circle',
            'fa-question-circle'  => 'help-circle',
            'fa-circle-question'  => 'help-circle',
            'fa-exclamation'      => 'alert-circle',
            'fa-exclamation-circle' => 'alert-circle',
            'fa-warning'          => 'alert-triangle',
            'fa-shield'          => 'shield',
            'fa-shield-alt'       => 'shield-check',
            'fa-shield-halved'    => 'shield',
            'fa-flag'            => 'flag',
            'fa-flag-checkered'  => 'flag',
            'fa-tag'             => 'tag',
            'fa-tags'            => 'tags',
            'fa-star'            => 'star',
            'fa-star-half'       => 'star-half',
            'fa-heart'           => 'heart',
            'fa-heart-broken'    => 'heart-crack',
            'fa-hand-holding-heart' => 'heart',
            'fa-badge'           => 'award',
            'fa-bookmark'        => 'bookmark',
            // Tech & Web
            'fa-globe'           => 'globe',
            'fa-globe-americas'  => 'globe',
            'fa-laptop'          => 'laptop',
            'fa-desktop'         => 'monitor',
            'fa-mobile-screen'   => 'smartphone',
            'fa-tablet'          => 'tablet',
            'fa-wifi'            => 'wifi',
            'fa-signal'          => 'signal',
            'fa-bluetooth'       => 'bluetooth',
            'fa-wifi-off'        => 'wifi-off',
            'fa-cloud'           => 'cloud',
            'fa-cloud-upload'    => 'cloud-upload',
            'fa-cloud-download'  => 'cloud-download',
            'fa-server'          => 'server',
            'fa-database'        => 'database',
            'fa-hdd'             => 'hard-drive',
            'fa-cogs'            => 'settings-2',
            'fa-tools'           => 'wrench',
            'fa-wrench'          => 'wrench',
            'fa-screwdriver-wrench' => 'wrench',
            'fa-code'            => 'code',
            'fa-code-branch'     => 'git-branch',
            'fa-terminal'        => 'terminal',
            'fa-bug'             => 'bug',
            'fa-virus'           => 'bug',
            'fa-shield-virus'    => 'shield-plus',
            // Files & Docs
            'fa-file-code'       => 'file-code',
            'fa-file-pdf'        => 'file-text',
            'fa-file-word'       => 'file-text',
            'fa-file-excel'      => 'file-spreadsheet',
            'fa-file-csv'        => 'file-spreadsheet',
            'fa-file-powerpoint' => 'file-text',
            'fa-file-image'      => 'image',
            'fa-file-video'      => 'video',
            'fa-file-audio'      => 'music',
            'fa-file-archive'    => 'archive',
            'fa-file-zip'        => 'archive',
            'fa-file-export'     => 'download',
            'fa-file-import'     => 'upload',
            'fa-file-arrow-down' => 'download',
            'fa-file-arrow-up'   => 'upload',
            'fa-file-plus'       => 'file-plus',
            'fa-file-minus'      => 'file-minus',
            // Misc
            'fa-arrow-up'        => 'arrow-up',
            'fa-arrow-down'      => 'arrow-down',
            'fa-arrow-left'      => 'arrow-left',
            'fa-arrow-right'     => 'arrow-right',
            'fa-arrows-alt'      => 'move',
            'fa-expand'          => 'maximize',
            'fa-compress'        => 'minimize',
            'fa-external-link-alt' => 'external-link',
            'fa-reply'           => 'reply',
            'fa-reply-all'       => 'forward',
            'fa-quote-left'      => 'quote',
            'fa-quote-right'     => 'quote',
            'fa-thumbtack'       => 'pin',
            'fa-thumb-war'       => 'thumbs-up',
            'fa-thumbs-down'     => 'thumbs-down',
            'fa-circle-notch'    => 'loader-2',
            'fa-spinner'        => 'loader-2',
            'fa-asterisk'       => 'plus',
            'fa-hr'              => 'minus',
            'fa-palette'        => 'palette',
            'fa-paint-brush'    => 'paintbrush',
            'fa-fill'           => 'droplet',
            'fa-language'       => 'languages',
            'fa-translate'      => 'languages',
            'fa-trophy'         => 'trophy',
            'fa-award'          => 'award',
            'fa-medal'          => 'medal',
            'fa-certificate'    => 'badge',
            'fa-gift'           => 'gift',
            'fa-hand-holding-dollar' => 'hand-coins',
            'fa-money-check'    => 'file-check',
            'fa-receipt'        => 'receipt',
            'fa-qrcode'         => 'qr-code',
            'fa-barcode'        => 'barcode',
            'fa-id-card'        => 'id-card',
            'fa-id-badge'       => 'badge-check',
            'fa-passport'       => 'plane',
            'fa-vote-yea'       => 'check-circle',
            'fa-person-booth'   => 'users',
            'fa-ribbon'        => 'ribbon',
            'fa-handshake'     => 'handshake',
            'fa-ring'          => 'circle-dot',
            'fa-calendar-day'  => 'sunrise',
            'fa-calendar-week' => 'calendar-range',
            'fa-magnifying-glass' => 'search',
            'fa-magnifier'      => 'search',
            'fa-caret-down'     => 'chevron-down',
            'fa-caret-up'       => 'chevron-up',
            'fa-caret-left'    => 'chevron-left',
            'fa-caret-right'   => 'chevron-right',
            'fa-chevron-down'  => 'chevron-down',
            'fa-chevron-up'    => 'chevron-up',
            'fa-chevron-left'  => 'chevron-left',
            'fa-chevron-right' => 'chevron-right',
            'fa-circle'        => 'circle',
            'fa-circle-dot'    => 'circle-dot',
            'fa-square'        => 'square',
            'fa-square-check'  => 'check-square',
            'fa-toggle-on'     => 'toggle-right',
            'fa-toggle-off'    => 'toggle-left',
            'fa-plug'          => 'plug',
            'fa-power-off'      => 'power-off',
            'fa-sign-out'      => 'log-out',
            'fa-sign-in'       => 'log-in',
            'fa-log-out'       => 'log-out',
            'fa-log-in'        => 'log-in',
            'fa-keyboard'      => 'keyboard',
            'fa-type'          => 'type',
            'fa-feather'       => 'feather',
            'fa-edit-alt'      => 'pen-line',
            'fa-file-signature' => 'file-signature',
            'fa-crown'         => 'crown',
            'fa-handshake-alt' => 'handshake',
            'fa-gavel'         => 'gavel',
            'fa-balance-scale' => 'scale',
            'fa-scale-balanced' => 'scale',
            'fa-coffee'        => 'coffee',
            'fa-utensils'      => 'utensils',
            'fa-utensil-spoon' => 'spoon',
            'fa-concierge-bell' => 'bell-concierge',
            'fa-taxi'          => 'car',
            'fa-car'           => 'car',
            'fa-bus'           => 'bus',
            'fa-bicycle'       => 'bike',
            'fa-plane'         => 'plane',
            'fa-ship'          => 'ship',
            'fa-motorcycle'    => 'bike',
            'fa-truck'         => 'truck',
            'fa-train'         => 'train',
            'fa-subway'       => 'train-front',
            'fa-map'          => 'map',
            'fa-map-marker'   => 'map-pin',
            'fa-map-pin'      => 'map-pin',
            'fa-location-dot' => 'map-pin',
            'fa-compass'      => 'compass',
            'fa-directions'   => 'navigation',
            'fa-navigation'   => 'navigation',
            'fa-route'        => 'route',
            'fa-bullseye'     => 'target',
            'fa-crosshairs'   => 'crosshair',
            'fa-anchor'       => 'anchor',
            'fa-sailboat'     => 'sailboat',
            'fa-mountain'     => 'mountain',
            'fa-tree'         => 'tree-deciduous',
            'fa-leaf'         => 'leaf',
            'fa-paw'          => 'paw-print',
            'fa-fish'         => 'fish',
            'fa-feather-alt'  => 'feather',
            'fa-bird'         => 'bird',
            'fa-worm'         => 'bug',
            'fa-spider'       => 'spider',
            'fa-cat'          => 'cat',
            'fa-dog'          => 'dog',
            'fa-horse'        => 'horse',
            'fa-shrimp'       => 'fish',
            'fa-cow'          => 'cow',
            'fa-kiwi-bird'    => 'bird',
            'fa-otter'        => 'cat',
            'fa-dove'         => 'bird',
            'fa-wind'         => 'wind',
            'fa-cloud-rain'   => 'cloud-rain',
            'fa-cloud-sun'   => 'sun-cloud',
            'fa-sun'          => 'sun',
            'fa-moon'         => 'moon',
            'fa-cloud-moon'  => 'cloud-moon',
            'fa-snowflake'   => 'snowflake',
            'fa-umbrella'    => 'umbrella',
            'fa-thermometer'  => 'thermometer',
            'fa-droplet'     => 'droplet',
            'fa-tint'         => 'droplet',
            'fa-fire'         => 'flame',
            'fa-torch'        => 'flame',
            'fa-lightbulb'    => 'lightbulb',
            'fa-bolt'         => 'zap',
            'fa-bolt-lightning' => 'zap',
            'fa-rocket'       => 'rocket',
            'fa-satellite'    => 'satellite',
            'fa-satellite-dish' => 'satellite-dish',
            'fa-satellite-uplink' => 'uplink',
            'fa-cash'         => 'indian-rupee',
            'fa-bitcoin'      => 'bitcoin',
            'faethereum'      => 'ethereum',
            'fa-calculator'   => 'calculator',
            'fa-percent'      => 'percent',
            'fa-discount'     => 'badge-percent',
            'fa-chart-line-down' => 'trending-down',
            'fa-vcard'        => 'contact',
            'fa-address-card' => 'contact-2',
            'fa-contact-card' => 'contact-2',
            'fa-user-tag'     => 'user-check',
            'fa-tags-alt'     => 'tags',
            'fa-ticket'       => 'ticket',
            'fa-ticket-alt'   => 'ticket',
            'fa-sticky-note'  => 'sticky-note',
            'fa-note-sticky'  => 'sticky-note',
            'fa-tasks'        => 'list-checks',
            'fa-list-check'  => 'list-checks',
            'fa-todo'         => 'list-todo',
            'fa-list'         => 'list',
            'fa-list-ul'      => 'list',
            'fa-list-ol'      => 'list-ordered',
            'fa-align-left'   => 'align-left',
            'fa-align-center' => 'align-center',
            'fa-align-right'  => 'align-right',
            'fa-align-justify' => 'align-justify',
            'fa-text-height'  => 'text',
            'fa-text-width'   => 'text-cursor-input',
            'fa-bold'         => 'bold',
            'fa-italic'       => 'italic',
            'fa-underline'    => 'underline',
            'fa-strikethrough' => 'strikethrough',
            'fa-eraser'       => 'eraser',
            'fa-highlighter'  => 'highlighter',
            'fa-pen-fine'    => 'pen-line',
            'fa-pen-nib'     => 'pen-nib',
            'fa-pen'          => 'pen',
            'fa-pen-square'  => 'pen-line',
            'fa-vector-square' => 'square',
            'fa-crop'         => 'crop',
            'fa-ruler'        => 'ruler',
            'fa-ruler-horizontal' => 'ruler',
            'fa-ruler-vertical' => 'ruler',
            'fa-rupee-sign'  => 'indian-rupee',
            'fa-indian-rupee-sign' => 'indian-rupee',
            'fa-face-smile'  => 'smile',
            'fa-face-frown'  => 'frown',
            'fa-face-meh'    => 'meh',
            'fa-face-angry'  => 'angry',
            'fa-face-laugh'  => 'smile',
            'fa-face-laugh-beam' => 'smile-beam',
            'fa-face-kiss'   => 'heart',
            'fa-face-kiss-beam' => 'heart',
            'fa-face-grin'   => 'grin',
            'fa-face-grin-stars' => 'stars',
            'fa-face-surprise' => 'surprise',
            'fa-face-confused' => 'circle-dot',
            'fa-circle-arrow-up' => 'arrow-up-circle',
            'fa-circle-arrow-down' => 'arrow-down-circle',
            'fa-circle-arrow-left' => 'arrow-left-circle',
            'fa-circle-arrow-right' => 'arrow-right-circle',
            'fa-arrows-spin' => 'refresh-cw',
            'fa-timeline'    => 'git-commit-horizontal',
            'fa-project-diagram' => 'git-branch',
            'fa-sitemap'      => 'sitemap',
            'fa-people-arrows' => 'users-round',
            'fa-person-shelter' => 'home',
            'fa-restroom'    => 'bath',
            'fa-baby'        => 'baby',
            'fa-baby-carriage' => 'stroller',
            'fa-person-cane' => 'accessibility',
            'fa-accessible-icon' => 'accessibility',
            'fa-universal-access' => 'accessibility',
            'fa-eye-dropper' => 'pipette',
            'fa-fill-drip'   => 'droplet',
            'fa-paint-roller' => 'paintbrush',
            'fa-color-palette' => 'palette',
            'fa-pen-ruler'   => 'ruler',
            'fa-stamp'       => 'stamp',
            'fa-magnet'      => 'magnet',
            'fa-target'      => 'target',
            'fa-funnel-dollar' => 'filter',
            'fa-broom'       => ' broom',
            'fa-spray-can'   => 'spray-can',
            'fa-sponge'      => 'circle-dot',
            'fa-bucket'      => 'bucket',
            'fa-hands-bubbles' => 'hand',
            'fa-hands-wash'  => 'hand',
            'fa-hand-holding' => 'hand',
            'fa-hand-fist'   => 'hand',
            'fa-fist-raised' => 'hand',
            'fa-handshake-simple' => 'handshake',
            'fa-wine-bottle' => 'wine',
            'fa-cocktail'    => 'wine',
            'fa-beer'        => 'beer',
            'fa-mug-hot'     => 'coffee',
            'fa-mug-soda'    => 'cup-soda',
            'fa-glass-water' => 'glass-water',
            'fa-glass-whiskey' => 'wine',
            'fa-blender'     => 'blender',
            'fa-pepper-hot'  => 'flame',
            'fa-bowl-food'   => 'utensils',
            'fa-bowl-rice'   => 'utensils',
            'fa-candy-cane'  => 'candy',
            'fa-cookie'      => 'cookie',
            'fa-cookie-bite' => 'cookie',
            'fa-cake-candles' => 'cake',
            'fa-birthday-cake' => 'cake',
            'fa-pie-chart'   => 'pie-chart',
            'fa-mitten'      => 'hand',
            'fa-scroll'      => 'scroll',
            'fa-scroll-torah' => 'scroll',
            'fa-hamsa'       => 'hand',
            'fa-meno'        => 'music',
            'fa-music'       => 'music',
            'fa-music-note'  => 'music',
            'fa-music-slash' => 'volume-x',
            'fa-headphones'  => 'headphones',
            'fa-headset'     => 'headphones',
            'fa-record-vinyl' => 'circle-dot',
            'fa-waveform'   => 'activity',
            'fa-wave-square' => 'audio-lines',
            'fa-radiation'  => 'radio',
            'fa-draw-polygon' => 'hexagon',
            'fa-drafting-compass' => 'compass',
            'fa-file-contract' => 'file-signature',
            'fa-low-vision' => 'eye-off',
            'fa-sign-language' => 'hand',
            'fa-hand-boy'    => 'hand',
            'fa-hand-girl'   => 'hand',
            'fa-hands-asl-interpreting' => 'hand',
            'fa-sensor'      => 'activity',
            'fa-thermometer-half' => 'thermometer',
            'fa-thermometer-quarter' => 'thermometer',
            'fa-thermometer-full' => 'thermometer',
            'fa-thermometer-empty' => 'thermometer',
            'fa-shower'      => 'shower',
            'fa-bath'        => 'bath',
            'fa-shower-down' => 'cloud-rain',
            'fa-box'         => 'box',
            'fa-boxes-stacked' => 'boxes',
            'fa-box-tissue'  => 'box',
            'fa-archive-box' => 'archive',
            'fa-dolly'       => 'package',
            'fa-forklift'    => 'truck',
            'fa-pallet'      => 'package',
            'fa-shipping-fast' => 'truck',
            'fa-shipping-timed' => 'truck',
            'fa-tractor'     => 'tractor',
            'fa-trailer'     => 'bus',
            'fa-truck-monster' => 'truck',
            'fa-truck-moving' => 'truck',
            'fa-truck-pickup' => 'car',
            'fa-warehouse'   => 'warehouse',
            'fa-tent'        => 'tent',
            'fa-tents'       => 'tent',
            'fa-house-chimney' => 'home',
            'fa-house-crack' => 'home',
            'fa-house-flood-water' => 'droplet',
            'fa-house-flood' => 'cloud-rain',
            'fa-igloo'       => 'home',
            'fa-journal-whills' => 'book',
            'fa-ladder-water' => 'waves',
            'fa-mosque'      => 'building',
            'fa-person-drowning' => 'droplet',
            'fa-person-through-window' => 'door-open',
            'fa-pump-soap'   => 'spray-can',
            'fa-sink'        => 'droplet',
            'fa-snowman'     => 'snowflake',
            'fa-soap'        => 'spray-can',
            'fa-solar-panel' => 'sun',
            'fa-spa'         => 'flower-2',
            'fa-suitcase'    => 'briefcase',
            'fa-suitcase-rolling' => 'briefcase',
            'fa-swimming-pool' => 'waves',
            'fa-tarp'        => 'package',
            'fa-tarp-droplet' => 'droplet',
            'fa-toilet'      => 'toilet',
            'fa-toilet-paper' => 'scroll',
            'fa-toilets-portable' => 'toilet',
            'fa-torah'       => 'book',
            'fa-trowel'      => 'shovel',
            'fa-truck-field' => 'truck',
            'fa-truck-field-un' => 'truck',
            'fa-truck-loading' => 'truck',
            'fa-van-shuttle' => 'bus',
            'fa-vest'        => 'shirt',
            'fa-vest-patches' => 'shirt',
            'fa-volleyball'  => 'volleyball',
            'fa-weight-scale' => 'scale',
            'fa-wheelchair'  => 'accessibility',
            'fa-user-astronaut' => 'user',
            'fa-user-graduate' => 'graduation-cap',
            'fa-user-injured' => 'user',
            'fa-user-secret'  => 'user',
            'fa-user-tie'     => 'briefcase',
            'fa-user-visually-hidden' => 'eye-off',
            'fa-vr-cardboard' => 'glasses',
            'fa-earth-americas' => 'globe',
            'fa-earth-africa' => 'globe',
            'fa-earth-asia'   => 'globe',
            'fa-earth-europe' => 'globe',
            'fa-handshake-simple-slash' => 'handshake',
            'fa-inbox'        => 'inbox',
            'fa-microchip'    => 'cpu',
            'fa-skull'        => 'skull',
            'fa-skull-crossbones' => 'skull',
            'fa-wine-glass'   => 'wine',
        ];
        return $map;
    }
}

/**
 * Convert Font Awesome icon class name to Lucide icon name.
 * Usage: fa_to_lucide('fa-gauge-high') → 'layout-dashboard'
 */
if (!function_exists('fa_to_lucide')) {
    function fa_to_lucide(string $faClass): string {
        $map = fa_to_lucide_map();
        $clean = trim($faClass);
        // Handle 'fa-gauge-high' or 'fas fa-gauge-high' or 'far fa-gauge-high'
        $name = preg_replace('/^(fa[bsr]?\s+|fa-)/i', '', $clean) ?: $clean;
        $fallback = preg_replace('/^fa-/', '', $name) ?: $name;
        return $map[$name] ?? $map[$clean] ?? $fallback;
    }
}

/**
 * Render a Lucide icon as HTML.
 *
 * Usage:
 *   <?= icon('layout-dashboard', 20) ?>
 *   <?= icon('settings', 16, 'color:#ccc') ?>
 *   <?= icon('user', 18) ?>
 *
 * Auto-converts FA class names:
 *   <?= icon('fa-gauge-high') ?>  → becomes layout-dashboard
 */
if (!function_exists('icon')) {
    function icon(string $name, int $size = 16, string $style = ''): string {
        // Auto-convert Font Awesome names
        $lucideName = str_starts_with($name, 'fa-') ? fa_to_lucide($name) : $name;

        $s = "width:{$size}px;height:{$size}px;display:inline-block;vertical-align:middle;flex-shrink:0;";
        if ($style) {
            $s .= $style;
        }
        return '<i data-lucide="' . htmlspecialchars($lucideName, ENT_QUOTES, 'UTF-8') . '" style="' . $s . '" class="lucide-icon" aria-hidden="true"></i>';
    }
}

/**
 * Output a Lucide icon directly (for use in HTML).
 * Alias of echo icon().
 */
if (!function_exists('icon_echo')) {
    function icon_echo(string $name, int $size = 16, string $style = ''): void {
        echo icon($name, $size, $style);
    }
}

/**
 * Render Lucide script tag for initializing icons.
 * Call once in <head> after the lucide.min.js is loaded.
 */
if (!function_exists('lucide_init_script')) {
    function lucide_init_script(): string {
        return '<script>
document.addEventListener("DOMContentLoaded", function() {
    if (typeof lucide !== "undefined") {
        lucide.createIcons();
    }
});
// Also run immediately in case DOM is already ready
if (document.readyState !== "loading" && typeof lucide !== "undefined") {
    lucide.createIcons();
}
</script>';
    }
}

/**
 * Output the Lucide script tag.
 */
if (!function_exists('lucide_script')) {
    function lucide_script(): void {
        echo lucide_init_script();
    }
}

/**
 * Lucide JS asset URL with cache busting.
 */
if (!function_exists('lucide_asset')) {
    function lucide_asset(): string {
        $path = defined('ROOT_PATH') ? ROOT_PATH : dirname(dirname(__FILE__)) . '/';
        $fullPath = $path . 'assets/vendor/lucide.min.js';
        $mtime = @filemtime($fullPath) ?: time();
        $base = defined('SITE_URL') ? SITE_URL : '/';
        return $base . 'assets/vendor/lucide.min.js?v=' . $mtime;
    }
}
