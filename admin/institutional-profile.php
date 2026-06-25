<?php
/**
 * Admin: संस्थागत प्रोफाइल व्यवस्थापन
 * File: admin/institutional-profile.php
 *
 * DESIGN PATTERN: feedbacks.php style
 *   — List view by default (URL: institutional-profile.php)
 *   — Full-page Add form  (URL: institutional-profile.php?action=add)
 *   — Full-page Edit form (URL: institutional-profile.php?action=edit&id=N)
 *
 * FIELDS: आर्थिक वर्ष, मिति (BS+AD), सदस्य, शेयर, बचत, ऋण,
 *         सम्पत्ति, जगेडा, अन्य कोष, बैंक/नगद, स्थिर सम्पत्ति,
 *         ऋणी सदस्य, Loan Reserve, NPA, NPL, Liquidity
 */

/* ─── 1. Config + session + DB ─── */
define('IS_ADMIN_PAGE', true);
require_once '../includes/config.php';
requireAdminLogin();

$db      = getDB();
$selfUrl = 'institutional-profile.php';

/* ─── 2. Table existence check ─── */
$tableExists = false;
try {
    $r = $db->query("SHOW TABLES LIKE 'institutional_profile'");
    $tableExists = ($r->rowCount() > 0);
} catch (Exception $e) {}

/* ─── 3. Auto-ALTER: Add missing columns (MySQL 5.7 compatible — no IF NOT EXISTS)
         try-catch प्रत्येकमा: column पहिले नै छ भने "Duplicate column" error → caught ─── */
if ($tableExists) {
    $alters = [
        /* Originally missing from CREATE TABLE (old servers) */
        "ALTER TABLE institutional_profile ADD COLUMN report_date_bs VARCHAR(60) DEFAULT '' COMMENT 'मिति बि.सं.'",
        "ALTER TABLE institutional_profile ADD COLUMN report_date_ad DATE NULL COMMENT 'मिति A.D.'",
        "ALTER TABLE institutional_profile ADD COLUMN report_note TEXT DEFAULT NULL COMMENT 'थप टिप्पणी'",
        "ALTER TABLE institutional_profile ADD COLUMN total_balance_member INT DEFAULT 0 COMMENT 'शेष सदस्य'",
        "ALTER TABLE institutional_profile ADD COLUMN total_loan_reserve_fund DECIMAL(15,2) DEFAULT 0",
        "ALTER TABLE institutional_profile ADD COLUMN total_loan_reserve_percent DECIMAL(8,2) DEFAULT 0",
        /* Additional columns needed for full financial profile */
        "ALTER TABLE institutional_profile ADD COLUMN share_capital_percent DECIMAL(8,2) DEFAULT 0 COMMENT 'शेयर % वृद्धि'",
        "ALTER TABLE institutional_profile ADD COLUMN reserved_fund DECIMAL(18,2) DEFAULT 0 COMMENT 'जगेडा कोष'",
        "ALTER TABLE institutional_profile ADD COLUMN reserved_fund_percent DECIMAL(8,2) DEFAULT 0 COMMENT 'जगेडा % वृद्धि'",
        "ALTER TABLE institutional_profile ADD COLUMN deposit_percent DECIMAL(8,2) DEFAULT 0 COMMENT 'बचत % वृद्धि'",
        "ALTER TABLE institutional_profile ADD COLUMN loan_percent DECIMAL(8,2) DEFAULT 0 COMMENT 'ऋण % वृद्धि'",
        "ALTER TABLE institutional_profile ADD COLUMN liquidity_percent DECIMAL(8,2) DEFAULT 0 COMMENT 'तरलता अनुपात'",
        "ALTER TABLE institutional_profile ADD COLUMN npl_percent DECIMAL(5,2) DEFAULT 0 COMMENT 'NPL %'",
        "ALTER TABLE institutional_profile ADD COLUMN attachment_path VARCHAR(255) DEFAULT '' COMMENT 'PDF/photo attachment'",
        "ALTER TABLE institutional_profile ADD COLUMN other_fund DECIMAL(18,2) DEFAULT 0 COMMENT 'अन्य कोष'",
        "ALTER TABLE institutional_profile ADD COLUMN bank_cash_balance DECIMAL(18,2) DEFAULT 0 COMMENT 'बैंक तथा नगद मौज्दात'",
        "ALTER TABLE institutional_profile ADD COLUMN fixed_assets DECIMAL(18,2) DEFAULT 0 COMMENT 'स्थिर सम्पत्ति'",
        "ALTER TABLE institutional_profile ADD COLUMN total_loan_members INT DEFAULT 0 COMMENT 'कुल ऋणी सदस्य'",
    ];
    foreach ($alters as $sql) {
        try { $db->exec($sql); } catch (Exception $e) { /* Column exists or other ignorable error */ }
    }
}

/* ─── 4. POST handler (runs before HTML; config.php starts session) ─── */
$flashSuccess = '';
$flashError   = '';

