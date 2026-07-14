<?php
/**
 * सहकारी पात्रो — कार्यक्रम / दिवस / बैठक व्यवस्थापन
 * Admin sets BS dates; public calendar shows green highlight + sidebar list.
 */
$pageTitle = 'सहकारी पात्रो कार्यक्रम';
$currentPage = 'sahakari-calendar-events';
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/nepali-bs-convert.php';
require_once __DIR__ . '/../includes/sahakari-calendar-events-tables.php';

if (!isAdminLoggedIn()) {
    redirect(ADMIN_URL . 'index.php');
}

$db = getDB();
ensureSahakariCalendarEventTables($db);

$BS_MONTHS_NP = ['बैशाख','जेठ','असार','श्रावण','भाद्र','आश्विन','कार्तिक','मंसिर','पौष','माघ','फाल्गुन','चैत्र'];
$EVENT_TYPES = [
    'program' => 'कार्यक्रम',
    'diwash'  => 'दिवस',
    'board'   => 'Board बैठक',
    'staff'   => 'कर्मचारी बैठक',
    'meeting' => 'बैठक',
    'other'   => 'अन्य',
];

$todayBs = nepali_ad_to_bs_components([
    (int)date('Y'),
    (int)date('n'),
    (int)date('j'),
]) ?: [2083, 1, 1];
$defaultYear = (int)($todayBs[0] ?? 2083);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = (string)($_POST['action'] ?? '');
    $redirectYear = (int)($_POST['filter_year'] ?? $defaultYear);
    try {
        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $titleNp = clean_text($_POST['title_np'] ?? '', 200);
            $titleEn = clean_text($_POST['title_en'] ?? '', 200);
            $descNp = clean_text($_POST['description_np'] ?? '', 2000);
            $descEn = clean_text($_POST['description_en'] ?? '', 2000);
            $eventType = clean_text($_POST['event_type'] ?? 'program', 40);
            if (!isset($EVENT_TYPES[$eventType])) {
                $eventType = 'program';
            }
            $recurrence = clean_text($_POST['recurrence'] ?? 'once', 20);
            if (!in_array($recurrence, ['once', 'monthly'], true)) {
                $recurrence = 'once';
            }
            $bsYear = (int)($_POST['bs_year'] ?? 0);
            $bsDay = (int)($_POST['bs_day'] ?? 0);
            $bsMonth = ($recurrence === 'monthly')
                ? null
                : (int)($_POST['bs_month'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($titleNp === '') {
                throw new Exception('नेपाली शीर्षक आवश्यक छ।');
            }
            if ($bsYear < 1970 || $bsYear > 2100) {
                throw new Exception('मान्य बी.स. वर्ष राख्नुहोस् (१९७०–२१००)।');
            }
            if ($bsDay < 1 || $bsDay > 32) {
                throw new Exception('गते १–३२ बीच हुनुपर्छ।');
            }
            if ($recurrence === 'once' && ($bsMonth < 1 || $bsMonth > 12)) {
                throw new Exception('एक पटकका लागि महिना छान्नुहोस्।');
            }

            if ($id > 0) {
                $db->prepare('UPDATE sahakari_calendar_events SET title_np=?, title_en=?, description_np=?, description_en=?, event_type=?, recurrence=?, bs_year=?, bs_month=?, bs_day=?, is_active=? WHERE id=?')
                    ->execute([$titleNp, $titleEn, $descNp, $descEn, $eventType, $recurrence, $bsYear, $bsMonth, $bsDay, $isActive, $id]);
                setFlash('success', 'कार्यक्रम अपडेट भयो।');
            } else {
                $db->prepare('INSERT INTO sahakari_calendar_events (title_np, title_en, description_np, description_en, event_type, recurrence, bs_year, bs_month, bs_day, is_active) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$titleNp, $titleEn, $descNp, $descEn, $eventType, $recurrence, $bsYear, $bsMonth, $bsDay, $isActive]);
                setFlash('success', 'सहकारी पात्रोमा कार्यक्रम थपियो।');
            }
            $redirectYear = $bsYear;
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare('UPDATE sahakari_calendar_events SET is_active = 1 - is_active WHERE id=?')->execute([$id]);
                setFlash('success', 'स्थिति परिवर्तन भयो।');
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $db->prepare('DELETE FROM sahakari_calendar_events WHERE id=?')->execute([$id]);
                setFlash('success', 'कार्यक्रम हटाइयो।');
            }
        }
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
    }
    redirect('sahakari-calendar-events.php?year=' . max(1970, min(2100, $redirectYear)));
}

$filterYear = isset($_GET['year']) ? (int)$_GET['year'] : $defaultYear;
$filterYear = max(1970, min(2100, $filterYear));

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $st = $db->prepare('SELECT * FROM sahakari_calendar_events WHERE id=?');
    $st->execute([$editId]);
    $edit = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($edit) {
        $filterYear = (int)$edit['bs_year'];
    }
}

