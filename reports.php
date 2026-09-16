<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Reports' : 'प्रतिवेदनहरू';
$pageDescription = isEnglish()
    ? 'Monthly and annual reports published for members and the public.'
    : 'सदस्य तथा सर्वसाधारणका लागि प्रकाशित मासिक तथा वार्षिक प्रतिवेदनहरू।';
$extraHead = (isset($extraHead) ? (string) $extraHead : '')
    . (function_exists('coopThemeLinkHtml')
        ? coopThemeLinkHtml('assets/css/reports-page.css')
        : '');
require_once 'includes/header.php';
$L = getLangStrings();

// Nepali months array
$nepaliMonths = [
    'baisakh' => 'बैशाख',
    'jestha' => 'जेठ',
    'ashadh' => 'असार',
    'shrawan' => 'श्रावण',
    'bhadra' => 'भदौ',
    'ashwin' => 'असोज',
    'kartik' => 'कात्तिक',
    'mangsir' => 'मंसिर',
    'poush' => 'पुष',
    'magh' => 'माघ',
    'falgun' => 'फागुन',
    'chaitra' => 'चैत्र'
];

$quarters = [
    'Q1' => isEnglish() ? 'First Quarter' : 'पहिलो त्रैमासिक',
    'Q2' => isEnglish() ? 'Second Quarter' : 'दोस्रो त्रैमासिक',
    'Q3' => isEnglish() ? 'Third Quarter' : 'तेस्रो त्रैमासिक',
    'Q4' => isEnglish() ? 'Fourth Quarter' : 'चौथो त्रैमासिक'
];

// Get filter (whitelist — bound params मात्र भए पनि अनावश्यक/गलत type बाट query सफा राख्न)
$allowedReportTypes = ['all', 'monthly', 'quarterly', 'progress', 'annual', 'financial', 'audit', 'agm', 'other'];
$filterType = $_GET['type'] ?? 'all';
if (!in_array($filterType, $allowedReportTypes, true)) {
    $filterType = 'all';
}
$filterYearRaw = isset($_GET['year']) ? trim((string) $_GET['year']) : '';
$filterYear = ($filterYearRaw !== '' && preg_match('/^\d{4}\/\d{2}$/', $filterYearRaw)) ? $filterYearRaw : null;
$filterMonth = isset($_GET['month']) ? trim((string) $_GET['month']) : '';
$nepaliMonthKeys = array_keys($nepaliMonths);
$filterMonth = ($filterMonth !== '' && in_array($filterMonth, $nepaliMonthKeys, true)) ? $filterMonth : null;

