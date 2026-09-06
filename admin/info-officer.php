<?php
/**
 * =====================================================
 * सूचना अधिकारी व्यवस्थापन
 * Information Officer Management Page
 *
 * यहाँबाट सूचना अधिकारी तोक्न सकिन्छ।
 * टिम सदस्यहरूमध्ये एक जनालाई सूचना अधिकारी बनाउन सकिन्छ।
 * =====================================================
 */
$pageTitle = 'सूचना अधिकारी';
$currentPage = 'info-officer';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/simple-cache.php';

$db      = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

$success = '';
$error   = '';

/* =====================================================
   POST: सूचना अधिकारी बदल्ने
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_officer'])) {
    $memberId = (int)($_POST['member_id'] ?? 0);
    if ($memberId > 0) {
        try {
            /* पहिले सबैको is_information_officer = 0 गर्ने */
            $db->exec("UPDATE team_members SET is_information_officer = 0");
            /* अनि चयन गरिएकोलाई 1 गर्ने */
            $db->prepare("UPDATE team_members SET is_information_officer = 1 WHERE id = ?")
               ->execute([$memberId]);
            $success = 'सूचना अधिकारी सफलतापूर्वक अपडेट भयो।';
            logSecurityEvent('info_officer_update', 'Info Officer set to member ID: ' . $memberId);
            if (function_exists('clearHomepageCache')) clearHomepageCache();
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    } else {
        /* member_id = 0 भए अर्थात् "कोई होइन" — सबै 0 गर्ने */
        $db->exec("UPDATE team_members SET is_information_officer = 0");
        $success = 'सूचना अधिकारी हटाइयो।';
        if (function_exists('clearHomepageCache')) clearHomepageCache();
    }
}

/* हालको सूचना अधिकारी */
$currentOfficer = null;
try {
    $row = $db->query("SELECT * FROM team_members WHERE is_information_officer = 1 LIMIT 1")->fetch();
    if ($row) $currentOfficer = $row;
} catch (Exception $e) {}

/* सबै टिम सदस्यहरू */
$allMembers = [];
try {
    $allMembers = $db->query("SELECT id, name, name_en, position, position_np, position_en, photo, category, phone, email FROM team_members ORDER BY display_order, name LIMIT 500")->fetchAll();
} catch (Exception $e) {}
$panel = (string)($_GET['panel'] ?? 'list');
if (!in_array($panel, ['list', 'form'], true)) {
    $panel = 'list';
}
?>

