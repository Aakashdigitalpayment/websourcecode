<?php
$pageTitle = 'बोलपत्र व्यवस्थापन';
$currentPage = 'auction-bids';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/auth-roles.php';
/* RBAC: staff hercha matra; mutate admin+ matra */
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_role('admin');

$db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();
$auction_id = intval($_GET['auction_id'] ?? 0);

require_once __DIR__ . '/../includes/auction-tables.php';
ensureAuctionTables($db);

/* ── POST handlers ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update_bid'])) {
            $id     = intval($_POST['id']);
            $status = clean_text($_POST['status'] ?? 'pending');
            if (!in_array($status, ['pending','accepted','rejected'])) $status = 'pending';
            $stmt = $db->prepare("UPDATE auction_bids SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            setFlash('success', 'बोलपत्र स्थिति अपडेट भयो।');
            redirect('auction-bids.php?auction_id=' . $auction_id);
        }
        if (isset($_POST['delete_bid'])) {
            $id = intval($_POST['bid_id']);
            $stmt = $db->prepare("DELETE FROM auction_bids WHERE id = ?");
            $stmt->execute([$id]);
            setFlash('success', 'बोलपत्र मेटाइयो।');
            redirect('auction-bids.php?auction_id=' . $auction_id);
        }
    } catch (Exception $e) {
        setFlash('error', 'कार्य गर्दा त्रुटि भयो। कृपया पुनः प्रयास गर्नुहोस्।');
        redirect('auction-bids.php?auction_id=' . $auction_id);
    }
}

/* ── Fetch data ── */
$auction = null;
$bids    = [];
$bidCounts = ['pending'=>0,'accepted'=>0,'rejected'=>0];
$statusClass = ['pending' => 'warning', 'accepted' => 'success', 'rejected' => 'danger'];
$statusLabel = ['pending' => 'पेन्डिङ', 'accepted' => 'स्वीकृत', 'rejected' => 'अस्वीकृत'];
$bidStatusFilter = $_GET['bid_status'] ?? '';
if ($bidStatusFilter !== '' && !in_array($bidStatusFilter, ['pending', 'accepted', 'rejected'], true)) {
    $bidStatusFilter = '';
}
$bidSearch = mb_substr(trim((string)($_GET['bid_search'] ?? '')), 0, 200, 'UTF-8');
try {
    if ($auction_id) {
        $stmt = $db->prepare("SELECT * FROM auction_notices WHERE id = ?");
        $stmt->execute([$auction_id]);
        $auction = $stmt->fetch();
    }
    // Build bid query with filters
    $bidWhere = $auction_id ? "ab.auction_id = ?" : "1=1";
    $bidParams = $auction_id ? [$auction_id] : [];
    if ($bidStatusFilter) { $bidWhere .= " AND ab.status = ?"; $bidParams[] = $bidStatusFilter; }
    if ($bidSearch !== '') {
        $bidWhere .= " AND (ab.bidder_name LIKE ? OR ab.bidder_phone LIKE ? OR ab.bidder_email LIKE ?)";
        $t = "%$bidSearch%"; $bidParams = array_merge($bidParams, [$t,$t,$t]);
    }
    $stmt = $db->prepare("SELECT ab.*, an.title as auction_title FROM auction_bids ab LEFT JOIN auction_notices an ON ab.auction_id = an.id WHERE $bidWhere ORDER BY ab.bid_amount DESC, ab.created_at DESC");
    $stmt->execute($bidParams);
    $bids = $stmt->fetchAll();
    // Counts
    if ($auction_id) {
        $cntStmt = $db->prepare("SELECT status, COUNT(*) as c FROM auction_bids WHERE auction_id = ? GROUP BY status");
        $cntStmt->execute([$auction_id]);
    } else {
        $cntStmt = $db->query("SELECT status, COUNT(*) as c FROM auction_bids GROUP BY status");
    }
    while ($cr = $cntStmt->fetch()) { $bidCounts[$cr['status']] = $cr['c']; }
} catch (Exception $e) {
    $bids = [];
}
?>

