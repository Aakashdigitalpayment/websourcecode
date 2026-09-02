<?php
$pageTitle = 'कार्यक्रम सेटिङ';
$currentPage = 'program-settings';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/program-tables.php';

$db = getDB();
ensureProgramTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $defaultInstant = !empty($_POST['default_instant_attendance']) ? '1' : '0';
    $defaultSharedQr = !empty($_POST['default_shared_qr']) ? '1' : '0';
    updateSetting('program_default_instant_attendance', $defaultInstant);
    updateSetting('program_default_shared_qr', $defaultSharedQr);
    updateSetting('program_default_eligible_scope', trim((string)($_POST['eligible_scope'] ?? 'all_active')));
    setFlash('success', 'सेटिङ सुरक्षित भयो।');
    redirect('program-settings.php');
}

$defaultInstant = getSetting('program_default_instant_attendance', '0') === '1';
$defaultSharedQr = getSetting('program_default_shared_qr', '1') === '1';
$eligibleScope = getSetting('program_default_eligible_scope', 'all_active');
?>
<div class="container-fluid py-3">
  <?php echo adminPageHeader('कार्यक्रम सेटिङ', 'fa-cog', 'Default values for new programs. AGM quorum/voter eligibility = Phase 5 extension.'); ?>
  <?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>
  <div class="card admin-table-card"><div class="card-body">
    <form method="POST" class="row g-3">
      <?php echo csrfField(); ?>
      <div class="col-12"><label class="form-check-label"><input type="checkbox" class="form-check-input me-1" name="default_instant_attendance" value="1" <?php echo $defaultInstant ? 'checked' : ''; ?>>Default: Instant QR attendance (approve बिना)</label></div>
      <div class="col-12"><label class="form-check-label"><input type="checkbox" class="form-check-input me-1" name="default_shared_qr" value="1" <?php echo $defaultSharedQr ? 'checked' : ''; ?>>Default: Shared parent QR for multi-location</label></div>
      <div class="col-md-6">
        <label class="form-label">Eligible member scope (AGM extension hook)</label>
        <select name="eligible_scope" class="form-select">
          <option value="all_active" <?php echo $eligibleScope==='all_active'?'selected':''; ?>>All active members</option>
          <option value="shareholders" <?php echo $eligibleScope==='shareholders'?'selected':''; ?>>Shareholders only (future)</option>
          <option value="voters" <?php echo $eligibleScope==='voters'?'selected':''; ?>>Eligible voters only (future AGM)</option>
        </select>
        <div class="form-text">Phase 5: quorum, proxy, voting eligibility यही scope बाट extend हुनेछ।</div>
      </div>
      <div class="col-12"><button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>सेभ</button></div>
    </form>
  </div></div>
  <div class="alert alert-info mt-3"><strong>Program Types:</strong> General, AGM, SGM, Orientation, Training, Seminar, Workshop, Financial Literacy, Other — programs.php मा छान्न सकिन्छ।</div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
