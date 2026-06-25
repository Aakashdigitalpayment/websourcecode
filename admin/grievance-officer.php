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
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    } else {
        $db->exec("UPDATE team_members SET is_grievance_officer = 0");
        $success = 'गुनासो अधिकारी हटाइयो।';
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
    $allMembers = $db->query("SELECT id, name, name_en, position, position_np, position_en, photo, category, phone, email FROM team_members ORDER BY display_order, name")->fetchAll();
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
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th></th>
                                    <th>नाम</th>
                                    <th>पद</th>
                                    <th>फोन</th>
                                    <th>कार्य</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allMembers as $m): ?>
                                <tr class="<?php echo ($currentOfficer && $currentOfficer['id'] == $m['id']) ? 'table-warning' : ''; ?>">
                                    <td>
                                        <?php if ($currentOfficer && $currentOfficer['id'] == $m['id']): ?>
                                            <i class="fas fa-check-circle text-success"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($m['photo'])): ?>
                                            <img src="<?php echo htmlspecialchars('../' . $m['photo']); ?>"
                                                 class="gof-row-avatar" alt="">
                                        <?php endif; ?>
                                        <strong><?php echo htmlspecialchars($m['name']); ?></strong>
                                        <?php if (!empty($m['name_en'])): ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($m['name_en']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($m['position_np'] ?: $m['position']); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($m['phone'] ?? '—'); ?></td>
                                    <td>
                                        <?php if (!$currentOfficer || $currentOfficer['id'] != $m['id']): ?>
                                        <form method="POST" class="svc-inline-form">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="member_id" value="<?php echo $m['id']; ?>">
                                            <button type="submit" name="set_officer" value="1"
                                                    class="btn btn-sm gof-btn-violet"
                                                    onclick="return confirm('<?php echo htmlspecialchars($m['name']); ?> लाई गुनासो अधिकारी बनाउने?')">
                                                <i class="fas fa-user-check me-1"></i>तोक्नुहोस्
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
