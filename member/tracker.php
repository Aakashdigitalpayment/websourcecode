<?php
/**
 * Member Portal — Application Tracker
 * Timeline view with history
 */
/* v2: bootstrap ले config + member-auth + global error guard load गर्छ */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/request-status-history.php';
requireMemberLogin();
memberSecurityHeaders();

$db       = getDB();
ensureRequestStatusHistoryTable($db);
$mem      = currentMember();
$memEmail = trim((string)($mem['email'] ?? ''));
$memPhone = trim((string)($mem['phone'] ?? ''));
$memberId = $mem['id'];
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

/* KYC-linked source priority (profile/dashboard/id-card जस्तै) */
try {
    $kycRow = null;
    $kycMemberLinkId = (int)($mem['kyc_application_id'] ?? 0);
    if ($kycMemberLinkId > 0) {
        $ks = $db->prepare("SELECT id, email, mobile FROM kyc_applications WHERE id=? LIMIT 1");
        $ks->execute([$kycMemberLinkId]);
        $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$kycRow) {
        $kw = [];
        $kp = [];
        if ($memEmail !== '') { $kw[] = 'LOWER(email)=?'; $kp[] = strtolower($memEmail); }
        if ($memPhone !== '') { $kw[] = 'mobile=?'; $kp[] = preg_replace('/[^0-9]/', '', $memPhone); }
        if (!empty($kw)) {
            $ks = $db->prepare("SELECT id, email, mobile FROM kyc_applications
                                WHERE (" . implode(' OR ', $kw) . ")
                                ORDER BY id DESC LIMIT 1");
            $ks->execute($kp);
            $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($kycRow && empty($mem['kyc_application_id'])) {
                $db->prepare("UPDATE members SET kyc_application_id=? WHERE id=?")
                    ->execute([(int)$kycRow['id'], $memberId]);
            }
        }
    }
    if ($kycRow) {
        if (trim((string)($kycRow['email'] ?? '')) !== '') $memEmail = trim((string)$kycRow['email']);
        if (trim((string)($kycRow['mobile'] ?? '')) !== '') $memPhone = trim((string)$kycRow['mobile']);
    }
} catch (Throwable $e) { /* fail-safe */ }

$unread   = getMemberUnreadCount($memberId);
$apps     = getMemberApplications($memEmail, $memPhone, 100, $memberId);

/* Filter */
$allowedTrackerFilters = ['all', 'appointment', 'kyc', 'loan', 'account', 'grievance', 'welfare', 'job'];
$filter   = $_GET['filter'] ?? 'all';
if (!in_array($filter, $allowedTrackerFilters, true)) {
    $filter = 'all';
}
$q        = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 200, 'UTF-8');
if ($filter !== 'all') {
    $filterMap = ['appointment'=>'appointments','kyc'=>'kyc_applications','loan'=>'loan_applications','account'=>'account_applications','grievance'=>'grievances','welfare'=>'welfare_claims','job'=>'job_applications'];
    $tbl = $filterMap[$filter] ?? null;
    if ($tbl) $apps = array_filter($apps, fn($a) => $a['_table'] === $tbl);
}
if ($q !== '') {
    $qLower = mb_strtolower($q);
    $apps = array_filter($apps, function($a) use ($qLower) {
        $hay = mb_strtolower(
            ($a['service_name'] ?? '') . ' ' .
            ($a['detail'] ?? '') . ' ' .
            ($a['tracking_id'] ?? '') . ' ' .
            ($a['status'] ?? '')
        );
        return strpos($hay, $qLower) !== false;
    });
}

/* Active detail */
$viewId  = isset($_GET['view']) ? (int) $_GET['view'] : 0;
$viewTbl = isset($_GET['tbl']) ? (string) $_GET['tbl'] : '';
$viewApp = null;

if ($viewId > 0 && $viewTbl !== '') {
    $safeTables = ['appointments','kyc_applications','loan_applications','account_applications','grievances','welfare_claims','job_applications'];
    if (in_array($viewTbl, $safeTables, true)) {
        try {
            $st = $db->prepare("SELECT * FROM $viewTbl WHERE id=?");
            $st->execute([$viewId]);
            $viewApp = $st->fetch(PDO::FETCH_ASSOC);
            /* Security: only show if email/phone matches */
            $appEmail = strtolower(trim((string)($viewApp['email'] ?? '')));
            $appPhone = preg_replace('/[^0-9]/', '', (string)($viewApp['phone'] ?? $viewApp['mobile'] ?? ''));
            $myEmail  = strtolower(trim((string)$memEmail));
            $myPhone  = preg_replace('/[^0-9]/', '', (string)$memPhone);
            if ($viewApp && $appEmail !== $myEmail && $appPhone !== $myPhone) {
                $appMemberId = (string)($viewApp['member_id'] ?? '');
                $myId = (string)$memberId;
                if ($appMemberId === '' || $appMemberId !== $myId) {
                    $viewApp = null;
                }
            }
        } catch (Exception $e) { $viewApp = null; }
    }
}

