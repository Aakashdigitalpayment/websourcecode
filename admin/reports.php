<?php
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('प्रतिवेदन व्यवस्थापन', 'Reports Management');
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

/* ── getBSFiscalYears: BS आर्थिक वर्ष <option> list ── */
if (!function_exists('getBSFiscalYears')) {
    function getBSFiscalYears(string $selected = ''): string {
        $html = '';
        for ($y = 2070; $y <= 2086; $y++) {
            $next  = $y + 1 - 2000;          // e.g. 2080+1-2000 = 81
            $label = $y . '/' . str_pad($next, 2, '0', STR_PAD_LEFT); // 2080/81
            $sel   = ($selected === $label) ? ' selected' : '';
            $html .= "<option value=\"{$label}\"{$sel}>{$label}</option>\n";
        }
        return $html;
    }
}

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
    'Q1' => 'पहिलो त्रैमासिक (बैशाख-असार)',
    'Q2' => 'दोस्रो त्रैमासिक (श्रावण-असोज)',
    'Q3' => 'तेस्रो त्रैमासिक (कात्तिक-पुष)',
    'Q4' => 'चौथो त्रैमासिक (माघ-चैत्र)'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

    try {
        if ($action === 'add' || $action === 'edit') {
            $id = $_POST['id'] ?? null;
            $title = clean_text($_POST['title']);
            $title_np = clean_text($_POST['title_np']);
            $report_type = clean_text($_POST['report_type']);
            $report_year = clean_text($_POST['report_year']);
            $report_month = $report_type === 'monthly' ? clean_text($_POST['report_month'] ?? '') : null;
            $report_quarter = $report_type === 'quarterly' ? clean_text($_POST['report_quarter'] ?? '') : null;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $display_order = (int)($_POST['display_order'] ?? 0);

            // Handle file upload
            $file_path = $_POST['existing_file'] ?? '';
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $upload = uploadFile($_FILES['file'], 'reports');
                if ($upload['success']) {
                    $file_path = $upload['path'];
                }
            }

            if ($action === 'add') {
                $stmt = $db->prepare("INSERT INTO reports (title, title_np, report_type, report_year, report_month, report_quarter, file_path, is_active, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$title, $title_np, $report_type, $report_year, $report_month, $report_quarter, $file_path, $is_active, $display_order]);
                setFlash('success', 'प्रतिवेदन थपियो।');
            } else {
                $stmt = $db->prepare("UPDATE reports SET title=?, title_np=?, report_type=?, report_year=?, report_month=?, report_quarter=?, file_path=?, is_active=?, display_order=? WHERE id=?");
                $stmt->execute([$title, $title_np, $report_type, $report_year, $report_month, $report_quarter, $file_path, $is_active, $display_order, $id]);
                setFlash('success', 'प्रतिवेदन अपडेट भयो।');
            }
        } elseif ($action === 'delete') {
            $id = $_POST['id'];
            $db->prepare("DELETE FROM reports WHERE id = ?")->execute([$id]);
            setFlash('success', 'प्रतिवेदन मेटाइयो।');
        }
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।');
    }

    redirect('reports.php');
}

// Get database connection
$db = getDB();

// Get filter
$allowedReportTypes = ['all', 'monthly', 'quarterly', 'progress', 'annual', 'financial', 'audit', 'agm', 'other'];
$filterType = $_GET['type'] ?? 'all';
if (!in_array($filterType, $allowedReportTypes, true)) {
    $filterType = 'all';
}

// Get all reports
try {
    if ($filterType !== 'all') {
        $stmt = $db->prepare("SELECT * FROM reports WHERE report_type = ? ORDER BY report_year DESC, display_order ASC, created_at DESC");
        $stmt->execute([$filterType]);
        $reports = $stmt->fetchAll();
    } else {
        $reports = $db->query("SELECT * FROM reports ORDER BY report_year DESC, display_order ASC, created_at DESC")->fetchAll();
    }
} catch (Exception $e) {
    $reports = [];
}

