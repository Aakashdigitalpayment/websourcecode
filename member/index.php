<?php
/**
 * Member Portal — Dashboard
 * v6: Programs/Charts हटाइयो, Partner history UI सुधारियो
 */
require_once __DIR__ . '/_bootstrap.php';
requireMemberLogin();
memberSecurityHeaders();

if (!isset($db) || !$db) {
    $db = function_exists('getDB') ? getDB() : null;
}
if (!$db) {
    header('Location: login.php?msg=db_unavailable'); exit;
}

$mem      = currentMember();
if (!$mem) { header('Location: login.php?msg=session_expired'); exit; }
$memberId = $mem['id'];
$memName  = trim((string)($mem['name'] ?? ''));
$memEmail = trim((string)($mem['email'] ?? ''));
$memPhone = trim((string)($mem['phone'] ?? ''));
$memAvatar = $mem['avatar_url'] ?? '';

/* KYC-linked profile priority */
$kycRow = null;
try {
    $kycMemberLinkId = (int)($mem['kyc_application_id'] ?? 0);
    if ($kycMemberLinkId > 0) {
        $ks = $db->prepare("SELECT id, full_name, email, mobile, photo FROM kyc_applications WHERE id=? LIMIT 1");
        $ks->execute([$kycMemberLinkId]);
        $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$kycRow) {
        $kw = []; $kp = [];
        if ($memEmail !== '') { $kw[] = 'LOWER(email)=?'; $kp[] = strtolower($memEmail); }
        if ($memPhone !== '') { $kw[] = 'mobile=?'; $kp[] = preg_replace('/[^0-9]/', '', $memPhone); }
        if (!empty($kw)) {
            $ks = $db->prepare("SELECT id, full_name, email, mobile, photo FROM kyc_applications WHERE (" . implode(' OR ', $kw) . ") ORDER BY id DESC LIMIT 1");
            $ks->execute($kp);
            $kycRow = $ks->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($kycRow && empty($mem['kyc_application_id'])) {
                $db->prepare("UPDATE members SET kyc_application_id=? WHERE id=?")->execute([(int)$kycRow['id'], $memberId]);
                $mem['kyc_application_id'] = (int)$kycRow['id'];
            }
        }
    }
} catch (Throwable $e) { $kycRow = null; }
if ($kycRow) {
    $memName  = trim((string)($kycRow['full_name'] ?? '')) !== '' ? trim((string)$kycRow['full_name']) : $memName;
    $memEmail = trim((string)($kycRow['email']     ?? '')) !== '' ? trim((string)$kycRow['email'])     : $memEmail;
    $memPhone = trim((string)($kycRow['mobile']    ?? '')) !== '' ? trim((string)$kycRow['mobile'])    : $memPhone;
    if (!empty($kycRow['photo'])) $memAvatar = trim((string)$kycRow['photo']);
}

/* Applications */
$apps      = getMemberApplications($memEmail, $memPhone, 200);
$totalApps = count($apps);
$pending   = count(array_filter($apps, fn($a) => $a['status'] === 'pending'));
$approved  = count(array_filter($apps, fn($a) => in_array($a['status'], ['approved','completed','resolved'])));
$raStatus  = $_GET['ra_status'] ?? 'all';
if (!in_array($raStatus, ['all','pending','approved','rejected'], true)) $raStatus = 'all';
$raQ = mb_substr(trim((string)($_GET['ra_q'] ?? '')), 0, 120);
$recentFiltered = $apps;
if ($raStatus !== 'all') {
    $recentFiltered = array_values(array_filter($recentFiltered, function($a) use ($raStatus) {
        $st = (string)($a['status'] ?? '');
        if ($raStatus === 'pending')  return in_array($st, ['pending','under_review','processing'], true);
        if ($raStatus === 'approved') return in_array($st, ['approved','completed','resolved'], true);
        if ($raStatus === 'rejected') return $st === 'rejected';
        return true;
    }));
}
if ($raQ !== '') {
    $qLower = mb_strtolower($raQ);
    $recentFiltered = array_values(array_filter($recentFiltered, function($a) use ($qLower) {
        $hay = mb_strtolower(($a['service_name']??'').' '.($a['detail']??'').' '.($a['tracking_id']??'').' '.($a['status']??''));
        return strpos($hay, $qLower) !== false;
    }));
}
$recentApps = array_slice($recentFiltered, 0, 5);