if ($tableExists && $_SERVER['REQUEST_METHOD'] === 'POST') {

    /* CSRF check — session started by config.php */
    if (!verifyCSRFToken()) {
        $_SESSION['flash_error'] = 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।';
        header('Location: ' . $selfUrl);
        exit;
    }

    $action = clean_text($_POST['action'] ?? '');

    /* ── ADD or EDIT ── */
    if (in_array($action, ['add', 'edit'])) {
        $id                         = (int)($_POST['id'] ?? 0);
        $fiscal_year                = clean_text($_POST['fiscal_year']               ?? '');
        $report_date_bs             = clean_text($_POST['report_date_bs']            ?? '');
        $report_date_ad_raw         = clean_text($_POST['report_date_ad']            ?? '');
        $report_date_ad             = ($report_date_ad_raw !== '') ? $report_date_ad_raw : null;
        $total_members              = (int)($_POST['total_members']                ?? 0);
        $total_balance_member       = (int)($_POST['total_balance_member']         ?? 0);
        $total_assets               = (float)($_POST['total_assets']               ?? 0);
        $share_capital              = (float)($_POST['share_capital']              ?? 0);
        $share_capital_percent      = (float)($_POST['share_capital_percent']      ?? 0);
        $reserved_fund              = (float)($_POST['reserved_fund']              ?? 0);
        $reserved_fund_percent      = (float)($_POST['reserved_fund_percent']      ?? 0);
        $other_fund                 = (float)($_POST['other_fund']                 ?? 0);
        $bank_cash_balance          = (float)($_POST['bank_cash_balance']          ?? 0);
        $fixed_assets               = (float)($_POST['fixed_assets']               ?? 0);
        $deposit                    = (float)($_POST['deposit']                    ?? 0);
        $deposit_percent            = (float)($_POST['deposit_percent']            ?? 0);
        $loan                       = (float)($_POST['loan']                       ?? 0);
        $loan_percent               = (float)($_POST['loan_percent']               ?? 0);
        $total_loan_members         = (int)($_POST['total_loan_members']           ?? 0);
        $total_loan_reserve_fund    = (float)($_POST['total_loan_reserve_fund']    ?? 0);
        $total_loan_reserve_percent = (float)($_POST['total_loan_reserve_percent'] ?? 0);
        $npa_percent                = (float)($_POST['npa_percent']                ?? 0);
        $liquidity_percent          = (float)($_POST['liquidity_percent']          ?? 0);
        $npl_percent                = (float)($_POST['npl_percent']                ?? 0);
        $report_note                = clean_text($_POST['report_note']               ?? '');
        $attachment_path            = clean_text($_POST['existing_attachment_path']   ?? '');
        $is_active                  = isset($_POST['is_active']) ? 1 : 0;

        if (empty($fiscal_year)) {
            $_SESSION['flash_error'] = 'आर्थिक वर्ष अनिवार्य छ।';
            $redirect = $action === 'add' ? $selfUrl . '?action=add' : $selfUrl . '?action=edit&id=' . $id;
            header('Location: ' . $redirect);
            exit;
        }

        if (!empty($_FILES['attachment_file']['name']) && ($_FILES['attachment_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['attachment_file'], 'institutional_profile');
            if (!empty($upload['success']) && !empty($upload['path'])) {
                $attachment_path = $upload['path'];
            }
        } elseif (!empty($_POST['remove_attachment'])) {
            $attachment_path = '';
        }

        $fields = compact(
            'fiscal_year','report_date_bs','report_date_ad',
            'total_members','total_balance_member','total_assets',
            'share_capital','share_capital_percent',
            'reserved_fund','reserved_fund_percent','other_fund',
            'bank_cash_balance','fixed_assets',
            'deposit','deposit_percent',
            'loan','loan_percent','total_loan_members',
            'total_loan_reserve_fund','total_loan_reserve_percent',
            'npa_percent','liquidity_percent','npl_percent',
            'report_note','attachment_path','is_active'
        );

        try {
            if ($action === 'add') {
                $cols = implode(', ', array_keys($fields));
                $phs  = implode(', ', array_fill(0, count($fields), '?'));
                $stmt = $db->prepare("INSERT INTO institutional_profile ({$cols}) VALUES ({$phs})");
                $stmt->execute(array_values($fields));
                $_SESSION['flash_success'] = 'नयाँ प्रोफाइल सफलतापूर्वक थपियो।';
            } else {
                $sets = implode(', ', array_map(function ($k) {
                    return "{$k} = ?";
                }, array_keys($fields)));
                $stmt = $db->prepare("UPDATE institutional_profile SET {$sets} WHERE id = ?");
                $stmt->execute([...array_values($fields), $id]);
                $_SESSION['flash_success'] = 'प्रोफाइल अपडेट भयो।';
            }
        } catch (Exception $e) {
            $msg = str_contains($e->getMessage(), 'Duplicate')
                ? 'यो आर्थिक वर्षको प्रोफाइल पहिले नै छ।'
                : 'त्रुटि: ' . $e->getMessage();
            $_SESSION['flash_error'] = $msg;
            $redirect = $action === 'add' ? $selfUrl . '?action=add' : $selfUrl . '?action=edit&id=' . $id;
            header('Location: ' . $redirect);
            exit;
        }
        header('Location: ' . $selfUrl);
        exit;
    }

    /* ── TOGGLE active/hidden ── */
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare("UPDATE institutional_profile SET is_active = 1 - is_active WHERE id = ?")
               ->execute([$id]);
            $_SESSION['flash_success'] = 'स्थिति परिवर्तन भयो।';
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'त्रुटि: ' . $e->getMessage();
        }
        header('Location: ' . $selfUrl);
        exit;
    }

    /* ── DELETE ── */
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare("DELETE FROM institutional_profile WHERE id = ?")->execute([$id]);
            $_SESSION['flash_success'] = 'रेकर्ड हटाइयो।';
        } catch (Exception $e) {
            $_SESSION['flash_error'] = 'त्रुटि: ' . $e->getMessage();
        }
        header('Location: ' . $selfUrl);
        exit;
    }
}

/* ─── 5. Flash messages from session ─── */
if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

