<?php
/**
 * =====================================================
 * Admin Messages — सम्पर्क सन्देश व्यवस्थापन
 * =====================================================
 */
$pageTitle = 'सम्पर्क सन्देशहरू';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db     = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'view'], true)) {
    $action = 'list';
}

/* ── Delete सन्देश (POST — CSRF protected) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    try {
        $db->prepare("DELETE FROM contact_messages WHERE id=?")->execute([$id]);
        setFlash('success', 'सन्देश सफलतापूर्वक मेटियो।');
    } catch (Exception $e) {
        setFlash('error', 'मेटाउन सकिएन।');
    }
    redirect('messages.php');
}

/* GET delete हटाइयो — POST only (CSRF protected) */

/* ── Mark as read ── */
if (isset($_GET['read']) && is_numeric($_GET['read'])) {
    $id = (int)$_GET['read'];
    try { $db->prepare("UPDATE contact_messages SET is_read=1 WHERE id=?")->execute([$id]); } catch (Exception $e) {}
    redirect('messages.php?action=view&id=' . $id);
}

/* ═══════════════════════════════════════════════════
   Single message view
   ═══════════════════════════════════════════════════ */
if ($action === 'view' && isset($_GET['id'])) {
    $id   = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT * FROM contact_messages WHERE id=?");
    $stmt->execute([$id]);
    $message = $stmt->fetch();

    if (!$message) {
        setFlash('error', 'सन्देश फेला परेन।');
        redirect('messages.php');
    }

    /* Auto-mark as read */
    if (!$message['is_read']) {
        $db->prepare("UPDATE contact_messages SET is_read=1 WHERE id=?")->execute([$id]);
    }

    /* Page header */
    echo adminPageHeader(
        'सन्देश विवरण',
        'fa-envelope-open',
        'प्राप्त सन्देशको पूर्ण जानकारी',
        '<a href="messages.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i>फिर्ता</a>'
    );

    $flash = getFlash(); if ($flash) echo adminAlert($flash['type'], $flash['message']);
    ?>

    <div class="card admin-table-card mb-4 arv-legacy-detail">
        <div class="card-header gradient-card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-envelope-open me-2"></i>
                <?php echo htmlspecialchars($message['name']); ?> को सन्देश
            </h5>
            <span class="badge bg-light text-dark">
                <?php echo formatNepaliDate($message['created_at']); ?>
            </span>
        </div>
        <div class="card-body">
            <!-- सम्पर्क जानकारी -->
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="p-3 rounded-3 msg-info-card msg-info-sender">
                        <div class="text-muted small mb-1"><i class="fas fa-user me-1"></i>प्रेषक</div>
                        <div class="fw-bold"><?php echo htmlspecialchars($message['name']); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="p-3 rounded-3 msg-info-card msg-info-email">
                        <div class="text-muted small mb-1"><i class="fas fa-envelope me-1"></i>इमेल</div>
                        <div class="fw-bold">
                            <a href="mailto:<?php echo htmlspecialchars($message['email']); ?>" class="text-primary text-decoration-none">
                                <?php echo htmlspecialchars($message['email']); ?>
                            </a>
                        </div>
                    </div>
                </div>
                <?php if (!empty($message['phone'])): ?>
                <div class="col-md-4">
                    <div class="p-3 rounded-3 msg-info-card msg-info-phone">
                        <div class="text-muted small mb-1"><i class="fas fa-phone me-1"></i>फोन</div>
                        <div class="fw-bold">
                            <a href="tel:<?php echo htmlspecialchars($message['phone']); ?>" class="text-dark text-decoration-none">
                                <?php echo htmlspecialchars($message['phone']); ?>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($message['subject'])): ?>
            <div class="mb-3 p-3 rounded-3 msg-subject-box">
                <strong class="text-success"><i class="fas fa-tag me-2"></i>विषय:</strong>
                <?php echo htmlspecialchars($message['subject']); ?>
            </div>
            <?php endif; ?>

            <!-- सन्देश body -->
            <div class="p-4 rounded-3 mb-4 msg-body-wrap">
                <h6 class="text-success fw-bold mb-3"><i class="fas fa-comment me-2"></i>सन्देश:</h6>
                <p class="mb-0 msg-body-text">
                    <?php echo nl2br(htmlspecialchars($message['message'])); ?>
                </p>
            </div>

            <!-- Action buttons -->
            <div class="d-flex gap-3 flex-wrap">
                <a href="mailto:<?php echo htmlspecialchars($message['email']); ?>?subject=Re: <?php echo htmlspecialchars($message['subject'] ?? 'Your Message'); ?>"
                   class="btn btn-primary">
                    <i class="fas fa-reply me-1"></i>जवाफ दिनुहोस्
                </a>
                <form method="POST" class="d-inline" onsubmit="return confirm('के तपाईं यो सन्देश स्थायी रूपमा मेट्न चाहनुहुन्छ?')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="delete_id" value="<?php echo (int)$message['id']; ?>">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>मेट्नुहोस्</button>
                </form>
                <a href="messages.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्
                </a>
            </div>
        </div>
    </div>

