<?php
/**
 * Institutional profile — shared helpers (public pages).
 * Safe: read-only queries, no schema changes.
 */

if (!function_exists('coopIpShortAmt')) {
    function coopIpShortAmt(float $v): string
    {
        if ($v >= 1e7) {
            return 'रू. ' . number_format($v / 1e7, 2) . ' करोड';
        }
        if ($v >= 1e5) {
            return 'रू. ' . number_format($v / 1e5, 1) . ' लाख';
        }
        if ($v > 0) {
            return 'रू. ' . number_format($v);
        }
        return '—';
    }
}

if (!function_exists('coopIpMonthLabel')) {
    function coopIpMonthLabel(int $m, bool $en = false): string
    {
        if ($m < 1 || $m > 12) {
            return $en ? 'Annual' : 'वार्षिक';
        }
        $enNames = [
            1 => 'Baisakh', 2 => 'Jestha', 3 => 'Ashadh', 4 => 'Shrawan',
            5 => 'Bhadra', 6 => 'Ashwin', 7 => 'Kartik', 8 => 'Mangsir',
            9 => 'Poush', 10 => 'Magh', 11 => 'Falgun', 12 => 'Chaitra',
        ];
        if ($en) {
            return $enNames[$m] ?? ('Month ' . $m);
        }
        if (function_exists('getNepaliMonthName')) {
            return (string) getNepaliMonthName((string) $m);
        }
        return 'महिना ' . $m;
    }
}

if (!function_exists('coopIpResolveMonth')) {
    function coopIpResolveMonth(array $row): int
    {
        $m = (int) ($row['report_month'] ?? 0);
        if ($m >= 1 && $m <= 12) {
            return $m;
        }
        $bs = trim((string) ($row['report_date_bs'] ?? ''));
        if (preg_match('/^\d{4}-(\d{2})/', $bs, $mm)) {
            return max(0, min(12, (int) $mm[1]));
        }
        return 0;
    }
}

if (!function_exists('coopIpSortKey')) {
    function coopIpSortKey(array $row): int
    {
        $fy = trim((string) ($row['fiscal_year'] ?? ''));
        $m = (int) ($row['_month'] ?? coopIpResolveMonth($row));
        $start = 0;
        if (preg_match('/^(\d{4})/', $fy, $mch)) {
            $start = (int) $mch[1];
        }
        return ($start * 100) + max(0, $m);
    }
}

if (!function_exists('coopIpFetchLatestProfile')) {
    function coopIpFetchLatestProfile(?PDO $db = null): ?array
    {
        static $memo = false;
        static $cached = null;
        if ($memo) {
            return $cached;
        }
        $memo = true;

        if (!$db instanceof PDO) {
            $db = function_exists('getDB') ? getDB() : null;
        }
        if (!$db instanceof PDO) {
            return $cached = null;
        }
        try {
            $exists = function_exists('dbTableExists')
                ? dbTableExists('institutional_profile')
                : (($r = $db->query("SHOW TABLES LIKE 'institutional_profile'")) && $r->rowCount() > 0);
            if (!$exists) {
                return $cached = null;
            }
            $row = $db->query(
                "SELECT * FROM institutional_profile WHERE is_active = 1
                 ORDER BY fiscal_year DESC, report_month DESC, id DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $cached = null;
            }
            $row['_month'] = coopIpResolveMonth($row);
            return $cached = $row;
        } catch (Throwable $e) {
            return $cached = null;
        }
    }
}

if (!function_exists('coopIpBuildChartSeries')) {
    /**
     * @return array{labels: string[], deposit: float[], loan: float[], assets: float[], members: int[], count: int}
     */
    function coopIpBuildChartSeries(array $profiles, int $maxPoints = 12, bool $en = false): array
    {
        $rows = [];
        foreach ($profiles as $p) {
            $m = (int) ($p['_month'] ?? coopIpResolveMonth($p));
            if ($m < 1) {
                continue;
            }
            $p['_month'] = $m;
            $rows[] = $p;
        }
        usort($rows, static function ($a, $b) {
            return coopIpSortKey($a) <=> coopIpSortKey($b);
        });
        if (count($rows) > $maxPoints) {
            $rows = array_slice($rows, -$maxPoints);
        }

        $labels = [];
        $deposit = [];
        $loan = [];
        $assets = [];
        $members = [];

        foreach ($rows as $p) {
            $m = (int) $p['_month'];
            $fy = trim((string) ($p['fiscal_year'] ?? ''));
            $labels[] = ($fy !== '' ? $fy . ' · ' : '') . coopIpMonthLabel($m, $en);
            $deposit[] = (float) ($p['deposit'] ?? 0);
            $loan[] = (float) ($p['loan'] ?? 0);
            $assets[] = (float) ($p['total_assets'] ?? 0);
            $members[] = (int) ($p['total_members'] ?? 0);
        }

        return [
            'labels'  => $labels,
            'deposit' => $deposit,
            'loan'    => $loan,
            'assets'  => $assets,
            'members' => $members,
            'count'   => count($labels),
        ];
    }
}