$rows = [];
try {
    $st = $db->prepare('SELECT * FROM sahakari_calendar_events WHERE bs_year=? ORDER BY recurrence ASC, bs_month IS NULL ASC, bs_month ASC, bs_day ASC, id ASC');
    $st->execute([$filterYear]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $rows = [];
}

$yearOptions = [];
try {
    $ys = $db->query('SELECT DISTINCT bs_year FROM sahakari_calendar_events ORDER BY bs_year DESC')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ys as $y) {
        $yearOptions[] = (int)$y;
    }
} catch (Throwable $e) {
    /* ignore */
}
if (!in_array($filterYear, $yearOptions, true)) {
    $yearOptions[] = $filterYear;
}
if (!in_array($defaultYear, $yearOptions, true)) {
    $yearOptions[] = $defaultYear;
}
rsort($yearOptions);

$form = [
    'id' => (int)($edit['id'] ?? 0),
    'title_np' => (string)($edit['title_np'] ?? ''),
    'title_en' => (string)($edit['title_en'] ?? ''),
    'description_np' => (string)($edit['description_np'] ?? ''),
    'description_en' => (string)($edit['description_en'] ?? ''),
    'event_type' => (string)($edit['event_type'] ?? 'program'),
    'recurrence' => (string)($edit['recurrence'] ?? 'once'),
    'bs_year' => (int)($edit['bs_year'] ?? $filterYear),
    'bs_month' => (int)($edit['bs_month'] ?? ($todayBs[1] ?? 1)),
    'bs_day' => (int)($edit['bs_day'] ?? ($todayBs[2] ?? 1)),
    'is_active' => isset($edit['is_active']) ? (int)$edit['is_active'] : 1,
];

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$liveCount = count(array_filter($rows, static fn($r) => (int)($r['is_active'] ?? 0) === 1));
?>