/* Notifications */
$unread = 0;
$notifs = [];
try {
    $unread  = getMemberUnreadCount($memberId);
    $notifSt = $db->prepare("SELECT * FROM member_notifications WHERE member_id=? ORDER BY created_at DESC LIMIT 5");
    $notifSt->execute([$memberId]);
    $notifs  = $notifSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $unread = 0; $notifs = []; }

/* ── Partner service history ── */
$partnerHistory = [];
try {
    $ph = $db->prepare("SELECT partner_name, service_name, service_taken, service_note, created_at
                        FROM member_partner_services
                        WHERE member_id = ?
                        ORDER BY created_at DESC
                        LIMIT 50");
    $ph->execute([$memberId]);
    $partnerHistory = $ph->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $partnerHistory = []; }

/* Per-partner summary (name => count taken) */
$partnerSummary = [];
foreach ($partnerHistory as $h) {
    $pn = trim((string)($h['partner_name'] ?? ''));
    if ($pn === '') continue;
    if (!isset($partnerSummary[$pn])) $partnerSummary[$pn] = ['total' => 0, 'taken' => 0];
    $partnerSummary[$pn]['total']++;
    if (!empty($h['service_taken'])) $partnerSummary[$pn]['taken']++;
}
arsort($partnerSummary); // most-used first

$welcome  = $_GET['welcome'] ?? '';
if (!in_array($welcome, ['google','facebook'], true)) $welcome = '';

$siteName = getSetting('site_name', 'आकाश सहकारी');
$logoPath = function_exists('getLocalizedLogoPath')
    ? trim((string) getLocalizedLogoPath('assets/images/logo.png'))
    : trim((string) getSetting('site_logo', getSetting('logo', 'assets/images/logo.png')));
$siteUrl  = SITE_URL;
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

$hour = (int)date('H');
$greeting = $hour < 12 ? $_t('शुभ बिहान', 'Good Morning') : ($hour < 17 ? $_t('शुभ दिन', 'Good Afternoon') : $_t('शुभ सन्ध्या', 'Good Evening'));

/* सबै Quick Apply → /member/ भित्र (कल्याण = native welfare.php, बाँकी = apply-frame) */
$quickActions = [
    ['href' => $siteUrl.'member/appointment.php',               'icon' => 'fa-calendar-check',     'color' => 'var(--primary-color)', 'label' => $_t('भेटघाट', 'Appointment')],
    ['href' => $siteUrl.'member/kyc.php',                        'icon' => 'fa-id-card',             'color' => 'var(--secondary-color)', 'label' => $_t('KYC दर्ता', 'KYC Registration')],
    ['href' => $siteUrl.'member/loan-apply.php',               'icon' => 'fa-hand-holding-usd',    'color' => 'var(--secondary-dark)', 'label' => $_t('ऋण आवेदन', 'Loan Apply')],
    ['href' => $siteUrl.'member/account-apply.php',            'icon' => 'fa-university',          'color' => 'var(--primary-color)', 'label' => $_t('खाता खोल्ने', 'Open Account')],
    ['href' => $siteUrl.'member/digital-service.php',          'icon' => 'fa-laptop',              'color' => 'var(--secondary-color)', 'label' => $_t('डिजिटल सेवा', 'Digital Service')],
    ['href' => $siteUrl.'member/grievance.php',                 'icon' => 'fa-comment-dots', 'color' => 'var(--secondary-color)', 'label' => $_t('गुनासो', 'Grievance')],
    ['href' => $siteUrl.'member/welfare.php',                   'icon' => 'fa-heart',               'color' => 'var(--secondary-color)', 'label' => $_t('कल्याण', 'Welfare')],
    ['href' => $siteUrl.'member/service-request.php',           'icon' => 'fa-concierge-bell',      'color' => 'var(--primary-color)', 'label' => $_t('सेवा अनुरोध', 'Service Req.')],
    ['href' => $siteUrl.'member/apply-frame.php?p=career',      'icon' => 'fa-briefcase',           'color' => 'var(--primary-dark)', 'label' => $_t('जागिर', 'Career')],
    ['href' => $siteUrl.'member/apply-frame.php?p=emi',         'icon' => 'fa-calculator',          'color' => 'var(--accent-color)', 'label' => 'EMI Calculator'],
];
$iconMap = [
    'success'=>['fas fa-circle-check','var(--primary-color)','color-mix(in srgb, var(--primary-color) 12%, white)'],
    'error'  =>['fas fa-circle-xmark','var(--secondary-color)','color-mix(in srgb, var(--secondary-color) 14%, white)'],
    'warning'=>['fas fa-triangle-exclamation','var(--secondary-dark)','color-mix(in srgb, var(--secondary-color) 14%, white)'],
    'info'   =>['fas fa-circle-info','var(--accent-color)','color-mix(in srgb, var(--accent-color) 12%, white)']
];
$pageTitle = $_t('सदस्य ड्यासबोर्ड', 'Member Dashboard') . ' — ' . $siteName;
require __DIR__ . '/includes/chrome.php';
?>
<style>
.midx-greeting{margin-bottom:16px;}
.midx-greeting-title{margin:0;color:var(--primary-color);}
.midx-greeting-date{margin:4px 0 0;color:var(--text-light);font-size:.88rem;}
.midx-stat-pending{color:var(--secondary-dark);}
.midx-stat-approved{color:var(--primary-color);}
.midx-stat-notif{color:var(--secondary-color);}
.midx-stat-partner{color:var(--accent-color);}
.midx-ds-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;}
.midx-ds-card{border-radius:12px;padding:14px 12px;text-decoration:none;display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;transition:transform .15s,box-shadow .15s;}
.midx-ds-card:hover{box-shadow:0 2px 8px rgba(var(--primary-rgb),.10);}
.midx-ds-icon-wrap{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;}
.midx-ds-icon{color:var(--text-on-primary);font-size:1.1rem;}
.midx-ds-label{font-size:.8rem;font-weight:700;color:var(--text-color);}
.midx-ds-desc{font-size:.68rem;color:var(--text-light);}
.midx-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
@media(max-width:700px){.midx-grid-2{grid-template-columns:1fr;}}
/* stat cards rendered as <a> — child colors set via explicit class rules */
.midx-link{font-size:.78rem;color:var(--mem-primary);font-weight:700;text-decoration:none;}
.midx-body-pad-sm{padding-top:6px;}
.midx-filter-row{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;}
.midx-filter-pill{padding:4px 10px;border-radius:18px;font-size:.7rem;font-weight:700;text-decoration:none;border:1.5px solid color-mix(in srgb, var(--primary-color) 20%, var(--gray-200));background:white;color:var(--text-light);}
.midx-filter-pill.is-active{border-color:var(--mem-primary);background:var(--mem-primary);color:var(--text-on-primary);}
.midx-search-form{display:flex;gap:6px;align-items:center;margin-bottom:10px;}
.midx-search-input{flex:1;min-width:0;border:1px solid color-mix(in srgb, var(--primary-color) 20%, var(--gray-300));border-radius:8px;padding:6px 9px;font-size:.76rem;}
.midx-search-btn{padding:6px 10px;border:none;border-radius:8px;background:var(--mem-primary);color:var(--text-on-primary);font-size:.74rem;font-weight:700;}
.midx-reset-btn{padding:6px 10px;border:1px solid color-mix(in srgb, var(--primary-color) 20%, var(--gray-300));border-radius:8px;background:white;color:var(--text-light);font-size:.74rem;font-weight:700;text-decoration:none;}
.midx-empty-sub{margin-top:8px;font-size:.78rem;}
.midx-track{font-size:.68rem;color:var(--text-light);font-family:monospace;}
.midx-unread-dot{width:8px;height:8px;border-radius:50%;background:var(--mem-accent);flex-shrink:0;margin-top:6px;}
.midx-card-mt{margin-top:18px;}
.midx-muted-count{font-size:.75rem;color:var(--text-light);font-weight:600;}
.midx-empty-note{margin-top:6px;font-size:.78rem;color:var(--text-muted);}
.midx-ph-taken{color:var(--primary-color);}
.midx-ph-org{color:var(--accent-color);font-size:.72rem;margin-right:4px;}
.midx-ph-mini{font-size:.72rem;}
.midx-alert-gap{margin-bottom:16px;}
.midx-stat-brand{color:var(--primary-color);}
.midx-card-gap{margin-bottom:18px;}
.midx-action-icon{background:var(--midx-action-color);}
.midx-ds-card-bg{background:var(--midx-ds-bg);}
.midx-ds-icon-bg{background:var(--midx-ds-color);}
.midx-notif-item{border-radius:8px;}
.midx-notif-dot-icon{background:var(--midx-ic-bg);color:var(--midx-ic-color);}
.midx-flex-grow{flex:1;min-width:0;}
.midx-notif-dot-inline{position:static;}
</style>
<?php if ($welcome): ?>
<div class="mem-alert mem-alert-success midx-alert-gap">
    <i class="fas fa-party-horn"></i>
    <?php echo $welcome === 'google' ? 'Google बाट ' : ($welcome === 'facebook' ? 'Facebook बाट ' : ''); ?>
    <?php echo $_t('स्वागत छ,', 'Welcome,'); ?> <strong><?php echo htmlspecialchars($memName); ?></strong>!