if (!function_exists('coopIpFormatAmtFull')) {
    /** Full NPR amount for posters / ledgers (Indian grouping, e.g. 22,94,00,700/-). */
    function coopIpFormatAmtFull(float $v, bool $en = false): string
    {
        if ($v <= 0) {
            return '—';
        }
        $digits = (string) (int) round($v);
        if (strlen($digits) <= 3) {
            $grouped = $digits;
        } else {
            $last3 = substr($digits, -3);
            $rest = substr($digits, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $grouped = $rest . ',' . $last3;
        }
        return ($en ? 'Rs. ' : 'रू. ') . $grouped . '/-';
    }
}

if (!function_exists('coopIpProfileUptoAdDate')) {
    /**
     * Resolve AD Y-m-d cutoff for a profile row (report date or end of BS month).
     */
    function coopIpProfileUptoAdDate(array $row): string
    {
        $ad = trim((string) ($row['report_date_ad'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $ad, $m)) {
            return $m[0];
        }
        $bs = trim((string) ($row['report_date_bs'] ?? ''));
        if ($bs !== '' && function_exists('bsToAd')) {
            $converted = trim((string) bsToAd($bs));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $converted, $m2)) {
                return $m2[0];
            }
        }
        /* Approximate: BS year-month → mid/end via convert if FY+month known */
        $fy = trim((string) ($row['fiscal_year'] ?? ''));
        $month = (int) ($row['_month'] ?? coopIpResolveMonth($row));
        if ($month >= 1 && $month <= 12 && preg_match('/^(\d{4})/', $fy, $fyM)) {
            $fyStart = (int) $fyM[1];
            /* FY Shrawan(4)–Ashadh(3): months 4–12 → fyStart, 1–3 → fyStart+1 */
            $bsYear = ($month >= 4) ? $fyStart : ($fyStart + 1);
            $bsGuess = sprintf('%04d-%02d-28', $bsYear, $month);
            if (function_exists('bsToAd')) {
                $converted = trim((string) bsToAd($bsGuess));
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $converted, $m3)) {
                    return $m3[0];
                }
            }
        }
        return date('Y-m-d');
    }
}

if (!function_exists('coopIpWelfareReliefByType')) {
    /**
     * SSOT: welfare_claim_types + member_welfare_claims totals up to an AD date.
     * Sahakari-specific types come from the catalog (member-welfare).
     *
     * @return list<array{slug:string,label:string,count:int,amount:float,color:string}>
     */
    function coopIpWelfareReliefByType(?PDO $db, string $uptoAd, bool $en = false): array
    {
        static $cache = [];
        $key = $uptoAd . '|' . ($en ? '1' : '0');
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        if (!$db instanceof PDO) {
            return $cache[$key] = [];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $uptoAd)) {
            return $cache[$key] = [];
        }

        $types = [];
        try {
            if (is_file(__DIR__ . '/welfare-claim-types.php')) {
                require_once __DIR__ . '/welfare-claim-types.php';
            }
            if (function_exists('welfareClaimTypesMap')) {
                $types = welfareClaimTypesMap($db, true);
            }
        } catch (Throwable $e) {
            $types = [];
        }
        if (!$types) {
            return $cache[$key] = [];
        }

        $claimsOk = false;
        try {
            $claimsOk = function_exists('dbTableExists')
                ? dbTableExists('member_welfare_claims')
                : (($r = $db->query("SHOW TABLES LIKE 'member_welfare_claims'")) && $r->rowCount() > 0);
        } catch (Throwable $e) {
            $claimsOk = false;
        }

        $agg = [];
        if ($claimsOk) {
            try {
                $sql = "SELECT claim_type AS slug,
                               COUNT(*) AS cnt,
                               SUM(CASE
                                     WHEN COALESCE(approved_amount, 0) > 0 THEN approved_amount
                                     ELSE COALESCE(claim_amount, 0)
                                   END) AS amt
                        FROM member_welfare_claims
                        WHERE DATE(created_at) <= ?
                          AND status IN ('approved','paid','completed')
                        GROUP BY claim_type";
                $st = $db->prepare($sql);
                $st->execute([$uptoAd]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $slug = (string) ($row['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $agg[$slug] = [
                        'count' => (int) ($row['cnt'] ?? 0),
                        'amount' => (float) ($row['amt'] ?? 0),
                    ];
                }
            } catch (Throwable $e) {
                /* soft fail — still list types */
            }
        }

        $out = [];
        foreach ($types as $slug => $meta) {
            $label = $en
                ? (string) (($meta['en'] ?? '') ?: ($meta['np'] ?? $slug))
                : (string) (($meta['np'] ?? '') ?: ($meta['en'] ?? $slug));
            $c = (int) ($agg[$slug]['count'] ?? 0);
            $a = (float) ($agg[$slug]['amount'] ?? 0);
            $out[] = [
                'slug' => (string) $slug,
                'label' => $label !== '' ? $label : (string) $slug,
                'count' => $c,
                'amount' => $a,
                'color' => (string) ($meta['color'] ?? '#ff9800'),
            ];
        }

        /* Claims for unknown/custom slugs not in active map */
        foreach ($agg as $slug => $vals) {
            $found = false;
            foreach ($out as $row) {
                if ($row['slug'] === $slug) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                continue;
            }
            if (($vals['count'] ?? 0) <= 0 && ($vals['amount'] ?? 0) <= 0) {
                continue;
            }
            $out[] = [
                'slug' => (string) $slug,
                'label' => (string) $slug,
                'count' => (int) $vals['count'],
                'amount' => (float) $vals['amount'],
                'color' => '#6b7280',
            ];
        }

        return $cache[$key] = $out;
    }
}