/* ─── 6. Determine current view ─── */
$viewAction = $_GET['action'] ?? 'list';  /* 'list' | 'add' | 'edit' */
if (!in_array($viewAction, ['list', 'add', 'edit'], true)) {
    $viewAction = 'list';
}
$editId     = (int)($_GET['id'] ?? 0);
$editRecord = null;

if ($viewAction === 'edit' && $editId && $tableExists) {
    $s = $db->prepare("SELECT * FROM institutional_profile WHERE id = ?");
    $s->execute([$editId]);
    $editRecord = $s->fetch();
    if (!$editRecord) {
        header('Location: ' . $selfUrl);
        exit;
    }
}

/* ─── 7. Fetch all records for list view ─── */
$profiles = [];
if ($tableExists && $viewAction === 'list') {
    try {
        $profiles = $db->query("SELECT * FROM institutional_profile ORDER BY fiscal_year DESC")->fetchAll();
    } catch (Exception $e) {}
}

/* ─── 8. Fiscal year options ─── */
$fiscalYears = [];
for ($yr = 2095; $yr >= 2070; $yr--) {
    $next = $yr + 1;
    $fiscalYears[] = $yr . '/' . sprintf('%02d', $next % 100);
}

/* ─── 9. Page title + admin-header ─── */
$pageTitle   = 'संस्थागत प्रोफाइल';
$currentPage = 'institutional-profile';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

/* ─── Helper: NPA color class ─── */
function npaClass(float $v): string {
    if ($v < 3)  return 'npa-good';
    if ($v < 5)  return 'npa-warning';
    return 'npa-danger';
}
/* ─── Helper: short number (रू. in crore/lakh) ─── */
function shortAmt(float $v): string {
    if ($v >= 1e7)  return 'रू. ' . round($v / 1e7, 2) . ' Cr';
    if ($v >= 1e5)  return 'रू. ' . round($v / 1e5, 1) . ' L';
    return 'रू. ' . number_format($v);
}

/* ─── CSRF token (set by admin-header.php) ─── */
$csrf = $csrfToken;
?>

<div class="container-fluid py-4">

<?php echo adminAlert('success', $flashSuccess); ?>
<?php echo adminAlert('danger',  $flashError); ?>

<?php /* ═══════════════════════════════════════════════════════
         LIST VIEW — default view
       ═══════════════════════════════════════════════════════ */
if ($viewAction === 'list'): ?>

<?php
$totalRecords = count($profiles);
$activeCount  = count(array_filter($profiles, function ($p) {
    return !empty($p['is_active']);
}));
echo adminPageHeader(
    'संस्थागत प्रोफाइल व्यवस्थापन',
    'fa-building-columns',
    'हरेक आर्थिक वर्षको financial data — थप्नुहोस्, सम्पादन गर्नुहोस्, active/inactive गर्नुहोस्।',
    adminStatLink($selfUrl, 'secondary', 'जम्मा', $totalRecords)
    . adminStatLink($selfUrl, 'success', 'Active', $activeCount, false)
    . adminAddBtn('नयाँ आर्थिक वर्ष थप्नुहोस्', $selfUrl . '?action=add')
);
?>

