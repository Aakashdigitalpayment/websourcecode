<?php
/**
 * BS ↔ AD conversion — same algorithm as assets/js/nepali.datepicker.min.js (NepaliFunctions Calendar).
 * Ref: BS 2000-09-17 ↔ AD 1944-01-01; month lengths from nepali_bs_month_days_map().
 */
declare(strict_types=1);

require_once __DIR__ . '/nepali-bs-month-days.generated.php';

/** @return array{0:int,1:int,2:int}|null Y-m-d components or null if invalid string */
function nepali_parse_ad_ymd(string $ymd): ?array {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
        return null;
    }
    $y = (int) $m[1];
    $mo = (int) $m[2];
    $d = (int) $m[3];
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
        return null;
    }
    $chk = checkdate($mo, $d, $y);
    return $chk ? [$y, $mo, $d] : null;
}

/** @return array{0:int,1:int,2:int}|null BS y-m-d (1-based month) or null */
function nepali_parse_bs_ymd(string $ymd): ?array {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
        return null;
    }
    $y = (int) $m[1];
    $mo = (int) $m[2];
    $d = (int) $m[3];
    if ($y < 1970 || $y > 2100 || $mo < 1 || $mo > 12 || $d < 1 || $d > 32) {
        return null;
    }
    return [$y, $mo, $d];
}

function nepali_bs_month_lens(int $year): ?array {
    $map = nepali_bs_month_days_map();
    return $map[$year] ?? null;
}

function nepali_bs_sum_year_days(int $year): int {
    $lens = nepali_bs_month_lens($year);
    return $lens === null ? 0 : array_sum($lens);
}

/** AD calendar-day difference (same as JS Date.UTC /864e5). */
function nepali_count_ad_days(int $y1, int $m1, int $d1, int $y2, int $m2, int $d2): int {
    $t1 = gmmktime(0, 0, 0, $m1, $d1, $y1);
    $t2 = gmmktime(0, 0, 0, $m2, $d2, $y2);
    return (int) abs(round(($t2 - $t1) / 86400));
}

/**
 * Count BS days between ref t and date n (same formula as JS `o`).
 * @param array{0:int,1:int,2:int} $tBs ref or start BS
 * @param array{0:int,1:int,2:int} $nBs end BS
 */
function nepali_count_bs_days(array $tBs, array $nBs): int {
    $map = nepali_bs_month_days_map();
    [$ty, $tm, $td] = $tBs;
    [$ny, $nm, $nd] = $nBs;
    $a = 0;
    for ($i = $ty; $i <= $ny; $i++) {
        $a += nepali_bs_sum_year_days($i);
    }
    for ($i = 0; $i < $tm; $i++) {
        $a -= $map[$ty][$i];
    }
    $a += $map[$ty][11];
    for ($i = $nm - 1; $i < 12; $i++) {
        $a -= $map[$ny][$i];
    }
    $a -= $td + 1;
    $a += $nd - 1;
    return $a;
}

/**
 * Add days to AD date (same as JS addAdDays + Date.UTC-based countAdDays).
 * @param array{0:int,1:int,2:int} $ad
 * @return array{0:int,1:int,2:int}|null
 */
function nepali_add_ad_days(array $ad, int $days): ?array {
    [$y, $m, $d] = $ad;
    $t = gmmktime(0, 0, 0, $m, $d, $y);
    if ($t === false) {
        return null;
    }
    $t2 = $t + $days * 86400;
    return [(int) gmdate('Y', $t2), (int) gmdate('n', $t2), (int) gmdate('j', $t2)];
}

/**
 * Add days to BS date (JS addBsDays).
 * @param array{0:int,1:int,2:int} $bs
 * @return array{0:int,1:int,2:int}|null
 */
function nepali_add_bs_days(array $bs, int $days): ?array {
    $map = nepali_bs_month_days_map();
    $y = $bs[0];
    $m = $bs[1];
    $d = $bs[2] + $days;
    while (true) {
        $lens = $map[$y] ?? null;
        if ($lens === null) {
            return null;
        }
        $dim = $lens[$m - 1];
        if ($d <= $dim) {
            return [$y, $m, $d];
        }
        $d -= $dim;
        $m++;
        if ($m > 12) {
            $m = 1;
            $y++;
        }
    }
}

/** @return array{0:int,1:int,2:int}|null */
function nepali_bs_to_ad_components(array $bs): ?array {
    $refBs = [2000, 9, 17];
    $refAd = [1944, 1, 1];
    $n = nepali_count_bs_days($refBs, $bs);
    return nepali_add_ad_days($refAd, $n);
}

/** @return array{0:int,1:int,2:int}|null */
function nepali_ad_to_bs_components(array $ad): ?array {
    $refBs = [2000, 9, 17];
    $refAd = [1944, 1, 1];
    $n = nepali_count_ad_days($refAd[0], $refAd[1], $refAd[2], $ad[0], $ad[1], $ad[2]);
    return nepali_add_bs_days($refBs, $n);
}

function nepali_bs_to_ad_string(string $bsYmd): ?string {
    $bs = nepali_parse_bs_ymd($bsYmd);
    if ($bs === null || !nepali_bs_date_valid($bs)) {
        return null;
    }
    $ad = nepali_bs_to_ad_components($bs);
    if ($ad === null) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $ad[0], $ad[1], $ad[2]);
}

function nepali_ad_to_bs_string(string $adYmd): ?string {
    $ad = nepali_parse_ad_ymd($adYmd);
    if ($ad === null) {
        return null;
    }
    $bs = nepali_ad_to_bs_components($ad);
    if ($bs === null) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $bs[0], $bs[1], $bs[2]);
}

/** BS date within map and day in range for that month (NepaliFunctions.BS.ValidateDate-style). */
function nepali_bs_date_valid(array $bs): bool {
    [$y, $m, $d] = $bs;
    if ($y < 1970 || $y > 2100 || $m < 1 || $m > 12 || $d < 1) {
        return false;
    }
    $lens = nepali_bs_month_lens($y);
    if ($lens === null) {
        return false;
    }
    $max = $lens[$m - 1];
    return $d <= $max;
}

/** Compare two BS Y-m-d strings: -1 if $a < $b, 0 if equal, 1 if $a > $b. Invalid → null. */
function nepali_bs_ymd_compare(string $a, string $b): ?int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $a) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $b)) {
        return null;
    }
    return $a <=> $b;
}

/** Kathmandu calendar AD date → BS Y-m-d for "today" in site logic. */
function nepali_kathmandu_today_bs(): string {
    $tz = new DateTimeZone('Asia/Kathmandu');
    $now = new DateTimeImmutable('now', $tz);
    $ad = sprintf('%04d-%02d-%02d', (int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));
    $bs = nepali_ad_to_bs_string($ad);
    return $bs ?? '1970-01-01';
}

/** Latin अंक भएको स्ट्रिङ → देवनागरी अंक (मिति Y-m-d मा पनि प्रयोग) */
function nepali_latin_digits_to_devanagari(string $s): string {
    static $map = [
        '0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४',
        '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९',
    ];
    return strtr($s, $map);
}