<?php echo adminPageHeader(
    'बोलपत्र व्यवस्थापन' . ($auction ? ' — ' . htmlspecialchars($auction['title']) : ''),
    'fa-list-ol',
    'लिलामीमा परेका बोलपत्रहरूको सूची र स्थिति व्यवस्थापन।',
    '<a href="auctions.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i>लिलामीमा फर्कनुहोस्</a>'
); ?>
<?php $_flash = getFlash(); if ($_flash) echo adminAlert($_flash['type'], $_flash['message']); ?>

<?php if ($auction): ?>
<div class="card mb-4 border-0 shadow-sm" style="border-radius:10px;">
    <div class="card-body py-3">
        <div class="row align-items-center">
            <div class="col-md-4"><strong>लिलामी:</strong> <?php echo htmlspecialchars($auction['title']); ?></div>
            <div class="col-md-3"><strong>न्यूनतम मूल्य:</strong> रु. <?php echo number_format((float)($auction['minimum_price'] ?? 0)); ?></div>
            <div class="col-md-3"><strong>मिति:</strong> <?php echo htmlspecialchars($auction['auction_date'] ?? 'N/A'); ?></div>
            <div class="col-md-2"><strong>स्थान:</strong> <?php echo htmlspecialchars($auction['location'] ?? 'N/A'); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Stat Mini Row ── -->
<div class="stat-mini-row no-print">
    <a href="<?php echo $auction_id?'?auction_id='.$auction_id:'auction-bids.php'; ?>" class="stat-mini <?php echo !$bidStatusFilter?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-gavel"></i></div>
        <div class="sm-val"><?php echo array_sum($bidCounts); ?></div>
        <div class="sm-lbl">जम्मा</div>
    </a>
    <a href="?<?php echo $auction_id?'auction_id='.$auction_id.'&':''; ?>bid_status=pending" class="stat-mini <?php echo $bidStatusFilter==='pending'?'active-filter':''; ?>">
        <div class="sm-icon ic-pending"><i class="fas fa-clock"></i></div>
        <div class="sm-val"><?php echo $bidCounts['pending']; ?></div>
        <div class="sm-lbl">पेन्डिङ</div>
    </a>
    <a href="?<?php echo $auction_id?'auction_id='.$auction_id.'&':''; ?>bid_status=accepted" class="stat-mini <?php echo $bidStatusFilter==='accepted'?'active-filter':''; ?>">
        <div class="sm-icon ic-approved"><i class="fas fa-check-circle"></i></div>
        <div class="sm-val"><?php echo $bidCounts['accepted']; ?></div>
        <div class="sm-lbl">स्वीकृत</div>
    </a>
    <a href="?<?php echo $auction_id?'auction_id='.$auction_id.'&':''; ?>bid_status=rejected" class="stat-mini <?php echo $bidStatusFilter==='rejected'?'active-filter':''; ?>">
        <div class="sm-icon ic-rejected"><i class="fas fa-times-circle"></i></div>
        <div class="sm-val"><?php echo $bidCounts['rejected']; ?></div>
        <div class="sm-lbl">अस्वीकृत</div>
    </a>
</div>