// Get reports from database
try {
    $db = getDB();

    // Build query
    $sql = "SELECT * FROM reports WHERE is_active = 1";
    $params = [];

    if ($filterType !== 'all') {
        $sql .= " AND report_type = ?";
        $params[] = $filterType;
    }

    if ($filterYear) {
        $sql .= " AND report_year = ?";
        $params[] = $filterYear;
    }

    if ($filterMonth) {
        $sql .= " AND report_month = ?";
        $params[] = $filterMonth;
    }

    $sql .= " ORDER BY report_year DESC, display_order ASC, created_at DESC LIMIT 300";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    // Get available years for filter
    $years = $db->query("SELECT DISTINCT report_year FROM reports WHERE is_active = 1 ORDER BY report_year DESC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);

} catch (Throwable $e) {
    $reports = [];
    $years = [];
}

// Group reports by type and year
$monthlyReports = [];
$quarterlyReports = [];
$progressReports = [];
$annualReports = [];
$financialReports = [];
$auditReports = [];
$agmReports = [];
$otherReports = [];

foreach ($reports as $report) {
    switch ($report['report_type']) {
        case 'monthly':
            $monthlyReports[$report['report_year']][] = $report;
            break;
        case 'quarterly':
            $quarterlyReports[$report['report_year']][] = $report;
            break;
        case 'progress':
            $progressReports[$report['report_year']][] = $report;
            break;
        case 'annual':
            $annualReports[$report['report_year']][] = $report;
            break;
        case 'financial':
            $financialReports[$report['report_year']][] = $report;
            break;
        case 'audit':
            $auditReports[$report['report_year']][] = $report;
            break;
        case 'agm':
            $agmReports[$report['report_year']][] = $report;
            break;
        default:
            $otherReports[$report['report_year']][] = $report;
    }
}

// Get type label
function getTypeLabel($type) {
    $labels = [
        'monthly' => isEnglish() ? 'Monthly' : 'मासिक',
        'quarterly' => isEnglish() ? 'Quarterly' : 'त्रैमासिक',
        'progress' => isEnglish() ? 'Progress' : 'प्रगति',
        'annual' => isEnglish() ? 'Annual' : 'वार्षिक',
        'financial' => isEnglish() ? 'Financial' : 'वित्तीय',
        'audit' => isEnglish() ? 'Audit' : 'लेखापरीक्षण',
        'agm' => isEnglish() ? 'AGM' : 'साधारण सभा',
        'other' => isEnglish() ? 'Other' : 'अन्य'
    ];
    return $labels[$type] ?? $type;
}

/** Public report file URL — relative uploads + absolute SITE_URL. */
function coop_public_report_file_url(?string $path): string {
    if ($path === null) {
        return '';
    }
    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || str_contains($path, '..')) {
        return '';
    }
    if (function_exists('safe_media_src')) {
        $u = safe_media_src($path);
        if ($u !== '') {
            return $u;
        }
    }
    if (preg_match('#^https?://#i', $path)) {
        return function_exists('safe_http_url') ? (string) safe_http_url($path) : '';
    }
    $path = ltrim($path, '/');
    if ($path === '') {
        return '';
    }
    return function_exists('getAssetUrl') ? getAssetUrl($path) : (rtrim((string) SITE_URL, '/') . '/' . $path);
}

/**
 * Icon-only View / Download / Share row (compact; share includes report details).
 */
