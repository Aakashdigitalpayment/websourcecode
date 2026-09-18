<?php
/**
 * Admin: संस्थागत राहत — Opening (portal अघिको ऐतिहासिक कुल)
 * Types from member-welfare catalog only.
 */
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once dirname(__DIR__) . '/includes/institutional-profile-welfare.php';

$db = getDB();
coopIpEnsureWelfareTables($db);

$selfUrl = 'institutional-welfare-opening.php';
$types = coopIpWelfareTypesForAdmin($db);
$opening = coopIpWelfareOpeningMap($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $_SESSION['flash_error'] = 'सुरक्षा जाँच असफल।';
        header('Location: ' . $selfUrl);
        exit;
    }
    $rows = [];
    $slugs = $_POST['claim_type'] ?? [];
    $counts = $_POST['opening_count'] ?? [];
    $amounts = $_POST['opening_amount'] ?? [];
    $notes = $_POST['note'] ?? [];
    if (is_array($slugs)) {
        foreach ($slugs as $i => $slug) {
            $slug = trim((string)$slug);
            if ($slug === '') {
                continue;
            }
            $rows[$slug] = [
                'count' => (int)($counts[$i] ?? 0),
                'amount' => (float)($amounts[$i] ?? 0),
                'note' => (string)($notes[$i] ?? ''),
            ];
        }
    }
    if (coopIpWelfareSaveOpening($db, $rows)) {
        $_SESSION['flash_success'] = 'राहत Opening मूल्य सुरक्षित भयो। मासिक प्रोफाइल बनाउँदा cumulative मा जोडिन्छ।';
        if (function_exists('clearHomepageCache')) {
            clearHomepageCache();
        }
    } else {
        $_SESSION['flash_error'] = 'Opening बचत असफल।';
    }
    header('Location: ' . $selfUrl);
    exit;
}

$csrf = generateCSRFToken();
$flashOk = (string)($_SESSION['flash_success'] ?? '');
$flashErr = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

echo adminPageHeader(
    'राहत Opening',
    'fa-hand-holding-heart',
    'Portal सुरु हुनुअघिको सदस्य राहत कुल — प्रकार member-welfare बाट',
    adminBackBtn('institutional-profile.php')
);
?>

<div class="admin-form-page">
  <?php if ($flashOk !== ''): ?>
    <div class="alert alert-success"><?php echo e($flashOk); ?></div>
  <?php endif; ?>
  <?php if ($flashErr !== ''): ?>
    <div class="alert alert-danger"><?php echo e($flashErr); ?></div>
  <?php endif; ?>

  <div class="alert alert-info small">
    <strong>के हो?</strong> सहकारीले portal राख्नुभन्दा पहिले दिएको राहत यहाँ <em>opening</em> का रूपमा राख्नुहोस्।
    प्रकार नयाँ बनाउन पर्दैन — <a href="welfare-claim-types.php">कल्याण दाबी प्रकार</a> बाट आउँछ।
    मासिक संस्थागत प्रोफाइल create गर्दा: <strong>opening + portal मा approved</strong> auto-fill हुन्छ; तपाईंले नम्बर फेर्न सक्नुहुन्छ।
  </div>

  <?php if (!$types): ?>
    <div class="alert alert-warning">अहिले कुनै राहत प्रकार छैन। पहिले <a href="welfare-claim-types.php">प्रकार थप्नुहोस्</a>।</div>
  <?php else: ?>
  <form method="post" class="card border-0 shadow-sm">
    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
    <div class="card-body table-responsive">
      <table class="table table-sm align-middle mb-0" data-testid="ip-welfare-opening-table">
        <thead>
          <tr>
            <th>राहत प्रकार</th>
            <th style="width:8rem;">जम्मा संख्या</th>
            <th style="width:10rem;">जम्मा रकम (रू.)</th>
            <th>टिप्पणी</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($types as $slug => $meta):
              $label = trim((string)(($meta['np'] ?? '') ?: ($meta['en'] ?? $slug)));
              $ov = $opening[$slug] ?? ['count' => 0, 'amount' => 0, 'note' => ''];
          ?>
          <tr>
            <td>
              <input type="hidden" name="claim_type[]" value="<?php echo e($slug); ?>">
              <span class="fw-semibold"><?php echo e($label); ?></span>
              <div class="small text-muted"><code><?php echo e($slug); ?></code></div>
            </td>
            <td>
              <input type="number" min="0" step="1" class="form-control form-control-sm"
                     name="opening_count[]" value="<?php echo (int)$ov['count']; ?>"
                     data-testid="ip-welfare-opening-count-<?php echo e($slug); ?>">
            </td>
            <td>
              <input type="number" min="0" step="0.01" class="form-control form-control-sm"
                     name="opening_amount[]" value="<?php echo e((string)$ov['amount']); ?>"
                     data-testid="ip-welfare-opening-amount-<?php echo e($slug); ?>">
            </td>
            <td>
              <input type="text" class="form-control form-control-sm" name="note[]"
                     value="<?php echo e((string)$ov['note']); ?>" maxlength="255"
                     placeholder="उदा. २०७५–८० को राहत">
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer d-flex gap-2 flex-wrap">
      <button type="submit" class="btn btn-success" data-testid="ip-welfare-opening-save">
        <i class="lucide-icon me-1" data-lucide="save" aria-hidden="true"></i>सुरक्षित गर्नुहोस्
      </button>
      <a href="institutional-profile.php" class="btn btn-outline-secondary">संस्थागत प्रोफाइल</a>
      <a href="welfare-claim-types.php" class="btn btn-outline-primary">राहत प्रकार</a>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
