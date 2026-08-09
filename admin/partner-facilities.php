<?php
/**
 * Admin: साझेदार सुविधा व्यवस्थापन (Partner Facilities CRUD)
 * Pattern: services.php जस्तै — Tab UI (List + Add/Edit)
 */
$pageTitle   = 'साझेदार सुविधा व्यवस्थापन';
$currentPage = 'partner-facilities';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/partner-facilities-tables.php';
require_once __DIR__ . '/../includes/member-partner-services-tables.php';

$db = getDB();
ensurePartnerFacilitiesTables($db);
ensureMemberPartnerServicesTable($db);

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    if (in_array($act, ['add', 'edit', 'delete', 'deactivate'], true)) {
        try {
            if ($act === 'add' || $act === 'edit') {
                $pname    = clean_text($_POST['partner_name'] ?? '', 200);
                $pnameEn  = clean_text($_POST['partner_name_en'] ?? '', 200);
                $location = clean_text($_POST['location'] ?? '', 200);
                $ftype    = clean_text($_POST['facility_type'] ?? '', 100);
                $discount = max(0, min(100, (float)($_POST['discount_percent'] ?? 0)));
                $dlabel   = clean_text($_POST['discount_label'] ?? '', 160);
                $desc     = clean_text($_POST['description'] ?? '', 8000);
                $descEn   = clean_text($_POST['description_en'] ?? '', 8000);
                $terms    = clean_text($_POST['terms_np'] ?? '', 4000);
                $phone    = preg_replace('/[^0-9+\-\s]/', '', clean_text($_POST['contact_phone'] ?? '', 30));
                $email    = strtolower(clean_text($_POST['contact_email'] ?? '', 120));
                $web      = clean_text($_POST['website_url'] ?? '', 255);
                if ($web !== '' && !preg_match('#^https?://#i', $web)) {
                    $web = 'https://' . $web;
                }
                $order    = (int)($_POST['display_order'] ?? 0);
                $active   = isset($_POST['is_active']) ? 1 : 0;
                $featured = isset($_POST['is_featured']) ? 1 : 0;
                $pinNew   = trim((string)($_POST['partner_pin'] ?? ''));
                $clearPin = isset($_POST['clear_pin']);

                if ($pname === '') {
                    throw new RuntimeException('साझेदार संस्थाको नाम अनिवार्य छ।');
                }

                $logo = trim((string)($_POST['existing_logo'] ?? ''));
                if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $up = uploadFile($_FILES['logo'], 'partners');
                    if (!empty($up['success'])) {
                        $logo = (string)$up['path'];
                    } elseif (!empty($up['message'])) {
                        throw new RuntimeException((string)$up['message']);
                    }
                }

                if ($act === 'add') {
                    $code = partnerGenerateCode($db);
                    $pinHash = $pinNew !== '' ? password_hash($pinNew, PASSWORD_DEFAULT) : null;
                    $db->prepare(
                        'INSERT INTO partner_facilities
                         (partner_name, partner_name_en, location, facility_type, discount_percent, discount_label,
                          description, description_en, terms_np, logo_path, contact_phone, contact_email, website_url,
                          partner_code, pin_hash, is_featured, display_order, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    )->execute([
                        $pname, $pnameEn, $location, $ftype, $discount, $dlabel,
                        $desc, $descEn, $terms, $logo !== '' ? $logo : null, $phone, $email, $web,
                        $code, $pinHash, $featured, $order, $active,
                    ]);
                    $success = 'साझेदार सुविधा थपियो। Desk code: ' . $code;
                } else {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id < 1) {
                        throw new RuntimeException('अवैध ID।');
                    }
                    $cur = partnerFindById($db, $id);
                    if (!$cur) {
                        throw new RuntimeException('साझेदार भेटिएन।');
                    }
                    $pinHash = $cur['pin_hash'] ?? null;
                    if ($clearPin) {
                        $pinHash = null;
                    } elseif ($pinNew !== '') {
                        $pinHash = password_hash($pinNew, PASSWORD_DEFAULT);
                    }
                    $code = trim((string)($cur['partner_code'] ?? ''));
                    if ($code === '') {
                        $code = partnerGenerateCode($db);
                    }
                    $db->prepare(
                        'UPDATE partner_facilities SET
                         partner_name=?, partner_name_en=?, location=?, facility_type=?, discount_percent=?, discount_label=?,
                         description=?, description_en=?, terms_np=?, logo_path=?, contact_phone=?, contact_email=?, website_url=?,
                         partner_code=?, pin_hash=?, is_featured=?, display_order=?, is_active=?
                         WHERE id=?'
                    )->execute([
                        $pname, $pnameEn, $location, $ftype, $discount, $dlabel,
                        $desc, $descEn, $terms, $logo !== '' ? $logo : null, $phone, $email, $web,
                        $code, $pinHash, $featured, $order, $active, $id,
                    ]);
                    $success = 'साझेदार सुविधा अपडेट भयो।';
                }
            } elseif ($act === 'deactivate') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $db->prepare('UPDATE partner_facilities SET is_active=0 WHERE id=?')->execute([$id]);
                    $success = 'साझेदार निष्क्रिय गरियो (अभिलेखमा सारियो)।';
                }
            } elseif ($act === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    if (partnerHasServiceLogs($db, $id)) {
                        $db->prepare('UPDATE partner_facilities SET is_active=0 WHERE id=?')->execute([$id]);
                        $success = 'सेवा लग भएकाले मेटाउन सकिएन — निष्क्रिय गरियो।';
                    } else {
                        $db->prepare('DELETE FROM partner_facilities WHERE id=?')->execute([$id]);
                        $success = 'साझेदार सुविधा मेटाइयो।';
                    }
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage() ?: 'त्रुटि भयो। कृपया पुनः प्रयास गर्नुहोस्।';
        }
    }
}

