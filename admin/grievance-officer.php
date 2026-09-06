<?php
/**
 * =====================================================
 * गुनासो अधिकारी व्यवस्थापन
 * Grievance Officer Management Page
 *
 * यहाँबाट गुनासो अधिकारी तोक्न सकिन्छ।
 * टिम सदस्यहरूमध्ये एक जनालाई गुनासो अधिकारी बनाउन सकिन्छ।
 * =====================================================
 */
$pageTitle = 'गुनासो अधिकारी';
$currentPage = 'grievance-officer';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/simple-cache.php';

$db      = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

$success = '';
$error   = '';

/* =====================================================
   POST: गुनासो अधिकारी बदल्ने
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_officer'])) {
    $memberId = (int)($_POST['member_id'] ?? 0);
    if ($memberId > 0) {
        try {
            /* पहिले सबैको is_grievance_officer = 0 गर्ने */
            $db->exec("UPDATE team_members SET is_grievance_officer = 0");
            /* अनि चयन गरिएकोलाई 1 गर्ने */
            $db->prepare("UPDATE team_members SET is_grievance_officer = 1 WHERE id = ?")
               ->execute([$memberId]);
            $success = 'गुनासो अधिकारी सफलतापूर्वक अपडेट भयो।';
            logSecurityEvent('grievance_officer_update', 'Grievance Officer set to member ID: ' . $memberId);
            if (function_exists('clearHomepageCache')) clearHomepageCache();
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    } else {
        $db->exec("UPDATE team_members SET is_grievance_officer = 0");
        $success = 'गुनासो अधिकारी हटाइयो।';
        if (function_exists('clearHomepageCache')) clearHomepageCache();
    }
}

/* हालको गुनासो अधिकारी */
$currentOfficer = null;
try {
    $row = $db->query("SELECT * FROM team_members WHERE is_grievance_officer = 1 LIMIT 1")->fetch();
    if ($row) $currentOfficer = $row;
} catch (Exception $e) {}

/* सबै टिम सदस्यहरू */
$allMembers = [];
try {
    $allMembers = $db->query("SELECT id, name, name_en, position, position_np, position_en, photo, category, phone, email FROM team_members ORDER BY display_order, name LIMIT 500")->fetchAll();
} catch (Exception $e) {}
?>

<?php
echo adminPageHeader(
    'गुनासो अधिकारी', 'fa-user-tie',
    'उजुरी तथा गुनासो सुन्ने अधिकारी',
    '<a href="team.php" class="btn btn-outline-light btn-sm"><i class="fas fa-users me-1"></i>टिम व्यवस्थापन</a>'
);
if ($success) echo adminAlert('success', $success);
if ($error) echo adminAlert('error', $error);
?>
<div class="container-fluid py-4">

    <div class="row g-4">

        <!-- हालको गुनासो अधिकारी -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header gof-card-header">
                    <h5 class="mb-0"><i class="fas fa-id-badge me-2"></i>हालको गुनासो अधिकारी</h5>
                </div>
                <div class="card-body text-center py-4">
                    <?php if ($currentOfficer): ?>
                        <?php if (!empty($currentOfficer['photo'])): ?>
                            <img src="<?php echo htmlspecialchars('../' . $currentOfficer['photo']); ?>"
                                 class="rounded-circle mb-3 border border-3 gof-avatar-photo"
                                 alt="Photo">
                        <?php else: ?>
                            <div class="rounded-circle mb-3 d-inline-flex align-items-center justify-content-center border border-3 gof-avatar-fallback">
                                <i class="fas fa-user-tie fa-2x gof-accent-icon"></i>
                            </div>
                        <?php endif; ?>
                        <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($currentOfficer['name']); ?></h5>
                        <?php if (!empty($currentOfficer['name_en'])): ?>
                            <p class="text-muted small mb-2"><?php echo htmlspecialchars($currentOfficer['name_en']); ?></p>
                        <?php endif; ?>
                        <p class="fw-semibold mb-2 gof-position-accent">
                            <?php echo htmlspecialchars($currentOfficer['position_np'] ?: $currentOfficer['position']); ?>
                        </p>
                        <?php if (!empty($currentOfficer['phone'])): ?>
                            <p class="text-muted small mb-1"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($currentOfficer['phone']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($currentOfficer['email'])): ?>
                            <p class="text-muted small mb-0"><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($currentOfficer['email']); ?></p>
                        <?php endif; ?>
                        <span class="badge mt-3 gof-badge-violet">
                            <i class="fas fa-check-circle me-1"></i>गुनासो अधिकारी
                        </span>
                    <?php else: ?>
                        <div class="text-muted py-4">
                            <i class="fas fa-user-slash fa-3x mb-3 d-block gof-empty-state-icon"></i>
                            <p>कुनै गुनासो अधिकारी तोकिएको छैन।</p>
                            <p class="small">तलबाट टिम सदस्य चयन गर्नुहोस्।</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- टिम सदस्य चयन -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom">
                    <h5 class="mb-0"><i class="fas fa-users me-2 text-primary"></i>गुनासो अधिकारी चयन गर्नुहोस्</h5>
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
                                                    class="btn btn-sm gof-btn-violet admin-assign-btn"
                                                    onclick="return confirm('<?php echo htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8'); ?> लाई गुनासो अधिकारी बनाउने?')">
                                                <i class="fas fa-user-check" aria-hidden="true"></i><span>तोक्नुहोस्</span>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                            <span class="badge gof-badge-violet">हालको अधिकारी</span>
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
                    <form method="POST" onsubmit="return confirm('गुनासो अधिकारी हटाउने?')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="member_id" value="0">
                        <button type="submit" name="set_officer" value="1" class="btn btn-sm btn-outline-danger">
                            <i class="fas fa-user-slash me-1"></i>गुनासो अधिकारी हटाउनुहोस्
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- जानकारी बक्स -->
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-body">
            <h6 class="fw-bold mb-2"><i class="fas fa-info-circle text-warning me-2"></i>गुनासो अधिकारीबारे</h6>
            <p class="text-muted small mb-0">
                गुनासो सुन्ने ऐन अन्तर्गत हरेक निकायले गुनासो अधिकारी तोक्नु पर्छ।
                यहाँ तोकिएको व्यक्ति <strong>गुनासो अधिकारी</strong> को रूपमा website मा देखाइनेछ।
                सदस्यहरूले गुनासो दर्ता गर्दा यही अधिकारीकहाँ पुग्नेछ।
            </p>
        </div>
    </div>

</div>

<?php require_once 'includes/admin-footer.php'; ?>