<!-- ── Bid Filter Bar ── -->
<div class="adm-filter-bar no-print">
    <form method="GET" class="row g-2 align-items-end">
        <?php if ($auction_id): ?><input type="hidden" name="auction_id" value="<?php echo $auction_id; ?>"><?php endif; ?>
        <div class="col-md-3 col-6">
            <label>स्थिति</label>
            <select name="bid_status" class="form-select form-select-sm" onchange="this.closest('form').submit()">
                <option value="">सबै स्थिति</option>
                <option value="pending"  <?php echo $bidStatusFilter==='pending'?'selected':''; ?>>⏳ पेन्डिङ</option>
                <option value="accepted" <?php echo $bidStatusFilter==='accepted'?'selected':''; ?>>✅ स्वीकृत</option>
                <option value="rejected" <?php echo $bidStatusFilter==='rejected'?'selected':''; ?>>❌ अस्वीकृत</option>
            </select>
        </div>
        <div class="col-md-7 col-12">
            <label>खोज्नुहोस्</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="bid_search" class="form-control" value="<?php echo htmlspecialchars($bidSearch); ?>" placeholder="बोलपत्रदाताको नाम, फोन, इमेल...">
            </div>
        </div>
        <div class="col-md-2 col-6">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search me-1"></i>खोज</button>
            <?php if ($bidStatusFilter||$bidSearch): ?><a href="auction-bids.php<?php echo $auction_id?'?auction_id='.$auction_id:''; ?>" class="btn btn-outline-secondary btn-sm w-100 mt-1"><i class="fas fa-times me-1"></i>हटाउनुहोस्</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- ── Bids Table ── -->
<div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden;">
    <div class="tbl-header-bar no-print">
        <h6><i class="fas fa-list-ol me-2 text-primary"></i>बोलपत्र सूची</h6>
        <span class="result-count-badge"><?php echo count($bids); ?> बोलपत्र</span>
    </div>
    <div class="table-responsive">
        <table class="table-hover table app-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>बोलपत्रदाता</th>
                        <th>सम्पर्क</th>
                        <th>बोलपत्र रकम</th>
                        <th>सन्देश</th>
                        <th>मिति</th>
                        <th>स्थिति</th>
                        <th>कार्य</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bids as $bid): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($bid['bidder_name']); ?></strong>
                            <?php if (!$auction_id && !empty($bid['auction_title'])): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($bid['auction_title']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <i class="fas fa-phone"></i> <?php echo htmlspecialchars($bid['bidder_phone']); ?>
                            <?php if (!empty($bid['bidder_email'])): ?>
                            <br><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($bid['bidder_email']); ?>
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-success">रु. <?php echo number_format((float)$bid['bid_amount']); ?></td>
                        <td><?php echo htmlspecialchars(mb_strimwidth($bid['message'] ?? '', 0, 50, '…')); ?></td>
                        <td><?php echo formatNepaliDate($bid['created_at'], true); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $statusClass[$bid['status']] ?? 'secondary'; ?>">
                                <?php echo $statusLabel[$bid['status']] ?? htmlspecialchars($bid['status']); ?>
                            </span>
                        </td>
                        <td class="no-print">
                            <div class="adm-action-icons dropdown">
                                <button type="button" class="adm-icon-btn adm-icon-btn--edit dropdown-toggle" data-bs-toggle="dropdown" title="कार्य" aria-label="Actions">
                                    <i class="fas fa-sliders"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <form method="POST" class="d-inline">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="id" value="<?php echo (int)$bid['id']; ?>">
                                            <input type="hidden" name="status" value="accepted">
                                            <button type="submit" name="update_bid" class="dropdown-item text-success">
                                                <i class="fas fa-check me-1"></i>स्वीकृत
                                            </button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="POST" class="d-inline">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="id" value="<?php echo (int)$bid['id']; ?>">
                                            <input type="hidden" name="status" value="rejected">
                                            <button type="submit" name="update_bid" class="dropdown-item text-danger">
                                                <i class="fas fa-times me-1"></i>अस्वीकृत
                                            </button>
                                        </form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('के तपाईं पक्का हुनुहुन्छ?')">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="delete_bid" value="1">
                                            <input type="hidden" name="bid_id" value="<?php echo (int)$bid['id']; ?>">
                                            <button type="submit" class="dropdown-item text-danger">
                                                <i class="fas fa-trash me-1"></i>मेटाउनुहोस्
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bids)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">
                        <i class="fas fa-inbox fa-2x d-block mb-2"></i>कुनै बोलपत्र छैन
                    </td></tr>
                    <?php endif; ?>
                </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
