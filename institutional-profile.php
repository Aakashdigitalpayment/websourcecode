<?php
/**
 * Public Page: संस्थागत प्रोफाइल
 * File: institutional-profile.php
 *
 * Admin मा थपिएको financial data यहाँ public page मा देखाइन्छ।
 * Admin: admin/institutional-profile.php बाट manage गर्नुहोस्।
 */
require_once 'includes/config.php';
require_once __DIR__ . '/includes/institutional-profile-helpers.php';
require_once __DIR__ . '/includes/institutional-profile-welfare.php';
require_once __DIR__ . '/includes/public-member-access.php';
if (is_file(__DIR__ . '/includes/nepali-bs-convert.php')) {
    require_once __DIR__ . '/includes/nepali-bs-convert.php';
}

$pmaUnlock = coopMemberAccessProcessUnlockRequest();
if (!empty($pmaUnlock['handled']) && !empty($pmaUnlock['ok'])) {
    $retPath = (string) ($pmaUnlock['return'] ?? '');
    if ($retPath === '') {
        $retPath = '/institutional-profile.php';
        if (!empty($_GET['id']) && (int) $_GET['id'] > 0) {
            $retPath .= '?id=' . (int) $_GET['id'];
        }
    }
    header('Location: ' . rtrim((string) SITE_URL, '/') . $retPath);
    exit;
}
$pmaUnlockError = !empty($pmaUnlock['handled']) ? (string) ($pmaUnlock['error'] ?? '') : '';
$pmaUnlocked = coopMemberAccessUnlocked();

$pageTitle = isEnglish() ? 'Institutional Profile' : 'संस्थागत प्रोफाइल';
$pageDescription = isEnglish()
    ? 'Key institutional indicators and financial profile of our cooperative.'
    : 'हाम्रो सहकारीको संस्थागत सूचक तथा वित्तीय प्रोफाइल।';
$extraHead = (isset($extraHead) ? (string) $extraHead : '')
    . (function_exists('coopThemeLinkHtml')
        ? coopThemeLinkHtml('assets/css/institutional-profile.css')
        : '');
require_once 'includes/header.php';
$L = getLangStrings();

/* ─── Helpers (thin wrappers → shared coopIp*) ─── */
function ipShortAmt(float $v): string {
    return coopIpShortAmt($v);
}

/** Full ledger amount (same as share poster) — no करोड shorthand. */
function ipFullAmt(float $v): string {
    return coopIpFormatAmtFull($v, isEnglish());
}

function ipNepaliNumber(int $number): string {
    return strtr((string)$number, ['0'=>'०','1'=>'१','2'=>'२','3'=>'३','4'=>'४','5'=>'५','6'=>'६','7'=>'७','8'=>'८','9'=>'९']);
}

function ipMonthLabel(int $m, bool $en = false): string {
    if ($m < 1 || $m > 12) {
        return $en ? 'Annual / Unset' : 'वार्षिक / नखुलेको';
    }
    return coopIpMonthLabel($m, $en);
}

