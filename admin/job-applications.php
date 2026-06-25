<?php
$pageTitle = 'आवेदनहरू व्यवस्थापन';
require_once 'includes/admin-header.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/../includes/request-status-history.php';
require_once __DIR__ . '/includes/admin-request-view.php';
/* RBAC: staff hercha matra; mutate admin+ matra */
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_role('admin');

$db = getDB();
ensureRequestStatusHistoryTable($db);
$jobAppStatuses = ['pending', 'shortlisted', 'interviewed', 'selected', 'rejected'];

/* पुरानो DB compatibility: job_applications.is_read column नहुन सक्छ */
$hasIsRead = false;
try {
    $colChk = $db->query("SHOW COLUMNS FROM job_applications LIKE 'is_read'");
    $hasIsRead = $colChk && $colChk->fetch() !== false;
} catch (Exception $e) {}

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_status') {
            $id = (int)$_POST['id'];
            $status = $_POST['status'] ?? 'pending';
            if (!in_array($status, $jobAppStatuses, true)) {
                $status = 'pending';
            }
            $notes = $_POST['admin_notes'] ?? '';
            $oldStatus = '';
            try {
                $oldSt = $db->prepare("SELECT status FROM job_applications WHERE id=? LIMIT 1");
                $oldSt->execute([$id]);
                $oldStatus = (string)($oldSt->fetchColumn() ?: '');
            } catch (Exception $e) {}

            /* Admin ले SMS/Email पठाउने option choose गर्‍यो? */
            $notifyOptIn = !empty($_POST['notify_member']) && $_POST['notify_member'] === '1';
            $notifyOutcome = [
                'admin_chose' => $notifyOptIn,
                'email' => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
                'sms'   => ['status' => 'not_attempted', 'reason' => '', 'to' => ''],
            ];

            if ($hasIsRead) {
                $stmt = $db->prepare("UPDATE job_applications SET status = ?, admin_notes = ?, is_read = 1 WHERE id = ?");
                $stmt->execute([$status, $notes, $id]);
            } else {
                $stmt = $db->prepare("UPDATE job_applications SET status = ?, admin_notes = ? WHERE id = ?");
                $stmt->execute([$status, $notes, $id]);
            }

            /* Member portal notification — outcome capture, channel-wise */
            try {
                $nr = $db->prepare("SELECT full_name, email, phone FROM job_applications WHERE id=?");
                $nr->execute([$id]); $nd = $nr->fetch();
                if ($nd && function_exists('sendMemberStatusUpdate')) {
                    $r = sendMemberStatusUpdate(
                        'job',
                        $nd['email']??'', $nd['phone']??'', $nd['full_name']??'',
                        $status, $notes, '',
                        /* forceSkip if admin opted out */ !$notifyOptIn
                    );
                    if (is_array($r)) {
                        $notifyOutcome['email'] = $r['email'] ?? $notifyOutcome['email'];
                        $notifyOutcome['sms']   = $r['sms']   ?? $notifyOutcome['sms'];
                    }
                }
            } catch (Exception $ex) {
                error_log('[job-applications notify] '.$ex->getMessage());
            }

            $notifySent = ($notifyOutcome['email']['status'] === 'sent') || ($notifyOutcome['sms']['status'] === 'sent');
            try {
                logRequestStatusHistory(
                    $db,
                    'job_application',
                    $id,
                    $oldStatus !== '' ? $oldStatus : null,
                    $status,
                    (string)$notes,
                    $notifySent,
                    (int)($_SESSION['admin_id'] ?? 0),
                    (string)($_SESSION['admin_name'] ?? 'Admin'),
                    $notifyOutcome
                );
            } catch (Exception $e) {}

            /* Flash message — notification outcome reflect गर्ने */
            $flashMsg = 'आवेदन स्थिति अपडेट भयो।';
            if ($notifyOptIn) {
                $emails = $notifyOutcome['email']['status'];
                $smses  = $notifyOutcome['sms']['status'];
                if ($emails === 'sent' || $smses === 'sent') {
                    $bits = [];
                    if ($emails === 'sent') $bits[] = 'Email';
                    if ($smses  === 'sent') $bits[] = 'SMS';
                    $flashMsg .= ' ' . implode(' + ', $bits) . ' सूचना पठाइयो।';
                } else {
                    $flashMsg .= ' तर सूचना पठाउन सकिएन (जाँच: settings/email/SMS gateway)।';
                }
            }
            setFlash('success', $flashMsg);
        } elseif ($action === 'delete') {
            $id = (int)$_POST['id'];
            $db->prepare("DELETE FROM job_applications WHERE id = ?")->execute([$id]);
            setFlash('success', 'आवेदन मेटाइयो।');
        } elseif ($action === 'mark_read' && $hasIsRead) {
            $id = (int)$_POST['id'];
            $db->prepare("UPDATE job_applications SET is_read = 1 WHERE id = ?")->execute([$id]);
        }
    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।');
    }

    $redirectQs = [];
    if (isset($_GET['view'])) {
        $redirectQs['view'] = (int)$_GET['view'];
    } elseif (!empty($_POST['id']) && $action === 'update_status') {
        $redirectQs['view'] = (int)$_POST['id'];
    }
    if (isset($_GET['career_id'])) {
        $redirectQs['career_id'] = (int)$_GET['career_id'];
    }
    redirect('job-applications.php' . ($redirectQs ? '?' . http_build_query($redirectQs) : ''));
}