function render_report_actions(array $report): void {
    global $nepaliMonths, $quarters;

    $fileUrl = coop_public_download_url((string) ($report['file_path'] ?? ''));
    $title = trim((string) (function_exists('getLangField') ? getLangField($report, 'title') : ($report['title'] ?? '')));
    if ($title === '') {
        $title = isEnglish() ? 'Report' : 'प्रतिवेदन';
    }
    $typeLabel = getTypeLabel((string) ($report['report_type'] ?? 'other'));
    $year = trim((string) ($report['report_year'] ?? ''));
    $metaParts = [$typeLabel];
    if ($year !== '') {
        $metaParts[] = (isEnglish() ? 'FY ' : 'आ.व. ') . $year;
    }
    $monthKey = trim((string) ($report['report_month'] ?? ''));
    if ($monthKey !== '' && is_array($nepaliMonths ?? null) && isset($nepaliMonths[$monthKey])) {
        $metaParts[] = (string) $nepaliMonths[$monthKey];
    } elseif ($monthKey !== '') {
        $metaParts[] = $monthKey;
    }
    $quarterKey = trim((string) ($report['report_quarter'] ?? ''));
    if ($quarterKey !== '' && is_array($quarters ?? null) && isset($quarters[$quarterKey])) {
        $metaParts[] = (string) $quarters[$quarterKey];
    } elseif ($quarterKey !== '') {
        $metaParts[] = $quarterKey;
    }
    $metaLine = implode(' · ', array_filter($metaParts));
    $siteName = trim((string) (function_exists('getSetting') ? getSetting('site_name', '') : ''));
    if ($siteName === '') {
        $siteName = isEnglish() ? 'Cooperative' : 'सहकारी';
    }

    $type = preg_replace('/[^a-z_]/', '', (string) ($report['report_type'] ?? 'all')) ?: 'all';
    $sharePage = rtrim((string) SITE_URL, '/') . '/reports.php?type=' . rawurlencode($type);
    if ($year !== '' && preg_match('/^\d{4}\/\d{2}$/', $year)) {
        $sharePage .= '&year=' . rawurlencode($year);
    }

    $shareText = $title . "\n" . $metaLine . "\n" . $siteName;
    if ($fileUrl !== '') {
        $shareText .= "\n" . (isEnglish() ? 'File: ' : 'फाइल: ') . $fileUrl;
    }

    $viewLabel = isEnglish() ? 'View' : 'हेर्नुहोस्';
    $dlLabel = isEnglish() ? 'Download' : 'डाउनलोड';
    $shareLabel = isEnglish() ? 'Share' : 'सेयर';

    echo '<div class="report-actions report-actions-icons" role="group" aria-label="'
        . htmlspecialchars(isEnglish() ? 'Report actions' : 'प्रतिवेदन कार्यहरू', ENT_QUOTES, 'UTF-8')
        . '">';

    if ($fileUrl !== '') {
        $safe = htmlspecialchars($fileUrl, ENT_QUOTES, 'UTF-8');
        echo '<a href="' . $safe . '" target="_blank" rel="noopener noreferrer" class="report-action-btn report-action-view"'
            . ' title="' . htmlspecialchars($viewLabel, ENT_QUOTES, 'UTF-8') . '"'
            . ' aria-label="' . htmlspecialchars($viewLabel . ': ' . $title, ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="lucide-icon" data-lucide="eye" aria-hidden="true"></i></a>';
        echo '<a href="' . $safe . '" download class="report-action-btn report-action-download"'
            . ' title="' . htmlspecialchars($dlLabel, ENT_QUOTES, 'UTF-8') . '"'
            . ' aria-label="' . htmlspecialchars($dlLabel . ': ' . $title, ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="lucide-icon" data-lucide="download" aria-hidden="true"></i></a>';
    } else {
        echo '<span class="report-action-missing text-muted" title="'
            . htmlspecialchars(isEnglish() ? 'File not available' : 'फाइल उपलब्ध छैन', ENT_QUOTES, 'UTF-8') . '">'
            . '<i class="lucide-icon" data-lucide="file-x" aria-hidden="true"></i></span>';
    }

    echo '<button type="button" class="report-action-btn report-action-share"'
        . ' title="' . htmlspecialchars($shareLabel, ENT_QUOTES, 'UTF-8') . '"'
        . ' aria-label="' . htmlspecialchars($shareLabel . ': ' . $title, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-share-title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-share-text="' . htmlspecialchars($shareText, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-share-url="' . htmlspecialchars($sharePage, ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="lucide-icon" data-lucide="share-2" aria-hidden="true"></i></button>';

    echo '</div>';
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Reports & Publications' : 'प्रतिवेदन तथा प्रकाशनहरू'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Reports' : 'प्रतिवेदन'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Reports Filter -->
<section class="reports-filter py-4">
    <div class="container">
        <div class="filter-wrapper">
            <div class="row align-items-center">
                <div class="col-lg-9">
                    <div class="filter-tabs">
                        <a href="reports.php" class="filter-tab <?php echo $filterType === 'all' ? 'active' : ''; ?>">
                            <i class="lucide-icon" data-lucide="folder-open" aria-hidden="true"></i> <?php echo isEnglish() ? 'All' : 'सबै'; ?>
                        </a>
                        <a href="?type=monthly" class="filter-tab <?php echo $filterType === 'monthly' ? 'active' : ''; ?>">
                            <i class="lucide-icon" data-lucide="sunrise" aria-hidden="true"></i> <?php echo isEnglish() ? 'Monthly' : 'मासिक'; ?>
                        </a>
                        <a href="?type=quarterly" class="filter-tab <?php echo $filterType === 'quarterly' ? 'active' : ''; ?>">
                            <i class="lucide-icon" data-lucide="calendar-range" aria-hidden="true"></i> <?php echo isEnglish() ? 'Quarterly' : 'त्रैमासिक'; ?>
                        </a>
                        <a href="?type=progress" class="filter-tab <?php echo $filterType === 'progress' ? 'active' : ''; ?>">
                            <i class="lucide-icon" data-lucide="trending-up" aria-hidden="true"></i> <?php echo isEnglish() ? 'Progress' : 'प्रगति'; ?>
                        </a>
                        <a href="?type=annual" class="filter-tab <?php echo $filterType === 'annual' ? 'active' : ''; ?>">
                            <i class="lucide-icon" data-lucide="calendar" aria-hidden="true"></i> <?php echo isEnglish() ? 'Annual' : 'वार्षिक'; ?>
                        </a>
                        <a href="?type=financial" class="filter-tab <?php echo $filterType === 'financial' ? 'active' : ''; ?>">
                            <i class="lucide-icon" data-lucide="bar-chart-3" aria-hidden="true"></i> <?php echo isEnglish() ? 'Financial' : 'वित्तीय'; ?>
                        </a>
                        <a href="?type=audit" class="filter-tab <?php echo $filterType === 'audit' ? 'active' : ''; ?>">
                            <i class="lucide-icon" data-lucide="clipboard-check" aria-hidden="true"></i> <?php echo isEnglish() ? 'Audit' : 'लेखापरीक्षण'; ?>
                        </a>
                        <a href="?type=agm" class="filter-tab <?php echo $filterType === 'agm' ? 'active' : ''; ?>">
                            <i class="lucide-icon" data-lucide="users" aria-hidden="true"></i> <?php echo isEnglish() ? 'AGM' : 'साधारण सभा'; ?>
                        </a>
                    </div>
                </div>
                <div class="col-lg-3 mt-3 mt-lg-0">
                    <div class="d-flex gap-2">
                        <?php if (!empty($years)): ?>
                        <select class="form-select" onchange="updateFilters();" id="yearFilter">
                            <option value=""><?php echo isEnglish() ? 'All Years' : 'सबै आ.व.'; ?></option>
                            <?php foreach ($years as $year): ?>
                            <option value="<?php echo htmlspecialchars((string) $year, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterYear === (string) $year ? 'selected' : ''; ?>>
                                <?php echo isEnglish() ? 'FY ' : 'आ.व. '; ?><?php echo htmlspecialchars((string) $year, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>

                        <?php if ($filterType === 'monthly'): ?>
                        <select class="form-select" onchange="updateFilters();" id="monthFilter">
                            <option value=""><?php echo isEnglish() ? 'All Months' : 'सबै महिना'; ?></option>
                            <?php foreach ($nepaliMonths as $key => $month): ?>
                            <option value="<?php echo e($key); ?>" <?php echo $filterMonth === $key ? 'selected' : ''; ?>>
                                <?php echo $month; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                </div>

                <script>
                function updateFilters() {
                    var year = document.getElementById('yearFilter')?.value || '';
                    var month = document.getElementById('monthFilter')?.value || '';
                    var url = '?type=<?php echo $filterType; ?>';
                    if (year) url += '&year=' + encodeURIComponent(year);
                    if (month) url += '&month=' + month;
                    window.location.href = url;
                }
                </script>
            </div>
        </div>
    </div>
</section>

<!-- Reports Content -->
<section class="section-padding">
    <div class="container">
        <div class="section-header text-center mb-5" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="lucide-icon" data-lucide="file-text" aria-hidden="true"></i> <?php echo isEnglish() ? 'Reports' : 'प्रतिवेदन'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Official Reports & Documents' : 'आधिकारिक प्रतिवेदन तथा कागजातहरू'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Access our official reports and documents' : 'हाम्रा आधिकारिक प्रतिवेदन र कागजातहरू हेर्नुहोस्'; ?></p>
        </div>

        <?php if (!empty($reports)): ?>

        <!-- Monthly Reports -->
        <?php if (($filterType === 'all' || $filterType === 'monthly') && !empty($monthlyReports)): ?>
        <div class="report-section mb-5" data-aos="fade-up">
            <div class="report-section-header">
                <h3><i class="lucide-icon" data-lucide="sunrise" aria-hidden="true"></i> <?php echo isEnglish() ? 'Monthly Reports' : 'मासिक प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($monthlyReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-3">
                        <div class="report-card monthly">
                            <div class="report-icon">
                                <i class="lucide-icon" data-lucide="file-text" aria-hidden="true"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo e(getLangField($report, 'title')); ?></h5>
                                <span class="report-month">
                                    <?php echo $nepaliMonths[$report['report_month']] ?? $report['report_month']; ?>
                                </span>
                            </div>
                            <?php render_report_actions($report); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Quarterly Reports -->
        <?php if (($filterType === 'all' || $filterType === 'quarterly') && !empty($quarterlyReports)): ?>
        <div class="report-section mb-5" data-aos="fade-up">
            <div class="report-section-header">
                <h3><i class="lucide-icon" data-lucide="calendar-range" aria-hidden="true"></i> <?php echo isEnglish() ? 'Quarterly Reports' : 'त्रैमासिक प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($quarterlyReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="report-card quarterly">
                            <div class="report-icon">
                                <i class="lucide-icon" data-lucide="bar-chart-3" aria-hidden="true"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo e(getLangField($report, 'title')); ?></h5>
                                <span class="report-quarter">
                                    <?php echo $quarters[$report['report_quarter']] ?? $report['report_quarter']; ?>
                                </span>
                            </div>
                            <?php render_report_actions($report); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Progress Reports -->
        <?php if (($filterType === 'all' || $filterType === 'progress') && !empty($progressReports)): ?>
        <div class="report-section mb-5" data-aos="fade-up">
            <div class="report-section-header">
                <h3><i class="lucide-icon" data-lucide="trending-up" aria-hidden="true"></i> <?php echo isEnglish() ? 'Progress Reports' : 'प्रगति प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($progressReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card progress-type">
                            <div class="report-icon">
                                <i class="lucide-icon" data-lucide="trending-up" aria-hidden="true"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo e(getLangField($report, 'title')); ?></h5>
                            </div>
                            <?php render_report_actions($report); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Annual/Financial/Audit Reports -->
        <?php if (($filterType === 'all' || $filterType === 'annual') && !empty($annualReports)): ?>
        <div class="report-section mb-5" data-aos="fade-up">
            <div class="report-section-header">
                <h3><i class="lucide-icon" data-lucide="calendar" aria-hidden="true"></i> <?php echo isEnglish() ? 'Annual Reports' : 'वार्षिक प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($annualReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card annual">
                            <div class="report-icon">
                                <i class="lucide-icon" data-lucide="file-text" aria-hidden="true"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo e(getLangField($report, 'title')); ?></h5>
                                <span class="report-type-badge"><?php echo getTypeLabel($report['report_type']); ?></span>
                            </div>
                            <?php render_report_actions($report); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Financial Reports -->
        <?php if (($filterType === 'all' || $filterType === 'financial') && !empty($financialReports)): ?>
        <div class="report-section mb-5" data-aos="fade-up">
            <div class="report-section-header">
                <h3><i class="lucide-icon" data-lucide="bar-chart-3" aria-hidden="true"></i> <?php echo isEnglish() ? 'Financial Reports' : 'वित्तीय प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($financialReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card financial">
                            <div class="report-icon">
                                <i class="lucide-icon" data-lucide="bar-chart-3" aria-hidden="true"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo e(getLangField($report, 'title')); ?></h5>
                            </div>
                            <?php render_report_actions($report); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Audit Reports -->
        <?php if (($filterType === 'all' || $filterType === 'audit') && !empty($auditReports)): ?>
        <div class="report-section mb-5" data-aos="fade-up">
            <div class="report-section-header">
                <h3><i class="lucide-icon" data-lucide="clipboard-check" aria-hidden="true"></i> <?php echo isEnglish() ? 'Audit Reports' : 'लेखापरीक्षण प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($auditReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card audit">
                            <div class="report-icon">
                                <i class="lucide-icon" data-lucide="clipboard-check" aria-hidden="true"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo e(getLangField($report, 'title')); ?></h5>
                            </div>
                            <?php render_report_actions($report); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- AGM Reports -->
        <?php if (($filterType === 'all' || $filterType === 'agm') && !empty($agmReports)): ?>
        <div class="report-section mb-5" data-aos="fade-up">
            <div class="report-section-header">
                <h3><i class="lucide-icon" data-lucide="users" aria-hidden="true"></i> <?php echo isEnglish() ? 'AGM Reports' : 'साधारण सभा प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($agmReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card agm">
                            <div class="report-icon">
                                <i class="lucide-icon" data-lucide="users" aria-hidden="true"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo e(getLangField($report, 'title')); ?></h5>
                            </div>
                            <?php render_report_actions($report); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Other Reports -->
        <?php if (($filterType === 'all' || $filterType === 'other') && !empty($otherReports)): ?>
        <div class="report-section mb-5" data-aos="fade-up">
            <div class="report-section-header">
                <h3><i class="lucide-icon" data-lucide="folder-open" aria-hidden="true"></i> <?php echo isEnglish() ? 'Other Reports' : 'अन्य प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($otherReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card other">
                            <div class="report-icon">
                                <i class="lucide-icon" data-lucide="file-text" aria-hidden="true"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo e(getLangField($report, 'title')); ?></h5>
                            </div>
                            <?php render_report_actions($report); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state text-center py-5">
            <i class="lucide-icon lucide-4x text-muted mb-3" data-lucide="file-text" aria-hidden="true"></i>
            <h4><?php echo isEnglish() ? 'No Reports Available' : 'कुनै प्रतिवेदन उपलब्ध छैन'; ?></h4>
            <p class="text-muted"><?php echo isEnglish() ? 'Reports will be available soon.' : 'प्रतिवेदनहरू चाँडै उपलब्ध हुनेछन्।'; ?></p>
        </div>
        <?php endif; ?>

    </div>
</section>


<?php require_once 'includes/footer.php'; ?>

<script>
(function () {
  var labels = {
    wa: <?php echo json_encode(isEnglish() ? 'WhatsApp' : 'WhatsApp'); ?>,
    fb: <?php echo json_encode(isEnglish() ? 'Facebook' : 'Facebook'); ?>,
    copy: <?php echo json_encode(isEnglish() ? 'Copy details' : 'विवरण कपी गर्नुहोस्'); ?>,
    copied: <?php echo json_encode(isEnglish() ? 'Report details copied.' : 'प्रतिवेदन विवरण कपी भयो।'); ?>
  };

  var openMenu = null;

  function closeMenu() {
    if (openMenu) {
      openMenu.remove();
      openMenu = null;
    }
  }

  function copyText(full) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(full).then(function () {
        window.alert(labels.copied);
      }).catch(function () {
        window.prompt(labels.copy, full);
      });
    }
    window.prompt(labels.copy, full);
  }

  function showFallbackMenu(btn, title, text, url) {
    closeMenu();
    var full = (text || title || '') + (url ? '\n' + url : '');
    var menu = document.createElement('div');
    menu.className = 'report-share-menu';
    menu.setAttribute('role', 'menu');

    var wa = document.createElement('a');
    wa.href = 'https://wa.me/?text=' + encodeURIComponent(full);
    wa.target = '_blank';
    wa.rel = 'noopener noreferrer';
    wa.setAttribute('role', 'menuitem');
    wa.textContent = labels.wa;

    var fb = document.createElement('a');
    fb.href = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url || location.href);
    fb.target = '_blank';
    fb.rel = 'noopener noreferrer';
    fb.setAttribute('role', 'menuitem');
    fb.textContent = labels.fb;

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
    btn.parentNode.appendChild(menu);
    openMenu = menu;
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target && ev.target.closest ? ev.target.closest('.report-action-share') : null;
    if (!btn) {
      if (!ev.target.closest || !ev.target.closest('.report-share-menu')) {
        closeMenu();
      }
      return;
    }
    ev.preventDefault();
    ev.stopPropagation();
    var title = btn.getAttribute('data-share-title') || document.title;
    var text = btn.getAttribute('data-share-text') || title;
    var url = btn.getAttribute('data-share-url') || location.href;
    if (navigator.share) {
      navigator.share({ title: title, text: text, url: url }).catch(function () {});
      return;
    }
    if (openMenu && btn.parentNode.contains(openMenu)) {
      closeMenu();
      return;
    }
    showFallbackMenu(btn, title, text, url);
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') closeMenu();
  });
})();
</script>
