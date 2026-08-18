<?php
/**
 * Admin — सदस्य बजार / सीप सूची स्वीकृति
 */
$pageTitle = 'सदस्य बजार / सीप';
$currentPage = 'member-marketplace';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/member-marketplace-tables.php';

$adminT = $adminT ?? static function (string $np, string $en): string {
    return (function_exists('isEnglish') && isEnglish()) ? $en : $np;
};

try {
    $db = getDB();
} catch (Exception $e) {
    $db = null;
}

checkCSRF();
$csrfToken = generateCSRFToken();

if ($db instanceof PDO) {
    ensureMemberMarketplaceTables($db);
    mpExpireStaleListings($db);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!($db instanceof PDO)) {
        $error = 'डेटाबेस जडान उपलब्ध छैन।';
    } elseif (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'सुरक्षा जाँच असफल भयो।';
    } else {
        $action = clean_text($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        $row = $id > 0 ? mpFetchListingById($db, $id) : null;

        if ($action === 'approve' && $row) {
            if (!mpIsApproveEligible($row)) {
                setFlash('error', 'उपलब्ध समय पहिले नै सकिसकेको छ — पहिले मिति सम्पादन गर्नुहोस् वा अस्वीकृत गर्नुहोस्।');
                redirect('member-marketplace.php?view=' . $id . '&tab=' . urlencode((string) ($_GET['tab'] ?? 'pending')));
            }
            $adminId = (int) ($_SESSION['admin_id'] ?? 0);
            $db->prepare(
                "UPDATE member_marketplace_listings
                 SET status='approved', is_active=1, approved_at=NOW(), approved_by=?, admin_note=''
                 WHERE id=?"
            )->execute([$adminId ?: null, $id]);
            try {
                $db->prepare('INSERT INTO member_notifications (member_id, title, message, type, link) VALUES (?,?,?,?,?)')
                    ->execute([
                        (int) $row['member_id'],
                        'सूची स्वीकृत',
                        'तपाईंको सूची "' . (string) $row['title'] . '" सार्वजनिक बजारमा देखिएको छ।',
                        'success',
                        SITE_URL . 'member/marketplace.php',
                    ]);
            } catch (Throwable $e) { /* notifications table may be missing */ }
            setFlash('success', 'सूची सार्वजनिक गरियो।');
            redirect('member-marketplace.php?tab=approved');
        }

        if ($action === 'reject' && $row) {
            $note = clean_text($_POST['admin_note'] ?? '', 500);
            $db->prepare(
                "UPDATE member_marketplace_listings
                 SET status='rejected', admin_note=?, approved_at=NULL, approved_by=NULL
                 WHERE id=?"
            )->execute([$note, $id]);
            try {
                $msg = 'तपाईंको सूची "' . (string) $row['title'] . '" अस्वीकृत भयो।';
                if ($note !== '') {
                    $msg .= ' कारण: ' . $note;
                }
                $db->prepare('INSERT INTO member_notifications (member_id, title, message, type, link) VALUES (?,?,?,?,?)')
                    ->execute([
                        (int) $row['member_id'],
                        'सूची अस्वीकृत',
                        $msg,
                        'warning',
                        SITE_URL . 'member/marketplace.php',
                    ]);
            } catch (Throwable $e) { /* ignore */ }
            setFlash('success', 'सूची अस्वीकृत गरियो।');
            redirect('member-marketplace.php?tab=rejected');
        }

        if ($action === 'expire' && $row) {
            $db->prepare("UPDATE member_marketplace_listings SET status='expired' WHERE id=?")->execute([$id]);
            setFlash('success', 'सूची सार्वजनिकबाट हटाइयो (अवधि सकियो)।');
            redirect('member-marketplace.php?tab=expired');
        }

        if ($action === 'delete' && $row) {
            if (!empty($row['image']) && function_exists('deleteFile')) {
                try { deleteFile((string) $row['image']); } catch (Throwable $e) { /* ignore */ }
            }
            $db->prepare('DELETE FROM member_marketplace_inquiries WHERE listing_id=?')->execute([$id]);
            $db->prepare('DELETE FROM member_marketplace_listings WHERE id=?')->execute([$id]);
            setFlash('success', 'सूची मेटाइयो।');
            redirect('member-marketplace.php');
        }
    }
}