<div class="admin-table-card">
  <!-- ── Live Search / Filter Bar ── -->
  <div class="ip-search-bar d-flex flex-wrap align-items-center gap-2 px-3 py-3 ip-search-shell">
    <div class="input-group ip-search-group">
      <span class="input-group-text bg-white border-end-0 ip-search-addon">
        <i class="fas fa-search text-success"></i>
      </span>
      <input type="text" id="ipSearchFY"
             class="form-control border-start-0 ip-search-input"
             placeholder="आ.व. खोज्नुहोस् (जस्तै: 2080/81)"
             autocomplete="off">
    </div>
    <select id="ipFilterStatus" class="form-select ip-filter-select">
      <option value="">सबै स्थिति</option>
      <option value="active">Active मात्र</option>
      <option value="inactive">Inactive मात्र</option>
    </select>
    <button type="button" id="ipClearFilter"
            class="btn btn-sm btn-outline-secondary"
            title="Filter हटाउनुहोस्" style="display:none;">
      <i class="fas fa-times me-1"></i>Clear
    </button>
    <span id="ipCountBadge" class="ms-auto badge ip-count-badge ip-count-default">
      <?php echo $totalRecords; ?> records
    </span>
  </div>
  <?php if (!empty($profiles)): ?>
  <ul class="nav nav-pills admin-inner-tabstrip flex-wrap gap-2 px-3 py-2 mx-3 mt-2 mb-0" role="tablist" id="ipViewTabs">
    <li class="nav-item" role="presentation">
      <button type="button" class="nav-link py-2 active ip-view-tab-btn" data-ip-view="main" id="ipViewMainBtn" aria-selected="true">
        <i class="fas fa-id-card me-1"></i>मुख्य जानकारी
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button type="button" class="nav-link py-2 ip-view-tab-btn" data-ip-view="finance" id="ipViewFinBtn" aria-selected="false">
        <i class="fas fa-coins me-1"></i>वित्तीय रकम
      </button>
    </li>
  </ul>
  <?php endif; ?>
  <div class="ip-profile-table-wrap px-0" id="ipTableWrap" data-ip-view="main">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" id="ipTable">
      <thead>
        <tr>
          <th class="ip-col ip-col-shared">आ.व.</th>
          <th class="ip-col ip-col-main">मिति (बि.सं.)</th>
          <th class="ip-col ip-col-main">मिति (A.D.)</th>
          <th class="ip-col ip-col-main">सदस्य</th>
          <th class="ip-col ip-col-fin">शेयर पूँजी</th>
          <th class="ip-col ip-col-fin">बचत</th>
          <th class="ip-col ip-col-fin">ऋण</th>
          <th class="ip-col ip-col-shared">कुल सम्पत्ति</th>
          <th class="ip-col ip-col-shared">NPA %</th>
          <th class="ip-col ip-col-shared">स्थिति</th>
          <th class="ip-col ip-col-shared ip-col-actions">कार्य</th>
        </tr>
      </thead>
      <tbody>
        <!-- no-results row: filter mismatch bhaye dekhinccha -->
        <tr id="ipNoResults" class="ip-no-results-row ip-hidden">
          <td colspan="11" class="text-center py-5">
            <i class="fas fa-search fa-2x text-muted mb-2 d-block"></i>
            <span class="text-muted">खोजी अनुसार कुनै record भेटिएन।</span>
            <br>
            <button class="btn btn-sm btn-outline-success mt-2" onclick="clearIPFilter()">
              <i class="fas fa-times me-1"></i>Filter हटाउनुहोस्
            </button>
          </td>
        </tr>
        <?php if (empty($profiles)): ?>
        <?php echo adminEmptyRow(11, 'कुनै डाटा उपलब्ध छैन। "नयाँ आर्थिक वर्ष थप्नुहोस्" बटनबाट सुरु गर्नुहोस्।'); ?>
        <?php else: ?>
        <?php foreach ($profiles as $p): ?>
        <tr class="ip-data-row"
            data-fy="<?php echo strtolower(htmlspecialchars($p['fiscal_year'], ENT_QUOTES)); ?>"
            data-status="<?php echo $p['is_active'] ? 'active' : 'inactive'; ?>">
          <td class="ip-col ip-col-shared">
            <strong class="text-primary"><?php echo htmlspecialchars($p['fiscal_year']); ?></strong>
          </td>
          <td class="ip-col ip-col-main">
            <?php if (!empty($p['report_date_bs'])): ?>
            <span class="badge bg-info bg-opacity-15 text-info border border-info border-opacity-25">
              <i class="fas fa-calendar-days me-1"></i><?php echo htmlspecialchars($p['report_date_bs']); ?>
            </span>
            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
          </td>
          <td class="ip-col ip-col-main">
            <?php echo !empty($p['report_date_ad'])
              ? '<small class="text-muted">' . date('d M Y', strtotime($p['report_date_ad'])) . '</small>'
              : '<span class="text-muted small">—</span>'; ?>
          </td>
          <td class="ip-col ip-col-main"><?php echo number_format((int)$p['total_members']); ?></td>
          <td class="ip-col ip-col-fin admin-amount">
            <?php echo shortAmt((float)$p['share_capital']); ?>
            <?php if ($p['share_capital_percent']): ?>
            <br><small class="admin-amount-sub">(<?php echo $p['share_capital_percent']; ?>%)</small>
            <?php endif; ?>
          </td>
          <td class="ip-col ip-col-fin admin-amount">
            <?php echo shortAmt((float)$p['deposit']); ?>
            <?php if ($p['deposit_percent']): ?>
            <br><small class="admin-amount-sub">(<?php echo $p['deposit_percent']; ?>%)</small>
            <?php endif; ?>
          </td>
          <td class="ip-col ip-col-fin admin-amount">
            <?php echo shortAmt((float)$p['loan']); ?>
            <?php if ($p['loan_percent']): ?>
            <br><small class="admin-amount-sub">(<?php echo $p['loan_percent']; ?>%)</small>
            <?php endif; ?>
          </td>
          <td class="ip-col ip-col-shared admin-amount"><?php echo shortAmt((float)$p['total_assets']); ?></td>
          <td class="ip-col ip-col-shared">
            <?php $npa = (float)$p['npa_percent']; ?>
            <span class="badge <?php echo npaClass($npa); ?>"><?php echo $npa; ?>%</span>
          </td>
          <td class="ip-col ip-col-shared"><?php echo adminToggleBtn((int)$p['id'], $p['is_active'], $csrf); ?></td>
          <td class="ip-col ip-col-shared">
            <div class="d-flex gap-1">
              <?php echo adminEditBtn('', $selfUrl . '?action=edit&id=' . $p['id']); ?>
              <?php echo adminDeleteBtn((int)$p['id'], $csrf, $p['fiscal_year'] . ' को record हटाउने?'); ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  </div><!-- /.ip-profile-table-wrap -->
</div>

<?php /* ═══════════════════════════════════════════════════════
         ADD / EDIT FORM VIEW (full-page, not modal)
       ═══════════════════════════════════════════════════════ */
elseif ($viewAction === 'add' || ($viewAction === 'edit' && $editRecord)):

$isEdit  = ($viewAction === 'edit');
$r       = $editRecord ?? [];
$formTitle = $isEdit
    ? '<i class="fas fa-pen me-2"></i>' . htmlspecialchars($r['fiscal_year']) . ' — सम्पादन'
    : '<i class="fas fa-plus-circle me-2"></i>नयाँ संस्थागत प्रोफाइल थप्नुहोस्';

/* Helper: pre-fill value or default */
$v = function (string $key, $default = '') use ($isEdit, $r) {
    return $isEdit ? ($r[$key] ?? $default) : $default;
};

echo adminPageHeader(
    $isEdit ? 'प्रोफाइल सम्पादन' : 'नयाँ प्रोफाइल',
    'fa-building-columns',
    $isEdit ? $r['fiscal_year'] . ' को financial data अद्यावधिक गर्नुहोस्' : 'हरेक आर्थिक वर्षको financial data राख्नुहोस्',
    adminBackBtn($selfUrl)
);
?>