<?php
} else {
    /* ═══════════════════════════════════════════════════
       सन्देश सूची (list view)
       ═══════════════════════════════════════════════════ */
    $filter = $_GET['filter'] ?? 'all';
    if (!in_array($filter, ['all', 'unread', 'read'], true)) {
        $filter = 'all';
    }

    /* Query — using safe prepared statements via sqWhere() */
    require_once __DIR__ . '/../includes/safe-query.php';
    $msgSearch = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
    $filters = [];
    if ($filter === 'unread') {
        $filters = ['is_read' => 0];
    } elseif ($filter === 'read') {
        $filters = ['is_read' => 1];
    }
    $w = sqWhere($filters, ['name','email','subject','message'], $msgSearch);

    $messages = [];
    $totalCount = $unreadCount = $readCount = 0;
    try {
        $stmt = $db->prepare("SELECT * FROM contact_messages {$w['sql']} ORDER BY created_at DESC");
        $stmt->execute($w['params']);
        $messages = $stmt->fetchAll();

        $totalCount  = core_safe_count($db, "SELECT COUNT(*) FROM contact_messages", '[messages total]');
        $unreadCount = core_safe_count($db, "SELECT COUNT(*) FROM contact_messages WHERE is_read=0", '[messages unread]');
        $readCount   = $totalCount - $unreadCount;
    } catch (Exception $e) {}

    /* Page header */
    echo adminPageHeader(
        'सम्पर्क सन्देशहरू',
        'fa-envelope',
        'आकाश सहकारीमा आएका सम्पर्क सन्देशहरू',
        adminStatLink('?is_read=0', 'danger', 'अपठित', $unreadCount)
        . ' ' . adminStatLink('messages.php', 'secondary', 'जम्मा', $totalCount)
    );

    $flash = getFlash(); if ($flash) echo adminAlert($flash['type'], $flash['message']);

    /* ── Stat Mini Row ── */
    ?>
    <div class="stat-mini-row no-print">
        <a href="messages.php" class="stat-mini <?php echo $filter==='all'?'active-filter':''; ?>">
            <div class="sm-icon ic-total"><i class="fas fa-inbox"></i></div>
            <div class="sm-val"><?php echo $totalCount; ?></div>
            <div class="sm-lbl">जम्मा सन्देश</div>
        </a>
        <a href="messages.php?filter=unread" class="stat-mini <?php echo $filter==='unread'?'active-filter':''; ?>">
            <div class="sm-icon ic-rejected"><i class="fas fa-envelope"></i></div>
            <div class="sm-val"><?php echo $unreadCount; ?></div>
            <div class="sm-lbl">नपढेको</div>
        </a>
        <a href="messages.php?filter=read" class="stat-mini <?php echo $filter==='read'?'active-filter':''; ?>">
            <div class="sm-icon ic-approved"><i class="fas fa-envelope-open"></i></div>
            <div class="sm-val"><?php echo $readCount; ?></div>
            <div class="sm-lbl">पढेको</div>
        </a>
    </div>

    <!-- Filter Bar -->
    <div class="adm-filter-bar no-print">
        <form method="GET" class="adm-filter-form">
            <div class="afb-group">
                <label>स्थिति</label>
                <select name="filter" class="afb-select">
                    <option value="all"    <?php echo $filter==='all'?'selected':''; ?>>सबै</option>
                    <option value="unread" <?php echo $filter==='unread'?'selected':''; ?>>नपढेको</option>
                    <option value="read"   <?php echo $filter==='read'?'selected':''; ?>>पढेको</option>
                </select>
            </div>
            <div class="afb-group afb-search">
                <label>खोज्नुहोस्</label>
                <div class="afb-search-wrap">
                    <i class="fas fa-search afb-search-icon"></i>
                    <input type="text" name="search" class="afb-input"
                           value="<?php echo htmlspecialchars($msgSearch ?? ''); ?>"
                           placeholder="नाम, इमेल, विषय, सन्देश...">
                </div>
            </div>
            <button type="submit" class="afb-btn-search"><i class="fas fa-search me-1"></i>खोज</button>
            <?php if ($filter !== 'all' || !empty($msgSearch)): ?>
            <a href="messages.php" class="afb-btn-reset"><i class="fas fa-times me-1"></i>रिसेट</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ── Messages Table ── -->
    <div class="app-table">
        <div class="tbl-header-bar">
            <span class="tbl-title">
                <i class="fas fa-envelope me-2"></i>सन्देश सूची
                <?php if ($filter !== 'all'): ?>
                <small class="ms-1 opacity-75">(<?php echo $filter==='unread'?'नपढेको':'पढेको'; ?>)</small>
                <?php endif; ?>
            </span>
            <span class="tbl-count"><?php echo count($messages); ?> सन्देश</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3" width="5%">#</th>
                            <th width="18%">नाम</th>
                            <th width="20%">इमेल</th>
                            <th>विषय / सन्देश</th>
                            <th width="120">मिति</th>
                            <th width="100" class="text-center">कार्य</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($messages)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">
                            <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
                            कुनै सन्देश छैन
                        </td></tr>
                    <?php else: foreach ($messages as $i => $msg): ?>
                        <tr class="<?php echo !$msg['is_read'] ? 'fw-semibold' : ''; ?>">
                            <td class="ps-3 text-muted"><?php echo $i + 1; ?></td>
                            <td>
                                <?php if (!$msg['is_read']): ?>
                                <span class="badge bg-danger me-1 msg-badge-xxs">नयाँ</span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($msg['name']); ?>
                            </td>
                            <td class="text-muted small"><?php echo htmlspecialchars($msg['email']); ?></td>
                            <td>
                                <span class="text-truncate d-block msg-text-clamp">
                                    <?php echo htmlspecialchars(mb_substr($msg['subject'] ?? $msg['message'] ?? '', 0, 55)); ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-muted"><?php echo formatNepaliDate($msg['created_at']); ?></small>
                            </td>
                            <td class="text-center">
                                <div class="adm-action-icons">
                                    <a href="messages.php?action=view&id=<?php echo $msg['id']; ?>"
                                       class="adm-icon-btn adm-icon-btn--view" title="हेर्नुहोस्" aria-label="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <form method="POST" class="adm-icon-form" onsubmit="return confirm('सन्देश मेट्ने?')">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="delete_id" value="<?php echo (int)$msg['id']; ?>">
                                        <button type="submit" class="adm-icon-btn adm-icon-btn--delete" title="मेट्नुहोस्" aria-label="Delete"><i class="fas fa-trash-can"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
    </div>

<?php } ?>

<?php require_once 'includes/admin-footer.php'; ?>