function ipResolveMonth(array $p): int {
    return coopIpResolveMonth($p);
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
    $tableExists = function_exists('dbTableExists')
        ? dbTableExists('institutional_profile')
        : false;
    if (!$tableExists && !function_exists('dbTableExists')) {
        $r = $db->query("SHOW TABLES LIKE 'institutional_profile'");
        $tableExists = ($r && $r->rowCount() > 0);
    }
    if ($tableExists) {
        try {
            if (function_exists('safeAddColumn')) {
                safeAddColumn($db, 'institutional_profile', 'report_month', "TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'BS month 1-12'");
            } else {
                $db->exec("ALTER TABLE institutional_profile ADD COLUMN report_month TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'BS month 1-12'");
            }
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
$ipChartSeries = ['labels' => [], 'deposit' => [], 'loan' => [], 'assets' => [], 'members' => [], 'count' => 0];
$ipSiteName = trim((string) (function_exists('getSetting')
    ? getSetting($isEn ? 'site_name_en' : 'site_name', getSetting('site_name', 'सहकारी'))
    : 'सहकारी'));
$ipSiteLogo = '';
if (function_exists('getLocalizedLogoPath')) {
    $ipSiteLogo = trim((string) getLocalizedLogoPath(''));
} elseif (function_exists('getSetting')) {
    $ipSiteLogo = trim((string) getSetting($isEn ? 'logo_en' : 'logo_np', getSetting('site_logo', getSetting('logo', ''))));
}
if ($ipSiteLogo !== '' && function_exists('safe_versioned_media_src')) {
    $ipSiteLogo = safe_versioned_media_src($ipSiteLogo);
} elseif ($ipSiteLogo !== '' && function_exists('getAssetUrl')) {
    $ipSiteLogo = getAssetUrl(ltrim($ipSiteLogo, '/'));
}
$ipWelfareByUpto = [];
foreach ($profiles as &$_pRow) {
    $_pRow['_month'] = ipResolveMonth($_pRow);
    $fy = trim((string)($_pRow['fiscal_year'] ?? ''));
    if ($fy !== '') $fiscalYears[$fy] = true;
    if ($_pRow['_month'] > 0) $monthSet[$_pRow['_month']] = true;
    $upto = coopIpProfileUptoAdDate($_pRow);
    $_pRow['_upto_ad'] = $upto;
    $_pid = (int)($_pRow['id'] ?? 0);
    $cacheKey = $_pid > 0 ? ('id:' . $_pid) : ('upto:' . $upto);
    if (!isset($ipWelfareByUpto[$cacheKey]) && isset($db) && $db instanceof PDO) {
        $ipWelfareByUpto[$cacheKey] = coopIpWelfareResolveForProfile($db, $_pRow, $isEn);
    }
    $_pRow['_welfare'] = $ipWelfareByUpto[$cacheKey] ?? [];
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


<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $isEn ? 'Institutional Profile' : 'संस्थागत प्रोफाइल'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $L['home'] ?? 'गृहपृष्ठ'; ?></a></li>
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
        <i class="lucide-icon lucide-2x" data-lucide="landmark" aria-hidden="true" style="color:var(--primary-color);"></i>
    </div>
    <h4 style="color:var(--primary-color);"><?php echo $isEn ? 'Institutional profile not available yet' : 'संस्थागत प्रोफाइल उपलब्ध छैन'; ?></h4>
    <p class="text-muted"><?php echo $isEn ? 'Will be available soon.' : 'छिट्टै उपलब्ध हुनेछ।'; ?></p>
</div>

<?php else: ?>

<div class="ip-section-intro text-center mb-4">
    <span class="ip-section-kicker"><i class="lucide-icon" data-lucide="trending-up" aria-hidden="true"></i> <?php echo $isEn ? 'Financial stats' : 'आर्थिक तथ्याङ्क'; ?></span>
    <h2><?php echo $isEn ? 'Institutional financial profile' : 'संस्थाको आर्थिक प्रोफाइल'; ?></h2>
    <p><?php echo $isEn
        ? 'Shows the latest two months by default — use filters or “All” to browse more.'
        : 'पहिले हालका २ महिना मात्र देखिन्छ — अरू हेर्न फिल्टर वा “सबै” प्रयोग गर्नुहोस्।'; ?></p>
</div>

<?php
$ipCanViewProfile = static function (?array $p) use ($pmaUnlocked): bool {
    if (!$p) {
        return true;
    }
    $lvl = function_exists('coopAccessLevelNormalize')
        ? coopAccessLevelNormalize((string) ($p['access_level'] ?? 'none'))
        : 'none';
    return $lvl !== 'member' || !empty($pmaUnlocked);
};
$renderFeatured = static function (?array $p, string $kicker, string $title, string $sub, bool $isCurrent, bool $isEn) use ($ipCanViewProfile): void {
    if (!$p) {
        echo '<div class="ip-featured-card ip-featured-empty">';
        echo '<span class="ip-featured-kicker"><i class="lucide-icon" data-lucide="clock" aria-hidden="true"></i> ' . htmlspecialchars($kicker) . '</span>';
        echo '<h3 class="ip-featured-title">' . htmlspecialchars($title) . '</h3>';
        echo '<p class="ip-featured-sub">' . ($isEn ? 'Data not published for this month yet.' : 'यो महिनाको डाटा अझै प्रकाशित भएको छैन।') . '</p>';
        echo '</div>';
        return;
    }
    $cls = $isCurrent ? 'ip-featured-card is-current' : 'ip-featured-card';
    echo '<article class="' . $cls . '">';
    echo '<span class="ip-featured-kicker"><i class="lucide-icon" aria-hidden="true" data-lucide="' . ($isCurrent ? 'zap' : 'history') . '"></i> ' . htmlspecialchars($kicker) . '</span>';
    echo '<h3 class="ip-featured-title">' . htmlspecialchars($title) . '</h3>';
    echo '<p class="ip-featured-sub">' . htmlspecialchars($sub) . '</p>';
    if (!$ipCanViewProfile($p)) {
        echo '<div class="ip-featured-locked"><i class="lucide-icon" data-lucide="lock" aria-hidden="true"></i> '
            . htmlspecialchars($isEn ? 'Members only — unlock below' : 'सदस्य मात्र — तल अनलक गर्नुहोस्')
            . '</div>';
        echo '</article>';
        return;
    }
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
            ($isEn ? 'FY ' : 'आ.व. ') . ($fp['fiscal_year'] ?? '') . ' · ' . ipMonthLabel($fm, $isEn),
            !empty($fp['report_date_bs']) ? (($isEn ? 'As of ' : 'मिति ') . $fp['report_date_bs']) : '',
            $fi === 0,
            $isEn
        );
    endforeach; ?>
<?php endif; ?>
</div>

<?php
$chartProfiles = array_values(array_filter($profiles, static function (array $p) use ($pmaUnlocked): bool {
    $lvl = function_exists('coopAccessLevelNormalize')
        ? coopAccessLevelNormalize((string) ($p['access_level'] ?? 'none'))
        : 'none';
    return $lvl !== 'member' || !empty($pmaUnlocked);
}));
$ipChartSeries = coopIpBuildChartSeries($chartProfiles, 12, $isEn);
if ($ipChartSeries['count'] >= 2):
?>
<div class="ip-charts-section" data-aos="fade-up" data-testid="institutional-profile-charts">
    <div class="ip-charts-head">
        <span class="ip-section-kicker"><i class="lucide-icon" data-lucide="chart-column" aria-hidden="true"></i> <?php echo $isEn ? 'Trend charts' : 'प्रवृत्ति चार्ट'; ?></span>
        <h3><?php echo $isEn ? 'Financial growth over recent months' : 'हालका महिनाहरूमा आर्थिक प्रवृत्ति'; ?></h3>
        <p><?php echo $isEn
            ? 'Based on published monthly institutional profile data (newest months on the right).'
            : 'प्रकाशित महिनागत संस्थागत प्रोफाइल डाटाबाट (दायाँ = नवीनतम)।'; ?></p>
    </div>
    <div class="ip-charts-grid">
        <div class="ip-chart-card">
            <h4 class="ip-chart-title"><i class="lucide-icon" data-lucide="trending-up" aria-hidden="true"></i> <?php echo $isEn ? 'Deposits, loans & assets' : 'बचत, ऋण र सम्पत्ति'; ?></h4>
            <div class="ip-chart-canvas-wrap">
                <canvas id="ipChartFinancial" role="img" aria-label="<?php echo $isEn ? 'Financial trend chart' : 'आर्थिक प्रवृत्ति चार्ट'; ?>"></canvas>
            </div>
        </div>
        <div class="ip-chart-card">
            <h4 class="ip-chart-title"><i class="lucide-icon" data-lucide="users" aria-hidden="true"></i> <?php echo $isEn ? 'Total members' : 'कुल सदस्य संख्या'; ?></h4>
            <div class="ip-chart-canvas-wrap ip-chart-canvas-wrap--sm">
                <canvas id="ipChartMembers" role="img" aria-label="<?php echo $isEn ? 'Member count trend chart' : 'सदस्य संख्या प्रवृत्ति चार्ट'; ?>"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="ip-profile-card mb-3 ip-month-card">
    <div class="ip-card-header">
        <div class="ip-card-title-wrap">
            <div class="ip-fy-badge"><i class="lucide-icon me-2" data-lucide="table" aria-hidden="true"></i> <?php echo $isEn ? 'Month-wise financial details' : 'महिनागत आर्थिक विवरण'; ?></div>
            <div class="ip-date-info"><span><?php echo $isEn ? 'Default: latest 2 months — filter for more' : 'पूर्वनिर्धारित: हालका २ महिना — अरू फिल्टरबाट'; ?></span></div>
        </div>
    </div>

    <div class="ip-filter-wrap" data-testid="institutional-profile-filters">
        <div class="ip-filter-bar">
            <select id="ipFiscalYearFilter" class="ip-filter-select" aria-label="Fiscal Year Filter">
                <option value=""><?php echo $isEn ? 'All Fiscal Years' : 'सबै आ.व.'; ?></option>
                <?php foreach ($fiscalYears as $fy): ?>
                <option value="<?php echo htmlspecialchars($fy, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $isEn ? 'FY ' : 'आ.व. '; ?><?php echo htmlspecialchars($fy, ENT_QUOTES, 'UTF-8'); ?></option>
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
                <i class="lucide-icon me-1" data-lucide="rotate-ccw" aria-hidden="true"></i><?php echo $isEn ? 'Reset' : 'रिसेट'; ?>
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
            <i class="lucide-icon me-1" data-lucide="filter-x" aria-hidden="true"></i><?php echo $isEn ? 'No matching record found.' : 'मिल्दो रेकर्ड भेटिएन।'; ?>
        </div>
    </div>

    <div class="ip-month-grid" data-coop-pma-refresh="ip" data-testid="institutional-profile-month-wise-grid">
        <?php foreach ($profiles as $idx => $p): ?>
        <?php
            $rowNo = $idx + 1;
            $rm = (int)($p['_month'] ?? 0);
            $pid = (int)($p['id'] ?? 0);
            $accessLevel = function_exists('coopAccessLevelNormalize')
                ? coopAccessLevelNormalize((string)($p['access_level'] ?? 'none'))
                : 'none';
            $isMemberOnly = ($accessLevel === 'member');
            $canOpenIp = !$isMemberOnly || !empty($pmaUnlocked);
            $totalLoanMembers = (int)($p['total_loan_members'] ?? 0);
            $otherFund = (float)($p['other_fund'] ?? 0);
            $bankCashBalance = (float)($p['bank_cash_balance'] ?? 0);
            $fixedAssets = (float)($p['fixed_assets'] ?? 0);
            $_ipDocUrl = '';
            $_ipDocDlUrl = '';
            $_ipDocExt = '';
            if (!empty($p['attachment_path']) && $canOpenIp && $pid > 0 && function_exists('coopMemberAccessFileUrl')) {
                $_ipDocUrl = htmlspecialchars(coopMemberAccessFileUrl('institutional-profile-file.php', $pid, false), ENT_QUOTES, 'UTF-8');
                $_ipDocDlUrl = htmlspecialchars(coopMemberAccessFileUrl('institutional-profile-file.php', $pid, true), ENT_QUOTES, 'UTF-8');
                $_ipDocExt = strtolower(pathinfo((string)$p['attachment_path'], PATHINFO_EXTENSION));
            }
            $_fy = trim((string)($p['fiscal_year'] ?? ''));
            $_dateBs = trim((string)($p['report_date_bs'] ?? ''));
            $_monthName = ipMonthLabel($rm, $isEn);
            $_filterText = strtolower(trim($_fy . ' ' . $_monthName . ' ' . $_dateBs . ' ' . ($isEn
                ? 'members share capital reserve fund institutional capital other funds deposits loan investment liquidity bank cash fixed assets total assets welfare relief facilities'
                : 'कुल सदस्य शेयर पूँजी जगेडा कोष कुल बचत ऋण लगानी बैंक नगद स्थिर सम्पत्ति कुल सम्पत्ति राहत कल्याण welfare')));
            $isCur = ($currentProfile && (int)($currentProfile['id'] ?? 0) === $pid);
            $isPrev = ($previousProfile && (int)($previousProfile['id'] ?? 0) === $pid);
            $tileCls = 'ip-month-tile' . ($isCur || $isPrev ? ' is-highlight' : '') . ($isMemberOnly && !$canOpenIp ? ' is-member-locked' : '');
            $defaultShow = ($isCur || $isPrev) ? '1' : '0';
            $ipDeepLink = rtrim((string) SITE_URL, '/') . '/institutional-profile.php' . ($pid > 0 ? ('?id=' . $pid) : '');
        ?>
        <article class="<?php echo $tileCls; ?>"
                 id="ip-profile-<?php echo $pid; ?>"
                 data-ip-id="<?php echo $pid; ?>"
                 data-fy="<?php echo htmlspecialchars($_fy, ENT_QUOTES, 'UTF-8'); ?>"
                 data-month="<?php echo (int)$rm; ?>"
                 data-default-show="<?php echo $defaultShow; ?>"
                 data-filter="<?php echo htmlspecialchars($_filterText, ENT_QUOTES, 'UTF-8'); ?>"
                 data-testid="institutional-profile-month-card-<?php echo $rowNo; ?>">
            <div class="ip-month-tile-head">
                <div>
                    <strong data-testid="institutional-profile-fiscal-year-<?php echo $rowNo; ?>"><?php echo $isEn ? 'FY ' : 'आ.व. '; ?><?php echo htmlspecialchars($p['fiscal_year']); ?></strong>
                    <span class="ip-month-badge"><i class="lucide-icon" data-lucide="calendar-range" aria-hidden="true"></i> <?php echo htmlspecialchars($_monthName); ?></span>
                    <?php if ($isMemberOnly): ?>
                    <span class="ip-member-badge"><i class="lucide-icon" data-lucide="lock" aria-hidden="true"></i> <?php echo $isEn ? 'Members' : 'सदस्य'; ?></span>
                    <?php endif; ?>
                    <?php if (!empty($p['report_date_bs'])): ?>
                    <span data-testid="institutional-profile-published-date-<?php echo $rowNo; ?>"><?php echo htmlspecialchars($p['report_date_bs']); ?><?php if (!empty($p['report_date_ad'])): ?> / <?php echo date('d M Y', strtotime($p['report_date_ad'])); ?><?php endif; ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($p['attachment_path']) && $canOpenIp && $_ipDocUrl !== ''): ?>
                <button type="button" class="ip-row-doc-btn"
                        onclick="ipOpenDoc('<?php echo $_ipDocUrl; ?>','<?php echo $_ipDocExt; ?>','<?php echo $_ipDocDlUrl; ?>')"
                        data-testid="institutional-profile-document-button-<?php echo $rowNo; ?>"
                        title="कागजात हेर्नुहोस्">
                    <i class="lucide-icon" data-lucide="<?php echo $_ipDocExt === 'pdf' ? 'file-text' : 'image'; ?>" aria-hidden="true"></i>
                </button>
                <?php elseif (!empty($p['attachment_path']) && !$canOpenIp): ?>
                <button type="button" class="ip-row-doc-btn ip-row-doc-locked" data-pma-toggle="1"
                        title="<?php echo $isEn ? 'Members only' : 'सदस्य मात्र'; ?>">
                    <i class="lucide-icon" data-lucide="lock" aria-hidden="true"></i>
                </button>
                <?php endif; ?>
            </div>

            <?php if ($isMemberOnly && !$canOpenIp): ?>
            <div class="ip-month-locked-body">
                <p class="ip-month-locked-msg">
                    <?php echo $isEn
                        ? 'Financial details, documents, and share are available to members only.'
                        : 'वित्तीय विवरण, कागजात र सेयर सदस्यका लागि मात्र उपलब्ध छ।'; ?>
                </p>
                <?php if ($pmaUnlockError !== ''): ?>
                <div class="coop-pma-error" role="alert"><?php echo htmlspecialchars($pmaUnlockError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <?php echo coopMemberAccessUnlockFormHtml(
                    'institutional-profile.php' . ($pid > 0 ? ('?id=' . $pid) : ''),
                    'ip-pma-unlock',
                    'ip' . $pid
                ); ?>
            </div>
            <?php else: ?>
            <div class="ip-month-ledger">
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(1); ?></span>
                    <span class="ip-month-title"><i class="lucide-icon" data-lucide="users" aria-hidden="true"></i> <?php echo $isEn ? 'Total members' : 'कुल सदस्य'; ?></span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-total-members-value-<?php echo $rowNo; ?>"><?php echo number_format((int)$p['total_members']); ?></strong><?php if (!empty($p['total_balance_member'])): ?><em><?php echo number_format((int)$p['total_balance_member']); ?> <?php echo $isEn ? 'active' : 'शेष'; ?></em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(2); ?></span>
                    <span class="ip-month-title"><i class="lucide-icon" data-lucide="coins" aria-hidden="true"></i> <?php echo $isEn ? 'Share capital' : 'शेयर पूँजी'; ?></span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-share-capital-value-<?php echo $rowNo; ?>"><?php echo htmlspecialchars(ipFullAmt((float)$p['share_capital']), ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($p['share_capital_percent'])): ?><em><?php echo htmlspecialchars((string)$p['share_capital_percent']); ?>% <?php echo $isEn ? 'growth' : 'वृद्धि'; ?></em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(3); ?></span>
                    <span class="ip-month-title"><i class="lucide-icon" data-lucide="shield" aria-hidden="true"></i> <?php echo $isEn ? 'Reserve fund' : 'जगेडा कोष'; ?></span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-reserved-fund-value-<?php echo $rowNo; ?>"><?php echo htmlspecialchars(ipFullAmt((float)($p['reserved_fund'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($p['reserved_fund_percent'])): ?><em><?php echo htmlspecialchars((string)$p['reserved_fund_percent']); ?>% <?php echo $isEn ? 'growth' : 'वृद्धि'; ?></em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(4); ?></span>
                    <span class="ip-month-title"><i class="lucide-icon" data-lucide="building-2" aria-hidden="true"></i> <?php echo $isEn ? 'Institutional capital' : 'कुल संस्थागत पूँजी'; ?></span>
                    <span class="ip-month-value"><strong><?php echo htmlspecialchars(ipFullAmt((float)($p['reserved_fund'] ?? 0) + (float)($p['other_fund'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></strong><em><?php echo $isEn ? 'Reserve + other' : 'जगेडा + अन्य'; ?></em></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(5); ?></span>
                    <span class="ip-month-title"><i class="lucide-icon" data-lucide="layers" aria-hidden="true"></i> <?php echo $isEn ? 'Other funds' : 'अन्य कोष'; ?></span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-other-fund-value-<?php echo $rowNo; ?>"><?php echo htmlspecialchars(ipFullAmt($otherFund), ENT_QUOTES, 'UTF-8'); ?></strong></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(6); ?></span>
                    <span class="ip-month-title"><i class="lucide-icon" data-lucide="piggy-bank" aria-hidden="true"></i> <?php echo $isEn ? 'Total deposits' : 'कुल बचत'; ?></span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-deposit-value-<?php echo $rowNo; ?>"><?php echo htmlspecialchars(ipFullAmt((float)$p['deposit']), ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($p['deposit_percent'])): ?><em><?php echo htmlspecialchars((string)$p['deposit_percent']); ?>% <?php echo $isEn ? 'growth' : 'वृद्धि'; ?></em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(7); ?></span>
                    <span class="ip-month-title"><i class="lucide-icon" data-lucide="banknote" aria-hidden="true"></i> <?php echo $isEn ? 'Loan investment' : 'लगानीमा रहेको ऋण'; ?></span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-loan-value-<?php echo $rowNo; ?>"><?php echo htmlspecialchars(ipFullAmt((float)$p['loan']), ENT_QUOTES, 'UTF-8'); ?></strong><?php if ($totalLoanMembers > 0): ?><em><?php echo number_format($totalLoanMembers); ?> <?php echo $isEn ? 'borrowers' : 'ऋणी सदस्य'; ?></em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(8); ?></span>
                    <span class="ip-month-title"><i class="lucide-icon" data-lucide="wallet" aria-hidden="true"></i> <?php echo $isEn ? 'Liquidity (bank & cash)' : 'तरलता (बैंक तथा नगद)'; ?></span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-bank-cash-balance-value-<?php echo $rowNo; ?>"><?php echo htmlspecialchars(ipFullAmt($bankCashBalance), ENT_QUOTES, 'UTF-8'); ?></strong><?php if (!empty($p['liquidity_percent'])): ?><em><?php echo (float)$p['liquidity_percent']; ?>%</em><?php endif; ?></span>
                </div>
                <div class="ip-month-ledger-row">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(9); ?></span>
                    <span class="ip-month-title"><i class="lucide-icon" data-lucide="landmark" aria-hidden="true"></i> <?php echo $isEn ? 'Fixed assets' : 'स्थिर सम्पत्ति'; ?></span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-fixed-assets-value-<?php echo $rowNo; ?>"><?php echo htmlspecialchars(ipFullAmt($fixedAssets), ENT_QUOTES, 'UTF-8'); ?></strong></span>
                </div>
                <div class="ip-month-ledger-row ip-month-total">
                    <span class="ip-month-sn"><?php echo ipNepaliNumber(10); ?></span>
                    <span class="ip-month-title"><i class="lucide-icon" data-lucide="landmark" aria-hidden="true"></i> <?php echo $isEn ? 'Total assets' : 'कुल सम्पत्ति'; ?></span>
                    <span class="ip-month-value"><strong data-testid="institutional-profile-total-assets-value-<?php echo $rowNo; ?>"><?php echo htmlspecialchars(ipFullAmt((float)$p['total_assets']), ENT_QUOTES, 'UTF-8'); ?></strong></span>
                </div>
            </div>

            <?php
            $welfareRows = is_array($p['_welfare'] ?? null) ? $p['_welfare'] : [];
            $welfareCountSum = 0;
            $welfareAmtSum = 0.0;
            foreach ($welfareRows as $_wr) {
                $welfareCountSum += (int) ($_wr['count'] ?? 0);
                $welfareAmtSum += (float) ($_wr['amount'] ?? 0);
            }
            $posterPayload = [
                'site' => $ipSiteName,
                'logo' => $ipSiteLogo,
                'fy' => $_fy,
                'month' => $_monthName,
                'dateBs' => $_dateBs,
                'dateAd' => trim((string) ($p['report_date_ad'] ?? ($p['_upto_ad'] ?? ''))),
                'finance' => [
                    ['label' => $isEn ? 'Share capital' : 'शेयर पूँजी', 'value' => coopIpFormatAmtFull((float) ($p['share_capital'] ?? 0), $isEn)],
                    ['label' => $isEn ? 'Reserve fund' : 'जगेडा कोष', 'value' => coopIpFormatAmtFull((float) ($p['reserved_fund'] ?? 0), $isEn)],
                    ['label' => $isEn ? 'Institutional capital' : 'कुल संस्थागत पूँजी', 'value' => coopIpFormatAmtFull((float) ($p['reserved_fund'] ?? 0) + (float) ($p['other_fund'] ?? 0), $isEn)],
                    ['label' => $isEn ? 'Other funds' : 'अन्य कोषहरुको रकम', 'value' => coopIpFormatAmtFull($otherFund, $isEn)],
                    ['label' => $isEn ? 'Total deposits' : 'कुल बचत', 'value' => coopIpFormatAmtFull((float) ($p['deposit'] ?? 0), $isEn)],
                    ['label' => $isEn ? 'Loan investment' : 'लगानीमा रहेको ऋण', 'value' => coopIpFormatAmtFull((float) ($p['loan'] ?? 0), $isEn)],
                    ['label' => $isEn ? 'Total assets' : 'कुल सम्पत्ति', 'value' => coopIpFormatAmtFull((float) ($p['total_assets'] ?? 0), $isEn)],
                ],
                'stats' => [
                    ['label' => $isEn ? 'Liquidity amount' : 'तरलता रकम', 'value' => coopIpFormatAmtFull($bankCashBalance, $isEn)],
                    ['label' => $isEn ? 'Liquidity %' : 'तरलता प्रतिशत', 'value' => !empty($p['liquidity_percent']) ? ((float) $p['liquidity_percent'] . '%') : '—'],
                    ['label' => $isEn ? 'Members' : 'सदस्य संख्या', 'value' => number_format((int) ($p['total_members'] ?? 0))],
                    ['label' => $isEn ? 'Borrower members' : 'कुल ऋणी सदस्य', 'value' => number_format($totalLoanMembers)],
                ],
                'welfare' => array_map(static function ($r) use ($isEn) {
                    return [
                        'label' => (string) ($r['label'] ?? ''),
                        'count' => (int) ($r['count'] ?? 0),
                        'amount' => coopIpFormatAmtFull((float) ($r['amount'] ?? 0), $isEn),
                    ];
                }, $welfareRows),
                'welfareTotalCount' => $welfareCountSum,
                'welfareTotalAmount' => coopIpFormatAmtFull($welfareAmtSum, $isEn),
                'pageUrl' => $ipDeepLink,
                'memberOnly' => $isMemberOnly,
                'fileUrl' => (!$isMemberOnly && $_ipDocUrl !== '') ? html_entity_decode($_ipDocUrl, ENT_QUOTES, 'UTF-8') : '',
            ];
            $posterJson = htmlspecialchars((string) json_encode($posterPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            ?>

            <?php if (!empty($welfareRows)): ?>
            <div class="ip-relief-block" data-testid="institutional-profile-relief-<?php echo $rowNo; ?>">
                <div class="ip-relief-head">
                    <strong><i class="lucide-icon" data-lucide="heart-handshake" aria-hidden="true"></i> <?php echo $isEn ? 'Member welfare facilities' : 'सदस्य राहत / कल्याण सुविधा'; ?></strong>
                    <span><?php echo $isEn ? 'Published report + opening / member-welfare' : 'प्रकाशित प्रोफाइल / Opening + member-welfare'; ?></span>
                </div>
                <div class="ip-relief-table-wrap">
                    <table class="ip-relief-table">
                        <thead>
                            <tr>
                                <th><?php echo $isEn ? 'Facility' : 'सुविधाको प्रकार'; ?></th>
                                <th><?php echo $isEn ? 'Count' : 'संख्या'; ?></th>
                                <th><?php echo $isEn ? 'Amount' : 'रकम'; ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($welfareRows as $wr): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $wr['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format((int) $wr['count']); ?></td>
                                <td><?php echo htmlspecialchars(coopIpFormatAmtFull((float) $wr['amount'], $isEn), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th><?php echo $isEn ? 'Total' : 'जम्मा'; ?></th>
                                <th><?php echo number_format($welfareCountSum); ?></th>
                                <th><?php echo htmlspecialchars(coopIpFormatAmtFull($welfareAmtSum, $isEn), ENT_QUOTES, 'UTF-8'); ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="ip-month-tile-foot">
                <?php if (!empty($p['npa_percent'])): ?><span class="ip-mini-chip">NPA <?php echo (float)$p['npa_percent']; ?>%</span><?php endif; ?>
                <?php if (!empty($p['npl_percent'])): ?><span class="ip-mini-chip">NPL <?php echo (float)$p['npl_percent']; ?>%</span><?php endif; ?>
                <?php if (!empty($p['liquidity_percent'])): ?><span class="ip-mini-chip">Liq <?php echo (float)$p['liquidity_percent']; ?>%</span><?php endif; ?>
                <button type="button"
                        class="ip-share-btn"
                        data-ip-poster="<?php echo $posterJson; ?>"
                        title="<?php echo $isEn ? 'Share monthly report' : 'मासिक विवरण सेयर गर्नुहोस्'; ?>"
                        aria-label="<?php echo $isEn ? 'Share monthly institutional report' : 'मासिक संस्थागत विवरण सेयर'; ?>">
                    <i class="lucide-icon" data-lucide="share-2" aria-hidden="true"></i>
                    <span><?php echo $isEn ? 'Share' : 'सेयर'; ?></span>
                </button>
            </div>
            <?php endif; /* member lock else */ ?>
        </article>
        <?php endforeach; ?>
    </div>
</div><!-- .ip-profile-card -->

<?php endif; /* end profiles check */ ?>

</div><!-- .container -->
</section>


<!-- Monthly share poster (social-ready layout) -->
<div id="ipPosterModal" class="ip-poster-modal" hidden data-testid="institutional-profile-share-modal">
  <div class="ip-poster-dialog" role="dialog" aria-modal="true" aria-labelledby="ipPosterTitle">
    <div class="ip-poster-toolbar">
      <strong id="ipPosterTitle"><?php echo $isEn ? 'Monthly institutional report' : 'मासिक संस्थागत विवरण'; ?></strong>
      <div class="ip-poster-toolbar-actions">
        <button type="button" class="ip-poster-tool-btn" id="ipPosterNativeShare"><?php echo $isEn ? 'Share' : 'सेयर'; ?></button>
        <button type="button" class="ip-poster-tool-btn" id="ipPosterCopy"><?php echo $isEn ? 'Copy text' : 'पाठ कपी'; ?></button>
        <button type="button" class="ip-poster-tool-btn" id="ipPosterPrint"><?php echo $isEn ? 'Print / PDF' : 'प्रिन्ट / PDF'; ?></button>
        <button type="button" class="ip-poster-tool-btn ip-poster-close" id="ipPosterClose" aria-label="<?php echo $isEn ? 'Close' : 'बन्द'; ?>">×</button>
      </div>
    </div>
    <div class="ip-poster-scroll">
      <article class="ip-poster-sheet" id="ipPosterSheet">
        <header class="ip-poster-brand">
          <div class="ip-poster-brand-row">
            <img id="ipPosterLogo" class="ip-poster-logo" alt="" hidden>
            <h2 id="ipPosterSite" class="ip-poster-site" hidden></h2>
            <p id="ipPosterPeriod" class="ip-poster-period"></p>
          </div>
          <div class="ip-poster-ribbon" id="ipPosterRibbon"></div>
        </header>

        <section class="ip-poster-section">
          <h3><?php echo $isEn ? 'Financial & other details' : 'वित्तीय तथा अन्य विवरण'; ?></h3>
          <table class="ip-poster-table" id="ipPosterFinanceTable">
            <thead><tr><th><?php echo $isEn ? 'Description' : 'विवरण'; ?></th><th><?php echo $isEn ? 'Amount' : 'रकम रु.'; ?></th></tr></thead>
            <tbody></tbody>
          </table>
          <div class="ip-poster-stats" id="ipPosterStats"></div>
        </section>

        <section class="ip-poster-section" id="ipPosterWelfareSection">
          <h3><?php echo $isEn ? 'Member welfare facilities (cumulative)' : 'सदस्य राहत सुविधाको अवस्था (हालसम्म)'; ?></h3>
          <table class="ip-poster-table" id="ipPosterWelfareTable">
            <thead>
              <tr>
                <th><?php echo $isEn ? 'Facility type' : 'सुविधाको प्रकार'; ?></th>
                <th><?php echo $isEn ? 'Count' : 'संख्या'; ?></th>
                <th><?php echo $isEn ? 'Amount' : 'रकम रु.'; ?></th>
              </tr>
            </thead>
            <tbody></tbody>
            <tfoot></tfoot>
          </table>
          <p class="ip-poster-note"><?php echo $isEn
            ? 'Welfare types & totals come from Member Welfare (single source).'
            : 'प्रकाशित मासिक प्रोफाइलमा सुरक्षित राहत (Opening + portal) — प्रकार सदस्य कल्याण बाट।'; ?></p>
        </section>
      </article>
    </div>
  </div>
</div>

<!-- Document Preview Modal -->
<div id="ipDocModal" data-testid="institutional-profile-document-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:99999;align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)ipCloseDoc()">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:920px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.4);">
    <div style="padding:14px 18px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:10px;">
        <span id="ipDocIcon" style="width:36px;height:36px;border-radius:8px;background:var(--bg-muted,#f0fdf4);display:inline-flex;align-items:center;justify-content:center;color:var(--primary-color,#1a5f2a);font-size:1.1rem;flex-shrink:0;"></span>
        <div>
          <div id="ipDocTitle" style="font-weight:700;color:#1a2e1d;font-size:.95rem;"></div>
          <div id="ipDocSub" style="font-size:.75rem;color:#6b7280;margin-top:1px;"></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <a id="ipDocDlBtn" href="#" download target="_blank" rel="noopener noreferrer"
           style="width:36px;height:36px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:var(--bg-muted,#f0fdf4);color:var(--primary-ink,var(--primary-color,#1a5f2a));text-decoration:none;border:1px solid color-mix(in srgb, var(--primary-color,#1a5f2a) 28%, #fff);"
           title="<?php echo $isEn ? 'Download' : 'डाउनलोड'; ?>"
           data-testid="institutional-profile-document-download-link">
          <i class="lucide-icon" data-lucide="download" aria-hidden="true" style="font-size:.85rem;"></i>
        </a>
        <button type="button" onclick="ipCloseDoc()"
                style="width:36px;height:36px;border-radius:8px;border:none;background:#f3f4f6;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;color:#6b7280;"
                title="<?php echo $isEn ? 'Close' : 'बन्द'; ?>"
                data-testid="institutional-profile-document-close-button">
          <i class="lucide-icon" data-lucide="x" aria-hidden="true" style="font-size:1rem;"></i>
        </button>
      </div>
    </div>
    <div id="ipDocBody" style="flex:1;overflow:auto;min-height:420px;display:flex;align-items:center;justify-content:center;background:#f9fafb;">
      <div id="ipDocLoader" style="text-align:center;padding:40px;color:#9ca3af;">
        <i class="lucide-icon lucide-spin lucide-2x" data-lucide="loader-2" aria-hidden="true" style="margin-bottom:12px;display:block;"></i>
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
        var shareId = '';
        try { shareId = new URLSearchParams(window.location.search).get('id') || ''; } catch (e) {}
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
            if (shareId && String(card.getAttribute('data-ip-id') || '') === String(shareId)) {
                show = true;
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

    document.addEventListener('coop:pma-unlocked', function () {
        try { applyIpFilters(); } catch (e) { /* ignore */ }
    });

    (function focusSharedIpProfile() {
        try {
            var sid = new URLSearchParams(window.location.search).get('id');
            if (!sid) return;
            var card = document.getElementById('ip-profile-' + sid)
                || document.querySelector('.ip-month-tile[data-ip-id="' + sid + '"]');
            if (!card) return;
            card.classList.add('is-share-target');
            card.classList.remove('is-hidden');
            if (typeof card.scrollIntoView === 'function') {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            var input = card.querySelector('#coop_pma_sadasyata, input[name="sadasyata_number"]');
            if (input && typeof input.focus === 'function') {
                window.setTimeout(function () { input.focus(); }, 400);
            }
        } catch (e) { /* ignore */ }
    })();

    function ipOpenDoc(url, ext, dlUrl) {
        var modal  = document.getElementById('ipDocModal');
        var body   = document.getElementById('ipDocBody');
        var loader = document.getElementById('ipDocLoader');
        var dlBtn  = document.getElementById('ipDocDlBtn');
        var title  = document.getElementById('ipDocTitle');
        var sub    = document.getElementById('ipDocSub');
        var icon   = document.getElementById('ipDocIcon');
        var isImg  = ['jpg','jpeg','png','gif','webp'].indexOf(ext) !== -1;

        dlBtn.href = dlUrl || (url + (url.indexOf('?') >= 0 ? '&' : '?') + 'dl=1');
        if (isImg) {
            icon.innerHTML  = '<i class="lucide-icon" data-lucide="image" aria-hidden="true"></i>';
            title.textContent = '<?php echo $isEn ? 'Image Document' : 'छवि कागजात'; ?>';
            sub.textContent = ext.toUpperCase();
        } else {
            icon.innerHTML  = '<i class="lucide-icon" data-lucide="file-text" aria-hidden="true"></i>';
            title.textContent = '<?php echo $isEn ? 'PDF Document' : 'PDF कागजात'; ?>';
            sub.textContent = 'PDF';
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        body.innerHTML = '';
        if (loader) {
            body.appendChild(loader);
            loader.style.display = 'block';
        }

        if (isImg) {
            var img = document.createElement('img');
            img.src = url;
            img.alt = '<?php echo $isEn ? 'Document Preview' : 'कागजात पूर्वावलोकन'; ?>';
            img.style.cssText = 'max-width:100%;max-height:75vh;border-radius:8px;display:block;padding:16px;';
            img.onload  = function () { if (loader) loader.style.display = 'none'; body.style.justifyContent = 'center'; };
            img.onerror = function () { if (loader) loader.innerHTML = '<i class="lucide-icon lucide-2x" data-lucide="triangle-alert" aria-hidden="true" style="color:#dc2626;margin-bottom:12px;display:block;"></i><div><?php echo $isEn ? 'Could not load image.' : 'छवि लोड भएन।'; ?></div>'; };
            body.appendChild(img);
        } else {
            var iframe = document.createElement('iframe');
            iframe.src   = url;
            iframe.title = '<?php echo $isEn ? 'PDF Preview' : 'PDF पूर्वावलोकन'; ?>';
            iframe.style.cssText = 'width:100%;height:75vh;border:0;display:block;';
            iframe.onload = function () { if (loader) loader.style.display = 'none'; };
            body.style.justifyContent = 'flex-start';
            body.appendChild(iframe);
        }
    }

    function ipCloseDoc() {
        var modal = document.getElementById('ipDocModal');
        if (!modal || modal.style.display !== 'flex') return;
        modal.style.display = 'none';
        var body = document.getElementById('ipDocBody');
        var loader = document.getElementById('ipDocLoader');
        if (body) {
            /* Keep loader node for next open — do not destroy */
            Array.prototype.slice.call(body.children).forEach(function (ch) {
                if (ch && ch.id !== 'ipDocLoader') body.removeChild(ch);
            });
            if (loader && !loader.parentNode) body.appendChild(loader);
            if (loader) loader.style.display = 'none';
        }
        document.body.style.overflow = '';
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (!t || !t.closest) return;
        var lockBtn = t.closest('[data-pma-toggle]');
        if (!lockBtn) return;
        ev.preventDefault();
        var card = lockBtn.closest('.ip-month-tile') || lockBtn.closest('article');
        if (!card) return;
        var input = card.querySelector('input[name="sadasyata_number"]');
        var form = card.querySelector('.coop-pma-unlock');
        if (form && typeof form.scrollIntoView === 'function') {
            form.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        if (input && typeof input.focus === 'function') {
            window.setTimeout(function () { input.focus(); }, 200);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var poster = document.getElementById('ipPosterModal');
        if (poster && !poster.hidden) return;
        ipCloseDoc();
    });

    window.ipOpenDoc  = ipOpenDoc;
    window.ipCloseDoc = ipCloseDoc;
}());
</script>

<script>
(function () {
  var modal = document.getElementById('ipPosterModal');
  if (!modal) return;
  var dialog = modal.querySelector('.ip-poster-dialog');
  var siteEl = document.getElementById('ipPosterSite');
  var periodEl = document.getElementById('ipPosterPeriod');
  var ribbonEl = document.getElementById('ipPosterRibbon');
  var logoEl = document.getElementById('ipPosterLogo');
  var finBody = document.querySelector('#ipPosterFinanceTable tbody');
  var statsEl = document.getElementById('ipPosterStats');
  var welBody = document.querySelector('#ipPosterWelfareTable tbody');
  var welFoot = document.querySelector('#ipPosterWelfareTable tfoot');
  var welSec = document.getElementById('ipPosterWelfareSection');
  var closeBtn = document.getElementById('ipPosterClose');
  var shareBtn = document.getElementById('ipPosterNativeShare');
  var current = null;
  var lastTrigger = null;
  var openMenu = null;
  var fyPrefix = <?php echo json_encode($isEn ? 'FY ' : 'आ.व. ', JSON_UNESCAPED_UNICODE); ?>;
  var totalLabel = <?php echo json_encode($isEn ? 'Total' : 'जम्मा', JSON_UNESCAPED_UNICODE); ?>;
  var labels = {
    copied: <?php echo json_encode($isEn ? 'Report text copied.' : 'विवरण कपी भयो।', JSON_UNESCAPED_UNICODE); ?>,
    wa: <?php echo json_encode('WhatsApp', JSON_UNESCAPED_UNICODE); ?>,
    fb: <?php echo json_encode('Facebook', JSON_UNESCAPED_UNICODE); ?>,
    copy: <?php echo json_encode($isEn ? 'Copy details' : 'विवरण कपी गर्नुहोस्', JSON_UNESCAPED_UNICODE); ?>
  };

  function fillRows(tbody, rows, cols) {
    tbody.innerHTML = '';
    (rows || []).forEach(function (r) {
      var tr = document.createElement('tr');
      cols.forEach(function (c) {
        var td = document.createElement('td');
        td.textContent = r[c] != null ? String(r[c]) : '';
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
  }

  function setWelfareFooter(count, amount) {
    welFoot.innerHTML = '';
    var tr = document.createElement('tr');
    var cells = [totalLabel, String(count || 0), String(amount || '')];
    cells.forEach(function (text) {
      var th = document.createElement('th');
      th.textContent = text;
      tr.appendChild(th);
    });
    welFoot.appendChild(tr);
  }

  function fyLine(d) {
    var parts = [];
    if (d.fy) parts.push(fyPrefix + d.fy);
    if (d.month) parts.push(d.month);
    return parts.join(' · ');
  }

  function buildShareText(d) {
    var lines = [];
    lines.push(d.site || '');
    lines.push(fyLine(d));
    if (d.dateBs) lines.push(<?php echo json_encode($isEn ? 'As of ' : 'मिति ', JSON_UNESCAPED_UNICODE); ?> + d.dateBs);
    if (d.memberOnly) {
      lines.push('');
      lines.push(<?php echo json_encode($isEn
        ? 'Members only — open the link and enter membership number to view details.'
        : 'सदस्य मात्र — लिंक खोलेर सदस्यता नम्बर हालेपछि विवरण हेर्न सकिन्छ।', JSON_UNESCAPED_UNICODE); ?>);
      if (d.pageUrl) lines.push('\n' + d.pageUrl);
      return lines.filter(Boolean).join('\n');
    }
    lines.push('');
    lines.push(<?php echo json_encode($isEn ? 'Financial details' : 'वित्तीय विवरण', JSON_UNESCAPED_UNICODE); ?>);
    (d.finance || []).forEach(function (r) {
      lines.push('- ' + r.label + ': ' + r.value);
    });
    (d.stats || []).forEach(function (r) {
      lines.push('- ' + r.label + ': ' + r.value);
    });
    if ((d.welfare || []).length) {
      lines.push('');
      lines.push(<?php echo json_encode($isEn ? 'Member welfare facilities' : 'सदस्य राहत सुविधा', JSON_UNESCAPED_UNICODE); ?>);
      d.welfare.forEach(function (r) {
        lines.push('- ' + r.label + ': ' + r.count + ' | ' + r.amount);
      });
      lines.push(totalLabel + ': ' + (d.welfareTotalCount || 0) + ' | ' + (d.welfareTotalAmount || ''));
    }
    if (d.fileUrl) {
      lines.push('');
      lines.push(<?php echo json_encode($isEn ? 'File: ' : 'फाइल: ', JSON_UNESCAPED_UNICODE); ?> + d.fileUrl);
    }
    if (d.pageUrl) lines.push('\n' + d.pageUrl);
    return lines.filter(Boolean).join('\n');
  }

  function focusables() {
    if (!dialog) return [];
    var nodes = Array.prototype.slice.call(dialog.querySelectorAll(
      'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    ));
    if (openMenu) {
      nodes = nodes.concat(Array.prototype.slice.call(openMenu.querySelectorAll('[role="menuitem"]')));
    }
    return nodes.filter(function (el) {
      if (el === document.activeElement) return true;
      if (openMenu && openMenu.contains(el)) return true;
      return el.offsetParent !== null;
    });
  }

  function closeMenu() {
    if (openMenu) {
      openMenu.remove();
      openMenu = null;
    }
  }

  function placeMenu(menu, anchor) {
    (dialog || document.body).appendChild(menu);
    openMenu = menu;
    var rect = anchor.getBoundingClientRect();
    var pad = 8;
    var mw = menu.offsetWidth || 184;
    var mh = menu.offsetHeight || 140;
    var left = rect.left + (rect.width / 2) - (mw / 2);
    left = Math.max(pad, Math.min(left, window.innerWidth - mw - pad));
    var top = rect.bottom + 6;
    if (top + mh > window.innerHeight - pad) {
      top = Math.max(pad, rect.top - mh - 6);
    }
    menu.style.left = Math.round(left) + 'px';
    menu.style.top = Math.round(top) + 'px';
    var first = menu.querySelector('[role="menuitem"]');
    if (first && typeof first.focus === 'function') {
      window.setTimeout(function () { first.focus(); }, 0);
    }
  }

  function copyText(full) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(full).then(function () {
        window.alert(labels.copied);
      }).catch(function () {
        window.prompt(labels.copy, full);
      });
      return;
    }
    window.prompt(labels.copy, full);
  }

  function showFallbackMenu(btn, title, text, url) {
    closeMenu();
    var full = String(text || title || '').trim();
    var page = String(url || '').trim();
    if (page && full.indexOf(page) === -1) {
      full = full ? (full + '\n' + page) : page;
    }
    var menu = document.createElement('div');
    menu.className = 'ip-share-menu';
    menu.setAttribute('role', 'menu');

    var wa = document.createElement('a');
    wa.href = 'https://wa.me/?text=' + encodeURIComponent(full);
    wa.target = '_blank';
    wa.rel = 'noopener noreferrer';
    wa.setAttribute('role', 'menuitem');
    wa.textContent = labels.wa;
    wa.addEventListener('click', closeMenu);

    var fbTarget = url || location.href;
    var fb = document.createElement('a');
    fb.href = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(fbTarget)
      + '&quote=' + encodeURIComponent(String(text || title || '').slice(0, 240));
    fb.target = '_blank';
    fb.rel = 'noopener noreferrer';
    fb.setAttribute('role', 'menuitem');
    fb.textContent = labels.fb;
    fb.addEventListener('click', closeMenu);

    var cp = document.createElement('button');
    cp.type = 'button';
    cp.setAttribute('role', 'menuitem');
    cp.textContent = labels.copy;
    cp.addEventListener('click', function () {
      copyText(full);
      closeMenu();
    });

    menu.appendChild(wa);
    menu.appendChild(fb);
    menu.appendChild(cp);
    placeMenu(menu, btn);
  }

  function openPoster(data, trigger) {
    current = data || {};
    lastTrigger = trigger || null;
    closeMenu();
    siteEl.textContent = current.site || '';
    periodEl.textContent = [fyLine(current), current.dateBs || ''].filter(Boolean).join(' · ');
    ribbonEl.textContent = <?php echo json_encode($isEn
      ? 'Official monthly institutional summary for members & public'
      : 'सदस्य तथा सर्वसाधारणका लागि आधिकारिक मासिक संस्थागत सारांश', JSON_UNESCAPED_UNICODE); ?>;
    /* Prefer banner logo (includes name) — hide duplicate text name when logo exists */
    if (current.logo) {
      logoEl.src = current.logo;
      logoEl.alt = current.site || '';
      logoEl.hidden = false;
      siteEl.hidden = true;
    } else {
      logoEl.removeAttribute('src');
      logoEl.alt = '';
      logoEl.hidden = true;
      siteEl.hidden = !(current.site || '');
    }
    fillRows(finBody, current.finance || [], ['label', 'value']);
    statsEl.innerHTML = '';
    (current.stats || []).forEach(function (s) {
      var div = document.createElement('div');
      div.className = 'ip-poster-stat';
      var span = document.createElement('span');
      var strong = document.createElement('strong');
      span.textContent = s.label || '';
      strong.textContent = s.value || '';
      div.appendChild(span);
      div.appendChild(strong);
      statsEl.appendChild(div);
    });
    if ((current.welfare || []).length) {
      welSec.hidden = false;
      fillRows(welBody, current.welfare, ['label', 'count', 'amount']);
      setWelfareFooter(current.welfareTotalCount, current.welfareTotalAmount);
    } else {
      welSec.hidden = true;
      welBody.innerHTML = '';
      welFoot.innerHTML = '';
    }
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
    window.setTimeout(function () {
      if (closeBtn) closeBtn.focus();
    }, 0);
  }

  function closePoster() {
    closeMenu();
    document.body.classList.remove('ip-poster-printing');
    modal.hidden = true;
    document.body.style.overflow = '';
    current = null;
    if (lastTrigger && typeof lastTrigger.focus === 'function') {
      lastTrigger.focus();
    }
    lastTrigger = null;
  }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!t || !t.closest) return;
    var btn = t.closest('.ip-share-btn');
    if (btn) {
      ev.preventDefault();
      var raw = btn.getAttribute('data-ip-poster') || '{}';
      var data;
      try { data = JSON.parse(raw); } catch (e) { data = {}; }
      openPoster(data, btn);
      return;
    }
    if (!t.closest('.ip-share-menu')) {
      closeMenu();
    }
  });

  closeBtn.addEventListener('click', closePoster);
  modal.addEventListener('click', function (ev) {
    if (ev.target === modal) closePoster();
  });

  document.addEventListener('keydown', function (ev) {
    if (modal.hidden) return;
    if (ev.key === 'Escape') {
      if (openMenu) {
        closeMenu();
        return;
      }
      closePoster();
      return;
    }
    if (ev.key !== 'Tab' || !dialog) return;
    var nodes = focusables();
    if (!nodes.length) return;
    var first = nodes[0];
    var last = nodes[nodes.length - 1];
    if (ev.shiftKey && document.activeElement === first) {
      ev.preventDefault();
      last.focus();
    } else if (!ev.shiftKey && document.activeElement === last) {
      ev.preventDefault();
      first.focus();
    }
  });

  document.getElementById('ipPosterCopy').addEventListener('click', function () {
    if (!current) return;
    copyText(buildShareText(current));
  });

  document.getElementById('ipPosterPrint').addEventListener('click', function () {
    if (modal.hidden) return;
    document.body.classList.add('ip-poster-printing');
    window.print();
  });
  window.addEventListener('afterprint', function () {
    document.body.classList.remove('ip-poster-printing');
  });

  shareBtn.addEventListener('click', function () {
    if (!current) return;
    var text = buildShareText(current);
    var title = (current.site || '') + ' — ' + (current.month || '');
    var url = current.pageUrl || location.href;
    if (typeof navigator.share === 'function') {
      navigator.share({ title: title, text: text, url: url }).catch(function (err) {
        if (err && err.name === 'AbortError') return;
        showFallbackMenu(shareBtn, title, text, url);
      });
      return;
    }
    if (openMenu) {
      closeMenu();
      return;
    }
    showFallbackMenu(shareBtn, title, text, url);
  });
})();
</script>

<?php if (!empty($ipChartSeries) && ($ipChartSeries['count'] ?? 0) >= 2): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g" crossorigin="anonymous"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var primary = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim() || '#1a5f2a';
    var primaryLight = getComputedStyle(document.documentElement).getPropertyValue('--primary-light').trim() || '#22c55e';
    var secondary = getComputedStyle(document.documentElement).getPropertyValue('--secondary-color').trim() || '#c0392b';
    var labels = <?php echo json_encode($ipChartSeries['labels'], JSON_UNESCAPED_UNICODE); ?>;
    var commonOpts = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                position: 'bottom',
                labels: { boxWidth: 12, padding: 14, font: { size: 11, weight: '600' } }
            },
            tooltip: {
                callbacks: {
                    label: function (ctx) {
                        var v = ctx.parsed.y;
                        if (ctx.chart.canvas.id === 'ipChartMembers') {
                            return ctx.dataset.label + ': ' + Number(v).toLocaleString();
                        }
                        if (v >= 1e7) return ctx.dataset.label + ': रू. ' + (v / 1e7).toFixed(2) + ' Cr';
                        if (v >= 1e5) return ctx.dataset.label + ': रू. ' + (v / 1e5).toFixed(1) + ' L';
                        return ctx.dataset.label + ': रू. ' + Number(v).toLocaleString();
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: { maxRotation: 45, minRotation: 0, font: { size: 10 } },
                grid: { display: false }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    font: { size: 10 },
                    callback: function (v) {
                        if (v >= 1e7) return (v / 1e7).toFixed(1) + 'Cr';
                        if (v >= 1e5) return (v / 1e5).toFixed(0) + 'L';
                        return v;
                    }
                }
            }
        }
    };

    var finEl = document.getElementById('ipChartFinancial');
    if (finEl) {
        new Chart(finEl, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: <?php echo json_encode($isEn ? 'Deposits' : 'बचत', JSON_UNESCAPED_UNICODE); ?>,
                        data: <?php echo json_encode($ipChartSeries['deposit']); ?>,
                        borderColor: primary,
                        backgroundColor: primary + '22',
                        fill: true,
                        tension: 0.32,
                        pointRadius: 3,
                        borderWidth: 2
                    },
                    {
                        label: <?php echo json_encode($isEn ? 'Loans' : 'ऋण', JSON_UNESCAPED_UNICODE); ?>,
                        data: <?php echo json_encode($ipChartSeries['loan']); ?>,
                        borderColor: secondary,
                        backgroundColor: secondary + '18',
                        fill: false,
                        tension: 0.32,
                        pointRadius: 3,
                        borderWidth: 2
                    },
                    {
                        label: <?php echo json_encode($isEn ? 'Total assets' : 'कुल सम्पत्ति', JSON_UNESCAPED_UNICODE); ?>,
                        data: <?php echo json_encode($ipChartSeries['assets']); ?>,
                        borderColor: primaryLight,
                        backgroundColor: 'transparent',
                        borderDash: [4, 3],
                        tension: 0.32,
                        pointRadius: 2,
                        borderWidth: 2
                    }
                ]
            },
            options: commonOpts
        });
    }

    var memEl = document.getElementById('ipChartMembers');
    if (memEl) {
        new Chart(memEl, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: <?php echo json_encode($isEn ? 'Members' : 'सदस्य', JSON_UNESCAPED_UNICODE); ?>,
                    data: <?php echo json_encode($ipChartSeries['members']); ?>,
                    backgroundColor: primary + 'cc',
                    borderColor: primary,
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return <?php echo json_encode($isEn ? 'Members' : 'सदस्य', JSON_UNESCAPED_UNICODE); ?> + ': ' + Number(ctx.parsed.y).toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { maxRotation: 45, font: { size: 10 } }, grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        ticks: { font: { size: 10 }, precision: 0 }
                    }
                }
            }
        });
    }
}());
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
