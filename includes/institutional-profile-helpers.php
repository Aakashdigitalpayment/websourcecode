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
