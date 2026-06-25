<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Reports' : 'प्रतिवेदनहरू';
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
$filterYear = isset($_GET['year']) ? (int) $_GET['year'] : 0;
$filterYear = $filterYear > 1900 && $filterYear < 2100 ? $filterYear : null;
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

    $sql .= " ORDER BY report_year DESC, display_order ASC, created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    // Get available years for filter
    $years = $db->query("SELECT DISTINCT report_year FROM reports WHERE is_active = 1 ORDER BY report_year DESC")->fetchAll(PDO::FETCH_COLUMN);

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
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Reports & Publications' : 'प्रतिवेदन तथा प्रकाशनहरू'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
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
                            <i class="fas fa-folder-open"></i> <?php echo isEnglish() ? 'All' : 'सबै'; ?>
                        </a>
                        <a href="?type=monthly" class="filter-tab <?php echo $filterType === 'monthly' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-day"></i> <?php echo isEnglish() ? 'Monthly' : 'मासिक'; ?>
                        </a>
                        <a href="?type=quarterly" class="filter-tab <?php echo $filterType === 'quarterly' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-week"></i> <?php echo isEnglish() ? 'Quarterly' : 'त्रैमासिक'; ?>
                        </a>
                        <a href="?type=progress" class="filter-tab <?php echo $filterType === 'progress' ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line"></i> <?php echo isEnglish() ? 'Progress' : 'प्रगति'; ?>
                        </a>
                        <a href="?type=annual" class="filter-tab <?php echo $filterType === 'annual' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar"></i> <?php echo isEnglish() ? 'Annual' : 'वार्षिक'; ?>
                        </a>
                        <a href="?type=financial" class="filter-tab <?php echo $filterType === 'financial' ? 'active' : ''; ?>">
                            <i class="fas fa-chart-bar"></i> <?php echo isEnglish() ? 'Financial' : 'वित्तीय'; ?>
                        </a>
                        <a href="?type=audit" class="filter-tab <?php echo $filterType === 'audit' ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-check"></i> <?php echo isEnglish() ? 'Audit' : 'लेखापरीक्षण'; ?>
                        </a>
                        <a href="?type=agm" class="filter-tab <?php echo $filterType === 'agm' ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i> <?php echo isEnglish() ? 'AGM' : 'साधारण सभा'; ?>
                        </a>
                    </div>
                </div>
                <div class="col-lg-3 mt-3 mt-lg-0">
                    <div class="d-flex gap-2">
                        <?php if (!empty($years)): ?>
                        <select class="form-select" onchange="updateFilters();" id="yearFilter">
                            <option value=""><?php echo isEnglish() ? 'All Years' : 'सबै आ.व.'; ?></option>
                            <?php foreach ($years as $year): ?>
                            <option value="<?php echo $year; ?>" <?php echo $filterYear === $year ? 'selected' : ''; ?>>
                                <?php echo isEnglish() ? 'FY ' : 'आ.व. '; ?><?php echo $year; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>

                        <?php if ($filterType === 'monthly'): ?>
                        <select class="form-select" onchange="updateFilters();" id="monthFilter">
                            <option value=""><?php echo isEnglish() ? 'All Months' : 'सबै महिना'; ?></option>
                            <?php foreach ($nepaliMonths as $key => $month): ?>
                            <option value="<?php echo $key; ?>" <?php echo $filterMonth === $key ? 'selected' : ''; ?>>
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
                    if (year) url += '&year=' + year;
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
                <span class="section-badge"><i class="fas fa-file-alt"></i> <?php echo isEnglish() ? 'Reports' : 'प्रतिवेदन'; ?></span>
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
                <h3><i class="fas fa-calendar-day"></i> <?php echo isEnglish() ? 'Monthly Reports' : 'मासिक प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($monthlyReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-3">
                        <div class="report-card monthly">
                            <div class="report-icon">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo getLangField($report, 'title'); ?></h5>
                                <span class="report-month">
                                    <?php echo $nepaliMonths[$report['report_month']] ?? $report['report_month']; ?>
                                </span>
                            </div>
                            <?php if ($report['file_path']): ?>
                            <div class="report-actions">
                                <a href="<?php echo $report['file_path']; ?>" target="_blank" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="<?php echo $report['file_path']; ?>" download class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-download"></i>
                                </a>
                            </div>
                            <?php endif; ?>
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
                <h3><i class="fas fa-calendar-week"></i> <?php echo isEnglish() ? 'Quarterly Reports' : 'त्रैमासिक प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($quarterlyReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="report-card quarterly">
                            <div class="report-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo getLangField($report, 'title'); ?></h5>
                                <span class="report-quarter">
                                    <?php echo $quarters[$report['report_quarter']] ?? $report['report_quarter']; ?>
                                </span>
                            </div>
                            <?php if ($report['file_path']): ?>
                            <div class="report-actions">
                                <a href="<?php echo $report['file_path']; ?>" target="_blank" class="btn btn-sm btn-warning">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="<?php echo $report['file_path']; ?>" download class="btn btn-sm btn-outline-warning">
                                    <i class="fas fa-download"></i>
                                </a>
                            </div>
                            <?php endif; ?>
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
                <h3><i class="fas fa-chart-line"></i> <?php echo isEnglish() ? 'Progress Reports' : 'प्रगति प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($progressReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card progress-type">
                            <div class="report-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo getLangField($report, 'title'); ?></h5>
                            </div>
                            <?php if ($report['file_path']): ?>
                            <div class="report-actions">
                                <a href="<?php echo $report['file_path']; ?>" target="_blank" class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> <?php echo isEnglish() ? 'View' : 'हेर्नुहोस्'; ?>
                                </a>
                                <a href="<?php echo $report['file_path']; ?>" download class="btn btn-sm btn-outline-info">
                                    <i class="fas fa-download"></i> <?php echo isEnglish() ? 'Download' : 'डाउनलोड'; ?>
                                </a>
                            </div>
                            <?php endif; ?>
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
                <h3><i class="fas fa-calendar"></i> <?php echo isEnglish() ? 'Annual Reports' : 'वार्षिक प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($annualReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card annual">
                            <div class="report-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo getLangField($report, 'title'); ?></h5>
                                <span class="report-type-badge"><?php echo getTypeLabel($report['report_type']); ?></span>
                            </div>
                            <?php if ($report['file_path']): ?>
                            <div class="report-actions">
                                <a href="<?php echo $report['file_path']; ?>" target="_blank" class="btn btn-sm btn-success">
                                    <i class="fas fa-eye"></i> <?php echo isEnglish() ? 'View' : 'हेर्नुहोस्'; ?>
                                </a>
                                <a href="<?php echo $report['file_path']; ?>" download class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-download"></i> <?php echo isEnglish() ? 'Download' : 'डाउनलोड'; ?>
                                </a>
                            </div>
                            <?php endif; ?>
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
                <h3><i class="fas fa-chart-bar"></i> <?php echo isEnglish() ? 'Financial Reports' : 'वित्तीय प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($financialReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card financial">
                            <div class="report-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo getLangField($report, 'title'); ?></h5>
                            </div>
                            <?php if ($report['file_path']): ?>
                            <div class="report-actions">
                                <a href="<?php echo $report['file_path']; ?>" target="_blank" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i> <?php echo isEnglish() ? 'View' : 'हेर्नुहोस्'; ?>
                                </a>
                                <a href="<?php echo $report['file_path']; ?>" download class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-download"></i> <?php echo isEnglish() ? 'Download' : 'डाउनलोड'; ?>
                                </a>
                            </div>
                            <?php endif; ?>
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
                <h3><i class="fas fa-clipboard-check"></i> <?php echo isEnglish() ? 'Audit Reports' : 'लेखापरीक्षण प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($auditReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card audit">
                            <div class="report-icon">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo getLangField($report, 'title'); ?></h5>
                            </div>
                            <?php if ($report['file_path']): ?>
                            <div class="report-actions">
                                <a href="<?php echo $report['file_path']; ?>" target="_blank" class="btn btn-sm btn-danger">
                                    <i class="fas fa-eye"></i> <?php echo isEnglish() ? 'View' : 'हेर्नुहोस्'; ?>
                                </a>
                                <a href="<?php echo $report['file_path']; ?>" download class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-download"></i> <?php echo isEnglish() ? 'Download' : 'डाउनलोड'; ?>
                                </a>
                            </div>
                            <?php endif; ?>
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
                <h3><i class="fas fa-users"></i> <?php echo isEnglish() ? 'AGM Reports' : 'साधारण सभा प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($agmReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card agm">
                            <div class="report-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo getLangField($report, 'title'); ?></h5>
                            </div>
                            <?php if ($report['file_path']): ?>
                            <div class="report-actions">
                                <a href="<?php echo $report['file_path']; ?>" target="_blank" class="btn btn-sm btn-purple">
                                    <i class="fas fa-eye"></i> <?php echo isEnglish() ? 'View' : 'हेर्नुहोस्'; ?>
                                </a>
                                <a href="<?php echo $report['file_path']; ?>" download class="btn btn-sm btn-outline-purple">
                                    <i class="fas fa-download"></i> <?php echo isEnglish() ? 'Download' : 'डाउनलोड'; ?>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Other Reports -->
        <?php if ($filterType === 'all' && !empty($otherReports)): ?>
        <div class="report-section mb-5" data-aos="fade-up">
            <div class="report-section-header">
                <h3><i class="fas fa-folder-open"></i> <?php echo isEnglish() ? 'Other Reports' : 'अन्य प्रतिवेदनहरू'; ?></h3>
            </div>

            <?php foreach ($otherReports as $year => $yearReports): ?>
            <div class="report-year-block mb-4">
                <h4 class="year-title"><?php echo isEnglish() ? 'Fiscal Year ' : 'आर्थिक वर्ष '; ?><?php echo $year; ?></h4>
                <div class="row">
                    <?php foreach ($yearReports as $report): ?>
                    <div class="col-lg-4 col-md-6 mb-3">
                        <div class="report-card other">
                            <div class="report-icon">
                                <i class="fas fa-file-pdf"></i>
                            </div>
                            <div class="report-info">
                                <h5><?php echo getLangField($report, 'title'); ?></h5>
                            </div>
                            <?php if ($report['file_path']): ?>
                            <div class="report-actions">
                                <a href="<?php echo $report['file_path']; ?>" target="_blank" class="btn btn-sm btn-secondary">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="<?php echo $report['file_path']; ?>" download class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-download"></i>
                                </a>
                            </div>
                            <?php endif; ?>
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
            <i class="fas fa-file-alt fa-4x text-muted mb-3"></i>
            <h4><?php echo isEnglish() ? 'No Reports Available' : 'कुनै प्रतिवेदन उपलब्ध छैन'; ?></h4>
            <p class="text-muted"><?php echo isEnglish() ? 'Reports will be available soon.' : 'प्रतिवेदनहरू चाँडै उपलब्ध हुनेछन्।'; ?></p>
        </div>
        <?php endif; ?>

    </div>
</section>


<?php require_once 'includes/footer.php'; ?>