</div>
<?php endif; ?>

    <!-- Greeting -->
    <div class="mem-greeting midx-greeting">
        <h2 class="midx-greeting-title"><?php echo $greeting; ?>, <?php echo htmlspecialchars($memName); ?>! 👋</h2>
        <p class="midx-greeting-date"><?php
            /* Use Kathmandu timezone explicitly for accurate BS today */
            $tz = new DateTimeZone('Asia/Kathmandu');
            $todayAd = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
            echo $_t('आजको मिति', 'Today') . ': ' . formatNepaliDate($todayAd);
        ?></p>
    </div>

    <!-- Stat cards — centralized stat-card.php component -->
    <?php
    $statCards = [
        [
            'label' => $_t('कुल आवेदन', 'Total Applications'),
            'value' => $totalApps,
            'icon'  => 'fa-clipboard-list',
            'color' => 'primary',
            'link'  => $siteUrl . 'member/tracker.php',
        ],
        [
            'label' => $_t('पेन्डिङ', 'Pending'),
            'value' => $pending,
            'icon'  => 'fa-hourglass-half',
            'color' => 'warning',
            'link'  => '?ra_status=pending#recent-apps',
        ],
        [
            'label' => $_t('स्वीकृत', 'Approved'),
            'value' => $approved,
            'icon'  => 'fa-circle-check',
            'color' => 'success',
            'link'  => '?ra_status=approved#recent-apps',
        ],
        [
            'label' => $_t('नयाँ सूचना', 'Notifications'),
            'value' => $unread,
            'icon'  => 'fa-bell',
            'color' => 'secondary',
            'link'  => $siteUrl . 'member/notifications.php',
        ],
        [
            'label' => $_t('साझेदार सेवा', 'Partner Services'),
            'value' => count($partnerHistory),
            'icon'  => 'fa-handshake',
            'color' => 'info',
            'link'  => '#partner-section',
        ],
    ];
    $statColClass = 'col-6 col-sm-4 col-lg';
    include __DIR__ . '/../includes/components/stat-card.php';
    ?>

    <!-- (Quick Apply & Digital Services moved to bottom — user request) -->
    <?php
    // capture digital services data for later render below
    $digitalServices = [];
    {
        $ibUrl  = getSetting('internet_banking_url', '');
        $iosUrl = getSetting('app_store_url', '');
        $andUrl = getSetting('play_store_url', '');
        if ($ibUrl)  $digitalServices[] = ['icon'=>'fa-laptop',      'color'=>'var(--accent-color)','bg'=>'color-mix(in srgb, var(--accent-color) 12%, white)','label'=>'Internet Banking','href'=>$ibUrl, 'desc'=>$_t('Online खाता व्यवस्थापन','Online account management'),'target'=>'_blank'];
        if ($iosUrl) $digitalServices[] = ['icon'=>'fa-apple','iconLib'=>'fab','color'=>'var(--text-color)','bg'=>'color-mix(in srgb, var(--primary-color) 10%, white)','label'=>'iOS App','href'=>$iosUrl,'desc'=>$_t('App Store बाट डाउनलोड','Download from App Store'),'target'=>'_blank'];
        if ($andUrl) $digitalServices[] = ['icon'=>'fa-google-play','iconLib'=>'fab','color'=>'var(--primary-color)','bg'=>'color-mix(in srgb, var(--primary-color) 12%, white)','label'=>'Android App','href'=>$andUrl,'desc'=>$_t('Play Store बाट डाउनलोड','Download from Play Store'),'target'=>'_blank'];
        $digitalServices = array_merge($digitalServices, [
            ['icon'=>'fa-mobile-screen-button','color'=>'var(--secondary-color)','bg'=>'color-mix(in srgb, var(--secondary-color) 12%, white)','label'=>$_t('मोबाइल बैंकिङ','Mobile Banking'),  'href'=>$siteUrl.'member/digital-service.php', 'desc'=>$_t('कुनै पनि समय बैंकिङ','Anytime banking')],
            ['icon'=>'fa-qrcode',              'color'=>'var(--secondary-dark)','bg'=>'color-mix(in srgb, var(--secondary-color) 12%, white)','label'=>$_t('QR भुक्तानी','QR Payment'),         'href'=>$siteUrl.'member/digital-service.php', 'desc'=>$_t('छिटो भुक्तानी','Quick payment')],
            ['icon'=>'fa-file-invoice-dollar', 'color'=>'var(--primary-color)','bg'=>'color-mix(in srgb, var(--primary-color) 12%, white)','label'=>$_t('अनलाइन ऋण','Online Loan'),           'href'=>$siteUrl.'member/loan-apply.php',            'desc'=>$_t('घरबाटै ऋण आवेदन','Apply loan from home')],
            ['icon'=>'fa-piggy-bank',          'color'=>'var(--secondary-dark)','bg'=>'color-mix(in srgb, var(--secondary-color) 10%, white)','label'=>$_t('अनलाइन बचत','Online Bachat'),      'href'=>$siteUrl.'member/account-apply.php',         'desc'=>$_t('बचत खाता Online','Online savings account')],
            ['icon'=>'fa-headset',             'color'=>'var(--secondary-color)','bg'=>'color-mix(in srgb, var(--secondary-color) 12%, white)','label'=>$_t('सेवा सहायता','24/7 Support'),     'href'=>$siteUrl.'member/service-request.php',       'desc'=>$_t('सहायता अनुरोध','Request support')],
            ['icon'=>'fa-id-card',             'color'=>'var(--primary-color)','bg'=>'color-mix(in srgb, var(--primary-color) 12%, white)','label'=>'Digital ID Card',   'href'=>$siteUrl.'member/id-card.php',
             'desc'=>$mem['id_card_generated'] ? $_t('ID Card हेर्नुहोस्','View ID card') : $_t('Admin Generate गर्दैछन्','Pending admin generation')],
            ['icon'=>'fa-calculator',          'color'=>'var(--accent-color)','bg'=>'color-mix(in srgb, var(--accent-color) 12%, white)','label'=>'EMI Calculator',    'href'=>$siteUrl.'member/apply-frame.php?p=emi',     'desc'=>$_t('किस्ता गणना','Installment calculation')],
        ]);
    }
    ?>
    <?php /* legacy block removed; old foreach kept commented for safety */ ?>
    <?php if (false): ?>
        <div class="midx-ds-grid">
                <?php
                foreach ($digitalServices as $ds): ?>
                <a href="<?php echo htmlspecialchars($ds['href']); ?>"
                   <?php if (!empty($ds['target'])): ?>target="<?php echo $ds['target']; ?>" rel="noopener"<?php endif; ?>
                   class="midx-ds-card midx-ds-card-bg" style="--midx-ds-bg:<?php echo htmlspecialchars($ds['bg'], ENT_QUOTES, 'UTF-8'); ?>;">
                    <div class="midx-ds-icon-wrap midx-ds-icon-bg" style="--midx-ds-color:<?php echo htmlspecialchars($ds['color'], ENT_QUOTES, 'UTF-8'); ?>;">
                        <i class="<?php echo $ds['iconLib'] ?? 'fas'; ?> <?php echo $ds['icon']; ?> midx-ds-icon"></i>
                    </div>
                    <div class="midx-ds-label"><?php echo $ds['label']; ?></div>
                    <div class="midx-ds-desc"><?php echo $ds['desc']; ?></div>
                </a>
                <?php endforeach; ?>
            </div>
    <?php endif; ?>

    <!-- Two-column: Recent apps + Notifications -->
    <div class="mem-grid-2 midx-grid-2" id="recent-apps">

        <div class="mem-card">
            <div class="mem-card-header">
                <div class="mem-card-title"><i class="fas fa-clock-rotate-left"></i><?php echo $_t('हालका आवेदनहरू', 'Recent Applications'); ?></div>
                <a href="<?php echo $siteUrl; ?>member/tracker.php" class="midx-link"><?php echo $_t('सबै हेर्नुस्', 'View all'); ?> →</a>
            </div>
            <div class="mem-card-body midx-body-pad-sm">
                <div class="midx-filter-row">
                    <?php $raFilters = ['all'=>$_t('सबै','All'),'pending'=>$_t('पेन्डिङ','Pending'),'approved'=>$_t('स्वीकृत','Approved'),'rejected'=>$_t('अस्वीकृत','Rejected')]; ?>
                    <?php foreach ($raFilters as $rk => $rl): ?>
                    <a href="?ra_status=<?php echo urlencode($rk); ?>&ra_q=<?php echo urlencode($raQ); ?>"
                       class="midx-filter-pill <?php echo $raStatus===$rk ? 'is-active' : ''; ?>">
                        <?php echo $rl; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <form method="GET" class="midx-search-form">
                    <input type="hidden" name="ra_status" value="<?php echo htmlspecialchars($raStatus); ?>">
                    <input type="text"  name="ra_q"      value="<?php echo htmlspecialchars($raQ); ?>"
                           placeholder="<?php echo $_t('सेवा वा Tracking ID खोज्नुहोस्...', 'Search service or tracking ID...'); ?>"
                           class="midx-search-input">
                    <button type="submit" class="midx-search-btn" aria-label="Search" title="Search"><i class="fas fa-search"></i></button>
                    <?php if ($raQ !== '' || $raStatus !== 'all'): ?>
                    <a href="<?php echo $siteUrl; ?>member/" class="midx-reset-btn"><?php echo $_t('रिसेट','Reset'); ?></a>
                    <?php endif; ?>
                </form>
                <?php if (empty($recentApps)): ?>
                <div class="mem-empty">
                    <span class="mem-empty-icon">📭</span>
                    <div><?php echo $_t('अहिलेसम्म कुनै आवेदन छैन।', 'No applications yet.'); ?></div>
                    <div class="midx-empty-sub"><?php echo $_t('माथि Quick Apply बाट सेवा लिनुहोस्।', 'Use Quick Apply above to request services.'); ?></div>
                </div>
                <?php else: foreach ($recentApps as $app): ?>
                <div class="mem-app-item">
                    <div class="mem-app-icon midx-action-icon" style="--midx-action-color:<?php echo htmlspecialchars($app['service_color'], ENT_QUOTES, 'UTF-8'); ?>;"><i class="fas <?php echo $app['service_icon']; ?>"></i></div>
                    <div class="mem-app-info">
                        <div class="mem-app-service midx-stat-brand" style="--midx-action-color:<?php echo htmlspecialchars($app['service_color'], ENT_QUOTES, 'UTF-8'); ?>;color:var(--midx-action-color);"><?php echo htmlspecialchars($app['service_name']); ?></div>
                        <div class="mem-app-detail"><?php echo htmlspecialchars($app['detail'] ?: '—'); ?></div>
                        <div class="mem-app-date"><?php echo formatNepaliDate($app['created_at'], true); ?></div>
                    </div>
                    <div class="mem-app-right">
                        <?php echo memberStatusBadge($app['status']); ?>
                        <?php if ($app['tracking_id']): ?><span class="midx-track"><?php echo htmlspecialchars($app['tracking_id']); ?></span><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="mem-card">
            <div class="mem-card-header">
                <div class="mem-card-title"><i class="fas fa-bell"></i><?php echo $_t('सूचनाहरू', 'Notifications'); ?>
                    <?php if ($unread > 0): ?><span class="mem-notif-dot midx-notif-dot-inline"><?php echo $unread; ?></span><?php endif; ?>
                </div>
                <a href="<?php echo $siteUrl; ?>member/notifications.php" class="midx-link"><?php echo $_t('सबै', 'All'); ?> →</a>
            </div>
            <div class="mem-card-body midx-body-pad-sm">
                <?php if (empty($notifs)): ?>
                <div class="mem-empty"><span class="mem-empty-icon">🔔</span><div><?php echo $_t('कुनै सूचना छैन।', 'No notifications.'); ?></div></div>
                <?php else:
                    foreach ($notifs as $n): $ic = $iconMap[$n['type']] ?? $iconMap['info'];
                ?>
                <div class="mem-notif-item midx-notif-item <?php echo !$n['is_read'] ? 'unread' : ''; ?>" onclick="markRead(<?php echo $n['id']; ?>, this)">
                    <div class="mem-notif-dot-icon midx-notif-dot-icon" style="--midx-ic-bg:<?php echo htmlspecialchars($ic[2], ENT_QUOTES, 'UTF-8'); ?>;--midx-ic-color:<?php echo htmlspecialchars($ic[1], ENT_QUOTES, 'UTF-8'); ?>;"><i class="<?php echo $ic[0]; ?>"></i></div>
                    <div class="midx-flex-grow">
                        <div class="mem-notif-title"><?php echo htmlspecialchars($n['title']); ?></div>
                        <div class="mem-notif-msg"><?php echo htmlspecialchars(mb_strimwidth($n['message'] ?? '', 0, 80, '…')); ?></div>
                        <div class="mem-notif-time"><?php echo formatNepaliDate($n['created_at'], true); ?></div>
                    </div>
                    <?php if (!$n['is_read']): ?><span class="midx-unread-dot"></span><?php endif; ?>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

    </div>

    <!-- ══════════════════════════════════════════════════════
         साझेदार संस्था सेवा इतिहास — v6 redesigned
    ═══════════════════════════════════════════════════════════ -->
    <div class="mem-card midx-card-mt" id="partner-section">
        <div class="mem-card-header">
            <div class="mem-card-title"><i class="fas fa-hospital"></i><?php echo $_t('साझेदार संस्था सेवा इतिहास', 'Partner Service History'); ?></div>
            <?php if (!empty($partnerHistory)): ?>
            <span class="midx-muted-count"><?php echo count($partnerHistory); ?> <?php echo $_t('रेकर्ड', 'records'); ?></span>
            <?php endif; ?>
        </div>
        <div class="mem-card-body">
            <?php if (empty($partnerHistory)): ?>
            <div class="mem-empty">
                <span class="mem-empty-icon">🏥</span>
                <div><?php echo $_t('अहिलेसम्म कुनै साझेदार संस्थामा सेवा लिइएको छैन।', 'No partner services taken yet.'); ?></div>
                <div class="midx-empty-note">
                    <?php echo $_t('साझेदार संस्थामा Member Card देखाएपछि यहाँ history देखिन्छ।', 'History appears here after showing your Member Card at partner organizations.'); ?>
                </div>
            </div>
            <?php else:
                $totalTaken   = count(array_filter($partnerHistory, fn($h) => !empty($h['service_taken'])));
                $totalOrgs    = count($partnerSummary);
            ?>
            <!-- Summary bar -->
            <div class="ph-total-bar">
                <div class="ph-total-stat">
                    <div class="ph-total-num"><?php echo count($partnerHistory); ?></div>
                    <div class="ph-total-lbl"><?php echo $_t('कुल भेट', 'Total Visits'); ?></div>
                </div>
                <div class="ph-divider"></div>
                <div class="ph-total-stat">
                    <div class="ph-total-num midx-ph-taken"><?php echo $totalTaken; ?></div>
                    <div class="ph-total-lbl"><?php echo $_t('सेवा लिइयो', 'Services Taken'); ?></div>
                </div>
                <div class="ph-divider"></div>
                <div class="ph-total-stat">
                    <div class="ph-total-num midx-stat-partner"><?php echo $totalOrgs; ?></div>
                    <div class="ph-total-lbl"><?php echo $_t('संस्थाहरू', 'Organizations'); ?></div>
                </div>
            </div>

            <!-- Per-partner filter pills -->
            <div class="ph-summary-row">
                <span class="ph-summary-pill active" data-filter="all" onclick="phFilter('all', this)">
                    <i class="fas fa-th-large midx-ph-mini"></i>
                    <?php echo $_t('सबै', 'All'); ?>
                    <span class="ph-pill-count"><?php echo count($partnerHistory); ?></span>
                </span>
                <?php foreach ($partnerSummary as $pname => $pdata): ?>
                <span class="ph-summary-pill" data-filter="<?php echo htmlspecialchars($pname, ENT_QUOTES); ?>" onclick="phFilter(<?php echo json_encode($pname); ?>, this)">
                    <i class="fas fa-building midx-ph-mini"></i>
                    <?php echo htmlspecialchars($pname); ?>
                    <span class="ph-pill-count"><?php echo $pdata['total']; ?> <?php echo $_t('पटक', 'times'); ?></span>
                </span>
                <?php endforeach; ?>
            </div>

            <!-- History rows -->
            <div id="phList">
                <?php foreach ($partnerHistory as $h):
                    $pnAttr = htmlspecialchars(trim((string)($h['partner_name'] ?? '')), ENT_QUOTES);
                    $taken  = !empty($h['service_taken']);
                ?>
                <div class="ph-history-item" data-org="<?php echo $pnAttr; ?>">
                    <div class="ph-org-icon">
                        <i class="fas fa-<?php echo ($h['facility_type'] ?? '') === 'अस्पताल' ? 'hospital' : 'building-columns'; ?>"></i>
                    </div>
                    <div class="ph-info">
                        <div class="ph-org-name"><?php echo htmlspecialchars($h['partner_name'] ?? '—'); ?></div>
                        <div class="ph-svc-name">
                            <i class="fas fa-stethoscope midx-ph-org"></i>
                            <?php echo htmlspecialchars($h['service_name'] ?: $_t('सेवा उल्लेख छैन', 'Service not specified')); ?>
                        </div>
                        <?php if (!empty($h['service_note'])): ?>
                        <div class="ph-svc-note"><i class="fas fa-note-sticky midx-ph-mini"></i><?php echo htmlspecialchars($h['service_note']); ?></div>
                        <?php endif; ?>
                        <div class="ph-date"><i class="fas fa-clock midx-ph-mini"></i><?php echo formatNepaliDate($h['created_at'], true); ?></div>
                    </div>
                    <div class="ph-taken-badge <?php echo $taken ? 'ph-taken-yes' : 'ph-taken-no'; ?>">
                        <?php if ($taken): ?><i class="fas fa-circle-check midx-ph-mini"></i><?php else: ?><i class="fas fa-circle-xmark midx-ph-mini"></i><?php endif; ?>
                        <?php echo $taken ? $_t('सेवा लिइयो', 'Taken') : $_t('नलिइएको', 'Not taken'); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