// Filter by career/job
$careerId = isset($_GET['career_id']) ? (int)$_GET['career_id'] : 0;
$statusFilter = $_GET['status'] ?? '';
if ($statusFilter !== '' && !in_array($statusFilter, $jobAppStatuses, true)) {
    $statusFilter = '';
}
$jobSearch = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');

/* ── Bucket: सक्रिय (कारबाही चाहिने) vs टुङ्गिएका vs सबै ── */
$jobActiveStatuses = ['pending', 'shortlisted', 'interviewed'];
$jobDoneStatuses   = ['selected', 'rejected'];
$bucket = $_GET['bucket'] ?? '';
if (!in_array($bucket, ['active', 'done', 'all'], true)) {
    /* explicit status filter set भए सबै देखाउने, नभए default = active */
    $bucket = $statusFilter !== '' ? 'all' : 'active';
}

// Build query
$query = "SELECT ja.*, c.title as job_title, c.deadline
          FROM job_applications ja
          LEFT JOIN careers c ON ja.career_id = c.id
          WHERE 1=1";
$params = [];

if ($careerId) {
    $query .= " AND ja.career_id = ?";
    $params[] = $careerId;
}

if ($statusFilter) {
    $query .= " AND ja.status = ?";
    $params[] = $statusFilter;
} elseif ($bucket === 'active') {
    $ph = implode(',', array_fill(0, count($jobActiveStatuses), '?'));
    $query .= " AND ja.status IN ($ph)";
    $params = array_merge($params, $jobActiveStatuses);
} elseif ($bucket === 'done') {
    $ph = implode(',', array_fill(0, count($jobDoneStatuses), '?'));
    $query .= " AND ja.status IN ($ph)";
    $params = array_merge($params, $jobDoneStatuses);
}

if ($jobSearch !== '') {
    $query .= " AND (ja.full_name LIKE ? OR ja.email LIKE ? OR ja.phone LIKE ?)";
    $jt = "%$jobSearch%"; $params = array_merge($params, [$jt,$jt,$jt]);
}

/* Smart sort: pending पहिले, अनि नयाँ-अनुसार */
$query .= " ORDER BY FIELD(ja.status, 'pending', 'shortlisted', 'interviewed', 'selected', 'rejected'), ja.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$applications = $stmt->fetchAll();

// Get all careers for filter dropdown
$careers = $db->query("SELECT id, title FROM careers ORDER BY created_at DESC")->fetchAll();

