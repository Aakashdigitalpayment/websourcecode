<?php
/**
 * Member Portal — कारोबार विवरण (Transactions)
 */
require_once __DIR__ . '/_bootstrap.php';
requireMemberLogin();
memberSecurityHeaders();

$member   = getLoggedInMemberProfile();
$memberId = $_SESSION['member_id'];
$db       = getDB();

// Pagination
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;
$filter  = in_array($_GET['type'] ?? '', ['credit','debit','']) ? ($_GET['type'] ?? '') : '';

$pageTitle = isEnglish() ? 'Transaction History' : 'कारोबार विवरण';
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

/* ── CSV Export — ?export=csv ────────────────────────────────
   Must run before ANY HTML output (headers must be clean).    */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        $whereFilter  = $filter ? 'AND transaction_type = ?' : '';
        $filterParams = $filter ? [$memberId, $filter] : [$memberId];

        $expSt = $db->prepare(
            "SELECT created_at, description, remarks, reference_no, amount, transaction_type
               FROM member_transactions
              WHERE member_id = ? $whereFilter
              ORDER BY created_at DESC"
        );
        $expSt->execute($filterParams);
        $rows = $expSt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        $rows = [];
    }

    $memberName = htmlspecialchars_decode(
        trim((string)($member['full_name'] ?? $member['name'] ?? 'member'))
    );
    $safeName   = preg_replace('/[^a-zA-Z0-9_-]/', '_', $memberName);
    $filename   = 'transactions_' . $safeName . '_' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens Nepali text correctly
    fwrite($out, "\xEF\xBB\xBF");

    // Header row
    fputcsv($out, ['मिति', 'विवरण', 'Reference No', 'रकम (रु.)', 'प्रकार']);

    foreach ($rows as $tx) {
        $desc    = trim((string)($tx['description'] ?? $tx['remarks'] ?? ''));
        $refno   = trim((string)($tx['reference_no'] ?? ''));
        $amount  = number_format((float)($tx['amount'] ?? 0), 2);
        $type    = ($tx['transaction_type'] ?? '') === 'credit' ? 'Credit (जम्मा)' : 'Debit (झिकेको)';
        $date    = $tx['created_at'] ? date('Y-m-d', strtotime($tx['created_at'])) : '';
        fputcsv($out, [$date, $desc, $refno, $amount, $type]);
    }
    fclose($out);
    exit;
}
?>
<?php require __DIR__ . '/includes/chrome.php'; ?>