$tab = (string) ($_GET['tab'] ?? 'pending');
$validTabs = ['pending', 'approved', 'rejected', 'expired', 'withdrawn', 'all'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'pending';
}

$counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'expired' => 0, 'withdrawn' => 0];
$rows = [];
try {
    if (!($db instanceof PDO)) {
        throw new RuntimeException('DB unavailable');
    }
    foreach (array_keys($counts) as $k) {
        if ($k === 'all') {
            $counts[$k] = (int) $db->query('SELECT COUNT(*) FROM member_marketplace_listings')->fetchColumn();
        } else {
            $st = $db->prepare('SELECT COUNT(*) FROM member_marketplace_listings WHERE status=?');
            $st->execute([$k]);
            $counts[$k] = (int) $st->fetchColumn();
        }
    }
    if ($tab === 'all') {
        $rows = $db->query(
            "SELECT l.*, m.name AS member_name
             FROM member_marketplace_listings l
             LEFT JOIN members m ON m.id = l.member_id
             ORDER BY FIELD(l.status,'pending','approved','rejected','expired','withdrawn'), l.id DESC LIMIT 400"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $st = $db->prepare(
            "SELECT l.*, m.name AS member_name
             FROM member_marketplace_listings l
             LEFT JOIN members m ON m.id = l.member_id
             WHERE l.status=? ORDER BY l.id DESC LIMIT 400"
        );
        $st->execute([$tab]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $rows = [];
}

$viewId = (int) ($_GET['view'] ?? 0);
$detail = ($viewId > 0 && $db instanceof PDO) ? mpFetchListingById($db, $viewId) : null;
if ($detail && $db instanceof PDO) {
    try {
        $mn = $db->prepare('SELECT name FROM members WHERE id = ? LIMIT 1');
        $mn->execute([(int) $detail['member_id']]);
        $detail['member_name'] = (string) ($mn->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $detail['member_name'] = '';
    }
}
$detailInquiries = [];
if ($detail && $db instanceof PDO) {
    try {
        $st = $db->prepare('SELECT * FROM member_marketplace_inquiries WHERE listing_id=? ORDER BY id DESC LIMIT 50');
        $st->execute([(int) $detail['id']]);
        $detailInquiries = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $detailInquiries = [];
    }
}

echo adminPageHeader(
    'सदस्य बजार / सीप',
    'fa-store',
    'सदस्यले पेश गरेका उत्पादन र सीप सूची — स्वीकृत भएपछि मात्र सार्वजनिक थप मेनुमा देखिन्छ।',
    '<span class="badge admin-stat-badge bg-warning-subtle text-warning border border-warning border-opacity-25 me-2"><i class="fas fa-clock me-1"></i>पेन्डिङ: ' . (int) $counts['pending'] . '</span>'
    . '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . (int) $counts['all'] . '</span>'
);
$_flash = getFlash();
if ($_flash) {
    echo adminAlert($_flash['type'], $_flash['message']);
}
if ($error) {
    echo adminAlert('danger', $error);
}
?>

<?php if ($detail): ?>
<div class="card admin-table-card mb-3">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
        <span class="fw-semibold"><i class="fas fa-store me-2"></i><?php echo htmlspecialchars((string) $detail['title']); ?></span>
        <a href="member-marketplace.php?tab=<?php echo htmlspecialchars($tab); ?>" class="btn btn-sm btn-outline-secondary">सूचीमा फर्कनुहोस्</a>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <?php $img = mpListingImageUrl($detail); ?>
                <?php if ($img !== ''): ?>
                    <img src="<?php echo htmlspecialchars($img); ?>" alt="" class="img-fluid rounded border" style="max-height:280px;object-fit:cover;width:100%">
                <?php endif; ?>
                <?php $st = mpStatusMeta((string) $detail['status']); ?>
                <p class="mt-2 mb-0">
                    <span class="badge bg-<?php echo htmlspecialchars($st['class']); ?>"><?php echo htmlspecialchars($st['np']); ?></span>
                </p>
            </div>
            <div class="col-md-7">
                <table class="table table-sm">
                    <tr><th>प्रकार</th><td><?php echo htmlspecialchars(mpTypeLabel((string) $detail['listing_type'])); ?></td></tr>
                    <tr><th>वर्ग</th><td><?php echo htmlspecialchars(mpCategoryLabel((string) $detail['listing_type'], (string) $detail['category'])); ?></td></tr>
                    <tr><th>मूल्य</th><td><?php echo htmlspecialchars(mpPriceDisplay($detail)); ?></td></tr>
                    <tr><th>परिमाण</th><td><?php echo htmlspecialchars((string) ($detail['quantity'] ?: '—')); ?></td></tr>
                    <tr><th>अनुभव</th><td><?php echo $detail['experience_years'] !== null && $detail['experience_years'] !== '' ? ((int) $detail['experience_years'] . ' वर्ष') : '—'; ?></td></tr>
                    <tr><th>ठाउँ</th><td><?php echo htmlspecialchars((string) ($detail['location'] ?: '—')); ?></td></tr>
                    <tr><th>सम्पर्क</th><td><?php echo htmlspecialchars((string) $detail['contact_name']); ?> · <?php echo htmlspecialchars((string) $detail['contact_phone']); ?></td></tr>
                    <tr><th>सदस्य</th><td><?php echo htmlspecialchars(trim((string) ($detail['member_name'] ?? '')) !== '' ? (string) $detail['member_name'] : ('#' . (int) $detail['member_id'])); ?></td></tr>
                    <tr><th>सदस्य ID</th><td>#<?php echo (int) $detail['member_id']; ?></td></tr>
                    <tr><th>उपलब्ध</th><td><?php echo htmlspecialchars((string) ($detail['available_from'] ?: '—')); ?> → <?php echo htmlspecialchars((string) ($detail['available_until'] ?: '—')); ?></td></tr>
                    <tr><th>दैनिक समय</th><td><?php echo htmlspecialchars(substr((string) ($detail['available_time_from'] ?? ''), 0, 5) ?: '—'); ?>–<?php echo htmlspecialchars(substr((string) ($detail['available_time_to'] ?? ''), 0, 5) ?: '—'); ?></td></tr>
                    <tr><th>विवरण</th><td><?php echo nl2br(htmlspecialchars((string) ($detail['description'] ?: '—'))); ?></td></tr>
                </table>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ((string) $detail['status'] !== 'approved'): ?>
                    <?php if (mpIsApproveEligible($detail)): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="id" value="<?php echo (int) $detail['id']; ?>">
                        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i>स्वीकृत / सार्वजनिक</button>
                    </form>
                    <?php else: ?>
                    <span class="badge bg-secondary">उपलब्ध समय सकिसकेको — स्वीकृत गर्न मिल्दैन</span>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ((string) $detail['status'] !== 'rejected'): ?>
                    <form method="post" class="d-flex gap-2 flex-wrap">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="id" value="<?php echo (int) $detail['id']; ?>">
                        <input type="text" name="admin_note" class="form-control form-control-sm" placeholder="अस्वीकृत कारण (ऐच्छिक)" maxlength="500" style="min-width:200px">
                        <button type="submit" class="btn btn-danger btn-sm">अस्वीकृत</button>
                    </form>
                    <?php endif; ?>
                    <?php if ((string) $detail['status'] === 'approved'): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="expire">
                        <input type="hidden" name="id" value="<?php echo (int) $detail['id']; ?>">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">सार्वजनिकबाट हटाउनुहोस्</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('मेट्ने हो?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int) $detail['id']; ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">मेटाउनुहोस्</button>
                    </form>
                    <a class="btn btn-outline-primary btn-sm" href="<?php echo htmlspecialchars(mpPublicPageUrl((string) $detail['listing_type'], (int) $detail['id'])); ?>" target="_blank" rel="noopener">सार्वजनिक पूर्वावलोकन</a>
                </div>
            </div>
        </div>
        <?php if ($detailInquiries !== []): ?>
        <hr>
        <h6>चासो सन्देशहरू</h6>
        <ul class="list-unstyled mb-0">
            <?php foreach ($detailInquiries as $iq): ?>
                <li class="border rounded p-2 mb-2">
                    <strong><?php echo htmlspecialchars((string) $iq['inquirer_name']); ?></strong>
                    · <?php echo htmlspecialchars((string) $iq['inquirer_phone']); ?>
                    <div class="small text-muted"><?php echo htmlspecialchars((string) $iq['created_at']); ?></div>
                    <div><?php echo nl2br(htmlspecialchars((string) $iq['message'])); ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>

<div class="stat-mini-row no-print">
    <?php foreach (['pending' => 'विचाराधीन', 'approved' => 'स्वीकृत', 'rejected' => 'अस्वीकृत', 'expired' => 'अवधि सकियो', 'withdrawn' => 'फिर्ता', 'all' => 'सबै'] as $tk => $tl): ?>
    <a href="?tab=<?php echo $tk; ?>" class="stat-mini <?php echo $tab === $tk ? 'active-filter' : ''; ?>">
        <div class="sm-val"><?php echo (int) ($counts[$tk] ?? 0); ?></div>
        <div class="sm-lbl"><?php echo $tl; ?></div>
    </a>
    <?php endforeach; ?>
</div>

<div class="card admin-table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 admin-table">
                <thead>
                    <tr>
                        <th>शीर्षक</th>
                        <th>सदस्य</th>
                        <th>प्रकार</th>
                        <th>मूल्य</th>
                        <th>सम्पर्क</th>
                        <th>सम्म</th>
                        <th>स्थिति</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <?php echo adminEmptyRow(8, 'सूची छैन।'); ?>
                <?php else: foreach ($rows as $r):
                    $st = mpStatusMeta((string) $r['status']);
                ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars((string) $r['title']); ?></strong>
                            <div class="small text-muted"><?php echo htmlspecialchars(mpCategoryLabel((string) $r['listing_type'], (string) $r['category'])); ?></div>
                        </td>
                        <td class="small"><?php echo htmlspecialchars(trim((string) ($r['member_name'] ?? '')) !== '' ? (string) $r['member_name'] : ('#' . (int) $r['member_id'])); ?></td>
                        <td><?php echo htmlspecialchars(mpTypeLabel((string) $r['listing_type'])); ?></td>
                        <td><?php echo htmlspecialchars(mpPriceDisplay($r)); ?></td>
                        <td><?php echo htmlspecialchars((string) $r['contact_name']); ?><br><span class="small"><?php echo htmlspecialchars((string) $r['contact_phone']); ?></span></td>
                        <td class="small"><?php echo htmlspecialchars((string) ($r['available_until'] ?: '—')); ?></td>
                        <td><span class="badge bg-<?php echo htmlspecialchars($st['class']); ?>"><?php echo htmlspecialchars($st['np']); ?></span></td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="?view=<?php echo (int) $r['id']; ?>&tab=<?php echo htmlspecialchars($tab); ?>">हेर्नुहोस्</a>
                            <?php if ((string) $r['status'] === 'pending' && mpIsApproveEligible($r)): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                <button class="btn btn-sm btn-success" type="submit">स्वीकृत</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