// Get statistics
if ($hasIsRead) {
    $stats = $db->query("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
        SUM(CASE WHEN status = 'interviewed' THEN 1 ELSE 0 END) as interviewed,
        SUM(CASE WHEN status = 'selected' THEN 1 ELSE 0 END) as selected,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread
        FROM job_applications")->fetch();
} else {
    $stats = $db->query("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'shortlisted' THEN 1 ELSE 0 END) as shortlisted,
        SUM(CASE WHEN status = 'interviewed' THEN 1 ELSE 0 END) as interviewed,
        SUM(CASE WHEN status = 'selected' THEN 1 ELSE 0 END) as selected,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        0 as unread
        FROM job_applications")->fetch();
}
$bucketCounts = [
    'active' => (int)($stats['pending'] ?? 0) + (int)($stats['shortlisted'] ?? 0) + (int)($stats['interviewed'] ?? 0),
    'done'   => (int)($stats['selected'] ?? 0) + (int)($stats['rejected'] ?? 0),
    'all'    => (int)($stats['total'] ?? 0),
];

// View single application
$viewApplication = null;
if (isset($_GET['view'])) {
    $viewId = (int) $_GET['view'];
    $stmt = $db->prepare("SELECT ja.*, c.title as job_title, c.title_np as job_title_np, c.deadline, c.department
                          FROM job_applications ja
                          LEFT JOIN careers c ON ja.career_id = c.id
                          WHERE ja.id = ?");
    $stmt->execute([$viewId]);
    $viewApplication = $stmt->fetch();

    // Mark as read
    if ($hasIsRead && $viewApplication && !$viewApplication['is_read']) {
        $db->prepare("UPDATE job_applications SET is_read = 1 WHERE id = ?")->execute([$viewId]);
    }
}
$jobHistory = [];
if ($viewApplication && !empty($viewApplication['id'])) {
    try {
        $jobHistory = fetchRequestStatusHistory($db, 'job_application', (int)$viewApplication['id'], 40);
    } catch (Exception $e) {
        $jobHistory = [];
    }
}
?>

<?php echo adminPageHeader('जागिर आवेदन व्यवस्थापन', 'fa-file-alt',
    'आउनुभएका जागिर आवेदनहरू',
    adminStatLink('?status=pending',     'warning',   'पेन्डिङ',       $stats['pending']     ?? 0) . ' ' .
    adminStatLink('?status=shortlisted', 'info',      'छनोट',          $stats['shortlisted'] ?? 0) . ' ' .
    adminStatLink('?status=selected',    'success',   'चयन',           $stats['selected']    ?? 0) . ' ' .
    adminStatLink('?status=rejected',    'danger',    'अस्वीकृत',      $stats['rejected']    ?? 0)
); ?>
<?php if ($flash = getFlash()): ?>
<div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3"><i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="container-fluid py-4">
    <!-- ── Stat Mini Row ── -->
    <div class="stat-mini-row no-print">
        <a href="job-applications.php" class="stat-mini <?php echo !$statusFilter&&!$careerId?'active-filter':''; ?>">
            <div class="sm-icon ic-total"><i class="fas fa-file-alt"></i></div>
            <div class="sm-val"><?php echo $stats['total'] ?? 0; ?></div>
            <div class="sm-lbl">जम्मा</div>
        </a>
        <a href="?bucket=all&amp;status=pending" class="stat-mini <?php echo $statusFilter==='pending'?'active-filter':''; ?>">
            <div class="sm-icon ic-pending"><i class="fas fa-clock"></i></div>
            <div class="sm-val"><?php echo $stats['pending'] ?? 0; ?></div>
            <div class="sm-lbl">पेन्डिङ</div>
        </a>
        <a href="?bucket=all&amp;status=shortlisted" class="stat-mini <?php echo $statusFilter==='shortlisted'?'active-filter':''; ?>">
            <div class="sm-icon ic-process"><i class="fas fa-list-check"></i></div>
            <div class="sm-val"><?php echo $stats['shortlisted'] ?? 0; ?></div>
            <div class="sm-lbl">छनोट</div>
        </a>
        <a href="?bucket=all&amp;status=interviewed" class="stat-mini <?php echo $statusFilter==='interviewed'?'active-filter':''; ?>">
            <div class="sm-icon job-icon-interview-bg"><i class="fas fa-comments job-icon-interview-fg"></i></div>
            <div class="sm-val"><?php echo $stats['interviewed'] ?? 0; ?></div>
            <div class="sm-lbl">अन्तर्वार्ता</div>
        </a>
        <a href="?bucket=all&amp;status=selected" class="stat-mini <?php echo $statusFilter==='selected'?'active-filter':''; ?>">
            <div class="sm-icon ic-approved"><i class="fas fa-user-check"></i></div>
            <div class="sm-val"><?php echo $stats['selected'] ?? 0; ?></div>
            <div class="sm-lbl">चयन</div>
        </a>
        <a href="?bucket=all&amp;status=rejected" class="stat-mini <?php echo $statusFilter==='rejected'?'active-filter':''; ?>">
            <div class="sm-icon ic-rejected"><i class="fas fa-times-circle"></i></div>
            <div class="sm-val"><?php echo $stats['rejected'] ?? 0; ?></div>
            <div class="sm-lbl">अस्वीकृत</div>
        </a>
    </div>

    <?php if ($viewApplication): ?>
    <?php
    /* ── Unified Request Detail View ────────────────────────────── */
    $jobBack = 'job-applications.php' . ($careerId ? '?career_id=' . $careerId : '');

    /* Tab 1: Overview — personal + role + cover letter */
    $overviewHtml  = '<div class="row g-3">';
    $overviewHtml .= '<div class="col-md-6"><div class="arv-section"><h3 class="arv-section-title"><i class="fas fa-user"></i> व्यक्तिगत जानकारी</h3>';
    $overviewHtml .= arvKvTable([
        'पूरा नाम'   => htmlspecialchars($viewApplication['full_name'] ?? ''),
        'इमेल'        => $viewApplication['email']
                          ? '<a href="mailto:' . htmlspecialchars($viewApplication['email'], ENT_QUOTES) . '">' . htmlspecialchars($viewApplication['email']) . '</a>'
                          : '',
        'फोन'         => $viewApplication['phone']
                          ? '<a href="tel:' . htmlspecialchars($viewApplication['phone'], ENT_QUOTES) . '">' . htmlspecialchars($viewApplication['phone']) . '</a>'
                          : '',
        'ठेगाना'      => htmlspecialchars((string)($viewApplication['address'] ?? '')),
        'जन्म मिति'  => htmlspecialchars((string)($viewApplication['date_of_birth'] ?? '')),
        'लिङ्ग'       => $viewApplication['gender'] ? htmlspecialchars(ucfirst((string)$viewApplication['gender'])) : '',
    ]);
    $overviewHtml .= '</div></div>';

    $overviewHtml .= '<div class="col-md-6"><div class="arv-section"><h3 class="arv-section-title"><i class="fas fa-graduation-cap"></i> शिक्षा र अनुभव</h3>';
    $overviewHtml .= arvKvTable([
        'शिक्षा'                => htmlspecialchars((string)($viewApplication['education']         ?? '')),
        'अनुभव'                 => htmlspecialchars((string)($viewApplication['experience']        ?? '')),
        'हालको रोजगारदाता' => htmlspecialchars((string)($viewApplication['current_employer'] ?? '')),
        'अपेक्षित तलब'       => htmlspecialchars((string)($viewApplication['expected_salary']  ?? '')),
        'आवेदन मिति'         => formatNepaliDate($viewApplication['created_at'], true),
    ]);
    $overviewHtml .= '</div></div></div>';

    if (!empty($viewApplication['cover_letter'])) {
        $overviewHtml .= '<div class="arv-section"><h3 class="arv-section-title"><i class="fas fa-envelope-open-text"></i> आवेदन पत्र / Cover Letter</h3>';
        $overviewHtml .= '<div class="arv-text-block">' . nl2br(htmlspecialchars((string)$viewApplication['cover_letter'])) . '</div></div>';
    }

    /* Tab 2: Documents */
    $docsHtml = arvDocsGrid([
        ['url' => !empty($viewApplication['resume_path'])       ? SITE_URL . $viewApplication['resume_path']       : '', 'label' => 'Resume / CV',    'icon' => 'fa-file-pdf'],
        ['url' => !empty($viewApplication['photo_path'])        ? SITE_URL . $viewApplication['photo_path']        : '', 'label' => 'फोटो',           'icon' => 'fa-image'],
        ['url' => !empty($viewApplication['citizenship_path'])  ? SITE_URL . $viewApplication['citizenship_path']  : '', 'label' => 'नागरिकता',     'icon' => 'fa-id-card'],
        ['url' => !empty($viewApplication['certificates_path']) ? SITE_URL . $viewApplication['certificates_path'] : '', 'label' => 'प्रमाणपत्र', 'icon' => 'fa-certificate'],
    ]);

    /* Tab 3: Activity Log */
    $logHtml = arvLogList($jobHistory);

    /* Sidebar: applied position + status update form */
    ob_start(); ?>
    <div class="arv-action-card">
        <h4 class="arv-action-title"><i class="fas fa-briefcase"></i> आवेदित पद</h4>
        <div class="arv-meta-list">
            <div><b><?php echo htmlspecialchars((string)($viewApplication['job_title'] ?? 'N/A')); ?></b></div>
            <div><i class="fas fa-building"></i> <?php echo htmlspecialchars((string)($viewApplication['department'] ?? 'General')); ?></div>
            <?php if (!empty($viewApplication['deadline'])): ?>
            <div><i class="fas fa-calendar"></i> म्याद: <?php echo htmlspecialchars((string)$viewApplication['deadline']); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="arv-action-card">
        <h4 class="arv-action-title"><i class="fas fa-pen-to-square"></i> स्थिति अपडेट</h4>
        <form method="POST">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?php echo (int)$viewApplication['id']; ?>">
            <div class="mb-3">
                <label class="form-label">स्थिति</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="pending"     <?php echo $viewApplication['status']==='pending'    ?'selected':''; ?>>⏳ पेन्डिङ</option>
                    <option value="shortlisted" <?php echo $viewApplication['status']==='shortlisted'?'selected':''; ?>>📋 छनोट</option>
                    <option value="interviewed" <?php echo $viewApplication['status']==='interviewed'?'selected':''; ?>>💬 अन्तर्वार्ता</option>
                    <option value="selected"    <?php echo $viewApplication['status']==='selected'   ?'selected':''; ?>>✅ चयन</option>
                    <option value="rejected"    <?php echo $viewApplication['status']==='rejected'   ?'selected':''; ?>>❌ अस्वीकृत</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">नोट / टिप्पणी</label>
                <textarea name="admin_notes" class="form-control form-control-sm" rows="3"
                    placeholder="(वैकल्पिक) यो स्थिति परिवर्तनको कारण..."><?php echo htmlspecialchars((string)($viewApplication['admin_notes'] ?? '')); ?></textarea>
            </div>
            <?php
            $hasEmail = !empty($viewApplication['email']);
            $hasPhone = !empty($viewApplication['phone']);
            ?>
            <div class="arv-notify-row mb-3">
                <label class="arv-notify-toggle">
                    <input type="checkbox" name="notify_member" value="1" <?php echo ($hasEmail || $hasPhone) ? 'checked' : ''; ?>>
                    <span><i class="fas fa-paper-plane"></i> Member लाई SMS/Email पठाउनुहोस्</span>
                </label>
                <div class="arv-notify-channels">
                    <span class="<?php echo $hasEmail ? 'is-on' : 'is-off'; ?>">
                        <i class="fas fa-envelope"></i> Email <?php echo $hasEmail ? '✓' : '—'; ?>
                    </span>
                    <span class="<?php echo $hasPhone ? 'is-on' : 'is-off'; ?>">
                        <i class="fas fa-mobile-screen"></i> SMS <?php echo $hasPhone ? '✓' : '—'; ?>
                    </span>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="fas fa-save"></i> अपडेट गर्नुहोस्
            </button>
        </form>
    </div>
    <?php $sidebarHtml = (string)ob_get_clean();

    $jobStatusMap = [
        'pending'     => ['warning', 'पेन्डिङ'],
        'shortlisted' => ['info',    'छनोट'],
        'interviewed' => ['secondary','अन्तर्वार्ता'],
        'selected'    => ['success', 'चयन'],
        'rejected'    => ['danger',  'अस्वीकृत'],
    ];

    echo renderAdminRequestView([
        'title'      => $viewApplication['full_name'] ?? '—',
        'subtitle'   => 'पद: <b>' . htmlspecialchars((string)($viewApplication['job_title'] ?? 'N/A')) . '</b>'
                      . ' · <i class="fas fa-clock"></i> ' . formatNepaliDate($viewApplication['created_at']),
        'status'     => (string)($viewApplication['status'] ?? ''),
        'statusMap'  => $jobStatusMap,
        'backUrl'    => $jobBack,
        'avatarIcon' => 'user-tie',
        'tabs'       => [
            ['id'=>'overview','label'=>'अवलोकन',     'icon'=>'fa-circle-info',         'html'=>$overviewHtml],
            ['id'=>'docs',    'label'=>'कागजात',     'icon'=>'fa-folder-open',         'html'=>$docsHtml],
            ['id'=>'log',     'label'=>'गतिविधि लग','icon'=>'fa-clock-rotate-left',   'html'=>$logHtml],
        ],
        'sidebar'    => $sidebarHtml,
    ]);
    ?>
    <?php else: ?>
    <!-- ── Bucket Tabs (Active / Done / All) ── -->
    <?php
    $jobBucketBaseQs = [];
    if ($careerId)              $jobBucketBaseQs['career_id'] = $careerId;
    if ($jobSearch !== '')      $jobBucketBaseQs['search']    = $jobSearch;
    /* explicit status filter clears when switching bucket — bucket sets its own scope */
    $jobBucketUrl = static function (string $b) use ($jobBucketBaseQs): string {
        $qs = $jobBucketBaseQs + ['bucket' => $b];
        return 'job-applications.php?' . http_build_query($qs);
    };
    ?>
    <div class="job-bucket-bar no-print">
        <a href="<?php echo htmlspecialchars($jobBucketUrl('active')); ?>" class="job-bucket job-bucket--active <?php echo $bucket==='active'?'is-on':''; ?>">
            <i class="fas fa-bolt"></i> सक्रिय आवेदन
            <span class="job-bucket-count"><?php echo (int)$bucketCounts['active']; ?></span>
        </a>
        <a href="<?php echo htmlspecialchars($jobBucketUrl('done')); ?>" class="job-bucket job-bucket--done <?php echo $bucket==='done'?'is-on':''; ?>">
            <i class="fas fa-check-double"></i> टुङ्गिएका
            <span class="job-bucket-count"><?php echo (int)$bucketCounts['done']; ?></span>
        </a>
        <a href="<?php echo htmlspecialchars($jobBucketUrl('all')); ?>" class="job-bucket job-bucket--all <?php echo $bucket==='all'?'is-on':''; ?>">
            <i class="fas fa-list"></i> सबै
            <span class="job-bucket-count"><?php echo (int)$bucketCounts['all']; ?></span>
        </a>
    </div>
    <!-- ── Applications Filter Bar ── -->
    <div class="adm-filter-bar no-print">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="bucket" value="<?php echo htmlspecialchars($bucket); ?>">
            <div class="col-md-3 col-6">
                <label>पद</label>
                <select name="career_id" class="form-select form-select-sm" onchange="this.closest('form').submit()">
                    <option value="">सबै पदहरू</option>
                    <?php foreach ($careers as $career): ?>
                    <option value="<?php echo $career['id']; ?>" <?php echo $careerId==$career['id']?'selected':''; ?>><?php echo htmlspecialchars($career['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 col-6">
                <label>स्थिति</label>
                <select name="status" class="form-select form-select-sm" onchange="this.closest('form').submit()">
                    <option value="">सबै स्थिति</option>
                    <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>⏳ पेन्डिङ</option>
                    <option value="shortlisted" <?php echo $statusFilter==='shortlisted'?'selected':''; ?>>📋 छनोट</option>
                    <option value="interviewed" <?php echo $statusFilter==='interviewed'?'selected':''; ?>>💬 अन्तर्वार्ता</option>
                    <option value="selected" <?php echo $statusFilter==='selected'?'selected':''; ?>>✅ चयन</option>
                    <option value="rejected" <?php echo $statusFilter==='rejected'?'selected':''; ?>>❌ अस्वीकृत</option>
                </select>
            </div>
            <div class="col-md-5 col-12">
                <label>खोज्नुहोस्</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($jobSearch); ?>" placeholder="नाम, फोन, इमेल...">
                </div>
            </div>
            <div class="col-md-2 col-6">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search me-1"></i>खोज</button>
                <?php if ($careerId||$statusFilter||$jobSearch !== ''): ?><a href="<?php echo htmlspecialchars($jobBucketUrl($bucket)); ?>" class="btn btn-outline-secondary btn-sm w-100 mt-1"><i class="fas fa-times me-1"></i>हटाउनुहोस्</a><?php endif; ?>
            </div>
        </form>
    </div>
    <!-- ── Applications List ── -->
    <?php
    $bucketLabel = ['active'=>'सक्रिय आवेदन', 'done'=>'टुङ्गिएका', 'all'=>'सबै आवेदन'][$bucket] ?? 'सबै आवेदन';
    ?>
    <div class="card border-0 shadow-sm app-rounded-card">
        <div class="tbl-header-bar no-print">
            <h6><i class="fas fa-briefcase me-2 text-primary"></i>रोजगार आवेदन सूची <small class="text-muted ms-2 fw-normal">— <?php echo htmlspecialchars($bucketLabel); ?></small></h6>
            <span class="result-count-badge"><?php echo count($applications); ?> आवेदन</span>
        </div>
        <div class="table-responsive admin-table-card">
            <table class="table-hover table app-table align-middle mb-0 table-responsive-stack">
                            <thead>
                                <tr>
                                    <th>आवेदक</th>
                                    <th>पद</th>
                                    <th>सम्पर्क</th>
                                    <th>आवेदन मिति</th>
                                    <th>स्थिति</th>
                                    <th>कार्य</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($applications as $app): ?>
                                <?php $isUnread = $hasIsRead ? ((int)($app['is_read'] ?? 0) === 0) : false; ?>
                                <tr class="<?php echo $isUnread ? 'table-warning' : ''; ?>">
                                    <td>
                                        <?php if ($isUnread): ?>
                                        <span class="badge bg-danger me-1">New</span>
                                        <?php endif; ?>
                                        <strong><?php echo htmlspecialchars($app['full_name']); ?></strong>
                                        <?php if ($app['education']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($app['education']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($app['job_title'] ?? 'N/A'); ?>
                                        <?php if ($app['deadline'] && strtotime($app['deadline']) < time()): ?>
                                        <br><small class="text-danger">म्याद समाप्त</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="mailto:<?php echo $app['email']; ?>" title="Email">
                                            <i class="fas fa-envelope"></i>
                                        </a>
                                        <a href="tel:<?php echo $app['phone']; ?>" title="Phone" class="ms-2">
                                            <i class="fas fa-phone"></i>
                                        </a>
                                        <br><small><?php echo $app['phone']; ?></small>
                                    </td>
                                    <td><?php echo formatNepaliDate($app['created_at']); ?></td>
                                    <td>
                                        <?php
                                        $rowStatus = (string)($app['status'] ?? '');
                                        $rowStatusMap = [
                                            'pending' => 'warning',
                                            'shortlisted' => 'info',
                                            'interviewed' => 'secondary',
                                            'selected' => 'success',
                                            'rejected' => 'danger',
                                        ];
                                        $rowStatusClass = $rowStatusMap[$rowStatus] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $rowStatusClass; ?>">
                                            <?php echo ucfirst($app['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="adm-action-icons">
                                            <a href="?view=<?php echo $app['id']; ?><?php echo $careerId ? '&career_id=' . $careerId : ''; ?>"
                                               class="adm-icon-btn adm-icon-btn--view" title="विवरण हेर्नुहोस्" aria-label="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <form method="POST" class="adm-icon-form"
                                                  onsubmit="return confirm('के तपाईं पक्का हुनुहुन्छ? यो कार्य फिर्ता हुँदैन।')">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $app['id']; ?>">
                                                <button type="submit" class="adm-icon-btn adm-icon-btn--delete" title="मेट्नुहोस्" aria-label="Delete">
                                                    <i class="fas fa-trash-can"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                                        कुनै आवेदन छैन
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
