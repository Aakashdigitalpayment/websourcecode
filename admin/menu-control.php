<?php
/**
 * Superadmin only — hide/show admin sidebar menu groups for this cooperative deploy.
 * Default: nothing hidden. Save requires confirm code (not SA role alone).
 */
define('IS_ADMIN_PAGE', true);
$pageTitle = 'Menu Control';
$currentPage = 'menu-control';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/admin-menu-control.php';

if (!isAdminLoggedIn()) {
    redirect(ADMIN_URL . 'index.php');
}
if (empty($_SESSION['is_superadmin'])) {
    setFlash('error', 'यो पृष्ठ केवल Superadmin ले प्रयोग गर्न सक्छ।');
    redirect(ADMIN_URL . 'dashboard.php');
}

$catalog = admin_menu_control_catalog();
$hidden = admin_menu_control_load_hidden();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_menu_control') {
            $code = (string)($_POST['confirm_code'] ?? '');
            if (!admin_menu_control_verify_confirm_code($code)) {
                error_log('[menu-control] confirm code failed for admin_id=' . (int)($_SESSION['admin_id'] ?? 0));
                throw new Exception('Confirm code गलत छ। Menu परिवर्तन सुरक्षित भएन।');
            }
            $posted = $_POST['hidden_groups'] ?? [];
            if (!is_array($posted)) {
                $posted = [];
            }
            /* Checkbox = hide: checked groups are hidden */
            $toHide = [];
            foreach ($posted as $g) {
                $g = trim((string)$g);
                if (isset($catalog[$g])) {
                    $toHide[] = $g;
                }
            }
            admin_menu_control_save_hidden($toHide);
            if (function_exists('writeAuditLog')) {
                writeAuditLog(
                    'menu_control_update',
                    'hidden_groups=' . (count($toHide) ? implode(',', $toHide) : '(none)'),
                    'settings',
                    0
                );
            }
            setFlash('success', 'Menu control अपडेट भयो। लुकेका समूह: ' . (count($toHide) ? implode(', ', $toHide) : 'कुनै छैन (सबै देखिने)'));
        } elseif ($action === 'reset_menu_control') {
            $code = (string)($_POST['confirm_code'] ?? '');
            if (!admin_menu_control_verify_confirm_code($code)) {
                throw new Exception('Confirm code गलत छ। Reset भएन।');
            }
            admin_menu_control_save_hidden([]);
            if (function_exists('writeAuditLog')) {
                writeAuditLog('menu_control_reset', 'all menus visible again', 'settings', 0);
            }
            setFlash('success', 'सबै menu फेरि देखिने बनाइयो (default)।');
        }
    } catch (Throwable $e) {
        error_log('[menu-control] ' . $e->getMessage());
        setFlash('error', 'मेनु अद्यावधिक गर्न सकिएन।');
    }
    redirect('menu-control.php');
}

require_once __DIR__ . '/includes/admin-header.php';

$adminIsEn = !empty($adminIsEnglish);
$t = static function (string $np, string $en) use ($adminIsEn): string {
    return $adminIsEn ? $en : $np;
};
$hidden = admin_menu_control_load_hidden();
?>
<div class="container-fluid py-3">
<?php
echo adminPageHeader(
    $t('Menu Control', 'Menu Control'),
    'layout-list',
    $t(
        'यो सहकारीको Admin sidebar मा कुन समूह देख्ने / नदेख्ने — केवल Superadmin। Default: सबै देखिने। SA tools (सुपरएडमिन समूह) सधैं SA लाई देखिन्छ।',
        'Choose which admin sidebar groups this cooperative sees. Superadmin only. Default: all visible. SA tools group always stays for Superadmin.'
    ),
    '<a href="dashboard.php" class="btn btn-sm btn-outline-secondary">Dashboard</a>'
);
?>
<?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

<div class="alert alert-info border-0 shadow-sm mb-3">
  <strong><?php echo $t('नियम', 'Rules'); ?>:</strong>
  <ul class="mb-0 small ps-3">
    <li><?php echo $t('Default अहिले जस्तै — कुनै समूह hide छैन भने सबै menu देखिन्छ।', 'Default unchanged — empty hide list means all menus show.'); ?></li>
    <li><?php echo $t('Tick गरिएको समूह सामान्य Admin बाट लुक्छ; Superadmin ले सधैं सबै देख्छ।', 'Ticked groups hide from normal admins; Superadmin always sees all.'); ?></li>
    <li><?php echo $t('सीधा URL बाट पनि लुकेको समूहको page खोल्न मिल्दैन (SA बाहेक)।', 'Hidden group pages are also blocked via direct URL (except SA).'); ?></li>
    <li><?php echo $t('सेभ गर्दा Confirm Code अनिवार्य — SA login मात्रले पुग्दैन।', 'Confirm Code is required to save — SA login alone is not enough.'); ?></li>
    <li><?php echo $t('संस्था (सेटिङ) वा सदस्य समूह लुकाउँदा सामान्य Admin ले ती page खोल्न सक्दैन — सावधानी अपनाउनुहोस्।', 'Hiding Organization or Members blocks those pages for normal admins — use carefully.'); ?></li>
  </ul>