// Get single report for editing
$editReport = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM reports WHERE id = ?");
    $stmt->execute([(int) $_GET['edit']]);
    $editReport = $stmt->fetch();
}
$panel = (string)($_GET['panel'] ?? ($editReport ? 'form' : 'list'));
if (!in_array($panel, ['list', 'form'], true)) {
    $panel = 'list';
}

// Get report type labels
function getReportTypeLabel($type) {
    $labels = [
        'monthly' => 'मासिक',
        'quarterly' => 'त्रैमासिक',
        'progress' => 'प्रगति',
        'annual' => 'वार्षिक',
        'financial' => 'वित्तीय',
        'audit' => 'लेखापरीक्षण',
        'agm' => 'साधारण सभा',
        'other' => 'अन्य'
    ];
    return $labels[$type] ?? $type;
}
?>

<?php
echo adminPageHeader($__t('प्रतिवेदन व्यवस्थापन', 'Reports Management'), 'fa-file-alt', $__t('वार्षिक प्रतिवेदन र दस्तावेजहरू व्यवस्थापन गर्नुहोस्', 'Manage annual reports and documents'));
$_flash = getFlash(); if ($_flash) echo adminAlert($_flash['type'], $_flash['message']);
?>
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12"></div>
    </div>

    <ul class="nav nav-tabs admin-nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?php echo $panel === 'list' ? 'active' : ''; ?>" href="reports.php?<?php echo htmlspecialchars(http_build_query(array_filter(['type' => $filterType !== 'all' ? $filterType : null, 'panel' => 'list'])), ENT_QUOTES, 'UTF-8'); ?>">
                <i class="fas fa-list me-2"></i><?php echo $__t('सूची', 'List'); ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $panel === 'form' ? 'active' : ''; ?>" href="reports.php?panel=form">
                <i class="fas fa-pen me-2"></i><?php echo $editReport ? $__t('सम्पादन', 'Edit') : $__t('फर्म', 'Form'); ?>
            </a>
        </li>
    </ul>

    <?php if ($panel === 'form'): ?>
    <div class="row">
        <!-- Form Section -->
        <div class="col-12">
            <div class="card admin-table-card">
                <div class="card-header">
                    <h5><?php echo $editReport ? $__t('प्रतिवेदन सम्पादन', 'Edit Report') : $__t('नयाँ प्रतिवेदन थप्नुहोस्', 'Add New Report'); ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="reportForm" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                      <input type="hidden" name="action" value="<?php echo $editReport ? 'edit' : 'add'; ?>">
                        <?php if ($editReport): ?>
                        <input type="hidden" name="id" value="<?php echo $editReport['id']; ?>">
                        <input type="hidden" name="existing_file" value="<?php echo $editReport['file_path']; ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label"><?php echo $__t('प्रतिवेदन प्रकार', 'Report Type'); ?> *</label>
                            <select name="report_type" id="reportType" class="form-select" required>
                                <option value="">-- <?php echo $__t('छान्नुहोस्', 'Select'); ?> --</option>
                                <option value="monthly" <?php echo ($editReport['report_type'] ?? '') === 'monthly' ? 'selected' : ''; ?>><?php echo $__t('मासिक प्रतिवेदन','Monthly Report'); ?></option>
                                <option value="quarterly" <?php echo ($editReport['report_type'] ?? '') === 'quarterly' ? 'selected' : ''; ?>><?php echo $__t('त्रैमासिक प्रतिवेदन','Quarterly Report'); ?></option>
                                <option value="progress" <?php echo ($editReport['report_type'] ?? '') === 'progress' ? 'selected' : ''; ?>><?php echo $__t('प्रगति प्रतिवेदन','Progress Report'); ?></option>
                                <option value="annual" <?php echo ($editReport['report_type'] ?? '') === 'annual' ? 'selected' : ''; ?>><?php echo $__t('वार्षिक प्रतिवेदन','Annual Report'); ?></option>
                                <option value="financial" <?php echo ($editReport['report_type'] ?? '') === 'financial' ? 'selected' : ''; ?>><?php echo $__t('वित्तीय विवरण','Financial Statement'); ?></option>
                                <option value="audit" <?php echo ($editReport['report_type'] ?? '') === 'audit' ? 'selected' : ''; ?>><?php echo $__t('लेखापरीक्षण प्रतिवेदन','Audit Report'); ?></option>
                                <option value="agm" <?php echo ($editReport['report_type'] ?? '') === 'agm' ? 'selected' : ''; ?>><?php echo $__t('साधारण सभा प्रतिवेदन','AGM Report'); ?></option>
                                <option value="other" <?php echo ($editReport['report_type'] ?? '') === 'other' ? 'selected' : ''; ?>><?php echo $__t('अन्य','Other'); ?></option>
                            </select>
                        </div>

                        <!-- Month selection (for monthly reports) -->
                        <div class="mb-3 <?php echo ($editReport['report_type'] ?? '') === 'monthly' ? '' : 'd-none'; ?>" id="monthField">
                            <label class="form-label"><?php echo $__t('महिना', 'Month'); ?> *</label>
                            <select name="report_month" class="form-select">
                                <option value="">-- <?php echo $__t('महिना छान्नुहोस्', 'Select month'); ?> --</option>
                                <?php foreach ($nepaliMonths as $key => $month): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($editReport['report_month'] ?? '') === $key ? 'selected' : ''; ?>>
                                    <?php echo $month; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Quarter selection (for quarterly reports) -->
                        <div class="mb-3 <?php echo ($editReport['report_type'] ?? '') === 'quarterly' ? '' : 'd-none'; ?>" id="quarterField">
                            <label class="form-label"><?php echo $__t('त्रैमास', 'Quarter'); ?> *</label>
                            <select name="report_quarter" class="form-select">
                                <option value="">-- <?php echo $__t('त्रैमास छान्नुहोस्', 'Select quarter'); ?> --</option>
                                <?php foreach ($quarters as $key => $quarter): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($editReport['report_quarter'] ?? '') === $key ? 'selected' : ''; ?>>
                                    <?php echo $quarter; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Title (English)</label>
                            <input type="text" name="title" class="form-control" required value="<?php echo $editReport['title'] ?? ''; ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?php echo $__t('शीर्षक (नेपाली)', 'Title (Nepali)'); ?></label>
                            <input type="text" name="title_np" class="form-control" value="<?php echo $editReport['title_np'] ?? ''; ?>">
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo $__t('आर्थिक वर्ष', 'Fiscal Year'); ?> *</label>
                                <select name="report_year" class="form-select" required>
                          <option value="">-- <?php echo $__t('आर्थिक वर्ष छान्नुहोस्', 'Select fiscal year'); ?> --</option>
                          <?php echo getBSFiscalYears($editReport['report_year'] ?? ''); ?>
                      </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo $__t('क्रम', 'Order'); ?></label>
                                <input type="number" name="display_order" class="form-control" value="<?php echo $editReport['display_order'] ?? 0; ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?php echo $__t('फाइल (PDF)', 'File (PDF)'); ?></label>
                            <input type="file" name="file" class="form-control" accept=".pdf,.doc,.docx">
                            <?php if (!empty($editReport['file_path'])): ?>
                            <small class="text-muted"><?php echo $__t('हालको', 'Current'); ?>: <?php echo basename($editReport['file_path']); ?></small>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="isActive"
                                   <?php echo ($editReport['is_active'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="isActive"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="lucide-icon" aria-hidden="true" data-lucide="save"></i> <?php echo $editReport ? $__t('अपडेट गर्नुहोस्', 'Update') : $__t('थप्नुहोस्', 'Add'); ?>
                        </button>
                        <?php if ($editReport): ?>
                        <a href="reports.php" class="btn btn-secondary"><?php echo $__t('रद्द गर्नुहोस्', 'Cancel'); ?></a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>

        <!-- List Section -->
    <div class="row">
        <div class="col-12">
            <div class="card admin-table-card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <h5 class="mb-0 flex-shrink-0"><?php echo $__t('प्रतिवेदन सूची', 'Report List'); ?></h5>
                    <div class="filter-buttons d-flex flex-wrap gap-2">
                        <a href="?type=all" class="btn btn-sm <?php echo $filterType === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo $__t('सबै', 'All'); ?></a>
                        <a href="?type=monthly" class="btn btn-sm <?php echo $filterType === 'monthly' ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo $__t('मासिक', 'Monthly'); ?></a>
                        <a href="?type=quarterly" class="btn btn-sm <?php echo $filterType === 'quarterly' ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo $__t('त्रैमासिक', 'Quarterly'); ?></a>
                        <a href="?type=progress" class="btn btn-sm <?php echo $filterType === 'progress' ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo $__t('प्रगति', 'Progress'); ?></a>
                        <a href="?type=annual" class="btn btn-sm <?php echo $filterType === 'annual' ? 'btn-primary' : 'btn-outline-primary'; ?>"><?php echo $__t('वार्षिक', 'Annual'); ?></a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="border-0"><?php echo $__t('शीर्षक', 'Title'); ?></th>
                                    <th class="border-0"><?php echo $__t('प्रकार','Type'); ?></th>
                                    <th class="border-0"><?php echo $__t('अवधि','Period'); ?></th>
                                    <th class="border-0"><?php echo $__t('स्थिति','Status'); ?></th>
                                    <th class="border-0 text-center"><?php echo $__t('कार्य','Actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td class="align-middle">
                                        <div class="fw-medium text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($report['title_np'] ?: $report['title']); ?>">
                                            <?php echo truncateText($report['title_np'] ?: $report['title'], 35); ?>
                                        </div>
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge rounded-pill <?php
                                            echo $report['report_type'] === 'monthly' ? 'bg-info' :
                                                ($report['report_type'] === 'quarterly' ? 'bg-warning' :
                                                ($report['report_type'] === 'annual' ? 'bg-success' : 'bg-secondary'));
                                        ?>">
                                            <?php echo getReportTypeLabel($report['report_type']); ?>
                                        </span>
                                    </td>
                                    <td class="align-middle">
                                        <span class="text-muted small">
                                            <?php
                                            echo $report['report_year'];
                                            if ($report['report_month']) {
                                                echo ' / ' . ($nepaliMonths[$report['report_month']] ?? $report['report_month']);
                                            }
                                            if ($report['report_quarter']) {
                                                echo ' / ' . $report['report_quarter'];
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge rounded-pill <?php echo $report['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $report['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?>
                                        </span>
                                    </td>
                                    <td class="align-middle">
                                        <div class="btn-group" role="group">
                                            <?php if ($report['file_path']): ?>
                                            <a href="../<?php echo $report['file_path']; ?>" class="btn btn-sm btn-success" target="_blank" title="<?php echo $__t('हेर्नुहोस्','View'); ?>">
                                                <i class="lucide-icon" aria-hidden="true" data-lucide="eye"></i>
                                            </a>
                                            <?php endif; ?>
                                            <a href="?edit=<?php echo $report['id']; ?>" class="btn btn-sm btn-primary" title="<?php echo $__t('सम्पादन','Edit'); ?>">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="POST" class="svc-inline-form" onsubmit="return confirm('<?php echo $__t('के तपाईं निश्चित हुनुहुन्छ?', 'Are you sure?'); ?>')">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
    <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $report['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="<?php echo $__t('मेटाउनुहोस्','Delete'); ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($reports)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="lucide-icon fa-3x mb-3 d-block opacity-50" aria-hidden="true" data-lucide="inbox"></i>
                                            <h6><?php echo $__t('कुनै प्रतिवेदन छैन','No reports found'); ?></h6>
                                            <small><?php echo $__t('पहिले प्रतिवेदन थप्नुहोस्','Add a report first'); ?></small>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const reportType = document.getElementById('reportType');
    const monthField = document.getElementById('monthField');
    const quarterField = document.getElementById('quarterField');
    if (!reportType || !monthField || !quarterField) return;

    function toggleFields() {
        const type = reportType.value;
        monthField.classList.toggle('d-none', type !== 'monthly');
        quarterField.classList.toggle('d-none', type !== 'quarterly');
    }

    reportType.addEventListener('change', toggleFields);
    toggleFields();
});
</script>


<?php require_once 'includes/admin-footer.php'; ?>