<script>
/* Partner history filter */
</script>

    <!-- ─────── Quick Apply (moved to bottom) ─────── -->
    <div class="mem-card midx-card-mt">
        <div class="mem-card-header">
            <div class="mem-card-title"><i class="fas fa-bolt"></i><?php echo $_t('छिटो आवेदन — सेवाहरू', 'Quick Apply — Services'); ?></div>
        </div>
        <div class="mem-card-body">
            <div class="mem-actions">
                <?php foreach ($quickActions as $qa): ?>
                <a href="<?php echo htmlspecialchars($qa['href']); ?>" class="mem-action-btn">
                    <div class="mem-action-icon midx-action-icon" style="--midx-action-color:<?php echo htmlspecialchars($qa['color'], ENT_QUOTES, 'UTF-8'); ?>;"><i class="fas <?php echo $qa['icon']; ?>"></i></div>
                    <?php echo $qa['label']; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ─────── Digital Services (moved to bottom) ─────── -->
    <div class="mem-card midx-card-gap">
        <div class="mem-card-header">
            <div class="mem-card-title"><i class="fas fa-laptop-code"></i><?php echo $_t('डिजिटल सेवाहरू', 'Digital Services'); ?></div>
        </div>
        <div class="mem-card-body">
            <div class="midx-ds-grid">
                <?php foreach ($digitalServices as $ds): ?>
                <a href="<?php echo htmlspecialchars($ds['href']); ?>"
                   <?php if (!empty($ds['target'])): ?>target="<?php echo $ds['target']; ?>" rel="noopener"<?php endif; ?>
                   class="midx-ds-card midx-ds-card-bg" style="--midx-ds-bg:<?php echo htmlspecialchars($ds['bg'], ENT_QUOTES, 'UTF-8'); ?>;">
                    <div class="midx-ds-icon-wrap midx-ds-icon-bg" style="--midx-ds-color:<?php echo htmlspecialchars($ds['color'], ENT_QUOTES, 'UTF-8'); ?>;">
                        <i class="<?php echo $ds['iconLib'] ?? 'fas'; ?> <?php echo $ds['icon']; ?> midx-ds-icon"></i>
                    </div>
                    <div class="midx-ds-label"><?php echo $ds['label']; ?></div>
                    <div class="midx-ds-desc"><?php echo $ds['desc']; ?></div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

<script>
/* Partner history filter */
function phFilter(val, pill) {
    document.querySelectorAll('.ph-summary-pill').forEach(function(p){ p.classList.remove('active'); });
    if (pill) pill.classList.add('active');
    document.querySelectorAll('#phList .ph-history-item').forEach(function(row){
        var match = val === 'all' || row.getAttribute('data-org') === val;
        row.classList.toggle('ph-hidden', !match);
    });
}
/* Mark notification read */
function markRead(id, el) {
    if (el.classList.contains('mem-notif-item-read')) return;
    fetch('<?php echo $siteUrl; ?>member/ajax.php?action=mark_notif_read&id=' + id, {credentials:'same-origin'})
        .then(function(){ el.classList.remove('unread'); el.classList.add('mem-notif-item-read'); });
}
</script>
<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