</div>

<div class="d-flex flex-wrap gap-2 mb-2">
  <button type="button" class="btn btn-sm btn-outline-secondary" id="mcSelectNone"><?php echo $t('सबै अनटिक', 'Uncheck all'); ?></button>
  <button type="button" class="btn btn-sm btn-outline-warning" id="mcSelectOptional"><?php echo $t('आम optional मात्र लुकाउने (निर्वाचन/रोजगारी/कार्यक्रम)', 'Hide common optional (election/career/program)'); ?></button>
</div>

<form method="POST" class="card admin-table-card">
  <?php echo csrfField(); ?>
  <input type="hidden" name="action" value="save_menu_control">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h6 class="mb-0"><?php echo $t('लुकाउने मेनु समूह', 'Groups to hide'); ?></h6>
    <span class="badge bg-secondary"><?php echo count($hidden); ?> <?php echo $t('लुकेको', 'hidden'); ?></span>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <?php foreach ($catalog as $key => $meta): ?>
      <?php $isHidden = in_array($key, $hidden, true); ?>
      <div class="col-md-6 col-lg-4">
        <label class="border rounded-3 p-3 d-flex gap-2 h-100 <?php echo $isHidden ? 'border-warning bg-warning bg-opacity-10' : ''; ?>" style="cursor:pointer;">
          <input type="checkbox" class="form-check-input mt-1" name="hidden_groups[]" value="<?php echo htmlspecialchars($key); ?>" <?php echo $isHidden ? 'checked' : ''; ?>>
          <span>
            <strong><?php echo htmlspecialchars($adminIsEn ? $meta['en'] : $meta['np']); ?></strong>
            <span class="text-muted small d-block font-monospace"><?php echo htmlspecialchars($key); ?></span>
            <span class="small text-muted d-block mt-1"><?php echo htmlspecialchars($adminIsEn ? $meta['hint_en'] : $meta['hint_np']); ?></span>
          </span>
        </label>
      </div>
      <?php endforeach; ?>
    </div>

    <hr class="my-4">
    <div class="row g-3 align-items-end">
      <div class="col-md-5">
        <label for="confirm_code" class="form-label fw-semibold"><?php echo $t('Confirm Code', 'Confirm Code'); ?> <span class="text-danger">*</span></label>
        <input type="password" name="confirm_code" id="confirm_code" class="form-control form-control-lg" inputmode="numeric" autocomplete="off" required placeholder="<?php echo $t('कोड राख्नुहोस्…', 'Enter code…'); ?>">
        <div class="form-text"><?php echo $t('Menu परिवर्तन सुरक्षित गर्न भौतिक confirm code चाहिन्छ।', 'Physical confirm code required to protect menu changes.'); ?></div>
      </div>
      <div class="col-md-7 d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary btn-lg"><i class="lucide-icon me-1" data-lucide="save" aria-hidden="true"></i><?php echo $t('सेभ गर्नुहोस्', 'Save'); ?></button>
      </div>
    </div>
  </div>
</form>

<form method="POST" class="mt-3" onsubmit="return confirm('<?php echo $t('सबै menu फेरि देखाउने?', 'Show all menus again?'); ?>');">
  <?php echo csrfField(); ?>
  <input type="hidden" name="action" value="reset_menu_control">
  <div class="card border-0 bg-light">
    <div class="card-body d-flex flex-wrap align-items-end gap-2">
      <div class="flex-grow-1" style="min-width:200px;">
        <label class="form-label small mb-1"><?php echo $t('Reset को लागि पनि Confirm Code', 'Confirm Code also required to reset'); ?></label>
        <input type="password" name="confirm_code" class="form-control" inputmode="numeric" autocomplete="off" required>
      </div>
      <button type="submit" class="btn btn-outline-secondary"><?php echo $t('सबै देखाउनुहोस् (Reset)', 'Show all (Reset)'); ?></button>
    </div>
  </div>
</form>
</div>
<script>
(function(){
  function setGroups(keys) {
    var set = {};
    (keys || []).forEach(function(k){ set[k] = true; });
    document.querySelectorAll('input[name="hidden_groups[]"]').forEach(function(cb){
      cb.checked = !!set[cb.value];
    });
  }
  var noneBtn = document.getElementById('mcSelectNone');
  if (noneBtn) noneBtn.addEventListener('click', function(){ setGroups([]); });
  var optBtn = document.getElementById('mcSelectOptional');
  if (optBtn) optBtn.addEventListener('click', function(){ setGroups(['nirvachan','rojgar','program']); });
})();
</script>
<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
