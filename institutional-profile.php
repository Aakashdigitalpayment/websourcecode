<?php
/**
 * Public Page: संस्थागत प्रोफाइल
 * File: institutional-profile.php
 *
 * Admin मा थपिएको financial data यहाँ public page मा देखाइन्छ।
 * Admin: admin/institutional-profile.php बाट manage गर्नुहोस्।
 */
require_once 'includes/config.php';
if (is_file(__DIR__ . '/includes/nepali-bs-convert.php')) {
    require_once __DIR__ . '/includes/nepali-bs-convert.php';
}
$pageTitle = isEnglish() ? 'Institutional Profile' : 'संस्थागत प्रोफाइल';
require_once 'includes/header.php';
$L = getLangStrings();

/* ─── Helpers ─── */
function ipShortAmt(float $v): string {
    if ($v >= 1e7) return 'रू. ' . number_format($v / 1e7, 2) . ' करोड';
    if ($v >= 1e5) return 'रू. ' . number_format($v / 1e5, 1) . ' लाख';
    if ($v > 0)    return 'रू. ' . number_format($v);
    return '—';
}

function ipNepaliNumber(int $number): string {
    return strtr((string)$number, ['0'=>'०','1'=>'१','2'=>'२','3'=>'३','4'=>'४','5'=>'५','6'=>'६','7'=>'७','8'=>'८','9'=>'९']);
}

function ipMonthLabel(int $m, bool $en = false): string {
    if ($m < 1 || $m > 12) {
        return $en ? 'Annual / Unset' : 'वार्षिक / नखुलेको';
    }
    if (function_exists('getNepaliMonthName')) {
        return (string) getNepaliMonthName((string) $m);
    }
    $enNames = [1=>'Baisakh',2=>'Jestha',3=>'Ashadh',4=>'Shrawan',5=>'Bhadra',6=>'Ashwin',7=>'Kartik',8=>'Mangsir',9=>'Poush',10=>'Magh',11=>'Falgun',12=>'Chaitra'];
    return $en ? ($enNames[$m] ?? ('Month ' . $m)) : ('महिना ' . $m);
}

function ipResolveMonth(array $p): int {
    $m = (int)($p['report_month'] ?? 0);
    if ($m >= 1 && $m <= 12) return $m;
    $bs = trim((string)($p['report_date_bs'] ?? ''));
    if (preg_match('/^\d{4}-(\d{2})/', $bs, $mm)) {
        return max(0, min(12, (int)$mm[1]));
    }
    return 0;
}

function ipFiscalFromBs(int $bsYear, int $bsMonth): string {
    /* Nepal FY starts Shrawan (4) */
    $start = ($bsMonth >= 4) ? $bsYear : ($bsYear - 1);
    return $start . '/' . sprintf('%02d', ($start + 1) % 100);
}

/* ─── Fetch active institutional profiles ─── */
$profiles = [];
$tableExists = false;
try {
    $db = getDB();
    $r = $db->query("SHOW TABLES LIKE 'institutional_profile'");
    $tableExists = ($r->rowCount() > 0);
    if ($tableExists) {
        try {
            $db->exec("ALTER TABLE institutional_profile ADD COLUMN report_month TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'BS month 1-12'");
        } catch (Exception $e) { /* exists */ }
        try {
            $profiles = $db->query(
                "SELECT * FROM institutional_profile WHERE is_active = 1 ORDER BY fiscal_year DESC, report_month DESC, id DESC LIMIT 48"
            )->fetchAll();
        } catch (Exception $e) {
            $profiles = $db->query(
                "SELECT * FROM institutional_profile WHERE is_active = 1 ORDER BY fiscal_year DESC LIMIT 24"
            )->fetchAll();
        }
    }
} catch (Exception $e) {
    $profiles = [];
}

$isEn = isEnglish();
$fiscalYears = [];
$monthSet = [];
foreach ($profiles as &$_pRow) {
    $_pRow['_month'] = ipResolveMonth($_pRow);
    $fy = trim((string)($_pRow['fiscal_year'] ?? ''));
    if ($fy !== '') $fiscalYears[$fy] = true;
    if ($_pRow['_month'] > 0) $monthSet[$_pRow['_month']] = true;
}
unset($_pRow);
$fiscalYears = array_keys($fiscalYears);
ksort($monthSet);

/* Current + previous Nepali month */
$curBsY = 0;
$curBsM = 0;
$prevBsY = 0;
$prevBsM = 0;
$curFy = '';
$prevFy = '';
if (function_exists('nepali_kathmandu_today_bs')) {
    $todayBs = nepali_kathmandu_today_bs();
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $todayBs, $tm)) {
        $curBsY = (int)$tm[1];
        $curBsM = (int)$tm[2];
        $prevBsM = $curBsM - 1;
        $prevBsY = $curBsY;
        if ($prevBsM < 1) { $prevBsM = 12; $prevBsY--; }
        $curFy = ipFiscalFromBs($curBsY, $curBsM);
        $prevFy = ipFiscalFromBs($prevBsY, $prevBsM);
    }
}