<main class="mp-main">
<div class="mp-container">
<style>
.tx-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;}
.tx-title{font-size:1.3rem;font-weight:700;color:var(--primary-color);margin:0;}
.tx-icon-gap{margin-right:8px;}
.tx-filters{display:flex;gap:8px;flex-wrap:wrap;}
.tx-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;}
.tx-card{border-radius:10px;padding:16px;text-align:center;border:1px solid transparent;}
.tx-card.credit{background:color-mix(in srgb, var(--primary-color) 12%, white);border-color:color-mix(in srgb, var(--primary-color) 24%, white);}
.tx-card.debit{background:color-mix(in srgb, var(--secondary-color) 12%, white);border-color:color-mix(in srgb, var(--secondary-color) 24%, white);}
.tx-card.balance{background:color-mix(in srgb, var(--secondary-color) 10%, white);border-color:color-mix(in srgb, var(--secondary-color) 20%, white);}
.tx-card.total{background:color-mix(in srgb, var(--primary-color) 8%, white);border-color:color-mix(in srgb, var(--primary-color) 14%, #e5e7eb);}
.tx-amt{font-size:1.1rem;font-weight:700;}
.tx-amt.credit{color:var(--primary-color);}
.tx-amt.debit{color:var(--secondary-color);}
.tx-amt.balance{color:var(--secondary-dark,var(--secondary-color));}
.tx-amt.total{color:var(--text-color,#374151);}
.tx-lbl{font-size:12px;margin-top:4px;}
.tx-lbl.credit{color:var(--primary-dark,var(--primary-color));}
.tx-lbl.debit,.tx-lbl.balance{color:var(--secondary-dark,var(--secondary-color));}
.tx-lbl.total{color:var(--text-light,#6b7280);}
.tx-shell{background:white;border-radius:12px;box-shadow:0 2px 8px rgba(var(--primary-rgb,26,95,42),.08);overflow:hidden;}
.tx-empty{text-align:center;padding:48px 24px;}
.tx-empty-icon{font-size:2.5rem;color:var(--text-muted,#d1d5db);margin-bottom:12px;display:block;}
.tx-empty-text{color:var(--text-light,#6b7280);margin:0;}
.tx-scroll{overflow-x:auto;}
.tx-table{width:100%;border-collapse:collapse;}
.tx-th-row{background:color-mix(in srgb, var(--primary-color) 8%, white);border-bottom:2px solid color-mix(in srgb, var(--primary-color) 14%, #e5e7eb);}
.tx-th{padding:12px 16px;text-align:left;font-size:12px;font-weight:600;color:var(--text-color,#374151);}
.tx-th-right{text-align:right;white-space:nowrap;}
.tx-th-center{text-align:center;padding:12px 8px;}
.tx-row{border-bottom:1px solid color-mix(in srgb, var(--primary-color) 10%, #f3f4f6);}
.tx-td{padding:12px 16px;font-size:13px;color:var(--text-light,#6b7280);white-space:nowrap;}
.tx-td-main{padding:12px 16px;font-size:13px;color:var(--text-color,#111827);}
.tx-ref{color:var(--text-muted,#9ca3af);}
.tx-amount{padding:12px 16px;text-align:right;font-size:14px;font-weight:700;white-space:nowrap;}
.tx-amount.credit{color:var(--primary-color);}
.tx-amount.debit{color:var(--secondary-color);}
.tx-type{padding:12px 8px;text-align:center;}
.tx-pill{display:inline-flex;align-items:center;gap:4px;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;border:1px solid transparent;}
.tx-pill.credit{background:color-mix(in srgb, var(--primary-color) 12%, white);color:var(--primary-color);border-color:color-mix(in srgb, var(--primary-color) 24%, white);}
.tx-pill.debit{background:color-mix(in srgb, var(--secondary-color) 12%, white);color:var(--secondary-color);border-color:color-mix(in srgb, var(--secondary-color) 24%, white);}
.tx-pag{display:flex;justify-content:center;gap:8px;padding:16px;border-top:1px solid color-mix(in srgb, var(--primary-color) 10%, #f3f4f6);flex-wrap:wrap;}
.tx-page-link{padding:6px 12px;border:1px solid color-mix(in srgb, var(--primary-color) 18%, #d1d5db);border-radius:6px;font-size:13px;color:var(--text-color,#374151);text-decoration:none;}
.tx-page-link.active{background:var(--primary-color);color:var(--text-on-primary,white);border:1px solid var(--primary-color);}
.tx-csv-btn{border-color:#6b7280;color:#374151;background:white;}
.tx-csv-btn:hover{background:#f0fdf4;border-color:var(--primary-color);color:var(--primary-color);}
</style>

  <!-- Page header -->
  <div class="tx-head">
    <h1 class="tx-title">
      <i class="fas fa-money-bill-transfer tx-icon-gap"></i>
      <?php echo isEnglish() ? 'Transaction History' : 'कारोबार विवरण'; ?>
    </h1>
    <div class="tx-filters">
      <a href="?type=" class="btn btn-sm <?php echo $filter==='' ? 'btn-success' : 'btn-outline-secondary'; ?>"><?php echo $_t('सबै', 'All'); ?></a>
      <a href="?type=credit" class="btn btn-sm <?php echo $filter==='credit' ? 'btn-success' : 'btn-outline-secondary'; ?>">
        <i class="fas fa-arrow-down me-1"></i><?php echo $_t('जम्मा', 'Credit'); ?>
      </a>
      <a href="?type=debit" class="btn btn-sm <?php echo $filter==='debit' ? 'btn-danger' : 'btn-outline-secondary'; ?>">
        <i class="fas fa-arrow-up me-1"></i><?php echo $_t('झिकेको', 'Debit'); ?>
      </a>
      <a href="?export=csv<?php echo $filter ? '&type=' . urlencode($filter) : ''; ?>"
         class="btn btn-sm btn-outline-secondary tx-csv-btn"
         title="<?php echo $_t('सबै कारोबार CSV मा डाउनलोड गर्नुहोस्', 'Download all transactions as CSV'); ?>">
        <i class="fas fa-file-csv me-1"></i><?php echo $_t('CSV डाउनलोड', 'Export CSV'); ?>
      </a>
    </div>
  </div>

  <?php
  $transactions = [];
  $totalCount   = 0;
  $totalCredit  = 0;
  $totalDebit   = 0;
  try {
      // Try member_transactions table first
      $whereFilter = $filter ? "AND transaction_type = ?" : '';
      $filterParams = $filter ? [$memberId, $filter] : [$memberId];

      $countSt = $db->prepare("SELECT COUNT(*), SUM(CASE WHEN transaction_type='credit' THEN amount ELSE 0 END), SUM(CASE WHEN transaction_type='debit' THEN amount ELSE 0 END) FROM member_transactions WHERE member_id=? $whereFilter");
      $countSt->execute($filterParams);
      [$totalCount, $totalCredit, $totalDebit] = $countSt->fetch(\PDO::FETCH_NUM);

      $st = $db->prepare("SELECT * FROM member_transactions WHERE member_id=? $whereFilter ORDER BY created_at DESC LIMIT ? OFFSET ?");
      $st->execute(array_merge($filterParams, [$perPage, $offset]));
      $transactions = $st->fetchAll(\PDO::FETCH_ASSOC);
  } catch (\Exception $e) {
      // Table may not exist yet
      $transactions = [];
      $totalCount   = 0;
  }
  $totalPages = $totalCount > 0 ? ceil($totalCount / $perPage) : 1;
  ?>

  <!-- Summary Cards -->
  <div class="tx-summary-grid">
    <div class="tx-card credit">
      <div class="tx-amt credit"><?php echo formatNepaliCurrency((float)$totalCredit); ?></div>
      <div class="tx-lbl credit"><i class="fas fa-arrow-down me-1"></i><?php echo $_t('जम्मा', 'Credit'); ?></div>
    </div>
    <div class="tx-card debit">
      <div class="tx-amt debit"><?php echo formatNepaliCurrency((float)$totalDebit); ?></div>
      <div class="tx-lbl debit"><i class="fas fa-arrow-up me-1"></i><?php echo $_t('झिकेको', 'Debit'); ?></div>
    </div>
    <div class="tx-card balance">
      <div class="tx-amt balance"><?php echo formatNepaliCurrency((float)$totalCredit - (float)$totalDebit); ?></div>
      <div class="tx-lbl balance"><i class="fas fa-wallet me-1"></i><?php echo $_t('ब्यालेन्स', 'Balance'); ?></div>
    </div>
    <div class="tx-card total">
      <div class="tx-amt total"><?php echo toNepaliNumeral($totalCount); ?></div>
      <div class="tx-lbl total"><i class="fas fa-list me-1"></i><?php echo $_t('जम्मा कारोबार', 'Total Transactions'); ?></div>
    </div>
  </div>

  <!-- Transaction List -->
  <div class="tx-shell">
    <?php if (empty($transactions)): ?>
    <div class="tx-empty">
      <i class="fas fa-receipt tx-empty-icon"></i>
      <p class="tx-empty-text">
        <?php echo isEnglish() ? 'No transactions found.' : 'कुनै कारोबार फेला परेन।'; ?>
      </p>
    </div>
    <?php else: ?>
    <div class="tx-scroll mem-table-card">
      <table class="tx-table table-responsive-stack">
        <thead>
          <tr class="tx-th-row">
            <th class="tx-th"><?php echo $_t('मिति', 'Date'); ?></th>
            <th class="tx-th"><?php echo $_t('विवरण', 'Description'); ?></th>
            <th class="tx-th tx-th-right"><?php echo $_t('रकम (रु.)', 'Amount'); ?></th>
            <th class="tx-th tx-th-center"><?php echo $_t('प्रकार', 'Type'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transactions as $tx): ?>
          <?php $isCredit = ($tx['transaction_type'] ?? '') === 'credit'; ?>
          <tr class="tx-row">
            <td class="tx-td">
              <?php echo htmlspecialchars(date('Y-m-d', strtotime($tx['created_at']))); ?>
            </td>
            <td class="tx-td-main">
              <?php echo htmlspecialchars($tx['description'] ?? $tx['remarks'] ?? '—'); ?>
              <?php if (!empty($tx['reference_no'])): ?>
              <br><small class="tx-ref"><?php echo $_t('रेफ:', 'Ref:'); ?> <?php echo htmlspecialchars($tx['reference_no']); ?></small>
              <?php endif; ?>
            </td>
            <td class="tx-amount <?php echo $isCredit ? 'credit' : 'debit'; ?>">
              <?php echo ($isCredit ? '+' : '−') . formatNepaliCurrency((float)($tx['amount'] ?? 0)); ?>
            </td>
            <td class="tx-type">
              <?php if ($isCredit): ?>
              <span class="tx-pill credit">
                <i class="fas fa-arrow-down"></i> <?php echo $_t('जम्मा', 'Credit'); ?>
              </span>
              <?php else: ?>
              <span class="tx-pill debit">
                <i class="fas fa-arrow-up"></i> <?php echo $_t('झिकेको', 'Debit'); ?>
              </span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="tx-pag">
      <?php if ($page > 1): ?>
      <a href="?page=<?php echo $page-1; ?>&type=<?php echo $filter; ?>" class="tx-page-link">‹</a>
      <?php endif; ?>
      <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
      <a href="?page=<?php echo $i; ?>&type=<?php echo $filter; ?>" class="tx-page-link <?php echo $i===$page ? 'active' : ''; ?>">
        <?php echo $i; ?>
      </a>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
      <a href="?page=<?php echo $page+1; ?>&type=<?php echo $filter; ?>" class="tx-page-link">›</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div>
</main>

<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
