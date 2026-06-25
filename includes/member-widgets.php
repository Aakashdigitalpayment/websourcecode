<?php
/**
 * ════════════════════════════════════════════════════════════════════
 * MEMBER WIDGETS — v4
 * ════════════════════════════════════════════════════════════════════
 * Member dashboard र अरू member panel pages मा साझा रूपमा use हुने
 * server-side helpers।
 *
 *   - calculateMemberLoanSummary($email, $phone)
 *       → approved/pending loan counts + total approved amount
 *   - calculateMemberSavingSummary($email, $phone)
 *       → approved account applications + tentative balance (UI label only)
 *   - getMemberTransactionHistory($email, $phone, $limit = 50)
 *       → सबै applications timeline format मा (loan + saving + service)
 *
 * NOTE: यो website हो (CBS होइन) — real account balance यहाँ छैन।
 *       Application data देखाइन्छ; Admin ले approved गरेको loan amount र
 *       account application लाई "summary" को रूपमा देखाउँछ।
 * ════════════════════════════════════════════════════════════════════
 */

if (!function_exists('memberLoanSummary')) {
function memberLoanSummary(string $email, string $phone): array {
    $out = ['total'=>0,'pending'=>0,'approved'=>0,'rejected'=>0,'approved_amount'=>0.0,'latest'=>null];
    try {
        global $db; if (!$db) $db = getDB(); if (!$db) return $out;
        $st = $db->prepare("SELECT id, tracking_id, loan_amount, status, created_at
                            FROM loan_applications
                            WHERE (email=? OR mobile=?)
                              AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00' OR 1=1)
                            ORDER BY created_at DESC");
        $st->execute([$email, $phone]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out['total']++;
            $st2 = strtolower($r['status'] ?? '');
            if (in_array($st2, ['pending','under_review','in_progress','processing'])) $out['pending']++;
            elseif (in_array($st2, ['approved','disbursed','completed']))               { $out['approved']++; $out['approved_amount'] += (float)($r['loan_amount'] ?? 0); }
            elseif (in_array($st2, ['rejected','cancelled']))                          $out['rejected']++;
        }
        $out['latest'] = $rows[0] ?? null;
    } catch (\Throwable $e) { error_log('[widgets/loan] '.$e->getMessage()); }
    return $out;
}}

if (!function_exists('memberSavingSummary')) {
function memberSavingSummary(string $email, string $phone): array {
    $out = ['total'=>0,'pending'=>0,'approved'=>0,'latest'=>null];
    try {
        global $db; if (!$db) $db = getDB(); if (!$db) return $out;
        $st = $db->prepare("SELECT id, tracking_id, account_type, status, created_at
                            FROM account_applications
                            WHERE (email=? OR mobile=? OR phone=?)
                            ORDER BY created_at DESC");
        try { $st->execute([$email, $phone, $phone]); }
        catch (\Throwable $e) {
            /* Schema variation fallback */
            $st = $db->prepare("SELECT id, tracking_id, status, created_at FROM account_applications WHERE email=? ORDER BY created_at DESC");
            $st->execute([$email]);
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $out['total']++;
            $s = strtolower($r['status'] ?? '');
            if (in_array($s, ['pending','under_review','in_progress'])) $out['pending']++;
            elseif (in_array($s, ['approved','completed']))             $out['approved']++;
        }
        $out['latest'] = $rows[0] ?? null;
    } catch (\Throwable $e) { error_log('[widgets/saving] '.$e->getMessage()); }
    return $out;
}}

if (!function_exists('memberTransactionHistory')) {
/**
 * Combine all application records into one timeline.
 * Each row: ['type','tracking_id','title','amount','status','date','color','icon']
 */
function memberTransactionHistory(string $email, string $phone, int $limit = 50): array {
    $out = [];
    try {
        global $db; if (!$db) $db = getDB(); if (!$db) return $out;

        /* Loan */
        try {
            $st = $db->prepare("SELECT 'loan' AS t, tracking_id, loan_amount AS amount, status, created_at, loan_type AS sub
                                FROM loan_applications WHERE email=? OR mobile=? ORDER BY created_at DESC LIMIT ?");
            $st->bindValue(1,$email); $st->bindValue(2,$phone); $st->bindValue(3,$limit,PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['type'=>'loan','tracking_id'=>$r['tracking_id'],'title'=>'ऋण आवेदन — '.($r['sub']??''),
                          'amount'=>$r['amount'],'status'=>$r['status'],'date'=>$r['created_at'],
                          'color'=>'#7c3aed','icon'=>'fa-hand-holding-usd'];
            }
        } catch (\Throwable $e) {}

        /* Account/Saving */
        try {
            $st = $db->prepare("SELECT 'account' AS t, tracking_id, NULL AS amount, status, created_at, account_type AS sub
                                FROM account_applications WHERE email=? OR mobile=? OR phone=? ORDER BY created_at DESC LIMIT ?");
            $st->bindValue(1,$email); $st->bindValue(2,$phone); $st->bindValue(3,$phone); $st->bindValue(4,$limit,PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['type'=>'account','tracking_id'=>$r['tracking_id'],'title'=>'खाता आवेदन — '.($r['sub']??''),
                          'amount'=>null,'status'=>$r['status'],'date'=>$r['created_at'],
                          'color'=>'#0e7490','icon'=>'fa-piggy-bank'];
            }
        } catch (\Throwable $e) {}

        /* KYC */
        try {
            $st = $db->prepare("SELECT tracking_id, status, created_at FROM kyc_applications WHERE email=? OR phone=? ORDER BY created_at DESC LIMIT ?");
            $st->bindValue(1,$email); $st->bindValue(2,$phone); $st->bindValue(3,$limit,PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['type'=>'kyc','tracking_id'=>$r['tracking_id'],'title'=>'KYC आवेदन',
                          'amount'=>null,'status'=>$r['status'],'date'=>$r['created_at'],
                          'color'=>'var(--secondary-color,#c0392b)','icon'=>'fa-id-card'];
            }
        } catch (\Throwable $e) {}

        /* Digital service */
        try {
            $st = $db->prepare("SELECT tracking_id, service_type AS sub, status, created_at FROM digital_service_requests WHERE email=? OR phone=? ORDER BY created_at DESC LIMIT ?");
            $st->bindValue(1,$email); $st->bindValue(2,$phone); $st->bindValue(3,$limit,PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['type'=>'digital','tracking_id'=>$r['tracking_id'],'title'=>'डिजिटल सेवा — '.($r['sub']??''),
                          'amount'=>null,'status'=>$r['status'],'date'=>$r['created_at'],
                          'color'=>'#059669','icon'=>'fa-laptop'];
            }
        } catch (\Throwable $e) {}

        /* Sort all by date DESC */
        usort($out, fn($a,$b) => strtotime($b['date']) <=> strtotime($a['date']));
        if (count($out) > $limit) $out = array_slice($out, 0, $limit);
    } catch (\Throwable $e) { error_log('[widgets/txn] '.$e->getMessage()); }
    return $out;
}}

/* EMI calculation — used both as widget output + verification of public-page logic.
 * Formula: P × r × (1+r)^n / ((1+r)^n - 1), where r = annual%/12/100, n = months */
if (!function_exists('calculateEMI')) {
function calculateEMI(float $principal, float $annualRatePct, int $months): array {
    if ($principal <= 0 || $months <= 0) return ['emi'=>0,'total'=>0,'interest'=>0];
    if ($annualRatePct <= 0) {
        $emi = $principal / $months;
        return ['emi'=>round($emi,2),'total'=>round($principal,2),'interest'=>0];
    }
    $r = $annualRatePct / 12 / 100;
    $pow = pow(1 + $r, $months);
    $emi = ($principal * $r * $pow) / ($pow - 1);
    $total = $emi * $months;
    return ['emi'=>round($emi,2),'total'=>round($total,2),'interest'=>round($total - $principal,2)];
}}