<?php
echo adminPageHeader(
    'सहकारी पात्रो कार्यक्रम',
    'fa-calendar-alt',
    'बोर्ड बैठक, दिवस, स्थापना दिन — मिति छानेर सहकारी पात्रोमा देखाउनुहोस्। मासिक दोहोरिने: हरेक महिनाको सोही गते।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-calendar-check me-1"></i>बी.स. ' . (int)$filterYear . ': ' . count($rows) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25"><i class="fas fa-check-circle me-1"></i>सक्रिय: ' . $liveCount . '</span>'
);
$_sceFlash = getFlash();
if ($_sceFlash) {
    $ft = ($_sceFlash['type'] ?? '') === 'error' ? 'danger' : (string)($_sceFlash['type'] ?? 'info');
    echo adminAlert($ft, (string)($_sceFlash['message'] ?? ''));
}
?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link <?php echo $form['id'] ? '' : 'active'; ?>" data-bs-toggle="tab" data-bs-target="#sce-list" type="button">
            <i class="fas fa-list me-2"></i>सूची
            <span class="badge bg-success ms-1"><?php echo count($rows); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?php echo $form['id'] ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#sce-form" type="button" id="sce-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="sceFormTabLabel"><?php echo $form['id'] ? 'सम्पादन' : 'नयाँ थप्नुहोस्'; ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade <?php echo $form['id'] ? '' : 'show active'; ?>" id="sce-list">
        <div class="card admin-table-card svc-flat-top-card">
            <div class="card-body border-bottom py-3">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label mb-1 small text-muted">वर्षिक फिल्टर (बी.स.)</label>
                        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($yearOptions as $y): ?>
                            <option value="<?php echo (int)$y; ?>" <?php echo $y === $filterYear ? 'selected' : ''; ?>><?php echo (int)$y; ?></option>
                            <?php endforeach; ?>
                            <?php for ($y = $defaultYear + 1; $y <= $defaultYear + 3; $y++):
                                if (in_array($y, $yearOptions, true)) {
                                    continue;
                                } ?>
                            <option value="<?php echo $y; ?>"><?php echo $y; ?> (नयाँ)</option>
                            <?php endfor; ?>
                            <?php for ($y = $defaultYear - 1; $y >= $defaultYear - 5; $y--):
                                if (in_array($y, $yearOptions, true)) {
                                    continue;
                                } ?>
                            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <a href="../sahakari-patro.php?tab=patro&cal_year=<?php echo (int)$filterYear; ?>" class="btn btn-sm btn-outline-success" target="_blank" rel="noopener">
                            <i class="fas fa-external-link-alt me-1"></i>पात्रोमा हेर्नुहोस्
                        </a>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>मिति</th>
                            <th>शीर्षक</th>
                            <th>प्रकार</th>
                            <th>दोहोरिने</th>
                            <th>स्थिति</th>
                            <th class="text-end">कार्य</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">बी.स. <?php echo (int)$filterYear; ?> मा कुनै कार्यक्रम छैन। नयाँ थप्नुहोस्।</td></tr>
                        <?php else: foreach ($rows as $r):
                            $rec = (string)($r['recurrence'] ?? 'once');
                            $dateLabel = $rec === 'monthly'
                                ? ('हरेक महिनाको ' . (int)$r['bs_day'] . ' गते')
                                : ($BS_MONTHS_NP[((int)$r['bs_month'] - 1)] . ' ' . (int)$r['bs_day'] . ', ' . (int)$r['bs_year']);
                            ?>
                        <tr>
                            <td class="text-nowrap"><strong><?php echo htmlspecialchars($dateLabel); ?></strong></td>
                            <td>
                                <div><?php echo htmlspecialchars((string)$r['title_np']); ?></div>
                                <?php if (trim((string)($r['title_en'] ?? '')) !== ''): ?>
                                <small class="text-muted"><?php echo htmlspecialchars((string)$r['title_en']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($EVENT_TYPES[$r['event_type'] ?? 'other'] ?? 'अन्य'); ?></span></td>
                            <td><?php echo $rec === 'monthly' ? 'मासिक' : 'एक पटक'; ?></td>
                            <td>
                                <?php if ((int)$r['is_active']): ?>
                                <span class="badge bg-success">सक्रिय</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">निष्क्रिय</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="?year=<?php echo (int)$filterYear; ?>&edit=<?php echo (int)$r['id']; ?>">सम्पादन</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('स्थिति बदल्ने?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="filter_year" value="<?php echo (int)$filterYear; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo (int)$r['is_active'] ? 'निष्क्रिय' : 'सक्रिय'; ?></button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('मेट्ने निश्चित हो?');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="filter_year" value="<?php echo (int)$filterYear; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">मेटाउनुहोस्</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade <?php echo $form['id'] ? 'show active' : ''; ?>" id="sce-form">
        <div class="card admin-table-card svc-flat-top-card">
            <div class="card-body">
                <form method="post" id="sce-event-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">
                    <input type="hidden" name="filter_year" value="<?php echo (int)$filterYear; ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">शीर्षक (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="title_np" class="form-control" required maxlength="200" value="<?php echo htmlspecialchars($form['title_np']); ?>" placeholder="जस्तै: Board बैठक">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Title (English)</label>
                            <input type="text" name="title_en" class="form-control" maxlength="200" value="<?php echo htmlspecialchars($form['title_en']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">प्रकार</label>
                            <select name="event_type" class="form-select">
                                <?php foreach ($EVENT_TYPES as $k => $lab): ?>
                                <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $form['event_type'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($lab); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">दोहोरिने</label>
                            <select name="recurrence" id="sce-recurrence" class="form-select">
                                <option value="once" <?php echo $form['recurrence'] === 'once' ? 'selected' : ''; ?>>एक पटक (मिति)</option>
                                <option value="monthly" <?php echo $form['recurrence'] === 'monthly' ? 'selected' : ''; ?>>हरेक महिनाको सोही गते</option>
                            </select>
                            <div class="form-text">मासिक = सो वर्षका सबै महिनाको त्यही गते (महिना छोटो भए छुट्छ)।</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">बी.स. वर्ष <span class="text-danger">*</span></label>
                            <input type="number" name="bs_year" class="form-control" min="1970" max="2100" required value="<?php echo (int)$form['bs_year']; ?>">
                        </div>
                        <div class="col-md-4" id="sce-month-wrap">
                            <label class="form-label">महिना <span class="text-danger">*</span></label>
                            <select name="bs_month" class="form-select">
                                <?php foreach ($BS_MONTHS_NP as $mi => $mn): ?>
                                <option value="<?php echo $mi + 1; ?>" <?php echo (int)$form['bs_month'] === ($mi + 1) ? 'selected' : ''; ?>><?php echo htmlspecialchars($mn); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">गते <span class="text-danger">*</span></label>
                            <input type="number" name="bs_day" class="form-control" min="1" max="32" required value="<?php echo (int)$form['bs_day']; ?>">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="sce-active" value="1" <?php echo $form['is_active'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="sce-active">सक्रिय (पात्रोमा देखाउने)</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">विवरण (नेपाली)</label>
                            <textarea name="description_np" class="form-control" rows="3"><?php echo htmlspecialchars($form['description_np']); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Description (EN)</label>
                            <textarea name="description_en" class="form-control" rows="3"><?php echo htmlspecialchars($form['description_en']); ?></textarea>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?php echo $form['id'] ? 'अपडेट गर्नुहोस्' : 'सेभ गर्नुहोस्'; ?></button>
                        <?php if ($form['id']): ?>
                        <a href="?year=<?php echo (int)$filterYear; ?>" class="btn btn-outline-secondary">रद्द</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
  var rec = document.getElementById('sce-recurrence');
  var wrap = document.getElementById('sce-month-wrap');
  function sync() {
    if (!rec || !wrap) return;
    wrap.style.display = rec.value === 'monthly' ? 'none' : '';
  }
  if (rec) {
    rec.addEventListener('change', sync);
    sync();
  }
})();
</script>

<?php require_once 'includes/admin-footer.php'; ?>