<div class="admin-form-page ip-admin-form-page">
  <div class="card mb-3 ip-admin-form-card">
    <div class="card-header ip-admin-form-hero">
      <div>
        <span class="ip-admin-kicker"><i class="fas fa-chart-line"></i> आर्थिक विवरण</span>
        <h5><?php echo $formTitle; ?></h5>
        <p><?php echo $isEdit ? 'सार्वजनिक प्रोफाइलमा देखिने वार्षिक विवरण यहाँबाट अद्यावधिक गर्नुहोस्।' : 'नयाँ आर्थिक वर्षको प्रोफाइल compact रूपमा भर्नुहोस्।'; ?></p>
      </div>
      <a href="<?php echo $selfUrl; ?>" class="btn btn-light btn-sm ip-admin-list-link" data-testid="institutional-profile-back-to-list-link">
        <i class="fas fa-list me-1"></i>सूची
      </a>
    </div>

    <form id="profileMainForm" method="POST" action="<?php echo $selfUrl; ?>" class="needs-validation" novalidate enctype="multipart/form-data">
      <input type="hidden" name="action" value="<?php echo $isEdit ? 'edit' : 'add'; ?>">
      <input type="hidden" name="id"     value="<?php echo $isEdit ? (int)$r['id'] : 0; ?>">
      <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

      <div class="card-body">

        <!-- ── SECTION 1: आर्थिक वर्ष + मिति ── -->
        <?php echo adminSectionCard('आर्थिक वर्ष र मिति', 'fa-calendar', 'primary', '
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">आर्थिक वर्ष <span class="text-danger">*</span></label>
              ' . str_replace('<select ', '<select data-testid="institutional-profile-fiscal-year-select" ', adminFiscalYearSelect('fiscal_year', (string)$v('fiscal_year'), true, 'fieldFiscalYear')) . '
              <small class="text-muted">उदाहरण: 2080/81</small>
            </div>
            <div class="col-md-4">
              <label class="form-label">मिति (बि.सं.) <small class="text-muted">(optional)</small></label>
              <div class="input-group">
                <input type="text" name="report_date_bs" id="fieldDateBs"
                       class="form-control nepali-datepicker"
                       data-testid="institutional-profile-report-date-bs-input"
                       value="' . htmlspecialchars((string)$v('report_date_bs')) . '"
                       placeholder="YYYY-MM-DD" autocomplete="off">
                <span class="input-group-text bg-success text-white border-success ndp-trigger ip-pointer" title="क्यालेन्डर खोल्नुहोस्" data-testid="institutional-profile-report-date-bs-calendar-button">
                  <i class="fas fa-calendar-alt"></i>
                </span>
              </div>
              <small class="text-muted">BS मिति — क्यालेन्डर आइकनमा क्लिक गर्नुहोस्</small>
            </div>
            <div class="col-md-4">
              <label class="form-label">मिति (A.D.) <small class="text-muted">(optional)</small></label>
              <input type="date" name="report_date_ad" id="fieldDateAd"
                     class="form-control"
                     data-testid="institutional-profile-report-date-ad-input"
                     value="' . htmlspecialchars((string)$v('report_date_ad')) . '">
              <small class="text-muted">Same मिति — Gregorian format</small>
            </div>
          </div>
        '); ?>

        <!-- ── SECTION 2: सदस्य + कुल सम्पत्ति ── -->
        <?php echo adminSectionCard('सदस्य र कुल सम्पत्ति', 'fa-users', 'success', '
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">कुल सदस्य संख्या</label>
              <input type="number" name="total_members" class="form-control" data-testid="institutional-profile-total-members-input"
                     min="0" value="' . (int)$v('total_members', 0) . '">
            </div>
            <div class="col-md-4">
              <label class="form-label">शेष सदस्य संख्या</label>
              <input type="number" name="total_balance_member" class="form-control" data-testid="institutional-profile-total-balance-member-input"
                     min="0" value="' . (int)$v('total_balance_member', 0) . '">
            </div>
            <div class="col-md-4">
              <label class="form-label">कुल सम्पत्ति (रू.)</label>
              <input type="number" name="total_assets" class="form-control" data-testid="institutional-profile-total-assets-input"
                     step="0.01" min="0" value="' . (float)$v('total_assets', 0) . '">
            </div>
          </div>
        '); ?>

        <!-- ── SECTION 3: शेयर पूँजी + जगेडा ── -->
        <?php echo adminSectionCard('शेयर पूँजी, कोष र सम्पत्ति', 'fa-coins', 'warning', '
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">शेयर पूँजी (रू.)</label>
              <input type="number" name="share_capital" class="form-control" data-testid="institutional-profile-share-capital-input"
                     step="0.01" min="0" value="' . (float)$v('share_capital', 0) . '">
            </div>
            <div class="col-md-3">
              <label class="form-label">शेयर पूँजी % वृद्धि</label>
              <div class="input-group">
                <input type="number" name="share_capital_percent" class="form-control" data-testid="institutional-profile-share-capital-percent-input"
                       step="0.01" value="' . (float)$v('share_capital_percent', 0) . '">
                <span class="input-group-text">%</span>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">जगेडा कोष (रू.)</label>
              <input type="number" name="reserved_fund" class="form-control" data-testid="institutional-profile-reserved-fund-input"
                     step="0.01" min="0" value="' . (float)$v('reserved_fund', 0) . '">
            </div>
            <div class="col-md-3">
              <label class="form-label">जगेडा कोष % वृद्धि</label>
              <div class="input-group">
                <input type="number" name="reserved_fund_percent" class="form-control" data-testid="institutional-profile-reserved-fund-percent-input"
                       step="0.01" value="' . (float)$v('reserved_fund_percent', 0) . '">
                <span class="input-group-text">%</span>
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">अन्य कोष (रू.)</label>
              <input type="number" name="other_fund" class="form-control" data-testid="institutional-profile-other-fund-input"
                     step="0.01" min="0" value="' . (float)$v('other_fund', 0) . '">
            </div>
            <div class="col-md-4">
              <label class="form-label">बैंक तथा नगद मौज्दात (रू.)</label>
              <input type="number" name="bank_cash_balance" class="form-control" data-testid="institutional-profile-bank-cash-balance-input"
                     step="0.01" min="0" value="' . (float)$v('bank_cash_balance', 0) . '">
            </div>
            <div class="col-md-4">
              <label class="form-label">स्थिर सम्पत्ति (रू.)</label>
              <input type="number" name="fixed_assets" class="form-control" data-testid="institutional-profile-fixed-assets-input"
                     step="0.01" min="0" value="' . (float)$v('fixed_assets', 0) . '">
            </div>
          </div>
        '); ?>

        <!-- ── SECTION 4: बचत + ऋण ── -->
        <?php echo adminSectionCard('बचत र ऋण', 'fa-piggy-bank', 'info', '
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">कुल बचत (रू.)</label>
              <input type="number" name="deposit" class="form-control" data-testid="institutional-profile-deposit-input"
                     step="0.01" min="0" value="' . (float)$v('deposit', 0) . '">
            </div>
            <div class="col-md-3">
              <label class="form-label">बचत % वृद्धि</label>
              <div class="input-group">
                <input type="number" name="deposit_percent" class="form-control" data-testid="institutional-profile-deposit-percent-input"
                       step="0.01" value="' . (float)$v('deposit_percent', 0) . '">
                <span class="input-group-text">%</span>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">कुल ऋण लगानी (रू.)</label>
              <input type="number" name="loan" class="form-control" data-testid="institutional-profile-loan-input"
                     step="0.01" min="0" value="' . (float)$v('loan', 0) . '">
            </div>
            <div class="col-md-3">
              <label class="form-label">ऋण % वृद्धि</label>
              <div class="input-group">
                <input type="number" name="loan_percent" class="form-control" data-testid="institutional-profile-loan-percent-input"
                       step="0.01" value="' . (float)$v('loan_percent', 0) . '">
                <span class="input-group-text">%</span>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">कुल ऋणी सदस्य</label>
              <input type="number" name="total_loan_members" class="form-control" data-testid="institutional-profile-total-loan-members-input"
                     min="0" value="' . (int)$v('total_loan_members', 0) . '">
            </div>
          </div>
        '); ?>

        <!-- ── SECTION 5: Loan Reserve + Quality Metrics ── -->
        <?php echo adminSectionCard('ऋण जोखिम कोष र गुणस्तर सूचकाङ्क', 'fa-chart-line', 'danger', '
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">ऋण जोखिम कोष (रू.)</label>
              <input type="number" name="total_loan_reserve_fund" class="form-control" data-testid="institutional-profile-loan-reserve-fund-input"
                     step="0.01" min="0" value="' . (float)$v('total_loan_reserve_fund', 0) . '">
            </div>
            <div class="col-md-2">
              <label class="form-label">Loan Reserve %</label>
              <div class="input-group">
                <input type="number" name="total_loan_reserve_percent" class="form-control" data-testid="institutional-profile-loan-reserve-percent-input"
                       step="0.01" value="' . (float)$v('total_loan_reserve_percent', 0) . '">
                <span class="input-group-text">%</span>
              </div>
            </div>
            <div class="col-md-2">
              <label class="form-label">NPA %
                <i class="fas fa-circle-info ms-1 text-muted" data-bs-toggle="tooltip"
                   title="Non-Performing Assets — कम राम्रो (अधिकतम 5%)"></i>
              </label>
              <div class="input-group">
                <input type="number" name="npa_percent" class="form-control" data-testid="institutional-profile-npa-percent-input"
                       step="0.01" min="0" max="100" value="' . (float)$v('npa_percent', 0) . '">
                <span class="input-group-text">%</span>
              </div>
              <small class="text-muted">5% भन्दा कम राम्रो</small>
            </div>
            <div class="col-md-2">
              <label class="form-label">Liquidity %</label>
              <div class="input-group">
                <input type="number" name="liquidity_percent" class="form-control" data-testid="institutional-profile-liquidity-percent-input"
                       step="0.01" min="0" max="100" value="' . (float)$v('liquidity_percent', 0) . '">
                <span class="input-group-text">%</span>
              </div>
            </div>
            <div class="col-md-2">
              <label class="form-label">NPL %</label>
              <div class="input-group">
                <input type="number" name="npl_percent" class="form-control" data-testid="institutional-profile-npl-percent-input"
                       step="0.01" min="0" max="100" value="' . (float)$v('npl_percent', 0) . '">
                <span class="input-group-text">%</span>
              </div>
            </div>
          </div>
        '); ?>

        <!-- ── SECTION 6: Document Upload ── -->
        <?php
        /* Pre-build existing attachment HTML */
        $_existAttachHtml = '<input type="hidden" name="existing_attachment_path" value="">';
        $_existAttachPreview = '';
        if ($isEdit && !empty($r['attachment_path'])) {
            $_attachUrl = htmlspecialchars(SITE_URL . ltrim($r['attachment_path'], '/'), ENT_QUOTES, 'UTF-8');
            $_attachExt = strtolower(pathinfo($r['attachment_path'], PATHINFO_EXTENSION));
            $_isImg     = in_array($_attachExt, ['jpg','jpeg','png','gif','webp']);
            $_thumb     = $_isImg
                ? '<img src="' . $_attachUrl . '" style="max-height:120px;max-width:100%;border-radius:8px;border:1px solid #e5e7eb;display:block;margin-bottom:10px;" alt="Preview">'
                : '<div class="d-flex align-items-center gap-3 p-3 border rounded mb-2" style="background:#fff7ed;">'
                  . '<i class="fas fa-file-pdf fa-2x" style="color:#dc2626;flex-shrink:0;"></i>'
                  . '<div><div class="fw-semibold text-dark" style="font-size:.85rem;">PDF कागजात</div>'
                  . '<div class="text-muted" style="font-size:.75rem;">' . htmlspecialchars(basename($r['attachment_path'])) . '</div></div></div>';
            $_existAttachPreview = $_thumb
                . '<div class="d-flex align-items-center gap-2 flex-wrap">'
                . '<a href="' . $_attachUrl . '" target="_blank" class="btn btn-sm btn-outline-success">'
                . '<i class="fas fa-eye me-1"></i>हेर्नुहोस् / डाउनलोड</a>'
                . '<div class="form-check mb-0 ms-2">'
                . '<input type="checkbox" class="form-check-input" name="remove_attachment" id="removeAttachment" value="1" data-testid="institutional-profile-remove-attachment-checkbox">'
                . '<label class="form-check-label small text-danger" for="removeAttachment">'
                . '<i class="fas fa-trash-can me-1"></i>यो कागजात हटाउने</label></div></div>';
            $_existAttachHtml = '<input type="hidden" name="existing_attachment_path" value="' . htmlspecialchars($r['attachment_path'], ENT_QUOTES) . '">';
        }

        echo adminSectionCard('संलग्न कागजात', 'fa-file-arrow-up', 'info',
            $_existAttachHtml
            . ($_existAttachPreview
                ? '<div class="mb-3"><label class="form-label fw-semibold text-dark">अहिलेको कागजात</label>' . $_existAttachPreview . '</div><hr class="my-3">'
                : '')
            . '<div class="row g-3">
                <div class="col-md-8">
                  <label class="form-label">नयाँ कागजात अपलोड गर्नुहोस् <span class="text-muted fw-normal">(optional)</span></label>
                  <input type="file" name="attachment_file" id="fieldAttachment"
                         class="form-control" accept=".pdf,.jpg,.jpeg" data-testid="institutional-profile-attachment-file-input"
                         onchange="(function(i){var w=document.getElementById(\'ipFpWrap\'),n=document.getElementById(\'ipFpName\');if(i.files.length){w.style.display=\'\';n.textContent=i.files[0].name;}else{w.style.display=\'none\';}})(this)">
                  <small class="text-muted d-block mt-1">
                    <i class="fas fa-circle-info me-1"></i>PDF, JPG वा JPEG — अधिकतम 10MB।
                    नयाँ upload गर्दा पुरानो कागजात replace हुन्छ।
                  </small>
                  <div id="ipFpWrap" class="mt-2" style="display:none;">
                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 py-2 px-3">
                      <i class="fas fa-check-circle me-1"></i>
                      <span id="ipFpName"></span>
                    </span>
                  </div>
                </div>
              </div>');
        ?>

        <!-- ── SECTION 7: Note + Status ── -->
        <?php echo adminSectionCard('थप टिप्पणी र स्थिति', 'fa-note-sticky', 'secondary', '
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">थप टिप्पणी <small class="text-muted">(optional)</small></label>
              <textarea name="report_note" class="form-control" rows="3" data-testid="institutional-profile-report-note-textarea"
                        placeholder="रिपोर्टको बारेमा कुनै विशेष टिप्पणी...">'. htmlspecialchars((string)$v('report_note')) .'</textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">Website मा देखाउने?</label>
              <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="is_active" id="fieldIsActive" data-testid="institutional-profile-is-active-switch"
                       ' . ((int)$v('is_active', 1) ? 'checked' : '') . '>
                <label class="form-check-label" for="fieldIsActive">
                  Website मा Active राख्नुहोस्
                </label>
              </div>
              <small class="text-muted">Checked = Public website मा देखिन्छ</small>
            </div>
          </div>
        '); ?>

      </div><!-- /card-body -->

    </form>

    <!-- form-footer: main form बाहिर — delete button nested form को bug नआओस् भनेर -->
    <div class="form-footer ip-admin-sticky-footer">
        <button type="submit" form="profileMainForm" class="btn btn-primary px-4" data-testid="institutional-profile-save-button">
          <i class="fas fa-save me-2"></i><?php echo $isEdit ? 'अपडेट गर्नुहोस्' : 'सेभ गर्नुहोस्'; ?>
        </button>
        <a href="<?php echo $selfUrl; ?>" class="btn btn-outline-secondary" data-testid="institutional-profile-cancel-link">
          <i class="fas fa-times me-1"></i>रद्द गर्नुहोस्
        </a>
        <?php if ($isEdit): ?>
        <div class="ms-auto">
          <?php echo adminDeleteBtn((int)$r['id'], $csrf, $r['fiscal_year'] . ' को record पूरै हटाउने?'); ?>
        </div>
        <?php endif; ?>
    </div>
  </div><!-- /card -->
</div><!-- /admin-form-page -->

<?php
/* ── Table doesn't exist — show error ── */
elseif (!$tableExists): ?>

<div class="alert alert-danger">
  <i class="fas fa-database me-2"></i>
  <strong>institutional_profile</strong> टेबल फेला परेन।
  <br><small>कृपया <code>database/install.sql</code> चलाउनुहोस् वा database setup गर्नुहोस्।</small>
</div>

<?php endif; ?>

</div><!-- /container-fluid -->


<script>
/* ── Live Search & Filter for Institutional Profile table ── */
(function () {
    var searchInput  = document.getElementById('ipSearchFY');
    var statusSelect = document.getElementById('ipFilterStatus');
    var clearBtn     = document.getElementById('ipClearFilter');
    var countBadge   = document.getElementById('ipCountBadge');
    var noResults    = document.getElementById('ipNoResults');

    if (!searchInput) return; /* Only on list view */

    var totalRows = document.querySelectorAll('tr.ip-data-row').length;

    function runFilter() {
        var query  = searchInput.value.trim().toLowerCase();
        var status = statusSelect ? statusSelect.value : '';
        var visible = 0;

        document.querySelectorAll('tr.ip-data-row').forEach(function (row) {
            var fy  = (row.getAttribute('data-fy') || '').toLowerCase();
            var st  = row.getAttribute('data-status') || '';

            var matchFY     = !query  || fy.indexOf(query) !== -1;
            var matchStatus = !status || st === status;

            if (matchFY && matchStatus) {
                row.style.display = '';
                visible++;
            } else {
                row.style.display = 'none';
            }
        });

        /* No-results row */
        if (noResults) {
            noResults.style.display = (visible === 0 && totalRows > 0) ? '' : 'none';
        }

        /* Count badge */
        if (countBadge) {
            if (query || status) {
                countBadge.textContent = visible + ' / ' + totalRows + ' records';
                countBadge.classList.remove('ip-count-default', 'ip-count-empty');
                countBadge.classList.add(visible === 0 ? 'ip-count-empty' : 'ip-count-default');
            } else {
                countBadge.textContent = totalRows + ' records';
                countBadge.classList.remove('ip-count-empty');
                countBadge.classList.add('ip-count-default');
            }
        }

        /* Clear button visibility */
        if (clearBtn) {
            clearBtn.style.display = (query || status) ? '' : 'none';
        }
    }

    /* Event listeners */
    if (searchInput)  searchInput.addEventListener('input', runFilter);
    if (statusSelect) statusSelect.addEventListener('change', runFilter);
    if (clearBtn)     clearBtn.addEventListener('click', clearIPFilter);

    /* Keyboard shortcut: / key focuses search */
    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && document.activeElement.tagName !== 'INPUT'
                          && document.activeElement.tagName !== 'TEXTAREA') {
            e.preventDefault();
            if (searchInput) { searchInput.focus(); searchInput.select(); }
        }
    });
})();

/* सूची: मुख्य / वित्तीय ट्याब — तालिकाको data-ip-view बदल्छ */
(function () {
    var wrap = document.getElementById('ipTableWrap');
    var btns = document.querySelectorAll('.ip-view-tab-btn');
    if (!wrap || !btns.length) return;

    function setView(v) {
        wrap.setAttribute('data-ip-view', v);
        btns.forEach(function (b) {
            var on = b.getAttribute('data-ip-view') === v;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        try { localStorage.setItem('ipListView', v); } catch (e) {}
    }

    btns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var v = btn.getAttribute('data-ip-view');
            if (v) setView(v);
        });
    });

    try {
        if (localStorage.getItem('ipListView') === 'finance') {
            setView('finance');
        }
    } catch (e) {}
})();

/* Global clear — called from no-results button too */
function clearIPFilter() {
    var s = document.getElementById('ipSearchFY');
    var f = document.getElementById('ipFilterStatus');
    if (s) { s.value = ''; }
    if (f) { f.value = ''; }
    document.querySelectorAll('tr.ip-data-row').forEach(function (r) {
        r.style.display = '';
    });
    var nr = document.getElementById('ipNoResults');
    if (nr) nr.style.display = 'none';
    var cb = document.getElementById('ipCountBadge');
    var total = document.querySelectorAll('tr.ip-data-row').length;
    if (cb) { cb.textContent = total + ' records'; cb.classList.remove('ip-count-empty'); cb.classList.add('ip-count-default'); }
    var clr = document.getElementById('ipClearFilter');
    if (clr) clr.style.display = 'none';
    if (s) s.focus();
}

/* AD date → BS approximate auto-fill */
document.addEventListener('DOMContentLoaded', function () {
    var adField = document.getElementById('fieldDateAd');
    var bsField = document.getElementById('fieldDateBs');
    if (adField && bsField) {
        adField.addEventListener('change', function () {
            if (!adField.value || bsField.value) return;
            var d = new Date(adField.value);
            if (isNaN(d)) return;
            try {
                if (typeof NepaliCalendar !== 'undefined') {
                    var bs = NepaliCalendar.toNepali(d.getFullYear(), d.getMonth()+1, d.getDate());
                    bsField.value = bs.year + '-'
                        + String(bs.month).padStart(2,'0') + '-'
                        + String(bs.day).padStart(2,'0');
                    return;
                }
            } catch(e) {}
            /* Fallback: approximate +56/57 years */
            var m = String(d.getMonth() + 1).padStart(2,'0');
            var day = String(d.getDate()).padStart(2,'0');
            bsField.value = (d.getFullYear() + 57) + '-' + m + '-' + day + ' (approx)';
        });
    }
    /* Bootstrap tooltip init */
    var tips = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tips.forEach(function(el) { new bootstrap.Tooltip(el); });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
