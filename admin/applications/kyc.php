<?php
/**
 * KYC Application List + Generate Member action
 * v10.0 — Refactored with new design system
 */
require_once __DIR__ . '/../_bootstrap.php';
requireAdminLogin();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Fetch KYC applications
$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all', 'pending', 'approved', 'rejected'], true)) {
    $status = 'all';
}
$where = ($status === 'all') ? '' : 'WHERE status = ' . $pdo->quote($status);
$rows = $pdo->query("SELECT * FROM kyc_applications {$where} ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

$counts = $pdo->query("SELECT
    COUNT(*) total,
    SUM(status='pending')  pending,
    SUM(status='approved') approved,
    SUM(status='rejected') rejected,
    SUM(member_id_generated IS NOT NULL) generated
  FROM kyc_applications")->fetch(PDO::FETCH_ASSOC);

$page_title = 'KYC आवेदन व्यवस्थापन';
include __DIR__ . '/../_partials/header.php';
?>

<div class="admin-page-header">
  <h1 class="admin-page-title"><i class="fas fa-id-card-clip"></i> KYC आवेदन</h1>
  <a href="/admin/" class="admin-btn admin-btn-ghost"><i class="fas fa-arrow-left"></i> ड्यासबोर्डमा फर्क</a>
</div>

<?php
  $statCards = [
    ['icon'=>'fa-inbox',      'label'=>'जम्मा KYC',    'value'=>(int)$counts['total'],     'color'=>'info',    'link'=>'kyc-applications.php'],
    ['icon'=>'fa-clock',      'label'=>'पेन्डिङ',       'value'=>(int)$counts['pending'],   'color'=>'warning', 'link'=>'kyc-applications.php?status=pending'],
    ['icon'=>'fa-check',      'label'=>'स्वीकृत',       'value'=>(int)$counts['approved'],  'color'=>'success', 'link'=>'kyc-applications.php?status=approved'],
    ['icon'=>'fa-xmark',      'label'=>'अस्वीकृत',      'value'=>(int)$counts['rejected'],  'color'=>'danger',  'link'=>'kyc-applications.php?status=rejected'],
    ['icon'=>'fa-user-check', 'label'=>'सदस्य बनेका',   'value'=>(int)$counts['generated'], 'color'=>'primary', 'link'=>'members.php'],
  ];
  $statColClass = 'col-6 col-sm-4 col-md-2';
  include __DIR__ . '/../../includes/components/stat-card.php';
?>

<div class="admin-card">
  <form method="get" class="admin-flex" style="flex-wrap:wrap;">
    <select name="status" class="admin-select" style="max-width:200px;" onchange="this.form.submit()">
      <option value="all"      <?= $status==='all'?'selected':'' ?>>सबै स्थिति</option>
      <option value="pending"  <?= $status==='pending'?'selected':'' ?>>पेन्डिङ</option>
      <option value="approved" <?= $status==='approved'?'selected':'' ?>>स्वीकृत</option>
      <option value="rejected" <?= $status==='rejected'?'selected':'' ?>>अस्वीकृत</option>
    </select>
    <input type="search" name="q" placeholder="नाम, मोबाइल, Tracking ID..." class="admin-input" style="max-width:300px;">
    <button class="admin-btn admin-btn-primary"><i class="fas fa-search"></i> खोज</button>
  </form>
</div>

<div class="admin-card">
  <h2 class="admin-card-title"><i class="fas fa-list"></i> KYC सूची <span class="admin-badge"><?= count($rows) ?></span></h2>

  <?php if (!$rows): ?>
    <div class="admin-empty">
      <div class="empty-icon"><i class="fas fa-inbox"></i></div>
      <div class="empty-title">कुनै KYC आवेदन फेला परेन</div>
      <div class="empty-text">हाल कुनै आवेदन छैन।</div>
    </div>
  <?php else: ?>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr>
          <th>आवेदक</th><th>सम्पर्क</th><th>नागरिकता</th>
          <th>Tracking ID</th><th>दर्ता मिति</th><th>स्थिति</th><th>कार्य</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td data-label="आवेदक"><b><?= htmlspecialchars($r['full_name'] ?? '-') ?></b><br>
              <small style="color:var(--text-muted);"><?= htmlspecialchars($r['address'] ?? '') ?></small></td>
          <td data-label="सम्पर्क"><?= htmlspecialchars($r['mobile'] ?? '-') ?><br>
              <small><?= htmlspecialchars($r['email'] ?? '') ?></small></td>
          <td data-label="नागरिकता"><?= htmlspecialchars($r['citizenship_no'] ?? '-') ?></td>
          <td data-label="Tracking"><code><?= htmlspecialchars($r['tracking_id'] ?? '-') ?></code></td>
          <td data-label="मिति"><?= date('Y-m-d', strtotime($r['created_at'])) ?></td>
          <td data-label="स्थिति">
            <?php
              $s = $r['status'];
              $cls = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$s] ?? '';
              $lbl = ['pending'=>'पेन्डिङ','approved'=>'स्वीकृत','rejected'=>'अस्वीकृत'][$s] ?? $s;
            ?>
            <span class="admin-badge admin-badge-<?= $cls ?>"><?= $lbl ?></span>
          </td>
          <td data-label="कार्य">
            <a href="kyc-view.php?id=<?= (int)$r['id'] ?>" class="admin-btn admin-btn-secondary admin-btn-sm">
              <i class="fas fa-eye"></i> हेर्नुहोस्
            </a>
            <?php if ($r['status'] === 'approved' && empty($r['member_id_generated'])): ?>
              <button onclick="generateMember(<?= (int)$r['id'] ?>, this)"
                      class="admin-btn admin-btn-primary admin-btn-sm">
                <i class="fas fa-user-plus"></i> सदस्य बनाउनुहोस्
              </button>
            <?php elseif (!empty($r['member_id_generated'])): ?>
              <span class="admin-badge admin-badge-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($r['member_id_generated']) ?>
              </span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;

async function generateMember(kycId, btn) {
  if (!confirm('यो KYC बाट सदस्य खाता auto-generate गर्नुहुन्छ?\n\n• Member ID, Password auto बन्छ\n• ID Card create हुन्छ\n• SMS र Email मा credentials पठाइन्छ')) return;

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

  try {
    const fd = new FormData();
    fd.append('kyc_id', kycId);
    fd.append('_csrf', CSRF);
    const res = await fetch('kyc-generate-member.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.ok) {
      alert('✅ सफल!\n\nMember ID: ' + data.member_id +
            '\nPassword: ' + data.password +
            '\nID Card: ' + (data.card_no || '-') +
            '\n\n' + data.message +
            '\n\n⚠ Password अहिले नै note गर्नुहोस् — फेरि देखिँदैन।');
      location.reload();
    } else {
      alert('❌ ' + (data.message || 'Unknown error'));
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-user-plus"></i> सदस्य बनाउनुहोस्';
    }
  } catch (e) {
    alert('Network error: ' + e.message);
    btn.disabled = false;
  }
}
</script>

<?php include __DIR__ . '/../_partials/footer.php'; ?>
