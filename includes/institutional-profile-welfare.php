<?php
/**
 * Institutional profile × member-welfare (राहत) — opening + monthly snapshots.
 *
 * Types SSOT: welfare_claim_types (member-welfare). No new type fields.
 * Storage SSOT: members.dob-style — claims live in portal; opening covers pre-portal;
 * each monthly report can store editable cum_* + month_* snapshots.
 */
declare(strict_types=1);

if (!function_exists('coopIpEnsureWelfareTables')) {
    function coopIpEnsureWelfareTables(?PDO $db = null): void
    {
        if (!$db instanceof PDO) {
            $db = function_exists('getDB') ? getDB() : null;
        }
        if (!$db instanceof PDO) {
            return;
        }
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS institutional_welfare_opening (
                    claim_type VARCHAR(60) NOT NULL,
                    opening_count INT NOT NULL DEFAULT 0,
                    opening_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                    note VARCHAR(255) NOT NULL DEFAULT '',
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (claim_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) { /* ignore */ }
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS institutional_profile_welfare (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    profile_id INT NOT NULL,
                    claim_type VARCHAR(60) NOT NULL,
                    month_count INT NOT NULL DEFAULT 0,
                    month_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                    cum_count INT NOT NULL DEFAULT 0,
                    cum_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_ipw_profile_type (profile_id, claim_type),
                    KEY idx_ipw_profile (profile_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) { /* ignore */ }
    }
}

if (!function_exists('coopIpWelfareTypesForAdmin')) {
    /** @return array<string,array<string,mixed>> */
    function coopIpWelfareTypesForAdmin(?PDO $db): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        try {
            if (is_file(__DIR__ . '/welfare-claim-types.php')) {
                require_once __DIR__ . '/welfare-claim-types.php';
            }
            if (function_exists('welfareClaimTypesMap')) {
                return welfareClaimTypesMap($db, false) ?: [];
            }
        } catch (Throwable $e) {
            return [];
        }
        return [];
    }
}

if (!function_exists('coopIpWelfareOpeningMap')) {
    /**
     * Pre-portal opening totals by claim_type slug.
     * @return array<string,array{count:int,amount:float,note:string}>
     */
    function coopIpWelfareOpeningMap(?PDO $db): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        coopIpEnsureWelfareTables($db);
        $out = [];
        try {
            $st = $db->query('SELECT claim_type, opening_count, opening_amount, note FROM institutional_welfare_opening ORDER BY claim_type ASC LIMIT 200');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $slug = trim((string)($row['claim_type'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $out[$slug] = [
                    'count' => (int)($row['opening_count'] ?? 0),
                    'amount' => (float)($row['opening_amount'] ?? 0),
                    'note' => trim((string)($row['note'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }
}

if (!function_exists('coopIpWelfareSaveOpening')) {
    /**
     * @param array<string,array{count?:int|string,amount?:float|string,note?:string}> $rows
     */
    function coopIpWelfareSaveOpening(PDO $db, array $rows): bool
    {
        coopIpEnsureWelfareTables($db);
        try {
            $db->beginTransaction();
            $db->exec('DELETE FROM institutional_welfare_opening');
            $ins = $db->prepare(
                'INSERT INTO institutional_welfare_opening (claim_type, opening_count, opening_amount, note)
                 VALUES (?,?,?,?)'
            );
            foreach ($rows as $slug => $vals) {
                $slug = trim((string)$slug);
                if ($slug === '' || !preg_match('/^[a-z0-9_\-]{1,60}$/i', $slug)) {
                    continue;
                }
                $cnt = max(0, (int)($vals['count'] ?? 0));
                $amt = max(0.0, (float)($vals['amount'] ?? 0));
                $note = mb_substr(trim((string)($vals['note'] ?? '')), 0, 255);
                if ($cnt === 0 && $amt <= 0.0 && $note === '') {
                    continue;
                }
                $ins->execute([$slug, $cnt, $amt, $note]);
            }
            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[ip-welfare] saveOpening: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('coopIpWelfareClaimsAgg')) {
    /**
     * Aggregate approved/paid/completed claims.
     * Mode: 'upto' => date <= $toAd; 'period' => $fromAd..$toAd inclusive.
     *
     * @return array<string,array{count:int,amount:float}>
     */
    function coopIpWelfareClaimsAgg(?PDO $db, string $mode, string $toAd, string $fromAd = ''): array
    {
        if (!$db instanceof PDO || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toAd)) {
            return [];
        }
        $mode = ($mode === 'period') ? 'period' : 'upto';
        if ($mode === 'period' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromAd)) {
            return [];
        }
        $claimsOk = false;
        try {
            $claimsOk = function_exists('dbTableExists')
                ? dbTableExists('member_welfare_claims')
                : (($r = $db->query("SHOW TABLES LIKE 'member_welfare_claims'")) && $r->rowCount() > 0);
        } catch (Throwable $e) {
            $claimsOk = false;
        }
        if (!$claimsOk) {
            return [];
        }

        $dateExpr = 'DATE(COALESCE(paid_at, reviewed_at, created_at))';
        $sumExpr = 'SUM(CASE WHEN COALESCE(approved_amount, 0) > 0 THEN approved_amount ELSE COALESCE(claim_amount, 0) END)';
        $sqls = $mode === 'period'
            ? [
                "SELECT claim_type AS slug, COUNT(*) AS cnt, {$sumExpr} AS amt
                 FROM member_welfare_claims
                 WHERE {$dateExpr} BETWEEN ? AND ?
                   AND status IN ('approved','paid','completed')
                 GROUP BY claim_type",
                "SELECT claim_type AS slug, COUNT(*) AS cnt, {$sumExpr} AS amt
                 FROM member_welfare_claims
                 WHERE DATE(created_at) BETWEEN ? AND ?
                   AND status IN ('approved','paid','completed')
                 GROUP BY claim_type",
            ]
            : [
                "SELECT claim_type AS slug, COUNT(*) AS cnt, {$sumExpr} AS amt
                 FROM member_welfare_claims
                 WHERE {$dateExpr} <= ?
                   AND status IN ('approved','paid','completed')
                 GROUP BY claim_type",
                "SELECT claim_type AS slug, COUNT(*) AS cnt, {$sumExpr} AS amt
                 FROM member_welfare_claims
                 WHERE DATE(created_at) <= ?
                   AND status IN ('approved','paid','completed')
                 GROUP BY claim_type",
            ];

        foreach ($sqls as $sql) {
            try {
                $st = $db->prepare($sql);
                if ($mode === 'period') {
                    $st->execute([$fromAd, $toAd]);
                } else {
                    $st->execute([$toAd]);
                }
                $agg = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $slug = (string)($row['slug'] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $agg[$slug] = [
                        'count' => (int)($row['cnt'] ?? 0),
                        'amount' => (float)($row['amt'] ?? 0),
                    ];
                }
                return $agg;
            } catch (Throwable $e) {
                /* try next */
            }
        }
        return [];
    }
}

if (!function_exists('coopIpWelfareMergeMaps')) {
    /**
     * @param array<string,array{count?:int,amount?:float}> ...$maps
     * @return array<string,array{count:int,amount:float}>
     */
    function coopIpWelfareMergeMaps(array ...$maps): array
    {
        $out = [];
        foreach ($maps as $map) {
            foreach ($map as $slug => $vals) {
                $slug = (string)$slug;
                if ($slug === '') {
                    continue;
                }
                if (!isset($out[$slug])) {
                    $out[$slug] = ['count' => 0, 'amount' => 0.0];
                }
                $out[$slug]['count'] += (int)($vals['count'] ?? 0);
                $out[$slug]['amount'] += (float)($vals['amount'] ?? 0);
            }
        }
        return $out;
    }
}

if (!function_exists('coopIpWelfareListFromMaps')) {
    /**
     * @param array<string,array{count?:int,amount?:float}> $agg
     * @return list<array{slug:string,label:string,count:int,amount:float,color:string}>
     */
    function coopIpWelfareListFromMaps(?PDO $db, array $agg, bool $en = false, bool $includeZeros = true): array
    {
        $types = coopIpWelfareTypesForAdmin($db);
        $out = [];
        foreach ($types as $slug => $meta) {
            $c = (int)($agg[$slug]['count'] ?? 0);
            $a = (float)($agg[$slug]['amount'] ?? 0);
            if (!$includeZeros && $c <= 0 && $a <= 0) {
                continue;
            }
            $label = $en
                ? (string)(($meta['en'] ?? '') ?: ($meta['np'] ?? $slug))
                : (string)(($meta['np'] ?? '') ?: ($meta['en'] ?? $slug));
            $out[] = [
                'slug' => (string)$slug,
                'label' => $label !== '' ? $label : (string)$slug,
                'count' => $c,
                'amount' => $a,
                'color' => (string)($meta['color'] ?? '#ff9800'),
            ];
        }
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
            $c = (int)($vals['count'] ?? 0);
            $a = (float)($vals['amount'] ?? 0);
            if ($c <= 0 && $a <= 0) {
                continue;
            }
            $out[] = [
                'slug' => (string)$slug,
                'label' => (string)$slug,
                'count' => $c,
                'amount' => $a,
                'color' => '#6b7280',
            ];
        }
        return $out;
    }
}

if (!function_exists('coopIpReportAdBounds')) {
    /**
     * AD inclusive bounds for a profile's BS report month.
     * @return array{from:string,to:string}|null
     */
    function coopIpReportAdBounds(array $row): ?array
    {
        if (!function_exists('coopIpProfileUptoAdDate')) {
            return null;
        }
        $to = coopIpProfileUptoAdDate($row);
        if ($to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return null;
        }
        $fy = trim((string)($row['fiscal_year'] ?? ''));
        $month = (int)($row['_month'] ?? (function_exists('coopIpResolveMonth') ? coopIpResolveMonth($row) : 0));
        $from = $to;
        if ($month >= 1 && $month <= 12 && preg_match('/^(\d{4})/', $fy, $fyM) && function_exists('bsToAd')) {
            $fyStart = (int)$fyM[1];
            $bsYear = ($month >= 4) ? $fyStart : ($fyStart + 1);
            $bsStart = sprintf('%04d-%02d-01', $bsYear, $month);
            $converted = trim((string)bsToAd($bsStart));
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $converted, $m)) {
                $from = $m[0];
            }
        }
        if ($from > $to) {
            $from = $to;
        }
        return ['from' => $from, 'to' => $to];
    }
}

if (!function_exists('coopIpPreviousProfileRow')) {
    /** @return array<string,mixed>|null */
    function coopIpPreviousProfileRow(?PDO $db, string $fiscalYear, int $reportMonth, int $excludeId = 0): ?array
    {
        if (!$db instanceof PDO || $fiscalYear === '') {
            return null;
        }
        try {
            /* Bound read — monthly IP rows stay well under this for decades */
            $rows = $db->query(
                'SELECT * FROM institutional_profile WHERE is_active = 1 OR is_active = 0 ORDER BY id DESC LIMIT 1000'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return null;
        }
        $target = [
            'fiscal_year' => $fiscalYear,
            'report_month' => $reportMonth,
            '_month' => $reportMonth,
        ];
        $targetKey = function_exists('coopIpSortKey') ? coopIpSortKey($target) : 0;
        $best = null;
        $bestKey = -1;
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($excludeId > 0 && $id === $excludeId) {
                continue;
            }
            $row['_month'] = function_exists('coopIpResolveMonth') ? coopIpResolveMonth($row) : (int)($row['report_month'] ?? 0);
            $key = function_exists('coopIpSortKey') ? coopIpSortKey($row) : 0;
            if ($key >= $targetKey) {
                continue;
            }
            if ($key > $bestKey) {
                $bestKey = $key;
                $best = $row;
            }
        }
        return $best;
    }
}

if (!function_exists('coopIpWelfareLoadProfileRows')) {
    /**
     * @return array<string,array{month_count:int,month_amount:float,cum_count:int,cum_amount:float}>
     */
    function coopIpWelfareLoadProfileRows(?PDO $db, int $profileId): array
    {
        if (!$db instanceof PDO || $profileId < 1) {
            return [];
        }
        coopIpEnsureWelfareTables($db);
        $out = [];
        try {
            $st = $db->prepare(
                'SELECT claim_type, month_count, month_amount, cum_count, cum_amount
                 FROM institutional_profile_welfare WHERE profile_id = ?
                 ORDER BY claim_type ASC LIMIT 200'
            );
            $st->execute([$profileId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $slug = trim((string)($row['claim_type'] ?? ''));
                if ($slug === '') {
                    continue;
                }
                $out[$slug] = [
                    'month_count' => (int)($row['month_count'] ?? 0),
                    'month_amount' => (float)($row['month_amount'] ?? 0),
                    'cum_count' => (int)($row['cum_count'] ?? 0),
                    'cum_amount' => (float)($row['cum_amount'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }
}

if (!function_exists('coopIpWelfareSaveProfileRows')) {
    /**
     * @param array<string,array{month_count?:int|string,month_amount?:float|string,cum_count?:int|string,cum_amount?:float|string}> $rows
     */
    function coopIpWelfareSaveProfileRows(PDO $db, int $profileId, array $rows): bool
    {
        if ($profileId < 1) {
            return false;
        }
        coopIpEnsureWelfareTables($db);
        try {
            $db->beginTransaction();
            $db->prepare('DELETE FROM institutional_profile_welfare WHERE profile_id = ?')->execute([$profileId]);
            $ins = $db->prepare(
                'INSERT INTO institutional_profile_welfare
                    (profile_id, claim_type, month_count, month_amount, cum_count, cum_amount)
                 VALUES (?,?,?,?,?,?)'
            );
            foreach ($rows as $slug => $vals) {
                $slug = trim((string)$slug);
                if ($slug === '' || !preg_match('/^[a-z0-9_\-]{1,60}$/i', $slug)) {
                    continue;
                }
                $mc = max(0, (int)($vals['month_count'] ?? 0));
                $ma = max(0.0, (float)($vals['month_amount'] ?? 0));
                $cc = max(0, (int)($vals['cum_count'] ?? 0));
                $ca = max(0.0, (float)($vals['cum_amount'] ?? 0));
                if ($mc === 0 && $ma <= 0 && $cc === 0 && $ca <= 0) {
                    continue;
                }
                $ins->execute([$profileId, $slug, $mc, $ma, $cc, $ca]);
            }
            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[ip-welfare] saveProfileRows: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('coopIpWelfarePrefillForMonth')) {
    /**
     * Auto defaults for admin form: month new + cumulative (opening + claims / prev cum).
     *
     * @return list<array{
     *   slug:string,label:string,color:string,
     *   prev_count:int,prev_amount:float,
     *   month_count:int,month_amount:float,
     *   cum_count:int,cum_amount:float
     * }>
     */
    function coopIpWelfarePrefillForMonth(
        ?PDO $db,
        string $fiscalYear,
        int $reportMonth,
        string $reportDateBs = '',
        string $reportDateAd = '',
        int $excludeProfileId = 0,
        bool $en = false
    ): array {
        if (!$db instanceof PDO || $fiscalYear === '' || $reportMonth < 1 || $reportMonth > 12) {
            return [];
        }
        $row = [
            'fiscal_year' => $fiscalYear,
            'report_month' => $reportMonth,
            '_month' => $reportMonth,
            'report_date_bs' => $reportDateBs,
            'report_date_ad' => $reportDateAd !== '' ? $reportDateAd : null,
        ];
        $bounds = coopIpReportAdBounds($row);
        if ($bounds === null) {
            return [];
        }
        $from = $bounds['from'];
        $to = $bounds['to'];

        $opening = coopIpWelfareOpeningMap($db);
        $openingAgg = [];
        foreach ($opening as $slug => $v) {
            $openingAgg[$slug] = ['count' => (int)$v['count'], 'amount' => (float)$v['amount']];
        }

        $monthAgg = coopIpWelfareClaimsAgg($db, 'period', $to, $from);
        $claimsUpto = coopIpWelfareClaimsAgg($db, 'upto', $to);

        $prev = coopIpPreviousProfileRow($db, $fiscalYear, $reportMonth, $excludeProfileId);
        $prevAgg = [];
        $prevStored = [];
        if ($prev && !empty($prev['id'])) {
            $prevStored = coopIpWelfareLoadProfileRows($db, (int)$prev['id']);
            if ($prevStored) {
                foreach ($prevStored as $slug => $v) {
                    $prevAgg[$slug] = [
                        'count' => (int)$v['cum_count'],
                        'amount' => (float)$v['cum_amount'],
                    ];
                }
            }
        }
        if (!$prevAgg) {
            /* Day before this month start → prior cumulative from opening + claims */
            $priorTo = date('Y-m-d', strtotime($from . ' -1 day')) ?: '';
            $claimsPrior = ($priorTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $priorTo))
                ? coopIpWelfareClaimsAgg($db, 'upto', $priorTo)
                : [];
            $prevAgg = coopIpWelfareMergeMaps($openingAgg, $claimsPrior);
        }

        $cumAgg = coopIpWelfareMergeMaps($prevAgg, $monthAgg);
        /* Prefer full opening+claims upto when no previous snapshot (more accurate vs month-split edge cases) */
        if (!$prevStored) {
            $cumAgg = coopIpWelfareMergeMaps($openingAgg, $claimsUpto);
        }

        $types = coopIpWelfareTypesForAdmin($db);
        $slugs = array_unique(array_merge(array_keys($types), array_keys($monthAgg), array_keys($cumAgg), array_keys($prevAgg)));
        $out = [];
        foreach ($slugs as $slug) {
            $slug = (string)$slug;
            if ($slug === '') {
                continue;
            }
            $meta = $types[$slug] ?? [];
            $label = $en
                ? (string)(($meta['en'] ?? '') ?: ($meta['np'] ?? $slug))
                : (string)(($meta['np'] ?? '') ?: ($meta['en'] ?? $slug));
            $out[] = [
                'slug' => $slug,
                'label' => $label !== '' ? $label : $slug,
                'color' => (string)($meta['color'] ?? '#ff9800'),
                'prev_count' => (int)($prevAgg[$slug]['count'] ?? 0),
                'prev_amount' => (float)($prevAgg[$slug]['amount'] ?? 0),
                'month_count' => (int)($monthAgg[$slug]['count'] ?? 0),
                'month_amount' => (float)($monthAgg[$slug]['amount'] ?? 0),
                'cum_count' => (int)($cumAgg[$slug]['count'] ?? 0),
                'cum_amount' => (float)($cumAgg[$slug]['amount'] ?? 0),
            ];
        }
        usort($out, static fn($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }
}

if (!function_exists('coopIpWelfareResolveForProfile')) {
    /**
     * Public display list: prefer saved cumulative snapshot; else opening + live claims.
     *
     * @return list<array{slug:string,label:string,count:int,amount:float,color:string}>
     */
    function coopIpWelfareResolveForProfile(?PDO $db, array $profileRow, bool $en = false): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        $profileId = (int)($profileRow['id'] ?? 0);
        if ($profileId > 0) {
            $stored = coopIpWelfareLoadProfileRows($db, $profileId);
            if ($stored) {
                $agg = [];
                foreach ($stored as $slug => $v) {
                    $agg[$slug] = [
                        'count' => (int)$v['cum_count'],
                        'amount' => (float)$v['cum_amount'],
                    ];
                }
                return coopIpWelfareListFromMaps($db, $agg, $en, true);
            }
        }
        if (!function_exists('coopIpProfileUptoAdDate') || !function_exists('coopIpWelfareReliefByType')) {
            return [];
        }
        $upto = coopIpProfileUptoAdDate($profileRow);
        $live = coopIpWelfareReliefByType($db, $upto, $en);
        $opening = coopIpWelfareOpeningMap($db);
        if (!$opening) {
            return $live;
        }
        $bySlug = [];
        foreach ($live as $row) {
            $bySlug[$row['slug']] = $row;
        }
        foreach ($opening as $slug => $ov) {
            if (!isset($bySlug[$slug])) {
                $types = coopIpWelfareTypesForAdmin($db);
                $meta = $types[$slug] ?? [];
                $label = $en
                    ? (string)(($meta['en'] ?? '') ?: ($meta['np'] ?? $slug))
                    : (string)(($meta['np'] ?? '') ?: ($meta['en'] ?? $slug));
                $bySlug[$slug] = [
                    'slug' => $slug,
                    'label' => $label !== '' ? $label : $slug,
                    'count' => 0,
                    'amount' => 0.0,
                    'color' => (string)($meta['color'] ?? '#ff9800'),
                ];
            }
            $bySlug[$slug]['count'] += (int)$ov['count'];
            $bySlug[$slug]['amount'] += (float)$ov['amount'];
        }
        return array_values($bySlug);
    }
}

if (!function_exists('coopIpWelfareParsePostRows')) {
    /**
     * Parse welfare_* POST arrays from admin monthly form.
     * @return array<string,array{month_count:int,month_amount:float,cum_count:int,cum_amount:float}>
     */
    function coopIpWelfareParsePostRows(array $post): array
    {
        $slugs = $post['welfare_slug'] ?? [];
        if (!is_array($slugs)) {
            return [];
        }
        $mc = $post['welfare_month_count'] ?? [];
        $ma = $post['welfare_month_amount'] ?? [];
        $cc = $post['welfare_cum_count'] ?? [];
        $ca = $post['welfare_cum_amount'] ?? [];
        $out = [];
        foreach ($slugs as $i => $slug) {
            $slug = trim((string)$slug);
            if ($slug === '') {
                continue;
            }
            $out[$slug] = [
                'month_count' => max(0, (int)($mc[$i] ?? 0)),
                'month_amount' => max(0.0, (float)($ma[$i] ?? 0)),
                'cum_count' => max(0, (int)($cc[$i] ?? 0)),
                'cum_amount' => max(0.0, (float)($ca[$i] ?? 0)),
            ];
        }
        return $out;
    }
}