<?php
echo adminPageHeader(
    'सूचना अधिकारी', 'fa-user-shield',
    'RTI अन्तर्गत सूचना दिने अधिकारी',
    '<a href="team.php" class="btn btn-outline-light btn-sm"><i class="fas fa-users me-1"></i>टिम व्यवस्थापन</a>'
);
if ($success) echo adminAlert('success', $success);
if ($error) echo adminAlert('error', $error);
?>
<div class="container-fluid py-4">

    <ul class="nav admin-nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $panel === 'list' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#info-officer-list-tab" type="button" role="tab">
                <i class="fas fa-list me-1"></i> सूची
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $panel === 'form' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#info-officer-form-tab" type="button" role="tab">
                <i class="fas fa-plus me-1"></i> नयाँ थप्नुहोस्
            </button>
        </li>
    </ul>

    <div class="tab-content info-officer-page">
        <div class="tab-pane fade <?php echo $panel === 'list' ? 'show active' : ''; ?>" id="info-officer-list-tab" role="tabpanel">
            <div class="row g-4">
                <!-- हालको सूचना अधिकारी -->
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-id-badge me-2"></i>हालको सूचना अधिकारी</h5>
                </div>
                <div class="card-body text-center py-4">
                    <?php if ($currentOfficer): ?>
                        <?php if (!empty($currentOfficer['photo'])): ?>
                            <img src="<?php echo htmlspecialchars('../' . $currentOfficer['photo']); ?>"
                                 class="rounded-circle mb-3 border border-3"
                                 style="width:100px;height:100px;object-fit:cover;border-color:var(--secondary-color,#c0392b)!important;"
                                 alt="Photo">
                        <?php else: ?>
                            <div class="rounded-circle mb-3 d-inline-flex align-items-center justify-content-center border border-3"
                                 style="width:100px;height:100px;background:#fef2f2;border-color:var(--secondary-color,#c0392b)!important;">
                                <i class="fas fa-user-shield fa-2x" style="color:var(--secondary-color,#c0392b);"></i>
                            </div>
                        <?php endif; ?>
                        <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($currentOfficer['name']); ?></h5>
                        <?php if (!empty($currentOfficer['name_en'])): ?>
                            <p class="text-muted small mb-2"><?php echo htmlspecialchars($currentOfficer['name_en']); ?></p>
                        <?php endif; ?>
                        <p class="fw-semibold mb-2 current-officer-position">
                            <?php echo htmlspecialchars($currentOfficer['position_np'] ?: $currentOfficer['position']); ?>
                        </p>
                        <?php if (!empty($currentOfficer['phone'])): ?>
                            <p class="text-muted small mb-1"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($currentOfficer['phone']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($currentOfficer['email'])): ?>
                            <p class="text-muted small mb-0"><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($currentOfficer['email']); ?></p>
                        <?php endif; ?>
                        <span class="badge mt-3 bg-primary">
                            <i class="fas fa-check-circle me-1"></i>सूचना अधिकारी
                        </span>
                    <?php else: ?>
                        <div class="text-muted py-4">
                            <i class="fas fa-user-slash fa-3x mb-3 d-block" style="opacity:.3;"></i>
                            <p>कुनै सूचना अधिकारी तोकिएको छैन।</p>
                            <p class="small">तलबाट टिम सदस्य चयन गर्नुहोस्।</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-circle-info me-2"></i>सूचनाको हकसम्बन्धी जानकारी (RTI)</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-0">
                                सूचनाको हक ऐन, २०६४ अन्तर्गत हरेक सार्वजनिक निकायले सूचना अधिकारी तोक्नु पर्छ।
                                यहाँ तोकिएको व्यक्ति <strong>सूचना अधिकारी</strong> को रूपमा website मा देखाइनेछ र
                                सूचना माग गर्दा सम्पर्क गर्न मिल्नेछ।
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?php echo $panel === 'form' ? 'show active' : ''; ?>" id="info-officer-form-tab" role="tabpanel">
            <!-- टिम सदस्य चयन गर्नुहोस् -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0"><i class="fas fa-users me-2 text-primary"></i>सूचना अधिकारी चयन गर्नुहोस्</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 admin-assign-table">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" class="admin-assign-status-col" aria-label="स्थिति"></th>
                                    <th scope="col">नाम</th>
                                    <th scope="col">पद</th>
                                    <th scope="col">फोन</th>
                                    <th scope="col" class="text-end">कार्य</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allMembers as $m): ?>
                                <?php $isCurrent = ($currentOfficer && (int)$currentOfficer['id'] === (int)$m['id']); ?>
                                <tr class="<?php echo $isCurrent ? 'admin-assign-row-current' : ''; ?>">
                                    <td class="text-center">
                                        <?php if ($isCurrent): ?>
                                            <i class="fas fa-check-circle text-success" title="हालको अधिकारी" aria-label="हालको अधिकारी"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="admin-person-cell">
                                            <?php if (!empty($m['photo'])): ?>
                                                <img src="<?php echo htmlspecialchars('../' . $m['photo']); ?>"
                                                     class="gof-row-avatar" alt="">
                                            <?php else: ?>
                                                <span class="admin-person-fallback" aria-hidden="true"><i class="fas fa-user"></i></span>
                                            <?php endif; ?>
                                            <div class="admin-person-meta">
                                                <span class="admin-person-name"><?php echo htmlspecialchars($m['name']); ?></span>
                                                <?php if (!empty($m['name_en'])): ?>
                                                    <span class="admin-person-sub"><?php echo htmlspecialchars($m['name_en']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($m['position_np'] ?: $m['position']); ?></td>
                                    <td class="admin-mono-soft"><?php echo htmlspecialchars($m['phone'] ?? '—'); ?></td>
                                    <td class="text-end">
                                        <?php if (!$isCurrent): ?>
                                        <form method="POST" class="svc-inline-form">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                                            <button type="submit" name="set_officer" value="1"
                                                    class="btn btn-sm btn-primary admin-assign-btn"
                                                    onclick="return confirm('<?php echo htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8'); ?> लाई सूचना अधिकारी बनाउने?')">
                                                <i class="fas fa-user-check" aria-hidden="true"></i><span>तोक्नुहोस्</span>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                            <span class="badge bg-success">हालको अधिकारी</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($allMembers)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="fas fa-users fa-2x mb-2 d-block"></i>
                                        टिम सदस्यहरू भेटिएनन्। पहिले <a href="team.php">टिम व्यवस्थापन</a> मा सदस्य थप्नुहोस्।
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if ($currentOfficer): ?>
                <div class="card-footer bg-white border-top">
                    <form method="POST" onsubmit="return confirm('सूचना अधिकारी हटाउने?')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="member_id" value="0">
                        <button type="submit" name="set_officer" value="1" class="btn btn-sm btn-outline-danger">
                            <i class="fas fa-user-slash me-1"></i>सूचना अधिकारी हटाउनुहोस्
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div>

</div>

<?php require_once 'includes/admin-footer.php'; ?>