try {
    $facilities = $db->query('SELECT * FROM partner_facilities ORDER BY display_order ASC, id DESC LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $types = array_values(array_unique(array_filter(array_column($facilities, 'facility_type'))));
} catch (Throwable $e) {
    $facilities = [];
    $types = [];
}

$usageMap = [];
try {
    $uc = $db->query('SELECT partner_id, COUNT(*) AS c FROM member_partner_services WHERE partner_id IS NOT NULL GROUP BY partner_id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($uc as $row) {
        $usageMap[(int)$row['partner_id']] = (int)$row['c'];
    }
} catch (Throwable $e) {
}

$typeFilter = trim((string)($_GET['type'] ?? ''));
if ($typeFilter !== '' && !in_array($typeFilter, $types, true)) {
    $typeFilter = '';
}

$viewLogsId = (int)($_GET['logs'] ?? 0);
$viewLogsPartner = null;
$viewLogsRows = [];
$viewLogsMissing = false;
$viewLogsTotal = 0;
if ($viewLogsId > 0) {
    $viewLogsPartner = partnerFindById($db, $viewLogsId);
    if ($viewLogsPartner && function_exists('fetchPartnerFacilityServiceLogs')) {
        $viewLogsRows = fetchPartnerFacilityServiceLogs($db, $viewLogsId, 80, false);
        $viewLogsTotal = (int)($usageMap[$viewLogsId] ?? count($viewLogsRows));
    } else {
        $viewLogsMissing = true;
    }
}

$pfPart = adminPartitionRowsByIsActive($facilities);
$facilitiesLive = $pfPart['live'];
$facilitiesArch = $pfPart['archived'];

$renderPfRow = static function (array $f, int $sn, array $usageMap, string $csrfToken): void {
    $uid = (int)$f['id'];
    $usage = (int)($usageMap[$uid] ?? 0);
    $logo = partnerFacilityLogoUrl($f);
    ?>
    <tr data-type="<?php echo htmlspecialchars((string)$f['facility_type']); ?>">
        <td class="ps-3 text-muted fw-semibold"><?php echo $sn; ?></td>
        <td>
            <div class="d-flex align-items-center gap-2">
                <?php if ($logo !== ''): ?>
                    <img src="<?php echo htmlspecialchars($logo); ?>" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:8px;">
                <?php endif; ?>
                <div>
                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars((string)$f['partner_name']); ?></div>
                    <?php if (!empty($f['partner_code'])): ?>
                        <code class="small text-muted"><?php echo htmlspecialchars((string)$f['partner_code']); ?></code>
                    <?php endif; ?>
                    <?php if (!empty($f['is_featured'])): ?><span class="badge bg-warning text-dark ms-1">विशेष</span><?php endif; ?>
                </div>
            </div>
        </td>
        <td><span class="text-muted"><i class="fas fa-location-dot me-1 text-success pf-location-icon"></i><?php echo htmlspecialchars($f['location'] ?: '—'); ?></span></td>
        <td><?php if ($f['facility_type']): ?><span class="badge pf-type-badge"><?php echo htmlspecialchars((string)$f['facility_type']); ?></span><?php else: echo '—'; endif; ?></td>
        <td class="text-center">
            <?php $d = partnerDiscountDisplay($f); echo $d !== '' ? '<span class="badge bg-warning text-dark fw-bold">' . htmlspecialchars($d) . '</span>' : '<span class="text-muted">—</span>'; ?>
        </td>
        <td><span class="text-muted"><?php echo htmlspecialchars(mb_substr((string)($f['description'] ?? ''), 0, 50)); ?><?php echo mb_strlen((string)($f['description'] ?? '')) > 50 ? '…' : ''; ?></span></td>
        <td class="text-center">
            <a class="badge bg-info text-dark text-decoration-none" href="?logs=<?php echo $uid; ?>#pf-usage-logs" title="सेवा लग हेर्नुहोस्"><?php echo $usage; ?></a>
        </td>
        <td class="text-center">
            <span class="badge bg-<?php echo !empty($f['is_active']) ? 'success' : 'secondary'; ?>">
                <?php echo !empty($f['is_active']) ? 'सक्रिय' : 'निष्क्रिय'; ?>
            </span>
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-primary me-1 btn-edit-pf"
                    data-id="<?php echo $uid; ?>"
                    data-name="<?php echo htmlspecialchars((string)$f['partner_name'], ENT_QUOTES); ?>"
                    data-name-en="<?php echo htmlspecialchars((string)($f['partner_name_en'] ?? ''), ENT_QUOTES); ?>"
                    data-location="<?php echo htmlspecialchars((string)$f['location'], ENT_QUOTES); ?>"
                    data-type="<?php echo htmlspecialchars((string)$f['facility_type'], ENT_QUOTES); ?>"
                    data-discount="<?php echo htmlspecialchars((string)$f['discount_percent'], ENT_QUOTES); ?>"
                    data-dlabel="<?php echo htmlspecialchars((string)($f['discount_label'] ?? ''), ENT_QUOTES); ?>"
                    data-desc="<?php echo htmlspecialchars((string)($f['description'] ?? ''), ENT_QUOTES); ?>"
                    data-desc-en="<?php echo htmlspecialchars((string)($f['description_en'] ?? ''), ENT_QUOTES); ?>"
                    data-terms="<?php echo htmlspecialchars((string)($f['terms_np'] ?? ''), ENT_QUOTES); ?>"
                    data-phone="<?php echo htmlspecialchars((string)($f['contact_phone'] ?? ''), ENT_QUOTES); ?>"
                    data-email="<?php echo htmlspecialchars((string)($f['contact_email'] ?? ''), ENT_QUOTES); ?>"
                    data-web="<?php echo htmlspecialchars((string)($f['website_url'] ?? ''), ENT_QUOTES); ?>"
                    data-logo="<?php echo htmlspecialchars((string)($f['logo_path'] ?? ''), ENT_QUOTES); ?>"
                    data-order="<?php echo (int)$f['display_order']; ?>"
                    data-active="<?php echo !empty($f['is_active']) ? '1' : '0'; ?>"
                    data-featured="<?php echo !empty($f['is_featured']) ? '1' : '0'; ?>"
                    data-has-pin="<?php echo !empty($f['pin_hash']) ? '1' : '0'; ?>"
                    title="सम्पादन"><i class="fas fa-edit"></i></button>
            <?php if (!empty($f['is_active'])): ?>
            <form method="POST" class="svc-inline-form" onsubmit="return confirm('निष्क्रिय गर्ने?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="deactivate">
                <input type="hidden" name="id" value="<?php echo $uid; ?>">
                <button type="button" class="btn btn-sm btn-outline-secondary" title="निष्क्रिय"><i class="fas fa-archive"></i></button>
            </form>
            <?php endif; ?>
            <form method="POST" class="svc-inline-form" onsubmit="return confirm('<?php echo $usage > 0 ? 'लग भएकाले निष्क्रिय मात्र हुन्छ। जारी?' : 'मेट्ने निश्चित?'; ?>');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?php echo $uid; ?>">
                <button type="button" class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
            </form>
        </td>
    </tr>
    <?php
};

?>

<?php echo adminPageHeader(
    'साझेदार सुविधा व्यवस्थापन',
    'fa-handshake',
    'सदस्य छुट — public सूची + verify desk लग। Public: <a href="../partner-facilities.php" target="_blank" rel="noopener noreferrer">partner-facilities.php</a>',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($facilities) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="fas fa-check-circle me-1"></i>सक्रिय: ' . count($facilitiesLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25 me-2"><i class="fas fa-archive me-1"></i>अभिलेख: ' . count($facilitiesArch) . '</span>'
    . '<a class="btn btn-outline-success btn-sm" href="../verify.php" target="_blank" rel="noopener noreferrer"><i class="fas fa-id-card me-1"></i>Verify</a>'
    . ' <a class="btn btn-outline-secondary btn-sm" href="vendor-enlistment.php"><i class="fas fa-store me-1"></i>Vendor</a>'
); ?>

<?php echo adminAlert('success', $success) . adminAlert('danger', $error); ?>

<?php if ($viewLogsMissing): ?>
<div class="alert alert-warning d-flex align-items-center justify-content-between flex-wrap gap-2" id="pf-usage-logs">
    <span><i class="fas fa-triangle-exclamation me-2"></i>साझेदार भेटिएन वा लग लोड गर्न सकिएन (ID: <?php echo (int)$viewLogsId; ?>).</span>
    <a href="partner-facilities.php" class="btn btn-sm btn-outline-secondary">सूचीमा फर्कनुहोस्</a>
</div>
<?php endif; ?>

<?php if ($viewLogsPartner): ?>
<div class="card admin-table-card mb-3" id="pf-usage-logs">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2 bg-white">
        <div>
            <strong><i class="fas fa-clock-rotate-left me-2 text-success"></i>सेवा लग — <?php echo htmlspecialchars((string)$viewLogsPartner['partner_name']); ?></strong>
            <?php if (!empty($viewLogsPartner['partner_code'])): ?>
                <code class="ms-2 small"><?php echo htmlspecialchars((string)$viewLogsPartner['partner_code']); ?></code>
            <?php endif; ?>
            <span class="badge bg-info text-dark ms-2"><?php echo (int)$viewLogsTotal; ?> जम्मा</span>
            <?php if ($viewLogsTotal > count($viewLogsRows)): ?>
            <span class="badge bg-light text-muted border ms-1">पछिल्ला <?php echo count($viewLogsRows); ?> देखाइएको</span>
            <?php endif; ?>
        </div>
        <a href="partner-facilities.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-xmark me-1"></i>बन्द</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:420px;overflow:auto;">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th class="ps-3">मिति</th>
                        <th>सदस्य</th>
                        <th>कार्ड / सदस्यता</th>
                        <th>सेवा</th>
                        <th>नोट</th>
                        <th class="text-center">स्थिति</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($viewLogsRows)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">यो साझेदारमा अहिलेसम्म कुनै सेवा लग छैन।</td></tr>
                <?php else: foreach ($viewLogsRows as $lr):
                    $taken = !empty($lr['service_taken']);
                    $when = function_exists('formatNepaliDate') ? formatNepaliDate($lr['created_at'] ?? '', true) : (string)($lr['created_at'] ?? '');
                ?>
                    <tr>
                        <td class="ps-3 small text-muted text-nowrap"><?php echo htmlspecialchars($when); ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars((string)($lr['member_name'] ?: '—')); ?></td>
                        <td><code class="small"><?php echo htmlspecialchars((string)($lr['member_card_no'] ?: $lr['member_id'] ?: '—')); ?></code></td>
                        <td><?php echo htmlspecialchars((string)(($lr['service_name'] ?? '') !== '' ? $lr['service_name'] : '—')); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars((string)($lr['service_note'] ?: '—')); ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?php echo $taken ? 'success' : 'secondary'; ?>"><?php echo $taken ? 'सेवा लिइयो' : 'verify मात्र'; ?></span>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0" id="pfTabs">
    <li class="nav-item">
        <button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#pf-list" id="pf-list-btn" title="जम्मा">
            <i class="fas fa-list me-2"></i>सुविधा सूची
            <span class="badge bg-success ms-1"><?php echo count($facilities); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#pf-form" id="pf-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="pfFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="pf-list">
        <div class="card admin-table-card svc-flat-top-card">
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3 flex-wrap">
                <div class="input-group input-group-sm pf-search-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 pf-list-search" placeholder="संस्था, स्थान, code खोज्नुहोस्..." autocomplete="off">
                </div>
                <select class="form-select form-select-sm pf-type-filter" id="pfTypeFilter">
                    <option value="">— सुविधा प्रकार —</option>
                    <?php foreach ($types as $t): ?>
                    <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $typeFilter === $t ? 'selected' : ''; ?>><?php echo htmlspecialchars($t); ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
                <?php echo adminListSubtabPills('pf-sub', count($facilitiesLive), count($facilitiesArch)); ?>
                <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="pf-sub-live" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 pf-data-table coop-table">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3" width="50">क्र.स.</th>
                                        <th>साझेदार / Code</th>
                                        <th>स्थान</th>
                                        <th>प्रकार</th>
                                        <th width="100" class="text-center">छुट</th>
                                        <th>विवरण</th>
                                        <th width="70" class="text-center">लग</th>
                                        <th width="90" class="text-center">स्थिति</th>
                                        <th width="150" class="text-center">कार्य</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($facilitiesLive)): ?>
                                    <tr><td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-handshake fa-3x mb-2 d-block opacity-25"></i>
                                        सक्रिय साझेदार छैन।
                                        <button type="button" class="btn btn-sm btn-success mt-2" onclick="document.getElementById('pf-form-btn').click()"><i class="fas fa-plus me-1"></i>थप्नुहोस्</button>
                                    </td></tr>
                                <?php else:
                                    $sn = 1;
                                    foreach ($facilitiesLive as $f) {
                                        $renderPfRow($f, $sn++, $usageMap, $csrfToken);
                                    }
                                endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="pf-sub-arch" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 pf-data-table coop-table">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3" width="50">क्र.स.</th>
                                        <th>साझेदार / Code</th>
                                        <th>स्थान</th>
                                        <th>प्रकार</th>
                                        <th width="100" class="text-center">छुट</th>
                                        <th>विवरण</th>
                                        <th width="70" class="text-center">लग</th>
                                        <th width="90" class="text-center">स्थिति</th>
                                        <th width="150" class="text-center">कार्य</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($facilitiesArch)): ?>
                                    <tr><td colspan="9" class="text-center py-5 text-muted">अभिलेख खाली छ।</td></tr>
                                <?php else:
                                    $sn = 1;
                                    foreach ($facilitiesArch as $f) {
                                        $renderPfRow($f, $sn++, $usageMap, $csrfToken);
                                    }
                                endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pf-form">
        <div class="card svc-flat-top-card">
            <div class="card-header d-flex justify-content-between align-items-center svc-form-header-grad">
                <h5 class="mb-0 fw-bold" id="pfFormTitle"><i class="fas fa-plus-circle me-2"></i>नयाँ साझेदार सुविधा थप्नुहोस्</h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelPf"><i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्</button>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="pfForm" class="needs-validation" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" id="pff_action" value="add">
                    <input type="hidden" name="id" id="pff_id" value="">
                    <input type="hidden" name="existing_logo" id="pff_existing_logo" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="pff_name" class="form-label fw-semibold text-success">नाम (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="partner_name" id="pff_name" class="form-control admin-fancy-input" required placeholder="जस्तै: ABC Hospital">
                        </div>
                        <div class="col-md-6">
                            <label for="pff_name_en" class="form-label fw-semibold text-success">Name (English)</label>
                            <input type="text" name="partner_name_en" id="pff_name_en" class="form-control admin-fancy-input" placeholder="Optional English name">
                        </div>
                        <div class="col-md-6">
                            <label for="pff_location" class="form-label fw-semibold text-success">स्थान</label>
                            <input type="text" name="location" id="pff_location" class="form-control admin-fancy-input" placeholder="काठमाडौं, पोखरा…">
                        </div>
                        <div class="col-md-6">
                            <label for="pff_type" class="form-label fw-semibold text-success">सुविधा प्रकार</label>
                            <input type="text" name="facility_type" id="pff_type" class="form-control admin-fancy-input" list="pfTypeList" placeholder="स्वास्थ्य, शिक्षा…">
                            <datalist id="pfTypeList">
                                <option value="स्वास्थ्य सेवा"><option value="शिक्षा"><option value="किराना तथा खाद्यान्न">
                                <option value="पोशाक"><option value="यातायात"><option value="होटल तथा खाजा">
                                <option value="फोटो तथा प्रिन्टिङ"><option value="कृषि सामग्री"><option value="इलेक्ट्रोनिक्स"><option value="अन्य">
                                <?php foreach ($types as $t): ?><option value="<?php echo htmlspecialchars($t); ?>"><?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-3">
                            <label for="pff_discount" class="form-label fw-semibold text-success">छुट (%)</label>
                            <div class="input-group">
                                <input type="number" name="discount_percent" id="pff_discount" class="form-control admin-fancy-input" min="0" max="100" step="0.5" value="0">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label for="pff_dlabel" class="form-label fw-semibold text-success">छुट लेबल <small class="text-muted">(override)</small></label>
                            <input type="text" name="discount_label" id="pff_dlabel" class="form-control admin-fancy-input" placeholder="जस्तै: १०% ल्याब + ५% OPD">
                        </div>
                        <div class="col-md-4">
                            <label for="pff_order" class="form-label fw-semibold text-success">प्रदर्शन क्रम</label>
                            <input type="number" name="display_order" id="pff_order" class="form-control admin-fancy-input" min="0" value="0">
                        </div>
                        <div class="col-md-4">
                            <label for="pff_phone" class="form-label fw-semibold text-success">फोन</label>
                            <input type="text" name="contact_phone" id="pff_phone" class="form-control admin-fancy-input" placeholder="98XXXXXXXX">
                        </div>
                        <div class="col-md-4">
                            <label for="pff_email" class="form-label fw-semibold text-success">इमेल</label>
                            <input type="email" name="contact_email" id="pff_email" class="form-control admin-fancy-input" placeholder="info@example.com">
                        </div>
                        <div class="col-md-4">
                            <label for="pff_web" class="form-label fw-semibold text-success">वेबसाइट</label>
                            <input type="text" name="website_url" id="pff_web" class="form-control admin-fancy-input" placeholder="https://…">
                        </div>
                        <div class="col-md-6">
                            <label for="pff_logo" class="form-label fw-semibold text-success">लोगो</label>
                            <input type="file" name="logo" id="pff_logo" class="form-control" accept="image/*">
                            <div id="pff_logo_preview" class="small text-muted mt-1"></div>
                        </div>
                        <div class="col-md-6">
                            <label for="pff_pin" class="form-label fw-semibold text-success">Desk PIN <small class="text-muted">(ऐच्छिक — verify लग)</small></label>
                            <input type="password" name="partner_pin" id="pff_pin" class="form-control admin-fancy-input" placeholder="नयाँ PIN (खाली = नचलाउने)" autocomplete="new-password">
                            <div class="form-check mt-2" id="pff_clear_pin_wrap" style="display:none;">
                                <input class="form-check-input" type="checkbox" name="clear_pin" id="pff_clear_pin">
                                <label class="form-check-label" for="pff_clear_pin">अवस्थित PIN हटाउनुहोस्</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="pff_desc" class="form-label fw-semibold text-success">विवरण (नेपाली)</label>
                            <textarea name="description" id="pff_desc" class="form-control admin-fancy-input" rows="3" placeholder="सदस्यले के पाउँछन्…"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="pff_desc_en" class="form-label fw-semibold text-success">Description (English)</label>
                            <textarea name="description_en" id="pff_desc_en" class="form-control admin-fancy-input" rows="3"></textarea>
                        </div>
                        <div class="col-12">
                            <label for="pff_terms" class="form-label fw-semibold text-success">शर्त / Terms</label>
                            <textarea name="terms_np" id="pff_terms" class="form-control admin-fancy-input" rows="2" placeholder="कार्ड अनिवार्य, केही सेवामा लागू नहुने…"></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="pff_active" checked>
                                <label class="form-check-label fw-semibold" for="pff_active">सक्रिय (Public मा देखिने)</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_featured" id="pff_featured">
                                <label class="form-check-label fw-semibold" for="pff_featured">विशेष / Featured (माथि देखाउने)</label>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info small mt-3 mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        प्रत्येक साझेदारलाई <strong>Desk code</strong> (PF-XXXXXX) दिइन्छ — verify.php मा सेवा लग गर्दा छान्न सजिलो। PIN सेट गरे सेवा लग गर्दा PIN चाहिन्छ।
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-success px-4"><i class="fas fa-save me-2"></i><span id="pfSubmitLabel">सुविधा सेभ गर्नुहोस्</span></button>
                        <button type="button" class="btn btn-outline-secondary" id="btnResetPf"><i class="fas fa-rotate-left me-1"></i>Reset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function pfActiveRows() {
    var pane = document.querySelector('#pf-list .admin-table-subtab-content .tab-pane.active');
    if (!pane) return [];
    return Array.from(pane.querySelectorAll('tbody tr[data-type]'));
}
var searchInp = document.querySelector('#pf-list .pf-list-search');
var typeSelEl = document.getElementById('pfTypeFilter');
var cntEl = document.querySelector('#pf-list .search-count');
function pfFilter() {
    var q = (searchInp && searchInp.value || '').toLowerCase();
    var typ = typeSelEl && typeSelEl.value || '';
    var vis = 0, total = 0;
    pfActiveRows().forEach(function (r) {
        total++;
        var show = (r.textContent || '').toLowerCase().indexOf(q) !== -1 && (!typ || (r.dataset.type || '') === typ);
        r.style.display = show ? '' : 'none';
        if (show) vis++;
    });
    if (cntEl) {
        if (!q && !typ) { cntEl.textContent = ''; cntEl.style.display = 'none'; }
        else { cntEl.textContent = String(vis); cntEl.style.display = ''; cntEl.title = vis + ' / ' + total; }
    }
}
if (searchInp) searchInp.addEventListener('input', pfFilter);
if (typeSelEl) typeSelEl.addEventListener('change', pfFilter);
document.addEventListener('shown.bs.tab', function (e) {
    var t = e.target && e.target.getAttribute('data-bs-target');
    if (t === '#pf-sub-live' || t === '#pf-sub-arch') pfFilter();
});
pfFilter();

document.querySelectorAll('.btn-edit-pf').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('pff_action').value = 'edit';
        document.getElementById('pff_id').value = this.dataset.id || '';
        document.getElementById('pff_name').value = this.dataset.name || '';
        document.getElementById('pff_name_en').value = this.dataset.nameEn || '';
        document.getElementById('pff_location').value = this.dataset.location || '';
        document.getElementById('pff_type').value = this.dataset.type || '';
        document.getElementById('pff_discount').value = this.dataset.discount || '0';
        document.getElementById('pff_dlabel').value = this.dataset.dlabel || '';
        document.getElementById('pff_desc').value = this.dataset.desc || '';
        document.getElementById('pff_desc_en').value = this.dataset.descEn || '';
        document.getElementById('pff_terms').value = this.dataset.terms || '';
        document.getElementById('pff_phone').value = this.dataset.phone || '';
        document.getElementById('pff_email').value = this.dataset.email || '';
        document.getElementById('pff_web').value = this.dataset.web || '';
        document.getElementById('pff_existing_logo').value = this.dataset.logo || '';
        document.getElementById('pff_order').value = this.dataset.order || '0';
        document.getElementById('pff_active').checked = this.dataset.active === '1';
        document.getElementById('pff_featured').checked = this.dataset.featured === '1';
        document.getElementById('pff_pin').value = '';
        var wrap = document.getElementById('pff_clear_pin_wrap');
        if (wrap) wrap.style.display = this.dataset.hasPin === '1' ? '' : 'none';
        document.getElementById('pff_clear_pin').checked = false;
        var prev = document.getElementById('pff_logo_preview');
        if (prev) prev.textContent = this.dataset.logo ? ('अवस्थित: ' + this.dataset.logo) : '';
        document.getElementById('pfFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>साझेदार सुविधा सम्पादन';
        document.getElementById('pfFormTabLabel').textContent = 'सम्पादन';
        document.getElementById('pfSubmitLabel').textContent = 'अपडेट गर्नुहोस्';
        document.getElementById('pf-form-btn').click();
    });
});

document.getElementById('btnCancelPf')?.addEventListener('click', function () {
    document.getElementById('pf-list-btn').click();
});
document.getElementById('btnResetPf')?.addEventListener('click', function () {
    document.getElementById('pff_action').value = 'add';
    document.getElementById('pff_id').value = '';
    document.getElementById('pff_existing_logo').value = '';
    document.getElementById('pfForm').reset();
    document.getElementById('pff_active').checked = true;
    document.getElementById('pff_clear_pin_wrap').style.display = 'none';
    document.getElementById('pff_logo_preview').textContent = '';
    document.getElementById('pfFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ साझेदार सुविधा थप्नुहोस्';
    document.getElementById('pfFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
    document.getElementById('pfSubmitLabel').textContent = 'सुविधा सेभ गर्नुहोस्';
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