function memberTrackerModuleKey(string $table): ?string {
    return match ($table) {
        'appointments' => 'appointment',
        'kyc_applications' => 'kyc',
        'loan_applications' => 'loan',
        'account_applications' => 'account',
        'grievances' => 'grievance',
        'welfare_claims' => 'welfare',
        'job_applications' => 'job_application',
        default => null,
    };
}
$viewHistory = [];
if ($viewApp && $viewTbl !== '') {
    $moduleKey = memberTrackerModuleKey($viewTbl);
    if ($moduleKey !== null) {
        try {
            $viewHistory = fetchRequestStatusHistory($db, $moduleKey, (int)$viewApp['id'], 40);
        } catch (Exception $e) {
            $viewHistory = [];
        }
    }
}

$siteName = getSetting('site_name', 'आकाश सहकारी');
$siteUrl  = SITE_URL;
$pageTitle = $_t('आवेदन ट्र्याकर', 'Application Tracker') . ' — ' . $siteName;

/* Timeline steps */
function timelineSteps($status) {
    global $_t;
    $steps = [
        ['key' => 'pending',      'label' => $_t('दर्ता', 'Submitted')],
        ['key' => 'under_review', 'label' => $_t('समीक्षा', 'Review')],
        ['key' => 'approved',     'label' => $_t('स्वीकृत', 'Approved')],
        ['key' => 'completed',    'label' => $_t('सम्पन्न', 'Completed')],
    ];
    $rejected = $status === 'rejected';
    $cur = memberStatusSteps($status);
    $html = '<div class="mem-timeline">';
    foreach ($steps as $i => $s) {
        $done   = $cur > $i;
        $active = $cur === $i;
        $cls    = $rejected && $i === 1 ? 'rejected' : ($done ? 'done' : ($active ? 'active' : ''));
        if ($i > 0) $html .= '<div class="mem-step-line ' . ($done || ($active && $i > 0) ? 'done' : '') . '"></div>';
        $html .= '<div class="mem-step ' . $cls . '">';
        $html .= '<div class="mem-step-dot">' . ($done ? '<i class="fas fa-check" style="font-size:.55rem;"></i>' : ($rejected && $i===1 ? '<i class="fas fa-x" style="font-size:.55rem;"></i>' : ($i+1))) . '</div>';
        $html .= '<div class="mem-step-label">' . $s['label'] . '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}
require __DIR__ . '/includes/chrome.php';
?>

    <!-- Detail view -->
    <?php if ($viewApp): ?>
    <div class="mem-card" style="margin-bottom:20px;">
        <div class="mem-card-header">
            <div class="mem-card-title"><i class="fas fa-file-alt"></i><?php echo $_t('आवेदन विवरण', 'Application Details'); ?></div>
            <a href="<?php echo $siteUrl; ?>member/tracker.php" style="font-size:0.8rem;color:var(--mem-primary);font-weight:700;text-decoration:none;">← <?php echo $_t('पछाडि', 'Back'); ?></a>
        </div>
        <div class="mem-card-body">
            <!-- Tracking ID -->
            <?php if (!empty($viewApp['tracking_id'])): ?>
            <div style="margin-bottom:16px;">
                <div style="font-size:0.75rem;color:var(--text-muted);font-weight:700;margin-bottom:4px;">TRACKING ID</div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="mem-tracking-id" id="trkId"><?php echo htmlspecialchars($viewApp['tracking_id']); ?></span>
                    <button onclick="copyTrk('trkId',this)" class="mem-topbar-btn" style="background:var(--primary-color);border:none;font-size:0.72rem;padding:5px 12px;cursor:pointer;">
                        <i class="lucide-icon me-1" aria-hidden="true" data-lucide="copy"></i><?php echo $_t('कपी', 'Copy'); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Timeline -->
            <?php echo timelineSteps($viewApp['status'] ?? 'pending'); ?>
            <?php if (!empty($viewHistory)): ?>
            <div style="margin-top:14px;background:var(--bg-soft);border:1px solid var(--border-soft);border-radius:10px;padding:12px;">
                <div style="font-size:.78rem;font-weight:700;color:var(--text-primary);margin-bottom:8px;">
                    <i class="fas fa-clock-rotate-left me-1"></i><?php echo $_t('स्टाटस/कमेन्ट इतिहास', 'Status/Comment History'); ?>
                </div>
                <?php foreach ($viewHistory as $h): ?>
                <div style="border:1px solid var(--border-soft);border-radius:8px;background:var(--bg-card);padding:8px 10px;margin-bottom:8px;">
                    <div style="font-size:.8rem;font-weight:700;color:var(--text-primary);"><?php echo htmlspecialchars((string)($h['old_status'] ?: '—')); ?> → <?php echo htmlspecialchars((string)($h['new_status'] ?: '—')); ?></div>
                    <?php if (!empty($h['admin_comment'])): ?>
                    <div style="font-size:.8rem;color:var(--text-secondary);margin-top:4px;white-space:pre-wrap;"><?php echo nl2br(htmlspecialchars((string)$h['admin_comment'])); ?></div>
                    <?php endif; ?>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-top:4px;">
                        <?php echo htmlspecialchars((string)($h['actor_name'] ?: 'Admin')); ?> ·
                        <?php echo formatNepaliDate((string)$h['created_at'], true); ?> ·
                        <?php echo !empty($h['notify_sent']) ? ($_t('Notify पठाइयो', 'Notify sent')) : ($_t('Notify छैन', 'No notify')); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php
            /* v10.3 (Issue #4): Admin reply highlight panel — पुरानो version मा
               loop ले admin_response लाई धेरै rows बीच लुकाउँथ्यो। अब reply
               भएमा सबै भन्दा माथि highlighted card मा देखाउँछ ताकि member ले
               तुरुन्तै देख्न सकोस्। */
            $hasReply = !empty($viewApp['admin_response']);
            $hasAttach = !empty($viewApp['admin_attachment']);
            if ($hasReply || $hasAttach):
            ?>
            <div style="margin:14px 0 6px;background:var(--bg-soft);border:1.5px solid var(--primary-light,#2e8b4a);border-radius:12px;padding:14px 16px;">
                <div style="display:flex;align-items:center;gap:8px;font-weight:700;color:var(--primary-dark,#144a21);font-size:.92rem;margin-bottom:8px;">
                    <i class="fas fa-comment-dots"></i> <?php echo $_t('Admin बाट प्रतिक्रिया', 'Response from Admin'); ?>
                    <?php if (!empty($viewApp['resolved_at'])): ?>
                    <span style="margin-left:auto;font-size:.7rem;font-weight:600;color:var(--primary-dark,#144a21);background:#fff;padding:2px 8px;border-radius:999px;">
                        <?php echo formatNepaliDate($viewApp['resolved_at'], true); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php if ($hasReply): ?>
                <div style="white-space:pre-wrap;font-size:.88rem;color:var(--primary-dark,#144a21);line-height:1.6;">
                    <?php echo nl2br(htmlspecialchars($viewApp['admin_response'])); ?>
                </div>
                <?php endif; ?>
                <?php if ($hasAttach): ?>
                <div style="margin-top:10px;">
                    <a href="<?php echo htmlspecialchars($siteUrl . ltrim($viewApp['admin_attachment'],'/')); ?>" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;background:#fff;color:var(--primary-dark,#144a21);border:1px solid var(--primary-light,#2e8b4a);padding:6px 12px;border-radius:8px;font-size:.78rem;font-weight:700;text-decoration:none;">
                        <i class="fas fa-paperclip"></i> <?php echo $_t('संलग्न फाइल हेर्नुहोस्', 'View Attachment'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Details table -->
            <table class="table" style="margin-top:16px;font-size:0.85rem;">
                <?php
                /* admin_response अब माथि highlight panel मा देखिएकोले list बाट skip गर्छौं — duplicate नहोस् */
                $skipKeys = ['id','password','admin_attachment','admin_note','google_id','facebook_id','admin_response'];
                $labelMap = [
                    'full_name'=>'नाम','name'=>'नाम','email'=>'इमेल','phone'=>'फोन','mobile'=>'मोबाइल',
                    'status'=>'अवस्था','created_at'=>'दर्ता मिति','tracking_id'=>'Tracking ID',
                    'branch'=>'शाखा','purpose'=>'उद्देश्य','loan_type'=>'ऋण प्रकार','loan_amount'=>'ऋण रकम',
                    'account_type'=>'खाता प्रकार','subject'=>'विषय','claim_type'=>'दाबी प्रकार',
                    'preferred_date'=>'मिति','preferred_time'=>'समय','description'=>'विवरण',
                    'admin_response'=>'Admin प्रतिक्रिया','remarks'=>'Admin टिप्पणी',
                ];
                foreach ($viewApp as $k => $v):
                    if (in_array($k, $skipKeys)) continue;
                    if (empty($v) && $v !== '0') continue;
                    $label = $labelMap[$k] ?? ucwords(str_replace('_',' ',$k));
                    $isHighlight = in_array($k, ['admin_response','remarks']);
                ?>
                <tr>
                            <th style="width:35%;color:var(--text-muted);font-weight:700;font-size:0.78rem;vertical-align:top;padding:6px 8px;border-bottom:1px solid var(--border-soft);"><?php echo htmlspecialchars($label); ?></th>
                    <td style="padding:6px 8px;border-bottom:1px solid var(--border-soft);<?php echo $isHighlight ? 'background:var(--bg-soft);' : ''; ?>">
                        <?php if ($k === 'status'): echo memberStatusBadge($v);
                        elseif ($k === 'created_at' || $k === 'updated_at'): echo formatNepaliDate($v, true);
                        elseif ($k === 'preferred_date'): echo formatNepaliDate($v);
                        elseif ($k === 'loan_amount' || $k === 'collateral_value'): echo 'रू. ' . number_format((float)$v);
                        elseif ($isHighlight): echo '<div style="white-space:pre-wrap;font-size:0.85rem;color:var(--text-secondary);">' . nl2br(htmlspecialchars($v)) . '</div>';
                        else: echo htmlspecialchars($v); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter bar -->
    <div class="mem-card">
        <div class="mem-card-header">
            <div class="mem-card-title"><i class="fas fa-clock-rotate-left"></i><?php echo $_t('मेरा सबै आवेदनहरू', 'All My Applications'); ?> (<?php echo count($apps); ?>)</div>
        </div>
        <div class="mem-card-body" style="padding-bottom:8px;">
            <!-- Filter chips -->
            <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:14px;">
                <?php $filters = ['all'=>$_t('सबै','All'),'appointment'=>$_t('भेटघाट','Appointment'),'kyc'=>'KYC','loan'=>$_t('ऋण','Loan'),'account'=>$_t('खाता','Account'),'grievance'=>$_t('गुनासो','Grievance'),'welfare'=>$_t('कल्याण','Welfare'),'job'=>$_t('जागिर','Job')]; ?>
                <?php foreach ($filters as $fk => $fl): ?>
                <a href="?filter=<?php echo $fk; ?>&q=<?php echo urlencode($q); ?>" style="padding:5px 13px;border-radius:20px;font-size:0.75rem;font-weight:700;text-decoration:none;border:1.5px solid <?php echo $filter===$fk ? 'var(--mem-primary)' : 'var(--border-soft)'; ?>;background:<?php echo $filter===$fk ? 'var(--mem-primary)' : 'var(--bg-card)'; ?>;color:<?php echo $filter===$fk ? 'var(--text-on-primary, #fff)' : 'var(--text-muted)'; ?>;">
                    <?php echo $fl; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <form method="GET" style="display:flex;gap:8px;align-items:center;margin-bottom:14px;">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="<?php echo $_t('Tracking ID, सेवा, विवरणबाट खोज्नुहोस्...', 'Search by tracking ID, service or details...'); ?>" style="flex:1;min-width:0;border:1px solid var(--border-color);border-radius:8px;padding:7px 10px;font-size:.8rem;">
                <button type="submit" style="padding:7px 12px;border:none;border-radius:8px;background:var(--mem-primary);color:var(--text-on-primary, #fff);font-size:.78rem;font-weight:700;"><i class="fas fa-search me-1"></i><?php echo $_t('खोज', 'Search'); ?></button>
                <?php if ($q !== ''): ?>
                <a href="?filter=<?php echo urlencode($filter); ?>" style="padding:7px 12px;border:1px solid var(--border-color);border-radius:8px;background:var(--bg-card);color:var(--text-muted);font-size:.78rem;font-weight:700;text-decoration:none;">Reset</a>
                <?php endif; ?>
            </form>

            <!-- Application list -->
            <?php if (empty($apps)): ?>
            <div class="mem-empty">
                <span class="mem-empty-icon">📭</span>
                <div><?php echo $_t('यस श्रेणीमा कुनै आवेदन छैन।', 'No applications in this category.'); ?></div>
                <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;justify-content:center;">
                    <?php
                    $emptyLinks = [
                        'loan' => ['href' => 'loan-apply.php', 'label' => $_t('ऋण आवेदन', 'Loan apply')],
                        'appointment' => ['href' => 'appointment.php', 'label' => $_t('भेटघाट', 'Appointment')],
                        'kyc' => ['href' => 'kyc.php', 'label' => 'KYC'],
                        'account' => ['href' => 'account-apply.php', 'label' => $_t('खाता खोल्ने', 'Open account')],
                        'grievance' => ['href' => 'grievance.php', 'label' => $_t('गुनासो', 'Grievance')],
                    ];
                    $show = ($filter === 'all')
                        ? ['loan', 'appointment', 'kyc']
                        : (isset($emptyLinks[$filter]) ? [$filter] : ['loan', 'appointment', 'kyc']);
                    foreach ($show as $sk):
                        if (!isset($emptyLinks[$sk])) continue;
                        $lk = $emptyLinks[$sk];
                    ?>
                    <a href="<?php echo htmlspecialchars($lk['href']); ?>" class="mem-btn mem-btn-outline" style="font-size:.8rem;padding:6px 12px;">
                        <?php echo htmlspecialchars($lk['label']); ?> →
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <?php foreach ($apps as $app): ?>
            <div class="mem-trk-card">
                <div class="mem-trk-header" onclick="toggleTrk(this)">
                    <div class="mem-app-icon" style="background:<?php echo $app['service_color']; ?>; width:38px;height:38px;border-radius:9px;flex-shrink:0;">
                        <i class="fas <?php echo $app['service_icon']; ?>"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:0.72rem;font-weight:700;color:<?php echo $app['service_color']; ?>;text-transform:uppercase;margin-bottom:2px;">
                            <?php echo htmlspecialchars($app['service_name']); ?>
                        </div>
                        <div style="font-size:0.88rem;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?php echo htmlspecialchars($app['detail'] ?: '—'); ?>
                        </div>
                        <div style="font-size:0.7rem;color:var(--text-muted);margin-top:2px;">
                            <?php echo formatNepaliDate($app['created_at']); ?>
                            <?php if ($app['tracking_id']): ?> · <code style="font-size:0.68rem;"><?php echo htmlspecialchars($app['tracking_id']); ?></code><?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;">
                        <?php echo memberStatusBadge($app['status']); ?>
                        <i class="fas fa-chevron-down" style="font-size:0.7rem;color:var(--text-muted);transition:transform .2s;" class="trk-chevron"></i>
                    </div>
                </div>
                <div class="mem-trk-body">
                    <?php echo timelineSteps($app['status']); ?>
                    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
                        <a href="?view=<?php echo (int)$app['id']; ?>&tbl=<?php echo htmlspecialchars($app['_table']); ?>&filter=<?php echo htmlspecialchars($filter); ?>&q=<?php echo urlencode($q); ?>"
                           class="btn" style="padding:7px 14px;background:var(--mem-primary);color:var(--text-on-primary, #fff);border-radius:8px;font-size:0.78rem;font-weight:700;text-decoration:none;border:none;">
                            <i class="fas fa-eye me-1"></i><?php echo $_t('विस्तृत हेर्नुहोस्', 'View Details'); ?>
                        </a>
                        <?php if ($app['tracking_id']): ?>
                        <a href="<?php echo $siteUrl; ?>application-tracker.php?tracking_id=<?php echo urlencode($app['tracking_id']); ?>"
                           target="_blank" style="padding:7px 14px;background:var(--bg-soft);color:var(--mem-primary);border-radius:8px;font-size:0.78rem;font-weight:700;text-decoration:none;border:1px solid var(--mem-primary);">
                            <i class="fas fa-search-location me-1"></i><?php echo $_t('पब्लिक ट्र्याकर', 'Public Tracker'); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

<script>
function toggleTrk(header) {
    var body    = header.nextElementSibling;
    var chevron = header.querySelector('.fa-chevron-down,.fa-chevron-up');
    var open    = body.classList.toggle('open');
    if (chevron) { chevron.className = chevron.className.replace('fa-chevron-down','fa-chevron-XXX').replace('fa-chevron-up','fa-chevron-down').replace('fa-chevron-XXX', open ? 'fa-chevron-up' : 'fa-chevron-down'); }
}
function copyTrk(id, btn) {
    var el = document.getElementById(id);
    navigator.clipboard.writeText(el.textContent.trim()).then(function(){
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
        setTimeout(function(){ btn.innerHTML = '<i class="lucide-icon me-1" aria-hidden="true" data-lucide="copy"></i>Copy'; }, 2000);
    });
}
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