$findProfile = static function (array $list, string $fy, int $month): ?array {
    foreach ($list as $row) {
        if (trim((string)($row['fiscal_year'] ?? '')) === $fy && (int)($row['_month'] ?? 0) === $month) {
            return $row;
        }
    }
    return null;
};

$currentProfile = ($curFy !== '' && $curBsM > 0) ? $findProfile($profiles, $curFy, $curBsM) : null;
$previousProfile = ($prevFy !== '' && $prevBsM > 0) ? $findProfile($profiles, $prevFy, $prevBsM) : null;

/* Fallback featured: newest two month-tagged records */
$featuredFallback = [];
if (!$currentProfile || !$previousProfile) {
    foreach ($profiles as $row) {
        if ((int)($row['_month'] ?? 0) < 1) continue;
        $featuredFallback[] = $row;
        if (count($featuredFallback) >= 2) break;
    }
}
?>

<style>
.ip-filter-wrap {
    background: color-mix(in srgb, var(--primary-color, #1a5f2a) 4%, var(--bg-card, #fff));
    border: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 14%, var(--border-color, #e5e7eb));
    border-radius: 14px;
    padding: 14px;
    margin: 0 0 14px;
}
.ip-filter-bar {
    display: grid;
    grid-template-columns: minmax(140px, 180px) minmax(140px, 180px) minmax(160px, 1fr) auto auto;
    gap: 10px;
    align-items: center;
}
.ip-filter-input,
.ip-filter-select {
    height: 42px;
    border-radius: 10px;
    border: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 18%, #d1d5db);
    background: var(--bg-card, #fff);
    color: var(--text-primary, #1f2937);
    font-size: 0.92rem;
    padding: 0 12px;
}
.ip-filter-input:focus,
.ip-filter-select:focus {
    outline: none;
    border-color: var(--primary-color, #1a5f2a);
    box-shadow: var(--shadow-focus, 0 0 0 3px rgba(26, 95, 42, 0.14));
}
.ip-filter-reset {
    height: 42px;
    border: none;
    border-radius: 10px;
    background: var(--primary-color, #1a5f2a);
    color: var(--text-on-primary, #fff);
    font-weight: 600;
    padding: 0 14px;
}
.ip-filter-count {
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--primary-ink, #166534);
    background: color-mix(in srgb, var(--primary-color, #1a5f2a) 10%, #fff);
    border: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 22%, #bbf7d0);
    border-radius: 999px;
    padding: 7px 11px;
    white-space: nowrap;
}
.ip-filter-empty {
    display: none;
    margin-top: 12px;
    border: 1px dashed #c9d8cf;
    border-radius: 12px;
    background: #fbfdfc;
    color: #4b5563;
    text-align: center;
    padding: 18px 14px;
    font-size: 0.92rem;
}
.ip-month-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}
.ip-month-chip {
    border: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 18%, #d1d5db);
    background: var(--bg-card, #fff);
    color: var(--text-secondary, #4b5563);
    border-radius: 999px;
    padding: 6px 12px;
    font-size: .78rem;
    font-weight: 700;
    cursor: pointer;
    transition: background .15s, color .15s, border-color .15s;
}
.ip-month-chip:hover,
.ip-month-chip.is-active {
    background: var(--primary-color, #1a5f2a);
    color: var(--text-on-primary, #fff);
    border-color: var(--primary-color, #1a5f2a);
}
.ip-featured {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.ip-featured-card {
    border-radius: 16px;
    border: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 16%, #e5e7eb);
    background:
      radial-gradient(ellipse 80% 60% at 100% 0%, color-mix(in srgb, var(--primary-color, #1a5f2a) 14%, transparent), transparent 55%),
      var(--bg-card, #fff);
    padding: 16px 16px 14px;
    box-shadow: 0 10px 28px rgba(15, 23, 42, .06);
}
.ip-featured-card.is-current {
    border-color: color-mix(in srgb, var(--primary-color, #1a5f2a) 40%, #86efac);
    box-shadow: 0 12px 32px rgba(var(--primary-rgb, 26,95,42), .14);
}
.ip-featured-kicker {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .02em;
    text-transform: uppercase;
    color: var(--primary-ink, var(--primary-color, #1a5f2a));
    background: color-mix(in srgb, var(--primary-color, #1a5f2a) 10%, #fff);
    border-radius: 999px;
    padding: 4px 10px;
    margin-bottom: 8px;
}
.ip-featured-title {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 900;
    color: var(--text-primary, #17251b);
}
.ip-featured-sub {
    margin: 4px 0 12px;
    font-size: .82rem;
    color: var(--text-muted, #64748b);
}
.ip-featured-stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}
.ip-featured-stat {
    background: color-mix(in srgb, var(--bg-soft, #f5faf6) 80%, #fff);
    border-radius: 10px;
    padding: 10px;
}
.ip-featured-stat span {
    display: block;
    font-size: .68rem;
    color: var(--text-muted, #64748b);
    font-weight: 600;
}
.ip-featured-stat strong {
    display: block;
    margin-top: 2px;
    font-size: .95rem;
    color: var(--primary-ink, var(--primary-color, #1a5f2a));
    font-weight: 800;
}
.ip-featured-empty {
    border-style: dashed;
    opacity: .92;
}
.ip-month-tile.is-hidden { display: none !important; }
.ip-month-tile.is-highlight {
    outline: 2px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 45%, transparent);
    outline-offset: 1px;
}
.ip-month-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 4px;
    font-size: .68rem;
    font-weight: 800;
    color: var(--primary-ink, #166534);
    background: color-mix(in srgb, var(--primary-color, #1a5f2a) 10%, #fff);
    border-radius: 999px;
    padding: 2px 8px;
}
@media (max-width: 991px) {
    .ip-filter-bar { grid-template-columns: 1fr 1fr; }
    .ip-featured { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .ip-filter-bar { grid-template-columns: 1fr; }
    .ip-filter-count { text-align: center; }
}
</style>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $isEn ? 'Institutional Profile' : 'संस्थागत प्रोफाइल'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home'] ?? 'गृहपृष्ठ'; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $isEn ? 'Institutional Profile' : 'संस्थागत प्रोफाइल'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Main Content -->
<section class="section-padding institutional-profile-page">
<div class="container">

<?php if (empty($profiles)): ?>
<div class="text-center py-5">
    <div class="ip-empty-icon-wrap">
        <i class="fas fa-building-columns fa-2x" style="color:var(--primary-color);"></i>
    </div>
    <h4 style="color:var(--primary-color);"><?php echo $isEn ? 'Institutional profile not available yet' : 'संस्थागत प्रोफाइल उपलब्ध छैन'; ?></h4>
    <p class="text-muted"><?php echo $isEn ? 'Will be available soon.' : 'छिट्टै उपलब्ध हुनेछ।'; ?></p>
</div>

<?php else: ?>

<div class="ip-section-intro text-center mb-4">
    <span class="ip-section-kicker"><i class="fas fa-chart-line"></i> <?php echo $isEn ? 'Financial stats' : 'आर्थिक तथ्याङ्क'; ?></span>
    <h2><?php echo $isEn ? 'Institutional financial profile' : 'संस्थाको आर्थिक प्रोफाइल'; ?></h2>
    <p><?php echo $isEn
        ? 'Shows the latest two months by default — use filters or “All” to browse more.'
        : 'पहिले हालका २ महिना मात्र देखिन्छ — अरू हेर्न फिल्टर वा “सबै” प्रयोग गर्नुहोस्।'; ?></p>
</div>

<?php
$renderFeatured = static function (?array $p, string $kicker, string $title, string $sub, bool $isCurrent, bool $isEn): void {
    if (!$p) {
        echo '<div class="ip-featured-card ip-featured-empty">';
        echo '<span class="ip-featured-kicker"><i class="fas fa-clock"></i> ' . htmlspecialchars($kicker) . '</span>';
        echo '<h3 class="ip-featured-title">' . htmlspecialchars($title) . '</h3>';
        echo '<p class="ip-featured-sub">' . ($isEn ? 'Data not published for this month yet.' : 'यो महिनाको डाटा अझै प्रकाशित भएको छैन।') . '</p>';
        echo '</div>';
        return;
    }
    $cls = $isCurrent ? 'ip-featured-card is-current' : 'ip-featured-card';
    echo '<article class="' . $cls . '">';
    echo '<span class="ip-featured-kicker"><i class="fas fa-' . ($isCurrent ? 'bolt' : 'history') . '"></i> ' . htmlspecialchars($kicker) . '</span>';
    echo '<h3 class="ip-featured-title">' . htmlspecialchars($title) . '</h3>';
    echo '<p class="ip-featured-sub">' . htmlspecialchars($sub) . '</p>';
    echo '<div class="ip-featured-stats">';
    $stats = [
        [$isEn ? 'Members' : 'सदस्य', number_format((int)($p['total_members'] ?? 0))],
        [$isEn ? 'Share capital' : 'शेयर पूँजी', ipShortAmt((float)($p['share_capital'] ?? 0))],
        [$isEn ? 'Deposits' : 'बचत', ipShortAmt((float)($p['deposit'] ?? 0))],
        [$isEn ? 'Total assets' : 'कुल सम्पत्ति', ipShortAmt((float)($p['total_assets'] ?? 0))],
    ];
    foreach ($stats as [$lab, $val]) {
        echo '<div class="ip-featured-stat"><span>' . htmlspecialchars($lab) . '</span><strong>' . htmlspecialchars($val) . '</strong></div>';
    }
    echo '</div></article>';
};
?>

<div class="ip-featured" data-testid="institutional-profile-featured-months">
<?php if ($currentProfile || $previousProfile || $curBsM > 0): ?>
    <?php
    $curTitle = ($curFy !== '' ? 'आ.व. ' . $curFy . ' · ' : '') . ipMonthLabel($curBsM, $isEn);
    $prevTitle = ($prevFy !== '' ? 'आ.व. ' . $prevFy . ' · ' : '') . ipMonthLabel($prevBsM, $isEn);
    $renderFeatured(
        $currentProfile,
        $isEn ? 'Current month' : 'अहिलेको महिना',
        $isEn ? ('FY ' . $curFy . ' · ' . ipMonthLabel($curBsM, true)) : $curTitle,
        $currentProfile && !empty($currentProfile['report_date_bs'])
            ? (($isEn ? 'As of ' : 'मिति ') . $currentProfile['report_date_bs'])
            : ($isEn ? 'Latest published snapshot' : 'नवीनतम प्रकाशित विवरण'),
        true,
        $isEn
    );
    $renderFeatured(
        $previousProfile,
        $isEn ? 'Previous month' : 'अघिल्लो महिना',
        $isEn ? ('FY ' . $prevFy . ' · ' . ipMonthLabel($prevBsM, true)) : $prevTitle,
        $previousProfile && !empty($previousProfile['report_date_bs'])
            ? (($isEn ? 'As of ' : 'मिति ') . $previousProfile['report_date_bs'])
            : ($isEn ? 'Compare with last month' : 'गत महिनासँग तुलना'),
        false,
        $isEn
    );
    ?>
<?php elseif (!empty($featuredFallback)): ?>
    <?php foreach (array_slice($featuredFallback, 0, 2) as $fi => $fp):
        $fm = (int)$fp['_month'];
        $renderFeatured(
            $fp,
            $fi === 0 ? ($isEn ? 'Latest month' : 'नवीनतम महिना') : ($isEn ? 'Earlier month' : 'अघिल्लो प्रकाशित'),
            'आ.व. ' . ($fp['fiscal_year'] ?? '') . ' · ' . ipMonthLabel($fm, $isEn),
            !empty($fp['report_date_bs']) ? (($isEn ? 'As of ' : 'मिति ') . $fp['report_date_bs']) : '',
            $fi === 0,
            $isEn
        );
    endforeach; ?>
<?php endif; ?>
</div>

<div class="ip-profile-card mb-3 ip-month-card">
    <div class="ip-card-header">
        <div class="ip-card-title-wrap">
            <div class="ip-fy-badge"><i class="fas fa-table me-2"></i> <?php echo $isEn ? 'Month-wise financial details' : 'महिनागत आर्थिक विवरण'; ?></div>
            <div class="ip-date-info"><span><?php echo $isEn ? 'Default: latest 2 months — filter for more' : 'पूर्वनिर्धारित: हालका २ महिना — अरू फिल्टरबाट'; ?></span></div>
        </div>
    </div>

    <div class="ip-filter-wrap" data-testid="institutional-profile-filters">
        <div class="ip-filter-bar">
            <select id="ipFiscalYearFilter" class="ip-filter-select" aria-label="Fiscal Year Filter">
                <option value=""><?php echo $isEn ? 'All Fiscal Years' : 'सबै आ.व.'; ?></option>
                <?php foreach ($fiscalYears as $fy): ?>
                <option value="<?php echo htmlspecialchars($fy, ENT_QUOTES, 'UTF-8'); ?>">आ.व. <?php echo htmlspecialchars($fy, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>

            <select id="ipMonthFilter" class="ip-filter-select" aria-label="Month Filter">
                <option value=""><?php echo $isEn ? 'All months' : 'सबै महिना'; ?></option>
                <option value="0"><?php echo $isEn ? 'Annual / unset' : 'वार्षिक / नखुलेको'; ?></option>
                <?php for ($mi = 1; $mi <= 12; $mi++): ?>
                <option value="<?php echo $mi; ?>"><?php echo htmlspecialchars(ipMonthLabel($mi, $isEn)); ?></option>
                <?php endfor; ?>
            </select>

            <input id="ipFinancialSearch" type="search" class="ip-filter-input"
                   placeholder="<?php echo $isEn ? 'Search FY, month, or keyword' : 'आ.व., महिना वा संकेतक खोज्नुहोस्'; ?>"
                   aria-label="Financial search">

            <button id="ipFilterReset" type="button" class="ip-filter-reset">
                <i class="fas fa-rotate-left me-1"></i><?php echo $isEn ? 'Reset' : 'रिसेट'; ?>
            </button>

            <span id="ipFilterCount" class="ip-filter-count"></span>
        </div>

        <div class="ip-month-chips" id="ipMonthChips" role="group" aria-label="<?php echo $isEn ? 'Quick month filters' : 'छिटो महिना फिल्टर'; ?>">
            <button type="button" class="ip-month-chip is-active" data-mode="recent2" data-testid="institutional-profile-chip-recent">
                <?php echo $isEn ? 'Latest 2' : 'हालका २'; ?>
            </button>
            <button type="button" class="ip-month-chip" data-month="" data-mode="all" data-testid="institutional-profile-chip-all"><?php echo $isEn ? 'All' : 'सबै'; ?></button>
            <?php if ($curBsM > 0): ?>
            <button type="button" class="ip-month-chip" data-month="<?php echo (int)$curBsM; ?>" data-fy="<?php echo htmlspecialchars($curFy, ENT_QUOTES); ?>" data-testid="institutional-profile-chip-current">
                <?php echo $isEn ? 'This month' : 'अहिलेको'; ?> · <?php echo htmlspecialchars(ipMonthLabel($curBsM, $isEn)); ?>
            </button>
            <button type="button" class="ip-month-chip" data-month="<?php echo (int)$prevBsM; ?>" data-fy="<?php echo htmlspecialchars($prevFy, ENT_QUOTES); ?>" data-testid="institutional-profile-chip-previous">
                <?php echo $isEn ? 'Previous' : 'अघिल्लो'; ?> · <?php echo htmlspecialchars(ipMonthLabel($prevBsM, $isEn)); ?>
            </button>
            <?php endif; ?>
            <?php foreach (array_keys($monthSet) as $chipM):
                if ($chipM === $curBsM || $chipM === $prevBsM) continue;
            ?>
            <button type="button" class="ip-month-chip" data-month="<?php echo (int)$chipM; ?>"><?php echo htmlspecialchars(ipMonthLabel((int)$chipM, $isEn)); ?></button>
            <?php endforeach; ?>
        </div>

        <div id="ipFilterEmpty" class="ip-filter-empty">
            <i class="fas fa-filter-circle-xmark me-1"></i><?php echo $isEn ? 'No matching record found.' : 'मिल्दो रेकर्ड भेटिएन।'; ?>
        </div>
    </div>

    <div class="ip-month-grid" data-testid="institutional-profile-month-wise-grid">
        <?php foreach ($profiles as $idx => $p): ?>
        <?php
            $rowNo = $idx + 1;
            $rm = (int)($p['_month'] ?? 0);
            $totalLoanMembers = (int)($p['total_loan_members'] ?? 0);
            $otherFund = (float)($p['other_fund'] ?? 0);
            $bankCashBalance = (float)($p['bank_cash_balance'] ?? 0);
            $fixedAssets = (float)($p['fixed_assets'] ?? 0);
            $_ipDocUrl  = !empty($p['attachment_path']) ? htmlspecialchars(SITE_URL . ltrim($p['attachment_path'], '/'), ENT_QUOTES, 'UTF-8') : '';
            $_ipDocExt  = !empty($p['attachment_path']) ? strtolower(pathinfo($p['attachment_path'], PATHINFO_EXTENSION)) : '';
            $_fy = trim((string)($p['fiscal_year'] ?? ''));
            $_dateBs = trim((string)($p['report_date_bs'] ?? ''));
            $_monthName = ipMonthLabel($rm, $isEn);
            $_filterText = strtolower(trim($_fy . ' ' . $_monthName . ' ' . $_dateBs . ' कुल सदस्य शेयर पूँजी जगेडा कोष कुल बचत ऋण लगानी बैंक नगद स्थिर सम्पत्ति कुल सम्पत्ति'));
            $isCur = ($currentProfile && (int)($currentProfile['id'] ?? 0) === (int)($p['id'] ?? 0));
            $isPrev = ($previousProfile && (int)($previousProfile['id'] ?? 0) === (int)($p['id'] ?? 0));
            $tileCls = 'ip-month-tile' . ($isCur || $isPrev ? ' is-highlight' : '');
            $defaultShow = ($isCur || $isPrev) ? '1' : '0';
        ?>
        <article class="<?php echo $tileCls; ?>"
                 data-fy="<?php echo htmlspecialchars($_fy, ENT_QUOTES, 'UTF-8'); ?>"
                 data-month="<?php echo (int)$rm; ?>"
                 data-default-show="<?php echo $defaultShow; ?>"
                 data-filter="<?php echo htmlspecialchars($_filterText, ENT_QUOTES, 'UTF-8'); ?>"
                 data-testid="institutional-profile-month-card-<?php echo $rowNo; ?>">
            <div class="ip-month-tile-head">
                <div>
                    <strong data-testid="institutional-profile-fiscal-year-<?php echo $rowNo; ?>">आ.व. <?php echo htmlspecialchars($p['fiscal_year']); ?></strong>
                    <span class="ip-month-badge"><i class="fas fa-calendar-week"></i> <?php echo htmlspecialchars($_monthName); ?></span>
                    <?php if (!empty($p['report_date_bs'])): ?>
                    <span data-testid="institutional-profile-published-date-<?php echo $rowNo; ?>"><?php echo htmlspecialchars($p['report_date_bs']); ?><?php if (!empty($p['report_date_ad'])): ?> / <?php echo date('d M Y', strtotime($p['report_date_ad'])); ?><?php endif; ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($p['attachment_path'])): ?>
                <button type="button" class="ip-row-doc-btn"
                        onclick="ipOpenDoc('<?php echo $_ipDocUrl; ?>','<?php echo $_ipDocExt; ?>')"
                        data-testid="institutional-profile-document-button-<?php echo $rowNo; ?>"
                        title="कागजात हेर्नुहोस्">
                    <i class="fas <?php echo $_ipDocExt === 'pdf' ? 'fa-file-pdf' : 'fa-file-image'; ?>"></i>
                </button>
                <?php endif; ?>
            </div>

            <div class="ip-month-ledger">
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(1); ?></span>
                    <span class="ip-month-title"><i class="fas fa-users"></i> कुल सदस्य</span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-total-members-value-<?php echo $rowNo; ?>"><?php echo number_format((int)$p['total_members']); ?></strong><?php if (!empty($p['total_balance_member'])): ?><em><?php echo number_format((int)$p['total_balance_member']); ?> शेष</em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(2); ?></span>
                    <span class="ip-month-title"><i class="fas fa-coins"></i> शेयर पूँजी</span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-share-capital-value-<?php echo $rowNo; ?>"><?php echo ipShortAmt((float)$p['share_capital']); ?></strong><?php if (!empty($p['share_capital_percent'])): ?><em><?php echo htmlspecialchars((string)$p['share_capital_percent']); ?>% वृद्धि</em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(3); ?></span>
                    <span class="ip-month-title"><i class="fas fa-shield-halved"></i> जगेडा कोष</span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-reserved-fund-value-<?php echo $rowNo; ?>"><?php echo ipShortAmt((float)($p['reserved_fund'] ?? 0)); ?></strong><?php if (!empty($p['reserved_fund_percent'])): ?><em><?php echo htmlspecialchars((string)$p['reserved_fund_percent']); ?>% वृद्धि</em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(4); ?></span>
                    <span class="ip-month-title"><i class="fas fa-layer-group"></i> अन्य कोष</span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-other-fund-value-<?php echo $rowNo; ?>"><?php echo ipShortAmt($otherFund); ?></strong></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(5); ?></span>
                    <span class="ip-month-title"><i class="fas fa-piggy-bank"></i> कुल बचत</span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-deposit-value-<?php echo $rowNo; ?>"><?php echo ipShortAmt((float)$p['deposit']); ?></strong><?php if (!empty($p['deposit_percent'])): ?><em><?php echo htmlspecialchars((string)$p['deposit_percent']); ?>% वृद्धि</em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(6); ?></span>
                    <span class="ip-month-title"><i class="fas fa-hand-holding-dollar"></i> ऋण लगानी</span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-loan-value-<?php echo $rowNo; ?>"><?php echo ipShortAmt((float)$p['loan']); ?></strong><?php if ($totalLoanMembers > 0): ?><em><?php echo number_format($totalLoanMembers); ?> ऋणी सदस्य</em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(7); ?></span>
                    <span class="ip-month-title"><i class="fas fa-money-bill-transfer"></i> बैंक तथा नगद</span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-bank-cash-balance-value-<?php echo $rowNo; ?>"><?php echo ipShortAmt($bankCashBalance); ?></strong></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(8); ?></span>
                    <span class="ip-month-title"><i class="fas fa-building-columns"></i> स्थिर सम्पत्ति</span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-fixed-assets-value-<?php echo $rowNo; ?>"><?php echo ipShortAmt($fixedAssets); ?></strong></span>
                </div>
                <div class="ip-month-ledger-row ip-month-total">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(9); ?></span>
                    <span class="ip-month-title"><i class="fas fa-landmark"></i> कुल सम्पत्ति</span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-total-assets-value-<?php echo $rowNo; ?>"><?php echo ipShortAmt((float)$p['total_assets']); ?></strong></span>
                </div>
            </div>

            <div class="ip-month-tile-foot">
                <?php if (!empty($p['npa_percent'])): ?><span class="ip-mini-chip">NPA <?php echo (float)$p['npa_percent']; ?>%</span><?php endif; ?>
                <?php if (!empty($p['npl_percent'])): ?><span class="ip-mini-chip">NPL <?php echo (float)$p['npl_percent']; ?>%</span><?php endif; ?>
                <?php if (!empty($p['liquidity_percent'])): ?><span class="ip-mini-chip">Liq <?php echo (float)$p['liquidity_percent']; ?>%</span><?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</div><!-- .ip-profile-card -->

<?php endif; /* end profiles check */ ?>

</div><!-- .container -->
</section>


<!-- Document Preview Modal -->
<div id="ipDocModal" data-testid="institutional-profile-document-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:99999;align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)ipCloseDoc()">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:920px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.4);">
    <div style="padding:14px 18px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:10px;">
        <span id="ipDocIcon" style="width:36px;height:36px;border-radius:8px;background:#f0fdf4;display:inline-flex;align-items:center;justify-content:center;color:var(--primary-color,#1a5f2a);font-size:1.1rem;flex-shrink:0;"></span>
        <div>
          <div id="ipDocTitle" style="font-weight:700;color:#1a2e1d;font-size:.95rem;"></div>
          <div id="ipDocSub" style="font-size:.75rem;color:#6b7280;margin-top:1px;"></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <a id="ipDocDlBtn" href="#" download target="_blank"
           style="width:36px;height:36px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:#f0fdf4;color:#166534;text-decoration:none;border:1px solid #bbf7d0;"
           title="<?php echo $isEn ? 'Download' : 'डाउनलोड'; ?>"
           data-testid="institutional-profile-document-download-link">
          <i class="fas fa-download" style="font-size:.85rem;"></i>
        </a>
        <button onclick="ipCloseDoc()"
                style="width:36px;height:36px;border-radius:8px;border:none;background:#f3f4f6;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#6b7280;"
                title="<?php echo $isEn ? 'Close' : 'बन्द'; ?>"
                data-testid="institutional-profile-document-close-button">
          <i class="fas fa-xmark" style="font-size:1rem;"></i>
        </button>
      </div>
    </div>
    <div id="ipDocBody" style="flex:1;overflow:auto;min-height:420px;display:flex;align-items:center;justify-content:center;background:#f9fafb;">
      <div id="ipDocLoader" style="text-align:center;padding:40px;color:#9ca3af;">
        <i class="fas fa-spinner fa-spin fa-2x" style="margin-bottom:12px;display:block;"></i>
        <div style="font-size:.85rem;"><?php echo $isEn ? 'Loading…' : 'लोड हुँदैछ…'; ?></div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    var fyFilter = document.getElementById('ipFiscalYearFilter');
    var monthFilter = document.getElementById('ipMonthFilter');
    var textFilter = document.getElementById('ipFinancialSearch');
    var resetBtn = document.getElementById('ipFilterReset');
    var countEl = document.getElementById('ipFilterCount');
    var emptyEl = document.getElementById('ipFilterEmpty');
    var chips = document.getElementById('ipMonthChips');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.ip-month-grid .ip-month-tile'));
    /* Default: only latest 2 months (current + previous). User can pick All / filters. */
    var viewMode = 'recent2';

    /* Fallback if current/previous markers missing: first 2 tiles in DOM order */
    (function ensureDefaultRecent() {
        var marked = cards.filter(function (c) { return c.getAttribute('data-default-show') === '1'; });
        if (marked.length === 0) {
            cards.slice(0, 2).forEach(function (c) { c.setAttribute('data-default-show', '1'); });
        }
    })();

    function setChipActive(monthVal, fyVal) {
        if (!chips) return;
        Array.prototype.forEach.call(chips.querySelectorAll('.ip-month-chip'), function (chip) {
            var mode = chip.getAttribute('data-mode') || '';
            var cm = chip.getAttribute('data-month');
            var cf = chip.getAttribute('data-fy') || '';
            var on = false;
            if (viewMode === 'recent2') {
                on = mode === 'recent2';
            } else if (viewMode === 'all' || (monthVal === '' && !fyVal)) {
                on = mode === 'all' || (cm === '' && mode !== 'recent2' && !chip.getAttribute('data-fy'));
            } else {
                on = (String(cm) === String(monthVal || '')) && (!cf || !fyVal || cf === fyVal);
                if (monthVal !== '' && cm === String(monthVal) && !chip.getAttribute('data-fy') && mode !== 'recent2' && mode !== 'all') on = true;
            }
            chip.classList.toggle('is-active', on);
        });
    }

    function applyIpFilters() {
        var fy = fyFilter ? (fyFilter.value || '').trim().toLowerCase() : '';
        var month = monthFilter ? (monthFilter.value || '') : '';
        var q = textFilter ? (textFilter.value || '').trim().toLowerCase() : '';
        var userFiltered = !!(fy || month !== '' || q);
        if (userFiltered) {
            viewMode = 'filtered';
        }
        var visible = 0;

        cards.forEach(function (card) {
            var cardFy = (card.getAttribute('data-fy') || '').toLowerCase();
            var cardMonth = card.getAttribute('data-month') || '';
            var blob = (card.getAttribute('data-filter') || '').toLowerCase();
            var fyOk = !fy || cardFy === fy;
            var monthOk = month === '' || cardMonth === String(month);
            var qOk = !q || blob.indexOf(q) !== -1;
            var show = fyOk && monthOk && qOk;
            if (viewMode === 'recent2' && !userFiltered) {
                show = card.getAttribute('data-default-show') === '1';
            }
            card.classList.toggle('is-hidden', !show);
            if (show) visible++;
        });

        if (countEl) {
            countEl.textContent = (visible + ' / ' + cards.length + ' ' + '<?php echo $isEn ? 'shown' : 'देखाइयो'; ?>');
        }
        if (emptyEl) {
            emptyEl.style.display = visible === 0 ? 'block' : 'none';
        }
        setChipActive(month, fyFilter ? fyFilter.value : '');
    }

    if (fyFilter) fyFilter.addEventListener('change', applyIpFilters);
    if (monthFilter) monthFilter.addEventListener('change', applyIpFilters);
    if (textFilter) textFilter.addEventListener('input', applyIpFilters);
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (fyFilter) fyFilter.value = '';
            if (monthFilter) monthFilter.value = '';
            if (textFilter) textFilter.value = '';
            viewMode = 'recent2';
            applyIpFilters();
            if (textFilter) textFilter.focus();
        });
    }
    if (chips) {
        chips.addEventListener('click', function (e) {
            var btn = e.target.closest('.ip-month-chip');
            if (!btn) return;
            var mode = btn.getAttribute('data-mode') || '';
            var m = btn.getAttribute('data-month');
            var f = btn.getAttribute('data-fy') || '';
            if (mode === 'recent2') {
                viewMode = 'recent2';
                if (fyFilter) fyFilter.value = '';
                if (monthFilter) monthFilter.value = '';
                if (textFilter) textFilter.value = '';
            } else if (mode === 'all' || (m === '' && mode !== 'recent2')) {
                viewMode = 'all';
                if (monthFilter) monthFilter.value = '';
                if (fyFilter) fyFilter.value = '';
            } else {
                viewMode = 'filtered';
                if (monthFilter) monthFilter.value = (m === null || m === undefined) ? '' : String(m);
                if (fyFilter) fyFilter.value = f;
            }
            applyIpFilters();
        });
    }
    applyIpFilters();

    function ipOpenDoc(url, ext) {
        var modal  = document.getElementById('ipDocModal');
        var body   = document.getElementById('ipDocBody');
        var loader = document.getElementById('ipDocLoader');
        var dlBtn  = document.getElementById('ipDocDlBtn');
        var title  = document.getElementById('ipDocTitle');
        var sub    = document.getElementById('ipDocSub');
        var icon   = document.getElementById('ipDocIcon');
        var isImg  = ['jpg','jpeg','png','gif','webp'].indexOf(ext) !== -1;

        dlBtn.href = url;
        if (isImg) {
            icon.innerHTML  = '<i class="fas fa-image"></i>';
            title.textContent = '<?php echo $isEn ? 'Image Document' : 'छवि कागजात'; ?>';
            sub.textContent = ext.toUpperCase();
        } else {
            icon.innerHTML  = '<i class="fas fa-file-pdf"></i>';
            title.textContent = '<?php echo $isEn ? 'PDF Document' : 'PDF कागजात'; ?>';
            sub.textContent = 'PDF';
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        body.innerHTML = '';
        body.appendChild(loader);
        loader.style.display = 'block';

        if (isImg) {
            var img = document.createElement('img');
            img.src = url;
            img.alt = '<?php echo $isEn ? 'Document Preview' : 'कागजात पूर्वावलोकन'; ?>';
            img.style.cssText = 'max-width:100%;max-height:75vh;border-radius:8px;display:block;padding:16px;';
            img.onload  = function () { loader.style.display = 'none'; body.style.justifyContent = 'center'; };
            img.onerror = function () { loader.innerHTML = '<i class="fas fa-triangle-exclamation fa-2x" style="color:#dc2626;margin-bottom:12px;display:block;"></i><div><?php echo $isEn ? 'Could not load image.' : 'छवि लोड भएन।'; ?></div>'; };
            body.appendChild(img);
        } else {
            var iframe = document.createElement('iframe');
            iframe.src   = url;
            iframe.title = '<?php echo $isEn ? 'PDF Preview' : 'PDF पूर्वावलोकन'; ?>';
            iframe.style.cssText = 'width:100%;height:75vh;border:0;display:block;';
            iframe.onload = function () { loader.style.display = 'none'; };
            body.style.justifyContent = 'flex-start';
            body.appendChild(iframe);
        }
    }

    function ipCloseDoc() {
        var modal = document.getElementById('ipDocModal');
        modal.style.display = 'none';
        document.getElementById('ipDocBody').innerHTML = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') ipCloseDoc();
    });

    window.ipOpenDoc  = ipOpenDoc;
    window.ipCloseDoc = ipCloseDoc;
}());
</script>

<?php require_once 'includes/footer.php'; ?>
